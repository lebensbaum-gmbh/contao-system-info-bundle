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
        private readonly PhpCliResolver $phpCliResolver,
        private readonly string $configuredManagerPath = '',
        private readonly ?UpdateProgressStore $progressStore = null,
    ) {
    }

    /**
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    public function install(string $systemId, array $expected): array
    {
        $requestId = $this->requestId($expected['request_id'] ?? null);
        $this->writeProgress($requestId, 'preflight', 'running', 'Sicherheitsprüfung wird ausgeführt.');

        $currentVersion = $this->versionValue($expected['current_contao_version'] ?? null, 'aktuelle Contao-Version');
        $targetVersion = $this->versionValue($expected['target_contao_version'] ?? null, 'Ziel-Contao-Version');
        $composerJsonHash = $this->hashValue($expected['composer_json_sha256'] ?? null, 'composer.json');
        $composerLockHash = $this->hashValue($expected['composer_lock_sha256'] ?? null, 'composer.lock');
        $expectedOperations = $this->operations($expected['operations'] ?? null);

        if (0 >= version_compare(ltrim($targetVersion, 'vV'), ltrim($currentVersion, 'vV'))) {
            throw new UpdateInstallationException('Die vorgesehene Zielversion ist nicht neuer als die aktuell vorbereitete Contao-Version.');
        }

        $preflight = $this->preparationService->prepare($systemId, $requestId, $targetVersion);
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

        $expectedComposerJsonAfter = $this->rewriteExactContaoConstraints(
            $composerJsonContents,
            $composerJson,
            $currentVersion,
            $targetVersion
        );

        if (!hash_equals(hash('sha256', $composerJsonContents), hash('sha256', $expectedComposerJsonAfter))) {
            $written = file_put_contents($composerJsonPath, $expectedComposerJsonAfter, LOCK_EX);

            if (false === $written || !$this->matchesContents($composerJsonPath, $expectedComposerJsonAfter)) {
                throw new UpdateInstallationException(
                    'Die composer.json konnte nicht sicher auf die vorbereitete Contao-Zielversion umgestellt werden. Das Update wurde vor Composer abgebrochen.'
                );
            }
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

        $this->writeProgress($requestId, 'preflight', 'success', 'Sicherheitsprüfung erfolgreich abgeschlossen.');
        $this->writeProgress($requestId, 'composer', 'running', 'Composer-Update wird ausgeführt.');

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

        $this->writeProgress($requestId, 'composer', 'success', 'Composer-Update erfolgreich abgeschlossen.');
        $this->writeProgress($requestId, 'verify', 'running', 'Installierte Contao-Version wird verifiziert.');

        $composerJsonAfter = $this->readRequiredFile($composerJsonPath, 'composer.json');
        $composerLockAfter = $this->readRequiredFile($composerLockPath, 'composer.lock');

        if (!hash_equals(hash('sha256', $expectedComposerJsonAfter), hash('sha256', $composerJsonAfter))) {
            throw new UpdateInstallationException('composer.json weicht nach der Installation von der vorbereiteten Zielkonfiguration ab. Das Update muss manuell geprüft werden.');
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

        $this->writeProgress($requestId, 'verify', 'success', 'Installierte Contao-Version erfolgreich verifiziert.');

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

    /** @param array<string, mixed> $composerJson */
    private function rewriteExactContaoConstraints(
        string $contents,
        array $composerJson,
        string $currentVersion,
        string $targetVersion,
    ): string {
        $require = $composerJson['require'] ?? null;

        if (!is_array($require)) {
            return $contents;
        }

        foreach ($require as $package => $constraint) {
            if (
                !is_string($package)
                || !is_string($constraint)
                || !str_starts_with(strtolower($package), 'contao/')
                || 'contao/conflicts' === strtolower($package)
                || !$this->isExactVersionConstraintFor($constraint, $currentVersion)
            ) {
                continue;
            }

            $packageToken = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $constraintToken = json_encode($constraint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $targetToken = json_encode(ltrim($targetVersion, 'vV'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $pattern = '/('.preg_quote($packageToken, '/').'\s*:\s*)'.preg_quote($constraintToken, '/').'/';
            $count = 0;
            $updated = preg_replace($pattern, '$1'.$targetToken, $contents, 1, $count);

            if (!is_string($updated) || 1 !== $count) {
                throw new UpdateInstallationException(sprintf(
                    'Die exakte Contao-Vorgabe für %s konnte nicht sicher auf die Zielversion umgestellt werden.',
                    $package
                ));
            }

            $contents = $updated;
        }

        return $contents;
    }

    private function isExactVersionConstraintFor(string $constraint, string $version): bool
    {
        $constraint = trim($constraint);

        if (1 !== preg_match('/\Av?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $constraint)) {
            return false;
        }

        return 0 === version_compare(ltrim($constraint, 'vV'), ltrim($version, 'vV'));
    }

    private function matchesContents(string $path, string $expected): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $actual = file_get_contents($path);

        return is_string($actual) && hash_equals(hash('sha256', $expected), hash('sha256', $actual));
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
        try {
            return $this->phpCliResolver->resolve();
        } catch (PhpCliResolutionException $exception) {
            throw new UpdateInstallationException($exception->getMessage(), 0, $exception);
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

    private function writeProgress(string $requestId, string $phase, string $status, string $message): void
    {
        if (null === $this->progressStore) {
            return;
        }

        try {
            $this->progressStore->write($requestId, $phase, $status, $message);
        } catch (Throwable) {
            // Progress reporting must never block or abort the actual update.
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
    }
}
