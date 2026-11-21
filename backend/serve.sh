#!/usr/bin/env bash
# Dev API server on 127.0.0.1:8031 (ports 8000/8010 are taken locally).
# Must match API_PROXY_TARGET in frontend/.env.
# Works from Git Bash (/c/...) and from WSL (/mnt/c/...).
cd "$(dirname "$0")" || exit 1
for php in /c/xampp/php/php.exe /mnt/c/xampp/php/php.exe; do
  [ -x "$php" ] && exec "$php" -d extension=sodium -S 127.0.0.1:8031 -t public public/index.php
done
echo "XAMPP PHP not found at /c/xampp or /mnt/c/xampp" >&2
exit 1
