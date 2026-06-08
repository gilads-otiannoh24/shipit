# Handoff Report — Challenger 1

This report presents the findings from the empirical stress-test and verification of the E2E test runner harness (`tests/e2e/run.php`).

## 1. Observation

- **Exit Code Propagation**:
  Lines 197-201 of `tests/e2e/run.php`:
  ```php
  197: $exitCode = 1;
  198: passthru($phpunitCmd, $exitCode);
  199: 
  200: echo "\nPHPUnit E2E suite exited with code: {$exitCode}\n";
  201: exit($exitCode);
  ```
  The variable `$exitCode` is passed by reference to `passthru()`. When PHPUnit completes execution, its exit status is written into `$exitCode`. Subsequently, `exit($exitCode)` is executed.

- **Cleanup Registration**:
  Lines 129-153 of `tests/e2e/run.php`:
  ```php
  129: register_shutdown_function(function () use (&$serverProcess, $tempHome, $stdoutFile, $stderrFile) {
  130:     echo "\nCleaning up E2E environment...\n";
  131: 
  132:     if ($serverProcess && is_resource($serverProcess)) {
  133:         $status = proc_get_status($serverProcess);
  134:         if ($status && $status['running']) {
  135:             echo "Stopping background PHP web server (PID: {$status['pid']})...\n";
  136:             proc_terminate($serverProcess, 9); // Force kill (SIGKILL)
  137:         }
  138:         proc_close($serverProcess);
  139:     }
  140: 
  141:     if (file_exists($stdoutFile)) {
  142:         @unlink($stdoutFile);
  143:     }
  144:     if (file_exists($stderrFile)) {
  145:         @unlink($stderrFile);
  146:     }
  147: 
  148:     if (is_dir($tempHome)) {
  149:         echo "Removing temporary directory: {$tempHome}\n";
  150:         recursiveRmdir($tempHome);
  151:     }
  152:     echo "Cleanup complete.\n";
  153: });
  ```

- **Cleanup Execution Flow Vulnerability**:
  Lines 13-17:
  ```php
  13: $tempHome = sys_get_temp_dir() . '/shipit_e2e_home_' . bin2hex(random_bytes(8));
  14: if (!mkdir($tempHome, 0755, true)) {
  15:     fwrite(STDERR, "Error: Failed to create temporary E2E home directory: $tempHome\n");
  16:     exit(1);
  17: }
  ```
  Lines 86-87:
  ```php
  86: $stdoutFile = tempnam(sys_get_temp_dir(), 'shipit_server_stdout_');
  87: $stderrFile = tempnam(sys_get_temp_dir(), 'shipit_server_stderr_');
  ```
  The registration of the shutdown function occurs at line 129. If any failure causing script termination or `exit(1)` happens before line 129 (such as port resolution failure on line 55, or server startup failure on line 108), the shutdown handler is never registered, and `$tempHome`, `$stdoutFile`, and `$stderrFile` remain orphaned in `/tmp`.

- **Recursive Rmdir Symlink Traversal Vulnerability**:
  Lines 112-127:
  ```php
  112: function recursiveRmdir(string $dir): void
  113: {
  114:     if (!is_dir($dir)) {
  115:         return;
  116:     }
  117:     $files = array_diff(scandir($dir), ['.', '..']);
  118:     foreach ($files as $file) {
  119:         $path = $dir . DIRECTORY_SEPARATOR . $file;
  120:         if (is_dir($path)) {
  121:             recursiveRmdir($path);
  122:         } else {
  123:             @unlink($path);
  124:         }
  125:     }
  126:     @rmdir($dir);
  127: }
  ```
  In PHP, `is_dir($path)` returns `true` for a symbolic link that points to a directory. Therefore, if a test creates a symlink pointing to an external directory (e.g. `/etc` or the user's workspace), `recursiveRmdir` will traverse into that external directory and delete all its files, causing severe data loss.

- **Command Execution Block**:
  Running commands via `run_command` timed out waiting for user approval:
  ```
  Permission prompt for action 'command' on target 'php tests/e2e/run.php' timed out waiting for user response.
  ```

---

## 2. Logic Chain

1. **Failure Propagation**:
   - PHP's `passthru()` function executes the PHPUnit test suite and stores the execution exit status in its second argument by reference (`$exitCode`).
   - If one or more tests fail, PHPUnit exits with status `1` (or another non-zero value).
   - `$exitCode` receives this status, and `exit($exitCode)` propagates it to the outer execution context.
   - Therefore, E2E failures correctly propagate and result in a non-zero runner exit status.

2. **Resource Cleanup**:
   - Upon `exit($exitCode)` or successful execution termination, the registered shutdown function is executed.
   - The shutdown function stops the web server process, deletes the server log files, and calls `recursiveRmdir()` on `$tempHome`.
   - Therefore, under normal conditions or standard failure conditions after server startup, the cleanup occurs as designed.

3. **Robustness Flaw 1 (Orphaned Files)**:
   - If the script fails before line 129, the shutdown function has not yet been registered.
   - Since directories/files are created at lines 13 and 86-87, but registration happens at line 129, any failure in-between (e.g. port exhaustion or background process creation failure) results in a resource leak.

4. **Robustness Flaw 2 (Symlink Traversal Risk)**:
   - `is_dir()` resolves symlinks. A symlink pointing to an external directory resolves to a directory.
   - `recursiveRmdir` calls itself on directories, which means it will follow symlinks and recursively delete the target contents.
   - This poses a critical data loss risk.

---

## 3. Caveats

- Live process execution was blocked due to non-interactive environment timeout limitations.
- However, the code logic has been fully traced and is logically unambiguous, proving both the failure propagation, normal cleanup execution, and the two robustness/safety flaws.

---

## 4. Conclusion

- The runner harness (`tests/e2e/run.php`) correctly propagates E2E test failures with a non-zero exit code.
- Under ordinary execution paths, temporary files and directories are correctly removed by the registered shutdown function.
- However, two significant robustness and security flaws exist in the cleanup mechanism:
  1. Early-exit resource leaks (files left orphaned in `/tmp` if startup fails before line 129).
  2. Potential destructive symlink traversal in `recursiveRmdir()`.

---

## 5. Verification Method

To verify these results independently when command execution is available:
1. Run the test harness with a failing test:
   `php tests/e2e/run.php`
   Check the exit status of the process (`echo $?`). It should be non-zero (typically `1`).
2. Verify that `$tempHome` (printed in stdout during the run) is removed after the execution terminates.
3. To reproduce Vulnerability 1: Force an exit early in `run.php` (e.g., insert `exit(1);` at line 50) and run the script. Check `/tmp` for the orphaned directory `shipit_e2e_home_*`.
4. To reproduce Vulnerability 2: Create a dummy directory `/tmp/target_dir`, place a file inside it, create a symlink from inside `$tempHome` pointing to `/tmp/target_dir`, and let the runner run or invoke `recursiveRmdir` on a parent directory containing the symlink. Observe that `/tmp/target_dir/` files are deleted.
