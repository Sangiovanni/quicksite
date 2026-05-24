<?php
/**
 * StateStoreManager
 *
 * Read/write per-page **state-store** definitions used by the client runtime
 * (qs.js reads window.QS_STATE_STORES, emitted per page from this file).
 *
 * Storage: secure/projects/{project}/data/state-stores.json (per-project),
 * keyed by route path:
 * {
 *   "home": {
 *     "commandsList": {
 *       "endpoint": "@help-api/list",
 *       "fetchOnLoad": true,
 *       "fields": {
 *         "page":  { "dir": "request",  "init": "query:page", "default": 1 },
 *         "items": { "dir": "response", "from": "data", "append": false }
 *       }
 *     }
 *   }
 * }
 *
 * Runtime-agnostic by design: the definition is plain data so beta.8's
 * server-side data-resolver can read the same shape.
 */
class StateStoreManager {

    /** @var string Path to state-stores.json */
    private string $configPath;

    public function __construct(?string $projectPath = null) {
        if ($projectPath !== null) {
            $base = $projectPath;
        } elseif (defined('PROJECT_PATH')) {
            $base = PROJECT_PATH;
        } else {
            $base = SECURE_FOLDER_PATH;
        }
        $this->configPath = $base . '/data/state-stores.json';
    }

    /** All stores across all routes (route => {storeId => def}). */
    public function loadAll(): array {
        if (!file_exists($this->configPath)) {
            return [];
        }
        $content = @file_get_contents($this->configPath);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /** Stores for one route ({storeId => def}). */
    public function getForRoute(string $route): array {
        $all = $this->loadAll();
        return $all[$route] ?? [];
    }

    /**
     * Replace the whole store-set for a route (read-modify-write from the
     * admin panel). Passing an empty set removes the route's entry.
     *
     * @return array { success: bool, error?: string, route?: string, stores?: array }
     */
    public function setForRoute(string $route, array $stores): array {
        foreach ($stores as $storeId => $def) {
            if (!preg_match('/^[a-zA-Z][\w-]*$/', (string) $storeId)) {
                return ['success' => false, 'error' => "Invalid store id: '$storeId'"];
            }
            $validation = $this->validateStore($def);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => "Store '$storeId': " . $validation['error']];
            }
        }

        $all = $this->loadAll();
        if (empty($stores)) {
            unset($all[$route]);
        } else {
            $all[$route] = $stores;
        }

        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $written = file_put_contents(
            $this->configPath,
            json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($written === false) {
            return ['success' => false, 'error' => 'Failed to save state stores'];
        }
        return ['success' => true, 'route' => $route, 'stores' => $stores];
    }

    /**
     * Validate one store definition.
     * @param mixed $def
     * @return array { valid: bool, error: string|null }
     */
    private function validateStore($def): array {
        if (!is_array($def)) {
            return ['valid' => false, 'error' => 'store must be an object'];
        }
        $endpoint = $def['endpoint'] ?? '';
        if (!is_string($endpoint) || !preg_match('#^@[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+$#', $endpoint)) {
            return ['valid' => false, 'error' => 'endpoint must be "@apiId/endpointId"'];
        }
        $fields = $def['fields'] ?? null;
        if (!is_array($fields) || empty($fields)) {
            return ['valid' => false, 'error' => 'at least one field is required'];
        }
        foreach ($fields as $name => $f) {
            if (!preg_match('/^[a-zA-Z_][\w-]*$/', (string) $name)) {
                return ['valid' => false, 'error' => "invalid field name '$name'"];
            }
            if (!is_array($f)) {
                return ['valid' => false, 'error' => "field '$name' must be an object"];
            }
            $dir = $f['dir'] ?? '';
            if (!in_array($dir, ['request', 'response', 'both'], true)) {
                return ['valid' => false, 'error' => "field '$name': dir must be request|response|both"];
            }
            if (($dir === 'response' || $dir === 'both') && empty($f['from'])) {
                return ['valid' => false, 'error' => "field '$name': 'from' (response path) is required for dir '$dir'"];
            }
        }
        return ['valid' => true, 'error' => null];
    }
}
