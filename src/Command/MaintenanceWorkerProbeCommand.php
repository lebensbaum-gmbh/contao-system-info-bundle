<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Command;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'maintend:maintenance-probe', description: 'Prüft ungefährlich, ob Wartungsaufträge unabhängig vom HTTP-Prozess starten können.')]
final class MaintenanceWorkerProbeCommand extends Command
{
    public function __construct(
        private readonly MaintenanceJobStore $jobStore,
        private readonly MaintenanceJobLauncher $launcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestId = bin2hex(random_bytes(16));
        $systemId = bin2hex(random_bytes(16));

        try {
            $this->jobStore->create($requestId, $systemId, 'probe', []);
            $this->launcher->launch($requestId);
            $output->writeln('Maintend-Probe gestartet. Nur eine Datei wird geschrieben; keine DB- oder Composer-Änderungen.');

            $deadline = microtime(true) + 30;

            do {
                usleep(500000);
                $job = $this->jobStore->read($requestId);

                if ('success' === ($job['status'] ?? null)) {
                    $output->writeln('<info>OK: Hintergrundprozess gestartet und abgeschlossen.</info>');

                    return Command::SUCCESS;
                }

                if ('error' === ($job['status'] ?? null)) {
                    throw new RuntimeException('Hintergrundprozess meldet Fehler: '.(string) ($job['message'] ?? ''));
                }
            } while (microtime(true) < $deadline);

            // Do not silently unblock other operations after an uncertain worker start.
            $output->writeln('<error>CLI-Probe nicht bestätigt. Keine Updates oder Wiederherstellungen starten, bevor der Hintergrundprozess geprüft wurde.</error>');
            $output->writeln('Job-ID: '.$requestId);

            // A probe only touches its own status file. Unblock future work
            // after a failed probe; a late worker may not mark it successful.
            try {
                $this->jobStore->progress(
                    $requestId,
                    'error',
                    'error',
                    'CLI-Selbsttest nicht innerhalb von 30 Sekunden bestätigt.'
                );
            } catch (Throwable) {
            }

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $output->writeln('<error>Maintend-Probe fehlgeschlagen: '.$exception->getMessage().'</error>');

            try {
                $job = $this->jobStore->read($requestId);
                if (!in_array($job['status'] ?? '', ['error', 'success'], true)) {
                    $this->jobStore->progress($requestId, 'error', 'error', $exception->getMessage());
                }
            } catch (Throwable) {
            }

            return Command::FAILURE;
        }
    }
}
