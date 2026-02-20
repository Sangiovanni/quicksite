<?php
/**
 * listInteractions - List all interactions ({{call:...}}) on a specific node
 * 
 * @method GET
 * @url /management/listInteractions/{structType}/{nodeId}
 * @auth required
 * @permission read
 * 
 * Returns all event→function bindings on the specified node, plus
 * available events filtered by the element's tag type.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// Include shared interaction helpers (constants, parseCallSyntax, etc.)
require_once SECURE_FOLDER_PATH . '/src/functions/interactionHelpers.php';

/**
 * Extract all interactions from a node's params
 */
function extractInteractionsFromNode(array $node): array {
    $interactions = [];
    $params = $node['params'] ?? [];
    
    // Check all event attributes in params
    $eventAttributes = array_merge(UNIVERSAL_EVENTS, 
        array_merge(...array_values(TAG_SPECIFIC_EVENTS))
    );
    
    foreach ($params as $key => $value) {
        if (in_array($key, $eventAttributes) && is_string($value)) {
            $parsed = parseCallSyntax($value);
            foreach ($parsed as $interaction) {
                $interaction['event'] = $key;
                $interactions[] = $interaction;
            }
        }
    }
    
    return $interactions;
}

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments [structType, ...nodeIdParts]
 * @return ApiResponse
 */
function __command_listInteractions(array $params = [], array $urlParams = []): ApiResponse {
    // ==========================================================================
    // URL PARAMETER VALIDATION
    // ==========================================================================
    
    // structType is first segment
    if (empty($urlParams) || !isset($urlParams[0])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: structType')
            ->withErrors([['field' => 'structType', 'usage' => 'GET /management/listInteractions/{structType}/{nodeId}']]);
    }
    
    $structType = $urlParams[0];
    $allowedTypes = ['menu', 'footer', 'page', 'component'];
    
    if (!in_array($structType, $allowedTypes, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Invalid structType. Must be one of: ' . implode(', ', $allowedTypes))
            ->withErrors([['field' => 'structType', 'value' => $structType, 'allowed' => $allowedTypes]]);
    }
    
    // nodeId is remaining segments joined (could be "hero/cta-button" from URL "/hero/cta-button")
    if (!isset($urlParams[1])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameter: nodeId')
            ->withErrors([['field' => 'nodeId', 'usage' => 'GET /management/listInteractions/{structType}/{nodeId}']]);
    }
    
    // For page type, we need to extract page name and nodeId separately
    // URL format: /listInteractions/page/home/hero/cta-button
    //             structType=page, page=home, nodeId=hero/cta-button
    // For menu/footer: /listInteractions/menu/0.1
    //             structType=menu, nodeId=0.1
    
    $pageName = null;
    $nodeId = null;
    
    if ($structType === 'page') {
        // First segment after structType is page name
        $pageName = $urlParams[1];
        
        // Validate page exists
        $specialPages = ['404', '500', '403', '401'];
        if (!routeExists($pageName, ROUTES) && !in_array($pageName, $specialPages, true)) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$pageName}' does not exist");
        }
        
        // Remaining segments form the nodeId
        if (!isset($urlParams[2])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage('Missing required parameter: nodeId')
                ->withErrors([['field' => 'nodeId', 'usage' => 'GET /management/listInteractions/page/{pageName}/{nodeId}']]);
        }
        
        $nodeId = implode('/', array_slice($urlParams, 2));
        
    } elseif ($structType === 'component') {
        // First segment is component name
        $componentName = $urlParams[1];
        $pageName = $componentName; // Reuse variable for consistency
        
        // Remaining segments form the nodeId
        if (!isset($urlParams[2])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage('Missing required parameter: nodeId')
                ->withErrors([['field' => 'nodeId', 'usage' => 'GET /management/listInteractions/component/{componentName}/{nodeId}']]);
        }
        
        $nodeId = implode('/', array_slice($urlParams, 2));
        
    } else {
        // menu/footer: nodeId is everything after structType
        $nodeId = implode('/', array_slice($urlParams, 1));
    }
    
    // Decode URL-encoded nodeId
    $nodeId = urldecode($nodeId);
    
    // ==========================================================================
    // LOAD STRUCTURE FILE
    // ==========================================================================
    
    if ($structType === 'page') {
        $jsonFile = resolvePageJsonPath($pageName);
        if ($jsonFile === null) {
            return ApiResponse::create(404, 'file.not_found')
                ->withMessage("Structure file not found for page '{$pageName}'");
        }
    } elseif ($structType === 'component') {
        $jsonFile = PROJECT_PATH . '/templates/model/json/components/' . $pageName . '.json';
    } else {
        $jsonFile = PROJECT_PATH . '/templates/model/json/' . $structType . '.json';
    }
    
    if (!file_exists($jsonFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Structure file not found')
            ->withData(['file' => basename($jsonFile)]);
    }
    
    $jsonContent = @file_get_contents($jsonFile);
    if ($jsonContent === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read structure file');
    }
    
    $structure = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Invalid JSON in structure file: ' . json_last_error_msg());
    }
    
    // ==========================================================================
    // FIND NODE BY ID
    // ==========================================================================
    
    // NodeNavigator uses numeric path like "0.1.2" 
    // But our nodeId from data-qs-node is like "hero/cta-button"
    // We need to convert or use addNodeIds to find it
    
    // First, add node IDs to structure
    $structureWithIds = NodeNavigator::addNodeIds($structure);
    
    // Find the node - try direct numeric path first
    $node = NodeNavigator::getNode($structure, $nodeId);
    
    if ($node === null) {
        // nodeId might be a semantic ID (generated from classes/ids)
        // Search structure for matching data-qs-node
        $node = findNodeBySemanticId($structureWithIds, $nodeId);
    }
    
    if ($node === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found: {$nodeId}")
            ->withData(['nodeId' => $nodeId, 'structType' => $structType]);
    }
    
    // ==========================================================================
    // EXTRACT INTERACTIONS AND BUILD RESPONSE
    // ==========================================================================
    
    $tag = $node['tag'] ?? 'div';
    $interactions = extractInteractionsFromNode($node);
    $availableEvents = getAvailableEventsForTag($tag);
    
    // Group interactions by event for better UI representation
    $groupedByEvent = [];
    foreach ($interactions as $interaction) {
        $event = $interaction['event'];
        if (!isset($groupedByEvent[$event])) {
            $groupedByEvent[$event] = [];
        }
        $groupedByEvent[$event][] = [
            'function' => $interaction['function'],
            'params' => $interaction['params'],
            'raw' => $interaction['raw']
        ];
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Interactions retrieved successfully')
        ->withData([
            'interactions' => $interactions,
            'groupedByEvent' => $groupedByEvent,
            'availableEvents' => $availableEvents,
            'element' => [
                'tag' => $tag,
                'nodeId' => $nodeId,
                'class' => $node['params']['class'] ?? null,
                'id' => $node['params']['id'] ?? null
            ],
            'totalInteractions' => count($interactions)
        ]);
}

/**
 * Find a node by its semantic ID (from data-qs-node attribute value)
 * This searches the structure recursively
 */
function findNodeBySemanticId(array $structure, string $targetId, string $currentPath = ''): ?array {
    // Check if this is the target node
    $nodeId = $structure['__nodeId'] ?? null;
    if ($nodeId === $targetId) {
        return $structure;
    }
    
    // Search in children
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $index => $child) {
            if (is_array($child)) {
                $found = findNodeBySemanticId($child, $targetId, $currentPath . '.' . $index);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }
    
    // Handle array of root elements
    if (isset($structure[0])) {
        foreach ($structure as $index => $item) {
            if (is_array($item)) {
                $found = findNodeBySemanticId($item, $targetId, (string)$index);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }
    
    return null;
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParametersManagement = new TrimParametersManagement();
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_listInteractions([], $urlSegments)->send();
}
