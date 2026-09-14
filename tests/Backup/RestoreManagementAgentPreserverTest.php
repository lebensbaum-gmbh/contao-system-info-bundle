<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreManagementAgentPreserver;
use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class RestoreManagementAgentPreserverTest extends TestCase
{
    public function testTemporaryConstraintPinsDevReference(): void
    {
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp');
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
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp');
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
        $preserver = new RestoreManagementAgentPreserver(new ComposerDryRunParser(), '/tmp');
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
