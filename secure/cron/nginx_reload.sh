#!/bin/bash
# ==========================================================
# QuickSite — nginx configuration reload script (FALLBACK)
# ==========================================================
#
# This script is a FALLBACK for when PHP cannot reload nginx
# directly (e.g., shell_exec disabled, no sudoers configured).
#
# RECOMMENDED: Use the sudoers approach instead (no cron needed):
#   echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx' \
#     | sudo tee /etc/sudoers.d/quicksite-nginx
#   sudo chmod 440 /etc/sudoers.d/quicksite-nginx
#
# With sudoers, QuickSite reloads nginx instantly from PHP.
# This script is only needed if that's not possible.
#
# How it works:
#   1. QuickSite updates secure/nginx/dynamic_routes.conf
#   2. QuickSite writes a flag: secure/nginx/.pending_reload
#   3. This script (running via cron) detects the flag
#   4. Tests nginx config with 'nginx -t'
#   5. If valid: reloads nginx, removes flag
#   6. If invalid: logs error, keeps flag for retry
#
# Setup:
#   1. Make executable:
#      chmod +x /path/to/secure/cron/nginx_reload.sh
#
#   2. Add to root crontab (runs every minute):
#      sudo crontab -e
#      * * * * * /path/to/secure/cron/nginx_reload.sh
#
#   3. Ensure your nginx vhost includes the config:
#      include /path/to/secure/nginx/dynamic_routes.conf;
#
# Logs:
#   Reload attempts are logged to secure/logs/nginx_reload.log
#
# ==========================================================

set -euo pipefail

# Auto-detect paths relative to this script's location
# Script is at: secure/cron/nginx_reload.sh
# Secure dir is: secure/
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SECURE_DIR="$(dirname "$SCRIPT_DIR")"

FLAG_FILE="$SECURE_DIR/nginx/.pending_reload"
LOG_FILE="$SECURE_DIR/logs/nginx_reload.log"

# Exit silently if no pending reload
[ -f "$FLAG_FILE" ] || exit 0

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Test nginx configuration before reloading
if nginx -t 2>>"$LOG_FILE"; then
    # Config is valid — reload nginx
    nginx -s reload
    rm -f "$FLAG_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] OK: nginx reloaded successfully" >> "$LOG_FILE"
else
    # Config test failed — do NOT reload, keep flag for retry after fix
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: nginx -t failed — config NOT reloaded. Fix the nginx config and the next cron run will retry." >> "$LOG_FILE"
fi
