#!/bin/bash
# ==========================================================
# QuickSite — Update
# ==========================================================
#
# Usage:
#   chmod +x update.sh
#   ./update.sh --check      # report whether a newer release exists, then exit
#   ./update.sh              # check, ask, and apply
#   ./update.sh --yes        # apply without asking (for an operator's own script)
#   ./update.sh --help
#
# WHY THIS IS A SCRIPT AND NOT A COMMAND. Applying an update rewrites the code
# that runs every project on the installation. QuickSite's authority model is
# per-project — there is no installation-wide role, deliberately — so there is no
# principal an HTTP endpoint could gate this on. The credential here is
# FILESYSTEM ACCESS: whoever can run this can already edit users.php, so they
# hold strictly more than any role could grant. That is the same principle the
# first-run setup token uses.
#
# The panel still TELLS you an update exists (that is `checkForUpdates`, and
# secure/management/config/operator.php decides who sees the notice). Discovery
# and action are two different problems: a script cannot tell you there is
# something to do, and an HTTP endpoint must not be the thing that does it.
#
# --check IS CRON-SAFE. It writes to stdout, asks nothing, touches nothing, and
# exits 10 when an update is available / 0 when up to date / non-zero on error,
# so `./update.sh --check || mail -s …` is a legitimate use.
#
# WHAT AN APPLY DOES NOT TOUCH. Your configuration lives in files that are not
# in the repository at all — users.php, auth.php, environment.php,
# operator.php, deploy-roots.php, every project under secure/projects/, and the
# renames setup.sh made. The git path cannot harm them (git only knows tracked
# files) and the ZIP path skips them by name. A backup is taken first regardless.
# ==========================================================

set -uo pipefail

# ---- portability notes, learned the hard way in S2.1 -----------------------
# 1. NO `grep -P`. It is a GNU extension AND it refuses to run under a non-UTF-8
#    multibyte locale ("-P supports only unibyte and UTF-8 locales") — a property
#    of the server, not of the pattern. Everything here uses POSIX grep/sed.
# 2. NO `grep -o`, no `sed -E`, no `\+` / `\?` BRE extensions, no `${var^^}`,
#    no arrays where a positional loop will do: this has to run under the
#    /bin/sh-ish bash 3.2 that ships on older boxes as well as bash 5.
# 3. LC_ALL is pinned below so sort/grep/tr behave the same everywhere.
# 4. This file is pinned `text eol=lf` in .gitattributes. A CRLF copy dies with
#    "bad interpreter: /bin/bash^M" — see the note in update.bat for the mirror
#    image of that problem.
LC_ALL=C
export LC_ALL

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
DIM='\033[2m'
BOLD='\033[1m'
NC='\033[0m'

# Colour only when stdout is a terminal — a cron mail full of escape codes is
# worse than a plain one.
if [ ! -t 1 ]; then
    GREEN=''; RED=''; YELLOW=''; DIM=''; BOLD=''; NC=''
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/VERSION"

GITHUB_OWNER="Sangiovanni"
GITHUB_REPO="quicksite"

# Where to look. Overridable so a FORK or an internal mirror can be updated
# from, and so this script can be tested against a fixture instead of the live
# internet. It grants nothing new: anyone who can set these can already edit
# this file — they are on the machine. An override in effect is ANNOUNCED
# loudly below, so it can never be quietly active.
QS_UPDATE_API="${QS_UPDATE_API:-https://api.github.com/repos/$GITHUB_OWNER/$GITHUB_REPO/releases/latest}"
QS_UPDATE_ZIP="${QS_UPDATE_ZIP:-https://github.com/$GITHUB_OWNER/$GITHUB_REPO/archive/refs/tags/__TAG__.zip}"
QS_UPDATE_DEFAULT_API="https://api.github.com/repos/$GITHUB_OWNER/$GITHUB_REPO/releases/latest"
# Consulted only when releases/latest answers 404 — see the fallback below.
QS_UPDATE_TAGS_API="https://api.github.com/repos/$GITHUB_OWNER/$GITHUB_REPO/tags?per_page=1"

# Exit codes — documented because --check is meant to be scripted against.
EXIT_OK=0             # up to date, or the apply succeeded
EXIT_ERROR=1          # something went wrong
EXIT_UPDATE=10        # --check only: a newer release exists

MODE="apply"
ASSUME_YES="no"

while [ $# -gt 0 ]; do
    case "$1" in
        --check|-c)   MODE="check" ;;
        --yes|-y)     ASSUME_YES="yes" ;;
        --help|-h)
            # Prints the header block above, from just after the title banner to
            # the banner that closes it. Marker-driven rather than a line range —
            # a hardcoded range silently prints the wrong thing the first time
            # somebody adds a paragraph.
            awk 'NR > 4 { if (/^# =+$/) exit; sub(/^# ?/, ""); print }' "$0"
            exit $EXIT_OK
            ;;
        *)
            echo "Unknown option: $1" >&2
            echo "Try: ./update.sh --help" >&2
            exit $EXIT_ERROR
            ;;
    esac
    shift
done

say()  { printf '%b\n' "$*"; }
fail() { printf '%b\n' "  ${RED}x${NC} $*" >&2; }
ok()   { printf '%b\n' "  ${GREEN}+${NC} $*"; }
warn() { printf '%b\n' "  ${YELLOW}!${NC} $*"; }

# ==========================================================
# Local version
# ==========================================================
if [ ! -f "$VERSION_FILE" ]; then
    fail "No VERSION file at $VERSION_FILE"
    fail "This does not look like a QuickSite install root."
    exit $EXIT_ERROR
fi

# tr -d, not a regex: the file may have a trailing newline, a CR from a Windows
# editor, or a stray space, and none of those are part of the version.
CURRENT="$(tr -d '\r\n\t ' < "$VERSION_FILE")"
CURRENT="${CURRENT#v}"
CURRENT="${CURRENT#V}"

if [ -z "$CURRENT" ]; then
    fail "VERSION is empty."
    exit $EXIT_ERROR
fi

# ==========================================================
# Install method
# ==========================================================
if [ -d "$SCRIPT_DIR/.git" ]; then
    METHOD="git"
else
    METHOD="zip"
fi

# ==========================================================
# Latest release from GitHub
# ==========================================================
# curl or wget, whichever exists. No PHP is required: this must work on a box
# where the CLI php is absent or is a different build from the FPM one.
# Writes the response body to stdout and sets HTTP_STATUS as a side effect.
#
# ⚠ CALL IT WITH A REDIRECT, NOT `$(…)`. A command substitution runs in a
# subshell, so HTTP_STATUS set inside would be discarded the moment it returned
# — the classic version of this function reports the status and the caller never
# sees it.
#
# `-f` is deliberately NOT used. It makes curl exit 22 and print nothing on an
# HTTP error, which throws away the very thing needed to tell "no network" from
# "reached GitHub, got a 404". Failures are judged on HTTP_STATUS instead.
HTTP_STATUS=''
http_get() {
    url="$1"
    HTTP_STATUS=''
    # A local fixture, for the probe. Real use never takes this branch.
    case "$url" in
        file://*) HTTP_STATUS=200; cat "${url#file://}" 2>/dev/null; return $? ;;
    esac
    if command -v curl >/dev/null 2>&1; then
        # Body, then the status on a final line. Split rather than a second
        # request: a HEAD would be a different request and could be answered
        # differently (rate limits are counted per request).
        _raw=$(curl -sSL --connect-timeout 15 --max-time 120 \
             -H 'Accept: application/vnd.github.v3+json' \
             -H 'User-Agent: QuickSite-Updater/1.0' \
             -w '\n%{http_code}' "$url" 2>/dev/null)
        _rc=$?
        HTTP_STATUS=$(printf '%s\n' "$_raw" | sed -n '$p')
        printf '%s\n' "$_raw" | sed '$d'
        return $_rc
    fi
    if command -v wget >/dev/null 2>&1; then
        # wget has no comparable status hook worth the complexity; the caller
        # falls back to the older, vaguer message when HTTP_STATUS is empty.
        wget -qO- --timeout=120 \
             --header='Accept: application/vnd.github.v3+json' \
             --header='User-Agent: QuickSite-Updater/1.0' \
             "$url" 2>/dev/null
        return $?
    fi
    return 127
}

# The tag out of GitHub's releases/latest JSON.
#
# POSIX sed, one capture, first match only. A JSON parser would be better and
# there is not one to rely on; the field is machine-generated by GitHub and its
# shape is stable. `head -n 1` matters — `body` can legitimately contain the
# text `"tag_name"` if a release note quotes it.
extract_tag() {
    sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1
}

# The tags endpoint returns an ARRAY whose entries carry "name" rather than
# "tag_name". per_page=1 means there is exactly one, so the first match is it.
extract_tag_name() {
    sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1
}

say ""
say "${BOLD}QuickSite update${NC}"
say ""
say "  Install:  $SCRIPT_DIR"
say "  Version:  ${BOLD}$CURRENT${NC}"
say "  Method:   $METHOD"
if [ "$QS_UPDATE_API" != "$QS_UPDATE_DEFAULT_API" ]; then
    say "  ${YELLOW}Source:   $QS_UPDATE_API  (OVERRIDDEN via QS_UPDATE_API)${NC}"
fi
say ""

# Redirect, not $(…) — see the note on http_get.
BODY_FILE="$(mktemp 2>/dev/null || echo "${TMPDIR:-/tmp}/qs_update_body.$$")"
http_get "$QS_UPDATE_API" > "$BODY_FILE" 2>/dev/null
HTTP_RC=$?

if [ $HTTP_RC -eq 127 ]; then
    rm -f "$BODY_FILE"
    fail "Neither curl nor wget is installed — cannot reach GitHub."
    exit $EXIT_ERROR
fi

LATEST_TAG="$(extract_tag < "$BODY_FILE")"
rm -f "$BODY_FILE"
RELEASE_STATUS="${HTTP_STATUS:-}"

# NO RELEASE, BUT PERHAPS A TAG. checkForUpdates — the admin panel notice — asks
# releases/latest and then falls back to the tags endpoint. This script stopped
# at the 404, so on a repository that tags without publishing releases the panel
# announced an update while the updater reported it could not find one: two
# surfaces, one question, two answers. A tag is enough to act on, because
# QS_UPDATE_ZIP already points at the archive GitHub generates for every tag.
#
# ⚠ ONLY for the DEFAULT endpoint. An operator who set QS_UPDATE_API aimed it
# somewhere deliberately, and silently redirecting them to github.com would
# answer a question they did not ask.
LATEST_FROM_TAG=no
if [ -z "$LATEST_TAG" ] && [ "$RELEASE_STATUS" = 404 ] && [ "$QS_UPDATE_API" = "$QS_UPDATE_DEFAULT_API" ]; then
    TAGS_FILE="$(mktemp 2>/dev/null || echo "${TMPDIR:-/tmp}/qs_update_tags.$$")"
    http_get "$QS_UPDATE_TAGS_API" > "$TAGS_FILE" 2>/dev/null
    LATEST_TAG="$(extract_tag_name < "$TAGS_FILE")"
    rm -f -- "$TAGS_FILE"
    if [ -n "$LATEST_TAG" ]; then
        LATEST_FROM_TAG=yes
    fi
fi

if [ -z "$LATEST_TAG" ]; then
    # SAY WHICH. These four need different things from the operator, and a
    # single "could not read the latest release" told them apart from nothing.
    # The distinction is free — the status is already in hand — and the wrong
    # guess costs real time: somebody whose firewall blocks outbound HTTPS and
    # somebody whose repository simply has no release yet would otherwise read
    # the same sentence and go looking in the same wrong place.
    # RELEASE_STATUS, not HTTP_STATUS: once the tags fallback above has run,
    # HTTP_STATUS describes THAT request and would misreport the cause.
    case "${RELEASE_STATUS:-}" in
        404)
            fail "GitHub has no published release OR tag for this repository."
            say  "    ${DIM}releases/latest answered 404 and the tags list gave nothing${NC}"
            say  "    ${DIM}either, so there is no version to compare against. Most likely${NC}"
            say  "    ${DIM}the repository is private to this machine, or nothing has been${NC}"
            say  "    ${DIM}tagged yet. Outbound access is working, so there is nothing to${NC}"
            say  "    ${DIM}fix on this server.${NC}"
            ;;
        403|429)
            fail "GitHub refused the request (HTTP $HTTP_STATUS) — most likely rate-limited."
            say  "    ${DIM}Unauthenticated requests are capped per address, per hour.${NC}"
            say  "    ${DIM}Wait and run this again; nothing has been changed.${NC}"
            ;;
        2??)
            fail "GitHub answered, but the response carried no release tag."
            say  "    ${DIM}HTTP $HTTP_STATUS with no 'tag_name'. If this persists, the API${NC}"
            say  "    ${DIM}shape may have changed — report it rather than working around it.${NC}"
            ;;
        ''|000)
            # ⚠ `000` IS THE NO-RESPONSE CASE, not a weird status. curl prints
            # that for %{http_code} when it never completed an HTTP exchange at
            # all — DNS failure, refused connection, TLS failure, timeout. An
            # empty value is the wget path, which reports no status. Both mean
            # the same thing to the operator, so they share a branch. (Matching
            # only '' sent every genuinely-offline server to the vague default,
            # which is the exact case this whole block exists to name.)
            fail "Could not reach GitHub."
            say  "    ${DIM}No HTTP response at all: no outbound network, DNS failure, or a${NC}"
            say  "    ${DIM}firewall blocking api.github.com. The admin panel's update notice${NC}"
            say  "    ${DIM}will be silent for the same reason.${NC}"
            ;;
        *)
            fail "Could not read the latest release from GitHub (HTTP $HTTP_STATUS)."
            say  "    ${DIM}Nothing has been changed.${NC}"
            ;;
    esac
    exit $EXIT_ERROR
fi

LATEST="${LATEST_TAG#v}"
LATEST="${LATEST#V}"

# ==========================================================
# Compare
# ==========================================================
# The engine compares versions with PHP's version_compare (checkForUpdates), and
# this script MUST agree with it or the panel notice and the CLI disagree about
# the same install. So the ordering below is version_compare's, reimplemented:
#
#   1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0
#
# ⚠ `sort -V` is NOT that ordering and is not used. It sorts `1.0.0` BEFORE
# `1.0.0-beta.1` in GNU coreutils, i.e. it calls the final release older than
# its own beta — which would report "up to date" to every install running a
# release. It is also not available at all on a BSD/busybox box.
#
# Pure shell, no PHP CLI required: a PHP-FPM-only server has no `php` on PATH,
# and an updater that silently degrades there is an updater that lies there.
# `qs_version_rank` maps the pre-release word onto version_compare's own order.
qs_version_rank() {
    case "$1" in
        dev)          echo 0 ;;
        alpha|a)      echo 1 ;;
        beta|b)       echo 2 ;;
        RC|rc)        echo 3 ;;
        none)         echo 4 ;;   # a plain release outranks every pre-release
        pl|p)         echo 5 ;;
        *)            echo 4 ;;   # unknown word: treat as a plain release
    esac
}

# Splits `1.0.0-beta.11` into the six fields `1 0 0 beta 11 0` on stdout, and a
# plain `1.0.0` into `1 0 0 none 0 0`. Anything that is not one of those shapes
# yields nothing, and the caller falls back (and says so).
#
# ⚠ THE SIXTH FIELD IS A POINT RELEASE — `1.0.0-beta.10.2`. The project tags
# those at the end of a work sequence, and without this the shape matched no
# expression at all: the comparison silently degraded to "the strings differ",
# which happens to answer correctly when the remote IS newer and answers
# WRONGLY the moment it is older. PHP's version_compare, which the admin panel
# uses for the same decision, has always ordered these correctly — so the two
# update surfaces disagreed on the project's own version numbers.
#
# ⚠ `none` IS AN EXPLICIT PLACEHOLDER, not decoration. Emitting an empty field
# instead does not work: the caller splits on whitespace, which collapses runs,
# so a plain release came back as FOUR fields and `$5` was unbound — and this
# script runs under `set -u`, so comparing against any final release (exactly
# what happens the day 1.0.0 ships) aborted the whole run.
qs_version_parts() {
    printf '%s' "$1" | sed -n \
      's/^\([0-9][0-9]*\)\.\([0-9][0-9]*\)\.\([0-9][0-9]*\)$/\1 \2 \3 none 0 0/p;
       s/^\([0-9][0-9]*\)\.\([0-9][0-9]*\)\.\([0-9][0-9]*\)-\([A-Za-z][A-Za-z]*\)\.\([0-9][0-9]*\)\.\([0-9][0-9]*\)$/\1 \2 \3 \4 \5 \6/p;
       s/^\([0-9][0-9]*\)\.\([0-9][0-9]*\)\.\([0-9][0-9]*\)-\([A-Za-z][A-Za-z]*\)\.\([0-9][0-9]*\)$/\1 \2 \3 \4 \5 0/p;
       s/^\([0-9][0-9]*\)\.\([0-9][0-9]*\)\.\([0-9][0-9]*\)-\([A-Za-z][A-Za-z]*\)$/\1 \2 \3 \4 0 0/p'
}

compare_versions() {
    _a="$1"; _b="$2"    # returns 0 when $_b is NEWER than $_a

    _pa="$(qs_version_parts "$_a")"
    _pb="$(qs_version_parts "$_b")"

    if [ -z "$_pa" ] || [ -z "$_pb" ]; then
        # An unrecognised shape. Say so, and treat "different" as "possibly
        # newer" — a false alarm someone can check beats a false "up to date".
        COMPARE_WAS_APPROXIMATE=yes
        [ "$_a" != "$_b" ]
        return $?
    fi

    # shellcheck disable=SC2086
    set -- $_pa; _a1=$1; _a2=$2; _a3=$3; _aw=$4; _an=$5; _ap=$6
    # shellcheck disable=SC2086
    set -- $_pb; _b1=$1; _b2=$2; _b3=$3; _bw=$4; _bn=$5; _bp=$6

    [ "$_b1" -gt "$_a1" ] && return 0
    [ "$_b1" -lt "$_a1" ] && return 1
    [ "$_b2" -gt "$_a2" ] && return 0
    [ "$_b2" -lt "$_a2" ] && return 1
    [ "$_b3" -gt "$_a3" ] && return 0
    [ "$_b3" -lt "$_a3" ] && return 1

    _ar="$(qs_version_rank "$_aw")"
    _br="$(qs_version_rank "$_bw")"
    [ "$_br" -gt "$_ar" ] && return 0
    [ "$_br" -lt "$_ar" ] && return 1

    [ "$_bn" -gt "$_an" ] && return 0
    [ "$_bn" -lt "$_an" ] && return 1

    # Point release last: 1.0.0-beta.10.2 is newer than 1.0.0-beta.10, and a
    # version with no point carries 0 here, so the two compare without a
    # special case.
    [ "$_bp" -gt "$_ap" ] && return 0
    return 1
}

COMPARE_WAS_APPROXIMATE=no
if compare_versions "$CURRENT" "$LATEST"; then
    UPDATE_AVAILABLE=yes
else
    UPDATE_AVAILABLE=no
fi

say "  Latest:   ${BOLD}$LATEST${NC}  ${DIM}($LATEST_TAG)${NC}"
if [ "${LATEST_FROM_TAG:-no}" = yes ]; then
    say "  ${DIM}          from the tag list — this repository publishes no releases${NC}"
fi
say ""

if [ "$COMPARE_WAS_APPROXIMATE" = yes ]; then
    warn "Could not read one of the version numbers, so they were compared"
    say  "    ${DIM}for equality only. \"Update available\" below means \"they differ\".${NC}"
    say  "    ${DIM}installed: $CURRENT   ·   latest: $LATEST${NC}"
    say  ""
fi

if [ "$UPDATE_AVAILABLE" = no ]; then
    ok "You are up to date ($CURRENT)."
    say ""
    exit $EXIT_OK
fi

say "  ${YELLOW}${BOLD}An update is available: $CURRENT -> $LATEST${NC}"
say "  https://github.com/$GITHUB_OWNER/$GITHUB_REPO/releases/tag/$LATEST_TAG"
say ""

if [ "$MODE" = "check" ]; then
    say "  ${DIM}Run ./update.sh (without --check) to apply it.${NC}"
    say ""
    exit $EXIT_UPDATE
fi

# ==========================================================
# Confirm
# ==========================================================
if [ "$ASSUME_YES" != "yes" ]; then
    # `read` from the terminal, and a refusal to proceed when there is not one.
    # An apply started by a cron job that then blocks forever on a prompt is a
    # worse outcome than one that refuses and says why.
    if [ ! -t 0 ]; then
        fail "Not running interactively and --yes was not given. Nothing changed."
        exit $EXIT_ERROR
    fi
    printf '  Apply this update now? [y/N]: '
    read -r ANSWER || ANSWER=""
    case "${ANSWER:-}" in
        [yY]|[yY][eE][sS]) ;;
        *) say ""; say "  Cancelled. Nothing changed."; say ""; exit $EXIT_OK ;;
    esac
    say ""
fi

# ==========================================================
# Backup
# ==========================================================
# Copies of the things an apply could plausibly damage, before it runs. Not a
# full-install backup — the engine's own files are what an update REPLACES, and
# restoring them from here would undo the update. What is captured is the state
# that is not in the repository and therefore cannot be recovered from it.
BACKUP_DIR="$SCRIPT_DIR/.quicksite-backups/$(date +%Y%m%d-%H%M%S)-$CURRENT"

backup_one() {
    src="$1"
    [ -e "$src" ] || return 0
    # Quoted: in ${var#pattern} the pattern is glob-expanded, and an install
    # path is not ours to assume is glob-free.
    rel="${src#"$SCRIPT_DIR"/}"
    dest="$BACKUP_DIR/$rel"
    mkdir -p "$(dirname "$dest")" 2>/dev/null || return 0
    if [ -d "$src" ]; then
        cp -R "$src" "$(dirname "$dest")/" 2>/dev/null || return 0
    else
        cp -p "$src" "$dest" 2>/dev/null || return 0
    fi
    return 0
}

say "  ${BOLD}Backing up your configuration…${NC}"
if ! mkdir -p "$BACKUP_DIR" 2>/dev/null; then
    fail "Could not create $BACKUP_DIR"
    fail "Refusing to update without a backup."
    exit $EXIT_ERROR
fi

CONFIG_DIR_REL="secure/management/config"
# Find the secure folder even when setup.sh renamed or nested it: init.php
# carries the current name, and .quicksite.conf records what setup chose.
SECURE_NAME="secure"
if [ -f "$SCRIPT_DIR/.quicksite.conf" ]; then
    CONF_SECURE="$(sed -n 's/^SECURE_FOLDER_NAME=\(.*\)$/\1/p' "$SCRIPT_DIR/.quicksite.conf" | head -n 1)"
    if [ -n "$CONF_SECURE" ] && [ -d "$SCRIPT_DIR/$CONF_SECURE" ]; then
        SECURE_NAME="$CONF_SECURE"
    fi
fi
CONFIG_DIR_REL="$SECURE_NAME/management/config"

backup_one "$SCRIPT_DIR/VERSION"
backup_one "$SCRIPT_DIR/.quicksite.conf"
for f in users.php auth.php environment.php operator.php deploy-roots.php roles.php \
         import-policy.php api-secrets.php; do
    backup_one "$SCRIPT_DIR/$CONFIG_DIR_REL/$f"
done
backup_one "$SCRIPT_DIR/$SECURE_NAME/projects"

ok "Backup: ${DIM}$BACKUP_DIR${NC}"
say ""

# ==========================================================
# Apply — git
# ==========================================================
apply_git() {
    if ! command -v git >/dev/null 2>&1; then
        fail "This is a git install but git is not on PATH."
        return 1
    fi

    BRANCH="$(git -C "$SCRIPT_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null)"
    [ -n "$BRANCH" ] || BRANCH="main"
    BEFORE="$(git -C "$SCRIPT_DIR" rev-parse --short HEAD 2>/dev/null)"

    DIRTY="$(git -C "$SCRIPT_DIR" status --porcelain 2>/dev/null)"
    if [ -n "$DIRTY" ]; then
        fail "The working tree has uncommitted changes:"
        printf '%s\n' "$DIRTY" | sed 's/^/      /'
        say  ""
        say  "    ${DIM}Commit or stash them, then run this again. A pull over local${NC}"
        say  "    ${DIM}edits is how people lose work they forgot they had made.${NC}"
        return 1
    fi

    say "  ${BOLD}Fetching…${NC}"
    if ! git -C "$SCRIPT_DIR" fetch --tags origin >/dev/null 2>&1; then
        fail "git fetch failed."
        return 1
    fi

    say "  ${BOLD}Pulling $BRANCH…${NC}"
    PULL_OUT="$(git -C "$SCRIPT_DIR" pull origin "$BRANCH" 2>&1)"
    if [ $? -ne 0 ]; then
        fail "git pull failed:"
        printf '%s\n' "$PULL_OUT" | sed 's/^/      /'
        return 1
    fi

    AFTER="$(git -C "$SCRIPT_DIR" rev-parse --short HEAD 2>/dev/null)"
    ok "git: $BEFORE -> $AFTER  (branch $BRANCH)"
    return 0
}

# ==========================================================
# Apply — ZIP
# ==========================================================
# The dangerous path, and the one that gets the explicit skip list. git cannot
# touch an untracked file; a naive unpack-over-the-top can and will.
apply_zip() {
    if ! command -v unzip >/dev/null 2>&1; then
        fail "unzip is not installed — cannot apply a ZIP update."
        say  "    ${DIM}Install unzip, or clone the repository so the git path is used.${NC}"
        return 1
    fi

    TMP="$(mktemp -d 2>/dev/null || echo "$SCRIPT_DIR/.quicksite-update-tmp.$$")"
    mkdir -p "$TMP" 2>/dev/null
    if [ ! -d "$TMP" ]; then
        fail "Could not create a temporary directory."
        return 1
    fi

    # __TAG__ is substituted rather than concatenated, so a mirror can put the
    # tag anywhere in its URL shape, not only at the end.
    ZIP_URL="$(printf '%s' "$QS_UPDATE_ZIP" | sed "s|__TAG__|$LATEST_TAG|g")"
    say "  ${BOLD}Downloading $LATEST_TAG…${NC}"

    case "$ZIP_URL" in
        file://*)
            # A local fixture — used by the update probe. cp, because curl is
            # not required to support file:// and several builds refuse it.
            cp "${ZIP_URL#file://}" "$TMP/update.zip" 2>/dev/null
            ;;
        *)
            if command -v curl >/dev/null 2>&1; then
                curl -fsSL --connect-timeout 15 --max-time 600 -o "$TMP/update.zip" "$ZIP_URL" 2>/dev/null
            else
                wget -q --timeout=600 -O "$TMP/update.zip" "$ZIP_URL" 2>/dev/null
            fi
            ;;
    esac
    if [ ! -s "$TMP/update.zip" ]; then
        fail "Download failed: $ZIP_URL"
        rm -rf "$TMP"
        return 1
    fi

    say "  ${BOLD}Extracting…${NC}"
    if ! unzip -q "$TMP/update.zip" -d "$TMP/x" 2>/dev/null; then
        fail "Could not extract the archive."
        rm -rf "$TMP"
        return 1
    fi

    # GitHub archives unpack into a single <repo>-<tag>/ directory.
    SRC=""
    for d in "$TMP"/x/*/; do
        [ -d "$d" ] || continue
        SRC="${d%/}"
        break
    done
    if [ -z "$SRC" ] || [ ! -f "$SRC/VERSION" ]; then
        fail "The archive does not look like a QuickSite release."
        rm -rf "$TMP"
        return 1
    fi

    # ---- THE SKIP LIST -----------------------------------------------------
    # Everything the release archive must NOT be allowed to write over. Two
    # kinds of entry:
    #
    #   a) files that are gitignored, so the ARCHIVE DOES NOT CONTAIN THEM and
    #      nothing would be copied over them anyway. They are still listed: the
    #      protection must not depend on a .gitignore in another repository
    #      staying the way it is today.
    #   b) directories that hold the author's own work — secure/projects/ above
    #      all. A release ships a starter project; unpacking it over a live
    #      install would overwrite the site somebody built in that folder.
    #
    # The renames setup.sh performs are handled by NOT copying a public/ or
    # secure/ that the install has renamed away from — see the loop.
    is_protected() {
        case "$1" in
            secure/projects|secure/projects/*)             return 0 ;;
            secure/management/config/users.php)            return 0 ;;
            secure/management/config/auth.php)             return 0 ;;
            secure/management/config/roles.php)            return 0 ;;
            secure/management/config/environment.php)      return 0 ;;
            secure/management/config/operator.php)         return 0 ;;
            secure/management/config/deploy-roots.php)     return 0 ;;
            secure/management/config/import-policy.php)    return 0 ;;
            secure/management/config/api-secrets.php)      return 0 ;;
            secure/management/config/setup-token.txt)      return 0 ;;
            secure/management/config/*.json)               return 0 ;;
            secure/management/config/*.lock)               return 0 ;;
            secure/logs|secure/logs/*)                     return 0 ;;
            secure/nginx|secure/nginx/*)                   return 0 ;;
            .quicksite.conf)                               return 0 ;;
            .git|.git/*)                                   return 0 ;;
        esac
        return 1
    }

    say "  ${BOLD}Applying…${NC}"

    # ⚠ THE LOOP BELOW RUNS IN A SUBSHELL — it is the right-hand side of a pipe,
    # so any variable it increments is lost when it ends. The tallies therefore
    # go to files in $TMP and are read back afterwards. Counting into shell
    # variables here is the classic version of this bug: it reports 0 copied
    # after a successful apply and 0 failed after a broken one.
    : > "$TMP/n_copied"
    : > "$TMP/n_skipped"
    : > "$TMP/n_failed"

    # `find -print` piped into a read loop: no arrays, no process substitution,
    # no `find -exec sh -c`, all of which vary across the shells this may meet.
    # (A filename containing a newline would split here. GitHub's own source
    # archive of this repository contains none, and inventing a NUL-delimited
    # path for a case that cannot arise costs more than it protects.)
    ( cd "$SRC" && find . -type f -print ) | while IFS= read -r rel; do
        rel="${rel#./}"
        [ -n "$rel" ] || continue

        if is_protected "$rel"; then
            echo x >> "$TMP/n_skipped"
            continue
        fi

        dest="$SCRIPT_DIR/$rel"
        destdir="$(dirname "$dest")"
        mkdir -p "$destdir" 2>/dev/null
        if cp -p "$SRC/$rel" "$dest" 2>/dev/null; then
            echo x >> "$TMP/n_copied"
        else
            echo x >> "$TMP/n_failed"
            echo "      could not write: $rel" >&2
        fi
    done

    COPIED="$(wc -l < "$TMP/n_copied" 2>/dev/null | tr -d ' ')"
    SKIPPED="$(wc -l < "$TMP/n_skipped" 2>/dev/null | tr -d ' ')"
    FAILED="$(wc -l < "$TMP/n_failed" 2>/dev/null | tr -d ' ')"
    [ -n "$COPIED" ]  || COPIED=0
    [ -n "$SKIPPED" ] || SKIPPED=0
    [ -n "$FAILED" ]  || FAILED=0

    rm -rf "$TMP"

    if [ "$FAILED" != "0" ]; then
        fail "$FAILED file(s) could not be written — the install is PART-UPDATED."
        say  "    ${DIM}Usually a permissions problem. Fix ownership and run this again;${NC}"
        say  "    ${DIM}re-applying the same release is safe.${NC}"
        return 1
    fi

    ok "Applied $LATEST_TAG — $COPIED file(s) written, $SKIPPED left alone"

    # A renamed public/ or secure/ is where the ZIP path stops being able to do
    # the right thing on its own: a release archive always lays its files out
    # under `public/` and `secure/`, and it has no way to know this install
    # calls them something else. Say so rather than leaving a second copy of
    # the engine sitting there looking like it worked.
    if [ "$SECURE_NAME" != "secure" ] || [ ! -d "$SCRIPT_DIR/public" ]; then
        warn "This install renamed its public and/or secure folder."
        say  "    ${DIM}A ZIP release unpacks under 'public/' and 'secure/', so it has just${NC}"
        say  "    ${DIM}created those names alongside your renamed ones. Move the new${NC}"
        say  "    ${DIM}engine files into your own folders, then delete the leftovers —${NC}"
        say  "    ${DIM}or switch to a git install, which has no such problem.${NC}"
    fi
    return 0
}

if [ "$METHOD" = "git" ]; then
    apply_git
    RC=$?
else
    apply_zip
    RC=$?
fi

if [ $RC -ne 0 ]; then
    say ""
    fail "Update did not complete. Your install is unchanged."
    say  "  ${DIM}Backup of your configuration: $BACKUP_DIR${NC}"
    say  ""
    exit $EXIT_ERROR
fi

# ==========================================================
# Report
# ==========================================================
NEW="$CURRENT"
if [ -f "$VERSION_FILE" ]; then
    NEW="$(tr -d '\r\n\t ' < "$VERSION_FILE")"
    NEW="${NEW#v}"
    NEW="${NEW#V}"
fi

say ""
say "${BOLD}========================================${NC}"
say "${GREEN}${BOLD}  Updated: $CURRENT -> $NEW${NC}"
say "${BOLD}========================================${NC}"
say ""
say "  Your configuration was not touched:"
say "    ${DIM}users.php, auth.php, environment.php, operator.php,${NC}"
say "    ${DIM}deploy-roots.php and every project under $SECURE_NAME/projects/${NC}"
say ""
say "  Backup taken first: ${DIM}$BACKUP_DIR${NC}"
say ""
say "  Next:"
say "    1. Load /admin/ once — a new release may add config keys, and the"
say "       engine writes any it is missing (an absent key always has a"
say "       sensible default, so nothing breaks in the meantime)."
say "    2. On nginx, if the routing config was regenerated, reload nginx."
say "    3. Check the release notes for anything that needs your attention:"
say "       ${DIM}https://github.com/$GITHUB_OWNER/$GITHUB_REPO/releases/tag/$LATEST_TAG${NC}"
say ""
exit $EXIT_OK
