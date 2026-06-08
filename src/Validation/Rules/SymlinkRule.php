<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class SymlinkRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Symlink Configuration';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $symlinks = $config['symlinks'] ?? [];
        if (empty($symlinks)) return null;

        foreach ($symlinks as $index => $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                return [
                    'status' => 'error',
                    'message' => "Invalid symlink pair at index $index.",
                    'suggestion' => 'Symlinks must be an array of pairs, e.g., [["src", "dest"]]'
                ];
            }

            [$src, $dest] = $pair;
            
            if (empty($src) || empty($dest)) {
                 return [
                    'status' => 'error',
                    'message' => "Symlink src or dest is empty at index $index.",
                    'suggestion' => 'Provide non-empty strings for both source and destination.'
                ];
            }

            // Note: We don't check if $src exists here because it might be created 
            // during the 'update' task before 'symlink' task runs.
        }

        return null;
    }
}
