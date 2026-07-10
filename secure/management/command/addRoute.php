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

    // Format check. Two valid shapes (beta.8 A1):
    //   1. Literal segment: lowercase letters / digits / hyphens
    //      (no leading or trailing hyphen). Existing convention.
    //   2. Param segment: ':' + lowercase identifier
    //      ([a-z_][a-z0-9_]*). Lowercase-only matches the literal-
    //      segment convention; mixed-case param names get rejected
    //      so the route author and URL author share the same rule.
    $isLiteral = (bool) preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $segment);
    $isParam   = (bool) preg_match('/^:[a-z_][a-z0-9_]*$/', $segment);
    if (!$isLiteral && !$isParam) {
        ApiResponse::create(400, 'route.invalid_segment')
            ->withMessage("Invalid segment '$segment'. Use lowercase letters, numbers, hyphens for literals (no leading/trailing hyphens), or ':name' for a path parameter (lowercase identifier).")
            ->withErrors([['field' => 'route', 'segment' => $segment, 'pattern' => "a-z 0-9 - OR :name"]])
            ->send();
    }
}

$routeName = end($segments); // The final segment name

// ============================================================================
// CHECK SPECIAL PAGES (404, 500, etc. — exist as templates, not routes)
// ============================================================================

if (count($segments) === 1 && in_array($segments[0], SPECIAL_PAGES, true)) {
    ApiResponse::create(200, 'route.special_page')
        ->withMessage("'{$segments[0]}' is a special page that already exists as a template — no route entry needed")
        ->withData(['route' => $segments[0], 'special_page' => true])
        ->send();
}

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

// ============================================================================
// CONFLICT DETECTION (beta.8 A1)
// Detects param-route sibling situations BEFORE saving so the warnings
// reference the original routes structure (not one that includes the
// new segment as its own sibling). Non-blocking — route still saves.
// ============================================================================

$conflictWarnings = detectRouteConflicts($segments, $currentRoutes);

// ============================================================================
// FILE PATH RESOLUTION
// ============================================================================

$pagesDir = PROJECT_PATH . '/templates/pages';
$jsonDir = PROJECT_PATH . '/templates/model/json/pages';

// Cascade-create missing parent routes (for nested routes)
$cascadeCreated = [];
if (count($segments) > 1) {
    for ($i = 1; $i < count($segments); $i++) {
        $parentSegments = array_slice($segments, 0, $i);
        if (!routePathExists($parentSegments, $currentRoutes)) {
            $parentPath = implode('/', $parentSegments);
            $parentName = end($parentSegments);

            // Create parent PHP file
            $parentPhpPath = resolveNewRoutePhpPath($parentSegments, $currentRoutes, $pagesDir);
            $parentPhpDir = dirname($parentPhpPath);
            if (!is_dir($parentPhpDir)) {
                mkdir($parentPhpDir, 0755, true);
            }
            file_put_contents($parentPhpPath, generate_page_template($parentPath), LOCK_EX);

            // Create parent JSON file
            $parentJsonPath = resolveNewRouteJsonPath($parentSegments, $jsonDir);
            $parentJsonDir = dirname($parentJsonPath);
            if (!is_dir($parentJsonDir)) {
                mkdir($parentJsonDir, 0755, true);
            }
            file_put_contents($parentJsonPath, generate_page_json($parentName), LOCK_EX);

            // Add parent to routes structure
            $currentRoutes = addRouteToStructure($parentSegments, $currentRoutes);
            $cascadeCreated[] = $parentPath;
        }
    }
}

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

    // Beta.8 A1 Build Slice 1 — regenerate the client-side routes
    // schema so qs.js (Slice 2) sees the new route immediately
    // without a full rebuild. Mirrors editApi's qs-api-config.js
    // regen pattern. File is project-scoped public/scripts/qs-route-schema.js.
    require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';
    qs_emit_route_schema($newRoutes);

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

if (!empty($cascadeCreated)) {
    $responseData['cascade_created'] = $cascadeCreated;
}

// Beta.8 A1 — surface non-blocking conflict warnings to the caller.
// Each warning carries a `type` (i18n key for client localisation) plus
// machine-readable details + an EN `message` fallback. Slice 3 (admin
// form) will render these inline.
if (!empty($conflictWarnings)) {
    $responseData['warnings'] = $conflictWarnings;
}

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
 * Beta.8 A1 — ':slug' segments sanitised to '__slug' for filesystem.
 */
function resolveNewRoutePhpPath(array $segments, array $routes, string $pagesDir): string {
    $fsSegments = array_map('paramRouteSegmentToFs', $segments);
    $routePath  = implode('/', $fsSegments);
    $routeName  = end($fsSegments);
    return $pagesDir . '/' . $routePath . '/' . $routeName . '.php';
}

/**
 * Resolve JSON file path for new route
 * Convention: ALL routes use folder structure - route/route.json
 * Beta.8 A1 — ':slug' segments sanitised to '__slug' for filesystem.
 */
function resolveNewRouteJsonPath(array $segments, string $jsonDir): string {
    $fsSegments = array_map('paramRouteSegmentToFs', $segments);
    $routePath  = implode('/', $fsSegments);
    $routeName  = end($fsSegments);
    return $jsonDir . '/' . $routePath . '/' . $routeName . '.json';
}

/**
 * Detect conflicts when adding a param route at a level that already
 * has siblings. Returns structured warnings[] (NOT blocking — the route
 * still saves). Locked design 2026-06-04, BETA8_PARAMETERISED_ROUTES.md
 * slice 4.
 *
 * Two warning shapes:
 *  - 'route.warning.param_shadows_exact_siblings' — when adding a
 *    ':name' segment at a depth that has existing literal siblings.
 *    Specificity rule keeps the literals safe at runtime; warn so the
 *    user confirms the catch-all is intended.
 *  - 'route.warning.duplicate_param_at_depth' — when adding a ':name'
 *    at a depth that already has a different ':other'. Declaration
 *    order resolves at runtime, but ambiguous — warn.
 *
 * Each warning carries:
 *   - type    : machine-readable i18n key
 *   - message : EN literal fallback (client localises via type later)
 *   - …       : structured data (siblings, existing, new, depth)
 *
 * @param array $segments  Segments of the route being added
 * @param array $routes    Current routes structure
 * @return array Array of warning objects
 */
function detectRouteConflicts(array $segments, array $routes): array {
    $warnings = [];
    $current  = $routes;
    $depth    = 0;

    foreach ($segments as $segment) {
        $isParamSegment = strlen($segment) > 1 && $segment[0] === ':';

        if ($isParamSegment) {
            // Look at this level's siblings BEFORE descending — these
            // are the routes that already exist at the same depth as
            // the new ':name' segment.
            $siblings = is_array($current) ? array_keys($current) : [];

            // a) Exact literal siblings → 'param_shadows_exact_siblings'
            $literalSiblings = array_values(array_filter(
                $siblings,
                fn(string $k): bool => strlen($k) === 0 || $k[0] !== ':'
            ));
            if (!empty($literalSiblings)) {
                $siblingsList = implode(', ', array_map(fn($s) => '/' . $s, $literalSiblings));
                $warnings[] = [
                    'type'     => 'route.warning.param_shadows_exact_siblings',
                    'depth'    => $depth,
                    'paramName'=> substr($segment, 1),
                    'siblings' => $literalSiblings,
                    'message'  => "Param route '$segment' at depth $depth will catch URLs other than the existing exact siblings: $siblingsList. Specificity keeps those safe at runtime, but verify the catch-all is intended.",
                ];
            }

            // b) Other ':name' siblings → 'duplicate_param_at_depth'
            foreach ($siblings as $k) {
                if (strlen($k) > 1 && $k[0] === ':' && $k !== $segment) {
                    $warnings[] = [
                        'type'     => 'route.warning.duplicate_param_at_depth',
                        'depth'    => $depth,
                        'existing' => $k,
                        'new'      => $segment,
                        'message'  => "Another param segment '$k' already exists at depth $depth. The new '$segment' is ambiguous — declaration order in routes.php decides which name captures (existing '$k' wins). Consider renaming or removing one.",
                    ];
                    break;  // one warning per duplicate is enough
                }
            }
        }

        // Descend if this segment exists (so deeper levels see the
        // right siblings); otherwise stop the walk — there are no
        // siblings to detect beyond this point for new branches.
        if (is_array($current) && isset($current[$segment])) {
            $current = $current[$segment];
        } else {
            break;
        }
        $depth++;
    }

    return $warnings;
}
