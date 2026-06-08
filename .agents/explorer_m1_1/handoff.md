# Handoff Report - Explorer 1 (Milestone 1 E2E Design)

## 1. Observation
- `composer.json` (lines 24-26) declares PHPUnit dependency `phpunit/phpunit` version `^13.1`.
- `phpunit.xml` (lines 7-12) declares a single test suite named `Unit` that includes `tests/` and excludes `tests/_support`.
- `src/ShipIt.php` (lines 915-919) defines the `getHomeDir()` method as:
  ```php
  private function getHomeDir(): ?string
  {
      $home = getenv('HOME') ?: getenv('USERPROFILE');
      return $home ? rtrim($home, DIRECTORY_SEPARATOR) : null;
  }
  ```
- `src/ShipIt.php` (lines 65-66) initializes the global configuration file:
  ```php
  $this->globalConfigFile = $home ? $home . DIRECTORY_SEPARATOR . '.shipit' . DIRECTORY_SEPARATOR . 'config.json' : '';
  ```
- Running `list_dir` on the project root showed that the `ui-interface/` and `tests/e2e/` directories do not yet exist, as they are scope for future milestones.

## 2. Logic Chain
- To safely test system registry changes (Requirement R1) without modifying the host user's actual `~/.shipit/config.json` configuration, we must intercept the `HOME` environment variable.
- By overriding `putenv('HOME=' . $tempHome)` and `$_ENV['HOME'] = $tempHome` in the test execution environment, `getHomeDir()` will resolve to the temporary path, isolating the registry.
- To execute E2E tests against the web interface (Requirements R2-R5) and verify CodeIgniter 4 integration (Requirement R6), a web server must be running. PHP's built-in web server (`php -S localhost:PORT -t ui-interface/public`) is suitable.
- A programmatic runner script (`tests/e2e/run.php`) is required to:
  1. Dynamically locate a free port to avoid conflicts.
  2. Spawn the PHP web server in the background using `proc_open()` (so we can get a process handle for termination).
  3. Wait for the server to become responsive before running tests.
  4. Run PHPUnit targeting the E2E suite (`--testsuite E2E`).
  5. Clean up the server process and temporary directories reliably on script termination (using a `try/finally` structure).
- To make writing E2E tests clean, a base class `ShipItE2ETestCase` should be introduced. It will support curl/stream wrapper HTTP requests, session cookie persistence, background execution of the CLI binary, and local sandbox folders for repositories.

## 3. Caveats
- Direct Linux user authentication (Requirement R3) relies on Unix credentials via `pwauth` or local SSH loopback. E2E tests run in containers or host environments where direct root access or setting up real Unix accounts might be restricted.
- Assumption: The authentication system will support a testing mode (e.g. `CI_ENVIRONMENT = 'testing'`) that bypasses real SSH/pwauth calls when predefined mock credentials (`testuser` / `testpass`) are provided.

## 4. Conclusion
- A robust, isolated, and non-intrusive E2E testing infrastructure is designed. It meets all six requirements of the application and keeps tests sandboxed from the host machine.
- The `tests/e2e/run.php` script is the key control loop that handles background servers, dynamic ports, and environment isolation.

## 5. Verification Method
- **Command**: Run `php tests/e2e/run.php`.
- **Files to Inspect**:
  - `TEST_INFRA.md`: Verifies the specification layout, features, and philosophy.
  - `tests/e2e/run.php`: Verifies port selection, `proc_open` execution, background server tracking, PHPUnit execution, and file removal.
  - `tests/e2e/ShipItE2ETestCase.php`: Verifies HTTP request helper and CLI command runner environment variables.
- **Invalidation Condition**: If `tests/e2e/run.php` is executed and does not terminate the background web server on exit, or if running `bin/shipit` during E2E tests affects the real `~/.shipit/config.json`, the design is invalid.
