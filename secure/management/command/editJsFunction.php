<?php
/**
 * editJsFunction Command
 * 
 * Edits an existing custom JavaScript function.
 * Core functions (show, hide, toggle, etc.) cannot be edited.
 * 
 * @method POST
 * @route /management/editJsFunction
 * @auth required (admin only)
 * 
 * @param string name Function name to edit (required)
 * @param string newName New function name (optional, for renaming)
 * @param array args New array of argument names (optional)
 * @param string body New JavaScript function body (optional)
 * @param string description New description (optional)
 * 
 * @return ApiResponse Success with updated function details or error
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsFunctionManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_editJsFunction(array $params = [], array $urlParams = []): ApiResponse {
    
    // Validate required field
    $name = $params['name'] ?? null;
    
    if (empty($name)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('name parameter is required')
            ->withData(['required_fields' => ['name']]);
    }
    
    if (!is_string($name)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('name must be a string')
            ->withErrors([['field' => 'name', 'expected' => 'string']]);
    }
    
    $manager = new JsFunctionManager();
    
    // Check if this is a core function
    if ($manager->isCoreFunction($name)) {
        return ApiResponse::create(403, 'operation.forbidden')
            ->withMessage("Cannot edit core function '{$name}'. Core functions are read-only.")
            ->withData(['core_functions' => $manager->getCoreFunctions()]);
    }
    
    // Check if function exists
    $existingFunc = $manager->getFunction($name);
    if ($existingFunc === null) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Function '{$name}' not found");
    }
    
    // Build updates array
    $updates = [];
    
    // Handle newName
    if (isset($params['newName'])) {
        if (!is_string($params['newName'])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('newName must be a string')
                ->withErrors([['field' => 'newName', 'expected' => 'string']]);
        }
        $updates['newName'] = $params['newName'];
    }
    
    // Handle args
    if (isset($params['args'])) {
        if (!is_array($params['args'])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('args must be an array')
                ->withErrors([['field' => 'args', 'expected' => 'array']]);
        }
        
        // Validate args are all valid strings
        foreach ($params['args'] as $index => $arg) {
            if (!is_string($arg)) {
                return ApiResponse::create(400, 'validation.invalid_type')
                    ->withMessage("args[{$index}] must be a string")
                    ->withErrors([['field' => "args[{$index}]", 'expected' => 'string']]);
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $arg)) {
                return ApiResponse::create(400, 'validation.invalid_format')
                    ->withMessage("args[{$index}] '{$arg}' is not a valid argument name")
                    ->withErrors([['field' => "args[{$index}]", 'value' => $arg, 'reason' => 'must be valid JS identifier']]);
            }
        }
        $updates['args'] = $params['args'];
    }
    
    // Handle body
    if (isset($params['body'])) {
        if (!is_string($params['body'])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('body must be a string')
                ->withErrors([['field' => 'body', 'expected' => 'string']]);
        }
        $updates['body'] = $params['body'];
    }
    
    // Handle description
    if (isset($params['description'])) {
        if (!is_string($params['description'])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage('description must be a string')
                ->withErrors([['field' => 'description', 'expected' => 'string']]);
        }
        $updates['description'] = $params['description'];
    }
    
    // Must have at least one update
    if (empty($updates)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('At least one field to update is required')
            ->withData(['updatable_fields' => ['newName', 'args', 'body', 'description']]);
    }
    
    // Perform edit
    $result = $manager->editFunction($name, $updates);
    
    if (!$result['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage($result['error']);
    }
    
    // Get updated function (use new name if renamed)
    $finalName = $updates['newName'] ?? $name;
    $func = $manager->getFunction($finalName);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Function '{$name}'" . ($finalName !== $name ? " renamed to '{$finalName}'" : "") . " updated successfully")
        ->withData([
            'function' => $func,
            'usage' => "{{call:{$finalName}:" . implode(',', $func['args'] ?? []) . "}}",
            'signature' => "QS.{$finalName}(" . implode(', ', $func['args'] ?? []) . ")"
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editJsFunction($trimParams->params(), $trimParams->additionalParams())->send();
}
