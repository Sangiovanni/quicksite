<?php
/**
 * storageScanHelpers.php — scan the current build for storage-key references
 * and reconcile them against the declared registry (storage.json).
 *
 * Surfaces scanned:
 *  - Structure JSON (pages / menu / footer / components):
 *      READS  — data-storage-value / data-storage-show / data-auth-source attrs
 *      WRITES — {{call:saveToken|store:STORAGE,KEY,…}} chains in param values
 *      CLEARS — {{call:clearToken:STORAGE,KEY}} chains
 *  - data/api-endpoints.json — auth.tokenSource / refreshTokenSource (READS)
 *  - data/page-events.json — per-route event handler chains (WRITES / CLEARS)
 *  - State stores (per page) — init sources localStorage:/sessionStorage: (READS)
 *
 * Reconcile states (see NOTES/planning/BETA9_STORAGE_REGISTRY.md):
 *   ok             declared + written
 *   incomplete     written/referenced but UNDECLARED (the GDPR gap — declare it)
 *   dangling_read  read somewhere but never written (likely a deleted writer)
 *   orphan         declared but unreferenced (ignore)
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/reservedStorageKeys.php';

const STORAGE_READ_ATTRS  = ['data-storage-value', 'data-storage-show', 'data-auth-source'];
const STORAGE_WRITE_VERBS = ['saveToken', 'store'];

/** Best-effort scope ('localStorage' / 'sessionStorage') from a ref string. */
function _scopeFromStorageRef(string $value): ?string {
    if (strpos($value, 'localStorage') !== false) return 'localStorage';
    if (strpos($value, 'sessionStorage') !== false) return 'sessionStorage';
    return null;
}

/**
 * Parse a string for {{call:VERB:ARGS}} chains targeting storage verbs.
 * @return array[] each ['verb' => ..., 'storage' => ..., 'key' => ...]
 */
function _scanChainStorageRefs(string $value): array {
    $out = [];
    if (strpos($value, '{{call:') === false) return $out;
    if (preg_match_all('/\{\{call:(saveToken|store|clearToken):([^}]*)\}\}/', $value, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $args = array_map('trim', explode(',', $m[2]));
            $key = $args[1] ?? '';            // storage = 0, key = 1, path = 2
            if ($key !== '') {
                $out[] = ['verb' => $m[1], 'storage' => $args[0] ?? '', 'key' => $key];
            }
        }
    }
    return $out;
}

/** Add a reference under $key's $bucket ('writers'|'clearers'|'readers'). */
function _addStorageRef(array &$refs, string $key, string $bucket, string $location, ?string $scope): void {
    if (!isset($refs[$key])) {
        $refs[$key] = ['writers' => [], 'clearers' => [], 'readers' => [], 'scope' => null];
    }
    if (!in_array($location, $refs[$key][$bucket], true)) {
        $refs[$key][$bucket][] = $location;
    }
    if ($scope !== null && $refs[$key]['scope'] === null) {
        $refs[$key]['scope'] = $scope;
    }
}

/** Recursively walk a structure node tree collecting storage refs. */
function _walkStructureForStorage($node, string $location, array &$refs, int $depth = 0): void {
    if ($depth > 25 || !is_array($node)) return;

    // List of nodes vs single node (mirrors reservedStorageKeys.php).
    if (!empty($node) && array_keys($node) === range(0, count($node) - 1)) {
        foreach ($node as $child) {
            _walkStructureForStorage($child, $location, $refs, $depth + 1);
        }
        return;
    }

    if (isset($node['params']) && is_array($node['params'])) {
        foreach ($node['params'] as $pname => $pval) {
            if (!is_string($pval)) continue;
            // Read-bearing attributes.
            if (in_array($pname, STORAGE_READ_ATTRS, true)) {
                $k = extractStorageKeyFromValue($pval);
                if ($k !== null && $k !== '') {
                    _addStorageRef($refs, $k, 'readers', $location, _scopeFromStorageRef($pval));
                }
            }
            // Write / clear verbs inside any chain-bearing param (on* attrs).
            foreach (_scanChainStorageRefs($pval) as $ref) {
                $bucket = ($ref['verb'] === 'clearToken') ? 'clearers' : 'writers';
                _addStorageRef($refs, $ref['key'], $bucket, $location, _scopeFromStorageRef($ref['storage']));
            }
        }
    }

    if (isset($node['children']) && is_array($node['children'])) {
        _walkStructureForStorage($node['children'], $location, $refs, $depth + 1);
    }
}

/** Recursively find auth.tokenSource / refreshTokenSource in api-endpoints.json. */
function _scanApiAuthSources($data, array &$refs, string $location = 'api-endpoints'): void {
    if (!is_array($data)) return;
    foreach ($data as $k => $v) {
        if (($k === 'tokenSource' || $k === 'refreshTokenSource') && is_string($v)) {
            $key = extractStorageKeyFromValue($v);
            if ($key !== null && $key !== '') {
                _addStorageRef($refs, $key, 'readers', $location, _scopeFromStorageRef($v));
            }
        } elseif (is_array($v)) {
            _scanApiAuthSources($v, $refs, $location);
        }
    }
}

/**
 * Page-event handler chains (data/page-events.json) — the per-route `onload`
 * etc. bindings carry the same {{call:saveToken|store|clearToken:…}} chains as
 * structure params, so they are a write/clear surface that must be scanned.
 * Shape: { "<route>": { "<event>": [ "<chain>", … ] } }.
 */
function _scanPageEvents(array &$refs): void {
    $file = PROJECT_PATH . '/data/page-events.json';
    if (!file_exists($file)) return;
    $events = json_decode(@file_get_contents($file), true);
    if (!is_array($events)) return;
    foreach ($events as $route => $handlers) {
        _scanEventChains($handlers, $refs, 'events:' . $route);
    }
}

/** Recursively run chain scanning over every string under a page's handlers. */
function _scanEventChains($data, array &$refs, string $location): void {
    if (is_string($data)) {
        foreach (_scanChainStorageRefs($data) as $ref) {
            $bucket = ($ref['verb'] === 'clearToken') ? 'clearers' : 'writers';
            _addStorageRef($refs, $ref['key'], $bucket, $location, _scopeFromStorageRef($ref['storage']));
        }
        return;
    }
    if (is_array($data)) {
        foreach ($data as $v) {
            _scanEventChains($v, $refs, $location);
        }
    }
}

/** Best-effort: state-store init sources that reference browser storage. */
function _scanStateStoreInit(array &$refs): void {
    $managerFile = SECURE_FOLDER_PATH . '/src/classes/StateStoreManager.php';
    if (!file_exists($managerFile)) return;
    require_once $managerFile;
    if (!class_exists('StateStoreManager')) return;
    try {
        $manager = new StateStoreManager();
        $all = method_exists($manager, 'loadAll') ? $manager->loadAll() : null;
        if (is_array($all)) {
            _scanStringsForStoragePrefix($all, $refs, 'state-store');
        }
    } catch (\Throwable $e) {
        // best-effort — never let a state-store quirk abort the scan
    }
}

/** Walk any nested data; any "localStorage:key" / "sessionStorage:key" string is a read. */
function _scanStringsForStoragePrefix($data, array &$refs, string $location): void {
    if (is_string($data)) {
        if (preg_match('/^(localStorage|sessionStorage):(.+)$/', $data, $m)) {
            _addStorageRef($refs, $m[2], 'readers', $location, $m[1]);
        }
        return;
    }
    if (is_array($data)) {
        foreach ($data as $v) {
            _scanStringsForStoragePrefix($v, $refs, $location);
        }
    }
}

/** Reconcile a collected ref map against the declared registry. */
function _reconcileStorage(array $refs): array {
    $declared = loadStorageRegistry()['items'];
    $buckets = ['ok' => [], 'incomplete' => [], 'dangling_read' => [], 'orphan' => []];

    foreach ($refs as $key => $r) {
        $written = !empty($r['writers']);
        $read = !empty($r['readers']);
        $isDeclared = isset($declared[$key]);
        $row = [
            'id'           => $key,
            'declared'     => $isDeclared,
            'inferredScope' => $r['scope'],
            'writers'      => $r['writers'],
            'readers'      => $r['readers'],
            'clearers'     => $r['clearers'],
        ];
        if ($read && !$written) {
            $buckets['dangling_read'][] = $row;     // read with no writer — flaw signal
        } elseif ($isDeclared) {
            $buckets['ok'][] = $row;                // declared + written/cleared
        } else {
            $buckets['incomplete'][] = $row;        // written/referenced but undeclared
        }
    }

    foreach ($declared as $key => $item) {
        if (!isset($refs[$key])) {
            $buckets['orphan'][] = [
                'id' => $key, 'declared' => true,
                'scope' => $item['scope'] ?? null, 'category' => $item['category'] ?? null,
            ];
        }
    }

    return [
        'buckets' => $buckets,
        'counts'  => [
            'ok'            => count($buckets['ok']),
            'incomplete'    => count($buckets['incomplete']),
            'dangling_read' => count($buckets['dangling_read']),
            'orphan'        => count($buckets['orphan']),
        ],
    ];
}

/** Full scan + reconcile. */
function scanStorageUsage(): array {
    $refs = [];

    foreach (scanAllPageJsonFiles() as $pageInfo) {
        $path = $pageInfo['path'] ?? null;
        if (!$path) continue;
        $structure = loadJsonStructure($path);
        if (is_array($structure)) {
            $label = $pageInfo['name'] ?? $pageInfo['route'] ?? basename(dirname($path));
            _walkStructureForStorage($structure, 'page:' . $label, $refs);
        }
    }

    foreach (['menu', 'footer'] as $layout) {
        $f = PROJECT_PATH . '/templates/model/json/' . $layout . '.json';
        if (file_exists($f)) {
            $s = loadJsonStructure($f);
            if (is_array($s)) _walkStructureForStorage($s, $layout, $refs);
        }
    }

    $componentDir = PROJECT_PATH . '/templates/model/json/components';
    if (is_dir($componentDir)) {
        foreach (glob($componentDir . '/*.json') ?: [] as $cf) {
            $s = loadJsonStructure($cf);
            if (is_array($s)) _walkStructureForStorage($s, 'component:' . basename($cf, '.json'), $refs);
        }
    }

    $apiFile = PROJECT_PATH . '/data/api-endpoints.json';
    if (file_exists($apiFile)) {
        $apis = json_decode(@file_get_contents($apiFile), true);
        if (is_array($apis)) _scanApiAuthSources($apis, $refs);
    }

    _scanPageEvents($refs);
    _scanStateStoreInit($refs);

    return _reconcileStorage($refs);
}
