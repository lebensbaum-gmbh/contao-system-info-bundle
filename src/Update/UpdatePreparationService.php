<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class UpdatePreparationService
{
    private const PROCESS_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly ComposerDryRunParser $parser,
        private readonly UpdatePolicy $policy,
        private readonly string $projectDir,
        private readonly string $configuredPhpCli = '',
        private readonly string $configuredManagerPath = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(string $systemId, string $requestId): array
    {
        $requestId = strtolower(trim($requestId));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)) {
            throw new UpdatePreparationException('Die Update-Anfrage enthält keine gültige Request-ID.');
        }

        $composerJsonPath = $this->projectDir.'/composer.json';
        $composerLockPath = $this->projectDir.'/composer.lock';
        $composerJsonContents = $this->readRequiredFile($composerJsonPath, 'composer.json');
        $composerLockContents = $this->readRequiredFile($composerLockPath, 'composer.lock');
        $composerJson = $this->decodeJson($composerJsonContents, 'composer.json');
        $composerLock = $this->decodeJson($composerLockContents, 'composer.lock');
        $currentContaoVersion = $this->installedContaoVersion($composerLock);
        $directPackages = $this->directPackages($composerJson);

        if ([] === $directPackages) {
            throw new UpdatePreparationException('In der composer.json wurden keine aktualisierbaren direkten Pakete gefunden.');
        }

        $before = [
            'composer_json_sha256' => hash('sha256', $composerJsonContents),
            'composer_lock_sha256' => hash('sha256', $composerLockContents),
        ];

        [$phpCli, $phpCliVersion] = $this->resolvePhpCli();
        [$commandPrefix, $composerDriver] = $this->resolveComposerCommand($phpCli);
        $command = array_merge(
            $commandPrefix,
            ['update'],
            $directPackages,
            [
                '--with-dependencies',
                '--no-install',
                '--no-scripts',
                '--no-dev',
                '--no-progress',
                '--no-ansi',
                '--no-interaction',
                '--optimize-autoloader',
                '--dry-run',
                '--no-plugins',
            ]
        );

        $process = new Process(
            $command,
            $this->projectDir,
            ['COMPOSER_MEMORY_LIMIT' => '-1']
        );
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $processException = null;

        try {
            $process->run();
        } catch (Throwable $exception) {
            $processException = $exception;
        }

        $composerFilesChanged = !$this->matchesContents($composerJsonPath, $composerJsonContents)
            || !$this->matchesContents($composerLockPath, $composerLockContents);

        if ($composerFilesChanged) {
            $restored = $this->restoreComposerFiles(
                $composerJsonPath,
                $composerJsonContents,
                $composerLockPath,
                $composerLockContents
            );

            if (!$restored) {
                throw new UpdatePreparationException(
                    'Der Composer-Dry-Run hat Projektdateien verändert und der ursprüngliche Zustand konnte nicht vollständig wiederhergestellt werden.'
                );
            }

            throw new UpdatePreparationException(
                'Der Composer-Dry-Run hat composer.json oder composer.lock verändert. Die Originaldateien wurden wiederhergestellt; die Vorbereitung wurde aus Sicherheitsgründen abgebrochen.'
            );
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (null !== $processException) {
            throw new UpdatePreparationException(
                'Der Composer-Dry-Run konnte nicht abgeschlossen werden: '.$this->safeDetail($processException->getMessage())
            );
        }

        if (!$process->isSuccessful()) {
            throw new UpdatePreparationException(
                'Composer konnte die Update-Abhängigkeiten nicht auflösen: '.$this->safeDetail($output)
            );
        }

        $parsed = $this->parser->parse($output);
        $targetContaoVersion = $this->parser->targetContaoVersion($parsed['operations'], $currentContaoVersion);
        $policy = $this->policy->evaluate($currentContaoVersion, $targetContaoVersion);
        $completedAt = new \DateTimeImmutable();

        return [
            'system_id' => $systemId,
            'api_version' => 1,
            'update_preparation' => [
                'id' => $requestId,
                'status' => $policy['allowed'] ? 'ready' : 'blocked',
                'current_contao_version' => $currentContaoVersion,
                'target_contao_version' => $targetContaoVersion,
                'php_version' => PHP_VERSION,
                'php_cli_version' => $phpCliVersion,
                'composer_driver' => $composerDriver,
                'summary' => $parsed['summary'],
                'operations' => $parsed['operations'],
                'blocked_reason' => $policy['reason'],
                'composer_json_sha256' => $before['composer_json_sha256'],
                'composer_lock_sha256' => $before['composer_lock_sha256'],
                'project_unchanged' => true,
                'completed_at' => $completedAt->format(DATE_ATOM),
            ],
        ];
    }

    private function readRequiredFile(string $path, string $label): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UpdatePreparationException($label.' wurde im Projektverzeichnis nicht gefunden oder ist nicht lesbar.');
        }

        $contents = file_get_contents($path);

        if (false === $contents || '' === trim($contents)) {
            throw new UpdatePreparationException($label.' konnte nicht gelesen werden oder ist leer.');
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $contents, string $label): array
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdatePreparationException($label.' enthält kein gültiges JSON.', 0, $exception);
        }

        if (!is_array($data)) {
            throw new UpdatePreparationException($label.' enthält keine gültige JSON-Struktur.');
        }

        return $data;
    }

    /** @param array<string, mixed> $composerLock */
    private function installedContaoVersion(array $composerLock): string
    {
        $packages = [];

        foreach (['packages', 'packages-dev'] as $key) {
            if (is_array($composerLock[$key] ?? null)) {
                $packages = array_merge($packages, $composerLock[$key]);
            }
        }

        foreach (['contao/core-bundle', 'contao/manager-bundle'] as $wantedPackage) {
            foreach ($packages as $package) {
                if (!is_array($package) || $wantedPackage !== ($package['name'] ?? null)) {
                    continue;
                }

                $version = trim((string) ($package['version'] ?? ''));

                if ('' !== $version) {
                    return $version;
                }
            }
        }

        throw new UpdatePreparationException('Die aktuell installierte Contao-Version konnte in composer.lock nicht ermittelt werden.');
    }

    /** @param array<string, mixed> $composerJson @return list<string> */
    private function directPackages(array $composerJson): array
    {
        $require = $composerJson['require'] ?? null;

        if (!is_array($require)) {
            return [];
        }

        $packages = [];

        foreach (array_keys($require) as $package) {
            if (is_string($package) && str_contains($package, '/')) {
                $packages[] = strtolower($package);
            }
        }

        sort($packages);

        return array_values(array_unique($packages));
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
        $seen = [];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ('' === $candidate || isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;

            if (!is_file($candidate) || !is_executable($candidate)) {
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

        throw new UpdatePreparationException(
            sprintf(
                'Es wurde kein zur Web-PHP-Version %s passendes PHP-CLI gefunden. Der im Contao Manager konfigurierte PHP-Pfad kann alternativ über CONTAO_SYSTEM_INFO_PHP_CLI vorgegeben werden.',
                $expectedVersion
            )
        );
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

        throw new UpdatePreparationException(
            'Weder der Contao Manager noch ein nutzbarer Composer wurde auf der Zielinstallation gefunden.'
        );
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

    private function matchesContents(string $path, string $expected): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $actual = file_get_contents($path);

        return is_string($actual) && hash_equals(hash('sha256', $expected), hash('sha256', $actual));
    }

    private function restoreComposerFiles(
        string $composerJsonPath,
        string $composerJsonContents,
        string $composerLockPath,
        string $composerLockContents,
    ): bool {
        $jsonWritten = file_put_contents($composerJsonPath, $composerJsonContents, LOCK_EX);
        $lockWritten = file_put_contents($composerLockPath, $composerLockContents, LOCK_EX);

        if (false === $jsonWritten || false === $lockWritten) {
            return false;
        }

        return $this->matchesContents($composerJsonPath, $composerJsonContents)
            && $this->matchesContents($composerLockPath, $composerLockContents);
    }

    private function safeDetail(string $detail): string
    {
        $detail = trim($detail);
        $detail = preg_replace('~https?://[^/@\s]+:[^/@\s]+@~i', 'https://***:***@', $detail) ?? $detail;
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        if ('' === $detail) {
            return 'Unbekannter Composer-Fehler.';
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
