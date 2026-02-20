<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * getStructure - Retrieves JSON structure for pages, menu, footer, or components
 * 
 * @method GET
 * @url /management/getStructure/{type}/{name?}/{option?}
 * @auth required
 * @permission read
 */

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments [type, name?, option?]
 * @return ApiResponse
 */
function __command_getStructure(array $params = [], array $urlParams = []): ApiResponse {
    // Validate type parameter (first segment)
    if (empty($urlParams) || !isset($urlParams[0])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("Type parameter missing from URL")
            ->withErrors([['field' => 'type', 'reason' => 'missing', 'usage' => 'GET /management/getStructure/{type}/{name?}']]);
    }

    $type = $urlParams[0];

// Type validation - type must be string
    if (!is_string($type)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The type parameter must be a string.')
            ->withErrors([
                ['field' => 'type', 'reason' => 'invalid_type', 'expected' => 'string']
            ]);
    }

    $allowed_types = ['menu', 'footer', 'page', 'component'];

    // Dynamic length validation for type
    $maxTypeLength = max(array_map('strlen', $allowed_types));
    if (strlen($type) > $maxTypeLength) {
        return ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage("The type parameter must not exceed {$maxTypeLength} characters.")
            ->withErrors([
                ['field' => 'type', 'value' => $type, 'max_length' => $maxTypeLength]
            ]);
    }

    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([
                ['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]
            ]);
    }

// For pages and components, name is from URL segments
    if ($type === 'page' || $type === 'component') {
        if (!isset($urlParams[1]) || empty($urlParams[1])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Name required in URL for type={$type}")
                ->withErrors([['field' => 'name', 'reason' => 'missing', 'usage' => "GET /management/getStructure/{$type}/{name}"]]);
        }
        
        // For pages, support nested routes: getStructure/page/guides/getting-started
        // Collect all URL segments after type as the route path
        if ($type === 'page') {
            $routeSegments = array_slice($urlParams, 1);
            
            // Check if last segment is a special option (showIds, summary, or nodeId)
            $lastSegment = end($routeSegments);
            if ($lastSegment === 'showIds' || $lastSegment === 'summary') {
                // Remove option from route segments
                array_pop($routeSegments);
            } elseif ($lastSegment !== false && RegexPatterns::match('node_id', $lastSegment)) {
                // Last segment is a nodeId - remove it from route segments
                array_pop($routeSegments);
            }
            
            // Filter out empty segments
            $routeSegments = array_filter($routeSegments, fn($s) => $s !== '');
            $name = implode('/', $routeSegments);
        } else {
            $name = $urlParams[1];
        }
        
        // Type validation - name must be string (allow numeric for routes like "404")
        if (is_int($name) || is_float($name)) {
            $name = (string) $name;
        }
        
        if (!is_string($name)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('The name parameter must be a string.')
                ->withErrors([
                    ['field' => 'name', 'reason' => 'invalid_type', 'expected' => 'string']
                ]);
        }
        
        // Length validation - max 200 characters for route path
        if (strlen($name) > 200) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage("The name parameter must not exceed 200 characters.")
                ->withErrors([
                    ['field' => 'name', 'value' => $name, 'max_length' => 200]
                ]);
        }
        
        // Check for path traversal attempts in name (BEFORE other validations)
        // Allow forward slashes for nested routes, but block dangerous patterns
        if (strpos($name, '..') !== false || 
            strpos($name, '\\') !== false ||
            strpos($name, "\0") !== false) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Name contains invalid path characters')
                ->withErrors([
                    ['field' => 'name', 'reason' => 'path_traversal_attempt']
                ]);
        }
        
        // Validate each segment of the route path
        $segments = array_filter(explode('/', $name), fn($s) => $s !== '');
        foreach ($segments as $segment) {
            if (!RegexPatterns::match('identifier_alphanum', $segment)) {
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage("Invalid segment '$segment'. Use only alphanumeric, hyphens, and underscores")
                    ->withErrors([RegexPatterns::validationError('identifier_alphanum', 'name', $segment)]);
            }
        }
        
        // Special pages that exist but are not in ROUTES (error pages, etc.)
        $specialPages = ['404', '500', '403', '401'];
        
        // Validate page exists (only for pages, not components)
        // Allow special pages (404, 500, etc.) even if not in ROUTES
        if ($type === 'page' && !routeExists($name, ROUTES) && !in_array($name, $specialPages, true)) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$name}' does not exist")
                ->withData(['available_routes' => flattenRoutes(ROUTES), 'special_pages' => $specialPages]);
        }
        
        // Build file path based on type
        if ($type === 'page') {
            // Use helper to resolve JSON path (supports folder structure)
            $json_file = resolvePageJsonPath($name);
            if ($json_file === null) {
                return ApiResponse::create(404, 'file.not_found')
                    ->withMessage("Structure file not found for page '{$name}'")
                    ->withData(['route' => $name]);
            }
        } else { // component
            $json_file = PROJECT_PATH . '/templates/model/json/components/' . $name . '.json';
        }
    } else {
        // For menu/footer, use the type directly
        $json_file = PROJECT_PATH . '/templates/model/json/' . $type . '.json';
        $name = null;
    }

    // Check file exists
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found")
            ->withData(['file' => $json_file]);
    }

    // Read and decode JSON
    $json_content = @file_get_contents($json_file);
    if ($json_content === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to read structure file");
    }

    $structure = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON in structure file: " . json_last_error_msg());
    }

    // Check for additional parameters: nodeId, showIds, or summary
    // For page/component: last segment could be the option
    // For menu/footer: urlParams[1] would be the option
    $optionSegment = null;
    
    if ($type === 'page' || $type === 'component') {
        // For pages/components, use the last segment if it looks like an option
        $lastSeg = end($urlParams);
        if ($lastSeg === 'showIds' || $lastSeg === 'summary' || RegexPatterns::match('node_id', $lastSeg)) {
            $optionSegment = $lastSeg;
        }
    } else {
        // For menu/footer, option is simply the second segment
        $optionSegment = $urlParams[1] ?? null;
    }

    $showIds = false;
    $targetNodeId = null;
    $summaryMode = false;

    if ($optionSegment !== null) {
        if ($optionSegment === 'showIds') {
            $showIds = true;
        } elseif ($optionSegment === 'summary') {
            $summaryMode = true;
        } elseif (RegexPatterns::match('node_id', $optionSegment)) {
            // It's a nodeId - retrieve specific node
            $targetNodeId = $optionSegment;
        } else {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage("Invalid option. Use 'showIds', 'summary', or a node identifier (e.g., '0.2.1')")
                ->withErrors([['field' => 'option', 'value' => $optionSegment]]);
        }
    }

    // Handle summary mode - returns simplified tree view
    if ($summaryMode) {
        $summary = NodeNavigator::getSummary($structure);
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Structure summary retrieved successfully')
            ->withData([
                'type' => $type,
                'name' => $name,
                'summary' => $summary,
                'note' => 'Use _nodeId values with editStructure for targeted edits'
            ]);
    }

    // Handle specific node retrieval
    if ($targetNodeId !== null) {
        $node = NodeNavigator::getNode($structure, $targetNodeId);
        
        if ($node === null) {
            return ApiResponse::create(404, 'node.not_found')
                ->withMessage("Node not found at identifier: {$targetNodeId}")
                ->withErrors([['field' => 'nodeId', 'value' => $targetNodeId]]);
        }
        
        // Add nodeId to the returned node
        $nodeWithId = NodeNavigator::addNodeIds($node, $targetNodeId);
        
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Node retrieved successfully')
            ->withData([
                'type' => $type,
                'name' => $name,
                'nodeId' => $targetNodeId,
                'node' => $nodeWithId
            ]);
    }

    // Handle showIds - add _nodeId to all nodes
    if ($showIds) {
        $structure = NodeNavigator::addNodeIds($structure);
    }

    // Success
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Structure retrieved successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'structure' => $structure,
            'file' => $json_file,
            'nodeIds' => $showIds ? 'included' : 'not included (add /showIds to URL)'
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_getStructure([], $urlSegments)->send();
}