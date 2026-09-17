<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Controller;

use JsonException;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationFinalizer;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateInstallationService;
use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class UpdateInstallationController
{
    private const MAX_ERROR_DETAIL_LENGTH = 1000;

    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly ActionRequestAuthenticator $actionRequestAuthenticator,
        private readonly UpdateInstallationService $updateInstallationService,
        private readonly UpdateInstallationFinalizer $updateInstallationFinalizer,
        private readonly UpdateProgressStore $progressStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function install(Request $request): JsonResponse
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

        if (!is_array($payload)) {
            return $this->createResponse(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }

        $requestId = isset($payload['request_id']) && is_string($payload['request_id'])
            ? strtolower(trim($payload['request_id']))
            : '';
        $hasValidRequestId = 1 === preg_match('/\A[a-f0-9]{32}\z/', $requestId);

        try {
            $result = $this->updateInstallationService->install(
                $credentials['system_id'],
                $payload
            );

            if (!isset($result['update_installation']) || !is_array($result['update_installation'])) {
                throw new \RuntimeException('Die Update-Installation hat nach dem Composer-Lauf keinen gültigen Ergebnisstatus geliefert.');
            }

            if ($hasValidRequestId) {
                $this->writeProgress($requestId, 'migration', 'running', 'Datenbankmigration wird ausgeführt.');
            }

            $migration = $this->updateInstallationFinalizer->migrate();

            if ($hasValidRequestId) {
                $this->writeProgress($requestId, 'migration', 'success', 'Datenbankmigration erfolgreich abgeschlossen.');
            }

            $result['update_installation']['database_migrated'] = true;
            $result['update_installation']['php_cli_version'] = $migration['php_cli_version'];
            $result['update_installation']['completed_at'] = $migration['completed_at'];

            if ($hasValidRequestId) {
                $this->writeProgress(
                    $requestId,
                    'completed',
                    'success',
                    'Update erfolgreich abgeschlossen.',
                    $result
                );
            }
        } catch (Throwable $exception) {
            if ($hasValidRequestId) {
                $this->writeProgress(
                    $requestId,
                    'error',
                    'error',
                    'Update fehlgeschlagen: '.$this->safeErrorDetail($exception)
                );
            }

            $this->logger->error('Domain Manager update installation failed.', [
                'exception' => $exception,
                'system_id' => $credentials['system_id'],
                'request_id' => $hasValidRequestId ? $requestId : null,
            ]);

            return $this->createResponse([
                'error' => 'update_installation_failed',
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

    /** @param array<string, mixed>|null $result */
    private function writeProgress(
        string $requestId,
        string $phase,
        string $status,
        string $message,
        ?array $result = null,
    ): void {
        try {
            $this->progressStore->write($requestId, $phase, $status, $message, null, $result);
        } catch (Throwable $exception) {
            $this->logger->warning('Domain Manager update progress could not be written.', [
                'exception' => $exception,
                'request_id' => $requestId,
                'phase' => $phase,
                'status' => $status,
            ]);
        }
    }

    private function safeErrorDetail(Throwable $exception): string
    {
        $detail = trim($exception->getMessage());

        if ('' === $detail) {
            return 'Unbekannter Fehler bei der Update-Installation.';
        }

        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        return mb_strlen($detail) > self::MAX_ERROR_DETAIL_LENGTH
            ? mb_substr($detail, 0, self::MAX_ERROR_DETAIL_LENGTH).'…'
            : $detail;
    }
}
