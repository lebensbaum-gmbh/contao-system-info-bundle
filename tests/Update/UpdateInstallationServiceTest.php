<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UpdateInstallationServiceTest extends TestCase
{
    public function testPinsVersionedContaoPackagesButLeavesConflictsUnpinned(): void
    {
        $service = new UpdateInstallationService(
            $this->createMock(UpdatePreparationService::class),
            '/tmp'
        );

        $method = new ReflectionMethod($service, 'pinnedPackageArguments');
        $method->setAccessible(true);

        self::assertSame([
            'contao/calendar-bundle:5.7.13',
            'contao/comments-bundle:5.7.13',
            'contao/conflicts',
            'contao/manager-bundle:5.7.13',
        ], $method->invoke($service, [
            'contao/calendar-bundle',
            'contao/comments-bundle',
            'contao/conflicts',
            'contao/manager-bundle',
        ], '5.7.13'));
    }

    public function testOperationComparisonIsOrderIndependent(): void
    {
        $service = new UpdateInstallationService(
            $this->createMock(UpdatePreparationService::class),
            '/tmp'
        );

        $method = new ReflectionMethod($service, 'normalizeOperations');
        $method->setAccessible(true);

        $left = [
            ['type' => 'update', 'package' => 'contao/news-bundle', 'from' => '5.7.12', 'to' => '5.7.13'],
            ['type' => 'update', 'package' => 'contao/core-bundle', 'from' => '5.7.12', 'to' => '5.7.13'],
        ];
        $right = array_reverse($left);

        self::assertSame($method->invoke($service, $left), $method->invoke($service, $right));
    }
}
