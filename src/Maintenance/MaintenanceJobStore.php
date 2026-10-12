<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use JsonException;
use RuntimeException;

final class MaintenanceJobStore
{
    private const OPERATIONS = ['update', 'restore', 'probe'];
    private const STATUSES = ['pending', 'running', 'success', 'error'];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * The global lock prevents concurrent update and restore jobs on this installation.
     * Pending jobs intentionally block new operations even if the worker fails to start:
     * operator intervention is safer than two concurrent Composer/DB changes.
     *
     * @param array<string,mixed> $payload
     */
    public function create(string $requestId, string $systemId, string $operation, array $payload): void
    {
        $requestId = $this->validId($requestId);
        $systemId = $this->validId($systemId);

        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new RuntimeException('Unbekannter Wartungsvorgang.');
        }

        $this->ensureDirectory();
        $handle = @fopen($this->directory().'/.queue.lock', 'c');

        if (false === $handle) {
            throw new RuntimeException('Die Wartungswarteschlange konnte nicht gesperrt werden.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Die Wartungswarteschlange konnte nicht exklusiv gesperrt werden.');
            }

            if (is_file($this->path($requestId))) {
                throw new RuntimeException('Für diese Anfrage existiert bereits ein Wartungsauftrag.');
            }

            foreach (glob($this->directory().'/*.json') ?: [] as $file) {
                $existing = $this->readFile($file);

                if (!in_array($existing['status'] ?? null, ['success', 'error'], true)) {
                    throw new RuntimeException('Ein Update oder eine Wiederherstellung läuft bereits oder hat einen ungeklärten Status. Weitere Wartungsarbeiten sind gesperrt.');
                }
            }

            $this->writeFile($requestId, [
                'request_id' => $requestId,
                'system_id' => $systemId,
                'operation' => $operation,
                'payload' => $payload,
                'status' => 'pending',
                'phase' => 'queued',
                'message' => 'Auftrag angenommen, CLI-Prozess wird gestartet.',
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Refuse HTTP backup/update preparation while a CLI mutation is active. */
    public function assertIdle(): void
    {
        $this->ensureDirectory();

        foreach (glob($this->directory().'/*.json') ?: [] as $file) {
            $job = $this->readFile($file);

            if (!in_array($job['status'] ?? null, ['success', 'error'], true)) {
                throw new RuntimeException('Ein Update oder eine Wiederherstellung läuft bereits. Diese Aktion ist derzeit gesperrt.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function read(string $requestId): array
    {
        $path = $this->path($this->validId($requestId));

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Der Wartungsauftrag wurde nicht gefunden.');
        }

        $data = $this->readFile($path);

        if (($data['request_id'] ?? null) !== $requestId) {
            throw new RuntimeException('Der Wartungsauftrag ist inkonsistent.');
        }

        return $data;
    }

    /** @param array<string,mixed>|null $result */
    public function progress(string $requestId, string $phase, string $status, string $message, ?array $result = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Ungültiger Wartungsstatus.');
        }

        $job = $this->read($requestId);

        if (in_array($job['status'] ?? '', ['success', 'error'], true)) {
            throw new RuntimeException('Ein abgeschlossener Wartungsauftrag darf nicht überschrieben werden.');
        }

        $job['phase'] = preg_replace('/[^a-z_]/', '', strtolower($phase)) ?: 'unknown';
        $job['status'] = $status;
        $job['message'] = mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 1000);
        $job['updated_at'] = time();

        if (null !== $result) {
            $job['result'] = $result;
        }

        $this->writeFile($requestId, $job);
    }

    /** @return array<string,mixed> */
    public function publicStatus(string $requestId, string $systemId, string $operation): array
    {
        $job = $this->read($requestId);

        if (!hash_equals($this->validId($systemId), (string) ($job['system_id'] ?? ''))
            || $operation !== ($job['operation'] ?? null)
        ) {
            throw new RuntimeException('Der Wartungsauftrag gehört nicht zur angefragten Installation oder Aktion.');
        }

        $response = [
            'request_id' => $requestId,
            'status' => $job['status'],
            'phase' => $job['phase'],
            'message' => $job['message'],
            'updated_at' => $job['updated_at'],
        ];

        if ('success' === $job['status'] && is_array($job['result'] ?? null)) {
            $response['result'] = $job['result'];
        }

        return $response;
    }

    private function validId(string $id): string
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new RuntimeException('Ungültige Wartungs-ID.');
        }

        return $id;
    }

    private function directory(): string
    {
        return $this->projectDir.'/var/domain-manager/maintenance-jobs';
    }

    private function path(string $requestId): string
    {
        return $this->directory().'/'.$requestId.'.json';
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Wartungsverzeichnis konnte nicht angelegt werden.');
        }

        @chmod($directory, 0700);
    }

    /** @return array<string,mixed> */
    private function readFile(string $path): array
    {
        $json = @file_get_contents($path);

        if (!is_string($json)) {
            throw new RuntimeException('Wartungsauftrag ist nicht lesbar.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Wartungsauftrag enthält ungültiges JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Wartungsauftrag hat ein ungültiges Format.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $job */
    private function writeFile(string $requestId, array $job): void
    {
        $this->ensureDirectory();
        $path = $this->path($this->validId($requestId));
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $json = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === @file_put_contents($temporary, $json, LOCK_EX)) {
            throw new RuntimeException('Der Wartungsstatus konnte nicht gespeichert werden.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Der Wartungsstatus konnte nicht atomar gespeichert werden.');
        }

        @chmod($path, 0600);
    }
}
