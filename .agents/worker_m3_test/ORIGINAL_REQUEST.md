## 2026-06-05T04:46:00Z

You are a Worker subagent for the E2E Testing Track of the ShipIt project.
Your working directory is /home/ian/Desktop/Packages/shipit/.agents/worker_m3_test.
Your task is to implement the Tier 2 Boundary & Corner Cases E2E test cases (Milestone 3).

Please clean up `tests/e2e/FailingCheckTest.php` if it still exists.

Please implement Tier 2 E2E tests by writing them in new boundary-specific test files or appending them to the existing E2E test files in `tests/e2e/`. Let's create new files under `tests/e2e/` named:
1. `tests/e2e/RegistryBoundaryTest.php` containing >= 5 test cases:
   - testMalformedJsonConfig(): Write invalid/malformed JSON to `config.json` and verify that CLI commands fail gracefully with a proper error message instead of PHP warnings/crashes.
   - testShellInjectionInInit(): Try to initialize a project with a malicious path or repository URL (e.g., path containing `; rm -rf` or similar shell metacharacters) and verify that CLI handles it securely without executing the shell command.
   - testDirectoryTraversalInPath(): Initialize a project with a path containing directory traversal (e.g., `/var/www/html/../../etc`) and verify if it's either normalized or rejected.
   - testExtremelyLongBranchName(): Initialize a project with an extremely long branch name (> 255 chars) and verify behavior.
   - testEmptyConfigValues(): Run init or deploy with missing or empty critical configuration values in the environment or project configuration and verify graceful rejection.

2. `tests/e2e/DashboardBoundaryTest.php` containing >= 5 test cases:
   - testDashboardWithMalformedConfig(): Load the web dashboard when `config.json` contains invalid JSON and verify the UI handles it gracefully (e.g. displays an error message or empty state, but does not crash with a 500 error).
   - testNonExistentProjectDetails(): Access the details page or trigger actions for a project path that does not exist in the registry and verify it returns a 404 response.
   - testMalformedFilterQuery(): Send invalid or excessively long search/filter parameters to the dashboard and verify it doesn't crash or trigger PHP warnings.
   - testProjectWithHugeMetadata(): Register a project with extremely large metadata values and verify dashboard renders without memory limit exhaustion.
   - testXssInjectionInFilters(): Send XSS payloads (e.g., `<script>alert(1)</script>`) in search/filter parameters and verify they are correctly escaped in the dashboard UI.

3. `tests/e2e/AuthenticationBoundaryTest.php` containing >= 5 test cases:
   - testExtremelyLongCredentials(): Attempt login with extremely long username or password values (e.g., 10,000 characters) and verify it is rejected gracefully.
   - testSqlAndShellInjectionInLogin(): Send SQL injection or shell commands in username/password fields and verify the application resists injection and rejects login.
   - testMissingCsrfToken(): Send a POST `/login` request without a CSRF token (if CSRF protection is active) or with an invalid CSRF token and verify it gets blocked.
   - testMalformedSessionCookie(): Send requests to protected dashboard routes with a corrupted/malformed session cookie and verify it is rejected and redirects to login.
   - testExpiredSessionCookie(): Verify that accessing dashboard after session timeout/cookie expiration redirects to login.

4. `tests/e2e/RemoteActionsBoundaryTest.php` containing >= 5 test cases:
   - testInvalidBackupTimestampFormat(): Trigger rollback with a malformed backup timestamp and verify it is validated and rejected with an HTTP 400.
   - testLogTraversalAttack(): Request logs with traversal patterns in `log_id` (e.g. `GET /projects/logs/../../etc/passwd` or `GET /projects/logs/some_log/../../../`) and verify it is blocked with HTTP 403 or 404.
   - testDeployNonExistentPath(): Trigger deploy for a project path not registered in `config.json` and verify it returns HTTP 404 or 400.
   - testDeployInvalidGitConfig(): Trigger deploy for a registered project whose git repo URL is invalid or inaccessible, verifying it fails and writes the failure status in the registry.
   - testMalformedDeployPayload(): Send POST `/projects/deploy` with a malformed JSON payload (invalid syntax or missing `project_path`) and verify it returns HTTP 400.

5. `tests/e2e/WebhooksBoundaryTest.php` containing >= 5 test cases:
   - testWebhookMissingPayload(): POST `/api/webhook/<token>` with empty body or missing payload and verify it is rejected.
   - testWebhookMalformedJson(): POST with malformed JSON body and verify it is rejected with a clean error response.
   - testWebhookInvalidMethod(): Send GET or PUT request to `/api/webhook/<token>` and verify it is rejected.
   - testWebhookTokenInjection(): POST `/api/webhook/<malicious_token>` containing directory traversal or SQL characters, verifying it is rejected safely.
   - testWebhookNonExistentProject(): POST `/api/webhook/<token>` where the token does not match any registered project and verify it returns HTTP 404/403.

Make sure all classes extend `ShipIt\Tests\e2e\ShipItE2ETestCase` and use namespaces correctly.
Verify that you can run these tests via:
`php tests/e2e/run.php`
They should run (executing the background server and phpunit) and report test results. Since some features are pending, those test cases are expected to fail or error out.

Document your files created and test run output in your handoff report.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A Forensic Auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.
