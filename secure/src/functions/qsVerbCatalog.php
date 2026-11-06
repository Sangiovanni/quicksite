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
 * siteUrl (read by CallTransformer): the argument is a URL, and a value starting with
 * one '/' is on this site, so the server composes it when the page is served:
 *   'page'     = like a link: the site's base, and the page's language on a
 *                multilingual site (the same function as an href)
 *   'resource' = the site's base alone (a fetch's URL in direct-URL mode)
 *
 * category hints (consumed by the admin picker, by design):
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
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s) to show, as a CSS selector: #modal, or .card for every element with that class.', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'The class that hides them, removed to show them. Leave it empty unless your site hides with another class.', 'inputType' => 'class']
            ],
            'description' => 'Show something that is hidden, such as a modal. It removes the hidden class from the element(s).',
            'example' => '{{call:show:#modal}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'hide',
            'category' => 'dom-toggle',
            'signature' => 'QS.hide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s) to hide, as a CSS selector: #modal, or .card for every element with that class.', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'The class that hides them, added to hide them. Leave it empty unless your site hides with another class.', 'inputType' => 'class']
            ],
            'description' => 'Hide something, such as a modal. It adds the hidden class to the element(s).',
            'example' => '{{call:hide:#modal}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'toggle',
            'category' => 'dom-toggle',
            'signature' => 'QS.toggle(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s), as a CSS selector: #menu, or .card for every element with that class.', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'The class to switch on and off, such as open.', 'inputType' => 'class']
            ],
            'description' => 'Switch a class on and off: with #menu and open, a closed menu opens and an open one closes. It adds the CSS class where it is missing and removes it where it is there.',
            'example' => '{{call:toggle:#menu,open}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'toggleHide',
            'category' => 'dom-toggle',
            'signature' => 'QS.toggleHide(target, hideClass?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s) to show or hide, as a CSS selector: #dropdown.', 'inputType' => 'selector'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'The class that hides them. Leave it empty unless your site hides with another class.', 'inputType' => 'class']
            ],
            'description' => 'Show it if it is hidden, hide it if it is showing: a dropdown button, for example. It switches the hidden class on the element(s).',
            'example' => '{{call:toggleHide:#dropdown}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'addClass',
            'category' => 'dom-toggle',
            'signature' => 'QS.addClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s), as a CSS selector: #card, or .card for every element with that class.', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'The class to add, such as highlight.', 'inputType' => 'class']
            ],
            'description' => 'Add a class to element(s): highlight a card when the pointer enters it, for example.',
            'example' => '{{call:addClass:#card,highlight}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onmouseenter']
        ],
        [
            'name' => 'removeClass',
            'category' => 'dom-toggle',
            'signature' => 'QS.removeClass(target, className)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s), as a CSS selector: #card, or .card for every element with that class.', 'inputType' => 'selector'],
                ['name' => 'className', 'type' => 'string', 'required' => true, 'description' => 'The class to remove, such as highlight.', 'inputType' => 'class']
            ],
            'description' => 'Remove a class from element(s): take a highlight off when the pointer leaves, for example.',
            'example' => '{{call:removeClass:#card,highlight}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onmouseleave']
        ],
        [
            'name' => 'setValue',
            'category' => 'dom-toggle',
            'signature' => 'QS.setValue(target, value)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element(s), as a CSS selector: #output.', 'inputType' => 'selector'],
                ['name' => 'value', 'type' => 'string|boolean', 'required' => true, 'description' => 'What to put there, such as Hello World. For a checkbox or radio, true, "true" or "1" checks it and anything else unchecks it.']
            ],
            'description' => 'Put a value in an element: text in a field, a choice in a select, a tick in a checkbox or radio. Any other element shows it as its text.',
            'example' => '{{call:setValue:#output,Hello World}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange']
        ],
        [
            'name' => 'redirect',
            'category' => 'nav',
            'signature' => 'QS.redirect(url)',
            'args' => [
                ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Where to go: /thank-you, or a whole address such as https://example.com. A path starting with / is a page of this site, composed like a link (the site\'s base, and the page\'s language on a multilingual site).', 'inputType' => 'route', 'allowExternal' => true, 'siteUrl' => 'page']
            ],
            'description' => 'Send the visitor to another page or address.',
            'example' => '{{call:redirect:/thank-you}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'filter',
            'category' => 'display',
            'signature' => 'QS.filter(event, itemsSelector, matchAttr?, hideClass?, emptyParent?)',
            'args' => [
                ['name' => 'event', 'type' => 'Event', 'required' => true, 'default' => 'event', 'description' => 'Keep the word event: the text to filter by is what the visitor types in this field.', 'inputType' => 'eventArg'],
                ['name' => 'itemsSelector', 'type' => 'string', 'required' => true, 'description' => 'Which items to show or hide, as a CSS selector: .card.', 'inputType' => 'selector'],
                ['name' => 'matchAttr', 'type' => 'string', 'required' => false, 'default' => 'textContent', 'description' => 'Where to look in each item: textContent for all its text, an attribute such as data-title, or one or more child selectors such as .cmd-name (comma-separated, e.g. ".cmd-name, .cmd-description"), whose text is read together.', 'inputType' => 'matchTarget'],
                ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'The class that hides an item that does not match. Leave it empty unless your site hides with another class.', 'inputType' => 'class'],
                ['name' => 'emptyParent', 'type' => 'string', 'required' => false, 'description' => 'A section to hide too when none of its items is left, such as .cmd-section. Optional.', 'inputType' => 'selector']
            ],
            'description' => 'Show only the items that match what the visitor types: a search box over a list of cards. Use on input fields.',
            'example' => '{{call:filter:event,.card,data-title}}',
            'events' => ['oninput', 'onchange', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'scrollTo',
            'category' => 'nav',
            'signature' => 'QS.scrollTo(target, behavior?)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Where to scroll, as a CSS selector: #contact.', 'inputType' => 'selector'],
                ['name' => 'behavior', 'type' => 'string', 'required' => false, 'default' => 'smooth', 'description' => 'How to get there: smooth (animated), instant (a jump), or auto (the browser decides, from prefers-reduced-motion).', 'inputType' => 'enum', 'options' => ['smooth', 'instant', 'auto']]
            ],
            'description' => 'Scroll to an element, smoothly by default: a "Contact us" button that brings the form into view.',
            'example' => '{{call:scrollTo:#contact}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'focus',
            'category' => 'focus',
            'signature' => 'QS.focus(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element, as a CSS selector: #searchInput.', 'inputType' => 'selector']
            ],
            'description' => 'Give an element the focus: put the cursor in a search field, for example.',
            'example' => '{{call:focus:#searchInput}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload']
        ],
        [
            'name' => 'blur',
            'category' => 'focus',
            'signature' => 'QS.blur(target)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'Which element, as a CSS selector: #searchInput.', 'inputType' => 'selector']
            ],
            'description' => 'Take the focus away from an element, such as a search field.',
            'example' => '{{call:blur:#searchInput}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'renderList',
            'category' => 'display',
            'signature' => 'QS.renderList(containerSelector, dataField, emptyText?)',
            'args' => [
                ['name' => 'containerSelector', 'type' => 'string', 'required' => true, 'description' => 'The element that holds the list, as a CSS selector: .files-list. Its first child is the pattern.', 'inputType' => 'selector'],
                ['name' => 'dataField', 'type' => 'string', 'required' => true, 'description' => 'Where the list is in the last fetch result, such as files or data.items (a dot-notation path).'],
                ['name' => 'emptyText', 'type' => 'string', 'required' => false, 'description' => 'What to show when the list is empty, such as No files found.']
            ],
            'description' => 'Show a list from the last fetch, one copy of a pattern for each entry: the container\'s first child is copied for each item. Use data-bind="fieldName" and data-bind-attr="attrName" on child elements to say what each copy shows.',
            'example' => '{{call:renderList:.files-list,files,No files found}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload', 'onsubmit']
        ],
        [
            'name' => 'toast',
            'category' => 'general',
            'signature' => 'QS.toast(message, type?, duration?)',
            'args' => [
                ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'The text to show, such as Saved!. On a multilingual site pick a translation key (the picker shows the project\'s keys): raw English text bakes the language into the page. "Custom text" lets you type a raw string for a one-off or a debug toast.', 'inputType' => 'translationKey', 'allowFreeText' => true],
                ['name' => 'type', 'type' => 'string', 'required' => false, 'default' => 'info', 'description' => 'Its colour: success (green), error (red), warning (yellow), info (neutral).', 'inputType' => 'enum', 'options' => ['info', 'success', 'error', 'warning']],
                ['name' => 'duration', 'type' => 'number', 'required' => false, 'default' => '4000', 'description' => 'How long it stays, in milliseconds: 4000 is four seconds.']
            ],
            'description' => 'Show a short message that goes away by itself (a toast): "Saved!", for example.',
            'example' => '{{call:toast:Hello world!,success}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onchange', 'onload', 'onsubmit']
        ],
        [
            'name' => 'fetch',
            'category' => 'fetch',
            'signature' => 'QS.fetch(target, ...options)',
            'args' => [
                ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'What to call: @apiId/endpointId for a registered endpoint, or an HTTP method (GET, POST, PUT, PATCH, DELETE) to call a URL directly.'],
                ['name' => 'url', 'type' => 'string', 'required' => false, 'description' => 'The URL to call, when target is a method: /api/contact. A path starting with / is on this site: the site\'s base is put in front of it (never a language). Supports a {{lang}} placeholder.', 'siteUrl' => 'resource'],
                ['name' => 'body', 'type' => 'string', 'required' => false, 'description' => 'Send a form: body=#contact-form sends every named input inside that form or container as a JSON payload.', 'inputType' => 'selector'],
                ['name' => 'silent', 'type' => 'string', 'required' => false, 'description' => 'silent=1 keeps the default success and error toast from showing.']
            ],
            'description' => 'Call an API: send a form to it, or load data for the page. For an endpoint of this project\'s API registry pass @apiId/endpointId; for a direct call pass METHOD, URL, then options. Use body=#form-id to send every named input inside that form or container as a JSON body: the way to submit a form after QS.validate.',
            'example' => '{{call:fetch:POST,/api/contact,body=#contact-form}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'validate',
            'category' => 'form',
            'signature' => 'QS.validate(event, formSelector)',
            'args' => [
                ['name' => 'event', 'type' => 'event', 'required' => true, 'description' => 'Keep the word event: the renderer forwards the event object, and on failure preventDefault() is called on it.'],
                ['name' => 'formSelector', 'type' => 'string', 'required' => true, 'description' => 'The <form> to check, as a CSS selector: #contact-form.', 'inputType' => 'selector']
            ],
            'description' => 'Check a form before it is sent: required fields, a valid email and so on, by the form\'s own HTML5 rules (required, minlength, pattern, type=email, ...). On failure the rest of the {{call:...}} chain does not run, so a chained fetch only runs when the form is valid. Each invalid field\'s browser error message goes into a sibling [data-error-for="<fieldname>"] container, which is cleared when the field becomes valid.',
            'example' => '{{call:validate:event,#contact-form}};{{call:fetch:POST,/api/contact,body=#contact-form}}',
            'events' => ['onsubmit']
        ],
        [
            'name' => 'saveToken',
            'category' => 'auth',
            'signature' => 'QS.saveToken(storage, key, path)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Where to keep it: localStorage (survives closing the browser) or sessionStorage (gone when the tab closes).', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'The name to keep it under, such as authToken. It matches the auth.tokenSource configured for the API calls that need it.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage'],
                ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Where the value is in the last fetch result, such as token or data.access_token (a dot-notation path).', 'inputType' => 'responsePath']
            ],
            'description' => 'Keep the token a login returned, so later calls can use it: chain it after a login fetch. It reads a value from the last fetch response (QS._lastFetchResult) and stores it in localStorage or sessionStorage, where later calls pick it up via the endpoint\'s auth.tokenSource. Fires `qs:auth:saved` on document with detail.{storage, key, tokenKey, value}.',
            'example' => '{{call:fetch:@auth-api/login,body=#login-form}};{{call:saveToken:localStorage,authToken,token}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'clearToken',
            'category' => 'auth',
            'signature' => 'QS.clearToken(storage, key)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Where it is kept: localStorage or sessionStorage.', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'The name it is kept under, such as authToken.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage']
            ],
            'description' => 'Forget a stored token: a "Log out" button, for example. Fires `qs:auth:cleared` on document with detail.{storage, key, tokenKey} so login-state badges can re-render.',
            'example' => '{{call:clearToken:localStorage,authToken}};{{call:redirect:/}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'store',
            'category' => 'state-store',
            'signature' => 'QS.store(storage, key, path)',
            'args' => [
                ['name' => 'storage', 'type' => 'string', 'required' => true, 'description' => 'Where to keep it: localStorage (survives closing the browser) or sessionStorage (gone when the tab closes).', 'inputType' => 'enum', 'options' => ['localStorage', 'sessionStorage']],
                ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'The name to keep it under, such as themePref. Declared in the storage registry (/admin/storage): pick an existing one or create it inline.', 'inputType' => 'storageKey', 'scopeFrom' => 'storage'],
                ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Where the value is in the last fetch result, such as data.theme (a dot-notation path).', 'inputType' => 'responsePath']
            ],
            'description' => 'Keep a value from the last fetch in the browser: a preference or a saved draft. saveToken is the same for a token. It reads the value from the last fetch response (QS._lastFetchResult), stores it in localStorage or sessionStorage and fires `qs:storage:changed`, so data-storage-* bindings re-render. For values the storage registry tracks.',
            'example' => '{{call:fetch:@prefs-api/get}};{{call:store:localStorage,themePref,data.theme}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit', 'onload']
        ],
        [
            'name' => 'refresh',
            'category' => 'auth',
            'signature' => 'QS.refresh(apiRef)',
            'args' => [
                ['name' => 'apiRef', 'type' => 'string', 'required' => true, 'description' => 'The API, as @apiId: @auth-api. It must have a Refresh config in /admin/apis. It reads that API\'s stored refresh token, requests a new access token, stores it back, and rotates the refresh token if configured.', 'inputType' => 'api']
            ],
            'description' => 'Get a new access token by hand: a "Refresh session" button, for example. It is the same flow as the automatic refresh on a 401: it reads the refresh token from the API\'s refreshTokenSource, POSTs to its refreshEndpoint, and stores the new access token. A raw fetch to the refresh endpoint will NOT work: only this verb can inject the stored refresh token. Fires `qs:auth:saved` on success.',
            'example' => '{{call:refresh:@auth-api}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onload']
        ],
        [
            'name' => 'exchangeMagicLink',
            'category' => 'auth',
            'signature' => 'QS.exchangeMagicLink(endpoint, paramName, returnTo?)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'The endpoint that trades the code in the address for a session token, as @apiId/endpointId: @auth-api/exchange-magic. Typically configured with auth.type=none: the exchange itself IS the login.', 'inputType' => 'apiEndpoint'],
                ['name' => 'paramName', 'type' => 'string', 'required' => true, 'description' => 'The :param in your route URL that carries the magic code. If your route is /auth/magic/:key, pick "key". The picker lists the :params declared on the current page\'s route — typo-proof. Under the hood the verb reads QS.routeParams[name], the route the server resolved for the page (see ARCHITECTURE §6.3).', 'inputType' => 'routeParam'],
                ['name' => 'returnTo', 'type' => 'string', 'required' => false, 'description' => 'Where to go after the exchange succeeds, such as /dashboard. Optional. A path starting with / is a page of this site, composed like a link (the site\'s base, and the page\'s language on a multilingual site). Falls back to the ?return= query param, which is followed only when it is a path on this site. With neither, no auto-redirect: chain an explicit {{call:redirect:/path}} step instead. When provided AND saveToken calls are chained after this verb, the saveTokens still run before the browser processes the queued navigation (they\'re sync and execute in the same microtask).', 'inputType' => 'route', 'siteUrl' => 'page']
            ],
            'description' => 'Sign a visitor in from the link in their email: the page at /auth/magic/:key trades the code in the address for a real session token. It POSTs {key: <captured code>} to the configured endpoint and stores the response in QS._lastFetchResult, so chained saveToken calls pick up the returned token + refreshToken. The URL code is single-use and short-lived: never put the real session token in the URL directly (it leaks via email forwarding, browser history, corporate HTTPS proxy logs, and mail-client link prefetchers). The route is authored via /admin/sitemap. Dispatches qs:auth:exchange-started before fetch + qs:auth:exchange-failed in catch so a magic-link-handler component can show "connecting" / "invalid link" UI via data-auth-show.',
            'example' => '{{call:exchangeMagicLink:@auth-api/exchange-magic,key}};{{call:saveToken:localStorage,authToken,token}};{{call:saveToken:localStorage,refreshToken,refreshToken}};{{call:redirect:/dashboard}}',
            'events' => ['onload']
        ],
        [
            'name' => 'requestMagicLink',
            'category' => 'auth',
            'signature' => 'QS.requestMagicLink(endpoint, email, returnTo?)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'The endpoint that emails the link, as @apiId/endpointId: @auth-api/issue-magic. Usually configured with auth.type=none: it runs before the visitor has a session.', 'inputType' => 'apiEndpoint'],
                ['name' => 'email', 'type' => 'string', 'required' => true, 'description' => 'The address: a CSS selector for the <input> that holds it ("#email-input", ".email-field"), whose .value is read, or a literal address ("user@example.com").'],
                ['name' => 'returnTo', 'type' => 'string', 'required' => false, 'description' => 'Where to go after the request succeeds, typically a "check your email" page such as /check-email. Optional. A path starting with / is a page of this site, composed like a link (the site\'s base, and the page\'s language on a multilingual site).', 'inputType' => 'route', 'siteUrl' => 'page']
            ],
            'description' => 'Email a visitor a sign-in link: a "Send me a link" form, for example. It POSTs {email} to the issue-magic endpoint, and the auth API emails a single-use code. Pairs with exchangeMagicLink, which runs on the landing page after the visitor clicks the link. The server-side endpoint MUST NOT reveal whether the email exists in its user store; the standard pattern is "always return 200" to avoid an account-enumeration oracle. That is the auth API\'s responsibility: this verb just POSTs.',
            'example' => '{{call:validate:event,#login-form}};{{call:requestMagicLink:@auth-api/issue-magic,#email-input,/check-email}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'onsubmit']
        ],
        [
            'name' => 'logoutServer',
            'category' => 'auth',
            'signature' => 'QS.logoutServer(endpoint)',
            'args' => [
                ['name' => 'endpoint', 'type' => 'string', 'required' => true, 'description' => 'The endpoint that ends the session on the server, as @apiId/endpointId: @auth-api/logout. Usually a POST endpoint inheriting bearer auth: the server uses the request\'s token to identify which session to drop.', 'inputType' => 'apiEndpoint']
            ],
            'description' => 'Log a visitor out on the server too: the first half of a "Log out" button. Chain this BEFORE clearToken, so the request still has the token attached, then clearToken to drop the local copy. It tells the auth API to invalidate the session or revoke the refresh token, as a thin wrapper around QS.fetch so the registry\'s bearer auth is applied. Logout failures are intentionally swallowed: the visitor wants out either way, so a clearToken and a redirect after it always run.',
            'example' => '{{call:logoutServer:@auth-api/logout}};{{call:clearToken:localStorage,authToken}};{{call:clearToken:localStorage,refreshToken}};{{call:redirect:/}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup']
        ],
        [
            'name' => 'setState',
            'category' => 'state-store',
            'signature' => 'QS.setState(storeId, field, value)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'The state store to update, such as results (defined in the State stores panel).', 'inputType' => 'store'],
                ['name' => 'field', 'type' => 'string', 'required' => true, 'description' => 'The field within it, such as q.'],
                ['name' => 'value', 'type' => 'string', 'required' => true, 'description' => 'The value to set: a literal, or a #id / .class selector such as #searchBox to read that element\'s current value.']
            ],
            'description' => 'Change a field of a state store: put what a search box holds into the store\'s q field, for example. Pair with fetchState to send it to the bound endpoint.',
            'example' => '{{call:setState:results,q,#searchBox}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'oninput', 'onchange']
        ],
        [
            'name' => 'fetchState',
            'category' => 'state-store',
            'signature' => 'QS.fetchState(storeId)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'The state store to fetch, such as commandsList (defined in the State stores panel).', 'inputType' => 'store']
            ],
            'description' => 'Load a state store\'s data: after setState, or once when the page opens. It runs the store\'s bound endpoint with its current field values, applies the response back into the store, and re-renders its bound DOM (data-state-value / data-state-list). For infinite-scroll triggers, prefer onScrollFetchState: it adds debounce + in-flight + exhausted guards (raw onscroll → fetchState thrashes the API).',
            'example' => '{{call:fetchState:commandsList}}',
            'events' => ['onclick', 'ondblclick', 'oncontextmenu', 'onkeydown', 'onkeyup', 'oninput', 'onchange', 'onsubmit', 'onload']
        ],
        [
            'name' => 'onScrollFetchState',
            'category' => 'state-store',
            'signature' => 'QS.onScrollFetchState(storeId, triggerPx?, debounceMs?)',
            'args' => [
                ['name' => 'storeId', 'type' => 'string', 'required' => true, 'description' => 'The state store to load more of, such as scrollingStore (defined in the State stores panel).', 'inputType' => 'store'],
                ['name' => 'triggerPx', 'type' => 'number', 'required' => false, 'default' => 200, 'description' => 'How close to the page bottom, in pixels: it fires when the viewport bottom is within this many pixels of it (default 200).'],
                ['name' => 'debounceMs', 'type' => 'number', 'required' => false, 'default' => 100, 'description' => 'How long to wait between scroll bursts, in milliseconds (default 100).']
            ],
            'description' => 'Load more as the visitor scrolls down: infinite scroll. Use as a page-event ONLOAD action, and pair with an append:true list field. It registers (once per store) a debounced window-scroll listener that calls fetchState only when the viewport is near the page bottom, and STOPS firing once the store is marked exhausted (HTTP error, response items empty, or a both-direction cursor that did not advance). setState clears the exhausted flag (e.g. a fresh search re-arms the trigger).',
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
