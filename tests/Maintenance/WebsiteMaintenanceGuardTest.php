<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\WebsiteMaintenanceGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class WebsiteMaintenanceGuardTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-website-guard-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory.'/var', 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testOwnPageIsReadableDuringTheOperationAndRemovedBeforeReturningItsResult(): void
    {
        $mask = umask(0077);
        try {
            $result = (new WebsiteMaintenanceGuard($this->directory))->run(function (): array {
                $path = $this->directory.'/var/maintenance.html';
                self::assertStringStartsWith(WebsiteMaintenanceGuard::PAGE_MARKER, file_get_contents($path));
                self::assertSame(0644, fileperms($path) & 0777);
                self::assertSame(0700, fileperms($this->directory.'/var') & 0777);

                return ['verified' => true];
            });
        } finally {
            umask($mask);
        }

        self::assertSame(['verified' => true], $result);
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
    }

    public function testControlledFailureRemovesOwnPageAndPreservesTheOriginalException(): void
    {
        $failure = new RuntimeException('Composer failure');
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function () use ($failure): void {
                throw $failure;
            });
            self::fail('Expected operation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
    }

    public function testExistingManualPageIsNeverOverwrittenOrRemoved(): void
    {
        $path = $this->directory.'/var/maintenance.html';
        file_put_contents($path, 'Manual maintenance');
        chmod($path, 0600);
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function (): void {
                self::fail('Operation must not start.');
            });
            self::fail('Existing maintenance page was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('nicht gestartet', $exception->getMessage());
        }
        self::assertSame('Manual maintenance', file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testFailedRealMutationCanKeepTheWebsiteProtectedForRecovery(): void
    {
        $failure = new RuntimeException('Partially installed packages');
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function () use ($failure): void {
                throw $failure;
            }, true);
            self::fail('Expected operation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertStringStartsWith(WebsiteMaintenanceGuard::PAGE_MARKER, file_get_contents($this->directory.'/var/maintenance.html'));
        $this->assertCannotStart();
    }

    public function testExistingDirectoryAndDanglingLinkAreNotReplaced(): void
    {
        $path = $this->directory.'/var/maintenance.html';
        mkdir($path);
        $this->assertCannotStart();
        self::assertDirectoryExists($path);
        rmdir($path);

        if ('Windows' !== PHP_OS_FAMILY && function_exists('symlink')) {
            self::assertTrue(symlink($this->directory.'/missing.html', $path));
            $this->assertCannotStart();
            self::assertTrue(is_link($path));
            self::assertFileDoesNotExist($this->directory.'/missing.html');
        }
    }

    public function testPageChangedDuringTheOperationIsKeptAndPreventsSuccessfulReturn(): void
    {
        $path = $this->directory.'/var/maintenance.html';
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function () use ($path): string {
                file_put_contents($path, 'Manual replacement');
                return 'success';
            });
            self::fail('A changed page was silently removed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('zwischenzeitlich verändert', $exception->getMessage());
        }
        self::assertSame('Manual replacement', file_get_contents($path));
    }

    public function testExistingSymlinkToAFileIsNeverFollowedOrChanged(): void
    {
        if ('Windows' === PHP_OS_FAMILY || !function_exists('symlink')) {
            self::markTestSkipped('POSIX symlink required.');
        }
        $destination = $this->directory.'/manual-page.html';
        file_put_contents($destination, 'Manual maintenance');
        chmod($destination, 0600);
        self::assertTrue(symlink($destination, $this->directory.'/var/maintenance.html'));
        $this->assertCannotStart();
        self::assertTrue(is_link($this->directory.'/var/maintenance.html'));
        self::assertSame('Manual maintenance', file_get_contents($destination));
        self::assertSame(0600, fileperms($destination) & 0777);
    }

    public function testReplacedFileWithTheSameContentsIsNotDeleted(): void
    {
        $path = $this->directory.'/var/maintenance.html';
        $original = null;
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function () use ($path, &$original): void {
                $contents = file_get_contents($path);
                $original = fopen($path, 'rb'); // Keep the old inode allocated.
                unlink($path);
                file_put_contents($path, $contents);
            });
            self::fail('A replacement file was removed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('zwischenzeitlich verändert', $exception->getMessage());
        } finally {
            if (is_resource($original)) fclose($original);
        }
        self::assertFileExists($path);
    }

    public function testCreationFailureNeverRunsTheOperation(): void
    {
        rmdir($this->directory.'/var');
        file_put_contents($this->directory.'/var', 'not a directory');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verzeichnis');
        (new WebsiteMaintenanceGuard($this->directory))->run(static function (): void {
            self::fail('Operation must not start.');
        });
    }

    public function testActualContaoFrontControllersReturn503BeforeBootstrappingWhileVendorIsUnavailable(): void
    {
        if (!function_exists('proc_open') || !ini_get('allow_url_fopen')) {
            self::markTestSkipped('Local PHP HTTP server required.');
        }
        mkdir($this->directory.'/public');
        mkdir($this->directory.'/vendor');
        $bootstrap = <<<'PHP'
<?php
namespace Symfony\Component\HttpFoundation {
    class Request { public static function createFromGlobals(): self { return new self(); } }
}
namespace Contao\ManagerBundle\HttpKernel {
    class ContaoKernel {
        public static function fromRequest($directory, $request): self { return new self(); }
        public function handle($request): object { return new class { public function send(): void { echo 'READY'; } }; }
    }
}
namespace { file_put_contents(__DIR__.'/../booted', 'yes'); return new \stdClass(); }
PHP;
        foreach (['contao-4.13-index.php', 'contao-5.7-index.php'] as $fixture) {
            copy(__DIR__.'/../Fixtures/'.$fixture, $this->directory.'/public/index.php');
            file_put_contents($this->directory.'/vendor/autoload.php', $bootstrap);
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertIsResource($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $server = new Process([PHP_BINARY, '-S', $address, '-t', $this->directory.'/public', $this->directory.'/public/index.php']);
            $server->start();

            try {
                $deadline = microtime(true) + 5;
                $connected = false;
                do {
                    $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
                    if (is_resource($connection)) { fclose($connection); $connected = true; break; }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                self::assertTrue($connected, $server->getErrorOutput());
                self::assertSame([200, 'READY'], $this->request($address, '/', 'GET'));
                unlink($this->directory.'/booted');

                (new WebsiteMaintenanceGuard($this->directory))->run(function () use ($address): void {
                    unlink($this->directory.'/vendor/autoload.php');
                    foreach ([['/', 'GET'], ['/contao', 'GET'], ['/_domainverwaltung/actions/update-status', 'POST'], ['/_domainverwaltung/actions/restore-status', 'POST']] as [$path, $method]) {
                        [$status, $body, $headers] = $this->request($address, $path, $method, true);
                        self::assertSame(503, $status);
                        self::assertStringStartsWith(WebsiteMaintenanceGuard::PAGE_MARKER, $body);
                        self::assertStringContainsString('Cache-Control: no-store', implode("\n", $headers));
                        self::assertFileDoesNotExist($this->directory.'/booted');
                    }
                });

                file_put_contents($this->directory.'/vendor/autoload.php', $bootstrap);
                self::assertSame([200, 'READY'], $this->request($address, '/contao', 'GET'));
                self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
            } finally {
                $server->stop(1);
            }
        }
    }

    public function testAbruptProcessDeathLeavesTheMaintenancePageForManualInvestigation(): void
    {
        if ('Windows' === PHP_OS_FAMILY || !function_exists('proc_open')) {
            self::markTestSkipped('POSIX process termination required.');
        }
        $script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; '
            .'(new \\Lebensbaum\\ContaoSystemInfoBundle\\Maintenance\\WebsiteMaintenanceGuard('
            .var_export($this->directory, true).'))->run(static function (): void { sleep(30); });';
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->start();
        $path = $this->directory.'/var/maintenance.html';
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($path) && microtime(true) < $deadline) { clearstatcache(true, $path); usleep(20000); }
            self::assertFileExists($path, $process->getErrorOutput());
        } finally {
            $process->stop(0, 9);
        }
        self::assertFileExists($path);
        $this->assertCannotStart();
    }

    private function assertCannotStart(): void
    {
        try {
            (new WebsiteMaintenanceGuard($this->directory))->run(static function (): void {
                self::fail('Operation must not start.');
            });
            self::fail('Existing maintenance entry was replaced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('nicht gestartet', $exception->getMessage());
        }
    }

    /** @return array{0:int,1:string,2?:list<string>} */
    private function request(string $address, string $path, string $method, bool $includeHeaders = false): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 3]]);
        $stream = fopen('http://'.$address.$path, 'rb', false, $context);
        self::assertIsResource($stream);
        $headers = stream_get_meta_data($stream)['wrapper_data'];
        $body = stream_get_contents($stream);
        fclose($stream);
        preg_match('/\s(\d{3})\s/', $headers[0], $matches);
        return $includeHeaders ? [(int) $matches[1], $body, $headers] : [(int) $matches[1], $body];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) continue;
            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) $this->removeDirectory($path);
            else unlink($path);
        }
        rmdir($directory);
    }
}
