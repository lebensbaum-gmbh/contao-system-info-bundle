<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePolicy;
use PHPUnit\Framework\TestCase;

final class UpdatePolicyTest extends TestCase
{
    public function testAllowsPatchUpdateWithinSameBranch(): void
    {
        $policy = new UpdatePolicy();
        $result = $policy->evaluate('5.7.10', '5.7.11');

        self::assertTrue($result['allowed']);
        self::assertSame('', $result['reason']);
    }

    public function testAllowsNoVersionChange(): void
    {
        $policy = new UpdatePolicy();
        $result = $policy->evaluate('v5.7.11', '5.7.11');

        self::assertTrue($result['allowed']);
    }

    public function testBlocksMinorBranchChange(): void
    {
        $policy = new UpdatePolicy();
        $result = $policy->evaluate('5.3.51', '5.7.11');

        self::assertFalse($result['allowed']);
        self::assertStringContainsString('5.3', $result['reason']);
        self::assertStringContainsString('5.7', $result['reason']);
    }

    public function testBlocksMajorBranchChange(): void
    {
        $policy = new UpdatePolicy();
        $result = $policy->evaluate('5.7.11', '6.0.0');

        self::assertFalse($result['allowed']);
    }

    public function testBlocksUnknownVersionFormat(): void
    {
        $policy = new UpdatePolicy();
        $result = $policy->evaluate('dev-main', 'dev-main');

        self::assertFalse($result['allowed']);
    }
}
