<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\ProjectArchiveBuilder;
use Lebensbaum\ContaoSystemInfoBundle\Backup\ProjectArchiveRestorer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use splitbrain\PHPArchive\Zip;

final class ProjectArchiveRestorerTest extends TestCase
{
    private string $projectDir;
    private string $archivePath;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dm-project-restore-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/files', 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/public/assets', 0700, true));
        self::assertSame(17, file_put_contents($this->projectDir.'/composer.json', '{"name":"before"}'));
        self::assertSame(16, file_put_contents($this->projectDir.'/config/config.yaml', "state: original\n"));
        self::assertSame(8, file_put_contents($this->projectDir.'/files/example.txt', 'original'));
        self::assertSame(6, file_put_contents($this->projectDir.'/public/custom.txt', 'custom'));
        self::assertSame(9, file_put_contents($this->projectDir.'/public/assets/generated.txt', 'generated'));

        $backupDir = $this->projectDir.'/var/domain-manager/backups/test';
        self::assertTrue(mkdir($backupDir, 0700, true));
        $this->archivePath = $backupDir.'/project.zip';
        (new ProjectArchiveBuilder($this->projectDir))->build($this->archivePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testRestoreReplacesManagedProjectStateAndKeepsGeneratedPublicAssets(): void
    {
        self::assertSame(16, file_put_contents($this->projectDir.'/composer.json', '{"name":"after"}'));
        self::assertSame(16, file_put_contents($this->projectDir.'/config/config.yaml', "state: changed!\n"));
        self::assertTrue(mkdir($this->projectDir.'/src', 0700, true));
        self::assertSame(5, file_put_contents($this->projectDir.'/src/stale.php', 'stale'));
        self::assertSame(7, file_put_contents($this->projectDir.'/public/custom.txt', 'changed'));
        self::assertSame(13, file_put_contents($this->projectDir.'/public/assets/generated.txt', 'still-current'));

        $result = (new ProjectArchiveRestorer($this->projectDir))->restore($this->archivePath);

        self::assertSame('{"name":"before"}', file_get_contents($this->projectDir.'/composer.json'));
        self::assertSame("state: original\n", file_get_contents($this->projectDir.'/config/config.yaml'));
        self::assertSame('original', file_get_contents($this->projectDir.'/files/example.txt'));
        self::assertSame('custom', file_get_contents($this->projectDir.'/public/custom.txt'));
        self::assertSame('still-current', file_get_contents($this->projectDir.'/public/assets/generated.txt'));
        self::assertDirectoryDoesNotExist($this->projectDir.'/src');
        self::assertSame(4, $result['file_count']);
        self::assertContains('config', $result['restored_roots']);
        self::assertContains('files', $result['restored_roots']);
        self::assertContains('public', $result['restored_roots']);
    }

    public function testRestorePreservesUnixModesAndRestoresEmptyDirectories(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            self::markTestSkipped('Unix permission modes required');
        }

        self::assertTrue(mkdir($this->projectDir.'/templates/custom', 0750, true));
        self::assertTrue(mkdir($this->projectDir.'/files/empty-private', 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/src', 0755, true));
        self::assertSame(3, file_put_contents($this->projectDir.'/src/run.sh', 'run'));
        self::assertSame(6, file_put_contents($this->projectDir.'/.env.local', 'SECRET'));
        self::assertTrue(chmod($this->projectDir.'/templates/custom', 0750));
        self::assertTrue(chmod($this->projectDir.'/files/empty-private', 0700));
        self::assertTrue(chmod($this->projectDir.'/src/run.sh', 0755));
        self::assertTrue(chmod($this->projectDir.'/config/config.yaml', 0640));
        self::assertTrue(chmod($this->projectDir.'/.env.local', 0600));

        (new ProjectArchiveBuilder($this->projectDir))->build($this->archivePath);
        self::assertTrue(chmod($this->projectDir.'/config/config.yaml', 0644));
        self::assertTrue(chmod($this->projectDir.'/src/run.sh', 0644));
        self::assertTrue(chmod($this->projectDir.'/.env.local', 0644));
        self::assertTrue(chmod($this->projectDir.'/templates/custom', 0755));
        self::assertTrue(chmod($this->projectDir.'/files/empty-private', 0755));

        $result = (new ProjectArchiveRestorer($this->projectDir))->restore($this->archivePath);

        self::assertTrue($result['permissions_restored']);
        self::assertSame('manifest-v1', $result['permissions_format']);
        $this->assertMode('config/config.yaml', 0640);
        $this->assertMode('src/run.sh', 0755);
        $this->assertMode('.env.local', 0600);
        $this->assertMode('templates/custom', 0750);
        $this->assertMode('files/empty-private', 0700);
    }

    public function testRestoreNeverMakesEnvFileWorldReadable(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            self::markTestSkipped('Unix permission modes required');
        }

        file_put_contents($this->projectDir.'/.env.local', 'PRIVATE');
        self::assertTrue(chmod($this->projectDir.'/.env.local', 0644));
        (new ProjectArchiveBuilder($this->projectDir))->build($this->archivePath);

        (new ProjectArchiveRestorer($this->projectDir))->restore($this->archivePath);

        $this->assertMode('.env.local', 0600);
    }

    public function testRestoreRejectsForgedPermissionsBeforeChangingFiles(): void
    {
        $malicious = $this->projectDir.'/forged.zip';
        $zip = new Zip();
        $zip->create($malicious);
        $zip->addData('composer.json', '{"name":"forged"}');
        $zip->addData('.maintend-permissions.json', json_encode([
            'schema' => 1,
            'paths' => [
                'composer.json' => ['type' => 'file', 'mode' => 0644],
                '../outside.txt' => ['type' => 'file', 'mode' => 0777],
            ],
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            (new ProjectArchiveRestorer($this->projectDir))->restore($malicious);
            self::fail('A forged permissions manifest must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('unsicheren Eintrag', $exception->getMessage());
        }

        self::assertSame('{"name":"before"}', file_get_contents($this->projectDir.'/composer.json'));
    }

    public function testLegacyBackupRemainsRestorableWithExplicitFallback(): void
    {
        $legacy = $this->projectDir.'/legacy.zip';
        $zip = new Zip();
        $zip->create($legacy);
        $zip->addData('composer.json', '{"name":"legacy"}');
        $zip->close();

        $result = (new ProjectArchiveRestorer($this->projectDir))->restore($legacy);

        self::assertSame('{"name":"legacy"}', file_get_contents($this->projectDir.'/composer.json'));
        self::assertFalse($result['permissions_restored']);
        self::assertSame('legacy-fallback', $result['permissions_format']);
    }

    public function testRestoreRejectsSymlinkInMergedPublicDestinationBeforeChangingFiles(): void
    {
        $outside = $this->projectDir.'/outside';
        self::assertTrue(mkdir($outside, 0755));
        self::assertNotFalse(file_put_contents($outside.'/index.html', 'keep'));
        self::assertTrue(symlink($outside, $this->projectDir.'/public/customdir'));
        self::assertTrue(mkdir($this->projectDir.'/separate-public', 0755));
        self::assertNotFalse(file_put_contents($this->projectDir.'/separate-public/index.html', 'backup'));

        // Build an archive from a safe public directory first.
        unlink($this->projectDir.'/public/customdir');
        self::assertTrue(mkdir($this->projectDir.'/public/customdir', 0755));
        self::assertNotFalse(file_put_contents($this->projectDir.'/public/customdir/index.html', 'backup'));
        (new ProjectArchiveBuilder($this->projectDir))->build($this->archivePath);
        unlink($this->projectDir.'/public/customdir/index.html');
        rmdir($this->projectDir.'/public/customdir');
        self::assertTrue(symlink($outside, $this->projectDir.'/public/customdir'));

        try {
            (new ProjectArchiveRestorer($this->projectDir))->restore($this->archivePath);
            self::fail('Restore must refuse symlink traversal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Symlink', $exception->getMessage());
        }

        self::assertSame('keep', file_get_contents($outside.'/index.html'));
        self::assertSame('{"name":"before"}', file_get_contents($this->projectDir.'/composer.json'));
    }

    private function assertMode(string $path, int $expected): void
    {
        $absolute = $this->projectDir.'/'.$path;
        clearstatcache(true, $absolute);
        self::assertSame($expected, fileperms($absolute) & 0777, $path);
    }

    public function testRestoreRejectsTraversalEntryEvenWhenArchiveLibrarySanitizesItsName(): void
    {
        $malicious = $this->projectDir.'/malicious.zip';
        $zip = new Zip();
        $zip->create($malicious);
        $zip->addData('../escape.txt', 'nope');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nicht erlaubten Pfad');

        (new ProjectArchiveRestorer($this->projectDir))->restore($malicious);
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
