<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

final class ComposerDryRunParser
{
    /**
     * @return array{
     *     summary: array{installs: int, updates: int, removals: int},
     *     operations: list<array{type: string, package: string, from: string, to: string}>
     * }
     */
    public function parse(string $output): array
    {
        $summary = [
            'installs' => 0,
            'updates' => 0,
            'removals' => 0,
        ];

        if (preg_match('/Lock file operations:\s*(\d+) installs?,\s*(\d+) updates?,\s*(\d+) removals?/i', $output, $match)) {
            $summary = [
                'installs' => (int) $match[1],
                'updates' => (int) $match[2],
                'removals' => (int) $match[3],
            ];
        }

        $operations = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^-\s+(?:Upgrading|Downgrading)\s+([a-z0-9_.-]+\/[a-z0-9_.-]+)\s+\((.+?)\s+=>\s+(.+?)\)$/i', $line, $match)) {
                $operations[] = [
                    'type' => 'update',
                    'package' => strtolower($match[1]),
                    'from' => trim($match[2]),
                    'to' => trim($match[3]),
                ];

                continue;
            }

            if (preg_match('/^-\s+Locking\s+([a-z0-9_.-]+\/[a-z0-9_.-]+)\s+\((.+?)\)$/i', $line, $match)) {
                $operations[] = [
                    'type' => 'install',
                    'package' => strtolower($match[1]),
                    'from' => '',
                    'to' => trim($match[2]),
                ];

                continue;
            }

            if (preg_match('/^-\s+Removing\s+([a-z0-9_.-]+\/[a-z0-9_.-]+)\s+\((.+?)\)$/i', $line, $match)) {
                $operations[] = [
                    'type' => 'remove',
                    'package' => strtolower($match[1]),
                    'from' => trim($match[2]),
                    'to' => '',
                ];
            }
        }

        return [
            'summary' => $summary,
            'operations' => $operations,
        ];
    }

    /** @param list<array{type: string, package: string, from: string, to: string}> $operations */
    public function targetContaoVersion(array $operations, string $currentVersion): string
    {
        foreach (['contao/core-bundle', 'contao/manager-bundle'] as $package) {
            foreach ($operations as $operation) {
                if ('update' === $operation['type'] && $package === $operation['package']) {
                    return $this->versionToken($operation['to']);
                }
            }
        }

        return $currentVersion;
    }

    private function versionToken(string $value): string
    {
        $value = trim($value);

        if (preg_match('/\A(v?\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?)/', $value, $match)) {
            return $match[1];
        }

        return $value;
    }
}
