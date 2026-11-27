<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Command\MaintenanceWorkerCommand;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\PublicAssetPermissionGuard;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\WebsiteMaintenanceGuard;
use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePolicy;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RejectedUpdateMaintenanceTest extends TestCase
{
    private const ID = '11111111111111111111111111111111';
    private const SYSTEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $directory;
    private array $payload;
    private MaintenanceJobStore $jobs;
    private UpdateProgressStore $progress;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-rejected-update-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        file_put_contents($this->directory.'/composer.json', json_encode(['require' => [
            'contao/core-bundle' => '5.7.*', 'codefog/contao-haste' => '^5.4',
        ]]));
        file_put_contents($this->directory.'/composer.lock', json_encode(['packages' => [
            ['name' => 'contao/core-bundle', 'version' => '5.7.17'],
            ['name' => 'codefog/contao-haste', 'version' => '5.4.1'],
        ]]));
        $this->payload = [
            'request_id' => self::ID, 'current_contao_version' => '5.7.17', 'target_contao_version' => '5.7.17',
            'composer_json_sha256' => hash_file('sha256', $this->directory.'/composer.json'),
            'composer_lock_sha256' => hash_file('sha256', $this->directory.'/composer.lock'),
            'operations' => [['type' => 'update', 'package' => 'codefog/contao-haste', 'from' => '5.4.1', 'to' => '5.4.2']],
        ];
        $this->jobs = new MaintenanceJobStore($this->directory);
        $this->progress = new UpdateProgressStore($this->directory);
        // A local Composer fixture executes through the real Symfony Process.
        file_put_contents($this->directory.'/composer.phar', <<<'PHP'
<?php
file_put_contents(__DIR__.'/composer-called', 'yes');
if (in_array('--dry-run', $argv, true)) {
    if (is_file(__DIR__.'/reject-dry-run')) { fwrite(STDERR, 'Dependency conflict'); exit(1); }
    echo "Package operations: 0 installs, 1 update, 0 removals\n  - Upgrading codefog/contao-haste (5.4.1 => 5.4.2)\n";
    exit(0);
}
file_put_contents(__DIR__.'/mutation-started', 'yes');
file_put_contents(__DIR__.'/composer.lock', '{"packages":[]}');
fwrite(STDERR, 'Simulated failure after a partial package write');
exit(1);
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testChangedComposerFilesRejectBeforeComposerAndReleaseTheWebsite(): void
    {
        // This is the IONOS test: change the requirement after approving its plan.
        $changed = str_replace('^5.4', '5.4.1', file_get_contents($this->directory.'/composer.json'));
        file_put_contents($this->directory.'/composer.json', $changed);
        self::assertSame(Command::FAILURE, $this->runWorker());
        self::assertFileDoesNotExist($this->directory.'/composer-called');
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
        self::assertSame($changed, file_get_contents($this->directory.'/composer.json'));
        self::assertSame($this->payload['composer_lock_sha256'], hash_file('sha256', $this->directory.'/composer.lock'));
        $status = $this->progress->read(self::ID);
        self::assertSame('error', $status['status']);
        self::assertStringContainsString('seit der Vorbereitung verändert', $status['message']);
        self::assertSame('error', $this->jobs->read(self::ID)['status']);
        $this->jobs->assertIdle();
    }

    public function testDryRunFailureReleasesTheWebsiteWithoutStartingMutation(): void
    {
        file_put_contents($this->directory.'/reject-dry-run', 'yes');
        self::assertSame(Command::FAILURE, $this->runWorker());
        self::assertFileExists($this->directory.'/composer-called');
        self::assertFileDoesNotExist($this->directory.'/mutation-started');
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
        self::assertSame($this->payload['composer_lock_sha256'], hash_file('sha256', $this->directory.'/composer.lock'));
        self::assertStringContainsString('Dependency conflict', $this->jobs->read(self::ID)['message']);
        $this->jobs->assertIdle();
    }

    public function testFailureAfterComposerStartsStillKeepsTheWebsiteProtected(): void
    {
        self::assertSame(Command::FAILURE, $this->runWorker());
        self::assertFileExists($this->directory.'/mutation-started');
        self::assertNotSame($this->payload['composer_lock_sha256'], hash_file('sha256', $this->directory.'/composer.lock'));
        self::assertStringStartsWith(WebsiteMaintenanceGuard::PAGE_MARKER, file_get_contents($this->directory.'/var/maintenance.html'));
        self::assertStringContainsString('Simulated failure after a partial package write', $this->jobs->read(self::ID)['message']);
        self::assertSame('error', $this->progress->read(self::ID)['status']);
    }

    private function runWorker(): int
    {
        $this->jobs->create(self::ID, self::SYSTEM, 'update', $this->payload);
        $php = new PhpCliResolver($this->directory, PHP_BINARY);
        $prepare = new UpdatePreparationService(new ComposerDryRunParser(), new UpdatePolicy(), $this->directory, $php);
        $install = new UpdateInstallationService($prepare, $this->directory, $php, '', $this->progress);
        $unused = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $worker = new MaintenanceWorkerCommand($this->jobs, $install, $unused(UpdateInstallationFinalizer::class),
            $this->progress, $unused(RestoreService::class), $unused(PublicAssetPermissionGuard::class),
            new NullLogger(), new WebsiteMaintenanceGuard($this->directory));
        $mask = umask();
        try {
            return (new CommandTester($worker))->execute(['request-id' => self::ID]);
        } finally {
            umask($mask);
        }
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
