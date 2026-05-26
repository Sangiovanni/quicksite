<?php
/**
 * listJsFunctions Command
 * 
 * Returns available QS.* JavaScript functions that can be used with {{call:...}} syntax.
 * Returns core functions from qs.js.
 * 
 * @method GET
 * @route /management/listJsFunctions
 * @auth required
 * 
 * @return ApiResponse List of functions with signatures, descriptions, and type (core/custom)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

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
            'signature' => 'QS.filter(event, itemsSelector, matchAttr?, hideClass?, emptyParent?)',
            'args' => [
                ['name' => 'event', 'type' => 'Event', 'required' => true, 'default' => 'event', 'description' => 'Pass "event" keyword to get input value, or a CSS selector to read from a different input', 'inputType' => 'eventArg'],
                ['name' => 'itemsSelector', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for items to filter', 'inputType' => 'selector'],
                ['name' => 'matchAttr', 'type' => 'string', 'required' => false, 'default' => 'textContent', 'description' => 'What to match: textContent, data-foo, or one or more child selectors like .cmd-name (comma-separated, e.g. ".cmd-name, .cmd-description"); descendant text is concatenated.', 'inputType' => 'matchTarget'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class for hidden items', 'inputType' => 'class'],
                ['name' => 'emptyParent', 'type' => 'string', 'required' => false, 'description' => 'Optional ancestor selector to hide when it has no visible items (e.g. .cmd-section)', 'inputType' => 'selector']
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
        ],
        [
            'name' => 'renderList',
            'signature' => 'QS.renderList(containerSelector, dataField, emptyText?)',
            'args' => [
                ['name' => 'containerSelector', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for the container element', 'inputType' => 'selector'],
                ['name' => 'dataField', 'type' => 'string', 'required' => true, 'description' => 'Dot-notation path to array field from last fetch result'],
                ['name' => 'emptyText', 'type' => 'string', 'required' => false, 'description' => 'Text shown when array is empty']
            ],
            'description' => 'Render an array of data into a container using data-bind templates. Container\'s first child is cloned for each item. Use data-bind="fieldName" and data-bind-attr="attrName" on child elements.',
            'example' => '{{call:renderList:.files-list,files,No files found}}',
            'events' => ['onclick', 'onload']
        ],
        [
            'name' => 'toast',
            'signature' => 'QS.toast(message, type?, duration?)',
            'args' => [
                ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'Message to display'],
                ['name' => 'type', 'type' => 'string', 'required' => false, 'default' => 'info', 'description' => 'success, error, warning, or info'],
                ['name' => 'duration', 'type' => 'number', 'required' => false, 'default' => '4000', 'description' => 'Duration in milliseconds']
            ],
            'description' => 'Show a toast notification message',
            'example' => '{{call:toast:Hello world!,success}}',
            'events' => ['onclick', 'onload', 'onsubmit']
        ],
        [
            'name' => 'fetch',
            'signature' => 'QS.fetch(target, ...options)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'HTTP method (GET/POST/PUT/PATCH/DELETE) for direct URL mode, or @apiId/endpointId for registry mode'],
                ['name' => 'url', 'type' => 'string', 'required' => false, 'description' => 'URL to call (direct URL mode only). Supports {{lang}} placeholder.'],
                ['name' => 'body', 'type' => 'string', 'required' => false, 'description' => 'body=#formSelector — auto-collects every named input inside the form/container as a JSON payload', 'inputType' => 'selector'],
                ['name' => 'silent', 'type' => 'string', 'required' => false, 'description' => 'silent=1 — suppress the default success/error toast']
            ],
            'description' => 'Make an API call. Direct URL mode: pass METHOD, URL, then options. Registry mode: pass @apiId/endpointId. Use body=#form-id to submit every named input inside the selected form/container as a JSON body — the canonical way to wire a form-submit chain after QS.validate.',
            'example' => '{{call:fetch:POST,/api/contact,body=#contact-form}}',
            'events' => ['onsubmit', 'onclick', 'onload']
        ],
        [
            'name' => 'validate',
            'signature' => 'QS.validate(event, formSelector)',
            'args' => [
                ['name' => 'event', 'type' => 'event', 'required' => true, 'description' => 'The event object — pass the literal keyword `event` (the renderer forwards it). Used to call preventDefault() on failure.'],
                ['name' => 'formSelector', 'type' => 'string', 'required' => true, 'description' => 'CSS selector of the <form> to validate', 'inputType' => 'selector']
            ],
            'description' => "Validate a form's fields using HTML5 constraints (required, minlength, pattern, type=email, ...). Writes each invalid field's browser error message into a sibling [data-error-for=\"<fieldname>\"] container, clears the container when the field becomes valid, and cancels the rest of the {{call:...}} chain on failure (so a chained fetch only runs when the form is valid).",
            'example' => '{{call:validate:event,#contact-form}};{{call:fetch:POST,/api/contact,body=#contact-form}}',
            'events' => ['onsubmit']
        ],
        [
            'name' => 'saveToken',
            'signature' => 'QS.saveToken(storage, key, path)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Where to store the token: localStorage (persists across browser restarts) or sessionStorage (clears on tab close).', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Storage key name (e.g. authToken). Matches the auth.tokenSource configured for downstream API calls.'],
                ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Dot-notation path into the last fetch result (e.g. token, data.access_token).']
            ],
            'description' => 'Read a value from the last fetch response (QS._lastFetchResult) and stash it in localStorage / sessionStorage. Typical use: chain after a login fetch to persist the returned token so subsequent calls pick it up via the endpoint\'s auth.tokenSource. Fires `qs:auth:saved` on document with detail.{storage, key, tokenKey, value}.',
            'example' => '{{call:fetch:@auth-api/login,body=#login-form}};{{call:saveToken:localStorage,authToken,token}}',
            'events' => ['onsubmit', 'onclick']
        ],
        [
            'name' => 'clearToken',
            'signature' => 'QS.clearToken(storage, key)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Storage to clear from: localStorage or sessionStorage.', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Storage key to remove.']
            ],
            'description' => 'Remove a stored token (logout). Fires `qs:auth:cleared` on document with detail.{storage, key, tokenKey} so login-state badges can re-render.',
            'example' => '{{call:clearToken:localStorage,authToken}};{{call:redirect:/}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'refresh',
            'signature' => 'QS.refresh(apiRef)',
            'args' => [
                ['name' => 'apiRef', 'type' => 'string', 'required' => true, 'description' => 'API to refresh as @apiId (must have a Refresh config in /admin/apis). It reads that API\'s stored refresh token, requests a new access token, stores it back, and rotates the refresh token if configured.']
            ],
            'description' => 'Manually run the Tier 2 token refresh for an API (same flow as the automatic refresh-on-401). Reads the refresh token from the API\'s refreshTokenSource, POSTs to its refreshEndpoint, and stores the new access token. A raw fetch to the refresh endpoint will NOT work — only this verb can inject the stored refresh token. Fires `qs:auth:saved` on success. Use for a "Refresh session" button.',
            'example' => '{{call:refresh:@auth-api}}',
            'events' => ['onclick']
        ],
        [
            'name' => 'setState',
            'signature' => 'QS.setState(storeId, field, value)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'State store to update (defined in the State stores panel)', 'inputType' => 'store'],
                ['name' => 'field', 'type' => 'string', 'required' => true, 'description' => 'Field name within the store'],
                ['name' => 'value', 'type' => 'string', 'required' => true, 'description' => 'Literal value, or a #id / .class selector to read that element\'s current value']
            ],
            'description' => 'Set a state store field — to a literal value, or to the live value of a #/. selector (e.g. a search box). Pair with fetchState to send it to the bound endpoint.',
            'example' => '{{call:setState:results,q,#searchBox}}',
            'events' => ['onclick', 'oninput', 'onchange', 'onkeyup']
        ],
        [
            'name' => 'fetchState',
            'signature' => 'QS.fetchState(storeId)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'State store to fetch (defined in the State stores panel)', 'inputType' => 'store']
            ],
            'description' => 'Run the store\'s bound endpoint with its current field values, apply the response back into the store, and re-render its bound DOM (data-state-value / data-state-list). Use after setState, or for one-shot loads. For infinite-scroll triggers, prefer onScrollFetchState — it adds debounce + in-flight + exhausted guards (raw onscroll → fetchState thrashes the API).',
            'example' => '{{call:fetchState:commandsList}}',
            'events' => ['onclick', 'onload', 'onsubmit', 'oninput']
        ],
        [
            'name' => 'onScrollFetchState',
            'signature' => 'QS.onScrollFetchState(storeId, triggerPx?, debounceMs?)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'State store to refresh on scroll-near-bottom (defined in the State stores panel)', 'inputType' => 'store'],
                ['name' => 'triggerPx', 'type' => 'number', 'required' => false, 'default' => 200, 'description' => 'Fire when the viewport bottom is within this many pixels of the page bottom (default 200)'],
                ['name' => 'debounceMs', 'type' => 'number', 'required' => false, 'default' => 100, 'description' => 'Debounce window between scroll bursts in milliseconds (default 100)']
            ],
            'description' => 'Register (once per store) a debounced window-scroll listener that calls fetchState only when the viewport is near the page bottom — and STOPS firing once the store is marked exhausted (HTTP error, response items empty, or a both-direction cursor that did not advance). Use as a page-event ONLOAD action to set up infinite scroll without API thrashing; pair with an append:true list field for "load more on scroll". setState clears the exhausted flag (e.g. fresh search re-arms the trigger).',
            'example' => '{{call:onScrollFetchState:scrollingStore,200,100}}',
            'events' => ['onload']
        ]
    ];
    
    // Add type='core' to all core functions
    foreach ($coreFunctions as &$func) {
        $func['type'] = 'core';
    }
    unset($func);
    
    // Merge core and custom functions
    $allFunctions = $coreFunctions;
    
    // Build function names lists
    $coreNames = array_map(fn($f) => $f['name'], $coreFunctions);
    $allNames = $coreNames;
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Available QS.* functions for {{call:...}} syntax')
        ->withData([
            'functions' => $allFunctions,
            'count' => count($allFunctions),
            'core_count' => count($coreFunctions),
            'custom_count' => 0,
            'names' => $allNames,
            'core_names' => $coreNames,
            'custom_names' => [],
            'syntax' => '{{call:functionName:arg1,arg2,...}}',
            'special_keywords' => ['event', 'this'],
            'library_paths' => [
                'core' => '/scripts/qs.js'
            ]
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listJsFunctions($trimParams->params(), $trimParams->additionalParams())->send();
}
