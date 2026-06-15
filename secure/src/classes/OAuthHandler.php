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
 *   - Callback-flow logic (Slice 2d — pending)
 *   - Logout + session-helpers (Slice 2e — pending)
 *
 * `handleCallback` throws with a "not implemented" message until 2d lands.
 */

class OAuthHandler
{
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
     * Complete the OAuth flow on callback. Validates state, exchanges
     * code for tokens, fetches userinfo, creates session, returns the
     * redirect + cookie spec.
     *
     * @param array<string, string> $query Query params from the callback URL
     *                                     (expects at minimum `code` + `state`,
     *                                     or `error` + optional `error_description`
     *                                     when the user denied consent).
     */
    public function handleCallback(array $query): array
    {
        throw new RuntimeException('OAuthHandler::handleCallback not yet implemented (Slice 2d)');
    }

    // ====================================================================
    // Loaders — preset + secret resolution. Each surfaces misconfig with
    // an explicit error so authors get a clear "what's missing" message
    // rather than a null-deref deeper in the flow.
    // ====================================================================

    /**
     * Load and return the preset for `$providerId` from
     * `secure/admin/config/oauth-presets.json`. Per the locked data-shape
     * principle, presets are JSON (user-extensible without PHP knowledge).
     */
    private static function loadPreset(string $providerId): array
    {
        $path = SECURE_FOLDER_PATH . '/admin/config/oauth-presets.json';
        if (!file_exists($path)) {
            throw new RuntimeException(
                "OAuth presets file not found: $path. Ship/restore "
                . "oauth-presets.json in secure/admin/config/."
            );
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("OAuth presets file unreadable: $path");
        }
        $all = json_decode($raw, true);
        if (!is_array($all)) {
            throw new RuntimeException(
                'OAuth presets file invalid JSON: ' . json_last_error_msg()
            );
        }
        if (!isset($all[$providerId]) || !is_array($all[$providerId])) {
            throw new RuntimeException(
                "OAuth provider preset '$providerId' not found in $path. "
                . 'Add an entry to oauth-presets.json (see existing providers '
                . 'for the schema).'
            );
        }
        return $all[$providerId];
    }

    /**
     * Load and return the secret entry for `$providerId` from
     * `secure/admin/config/oauth-secrets.php`. The secrets file is
     * gitignored; the `.example` sibling ships as the template.
     */
    private static function loadSecret(string $providerId): array
    {
        $path = SECURE_FOLDER_PATH . '/admin/config/oauth-secrets.php';
        if (!file_exists($path)) {
            throw new RuntimeException(
                "OAuth secrets file not found: $path. Copy "
                . 'oauth-secrets.php.example to oauth-secrets.php and fill '
                . 'in client_id + client_secret for each provider you use.'
            );
        }
        $all = require $path;
        if (!is_array($all)) {
            throw new RuntimeException(
                "OAuth secrets file must return an array: $path"
            );
        }
        if (!isset($all[$providerId]) || !is_array($all[$providerId])
            || !isset($all[$providerId]['client_id'])
        ) {
            throw new RuntimeException(
                "OAuth secrets for provider '$providerId' missing or "
                . "malformed. Add an entry with 'client_id' (+ "
                . "'client_secret' for confidential clients) to "
                . 'oauth-secrets.php.'
            );
        }
        return [
            'client_id' => (string) $all[$providerId]['client_id'],
            'client_secret' => isset($all[$providerId]['client_secret'])
                ? (string) $all[$providerId]['client_secret']
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
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        $scheme = $isHttps ? 'https' : 'http';
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
}
