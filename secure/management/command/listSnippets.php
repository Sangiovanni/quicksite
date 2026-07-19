<?php
/**
 * listSnippets - List all available snippets
 * 
 * @method GET
 * @url /management/listSnippets
 * @auth required
 * @permission read
 * 
 * Returns all snippets (core + project-specific) organized by category.
 * Core snippets are read-only and marked with isCore: true.
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
function __command_listSnippets(array $params = [], array $urlParams = []): ApiResponse {
    // C8 8.5 CONTAINMENT: the project read is BOUND to the URL marker the
    // dispatcher authorized. A body `project` is an optional echo that must match.
    // (Before: `project` selected the target freely and fell back to the SERVED
    // main from target.php, so an authorized marker on one project could read
    // another project's snippets — F-C8-8.5-1.)
    $bound = qs_bind_marker_project($params, 'listSnippets');
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

    // Load core snippets
    $coreSnippetsPath = getCoreSnippetsPath();
    $coreSnippets = listSnippetsFromPath($coreSnippetsPath, 'core');
    
    // Load global (custom) snippets
    $globalSnippetsPath = getGlobalSnippetsPath();
    $globalSnippets = listSnippetsFromPath($globalSnippetsPath, 'global');
    
    // Load project snippets
    $projectSnippets = [];
    if ($projectName) {
        $projectSnippetsPath = getProjectSnippetsPath($projectName);
        $projectSnippets = listSnippetsFromPath($projectSnippetsPath, 'project');
    }
    
    // Merge all sources
    $allSnippets = array_merge($coreSnippets, $globalSnippets, $projectSnippets);
    
    // Group by category
    $byCategory = [];
    foreach ($allSnippets as $snippet) {
        $category = $snippet['category'] ?? 'other';
        if (!isset($byCategory[$category])) {
            $byCategory[$category] = [];
        }
        $byCategory[$category][] = $snippet;
    }
    
    // Sort categories
    ksort($byCategory);
    
    // Category display names
    $categoryNames = [
        'nav' => 'Navigation',
        'forms' => 'Forms',
        'cards' => 'Cards',
        'layouts' => 'Layouts',
        'content' => 'Content',
        'lists' => 'Lists',
        'other' => 'Other'
    ];
    
    return ApiResponse::create(200, 'snippets.list_success')
        ->withMessage('Found ' . count($allSnippets) . ' snippet(s)')
        ->withData([
            'snippets' => $allSnippets,
            'byCategory' => $byCategory,
            'categoryNames' => $categoryNames,
            'counts' => [
                'total' => count($allSnippets),
                'core' => count($coreSnippets),
                'global' => count($globalSnippets),
                'project' => count($projectSnippets)
            ],
            'project' => $projectName
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listSnippets($trimParams->params(), $trimParams->additionalParams())->send();
}
