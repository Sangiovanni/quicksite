<?php
/**
 * ApiEndpointManager
 * 
 * Manages external API endpoint definitions for the project.
 * Handles reading/writing endpoint configurations to JSON.
 * 
 * Storage: secure/projects/{project}/data/api-endpoints.json (per-project)
 * 
 * Structure:
 * {
 *   "version": "1.0",
 *   "apis": {
 *     "api-id": {
 *       "name": "API Display Name",
 *       "baseUrl": "https://api.example.com",
 *       "auth": { "type": "bearer", "tokenSource": "localStorage:token" },
 *       "endpoints": [
 *         { "id": "endpoint-id", "name": "...", "path": "/...", "method": "POST", ... }
 *       ]
 *     }
 *   }
 * }
 */

class ApiEndpointManager {
    
    /** @var string Path to api-endpoints.json config */
    private string $configPath;
    
    /** @var array Valid HTTP methods */
    private array $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    
    /** @var array Valid auth types */
    // 'cookie' = browser-managed session cookie (Pattern X). The
    // server's Set-Cookie does everything; runtime just adds
    // `credentials: 'include'` to fetch options. No tokenSource needed.
    private array $validAuthTypes = ['none', 'bearer', 'apiKey', 'basic', 'cookie'];

    /** @var array Valid `callableFrom` values (beta.8 Track A4). */
    // The marker declares whether an endpoint can be invoked from
    // the client (browser via QS.fetch), the server (PHP via the
    // future data resolver from BETA8_DATA_RESOLVER.md), or both.
    // When absent on an endpoint, the effective value is auto-derived
    // from the auth type by deriveCallableFrom() below.
    public const VALID_CALLABLE_FROM = ['client', 'server', 'both'];

    /**
     * Auto-derive callableFrom from an auth type (beta.8 Track A4).
     *
     * Rationale: most auth shapes carry no server-side secret —
     * bearer tokens live in client storage, cookies are
     * browser-managed, basic auth credentials usually come from a
     * client form, none means no secrets at all. Those default to
     * 'both'. apiKey is the one shape that implies a server-side
     * secret (the key is meant to live in api-secrets.php, never in
     * client config); it defaults to 'server' only.
     *
     * Explicit `callableFrom` on the endpoint always wins over this
     * derived value (see effectiveCallableFrom() below). Use this
     * helper only when the endpoint has no explicit value.
     *
     * Locked design 2026-06-04 in BETA8_DATA_RESOLVER.md "callableFrom
     * marker (Track A4)".
     */
    public static function deriveCallableFrom(string $authType): string {
        // apiKey is the only shape whose secret needs server-side
        // storage by convention; everything else is client-friendly
        // by default.
        if ($authType === 'apiKey') return 'server';
        return 'both';
    }

    /**
     * Resolve the effective callableFrom for an endpoint, taking
     * into account: (a) the endpoint's explicit value if set,
     * (b) the endpoint's own auth override if present, (c) the
     * parent API's auth type as the fallback.
     *
     * Returns one of 'client' | 'server' | 'both'.
     */
    public static function effectiveCallableFrom(array $api, array $endpoint): string {
        // Endpoint-level explicit value always wins.
        if (isset($endpoint['callableFrom'])
            && in_array($endpoint['callableFrom'], self::VALID_CALLABLE_FROM, true)) {
            return $endpoint['callableFrom'];
        }
        // Otherwise derive from the effective auth type — endpoint
        // override takes precedence over API-level auth.
        $authType = $endpoint['auth']['type']
            ?? $api['auth']['type']
            ?? 'none';
        return self::deriveCallableFrom($authType);
    }
    
    /**
     * Constructor
     * @param string|null $projectPath Optional project path override
     */
    public function __construct(?string $projectPath = null) {
        if ($projectPath !== null) {
            $basePath = $projectPath;
        } elseif (defined('PROJECT_PATH')) {
            $basePath = PROJECT_PATH;
        } else {
            $basePath = SECURE_FOLDER_PATH;
        }
        
        $this->configPath = $basePath . '/data/api-endpoints.json';
        
        // Ensure data directory exists
        $dataDir = dirname($this->configPath);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        // Initialize empty config if it doesn't exist
        if (!file_exists($this->configPath)) {
            $this->initializeConfig();
        }
    }
    
    /**
     * Initialize an empty config file
     * @return bool
     */
    private function initializeConfig(): bool {
        $data = [
            'version' => '1.0',
            'updated' => date('Y-m-d H:i:s'),
            'apis' => new \stdClass() // Empty object, not array
        ];
        
        return $this->saveConfig($data);
    }
    
    /**
     * Load the full config
     * @return array
     */
    public function loadConfig(): array {
        if (!file_exists($this->configPath)) {
            return [
                'version' => '1.0',
                'updated' => null,
                'apis' => []
            ];
        }
        
        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);
        
        if ($data === null) {
            return [
                'version' => '1.0',
                'updated' => null,
                'apis' => []
            ];
        }
        
        // Ensure apis is an array
        if (!isset($data['apis']) || !is_array($data['apis'])) {
            $data['apis'] = [];
        }
        
        return $data;
    }
    
    /**
     * Save the config
     * @param array $data Full config data
     * @return bool
     */
    private function saveConfig(array $data): bool {
        $data['updated'] = date('Y-m-d H:i:s');
        
        // If apis is empty array, convert to stdClass for pretty JSON output
        if (isset($data['apis']) && is_array($data['apis']) && empty($data['apis'])) {
            $data['apis'] = new \stdClass();
        }
        
        return file_put_contents(
            $this->configPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }
    
    /**
     * Get the config path
     * @return string
     */
    public function getConfigPath(): string {
        return $this->configPath;
    }
    
    // =========================================================================
    //                           API CRUD
    // =========================================================================
    
    /**
     * List all APIs
     * @return array Array of API definitions with their endpoints
     */
    public function listApis(): array {
        $config = $this->loadConfig();
        return $config['apis'] ?? [];
    }
    
    /**
     * Get a specific API by ID
     * @param string $apiId
     * @return array|null
     */
    public function getApi(string $apiId): ?array {
        $config = $this->loadConfig();
        return $config['apis'][$apiId] ?? null;
    }
    
    /**
     * Check if an API exists
     * @param string $apiId
     * @return bool
     */
    public function apiExists(string $apiId): bool {
        return $this->getApi($apiId) !== null;
    }
    
    /**
     * Add a new API
     * @param string $apiId Unique identifier
     * @param string $name Display name
     * @param string $baseUrl Base URL for all endpoints
     * @param array $auth Authentication config (optional)
     * @param string $description Optional description
     * @return array Result with success/error
     */
    public function addApi(string $apiId, string $name, string $baseUrl, array $auth = [], string $description = ''): array {
        // Validate apiId format (alphanumeric, dashes, underscores)
        if (!preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $apiId)) {
            return [
                'success' => false,
                'error' => 'Invalid API ID format. Use alphanumeric characters, dashes, and underscores. Must start with alphanumeric.'
            ];
        }
        
        // Check for duplicates
        if ($this->apiExists($apiId)) {
            return [
                'success' => false,
                'error' => "API '$apiId' already exists"
            ];
        }
        
        // Validate baseUrl — strip trailing slash/backslash first so Windows-
        // style paste like "http://test.api\\" round-trips cleanly.
        $baseUrl = rtrim($baseUrl, "/\\");
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'error' => 'Invalid base URL format'
            ];
        }

        // Validate auth if provided
        if (!empty($auth)) {
            $authValidation = $this->validateAuth($auth);
            if (!$authValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $authValidation['error']
                ];
            }
        } else {
            $auth = ['type' => 'none'];
        }

        // Build API definition
        $apiDef = [
            'name' => $name,
            'description' => $description,
            'baseUrl' => $baseUrl,
            'auth' => $auth,
            'endpoints' => [],
            'created' => date('Y-m-d H:i:s')
        ];
        
        // Save
        $config = $this->loadConfig();
        $config['apis'][$apiId] = $apiDef;
        
        if (!$this->saveConfig($config)) {
            return [
                'success' => false,
                'error' => 'Failed to save configuration'
            ];
        }
        
        return [
            'success' => true,
            'apiId' => $apiId,
            'api' => $apiDef
        ];
    }
    
    /**
     * Edit an existing API
     * @param string $apiId API to edit
     * @param array $updates Fields to update (name, baseUrl, auth, description, endpoints)
     * @return array Result
     */
    public function editApi(string $apiId, array $updates): array {
        $config = $this->loadConfig();
        
        if (!isset($config['apis'][$apiId])) {
            return [
                'success' => false,
                'error' => "API '$apiId' not found"
            ];
        }
        
        $api = $config['apis'][$apiId];

        // Tracks an endpoint id rename (['from'=>.., 'to'=>..]) so the caller
        // can run the reference cascade (interactions + page events). null
        // when no rename happened this call.
        $renamed = null;

        // Update allowed fields
        if (isset($updates['name'])) {
            $api['name'] = $updates['name'];
        }
        
        if (isset($updates['description'])) {
            $api['description'] = $updates['description'];
        }
        
        if (isset($updates['baseUrl'])) {
            // Strip trailing slash/backslash before validation — same rule as addApi.
            $newBase = rtrim($updates['baseUrl'], "/\\");
            if (!filter_var($newBase, FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'error' => 'Invalid base URL format'
                ];
            }
            $api['baseUrl'] = $newBase;
        }
        
        if (isset($updates['auth'])) {
            $authValidation = $this->validateAuth($updates['auth']);
            if (!$authValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $authValidation['error']
                ];
            }
            $api['auth'] = $updates['auth'];
        }
        
        // Handle endpoints updates (full replacement or individual operations)
        if (isset($updates['endpoints'])) {
            // Full replacement - validate all endpoints
            foreach ($updates['endpoints'] as $endpoint) {
                $validation = $this->validateEndpoint($endpoint);
                if (!$validation['valid']) {
                    return [
                        'success' => false,
                        'error' => "Invalid endpoint: " . $validation['error']
                    ];
                }
            }
            $api['endpoints'] = $updates['endpoints'];
        }
        
        // Handle single endpoint add
        if (isset($updates['addEndpoint'])) {
            $endpoint = $this->normalizeEndpointOptionalFields($updates['addEndpoint']);
            $validation = $this->validateEndpoint($endpoint);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => "Invalid endpoint: " . $validation['error']
                ];
            }
            
            // Check for duplicate endpoint ID
            foreach ($api['endpoints'] as $existing) {
                if ($existing['id'] === $endpoint['id']) {
                    return [
                        'success' => false,
                        'error' => "Endpoint ID '{$endpoint['id']}' already exists in this API"
                    ];
                }
            }
            
            $endpoint['created'] = date('Y-m-d H:i:s');
            $api['endpoints'][] = $endpoint;
        }
        
        // Handle single endpoint edit
        if (isset($updates['editEndpoint'])) {
            $endpointId = $updates['editEndpoint']['id'] ?? null;
            $endpointUpdates = $updates['editEndpoint']['updates'] ?? [];
            
            if (!$endpointId) {
                return [
                    'success' => false,
                    'error' => 'Endpoint ID required for editEndpoint'
                ];
            }
            
            $found = false;
            foreach ($api['endpoints'] as $idx => $existing) {
                if ($existing['id'] === $endpointId) {
                    // Merge updates over the existing endpoint — this keeps
                    // UNMANAGED fields (headers, responseBindings, queryParams,
                    // created) intact — then drop any MANAGED field the editor
                    // cleared (sent as an empty value).
                    $merged = array_merge($existing, $endpointUpdates);
                    $merged = $this->normalizeEndpointOptionalFields($merged);
                    $merged['updated'] = date('Y-m-d H:i:s');

                    // Validate the result
                    $validation = $this->validateEndpoint($merged);
                    if (!$validation['valid']) {
                        return [
                            'success' => false,
                            'error' => "Invalid endpoint after update: " . $validation['error']
                        ];
                    }

                    $api['endpoints'][$idx] = $merged;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                return [
                    'success' => false,
                    'error' => "Endpoint '$endpointId' not found"
                ];
            }
        }
        
        // Handle endpoint deletion
        if (isset($updates['deleteEndpoint'])) {
            $endpointId = $updates['deleteEndpoint'];
            $found = false;
            
            $api['endpoints'] = array_values(array_filter($api['endpoints'], function($ep) use ($endpointId, &$found) {
                if ($ep['id'] === $endpointId) {
                    $found = true;
                    return false;
                }
                return true;
            }));
            
            if (!$found) {
                return [
                    'success' => false,
                    'error' => "Endpoint '$endpointId' not found"
                ];
            }
        }
        
        // Handle endpoint rename (id change). Atomic + in-place: preserves all
        // fields (managed overlaid from the form, unmanaged kept via merge),
        // validates the new id, and guards against collisions. External
        // references (interactions / page events) are re-pointed by the
        // editApi command after save; refreshEndpoint refs (which live in this
        // same config file) are rewritten just below.
        if (isset($updates['renameEndpoint'])) {
            $fromId = $updates['renameEndpoint']['from'] ?? null;
            $toId = $updates['renameEndpoint']['to'] ?? null;
            $endpointUpdates = $updates['renameEndpoint']['updates'] ?? [];

            if (!$fromId || !$toId) {
                return ['success' => false, 'error' => 'renameEndpoint requires "from" and "to"'];
            }
            if (!preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $toId)) {
                return ['success' => false, 'error' => 'Invalid endpoint ID format. Use alphanumeric, dashes, underscores.'];
            }
            if ($toId !== $fromId) {
                foreach ($api['endpoints'] as $ep) {
                    if ($ep['id'] === $toId) {
                        return ['success' => false, 'error' => "Endpoint ID '$toId' already exists in this API"];
                    }
                }
            }

            $found = false;
            foreach ($api['endpoints'] as $idx => $existing) {
                if ($existing['id'] === $fromId) {
                    $merged = array_merge($existing, $endpointUpdates);
                    $merged['id'] = $toId; // the new id always wins
                    $merged = $this->normalizeEndpointOptionalFields($merged);
                    $merged['updated'] = date('Y-m-d H:i:s');

                    $validation = $this->validateEndpoint($merged);
                    if (!$validation['valid']) {
                        return ['success' => false, 'error' => "Invalid endpoint after rename: " . $validation['error']];
                    }

                    $api['endpoints'][$idx] = $merged;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['success' => false, 'error' => "Endpoint '$fromId' not found"];
            }
            $renamed = ['from' => $fromId, 'to' => $toId];
        }

        $api['updated'] = date('Y-m-d H:i:s');
        $config['apis'][$apiId] = $api;

        // Re-point refreshEndpoint references (Tier 2 auth config) that pointed
        // at the renamed endpoint, across every API in the registry.
        if ($renamed && $renamed['from'] !== $renamed['to']) {
            $oldRef = '@' . $apiId . '/' . $renamed['from'];
            $newRef = '@' . $apiId . '/' . $renamed['to'];
            foreach ($config['apis'] as $aid => $aDef) {
                if (($aDef['auth']['refreshEndpoint'] ?? null) === $oldRef) {
                    $config['apis'][$aid]['auth']['refreshEndpoint'] = $newRef;
                }
            }
        }

        if (!$this->saveConfig($config)) {
            return [
                'success' => false,
                'error' => 'Failed to save configuration'
            ];
        }

        return [
            'success' => true,
            'apiId' => $apiId,
            'api' => $api,
            'renamed' => $renamed
        ];
    }
    
    /**
     * Delete an API and all its endpoints
     * @param string $apiId
     * @return array Result
     */
    public function deleteApi(string $apiId): array {
        $config = $this->loadConfig();
        
        if (!isset($config['apis'][$apiId])) {
            return [
                'success' => false,
                'error' => "API '$apiId' not found"
            ];
        }
        
        $deletedApi = $config['apis'][$apiId];
        $endpointCount = count($deletedApi['endpoints'] ?? []);
        
        unset($config['apis'][$apiId]);
        
        if (!$this->saveConfig($config)) {
            return [
                'success' => false,
                'error' => 'Failed to save configuration'
            ];
        }
        
        return [
            'success' => true,
            'apiId' => $apiId,
            'deletedEndpoints' => $endpointCount
        ];
    }
    
    // =========================================================================
    //                        ENDPOINT QUERIES
    // =========================================================================
    
    /**
     * List all endpoints (optionally filtered by API)
     * @param string|null $apiId Filter by API, or null for all
     * @return array
     */
    public function listEndpoints(?string $apiId = null): array {
        $config = $this->loadConfig();
        $result = [];
        
        foreach ($config['apis'] as $aid => $api) {
            if ($apiId !== null && $aid !== $apiId) {
                continue;
            }
            
            foreach ($api['endpoints'] as $endpoint) {
                $result[] = array_merge($endpoint, [
                    'apiId' => $aid,
                    'apiName' => $api['name'],
                    'fullUrl' => $api['baseUrl'] . $endpoint['path']
                ]);
            }
        }
        
        return $result;
    }
    
    /**
     * Get a specific endpoint by ID
     * @param string $endpointId
     * @param string|null $apiId Optional API filter (faster lookup)
     * @return array|null Endpoint with apiId added, or null
     */
    public function getEndpoint(string $endpointId, ?string $apiId = null): ?array {
        $config = $this->loadConfig();
        
        foreach ($config['apis'] as $aid => $api) {
            if ($apiId !== null && $aid !== $apiId) {
                continue;
            }
            
            foreach ($api['endpoints'] as $endpoint) {
                if ($endpoint['id'] === $endpointId) {
                    return array_merge($endpoint, [
                        'apiId' => $aid,
                        'apiName' => $api['name'],
                        'baseUrl' => $api['baseUrl'],
                        'fullUrl' => $api['baseUrl'] . $endpoint['path'],
                        'apiAuth' => $api['auth']
                    ]);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find endpoints by ID (could be duplicates across APIs)
     * @param string $endpointId
     * @return array All matching endpoints
     */
    public function findEndpoints(string $endpointId): array {
        $config = $this->loadConfig();
        $results = [];
        
        foreach ($config['apis'] as $aid => $api) {
            foreach ($api['endpoints'] as $endpoint) {
                if ($endpoint['id'] === $endpointId) {
                    $results[] = array_merge($endpoint, [
                        'apiId' => $aid,
                        'apiName' => $api['name'],
                        'baseUrl' => $api['baseUrl'],
                        'fullUrl' => $api['baseUrl'] . $endpoint['path']
                    ]);
                }
            }
        }
        
        return $results;
    }
    
    // =========================================================================
    //                          VALIDATION
    // =========================================================================
    
    /**
     * Validate auth configuration
     * @param array $auth
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateAuth(array $auth): array {
        $type = $auth['type'] ?? null;
        
        if (!$type || !in_array($type, $this->validAuthTypes)) {
            return [
                'valid' => false,
                'error' => 'Invalid auth type. Must be one of: ' . implode(', ', $this->validAuthTypes)
            ];
        }
        
        // tokenSource is required for everything that reads a stored
        // value (bearer, apiKey, basic). 'cookie' delegates to the
        // browser (no tokenSource). 'none' is obvious.
        if ($type !== 'none' && $type !== 'cookie' && empty($auth['tokenSource'])) {
            return [
                'valid' => false,
                'error' => "Auth type '$type' requires tokenSource"
            ];
        }
        
        // Validate tokenSource format (prefix:key)
        if (!empty($auth['tokenSource'])) {
            $validPrefixes = ['localStorage', 'sessionStorage', 'config', 'header'];
            $parts = explode(':', $auth['tokenSource'], 2);

            if (count($parts) !== 2 || !in_array($parts[0], $validPrefixes)) {
                return [
                    'valid' => false,
                    'error' => 'Invalid tokenSource format. Use: localStorage:key, sessionStorage:key, config:key, or header:headerName'
                ];
            }
        }

        // Refresh-token config (Tier 2). All four primary fields move
        // together — partial config is rejected so the runtime never
        // sees a half-wired refresh path. responseRefreshTokenPath is
        // truly optional (only set when the endpoint rotates).
        $refreshKeys = ['refreshEndpoint', 'refreshTokenSource', 'refreshTokenBodyField', 'responseTokenPath'];
        $refreshSet = array_filter($refreshKeys, fn($k) => !empty($auth[$k]));
        if (!empty($refreshSet)) {
            if ($type !== 'bearer') {
                return [
                    'valid' => false,
                    'error' => "Refresh config is only supported for auth type 'bearer'"
                ];
            }
            $missing = array_diff($refreshKeys, $refreshSet);
            if (!empty($missing)) {
                return [
                    'valid' => false,
                    'error' => 'Refresh config requires all of: ' . implode(', ', $refreshKeys) . '. Missing: ' . implode(', ', $missing)
                ];
            }
            if (!preg_match('/^@[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $auth['refreshEndpoint'])) {
                return [
                    'valid' => false,
                    'error' => "Invalid refreshEndpoint format. Use: @apiId/endpointId"
                ];
            }
            // Refresh token storage: restricted to browser stores. 'config'
            // would publish the refresh token to every visitor; 'header'
            // is for sent-on-every-request keys, not stored values.
            $refreshStoragePrefixes = ['localStorage', 'sessionStorage'];
            $rParts = explode(':', $auth['refreshTokenSource'], 2);
            if (count($rParts) !== 2 || !in_array($rParts[0], $refreshStoragePrefixes)) {
                return [
                    'valid' => false,
                    'error' => 'Invalid refreshTokenSource format. Use: localStorage:key or sessionStorage:key'
                ];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Drop managed optional endpoint fields whose value is empty.
     *
     * The admin editor fully controls these fields and submits an empty
     * value ('' / [] / {}) to mean "cleared". Our storage convention is
     * "absent = default/none", so an empty value removes the key rather
     * than persisting an empty stub. Crucially this lets editEndpoint's
     * array_merge keep UNMANAGED fields (headers, responseBindings,
     * queryParams, created) while still allowing the editor to clear the
     * managed ones — previously impossible, since merge can't delete keys
     * and the frontend dropped empties as `undefined` (so a cleared
     * description / schema / endpoint-auth silently kept its old value).
     *
     * @param array $endpoint
     * @return array
     */
    private function normalizeEndpointOptionalFields(array $endpoint): array {
        // 'callableFrom' is managed (cleared via empty string in the
        // form to mean "go back to auto-derive"). Same handling as
        // other managed-optional fields.
        $managedOptional = ['description', 'auth', 'parameters', 'requestSchema', 'responseSchema', 'callableFrom'];
        foreach ($managedOptional as $field) {
            if (array_key_exists($field, $endpoint) && $this->isEmptyEndpointValue($endpoint[$field])) {
                unset($endpoint[$field]);
            }
        }
        return $endpoint;
    }

    /**
     * @param mixed $value
     * @return bool True when null, '', or an empty array/object.
     */
    private function isEmptyEndpointValue($value): bool {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value) && count($value) === 0) {
            return true;
        }
        return false;
    }

    /**
     * Validate endpoint definition
     * @param array $endpoint
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateEndpoint(array $endpoint): array {
        // Required fields
        if (empty($endpoint['id'])) {
            return ['valid' => false, 'error' => 'Endpoint ID is required'];
        }
        
        if (!preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $endpoint['id'])) {
            return [
                'valid' => false,
                'error' => 'Invalid endpoint ID format. Use alphanumeric, dashes, underscores.'
            ];
        }
        
        if (empty($endpoint['name'])) {
            return ['valid' => false, 'error' => 'Endpoint name is required'];
        }
        
        if (empty($endpoint['path'])) {
            return ['valid' => false, 'error' => 'Endpoint path is required'];
        }
        
        // Path must start with /
        if ($endpoint['path'][0] !== '/') {
            return ['valid' => false, 'error' => 'Endpoint path must start with /'];
        }
        
        if (empty($endpoint['method'])) {
            return ['valid' => false, 'error' => 'Endpoint method is required'];
        }
        
        $method = strtoupper($endpoint['method']);
        if (!in_array($method, $this->validMethods)) {
            return [
                'valid' => false,
                'error' => 'Invalid method. Must be one of: ' . implode(', ', $this->validMethods)
            ];
        }

        // Validate callableFrom (beta.8 Track A4) — optional; when
        // present, must be one of client/server/both. Absent = auto-
        // derived at read time from auth type.
        if (isset($endpoint['callableFrom'])
            && !in_array($endpoint['callableFrom'], self::VALID_CALLABLE_FROM, true)) {
            return [
                'valid' => false,
                'error' => 'Invalid callableFrom. Must be one of: ' . implode(', ', self::VALID_CALLABLE_FROM) . ' (or omit for auto-derive).'
            ];
        }

        // Validate `parameters` shape + cross-check against :placeholders in path
        $paramValidation = $this->validateParameters($endpoint);
        if (!$paramValidation['valid']) {
            return $paramValidation;
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate the optional `parameters` array on an endpoint and ensure
     * every :placeholder in `path` is declared.
     *
     * Each parameter is { name, type?, required?, description? } where:
     *  - name: required, must match /^[a-zA-Z][a-zA-Z0-9_]*$/
     *  - type: optional, one of string|number|integer|boolean
     *  - required, description: optional, free-form (boolean / string)
     *
     * Path placeholders use the `:name` syntax. Every placeholder must
     * appear in `parameters`, otherwise the user has a hole in their
     * runtime URL builder.
     *
     * @param array $endpoint
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateParameters(array $endpoint): array {
        $declared = [];
        $validTypes = ['string', 'number', 'integer', 'boolean'];

        if (isset($endpoint['parameters'])) {
            if (!is_array($endpoint['parameters'])) {
                return ['valid' => false, 'error' => "'parameters' must be an array"];
            }
            foreach ($endpoint['parameters'] as $idx => $p) {
                if (!is_array($p)) {
                    return ['valid' => false, 'error' => "Parameter at index $idx must be an object"];
                }
                $name = $p['name'] ?? '';
                if (!is_string($name) || $name === '') {
                    return ['valid' => false, 'error' => "Parameter at index $idx is missing 'name'"];
                }
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
                    return [
                        'valid' => false,
                        'error' => "Invalid parameter name '$name'. Use letters, digits, underscores; must start with a letter."
                    ];
                }
                if (in_array($name, $declared, true)) {
                    return ['valid' => false, 'error' => "Duplicate parameter name '$name'"];
                }
                if (isset($p['type']) && !in_array($p['type'], $validTypes, true)) {
                    return [
                        'valid' => false,
                        'error' => "Invalid type '{$p['type']}' for parameter '$name'. Must be one of: " . implode(', ', $validTypes)
                    ];
                }
                $declared[] = $name;
            }
        }

        // Every :placeholder in path must be declared
        if (preg_match_all('/:([a-zA-Z][a-zA-Z0-9_]*)/', $endpoint['path'], $matches)) {
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, $declared, true)) {
                    return [
                        'valid' => false,
                        'error' => "Path placeholder ':$placeholder' is not declared in 'parameters'. Add a parameter named '$placeholder' to use it in the path."
                    ];
                }
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Get valid HTTP methods
     * @return array
     */
    public function getValidMethods(): array {
        return $this->validMethods;
    }
    
    /**
     * Get valid auth types
     * @return array
     */
    public function getValidAuthTypes(): array {
        return $this->validAuthTypes;
    }
    
    /**
     * Compile API config to JavaScript
     * 
     * Generates a JS file that exposes API configs as window.QS_API_ENDPOINTS
     * This is used during build to create the runtime config.
     * 
     * @return string JavaScript code
     */
    public function compileToJs(): string {
        $config = $this->loadConfig();
        
        // If no APIs defined, return minimal config
        if (empty($config['apis'])) {
            return "/**\n * QuickSite API Endpoints (qs-api-config.js)\n * No APIs configured.\n */\nwindow.QS_API_ENDPOINTS = {};\n";
        }
        
        // Build the config object for JS
        // Only include necessary runtime fields (exclude sensitive data)
        $jsConfig = [];
        
        foreach ($config['apis'] as $apiId => $api) {
            $apiEndpoints = [];

            // Convert endpoints to keyed object for quick lookup
            if (!empty($api['endpoints'])) {
                foreach ($api['endpoints'] as $endpoint) {
                    $endpointId = $endpoint['id'] ?? null;
                    if (!$endpointId) continue;

                    // beta.8 Track A4 — callableFrom filter. Endpoints
                    // whose effective callableFrom is 'server' never reach
                    // the client config (would either leak a secret-keyed
                    // endpoint's path OR mislead QS.fetch into thinking
                    // the endpoint is callable from the browser). The
                    // future data resolver (BETA8_DATA_RESOLVER.md) reads
                    // the raw config server-side and respects the same
                    // marker — so server-only endpoints stay reachable
                    // server-side without ever appearing here.
                    if (self::effectiveCallableFrom($api, $endpoint) === 'server') {
                        continue;
                    }

                    $apiEndpoints[$endpointId] = [
                        'id' => $endpointId,
                        'name' => $endpoint['name'] ?? $endpointId,
                        'path' => $endpoint['path'] ?? '/',
                        'method' => $endpoint['method'] ?? 'GET',
                        'description' => $endpoint['description'] ?? ''
                    ];

                    // Include schema if defined (for visual editor hints)
                    if (!empty($endpoint['requestSchema'])) {
                        $apiEndpoints[$endpointId]['requestSchema'] = $endpoint['requestSchema'];
                    }
                    if (!empty($endpoint['responseSchema'])) {
                        $apiEndpoints[$endpointId]['responseSchema'] = $endpoint['responseSchema'];
                    }
                    // Include response bindings so runtime applyBindings() can use them.
                    // Some binding shapes need server-side translation here
                    // (e.g. count-sentence bindings store zeroKey/oneKey/manyKey
                    // and the runtime needs the resolved strings). The
                    // transformer below swaps keys → translated strings using
                    // the current request's language. Multi-language sites:
                    // qs-api-config.js is project-scoped, not per-language —
                    // see BACKLOG.md "Per-language count bindings" for the
                    // proper fix (inline translation registry per page render).
                    if (!empty($endpoint['responseBindings'])) {
                        $apiEndpoints[$endpointId]['responseBindings']
                            = $this->transformBindingsForCompile($endpoint['responseBindings']);
                    }
                    // Include parameters so QS.fetch can substitute :placeholders
                    // and route remaining params to query string (Step 2 wiring).
                    if (!empty($endpoint['parameters'])) {
                        $apiEndpoints[$endpointId]['parameters'] = $endpoint['parameters'];
                    }
                }
            }

            // If EVERY endpoint of this API is server-only, the API's
            // auth config + baseUrl have no client-side purpose and
            // shouldn't be emitted. Skip the API entry entirely.
            if (empty($apiEndpoints)) {
                continue;
            }

            $jsConfig[$apiId] = [
                'name' => $api['name'] ?? $apiId,
                'baseUrl' => $api['baseUrl'] ?? '',
                'auth' => $api['auth'] ?? ['type' => 'none'],
                'endpoints' => $apiEndpoints
            ];
        }

        // If every API ended up filtered (all endpoints server-only),
        // emit the same "{}" shape the no-APIs-configured early-return
        // above uses — empty PHP array would JSON-encode as "[]"
        // (an empty array literal), which is technically valid JS but
        // breaks the contract clients rely on
        // (window.QS_API_ENDPOINTS is always an object keyed by apiId).
        if (empty($jsConfig)) {
            return "/**\n * QuickSite API Endpoints (qs-api-config.js)\n * All endpoints are server-only (callableFrom='server'). No client-side config.\n */\nwindow.QS_API_ENDPOINTS = {};\n";
        }

        $jsonConfig = json_encode($jsConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        $js = "/**\n";
        $js .= " * QuickSite API Endpoints (qs-api-config.js)\n";
        $js .= " * Auto-generated during build - DO NOT EDIT\n";
        $js .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
        $js .= " */\n";
        $js .= "window.QS_API_ENDPOINTS = " . $jsonConfig . ";\n";

        return $js;
    }

    /**
     * Transform a responseBindings array for runtime consumption.
     *
     * The picker writes some bindings with translation KEYS (e.g.
     * `count` mode + `format: 'sentence'` carries `zeroKey`, `oneKey`,
     * `manyKey`). The runtime needs resolved STRINGS (no client-side
     * translation engine per project convention — PHP is the only
     * translation engine).
     *
     * This method walks each binding and swaps keys → translated
     * strings using the currently-loaded language. Pure: returns a
     * new array, doesn't mutate the input. Bindings without a
     * translation rule pass through unchanged.
     *
     * Multi-language note: the active language at compile time
     * "wins" — qs-api-config.js is project-scoped, not per-language.
     * Filed as a backlog item to fix via a per-page inline
     * translation registry.
     */
    private function transformBindingsForCompile(array $bindings): array {
        // Lazy-load Translator only when at least one binding actually
        // needs translation. Avoids a hard coupling on common-case
        // bindings that ship without any keys.
        $needsTranslator = false;
        foreach ($bindings as $b) {
            if (is_array($b)
                && ($b['renderMode'] ?? null) === 'count'
                && ($b['format']     ?? null) === 'sentence') {
                $needsTranslator = true;
                break;
            }
        }
        if ($needsTranslator) {
            require_once __DIR__ . '/Translator.php';
        }

        $out = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding)) {
                $out[] = $binding;
                continue;
            }
            $copy = $binding;

            // Count + sentence: keys → resolved strings.
            if (($copy['renderMode'] ?? null) === 'count'
                && ($copy['format']     ?? null) === 'sentence') {

                foreach ([
                    'zeroKey' => 'zeroStr',
                    'oneKey'  => 'oneStr',
                    'manyKey' => 'manyStr',
                ] as $keyField => $strField) {
                    if (isset($copy[$keyField]) && is_string($copy[$keyField]) && $copy[$keyField] !== '') {
                        $copy[$strField] = Translator::translate($copy[$keyField]);
                    }
                }
                // Drop the *Key fields from the compiled output — the
                // runtime only reads *Str. Keys stay in api-endpoints.json
                // so the picker can re-render them on edit and the
                // re-compile is repeatable.
                unset($copy['zeroKey'], $copy['oneKey'], $copy['manyKey']);
            }

            $out[] = $copy;
        }
        return $out;
    }

    /**
     * Write compiled JS to a file
     * 
     * @param string $outputPath Full path to output file
     * @return bool Success
     */
    public function writeCompiledJs(string $outputPath): bool {
        $js = $this->compileToJs();
        $dir = dirname($outputPath);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($outputPath, $js) !== false;
    }
}
