<?php
/**
 * testAiKey - Test if an AI API key is valid
 * 
 * @method POST
 * @url /management/testAiKey
 * @auth required
 * @permission read
 * 
 * Tests an API key against a specific provider to verify it's valid.
 * If valid, returns the list of available models for that key.
 * 
 * Security Note: The key is used in memory for a single API call
 * and is never stored on disk.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AiProviderManager.php';

/**
 * Command function for internal execution via CommandRunner
 */
function __command_testAiKey(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required parameter: key
    if (!isset($params['key']) || !is_string($params['key'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Parameter "key" is required and must be a string');
    }
    
    $key = trim($params['key']);
    
    if (empty($key)) {
        return ApiResponse::create(400, 'validation.empty')
            ->withMessage('API key cannot be empty');
    }
    
    // Basic length validation
    if (strlen($key) < 20) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('API key appears too short to be valid');
    }
    
    try {
        $manager = new AiProviderManager();
        
        // If provider not specified, try to detect it
        $providerId = $params['provider'] ?? null;
        
        if (!$providerId) {
            $detection = $manager->detectProvider($key);
            if ($detection['detected']) {
                $providerId = $detection['provider'];
            } else {
                return ApiResponse::create(400, 'validation.provider_required')
                    ->withMessage('Could not detect provider from key prefix. Please specify the "provider" parameter.')
                    ->withData([
                        'fallback_providers' => $detection['fallback_providers']
                    ]);
            }
        }
        
        // Validate provider exists
        $provider = $manager->getProvider($providerId);
        if (!$provider) {
            $providers = $manager->listProviders();
            $validIds = array_column($providers, 'id');
            return ApiResponse::create(400, 'validation.invalid_provider')
                ->withMessage("Unknown provider: $providerId")
                ->withData([
                    'valid_providers' => $validIds
                ]);
        }
        
        // Test the key
        $result = $manager->testKey($key, $providerId);
        
        if ($result['valid']) {
            return ApiResponse::create(200, 'key.valid')
                ->withMessage("API key is valid for {$result['name']}")
                ->withData([
                    'valid' => true,
                    'provider' => $result['provider'],
                    'name' => $result['name'],
                    'models' => $result['models']
                ]);
        } else {
            return ApiResponse::create(401, 'key.invalid')
                ->withMessage($result['message'])
                ->withData([
                    'valid' => false,
                    'error' => $result['error'],
                    'provider' => $providerId
                ]);
        }
    } catch (Exception $e) {
        return ApiResponse::create(500, 'server.error')
            ->withMessage('Failed to test API key: ' . $e->getMessage());
    }
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_testAiKey($params)->send();
}
