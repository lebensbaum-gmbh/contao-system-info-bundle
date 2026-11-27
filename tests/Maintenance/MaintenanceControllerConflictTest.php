<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Lebensbaum\ContaoSystemInfoBundle\Backup\BackupService;
use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Controller\BackupController;
use Lebensbaum\ContaoSystemInfoBundle\Controller\RestoreController;
use Lebensbaum\ContaoSystemInfoBundle\Controller\UpdateInstallationController;
use Lebensbaum\ContaoSystemInfoBundle\Controller\UpdatePreparationController;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionNonceStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\RequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\SecretCipher;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class MaintenanceControllerConflictTest extends TestCase
{
    private const SYSTEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ACTIVE = '11111111111111111111111111111111';
    private const INCOMING = '22222222222222222222222222222222';
    private const SECRET = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ACTIONS = ['backup', 'backup/delete', 'update-prepare', 'update-install', 'restore'];

    private string $directory;
    private MaintenanceJobStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-http-conflict-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->store = new MaintenanceJobStore($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testQueuedAndRunningJobsRejectAllActionsWithoutErrorLogsOrJobChanges(): void
    {
        $this->store->create(self::ACTIVE, self::SYSTEM, 'restore', ['backup_id' => self::ACTIVE]);

        foreach (['pending', 'running'] as $status) {
            if ('running' === $status) {
                $this->store->progress(self::ACTIVE, 'database', 'running', 'Restore aktiv.');
            }
            $before = $this->store->read(self::ACTIVE);

            foreach (self::ACTIONS as $action) {
                $logger = $this->createMock(LoggerInterface::class);
                $logger->expects(self::never())->method('error');
                $response = $this->call($action, $logger);
                $this->assertConflict($response);
                self::assertSame($before, $this->store->read(self::ACTIVE));
                self::assertCount(1, glob($this->directory.'/var/domain-manager/maintenance-jobs/*.json'));
                self::assertFileDoesNotExist($this->directory.'/var/domain-manager/update-progress/'.self::INCOMING.'.json');
            }
        }
    }

    public function testSynchronousReservationRejectsAllActionsWithoutCreatingJobs(): void
    {
        $this->store->runWhenIdle(static fn () => null);
        $handle = fopen($this->directory.'/var/domain-manager/maintenance-jobs/.queue.lock', 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            foreach (self::ACTIONS as $action) {
                $logger = $this->createMock(LoggerInterface::class);
                $logger->expects(self::never())->method('error');
                $this->assertConflict($this->call($action, $logger));
                self::assertSame([], glob($this->directory.'/var/domain-manager/maintenance-jobs/*.json') ?: []);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertSame('free', $this->store->runWhenIdle(static fn (): string => 'free'));
    }

    public function testDuplicateAsyncRequestCannotMarkTheOriginalJobAsFailed(): void
    {
        $this->store->create(self::ACTIVE, self::SYSTEM, 'update', ['request_id' => self::ACTIVE]);

        foreach (['pending', 'running', 'success'] as $status) {
            if ('pending' !== $status) {
                $this->store->progress(self::ACTIVE, 'test', $status, 'Originalauftrag.');
            }
            $before = $this->store->read(self::ACTIVE);

            foreach (['update-install', 'restore'] as $action) {
                $logger = $this->createMock(LoggerInterface::class);
                $logger->expects(self::never())->method('error');
                $this->assertConflict($this->call($action, $logger, self::ACTIVE));
                self::assertSame($before, $this->store->read(self::ACTIVE));
            }
        }
    }

    public function testStorageFailuresAreStillLoggedAndAreNotReportedAsMaintenanceConflicts(): void
    {
        $this->store->runWhenIdle(static fn () => null);
        self::assertTrue(unlink($this->directory.'/var/domain-manager/maintenance-jobs/.queue.lock'));
        self::assertTrue(mkdir($this->directory.'/var/domain-manager/maintenance-jobs/.queue.lock'));

        foreach (self::ACTIONS as $action) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error');
            $response = $this->call($action, $logger);
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            self::assertContains($response->getStatusCode(), [409, 500, 503]);
            self::assertNotSame('maintenance_busy', $data['error']);
            self::assertStringContainsString('konnte nicht gesperrt werden', $data['detail']);
        }
    }

    public function testUnauthorizedRequestDoesNotDiscloseOrChangeAnActiveJob(): void
    {
        $this->store->create(self::ACTIVE, self::SYSTEM, 'restore', []);
        $before = $this->store->read(self::ACTIVE);

        foreach (self::ACTIONS as $action) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::never())->method('error');
            $response = $this->call($action, $logger, self::INCOMING, str_repeat('c', 64));
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('unauthorized', $data['error']);
            self::assertArrayNotHasKey('detail', $data);
            self::assertSame($before, $this->store->read(self::ACTIVE));
        }
    }

    private function assertConflict(JsonResponse $response): void
    {
        self::assertSame(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('maintenance_busy', $data['error']);
        self::assertNotSame('', $data['detail']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    private function call(
        string $action,
        LoggerInterface $logger,
        string $id = self::INCOMING,
        string $signingSecret = self::SECRET,
    ): JsonResponse {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(false);
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $credentials = new CredentialStore($connection, new SecretCipher('test-kernel-secret'), self::SYSTEM, self::SECRET);
        $auth = new ActionRequestAuthenticator(new ActionNonceStore($this->directory));
        // A reservation must reject these requests before any mutation service or launcher is invoked.
        $unused = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = $this->signedRequest($action, $id, $signingSecret);

        if ('update-prepare' === $action) {
            return (new UpdatePreparationController(
                $credentials, $auth, $unused(UpdatePreparationService::class), $this->store, $logger
            ))->prepare($request);
        }
        if ('update-install' === $action) {
            return (new UpdateInstallationController(
                $credentials, $auth, $unused(UpdateInstallationService::class), $unused(UpdateInstallationFinalizer::class),
                new UpdateProgressStore($this->directory), $logger, $this->store, $unused(MaintenanceJobLauncher::class)
            ))->install($request);
        }
        if ('restore' === $action) {
            return (new RestoreController(
                $credentials, $auth, $unused(RestoreService::class), $logger, $this->store, $unused(MaintenanceJobLauncher::class)
            ))->restore($request);
        }

        $controller = new BackupController(
            $credentials, new RequestAuthenticator(), $auth, $unused(BackupService::class), $this->store, $logger
        );

        return 'backup/delete' === $action ? $controller->delete($request) : $controller->create($request);
    }

    private function signedRequest(string $action, string $id, string $secret): Request
    {
        $path = '/_domainverwaltung/actions/'.$action;
        $body = json_encode(['request_id' => $id, 'backup_id' => self::ACTIVE], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', implode("\n", [
            $timestamp, $nonce, 'POST', $path, hash('sha256', $body),
        ]), $secret);

        return Request::create($path, 'POST', [], [], [], [
            'HTTP_X_DOMAIN_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_DOMAIN_MANAGER_NONCE' => $nonce,
            'HTTP_X_DOMAIN_MANAGER_SIGNATURE' => $signature,
        ], $body);
    }
}
