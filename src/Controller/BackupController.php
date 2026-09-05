<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use JsonException;
use Lebensbaum\ContaoSystemInfoBundle\Backup\BackupService;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\RequestAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BackupController
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly RequestAuthenticator $requestAuthenticator,
        private readonly ActionRequestAuthenticator $actionRequestAuthenticator,
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $credentials = $this->credentialStore->getCredentials();
        } catch (Throwable) {
            return $this->createResponse(['error' => 'service_not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->actionRequestAuthenticator->isAuthorized($request, $credentials['secret'])) {
            return $this->createResponse([
                'error' => 'unauthorized',
                'server_time' => time(),
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload) || !isset($payload['request_id']) || !is_string($payload['request_id'])) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->backupService->create($credentials['system_id'], $payload['request_id']);
        } catch (Throwable $exception) {
            $this->logger->error('Domain Manager remote backup failed.', [
                'exception' => $exception,
                'system_id' => $credentials['system_id'],
            ]);

            return $this->createResponse(['error' => 'backup_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->createResponse($result, Response::HTTP_CREATED);
    }

    public function list(Request $request): JsonResponse
    {
        try {
            $credentials = $this->credentialStore->getCredentials();
        } catch (Throwable) {
            return $this->createResponse(['error' => 'service_not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->requestAuthenticator->isAuthorized($request, $credentials['secret'])) {
            return $this->createResponse([
                'error' => 'unauthorized',
                'server_time' => time(),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->createResponse([
            'system_id' => $credentials['system_id'],
            'api_version' => 1,
            'backups' => $this->backupService->list($credentials['system_id']),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function createResponse(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
