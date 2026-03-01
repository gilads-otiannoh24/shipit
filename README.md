# ShipIt
The missing bridge between Git and Shared Hosting/VPS.

ShipIt is a lightweight, zero-dependency PHP deployment orchestrator designed for developers who want professional CI/CD workflows (hooks, backups, rollbacks, and environment management) on servers managed by DirectAdmin, cPanel, or raw VPS.

## Why use ShipIt?
Zero-Downtime Mentality: Automatic backups before every update.

Environment Protection: Never accidentally overwrite your .env or user-uploaded content again.

Framework Aware: Built-in adapters for CodeIgniter 4, Laravel, and React.

Permission Fixer: Automatically handles chown and chmod for webserver users.

Dead Simple: No Docker, no Kubernetes, no complex YAML—just PHP and Git.

## Server Requirements
ShipIt is designed to run natively on Linux-based environments (VPS, Dedicated, or Shared Hosting with SSH access like DirectAdmin / cPanel). 

Your server must have:
- **PHP 8.1+** installed and accessible via CLI.
- **Git** installed and authenticated (e.g., SSH keys added so `git clone` can run without interactive password prompts).
- **Composer** and **NPM** installed (if your deployment hooks require them).
- **SSH / Terminal access** to run the deployment script.

## Quick Start
Install ShipIt via Composer:
```bash
composer require gilads-otiannoh254/shipit
```

Create a .deploy/config.json file in your project root:
```json
{
    "adapter": "codeigniter",
    "server": "ian@ian.com",
    "gitRepoUrl": "git@github.com:gilads-otiannoh254/shipit.git",
    "branch": "master",
    "user": "ian",
    "group": "ian",
    "ownership": [
        "public",
        "public_html",
        "storage",
        "uploads"
    ],
    "permissions": [
        "public_html",
        "storage",
        "uploads"
    ],
    "hooks": {
        "pre-update": "php artisan cache:clear",
        "post-update": "php artisan cache:clear"
    }
}
```

## Running on a Server

Once ShipIt is installed via Composer, you can trigger a deployment directly from your server's terminal (e.g. via SSH).

Navigate to your project root (where your `composer.json` and `.deploy` folder live) and run:

```bash
vendor/bin/shipit
```

### Available Commands and Options

- `vendor/bin/shipit` - Run the full deployment sequence (Backup -> Update -> Composer -> NPM -> Symlink -> Permissions).
- `vendor/bin/shipit rollback` - Automatically restore the application from the last successful backup.
- `vendor/bin/shipit list` - Display all available deployment tasks.
- `vendor/bin/shipit --dry-run` - Simulate the deployment process and see exactly which files would be copied/deleted, without modifying anything on disk.
- `vendor/bin/shipit --only=update,composer` - Run *only* the specified tasks.
- `vendor/bin/shipit --ignore=npm,symlink` - Run all default tasks *except* the specified ones.
- `vendor/bin/shipit --adapter=ci4` - Override the adapter defined in `config.json` on the fly.

### Setting up Auto-Deployments (Webhooks / Cron)

To trigger deployments automatically when you push to Git:

1. **Option 1: Using a webhook listener.** You can create a simple PHP script exposed publicly (e.g., `deploy.php`) that runs `shell_exec('cd /path/to/project && vendor/bin/shipit > deploy.log 2>&1');` when a payload is received from GitHub/GitLab. Make sure to secure this endpoint with a secret token!
2. **Option 2: Cron Job.** If you prefer periodic polling, set up a cron job on your server to run `vendor/bin/shipit` on a schedule. Because ShipIt checks if Git cloning is needed, however, a webhook is highly recommended for efficiency.

## How it works under the hood

When you run `vendor/bin/shipit`:
1. **Backup**: Your current directory is zipped/copied to `../../domain_backups/` relative to your project root.
2. **Clone**: Your configured Git repository branch is cloned into a temporary folder.
3. **Merge**: Files are copied over your structure, excluding anything listed in `.deployignore` or standard ignores (like `.env`, `vendor/`, `storage/`).
4. **Build**: Composer and NPM hooks run.
5. **Permissions**: File and folder ownership and permissions are automatically enforced according to your `config.json`.

## The `.deployignore` File

By default, ShipIt already ignores common files during updates (e.g., `.env`, `vendor`, `node_modules`, `.git`, `public_html`). 

If you have specific files or folders in your Git repository that should **not** be copied to your live server during a deployment, you can place a `.deployignore` file in any directory. 

The syntax is similar to `.gitignore`. Each line represents a pattern to exclude:

```text
# Ignore specific files
docker-compose.yml
phpunit.xml

# Ignore entire directories
tests/
dev-tools/

# Ignore files matching a pattern
*.log
*.sqlite
```

ShipIt traverses your folders and recursively applies any `.deployignore` files it finds during the update process. Use the `vendor/bin/shipit --dry-run` flag to safely verify that your ignore patterns are working correctly before doing a live deployment!

## Security Best Practices

- **Never expose `.deploy/` to the web:** Best practice is to keep your root directory (where `composer.json` and `.deploy` live) *above* your public document root (e.g. `/home/user/domains/domain.com/` while the web root is `/home/user/domains/domain.com/public_html`). 
- **Protect Webhooks:** If you use a webhook PHP script to trigger deployments, secure the endpoint using a secret token verify from GitHub/GitLab. Do not leave the webhook URL easily guessable.
- **SSH Keys vs Passwords:** Always authenticate the server against the Git provider using Deploy Keys or SSH keys instead of hardcoding passwords or tokens in URLs.

