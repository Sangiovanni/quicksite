<?php
/**
 * deleteSnippet - Delete a project snippet
 * 
 * @method DELETE
 * @url /management/deleteSnippet
 * @auth required
 * @permission edit
 * @param string $id Required - Snippet ID to delete
 * @param string $project Optional - Project name (defaults to active project)
 * 
 * Deletes a snippet from the project's snippets folder.
 * Cannot delete core snippets (they are read-only).
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
function __command_deleteSnippet(array $params = [], array $urlParams = []): ApiResponse {
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
    
    if (!$projectName) {
        return ApiResponse::create(400, 'snippets.project_required')
            ->withMessage('No project specified and no active project found');
    }
    
    // Check if this is a core snippet (cannot delete)
    $coreSnippetsPath = getSnippetsPath(null);
    $coreSnippet = findSnippetInPath($snippetId, $coreSnippetsPath, true);
    
    if ($coreSnippet !== null) {
        return ApiResponse::create(403, 'snippets.cannot_delete_core')
            ->withMessage('Cannot delete core snippets. Use duplicateSnippet to create an editable copy.');
    }
    
    // Delete project snippet
    $result = deleteProjectSnippet($snippetId, $projectName);
    
    if (!$result['success']) {
        return ApiResponse::create(404, 'snippets.not_found')
            ->withMessage($result['error']);
    }
    
    return ApiResponse::create(200, 'snippets.delete_success')
        ->withMessage('Snippet deleted: ' . $snippetId)
        ->withData([
            'id' => $snippetId,
            'project' => $projectName
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
