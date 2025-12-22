<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Delete Asset Command
 * 
 * Deletes a file from assets folders
 * Parameters: category (string), filename (string)
 */

// Get parameters from POST/JSON
$params = $trimParametersManagement->params();
$category = $params['category'] ?? null;
$filename = $params['filename'] ?? null;

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

// Validate filename is provided
if (empty($filename)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('filename parameter is required')
        ->withData([
            'required_fields' => ['filename']
        ])
        ->send();
}

// Type validation - filename must be string
if (!is_string($filename)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The filename parameter must be a string.')
        ->withErrors([
            ['field' => 'filename', 'reason' => 'invalid_type', 'expected' => 'string']
        ])
        ->send();
}

// Check for path traversal attempts BEFORE sanitization
if (strpos($filename, '..') !== false || 
    strpos($filename, '/') !== false || 
    strpos($filename, '\\') !== false ||
    strpos($filename, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename contains invalid path characters')
        ->withErrors([
            ['field' => 'filename', 'reason' => 'path_traversal_attempt']
        ])
        ->send();
}

// Sanitize filename - basename removes any path components
$filename = basename($filename);

// After sanitization, check if filename is empty or became empty
if (empty($filename)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid filename format (empty after sanitization)')
        ->withErrors([
            ['field' => 'filename', 'reason' => 'empty_after_sanitization']
        ])
        ->send();
}

// Filename length validation - max 100 characters (including extension)
if (strlen($filename) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage("The filename must not exceed 100 characters.")
        ->withErrors([
            ['field' => 'filename', 'value' => $filename, 'max_length' => 100]
        ])
        ->send();
}

// Format validation - only alphanumeric, hyphens, underscores, and dots for extension
if (!RegexPatterns::match('file_name_with_ext', $filename)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename must contain only letters, numbers, hyphens, and underscores, with a valid extension')
        ->withErrors([RegexPatterns::validationError('file_name_with_ext', 'filename', $filename)])
        ->send();
}

// Build file path
$filePath = PUBLIC_FOLDER_ROOT . '/assets/' . $category . '/' . $filename;

// Check if file exists
if (!file_exists($filePath)) {
    ApiResponse::create(404, 'asset.not_found')
        ->withMessage("File '{$filename}' not found in category '{$category}'")
        ->withData([
            'filename' => $filename,
            'category' => $category
        ])
        ->send();
}

// Check if it's actually a file (not a directory)
if (!is_file($filePath)) {
    ApiResponse::create(400, 'asset.invalid_filename')
        ->withMessage("Path is not a file")
        ->send();
}

// Delete the file
if (!unlink($filePath)) {
    ApiResponse::create(500, 'asset.delete_failed')
        ->withMessage("Failed to delete file")
        ->send();
}

// Remove metadata for this asset if exists
$metadataPath = SECURE_FOLDER_PATH . '/config/assets_metadata.json';
if (file_exists($metadataPath)) {
    $metadataContent = file_get_contents($metadataPath);
    $metadata = json_decode($metadataContent, true) ?: [];
    
    $assetKey = $category . '/' . $filename;
    if (isset($metadata[$assetKey])) {
        unset($metadata[$assetKey]);
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// Success response
ApiResponse::create(204, 'operation.success')
    ->withMessage("File deleted successfully")
    ->withData([
        'filename' => $filename,
        'category' => $category
    ])
    ->send();