<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use Composer\InstalledVersions;
use JsonException;
use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolutionException;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class RestoreManagementAgentPreserver
{
    private const PACKAGE = 'lebensbaum/contao-system-info-bundle';
    private const COMPOSER_TIMEOUT = 600.0;
    private const CACHE_TIMEOUT = 300.0;
    private const MAX_ERROR_DETAIL_LENGTH = 1200;

    public function __construct(
        private readonly ComposerDryRunParser $dryRunParser,
        private readonly string $projectDir,
        private readonly PhpCliResolver $phpCliResolver,
        private readonly string $configuredManagerPath = '',
    ) {
    }

    /**
     * @return array{
     *     section: 'require'|'require-dev',
     *     constraint: string,
     *     version: string,
     *     reference: string
     * }
     */
    public function capture(): array
    {
        $composer = $this->readJsonFile(
            $this->projectDir.'/composer.json',
            'Die aktuelle composer.json konnte vor der Wiederherstellung nicht gelesen werden.'
        );
        $lock = $this->readJsonFile(
            $this->projectDir.'/composer.lock',
            'Die aktuelle composer.lock konnte vor der Wiederherstellung nicht gelesen werden.'
        );

        [$section, $constraint] = $this->rootRequirement($composer);
        $locked = $this->lockedPackage($lock);

        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            throw new RuntimeException('System Info ist im aktuell installierten Vendor-Stand nicht vorhanden. Die Wiederherstellung wurde vor dem ersten Eingriff abgebrochen.');
        }

        $installedVersion = trim((string) (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? ''));
        $installedReference = trim((string) (InstalledVersions::getReference(self::PACKAGE) ?? ''));

        if ('' === $installedVersion || !$this->sameVersion($locked['version'], $installedVersion)) {
            throw new RuntimeException(sprintf(
                'Der installierte System-Info-Stand (%s) stimmt nicht mit composer.lock (%s) überein. Die Wiederherstellung wurde vor dem ersten Eingriff abgebrochen.',
                '' !== $installedVersion ? $installedVersion : 'unbekannt',
                $locked['version']
            ));
        }

        if ('' !== $locked['reference'] && '' !== $installedReference && !hash_equals($locked['reference'], $installedReference)) {
            throw new RuntimeException('Die installierte System-Info-Reference stimmt nicht mit composer.lock überein. Die Wiederherstellung wurde vor dem ersten Eingriff abgebrochen.');
        }

        return [
            'section' => $section,
            'constraint' => $constraint,
            'version' => $locked['version'],
            'reference' => $locked['reference'],
        ];
    }

    /**
     * @param array{section:string,constraint:string,version:string,reference:string} $state
     *
     * @return array{
     *     management_agent_preserved: bool,
     *     management_agent_version: string,
     *     management_agent_reference: string,
     *     cache_rebuilt: bool
     * }
     */
    public function restore(array $state): array
    {
        $state = $this->validateState($state);
        $composerJsonPath = $this->projectDir.'/composer.json';
        $restoredComposerJson = @file_get_contents($composerJsonPath);

        if (false === $restoredComposerJson) {
            throw new RuntimeException('Die wiederhergestellte composer.json konnte für den Erhalt von System Info nicht gelesen werden.');
        }

        $restoredComposer = $this->decodeJson(
            $restoredComposerJson,
            'Die wiederhergestellte composer.json ist ungültig.'
        );
        [$restoredSection] = $this->rootRequirement($restoredComposer);

        if ($restoredSection !== $state['section']) {
            throw new RuntimeException('System Info befindet sich im Backup in einem anderen Composer-Bereich als vor der Wiederherstellung. Der Verwaltungsagent wird aus Sicherheitsgründen nicht automatisch verändert.');
        }

        [$phpCli] = $this->resolvePhpCli();
        [$commandPrefix] = $this->resolveComposerCommand($phpCli);

        $actualUpdateStarted = false;

        try {
            $this->restoreRootConstraint($commandPrefix, $state);
            $dryRun = $this->runComposer(
                array_merge(
                    $commandPrefix,
                    ['update', self::PACKAGE.':'.$this->temporaryConstraint($state)],
                    $this->composerUpdateArguments(true)
                ),
                'Der System-Info-Stand konnte vor dem abschließenden Restore-Schritt nicht sicher geprüft werden.'
            );

            $this->assertSafeDryRun($dryRun);
            $actualUpdateStarted = true;

            $this->runComposer(
                array_merge(
                    $commandPrefix,
                    ['update', self::PACKAGE.':'.$this->temporaryConstraint($state)],
                    $this->composerUpdateArguments(false)
                ),
                'Der System-Info-Stand vor der Wiederherstellung konnte nicht wiederhergestellt werden.'
            );
        } catch (Throwable $exception) {
            if (!$actualUpdateStarted) {
                $this->restoreFile($composerJsonPath, $restoredComposerJson);
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('System Info konnte nach dem Projekt-Restore nicht auf dem Verwaltungsstand vor der Wiederherstellung gehalten werden: '.$this->safeDetail($exception->getMessage()), 0, $exception);
        }

        $this->verifyRestoredState($state);
        $this->rebuildCache($phpCli);

        return [
            'management_agent_preserved' => true,
            'management_agent_version' => $state['version'],
            'management_agent_reference' => $state['reference'],
            'cache_rebuilt' => true,
        ];
    }

    /**
     * @param list<string> $commandPrefix
     * @param array{section:string,constraint:string,version:string,reference:string} $state
     */
    private function restoreRootConstraint(array $commandPrefix, array $state): void
    {
        $arguments = [
            'require',
            self::PACKAGE.':'.$state['constraint'],
            '--no-update',
            '--no-scripts',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
        ];

        if ('require-dev' === $state['section']) {
            $arguments[] = '--dev';
        }

        $this->runComposer(
            array_merge($commandPrefix, $arguments),
            'Die System-Info-Anforderung in composer.json konnte nach dem Projekt-Restore nicht wiederhergestellt werden.'
        );
    }

    /** @return list<string> */
    private function composerUpdateArguments(bool $dryRun): array
    {
        $arguments = [
            '--no-scripts',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
            '--optimize-autoloader',
        ];

        if ($dryRun) {
            $arguments[] = '--dry-run';
        }

        return $arguments;
    }

    /** @param array{section:string,constraint:string,version:string,reference:string} $state */
    private function temporaryConstraint(array $state): string
    {
        $constraint = $state['version'];

        if (str_starts_with($constraint, 'dev-') && '' !== $state['reference']) {
            $constraint .= '#'.$state['reference'];
        }

        return $constraint;
    }

    private function assertSafeDryRun(string $output): void
    {
        $plan = $this->dryRunParser->parse($output);
        $summary = $plan['summary'];

        if (0 !== $summary['installs'] || 0 !== $summary['removals'] || $summary['updates'] > 1) {
            throw new RuntimeException(sprintf(
                'Der Sicherheits-Dry-Run zum Erhalt von System Info würde weitere Composer-Änderungen auslösen (%d Installationen, %d Updates, %d Entfernungen). Der Restore wird nicht als vollständig abgeschlossen gemeldet.',
                $summary['installs'],
                $summary['updates'],
                $summary['removals']
            ));
        }

        foreach ($plan['operations'] as $operation) {
            if ('update' !== $operation['type'] || self::PACKAGE !== $operation['package']) {
                throw new RuntimeException('Der Sicherheits-Dry-Run zum Erhalt von System Info enthält eine unerwartete Paketänderung: '.$operation['package'].'. Der Restore wird nicht als vollständig abgeschlossen gemeldet.');
            }
        }
    }

    /** @param array{section:string,constraint:string,version:string,reference:string} $state */
    private function verifyRestoredState(array $state): void
    {
        $composer = $this->readJsonFile(
            $this->projectDir.'/composer.json',
            'composer.json konnte nach dem System-Info-Restore nicht verifiziert werden.'
        );
        $lock = $this->readJsonFile(
            $this->projectDir.'/composer.lock',
            'composer.lock konnte nach dem System-Info-Restore nicht verifiziert werden.'
        );
        [$section, $constraint] = $this->rootRequirement($composer);
        $locked = $this->lockedPackage($lock);

        if ($section !== $state['section'] || $constraint !== $state['constraint']) {
            throw new RuntimeException('Die System-Info-Anforderung in composer.json entspricht nach dem Restore nicht dem Zustand vor der Wiederherstellung.');
        }

        if (!$this->sameVersion($locked['version'], $state['version'])) {
            throw new RuntimeException(sprintf(
                'System Info hat nach dem Restore die Version %s statt des vorherigen Stands %s.',
                $locked['version'],
                $state['version']
            ));
        }

        if ('' !== $state['reference'] && !hash_equals($state['reference'], $locked['reference'])) {
            throw new RuntimeException('Die System-Info-Reference entspricht nach dem Restore nicht dem Zustand vor der Wiederherstellung.');
        }
    }

    /** @param array<string, mixed> $composer @return array{0:'require'|'require-dev',1:string} */
    private function rootRequirement(array $composer): array
    {
        $found = [];

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? null;

            if (!is_array($requirements) || !array_key_exists(self::PACKAGE, $requirements)) {
                continue;
            }

            $constraint = $requirements[self::PACKAGE];

            if (!is_string($constraint) || '' === trim($constraint)) {
                throw new RuntimeException('Die System-Info-Anforderung in composer.json ist ungültig.');
            }

            $found[] = [$section, trim($constraint)];
        }

        if (1 !== count($found)) {
            throw new RuntimeException('System Info muss für eine sichere Wiederherstellung genau einmal direkt in composer.json eingetragen sein.');
        }

        /** @var array{0:'require'|'require-dev',1:string} $result */
        $result = $found[0];

        return $result;
    }

    /** @param array<string, mixed> $lock @return array{version:string,reference:string} */
    private function lockedPackage(array $lock): array
    {
        $matches = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (!is_array($package) || self::PACKAGE !== ($package['name'] ?? null)) {
                    continue;
                }

                $version = isset($package['version']) && is_string($package['version']) ? trim($package['version']) : '';
                $source = is_array($package['source'] ?? null) ? $package['source'] : [];
                $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
                $reference = '';

                foreach ([$source['reference'] ?? null, $dist['reference'] ?? null] as $candidate) {
                    if (is_string($candidate) && '' !== trim($candidate)) {
                        $reference = trim($candidate);
                        break;
                    }
                }

                if ('' === $version) {
                    throw new RuntimeException('System Info hat in composer.lock keine gültige Version.');
                }

                $matches[] = ['version' => $version, 'reference' => $reference];
            }
        }

        if (1 !== count($matches)) {
            throw new RuntimeException('System Info muss für eine sichere Wiederherstellung genau einmal in composer.lock vorhanden sein.');
        }

        return $matches[0];
    }

    /** @param array{section:string,constraint:string,version:string,reference:string} $state @return array{section:'require'|'require-dev',constraint:string,version:string,reference:string} */
    private function validateState(array $state): array
    {
        $section = $state['section'] ?? '';
        $constraint = trim((string) ($state['constraint'] ?? ''));
        $version = trim((string) ($state['version'] ?? ''));
        $reference = trim((string) ($state['reference'] ?? ''));

        if (!in_array($section, ['require', 'require-dev'], true) || '' === $constraint || '' === $version) {
            throw new RuntimeException('Der vor dem Restore gesicherte System-Info-Zustand ist ungültig.');
        }

        return ['section' => $section, 'constraint' => $constraint, 'version' => $version, 'reference' => $reference];
    }

    private function sameVersion(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === $right) {
            return true;
        }

        if (preg_match('/\Av?\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.-]+)?\z/', $left)
            && preg_match('/\Av?\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.-]+)?\z/', $right)) {
            return ltrim($left, 'v') === ltrim($right, 'v');
        }

        return false;
    }

    /** @return array{0:string,1:string} */
    private function resolvePhpCli(): array
    {
        try {
            return $this->phpCliResolver->resolve();
        } catch (PhpCliResolutionException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{0:list<string>,1:string} */
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

        throw new RuntimeException('Weder der Contao Manager noch ein nutzbarer Composer wurde für den Erhalt von System Info gefunden.');
    }

    /** @param list<string> $command */
    private function runComposer(array $command, string $errorMessage): string
    {
        $process = new Process($command, $this->projectDir, ['COMPOSER_MEMORY_LIMIT' => '-1']);
        $process->setTimeout(self::COMPOSER_TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException($errorMessage.' Ursache: '.$this->safeDetail($exception->getMessage()), 0, $exception);
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new RuntimeException($errorMessage.' Ursache: '.$this->safeDetail($output));
        }

        return $output;
    }

    private function rebuildCache(string $phpCli): void
    {
        $consolePath = $this->projectDir.'/vendor/bin/contao-console';

        if (!is_file($consolePath) || !is_readable($consolePath)) {
            throw new RuntimeException('vendor/bin/contao-console wurde nach dem Erhalt von System Info nicht gefunden. Der Prod-Cache konnte nicht neu aufgebaut werden.');
        }

        $process = new Process([$phpCli, $consolePath, 'cache:clear', '--env=prod', '--no-ansi'], $this->projectDir);
        $process->setTimeout(self::CACHE_TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException('Der Prod-Cache konnte nach dem Erhalt von System Info nicht neu aufgebaut werden. Ursache: '.$this->safeDetail($exception->getMessage()), 0, $exception);
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Der Prod-Cache konnte nach dem Erhalt von System Info nicht neu aufgebaut werden. Ursache: '.$this->safeDetail($output));
        }
    }

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
    private function readJsonFile(string $path, string $errorMessage): array
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            throw new RuntimeException($errorMessage);
        }

        return $this->decodeJson($content, $errorMessage);
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $content, string $errorMessage): array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($errorMessage, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException($errorMessage);
        }

        return $decoded;
    }

    private function restoreFile(string $path, string $content): void
    {
        $temporary = $path.'.domain-manager-agent-rollback-'.bin2hex(random_bytes(6));

        if (false === @file_put_contents($temporary, $content, LOCK_EX)) {
            throw new RuntimeException('composer.json konnte nach einem abgebrochenen System-Info-Dry-Run nicht zurückgesetzt werden.');
        }

        @chmod($temporary, 0644);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('composer.json konnte nach einem abgebrochenen System-Info-Dry-Run nicht atomar zurückgesetzt werden.');
        }
    }

    private function safeDetail(string $detail): string
    {
        $detail = trim($detail);
        $detail = preg_replace('~https?://[^/@\s]+:[^/@\s]+@~i', 'https://***:***@', $detail) ?? $detail;
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        if ('' === $detail) {
            return 'Unbekannter Fehler beim Erhalt von System Info.';
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
