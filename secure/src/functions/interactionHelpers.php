<?php
/**
 * interactionHelpers.php - Shared constants and helpers for interaction commands
 * 
 * This file is included by: listInteractions, addInteraction, editInteraction, deleteInteraction
 * Prefixed with underscore to indicate it's not a command itself.
 */

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

// =============================================================================
// EVENT CONSTANTS - Available events by element type
// =============================================================================
//
// Buckets returned by getAvailableEventsForTag():
//   - common     : the 3-6 events most likely used on this tag
//   - lessCommon : remaining standard non-deprecated events
//   - advanced   : touch / contextmenu / clipboard / focusin-out
//
// Removed in beta.6 (deprecated/duplicates - DO NOT re-add):
//   onkeypress (deprecated), onunload (deprecated),
//   onmouseover (use onmouseenter), onmouseout (use onmouseleave)

/**
 * Universal events available on all elements (excluding advanced bucket).
 * onmouseenter / onmouseleave kept; onmouseover / onmouseout removed.
 * onkeydown / onkeyup kept; onkeypress removed (deprecated).
 */
if (!defined('UNIVERSAL_EVENTS')) {
    define('UNIVERSAL_EVENTS', [
        'onclick', 'ondblclick',
        'onmouseenter', 'onmouseleave', 'onmousemove',
        'onmousedown', 'onmouseup',
        'onfocus', 'onblur',
        'onkeydown', 'onkeyup'
    ]);
}

/**
 * Events specific to certain tags (added on top of UNIVERSAL_EVENTS).
 * onunload removed from body (deprecated; use onbeforeunload / pagehide).
 */
if (!defined('TAG_SPECIFIC_EVENTS')) {
    define('TAG_SPECIFIC_EVENTS', [
        'form' => ['onsubmit', 'onreset'],
        'input' => ['oninput', 'onchange'],
        'textarea' => ['oninput', 'onchange'],
        'select' => ['onchange'],
        'details' => ['ontoggle'],
        'video' => ['onplay', 'onpause', 'onended', 'onvolumechange', 'ontimeupdate'],
        'audio' => ['onplay', 'onpause', 'onended', 'onvolumechange', 'ontimeupdate'],
        'img' => ['onload', 'onerror'],
        'iframe' => ['onload'],
        'body' => ['onload', 'onresize', 'onscroll']
    ]);
}

/**
 * Curated "Common" event lists per tag.
 * These appear in the picker first, scoped to the selected element's tag.
 * Tags not listed fall back to ['onclick', 'onmouseenter', 'onmouseleave'].
 *
 * Tag-specific events (TAG_SPECIFIC_EVENTS) are merged in automatically -
 * they always belong in Common for their tag.
 */
if (!defined('COMMON_EVENTS_BY_TAG')) {
    define('COMMON_EVENTS_BY_TAG', [
        'button'   => ['onclick', 'ondblclick', 'onmouseenter', 'onmouseleave', 'onfocus', 'onblur'],
        'a'        => ['onclick', 'onmouseenter', 'onmouseleave', 'onfocus', 'onblur'],
        'input'    => ['onfocus', 'onblur', 'onkeydown'],
        'textarea' => ['onfocus', 'onblur'],
        'select'   => ['onfocus', 'onblur'],
        'form'     => [],
        'img'      => ['onclick'],
        'video'    => [],
        'audio'    => [],
        'details'  => [],
    ]);
}

/**
 * Default Common bucket for tags not in COMMON_EVENTS_BY_TAG.
 * Three truly-generic events keep the Common group meaningful on
 * <div>, <span>, <p>, <section>, etc.
 */
if (!defined('DEFAULT_COMMON_EVENTS')) {
    define('DEFAULT_COMMON_EVENTS', [
        'onclick', 'onmouseenter', 'onmouseleave'
    ]);
}

/**
 * Advanced events - hidden by default in the picker.
 *  - 'universal' bucket is offered on every tag
 *  - per-tag bucket is added when the element matches
 */
if (!defined('ADVANCED_EVENTS')) {
    define('ADVANCED_EVENTS', [
        'universal' => [
            'oncontextmenu',
            'ontouchstart', 'ontouchend', 'ontouchmove',
            'onfocusin', 'onfocusout',
        ],
        'input'    => ['onpaste', 'oncopy', 'oncut'],
        'textarea' => ['onpaste', 'oncopy', 'oncut'],
    ]);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get available events for a specific tag, bucketed for picker rendering.
 *
 * @param string $tag HTML tag name (e.g. 'button', 'input', 'div').
 * @return array {
 *     common:     string[] events shown in "Common for {tag}" optgroup,
 *     lessCommon: string[] events shown in "Less common" optgroup,
 *     advanced:   string[] events shown in "Advanced" optgroup
 * }
 */
if (!function_exists('getAvailableEventsForTag')) {
    function getAvailableEventsForTag(string $tag): array {
        $tagSpecific = TAG_SPECIFIC_EVENTS[$tag] ?? [];

        // 1. Common = curated map (or default) + tag-specific events not already in.
        $curated = COMMON_EVENTS_BY_TAG[$tag] ?? DEFAULT_COMMON_EVENTS;
        $common = array_values(array_unique(array_merge($curated, $tagSpecific)));

        // 2. Less common = (UNIVERSAL_EVENTS minus Common).
        $lessCommon = array_values(array_diff(UNIVERSAL_EVENTS, $common));

        // 3. Advanced = universal advanced + per-tag advanced.
        $advanced = ADVANCED_EVENTS['universal'];
        if (isset(ADVANCED_EVENTS[$tag])) {
            $advanced = array_merge($advanced, ADVANCED_EVENTS[$tag]);
        }
        $advanced = array_values(array_unique($advanced));

        return [
            'common'     => $common,
            'lessCommon' => $lessCommon,
            'advanced'   => $advanced,
        ];
    }
}

/**
 * Flatten the bucketed shape returned by getAvailableEventsForTag()
 * into a single ordered list (common -> lessCommon -> advanced).
 * Use only for callers that genuinely need a flat list (legacy contracts).
 *
 * @param array $bucketed Output of getAvailableEventsForTag().
 * @return string[] Flat ordered list of event names.
 */
if (!function_exists('flattenAvailableEvents')) {
    function flattenAvailableEvents(array $bucketed): array {
        return array_values(array_unique(array_merge(
            $bucketed['common']     ?? [],
            $bucketed['lessCommon'] ?? [],
            $bucketed['advanced']   ?? []
        )));
    }
}

/**
 * Parse {{call:function:params}} syntax from a string
 * Returns array of parsed interactions
 */
if (!function_exists('parseCallSyntax')) {
    function parseCallSyntax(string $value): array {
        $interactions = [];
        
        // Match all {{call:...}} patterns
        // Pattern: {{call:functionName:param1,param2,...}} or {{call:functionName}}
        preg_match_all('/\{\{call:([^:}]+)(?::([^}]*))?\}\}/', $value, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $functionName = $match[1];
            $paramsString = $match[2] ?? '';
            
            // Parse params: split on commas NOT preceded by a backslash
            // (so a single param can contain a literal comma escaped as "\,"),
            // and not inside [...] either. Then unescape "\," → ",".
            $params = [];
            if (!empty($paramsString)) {
                $params = preg_split('/(?<!\\\\),(?![^\[]*\])/', $paramsString);
                $params = array_map(function ($p) {
                    return trim(str_replace('\\,', ',', $p));
                }, $params);
            }
            
            $interactions[] = [
                'function' => $functionName,
                'params' => $params,
                'raw' => $match[0]
            ];
        }
        
        return $interactions;
    }
}

/**
 * Generate {{call:function:params}} syntax
 */
if (!function_exists('generateCallSyntax')) {
    function generateCallSyntax(string $function, array $params): string {
        if (empty($params)) {
            return "{{call:{$function}}}";
        }
        // Escape commas inside individual params so a single param can contain
        // a literal comma (e.g. a multi-selector matchAttr like ".a, .b").
        $encoded = array_map(function ($p) {
            return str_replace(',', '\\,', (string)$p);
        }, $params);
        return "{{call:{$function}:" . implode(',', $encoded) . "}}";
    }
}

/**
 * Beta.9 A2 Slice 5 follow-up — extract the :param names declared on a
 * page slug. The in-memory + over-the-wire slug carries the literal
 * `:name` form straight from routes.php (e.g. "auth/magic/:key" →
 * ["key"], "user/:id/posts/:postid" → ["id", "postid"]). The NTFS-safe
 * `:` ↔ `__` sanitisation only kicks in when building file-system paths;
 * defensive: tolerate the sanitised `__name` form too in case any future
 * surface sends it.
 *
 * Used by validateInteractionArgs() when an arg declares
 * `inputType: 'routeParam'` (today: exchangeMagicLink.paramName) — the
 * verb reads QS.routeParams[name] at runtime, so name MUST be one of
 * the route's :params.
 */
if (!function_exists('routeParamsForPageSlug')) {
    function routeParamsForPageSlug(string $slug): array {
        $params = [];
        $seen   = [];
        foreach (explode('/', $slug) as $seg) {
            $name = null;
            if ($seg !== '' && $seg[0] === ':' && strlen($seg) > 1) {
                $name = substr($seg, 1);
            } elseif (strpos($seg, '__') === 0 && strlen($seg) > 2) {
                $name = substr($seg, 2);
            }
            if ($name !== null && !isset($seen[$name])) {
                $seen[$name] = true;
                $params[] = $name;
            }
        }
        return $params;
    }
}

/**
 * Beta.9 A2 Slice 5 follow-up — validate that a verb's required positional
 * args are present (non-empty) in the supplied params array AND that
 * inputType-specific values match their contract.
 *
 * Without the required-arg check, a client serializer that compacts empties
 * (which preview-js-interactions.js did before this slice) could land
 * later args in the slots of earlier ones — e.g. exchangeMagicLink with
 * only returnTo filled would persist as
 *   {{call:exchangeMagicLink:/dashboard}}
 * with "/dashboard" mis-bound to the `endpoint` arg (position 0).
 *
 * Without the routeParam value check, a paramName that doesn't exist in
 * the current page's route (typo or stale config) would pass save and
 * fail silently at runtime (QS.routeParams[badName] = undefined).
 *
 * Defense-in-depth: the client-side validator should catch this first
 * and surface field-level errors, but this server check guards against
 * direct API callers, batch imports, and future client regressions.
 *
 * @param string      $verb     The verb name (e.g. "redirect", "setState").
 * @param array       $params   Positional values as collected from the form.
 *                              Empty/missing slots count as "not provided".
 * @param string|null $pageName Optional page slug for inputType-aware checks
 *                              (today: 'routeParam'). When null, those
 *                              context-dependent checks are skipped — the
 *                              basic required-arg check still runs.
 *
 * @return array  Empty array on success.
 *                On failure: list of [
 *                    'field'   => arg name,
 *                    'index'   => positional index (0-based),
 *                    'reason'  => 'missing' | 'unknown_verb' | 'invalid_route_param',
 *                    'hint'    => human-readable hint,
 *                ].
 *                When the verb itself is unknown (not in the catalog),
 *                returns a single entry with field='function',
 *                reason='unknown_verb' so the caller can return 422
 *                without consulting the verb's argspec.
 */
if (!function_exists('validateInteractionArgs')) {
    function validateInteractionArgs(string $verb, array $params, ?string $pageName = null): array {
        require_once SECURE_FOLDER_PATH . '/src/functions/qsVerbCatalog.php';

        $catalog = qsVerbCatalog();
        $entry = null;
        foreach ($catalog as $e) {
            if (isset($e['name']) && $e['name'] === $verb) {
                $entry = $e;
                break;
            }
        }

        if ($entry === null) {
            return [[
                'field'  => 'function',
                'index'  => -1,
                'reason' => 'unknown_verb',
                'hint'   => "Verb '{$verb}' is not in the QS verb catalog.",
            ]];
        }

        $argSpec = $entry['args'] ?? [];
        $errors  = [];

        foreach ($argSpec as $i => $arg) {
            $required  = isset($arg['required']) ? (bool) $arg['required'] : true;
            $inputType = $arg['inputType'] ?? '';
            $value     = $params[$i] ?? '';

            // Required-emptiness check — runs first; if empty + required,
            // skip the context check (the missing-error already covers it).
            if ($required && ($value === '' || $value === null)) {
                $errors[] = [
                    'field'  => $arg['name'] ?? ('arg' . $i),
                    'index'  => $i,
                    'reason' => 'missing',
                    'hint'   => 'Required parameter "' . ($arg['name'] ?? ('arg' . $i)) . '" is empty.',
                ];
                continue;
            }

            // Empty optional values are valid; no further checks needed.
            if ($value === '' || $value === null) {
                continue;
            }

            // Context-dependent checks (only run with pageName available).
            if ($inputType === 'routeParam' && $pageName !== null) {
                $valid = routeParamsForPageSlug($pageName);
                if (!in_array($value, $valid, true)) {
                    $errors[] = [
                        'field'  => $arg['name'] ?? ('arg' . $i),
                        'index'  => $i,
                        'reason' => 'invalid_route_param',
                        'hint'   => empty($valid)
                            ? "Current page '{$pageName}' has no :params; this verb requires a route with a :param segment."
                            : "Value '{$value}' is not a :param in the current page's route. Available: " . implode(', ', $valid) . '.',
                    ];
                }
            }
        }

        return $errors;
    }
}

/**
 * Find a node by its semantic nodeId (like "0.1.2") in a structure
 * Returns array with 'node' and 'path' keys, or null if not found
 */
if (!function_exists('findNodeByNumericPath')) {
    function findNodeByNumericPath(array $structure, string $nodeId): ?array {
        $parts = explode('.', $nodeId);
        $current = $structure;
        $path = [];
        
        foreach ($parts as $index) {
            $idx = (int)$index;
            
            // Handle root-level array
            if (empty($path)) {
                if (!isset($current[$idx])) {
                    return null;
                }
                $current = $current[$idx];
                $path[] = $idx;
            } else {
                // Navigate into children
                if (!isset($current['children']) || !isset($current['children'][$idx])) {
                    return null;
                }
                $current = $current['children'][$idx];
                $path[] = 'children';
                $path[] = $idx;
            }
        }
        
        return [
            'node' => $current,
            'path' => $path
        ];
    }
}

/**
 * Update a node at a specific path in the structure
 */
if (!function_exists('updateNodeAtPath')) {
    function updateNodeAtPath(array &$structure, array $path, array $newNode): bool {
        $current = &$structure;
        
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                return false;
            }
            $current = &$current[$key];
        }
        
        $current = $newNode;
        return true;
    }
}
