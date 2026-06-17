<?php
/**
 * oauthProviderHelpers.php — CRUD primitives for the admin OAuth
 * providers page (beta.9 A1 Slice 8).
 *
 * These helpers operate on a SPECIFIC scope ('admin' or 'project')
 * rather than the runtime "project-first / admin-fallback" lookup
 * used by `OAuthHandler::loadPreset` / `loadSecret`. Two different
 * concerns:
 *
 *   - Runtime lookup (OAuthHandler): "which preset does this provider
 *     resolve to?" — picks the winning entry across scopes.
 *   - CRUD (this file): "modify the entry at THIS scope" — needs to
 *     target one specific file even when the other scope also has an
 *     entry (e.g., overriding admin's google with a project version).
 *
 * File layout:
 *   - Admin presets:  secure/admin/config/oauth-presets.json (JSON)
 *   - Admin secrets:  secure/admin/config/oauth-secrets.php  (PHP — regenerated on write)
 *   - Project presets: secure/projects/<active>/data/oauth-presets.json (JSON)
 *   - Project secrets: secure/projects/<active>/data/oauth-secrets.json (JSON)
 *
 * Locked design 2026-06-15, DESIGN_DECISIONS.md "OAuth providers
 * admin page shape".
 */

if (!function_exists('oauthProviderPresetPath')) {

/**
 * Resolve the presets file path for a given scope. Returns null when
 * scope='project' but PROJECT_PATH is undefined.
 */
function oauthProviderPresetPath(string $scope): ?string {
    if ($scope === 'admin') {
        return SECURE_FOLDER_PATH . '/admin/config/oauth-presets.json';
    }
    if ($scope === 'project') {
        return defined('PROJECT_PATH')
            ? PROJECT_PATH . '/data/oauth-presets.json'
            : null;
    }
    return null;
}

/**
 * Resolve the secrets file path for a given scope. Admin = PHP file
 * (regenerated on write), project = JSON.
 */
function oauthProviderSecretPath(string $scope): ?string {
    if ($scope === 'admin') {
        return SECURE_FOLDER_PATH . '/admin/config/oauth-secrets.php';
    }
    if ($scope === 'project') {
        return defined('PROJECT_PATH')
            ? PROJECT_PATH . '/data/oauth-secrets.json'
            : null;
    }
    return null;
}

/**
 * Read the full presets file at a scope. Returns [] when missing or
 * malformed. Ignore-marker keys (starting with `_`) are PRESERVED in
 * the returned array — callers that want only real providers must
 * filter themselves.
 */
function oauthProviderReadPresetsFile(string $scope): array {
    $path = oauthProviderPresetPath($scope);
    if ($path === null || !file_exists($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Read the full secrets map at a scope. Admin returns the PHP array;
 * project returns the JSON object. Returns [] on missing / malformed.
 */
function oauthProviderReadSecretsFile(string $scope): array {
    $path = oauthProviderSecretPath($scope);
    if ($path === null || !file_exists($path)) {
        return [];
    }
    if ($scope === 'admin') {
        $arr = require $path;
        return is_array($arr) ? $arr : [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Write the presets map back to disk at a scope. JSON-encoded with
 * pretty print + unescaped slashes (matches what the user sees in
 * their editor). Creates the parent directory if needed.
 */
function oauthProviderWritePresetsFile(string $scope, array $presets): bool {
    $path = oauthProviderPresetPath($scope);
    if ($path === null) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $json = json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

/**
 * Write the secrets map back to disk at a scope. Project = JSON;
 * admin = PHP `<?php return [...]` file, hand-formatted because we
 * want a predictable shape (matches what `oauth-secrets.php.example`
 * documents). var_export is avoided because it uses `array(` syntax
 * and indentation that doesn't match the example.
 */
function oauthProviderWriteSecretsFile(string $scope, array $secrets): bool {
    $path = oauthProviderSecretPath($scope);
    if ($path === null) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    if ($scope === 'project') {
        $json = json_encode($secrets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false && file_put_contents($path, $json . "\n", LOCK_EX) !== false;
    }
    // admin: regenerate the PHP file with a small header.
    $content  = "<?php\n";
    $content .= "/**\n";
    $content .= " * oauth-secrets.php — OAuth client credentials.\n";
    $content .= " * Auto-managed by addOAuthProvider / editOAuthProvider /\n";
    $content .= " * deleteOAuthProvider. Hand-edit if you prefer, but the\n";
    $content .= " * next admin-UI write will replace the whole file.\n";
    $content .= " *\n";
    $content .= " * See oauth-secrets.php.example for the LOOKUP ORDER and\n";
    $content .= " * security disclosure.\n";
    $content .= " */\n";
    $content .= "\n";
    $content .= "return [\n";
    foreach ($secrets as $id => $entry) {
        if (!is_string($id) || $id === '' || !is_array($entry) || !isset($entry['client_id'])) {
            continue;
        }
        $content .= "    " . var_export($id, true) . " => [\n";
        $content .= "        'client_id' => " . var_export((string) $entry['client_id'], true) . ",\n";
        if (isset($entry['client_secret']) && $entry['client_secret'] !== null && $entry['client_secret'] !== '') {
            $content .= "        'client_secret' => " . var_export((string) $entry['client_secret'], true) . ",\n";
        }
        $content .= "    ],\n";
    }
    $content .= "];\n";
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

/**
 * Scan the active project for any usage of `$providerId`. Returns
 * a structured list of usage sites so the deleteOAuthProvider command
 * can refuse-and-explain when usage_count > 0. Two surfaces scanned:
 *
 *   - route-resolvers.json: counts entries where an oauth-* kind
 *     declares `provider: "<id>"` literally. Param placeholders are
 *     ambiguous; we don't count them (the user manages those via the
 *     route-level UI directly).
 *   - page structure JSON: counts oauth-button nodes whose `provider`
 *     attribute matches. Walks recursively; bounded by project size.
 *
 * Returns array{routes: list, buttons: list, count: int}.
 */
function oauthProviderScanUsage(string $providerId): array {
    $routes  = [];
    $buttons = [];

    if (defined('PROJECT_PATH')) {
        // Resolvers
        $sidecar = PROJECT_PATH . '/data/route-resolvers.json';
        if (file_exists($sidecar)) {
            $raw = @file_get_contents($sidecar);
            $all = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($all)) {
                foreach ($all as $routePath => $entry) {
                    $configs = (isset($entry['kind']) && is_string($entry['kind'])) ? [$entry] : $entry;
                    if (!is_array($configs)) continue;
                    foreach ($configs as $config) {
                        if (!is_array($config)) continue;
                        $kind = $config['kind'] ?? null;
                        if ($kind !== 'oauth-start' && $kind !== 'oauth-callback' && $kind !== 'oauth-logout') {
                            continue;
                        }
                        $provider = $config['provider'] ?? null;
                        if (is_string($provider) && $provider === $providerId) {
                            $routes[] = ['route' => (string) $routePath, 'kind' => $kind];
                        }
                    }
                }
            }
        }

        // Page structure JSON — recursive scan
        $pagesDir = PROJECT_PATH . '/templates/model/json/pages';
        if (is_dir($pagesDir)) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir));
            foreach ($rii as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
                $raw = @file_get_contents($file->getPathname());
                $tree = $raw !== false ? json_decode($raw, true) : null;
                if (!is_array($tree)) continue;
                $hits = [];
                _oauthProvider_walkNodes($tree, $providerId, $hits);
                if (!empty($hits)) {
                    $relPath = str_replace($pagesDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $buttons[] = [
                        'page'  => str_replace(['\\', '.json'], ['/', ''], $relPath),
                        'count' => count($hits),
                    ];
                }
            }
        }
    }

    return [
        'routes'  => $routes,
        'buttons' => $buttons,
        'count'   => count($routes) + array_sum(array_column($buttons, 'count')),
    ];
}

/**
 * Recursively walk a node tree counting oauth-button nodes that
 * reference $providerId. Helper for oauthProviderScanUsage. The
 * underscored name signals "internal — only used by the scanner".
 */
function _oauthProvider_walkNodes($nodes, string $providerId, array &$hits): void {
    if (!is_array($nodes)) return;
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;
        // oauth-button stamps an `<a>` tag with a specific class +
        // href. We match against the rendered shape rather than a
        // custom kind field — the splice writes plain DOM, no marker
        // survives in the structure JSON. The class is the most
        // stable signal; the href encodes the provider id too.
        $cls = (isset($node['params']) && is_array($node['params'])) ? ($node['params']['class'] ?? '') : '';
        if (is_string($cls) && strpos($cls, 'qs-oauth-button--' . $providerId) !== false) {
            $hits[] = true;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            _oauthProvider_walkNodes($node['children'], $providerId, $hits);
        }
    }
}

} // end if (!function_exists('oauthProviderPresetPath'))
