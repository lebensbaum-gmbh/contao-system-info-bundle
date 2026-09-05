<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class ProjectArchiveBuilder
{
    private const ROOT_FILES = [
        'composer.json',
        'composer.lock',
        '.env',
        '.env.local',
        '.env.prod',
        '.env.prod.local',
    ];

    private const ROOT_DIRECTORIES = [
        'config',
        'templates',
        'files',
        'src',
        'contao',
        'app',
    ];

    private const PUBLIC_DIRECTORIES = [
        'public',
        'web',
    ];

    private const GENERATED_PUBLIC_ROOTS = [
        'assets',
        'bundles',
        'files',
        'share',
        'system',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{size:int,sha256:string,file_count:int,source_size:int,included_roots:list<string>,skipped_symlinks:int}
     */
    public function build(string $targetPath): array
    {
        if (!str_ends_with($targetPath, '.tar.gz')) {
            throw new RuntimeException('Das Projektarchiv muss die Endung .tar.gz haben.');
        }

        if (!class_exists(PharData::class)) {
            throw new RuntimeException('Die PHP-Phar-Erweiterung ist nicht verfügbar.');
        }

        $targetDirectory = dirname($targetPath);
        $this->ensureDirectory($targetDirectory);

        $tarPath = substr($targetPath, 0, -3);
        @unlink($targetPath);
        @unlink($tarPath);
        @unlink($tarPath.'.gz');

        $fileCount = 0;
        $sourceSize = 0;
        $skippedSymlinks = 0;
        $includedRoots = [];

        try {
            $archive = new PharData($tarPath);

            foreach (self::ROOT_FILES as $relativePath) {
                $absolutePath = $this->projectDir.'/'.$relativePath;

                if (!is_file($absolutePath)) {
                    continue;
                }

                if (is_link($absolutePath)) {
                    ++$skippedSymlinks;
                    continue;
                }

                $archive->addFile($absolutePath, $relativePath);
                ++$fileCount;
                $sourceSize += max(0, (int) @filesize($absolutePath));
                $includedRoots[$relativePath] = true;
            }

            foreach (self::ROOT_DIRECTORIES as $relativeDirectory) {
                $this->addDirectory(
                    $archive,
                    $relativeDirectory,
                    [],
                    $fileCount,
                    $sourceSize,
                    $skippedSymlinks,
                    $includedRoots
                );
            }

            foreach (self::PUBLIC_DIRECTORIES as $relativeDirectory) {
                $this->addDirectory(
                    $archive,
                    $relativeDirectory,
                    self::GENERATED_PUBLIC_ROOTS,
                    $fileCount,
                    $sourceSize,
                    $skippedSymlinks,
                    $includedRoots
                );
            }

            $archive->compress(Phar::GZ);
            unset($archive);

            if (!is_file($tarPath.'.gz')) {
                throw new RuntimeException('Das komprimierte Projektarchiv wurde nicht erzeugt.');
            }

            if (!@rename($tarPath.'.gz', $targetPath)) {
                throw new RuntimeException('Das Projektarchiv konnte nicht an den Zielort verschoben werden.');
            }

            @unlink($tarPath);
            @chmod($targetPath, 0600);

            $checksum = hash_file('sha256', $targetPath);

            if (false === $checksum) {
                throw new RuntimeException('Die Prüfsumme des Projektarchivs konnte nicht berechnet werden.');
            }

            return [
                'size' => max(0, (int) @filesize($targetPath)),
                'sha256' => $checksum,
                'file_count' => $fileCount,
                'source_size' => $sourceSize,
                'included_roots' => array_values(array_keys($includedRoots)),
                'skipped_symlinks' => $skippedSymlinks,
            ];
        } catch (Throwable $exception) {
            @unlink($targetPath);
            @unlink($tarPath);
            @unlink($tarPath.'.gz');

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Das Projektarchiv konnte nicht erstellt werden: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param list<string> $excludedTopLevelRoots
     * @param array<string, bool> $includedRoots
     */
    private function addDirectory(
        PharData $archive,
        string $relativeDirectory,
        array $excludedTopLevelRoots,
        int &$fileCount,
        int &$sourceSize,
        int &$skippedSymlinks,
        array &$includedRoots,
    ): void {
        $absoluteDirectory = $this->projectDir.'/'.$relativeDirectory;

        if (!is_dir($absoluteDirectory) || is_link($absoluteDirectory)) {
            if (is_link($absoluteDirectory)) {
                ++$skippedSymlinks;
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $absolutePath = $item->getPathname();
            $relativeWithinRoot = ltrim(substr($absolutePath, strlen($absoluteDirectory)), DIRECTORY_SEPARATOR);
            $relativeWithinRoot = str_replace(DIRECTORY_SEPARATOR, '/', $relativeWithinRoot);
            $topLevel = explode('/', $relativeWithinRoot, 2)[0] ?? '';

            if (in_array($topLevel, $excludedTopLevelRoots, true)) {
                continue;
            }

            if ($item->isLink()) {
                ++$skippedSymlinks;
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $archivePath = $relativeDirectory.'/'.$relativeWithinRoot;
            $archive->addFile($absolutePath, $archivePath);
            ++$fileCount;
            $sourceSize += max(0, (int) $item->getSize());
            $includedRoots[$relativeDirectory] = true;
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Backup-Verzeichnis konnte nicht angelegt werden.');
        }

        @chmod($directory, 0700);
    }
}
