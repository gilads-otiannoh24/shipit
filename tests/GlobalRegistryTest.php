<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\ShipIt;
use ShipIt\TerminalUI;
use ReflectionClass;

class GlobalRegistryTest extends TestCase
{
    private string $tempDir;
    private string $shipitHome;
    private string $globalConfigDir;
    private string $globalConfigFile;

    protected function setUp(): void
    {
        // Setup a temporary directory for the project and SHIPIT_HOME
        $this->tempDir = sys_get_temp_dir() . '/shipit_proj_' . uniqid();
        $this->shipitHome = sys_get_temp_dir() . '/shipit_home_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->shipitHome, 0777, true);

        $this->globalConfigDir = $this->shipitHome . '/.shipit';
        $this->globalConfigFile = $this->globalConfigDir . '/config.json';

        // Set the environment variable
        putenv("SHIPIT_HOME=" . $this->shipitHome);
    }

    protected function tearDown(): void
    {
        // Clean up directories
        $this->removeFolder($this->tempDir);
        $this->removeFolder($this->shipitHome);

        // Clear the environment variable
        putenv("SHIPIT_HOME");
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testGetHomeDirRespectsShipitHome(): void
    {
        $shipIt = new ShipIt();
        $this->assertSame($this->shipitHome, $shipIt->getHomeDir());

        // Test fallback behavior if SHIPIT_HOME is not set
        putenv("SHIPIT_HOME"); // unset
        $shipIt2 = new ShipIt();
        $expectedFallback = getenv('HOME') ?: getenv('USERPROFILE');
        $expectedFallback = $expectedFallback ? rtrim($expectedFallback, DIRECTORY_SEPARATOR) : null;
        $this->assertSame($expectedFallback, $shipIt2->getHomeDir());

        // Restore for other tests
        putenv("SHIPIT_HOME=" . $this->shipitHome);
    }

    public function testInitRegistersProject(): void
    {
        $shipIt = new ShipIt();

        // Inject properties using Reflection
        $reflector = new ReflectionClass(ShipIt::class);

        $rootDirProp = $reflector->getProperty('rootDir');
        $rootDirProp->setValue($shipIt, $this->tempDir);

        $deployDirProp = $reflector->getProperty('deployDir');
        $deployDirProp->setValue($shipIt, $this->tempDir . '/.deploy');

        $configFileProp = $reflector->getProperty('configFile');
        $configFileProp->setValue($shipIt, $this->tempDir . '/.deploy/config.json');

        $uiProp = $reflector->getProperty('ui');
        $ui = $this->createStub(TerminalUI::class);
        $ui->method('prompt')->willReturn('n');
        $uiProp->setValue($shipIt, $ui);

        // Run shipit init
        $shipIt->run(['bin/shipit', 'init']);

        // Check if config.json was created
        $this->assertFileExists($this->tempDir . '/.deploy/config.json');

        // Check the global registry config file
        $this->assertFileExists($this->globalConfigFile);

        $registry = json_decode(file_get_contents($this->globalConfigFile), true);
        $this->assertArrayHasKey('projects', $registry);

        $path = realpath($this->tempDir) ?: $this->tempDir;
        $this->assertArrayHasKey($path, $registry['projects']);

        $project = $registry['projects'][$path];
        $this->assertSame($path, $project['path']);
        $this->assertSame('git@github.com:username/repository.git', $project['gitRepoUrl']);
        $this->assertSame('main', $project['branch']);
        $this->assertNull($project['last_shipped_at']);
        $this->assertNull($project['latest_outcome']);
        $this->assertNotEmpty($project['webhook_token']);
        $this->assertSame(32, strlen($project['webhook_token']));
    }

    public function testSuccessfulDeploymentUpdatesRegistryAndPreservesToken(): void
    {
        $shipIt = new ShipIt();

        // Inject properties using Reflection
        $reflector = new ReflectionClass(ShipIt::class);

        $rootDirProp = $reflector->getProperty('rootDir');
        $rootDirProp->setValue($shipIt, $this->tempDir);

        $deployDirProp = $reflector->getProperty('deployDir');
        $deployDirProp->setValue($shipIt, $this->tempDir . '/.deploy');

        $configFileProp = $reflector->getProperty('configFile');
        $configFileProp->setValue($shipIt, $this->tempDir . '/.deploy/config.json');

        $uiProp = $reflector->getProperty('ui');
        $ui = $this->createStub(TerminalUI::class);
        $ui->method('prompt')->willReturn('n');
        $uiProp->setValue($shipIt, $ui);

        // 1. Run init to register the project initially
        $shipIt->run(['bin/shipit', 'init']);

        // Assert initialization details
        $this->assertFileExists($this->globalConfigFile);
        $registryBefore = json_decode(file_get_contents($this->globalConfigFile), true);
        $path = realpath($this->tempDir) ?: $this->tempDir;
        $initialToken = $registryBefore['projects'][$path]['webhook_token'];

        // Modify the project config file to contain a valid gitRepoUrl so deployment doesn't error out on loading config
        $configPath = $this->tempDir . '/.deploy/config.json';
        $projConfig = json_decode(file_get_contents($configPath), true);
        $projConfig['gitRepoUrl'] = 'git@github.com:username/repository.git';
        file_put_contents($configPath, json_encode($projConfig, JSON_PRETTY_PRINT));

        // 2. Instantiate a fresh ShipIt instance (simulating a new execution run)
        $shipItDeploy = new ShipIt();
        $reflectorDeploy = new ReflectionClass(ShipIt::class);

        $rootDirPropDeploy = $reflectorDeploy->getProperty('rootDir');
        $rootDirPropDeploy->setValue($shipItDeploy, $this->tempDir);

        $deployDirPropDeploy = $reflectorDeploy->getProperty('deployDir');
        $deployDirPropDeploy->setValue($shipItDeploy, $this->tempDir . '/.deploy');

        $configFilePropDeploy = $reflectorDeploy->getProperty('configFile');
        $configFilePropDeploy->setValue($shipItDeploy, $this->tempDir . '/.deploy/config.json');

        $uiPropDeploy = $reflectorDeploy->getProperty('ui');
        $uiPropDeploy->setValue($shipItDeploy, $ui);

        // Run deployment with ignore-all so no commands are actually run
        $shipItDeploy->run(['bin/shipit', 'deploy', '--ignore-all']);

        // Check if registry got updated
        $registryAfter = json_decode(file_get_contents($this->globalConfigFile), true);
        $projectAfter = $registryAfter['projects'][$path];

        $this->assertSame($path, $projectAfter['path']);
        $this->assertSame('success', $projectAfter['latest_outcome']);
        $this->assertNotNull($projectAfter['last_shipped_at']);
        
        // Assert date format is YYYY-MM-DD HH:MM:SS
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $projectAfter['last_shipped_at']);

        // Check if webhook token is preserved
        $this->assertSame($initialToken, $projectAfter['webhook_token']);
    }
}
