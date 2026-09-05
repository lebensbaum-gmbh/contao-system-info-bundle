<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\ProjectArchiveBuilder;
use PharData;
use PHPUnit\Framework\TestCase;

final class ProjectArchiveBuilderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dm-project-backup-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir, 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/files', 0700, true));
        self::assertTrue(mkdir($this->projectDir.'/public/assets', 0700, true));
        self::assertSame(2, file_put_contents($this->projectDir.'/composer.json', '{}'));
        self::assertNotFalse(file_put_contents($this->projectDir.'/config/config.yaml', "framework: {}\n"));
        self::assertNotFalse(file_put_contents($this->projectDir.'/files/example.txt', 'content'));
        self::assertNotFalse(file_put_contents($this->projectDir.'/public/custom.txt', 'custom'));
        self::assertNotFalse(file_put_contents($this->projectDir.'/public/assets/generated.txt', 'generated'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testBuildIncludesProjectFilesAndSkipsGeneratedPublicAssets(): void
    {
        $targetDirectory = $this->projectDir.'/var/domain-manager/backups/test';
        self::assertTrue(mkdir($targetDirectory, 0700, true));
        $target = $targetDirectory.'/project.tar.gz';
        $result = (new ProjectArchiveBuilder($this->projectDir))->build($target);

        self::assertFileExists($target);
        self::assertGreaterThan(0, $result['size']);
        self::assertSame(4, $result['file_count']);
        self::assertSame(64, strlen($result['sha256']));

        $archive = new PharData($target);
        self::assertTrue(isset($archive['composer.json']));
        self::assertTrue(isset($archive['config/config.yaml']));
        self::assertTrue(isset($archive['files/example.txt']));
        self::assertTrue(isset($archive['public/custom.txt']));
        self::assertFalse(isset($archive['public/assets/generated.txt']));
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
