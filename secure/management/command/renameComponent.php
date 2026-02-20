<?php
/**
 * renameComponent - Rename a component and update all references
 * 
 * @method POST
 * @url /management/renameComponent
 * @auth required
 * @permission editStructure
 * 
 * Renames a component file and updates all references in:
 * - Pages (all JSON files in pages/)
 * - Menu (menu.json)
 * - Footer (footer.json)
 * - Other components (other files in components/)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Recursively update component references in a structure
 * 
 * @param array|null $node Structure node
 * @param string $oldName Old component name
 * @param string $newName New component name
 * @param int &$count Counter for updated references
 * @return array|null Modified node
 */
function updateComponentReferences($node, string $oldName, string $newName, int &$count): ?array {
    if (!is_array($node)) {
        return $node;
    }
    
    // Update component reference if it matches
    if (isset($node['component']) && $node['component'] === $oldName) {
        $node['component'] = $newName;
        $count++;
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $index => $child) {
            $node['children'][$index] = updateComponentReferences($child, $oldName, $newName, $count);
        }
    }
    
    return $node;
}

/**
 * Update component references in a JSON file
 * 
 * @param string $filePath Path to JSON file
 * @param string $oldName Old component name
 * @param string $newName New component name
 * @return array ['updated' => bool, 'count' => int, 'error' => string|null]
 */
function updateFileReferences(string $filePath, string $oldName, string $newName): array {
    if (!file_exists($filePath)) {
        return ['updated' => false, 'count' => 0, 'error' => null];
    }
    
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return ['updated' => false, 'count' => 0, 'error' => 'Could not read file'];
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['updated' => false, 'count' => 0, 'error' => 'Invalid JSON'];
    }
    
    $count = 0;
    
    // Structure can be single node or array of nodes
    if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
        $structure = updateComponentReferences($structure, $oldName, $newName, $count);
    } else if (is_array($structure)) {
        foreach ($structure as $index => $node) {
            $structure[$index] = updateComponentReferences($node, $oldName, $newName, $count);
        }
    }
    
    if ($count > 0) {
        $newContent = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($filePath, $newContent) === false) {
            return ['updated' => false, 'count' => $count, 'error' => 'Could not write file'];
        }
    }
    
    return ['updated' => $count > 0, 'count' => $count, 'error' => null];
}

/**
 * Recursively get all page JSON files
 */
function getAllPageFilesForRename(string $dir, string $prefix = ''): array {
    $pages = [];
    
    if (!is_dir($dir)) {
        return $pages;
    }
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . '/' . $item;
        
        if (is_dir($path)) {
            $subPrefix = $prefix ? $prefix . '/' . $item : $item;
            $pages = array_merge($pages, getAllPageFilesForRename($path, $subPrefix));
        } else if (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            $baseName = pathinfo($item, PATHINFO_FILENAME);
            $pageName = $prefix ? $prefix . '/' . $baseName : $baseName;
            $pages[] = [
                'name' => $pageName,
                'file' => $path
            ];
        }
    }
    
    return $pages;
}

/**
 * Command function for renameComponent
 * 
 * @param array $params Body parameters: oldName, newName
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_renameComponent(array $params = [], array $urlParams = []): ApiResponse {
    $oldName = $params['oldName'] ?? $params['from'] ?? null;
    $newName = $params['newName'] ?? $params['to'] ?? null;
    
    // Validate required parameters
    if (!$oldName) {
        return ApiResponse::create(400, 'validation.missing_parameter')
            ->withMessage('Old component name is required')
            ->withData(['missing' => 'oldName']);
    }
    
    if (!$newName) {
        return ApiResponse::create(400, 'validation.missing_parameter')
            ->withMessage('New component name is required')
            ->withData(['missing' => 'newName']);
    }
    
    // Validate names don't contain slashes
    if (strpos($oldName, '/') !== false || strpos($newName, '/') !== false) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Component names cannot contain slashes');
    }
    
    // Validate new name format (alphanumeric with hyphens)
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $newName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Component name must start with a letter and contain only letters, numbers, and hyphens')
            ->withData(['invalid' => $newName]);
    }
    
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    $oldFile = $componentsDir . '/' . $oldName . '.json';
    $newFile = $componentsDir . '/' . $newName . '.json';
    
    // Check old component exists
    if (!file_exists($oldFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Component '{$oldName}' does not exist")
            ->withData(['component' => $oldName]);
    }
    
    // Check new name doesn't already exist
    if (file_exists($newFile)) {
        return ApiResponse::create(409, 'file.already_exists')
            ->withMessage("Component '{$newName}' already exists")
            ->withData(['component' => $newName]);
    }
    
    // Rename the file first
    if (!rename($oldFile, $newFile)) {
        return ApiResponse::create(500, 'server.file_rename_failed')
            ->withMessage("Failed to rename component file")
            ->withData(['from' => $oldFile, 'to' => $newFile]);
    }
    
    $jsonDir = PROJECT_PATH . '/templates/model/json';
    $updatedFiles = [];
    $errors = [];
    $totalReferences = 0;
    
    // Update menu.json
    $result = updateFileReferences($jsonDir . '/menu.json', $oldName, $newName);
    if ($result['count'] > 0) {
        $updatedFiles[] = ['file' => 'menu.json', 'references' => $result['count']];
        $totalReferences += $result['count'];
    }
    if ($result['error']) {
        $errors[] = ['file' => 'menu.json', 'error' => $result['error']];
    }
    
    // Update footer.json
    $result = updateFileReferences($jsonDir . '/footer.json', $oldName, $newName);
    if ($result['count'] > 0) {
        $updatedFiles[] = ['file' => 'footer.json', 'references' => $result['count']];
        $totalReferences += $result['count'];
    }
    if ($result['error']) {
        $errors[] = ['file' => 'footer.json', 'error' => $result['error']];
    }
    
    // Update all pages
    $pagesDir = $jsonDir . '/pages';
    $pageFiles = getAllPageFilesForRename($pagesDir);
    
    foreach ($pageFiles as $pageInfo) {
        $result = updateFileReferences($pageInfo['file'], $oldName, $newName);
        if ($result['count'] > 0) {
            $updatedFiles[] = ['file' => 'pages/' . $pageInfo['name'] . '.json', 'references' => $result['count']];
            $totalReferences += $result['count'];
        }
        if ($result['error']) {
            $errors[] = ['file' => 'pages/' . $pageInfo['name'] . '.json', 'error' => $result['error']];
        }
    }
    
    // Update other components
    if (is_dir($componentsDir)) {
        $componentFiles = glob($componentsDir . '/*.json');
        foreach ($componentFiles as $file) {
            $compName = basename($file, '.json');
            // Skip the renamed component itself
            if ($compName === $newName) {
                continue;
            }
            
            $result = updateFileReferences($file, $oldName, $newName);
            if ($result['count'] > 0) {
                $updatedFiles[] = ['file' => 'components/' . $compName . '.json', 'references' => $result['count']];
                $totalReferences += $result['count'];
            }
            if ($result['error']) {
                $errors[] = ['file' => 'components/' . $compName . '.json', 'error' => $result['error']];
            }
        }
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Component renamed from '{$oldName}' to '{$newName}'" . 
            ($totalReferences > 0 ? " ({$totalReferences} references updated)" : ''))
        ->withData([
            'oldName' => $oldName,
            'newName' => $newName,
            'references_updated' => $totalReferences,
            'files_updated' => $updatedFiles,
            'errors' => empty($errors) ? null : $errors
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_renameComponent($trimParams->params(), $trimParams->additionalParams())->send();
}
