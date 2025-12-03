<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$category = $_GET['category'] ?? null;
$filename = $_GET['filename'] ?? null;

// Validate category
$validCategories = ['images', 'scripts', 'font', 'audio', 'videos'];
if (!in_array($category, $validCategories)) {
    ApiResponse::create(400, 'asset.invalid_category')
        ->withMessage("Category must be one of: " . implode(', ', $validCategories))
        ->withErrors([
            ['field' => 'category', 'value' => $category, 'allowed' => $validCategories]
        ])
        ->send();
}

// Validate filename
if (empty($filename)) {
    ApiResponse::create(400, 'validation.required')
        ->withErrors([
            ['field' => 'filename', 'reason' => 'missing']
        ])
        ->send();
}

// Sanitize filename (no path traversal)
$filename = basename($filename);
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    ApiResponse::create(400, 'asset.invalid_filename')
        ->withMessage("Invalid filename")
        ->send();
}

// Build file path
$filePath = PUBLIC_FOLDER_PATH . '/assets/' . $category . '/' . $filename;

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

// Success response
ApiResponse::create(204, 'operation.success')
    ->withMessage("File deleted successfully")
    ->withData([
        'filename' => $filename,
        'category' => $category
    ])
    ->send();