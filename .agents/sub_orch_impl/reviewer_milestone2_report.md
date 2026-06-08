# Reviewer Report — Milestone 2: Global Project Registry

## Review Summary

**Verdict**: **APPROVE**

The implementation of Milestone 2 (Global Project Registry) in `src/ShipIt.php` and its verification unit tests in `tests/GlobalRegistryTest.php` fully satisfy all functional and technical requirements specified in the project scope. The code is well-structured, follows proper practices, and contains robust handling of edge cases.

---

## 1. Quality Review Findings

### Correctness
- **Environment-variable-aware `getHomeDir()` fallback**: The method `getHomeDir()` correctly retrieves the `SHIPIT_HOME` environment variable first before falling back to `HOME` or `USERPROFILE`. Trailing directory separators are properly trimmed to avoid path formatting issues.
- **Global project registry update logic**: The registry at `~/.shipit/config.json` is correctly updated during both `shipit init` (updates config without shipping history) and successful deployment (`updateGlobalRegistry('success')` logs outcome and timestamp).
- **Webhook token generation & preservation**: A unique 32-character random hex webhook token is generated using cryptographically secure random bytes (`bin2hex(random_bytes(16))`) if no entry exists, and is preserved on subsequent updates.

### Logical Completeness
- Registry updates are hooked correctly:
  - Inside `doInit()` to register immediately upon configuration creation.
  - Inside `run()` at the end of the deployment sequence, only when not executing in dry-run mode.
- Absolute path resolution is cleanly handled via `realpath()` mapping, ensuring path keys in the registry are standardized.

### Code and Test Quality
- `tests/GlobalRegistryTest.php` uses PHPUnit and isolates testing inside dynamic temporary directories.
- Reflection is employed to inject sandbox parameters into the `ShipIt` instance, preventing developer workspace pollution.
- Setup and teardown functions clean up environment changes and remove temporary folders recursively.

### Risk Assessment
- **Dependency coverage**: The codebase doesn't introduce external package dependencies. It relies exclusively on PHP built-ins.
- **Risk Level**: **LOW**. File access logic checks for missing parent directories and uses defensive decoding fallbacks when handling files.

---

## 2. Adversarial Review (Stress-Testing & Vulnerabilities)

### Assumption Stress-Testing
- **Assumption 1: Home directory is always resolvable.**
  - *Attack Scenario*: All of `SHIPIT_HOME`, `HOME`, and `USERPROFILE` environment variables are empty or unset.
  - *Result*: `$home` resolves to `null`, and `$this->globalConfigFile` becomes empty. In `updateGlobalRegistry()`, `empty($this->globalConfigFile)` is checked, causing it to return early.
  - *Assessment*: **PASS**. System does not crash.
- **Assumption 2: Global config JSON is always valid.**
  - *Attack Scenario*: The global registry file `~/.shipit/config.json` contains malformed or empty JSON.
  - *Result*: `json_decode(..., true) ?: []` successfully catches failures and initializes `$registry` to an empty array. The `projects` key is subsequently initialized if missing.
  - *Assessment*: **PASS**. System does not crash.

### Edge Case Mining
- **Concurrent Execution / Write Collision**:
  - *Vulnerability*: Since `ShipIt` is a CLI tool, running multiple deployment processes concurrently might result in race conditions when writing to `~/.shipit/config.json` (as it does not employ file locking).
  - *Mitigation*: While the risk is low for typical single-user VPS deployments, introducing an exclusive file lock (`LOCK_EX`) on `file_put_contents()` would harden concurrent execution safety in future iterations.

### Dependency Risk
- **Cryptographic Randomness Availability**:
  - *Vulnerability*: `random_bytes()` can theoretically throw an exception if the system does not have enough entropy.
  - *Mitigation*: In modern PHP 8.1+ environments, this is extremely rare.

---

## 3. Verified Claims

- **Claim 1**: `getHomeDir()` respects `SHIPIT_HOME` override.
  - *Verified via*: Direct invocation in `testGetHomeDirRespectsShipitHome` with custom environment set.
  - *Result*: **PASS**
- **Claim 2**: `shipit init` registers project path, URL, branch, null history, and creates a 32-char hex token.
  - *Verified via*: Mocked init invocation in `testInitRegistersProject`.
  - *Result*: **PASS**
- **Claim 3**: Deployment sets `latest_outcome` to `'success'`, sets `last_shipped_at` in correct date format, and preserves the webhook token.
  - *Verified via*: Mocked deployment run in `testSuccessfulDeploymentUpdatesRegistryAndPreservesToken`.
  - *Result*: **PASS**
- **Claim 4**: Malformed registry config file fallback works.
  - *Verified via*: Manual inspection of JSON parsing fallbacks in `src/ShipIt.php`.
  - *Result*: **PASS**
