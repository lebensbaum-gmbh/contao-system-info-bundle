<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Security;

use Lebensbaum\ContaoSystemInfoBundle\Security\RequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestAuthenticatorTest extends TestCase
{
    private const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PATH = '/_domainverwaltung/systeminfo';

    public function testAcceptsValidSignature(): void
    {
        $timestamp = (string) time();
        $request = $this->createSignedRequest($timestamp, self::SECRET);

        self::assertTrue((new RequestAuthenticator())->isAuthorized($request, self::SECRET));
    }

    public function testRejectsInvalidSignature(): void
    {
        $timestamp = (string) time();
        $request = $this->createSignedRequest($timestamp, str_repeat('b', 64));

        self::assertFalse((new RequestAuthenticator())->isAuthorized($request, self::SECRET));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $timestamp = (string) (time() - 301);
        $request = $this->createSignedRequest($timestamp, self::SECRET);

        self::assertFalse((new RequestAuthenticator())->isAuthorized($request, self::SECRET));
    }

    public function testRejectsMissingHeaders(): void
    {
        $request = Request::create(self::PATH, 'GET');

        self::assertFalse((new RequestAuthenticator())->isAuthorized($request, self::SECRET));
    }

    private function createSignedRequest(string $timestamp, string $signingSecret): Request
    {
        $signedContent = implode("\n", [$timestamp, 'GET', self::PATH]);
        $signature = hash_hmac('sha256', $signedContent, $signingSecret);

        return Request::create(
            self::PATH,
            'GET',
            [],
            [],
            [],
            [
                'HTTP_X_DOMAIN_MANAGER_TIMESTAMP' => $timestamp,
                'HTTP_X_DOMAIN_MANAGER_SIGNATURE' => $signature,
            ]
        );
    }
}
