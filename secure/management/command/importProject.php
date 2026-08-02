<?php
/**
 * importProject Command (Secure Version)
 * 
 * Imports a project from an uploaded ZIP file with secure rebuild approach.
 * PHP files in the ZIP are IGNORED - all PHP is rebuilt from JSON structures.
 * 
 * Security measures:
 * - PHP files in ZIP are skipped (logged as warnings)
 * - config.php rebuilt from validated config.json
 * - routes.php rebuilt from validated routes.json
 * - Page PHP wrappers rebuilt from JSON using JsonToHtmlRenderer (dev mode)
 * 
 * @method POST
 * @route /management/importProject
 * @auth required (admin permission)
 * 
 * @param file $file Uploaded ZIP file (required via multipart/form-data)
 * @param string $name New project name (optional, uses ZIP folder name if not provided)
 * @param bool $overwrite Overwrite if project exists (optional, default: false)
 * @param bool $switch_to Switch to imported project (optional, default: false)
 * 
 * @return ApiResponse Import result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/filePolicy.php';

// Allowed keys in config.json import (security: whitelist only)
const IMPORT_ALLOWED_CONFIG_KEYS = [
    'SITE_NAME',
    'LANGUAGES_SUPPORTED',
    'LANGUAGE_DEFAULT',
    'LANGUAGES_NAME',
    'MULTILINGUAL_SUPPORT',
    'TITLE',
    'FAVICON'
];

// The extension gate is an ALLOWLIST in filePolicy.php, not a blocklist here.
// A blocklist had to enumerate every dangerous spelling, and missed three: it
// matched '.htaccess' case-sensitively (so '.HTACCESS' passed, and both name
// the same file on a case-insensitive filesystem), and it had never heard of
// '.phtm' or 'web.config'. It also validated no content at all, so an entry
// named '.png' could hold anything.

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_importProject(array $params = [], array $urlParams = []): ApiResponse {
    // Merge query parameters for POST with multipart (query params in URL)
    $params = array_merge($_GET, $_POST, $params);
    
    // Check ZipArchive is available
    if (!class_exists('ZipArchive')) {
        return ApiResponse::create(500, 'server.missing_extension')
            ->withMessage('ZIP extension not available')
            ->withData(['hint' => 'Install php-zip extension']);
    }
    
    // Check for uploaded file
    $uploadedFile = null;
    $zipPath = null;
    
    // Method 1: Check $_FILES for uploaded file
    if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = getUploadErrorMessage($file['error']);
            return ApiResponse::create(400, 'upload.failed')
                ->withMessage("File upload failed: $uploadError")
                ->withData(['error_code' => $file['error'], 'error' => $uploadError]);
        }
        
        // Validate file type
        $mimeType = mime_content_type($file['tmp_name']);
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        
        if (!in_array($mimeType, $allowedMimes) && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('File must be a ZIP archive')
                ->withData(['received_type' => $mimeType]);
        }
        
        $zipPath = $file['tmp_name'];
        $uploadedFile = $file['name'];
    }
    // Method 2: Check for file path in params (for internal calls).
    // qs_param_string first: `?file_path[]=x` is non-empty but is an array, and
    // file_exists() is typed for a string — TypeError (beta.10 C13 F-C13-11).
    elseif (($filePathParam = qs_param_string($params, 'file_path', '')) !== ''
            && file_exists($filePathParam)) {
        $zipPath = $filePathParam;
        $uploadedFile = basename($filePathParam);
    }
    else {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('No file uploaded')
            ->withErrors(['file' => 'Required. Upload a ZIP file or provide file_path for internal calls']);
    }
    
    // Options
    $newName = trim($params['name'] ?? '');
    $overwrite = filter_var($params['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Open and validate ZIP
    $zip = new ZipArchive();
    $result = $zip->open($zipPath);
    
    if ($result !== true) {
        return ApiResponse::create(400, 'validation.invalid_zip')
            ->withMessage('Invalid or corrupted ZIP file')
            ->withData(['error_code' => $result]);
    }

    // SECURITY (C11 11.0) — resource limits, enforced from the archive's own
    // central directory BEFORE a single byte is extracted. getFromIndex()
    // reads an entry fully into memory, so without a cap an uploaded archive
    // is an unbounded allocation and an unbounded number of files on disk.
    $limitBreach = checkArchiveLimits($zip);
    if ($limitBreach !== null) {
        $zip->close();
        return ApiResponse::create(413, 'validation.size_limit_exceeded')
            ->withMessage($limitBreach['message'])
            ->withData($limitBreach['data']);
    }

    // Find project folder in ZIP
    $projectFolder = findProjectFolderInZip($zip);
    
    if ($projectFolder === null) {
        $zip->close();
        return ApiResponse::create(400, 'validation.invalid_structure')
            ->withMessage('Invalid project structure in ZIP')
            ->withErrors(['structure' => 'ZIP must contain a project folder with config.json, routes.json, or templates/model/json/']);
    }
    
    // Determine project name
    $projectName = !empty($newName) ? $newName : $projectFolder['name'];
    
    // Validate project name
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', $projectName)) {
        $zip->close();
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['name' => 'Must start with letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }
    
    // Reserved names
    $reserved = ['admin', 'management', 'src', 'logs', 'config', 'projects'];
    if (in_array(strtolower($projectName), $reserved)) {
        $zip->close();
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Project name '$projectName' is reserved");
    }
    
    // Check if project already exists
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (is_dir($projectPath)) {
        if (!$overwrite) {
            $zip->close();
            return ApiResponse::create(409, 'resource.already_exists')
                ->withMessage("Project '$projectName' already exists")
                ->withData([
                    'existing_path' => 'secure/projects/' . $projectName,
                    'hint' => 'Set overwrite=true to replace existing project'
                ]);
        }
        
        // Delete existing project
        deleteImportDirectory($projectPath);
    }
    
    // Create project directory structure
    if (!mkdir($projectPath, 0755, true)) {
        $zip->close();
        return ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage('Failed to create project directory');
    }
    
    // Create required subdirectories
    $requiredDirs = [
        '/templates',
        '/templates/pages',
        '/templates/components',
        '/templates/model',
        '/templates/model/json',
        '/templates/model/json/pages',
        '/templates/model/json/components',
        '/config',
        '/translate',
        '/data',
        '/public',
        '/public/assets',
        '/public/style',
        '/public/build'
    ];
    foreach ($requiredDirs as $dir) {
        @mkdir($projectPath . $dir, 0755, true);
    }
    
    // Extract files (allowlisted extensions + content validation; refusals tracked)
    // No 'skipped_php' bucket: the allowlist refuses every executable spelling
    // before a blocklist would have seen it, so a separate PHP counter could
    // only ever report 0 while files were in fact being refused — a misleading
    // signal on a security-relevant response. 'skipped_disallowed' replaces it
    // and names the reason for each refusal.
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0,
              'skipped_unsafe' => [], 'skipped_disallowed' => []];
    $extractResult = extractProjectFromZipSecure($zip, $projectFolder['prefix'], $projectPath, $stats);
    
    $zip->close();
    
    if (!$extractResult['success']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.extract_failed')
            ->withMessage('Failed to extract project files')
            ->withData(['error' => $extractResult['error']]);
    }
    
    // Rebuild PHP files from JSON (secure approach)
    $rebuildResult = rebuildPhpFromJson($projectPath);
    
    if (!$rebuildResult['success']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.rebuild_failed')
            ->withMessage('Failed to rebuild PHP files from JSON')
            ->withData(['error' => $rebuildResult['error']]);
    }
    
    // Validate imported project has required files
    $validation = validateImportedProject($projectPath);
    
    if (!$validation['valid']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(400, 'validation.incomplete_project')
            ->withMessage('Imported project is incomplete')
            ->withErrors($validation['errors']);
    }
    
    // C8 8.4 BIRTH-WRITE: an imported archive's config/members.json is UNTRUSTED
    // input — it could name any owner and any roster (a membership-hijack plant),
    // or be absent entirely (an ownerless, inaccessible project). Discard whatever
    // the ZIP carried (log it for audit) and mint a fresh trust file: the IMPORTER
    // is the sole owner. (Export now excludes members.json, but old archives and
    // hand-built ZIPs may still contain one — never trust it.)
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    $importerId = getCurrentUser()['id'] ?? null;
    $discarded = @json_decode((string)@file_get_contents($projectPath . '/config/members.json'), true);
    if (is_array($discarded)) {
        $dOwner   = $discarded['owner'] ?? '(none)';
        $dMembers = is_array($discarded['members'] ?? null) ? count($discarded['members']) : 0;
        $dInv     = is_array($discarded['invitations'] ?? null) ? count($discarded['invitations']) : 0;
        error_log("importProject: discarded archive members.json for '{$projectName}' (owner='{$dOwner}', members={$dMembers}, invitations={$dInv}) — importer '{$importerId}' set as sole owner (C8 8.4 containment)");
    }
    if (!qs_project_birth_write_members($projectPath, $importerId)) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to initialise imported project membership');
    }
    @unlink($projectPath . '/config/members.json.lock');

    // Load project info
    $projectInfo = getImportedProjectInfo($projectPath);

    $result = [
        'project' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'imported' => true,
        'source_file' => $uploadedFile,
        'files_count' => $stats['files'],
        'directories_count' => $stats['directories'],
        'total_size' => formatImportBytes($stats['total_size']),
        'site_name' => $projectInfo['site_name'],
        'routes_count' => $projectInfo['routes_count'],
        'languages' => $projectInfo['languages'],
        'switched_to' => false,
        'security' => [
            'format' => 'v2.0-secure',
            'php_rebuilt_from_json' => true,
            // Entries refused by the zip-slip containment guard (path escape attempts).
            'skipped_unsafe_paths' => count($stats['skipped_unsafe']),
            'skipped_unsafe' => $stats['skipped_unsafe'],
            // Entries refused by the extension allowlist or by content validation
            // (C11 11.0). Reported rather than fatal: one stray file must not
            // block an otherwise legitimate import, but it must never be silent.
            'skipped_disallowed_files' => count($stats['skipped_disallowed']),
            'skipped_disallowed' => $stats['skipped_disallowed'],
            'membership' => 'archive members.json discarded; importer set as sole owner'
        ],
        'rebuild_stats' => $rebuildResult['stats'] ?? []
    ];
    
    // Register the import in the importer's project index + move ONLY their
    // per-user editing target with switch_to (C9 fixed-main — a command NEVER
    // repoints what a deployment serves; the old tail here did, the same
    // pre-C9 leftover createProject dropped in 8.0). Edited at /p/<id>/.
    if ($importerId !== null) {
        $siteName = $projectInfo['site_name'] ?? $projectName;
        $written = qs_users_mutate(function (array &$cfg) use ($importerId, $projectName, $siteName, $switchTo) {
            if (!isset($cfg['users'][$importerId])) {
                return false;
            }
            $cfg['users'][$importerId]['projects'][$projectName] = [
                'name'    => $siteName,
                'created' => date('Y-m-d'),
            ];
            if ($switchTo) {
                $cfg['users'][$importerId]['selected_project'] = $projectName;
            }
            return true;
        });
        $result['switched_to'] = ($written === true && $switchTo);
    }
    $result['owner_user_id'] = $importerId;

    return ApiResponse::create(201, 'resource.imported')
        ->withMessage("Project '$projectName' imported successfully (secure rebuild)")
        ->withData($result);
}

/**
 * Enforce archive resource limits from the ZIP's central directory.
 *
 * Reads only the per-entry headers (statIndex), so a bomb is refused without
 * decompressing anything. Limits and their defaults live in filePolicy.php.
 *
 * @return array{message:string, data:array}|null Null when the archive is within limits
 */
function checkArchiveLimits(ZipArchive $zip): ?array {
    $limits = qs_archive_limits();

    if ($zip->numFiles > $limits['max_entries']) {
        return ['message' => 'Archive contains too many entries', 'data' => [
            'entries' => $zip->numFiles,
            'max_entries' => $limits['max_entries'],
        ]];
    }

    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $size = (int)($stat['size'] ?? 0);
        $comp = (int)($stat['comp_size'] ?? 0);

        if ($size > $limits['max_entry_bytes']) {
            return ['message' => 'Archive contains an entry that is too large', 'data' => [
                'entry' => (string)($stat['name'] ?? ''),
                'uncompressed_bytes' => $size,
                'max_entry_bytes' => $limits['max_entry_bytes'],
            ]];
        }

        // A high uncompressed:compressed ratio is the signature of a
        // decompression bomb. Tiny entries are exempt: a few hundred bytes
        // expanding from a dozen is ordinary compression, not an attack.
        if ($comp > 0 && $size > 1024 && intdiv($size, $comp) > $limits['max_ratio']) {
            return ['message' => 'Archive contains a suspiciously compressed entry', 'data' => [
                'entry' => (string)($stat['name'] ?? ''),
                'ratio' => intdiv($size, $comp) . ':1',
                'max_ratio' => $limits['max_ratio'] . ':1',
            ]];
        }

        $total += $size;
        if ($total > $limits['max_total_bytes']) {
            return ['message' => 'Archive total uncompressed size is too large', 'data' => [
                'uncompressed_bytes_so_far' => $total,
                'max_total_bytes' => $limits['max_total_bytes'],
            ]];
        }
    }

    return null;
}

/**
 * Find project folder in ZIP archive (v2.0 format)
 */
function findProjectFolderInZip(ZipArchive $zip): ?array {
    // Look for v2.0 format indicators: config.json, routes.json, or templates/model/json/
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = explode('/', $name);
        
        $filename = basename($name);
        
        // Check for v2.0 JSON format
        if ($filename === 'config.json' || $filename === 'routes.json') {
            if (count($parts) === 1) {
                return ['name' => 'imported_project', 'prefix' => '', 'format' => 'v2.0'];
            } elseif (count($parts) === 2) {
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/', 'format' => 'v2.0'];
            }
        }
        
        // Check for templates/model/json/ structure
        if (strpos($name, 'templates/model/json/') !== false) {
            // Extract project folder name from path
            $jsonPos = strpos($name, '/templates/model/json/');
            if ($jsonPos > 0) {
                $projectFolder = substr($name, 0, $jsonPos);
                $firstSlash = strpos($projectFolder, '/');
                if ($firstSlash === false) {
                    return ['name' => $projectFolder, 'prefix' => $projectFolder . '/', 'format' => 'v2.0'];
                }
            }
        }
        
        // Legacy: check for config.php or routes.php (v1.0 format - still supported)
        if ($filename === 'config.php' || $filename === 'routes.php') {
            if (count($parts) === 1) {
                return ['name' => 'imported_project', 'prefix' => '', 'format' => 'v1.0'];
            } elseif (count($parts) === 2) {
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/', 'format' => 'v1.0'];
            }
        }
    }
    
    return null;
}

/**
 * Extract project from ZIP (secure: skip PHP files)
 */
function extractProjectFromZipSecure(ZipArchive $zip, string $prefix, string $destPath, array &$stats): array {
    $prefixLen = strlen($prefix);
    
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        
        // Skip if not in our prefix
        if ($prefix !== '' && strpos($name, $prefix) !== 0) {
            continue;
        }
        
        // Get relative path within project
        $relativePath = substr($name, $prefixLen);
        
        // Skip empty or root
        if (empty($relativePath) || $relativePath === '/') {
            continue;
        }
        
        // Skip export_info.json (metadata file)
        if ($relativePath === 'export_info.json') {
            continue;
        }

        // SECURITY (C8 8.4) — ZIP-SLIP containment. The entry name is fully
        // attacker-controlled: an entry like 'proj/../../../evil.json' would resolve
        // OUTSIDE the new project directory and let an archive plant or overwrite a
        // file anywhere the process can write (another project's data, a config
        // file...). The extension filter below does NOT stop this — a .json or .css
        // written to the wrong place is still an escape. Reject anything that is not
        // a clean, relative, non-escaping path BEFORE it is used to build a path.
        $relativePath = str_replace('\\', '/', $relativePath);
        if (strpos($relativePath, "\0") !== false                 // null-byte truncation
            || $relativePath[0] === '/'                            // absolute (posix)
            || preg_match('#^[a-zA-Z]:#', $relativePath)           // absolute (windows drive)
            || preg_match('#(^|/)\.\.(/|$)#', $relativePath)       // any '..' segment
        ) {
            $stats['skipped_unsafe'][] = $relativePath;
            continue;
        }

        $destFilePath = $destPath . '/' . $relativePath;

        // If directory (ends with /)
        if (substr($name, -1) === '/') {
            // A directory name that the OS refuses (reserved device names, a
            // segment of only dots, invalid characters) is the archive's
            // problem, not the import's. Skip and report it the same way an
            // unsafe path is reported — it must not abort the whole import,
            // which used to roll the project back with a 500.
            if (!is_dir($destFilePath) && !@mkdir($destFilePath, 0755, true) && !is_dir($destFilePath)) {
                $stats['skipped_unsafe'][] = $relativePath . ' (unusable directory name)';
                continue;
            }
            $stats['directories']++;
            continue;
        }

        // SECURITY (C11 11.2) — no HIDDEN segment anywhere in the path. An
        // archive carries a website, not a working tree: `.git/`, `.svn/` and
        // `.idea/` are tooling leftovers, and a published `.git/` discloses the
        // whole source history. The extension allowlist alone did not stop them
        // — `.git/config.json` and `.idea/workspace.xml` have permitted
        // extensions. Deployment-owned hidden paths (a `/.well-known/` TLS
        // challenge, server config) belong in the deployment's own web root.
        if (qs_policy_has_hidden_segment($relativePath)) {
            $stats['skipped_disallowed'][] = $relativePath . ' (hidden path segment)';
            continue;
        }

        // SECURITY (C11 11.0) — ALLOWLIST. Anything whose extension is not
        // explicitly permitted is refused, so a spelling nobody predicted
        // ('.phtm', 'web.config') and a case variant of one that was
        // ('.HTACCESS') are both refused by default rather than by enumeration.
        if (!qs_import_allows_extension($relativePath)) {
            $stats['skipped_disallowed'][] = $relativePath;
            continue;
        }

        // Ensure parent directory exists
        $parentDir = dirname($destFilePath);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            $stats['skipped_unsafe'][] = $relativePath . ' (unusable directory name)';
            continue;
        }

        // Extract file
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            return ['success' => false, 'error' => "Failed to read file from ZIP: $relativePath"];
        }

        // SECURITY (C11 11.0) — the name must not lie about the content. An
        // allowed extension is necessary but not sufficient: '.png' holding
        // PHP source passes any extension check ever written. SVG comes back
        // sanitised, so write what the validator returns, not the raw bytes.
        $verdict = qs_import_validate_content($relativePath, $content);
        if (!$verdict['ok']) {
            $stats['skipped_disallowed'][] = $relativePath . ' (' . $verdict['reason'] . ')';
            continue;
        }
        $content = $verdict['content'];

        if (file_put_contents($destFilePath, $content) === false) {
            return ['success' => false, 'error' => "Failed to write file: $relativePath"];
        }

        $stats['files']++;
        $stats['total_size'] += strlen($content);
    }

    return ['success' => true];
}

/**
 * Rebuild PHP files from JSON structures
 */
function rebuildPhpFromJson(string $projectPath): array {
    $stats = [
        'config_rebuilt' => false,
        'routes_rebuilt' => false,
        'pages_rebuilt' => 0,
        'menu_rebuilt' => false,
        'footer_rebuilt' => false
    ];
    
    // 1. Rebuild config.php from config.json
    $configJsonPath = $projectPath . '/config.json';
    if (file_exists($configJsonPath)) {
        $configJson = json_decode(file_get_contents($configJsonPath), true);
        if ($configJson === null) {
            return ['success' => false, 'error' => 'Invalid config.json format'];
        }
        
        // Validate and filter config keys
        $validConfig = [];
        foreach (IMPORT_ALLOWED_CONFIG_KEYS as $key) {
            if (isset($configJson[$key])) {
                $validConfig[$key] = $configJson[$key];
            }
        }
        
        // Set defaults for required keys
        if (!isset($validConfig['SITE_NAME'])) {
            $validConfig['SITE_NAME'] = basename($projectPath);
        }
        if (!isset($validConfig['LANGUAGES_SUPPORTED'])) {
            $validConfig['LANGUAGES_SUPPORTED'] = ['en'];
        }
        if (!isset($validConfig['LANGUAGE_DEFAULT'])) {
            $validConfig['LANGUAGE_DEFAULT'] = $validConfig['LANGUAGES_SUPPORTED'][0] ?? 'en';
        }
        if (!isset($validConfig['MULTILINGUAL_SUPPORT'])) {
            $validConfig['MULTILINGUAL_SUPPORT'] = false;
        }
        
        $configPhp = "<?php\n/**\n * Site Configuration\n * Rebuilt from JSON on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($validConfig, true) . ";\n";
        
        if (file_put_contents($projectPath . '/config.php', $configPhp) === false) {
            return ['success' => false, 'error' => 'Failed to write config.php'];
        }
        
        $stats['config_rebuilt'] = true;
        
        // Remove config.json after successful rebuild
        unlink($configJsonPath);
    } else {
        // Create default config if no config.json
        $defaultConfig = [
            'SITE_NAME' => basename($projectPath),
            'LANGUAGES_SUPPORTED' => ['en'],
            'LANGUAGE_DEFAULT' => 'en',
            'MULTILINGUAL_SUPPORT' => false
        ];
        $configPhp = "<?php\n/**\n * Site Configuration (default)\n * Created on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($defaultConfig, true) . ";\n";
        file_put_contents($projectPath . '/config.php', $configPhp);
        $stats['config_rebuilt'] = true;
    }
    
    // 2. Rebuild routes.php from routes.json
    $routesJsonPath = $projectPath . '/routes.json';
    if (file_exists($routesJsonPath)) {
        $routesJson = json_decode(file_get_contents($routesJsonPath), true);
        if ($routesJson === null) {
            return ['success' => false, 'error' => 'Invalid routes.json format'];
        }
        
        // Validate routes structure (should be nested array)
        if (!is_array($routesJson)) {
            return ['success' => false, 'error' => 'routes.json must be an array'];
        }
        
        $routesPhp = "<?php\n/**\n * Route Definitions\n * Rebuilt from JSON on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . varExportNested($routesJson) . ";\n";
        
        if (file_put_contents($projectPath . '/routes.php', $routesPhp) === false) {
            return ['success' => false, 'error' => 'Failed to write routes.php'];
        }
        
        $stats['routes_rebuilt'] = true;
        
        // Remove routes.json after successful rebuild
        unlink($routesJsonPath);
    } else {
        // Create default routes if no routes.json
        $defaultRoutes = ['home' => []];
        $routesPhp = "<?php\n/**\n * Route Definitions (default)\n * Created on import: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . varExportNested($defaultRoutes) . ";\n";
        file_put_contents($projectPath . '/routes.php', $routesPhp);
        $stats['routes_rebuilt'] = true;
    }
    
    // 3. Rebuild page PHP files as development wrappers (use JsonToHtmlRenderer)
    $pagesJsonDir = $projectPath . '/templates/model/json/pages';
    
    if (is_dir($pagesJsonDir)) {
        $result = rebuildPageWrappers($pagesJsonDir, $projectPath . '/templates/pages', $stats);
        if (!$result['success']) {
            return $result;
        }
    }
    
    // Menu, footer, and components don't need compiled PHP in development mode.
    // JsonToHtmlRenderer handles them dynamically from JSON at runtime.
    $stats['menu_rebuilt'] = true;
    $stats['footer_rebuilt'] = true;
    
    return ['success' => true, 'stats' => $stats];
}

/**
 * Generate development page wrapper PHP files.
 * Each page gets a thin wrapper that delegates rendering to JsonToHtmlRenderer,
 * which reads the JSON structure at runtime and adds data-qs-* attributes
 * needed by the visual editor.
 */
function rebuildPageWrappers(string $jsonDir, string $phpDir, array &$stats, string $prefix = ''): array {
    $items = scandir($jsonDir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $jsonPath = $jsonDir . '/' . $item;
        
        if (is_dir($jsonPath)) {
            $expectedJsonFile = $item . '.json';
            $hasMatchingJson = file_exists($jsonPath . '/' . $expectedJsonFile);
            $currentRoute = $prefix ? $prefix . '/' . $item : $item;
            
            $subPhpDir = $phpDir . '/' . $item;
            @mkdir($subPhpDir, 0755, true);
            
            if ($hasMatchingJson) {
                $phpPath = $subPhpDir . '/' . $item . '.php';
                if (file_put_contents($phpPath, generatePageWrapper($currentRoute, $item)) === false) {
                    return ['success' => false, 'error' => "Failed to write: $item.php at $phpPath"];
                }
                $stats['pages_rebuilt']++;
            }
            
            // Recurse for nested routes
            $result = rebuildPageWrappers($jsonPath, $subPhpDir, $stats, $currentRoute);
            if (!$result['success']) {
                return $result;
            }
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            $routeName = pathinfo($item, PATHINFO_FILENAME);
            $currentRoute = $prefix ? $prefix . '/' . $routeName : $routeName;
            
            $pageDir = $phpDir . '/' . $routeName;
            @mkdir($pageDir, 0755, true);
            
            $phpPath = $pageDir . '/' . $routeName . '.php';
            if (file_put_contents($phpPath, generatePageWrapper($currentRoute, $routeName)) === false) {
                return ['success' => false, 'error' => "Failed to write: $routeName.php"];
            }
            $stats['pages_rebuilt']++;
        }
    }
    
    return ['success' => true];
}

/**
 * Generate the development page wrapper PHP code.
 *
 * SECURITY (C13 F-C13-2): `$routePath`/`$pageName` are ARCHIVE ENTRY NAMES —
 * `rebuildPageWrappers()` derives them from `scandir()` of the just-extracted
 * upload, so they are fully attacker-authored. The former body interpolated the
 * name into an `<<<PHP` (interpolating) heredoc inside a single-quoted literal
 * (`renderPage('$routePath')`); an entry named `x');<php>;#.json` closed the
 * literal and the tail became live PHP in a wrapper that `public/p/index.php`
 * later `require_once`s — authenticated RCE, reachable via the any-auth
 * importProject. The C11 import gates check an entry's path/extension/content
 * but never its name's character set, and a name never becomes content, so none
 * of them caught it.
 *
 * The wrapper is route-AGNOSTIC by design: it reads the route from
 * `TrimParameters` at request time. So there is nothing to bake in — delegate
 * to the single canonical generator (`generate_page_template`, the one
 * createProject uses), which is a nowdoc that interpolates NOTHING. This also
 * retires a stale second copy: importProject's old inline form predated the
 * Beta.8 route-agnostic bootstrap and omitted the renderer options array.
 * `$routePath`/`$pageName` are intentionally unused now (the canonical generator
 * ignores its argument); the signature is kept so the two call sites are
 * untouched.
 */
function generatePageWrapper(string $routePath, string $pageName): string {
    return generate_page_template($routePath);
}

/**
 * Validate imported project structure
 */
function validateImportedProject(string $projectPath): array {
    $errors = [];
    
    // Check for config.php (should have been rebuilt)
    if (!file_exists($projectPath . '/config.php')) {
        $errors['config'] = 'config.php missing (rebuild failed)';
    }
    
    // Check for routes.php (should have been rebuilt)
    if (!file_exists($projectPath . '/routes.php')) {
        $errors['routes'] = 'routes.php missing (rebuild failed)';
    }
    
    // Check templates directory exists
    if (!is_dir($projectPath . '/templates')) {
        $errors['templates'] = 'templates directory missing';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Get info from imported project
 */
function getImportedProjectInfo(string $projectPath): array {
    $info = [
        'site_name' => 'Unknown',
        'routes_count' => 0,
        'languages' => []
    ];
    
    // Load config
    if (file_exists($projectPath . '/config.php')) {
        $config = require $projectPath . '/config.php';
        $info['site_name'] = $config['SITE_NAME'] ?? 'Unknown';
    }
    
    // Count routes (recursive for nested)
    if (file_exists($projectPath . '/routes.php')) {
        $routes = require $projectPath . '/routes.php';
        $info['routes_count'] = countRoutesRecursive($routes);
    }
    
    // List languages
    $translateDir = $projectPath . '/translate';
    if (is_dir($translateDir)) {
        foreach (scandir($translateDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && $file !== 'default.json') {
                $info['languages'][] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
    }
    
    return $info;
}

/**
 * Count routes recursively
 */
function countRoutesRecursive(array $routes): int {
    $count = 0;
    foreach ($routes as $name => $children) {
        $count++;
        if (is_array($children) && !empty($children)) {
            $count += countRoutesRecursive($children);
        }
    }
    return $count;
}

/**
 * Recursively delete a directory
 */
function deleteImportDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        
        if (is_dir($path)) {
            deleteImportDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * Get upload error message
 */
function getUploadErrorMessage(int $errorCode): string {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
    ];
    
    return $messages[$errorCode] ?? 'Unknown error';
}

/**
 * Format bytes to human readable
 */
function formatImportBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp = floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    
    return round($bytes / pow(1024, $exp), 2) . ' ' . $units[$exp];
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_importProject($trimParams->params(), $trimParams->additionalParams())->send();
}
