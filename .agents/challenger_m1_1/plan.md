# Empirical Testing Plan for E2E Test Runner Harness

This plan details the steps required to verify the robustness of `tests/e2e/run.php`.

## Steps:

1. **Verify Successful Run & Cleanup Behavior:**
   - Execute `php tests/e2e/run.php` under normal conditions (where all tests pass).
   - Capture the exit code of the process.
   - Verify that the temporary home directory (e.g., `/tmp/shipit_e2e_home_*`) and temporary files (stdout/stderr log files) are deleted/cleaned up correctly.
   - We will print the output of the command and manually inspect the logs or use directory listing to verify cleanup.

2. **Verify Failure Propagation & Cleanup Behavior:**
   - Dynamically create a failing test file: `tests/e2e/FailingCheckTest.php` with:
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
   - Execute `php tests/e2e/run.php` to run the E2E suite.
   - Capture the exit code of the process and verify that it is non-zero (meaning the failure correctly propagated).
   - Check the console output to identify the printed temporary home directory path.
   - Verify that the temporary home directory and temporary files (stdout/stderr log files) are deleted/cleaned up even when the test suite fails.
   - Clean up the temporary failing test file `tests/e2e/FailingCheckTest.php`.

3. **Verify Shutdown Handler & Process Terminations:**
   - Verify that PHP development server background process is stopped.
   
4. **Handoff & Communication:**
   - Generate `handoff.md` detailing observations, logic chain, caveats, and conclusions.
   - Send message to the orchestrator.
