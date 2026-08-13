<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Security;

use Doctrine\DBAL\Connection;
use Lebensbaum\ContaoSystemInfoBundle\Exception\CredentialsUnavailableException;

final class CredentialStore
{
    private const TABLE = 'tl_system_info_settings';

    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $secretCipher,
        private readonly string $legacySystemId = '',
        private readonly string $legacySharedSecret = '',
    ) {
    }

    /**
     * @return array{system_id:string,secret:string,secret_changed_at:int}
     */
    public function getCredentials(): array
    {
        if (!$this->tableExists()) {
            if ($this->legacyCredentialsAreValid()) {
                return [
                    'system_id' => trim($this->legacySystemId),
                    'secret' => trim($this->legacySharedSecret),
                    'secret_changed_at' => 0,
                ];
            }

            throw new CredentialsUnavailableException(
                'Die System-Info-Datenbanktabelle fehlt. Bitte zuerst die Datenbankmigration ausführen.'
            );
        }

        $record = $this->getOrCreateRecord();
        $secret = $this->secretCipher->decrypt((string) $record['encrypted_secret']);
        $this->assertSystemId((string) $record['system_id']);
        $this->assertSecret($secret);

        return [
            'system_id' => (string) $record['system_id'],
            'secret' => $secret,
            'secret_changed_at' => (int) $record['secret_changed_at'],
        ];
    }

    /**
     * @return array{system_id:string,secret_changed_at:int,reveal_pending:bool}
     */
    public function getMetadata(): array
    {
        if (!$this->tableExists()) {
            throw new CredentialsUnavailableException(
                'Die System-Info-Datenbanktabelle fehlt. Bitte zuerst die Datenbankmigration ausführen.'
            );
        }

        $record = $this->getOrCreateRecord();
        $this->assertSystemId((string) $record['system_id']);

        return [
            'system_id' => (string) $record['system_id'],
            'secret_changed_at' => (int) $record['secret_changed_at'],
            'reveal_pending' => '1' === (string) $record['secret_reveal_pending'],
        ];
    }

    /**
     * Return the secret while it is explicitly marked for one-time setup display.
     * The flag is not consumed during rendering, so Contao may render the back end
     * module more than once without accidentally hiding the secret.
     */
    public function getPendingReveal(): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }

        $record = $this->getOrCreateRecord();

        if ('1' !== (string) $record['secret_reveal_pending']) {
            return null;
        }

        $secret = $this->secretCipher->decrypt((string) $record['encrypted_secret']);
        $this->assertSecret($secret);

        return $secret;
    }

    public function hidePendingReveal(): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $record = $this->getOrCreateRecord();

        $this->connection->update(
            self::TABLE,
            [
                'tstamp' => time(),
                'secret_reveal_pending' => '',
            ],
            ['id' => (int) $record['id']]
        );
    }

    public function rotateSecret(): string
    {
        if (!$this->tableExists()) {
            throw new CredentialsUnavailableException(
                'Die System-Info-Datenbanktabelle fehlt. Bitte zuerst die Datenbankmigration ausführen.'
            );
        }

        $record = $this->getOrCreateRecord();
        $secret = $this->generateSecret();
        $timestamp = time();

        $this->connection->update(
            self::TABLE,
            [
                'tstamp' => $timestamp,
                'encrypted_secret' => $this->secretCipher->encrypt($secret),
                'secret_changed_at' => $timestamp,
                'secret_reveal_pending' => '1',
            ],
            ['id' => (int) $record['id']]
        );

        return $secret;
    }

    private function tableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([self::TABLE]);
    }

    /**
     * @return array{id:int,system_id:string,encrypted_secret:string,secret_changed_at:int,secret_reveal_pending:string}
     */
    private function getOrCreateRecord(): array
    {
        $record = $this->connection->fetchAssociative(
            'SELECT id, system_id, encrypted_secret, secret_changed_at, secret_reveal_pending FROM '.self::TABLE.' ORDER BY id LIMIT 1'
        );

        if (false !== $record) {
            return [
                'id' => (int) $record['id'],
                'system_id' => (string) $record['system_id'],
                'encrypted_secret' => (string) $record['encrypted_secret'],
                'secret_changed_at' => (int) $record['secret_changed_at'],
                'secret_reveal_pending' => (string) $record['secret_reveal_pending'],
            ];
        }

        if ($this->legacyCredentialsAreValid()) {
            // Existing v1.0.0 credentials are imported without revealing the
            // already established secret in clear text.
            $systemId = trim($this->legacySystemId);
            $secret = trim($this->legacySharedSecret);
            $revealPending = '';
        } else {
            // Fresh installation: generate both credentials and keep the new
            // secret visible until the administrator explicitly hides it.
            $systemId = $this->generateSystemId();
            $secret = $this->generateSecret();
            $revealPending = '1';
        }

        $timestamp = time();
        $encryptedSecret = $this->secretCipher->encrypt($secret);

        $this->connection->insert(self::TABLE, [
            'tstamp' => $timestamp,
            'system_id' => $systemId,
            'encrypted_secret' => $encryptedSecret,
            'secret_changed_at' => $timestamp,
            'secret_reveal_pending' => $revealPending,
        ]);

        $id = (int) $this->connection->fetchOne(
            'SELECT id FROM '.self::TABLE.' WHERE system_id = ? ORDER BY id LIMIT 1',
            [$systemId]
        );

        return [
            'id' => $id,
            'system_id' => $systemId,
            'encrypted_secret' => $encryptedSecret,
            'secret_changed_at' => $timestamp,
            'secret_reveal_pending' => $revealPending,
        ];
    }

    private function legacyCredentialsAreValid(): bool
    {
        return 1 === preg_match('/\A[a-f0-9]{32}\z/i', trim($this->legacySystemId))
            && 1 === preg_match('/\A[a-f0-9]{64}\z/i', trim($this->legacySharedSecret));
    }

    private function generateSystemId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function assertSystemId(string $systemId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/i', $systemId)) {
            throw new CredentialsUnavailableException('Die gespeicherte Installations-ID ist ungültig.');
        }
    }

    private function assertSecret(string $secret): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/i', $secret)) {
            throw new CredentialsUnavailableException('Das gespeicherte System-Info-Secret ist ungültig.');
        }
    }
}
