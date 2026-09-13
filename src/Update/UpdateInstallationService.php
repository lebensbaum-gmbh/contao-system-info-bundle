<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class UpdateInstallationService
{
    private const PROCESS_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly UpdatePreparationService $preparationService,
        private readonly string $projectDir,
        private readonly string $configuredPhpCli = '',
        private readonly string $configuredManagerPath = '',
    ) {
    }

    /**
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    public function install(string $systemId, array $expected): array
    {
        $requestId = $this->requestId($expected['request_id'] ?? null);
        $currentVersion = $this->versionValue($expected['current_contao_version'] ?? null, 'aktuelle Contao-Version');
        $targetVersion = $this->versionValue($expected['target_contao_version'] ?? null, 'Ziel-Contao-Version');
        $composerJsonHash = $this->hashValue($expected['composer_json_sha256'] ?? null, 'composer.json');
        $composerLockHash = $this->hashValue($expected['composer_lock_sha256'] ?? null, 'composer.lock');
        $expectedOperations = $this->operations($expected['operations'] ?? null);

        if (0 >= version_compare(ltrim($targetVersion, 'vV'), ltrim($currentVersion, 'vV'))) {
            throw new UpdateInstallationException('Die vorgesehene Zielversion ist nicht neuer als die aktuell vorbereitete Contao-Version.');
        }

        $preflight = $this->preparationService->prepare($systemId, $requestId);
        $prepared = $preflight['update_preparation'] ?? null;

        if (!is_array($prepared)) {
            throw new UpdateInstallationException('Der Sicherheits-Dry-Run hat keine gültigen Vorbereitungsdaten geliefert.');
        }

        if ('ready' !== ($prepared['status'] ?? null)) {
            throw new UpdateInstallationException('Der Sicherheits-Dry-Run meldet das Update nicht mehr als installierbar. Bitte die Update-Vorbereitung erneut ausführen.');
        }

        $this->assertSameString($currentVersion, $prepared['current_contao_version'] ?? null, 'aktuelle Contao-Version');
        $this->assertSameString($targetVersion, $prepared['target_contao_version'] ?? null, 'Ziel-Contao-Version');
        $this->assertSameString($composerJsonHash, $prepared['composer_json_sha256'] ?? null, 'Prüfsumme von composer.json');
        $this->assertSameString($composerLockHash, $prepared['composer_lock_sha256'] ?? null, 'Prüfsumme von composer.lock');

        $actualOperations = $this->operations($prepared['operations'] ?? null);

        if ($this->normalizeOperations($expectedOperations) !== $this->normalizeOperations($actualOperations)) {
            throw new UpdateInstallationException('Der aktuelle Composer-Dry-Run weicht vom vorbereiteten Paketplan ab. Bitte die Update-Vorbereitung erneut ausführen.');
        }

        $composerJsonPath = $this->projectDir.'/composer.json';
        $composerLockPath = $this->projectDir.'/composer.lock';
        $composerJsonContents = $this->readRequiredFile($composerJsonPath, 'composer.json');
        $composerLockContents = $this->readRequiredFile($composerLockPath, 'composer.lock');

        if (!hash_equals($composerJsonHash, hash('sha256', $composerJsonContents))
            || !hash_equals($composerLockHash, hash('sha256', $composerLockContents))
        ) {
            throw new UpdateInstallationException('composer.json oder composer.lock wurden seit der Vorbereitung verändert. Bitte die Update-Vorbereitung erneut ausführen.');
        }

        $composerJson = $this->decodeJson($composerJsonContents, 'composer.json');
        $composerLock = $this->decodeJson($composerLockContents, 'composer.lock');
        $installedVersion = $this->installedContaoVersion($composerLock);

        if (0 !== version_compare(ltrim($installedVersion, 'vV'), ltrim($currentVersion, 'vV'))) {
            throw new UpdateInstallationException('Die tatsächlich installierte Contao-Version entspricht nicht mehr dem vorbereiteten Ausgangsstand. Bitte die Update-Vorbereitung erneut ausführen.');
        }

        $contaoPackages = $this->contaoPackages($composerJson);

        if ([] === $contaoPackages) {
            throw new UpdateInstallationException('In der composer.json wurden keine direkt eingebundenen Contao-Pakete gefunden.');
        }

        [$phpCli, $phpCliVersion] = $this->resolvePhpCli();
        [$commandPrefix, $composerDriver] = $this->resolveComposerCommand($phpCli);
        $packageArguments = $this->pinnedPackageArguments($contaoPackages, $targetVersion);
        $command = array_merge(
            $commandPrefix,
            ['update'],
            $packageArguments,
            [
                '--with-dependencies',
                '--minimal-changes',
                '--patch-only',
                '--no-dev',
                '--no-progress',
                '--no-ansi',
                '--no-interaction',
                '--optimize-autoloader',
            ]
        );

        $process = new Process(
            $command,
            $this->projectDir,
            ['COMPOSER_MEMORY_LIMIT' => '-1']
        );
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new UpdateInstallationException(
                'Die Composer-Installation konnte nicht abgeschlossen werden: '.$this->safeDetail($exception->getMessage()),
                0,
                $exception
            );
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new UpdateInstallationException(
                'Composer konnte das vorbereitete Update nicht installieren. Die Zielinstallation kann sich in einem teilweise aktualisierten Zustand befinden. Das vorgeschaltete Sicherheitsbackup bleibt verfügbar. Ursache: '.$this->safeDetail($output)
            );
        }

        $composerJsonAfter = $this->readRequiredFile($composerJsonPath, 'composer.json');
        $composerLockAfter = $this->readRequiredFile($composerLockPath, 'composer.lock');

        if (!hash_equals($composerJsonHash, hash('sha256', $composerJsonAfter))) {
            throw new UpdateInstallationException('composer.json wurde während der Installation unerwartet verändert. Das Update muss manuell geprüft werden.');
        }

        $lockAfter = $this->decodeJson($composerLockAfter, 'composer.lock');
        $installedTargetVersion = $this->installedContaoVersion($lockAfter);

        if (0 !== version_compare(ltrim($installedTargetVersion, 'vV'), ltrim($targetVersion, 'vV'))) {
            throw new UpdateInstallationException(sprintf(
                'Nach der Installation wurde Contao %s statt der vorbereiteten Zielversion %s erkannt. Das Update muss manuell geprüft werden.',
                $installedTargetVersion,
                $targetVersion
            ));
        }

        return [
            'system_id' => $systemId,
            'api_version' => 1,
            'update_installation' => [
                'id' => $requestId,
                'status' => 'completed',
                'current_contao_version' => $currentVersion,
                'target_contao_version' => $targetVersion,
                'installed_contao_version' => $installedTargetVersion,
                'php_version' => PHP_VERSION,
                'php_cli_version' => $phpCliVersion,
                'composer_driver' => $composerDriver,
                'composer_json_sha256' => hash('sha256', $composerJsonAfter),
                'composer_lock_sha256' => hash('sha256', $composerLockAfter),
                'completed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
        ];
    }

    private function requestId(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $value)) {
            throw new UpdateInstallationException('Die Update-Installation enthält keine gültige Request-ID.');
        }

        return $value;
    }

    private function versionValue(mixed $value, string $label): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (1 !== preg_match('/\Av?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $value)) {
            throw new UpdateInstallationException(sprintf('Die %s ist ungültig.', $label));
        }

        return $value;
    }

    private function hashValue(mixed $value, string $label): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new UpdateInstallationException(sprintf('Die vorbereitete Prüfsumme für %s ist ungültig.', $label));
        }

        return $value;
    }

    /**
     * @return list<array{type: string, package: string, from: string, to: string}>
     */
    private function operations(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UpdateInstallationException('Der vorbereitete Composer-Paketplan fehlt oder ist ungültig.');
        }

        $operations = [];

        foreach ($value as $operation) {
            if (!is_array($operation)) {
                throw new UpdateInstallationException('Der vorbereitete Composer-Paketplan enthält einen ungültigen Eintrag.');
            }

            $type = isset($operation['type']) && is_string($operation['type']) ? trim($operation['type']) : '';
            $package = isset($operation['package']) && is_string($operation['package']) ? strtolower(trim($operation['package'])) : '';
            $from = isset($operation['from']) && is_string($operation['from']) ? trim($operation['from']) : '';
            $to = isset($operation['to']) && is_string($operation['to']) ? trim($operation['to']) : '';

            if (!in_array($type, ['install', 'update', 'remove'], true)
                || 1 !== preg_match('/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/', $package)
            ) {
                throw new UpdateInstallationException('Der vorbereitete Composer-Paketplan enthält einen ungültigen Eintrag.');
            }

            $operations[] = [
                'type' => $type,
                'package' => $package,
                'from' => $from,
                'to' => $to,
            ];
        }

        if ([] === $operations) {
            throw new UpdateInstallationException('Der vorbereitete Composer-Paketplan ist leer.');
        }

        return $operations;
    }

    /**
     * @param list<array{type: string, package: string, from: string, to: string}> $operations
     * @return list<array{type: string, package: string, from: string, to: string}>
     */
    private function normalizeOperations(array $operations): array
    {
        usort($operations, static function (array $left, array $right): int {
            return [$left['package'], $left['type'], $left['from'], $left['to']]
                <=> [$right['package'], $right['type'], $right['from'], $right['to']];
        });

        return array_values($operations);
    }

    private function assertSameString(string $expected, mixed $actual, string $label): void
    {
        $actual = is_string($actual) ? trim($actual) : '';

        if (!hash_equals($expected, $actual)) {
            throw new UpdateInstallationException(sprintf('Der Sicherheits-Dry-Run liefert eine abweichende %s. Bitte die Update-Vorbereitung erneut ausführen.', $label));
        }
    }

    /** @param list<string> $packages @return list<string> */
    private function pinnedPackageArguments(array $packages, string $targetVersion): array
    {
        $arguments = [];

        foreach ($packages as $package) {
            if ('contao/conflicts' === $package) {
                $arguments[] = $package;
                continue;
            }

            $arguments[] = $package.':'.ltrim($targetVersion, 'vV');
        }

        return $arguments;
    }

    private function readRequiredFile(string $path, string $label): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UpdateInstallationException($label.' wurde im Projektverzeichnis nicht gefunden oder ist nicht lesbar.');
        }

        $contents = file_get_contents($path);

        if (false === $contents || '' === trim($contents)) {
            throw new UpdateInstallationException($label.' konnte nicht gelesen werden oder ist leer.');
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $contents, string $label): array
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateInstallationException($label.' enthält kein gültiges JSON.', 0, $exception);
        }

        if (!is_array($data)) {
            throw new UpdateInstallationException($label.' enthält keine gültige JSON-Struktur.');
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

        throw new UpdateInstallationException('Die installierte Contao-Version konnte in composer.lock nicht ermittelt werden.');
    }

    /** @param array<string, mixed> $composerJson @return list<string> */
    private function contaoPackages(array $composerJson): array
    {
        $require = $composerJson['require'] ?? null;

        if (!is_array($require)) {
            return [];
        }

        $packages = [];

        foreach (array_keys($require) as $package) {
            if (!is_string($package)) {
                continue;
            }

            $package = strtolower(trim($package));

            if (str_starts_with($package, 'contao/')) {
                $packages[] = $package;
            }
        }

        if (!in_array('contao/manager-bundle', $packages, true)
            && !in_array('contao/core-bundle', $packages, true)
        ) {
            return [];
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
            'Es wurde kein zur Web-PHP-Version %s passendes PHP-CLI gefunden. Der PHP-Pfad kann über CONTAO_SYSTEM_INFO_PHP_CLI vorgegeben werden.',
            $expectedVersion
        ));
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

        throw new UpdateInstallationException('Weder der Contao Manager noch ein nutzbarer Composer wurde auf der Zielinstallation gefunden.');
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
