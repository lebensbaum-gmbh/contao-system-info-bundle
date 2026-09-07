<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Update;

final class UpdatePolicy
{
    /** @return array{allowed: bool, reason: string} */
    public function evaluate(string $currentVersion, string $targetVersion): array
    {
        $currentBranch = $this->branch($currentVersion);
        $targetBranch = $this->branch($targetVersion);

        if (null === $currentBranch || null === $targetBranch) {
            return [
                'allowed' => false,
                'reason' => 'Der aktuelle oder vorgesehene Contao-Versionszweig konnte nicht eindeutig bestimmt werden.',
            ];
        }

        if ($currentBranch !== $targetBranch) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Die Vorbereitung würde den Contao-Zweig von %s auf %s wechseln. Erlaubt sind ausschließlich Bugfix-Updates innerhalb desselben Zweigs.',
                    $currentBranch,
                    $targetBranch
                ),
            ];
        }

        return [
            'allowed' => true,
            'reason' => '',
        ];
    }

    private function branch(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        if (!preg_match('/\A(\d+)\.(\d+)(?:\.|\z)/', $version, $match)) {
            return null;
        }

        return $match[1].'.'.$match[2];
    }
}
