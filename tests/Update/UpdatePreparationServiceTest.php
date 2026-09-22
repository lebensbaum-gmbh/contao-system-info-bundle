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
    public function testTemporarilyRewritesExactContaoConstraintForRequestedPatch(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp',
            new PhpCliResolver('/tmp')
        );

        $method = new ReflectionMethod($service, 'rewriteExactContaoConstraints');
        $method->setAccessible(true);

        $contents = <<<'JSON'
{
    "require": {
        "php": "^8.2",
        "contao/manager-bundle": "5.3.50",
        "contao/conflicts": "*@dev",
        "terminal42/notification_center": "^2.0"
    }
}
JSON;

        $rewritten = $method->invoke(
            $service,
            $contents,
            json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
            '5.3.50',
            '5.3.51'
        );

        self::assertStringContainsString('"contao/manager-bundle": "5.3.51"', $rewritten);
        self::assertStringContainsString('"contao/conflicts": "*@dev"', $rewritten);
        self::assertStringContainsString('"terminal42/notification_center": "^2.0"', $rewritten);
    }

    public function testDoesNotRewriteFlexibleContaoConstraint(): void
    {
        $service = new UpdatePreparationService(
            new ComposerDryRunParser(),
            new UpdatePolicy(),
            '/tmp',
            new PhpCliResolver('/tmp')
        );

        $method = new ReflectionMethod($service, 'rewriteExactContaoConstraints');
        $method->setAccessible(true);

        $contents = '{"require":{"contao/manager-bundle":"5.3.*"}}';

        self::assertSame(
            $contents,
            $method->invoke(
                $service,
                $contents,
                json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
                '5.3.50',
                '5.3.51'
            )
        );
    }

}
