<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\SystemInfo;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class SystemInfoProvider
{
    private const API_VERSION = 1;
    private const PACKAGE_NAME = 'lebensbaum/contao-system-info-bundle';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment,
    ) {
    }

    /**
     * @return array{
     *     api_version:int,
     *     system_info_version:string,
     *     system_id:string,
     *     contao_version:?string,
     *     php_version:string,
     *     database_name:string,
     *     document_root:string,
     *     app_environment:string,
     *     generated_at:string
     * }
     */
    public function collect(Request $request, string $systemId): array
    {
        $contaoVersion = InstalledVersions::isInstalled('contao/core-bundle')
            ? InstalledVersions::getPrettyVersion('contao/core-bundle')
            : null;
        $systemInfoVersion = InstalledVersions::isInstalled(self::PACKAGE_NAME)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE_NAME)
            : null;

        $connectionParams = $this->connection->getParams();
        $databaseName = trim((string) ($connectionParams['dbname'] ?? ''));

        return [
            'api_version' => self::API_VERSION,
            'system_info_version' => $systemInfoVersion ?? 'unknown',
            'system_id' => $systemId,
            'contao_version' => $contaoVersion,
            'php_version' => PHP_VERSION,
            'database_name' => $databaseName,
            'document_root' => $this->resolveDocumentRoot($request),
            'app_environment' => $this->environment,
            'generated_at' => gmdate('c'),
        ];
    }

    private function resolveDocumentRoot(Request $request): string
    {
        $documentRoot = trim((string) $request->server->get('DOCUMENT_ROOT', ''));

        if ('' !== $documentRoot) {
            return rtrim($documentRoot, "/\\");
        }

        $scriptFilename = trim((string) $request->server->get('SCRIPT_FILENAME', ''));

        if ('' !== $scriptFilename) {
            return rtrim(\dirname($scriptFilename), "/\\");
        }

        return '';
    }
}
