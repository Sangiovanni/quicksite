<?php
/**
 * AiProviderManager - Manages AI provider detection, validation, and API calls
 * 
 * This class handles:
 * - Loading provider configurations
 * - Detecting provider from API key prefix
 * - Testing API key validity
 * - Fetching available models
 * - Making AI API calls (server as proxy)
 * 
 * Security Note: API keys are passed per-request and never stored on disk.
 * Keys exist only in memory during the request lifecycle.
 * 
 * @package QuickSite\Admin
 * @version 1.0.0
 */

class AiProviderManager {
    
    /** @var array Provider configurations */
    private array $providers;
    
    /** @var int Request timeout in seconds */
    private int $timeout = 30;
    
    /** @var array Standard error types returned to client */
    private static array $errorTypes = [
        'invalid_key' => 'API key is invalid or expired',
        'no_access' => 'Key does not have access to this resource',
        'rate_limit' => 'Rate limit exceeded, try again later',
        'quota_exceeded' => 'API quota exhausted, check billing',
        'overloaded' => 'API is overloaded, try again later',
        'invalid_request' => 'Invalid request format',
        'network_error' => 'Could not connect to API',
        'unknown' => 'Unknown error occurred'
    ];
    
    /**
     * Constructor - loads provider configurations
     */
    public function __construct() {
        $configPath = SECURE_FOLDER_PATH . '/management/config/ai_providers.php';
        if (!file_exists($configPath)) {
            throw new Exception('AI providers configuration not found');
        }
        $this->providers = require $configPath;
    }
    
    /**
     * Get all available providers
     * 
     * @return array List of providers with name, id, and whether they support prefix detection
     */
    public function listProviders(): array {
        $result = [];
        foreach ($this->providers as $id => $config) {
            $result[] = [
                'id' => $id,
                'name' => $config['name'],
                'has_prefix_detection' => !empty($config['prefixes']),
                'default_models' => $config['default_models'] ?? []
            ];
        }
        return $result;
    }
    
    /**
     * Detect provider from API key prefix
     * 
     * @param string $key API key to analyze
     * @return array Detection result with provider id or null
     */
    public function detectProvider(string $key): array {
        // Collect all prefixes with their provider info
        $allPrefixes = [];
        foreach ($this->providers as $id => $config) {
            if (empty($config['prefixes'])) {
                continue;
            }
            foreach ($config['prefixes'] as $prefix) {
                $allPrefixes[] = [
                    'prefix' => $prefix,
                    'provider' => $id,
                    'name' => $config['name']
                ];
            }
        }
        
        // Sort by prefix length descending (longest first)
        usort($allPrefixes, function($a, $b) {
            return strlen($b['prefix']) - strlen($a['prefix']);
        });
        
        // Try matching prefixes (longest first to avoid partial matches)
        foreach ($allPrefixes as $entry) {
            if (str_starts_with($key, $entry['prefix'])) {
                return [
                    'detected' => true,
                    'provider' => $entry['provider'],
                    'name' => $entry['name'],
                    'method' => 'prefix'
                ];
            }
        }
        
        // No prefix match - return providers that need fallback probing
        $fallbackProviders = [];
        foreach ($this->providers as $id => $config) {
            if (empty($config['prefixes'])) {
                $fallbackProviders[] = [
                    'id' => $id,
                    'name' => $config['name']
                ];
            }
        }
        
        return [
            'detected' => false,
            'provider' => null,
            'method' => 'none',
            'fallback_providers' => $fallbackProviders,
            'message' => 'Could not detect provider from prefix. Use testAiKey with explicit provider to verify.'
        ];
    }
    
    /**
     * Test if an API key is valid by making a minimal API call
     * 
     * @param string $key API key to test
     * @param string $providerId Provider to test against
     * @return array Result with validity, models if successful, error if not
     */
    public function testKey(string $key, string $providerId): array {
        if (!isset($this->providers[$providerId])) {
            return [
                'valid' => false,
                'error' => 'unknown_provider',
                'message' => "Unknown provider: $providerId"
            ];
        }
        
        $provider = $this->providers[$providerId];
        
        // Try to fetch models list first (lightweight validation)
        if (!empty($provider['models_endpoint'])) {
            $modelsResult = $this->fetchModels($key, $providerId);
            if ($modelsResult['success']) {
                return [
                    'valid' => true,
                    'provider' => $providerId,
                    'name' => $provider['name'],
                    'models' => $modelsResult['models']
                ];
            }
            // If models fetch failed with auth error, key is invalid
            if (in_array($modelsResult['error'], ['invalid_key', 'no_access'])) {
                return [
                    'valid' => false,
                    'error' => $modelsResult['error'],
                    'message' => self::$errorTypes[$modelsResult['error']] ?? $modelsResult['message']
                ];
            }
        }
        
        // Fallback: try a minimal chat completion request
        $testResult = $this->makeMinimalRequest($key, $providerId);
        
        if ($testResult['success']) {
            return [
                'valid' => true,
                'provider' => $providerId,
                'name' => $provider['name'],
                'models' => $provider['default_models'] ?? []
            ];
        }
        
        return [
            'valid' => false,
            'error' => $testResult['error'],
            'message' => self::$errorTypes[$testResult['error']] ?? $testResult['message']
        ];
    }
    
    /**
     * Fetch available models from provider API
     * 
     * @param string $key API key
     * @param string $providerId Provider id
     * @return array Result with models list or error
     */
    private function fetchModels(string $key, string $providerId): array {
        $provider = $this->providers[$providerId];
        
        if (empty($provider['models_endpoint'])) {
            return [
                'success' => false,
                'error' => 'no_endpoint',
                'models' => $provider['default_models'] ?? []
            ];
        }
        
        $url = $provider['models_endpoint'];
        $headers = $this->buildHeaders($key, $provider);
        
        // Google uses query param for auth
        if ($providerId === 'google') {
            $url .= '?key=' . urlencode($key);
            $headers = ['Content-Type: application/json'];
        }
        
        $response = $this->httpRequest('GET', $url, $headers);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $this->categorizeError($response, $provider),
                'message' => $response['error'] ?? 'Failed to fetch models'
            ];
        }
        
        // Parse models from response based on provider
        $models = $this->parseModelsResponse($response['body'], $providerId);
        
        return [
            'success' => true,
            'models' => $models
        ];
    }
    
    /**
     * Parse models list from API response
     * 
     * @param array $body Response body
     * @param string $providerId Provider id
     * @return array List of model ids
     */
    private function parseModelsResponse(array $body, string $providerId): array {
        $models = [];
        
        switch ($providerId) {
            case 'openai':
                // OpenAI returns { data: [{ id: 'gpt-4o', ... }, ...] }
                if (isset($body['data']) && is_array($body['data'])) {
                    foreach ($body['data'] as $model) {
                        // Filter to chat models only
                        $id = $model['id'] ?? '';
                        if (str_contains($id, 'gpt') || str_contains($id, 'o1') || str_contains($id, 'o3')) {
                            $models[] = $id;
                        }
                    }
                }
                // Sort to put newer models first
                usort($models, function($a, $b) {
                    // Prioritize gpt-4o, then gpt-4, then gpt-3.5
                    $priority = ['gpt-4o' => 1, 'gpt-4-turbo' => 2, 'gpt-4' => 3, 'gpt-3.5' => 4];
                    $aScore = 99;
                    $bScore = 99;
                    foreach ($priority as $prefix => $score) {
                        if (str_starts_with($a, $prefix)) $aScore = min($aScore, $score);
                        if (str_starts_with($b, $prefix)) $bScore = min($bScore, $score);
                    }
                    return $aScore - $bScore;
                });
                break;
                
            case 'mistral':
                // Mistral returns { data: [{ id: 'mistral-large', ... }, ...] }
                if (isset($body['data']) && is_array($body['data'])) {
                    foreach ($body['data'] as $model) {
                        $models[] = $model['id'] ?? '';
                    }
                }
                break;
                
            case 'google':
                // Google returns { models: [{ name: 'models/gemini-pro', ... }, ...] }
                if (isset($body['models']) && is_array($body['models'])) {
                    foreach ($body['models'] as $model) {
                        $name = $model['name'] ?? '';
                        // Extract model id from "models/gemini-pro" format
                        if (str_starts_with($name, 'models/')) {
                            $id = substr($name, 7);
                            // Filter to generative models
                            if (str_contains($id, 'gemini')) {
                                $models[] = $id;
                            }
                        }
                    }
                }
                break;
                
            default:
                // Return default models for providers without models endpoint
                return $this->providers[$providerId]['default_models'] ?? [];
        }
        
        return array_filter($models);
    }
    
    /**
     * Make a minimal request to validate the key works
     * 
     * @param string $key API key
     * @param string $providerId Provider id
     * @return array Success/error result
     */
    private function makeMinimalRequest(string $key, string $providerId): array {
        $provider = $this->providers[$providerId];
        $headers = $this->buildHeaders($key, $provider);
        
        $url = $provider['endpoint'];
        $body = $this->buildMinimalRequestBody($providerId);
        
        // Google uses query param and different URL format
        if ($providerId === 'google') {
            $model = $provider['default_models'][0] ?? 'gemini-1.5-flash-latest';
            $url = str_replace('{model}', $model, $url);
            $url .= '?key=' . urlencode($key);
        }
        
        $response = $this->httpRequest('POST', $url, $headers, $body);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $this->categorizeError($response, $provider),
                'message' => $response['error'] ?? 'Request failed'
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Build minimal request body for key validation
     * 
     * @param string $providerId Provider id
     * @return array Request body
     */
    private function buildMinimalRequestBody(string $providerId): array {
        switch ($providerId) {
            case 'anthropic':
                return [
                    'model' => 'claude-3-haiku-20240307', // Cheapest model for testing
                    'max_tokens' => 1,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Hi']
                    ]
                ];
                
            case 'google':
                return [
                    'contents' => [
                        ['parts' => [['text' => 'Hi']]]
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 1
                    ]
                ];
                
            default: // OpenAI-compatible (OpenAI, Mistral)
                $provider = $this->providers[$providerId];
                return [
                    'model' => $provider['default_models'][0] ?? 'gpt-3.5-turbo',
                    'max_tokens' => 1,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Hi']
                    ]
                ];
        }
    }
    
    /**
     * Make an AI completion request
     * 
     * @param string $key API key (passed per-request, never stored)
     * @param string $providerId Provider id
     * @param string $model Model to use
     * @param array $messages Chat messages
     * @param array $options Additional options (max_tokens, temperature, etc.)
     * @return array Response with content or error
     */
    public function call(string $key, string $providerId, string $model, array $messages, array $options = []): array {
        if (!isset($this->providers[$providerId])) {
            return [
                'success' => false,
                'error' => 'unknown_provider',
                'message' => "Unknown provider: $providerId"
            ];
        }
        
        $provider = $this->providers[$providerId];
        $headers = $this->buildHeaders($key, $provider);
        
        $url = $provider['endpoint'];
        $body = $this->buildRequestBody($providerId, $model, $messages, $options);
        
        // Google uses query param and different URL format
        if ($providerId === 'google') {
            $url = str_replace('{model}', $model, $url);
            $url .= '?key=' . urlencode($key);
        }
        
        // Use longer timeout for actual calls (AI can take time for large responses)
        $originalTimeout = $this->timeout;
        $this->timeout = $options['timeout'] ?? 180;
        
        $response = $this->httpRequest('POST', $url, $headers, $body);
        
        $this->timeout = $originalTimeout;
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $this->categorizeError($response, $provider),
                'message' => $response['error'] ?? 'API request failed',
                'raw_error' => $response['body'] ?? null
            ];
        }
        
        // Parse response based on provider format
        $content = $this->parseCompletionResponse($response['body'], $providerId);
        
        // If content is empty, include raw response for debugging
        $debugInfo = null;
        if (empty($content)) {
            $debugInfo = [
                'raw_response_keys' => array_keys($response['body'] ?? []),
                'raw_response_preview' => substr(json_encode($response['body']), 0, 1000)
            ];
        }
        
        return [
            'success' => true,
            'content' => $content,
            'provider' => $providerId,
            'model' => $model,
            'usage' => $this->parseUsage($response['body'], $providerId),
            'rate_limits' => $this->parseRateLimits($response['headers'] ?? []),
            'debug' => $debugInfo
        ];
    }
    
    /**
     * Build request body for completion request
     * 
     * @param string $providerId Provider id
     * @param string $model Model to use
     * @param array $messages Chat messages
     * @param array $options Additional options
     * @return array Request body
     */
    private function buildRequestBody(string $providerId, string $model, array $messages, array $options): array {
        // Default to 8192 tokens - enough for complex JSON responses
        $maxTokens = $options['max_tokens'] ?? 8192;
        $temperature = $options['temperature'] ?? 0.7;
        
        switch ($providerId) {
            case 'anthropic':
                // Anthropic uses different message format
                $systemMessage = null;
                $chatMessages = [];
                
                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $systemMessage = $msg['content'];
                    } else {
                        $chatMessages[] = [
                            'role' => $msg['role'],
                            'content' => $msg['content']
                        ];
                    }
                }
                
                $body = [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'messages' => $chatMessages
                ];
                
                if ($systemMessage) {
                    $body['system'] = $systemMessage;
                }
                
                if (isset($options['temperature'])) {
                    $body['temperature'] = $temperature;
                }
                
                return $body;
                
            case 'google':
                // Google uses "contents" with "parts"
                $contents = [];
                $systemInstruction = null;
                
                foreach ($messages as $msg) {
                    if ($msg['role'] === 'system') {
                        $systemInstruction = ['parts' => [['text' => $msg['content']]]];
                    } else {
                        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
                        $contents[] = [
                            'role' => $role,
                            'parts' => [['text' => $msg['content']]]
                        ];
                    }
                }
                
                $body = [
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => $maxTokens,
                        'temperature' => $temperature
                    ]
                ];
                
                if ($systemInstruction) {
                    $body['systemInstruction'] = $systemInstruction;
                }
                
                return $body;
                
            default: // OpenAI-compatible format (OpenAI, Mistral)
                return [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'messages' => $messages
                ];
        }
    }
    
    /**
     * Parse completion response to extract content
     * 
     * @param array $body Response body
     * @param string $providerId Provider id
     * @return string Extracted content
     */
    private function parseCompletionResponse(array $body, string $providerId): string {
        switch ($providerId) {
            case 'anthropic':
                // Anthropic: { content: [{ type: 'text', text: '...' }] }
                if (isset($body['content'][0]['text'])) {
                    return $body['content'][0]['text'];
                }
                break;
                
            case 'google':
                // Google: { candidates: [{ content: { parts: [{ text: '...' }] } }] }
                if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                    return $body['candidates'][0]['content']['parts'][0]['text'];
                }
                // Try alternative structure (some models may vary)
                if (isset($body['candidates'][0]['content']['parts'])) {
                    // Concatenate all text parts
                    $parts = $body['candidates'][0]['content']['parts'];
                    $texts = array_filter(array_map(fn($p) => $p['text'] ?? '', $parts));
                    if (!empty($texts)) {
                        return implode('', $texts);
                    }
                }
                // Fallback: check if there's a direct text field
                if (isset($body['text'])) {
                    return $body['text'];
                }
                break;
                
            default: // OpenAI-compatible
                // OpenAI/Mistral: { choices: [{ message: { content: '...' } }] }
                if (isset($body['choices'][0]['message']['content'])) {
                    return $body['choices'][0]['message']['content'];
                }
                break;
        }
        
        return '';
    }
    
    /**
     * Parse usage information from response
     * 
     * @param array $body Response body
     * @param string $providerId Provider id
     * @return array|null Usage data
     */
    private function parseUsage(array $body, string $providerId): ?array {
        switch ($providerId) {
            case 'anthropic':
                if (isset($body['usage'])) {
                    return [
                        'input_tokens' => $body['usage']['input_tokens'] ?? 0,
                        'output_tokens' => $body['usage']['output_tokens'] ?? 0
                    ];
                }
                break;
                
            case 'google':
                if (isset($body['usageMetadata'])) {
                    return [
                        'input_tokens' => $body['usageMetadata']['promptTokenCount'] ?? 0,
                        'output_tokens' => $body['usageMetadata']['candidatesTokenCount'] ?? 0
                    ];
                }
                break;
                
            default:
                if (isset($body['usage'])) {
                    return [
                        'input_tokens' => $body['usage']['prompt_tokens'] ?? 0,
                        'output_tokens' => $body['usage']['completion_tokens'] ?? 0
                    ];
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Build HTTP headers for provider
     * 
     * @param string $key API key
     * @param array $provider Provider config
     * @return array Headers
     */
    private function buildHeaders(string $key, array $provider): array {
        $headers = ['Content-Type: application/json'];
        
        // Add auth header if provider uses one (not Google which uses query param)
        if (!empty($provider['auth_header'])) {
            $authValue = str_replace('{key}', $key, $provider['auth_format']);
            $headers[] = $provider['auth_header'] . ': ' . $authValue;
        }
        
        // Add any extra headers (like Anthropic's version header)
        if (!empty($provider['extra_headers'])) {
            foreach ($provider['extra_headers'] as $name => $value) {
                $headers[] = "$name: $value";
            }
        }
        
        return $headers;
    }
    
    /**
     * Parse rate limit information from response headers
     * 
     * @param array $headers Response headers
     * @return array|null Parsed rate limit info
     */
    private function parseRateLimits(array $headers): ?array {
        if (empty($headers)) {
            return null;
        }
        
        $rateLimits = [];
        
        // OpenAI/Anthropic style headers: x-ratelimit-limit-requests, x-ratelimit-remaining-requests, etc.
        foreach ($headers as $key => $value) {
            // Normalize key
            $normalizedKey = str_replace(['x-ratelimit-', 'x-rate-limit-'], '', $key);
            $normalizedKey = str_replace('-', '_', $normalizedKey);
            $rateLimits[$normalizedKey] = is_numeric($value) ? (int)$value : $value;
        }
        
        // Calculate percentage used if we have limit and remaining
        if (isset($rateLimits['limit_requests']) && isset($rateLimits['remaining_requests'])) {
            $rateLimits['requests_used_percent'] = round(
                (1 - $rateLimits['remaining_requests'] / $rateLimits['limit_requests']) * 100,
                1
            );
        }
        if (isset($rateLimits['limit_tokens']) && isset($rateLimits['remaining_tokens'])) {
            $rateLimits['tokens_used_percent'] = round(
                (1 - $rateLimits['remaining_tokens'] / $rateLimits['limit_tokens']) * 100,
                1
            );
        }
        
        return empty($rateLimits) ? null : $rateLimits;
    }
    
    /**
     * Categorize error response into standard error type
     * 
     * @param array $response HTTP response
     * @param array $provider Provider config
     * @return string Error type
     */
    private function categorizeError(array $response, array $provider): string {
        $statusCode = $response['status_code'] ?? 0;
        $body = $response['body'] ?? [];
        $errorMessage = '';
        
        // Extract error message from response body
        if (isset($body['error']['message'])) {
            $errorMessage = $body['error']['message'];
        } elseif (isset($body['error']['type'])) {
            $errorMessage = $body['error']['type'];
        } elseif (isset($body['message'])) {
            $errorMessage = $body['message'];
        }
        
        // Check status code mapping first
        if ($statusCode > 0 && isset($provider['error_codes'][$statusCode])) {
            return $provider['error_codes'][$statusCode];
        }
        
        // Check error message mapping
        foreach ($provider['error_messages'] ?? [] as $pattern => $errorType) {
            if (str_contains(strtolower($errorMessage), strtolower($pattern))) {
                return $errorType;
            }
        }
        
        // Default categorization by status code
        if ($statusCode === 0) {
            return 'network_error';
        } elseif ($statusCode === 401 || $statusCode === 403) {
            return 'invalid_key';
        } elseif ($statusCode === 429) {
            return 'rate_limit';
        }
        
        return 'unknown';
    }
    
    /**
     * Make HTTP request using cURL
     * 
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array $headers Headers
     * @param array|null $body Request body (for POST)
     * @return array Response with success, status_code, body, error
     */
    private function httpRequest(string $method, string $url, array $headers, ?array $body = null): array {
        $ch = curl_init();
        
        // Check for development mode SSL bypass
        // In production, always verify SSL. In dev, check if CA bundle is available.
        $sslVerify = true;
        $sslVerifyHost = 2;
        
        // Development mode: disable SSL verify if no CA bundle is configured
        // This should be removed or properly configured in production!
        $isDevelopmentMode = false;
        $authConfigPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
        if (file_exists($authConfigPath)) {
            $authConfig = require $authConfigPath;
            $isDevelopmentMode = $authConfig['cors']['development_mode'] ?? false;
        }
        
        if ($isDevelopmentMode) {
            // Check if curl.cainfo is configured
            $caInfo = ini_get('curl.cainfo');
            if (empty($caInfo) || !file_exists($caInfo)) {
                $sslVerify = false;
                $sslVerifyHost = 0;
                error_log('AiProviderManager: WARNING - SSL verification disabled in development mode (no CA bundle configured)');
            }
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerifyHost,
            // Note: We don't use CURLOPT_LOW_SPEED_* because AI models like Gemini 2.5
            // have a "thinking" phase where they send 0 bytes while processing.
            // The main CURLOPT_TIMEOUT handles overall request timeout.
        ]);
        
        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        
        // Capture response headers for rate limit info
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;
            
            $key = strtolower(trim($header[0]));
            $value = trim($header[1]);
            
            // Capture rate limit headers (OpenAI, Anthropic use x-ratelimit-*)
            if (strpos($key, 'ratelimit') !== false || strpos($key, 'rate-limit') !== false) {
                $responseHeaders[$key] = $value;
            }
            return $len;
        });
        
        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'status_code' => 0,
                'body' => null,
                'headers' => [],
                'error' => "cURL error: $curlError"
            ];
        }
        
        $decodedBody = json_decode($responseBody, true);
        
        // Consider 2xx as success
        $success = $statusCode >= 200 && $statusCode < 300;
        
        return [
            'success' => $success,
            'status_code' => $statusCode,
            'body' => $decodedBody,
            'headers' => $responseHeaders,
            'error' => $success ? null : ($decodedBody['error']['message'] ?? "HTTP $statusCode")
        ];
    }
    
    /**
     * Get provider configuration
     * 
     * @param string $providerId Provider id
     * @return array|null Provider config or null if not found
     */
    public function getProvider(string $providerId): ?array {
        return $this->providers[$providerId] ?? null;
    }
    
    /**
     * Get error message for error type
     * 
     * @param string $errorType Error type code
     * @return string Human-readable error message
     */
    public static function getErrorMessage(string $errorType): string {
        return self::$errorTypes[$errorType] ?? 'Unknown error occurred';
    }
}
