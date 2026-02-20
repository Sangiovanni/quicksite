<?php
/**
 * Check For Updates Command
 * 
 * Compares the local VERSION file against the latest release/tag on GitHub.
 * Uses the GitHub API to fetch the latest tag and compares with version_compare().
 * 
 * @method GET
 * @route /management/checkForUpdates
 * @auth required (developer+)
 * @return ApiResponse Update availability information
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Parse a version string, stripping any leading 'v' prefix.
 */
if (!function_exists('updates_normalizeVersion')) {
    function updates_normalizeVersion(string $version): string {
        return ltrim(trim($version), 'vV');
    }
}

/**
 * Read the local VERSION file.
 * Returns null if the file does not exist or is empty.
 */
if (!function_exists('updates_getLocalVersion')) {
    function updates_getLocalVersion(): ?string {
        // VERSION file lives at the project root (two levels above secure/management/command/)
        $candidates = [
            defined('PUBLIC_FOLDER_PATH') ? dirname(PUBLIC_FOLDER_PATH) . '/VERSION' : null,
            SECURE_FOLDER_PATH . '/../VERSION',          // secure is sibling of public
            SECURE_FOLDER_PATH . '/../../VERSION',       // fallback
        ];

        foreach ($candidates as $path) {
            if ($path === null) continue;
            $realPath = realpath($path);
            if ($realPath && is_file($realPath)) {
                $content = trim(file_get_contents($realPath));
                if ($content !== '') {
                    return updates_normalizeVersion($content);
                }
            }
        }

        return null;
    }
}

/**
 * Fetch the latest tag from the GitHub repository.
 * Uses the GitHub REST API v3 (no authentication required for public repos).
 *
 * @param string $owner  Repository owner
 * @param string $repo   Repository name
 * @return array{version: string, tag: string, url: string}|null
 */
if (!function_exists('updates_fetchLatestTag')) {
    function updates_fetchLatestTag(string $owner, string $repo): ?array {
        // Try the releases/latest endpoint first (preferred — gives us release notes)
        $releaseUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $result = updates_githubGet($releaseUrl);

        if ($result !== null && isset($result['tag_name'])) {
            return [
                'version' => updates_normalizeVersion($result['tag_name']),
                'tag'     => $result['tag_name'],
                'url'     => $result['html_url'] ?? "https://github.com/{$owner}/{$repo}/releases",
                'body'    => $result['body'] ?? '',
                'published_at' => $result['published_at'] ?? null,
            ];
        }

        // Fallback: list tags sorted by semver (most recent first)
        $tagsUrl = "https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=1";
        $tags = updates_githubGet($tagsUrl);

        if ($tags !== null && is_array($tags) && count($tags) > 0) {
            $tag = $tags[0];
            return [
                'version' => updates_normalizeVersion($tag['name']),
                'tag'     => $tag['name'],
                'url'     => "https://github.com/{$owner}/{$repo}/releases/tag/" . urlencode($tag['name']),
                'body'    => '',
                'published_at' => null,
            ];
        }

        return null;
    }
}

/**
 * Make a GET request to the GitHub API.
 * Uses cURL if available, falls back to file_get_contents with stream context.
 *
 * @return array|null  Decoded JSON or null on failure
 */
if (!function_exists('updates_githubGet')) {
    function updates_githubGet(string $url): ?array {
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: QuickSite-CMS-UpdateChecker/1.0',
        ];

        // Prefer cURL
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode >= 400) {
                return null;
            }

            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : null;
        }

        // Fallback: file_get_contents with stream context
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers),
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}

// ─── GitHub repository coordinates ───────────────────────────────────
define('QUICKSITE_GITHUB_OWNER', 'Sangiovanni');
define('QUICKSITE_GITHUB_REPO',  'quicksite');

/**
 * Main command function.
 */
function __command_checkForUpdates(array $params = [], array $urlParams = []): ApiResponse {

    // 1. Read local version
    $localVersion = updates_getLocalVersion();
    if ($localVersion === null) {
        return ApiResponse::create(500, 'update_check.no_local_version')
            ->withMessage('Could not read VERSION file. Make sure a VERSION file exists at the project root.');
    }

    // 2. Detect install method
    $isGitInstall = false;
    $gitDir = realpath(SECURE_FOLDER_PATH . '/../.git')
           ?: realpath(SECURE_FOLDER_PATH . '/../../.git');
    if ($gitDir && is_dir($gitDir)) {
        $isGitInstall = true;
    }

    // 3. Fetch latest version from GitHub
    $latest = updates_fetchLatestTag(QUICKSITE_GITHUB_OWNER, QUICKSITE_GITHUB_REPO);

    if ($latest === null) {
        return ApiResponse::create(200, 'update_check.unable_to_reach')
            ->withMessage('Could not reach GitHub to check for updates. You may be offline or rate-limited.')
            ->withData([
                'current_version' => $localVersion,
                'update_available' => false,
                'checked' => false,
                'install_method' => $isGitInstall ? 'git' : 'zip',
            ]);
    }

    // 4. Compare versions
    $comparison = version_compare($latest['version'], $localVersion);
    $updateAvailable = $comparison > 0;

    $data = [
        'current_version'  => $localVersion,
        'latest_version'   => $latest['version'],
        'latest_tag'       => $latest['tag'],
        'update_available'  => $updateAvailable,
        'checked'          => true,
        'install_method'   => $isGitInstall ? 'git' : 'zip',
        'release_url'      => $latest['url'],
    ];

    if ($updateAvailable && $latest['body']) {
        $data['release_notes'] = $latest['body'];
    }
    if ($latest['published_at']) {
        $data['published_at'] = $latest['published_at'];
    }

    $message = $updateAvailable
        ? "Update available: {$latest['version']} (you have {$localVersion})"
        : "You are up to date ({$localVersion})";

    return ApiResponse::create(200, 'update_check.success')
        ->withMessage($message)
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_checkForUpdates($trimParams->params(), $trimParams->additionalParams())->send();
}
