<?php

declare(strict_types=1);

namespace ShipIt;

class Filesystem
{
    private TerminalUI $ui;
    private bool $dryRun;
    private array $deployIgnoreCache = [];

    public function __construct(TerminalUI $ui, bool $dryRun = false)
    {
        $this->ui = $ui;
        $this->dryRun = $dryRun;
    }

    public function parseDeployIgnore(string $dir): array
    {
        $ignoreFile = rtrim($dir, '/') . '/.deployignore';

        if (isset($this->deployIgnoreCache[$ignoreFile])) {
            return $this->deployIgnoreCache[$ignoreFile];
        }

        $ignores = [];
        if (file_exists($ignoreFile)) {
            $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $ignores[] = $line;
                }
            }
        }
        $this->deployIgnoreCache[$ignoreFile] = $ignores;
        return $ignores;
    }

    private function isIgnored(string $relPath, array $ignoreList): bool
    {
        foreach ($ignoreList as $pattern) {
            if ($pattern === $relPath)
                return true;
            if (str_starts_with($pattern, '/') && $pattern === '/' . $relPath)
                return true;
            if (str_ends_with($pattern, '/') && str_starts_with($relPath . '/', ltrim($pattern, '/')))
                return true;
            if (fnmatch($pattern, $relPath))
                return true;
        }
        return false;
    }

    public function copyFolder(string $source, string $destination, array $ignoreList = [], string $relativeBase = '', bool $log = false): void
    {
        if (!is_dir($source))
            return;

        $sourceIgnore = $this->parseDeployIgnore($source);
        $fullIgnoreList = array_unique(array_merge($ignoreList, $sourceIgnore));

        if (!$this->dryRun && !file_exists($destination)) {
            mkdir($destination, 0777, true);
        } elseif ($this->dryRun && $relativeBase === '') {
            $this->ui->info("[Dry Run] Would create root directory: $destination");
        }

        $items = array_diff(scandir($source) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $srcPath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;
            $relPath = ltrim($relativeBase . '/' . $item, '/');

            if ($this->isIgnored($relPath, $fullIgnoreList)) {
                continue;
            }

            if (is_dir($srcPath)) {
                $this->copyFolder($srcPath, $destPath, $fullIgnoreList, $relPath, $log);
            } else {
                if ($this->dryRun) {
                    if ($log) {
                        $this->ui->success("[Dry Run] Copied: $relPath");
                    } else {
                        $this->ui->info("[Dry Run] Would copy: $relPath");
                    }
                } else {
                    if (!copy($srcPath, $destPath)) {
                        $this->ui->error("Failed to copy: $relPath");
                    } elseif ($log) {
                        $this->ui->success("Copied: $relPath");
                    }
                }
            }
        }
    }

    public function removeFolder(string $dir): void
    {
        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would remove: $dir");
            return;
        }
        if (!is_dir($dir))
            return;
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeFolder($path) : unlink($path);
        }
        rmdir($dir);
    }
}
