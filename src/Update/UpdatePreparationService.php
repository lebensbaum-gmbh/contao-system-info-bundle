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
        private readonly PhpCliResolver $phpCliResolver,
        private readonly string $configuredManagerPath = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(string $systemId, string $requestId, ?string $requestedTargetVersion = null): array
    {
        $requestId = strtolower(trim($requestId));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)) {
            throw new UpdatePreparationException('Die Update-Anfrage enthält keine gültige Request-ID.');
        }

        if (!function_exists('proc_open')) {
            throw new UpdatePreparationException(
                'Die PHP-Funktion proc_open ist auf der Zielinstallation nicht verfügbar. Ohne Prozessausführung kann der Composer-Dry-Run nicht gestartet werden.'
            );
        }

        $composerJsonPath = $this->projectDir.'/composer.json';
        $composerLockPath = $this->projectDir.'/composer.lock';
        $composerJsonContents = $this->readRequiredFile($composerJsonPath, 'composer.json');
        $composerLockContents = $this->readRequiredFile($composerLockPath, 'composer.lock');
        $composerJson = $this->decodeJson($composerJsonContents, 'composer.json');
        $composerLock = $this->decodeJson($composerLockContents, 'composer.lock');
        $currentContaoVersion = $this->installedContaoVersion($composerLock);
        $requestedTargetVersion = $this->normalizeRequestedTargetVersion($requestedTargetVersion);
        $contaoPackages = $this->contaoPackages($composerJson);

        if ([] === $contaoPackages) {
            throw new UpdatePreparationException('In der composer.json wurden keine direkt eingebundenen Contao-Pakete gefunden.');
        }

        $before = [
            'composer_json_sha256' => hash('sha256', $composerJsonContents),
            'composer_lock_sha256' => hash('sha256', $composerLockContents),
        ];

        [$phpCli, $phpCliVersion] = $this->resolvePhpCli();
        [$commandPrefix, $composerDriver] = $this->resolveComposerCommand($phpCli);

        $temporaryComposerJsonContents = $composerJsonContents;
        $packageArguments = $contaoPackages;

        if (null !== $requestedTargetVersion) {
            $policy = $this->policy->evaluate($currentContaoVersion, $requestedTargetVersion);

            if (!$policy['allowed']) {
                return [
                    'system_id' => $systemId,
                    'api_version' => 1,
                    'update_preparation' => [
                        'id' => $requestId,
                        'status' => 'blocked',
                        'current_contao_version' => $currentContaoVersion,
                        'target_contao_version' => $requestedTargetVersion,
                        'php_version' => PHP_VERSION,
                        'php_cli_version' => $phpCliVersion,
                        'composer_driver' => $composerDriver,
                        'summary' => ['installs' => 0, 'updates' => 0, 'removals' => 0],
                        'operations' => [],
                        'blocked_reason' => $policy['reason'],
                        'composer_json_sha256' => $before['composer_json_sha256'],
                        'composer_lock_sha256' => $before['composer_lock_sha256'],
                        'project_unchanged' => true,
                        'completed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ],
                ];
            }

            $temporaryComposerJsonContents = $this->rewriteExactContaoConstraints(
                $composerJsonContents,
                $composerJson,
                $currentContaoVersion,
                $requestedTargetVersion
            );
            $packageArguments = $this->pinnedPackageArguments($contaoPackages, $requestedTargetVersion);

            if (!hash_equals(
                hash('sha256', $composerJsonContents),
                hash('sha256', $temporaryComposerJsonContents)
            )) {
                $written = file_put_contents($composerJsonPath, $temporaryComposerJsonContents, LOCK_EX);

                if (false === $written || !$this->matchesContents($composerJsonPath, $temporaryComposerJsonContents)) {
                    $this->restoreComposerFiles(
                        $composerJsonPath,
                        $composerJsonContents,
                        $composerLockPath,
                        $composerLockContents
                    );

                    throw new UpdatePreparationException(
                        'Die composer.json konnte für die temporäre Prüfung der Zielversion nicht sicher vorbereitet werden.'
                    );
                }
            }
        }

        $command = array_merge(
            $commandPrefix,
            ['update'],
            $packageArguments,
            [
                '--with-dependencies',
                '--minimal-changes',
                '--patch-only',
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

        $unexpectedComposerChange = !$this->matchesContents($composerJsonPath, $temporaryComposerJsonContents);
        $unexpectedLockChange = !$this->matchesContents($composerLockPath, $composerLockContents);
        $needsRestore = null !== $requestedTargetVersion
            || $unexpectedComposerChange
            || $unexpectedLockChange;

        if ($needsRestore && !$this->restoreComposerFiles(
            $composerJsonPath,
            $composerJsonContents,
            $composerLockPath,
            $composerLockContents
        )) {
            throw new UpdatePreparationException(
                'Der Composer-Dry-Run hat Projektdateien verändert und der ursprüngliche Zustand konnte nicht vollständig wiederhergestellt werden.'
            );
        }

        if ($unexpectedComposerChange || $unexpectedLockChange) {
            throw new UpdatePreparationException(
                'Der Composer-Dry-Run hat composer.json oder composer.lock unerwartet verändert. Die Originaldateien wurden wiederhergestellt; die Vorbereitung wurde aus Sicherheitsgründen abgebrochen.'
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
        $resolvedTargetVersion = $this->parser->targetContaoVersion($parsed['operations'], $currentContaoVersion);
        $targetContaoVersion = $requestedTargetVersion ?? $resolvedTargetVersion;

        if (
            null !== $requestedTargetVersion
            && 0 !== version_compare(ltrim($resolvedTargetVersion, 'vV'), ltrim($requestedTargetVersion, 'vV'))
        ) {
            throw new UpdatePreparationException(sprintf(
                'Composer hat die angeforderte Contao-Zielversion %s nicht als Paketplan aufgelöst. Ermittelt wurde %s.',
                $requestedTargetVersion,
                $resolvedTargetVersion
            ));
        }

        $policy = $this->policy->evaluate($currentContaoVersion, $targetContaoVersion);
        $sameContaoVersion = 0 === version_compare(ltrim($currentContaoVersion, 'vV'), ltrim($targetContaoVersion, 'vV'));
        $status = $policy['allowed'] ? ($sameContaoVersion ? 'up_to_date' : 'ready') : 'blocked';
        $completedAt = new \DateTimeImmutable();

        if ('up_to_date' === $status) {
            $parsed = [
                'summary' => ['installs' => 0, 'updates' => 0, 'removals' => 0],
                'operations' => [],
            ];
        }

        return [
            'system_id' => $systemId,
            'api_version' => 1,
            'update_preparation' => [
                'id' => $requestId,
                'status' => $status,
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

    private function normalizeRequestedTargetVersion(?string $version): ?string
    {
        if (null === $version || '' === trim($version)) {
            return null;
        }

        $version = trim($version);

        if (1 !== preg_match('/\Av?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version)) {
            throw new UpdatePreparationException('Die angeforderte Contao-Zielversion ist ungültig.');
        }

        return $version;
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
                throw new UpdatePreparationException(sprintf(
                    'Die exakte Contao-Vorgabe für %s konnte für die temporäre Update-Prüfung nicht sicher angepasst werden.',
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
            throw new UpdatePreparationException($exception->getMessage(), 0, $exception);
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
