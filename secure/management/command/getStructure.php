<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

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

// For pages and components, name is the second URL segment
    if ($type === 'page' || $type === 'component') {
        if (!isset($urlParams[1]) || empty($urlParams[1])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Name required in URL for type={$type}")
                ->withErrors([['field' => 'name', 'reason' => 'missing', 'usage' => "GET /management/getStructure/{$type}/{name}"]]);
        }
        
        $name = $urlParams[1];
        
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
        
        // Length validation - max 100 characters for name
        if (strlen($name) > 100) {
            return ApiResponse::create(400, 'validation.invalid_length')
                ->withMessage("The name parameter must not exceed 100 characters.")
                ->withErrors([
                    ['field' => 'name', 'value' => $name, 'max_length' => 100]
                ]);
        }
        
        // Check for path traversal attempts in name (BEFORE other validations)
        if (strpos($name, '..') !== false || 
            strpos($name, '/') !== false || 
            strpos($name, '\\') !== false ||
            strpos($name, "\0") !== false) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage('Name contains invalid path characters')
                ->withErrors([
                    ['field' => 'name', 'reason' => 'path_traversal_attempt']
                ]);
        }
        
        // Validate name format (alphanumeric, hyphens, underscores) for both pages and components
        if (!RegexPatterns::match('identifier_alphanum', $name)) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage("Invalid name format. Use only alphanumeric, hyphens, and underscores")
                ->withErrors([RegexPatterns::validationError('identifier_alphanum', 'name', $name)]);
        }
        
        // Special pages that exist but are not in ROUTES (error pages, etc.)
        $specialPages = ['404', '500', '403', '401'];
        
        // Validate page exists (only for pages, not components)
        // Allow special pages (404, 500, etc.) even if not in ROUTES
        if ($type === 'page' && !in_array($name, ROUTES, true) && !in_array($name, $specialPages, true)) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$name}' does not exist")
                ->withData(['available_routes' => ROUTES, 'special_pages' => $specialPages]);
        }
        
        // Build file path based on type
        if ($type === 'page') {
            $json_file = SECURE_FOLDER_PATH . '/templates/model/json/pages/' . $name . '.json';
        } else { // component
            $json_file = SECURE_FOLDER_PATH . '/templates/model/json/components/' . $name . '.json';
        }
    } else {
        // For menu/footer, use the type directly
        $json_file = SECURE_FOLDER_PATH . '/templates/model/json/' . $type . '.json';
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

// Check for additional parameters: nodeId or showIds
    // For page/component: urlParams[2] would be the option
    // For menu/footer: urlParams[1] would be the option
    $optionSegment = ($type === 'page' || $type === 'component') 
        ? ($urlParams[2] ?? null) 
        : ($urlParams[1] ?? null);

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