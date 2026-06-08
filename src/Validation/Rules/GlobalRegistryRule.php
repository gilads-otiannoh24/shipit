<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class GlobalRegistryRule implements ValidationRuleInterface
{
    private string $globalConfigFile;

    public function __construct(string $globalConfigFile)
    {
        $this->globalConfigFile = $globalConfigFile;
    }

    public function getName(): string
    {
        return 'Global Registry';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        if (empty($this->globalConfigFile)) {
            return [
                'status' => 'warning',
                'message' => "Global config file path not determined.",
                'suggestion' => "Check SHIPIT_HOME or HOME environment variables."
            ];
        }

        if (!file_exists($this->globalConfigFile)) {
            return [
                'status' => 'info',
                'message' => "Global registry file does not exist yet.",
                'suggestion' => "It will be created automatically on your first successful deployment."
            ];
        }

        $content = @file_get_contents($this->globalConfigFile);
        if ($content === false) {
            return [
                'status' => 'error',
                'message' => "Could not read global registry file.",
                'suggestion' => "Check permissions on " . $this->globalConfigFile
            ];
        }

        $registry = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => "Global registry file is not valid JSON: " . json_last_error_msg(),
                'suggestion' => "Check and fix the file at " . $this->globalConfigFile
            ];
        }

        if (!isset($registry['projects']) || !is_array($registry['projects'])) {
            return [
                'status' => 'warning',
                'message' => "Global registry missing 'projects' key.",
                'suggestion' => "The registry might be corrupted or using an old format."
            ];
        }

        // Check if current project is registered
        $path = realpath($rootDir) ?: $rootDir;
        if (!isset($registry['projects'][$path])) {
            return [
                'status' => 'info',
                'message' => "Current project is not yet registered in the global registry.",
                'suggestion' => "Run 'shipit init' or 'shipit deploy' to register it."
            ];
        }

        return null;
    }
}
