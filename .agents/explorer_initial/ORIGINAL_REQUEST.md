## 2026-06-05T00:52:57Z
Explore the ShipIt codebase and the server environment to determine:
1. How ShipIt handles global and local configurations, and where we should hook the registry logic (R1).
2. How to implement Unix/Linux system user authentication in PHP under CodeIgniter 4 on the server (R3). Check if PHP posix functions, PAM extension, `pwauth` utility, SSH loopback authentication, or `su` wrapper are available, and check what system users exist on this local environment.
3. How to structure and setup CodeIgniter 4 in `ui-interface/` (R6). How we can configure/bootstrap it, and how to verify it.
4. How the dashboard web server can execute `shipit` commands (deploy/rollback) while displaying real-time command output logs (R4).
5. How webhooks can be structured (R5).
6. Verify what PHP/composer dependencies we have and if CI4 is already present or needs to be installed.

Write your findings to /home/ian/Desktop/Packages/shipit/.agents/explorer_initial/handoff.md and notify me when complete.
