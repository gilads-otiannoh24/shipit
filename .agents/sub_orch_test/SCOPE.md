# Scope: E2E Testing Track

## Architecture
- **E2E Test Runner Harness**: A script (`tests/e2e/run.php`) that initializes a temporary test environment, sets custom environment variables (e.g. `SHIPIT_HOME` to isolate `config.json` changes), starts a background test server (`php -S localhost:8888`), runs PHPUnit on the E2E test suite, and clean up.
- **Opaque-Box Tests**: Written using PHPUnit, placed in `tests/e2e/`. Tests must only interact with the application via command execution (`bin/shipit`) and HTTP requests to the test server, plus checking filesystem side effects.
- **Test Categories**:
  - **Tier 1 (Feature Coverage)**: Happy-path tests for Registry, Dashboard UI, System User Authentication, Remote Actions, and Webhooks.
  - **Tier 2 (Boundary & Corner Cases)**: Edge cases, invalid inputs, authentication failures, missing parameters.
  - **Tier 3 (Cross-Feature/Pairwise)**: Interaction scenarios (e.g., login -> init project -> trigger deploy -> check dashboard -> trigger rollback).
  - **Tier 4 (Real-World Scenarios)**: Complex workflows (e.g., end-to-end webhook push deploying and then checking UI logs and status).

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| 1 | Test Harness & Infra | Create `TEST_INFRA.md` and implement test runner harness (`tests/e2e/run.php`) | None | DONE |
| 2 | Tier 1 Feature Coverage | Implement Tier 1 E2E tests (>= 5 cases per feature) | M1 | DONE |
| 3 | Tier 2 Boundary & Corner Cases | Implement Tier 2 E2E tests (>= 5 cases per feature) | M2 | DONE |
| 4 | Tier 3 & Tier 4 Scenarios | Implement Tier 3 and Tier 4 E2E tests | M3 | DONE |
| 5 | Publishing & Validation | Run tests (verify failure reporting) and publish `TEST_READY.md` | M4 | IN_PROGRESS |

## Interface Contracts
### E2E Test runner environment contracts:
- `SHIPIT_HOME`: Directory path used by `shipit` CLI and web app to read/write `config.json`.
- `TEST_SERVER_URL`: Base URL of the running web server (e.g., `http://localhost:8888`).
- `TEST_USER_USERNAME` & `TEST_USER_PASSWORD`: Test credentials for system authentication simulation.
