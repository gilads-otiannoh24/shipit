# Milestone 2: Global Project Registry — Worker Report

## 1. Description of Changes

We implemented all tasks required under Milestone 2:

### Core File Modifications (`src/ShipIt.php`)

1. **Environment-variable-aware Home Directory Resolution (`getHomeDir()`):**
   - Modified `getHomeDir()` to check the `SHIPIT_HOME` environment variable first before falling back to `HOME` or `USERPROFILE`.
   - Changed access visibility of `getHomeDir()` to `public` to facilitate direct testing in the test suite.

2. **Global Project Registry Update & Registration (`updateGlobalRegistry()`):**
   - Implemented `public function updateGlobalRegistry(?string $outcome = null): void`.
   - Resolves the absolute path to the project root using `realpath()`.
   - Reads the project configuration from `.deploy/config.json` to fetch `gitRepoUrl` and `branch`.
   - Reads the global registry from `$this->globalConfigFile` (which defaults to `~/.shipit/config.json` or respects `SHIPIT_HOME`).
   - Ensures projects schema maps as specified:
     ```json
     {
       "projects": {
         "/absolute/path/to/project": {
           "path": "/absolute/path/to/project",
           "gitRepoUrl": "git@github.com:user/repo.git",
           "branch": "main",
           "last_shipped_at": "YYYY-MM-DD HH:MM:SS" or null,
           "latest_outcome": "success" or null,
           "webhook_token": "random_hex_string"
         }
       }
     }
     ```
   - Generates a 32-character random hex string for `webhook_token` (using `bin2hex(random_bytes(16))`) if it does not already exist, and preserves the existing token on subsequent runs.
   - If the parent directory `.shipit` does not exist in the home path, it creates it using `mkdir()`.

3. **Lifecycle Integration:**
   - Placed a call to `updateGlobalRegistry()` at the end of standard `shipit init` initialization in `doInit()`.
   - Placed a call to `updateGlobalRegistry('success')` at the end of `run()` upon a successful deployment (only if not running in dry-run mode).

### Unit Tests (`tests/GlobalRegistryTest.php`)

Written a robust unit test suite containing:
1. `testGetHomeDirRespectsShipitHome()`: Verifies environment variable override and fallback to normal default behavior when unset.
2. `testInitRegistersProject()`: Verifies that running `shipit init` registers the project paths, branch, git URL, null shipped timestamps/outcomes, and generates a valid 32-char hex token.
3. `testSuccessfulDeploymentUpdatesRegistryAndPreservesToken()`: Verifies that a successful deployment updates the latest outcome to `'success'`, formats `last_shipped_at` to `YYYY-MM-DD HH:MM:SS`, and preserves the existing webhook token.

---

## 2. Test Execution Details

- Test file written: `/home/ian/Desktop/Packages/shipit/tests/GlobalRegistryTest.php`
- Integration mechanism: Environment variable `SHIPIT_HOME` set in setUp and cleared in tearDown to guarantee a hermetic test run that doesn't overwrite any real user directories.
