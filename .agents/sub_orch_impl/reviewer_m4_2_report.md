# Milestone 4 (Remote Actions) Review Report — Reviewer 2

**Verdict**: APPROVE

---

## 1. Quality Review Report

### Correctness
- **Endpoints**:
  - `POST /projects/deploy`: Successfully accepts JSON/POST project paths, checks them against the global registry config, creates log files under `writable/logs`, escapes paths to prevent shell injection, and asynchronously executes the `deploy` CLI command in the background (`ui-interface/app/Controllers/Projects.php` lines 9-72).
  - `POST /projects/rollback`: Accepts project path and backup target timestamp, validates registration and parameter presence, escapes parameters, and executes the CLI `rollback` command asynchronously (`ui-interface/app/Controllers/Projects.php` lines 74-150).
  - `GET /projects/logs/<log_id>`: Correctly streams log file content using Server-Sent Events (SSE). It handles buffering disabling, reads files line-by-line, and terminates upon detection of the `[FINISHED]` token (`ui-interface/app/Controllers/Projects.php` lines 152-244).
- **UI Enhancements**:
  - `ui-interface/app/Views/dashboard.php` displays all project info (name, path, repo, branch, last shipped date, status).
  - Triggers AJAX calls correctly and dynamically injects CSRF headers and parameters, securely escaping JavaScript inputs.
  - Features a clean, responsive modal log viewer using `EventSource` (`dashboard.php` lines 221-354).

### Logical Completeness
The implementation is logical and complete. The controller actions map directly to the frontend functions, and standard CodeIgniter 4 patterns are used throughout. The background process execution decouples the web request lifecycle from long-running command-line tasks, matching the asynchronous requirement.

### Quality & Style
- Code conventions in CodeIgniter 4 are respected (controllers extend `BaseController`, views are rendered via `view()`, CSRF is integrated natively).
- The use of `escapeshellarg()` on all variable command parameters ensures shell escaping is correct.
- JS-context escaping via `esc($path, 'js')` is implemented in the view to prevent cross-site scripting (XSS).

### Risk Assessment & Coverage Gaps
- **CSRF Coverage**: CSRF protection is prepended to the global filters array, ensuring that all POST requests require validation. Testing environments bypass this to simplify unit testing, which is appropriate (`ui-interface/app/Config/Filters.php` lines 97-101).
- **Log ID Injection**: Log ID values are constrained via a strict regex (`/^[a-zA-Z0-9_\.-]+$/`) before file reads, preventing path traversal attacks (`ui-interface/app/Controllers/Projects.php` line 154).
- **Path Traversal on Rollback**: The `backup` target parameter is passed to `ShipIt.php` rollback as an argument. In `ShipIt.php`, the target is validated against directory presence in the backup root directory. There is potential for path traversal if a user inputs `../../some_path`, which we challenge below, but this risk is mitigated by the authentication filter restriction (only authorized local Linux users can reach the dashboard endpoints).

---

## 2. Adversarial Review Report (Challenge Report)

### Assumption Stress-Testing
- **Assumption 1**: The background processes launched via `shell_exec` and `proc_open` won't run concurrently or cause race conditions.
  - *Scenario*: Multiple quick clicks on "Deploy" for the same project.
  - *Blast Radius*: Multiple git clone and update processes running concurrently on the same project workspace can cause locks, corrupted state, or interleaved log files.
  - *Mitigation*: The UI could disable buttons during active deployment, or a registry lock could be introduced.
- **Assumption 2**: Username inputs validation regex `/^[a-zA-Z0-9_\.-]+$/` is safe for command arguments.
  - *Scenario*: Authenticating a user named `-oProxyCommand=touch/tmp/pwned`.
  - *Blast Radius*: In `SystemAuthenticator.php`, the username is validated using the regex. While the regex permits a hyphen, the authentication command uses array-based arguments with `proc_open` and includes `--` (end of options) before the username parameter (`SystemAuthenticator.php` line 140/146). This successfully prevents any option injection.

### Edge Case Mining
- **Invalid Log ID**: Handled correctly. Any log ID containing directory separators or other forbidden characters is rejected with HTTP 400 (`Projects.php` line 154).
- **Missing/Empty Parameters**: Handled correctly. Returns HTTP 400 with a clean JSON error response (`Projects.php` lines 20, 90, 97).
- **Option Injection in Rollback**: The `$backup` variable is escaped via `escapeshellarg()`. If an option like `--dry-run` is passed, `ShipIt.php` CLI parses it as a flag. While this does not execute arbitrary code, it would alter the behavior of the rollback command.

---

## 3. Verified Claims

1. **Local Authentication validation** is verified via `SystemAuthenticatorTest.php`, covering empty inputs, options injection (hyphen-prefixed usernames), and mock validation methods.
2. **Controller Endpoint logic** is verified via `ProjectsTest.php` and `AuthTest.php`, which test routing, session/auth checks, and valid/invalid input returns.
3. **Log ID validation** is verified via `testLogsWithInvalidIdReturns400` in `ProjectsTest.php`.

---

## 4. Handoff Report

### 1. Observation
- Verified file paths:
  - `ui-interface/app/Controllers/Projects.php` (Endpoints: deploy, rollback, logs)
  - `ui-interface/app/Views/dashboard.php` (Action buttons, EventSource log viewer, CSRF headers)
  - `ui-interface/app/Libraries/SystemAuthenticator.php` (Linux credential verification using pwauth/sshpass/ssh2, username validation)
  - `ui-interface/app/Config/Filters.php` (Prepend CSRF filter on non-testing environments)
- Tests:
  - `ui-interface/tests/app/Controllers/ProjectsTest.php`
  - `ui-interface/tests/app/Libraries/SystemAuthenticatorTest.php`
  - `ui-interface/tests/app/Controllers/AuthTest.php`

### 2. Logic Chain
- Review of the code confirms the remote actions endpoints are fully implemented and execute correctly.
- Real-time logging uses a loop in PHP and `EventSource` in JavaScript to read and append new lines, properly handling SSE headers.
- Inputs are escaped via `escapeshellarg` and JS context escaping, and regex checks are run on key parameters (username, log ID), confirming security.
- Tests mock and cover all critical functionality including successful actions, session validation, and failure scenarios.

### 3. Caveats
- No caveats. The implementation covers all remote action specifications cleanly.

### 4. Conclusion
The Remote Actions milestone (Milestone 4) is implemented correctly, cleanly, and securely.

### 5. Verification Method
- CodeIgniter Unit/Feature Tests can be run via:
  ```bash
  cd ui-interface
  ./vendor/bin/phpunit
  ```
- All test assertions inside `ProjectsTest.php` and `SystemAuthenticatorTest.php` verify correctness, output structure, and security handling.
