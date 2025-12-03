<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$category = $_GET['category'] ?? null;

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

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = isset($_FILES['file']) 
        ? 'Upload error code: ' . $_FILES['file']['error'] 
        : 'No file uploaded';
    
    ApiResponse::create(400, 'asset.upload_failed')
        ->withMessage($errorMsg)
        ->send();
}

$file = $_FILES['file'];

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

// Validate MIME type based on category
$allowedMimes = [
    'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
    'scripts' => ['application/javascript', 'text/javascript', 'application/x-javascript'],
    'font' => ['font/ttf', 'font/otf', 'font/woff', 'font/woff2', 'application/x-font-ttf', 'application/x-font-otf'],
    'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg'],
    'videos' => ['video/mp4', 'video/webm', 'video/ogg']
];

// Get actual MIME type (not just from client)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimes[$category])) {
    ApiResponse::create(400, 'asset.invalid_file_type')
        ->withMessage("File type '{$mimeType}' not allowed for category '{$category}'")
        ->withData([
            'detected_mime' => $mimeType,
            'allowed_mimes' => $allowedMimes[$category]
        ])
        ->send();
}

// Sanitize filename
$originalName = $file['name'];
$pathInfo = pathinfo($originalName);
$extension = strtolower($pathInfo['extension'] ?? '');
$basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $pathInfo['filename']);

// Validate extension matches MIME type
$validExtensions = [
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
    'scripts' => ['js'],
    'font' => ['ttf', 'otf', 'woff', 'woff2'],
    'audio' => ['mp3', 'wav', 'ogg'],
    'videos' => ['mp4', 'webm', 'ogv']
];

if (!in_array($extension, $validExtensions[$category])) {
    ApiResponse::create(400, 'asset.invalid_extension')
        ->withMessage("File extension '.{$extension}' not allowed for category '{$category}'")
        ->withData([
            'extension' => $extension,
            'allowed_extensions' => $validExtensions[$category]
        ])
        ->send();
}

// Build target path
$targetDir = PUBLIC_FOLDER_PATH . '/assets/' . $category . '/';
$targetFile = $targetDir . $basename . '.' . $extension;

// Check if file already exists and generate unique name
$finalFilename = $basename . '.' . $extension;
$counter = 1;
while (file_exists($targetFile)) {
    $finalFilename = $basename . '_' . $counter . '.' . $extension;
    $targetFile = $targetDir . $finalFilename;
    $counter++;
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
    ApiResponse::create(500, 'asset.move_failed')
        ->withMessage("Failed to move uploaded file to destination")
        ->send();
}

// Success response
ApiResponse::create(201, 'operation.success')
    ->withMessage("File uploaded successfully")
    ->withData([
        'filename' => $finalFilename,
        'category' => $category,
        'path' => '/assets/' . $category . '/' . $finalFilename,
        'size' => $file['size'],
        'mime_type' => $mimeType
    ])
    ->send();