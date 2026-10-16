<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Security;

use Symfony\Component\HttpFoundation\Request;

final class ActionRequestAuthenticator
{
    private const MAX_TIME_DIFFERENCE = 300;

    public function __construct(private readonly ActionNonceStore $nonceStore)
    {
    }

    public function isAuthorized(Request $request, string $sharedSecret): bool
    {
        $timestamp = $request->headers->get('X-Domain-Manager-Timestamp');
        $nonce = $request->headers->get('X-Domain-Manager-Nonce');
        $signature = $request->headers->get('X-Domain-Manager-Signature');

        if (
            !is_string($timestamp)
            || !ctype_digit($timestamp)
            || !is_string($nonce)
            || 1 !== preg_match('/\A[a-f0-9]{32}\z/i', $nonce)
            || !is_string($signature)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/i', $signature)
        ) {
            return false;
        }

        $timestampValue = (int) $timestamp;

        if (abs(time() - $timestampValue) > self::MAX_TIME_DIFFERENCE) {
            return false;
        }

        $bodyHash = hash('sha256', $request->getContent());
        $signedContent = implode("\n", [
            $timestamp,
            strtolower($nonce),
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $bodyHash,
        ]);
        $expectedSignature = hash_hmac('sha256', $signedContent, $sharedSecret);

        if (!hash_equals($expectedSignature, strtolower($signature))) {
            return false;
        }

        return $this->nonceStore->consume($nonce, $timestampValue);
    }
}
