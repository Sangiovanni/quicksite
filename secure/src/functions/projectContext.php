<?php
/**
 * Project-context loader (beta.10 C7).
 *
 * Defines the per-request PROJECT_* / CONFIG / ROUTES constants for ONE project.
 * Extracted from init.php so the project can be selected PER REQUEST:
 *
 *   - Served site + admin pages (init.php auto-run): the single GLOBAL served
 *     project from target.php (L6 — unchanged).
 *   - Management dispatcher (C7): the per-request projectId peeled from the URL,
 *     AFTER it has been validated (is_valid_project_name — F1) and membership-
 *     checked. The dispatcher defines QS_DEFER_PROJECT_CONTEXT so init.php skips
 *     its target.php read, then calls this itself.
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
            die(
                "<h1>QuickSite Project Error</h1>" .
                "<p><strong>Missing file:</strong> <code>" . htmlspecialchars(CONFIG_PATH) . "</code></p>" .
                "<p><strong>Active project:</strong> <code>" . htmlspecialchars(PROJECT_NAME) . "</code></p>" .
                "<p><strong>What this means:</strong> The project configuration file <code>config.php</code> is missing for project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>'.</p>" .
                "<p><strong>Expected structure:</strong><br><code>" . htmlspecialchars(SECURE_FOLDER_PATH) . "/projects/" . htmlspecialchars(PROJECT_NAME) . "/config.php</code></p>" .
                "<p><strong>Possible causes:</strong></p>" .
                "<ul>" .
                "<li>Project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>' does not exist in <code>" . SECURE_FOLDER_NAME . "/projects/</code></li>" .
                "<li>Project folder exists but <code>config.php</code> was deleted</li>" .
                "<li>Wrong project name in <code>" . SECURE_FOLDER_NAME . "/management/config/target.php</code></li>" .
                "<li>If paths look wrong, check constants in <code>" . PUBLIC_FOLDER_NAME . "/init.php</code></li>" .
                "</ul>"
            );
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
            die(
                "<h1>QuickSite Project Error</h1>" .
                "<p><strong>Missing file:</strong> <code>" . htmlspecialchars(ROUTES_PATH) . "</code></p>" .
                "<p><strong>Active project:</strong> <code>" . htmlspecialchars(PROJECT_NAME) . "</code></p>" .
                "<p><strong>What this means:</strong> The routes definition file <code>routes.php</code> is missing for project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>'.</p>" .
                "<p><strong>Expected structure:</strong><br><code>" . htmlspecialchars(SECURE_FOLDER_PATH) . "/projects/" . htmlspecialchars(PROJECT_NAME) . "/routes.php</code></p>" .
                "<p><strong>Possible causes:</strong></p>" .
                "<ul>" .
                "<li>Project '<strong>" . htmlspecialchars(PROJECT_NAME) . "</strong>' is incomplete - missing <code>routes.php</code></li>" .
                "<li>File was accidentally deleted</li>" .
                "<li>Corrupted project - try recreating with <code>createProject</code> API command</li>" .
                "<li>If paths look wrong, check constants in <code>" . PUBLIC_FOLDER_NAME . "/init.php</code></li>" .
                "</ul>"
            );
        }
        define('ROUTES', require ROUTES_PATH);
    }
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
