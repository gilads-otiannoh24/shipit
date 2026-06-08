# Milestone 5 Verification & Review Report: Automation Webhooks

## Review Summary

**Verdict**: REQUEST_CHANGES (Critical Findings)

Milestone 5 has been reviewed for functional correctness, logical completeness, security, and quality. While parts of the webhook routing and filters have been set up, the actual webhook execution path is broken due to a faulty delegation pattern, and multiple unit tests are failing out of the box.

---

## Findings

### [Critical] Finding 1: Broken Delegation in Webhooks Controller
- **What**: The `Webhooks` controller delegates to the `Api` controller but does so via a bare instantiation: `return (new Api())->webhook($token);`.
- **Where**: `ui-interface/app/Controllers/Webhooks.php` (line 9)
- **Why**: Instantiating a CodeIgniter 4 controller manually via `new Api()` does not initialize the controller lifecycle (e.g., injecting the HTTP request and response objects). Consequently, `$this->request` is `null` inside `Api::webhook()`, resulting in a fatal runtime error: `Error: Call to a member function getJSON() on null` when the controller tries to parse the payload body.
- **Suggestion**: The route in `Routes.php` should map directly to the correct controller/method (e.g., `Api::webhook`), OR the delegation in `Webhooks::trigger()` must properly call the initialization method:
  ```php
  $api = new Api();
  $api->initController($this->request, $this->response, $this->logger);
  return $api->webhook($token);
  ```

### [Critical] Finding 2: Fatal Test Failures Out-of-the-Box
- **What**: Running the test suite (`vendor/bin/phpunit`) in `ui-interface/` fails out of the box with 6 failures/errors.
- **Where**: `ui-interface/tests/app/Controllers/ApiTest.php` and `WebhooksTest.php`
- **Why**: 
  1. The broken delegation throws PHP Fatal Errors when executing tests in `WebhooksTest.php`.
  2. The assertions in `WebhooksTest.php` call `$result->getStatusCode()`. CodeIgniter's `TestResponse` does not implement a `getStatusCode()` method; instead, its `__call()` magic method catches the unrecognized call and returns `null`. This results in false-negative assertion failures (e.g. `in_array(null, [200, 202])` returns false).
  3. The `ApiTest.php` is also affected by a false-positive in `testWebhookEndpointIsPubliclyAccessible` because it asserts `assertNotEquals(302, null)`, which trivially passes even if the route is redirected.
- **Suggestion**: 
  - Fix the controller delegation.
  - Update all assertions in the tests to retrieve the status code using the public accessor method: `$result->response()->getStatusCode()`.

### [Major] Finding 3: Contradictory Test Expectations on Webhook Endpoint
- **What**: The test suites `ApiTest.php` and `WebhooksTest.php` assert contradictory behaviors on the same `/api/webhook/<token>` route.
- **Where**: 
  - `ui-interface/tests/app/Controllers/ApiTest.php`
  - `ui-interface/tests/app/Controllers/WebhooksTest.php`
- **Why**: 
  - For branch mismatch, `ApiTest.php` expects HTTP 202 with JSON status `"skipped"`, while `WebhooksTest.php` expects HTTP 200/202 with JSON status `"ignored"`.
  - For an empty payload, `ApiTest.php` expects the webhook to trigger a deployment (returning HTTP 202 with status `"started"`), whereas `WebhooksTest.php` expects it to be ignored (returning HTTP 200/202 with status `"ignored"`).
  These two test suites cannot pass simultaneously on the same route configuration.
- **Suggestion**: Harmonize the product specifications. Determine whether empty payloads should trigger deployments (as specified in some requirements) or be ignored, and rewrite/standardize on a single test suite that defines the correct behavior.

### [Major] Finding 4: Undefined Variable `$matchedPath`
- **What**: The controller references `$matchedPath` when setting up the project path.
- **Where**: `ui-interface/app/Controllers/Api.php` (line 105)
- **Why**: In `Api::webhook()`, the loop over `$projects` is: `foreach ($projects as $project)`. It does not assign the key (which is the project path) to `$matchedPath`. Thus, `$matchedPath` is undefined on line 105, which throws a PHP Notice/Warning at runtime.
- **Suggestion**: Capture the path key during iteration:
  ```php
  foreach ($projects as $path => $project) { ... }
  ```

### [Minor] Finding 5: Insecure Token Comparison (Timing Attack Vulnerability)
- **What**: The webhook token comparison uses standard strict equality.
- **Where**: `ui-interface/app/Controllers/Api.php` (line 26)
- **Why**: Standard `===` comparison is not time-constant, exposing the webhook authorization endpoint to potential timing attacks that could reveal the webhook token.
- **Suggestion**: Use the cryptographically secure constant-time comparison helper:
  ```php
  if (isset($project['webhook_token']) && hash_equals($project['webhook_token'], $token)) {
  ```

---

## Verified Claims

- **Route configuration `api/webhook/(:any)`** → Verified via `Routes.php` → **PASS** (Correctly maps to controller, though target controller lacks functionality).
- **CSRF & AuthFilter Exclusions** → Verified via `Filters.php` → **PASS** (Exclusions for `api/webhook/*` are properly configured).
- **Branch parsing from payload (`ref` field)** → Verified via `Api.php` → **PASS** (Regex/substring parsing extracts the branch correctly).
- **Asynchronous background execution (HTTP 202)** → Verified via `Api.php` → **PASS** (Spawns background processes correctly using command grouping and `&`).

---

## Coverage Gaps & Unverified Items

- **Concurrent deployment queuing** — Risk Level: **Medium** — The E2E tests check for concurrent webhook handling, but the current background task runner triggers background CLI processes concurrently without an explicit lock/queue mechanism, which could lead to race conditions when multiple payloads are received at the same time. Recommendation: Investigate adding locking/queuing mechanisms in future milestones.

---

## Challenger Report & Stress-Testing

### [High] Challenge 1: Timing Attack on Webhook Tokens
- **Assumption challenged**: Standard string comparison (`===`) is sufficient for API token validation.
- **Attack scenario**: A malicious actor can measure response latency discrepancies to guess a project's `webhook_token` character-by-character.
- **Blast radius**: Unauthorized triggers of deployment pipelines on production systems.
- **Mitigation**: Implement `hash_equals()` for comparing webhook tokens.

### [Medium] Challenge 2: Test Flakiness / Pollution of `writable/logs`
- **Assumption challenged**: The test execution environment maintains perfect isolation.
- **Attack scenario**: When `WebhooksTest` runs, it uses `glob(WRITEPATH . 'logs/deploy_*.log')` to check if a deploy log was created. If any previous test (or manual action) left a log file matching that pattern, the test fails.
- **Blast radius**: False-negative test outcomes leading to CI/CD pipeline blockers.
- **Mitigation**: Clean up or isolate log folders in test setup/teardown.
