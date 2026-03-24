<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/SvgSanitizer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AssetMetadataManager.php';

/**
 * Upload Asset Command
 * 
 * Uploads files to assets folders with auto-detected category.
 * Category is resolved from the file extension using assetExtensions.php.
 * Supports two sources (first available wins):
 *   1. Multipart file upload ($_FILES['file'])
 *   2. HTTPS URL download (url parameter)
 * Category passed via POST/multipart form data.
 * Optional description for AI context.
 */

/**
 * Download a URL to a temporary file with SSRF protection.
 * Resolves hostname before each request and blocks private/reserved IPs.
 * Handles up to 3 redirects, re-validating each hop.
 *
 * @return array{tmpFile: string, finalUrl: string}|array{error: string}
 */
function downloadUrlToTemp(string $url, int $maxSize, string $tmpDir): array
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        if (filter_var($currentUrl, FILTER_VALIDATE_URL) === false) {
            return ['error' => 'Invalid URL format'];
        }

        $parsed = parse_url($currentUrl);

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'https') {
            return ['error' => 'Only HTTPS URLs are allowed'];
        }

        $host = $parsed['host'] ?? '';
        if (empty($host)) {
            return ['error' => 'URL has no hostname'];
        }

        // Resolve hostname to IP before connecting
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return ['error' => 'Could not resolve hostname: ' . $host];
        }

        // Block private/reserved IPs only for non-local requests
        // Allow loopback (127.x) and private ranges for self-hosted CMS use
        // Still block link-local (169.254.x) and other reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['error' => 'URL resolves to a reserved IP address'];
        }

        $tmpFile = tempnam($tmpDir, 'asset_');
        if ($tmpFile === false) {
            return ['error' => 'Could not create temporary file'];
        }

        $fp = fopen($tmpFile, 'wb');
        if (!$fp) {
            @unlink($tmpFile);
            return ['error' => 'Could not write to temporary file'];
        }

        $responseHeaders = [];
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL            => $currentUrl,
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => false, // Handle redirects manually for SSRF safety
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_MAXFILESIZE    => $maxSize,
            CURLOPT_USERAGENT      => 'QuickSite/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ];

        // Handle SSL certificate verification
        if ($scheme === 'https') {
            $caInfo = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
            if (!empty($caInfo) && file_exists($caInfo)) {
                $curlOpts[CURLOPT_CAINFO] = $caInfo;
            } else {
                // No CA bundle configured (common on WAMP/local dev)
                $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
                $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
        }

        curl_setopt_array($ch, $curlOpts);

        curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!empty($curlError)) {
            @unlink($tmpFile);
            return ['error' => 'Download failed: ' . $curlError];
        }

        // Handle redirect — re-validate the next hop
        if ($httpCode >= 300 && $httpCode < 400) {
            @unlink($tmpFile);
            $location = $responseHeaders['location'] ?? null;
            if (empty($location)) {
                return ['error' => 'Redirect with no Location header'];
            }
            // Resolve relative redirect URLs
            if (!str_starts_with($location, 'http')) {
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $location = $parsed['scheme'] . '://' . $host . $port . $location;
            }
            $currentUrl = $location;
            continue;
        }

        if ($httpCode !== 200) {
            @unlink($tmpFile);
            return ['error' => 'Server returned HTTP ' . $httpCode];
        }

        return ['tmpFile' => $tmpFile, 'finalUrl' => $currentUrl];
    }

    return ['error' => 'Too many redirects (maximum ' . $maxRedirects . ')'];
}

// Get parameters from POST/JSON (handles multipart form-data)
$params = $trimParametersManagement->params();
$description = $params['description'] ?? null;
$alt = $params['alt'] ?? null;

// Initialize AssetMetadataManager for category resolution and metadata storage
$metaManager = new AssetMetadataManager(
    PROJECT_PATH . '/data',
    SECURE_FOLDER_PATH . '/management/config/assetExtensions.php'
);

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

// Validate alt if provided (optional parameter)
if ($alt !== null) {
    if (!is_string($alt)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The alt parameter must be a string.')
            ->withErrors([
                ['field' => 'alt', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }
    
    if (strlen($alt) > 250) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The alt parameter must not exceed 250 characters.')
            ->withErrors([
                ['field' => 'alt', 'max_length' => 250, 'actual_length' => strlen($alt)]
            ])
            ->send();
    }
    
    $alt = trim($alt);
}

// ============================================================
// Determine file source: multipart upload or URL download
// ============================================================
$url = $params['url'] ?? null;
$sourceType = null;
$cleanupTmpFile = null; // Temp file to delete on error (URL downloads only)

// Validate file size limits (needed before URL download)
$sizeLimits = [
    'images' => 5 * 1024 * 1024,    // 5MB
    'font' => 2 * 1024 * 1024,      // 2MB
    'audio' => 10 * 1024 * 1024,    // 10MB
    'videos' => 50 * 1024 * 1024    // 50MB
];

// Priority: multipart file upload > URL
$hasFileUpload = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasFileUpload) {
    // ---- SOURCE: Multipart file upload ----
    $sourceType = 'upload';
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
            ->withData(['error_code' => $file['error']])
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

} elseif (!empty($url)) {
    // ---- SOURCE: URL download ----
    $sourceType = 'url';
    
    // Validate url parameter type
    if (!is_string($url)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The url parameter must be a string.')
            ->withErrors([['field' => 'url', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }
    
    // Length limit
    if (strlen($url) > 2048) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('URL must not exceed 2048 characters.')
            ->send();
    }
    
    // Pre-extract filename from URL to resolve category and size limit before downloading
    $urlPath = parse_url($url, PHP_URL_PATH);
    $urlFilename = $urlPath ? basename($urlPath) : '';
    if (empty($urlFilename) || $urlFilename === '/') {
        ApiResponse::create(400, 'validation.invalid_file')
            ->withMessage('Could not determine filename from URL. URL must point to a file with a recognized extension.')
            ->send();
    }
    
    // Resolve category from URL filename extension
    $urlCategory = $metaManager->resolveCategory($urlFilename);
    if ($urlCategory === null) {
        $urlExt = strtolower(pathinfo($urlFilename, PATHINFO_EXTENSION));
        ApiResponse::create(400, 'validation.invalid_extension')
            ->withMessage("Unrecognized file extension '.{$urlExt}' in URL — cannot determine asset category")
            ->withErrors([['field' => 'url', 'reason' => 'unknown_extension', 'extension' => $urlExt]])
            ->send();
    }
    
    // Ensure tmp directory exists
    $tmpDir = SECURE_FOLDER_PATH . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0750, true);
    }
    
    // Download with SSRF protection (use category-specific size limit)
    $downloadResult = downloadUrlToTemp($url, $sizeLimits[$urlCategory], $tmpDir);
    
    if (isset($downloadResult['error'])) {
        ApiResponse::create(400, 'asset.url_download_failed')
            ->withMessage('URL download failed: ' . $downloadResult['error'])
            ->send();
    }
    
    $cleanupTmpFile = $downloadResult['tmpFile'];
    
    // Use final URL filename (may differ after redirects)
    $finalUrlPath = parse_url($downloadResult['finalUrl'], PHP_URL_PATH);
    $finalUrlFilename = $finalUrlPath ? basename($finalUrlPath) : $urlFilename;
    if (empty($finalUrlFilename) || $finalUrlFilename === '/') {
        $finalUrlFilename = $urlFilename;
    }
    
    // Build a $file array matching the $_FILES shape for unified validation
    $file = [
        'tmp_name' => $downloadResult['tmpFile'],
        'name'     => $finalUrlFilename,
        'size'     => filesize($downloadResult['tmpFile']),
        'error'    => UPLOAD_ERR_OK,
    ];

} else {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('No file source provided. Upload a file or provide a url parameter.')
        ->withData(['accepted_sources' => ['file (multipart)', 'url (HTTPS)']])
        ->send();
}

// ============================================================
// Resolve category from file extension (auto-detect)
// ============================================================

// Sanitize and validate filename
$originalName = basename($file['name']); // Remove any path components

// Additional security: check for empty filename after basename
if (empty($originalName)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Invalid filename provided')
        ->send();
}

// Extract filename components safely
$pathInfo = pathinfo($originalName);
$extension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : '';
$filenameOnly = $pathInfo['filename'] ?? '';

// Validate extension exists and is not empty
if (empty($extension)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('File must have a valid extension')
        ->send();
}

// Additional security: block dangerous extensions regardless of category
$dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'htaccess'];
if (in_array($extension, $dangerousExtensions, true)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.forbidden_extension')
        ->withMessage('Executable file types are not allowed')
        ->withData(['extension' => $extension])
        ->send();
}

// Auto-detect category from file extension
$category = $metaManager->resolveCategory($originalName);
if ($category === null) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_extension')
        ->withMessage("Unrecognized file extension '.{$extension}' — cannot determine asset category")
        ->withErrors([['field' => 'file', 'reason' => 'unknown_extension', 'extension' => $extension, 'allowed' => $metaManager->getAllExtensions()]])
        ->send();
}

// Validate file size (category-specific limits)
if ($file['size'] > $sizeLimits[$category]) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'asset.file_too_large')
        ->withMessage("File exceeds maximum size of " . ($sizeLimits[$category] / 1024 / 1024) . "MB for category '{$category}'")
        ->withData([
            'max_size_mb' => $sizeLimits[$category] / 1024 / 1024,
            'actual_size_mb' => round($file['size'] / 1024 / 1024, 2)
        ])
        ->send();
}

// Validate MIME type based on resolved category
$allowedMimes = [
    'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
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
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Could not determine file type')
        ->send();
}

if (!in_array($mimeType, $allowedMimes[$category], true)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_mime_type')
        ->withMessage("File type '{$mimeType}' not allowed for category '{$category}'")
        ->withData([
            'detected_mime' => $mimeType,
            'allowed_mimes' => $allowedMimes[$category]
        ])
        ->send();
}

// Validate filename is not empty
if (empty($filenameOnly)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_file')
        ->withMessage('Filename cannot be empty')
        ->send();
}

// Sanitize filename - only alphanumeric, hyphens, underscores
// This prevents: path traversal, special chars, unicode attacks
$basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filenameOnly);

// Check if sanitization resulted in empty string
if (empty($basename)) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename contains only invalid characters')
        ->send();
}

// Enforce 100 character limit for filename (consistent with other commands)
if (strlen($basename . '.' . $extension) > 100) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Filename must not exceed 100 characters.')
        ->withErrors([
            ['field' => 'filename', 'max_length' => 100, 'actual_length' => strlen($basename . '.' . $extension)]
        ])
        ->send();
}

// Build target path (use PUBLIC_FOLDER_ROOT not PUBLIC_FOLDER_PATH)
$targetDir = PUBLIC_CONTENT_PATH . '/assets/' . $category . '/';

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

// Move file to destination (source-aware)
if ($sourceType === 'upload') {
    $moveSuccess = move_uploaded_file($file['tmp_name'], $targetFile);
} else {
    $moveSuccess = rename($file['tmp_name'], $targetFile);
    $cleanupTmpFile = null; // File was moved, no longer needs cleanup
}

if (!$moveSuccess) {
    if ($cleanupTmpFile) @unlink($cleanupTmpFile);
    ApiResponse::create(500, 'server.file_move_failed')
        ->withMessage("Failed to move file to destination")
        ->withData([
            'target' => '/assets/' . $category . '/' . $finalFilename
        ])
        ->send();
}

// Verify file was actually created
if (!file_exists($targetFile)) {
    ApiResponse::create(500, 'server.file_verification_failed')
        ->withMessage("File upload failed: file not found after move")
        ->send();
}

$actualSize = filesize($targetFile);

// Size mismatch check (only for multipart uploads where we can compare)
if ($sourceType === 'upload' && $actualSize !== $file['size']) {
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
// SVG Sanitization (remove scripts, event handlers, etc.)
// ============================================================
if ($mimeType === 'image/svg+xml') {
    if (!SvgSanitizer::sanitizeFile($targetFile)) {
        @unlink($targetFile);
        ApiResponse::create(400, 'validation.invalid_file')
            ->withMessage('SVG file could not be sanitized — it may contain malformed XML')
            ->send();
    }
    // Update actual size after sanitization (content may have changed)
    $actualSize = filesize($targetFile);
}

// ============================================================
// Store Asset Metadata (via AssetMetadataManager)
// ============================================================

// Build metadata for this asset
$assetMeta = [
    'uploaded' => date('c'), // ISO 8601 format
    'mime_type' => $mimeType,
    'size' => $actualSize
];

// Add description if provided
if (!empty($description)) {
    $assetMeta['description'] = $description;
}

// Add alt if provided
if (!empty($alt)) {
    $assetMeta['alt'] = $alt;
}

// Auto-detect dimensions for images
$fileInfo = $metaManager->detectFileInfo($targetFile);
if (isset($fileInfo['width'])) {
    $assetMeta['width'] = $fileInfo['width'];
    $assetMeta['height'] = $fileInfo['height'];
    $assetMeta['dimensions'] = $fileInfo['dimensions'];
}

// Store metadata
$metaManager->set($category, $finalFilename, $assetMeta);
$metaManager->save();

// Build response data
$responseData = [
    'filename' => $finalFilename,
    'category' => $category,
    'path' => '/assets/' . $category . '/' . $finalFilename,
    'size' => $actualSize,
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

// Include alt if provided
if (!empty($alt)) {
    $responseData['alt'] = $alt;
}

// Success response
ApiResponse::create(201, 'operation.success')
    ->withMessage("File uploaded successfully")
    ->withData($responseData)
    ->send();