<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

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
     * @return array{size:int,sha256:string,file_count:int,source_size:int,included_roots:list<string>,skipped_symlinks:int,format:string}
     */
    public function build(string $targetPath): array
    {
        if (!str_ends_with($targetPath, '.zip')) {
            throw new RuntimeException('Das Projektarchiv muss die Endung .zip haben.');
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Zip-Erweiterung (ext-zip) ist nicht verfügbar.');
        }

        $targetDirectory = dirname($targetPath);
        $this->ensureDirectory($targetDirectory);
        @unlink($targetPath);

        $fileCount = 0;
        $sourceSize = 0;
        $skippedSymlinks = 0;
        $includedRoots = [];
        $archive = new ZipArchive();
        $openResult = $archive->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if (true !== $openResult) {
            throw new RuntimeException(sprintf('Das ZIP-Projektarchiv konnte nicht geöffnet werden (Code %s).', (string) $openResult));
        }

        try {
            foreach (self::ROOT_FILES as $relativePath) {
                $absolutePath = $this->projectDir.'/'.$relativePath;

                if (!is_file($absolutePath)) {
                    continue;
                }

                if (is_link($absolutePath)) {
                    ++$skippedSymlinks;
                    continue;
                }

                $this->addFile($archive, $absolutePath, $relativePath);
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

            if (!$archive->close()) {
                throw new RuntimeException('Das ZIP-Projektarchiv konnte nicht abgeschlossen werden.');
            }

            @chmod($targetPath, 0600);

            if (!is_file($targetPath)) {
                throw new RuntimeException('Das ZIP-Projektarchiv wurde nicht erzeugt.');
            }

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
                'format' => 'zip',
            ];
        } catch (Throwable $exception) {
            try {
                $archive->close();
            } catch (Throwable) {
            }

            @unlink($targetPath);

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
        ZipArchive $archive,
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
            $this->addFile($archive, $absolutePath, $archivePath);
            ++$fileCount;
            $sourceSize += max(0, (int) $item->getSize());
            $includedRoots[$relativeDirectory] = true;
        }
    }

    private function addFile(ZipArchive $archive, string $absolutePath, string $archivePath): void
    {
        if (!$archive->addFile($absolutePath, $archivePath)) {
            throw new RuntimeException(sprintf('Die Datei „%s“ konnte dem Projektarchiv nicht hinzugefügt werden.', $archivePath));
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
