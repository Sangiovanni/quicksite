<?php
/**
 * qsVerbCatalog.php — Single source of truth for QS.* verb metadata.
 *
 * Read by:
 *   - secure/management/command/listJsFunctions.php (admin picker payload)
 *   - secure/src/classes/JsonToHtmlRenderer.php    (runtime page rendering)
 *   - secure/src/classes/JsonToPhpCompiler.php     (build path)
 *
 * Each entry: name, signature, args, description, example, events.
 *
 * To add a new QS.* verb:
 *   1. Implement it in secure/src/runtime/qs.js
 *   2. Add a catalog entry here — picker, renderer allowlist, and build
 *      allowlist all pick it up automatically.
 *
 * Before this file existed (pre-beta.7), the catalog was duplicated across
 * three files. Adding a verb required editing three places; missing one
 * caused the verb to be silently dropped at render or build time. See git
 * history for the consolidation.
 */

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

/**
 * Return the full QS.* verb catalog.
 *
 * inputType hints (consumed by the admin picker):
 *   'selector'   = CSS selector picker
 *   'class'      = CSS class picker
 *   'eventArg'   = event/this keyword arg
 *   'matchTarget' = textContent | data-* | child selector
 *   'enum'       = constrained value list (see 'options' on the same arg)
 *   'store'      = state-store name picker
 *   (default)    = plain text input
 *
 * category hints (consumed by the admin picker — beta.9 A2 Slice 1,
 * locked 2026-06-17):
 *   'dom-toggle'  = show/hide/class manipulation
 *   'form'        = form-targeted (validate, future submit helpers)
 *   'fetch'       = network calls
 *   'auth'        = saveToken/clearToken/refresh/magic-link/logout
 *   'nav'         = redirect, scroll-to, future history helpers
 *   'focus'       = focus/blur
 *   'state-store' = state-store interactions (setState/fetchState/onScroll)
 *   'display'     = rendering / notification helpers (toast/renderList/filter)
 *   'general'     = INTENTIONAL placement for truly cross-cutting verbs
 *                   (visually distinct from "Uncategorized")
 *   (missing)     = falls into "Uncategorized" — defensive fallback, flags
 *                   to the picker user that the verb forgot to declare
 *
 * @return array<int, array<string, mixed>>
 */
function qsVerbCatalog(): array {
    return [
        [
            'name' => 'show',
            'category' => 'dom-toggle',
            'signature' => 'QS.show(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to show', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to remove', 'inputType' => 'class']
            ],
            'description' => 'Show element(s) by removing the hidden class',
            'example' => '{{call:show:#modal}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'hide',
            'category' => 'dom-toggle',
            'signature' => 'QS.hide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to hide', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to add', 'inputType' => 'class']
            ],
            'description' => 'Hide element(s) by adding the hidden class',
            'example' => '{{call:hide:#modal}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'toggle',
            'category' => 'dom-toggle',
            'signature' => 'QS.toggle(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to toggle', 'inputType' => 'class']
            ],
            'description' => 'Toggle a CSS class on element(s)',
            'example' => '{{call:toggle:#menu,open}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'toggleHide',
            'category' => 'dom-toggle',
            'signature' => 'QS.toggleHide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s) to toggle visibility', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'Class to toggle (default: hidden)', 'inputType' => 'class']
            ],
            'description' => 'Toggle element(s) visibility - if hidden, show it; if visible, hide it',
            'example' => '{{call:toggleHide:#dropdown}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'addClass',
            'category' => 'dom-toggle',
            'signature' => 'QS.addClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to add', 'inputType' => 'class']
            ],
            'description' => 'Add a CSS class to element(s)',
            'example' => '{{call:addClass:#card,highlight}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onmouseenter']
        ],
        [
            'name' => 'removeClass',
            'category' => 'dom-toggle',
            'signature' => 'QS.removeClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'CSS class to remove', 'inputType' => 'class']
            ],
            'description' => 'Remove a CSS class from element(s)',
            'example' => '{{call:removeClass:#card,highlight}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onmouseleave']
        ],
        [
            'name' => 'setValue',
            'category' => 'dom-toggle',
            'signature' => 'QS.setValue(target, value)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element(s)', 'inputType' => 'selector'],
                ['name' => 'value', 'type' => 'string|boolean', 'required' => true, 'description' => 'Value to set (for checkbox/radio: true, "true", or "1" to check)']
            ],
            'description' => 'Set the value of element(s). Handles inputs, textareas, selects, checkboxes, and radios.',
            'example' => '{{call:setValue:#output,Hello World}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'redirect',
            'category' => 'nav',
            'signature' => 'QS.redirect(url)',
            'args' => [
                ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'URL to navigate to', 'inputType' => 'route', 'allowExternal' => true]
            ],
            'description' => 'Navigate to a URL',
            'example' => '{{call:redirect:/thank-you}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'filter',
            'category' => 'display',
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
            'events' => ['oninput', 'onchange', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'scrollTo',
            'category' => 'nav',
            'signature' => 'QS.scrollTo(target, behavior?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to scroll to', 'inputType' => 'selector'],
                ['name' => 'behavior', 'type' => 'string', 'required' => false, 'default' => 'smooth', 'description' => 'Scroll animation: smooth (animated), instant (jump), auto (let browser decide based on prefers-reduced-motion).', 'inputType' => 'enum', 'options' => ['smooth', 'instant', 'auto']]
            ],
            'description' => 'Smoothly scroll to an element',
            'example' => '{{call:scrollTo:#contact}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'focus',
            'category' => 'focus',
            'signature' => 'QS.focus(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to focus', 'inputType' => 'selector']
            ],
            'description' => 'Focus an element',
            'example' => '{{call:focus:#searchInput}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload']
        ],
        [
            'name' => 'blur',
            'category' => 'focus',
            'signature' => 'QS.blur(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for element to blur', 'inputType' => 'selector']
            ],
            'description' => 'Remove focus from an element',
            'example' => '{{call:blur:#searchInput}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'renderList',
            'category' => 'display',
            'signature' => 'QS.renderList(containerSelector, dataField, emptyText?)',
            'args' => [
                ['name' => 'containerSelector', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for the container element', 'inputType' => 'selector'],
                ['name' => 'dataField', 'type' => 'string', 'required' => true, 'description' => 'Dot-notation path to array field from last fetch result'],
                ['name' => 'emptyText', 'type' => 'string', 'required' => false, 'description' => 'Text shown when array is empty']
            ],
            'description' => 'Render an array of data into a container using data-bind templates. Container\'s first child is cloned for each item. Use data-bind="fieldName" and data-bind-attr="attrName" on child elements.',
            'example' => '{{call:renderList:.files-list,files,No files found}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload', 'onsubmit']
        ],
        [
            'name' => 'toast',
            'category' => 'general',
            'signature' => 'QS.toast(message, type?, duration?)',
            'args' => [
                ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'Text shown in the toast. For multilingual sites pick a translation key (the picker shows the project\'s keys) — raw English text bakes the language into the page. The "Custom text" sentinel lets you type a raw string for one-off / debug toasts.', 'inputType' => 'translationKey', 'allowFreeText' => true],
                ['name' => 'type', 'type' => 'string', 'required' => false, 'default' => 'info', 'description' => 'Visual style: success (green), error (red), warning (yellow), info (neutral).', 'inputType' => 'enum', 'options' => ['info', 'success', 'error', 'warning']],
                ['name' => 'duration', 'type' => 'number', 'required' => false, 'default' => '4000', 'description' => 'Auto-dismiss delay in milliseconds.']
            ],
            'description' => 'Show a toast notification message',
            'example' => '{{call:toast:Hello world!,success}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange', 'onload', 'onsubmit']
        ],
        [
            'name' => 'fetch',
            'category' => 'fetch',
            'signature' => 'QS.fetch(target, ...options)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'HTTP method (GET/POST/PUT/PATCH/DELETE) for direct URL mode, or @apiId/endpointId for registry mode'],
                ['name' => 'url', 'type' => 'string', 'required' => false, 'description' => 'URL to call (direct URL mode only). Supports {{lang}} placeholder.'],
                ['name' => 'body', 'type' => 'string', 'required' => false, 'description' => 'body=#formSelector — auto-collects every named input inside the form/container as a JSON payload', 'inputType' => 'selector'],
                ['name' => 'silent', 'type' => 'string', 'required' => false, 'description' => 'silent=1 — suppress the default success/error toast']
            ],
            'description' => 'Make an API call. Direct URL mode: pass METHOD, URL, then options. Registry mode: pass @apiId/endpointId. Use body=#form-id to submit every named input inside the selected form/container as a JSON body — the canonical way to wire a form-submit chain after QS.validate.',
            'example' => '{{call:fetch:POST,/api/contact,body=#contact-form}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'validate',
            'category' => 'form',
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
            'category' => 'auth',
            'signature' => 'QS.saveToken(storage, key, path)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Where to store the token: localStorage (persists across browser restarts) or sessionStorage (clears on tab close).', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Storage key name (e.g. authToken). Matches the auth.tokenSource configured for downstream API calls.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage'],
                ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Dot-notation path into the last fetch result (e.g. token, data.access_token).', 'inputType' => 'responsePath']
            ],
            'description' => 'Read a value from the last fetch response (QS._lastFetchResult) and stash it in localStorage / sessionStorage. Typical use: chain after a login fetch to persist the returned token so subsequent calls pick it up via the endpoint\'s auth.tokenSource. Fires `qs:auth:saved` on document with detail.{storage, key, tokenKey, value}.',
            'example' => '{{call:fetch:@auth-api/login,body=#login-form}};{{call:saveToken:localStorage,authToken,token}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'clearToken',
            'category' => 'auth',
            'signature' => 'QS.clearToken(storage, key)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Storage to clear from: localStorage or sessionStorage.', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Storage key to remove.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage']
            ],
            'description' => 'Remove a stored token (logout). Fires `qs:auth:cleared` on document with detail.{storage, key, tokenKey} so login-state badges can re-render.',
            'example' => '{{call:clearToken:localStorage,authToken}};{{call:redirect:/}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'store',
            'category' => 'state-store',
            'signature' => 'QS.store(storage, key, path)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'localStorage (persists across restarts) or sessionStorage (clears on tab close).', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Storage key name. Declared in the storage registry (/admin/storage) — pick an existing one or create it inline.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage'],
                ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Dot-notation path into the last fetch result (e.g. data.theme).', 'inputType' => 'responsePath']
            ],
            'description' => 'Generic storage write — the non-auth sibling of saveToken. Reads a value from the last fetch response (QS._lastFetchResult) and stashes it in localStorage / sessionStorage, firing `qs:storage:changed` so data-storage-* bindings re-render. Use for non-token values (preferences, saved drafts, …) that the storage registry tracks.',
            'example' => '{{call:fetch:@prefs-api/get}};{{call:store:localStorage,themePref,data.theme}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'refresh',
            'category' => 'auth',
            'signature' => 'QS.refresh(apiRef)',
            'args' => [
                ['name' => 'apiRef', 'type' => 'string', 'required' => true, 'description' => 'API to refresh as @apiId (must have a Refresh config in /admin/apis). It reads that API\'s stored refresh token, requests a new access token, stores it back, and rotates the refresh token if configured.', 'inputType' => 'api']
            ],
            'description' => 'Manually run the Tier 2 token refresh for an API (same flow as the automatic refresh-on-401). Reads the refresh token from the API\'s refreshTokenSource, POSTs to its refreshEndpoint, and stores the new access token. A raw fetch to the refresh endpoint will NOT work — only this verb can inject the stored refresh token. Fires `qs:auth:saved` on success. Use for a "Refresh session" button.',
            'example' => '{{call:refresh:@auth-api}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload']
        ],
        [
            'name' => 'exchangeMagicLink',
            'category' => 'auth',
            'signature' => 'QS.exchangeMagicLink(endpoint, paramName, returnTo?)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'Auth API endpoint that trades the URL code for a session token, as @apiId/endpointId (e.g. @auth-api/exchange-magic). Typically configured with auth.type=none — the exchange itself IS the login.', 'inputType' => 'apiEndpoint'],
                ['name' => 'paramName', 'type' => 'string', 'required' => true, 'description' => 'The :param in your route URL that carries the magic code. If your route is /auth/magic/:key, pick "key". The picker lists the :params declared on the current page\'s route — typo-proof. Under the hood the verb reads QS.routeParams[name] (populated by qs.js\'s path matcher, see ARCHITECTURE §5.3).', 'inputType' => 'routeParam'],
                ['name' => 'returnTo', 'type' => 'string', 'required' => false, 'description' => 'Optional URL to navigate to after the exchange succeeds. Falls back to the ?return= query param. With neither, no auto-redirect — chain an explicit {{call:redirect:/path}} step instead. When provided AND saveToken calls are chained after this verb, the saveTokens still run before the browser processes the queued navigation (they\'re sync and execute in the same microtask).', 'inputType' => 'route']
            ],
            'description' => 'Exchange a single-use magic-link code for a real session token. POSTs {key: <captured code>} to the configured endpoint and stores the response in QS._lastFetchResult so chained saveToken calls pick up the returned token + refreshToken. The URL code is single-use and short-lived — never put the real session token in the URL directly (it leaks via email forwarding, browser history, corporate HTTPS proxy logs, and mail-client link prefetchers). Sits on a /auth/magic/:key (or similar) param route authored via /admin/sitemap. Dispatches qs:auth:exchange-started before fetch + qs:auth:exchange-failed in catch so a magic-link-handler component can show "connecting" / "invalid link" UI via data-auth-show. See BETA8_AUTH_TIER_3.md for the full actor diagram.',
            'example' => '{{call:exchangeMagicLink:@auth-api/exchange-magic,key}};{{call:saveToken:localStorage,authToken,token}};{{call:saveToken:localStorage,refreshToken,refreshToken}};{{call:redirect:/dashboard}}',
            'events' => ['onload']
        ],
        [
            'name' => 'requestMagicLink',
            'category' => 'auth',
            'signature' => 'QS.requestMagicLink(endpoint, email, returnTo?)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'Auth API endpoint that issues a magic-link email, as @apiId/endpointId (e.g. @auth-api/issue-magic). Usually configured with auth.type=none — runs before the user has a session.', 'inputType' => 'apiEndpoint'],
                ['name' => 'email', 'type' => 'string', 'required' => true, 'description' => 'Either a literal email address ("user@example.com") or a CSS selector ("#email-input", ".email-field") pointing to an <input>. The verb reads .value from the matched element.'],
                ['name' => 'returnTo', 'type' => 'string', 'required' => false, 'description' => 'Optional URL to navigate to after the request succeeds — typically a "check your email" confirmation page.', 'inputType' => 'route']
            ],
            'description' => 'Forward path of magic-link auth — POSTs {email} to the issue-magic endpoint to trigger the auth API to email a single-use code to the user. Pairs with exchangeMagicLink (which runs on the landing page after the user clicks the email link). The server-side endpoint MUST NOT reveal whether the email exists in its user store; the standard pattern is "always return 200" to avoid an account-enumeration oracle — that\'s the auth API\'s responsibility, this verb just POSTs.',
            'example' => '{{call:validate:event,#login-form}};{{call:requestMagicLink:@auth-api/issue-magic,#email-input,/check-email}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit']
        ],
        [
            'name' => 'logoutServer',
            'category' => 'auth',
            'signature' => 'QS.logoutServer(endpoint)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'Auth API endpoint that invalidates the server-side session, as @apiId/endpointId (e.g. @auth-api/logout). Usually a POST endpoint inheriting bearer auth — the server uses the request\'s token to identify which session to drop.', 'inputType' => 'apiEndpoint']
            ],
            'description' => 'Server-side logout — tells the auth API to invalidate the session / revoke the refresh token. The Tier 1 logout pair: chain this BEFORE clearToken (so the request still has the token attached) then clearToken to drop the local copy. Thin wrapper around QS.fetch so the registry\'s bearer auth is applied. Logout failures are intentionally swallowed — the user wants out either way, so subsequent clearToken + redirect should always run.',
            'example' => '{{call:logoutServer:@auth-api/logout}};{{call:clearToken:localStorage,authToken}};{{call:clearToken:localStorage,refreshToken}};{{call:redirect:/}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'setState',
            'category' => 'state-store',
            'signature' => 'QS.setState(storeId, field, value)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'State store to update (defined in the State stores panel)', 'inputType' => 'store'],
                ['name' => 'field', 'type' => 'string', 'required' => true, 'description' => 'Field name within the store'],
                ['name' => 'value', 'type' => 'string', 'required' => true, 'description' => 'Literal value, or a #id / .class selector to read that element\'s current value']
            ],
            'description' => 'Set a state store field — to a literal value, or to the live value of a #/. selector (e.g. a search box). Pair with fetchState to send it to the bound endpoint.',
            'example' => '{{call:setState:results,q,#searchBox}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'oninput', 'onchange']
        ],
        [
            'name' => 'fetchState',
            'category' => 'state-store',
            'signature' => 'QS.fetchState(storeId)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'State store to fetch (defined in the State stores panel)', 'inputType' => 'store']
            ],
            'description' => 'Run the store\'s bound endpoint with its current field values, apply the response back into the store, and re-render its bound DOM (data-state-value / data-state-list). Use after setState, or for one-shot loads. For infinite-scroll triggers, prefer onScrollFetchState — it adds debounce + in-flight + exhausted guards (raw onscroll → fetchState thrashes the API).',
            'example' => '{{call:fetchState:commandsList}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'oninput', 'onchange', 'onsubmit', 'onload']
        ],
        [
            'name' => 'onScrollFetchState',
            'category' => 'state-store',
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
}

/**
 * Return just the verb names — the allowlist consumed by
 * JsonToHtmlRenderer and JsonToPhpCompiler when validating
 * {{call:fn:...}} placeholders.
 *
 * @return array<int, string>
 */
function qsVerbNames(): array {
    return array_column(qsVerbCatalog(), 'name');
}
