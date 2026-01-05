<?php
/**
 * detectProvider - Detect AI provider from API key prefix
 * 
 * @method POST
 * @url /management/detectProvider
 * @auth required
 * @permission read
 * 
 * Analyzes an API key to determine which provider it belongs to
 * based on known prefixes (sk- for OpenAI, sk-ant- for Anthropic, etc.)
 * 
 * Security Note: The key is analyzed in memory and never stored.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AiProviderManager.php';

/**
 * Command function for internal execution via CommandRunner
 */
function __command_detectProvider(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required parameter
    if (!isset($params['key']) || !is_string($params['key'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Parameter "key" is required and must be a string');
    }
    
    $key = trim($params['key']);
    
    if (empty($key)) {
        return ApiResponse::create(400, 'validation.empty')
            ->withMessage('API key cannot be empty');
    }
    
    // Basic length validation (most API keys are 32+ characters)
    if (strlen($key) < 20) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('API key appears too short to be valid');
    }
    
    try {
        $manager = new AiProviderManager();
        $result = $manager->detectProvider($key);
        
        if ($result['detected']) {
            return ApiResponse::create(200, 'provider.detected')
                ->withMessage("Provider detected: {$result['name']}")
                ->withData([
                    'provider' => $result['provider'],
                    'name' => $result['name'],
                    'method' => $result['method']
                ]);
        } else {
            return ApiResponse::create(200, 'provider.unknown')
                ->withMessage('Could not detect provider from key prefix')
                ->withData([
                    'detected' => false,
                    'fallback_providers' => $result['fallback_providers'],
                    'hint' => 'Use testAiKey with explicit provider parameter to verify the key'
                ]);
        }
    } catch (Exception $e) {
        return ApiResponse::create(500, 'server.error')
            ->withMessage('Failed to detect provider: ' . $e->getMessage());
    }
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_detectProvider($params)->send();
}
