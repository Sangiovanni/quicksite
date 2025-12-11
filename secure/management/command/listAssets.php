<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Get parameters (works with GET, POST, or JSON)
$params = $trimParametersManagement->params();
$category = $params['category'] ?? null;

// Validate category if provided
$validCategories = require SECURE_FOLDER_PATH . '/management/config/assetCategories.php';

// If category is provided, validate it
if ($category !== null) {
    // Type validation - category must be string
    if (!is_string($category)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The category parameter must be a string.')
            ->withErrors([
                ['field' => 'category', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }

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
}

$assetsPath = PUBLIC_FOLDER_ROOT . '/assets/';
$result = [];

// Determine which categories to scan
$categoriesToScan = $category !== null ? [$category] : $validCategories;

foreach ($categoriesToScan as $cat) {
    $categoryPath = $assetsPath . $cat . '/';
    
    if (!is_dir($categoryPath)) {
        continue;
    }
    
    $files = array_diff(scandir($categoryPath), ['.', '..', 'index.php']);
    $fileList = [];
    
    foreach ($files as $file) {
        $filePath = $categoryPath . $file;
        
        // Skip directories and non-files
        if (!is_file($filePath)) {
            continue;
        }
        
        $fileList[] = [
            'filename' => $file,
            'size' => filesize($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'path' => '/assets/' . $cat . '/' . $file
        ];
    }
    
    // Sort by filename
    usort($fileList, function($a, $b) {
        return strcmp($a['filename'], $b['filename']);
    });
    
    $result[$cat] = $fileList;
}

// Success response
ApiResponse::create(200, 'operation.success')
    ->withMessage($category !== null 
        ? "Assets retrieved for category '{$category}'" 
        : "All assets retrieved")
    ->withData([
        'assets' => $result,
        'total_categories' => count($result),
        'total_files' => array_sum(array_map('count', $result))
    ])
    ->send();