<?php
/**
 * policyPageHelpers.php — shared route + page-write machinery for the generated
 * policy pages (cookie policy, privacy policy). Extracted from
 * generateCookiePolicy so both generators share one implementation of route
 * validation, cascade-create, routes.php persistence, schema regen, and leaf
 * page writing.
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';

/**
 * Validate + normalise a policy-page route.
 * @return array{route:string, segments:array, error:?array} error = {code,message}
 */
function policyValidateRoute(string $route): array {
    $route = trim(str_replace('\\', '/', $route), '/');
    $segments = array_values(array_filter(explode('/', $route), fn($s) => $s !== ''));
    if (count($segments) < 1 || count($segments) > 5) {
        return ['route' => $route, 'segments' => [], 'error' => ['code' => 'route.invalid', 'message' => 'Route must have between 1 and 5 segments']];
    }
    foreach ($segments as $seg) {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $seg)) {
            return ['route' => $route, 'segments' => [], 'error' => ['code' => 'route.invalid_segment', 'message' => "Invalid route segment '$seg'. Use lowercase letters, numbers and hyphens (no path parameters)."]];
        }
    }
    return ['route' => $route, 'segments' => $segments, 'error' => null];
}

/** Whether a route chain exists in a ROUTES-shaped array. */
function policyRouteExists(array $segments, array $routes): bool {
    $cur = $routes;
    foreach ($segments as $s) {
        if (!is_array($cur) || !isset($cur[$s])) return false;
        $cur = $cur[$s];
    }
    return true;
}

/** Add a route chain to a ROUTES-shaped array (returns the new array). */
function policyAddRoute(array $segments, array $routes): array {
    $result = $routes;
    $cur = &$result;
    foreach ($segments as $s) {
        if (!isset($cur[$s]) || !is_array($cur[$s])) $cur[$s] = [];
        $cur = &$cur[$s];
    }
    return $result;
}

/** Write a default empty page (php bootstrap + empty json) for an ancestor route. */
function _policyWriteAncestorPage(array $segments): void {
    $name = end($segments);
    $phpPath = PROJECT_PATH . '/templates/pages/' . implode('/', $segments) . '/' . $name . '.php';
    $jsonPath = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments) . '/' . $name . '.json';
    foreach ([dirname($phpPath), dirname($jsonPath)] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    if (!file_exists($phpPath)) file_put_contents($phpPath, generate_page_template(implode('/', $segments)), LOCK_EX);
    if (!file_exists($jsonPath)) file_put_contents($jsonPath, json_encode([['tag' => 'main', 'params' => ['class' => 'container'], 'children' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Cascade-create any missing route in the chain (writing default ancestor pages),
 * then persist routes.php + regen the client route schema. No-op for an existing
 * leaf. Safe to call after a route-exists check.
 */
function policyCreateRoute(array $segments): void {
    $routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
    $newRoutes = $routes;
    $changed = false;
    for ($i = 1; $i <= count($segments); $i++) {
        $chain = array_slice($segments, 0, $i);
        if (!policyRouteExists($chain, $newRoutes)) {
            $newRoutes = policyAddRoute($chain, $newRoutes);
            $changed = true;
            if ($i < count($segments)) {
                _policyWriteAncestorPage($chain);
            }
        }
    }
    if ($changed && defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, '<?php return ' . varExportNested($newRoutes) . '; ?>', LOCK_EX);
        if (function_exists('opcache_invalidate')) opcache_invalidate(ROUTES_PATH, true);
        $schemaPath = PUBLIC_CONTENT_PATH . '/scripts/qs-route-schema.js';
        if (function_exists('writeRoutesMetaFile')) writeRoutesMetaFile($newRoutes, $schemaPath);
    }
}

/** Remove a route chain from a ROUTES-shaped array (returns the new array). */
function policyRemoveRoute(array $segments, array $routes): array {
    if (count($segments) === 1) {
        unset($routes[$segments[0]]);
        return $routes;
    }
    $head = array_shift($segments);
    if (isset($routes[$head]) && is_array($routes[$head])) {
        $routes[$head] = policyRemoveRoute($segments, $routes[$head]);
    }
    return $routes;
}

/**
 * Delete a leaf route: remove it from ROUTES, persist routes.php + regen schema,
 * delete the leaf page files, and prune now-empty folders. Parent routes stay.
 */
function policyDeleteRoute(array $segments): void {
    $newRoutes = policyRemoveRoute($segments, defined('ROUTES') && is_array(ROUTES) ? ROUTES : []);
    if (defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, '<?php return ' . varExportNested($newRoutes) . '; ?>', LOCK_EX);
        if (function_exists('opcache_invalidate')) opcache_invalidate(ROUTES_PATH, true);
    }
    if (function_exists('writeRoutesMetaFile')) {
        writeRoutesMetaFile($newRoutes, PUBLIC_CONTENT_PATH . '/scripts/qs-route-schema.js');
    }
    $name = end($segments);
    $jsonDir = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments);
    $phpDir  = PROJECT_PATH . '/templates/pages/' . implode('/', $segments);
    @unlink($jsonDir . '/' . $name . '.json');
    @unlink($phpDir . '/' . $name . '.php');
    @rmdir($jsonDir);
    @rmdir($phpDir);
}

/**
 * Write the leaf page: a php bootstrap (only if absent — preserves an existing
 * one) + the json structure (always overwritten with the freshly generated tree).
 * @return bool whether the json structure was written.
 */
function policyWriteLeaf(array $segments, array $structure): bool {
    $name = end($segments);
    $phpPath = PROJECT_PATH . '/templates/pages/' . implode('/', $segments) . '/' . $name . '.php';
    $jsonPath = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments) . '/' . $name . '.json';
    foreach ([dirname($phpPath), dirname($jsonPath)] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    if (!file_exists($phpPath)) {
        file_put_contents($phpPath, generate_page_template(implode('/', $segments)), LOCK_EX);
    }
    return file_put_contents($jsonPath, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) !== false;
}
