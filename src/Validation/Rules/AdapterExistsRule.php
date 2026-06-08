<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class AdapterExistsRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Adapter Existence';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $adapterName = $config['adapter'] ?? null;
        if (empty($adapterName)) return null;

        $adapterName = strtolower($adapterName);
        $builtIn = ['ci4', 'laravel', 'vite', 'react', 'custom'];
        
        if (in_array($adapterName, $builtIn, true)) {
            return null;
        }

        $deployDir = $rootDir . '/.deploy';
        $adapterFile = $deployDir . '/' . $adapterName . '.adapter.php';

        if (!file_exists($adapterFile)) {
            return [
                'status' => 'error',
                'message' => "Adapter '$adapterName' is configured but no adapter file was found.",
                'suggestion' => "Create $adapterFile or change the 'adapter' setting in config.json."
            ];
        }

        return null;
    }
}
