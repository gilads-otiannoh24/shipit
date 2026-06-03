<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\Filesystem;
use ShipIt\TerminalUI;

class FilesystemTest extends TestCase
{
    private string $tempDir;
    private string $srcDir;
    private string $destDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_test_' . uniqid();
        $this->srcDir = $this->tempDir . '/src';
        $this->destDir = $this->tempDir . '/dest';

        mkdir($this->srcDir, 0777, true);
        mkdir($this->destDir, 0777, true);

        $ui = $this->createStub(TerminalUI::class);
        $this->fs = new Filesystem($ui, false);
    }

    protected function tearDown(): void
    {
        $this->fs->removeFolder($this->tempDir);
    }

    public function testParseDeployIgnore(): void
    {
        $ignoreContent = <<<EOL
# Comment line
.env
vendor/
*.log
EOL;
        file_put_contents($this->srcDir . '/.deployignore', $ignoreContent);

        $ignores = $this->fs->parseDeployIgnore($this->srcDir);

        $this->assertSame(['.env', 'vendor/', '*.log'], $ignores);
    }

    public function testCopyFolderRespectsIgnores(): void
    {
        // Setup source files
        file_put_contents($this->srcDir . '/keep.txt', 'keep me');
        file_put_contents($this->srcDir . '/ignore.log', 'ignore me');
        mkdir($this->srcDir . '/vendor');
        file_put_contents($this->srcDir . '/vendor/autoload.php', 'composer code');

        // Setup .deployignore
        $ignoreContent = <<<EOL
*.log
vendor/
EOL;
        file_put_contents($this->srcDir . '/.deployignore', $ignoreContent);

        $this->fs->copyFolder($this->srcDir, $this->destDir);

        $this->assertFileExists($this->destDir . '/keep.txt');
        $this->assertFileExists($this->destDir . '/.deployignore');
        $this->assertFileDoesNotExist($this->destDir . '/ignore.log');
        $this->assertFileDoesNotExist($this->destDir . '/vendor/autoload.php');
    }

    public function testClearDirectory(): void
    {
        file_put_contents($this->destDir . '/file1.txt', '1');
        file_put_contents($this->destDir . '/file2.txt', '2');
        mkdir($this->destDir . '/keep_folder');
        file_put_contents($this->destDir . '/keep_folder/file3.txt', '3');

        // Clear everything except keep_folder
        $this->fs->clearDirectory($this->destDir, ['keep_folder']);

        $this->assertFileDoesNotExist($this->destDir . '/file1.txt');
        $this->assertFileDoesNotExist($this->destDir . '/file2.txt');
        $this->assertFileExists($this->destDir . '/keep_folder/file3.txt');
    }
}
