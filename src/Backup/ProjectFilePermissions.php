<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use JsonException;
use RuntimeException;

/**
 * Unix permissions of explicitly archived project files/directories.
 *
 * The manifest lives inside project.zip, so the backup's existing SHA-256
 * protects file contents and modes together. No UID/GID or symlink targets
 * are stored or restored.
 */
final class ProjectFilePermissions
{
    public const ENTRY = '.maintend-permissions.json';

    /** @var array<string, array{type: 'file'|'dir', mode: int}> */
    private array $paths = [];

    public function record(string $relativePath, string $absolutePath, string $type): void
    {
        if (!in_array($type, ['file', 'dir'], true) || is_link($absolutePath)) {
            throw new RuntimeException('Ungültiger oder verlinkter Pfad in der Berechtigungssicherung.');
        }

        $mode = @fileperms($absolutePath);

        if (false === $mode || 0 !== ($mode & 07000)) {
            throw new RuntimeException('Unix-Sonderrechte können nicht sicher gesichert werden: '.$relativePath);
        }

        $this->paths[$relativePath] = [
            'type' => $type,
            'mode' => $mode & 0777,
        ];
    }

    public function toJson(): string
    {
        ksort($this->paths);

        return json_encode([
            'schema' => 1,
            'paths' => $this->paths,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /**
     * Parse and validate the entire manifest before changing any project file.
     * The complete ZIP is already integrity-checked against the backup manifest.
     *
     * @param list<string> $archivedFiles
     * @param callable(string):bool $allowedPath
     */
    public static function parse(string $json, array $archivedFiles, callable $allowedPath): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Das Dateirechte-Manifest ist ungültig.', 0, $exception);
        }

        if (!is_array($data) || 1 !== ($data['schema'] ?? null) || !is_array($data['paths'] ?? null)) {
            throw new RuntimeException('Das Dateirechte-Manifest hat ein unbekanntes Format.');
        }

        $manifest = new self();
        $entries = $data['paths'];

        if (count($entries) > 250000) {
            throw new RuntimeException('Das Dateirechte-Manifest ist ungewöhnlich groß.');
        }

        foreach ($entries as $path => $entry) {
            if (!is_string($path)
                || '' === $path
                || str_contains($path, "\0")
                || str_contains($path, '\\')
                || str_starts_with($path, '/')
                || preg_match('~(^|/)(\\.|\\.\\.|)(/|$)~', $path)
                || !$allowedPath($path)
                || !is_array($entry)
                || !in_array($entry['type'] ?? null, ['file', 'dir'], true)
                || !isset($entry['mode'])
                || !is_int($entry['mode'])
                || $entry['mode'] < 0
                || $entry['mode'] > 0777
            ) {
                throw new RuntimeException('Das Dateirechte-Manifest enthält einen unsicheren Eintrag.');
            }

            $manifest->paths[$path] = ['type' => $entry['type'], 'mode' => $entry['mode']];
        }

        $expected = array_fill_keys($archivedFiles, true);

        foreach ($expected as $path => $_) {
            if (!isset($manifest->paths[$path]) || 'file' !== $manifest->paths[$path]['type']) {
                throw new RuntimeException('Für eine gesicherte Projektdatei fehlen die Dateirechte: '.$path);
            }
        }

        foreach ($manifest->paths as $path => $entry) {
            if ('file' === $entry['type'] && !isset($expected[$path])) {
                throw new RuntimeException('Dateirechte für eine nicht archivierte Datei: '.$path);
            }
        }

        return $manifest;
    }

    /**
     * Existing public/web directories are merged, not replaced. Refuse any
     * symlink in managed destination paths before the restore starts; otherwise
     * a user-defined symlink could redirect a restored file outside the project.
     */
    public function assertDestinationSafe(string $projectDir): void
    {
        foreach ($this->paths as $path => $_) {
            if (!in_array($path, ['public', 'web'], true)
                && !str_starts_with($path, 'public/')
                && !str_starts_with($path, 'web/')
            ) {
                continue;
            }

            $current = rtrim($projectDir, '/');

            foreach (explode('/', $path) as $segment) {
                $current .= '/'.$segment;

                if (is_link($current)) {
                    throw new RuntimeException('Die vorhandene öffentliche Projektstruktur enthält einen Symlink im Restore-Pfad: '.$path);
                }
            }
        }
    }

    /**
     * Restore only paths explicitly included in the backup. Do not follow
     * symlinks, apply permissions to generated vendor/ files, or chmod secrets
     * outside the approved project roots. Directory modes are applied last.
     */
    public function apply(string $projectDir): void
    {
        $dirs = [];

        foreach ($this->paths as $path => $entry) {
            if ('dir' === $entry['type']) {
                $dirs[$path] = $entry['mode'];
            }
        }

        // Empty directories were not represented in older ZIP archives.
        // Create them before changing any saved restrictive directory mode.
        uksort($dirs, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

        foreach ($dirs as $path => $_) {
            $this->assertSafeComponents($projectDir, $path, false);
            $absolute = $projectDir.'/'.$path;

            if (!is_dir($absolute) && !@mkdir($absolute, 0700)) {
                throw new RuntimeException('Ein gesichertes Projektverzeichnis konnte nicht erstellt werden: '.$path);
            }
        }

        foreach ($this->paths as $path => $entry) {
            if ('file' !== $entry['type']) {
                continue;
            }

            $this->assertSafeComponents($projectDir, $path, true);
            $absolute = $projectDir.'/'.$path;
            $mode = $entry['mode'];

            // Never make environment secrets world/group-readable on restore,
            // even if a source installation had unsafe .env permissions.
            if (in_array($path, ['.env', '.env.local', '.env.prod', '.env.prod.local'], true)) {
                $mode &= 0700;
            }

            if (!@chmod($absolute, $mode)) {
                throw new RuntimeException('Gesicherte Dateirechte konnten nicht gesetzt werden: '.$path);
            }
        }

        // Parent directories last (deepest first), preserving 0750, 0700 etc.
        uksort($dirs, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($dirs as $path => $mode) {
            $this->assertSafeComponents($projectDir, $path, false);

            if (!@chmod($projectDir.'/'.$path, $mode)) {
                throw new RuntimeException('Gesicherte Verzeichnisrechte konnten nicht gesetzt werden: '.$path);
            }
        }
    }

    private function assertSafeComponents(string $projectDir, string $relativePath, bool $file): void
    {
        $root = rtrim($projectDir, '/');
        $segments = explode('/', $relativePath);
        $current = $root;

        foreach ($segments as $index => $segment) {
            $current .= '/'.$segment;

            if (is_link($current)) {
                throw new RuntimeException('Dateirechte dürfen nicht über einen Symlink geändert werden: '.$relativePath);
            }

            $last = $index === count($segments) - 1;

            if ($last && $file) {
                if (!is_file($current)) {
                    throw new RuntimeException('Die gesicherte Datei fehlt bei der Rechtewiederherstellung: '.$relativePath);
                }
            } elseif (file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('Ein Projektpfad ist kein Verzeichnis: '.$relativePath);
            }
        }
    }
}
