<?php
/**
 * getSnippet - Get a single snippet by ID
 * 
 * @method GET
 * @url /management/getSnippet
 * @auth required
 * @permission read
 * @param string $id Required - Snippet ID
 * @param string $project Optional - Project name (defaults to active project)
 * 
 * Returns full snippet data including structure, translations, and CSS.
 * Searches project snippets first, then falls back to core snippets.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getSnippet(array $params = [], array $urlParams = []): ApiResponse {
    $snippetId = $params['id'] ?? null;
    $projectName = $params['project'] ?? null;
    
    if (!$snippetId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Snippet ID is required');
    }
    
    // Get project name if not provided
    if (!$projectName) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }
    
    // Get snippet by ID
    $snippet = getSnippetById($snippetId, $projectName);
    
    if ($snippet === null) {
        return ApiResponse::create(404, 'snippets.not_found')
            ->withMessage('Snippet not found: ' . $snippetId);
    }
    
    // Remove internal fields
    unset($snippet['_filePath']);
    
    return ApiResponse::create(200, 'snippets.get_success')
        ->withMessage('Snippet loaded: ' . $snippet['name'])
        ->withData($snippet);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
