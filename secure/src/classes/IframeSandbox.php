<?php

/**
 * IframeSandbox — Centralized iframe sandbox permission manager
 *
 * Single source of truth for iframe sandbox attributes.
 * Called by JsonToHtmlRenderer, addNode, editNode, and API commands.
 *
 * Config stored per-project at: data/iframe_sandbox.json
 * Format:
 *   {
 *     "tags": {
 *       "iframe": {
 *         "youtube.com": "allow-scripts allow-same-origin",
 *         "youtu.be": "allow-scripts allow-same-origin"
 *       }
 *     },
 *     "default": ""
 *   }
 *
 * Domain matching:
 *   hostname === domain  OR  hostname ends with ".{domain}"
 *   (CSP-style — "youtube.com" matches www.youtube.com but NOT fakeyoutube.com)
 *
 * Never-allowed permissions (always stripped):
 *   - allow-top-navigation
 *   - allow-top-navigation-by-user-activation
 *   - allow-popups-to-escape-sandbox
 */
class IframeSandbox
{
    /**
     * Permissions that are ALWAYS stripped regardless of config.
     * These allow iframe content to redirect or escape the parent page.
     */
    const NEVER_ALLOWED = [
        'allow-top-navigation',
        'allow-top-navigation-by-user-activation',
        'allow-popups-to-escape-sandbox',
    ];

    /**
     * All valid sandbox permission tokens (for UI checkboxes and validation).
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
     * Tags that can embed external content and support sandbox rules.
     */
    const VALID_EMBED_TAGS = [
        'iframe',
        'video',
        'audio',
    ];

    /** @var array|null Cached config (loaded once per request) */
    private static ?array $config = null;

    /** @var string|null Cached project path */
    private static ?string $configPath = null;

    /**
     * Get the config file path for the current project.
     */
    private static function getConfigPath(): string
    {
        if (self::$configPath === null) {
            self::$configPath = PROJECT_PATH . '/data/iframe_sandbox.json';
        }
        return self::$configPath;
    }

    /**
     * Load the iframe sandbox config. Cached per request.
     */
    public static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = self::getConfigPath();
        if (!file_exists($path)) {
            self::$config = ['tags' => array_fill_keys(self::VALID_EMBED_TAGS, []), 'default' => ''];
            return self::$config;
        }

        $content = file_get_contents($path);
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            self::$config = ['tags' => array_fill_keys(self::VALID_EMBED_TAGS, []), 'default' => ''];
            return self::$config;
        }

        $tags = $decoded['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        // Preserve all valid tag rules
        $normalizedTags = [];
        foreach (self::VALID_EMBED_TAGS as $tag) {
            $rules = $tags[$tag] ?? [];
            $normalizedTags[$tag] = is_array($rules) ? $rules : [];
        }

        self::$config = [
            'tags' => $normalizedTags,
            'default' => $decoded['default'] ?? '',
        ];
        return self::$config;
    }

    /**
     * Force reload config (after a write operation).
     */
    public static function clearCache(): void
    {
        self::$config = null;
        self::$configPath = null;
    }

    /**
     * Save config to the project's data file.
     */
    public static function saveConfig(array $config): bool
    {
        $path = self::getConfigPath();

        // Ensure data dir exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sanitize before save: strip never-allowed from all tag rules and default
        $tags = $config['tags'] ?? [];
        foreach ($tags as $tag => $rules) {
            if (!is_array($rules)) continue;
            $cleanRules = [];
            foreach ($rules as $domain => $sandbox) {
                $cleanRules[$domain] = self::stripNeverAllowed(is_string($sandbox) ? $sandbox : '');
            }
            $tags[$tag] = $cleanRules;
        }
        $config['tags'] = $tags;
        $config['default'] = self::stripNeverAllowed($config['default'] ?? '');

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents($path, $json, LOCK_EX);

        // Clear cache so next read picks up the new data
        self::clearCache();

        return $result !== false;
    }

    /**
     * Get the sandbox attribute value for a given iframe src URL.
     *
     * This is the main entry point for renderers and commands.
     *
     * @param string $src The iframe src URL
     * @return string The sandbox attribute value (empty string = block everything)
     */
    public static function getSandboxValue(string $src): string
    {
        $hostname = self::extractHostname($src);
        if ($hostname === null) {
            // Can't parse URL — use strictest sandbox
            return '';
        }

        $config = self::loadConfig();

        // Check iframe rules — domain map lookup
        $iframeRules = $config['tags']['iframe'] ?? [];
        foreach ($iframeRules as $domain => $sandbox) {
            if (self::matchesDomain($hostname, $domain)) {
                return self::stripNeverAllowed(is_string($sandbox) ? $sandbox : '');
            }
        }

        // No match — return default (typically empty = block all)
        return self::stripNeverAllowed($config['default'] ?? '');
    }

    /**
     * Build the full sandbox attribute string for an HTML tag.
     * Returns the entire attribute: sandbox="..." or sandbox="" (bare).
     *
     * @param string $src The iframe src URL
     * @return string The sandbox="..." attribute to insert in HTML
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
     * Extract hostname from a URL.
     *
     * @param string $url
     * @return string|null Lowercase hostname, or null if unparseable
     */
    public static function extractHostname(string $url): ?string
    {
        // Ensure a scheme exists for parse_url to work
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return null;
        }

        return strtolower($parsed['host']);
    }

    /**
     * Check if a hostname matches a domain pattern.
     *
     * CSP-style matching:
     *   hostname === domain         → exact match
     *   hostname ends with .domain  → subdomain match
     *
     * Examples:
     *   matchesDomain("youtube.com", "youtube.com")        → true
     *   matchesDomain("www.youtube.com", "youtube.com")    → true
     *   matchesDomain("m.youtube.com", "youtube.com")      → true
     *   matchesDomain("fakeyoutube.com", "youtube.com")    → false
     *   matchesDomain("attackonyoutube.com", "youtube.com") → false
     *
     * @param string $hostname The actual hostname from the URL
     * @param string $domain The domain pattern from the rule
     * @return bool
     */
    public static function matchesDomain(string $hostname, string $domain): bool
    {
        $hostname = strtolower(trim($hostname));
        $domain = strtolower(trim($domain));

        if ($hostname === '' || $domain === '') {
            return false;
        }

        // Exact match
        if ($hostname === $domain) {
            return true;
        }

        // Subdomain match: hostname must end with ".{domain}"
        $suffix = '.' . $domain;
        return str_ends_with($hostname, $suffix);
    }

    /**
     * Remove never-allowed permissions from a sandbox string.
     *
     * @param string $sandbox Space-separated permission tokens
     * @return string Sanitized sandbox string
     */
    public static function stripNeverAllowed(string $sandbox): string
    {
        if ($sandbox === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', trim($sandbox));
        $filtered = array_filter($tokens, function (string $token) {
            return $token !== '' && !in_array($token, self::NEVER_ALLOWED, true);
        });

        return implode(' ', $filtered);
    }

    /**
     * Validate a sandbox permission string.
     * Returns array of invalid tokens, or empty array if all valid.
     *
     * @param string $sandbox Space-separated permission tokens
     * @return array Invalid tokens found
     */
    public static function validatePermissions(string $sandbox): array
    {
        if (trim($sandbox) === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', trim($sandbox));
        $invalid = [];
        $allKnown = array_merge(self::VALID_PERMISSIONS, self::NEVER_ALLOWED);

        foreach ($tokens as $token) {
            if ($token !== '' && !in_array($token, $allKnown, true)) {
                $invalid[] = $token;
            }
        }

        return $invalid;
    }

    /**
     * Check if a tag name is a valid embeddable tag.
     *
     * @param string $tag
     * @return bool
     */
    public static function isValidTag(string $tag): bool
    {
        return in_array(strtolower(trim($tag)), self::VALID_EMBED_TAGS, true);
    }

    /**
     * Validate a domain string (basic check).
     * Rejects empty, only dots, contains /, :, etc.
     *
     * @param string $domain
     * @return bool
     */
    public static function isValidDomain(string $domain): bool
    {
        $domain = trim($domain);
        if ($domain === '') {
            return false;
        }

        // Must look like a domain (letters, digits, dots, hyphens)
        // No paths, no ports, no schemes
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/', $domain);
    }

    /**
     * Validate a full config array structure.
     *
     * @param array $config
     * @return array Errors found (empty = valid)
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];

        if (!isset($config['tags']) || !is_array($config['tags'])) {
            $errors[] = 'Missing or invalid "tags" object';
            return $errors;
        }

        $iframeRules = $config['tags']['iframe'] ?? [];
        if (!is_array($iframeRules)) {
            $errors[] = '"tags.iframe" must be an object';
            return $errors;
        }

        foreach ($iframeRules as $domain => $sandbox) {
            if (!is_string($domain) || !self::isValidDomain($domain)) {
                $errors[] = "Invalid domain key: '{$domain}'";
            }
            if (!is_string($sandbox)) {
                $errors[] = "Rule '{$domain}': sandbox must be a string";
                continue;
            }
            $invalid = self::validatePermissions($sandbox);
            if (!empty($invalid)) {
                $errors[] = "Rule '{$domain}': unknown permissions: " . implode(', ', $invalid);
            }
        }

        if (isset($config['default'])) {
            $invalid = self::validatePermissions($config['default']);
            if (!empty($invalid)) {
                $errors[] = "Default: unknown permissions: " . implode(', ', $invalid);
            }
        }

        return $errors;
    }

    // ── Node Param Sanitization ──────────────────────────────

    /**
     * Strip the sandbox attribute from node params if the tag is an embed tag.
     * The system enforces sandbox at render time — users cannot set it manually.
     *
     * @param string $tag The HTML tag name
     * @param array $params The node params (modified in place by reference)
     * @return bool True if a sandbox param was stripped
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
     * Recursively strip sandbox attributes from all embed tag nodes in a structure tree.
     * Used by editStructure which receives an entire node tree.
     *
     * @param array &$structure The structure array (modified in place)
     * @return int Number of sandbox params stripped
     */
    public static function sanitizeStructure(array &$structure): int
    {
        $count = 0;

        // Handle array of nodes (page/menu/footer)
        if (isset($structure[0]) || empty($structure)) {
            foreach ($structure as &$node) {
                if (is_array($node)) {
                    $count += self::sanitizeNode($node);
                }
            }
            unset($node);
        } else {
            // Single node (component root)
            $count += self::sanitizeNode($structure);
        }

        return $count;
    }

    /**
     * Recursively sanitize a single node and its children.
     *
     * @param array &$node
     * @return int
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

        // Recurse into children
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
