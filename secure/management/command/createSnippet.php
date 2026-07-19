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
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';

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
    // C8 8.5 CONTAINMENT: the project WRITTEN TO is BOUND to the URL marker the
    // dispatcher authorized; a body `project` is an optional echo that must match.
    // (F-C8-8.5-1: it used to select the write target freely and fall back to the
    // SERVED main from target.php, so an editor authorized on one project could
    // plant, overwrite and delete snippets in a project they were not a member of.)
    $bound = qs_bind_marker_project($params, 'createSnippet');
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

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
    
    // (project already bound to the authorized marker above)
    
    // Check if snippet ID already exists in project or global
    $existingSnippet = findSnippetInPath($snippetId, getProjectSnippetsPath($projectName), 'project');
    if ($existingSnippet === null) {
        $existingSnippet = findSnippetInPath($snippetId, getGlobalSnippetsPath(), 'global');
    }
    if ($existingSnippet !== null) {
        return ApiResponse::create(409, 'snippets.already_exists')
            ->withMessage('A snippet with this ID already exists' . ($existingSnippet['source'] === 'global' ? ' (global)' : ' in the project'));
    }
    
    // Build snippet data
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
    
    // Extract CSS selectors and matching rules from project stylesheet
    $cssResult = extractSnippetCss($structure, $projectName);
    if (!empty($cssResult['selectors']['classes']) || !empty($cssResult['selectors']['ids'])) {
        $snippetData['selectors'] = $cssResult['selectors'];
    }
    if (!empty($cssResult['css'])) {
        $snippetData['css'] = $cssResult['css'];
    }
    
    // Determine save scope (project or global)
    $scope = $params['scope'] ?? 'project';
    if (!in_array($scope, ['project', 'global'], true)) {
        $scope = 'project';
    }
    
    // Save snippet
    $result = saveProjectSnippet($snippetData, $projectName, $scope);
    
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
