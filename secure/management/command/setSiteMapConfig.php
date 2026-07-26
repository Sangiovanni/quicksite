<?php
/**
 * setSiteMapConfig Command
 *
 * Owns BOTH sitemap WRITES, split out of getSiteMap:
 *   - persists `config/sitemap-config.json` (excludedRoutes + customUrls)
 *   - optionally publishes `public/sitemap.txt` from the current routes
 *
 * WHY IT IS ITS OWN COMMAND: getSiteMap lives in `content.read`, which the VIEWER
 * tier holds. Those two writes decide what the PUBLISHED sitemap contains —
 * excludedRoutes remove real pages from it, customUrls add arbitrary absolute URLs
 * to it — so they are an authoring capability, not a read. They now sit in
 * `route.write` (editor and above), matching the other commands that shape which
 * routes the world sees. getSiteMap is a pure read again.
 *
 * @method POST
 * @route /management/p/<projectId>/setSiteMapConfig
 * @auth required (route.write — editor, designer, developer, admin, owner)
 *
 * @param array  $excludedRoutes Route names to keep OUT of the sitemap (optional)
 * @param array  $customUrls     Extra absolute URLs to append (optional; non-URLs dropped)
 * @param bool   $save           Also write public/sitemap.txt (optional)
 * @param string $baseUrl        Base for the generated URLs when saving (optional)
 *
 * @return ApiResponse Saved config, plus publish details when $save is true
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/sitemapHelpers.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_setSiteMapConfig(array $params = [], array $urlParams = []): ApiResponse {
    // Marker-only targeting, like every other project-scoped writer.
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/setSiteMapConfig');
    }

    $hasExcluded = array_key_exists('excludedRoutes', $params);
    $hasCustom   = array_key_exists('customUrls', $params);
    if (!$hasExcluded && !$hasCustom) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Provide excludedRoutes and/or customUrls')
            ->withErrors([
                'excludedRoutes' => 'Optional array of route names',
                'customUrls'     => 'Optional array of absolute URLs',
            ]);
    }
    if ($hasExcluded && !is_array($params['excludedRoutes'])) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('excludedRoutes must be an array');
    }
    if ($hasCustom && !is_array($params['customUrls'])) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('customUrls must be an array');
    }

    // Merge onto what is stored, so a caller sending only one field does not silently
    // wipe the other.
    $current = qs_sitemap_config_load(PROJECT_PATH);
    $incoming = [
        'excludedRoutes' => $hasExcluded ? $params['excludedRoutes'] : ($current['excludedRoutes'] ?? []),
        'customUrls'     => $hasCustom   ? $params['customUrls']     : ($current['customUrls'] ?? []),
    ];
    // Report what was dropped rather than silently discarding it — a customUrl that is
    // not a valid absolute URL never reaches the published file.
    $normalised = qs_sitemap_config_normalise($incoming);
    $rejected = [];
    foreach (($incoming['customUrls'] ?? []) as $candidate) {
        if (!is_string($candidate) || !filter_var(trim($candidate), FILTER_VALIDATE_URL)) {
            $rejected[] = is_scalar($candidate) ? (string)$candidate : gettype($candidate);
        }
    }

    if (!qs_sitemap_config_save(PROJECT_PATH, $normalised)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to persist the sitemap configuration');
    }

    $data = [
        'project'        => PROJECT_NAME,
        'excludedRoutes' => $normalised['excludedRoutes'],
        'customUrls'     => $normalised['customUrls'],
        'saved'          => true,
    ];
    if ($rejected !== []) {
        $data['rejectedUrls'] = $rejected;
    }

    // ---- optional publish -----------------------------------------------------
    if (!empty($params['save'])) {
        // Reuse getSiteMap for generation — one place builds URLs. It is loaded as an
        // INTERNAL call so its own HTTP self-dispatch stays inert; this command's
        // dispatch already ran, so defining the flag here cannot affect it.
        if (!function_exists('__command_getSiteMap')) {
            if (!defined('COMMAND_INTERNAL_CALL')) {
                define('COMMAND_INTERNAL_CALL', true);
            }
            require_once SECURE_FOLDER_PATH . '/management/command/getSiteMap.php';
        }
        $genParams = [];
        if (!empty($params['baseUrl'])) {
            $genParams['baseUrl'] = $params['baseUrl'];
        }
        $generated = __command_getSiteMap($genParams, ['json']);
        if ($generated->getStatus() < 200 || $generated->getStatus() >= 300) {
            return ApiResponse::create(500, 'operation.write_failed')
                ->withMessage('Saved the configuration, but could not generate the sitemap to publish');
        }

        $sitemapData = qs_sitemap_apply_config((array)$generated->getData(), $normalised);
        $content = implode("\n", $sitemapData['urls'] ?? []) . "\n";

        $publicDir = PROJECT_PATH . '/public';
        if (!is_dir($publicDir) && !mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
            return ApiResponse::create(500, 'operation.write_failed')
                ->withMessage('Saved the configuration, but could not create the project public folder');
        }
        $sitemapPath = $publicDir . '/sitemap.txt';
        if (file_put_contents($sitemapPath, $content) === false) {
            return ApiResponse::create(500, 'operation.write_failed')
                ->withMessage('Saved the configuration, but failed to write sitemap.txt');
        }

        $data['published'] = true;
        $data['path']      = $sitemapPath;
        $data['urlCount']  = count($sitemapData['urls'] ?? []);

        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Sitemap configuration saved and sitemap.txt published')
            ->withData($data);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Sitemap configuration saved')
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setSiteMapConfig($trimParams->params(), $trimParams->additionalParams())->send();
}
