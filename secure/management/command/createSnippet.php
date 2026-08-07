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
 * @param string $scope Optional - 'project' (default) or 'personal' (the caller's
 *               own library, reusable across THEIR projects). 'global' is a
 *               legacy alias of 'personal'.
 *
 * Creates a new snippet in the project's snippets folder, or in the caller's own
 * personal library when scope=personal.
 * User snippets cannot have CSS field (they use project styles).
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/PathManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_first_unrenderable_tag

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
    // (F-C8-8.5-1: it used to select the write target freely and fall back to an
    // installation-wide default project, so an editor authorized on one project could
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

    // SECURITY (beta.10 C13 13.5, F2 write side): the structure arrives verbatim
    // from the request, so this command is where a non-renderable tag can ENTER
    // stored data — and from here insertSnippet copies it into a page. Enforce the
    // same TagRegistry gate the renderer, the compiler and editStructure use. The
    // editor only ever emits allowlisted tags, so this refuses nothing a legitimate
    // snippet contains.
    $badTag = qs_first_unrenderable_tag($structure);
    if ($badTag !== null) {
        return ApiResponse::create(400, 'validation.blocked_tag')
            ->withMessage("Tag '{$badTag}' is not allowed (security restriction)")
            ->withErrors([['field' => 'structure', 'reason' => 'blocked_tag', 'value' => $badTag]]);
    }
    
    // (project already bound to the authorized marker above)
    
    // Check if snippet ID already exists in the project or in the caller's own
    // personal library. It is deliberately NOT checked against other users'
    // personal snippets: doing so would answer "does user X own a snippet called
    // Y" to anyone who can guess an id — the existence oracle C10 spent a slice
    // closing elsewhere.
    $existingSnippet = findSnippetInPath($snippetId, getProjectSnippetsPath($projectName), 'project');
    if ($existingSnippet === null) {
        $personalSnippetsPath = getPersonalSnippetsPath();
        if ($personalSnippetsPath !== null) {
            $existingSnippet = findSnippetInPath($snippetId, $personalSnippetsPath, 'personal');
        }
    }
    if ($existingSnippet !== null) {
        return ApiResponse::create(409, 'snippets.already_exists')
            ->withMessage('A snippet with this ID already exists' . ($existingSnippet['source'] === 'personal' ? ' in your personal snippets' : ' in the project'));
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
    
    // Determine save scope (project or personal).
    //
    // 'global' is accepted as a legacy ALIAS of 'personal'. The scope used to
    // write to one flat installation-wide directory that every project marker
    // could read, insert from and DELETE — proven cross-project in beta.10 C13
    // 13.6b. It is per-user now, which is what the UI's "available to all
    // projects" always meant: all of the author's own. The alias stays so a
    // cached editor bundle (or any caller written against the old name) keeps
    // working; it lands in the same per-user directory.
    $scope = $params['scope'] ?? 'project';
    if ($scope === 'global') {
        $scope = 'personal';
    }
    if (!in_array($scope, ['project', 'personal'], true)) {
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
