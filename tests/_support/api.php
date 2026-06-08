<?php

declare(strict_types=1);

// Standard JSON API headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$baseSupportDir = __DIR__;
$repoDir = $baseSupportDir . '/repo';
$exampleDir = $baseSupportDir . '/example.com';
$backupDir = $baseSupportDir . '/backups';
$junitFile = $baseSupportDir . '/junit.xml';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'status':
            echo json_encode(getStatus($repoDir, $exampleDir, $backupDir, $junitFile));
            break;

        case 'init_sandbox':
            echo json_encode(initSandbox($repoDir, $exampleDir, $backupDir, $junitFile));
            break;

        case 'run_deploy':
            echo json_encode(runDeploy($exampleDir));
            break;

        case 'run_rollback':
            $target = $_POST['target'] ?? '';
            echo json_encode(runRollback($exampleDir, $target));
            break;

        case 'run_tests':
            echo json_encode(runTests($junitFile));
            break;

        case 'modify_git_file':
            $filename = $_POST['filename'] ?? 'index.php';
            $content = $_POST['content'] ?? '';
            $commitMsg = $_POST['commit_msg'] ?? 'Update file';
            echo json_encode(modifyGitFile($repoDir, $filename, $content, $commitMsg));
            break;

        case 'update_config':
            $data = json_decode(file_get_contents('php://input') ?: '{}', true);
            echo json_encode(updateConfig($exampleDir, $data));
            break;

        default:
            throw new Exception("Unknown action: " . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Recursively scans a directory for files, ignoring specific folders like .git and vendor.
 */
function scanFiles(string $dir, string $baseDir = ''): array
{
    if (empty($baseDir)) {
        $baseDir = $dir;
    }
    if (!is_dir($dir)) {
        return [];
    }

    $result = [];
    $items = scandir($dir);
    if ($items === false) {
        return [];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
        $relPath = ltrim(substr($fullPath, strlen($baseDir)), DIRECTORY_SEPARATOR);

        // Skip git and dependencies for simple clean list
        if ($item === '.git' || $item === 'node_modules' || $item === 'vendor' || $item === '__temp_update_clone') {
            $result[] = [
                'name' => $relPath,
                'is_dir' => true,
                'ignored' => true
            ];
            continue;
        }

        if (is_dir($fullPath)) {
            $result[] = [
                'name' => $relPath,
                'is_dir' => true,
                'children' => scanFiles($fullPath, $baseDir)
            ];
        } else {
            $result[] = [
                'name' => $relPath,
                'is_dir' => false,
                'size' => filesize($fullPath),
                'mtime' => filemtime($fullPath),
                'content' => is_readable($fullPath) && filesize($fullPath) < 50000 ? file_get_contents($fullPath) : null
            ];
        }
    }
    return $result;
}

/**
 * Gets status information.
 */
function getStatus(string $repoDir, string $exampleDir, string $backupDir, string $junitFile): array
{
    $sandboxInitialized = is_dir($repoDir) && is_dir($exampleDir);
    $repoFiles = $sandboxInitialized ? scanFiles($repoDir) : [];
    $exampleFiles = $sandboxInitialized ? scanFiles($exampleDir) : [];
    
    // Scan backups
    $backups = [];
    if (is_dir($backupDir)) {
        $items = scandir($backupDir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $backupDir . '/' . $item;
                if (is_dir($path)) {
                    $backups[] = [
                        'name' => $item,
                        'created_at' => date('Y-m-d H:i:s', filemtime($path)),
                        'files' => array_diff(scandir($path) ?: [], ['.', '..'])
                    ];
                }
            }
        }
    }
    // Sort backups descending
    usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));

    // Get config
    $config = null;
    $configFile = $exampleDir . '/.deploy/config.json';
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
    }

    // Get test status
    $tests = null;
    if (file_exists($junitFile)) {
        $tests = parseJUnitXML($junitFile);
    }

    // Git log
    $gitLog = [];
    if ($sandboxInitialized && is_dir($repoDir . '/.git')) {
        $cmd = "cd " . escapeshellarg($repoDir) . " && git log -n 5 --pretty=format:'%h|%s|%an|%ad' --date=short 2>&1";
        exec($cmd, $output, $status);
        if ($status === 0) {
            foreach ($output as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 4) {
                    $gitLog[] = [
                        'hash' => $parts[0],
                        'subject' => $parts[1],
                        'author' => $parts[2],
                        'date' => $parts[3]
                    ];
                }
            }
        }
    }

    return [
        'success' => true,
        'sandbox_initialized' => $sandboxInitialized,
        'repo_files' => $repoFiles,
        'example_files' => $exampleFiles,
        'backups' => $backups,
        'config' => $config,
        'tests' => $tests,
        'git_log' => $gitLog
    ];
}

/**
 * Initializes the Sandbox.
 */
function initSandbox(string $repoDir, string $exampleDir, string $backupDir, string $junitFile): array
{
    // Clean old folders
    cleanDir($repoDir);
    cleanDir($exampleDir);
    cleanDir($backupDir);

    if (file_exists($junitFile)) {
        unlink($junitFile);
    }

    // Create folders
    if (!is_dir($repoDir)) {
        mkdir($repoDir, 0777, true);
    }
    if (!is_dir($exampleDir)) {
        mkdir($exampleDir, 0777, true);
    }
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    // 1. Setup git repo
    $files = [
        'index.php' => "<?php\necho \"<h1>Welcome to ShipIt Demo Site</h1>\";\necho \"<p>Status: Running smoothly.</p>\";\n",
        'style.css' => "body { font-family: sans-serif; background: #fafafa; color: #333; padding: 2rem; }\nh1 { color: #5a5fcf; }",
        '.deployignore' => "# Files to ignore during deployment\n.env\ntests/\ndocker-compose.yml\n*.log\nnode_modules/\nvendor/\n",
        'composer.json' => "{\n  \"name\": \"shipit/demo\",\n  \"require\": {}\n}",
        'package.json' => "{\n  \"name\": \"shipit-demo\",\n  \"scripts\": {\n    \"build\": \"echo 'Building production assets...'\"\n  }\n}",
        '.env' => "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=shipit_demo\nDB_USERNAME=root\nDB_PASSWORD=secret\n",
        'storage/logs/laravel.log' => "Logs folder content that should be kept live but ignored on pull\n",
        'tests/ExampleTest.php' => "<?php\n// Test suite example"
    ];

    foreach ($files as $name => $content) {
        $fullPath = $repoDir . '/' . $name;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
    }

    // Run git commands
    $escapedRepo = escapeshellarg($repoDir);
    exec("cd $escapedRepo && git init 2>&1", $out1, $s1);
    exec("cd $escapedRepo && git config user.name 'ShipIt Simulator' 2>&1", $out2, $s2);
    exec("cd $escapedRepo && git config user.email 'simulator@shipit.local' 2>&1", $out3, $s3);
    exec("cd $escapedRepo && git checkout -b main 2>&1", $outCheckout, $sCheckout);
    exec("cd $escapedRepo && git add . 2>&1", $out4, $s4);
    exec("cd $escapedRepo && git commit -m 'Initial commit of web application' 2>&1", $out5, $s5);

    // 2. Setup deployed directory
    // Create config folder
    mkdir($exampleDir . '/.deploy', 0777, true);
    
    // Create a live env and logs file in the deployed dir that should be protected
    file_put_contents($exampleDir . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/var/live/database.sqlite\n");
    mkdir($exampleDir . '/storage/logs', 0777, true);
    file_put_contents($exampleDir . '/storage/logs/live_error.log', "Live server specific logs...\n");

    // Write ShipIt config
    $config = [
        'adapter' => 'vite',
        'server' => 'vps',
        'gitRepoUrl' => $repoDir,
        'branch' => 'main',
        'user' => get_current_user(),
        'group' => get_current_user(),
        'backup_path' => $backupDir,
        'backup_retention' => 3,
        'writable' => ['storage'],
        'last_shipped_at' => null
    ];
    file_put_contents($exampleDir . '/.deploy/config.json', json_encode($config, JSON_PRETTY_PRINT));

    return [
        'success' => true,
        'message' => 'Sandbox successfully initialized.'
    ];
}

/**
 * Runs ShipIt deploy command.
 */
function runDeploy(string $exampleDir): array
{
    if (!file_exists($exampleDir . '/.deploy/config.json')) {
        throw new Exception("Sandbox is not initialized. Please click 'Init/Reset Sandbox' first.");
    }

    $binPath = realpath(__DIR__ . '/../../bin/shipit');
    if (!$binPath) {
        throw new Exception("ShipIt binary not found.");
    }

    $cmd = "cd " . escapeshellarg($exampleDir) . " && php " . escapeshellarg($binPath) . " --ignore=composer,npm,perms,symlink < /dev/null 2>&1";
    exec($cmd, $output, $status);

    return [
        'success' => $status === 0,
        'status_code' => $status,
        'output' => implode("\n", $output)
    ];
}

/**
 * Runs ShipIt rollback command.
 */
function runRollback(string $exampleDir, string $target): array
{
    if (!file_exists($exampleDir . '/.deploy/config.json')) {
        throw new Exception("Sandbox is not initialized. Please click 'Init/Reset Sandbox' first.");
    }

    $binPath = realpath(__DIR__ . '/../../bin/shipit');
    if (!$binPath) {
        throw new Exception("ShipIt binary not found.");
    }

    $cmd = "cd " . escapeshellarg($exampleDir) . " && php " . escapeshellarg($binPath) . " rollback";
    if (!empty($target)) {
        $cmd .= " " . escapeshellarg($target);
    }
    $cmd .= " --ignore=composer,npm,perms,symlink < /dev/null 2>&1";

    exec($cmd, $output, $status);

    return [
        'success' => $status === 0,
        'status_code' => $status,
        'output' => implode("\n", $output)
    ];
}

/**
 * Runs PHPUnit tests.
 */
function runTests(string $junitFile): array
{
    $phpunitBin = realpath(__DIR__ . '/../../vendor/bin/phpunit');
    if (!$phpunitBin) {
        // Try fallback to just vendor/bin/phpunit relative
        $phpunitBin = __DIR__ . '/../../vendor/bin/phpunit';
    }

    if (file_exists($junitFile)) {
        unlink($junitFile);
    }

    // Run tests
    $cmd = "cd " . escapeshellarg(dirname(__DIR__, 2)) . " && php " . escapeshellarg($phpunitBin) . " --log-junit " . escapeshellarg($junitFile) . " 2>&1";
    exec($cmd, $output, $status);

    $parsed = null;
    if (file_exists($junitFile)) {
        $parsed = parseJUnitXML($junitFile);
    }

    return [
        'success' => true, // API successfully ran the tests
        'test_success' => $status === 0,
        'output' => implode("\n", $output),
        'parsed' => $parsed
    ];
}

/**
 * Modifies a file in the Git Repository and commits it.
 */
function modifyGitFile(string $repoDir, string $filename, string $content, string $commitMsg): array
{
    // Sanitize filename to prevent directory traversal
    $filename = str_replace(['..', '\\'], '', $filename);
    $fullPath = $repoDir . '/' . $filename;
    
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($fullPath, $content);

    $escapedRepo = escapeshellarg($repoDir);
    $escapedFile = escapeshellarg($filename);
    $escapedMsg = escapeshellarg($commitMsg);

    exec("cd $escapedRepo && git add $escapedFile 2>&1", $out1, $s1);
    exec("cd $escapedRepo && git commit -m $escapedMsg 2>&1", $out2, $s2);

    return [
        'success' => $s2 === 0,
        'commit_status' => $s2,
        'output' => implode("\n", array_merge($out1, $out2))
    ];
}

/**
 * Updates Config.
 */
function updateConfig(string $exampleDir, array $data): array
{
    $configFile = $exampleDir . '/.deploy/config.json';
    if (!file_exists($configFile)) {
        throw new Exception("config.json does not exist yet. Please initialize the sandbox.");
    }

    $current = json_decode(file_get_contents($configFile), true) ?: [];
    // Only allow updating specific safe fields
    if (isset($data['backup_retention'])) {
        $current['backup_retention'] = (int)$data['backup_retention'];
    }
    if (isset($data['adapter'])) {
        $current['adapter'] = $data['adapter'];
    }
    if (isset($data['backup_env'])) {
        $current['backup_env'] = (bool)$data['backup_env'];
    }

    file_put_contents($configFile, json_encode($current, JSON_PRETTY_PRINT));

    return [
        'success' => true,
        'config' => $current
    ];
}

/**
 * Recursively deletes directories and files.
 */
function cleanDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            // Remove permissions barriers for git files
            if ($item === '.git') {
                chmod($path, 0777);
                cleanDirGitFiles($path);
            } else {
                cleanDir($path);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

/**
 * Special helper for removing read-only git files.
 */
function cleanDirGitFiles(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            cleanDirGitFiles($path);
            rmdir($path);
        } else {
            chmod($path, 0777);
            unlink($path);
        }
    }
}

/**
 * Parses JUnit XML file generated by PHPUnit.
 */
function parseJUnitXML(string $file): array
{
    $xml = simplexml_load_file($file);
    if (!$xml) {
        return [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'suites' => []
        ];
    }

    $total = 0;
    $passed = 0;
    $failed = 0;
    $errors = 0;
    $suites = [];

    // SimpleXML structure can have nested suites
    $parseSuite = function ($suiteNode) use (&$parseSuite, &$total, &$passed, &$failed, &$errors, &$suites) {
        $suiteName = (string)$suiteNode['name'];
        $suiteData = [
            'name' => $suiteName,
            'tests' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'cases' => []
        ];

        // Process child suites recursively
        foreach ($suiteNode->testsuite as $childSuite) {
            $childParsed = $parseSuite($childSuite);
            $suiteData['tests'] += $childParsed['tests'];
            $suiteData['passed'] += $childParsed['passed'];
            $suiteData['failed'] += $childParsed['failed'];
            $suiteData['errors'] += $childParsed['errors'];
            $suiteData['cases'] = array_merge($suiteData['cases'], $childParsed['cases']);
        }

        // Process individual test cases
        foreach ($suiteNode->testcase as $caseNode) {
            $caseName = (string)$caseNode['name'];
            $class = (string)$caseNode['class'];
            $time = (float)$caseNode['time'];

            $status = 'passed';
            $message = '';
            
            if (isset($caseNode->failure)) {
                $status = 'failed';
                $message = (string)$caseNode->failure;
                $failed++;
                $suiteData['failed']++;
            } elseif (isset($caseNode->error)) {
                $status = 'error';
                $message = (string)$caseNode->error;
                $errors++;
                $suiteData['errors']++;
            } else {
                $passed++;
                $suiteData['passed']++;
            }

            $total++;
            $suiteData['tests']++;

            $suiteData['cases'][] = [
                'name' => $caseName,
                'class' => $class,
                'time' => $time,
                'status' => $status,
                'message' => $message
            ];
        }

        if ($suiteData['tests'] > 0) {
            $suites[] = $suiteData;
        }
        return $suiteData;
    };

    // Root node could be a single testsuites or a root testsuite
    if ($xml->getName() === 'testsuites') {
        foreach ($xml->testsuite as $suite) {
            $parseSuite($suite);
        }
    } else {
        $parseSuite($xml);
    }

    return [
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'errors' => $errors,
        'suites' => $suites
    ];
}
