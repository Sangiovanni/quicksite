<?php
/**
 * listJsFunctions Command
 * 
 * Returns available QS.* JavaScript functions that can be used with {{call:...}} syntax.
 * Includes both core functions (from qs.js) and custom functions (from qs-custom.js).
 * 
 * @method GET
 * @route /management/listJsFunctions
 * @auth required
 * 
 * @return ApiResponse List of functions with signatures, descriptions, and type (core/custom)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsFunctionManager.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_listJsFunctions(array $params = [], array $urlParams = []): ApiResponse {
    
    // Define core QS.* functions (from public/scripts/qs.js)
    // These are read-only and cannot be modified
    // inputType hints: 'selector' = CSS selector picker, 'class' = CSS class picker, 'text' = plain text (default)
    $coreFunctions = [
        [
            'name' => 'show',
            'signature' => 'QS.show(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to show', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to remove', 'inputType' => 'class']
            ],
            'description' => 'Show element(s) by removing the hidden class',
            'example' => '{{call:show:#modal}}',
            'events' => ['onclick', 'onchange']
        ],
        [
            'name' => 'hide',
            'signature' => 'QS.hide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to hide', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to add', 'inputType' => 'class']
            ],
            'description' => 'Hide element(s) by adding the hidden class',
            'example' => '{{call:hide:#modal}}',
            'events' => ['onclick', 'onchange']
        ],
        [
            'name' => 'toggle',
            'signature' => 'QS.toggle(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to toggle', 'inputType' => 'class']
            ],
            'description' => 'Toggle a CSS class on element(s)',
            'example' => '{{call:toggle:#menu,open}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'toggleHide',
            'signature' => 'QS.toggleHide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to toggle visibility', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to toggle (default: hidden)', 'inputType' => 'class']
            ],
            'description' => 'Toggle element(s) visibility - if hidden, show it; if visible, hide it',
            'example' => '{{call:toggleHide:#dropdown}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'addClass',
            'signature' => 'QS.addClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to add', 'inputType' => 'class']
            ],
            'description' => 'Add a CSS class to element(s)',
            'example' => '{{call:addClass:#card,highlight}}',
            'events' => ['onclick', 'onmouseenter']
        ],
        [
            'name' => 'removeClass',
            'signature' => 'QS.removeClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to remove', 'inputType' => 'class']
            ],
            'description' => 'Remove a CSS class from element(s)',
            'example' => '{{call:removeClass:#card,highlight}}',
            'events' => ['onclick', 'onmouseleave']
        ],
        [
            'name' => 'setValue',
            'signature' => 'QS.setValue(target, value)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'value', 'type' => 'string|boolean', 'required' => true, 'description' => 'Value to set (for checkbox/radio: true, "true", or "1" to check)']
            ],
            'description' => 'Set the value of element(s). Handles inputs, textareas, selects, checkboxes, and radios.',
            'example' => '{{call:setValue:#output,Hello World}}',
            'events' => ['onclick', 'onchange']
        ],
        [
            'name' => 'redirect',
            'signature' => 'QS.redirect(url)',
            'args' => [
                ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'URL to navigate to']
            ],
            'description' => 'Navigate to a URL',
            'example' => '{{call:redirect:/thank-you}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'filter',
            'signature' => 'QS.filter(event, itemsSelector, matchAttr?, hideClass?)',
            'args' => [
                ['name' => 'event', 'type' => 'Event', 'required' => true, 'description' => 'Pass "event" keyword to get input value'],
                ['name' => 'itemsSelector', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for items to filter', 'inputType' => 'selector'],
                ['name' => 'matchAttr', 'type' => 'string', 'required' => false, 'default' => 'textContent', 'description' => 'Attribute to match against (e.g., data-title)'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class for hidden items', 'inputType' => 'class']
            ],
            'description' => 'Filter elements based on input value. Use on input fields.',
            'example' => '{{call:filter:event,.card,data-title}}',
            'events' => ['oninput', 'onkeyup']
        ],
        [
            'name' => 'scrollTo',
            'signature' => 'QS.scrollTo(target, behavior?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to scroll to', 'inputType' => 'selector'],
                ['name' => 'behavior', 'type' => 'string', 'required' => false, 'default' => 'smooth', 'description' => '"smooth" or "instant"']
            ],
            'description' => 'Smoothly scroll to an element',
            'example' => '{{call:scrollTo:#contact}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'focus',
            'signature' => 'QS.focus(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to focus', 'inputType' => 'selector']
            ],
            'description' => 'Focus an element',
            'example' => '{{call:focus:#searchInput}}',
            'events' => ['onclick', 'onload']
        ],
        [
            'name' => 'blur',
            'signature' => 'QS.blur(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to blur', 'inputType' => 'selector']
            ],
            'description' => 'Remove focus from an element',
            'example' => '{{call:blur:#searchInput}}',
            'events' => ['onclick']
        ]
    ];
    
    // Add type='core' to all core functions
    foreach ($coreFunctions as &$func) {
        $func['type'] = 'core';
    }
    unset($func);
    
    // Get custom functions
    $manager = new JsFunctionManager();
    $customFuncs = $manager->getCustomFunctions();
    
    // Format custom functions to match core function structure
    $customFunctions = [];
    foreach ($customFuncs as $func) {
        $args = [];
        foreach (($func['args'] ?? []) as $argName) {
            $args[] = [
                'name' => $argName,
                'type' => 'any',
                'required' => true,
                'description' => ''
            ];
        }
        
        $customFunctions[] = [
            'name' => $func['name'],
            'signature' => 'QS.' . $func['name'] . '(' . implode(', ', $func['args'] ?? []) . ')',
            'args' => $args,
            'description' => $func['description'] ?? '',
            'example' => '{{call:' . $func['name'] . ':' . implode(',', $func['args'] ?? []) . '}}',
            'events' => ['onclick', 'onchange', 'oninput'],
            'type' => 'custom',
            'created' => $func['created'] ?? null,
            'modified' => $func['modified'] ?? null
        ];
    }
    
    // Merge core and custom functions
    $allFunctions = array_merge($coreFunctions, $customFunctions);
    
    // Build function names lists
    $coreNames = array_map(fn($f) => $f['name'], $coreFunctions);
    $customNames = array_map(fn($f) => $f['name'], $customFunctions);
    $allNames = array_merge($coreNames, $customNames);
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Available QS.* functions for {{call:...}} syntax')
        ->withData([
            'functions' => $allFunctions,
            'count' => count($allFunctions),
            'core_count' => count($coreFunctions),
            'custom_count' => count($customFunctions),
            'names' => $allNames,
            'core_names' => $coreNames,
            'custom_names' => $customNames,
            'syntax' => '{{call:functionName:arg1,arg2,...}}',
            'special_keywords' => ['event', 'this'],
            'library_paths' => [
                'core' => '/scripts/qs.js',
                'custom' => '/scripts/qs-custom.js'
            ]
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listJsFunctions($trimParams->params(), $trimParams->additionalParams())->send();
}
