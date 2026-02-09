<?php
/**
 * deleteRoute Command
 * 
 * Deletes a route and its associated files.
 * For routes with children, requires force=true to cascade delete.
 * 
 * @method POST
 * @route /management/deleteRoute
 * @auth required (write permission)
 * 
 * @param string $route Route path (e.g., 'about' or 'guides/installation')
 * @param bool $force Force deletion of routes with children (cascade delete)
 * 
 * @return ApiResponse Deletion result
 * 
 * @example
 *   {"route": "contact"}                    → Delete single route
 *   {"route": "guides", "force": true}      → Delete guides and all children
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';

// ============================================================================
// VALIDATION
// ============================================================================

// Check required parameter
if (!array_key_exists('route', $trimParametersManagement->params())) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route path is required')
        ->withErrors([['field' => 'route', 'reason' => 'missing']])
        ->send();
}

$routePath = $trimParametersManagement->params()['route'];
$force = filter_var($trimParametersManagement->params()['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Type validation
if (is_int($routePath) || is_float($routePath)) {
    $routePath = (string) $routePath;
}

if (!is_string($routePath)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The route parameter must be a string')
        ->withErrors([['field' => 'route', 'reason' => 'invalid_type', 'expected' => 'string']])
        ->send();
}

// Normalize path
$routePath = trim(str_replace('\\', '/', $routePath), '/');

// Length validation
if (strlen($routePath) < 1 || strlen($routePath) > 200) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Route path must be between 1 and 200 characters')
        ->send();
}

// Split into segments
$segments = array_filter(explode('/', $routePath), fn($s) => $s !== '');
$segments = array_values($segments);

// Validate segments
foreach ($segments as $segment) {
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $segment)) {
        ApiResponse::create(400, 'route.invalid_segment')
            ->withMessage("Invalid segment '$segment'")
            ->send();
    }
}

// ============================================================================
// CHECK ROUTE EXISTS
// ============================================================================

$currentRoutes = ROUTES;

if (!routePathExists($segments, $currentRoutes)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Route '$routePath' does not exist")
        ->withData(['route' => $routePath])
        ->send();
}

// ============================================================================
// CHECK FOR CHILDREN
// ============================================================================

$children = getRouteChildren($segments, $currentRoutes);
$allDescendants = getAllDescendants($segments, $currentRoutes);

if (!empty($children) && !$force) {
    ApiResponse::create(400, 'route.has_children')
        ->withMessage("Route '$routePath' has child routes. Use force=true to cascade delete.")
        ->withData([
            'route' => $routePath,
            'children' => $children,
            'all_descendants' => $allDescendants,
            'total_routes_to_delete' => count($allDescendants) + 1
        ])
        ->send();
}

// ============================================================================
// COLLECT FILES TO DELETE
// ============================================================================

$pagesDir = PROJECT_PATH . '/templates/pages';
$jsonDir = PROJECT_PATH . '/templates/model/json/pages';

// Routes to delete (self + all descendants)
$routesToDelete = array_merge([$segments], 
    array_map(fn($path) => array_filter(explode('/', $path)), $allDescendants)
);

$filesToDelete = [];
$dirsToDelete = [];

foreach ($routesToDelete as $routeSegments) {
    $path = implode('/', $routeSegments);
    $name = end($routeSegments);
    
    // Determine file location (check both patterns)
    // Pattern 1: path/name.php (leaf)
    $leafPhp = $pagesDir . '/' . $path . '.php';
    // Pattern 2: path/name/name.php (branch)
    $branchPhp = $pagesDir . '/' . $path . '/' . $name . '.php';
    
    if (file_exists($branchPhp)) {
        $filesToDelete[] = $branchPhp;
        $dirsToDelete[] = $pagesDir . '/' . $path;
    } elseif (file_exists($leafPhp)) {
        $filesToDelete[] = $leafPhp;
    }
    
    // JSON files
    $leafJson = $jsonDir . '/' . $path . '.json';
    $branchJson = $jsonDir . '/' . $path . '/' . $name . '.json';
    
    if (file_exists($branchJson)) {
        $filesToDelete[] = $branchJson;
        $dirsToDelete[] = $jsonDir . '/' . $path;
    } elseif (file_exists($leafJson)) {
        $filesToDelete[] = $leafJson;
    }
}

// ============================================================================
// DELETE FILES
// ============================================================================

$deletedFiles = [];
$failedFiles = [];

foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        if (@unlink($file)) {
            $deletedFiles[] = str_replace(PROJECT_PATH, '', $file);
        } else {
            $failedFiles[] = str_replace(PROJECT_PATH, '', $file);
        }
    }
}

// Delete directories (deepest first, only if empty)
$dirsToDelete = array_unique($dirsToDelete);
usort($dirsToDelete, fn($a, $b) => substr_count($b, '/') - substr_count($a, '/')); // Deepest first

$deletedDirs = [];
foreach ($dirsToDelete as $dir) {
    if (is_dir($dir)) {
        // Only delete if empty
        $contents = array_diff(scandir($dir), ['.', '..']);
        if (empty($contents)) {
            if (@rmdir($dir)) {
                $deletedDirs[] = str_replace(PROJECT_PATH, '', $dir);
            }
        }
    }
}

// ============================================================================
// UPDATE ROUTES.PHP
// ============================================================================

$newRoutes = removeRouteFromStructure($segments, $currentRoutes);
$routesContent = "<?php return " . varExportNested($newRoutes) . "; ?>";

if (file_put_contents(ROUTES_PATH, $routesContent, LOCK_EX) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to update routes file')
        ->send();
}

// Invalidate opcache
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(ROUTES_PATH, true);
}

// ============================================================================
// CLEAN UP ALIASES
// ============================================================================

$aliasesFile = PROJECT_PATH . '/data/aliases.json';
$deletedAliases = [];

if (file_exists($aliasesFile)) {
    $aliases = json_decode(file_get_contents($aliasesFile), true) ?: [];
    
    // Find aliases pointing to deleted routes
    foreach ($routesToDelete as $routeSegments) {
        $targetPath = '/' . implode('/', $routeSegments);
        
        foreach ($aliases as $aliasPath => $aliasInfo) {
            if ($aliasInfo['target'] === $targetPath || 
                strpos($aliasInfo['target'], $targetPath . '/') === 0) {
                $deletedAliases[] = $aliasPath;
                unset($aliases[$aliasPath]);
            }
        }
    }
    
    if (!empty($deletedAliases)) {
        file_put_contents($aliasesFile, json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// ============================================================================
// CLEAN UP ROUTE LAYOUT SETTINGS
// ============================================================================

require_once SECURE_FOLDER_PATH . '/src/classes/RouteLayoutManager.php';
$layoutManager = new RouteLayoutManager();

// Remove layout entries for all deleted routes
$deletedLayoutRoutes = [];
foreach ($routesToDelete as $routeSegments) {
    $routeToRemove = implode('/', $routeSegments);
    if ($layoutManager->hasExplicitLayout($routeToRemove)) {
        $layoutManager->removeLayout($routeToRemove);
        $deletedLayoutRoutes[] = $routeToRemove;
    }
}

// ============================================================================
// SUCCESS RESPONSE
// ============================================================================

ApiResponse::create(200, 'route.deleted')
    ->withMessage("Route '$routePath' deleted successfully" . (!empty($allDescendants) ? " (and " . count($allDescendants) . " children)" : ""))
    ->withData([
        'route' => $routePath,
        'deleted_routes' => array_merge([$routePath], $allDescendants),
        'deleted_files' => $deletedFiles,
        'deleted_directories' => $deletedDirs,
        'failed_files' => $failedFiles,
        'aliases_removed' => $deletedAliases
    ])
    ->send();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Check if route path exists in nested structure
 */
function routePathExists(array $segments, array $routes): bool {
    $current = $routes;
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    return true;
}

/**
 * Get direct children of a route
 */
function getRouteChildren(array $segments, array $routes): array {
    $current = $routes;
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            return [];
        }
        $current = $current[$segment];
    }
    return array_keys($current);
}

/**
 * Get all descendant paths (recursive)
 */
function getAllDescendants(array $segments, array $routes, string $prefix = ''): array {
    $current = $routes;
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            return [];
        }
        $current = $current[$segment];
    }
    
    $basePath = implode('/', $segments);
    $descendants = [];
    
    foreach ($current as $childName => $childRoutes) {
        $childPath = $basePath . '/' . $childName;
        $descendants[] = $childPath;
        
        // Recurse for grandchildren
        $childSegments = array_merge($segments, [$childName]);
        $grandchildren = getAllDescendants($childSegments, $routes);
        $descendants = array_merge($descendants, $grandchildren);
    }
    
    return $descendants;
}

/**
 * Remove route from nested structure
 */
function removeRouteFromStructure(array $segments, array $routes): array {
    if (count($segments) === 1) {
        unset($routes[$segments[0]]);
        return $routes;
    }
    
    // Navigate to parent and remove child
    $key = array_shift($segments);
    if (isset($routes[$key])) {
        $routes[$key] = removeRouteFromStructure($segments, $routes[$key]);
    }
    
    return $routes;
}

/**
 * Export array with proper formatting
 */
function varExportNested(array $array, int $indent = 0): string {
    if (empty($array)) {
        return '[]';
    }
    
    $spaces = str_repeat('    ', $indent);
    $innerSpaces = str_repeat('    ', $indent + 1);
    
    $lines = ["["];
    foreach ($array as $key => $value) {
        $exportedKey = var_export($key, true);
        $exportedValue = is_array($value) ? varExportNested($value, $indent + 1) : var_export($value, true);
        $lines[] = "{$innerSpaces}{$exportedKey} => {$exportedValue},";
    }
    $lines[] = "{$spaces}]";
    
    return implode("\n", $lines);
}
