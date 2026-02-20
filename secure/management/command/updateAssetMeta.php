<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Update Asset Metadata Command
 * 
 * Updates metadata (description, alt text) for an existing asset
 * 
 * Parameters:
 * - category (required): Asset category (images, scripts, etc.)
 * - filename (required): Name of the file
 * - description (optional): Description of the asset for AI context
 * - alt (optional): Alt text for images (accessibility)
 */

$params = $trimParametersManagement->params();

// Required parameters
$category = $params['category'] ?? null;
$filename = $params['filename'] ?? null;

// Optional parameters
$description = $params['description'] ?? null;
$alt = $params['alt'] ?? null;

// Validate required parameters
if (empty($category)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('category parameter is required')
        ->withData(['required_fields' => ['category', 'filename']])
        ->send();
}

if (empty($filename)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('filename parameter is required')
        ->withData(['required_fields' => ['category', 'filename']])
        ->send();
}

// Type validation
if (!is_string($category)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The category parameter must be a string.')
        ->withErrors([['field' => 'category', 'reason' => 'invalid_type', 'expected' => 'string']])
        ->send();
}

if (!is_string($filename)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The filename parameter must be a string.')
        ->withErrors([['field' => 'filename', 'reason' => 'invalid_type', 'expected' => 'string']])
        ->send();
}

// Validate category against whitelist
$validCategories = require SECURE_FOLDER_PATH . '/management/config/assetCategories.php';

if (!in_array($category, $validCategories, true)) {
    ApiResponse::create(400, 'validation.invalid_value')
        ->withMessage("Category must be one of: " . implode(', ', $validCategories))
        ->withErrors([['field' => 'category', 'value' => $category, 'allowed' => $validCategories]])
        ->send();
}

// Sanitize filename to prevent path traversal
$filename = basename($filename);

// Check file exists
$filePath = PUBLIC_FOLDER_ROOT . '/assets/' . $category . '/' . $filename;
if (!file_exists($filePath) || !is_file($filePath)) {
    ApiResponse::create(404, 'asset.not_found')
        ->withMessage("Asset not found: {$category}/{$filename}")
        ->withData(['category' => $category, 'filename' => $filename])
        ->send();
}

// Validate description if provided
if ($description !== null) {
    if (!is_string($description)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The description parameter must be a string.')
            ->withErrors([['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }
    
    if (strlen($description) > 500) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The description parameter must not exceed 500 characters.')
            ->withErrors([['field' => 'description', 'max_length' => 500, 'actual_length' => strlen($description)]])
            ->send();
    }
    
    $description = trim($description);
}

// Validate alt if provided
if ($alt !== null) {
    if (!is_string($alt)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The alt parameter must be a string.')
            ->withErrors([['field' => 'alt', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }
    
    if (strlen($alt) > 250) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The alt parameter must not exceed 250 characters.')
            ->withErrors([['field' => 'alt', 'max_length' => 250, 'actual_length' => strlen($alt)]])
            ->send();
    }
    
    $alt = trim($alt);
}

// At least one update field must be provided
if ($description === null && $alt === null) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('At least one of description or alt must be provided')
        ->withData(['optional_fields' => ['description', 'alt']])
        ->send();
}

// Load metadata file
$metadataPath = PROJECT_PATH . '/data/assets_metadata.json';
$metadata = [];
if (file_exists($metadataPath)) {
    $metadataContent = file_get_contents($metadataPath);
    $metadata = json_decode($metadataContent, true) ?: [];
}

// Build asset key
$assetKey = $category . '/' . $filename;

// Get or create metadata entry for this asset
if (!isset($metadata[$assetKey])) {
    // Asset doesn't have metadata yet - create entry with basic info
    $metadata[$assetKey] = [
        'size' => filesize($filePath),
        'uploaded' => date('c', filemtime($filePath)) // Use file modified time as fallback
    ];
    
    // Detect MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    if ($mimeType) {
        $metadata[$assetKey]['mime_type'] = $mimeType;
    }
    
    // Detect image dimensions
    if ($category === 'images') {
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo !== false) {
            $metadata[$assetKey]['width'] = $imageInfo[0];
            $metadata[$assetKey]['height'] = $imageInfo[1];
            $metadata[$assetKey]['dimensions'] = $imageInfo[0] . 'x' . $imageInfo[1];
        }
    }
}

// Update metadata fields
$updated = [];

if ($description !== null) {
    if (empty($description)) {
        // Empty string removes description
        unset($metadata[$assetKey]['description']);
        $updated[] = 'description removed';
    } else {
        $metadata[$assetKey]['description'] = $description;
        $updated[] = 'description';
    }
}

if ($alt !== null) {
    if (empty($alt)) {
        // Empty string removes alt
        unset($metadata[$assetKey]['alt']);
        $updated[] = 'alt removed';
    } else {
        $metadata[$assetKey]['alt'] = $alt;
        $updated[] = 'alt';
    }
}

// Record last modified
$metadata[$assetKey]['meta_updated'] = date('c');

// Save metadata file
$saved = file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if ($saved === false) {
    ApiResponse::create(500, 'server.write_failed')
        ->withMessage('Failed to save metadata file')
        ->send();
}

// Success response
ApiResponse::create(200, 'operation.success')
    ->withMessage("Asset metadata updated: " . implode(', ', $updated))
    ->withData([
        'category' => $category,
        'filename' => $filename,
        'path' => '/assets/' . $category . '/' . $filename,
        'metadata' => $metadata[$assetKey],
        'updated_fields' => $updated
    ])
    ->send();
