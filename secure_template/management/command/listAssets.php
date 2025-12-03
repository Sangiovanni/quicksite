<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$category = $_GET['category'] ?? null;

// Validate category (optional - if not provided, list all)
$validCategories = ['images', 'scripts', 'font', 'audio', 'videos'];
if ($category !== null && !in_array($category, $validCategories)) {
    ApiResponse::create(400, 'asset.invalid_category')
        ->withMessage("Category must be one of: " . implode(', ', $validCategories))
        ->withErrors([
            ['field' => 'category', 'value' => $category, 'allowed' => $validCategories]
        ])
        ->send();
}

$assetsPath = PUBLIC_FOLDER_PATH . '/assets/';
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