<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backup;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Backup\Config\RestoreConfig;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use JsonException;
use RuntimeException;
use Throwable;

final class RestoreService
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly ProjectArchiveRestorer $projectArchiveRestorer,
        private readonly BackupManager $backupManager,
        private readonly VirtualFilesystemInterface $contaoBackupsStorage,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function restore(string $systemId, string $requestId, string $backupId): array
    {
        $requestId = $this->normalizeId($requestId, 'Restore-Anfrage');
        $backupId = $this->normalizeId($backupId, 'Backup');
        $restoreDirectory = $this->projectDir.'/var/domain-manager/restores';
        $this->ensureDirectory($restoreDirectory);
        $lockDirectory = $restoreDirectory.'/.locks';
        $this->ensureDirectory($lockDirectory);
        $lockPath = $lockDirectory.'/'.$requestId.'.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if (false === $lockHandle) {
            throw new RuntimeException('Die Wiederherstellung konnte nicht gesperrt werden.');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Die Wiederherstellung konnte nicht exklusiv gesperrt werden.');
            }

            $resultPath = $restoreDirectory.'/'.$requestId.'.json';

            if (is_file($resultPath)) {
                return $this->readJsonFile($resultPath, 'Das Restore-Ergebnis ist ungültig.');
            }

            $manifest = $this->readAndVerifyManifest($systemId, $backupId);
            $backup = $manifest['backup'];
            $backupDirectory = $this->projectDir.'/var/domain-manager/backups/'.$backupId;
            $databasePath = $backupDirectory.'/database.sql.gz';
            $projectPath = $backupDirectory.'/project.zip';
            $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // A restore is destructive. Always create a fresh rescue point first.
            $safetyBackupId = bin2hex(random_bytes(16));
            $safetyManifest = $this->backupService->create($systemId, $safetyBackupId);
            $safetyBackup = $safetyManifest['backup'] ?? null;

            if (!is_array($safetyBackup) || 'completed' !== ($safetyBackup['status'] ?? null)) {
                throw new RuntimeException('Die Sicherheitskopie vor der Wiederherstellung wurde nicht vollständig erstellt.');
            }

            @set_time_limit(0);
            $projectResult = $this->projectArchiveRestorer->restore($projectPath);
            $this->restoreDatabase($databasePath, $requestId);
            $completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $result = [
                'system_id' => $systemId,
                'api_version' => 1,
                'restore' => [
                    'id' => $requestId,
                    'status' => 'completed',
                    'backup_id' => $backupId,
                    'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
                    'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
                    'restored_contao_version' => (string) ($backup['contao_version'] ?? ''),
                    'restored_php_version' => (string) ($backup['php_version'] ?? ''),
                    'project_file_count' => (int) ($projectResult['file_count'] ?? 0),
                    'project_source_size' => (int) ($projectResult['source_size'] ?? 0),
                    'restored_roots' => $projectResult['restored_roots'] ?? [],
                    'safety_backup' => $safetyBackup,
                ],
            ];

            $this->writeJsonFile($resultPath, $result);

            return $result;
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return array{system_id:string,api_version:mixed,backup:array<string,mixed>} */
    private function readAndVerifyManifest(string $systemId, string $backupId): array
    {
        $backupDirectory = $this->projectDir.'/var/domain-manager/backups/'.$backupId;
        $manifestPath = $backupDirectory.'/manifest.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException('Das ausgewählte Backup wurde auf der Zielinstallation nicht gefunden.');
        }

        $manifest = $this->readJsonFile($manifestPath, 'Das Backup-Manifest ist ungültig.');

        if (!isset($manifest['system_id']) || !is_string($manifest['system_id']) || !hash_equals($systemId, $manifest['system_id'])) {
            throw new RuntimeException('Das Backup gehört nicht zu dieser System-Info-Installation.');
        }

        $backup = $manifest['backup'] ?? null;

        if (!is_array($backup) || ($backup['id'] ?? null) !== $backupId || 'completed' !== ($backup['status'] ?? null)) {
            throw new RuntimeException('Das Backup-Manifest meldet kein vollständig abgeschlossenes Backup.');
        }

        $database = $backup['database'] ?? null;
        $project = $backup['project'] ?? null;

        if (!is_array($database) || !is_array($project)) {
            throw new RuntimeException('Das Backup-Manifest enthält keine vollständigen Datei-Informationen.');
        }

        if ('database.sql.gz' !== ($database['name'] ?? null)) {
            throw new RuntimeException('Das Backup-Manifest enthält einen unerwarteten Datenbank-Dateinamen.');
        }

        if ('project.zip' !== ($project['name'] ?? null) || 'zip' !== ($project['format'] ?? null)) {
            throw new RuntimeException('Das Backup-Manifest enthält kein unterstütztes Projektarchiv.');
        }

        $this->verifyFile(
            $backupDirectory.'/database.sql.gz',
            $database['sha256'] ?? null,
            $database['size'] ?? null,
            'Datenbanksicherung'
        );
        $this->verifyFile(
            $backupDirectory.'/project.zip',
            $project['sha256'] ?? null,
            $project['size'] ?? null,
            'Projektarchiv'
        );

        /** @var array{system_id:string,api_version:mixed,backup:array<string,mixed>} $manifest */
        return $manifest;
    }

    private function verifyFile(string $path, mixed $expectedHash, mixed $expectedSize, string $label): void
    {
        if (!is_file($path)) {
            throw new RuntimeException($label.' wurde nicht gefunden.');
        }

        $expectedHash = is_string($expectedHash) ? strtolower(trim($expectedHash)) : '';

        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)) {
            throw new RuntimeException($label.' hat im Manifest keine gültige SHA-256-Prüfsumme.');
        }

        $actualHash = hash_file('sha256', $path);

        if (false === $actualHash || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException($label.' stimmt nicht mit der gespeicherten SHA-256-Prüfsumme überein.');
        }

        $actualSize = @filesize($path);
        $expectedSize = is_int($expectedSize) ? $expectedSize : (is_string($expectedSize) && ctype_digit($expectedSize) ? (int) $expectedSize : -1);

        if (false === $actualSize || $expectedSize < 0 || (int) $actualSize !== $expectedSize) {
            throw new RuntimeException($label.' stimmt nicht mit der im Manifest gespeicherten Größe überein.');
        }
    }

    private function restoreDatabase(string $databasePath, string $requestId): void
    {
        $filename = sprintf(
            'domain-manager-restore-%s__%s.sql.gz',
            $requestId,
            gmdate(Backup::DATETIME_FORMAT)
        );
        $stream = @fopen($databasePath, 'rb');

        if (false === $stream) {
            throw new RuntimeException('Die Datenbanksicherung konnte für die Wiederherstellung nicht geöffnet werden.');
        }

        try {
            $this->contaoBackupsStorage->writeStream($filename, $stream);
        } finally {
            fclose($stream);
        }

        try {
            $config = new RestoreConfig(new Backup($filename));
            $this->backupManager->restore($config);
        } catch (Throwable $exception) {
            throw new RuntimeException('Die Datenbank konnte nicht wiederhergestellt werden: '.$exception->getMessage(), 0, $exception);
        } finally {
            try {
                if ($this->contaoBackupsStorage->fileExists($filename)) {
                    $this->contaoBackupsStorage->delete($filename);
                }
            } catch (Throwable) {
            }
        }
    }

    private function normalizeId(string $value, string $label): string
    {
        $value = strtolower(trim($value));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $value)) {
            throw new RuntimeException($label.' hat eine ungültige ID.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path, string $errorMessage): array
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            throw new RuntimeException($errorMessage);
        }

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

    /** @param array<string, mixed> $data */
    private function writeJsonFile(string $path, array $data): void
    {
        try {
            $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Das Restore-Ergebnis konnte nicht serialisiert werden.', 0, $exception);
        }

        $temporary = $path.'.tmp';

        if (false === @file_put_contents($temporary, $content, LOCK_EX)) {
            throw new RuntimeException('Das Restore-Ergebnis konnte nicht gespeichert werden.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Das Restore-Ergebnis konnte nicht abgeschlossen werden.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Restore-Verzeichnis konnte nicht angelegt werden.');
        }

        @chmod($directory, 0700);
    }
}
