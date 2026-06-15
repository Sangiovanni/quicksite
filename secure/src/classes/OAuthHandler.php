<?php
/**
 * OAuthHandler — Server-side OAuth 2.0 Authorization Code + PKCE flow (beta.9 A1).
 *
 * Drives the two halves of the OAuth login flow:
 *
 *   handleStart(provider, returnTo?)
 *     Called by the `oauth-start` route-resolver kind. Generates state +
 *     PKCE verifier/challenge, stores them server-side, builds the
 *     provider's authorize URL with all required params, returns a 302
 *     for the resolver to apply.
 *
 *   handleCallback(provider, query)
 *     Called by the `oauth-callback` route-resolver kind. Validates the
 *     returned `state` against what was stored at start (single-use),
 *     POSTs the `code` + `client_secret` + `code_verifier` to the
 *     provider's token endpoint, fetches userinfo with the access_token,
 *     creates a server-side session record, returns a 302 + session-cookie
 *     spec for the resolver to apply.
 *
 *
 * Architecture decisions (see docs/DESIGN_DECISIONS.md, locked 2026-06-14):
 *
 *   - **Token custody = BFF** (provider tokens stay server-side; browser
 *     gets a first-party HttpOnly session cookie). Aligns with the beta.10
 *     XSS threat model; matches IETF "OAuth 2.0 for Browser-Based Apps"
 *     BCP recommendation.
 *   - **Callback + start hooks = route-resolver kinds** (`oauth-callback`,
 *     `oauth-start`). Reuses beta.8's resolver-attachment UX; routes stay
 *     user-authored.
 *   - **State + session storage = PHP sessions behind a thin abstraction**
 *     (oauthStateStore.php). Swap-to-file is a one-file change later if
 *     project-local-with-encryption or multi-language support emerges.
 *   - **PKCE always-on** for all clients (belt-and-braces against partial
 *     code-leak attacks).
 *   - **Provider presets = JSON** (`secure/admin/config/oauth-presets.json`).
 *     Authors extend without PHP knowledge.
 *   - **Client secrets = dedicated PHP file** (`oauth-secrets.php`), not
 *     `api-secrets.php` — different lifecycle + blast radius.
 *
 *
 * Resolver return shape (consumed by the resolver-kind dispatcher):
 *
 *   [
 *     'redirect' => 'https://...',   // 302 target
 *     'cookie'   => null | [          // optional session cookie to set
 *       'name'     => 'qs_oauth_session',
 *       'value'    => '<sessionId>',
 *       'options'  => ['httponly' => true, 'secure' => true,
 *                      'samesite' => 'Lax', 'path' => '/'],
 *     ],
 *   ]
 *
 *
 * Slice status:
 *
 *   - Resolver-kind registration in resolverHelpers (Slice 2b — DONE)
 *   - Start-flow logic (Slice 2c — DONE)
 *   - Callback-flow logic (Slice 2d — DONE)
 *   - Logout + session-helpers (Slice 2e — pending)
 */

class OAuthHandler
{
    /**
     * Default post-auth session lifetime (14 days). Matches the test.oauth
     * fixture's refresh-token TTL and is a common SaaS-app default. Per
     * the locked decision, configurable later via per-API auth config
     * (same knob as resolver cacheTTL precedent); hardcoded constant for
     * 2d MVP.
     */
    private const SESSION_TTL_SECONDS = 14 * 86400;

    /** @var array<string, mixed> preset for the active provider */
    private array $preset;

    /** @var string the active provider id (matches preset key) */
    private string $providerId;

    /** @var array{client_id: string, client_secret: ?string} secrets for the active provider */
    private array $secret;

    /**
     * Construct an OAuthHandler for a specific provider. The preset +
     * secret are loaded eagerly so misconfiguration surfaces at construct
     * time rather than mid-flow.
     *
     * @throws RuntimeException when the provider id has no matching preset
     *                          or secrets entry.
     */
    public function __construct(string $providerId)
    {
        $this->providerId = $providerId;
        $this->preset = self::loadPreset($providerId);
        $this->secret = self::loadSecret($providerId);
    }

    /**
     * Start the OAuth flow. Resolver calls this; we return the redirect
     * spec for the resolver to apply.
     *
     * Flow:
     *   1. Generate `state` (16 random bytes hex-encoded; 32 chars).
     *   2. Generate PKCE `code_verifier` (32 random bytes base64url-encoded;
     *      ~43 chars — RFC 7636 minimum, 256 bits of entropy) +
     *      `code_challenge = base64url(SHA256(verifier))`.
     *   3. Resolve `redirect_uri` from `$config['callback_url']` (already
     *      substituted of `{:routeParam}` placeholders by the dispatcher);
     *      default to `/auth/oauth/<provider>/callback`. If relative,
     *      make absolute against the current scheme + host.
     *   4. Store `state` → {verifier, provider, returnTo, redirect_uri}
     *      via `storeOAuthState()` with ~10-min TTL, single-use.
     *   5. Build the provider's authorize URL with `client_id`,
     *      `redirect_uri`, `response_type=code`, `scope`, `state`,
     *      `code_challenge`, `code_challenge_method=S256`, + any preset
     *      `extra_authorize_params`.
     *   6. Return `['redirect' => $authorizeUrl, 'cookie' => null]`.
     *      (PHP's `session_start()` inside `storeOAuthState()` already
     *      sets the `qs_oauth_session` cookie that holds the state record,
     *      so the dispatcher doesn't need to set anything extra.)
     *
     * @param array<string, mixed> $config The resolver config (provider,
     *                                     callback_url, …); placeholder
     *                                     substitution already done by the
     *                                     dispatcher.
     * @param string|null          $returnTo Optional post-login redirect
     *                                       target. Only same-site paths
     *                                       starting with '/' (and not '//')
     *                                       are honoured; everything else
     *                                       is dropped to prevent open-
     *                                       redirect abuse.
     * @return array{redirect: string, cookie: null}
     */
    public function handleStart(array $config, ?string $returnTo = null): array
    {
        $state = bin2hex(random_bytes(16));
        $codeVerifier = self::base64url(random_bytes(32));
        $codeChallenge = self::base64url(hash('sha256', $codeVerifier, true));

        $callbackUrl = isset($config['callback_url']) && is_string($config['callback_url']) && $config['callback_url'] !== ''
            ? $config['callback_url']
            : '/auth/oauth/' . $this->providerId . '/callback';
        $redirectUri = self::makeAbsoluteUrl($callbackUrl);

        $safeReturnTo = self::sanitiseReturnTo($returnTo);

        storeOAuthState($state, [
            'verifier'     => $codeVerifier,
            'provider'     => $this->providerId,
            'returnTo'     => $safeReturnTo,
            'redirect_uri' => $redirectUri,
        ], 600);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->secret['client_id'],
            'redirect_uri'          => $redirectUri,
            'scope'                 => (string) ($this->preset['scope'] ?? ''),
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        $extra = $this->preset['extra_authorize_params'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $params[(string) $k] = (string) $v;
            }
        }

        $authorizeUrl = $this->preset['authorize_url'] . '?' . http_build_query($params);

        return ['redirect' => $authorizeUrl, 'cookie' => null];
    }

    /**
     * Complete the OAuth flow on callback. Resolver calls this; we return
     * the redirect + cookie spec for the resolver to apply.
     *
     * Flow:
     *   1. Validate `state` from query against the record stored at start
     *      via `consumeOAuthState` (single-use; expired/missing => redirect
     *      to '/' with ?oauth_error=invalid_state).
     *   2. If the provider returned an `error` param (user clicked Deny,
     *      consent revoked, etc.), redirect to the sanitised returnTo
     *      from the state record (or '/') with ?oauth_error=<code>.
     *   3. Exchange `code` for tokens at the preset's `token_url` —
     *      Basic auth (client_id:client_secret) + form body with
     *      grant_type=authorization_code, code, redirect_uri (the SAME
     *      value sent at start, recovered from the state record per
     *      OAuth2 spec §4.1.3), code_verifier (PKCE).
     *   4. Fetch userinfo at the preset's `userinfo_url` with the access
     *      token (Bearer). Extract `sub`, `email`, optional `name` via
     *      the preset's dot-paths.
     *   5. Generate an opaque 32-byte session id (64 hex chars), store
     *      the session record server-side via `storeOAuthSession` with
     *      a 14-day TTL.
     *   6. Return `['redirect' => $returnTo ?? '/', 'cookie' => [name:
     *      qs_oauth_user, value: sessionId, options: ...]]`.
     *
     * Token custody: provider tokens NEVER reach the browser. They live
     * in the server-side session record only (BFF pattern locked
     * 2026-06-14). The browser carries an opaque sessionId in the
     * qs_oauth_user cookie; the server looks up the user record from
     * there.
     *
     * Error handling: recoverable failures (provider denial, token
     * exchange 4xx/5xx, userinfo 4xx/5xx, missing required userinfo
     * fields) redirect to returnTo with ?oauth_error=<code> rather than
     * throwing. Unrecoverable issues (network failures, malformed
     * config) bubble as PHP errors.
     *
     * @param array<string, mixed>  $config Resolver config (provider,
     *                                      callback_url, …); placeholder
     *                                      substitution already done by
     *                                      the dispatcher. Accepted for
     *                                      signature parity with
     *                                      handleStart; current 2d uses
     *                                      only the values stored in the
     *                                      state record (set at start).
     * @param array<string, string> $query  Query params from the callback
     *                                      URL — `code` + `state` on
     *                                      success, or `error` (+ optional
     *                                      `error_description`) on denial.
     * @return array{redirect: string, cookie: ?array{name:string,value:string,options:array}}
     */
    public function handleCallback(array $config, array $query): array
    {
        $state = isset($query['state']) ? (string) $query['state'] : '';
        if ($state === '') {
            return self::buildErrorRedirect('/', 'invalid_state');
        }
        $stateRecord = consumeOAuthState($state);
        if ($stateRecord === null) {
            return self::buildErrorRedirect('/', 'invalid_state');
        }

        $returnTo = isset($stateRecord['returnTo']) && is_string($stateRecord['returnTo']) && $stateRecord['returnTo'] !== ''
            ? $stateRecord['returnTo']
            : '/';

        if (isset($query['error']) && is_string($query['error']) && $query['error'] !== '') {
            return self::buildErrorRedirect($returnTo, (string) $query['error']);
        }

        $code = isset($query['code']) ? (string) $query['code'] : '';
        if ($code === '') {
            return self::buildErrorRedirect($returnTo, 'missing_code');
        }

        $redirectUri = isset($stateRecord['redirect_uri']) ? (string) $stateRecord['redirect_uri'] : '';
        $verifier    = isset($stateRecord['verifier'])     ? (string) $stateRecord['verifier']     : '';

        $tokenResp = $this->exchangeCodeForTokens($code, $verifier, $redirectUri);
        if ($tokenResp === null || !isset($tokenResp['access_token']) || $tokenResp['access_token'] === '') {
            return self::buildErrorRedirect($returnTo, 'token_exchange_failed');
        }

        $userinfo = $this->fetchUserInfo((string) $tokenResp['access_token']);
        if ($userinfo === null) {
            return self::buildErrorRedirect($returnTo, 'userinfo_failed');
        }

        $sub = self::dotPath($userinfo, (string) ($this->preset['userinfo_sub_path'] ?? 'sub'));
        if ($sub === null || $sub === '') {
            return self::buildErrorRedirect($returnTo, 'userinfo_missing_sub');
        }
        $email = self::dotPath($userinfo, (string) ($this->preset['userinfo_email_path'] ?? 'email'));
        $name  = isset($this->preset['userinfo_name_path'])
            ? self::dotPath($userinfo, (string) $this->preset['userinfo_name_path'])
            : null;

        $sessionId = bin2hex(random_bytes(32));
        $now       = time();
        $sessionRecord = [
            'provider'         => $this->providerId,
            'sub'              => (string) $sub,
            'email'            => $email !== null ? (string) $email : null,
            'name'             => $name !== null ? (string) $name : null,
            'access_token'     => (string) $tokenResp['access_token'],
            'refresh_token'    => isset($tokenResp['refresh_token']) ? (string) $tokenResp['refresh_token'] : null,
            'token_expires_at' => isset($tokenResp['expires_in']) ? $now + (int) $tokenResp['expires_in'] : null,
            'scope'            => isset($tokenResp['scope']) ? (string) $tokenResp['scope'] : null,
            'issued_at'        => $now,
        ];

        storeOAuthSession($sessionId, $sessionRecord, self::SESSION_TTL_SECONDS);

        return [
            'redirect' => $returnTo,
            'cookie'   => [
                'name'    => 'qs_oauth_user',
                'value'   => $sessionId,
                'options' => [
                    'expires'  => $now + self::SESSION_TTL_SECONDS,
                    'path'     => '/',
                    'secure'   => _oauthIsHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
            ],
        ];
    }

    /**
     * POST the authorization code to the provider's token endpoint per
     * RFC 6749 §4.1.3. Uses HTTP Basic auth (client_secret_basic — the
     * spec's preferred scheme per §2.3.1). Returns the decoded JSON
     * response on success, null on transport failure or non-2xx status.
     */
    private function exchangeCodeForTokens(string $code, string $codeVerifier, string $redirectUri): ?array
    {
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $basic = base64_encode($this->secret['client_id'] . ':' . ($this->secret['client_secret'] ?? ''));

        $result = self::httpRequest(
            'POST',
            (string) $this->preset['token_url'],
            $body,
            [
                'Authorization: Basic ' . $basic,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );
        if ($result === null || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        $json = json_decode($result['body'], true);
        return is_array($json) ? $json : null;
    }

    /**
     * Fetch userinfo with the access token (Bearer). User-Agent is set
     * unconditionally — GitHub's api.github.com REQUIRES it (403 otherwise);
     * other providers ignore it but accept it.
     */
    private function fetchUserInfo(string $accessToken): ?array
    {
        $result = self::httpRequest(
            'GET',
            (string) $this->preset['userinfo_url'],
            null,
            [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'User-Agent: QuickSite-OAuth/1.0',
            ]
        );
        if ($result === null || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        $json = json_decode($result['body'], true);
        return is_array($json) ? $json : null;
    }

    // ====================================================================
    // Loaders — preset + secret resolution.
    //
    // Lookup order (Slice 2.5 — "per-project config" locked 2026-06-15):
    //
    //   1. Project file (secure/projects/<active>/data/oauth-{presets,secrets}.json)
    //      — primary lookup. Each project owns its own credentials and
    //        can override the engine catalogue with custom providers,
    //        modified scopes, etc.
    //   2. Admin file (secure/admin/config/oauth-{presets.json,secrets.php})
    //      — fallback / engine-wide default. Holds the canonical provider
    //        catalogue (URLs, default scope, userinfo paths) plus any
    //        engine-wide credentials (typical case: the test-oauth
    //        fixture credentials used across dev projects).
    //
    // Override is at PROVIDER level (full-entry replace, not field-level
    // merge): if a project's file declares google, it owns google
    // entirely; admin's google is ignored for that project. Authors who
    // want to tweak one field copy the whole admin entry and edit it.
    // Predictable beats clever — no surprise merge resolution.
    //
    // Each loader surfaces misconfig with an explicit error so authors
    // get a clear "what's missing" message rather than a null-deref
    // deeper in the flow.
    // ====================================================================

    /**
     * Load and return the preset for `$providerId`. Per-project
     * `data/oauth-presets.json` takes precedence over the admin
     * catalogue at `secure/admin/config/oauth-presets.json`.
     */
    private static function loadPreset(string $providerId): array
    {
        $projectPath = self::projectConfigPath('oauth-presets.json');
        if ($projectPath !== null && file_exists($projectPath)) {
            $projectPresets = self::readJsonFile($projectPath, 'OAuth presets');
            if (isset($projectPresets[$providerId]) && is_array($projectPresets[$providerId])) {
                return $projectPresets[$providerId];
            }
        }

        $adminPath = SECURE_FOLDER_PATH . '/admin/config/oauth-presets.json';
        if (!file_exists($adminPath)) {
            throw new RuntimeException(
                "OAuth presets file not found: $adminPath. Ship/restore "
                . 'oauth-presets.json in secure/admin/config/.'
            );
        }
        $admin = self::readJsonFile($adminPath, 'OAuth presets');
        if (!isset($admin[$providerId]) || !is_array($admin[$providerId])) {
            throw new RuntimeException(
                "OAuth provider preset '$providerId' not found. Add it to "
                . ($projectPath ?? '<project>/data/oauth-presets.json')
                . " (per-project) or $adminPath (engine catalogue)."
            );
        }
        return $admin[$providerId];
    }

    /**
     * Load and return the secret entry for `$providerId`. Per-project
     * `data/oauth-secrets.json` (JSON, gitignored per .gitignore rule)
     * takes precedence over the admin fallback at
     * `secure/admin/config/oauth-secrets.php` (PHP, gitignored).
     *
     * Admin-level secrets keep their PHP shape because they may carry
     * env-var interpolation in deployed installs (mirrors
     * api-secrets.php); per-project secrets are JSON because per-project
     * data follows the locked "JSON for the author's website data"
     * principle and lets authors edit without PHP knowledge.
     */
    private static function loadSecret(string $providerId): array
    {
        $projectPath = self::projectConfigPath('oauth-secrets.json');
        if ($projectPath !== null && file_exists($projectPath)) {
            $projectSecrets = self::readJsonFile($projectPath, 'OAuth secrets');
            if (isset($projectSecrets[$providerId]) && is_array($projectSecrets[$providerId])
                && isset($projectSecrets[$providerId]['client_id'])
            ) {
                return self::normaliseSecretEntry($projectSecrets[$providerId]);
            }
        }

        $adminPath = SECURE_FOLDER_PATH . '/admin/config/oauth-secrets.php';
        if (file_exists($adminPath)) {
            $admin = require $adminPath;
            if (is_array($admin) && isset($admin[$providerId]) && is_array($admin[$providerId])
                && isset($admin[$providerId]['client_id'])
            ) {
                return self::normaliseSecretEntry($admin[$providerId]);
            }
        }

        $projectHint = $projectPath ?? '<project>/data/oauth-secrets.json';
        throw new RuntimeException(
            "OAuth secrets for provider '$providerId' not found. Add an "
            . "entry with 'client_id' (+ 'client_secret' for confidential "
            . "clients) to either $projectHint (per-project, JSON) or "
            . "$adminPath (engine-wide fallback, PHP — copy "
            . 'oauth-secrets.php.example if it does not exist yet).'
        );
    }

    /**
     * Resolve an absolute path to a per-project config file. Returns
     * null when PROJECT_PATH is undefined (very early request boot;
     * shouldn't happen in the OAuth flow but defensive). Always returns
     * a non-existent path string when the active project simply has no
     * such config file — callers must `file_exists()` check before
     * reading.
     */
    private static function projectConfigPath(string $fileName): ?string
    {
        if (!defined('PROJECT_PATH')) {
            return null;
        }
        return PROJECT_PATH . '/data/' . $fileName;
    }

    /**
     * Read + decode a JSON config file. Throws with explicit context
     * (which file, what kind) so authors get a useful error in the
     * 500 page instead of "json_decode returned null".
     */
    private static function readJsonFile(string $path, string $kind): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("$kind file unreadable: $path");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "$kind file invalid JSON ($path): " . json_last_error_msg()
            );
        }
        return $decoded;
    }

    /**
     * Coerce a secret entry to the canonical {client_id, client_secret}
     * shape. `client_secret` is null for public clients (PKCE-only).
     */
    private static function normaliseSecretEntry(array $entry): array
    {
        return [
            'client_id' => (string) $entry['client_id'],
            'client_secret' => isset($entry['client_secret'])
                ? (string) $entry['client_secret']
                : null,
        ];
    }

    // ====================================================================
    // Helpers — URL + encoding + sanitisation utilities used by both
    // start (2c) and (forthcoming) callback (2d) flows.
    // ====================================================================

    /**
     * Base64URL encoding per RFC 4648 §5 (URL-and-filename-safe alphabet,
     * no padding). Used for the PKCE `code_verifier`/`code_challenge` and
     * by RFC 7636 §4 explicitly: standard base64 with `+`→`-`, `/`→`_`,
     * and trailing `=` removed.
     */
    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Promote a relative URL to absolute against the current request's
     * scheme + host. Pass-through when already absolute (starts with
     * `http://` or `https://`). Honours `X-Forwarded-Proto` for the common
     * reverse-proxy case (project-level base-url config is a deferred
     * escape hatch — see DESIGN_DECISIONS.md "OAuth handleStart shape").
     */
    private static function makeAbsoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $scheme = _oauthIsHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }
        return $scheme . '://' . $host . $url;
    }

    /**
     * Open-redirect guard for the `returnTo` value. Only same-site paths
     * starting with `/` (and NOT `//`, which would be a protocol-relative
     * URL pointing off-site) are honoured; everything else is dropped to
     * null so the callback handler falls back to the safe default.
     */
    private static function sanitiseReturnTo(?string $returnTo): ?string
    {
        if ($returnTo === null || $returnTo === '') {
            return null;
        }
        if ($returnTo[0] !== '/' || (isset($returnTo[1]) && $returnTo[1] === '/')) {
            return null;
        }
        return $returnTo;
    }

    /**
     * Minimal cURL wrapper for the OAuth back-channel (token exchange +
     * userinfo). Returns ['status' => int, 'body' => string] on a
     * received HTTP response (any status); null on transport failure
     * (DNS, connect, timeout, etc.). Never follows redirects — OAuth's
     * back-channel responses are direct, and following blindly would
     * mask provider misconfig as opaque success.
     */
    private static function httpRequest(string $method, string $url, ?string $body, array $headers): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $respBody = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($respBody === false) {
            return null;
        }
        return ['status' => $status, 'body' => (string) $respBody];
    }

    /**
     * Resolve a dot-path against a nested array. 'id' returns
     * $arr['id']; 'data.user.email' returns $arr['data']['user']['email'].
     * Returns null if any segment is missing or non-array along the way.
     * Used to read provider userinfo fields per the preset's configured
     * dot-paths (different providers nest the user record differently).
     */
    private static function dotPath(array $arr, string $path)
    {
        $current = $arr;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * Build a redirect response carrying ?oauth_error=<code> so the
     * destination page can surface a UX message. Preserves any existing
     * query string on $returnTo (uses '&' instead of '?' when needed).
     * Cookie is null — error paths never establish a session.
     */
    private static function buildErrorRedirect(string $returnTo, string $code): array
    {
        $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
        return [
            'redirect' => $returnTo . $sep . 'oauth_error=' . urlencode($code),
            'cookie'   => null,
        ];
    }
}
