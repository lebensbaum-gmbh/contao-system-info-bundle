<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MaintenanceJobLauncher
{
    public function __construct(
        private readonly PhpCliResolver $phpCliResolver,
        private readonly string $projectDir,
    ) {
    }

    public function launch(string $requestId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)) {
            throw new RuntimeException('Ungültige Wartungs-ID.');
        }

        if (!function_exists('proc_open')) {
            throw new RuntimeException('Der Server erlaubt das Starten eines separaten PHP-CLI-Prozesses nicht.');
        }

        $console = $this->projectDir.'/vendor/bin/contao-console';

        if (!is_file($console) || !is_readable($console)) {
            throw new RuntimeException('Die Contao-Konsole ist für Hintergrundaufträge nicht erreichbar.');
        }

        [$phpCli] = $this->phpCliResolver->resolve();

        // Shell is used ONLY to detach; all dynamic arguments are strictly
        // validated or escaped. The CLI job starts after the HTTP response is
        // normally finished, so a cache rebuild cannot invalidate its kernel.
        $command = 'sleep 3; exec '.escapeshellarg($phpCli).' '
            .escapeshellarg($console).' maintend:maintenance-job '
            .escapeshellarg($requestId).' --env=prod --no-ansi --no-interaction';

        $log = $this->projectDir.'/var/domain-manager/maintenance-jobs/'.$requestId.'.log';
        $shell = 'umask 077; nohup /bin/sh -c '.escapeshellarg($command)
            .' > '.escapeshellarg($log).' 2>&1 < /dev/null &';

        $process = new Process(['/bin/sh', '-c', $shell], $this->projectDir);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Der Hintergrundauftrag konnte nicht gestartet werden. Bitte CLI-Konfiguration und Hosting-Berechtigungen prüfen.');
        }
    }
}
