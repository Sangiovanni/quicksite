<?php
/**
 * _interactionHelpers.php - Shared constants and helpers for interaction commands
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

/**
 * Universal events available on all elements
 */
if (!defined('UNIVERSAL_EVENTS')) {
    define('UNIVERSAL_EVENTS', [
        'onclick', 'ondblclick', 
        'onmouseover', 'onmouseout', 'onmouseenter', 'onmouseleave', 'onmousemove',
        'onmousedown', 'onmouseup',
        'onfocus', 'onblur',
        'onkeydown', 'onkeyup', 'onkeypress',
        'ontouchstart', 'ontouchend', 'ontouchmove'
    ]);
}

/**
 * Events specific to certain tags
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
        'body' => ['onload', 'onunload', 'onresize', 'onscroll']
    ]);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get available events for a specific tag
 */
if (!function_exists('getAvailableEventsForTag')) {
    function getAvailableEventsForTag(string $tag): array {
        $events = UNIVERSAL_EVENTS;
        
        // Add tag-specific events
        if (isset(TAG_SPECIFIC_EVENTS[$tag])) {
            $events = array_merge($events, TAG_SPECIFIC_EVENTS[$tag]);
        }
        
        return array_values(array_unique($events));
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
            
            // Parse params (comma-separated, but be careful with selectors)
            // Simple approach: split by comma, handle common cases
            $params = [];
            if (!empty($paramsString)) {
                // Split by comma, but not commas inside brackets/quotes
                $params = preg_split('/,(?![^\[]*\])/', $paramsString);
                $params = array_map('trim', $params);
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
        return "{{call:{$function}:" . implode(',', $params) . "}}";
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
