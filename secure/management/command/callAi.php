<?php
/**
 * callAi - Make an AI completion request
 * 
 * @method POST
 * @url /management/callAi
 * @auth required
 * @permission write
 * 
 * Proxies a chat completion request to the specified AI provider.
 * The server acts as a proxy to avoid CORS issues.
 * 
 * Security Note: The API key is passed per-request, used once
 * for the API call, and immediately garbage collected.
 * Keys are never stored on disk.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AiProviderManager.php';

/**
 * Command function for internal execution via CommandRunner
 */
function __command_callAi(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required parameters
    $required = ['key', 'provider', 'model', 'messages'];
    foreach ($required as $param) {
        if (!isset($params[$param])) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Parameter \"$param\" is required");
        }
    }
    
    $key = trim($params['key']);
    $providerId = $params['provider'];
    $model = $params['model'];
    $messages = $params['messages'];
    
    // Validate key
    if (empty($key) || strlen($key) < 20) {
        return ApiResponse::create(400, 'validation.invalid_key')
            ->withMessage('Invalid API key format');
    }
    
    // Validate messages format
    if (!is_array($messages) || empty($messages)) {
        return ApiResponse::create(400, 'validation.invalid_messages')
            ->withMessage('Messages must be a non-empty array');
    }
    
    // Validate message structure
    foreach ($messages as $index => $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) {
            return ApiResponse::create(400, 'validation.invalid_message_format')
                ->withMessage("Message at index $index must have 'role' and 'content' fields");
        }
        if (!in_array($msg['role'], ['system', 'user', 'assistant'])) {
            return ApiResponse::create(400, 'validation.invalid_role')
                ->withMessage("Message at index $index has invalid role. Must be: system, user, or assistant");
        }
    }
    
    try {
        $manager = new AiProviderManager();
        
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
        
        // Build options from optional parameters
        $options = [];
        if (isset($params['max_tokens']) && is_numeric($params['max_tokens'])) {
            $options['max_tokens'] = min(max((int)$params['max_tokens'], 1), 128000);
        }
        if (isset($params['temperature']) && is_numeric($params['temperature'])) {
            $options['temperature'] = min(max((float)$params['temperature'], 0), 2);
        }
        if (isset($params['timeout']) && is_numeric($params['timeout'])) {
            $options['timeout'] = min(max((int)$params['timeout'], 10), 300);
        }
        
        // Make the API call
        $result = $manager->call($key, $providerId, $model, $messages, $options);
        
        if ($result['success']) {
            return ApiResponse::create(200, 'ai.response')
                ->withMessage('AI response received')
                ->withData([
                    'content' => $result['content'],
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'usage' => $result['usage'],
                    'rate_limits' => $result['rate_limits'] ?? null
                ]);
        } else {
            // Map error types to HTTP status codes
            $statusMap = [
                'invalid_key' => 401,
                'no_access' => 403,
                'rate_limit' => 429,
                'quota_exceeded' => 402,
                'overloaded' => 503,
                'invalid_request' => 400,
                'network_error' => 502,
            ];
            
            $status = $statusMap[$result['error']] ?? 500;
            
            return ApiResponse::create($status, 'ai.' . $result['error'])
                ->withMessage($result['message'])
                ->withData([
                    'error_type' => $result['error'],
                    'provider' => $providerId,
                    'model' => $model
                ]);
        }
    } catch (Exception $e) {
        return ApiResponse::create(500, 'server.error')
            ->withMessage('Failed to call AI: ' . $e->getMessage());
    }
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_callAi($params)->send();
}
