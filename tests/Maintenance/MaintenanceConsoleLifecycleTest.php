<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Maintenance;

use ErrorException;
use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Command\MaintenanceWorkerCommand;
use Lebensbaum\ContaoSystemInfoBundle\EventSubscriber\MaintenanceConsoleLifecycleSubscriber;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\PublicAssetPermissionGuard;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\WebsiteMaintenanceGuard;
use Lebensbaum\ContaoSystemInfoBundle\Tests\Fixtures\ConsoleLifecycleListener;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

final class MaintenanceConsoleLifecycleTest extends TestCase
{
    private const ID = '11111111111111111111111111111111';
    private const SYSTEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $directory;
    private MaintenanceJobStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-console-lifecycle-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->store = new MaintenanceJobStore($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testUnguardedApplicationReproducesMissingContainerFactoryAfterSuccessfulCommand(): void
    {
        [$application] = $this->application(false);
        $this->store->create(self::ID, self::SYSTEM, 'probe', ['maintenance_page' => true]);

        // Turn the missing require warning into an exception consistently on
        // PHPUnit 9 and 11. The failure occurs in Application, not in the job.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (E_WARNING === $severity && str_contains($message, 'getFosHttpCache_EventListener_InvalidationService.php')) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
        $failure = null;
        try {
            $this->runApplication($application);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(ErrorException::class, $failure, json_encode([
            'trace' => $this->trace(), 'cache_exists' => is_dir($this->directory.'/cache'),
        ], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('getFosHttpCache_EventListener_InvalidationService.php', $failure->getMessage());
        self::assertSame('success', $this->store->read(self::ID)['status']);
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
        self::assertSame(['cache-removed'], $this->trace());
    }

    public function testFullApplicationTerminatesAfterContainerRemovalAndStillRunsBothListeners(): void
    {
        [$application, $dispatcher] = $this->application(true);
        $this->store->create(self::ID, self::SYSTEM, 'probe', ['maintenance_page' => true]);

        self::assertSame(Command::SUCCESS, $this->runApplication($application));
        self::assertSame('success', $this->store->read(self::ID)['status']);
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
        self::assertSame([
            'constructed:terminate', 'constructed:error', 'cache-removed', 'called:terminate',
        ], $this->trace());

        // Error handling also remains usable without the old container files;
        // listener preparation must not dispatch this event prematurely.
        $error = new RuntimeException('Unchanged application error');
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), $error);
        $dispatcher->dispatch($event, ConsoleEvents::ERROR);
        self::assertSame($error, $event->getError());
        self::assertSame('called:error', array_slice($this->trace(), -1)[0]);
        $this->store->assertIdle();
    }

    public function testDeliberatelyFailedProbeKeepsItsFailureExitCodeAndNormalTermination(): void
    {
        [$application] = $this->application(true);
        $this->store->create(self::ID, self::SYSTEM, 'probe', ['maintenance_page' => true, 'fail' => true]);

        self::assertSame(Command::FAILURE, $this->runApplication($application));
        self::assertSame('error', $this->store->read(self::ID)['status']);
        self::assertSame('Absichtlicher Abbruch des CLI-Selbsttests.', $this->store->read(self::ID)['message']);
        self::assertFileDoesNotExist($this->directory.'/var/maintenance.html');
        self::assertSame([
            'constructed:terminate', 'constructed:error', 'cache-removed', 'called:terminate',
        ], $this->trace());
        $this->store->assertIdle();
    }

    public function testOtherCommandsDoNotResolveListenersEarly(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $subscriber = new MaintenanceConsoleLifecycleSubscriber($dispatcher);
        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            new Command('unrelated:command'), new ArrayInput([]), new NullOutput()
        ));

        self::assertSame([], $this->trace());
        self::assertDirectoryExists($this->directory.'/cache');
    }

    public function testLazyWorkerCommandAlsoPreparesItsTerminationListeners(): void
    {
        [$application] = $this->application(true, true);
        $this->store->create(self::ID, self::SYSTEM, 'probe', []);

        self::assertSame(Command::SUCCESS, $this->runApplication($application));
        self::assertSame('success', $this->store->read(self::ID)['status']);
        self::assertSame([
            'constructed:terminate', 'constructed:error', 'cache-removed', 'called:terminate',
        ], $this->trace());
    }

    /** @return array{Application, EventDispatcher} */
    private function application(bool $guard, bool $lazy = false): array
    {
        $dispatcher = $this->compiledDispatcher();
        if ($guard) {
            $dispatcher->addSubscriber(new MaintenanceConsoleLifecycleSubscriber($dispatcher));
        }
        // Simulate a Composer/Contao setup replacing the worker's own cache.
        // Use the real split DI container files, not a mocked dispatcher or
        // just CommandTester (which never dispatches console.terminate).
        $dispatcher->addListener(ConsoleEvents::COMMAND, function (): void {
            $this->removeDirectory($this->directory.'/cache');
            file_put_contents($this->directory.'/trace', "cache-removed\n", FILE_APPEND);
        }, -2048);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        // Shared hosting can lack usable pcntl signal handlers. Recent Symfony
        // versions otherwise prepare TERMINATE listeners for signal handling
        // already, which would accidentally hide this hosting-specific case.
        $application->setSignalsToDispatchEvent();
        $application->setDispatcher($dispatcher);
        $unused = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $worker = new MaintenanceWorkerCommand(
            $this->store, $unused(UpdateInstallationService::class), $unused(UpdateInstallationFinalizer::class),
            $unused(UpdateProgressStore::class), $unused(RestoreService::class),
            $unused(PublicAssetPermissionGuard::class), new NullLogger(), new WebsiteMaintenanceGuard($this->directory)
        );
        $application->add($lazy ? new LazyCommand(
            'maintend:maintenance-job', [], 'Test lazy worker', false, static fn (): Command => $worker
        ) : $worker);

        return [$application, $dispatcher];
    }

    private function compiledDispatcher(): EventDispatcher
    {
        $builder = new ContainerBuilder();
        $builder->register('maintend.test.listener_dependency', \stdClass::class);
        foreach (['fos_http_cache.event_listener.invalidation' => 'terminate', 'maintend.test.error' => 'error'] as $id => $kind) {
            // Like the real FOS listener, this service has an object dependency.
            // Scalar-only constructors are trivial and Symfony can inline them
            // into the listener closure, bypassing the split factory entirely.
            $builder->register($id, ConsoleLifecycleListener::class)
                ->setPublic(true)->setArguments([
                    $this->directory.'/trace', $kind, new Reference('maintend.test.listener_dependency'),
                ]);
        }
        $builder->register('event_dispatcher', EventDispatcher::class)->setPublic(true)
            ->addMethodCall('addListener', [ConsoleEvents::TERMINATE, [
                new ServiceClosureArgument(new Reference('fos_http_cache.event_listener.invalidation')), 'onConsoleEvent',
            ]])
            ->addMethodCall('addListener', [ConsoleEvents::ERROR, [
                new ServiceClosureArgument(new Reference('maintend.test.error')), 'onConsoleEvent',
            ]]);
        $builder->compile();
        $class = 'MaintendLifecycleContainer'.bin2hex(random_bytes(8));
        $files = (new PhpDumper($builder))->dump(['class' => $class, 'as_files' => true, 'debug' => false]);
        self::assertIsArray($files);
        foreach ($files as $path => $contents) {
            $target = $this->directory.'/cache/'.$path;
            if (!is_dir(dirname($target))) self::assertTrue(mkdir(dirname($target), 0700, true));
            self::assertNotFalse(file_put_contents($target, $contents));
        }
        $container = require $this->directory.'/cache/'.$class.'.php';

        return $container->get('event_dispatcher');
    }

    private function runApplication(Application $application): int
    {
        $mask = umask();
        try {
            return $application->run(new ArrayInput([
                'command' => 'maintend:maintenance-job', 'request-id' => self::ID,
            ]), new NullOutput());
        } finally {
            umask($mask);
        }
    }

    /** @return list<string> */
    private function trace(): array
    {
        return is_file($this->directory.'/trace')
            ? file($this->directory.'/trace', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) continue;
            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) $this->removeDirectory($path);
            else unlink($path);
        }
        rmdir($directory);
    }
}
