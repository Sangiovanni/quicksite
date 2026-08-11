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
 *      the id is the segment after the FIRST 'p' marker, so an optional
 *      PUBLIC_FOLDER_SPACE prefix that we cannot read pre-init does not matter).
 *      Whether that id names a real project is NOT asked here — the gate decides,
 *      and it is the same decision for "private" and for "does not exist", which
 *      is what keeps the two indistinguishable (see qs_surface_b_gate).
 *      Sets BASE_URL before init.php would derive it. PUBLIC_CONTENT_PATH is NOT set here: C15 15.3 binds it
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
 * EXISTENCE POSTURE: every refusal is 404, the SAME status an id that names no
 * project gets. A private project is therefore indistinguishable from one that
 * does not exist — for an anonymous visitor and for an authenticated
 * non-member alike. `/management` already answers a uniform 403 for both cases
 * on every project-scoped command, so surface B was the install's only
 * project-existence oracle; answering 401 (no identity) or 403 (identity, not a
 * member) here would confirm "this id is a real private project" to anyone who
 * asked. The cost is accepted and deliberate: a signed-out member of a private
 * project sees 404 rather than a prompt to sign in.
 *
 * THE GATE NEVER ASKS WHETHER THE PROJECT EXISTS. That is not an oversight, it
 * is the whole mechanism: an id naming nothing has no members.json, so
 * loadProjectMembers() hands back the empty shape and the id reads as PRIVATE
 * WITH NO MEMBERS — which is precisely what it is. A ghost id and a private
 * project therefore execute the SAME LINES and cannot answer differently.
 * Existence is a BINDING question (which folder do we serve from?), answered
 * only after this function admits, and admission already implies a members.json
 * — which cannot exist without the project.
 *
 * That property is load-bearing and was learned the hard way. The oracle was
 * closed once in beta.10 by making the two refusals emit matching headers, and
 * beta.11's session rework reopened it: routing checked existence FIRST, so a
 * real id ran this gate (starting a session, emitting PHP's `Expires` and
 * `Pragma` cache-limiter headers) while a ghost id was refused earlier and
 * emitted neither. Any caller holding ANY cookie could read the difference. The
 * repair is structural rather than cosmetic — there is now ONE refusal path, so
 * there is no second path to keep in step. DO NOT reintroduce an existence test
 * ahead of the identity check below, here or in the caller.
 *
 * COOKIE ONLY. Identity here is the panel's session cookie and nothing else.
 * The gate used to also accept an `Authorization: Bearer` header, which under
 * Apache never arrives (the header is not forwarded to this surface unless the
 * deployment configures it) but under nginx does — the same code deciding
 * access differently on the two supported targets. One credential path removes
 * that divergence, and it is the path that actually works: a preview iframe is
 * a plain browser navigation and can carry no header of its own.
 *
 * The trade this accepts: a project mapped to its OWN domain is a different
 * origin, so the panel's cookie is not sent there. That is irrelevant for a
 * public project (no credential needed) and means a PRIVATE project cannot be
 * previewed on its mapped domain — preview it at `/p/<id>/` on the panel's own
 * origin, which is where the editor points anyway.
 *
 * Safe to call with ANY id a URL can carry — malformed, oversized, naming
 * nothing. Those cases are refused by the ordinary private-project path, not by
 * an early return of their own.
 *
 * @return int|null null = allowed (public project, or authenticated member);
 *                  404 = refused, reason deliberately not distinguished.
 */
function qs_surface_b_gate(string $id, string $secure): ?int {
    if (!defined('SECURE_FOLDER_PATH')) {
        define('SECURE_FOLDER_PATH', $secure); // init.php's own define is if(!defined())-guarded
    }
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

    // The shape rule is this function's own precondition now that it is handed
    // raw URL segments. A malformed id takes the empty shape — the same shape an
    // id naming no project takes, and the same shape a private project with no
    // members takes — so all three walk the identity check below together.
    // (loadProjectMembers is independently F1-guarded; this is not its defence.)
    $members    = qs_sb_valid_id($id) ? loadProjectMembers($id) : ['members' => []];
    $visibility = $members['visibility'] ?? 'private';   // secure default: private
    if ($visibility === 'public') {
        return null;
    }
    // Private → require identity, from the session cookie (see above). Read
    // without holding the session open, so the author's-site OAuth state store
    // can still start its own session later in this same request.
    $auth = qs_session_auth();
    if (empty($auth['valid'])) {
        return 404; // no identity — indistinguishable from "no such project"
    }
    $userId = $auth['user']['id'] ?? '';
    if ($userId === '' || getUserRoleForProject($userId, $id) === null) {
        return 404; // identity, but not a member — same answer, no oracle
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
    // FallbackResource's internal redirect re-prefixes environment variables, so
    // both spellings are read. Never set on the authoring hostname, where the
    // /p/<id>/ URL detection below stays the entry.
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
            //
            // This IS an existence test ahead of the gate, and it is NOT the
            // shape the /p/ lookup below had to give up: $envId comes from the
            // vhost, so a visitor cannot vary it and cannot compare two answers.
            // There is one id here and the deployment already knows it.
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

    // The id this request NAMES: the segment after the FIRST 'p' marker. The
    // marker is anchored rather than searched for, so an optional space prefix
    // (`/<space>/p/<id>/…`) still resolves, and a project's own route that
    // happens to contain a 'p' segment cannot pull the binding off the id.
    //
    // ⚠ THIS LOOKUP IS DELIBERATELY FILESYSTEM-FREE. It used to accept only a
    // 'p' whose next segment was an EXISTING project directory, which made
    // "does this project exist?" a ROUTING decision taken BEFORE the gate — so
    // a real id ran the gate and a ghost id did not, and the gate's side
    // effects (a started session, hence PHP's cache-limiter headers) told the
    // two apart to anyone holding any cookie at all. Existence is settled by
    // the gate now, as the absence of a members.json. Adding an is_dir() back
    // here reopens the oracle. (see qs_surface_b_gate)
    $idIndex = -1;
    $count = count($segs);
    for ($i = 0; $i < $count - 1; $i++) {
        if ($segs[$i] === 'p') {
            $idIndex = $i;
            break;
        }
    }
    if ($idIndex < 0) {
        // No id named at all — a bare `/p/`, or (on a misconfigured mapped
        // domain) a path carrying no marker. Nothing to gate, and no id whose
        // existence could leak: public/p/index.php answers the generic 404
        // below its own require of init.php. There is no privileged project to
        // fall back to (C15 15.3).
        return;
    }

    $id = rawurldecode($segs[$idIndex + 1]);

    // ---- visibility + membership gate (§8.4) — PRE-INIT deliberately ------------
    // THE single decision point for every id this surface is asked about: private,
    // public, nonexistent and malformed all arrive here and are answered by the
    // same lines at the same moment in the request. A refused request answers a
    // generic, engine-owned status page and stops — it does not reach init.php,
    // and neither does a nonexistent one any more, so there is no longer a pair
    // of refusal paths whose responses have to be kept matching by hand.
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
    // Admitted ⇒ the project's members.json was read and said so, and that file
    // cannot exist without the project. Binding below needs no existence test of
    // its own (and must not grow one — see the lookup note above).

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

    // No HIDDEN segment anywhere in the path (C11 11.2). This used to inspect
    // only basename(), which refused `style/.htaccess` but SERVED
    // `.hidden/x.json` — a hidden DIRECTORY published everything inside it, and
    // `.git/` is the classic case (source history disclosure). A project's
    // public/ holds the website as it is; anything a deployment needs at a
    // hidden path (a `/.well-known/` TLS challenge, server config) is served
    // from the deployment's OWN web root, which never enters this passthrough.
    // The rule is "no segment may START with a dot", not "no dots" — files need
    // their extensions.
    foreach (explode('/', str_replace('\\', '/', $decoded)) as $segment) {
        if ($segment !== '' && $segment[0] === '.') return ['status' => 403]; // hidden file or directory
    }
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
 *
 * EVERY REFUSAL THAT NAMES AN ID NOW HAPPENS PRE-INIT, through the one gate, so
 * every response an id-existence comparison could be built from is produced by
 * this function at the same point in the request. What still reaches it AFTER
 * init.php is the bare `/p/` backstop in public/p/index.php — no id, nothing to
 * compare — and the in-project asset / page 404s, which only a caller already
 * admitted to that project can reach. Those two carry init.php's own baseline
 * headers ahead of the ones set here, so their header ORDER differs from a
 * pre-init deny. That is accepted: neither can be reached for an id whose
 * existence is still secret. This function emits init.php's two baseline
 * security headers itself so the header SET is the same either way (init.php's
 * own header() calls are idempotent).
 *
 * That header symmetry is a courtesy now, not the containment. Containment is
 * that "private" and "does not exist" are ONE code path — beta.10 relied on the
 * symmetry alone and the beta.11 session rework slipped straight past it, adding
 * `Expires` and `Pragma` on one side only.
 *
 * A REFUSAL'S HEADERS DEPEND ON NOTHING BUT ITS STATUS. The cache trio below is
 * emitted unconditionally for exactly the reason above: reading the gate's
 * cookie starts a PHP session, and session_start()'s cache limiter emits
 * `Expires` / `Cache-Control` / `Pragma` on its own. Emitting them here too
 * means a caller holding a cookie and a caller holding none get the same
 * response — PHP's versions are simply overwritten in place, keeping their
 * position, so the ORDER matches as well. Without this the deny would carry
 * three extra headers for anyone who presented a cookie, which is how the
 * signed-in and anonymous refusals drifted apart in the first place.
 */
function qs_sb_deny(int $status, string $message): void {
    if (!headers_sent()) {
        http_response_code($status);
        // Cache posture FIRST — that is where session_start() already put its
        // copies, and header() replaces in place, so declaring them in this
        // order makes both the with-session and without-session responses come
        // out identical. `no-store` alone is enough for a modern client; the
        // other two are the HTTP/1.0 belt PHP itself sends.
        header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        // Then init.php's two baseline security headers, in init.php's own
        // order, so a post-init deny (where init.php already sent these) ends
        // up with the same header ORDER as a pre-init deny that sends them
        // here. Same status, same body, same headers, same order.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Type: text/html; charset=utf-8');
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
