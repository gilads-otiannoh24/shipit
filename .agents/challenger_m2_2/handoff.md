# Handoff Report — Stress-Testing Tier 1 E2E Test Suites

## 1. Observation

- **Observation 1 (Authentication Test False Positive)**: In `tests/e2e/AuthenticationTest.php` (lines 9–33), `testLoginFormSetsCookie` checks for successful login by asserting:
  ```php
  $this->assertTrue(in_array($response['status_code'], [200, 302], true), "Login response status must be 200 or 302");
  
  // Assert cookie file has been written with a session cookie (usually ci_session)
  $this->assertFileExists($this->cookieFile);
  $cookieContent = file_get_contents($this->cookieFile);
  $this->assertStringContainsString('ci_session', $cookieContent, "Session cookie was not set on login");
  ```
  However, in `ui-interface/app/Libraries/SystemAuthenticator.php` (lines 14–44), there is no check for the mock variables `TEST_USER_USERNAME` and `TEST_USER_PASSWORD`. Failed login attempts in `ui-interface/app/Controllers/Auth.php` (line 30) perform a redirect back to `/login` (which has a `302` status code). Additionally, CodeIgniter 4 automatically initializes a session and sets the `ci_session` cookie on all requests (including failed logins).

- **Observation 2 (Invalid Assertion in Password Rejection Test)**: In `tests/e2e/AuthenticationTest.php` (lines 67–70), the `testInvalidPasswordRejected` test asserts:
  ```php
  $this->assertTrue(
      $response['status_code'] === 401 || 
      ($response['status_code'] === 302 && str_contains(strtolower($response['headers']), 'error')),
      "Invalid password was not rejected properly."
  );
  ```
  However, in `ui-interface/app/Controllers/Auth.php` (line 30), a failed login redirects back using:
  ```php
  return redirect()->back()->withInput()->with('error', 'Invalid username or password.');
  ```
  This command sets flashdata inside the session and issues a standard redirect header (e.g. `Location: http://127.0.0.1:port/login`). The string `error` is stored in the session cookie/payload, but is **not** present in the HTTP response headers.

- **Observation 3 (Missing Webhook Routes/Controllers)**: In `tests/e2e/WebhooksTest.php` (lines 85–93), the test hits `/api/webhook/` + token. However, looking at the routing file `ui-interface/app/Config/Routes.php` (lines 1–15) and controllers in `ui-interface/app/Controllers/`, there is no route or controller implemented for webhooks (which is planned for Milestone 5). Thus, the E2E webhook tests will fail with a `404 Not Found` response if they run.

- **Observation 4 (Sandbox Contamination on Direct PHPUnit Run)**: In `tests/e2e/RegistryTest.php` (lines 61–65), the test asserts:
  ```php
  $shipitHome = getenv('SHIPIT_HOME');
  $this->assertNotEmpty($shipitHome);
  ```
  And in `tests/e2e/RegistryTest.php` (lines 107–108, 156–158), it defines:
  ```php
  $globalConfigPath = $shipitHome . '/.shipit/config.json';
  ```
  When run directly via `vendor/bin/phpunit`, `SHIPIT_HOME` is not set. The E2E tests will run `bin/shipit init` which falls back to the developer's real `HOME` directory (`~/.shipit/config.json`), violating sandbox isolation. The test then asserts that the configuration file exists at `/.shipit/config.json` (since `$shipitHome` is empty/false), which fails.

- **Observation 5 (Directory Leaks in Temporary Paths)**: In `tests/e2e/DashboardTest.php` (lines 15–20), `tests/e2e/RemoteActionsTest.php` (lines 16–20), and `tests/e2e/WebhooksTest.php` (lines 17–21), if `SHIPIT_HOME` is not defined, it creates a temporary path:
  ```php
  $this->shipitHome = getenv('SHIPIT_HOME') ?: (sys_get_temp_dir() . '/shipit_home_' . uniqid());
  if (!is_dir($this->shipitHome . '/.shipit')) {
      mkdir($this->shipitHome . '/.shipit', 0755, true);
  }
  ```
  In `tearDown()`, it only unlinks the `config.json` file:
  ```php
  if (file_exists($this->globalConfigPath)) {
      @unlink($this->globalConfigPath);
  }
  ```
  It does not remove the `.shipit` directory or the parent `shipit_home_*` directory, leaving empty directories leaked in `sys_get_temp_dir()`.

---

## 2. Logic Chain

1. **Test False Positives**:
   - Because `SystemAuthenticator` does not support `TEST_USER_USERNAME`/`TEST_USER_PASSWORD` env checks, a login with mock credentials `testuser`/`testpass` always fails in the application (Observation 1).
   - A failed login returns a redirect (302) and a session cookie is set anyway (Observation 1).
   - The test `testLoginFormSetsCookie` only asserts status `200` or `302` and that `ci_session` is in the cookie file (Observation 1).
   - Therefore, the test incorrectly passes (false positive) even though authentication failed completely.
   - Similarly, `testLogoutDestroysSession` passes because the request to `/` redirects back to login even if they were never logged in (Observation 1).

2. **Broken Assertion Logic**:
   - `testInvalidPasswordRejected` expects the word `error` to be in the HTTP response headers (Observation 2).
   - In CI4, `redirect()->back()->with('error', ...)` uses session flashdata and does not include the word `error` in response headers (Observation 2).
   - Thus, if this test is actually run against the server, it will fail despite the server correctly rejecting the login.

3. **Incomplete Endpoints**:
   - Webhook tests target `/api/webhook/` (Observation 3).
   - Since webhook endpoints are not defined in `Routes.php` or any controller (Observation 3), hitting them will return 404.
   - Thus, webhook tests will fail due to missing application implementation.

4. **Sandbox Contamination**:
   - Running E2E tests directly via `phpunit` inherits the active user's environment. `SHIPIT_HOME` is not set by default (Observation 4).
   - Subprocesses like `bin/shipit init` fall back to the real `HOME` directory, writing to `~/.shipit/config.json` (Observation 4).
   - The test suite therefore modifies the developer's host configuration file.
   - The tests then fail on assertions for `/.shipit/config.json` (Observation 4).

5. **Resource Leaks**:
   - Since E2E test classes construct unique directories in `sys_get_temp_dir()` when `SHIPIT_HOME` is unset, and only clean up the config file inside it (Observation 5), they leave behind nested empty directories (`shipit_home_*` and `.shipit`).

---

## 3. Caveats

- We did not modify the production code of the application to verify if fixing the issues causes E2E tests to pass, as Challenger is review-only.
- We analyzed the CodeIgniter 4 routing configuration and controller logic, confirming that no webhook routes exist yet in the codebase.

---

## 4. Conclusion

The Tier 1 E2E test suites contain several critical issues:
1. **Severe False Positives**: `testLoginFormSetsCookie` and `testLogoutDestroysSession` pass successfully even when authentication fails.
2. **Broken Test Oracle**: `testInvalidPasswordRejected` expects `error` in headers, which is absent in CI4 redirects.
3. **Missing Implementation**: Webhook tests fail because the webhook routes are not registered yet.
4. **Isolation Bypass**: Running `phpunit` directly modifies the host user's actual `~/.shipit/config.json` rather than using a sandboxed directory.
5. **Directory Leaks**: Leftover directories under `/tmp` on each test run when fallback path is used.

---

## 5. Verification Method

- To verify the **Sandbox Contamination**: Run `vendor/bin/phpunit tests/e2e/RegistryTest.php` without setting `SHIPIT_HOME`. Observe that it attempts to assert file existence at `/.shipit/config.json` and fails, while actually creating/modifying `~/.shipit/config.json`.
- To verify the **False Positive on login**: Run the test runner `php tests/e2e/run.php` (if permissions/environment allow) and inspect how `testLoginFormSetsCookie` behaves. Since `SystemAuthenticator.php` has no mock authentication check, it returns a failed login redirect, but the test asserts success due to loose status code check.
