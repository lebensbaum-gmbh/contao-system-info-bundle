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

    public function testRejectsFontInContaoPublishedFilesSymlink(): void
    {
        $this->publishFilesFolder('mate');
        self::assertTrue(mkdir($this->project.'/files/mate/fonts', 0755));
        $font = $this->project.'/files/mate/fonts/open-sans.woff2';
        file_put_contents($font, 'font');
        chmod($font, 0600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('files/mate/fonts/open-sans.woff2');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testRejectsUntraversableParentOfPublishedImage(): void
    {
        $this->publishFilesFolder('hausmeister');
        self::assertTrue(mkdir($this->project.'/files/hausmeister/00-basics', 0755));
        $image = $this->project.'/files/hausmeister/00-basics/vi-bg-grey-01.webp';
        file_put_contents($image, 'image');
        chmod($image, 0644);
        chmod($this->project.'/files', 0700);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('files');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testIgnoresPrivateUnpublishedFilesTree(): void
    {
        $this->publishFilesFolder('hausmeister');
        self::assertTrue(mkdir($this->project.'/files/private', 0700));
        file_put_contents($this->project.'/files/private/secret.webp', 'secret');
        chmod($this->project.'/files/private/secret.webp', 0600);

        self::assertTrue(mkdir($this->project.'/files/hausmeister/00-basics', 0755));
        $image = $this->project.'/files/hausmeister/00-basics/vi-bg-grey-01.webp';
        file_put_contents($image, 'image');
        chmod($image, 0644);

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
        self::assertSame(0600, fileperms($this->project.'/files/private/secret.webp') & 0777);
    }

    public function testDoesNotFollowPublishedLinkOutsideProject(): void
    {
        $publicDir = $this->project.'/public/files';
        self::assertTrue(mkdir($publicDir, 0755));
        self::assertTrue(symlink('/tmp', $publicDir.'/outside'));

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    public function testSkipsDreamweaverNotesWithoutChangingPrivatePermissions(): void
    {
        $this->publishFilesFolder('hausmeister');
        $cssDir = $this->project.'/files/hausmeister/01-css';
        self::assertTrue(mkdir($cssDir, 0755));
        file_put_contents($cssDir.'/theme.css', 'body { color: black; }');
        chmod($cssDir.'/theme.css', 0644);

        $notes = $cssDir.'/_notes';
        self::assertTrue(mkdir($notes, 0700));
        file_put_contents($notes.'/dwsync.xml', '<sync />');
        chmod($notes, 0700);
        chmod($notes.'/dwsync.xml', 0600);

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();

        clearstatcache(true, $notes);
        self::assertSame(0700, fileperms($notes) & 0777);
        self::assertSame(0600, fileperms($notes.'/dwsync.xml') & 0777);
    }

    public function testStillRejectsUnreadablePublicCssNextToDreamweaverNotes(): void
    {
        $this->publishFilesFolder('hausmeister');
        $cssDir = $this->project.'/files/hausmeister/01-css';
        self::assertTrue(mkdir($cssDir, 0755));
        self::assertTrue(mkdir($cssDir.'/_notes', 0700));
        file_put_contents($cssDir.'/_notes/dwsync.xml', 'sync');
        chmod($cssDir.'/_notes', 0700);

        file_put_contents($cssDir.'/theme.css', 'body { color: black; }');
        chmod($cssDir.'/theme.css', 0600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('files/hausmeister/01-css/theme.css');

        (new PublicAssetPermissionGuard($this->project))->assertWebReadable();
    }

    private function publishFilesFolder(string $folder): void
    {
        $path = $this->project.'/files/'.$folder;
        self::assertTrue(mkdir($path, 0755, true));
        self::assertTrue(chmod($this->project.'/files', 0755));
        self::assertTrue(chmod($path, 0755));
        $publicDir = $this->project.'/public/files';
        if (!is_dir($publicDir)) {
            self::assertTrue(mkdir($publicDir, 0755));
        }
        self::assertTrue(symlink($path, $publicDir.'/'.$folder));
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
