<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\ComposerDryRunParser;
use PHPUnit\Framework\TestCase;

final class ComposerDryRunParserTest extends TestCase
{
    public function testParsesComposerOperationsAndContaoTarget(): void
    {
        $output = <<<'TXT'
Loading composer repositories with package information
Updating dependencies
Lock file operations: 1 install, 2 updates, 1 removal
  - Locking vendor/new-package (1.2.3)
  - Upgrading contao/core-bundle (5.7.10 => 5.7.11)
  - Upgrading symfony/http-kernel (v7.3.2 => v7.3.3)
  - Removing vendor/old-package (2.0.0)
TXT;

        $parser = new ComposerDryRunParser();
        $result = $parser->parse($output);

        self::assertSame([
            'installs' => 1,
            'updates' => 2,
            'removals' => 1,
        ], $result['summary']);
        self::assertCount(4, $result['operations']);
        self::assertSame('update', $result['operations'][1]['type']);
        self::assertSame('contao/core-bundle', $result['operations'][1]['package']);
        self::assertSame('5.7.10', $result['operations'][1]['from']);
        self::assertSame('5.7.11', $result['operations'][1]['to']);
        self::assertSame('5.7.11', $parser->targetContaoVersion($result['operations'], '5.7.10'));
    }

    public function testKeepsCurrentVersionWhenNoContaoUpdateIsPlanned(): void
    {
        $parser = new ComposerDryRunParser();
        $result = $parser->parse("Nothing to modify in lock file\n");

        self::assertSame([
            'installs' => 0,
            'updates' => 0,
            'removals' => 0,
        ], $result['summary']);
        self::assertSame([], $result['operations']);
        self::assertSame('5.7.11', $parser->targetContaoVersion($result['operations'], '5.7.11'));
    }

    public function testExtractsVersionTokenFromComposerReferenceSuffix(): void
    {
        $parser = new ComposerDryRunParser();
        $operations = [[
            'type' => 'update',
            'package' => 'contao/core-bundle',
            'from' => '5.7.10 1234567',
            'to' => '5.7.11 7654321',
        ]];

        self::assertSame('5.7.11', $parser->targetContaoVersion($operations, '5.7.10'));
    }
}
