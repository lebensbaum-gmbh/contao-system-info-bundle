<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreManagementAgentPreserver;
use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class RestoreManagementAgentPreserverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir().'/maintend-preserver-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->fixtureDir.'/vendor/composer', 0755, true));
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixtureDir) || !is_dir($this->fixtureDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixtureDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->fixtureDir);
    }

    public function testRestoreSkipsNetworkComposerWhenAgentAlreadyMatches(): void
    {
        [$state] = $this->createLocalAgentFixture();

        // No Composer or Contao Manager is installed in the fixture. The
        // function must return without trying to resolve a CLI/Composer.
        $result = $this->preserver()->restore($state);

        self::assertSame(true, $result['management_agent_preserved']);
        self::assertSame($state['reference'], $result['management_agent_reference']);
    }

    public function testLocalNoopRejectsDifferentComposerConstraint(): void
    {
        [$state, $composer] = $this->createLocalAgentFixture();
        $composer['require']['lebensbaum/contao-system-info-bundle'] = 'dev-other-branch';

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    public function testLocalNoopRejectsDifferentLockedReference(): void
    {
        [$state, $composer, $lock] = $this->createLocalAgentFixture();
        $lock['packages'][0]['source']['reference'] = str_repeat('b', 40);
        $this->writeJson($this->fixtureDir.'/composer.lock', $lock);

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    public function testLocalNoopRejectsDifferentInstalledReference(): void
    {
        [$state, $composer] = $this->createLocalAgentFixture();
        $this->writeInstalledMeta($state['version'], str_repeat('b', 40));

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    public function testLocalNoopRejectsMissingInstalledMetadata(): void
    {
        [$state, $composer] = $this->createLocalAgentFixture();
        unlink($this->fixtureDir.'/vendor/composer/installed.php');

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    public function testLocalNoopRejectsMissingReference(): void
    {
        [$state, $composer] = $this->createLocalAgentFixture();
        $state['reference'] = '';

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    public function testLocalNoopRejectsDifferentInstalledVersion(): void
    {
        [$state, $composer] = $this->createLocalAgentFixture();
        $this->writeInstalledMeta('dev-other-branch', $state['reference']);

        self::assertFalse($this->checkLocalAgent($state, $composer));
    }

    /**
     * @return array{0:array{section:string,constraint:string,version:string,reference:string},1:array<string,mixed>,2:array<string,mixed>}
     */
    private function createLocalAgentFixture(): array
    {
        $name = 'lebensbaum/contao-system-info-bundle';
        $version = 'dev-feature/project-wide-update-plan';
        $state = [
            'section' => 'require',
            'constraint' => $version,
            'version' => $version,
            'reference' => str_repeat('a', 40),
        ];
        $composer = ['require' => [$name => $version]];
        $lock = ['packages' => [
            ['name' => $name, 'version' => $version, 'source' => ['reference' => $state['reference']]],
        ]];

        $this->writeJson($this->fixtureDir.'/composer.json', $composer);
        $this->writeJson($this->fixtureDir.'/composer.lock', $lock);
        $this->writeInstalledMeta($version, $state['reference']);

        return [$state, $composer, $lock];
    }

    private function writeJson(string $path, array $data): void
    {
        self::assertNotFalse(file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function writeInstalledMeta(string $version, string $reference): void
    {
        $data = ['versions' => [
            'lebensbaum/contao-system-info-bundle' => [
                'pretty_version' => $version,
                'reference' => $reference,
            ],
        ]];
        self::assertNotFalse(file_put_contents(
            $this->fixtureDir.'/vendor/composer/installed.php',
            '<?php return '.var_export($data, true).';'
        ));
    }

    private function preserver(): RestoreManagementAgentPreserver
    {
        return new RestoreManagementAgentPreserver(
            new ComposerDryRunParser(),
            $this->fixtureDir,
            new PhpCliResolver($this->fixtureDir)
        );
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $composer */
    private function checkLocalAgent(array $state, array $composer): bool
    {
        $method = new ReflectionMethod($this->preserver(), 'isAlreadyPreservedLocally');

        return $method->invoke($this->preserver(), $state, $composer);
    }

    public function testTemporaryConstraintPinsDevReference(): void
    {
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp', new PhpCliResolver('/tmp'));
        $method = new ReflectionMethod($preserver, 'temporaryConstraint');

        self::assertSame(
            'dev-feature/restore-preserve-system-info#abcdef1234567890',
            $method->invoke($preserver, [
                'section' => 'require',
                'constraint' => 'dev-feature/restore-preserve-system-info',
                'version' => 'dev-feature/restore-preserve-system-info',
                'reference' => 'abcdef1234567890',
            ])
        );
    }

    public function testSafeDryRunAcceptsOnlySystemInfoUpdate(): void
    {
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp', new PhpCliResolver('/tmp'));
        $method = new ReflectionMethod($preserver, 'assertSafeDryRun');

        $output = <<<'OUT'
Lock file operations: 0 installs, 1 update, 0 removals
  - Upgrading lebensbaum/contao-system-info-bundle (dev-old aaaaaaa => dev-new bbbbbbb)
Installing dependencies from lock file (including require-dev)
Package operations: 0 installs, 1 update, 0 removals
  - Upgrading lebensbaum/contao-system-info-bundle (dev-old aaaaaaa => dev-new bbbbbbb)
OUT;

        $method->invoke($preserver, $output);
        self::assertTrue(true);
    }

    public function testSafeDryRunRejectsAdditionalPackageChanges(): void
    {
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp', new PhpCliResolver('/tmp'));
        $method = new ReflectionMethod($preserver, 'assertSafeDryRun');

        $output = <<<'OUT'
Lock file operations: 0 installs, 2 updates, 0 removals
  - Upgrading lebensbaum/contao-system-info-bundle (dev-old aaaaaaa => dev-new bbbbbbb)
  - Upgrading symfony/process (v7.3.0 => v7.3.1)
OUT;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('weitere Composer-Änderungen');
        $method->invoke($preserver, $output);
    }
}
