<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\PublicAssetPermissionGuard;
use PHPUnit\Framework\TestCase;

final class PublicAssetPermissionGuardTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            self::markTestSkipped('POSIX file permissions required');
        }

        $this->project = sys_get_temp_dir().'/maintend-public-permissions-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->project.'/public/bundles/contaocore', 0755, true));
        self::assertTrue(mkdir($this->project.'/vendor/contao/core-bundle/contao/themes/flexible', 0755, true));
        self::assertTrue(mkdir($this->project.'/var/domain-manager/backups', 0700, true));
        file_put_contents($this->project.'/public/bundles/contaocore/backend.js', 'test');
        file_put_contents($this->project.'/vendor/contao/core-bundle/contao/themes/flexible/backend.css', 'test');
        file_put_contents($this->project.'/var/domain-manager/backups/secret.sql.gz', 'private');

        $this->setModes(
            [
                'public/bundles/contaocore/backend.js' => 0644,
                'vendor/contao/core-bundle/contao/themes/flexible/backend.css' => 0644,
                'var/domain-manager/backups/secret.sql.gz' => 0600,
            ]
        );

        self::assertTrue(mkdir($this->project.'/system/themes', 0755, true));
        self::assertTrue(symlink(
            $this->project.'/vendor/contao/core-bundle/contao/themes/flexible',
            $this->project.'/system/themes/flexible'
        ));
    }

    public function testAllowsPublicFilesAndKeepsPrivateBackupsPrivate(): void
    {
        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();

        self::assertSame(0600, fileperms($this->project.'/var/domain-manager/backups/secret.sql.gz') & 0777);
    }

    public function testRejectsOwnerOnlyPublicFile(): void
    {
        chmod($this->project.'/public/bundles/contaocore/backend.js', 0600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('public/bundles/contaocore/backend.js');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testRejectsOwnerOnlyVendorDirectoryBehindThemeSymlink(): void
    {
        chmod($this->project.'/vendor/contao/core-bundle', 0700);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vendor/contao/core-bundle');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testRejectsOwnerOnlyThemeStylesheet(): void
    {
        chmod($this->project.'/vendor/contao/core-bundle/contao/themes/flexible/backend.css', 0600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backend.css');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testDoesNotChangeAlreadyRestrictiveModes(): void
    {
        chmod($this->project.'/public/bundles/contaocore/backend.js', 0600);

        try {
            (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
            self::fail('Expected a permission failure');
        } catch (\RuntimeException) {
            clearstatcache(true, $this->project.'/public/bundles/contaocore/backend.js');
            self::assertSame(0600, fileperms($this->project.'/public/bundles/contaocore/backend.js') & 0777);
        }
    }

    /** @param array<string, int> $paths */
    private function setModes(array $paths): void
    {
        foreach ($paths as $path => $mode) {
            self::assertTrue(chmod($this->project.'/'.$path, $mode));
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->project)) {
            return;
        }

        $this->remove($this->project);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->remove($path.'/'.$entry);
            }
        }

        @chmod($path, 0755);
        @rmdir($path);
    }
}
