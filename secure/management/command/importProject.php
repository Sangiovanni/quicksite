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
// The keys an import takes from config.json (security: an allowlist) are
// QS_PROJECT_SETTING_KEYS — the list exportProject exports — and each value is
// checked against the rule its writers follow (importFirstInvalidSetting()).
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree_rollback

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
        qs_delete_tree_rollback($projectPath, 'importProject');
        return ApiResponse::create(500, 'server.extract_failed')
            ->withMessage('Failed to extract project files')
            ->withData(['error' => $extractResult['error']]);
    }
    
    // Rebuild PHP files from JSON (secure approach)
    $rebuildResult = rebuildPhpFromJson($projectPath);
    
    if (!$rebuildResult['success']) {
        qs_delete_tree_rollback($projectPath, 'importProject');
        return ApiResponse::create(500, 'server.rebuild_failed')
            ->withMessage('Failed to rebuild PHP files from JSON')
            ->withData(['error' => $rebuildResult['error']]);
    }
    
    // Validate imported project has required files
    $validation = validateImportedProject($projectPath);
    
    if (!$validation['valid']) {
        qs_delete_tree_rollback($projectPath, 'importProject');
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
        qs_delete_tree_rollback($projectPath, 'importProject');
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
            // silent. A file the site reads, or a stylesheet, is never listed here —
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
 * Find the project folder in the archive, as exportProject writes it: the folder
 * holding config.json, routes.json or templates/model/json/, or the archive's
 * root. Whether the folder holds what an import requires is the archive gate's
 * question (importFirstStructureFailure()), which names what is missing.
 */
function findProjectFolderInZip(ZipArchive $zip): ?array {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = explode('/', $name);

        $filename = basename($name);

        if ($filename === 'config.json' || $filename === 'routes.json') {
            if (count($parts) === 1) {
                return ['name' => 'imported_project', 'prefix' => ''];
            } elseif (count($parts) === 2) {
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/'];
            }
        }

        if (strpos($name, 'templates/model/json/') !== false) {
            // Extract project folder name from path
            $jsonPos = strpos($name, '/templates/model/json/');
            if ($jsonPos > 0) {
                $projectFolder = substr($name, 0, $jsonPos);
                $firstSlash = strpos($projectFolder, '/');
                if ($firstSlash === false) {
                    return ['name' => $projectFolder, 'prefix' => $projectFolder . '/'];
                }
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
        // site reads or a stylesheet, which the gate checked the same way: a
        // refusal of one here fails the whole import (the second layer). The files
        // the site reads are also the only entries the check treats as never
        // served, which exempts their text from the PHP-opening-tag rule — see
        // importIsSiteData().
        $verdict = qs_import_validate_content($relativePath, $content, $siteData);
        if (!$verdict['ok']) {
            if ($siteData || importIsStylesheet($relativePath)) {
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
 * a dot or a space.
 *
 * Case is a spelling too. The engine reads `config.json`, `routes.json` and the
 * folders `config/`, `translate/`, `data/`, `snippets/`, `public/` and
 * `templates/model/json/` by those exact names; on Windows `CONFIG.JSON` or
 * `Templates/` lands on them, on Linux it is another file the engine never reads.
 * So a name that matches one of them only when case is ignored is not clean
 * either, and an archive imports the same way on every system.
 *
 * exportProject never writes another shape — every name it carries is one the
 * engine chose or validated — so a name that fails comes from an archive built
 * some other way, and the whole archive is refused for it.
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
    $probe = $isDirectory ? $relativePath . '/' : $relativePath;
    foreach (['config.json', 'routes.json', 'config/', 'translate/', 'data/', 'snippets/', 'public/',
              'templates/', 'templates/model/', 'templates/model/json/'] as $fixed) {
        $spelled = substr($fixed, -1) === '/' ? substr($probe, 0, strlen($fixed)) : $probe;
        if ($spelled !== $fixed && strcasecmp($spelled, $fixed) === 0) {
            return "Not a clean path: '{$spelled}' must be spelled '{$fixed}', the one spelling the engine reads.";
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
 * Is this entry a STYLESHEET — any `.css` file, wherever the archive puts it, not
 * at a hidden path (which the import never writes)?
 *
 * A stylesheet the content check refuses — it opens a PHP block, or holds a
 * construct every command that writes a stylesheet refuses (qs_css_first_danger())
 * — refuses the whole archive rather than being skipped: a project imported
 * without its stylesheet is a site with its styling missing, and the same text is
 * refused however it would arrive.
 *
 * @param string $relativePath the entry's path inside the project folder, `/`-separated
 */
function importIsStylesheet(string $relativePath): bool {
    return qs_policy_extension($relativePath) === 'css' && !qs_policy_has_hidden_segment($relativePath);
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
 * The first setting in an archive's config.json that this installation would
 * refuse, or null when every one is valid. config.php is rebuilt from these values
 * and the router, the translator and every language command read them — a
 * language code becomes part of a translation file's path — so a value no command
 * could have written refuses the whole archive.
 *
 * An archive must name its languages: LANGUAGES_SUPPORTED is the one setting it
 * cannot leave out, checked first. The import never chooses a language for a
 * project — an archive without its list is not one exportProject wrote.
 *
 * Then each setting follows the one rule its writers follow,
 * qs_project_setting_error() in projectSettings.php. And every language the
 * archive brings must be in this installation's language list, as a language
 * addLang adds must be: an import adds languages to the installation's projects.
 *
 * Any other setting that is absent (or null) is not checked: the rebuild gives it
 * its default — the default language is the list's first. A key that is not a
 * setting is not imported, so it is not checked either — an archive's language
 * names included: a project stores codes only.
 *
 * @return array|null ['key' => the setting, 'message' => the rule it breaks]
 */
function importFirstInvalidSetting(array $config): ?array {
    if (!isset($config['LANGUAGES_SUPPORTED'])) {
        return ['key' => 'LANGUAGES_SUPPORTED',
                'message' => "LANGUAGES_SUPPORTED is missing, so the archive names no language. It must list the project's languages, each one in this installation's language list."];
    }
    $bad = qs_project_settings_first_error($config);
    if ($bad !== null) {
        return $bad;
    }
    foreach ($config['LANGUAGES_SUPPORTED'] as $code) {
        if (!qs_language_is_listed($code)) {
            // The setting's rule has already made every entry 2 or 3 lowercase
            // letters, so the code is safe to name.
            return ['key' => 'LANGUAGES_SUPPORTED',
                    'message' => "LANGUAGES_SUPPORTED holds '{$code}', which is not in this installation's language list."];
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
 * First the NAMES, read from the archive's directory before any entry is
 * decompressed. Every entry's name must be a clean relative path — `unsafe_path`
 * otherwise (importEntryNameRefusal()), because only a clean name lands where its
 * spelling says. And the project folder must hold `config.json` — `missing_file`
 * otherwise: it is where an archive names its languages, and the import never
 * chooses them for it.
 *
 * Then every file the site reads (importIsSiteData()) is checked, per entry the
 * first of:
 *   1. `invalid_json` — the entry cannot be read, does not parse, or does not
 *      decode to a JSON array or object; the site could not read it either;
 *   2. `disallowed_content` — the import policy's extension allowlist or the
 *      archive content check refuses it, as it would at extraction;
 * and the root config.json also `invalid_setting` — no language list, a setting
 * the command that writes it would refuse, or a language this installation's
 * language list does not hold (importFirstInvalidSetting()), named in `value`;
 * and a structure file (importStructureKind()) also the first of:
 *   3. `unsafe_value` — an attribute the write gate refuses, naming the node and
 *      the attribute;
 *   4. `blocked_tag` — a tag the renderer refuses;
 *   5. `invalid_component_reference` — a reference the resolver refuses.
 * Every stylesheet (importIsStylesheet()) the import policy allows is checked
 * too, and one that cannot be read or fails the content check — which for a
 * stylesheet includes the scan every stylesheet writer runs — answers
 * `disallowed_content`.
 * Every other entry keeps the extraction's per-entry rule: an asset, a stray file
 * or anything at a hidden path is skipped and reported when refused, while the
 * rest imports.
 *
 * @return array|null the first failure, for qs_unsafe_structure_param_response():
 *                    `file` names the entry and `reason` the check it failed
 */
function importFirstStructureFailure(ZipArchive $zip, string $prefix): ?array {
    $hasConfig = false;
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
        if (!$isDirectory && $relativePath === 'config.json') {
            $hasConfig = true;
        }
    }
    if (!$hasConfig) {
        return ['file' => 'config.json', 'reason' => 'missing_file',
                'message' => 'The archive has no config.json, so it names no language. An archive must carry config.json with LANGUAGES_SUPPORTED listing its languages.'];
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $relativePath = $name === false ? null : importEntryRelativePath($name, $prefix);
        if ($relativePath === null || substr($name, -1) === '/') {
            continue;
        }
        if (importIsStylesheet($relativePath)) {
            // A type the import policy does not allow is skipped at extraction,
            // a stylesheet included; there is nothing to check then.
            if (!qs_import_allows_extension($relativePath)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                return ['file' => $relativePath, 'reason' => 'disallowed_content',
                        'message' => 'The entry cannot be read from the archive (it is damaged or encrypted).'];
            }
            $verdict = qs_import_validate_content($relativePath, $content);
            if (!$verdict['ok']) {
                return ['file' => $relativePath, 'reason' => 'disallowed_content',
                        'message' => ucfirst($verdict['reason']) . '.'];
            }
            continue;
        }
        if (!importIsSiteData($relativePath)) {
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
    
    // 1. Rebuild config.php from config.json. The archive gate refused an archive
    // with no config.json, or one that lists no language, before anything was
    // written; either one here means that gate is broken, and the import then
    // fails whole and is rolled back, like the extraction's second layers.
    $configJsonPath = $projectPath . '/config.json';
    $configJson = is_file($configJsonPath) ? json_decode((string) file_get_contents($configJsonPath), true) : null;
    if (!is_array($configJson)) {
        return ['success' => false, 'error' => 'config.json is missing or not a JSON object'];
    }
    if (!isset($configJson['LANGUAGES_SUPPORTED'])) {
        return ['success' => false, 'error' => 'config.json lists no language'];
    }

    // Validate and filter config keys
    $validConfig = [];
    foreach (QS_PROJECT_SETTING_KEYS as $key) {
        if (isset($configJson[$key])) {
            $validConfig[$key] = $configJson[$key];
        }
    }

    // Defaults for the settings an archive may leave out. Its list has passed its
    // setting's rule in the archive gate — a non-empty list — so its first
    // language is always there to be the default.
    if (!isset($validConfig['SITE_NAME'])) {
        $validConfig['SITE_NAME'] = basename($projectPath);
    }
    if (!isset($validConfig['LANGUAGE_DEFAULT'])) {
        $validConfig['LANGUAGE_DEFAULT'] = $validConfig['LANGUAGES_SUPPORTED'][0];
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
