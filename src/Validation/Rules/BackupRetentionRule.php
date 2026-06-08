<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class BackupRetentionRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Backup Retention';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $retention = $config['backup_retention'] ?? null;
        
        if ($retention === null) return null;

        if (!is_int($retention)) {
            return [
                'status' => 'error',
                'message' => "backup_retention must be an integer.",
                'suggestion' => "Set an integer value (e.g., 5) in config.json"
            ];
        }

        if ($retention <= 0) {
            return [
                'status' => 'warning',
                'message' => "backup_retention is set to $retention.",
                'suggestion' => "This will disable backup rotation. Set to > 0 to keep history."
            ];
        }

        if ($retention > 50) {
            return [
                'status' => 'info',
                'message' => "backup_retention is quite high ($retention).",
                'suggestion' => "High retention counts can consume significant disk space."
            ];
        }

        return null;
    }
}
