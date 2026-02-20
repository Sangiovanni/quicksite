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

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_listSnippets(array $params = [], array $urlParams = []): ApiResponse {
    // Get project name from params or use active project
    $projectName = $params['project'] ?? null;
    
    if (!$projectName) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }
    
    // Load core snippets
    $coreSnippetsPath = getSnippetsPath(null);
    $coreSnippets = listSnippetsFromPath($coreSnippetsPath, true);
    
    // Load project snippets
    $projectSnippets = [];
    if ($projectName) {
        $projectSnippetsPath = getSnippetsPath($projectName);
        $projectSnippets = listSnippetsFromPath($projectSnippetsPath, false);
    }
    
    // Merge and organize by category
    $allSnippets = array_merge($coreSnippets, $projectSnippets);
    
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
