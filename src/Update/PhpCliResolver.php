<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class PhpCliResolver
{
    private const PROBE_TIMEOUT = 10.0;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $configuredPhpCli = '',
    ) {
    }

    /** @return array{0: string, 1: string} */
    public function resolve(?string $expectedVersion = null): array
    {
        $expectedVersion = trim((string) $expectedVersion);

        if ('' === $expectedVersion) {
            $expectedVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        }

        if (1 !== preg_match('/\A\d+\.\d+\z/', $expectedVersion)) {
            throw new PhpCliResolutionException('Die erwartete PHP-Version ist ungültig.');
        }

        $candidates = $this->candidatePaths($expectedVersion);
        $diagnostics = [];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_executable($candidate)) {
                $diagnostics[] = $candidate.' (nicht ausführbar)';
                continue;
            }

            try {
                $probe = new Process([$candidate, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;']);
                $probe->setTimeout(self::PROBE_TIMEOUT);
                $probe->run();
            } catch (Throwable $exception) {
                $diagnostics[] = $candidate.' (Prüfung fehlgeschlagen)';
                continue;
            }

            $version = trim($probe->getOutput());

            if ($probe->isSuccessful() && $expectedVersion === $version) {
                return [$candidate, $version];
            }

            if ('' !== $version) {
                $diagnostics[] = $candidate.' (PHP '.$version.')';
            } else {
                $diagnostics[] = $candidate.' (keine Versionsantwort)';
            }
        }

        $detail = implode(', ', array_slice($diagnostics, 0, 16));

        if (count($diagnostics) > 16) {
            $detail .= sprintf(', … +%d weitere', count($diagnostics) - 16);
        }

        throw new PhpCliResolutionException(sprintf(
            'Es wurde kein zur Web-PHP-Version %s passendes PHP-CLI gefunden.%s Falls der Hoster einen abweichenden Pfad verwendet, kann dieser über CONTAO_SYSTEM_INFO_PHP_CLI vorgegeben werden.',
            $expectedVersion,
            '' !== $detail ? ' Geprüft: '.$detail.'.' : ''
        ));
    }

    /** @return list<string> */
    private function candidatePaths(string $expectedVersion): array
    {
        $candidates = [];
        $configured = trim($this->configuredPhpCli);

        if ('' !== $configured) {
            $candidates[] = $configured;
        }

        $managerPhpCli = trim((string) ($this->readManagerConfig()['php_cli'] ?? ''));

        if ('' !== $managerPhpCli) {
            $candidates[] = $managerPhpCli;
        }

        [$major, $minor] = explode('.', $expectedVersion, 2);
        $compactVersion = $major.$minor;
        $versionedNames = [
            'php'.$compactVersion,
            'php'.$expectedVersion,
            'php'.$expectedVersion.'-cli',
        ];

        $finder = new ExecutableFinder();

        foreach ($versionedNames as $binaryName) {
            $resolved = $finder->find($binaryName);

            if (is_string($resolved) && '' !== $resolved) {
                $candidates[] = $resolved;
            }
        }

        $pathPhp = $finder->find('php');

        if (is_string($pathPhp) && '' !== $pathPhp) {
            $candidates[] = $pathPhp;
        }

        if ('' !== PHP_BINARY) {
            $candidates[] = PHP_BINARY;
        }

        foreach (['/usr/bin', '/usr/local/bin'] as $directory) {
            foreach ($versionedNames as $binaryName) {
                $candidates[] = $directory.'/'.$binaryName;
            }
        }

        // Common layouts used by Plesk, cPanel, CloudLinux and other shared hosters.
        $candidates[] = '/opt/plesk/php/'.$expectedVersion.'/bin/php';
        $candidates[] = '/opt/cpanel/ea-php'.$compactVersion.'/root/usr/bin/php';
        $candidates[] = '/opt/alt/php'.$compactVersion.'/usr/bin/php';
        $candidates[] = '/usr/local/php'.$compactVersion.'/bin/php';
        $candidates[] = '/usr/local/php'.$expectedVersion.'/bin/php';
        $candidates[] = '/opt/php'.$compactVersion.'/bin/php';
        $candidates[] = '/opt/php/'.$expectedVersion.'/bin/php';
        $candidates[] = '/opt/php-'.$expectedVersion.'/bin/php';

        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        return array_values(array_unique(array_filter(
            array_map(static fn (string $candidate): string => trim($candidate), $candidates),
            static fn (string $candidate): bool => '' !== $candidate
        )));
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
}
