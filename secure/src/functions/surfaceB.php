<?php
/**
 * surfaceB.php (beta.10 C9) — `/p/<projectId>/` live project view + static passthrough.
 *
 * Surface B (AUTH_REWORK §5.5): the WIP site of a NON-reserved project, live-rendered
 * from `secure/projects/<id>/public/` + templates by the existing engine, and served
 * under `/p/<id>/`. The reserved base project `quicksite` is served at the site ROOT
 * (BASE_URL/) instead — `/p/quicksite/` 301-redirects there (D6).
 *
 * Two-part flow, wired into public/index.php:
 *   1. qs_surface_b_maybe_handle()  — runs FIRST, BEFORE init.php. Detects a /p/<id>/
 *      request (existence-based, so an optional PUBLIC_FOLDER_SPACE prefix that we
 *      cannot read pre-init does not matter), and overrides the base-derived constants
 *      PUBLIC_CONTENT_PATH + BASE_URL to the project's own public/ + the /p/<id> URL,
 *      before init.php defines them. Defers project context (QS_DEFER_PROJECT_CONTEXT).
 *   2. qs_surface_b_finish()        — runs AFTER init + qs_load_project_context(id).
 *      Enforces visibility + membership (L11/§8.4), then either serves a static asset
 *      through the L11 canonicalise+prefix-checked passthrough (secrets UNREACHABLE),
 *      or sets up the HTML live-render (freshness/backfill of qs-*.js, CSP header,
 *      REQUEST_URI rewrite) and returns so public/index.php's normal pipeline renders.
 *
 * L11: the static passthrough serves ONLY files inside `…/public/`; `config/`
 * (members.json), `data/` (api-endpoints.json), `routes.php`, `config.php`,
 * `templates/`, `translate/` are unreachable by construction. Proven by
 * scratchpad/c9_passthrough_poc.php (25/25) and the live check in this concern.
 */

require_once __DIR__ . '/projectPublicArtifacts.php'; // QS_RESERVED_BASE + regen helpers

if (!defined('QS_SURFACE_B_RESERVED_WORDS')) {
    // Segment names that may NOT be a /p/ project id (and that createProject must also
    // reserve). Mirrors the URL namespaces a project view must never shadow (D6).
    define('QS_SURFACE_B_RESERVED_WORDS', 'quicksite,p,admin,management,assets,scripts,style,src,logs,config,projects');
}

/** F1 id shape (replicated so this can run pre-init without PathManagement). */
function qs_sb_valid_id(string $id): bool {
    return $id !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
}

/**
 * PRE-INIT: detect a `/p/<id>/` request and set up the surface-B constant overrides.
 * No-op (returns) for every non-surface-B request. Call FIRST in public/index.php,
 * before require 'init.php'.
 */
function qs_surface_b_maybe_handle(): void {
    // Secure root without init.php constants: this file is secure/src/functions/…
    $serverRoot = dirname(__DIR__, 3);
    $secure     = $serverRoot . '/secure';

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return;
    }
    $segs = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));

    // Find the FIRST 'p' segment whose NEXT segment is an EXISTING, valid project.
    // Existence-based → robust to an optional space prefix; cannot hijack a served
    // route unless that route is literally p/<existing-project-id> ('p' is reserved).
    $idIndex = -1;
    $count = count($segs);
    for ($i = 0; $i < $count - 1; $i++) {
        if ($segs[$i] === 'p') {
            $cand = rawurldecode($segs[$i + 1]);
            if (qs_sb_valid_id($cand) && is_dir($secure . '/projects/' . $cand)) {
                $idIndex = $i;
                break;
            }
        }
    }
    if ($idIndex < 0) {
        return; // not a surface-B request → normal served site
    }

    $id = rawurldecode($segs[$idIndex + 1]);

    // The SERVED project (target.php, dynamic) is served at the ROOT, not /p/ — 301 to the
    // root-equivalent path (strip the optional-space + p + id marker, keep the rest). D6.
    if ($id === qs_served_project($secure)) {
        $spaceSegs = array_slice($segs, 0, $idIndex);       // whatever preceded 'p' (space)
        $rest      = array_slice($segs, $idIndex + 2);       // after the id
        $target    = '/' . implode('/', array_merge($spaceSegs, $rest));
        $query     = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        header('Location: ' . $target . ($query ? '?' . $query : ''), true, 301);
        exit;
    }

    $prefixSegs = array_slice($segs, 0, $idIndex + 2);       // [optional space] + p + id
    $subSegs    = array_slice($segs, $idIndex + 2);          // the rest (route or asset)
    $subpath    = implode('/', $subSegs);                    // RAW (kept encoded for the resolver)

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = ($https ? 'https' : 'http') . '://' . $host . '/' . implode('/', $prefixSegs) . '/';

    $GLOBALS['__qs_sb'] = [
        'id'         => $id,
        'secure'     => $secure,
        'serverRoot' => $serverRoot,
        'subpath'    => $subpath,
        'projectDir' => $secure . '/projects/' . $id,
    ];

    // Override base-derived constants BEFORE init.php defines them (all if(!defined())).
    if (!defined('QS_DEFER_PROJECT_CONTEXT')) define('QS_DEFER_PROJECT_CONTEXT', true);
    if (!defined('PUBLIC_CONTENT_PATH'))      define('PUBLIC_CONTENT_PATH', $secure . '/projects/' . $id . '/public');
    if (!defined('BASE_URL'))                 define('BASE_URL', $baseUrl);
    if (!defined('QS_SURFACE_B_PROJECT'))     define('QS_SURFACE_B_PROJECT', $id);
    if (!defined('QS_SURFACE_B'))             define('QS_SURFACE_B', true);
}

/**
 * POST-INIT: gate visibility, then serve a static asset (passthrough) OR set up the
 * HTML live-render and return. Call in public/index.php right after init.php +
 * qs_load_project_context(QS_SURFACE_B_PROJECT).
 */
function qs_surface_b_finish(): void {
    if (!isset($GLOBALS['__qs_sb'])) {
        return;
    }
    $sb         = $GLOBALS['__qs_sb'];
    $id         = $sb['id'];
    $projectDir = $sb['projectDir'];
    $subpath    = $sb['subpath'];

    // ---- visibility + membership gate (§8.4) -------------------------------------
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    $members    = loadProjectMembers($id);
    $visibility = $members['visibility'] ?? 'private';   // secure default: private
    if ($visibility !== 'public') {
        // Private → require identity. The author's own preview iframe carries a
        // short-lived `qs_preview` cookie (D3); a bearer header is also accepted.
        $token      = $_COOKIE['qs_preview'] ?? null;
        $authHeader = ($token !== null && $token !== '')
            ? 'Bearer ' . $token
            : ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        $auth = validateBearerToken($authHeader);
        if (empty($auth['valid'])) {
            qs_sb_deny(401, 'This project is private. Sign in to view it.');
        }
        $userId = $auth['user']['id'] ?? '';
        if ($userId === '' || getUserRoleForProject($userId, $id) === null) {
            // Same 403 for non-member / wrong project — no membership oracle.
            qs_sb_deny(403, 'You do not have access to this project.');
        }
    }

    // ---- static passthrough (L11) ------------------------------------------------
    if ($subpath !== '') {
        // qs.js is the shared ENGINE runtime, identical for every project — serve the
        // canonical copy, never a per-project file (D4).
        if ($subpath === 'scripts/qs.js') {
            qs_sb_send_file($sb['serverRoot'] . '/public/scripts/qs.js');
        }
        $resolved = qs_surface_b_resolve_static($projectDir . '/public', $subpath);
        if (isset($resolved['file'])) {
            qs_sb_send_file($resolved['file']);
        }
        // A subpath that LOOKS like a file (has an extension) but didn't resolve is a
        // 404 — do NOT fall through to the HTML renderer (which would 200 a "page").
        if (qs_sb_looks_static($subpath)) {
            qs_sb_deny((int) ($resolved['status'] ?? 404), 'Not found');
        }
        // else: extension-less subpath → a page route → fall through to HTML render.
    }

    // ---- HTML live-render setup --------------------------------------------------
    // Freshness / backfill: the project's own qs-*.js may be missing (never generated
    // per-project before C9) or stale. Regenerate in editor mode (preview must be
    // current) or when stale.
    $editor = isset($_GET['_editor']) && $_GET['_editor'] === '1';
    if ($editor || qs_project_scripts_stale($projectDir)) {
        qs_regenerate_project_scripts($projectDir, $id);
    }

    qs_surface_b_send_headers();

    // Rewrite REQUEST_URI so TrimParameters + the whole pipeline see a clean path
    // (the optional-space + p + id marker stripped; sub-route + query preserved).
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = '/' . $subpath . ($query ? '?' . $query : '');
    // return → public/index.php continues its normal render pipeline.
}

/**
 * L11 static resolver — the proven passthrough (scratchpad/c9_passthrough_poc.php,
 * 25/25 on PHP 8.0 + 8.4). Returns ['file'=>abs] to serve, or ['status'=>code] to refuse.
 *
 * @param string $publicRoot secure/projects/<id>/public
 * @param string $subpath     RAW path after /p/<id>/ (still URL-encoded)
 */
function qs_surface_b_resolve_static(string $publicRoot, string $subpath): array {
    $decoded = rawurldecode($subpath);
    if (strpos($decoded, "\0") !== false)          return ['status' => 400]; // null byte
    if (preg_match('#%2e|%2f|%5c#i', $subpath))     return ['status' => 400]; // encoded traversal token

    $base = basename($decoded);
    if ($base !== '' && $base[0] === '.')           return ['status' => 403]; // dotfile (.htaccess…)
    $ext = strtolower(pathinfo($decoded, PATHINFO_EXTENSION));
    if ($ext === 'php' || $ext === 'phtml')         return ['status' => 403]; // never serve source

    $root = realpath($publicRoot);
    if ($root === false)                            return ['status' => 404];

    $real = realpath($root . DIRECTORY_SEPARATOR . $decoded);
    if ($real === false)                            return ['status' => 404]; // non-existent

    // THE jail check: canonical target must live inside …/public/ (trailing separator
    // so /public2 cannot satisfy /public). Case-insensitive compare on Windows.
    $jail = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    $hay  = $real . (is_dir($real) ? DIRECTORY_SEPARATOR : '');
    if (DIRECTORY_SEPARATOR === '\\') { $jail = strtolower($jail); $hay = strtolower($hay); }
    if (strncmp($hay, $jail, strlen($jail)) !== 0)  return ['status' => 403]; // escapes jail

    if (is_dir($real))                              return ['status' => 403]; // no dir listing
    return ['file' => $real];
}

/** True if a subpath names a file (has an extension) rather than a page route. */
function qs_sb_looks_static(string $subpath): bool {
    return pathinfo(rawurldecode($subpath), PATHINFO_EXTENSION) !== '';
}

/** Send a static file with an allowlisted content-type + cache headers, then exit. */
function qs_sb_send_file(string $file): void {
    if (!is_file($file)) {
        qs_sb_deny(404, 'Not found');
    }
    static $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'json' => 'application/json', 'map' => 'application/json', 'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'avif' => 'image/avif', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav', 'pdf' => 'application/pdf',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $ctype = $types[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $ctype);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($file));
    header('Cache-Control: public, max-age=300');
    // SVG can carry script — force download-style handling defensively (never inline-exec).
    if ($ext === 'svg') {
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
    }
    readfile($file);
    exit;
}

/** Emit surface-B response security headers for the HTML render. */
function qs_surface_b_send_headers(): void {
    // Same-origin framing only (the admin preview iframe is same-origin). Cross-origin
    // shared embedding is a later concern. init.php already sends X-Frame-Options.
    // A baseline CSP tighter than the admin's own chrome: engine pages use inline
    // scripts (theme toggle, state-store hydration) so 'unsafe-inline' is required for
    // now; object/base are locked down and framing is restricted to same origin.
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; font-src 'self' data:; "
            . "connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    }
}

/** Refuse a surface-B request with a minimal HTML page, then exit. */
function qs_sb_deny(int $status, string $message): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
       . "<title>{$status}</title></head><body style=\"font-family:system-ui,sans-serif;"
       . "max-width:32rem;margin:15vh auto;text-align:center;color:#333\">"
       . "<h1 style=\"font-size:3rem;margin:0\">{$status}</h1><p>{$safe}</p></body></html>";
    exit;
}
