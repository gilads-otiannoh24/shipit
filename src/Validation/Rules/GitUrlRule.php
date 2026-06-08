<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class GitUrlRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Git URL Format';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $url = $config['gitRepoUrl'] ?? '';
        if (empty($url)) return null;

        // Basic check for SSH or HTTPS git URLs
        // e.g. git@github.com:user/repo.git or https://github.com/user/repo.git
        // Also allow local paths for testing
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            if (!is_dir($url) && !is_dir(realpath($rootDir . '/' . $url) ?: '')) {
                 return [
                    'status' => 'warning',
                    'message' => "Local Git path does not exist: $url",
                    'suggestion' => "Ensure the path to the local repository is correct."
                ];
            }
            return null;
        }

        $isSsh = preg_match('/^git@.*:.*\.git$/', $url);
        $isHttps = preg_match('/^https:\/\/.*\.git$/', $url);

        if (!$isSsh && !$isHttps) {
            return [
                'status' => 'warning',
                'message' => "Git URL format looks unusual: $url",
                'suggestion' => "Standard formats are git@host:user/repo.git or https://host/user/repo.git"
            ];
        }

        return null;
    }
}
