<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Change Favicon Command
 * 
 * Updates the site favicon from an uploaded image in assets/images
 * Requires: imageName (name of image file in assets/images)
 * Supports: PNG format only
 */

$params = $trimParametersManagement->params();

// Get image name from params
$imageName = $params['imageName'] ?? null;

if (empty($imageName)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('imageName parameter is required')
        ->withData([
            'required_fields' => ['imageName']
        ])
        ->send();
}

// Type validation
if (!is_string($imageName)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The imageName parameter must be a string.')
        ->withErrors([
            ['field' => 'imageName', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// Sanitize filename (prevent directory traversal)
$imageName = basename($imageName);

// Check if basename returned empty string
if (empty($imageName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid filename provided')
        ->send();
}

// Length validation (consistent with other file operations)
if (strlen($imageName) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('The image filename must not exceed 100 characters.')
        ->withErrors([
            ['field' => 'imageName', 'value' => $imageName, 'max_length' => 100]
        ])
        ->send();
}

// Validate filename format (only safe characters)
// Pattern: lowercase letters, numbers, hyphens, underscores, and .png extension
if (!RegexPatterns::match('favicon_file', $imageName)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Image filename must contain only letters, numbers, hyphens, underscores, and end with .png')
        ->withErrors([RegexPatterns::validationError('favicon_file', 'imageName', $imageName)])
        ->send();
}

// Validate PNG extension
$extension = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
if ($extension !== 'png') {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Only PNG format is supported for favicon')
        ->withData([
            'provided_extension' => $extension,
            'required_extension' => 'png'
        ])
        ->send();
}

// Check if image exists in assets/images
$imagePath = PUBLIC_FOLDER_ROOT . '/assets/images/' . $imageName;

if (!file_exists($imagePath)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Image file not found in assets/images')
        ->withData([
            'requested_file' => $imageName,
            'expected_path' => '/assets/images/' . $imageName
        ])
        ->send();
}

// Validate it's actually an image
$imageInfo = @getimagesize($imagePath);
if ($imageInfo === false) {
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('File is not a valid image')
        ->withData([
            'file' => $imageName
        ])
        ->send();
}

// Validate it's PNG format (not just extension)
if ($imageInfo[2] !== IMAGETYPE_PNG) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('File must be PNG format (not just extension)')
        ->withData([
            'file' => $imageName,
            'detected_type' => image_type_to_mime_type($imageInfo[2])
        ])
        ->send();
}

// Define favicon path
$faviconPath = PUBLIC_FOLDER_ROOT . '/assets/images/favicon.png';
$backupName = null;

// Check if source and destination are the same file
if (realpath($imagePath) === realpath($faviconPath)) {
    // Already using this image as favicon - nothing to do
    ApiResponse::create(200, 'success.favicon_unchanged')
        ->withMessage('This image is already set as the favicon')
        ->withData([
            'favicon' => 'favicon.png',
            'note' => 'No changes made - file is already the favicon'
        ])
        ->send();
}

// Backup existing favicon if it exists
if (file_exists($faviconPath)) {
    $backupName = 'favicon_backup_' . date('Ymd_His') . '.png';
    $backupPath = PUBLIC_FOLDER_ROOT . '/assets/images/' . $backupName;
    
    if (!rename($faviconPath, $backupPath)) {
        ApiResponse::create(500, 'server.file_operation_failed')
            ->withMessage('Failed to backup existing favicon')
            ->send();
    }
}

// Verify source still exists before copy (defensive check)
if (!file_exists($imagePath)) {
    // Restore backup if it was created
    if ($backupName && file_exists($backupPath)) {
        rename($backupPath, $faviconPath);
    }
    
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Source image no longer exists')
        ->withData([
            'requested_file' => $imageName
        ])
        ->send();
}

// Copy new image as favicon
if (!copy($imagePath, $faviconPath)) {
    // Restore backup if copy failed
    if ($backupName && file_exists(PUBLIC_FOLDER_ROOT . '/assets/images/' . $backupName)) {
        rename(PUBLIC_FOLDER_ROOT . '/assets/images/' . $backupName, $faviconPath);
    }
    
    ApiResponse::create(500, 'server.file_operation_failed')
        ->withMessage('Failed to update favicon')
        ->send();
}

// Success response
ApiResponse::create(200, 'success.favicon_updated')
    ->withMessage('Favicon updated successfully')
    ->withData([
        'new_favicon' => 'favicon.png',
        'source_image' => $imageName,
        'backup_created' => $backupName,
        'favicon_url' => '/assets/images/favicon.png'
    ])
    ->send();