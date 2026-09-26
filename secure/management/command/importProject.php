<?php
/**
 * importProject Command (Secure Version)
 * 
 * Imports a project from an uploaded ZIP file with secure rebuild approach.
 * PHP files in the ZIP are IGNORED - all PHP is rebuilt from JSON structures.
 * 
 * Security measures:
 * - PHP files in ZIP are refused by the extension allowlist (listed in the response)
 * - config.php rebuilt from validated config.json
 * - routes.php rebuilt from validated routes.json
 * - Page PHP wrappers rebuilt from JSON using JsonToHtmlRenderer (dev mode)
 * 
 * @method POST
 * @route /management/importProject
 * @auth required (any authenticated account — category projects.create is
 *       scope: global, access: 'any'; there is no role gate in front of this)
 * 
 * @param file $file Uploaded ZIP file (required via multipart/form-data)
 * @param string $name New project name (optional, uses ZIP folder name if not provided)
 * @param bool $switch_to Switch to imported project (optional, default: false)
 * 
 * @return ApiResponse Import result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/filePolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/uploadLimits.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// Allowed keys in config.json import (security: whitelist only). Every one is a
// setting a command writes — the list exportProject exports — and its value is
// checked against that command's rule (importFirstInvalidSetting()).
const IMPORT_ALLOWED_CONFIG_KEYS = [
    'SITE_NAME',
    'LANGUAGES_SUPPORTED',
    'LANGUAGE_DEFAULT',
    'LANGUAGES_NAME',
    'MULTILINGUAL_SUPPORT',
    'THEME_MODE_ENABLED',
    'THEME_DEFAULT',
    'THEME_USER_TOGGLE_ENABLED',
    'FAVICON_PATH'
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
    // ⚠ There is deliberately NO `array_merge($_GET, $_POST, $params)` here.
    //
    // The line that used to open this function was commented "merge query
    // parameters for POST with multipart". The need behind it is real — a
    // caller uploading an archive may put `name` and `switch_to` on the query
    // string rather than in the multipart body — but it was already met: the
    // dispatcher's TrimParametersManagement builds $params as $_GET merged
    // with $_POST, in that same precedence, before this file is included. Over
    // HTTP the merge was a no-op, and that those options still arrive was
    // verified end to end.
    //
    // What it DID add was reach: it re-imported the ambient superglobals on
    // EVERY call, an in-process one included, so every parameter this function
    // reads was settable from the query string whatever the caller intended.
    // That is how a branch commented "for internal calls" became a public one
    // (S5.6a). A parameter this command reads is a parameter the web can set.

    // Per-user resource limits (quota.php — absent file = no limits). The RATE
    // axis first, before the archive is opened or a byte is read: an import is
    // the most expensive upload QuickSite accepts, so a caller at their limit
    // should be refused before any of that work is done. The counter is spent
    // only by an import that actually completes.
    require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    $quotaUserId = (string)(getCurrentUser()['id'] ?? '');
    $rateWait = qs_quota_rate_wait($quotaUserId);
    if ($rateWait > 0) {
        return ApiResponse::create(429, 'quota.rate_limited')
            ->withMessage(qs_quota_rate_message($rateWait))
            ->withData(['retry_after' => $rateWait]);
    }

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
    // An uploaded file is the ONLY way an archive gets in.
    //
    // ⚠ There used to be a second method here: a `file_path` parameter,
    // commented "for internal calls", that opened whatever absolute path it
    // named — no project-name validation, no marker comparison, no base
    // directory. It had no internal caller (importProject is not in
    // CommandRunner's allowlist and no PHP file requires this one), it was
    // documented nowhere, and because `projects.create` is a global
    // access:'any' category it was reachable by every signed-in account,
    // member of nothing. It handed them a filesystem existence oracle over the
    // whole server and read past the containment that put each project's
    // exports under its own marker. Removed in S5.6a — an import supplies its
    // archive in the request body or not at all.
    else {
        // Same distinction uploadAsset draws: an archive PHP discarded for
        // exceeding post_max_size arrives here looking exactly like no archive
        // at all. An import ZIP is the one upload most likely to be big, so this
        // is the surface where the wrong answer costs the most.
        $breach = qs_post_body_discarded();
        if ($breach !== null) {
            return ApiResponse::create(413, 'request.body_too_large')
                ->withMessage(qs_post_too_large_message($breach))
                ->withData(qs_post_too_large_data($breach));
        }

        // transport_max, not effective_max: an archive is not an asset and has
        // no per-category cap, so the ceiling that applies to it is the server's.
        $limits = qs_upload_limits();
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('No file uploaded')
            ->withData([
                'max_file_size'       => $limits['transport_max'],
                'max_file_size_human' => qs_format_size($limits['transport_max']),
            ])
            ->withErrors(['file' => 'Required. Upload a ZIP file as multipart/form-data.']);
    }
    
    // Options.
    //
    // ⚠ `overwrite` IS GONE (S2.5). It used to delete the existing project
    // directory and re-create it from the archive, with the importer birth-
    // written as sole owner — and with NO membership check of any kind.
    // `projects.create` is a global access:'any' category, so that made
    // "replace any project on this installation and take its id" available to
    // every signed-in account, including one invited to edit a single
    // unrelated project. Reproduced end to end: a non-member replaced
    // another account's project and members.json came back naming the
    // attacker as owner.
    //
    // The ruling is that two projects may never share an id, in any
    // circumstance — which matters more since S2.4 made the id a browser
    // storage namespace. So a collision now always refuses, and the way to
    // reuse an id is to delete the project first: an explicit, owner-gated
    // action that already exists as its own command, rather than a side effect
    // of an upload.
    $newName = trim($params['name'] ?? '');
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
    $archiveBytes = 0;
    $limitBreach = checkArchiveLimits($zip, $archiveBytes);
    if ($limitBreach !== null) {
        $zip->close();
        return ApiResponse::create(413, 'validation.size_limit_exceeded')
            ->withMessage($limitBreach['message'])
            ->withData($limitBreach['data']);
    }

    // The STORAGE axis. The archive's UNCOMPRESSED total is what will land on
    // disk, and checkArchiveLimits has just walked the central directory to
    // compute it — so this costs nothing beyond the comparison. Checked before
    // the project directory is created, so a refusal leaves nothing behind.
    $quotaBreach = qs_quota_check_storage($quotaUserId, $archiveBytes);
    if ($quotaBreach !== null) {
        $zip->close();
        return ApiResponse::create(507, 'quota.storage_exceeded')
            ->withMessage($quotaBreach['message'])
            ->withData($quotaBreach['data']);
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
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/D', $projectName)) {
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
    
    // A colliding id is refused, always — there is no option that turns this
    // off. See the note beside the removed `overwrite` option above: an id is a
    // project's identity AND its browser-storage namespace, and no upload gets
    // to reassign either.
    //
    // ⚠ Deliberately says nothing about who owns the existing project, or
    // whether the caller can see it. The dispatcher's rule is that existence,
    // membership and role never leak; this answer is identical for a project
    // the caller owns and one they have never heard of.
    if (is_dir($projectPath)) {
        $zip->close();
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("A project with the id '$projectName' already exists on this installation.")
            ->withData([
                'hint' => 'Import under a different name, or delete the existing project first.',
            ]);
    }
    
    // Every entry's name, and every file the site is read from — pages,
    // components, menu, footer, snippets, settings, routes, translations, data —
    // is checked before the project directory exists, and the first one that
    // fails refuses the WHOLE import: a name that is not a clean relative path, a
    // file that cannot be read, does not parse, or is refused by the archive
    // content check, and a structure that carries an unsafe attribute, a blocked
    // tag or an invalid component reference. Importing the rest would ship a
    // project with a hole in it — a dropped page's route survives and 404s, a
    // dropped translation leaves a language showing raw keys. A refused import
    // creates nothing.
    $archiveFailure = importFirstStructureFailure($zip, $projectFolder['prefix']);
    if ($archiveFailure !== null) {
        $zip->close();
        return qs_unsafe_structure_param_response($archiveFailure);
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
        '/public/style'
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
    // Resolved once at the top of the command for the quota check; reused here
    // rather than re-validating the bearer token a second time. Same value,
    // same nullable shape the birth-write and the index update below expect.
    $importerId = $quotaUserId !== '' ? $quotaUserId : null;
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
        'path' => SECURE_FOLDER_NAME . '/projects/' . $projectName,
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
            // Entries whose folder the filesystem would not create (a reserved
            // device name on Windows, say). An entry whose NAME escapes the
            // project, or is not a clean relative path, never gets this far:
            // importFirstStructureFailure() refuses the whole archive for it.
            'skipped_unsafe_paths' => count($stats['skipped_unsafe']),
            'skipped_unsafe' => $stats['skipped_unsafe'],
            // Entries refused by the hidden-path rule, the extension allowlist or
            // content validation. Reported rather than fatal: one stray file must
            // not block an otherwise legitimate import, but it must never be
            // silent. A file the site reads is never listed here —
            // importFirstStructureFailure() refuses the whole archive for it instead.
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

    // Per-user resource limits — the import is on disk, so it counts. The new
    // project has no cached measurement to drop (it did not exist a moment
    // ago), which is why only the rate counter moves here.
    qs_quota_record_upload($quotaUserId);

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
 * @param int|null $totalBytes OUT: uncompressed total of every entry, set only
 *                             when the archive passes. The walk needed to reach
 *                             that number is the walk this function already
 *                             does, so the per-user quota check reuses it
 *                             rather than opening the central directory twice.
 * @return array{message:string, data:array}|null Null when the archive is within limits
 */
function checkArchiveLimits(ZipArchive $zip, ?int &$totalBytes = null): ?array {
    $limits = qs_archive_limits();
    $totalBytes = 0;

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
            return ['message' => 'Archive contains an entry compressed beyond the allowed ratio (raise max_ratio in ' . SECURE_FOLDER_NAME . '/management/config/import-policy.php)', 'data' => [
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

    $totalBytes = $total;
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
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $relativePath = $name === false ? null : importEntryRelativePath($name, $prefix);
        if ($relativePath === null) {
            continue;
        }
        $isDirectory = substr($name, -1) === '/';

        // SECURITY — the entry name is fully attacker-controlled, and it is about
        // to become a filesystem path: 'proj/../../../evil.json' would resolve
        // OUTSIDE the new project directory, and 'templates//model/…' would land on
        // a real page under a spelling no check recognises. The extension filter
        // below does NOT stop either — a .json written to the wrong place is still
        // an escape. importFirstStructureFailure() refused every name that is not a
        // clean relative path before the project directory existed, so one here
        // means that gate is broken; the import then fails whole, like the other
        // second layers below.
        $nameRefusal = importEntryNameRefusal($relativePath, $isDirectory);
        if ($nameRefusal !== null) {
            return ['success' => false, 'error' => "$relativePath: $nameRefusal"];
        }

        // Skip export_info.json (metadata file)
        if ($relativePath === 'export_info.json') {
            continue;
        }

        $destFilePath = $destPath . '/' . $relativePath;
        $siteData = importIsSiteData($relativePath);

        // If directory (ends with /)
        if ($isDirectory) {
            // A directory name that the OS refuses (a reserved device name,
            // invalid characters) is the archive's problem, not the import's.
            // Skip and report it under skipped_unsafe rather than failing the
            // whole import over one folder.
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
        // A file the site reads was already held to it by the gate, so a refusal
        // here fails whole, like every other second layer.
        if (!qs_import_allows_extension($relativePath)) {
            if ($siteData) {
                return ['success' => false, 'error' => "$relativePath: the import policy does not allow this file type"];
            }
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

        // SECURITY — the name must not lie about the content. An allowed
        // extension is necessary but not sufficient: '.png' holding PHP source
        // passes any extension check ever written. SVG comes back sanitised, so
        // write what the validator returns, not the raw bytes. A refused entry is
        // skipped and reported while the rest imports — unless it is a file the
        // site reads, which the gate checked the same way: a refusal of one here
        // fails the whole import (the second layer). Those files are also the only
        // entries the check treats as never served, which exempts their text from
        // the PHP-opening-tag rule — see importIsSiteData().
        $verdict = qs_import_validate_content($relativePath, $content, $siteData);
        if (!$verdict['ok']) {
            if ($siteData) {
                return ['success' => false, 'error' => "$relativePath: {$verdict['reason']}"];
            }
            $stats['skipped_disallowed'][] = $relativePath . ' (' . $verdict['reason'] . ')';
            continue;
        }
        $content = $verdict['content'];

        // SECURITY — the structure gates' SECOND LAYER. Every gate above
        // constrains an entry's PATH, EXTENSION or CONTENT SHAPE; none of them
        // looks at a tag name or a component reference inside a well-formed
        // structure, which is how an archive would put a node the renderer
        // refuses ('script', or anything off the allowlist) or a reference that
        // walks out of the components directory straight into stored data. The
        // same shared policies the write-side writers use — one helper, one answer.
        //
        // importFirstStructureFailure() ran the content check and both of these
        // over the same entries before the project directory existed, and refused
        // the WHOLE archive on any failure — so an archive that got this far
        // passes them, and a failure here means that gate is broken. The answer
        // is then the gate's own: the import fails whole and is rolled back.
        // Skipping the entry instead would ship a project with a hole in it.
        $badTag = importFirstUnrenderableTag($relativePath, $content);
        if ($badTag !== null) {
            return ['success' => false, 'error' => "$relativePath: blocked tag '$badTag'"];
        }
        $badRef = importFirstInvalidComponentReference($relativePath, $content);
        if ($badRef !== null) {
            return ['success' => false, 'error' => "$relativePath: invalid component reference '$badRef'"];
        }

        if (file_put_contents($destFilePath, $content) === false) {
            return ['success' => false, 'error' => "Failed to write file: $relativePath"];
        }

        $stats['files']++;
        $stats['total_size'] += strlen($content);
    }

    return ['success' => true];
}

/**
 * An archive entry's path inside the project folder, `/`-separated — the one
 * derivation the archive gate and the extraction share, so they judge the same
 * string — or null for an entry the import does not extract: one outside the
 * project folder, and the project folder's own directory entry.
 *
 * @param string $name   the entry's name in the archive
 * @param string $prefix the project folder's name plus `/`, or '' for an archive
 *                       whose project sits at its root
 */
function importEntryRelativePath(string $name, string $prefix): ?string {
    if ($prefix !== '' && strpos($name, $prefix) !== 0) {
        return null;
    }
    $relativePath = str_replace('\\', '/', substr($name, strlen($prefix)));
    return $relativePath === '' ? null : $relativePath;
}

/**
 * Why an archive entry's name is not a clean relative path, or null when it is.
 *
 * Every check an import makes reads the entry's NAME — a page is a `.json` under
 * `templates/model/json/` — while the extraction writes it at `<project>/<name>`
 * and lets the filesystem resolve that. The two agree only when the name has one
 * spelling. `templates//model/json/…` and `templates/./model/json/…` land on the
 * real page path on every OS, and on Windows `templates./…` can too — a trailing
 * dot or space is not part of a Windows folder name. None of them starts with
 * `templates/model/json/`, so a check that reads the name would pass a page it
 * never looked at, or skip one the site needs. `..` walks out of the project. So a
 * clean name is relative (no leading slash, no drive letter), holds no NUL byte,
 * and is made of segments that are neither empty, `.` nor `..`, and do not end in
 * a dot or a space. exportProject never writes another shape — every name it
 * carries is one the engine chose or validated — so a name that fails comes from
 * an archive built some other way, and the whole archive is refused for it.
 *
 * @param string $relativePath the entry's path inside the project folder (importEntryRelativePath())
 * @param bool   $isDirectory  a directory entry, whose name ends in the one `/` the format gives it
 * @return string|null the refusal message, or null for a clean name
 */
function importEntryNameRefusal(string $relativePath, bool $isDirectory): ?string {
    if (strpos($relativePath, "\0") !== false) {
        return 'Not a clean path: it contains a NUL byte.';
    }
    if ($isDirectory && substr($relativePath, -1) === '/') {
        $relativePath = substr($relativePath, 0, -1);
    }
    if ($relativePath !== '' && ($relativePath[0] === '/' || preg_match('#^[a-zA-Z]:#', $relativePath))) {
        return 'Not a clean path: it is absolute.';
    }
    foreach (explode('/', $relativePath) as $segment) {
        if ($segment === '') {
            return 'Not a clean path: it has an empty segment (two slashes in a row).';
        }
        if ($segment === '.' || $segment === '..') {
            return "Not a clean path: it has a '.' or '..' segment.";
        }
        $last = substr($segment, -1);
        if ($last === '.' || $last === ' ') {
            return 'Not a clean path: a segment ends in a dot or a space.';
        }
    }
    return null;
}

/**
 * Which archive entries hold STRUCTURE — the one definition the archive gate,
 * the two site predicates below and the extraction share, so they can never
 * disagree about which entries they walk.
 *
 * Deliberately narrow. A structure walk follows `children` and trips on a `tag`
 * or a `component` key — which is exactly right for a page/component/menu/footer
 * tree, and exactly WRONG for the author's own data. A `data/items.json` holding
 * `[{"tag":"newsletter",...}]` is legitimate content, not markup, and gating it
 * would refuse a file the site depends on. So an entry holds structure only when
 * it is a `.json` file in one of the two places structure lives:
 *   - templates/model/json/  — pages, components, menu.json, footer.json;
 *     the file IS the tree.
 *   - snippets/              — a snippet wraps its tree under `structure`, and
 *     insertSnippet copies that tree into a page.
 * and not at a hidden path: the extraction never writes one (see
 * qs_policy_has_hidden_segment()), so nothing there is ever read as structure,
 * and a tooling leftover such as a `._about.json` is skipped like any other.
 * Paths are matched case-insensitively: NTFS resolves 'Templates/' and
 * 'templates/' to one directory, so a case variant must not slip the gate. The
 * name is a clean one by the time this is asked (importEntryNameRefusal()), so
 * its spelling is the place it lands.
 *
 * @param string $relativePath the entry's path inside the project folder, `/`-separated
 * @return string|null 'model' or 'snippet', or null when the entry holds no structure
 */
function importStructureKind(string $relativePath): ?string {
    $lower = strtolower($relativePath);
    if (substr($lower, -5) !== '.json' || qs_policy_has_hidden_segment($relativePath)) {
        return null;
    }
    if (strpos($lower, 'templates/model/json/') === 0) {
        return 'model';
    }
    if (strpos($lower, 'snippets/') === 0) {
        return 'snippet';
    }
    return null;
}

/**
 * Is this entry one of the JSON files the site is read from — the project's own
 * data? The one definition the archive gate, the extraction and the content
 * check's never-served exemption share.
 *
 * Every structure file (importStructureKind()), and every other `.json` file the
 * project is read from:
 *   - config.json and routes.json at the project root — the import rebuilds
 *     config.php and routes.php from them;
 *   - config/   — per-project settings (route layout, sitemap, …);
 *   - translate/ — every language's text;
 *   - data/     — aliases, API endpoints, asset metadata and the rest.
 * Not at a hidden path, for the reason importStructureKind() gives.
 *
 * Two rules follow from being one of them. A file the site reads that cannot be
 * read or used refuses the whole archive, because importing the rest ships a
 * project with a hole in it: a route whose page is missing, a language showing
 * raw keys, settings and routes silently replaced by the defaults. And none of
 * them is ever served — a web server reaches only a project's `public/` — so the
 * content check lets their text show a PHP opening tag: every path from these
 * files into generated PHP (`config.php`, `routes.php`, a build's compiled pages)
 * writes values as string literals, never as code.
 *
 * @param string $relativePath the entry's path inside the project folder, `/`-separated
 */
function importIsSiteData(string $relativePath): bool {
    if (importStructureKind($relativePath) !== null) {
        return true;
    }
    $lower = strtolower($relativePath);
    if (substr($lower, -5) !== '.json' || qs_policy_has_hidden_segment($relativePath)) {
        return false;
    }
    if ($lower === 'config.json' || $lower === 'routes.json') {
        return true;
    }
    foreach (['config/', 'translate/', 'data/'] as $folder) {
        if (strpos($lower, $folder) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * The tag gate's SITE predicate: given an archive entry, return the first tag the
 * render/compile layers would refuse, or null when this entry carries no
 * renderable structure at all (see importStructureKind() for which entries do).
 *
 * @return string|null the offending tag, or null when the entry is clean/irrelevant
 */
function importFirstUnrenderableTag(string $relativePath, string $content): ?string {
    $kind = importStructureKind($relativePath);
    if ($kind === null) {
        return null;
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }
    $structure = $kind === 'snippet' ? ($data['structure'] ?? null) : $data;

    return qs_first_unrenderable_tag($structure);
}

/**
 * The component-reference gate's SITE predicate — the exact twin of
 * importFirstUnrenderableTag() above, over the same entries: the author's own
 * data files may legitimately contain a `component` key that means something
 * else entirely.
 *
 * @return string|null the offending reference, or null when the entry is clean
 */
function importFirstInvalidComponentReference(string $relativePath, string $content): ?string {
    $kind = importStructureKind($relativePath);
    if ($kind === null) {
        return null;
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }
    $structure = $kind === 'snippet' ? ($data['structure'] ?? null) : $data;

    return qs_first_invalid_component_reference($structure);
}

/**
 * The first setting in an archive's config.json that the command writing it
 * would refuse, or null when every one is valid. config.php is rebuilt from these
 * values and the router, the translator and every language command read them — a
 * language code becomes part of a translation file's path — so a value no command
 * could have written refuses the whole archive.
 *
 * Each rule is its writer's:
 *   - SITE_NAME — createProject (at most 200 characters, control characters
 *     removed) and cloneProject;
 *   - LANGUAGES_SUPPORTED — createProject, addLang and deleteLang: a list of
 *     distinct language codes that is never empty;
 *   - LANGUAGE_DEFAULT — createProject and setDefaultLang: a code on that list;
 *   - LANGUAGES_NAME — createProject, addLang and setMultilingual: a display name
 *     of at most 100 bytes for a listed code;
 *   - MULTILINGUAL_SUPPORT — setMultilingual; THEME_MODE_ENABLED, THEME_DEFAULT
 *     and THEME_USER_TOGGLE_ENABLED — setThemeMode;
 *   - FAVICON_PATH — editFavicon, and editAsset when it renames the favicon.
 * A setting that is absent (or null) is not checked: the rebuild gives it its
 * default, and the language list's default is the one LANGUAGE_DEFAULT is then
 * checked against.
 *
 * @return array|null ['key' => the setting, 'message' => the rule it breaks]
 */
function importFirstInvalidSetting(array $config): ?array {
    $isCode = static function ($v): bool {
        return is_string($v) && RegexPatterns::match('language_code', $v);
    };
    $codeRule = RegexPatterns::getDescription('language_code');

    if (isset($config['SITE_NAME'])) {
        $name = $config['SITE_NAME'];
        if (!is_string($name) || mb_strlen($name, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return ['key' => 'SITE_NAME', 'message' => 'SITE_NAME must be text of at most 200 characters, with no control characters.'];
        }
    }

    $languages = ['en'];
    if (isset($config['LANGUAGES_SUPPORTED'])) {
        $list = $config['LANGUAGES_SUPPORTED'];
        if (!is_array($list) || $list === [] || array_values($list) !== $list
            || count(array_filter($list, $isCode)) !== count($list)
            || count(array_unique($list)) !== count($list)) {
            return ['key' => 'LANGUAGES_SUPPORTED', 'message' => "LANGUAGES_SUPPORTED must be a list of distinct language codes ({$codeRule}), not empty."];
        }
        $languages = $list;
    }

    if (isset($config['LANGUAGE_DEFAULT'])
        && (!$isCode($config['LANGUAGE_DEFAULT']) || !in_array($config['LANGUAGE_DEFAULT'], $languages, true))) {
        return ['key' => 'LANGUAGE_DEFAULT', 'message' => "LANGUAGE_DEFAULT must be a language code ({$codeRule}) listed in LANGUAGES_SUPPORTED."];
    }

    if (isset($config['LANGUAGES_NAME'])) {
        $names = $config['LANGUAGES_NAME'];
        $valid = is_array($names);
        foreach ($valid ? $names : [] as $code => $label) {
            if (!is_string($code) || !in_array($code, $languages, true) || !is_string($label)
                || $label === '' || strlen($label) > 100 || !RegexPatterns::match('language_name', $label)) {
                $valid = false;
                break;
            }
        }
        if (!$valid) {
            return ['key' => 'LANGUAGES_NAME', 'message' => 'LANGUAGES_NAME must give each listed language code a display name of at most 100 bytes ('
                . RegexPatterns::getDescription('language_name') . ').'];
        }
    }

    foreach (['MULTILINGUAL_SUPPORT', 'THEME_MODE_ENABLED', 'THEME_USER_TOGGLE_ENABLED'] as $key) {
        if (isset($config[$key]) && !is_bool($config[$key])) {
            return ['key' => $key, 'message' => "{$key} must be true or false."];
        }
    }
    if (isset($config['THEME_DEFAULT']) && !in_array($config['THEME_DEFAULT'], ['light', 'dark', 'system'], true)) {
        return ['key' => 'THEME_DEFAULT', 'message' => 'THEME_DEFAULT must be light, dark or system.'];
    }

    if (isset($config['FAVICON_PATH'])) {
        $prefix = '/assets/images/';
        $path = $config['FAVICON_PATH'];
        $file = is_string($path) && strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
        if ($file === '' || strlen($file) > 100 || !RegexPatterns::match('file_name_with_ext', $file)
            || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), qs_favicon_extensions(), true)) {
            return ['key' => 'FAVICON_PATH', 'message' => "FAVICON_PATH must be {$prefix} followed by a file name with a favicon extension ("
                . implode(', ', qs_favicon_extensions()) . ').'];
        }
    }

    return null;
}

/**
 * The ARCHIVE GATE: every entry the import would extract is checked before the
 * project directory is created, and the first one that fails refuses the WHOLE
 * archive. A project imported with one page dropped keeps that page's route, and
 * the route 404s; a refused import leaves nothing on disk.
 *
 * Every entry's name must be a clean relative path — `unsafe_path` otherwise
 * (importEntryNameRefusal()), because only a clean name lands where its spelling
 * says. Then every file the site reads (importIsSiteData()) is checked, per entry
 * the first of:
 *   1. `invalid_json` — the entry cannot be read, does not parse, or does not
 *      decode to a JSON array or object; the site could not read it either;
 *   2. `disallowed_content` — the import policy's extension allowlist or the
 *      archive content check refuses it, as it would at extraction;
 * and the root config.json also `invalid_setting` — a setting the command that
 * writes it would refuse (importFirstInvalidSetting()), named in `value`;
 * and a structure file (importStructureKind()) also the first of:
 *   3. `unsafe_value` — an attribute the write gate refuses, naming the node and
 *      the attribute;
 *   4. `blocked_tag` — a tag the renderer refuses;
 *   5. `invalid_component_reference` — a reference the resolver refuses.
 * Every other entry keeps the extraction's per-entry rule: an asset, a stray file
 * or anything at a hidden path is skipped and reported when refused, while the
 * rest imports.
 *
 * @return array|null the first failure, for qs_unsafe_structure_param_response():
 *                    `file` names the entry and `reason` the check it failed
 */
function importFirstStructureFailure(ZipArchive $zip, string $prefix): ?array {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $relativePath = $name === false ? null : importEntryRelativePath($name, $prefix);
        if ($relativePath === null) {
            continue;
        }
        $isDirectory = substr($name, -1) === '/';
        $nameRefusal = importEntryNameRefusal($relativePath, $isDirectory);
        if ($nameRefusal !== null) {
            return ['file' => $relativePath, 'reason' => 'unsafe_path', 'message' => $nameRefusal];
        }
        if ($isDirectory || !importIsSiteData($relativePath)) {
            continue;
        }
        $kind = importStructureKind($relativePath);

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            return ['file' => $relativePath, 'reason' => 'invalid_json',
                    'message' => 'The entry cannot be read from the archive (it is damaged or encrypted).'];
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $message = $kind !== null
                ? 'Not a structure: the file must hold a JSON array or object.'
                : 'The file must hold a JSON array or object.';
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = 'Not valid JSON (' . json_last_error_msg() . ').';
                // A byte-order mark is invisible in an editor, so "Syntax error"
                // on JSON that looks right says nothing useful. Named only when it
                // is the one thing wrong: without it the rest must decode.
                if (strncmp($content, "\xEF\xBB\xBF", 3) === 0 && is_array(json_decode(substr($content, 3), true))) {
                    $message = 'Not valid JSON: the file starts with a byte-order mark (BOM). Save it as UTF-8 without a BOM.';
                }
            }
            return ['file' => $relativePath, 'reason' => 'invalid_json', 'message' => $message];
        }
        if (!qs_import_allows_extension($relativePath)) {
            return ['file' => $relativePath, 'reason' => 'disallowed_content',
                    'message' => 'The import policy does not allow this file type.'];
        }
        $verdict = qs_import_validate_content($relativePath, $content, true);
        if (!$verdict['ok']) {
            return ['file' => $relativePath, 'reason' => 'disallowed_content',
                    'message' => ucfirst($verdict['reason']) . '.'];
        }
        if (strtolower($relativePath) === 'config.json') {
            $badSetting = importFirstInvalidSetting($data);
            if ($badSetting !== null) {
                return ['file' => $relativePath, 'reason' => 'invalid_setting', 'value' => $badSetting['key'],
                        'message' => $badSetting['message']];
            }
        }
        if ($kind === null) {
            continue;
        }

        $failure = qs_first_unsafe_structure_param($kind === 'snippet' ? ($data['structure'] ?? null) : $data);
        if ($failure !== null) {
            return $failure + ['file' => $relativePath, 'reason' => 'unsafe_value'];
        }
        $badTag = importFirstUnrenderableTag($relativePath, $content);
        if ($badTag !== null) {
            return ['file' => $relativePath, 'reason' => 'blocked_tag', 'value' => $badTag,
                    'message' => "Tag '{$badTag}' is not allowed (security restriction)."];
        }
        $badRef = importFirstInvalidComponentReference($relativePath, $content);
        if ($badRef !== null) {
            return ['file' => $relativePath, 'reason' => 'invalid_component_reference', 'value' => $badRef,
                    'message' => "Component reference '{$badRef}' is not allowed (security restriction)."];
        }
    }
    return null;
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
            // Inside a route's folder, <route>.json is that folder's own page, and
            // the directory branch one level up has already written its wrapper.
            // Any other .json here is a page stored flat — `pages/<route>.json`,
            // which resolvePageJsonPath() also reads.
            if ($prefix !== '' && $item === basename($jsonDir) . '.json') {
                continue;
            }
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
    $limits = qs_upload_limits();

    $messages = [
        // The number, not the directive name — see uploadAsset for the same
        // correction. "File exceeds upload_max_filesize" told a caller which
        // config key to blame and never what the ceiling actually is.
        UPLOAD_ERR_INI_SIZE => 'The archive is larger than this server accepts for a single upload ('
            . qs_format_size($limits['upload_max_filesize']) . ', PHP upload_max_filesize). '
            . 'The largest file this server will carry is ' . qs_format_size($limits['transport_max']) . '.',
        UPLOAD_ERR_FORM_SIZE => 'The archive exceeds the MAX_FILE_SIZE limit declared by the form',
        UPLOAD_ERR_PARTIAL => 'The archive was only partially uploaded — the connection ended early',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload folder configured',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload'
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
