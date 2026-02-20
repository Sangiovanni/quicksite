<?php
/**
 * listAssets - List all uploaded assets by category
 * 
 * @method GET
 * @url /management/listAssets
 * @url /management/listAssets/{category}
 * @auth required
 * @permission read
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [category?]
 * @return ApiResponse
 */
function __command_listAssets(array $params = [], array $urlParams = []): ApiResponse {
    $category = $urlParams[0] ?? null;

    // Validate category if provided
    $validCategories = require SECURE_FOLDER_PATH . '/management/config/assetCategories.php';

    // Load asset metadata
    $metadataPath = PROJECT_PATH . '/data/assets_metadata.json';
    $allMetadata = [];
    if (file_exists($metadataPath)) {
        $metadataContent = file_get_contents($metadataPath);
        $allMetadata = json_decode($metadataContent, true) ?: [];
    }

    // If category is provided, validate it
    if ($category !== null) {
        // Type validation - category must be string
        if (!is_string($category)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The category parameter must be a string.')
                ->withErrors([
                    ['field' => 'category', 'reason' => 'invalid_type', 'expected' => 'string']
                ]);
        }

        // Dynamic length validation based on longest valid category
        $maxCategoryLength = max(array_map('strlen', $validCategories));
        if (strlen($category) > $maxCategoryLength) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage("The category parameter must not exceed {$maxCategoryLength} characters.")
                ->withErrors([
                    ['field' => 'category', 'value' => $category, 'max_length' => $maxCategoryLength]
                ]);
        }

        // Check if category is in whitelist
        if (!in_array($category, $validCategories, true)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("Category must be one of: " . implode(', ', $validCategories))
                ->withErrors([
                    ['field' => 'category', 'value' => $category, 'allowed' => $validCategories]
                ]);
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
            
            // Build file info
            $fileInfo = [
                'filename' => $file,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                'path' => '/assets/' . $cat . '/' . $file
            ];
            
            // Merge metadata if available
            $assetKey = $cat . '/' . $file;
            if (isset($allMetadata[$assetKey])) {
                $meta = $allMetadata[$assetKey];
                
                // Add description if available
                if (!empty($meta['description'])) {
                    $fileInfo['description'] = $meta['description'];
                }
                
                // Add dimensions for images
                if (isset($meta['dimensions'])) {
                    $fileInfo['dimensions'] = $meta['dimensions'];
                    $fileInfo['width'] = $meta['width'];
                    $fileInfo['height'] = $meta['height'];
                }
                
                // Add mime type if stored
                if (isset($meta['mime_type'])) {
                    $fileInfo['mime_type'] = $meta['mime_type'];
                }
                
                // Add upload date if available
                if (isset($meta['uploaded'])) {
                    $fileInfo['uploaded'] = $meta['uploaded'];
                }
            }
            
            $fileList[] = $fileInfo;
        }
        
        // Sort by filename
        usort($fileList, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });
        
        $result[$cat] = $fileList;
    }

    // Success response
    return ApiResponse::create(200, 'operation.success')
        ->withMessage($category !== null 
            ? "Assets retrieved for category '{$category}'" 
            : "All assets retrieved")
        ->withData([
            'assets' => $result,
            'total_categories' => count($result),
            'total_files' => array_sum(array_map('count', $result))
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_listAssets([], $urlSegments)->send();
}