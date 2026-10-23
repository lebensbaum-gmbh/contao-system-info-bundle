<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Command;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function configure(): void
    {
        $this->addOption('hold', null, InputOption::VALUE_REQUIRED, 'Testauftrag 0 bis 120 Sekunden aktiv halten, um die Wartungssperre zu prüfen.', '0');
        $this->addOption('fail', null, InputOption::VALUE_NONE, 'Den Testauftrag absichtlich mit einem Fehler beenden.');
        $this->addOption('maintenance-page', null, InputOption::VALUE_NONE, 'Während der Probe auch die Website vorübergehend mit HTTP 503 sperren.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hold = trim((string) $input->getOption('hold'));
        if (!ctype_digit($hold) || (int) $hold > 120) {
            $output->writeln('<error>--hold muss eine ganze Zahl zwischen 0 und 120 sein.</error>');

            return Command::INVALID;
        }

        $holdSeconds = (int) $hold;
        $requestId = bin2hex(random_bytes(16));
        $systemId = bin2hex(random_bytes(16));

        try {
            $this->jobStore->create($requestId, $systemId, 'probe', [
                'hold_seconds' => $holdSeconds,
                'fail' => (bool) $input->getOption('fail'),
                'maintenance_page' => (bool) $input->getOption('maintenance-page'),
            ]);
            $this->launcher->launch($requestId);
            $output->writeln('Maintend-Probe gestartet. Nur Teststatus und Protokoll werden geschrieben; keine DB- oder Composer-Änderungen.');
            $output->writeln('Job-ID: '.$requestId);
            if ($input->getOption('maintenance-page')) {
                $output->writeln('Während dieser Probe zeigt die Website vorübergehend eine Wartungsseite (HTTP 503).');
            }
            if ($holdSeconds > 0) {
                $output->writeln(sprintf('Testauftrag bleibt %d Sekunden aktiv. Jetzt kann die Wartungssperre geprüft werden.', $holdSeconds));
            }

            $deadline = microtime(true) + $holdSeconds + 30;

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
                    'CLI-Selbsttest nicht innerhalb des vorgesehenen Zeitfensters bestätigt.'
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
