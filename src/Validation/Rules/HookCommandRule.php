<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class HookCommandRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Hook Commands';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $hooks = $config['hooks'] ?? [];
        if (empty($hooks)) return null;

        foreach ($hooks as $name => $cmd) {
            if (!is_string($cmd) || trim($cmd) === '') {
                return [
                    'status' => 'error',
                    'message' => "Hook '$name' has an invalid or empty command.",
                    'suggestion' => "Ensure all hooks are valid shell command strings."
                ];
            }

            // Check for suspicious characters that might indicate a typo or dangerous input
            if (preg_match('/[<>|;&]/', $cmd) && !str_contains($cmd, '2>&1')) {
                 return [
                    'status' => 'warning',
                    'message' => "Hook '$name' contains special shell characters: $cmd",
                    'suggestion' => "Verify that the command logic is intentional."
                ];
            }
        }

        return null;
    }
}
