<?php
/**
 * analyzeReachability - Find orphan (unreachable) routes via BFS from home
 * 
 * @method GET
 * @url /management/analyzeReachability
 * @auth required
 * @permission read
 * 
 * Performs a BFS traversal starting from the home route, following internal
 * links found in page structures, menu, and footer. Routes not visited
 * during traversal are reported as orphans (unreachable from navigation).
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Recursively extract internal hrefs from a JSON structure node tree.
 * 
 * Detects two patterns:
 *   1. tag:'a' with params.href  (direct anchor)
 *   2. component with data.href  (component reference like footer-link)
 * 
 * Only keeps internal links (starting with '/'), normalizes '/' to 'home'.
 */
function extractInternalLinks(array $nodes, array &$links, int $depth = 0, int $maxDepth = 50): void {
    if ($depth > $maxDepth) return;

    foreach ($nodes as $node) {
        if (!is_array($node)) continue;

        // Pattern A: <a href="/route">
        if (isset($node['tag']) && $node['tag'] === 'a' && isset($node['params']['href'])) {
            $href = $node['params']['href'];
            if (is_string($href) && str_starts_with($href, '/') && !str_starts_with($href, '//')) {
                $route = ltrim($href, '/');
                // Skip anchor-only or empty (home)
                if ($route === '' || $route === '/') {
                    $links[] = 'home';
                } elseif (!str_contains($route, '#')) {
                    // Strip query string
                    $route = explode('?', $route, 2)[0];
                    $links[] = $route;
                }
            }
        }

        // Pattern B: component with data.href (e.g. footer-link)
        if (isset($node['component']) && isset($node['data']['href'])) {
            $href = $node['data']['href'];
            if (is_string($href) && str_starts_with($href, '/') && !str_starts_with($href, '//')) {
                $route = ltrim($href, '/');
                if ($route === '' || $route === '/') {
                    $links[] = 'home';
                } elseif (!str_contains($route, '#')) {
                    $route = explode('?', $route, 2)[0];
                    $links[] = $route;
                }
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            extractInternalLinks($node['children'], $links, $depth + 1, $maxDepth);
        }
        // Some nodes store content in 'content' array
        if (isset($node['content']) && is_array($node['content'])) {
            extractInternalLinks($node['content'], $links, $depth + 1, $maxDepth);
        }
    }
}

/**
 * Load a JSON structure file and extract all internal links from it.
 */
function getLinksFromStructure(string $filePath): array {
    $structure = loadJsonStructure($filePath);
    if (!is_array($structure)) return [];
    
    $links = [];
    extractInternalLinks($structure, $links);
    return array_values(array_unique($links));
}

function __command_analyzeReachability(array $params = [], array $urlParams = []): ApiResponse {
    // 1. Get all routes
    $routes = require ROUTES_PATH;
    $allRoutes = flattenRoutes($routes);
    
    if (empty($allRoutes)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No routes defined')
            ->withData([
                'total_routes' => 0,
                'reachable' => [],
                'orphans' => [],
                'graph' => []
            ]);
    }

    // 2. Load route-layout config (determines menu/footer visibility per route)
    $layoutConfigPath = PROJECT_PATH . '/config/route-layout.json';
    $layoutConfig = [];
    if (file_exists($layoutConfigPath)) {
        $raw = json_decode(file_get_contents($layoutConfigPath), true);
        if (is_array($raw) && isset($raw['routes'])) {
            $layoutConfig = $raw['routes'];
        }
    }

    // 3. Extract links from menu and footer (global navigation)
    $menuLinks = getLinksFromStructure(PROJECT_PATH . '/templates/model/json/menu.json');
    $footerLinks = getLinksFromStructure(PROJECT_PATH . '/templates/model/json/footer.json');

    // 4. Build adjacency list: route -> [reachable routes]
    $graph = [];
    foreach ($allRoutes as $route) {
        $pageLinks = [];

        // Page content links
        $jsonPath = resolvePageJsonPath($route);
        if ($jsonPath !== null) {
            $pageLinks = getLinksFromStructure($jsonPath);
        }

        // Determine if menu/footer are visible on this route
        $routeLayout = $layoutConfig[$route] ?? [];
        $hasMenu = $routeLayout['menu'] ?? true;     // default: visible
        $hasFooter = $routeLayout['footer'] ?? true;  // default: visible

        $reachableFromHere = $pageLinks;
        if ($hasMenu) {
            $reachableFromHere = array_merge($reachableFromHere, $menuLinks);
        }
        if ($hasFooter) {
            $reachableFromHere = array_merge($reachableFromHere, $footerLinks);
        }

        // Filter to only known routes and deduplicate
        $reachableFromHere = array_unique($reachableFromHere);
        $reachableFromHere = array_values(array_intersect($reachableFromHere, $allRoutes));

        $graph[$route] = $reachableFromHere;
    }

    // 5. BFS from 'home'
    $startRoute = 'home';
    $visited = [];
    $queue = [];

    if (in_array($startRoute, $allRoutes, true)) {
        $queue[] = $startRoute;
        $visited[$startRoute] = true;
    }

    while (!empty($queue)) {
        $current = array_shift($queue);
        $neighbors = $graph[$current] ?? [];
        foreach ($neighbors as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $visited[$neighbor] = true;
                $queue[] = $neighbor;
            }
        }
    }

    $reachable = array_keys($visited);
    sort($reachable);
    $orphans = array_values(array_diff($allRoutes, $reachable));
    sort($orphans);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(empty($orphans) 
            ? 'All routes are reachable from home' 
            : count($orphans) . ' orphan route(s) found')
        ->withData([
            'total_routes' => count($allRoutes),
            'reachable_count' => count($reachable),
            'orphan_count' => count($orphans),
            'reachable' => $reachable,
            'orphans' => $orphans,
            'graph' => $graph,
            'global_links' => [
                'menu' => $menuLinks,
                'footer' => $footerLinks
            ]
        ]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_analyzeReachability()->send();
}
