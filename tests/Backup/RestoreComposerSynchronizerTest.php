<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Backup;

use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreComposerSynchronizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RestoreComposerSynchronizerTest extends TestCase
{
    public function testComposerInstallUsesRestoredLockWithoutScripts(): void
    {
        $synchronizer = new RestoreComposerSynchronizer('/tmp', new PhpCliResolver('/tmp'));
        $method = new ReflectionMethod($synchronizer, 'composerInstallArguments');
        $method->setAccessible(true);

        $arguments = $method->invoke($synchronizer);

        self::assertSame([
            '--no-scripts',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
            '--optimize-autoloader',
        ], $arguments);
        self::assertNotContains('--no-install', $arguments);
        self::assertNotContains('--dry-run', $arguments);
    }
}
