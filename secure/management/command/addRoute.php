<?php
/**
 * addRoute Command
 * 
 * Creates a new route with support for nested paths (up to 5 levels deep).
 * 
 * @method POST
 * @route /management/addRoute
 * @auth required (write permission)
 * 
 * @param string $route Route path (e.g., 'about' or 'guides/installation')
 * 
 * @return ApiResponse Creation result
 * 
 * @example
 *   {"route": "contact"}           → /contact
 *   {"route": "guides/getting-started"} → /guides/getting-started
 *   {"route": "docs/api/auth"}     → /docs/api/auth
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// ============================================================================
// CONSTANTS
// ============================================================================

const MAX_ROUTE_DEPTH = 5;
const MAX_SEGMENT_LENGTH = 50;
const MAX_PATH_LENGTH = 200;

// ============================================================================
// VALIDATION
// ============================================================================

$params = $trimParametersManagement->params();

// Support both 'route' (full form) and 'name' (shorthand for simple routes)
$routePath = $params['route'] ?? $params['name'] ?? null;

// Check for parent parameter - prepend to route if provided
$parent = $params['parent'] ?? null;
if ($parent !== null && $parent !== '') {
    // Normalize parent (trim slashes)
    $parent = trim(str_replace('\\', '/', $parent), '/');
    if ($routePath !== null) {
        $routePath = $parent . '/' . $routePath;
    }
}

// Check required parameter
if ($routePath === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route path is required')
        ->withErrors([['field' => 'route', 'reason' => 'missing', 'hint' => 'Use "route" or "name" parameter']])
        ->send();
}

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

// Normalize path (trim slashes, convert backslashes)
$routePath = trim(str_replace('\\', '/', $routePath), '/');

// Length validation
if (strlen($routePath) < 1 || strlen($routePath) > MAX_PATH_LENGTH) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Route path must be between 1 and ' . MAX_PATH_LENGTH . ' characters')
        ->withErrors([['field' => 'route', 'min_length' => 1, 'max_length' => MAX_PATH_LENGTH]])
        ->send();
}

// Split into segments
$segments = array_filter(explode('/', $routePath), fn($s) => $s !== '');
$segments = array_values($segments);

// Depth validation
if (count($segments) > MAX_ROUTE_DEPTH) {
    ApiResponse::create(400, 'route.too_deep')
        ->withMessage('Route path exceeds maximum depth of ' . MAX_ROUTE_DEPTH . ' levels')
        ->withErrors([['field' => 'route', 'depth' => count($segments), 'max_depth' => MAX_ROUTE_DEPTH]])
        ->send();
}

// Validate each segment
foreach ($segments as $index => $segment) {
    // Length check
    if (strlen($segment) > MAX_SEGMENT_LENGTH) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage("Segment '$segment' exceeds maximum length of " . MAX_SEGMENT_LENGTH)
            ->withErrors([['field' => 'route', 'segment' => $segment, 'max_length' => MAX_SEGMENT_LENGTH]])
            ->send();
    }
    
    // Format check (lowercase, numbers, hyphens only)
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $segment)) {
        ApiResponse::create(400, 'route.invalid_segment')
            ->withMessage("Invalid segment '$segment'. Use lowercase letters, numbers, and hyphens (no leading/trailing hyphens)")
            ->withErrors([['field' => 'route', 'segment' => $segment, 'pattern' => 'a-z, 0-9, hyphens']])
            ->send();
    }
}

$routeName = end($segments); // The final segment name

// ============================================================================
// CHECK IF ROUTE EXISTS
// ============================================================================

$currentRoutes = ROUTES;

// Check if full path already exists
if (routePathExists($segments, $currentRoutes)) {
    ApiResponse::create(400, 'route.already_exists')
        ->withMessage("Route '$routePath' already exists")
        ->withData(['route' => $routePath])
        ->send();
}

// Check parent routes exist (for nested routes)
if (count($segments) > 1) {
    $parentSegments = array_slice($segments, 0, -1);
    if (!routePathExists($parentSegments, $currentRoutes)) {
        ApiResponse::create(400, 'route.parent_not_found')
            ->withMessage("Parent route '" . implode('/', $parentSegments) . "' does not exist. Create it first.")
            ->withData([
                'route' => $routePath,
                'missing_parent' => implode('/', $parentSegments)
            ])
            ->send();
    }
}

// ============================================================================
// FILE PATH RESOLUTION
// ============================================================================

$pagesDir = PROJECT_PATH . '/templates/pages';
$jsonDir = PROJECT_PATH . '/templates/model/json/pages';

// Determine PHP file path based on convention
// If parent route gains its first child, we need to move parent's file
$phpFilePath = resolveNewRoutePhpPath($segments, $currentRoutes, $pagesDir);
$jsonFilePath = resolveNewRouteJsonPath($segments, $jsonDir);

// NOTE: Parent restructuring removed - all routes now use folder structure
// Parent is already at parent/parent.php, no move needed

// ============================================================================
// CREATE DIRECTORY STRUCTURE
// ============================================================================

$createdDirs = [];
$createdFiles = [];

try {
    // Create PHP directory if needed
    $phpDir = dirname($phpFilePath);
    if (!is_dir($phpDir)) {
        if (!mkdir($phpDir, 0755, true)) {
            throw new Exception("Failed to create directory: $phpDir");
        }
        $createdDirs[] = $phpDir;
    }
    
    // Create JSON directory if needed
    $jsonFileDir = dirname($jsonFilePath);
    if (!is_dir($jsonFileDir)) {
        if (!mkdir($jsonFileDir, 0755, true)) {
            throw new Exception("Failed to create directory: $jsonFileDir");
        }
        $createdDirs[] = $jsonFileDir;
    }
    
    // ========================================================================
    // CREATE NEW ROUTE FILES
    // ========================================================================
    
    // Generate PHP template (use full route path, not just the name)
    $phpContent = generate_page_template($routePath);
    if (file_put_contents($phpFilePath, $phpContent, LOCK_EX) === false) {
        throw new Exception("Failed to write PHP file: $phpFilePath");
    }
    $createdFiles[] = $phpFilePath;
    
    // Generate JSON structure
    $jsonContent = generate_page_json($routeName);
    if (file_put_contents($jsonFilePath, $jsonContent, LOCK_EX) === false) {
        throw new Exception("Failed to write JSON file: $jsonFilePath");
    }
    $createdFiles[] = $jsonFilePath;
    
    // ========================================================================
    // UPDATE ROUTES.PHP
    // ========================================================================
    
    $newRoutes = addRouteToStructure($segments, $currentRoutes);
    $routesContent = "<?php return " . varExportNested($newRoutes) . "; ?>";
    
    if (file_put_contents(ROUTES_PATH, $routesContent, LOCK_EX) === false) {
        throw new Exception("Failed to update routes file");
    }
    
    // Invalidate opcache
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(ROUTES_PATH, true);
    }
    
} catch (Exception $e) {
    // Cleanup on failure
    foreach (array_reverse($createdFiles) as $file) {
        @unlink($file);
    }
    foreach (array_reverse($createdDirs) as $dir) {
        @rmdir($dir); // Only removes empty dirs
    }
    
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}

// ============================================================================
// SUCCESS RESPONSE
// ============================================================================

$responseData = [
    'route' => $routePath,
    'segments' => $segments,
    'depth' => count($segments),
    'php_file' => str_replace(PROJECT_PATH, '', $phpFilePath),
    'json_file' => str_replace(PROJECT_PATH, '', $jsonFilePath),
];

ApiResponse::create(201, 'route.created')
    ->withMessage("Route '$routePath' created successfully")
    ->withData($responseData)
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
 * Get children of a route path
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
 * Add route to nested structure
 */
function addRouteToStructure(array $segments, array $routes): array {
    $result = $routes;
    $current = &$result;
    
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            $current[$segment] = [];
        }
        $current = &$current[$segment];
    }
    
    return $result;
}

/**
 * Resolve PHP file path for new route
 * Convention: ALL routes use folder structure - route/route.php
 */
function resolveNewRoutePhpPath(array $segments, array $routes, string $pagesDir): string {
    $routePath = implode('/', $segments);
    $routeName = end($segments);
    
    // All routes use folder structure: path/name/name.php
    return $pagesDir . '/' . $routePath . '/' . $routeName . '.php';
}

/**
 * Resolve JSON file path for new route
 * Convention: ALL routes use folder structure - route/route.json
 */
function resolveNewRouteJsonPath(array $segments, string $jsonDir): string {
    $routePath = implode('/', $segments);
    $routeName = end($segments);
    
    // All routes use folder structure: path/name/name.json
    return $jsonDir . '/' . $routePath . '/' . $routeName . '.json';
}

/**
 * Export array with proper formatting for nested routes
 */
function varExportNested(array $array, int $indent = 0): string {
    if (empty($array)) {
        return '[]';
    }
    
    $isAssoc = array_keys($array) !== range(0, count($array) - 1);
    
    if (!$isAssoc) {
        // Simple indexed array - shouldn't happen for routes but handle it
        return var_export($array, true);
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
