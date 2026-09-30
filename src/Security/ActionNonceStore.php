<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Security;

use RuntimeException;

final class ActionNonceStore
{
    private const MAX_AGE = 600;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function consume(string $nonce, int $timestamp): bool
    {
        $nonce = strtolower(trim($nonce));

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $nonce)) {
            return false;
        }

        $directory = $this->projectDir.'/var/domain-manager/action-nonces';
        $this->ensureDirectory($directory);
        $this->cleanup($directory);

        $path = $directory.'/'.hash('sha256', $nonce).'.nonce';
        $handle = @fopen($path, 'x');

        if (false === $handle) {
            return false;
        }

        try {
            if (false === fwrite($handle, (string) $timestamp)) {
                @unlink($path);

                return false;
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);

        return true;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Verzeichnis für Action-Nonces konnte nicht angelegt werden.');
        }

        @chmod($directory, 0700);
    }

    private function cleanup(string $directory): void
    {
        $threshold = time() - self::MAX_AGE;
        $entries = @scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || !str_ends_with($entry, '.nonce')) {
                continue;
            }

            $path = $directory.'/'.$entry;
            $modifiedAt = @filemtime($path);

            if (false !== $modifiedAt && $modifiedAt < $threshold) {
                @unlink($path);
            }
        }
    }
}
