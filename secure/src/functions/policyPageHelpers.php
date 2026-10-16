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
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';
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
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $seg)) {
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

/**
 * Write a new page (php bootstrap + its structure, empty as every new page starts:
 * generate_page_json()) for an ancestor route.
 * Each file passes the size check first (qs_small_write_guard); false when one is
 * refused — the command answers qs_small_write_refused().
 */
function _policyWriteAncestorPage(array $segments): bool {
    require_once __DIR__ . '/quota.php';
    $name = end($segments);
    $phpPath = PROJECT_PATH . '/templates/pages/' . implode('/', $segments) . '/' . $name . '.php';
    $jsonPath = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments) . '/' . $name . '.json';
    $json = generate_page_json($name);
    $php = generate_page_template(implode('/', $segments));
    if ((!file_exists($phpPath) && !qs_small_write_guard($phpPath, strlen($php)))
        || (!file_exists($jsonPath) && !qs_small_write_guard($jsonPath, strlen($json)))) {
        return false;
    }
    foreach ([dirname($phpPath), dirname($jsonPath)] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    if (!file_exists($phpPath)) file_put_contents($phpPath, $php, LOCK_EX);
    if (!file_exists($jsonPath)) file_put_contents($jsonPath, $json, LOCK_EX);
    return true;
}

/**
 * Cascade-create any missing route in the chain (writing default ancestor pages),
 * then persist routes.php + regen the client route schema. No-op for an existing
 * leaf. Safe to call after a route-exists check. False when the size check
 * refused a file (qs_small_write_guard): what it wrote is taken back.
 */
function policyCreateRoute(array $segments): bool {
    require_once __DIR__ . '/quota.php';
    $routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
    $newRoutes = $routes;
    $changed = false;
    for ($i = 1; $i <= count($segments); $i++) {
        $chain = array_slice($segments, 0, $i);
        if (!policyRouteExists($chain, $newRoutes)) {
            $newRoutes = policyAddRoute($chain, $newRoutes);
            $changed = true;
            if ($i < count($segments) && !_policyWriteAncestorPage($chain)) {
                return false;
            }
        }
    }
    if ($changed && defined('ROUTES_PATH')) {
        $content = '<?php return ' . varExportNested($newRoutes) . '; ?>';
        if (!qs_small_write_guard(ROUTES_PATH, strlen($content))) {
            return false;
        }
        file_put_contents(ROUTES_PATH, $content, LOCK_EX);
        qs_opcache_invalidate(ROUTES_PATH);
        require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';
        // A later refusal puts routes.php back; the schema derived from it with it.
        foreach (qs_public_artifact_targets('scripts/qs-route-schema.js') as $schema) {
            qs_small_write_keep($schema);
        }
        qs_emit_route_schema($newRoutes);
    }
    return true;
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
 * False when the size check refused (it never does: a deletion only shrinks).
 */
function policyDeleteRoute(array $segments): bool {
    require_once __DIR__ . '/quota.php';
    $routes = defined('ROUTES') && is_array(ROUTES) ? ROUTES : [];
    $newRoutes = policyRemoveRoute($segments, $routes);
    $name = end($segments);
    $jsonDir = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments);
    $phpDir  = PROJECT_PATH . '/templates/pages/' . implode('/', $segments);
    $content = '<?php return ' . varExportNested($newRoutes) . '; ?>';
    // Guarded like every write of a chain, so a refusal later in the command can
    // put the route and its page files back (qs_small_write_guard).
    if ((defined('ROUTES_PATH') && !qs_small_write_guard(ROUTES_PATH, strlen($content)))
        || !qs_small_write_guard($jsonDir . '/' . $name . '.json', null)
        || !qs_small_write_guard($phpDir . '/' . $name . '.php', null)) {
        return false;
    }
    if (defined('ROUTES_PATH')) {
        file_put_contents(ROUTES_PATH, $content, LOCK_EX);
        qs_opcache_invalidate(ROUTES_PATH);
    }
    require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';
    foreach (qs_public_artifact_targets('scripts/qs-route-schema.js') as $schema) {
        qs_small_write_keep($schema);
    }
    qs_emit_route_schema($newRoutes);
    @unlink($jsonDir . '/' . $name . '.json');
    @unlink($phpDir . '/' . $name . '.php');
    @rmdir($jsonDir);
    @rmdir($phpDir);
    return true;
}

/**
 * Write the leaf page: a php bootstrap (only if absent — preserves an existing
 * one) + the json structure (always overwritten with the freshly generated tree).
 * @return bool whether the json structure was written.
 */
function policyWriteLeaf(array $segments, array $structure): bool {
    require_once __DIR__ . '/quota.php';
    $name = end($segments);
    $phpPath = PROJECT_PATH . '/templates/pages/' . implode('/', $segments) . '/' . $name . '.php';
    $jsonPath = PROJECT_PATH . '/templates/model/json/pages/' . implode('/', $segments) . '/' . $name . '.json';
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $php = generate_page_template(implode('/', $segments));
    // The size check (qs_small_write_guard): false when refused, and the command
    // answers qs_small_write_refused().
    if ((!file_exists($phpPath) && !qs_small_write_guard($phpPath, strlen($php)))
        || !qs_small_write_guard($jsonPath, qs_json_size($structure, $flags, "\n"))) {
        return false;
    }
    foreach ([dirname($phpPath), dirname($jsonPath)] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    if (!file_exists($phpPath)) {
        file_put_contents($phpPath, $php, LOCK_EX);
    }
    return qs_json_write($jsonPath, $structure, $flags, LOCK_EX, "\n");
}
