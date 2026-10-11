<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use Composer\InstalledVersions;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use JsonException;
use RuntimeException;
use Throwable;

final class BackupService
{
    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly ProjectArchiveBuilder $projectArchiveBuilder,
        private readonly VirtualFilesystemInterface $contaoBackupsStorage,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $systemId, string $requestId): array
    {
        $requestId = strtolower(trim($requestId));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)) {
            throw new RuntimeException('Die Backup-Anfrage hat eine ungültige ID.');
        }

        $baseDirectory = $this->projectDir.'/var/domain-manager/backups';
        $this->ensureDirectory($baseDirectory);
        $lockDirectory = $baseDirectory.'/.locks';
        $this->ensureDirectory($lockDirectory);
        $lockPath = $lockDirectory.'/'.$requestId.'.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if (false === $lockHandle) {
            throw new RuntimeException('Das Backup konnte nicht gesperrt werden.');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Das Backup konnte nicht exklusiv gesperrt werden.');
            }

            $backupDirectory = $baseDirectory.'/'.$requestId;
            $manifestPath = $backupDirectory.'/manifest.json';

            if (is_file($manifestPath)) {
                return $this->readManifest($manifestPath);
            }

            if (is_dir($backupDirectory)) {
                $this->removeDirectory($backupDirectory);
            }

            $this->ensureDirectory($backupDirectory);
            @set_time_limit(0);

            $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $databaseFileName = sprintf(
                'domain-manager-%s__%s.sql.gz',
                $requestId,
                $createdAt->format('YmdHis')
            );
            $databaseConfig = $this->backupManager
                ->createCreateConfig()
                ->withFileName($databaseFileName);

            $this->backupManager->create($databaseConfig);
            $databasePath = $backupDirectory.'/database.sql.gz';

            try {
                $input = $this->backupManager->readStream($databaseConfig->getBackup());
                $output = @fopen($databasePath, 'wb');

                if (false === $output) {
                    if (is_resource($input)) {
                        fclose($input);
                    }

                    throw new RuntimeException('Die Datenbanksicherung konnte nicht in das Backup-Set kopiert werden.');
                }

                try {
                    if (false === stream_copy_to_stream($input, $output)) {
                        throw new RuntimeException('Die Datenbanksicherung konnte nicht vollständig kopiert werden.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            } finally {
                // Contao first creates a DB dump in its own backup storage.
                // The maintained copy is database.sql.gz; do not retain a duplicate.
                $this->removeContaoDatabaseCopy($databaseFileName);
            }

            @chmod($databasePath, 0600);
            $databaseChecksum = hash_file('sha256', $databasePath);

            if (false === $databaseChecksum) {
                throw new RuntimeException('Die Prüfsumme der Datenbanksicherung konnte nicht berechnet werden.');
            }

            $projectPath = $backupDirectory.'/project.zip';
            $project = $this->projectArchiveBuilder->build($projectPath);
            $databaseSize = max(0, (int) @filesize($databasePath));
            $completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $manifest = [
                'system_id' => $systemId,
                'api_version' => 1,
                'backup' => [
                    'id' => $requestId,
                    'status' => 'completed',
                    'created_at' => $createdAt->format(\DateTimeInterface::ATOM),
                    'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
                    'contao_version' => $this->packageVersion('contao/core-bundle'),
                    'php_version' => PHP_VERSION,
                    'database' => [
                        'name' => 'database.sql.gz',
                        'size' => $databaseSize,
                        'sha256' => $databaseChecksum,
                    ],
                    'project' => [
                        'name' => 'project.zip',
                        'format' => $project['format'],
                        'size' => $project['size'],
                        'sha256' => $project['sha256'],
                        'file_count' => $project['file_count'],
                        'source_size' => $project['source_size'],
                        'included_roots' => $project['included_roots'],
                        'skipped_symlinks' => $project['skipped_symlinks'],
                    ],
                    'total_size' => $databaseSize + $project['size'],
                ],
            ];

            $this->writeManifest($manifestPath, $manifest);

            return $manifest;
        } catch (Throwable $exception) {
            $backupDirectory = $baseDirectory.'/'.$requestId;

            if (is_dir($backupDirectory) && !is_file($backupDirectory.'/manifest.json')) {
                $this->removeDirectory($backupDirectory);
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Das Backup konnte nicht erstellt werden: '.$exception->getMessage(), 0, $exception);
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Delete a complete backup set on the target installation.
     * Deleting a missing set is idempotent; a foreign installation's set is never deleted.
     */
    public function delete(string $systemId, string $backupId): void
    {
        $backupId = strtolower(trim($backupId));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $backupId)) {
            throw new RuntimeException('Die Backup-ID ist ungültig.');
        }

        $baseDirectory = $this->projectDir.'/var/domain-manager/backups';
        $this->ensureDirectory($baseDirectory.'/.locks');
        $lockHandle = @fopen($baseDirectory.'/.locks/'.$backupId.'.lock', 'c');

        if (false === $lockHandle) {
            throw new RuntimeException('Die Sicherung konnte nicht zum Löschen gesperrt werden.');
        }

        try {
            // Creating or restoring this exact backup may still be in progress.
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Die Sicherung wird gerade verwendet und kann nicht gelöscht werden.');
            }

            $directory = $baseDirectory.'/'.$backupId;

            if (!file_exists($directory) && !is_link($directory)) {
                return;
            }

            if (!is_dir($directory) || is_link($directory)) {
                throw new RuntimeException('Der Sicherungspfad ist kein gültiges Verzeichnis.');
            }

            $manifestPath = $directory.'/manifest.json';

            if (!is_file($manifestPath) || is_link($manifestPath)) {
                throw new RuntimeException('Die Sicherung hat kein gültiges Manifest und wird nicht gelöscht.');
            }

            $manifest = $this->readManifest($manifestPath);

            if (!is_string($manifest['system_id'] ?? null)
                || !hash_equals($systemId, $manifest['system_id'])
                || ($manifest['backup']['id'] ?? null) !== $backupId
            ) {
                throw new RuntimeException('Die Sicherung gehört nicht zur erwarteten Installation.');
            }

            // Older sets may still have the temporary Contao-generated dump.
            $createdAt = $manifest['backup']['created_at'] ?? null;

            if (is_string($createdAt)) {
                try {
                    $stamp = (new \DateTimeImmutable($createdAt))->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis');
                    $this->removeContaoDatabaseCopy(sprintf('domain-manager-%s__%s.sql.gz', $backupId, $stamp));
                } catch (\DateMalformedStringException) {
                    // The dedicated backup directory is still safely removable.
                }
            }

            $this->removeDirectory($directory);

            if (file_exists($directory) || is_link($directory)) {
                throw new RuntimeException('Die Sicherungsdateien konnten nicht vollständig gelöscht werden.');
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(string $systemId): array
    {
        $baseDirectory = $this->projectDir.'/var/domain-manager/backups';

        if (!is_dir($baseDirectory)) {
            return [];
        }

        $entries = @scandir($baseDirectory);

        if (false === $entries) {
            return [];
        }

        $backups = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || '.locks' === $entry) {
                continue;
            }

            if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $entry)) {
                continue;
            }

            $manifestPath = $baseDirectory.'/'.$entry.'/manifest.json';

            if (!is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->readManifest($manifestPath);
            } catch (Throwable) {
                continue;
            }

            if (!isset($manifest['system_id']) || !is_string($manifest['system_id'])) {
                continue;
            }

            if (!hash_equals($systemId, $manifest['system_id'])) {
                continue;
            }

            $backup = $manifest['backup'] ?? null;

            if (is_array($backup)) {
                $backups[] = $backup;
            }
        }

        usort($backups, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        return $backups;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            throw new RuntimeException('Das Backup-Manifest konnte nicht gelesen werden.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Das Backup-Manifest ist ungültig.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Das Backup-Manifest hat ein ungültiges Format.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $path, array $manifest): void
    {
        try {
            $content = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Das Backup-Manifest konnte nicht erzeugt werden.', 0, $exception);
        }

        $temporaryPath = $path.'.tmp';

        if (false === @file_put_contents($temporaryPath, $content, LOCK_EX)) {
            throw new RuntimeException('Das Backup-Manifest konnte nicht geschrieben werden.');
        }

        @chmod($temporaryPath, 0600);

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Das Backup-Manifest konnte nicht abgeschlossen werden.');
        }
    }

    private function removeContaoDatabaseCopy(string $name): void
    {
        if ($this->contaoBackupsStorage->fileExists($name)) {
            $this->contaoBackupsStorage->delete($name);
        }
    }

    private function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? '';
        } catch (Throwable) {
            return '';
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

    private function removeDirectory(string $directory): void
    {
        $entries = @scandir($directory);

        if (false === $entries) {
            return;
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

        @rmdir($directory);
    }
}
