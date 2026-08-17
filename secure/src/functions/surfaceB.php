<?php
/**
 * surfaceB.php (beta.10 C9) — `/p/<projectId>/` live project view + static passthrough.
 *
 * Surface B (AUTH_REWORK §5.5): the live WIP site of a project, rendered from
 * `secure/projects/<id>/public/` + templates by the existing engine, and served under
 * `/p/<id>/`. C15 15.2: EVERY project is reached this way — there is no privileged root
 * project any more, and the web root is free (the renderer lives at public/p/index.php).
 *
 * ONE ENTRY, ALWAYS. A project is reached at `/p/<id>/` and nowhere else. There
 * was a second entry — a production domain declaring `SetEnv QS_PROJECT <id>`
 * and serving that project at its root — and it is WITHDRAWN: production is
 * build-only. A project stays in development at `/p/<id>/` for its whole life
 * and the BUILD is the production artifact, so nothing needs a live, unbuilt
 * project answering the public internet. The rule that made a declaring domain
 * refuse every `/p/…` URL went with it; it existed only to stop such a domain
 * being used to reach the install's other projects, and with no such domain
 * left it did nothing but break the editor's preview iframe wherever the
 * variable happened to be set.
 *
 * Two-part flow, wired into public/p/index.php:
 *   1. qs_surface_b_maybe_handle()  — runs FIRST, BEFORE init.php. Binds the project
 *      from the detected /p/<id>/ request: the id is the segment after the FIRST
 *      'p' marker, so an optional PUBLIC_FOLDER_SPACE prefix that we cannot read
 *      pre-init does not matter.
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

/**
 * How big a file may be before its ETag stops being a content hash.
 *
 * At or below this, the validator is md5 of the bytes: it changes when the
 * content changes and at no other time, which is what a strong validator has to
 * mean. That covers the files an author actually edits — stylesheets, scripts,
 * JSON, most images — and hashing them costs far less than the response the
 * validator saves. Above it, the validator is the file's modification time and
 * size, the same pair Apache and nginx ship by default: re-reading a 200 MB
 * video on every conditional request would cost more than sending it.
 *
 * Neither form contains a path, an inode or anything else about the filesystem.
 * beta.10 removed absolute paths from responses on purpose, and an ETag is a
 * response header like any other.
 */
const QS_SB_ETAG_CONTENT_MAX = 1048576; // 1 MiB

/** Read size for a range body — big enough not to syscall per packet, small
 *  enough that a 200 MB file never sits in memory. */
const QS_SB_STREAM_CHUNK = 262144; // 256 KiB

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
 * a plain browser navigation and can carry no header of its own. Every request
 * this gate sees is same-origin with the panel now that `/p/<id>/` is the only
 * way in, so the cookie is always the credential that is actually available.
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
    // Stashed for qs_sb_send_file(), which has to choose `Cache-Control: public`
    // or `private` and must not re-derive the answer — two readings of the same
    // question are two things to keep in step. Written on EVERY path, including
    // the refusals below, so it is never left over from a previous call.
    $GLOBALS['__qs_sb_public'] = ($visibility === 'public');
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
        // No id named at all — a bare `/p/`, or a path funnelled here by a
        // deployment's own FallbackResource / try_files that carries no marker.
        // Nothing to gate, and no id whose existence could leak: public/p/index.php
        // answers the generic 404 below its own require of init.php. There is no
        // privileged project to fall back to (C15 15.3).
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

/**
 * Send a static file with an allowlisted content-type, a cache validator and
 * byte-range support, then exit.
 *
 * ⚠ THE GATE HAS ALREADY RUN. Nothing in this function decides who may read
 * anything: it is reached only from qs_surface_b_finish(), which runs after
 * qs_surface_b_maybe_handle() admitted the request, and a refusal there calls
 * qs_sb_deny() which exits. A Range request is not a way round that — it is a
 * request header, read here, long after the decision. Adding a caller that
 * reaches this function without passing the gate would be the bug; the range
 * arithmetic below cannot be one.
 *
 * WHAT THIS ANSWERS, and why each matters on a site made of project assets:
 *
 *   Accept-Ranges / Range → 206
 *      Without it a browser cannot ask for the middle of a file. A video
 *      scrubber stalls or never appears, and a PDF viewer must fetch every page
 *      before it can draw the first. The content-type allowlist below has
 *      carried mp4, webm, ogg, mp3, wav and pdf all along.
 *   ETag / Last-Modified → 304
 *      The bigger everyday cost. With no validator there is nothing to
 *      revalidate against, so once max-age lapses the browser cannot ask "has
 *      this changed?" and has to refetch the whole file. That applies to every
 *      asset on every project site; it is merely invisible when the asset is a
 *      4 KB stylesheet rather than a 200 MB video.
 *
 * MULTI-RANGE IS DECLINED HERE, NOT FAKED. A Range naming more than one range
 * is ignored and the whole representation is sent with 200 — a legitimate
 * answer (RFC 9110: a server MAY ignore a Range header) and an honest one.
 * Answering 206 with one range while the client asked for several would
 * misdescribe what it received, and multipart/byteranges is a lot of machinery
 * for something nothing fetching a project asset asks for.
 *
 * ⚠ What the CLIENT sees may still be a multipart 206, because declaring
 * `Accept-Ranges: bytes` on a 200 with a known Content-Length arms the web
 * server's OWN range filter. Measured on Apache 2.4.62: a two-range request
 * comes back as a correct `206 multipart/byteranges` with per-part
 * `Content-range` headers, assembled by Apache from the full body sent here.
 * That is a better answer than the one this function chose, and it costs
 * nothing to be right about: single ranges never reach that filter (a 206 is
 * not re-sliced), and an ignored-because-invalid range stays ignored (Apache
 * rejects the same malformed and inverted specs, and honours If-Range the same
 * way). So the guarantee this function makes is "a correct response either
 * way", not "always 200" — do not write a test that asserts the status.
 */
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
    $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $ctype = $types[$ext] ?? 'application/octet-stream';
    $size  = (int) filesize($file);
    $mtime = (int) filemtime($file);
    $etag  = qs_sb_etag($file, $size, $mtime);
    $isHead = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';

    // Everything a 200, a 206 and a 304 all carry. Content-Length is NOT here:
    // it differs per outcome (the file, the range, or absent), and declaring it
    // early is how a 206 ends up announcing the size of the whole file.
    header('Content-Type: ' . $ctype);
    header('X-Content-Type-Options: nosniff');
    // `public` invites ANY cache to store this, shared proxies and CDNs included;
    // on a member-only project that is an asset stored where a non-member's
    // request could be answered from. The gate already decided the project's
    // visibility (qs_surface_b_gate stashes it) — read that answer, never a
    // second reading of members.json.
    //
    // `private`, NOT `no-store`: a member's own browser holding an asset it was
    // entitled to fetch is fine and is the whole point of the ETag/Last-Modified
    // validators above. `no-store` would forbid that too, so every navigation
    // would refetch the bytes instead of answering 304 — a real cost paid for no
    // gain, since the thing being kept out is the SHARED cache and `private`
    // already keeps it out.
    //
    // DEFAULT-ON-ABSENT leans private: an unset flag means the gate did not run,
    // which cannot happen through qs_surface_b_finish() but must fail closed if a
    // future caller finds another way in.
    $sharedCacheable = !empty($GLOBALS['__qs_sb_public']);
    header('Cache-Control: ' . ($sharedCacheable ? 'public' : 'private') . ', max-age=300');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    // SVG can carry script — force download-style handling defensively (never inline-exec).
    if ($ext === 'svg') {
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
    }

    // ---- conditional request: is what the caller already has still current? ----
    if (qs_sb_not_modified($etag, $mtime)) {
        http_response_code(304);
        exit; // no body, and no Content-Length to contradict it
    }

    // ---- byte range ------------------------------------------------------------
    $range = qs_sb_parse_range($_SERVER['HTTP_RANGE'] ?? null, $size, $etag, $mtime);
    if ($range === 'unsatisfiable') {
        // The caller asked for bytes past the end. Tell it how long the file
        // actually is so it can ask again — that is what the '*' form is for.
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Content-Length: 0');
        exit;
    }
    if (is_array($range)) {
        [$start, $end] = $range;
        http_response_code(206);
        header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        // THE LENGTH OF THE RANGE, not of the file.
        header('Content-Length: ' . (string) ($end - $start + 1));
        if (!$isHead) {
            qs_sb_emit_range($file, $start, $end - $start + 1);
        }
        exit;
    }

    header('Content-Length: ' . (string) $size);
    if (!$isHead) {
        readfile($file);
    }
    exit;
}

/**
 * The cache validator for this file. Strong in both forms — see
 * QS_SB_ETAG_CONTENT_MAX for why there are two and where the line sits.
 */
function qs_sb_etag(string $file, int $size, int $mtime): string {
    if ($size <= QS_SB_ETAG_CONTENT_MAX) {
        $hash = @md5_file($file);
        if (is_string($hash)) {
            return '"' . $hash . '"';
        }
    }
    return sprintf('"%x-%x"', $mtime, $size);
}

/**
 * Does the caller already hold this exact representation?
 *
 * If-None-Match decides alone when it is present: it compares a validator the
 * server chose, while If-Modified-Since compares a clock, and RFC 9110 gives
 * the entity tag precedence for exactly that reason. The comparison is the weak
 * one the spec requires for GET — `W/"x"` and `"x"` name the same
 * representation for caching purposes.
 */
function qs_sb_not_modified(string $etag, int $mtime): bool {
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
    if (is_string($inm) && trim($inm) !== '') {
        if (trim($inm) === '*') {
            return true;
        }
        foreach (explode(',', $inm) as $candidate) {
            $candidate = trim($candidate);
            if (strncmp($candidate, 'W/', 2) === 0) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false; // it named tags, none of them ours
    }
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
    if (is_string($ims) && trim($ims) !== '') {
        $since = strtotime($ims);
        return $since !== false && $mtime <= $since;
    }
    return false;
}

/**
 * Resolve a Range header against a file of $size bytes.
 *
 * @return array{0:int,1:int}|string|null  [start,end] to answer with 206;
 *         'unsatisfiable' to answer 416; null to ignore the header and send the
 *         whole file with 200.
 *
 * The three answers are not interchangeable, and the difference is where range
 * implementations go wrong:
 *   - UNSATISFIABLE means the caller asked for a position past the end. Only
 *     that. It earns a 416.
 *   - INVALID (last byte before first, junk where a number belongs, a unit that
 *     is not bytes, more than one range) is not an error at all: the header is
 *     ignored and the whole file is sent, which is always a correct response to
 *     a GET.
 */
function qs_sb_parse_range(?string $header, int $size, string $etag, int $mtime) {
    if (!is_string($header) || trim($header) === '') {
        return null;
    }

    // If-Range: honour the Range only while the caller's copy still matches this
    // representation. If the file changed under it, the pieces it holds and the
    // piece it is asking for do not belong to the same file, and the whole file
    // is the only safe answer.
    $ifRange = $_SERVER['HTTP_IF_RANGE'] ?? null;
    if (is_string($ifRange) && trim($ifRange) !== '') {
        $ifRange = trim($ifRange);
        $stillValid = ($ifRange === $etag);
        if (!$stillValid) {
            $asDate = strtotime($ifRange);
            $stillValid = ($asDate !== false && $asDate === $mtime);
        }
        if (!$stillValid) {
            return null;
        }
    }

    if (strncasecmp($header, 'bytes=', 6) !== 0) {
        return null; // a range unit we do not implement
    }
    $spec = trim(substr($header, 6));
    if ($spec === '' || strpos($spec, ',') !== false || strpos($spec, '-') === false) {
        return null; // empty, multi-range (refused by design), or malformed
    }

    [$fromRaw, $toRaw] = explode('-', $spec, 2);
    $fromRaw = trim($fromRaw);
    $toRaw   = trim($toRaw);

    if ($fromRaw === '') {
        // Suffix form `bytes=-N`: the LAST N bytes, not the first N.
        if ($toRaw === '' || !ctype_digit($toRaw)) {
            return null;
        }
        $wanted = (int) $toRaw;
        if ($wanted <= 0 || $size === 0) {
            return 'unsatisfiable'; // `bytes=-0` names no bytes that exist
        }
        return [max(0, $size - $wanted), $size - 1]; // asking for more than there is = all of it
    }

    if (!ctype_digit($fromRaw)) {
        return null;
    }
    $start = (int) $fromRaw;
    if ($toRaw === '') {
        $end = $size - 1;            // open-ended `bytes=N-`
    } elseif (ctype_digit($toRaw)) {
        $end = (int) $toRaw;
        if ($end < $start) {
            return null;             // invalid, not unsatisfiable — ignore it
        }
        $end = min($end, $size - 1); // a request past the end is clamped, not refused
    } else {
        return null;
    }
    if ($start >= $size) {
        return 'unsatisfiable';      // also the whole answer for a zero-byte file
    }
    return [$start, $end];
}

/**
 * Write exactly $length bytes starting at $start.
 *
 * Seek and read — never readfile(), which would read the file from byte zero
 * and hand the client the whole thing under a header promising a slice of it.
 */
function qs_sb_emit_range(string $file, int $start, int $length): void {
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return;
    }
    if ($start > 0) {
        fseek($fh, $start);
    }
    while ($length > 0 && !feof($fh)) {
        $buf = fread($fh, (int) min(QS_SB_STREAM_CHUNK, $length));
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $length -= strlen($buf);
        // Keep memory flat across a large range even if something upstream
        // started an output buffer.
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
    fclose($fh);
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
 * QS_PUBLIC_BASE_URL. Constraints, deliberately tight:
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
