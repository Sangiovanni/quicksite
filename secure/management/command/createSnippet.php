<?php
/**
 * createSnippet - Create a new project snippet
 * 
 * @method POST
 * @url /management/createSnippet
 * @auth required
 * @permission edit
 * @param string $id Required - Unique snippet ID
 * @param string $name Required - Display name
 * @param string $category Optional - Category (nav, forms, cards, layouts, content, lists, other)
 * @param string $description Optional - Description text
 * @param object $structure Required - Structure JSON (like components)
 * @param object $translations Optional - Translation keys by language
 * @param string $project Optional - Project name (defaults to active project)
 * 
 * Creates a new snippet in the project's snippets folder.
 * User snippets cannot have CSS field (they use project styles).
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
function __command_createSnippet(array $params = [], array $urlParams = []): ApiResponse {
    $snippetId = $params['id'] ?? null;
    $name = $params['name'] ?? null;
    $category = $params['category'] ?? 'other';
    $description = $params['description'] ?? '';
    $structure = $params['structure'] ?? null;
    $translations = $params['translations'] ?? [];
    $projectName = $params['project'] ?? null;
    
    // Validate required fields
    if (!$snippetId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Snippet ID is required');
    }
    
    if (!$name) {
        return ApiResponse::create(400, 'snippets.name_required')
            ->withMessage('Snippet name is required');
    }
    
    if (!$structure) {
        return ApiResponse::create(400, 'snippets.structure_required')
            ->withMessage('Snippet structure is required');
    }
    
    // Validate ID format (alphanumeric, dashes, underscores)
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $snippetId)) {
        return ApiResponse::create(400, 'snippets.invalid_id')
            ->withMessage('Snippet ID must start with a letter and contain only letters, numbers, dashes, and underscores');
    }
    
    // Validate category
    $validCategories = ['nav', 'forms', 'cards', 'layouts', 'content', 'lists', 'other'];
    if (!in_array($category, $validCategories)) {
        $category = 'other';
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
    
    // Check if snippet ID already exists in project
    $existingSnippet = findSnippetInPath($snippetId, getSnippetsPath($projectName), false);
    if ($existingSnippet !== null) {
        return ApiResponse::create(409, 'snippets.already_exists')
            ->withMessage('A snippet with this ID already exists in the project');
    }
    
    // Build snippet data (no CSS for user snippets)
    $snippetData = [
        'id' => $snippetId,
        'name' => $name,
        'category' => $category,
        'description' => $description,
        'structure' => $structure
    ];
    
    if (!empty($translations)) {
        $snippetData['translations'] = $translations;
    }
    
    // Save snippet
    $result = saveProjectSnippet($snippetData, $projectName);
    
    if (!$result['success']) {
        return ApiResponse::create(500, 'snippets.save_failed')
            ->withMessage($result['error']);
    }
    
    return ApiResponse::create(201, 'snippets.create_success')
        ->withMessage('Snippet created: ' . $name)
        ->withData([
            'id' => $snippetId,
            'name' => $name,
            'category' => $category,
            'path' => $result['path'],
            'project' => $projectName
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_createSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
