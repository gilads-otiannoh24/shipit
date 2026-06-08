You are a code-executing challenger/implementer. Your task is to implement the Milestone 4: Tier 3 & Tier 4 Scenarios in the E2E testing suite, and fix the identified bugs in the E2E test files and application code so that the E2E test suite runs and passes successfully.

Here are the specific files you need to write/modify under `/home/ian/Desktop/Packages/shipit`:

1. Fix E2E tests missing login helper:
   - Update `tests/e2e/DashboardTest.php` and `tests/e2e/RemoteActionsTest.php` to add the `login()` helper and call it at the start of all test cases that require authentication:
     ```php
     protected function login(): void
     {
         $this->sendHttpRequest(
             'POST',
             '/login',
             ['Content-Type' => 'application/x-www-form-urlencoded'],
             http_build_query([
                 'username' => 'testuser',
                 'password' => 'testpass',
             ])
         );
     }
     ```
   - Make sure to update the assertions as well if needed (e.g. search/filtering might need index logic changes in the controller).

2. Implement search/filtering in the Projects/Dashboard controller if missing:
   - Ensure the index method in `ui-interface/app/Controllers/Projects.php` (or whichever controller handles the main dashboard `/`) supports the `search` query parameter, filtering the registered projects list case-insensitively, so that `DashboardTest::testDashboardFilter` passes.

3. Fix backup timestamp validation in Projects controller rollback action:
   - Ensure that the backup timestamp is validated. If it's malformed (e.g. not matching the format `Ymd_His` or similar, check `RemoteActionsBoundaryTest::testInvalidBackupTimestampFormat` which expects 400 Bad Request on invalid backup timestamps), return a 400 response with JSON message, rather than returning 200/202.

4. Fix Webhooks Controller to reject empty or malformed JSON payloads:
   - In `ui-interface/app/Controllers/Api.php`, strictly return 400 Bad Request if the request content type is application/json but the body is empty or contains malformed/invalid JSON (this ensures `WebhooksBoundaryTest::testWebhookMissingPayload` and `testWebhookMalformedJson` pass). Make sure ping events (containing `zen` or `X-GitHub-Event: ping`) are still handled gracefully returning 200.

5. Create `tests/e2e/ScenariosTest.php`:
   - Implement the 10 Tier 3 tests and 5 Tier 4 tests as specified in the original request.
   - For Tier 3 (Cross-Feature/Pairwise):
     1. testCliInitThenUiView
     2. testCliInitThenUiDeploy
     3. testCliInitThenWebhookDeploy
     4. testCliConfigThenUiDetails
     5. testUiLoginThenRollbackThenCliVerify
     6. testWebhookDeployThenUiLogStream
     7. testUiDeployThenUiLogStream
     8. testCliDeployThenUiView
     9. testCliFailingDeployThenUiView
     10. testWebhookFailingDeployThenUiView
   - For Tier 4 (Real-World Scenarios):
     1. testBackupRetentionRotation
     2. testGitMergeConflictHandling
     3. testMultiUserOperations
     4. testSystemEnvironmentVerification
     5. testIgnoreFilesDeployment

6. Run the E2E test runner harness using `php tests/e2e/run.php` to verify everything is clean and all tests pass.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results.
