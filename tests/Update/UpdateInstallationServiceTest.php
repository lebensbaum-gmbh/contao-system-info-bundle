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
