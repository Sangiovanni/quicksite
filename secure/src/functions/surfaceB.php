<?php
/**
 * surfaceB.php (beta.10 C9) — `/p/<projectId>/` live project view + static passthrough.
 *
 * Surface B (AUTH_REWORK §5.5): the live WIP site of a project, rendered from
 * `secure/projects/<id>/public/` + templates by the existing engine, and served under
 * `/p/<id>/`. C15 15.2: EVERY project is reached this way — there is no privileged root
 * project any more, and the web root is free (the renderer lives at public/p/index.php).
 *
 * Two-part flow, wired into public/p/index.php:
 *   1. qs_surface_b_maybe_handle()  — runs FIRST, BEFORE init.php. Binds the project
 *      from one of two entries: the vhost's QS_PROJECT env (a mapped production
 *      domain — C15 15.4) or a detected /p/<id>/ request (the authoring hostname;
 *      existence-based, so an optional PUBLIC_FOLDER_SPACE prefix that we cannot
 *      read pre-init does not matter). Sets BASE_URL before init.php would derive it. PUBLIC_CONTENT_PATH is NOT set here: C15 15.3 binds it
 *      beside PROJECT_PATH in qs_load_project_context(), so there is no longer a competing
 *      definition to pre-empt.
 *   2. qs_surface_b_finish()        — runs AFTER init + qs_load_project_context(id).
 *      Enforces visibility + membership (L11/§8.4), then either serves a static asset
 *      through the L11 canonicalise+prefix-checked passthrough (secrets UNREACHABLE),
 *      or sets up the HTML live-render (freshness/backfill of qs-*.js, CSP header,
 *      REQUEST_URI rewrite) and returns so public/p/index.php's normal pipeline renders.
 *
 * L11: the static passthrough serves ONLY files inside `…/public/`; `config/`
 * (members.json), `data/` (api-endpoints.json), `routes.php`, `config.php`,
 * `templates/`, `translate/` are unreachable by construction. Proven by
 * scratchpad/c9_passthrough_poc.php (25/25) and the live check in this concern.
 */

require_once __DIR__ . '/projectPublicArtifacts.php'; // QS_RESERVED_BASE + regen helpers
require_once __DIR__ . '/projectContext.php';         // qs_request_origin (R6) — pre-init-safe

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
 * The surface-B visibility + membership gate (§8.4). PRE-INIT-safe: only needs
 * SECURE_FOLDER_PATH (defined here from the computed secure root when init.php
 * has not run yet) + members.json + the C5b session store.
 *
 * @return int|null null = allowed (public project, or authenticated member);
 *                  401 = private + no valid identity; 403 = valid identity,
 *                  not a member (same refusal for a nonexistent project).
 */
function qs_surface_b_gate(string $id, string $secure): ?int {
    if (!defined('SECURE_FOLDER_PATH')) {
        define('SECURE_FOLDER_PATH', $secure); // init.php's own define is if(!defined())-guarded
    }
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

    $members    = loadProjectMembers($id);
    $visibility = $members['visibility'] ?? 'private';   // secure default: private
    if ($visibility === 'public') {
        return null;
    }
    // Private → require identity. The author's own preview iframe carries a
    // short-lived `qs_preview` cookie (D3); a bearer header is also accepted.
    $token      = $_COOKIE['qs_preview'] ?? null;
    $authHeader = ($token !== null && $token !== '')
        ? 'Bearer ' . $token
        : ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    $auth = validateBearerToken($authHeader);
    if (empty($auth['valid'])) {
        return 401;
    }
    $userId = $auth['user']['id'] ?? '';
    if ($userId === '' || getUserRoleForProject($userId, $id) === null) {
        return 403; // same for non-member / stranger project — no membership oracle
    }
    return null;
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

    // ---- C15 15.4: ENV ENTRY MODE — a mapped production domain names its project --
    // §15.1.3 mechanism (b): the vhost declares `SetEnv QS_PROJECT <id>` (Apache) /
    // `fastcgi_param QS_PROJECT <id>` (nginx) and funnels every non-file request
    // here (FallbackResource / try_files). No URL marker, no rewrite — the request
    // path IS the route, and the domain root IS the site. REDIRECT_ fallback:
    // FallbackResource's internal redirect re-prefixes environment variables (same
    // reason the gate reads REDIRECT_HTTP_AUTHORIZATION). Never set on the
    // authoring hostname, where /p/<id>/ URL detection below stays the entry.
    $envId = $_SERVER['QS_PROJECT'] ?? $_SERVER['REDIRECT_QS_PROJECT'] ?? '';
    if (is_string($envId) && $envId !== '') {
        // R5 (Sangio 2026-07-24) — one domain, one site: a mapped domain answers
        // 404 to any literal /p/… request, so a production domain cannot be used
        // to reach or enumerate OTHER projects on the install. PHP-side belt to
        // the vhost's own RewriteRule; also closes the /p/ existence oracle here.
        if (($segs[0] ?? '') === 'p') {
            qs_sb_deny(404, 'This site is not available.');
        }
        if (!qs_sb_valid_id($envId) || !is_dir($secure . '/projects/' . $envId)) {
            // Deployment config error, not a visitor error: the vhost names a
            // project that does not exist. Degrade to 404 with a log (R4 posture)
            // — there is no fallback project by design (C15 15.3).
            error_log(
                "QuickSite: QS_PROJECT='{$envId}' names no existing project — "
                . 'check the vhost SetEnv / fastcgi_param.'
            );
            qs_sb_deny(404, 'This site is not available.');
        }
        $denyStatus = qs_surface_b_gate($envId, $secure);
        if ($denyStatus !== null) {
            qs_sb_deny($denyStatus, 'This site is not available.');
        }
        $GLOBALS['__qs_sb'] = [
            'id'         => $envId,
            'secure'     => $secure,
            'serverRoot' => $serverRoot,
            'subpath'    => implode('/', $segs), // full path — the domain root IS the site
            'projectDir' => $secure . '/projects/' . $envId,
        ];
        if (!defined('BASE_URL'))             define('BASE_URL', qs_request_origin() . '/');
        if (!defined('QS_SURFACE_B_PROJECT')) define('QS_SURFACE_B_PROJECT', $envId);
        if (!defined('QS_SURFACE_B'))         define('QS_SURFACE_B', true);
        return;
    }

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
        // Not a resolvable /p/<id>/ request. The renderer answers a generic 404: there is
        // no privileged project to fall back to (C15 15.3).
        return;
    }

    $id = rawurldecode($segs[$idIndex + 1]);

    // ---- visibility + membership gate (§8.4) — PRE-INIT deliberately ------------
    // A refused request answers a generic, engine-owned status page and stops here.
    // It used to fall through to the NORMAL pipeline so the MAIN served project could
    // render ITS error page; C15 15.3 deleted the served project, so there is no other
    // project to borrow a template from — and borrowing the REQUESTED project's own
    // template would hand a non-member that private project's styling and branding.
    // The generic page is byte-identical whatever the reason, so it adds no oracle.
    // The gate only needs members.json + the session store, none of the init constants.
    $denyStatus = qs_surface_b_gate($id, $secure);
    if ($denyStatus !== null) {
        qs_sb_deny($denyStatus, 'This site is not available.');
    }

    $prefixSegs = array_slice($segs, 0, $idIndex + 2);       // [optional space] + p + id
    $subSegs    = array_slice($segs, $idIndex + 2);          // the rest (route or asset)
    $subpath    = implode('/', $subSegs);                    // RAW (kept encoded for the resolver)

    // C15 15.4 (R6): validated origin, never the raw Host header.
    $baseUrl = qs_request_origin() . '/' . implode('/', $prefixSegs) . '/';

    $GLOBALS['__qs_sb'] = [
        'id'         => $id,
        'secure'     => $secure,
        'serverRoot' => $serverRoot,
        'subpath'    => $subpath,
        'projectDir' => $secure . '/projects/' . $id,
    ];

    // Override the base-derived URL BEFORE init.php derives it (all if(!defined())).
    // PUBLIC_CONTENT_PATH is bound with the project by qs_load_project_context() (15.3).
    if (!defined('BASE_URL'))             define('BASE_URL', $baseUrl);
    if (!defined('QS_SURFACE_B_PROJECT')) define('QS_SURFACE_B_PROJECT', $id);
    if (!defined('QS_SURFACE_B'))         define('QS_SURFACE_B', true);
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

    // (The visibility + membership gate ran PRE-INIT in qs_surface_b_maybe_handle —
    // a denied request never reaches this function: it boots the MAIN site and
    // renders its error page instead. Reaching here = public project or member.)

    // ---- static passthrough (L11) ------------------------------------------------
    if ($subpath !== '') {
        // qs.js is the shared ENGINE runtime, identical for every project — serve the
        // canonical copy, never a per-project file (D4). C15 15.2: the canonical copy
        // is engine-owned at secure/src/runtime/qs.js (unshadowable by a user file at
        // the now-free web root); it is reachable ONLY through this passthrough.
        if ($subpath === 'scripts/qs.js') {
            qs_sb_send_file($sb['serverRoot'] . '/secure/src/runtime/qs.js');
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

/**
 * C15 15.4 (E3) — the DEPLOYMENT's own page for a given deny status, or null.
 *
 * `SetEnv QS_ERROR_PAGE_404 /404.html` (per-vhost, or .htaccess on shared
 * hosting) lets a deployment back QuickSite's project-less status pages with
 * its own root-level files — the same declare-and-obey mechanism as
 * QS_PROJECT / QS_PUBLIC_BASE_URL. Constraints, deliberately tight:
 *
 *   - root-relative path only, realpath-jailed to the DOCUMENT ROOT (the L11
 *     idiom) — a config value can never read outside the web root;
 *   - .html / .htm only, served via readfile — NEVER an include, so a config
 *     value can never become an execution or source-disclosure primitive;
 *   - anything invalid → error_log + null, and the caller degrades to the
 *     built-in generic page (R4 posture: a typo never breaks the deny).
 *
 * QuickSite ships NO files at the web root — "root stays free" holds; the
 * built-in page below remains the default when the deployment declares nothing.
 */
function qs_sb_error_page_file(int $status): ?string {
    $value = $_SERVER['QS_ERROR_PAGE_' . $status]
        ?? $_SERVER['REDIRECT_QS_ERROR_PAGE_' . $status]
        ?? '';
    if (!is_string($value) || $value === '') {
        return null;
    }
    $reject = static function (string $why) use ($value, $status): ?string {
        error_log("QuickSite: ignoring QS_ERROR_PAGE_{$status}='{$value}' — {$why}. Serving the generic page.");
        return null;
    };
    if ($value[0] !== '/' || strpos($value, "\0") !== false) {
        return $reject('must be a root-relative path under the document root');
    }
    $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
    if ($ext !== 'html' && $ext !== 'htm') {
        return $reject('only .html/.htm files are served');
    }
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot === false) {
        return $reject('document root unresolvable');
    }
    $real = realpath($docRoot . $value);
    if ($real === false || !is_file($real)) {
        return $reject('file not found');
    }
    $jail = rtrim($docRoot, '/\\') . DIRECTORY_SEPARATOR;
    $hay  = $real;
    if (DIRECTORY_SEPARATOR === '\\') { $jail = strtolower($jail); $hay = strtolower($hay); }
    if (strncmp($hay, $jail, strlen($jail)) !== 0) {
        return $reject('resolves outside the document root');
    }
    return $real;
}

/**
 * Refuse a surface-B request, then exit. The deployment's own page wins when
 * declared and valid (QS_ERROR_PAGE_<status>, E3); the built-in minimal page
 * is the default.
 */
function qs_sb_deny(int $status, string $message): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $custom = qs_sb_error_page_file($status);
    if ($custom !== null) {
        readfile($custom);
        exit;
    }
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
       . "<title>{$status}</title></head><body style=\"font-family:system-ui,sans-serif;"
       . "max-width:32rem;margin:15vh auto;text-align:center;color:#333\">"
       . "<h1 style=\"font-size:3rem;margin:0\">{$status}</h1><p>{$safe}</p></body></html>";
    exit;
}
