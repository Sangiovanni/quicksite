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

/**
 * Re-point page events from @apiId/fromId to @apiId/toId when an endpoint is
 * renamed. Mirrors cleanPageEventsForApiEndpoint but rewrites the reference in
 * place (preserving any trailing args) instead of removing the call.
 *
 * @param string $apiId   The API identifier
 * @param string $fromId  Old endpoint id
 * @param string $toId    New endpoint id
 * @return array Summary: renamedCalls
 */
function renamePageEventsForApiEndpoint(string $apiId, string $fromId, string $toId): array {
    $eventsFile = PROJECT_PATH . '/data/page-events.json';
    if (!file_exists($eventsFile)) {
        return ['renamedCalls' => [], 'file' => 'not_found'];
    }

    $allEvents = json_decode(@file_get_contents($eventsFile), true);
    if (!is_array($allEvents) || empty($allEvents)) {
        return ['renamedCalls' => [], 'file' => 'empty'];
    }

    $pattern = _buildFetchRefRenamePattern($apiId, $fromId);
    $replacement = '${1}@' . $apiId . '/' . $toId;

    $renamedCalls = [];
    $modified = false;

    foreach ($allEvents as $pageName => &$pageEvents) {
        foreach ($pageEvents as $eventName => &$calls) {
            if (!is_array($calls)) continue;
            foreach ($calls as $i => $call) {
                if (!is_string($call)) continue;
                $newCall = preg_replace($pattern, $replacement, $call);
                if ($newCall !== $call) {
                    $calls[$i] = $newCall;
                    $renamedCalls[] = [
                        'page' => $pageName,
                        'event' => $eventName,
                        'from' => $call,
                        'to' => $newCall
                    ];
                    $modified = true;
                }
            }
        }
    }
    unset($pageEvents, $calls);

    if ($modified) {
        file_put_contents(
            $eventsFile,
            json_encode($allEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return ['renamedCalls' => $renamedCalls];
}

// =============================================================================
// ELEMENT-LEVEL INTERACTION CLEANUP / RENAME
// =============================================================================

/**
 * Build the pattern matching a full {{call:fetch:@apiId/endpointId(,args)?}}
 * reference (or every endpoint under the API when $endpointId is null).
 * Used by the delete cascade to strip whole calls.
 */
function _buildFetchRefPattern(string $apiId, ?string $endpointId): string {
    $escapedApiId = preg_quote($apiId, '/');
    if ($endpointId !== null) {
        $escapedEndpointId = preg_quote($endpointId, '/');
        return '/\{\{call:fetch:@' . $escapedApiId . '\/' . $escapedEndpointId . '(?:,[^}]*)?\}\}/';
    }
    return '/\{\{call:fetch:@' . $escapedApiId . '\/[^}]+\}\}/';
}

/**
 * Build the pattern that captures the `{{call:fetch:` prefix immediately
 * before @apiId/fromId at a reference boundary (next char is ',' or '}').
 * Used by the rename cascade to rewrite the id while keeping any trailing
 * args and the closing braces intact. Group 1 is the prefix to re-emit.
 */
function _buildFetchRefRenamePattern(string $apiId, string $fromId): string {
    $escaped = preg_quote('@' . $apiId . '/' . $fromId, '/');
    return '/(\{\{call:fetch:)' . $escaped . '(?=[,}])/';
}

/**
 * Remove element-level interactions referencing a specific API/endpoint from
 * all JSON structures (pages, components, menu, footer).
 *
 * @param string $apiId The API identifier
 * @param string|null $endpointId Specific endpoint, or null for entire API
 * @return array { modifiedFiles: string[], removedInteractions: array[] }
 */
function cleanInteractionsForApiEndpoint(string $apiId, ?string $endpointId = null): array {
    $pattern = _buildFetchRefPattern($apiId, $endpointId);

    // Transform: strip every matching {{call:fetch:...}} from the value.
    $transform = function (string $value) use ($pattern): ?array {
        if (!preg_match($pattern, $value)) return null;
        $newValue = trim(preg_replace('/\s+/', ' ', preg_replace($pattern, '', $value)));
        return ['value' => $newValue, 'record' => ['removed' => $value, 'remaining' => $newValue]];
    };

    return _eachStructureFile(function (string $filePath, string $context) use ($transform) {
        return _transformInteractionFile($filePath, $transform, $context);
    }, 'removedInteractions');
}

/**
 * Re-point element-level interactions from @apiId/fromId to @apiId/toId when an
 * endpoint is renamed. Mirrors cleanInteractionsForApiEndpoint but rewrites the
 * reference in place (keeping trailing args) instead of removing the call.
 *
 * @param string $apiId  The API identifier
 * @param string $fromId Old endpoint id
 * @param string $toId   New endpoint id
 * @return array { modifiedFiles: string[], renamedInteractions: array[] }
 */
function renameInteractionsForApiEndpoint(string $apiId, string $fromId, string $toId): array {
    $pattern = _buildFetchRefRenamePattern($apiId, $fromId);
    $replacement = '${1}@' . $apiId . '/' . $toId;

    // Transform: rewrite the @apiId/fromId reference to @apiId/toId in place.
    $transform = function (string $value) use ($pattern, $replacement): ?array {
        if (!preg_match($pattern, $value)) return null;
        $newValue = preg_replace($pattern, $replacement, $value);
        if ($newValue === $value) return null;
        return ['value' => $newValue, 'record' => ['from' => $value, 'to' => $newValue]];
    };

    return _eachStructureFile(function (string $filePath, string $context) use ($transform) {
        return _transformInteractionFile($filePath, $transform, $context);
    }, 'renamedInteractions');
}

/**
 * Enumerate every editable JSON structure file (pages, components in both the
 * flat and subdir layouts, menu, footer) and run $process($filePath, $context)
 * on each. Centralises the file set walked by the clean and rename cascades.
 *
 * @param callable $process    fn(string $filePath, string $context): ?array  (change records or null)
 * @param string   $recordsKey Key under which to collect the change records
 * @return array { modifiedFiles: string[], <recordsKey>: array[] }
 */
function _eachStructureFile(callable $process, string $recordsKey): array {
    $results = ['modifiedFiles' => [], $recordsKey => []];
    $jsonBase = PROJECT_PATH . '/templates/model/json';

    $apply = function (string $file, string $context) use (&$results, $process, $recordsKey) {
        if (!file_exists($file)) return;
        $records = $process($file, $context);
        if ($records) {
            $results['modifiedFiles'][] = $file;
            $results[$recordsKey] = array_merge($results[$recordsKey], $records);
        }
    };

    // 1. Pages
    foreach (flattenRoutes(ROUTES) as $routePath) {
        $jsonFile = resolvePageJsonPath($routePath);
        if ($jsonFile) $apply($jsonFile, "page:$routePath");
    }

    // 2. Components (flat dir: components/<name>.json)
    $componentsDir = $jsonBase . '/components';
    if (is_dir($componentsDir)) {
        foreach (glob($componentsDir . '/*.json') as $componentFile) {
            $apply($componentFile, 'component:' . basename($componentFile, '.json'));
        }
    }

    // 2b. Components (subdir layout: components/<name>/<name>.json)
    $componentsAltDir = PROJECT_PATH . '/components';
    if (is_dir($componentsAltDir)) {
        foreach (glob($componentsAltDir . '/*', GLOB_ONLYDIR) as $dir) {
            $compName = basename($dir);
            $apply($dir . '/' . $compName . '.json', "component:$compName");
        }
    }

    // 3. Menu + footer
    foreach (['menu', 'footer'] as $structType) {
        $apply($jsonBase . '/' . $structType . '.json', $structType);
    }

    return $results;
}

/**
 * Walk a single JSON structure file and apply $valueTransform to every event-
 * attribute value found on any node. $valueTransform(string $value): ?array
 * returns ['value' => newValue, 'record' => recordData] when it changes the
 * value, or null to leave it untouched. A resulting empty string removes the
 * event attribute entirely.
 *
 * @return array|null Change records (each merged with context + event), or null if unchanged
 */
function _transformInteractionFile(string $filePath, callable $valueTransform, string $context): ?array {
    $content = @file_get_contents($filePath);
    if ($content === false) return null;

    $structure = json_decode($content, true);
    if (!is_array($structure)) return null;

    $records = [];
    $modified = false;
    _walkNodesTransform($structure, _getAllEventAttributeNames(), $valueTransform, $context, $records, $modified);

    if ($modified) {
        file_put_contents(
            $filePath,
            json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        return $records;
    }
    return null;
}

/**
 * Recursively walk nodes and apply $valueTransform to each event-attribute
 * value. Shared by the clean (remove) and rename (rewrite) cascades.
 *
 * @param array    &$nodes         The structure array (modified in place)
 * @param array    $eventNames     All possible event attribute names
 * @param callable $valueTransform fn(string $value): ?array  (['value'=>..,'record'=>..] or null)
 * @param string   $context        Context label for the records
 * @param array    &$records       Accumulator for change records
 * @param bool     &$modified      Whether any changes were made
 */
function _walkNodesTransform(array &$nodes, array $eventNames, callable $valueTransform, string $context, array &$records, bool &$modified): void {
    foreach ($nodes as &$node) {
        if (!is_array($node)) continue;

        if (isset($node['params']) && is_array($node['params'])) {
            foreach ($eventNames as $eventAttr) {
                if (!isset($node['params'][$eventAttr])) continue;
                $value = $node['params'][$eventAttr];
                if (!is_string($value)) continue;

                $result = $valueTransform($value);
                if ($result === null) continue;

                $newValue = $result['value'];
                $records[] = array_merge(['context' => $context, 'event' => $eventAttr], $result['record']);

                if ($newValue === '') {
                    // Nothing left — drop the event attribute entirely.
                    unset($node['params'][$eventAttr]);
                } else {
                    $node['params'][$eventAttr] = $newValue;
                }
                $modified = true;
            }
        }

        if (isset($node['children']) && is_array($node['children'])) {
            _walkNodesTransform($node['children'], $eventNames, $valueTransform, $context, $records, $modified);
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
