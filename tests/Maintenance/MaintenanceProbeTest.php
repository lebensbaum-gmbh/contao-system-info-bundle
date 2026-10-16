<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Command\MaintenanceWorkerCommand;
use Lebensbaum\ContaoSystemInfoBundle\Command\MaintenanceWorkerProbeCommand;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\PublicAssetPermissionGuard;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceProbeTest extends TestCase
{
    private string $directory;
    private MaintenanceJobStore $store;
    private const SYSTEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ONE = '11111111111111111111111111111111';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-probe-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->store = new MaintenanceJobStore($this->directory);
        file_put_contents($this->directory.'/composer.json', '{"require":{}}');
        file_put_contents($this->directory.'/composer.lock', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        $this->clearDirectory($this->directory);
    }

    public function testProbeCommandRejectsInvalidHoldWithoutCreatingJobs(): void
    {
        foreach (['-1', '121', '1.5', 'abc', ''] as $hold) {
            $tester = $this->probeCommand();
            self::assertSame(Command::INVALID, $tester->execute(['--hold' => $hold]));
            self::assertStringContainsString('zwischen 0 und 120', $tester->getDisplay());
        }
        self::assertSame([], glob($this->directory.'/var/domain-manager/maintenance-jobs/*.json') ?: []);
    }

    public function testProbeCommandNeverClearsAnExistingRealJob(): void
    {
        $this->store->create(self::ONE, self::SYSTEM, 'restore', []);
        $tester = $this->probeCommand();
        self::assertSame(Command::FAILURE, $tester->execute(['--hold' => '60']));
        self::assertStringContainsString('läuft bereits', $tester->getDisplay());
        self::assertSame('pending', $this->store->read(self::ONE)['status']);
        self::assertCount(1, glob($this->directory.'/var/domain-manager/maintenance-jobs/*.json'));
    }

    public function testNormalProbeCompletesAndReleasesMaintenance(): void
    {
        $this->store->create(self::ONE, self::SYSTEM, 'probe', []);
        self::assertSame(Command::SUCCESS, $this->runWorker());
        self::assertSame('success', $this->store->read(self::ONE)['status']);
        self::assertSame('idle', $this->store->runWhenIdle(static fn (): string => 'idle'));
        $this->assertComposerUntouched();
    }

    public function testProbeOptionsReachDetachedWorkerAndFailureReleasesMaintenance(): void
    {
        if ('Windows' === PHP_OS_FAMILY || !function_exists('proc_open') || !is_executable('/bin/sh')) {
            self::markTestSkipped('POSIX background process required.');
        }
        self::assertTrue(mkdir($this->directory.'/vendor/bin', 0700, true));
        $console = <<<'PHP'
<?php
require __AUTOLOAD__;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Command\MaintenanceWorkerCommand;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\PublicAssetPermissionGuard;
$unused = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
$command = new MaintenanceWorkerCommand(
    new MaintenanceJobStore(__PROJECT__),
    $unused(UpdateInstallationService::class), $unused(UpdateInstallationFinalizer::class),
    $unused(UpdateProgressStore::class), $unused(RestoreService::class),
    $unused(PublicAssetPermissionGuard::class), new Psr\Log\NullLogger()
);
exit($command->run(
    new Symfony\Component\Console\Input\ArrayInput(['request-id' => $argv[2]]),
    new Symfony\Component\Console\Output\NullOutput()
));
PHP;
        $console = str_replace(
            ['__AUTOLOAD__', '__PROJECT__'],
            [var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true), var_export($this->directory, true)],
            $console
        );
        self::assertNotFalse(file_put_contents($this->directory.'/vendor/bin/contao-console', $console));

        $tester = $this->probeCommand();
        self::assertSame(Command::FAILURE, $tester->execute(['--hold' => '1', '--fail' => true]));
        self::assertStringContainsString('Absichtlicher Abbruch', $tester->getDisplay());
        $files = glob($this->directory.'/var/domain-manager/maintenance-jobs/*.json');
        self::assertCount(1, $files);
        $job = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['hold_seconds' => 1, 'fail' => true], $job['payload']);
        self::assertSame('error', $job['status']);
        $this->store->assertIdle();
        $this->store->create(self::ONE, self::SYSTEM, 'update', []);
        $this->assertComposerUntouched();
    }

    public function testHeldProbeCanFailDeliberatelyAndReleasesMaintenance(): void
    {
        $this->store->create(self::ONE, self::SYSTEM, 'probe', ['hold_seconds' => 1, 'fail' => true]);
        $started = microtime(true);
        self::assertSame(Command::FAILURE, $this->runWorker());
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $job = $this->store->read(self::ONE);
        self::assertSame('error', $job['status']);
        self::assertSame('Absichtlicher Abbruch des CLI-Selbsttests.', $job['message']);
        self::assertSame('idle', $this->store->runWhenIdle(static fn (): string => 'idle'));
        $this->assertComposerUntouched();
    }

    public function testInvalidProbePayloadFailsWithoutWaitingOrBlockingFutureActions(): void
    {
        foreach ([['hold_seconds' => -1], ['hold_seconds' => 121], ['hold_seconds' => '60'], ['fail' => 'yes']] as $index => $payload) {
            $id = str_pad((string) ($index + 2), 32, '0', STR_PAD_LEFT);
            $this->store->create($id, self::SYSTEM, 'probe', $payload);
            self::assertSame(Command::FAILURE, $this->runWorker($id));
            self::assertSame('error', $this->store->read($id)['status']);
            self::assertSame('Ungültige Optionen für den CLI-Selbsttest.', $this->store->read($id)['message']);
            $this->store->assertIdle();
        }
    }

    private function probeCommand(): CommandTester
    {
        return new CommandTester(new MaintenanceWorkerProbeCommand(
            $this->store,
            new MaintenanceJobLauncher(new PhpCliResolver($this->directory, PHP_BINARY), $this->directory)
        ));
    }

    private function runWorker(string $id = self::ONE): int
    {
        // Probe jobs must never call the update, restore or asset-mutation services.
        $unused = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $worker = new MaintenanceWorkerCommand(
            $this->store,
            $unused(UpdateInstallationService::class),
            $unused(UpdateInstallationFinalizer::class),
            $unused(UpdateProgressStore::class),
            $unused(RestoreService::class),
            $unused(PublicAssetPermissionGuard::class),
            new NullLogger()
        );
        $mask = umask();
        try {
            return (new CommandTester($worker))->execute(['request-id' => $id]);
        } finally {
            umask($mask);
        }
    }

    private function assertComposerUntouched(): void
    {
        self::assertSame('{"require":{}}', file_get_contents($this->directory.'/composer.json'));
        self::assertSame('{"packages":[]}', file_get_contents($this->directory.'/composer.lock'));
    }

    private function clearDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) continue;
            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) $this->clearDirectory($path);
            else unlink($path);
        }
        rmdir($directory);
    }
}
