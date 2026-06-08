# ShipIt Test & Deployment Sandbox Visualizer

This directory contains a complete interactive web dashboard and sandbox to visualize, simulate, and test the ShipIt deployment workflow alongside running PHPUnit test assertions.

## Directory Structure

- `index.html`: A beautiful, premium, single-page web dashboard using CSS glassmorphism, responsive grid layout, and step-by-step animations.
- `api.php`: The backend API that handles workspace scanner, runs actual git commits, invokes `bin/shipit` or `shipit rollback`, and executes/parses PHPUnit tests.
- `repo/`: Mock local Git repository folder representing the source code structure.
- `example.com/`: Mock production target folder representing the webroot/live site.
- `backups/`: Mock backup archive folder demonstrating the rotational scheme.

## How to Run the Visualizer

You can launch a built-in PHP development server targeting this directory:

```bash
php -S localhost:8000 -t tests/_support
```

Once running, navigate to `http://localhost:8000` in your web browser to access the dashboard!

## Features Explained

1. **Sandbox Initialization**: Resets folders and creates a new Git repository in `repo/` with sample files (including `.deployignore`, `composer.json`, `package.json`, `.env`), commits them, and configures `.deploy/config.json`.
2. **Git Commit Simulation**: Select a file, modify its contents, type a commit message, and commit directly. The local Git tree updates dynamically in the sidebar.
3. **Deployment Flow Animation**: Clicking "Run Deployment" executes the actual `bin/shipit` script, triggers steps (Backup -> Clone -> Ignore filtering -> Adapter hook runs -> Chmod/Chown), and updates files on `example.com/` live!
4. **Backup Rotation Timeline**: Demonstrates the backup retention limit. If retention is set to `3`, creating a 4th backup automatically marks the oldest backup as "Pruned next deploy" and deletes it.
5. **PHPUnit visual report**: Invokes the PHPUnit test suite, parses the resulting JUnit XML, and displays clean, expanding test cards showing time spent, status, and assertion diagnostic messages.