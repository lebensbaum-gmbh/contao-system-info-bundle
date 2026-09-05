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
