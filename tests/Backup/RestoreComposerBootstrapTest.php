<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreComposerSynchronizer;
use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreManagementAgentPreserver;
use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RestoreComposerBootstrapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintend-restore-bootstrap-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPlainCacheClearCannotBootAContainerForAnAgentClassRemovedByComposer(): void
    {
        [$project, $class] = $this->project('5.7');
        unlink($project.'/removed-subscriber.php');

        $process = new Process([PHP_BINARY, $project.'/vendor/bin/contao-console', 'cache:clear', '--env=prod'], $project);
        $process->run();

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString($class, $process->getErrorOutput().$process->getOutput());
        self::assertFileExists($project.'/var/cache/prod/container.php');
        self::assertFileDoesNotExist($project.'/setup-trace');
    }

    public function testArchivedComposerInstallBootsWithBothOriginalContaoSetupEntrypoints(): void
    {
        foreach (['4.13', '5.7'] as $version) {
            [$project] = $this->project($version);
            $result = (new RestoreComposerSynchronizer($project, new PhpCliResolver($project, PHP_BINARY)))->synchronize();

            self::assertTrue($result['composer_synchronized']);
            self::assertTrue($result['cache_rebuilt']);
            self::assertFileDoesNotExist($project.'/removed-subscriber.php');
            self::assertFileDoesNotExist($project.'/var/cache/prod/container.php');
            self::assertFileExists($project.'/var/cache/prod/rebuilt');
            self::assertSame(['install'], $this->trace($project, 'composer-trace'));
            self::assertSame(['setup'], $this->trace($project, 'setup-trace'));
        }
    }

    public function testAgentPreservationAlsoRebuildsAfterReplacingAnAgentClass(): void
    {
        foreach (['4.13', '5.7'] as $version) {
            [$project] = $this->project($version);
            $result = $this->preserver($project)->restore($this->agentState());

            self::assertTrue($result['management_agent_preserved']);
            self::assertTrue($result['cache_rebuilt']);
            self::assertSame(str_repeat('a', 40), $result['management_agent_reference']);
            self::assertFileDoesNotExist($project.'/removed-subscriber.php');
            self::assertFileDoesNotExist($project.'/var/cache/prod/container.php');
            self::assertFileExists($project.'/var/cache/prod/rebuilt');
            self::assertSame(['require', 'dry-run', 'update'], $this->trace($project, 'composer-trace'));
            self::assertSame(['setup'], $this->trace($project, 'setup-trace'));
        }
    }

    public function testFailedSetupAfterArchiveInstallIsNotReportedAsRebuilt(): void
    {
        [$project] = $this->project('5.7');
        file_put_contents($project.'/fail-setup', '1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture setup failure');
        (new RestoreComposerSynchronizer($project, new PhpCliResolver($project, PHP_BINARY)))->synchronize();
    }

    public function testFailedSetupAfterAgentPreservationIsNotReportedAsRebuilt(): void
    {
        [$project] = $this->project('4.13');
        file_put_contents($project.'/fail-setup', '1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture setup failure');
        $this->preserver($project)->restore($this->agentState());
    }

    private function preserver(string $project): RestoreManagementAgentPreserver
    {
        return new RestoreManagementAgentPreserver(new ComposerDryRunParser(), $project, new PhpCliResolver($project, PHP_BINARY));
    }

    /** @return array{section:string,constraint:string,version:string,reference:string} */
    private function agentState(): array
    {
        return [
            'section' => 'require',
            'constraint' => 'dev-feature/project-wide-update-plan',
            'version' => 'dev-feature/project-wide-update-plan',
            'reference' => str_repeat('a', 40),
        ];
    }

    /** @return array{string,string} */
    private function project(string $version): array
    {
        $project = $this->directory.'/project-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($project.'/vendor/bin', 0700, true));
        self::assertTrue(mkdir($project.'/vendor/composer', 0700, true));
        self::assertTrue(mkdir($project.'/var/cache/prod', 0700, true));
        self::assertTrue(copy(__DIR__.'/../Fixtures/contao-'.$version.'-setup.php', $project.'/vendor/bin/contao-setup'));

        $state = $this->agentState();
        $name = 'lebensbaum/contao-system-info-bundle';
        file_put_contents($project.'/composer.json', json_encode(['require' => [$name => $state['constraint']]], JSON_THROW_ON_ERROR));
        file_put_contents($project.'/composer.lock', json_encode(['packages' => [[
            'name' => $name, 'version' => $state['version'], 'source' => ['reference' => str_repeat('b', 40)],
        ]]], JSON_THROW_ON_ERROR));
        file_put_contents($project.'/vendor/composer/installed.php', '<?php return [];');

        // A real compiled container still names a service supplied by the
        // previous agent. The fixture Composer process removes its PHP file.
        $class = 'MaintendRemovedSubscriber'.bin2hex(random_bytes(8));
        $definition = 'final class '.$class.' { public function __construct(object $dependency) {} }';
        eval($definition);
        file_put_contents($project.'/removed-subscriber.php', '<?php '.$definition);
        $builder = new ContainerBuilder();
        $builder->register('listener.dependency', \stdClass::class);
        $builder->register('removed.subscriber', $class)->setPublic(true)->setArguments([new Reference('listener.dependency')]);
        $builder->compile();
        $containerClass = $class.'Container';
        file_put_contents($project.'/var/cache/prod/container.php', (new PhpDumper($builder))->dump([
            'class' => $containerClass,
        ])."\nreturn new \\".$containerClass."();\n");

        // Keep the two licensed Contao entrypoints unchanged. Only their
        // kernel/application collaborators are isolated: constructing the
        // application boots the stale DI service if setup has not purged it.
        // This tests bootstrap ordering, not the full Contao setup or a DB.
        file_put_contents($project.'/fixture-kernel.php', <<<'PHP'
<?php
namespace Contao\ManagerBundle\HttpKernel;
final class ContaoKernel
{
    public function __construct(public string $projectDir, private string $environment) {}
    public static function fromInput(string $projectDir, $input): self
    {
        return new self($projectDir, $input->getParameterOption('--env', 'prod'));
    }
    public function getCacheDir(): string { return $this->projectDir.'/var/cache/'.$this->environment; }
}
namespace Contao\ManagerBundle\Console;
final class ContaoApplication
{
    public function __construct(private \Contao\ManagerBundle\HttpKernel\ContaoKernel $kernel)
    {
        $path = $kernel->getCacheDir().'/container.php';
        if (is_file($path)) (require $path)->get('removed.subscriber');
    }
    public function setDefaultCommand(string $command): void {}
    public function run(): int
    {
        $project = $this->kernel->projectDir;
        file_put_contents($project.'/setup-trace', "setup\n", FILE_APPEND);
        if (is_file($project.'/fail-setup')) {
            fwrite(STDERR, "fixture setup failure\n");
            exit(7);
        }
        if (!is_dir($this->kernel->getCacheDir())) mkdir($this->kernel->getCacheDir(), 0700, true);
        file_put_contents($this->kernel->getCacheDir().'/rebuilt', '1');
        return 0;
    }
}
PHP);
        file_put_contents($project.'/vendor/autoload.php', '<?php'
            ."\nrequire __DIR__.'/../fixture-kernel.php';"
            ."\nrequire ".var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
            ."\nif (is_file(__DIR__.'/../removed-subscriber.php')) require __DIR__.'/../removed-subscriber.php';"
        );
        file_put_contents($project.'/vendor/bin/contao-console', <<<'PHP'
<?php
require __DIR__.'/../autoload.php';
$kernel = \Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput(dirname(__DIR__, 2), new \Symfony\Component\Console\Input\ArrayInput(['--env' => 'prod']));
$application = new \Contao\ManagerBundle\Console\ContaoApplication($kernel);
$application->run();
PHP);
        file_put_contents($project.'/composer.phar', <<<'PHP'
<?php
$project = __DIR__;
$operation = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
file_put_contents($project.'/composer-trace', ($dryRun ? 'dry-run' : $operation)."\n", FILE_APPEND);
if ('require' === $operation) exit(0);
if ($dryRun) {
    echo "Lock file operations: 0 installs, 1 update, 0 removals\n";
    echo "  - Upgrading lebensbaum/contao-system-info-bundle (dev-old bbbbbbb => dev-new aaaaaaa)\n";
    exit(0);
}
if (!in_array($operation, ['install', 'update'], true)) exit(9);
if (!in_array('--no-scripts', $argv, true)) exit(10);
if (is_file($project.'/removed-subscriber.php')) unlink($project.'/removed-subscriber.php');
if ('update' === $operation) {
    $lock = json_decode(file_get_contents($project.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $lock['packages'][0]['source']['reference'] = str_repeat('a', 40);
    file_put_contents($project.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
}
PHP);

        return [$project, $class];
    }

    /** @return list<string> */
    private function trace(string $project, string $name): array
    {
        return file($project.'/'.$name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
}
