<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class UpdateInstallationFinalizer
{
    private const PROCESS_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $configuredPhpCli = '',
    ) {
    }

    /** @return array{php_cli_version: string, completed_at: string} */
    public function migrate(): array
    {
        if (!function_exists('proc_open')) {
            throw new UpdateInstallationException(
                'Die PHP-Funktion proc_open ist auf der Zielinstallation nicht verfügbar. Die Datenbankmigration konnte nicht gestartet werden.'
            );
        }

        $consolePath = $this->projectDir.'/vendor/bin/contao-console';

        if (!is_file($consolePath) || !is_readable($consolePath)) {
            throw new UpdateInstallationException(
                'vendor/bin/contao-console wurde nach dem Composer-Update nicht gefunden. Die Datenbankmigration konnte nicht gestartet werden.'
            );
        }

        [$phpCli, $phpCliVersion] = $this->resolvePhpCli();
        $process = new Process([
            $phpCli,
            $consolePath,
            'contao:migrate',
            '--no-interaction',
            '--no-ansi',
        ], $this->projectDir);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new UpdateInstallationException(
                'Die Datenbankmigration konnte nach dem Composer-Update nicht abgeschlossen werden. Das Sicherheitsbackup bleibt für eine Wiederherstellung verfügbar. Ursache: '.$this->safeDetail($exception->getMessage()),
                0,
                $exception
            );
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new UpdateInstallationException(
                'Die Datenbankmigration ist nach dem Composer-Update fehlgeschlagen. Die Zielinstallation kann sich in einem teilweise migrierten Zustand befinden. Das Sicherheitsbackup bleibt verfügbar. Ursache: '.$this->safeDetail($output)
            );
        }

        return [
            'php_cli_version' => $phpCliVersion,
            'completed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function resolvePhpCli(): array
    {
        $candidates = [];
        $configured = trim($this->configuredPhpCli);

        if ('' !== $configured) {
            $candidates[] = $configured;
        }

        $managerConfig = $this->readManagerConfig();
        $managerPhpCli = trim((string) ($managerConfig['php_cli'] ?? ''));

        if ('' !== $managerPhpCli) {
            $candidates[] = $managerPhpCli;
        }

        $finder = new ExecutableFinder();
        $pathPhp = $finder->find('php');

        if (is_string($pathPhp) && '' !== $pathPhp) {
            $candidates[] = $pathPhp;
        }

        if ('' !== PHP_BINARY) {
            $candidates[] = PHP_BINARY;
        }

        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $expectedVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ('' === $candidate || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            try {
                $probe = new Process([$candidate, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;']);
                $probe->setTimeout(10.0);
                $probe->run();
            } catch (Throwable) {
                continue;
            }

            $version = trim($probe->getOutput());

            if ($probe->isSuccessful() && $expectedVersion === $version) {
                return [$candidate, $version];
            }
        }

        throw new UpdateInstallationException(sprintf(
            'Für die Datenbankmigration wurde kein zur Web-PHP-Version %s passendes PHP-CLI gefunden. Der PHP-Pfad kann über CONTAO_SYSTEM_INFO_PHP_CLI vorgegeben werden.',
            $expectedVersion
        ));
    }

    /** @return array<string, mixed> */
    private function readManagerConfig(): array
    {
        $path = $this->projectDir.'/contao-manager/manager.json';

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (false === $contents || '' === trim($contents)) {
            return [];
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function safeDetail(string $detail): string
    {
        $detail = trim($detail);
        $detail = preg_replace('~https?://[^/@\s]+:[^/@\s]+@~i', 'https://***:***@', $detail) ?? $detail;
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        if ('' === $detail) {
            return 'Unbekannter Fehler bei der Datenbankmigration.';
        }

        if (mb_strlen($detail) > self::MAX_ERROR_DETAIL_LENGTH) {
            $detail = mb_substr($detail, -self::MAX_ERROR_DETAIL_LENGTH);
        }

        return $detail;
    }
}
