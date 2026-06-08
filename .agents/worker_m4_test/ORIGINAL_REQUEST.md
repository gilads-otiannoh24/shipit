## 2026-06-05T06:42:19Z

<USER_REQUEST>
You are a Worker subagent for the E2E Testing Track of the ShipIt project.
Your working directory is /home/ian/Desktop/Packages/shipit/.agents/worker_m4_test.
Your task is to implement the Tier 3 (Cross-Feature/Pairwise) and Tier 4 (Real-World Workloads) E2E test cases (Milestone 4) and resolve outstanding bugs in the Tier 1/2 tests.

Please do the following:
1. Address the E2E test bugs identified in the reviews/stress-tests:
   - In `tests/e2e/AuthenticationTest.php`, ensure `testLoginFormSetsCookie` and `testLogoutDestroysSession` do not falsely pass on failed logins (i.e., verify that after login, a GET request to a protected dashboard route actually returns 200 instead of 302/401).
   - In `tests/e2e/AuthenticationTest.php`, fix the `testInvalidPasswordRejected` and `testInvalidUsernameRejected` assertions to inspect the actual response body or flashdata rather than looking for a header that does not exist.
   - Guard against sandbox contamination: In `tests/e2e/ShipItE2ETestCase.php::setUp()`, verify that the `SHIPIT_HOME` and `HOME` environment variables are set and point to a temporary test directory. If they point to the developer's real HOME or are empty, throw a skipped test exception or fail immediately to protect the host environment.
   - Fix directory leaks: Ensure that temporary directories created in any test classes (such as `DashboardTest`, `RemoteActionsTest`, `WebhooksTest`) are recursively deleted in their `tearDown()` method to avoid polluting `/tmp`.
2. Write `tests/e2e/CrossFeatureTest.php` containing >= 5 Tier 3 test cases:
   - testRegistryAndUI(): Registers a project via CLI (`bin/shipit init`) and asserts it appears on the dashboard UI.
   - testAuthAndActions(): Verifies that triggering remote deploy/rollback requires a valid session (blocks/redirects unauthenticated requests).
   - testWebhookAndRegistryUI(): Triggering a push webhook updates both the project registry status and dashboard details.
   - testDeployBackupRollback(): Running deploy creates a backup, which is then listable and selectable via the Rollback dashboard API.
   - testWebhookActionLogs(): Verifies that a webhook-triggered deployment generates log files which are readable/streamable via the logs dashboard endpoint.
3. Write `tests/e2e/RealWorldWorkloadTest.php` containing >= 2 Tier 4 workload scenarios:
   - testFullWorkspaceLifecycle(): Automates a full multi-stage release lifecycle: bootstrapping a project, pushing git changes, verifying webhook automation, viewing real-time deployment logs, and performing a manual UI-triggered rollback.
   - testConcurrencyAndLockStress(): Simulates concurrent webhook events and dashboard deploy triggers targeting the registry and filesystems under load, verifying that config lock mechanisms prevent resource/configuration corruption.

Verify that you can run all E2E tests via:
`vendor/bin/phpunit --testsuite E2E`
They should run (and fail/pass appropriately based on the current state of implementation). Note: since the implementation track has already built the CI4 UI, some of these tests might pass or fail based on current implementation correctness.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A Forensic Auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Provide your handoff report in your agent directory when finished, summarizing the files created/modified and the test command output.
</USER_REQUEST>

## 2026-06-05T06:48:02Z

<USER_REQUEST>
You are a worker assigned to implement Milestone 4: Tier 3 & Tier 4 Scenarios in the E2E testing suite.

Your tasks:
1. Create the test file `tests/e2e/ScenariosTest.php` under the workspace `/home/ian/Desktop/Packages/shipit`.
2. Implement 10 Tier 3 (Cross-Feature / Pairwise Combinations) tests and 5 Tier 4 (Real-World Scenarios) tests. Use the base class `ShipIt\Tests\e2e\ShipItE2ETestCase` and its helper methods `runCliCommand` and `sendHttpRequest` to interact with the CLI and HTTP endpoints.
Here is the detailed specification of the test cases:

=== Tier 3: Cross-Feature / Pairwise Combinations ===
1. testCliInitThenUiView:
   - Create a temporary project directory.
   - Run CLI `init` (reply 'n\nn\n' to skeletons prompt).
   - Login to Web UI via POST /login.
   - Send GET / to get the dashboard project list.
   - Assert that the project path is visible in the dashboard response body.
2. testCliInitThenUiDeploy:
   - CLI init a new temporary project.
   - Login to Web UI.
   - POST /projects/deploy with project_path.
   - Assert response status is 202/200, status is 'started', and log_id is not empty.
3. testCliInitThenWebhookDeploy:
   - CLI init a new temporary project.
   - Parse project's webhook token from the global config file (~/.shipit/config.json).
   - POST /api/webhook/<token> with matching branch.
   - Assert response status is 202.
4. testCliConfigThenUiDetails:
   - Run CLI config command to set a setting (e.g. `foo` = `bar` globally or locally).
   - Read/verify the global config file or load dashboard details via GET / and assert it works.
5. testUiLoginThenRollbackThenCliVerify:
   - Register a project.
   - POST /projects/rollback via Web UI with a mock backup timestamp.
   - Verify registry outcome/metadata or logs reflect the attempt.
6. testWebhookDeployThenUiLogStream:
   - Trigger a webhook deployment via POST /api/webhook/<token> for a registered project.
   - Get the log_id from the JSON response or by checking the logs folder.
   - Send GET /projects/logs/<log_id> and assert that log data is returned successfully.
7. testUiDeployThenUiLogStream:
   - Trigger a deploy via Web UI POST /projects/deploy.
   - Get the log_id from the response.
   - Send GET /projects/logs/<log_id> and assert that log data is returned.
8. testCliDeployThenUiView:
   - CLI init a project, configure a mock gitRepoUrl in its config.json.
   - Run CLI deploy command `deploy --ignore-all`.
   - Send GET / to Web UI and assert the project's latest_outcome is 'success'.
9. testCliFailingDeployThenUiView:
   - CLI init a project, configure an invalid gitRepoUrl in its config.json.
   - Run CLI deploy command (which fails).
   - Send GET / to Web UI and assert the project's latest_outcome is 'failed'.
10. testWebhookFailingDeployThenUiView:
    - Register a project with an invalid gitRepoUrl.
    - POST to the project's webhook endpoint.
    - Wait a brief moment or check the registry / GET / dashboard page to assert the outcome is eventually recorded as 'failed'.

=== Tier 4: Real-World Scenarios ===
1. testBackupRetentionRotation:
   - CLI init a project.
   - Write a dummy file to the project.
   - Trigger deploy (so a backup is created). Repeat this multiple times.
   - Verify that backup rotation is handled correctly.
2. testGitMergeConflictHandling:
   - Deploy a project with invalid/unclean state, verify behavior is handled gracefully.
3. testMultiUserOperations:
   - Verify session cookie isolation. Send request with no cookie -> redirected. Send request with active session cookie -> works.
4. testSystemEnvironmentVerification:
   - Verify that changing SHIPIT_HOME environment variable isolates the global configuration and project registry context.
5. testIgnoreFilesDeployment:
   - Create a project with a .deployignore file.
   - Trigger a deploy, and verify that files ignored by .deployignore are not copied to the target directory.

Ensure the tests compile, are fully isolated, and pass. Run `php tests/e2e/run.php` to execute the E2E tests and verify everything.
MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A Forensic Auditor will independently verify your work.
</USER_REQUEST>

