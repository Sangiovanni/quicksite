<?php
/**
 * Sitemap configuration helpers — shared by getSiteMap (read) and
 * setSiteMapConfig (write).
 *
 * The config is a per-project sidecar, `config/sitemap-config.json`:
 *   excludedRoutes  route names kept OUT of the generated sitemap
 *   customUrls      extra absolute URLs appended to it
 *
 * Both fields change what the PUBLISHED sitemap contains, which is why writing
 * them lives in its own command in a write category — reading a sitemap and
 * deciding what the world sees in it are different capabilities.
 */

/** Absolute path of a project's sitemap config sidecar. */
function qs_sitemap_config_path(string $projectPath): string {
    return $projectPath . '/config/sitemap-config.json';
}

/** Read the sidecar; always returns the full shape, even when absent. */
function qs_sitemap_config_load(string $projectPath): array {
    $default = ['excludedRoutes' => [], 'customUrls' => []];
    $path = qs_sitemap_config_path($projectPath);
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

/**
 * Normalise a caller-supplied config to exactly the two known keys, dropping
 * anything else. customUrls keeps only values that are real absolute URLs, so a
 * malformed entry can never reach the published sitemap.
 */
function qs_sitemap_config_normalise(array $config): array {
    $excluded = [];
    foreach (($config['excludedRoutes'] ?? []) as $route) {
        if (is_string($route) && trim($route) !== '') {
            $excluded[] = trim($route);
        }
    }
    $urls = [];
    foreach (($config['customUrls'] ?? []) as $url) {
        if (is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL)) {
            $urls[] = trim($url);
        }
    }
    return [
        'excludedRoutes' => array_values(array_unique($excluded)),
        'customUrls'     => array_values(array_unique($urls)),
    ];
}

/** Write the sidecar (creating config/ if needed). Returns false on failure. */
function qs_sitemap_config_save(string $projectPath, array $config): bool {
    $configDir = $projectPath . '/config';
    if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
        return false;
    }
    $payload = qs_sitemap_config_normalise($config);
    return file_put_contents(
        qs_sitemap_config_path($projectPath),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

/**
 * Apply the config to generated sitemap data: drop excluded routes (and their
 * URLs), then append the custom URLs. Shared so the preview a reader sees and the
 * file a writer publishes can never diverge.
 */
function qs_sitemap_apply_config(array $sitemapData, array $config): array {
    $excluded = $config['excludedRoutes'] ?? [];
    if (!empty($excluded)) {
        $filteredRoutes = [];
        $filteredUrls   = [];
        foreach (($sitemapData['routes'] ?? []) as $routeData) {
            if (in_array($routeData['name'] ?? null, $excluded, true)) {
                continue;
            }
            $filteredRoutes[] = $routeData;
            foreach (($routeData['urls'] ?? []) as $url) {
                $filteredUrls[] = $url;
            }
        }
        $sitemapData['routes'] = $filteredRoutes;
        $sitemapData['urls']   = $filteredUrls;
    }
    foreach (($config['customUrls'] ?? []) as $customUrl) {
        if (filter_var($customUrl, FILTER_VALIDATE_URL)) {
            $sitemapData['urls'][] = $customUrl;
        }
    }
    $sitemapData['totalUrls'] = count($sitemapData['urls'] ?? []);
    return $sitemapData;
}
