<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolutionException;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PhpCliResolverTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testUsesExplicitConfiguredCliWhenVersionMatches(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $binary = $this->fakePhp($directory.'/custom-php', '8.4');

        $resolver = new PhpCliResolver($directory, $binary);

        self::assertSame([$binary, '8.4'], $resolver->resolve('8.4'));
    }

    public function testReadsPhpCliFromContaoManagerConfiguration(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $binary = $this->fakePhp($directory.'/manager-php', '8.3');

        mkdir($directory.'/contao-manager', 0777, true);
        file_put_contents(
            $directory.'/contao-manager/manager.json',
            json_encode(['php_cli' => $binary], JSON_THROW_ON_ERROR)
        );

        $resolver = new PhpCliResolver($directory);

        self::assertSame([$binary, '8.3'], $resolver->resolve('8.3'));
    }

    public function testFindsVersionedPhpBinaryFromPath(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $binary = $this->fakePhp($directory.'/php999', '99.9');
        $previousPath = getenv('PATH');

        try {
            putenv('PATH='.$directory);
            $resolver = new PhpCliResolver($directory);

            self::assertSame([$binary, '99.9'], $resolver->resolve('99.9'));
        } finally {
            false === $previousPath ? putenv('PATH') : putenv('PATH='.$previousPath);
        }
    }

    public function testReportsDetectedMismatchingPhpVersion(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $binary = $this->fakePhp($directory.'/wrong-php', '8.3');
        $resolver = new PhpCliResolver($directory, $binary);

        try {
            $resolver->resolve('99.9');
            self::fail('Expected PHP CLI resolution to fail.');
        } catch (PhpCliResolutionException $exception) {
            self::assertStringContainsString('Web-PHP-Version 99.9', $exception->getMessage());
            self::assertStringContainsString($binary.' (PHP 8.3)', $exception->getMessage());
            self::assertStringContainsString('CONTAO_SYSTEM_INFO_PHP_CLI', $exception->getMessage());
        }
    }

    public function testIncludesCommonSharedHostingLayouts(): void
    {
        $resolver = new PhpCliResolver('/tmp');
        $method = new ReflectionMethod($resolver, 'candidatePaths');
        $method->setAccessible(true);

        /** @var list<string> $candidates */
        $candidates = $method->invoke($resolver, '8.4');

        self::assertContains('/usr/bin/php84', $candidates);
        self::assertContains('/usr/local/bin/php8.4', $candidates);
        self::assertContains('/opt/plesk/php/8.4/bin/php', $candidates);
        self::assertContains('/opt/cpanel/ea-php84/root/usr/bin/php', $candidates);
        self::assertContains('/opt/alt/php84/usr/bin/php', $candidates);
        self::assertContains('/opt/RZphp84/bin/php-cli', $candidates);
        self::assertContains('/opt/RZphp83/bin/php-cli', $method->invoke($resolver, '8.3'));
    }

    public function testRejectsMatchingCgiAndContinuesWithCliFromPath(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $cgi = $this->fakePhp($directory.'/php-cgi', '99.9', 'cgi-fcgi');
        $cli = $this->fakePhp($directory.'/php999', '99.9');
        $previousPath = getenv('PATH');

        try {
            putenv('PATH='.$directory);
            self::assertSame([$cli, '99.9'], (new PhpCliResolver($directory, $cgi))->resolve('99.9'));
        } finally {
            false === $previousPath ? putenv('PATH') : putenv('PATH='.$previousPath);
        }
    }

    public function testDoesNotExposeUsageOutputInResolutionError(): void
    {
        $this->requireUnix();
        $directory = $this->temporaryDirectory();
        $binary = $this->fakePhp($directory.'/php-cgi', str_repeat('Usage: php83 [-q] [-h] ', 1000));

        try {
            (new PhpCliResolver($directory, $binary))->resolve('99.9');
            self::fail('Expected PHP CLI resolution to fail.');
        } catch (PhpCliResolutionException $exception) {
            self::assertStringContainsString($binary.' (keine gültige CLI-Antwort)', $exception->getMessage());
            self::assertStringNotContainsString('Usage:', $exception->getMessage());
            self::assertLessThan(4000, strlen($exception->getMessage()));
        }
    }

    public function testAcceptsTheActualCliRuntime(): void
    {
        if ('cli' !== PHP_SAPI) {
            self::markTestSkipped('This test requires the CLI SAPI.');
        }

        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        self::assertSame([PHP_BINARY, $version], (new PhpCliResolver($this->temporaryDirectory(), PHP_BINARY))->resolve($version));
    }

    private function requireUnix(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Executable fixture tests require a Unix-like environment.');
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/contao-system-info-'.bin2hex(random_bytes(6));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail('Could not create temporary test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function fakePhp(string $path, string $version, string $sapi = 'cli'): string
    {
        file_put_contents($path, "#!/bin/sh\nprintf '%s' '".str_replace("'", "'\\''", $version.'|'.$sapi)."'\n");
        chmod($path, 0755);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
