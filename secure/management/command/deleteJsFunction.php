<?php
/**
 * deleteJsFunction Command
 * 
 * Deletes a custom JavaScript function from the QS namespace.
 * Core functions (show, hide, toggle, etc.) cannot be deleted.
 * 
 * @method POST
 * @route /management/deleteJsFunction
 * @auth required (admin only)
 * 
 * @param string name Function name to delete (required)
 * 
 * @return ApiResponse Success confirmation or error
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
function __command_deleteJsFunction(array $params = [], array $urlParams = []): ApiResponse {
    
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
            ->withMessage("Cannot delete core function '{$name}'. Core functions are protected.")
            ->withData(['core_functions' => $manager->getCoreFunctions()]);
    }
    
    // Check if function exists
    $existingFunc = $manager->getFunction($name);
    if ($existingFunc === null) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage("Function '{$name}' not found");
    }
    
    // Perform delete
    $result = $manager->deleteFunction($name);
    
    if (!$result['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage($result['error']);
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Function '{$name}' deleted successfully")
        ->withData([
            'deleted' => $name,
            'remaining_custom' => count($manager->getCustomFunctions())
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteJsFunction($trimParams->params(), $trimParams->additionalParams())->send();
}
