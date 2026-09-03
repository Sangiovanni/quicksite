<?php
/**
 * Update discovery — is a newer QuickSite released than the one installed?
 *
 * NOT A COMMAND. It reports on THE INSTALLATION, not on any project, so under
 * the beta.11 rule (the command surface is a CLI for developing a project) it
 * has no place in `secure/management/command/`. It was `checkForUpdates` until
 * that rule was applied; the panel reaches it as the `update-check` arm of
 * `public/admin/api/index.php`.
 *
 * DISCOVERY, NOT ACTION. Nothing here applies anything. Applying an update
 * rewrites the code every project on the installation runs on, and authority in
 * QuickSite is per-project — so there is no principal to gate that on and no
 * HTTP surface for it at all. Updating is `git pull` on the server.
 *
 * WHO SEES the resulting notice is decided in layout.php from
 * secure/management/config/operator.php. That is a DISPLAY preference and
 * grants nothing: this endpoint answers any authenticated caller, exactly as
 * `checkForUpdates` always did. Gating it on the operator list would turn a
 * display preference into an authorization tier, which is the one thing that
 * file's design forbids.
 */

if (!defined('QS_UPDATE_GITHUB_OWNER')) {
    define('QS_UPDATE_GITHUB_OWNER', 'Sangiovanni');
}
if (!defined('QS_UPDATE_GITHUB_REPO')) {
    define('QS_UPDATE_GITHUB_REPO', 'quicksite');
}

if (!function_exists('qs_updates_normalize_version')) {
    /**
     * A version string as version_compare wants it — no leading 'v'.
     */
    function qs_updates_normalize_version(string $version): string {
        return ltrim(trim($version), 'vV');
    }
}

if (!function_exists('qs_updates_github_get')) {
    /**
     * GET a GitHub REST API v3 URL. cURL when it is available, a stream context
     * otherwise. The URL is composed from compile-time constants by the only
     * caller below — no part of it comes from the request.
     *
     * @return array|null Decoded JSON, or null on any failure (offline, rate
     *                    limit, non-2xx, unparseable body).
     */
    function qs_updates_github_get(string $url): ?array {
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: QuickSite-CMS-UpdateChecker/1.0',
        ];

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

if (!function_exists('qs_updates_latest_tag')) {
    /**
     * The latest release of the repository, falling back to its newest tag.
     *
     * @return array{version:string,tag:string,url:string,body:string,published_at:?string}|null
     */
    function qs_updates_latest_tag(string $owner, string $repo): ?array {
        // releases/latest first — it carries the release notes.
        $result = qs_updates_github_get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest");

        if ($result !== null && isset($result['tag_name'])) {
            return [
                'version'      => qs_updates_normalize_version($result['tag_name']),
                'tag'          => $result['tag_name'],
                'url'          => $result['html_url'] ?? "https://github.com/{$owner}/{$repo}/releases",
                'body'         => $result['body'] ?? '',
                'published_at' => $result['published_at'] ?? null,
            ];
        }

        // Fallback: the newest tag, for a repository that publishes tags without
        // creating releases.
        $tags = qs_updates_github_get("https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=1");

        if ($tags !== null && is_array($tags) && count($tags) > 0) {
            $tag = $tags[0];
            return [
                'version'      => qs_updates_normalize_version($tag['name']),
                'tag'          => $tag['name'],
                'url'          => "https://github.com/{$owner}/{$repo}/releases/tag/" . urlencode($tag['name']),
                'body'         => '',
                'published_at' => null,
            ];
        }

        return null;
    }
}

if (!function_exists('qs_update_check')) {
    /**
     * Compare the installed VERSION against the latest published release.
     *
     * ⚠ The version read is qs_local_version() — THE shared reader in
     * utilsManagement.php — and no longer a private copy. The command file this
     * code came from deliberately kept its own updates_getLocalVersion() because
     * sharing would have dragged utilsManagement's ~1000 lines (and
     * JsonToHtmlRenderer, PageManagement, TagRegistry, Translator and
     * TrimParameters behind it) into a command that otherwise loaded one class.
     * That trade does not exist here: the admin JSON endpoint already requires
     * utilsManagement on every request, so the dependency is paid and a second
     * reader of the same file is pure duplication. The two only ever differed by
     * the leading-v strip below, which belongs at the point of comparison anyway
     * — the build stamp must NOT have it.
     *
     * @return array{status:int,success:bool,error:?string,data:array}
     */
    function qs_update_check(): array {
        require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

        $raw = qs_local_version();
        if ($raw === null) {
            return [
                'status'  => 500,
                'success' => false,
                'error'   => 'Could not read VERSION file. Make sure a VERSION file exists at the installation root.',
                'data'    => [],
            ];
        }
        $localVersion = qs_updates_normalize_version($raw);

        // git install or unpacked archive — the notice words its instruction
        // differently for each, and only the operator ever sees it.
        $gitDir = realpath(SECURE_FOLDER_PATH . '/../.git')
               ?: realpath(SECURE_FOLDER_PATH . '/../../.git');
        $isGitInstall = $gitDir && is_dir($gitDir);

        $latest = qs_updates_latest_tag(QS_UPDATE_GITHUB_OWNER, QS_UPDATE_GITHUB_REPO);

        // Unreachable is NOT an error: an install with no outbound access is a
        // normal install. Answer success with checked=false so the caller stays
        // silent rather than showing a failure nobody can act on.
        if ($latest === null) {
            return [
                'status'  => 200,
                'success' => true,
                'error'   => null,
                'data'    => [
                    'current_version'  => $localVersion,
                    'update_available' => false,
                    'checked'          => false,
                    'install_method'   => $isGitInstall ? 'git' : 'zip',
                ],
            ];
        }

        // version_compare natively orders pre-release tags:
        // 1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0.
        $updateAvailable = version_compare($latest['version'], $localVersion) > 0;

        $data = [
            'current_version'  => $localVersion,
            'latest_version'   => $latest['version'],
            'latest_tag'       => $latest['tag'],
            'update_available' => $updateAvailable,
            'checked'          => true,
            'install_method'   => $isGitInstall ? 'git' : 'zip',
            'release_url'      => $latest['url'],
        ];
        if ($updateAvailable && $latest['body'] !== '') {
            $data['release_notes'] = $latest['body'];
        }
        if ($latest['published_at'] !== null) {
            $data['published_at'] = $latest['published_at'];
        }

        return ['status' => 200, 'success' => true, 'error' => null, 'data' => $data];
    }
}
