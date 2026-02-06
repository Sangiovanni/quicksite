<?php
/**
 * testApiEndpoint - Test an external API endpoint with sample data
 * 
 * @method POST
 * @url /management/testApiEndpoint
 * @auth required
 * @permission editor
 * 
 * Body (JSON): {
 *     "endpointId": "contact-submit",    // Required: Endpoint to test
 *     "apiId": "main-backend",           // Optional: API filter (required if duplicate IDs exist)
 *     "testData": { ... },               // Optional: Request body for POST/PUT/PATCH
 *     "queryParams": { ... },            // Optional: Query parameters for GET
 *     "headers": { ... },                // Optional: Additional headers
 *     "authToken": "...",                // Optional: Override auth token for testing
 *     "timeout": 30                      // Optional: Request timeout in seconds (default: 30)
 * }
 * 
 * Makes an actual HTTP request to the external API endpoint.
 * Returns the response status, headers, and body.
 * 
 * ⚠️ Security: This endpoint makes server-side requests to external URLs.
 * Only registered endpoints can be tested - no arbitrary URLs allowed.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';

/**
 * Command function for internal execution
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_testApiEndpoint(array $params = [], array $urlParams = []): ApiResponse {
    $endpointId = $params['endpointId'] ?? null;
    $apiId = $params['apiId'] ?? null;
    $testData = $params['testData'] ?? null;
    $queryParams = $params['queryParams'] ?? [];
    $extraHeaders = $params['headers'] ?? [];
    $authToken = $params['authToken'] ?? null;
    $timeout = $params['timeout'] ?? 30;
    
    if (!$endpointId || !is_string($endpointId)) {
        return ApiResponse::create(400, 'api.error.missing_parameter')
            ->withMessage('Missing "endpointId" parameter');
    }
    
    // Get endpoint from registry
    $manager = new ApiEndpointManager();
    $endpoint = $manager->getEndpoint($endpointId, $apiId);
    
    if (!$endpoint) {
        $msg = $apiId 
            ? "Endpoint '$endpointId' not found in API '$apiId'"
            : "Endpoint '$endpointId' not found";
        return ApiResponse::create(404, 'api.error.not_found')
            ->withMessage($msg);
    }
    
    // Check for duplicates
    if (!$apiId) {
        $duplicates = $manager->findEndpoints($endpointId);
        if (count($duplicates) > 1) {
            return ApiResponse::create(400, 'api.error.invalid_parameter')
                ->withMessage("Endpoint ID '$endpointId' exists in multiple APIs. Specify 'apiId' parameter.")
                ->withData([
                    'duplicateApis' => array_map(fn($e) => $e['apiId'], $duplicates)
                ]);
        }
    }
    
    // Build URL
    $url = $endpoint['fullUrl'];
    
    // Add query params for GET requests
    if (!empty($queryParams)) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($queryParams);
    }
    
    // Build headers
    $headers = [];
    
    // Add Content-Type for requests with body
    $method = strtoupper($endpoint['method']);
    if (in_array($method, ['POST', 'PUT', 'PATCH']) && $testData !== null) {
        $headers['Content-Type'] = 'application/json';
    }
    
    // Add auth header
    $auth = $endpoint['apiAuth'] ?? ['type' => 'none'];
    if ($auth['type'] !== 'none') {
        if ($authToken) {
            // Use provided test token
            if ($auth['type'] === 'bearer') {
                $headers['Authorization'] = 'Bearer ' . $authToken;
            } elseif ($auth['type'] === 'apiKey') {
                // Extract header name from tokenSource (format: header:X-API-Key)
                $tokenSource = $auth['tokenSource'] ?? '';
                if (strpos($tokenSource, 'header:') === 0) {
                    $headerName = substr($tokenSource, 7);
                    $headers[$headerName] = $authToken;
                }
            } elseif ($auth['type'] === 'basic') {
                $headers['Authorization'] = 'Basic ' . base64_encode($authToken);
            }
        }
        // If no authToken provided, we skip auth - the test will likely fail but that's informative
    }
    
    // Add endpoint-specific headers
    if (!empty($endpoint['headers'])) {
        $headers = array_merge($headers, $endpoint['headers']);
    }
    
    // Add extra headers from request
    $headers = array_merge($headers, $extraHeaders);
    
    // Make the request
    $startTime = microtime(true);
    
    try {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
        ]);
        
        // Set headers
        if (!empty($headers)) {
            $headerLines = [];
            foreach ($headers as $key => $value) {
                $headerLines[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        
        // Set body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && $testData !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
        }
        
        $response = curl_exec($ch);
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000); // ms
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            
            return ApiResponse::create(502, 'api.error.external_request_failed')
                ->withMessage("Request failed: $error")
                ->withData([
                    'endpoint' => [
                        'id' => $endpointId,
                        'apiId' => $endpoint['apiId'],
                        'method' => $method,
                        'url' => $url
                    ],
                    'error' => $error,
                    'duration_ms' => $duration
                ]);
        }
        
        // Parse response
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        
        curl_close($ch);
        
        // Parse response headers
        $headerLines = explode("\r\n", trim($responseHeaders));
        $parsedHeaders = [];
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $parsedHeaders[trim($key)] = trim($value);
            }
        }
        
        // Try to decode JSON response
        $decodedBody = json_decode($responseBody, true);
        $isJson = (json_last_error() === JSON_ERROR_NONE);
        
        // Determine success based on HTTP code
        $isSuccess = $httpCode >= 200 && $httpCode < 300;
        
        return ApiResponse::create(
            $isSuccess ? 200 : 200, // Always return 200 for test results
            $isSuccess ? 'operation.success' : 'operation.warning'
        )
            ->withMessage($isSuccess 
                ? "Endpoint test successful (HTTP $httpCode)" 
                : "Endpoint returned HTTP $httpCode")
            ->withData([
                'endpoint' => [
                    'id' => $endpointId,
                    'apiId' => $endpoint['apiId'],
                    'name' => $endpoint['name'],
                    'method' => $method,
                    'url' => $url
                ],
                'request' => [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $testData
                ],
                'response' => [
                    'status' => $httpCode,
                    'headers' => $parsedHeaders,
                    'body' => $isJson ? $decodedBody : $responseBody,
                    'isJson' => $isJson,
                    'bodyLength' => strlen($responseBody)
                ],
                'timing' => [
                    'duration_ms' => $duration
                ],
                'success' => $isSuccess
            ]);
            
    } catch (Exception $e) {
        return ApiResponse::create(500, 'api.error.internal_error')
            ->withMessage('Error testing endpoint: ' . $e->getMessage());
    }
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_testApiEndpoint($trimParams->params(), $trimParams->additionalParams())->send();
}
