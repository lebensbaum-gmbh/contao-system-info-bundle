<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use Symfony\Component\Process\Process;
use Throwable;

final class UpdateInstallationFinalizer
{
    private const PROCESS_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly string $projectDir,
        private readonly PhpCliResolver $phpCliResolver,
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
        try {
            return $this->phpCliResolver->resolve();
        } catch (PhpCliResolutionException $exception) {
            throw new UpdateInstallationException($exception->getMessage(), 0, $exception);
        }
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
