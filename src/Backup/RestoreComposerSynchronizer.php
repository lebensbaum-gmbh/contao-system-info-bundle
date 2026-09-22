<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class RestoreComposerSynchronizer
{
    private const COMPOSER_TIMEOUT = 600.0;
    private const CACHE_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly string $projectDir,
        private readonly \Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver $phpCliResolver,
        private readonly string $configuredManagerPath = '',
    ) {
    }

    /**
     * @return array{
     *     composer_driver: string,
     *     php_cli_version: string,
     *     composer_synchronized: bool,
     *     cache_rebuilt: bool
     * }
     */
    public function synchronize(): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                'Die PHP-Funktion proc_open ist auf der Zielinstallation nicht verfügbar. Der Composer-Stand des Backups konnte nicht wiederhergestellt werden.'
            );
        }

        $composerJsonPath = $this->projectDir.'/composer.json';
        $composerLockPath = $this->projectDir.'/composer.lock';

        if (!is_file($composerJsonPath) || !is_readable($composerJsonPath)) {
            throw new RuntimeException('Die wiederhergestellte composer.json wurde nicht gefunden oder ist nicht lesbar.');
        }

        if (!is_file($composerLockPath) || !is_readable($composerLockPath)) {
            throw new RuntimeException('Die wiederhergestellte composer.lock wurde nicht gefunden oder ist nicht lesbar.');
        }

        [$phpCli, $phpCliVersion] = $this->resolvePhpCli();
        [$commandPrefix, $composerDriver] = $this->resolveComposerCommand($phpCli);
        $process = new Process(
            array_merge($commandPrefix, ['install'], $this->composerInstallArguments()),
            $this->projectDir,
            ['COMPOSER_MEMORY_LIMIT' => '-1']
        );
        $process->setTimeout(self::COMPOSER_TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Der Composer-Stand des Backups konnte nicht vollständig wiederhergestellt werden. Das vor dem Restore erzeugte Sicherheitsbackup bleibt verfügbar. Ursache: '.$this->safeDetail($exception->getMessage()),
                0,
                $exception
            );
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'composer install gegen die wiederhergestellte composer.lock ist fehlgeschlagen. Die Zielinstallation kann sich in einem teilweise wiederhergestellten Zustand befinden. Das vor dem Restore erzeugte Sicherheitsbackup bleibt verfügbar. Ursache: '.$this->safeDetail($output)
            );
        }

        $consolePath = $this->projectDir.'/vendor/bin/contao-console';

        if (!is_file($consolePath) || !is_readable($consolePath)) {
            throw new RuntimeException(
                'vendor/bin/contao-console wurde nach der Composer-Wiederherstellung nicht gefunden. Der Cache konnte nicht neu aufgebaut werden.'
            );
        }

        $cacheProcess = new Process([
            $phpCli,
            $consolePath,
            'cache:clear',
            '--env=prod',
            '--no-ansi',
        ], $this->projectDir);
        $cacheProcess->setTimeout(self::CACHE_TIMEOUT);

        try {
            $cacheProcess->run();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Der Prod-Cache konnte nach der Composer-Wiederherstellung nicht neu aufgebaut werden. Das vor dem Restore erzeugte Sicherheitsbackup bleibt verfügbar. Ursache: '.$this->safeDetail($exception->getMessage()),
                0,
                $exception
            );
        }

        $cacheOutput = trim($cacheProcess->getOutput()."\n".$cacheProcess->getErrorOutput());

        if (!$cacheProcess->isSuccessful()) {
            throw new RuntimeException(
                'Der Prod-Cache konnte nach der Composer-Wiederherstellung nicht neu aufgebaut werden. Die Wiederherstellung gilt nicht als vollständig abgeschlossen. Ursache: '.$this->safeDetail($cacheOutput)
            );
        }

        return [
            'composer_driver' => $composerDriver,
            'php_cli_version' => $phpCliVersion,
            'composer_synchronized' => true,
            'cache_rebuilt' => true,
        ];
    }

    /** @return list<string> */
    private function composerInstallArguments(): array
    {
        return [
            '--no-scripts',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
            '--optimize-autoloader',
        ];
    }

    /** @return array{0:string,1:string} */
    private function resolvePhpCli(): array
    {
        try {
            return $this->phpCliResolver->resolve();
        } catch (\Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolutionException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{0: list<string>, 1: string} */
    private function resolveComposerCommand(string $phpCli): array
    {
        $managerPath = $this->findManagerPath();

        if (null !== $managerPath) {
            return [[
                $phpCli,
                '-q',
                '-dmax_execution_time=0',
                '-dmemory_limit=-1',
                '-ddisplay_errors=0',
                '-ddisplay_startup_errors=0',
                '-derror_reporting=0',
                '-dallow_url_fopen=1',
                '-ddate.timezone=UTC',
                $managerPath,
                'composer',
            ], 'contao-manager'];
        }

        $composerPhar = $this->projectDir.'/composer.phar';

        if (is_file($composerPhar) && is_readable($composerPhar)) {
            return [[$phpCli, '-dmemory_limit=-1', $composerPhar], 'composer-phar'];
        }

        $composerBinary = (new ExecutableFinder())->find('composer');

        if (is_string($composerBinary) && '' !== $composerBinary && is_file($composerBinary)) {
            return [[$phpCli, '-dmemory_limit=-1', $composerBinary], 'composer-binary'];
        }

        throw new RuntimeException('Weder der Contao Manager noch ein nutzbarer Composer wurde auf der Zielinstallation gefunden.');
    }

    private function findManagerPath(): ?string
    {
        $configured = trim($this->configuredManagerPath);
        $candidates = [];

        if ('' !== $configured) {
            if ($this->isAbsolutePath($configured)) {
                $candidates[] = $configured;
            } else {
                $candidates[] = $this->projectDir.'/'.$configured;
                $candidates[] = $this->projectDir.'/public/'.$configured;
                $candidates[] = $this->projectDir.'/web/'.$configured;
            }
        }

        $candidates[] = $this->projectDir.'/public/contao-manager.phar.php';
        $candidates[] = $this->projectDir.'/web/contao-manager.phar.php';

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

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
            return 'Unbekannter Fehler bei der Composer-Wiederherstellung.';
        }

        if (mb_strlen($detail) > self::MAX_ERROR_DETAIL_LENGTH) {
            $detail = mb_substr($detail, -self::MAX_ERROR_DETAIL_LENGTH);
        }

        return $detail;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
    }
}
