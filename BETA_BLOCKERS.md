# Beta Blockers & Technical Debt

This document outlines the issues and architectural limitations identified in the **Alpha (0.0.3-alpha)** phase that prevent the project from being classified as **Beta** or **Production Ready**.

## 1. Non-Atomic Deployments
**Status:** Critical
**Description:** Currently, ShipIt updates the live site by recursively copying files from a temporary clone directory directly into the root.
**Risk:** If the script times out or a server error occurs mid-copy, the live site is left in a "partially updated" state.
**Required for Beta:** Implement a "Symlink Swap" strategy (deploy to a new timestamped folder and then switch a `current` symlink) to ensure atomicity and true zero-downtime.

## 2. Efficiency of PHP-Native File Loops
**Status:** High
**Description:** The `Filesystem` class uses native PHP `scandir` and `copy` loops for backups and updates.
**Risk:** While portable, this is inefficient for large projects. A project with thousands of files may hit PHP memory limits or execution timeouts.
**Required for Beta:** Detect and utilize system-level tools like `rsync` or `tar` when available on the server, falling back to PHP only when necessary.

## 3. Hardcoded Default Ignore Lists
**Status:** Medium
**Description:** Many default ignore patterns (e.g., `vendor`, `node_modules`, `.git`) are hardcoded in the `ShipIt` class.
**Risk:** This limits flexibility for non-standard project structures where these directories might need to be handled differently.
**Required for Beta:** Move all default ignore logic to a centralized, customizable configuration schema or a default `.deployignore` template.

## 4. Configuration Schema Stability
**Status:** Medium
**Description:** The `config.json` schema is still evolving.
**Required for Beta:** Formalize the configuration schema and provide a migration path or robust validation for breaking changes between versions.
