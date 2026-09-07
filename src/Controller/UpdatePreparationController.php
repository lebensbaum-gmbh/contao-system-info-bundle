<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use JsonException;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdatePreparationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class UpdatePreparationController
{
    private const MAX_ERROR_DETAIL_LENGTH = 800;

    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly ActionRequestAuthenticator $actionRequestAuthenticator,
        private readonly UpdatePreparationService $updatePreparationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepare(Request $request): JsonResponse
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
            $result = $this->updatePreparationService->prepare(
                $credentials['system_id'],
                $payload['request_id']
            );
        } catch (Throwable $exception) {
            $this->logger->error('Domain Manager update preparation failed.', [
                'exception' => $exception,
                'system_id' => $credentials['system_id'],
            ]);

            return $this->createResponse([
                'error' => 'update_preparation_failed',
                'detail' => $this->safeErrorDetail($exception),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->createResponse($result, Response::HTTP_OK);
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
            return 'Unbekannter Fehler bei der Update-Vorbereitung.';
        }

        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        return mb_strlen($detail) > self::MAX_ERROR_DETAIL_LENGTH
            ? mb_substr($detail, 0, self::MAX_ERROR_DETAIL_LENGTH).'…'
            : $detail;
    }
}
