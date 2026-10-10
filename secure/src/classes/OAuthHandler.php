<?php
/**
 * OAuthHandler — Server-side OAuth 2.0 Authorization Code + PKCE flow.
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
 * Architecture decisions (see docs/DESIGN_DECISIONS.md):
 *
 *   - **Token custody = BFF** (provider tokens stay server-side; browser
 *     gets a first-party HttpOnly session cookie). Aligns with the
 *     XSS threat model; matches IETF "OAuth 2.0 for Browser-Based Apps"
 *     BCP recommendation.
 *   - **Callback + start hooks = route-resolver kinds** (`oauth-callback`,
 *     `oauth-start`). Reuses the resolver-attachment UX; routes stay
 *     user-authored.
 *   - **State + session storage = PHP sessions behind a thin abstraction**
 *     (oauthStateStore.php). Swap-to-file is a one-file change later if
 *     project-local-with-encryption or multi-language support emerges.
 *   - **PKCE always-on** for all clients (belt-and-braces against partial
 *     code-leak attacks).
 *   - **Providers = one JSON list the operator edits**
 *     (`<secure>/management/config/oauth-providers.json`, else the shipped
 *     `.example`). No project defines a provider. See oauthProviderHelpers.php.
 *   - **Keys = the project's own** (`data/oauth-secrets.json`): a `preview`
 *     set for this installation and a `build` set a build carries. There are
 *     no installation-wide keys.
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
 */

require_once __DIR__ . '/OutboundUrlPolicy.php';
// Only qs_project_cookie_name() and qs_request_host() are needed, and both
// live in requestRuntime.php — storageHelpers and projectContext are
// authoring files that cannot travel into a production build.
require_once __DIR__ . '/../functions/requestRuntime.php'; // qs_project_cookie_name, qs_site_path
require_once __DIR__ . '/../functions/oauthProviderHelpers.php'; // qs_oauth_provider, qs_oauth_sign_in_keys

class OAuthHandler
{
    /**
     * Default post-auth session lifetime (14 days), a common SaaS-app
     * default. A
     * hardcoded constant, by design (DESIGN_DECISIONS.md "OAuth handleCallback
     * shape", which names the 14d TTL).
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
     * @param mixed                $returnTo Optional post-login redirect
     *                                       target (`?return=`). Kept only
     *                                       when it is a path on this site
     *                                       naming one of its routes (see
     *                                       sanitiseReturnTo); anything else
     *                                       lands on the site's home.
     * @return array{redirect: string, cookie: null}
     */
    public function handleStart(array $config, $returnTo = null): array
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
     *   6. Return `['redirect' => <the page returnTo names, on this site>,
     *      'cookie' => [name: qs_oauth_user, value: sessionId, options: ...]]`.
     *
     * Token custody: provider tokens NEVER reach the browser. They live
     * in the server-side session record only (the BFF pattern, by
     * design). The browser carries an opaque sessionId in the
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
     *                                      handleStart; the callback uses
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

        // Checked again on the way out: the record is the server's own, but the rule is cheap and
        // a record written before an upgrade would otherwise be followed as stored.
        $returnTo = self::sanitiseReturnTo($stateRecord['returnTo'] ?? null) ?? '/';

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
            'redirect' => self::siteUrl($returnTo),
            'cookie'   => [
                // Namespaced per project: one host serves every project at
                // /p/<id>/ and they share one cookie jar. Set, read and CLEAR
                // must all compose through the same helper.
                'name'    => qs_project_cookie_name(QS_OAUTH_COOKIE),
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
     * Log the user out: optionally revoke the access token at the
     * provider (if the preset declares `revoke_url`), clear the server-
     * side session record, return a redirect + cookie-expiration spec.
     *
     * Dispatcher contract:
     *   - Resolves the session BEFORE constructing OAuthHandler (so the
     *     handler is built with the session's provider, not the URL).
     *   - Calls handleLogout with the resolved sessionId + the resolver
     *     config (used for the optional sanity check on `provider`) +
     *     `?return` query param.
     *   - When there's no session at all (cookie missing / expired), the
     *     dispatcher short-circuits before reaching this method — see
     *     public/index.php oauth-logout branch.
     *
     * Provider-side revoke per RFC 7009: POST `token=<access_token>` to
     * the preset's `revoke_url` with client_secret_basic auth. Failure
     * is logged but doesn't block local logout — the user's intent of
     * "log me out HERE" succeeds regardless. Providers without an
     * RFC 7009-compatible revoke endpoint (GitHub, Meta) simply omit
     * `revoke_url` in their preset and we skip the call.
     *
     * Cookie expiration uses `expires` in the past (rather than
     * Max-Age=0) for broadest browser compatibility — same attrs as
     * the original set call (HttpOnly + Secure-when-HTTPS + SameSite=Lax)
     * so the browser recognises it as the same cookie to overwrite.
     *
     * @param array<string, mixed> $config       Resolver config (used to
     *                                           sanity-check provider field
     *                                           when present).
     * @param string               $sessionId    Value of the qs_oauth_user
     *                                           cookie (the dispatcher
     *                                           confirmed it points at a
     *                                           live session before calling).
     * @param mixed                $returnTo     Optional post-logout
     *                                           redirect (`?return=`), kept
     *                                           by the same rule as
     *                                           handleStart's.
     * @return array{redirect: string, cookie: array{name:string,value:string,options:array}}
     */
    public function handleLogout(array $config, string $sessionId, $returnTo = null): array
    {
        $record = getOAuthSession($sessionId);

        if ($record !== null && isset($config['provider']) && is_string($config['provider']) && $config['provider'] !== '') {
            $declaredProvider = (string) $config['provider'];
            $actualProvider   = isset($record['provider']) ? (string) $record['provider'] : '';
            if ($declaredProvider !== $actualProvider) {
                error_log(
                    "OAuth logout sanity-check mismatch: resolver declared "
                    . "provider='{$declaredProvider}' but active session is "
                    . "for '{$actualProvider}'. Proceeding with logout for "
                    . "'{$actualProvider}' (cookie is the truth)."
                );
            }
        }

        if ($record !== null
            && isset($this->preset['revoke_url']) && is_string($this->preset['revoke_url']) && $this->preset['revoke_url'] !== ''
            && isset($record['access_token']) && is_string($record['access_token']) && $record['access_token'] !== ''
        ) {
            $this->revokeAtProvider((string) $this->preset['revoke_url'], (string) $record['access_token']);
        }

        clearOAuthSession($sessionId);

        return [
            'redirect' => self::returnTarget($returnTo),
            'cookie'   => [
                // Namespaced per project: one host serves every project at
                // /p/<id>/ and they share one cookie jar. Set, read and CLEAR
                // must all compose through the same helper.
                'name'    => qs_project_cookie_name(QS_OAUTH_COOKIE),
                'value'   => '',
                'options' => [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => _oauthIsHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
            ],
        ];
    }

    /**
     * POST the access token to the provider's RFC 7009 revoke endpoint
     * with client_secret_basic auth. Failure is logged + swallowed —
     * provider-side revoke is best-effort; local logout always succeeds.
     */
    private function revokeAtProvider(string $revokeUrl, string $accessToken): void
    {
        $body  = http_build_query([
            'token'           => $accessToken,
            'token_type_hint' => 'access_token',
        ]);
        $basic = base64_encode($this->secret['client_id'] . ':' . ($this->secret['client_secret'] ?? ''));

        $result = self::httpRequest(
            'POST',
            $revokeUrl,
            $body,
            [
                'Authorization: Basic ' . $basic,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );
        if ($result === null || $result['status'] < 200 || $result['status'] >= 300) {
            error_log(
                "OAuth revoke at provider '{$this->providerId}' failed "
                . '(status=' . ($result['status'] ?? 'transport_error') . '). '
                . 'Local logout proceeded; provider-side token may remain '
                . 'valid until natural expiry.'
            );
        }
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
        if ($result === null) {
            return null; // the transport failure is already logged
        }
        $json = json_decode($result['body'], true);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($json) || empty($json['access_token'])) {
            self::logProviderRefusal('token exchange', $this->providerId, $result['status'], is_array($json) ? $json : null);
            return null;
        }
        return $json;
    }

    /**
     * Log why a provider refused a back-channel request: the HTTP status and the provider's own
     * `error` / `error_description` (RFC 6749 §5.2), which name the cause — a wrong secret
     * (`invalid_client`, GitHub's `incorrect_client_credentials`), a callback address the app does
     * not list (`redirect_uri_mismatch`), a code already used or expired (`invalid_grant`). Nothing
     * else from the answer is written: it may hold a token.
     */
    private static function logProviderRefusal(string $what, string $providerId, int $status, ?array $json): void
    {
        $field = static function (?array $j, string $k): string {
            $v = $j[$k] ?? null;
            return is_string($v) ? substr((string) preg_replace('/[^\x20-\x7E]/', '?', $v), 0, 200) : '-';
        };
        error_log("OAuth $what at provider '$providerId' refused: HTTP $status, error=" . $field($json, 'error')
            . ', description=' . $field($json, 'error_description'));
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
        if ($result === null) {
            return null; // the transport failure is already logged
        }
        $json = json_decode($result['body'], true);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($json)) {
            self::logProviderRefusal('userinfo request', $this->providerId, $result['status'], is_array($json) ? $json : null);
            return null;
        }
        return $json;
    }

    // ====================================================================
    // Loaders — the provider's entry and the keys this sign-in uses.
    //
    // The provider comes from the installation's list, never from the
    // project: one file the operator edits (see oauthProviderHelpers.php).
    // The keys come from the project: its `preview` set on this
    // installation; in a built site the server's QS_OAUTH_<PROVIDER>_*
    // variables, else the `build` set the build carried.
    //
    // Each loader surfaces misconfiguration with an explicit error, which
    // the dispatcher logs and answers with its "not configured" page.
    // ====================================================================

    /** The provider's entry from the installation's list. */
    private static function loadPreset(string $providerId): array
    {
        $entry = qs_oauth_provider($providerId);
        if ($entry === null) {
            throw new RuntimeException(
                "OAuth provider '$providerId' is not offered: it is not in " . qs_oauth_providers_label()
                . ', or its entry there is malformed (the reason is in the error log).'
            );
        }
        return $entry;
    }

    /** The keys this request's sign-in uses for the provider. */
    private static function loadSecret(string $providerId): array
    {
        $keys = qs_oauth_sign_in_keys($providerId);
        if ($keys === null) {
            throw new RuntimeException(qs_oauth_on_installation()
                ? "This project has no preview keys for OAuth provider '$providerId'. Its owner or admin enters them on the OAuth providers page."
                : "This site has no keys for OAuth provider '$providerId': set QS_OAUTH_<PROVIDER>_CLIENT_ID and _CLIENT_SECRET on the server, or enter the project's build keys and build again."
            );
        }
        return $keys;
    }

    // ====================================================================
    // Helpers — URL, encoding and sanitisation utilities.
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
        // The host is the VALIDATED one, never the raw header.
        //
        // This builds the OAuth `redirect_uri` (see handleStart), so a poisoned
        // Host used to put an attacker-chosen origin into it. Whether the
        // provider's registered-redirect allowlist saves you depends on the
        // provider, and it was worth checking rather than assuming: Google,
        // Meta and Amazon all require an EXACT match, so an injected host is
        // simply refused — but GitHub matches the callback's host **excluding
        // sub-domains**, so `evil.example.com` is accepted for an app
        // registered at `example.com` and the authorization code lands on the
        // attacker's host. qs_request_host() shape-validates and honours
        // QS_TRUSTED_HOSTS, which closes that.
        //
        // The scheme deliberately stays with _oauthIsHttps(): it also honours
        // X-Forwarded-Proto, which the origin helper does not, and taking the
        // whole origin instead would have downgraded every reverse-proxy
        // deployment to http.
        
        $scheme = _oauthIsHttps() ? 'https' : 'http';
        $host = qs_request_host();
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }

        // The site's PUBLIC BASE, not the bare origin.
        //
        // An author configures `callback_url: /auth/callback`, which is the
        // route relative to their own site. That site is at `/` in a build
        // and at `/p/<projectId>/` in preview, so composing against the
        // origin alone produced a redirect_uri pointing outside the project
        // — the flow could not be previewed against any provider that
        // validates redirect URIs, which is all of them. Same class as the
        // alias redirect that composed against the wrong base.
        //
        // An absolute callback_url still wins (returned above); this only
        // affects the root-relative form, which is what the base is for.
        $base = defined('QS_PUBLIC_BASE') ? rtrim(QS_PUBLIC_BASE, '/') : '';

        return $scheme . '://' . $host . $base . $url;
    }

    /**
     * Where a `?return=` sends the browser: the page it names, on this site, or the site's home.
     *
     * Every OAuth redirect a stranger's value can reach goes through here or through
     * sanitiseReturnTo(): the sign-in (stored at start, followed after the callback), the
     * callback's error redirects, the sign-out and the sign-out's two early exits in
     * oauthRuntime.php, which run before any handler exists.
     *
     * @param mixed $returnTo The raw value: a string, an array, or null.
     */
    public static function returnTarget($returnTo): string
    {
        return self::siteUrl(self::sanitiseReturnTo($returnTo) ?? '/');
    }

    /**
     * A path on this site, composed against the site's public base: `/` in a build at the
     * domain's root, the URL space in a build below it, `/p/<projectId>/` in preview. The path
     * names a page of the SITE, so `/` alone is the site's home, not the host's.
     */
    private static function siteUrl(string $sitePath): string
    {
        $base = defined('QS_PUBLIC_BASE') ? rtrim(QS_PUBLIC_BASE, '/') : '';
        return $base . $sitePath;
    }

    /**
     * Open-redirect guard for the `returnTo` value. Two layers:
     *
     *   1. A path on this site (`qs_site_path()`, the rule `qs.js` shares):
     *      one `/`, then neither a second `/` nor a backslash, and no
     *      control character. Everything else could be read by a browser
     *      as another host or another scheme.
     *   2. A registered route in ROUTES (rejects typo'd and made-up paths,
     *      by design).
     *
     * Anything that fails returns null, and the caller lands on the site's
     * home. A query string and a fragment survive the check (set aside
     * for the route match, kept in the value returned).
     *
     * @param mixed $returnTo
     */
    private static function sanitiseReturnTo($returnTo): ?string
    {
        $returnTo = qs_site_path($returnTo);
        if ($returnTo === null) {
            return null;
        }

        // Split off query + fragment for the route-existence check.
        $pathOnly = $returnTo;
        $cutAt = false;
        $qpos = strpos($returnTo, '?');
        $fpos = strpos($returnTo, '#');
        if ($qpos !== false && ($fpos === false || $qpos < $fpos)) $cutAt = $qpos;
        elseif ($fpos !== false) $cutAt = $fpos;
        if ($cutAt !== false) {
            $pathOnly = substr($returnTo, 0, $cutAt);
        }

        // '/' is always valid (homepage).
        if ($pathOnly === '/') {
            return $returnTo;
        }
        if (!defined('ROUTES') || !is_array(ROUTES)) {
            return null;
        }
        $segments = array_values(array_filter(
            explode('/', trim($pathOnly, '/')),
            function ($s) { return $s !== ''; }
        ));
        if (empty($segments)) {
            return $returnTo;
        }
        if (!self::routeMatches($segments, ROUTES)) {
            return null;
        }
        return $returnTo;
    }

    /**
     * Walk `$segments` against the routes tree. Literal children take
     * precedence over `:name` param children (matches the production
     * routing specificity rule in TrimParameters::resolveRoute).
     * Returns true only when ALL segments resolve (no partial matches).
     */
    private static function routeMatches(array $segments, array $routes): bool
    {
        $current = $routes;
        foreach ($segments as $segment) {
            if (is_array($current) && isset($current[$segment])) {
                $current = $current[$segment];
                continue;
            }
            if (!is_array($current)) {
                return false;
            }
            $paramKey = null;
            foreach (array_keys($current) as $key) {
                if (is_string($key) && strlen($key) > 1 && $key[0] === ':') {
                    $paramKey = $key;
                    break;
                }
            }
            if ($paramKey === null) {
                return false;
            }
            $current = $current[$paramKey];
        }
        return true;
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
        // SSRF guard: provider token/userinfo/revoke URLs
        // come from the provider preset (author/admin config). Validate the
        // back-channel URL — block non-http(s) + loopback/private/metadata,
        // pin the resolved IP. A block is surfaced as a transport failure
        // (null), matching this method's contract.
        $ssrf = OutboundUrlPolicy::check($url);
        if (!$ssrf['ok']) {
            error_log("OAuth back-channel URL blocked by SSRF policy ({$url}): {$ssrf['error']}");
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (!empty($ssrf['resolve'])) {
            curl_setopt($ch, CURLOPT_RESOLVE, $ssrf['resolve']); // pin the checked IP
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $respBody = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($respBody === false) {
            // The reason goes to the log: without it every transport failure reads as the same
            // oauth_error on the page. A certificate the server cannot verify (no CA bundle
            // configured for PHP's curl) is the common one on a local stack.
            error_log('OAuth back-channel request to ' . (string) parse_url($url, PHP_URL_HOST) . " failed: $curlError");
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
     * destination page can surface a UX message. $sitePath is a path on
     * this site that already passed sanitiseReturnTo() (or '/'); it is
     * composed against the site's base. Preserves any existing query
     * string (uses '&' instead of '?' when needed). Cookie is null — error
     * paths never establish a session.
     */
    private static function buildErrorRedirect(string $sitePath, string $code): array
    {
        $sep = (strpos($sitePath, '?') === false) ? '?' : '&';
        return [
            'redirect' => self::siteUrl($sitePath) . $sep . 'oauth_error=' . urlencode($code),
            'cookie'   => null,
        ];
    }
}
