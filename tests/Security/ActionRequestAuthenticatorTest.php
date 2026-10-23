<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Security;

use Lebensbaum\ContaoSystemInfoBundle\Security\ActionNonceStore;
use Lebensbaum\ContaoSystemInfoBundle\Security\ActionRequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ActionRequestAuthenticatorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dm-action-auth-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testValidSignedRequestIsAcceptedOnlyOnce(): void
    {
        $secret = str_repeat('a', 64);
        $timestamp = (string) time();
        $nonce = str_repeat('b', 32);
        $body = '{"request_id":"'.str_repeat('c', 32).'"}';
        $path = '/_domainverwaltung/actions/backup';
        $signature = $this->signature($secret, $timestamp, $nonce, 'POST', $path, $body);
        $request = Request::create($path, 'POST', [], [], [], [
            'HTTP_X_DOMAIN_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_DOMAIN_MANAGER_NONCE' => $nonce,
            'HTTP_X_DOMAIN_MANAGER_SIGNATURE' => $signature,
        ], $body);
        $authenticator = new ActionRequestAuthenticator(new ActionNonceStore($this->projectDir));

        self::assertTrue($authenticator->isAuthorized($request, $secret));
        self::assertFalse($authenticator->isAuthorized($request, $secret));
    }

    public function testModifiedBodyIsRejected(): void
    {
        $secret = str_repeat('a', 64);
        $timestamp = (string) time();
        $nonce = str_repeat('b', 32);
        $path = '/_domainverwaltung/actions/backup';
        $signature = $this->signature($secret, $timestamp, $nonce, 'POST', $path, '{"request_id":"one"}');
        $request = Request::create($path, 'POST', [], [], [], [
            'HTTP_X_DOMAIN_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_DOMAIN_MANAGER_NONCE' => $nonce,
            'HTTP_X_DOMAIN_MANAGER_SIGNATURE' => $signature,
        ], '{"request_id":"two"}');
        $authenticator = new ActionRequestAuthenticator(new ActionNonceStore($this->projectDir));

        self::assertFalse($authenticator->isAuthorized($request, $secret));
    }

    public function testStaleRequestIsRejected(): void
    {
        $secret = str_repeat('a', 64);
        $timestamp = (string) (time() - 301);
        $nonce = str_repeat('b', 32);
        $body = '{}';
        $path = '/_domainverwaltung/actions/backup';
        $signature = $this->signature($secret, $timestamp, $nonce, 'POST', $path, $body);
        $request = Request::create($path, 'POST', [], [], [], [
            'HTTP_X_DOMAIN_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_DOMAIN_MANAGER_NONCE' => $nonce,
            'HTTP_X_DOMAIN_MANAGER_SIGNATURE' => $signature,
        ], $body);
        $authenticator = new ActionRequestAuthenticator(new ActionNonceStore($this->projectDir));

        self::assertFalse($authenticator->isAuthorized($request, $secret));
    }

    private function signature(
        string $secret,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $body,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            $timestamp,
            strtolower($nonce),
            strtoupper($method),
            $path,
            hash('sha256', $body),
        ]), $secret);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
