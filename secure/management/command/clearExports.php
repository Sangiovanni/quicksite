<?php
/**
 * clearExports Command
 * 
 * Clears the exports folder (removes saved export ZIP files).
 * 
 * @method POST
 * @route /management/clearExports
 * @auth required (admin permission)
 * 
 * @param string $project Clear only exports for specific project (optional)
 * @param bool $confirm Safety confirmation (required, must be true)
 * 
 * @return ApiResponse Cleanup result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_clearExports(array $params = [], array $urlParams = []): ApiResponse {
    // Safety confirmation
    $confirm = filter_var($params['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (!$confirm) {
        return ApiResponse::create(400, 'validation.confirmation_required')
            ->withMessage('Deletion must be confirmed')
            ->withErrors(['confirm' => 'Set confirm=true to proceed']);
    }
    
    $exportDir = SECURE_FOLDER_PATH . '/exports';
    
    if (!is_dir($exportDir)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Exports folder does not exist (nothing to clear)')
            ->withData(['deleted_count' => 0]);
    }
    
    $projectFilter = trim($params['project'] ?? '');
    $pattern = $projectFilter ? $exportDir . '/' . $projectFilter . '_export_*.zip' : $exportDir . '/*.zip';
    
    $files = glob($pattern);
    
    if ($files === false || count($files) === 0) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No export files to clear')
            ->withData(['deleted_count' => 0]);
    }
    
    $deleted = [];
    $failed = [];
    $totalSize = 0;
    
    foreach ($files as $file) {
        $filename = basename($file);
        $size = filesize($file);
        
        if (unlink($file)) {
            $deleted[] = $filename;
            $totalSize += $size;
        } else {
            $failed[] = $filename;
        }
    }
    
    $message = count($deleted) . ' export file(s) deleted';
    if ($projectFilter) {
        $message .= " for project '$projectFilter'";
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage($message)
        ->withData([
            'deleted_count' => count($deleted),
            'deleted_files' => $deleted,
            'failed_count' => count($failed),
            'failed_files' => $failed,
            'freed_space' => formatClearExportsBytes($totalSize)
        ]);
}

/**
 * Format bytes to human readable
 */
function formatClearExportsBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp = floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    
    return round($bytes / pow(1024, $exp), 2) . ' ' . $units[$exp];
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_clearExports($trimParams->params(), $trimParams->additionalParams())->send();
}
