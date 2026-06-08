# Handoff Report: Initial Exploration of ShipIt & Server Environment

## 1. Observation

### 1.1 Configuration Files and Paths
- **Global Config Path**: `src/ShipIt.php` lines 65–66:
  ```php
  $home = $this->getHomeDir();
  $this->globalConfigFile = $home ? $home . DIRECTORY_SEPARATOR . '.shipit' . DIRECTORY_SEPARATOR . 'config.json' : '';
  ```
- **Local Config Path**: `src/ShipIt.php` lines 62–63:
  ```php
  $this->deployDir = $this->rootDir . '/.deploy';
  $this->configFile = $this->deployDir . '/config.json';
  ```
- **Configuration Loading Logic**: `src/ShipIt.php` lines 209–254 (`loadConfig()`) merges the defaults, global config, and local config:
  ```php
  $globalConfig = [];
  if (!empty($this->globalConfigFile) && file_exists($this->globalConfigFile)) {
      $globalConfig = json_decode(file_get_contents($this->globalConfigFile), true) ?: [];
  }
  ...
  if (file_exists($this->configFile)) {
      $loaded = json_decode(file_get_contents($this->configFile), true) ?: [];
      $this->config = array_merge($defaultConfig, $globalConfig, $loaded);
  }
  ```
- **Deployment Completion Logic**: `src/ShipIt.php` lines 154–157 writes local configuration status:
  ```php
  if (!$this->dryRun) {
      $this->config['last_shipped_at'] = date('Y-m-d H:i:s');
      file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }
  ```
- **Configuration Init Logic**: `src/ShipIt.php` lines 950–994 (`doInit()`) handles core skeleton initialization.

### 1.2 User Context and Permissions
- **Active Workspace Directory**: `/home/ian/Desktop/Packages/shipit`
- **Primary Host User**: `ian`
- **Root Dependencies**: `composer.json` lines 6–8 specify the PHP version constraint:
  ```json
  "require": {
      "php": ">=8.1"
  }
  ```
- No other core libraries are listed in `composer.json`.

---

## 2. Logic Chain

### 2.1 Configuration and Hooking Registry Logic (R1)
1. Since the global configuration file is resolved to `~/.shipit/config.json`, this is the ideal location to store the centralized registry of projects.
2. A new `projects` key in the global JSON object can hold an associative array of project details, keyed by their absolute directories (e.g. `{"projects": {"/path/to/project": {"path": "...", "gitRepoUrl": "...", "branch": "...", "last_shipped_at": "..."}}}`).
3. To hook the registry logic:
   - For `shipit init`, we should update the registry inside `doInit()` immediately after the config file is written (around line 976, inside the `init` target block).
   - For deployments, we should call an update function right after the deployment completes successfully (around line 157 in `src/ShipIt.php` after writing the local `last_shipped_at` key).

### 2.2 Unix/Linux System User Authentication in PHP (R3)
1. **POSIX Functions**: Functions like `posix_getpwnam()` retrieve user metadata (UID/GID) but cannot read password hashes from `/etc/shadow` because of security constraints. They are insufficient for credential verification.
2. **PAM Extension**: Requires the PECL `pam` extension, which is rarely installed by default, and requires configuring root-owned PAM service definitions (e.g., `/etc/pam.d/php`), making it complex to configure.
3. **`pwauth` Utility**: It is standard, suid-root, and securely validates user passwords against `/etc/shadow`. It can be run from PHP using `proc_open` by piping credentials to `stdin` and checking for exit status `0`. This is highly robust if `pwauth` is installed.
4. **SSH Loopback Authentication**: If the SSH daemon is running and permits password auth on `localhost`, PHP can trigger a connection attempt via `ssh2_auth_password()` or a system call to `sshpass` (e.g. `ssh -o PreferredAuthentications=password`). This is highly portable as it bypasses the need for SUID binaries or PAM modules.
5. **System User**: The local system environment primary user is `ian`.

### 2.3 CodeIgniter 4 UI-Interface Structure (R6)
1. Because `ui-interface` is not present in the workspace, we must install a new CI4 project skeleton inside `ui-interface/` using Composer (`composer create-project codeigniter4/appstarter ui-interface`).
2. Bootstrapping with ShipIt core files requires autoloading the `ShipIt\` namespace. We can link it inside `ui-interface/composer.json`:
   ```json
   "autoload": {
       "psr-4": {
           "App\\": "app/",
           "Config\\": "app/Config/",
           "ShipIt\\": "../src/"
       }
   }
   ```
3. To configure the instance, we create `ui-interface/.env` and define standard values (`CI_ENVIRONMENT = development`, `app.baseURL`, etc.).
4. Running the application can be verified by running `php spark serve --port 8080` from `ui-interface/` and testing access via curl or browser.

### 2.4 Real-time Command Execution Logs (R4)
1. Streaming logs directly in one HTTP request via Server-Sent Events (SSE) using `proc_open` and `flush()` is simple, but web servers limit execution time (`max_execution_time`), and closing the browser will terminate the process.
2. The recommended architecture is **asynchronous execution + log polling**:
   - The user triggers deploy/rollback.
   - The CI4 backend starts `bin/shipit` in the background, redirecting stdout and stderr to a dedicated log file (`php bin/shipit deploy > writable/logs/deploy_<id>.log 2>&1 &`).
   - The frontend reads logs incrementally using an endpoint (e.g. `/projects/logs/<id>`) through EventSource (SSE) tailing the log file or Ajax polling, keeping deployments resilient to connection losses.

### 2.5 Webhook Structure (R5)
1. Register a dynamic route in CI4: `POST /api/webhook/(:any)`.
2. The endpoint authenticates requests using a unique random token associated with each project profile (e.g. `POST /api/webhook/token_xyz`).
3. Payload check: Parse standard webhook payloads from GitHub/GitLab, identify the target branch (e.g. `ref` must match the configured deployment branch like `refs/heads/main`).
4. To prevent timeout errors in Git providers (which wait at most 10 seconds), the webhook controller must spawn the `deploy` task asynchronously in the background and immediately return an HTTP 202 Accepted status.

---

## 3. Caveats

- **System Permissions**: If the webserver runs under a restricted user (e.g. `www-data`), it may not have permission to access the home folder (`/home/ian/.shipit/config.json`) of user `ian`. Running the web interface under the local user (using `php spark serve` as `ian`) is required to avoid permission issues.
- **PAM/pwauth Availability**: System-level commands like `pwauth` may require package installation (`apt install pwauth`). SSH loopback is recommended as a fallback since it only requires an active SSH service.
- **No external network access**: Because this agent operates in CODE_ONLY mode, package installations and dependency checks must rely on local sources.

---

## 4. Conclusion

1. **Configurations & Registry (R1)**: We should hook registry updates in `src/ShipIt.php` during `doInit()` and at the end of the `run()` deployment sequence, modifying `~/.shipit/config.json`.
2. **Authentication (R3)**: Implement Unix authentication using either `pwauth` (via `proc_open` stdin pipe) or SSH loopback to `127.0.0.1` (using `sshpass` or `ssh2`).
3. **CI4 Setup (R6)**: Set up CodeIgniter 4 in `ui-interface/` using a clean composer layout, registering routes, and adding custom namespaces for `ShipIt\` pointing to `../src/`.
4. **Action Logging (R4)**: Run `shipit` commands in the background redirecting to log files, then serve those logs via SSE/Polling to avoid connection drop interruptions.
5. **Webhooks (R5)**: Trigger non-blocking deployments asynchronously on matching branch Git push events, secured by a unique project URL token.
6. **Dependencies (R6)**: CodeIgniter 4 is currently absent in the workspace; we must install it under `ui-interface/`.

---

## 5. Verification Method

### 5.1 Registry Integration
- Create a test target directory, run `php bin/shipit init` inside it, and verify that the directory absolute path has been appended to `~/.shipit/config.json`.
- Execute a deployment dry run (`php bin/shipit deploy --dry-run`) to ensure no unexpected configuration corruption.

### 5.2 CI4 UI and Auth Verification
- Initialize `ui-interface/` with CI4 code.
- Run the server:
  ```bash
  php ui-interface/spark serve --port 8080
  ```
- Send a request using `curl -I http://localhost:8080/` to verify that the CI4 bootstrapping works and redirects unauthorized requests.
