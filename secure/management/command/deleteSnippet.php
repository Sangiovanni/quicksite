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
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_deleteSnippet(array $params = [], array $urlParams = []): ApiResponse {
    $snippetId = $params['id'] ?? null;

    // C8 8.5 CONTAINMENT: the project DELETED FROM is BOUND to the URL marker the
    // dispatcher authorized; a body `project` is an optional echo that must match
    // (F-C8-8.5-1 — it used to select the delete target freely, falling back to
    // the SERVED main from target.php).
    $bound = qs_bind_marker_project($params, 'deleteSnippet');
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    if (!$snippetId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Snippet ID is required');
    }
    
    // Check if this is a core snippet (cannot delete)
    $coreSnippetsPath = getCoreSnippetsPath();
    $coreSnippet = findSnippetInPath($snippetId, $coreSnippetsPath, 'core');
    
    if ($coreSnippet !== null) {
        return ApiResponse::create(403, 'snippets.cannot_delete_core')
            ->withMessage('Cannot delete core snippets. Use duplicateSnippet to create an editable copy.');
    }
    
    // Delete project or global snippet
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
