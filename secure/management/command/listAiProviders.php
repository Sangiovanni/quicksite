<?php
/**
 * listAiProviders - List available AI providers
 * 
 * @method GET
 * @url /management/listAiProviders
 * @auth required
 * @permission read
 * 
 * Returns a list of all supported AI providers with their
 * names, whether they support prefix detection, and default models.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AiProviderManager.php';

/**
 * Command function for internal execution via CommandRunner
 */
function __command_listAiProviders(array $params = [], array $urlParams = []): ApiResponse {
    try {
        $manager = new AiProviderManager();
        $providers = $manager->listProviders();
        
        return ApiResponse::create(200, 'providers.list')
            ->withMessage('AI providers retrieved successfully')
            ->withData([
                'providers' => $providers,
                'count' => count($providers)
            ]);
    } catch (Exception $e) {
        return ApiResponse::create(500, 'server.error')
            ->withMessage('Failed to load AI providers: ' . $e->getMessage());
    }
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listAiProviders()->send();
}
