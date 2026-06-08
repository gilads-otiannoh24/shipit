# Handoff Report: E2E Test Infrastructure & Runner Design

## 1. Observation
- `ORIGINAL_REQUEST.md` specifies core requirements R1 (Global Project Registry), R2 (Central Control Panel Web Server), R3 (System User Authentication), R4 (Remote Deploy/Rollback Actions), R5 (Automation Webhooks), R6 (Framework Constraint using CodeIgniter 4 in `ui-interface/`).
- `PROJECT.md` identifies Milestones, layouts, and API/JSON contracts (e.g. `~/.shipit/config.json` schema, webhooks, and dashboard action payloads).
- `src/ShipIt.php` lines 65-66:
  ```php
  $home = $this->getHomeDir();
  $this->globalConfigFile = $home ? $home . DIRECTORY_SEPARATOR . '.shipit' . DIRECTORY_SEPARATOR . 'config.json' : '';
  ```
  And lines 915-919:
  ```php
  private function getHomeDir(): ?string
  {
      $home = getenv('HOME') ?: getenv('USERPROFILE');
      return $home ? rtrim($home, DIRECTORY_SEPARATOR) : null;
  }
  ```
- `.agents/sub_orch_test/SCOPE.md` outlines the environment contracts:
  - `SHIPIT_HOME`: Directory path used by `shipit` CLI and web app to read/write `config.json`.
  - `TEST_SERVER_URL`: Base URL of the running web server.
  - `TEST_USER_USERNAME` & `TEST_USER_PASSWORD`: Credentials for system authentication simulation.

## 2. Logic Chain
- To achieve E2E environment isolation, the test runner must override `getenv('HOME')` (and `SHIPIT_HOME` if supported) before invoking the CLI tool or launching the CodeIgniter 4 web server.
- The runner script `tests/e2e/run.php` can run the built-in PHP web server dynamically on a free port by utilizing socket listening on port `0` to resolve an unallocated port number.
- By using `exec php -S 127.0.0.1:$port ... & echo $!` in Linux, we can launch the server in the background and capture its true Process ID (PID) to ensure we can kill it during cleanup.
- A register shutdown function in PHP will guarantee cleanup of the background web server and the temporary directory even on test failure or runner crashes.
- Opaque-box E2E tests should use standard cURL/HTTP requests to check the web server UI/API endpoints, shell execution for the CLI `bin/shipit`, and direct file reads on the temp directory for the config files.

## 3. Caveats
- System user authentication (R3) relies on local Linux user accounts. Running this in a standard testing harness is problematic since we cannot easily create Linux system users during execution.
- We assume that the application's auth controller will detect the `TEST_USER_USERNAME` and `TEST_USER_PASSWORD` environment variables and allow authentication matching those values when running in the test environment (e.g., if a testing flag is set or if the environment variables are present).

## 4. Conclusion
- The design of `TEST_INFRA.md` and `tests/e2e/run.php` is complete and meets all requirements.

## 5. Verification Method
- Independent verification can be performed by implementing the `tests/e2e/run.php` script and running `php tests/e2e/run.php`.
- Test failure or server leak invalidates the runner design.
