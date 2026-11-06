<?php
/**
 * exportProject Command
 * 
 * Exports a project as a downloadable ZIP file with secure JSON-only format.
 * PHP files are NOT exported - they will be rebuilt on import from JSON structures.
 * 
 * Export format v2.0:
 * - config.json (sanitized from config.php)
 * - routes.json (from routes.php)
 * - config/*.json (project settings: route-layout, etc., and the project's
 *   name, config/project.json — an import writes its own over it)
 * - templates/model/json/ (JSON structures only)
 * - snippets/ (the project's own snippets)
 * - translate/*.json
 * - data/*.json, but the project's OAuth keys only when asked, and then the build set alone
 * - public/assets/, public/style/
 *
 * @method GET
 * @route /management/exportProject
 * @auth required (admin permission)
 *
 * @param string $name Project name (optional, defaults to active project)
 * @param bool $include_public Include public files (optional, default: true)
 * @param bool $include_oauth_secrets Carry the project's OAuth build keys, secrets included (optional, default: false)
 * @param bool $save Save to exports folder instead of streaming (optional, default: false)
 * 
 * @return ApiResponse|void Streams ZIP file or returns download URL if save=true
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_format_size
require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php'; // qs_safe_error_message
// The settings an export carries into config.json (security: no arbitrary PHP
// execution) are QS_PROJECT_SETTING_KEYS — the one list importProject takes back.
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php'; // qs_project_language_codes
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache
require_once SECURE_FOLDER_PATH . '/src/functions/projectIdentity.php'; // qs_project_name_in
require_once SECURE_FOLDER_PATH . '/src/functions/ZipUtilities.php';    // qs_temp_archive_arm / _send
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php';      // qs_delete_tree_leads_elsewhere

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body/query parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_exportProject(array $params = [], array $urlParams = []): ApiResponse {
    // Merge query parameters for GET requests
    $params = array_merge($_GET, $params);

    // CONTAINMENT (confused deputy): the exported project is BOUND to
    // the URL marker (PROJECT_NAME, authorized by the dispatcher — project.data,
    // admin+ — before this runs). A query `name`/`project` that disagrees is
    // refused; it is optional. You cannot export a project you did not
    // target/authorize.
    $bound = qs_bind_marker_project($params, 'exportProject', ['name', 'project']);
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    // Reject a traversal payload before the export source path is built.
    if (!is_valid_project_name($projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name')
            ->withErrors([['field' => 'name', 'reason' => 'invalid_format']]);
    }

    // Options
    $includePublic = filter_var($params['include_public'] ?? true, FILTER_VALIDATE_BOOLEAN);
    // The project's OAuth keys are credentials: an archive carries them only when asked, and only
    // a role that may set them may ask — the rule the build's own box follows (build.php), whatever
    // roles may export.
    $includeOAuthSecrets = filter_var($params['include_oauth_secrets'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($includeOAuthSecrets) {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $oauthCaller = getCurrentUser();
        if (!is_array($oauthCaller) || !hasPermission($oauthCaller, 'setOAuthCredentials', $projectName)) {
            return ApiResponse::create(403, 'auth.forbidden')
                ->withMessage('Only a role that may set the project\'s OAuth keys (its owner or admin) may include the OAuth keys in an export')
                ->withData(['command' => 'exportProject', 'parameter' => 'include_oauth_secrets']);
        }
    }
    // Default: stream directly. Use save=true to store in exports folder
    $save = filter_var($params['save'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Check project exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Project '$projectName' not found")
            ->withData(['searched_path' => SECURE_FOLDER_NAME . '/projects/' . $projectName]);
    }
    
    // Check ZipArchive is available
    if (!class_exists('ZipArchive')) {
        return ApiResponse::create(500, 'server.missing_extension')
            ->withMessage('ZIP extension not available')
            ->withData(['hint' => 'Install php-zip extension']);
    }
    
    // No pre-export "pull the live public/ into the project folder" step. The
    // project's own public/ IS its live dir, so the export below already carries the current
    // styles and assets. A pull from a shared live copy would also let exporting any
    // project pick up another project's assets.

    // The archive is built in the system's temporary folder, in a file no other request
    // shares (tempnam() creates it, readable by its owner only): it holds the project's data,
    // and its OAuth build keys when asked. Its name in a download is the export's name. It is
    // armed at once, so it is removed however the request ends — a stream the client cuts
    // and a fatal error while it is built included (qs_temp_archive_arm()); a saved archive
    // is moved out of the way first.
    $exportStamp = date('Ymd_His');
    $zipFileName = $projectName . '_export_' . $exportStamp . '.zip';
    $zipPath = @tempnam(sys_get_temp_dir(), 'qs-export-');
    if ($zipPath === false) {
        return ApiResponse::create(500, 'server.zip_create_failed')
            ->withMessage('Failed to create ZIP archive');
    }
    qs_temp_archive_arm($zipPath);
    @chmod($zipPath, 0600);

    // Create ZIP archive
    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($result !== true) {
        @unlink($zipPath);
        return ApiResponse::create(500, 'server.zip_create_failed')
            ->withMessage('Failed to create ZIP archive')
            ->withData(['error_code' => $result]);
    }
    
    // Add project files to ZIP (secure JSON-only format)
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0];
    
    try {
        // 1. Export config.php as config.json (sanitized)
        exportConfigAsJson($zip, $projectPath, $projectName, $stats);
        
        // 2. Export routes.php as routes.json
        exportRoutesAsJson($zip, $projectPath, $projectName, $stats);
        
        // A link is never followed, as no copier of the engine follows one: a folder this
        // export reads is left out when it, or a folder on the way to it, is a link
        // (exportPathLeadsElsewhere()), and every walker below leaves out a link inside it.
        // An archive carries the project's own files, nothing a link reaches elsewhere.

        // 3. Export config/*.json (project settings) — but NEVER members.json
        // (privacy): it holds the membership graph + owner id + private
        // invitation notes. Import discards any archived members.json and
        // birth-writes the importer as sole owner, so shipping it would be a pure
        // leak. (The members.json.lock sidecar is not .json — already skipped.)
        $configDir = $projectPath . '/config';
        if (is_dir($configDir) && !exportPathLeadsElsewhere($projectPath, 'config')) {
            addJsonFilesOnly($zip, $configDir, $projectName . '/config', $stats, ['members.json']);
        }

        // 4. Export templates/model/json/ (JSON structures only - no PHP!)
        $jsonModelPath = $projectPath . '/templates/model/json';
        if (is_dir($jsonModelPath) && !exportPathLeadsElsewhere($projectPath, 'templates/model/json')) {
            addAllFilesToZip($zip, $jsonModelPath, $projectName . '/templates/model/json', $stats);
        }

        // 5. Export snippets/ — the project's own snippets, the tier the editor's
        // Save as Snippet writes to by default. Without them an export → import
        // round trip loses them. (A personal snippet belongs to its author, not
        // to the project, and never travels.)
        $snippetsPath = $projectPath . '/snippets';
        if (is_dir($snippetsPath) && !exportPathLeadsElsewhere($projectPath, 'snippets')) {
            addJsonFilesOnly($zip, $snippetsPath, $projectName . '/snippets', $stats);
        }

        // 6. Export translate/*.json
        $translatePath = $projectPath . '/translate';
        if (is_dir($translatePath) && !exportPathLeadsElsewhere($projectPath, 'translate')) {
            addJsonFilesOnly($zip, $translatePath, $projectName . '/translate', $stats);
        }

        // 7. Export data/*.json — but never the OAuth key file as it is. Its keys travel only when
        // the caller asks (include_oauth_secrets), and then only the build set: the deployed site's
        // keys keep working wherever the project is imported, while the preview keys name this
        // installation's address of the project (/p/<projectId>/, and an import draws a new id), so
        // they never leave it. A data/ that is a link carries nothing, its keys included.
        $dataPath = $projectPath . '/data';
        if (is_dir($dataPath) && !exportPathLeadsElsewhere($projectPath, 'data')) {
            addJsonFilesOnly($zip, $dataPath, $projectName . '/data', $stats, ['oauth-secrets.json']);
            if ($includeOAuthSecrets) {
                addOAuthBuildKeysToZip($zip, $projectName, $stats);
            }
        }

        // 8. Export public/ (assets only, no PHP)
        if ($includePublic) {
            $publicPath = $projectPath . '/public';
            if (is_dir($publicPath) && !exportPathLeadsElsewhere($projectPath, 'public')) {
                addPublicAssetsToZip($zip, $publicPath, $projectName . '/public', $stats);
            }
        }
        
        // 9. Add metadata file
        $metadata = createExportMetadata($projectName, $projectPath, $stats);
        $zip->addFromString($projectName . '/export_info.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $stats['files']++;

        $zip->close();
        // The ZIP library writes the archive anew on close: it stays readable by its owner only.
        @chmod($zipPath, 0600);
    } catch (Throwable $e) {
        // `Throwable`, not `Exception`: an `Error` (TypeError from a malformed
        // routes.php, the max_execution_time timeout, any other engine-level
        // failure) is NOT an Exception. It would escape, so neither close() nor
        // unlink() would run — and the ZipArchive destructor still MATERIALISES
        // the half-built archive at request shutdown: a full copy of the project's
        // data tree left in the service temp dir, owned by the Apache service
        // account, with no product route to remove it. `Throwable` is the only
        // catch that covers both hierarchies.
        $zip->close();
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
        return ApiResponse::create(500, 'server.zip_error')
            ->withMessage('Error creating ZIP archive')
            // PHP's own messages embed absolute paths ("... called in
            // C:\wamp64\...\exportProject.php on line 419"), so the raw message
            // would publish the install layout. Development still sees it; production
            // gets a fixed string and the detail goes to the error log.
            ->withData(['error' => qs_safe_error_message($e, 'exportProject')]);
    }
    
    // Get final ZIP size
    $zipSize = filesize($zipPath);
    
    // If save=true, store in the PROJECT'S OWN exports folder for later download.
    // One installation-wide exports directory would make every archive
    // addressable from ANY authorized marker — downloadExport could stream another
    // project's archive and clearExports could delete it. Per-project storage
    // removes the shared namespace instead of filtering it, so the containment is
    // structural.
    if ($save) {
        $exportDir = qs_ensure_project_exports_dir($projectName);
        if ($exportDir === null) {
            unlink($zipPath);
            return ApiResponse::create(500, 'server.move_failed')
                ->withMessage('Failed to create the export directory');
        }

        // The storage quota: a saved archive is kept in the project, so it is charged
        // to the project's owner, less the oldest archive the five-export limit drops
        // for it. A refused archive is not kept. (A streamed export lives in the
        // system's temporary folder for the download only, and is not charged.)
        if (qs_quota_storage_limited()) {
            $quotaCallerId = (string)(getCurrentUser()['id'] ?? '');
            $freed = 0;
            foreach (exportsBeyondLimit($exportDir, $projectName, 1) as $oldExport) {
                $freed += (int) @filesize($oldExport);
            }
            $quotaBreach = qs_quota_check_storage(qs_quota_storage_owner($projectName, $quotaCallerId), (int) $zipSize,
                $quotaCallerId, ['project' => $projectName, 'kind' => 'export', 'freed' => $freed]);
            if ($quotaBreach !== null) {
                unlink($zipPath);
                return ApiResponse::create(507, 'quota.storage_exceeded')
                    ->withMessage($quotaBreach['message'])
                    ->withData($quotaBreach['data']);
            }
        }

        // A saved archive never replaces another: its name is claimed first (a second
        // archive saved in the same second takes `_2`, then `_3`…), and the archive is
        // moved over the claim.
        $zipFileName = exportClaimName($exportDir, $projectName, $exportStamp);
        if ($zipFileName === null) {
            unlink($zipPath);
            return ApiResponse::create(500, 'server.move_failed')
                ->withMessage('Failed to save export file');
        }
        $finalPath = $exportDir . '/' . $zipFileName;

        // Move ZIP to exports folder
        if (!rename($zipPath, $finalPath)) {
            // Try copy+delete if rename fails. A copy writes into the claim, so the
            // archive is made readable by its owner only again afterwards.
            if (!copy($zipPath, $finalPath)) {
                unlink($zipPath);
                @unlink($finalPath);
                return ApiResponse::create(500, 'server.move_failed')
                    ->withMessage('Failed to save export file');
            }
            @chmod($finalPath, 0600);
            unlink($zipPath);
        }
        
        // Clean up old exports (keep last 5 per project)
        cleanupOldExports($exportDir, $projectName);
        qs_invalidate_space_cache($projectName);

        return ApiResponse::create(200, 'resource.exported')
            ->withMessage("Project '$projectName' exported and saved")
            ->withData([
                'project' => $projectName,
                'filename' => $zipFileName,
                'path' => SECURE_FOLDER_NAME . '/projects/' . $projectName . '/exports/' . $zipFileName,
                'size' => qs_format_size((int) $zipSize),
                'size_bytes' => $zipSize,
                'files_count' => $stats['files'],
                'directories_count' => $stats['directories'],
                'original_size' => qs_format_size((int) $stats['total_size']),
                // downloadExport is project-scoped, so the URL carries the marker.
                'download_url' => '/management/p/' . rawurlencode($projectName) . '/downloadExport?file=' . urlencode($zipFileName),
                'format' => 'v2.0-secure',
                'note' => 'Secure format: PHP files excluded, will be rebuilt on import'
            ]);
    }
    
    // Default: stream the archive to the browser and remove it (no file saved). The send ends
    // the request; the armed removal covers a stream the client cuts.
    if (!qs_temp_archive_send($zipPath, $zipFileName)) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read the export archive');
    }
}

/**
 * Export config.php as sanitized config.json.
 *
 * The project's config.php exists: the dispatcher loads a project-scoped command's
 * project strictly, and refuses one without it before this command runs.
 *
 * The archive names the languages the project is served in — its list, or, when
 * its config.php has none, its default language (qs_project_language_codes()) —
 * because an import refuses an archive that names none.
 */
function exportConfigAsJson(ZipArchive $zip, string $projectPath, string $projectName, array &$stats): void {
    $config = require $projectPath . '/config.php';
    $config = is_array($config) ? $config : [];

    // Filter to allowed keys only (security)
    $configJson = [];
    foreach (QS_PROJECT_SETTING_KEYS as $key) {
        if (isset($config[$key])) {
            $configJson[$key] = $config[$key];
        }
    }
    $configJson['LANGUAGES_SUPPORTED'] = qs_project_language_codes($config);

    $content = json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString($projectName . '/config.json', $content);
    $stats['files']++;
    $stats['total_size'] += strlen($content);
}

/**
 * Export routes.php as routes.json
 */
function exportRoutesAsJson(ZipArchive $zip, string $projectPath, string $projectName, array &$stats): void {
    $routesFile = $projectPath . '/routes.php';
    
    // A project's routes.php is data on disk, not a trusted constant: a truncated
    // or hand-edited file can `return` a scalar (or nothing at all). Four of the
    // six sites that require it already gate on is_array(); these two did not.
    if (!file_exists($routesFile)) {
        $routes = ['home' => []];
    } else {
        $routes = require $routesFile;
        if (!is_array($routes)) {
            $routes = [];
        }
    }

    $content = json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString($projectName . '/routes.json', $content);
    $stats['files']++;
    $stats['total_size'] += strlen($content);
}

/**
 * Whether the folder $rel ('/'-separated, under $root) is a link, or is reached through one:
 * the export leaves such a folder out, as it leaves out a link inside the folders it reads.
 */
function exportPathLeadsElsewhere(string $root, string $rel): bool {
    $dir = $root;
    foreach (explode('/', $rel) as $segment) {
        $path = $dir . '/' . $segment;
        if (qs_delete_tree_leads_elsewhere($dir, $segment, $path)) {
            return true;
        }
        $dir = $path;
    }
    return false;
}

/**
 * Add only JSON files from a directory (no PHP). $excludeNames = basenames to
 * skip at THIS level (members.json is excluded from the config export). A link
 * — to a file or a folder, a Windows junction included — is left out.
 */
function addJsonFilesOnly(ZipArchive $zip, string $dir, string $zipBase, array &$stats, array $excludeNames = []): void {
    $items = scandir($dir);
    $realDir = realpath($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (in_array($item, $excludeNames, true)) {
            continue;
        }

        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;

        if (qs_delete_tree_leads_elsewhere($dir, $item, $path, $realDir)) {
            continue;
        }
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addJsonFilesOnly($zip, $path, $zipPath, $stats);
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // Only JSON files
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
        // Skip non-JSON files (especially .php)
    }
}

/**
 * Add the project's OAuth build keys to the archive as data/oauth-secrets.json: each provider's
 * build set with its secret (qs_oauth_carried_set()), and nothing of its preview set. Nothing is
 * added when the project has no build keys.
 */
function addOAuthBuildKeysToZip(ZipArchive $zip, string $projectName, array &$stats): void {
    require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';
    $carried = [];
    foreach (qs_oauth_keys_read() as $provider => $sets) {
        if (isset($sets['build'])) {
            [$set] = qs_oauth_carried_set((string) $provider, $sets['build'], true);
            $carried[(string) $provider] = ['build' => $set];
        }
    }
    if ($carried === []) {
        return;
    }
    $json = json_encode((object) $carried, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $zip->addFromString($projectName . '/data/oauth-secrets.json', $json . "\n");
    $stats['files']++;
    $stats['total_size'] += strlen($json) + 1;
}

/**
 * Add public assets to ZIP (images, CSS, JS - no PHP)
 */
function addPublicAssetsToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    // Allowed folders in public/. No 'build': builds live outside public/, and an
    // export carries only what an export is for — the project's own content.
    $allowedFolders = ['assets', 'style'];

    foreach ($allowedFolders as $folder) {
        $folderPath = $dir . '/' . $folder;
        if (is_dir($folderPath) && !qs_delete_tree_leads_elsewhere($dir, $folder, $folderPath)) {
            addSafeFilesToZip($zip, $folderPath, $zipBase . '/' . $folder, $stats);
        }
    }
}

/**
 * Add files to ZIP excluding dangerous extensions. A link is left out.
 */
function addSafeFilesToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    // Dangerous extensions that could execute code
    $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'htaccess'];

    $items = scandir($dir);
    $realDir = realpath($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;

        if (qs_delete_tree_leads_elsewhere($dir, $item, $path, $realDir)) {
            continue;
        }
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addSafeFilesToZip($zip, $path, $zipPath, $stats);
        } else {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            
            // Skip dangerous file types
            if (in_array($ext, $dangerousExtensions)) {
                continue;
            }
            
            // Skip editor/system backup files (e.g. favicon.png~)
            if (str_ends_with($item, '~')) {
                continue;
            }
            
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
    }
}

/**
 * Recursively add directory contents to ZIP (used for templates/model/json). A link is
 * left out.
 *
 * @param ZipArchive $zip ZIP archive instance
 * @param string $dir Directory to add
 * @param string $zipBase Base path in ZIP
 * @param array &$stats Stats counter
 */
function addAllFilesToZip(ZipArchive $zip, string $dir, string $zipBase, array &$stats): void {
    $items = scandir($dir);
    $realDir = realpath($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        $zipPath = $zipBase . '/' . $item;

        if (qs_delete_tree_leads_elsewhere($dir, $item, $path, $realDir)) {
            continue;
        }
        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            $stats['directories']++;
            addAllFilesToZip($zip, $path, $zipPath, $stats);
        } else {
            $zip->addFile($path, $zipPath);
            $stats['files']++;
            $stats['total_size'] += filesize($path);
        }
    }
}

/**
 * Create export metadata
 */
function createExportMetadata(string $projectName, string $projectPath, array $stats): array {
    // Load config if exists
    $config = [];
    $configFile = $projectPath . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (!is_array($config)) {
            $config = [];
        }
    }

    // Count routes recursively. countRoutesRecursive() is typed `array`, so a
    // scalar here would raise a TypeError. The guard removes that carrier; the
    // Throwable catch above covers every other one.
    $routesCount = 0;
    $routesFile = $projectPath . '/routes.php';
    if (file_exists($routesFile)) {
        $routes = require $routesFile;
        if (is_array($routes)) {
            $routesCount = countRoutesRecursive($routes);
        }
    }
    
    // Count languages
    $languages = [];
    $translateDir = $projectPath . '/translate';
    if (is_dir($translateDir)) {
        foreach (scandir($translateDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && $file !== 'default.json') {
                $languages[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
    }
    
    return [
        'export_info' => [
            'version' => '2.0',
            'format' => 'secure-json-only',
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => 'QuickSite Engine',
            'php_version' => PHP_VERSION,
            'note' => 'PHP files excluded for security. They will be rebuilt on import from JSON structures.'
        ],
        'project' => [
            'id' => $projectName,
            'name' => qs_project_name_in($projectPath) ?? $projectName,
            'routes_count' => $routesCount,
            'languages' => $languages,
            'multilingual' => $config['MULTILINGUAL_SUPPORT'] ?? false
        ],
        'statistics' => [
            'files' => $stats['files'],
            'directories' => $stats['directories'],
            'total_size_bytes' => $stats['total_size']
        ]
    ];
}

/**
 * Count routes recursively in nested structure
 */
function countRoutesRecursive(array $routes): int {
    $count = 0;
    foreach ($routes as $name => $children) {
        $count++; // Count this route
        if (is_array($children) && !empty($children)) {
            $count += countRoutesRecursive($children); // Count children
        }
    }
    return $count;
}

/**
 * Clean up old exports
 */
function cleanupOldExports(string $exportDir, string $projectName): void {
    foreach (exportsBeyondLimit($exportDir, $projectName, 0) as $file) {
        unlink($file);
    }
}

/**
 * The saved archives past the last 5 once $adding more are saved: the oldest, by
 * modification time, then by name (exportNameOrder()). The quota check asks with 1
 * before an archive is kept.
 *
 * @return string[] paths, oldest first
 */
function exportsBeyondLimit(string $exportDir, string $projectName, int $adding): array {
    $files = glob($exportDir . '/' . $projectName . '_export_*.zip') ?: [];
    $excess = count($files) + $adding - 5;
    if ($excess <= 0) {
        return [];
    }
    usort($files, static function (string $a, string $b): int {
        return [filemtime($a), ...exportNameOrder($a)] <=> [filemtime($b), ...exportNameOrder($b)];
    });
    return array_slice($files, 0, $excess);
}

/**
 * Where a saved archive's name puts it among archives of the same second: its date and
 * time, then its number (none is 1, `_2` is 2…). A modification time counts in whole
 * seconds, so archives saved in one second tie on it; their names order them, `_10`
 * after `_2`. A name of another shape comes first, by name.
 *
 * @return array{0: string, 1: int, 2: string}
 */
function exportNameOrder(string $path): array {
    $name = basename($path);
    if (preg_match('/_export_(\d{8}_\d{6})(?:_(\d+))?\.zip$/D', $name, $m) === 1) {
        return [$m[1], isset($m[2]) ? (int) $m[2] : 1, $name];
    }
    return ['', 0, $name];
}

/**
 * Claim a saved archive's name: `<id>_export_<date>_<time>.zip`, else the first of
 * `_2`, `_3`… not taken. Each is created empty with an exclusive create, so two
 * requests never claim one name; the archive is then moved over the claim. The claim is
 * readable by its owner only from the start, as the archive is: a move that copies into
 * it keeps that mode. null when no name could be created.
 */
function exportClaimName(string $exportDir, string $projectName, string $stamp): ?string {
    for ($n = 1; $n <= 100; $n++) {
        $name = $projectName . '_export_' . $stamp . ($n === 1 ? '' : '_' . $n) . '.zip';
        $handle = @fopen($exportDir . '/' . $name, 'x');
        if ($handle !== false) {
            fclose($handle);
            @chmod($exportDir . '/' . $name, 0600);
            return $name;
        }
        if (!file_exists($exportDir . '/' . $name)) {
            return null;   // not taken, yet not creatable: the folder refuses the write
        }
    }
    return null;
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_exportProject($trimParams->params(), $trimParams->additionalParams())->send();
}
