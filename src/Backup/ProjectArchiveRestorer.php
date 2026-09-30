<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use RuntimeException;
use splitbrain\PHPArchive\FileInfo;
use splitbrain\PHPArchive\Zip;
use Throwable;

final class ProjectArchiveRestorer
{
    private const ROOT_FILES = [
        'composer.json',
        'composer.lock',
        '.env',
        '.env.local',
        '.env.prod',
        '.env.prod.local',
    ];

    private const EXACT_DIRECTORIES = [
        'config',
        'templates',
        'files',
        'src',
        'contao',
        'app',
    ];

    private const MERGE_DIRECTORIES = [
        'public',
        'web',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return array{file_count:int,source_size:int,restored_roots:list<string>} */
    public function restore(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('Das Projektarchiv für die Wiederherstellung wurde nicht gefunden.');
        }

        $inspection = $this->inspectArchive($archivePath);
        $temporaryDirectory = $this->projectDir.'/var/domain-manager/restores/.tmp-'.bin2hex(random_bytes(12));
        $this->ensureDirectory($temporaryDirectory);

        try {
            $zip = new Zip();
            $zip->open($archivePath);
            $zip->extract($temporaryDirectory);

            $restoredRoots = [];
            $this->restoreRootFiles($temporaryDirectory, $restoredRoots);
            $this->restoreExactDirectories($temporaryDirectory, $restoredRoots);
            $this->restoreMergedDirectories($temporaryDirectory, $restoredRoots);

            return [
                'file_count' => $inspection['file_count'],
                'source_size' => $inspection['source_size'],
                'restored_roots' => array_values(array_unique($restoredRoots)),
            ];
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Die Projektdateien konnten nicht wiederhergestellt werden: '.$exception->getMessage(), 0, $exception);
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    /** @return array{file_count:int,source_size:int} */
    private function inspectArchive(string $archivePath): array
    {
        try {
            $zip = new Zip();
            $zip->open($archivePath);
            $entries = $zip->contents();
        } catch (Throwable $exception) {
            throw new RuntimeException('Das Projektarchiv konnte nicht geprüft werden: '.$exception->getMessage(), 0, $exception);
        }

        $fileCount = 0;
        $sourceSize = 0;
        $seen = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof FileInfo) {
                throw new RuntimeException('Das Projektarchiv enthält einen unbekannten Eintrag.');
            }

            $path = $this->normalizeArchivePath($entry->getPath());

            if (isset($seen[$path])) {
                throw new RuntimeException('Das Projektarchiv enthält einen Pfad mehrfach: '.$path);
            }

            $seen[$path] = true;

            if (!$entry->getIsdir()) {
                ++$fileCount;
                $sourceSize += max(0, (int) $entry->getSize());
            }
        }

        return [
            'file_count' => $fileCount,
            'source_size' => $sourceSize,
        ];
    }

    private function normalizeArchivePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = rtrim($path, '/');

        if (
            '' === $path
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || 1 === preg_match('/\A[A-Za-z]:\//', $path)
        ) {
            throw new RuntimeException('Das Projektarchiv enthält einen ungültigen Pfad.');
        }

        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new RuntimeException('Das Projektarchiv enthält einen unsicheren Pfad: '.$path);
            }
        }

        if (!$this->isAllowedPath($path)) {
            throw new RuntimeException('Das Projektarchiv enthält einen nicht erlaubten Pfad: '.$path);
        }

        return $path;
    }

    private function isAllowedPath(string $path): bool
    {
        if (in_array($path, self::ROOT_FILES, true)) {
            return true;
        }

        foreach ([...self::EXACT_DIRECTORIES, ...self::MERGE_DIRECTORIES] as $directory) {
            if ($path === $directory || str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $restoredRoots */
    private function restoreRootFiles(string $temporaryDirectory, array &$restoredRoots): void
    {
        foreach (self::ROOT_FILES as $relativePath) {
            $source = $temporaryDirectory.'/'.$relativePath;
            $destination = $this->projectDir.'/'.$relativePath;

            if (is_file($source)) {
                $this->replaceFile($source, $destination, str_starts_with($relativePath, '.env') ? 0600 : 0644);
                $restoredRoots[] = $relativePath;
                continue;
            }

            if (is_file($destination) || is_link($destination)) {
                if (!@unlink($destination)) {
                    throw new RuntimeException('Die Datei „'.$relativePath.'“ konnte für die Wiederherstellung nicht entfernt werden.');
                }
            }
        }
    }

    /** @param list<string> $restoredRoots */
    private function restoreExactDirectories(string $temporaryDirectory, array &$restoredRoots): void
    {
        foreach (self::EXACT_DIRECTORIES as $relativeDirectory) {
            $source = $temporaryDirectory.'/'.$relativeDirectory;
            $destination = $this->projectDir.'/'.$relativeDirectory;

            if (is_dir($destination) || is_link($destination)) {
                $this->removeDirectory($destination);
            }

            if (!is_dir($source)) {
                continue;
            }

            if (!@rename($source, $destination)) {
                throw new RuntimeException('Das Verzeichnis „'.$relativeDirectory.'“ konnte nicht wiederhergestellt werden.');
            }

            $restoredRoots[] = $relativeDirectory;
        }
    }

    /** @param list<string> $restoredRoots */
    private function restoreMergedDirectories(string $temporaryDirectory, array &$restoredRoots): void
    {
        foreach (self::MERGE_DIRECTORIES as $relativeDirectory) {
            $source = $temporaryDirectory.'/'.$relativeDirectory;

            if (!is_dir($source)) {
                continue;
            }

            $this->copyDirectory($source, $this->projectDir.'/'.$relativeDirectory);
            $restoredRoots[] = $relativeDirectory;
        }
    }

    private function replaceFile(string $source, string $destination, int $mode): void
    {
        $directory = dirname($destination);
        $this->ensureDirectory($directory);
        $temporary = $destination.'.domain-manager-restore-'.bin2hex(random_bytes(6));

        if (!@copy($source, $temporary)) {
            throw new RuntimeException('Eine Projektdatei konnte für die Wiederherstellung nicht kopiert werden.');
        }

        @chmod($temporary, $mode);

        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Eine Projektdatei konnte nicht atomar wiederhergestellt werden.');
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        $entries = @scandir($source);

        if (false === $entries) {
            throw new RuntimeException('Ein temporäres Restore-Verzeichnis konnte nicht gelesen werden.');
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourcePath = $source.'/'.$entry;
            $destinationPath = $destination.'/'.$entry;

            if (is_dir($sourcePath) && !is_link($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            if (!is_file($sourcePath)) {
                continue;
            }

            $this->replaceFile($sourcePath, $destinationPath, 0644);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Ein Verzeichnis für die Wiederherstellung konnte nicht angelegt werden.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            @unlink($directory);
            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $entries = @scandir($directory);

        if (false === $entries) {
            throw new RuntimeException('Ein Verzeichnis konnte für die Wiederherstellung nicht gelesen werden.');
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        if (!@rmdir($directory) && is_dir($directory)) {
            throw new RuntimeException('Ein Verzeichnis konnte für die Wiederherstellung nicht entfernt werden.');
        }
    }
}
