<?php
/**
 * importProject Command
 * 
 * Imports a project from an uploaded ZIP file.
 * Can be called via API or internally from admin panel.
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

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_importProject(array $params = [], array $urlParams = []): ApiResponse {
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
            return ApiResponse::create(400, 'upload.failed')
                ->withMessage('File upload failed')
                ->withData(['error_code' => $file['error'], 'error' => getUploadErrorMessage($file['error'])]);
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
    // Method 2: Check for file path in params (for internal calls)
    elseif (!empty($params['file_path']) && file_exists($params['file_path'])) {
        $zipPath = $params['file_path'];
        $uploadedFile = basename($params['file_path']);
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
    
    // Find project folder in ZIP
    $projectFolder = findProjectFolderInZip($zip);
    
    if ($projectFolder === null) {
        $zip->close();
        return ApiResponse::create(400, 'validation.invalid_structure')
            ->withMessage('Invalid project structure in ZIP')
            ->withErrors(['structure' => 'ZIP must contain a project folder with config.php or routes.php']);
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
    
    // Create project directory
    if (!mkdir($projectPath, 0755, true)) {
        $zip->close();
        return ApiResponse::create(500, 'server.directory_create_failed')
            ->withMessage('Failed to create project directory');
    }
    
    // Extract files
    $stats = ['files' => 0, 'directories' => 0, 'total_size' => 0];
    $extractResult = extractProjectFromZip($zip, $projectFolder['prefix'], $projectPath, $stats);
    
    $zip->close();
    
    if (!$extractResult['success']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(500, 'server.extract_failed')
            ->withMessage('Failed to extract project files')
            ->withData(['error' => $extractResult['error']]);
    }
    
    // Validate extracted project has required files
    $validation = validateImportedProject($projectPath);
    
    if (!$validation['valid']) {
        deleteImportDirectory($projectPath);
        return ApiResponse::create(400, 'validation.incomplete_project')
            ->withMessage('Imported project is incomplete')
            ->withErrors($validation['errors']);
    }
    
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
        'switched_to' => false
    ];
    
    // Switch to project if requested
    if ($switchTo) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($projectName) . "'\n];\n";
        
        if (file_put_contents($targetFile, $targetContent, LOCK_EX) !== false) {
            $result['switched_to'] = true;
        }
    }
    
    return ApiResponse::create(201, 'resource.imported')
        ->withMessage("Project '$projectName' imported successfully")
        ->withData($result);
}

/**
 * Find project folder in ZIP archive
 */
function findProjectFolderInZip(ZipArchive $zip): ?array {
    $candidates = [];
    
    // Look for config.php or routes.php at various levels
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = explode('/', $name);
        
        // Check for project indicators
        $filename = basename($name);
        if ($filename === 'config.php' || $filename === 'routes.php') {
            // Determine project folder
            if (count($parts) === 1) {
                // Root level - project files at root
                return ['name' => 'imported_project', 'prefix' => ''];
            } elseif (count($parts) === 2) {
                // One level deep - standard structure
                return ['name' => $parts[0], 'prefix' => $parts[0] . '/'];
            }
        }
    }
    
    return null;
}

/**
 * Extract project from ZIP
 */
function extractProjectFromZip(ZipArchive $zip, string $prefix, string $destPath, array &$stats): array {
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
        
        $destFilePath = $destPath . '/' . $relativePath;
        
        // If directory (ends with /)
        if (substr($name, -1) === '/') {
            if (!is_dir($destFilePath) && !mkdir($destFilePath, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create directory: $relativePath"];
            }
            $stats['directories']++;
        } else {
            // Ensure parent directory exists
            $parentDir = dirname($destFilePath);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create parent directory for: $relativePath"];
            }
            
            // Extract file
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                return ['success' => false, 'error' => "Failed to read file from ZIP: $relativePath"];
            }
            
            if (file_put_contents($destFilePath, $content) === false) {
                return ['success' => false, 'error' => "Failed to write file: $relativePath"];
            }
            
            $stats['files']++;
            $stats['total_size'] += strlen($content);
        }
    }
    
    return ['success' => true];
}

/**
 * Validate imported project structure
 */
function validateImportedProject(string $projectPath): array {
    $errors = [];
    
    // Check for config.php or routes.php (at least one required)
    $hasConfig = file_exists($projectPath . '/config.php');
    $hasRoutes = file_exists($projectPath . '/routes.php');
    
    if (!$hasConfig && !$hasRoutes) {
        $errors['structure'] = 'Project must have config.php or routes.php';
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
    
    // Count routes
    if (file_exists($projectPath . '/routes.php')) {
        $routes = require $projectPath . '/routes.php';
        $info['routes_count'] = count($routes);
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