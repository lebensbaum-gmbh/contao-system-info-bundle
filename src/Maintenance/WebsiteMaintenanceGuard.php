<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use RuntimeException;
use Throwable;

/** Uses Contao's pre-bootstrap maintenance page; never changes public/index.php. */
final class WebsiteMaintenanceGuard
{
    public const PAGE_MARKER = '<!-- maintend:website-maintenance:v1 -->';

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @template T @param callable():T $operation @return T */
    public function run(callable $operation, bool $keepPageOnFailure = false): mixed
    {
        $path = $this->projectDir.'/var/maintenance.html';
        $contents = self::PAGE_MARKER.'<!-- owner:'.bin2hex(random_bytes(16))." -->\n"
            .'<!DOCTYPE html><html lang="de"><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Wartung</title><body><h1>Die Website wird gerade gewartet.</h1>'
            .'<p>Bitte versuchen Sie es in wenigen Minuten erneut.</p></body></html>';
        $owner = $this->createPage($path, $contents);

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            if ($keepPageOnFailure) {
                // A failed package/database mutation may have left the website
                // incomplete. Keep it protected until the operator checks it.
                throw $exception;
            }

            try {
                $this->removePage($path, $contents, $owner);
            } catch (Throwable $cleanupException) {
                throw new RuntimeException(
                    $cleanupException->getMessage().' Vorheriger Fehler: '.$exception->getMessage(),
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        $this->removePage($path, $contents, $owner);

        return $result;
    }

    /** @return array<string|int,int> */
    private function createPage(string $path, string $contents): array
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Verzeichnis für die Wartungsseite konnte nicht angelegt werden.');
        }

        // Exclusive creation also rejects an existing file, directory or dangling symlink.
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new RuntimeException('Die Wartungsseite konnte nicht exklusiv angelegt werden. Eine vorhandene var/maintenance.html bleibt unverändert; der Vorgang wurde nicht gestartet.');
        }

        $owner = fstat($handle);
        try {
            if (false === $owner
                || strlen($contents) !== fwrite($handle, $contents)
                || !fflush($handle)
                || !@chmod($path, 0644)
            ) {
                throw new RuntimeException('Die Wartungsseite konnte nicht vollständig und lesbar geschrieben werden.');
            }
        } catch (Throwable $exception) {
            if (is_array($owner) && $this->isOwnedFile($path, $owner)) {
                @unlink($path);
            }
            throw $exception;
        } finally {
            fclose($handle);
        }

        return $owner;
    }

    /** @param array<string|int,int> $owner */
    private function removePage(string $path, string $contents, array $owner): void
    {
        clearstatcache(true, $path);
        if (false === @lstat($path)) {
            return;
        }

        if (!$this->isOwnedFile($path, $owner) || $contents !== @file_get_contents($path)) {
            throw new RuntimeException('Die Wartungsseite wurde zwischenzeitlich verändert und bleibt erhalten. Bitte var/maintenance.html und den Wartungsauftrag prüfen.');
        }

        if (!@unlink($path)) {
            throw new RuntimeException('Die eigene Wartungsseite konnte nicht entfernt werden. Bitte var/maintenance.html und den Wartungsauftrag prüfen.');
        }
    }

    /** @param array<string|int,int> $owner */
    private function isOwnedFile(string $path, array $owner): bool
    {
        clearstatcache(true, $path);
        $current = @lstat($path);

        return is_array($current)
            && ($current['mode'] & 0170000) === 0100000
            && $current['dev'] === $owner['dev']
            && $current['ino'] === $owner['ino'];
    }
}
