<?php
/**
 * findComponentUsages - Find all pages and components that use a specific component
 * 
 * @method GET
 * @url /management/findComponentUsages/{componentName}
 * @auth required
 * @permission read
 * 
 * Scans all pages, menu, footer, and other components to find where a specific
 * component is used. Essential for:
 * - Knowing impact before deleting a component
 * - Understanding component dependencies
 * - Safe rename operations
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Recursively find component references in a structure
 * 
 * @param array|null $node Structure node to scan
 * @param string $componentName Component name to find
 * @param array &$found Array to collect found locations
 * @param string $path Current node path for location tracking
 */
function findComponentInStructure($node, string $componentName, array &$found, string $path = ''): void {
    if (!is_array($node)) {
        return;
    }
    
    // Check if this node references the component
    if (isset($node['component']) && $node['component'] === $componentName) {
        $found[] = $path ?: 'root';
    }
    
    // Recurse into children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $index => $child) {
            $childPath = $path ? "{$path}.{$index}" : (string)$index;
            findComponentInStructure($child, $componentName, $found, $childPath);
        }
    }
}

/**
 * Scan a JSON file for component usage
 * 
 * @param string $filePath Path to JSON file
 * @param string $componentName Component to find
 * @return array Array of node paths where component is used, empty if not used
 */
function scanFileForComponent(string $filePath, string $componentName): array {
    if (!file_exists($filePath)) {
        return [];
    }
    
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return [];
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    $found = [];
    
    // Structure can be a single node object OR an array of nodes
    if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
        // Single node object
        findComponentInStructure($structure, $componentName, $found, '0');
    } else if (is_array($structure)) {
        // Array of nodes
        foreach ($structure as $index => $node) {
            findComponentInStructure($node, $componentName, $found, (string)$index);
        }
    }
    
    return $found;
}

/**
 * Recursively get all page JSON files
 * 
 * @param string $dir Directory to scan
 * @param string $prefix Path prefix for nested pages
 * @return array Array of ['name' => pageName, 'file' => filePath]
 */
function getAllPageFiles(string $dir, string $prefix = ''): array {
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
            // Recurse into subdirectory
            $subPrefix = $prefix ? $prefix . '/' . $item : $item;
            $pages = array_merge($pages, getAllPageFiles($path, $subPrefix));
        } else if (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            // JSON file - extract page name
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
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this GET command)
 * @param array $urlParams URL segments - [0] = component name
 * @return ApiResponse
 */
function __command_findComponentUsages(array $params = [], array $urlParams = []): ApiResponse {
    // Get component name from URL
    $componentName = $urlParams[0] ?? $params['component'] ?? null;
    
    if (!$componentName) {
        return ApiResponse::create(400, 'validation.missing_parameter')
            ->withMessage('Component name is required')
            ->withData(['missing' => 'component']);
    }
    
    // Validate component exists
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    $componentFile = $componentsDir . '/' . $componentName . '.json';
    
    if (!file_exists($componentFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Component '{$componentName}' does not exist")
            ->withData(['component' => $componentName]);
    }
    
    $usages = [
        'pages' => [],
        'menu' => null,
        'footer' => null,
        'components' => []
    ];
    
    $jsonDir = PROJECT_PATH . '/templates/model/json';
    
    // Scan menu.json
    $menuFile = $jsonDir . '/menu.json';
    $menuLocations = scanFileForComponent($menuFile, $componentName);
    if (!empty($menuLocations)) {
        $usages['menu'] = [
            'type' => 'menu',
            'locations' => $menuLocations,
            'count' => count($menuLocations)
        ];
    }
    
    // Scan footer.json
    $footerFile = $jsonDir . '/footer.json';
    $footerLocations = scanFileForComponent($footerFile, $componentName);
    if (!empty($footerLocations)) {
        $usages['footer'] = [
            'type' => 'footer',
            'locations' => $footerLocations,
            'count' => count($footerLocations)
        ];
    }
    
    // Scan all pages
    $pagesDir = $jsonDir . '/pages';
    $pageFiles = getAllPageFiles($pagesDir);
    
    foreach ($pageFiles as $pageInfo) {
        $locations = scanFileForComponent($pageInfo['file'], $componentName);
        if (!empty($locations)) {
            $usages['pages'][] = [
                'name' => $pageInfo['name'],
                'type' => 'page',
                'locations' => $locations,
                'count' => count($locations)
            ];
        }
    }
    
    // Scan other components
    if (is_dir($componentsDir)) {
        $componentFiles = glob($componentsDir . '/*.json');
        foreach ($componentFiles as $file) {
            $otherName = basename($file, '.json');
            
            // Skip self
            if ($otherName === $componentName) {
                continue;
            }
            
            $locations = scanFileForComponent($file, $componentName);
            if (!empty($locations)) {
                $usages['components'][] = [
                    'name' => $otherName,
                    'type' => 'component',
                    'locations' => $locations,
                    'count' => count($locations)
                ];
            }
        }
    }
    
    // Calculate totals
    $totalUsages = 0;
    $usedIn = [];
    
    if ($usages['menu']) {
        $totalUsages += $usages['menu']['count'];
        $usedIn[] = 'menu';
    }
    if ($usages['footer']) {
        $totalUsages += $usages['footer']['count'];
        $usedIn[] = 'footer';
    }
    foreach ($usages['pages'] as $page) {
        $totalUsages += $page['count'];
        $usedIn[] = 'page:' . $page['name'];
    }
    foreach ($usages['components'] as $comp) {
        $totalUsages += $comp['count'];
        $usedIn[] = 'component:' . $comp['name'];
    }
    
    $isUsed = $totalUsages > 0;
    $canDelete = empty($usages['components']); // Can delete only if no other components use it
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage($isUsed 
            ? "Component '{$componentName}' is used in {$totalUsages} location(s)"
            : "Component '{$componentName}' is not used anywhere"
        )
        ->withData([
            'component' => $componentName,
            'is_used' => $isUsed,
            'can_delete' => $canDelete,
            'delete_warning' => !$canDelete 
                ? 'Cannot delete: component is used by other components'
                : ($isUsed ? 'Warning: component is used in pages/menu/footer' : null),
            'total_usages' => $totalUsages,
            'used_in' => $usedIn,
            'usages' => $usages
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_findComponentUsages($trimParams->params(), $trimParams->additionalParams())->send();
}
