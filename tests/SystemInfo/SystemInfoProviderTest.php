<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\SystemInfo;

use Doctrine\DBAL\Connection;
use Lebensbaum\ContaoSystemInfoBundle\SystemInfo\SystemInfoProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SystemInfoProviderTest extends TestCase
{
    private const SYSTEM_ID = '0123456789abcdef0123456789abcdef';

    public function testCollectsDatabaseNameDocumentRootAndRequiredFields(): void
    {
        $provider = $this->createProvider(['dbname' => 'contao_test']);
        $request = Request::create(
            '/_domainverwaltung/systeminfo',
            'GET',
            [],
            [],
            [],
            [
                'DOCUMENT_ROOT' => '/www/htdocs/customer/example/public/',
                'SCRIPT_FILENAME' => '/www/htdocs/customer/example/public/index.php',
            ]
        );

        $data = $provider->collect($request, self::SYSTEM_ID);

        self::assertSame(self::SYSTEM_ID, $data['system_id']);
        self::assertSame('contao_test', $data['database_name']);
        self::assertSame('/www/htdocs/customer/example/public', $data['document_root']);
        self::assertSame('test', $data['app_environment']);
        self::assertSame(PHP_VERSION, $data['php_version']);
        self::assertArrayHasKey('contao_version', $data);
        self::assertTrue(null === $data['contao_version'] || is_string($data['contao_version']));
        self::assertNotSame('', $data['generated_at']);
    }

    public function testFallsBackToScriptFilenameForDocumentRoot(): void
    {
        $provider = $this->createProvider([]);
        $request = Request::create(
            '/_domainverwaltung/systeminfo',
            'GET',
            [],
            [],
            [],
            [
                'DOCUMENT_ROOT' => '',
                'SCRIPT_FILENAME' => '/www/htdocs/customer/example/web/index.php',
            ]
        );

        $data = $provider->collect($request, self::SYSTEM_ID);

        self::assertSame('', $data['database_name']);
        self::assertSame('/www/htdocs/customer/example/web', $data['document_root']);
    }

    public function testReturnsEmptyDocumentRootWhenServerValuesAreMissing(): void
    {
        $provider = $this->createProvider([]);
        $request = Request::create('/_domainverwaltung/systeminfo', 'GET');

        $data = $provider->collect($request, self::SYSTEM_ID);

        self::assertSame('', $data['document_root']);
    }

    /**
     * @param array<string, mixed> $connectionParams
     */
    private function createProvider(array $connectionParams): SystemInfoProvider
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('getParams')
            ->willReturn($connectionParams);

        return new SystemInfoProvider($connection, 'test');
    }
}
