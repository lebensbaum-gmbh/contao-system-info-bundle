<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use JsonException;
use Lebensbaum\ContaoSystemInfoBundle\Backup\RestoreService;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobStore;
use Lebensbaum\ContaoSystemInfoBundle\Maintenance\MaintenanceJobLauncher;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RestoreController
{
    private const MAX_ERROR_DETAIL_LENGTH = 800;

    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly ActionRequestAuthenticator $actionRequestAuthenticator,
        private readonly RestoreService $restoreService,
        private readonly LoggerInterface $logger,
        private readonly MaintenanceJobStore $jobStore,
        private readonly MaintenanceJobLauncher $jobLauncher,
    ) {
    }

    public function restore(Request $request): JsonResponse
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

        if (
            !is_array($payload)
            || !isset($payload['request_id'], $payload['backup_id'])
            || !is_string($payload['request_id'])
            || !is_string($payload['backup_id'])
        ) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        $requestId = strtolower(trim($payload['request_id']));
        $backupId = strtolower(trim($payload['backup_id']));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)
            || 1 !== preg_match('/\A[a-f0-9]{32}\z/', $backupId)
        ) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->jobStore->create($requestId, $credentials['system_id'], 'restore', [
                'backup_id' => $backupId,
            ]);
            $this->jobLauncher->launch($requestId);
        } catch (Throwable $exception) {
            $this->logger->error('Domain Manager restore worker could not be queued.', [
                'exception' => $exception,
                'request_id' => $requestId,
            ]);

            try {
                $this->jobStore->progress($requestId, 'error', 'error', $this->safeErrorDetail($exception));
            } catch (Throwable) {
            }

            return $this->createResponse([
                'error' => 'restore_failed',
                'detail' => $this->safeErrorDetail($exception),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->createResponse([
            'system_id' => $credentials['system_id'],
            'api_version' => 1,
            'accepted' => true,
            'request_id' => $requestId,
        ], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request): JsonResponse
    {
        try {
            $credentials = $this->credentialStore->getCredentials();
        } catch (Throwable) {
            return $this->createResponse(['error' => 'service_not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->actionRequestAuthenticator->isAuthorized($request, $credentials['secret'])) {
            return $this->createResponse(['error' => 'unauthorized', 'server_time' => time()], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null)
            ? strtolower(trim($payload['request_id']))
            : '';

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $requestId)) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $progress = $this->jobStore->publicStatus($requestId, $credentials['system_id'], 'restore');
        } catch (Throwable $exception) {
            return $this->createResponse([
                'error' => 'restore_status_failed',
                'detail' => $this->safeErrorDetail($exception),
            ], Response::HTTP_CONFLICT);
        }

        return $this->createResponse([
            'system_id' => $credentials['system_id'],
            'api_version' => 1,
            'restore_progress' => $progress,
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

    private function safeErrorDetail(Throwable $exception): string
    {
        $detail = trim($exception->getMessage());

        if ('' === $detail) {
            return 'Unbekannter Fehler bei der Wiederherstellung.';
        }

        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        return mb_strlen($detail) > self::MAX_ERROR_DETAIL_LENGTH
            ? mb_substr($detail, 0, self::MAX_ERROR_DETAIL_LENGTH).'…'
            : $detail;
    }
}
