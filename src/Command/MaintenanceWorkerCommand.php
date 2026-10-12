<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Command;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'maintend:maintenance-job', description: 'Führt einen signiert angenommenen Wartungsauftrag im getrennten CLI-Prozess aus.')]
final class MaintenanceWorkerCommand extends Command
{
    public function __construct(
        private readonly MaintenanceJobStore $jobStore,
        private readonly UpdateInstallationService $updateInstallation,
        private readonly UpdateInstallationFinalizer $updateFinalizer,
        private readonly UpdateProgressStore $updateProgress,
        private readonly RestoreService $restoreService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('request-id', InputArgument::REQUIRED, 'Zuvor signiert angenommene Auftrags-ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestId = (string) $input->getArgument('request-id');
        $job = $this->jobStore->read($requestId);
        $operation = (string) ($job['operation'] ?? '');
        $systemId = (string) ($job['system_id'] ?? '');
        $payload = $job['payload'] ?? null;

        if (!in_array($operation, ['update', 'restore'], true)
            || !is_array($payload)
            || ($job['status'] ?? null) !== 'pending'
            || 1 !== preg_match('/\A[a-f0-9]{32}\z/', $systemId)
        ) {
            throw new RuntimeException('Der Auftrag wurde bereits gestartet oder ist ungültig.');
        }

        $this->jobStore->progress($requestId, 'preflight', 'running', 'CLI-Prozess gestartet.');

        try {
            if ('update' === $operation) {
                $result = $this->updateInstallation->install($systemId, $payload);
                $update = $result['update_installation'] ?? null;

                if (!is_array($update)) {
                    throw new RuntimeException('Die Update-Installation hat kein gültiges Ergebnis geliefert.');
                }

                $this->jobStore->progress($requestId, 'migration', 'running', 'Datenbankmigration wird ausgeführt.');
                $this->updateProgress->write($requestId, 'migration', 'running', 'Datenbankmigration wird ausgeführt.');
                $migration = $this->updateFinalizer->migrate();
                $update['database_migrated'] = true;
                $update['php_cli_version'] = $migration['php_cli_version'];
                $update['completed_at'] = $migration['completed_at'];
                $this->updateProgress->write($requestId, 'completed', 'success', 'Update erfolgreich abgeschlossen.', null, $update);
                $this->jobStore->progress($requestId, 'completed', 'success', 'Update erfolgreich abgeschlossen.', $update);
            } else {
                $backupId = $payload['backup_id'] ?? null;

                if (!is_string($backupId) || 1 !== preg_match('/\A[a-f0-9]{32}\z/', $backupId)) {
                    throw new RuntimeException('Der Restore-Auftrag enthält keine gültige Sicherungs-ID.');
                }

                $progress = function (string $phase, string $message) use ($requestId): void {
                    $this->jobStore->progress($requestId, $phase, 'running', $message);
                };

                $result = $this->restoreService->restore($systemId, $requestId, $backupId, $progress);
                $restore = $result['restore'] ?? null;

                if (!is_array($restore) || 'completed' !== ($restore['status'] ?? null)) {
                    throw new RuntimeException('Die Wiederherstellung wurde nicht vollständig bestätigt.');
                }

                $this->jobStore->progress($requestId, 'completed', 'success', 'Wiederherstellung vollständig abgeschlossen.', $restore);
            }

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->logger->error('Maintend CLI-Wartungsauftrag fehlgeschlagen.', [
                'request_id' => $requestId,
                'operation' => $operation,
                'exception' => $exception,
            ]);
            $message = mb_substr(trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? ''), 0, 950);

            if ('update' === $operation) {
                $this->updateProgress->write($requestId, 'error', 'error', 'Update fehlgeschlagen: '.$message);
            }

            $this->jobStore->progress($requestId, 'error', 'error', $message);

            return Command::FAILURE;
        }
    }
}
