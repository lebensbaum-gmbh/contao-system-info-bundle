<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
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
            '/tmp',
            new PhpCliResolver('/tmp')
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
            '/tmp',
            new PhpCliResolver('/tmp')
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
    public function testBuildsProjectWideUpdateArgumentsAndPinsContaoPackages(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp',
            new PhpCliResolver('/tmp')
        );

        $method = new ReflectionMethod($service, 'updatePackageArguments');
        $method->setAccessible(true);

        $arguments = $method->invoke($service, [
            'require' => [
                'php' => '^8.4',
                'contao/newsletter-bundle' => '5.7.*',
                'doctrine/orm' => '^3.0',
                'contao/manager-bundle' => '5.7.*',
                'terminal42/notification_center' => '^2.7',
                'contao/conflicts' => '*@dev',
                'lebensbaum/contao-system-info-bundle' => 'dev-feature/project-wide-update-plan',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^11.5',
            ],
        ], '5.7.14');

        self::assertSame([
            'contao/conflicts',
            'contao/manager-bundle:5.7.14',
            'contao/newsletter-bundle:5.7.14',
            'doctrine/orm',
            'phpunit/phpunit',
            'terminal42/notification_center',
        ], $arguments);
    }

    public function testBuildsProjectWideUpdateArgumentsWithoutTargetPinning(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp',
            new PhpCliResolver('/tmp')
        );

        $method = new ReflectionMethod($service, 'updatePackageArguments');
        $method->setAccessible(true);

        self::assertSame([
            'contao/manager-bundle',
            'terminal42/notification_center',
        ], $method->invoke($service, [
            'require' => [
                'contao/manager-bundle' => '5.7.*',
                'terminal42/notification_center' => '^2.7',
                'lebensbaum/contao-system-info-bundle' => '^1.0',
            ],
        ], null));
    }

}
