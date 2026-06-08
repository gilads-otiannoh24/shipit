## 2026-06-05T11:48:09Z
Please run `ps aux | grep php` to check if there are any active, hung PHP development servers (e.g. `php -S 127.0.0.1`) or PHPUnit processes running. If there are, please identify their PIDs, kill them (using kill -9 if necessary), and report back.
