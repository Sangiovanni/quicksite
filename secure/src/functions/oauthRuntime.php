<?php
/**
 * OAuth sign-in routes — the single lifecycle.
 *
 * A route whose resolver KIND is `oauth-start`, `oauth-callback` or
 * `oauth-logout` is not a page: it is a step in an authorization-code flow. It
 * replaces the render with a 302, and on the callback with a session cookie
 * too. So it has to run before anything is rendered, and it never returns when
 * it applies.
 *
 * ⚠ THIS IS THE AUTHOR'S SITE'S SIGN-IN, NOT QUICKSITE'S. It is the visitor
 * logging in to the website that was built, against whatever provider the
 * author configured. That is why a production build can run it at all: the flow
 * needs PHP sessions, an outbound HTTPS call and a route to come back to, and a
 * built site has all three. It needs nothing from the management API or the
 * admin panel, neither of which exists in a build.
 *
 * ⚠ THE CLIENT SECRET COMES FROM THE SERVER FIRST. OAuthHandler reads
 * QS_OAUTH_<PROVIDER>_CLIENT_ID / _CLIENT_SECRET before the shipped
 * `data/oauth-secrets.json`, so a deployer can keep the credential out of a
 * build folder that `downloadBuild` hands to anyone with build permission.
 *
 * Lifted out of the /p/<projectId>/ renderer so both surfaces run one
 * implementation. The decisions below — logout deriving its provider from the
 * cookie rather than the config, logout degrading to local-only when the
 * handler cannot be built, the misconfiguration page telling an anonymous
 * visitor nothing about the server — are all load-bearing and were argued
 * where they were written.
 */

require_once __DIR__ . '/routeHelpers.php';      // substituteRouteParams
require_once __DIR__ . '/requestRuntime.php';    // qs_project_cookie_name
require_once __DIR__ . '/environment.php';       // qs_is_development

if (!function_exists('qs_run_oauth_route')) {
    /**
     * Run this route's OAuth step, if it has one. Returns immediately when the
     * route is an ordinary data route; NEVER RETURNS when it is a sign-in step.
     *
     * `validateResolverConfigs` enforces one kind per route at save time, so
     * the first config's kind is authoritative for the whole array.
     *
     * @param array  $resolverConfigs This route's resolvers.
     * @param string $routePath       Matched route, for diagnostics.
     * @param array  $routeParams     URL path params — substituted into the
     *                                provider and callback_url before the
     *                                handler sees them.
     */
    function qs_run_oauth_route(array $resolverConfigs, string $routePath, array $routeParams): void
    {
        if (empty($resolverConfigs)) {
            return;
        }
        // OAuth resolver kinds replace the data-fetch + render pipeline with a
        // 302 + optional session cookie. validateResolverConfigs enforces one
        // kind per route, so the first config decides for the whole array.
        $firstKind = $resolverConfigs[0]['kind'] ?? 'data';
        if ($firstKind === 'oauth-start' || $firstKind === 'oauth-callback' || $firstKind === 'oauth-logout') {
            require_once SECURE_FOLDER_PATH . '/src/classes/OAuthHandler.php';
            require_once SECURE_FOLDER_PATH . '/src/functions/oauthStateStore.php';
            require_once SECURE_FOLDER_PATH . '/src/functions/requestRuntime.php'; // qs_project_cookie_name

            $__oauthCfg = $resolverConfigs[0];
            // Substitute {:routeParam} placeholders in every config string
            // field BEFORE the handler runs — handler operates on already-
            // resolved values. Covers `provider` (one resolver entry on
            // /auth/oauth/:provider/callback can serve every provider) AND
            // `callback_url` (lets the start-flow target match the user's
            // route shape, e.g. /auth/oauth/{:provider}/callback). See
            // DESIGN_DECISIONS.md "OAuth handleStart shape" for why
            // substitution lives in the dispatcher, not the handler.
            foreach (['provider', 'callback_url'] as $__phField) {
                if (isset($__oauthCfg[$__phField]) && is_string($__oauthCfg[$__phField])) {
                    $__oauthCfg[$__phField] = substituteRouteParams(
                        $__oauthCfg[$__phField],
                        $routeParams
                    );
                }
            }

            // Slice 2e: oauth-logout takes a different path to derive the
            // provider id. start/callback get the provider from the config
            // (URL-driven); logout auto-detects from the active session,
            // because the user might have logged in via Meta and now hit a
            // generic /logout route — the cookie is the truth.
            $__logoutSessionId = '';
            if ($firstKind === 'oauth-logout') {
                // Namespaced per project — the SAME composition the set and the
                // clears below use. A mismatch here reads as "no session" and the
                // logout silently leaves the real cookie in place.
                $__qsOauthCookie   = qs_project_cookie_name(QS_OAUTH_COOKIE);
                $__logoutSessionId = isset($_COOKIE[$__qsOauthCookie]) ? (string) $_COOKIE[$__qsOauthCookie] : '';
                $__logoutSession = $__logoutSessionId !== '' ? getOAuthSession($__logoutSessionId) : null;
                if ($__logoutSession === null) {
                    // No active session — logout is idempotent. Expire the
                    // cookie defensively (in case it lingers with a stale
                    // sessionId no longer in the store) and redirect.
                    // ⚠ A cookie is cleared by NAME and PATH. Both must match the
                    // set exactly, or this expires a cookie that does not exist
                    // and the session cookie survives a "successful" logout.
                    setcookie(qs_project_cookie_name(QS_OAUTH_COOKIE), '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'secure'   => _oauthIsHttps(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    header('Location: ' . ($_GET['return'] ?? '/'), true, 302);
                    exit;
                }
                $__provider = (string) ($__logoutSession['provider'] ?? '');
            } else {
                $__provider = $__oauthCfg['provider'] ?? '';
            }

            try {
                $__oauthHandler = new OAuthHandler($__provider);
            } catch (RuntimeException $__oauthErr) {
                // Surface OAuth misconfig loudly — missing presets file,
                // unknown provider id, missing secrets entry. Mirrors the
                // existing data-resolver config-bug treatment.
                //
                // Exception for logout: if the handler can't be built (e.g.,
                // preset was removed after the user logged in), fall back to
                // local-only logout — the user's intent of "log me out" must
                // succeed even when the provider catalogue changed under
                // them. The provider-side token will expire naturally.
                if ($firstKind === 'oauth-logout') {
                    error_log(
                        "OAuth logout: handler construction failed (provider='{$__provider}'): "
                        . $__oauthErr->getMessage()
                        . '. Falling back to local-only logout.'
                    );
                    clearOAuthSession($__logoutSessionId);
                    // ⚠ A cookie is cleared by NAME and PATH. Both must match the
                    // set exactly, or this expires a cookie that does not exist
                    // and the session cookie survives a "successful" logout.
                    setcookie(qs_project_cookie_name(QS_OAUTH_COOKIE), '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'secure'   => _oauthIsHttps(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    header('Location: ' . ($_GET['return'] ?? '/'), true, 302);
                    exit;
                }
                // C12 (F9): this is the PUBLIC site. It used to echo the raw
                // exception message plus the names of the secret files to any
                // anonymous visitor who hit a misconfigured OAuth route. The
                // operator's diagnosis now goes to the error log; the visitor gets
                // the fact that it is misconfigured and nothing about the server.
                require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';
                $__oauthSafe = qs_safe_error_message($__oauthErr, 'oauth:' . $routePath);
                http_response_code(500);
                echo "<h1>500 — OAuth misconfigured</h1>\n";
                echo "<p>This sign-in route is not correctly configured.</p>\n";
                if (qs_is_development()) {
                    echo '<p>Route: <code>' . htmlspecialchars($routePath) . "</code></p>\n";
                    echo '<p>Provider: <code>' . htmlspecialchars((string) $__provider) . "</code></p>\n";
                    echo '<p>Error: ' . htmlspecialchars($__oauthSafe) . "</p>\n";
                    echo "<p><small>Fix the OAuth config (oauth-presets.json / oauth-secrets.php) and reload.</small></p>\n";
                }
                exit;
            }

            switch ($firstKind) {
                case 'oauth-start':
                    $__oauthResult = $__oauthHandler->handleStart($__oauthCfg, $_GET['return'] ?? null);
                    break;
                case 'oauth-callback':
                    $__oauthResult = $__oauthHandler->handleCallback($__oauthCfg, $_GET);
                    break;
                default: // 'oauth-logout'
                    $__oauthResult = $__oauthHandler->handleLogout($__oauthCfg, $__logoutSessionId, $_GET['return'] ?? null);
            }

            // Apply optional session cookie + 302 redirect. Return shape
            // locked in OAuthHandler's docblock: ['redirect' => $url,
            // 'cookie' => null | ['name'=>..., 'value'=>..., 'options'=>[...]]].
            if (!empty($__oauthResult['cookie'])) {
                $__c = $__oauthResult['cookie'];
                setcookie($__c['name'], $__c['value'], $__c['options'] ?? []);
            }
            $__redirect = $__oauthResult['redirect'] ?? '/';
            header('Location: ' . $__redirect, true, 302);
            exit;
        }

    }
}
