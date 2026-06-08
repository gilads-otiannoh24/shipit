<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\Adapters\CI4Adapter;
use ShipIt\ShipIt;

class CI4AdapterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_ci4_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeFolder($this->tempDir);
    }

    private function removeFolder(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeFolder($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testGetWritablePaths(): void
    {
        $adapter = new CI4Adapter();
        $this->assertSame(
            ['writable', 'writable/cache', 'writable/logs', 'writable/session', 'writable/uploads'],
            $adapter->getWritablePaths()
        );
    }

    public function testPostHooksInitializeWritableDirectories(): void
    {
        $adapter = new CI4Adapter();
        $hooks = $adapter->getPostHooks();

        $this->assertArrayHasKey('update', $hooks);
        $this->assertCount(1, $hooks['update']);

        $hook = $hooks['update'][0];

        $shipIt = $this->createMock(ShipIt::class);
        $shipIt->method('getRootDir')->willReturn($this->tempDir);

        // Expect runCommand to be called 5 times for the missing directories
        $shipIt->expects($this->exactly(5))
            ->method('runCommand')
            ->with($this->stringContains('Init CI4 Writable Folder'));

        $hook($shipIt);
    }

    public function testPostHooksSkipIfWritableDirectoriesExist(): void
    {
        $adapter = new CI4Adapter();
        $hooks = $adapter->getPostHooks();
        $hook = $hooks['update'][0];

        // Pre-create the directories
        mkdir($this->tempDir . '/writable/cache', 0777, true);
        mkdir($this->tempDir . '/writable/logs', 0777, true);
        mkdir($this->tempDir . '/writable/session', 0777, true);
        mkdir($this->tempDir . '/writable/uploads', 0777, true);

        $shipIt = $this->createMock(ShipIt::class);
        $shipIt->method('getRootDir')->willReturn($this->tempDir);

        // Expect runCommand to never be called because directories exist
        $shipIt->expects($this->never())
            ->method('runCommand');

        $hook($shipIt);
    }
}
