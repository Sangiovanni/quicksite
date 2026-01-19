<?php
/**
 * addJsFunction Command
 * 
 * Adds a custom JavaScript function to the QS namespace.
 * The function will be available for use with {{call:...}} syntax.
 * 
 * @method POST
 * @route /management/addJsFunction
 * @auth required (admin only)
 * 
 * @param string name Function name (must start with letter, alphanumeric only)
 * @param array args Array of argument names (optional)
 * @param string body JavaScript function body
 * @param string description Description of what the function does (optional)
 * 
 * @return ApiResponse Success with function details or error
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
function __command_addJsFunction(array $params = [], array $urlParams = []): ApiResponse {
    
    // Validate required fields
    $name = $params['name'] ?? null;
    $body = $params['body'] ?? null;
    $args = $params['args'] ?? [];
    $description = $params['description'] ?? '';
    
    // Name is required
    if (empty($name)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('name parameter is required')
            ->withData(['required_fields' => ['name', 'body']]);
    }
    
    // Body is required
    if (empty($body)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('body parameter is required')
            ->withData(['required_fields' => ['name', 'body']]);
    }
    
    // Type validation
    if (!is_string($name)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('name must be a string')
            ->withErrors([['field' => 'name', 'expected' => 'string']]);
    }
    
    if (!is_string($body)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('body must be a string')
            ->withErrors([['field' => 'body', 'expected' => 'string']]);
    }
    
    if (!is_array($args)) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('args must be an array')
            ->withErrors([['field' => 'args', 'expected' => 'array']]);
    }
    
    // Validate args are all strings
    foreach ($args as $index => $arg) {
        if (!is_string($arg)) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage("args[{$index}] must be a string")
                ->withErrors([['field' => "args[{$index}]", 'expected' => 'string']]);
        }
        // Validate arg name format
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $arg)) {
            return ApiResponse::create(400, 'validation.invalid_format')
                ->withMessage("args[{$index}] '{$arg}' is not a valid argument name")
                ->withErrors([['field' => "args[{$index}]", 'value' => $arg, 'reason' => 'must be valid JS identifier']]);
        }
    }
    
    // Use JsFunctionManager to add function
    $manager = new JsFunctionManager();
    
    $result = $manager->addFunction([
        'name' => $name,
        'args' => $args,
        'body' => $body,
        'description' => $description
    ]);
    
    if (!$result['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage($result['error']);
    }
    
    // Get the newly created function
    $func = $manager->getFunction($name);
    
    return ApiResponse::create(201, 'operation.success')
        ->withMessage("Function '{$name}' created successfully")
        ->withData([
            'function' => $func,
            'usage' => "{{call:{$name}:" . implode(',', $args) . "}}",
            'signature' => "QS.{$name}(" . implode(', ', $args) . ")"
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addJsFunction($trimParams->params(), $trimParams->additionalParams())->send();
}
