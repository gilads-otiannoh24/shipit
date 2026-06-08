# Worker Report: Milestone 3 — CI4 UI & Authenticator

## 1. CodeIgniter 4 Initialization
- Successfully initialized the CodeIgniter 4 framework inside the `ui-interface/` directory using Composer cache.
- Configured PHP autoloader dynamically in `ui-interface/app/Config/Autoload.php` to point the `ShipIt\` namespace to `ROOTPATH . '../src'` (which maps to the core code directory).

## 2. Linux System User Authentication
Created `ui-interface/app/Libraries/SystemAuthenticator.php`:
- First checks for the `pwauth` utility in `/usr/sbin/pwauth`, `/usr/bin/pwauth`, or in the PATH. If found, uses `proc_open` to pipe credentials to its stdin and checks for an exit code of `0`.
- Fallback: If `pwauth` is missing, it falls back to SSH loopback verification. If the PHP `ssh2` extension is loaded, it attempts `ssh2_connect('127.0.0.1')` and `ssh2_auth_password()`.
- Second Fallback: If `ssh2` is not loaded, it checks for `sshpass` utility in PATH, running a loopback ssh login test securely by passing the password through the `SSHPASS` environment variable.
- Handles all potential process/extension exceptions gracefully, returning a boolean.

## 3. Auth Controller and Views
- Created `ui-interface/app/Controllers/Auth.php` handling:
  - `GET /login`: Displays a clean login page.
  - `POST /login`: Receives `username` and `password`, authenticates, sets session variables (`logged_in => true`, `username => ...`), and redirects to `/`.
  - `GET /logout`: Clears the session keys and destroys the session, redirecting to `/login`.
- Created view `ui-interface/app/Views/login.php` with a styled login form and error alert box displaying authentication failures.

## 4. Route Protection Filter
- Created `ui-interface/app/Filters/AuthFilter.php` to intercept incoming requests and redirect unauthorized users (without `logged_in => true` in session) to `/login`.
- Registered `auth` filter alias and configured global application filters in `ui-interface/app/Config/Filters.php` to protect all routes globally except `login`, `logout`, and `api/webhook/*`.

## 5. Environment, Routing, and Dashboard
- Configured routes in `ui-interface/app/Config/Routes.php` mapping root route `/` to `Dashboard::index` and login/logout requests to `Auth::login` and `Auth::logout`.
- Created `ui-interface/app/Controllers/Dashboard.php` loading registered projects from the global configuration registry at `~/.shipit/config.json`.
- Created `ui-interface/app/Views/dashboard.php` showing names, paths, git details, branches, and outcomes of registered projects, complete with user session display and a logout button.

## 6. Verification & Test Suite Results
Wrote two test files in the `ui-interface/tests/app/` folder:
1. `tests/app/Libraries/SystemAuthenticatorTest.php`: unit tests mocking system paths and command execution to check pwauth/ssh2/sshpass paths.
2. `tests/app/Controllers/AuthTest.php`: integration tests verifying login view, authentication POST (successful and failed), logout flows, and AuthFilter route protection.

### Test Output
Executed `vendor/bin/phpunit` in `ui-interface/`:
```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.6 with PCOV 1.0.12
Configuration: /home/ian/Desktop/Packages/shipit/ui-interface/phpunit.dist.xml

.................                                                 17 / 17 (100%)

Time: 00:00.154, Memory: 20.00 MB

OK (17 tests, 32 assertions)
```
All 17 tests passed successfully.
