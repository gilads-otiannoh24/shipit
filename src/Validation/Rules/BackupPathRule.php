<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class BackupPathRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Backup Path Writable';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $path = $config['backup_path'] ?? '';
        if (empty($path)) return null;

        if (is_dir($path)) {
            if (!is_writable($path)) {
                return [
                    'status' => 'error',
                    'message' => "Backup path exists but is NOT writable: $path",
                    'suggestion' => "Run: chmod -R 775 " . escapeshellarg($path)
                ];
            }
        } else {
            // Check if parent is writable so we can create it
            $parent = dirname($path);
            if (!is_dir($parent) || !is_writable($parent)) {
                return [
                    'status' => 'error',
                    'message' => "Backup directory cannot be created. Parent is not writable: $parent",
                    'suggestion' => "Ensure the parent directory exists and is writable by the current user."
                ];
            }
        }

        return null;
    }
}
