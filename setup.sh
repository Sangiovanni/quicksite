#!/bin/bash
# ==========================================================
# QuickSite — Post-Clone Setup
# ==========================================================
#
# Run this after cloning, before your first page load.
#
# Usage:
#   chmod +x setup.sh
#   ./setup.sh                        # menu
#   ./setup.sh <public_name>          # pre-answer the public-folder rename,
#                                     # then show the menu
#
# A MENU, NOT A WALK. Every item is independent and re-runnable: come back
# later to change one thing without answering the other five again. The
# settings you choose are remembered in .quicksite.conf and read back the next
# time you run this.
#
#   1. Public folder name   — match your vhost DocumentRoot (www, public_html…)
#   2. Secure folder name   — rename/nest the engine folder for obscurity
#   3. URL space            — serve from a subdirectory (http://domain/web/)
#   4. Environment          — production (default) or development
#   5. Self-registration    — may visitors create their own accounts?
#   6. Setup token          — read the first-run credential off disk
#
# Items 1-3 rewrite init.php constants and the .htaccess routing. Items 4-5
# write config files under the secure folder. Item 6 only reads.
#
# WHAT THIS SCRIPT CANNOT DO: create your account. No account exists yet, so
# there is nobody to be one. That happens in the browser at /admin/, and the
# setup token (item 6) is what authorises it — see the notes there.
# ==========================================================

set -euo pipefail

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
DIM='\033[2m'
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
# Has setup ever run here? Captured BEFORE save_conf writes the file, because
# the landing-page drop below is a first-launch-only action.
FIRST_LAUNCH=yes
[ -f "$CONF_FILE" ] && FIRST_LAUNCH=no
if [ -f "$CONF_FILE" ]; then
    source "$CONF_FILE"
    # Backward compat: old conf format used PUBLIC_FOLDER_SPACE
    PUBLIC_SPACE="${PUBLIC_SPACE:-${PUBLIC_FOLDER_SPACE:-}}"
    PUBLIC_DIR="$SCRIPT_DIR/$PUBLIC_FOLDER_NAME"
    SECURE_DIR="$SCRIPT_DIR/$SECURE_FOLDER_NAME"
fi

# Helper: save current state to conf (crash recovery + re-run detection)
save_conf() {
    cat > "$CONF_FILE" << CONFEOF
PUBLIC_FOLDER_NAME=$PUBLIC_FOLDER_NAME
SECURE_FOLDER_NAME=$SECURE_FOLDER_NAME
PUBLIC_SPACE=$PUBLIC_SPACE
CONFEOF
}

# Where init.php lives right now (it moves with the URL space).
init_file() {
    if [ -n "$PUBLIC_SPACE" ]; then
        echo "$PUBLIC_DIR/$PUBLIC_SPACE/init.php"
    else
        echo "$PUBLIC_DIR/init.php"
    fi
}

# The directory holding the served .htaccess files (also moves with the space).
served_dir() {
    if [ -n "$PUBLIC_SPACE" ]; then
        echo "$PUBLIC_DIR/$PUBLIC_SPACE"
    else
        echo "$PUBLIC_DIR"
    fi
}

CONFIG_DIR() { echo "$SECURE_DIR/management/config"; }

# First capture group of a BRE, or '' when the file or the pattern is absent.
#
# POSIX sed, deliberately: `grep -P` is a GNU extension that ALSO refuses to run
# under a non-UTF-8 multibyte locale ("-P supports only unibyte and UTF-8
# locales"), which is a property of the server this lands on, not of the
# pattern. A setup script that reads its own config back wrongly on somebody
# else's box is worse than one that never reads it at all — it reports settings
# that are not in effect.
first_capture() {
    local file="$1" pattern="$2" out
    [ -f "$file" ] || { echo ""; return 0; }
    out=$(sed -n "s/.*${pattern}.*/\\1/p" "$file" 2>/dev/null) || out=""
    echo "${out%%$'\n'*}"
}

pause_for_menu() {
    echo ""
    read -r -p "  Press Enter to return to the menu… " _ || true
}

# ==========================================================
# Crash recovery — locate folders that were renamed without us
# ==========================================================
if [ ! -d "$PUBLIC_DIR" ]; then
    for d in "$SCRIPT_DIR"/*/; do
        if [ -f "$d/init.php" ] || ls "$d"/*/init.php >/dev/null 2>&1; then
            PUBLIC_FOLDER_NAME="$(basename "$d")"
            PUBLIC_DIR="$SCRIPT_DIR/$PUBLIC_FOLDER_NAME"
            break
        fi
    done
fi

if [ ! -d "$SECURE_DIR" ]; then
    INIT_PROBE="$(init_file)"
    if [ -f "$INIT_PROBE" ]; then
        DETECTED=$(first_capture "$INIT_PROBE" "define('SECURE_FOLDER_NAME',[[:space:]]*'\\([^']*\\)')")
        if [ -n "$DETECTED" ] && [ -d "$SCRIPT_DIR/$DETECTED" ]; then
            SECURE_DIR="$SCRIPT_DIR/$DETECTED"
            SECURE_FOLDER_NAME="$DETECTED"
        fi
    fi
fi

# ==========================================================
# File ownership — PHP needs write access to the secure folder
# ==========================================================
# Runs once, before the menu: a clone made as root leaves every later item
# writing files the web server cannot then read back.
OWNERSHIP_WARNING=""
if [ "$(whoami)" = "root" ]; then
    OWNERSHIP_WARNING="yes"
    WEB_USER=""
    # CloudPanel: the site user matches the htdocs parent folder name
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
        echo ""
        echo -e "  ${BOLD}Fixing file ownership…${NC}"
        chown -R "$WEB_USER:$WEB_USER" "$SCRIPT_DIR"
        echo -e "  ${GREEN}✓${NC} Ownership set to ${BOLD}$WEB_USER${NC}"
        OWNERSHIP_WARNING=""
    fi
fi

# ==========================================================
# Landing page — first launch only, and only into an empty root
# ==========================================================
# QuickSite serves nothing at the web root by design, so a brand-new install has
# nothing there and answers 403. That is correct and it looks broken, which is
# the worst combination — hence a placeholder that says where the panel is.
#
# TWO CONDITIONS, both required. It runs only on the FIRST setup launch, so
# deleting the page is permanent rather than something the next run undoes; and
# only when the root holds no index file of its own, so cloning QuickSite onto a
# server that already has a site never overwrites that site's front page.
maybe_place_landing_page() {
    [ "$FIRST_LAUNCH" = yes ] || return 0

    local dir; dir="$(served_dir)"
    local template="$SECURE_DIR/deploy/index.html.example"
    [ -d "$dir" ] || return 0
    [ -f "$template" ] || return 0

    # Any index.* at all — .php, .html, .htm, anything the server might pick as
    # a DirectoryIndex. If something is already there, it is not ours to touch.
    if compgen -G "$dir/index.*" > /dev/null 2>&1; then
        return 0
    fi

    cp "$template" "$dir/index.html" || return 0
    echo ""
    echo -e "  ${GREEN}✓${NC} Added a placeholder page at the web root"
    echo -e "    ${DIM}The root serves nothing by default, so it would answer 403.${NC}"
    echo -e "    ${DIM}Delete or replace $PUBLIC_FOLDER_NAME/index.html when you put${NC}"
    echo -e "    ${DIM}your own site there — nothing depends on it.${NC}"
}

# ==========================================================
# Current-state readers (shown on the menu, so nothing is guessed)
# ==========================================================

# 'production' | 'development' | '' when the file has not been written.
env_current() {
    first_capture "$(CONFIG_DIR)/environment.php" \
        "'environment'[[:space:]]*=>[[:space:]]*'\\([^']*\\)'"
}

env_label() {
    local v; v="$(env_current)"
    case "$v" in
        development) echo -e "${YELLOW}development${NC}  ${DIM}(may call localhost / LAN addresses)${NC}" ;;
        production)  echo -e "production" ;;
        "")          echo -e "production  ${DIM}(default — no environment.php yet)${NC}" ;;
        *)           echo -e "production  ${DIM}(unrecognised value '$v' — treated as production)${NC}" ;;
    esac
}

# 'true' | 'false' | '' when auth.php has not been created yet.
selfreg_current() {
    first_capture "$(CONFIG_DIR)/auth.php" \
        "'allow_self_registration'[[:space:]]*=>[[:space:]]*\\([a-zA-Z]*\\)"
}

selfreg_label() {
    local v; v="$(selfreg_current)"
    case "$v" in
        true)  echo -e "${YELLOW}on${NC}   ${DIM}(anyone may create an account at /admin/register)${NC}" ;;
        false) echo -e "off" ;;
        *)     echo -e "off  ${DIM}(default — auth.php not created yet)${NC}" ;;
    esac
}

space_label() {
    if [ -n "$PUBLIC_SPACE" ]; then
        echo "$PUBLIC_SPACE  → http://your-domain/$PUBLIC_SPACE/"
    else
        echo -e "(none)  ${DIM}— served from the domain root${NC}"
    fi
}

# ==========================================================
# Item 1 — public folder name
# ==========================================================
item_public() {
    local NEW_NAME="${1:-}"

    echo ""
    echo -e "${BOLD}Public folder name${NC}"
    echo ""
    echo "  Your vhost DocumentRoot must point at this folder. If the vhost"
    echo "  expects a specific name (e.g. 'www', 'www.example.com',"
    echo "  'public_html'), rename it here."
    echo ""
    echo -e "  Current name: ${BOLD}$PUBLIC_FOLDER_NAME${NC}"
    echo ""

    if [ -z "$NEW_NAME" ]; then
        read -r -p "  New name (Enter to keep '$PUBLIC_FOLDER_NAME'): " NEW_NAME || true
    fi

    if [ -z "$NEW_NAME" ] || [ "$NEW_NAME" = "$PUBLIC_FOLDER_NAME" ]; then
        echo -e "  ${GREEN}✓${NC} Keeping '$PUBLIC_FOLDER_NAME'"
        return 0
    fi
    if echo "$NEW_NAME" | grep -q '[/\\]'; then
        echo -e "  ${RED}✗ Error: folder name cannot contain slashes${NC}"
        return 0
    fi
    if [ "$NEW_NAME" = "$SECURE_FOLDER_NAME" ]; then
        echo -e "  ${RED}✗ Error: cannot use the same name as the secure folder${NC}"
        return 0
    fi
    if [ ! -d "$PUBLIC_DIR" ]; then
        echo -e "  ${RED}✗ Error: public folder '$PUBLIC_FOLDER_NAME' not found${NC}"
        return 0
    fi
    if [ -e "$SCRIPT_DIR/$NEW_NAME" ]; then
        echo -e "  ${RED}✗ Error: '$NEW_NAME' already exists${NC}"
        return 0
    fi

    mv "$PUBLIC_DIR" "$SCRIPT_DIR/$NEW_NAME"
    PUBLIC_DIR="$SCRIPT_DIR/$NEW_NAME"
    PUBLIC_FOLDER_NAME="$NEW_NAME"

    local INIT_FILE; INIT_FILE="$(init_file)"
    if [ -f "$INIT_FILE" ]; then
        sed -i "s#define('PUBLIC_FOLDER_NAME',[[:space:]]*'[^']*')#define('PUBLIC_FOLDER_NAME', '$NEW_NAME')#" "$INIT_FILE"
    fi
    save_conf
    echo -e "  ${GREEN}✓${NC} Renamed → ${BOLD}$NEW_NAME${NC}"
}

# ==========================================================
# Item 2 — secure folder name
# ==========================================================
item_secure() {
    echo ""
    echo -e "${BOLD}Secure folder name${NC}"
    echo ""
    echo "  The secure folder holds the QuickSite engine and every project's"
    echo "  data. It is never served. Rename it for obscurity, or nest it."
    echo "  Examples: 'backend', 'app', 'backends/project1'"
    echo ""
    echo -e "  Current name: ${BOLD}$SECURE_FOLDER_NAME${NC}"
    echo ""

    local NEW_NAME
    read -r -p "  New name (Enter to keep '$SECURE_FOLDER_NAME'): " NEW_NAME || true

    # Normalize backslashes to forward slashes, trim leading/trailing slashes
    NEW_NAME=$(echo "$NEW_NAME" | sed 's:\\:/:g; s:^/*::; s:/*$::')

    if [ -z "$NEW_NAME" ] || [ "$NEW_NAME" = "$SECURE_FOLDER_NAME" ]; then
        echo -e "  ${GREEN}✓${NC} Keeping '$SECURE_FOLDER_NAME'"
        return 0
    fi
    if [ "$NEW_NAME" = "$PUBLIC_FOLDER_NAME" ]; then
        echo -e "  ${RED}✗ Error: cannot use the same name as the public folder${NC}"
        return 0
    fi
    if [ ! -d "$SECURE_DIR" ]; then
        echo -e "  ${RED}✗ Error: secure folder '$SECURE_FOLDER_NAME' not found${NC}"
        return 0
    fi

    local DEPTH; DEPTH=$(echo "$NEW_NAME" | awk -F/ '{print NF}')
    if [ "$DEPTH" -gt 5 ]; then
        echo -e "  ${RED}✗ Error: path too deep (max 5 levels)${NC}"
        return 0
    fi

    local TARGET="$SCRIPT_DIR/$NEW_NAME"

    # Detect un-nesting (e.g. secure/test → secure): target is ancestor of source
    local IS_UNNEST=false
    if echo "$SECURE_DIR" | grep -q "^${TARGET}/"; then
        IS_UNNEST=true
    fi

    if [ -e "$TARGET" ] && [ "$IS_UNNEST" != true ]; then
        echo -e "  ${RED}✗ Error: '$NEW_NAME' already exists${NC}"
        return 0
    fi

    local OLD_SECURE_DIR="$SECURE_DIR"
    local PARENT_DIR; PARENT_DIR="$(dirname "$TARGET")"
    local TMP_DIR CLEANUP_DIR

    if [ "$IS_UNNEST" = true ]; then
        # Un-nesting (e.g. secure/test → secure): target is parent of source
        TMP_DIR="$SCRIPT_DIR/.secure_move_tmp"
        mv "$SECURE_DIR" "$TMP_DIR"
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
            echo -e "  ${YELLOW}⚠ Warning: '$NEW_NAME' still contains other files.${NC}"
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
    SECURE_FOLDER_NAME="$NEW_NAME"

    local INIT_FILE; INIT_FILE="$(init_file)"
    if [ -f "$INIT_FILE" ]; then
        sed -i "s#define('SECURE_FOLDER_NAME',[[:space:]]*'[^']*')#define('SECURE_FOLDER_NAME', '$NEW_NAME')#" "$INIT_FILE"
    fi

    # Cleanup empty parent directories from the old nested path
    local OLD_PARENT; OLD_PARENT="$(dirname "$OLD_SECURE_DIR")"
    while [ "$OLD_PARENT" != "$SCRIPT_DIR" ] && [ -d "$OLD_PARENT" ]; do
        if [ -z "$(ls -A "$OLD_PARENT" 2>/dev/null)" ]; then
            rmdir "$OLD_PARENT"
            OLD_PARENT="$(dirname "$OLD_PARENT")"
        else
            break
        fi
    done

    save_conf
    echo -e "  ${GREEN}✓${NC} Renamed → ${BOLD}$NEW_NAME${NC}"
}

# ==========================================================
# Item 3 — URL space / prefix
# ==========================================================
item_space() {
    echo ""
    echo -e "${BOLD}URL space / prefix${NC}"
    echo ""
    echo "  A URL space serves the site from a subdirectory of the domain."
    echo "  Example: 'web' → http://your-domain/web/"
    echo ""

    local DESIRED_SPACE="$PUBLIC_SPACE"
    local INPUT

    if [ -n "$PUBLIC_SPACE" ]; then
        echo -e "  Current space: ${BOLD}$PUBLIC_SPACE${NC}"
        echo ""
        echo "  Enter a new space, type 'none' to remove it, or press Enter"
        echo "  to keep the current one."
        echo ""
        read -r -p "  Space: " INPUT || true

        if [ -z "$INPUT" ] || [ "$INPUT" = "$PUBLIC_SPACE" ]; then
            echo -e "  ${GREEN}✓${NC} Keeping '$PUBLIC_SPACE'"
            return 0
        elif [ "$INPUT" = "none" ]; then
            DESIRED_SPACE=""
        else
            DESIRED_SPACE=$(echo "$INPUT" | sed 's:^[/\\]*::; s:[/\\]*$::')
        fi
    else
        echo "  No space currently set — the site serves from the domain root."
        echo ""
        read -r -p "  Space (Enter to keep serving from the root): " INPUT || true

        if [ -z "$INPUT" ]; then
            echo -e "  ${GREEN}✓${NC} No space — serving from the root"
            return 0
        fi
        DESIRED_SPACE=$(echo "$INPUT" | sed 's:^[/\\]*::; s:[/\\]*$::')
    fi

    if [ "$DESIRED_SPACE" = "$PUBLIC_SPACE" ]; then
        echo -e "  ${GREEN}✓${NC} Unchanged"
        return 0
    fi

    if [ -n "$DESIRED_SPACE" ]; then
        if echo "$DESIRED_SPACE" | grep -q '[^a-zA-Z0-9._/-]'; then
            echo -e "  ${RED}✗ Error: invalid characters in space name${NC}"
            echo "  Allowed: a-z A-Z 0-9 . - _ /"
            return 0
        fi
        local DEPTH; DEPTH=$(echo "$DESIRED_SPACE" | awk -F/ '{print NF}')
        if [ "$DEPTH" -gt 5 ]; then
            echo -e "  ${RED}✗ Error: space path too deep (max 5 levels)${NC}"
            return 0
        fi
        if [ -d "$PUBLIC_DIR/$DESIRED_SPACE" ]; then
            echo -e "  ${RED}✗ Error: directory '$DESIRED_SPACE' already exists${NC}"
            return 0
        fi
    fi

    local SPACE_DIR TOP_SEGMENT item BASENAME

    # Remove the current space (move files back to the public root)
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

    # Set the new space (move files from the root into the space dir)
    if [ -n "$DESIRED_SPACE" ]; then
        TOP_SEGMENT=$(echo "$DESIRED_SPACE" | cut -d/ -f1)
        SPACE_DIR="$PUBLIC_DIR/$DESIRED_SPACE"
        mkdir -p "$SPACE_DIR"
        for item in "$PUBLIC_DIR"/* "$PUBLIC_DIR"/.*; do
            BASENAME="$(basename "$item")"
            case "$BASENAME" in .|..) continue ;; esac
            [ "$BASENAME" = "$TOP_SEGMENT" ] && continue
            mv "$item" "$SPACE_DIR/" 2>/dev/null || true
        done
    fi

    PUBLIC_SPACE="$DESIRED_SPACE"

    local INIT_FILE; INIT_FILE="$(init_file)"
    if [ -f "$INIT_FILE" ]; then
        sed -i "s#define('PUBLIC_FOLDER_SPACE',[[:space:]]*'[^']*')#define('PUBLIC_FOLDER_SPACE', '$PUBLIC_SPACE')#" "$INIT_FILE"
    fi

    # ---- Apache: retarget every FallbackResource to the new prefix ----------
    # FallbackResource takes a URL path relative to the DocumentRoot, so each of
    # these has to carry the space or Apache looks for the entry point where it
    # no longer is. /p/ is in this list because /p/<projectId>/ is how EVERY
    # project is served (public/p/.htaccess) — miss it and the whole public
    # surface 404s on a space install while /admin/ still works, which reads
    # like a routing bug and is not one.
    local HT_DIR PREFIX
    HT_DIR="$(served_dir)"
    if [ -n "$PUBLIC_SPACE" ]; then PREFIX="/$PUBLIC_SPACE"; else PREFIX=""; fi
    [ -f "$HT_DIR/.htaccess" ]            && sed -i "s|FallbackResource .*|FallbackResource $PREFIX/index.php|"            "$HT_DIR/.htaccess"
    [ -f "$HT_DIR/p/.htaccess" ]          && sed -i "s|FallbackResource .*|FallbackResource $PREFIX/p/index.php|"          "$HT_DIR/p/.htaccess"
    [ -f "$HT_DIR/management/.htaccess" ] && sed -i "s|FallbackResource .*|FallbackResource $PREFIX/management/index.php|" "$HT_DIR/management/.htaccess"
    [ -f "$HT_DIR/admin/.htaccess" ]      && sed -i "s|FallbackResource .*|FallbackResource $PREFIX/admin/index.php|"      "$HT_DIR/admin/.htaccess"

    # ---- nginx: drop the stale routing config, do NOT rewrite it here -------
    # The generator lives in ONE place — secure/src/functions/NginxConfig.php —
    # and init.php recreates the file on the next page load. This script used to
    # keep its own copy of that generator, which silently went stale: it still
    # emitted a root catch-all into an entry point that no longer exists and no
    # /p/ block at all, so re-running setup broke the routing of an install that
    # was working. Deleting is the one operation that cannot go out of date.
    local NGINX_CONF="$SECURE_DIR/nginx/dynamic_routes.conf"
    if [ -f "$NGINX_CONF" ]; then
        rm -f "$NGINX_CONF"
        echo -e "  ${GREEN}✓${NC} Removed the old nginx routing config"
        echo -e "  ${YELLOW}⚠ nginx: load any page once${NC} — that regenerates"
        echo "    $NGINX_CONF"
        echo "    for the new prefix. Reload nginx after that, not before: the"
        echo "    include points at a file that does not exist right now."
    fi

    save_conf
    if [ -n "$PUBLIC_SPACE" ]; then
        echo -e "  ${GREEN}✓${NC} Space set → http://your-domain/${BOLD}$PUBLIC_SPACE${NC}/"
    else
        echo -e "  ${GREEN}✓${NC} Space removed — serving from the root"
    fi
}

# ==========================================================
# Item 4 — environment (production / development)
# ==========================================================
item_environment() {
    local cfg_dir; cfg_dir="$(CONFIG_DIR)"
    local live="$cfg_dir/environment.php"
    local example="$cfg_dir/environment.php.example"

    echo ""
    echo -e "${BOLD}Environment${NC}"
    echo ""
    echo "  QuickSite fetches URLs from the server for you — data resolvers,"
    echo "  API endpoint tests, OAuth back-channels, importing an asset by URL."
    echo ""
    echo -e "  ${BOLD}production${NC}  those fetches may NOT reach loopback, private or"
    echo "              cloud-metadata addresses. This is what a public or"
    echo "              shared deployment must run as."
    echo -e "  ${BOLD}development${NC} lifts the internal-address block, so a local author"
    echo "              can call http://localhost:3000 or a LAN API. The"
    echo "              http/https scheme rule and DNS pinning stay on."
    echo ""
    echo -e "  Currently: $(env_label)"
    echo ""
    echo -e "  ${DIM}Only ever choose development on a machine you fully trust and${NC}"
    echo -e "  ${DIM}that untrusted callers cannot reach.${NC}"
    echo ""

    # Both answers are SPELLED OUT. A bare [y/N] makes the reader infer what N
    # means, and here it is not "no" — it is the other named mode. Getting that
    # backwards on a public server is the one mistake this item can cause.
    local ANSWER
    read -r -p "  Is this a local/dev install?  y = development, Enter = production: " ANSWER || true

    local VALUE="production"
    case "${ANSWER:-}" in
        [yY]|[yY][eE][sS]) VALUE="development" ;;
    esac

    if [ ! -d "$cfg_dir" ]; then
        echo -e "  ${RED}✗ Error: config folder not found:${NC} $cfg_dir"
        return 0
    fi

    # Start from the .example so the live file keeps the full explanation of
    # what the setting does — the operator edits this file by hand later, and a
    # bare two-line array tells them nothing.
    if [ ! -f "$live" ]; then
        if [ -f "$example" ]; then
            cp "$example" "$live"
        else
            printf "<?php\n\nreturn [\n    'environment' => 'production',\n];\n" > "$live"
        fi
    fi

    # '#' as the delimiter, not '|': BRE alternation is written \| and sed reads
    # a backslash-escaped delimiter as a LITERAL, so a '|'-delimited s/// that
    # contains \| silently matches nothing. That is how the sibling
    # self-registration edit failed its first test run.
    sed -i "s#'environment'[[:space:]]*=>[[:space:]]*'[^']*'#'environment' => '$VALUE'#" "$live"

    if [ "$(env_current)" = "$VALUE" ]; then
        echo -e "  ${GREEN}✓${NC} Environment: ${BOLD}$VALUE${NC}"
        if [ "$VALUE" = "development" ]; then
            echo -e "  ${YELLOW}⚠ Do not run a publicly reachable install this way.${NC}"
        fi
    else
        echo -e "  ${RED}✗ Could not set the value automatically.${NC}"
        echo "    Edit this file by hand and set 'environment' => '$VALUE':"
        echo "    $live"
    fi

    echo ""
    echo -e "  ${DIM}This is a server-side file, on purpose: there is no API that${NC}"
    echo -e "  ${DIM}changes it, so a leaked credential cannot switch the install${NC}"
    echo -e "  ${DIM}into development and re-open outbound access.${NC}"
}

# ==========================================================
# Item 5 — self-registration
# ==========================================================
item_selfreg() {
    local cfg_dir; cfg_dir="$(CONFIG_DIR)"
    local live="$cfg_dir/auth.php"
    local example="$cfg_dir/auth.php.example"

    echo ""
    echo -e "${BOLD}Self-registration${NC}"
    echo ""
    echo "  May visitors create their own accounts at /admin/register?"
    echo ""
    echo "  off  accounts are created by you, from the panel. This is the"
    echo "       default, and what a private install wants."
    echo "  on   anyone who can reach /admin/register can make an account,"
    echo "       subject to the per-IP and per-hour limits in auth.php."
    echo ""
    echo -e "  Currently: $(selfreg_label)"
    echo ""
    echo -e "  ${DIM}Either way, the FIRST account is created from the first-run page${NC}"
    echo -e "  ${DIM}using the setup token — self-registration never bootstraps an${NC}"
    echo -e "  ${DIM}install.${NC}"
    echo ""

    local ANSWER
    read -r -p "  Allow visitors to create their own accounts? [y/N]: " ANSWER || true

    local VALUE="false"
    case "${ANSWER:-}" in
        [yY]|[yY][eE][sS]) VALUE="true" ;;
    esac

    if [ ! -d "$cfg_dir" ]; then
        echo -e "  ${RED}✗ Error: config folder not found:${NC} $cfg_dir"
        return 0
    fi

    # auth.php does not exist yet on a fresh clone — the engine creates it from
    # the .example on the first page load. Creating it here is the same copy,
    # done earlier; the engine's copy is create-if-absent and leaves ours alone.
    if [ ! -f "$live" ]; then
        if [ -f "$example" ]; then
            cp "$example" "$live"
        else
            echo -e "  ${RED}✗ Error: neither auth.php nor auth.php.example is present${NC}"
            echo "    $cfg_dir"
            return 0
        fi
    fi

    sed -i "s#'allow_self_registration'[[:space:]]*=>[[:space:]]*[a-zA-Z]*#'allow_self_registration' => $VALUE#" "$live"

    if [ "$(selfreg_current)" = "$VALUE" ]; then
        if [ "$VALUE" = "true" ]; then
            echo -e "  ${GREEN}✓${NC} Self-registration: ${BOLD}on${NC}"
            echo -e "  ${YELLOW}⚠ /admin/register is now open to anyone who can reach it.${NC}"
        else
            echo -e "  ${GREEN}✓${NC} Self-registration: ${BOLD}off${NC}"
        fi
    else
        echo -e "  ${RED}✗ Could not set the value automatically.${NC}"
        echo "    Edit this file by hand and set 'allow_self_registration' => $VALUE:"
        echo "    $live"
    fi
}

# ==========================================================
# Item 6 — show my setup token
# ==========================================================
item_token() {
    local token_file; token_file="$(CONFIG_DIR)/setup-token.txt"

    echo ""
    echo -e "${BOLD}Setup token${NC}"
    echo ""
    echo "  QuickSite ships no default password. The first account is created"
    echo "  in the browser, and what proves you are the person who installed"
    echo "  this — rather than a passer-by who found the address — is being"
    echo "  able to read a file on the server. That file is the token."
    echo ""

    if [ ! -f "$token_file" ]; then
        # THE ORDERING THAT SURPRISES PEOPLE. The token is minted by the engine
        # when the first-run page renders, not by this script. On a fresh clone
        # it genuinely does not exist yet, and printing an empty value here
        # would look like a broken install.
        echo -e "  ${YELLOW}The token has not been created yet.${NC}"
        echo ""
        echo "  It is written the first time the admin panel is loaded. Do this,"
        echo "  in this order:"
        echo ""
        echo -e "    1. Point your vhost DocumentRoot at ${BOLD}$PUBLIC_FOLDER_NAME/${NC} and"
        echo "       restart your web server."
        if [ -n "$PUBLIC_SPACE" ]; then
            echo -e "    2. Open ${BOLD}http://your-domain/$PUBLIC_SPACE/admin/${NC} in a browser."
        else
            echo -e "    2. Open ${BOLD}http://your-domain/admin/${NC} in a browser."
        fi
        echo "       Leave the page open — it is the form you will fill in."
        echo "    3. Come back here and choose this item again."
        echo ""
        echo "  Expected location:"
        echo "    $token_file"
        return 0
    fi

    local RAW TOKEN
    RAW="$(cat "$token_file" 2>/dev/null || true)"
    TOKEN="$(echo "$RAW" | tr -d '[:space:]')"

    if echo "$TOKEN" | grep -qE '^[0-9a-f]{64}$'; then
        echo -e "  ${GREEN}Your setup token:${NC}"
        echo ""
        echo -e "    ${BOLD}$TOKEN${NC}"
        echo ""
        echo "  Paste it into the first-run form at /admin/. It is used once and"
        echo "  destroyed the moment your account is created."
    else
        # Consumed: qs_setup_token_consume() overwrites the value with a marker
        # before unlinking, so anything here that is not token-shaped means the
        # bootstrap is over.
        echo -e "  ${YELLOW}This token has already been used.${NC}"
        echo ""
        echo "  An account exists on this installation, so the first-run page is"
        echo "  gone for good and the token grants nothing. Sign in at /admin/"
        echo "  instead."
        echo ""
        echo -e "  ${DIM}Lost the password? There is no reset from here. Delete${NC}"
        echo -e "  ${DIM}$(CONFIG_DIR)/users.php${NC}"
        echo -e "  ${DIM}to start the bootstrap over — every account goes with it.${NC}"
    fi
}

# ==========================================================
# The menu
# ==========================================================
show_header() {
    echo ""
    echo -e "${BOLD}========================================${NC}"
    echo -e "${BOLD}       QuickSite Setup${NC}"
    echo -e "${BOLD}========================================${NC}"
}

show_menu() {
    echo ""
    echo -e "  Public folder:      ${BOLD}$PUBLIC_FOLDER_NAME${NC}"
    echo -e "  Secure folder:      ${BOLD}$SECURE_FOLDER_NAME${NC}"
    echo -e "  URL space:          $(space_label)"
    echo -e "  Environment:        $(env_label)"
    echo -e "  Self-registration:  $(selfreg_label)"
    echo ""
    echo "    1) Rename the public folder"
    echo "    2) Rename the secure folder"
    echo "    3) Change the URL space / prefix"
    echo "    4) Switch environment (development / production)"
    echo "    5) Turn self-registration on / off"
    echo "    6) Show my setup token"
    echo "    q) Finish"
    echo ""
}

show_header
maybe_place_landing_page
save_conf

# A public folder name given on the command line pre-answers item 1.
if [ -n "${1:-}" ]; then
    item_public "$1"
fi

while true; do
    show_menu
    CHOICE=""
    read -r -p "  Choice: " CHOICE || { echo ""; break; }
    case "${CHOICE:-}" in
        1) item_public; pause_for_menu ;;
        2) item_secure; pause_for_menu ;;
        3) item_space; pause_for_menu ;;
        4) item_environment; pause_for_menu ;;
        5) item_selfreg; pause_for_menu ;;
        6) item_token; pause_for_menu ;;
        q|Q|"") break ;;
        *) echo -e "  ${RED}Unknown choice: $CHOICE${NC}" ;;
    esac
done

save_conf

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${GREEN}${BOLD}  Setup complete${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""
echo "  Public folder:      $PUBLIC_FOLDER_NAME"
echo "  Secure folder:      $SECURE_FOLDER_NAME"
if [ -n "$PUBLIC_SPACE" ]; then
    echo "  URL space:          $PUBLIC_SPACE"
fi
echo -e "  Environment:        $(env_label)"
echo -e "  Self-registration:  $(selfreg_label)"
echo ""

if [ -n "$OWNERSHIP_WARNING" ]; then
    echo -e "  ${RED}${BOLD}⚠ IMPORTANT: file ownership${NC}"
    echo -e "  ${RED}You are running as root. PHP needs write access to $SECURE_FOLDER_NAME/.${NC}"
    echo -e "  ${RED}Run this (replace USER with your web server / site user):${NC}"
    echo ""
    echo -e "    ${BOLD}chown -R USER:USER $(basename "$SCRIPT_DIR")/${NC}"
    echo ""
    echo -e "  ${RED}Common users: www-data (Ubuntu), nginx, apache,${NC}"
    echo -e "  ${RED}or your CloudPanel site user (e.g. quicksite-test)${NC}"
    echo ""
fi

echo "  Next steps:"
echo "    1. Point your vhost DocumentRoot at the public folder"
echo "    2. Restart your web server"
echo "    3. Load http://your-domain/admin/ once — this creates the config"
echo "       files and mints your setup token"
echo "    4. On nginx: the page that appears tells you which file to include"
echo "       in your server block; add it and reload nginx. On Apache: nothing"
echo "       to do."
echo "    5. Run ./setup.sh again and choose item 6 to read your setup token,"
echo "       then create your first account on the /admin/ page."
echo ""
echo "  That first account becomes the owner of the starter project, so the"
echo "  panel has something to edit the moment you sign in."
echo ""
