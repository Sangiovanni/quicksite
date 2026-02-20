<?php
/**
 * cascadeCleanupHelpers.php - Cascade cleanup when deleting routes, APIs, or endpoints
 * 
 * Removes orphaned references from:
 * - page-events.json (page events referencing deleted routes or API endpoints)
 * - Element-level interactions in JSON structures ({{call:fetch:@api/endpoint}} on nodes)
 * 
 * Included by: deleteRoute, deleteApi, editApi (deleteEndpoint)
 * Prefixed with underscore to indicate it's not a command itself.
 * 
 * @since February 20, 2026
 */

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// =============================================================================
// PAGE EVENTS CLEANUP
// =============================================================================

/**
 * Remove all page events for a given route (when the route/page is deleted).
 * Simply removes the top-level key from page-events.json.
 * 
 * @param string|array $routeNames Single route name or array of route names to remove
 * @return array Summary of what was cleaned
 */
function cleanPageEventsForRoutes($routeNames): array {
    if (!is_array($routeNames)) {
        $routeNames = [$routeNames];
    }
    
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    if (!file_exists($eventsFile)) {
        return ['cleaned' => [], 'file' => 'not_found'];
    }

    $content = @file_get_contents($eventsFile);
    $allEvents = json_decode($content, true);
    if (!is_array($allEvents) || empty($allEvents)) {
        return ['cleaned' => [], 'file' => 'empty'];
    }

    $cleaned = [];
    foreach ($routeNames as $routeName) {
        if (isset($allEvents[$routeName])) {
            $cleaned[] = $routeName;
            unset($allEvents[$routeName]);
        }
    }

    if (!empty($cleaned)) {
        file_put_contents($eventsFile, json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return ['cleaned' => $cleaned];
}

/**
 * Remove page events that reference a specific API or endpoint via {{call:fetch:@apiId/...}}.
 * 
 * When deleting an entire API:   $endpointId = null → removes all @apiId/* references
 * When deleting one endpoint:    $endpointId = 'listFile' → removes only @apiId/listFile references
 * 
 * @param string $apiId The API identifier
 * @param string|null $endpointId Specific endpoint, or null for entire API
 * @return array Summary: cleaned pages, removed calls
 */
function cleanPageEventsForApiEndpoint(string $apiId, ?string $endpointId = null): array {
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    if (!file_exists($eventsFile)) {
        return ['removedCalls' => [], 'file' => 'not_found'];
    }

    $content = @file_get_contents($eventsFile);
    $allEvents = json_decode($content, true);
    if (!is_array($allEvents) || empty($allEvents)) {
        return ['removedCalls' => [], 'file' => 'empty'];
    }

    // Build regex pattern to match relevant {{call:fetch:@apiId/...}} entries
    $escapedApiId = preg_quote($apiId, '/');
    if ($endpointId !== null) {
        // Match only specific endpoint: {{call:fetch:@apiId/endpointId}} or {{call:fetch:@apiId/endpointId,...}}
        $escapedEndpointId = preg_quote($endpointId, '/');
        $pattern = '/\{\{call:fetch:@' . $escapedApiId . '\/' . $escapedEndpointId . '(?:,[^}]*)?\}\}/';
    } else {
        // Match any endpoint under this API: {{call:fetch:@apiId/...}}
        $pattern = '/\{\{call:fetch:@' . $escapedApiId . '\/[^}]+\}\}/';
    }

    $removedCalls = [];
    $modified = false;

    foreach ($allEvents as $pageName => &$pageEvents) {
        foreach ($pageEvents as $eventName => &$calls) {
            if (!is_array($calls)) continue;
            
            $filteredCalls = [];
            foreach ($calls as $call) {
                if (preg_match($pattern, $call)) {
                    $removedCalls[] = [
                        'page' => $pageName,
                        'event' => $eventName,
                        'call' => $call
                    ];
                    $modified = true;
                } else {
                    $filteredCalls[] = $call;
                }
            }
            $calls = $filteredCalls;
        }
        unset($calls);

        // Clean up empty event arrays
        foreach ($pageEvents as $eventName => $calls) {
            if (empty($calls)) {
                unset($pageEvents[$eventName]);
            }
        }
    }
    unset($pageEvents);

    // Clean up empty page entries
    foreach ($allEvents as $pageName => $pageEvents) {
        if (empty($pageEvents)) {
            unset($allEvents[$pageName]);
        }
    }

    if ($modified) {
        file_put_contents(
            $eventsFile,
            json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return ['removedCalls' => $removedCalls];
}

// =============================================================================
// ELEMENT-LEVEL INTERACTION CLEANUP
// =============================================================================

/**
 * Remove element-level interactions referencing a specific API/endpoint from all JSON structures.
 * Scans pages, components, menu, and footer.
 * 
 * @param string $apiId The API identifier
 * @param string|null $endpointId Specific endpoint, or null for entire API
 * @return array Summary of cleaned interactions
 */
function cleanInteractionsForApiEndpoint(string $apiId, ?string $endpointId = null): array {
    // Build regex pattern (same logic as page events)
    $escapedApiId = preg_quote($apiId, '/');
    if ($endpointId !== null) {
        $escapedEndpointId = preg_quote($endpointId, '/');
        $pattern = '/\{\{call:fetch:@' . $escapedApiId . '\/' . $escapedEndpointId . '(?:,[^}]*)?\}\}/';
    } else {
        $pattern = '/\{\{call:fetch:@' . $escapedApiId . '\/[^}]+\}\}/';
    }

    $results = [
        'modifiedFiles' => [],
        'removedInteractions' => []
    ];

    $jsonBase = PROJECT_PATH . '/templates/model/json';

    // --- 1. Scan all pages ---
    $allRoutes = flattenRoutes(ROUTES);
    foreach ($allRoutes as $routePath) {
        $jsonFile = resolvePageJsonPath($routePath);
        if ($jsonFile && file_exists($jsonFile)) {
            $cleaned = _cleanInteractionsInFile($jsonFile, $pattern, "page:$routePath");
            if ($cleaned) {
                $results['modifiedFiles'][] = $jsonFile;
                $results['removedInteractions'] = array_merge($results['removedInteractions'], $cleaned);
            }
        }
    }

    // --- 2. Scan all components ---
    $componentsDir = $jsonBase . '/components';
    if (is_dir($componentsDir)) {
        $componentFiles = glob($componentsDir . '/*.json');
        foreach ($componentFiles as $componentFile) {
            $compName = basename($componentFile, '.json');
            $cleaned = _cleanInteractionsInFile($componentFile, $pattern, "component:$compName");
            if ($cleaned) {
                $results['modifiedFiles'][] = $componentFile;
                $results['removedInteractions'] = array_merge($results['removedInteractions'], $cleaned);
            }
        }
    }

    // Also check component subdirectories (componentName/componentName.json pattern)
    $componentsAltDir = PROJECT_PATH . '/components';
    if (is_dir($componentsAltDir)) {
        $dirs = glob($componentsAltDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $compName = basename($dir);
            $compFile = $dir . '/' . $compName . '.json';
            if (file_exists($compFile)) {
                $cleaned = _cleanInteractionsInFile($compFile, $pattern, "component:$compName");
                if ($cleaned) {
                    $results['modifiedFiles'][] = $compFile;
                    $results['removedInteractions'] = array_merge($results['removedInteractions'], $cleaned);
                }
            }
        }
    }

    // --- 3. Scan menu and footer ---
    foreach (['menu', 'footer'] as $structType) {
        $file = $jsonBase . '/' . $structType . '.json';
        if (file_exists($file)) {
            $cleaned = _cleanInteractionsInFile($file, $pattern, $structType);
            if ($cleaned) {
                $results['modifiedFiles'][] = $file;
                $results['removedInteractions'] = array_merge($results['removedInteractions'], $cleaned);
            }
        }
    }

    return $results;
}

/**
 * Scan a single JSON structure file, remove matching {{call:fetch:...}} from all nodes.
 * 
 * @param string $filePath Path to the JSON file
 * @param string $pattern Regex pattern matching the {{call:...}} to remove
 * @param string $context Description for logging (e.g. "page:home")
 * @return array|null Removed interactions, or null if nothing changed
 */
function _cleanInteractionsInFile(string $filePath, string $pattern, string $context): ?array {
    $content = @file_get_contents($filePath);
    if ($content === false) return null;

    $structure = json_decode($content, true);
    if (!is_array($structure)) return null;

    $removed = [];
    $modified = false;

    // Get all event attribute names we need to check
    $allEvents = _getAllEventAttributeNames();

    // Walk the structure recursively
    _walkNodes($structure, $pattern, $allEvents, $context, $removed, $modified);

    if ($modified) {
        file_put_contents(
            $filePath,
            json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    return $modified ? $removed : null;
}

/**
 * Recursively walk nodes and clean matching interactions from event attributes.
 * 
 * @param array &$nodes The structure array (modified in place)
 * @param string $pattern Regex pattern to match
 * @param array $eventNames All possible event attribute names
 * @param string $context Context label for logging
 * @param array &$removed Accumulator for removed interactions
 * @param bool &$modified Whether any changes were made
 */
function _walkNodes(array &$nodes, string $pattern, array $eventNames, string $context, array &$removed, bool &$modified): void {
    foreach ($nodes as &$node) {
        if (!is_array($node)) continue;

        // Check params for event attributes
        if (isset($node['params']) && is_array($node['params'])) {
            foreach ($eventNames as $eventAttr) {
                if (!isset($node['params'][$eventAttr])) continue;

                $value = $node['params'][$eventAttr];
                if (!is_string($value)) continue;

                // Check if this attribute contains any matching pattern
                if (preg_match($pattern, $value)) {
                    // Remove matching {{call:...}} entries
                    $newValue = preg_replace($pattern, '', $value);
                    // Clean up extra spaces from removal
                    $newValue = trim(preg_replace('/\s+/', ' ', $newValue));

                    $removed[] = [
                        'context' => $context,
                        'event' => $eventAttr,
                        'removed' => $value,
                        'remaining' => $newValue
                    ];

                    if (empty($newValue)) {
                        // No interactions left — remove the event attribute entirely
                        unset($node['params'][$eventAttr]);
                    } else {
                        $node['params'][$eventAttr] = $newValue;
                    }
                    $modified = true;
                }
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            _walkNodes($node['children'], $pattern, $eventNames, $context, $removed, $modified);
        }
    }
    unset($node);
}

/**
 * Get a flat list of all possible event attribute names (universal + tag-specific).
 */
function _getAllEventAttributeNames(): array {
    // Include _interactionHelpers.php constants if not already defined
    if (!defined('UNIVERSAL_EVENTS')) {
        require_once __DIR__ . '/interactionHelpers.php';
    }

    $all = UNIVERSAL_EVENTS;
    foreach (TAG_SPECIFIC_EVENTS as $tag => $events) {
        $all = array_merge($all, $events);
    }
    return array_values(array_unique($all));
}
