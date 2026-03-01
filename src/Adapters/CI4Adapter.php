<?php

declare(strict_types=1);

namespace ShipIt\Adapters;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

class CI4Adapter implements AdapterInterface
{
    public function getTasks(): array
    {
        return [
            'migrate' => function (ShipIt $shipIt) {
                $shipIt->runCommand('CI4 Migrate', 'php spark migrate', true);
            }
        ];
    }

    public function getPreHooks(): array
    {
        return [];
    }

    public function getPostHooks(): array
    {
        return [];
    }

    public function getWritablePaths(): array
    {
        return ['writable', 'writable/cache', 'writable/logs', 'writable/session', 'writable/uploads'];
    }

    public function getOwnershipPaths(): array
    {
        return [];
    }

    public function getSymlinks(): array
    {
        return [];
    }

    public function getUpdateIgnore(): array
    {
        return ['writable'];
    }

    public function getBackupIgnore(): array
    {
        return ['writable/cache', 'writable/session', 'writable/logs'];
    }

    public function getRunOrderRules(): array
    {
        return ['after' => ['update' => ['migrate']]];
    }
}
