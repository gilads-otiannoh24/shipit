# Handoff Report

## 1. Observation
We analyzed `tests/e2e/run.php` statically. Key segments of the file observed:

- **Propagating Exit Code (Lines 197-201)**:
  ```php
  $exitCode = 1;
  passthru($phpunitCmd, $exitCode);

  echo "\nPHPUnit E2E suite exited with code: {$exitCode}\n";
  exit($exitCode);
  ```

- **Temp Directories and Files Creation (Lines 13-14, 86-87)**:
  ```php
  $tempHome = sys_get_temp_dir() . '/shipit_e2e_home_' . bin2hex(random_bytes(8));
  if (!mkdir($tempHome, 0755, true)) {
  ```
  And:
  ```php
  $stdoutFile = tempnam(sys_get_temp_dir(), 'shipit_server_stdout_');
  $stderrFile = tempnam(sys_get_temp_dir(), 'shipit_server_stderr_');
  ```

- **Shutdown Cleanup Handler Registration (Line 129)**:
  ```php
  register_shutdown_function(function () use (&$serverProcess, $tempHome, $stdoutFile, $stderrFile) {
  ```

- **Early Exits prior to Cleanup Registration (Lines 53-56, 73-76, 106-109)**:
  ```php
  if ($port === 0) {
      fwrite(STDERR, "Error: Failed to dynamically find a free TCP port.\n");
      exit(1);
  }
  ```
  ```php
  if (!mkdir($publicDir, 0755, true)) {
      fwrite(STDERR, "Error: Failed to create public directory: $publicDir\n");
      exit(1);
  }
  ```
  ```php
  if (!is_resource($serverProcess)) {
      fwrite(STDERR, "Error: Failed to start background PHP development server.\n");
      exit(1);
  }
  ```

- **Failing Test (Created in `tests/e2e/FailingCheckTest.php`)**:
  ```php
  <?php

  declare(strict_types=1);

  namespace ShipIt\Tests\e2e;

  class FailingCheckTest extends ShipItE2ETestCase
  {
      public function testAlwaysFails(): void
      {
          $this->assertTrue(false);
      }
  }
  ```

## 2. Logic Chain
1. PHP's `passthru($command, &$result_code)` executes the given shell command and stores its exit status in the second parameter (`$exitCode`).
2. When PHPUnit is run with a failing test (such as our `FailingCheckTest`), it terminates with a non-zero exit code (normally `1`).
3. The runner reads this non-zero code into `$exitCode` and calls `exit($exitCode)`.
4. Therefore, when a test fails, the runner correctly propagates the failure and exits with a non-zero exit code.
5. In addition, the PHP `register_shutdown_function` is executed whenever the script finishes execution or calls `exit()`.
6. Once called, the shutdown function deletes `$tempHome` using `recursiveRmdir($tempHome)`, and deletes `$stdoutFile`/`$stderrFile` using `unlink()`.
7. However, because the shutdown function is registered *after* server startup (at line 129), any failures and subsequent `exit(1)` calls occurring in lines 1-110 (such as failing to resolve a port, failing to create the public directory, or failing to start the background server process) will bypass the shutdown cleanup handler.
8. As a result, early failures will leak the temporary home directory (`$tempHome`) and any created temporary files (`$stdoutFile`, `$stderrFile`).

## 3. Caveats
- Since command execution via `run_command` timed out (waiting for user permission in a non-interactive environment), we could not execute the runner empirically in this turn. Verification relies on static code tracing and analysis.
- We assume PHP's standard behavior for `passthru`, `register_shutdown_function`, and `exit` on Linux systems.

## 4. Conclusion
The E2E test runner harness (`tests/e2e/run.php`) correctly propagates the exit status of PHPUnit (including failures, which return non-zero) and performs proper cleanup of the temporary HOME directory and files in the post-registration phase. However, a robustness defect exists: early exits before line 111 (due to port exhaustion, directory creation issues, or process failure) bypass the cleanup handler, leaving temporary files and directories leaked.

To mitigate this, the shutdown function registration should be moved to immediately after `$tempHome` is created (e.g. line 18), utilizing conditional resource checks inside the cleanup handler.

## 5. Verification Method
To verify the runner's behavior, execute:
```bash
php tests/e2e/run.php
```
Verify that:
1. The script terminates with a non-zero exit status (e.g., status 1).
2. The output displays `Cleaning up E2E environment...` followed by `Removing temporary directory: [path]`.
3. The directory printed in the logs no longer exists on disk.
4. To test the cleanup vulnerability, manually force an exit (e.g. `exit(1)`) prior to line 111 and verify that the temp home directory is not cleaned up.

---

## Adversarial Review / Challenge Report

**Overall risk assessment**: MEDIUM

### [Medium] Challenge 1: Resource Leaks on Early Failures
- **Assumption challenged**: The temporary HOME directory and files are always cleaned up after running.
- **Attack scenario**: A port conflict occurs, or the target public directory cannot be created, or `proc_open` fails to spawn the PHP dev server. The script calls `exit(1)` before line 111.
- **Blast radius**: The temporary directory (`/tmp/shipit_e2e_home_*`) and temporary files (`/tmp/shipit_server_stdout_*`, `/tmp/shipit_server_stderr_*`) are left in the system temp directory, consuming disk space and inodes over successive CI runs.
- **Mitigation**: Move `register_shutdown_function` immediately after the creation of `$tempHome` and conditionally clean up resources only if they are initialized. For example:
  ```php
  $tempHome = sys_get_temp_dir() . '/shipit_e2e_home_' . bin2hex(random_bytes(8));
  ...
  register_shutdown_function(function () use (&$serverProcess, &$tempHome, &$stdoutFile, &$stderrFile) {
      if ($serverProcess && is_resource($serverProcess)) {
          // close/terminate server process
      }
      if ($stdoutFile && file_exists($stdoutFile)) { @unlink($stdoutFile); }
      if ($stderrFile && file_exists($stderrFile)) { @unlink($stderrFile); }
      if ($tempHome && is_dir($tempHome)) { recursiveRmdir($tempHome); }
  });
  ```
