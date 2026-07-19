<?php
/**
 * downloadExport Command
 * 
 * Downloads a previously exported project ZIP file.
 * 
 * @method GET
 * @route /management/downloadExport
 * @auth required (admin permission)
 * 
 * @param string $file Export filename (required)
 * 
 * @return void Streams file download
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Query parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_downloadExport(array $params = [], array $urlParams = []): ApiResponse {
    // Merge query parameters for GET requests
    $params = array_merge($_GET, $params);

    // C8 8.5 CONTAINMENT (F-C8-8.5-2): the archive is read from the PROJECT'S OWN
    // exports directory, bound to the URL marker the dispatcher authorized. This
    // used to read a shared installation-wide secure/exports, where the traversal
    // guard below still held but the directory was not partitioned by project — so
    // an admin on any project could name and stream another project's full archive
    // (which carries its data/ tree, api-endpoints.json included).
    $bound = qs_bind_marker_project($params, 'downloadExport');
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    $filename = trim($params['file'] ?? '');

    if (empty($filename)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Export filename is required')
            ->withErrors(['file' => 'Required field']);
    }
    
    // Security: validate filename format (no path traversal)
    if (preg_match('/[\/\\\\]/', $filename) || strpos($filename, '..') !== false) {
        return ApiResponse::create(400, 'validation.invalid_filename')
            ->withMessage('Invalid filename')
            ->withErrors(['file' => 'Filename cannot contain path separators']);
    }
    
    // Must be a zip file
    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('Only ZIP files can be downloaded')
            ->withErrors(['file' => 'Must be a .zip file']);
    }
    
    // Check the project's own exports directory
    $exportDir = qs_project_exports_dir($projectName);
    $filePath = $exportDir . '/' . $filename;
    
    if (!file_exists($filePath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage('Export file not found')
            ->withData([
                'filename' => $filename,
                'hint' => 'Export may have expired or been deleted'
            ]);
    }
    
    // Stream the file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($filePath);
    exit;
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_downloadExport($trimParams->params(), $trimParams->additionalParams())->send();
}