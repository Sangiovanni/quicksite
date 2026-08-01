<?php
/**
 * Project-context loader (beta.10 C7).
 *
 * Defines the per-request PROJECT_* / CONFIG / ROUTES constants for ONE project.
 * There is no installation-wide "current project": every entry point names the
 * project the request actually targets, and calls this with it.
 *
 *   - Renderer (`public/p/index.php`): the project peeled from `/p/<id>/`.
 *   - Management dispatcher (C7) + admin-api dispatcher: the per-request projectId
 *     peeled from the URL marker, AFTER it has been validated
 *     (is_valid_project_name — F1) and membership-checked.
 *   - Admin panel: the caller's own EDITED project (selected_project), non-strict
 *     so an account that is a member of nothing still boots into the empty state.
 *
 * Every define is `if (!defined())`-guarded, so the FIRST caller wins and a
 * second call is a no-op — a request loads exactly one project context.
 *
 * @param string $projectName  Project folder name under secure/projects/.
 *                             MUST already be validated by the caller when it
 *                             comes from request input (this function only
 *                             builds paths; it does not re-validate — F1 is the
 *                             dispatcher's job before membership is confirmed).
 * @param bool   $strict       true  → a missing config.php / routes.php is a
 *                                     fatal install error (die with a diagnostic
 *                                     page) — the served-site + project-command
 *                                     behaviour, identical to pre-C7 init.php.
 *                             false → tolerate a missing/blank project: define
 *                                     safe empty CONFIG/ROUTES and return. Used
 *                                     for GLOBAL commands whose UX-default
 *                                     project may not resolve (e.g. a user with
 *                                     zero memberships). Never dies, never leaks.
 */
function qs_load_project_context(string $projectName, bool $strict = true): void
{
    if (!defined('PROJECT_PATH')) {
        define('PROJECT_PATH', SECURE_FOLDER_PATH . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . $projectName);
        define('PROJECT_NAME', $projectName);
    }

    // C15 15.3 — PUBLIC_CONTENT_PATH is bound HERE, with the project, and nowhere else.
    // Every project serves from its own public/; no project is privileged, so there is no
    // installation-wide value left to fall back to. Binding it beside PROJECT_PATH is what
    // let the three pre-init "override the base before init.php defines it" dances
    // (management dispatcher, admin-api dispatcher, surfaceB) be deleted outright — there
    // is no competing definition to pre-empt any more.
    // Skipped for a blank project name (the tolerant path below): a caller with no
    // resolvable project has no public dir, and no global command reads the constant.
    if (!defined('PUBLIC_CONTENT_PATH') && $projectName !== '') {
        define('PUBLIC_CONTENT_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'public');
    }

    // --- config.php -----------------------------------------------------------
    if (!defined('CONFIG_PATH')) {
        define('CONFIG_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'config.php');
    }
    if (!defined('CONFIG')) {
        if (!file_exists(CONFIG_PATH)) {
            if (!$strict) {
                qs_define_empty_project_context();
                return;
            }
            http_response_code(500);
            qs_project_context_die('config.php', CONFIG_PATH, PROJECT_NAME);
        }
        define('CONFIG', require CONFIG_PATH);
    }

    if (!defined('MULTILINGUAL_SUPPORT')) {
        define('MULTILINGUAL_SUPPORT', CONFIG['MULTILINGUAL_SUPPORT'] ?? false);
    }

    // --- theme flags (safe fallbacks) ----------------------------------------
    if (!defined('THEME_MODE_ENABLED')) {
        define('THEME_MODE_ENABLED', CONFIG['THEME_MODE_ENABLED'] ?? false);
    }
    if (!defined('THEME_DEFAULT')) {
        define('THEME_DEFAULT', CONFIG['THEME_DEFAULT'] ?? 'light');
    }
    if (!defined('THEME_USER_TOGGLE_ENABLED')) {
        define('THEME_USER_TOGGLE_ENABLED', CONFIG['THEME_USER_TOGGLE_ENABLED'] ?? false);
    }

    // --- routes.php -----------------------------------------------------------
    if (!defined('ROUTES_PATH')) {
        define('ROUTES_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'routes.php');
    }
    if (!defined('ROUTES')) {
        if (!file_exists(ROUTES_PATH)) {
            if (!$strict) {
                define('ROUTES', []);
                return;
            }
            http_response_code(500);
            qs_project_context_die('routes.php', ROUTES_PATH, PROJECT_NAME);
        }
        define('ROUTES', require ROUTES_PATH);
    }
}

/**
 * The install-error page for a project that cannot be loaded. Never returns.
 *
 * C12 (F9): both call sites used to print the ABSOLUTE path of the missing file
 * plus SECURE_FOLDER_PATH, to whoever asked. This is reachable from the PUBLIC
 * `/p/<id>/` renderer, so an anonymous visitor to a half-deleted project got the
 * server's directory layout. The diagnosis a deployer needs is which FILE is
 * missing from which PROJECT — the project id is already in the URL they typed,
 * and the file name is a fixed string — so the page keeps every bit of that and
 * drops only the part that was never actionable. The absolute path goes to the
 * error log, where the person who can act on it is already looking.
 *
 * In a development install the path is shown, because there the audience for
 * this page IS the person holding the filesystem.
 *
 * @param string $fileName Bare name of the missing file, e.g. 'config.php'.
 * @param string $absPath  Its absolute path — logged, shown only in development.
 * @param string $project  Project id (already caller-supplied via the URL).
 */
function qs_project_context_die(string $fileName, string $absPath, string $project): void
{
    require_once __DIR__ . '/environment.php';
    error_log("QuickSite: project '{$project}' is missing {$fileName} (expected at {$absPath})");

    $safeProject = htmlspecialchars($project, ENT_QUOTES, 'UTF-8');
    $safeFile    = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');

    $page = '<h1>QuickSite Project Error</h1>'
          . '<p><strong>Project:</strong> <code>' . $safeProject . '</code></p>'
          . '<p><strong>Missing file:</strong> <code>' . $safeFile . '</code></p>'
          . '<p>The project <strong>' . $safeProject . '</strong> cannot be served because its '
          . '<code>' . $safeFile . '</code> is missing.</p>'
          . '<p><strong>Possible causes:</strong></p>'
          . '<ul>'
          . '<li>The project does not exist, or its folder is incomplete</li>'
          . '<li><code>' . $safeFile . '</code> was deleted or never written</li>'
          . '<li>The request names the wrong project id</li>'
          . '</ul>'
          . '<p>The full path has been written to the server error log.</p>';

    if (qs_is_development()) {
        $page .= '<hr><p><strong>Expected at:</strong> <code>'
               . htmlspecialchars($absPath, ENT_QUOTES, 'UTF-8')
               . '</code><br><small>Shown because this install is configured as '
               . '<code>development</code>.</small></p>';
    }

    die($page);
}

/**
 * C15 15.4 (R6) — the request's scheme+host, derived ONCE and validated.
 *
 * Every URL the engine composes against the request used to read
 * $_SERVER['HTTP_HOST'] raw (init.php's BASE_URL, surfaceB's /p/ base) — an
 * attacker-controlled header on any catch-all vhost. This is the single
 * replacement for those reads. PRE-INIT-safe: touches no constants.
 *
 * Validation, in order:
 *   1. Shape — the host must look like a hostname/IPv4 (RFC-1123 labels) or a
 *      bracketed IPv6 literal, with an optional :port. Anything else (CRLF,
 *      slashes, spaces, userinfo @, a scheme…) is discarded and the fallback
 *      chain runs: SERVER_NAME (same shape check) → 'localhost', with an
 *      error_log so a misconfigured proxy is visible.
 *   2. Trust (optional) — when the deployment sets QS_TRUSTED_HOSTS
 *      (comma-separated exact host[:port] values, per-vhost SetEnv /
 *      fastcgi_param), a host not in the list is replaced by the FIRST entry.
 *      Degrade-not-die (R4's posture): links point at the canonical host
 *      instead of the request being refused over a config mismatch, and the
 *      spoofed value never reaches any output either way.
 *
 * @return string "http(s)://host[:port]" — NO trailing slash; callers append.
 */
function qs_request_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    return ($https ? 'https://' : 'http://') . qs_request_host();
}

/**
 * The validated host[:port] alone — same validation as qs_request_origin(),
 * without a scheme.
 *
 * C12 split this out for ONE caller: OAuthHandler::makeAbsoluteUrl(), which
 * builds the OAuth `redirect_uri` and decides its scheme with _oauthIsHttps().
 * That helper is a SUPERSET of the scheme test above — it also honours
 * X-Forwarded-Proto — so handing OAuth a whole origin would have silently
 * regressed every reverse-proxy deployment from https to http. It needs the
 * validated host and its own scheme, so that is exactly what it now gets. One
 * validator, two accessors; qs_request_origin() is unchanged in behaviour.
 *
 * @return string "host" or "host:port" — never empty, never attacker-shaped.
 */
function qs_request_host(): string
{
    $shapeOk = static function ($host): bool {
        if (!is_string($host) || $host === '' || strlen($host) > 255) {
            return false;
        }
        // Bracketed IPv6 literal, optional port: [::1] / [::1]:8443
        if (preg_match('/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host) === 1) {
            return true;
        }
        // RFC-1123 labels (letters/digits/hyphen, dot-separated), optional port.
        return preg_match(
            '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*(:\d{1,5})?$/',
            $host
        ) === 1;
    };

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!$shapeOk($host)) {
        $fallback = $_SERVER['SERVER_NAME'] ?? '';
        $host     = $shapeOk($fallback) ? $fallback : 'localhost';
        error_log(
            'QuickSite: rejected malformed Host header'
            . ' — falling back to ' . $host
            . ' (set QS_TRUSTED_HOSTS to pin the canonical host).'
        );
    }

    $trusted = $_SERVER['QS_TRUSTED_HOSTS'] ?? $_SERVER['REDIRECT_QS_TRUSTED_HOSTS'] ?? '';
    if (is_string($trusted) && trim($trusted) !== '') {
        $list = array_values(array_filter(array_map('trim', explode(',', $trusted)), $shapeOk));
        if ($list !== [] && !in_array($host, $list, true)) {
            error_log(
                "QuickSite: Host '{$host}' is not in QS_TRUSTED_HOSTS"
                . " — using canonical '{$list[0]}' instead."
            );
            $host = $list[0];
        }
    }

    return $host;
}

/**
 * Define safe, empty project-scoped constants for the tolerant (non-strict)
 * path — a GLOBAL command whose UX-default project could not be resolved
 * (e.g. a zero-membership user). Nothing here is authoritative or leaks; it
 * exists only so shared code reading CONFIG/ROUTES/THEME_* does not fatal.
 */
function qs_define_empty_project_context(): void
{
    if (!defined('CONFIG'))                   define('CONFIG', ['MULTILINGUAL_SUPPORT' => false]);
    if (!defined('MULTILINGUAL_SUPPORT'))     define('MULTILINGUAL_SUPPORT', false);
    if (!defined('THEME_MODE_ENABLED'))       define('THEME_MODE_ENABLED', false);
    if (!defined('THEME_DEFAULT'))            define('THEME_DEFAULT', 'light');
    if (!defined('THEME_USER_TOGGLE_ENABLED')) define('THEME_USER_TOGGLE_ENABLED', false);
    if (!defined('ROUTES_PATH'))              define('ROUTES_PATH', PROJECT_PATH . DIRECTORY_SEPARATOR . 'routes.php');
    if (!defined('ROUTES'))                   define('ROUTES', []);
}
