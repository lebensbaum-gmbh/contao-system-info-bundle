<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use Lebensbaum\ContaoSystemInfoBundle\Update\PhpCliResolver;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePolicy;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UpdateInstallationServiceTest extends TestCase
{
    public function testBuildsProjectWideUpdateArgumentsAndProtectsManagementAgent(): void
    {
        $service = $this->service();

        $method = new ReflectionMethod($service, 'updatePackageArguments');
        $method->setAccessible(true);

        self::assertSame([
            'alnv/catalog-manager-bundle',
            'contao/conflicts',
            'contao/manager-bundle:5.7.14',
            'phpunit/phpunit',
            'terminal42/notification_center',
        ], $method->invoke($service, [
            'require' => [
                'php' => '^8.4',
                'contao/manager-bundle' => '5.7.*',
                'contao/conflicts' => '*@dev',
                'alnv/catalog-manager-bundle' => '^4.0',
                'terminal42/notification_center' => '^2.7',
                'lebensbaum/contao-system-info-bundle' => 'dev-feature/project-wide-update-plan',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^11.5',
            ],
        ], '5.7.14'));
    }

    public function testOperationComparisonIsOrderIndependent(): void
    {
        $service = $this->service();

        $method = new ReflectionMethod($service, 'normalizeOperations');
        $method->setAccessible(true);

        $left = [
            ['type' => 'update', 'package' => 'contao/news-bundle', 'from' => '5.7.12', 'to' => '5.7.13'],
            ['type' => 'update', 'package' => 'contao/core-bundle', 'from' => '5.7.12', 'to' => '5.7.13'],
        ];
        $right = array_reverse($left);

        self::assertSame($method->invoke($service, $left), $method->invoke($service, $right));
    }

    public function testPostInstallVerificationAcceptsExactlyThePreparedPlan(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'assertPlannedPackageChanges');
        $method->setAccessible(true);

        $before = [
            'packages' => [
                ['name' => 'contao/core-bundle', 'version' => '5.7.13'],
                ['name' => 'terminal42/contao-leads', 'version' => '3.3.2'],
                ['name' => 'terminal42/notification_center', 'version' => '2.7.4'],
            ],
        ];
        $after = [
            'packages' => [
                ['name' => 'contao/core-bundle', 'version' => '5.7.14'],
                ['name' => 'terminal42/contao-leads', 'version' => '3.4.0'],
                ['name' => 'terminal42/notification_center', 'version' => '2.7.6'],
            ],
        ];
        $operations = [
            ['type' => 'update', 'package' => 'contao/core-bundle', 'from' => '5.7.13', 'to' => '5.7.14'],
            ['type' => 'update', 'package' => 'terminal42/contao-leads', 'from' => '3.3.2', 'to' => '3.4.0'],
            ['type' => 'update', 'package' => 'terminal42/notification_center', 'from' => '2.7.4', 'to' => '2.7.6'],
        ];

        $method->invoke($service, $before, $after, $operations);
        self::assertTrue(true);
    }

    public function testPostInstallVerificationRejectsMissingExtensionUpdates(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'assertPlannedPackageChanges');
        $method->setAccessible(true);

        $before = [
            'packages' => [
                ['name' => 'contao/core-bundle', 'version' => '5.7.13'],
                ['name' => 'terminal42/contao-leads', 'version' => '3.3.2'],
            ],
        ];
        $after = [
            'packages' => [
                ['name' => 'contao/core-bundle', 'version' => '5.7.14'],
                ['name' => 'terminal42/contao-leads', 'version' => '3.3.2'],
            ],
        ];
        $operations = [
            ['type' => 'update', 'package' => 'contao/core-bundle', 'from' => '5.7.13', 'to' => '5.7.14'],
            ['type' => 'update', 'package' => 'terminal42/contao-leads', 'from' => '3.3.2', 'to' => '3.4.0'],
        ];

        $this->expectException(\Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationException::class);
        $this->expectExceptionMessage('terminal42/contao-leads');

        $method->invoke($service, $before, $after, $operations);
    }

    public function testPostInstallVerificationRejectsUnplannedChanges(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'assertPlannedPackageChanges');
        $method->setAccessible(true);

        $before = ['packages' => [
            ['name' => 'contao/core-bundle', 'version' => '5.7.13'],
            ['name' => 'vendor/extra', 'version' => '1.0.0'],
        ]];
        $after = ['packages' => [
            ['name' => 'contao/core-bundle', 'version' => '5.7.14'],
            ['name' => 'vendor/extra', 'version' => '2.0.0'],
        ]];
        $operations = [
            ['type' => 'update', 'package' => 'contao/core-bundle', 'from' => '5.7.13', 'to' => '5.7.14'],
        ];

        $this->expectException(\Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationException::class);
        $this->expectExceptionMessage('vendor/extra');

        $method->invoke($service, $before, $after, $operations);
    }

    private function service(): UpdateInstallationService
    {
        $preparationService = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp',
            new PhpCliResolver('/tmp')
        );

        return new UpdateInstallationService(
            $preparationService,
            '/tmp',
            new PhpCliResolver('/tmp')
        );
    }

}
