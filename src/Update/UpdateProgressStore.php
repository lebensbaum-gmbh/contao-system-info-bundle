<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

use JsonException;
use RuntimeException;

final class UpdateProgressStore
{
    private const MAX_MESSAGE_LENGTH = 1000;

    /** @var list<string> */
    private const PHASES = [
        'preflight',
        'composer',
        'verify',
        'migration',
        'completed',
        'error',
    ];

    /** @var list<string> */
    private const STATUSES = [
        'pending',
        'running',
        'success',
        'error',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @param array<string, mixed>|null $result
     * @return array{request_id:string,phase:string,status:string,message:string,updated_at:int,result?:array<string,mixed>}
     */
    public function write(
        string $requestId,
        string $phase,
        string $status,
        string $message,
        ?int $updatedAt = null,
        ?array $result = null,
    ): array {
        $requestId = $this->requestId($requestId);
        $phase = $this->phase($phase);
        $status = $this->status($status);
        $message = $this->message($message);
        $updatedAt ??= time();

        if ($updatedAt < 1) {
            throw new RuntimeException('Der Zeitstempel des Update-Fortschritts ist ungültig.');
        }

        $state = [
            'request_id' => $requestId,
            'phase' => $phase,
            'status' => $status,
            'message' => $message,
            'updated_at' => $updatedAt,
        ];

        if (null !== $result) {
            $state['result'] = $result;
        }

        try {
            $json = json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Der Update-Fortschritt konnte nicht serialisiert werden.', 0, $exception);
        }

        $directory = $this->directory();
        $this->ensureDirectory($directory);
        $path = $this->path($requestId);
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(6));

        if (false === @file_put_contents($temporaryPath, $json, LOCK_EX)) {
            throw new RuntimeException('Der Update-Fortschritt konnte nicht gespeichert werden.');
        }

        @chmod($temporaryPath, 0600);

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Der Update-Fortschritt konnte nicht atomar gespeichert werden.');
        }

        @chmod($path, 0600);

        return $state;
    }

    /**
     * @return array{request_id:string,phase:string,status:string,message:string,updated_at:int,result?:array<string,mixed>}|null
     */
    public function read(string $requestId): ?array
    {
        $requestId = $this->requestId($requestId);
        $path = $this->path($requestId);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (false === $contents || '' === trim($contents)) {
            throw new RuntimeException('Der gespeicherte Update-Fortschritt konnte nicht gelesen werden.');
        }

        try {
            $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Der gespeicherte Update-Fortschritt enthält kein gültiges JSON.', 0, $exception);
        }

        if (!is_array($state)) {
            throw new RuntimeException('Der gespeicherte Update-Fortschritt hat ein ungültiges Format.');
        }

        $storedRequestId = $this->requestId($state['request_id'] ?? null);
        $phase = $this->phase($state['phase'] ?? null);
        $status = $this->status($state['status'] ?? null);
        $message = $this->message($state['message'] ?? null);
        $updatedAt = is_int($state['updated_at'] ?? null) ? $state['updated_at'] : 0;

        if (!hash_equals($requestId, $storedRequestId) || $updatedAt < 1) {
            throw new RuntimeException('Der gespeicherte Update-Fortschritt ist inkonsistent.');
        }

        $progress = [
            'request_id' => $storedRequestId,
            'phase' => $phase,
            'status' => $status,
            'message' => $message,
            'updated_at' => $updatedAt,
        ];

        if (array_key_exists('result', $state)) {
            if (!is_array($state['result'])) {
                throw new RuntimeException('Das gespeicherte Update-Ergebnis hat ein ungültiges Format.');
            }

            $progress['result'] = $state['result'];
        }

        return $progress;
    }

    public function remove(string $requestId): void
    {
        $path = $this->path($this->requestId($requestId));

        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Der gespeicherte Update-Fortschritt konnte nicht entfernt werden.');
        }
    }

    private function directory(): string
    {
        return $this->projectDir.'/var/domain-manager/update-progress';
    }

    private function path(string $requestId): string
    {
        return $this->directory().'/'.$requestId.'.json';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Das Verzeichnis für den Update-Fortschritt konnte nicht angelegt werden.');
            }
        }

        @chmod($directory, 0700);
    }

    private function requestId(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $value)) {
            throw new RuntimeException('Die Request-ID des Update-Fortschritts ist ungültig.');
        }

        return $value;
    }

    private function phase(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, self::PHASES, true)) {
            throw new RuntimeException('Die Phase des Update-Fortschritts ist ungültig.');
        }

        return $value;
    }

    private function status(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, self::STATUSES, true)) {
            throw new RuntimeException('Der Status des Update-Fortschritts ist ungültig.');
        }

        return $value;
    }

    private function message(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        if ('' === $value) {
            throw new RuntimeException('Die Meldung des Update-Fortschritts darf nicht leer sein.');
        }

        return mb_strlen($value) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($value, 0, self::MAX_MESSAGE_LENGTH).'…'
            : $value;
    }
}
