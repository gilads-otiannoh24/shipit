# Original User Request

## Initial Request — 2026-06-05T00:51:46+03:00

An automated central dashboard and registration system for managing multiple ShipIt projects on a single server/VPS, complete with Linux system user authentication, webhook deployment automation, and built using the CodeIgniter 4 (CI4) framework.

Working directory: /home/ian/Desktop/Packages/shipit
Integrity mode: development

## Requirements

### R1. Global Project Registry
Extend `ShipIt`'s core logic to maintain a centralized list of projects in `~/.shipit/config.json`. Any time `shipit init` or a deployment is run, the project's absolute path, Git repository URL, branch, and metadata must be registered or updated in the global list.

### R2. Central Control Panel Web Server
Build a lightweight dashboard web server running on a configurable port. The interface must list all registered projects and display their branch, repository URL, last deploy timestamp, and latest deploy outcome.

### R3. System User Authentication
Secure the control panel utilizing system-level Linux accounts. The login must authenticate users using Unix username and password.

### R4. Remote Deploy/Rollback Actions
Allow users to trigger `shipit` deployments or rollbacks for any registered project directly from the dashboard UI, displaying real-time command output logs.

### R5. Automation Webhooks
Provide unique webhook endpoints for each project to automate deployments on Git push events.

### R6. Framework Constraint (CodeIgniter 4)
The control panel dashboard and its user interface must be developed using the **CodeIgniter 4 (CI4)** framework, situated in a folder named `ui-interface/` located in the project's root directory.

## Acceptance Criteria

### Registry Verification
- [ ] Running `bin/shipit init` in a new directory automatically appends its path and details to `~/.shipit/config.json`.
- [ ] Running a deployment updates the project's `last_shipped_at` status in the global registry.

### Control Panel & Auth Verification
- [ ] The folder `ui-interface/` exists in the project root directory and contains a fully configured CodeIgniter 4 application.
- [ ] Running the dashboard start command launches a server on the designated port serving the CI4 app.
- [ ] Accessing the control panel forces authentication; invalid credentials block access.
- [ ] Authenticated requests can successfully retrieve the list of registered projects.

### Action & Webhook Verification
- [ ] Triggering a deploy via the dashboard UI executes the underlying deployment script and responds with stdout logs.
- [ ] Triggering a POST webhook with a valid project token schedules and runs a deploy successfully.
