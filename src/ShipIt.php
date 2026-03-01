<?php

declare(strict_types=1);

namespace ShipIt;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\Adapters\CI4Adapter;
use ShipIt\Adapters\LaravelAdapter;
use ShipIt\Adapters\ViteAdapter;

class ShipIt
{
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
            $this->doRollback();
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
        if (empty($this->config['adapter'])) {
            return;
        }

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

    private function doRollback(): void
    {
        $backupRoot = $this->config['backup_path'];
        if (!is_dir($backupRoot)) {
            $this->ui->error("No backup directory found at $backupRoot.");
            return;
        }
        $backups = array_filter(glob($backupRoot . DIRECTORY_SEPARATOR . 'backup_*'), 'is_dir');
        if (empty($backups)) {
            $this->ui->error("No backup folders found at $backupRoot.");
            return;
        }

        usort($backups, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $lastBackup = $backups[0];
        if (!$lastBackup || !is_dir($lastBackup)) {
            $this->ui->error("No valid backup found.");
            return;
        }

        $this->ui->info("🔁 Rollback started from $lastBackup ...");

        $this->ui->info("🧹 Clearing current project directory...");
        $this->fs->clearDirectory($this->rootDir, ['.deploy', '.git']);

        $this->ui->info("📦 Restoring files from backup...");
        $this->fs->copyFolder($lastBackup, $this->rootDir, [], '', $this->log);

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
                ["Last Shipped", $this->config['last_shipped_at'] ?? 'Never'],
            ]
        );

        $this->ui->info("\nRegistered Tasks:");
        $this->setupTasks();
        $this->applyAdapter();
        $this->applyServerProfile();

        // This is a bit hacky because we don't have a getRegisteredTasks in TaskRunner yet
        // but it works for a quick summary.
        echo "Tasks in run order: backup, update, composer, npm, symlink, perms\n";
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
        echo "Usage: shipit [command] [options]\n\n";
        echo "Commands:\n";
        echo "  deploy (default)          Run the deployment process\n";
        echo "  rollback                  Restore last backup\n";
        echo "  list                      Show available tasks\n";
        echo "  status                    Show current configuration and state\n";
        echo "  config [key] [value]      Manage configuration\n";
        echo "    --global                Manage global settings (~/.shipit/config.json)\n\n";
        echo "Options:\n";
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
