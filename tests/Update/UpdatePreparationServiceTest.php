<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePolicy;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UpdatePreparationServiceTest extends TestCase
{
    public function testSelectsAllDirectContaoPackagesAndIgnoresForeignPackages(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp'
        );

        $method = new ReflectionMethod($service, 'contaoPackages');
        $method->setAccessible(true);

        $packages = $method->invoke($service, [
            'require' => [
                'php' => '^8.4',
                'contao/newsletter-bundle' => '5.7.*',
                'doctrine/orm' => '^3.0',
                'contao/manager-bundle' => '5.7.*',
                'contao/comments-bundle' => '5.7.*',
                'terminal42/notification_center' => '^2.7',
                'contao/calendar-bundle' => '5.7.*',
                'contao/conflicts' => '*@dev',
            ],
        ]);

        self::assertSame([
            'contao/calendar-bundle',
            'contao/comments-bundle',
            'contao/conflicts',
            'contao/manager-bundle',
            'contao/newsletter-bundle',
        ], $packages);
    }

    public function testRequiresManagerOrCoreBundleAsDirectRootPackage(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp'
        );

        $method = new ReflectionMethod($service, 'contaoPackages');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($service, [
            'require' => [
                'contao/calendar-bundle' => '5.7.*',
                'contao/news-bundle' => '5.7.*',
            ],
        ]));
    }
}
