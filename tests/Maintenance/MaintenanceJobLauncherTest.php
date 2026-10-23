<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MaintenanceJobLauncherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-launcher-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory.'/vendor/bin', 0700, true));
        self::assertTrue(mkdir($this->directory.'/var/domain-manager/maintenance-jobs', 0700, true));
    }

    protected function tearDown(): void
    {
        $this->clearDirectory($this->directory);
    }

    public function testDetachedCliProcessSurvivesLauncherReturning(): void
    {
        $this->assertDetachedWorker(false);
    }

    public function testDetachedWorkerRunsWithoutNohupOrSetsidAndSurvivesHangup(): void
    {
        $this->assertDetachedWorker(true);
    }

    private function assertDetachedWorker(bool $restrictedPath): void
    {
        if ('Windows' === PHP_OS_FAMILY || !function_exists('proc_open') || !is_executable('/bin/sh')) {
            self::markTestSkipped('POSIX background process required');
        }

        $console = $this->directory.'/vendor/bin/contao-console';
        $marker = $this->directory.'/worker-started';
        $ready = $this->directory.'/worker-ready';
        self::assertNotFalse(file_put_contents(
            $console,
            "<?php\nfile_put_contents(".var_export($ready, true).", (string) getmypid());\n"
            ."usleep(1500000);\nfile_put_contents(".var_export($marker, true).", 'started');\n"
        ));

        $launcher = new MaintenanceJobLauncher(
            new PhpCliResolver($this->directory, PHP_BINARY),
            $this->directory
        );
        $requestId = '99999999999999999999999999999999';
        $originalPath = getenv('PATH');
        if ($restrictedPath) {
            // Leave only the launcher's existing sleep dependency available.
            // PHP and sh use absolute paths; nohup and setsid cannot resolve.
            $bin = $this->directory.'/restricted-bin';
            self::assertTrue(mkdir($bin, 0700));
            self::assertTrue(symlink('/bin/sleep', $bin.'/sleep'));
            putenv('PATH='.$bin);
        }

        try {
            $started = microtime(true);
            $launcher->launch($requestId);

            // The request must return before the worker wakes up.
            self::assertLessThan(2.5, microtime(true) - $started);
        } finally {
            if (false === $originalPath) {
                putenv('PATH');
            } else {
                putenv('PATH='.$originalPath);
            }
        }

        $deadline = microtime(true) + 9;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($ready);
        if ($restrictedPath) {
            $pid = trim((string) file_get_contents($ready));
            self::assertMatchesRegularExpression('/\\A[1-9][0-9]*\\z/', $pid);
            // The shell has exec'd PHP. Verify that the PHP worker itself
            // inherited SIGHUP protection, not just an intermediate shell.
            $hangup = new Process(['/bin/sh', '-c', 'kill -HUP '.$pid]);
            $hangup->mustRun();
        }

        $deadline = microtime(true) + 9;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(100000);
        }

        self::assertFileExists($marker);
        self::assertSame('started', file_get_contents($marker));

        // Logs/status stay private, but generated public assets must be
        // web-readable. A worker inheriting umask 077 would create 0600.
        clearstatcache(true, $marker);
        self::assertSame(0644, fileperms($marker) & 0777);

        $log = $this->directory.'/var/domain-manager/maintenance-jobs/'.$requestId.'.log';
        clearstatcache(true, $log);
        self::assertFileExists($log);
        self::assertSame(0600, fileperms($log) & 0777);
    }

    public function testRejectsUnsafeRequestIdBeforeLaunching(): void
    {
        $launcher = new MaintenanceJobLauncher(
            new PhpCliResolver($this->directory, PHP_BINARY),
            $this->directory
        );

        $this->expectException(\RuntimeException::class);
        $launcher->launch('../some-command');
    }

    private function clearDirectory(string $directory): void
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
                $this->clearDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
