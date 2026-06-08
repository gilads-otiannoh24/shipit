<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class RequiredConfigRule implements ValidationRuleInterface
{
    private array $requiredFields = [
        'gitRepoUrl' => 'The Git repository URL is required for cloning.',
        'backup_path' => 'A backup path is required to safely store versions before updating.',
    ];

    public function getName(): string
    {
        return 'Required Config';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        foreach ($this->requiredFields as $field => $message) {
            if (empty($config[$field])) {
                return [
                    'status' => 'error',
                    'message' => "Missing required field: $field",
                    'suggestion' => $message
                ];
            }
        }

        return null;
    }
}
