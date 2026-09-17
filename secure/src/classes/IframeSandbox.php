<?php
require_once __DIR__ . '/../functions/jsonIo.php';        // read-only here, but kept for parity with the runtime bundle
require_once __DIR__ . '/../functions/requestRuntime.php'; // qs_request_host() — the install's own host (own-host guard)

/**
 * IframeSandbox — the embed sandbox policy, applied at render time.
 *
 * Single source of truth for the `sandbox` attribute the engine forces onto
 * every <iframe>. Called by JsonToHtmlRenderer (preview / live view) and by the
 * compiled pages a build emits (JsonToPhpCompiler writes a call to
 * getSandboxAttribute()). addNode / editNode / addComplexElement / editStructure
 * use the sanitizers below to strip an author-supplied sandbox attribute.
 *
 * ── WHERE THE POLICY LIVES ─────────────────────────────────────────────────
 * INSTALL-WIDE, set at deployment, in one file for the whole installation:
 *
 *     <secure>/management/config/embed-policy.json
 *
 * It is set only by whoever deploys QuickSite and cannot be changed from the
 * panel. That is the point: the panel and every project are served from ONE
 * origin and share one localStorage namespace, so a policy a project owner or
 * admin could edit would let them grant an iframe allow-scripts +
 * allow-same-origin pointing back at our own origin — which switches the sandbox
 * off for a frame that can then read every project's storage and act on /admin/.
 * A per-project, panel-writable policy cannot be trusted with that, so the policy
 * is install-wide. getIframeSandbox still READS it (an agent needs to know which
 * hosts embed cleanly); nothing writes it at runtime.
 *
 * A BUILT SITE carries a copy, bundled by build.php at <secure>/data/embed-policy.json,
 * and resolves it there — the install's config folder is not present in a build.
 *
 * ── FILE SHAPE ─────────────────────────────────────────────────────────────
 *   {
 *     "default": ["<token>", ...],          // unmatched hosts; [] = strictest
 *     "hosts":   [ { "name": "<host>", "parameters": ["<token>", ...] }, ... ]
 *   }
 * Only <iframe> is governed. Any other top-level key (e.g. "_comment") is
 * ignored, which is what lets the .example document its own format.
 *
 * Domain matching (CSP-style): hostname === name OR hostname ends with ".{name}".
 * "youtube.com" matches www.youtube.com but not fakeyoutube.com.
 *
 * ── GUARD RAILS (hold even against the deployer) ────────────────────────────
 *   - NEVER_ALLOWED tokens are always stripped.
 *   - allow-scripts + allow-same-origin is never emitted TOGETHER for a
 *     same-origin or relative src (that pair is what re-enables our origin).
 *   - a hosts[] entry naming this install's own host is dropped at load.
 */
class IframeSandbox
{
    /**
     * Permissions ALWAYS stripped: they let framed content redirect or escape
     * the top page regardless of what else is granted.
     */
    const NEVER_ALLOWED = [
        'allow-top-navigation',
        'allow-top-navigation-by-user-activation',
        'allow-popups-to-escape-sandbox',
    ];

    /**
     * Every valid sandbox token (used by getIframeSandbox for the read-only
     * panel view; the policy file may only name these).
     */
    const VALID_PERMISSIONS = [
        'allow-scripts',
        'allow-same-origin',
        'allow-forms',
        'allow-popups',
        'allow-modals',
        'allow-orientation-lock',
        'allow-pointer-lock',
        'allow-presentation',
        'allow-downloads',
    ];

    /**
     * Tags whose author-supplied `sandbox` attribute the write-side sanitizers
     * strip. Only <iframe> is actually policed at render time; <video>/<audio>
     * are here so a stray sandbox attribute on them is dropped too (it is inert
     * on those elements, so this is tidiness, not a control).
     */
    const VALID_EMBED_TAGS = [
        'iframe',
        'video',
        'audio',
    ];

    /** @var array|null Normalised config, cached per request. */
    private static ?array $config = null;

    /** @var string|null Resolved config path, cached per request. */
    private static ?string $configPath = null;

    /**
     * The install-wide policy file — or, inside a built site, the copy the build
     * bundled beside its other runtime data.
     *
     * Install vs build is decided STRUCTURALLY: an install carries the engine's
     * management/config directory; a build ships only the request-time runtime
     * plus its data, so that directory is absent and the policy lives in data/.
     * ⚠ This must NOT key off a request-scoped signal such as QS_SITE_BOOT: the
     * build command defines that constant on the INSTALL to read a built site's
     * qs-site.php (qs_site_verify_servable), which would then resolve an install
     * request to the build path and silently fall back to the strictest sandbox.
     * The directory on disk cannot be redefined mid-request, so it is safe.
     */
    private static function getConfigPath(): string
    {
        if (self::$configPath === null) {
            $installConfigDir = SECURE_FOLDER_PATH . '/management/config';
            if (is_dir($installConfigDir)) {
                self::$configPath = $installConfigDir . '/embed-policy.json';
            } else {
                self::$configPath = SECURE_FOLDER_PATH . '/data/embed-policy.json';
            }
        }
        return self::$configPath;
    }

    /**
     * The strictest fallback: no host rules, empty default (block everything).
     * Returned whenever the file is absent or unreadable.
     */
    private static function emptyConfig(): array
    {
        return ['default' => '', 'hosts' => []];
    }

    /**
     * Load and NORMALISE the policy. Cached per request.
     *
     * The stored shape is arrays of tokens; the internal shape this returns is
     * space-joined strings, because a sandbox attribute value is a string and
     * every consumer below wants it that way:
     *
     *     ['default' => 'tok tok', 'hosts' => [['name' => 'h', 'sandbox' => 'tok tok'], ...]]
     *
     * NEVER_ALLOWED tokens are stripped here, and a hosts[] entry whose name is
     * this install's own host is dropped here (with a log line) — both so no
     * later reader has to remember to.
     */
    public static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = self::getConfigPath();
        if (!is_file($path)) {
            self::$config = self::emptyConfig();
            return self::$config;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            self::$config = self::emptyConfig();
            return self::$config;
        }

        // default — an array of tokens; a space-separated string is also accepted.
        $default = $decoded['default'] ?? [];
        if (is_array($default)) {
            $default = implode(' ', array_filter($default, 'is_string'));
        } elseif (!is_string($default)) {
            $default = '';
        }
        $default = self::stripNeverAllowed($default);

        $ownHost = self::ownHost();

        $hosts = [];
        foreach (($decoded['hosts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name'])
                ? strtolower(trim($entry['name']))
                : '';
            if ($name === '') {
                continue;
            }
            // Own-host guard: a rule for this install's own host would grant a
            // same-origin frame whatever it lists — refuse it here, and say so.
            if ($ownHost !== null && self::matchesDomain($ownHost, $name)) {
                error_log("IframeSandbox: ignoring embed-policy host '{$name}' — it names this install's own host, which cannot be granted embed permissions (same-origin escape).");
                continue;
            }
            $params = $entry['parameters'] ?? [];
            $params = is_array($params) ? implode(' ', array_filter($params, 'is_string')) : '';
            $hosts[] = ['name' => $name, 'sandbox' => self::stripNeverAllowed($params)];
        }

        self::$config = ['default' => $default, 'hosts' => $hosts];
        return self::$config;
    }

    /**
     * This install's own host, or null when it cannot be known reliably.
     *
     * ⚠ There is no single "install host" in general: behind a proxy, or on a
     * deployment answering several hostnames, the honest answer is per request.
     * qs_request_host() is the engine's ONE host source — validated, and pinned
     * to the deployer's canonical value when QS_TRUSTED_HOSTS is set — so the
     * own-host guard is exact on a pinned deployment and best-effort (the
     * current request's host) otherwise. It is a guard rail, not a boundary; the
     * boundary is that the pair is never emitted for a same-origin src at all.
     */
    private static function ownHost(): ?string
    {
        if (!function_exists('qs_request_host')) {
            return null;
        }
        $host = strtolower(qs_request_host());
        // Strip an optional :port — policy host names never carry one.
        $colon = strrpos($host, ':');
        if ($colon !== false && strpos($host, ']') === false) {
            $host = substr($host, 0, $colon);
        }
        return $host !== '' ? $host : null;
    }

    /**
     * The sandbox attribute VALUE for a given iframe src. The main entry point
     * for the renderer and the compiled pages.
     *
     * @param string $src The authored iframe src.
     * @return string Space-separated tokens; '' means bare sandbox (block all).
     */
    public static function getSandboxValue(string $src): string
    {
        $config = self::loadConfig();
        $default = $config['default'];

        $hostname = self::extractHostname($src);

        // No host means a relative / scheme-less src (evil.xml, assets/x,
        // /p/other/page): it resolves against OUR origin. So does an absolute
        // src naming our own host. Either way it is same-origin — apply the
        // default policy but never the allow-scripts + allow-same-origin pair,
        // which for a same-origin frame is exactly script access to this origin.
        if ($hostname === null || self::isOwnHost($hostname)) {
            return self::stripSameOriginEscape($default);
        }

        foreach ($config['hosts'] as $host) {
            if (self::matchesDomain($hostname, $host['name'])) {
                return $host['sandbox'];
            }
        }

        return $default;
    }

    /**
     * The whole attribute: sandbox="..." (or bare sandbox="" to block all).
     */
    public static function getSandboxAttribute(string $src): string
    {
        $value = self::getSandboxValue($src);
        if ($value === '') {
            return 'sandbox=""';
        }
        return 'sandbox="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Extract the lowercase host from a URL, or null when the src names no host.
     *
     * ⚠ It must NOT prepend a scheme. A value with no scheme and no leading //
     * ("evil.xml", "youtube.com/embed", "/p/other/page") is a RELATIVE URL —
     * the browser resolves it against the current page's origin, so it is
     * same-origin, and reporting no host is what lets getSandboxValue treat it
     * as such. Prepending https:// and reading the first path segment as a host
     * would mistake "youtube.com/embed" for a cross-origin frame at youtube.com
     * and "evil.xml" for a frame at host "evil.xml" — a same-origin frame handed
     * a cross-origin host's policy.
     */
    public static function extractHostname(string $url): ?string
    {
        $parsed = parse_url(trim($url));
        if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') {
            return null;
        }
        return strtolower($parsed['host']);
    }

    /**
     * Is this hostname this install's own host? (belt to loadConfig's braces)
     */
    private static function isOwnHost(string $hostname): bool
    {
        $own = self::ownHost();
        return $own !== null && self::matchesDomain($hostname, $own);
    }

    /**
     * CSP-style domain match: hostname === name, or hostname ends with ".{name}".
     * "youtube.com" matches www.youtube.com and m.youtube.com, not fakeyoutube.com.
     */
    public static function matchesDomain(string $hostname, string $domain): bool
    {
        $hostname = strtolower(trim($hostname));
        $domain = strtolower(trim($domain));
        if ($hostname === '' || $domain === '') {
            return false;
        }
        if ($hostname === $domain) {
            return true;
        }
        return str_ends_with($hostname, '.' . $domain);
    }

    /**
     * Remove never-allowed tokens (and blanks) from a space-separated string.
     */
    public static function stripNeverAllowed(string $sandbox): string
    {
        if ($sandbox === '') {
            return '';
        }
        $tokens = preg_split('/\s+/', trim($sandbox));
        $filtered = array_filter($tokens, static function (string $token): bool {
            return $token !== '' && !in_array($token, self::NEVER_ALLOWED, true);
        });
        return implode(' ', $filtered);
    }

    /**
     * For a same-origin or relative src, break the allow-scripts +
     * allow-same-origin pair: that combination gives a same-origin frame script
     * access to THIS origin's DOM and storage. allow-same-origin is the token
     * dropped — a frame kept out of our origin can still run its own scripts in
     * an opaque origin, which is the least-restrictive safe answer — while
     * allow-scripts alone (no same-origin) stays, since it cannot reach us.
     */
    public static function stripSameOriginEscape(string $sandbox): string
    {
        if ($sandbox === '') {
            return '';
        }
        $tokens = preg_split('/\s+/', trim($sandbox));
        $hasScripts = in_array('allow-scripts', $tokens, true);
        $hasSameOrigin = in_array('allow-same-origin', $tokens, true);
        if ($hasScripts && $hasSameOrigin) {
            $tokens = array_filter($tokens, static fn(string $t): bool => $t !== '' && $t !== 'allow-same-origin');
        } else {
            $tokens = array_filter($tokens, static fn(string $t): bool => $t !== '');
        }
        return implode(' ', $tokens);
    }

    // ── Node param sanitisation (write side) ─────────────────────────────────

    /**
     * Strip a user-supplied `sandbox` from an embed node's params — the system
     * decides the sandbox at render time, authors cannot set it.
     *
     * @return bool True if a sandbox param was removed.
     */
    public static function sanitizeNodeParams(string $tag, array &$params): bool
    {
        if (!in_array(strtolower($tag), self::VALID_EMBED_TAGS, true)) {
            return false;
        }
        if (array_key_exists('sandbox', $params)) {
            unset($params['sandbox']);
            return true;
        }
        return false;
    }

    /**
     * Recursively strip sandbox attributes from every embed node in a structure
     * tree (editStructure hands over a whole tree).
     *
     * @return int Number of sandbox params stripped.
     */
    public static function sanitizeStructure(array &$structure): int
    {
        $count = 0;
        if (isset($structure[0]) || empty($structure)) {
            foreach ($structure as &$node) {
                if (is_array($node)) {
                    $count += self::sanitizeNode($node);
                }
            }
            unset($node);
        } else {
            $count += self::sanitizeNode($structure);
        }
        return $count;
    }

    /**
     * Sanitise one node and its children.
     */
    private static function sanitizeNode(array &$node): int
    {
        $count = 0;
        $tag = $node['tag'] ?? null;
        if ($tag && isset($node['params']) && is_array($node['params'])) {
            if (self::sanitizeNodeParams($tag, $node['params'])) {
                $count++;
            }
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as &$child) {
                if (is_array($child)) {
                    $count += self::sanitizeNode($child);
                }
            }
            unset($child);
        }
        return $count;
    }
}
