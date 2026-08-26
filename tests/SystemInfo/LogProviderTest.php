<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\SystemInfo;

use Lebensbaum\ContaoSystemInfoBundle\SystemInfo\LogProvider;
use PHPUnit\Framework\TestCase;

final class LogProviderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/contao-system-info-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->projectDir.'/var/logs/prod-test.log';

        if (is_file($file)) {
            unlink($file);
        }

        @rmdir($this->projectDir.'/var/logs');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testOnlyRelevantEntriesAreReturnedAsStructuredData(): void
    {
        $date = (new \DateTimeImmutable())->format('Y-m-d\\TH:i:s.uP');
        $log = sprintf(
            "[%s] request.INFO: Matched route. {\"request_uri\":\"https://example.org/\"} {\"request_method\":\"GET\"}\n".
            "[%s] request.CRITICAL: Uncaught PHP Exception Exception: \"Could not find template \\\"nav_default_nature\\\"\" at TemplateLoader.php line 156 {\"exception\":\"[object] (Exception(code: 0): Could not find template \\\"nav_default_nature\\\" at /var/www/vendor/TemplateLoader.php:156)\"} {\"request_uri\":\"https://example.org/\",\"request_method\":\"GET\"}\n",
            $date,
            $date,
        );
        file_put_contents($this->projectDir.'/var/logs/prod-test.log', $log);

        $result = (new LogProvider($this->projectDir))->collect('0123456789abcdef0123456789abcdef');

        self::assertSame('0123456789abcdef0123456789abcdef', $result['system_id']);
        self::assertCount(1, $result['entries']);
        self::assertSame('CRITICAL', $result['entries'][0]['level']);
        self::assertSame('request', $result['entries'][0]['channel']);
        self::assertStringContainsString('nav_default_nature', $result['entries'][0]['message']);
        self::assertSame('https://example.org/', $result['entries'][0]['request_uri']);
        self::assertSame('Exception', $result['entries'][0]['exception_class']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['entries'][0]['fingerprint']);
    }
}
