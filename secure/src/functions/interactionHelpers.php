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
