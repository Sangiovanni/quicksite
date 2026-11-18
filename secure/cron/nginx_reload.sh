#!/bin/bash
# ==========================================================
# QuickSite — nginx configuration reload (cron fallback, run by root)
# ==========================================================
#
# A FALLBACK for when PHP cannot reload nginx itself (shell_exec disabled, or
# no sudoers rule). The recommended way needs no cron: let the web account run
# the two nginx commands through sudo, and nothing else:
#
#   sudo tee /etc/sudoers.d/quicksite-nginx > /dev/null <<'EOF'
#   www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t
#   www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -s reload
#   EOF
#   sudo chmod 440 /etc/sudoers.d/quicksite-nginx
#   sudo visudo -cf /etc/sudoers.d/quicksite-nginx
#
# Replace www-data with your PHP account, and /usr/sbin/nginx with the path
# `command -v nginx` prints if it is another. Never name nginx without its
# arguments: sudo reads that as ANY arguments, and the web account could start
# nginx as root with a configuration of its own choosing.
#
# ROOT RUNS ITS OWN COPY, KEPT OUTSIDE THE QUICKSITE FOLDER
#
#   The file you are reading belongs to the web account (setup.sh gives it the
#   whole QuickSite folder), which could rewrite it before root's next run:
#   root's crontab must never name it. Instead, as root:
#
#   1. Install a root-owned copy (again after an update that changes this file):
#        install -o root -g root -m 0755 /path/to/secure/cron/nginx_reload.sh /usr/local/sbin/quicksite-nginx-reload
#
#   2. Point root's crontab at the copy (crontab -e), with the install's secure
#      folder as its argument; it runs every minute:
#        * * * * * /usr/local/sbin/quicksite-nginx-reload /path/to/secure
#
# What it does: when QuickSite has left the flag <secure>/nginx/.pending_reload,
# it tests the configuration (nginx -t). When the test passes it reloads nginx
# (nginx -s reload) and removes the flag; when it fails, it keeps the flag for
# the next run. It writes nothing else in the QuickSite folder: every line it
# logs goes to the system log, tagged quicksite-nginx-reload
# (journalctl -t quicksite-nginx-reload, or /var/log/syslog).
# ==========================================================

set -euo pipefail

# nginx itself. If `command -v nginx` prints another path, change it here, in
# your copy, as in the sudoers lines.
NGINX=/usr/sbin/nginx
TAG=quicksite-nginx-reload

log() {
    local message="$*"
    logger -t "$TAG" -- "${message//$'\n'/ | }"
}

SECURE_DIR="${1:-}"
if [ -z "$SECURE_DIR" ] || [ ! -d "$SECURE_DIR" ]; then
    log "ERROR: the argument must be the install's secure folder (got '$SECURE_DIR'); nothing done"
    exit 1
fi
if [ -L "$SECURE_DIR/nginx" ]; then
    log "ERROR: $SECURE_DIR/nginx is a link; nothing done"
    exit 1
fi

FLAG="$SECURE_DIR/nginx/.pending_reload"

# Nothing to do unless QuickSite left the flag, as a plain file. A link in its
# place is refused: root never acts on a file the web account points elsewhere.
if [ ! -e "$FLAG" ] && [ ! -L "$FLAG" ]; then
    exit 0
fi
if [ -L "$FLAG" ] || [ ! -f "$FLAG" ]; then
    log "ERROR: $FLAG is not a plain file; nothing done"
    exit 1
fi

if OUTPUT="$("$NGINX" -t 2>&1)"; then
    if OUTPUT="$("$NGINX" -s reload 2>&1)"; then
        rm -f -- "$FLAG"
        log "OK: nginx reloaded"
    else
        log "ERROR: nginx -s reload failed; the flag is kept for the next run: $OUTPUT"
    fi
else
    log "ERROR: nginx -t failed, nginx NOT reloaded; fix the configuration and the next run retries: $OUTPUT"
fi
