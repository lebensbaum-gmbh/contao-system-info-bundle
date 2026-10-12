<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Refuse to report a maintenance job as successful when its generated,
 * publicly requested assets cannot be traversed/read by the webserver.
 *
 * This is a non-mutating safety check. Never chmod arbitrary vendor/
 * or user project files: private .env, backup, and job paths must remain private.
 */
final class PublicAssetPermissionGuard
{
    private const ROOTS = [
        'public/bundles',
        'web/bundles',
        'public/assets',
        'web/assets',
        'public/system/themes',
        'web/system/themes',
        'system/themes',
    ];

    private const PUBLIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'svg', 'png', 'jpg', 'jpeg',
        'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function assertWebReadable(): void
    {
        // POSIX permission bits are not meaningful on Windows.
        if ('Windows' === PHP_OS_FAMILY) {
            return;
        }

        $project = realpath($this->projectDir);

        if (false === $project) {
            throw new RuntimeException('Das Projektverzeichnis konnte bei der Rechteprüfung nicht aufgelöst werden.');
        }

        $seen = [];

        foreach (self::ROOTS as $root) {
            $path = $this->projectDir.'/'.$root;

            if (!is_dir($path)) {
                continue;
            }

            $this->checkTree($path, $project, $seen);

            // Core themes are often symlinks into vendor/contao/core-bundle.
            // The directory iterator does not follow those by default.
            if (str_ends_with($root, '/themes')) {
                foreach (scandir($path) ?: [] as $child) {
                    if ('.' === $child || '..' === $child || !is_dir($path.'/'.$child)) {
                        continue;
                    }

                    $this->checkTree($path.'/'.$child, $project, $seen);
                }
            }
        }
    }

    /** @param array<string, bool> $seen */
    private function checkTree(string $path, string $project, array &$seen): void
    {
        $resolved = realpath($path);

        // Never traverse arbitrary external symlink targets.
        if (false === $resolved || !str_starts_with($resolved.'/', rtrim($project, '/').'/')) {
            return;
        }

        if (isset($seen[$resolved])) {
            return;
        }
        $seen[$resolved] = true;

        $this->checkDirectoryAndParents($resolved, $project);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                $entryPath = $entry->getPathname();

                if ($entry->isLink()) {
                    continue;
                }

                if ($entry->isDir()) {
                    $this->assertDirectoryTraversable($entryPath);
                    continue;
                }

                if ($entry->isFile() && in_array(strtolower($entry->getExtension()), self::PUBLIC_EXTENSIONS, true)) {
                    $this->assertFileReadable($entryPath);
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Öffentliche Contao-Assets konnten nach der Wartung nicht geprüft werden: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function checkDirectoryAndParents(string $directory, string $project): void
    {
        $current = $directory;

        while (str_starts_with($current.'/', rtrim($project, '/').'/')) {
            $this->assertDirectoryTraversable($current);

            if ($current === $project) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }
    }

    private function assertDirectoryTraversable(string $directory): void
    {
        clearstatcache(true, $directory);
        $mode = @fileperms($directory);

        // Owner-only 0700 prevents a separate Apache/PHP user from entering.
        // Group-accessible modes remain allowed for group-based deployments.
        if (false === $mode || 0 === ($mode & 0011)) {
            throw new RuntimeException(
                'Öffentliche Contao-Assets sind wegen fehlender Verzeichnisrechte nicht erreichbar: '
                .$this->relativePath($directory)
                .' (benötigt Ausführungs-/Traversierungsrecht für den Webserver).'
            );
        }
    }

    private function assertFileReadable(string $file): void
    {
        clearstatcache(true, $file);
        $mode = @fileperms($file);

        // Owner-only 0600 cannot be served by Apache running as another user.
        if (false === $mode || 0 === ($mode & 0044)) {
            throw new RuntimeException(
                'Öffentliches Contao-Asset ist nicht lesbar: '.$this->relativePath($file)
                .' (Dateirechte prüfen; Wartung nicht als erfolgreich melden).'
            );
        }
    }

    private function relativePath(string $path): string
    {
        $prefix = rtrim($this->projectDir, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
