<?php

declare(strict_types=1);

namespace ShipIt\Contracts;

/**
 * Interface for validation rules.
 */
interface ValidationRuleInterface
{
    /**
     * Get the name of the rule.
     */
    public function getName(): string;

    /**
     * Perform the validation.
     * 
     * @param array $config The merged configuration to validate.
     * @param string $rootDir The root directory of the project.
     * @return array Returns an array with keys: 'status' (error|warning|info), 'message', and optional 'suggestion'.
     *               Return null or empty array if validation passes.
     */
    public function validate(array $config, string $rootDir): ?array;
}
