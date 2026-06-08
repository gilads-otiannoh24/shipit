# Milestone 1: E2E Testing Infrastructure Implementation Progress

Last visited: 2026-06-05T01:05:15+03:00

## Done
- Created `TEST_INFRA.md` at project root specifying philosophy, mapping, tiers, and criteria.
- Created `tests/e2e/` directory.
- Implemented robust E2E test runner harness `tests/e2e/run.php` supporting isolated HOME/SHIPIT_HOME environment, socket-based port discovery (with fsockopen fallback), background server management, and automatic process/file cleanup.
- Implemented base E2E test case `tests/e2e/ShipItE2ETestCase.php` with cURL HTTP request helpers (with cookie persistence) and sandbox CLI execution methods.
- Implemented basic `tests/e2e/HarnessCheckTest.php` assertion check.
- Modified `phpunit.xml` to exclude `tests/e2e` from `Unit` test suite and add a separate `E2E` test suite pointing to `tests/e2e`.
- Verified `phpunit --testsuite Unit` ignores E2E tests, keeping units isolated.
