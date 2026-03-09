<?php
/**
 * Deploy Build Command - Copies a build to a target root directory
 * 
 * Method: POST
 * Endpoint: /management/deployBuild
 * 
 * Parameters:
 * - name: Build folder name (e.g., build_20251213_185955)
 * - targetPath: Absolute path to the root directory where the build will be deployed
 *               The build's public and secure folders will be placed inside this path.
 *               Example: /var/www/mysite -> creates /var/www/mysite/{publicFolder}/ and /var/www/mysite/{secureFolder}/
 * - overwrite: (optional) If true, overwrite existing files (default: false)
 *              When false, the command scans for file conflicts first and returns them.
 * 
 * SECURITY NOTE:
 * - This command allows copying to arbitrary paths on the filesystem
 * - Protect your API token - anyone with access can deploy anywhere the PHP process can write
 * - Path traversal attempts (..) are blocked
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$params = $trimParametersManagement->params();
$buildName = $params['name'] ?? null;
$targetPath = $params['targetPath'] ?? null;
$overwrite = $params['overwrite'] ?? false;

// === VALIDATION ===

// Validate build name
if (empty($buildName)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Build name is required')
        ->withErrors([['field' => 'name', 'reason' => 'missing']])
        ->send();
}

if (!is_string($buildName) || !RegexPatterns::match('build_name', $buildName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid build name format')
        ->withErrors([RegexPatterns::validationError('build_name', 'name', $buildName ?? '')])
        ->send();
}

// Validate targetPath
if (empty($targetPath)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('targetPath is required')
        ->withErrors([['field' => 'targetPath', 'reason' => 'missing']])
        ->send();
}

if (!is_string($targetPath)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('targetPath must be a string')
        ->withErrors([['field' => 'targetPath', 'expected' => 'string']])
        ->send();
}

// Validate overwrite is boolean
if (isset($params['overwrite']) && !is_bool($overwrite)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('overwrite must be a boolean')
        ->withErrors([['field' => 'overwrite', 'expected' => 'boolean']])
        ->send();
}

// Security: Block path traversal attempts
if (strpos($targetPath, '..') !== false) {
    ApiResponse::create(400, 'validation.security_violation')
        ->withMessage('Path traversal is not allowed')
        ->withErrors([
            ['reason' => 'Paths containing ".." are blocked for security']
        ])
        ->send();
}

// Normalize path (handle both Windows and Unix)
$targetPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPath), DIRECTORY_SEPARATOR);

// Security: Ensure path is absolute
$isAbsolute = (PHP_OS_FAMILY === 'Windows')
    ? preg_match('/^[A-Za-z]:/', $targetPath)
    : (strpos($targetPath, '/') === 0);

if (!$isAbsolute) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('targetPath must be an absolute path')
        ->withErrors([
            ['field' => 'targetPath', 'value' => $targetPath],
            ['example' => PHP_OS_FAMILY === 'Windows' ? 'C:\\wamp64\\www\\mysite' : '/var/www/mysite']
        ])
        ->send();
}

// === BUILD VALIDATION ===

// Check that no build is currently in progress (would mean files are incomplete)
if (isLocked('build')) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage('A build operation is currently in progress. Wait for it to complete before deploying.')
        ->send();
}

$buildDir = PUBLIC_CONTENT_PATH . '/build';
$buildFolder = $buildDir . '/' . $buildName;

if (!is_dir($buildFolder)) {
    ApiResponse::create(404, 'build.not_found')
        ->withMessage('Build not found')
        ->withData(['requested_build' => $buildName])
        ->send();
}

// Read manifest to get folder names
$manifestPath = $buildFolder . '/build_manifest.json';
$buildPublicName = null;
$buildSecureName = null;
$buildSpace = null;

if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if ($manifest) {
        $buildPublicName = $manifest['public'] ?? null;
        $buildSecureName = $manifest['secure'] ?? null;
        $buildSpace = $manifest['space'] ?? '';
    }
}

// If no manifest, scan for folders (handles nested secure paths like "secure/business")
if (!$buildPublicName || !$buildSecureName) {
    $foundDirs = [];
    foreach (scandir($buildFolder) as $item) {
        if ($item === '.' || $item === '..' || !is_dir($buildFolder . '/' . $item)) continue;
        $foundDirs[] = $item;
    }
    
    // Try to identify secure folder by recursively searching for config.php
    foreach ($foundDirs as $dir) {
        $dirPath = $buildFolder . '/' . $dir;
        // Direct config.php (flat secure folder like "backend/config.php")
        if (file_exists($dirPath . '/config.php')) {
            $buildSecureName = $dir;
        } else {
            // Check one level deeper for nested secure paths (e.g., "secure/business/config.php")
            foreach (scandir($dirPath) as $sub) {
                if ($sub === '.' || $sub === '..') continue;
                if (is_dir($dirPath . '/' . $sub) && file_exists($dirPath . '/' . $sub . '/config.php')) {
                    $buildSecureName = $dir . '/' . $sub;
                    break;
                }
            }
        }
    }
    
    // Public folder is whichever top-level dir is NOT the secure root
    $secureRoot = $buildSecureName ? explode('/', $buildSecureName)[0] : null;
    foreach ($foundDirs as $dir) {
        if ($dir !== $secureRoot) {
            $buildPublicName = $dir;
            break;
        }
    }
}

if (!$buildPublicName || !$buildSecureName) {
    ApiResponse::create(500, 'build.invalid_structure')
        ->withMessage('Could not identify public and secure folders in build')
        ->withData(['build' => $buildName])
        ->send();
}

$sourcePublic = $buildFolder . '/' . $buildPublicName;
$sourceSecure = $buildFolder . '/' . $buildSecureName;

if (!is_dir($sourcePublic)) {
    ApiResponse::create(500, 'build.missing_folder')
        ->withMessage('Public folder not found in build')
        ->withData(['expected' => $buildPublicName])
        ->send();
}

if (!is_dir($sourceSecure)) {
    ApiResponse::create(500, 'build.missing_folder')
        ->withMessage('Secure folder not found in build')
        ->withData(['expected' => $buildSecureName])
        ->send();
}

// Determine destination paths
$destPublic = $targetPath . DIRECTORY_SEPARATOR . $buildPublicName;
$destSecure = $targetPath . DIRECTORY_SEPARATOR . $buildSecureName;

// === SAFETY CHECK: Warn when deploying without space to existing multi-project directory ===
$spaceWarning = null;
if (empty($buildSpace) && is_dir($destPublic)) {
    // Check if the destination public dir already has subdirectories (indicating multi-project / space usage)
    $existingSubdirs = [];
    foreach (scandir($destPublic) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir($destPublic . DIRECTORY_SEPARATOR . $item) && $item !== 'build') {
            $existingSubdirs[] = $item;
        }
    }
    if (count($existingSubdirs) > 0) {
        $spaceWarning = 'This build has no "space" parameter — public files will be placed directly in ' 
            . $buildPublicName . '/ root alongside existing subdirectories: ' . implode(', ', $existingSubdirs) 
            . '. If this is a multi-project setup, rebuild with space=<projectname> to place files in their own subdirectory.';
    }
}

// === FILE CONFLICT DETECTION ===

/**
 * Scan source directory and find files that already exist at destination.
 * Returns array of relative paths that would be overwritten.
 */
function findConflicts(string $source, string $dest): array {
    $conflicts = [];
    if (!is_dir($dest)) return $conflicts;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $item) {
        $relativePath = $iterator->getSubPathname();
        $destFile = $dest . DIRECTORY_SEPARATOR . $relativePath;
        if (file_exists($destFile)) {
            $conflicts[] = $relativePath;
        }
    }
    
    return $conflicts;
}

// Check for file conflicts in public/secure directories
$publicConflicts = findConflicts($sourcePublic, $destPublic);
$secureConflicts = findConflicts($sourceSecure, $destSecure);
$totalConflicts = count($publicConflicts) + count($secureConflicts);

if ($totalConflicts > 0 && !$overwrite) {
    $conflictData = [
        'total_conflicts' => $totalConflicts,
        'public_conflicts' => [
            'folder' => $buildPublicName,
            'count' => count($publicConflicts),
            'files' => array_slice($publicConflicts, 0, 50)
        ],
        'secure_conflicts' => [
            'folder' => $buildSecureName,
            'count' => count($secureConflicts),
            'files' => array_slice($secureConflicts, 0, 50)
        ],
        'space' => $buildSpace ?: '(none — files at public root)',
        'hint' => 'Set overwrite=true to replace existing files'
    ];
    if ($spaceWarning) {
        $conflictData['warning'] = $spaceWarning;
    }
    ApiResponse::create(409, 'conflict.files_exist')
        ->withMessage("Found {$totalConflicts} file(s) that would be overwritten")
        ->withData($conflictData)
        ->send();
}

// === CHECK/CREATE TARGET DIRECTORY ===

if (!is_dir($targetPath)) {
    if (!mkdir($targetPath, 0755, true)) {
        ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage('Failed to create target directory')
            ->withData(['path' => $targetPath])
            ->send();
    }
}

if (!is_writable($targetPath)) {
    ApiResponse::create(500, 'server.permission_denied')
        ->withMessage('Target directory is not writable')
        ->withData(['path' => $targetPath])
        ->send();
}

// === ACQUIRE LOCK ===
$lock = acquireLock('deploy');

if (!$lock) {
    ApiResponse::create(409, 'conflict.operation_in_progress')
        ->withMessage('Another deployment is in progress')
        ->send();
}

// Helper to release lock on error
function release_deploy_lock() {
    global $lock;
    if ($lock) releaseLock($lock);
}

// === COPY FILES ===

// Track all created files and directories for rollback on failure
$createdFiles = [];
$createdDirs = [];

/**
 * Recursively copy a directory, tracking all created items for rollback
 */
function copyDirectory(string $source, string $dest, bool $overwrite, array &$createdFiles, array &$createdDirs): array {
    if (!is_dir($dest)) {
        if (!mkdir($dest, 0755, true)) {
            return ['files' => 0, 'directories' => 0, 'error' => "Failed to create directory: {$dest}"];
        }
        $createdDirs[] = $dest;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $copiedFiles = 0;
    $copiedDirs = 0;
    
    foreach ($iterator as $item) {
        $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
        
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                if (!mkdir($destPath, 0755, true)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'error' => "Failed to create directory: {$destPath}"];
                }
                $createdDirs[] = $destPath;
                $copiedDirs++;
            }
        } else {
            $fileExisted = file_exists($destPath);
            if ($overwrite || !$fileExisted) {
                if (!copy($item->getPathname(), $destPath)) {
                    return ['files' => $copiedFiles, 'directories' => $copiedDirs, 'error' => "Failed to copy file: {$destPath}"];
                }
                // Only track for rollback if we created a new file (not overwrote)
                if (!$fileExisted) {
                    $createdFiles[] = $destPath;
                }
                $copiedFiles++;
            }
        }
    }
    
    return ['files' => $copiedFiles, 'directories' => $copiedDirs];
}

/**
 * Attempt rollback: remove all NEW files and directories created during deployment.
 * Does not touch files that were overwritten (they are already changed).
 */
function rollbackDeployment(array $createdFiles, array $createdDirs): array {
    $rollbackErrors = [];
    
    // Delete files first (in reverse order)
    foreach (array_reverse($createdFiles) as $file) {
        if (file_exists($file) && !@unlink($file)) {
            $rollbackErrors[] = "Could not remove file: {$file}";
        }
    }
    
    // Delete directories in reverse order (deepest first)
    foreach (array_reverse($createdDirs) as $dir) {
        if (is_dir($dir)) {
            if (count(scandir($dir)) <= 2) {
                if (!@rmdir($dir)) {
                    $rollbackErrors[] = "Could not remove directory: {$dir}";
                }
            } else {
                $rollbackErrors[] = "Directory not empty after file cleanup: {$dir}";
            }
        }
    }
    
    return $rollbackErrors;
}

// Copy public folder
$publicResult = copyDirectory($sourcePublic, $destPublic, $overwrite, $createdFiles, $createdDirs);

if (isset($publicResult['error'])) {
    $rollbackErrors = rollbackDeployment($createdFiles, $createdDirs);
    release_deploy_lock();
    ApiResponse::create(500, 'deploy.copy_failed')
        ->withMessage('Deployment failed while copying public folder')
        ->withData([
            'error' => $publicResult['error'],
            'files_copied_before_failure' => $publicResult['files'],
            'rollback_attempted' => true,
            'rollback_complete' => empty($rollbackErrors),
            'rollback_errors' => $rollbackErrors ?: null
        ])
        ->send();
}

// Copy secure folder
$secureResult = copyDirectory($sourceSecure, $destSecure, $overwrite, $createdFiles, $createdDirs);

if (isset($secureResult['error'])) {
    $rollbackErrors = rollbackDeployment($createdFiles, $createdDirs);
    release_deploy_lock();
    ApiResponse::create(500, 'deploy.copy_failed')
        ->withMessage('Deployment failed while copying secure folder')
        ->withData([
            'error' => $secureResult['error'],
            'files_copied_before_failure' => $publicResult['files'] + $secureResult['files'],
            'rollback_attempted' => true,
            'rollback_complete' => empty($rollbackErrors),
            'rollback_errors' => $rollbackErrors ?: null
        ])
        ->send();
}

// Copy LICENSE to target (always overwrite silently — same license file)
$licenseCopied = false;
$licenseSource = $buildFolder . '/LICENSE';
$licenseDest = $targetPath . DIRECTORY_SEPARATOR . 'LICENSE';
if (file_exists($licenseSource)) {
    $fileExisted = file_exists($licenseDest);
    if (copy($licenseSource, $licenseDest)) {
        if (!$fileExisted) {
            $createdFiles[] = $licenseDest;
        }
        $licenseCopied = true;
    }
}

// Release lock
release_deploy_lock();

// === SUCCESS RESPONSE ===
$responseData = [
    'build' => $buildName,
    'target' => $targetPath,
    'folders' => [
        'public' => $buildPublicName,
        'secure' => $buildSecureName,
        'space' => $buildSpace ?: '(none — files at public root)'
    ],
    'deployed_paths' => [
        'public' => $destPublic,
        'secure' => $destSecure
    ],
    'public_deployment' => [
        'files_copied' => $publicResult['files'],
        'directories_created' => $publicResult['directories']
    ],
    'secure_deployment' => [
        'files_copied' => $secureResult['files'],
        'directories_created' => $secureResult['directories']
    ],
    'license_copied' => $licenseCopied,
    'overwrite_mode' => $overwrite,
    'files_overwritten' => $overwrite ? $totalConflicts : 0
];

if ($spaceWarning) {
    $responseData['warning'] = $spaceWarning;
}

ApiResponse::create(200, 'operation.success')
    ->withMessage('Build deployed successfully')
    ->withData($responseData)
    ->send();
