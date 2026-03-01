<?php

declare(strict_types=1);

namespace ShipIt;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\Adapters\CI4Adapter;

class ShipIt
{
    private TerminalUI $ui;
    private TaskRunner $runner;
    private Filesystem $fs;

    private string $rootDir;
    private string $deployDir;
    private string $configFile;

    private array $config = [];
    private bool $dryRun = false;
    private bool $log = false;
    private array $ignoreList = [];
    private array $onlyList = [];
    private bool $ignoreAll = false;
    private bool $updateSelf = false;

    private array $updateIgnoreList = [
        '.env',
        '.deploy',
        'logs',
        'public_html',
        'private_html',
        'public_ftp',
        'vendor',
        'node_modules',
        'stats',
        '.git',
        '.deployignore'
    ];
    private array $backupIgnoreList = [
        'vendor',
        'node_modules',
        '.git',
        '.vscode',
        '.DS_Store',
        '__temp_update_clone'
    ];
    private array $adapterRunOrderRules = [];

    public function __construct()
    {
        $this->ui = new TerminalUI();
        $this->runner = new TaskRunner($this->ui);

        $this->rootDir = getcwd() ?: __DIR__;
        $this->deployDir = $this->rootDir . '/.deploy';
        $this->configFile = $this->deployDir . '/config.json';
    }

    public function run(array $argv): void
    {
        $this->parseArgs($argv);
        $this->fs = new Filesystem($this->ui, $this->dryRun);

        if (!is_dir($this->deployDir) && !$this->dryRun) {
            mkdir($this->deployDir, 0777, true);
        }

        $this->loadConfig();

        $cmd = $argv[1] ?? 'deploy';
        if ($cmd === 'rollback') {
            $this->doRollback();
            return;
        }
        if ($cmd === 'list') {
            $this->listTasks();
            return;
        }

        $this->setupTasks();
        $this->applyAdapter();

        $runOrder = ['backup', 'update', 'composer', 'npm', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        if ($this->dryRun) {
            $this->ui->info("DRY RUN MODE ENABLED. No files will be modified.");
        }

        $this->runner->run($runOrder, $this->ignoreList, $this->onlyList, $this->ignoreAll, $this);
        $this->ui->success("\n✅ Deployment completed successfully.");
    }

    public function runCommand(string $label, string $cmd, bool $ignoreError = false): void
    {
        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would run: $label ($cmd)");
            return;
        }
        $escaped = escapeshellarg($this->rootDir);
        $fullCmd = "cd $escaped && $cmd 2>&1";
        $this->ui->info("⚙️  Running $label...");
        $output = shell_exec($fullCmd);
        if ($output === null && !$ignoreError) {
            $this->ui->error("$label failed");
        } else {
            $this->ui->success("$label done");
        }
    }

    private function parseArgs(array $argv): void
    {
        $this->dryRun = in_array('--dry-run', $argv, true);
        $this->log = in_array('--log', $argv, true);
        $this->ignoreAll = in_array('--ignore-all', $argv, true);
        $this->updateSelf = in_array('--update', $argv, true);

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--adapter=')) {
                $this->config['adapter'] = substr($arg, 10);
            } elseif (str_starts_with($arg, '--server=')) {
                $this->config['server'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--repo=')) {
                $this->config['gitRepoUrl'] = substr($arg, 7);
            } elseif (str_starts_with($arg, '--branch=')) {
                $this->config['branch'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--ignore=')) {
                $this->ignoreList = explode(',', substr($arg, 9));
            } elseif (str_starts_with($arg, '--only=')) {
                $this->onlyList = explode(',', substr($arg, 7));
            } elseif ($arg === '--help') {
                $this->showHelp();
                exit(0);
            }
        }
    }

    private function loadConfig(): void
    {
        $defaultConfig = [
            'adapter' => null,
            'server' => null,
            'gitRepoUrl' => null,
            'branch' => 'main',
            'user' => 'admin',
            'group' => 'admin',
            'ownership' => ['public', 'public_html', 'private_html'],
            'symlinks' => [
                ['public', 'public_html'],
                ['public_html', 'private_html']
            ],
            'writable' => ['.tempest', 'storage', 'bootstrap/cache', 'writable'],
        ];

        if (file_exists($this->configFile)) {
            $loaded = json_decode(file_get_contents($this->configFile), true) ?: [];
            $this->config = array_merge($defaultConfig, $loaded);
        } else {
            $this->ui->info("No config.json found. Initializing...");
            $this->config = $this->initInteractive($defaultConfig);
            if (!$this->dryRun) {
                file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT));
            }
        }
    }

    private function initInteractive(array $defaultConfig): array
    {
        $config = $defaultConfig;
        $config['gitRepoUrl'] = $this->ui->prompt("Git Repository URL");
        $config['branch'] = $this->ui->prompt("Branch", "main");
        $adapter = $this->ui->prompt("Framework Adapter (e.g. ci4, or leave empty for none)", "");
        if ($adapter !== '') {
            $config['adapter'] = $adapter;
        }
        $config['user'] = $this->ui->prompt("Owner User", "admin");
        $config['group'] = $this->ui->prompt("Owner Group", "admin");

        $this->ui->success("Configuration saved.");

        $this->ui->table(
            ["Key", "Value"],
            [
                ["gitRepoUrl", $config['gitRepoUrl']],
                ["branch", $config['branch']],
                ["adapter", $config['adapter'] ?? 'none'],
                ["user", $config['user']],
                ["group", $config['group']],
            ]
        );
        return $config;
    }

    private function setupTasks(): void
    {
        $this->runner->addTask('backup', fn() => $this->doBackup());
        $this->runner->addTask('update', fn() => $this->doUpdate());
        $this->runner->addTask('composer', fn() => $this->runCommand('Composer Install', 'composer install --no-dev --optimize-autoloader', true));
        $this->runner->addTask('npm', fn() => $this->runCommand('NPM Install & Build', 'npm install && npm run build'));
        $this->runner->addTask('perms', fn() => $this->fixPermissions());
        $this->runner->addTask('symlink', fn() => $this->createSymlinks());

        $this->runner->addPreHook('update', fn() => $this->ui->info("🔒 Entering maintenance mode..."));
        $this->runner->addPostHook('update', fn() => $this->ui->info("🔓 Leaving maintenance mode..."));
        $this->runner->addPostHook('composer', fn() => $this->ui->success("🚀 Composer done, autoloader optimized."));
    }

    private function applyAdapter(): void
    {
        if (empty($this->config['adapter'])) {
            return;
        }

        $adapterName = strtolower($this->config['adapter']);
        $adapterClass = null;

        if ($adapterName === 'ci4') {
            $adapterClass = new CI4Adapter();
        } else {
            $adapterFile = $this->deployDir . '/' . $adapterName . '.adapter.php';
            if (file_exists($adapterFile)) {
                require_once $adapterFile;
                $className = ucfirst($adapterName) . 'Adapter';
                if (class_exists($className)) {
                    $adapterClass = new $className();
                }
            }
        }

        if ($adapterClass instanceof AdapterInterface) {
            foreach ($adapterClass->getTasks() as $name => $task) {
                $this->runner->addTask($name, $task);
            }
            foreach ($adapterClass->getPreHooks() as $task => $hooks) {
                foreach ($hooks as $hook) {
                    $this->runner->addPreHook($task, $hook);
                }
            }
            foreach ($adapterClass->getPostHooks() as $task => $hooks) {
                foreach ($hooks as $hook) {
                    $this->runner->addPostHook($task, $hook);
                }
            }

            $this->config['writable'] = array_merge($this->config['writable'], $adapterClass->getWritablePaths());
            $this->config['ownership'] = array_merge($this->config['ownership'], $adapterClass->getOwnershipPaths());
            $this->config['symlinks'] = array_merge($this->config['symlinks'], $adapterClass->getSymlinks());

            $this->updateIgnoreList = array_unique(array_merge($this->updateIgnoreList, $adapterClass->getUpdateIgnore()));
            $this->backupIgnoreList = array_unique(array_merge($this->backupIgnoreList, $adapterClass->getBackupIgnore()));

            if (method_exists($adapterClass, 'getRunOrderRules')) {
                $this->adapterRunOrderRules = $adapterClass->getRunOrderRules();
            }
        }
    }

    private function doBackup(): void
    {
        $backupRoot = $this->rootDir . "/../../domain_backups/" . basename($this->rootDir);
        $timestamp = date('Ymd_His');
        $backupFolder = "$backupRoot/backup_$timestamp";

        if (!$this->dryRun && !is_dir($backupRoot)) {
            mkdir($backupRoot, 0777, true);
        }
        if (!$this->dryRun && !is_dir($backupFolder)) {
            mkdir($backupFolder, 0777, true);
        }

        $this->ui->info("📁 Backup started...");
        $this->fs->copyFolder($this->rootDir, $backupFolder, $this->backupIgnoreList, '', $this->log);
        $this->ui->success("Backup saved to $backupFolder");
    }

    private function doUpdate(): void
    {
        $gitRepoUrl = $this->config['gitRepoUrl'] ?? null;
        $branch = $this->config['branch'] ?? 'main';
        $cloneFolder = $this->rootDir . "/__temp_update_clone";

        if (!$gitRepoUrl) {
            $this->ui->error("No gitRepoUrl set in config.json or via arguments.");
            exit(1);
        }

        if (is_dir($cloneFolder) && !$this->dryRun) {
            $this->fs->removeFolder($cloneFolder);
        }

        $this->ui->info("📥 Cloning $gitRepoUrl (branch: $branch)");
        if (!$this->dryRun) {
            exec("git clone -b " . escapeshellarg($branch) . " " . escapeshellarg($gitRepoUrl) . " " . escapeshellarg($cloneFolder), $out, $status);
            if ($status !== 0) {
                $this->ui->error("Git clone failed.");
                exit(1);
            }
        }

        $this->ui->info("🔄 Updating project...");
        $this->fs->copyFolder($cloneFolder, $this->rootDir, $this->updateIgnoreList, '', $this->log);
        if (!$this->dryRun) {
            $this->fs->removeFolder($cloneFolder);
        }
        $this->ui->success("Update completed");
    }

    private function doRollback(): void
    {
        $backupRoot = $this->rootDir . "/../../domain_backups/" . basename($this->rootDir);
        if (!is_dir($backupRoot)) {
            $this->ui->error("No backup directory found.");
            return;
        }
        $lastBackup = shell_exec("cd " . escapeshellarg($backupRoot) . " && ls -dt * 2>/dev/null | head -1");
        $lastBackup = $backupRoot . DIRECTORY_SEPARATOR . trim((string) $lastBackup);
        if (!$lastBackup || !is_dir($lastBackup)) {
            $this->ui->error("No backup available.");
            return;
        }

        $this->ui->info("🔁 Rollback from $lastBackup ...");
        $this->fs->copyFolder($lastBackup, $this->rootDir, $this->updateIgnoreList, '', $this->log);
        $this->ui->success("Rollback complete");
    }

    private function fixPermissions(): void
    {
        $user = $this->config['user'] ?? 'admin';
        $group = $this->config['group'] ?? 'admin';
        $basePath = $this->rootDir;

        $this->ui->info("🔑 Fixing permissions in $basePath ...");

        foreach ($this->config['ownership'] as $dir) {
            $path = $basePath . '/' . $dir;
            if (file_exists($path)) {
                $this->runCommand("Chown $dir", "chown -R $user:$group " . escapeshellarg($path), true);
            }
        }

        $this->runCommand('Set directory perms', "find " . escapeshellarg($basePath) . " -type d -exec chmod 755 {} +", true);
        $this->runCommand('Set file perms', "find " . escapeshellarg($basePath) . " -type f -exec chmod 644 {} +", true);

        foreach ($this->config['writable'] as $dir) {
            $path = $basePath . '/' . $dir;
            if (is_dir($path)) {
                $this->runCommand("Writable $dir", "chmod -R 775 " . escapeshellarg($path), true);
            }
        }
        $this->ui->success("Permissions fixed");
    }

    private function createSymlinks(): void
    {
        $paths = $this->config["symlinks"] ?? [];
        $basePath = $this->rootDir;

        foreach ($paths as $p) {
            if (!(is_array($p) && isset($p[0]) && isset($p[1]) && is_string($p[0]) && is_string($p[1]))) {
                $this->ui->error("Symlink pair is invalid");
                continue;
            }

            $f1 = "$basePath/{$p[0]}";
            $f2 = "$basePath/{$p[1]}";

            if (is_dir($f1) || is_file($f1)) {
                if (is_link($f2) || is_dir($f2) || is_file($f2)) {
                    $this->runCommand("Remove existing {$p[1]}", "rm -rf " . escapeshellarg($f2), true);
                }
                $this->runCommand('Create symlink', "ln -s ./{$p[0]} ./{$p[1]}", true);
            } else {
                $this->ui->info("⚠️  Skipped symlink: no {$p[0]} found");
            }
        }
    }

    private function listTasks(): void
    {
        $this->ui->info("📋  Use `shipit` to run the deployment.");
        $this->ui->info("Other commands: rollback, list");
    }

    private function showHelp(): void
    {
        echo "Usage: shipit [options]\n\n";
        echo "Options:\n";
        echo "  rollback                  Restore last backup\n";
        echo "  list                      Show available tasks\n";
        echo "  --adapter=ci4             Use CI4 adapter\n";
        echo "  --server=directadmin      Use DirectAdmin server profile\n";
        echo "  --ignore=<task1,task2>    Skip specific tasks\n";
        echo "  --only=<task1,task2>      Run only specific tasks\n";
        echo "  --ignore-all              Skip all optional tasks\n";
        echo "  --dry-run                 Simulate deployment\n";
        echo "  --log                     Show files copied\n";
        echo "  --update                  Update this script too\n";
        echo "  --help                    Show this help\n";
    }
}
