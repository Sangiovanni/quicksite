<?php
/**
 * API registry — the READ half, and the only half a served page needs.
 *
 * `ApiEndpointManager` is 1,100 lines, and almost all of it is AUTHORING:
 * add / edit / delete an API, validate a method, compile the client bundle,
 * transform count bindings. A request that renders a page uses exactly three
 * things from it — look up an API, look up an endpoint, decide whether the
 * endpoint may be called from the server — and those three are what
 * `serverFetch` needs to turn `@api/endpoint` into an HTTP call.
 *
 * WHY THE SPLIT EXISTS. A production build carries the runtime that serves its
 * pages and nothing else; that is what precompilation buys. Shipping the whole
 * manager to make three lookups work would drag the authoring surface — and its
 * `utilsManagement.php` dependency — into every deployed site. Splitting the
 * read half out means both surfaces run the SAME lookup code, and only the
 * install carries the half that writes.
 *
 * `ApiEndpointManager` delegates to these functions rather than duplicating
 * them, so there is one definition of "what is this endpoint" in the tree.
 *
 * ⚠ NOTHING HERE WRITES, and nothing here creates a directory. The manager's
 * constructor does both (it mkdir's data/ and seeds an empty config), which is
 * fine for an authoring surface and wrong for a served page — a deployed build
 * may sit on a read-only filesystem, and a page render has no business creating
 * project files.
 */

if (!function_exists('qs_api_config_path')) {
    /**
     * Where this project's API registry lives.
     *
     * @param string|null $projectPath Defaults to PROJECT_PATH, then SECURE_FOLDER_PATH.
     */
    function qs_api_config_path(?string $projectPath = null): string
    {
        if ($projectPath === null) {
            $projectPath = defined('PROJECT_PATH') ? PROJECT_PATH : SECURE_FOLDER_PATH;
        }
        return $projectPath . '/data/api-endpoints.json';
    }
}

if (!function_exists('qs_api_load_config')) {
    /**
     * The registry, or an empty one. Never fails, never writes.
     *
     * @return array{version: string, updated: ?string, apis: array}
     */
    function qs_api_load_config(?string $projectPath = null): array
    {
        $empty = ['version' => '1.0', 'updated' => null, 'apis' => []];

        $path = qs_api_config_path($projectPath);
        if (!file_exists($path)) {
            return $empty;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return $empty;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $empty;
        }
        if (!isset($data['apis']) || !is_array($data['apis'])) {
            $data['apis'] = [];
        }
        return $data;
    }
}

if (!function_exists('qs_api_get')) {
    /** One API's definition (name, baseUrl, auth, endpoints), or null. */
    function qs_api_get(string $apiId, ?string $projectPath = null): ?array
    {
        $config = qs_api_load_config($projectPath);
        return $config['apis'][$apiId] ?? null;
    }
}

if (!function_exists('qs_api_get_endpoint')) {
    /**
     * One endpoint, flattened with the API context a caller needs to issue the
     * request: `apiId`, `apiName`, `baseUrl`, `fullUrl`, `apiAuth`.
     *
     * @param string      $endpointId Endpoint id.
     * @param string|null $apiId      Restrict the search to one API.
     */
    function qs_api_get_endpoint(string $endpointId, ?string $apiId = null, ?string $projectPath = null): ?array
    {
        $config = qs_api_load_config($projectPath);

        foreach ($config['apis'] as $aid => $api) {
            if ($apiId !== null && $aid !== $apiId) {
                continue;
            }
            foreach (($api['endpoints'] ?? []) as $endpoint) {
                if (($endpoint['id'] ?? null) === $endpointId) {
                    return array_merge($endpoint, [
                        'apiId'   => $aid,
                        'apiName' => $api['name'] ?? $aid,
                        'baseUrl' => $api['baseUrl'] ?? '',
                        'fullUrl' => ($api['baseUrl'] ?? '') . ($endpoint['path'] ?? ''),
                        'apiAuth' => $api['auth'] ?? [],
                    ]);
                }
            }
        }
        return null;
    }
}

if (!function_exists('qs_api_derive_callable_from')) {
    /**
     * Where an endpoint may be called from, inferred from its auth type.
     *
     * `apiKey` is the one shape whose secret is meant to live server-side, so
     * it defaults to server-only; every other shape keeps its credential on the
     * client and defaults to both.
     */
    function qs_api_derive_callable_from(string $authType): string
    {
        return $authType === 'apiKey' ? 'server' : 'both';
    }
}

if (!function_exists('qs_api_effective_callable_from')) {
    /**
     * The endpoint's effective `callableFrom`: an explicit value wins,
     * otherwise it is derived from the endpoint's auth type, otherwise the
     * API's.
     */
    function qs_api_effective_callable_from(array $api, array $endpoint): string
    {
        $valid = ['client', 'server', 'both'];
        if (isset($endpoint['callableFrom']) && in_array($endpoint['callableFrom'], $valid, true)) {
            return $endpoint['callableFrom'];
        }
        $authType = $endpoint['auth']['type'] ?? $api['auth']['type'] ?? 'none';
        return qs_api_derive_callable_from((string) $authType);
    }
}
