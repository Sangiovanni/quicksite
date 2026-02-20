<?php
/**
 * duplicateSnippet - Duplicate a snippet to project snippets
 * 
 * @method POST
 * @url /management/duplicateSnippet
 * @auth required
 * @permission edit
 * @param string $id Required - Source snippet ID to duplicate
 * @param string $newId Optional - New snippet ID (defaults to original-copy)
 * @param string $newName Optional - New display name (defaults to "Copy of [original]")
 * @param string $project Optional - Project name (defaults to active project)
 * 
 * Duplicates a snippet (usually core) to the project's snippets folder.
 * This is the only way to get an editable copy of core snippets.
 * CSS is NOT copied for user snippets - they should use project styles.
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
function __command_duplicateSnippet(array $params = [], array $urlParams = []): ApiResponse {
    $sourceId = $params['id'] ?? null;
    $newId = $params['newId'] ?? null;
    $newName = $params['newName'] ?? null;
    $projectName = $params['project'] ?? null;
    
    if (!$sourceId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Source snippet ID is required');
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
    
    // Get source snippet
    $sourceSnippet = getSnippetById($sourceId, $projectName);
    
    if ($sourceSnippet === null) {
        return ApiResponse::create(404, 'snippets.source_not_found')
            ->withMessage('Source snippet not found: ' . $sourceId);
    }
    
    // Generate new ID and name if not provided
    if (!$newId) {
        $newId = $sourceId . '-copy';
        // If that already exists, add number
        $counter = 2;
        while (findSnippetInPath($newId, getSnippetsPath($projectName), false) !== null) {
            $newId = $sourceId . '-copy-' . $counter;
            $counter++;
        }
    }
    
    if (!$newName) {
        $newName = 'Copy of ' . $sourceSnippet['name'];
    }
    
    // Validate new ID format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $newId)) {
        return ApiResponse::create(400, 'snippets.invalid_id')
            ->withMessage('New ID must start with a letter and contain only letters, numbers, dashes, and underscores');
    }
    
    // Check if new ID already exists
    if (findSnippetInPath($newId, getSnippetsPath($projectName), false) !== null) {
        return ApiResponse::create(409, 'snippets.already_exists')
            ->withMessage('A snippet with ID "' . $newId . '" already exists in the project');
    }
    
    // Build new snippet (copy structure and translations, NOT css)
    $newSnippet = [
        'id' => $newId,
        'name' => $newName,
        'category' => $sourceSnippet['category'] ?? 'other',
        'description' => $sourceSnippet['description'] ?? '',
        'structure' => $sourceSnippet['structure']
    ];
    
    // Copy translations if present
    if (!empty($sourceSnippet['translations'])) {
        // Optionally: rename translation keys to avoid conflicts
        // For now, just copy as-is (user can edit)
        $newSnippet['translations'] = $sourceSnippet['translations'];
    }
    
    // Note: CSS is intentionally NOT copied for user snippets
    // They should adapt the structure to use project CSS classes
    
    // Save new snippet
    $result = saveProjectSnippet($newSnippet, $projectName);
    
    if (!$result['success']) {
        return ApiResponse::create(500, 'snippets.save_failed')
            ->withMessage($result['error']);
    }
    
    return ApiResponse::create(201, 'snippets.duplicate_success')
        ->withMessage('Snippet duplicated: ' . $newName)
        ->withData([
            'sourceId' => $sourceId,
            'newId' => $newId,
            'name' => $newName,
            'category' => $newSnippet['category'],
            'path' => $result['path'],
            'project' => $projectName,
            'note' => $sourceSnippet['isCore'] 
                ? 'Core snippet CSS not copied. Adapt classes to use your project styles.'
                : null
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_duplicateSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
