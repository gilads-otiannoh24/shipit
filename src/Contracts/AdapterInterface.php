<?php

declare(strict_types=1);

namespace ShipIt\Contracts;

interface AdapterInterface
{
    public function getTasks(): array;
    public function getPreHooks(): array;
    public function getPostHooks(): array;
    public function getWritablePaths(): array;
    public function getOwnershipPaths(): array;
    public function getSymlinks(): array;
    public function getUpdateIgnore(): array;
    public function getBackupIgnore(): array;
    public function getRunOrderRules(): array;
}
