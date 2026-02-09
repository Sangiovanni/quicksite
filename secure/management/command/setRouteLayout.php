<?php
/**
 * setRouteLayout Command
 * 
 * Configures visibility of menu and footer for a specific route.
 * Supports inheritance (children inherit from nearest ancestor with explicit settings)
 * and propagation (apply settings to all descendants).
 * 
 * @method POST
 * @route /management/setRouteLayout
 * @auth required (editor+ permission)
 * 
 * @param string $route Route path to configure
 * @param bool $menu (optional) Whether to show menu (header) - true/false
 * @param bool $footer (optional) Whether to show footer - true/false
 * @param bool $propagate (optional) Apply settings to all descendants - default false
 * 
 * @return ApiResponse Success with applied settings
 * 
 * @example
 *   {"route": "landing", "menu": false, "footer": false}
 *   {"route": "app", "menu": false, "propagate": true}
 *   {"route": "docs/api", "menu": true, "footer": true, "propagate": false}
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RouteLayoutManager.php';
require_once SECURE_FOLDER_PATH . '/src/functions/String.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// ============================================================================
// CONSTANTS
// ============================================================================

const MAX_PATH_LENGTH = 200;

// ============================================================================
// VALIDATION
// ============================================================================

$params = $trimParametersManagement->params();

// Required: route
$route = $params['route'] ?? null;

if ($route === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route path is required')
        ->withErrors([['field' => 'route', 'reason' => 'missing']])
        ->send();
}

// Type validation for route
if (is_int($route) || is_float($route)) {
    $route = (string) $route;
}

if (!is_string($route)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The route parameter must be a string')
        ->withErrors([['field' => 'route', 'reason' => 'invalid_type', 'expected' => 'string']])
        ->send();
}

// Normalize route path
$route = trim(str_replace('\\', '/', $route), '/');

// Length validation
if (strlen($route) < 1 || strlen($route) > MAX_PATH_LENGTH) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('Route path must be between 1 and ' . MAX_PATH_LENGTH . ' characters')
        ->withErrors([['field' => 'route', 'min_length' => 1, 'max_length' => MAX_PATH_LENGTH]])
        ->send();
}

// Optional: menu (boolean)
$menu = null;
if (array_key_exists('menu', $params)) {
    $menuRaw = $params['menu'];
    if (is_bool($menuRaw)) {
        $menu = $menuRaw;
    } elseif (is_string($menuRaw)) {
        $menuLower = strtolower($menuRaw);
        if ($menuLower === 'true' || $menuLower === '1') {
            $menu = true;
        } elseif ($menuLower === 'false' || $menuLower === '0') {
            $menu = false;
        } else {
            ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The menu parameter must be a boolean (true/false)')
                ->withErrors([['field' => 'menu', 'reason' => 'invalid_type', 'expected' => 'boolean']])
                ->send();
        }
    } elseif (is_int($menuRaw)) {
        $menu = $menuRaw !== 0;
    } else {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The menu parameter must be a boolean (true/false)')
            ->withErrors([['field' => 'menu', 'reason' => 'invalid_type', 'expected' => 'boolean']])
            ->send();
    }
}

// Optional: footer (boolean)
$footer = null;
if (array_key_exists('footer', $params)) {
    $footerRaw = $params['footer'];
    if (is_bool($footerRaw)) {
        $footer = $footerRaw;
    } elseif (is_string($footerRaw)) {
        $footerLower = strtolower($footerRaw);
        if ($footerLower === 'true' || $footerLower === '1') {
            $footer = true;
        } elseif ($footerLower === 'false' || $footerLower === '0') {
            $footer = false;
        } else {
            ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The footer parameter must be a boolean (true/false)')
                ->withErrors([['field' => 'footer', 'reason' => 'invalid_type', 'expected' => 'boolean']])
                ->send();
        }
    } elseif (is_int($footerRaw)) {
        $footer = $footerRaw !== 0;
    } else {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The footer parameter must be a boolean (true/false)')
            ->withErrors([['field' => 'footer', 'reason' => 'invalid_type', 'expected' => 'boolean']])
            ->send();
    }
}

// Optional: propagate (boolean, default false)
$propagate = false;
if (array_key_exists('propagate', $params)) {
    $propagateRaw = $params['propagate'];
    if (is_bool($propagateRaw)) {
        $propagate = $propagateRaw;
    } elseif (is_string($propagateRaw)) {
        $propagateLower = strtolower($propagateRaw);
        if ($propagateLower === 'true' || $propagateLower === '1') {
            $propagate = true;
        } elseif ($propagateLower === 'false' || $propagateLower === '0') {
            $propagate = false;
        } else {
            ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The propagate parameter must be a boolean (true/false)')
                ->withErrors([['field' => 'propagate', 'reason' => 'invalid_type', 'expected' => 'boolean']])
                ->send();
        }
    } elseif (is_int($propagateRaw)) {
        $propagate = $propagateRaw !== 0;
    } else {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The propagate parameter must be a boolean (true/false)')
            ->withErrors([['field' => 'propagate', 'reason' => 'invalid_type', 'expected' => 'boolean']])
            ->send();
    }
}

// At least one of menu or footer must be specified
if ($menu === null && $footer === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('At least one of menu or footer must be specified')
        ->withErrors([['reason' => 'missing', 'hint' => 'Provide menu and/or footer parameter']])
        ->send();
}

// ============================================================================
// VERIFY ROUTE EXISTS
// ============================================================================

// Check if route exists in ROUTES constant
$allRoutes = flattenRoutes(ROUTES);

// Special case: allow 'home' to be configured
if ($route !== 'home' && !in_array($route, $allRoutes)) {
    ApiResponse::create(404, 'route.not_found')
        ->withMessage("Route '$route' does not exist")
        ->withErrors([['field' => 'route', 'reason' => 'not_found']])
        ->send();
}

// ============================================================================
// APPLY LAYOUT SETTINGS
// ============================================================================

$layoutManager = new RouteLayoutManager();

// Get current effective layout for comparison
$beforeLayout = $layoutManager->getEffectiveLayout($route);

// Set the layout (only provided values will be updated)
$layoutManager->setLayout($route, $menu, $footer);

// If propagate is true, apply to all descendants
$propagatedRoutes = [];
if ($propagate) {
    $propagateResult = $layoutManager->propagateLayout($route, $menu, $footer, $allRoutes);
    $propagatedRoutes = $propagateResult['affected'] ?? [];
}

// Get the new effective layout
$afterLayout = $layoutManager->getEffectiveLayout($route);

// ============================================================================
// RESPONSE
// ============================================================================

$response = ApiResponse::create(200, 'route.layout_updated')
    ->withMessage("Layout settings updated for route '$route'")
    ->withData([
        'route' => $route,
        'layout' => $afterLayout,
        'explicit' => $layoutManager->hasExplicitLayout($route)
    ]);

if ($propagate && count($propagatedRoutes) > 0) {
    $response->withField('propagated_to', $propagatedRoutes);
    $response->withField('propagated_count', count($propagatedRoutes));
}

$response->send();
