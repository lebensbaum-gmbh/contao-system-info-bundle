<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MaintenanceJobStoreTest extends TestCase
{
    private string $directory;
    private const ONE = '11111111111111111111111111111111';
    private const TWO = '22222222222222222222222222222222';
    private const SYSTEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-jobs-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->clearDirectory($this->directory);
    }

    public function testCreatesReadableProtectedJobWithoutLeakingPayloadToStatus(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'restore', ['backup_id' => self::TWO]);

        $job = $store->read(self::ONE);
        self::assertSame('pending', $job['status']);
        self::assertSame(['backup_id' => self::TWO], $job['payload']);
        $status = $store->publicStatus(self::ONE, self::SYSTEM, 'restore');
        self::assertSame(self::ONE, $status['request_id']);
        self::assertArrayNotHasKey('payload', $status);
        self::assertArrayNotHasKey('system_id', $status);
        self::assertSame('pending', $status['status']);
        self::assertSame(0600, fileperms($this->directory.'/var/domain-manager/maintenance-jobs/'.self::ONE.'.json') & 0777);
    }

    public function testGlobalQueueRejectsOtherOperationUntilJobFinishes(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'update', ['request_id' => self::ONE]);
        try {
            $store->assertIdle();
            self::fail('Active job not blocked');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('läuft bereits', $e->getMessage());
        }


        try {
            $store->create(self::TWO, self::SYSTEM, 'restore', ['backup_id' => self::ONE]);
            self::fail('Concurrent maintenance job was accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('läuft bereits', $e->getMessage());
        }

        $store->progress(self::ONE, 'completed', 'success', 'Update vollständig bestätigt.', ['id' => self::ONE]);
        $store->assertIdle();
        $store->create(self::TWO, self::SYSTEM, 'restore', ['backup_id' => self::ONE]);
        self::assertSame('pending', $store->read(self::TWO)['status']);
    }

    public function testWorkerCannotRunTwiceForTheSameJob(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'restore', ['backup_id' => self::TWO]);

        $running = false;
        $result = $store->runExclusively(self::ONE, function () use ($store, &$running): string {
            $running = true;
            try {
                $store->runExclusively(self::ONE, static fn (): string => 'should never run');
                self::fail('A second worker acquired the same job lock.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('bereits', $exception->getMessage());
            }

            return 'locked';
        });

        self::assertTrue($running);
        self::assertSame('locked', $result);
        // A later run may obtain the file lock, but the CLI command also
        // checks that the job is pending before executing it.
        self::assertSame('reusable lock', $store->runExclusively(
            self::ONE,
            static fn (): string => 'reusable lock'
        ));
    }

    public function testStatusRejectsForeignSystemAndOperation(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'restore', ['backup_id' => self::TWO]);

        foreach ([
            ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'restore'],
            [self::SYSTEM, 'update'],
        ] as [$systemId, $operation]) {
            try {
                $store->publicStatus(self::ONE, $systemId, $operation);
                self::fail('Foreign job status was exposed.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('gehört nicht', $e->getMessage());
            }
        }
    }

    public function testTerminalJobCannotBeOverwritten(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'restore', ['backup_id' => self::TWO]);
        $store->progress(self::ONE, 'error', 'error', 'Restore fehlgeschlagen.');

        $this->expectException(RuntimeException::class);
        $store->progress(self::ONE, 'completed', 'success', 'Fälschlicher Erfolg.');
    }

    public function testInvalidAndReusedRequestIdsAreRejected(): void
    {
        $store = new MaintenanceJobStore($this->directory);
        $store->create(self::ONE, self::SYSTEM, 'restore', []);

        try {
            $store->create(self::ONE, self::SYSTEM, 'restore', []);
            self::fail('Duplicate ID accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('bereits', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $store->read('../etc/passwd');
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
