<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * getStyles - Retrieves the main CSS stylesheet content
 * 
 * @method GET
 * @url /management/getStyles
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getStyles(array $params = [], array $urlParams = []): ApiResponse {
    $styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

    // Check if file exists
    if (!file_exists($styleFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Style file not found")
            ->withData(['file' => $styleFile]);
    }

    // Read file content
    $content = @file_get_contents($styleFile);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to read style file");
    }

    // Get file stats
    $fileStats = stat($styleFile);

    // Success
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Style file retrieved successfully')
        ->withData([
            'content' => $content,
            'file' => $styleFile,
            'size' => $fileStats['size'],
            'modified' => date('Y-m-d H:i:s', $fileStats['mtime'])
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_getStyles()->send();
}