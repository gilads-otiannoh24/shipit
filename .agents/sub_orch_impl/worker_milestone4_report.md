# Worker Report: Milestone 4 — Remote Actions

## 1. Remote Actions Endpoints
Implemented the following remote action endpoints in CodeIgniter 4 (`ui-interface/app/Controllers/Projects.php`):
- `POST /projects/deploy`: Receives `{"project_path": "/absolute/path/to/project"}`. Resolves project path, ensures it is registered in the global project registry, and spawns the background process:
  `cd <project_path> && php <path_to_bin_shipit> deploy --log > <log_file_path> 2>&1`
  The logs are written to `ui-interface/writable/logs/<log_id>.log` using a unique log ID (`deploy_<timestamp_hash>`).
- `POST /projects/rollback`: Receives `{"project_path": "/absolute/path/to/project", "backup": "<backup_timestamp>"}`. Resolves and verifies project registration, and spawns the background rollback process:
  `cd <project_path> && php <path_to_bin_shipit> rollback <backup_timestamp> --log > <log_file_path> 2>&1`
  The logs are written to `ui-interface/writable/logs/<log_id>.log` using a unique log ID (`rollback_<timestamp_hash>`).
- `GET /projects/logs/<log_id>`: Streams log file contents using Server-Sent Events (SSE). Incremental lines are read from the log file and sent to the browser in real-time. Once the completion marker `[FINISHED]` is encountered, the connection is closed.

## 2. Web Dashboard UI Enhancements
Enhanced the Web Dashboard UI (`ui-interface/app/Views/dashboard.php`):
- Added a **Deploy** button for each project triggering `POST /projects/deploy` asynchronously.
- Added a dropdown list next to each project populated dynamically with available backups and a **Rollback** button to invoke targeted rollbacks.
- Integrated a real-time **Log Viewer Modal** using JavaScript `EventSource` to stream execution logs in real-time as background processes write them.

## 3. Core Logic & Testing Fixes
- **Backup Self-Copy Recursion Fix**: Extended `doBackup()` in `src/ShipIt.php` to calculate if `backup_path` is a subdirectory of the project's root path. If so, its relative path is dynamically ignored during recursive copy, preventing infinite directory nesting.
- **Rollback Backup Preservation Fix**: Modified `doRollback()` in `src/ShipIt.php` to add the relative `backup_path` to the `$keep` list during directory clearing, avoiding deletion of stored backups.
- **Mock Binary Injection for Testing**: Hardened `setUp()` in `ProjectsTest.php` by dynamically injecting mock `npm` and `composer` executables into the execution `PATH` and using a local Git repository path (`tests/_support/repo`) for git cloning. This allows tests to run completely offline without waiting on timed-out remote network calls.

## 4. Security Hardening
- **Option Injection Mitigation**: Hardened `SystemAuthenticator::authenticate()` in `ui-interface/app/Libraries/SystemAuthenticator.php` to immediately reject any login request where the username starts with a hyphen `-`.
- Verified that the SSH fallback command execution array uses double hyphens `--` before the destination positional argument.

## 5. Verification & Test Suite Results
Ran the complete test suites to ensure 100% correctness:

### CodeIgniter 4 Controller Tests
Executed `vendor/bin/phpunit` in `ui-interface/`:
```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.6 with PCOV 1.0.12
Configuration: /home/ian/Desktop/Packages/shipit/ui-interface/phpunit.dist.xml

.......................                                           23 / 23 (100%)

Time: 00:00.361, Memory: 18.00 MB

OK (23 tests, 53 assertions)
```

### Core CLI Unit Tests
Executed `vendor/bin/phpunit --testsuite Unit` at the project root:
```
PHPUnit 13.1.13 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.6
Configuration: /home/ian/Desktop/Packages/shipit/phpunit.xml

..................                                                18 / 18 (100%)

Time: 00:00.021, Memory: 16.00 MB

OK (18 tests, 60 assertions)
```

All unit and integration tests passed successfully.
