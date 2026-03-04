#!/bin/bash
# ==========================================================
# QuickSite — Post-Clone Setup Script
# ==========================================================
#
# Run this after cloning to configure folder names and URL
# prefix before your first page load.
#
# Usage:
#   chmod +x setup.sh
#   ./setup.sh                        # interactive
#   ./setup.sh <public_name>          # rename public folder only
#
# What it does:
#   1. Renames "public/" to match your vhost (e.g. www, public_html)
#   2. Renames "secure/" for obscurity (e.g. backend, app)
#   3. Sets a URL prefix/space (e.g. "web" → http://domain/web/)
#   - Updates init.php constants and .htaccess FallbackResource
#
# Everything else (config files, nginx routing, admin panel)
# is handled automatically on first page load.
# ==========================================================

set -euo pipefail

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PUBLIC_DIR="$SCRIPT_DIR/public"
SECURE_DIR="$SCRIPT_DIR/secure"
PUBLIC_FOLDER_NAME="public"
SECURE_FOLDER_NAME="secure"
PUBLIC_SPACE=""

# Read existing config if available (re-run detection)
CONF_FILE="$SCRIPT_DIR/.quicksite.conf"
if [ -f "$CONF_FILE" ]; then
    source "$CONF_FILE"
    # Backward compat: old conf format used PUBLIC_FOLDER_SPACE
    PUBLIC_SPACE="${PUBLIC_SPACE:-${PUBLIC_FOLDER_SPACE:-}}"
    PUBLIC_DIR="$SCRIPT_DIR/$PUBLIC_FOLDER_NAME"
    SECURE_DIR="$SCRIPT_DIR/$SECURE_FOLDER_NAME"
fi

# Helper: save current state to conf (crash recovery)
save_conf() {
    cat > "$CONF_FILE" << CONFEOF
PUBLIC_FOLDER_NAME=$PUBLIC_FOLDER_NAME
SECURE_FOLDER_NAME=$SECURE_FOLDER_NAME
PUBLIC_SPACE=$PUBLIC_SPACE
CONFEOF
}

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${BOLD}       QuickSite Setup${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""

# ==========================================================
# Step 1: Rename public folder
# ==========================================================
echo -e "${BOLD}Step 1 — Public folder name${NC}"
echo ""

# Auto-detect if configured folder doesn't exist (crash recovery)
if [ ! -d "$PUBLIC_DIR" ]; then
    for d in "$SCRIPT_DIR"/*/; do
        if [ -f "$d/init.php" ] || ls "$d"/*/init.php >/dev/null 2>&1; then
            PUBLIC_FOLDER_NAME="$(basename "$d")"
            PUBLIC_DIR="$SCRIPT_DIR/$PUBLIC_FOLDER_NAME"
            break
        fi
    done
fi

NEW_PUBLIC_NAME="${1:-}"

if [ -z "$NEW_PUBLIC_NAME" ]; then
    echo "  Your vhost DocumentRoot should point to the public folder."
    echo "  If your vhost expects a specific name (e.g. 'www',"
    echo "  'www.example.com', 'public_html'), you can rename it now."
    echo ""
    echo -e "  Current name: ${BOLD}$PUBLIC_FOLDER_NAME${NC}"
    echo ""
    read -p "  New name (Enter to keep '$PUBLIC_FOLDER_NAME'): " NEW_PUBLIC_NAME
fi

if [ -z "$NEW_PUBLIC_NAME" ] || [ "$NEW_PUBLIC_NAME" = "$PUBLIC_FOLDER_NAME" ]; then
    echo -e "  ${GREEN}✓${NC} Keeping '$PUBLIC_FOLDER_NAME'"
elif echo "$NEW_PUBLIC_NAME" | grep -q '[/\\]'; then
    echo -e "  ${RED}✗ Error: folder name cannot contain slashes${NC}"
    exit 1
elif [ "$NEW_PUBLIC_NAME" = "$SECURE_FOLDER_NAME" ]; then
    echo -e "  ${RED}✗ Error: cannot use the same name as the secure folder${NC}"
    exit 1
elif [ ! -d "$PUBLIC_DIR" ]; then
    echo -e "  ${RED}✗ Error: public folder '$PUBLIC_FOLDER_NAME' not found${NC}"
    exit 1
elif [ -d "$SCRIPT_DIR/$NEW_PUBLIC_NAME" ]; then
    echo -e "  ${RED}✗ Error: folder '$NEW_PUBLIC_NAME' already exists${NC}"
    exit 1
else
    mv "$PUBLIC_DIR" "$SCRIPT_DIR/$NEW_PUBLIC_NAME"
    PUBLIC_DIR="$SCRIPT_DIR/$NEW_PUBLIC_NAME"
    PUBLIC_FOLDER_NAME="$NEW_PUBLIC_NAME"

    # Update PUBLIC_FOLDER_NAME in init.php (account for URL space)
    if [ -n "$PUBLIC_SPACE" ]; then
        INIT_FILE="$PUBLIC_DIR/$PUBLIC_SPACE/init.php"
    else
        INIT_FILE="$PUBLIC_DIR/init.php"
    fi
    if [ -f "$INIT_FILE" ]; then
        sed -i "s/define('PUBLIC_FOLDER_NAME',\s*'[^']*')/define('PUBLIC_FOLDER_NAME', '$NEW_PUBLIC_NAME')/" "$INIT_FILE"
    fi
    echo -e "  ${GREEN}✓${NC} Renamed: public → ${BOLD}$NEW_PUBLIC_NAME${NC}"
fi

save_conf
echo ""

# ==========================================================
# Step 2: Rename secure folder
# ==========================================================
echo -e "${BOLD}Step 2 — Secure folder name${NC}"
echo ""

# Detect current secure folder (may already be renamed)
if [ ! -d "$SECURE_DIR" ]; then
    # Try to find it via SECURE_FOLDER_NAME in init.php
    INIT_FILE="$PUBLIC_DIR/init.php"
    if [ -f "$INIT_FILE" ]; then
        DETECTED=$(grep -oP "define\('SECURE_FOLDER_NAME',\s*'\K[^']*" "$INIT_FILE" 2>/dev/null || true)
        if [ -n "$DETECTED" ] && [ -d "$SCRIPT_DIR/$DETECTED" ]; then
            SECURE_DIR="$SCRIPT_DIR/$DETECTED"
            SECURE_FOLDER_NAME="$DETECTED"
        fi
    fi
fi

echo "  The secure folder holds the QuickSite engine."
echo "  Rename it for obscurity, or nest it in a subdirectory."
echo "  Examples: 'backend', 'app', 'backends/project1'"
echo ""
echo -e "  Current name: ${BOLD}$SECURE_FOLDER_NAME${NC}"
echo ""
read -p "  New name (Enter to keep '$SECURE_FOLDER_NAME'): " NEW_SECURE_NAME

# Normalize backslashes to forward slashes, trim leading/trailing slashes
NEW_SECURE_NAME=$(echo "$NEW_SECURE_NAME" | sed 's:\\:/:g; s:^/*::; s:/*$::')

if [ -z "$NEW_SECURE_NAME" ] || [ "$NEW_SECURE_NAME" = "$SECURE_FOLDER_NAME" ]; then
    echo -e "  ${GREEN}✓${NC} Keeping '$SECURE_FOLDER_NAME'"
elif [ "$NEW_SECURE_NAME" = "$PUBLIC_FOLDER_NAME" ]; then
    echo -e "  ${RED}✗ Error: cannot use the same name as the public folder${NC}"
    exit 1
elif [ ! -d "$SECURE_DIR" ]; then
    echo -e "  ${RED}✗ Error: secure folder '$SECURE_FOLDER_NAME' not found${NC}"
    exit 1
else
    # Validate depth (max 5 levels)
    DEPTH=$(echo "$NEW_SECURE_NAME" | awk -F/ '{print NF}')
    if [ "$DEPTH" -gt 5 ]; then
        echo -e "  ${RED}✗ Error: path too deep (max 5 levels)${NC}"
        exit 1
    fi

    TARGET="$SCRIPT_DIR/$NEW_SECURE_NAME"

    # Detect un-nesting (e.g. secure/test → secure): target is ancestor of source
    IS_UNNEST=false
    if echo "$SECURE_DIR" | grep -q "^${TARGET}/"; then
        IS_UNNEST=true
    fi

    if [ -e "$TARGET" ] && [ "$IS_UNNEST" != true ]; then
        echo -e "  ${RED}✗ Error: '$NEW_SECURE_NAME' already exists${NC}"
        exit 1
    fi

    # Remember old location for cleanup (before updating vars)
    OLD_SECURE_DIR="$SECURE_DIR"

    # Perform the move
    PARENT_DIR="$(dirname "$TARGET")"
    if [ "$IS_UNNEST" = true ]; then
        # Un-nesting (e.g. secure/test → secure): target is parent of source
        TMP_DIR="$SCRIPT_DIR/.secure_move_tmp"
        mv "$SECURE_DIR" "$TMP_DIR"
        # Remove the now-empty parent chain up to (and including) target
        CLEANUP_DIR="$(dirname "$SECURE_DIR")"
        while [ "$CLEANUP_DIR" != "$SCRIPT_DIR" ] && [ -d "$CLEANUP_DIR" ]; do
            if [ -z "$(ls -A "$CLEANUP_DIR" 2>/dev/null)" ]; then
                rmdir "$CLEANUP_DIR"
                CLEANUP_DIR="$(dirname "$CLEANUP_DIR")"
            else
                break
            fi
        done
        if [ -e "$TARGET" ]; then
            echo -e "  ${YELLOW}⚠ Warning: '$NEW_SECURE_NAME' still contains other files.${NC}"
            echo -e "  ${YELLOW}  They will be merged into the secure folder.${NC}"
        fi
        mv "$TMP_DIR" "$TARGET"
    elif echo "$TARGET" | grep -q "^${SECURE_DIR}/"; then
        # Self-nesting (e.g. secure → secure/test): can't mv into itself
        TMP_DIR="$SCRIPT_DIR/.secure_move_tmp"
        mv "$SECURE_DIR" "$TMP_DIR"
        mkdir -p "$PARENT_DIR"
        mv "$TMP_DIR" "$TARGET"
    elif [ "$PARENT_DIR" != "$SCRIPT_DIR" ]; then
        mkdir -p "$PARENT_DIR"
        mv "$SECURE_DIR" "$TARGET"
    else
        mv "$SECURE_DIR" "$TARGET"
    fi

    SECURE_DIR="$TARGET"
    SECURE_FOLDER_NAME="$NEW_SECURE_NAME"

    # Update SECURE_FOLDER_NAME in init.php (account for URL space)
    if [ -n "$PUBLIC_SPACE" ]; then
        INIT_FILE="$PUBLIC_DIR/$PUBLIC_SPACE/init.php"
    else
        INIT_FILE="$PUBLIC_DIR/init.php"
    fi
    if [ -f "$INIT_FILE" ]; then
        sed -i "s|define('SECURE_FOLDER_NAME',\s*'[^']*')|define('SECURE_FOLDER_NAME', '$NEW_SECURE_NAME')|" "$INIT_FILE"
    fi

    # Cleanup empty parent directories from old nested path
    OLD_PARENT="$(dirname "$OLD_SECURE_DIR")"
    while [ "$OLD_PARENT" != "$SCRIPT_DIR" ] && [ -d "$OLD_PARENT" ]; do
        if [ -z "$(ls -A "$OLD_PARENT" 2>/dev/null)" ]; then
            rmdir "$OLD_PARENT"
            OLD_PARENT="$(dirname "$OLD_PARENT")"
        else
            break
        fi
    done

    echo -e "  ${GREEN}✓${NC} Renamed → ${BOLD}$NEW_SECURE_NAME${NC}"
fi

save_conf
echo ""

# ==========================================================
# Step 3: Set URL space / prefix
# ==========================================================
echo -e "${BOLD}Step 3 — URL space / prefix${NC}"
echo ""
echo "  A URL space serves the site from a subdirectory."
echo "  Example: 'web' → http://domain/web/"

DESIRED_SPACE="$PUBLIC_SPACE"

if [ -n "$PUBLIC_SPACE" ]; then
    echo ""
    echo -e "  Current space: ${BOLD}$PUBLIC_SPACE${NC}"
    echo ""
    echo "  Enter a new space, type 'none' to remove it,"
    echo "  or press Enter to keep the current space."
    echo ""
    read -p "  Space: " NEW_SPACE_INPUT

    if [ -z "$NEW_SPACE_INPUT" ]; then
        echo -e "  ${GREEN}✓${NC} Keeping '$PUBLIC_SPACE'"
    elif [ "$NEW_SPACE_INPUT" = "none" ]; then
        DESIRED_SPACE=""
    elif [ "$NEW_SPACE_INPUT" = "$PUBLIC_SPACE" ]; then
        echo -e "  ${GREEN}✓${NC} Keeping '$PUBLIC_SPACE'"
    else
        DESIRED_SPACE=$(echo "$NEW_SPACE_INPUT" | sed 's:^[/\\]*::; s:[/\\]*$::')
    fi
else
    echo ""
    echo "  No space currently set."
    echo "  Press Enter to skip, or enter a space name."
    echo ""
    read -p "  Space (Enter for none): " NEW_SPACE_INPUT

    if [ -n "$NEW_SPACE_INPUT" ]; then
        DESIRED_SPACE=$(echo "$NEW_SPACE_INPUT" | sed 's:^[/\\]*::; s:[/\\]*$::')
    else
        echo -e "  ${GREEN}✓${NC} No space — serving from root"
    fi
fi

if [ "$DESIRED_SPACE" != "$PUBLIC_SPACE" ]; then
    # Validate new space (if non-empty)
    if [ -n "$DESIRED_SPACE" ]; then
        if echo "$DESIRED_SPACE" | grep -qP '[^a-zA-Z0-9._/\-]'; then
            echo -e "  ${RED}✗ Error: invalid characters in space name${NC}"
            echo "  Allowed: a-z A-Z 0-9 . - _ /"
            exit 1
        fi
        DEPTH=$(echo "$DESIRED_SPACE" | awk -F/ '{print NF}')
        if [ "$DEPTH" -gt 5 ]; then
            echo -e "  ${RED}✗ Error: space path too deep (max 5 levels)${NC}"
            exit 1
        fi
    fi

    # Remove current space (move files back to public root)
    if [ -n "$PUBLIC_SPACE" ]; then
        SPACE_DIR="$PUBLIC_DIR/$PUBLIC_SPACE"
        if [ -d "$SPACE_DIR" ]; then
            for item in "$SPACE_DIR"/* "$SPACE_DIR"/.*; do
                BASENAME="$(basename "$item")"
                case "$BASENAME" in .|..) continue ;; esac
                mv "$item" "$PUBLIC_DIR/" 2>/dev/null || true
            done
            TOP_SEGMENT=$(echo "$PUBLIC_SPACE" | cut -d/ -f1)
            rm -rf "$PUBLIC_DIR/$TOP_SEGMENT" 2>/dev/null || true
        fi
    fi

    # Set new space (move files from root into space dir)
    if [ -n "$DESIRED_SPACE" ]; then
        TOP_SEGMENT=$(echo "$DESIRED_SPACE" | cut -d/ -f1)
        SPACE_DIR="$PUBLIC_DIR/$DESIRED_SPACE"

        if [ -d "$SPACE_DIR" ]; then
            echo -e "  ${RED}✗ Error: directory '$DESIRED_SPACE' already exists${NC}"
            exit 1
        fi

        mkdir -p "$SPACE_DIR"

        for item in "$PUBLIC_DIR"/* "$PUBLIC_DIR"/.*; do
            BASENAME="$(basename "$item")"
            case "$BASENAME" in .|..) continue ;; esac
            [ "$BASENAME" = "$TOP_SEGMENT" ] && continue
            mv "$item" "$SPACE_DIR/" 2>/dev/null || true
        done
    fi

    PUBLIC_SPACE="$DESIRED_SPACE"

    # Update init.php
    if [ -n "$PUBLIC_SPACE" ]; then
        INIT_FILE="$PUBLIC_DIR/$PUBLIC_SPACE/init.php"
    else
        INIT_FILE="$PUBLIC_DIR/init.php"
    fi
    if [ -f "$INIT_FILE" ]; then
        sed -i "s|define('PUBLIC_FOLDER_SPACE',\s*'[^']*')|define('PUBLIC_FOLDER_SPACE', '$PUBLIC_SPACE')|" "$INIT_FILE"
    fi

    # Update .htaccess FallbackResource
    if [ -n "$PUBLIC_SPACE" ]; then
        HT_DIR="$PUBLIC_DIR/$PUBLIC_SPACE"
        FALLBACK_PREFIX="/$PUBLIC_SPACE"
    else
        HT_DIR="$PUBLIC_DIR"
        FALLBACK_PREFIX=""
    fi
    [ -f "$HT_DIR/.htaccess" ] && sed -i "s|FallbackResource .*|FallbackResource $FALLBACK_PREFIX/index.php|" "$HT_DIR/.htaccess"
    [ -f "$HT_DIR/management/.htaccess" ] && sed -i "s|FallbackResource .*|FallbackResource $FALLBACK_PREFIX/management/index.php|" "$HT_DIR/management/.htaccess"
    [ -f "$HT_DIR/admin/.htaccess" ] && sed -i "s|FallbackResource .*|FallbackResource $FALLBACK_PREFIX/admin/index.php|" "$HT_DIR/admin/.htaccess"

    # Regenerate nginx dynamic_routes.conf if it already exists
    NGINX_CONF="$SECURE_DIR/nginx/dynamic_routes.conf"
    if [ -f "$NGINX_CONF" ]; then
        if [ -n "$PUBLIC_SPACE" ]; then
            PREFIX="/$PUBLIC_SPACE"
        else
            PREFIX=""
        fi
        LOCATION_PATH="${PREFIX:-/}"
        [ -n "$PREFIX" ] && LOCATION_PATH="${PREFIX}/"
        DATE_NOW=$(date '+%Y-%m-%d %H:%M:%S')
        cat > "$NGINX_CONF" << NGINXEOF
# ==========================================================
# QuickSite — nginx dynamic routes configuration
# ==========================================================
# Auto-generated on ${DATE_NOW} by QuickSite setup.sh
# Do NOT edit manually — regenerated when public space changes.
#
# Usage: Include this file in your nginx server {} block:
#   include /path/to/secure/nginx/dynamic_routes.conf;
#
# Manual reload: nginx -t && nginx -s reload
# ==========================================================

# Admin panel API (AJAX helper for dynamic form fields)
location ${PREFIX}/admin/api/ {
    try_files \$uri \$uri/ ${PREFIX}/admin/api/index.php\$is_args\$args;
}

# Management API (QuickSite command endpoint)
location ${PREFIX}/management/ {
    try_files \$uri \$uri/ ${PREFIX}/management/index.php\$is_args\$args;
}

# Admin panel
location ${PREFIX}/admin/ {
    try_files \$uri \$uri/ ${PREFIX}/admin/index.php\$is_args\$args;
}

# Public site (catch-all for QuickSite routes)
location ${LOCATION_PATH} {
    try_files \$uri \$uri/ ${PREFIX}/index.php\$is_args\$args;
}
NGINXEOF
        echo -e "  ${GREEN}✓${NC} Updated nginx dynamic_routes.conf"

        # Attempt nginx reload
        if command -v nginx &>/dev/null; then
            if nginx -t 2>/dev/null; then
                nginx -s reload 2>/dev/null && echo -e "  ${GREEN}✓${NC} nginx reloaded" || true
            fi
        fi
    fi

    if [ -n "$PUBLIC_SPACE" ]; then
        echo -e "  ${GREEN}✓${NC} Space set → http://domain/${BOLD}$PUBLIC_SPACE${NC}/"
    else
        echo -e "  ${GREEN}✓${NC} Space removed — serving from root"
    fi
fi

echo ""

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

# Save final config
save_conf

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${GREEN}${BOLD}  Setup complete${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""
echo "  Public folder:  $PUBLIC_FOLDER_NAME"
echo "  Secure folder:  $SECURE_FOLDER_NAME"
if [ -n "$PUBLIC_SPACE" ]; then
    echo "  URL space:      $PUBLIC_SPACE"
fi
echo ""

if [ -n "$OWNERSHIP_WARNING" ]; then
    echo -e "  ${RED}${BOLD}⚠ IMPORTANT: File ownership${NC}"
    echo -e "  ${RED}You cloned as root. PHP needs write access to $SECURE_FOLDER_NAME/.${NC}"
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
