<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Upload Asset Command
 * 
 * Uploads files to assets folders with category-specific validation
 * Category passed via POST/multipart form data, file via $_FILES
 * Optional description for AI context
 */

// Get parameters from POST/JSON (handles multipart form-data)
$params = $trimParametersManagement->params();
$category = $params['category'] ?? null;
$description = $params['description'] ?? null;

// Validate category is provided
if (empty($category)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('category parameter is required')
        ->withData([
            'required_fields' => ['category']
        ])
        ->send();
}

// Type validation - category must be string
if (!is_string($category)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The category parameter must be a string.')
        ->withErrors([
            ['field' => 'category', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// Validate category against whitelist
$validCategories = require SECURE_FOLDER_PATH . '/management/config/assetCategories.php';

// Dynamic length validation based on longest valid category
$maxCategoryLength = max(array_map('strlen', $validCategories));
if (strlen($category) > $maxCategoryLength) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage("The category parameter must not exceed {$maxCategoryLength} characters.")
        ->withErrors([
            ['field' => 'category', 'value' => $category, 'max_length' => $maxCategoryLength]
        ])
        ->send();
}

// Check if category is in whitelist
if (!in_array($category, $validCategories, true)) {
    ApiResponse::create(400, 'validation.invalid_value')
        ->withMessage("Category must be one of: " . implode(', ', $validCategories))
        ->withErrors([
            ['field' => 'category', 'value' => $category, 'allowed' => $validCategories]
        ])
        ->send();
}

// Validate description if provided (optional parameter)
if ($description !== null) {
    if (!is_string($description)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The description parameter must be a string.')
            ->withErrors([
                ['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }
    
    // Limit description to 500 characters
    if (strlen($description) > 500) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The description parameter must not exceed 500 characters.')
            ->withErrors([
                ['field' => 'description', 'max_length' => 500, 'actual_length' => strlen($description)]
            ])
            ->send();
    }
    
    // Trim the description
    $description = trim($description);
}

// Check if file was uploaded
if (!isset($_FILES['file'])) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('No file uploaded')
        ->withData([
            'required_fields' => ['file']
        ])
        ->send();
}

$file = $_FILES['file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
    ];
    
    $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
    
    ApiResponse::create(400, 'asset.upload_failed')
        ->withMessage($errorMsg)
        ->withData([
            'error_code' => $file['error']
        ])
        ->send();
}

// Validate file has a name
if (empty($file['name']) || !is_string($file['name'])) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Invalid or missing filename')
        ->send();
}

// Validate tmp_name exists and is uploaded file
if (!is_uploaded_file($file['tmp_name'])) {
    ApiResponse::create(400, 'asset.invalid_upload')
        ->withMessage('File was not uploaded via HTTP POST')
        ->send();
}

// Validate file size (category-specific limits)
$sizeLimits = [
    'images' => 5 * 1024 * 1024,    // 5MB
    'scripts' => 1 * 1024 * 1024,   // 1MB
    'font' => 2 * 1024 * 1024,      // 2MB
    'audio' => 10 * 1024 * 1024,    // 10MB
    'videos' => 50 * 1024 * 1024    // 50MB
];

if ($file['size'] > $sizeLimits[$category]) {
    ApiResponse::create(400, 'asset.file_too_large')
        ->withMessage("File exceeds maximum size of " . ($sizeLimits[$category] / 1024 / 1024) . "MB for category '{$category}'")
        ->withData([
            'max_size_mb' => $sizeLimits[$category] / 1024 / 1024,
            'actual_size_mb' => round($file['size'] / 1024 / 1024, 2)
        ])
        ->send();
}

// Validate MIME type based on category (detect actual content, not just extension)
$allowedMimes = [
    'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
    'scripts' => ['application/javascript', 'text/javascript', 'application/x-javascript', 'text/plain'], // text/plain for .js sometimes
    'font' => ['font/ttf', 'font/otf', 'font/woff', 'font/woff2', 'application/x-font-ttf', 'application/x-font-otf', 'application/font-woff', 'application/font-woff2', 'application/octet-stream'], // fonts often detected as octet-stream
    'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-wav'],
    'videos' => ['video/mp4', 'video/webm', 'video/ogg']
];

// Get actual MIME type (server-side detection, not client-provided)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Validate MIME type detected
if ($mimeType === false || empty($mimeType)) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Could not determine file type')
        ->send();
}

if (!in_array($mimeType, $allowedMimes[$category], true)) {
    ApiResponse::create(400, 'validation.invalid_mime_type')
        ->withMessage("File type '{$mimeType}' not allowed for category '{$category}'")
        ->withData([
            'detected_mime' => $mimeType,
            'allowed_mimes' => $allowedMimes[$category]
        ])
        ->send();
}

// Sanitize and validate filename
$originalName = basename($file['name']); // Remove any path components

// Additional security: check for empty filename after basename
if (empty($originalName)) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Invalid filename provided')
        ->send();
}

// Extract filename components safely
$pathInfo = pathinfo($originalName);
$extension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : '';
$filenameOnly = $pathInfo['filename'] ?? '';

// Validate filename is not empty
if (empty($filenameOnly)) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Filename cannot be empty')
        ->send();
}

// Sanitize filename - only alphanumeric, hyphens, underscores
// This prevents: path traversal, special chars, unicode attacks
$basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filenameOnly);

// Check if sanitization resulted in empty string
if (empty($basename)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename contains only invalid characters')
        ->send();
}

// Enforce 100 character limit for filename (consistent with other commands)
if (strlen($basename . '.' . $extension) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Filename must not exceed 100 characters.')
        ->withErrors([
            ['field' => 'filename', 'max_length' => 100, 'actual_length' => strlen($basename . '.' . $extension)]
        ])
        ->send();
}

// Validate extension exists and is not empty
if (empty($extension)) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('File must have a valid extension')
        ->send();
}

// Validate extension matches category BEFORE checking MIME
// This prevents uploading .php files even if they somehow pass MIME checks
$validExtensions = [
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
    'scripts' => ['js'],
    'font' => ['ttf', 'otf', 'woff', 'woff2'],
    'audio' => ['mp3', 'wav', 'ogg'],
    'videos' => ['mp4', 'webm', 'ogv']
];

if (!in_array($extension, $validExtensions[$category], true)) {
    ApiResponse::create(400, 'validation.invalid_extension')
        ->withMessage("File extension '.{$extension}' not allowed for category '{$category}'")
        ->withData([
            'extension' => $extension,
            'allowed_extensions' => $validExtensions[$category]
        ])
        ->send();
}

// Additional security: block dangerous extensions regardless of category
$dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'htaccess'];
if (in_array($extension, $dangerousExtensions, true)) {
    ApiResponse::create(400, 'validation.forbidden_extension')
        ->withMessage('Executable file types are not allowed')
        ->withData([
            'extension' => $extension
        ])
        ->send();
}

// Build target path (use PUBLIC_FOLDER_ROOT not PUBLIC_FOLDER_PATH)
$targetDir = PUBLIC_FOLDER_ROOT . '/assets/' . $category . '/';

// Validate target directory exists
if (!is_dir($targetDir)) {
    ApiResponse::create(500, 'server.directory_not_found')
        ->withMessage("Target directory does not exist: /assets/{$category}/")
        ->send();
}

// Validate target directory is writable
if (!is_writable($targetDir)) {
    ApiResponse::create(500, 'server.permission_denied')
        ->withMessage("Target directory is not writable")
        ->send();
}

$targetFile = $targetDir . $basename . '.' . $extension;

// Check if file already exists and generate unique name
$finalFilename = $basename . '.' . $extension;
$counter = 1;

// Limit counter to prevent infinite loops
while (file_exists($targetFile) && $counter < 1000) {
    $finalFilename = $basename . '_' . $counter . '.' . $extension;
    $targetFile = $targetDir . $finalFilename;
    
    // Re-check 100 char limit with counter
    if (strlen($finalFilename) > 100) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Filename with uniqueness counter exceeds 100 characters. Please use a shorter filename.')
            ->withErrors([
                ['field' => 'filename', 'max_length' => 100, 'actual_length' => strlen($finalFilename)]
            ])
            ->send();
    }
    
    $counter++;
}

// Safeguard: if we hit 1000 duplicates, something is wrong
if ($counter >= 1000) {
    ApiResponse::create(500, 'server.too_many_duplicates')
        ->withMessage('Unable to generate unique filename after 1000 attempts')
        ->send();
}

// Move uploaded file to destination
// Use move_uploaded_file() which validates it's an actual uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
    ApiResponse::create(500, 'server.file_move_failed')
        ->withMessage("Failed to move uploaded file to destination")
        ->withData([
            'target' => '/assets/' . $category . '/' . $finalFilename
        ])
        ->send();
}

// Verify file was actually created and has correct size
if (!file_exists($targetFile)) {
    ApiResponse::create(500, 'server.file_verification_failed')
        ->withMessage("File upload failed: file not found after move")
        ->send();
}

$actualSize = filesize($targetFile);
if ($actualSize !== $file['size']) {
    // File size mismatch - possible corruption, delete it
    @unlink($targetFile);
    ApiResponse::create(500, 'server.file_corrupted')
        ->withMessage("File upload failed: size mismatch (possible corruption)")
        ->withData([
            'expected_size' => $file['size'],
            'actual_size' => $actualSize
        ])
        ->send();
}

// ============================================================
// Store Asset Metadata
// ============================================================
$metadataPath = PROJECT_PATH . '/data/assets_metadata.json';
$assetKey = $category . '/' . $finalFilename;
$metadata = [];

// Load existing metadata
if (file_exists($metadataPath)) {
    $metadataContent = file_get_contents($metadataPath);
    $metadata = json_decode($metadataContent, true) ?: [];
}

// Build metadata for this asset
$assetMeta = [
    'uploaded' => date('c'), // ISO 8601 format
    'mime_type' => $mimeType,
    'size' => $file['size']
];

// Add description if provided
if (!empty($description)) {
    $assetMeta['description'] = $description;
}

// Auto-detect dimensions for images
if ($category === 'images') {
    $imageInfo = @getimagesize($targetFile);
    if ($imageInfo !== false) {
        $assetMeta['width'] = $imageInfo[0];
        $assetMeta['height'] = $imageInfo[1];
        $assetMeta['dimensions'] = $imageInfo[0] . 'x' . $imageInfo[1];
    }
}

// Auto-detect duration for audio/video (if possible)
// Note: This requires additional libraries, so we'll skip for now

// Store metadata
$metadata[$assetKey] = $assetMeta;

// Save metadata file
file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Build response data
$responseData = [
    'filename' => $finalFilename,
    'category' => $category,
    'path' => '/assets/' . $category . '/' . $finalFilename,
    'size' => $file['size'],
    'mime_type' => $mimeType
];

// Include dimensions in response for images
if (isset($assetMeta['dimensions'])) {
    $responseData['dimensions'] = $assetMeta['dimensions'];
}

// Include description if provided
if (!empty($description)) {
    $responseData['description'] = $description;
}

// Success response
ApiResponse::create(201, 'operation.success')
    ->withMessage("File uploaded successfully")
    ->withData($responseData)
    ->send();