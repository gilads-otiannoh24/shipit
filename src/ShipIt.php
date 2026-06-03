<?php

declare(strict_types=1);

namespace ShipIt;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\Adapters\CI4Adapter;
use ShipIt\Adapters\LaravelAdapter;
use ShipIt\Adapters\ViteAdapter;

class ShipIt
{
    public const VERSION = '0.0.2';

    private TerminalUI $ui;
    private TaskRunner $runner;
    private Filesystem $fs;

    private string $rootDir;
    private string $deployDir;
    private string $configFile;
    private string $globalConfigFile;

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

        $home = $this->getHomeDir();
        $this->globalConfigFile = $home ? $home . DIRECTORY_SEPARATOR . '.shipit' . DIRECTORY_SEPARATOR . 'config.json' : '';
    }

    public function run(array $argv): void
    {
        $this->parseArgs($argv);
        $this->fs = new Filesystem($this->ui, $this->dryRun);

        $cmd = 'deploy';
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                $cmd = $arg;
                break;
            }
        }

        if ($cmd !== 'config' || count($argv) <= 2) {
            $this->printLogo();
        }

        if ($cmd === 'init') {
            $this->doInit($argv);
            return;
        }

        if ($cmd === 'doctor') {
            if (file_exists($this->configFile)) {
                $this->loadConfig();
            }
            $this->doDoctor();
            return;
        }

        if ($cmd === 'version') {
            $this->showVersion();
            return;
        }

        if ($cmd === 'config' || $cmd === 'help' || in_array('--help', $argv, true)) {
            $this->loadConfig($cmd === 'config' && in_array('--global', $argv, true));
            if ($cmd === 'config') {
                $this->doConfig($argv);
                return;
            }
            $this->showHelp();
            return;
        }

        if (!is_dir($this->deployDir) && !$this->dryRun) {
            mkdir($this->deployDir, 0777, true);
        }

        $this->loadConfig();

        $this->logExecution($cmd);

        if ($cmd === 'rollback') {
            $this->doRollback($argv);
            return;
        }
        if ($cmd === 'backups') {
            $this->doBackups();
            return;
        }
        if ($cmd === 'list') {
            $this->listTasks();
            return;
        }
        if ($cmd === 'status') {
            $this->doStatus();
            return;
        }

        $this->setupTasks();
        $this->applyAdapter();
        $this->applyServerProfile();

        $runOrder = ['backup', 'update', 'composer', 'npm', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        if ($this->dryRun) {
            $this->ui->info("DRY RUN MODE ENABLED. No files will be modified.");
        }

        $this->runner->run($runOrder, $this->ignoreList, $this->onlyList, $this->ignoreAll, $this);

        if (!$this->dryRun) {
            $this->config['last_shipped_at'] = date('Y-m-d H:i:s');
            file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

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
            } elseif ($arg === '--version' || $arg === '-v') {
                $this->showVersion();
                exit(0);
            }
        }
    }

    private function loadConfig(bool $globalOnly = false): void
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
            'backup_path' => dirname($this->rootDir, 2) . '/domain_backups/' . basename($this->rootDir),
            'backup_retention' => 5,
            'last_shipped_at' => null,
        ];

        $globalConfig = [];
        if (!empty($this->globalConfigFile) && file_exists($this->globalConfigFile)) {
            $globalConfig = json_decode(file_get_contents($this->globalConfigFile), true) ?: [];
        }

        if ($globalOnly) {
            $this->config = array_merge($defaultConfig, $globalConfig);
            return;
        }

        if (file_exists($this->configFile)) {
            $loaded = json_decode(file_get_contents($this->configFile), true) ?: [];
            $this->config = array_merge($defaultConfig, $globalConfig, $loaded);
        } else {
            $this->ui->info("No project config.json found. Initializing...");
            $this->config = array_merge($defaultConfig, $globalConfig);
            $this->config = $this->initInteractive($this->config);
            if (!$this->dryRun) {
                if (!is_dir($this->deployDir)) {
                    mkdir($this->deployDir, 0777, true);
                }
                file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT));
            }
        }
    }

    private function initInteractive(array $baseConfig): array
    {
        $config = $baseConfig;
        $config['gitRepoUrl'] = $this->ui->prompt("Git Repository URL", $config['gitRepoUrl'] ?? '');
        $config['branch'] = $this->ui->prompt("Branch", $config['branch'] ?? 'main');
        $adapter = $this->ui->prompt("Framework Adapter (e.g. ci4, laravel, vite, or leave empty)", $config['adapter'] ?? '');
        if ($adapter !== '') {
            $config['adapter'] = $adapter;
        }
        $config['user'] = $this->ui->prompt("Owner User", $config['user'] ?? 'admin');
        $config['group'] = $this->ui->prompt("Owner Group", $config['group'] ?? 'admin');
        $config['backup_path'] = $this->ui->prompt("Backups Path", $config['backup_path']);

        $this->ui->success("Configuration saved to {$this->configFile}");

        $this->ui->table(
            ["Key", "Value"],
            [
                ["gitRepoUrl", $config['gitRepoUrl']],
                ["branch", $config['branch']],
                ["adapter", $config['adapter'] ?? 'none'],
                ["user", $config['user']],
                ["group", $config['group']],
                ["backup_path", $config['backup_path']],
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
        $this->applyConfigHooks();
    }

    private function applyConfigHooks(): void
    {
        $hooks = $this->config['hooks'] ?? [];
        foreach ($hooks as $key => $command) {
            if (str_starts_with($key, 'pre-')) {
                $task = substr($key, 4);
                $this->runner->addPreHook($task, fn() => $this->runCommand("Pre-hook for $task", $command, true));
            } elseif (str_starts_with($key, 'post-')) {
                $task = substr($key, 5);
                $this->runner->addPostHook($task, fn() => $this->runCommand("Post-hook for $task", $command, true));
            }
        }
    }

    private function applyAdapter(): void
    {
        $adaptersToLoad = [];
        $adapterName = null;

        // 1. Load primary framework adapter if configured
        if (!empty($this->config['adapter'])) {
            $adapterName = strtolower($this->config['adapter']);
            $adapterClass = null;

            if ($adapterName === 'ci4') {
                $adapterClass = new CI4Adapter();
            } elseif ($adapterName === 'laravel') {
                $adapterClass = new LaravelAdapter();
            } elseif ($adapterName === 'vite' || $adapterName === 'react') {
                $adapterClass = new ViteAdapter();
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

            if ($adapterClass) {
                $adaptersToLoad[] = $adapterClass;
            }
        }

        // 2. Load local custom.adapter.php if present (and wasn't already loaded as primary)
        if ($adapterName !== 'custom') {
            $customAdapterFile = $this->deployDir . '/custom.adapter.php';
            if (file_exists($customAdapterFile)) {
                require_once $customAdapterFile;
                if (class_exists('CustomAdapter')) {
                    $adaptersToLoad[] = new \CustomAdapter();
                }
            }
        }

        // 3. Process tasks, hooks, permissions, ignore lists, and order rules from all loaded adapters
        foreach ($adaptersToLoad as $adapterClass) {
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
                    $this->adapterRunOrderRules = $this->mergeAdapterRules($this->adapterRunOrderRules, $adapterClass->getRunOrderRules());
                }
            }
        }
    }

    private function mergeAdapterRules(array $rules1, array $rules2): array
    {
        $merged = $rules1;
        foreach ($rules2 as $type => $targets) {
            if (!isset($merged[$type])) {
                $merged[$type] = $targets;
                continue;
            }
            if ($type === 'prepend' || $type === 'append') {
                $merged[$type] = array_merge($merged[$type], $targets);
            } else { // 'before' or 'after'
                foreach ($targets as $target => $inserts) {
                    if (isset($merged[$type][$target])) {
                        $merged[$type][$target] = array_values(array_unique(array_merge($merged[$type][$target], $inserts)));
                    } else {
                        $merged[$type][$target] = $inserts;
                    }
                }
            }
        }
        return $merged;
    }

    private function applyServerProfile(): void
    {
        if (empty($this->config['server'])) {
            return;
        }

        $serverName = strtolower($this->config['server']);
        $profileFile = $this->deployDir . '/' . $serverName . '.server.php';

        if (file_exists($profileFile)) {
            $profile = include $profileFile;

            if (is_array($profile)) {
                // Handle arbitrary hooks/tasks/configs from a simple array return
                if (isset($profile['tasks'])) {
                    foreach ($profile['tasks'] as $name => $task) {
                        $this->runner->addTask($name, $task);
                    }
                }

                foreach (['preHooks', 'postHooks'] as $type) {
                    if (isset($profile[$type])) {
                        foreach ($profile[$type] as $task => $hooks) {
                            foreach ((array) $hooks as $hook) {
                                $method = $type === 'preHooks' ? 'addPreHook' : 'addPostHook';
                                $this->runner->{$method}($task, $hook);
                            }
                        }
                    }
                }

                foreach (['writable', 'ownership', 'symlinks'] as $key) {
                    if (isset($profile[$key])) {
                        $this->config[$key] = array_merge($this->config[$key] ?? [], $profile[$key]);
                    }
                }

                if (isset($profile['ignoreLists']['update'])) {
                    $this->updateIgnoreList = array_unique(array_merge($this->updateIgnoreList, $profile['ignoreLists']['update']));
                }
                if (isset($profile['ignoreLists']['backup'])) {
                    $this->backupIgnoreList = array_unique(array_merge($this->backupIgnoreList, $profile['ignoreLists']['backup']));
                }
            }
        }
    }

    private function doBackup(): void
    {
        if ($this->isFirstRun()) {
            $this->ui->info("⏩ Skipping backup: project directory appears to be empty or contains only deployment files.");
            return;
        }

        $backupRoot = $this->config['backup_path'];
        $timestamp = date('Ymd_His');
        $backupFolder = "$backupRoot/backup_$timestamp";

        if (!$this->dryRun && !is_dir($backupRoot)) {
            mkdir($backupRoot, 0777, true);
        }
        if (!$this->dryRun && !is_dir($backupFolder)) {
            mkdir($backupFolder, 0777, true);
        }

        $this->ui->info("📁 Backup started to $backupRoot ...");
        $this->fs->copyFolder($this->rootDir, $backupFolder, $this->backupIgnoreList, '', $this->log);
        $this->ui->success("Backup saved to $backupFolder");

        $this->rotateBackups();
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
        $isFirstRun = $this->isFirstRun();
        if ($isFirstRun) {
            $this->ui->info("✨ First run detected: copying all files from repository.");
        }

        $this->fs->copyFolder($cloneFolder, $this->rootDir, $this->updateIgnoreList, '', $this->log, $isFirstRun);
        if (!$this->dryRun) {
            $this->fs->removeFolder($cloneFolder);
        }
        $this->ui->success("Update completed");
    }

    private function doRollback(array $argv = []): void
    {
        $backupRoot = $this->config['backup_path'];
        if (!is_dir($backupRoot)) {
            $this->ui->error("No backup directory found at $backupRoot.");
            return;
        }

        // Check if a specific backup target is specified
        $target = null;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg !== 'rollback' && !str_starts_with($arg, '--')) {
                $target = $arg;
                break;
            }
        }

        $backups = array_filter(glob($backupRoot . DIRECTORY_SEPARATOR . 'backup_*'), 'is_dir');
        if (empty($backups)) {
            $this->ui->error("No backup folders found at $backupRoot.");
            return;
        }

        $selectedBackup = null;

        if ($target !== null) {
            $possiblePaths = [
                $backupRoot . DIRECTORY_SEPARATOR . $target,
                $backupRoot . DIRECTORY_SEPARATOR . 'backup_' . $target
            ];
            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    $selectedBackup = $path;
                    break;
                }
            }

            if ($selectedBackup === null) {
                $this->ui->error("Specified backup target '$target' not found in $backupRoot.");
                return;
            }
        } else {
            usort($backups, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });

            if (count($backups) > 1) {
                $this->ui->info("Multiple backups found. Please select which one to restore:");
                foreach ($backups as $index => $b) {
                    $name = basename($b);
                    $num = $index + 1;
                    $time = date('Y-m-d H:i:s', filemtime($b));
                    echo "  [$num] $name (Created: $time)\n";
                }

                $choice = $this->ui->prompt("Select backup (1-" . count($backups) . ")", "1");
                $choiceIdx = (int) $choice - 1;

                if (isset($backups[$choiceIdx])) {
                    $selectedBackup = $backups[$choiceIdx];
                } else {
                    $this->ui->error("Invalid selection. Defaulting to latest backup.");
                    $selectedBackup = $backups[0];
                }
            } else {
                $selectedBackup = $backups[0];
            }
        }

        if (!$selectedBackup || !is_dir($selectedBackup)) {
            $this->ui->error("No valid backup found.");
            return;
        }

        $this->ui->info("🔁 Rollback started from " . basename($selectedBackup) . " ...");

        $this->ui->info("🧹 Clearing current project directory...");
        $this->fs->clearDirectory($this->rootDir, ['.deploy', '.git']);

        $this->ui->info("📦 Restoring files from backup...");
        $this->fs->copyFolder($selectedBackup, $this->rootDir, [], '', $this->log);

        $this->ui->info("⚙️ Running post-rollback tasks...");
        $this->setupTasks();
        $this->applyAdapter();

        // During rollback, skip backup and update
        $runOrder = ['composer', 'npm', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        $this->runner->run($runOrder, $this->ignoreList, $this->onlyList, $this->ignoreAll, $this);

        $this->ui->success("Rollback complete");
    }

    private function doBackups(): void
    {
        $backupRoot = $this->config['backup_path'];
        if (!is_dir($backupRoot)) {
            $this->ui->info("Backup directory '$backupRoot' does not exist yet.");
            return;
        }

        $backups = array_filter(glob($backupRoot . DIRECTORY_SEPARATOR . 'backup_*'), 'is_dir');
        if (empty($backups)) {
            $this->ui->info("No backups found in $backupRoot.");
            return;
        }

        usort($backups, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $this->ui->info("Available Backups (Newest First):");
        $rows = [];
        foreach ($backups as $b) {
            $name = basename($b);
            $timestamp = substr($name, 7); // extract timestamp from 'backup_YYYYMMDD_HHMMSS'
            $formattedDate = 'Unknown';
            if (strlen($timestamp) === 15) {
                $parts = explode('_', $timestamp);
                if (count($parts) === 2) {
                    $date = $parts[0];
                    $time = $parts[1];
                    $formattedDate = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' .
                        substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);
                }
            }
            $size = $this->getFolderSize($b);
            $rows[] = [$name, $formattedDate, $this->formatSize($size)];
        }

        $this->ui->table(
            ['Backup Name', 'Created At', 'Size'],
            $rows
        );
    }

    private function getFolderSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
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
        $this->ui->info("Other commands: rollback, list, status, config");
    }

    private function doStatus(): void
    {
        $this->ui->info("ShipIt Deployment Status");
        $this->ui->table(
            ["Configuration", "Value"],
            [
                ["Project Root", $this->rootDir],
                ["Backup Path", $this->config['backup_path'] ?? 'not set'],
                ["Git Repo", $this->config['gitRepoUrl'] ?? 'not set'],
                ["Branch", $this->config['branch'] ?? 'main'],
                ["Adapter", $this->config['adapter'] ?? 'none'],
                ["Server Profile", $this->config['server'] ?? 'none'],
                ["User/Group", ($this->config['user'] ?? 'admin') . ':' . ($this->config['group'] ?? 'admin')],
                ["Backup Retention", (string) ($this->config['backup_retention'] ?? 5)],
                ["Last Shipped", $this->config['last_shipped_at'] ?? 'Never'],
            ]
        );

        $this->setupTasks();
        $this->applyAdapter();
        $this->applyServerProfile();

        $runOrder = ['backup', 'update', 'composer', 'npm', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        $registeredTasks = array_keys($this->runner->getTasks());
        $preHooks = $this->runner->getPreHooks();
        $postHooks = $this->runner->getPostHooks();

        $this->ui->info("\nDeployment Tasks in Run Order:");
        foreach ($runOrder as $index => $taskName) {
            $details = [];
            if (isset($preHooks[$taskName]) && count($preHooks[$taskName]) > 0) {
                $details[] = count($preHooks[$taskName]) . " pre-hook(s)";
            }
            if (isset($postHooks[$taskName]) && count($postHooks[$taskName]) > 0) {
                $details[] = count($postHooks[$taskName]) . " post-hook(s)";
            }

            $suffix = !empty($details) ? " (" . implode(', ', $details) . ")" : "";
            echo "  " . ($index + 1) . ". " . $taskName . $suffix . "\n";
        }

        $diff = array_diff($registeredTasks, $runOrder);
        if (!empty($diff)) {
            $this->ui->info("\nOther Registered Tasks (Not in run order):");
            foreach ($diff as $taskName) {
                echo "  - $taskName\n";
            }
        }
    }

    private function doConfig(array $argv): void
    {
        $isGlobal = in_array('--global', $argv, true);
        $file = $isGlobal ? $this->globalConfigFile : $this->configFile;

        if ($isGlobal && empty($file)) {
            $this->ui->error("Could not determine global config path.");
            return;
        }

        // Extract key/value from arguments (skip flags and command)
        $cleanArgs = [];
        $skipNext = false;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === 'config' || $arg === '--global')
                continue;
            if (str_starts_with($arg, '--'))
                continue;
            $cleanArgs[] = $arg;
        }

        if (empty($cleanArgs)) {
            $this->ui->info("Config file: " . ($file ?: 'none'));
            if ($file && file_exists($file)) {
                echo file_get_contents($file) . "\n";
            } else {
                $this->ui->info("Config file does not exist.");
            }
            return;
        }

        $key = $cleanArgs[0];
        $value = $cleanArgs[1] ?? null;

        $config = [];
        if ($file && file_exists($file)) {
            $config = json_decode(file_get_contents($file), true) ?: [];
        }

        if ($value === null) {
            if (isset($config[$key])) {
                $out = is_scalar($config[$key]) ? (string) $config[$key] : json_encode($config[$key]);
                echo $out . "\n";
            } else {
                $this->ui->error("Key '$key' not found in " . ($isGlobal ? "global" : "project") . " config.");
            }
            return;
        }

        // Type detection
        if ($value === 'true')
            $value = true;
        elseif ($value === 'false')
            $value = false;
        elseif (is_numeric($value))
            $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
        elseif (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE)
                $value = $decoded;
        }

        $config[$key] = $value;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $this->ui->error("Failed to write to $file");
        } else {
            $this->ui->success("Updated " . ($isGlobal ? "global" : "project") . " config: $key = " . json_encode($value));
        }
    }

    private function logExecution(string $cmd): void
    {
        if (empty($this->globalConfigFile))
            return;

        $logDir = dirname($this->globalConfigFile);
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'history.log';

        if (!is_dir($logDir) && !$this->dryRun) {
            mkdir($logDir, 0777, true);
        }

        $user = get_current_user();
        $host = gethostname();
        $date = date('Y-m-d H:i:s');
        $project = basename($this->rootDir);

        $entry = "[$date] User: $user@$host | Project: $project | Command: $cmd" . PHP_EOL;

        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would log execution: " . trim($entry));
            return;
        }

        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    private function isFirstRun(): bool
    {
        // Check if directory is empty (ignoring management files)
        $items = array_diff(scandir($this->rootDir) ?: [], ['.', '..', '.deploy', '.git', 'shipit', 'config.json', 'vendor', '__temp_update_clone']);
        return empty($items);
    }

    private function getHomeDir(): ?string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        return $home ? rtrim($home, DIRECTORY_SEPARATOR) : null;
    }

    private function showHelp(): void
    {
        $this->printLogo();
        echo "Usage: shipit [command] [options]\n\n";
        echo "Commands:\n";
        echo "  deploy (default)          Run the deployment process\n";
        echo "  rollback [target]         Restore a backup (omitted = latest)\n";
        echo "  backups                   List all available backups\n";
        echo "  list                      Show available tasks\n";
        echo "  status                    Show current configuration and state\n";
        echo "  config [key] [value]      Manage configuration\n";
        echo "    --global                Manage global settings (~/.shipit/config.json)\n";
        echo "  init [target]             Initialize configuration skeletons in .deploy/\n";
        echo "    Targets: config, ignore, adapter, server (omitted = all)\n";
        echo "  doctor                    Run diagnostic checks on server environment\n";
        echo "  version                   Show this script's version\n\n";
        echo "Options:\n";
        echo "  --adapter=ci4             Use CI4 adapter\n";
        echo "  --server=directadmin      Use DirectAdmin server profile\n";
        echo "  --ignore=<task1,task2>    Skip specific tasks\n";
        echo "  --only=<task1,task2>      Run only specific tasks\n";
        echo "  --ignore-all              Skip all optional tasks\n";
        echo "  --dry-run                 Simulate deployment\n";
        echo "  --log                     Show files copied\n";
        echo "  --update                  Update this script too\n";
        echo "  --version, -v             Show this script's version\n";
        echo "  --help                    Show this help\n";
    }

    private function doInit(array $argv): void
    {
        $sub = null;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg !== 'init' && !str_starts_with($arg, '--')) {
                $sub = strtolower($arg);
                break;
            }
        }

        if (!is_dir($this->deployDir) && !$this->dryRun) {
            mkdir($this->deployDir, 0777, true);
            $this->ui->success("Created deployment directory: {$this->deployDir}");
        }

        if ($sub === 'config') {
            $this->initConfigFile(true);
        } elseif ($sub === 'ignore') {
            $this->initIgnoreFile(true);
        } elseif ($sub === 'adapter') {
            $this->initAdapterFile(true);
        } elseif ($sub === 'server') {
            $this->initServerFile(true);
        } elseif ($sub === null) {
            $this->ui->info("Initializing ShipIt deployment configuration in: {$this->rootDir}");

            $this->initConfigFile(false);
            $this->initIgnoreFile(false);
            $this->initReadmeFile(false);

            $createAdapter = $this->ui->prompt("Do you want to create a custom adapter skeleton? (y/n)", "n");
            if (strtolower($createAdapter) === 'y' || strtolower($createAdapter) === 'yes') {
                $this->initAdapterFile(false);
            }

            $createServer = $this->ui->prompt("Do you want to create a custom server profile skeleton? (y/n)", "n");
            if (strtolower($createServer) === 'y' || strtolower($createServer) === 'yes') {
                $this->initServerFile(false);
            }

            $this->ui->success("Initialization complete!");
        } else {
            $this->ui->error("Unknown init target '$sub'. Available targets: config, ignore, adapter, server.");
        }
    }

    private function writeFile(string $path, string $content, bool $force = false): void
    {
        $filename = basename($path);
        if (file_exists($path) && !$force) {
            $overwrite = $this->ui->prompt("File '$filename' already exists. Overwrite? (y/n)", "n");
            if (strtolower($overwrite) !== 'y' && strtolower($overwrite) !== 'yes') {
                $this->ui->info("Skipped creating '$filename'.");
                return;
            }
        }

        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would write to $path:\n$content\n");
            return;
        }

        if (file_put_contents($path, $content) !== false) {
            $this->ui->success("Created: $path");
        } else {
            $this->ui->error("Failed to write to $path");
        }
    }

    private function initConfigFile(bool $force): void
    {
        $this->ui->info("Creating standard config.json...");
        $defaultConfig = [
            'adapter' => 'laravel',
            'server' => 'directadmin',
            'gitRepoUrl' => 'git@github.com:username/repository.git',
            'branch' => 'main',
            'user' => 'admin',
            'group' => 'admin',
            'ownership' => ['public', 'public_html', 'private_html'],
            'symlinks' => [
                ['public', 'public_html'],
                ['public_html', 'private_html']
            ],
            'writable' => ['storage', 'bootstrap/cache', 'writable'],
            'backup_path' => dirname($this->rootDir, 2) . '/domain_backups/' . basename($this->rootDir),
            'backup_retention' => 5,
            'hooks' => [
                'pre-update' => 'echo "Entering maintenance mode..."',
                'post-update' => 'echo "Leaving maintenance mode..."',
            ],
        ];

        $content = json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $this->writeFile($this->configFile, $content, $force);
    }

    private function initReadmeFile(bool $force): void
    {
        $content = <<<'MARKDOWN'
# ShipIt Deployment Configuration Directory

This directory contains the deployment configuration files and custom extensions for ShipIt.

## Files

### 1. config.json
The main configuration file. It overrides the global configuration.
Key settings:
- `gitRepoUrl`: The SSH URL of the git repository to deploy from.
- `branch`: The git branch to clone and deploy (default: "main").
- `adapter`: Optional framework adapter (e.g. "ci4", "laravel", "vite", "react", or "custom").
- `server`: Optional server profile (e.g. "directadmin", "cpanel", or "custom").
- `user` / `group`: The webserver user and group ownership to apply.
- `backup_path`: Destination directory where backups will be stored before deployment.
- `ownership`: Array of directories to apply user/group ownership to.
- `writable`: Array of directories to make writable (chmod 775).
- `symlinks`: A list of source-to-target pairs for symlinking (e.g., [["public", "public_html"]]).
- `hooks`: Script commands to run before or after tasks (e.g., "pre-update", "post-composer").

### 2. custom.adapter.php (Optional)
A custom adapter class. To use it, set "adapter": "custom" in config.json.
You can implement tasks, hooks, writable paths, symlinks, and run order specific to your framework.

### 3. custom.server.php (Optional)
A custom server profile returning an array. To use it, set "server": "custom" in config.json.
You can override directories, add hooks, or run specific tasks suitable for the server environment.
MARKDOWN;

        $this->writeFile($this->deployDir . '/README.md', $content . PHP_EOL, $force);
    }

    private function initAdapterFile(bool $force): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

/**
 * Custom Adapter for ShipIt.
 * 
 * NOTE: If you want to EXTEND an existing framework adapter (like CI4 or Laravel)
 * to run custom scripts/tasks in addition to the default tasks, you can:
 * 1. Keep "adapter": "ci4" (or "laravel") in your config.json.
 * 2. Implement your custom tasks and hooks in this CustomAdapter.
 * ShipIt will automatically load both and merge their tasks/hooks.
 *
 * Alternatively, you can subclass the adapter directly:
 * class CustomAdapter extends \ShipIt\Adapters\CI4Adapter { ... }
 */
class CustomAdapter implements AdapterInterface
{
    /**
     * Define custom tasks for your deployment process.
     * These tasks are run during the deployment.
     *
     * @return array<string, callable>
     */
    public function getTasks(): array
    {
        return [
            // Example of a custom task:
            // 'my_custom_task' => function (ShipIt $shipIt) {
            //     $shipIt->runCommand('My Custom Task', 'echo "Running my custom task!"');
            // }
        ];
    }

    /**
     * Define commands or actions to execute BEFORE specific deployment tasks.
     *
     * @return array<string, array<callable>>
     */
    public function getPreHooks(): array
    {
        return [
            // Example: run a command before the update task starts
            // 'update' => [
            //     function (ShipIt $shipIt) {
            //         $shipIt->runCommand('Maintenance On', 'php artisan down', true);
            //     }
            // ]
        ];
    }

    /**
     * Define commands or actions to execute AFTER specific deployment tasks.
     *
     * @return array<string, array<callable>>
     */
    public function getPostHooks(): array
    {
        return [
            // Example: run a command after the update task completes
            // 'update' => [
            //     function (ShipIt $shipIt) {
            //         $shipIt->runCommand('Maintenance Off', 'php artisan up', true);
            //     }
            // ]
        ];
    }

    /**
     * Return list of directories that need write permissions (chmod 775).
     *
     * @return array<string>
     */
    public function getWritablePaths(): array
    {
        return [
            // 'storage',
            // 'bootstrap/cache'
        ];
    }

    /**
     * Return list of directories that need ownership fixed (chown user:group).
     *
     * @return array<string>
     */
    public function getOwnershipPaths(): array
    {
        return [];
    }

    /**
     * Return list of symlink mappings to create.
     * Format: [['source_relative_path', 'target_relative_path']]
     *
     * @return array<array{string, string}>
     */
    public function getSymlinks(): array
    {
        return [];
    }

    /**
     * List of paths relative to root directory to ignore during update.
     *
     * @return array<string>
     */
    public function getUpdateIgnore(): array
    {
        return [];
    }

    /**
     * List of paths relative to root directory to ignore during backup.
     *
     * @return array<string>
     */
    public function getBackupIgnore(): array
    {
        return [];
    }

    /**
     * Define run order rules relative to default tasks.
     * Format: ['after' => ['task1' => ['task2']], 'before' => [...]]
     *
     * @return array
     */
    public function getRunOrderRules(): array
    {
        return [
            // Example: run 'my_custom_task' after the 'update' task
            // 'after' => [
            //     'update' => ['my_custom_task']
            // ]
        ];
    }
}
PHP;

        $path = $this->deployDir . '/custom.adapter.php';
        $this->writeFile($path, $content, $force);
    }

    private function initServerFile(bool $force): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Custom Server Profile for ShipIt.
 * To use this profile, set "server": "custom" in your .deploy/config.json.
 *
 * This profile returns an array defining server-specific hooks, tasks, permissions,
 * symlinks, and ignore rules.
 */
return [
    // Custom tasks specific to this server
    'tasks' => [
        // 'restart_fpm' => function ($shipIt) {
        //     $shipIt->runCommand('Restart PHP-FPM', 'sudo systemctl restart php8.2-fpm', true);
        // }
    ],

    // Pre-hooks to run before core tasks
    'preHooks' => [
        // 'backup' => [
        //     function ($shipIt) {
        //         $shipIt->info("Starting custom server backup pre-hook");
        //     }
        // ]
    ],

    // Post-hooks to run after core tasks
    'postHooks' => [
        // 'perms' => [
        //     function ($shipIt) {
        //         $shipIt->runCommand('Optimize OpCache', 'cachetool opcache:status', true);
        //     }
        // ]
    ],

    // Paths that must be writable on this server (chmod 775)
    'writable' => [
        // 'storage/logs'
    ],

    // Paths that need ownership fixed on this server (chown user:group)
    'ownership' => [
        // 'public_html'
    ],

    // Symlinks to establish on this server
    'symlinks' => [
        // ['public', 'public_html']
    ],

    // Lists of paths to ignore specific to this server
    'ignoreLists' => [
        'update' => [
            // '.htaccess_production'
        ],
        'backup' => [
            // 'tmp'
        ]
    ]
];
PHP;

        $path = $this->deployDir . '/custom.server.php';
        $this->writeFile($path, $content, $force);
    }

    private function initIgnoreFile(bool $force): void
    {
        $content = <<<'INI'
# .deployignore
# This file tells ShipIt which files and directories to ignore when copying
# files from your cloned Git repository into the active production directory.
#
# Patterns are matched relative to the repository root.
# Lines starting with '#' are treated as comments and ignored.

# Environment and sensitive configuration files
.env
.env.local
.env.production
*.key

# Version control files
.git
.gitignore
.gitattributes
.github/
.gitlab-ci.yml

# ShipIt deployment files
.deploy/
.deployignore
shipit
config.json

# Composer and Package Manager directories
# (ShipIt will run 'composer install' and 'npm build' in production,
# so we ignore the local source copies if they exist)
vendor/
node_modules/

# Cache, Logs, and Temp files
logs/
*.log
tmp/
.sass-cache/
.eslintcache
.phpunit.result.cache

# Framework-specific directories (e.g. Laravel / CodeIgniter)
storage/
writable/
bootstrap/cache/

# IDE and OS files
.vscode/
.idea/
.DS_Store
Thumbs.db
INI;

        $path = $this->rootDir . '/.deployignore';
        $this->writeFile($path, $content, $force);
    }

    private function rotateBackups(): void
    {
        $backupRoot = $this->config['backup_path'];
        $retention = (int) ($this->config['backup_retention'] ?? 5);

        if (!is_dir($backupRoot)) {
            return;
        }

        $backups = array_filter(glob($backupRoot . DIRECTORY_SEPARATOR . 'backup_*'), 'is_dir');

        if (count($backups) <= $retention) {
            return;
        }

        usort($backups, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $backupsToDelete = array_slice($backups, $retention);

        $this->ui->info("🧹 Cleaning up old backups (retention limit: $retention)...");
        foreach ($backupsToDelete as $oldBackup) {
            if ($this->dryRun) {
                $this->ui->info("[Dry Run] Would remove old backup: " . basename($oldBackup));
                continue;
            }

            $this->fs->removeFolder($oldBackup);
            $this->ui->success("Removed old backup: " . basename($oldBackup));
        }
    }

    private function doDoctor(): void
    {
        $this->ui->info("ShipIt Environment Doctor");
        $this->ui->info("Checking server environment and prerequisites...\n");

        $checks = [];
        $allPassed = true;

        // 1. PHP Version
        $phpVersion = PHP_VERSION;
        $phpPassed = version_compare($phpVersion, '8.1.0', '>=');
        $checks[] = [
            'Prerequisite',
            'PHP Version',
            $phpPassed ? 'SUCCESS' : 'FAILURE',
            "Required: >= 8.1. Current: $phpVersion"
        ];
        if (!$phpPassed)
            $allPassed = false;

        // 2. Disabled exec functions
        $requiredFuncs = ['exec', 'shell_exec', 'passthru'];
        $disabledFuncs = array_filter($requiredFuncs, function ($f) {
            return !function_exists($f) || in_array($f, explode(',', ini_get('disable_functions')), true);
        });
        $funcsPassed = empty($disabledFuncs);
        $checks[] = [
            'Prerequisite',
            'System Exec Functions',
            $funcsPassed ? 'SUCCESS' : 'WARNING',
            $funcsPassed ? 'Required functions are enabled' : 'Disabled: ' . implode(', ', $disabledFuncs) . '. Deployment commands may fail.'
        ];
        if (!$funcsPassed)
            $allPassed = false;

        // 3. Git binary
        $gitPath = $this->findBinary('git');
        $gitPassed = $gitPath !== null;
        $checks[] = [
            'Prerequisite',
            'Git Command',
            $gitPassed ? 'SUCCESS' : 'FAILURE',
            $gitPassed ? "Found at: $gitPath" : 'Not found in path. Install Git to enable cloning.'
        ];
        if (!$gitPassed)
            $allPassed = false;

        // 4. Composer binary
        $composerPath = $this->findBinary('composer');
        $composerPassed = $composerPath !== null;
        $checks[] = [
            'Prerequisite',
            'Composer Command',
            $composerPassed ? 'SUCCESS' : 'WARNING',
            $composerPassed ? "Found at: $composerPath" : 'Not found in path. Hook "composer" task will fail if not resolved.'
        ];

        // 5. NPM binary
        $npmPath = $this->findBinary('npm');
        $npmPassed = $npmPath !== null;
        $checks[] = [
            'Prerequisite',
            'NPM Command',
            $npmPassed ? 'SUCCESS' : 'WARNING',
            $npmPassed ? "Found at: $npmPath" : 'Not found in path. Hook "npm" task will fail if not resolved.'
        ];

        // 6. Configuration Check
        $configExists = file_exists($this->configFile);
        $checks[] = [
            'Configuration',
            'Project Config',
            $configExists ? 'SUCCESS' : 'FAILURE',
            $configExists ? 'Found .deploy/config.json' : 'Missing .deploy/config.json. Run "shipit init".'
        ];
        if (!$configExists)
            $allPassed = false;

        // 7. DeployIgnore Check
        $ignoreExists = file_exists($this->rootDir . '/.deployignore');
        $checks[] = [
            'Configuration',
            'DeployIgnore File',
            $ignoreExists ? 'SUCCESS' : 'WARNING',
            $ignoreExists ? 'Found .deployignore' : 'Missing .deployignore. All files will be copied.'
        ];

        // 8. Repository Connection Check
        if ($configExists && !empty($this->config['gitRepoUrl'])) {
            $repoUrl = $this->config['gitRepoUrl'];
            $this->ui->info("Testing connection to Git repository: $repoUrl ...");

            $connectionCmd = "GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND=\"ssh -o BatchMode=yes\" git ls-remote -h " . escapeshellarg($repoUrl) . " 2>&1";
            exec($connectionCmd, $output, $status);

            $repoPassed = ($status === 0);
            $checks[] = [
                'Configuration',
                'Git Repo Connection',
                $repoPassed ? 'SUCCESS' : 'FAILURE',
                $repoPassed ? 'Authentication successful' : 'Connection failed. Verify SSH keys, repository URL, or access permissions.'
            ];
            if (!$repoPassed)
                $allPassed = false;
        }

        $this->ui->table(
            ['Category', 'Check', 'Result', 'Notes'],
            $checks
        );

        if ($allPassed) {
            $this->ui->success("All critical checks passed! Your environment is ready to deploy.");
        } else {
            $this->ui->error("Some checks failed or generated warnings. Please review the table above.");
        }
    }

    private function findBinary(string $binary): ?string
    {
        $command = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';
        $output = [];
        $status = 0;
        @exec("$command " . escapeshellarg($binary) . " 2>&1", $output, $status);
        if ($status === 0 && !empty($output)) {
            return trim($output[0]);
        }
        return null;
    }

    private function printLogo(): void
    {
        $logoFile = __DIR__ . '/assets/ascii-logo.txt';
        if (file_exists($logoFile)) {
            $logo = file_get_contents($logoFile);
            echo $this->ui->color($logo, "\033[1;35m"); // Bold Magenta/Purple
        }
    }

    private function showVersion(): void
    {
        $this->printLogo();
        $this->ui->info("ShipIt version " . self::VERSION);
    }
}
