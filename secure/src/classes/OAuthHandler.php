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
 * Out of scope for this slice (2a — scaffolding):
 *
 *   - Resolver-kind registration in DataResolver / resolverHelpers (Slice 2b)
 *   - Actual start-flow logic (Slice 2c)
 *   - Actual callback-flow logic (Slice 2d)
 *   - Logout + session-helpers (Slice 2e)
 *
 * The methods below throw with a "not implemented" message until the
 * corresponding flow slice lands.
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
     * Slice 2c will implement: generate state + PKCE, store via
     * oauthStateStore, build authorize URL with `client_id`,
     * `redirect_uri`, `scope`, `state`, `code_challenge`,
     * `code_challenge_method=S256`, + any preset `extra_authorize_params`.
     */
    public function handleStart(?string $returnTo = null): array
    {
        throw new RuntimeException('OAuthHandler::handleStart not yet implemented (Slice 2c)');
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
}
