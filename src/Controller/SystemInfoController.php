<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\RequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\SystemInfo\SystemInfoProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SystemInfoController
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly RequestAuthenticator $requestAuthenticator,
        private readonly SystemInfoProvider $systemInfoProvider,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $credentials = $this->credentialStore->getCredentials();
        } catch (Throwable) {
            return $this->createResponse(
                [
                    'error' => 'service_not_configured',
                    'server_time' => time(),
                ],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $systemId = $credentials['system_id'];
        $sharedSecret = $credentials['secret'];

        if (!$this->requestAuthenticator->isAuthorized($request, $sharedSecret)) {
            return $this->createResponse(
                [
                    'error' => 'unauthorized',
                    'server_time' => time(),
                ],
                Response::HTTP_UNAUTHORIZED
            );
        }

        try {
            $data = $this->systemInfoProvider->collect($request, $systemId);
        } catch (Throwable) {
            return $this->createResponse(
                [
                    'error' => 'system_info_unavailable',
                    'server_time' => time(),
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->createResponse($data);
    }

    private function createResponse(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
