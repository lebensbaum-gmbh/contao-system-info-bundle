<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use Composer\InstalledVersions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SystemInfoController
{
    private const MAX_TIME_DIFFERENCE = 300;

    public function __construct(
        private readonly string $systemId,
        private readonly string $sharedSecret,
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if ('' === trim($this->systemId) || '' === trim($this->sharedSecret)) {
            return $this->createResponse(
                ['error' => 'service_not_configured'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $timestamp = $request->headers->get('X-Domain-Manager-Timestamp');
        $signature = $request->headers->get('X-Domain-Manager-Signature');

        if (
            !is_string($timestamp)
            || !ctype_digit($timestamp)
            || !is_string($signature)
        ) {
            return $this->createResponse(
                ['error' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIME_DIFFERENCE) {
            return $this->createResponse(
                ['error' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $signedContent = implode("\n", [
            $timestamp,
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            $signedContent,
            $this->sharedSecret
        );

        if (!hash_equals($expectedSignature, strtolower($signature))) {
            return $this->createResponse(
                ['error' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $contaoVersion = InstalledVersions::isInstalled('contao/core-bundle')
            ? InstalledVersions::getPrettyVersion('contao/core-bundle')
            : null;

        return $this->createResponse([
            'system_id' => $this->systemId,
            'contao_version' => $contaoVersion,
            'php_version' => PHP_VERSION,
            'app_environment' => $this->environment,
            'generated_at' => gmdate('c'),
        ]);
    }

    private function createResponse(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}