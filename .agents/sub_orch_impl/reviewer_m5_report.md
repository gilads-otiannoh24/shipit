# Milestone 5 Review Report

## Review Summary

**Verdict**: REQUEST_CHANGES

The implementation of Milestone 5: Automation Webhooks in `ui-interface/` satisfies most functional requirements (CSRF/Auth filter bypass, global registry token verification, background non-blocking deployment, payload branch filtering). However, there is a significant discrepancy between the controller implementation in `Api.php` and the assertions in the two test files `tests/app/Controllers/ApiTest.php` and `tests/app/Controllers/WebhooksTest.php`. Running the test suite will fail due to mismatched status and reason strings when webhooks are skipped/ignored.

---

## Findings

### [Critical] Finding 1: Mismatched Assertions Between Controller and Webhook Tests

- **What**: The webhook controller (`app/Controllers/Api.php`) returns responses that are incompatible with the assertions in the CodeIgniter test suite `tests/app/Controllers/WebhooksTest.php`, causing tests to fail.
- **Where**: 
  - `ui-interface/app/Controllers/Api.php` (lines 72-98, 133-140)
  - `ui-interface/tests/app/Controllers/WebhooksTest.php` (lines 150-172, 194-213)
- **Why**: 
  - For mismatched branches: `Api.php` returns `status => 'skipped'` and `reason => 'branch mismatch'`, whereas `WebhooksTest.php` expects `status => 'ignored'` and `reason => 'branch mismatch or non-push event'`.
  - For ping events: `Api.php` returns `status => 'ignored'` and `reason => 'ping event'`, whereas `WebhooksTest.php` expects `status => 'ignored'` and `reason => 'branch mismatch or non-push event'`.
- **Suggestion**: The implementer should unify the expected JSON schema and response fields across the controller and both test suites (`ApiTest.php` and `WebhooksTest.php`). For example, modifying the controller to return values that satisfy the expected values of the tests, or updating the test files so they assert the same unified controller behavior.

### [Minor] Finding 2: Lack of Constant-Time Comparison for Webhook Tokens

- **What**: Webhook token validation uses loose/standard comparison (`===`) instead of constant-time string comparison.
- **Where**: `ui-interface/app/Controllers/Api.php` (line 27)
- **Why**: Standard comparison of strings can leak timing information, potentially allowing token recovery in theory (though difficult in remote network settings).
- **Suggestion**: Use `hash_equals($project['webhook_token'], $token)` for comparing webhook tokens securely.

---

## Verified Claims

- **Bypassing AuthFilter & CSRF** → Verified via inspection of `ui-interface/app/Config/Filters.php` → **PASS**
  - Confirmed `api/webhook/*` is added to the `except` list of both `auth` and `csrf` filters.
- **Token Verification** → Verified via inspection of `ui-interface/app/Controllers/Api.php` → **PASS**
  - Confirmed it checks the URL token against `webhook_token` in `~/.shipit/config.json`.
- **Payload Branch Parsing** → Verified via inspection of `ui-interface/app/Controllers/Api.php` → **PASS**
  - Confirmed it extracts branch correctly by trimming `refs/heads/`.
- **Non-blocking Deployment** → Verified via inspection of `ui-interface/app/Controllers/Api.php` → **PASS**
  - Confirmed it executes `php bin/shipit deploy --log` in background via `shell_exec` and immediately returns 202.

---

## Coverage Gaps

- **E2E / CLI execution of PHPUnit** — risk level: low — recommendation: accept risk / proceed with manual code audit.
  - The permission prompt for executing `run_command` to run `./vendor/bin/phpunit` timed out. This is a workflow constraint, but manual code review was sufficient to trace execution paths and identify the test mismatches.

---

## Unverified Items

- **Actual test suite execution** — Permission prompt timed out.

---

# Adversarial Review

## Challenge Summary

**Overall risk assessment**: MEDIUM

The core challenge lies in the webhook controller's reliance on specific shell command execution patterns (`shell_exec` with background running) and the loose input parsing of incoming Git webhook payloads.

## Challenges

### [Medium] Challenge 1: Shell Execution Failure under Restrictive Environment

- **Assumption challenged**: Assumes `shell_exec` is enabled and that standard Linux backgrounding (`> /dev/null 2>&1 &`) is supported.
- **Attack scenario**: If PHP is configured with `disable_functions = shell_exec` in `php.ini`, the background deployment will fail silently.
- **Blast radius**: Automation webhooks will return 202 but never actually trigger any deployment actions.
- **Mitigation**: Add a check using `function_exists('shell_exec')` and log/return an error if shell execution is disabled on the server.

### [Low] Challenge 2: Type Confusion on Payload Parsing

- **Assumption challenged**: Assumes `ref` in the JSON body is always a string.
- **Attack scenario**: A malicious payload where `ref` is an array or object could trigger a PHP type warning/error in `strpos($ref, 'refs/heads/')`.
- **Blast radius**: Leads to PHP error 500, potentially revealing stack traces if display_errors is enabled.
- **Mitigation**: Check that `is_string($json['ref'])` before running `strpos()`.

## Stress Test Results

- **Ping event payload** → Controller returns `reason: 'ping event'` → `WebhooksTest.php` expects `reason: 'branch mismatch or non-push event'` → **FAIL**
- **Branch mismatch payload** → Controller returns `status: 'skipped'` → `WebhooksTest.php` expects `status: 'ignored'` → **FAIL**
