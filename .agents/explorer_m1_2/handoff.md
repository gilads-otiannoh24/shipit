# Handoff Report - Explorer 2 (Milestone 1 E2E Design)

## 1. Observation
- `composer.json` declares PHPUnit dependency `phpunit/phpunit` version `^13.1`.
- `phpunit.xml` declares a single test suite named `Unit` that includes `tests/` and excludes `tests/_support`.
- `src/ShipIt.php` lines 915-919 defines `getHomeDir()` which reads `getenv('HOME')` (or `USERPROFILE`).
- `PROJECT.md` identifies API endpoints:
  - `POST /projects/deploy` payload `{"project_path": "..."}` -> returns `{"status": "started", "log_id": "..."}`
  - `POST /projects/rollback` payload `{"project_path": "...", "backup": "..."}` -> returns `{"status": "started", "log_id": "..."}`
  - `GET /projects/logs/<log_id>` returns SSE stream/JSON chunk.
  - `POST /api/webhook/<token>` returns 202.
  - Global Registry schema (`~/.shipit/config.json`).
- `tests/_support/api.php` provides support and sandbox helpers.

## 2. Logic Chain
- Isolating global registry changes requires modifying the `HOME` and `SHIPIT_HOME` environment variables to point to a temporary test directory so that `ShipIt::getHomeDir()` and related functions return it, avoiding conflicts with real user configuration.
- Dynamic port selection avoids conflict by binding a TCP socket to port `0`.
- Background web server running under `proc_open` lets us control it programmatically, capture outputs, and clean it up using register shutdown handlers.
- E2E testing architecture groups test cases into 4 distinct tiers matching complexity and requirements R1-R6.

## 3. Caveats
- Unix user authentication (R3) relies on local system credentials which cannot be easily created/verified dynamically in CI/automated environments.
- The UI application is assumed to support fallback test environment logic where setting `TEST_USER_USERNAME` and `TEST_USER_PASSWORD` environment variables allows successful login.

## 4. Conclusion
- Detailed design specifications for `TEST_INFRA.md` and the runner `tests/e2e/run.php` are complete.

### Proposed TEST_INFRA.md contents
```markdown
# E2E Test Infrastructure Specification

This document details the end-to-end (E2E) testing infrastructure for the ShipIt Control Panel and Global Registry.

## 1. Testing Philosophy
- **Isolated Sandbox**: All tests run against a simulated environment. The global registry configuration `config.json` is redirected to a temporary location using the `SHIPIT_HOME` and `HOME` environment variables, keeping developer environments pristine.
- **Opaque-Box Testing**: Tests interact only via public interfaces: CLI tool calls (`bin/shipit`), HTTP requests to the dashboard/API server, and assertions on the filesystem side-effects.
- **Process Orchestration**: The E2E runner dynamically starts a PHP web server, runs the PHPUnit tests, and cleanly tears down processes and temporary directories on completion.
- **System Auth Mocking**: Since standard test environments cannot easily modify Linux system users, the login system supports mock Unix authentication when running in the testing environment if the `TEST_USER_USERNAME` and `TEST_USER_PASSWORD` environment variables are matched.

## 2. Feature Inventory mapped to Requirements
| Requirement | Description | E2E Test Verification Methods |
|---|---|---|
| **R1. Global Project Registry** | CENTRALIZED CONFIG in `~/.shipit/config.json` | - Check `bin/shipit init` appends directory path, repo URL, branch, and webhook token.<br>- Check deploy updates `last_shipped_at` and `latest_outcome` in `config.json`. |
| **R2. Central Control Panel** | Dashboard UI showing registered projects | - HTTP GET `/` returns project list with branch, repo, last deploy, latest outcome. |
| **R3. System User Auth** | Unix credentials authentication | - POST `/login` with valid/invalid credentials.<br>- Verifying session cookie protection on all `/projects` routes. |
| **R4. Remote Actions** | Trigger deploy/rollback & view logs | - POST `/projects/deploy` and POST `/projects/rollback` returns HTTP 200/202 with `log_id`.<br>- GET `/projects/logs/<log_id>` streams stdout logs in real-time. |
| **R5. Automation Webhooks** | Token-based trigger webhook | - POST `/api/webhook/<token>` executes non-blocking deployment, returns HTTP 202. |
| **R6. Framework Constraint** | CodeIgniter 4 in `ui-interface/` | - Verify directories and controllers are in `ui-interface/app/`. |

## 3. Test Architecture
Tests are run using PHPUnit under `tests/e2e/` and are organized in four tiers:

### Tier 1: Feature Coverage (>= 5 cases per feature)
- **Registry**:
  1. Initialize configuration for new project.
  2. Append metadata to config.
  3. Fail to init already initialized project.
  4. Global config update via CLI argument.
  5. Deployment updates status outcome and timestamp in registry.
- **Web UI**:
  1. Dashboard displays list of projects.
  2. Project details match config registry.
  3. No projects registered state.
  4. Sorting / filtering projects.
  5. Static assets load correctly.
- **System Auth**:
  1. Successful login setting cookie.
  2. Block access for unauthenticated requests.
  3. Access denied for invalid password.
  4. Access denied for invalid username.
  5. Logout destroys session.
- **Remote Actions**:
  1. Trigger deploy returns `log_id`.
  2. Trigger rollback returns `log_id`.
  3. Active log endpoint streams output.
  4. Real-time log completion status.
  5. Invalid action request handling.
- **Webhooks**:
  1. Valid token triggers deploy.
  2. Webhook triggers non-blocking response.
  3. Invalid token returns 404/403.
  4. Webhook payload matching different branches.
  5. Concurrently triggered webhook queuing.

### Tier 2: Boundary & Corner Cases (>= 5 cases per feature)
- Edge cases like malformed JSON configs, directory traversal payloads in paths, shell injection attempts in project names, database locked errors, network drops during webhook pushes, and invalid backup timestamps.

### Tier 3: Cross-Feature / Pairwise Combinations
- Workflows integrating CLI and web actions, e.g., register project via CLI -> login via UI -> view list -> deploy via UI -> check log stream -> trigger webhook -> check updated UI dashboard status -> rollback from UI.

### Tier 4: Real-World Scenarios
- Complex workflows simulating actual VPS setups: multi-user operations, backup retention rotation, git merge conflict handling during deployment, and system environment verification.

## 4. Coverage Thresholds
- **Feature Coverage**: 100% of R1-R6 requirements.
- **Test Volume**:
  - Tier 1: >= 25 cases
  - Tier 2: >= 25 cases
  - Tier 3: >= 10 cases
  - Tier 4: >= 5 cases
```

### Proposed tests/e2e/run.php design
```php
<?php

declare(strict_types=1);

/**
 * ShipIt E2E Test Runner Harness
 *
 * Sets up an isolated environment, runs a background PHP server for the CI4 UI,
 * runs E2E PHPUnit tests, and cleans up on termination.
 */

// 1. Establish isolated testing directory
$tempHome = sys_get_temp_dir() . '/shipit_e2e_' . bin2hex(random_bytes(8));
if (!mkdir($tempHome, 0755, true)) {
    fwrite(STDERR, "Error: Failed to create temp directory: $tempHome\n");
    exit(1);
}

// 2. Set environment variables for isolation
putenv("SHIPIT_HOME={$tempHome}");
putenv("HOME={$tempHome}");
putenv("TEST_USER_USERNAME=testuser");
putenv("TEST_USER_PASSWORD=testpassword");
$_ENV['SHIPIT_HOME'] = $tempHome;
$_ENV['HOME'] = $tempHome;
$_ENV['TEST_USER_USERNAME'] = 'testuser';
$_ENV['TEST_USER_PASSWORD'] = 'testpassword';

// 3. Dynamically resolve a free port
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false || !socket_bind($socket, '127.0.0.1', 0) || !socket_listen($socket)) {
    fwrite(STDERR, "Error: Failed to bind to a dynamic port.\n");
    cleanup($tempHome);
    exit(1);
}
socket_getsockname($socket, $address, $port);
socket_close($socket);

$serverUrl = "http://127.0.0.1:{$port}";
putenv("TEST_SERVER_URL={$serverUrl}");
$_ENV['TEST_SERVER_URL'] = $serverUrl;

echo "==================================================\n";
echo "ShipIt E2E Test Runner Harness\n";
echo "==================================================\n";
echo "Temp SHIPIT_HOME: {$tempHome}\n";
echo "Server URL:      {$serverUrl}\n";
echo "==================================================\n\n";

// 4. Register cleanup handler to kill server and purge files on exit
$serverProcess = null;
$pipes = [];

register_shutdown_function(function () use (&$serverProcess, &$pipes, $tempHome) {
    echo "\nCleaning up E2E environment...\n";
    
    // Close open pipes
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    
    // Terminate PHP web server
    if (is_resource($serverProcess)) {
        $status = proc_get_status($serverProcess);
        if ($status['running']) {
            echo "Stopping background PHP web server (PID: {$status['pid']})...\n";
            proc_terminate($serverProcess, 9);
        }
        proc_close($serverProcess);
    }
    
    // Remove temporary workspace directory
    cleanup($tempHome);
    echo "Cleanup complete.\n";
});

// Helper function to recursively delete directory
function cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? cleanup($path) : @unlink($path);
    }
    @rmdir($dir);
}

// 5. Start background PHP web server
$publicPath = realpath(__DIR__ . '/../../ui-interface/public');
if (!$publicPath || !is_dir($publicPath)) {
    // If ui-interface directory doesn't exist yet, we fall back to a mock public folder or error
    fwrite(STDERR, "Warning: ui-interface/public not found. Creating a temporary dummy public root.\n");
    $publicPath = $tempHome . '/public';
    mkdir($publicPath, 0755, true);
    file_put_contents($publicPath . '/index.php', "<?php echo json_encode(['status' => 'mock_server_running']);");
}

$serverCmd = "exec php -S 127.0.0.1:{$port} -t " . escapeshellarg($publicPath);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$env = array_merge($_ENV, [
    'CI_ENVIRONMENT' => 'testing',
]);

$serverProcess = proc_open($serverCmd, $descriptors, $pipes, null, $env);
if (!is_resource($serverProcess)) {
    fwrite(STDERR, "Error: Failed to launch PHP web server.\n");
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Wait for server to become responsive
$connected = false;
$start = time();
while (time() - $start < 5) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        $connected = true;
        break;
    }
    usleep(100000); // 100ms
}

if (!$connected) {
    fwrite(STDERR, "Error: PHP web server did not respond within 5 seconds.\n");
    exit(1);
}
echo "Background PHP web server is running and responsive.\n\n";

// 6. Locate PHPUnit binary and run E2E tests
$phpunitBin = realpath(__DIR__ . '/../../vendor/bin/phpunit') ?: 'phpunit';
$phpunitCmd = "php " . escapeshellarg($phpunitBin) . " --configuration=" . escapeshellarg(realpath(__DIR__ . '/../../phpunit.xml')) . " tests/e2e";

echo "Running PHPUnit E2E tests: {$phpunitCmd}\n";
$phpunitProcess = proc_open($phpunitCmd, [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR
], $phpunitPipes, null, $env);

$exitCode = proc_close($phpunitProcess);

echo "\nTests completed with exit code: {$exitCode}\n";
exit($exitCode);
```

## 5. Verification Method
- **Command**: Run `php tests/e2e/run.php`.
- **Files to Inspect**:
  - `TEST_INFRA.md` for proper requirement mapping and architectural design.
  - `tests/e2e/run.php` for isolated environment configuration, background process tracking, dynamic port lookup, and shutdown/cleanup registration.
