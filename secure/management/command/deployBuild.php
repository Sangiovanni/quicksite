<?php
/**
 * Deploy Build Command - Copies a build to production paths
 * 
 * Method: POST
 * Endpoint: /management/deployBuild
 * 
 * Parameters:
 * - name: Build folder name (e.g., build_20251213_185955)
 * - publicPath: Absolute path where public folder contents should be copied
 * - securePath: Absolute path where secure folder contents should be copied
 * - overwrite: (optional) If true, overwrite existing files (default: false)
 * 
 * SECURITY NOTE:
 * - This command allows copying to arbitrary paths
 * - The secure folder MUST be outside the web root for security
 * - Protect your API token - anyone with access can deploy anywhere
 * - Path traversal attempts (..) are blocked
 */
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/LockManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

$params = $trimParametersManagement->params();
$buildName = $params['name'] ?? null;
$publicPath = $params['publicPath'] ?? null;
$securePath = $params['securePath'] ?? null;
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

// Validate publicPath
if (empty($publicPath)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('publicPath is required')
        ->withErrors([['field' => 'publicPath', 'reason' => 'missing']])
        ->send();
}

if (!is_string($publicPath)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('publicPath must be a string')
        ->withErrors([['field' => 'publicPath', 'expected' => 'string']])
        ->send();
}

// Validate securePath
if (empty($securePath)) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('securePath is required')
        ->withErrors([['field' => 'securePath', 'reason' => 'missing']])
        ->send();
}

if (!is_string($securePath)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('securePath must be a string')
        ->withErrors([['field' => 'securePath', 'expected' => 'string']])
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
if (strpos($publicPath, '..') !== false || strpos($securePath, '..') !== false) {
    ApiResponse::create(400, 'validation.security_violation')
        ->withMessage('Path traversal is not allowed')
        ->withErrors([
            ['reason' => 'Paths containing ".." are blocked for security']
        ])
        ->send();
}

// Normalize paths (handle both Windows and Unix)
$publicPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicPath), DIRECTORY_SEPARATOR);
$securePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $securePath), DIRECTORY_SEPARATOR);

// Security: Ensure paths are absolute
$isAbsolutePublic = (PHP_OS_FAMILY === 'Windows') 
    ? preg_match('/^[A-Za-z]:/', $publicPath) 
    : (strpos($publicPath, '/') === 0);

$isAbsoluteSecure = (PHP_OS_FAMILY === 'Windows') 
    ? preg_match('/^[A-Za-z]:/', $securePath) 
    : (strpos($securePath, '/') === 0);

if (!$isAbsolutePublic) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('publicPath must be an absolute path')
        ->withErrors([
            ['field' => 'publicPath', 'value' => $publicPath],
            ['example' => PHP_OS_FAMILY === 'Windows' ? 'C:\\wamp64\\www\\mysite' : '/var/www/mysite']
        ])
        ->send();
}

if (!$isAbsoluteSecure) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('securePath must be an absolute path')
        ->withErrors([
            ['field' => 'securePath', 'value' => $securePath],
            ['example' => PHP_OS_FAMILY === 'Windows' ? 'C:\\wamp64\\www\\mysite_app' : '/var/www/mysite_app']
        ])
        ->send();
}

// Security: Public and secure paths must be different
if ($publicPath === $securePath) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('publicPath and securePath must be different')
        ->withErrors([
            ['reason' => 'Deploying both folders to the same location would overwrite files']
        ])
        ->send();
}

// Security: One cannot be inside the other
if (strpos($publicPath, $securePath) === 0 || strpos($securePath, $publicPath) === 0) {
    ApiResponse::create(400, 'validation.security_violation')
        ->withMessage('One deployment path cannot be inside the other')
        ->withErrors([
            ['publicPath' => $publicPath, 'securePath' => $securePath],
            ['reason' => 'Nested deployment paths could cause security issues']
        ])
        ->send();
}

// === BUILD VALIDATION ===

$buildDir = PUBLIC_FOLDER_ROOT . '/build';
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

if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if ($manifest) {
        $buildPublicName = $manifest['public'] ?? null;
        $buildSecureName = $manifest['secure'] ?? null;
    }
}

// If no manifest, scan for folders
if (!$buildPublicName || !$buildSecureName) {
    foreach (scandir($buildFolder) as $item) {
        if ($item === '.' || $item === '..' || !is_dir($buildFolder . '/' . $item)) continue;
        
        // Secure folder has config.php
        if (file_exists($buildFolder . '/' . $item . '/config.php')) {
            $buildSecureName = $item;
        } else {
            $buildPublicName = $item;
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

// === CHECK DESTINATION DIRECTORIES ===

// Check if destination directories exist and are writable
$publicExists = is_dir($publicPath);
$secureExists = is_dir($securePath);

if (!$overwrite) {
    if ($publicExists && count(scandir($publicPath)) > 2) { // More than . and ..
        ApiResponse::create(409, 'conflict.directory_not_empty')
            ->withMessage('Public destination directory is not empty')
            ->withData([
                'path' => $publicPath,
                'hint' => 'Set overwrite=true to replace existing files'
            ])
            ->send();
    }
    
    if ($secureExists && count(scandir($securePath)) > 2) {
        ApiResponse::create(409, 'conflict.directory_not_empty')
            ->withMessage('Secure destination directory is not empty')
            ->withData([
                'path' => $securePath,
                'hint' => 'Set overwrite=true to replace existing files'
            ])
            ->send();
    }
}

// Create directories if they don't exist
if (!$publicExists && !mkdir($publicPath, 0755, true)) {
    ApiResponse::create(500, 'server.directory_create_failed')
        ->withMessage('Failed to create public destination directory')
        ->withData(['path' => $publicPath])
        ->send();
}

if (!$secureExists && !mkdir($securePath, 0755, true)) {
    ApiResponse::create(500, 'server.directory_create_failed')
        ->withMessage('Failed to create secure destination directory')
        ->withData(['path' => $securePath])
        ->send();
}

// Check writability
if (!is_writable($publicPath)) {
    ApiResponse::create(500, 'server.permission_denied')
        ->withMessage('Public destination directory is not writable')
        ->withData(['path' => $publicPath])
        ->send();
}

if (!is_writable($securePath)) {
    ApiResponse::create(500, 'server.permission_denied')
        ->withMessage('Secure destination directory is not writable')
        ->withData(['path' => $securePath])
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

/**
 * Recursively copy a directory
 */
function copyDirectory($source, $dest, $overwrite = false) {
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
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
                mkdir($destPath, 0755, true);
                $copiedDirs++;
            }
        } else {
            if ($overwrite || !file_exists($destPath)) {
                copy($item->getPathname(), $destPath);
                $copiedFiles++;
            }
        }
    }
    
    return ['files' => $copiedFiles, 'directories' => $copiedDirs];
}

// Copy public folder
$publicResult = copyDirectory($sourcePublic, $publicPath, $overwrite);

// Copy secure folder
$secureResult = copyDirectory($sourceSecure, $securePath, $overwrite);

// Copy LICENSE and README to secure folder
$licensePath = $buildFolder . '/LICENSE';
$readmePath = $buildFolder . '/README.txt';
$manifestDestPath = $securePath . '/build_manifest.json';

$extraFiles = [];
if (file_exists($licensePath)) {
    copy($licensePath, $securePath . '/LICENSE');
    $extraFiles[] = 'LICENSE';
}
if (file_exists($readmePath)) {
    copy($readmePath, $securePath . '/README.txt');
    $extraFiles[] = 'README.txt';
}
if (file_exists($manifestPath)) {
    copy($manifestPath, $manifestDestPath);
    $extraFiles[] = 'build_manifest.json';
}

// Release lock
release_deploy_lock();

// === SUCCESS RESPONSE ===
ApiResponse::create(200, 'operation.success')
    ->withMessage('Build deployed successfully')
    ->withData([
        'build' => $buildName,
        'deployed_to' => [
            'public' => $publicPath,
            'secure' => $securePath
        ],
        'source_folders' => [
            'public' => $buildPublicName,
            'secure' => $buildSecureName
        ],
        'public_deployment' => [
            'files_copied' => $publicResult['files'],
            'directories_created' => $publicResult['directories']
        ],
        'secure_deployment' => [
            'files_copied' => $secureResult['files'],
            'directories_created' => $secureResult['directories']
        ],
        'extra_files_copied' => $extraFiles,
        'overwrite_mode' => $overwrite
    ])
    ->send();
