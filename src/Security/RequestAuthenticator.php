<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Security;

use Symfony\Component\HttpFoundation\Request;

final class RequestAuthenticator
{
    private const MAX_TIME_DIFFERENCE = 300;

    public function isAuthorized(Request $request, string $sharedSecret): bool
    {
        $timestamp = $request->headers->get('X-Domain-Manager-Timestamp');
        $signature = $request->headers->get('X-Domain-Manager-Signature');

        if (
            !is_string($timestamp)
            || !ctype_digit($timestamp)
            || !is_string($signature)
        ) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIME_DIFFERENCE) {
            return false;
        }

        $signedContent = implode("\n", [
            $timestamp,
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
        ]);

        $expectedSignature = hash_hmac('sha256', $signedContent, $sharedSecret);

        return hash_equals($expectedSignature, strtolower($signature));
    }
}
