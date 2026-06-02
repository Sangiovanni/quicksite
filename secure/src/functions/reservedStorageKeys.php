<?php
/**
 * reservedStorageKeys.php — Server-side admin-namespace storage-key guard.
 *
 * Mirrors the JS-side check shipped late beta.7 in
 * public/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js
 * (slice 5). The admin panel and the user's site share the browser's
 * storage origin: if a user authors `data-storage-value="localStorage:X"`
 * where X collides with an admin key (auth tokens, AI settings, etc.),
 * the rendered page can READ or CLEAR the admin's state.
 *
 * The JS check is UX. This file is the SECURITY layer — without it, a
 * user with a valid token can POST directly to addNode / editStructure
 * with a hostile params payload and bypass the picker entirely.
 *
 * Strategy: a single regex (matching JS exactly) catches the four
 * naming prefixes the admin uses. We don't maintain an exact-match
 * list — too easy to drift. Project keys should use a project-specific
 * prefix anyway.
 *
 *   quicksite_*  (snake)   e.g. quicksite_admin_token
 *   quicksite-*  (kebab)   e.g. quicksite-tools-show-names
 *   qs_*         (snake)   e.g. qs_api_auth_tokens
 *   qs-*         (kebab)   e.g. qs-add-last-tab
 *
 * Three attribute names carry storage-key references in their values:
 *   data-storage-show   "has:loc:key" or "missing:loc:key"
 *   data-storage-value  "loc:key"
 *   data-auth-source    "loc:key"
 *
 * Callers hook into the commands that accept structure params:
 *   addNode, editNode, addComponentToNode, editComponentToNode
 *     → validateParamsForReservedKeys($params)
 *   editStructure, addComplexElement (write whole subtrees)
 *     → validateStructureForReservedKeys($structure)
 *
 * On violation, the helper returns a list of errors. Each caller
 * decides how to surface (typically 400 validation.reserved_key).
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

const RESERVED_STORAGE_KEY_PATTERN = '/^(quicksite[_-]|qs[_-])/i';

const RESERVED_STORAGE_KEY_MESSAGE = 'Reserved admin-namespace prefix '
    . '(quicksite_ / quicksite- / qs_ / qs-). These are used by the '
    . 'admin panel and would collide with admin state. Pick a '
    . 'project-specific prefix.';

/** Attributes whose value carries a storage-key reference. */
const STORAGE_KEY_BEARING_ATTRS = [
    'data-storage-show',   // "has:loc:key" or "missing:loc:key"
    'data-storage-value',  // "loc:key"
    'data-auth-source',    // "loc:key"
];

/**
 * Is this storage key in the reserved admin namespace?
 */
function isReservedStorageKey(string $key): bool {
    return $key !== '' && preg_match(RESERVED_STORAGE_KEY_PATTERN, $key) === 1;
}

/**
 * Extract the storage KEY from a `data-storage-*` / `data-auth-source`
 * value. Returns null if the value doesn't have a storage-key segment
 * to check (e.g. malformed input we can't parse — let other validators
 * handle that; we only flag the reserved-key case).
 *
 * Formats handled:
 *   "localStorage:authToken"            → "authToken"
 *   "sessionStorage:foo"                → "foo"
 *   "has:localStorage:authToken"        → "authToken"
 *   "missing:sessionStorage:foo"        → "foo"
 *   "loc:key:with:colons"               → "key:with:colons"   (defensive)
 */
function extractStorageKeyFromValue(string $value): ?string {
    if ($value === '') return null;
    $parts = explode(':', $value);
    if (count($parts) < 2) return null;

    // 3-part shape: "has:loc:key" / "missing:loc:key"
    if (in_array($parts[0], ['has', 'missing'], true) && count($parts) >= 3) {
        // Re-join from index 2 so colons in keys (rare) survive.
        return implode(':', array_slice($parts, 2));
    }
    // 2-part shape: "loc:key"
    if (in_array($parts[0], ['localStorage', 'sessionStorage'], true)) {
        return implode(':', array_slice($parts, 1));
    }
    return null;
}

/**
 * Scan a single params array (`{key: value, ...}`) for reserved-key
 * violations on any storage-key-bearing attribute.
 *
 * @return array[] list of error rows. Empty when nothing reserved.
 *                 Each row: [
 *                   'param' => string,      // e.g. 'data-storage-show'
 *                   'value' => string,      // the raw attribute value
 *                   'reservedKey' => string,// the extracted reserved key
 *                 ]
 */
function findReservedKeysInParams(array $params): array {
    $errors = [];
    foreach (STORAGE_KEY_BEARING_ATTRS as $attr) {
        if (!isset($params[$attr])) continue;
        $value = $params[$attr];
        // Defensive: params can be strings, scalars, or arrays. Skip
        // anything that isn't a parseable scalar string.
        if (!is_string($value)) continue;
        $key = extractStorageKeyFromValue($value);
        if ($key !== null && isReservedStorageKey($key)) {
            $errors[] = [
                'param' => $attr,
                'value' => $value,
                'reservedKey' => $key,
            ];
        }
    }
    return $errors;
}

/**
 * Walk a structure tree (or list of trees) recursively, scanning each
 * node's `params` for reserved-key violations. Each error row also
 * carries a `node_path` so the caller can surface where the violation
 * lives in the tree.
 *
 * @param mixed  $structure The node, list of nodes, or arbitrary
 *                          shape passed to editStructure /
 *                          addComplexElement.
 * @param string $path      Internal — used during recursion.
 * @return array[] list of error rows (same shape as findReservedKeysInParams
 *                 plus a 'node_path' string field).
 */
function findReservedKeysInStructure($structure, string $path = ''): array {
    $errors = [];
    if (!is_array($structure)) return $errors;

    // Detect "node" vs "list of nodes". A list is a sequential int-keyed
    // array; a node is an assoc array with `tag` / `component` / `textKey`.
    $isList = array_keys($structure) === range(0, count($structure) - 1);

    if ($isList) {
        foreach ($structure as $i => $child) {
            $childPath = $path === '' ? (string)$i : ($path . '.' . $i);
            $errors = array_merge($errors, findReservedKeysInStructure($child, $childPath));
        }
        return $errors;
    }

    // Single node — check its params + recurse into children
    if (isset($structure['params']) && is_array($structure['params'])) {
        $paramErrors = findReservedKeysInParams($structure['params']);
        foreach ($paramErrors as $err) {
            $err['node_path'] = $path === '' ? '(root)' : $path;
            $errors[] = $err;
        }
    }
    if (isset($structure['children']) && is_array($structure['children'])) {
        $childPath = $path === '' ? 'children' : ($path . '.children');
        $errors = array_merge($errors, findReservedKeysInStructure($structure['children'], $childPath));
    }

    return $errors;
}

/**
 * Convenience: format error rows into the shape ApiResponse->withErrors()
 * consumes. Each row becomes {field, reason, value, reservedKey, hint}.
 */
function formatReservedKeyErrors(array $errors): array {
    return array_map(function ($e) {
        $row = [
            'field' => 'params.' . $e['param'],
            'reason' => 'reserved_storage_key',
            'value' => $e['value'],
            'reservedKey' => $e['reservedKey'],
            'hint' => RESERVED_STORAGE_KEY_MESSAGE,
        ];
        if (isset($e['node_path'])) $row['node_path'] = $e['node_path'];
        return $row;
    }, $errors);
}
