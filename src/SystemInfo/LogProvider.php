<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\SystemInfo;

final class LogProvider
{
    private const MAX_FILES = 3;
    private const MAX_BYTES_PER_FILE = 1048576;
    private const MAX_ENTRIES = 100;
    private const MAX_AGE = 86400;
    private const LEVELS = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return array{system_id:string,generated_at:int,entries:list<array<string,mixed>>} */
    public function collect(string $systemId): array
    {
        return [
            'system_id' => $systemId,
            'generated_at' => time(),
            'entries' => $this->readEntries(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function readEntries(): array
    {
        $files = glob($this->projectDir.'/var/logs/prod*.log') ?: [];
        usort($files, static fn (string $a, string $b): int => ((int) @filemtime($b)) <=> ((int) @filemtime($a)));
        $files = array_slice($files, 0, self::MAX_FILES);
        $cutoff = time() - self::MAX_AGE;
        $entries = [];

        foreach ($files as $file) {
            foreach ($this->tailLines($file) as $line) {
                $entry = $this->parseLine($line, $cutoff);

                if (null !== $entry) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => ((int) $b['timestamp']) <=> ((int) $a['timestamp']));

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /** @return list<string> */
    private function tailLines(string $file): array
    {
        $size = @filesize($file);

        if (false === $size || $size < 1) {
            return [];
        }

        $length = min($size, self::MAX_BYTES_PER_FILE);
        $handle = @fopen($file, 'rb');

        if (false === $handle) {
            return [];
        }

        try {
            if ($length < $size) {
                fseek($handle, -$length, SEEK_END);
            }

            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (!is_string($content) || '' === $content) {
            return [];
        }

        if ($length < $size) {
            $firstNewline = strpos($content, "\n");
            $content = false === $firstNewline ? '' : substr($content, $firstNewline + 1);
        }

        return preg_split('/\R/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array<string,mixed>|null */
    private function parseLine(string $line, int $cutoff): ?array
    {
        if (1 !== preg_match('/^\[(?<date>[^\]]+)\]\s+(?<channel>[^.\s]+)\.(?<level>[A-Z]+):\s+(?<rest>.*)$/', $line, $matches)) {
            return null;
        }

        $level = strtoupper((string) $matches['level']);

        if (!in_array($level, self::LEVELS, true)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable((string) $matches['date']);
            $timestamp = $date->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        if ($timestamp < $cutoff) {
            return null;
        }

        $rest = trim((string) $matches['rest']);
        $contextOffset = strpos($rest, ' {"');
        $message = trim(false === $contextOffset ? $rest : substr($rest, 0, $contextOffset));
        $message = $this->truncate($message, 600);
        $requestUri = $this->extractJsonString($rest, 'request_uri');
        $exceptionClass = '';

        if (1 === preg_match('/"exception":"\[object\] \(([^(:]+)(?:\(|:)/', $rest, $exceptionMatch)) {
            $exceptionClass = trim((string) $exceptionMatch[1]);
        }

        $fingerprintSource = implode('|', [
            $level,
            $exceptionClass,
            $this->normalizeForFingerprint($message),
        ]);

        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'channel' => (string) $matches['channel'],
            'message' => $message,
            'request_uri' => $this->truncate($requestUri, 500),
            'exception_class' => $this->truncate($exceptionClass, 255),
            'fingerprint' => hash('sha256', $fingerprintSource),
        ];
    }

    private function extractJsonString(string $line, string $key): string
    {
        if (1 !== preg_match('/"'.preg_quote($key, '/').'":"((?:\\\\.|[^"\\\\])*)"/', $line, $matches)) {
            return '';
        }

        $decoded = json_decode('"'.$matches[1].'"', true);

        return is_string($decoded) ? $decoded : '';
    }

    private function normalizeForFingerprint(string $message): string
    {
        $message = preg_replace('~/(?:[^\s/]+/){2,}[^\s:]+~', '/…/path', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return trim($message);
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim($value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
