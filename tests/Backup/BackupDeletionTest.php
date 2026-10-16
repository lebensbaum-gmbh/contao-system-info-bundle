<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\BackupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class BackupDeletionTest extends TestCase
{
    private string $projectDir;
    private const SYSTEM_ID = '11111111111111111111111111111111';
    private const BACKUP_ID = '22222222222222222222222222222222';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/maintend-delete-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/var/domain-manager/backups', 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testDeleteRemovesDatabaseProjectAndManifest(): void
    {
        $directory = $this->createBackup(self::SYSTEM_ID);
        $this->service()->delete(self::SYSTEM_ID, self::BACKUP_ID);

        self::assertDirectoryDoesNotExist($directory);
        // Retrying a confirmed deletion is safe when the central DB still has its entry.
        $this->service()->delete(self::SYSTEM_ID, self::BACKUP_ID);
    }

    public function testDeleteRejectsAForeignInstallation(): void
    {
        $directory = $this->createBackup(self::SYSTEM_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gehört nicht');
        try {
            $this->service()->delete('33333333333333333333333333333333', self::BACKUP_ID);
        } finally {
            self::assertFileExists($directory.'/project.zip');
        }
    }

    public function testDeleteRejectsLockedBackup(): void
    {
        $directory = $this->createBackup(self::SYSTEM_ID);
        $lockDirectory = $this->projectDir.'/var/domain-manager/backups/.locks';
        self::assertTrue(mkdir($lockDirectory, 0700, true));
        $handle = fopen($lockDirectory.'/'.self::BACKUP_ID.'.lock', 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_SH | LOCK_NB));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('gerade verwendet');
            $this->service()->delete(self::SYSTEM_ID, self::BACKUP_ID);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            self::assertFileExists($directory.'/project.zip');
        }
    }

    public function testDeleteRejectsUnsafeId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->delete(self::SYSTEM_ID, '../backups');
    }

    private function createBackup(string $systemId): string
    {
        $path = $this->projectDir.'/var/domain-manager/backups/'.self::BACKUP_ID;
        self::assertTrue(mkdir($path, 0700, true));
        self::assertNotFalse(file_put_contents($path.'/database.sql.gz', 'db-data'));
        self::assertNotFalse(file_put_contents($path.'/project.zip', 'project-data'));
        self::assertNotFalse(file_put_contents($path.'/manifest.json', json_encode([
            'system_id' => $systemId,
            'backup' => ['id' => self::BACKUP_ID, 'status' => 'completed'],
        ], JSON_THROW_ON_ERROR)));

        return $path;
    }

    private function service(): BackupService
    {
        $service = (new ReflectionClass(BackupService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BackupService::class, 'projectDir'))->setValue($service, $this->projectDir);

        return $service;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
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
