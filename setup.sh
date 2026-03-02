#!/bin/bash
# ==========================================================
# QuickSite — Post-Clone Setup Script
# ==========================================================
#
# Run this after cloning to match the public folder name
# to your virtual host DocumentRoot.
#
# Usage:
#   chmod +x setup.sh
#   ./setup.sh                        # interactive
#   ./setup.sh www.example.com        # direct rename
#
# What it does:
#   - Renames "public/" to match your vhost (e.g. www, public_html)
#   - Updates PUBLIC_FOLDER_NAME in init.php
#
# Everything else (config files, nginx routing, admin panel)
# is handled automatically on first page load.
# ==========================================================

set -euo pipefail

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PUBLIC_DIR="$SCRIPT_DIR/public"
PUBLIC_FOLDER_NAME="public"

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${BOLD}       QuickSite Setup${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""

NEW_PUBLIC_NAME="${1:-}"

if [ -z "$NEW_PUBLIC_NAME" ]; then
    echo "  Your vhost DocumentRoot should point to the public folder."
    echo "  If your vhost expects a specific name (e.g. 'www',"
    echo "  'www.example.com', 'public_html'), you can rename it now."
    echo ""
    echo -e "  Current name: ${BOLD}public${NC}"
    echo ""
    read -p "  New name (Enter to keep 'public'): " NEW_PUBLIC_NAME
fi

if [ -z "$NEW_PUBLIC_NAME" ] || [ "$NEW_PUBLIC_NAME" = "public" ]; then
    echo -e "  ${GREEN}✓${NC} Keeping 'public'"
    PUBLIC_FOLDER_NAME="public"
elif echo "$NEW_PUBLIC_NAME" | grep -q '[/\\]'; then
    echo -e "  ${RED}✗ Error: folder name cannot contain slashes${NC}"
    exit 1
elif [ "$NEW_PUBLIC_NAME" = "secure" ]; then
    echo -e "  ${RED}✗ Error: cannot use 'secure' as the public folder name${NC}"
    exit 1
elif [ ! -d "$PUBLIC_DIR" ]; then
    echo -e "  ${RED}✗ Error: 'public' folder not found — already renamed?${NC}"
    # Try to detect existing renamed folder
    for d in "$SCRIPT_DIR"/*/; do
        if [ -f "$d/init.php" ]; then
            PUBLIC_FOLDER_NAME="$(basename "$d")"
            echo -e "  Found existing public folder: ${BOLD}$PUBLIC_FOLDER_NAME${NC}"
        fi
    done
elif [ -d "$SCRIPT_DIR/$NEW_PUBLIC_NAME" ]; then
    echo -e "  ${RED}✗ Error: folder '$NEW_PUBLIC_NAME' already exists${NC}"
    exit 1
else
    echo -e "  Renaming: public → ${BOLD}$NEW_PUBLIC_NAME${NC}"
    mv "$PUBLIC_DIR" "$SCRIPT_DIR/$NEW_PUBLIC_NAME"
    PUBLIC_DIR="$SCRIPT_DIR/$NEW_PUBLIC_NAME"
    PUBLIC_FOLDER_NAME="$NEW_PUBLIC_NAME"
    echo -e "  ${GREEN}✓${NC} Renamed successfully"

    # Update PUBLIC_FOLDER_NAME in init.php
    INIT_FILE="$PUBLIC_DIR/init.php"
    if [ -f "$INIT_FILE" ]; then
        sed -i "s/define('PUBLIC_FOLDER_NAME',\s*'[^']*')/define('PUBLIC_FOLDER_NAME', '$NEW_PUBLIC_NAME')/" "$INIT_FILE"
        echo -e "  ${GREEN}✓${NC} Updated PUBLIC_FOLDER_NAME in init.php"
    fi
fi

# ==========================================================
# File ownership check
# ==========================================================
# PHP (via Apache/nginx + php-fpm) needs write access to secure/
# for auto-creating config files, logs, exports, etc.
# If cloned as root, files must be chown'd to the web server user.

OWNERSHIP_WARNING=""
CURRENT_USER="$(whoami)"

if [ "$CURRENT_USER" = "root" ]; then
    OWNERSHIP_WARNING="yes"
    # Try to detect the web server user
    WEB_USER=""
    # CloudPanel: site user matches the htdocs parent folder name
    HTDOCS_PARENT="$(basename "$(dirname "$SCRIPT_DIR")" 2>/dev/null || true)"
    if id "$HTDOCS_PARENT" &>/dev/null 2>&1; then
        WEB_USER="$HTDOCS_PARENT"
    elif id "www-data" &>/dev/null 2>&1; then
        WEB_USER="www-data"
    elif id "nginx" &>/dev/null 2>&1; then
        WEB_USER="nginx"
    elif id "apache" &>/dev/null 2>&1; then
        WEB_USER="apache"
    fi

    if [ -n "$WEB_USER" ]; then
        echo -e "  ${BOLD}Fixing file ownership...${NC}"
        chown -R "$WEB_USER:$WEB_USER" "$SCRIPT_DIR"
        echo -e "  ${GREEN}✓${NC} Ownership set to ${BOLD}$WEB_USER${NC}"
        OWNERSHIP_WARNING=""
    fi
fi

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${GREEN}${BOLD}  Setup complete${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""
echo "  Public folder: $PUBLIC_FOLDER_NAME"
echo ""

if [ -n "$OWNERSHIP_WARNING" ]; then
    echo -e "  ${RED}${BOLD}⚠ IMPORTANT: File ownership${NC}"
    echo -e "  ${RED}You cloned as root. PHP needs write access to secure/.${NC}"
    echo -e "  ${RED}Run this (replace USER with your web server / site user):${NC}"
    echo ""
    echo -e "    ${BOLD}chown -R USER:USER $(basename "$SCRIPT_DIR")/${NC}"
    echo ""
    echo -e "  ${RED}Common users: www-data (Ubuntu), nginx, apache,${NC}"
    echo -e "  ${RED}or your CloudPanel site user (e.g. quicksite-test)${NC}"
    echo ""
fi

echo "  Next steps:"
echo "    1. Point your vhost DocumentRoot to the public folder"
echo "    2. Restart your web server"
echo "    3. Open http://your-domain/admin/"
echo ""
echo "  Default API token: CHANGE_ME_superadmin_token"
echo "  Config files are auto-created on first page load."
echo "  On nginx, you will see a first-load setup page."
echo ""
