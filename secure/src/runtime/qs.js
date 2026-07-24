/**
 * QuickSite Core Library (qs.js)
 * 
 * Lightweight utility functions for declarative interactions.
 * Use via {{call:functionName:args}} syntax in structure JSON.
 * 
 * Example: "onclick": "{{call:hide:#modal}}"
 * Renders: onclick="QS.hide('#modal')"
 * 
 * @version 2.0.0
 * @license MIT
 */

(function(window) {
    'use strict';

    // Create QS namespace
    const QS = {};

    /**
     * Client-side path matcher (beta.8 A1 Build Slice 2).
     *
     * Mirrors server-side TrimParameters::resolveRoute in pure JS.
     * Walks window.QS_ROUTES (build-emitted by qs-route-schema.js,
     * loaded BEFORE this file) and finds the best match for
     * location.pathname.
     *
     * Specificity rule (locked 2026-06-04): more literal segments
     * wins; declaration order in QS_ROUTES is the tie-breaker (first
     * match at the highest score wins).
     *
     * Exposed:
     *   QS.routeParams — captured URL path-params, e.g. {slug:'red-vase'}
     *   QS.routePath   — the matched pattern, e.g. 'products/:slug'
     *   QS.routeFound  — true when a route matched, false otherwise
     *
     * Multilingual: strips the first URL segment if it matches the
     * page's <html lang> attribute. Other prefix handling (BASE_URL,
     * PUBLIC_FOLDER_SPACE for sites in subpaths) is NOT yet handled —
     * filed as a follow-up. For now sites in subpaths would see the
     * subpath as the first segment.
     */
    QS.routeParams = {};
    QS.routePath   = null;
    QS.routeFound  = false;
    (function matchRouteOnLoad() {
        const schema = window.QS_ROUTES;
        if (!Array.isArray(schema)) return;

        // Normalise the URL path.
        let path = (window.location && window.location.pathname) || '';

        // Strip lang prefix if the first segment matches <html lang>.
        const lang = (document.documentElement && document.documentElement.lang) || '';
        if (lang) {
            if (path === '/' + lang || path === '/' + lang + '/') {
                path = '/';
            } else if (path.indexOf('/' + lang + '/') === 0) {
                path = path.substring(lang.length + 1);
            }
        }

        // Trim leading / trailing slashes; drop empty segments
        path = path.replace(/^\/+|\/+$/g, '');
        if (!path) return; // root URL — no route to match

        const urlSegments = path.split('/');

        // Iterate routes, find the highest-specificity match.
        // Specificity score = literal-segment count. Ties broken by
        // declaration order: first-encountered match at the highest
        // score wins (so we use strict-greater-than, not >=, when
        // updating bestMatch).
        let bestMatch = null;
        let bestScore = -1;

        for (let i = 0; i < schema.length; i++) {
            const route = schema[i];
            if (!route || typeof route.path !== 'string') continue;

            const patternSegments = route.path.split('/');
            if (patternSegments.length !== urlSegments.length) continue;

            const params = {};
            let literalScore = 0;
            let matched = true;

            for (let s = 0; s < patternSegments.length; s++) {
                const pat = patternSegments[s];
                const url = urlSegments[s];

                if (pat.length > 1 && pat.charAt(0) === ':') {
                    // Param segment — capture (urldecode to match
                    // server-side TrimParameters behavior).
                    try {
                        params[pat.substring(1)] = decodeURIComponent(url);
                    } catch (e) {
                        params[pat.substring(1)] = url;
                    }
                } else if (pat === url) {
                    literalScore++;
                } else {
                    matched = false;
                    break;
                }
            }

            if (matched && literalScore > bestScore) {
                bestScore = literalScore;
                bestMatch = { path: route.path, params: params };
            }
        }

        if (bestMatch) {
            QS.routePath   = bestMatch.path;
            QS.routeParams = bestMatch.params;
            QS.routeFound  = true;
        }
    })();

    // Inject default hidden class CSS if not already defined
    (function() {
        var id = 'qs-hidden-style';
        if (!document.getElementById(id)) {
            var s = document.createElement('style');
            s.id = id;
            s.textContent = '.hidden{display:none!important}';
            (document.head || document.documentElement).appendChild(s);
        }
    })();

    // Default hidden class
    const DEFAULT_HIDDEN_CLASS = 'hidden';

    /**
     * Resolve `selector` to a list of elements. Accepts a CSS string, a
     * single Element (inline `onclick="QS.hide(this)"`), an Array, or a
     * NodeList — so picker-saved selectors and inline-event `this` share
     * one entry point.
     * @param {string|Element|NodeList|Element[]} selector
     * @returns {NodeList|Element[]}
     */
    function getElements(selector) {
        if (selector instanceof Element) return [selector];
        if (selector instanceof NodeList) return selector;
        if (Array.isArray(selector)) return selector;
        try {
            return document.querySelectorAll(selector);
        } catch (e) {
            console.warn('[QS] Invalid selector:', selector);
            return [];
        }
    }

    /**
     * Single-element variant of getElements.
     * @param {string|Element} target
     * @returns {Element|null}
     */
    function getElement(target) {
        if (target instanceof Element) return target;
        try {
            return document.querySelector(target);
        } catch (e) {
            console.warn('[QS] Invalid selector:', target);
            return null;
        }
    }

    /**
     * Show element(s) - removes hidden class
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     * @param {string} [hideClass] - Class to remove (default: 'hidden')
     */
    QS.show = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.remove(hideClass));
    };

    /**
     * Hide element(s) - adds hidden class
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     * @param {string} [hideClass] - Class to add (default: 'hidden')
     */
    QS.hide = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.add(hideClass));
    };

    /**
     * Toggle element(s) visibility (show/hide flip-switch)
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     * @param {string} [hideClass] - Class to toggle (default: 'hidden')
     */
    QS.toggleHide = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.toggle(hideClass));
    };

    /**
     * Toggle a CSS class on element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to toggle
     */
    QS.toggle = function(target, className) {
        if (!className) {
            console.warn('[QS] toggle: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.toggle(className));
    };

    /**
     * Add a CSS class to element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to add
     */
    QS.addClass = function(target, className) {
        if (!className) {
            console.warn('[QS] addClass: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.add(className));
    };

    /**
     * Remove a CSS class from element(s)
     * @param {string} target - CSS selector
     * @param {string} className - Class to remove
     */
    QS.removeClass = function(target, className) {
        if (!className) {
            console.warn('[QS] removeClass: className required');
            return;
        }
        const elements = getElements(target);
        elements.forEach(el => el.classList.remove(className));
    };

    /**
     * Set text content or value of element(s)
     * Handles inputs, textareas, selects, checkboxes, and radios
     * @param {string} target - CSS selector
     * @param {string|boolean} value - Value to set (boolean for checkbox/radio)
     */
    QS.setValue = function(target, value) {
        const elements = getElements(target);
        elements.forEach(el => {
            if (el.tagName === 'INPUT') {
                const type = (el.type || '').toLowerCase();
                if (type === 'checkbox' || type === 'radio') {
                    // For checkbox/radio: set checked state
                    // Accept boolean, 'true'/'false' strings, or any truthy value
                    el.checked = (value === true || value === 'true' || value === '1');
                } else {
                    el.value = value;
                }
            } else if (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                el.value = value;
            } else {
                el.textContent = value;
            }
        });
    };

    /**
     * Navigate to URL
     * @param {string} url - URL to navigate to
     */
    QS.redirect = function(url) {
        if (url) {
            window.location.href = url;
        }
    };

    // ---- QS.filter: highlight helpers (DOM-walk, XSS-safe) ----
    // Snapshot/restore an element's original child nodes around <mark> wrapping.
    var _qsHighlightPerfWarned = false;
    var _QS_HIGHLIGHT_BUDGET = 500; // items × subelements
    function _qsCanHighlight(items, attr) {
        try {
            if (!items || !items.length) return true;
            // Sample the first item to estimate descendants per item.
            // For textContent mode (attr === null), 1 node per item.
            var sample = attr ? (items[0].querySelectorAll(attr).length || 1) : 1;
            if (items.length * sample > _QS_HIGHLIGHT_BUDGET) {
                if (!_qsHighlightPerfWarned) {
                    console.warn('[QS] filter: highlighting skipped (over ' + _QS_HIGHLIGHT_BUDGET + ' nodes)');
                    _qsHighlightPerfWarned = true;
                }
                return false;
            }
        } catch (e) { /* ignore */ }
        return true;
    }
    function _qsRestoreOriginal(el) {
        if (el && el.__qsOriginalChildren) {
            var clones = el.__qsOriginalChildren.map(function (n) { return n.cloneNode(true); });
            el.replaceChildren.apply(el, clones);
        }
    }
    function _qsHighlightIn(el, needle) {
        if (!el || !needle) return;
        if (!el.__qsOriginalChildren) {
            el.__qsOriginalChildren = Array.from(el.childNodes).map(function (n) { return n.cloneNode(true); });
        } else {
            // Restore first so we don't mark over previous marks.
            _qsRestoreOriginal(el);
        }
        var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                var p = n.parentNode;
                if (p && (p.nodeName === 'SCRIPT' || p.nodeName === 'STYLE' || p.nodeName === 'MARK')) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        textNodes.forEach(function (textNode) {
            var text = textNode.nodeValue;
            var lower = text.toLowerCase();
            var idx = lower.indexOf(needle);
            if (idx === -1) return;
            var frag = document.createDocumentFragment();
            var cursor = 0;
            while (idx !== -1) {
                if (idx > cursor) frag.appendChild(document.createTextNode(text.slice(cursor, idx)));
                var mark = document.createElement('mark');
                mark.className = 'qs-filter-hit';
                mark.textContent = text.slice(idx, idx + needle.length);
                frag.appendChild(mark);
                cursor = idx + needle.length;
                idx = lower.indexOf(needle, cursor);
            }
            if (cursor < text.length) frag.appendChild(document.createTextNode(text.slice(cursor)));
            textNode.parentNode.replaceChild(frag, textNode);
        });
    }

    /**
     * Filter elements based on text input
     * Call from input element's oninput event
     * @param {Event|string} eventOrSelector - Event object OR items selector (for backward compat)
     * @param {string} [itemsSelector] - Selector for items to filter (when event passed)
     * @param {string} [matchAttr] - What to match against. One of:
     *   - 'textContent' (default) → the item's full text
     *   - 'data-foo' or '[foo]' → an attribute on the item itself
     *   - a CSS selector starting with '.', '#', ' ', '>' → textContent of the
     *     matching descendant(s). Accepts a single selector or a comma-separated
     *     list (e.g. '.cmd-name, .cmd-description'); the text of every matched
     *     descendant is concatenated before matching.
     * @param {string} [hideClass] - Class to add/remove (default: 'hidden')
     * @param {string} [emptyParent] - Optional ancestor selector. Each matched
     *   ancestor is hidden (with `hideClass`) when none of its descendant items
     *   (matching `itemsSelector`) are visible, and shown again otherwise.
     *   Useful for collapsing empty sections (e.g. '.cmd-section').
     * 
     * Usage in JSON: "oninput": "{{call:filter:event,.card,.card-title,hidden,.cmd-section}}"
     * Pass 'event' as first arg to get input value from the triggering element
     */
    QS.filter = function(eventOrSelector, itemsSelector, matchAttr, hideClass, emptyParent) {
        let searchValue = '';
        let selector = itemsSelector;
        let attr = matchAttr;
        let hideCls = hideClass || DEFAULT_HIDDEN_CLASS;
        let parentSel = emptyParent;
        
        // Check if first arg is an event or a selector string
        if (eventOrSelector && typeof eventOrSelector === 'object' && eventOrSelector.target) {
            // Called with event: QS.filter(event, '.items', 'data-title')
            searchValue = (eventOrSelector.target.value || '').toLowerCase();
        } else if (typeof eventOrSelector === 'string') {
            // Called without event: QS.filter('.items', 'data-title')
            // Shift arguments and try to get value from activeElement
            selector = eventOrSelector;
            attr = itemsSelector;
            hideCls = matchAttr || DEFAULT_HIDDEN_CLASS;
            parentSel = hideClass;
            const activeEl = document.activeElement;
            searchValue = (activeEl && activeEl.value !== undefined) 
                ? activeEl.value.toLowerCase() 
                : '';
        }
        
        if (!selector) {
            console.warn('[QS] filter: itemsSelector required');
            return;
        }

        const items = getElements(selector);

        // Decide once whether highlighting participates this call.
        // - Child-selector mode: highlight inside the matched descendants.
        // - textContent (or no attr): highlight inside the item element itself.
        // - data-*/attr modes: no highlighting (match value isn't visible text).
        const isChildSelectorMode = !!attr && /^[.#> ]/.test(attr);
        const isTextContentMode = !attr || attr === 'textContent';
        const highlightEnabled = (isChildSelectorMode || isTextContentMode)
            && _qsCanHighlight(items, isChildSelectorMode ? attr : null);

        items.forEach(el => {
            let matchValue = '';
            let matchedChildren = null;

            if (isTextContentMode) {
                matchValue = el.textContent || '';
            } else if (attr.startsWith('data-') || attr.startsWith('[')) {
                // data-* attribute or [attr] selector syntax
                const attrName = attr.replace(/^\[|\]$/g, ''); // Remove brackets if present
                matchValue = el.getAttribute(attrName) || '';
            } else if (isChildSelectorMode) {
                // Child CSS selector (single or comma-separated) → concatenate
                // textContent of every matching descendant. A single selector
                // returns a 1-element list, so behavior is identical to before.
                matchedChildren = el.querySelectorAll(attr);
                matchValue = Array.from(matchedChildren).map(n => n.textContent || '').join(' ');
            } else {
                matchValue = el.getAttribute(attr) || el.textContent || '';
            }

            matchValue = matchValue.toLowerCase();

            const isHit = searchValue === '' || matchValue.includes(searchValue);
            if (isHit) {
                el.classList.remove(hideCls);
            } else {
                el.classList.add(hideCls);
            }

            // Highlight management.
            if (isChildSelectorMode && matchedChildren) {
                if (isHit && searchValue !== '' && highlightEnabled) {
                    matchedChildren.forEach(child => _qsHighlightIn(child, searchValue));
                } else {
                    matchedChildren.forEach(child => _qsRestoreOriginal(child));
                }
            } else if (isTextContentMode) {
                if (isHit && searchValue !== '' && highlightEnabled) {
                    _qsHighlightIn(el, searchValue);
                } else {
                    _qsRestoreOriginal(el);
                }
            }
        });

        // Hide ancestors that no longer have any visible items.
        if (parentSel) {
            getElements(parentSel).forEach(parent => {
                const visible = parent.querySelector(selector + ':not(.' + hideCls + ')');
                if (visible) {
                    parent.classList.remove(hideCls);
                } else {
                    parent.classList.add(hideCls);
                }
            });
        }
    };

    /**
     * Scroll smoothly to an element
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     * @param {string} [behavior] - 'smooth' (default) or 'instant'
     */
    QS.scrollTo = function(target, behavior) {
        behavior = behavior || 'smooth';
        const element = getElement(target);
        if (element) {
            element.scrollIntoView({ behavior: behavior, block: 'start' });
        }
    };

    /**
     * Focus an element
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     */
    QS.focus = function(target) {
        const element = getElement(target);
        if (element && element.focus) {
            element.focus();
        }
    };

    /**
     * Blur (unfocus) an element
     * @param {string|Element} target - CSS selector, or an Element (e.g. inline `this`)
     */
    QS.blur = function(target) {
        const element = getElement(target);
        if (element && element.blur) {
            element.blur();
        }
    };

    // =========================================================================
    // TOAST NOTIFICATIONS
    // =========================================================================
    
    /**
     * Show a toast notification
     * @param {string} message - Message to display
     * @param {string} [type] - 'success', 'error', 'warning', 'info' (default: 'info')
     * @param {number} [duration] - Duration in ms (default: 4000)
     */
    QS.toast = function(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        
        // Get or create container
        let container = document.querySelector('.qs-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'qs-toast-container';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;display:flex;flex-direction:column;gap:8px;';
            document.body.appendChild(container);
        }
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = 'qs-toast qs-toast--' + type;
        const colors = { success: '#22c55e', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
        toast.style.cssText = 'padding:12px 20px;background:' + (colors[type] || colors.info) + ';color:#fff;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;max-width:350px;opacity:0;transform:translateX(100%);transition:all 0.3s ease;';
        toast.textContent = message;
        container.appendChild(toast);
        
        // Animate in
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });
        
        // Remove after duration
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };

    // =========================================================================
    // FETCH / API CALLS
    // =========================================================================
    
    /**
     * Make an API fetch call
     * Supports two modes:
     * 1. Registry mode: {{call:fetch:@apiId/endpointId,...}}
     * 2. Direct URL mode: {{call:fetch:METHOD,url,...}}
     * 
     * Options (comma-separated):
     * - body=#form or body=#selector (form data or JSON from inputs)
     * - silent=1 (suppress the default success/error toast)
     * - toastSuccessKey / toastErrorKey (translated toast text, resolved
     *   server-side at compile time via JsonToHtmlRenderer's
     *   TRANSLATABLE_KEYWORD_ARGS metadata)
     *
     * @param {string} target - Registry ref (@api/endpoint) or METHOD
     * @param {...string} options - Additional options
     */
    QS.fetch = function(target, ...options) {
        // Parse options into object
        const opts = {};
        let url = null;
        let method = 'GET';

        // `ref` is the public identity of this fetch — used as the cache
        // key and emitted on the qs:fetch:loaded / qs:fetch:error events
        // so listeners can filter (e.g. `if (e.detail.ref === '@api/x')`).
        // Set late in direct-URL mode once we know the URL.
        let ref = target;

        options.forEach(opt => {
            if (opt.includes('=')) {
                const [key, val] = opt.split('=');
                opts[key.trim()] = val.trim();
            }
        });
        
        // Registry mode: @apiId/endpointId or @endpointId
        if (target.startsWith('@')) {
            const resolved = resolveEndpoint(target.substring(1));
            if (!resolved) {
                // beta.8 Track A4 — a missing endpoint here can mean:
                //   (a) typo in the @api/endpoint reference,
                //   (b) the endpoint is callableFrom:'server' and was
                //       filtered out of qs-api-config.js by design
                //       (server-only endpoints never reach client config),
                //   (c) qs-api-config.js is stale and a rebuild is needed.
                // Give devs the hint via console; users still get the
                // friendly toast (they shouldn't see this in healthy
                // configurations).
                console.warn(
                    '[QS] fetch: endpoint ' + target + ' not in client config. '
                    + 'Likely causes: typo, the endpoint is marked '
                    + "callableFrom:'server' (won't appear here by design), "
                    + 'or qs-api-config.js is stale (rebuild).'
                );
                QS.toast('API endpoint not found: ' + target, 'error');
                return Promise.reject(new Error('Endpoint not found'));
            }
            url = resolved.url;
            method = resolved.method;
            opts._auth = resolved.auth;
            opts._endpoint = resolved.endpoint;
        }
        // Direct URL mode: METHOD is first arg, URL is second
        else {
            method = target.toUpperCase();
            url = options[0];
            ref = method + ' ' + (url || '');
            // Re-parse remaining options (skip URL)
            for (let i = 1; i < options.length; i++) {
                const opt = options[i];
                if (opt && opt.includes('=')) {
                    const [key, val] = opt.split('=');
                    opts[key.trim()] = val.trim();
                }
            }
        }
        
        if (!url) {
            QS.toast('No URL specified for fetch', 'error');
            return Promise.reject(new Error('No URL'));
        }
        
        // Resolve {{lang}} placeholder in URL
        const pageLang = document.documentElement.lang || 'en';
        url = url.replace(/\{\{lang\}\}/g, pageLang);

        // Substitute :placeholders in URL from `opts`. Mirrors server-side
        // substitution in testApiEndpoint.php. Any opts key whose name
        // matches a `:name` segment in the URL becomes the path value
        // (URL-encoded). Required params (per the endpoint's declared
        // `parameters`) that are missing → reject with a clear error.
        // Remaining opts go to the query string at the end (below).
        const RESERVED_OPTS = new Set([
            'body', 'onSuccess', 'onError', 'silent', '_auth', '_endpoint',
            // Translatable toast labels — already resolved to strings by
            // PHP at compile time. They're not path placeholders and
            // shouldn't leak into the query string.
            'toastSuccessKey', 'toastErrorKey',
            // Suppress the endpoint's responseBindings for this call — used by
            // QS.fetchState so a state store owns its own rendering (otherwise
            // append/infinite would flicker: bindings replace, store appends).
            'noBindings'
        ]);
        if (url.indexOf(':') !== -1) {
            const placeholders = [];
            url.replace(/:([a-zA-Z][a-zA-Z0-9_]*)/g, function (_m, n) {
                placeholders.push(n); return _m;
            });
            const paramDefs = (opts._endpoint && opts._endpoint.parameters) || [];
            for (const name of placeholders) {
                const val = opts[name];
                const def = paramDefs.find(p => p && p.name === name);
                const required = !!(def && def.required);
                if (val !== undefined && val !== null && val !== '') {
                    url = url.replace(':' + name, encodeURIComponent(val));
                    delete opts[name];   // consumed; don't echo into query string
                } else if (required) {
                    const msg = '[QS] fetch: missing required parameter: ' + name;
                    console.warn(msg);
                    QS.toast('Missing required parameter: ' + name, 'error');
                    return Promise.reject(new Error(msg));
                }
                // Optional + missing → leave `:name` literal (consistent with test panel).
            }
        }

        // Build request options
        const fetchOpts = { method: method, headers: {} };
        
        // Always send Accept-Language for multilingual API support
        fetchOpts.headers['Accept-Language'] = pageLang;
        
        // Apply auth if from registry
        if (opts._auth) {
            applyAuth(fetchOpts, opts._auth);
        }
        
        // GET/HEAD requests can't carry a body — the browser throws
        // synchronously if we try. So when method is GET/HEAD and a
        // `body=#form` source is provided, flatten the collected fields
        // into the query string instead. This matches how HTML form
        // submission natively encodes GET form bodies, and is what most
        // users expect for "submit a search form" / "filter list" flows.
        const isBodyless = (method === 'GET' || method === 'HEAD');
        const queryParts = [];

        if (opts.body) {
            const bodyData = collectBody(opts.body);
            if (bodyData) {
                if (isBodyless) {
                    // Each top-level field → its own query param.
                    for (const k in bodyData) {
                        if (!Object.prototype.hasOwnProperty.call(bodyData, k)) continue;
                        const v = bodyData[k];
                        if (v === undefined || v === null || v === '') continue;
                        queryParts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                    }
                } else {
                    fetchOpts.body = JSON.stringify(bodyData);
                    fetchOpts.headers['Content-Type'] = 'application/json';
                }
            }
        }

        // Any opts not consumed by path substitution and not in the reserved
        // control set are appended to the query string. This lets callers
        // pass e.g. `page=2,sort=name` without manually building the URL.
        for (const key in opts) {
            if (!Object.prototype.hasOwnProperty.call(opts, key)) continue;
            if (RESERVED_OPTS.has(key)) continue;
            const val = opts[key];
            if (val === undefined || val === null || val === '') continue;
            queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
        }
        if (queryParts.length > 0) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + queryParts.join('&');
        }

        // Make the request. Tier 2: on a 401 from an endpoint whose auth
        // declares a refreshEndpoint, transparently refresh the access token
        // and retry the original request exactly once. The refresh call uses
        // plain fetch (not QS.fetch), so it can't recurse into another refresh.
        const issueRequest = () => fetch(url, fetchOpts);
        return issueRequest()
            .then(response => {
                if (response.status === 401 && opts._auth && opts._auth.refreshEndpoint) {
                    return refreshAuthToken(opts._auth).then(refreshed => {
                        if (!refreshed) return response; // let the 401 fall through to onError
                        const t = getTokenValue(opts._auth.tokenSource);
                        if (t) fetchOpts.headers['Authorization'] = 'Bearer ' + t;
                        return issueRequest(); // retry once with the new token
                    });
                }
                return response;
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json().catch(() => ({}));
            })
            .then(data => {
                // Store for direct renderList access
                QS._lastFetchResult = data;

                // Cache by ref so late subscribers (QS.onceCached) can
                // replay the latest result without re-fetching.
                QS._fetchCache[ref] = data;

                // Apply response bindings if defined (unless the caller opts
                // out via noBindings — e.g. QS.fetchState renders via the store).
                if (opts._endpoint && opts._endpoint.responseBindings && !opts.noBindings) {
                    applyBindings(data, opts._endpoint.responseBindings);
                }

                // Toast: `toastSuccessKey` (translated server-side at
                // compile time via TRANSLATABLE_KEYWORD_ARGS in
                // JsonToHtmlRenderer) overrides the default 'Success'
                // text. `silent` opts out entirely.
                if (!opts.silent) {
                    QS.toast(opts.toastSuccessKey || 'Success', 'success');
                }

                // Notify any listener (e.g. cross-page count, post-fetch chains).
                // Fires AFTER bindings + toast so listeners see a fully
                // settled DOM if they react to it.
                document.dispatchEvent(new CustomEvent('qs:fetch:loaded', {
                    detail: { ref: ref, data: data }
                }));

                return data;
            })
            .catch(error => {
                // Error toast — same precedence as success but using
                // toastErrorKey: explicit translated key → network
                // error message → generic fallback. `silent` opts out.
                if (!opts.silent) {
                    QS.toast(opts.toastErrorKey || error.message || 'Request failed', 'error');
                }

                document.dispatchEvent(new CustomEvent('qs:fetch:error', {
                    detail: { ref: ref, error: error }
                }));

                throw error;
            });
    };

    // Cache of the latest data per ref — populated by QS.fetch on success,
    // consulted by QS.onceCached for late subscribers.
    QS._fetchCache = {};

    /**
     * Subscribe to a QS event (e.g. `fetch:loaded`, `fetch:error`) and
     * auto-unsubscribe after the first call.
     *
     * Use this when you want "after the NEXT fetch lands, do X" without
     * the lifecycle hassle of addEventListener + removeEventListener.
     * Does NOT replay cached results — call QS.onceCached for that.
     *
     * @param {string} eventSuffix  e.g. 'fetch:loaded'
     * @param {function} handler    Receives the CustomEvent.
     */
    QS.after = function(eventSuffix, handler) {
        const evt = 'qs:' + eventSuffix;
        const wrapped = function (e) {
            document.removeEventListener(evt, wrapped);
            try { handler(e); } catch (err) { console.warn('[QS] QS.after handler threw:', err); }
        };
        document.addEventListener(evt, wrapped);
    };

    /**
     * Same as QS.after, BUT if `QS._fetchCache` already has data for any
     * ref under this event type, fire the handler immediately once per
     * cached ref (synthetic event with .detail.{ref, data}), AND register
     * a one-shot listener for future events.
     *
     * Use this for "give me the latest result whether it just arrived or
     * landed earlier" — common pattern for cross-page bindings.
     */
    QS.onceCached = function(eventSuffix, handler) {
        if (eventSuffix === 'fetch:loaded') {
            const cache = QS._fetchCache || {};
            for (const ref in cache) {
                if (!Object.prototype.hasOwnProperty.call(cache, ref)) continue;
                try {
                    handler({ type: 'qs:fetch:loaded', detail: { ref: ref, data: cache[ref] } });
                } catch (err) {
                    console.warn('[QS] QS.onceCached cached-replay threw:', err);
                }
            }
        }
        QS.after(eventSuffix, handler);
    };
    
    /**
     * Resolve an endpoint reference from the registry
     * @param {string} ref - "apiId/endpointId" or "endpointId"
     * @returns {object|null} {url, method, auth, endpoint}
     */
    function resolveEndpoint(ref) {
        const registry = window.QS_API_ENDPOINTS;
        if (!registry) {
            console.warn('[QS] API registry not loaded (QS_API_ENDPOINTS)');
            return null;
        }
        
        let apiId, endpointId;
        
        if (ref.includes('/')) {
            [apiId, endpointId] = ref.split('/');
        } else {
            // Search all APIs for this endpoint
            endpointId = ref;
            for (const [id, api] of Object.entries(registry)) {
                if (api.endpoints && api.endpoints[endpointId]) {
                    apiId = id;
                    break;
                }
            }
        }
        
        if (!apiId || !registry[apiId]) {
            return null;
        }
        
        const api = registry[apiId];
        const endpoint = api.endpoints && api.endpoints[endpointId];
        
        if (!endpoint) {
            return null;
        }
        
        // Build full URL
        let url = (api.baseUrl || '') + (endpoint.path || '');
        
        return {
            url: url,
            method: endpoint.method || 'GET',
            auth: api.auth,
            endpoint: endpoint
        };
    }
    
    /**
     * Apply auth configuration to fetch options
     * @param {object} fetchOpts - Fetch options object
     * @param {object} auth - Auth config {type, tokenSource, apiKey, headerName}
     */
    function applyAuth(fetchOpts, auth) {
        if (!auth || !auth.type || auth.type === 'none') {
            return;
        }
        
        if (auth.type === 'bearer' && auth.tokenSource) {
            const token = getTokenValue(auth.tokenSource);
            if (token) {
                fetchOpts.headers['Authorization'] = 'Bearer ' + token;
            }
        } else if (auth.type === 'api-key' && auth.apiKey) {
            const headerName = auth.headerName || 'X-API-Key';
            // apiKey could be a tokenSource reference or literal value
            const keyValue = auth.apiKey.includes(':') ? getTokenValue(auth.apiKey) : auth.apiKey;
            if (keyValue) {
                fetchOpts.headers[headerName] = keyValue;
            }
        } else if (auth.type === 'basic' && auth.tokenSource) {
            const token = getTokenValue(auth.tokenSource);
            if (token) {
                fetchOpts.headers['Authorization'] = 'Basic ' + token;
            }
        } else if (auth.type === 'cookie') {
            // Pattern X: same-origin session cookie. The browser owns
            // the cookie; we just need to ask fetch to send it. No
            // tokenSource plumbing — the server's Set-Cookie header is
            // self-contained, and the browser sends it automatically.
            // `credentials: 'include'` ALSO works cross-origin if the
            // server sets the right CORS headers (Access-Control-
            // Allow-Credentials + a concrete Origin). Documented limit:
            // no built-in CSRF token helper; if the API needs a CSRF
            // token echoed in a header, configure it manually via a
            // separate interaction (filed as a future concern).
            fetchOpts.credentials = 'include';
        }
    }
    
    /**
     * Get token value from storage
     * @param {string} source - "localStorage:key" or "sessionStorage:key"
     * @returns {string|null}
     */
    function getTokenValue(source) {
        if (!source || !source.includes(':')) {
            return null;
        }
        
        const [storageType, key] = source.split(':');
        
        if (storageType === 'localStorage') {
            return localStorage.getItem(key);
        } else if (storageType === 'sessionStorage') {
            return sessionStorage.getItem(key);
        }
        
        return null;
    }

    /**
     * Parse a "localStorage:key" / "sessionStorage:key" reference.
     * @param {string} ref
     * @returns {{storage:string, key:string}|null}
     */
    function parseStorageRef(ref) {
        if (!ref || ref.indexOf(':') === -1) return null;
        const idx = ref.indexOf(':');
        return { storage: ref.slice(0, idx), key: ref.slice(idx + 1) };
    }

    // =========================================================================
    // CONSENT — GDPR write-gating (beta.9 Phase 2)
    //
    // Reads the `consent_prefs` cookie + window.QS_CONSENT (the key→category map,
    // emitted server-side ONLY when the project enabled the consent layer). When
    // the layer is off, gating is fully dormant. When on, non-essential storage
    // writes are skipped unless the visitor consented to that key's category.
    // =========================================================================

    /** Parse the consent_prefs cookie → object, or null. */
    function _readConsentCookie() {
        try {
            var m = document.cookie.match(/(?:^|;\s*)consent_prefs=([^;]*)/);
            if (!m) return null;
            var obj = JSON.parse(decodeURIComponent(m[1]));
            return (obj && typeof obj === 'object') ? obj : null;
        } catch (e) {
            return null;
        }
    }

    /** Current consent choices (empty object when none set). */
    QS.getConsent = function() {
        return _readConsentCookie() || {};
    };

    /** essential is always granted; every other category needs an explicit true. */
    QS.hasConsent = function(category) {
        if (category === 'essential') return true;
        var prefs = _readConsentCookie();
        return !!(prefs && prefs[category] === true);
    };

    /**
     * Persist the visitor's consent choice (used by the banner — slice 7).
     * 180-day life, Path=/, SameSite=Lax, Secure on https. Fires
     * `qs:consent:changed` so the banner/gated UI can react.
     * @param {{functional?:boolean, analytics?:boolean, marketing?:boolean}} prefs
     */
    QS.setConsent = function(prefs) {
        var cfg = (window.QS_CONSENT && window.QS_CONSENT.enabled) ? window.QS_CONSENT : null;
        var payload = {
            v: cfg ? (cfg.version || 1) : 1,
            functional: !!(prefs && prefs.functional),
            analytics: !!(prefs && prefs.analytics),
            marketing: !!(prefs && prefs.marketing)
        };
        var maxAge = 180 * 24 * 60 * 60; // 180 days
        var secure = (location.protocol === 'https:') ? '; Secure' : '';
        document.cookie = 'consent_prefs=' + encodeURIComponent(JSON.stringify(payload)) +
            '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax' + secure;
        document.dispatchEvent(new CustomEvent('qs:consent:changed', { detail: payload }));
        return payload;
    };

    /**
     * Gate a storage WRITE by key. Returns true when the write may proceed.
     * Dormant (always true) unless the project enabled the consent layer
     * (window.QS_CONSENT). Then: essential always passes; a declared non-
     * essential key needs consent; an UNDECLARED key is fail-closed (blocked) —
     * GDPR-safe per the locked design.
     */
    function _consentAllowsWrite(key) {
        var cfg = window.QS_CONSENT;
        if (!cfg || !cfg.enabled) return true;          // layer off → no gating
        var category = (cfg.categories && cfg.categories[key]) || null;
        if (category === 'essential') return true;
        if (category === null) {                         // undeclared → fail-closed
            _consentBlocked(key, null);
            return false;
        }
        if (QS.hasConsent(category)) return true;
        _consentBlocked(key, category);
        return false;
    }

    function _consentBlocked(key, category) {
        try {
            document.dispatchEvent(new CustomEvent('qs:consent:blocked', {
                detail: { key: key, category: category }
            }));
        } catch (e) { /* no-op */ }
        if (window.console && console.debug) {
            console.debug('[QS] consent: write to "' + key + '" skipped (category=' + (category || 'undeclared') + ')');
        }
    }

    // ---- Banner + popup controller (slice 7) ------------------------------
    // Wires the generated consent-banner / consent-popup structures (reserved
    // ids + data-consent-action / data-consent-toggle). The structures carry
    // the markup (styleable/editable); this drives behaviour.

    var CONSENT_TOGGLE_CATS = ['functional', 'analytics', 'marketing'];

    function _consentShow(el) { if (el) el.hidden = false; }
    function _consentHide(el) { if (el) el.hidden = true; }

    function _consentSyncToggles(popup, prefs) {
        if (!popup) return;
        popup.querySelectorAll('[data-consent-toggle]').forEach(function (cb) {
            var cat = cb.getAttribute('data-consent-toggle');
            cb.checked = !!(prefs && prefs[cat] === true);
        });
    }

    function _consentReadToggles(popup) {
        var out = {};
        CONSENT_TOGGLE_CATS.forEach(function (c) { out[c] = false; });
        if (popup) {
            popup.querySelectorAll('[data-consent-toggle]').forEach(function (cb) {
                out[cb.getAttribute('data-consent-toggle')] = !!cb.checked;
            });
        }
        return out;
    }

    /** Engine default styles, injected once so the layer is usable un-styled.
     *  Authors override via their own stylesheet (later: seed into style.css). */
    function _consentInjectStyles() {
        if (document.getElementById('qs-consent-styles')) return;
        if (!document.getElementById('qs-consent-banner') && !document.getElementById('qs-consent-popup')) return;
        var css =
            // Respect the `hidden` attribute — a class selector's display would
            // otherwise override the UA [hidden]{display:none}, so both layers
            // would always show. This rule (higher specificity) wins.
            '.qs-consent-banner[hidden],.qs-consent-popup[hidden]{display:none}' +
            '.qs-consent-banner{position:fixed;left:0;right:0;bottom:0;z-index:9999;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;background:#1d1f23;color:#f3f4f6;box-shadow:0 -2px 16px rgba(0,0,0,.25);font-size:14px}' +
            '.qs-consent-banner__text{margin:0;flex:1 1 320px;line-height:1.45}' +
            '.qs-consent-banner__policy{color:#9ecbff;margin-left:6px}' +
            '.qs-consent-banner__actions{display:flex;flex-wrap:wrap;gap:8px}' +
            '.qs-consent-btn{cursor:pointer;border:1px solid transparent;border-radius:6px;padding:8px 14px;font-size:14px;font-weight:600}' +
            '.qs-consent-btn--ghost{background:transparent;border-color:#4b5563;color:#f3f4f6}' +
            '.qs-consent-btn--primary{background:#2563eb;color:#fff}' +
            '.qs-consent-popup{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.5)}' +
            '.qs-consent-popup__dialog{background:#fff;color:#1d1f23;border-radius:12px;max-width:440px;width:100%;padding:20px 22px;box-shadow:0 12px 48px rgba(0,0,0,.35)}' +
            '.qs-consent-popup__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}' +
            '.qs-consent-popup__title{margin:0;font-size:18px}' +
            '.qs-consent-popup__close{background:none;border:0;font-size:18px;cursor:pointer;color:#6b7280}' +
            '.qs-consent-popup__intro{margin:0 0 14px;color:#4b5563;font-size:13px}' +
            '.qs-consent-popup__rows{display:flex;flex-direction:column;gap:4px;margin-bottom:16px}' +
            '.qs-consent-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px}' +
            '.qs-consent-row__name{font-weight:600;flex:1}' +
            '.qs-consent-row__note{font-size:12px;color:#6b7280}' +
            '.qs-consent-row__toggle{width:18px;height:18px}' +
            '.qs-consent-row--locked{opacity:.7}' +
            '.qs-consent-popup__actions{display:flex;justify-content:flex-end;gap:8px}' +
            // Cookie-policy page (rendered on the same pages as the global banner).
            '.qs-cookie-policy-page{max-width:900px;margin:0 auto;padding:24px 16px}' +
            '.qs-cookie-policy{width:100%;border-collapse:collapse;margin:16px 0;font-size:14px}' +
            '.qs-cookie-policy th,.qs-cookie-policy td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top}' +
            '.qs-cookie-policy th{background:#f9fafb;font-weight:600}' +
            '.qs-cookie-policy__key{font-family:ui-monospace,Menlo,Consolas,monospace}' +
            '.qs-cookie-policy__providers{line-height:1.7}' +
            '.qs-cookie-policy__disclaimer{margin-top:20px;font-size:12px;color:#6b7280;font-style:italic}';
        var style = document.createElement('style');
        style.id = 'qs-consent-styles';
        style.textContent = css;
        (document.head || document.documentElement).appendChild(style);
    }

    function _wireConsentActions(scope, banner, popup) {
        if (!scope) return;
        scope.querySelectorAll('[data-consent-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switch (btn.getAttribute('data-consent-action')) {
                    case 'accept-all':
                        QS.setConsent({ functional: true, analytics: true, marketing: true });
                        _consentHide(banner); _consentHide(popup);
                        break;
                    case 'refuse-all':
                        QS.setConsent({ functional: false, analytics: false, marketing: false });
                        _consentHide(banner); _consentHide(popup);
                        break;
                    case 'customize':
                        _consentSyncToggles(popup, QS.getConsent());
                        _consentShow(popup);
                        break;
                    case 'save':
                        QS.setConsent(_consentReadToggles(popup));
                        _consentHide(popup); _consentHide(banner);
                        break;
                    case 'close':
                        _consentHide(popup);
                        break;
                }
            });
        });
    }

    function _initConsentBanner() {
        var banner = document.getElementById('qs-consent-banner');
        var popup = document.getElementById('qs-consent-popup');
        if (!banner && !popup) return;
        _consentInjectStyles();
        _wireConsentActions(banner, banner, popup);
        _wireConsentActions(popup, banner, popup);
        // Auto-show the banner when the layer is enabled (live site) and the
        // visitor hasn't chosen yet. QS_CONSENT is emitted live-only, so the
        // banner never auto-pops in the editor (the toolbar toggle shows it).
        if (window.QS_CONSENT && window.QS_CONSENT.enabled && !_readConsentCookie()) {
            _consentShow(banner);
        }
    }

    /** Re-open the preferences popup (e.g. a footer "Cookie settings" link). */
    QS.openConsent = function () {
        var popup = document.getElementById('qs-consent-popup');
        _consentSyncToggles(popup, QS.getConsent());
        _consentShow(popup);
    };

    /**
     * Write a token value to browser storage and fire qs:auth:saved.
     * Shared by QS.saveToken and the Tier 2 refresh flow.
     * @returns {boolean} success
     */
    function setStoredToken(storage, key, value) {
        if (storage !== 'localStorage' && storage !== 'sessionStorage') return false;
        if (!_consentAllowsWrite(key)) return true;   // consent-gated: skip, not an error
        try {
            window[storage].setItem(key, String(value));
        } catch (e) {
            return false;
        }
        document.dispatchEvent(new CustomEvent('qs:auth:saved', {
            detail: { storage: storage, key: key, tokenKey: key, value: String(value) }
        }));
        return true;
    }

    // Tier 2: one in-flight refresh per refresh-token storage location, so a
    // burst of concurrent 401s collapses to a single refresh POST.
    QS._refreshInFlight = QS._refreshInFlight || {};

    /**
     * Refresh the access token for an endpoint's auth config (Tier 2).
     *
     * Reads the refresh token from `auth.refreshTokenSource`, POSTs it to
     * `auth.refreshEndpoint` (resolved from the registry — it runs with its
     * OWN configured auth), then stores the new access token at
     * `auth.tokenSource` (path `auth.responseTokenPath`) and rotates the
     * refresh token when `auth.responseRefreshTokenPath` is set and present.
     * On ANY failure (no refresh token, endpoint error, missing token in the
     * response) it clears BOTH stored tokens — firing qs:auth:cleared so a
     * listener can redirect to login — and resolves false.
     *
     * @param {object} auth Endpoint auth config
     * @returns {Promise<boolean>} true when a new access token was stored
     */
    function refreshAuthToken(auth) {
        const lockKey = auth.refreshTokenSource || auth.refreshEndpoint || '@';
        if (QS._refreshInFlight[lockKey]) return QS._refreshInFlight[lockKey];

        const refreshToken = getTokenValue(auth.refreshTokenSource);
        const resolved = auth.refreshEndpoint ? resolveEndpoint(auth.refreshEndpoint.replace(/^@/, '')) : null;
        if (!refreshToken || !resolved) {
            return Promise.resolve(false);
        }

        const method = (resolved.method || 'POST').toUpperCase();
        const refreshOpts = { method: method, headers: { 'Content-Type': 'application/json' } };
        // The refresh endpoint runs with its own configured auth (typically
        // none); we don't special-case the expired bearer.
        applyAuth(refreshOpts, resolved.auth);
        if (method !== 'GET' && method !== 'HEAD') {
            const body = {};
            body[auth.refreshTokenBodyField] = refreshToken;
            refreshOpts.body = JSON.stringify(body);
        }

        const promise = fetch(resolved.url, refreshOpts)
            .then(r => r.ok ? r.json().catch(() => ({})) : Promise.reject(new Error('refresh HTTP ' + r.status)))
            .then(refreshData => {
                const newToken = getNestedValue(refreshData, auth.responseTokenPath);
                if (newToken === undefined || newToken === null || newToken === '') {
                    throw new Error('refresh: no token at "' + auth.responseTokenPath + '"');
                }
                const dst = parseStorageRef(auth.tokenSource);
                if (dst) setStoredToken(dst.storage, dst.key, newToken);

                // Rotate the refresh token if the endpoint returned a new one.
                if (auth.responseRefreshTokenPath) {
                    const newRefresh = getNestedValue(refreshData, auth.responseRefreshTokenPath);
                    if (newRefresh !== undefined && newRefresh !== null && newRefresh !== '') {
                        const rdst = parseStorageRef(auth.refreshTokenSource);
                        if (rdst) setStoredToken(rdst.storage, rdst.key, newRefresh);
                    }
                }
                return true;
            })
            .catch(() => {
                // Refresh failed → clear both tokens so the app lands in a clean
                // logged-out state; qs:auth:cleared listeners can redirect.
                const at = parseStorageRef(auth.tokenSource);
                const rt = parseStorageRef(auth.refreshTokenSource);
                if (at) QS.clearToken(at.storage, at.key);
                if (rt) QS.clearToken(rt.storage, rt.key);
                return false;
            })
            .finally(() => { delete QS._refreshInFlight[lockKey]; });

        QS._refreshInFlight[lockKey] = promise;
        return promise;
    }

    /**
     * Collect body data from a form or input elements
     * @param {string} selector - Form selector (#form) or inputs selector
     * @returns {object|null} JSON data
     */
    function collectBody(selector) {
        const el = document.querySelector(selector);
        if (!el) {
            console.warn('[QS] Body selector not found:', selector);
            return null;
        }
        
        // If it's a form, use FormData
        if (el.tagName === 'FORM') {
            const formData = new FormData(el);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            return data;
        }
        
        // Otherwise, find all inputs within the element
        const inputs = el.querySelectorAll('input, textarea, select');
        const data = {};
        inputs.forEach(input => {
            const name = input.name || input.id;
            if (name) {
                if (input.type === 'checkbox') {
                    data[name] = input.checked;
                } else if (input.type === 'radio') {
                    if (input.checked) {
                        data[name] = input.value;
                    }
                } else {
                    data[name] = input.value;
                }
            }
        });
        
        return Object.keys(data).length > 0 ? data : null;
    }
    
    /**
     * Apply response bindings to DOM.
     *
     * Supported renderModes:
     *   - 'list'          → data-bind template, items applied directly.
     *   - 'componentList' → data-bind template (typically a hidden
     *                       component instance), per-item `fieldMap`
     *                       maps API fields → template variables,
     *                       optional `enum: "<component>.<key>"` per
     *                       field resolves against window.QS_ENUMS.
     *   - default         → scalar binding (selector + optional attribute).
     *
     * @param {object} data - Response data
     * @param {array} bindings - binding spec array
     */
    function applyBindings(data, bindings) {
        if (!Array.isArray(bindings)) return;

        bindings.forEach(binding => {
            const { field } = binding;
            if (!field) return;

            // Get value from data using dot notation
            const value = getNestedValue(data, field);
            if (value === undefined) return;

            // List rendering mode (simple — items applied directly via data-bind)
            if (binding.renderMode === 'list') {
                const container = binding.container;
                if (!container) return;
                renderList(container, value, binding.emptyText);
                return;
            }

            // Component-list mode (clone-and-map: each item is mapped
            // through binding.fieldMap, with optional per-field enum
            // resolution against window.QS_ENUMS, then applied to the
            // cloned template via the existing data-bind mechanism).
            if (binding.renderMode === 'componentList') {
                const container = binding.container;
                if (!container) {
                    console.warn('[QS] componentList: missing `container` in binding for field:', field);
                    return;
                }
                if (!Array.isArray(value)) {
                    console.warn('[QS] componentList: field did not resolve to an array:', field, value);
                    return;
                }
                renderComponentList(container, value, binding.fieldMap || {}, binding.emptyText);
                return;
            }

            // Count mode — write the count of an array/value to a
            // selector, optionally as a translated sentence with
            // {n} substitution + zero/one/many branching. The
            // sentence strings (zeroStr / oneStr / manyStr) are
            // pre-resolved server-side at writeCompiledJs time;
            // runtime just picks one and substitutes {n}.
            if (binding.renderMode === 'count') {
                renderCount(value, binding);
                return;
            }

            // Scalar binding mode
            const { selector, attribute } = binding;
            if (!selector) return;

            const elements = getElements(selector);
            elements.forEach(el => {
                if (attribute) {
                    el.setAttribute(attribute, value);
                } else if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                    el.value = value;
                } else {
                    el.textContent = value;
                }
            });
        });
    }
    
    /**
     * Get nested value from object using dot notation
     * @param {object} obj - Source object
     * @param {string} path - Dot notation path (e.g., "user.name")
     * @returns {*}
     */
    function getNestedValue(obj, path) {
        return path.split('.').reduce((o, k) => (o || {})[k], obj);
    }

    // =========================================================================
    // LIST RENDERING
    // =========================================================================

    // Template cache: containerSelector → cloned template element
    const _listTemplates = {};

    /**
     * Render an array of data into a container using its first child as template.
     * Template element is identified by [data-list-template] or first child.
     * Inside the template, elements with [data-bind] get their content populated.
     * Elements with [data-bind-attr] set the specified attribute instead of textContent.
     *
     * @param {string} containerSelector - CSS selector for the container
     * @param {Array} items - Array of objects (or primitives) to render
     * @param {string} [emptyText] - Message to show when array is empty
     */
    function renderList(containerSelector, items, emptyText) {
        const container = document.querySelector(containerSelector);
        if (!container) {
            console.warn('[QS] renderList: container not found:', containerSelector);
            return;
        }

        // Ensure items is an array
        if (!Array.isArray(items)) {
            items = [items];
        }

        // Cache the template on first call (before clearing)
        if (!_listTemplates[containerSelector]) {
            const tplEl = container.querySelector('[data-list-template]') || container.firstElementChild;
            if (!tplEl) {
                console.warn('[QS] renderList: no template element found in', containerSelector);
                return;
            }
            _listTemplates[containerSelector] = tplEl.cloneNode(true);
            // Remove the data-list-template attr from clone so rendered items don't have it
            _listTemplates[containerSelector].removeAttribute('data-list-template');
        }

        const template = _listTemplates[containerSelector];

        // Clear container
        container.innerHTML = '';

        // Empty state
        if (items.length === 0) {
            if (emptyText) {
                const emptyEl = document.createElement('p');
                emptyEl.className = 'qs-list-empty';
                emptyEl.textContent = emptyText;
                container.appendChild(emptyEl);
            }
            return;
        }

        // Render each item
        items.forEach((item, index) => {
            const clone = template.cloneNode(true);
            populateTemplate(clone, item, index);
            container.appendChild(clone);
        });
    }

    /**
     * Populate a cloned template element with data from one item.
     * Finds all [data-bind] elements and sets their content.
     *
     * @param {HTMLElement} el - Cloned template root
     * @param {*} item - Data item (object or primitive)
     * @param {number} index - Item index in the array
     */
    function populateTemplate(el, item, index) {
        // If item is a primitive (string, number), wrap it
        if (typeof item !== 'object' || item === null) {
            item = { _value: item, _index: index };
        } else {
            // Add _index for convenience
            item = Object.assign({ _index: index }, item);
        }

        // Check root element itself
        if (el.hasAttribute && el.hasAttribute('data-bind')) {
            setBindValue(el, item);
        }

        // Find all [data-bind] descendants
        const boundEls = el.querySelectorAll('[data-bind]');
        boundEls.forEach(boundEl => {
            setBindValue(boundEl, item);
        });
    }

    /**
     * Set the value of a single [data-bind] element.
     * If [data-bind-attr] is present, sets that attribute.
     * Otherwise sets textContent (or value for inputs).
     *
     * @param {HTMLElement} el - Element with data-bind attribute
     * @param {object} item - Data item
     */
    function setBindValue(el, item) {
        const bindKey = el.getAttribute('data-bind');
        if (!bindKey) return;

        let value = getNestedValue(item, bindKey);
        
        // Fallback for primitive items: if the requested key doesn't exist
        // but _value does (item was a string/number), use _value instead.
        // This lets data-bind="anyName" work with string arrays.
        if ((value === undefined || value === null) && item._value !== undefined) {
            value = item._value;
        }
        
        if (value === undefined || value === null) return;

        const bindAttr = el.getAttribute('data-bind-attr');
        if (bindAttr) {
            el.setAttribute(bindAttr, String(value));
        } else if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
            el.value = String(value);
        } else if (el.tagName === 'IMG' && !bindAttr) {
            // For <img>, if no explicit bind-attr, default to src
            el.setAttribute('src', String(value));
        } else {
            el.textContent = String(value);
        }
    }

    /**
     * Render a list of items into a container using a hidden component
     * instance as the per-item template. Like renderList, but each item
     * is mapped through `fieldMap` first: each entry says where the
     * value comes from in the API item and optionally which enum table
     * to translate through.
     *
     * fieldMap shape:
     *   {
     *     "<componentVar>": { from: "<apiField>"[, enum: "<component>.<key>"] },
     *     ...
     *   }
     *
     * Enum resolution: when `spec.enum` is set, the raw value is run
     * through QS.enum(name, value, fallback). On miss, falls back to the
     * raw value so the page stays functional (QS.enum logs a console
     * warning when the table is missing).
     *
     * The template element + cloning logic is shared with renderList —
     * we just compute the per-item mapped object before calling the
     * existing populateTemplate.
     *
     * @param {string} containerSelector - CSS selector for the container
     * @param {Array}  items              - API items array
     * @param {object} fieldMap           - var → { from, enum? } map
     * @param {string} [emptyText]        - Message shown when items is empty
     */
    function renderComponentList(containerSelector, items, fieldMap, emptyText) {
        const container = document.querySelector(containerSelector);
        if (!container) {
            console.warn('[QS] renderComponentList: container not found:', containerSelector);
            return;
        }

        if (!Array.isArray(items)) items = [items];

        // Reuse renderList's template cache: same container, same
        // template-discovery rules. The cache key is the selector, so
        // a container is either a 'list' template or a 'componentList'
        // template — switching modes for the same container would
        // require clearing _listTemplates[containerSelector] first.
        if (!_listTemplates[containerSelector]) {
            const tplEl = container.querySelector('[data-list-template]') || container.firstElementChild;
            if (!tplEl) {
                console.warn('[QS] renderComponentList: no template element found in', containerSelector);
                return;
            }
            const cached = tplEl.cloneNode(true);
            cached.removeAttribute('data-list-template');
            // The hidden template usually carries `style="display:none"`
            // so it doesn't show before fetch. Drop that inline style on
            // the clone — rendered items must be visible. If the author
            // composed more styles into the inline style, only `display:
            // none` is removed; the rest stays.
            if (cached.style && cached.style.display === 'none') {
                cached.style.removeProperty('display');
                // If style attribute is now empty, drop it entirely
                // so the rendered HTML stays clean.
                if (cached.getAttribute('style') === '') {
                    cached.removeAttribute('style');
                }
            }
            _listTemplates[containerSelector] = cached;
        }

        const template = _listTemplates[containerSelector];

        // Clear container
        container.innerHTML = '';

        // Empty state
        if (items.length === 0) {
            if (emptyText) {
                const emptyEl = document.createElement('p');
                emptyEl.className = 'qs-list-empty';
                emptyEl.textContent = emptyText;
                container.appendChild(emptyEl);
            }
            return;
        }

        // Per-item: walk fieldMap → build mapped object → clone +
        // populate. populateTemplate already handles [data-bind] /
        // [data-bind-attr] in the cloned template, so the per-item
        // mapped object can use any keys the template expects.
        const mapEntries = Object.keys(fieldMap || {});
        items.forEach((item, index) => {
            const mapped = { _index: index };
            mapEntries.forEach(targetVar => {
                const spec = fieldMap[targetVar];
                if (!spec || typeof spec !== 'object') return;
                const fromKey = spec.from || targetVar;
                const rawValue = getNestedValue(item, fromKey);
                if (spec.enum) {
                    mapped[targetVar] = QS.enum(spec.enum, rawValue, rawValue);
                } else {
                    mapped[targetVar] = rawValue;
                }
            });

            const clone = template.cloneNode(true);
            populateTemplate(clone, mapped, index);
            container.appendChild(clone);
        });
    }

    // =========================================================================
    // COUNT RENDERING
    // =========================================================================

    /**
     * Compute a numeric count from an arbitrary value.
     *   - Array       → array.length
     *   - null / undefined / falsy (0, '', false) → fallback
     *   - truthy non-array (object / scalar)      → 1
     *
     * `fallback` defaults to 0. Used by both renderCount (binding mode)
     * and any future direct callers.
     */
    function computeCount(value, fallback) {
        var fb = (fallback === undefined || fallback === null) ? 0 : Number(fallback);
        if (Number.isNaN(fb)) fb = 0;
        if (Array.isArray(value)) return value.length;
        if (value === null || value === undefined) return fb;
        if (value === 0 || value === '' || value === false) return fb;
        return 1;
    }

    /**
     * Apply a count-mode response binding.
     *
     * Binding shape (from qs-api-config.js):
     *   {
     *     field:      "data.commands",          // resolved by caller, passed as `value`
     *     renderMode: "count",
     *     selector:   "#cmd-counter",
     *     fallback:   0,                         // optional
     *     // sentence form (added by writeCompiledJs from KEYS in the
     *     // saved binding, translated to STRINGS at compile time):
     *     format:    "sentence",
     *     zeroStr:   "No commands",
     *     oneStr:    "1 command",
     *     manyStr:   "{n} commands"
     *   }
     *
     * Without `format`, the count number is written verbatim.
     * With `format === "sentence"`, picks zeroStr/oneStr/manyStr per
     * count, substitutes `{n}` with the actual number.
     *
     * Sentence strings are already in the user's language — server
     * translation happens at qs-api-config.js write time. Bindings on
     * multi-language sites currently freeze in whatever language was
     * active when writeCompiledJs ran (filed in BACKLOG.md).
     */
    function renderCount(value, binding) {
        var selector = binding.selector;
        if (!selector) {
            console.warn('[QS] count: missing `selector` in binding for field:', binding.field);
            return;
        }
        var elements = getElements(selector);
        if (elements.length === 0) {
            console.warn('[QS] count: selector matched no elements:', selector);
            return;
        }

        var n = computeCount(value, binding.fallback);
        var output;

        if (binding.format === 'sentence') {
            var template;
            if (n === 0)      template = binding.zeroStr;
            else if (n === 1) template = binding.oneStr;
            else              template = binding.manyStr;

            if (typeof template !== 'string' || template === '') {
                // Sentence mode declared but the matching string is
                // missing — fall back to the raw number so the page
                // still shows something useful.
                console.warn('[QS] count[sentence]: missing template for n=' + n,
                    '(zero/one/many strings:', binding.zeroStr, binding.oneStr, binding.manyStr, ')');
                output = String(n);
            } else {
                output = template.replace(/\{n\}/g, String(n));
            }
        } else {
            output = String(n);
        }

        elements.forEach(function(el) {
            el.textContent = output;
        });
    }

    // =========================================================================
    // ENUM REGISTRY (`window.QS_ENUMS` populated by qs-enums.js)
    // =========================================================================

    /**
     * Resolve a value through a global enum table.
     *
     * The registry (`window.QS_ENUMS`) is generated by the PHP
     * `EnumSyncHelper` from each component's `__enums__` block. Each
     * entry is a flat `{ key: value }` map. Names are fully-qualified
     * (e.g. `component-command-card.method_text`).
     *
     * Forgiving by design:
     *  - missing table          → warn + return fallback (or value).
     *  - value not in table     → return fallback (or value).
     *
     * Used by renderComponentList; also callable from interaction
     * chains via {{call:setEnumValue:...}} once that verb is wired.
     *
     * @param {string} name      - Fully-qualified enum name
     * @param {*}      value     - Lookup key
     * @param {*}      [fallback] - Value to return on miss (default: `value`)
     */
    QS.enum = function(name, value, fallback) {
        const table = (window.QS_ENUMS || {})[name];
        if (!table) {
            console.warn('[QS] enum table not found:', name,
                '(is qs-enums.js loaded? does any binding reference this name?)');
            return fallback !== undefined ? fallback : value;
        }
        return Object.prototype.hasOwnProperty.call(table, value)
            ? table[value]
            : (fallback !== undefined ? fallback : value);
    };

    // Expose applyBindings publicly so a caller with raw response data
    // can apply a binding spec without going through QS.fetch. Useful
    // for: testing, pre-cached data scenarios, manual rebinding after
    // a DOM mutation.
    QS.applyBindings = applyBindings;

    // Expose renderList publicly so it can also be called directly via {{call:renderList:...}}
    QS.renderList = function(containerSelector, dataOrField, emptyText) {
        // If second arg is a string, try to resolve from last fetch result
        if (typeof dataOrField === 'string') {
            const data = QS._lastFetchResult || {};
            const items = getNestedValue(data, dataOrField);
            renderList(containerSelector, items || [], emptyText);
        } else {
            renderList(containerSelector, dataOrField || [], emptyText);
        }
    };

    // Store last fetch result for direct renderList calls
    QS._lastFetchResult = null;

    // =========================================================================
    // AUTH TOKEN PERSISTENCE  (BETA7_AUTH_FLOWS Tier 1)
    // =========================================================================
    // Read a value from QS._lastFetchResult (last successful fetch's data)
    // and stash it in localStorage / sessionStorage, so subsequent
    // QS.fetch calls pick it up via the endpoint's auth.tokenSource
    // config. Pairs with QS.clearToken for logout flows.

    /**
     * Save a value from the last fetch's response into browser storage.
     *
     * @param {string} storage  'localStorage' or 'sessionStorage'
     * @param {string} key       Storage key (e.g. 'authToken')
     * @param {string} path      Dot-notation path into the fetch result
     *                           (e.g. 'token', 'data.access_token')
     *
     * Fires `qs:auth:saved` CustomEvent on document with
     *   detail: { storage, key, tokenKey: key, value }
     * so cross-page UI (login-state badges, etc.) can re-render.
     *
     * Forgiving by design: invalid storage type, missing fetch result,
     * empty path resolution, or storage write failure → console.warn +
     * no-op. Doesn't throw, so an auth-save step in a chain doesn't
     * abort the rest of the chain.
     */
    QS.saveToken = function(storage, key, path) {
        if (storage !== 'localStorage' && storage !== 'sessionStorage') {
            console.warn('[QS] saveToken: invalid storage type:', storage,
                '(must be localStorage or sessionStorage)');
            return;
        }
        if (typeof key !== 'string' || key === '') {
            console.warn('[QS] saveToken: key is required');
            return;
        }
        if (typeof path !== 'string' || path === '') {
            console.warn('[QS] saveToken: path is required (e.g. "token" or "data.access_token")');
            return;
        }
        var src = QS._lastFetchResult;
        if (src == null) {
            console.warn('[QS] saveToken: no fetch result to read from (call after a QS.fetch)');
            return;
        }
        var value = getNestedValue(src, path);
        if (value === undefined || value === null || value === '') {
            console.warn('[QS] saveToken: path resolved to empty value:', path);
            return;
        }
        if (!setStoredToken(storage, key, String(value))) {
            console.warn('[QS] saveToken: storage write failed');
        }
    };

    /**
     * Generic storage write — the non-auth sibling of saveToken. Reads a value
     * from the last fetch result (QS._lastFetchResult) via `path` and stashes it
     * in localStorage / sessionStorage, firing `qs:storage:changed` so
     * data-storage-* bindings re-render. Use for non-token values (preferences,
     * saved drafts, …) that the storage registry tracks. Forgiving by design:
     * warn + no-op on bad input, never throws (won't abort a chain).
     *
     * @param {string} storage  'localStorage' or 'sessionStorage'
     * @param {string} key       Storage key name (declared in /admin/storage)
     * @param {string} path      Dot-path into QS._lastFetchResult
     */
    QS.store = function(storage, key, path) {
        if (storage !== 'localStorage' && storage !== 'sessionStorage') {
            console.warn('[QS] store: invalid storage type:', storage,
                '(must be localStorage or sessionStorage)');
            return;
        }
        if (typeof key !== 'string' || key === '') {
            console.warn('[QS] store: key is required');
            return;
        }
        if (typeof path !== 'string' || path === '') {
            console.warn('[QS] store: path is required (e.g. "data.preference")');
            return;
        }
        var src = QS._lastFetchResult;
        if (src == null) {
            console.warn('[QS] store: no fetch result to read from (call after a QS.fetch)');
            return;
        }
        var value = getNestedValue(src, path);
        if (value === undefined || value === null || value === '') {
            console.warn('[QS] store: path resolved to empty value:', path);
            return;
        }
        if (!_consentAllowsWrite(key)) return;   // consent-gated: skip the write + event
        try {
            window[storage].setItem(key, String(value));
        } catch (e) {
            console.warn('[QS] store: storage write failed');
            return;
        }
        document.dispatchEvent(new CustomEvent('qs:storage:changed', {
            detail: { storage: storage, key: key, value: String(value) }
        }));
    };

    /**
     * Remove a stored token from localStorage / sessionStorage. Fires
     * `qs:auth:cleared` on document with detail.{ storage, key, tokenKey }.
     *
     * @param {string} storage  'localStorage' or 'sessionStorage'
     * @param {string} key       Storage key to remove
     */
    QS.clearToken = function(storage, key) {
        if (storage !== 'localStorage' && storage !== 'sessionStorage') {
            console.warn('[QS] clearToken: invalid storage type:', storage);
            return;
        }
        if (typeof key !== 'string' || key === '') {
            console.warn('[QS] clearToken: key is required');
            return;
        }
        try {
            window[storage].removeItem(key);
        } catch (e) {
            console.warn('[QS] clearToken: storage write failed:', e);
            return;
        }
        document.dispatchEvent(new CustomEvent('qs:auth:cleared', {
            detail: { storage: storage, key: key, tokenKey: key }
        }));
    };

    // =========================================================================
    // MAGIC-LINK EXCHANGE  (beta.8 A3 — Tier 3 auth)
    // =========================================================================
    /**
     * Exchange a single-use magic-link code for a real session token.
     *
     * Magic-link sign-in lands the user on a route like /auth/magic/:key.
     * The URL value is a SINGLE-USE CODE (not the session token itself).
     * This verb POSTs the captured code to the configured exchange
     * endpoint, stores the response in QS._lastFetchResult so chained
     * saveToken / saveToken calls pick up the returned token +
     * refreshToken, then optionally redirects.
     *
     * Why a code, not the token directly: a token-in-URL leaks via email
     * forwarding, browser history, corporate HTTPS proxies, and email-
     * client link prefetchers. The code is single-use (server marks it
     * USED on exchange) and short-lived. See BETA8_AUTH_TIER_3.md.
     *
     * @param {string} endpointRef Registry ref like '@auth-api/exchange-magic'.
     *                             The endpoint should be configured with
     *                             auth.type='none' — the exchange itself is
     *                             the login. (Auth injection is not applied
     *                             by this verb; for authed exchange endpoints
     *                             use raw QS.fetch.)
     * @param {string} paramName   Name of the route :name segment that holds
     *                             the code (e.g. 'key' for /auth/magic/:key).
     *                             The verb reads QS.routeParams[paramName].
     * @param {string} [returnTo]  Optional URL to navigate to after the
     *                             exchange succeeds. When OMITTED, falls
     *                             back to the ?return= query param. With
     *                             neither, no auto-redirect — chain an
     *                             explicit {{call:redirect:/path}} step
     *                             instead. WHEN PROVIDED, browser
     *                             navigation is queued immediately after
     *                             the fetch resolves; any chained
     *                             synchronous saveToken calls still run
     *                             before navigation actually happens
     *                             (saveToken is sync, runs in the same
     *                             microtask before the browser processes
     *                             the queued navigation).
     *
     * Returns a Promise resolving to the API response on success, or
     * undefined on failure (console.warn surfaces the reason). The verb
     * is registered as CHAIN_AWAITABLE in JsonToHtmlRenderer.php so
     * `{{call:exchangeMagicLink:…}};{{call:saveToken:…}}` chains await
     * the exchange before saveToken reads QS._lastFetchResult.
     *
     * Typical chain:
     *   {{call:exchangeMagicLink:@auth-api/exchange-magic,key}};
     *   {{call:saveToken:localStorage,authToken,token}};
     *   {{call:saveToken:localStorage,refreshToken,refreshToken}};
     *   {{call:redirect:/dashboard}}
     */
    QS.exchangeMagicLink = function(endpointRef, paramName, returnTo) {
        if (typeof endpointRef !== 'string' || endpointRef === '') {
            console.warn('[QS] exchangeMagicLink: endpoint is required (e.g. @auth-api/exchange-magic)');
            return Promise.resolve();
        }
        if (typeof paramName !== 'string' || paramName === '') {
            console.warn('[QS] exchangeMagicLink: paramName is required (the route :name segment that holds the code)');
            return Promise.resolve();
        }

        var code = QS.routeParams ? QS.routeParams[paramName] : undefined;
        if (code === undefined || code === null || code === '') {
            console.warn('[QS] exchangeMagicLink: route param "' + paramName + '" is empty — not on a /…/:' + paramName + ' page?');
            return Promise.resolve();
        }

        var resolved = resolveEndpoint(endpointRef.replace(/^@/, ''));
        if (!resolved) {
            console.warn('[QS] exchangeMagicLink: endpoint not found in registry:', endpointRef);
            return Promise.resolve();
        }

        // Beta.8 A3 — dispatch the 'started' lifecycle event before the
        // fetch fires. Drives data-auth-show="connecting" visibility in
        // the magic-link-handler component. Subsequent qs:auth:saved (from
        // chained saveToken) or qs:auth:exchange-failed (from the catch
        // below) flips the cursor away from 'connecting'.
        document.dispatchEvent(new CustomEvent('qs:auth:exchange-started', {
            detail: { endpoint: endpointRef, paramName: paramName, code: code }
        }));

        // Exchange is always POST with a JSON body — the endpoint's
        // configured method is intentionally ignored here. Browsers
        // refuse to attach a body to GET, so honouring an accidentally-
        // GET endpoint config would silently drop the {key} payload and
        // the exchange would 405. Hardcoding POST surfaces a clear
        // server-side method-not-allowed if the endpoint really is
        // GET-only on the user's auth API (a real misconfig worth seeing).
        return fetch(resolved.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: code })
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json().catch(function() { return {}; });
            })
            .then(function(data) {
                QS._lastFetchResult = data;
                // Resolve returnTo: explicit arg → ?return= query → null.
                var target = (returnTo !== undefined && returnTo !== null && returnTo !== '') ? returnTo : null;
                if (!target) {
                    var queryReturn = new URLSearchParams(location.search).get('return');
                    if (queryReturn) target = queryReturn;
                }
                if (target) QS.redirect(target);
                return data;
            })
            .catch(function(err) {
                console.warn('[QS] exchangeMagicLink: exchange failed:', err);
                // Leave QS._lastFetchResult untouched so chained saveToken
                // doesn't pick up stale data from an earlier fetch.
                // Beta.8 A3 — dispatch the 'failed' lifecycle event so the
                // magic-link-handler component's data-auth-show="failed"
                // element becomes visible. Cleared on the next qs:auth:saved
                // (retry succeeded) or qs:auth:cleared (logout).
                document.dispatchEvent(new CustomEvent('qs:auth:exchange-failed', {
                    detail: { endpoint: endpointRef, paramName: paramName, error: err }
                }));
            });
    };

    /**
     * Request a magic-link to be issued to an email address. The forward
     * path of magic-link auth — pairs with QS.exchangeMagicLink (which
     * runs on the landing page after the user clicks the email link).
     *
     * @param {string} endpointRef Registry ref of the issue-magic endpoint,
     *                             e.g. '@auth-api/issue-magic'. Usually
     *                             configured with auth.type='none' (this
     *                             call runs BEFORE the user has a session).
     * @param {string} email       Either a literal email address ('a@b.com')
     *                             or a CSS selector ('#email-input',
     *                             '.email-field') pointing to an <input>.
     *                             The verb reads .value from the matched
     *                             element. Mirrors the selector convention
     *                             used by setState's value arg + QS.fetch's
     *                             body= option.
     * @param {string} [returnTo]  Optional URL to navigate to after the
     *                             request succeeds — typically a "check
     *                             your email" page.
     *
     * Returns a Promise resolving to the API response on success, or
     * undefined on failure (console.warn surfaces the reason). The
     * server-side endpoint MUST NOT reveal whether the email exists in
     * its user store — the standard pattern is "always return 200 even
     * when the email is unknown" to avoid an account-enumeration oracle.
     * That's the AUTH API's responsibility; the verb just POSTs.
     */
    QS.requestMagicLink = function(endpointRef, email, returnTo) {
        if (typeof endpointRef !== 'string' || endpointRef === '') {
            console.warn('[QS] requestMagicLink: endpoint is required (e.g. @auth-api/issue-magic)');
            return Promise.resolve();
        }
        if (typeof email !== 'string' || email === '') {
            console.warn('[QS] requestMagicLink: email is required (literal address or #selector / .selector)');
            return Promise.resolve();
        }

        // Resolve selector → input value, or use literal.
        var emailValue = email;
        if (email.charAt(0) === '#' || email.charAt(0) === '.') {
            var el = document.querySelector(email);
            if (!el) {
                console.warn('[QS] requestMagicLink: selector not found:', email);
                return Promise.resolve();
            }
            emailValue = (typeof el.value === 'string') ? el.value : (el.textContent || '');
            if (!emailValue) {
                console.warn('[QS] requestMagicLink: selector resolved to empty value:', email);
                return Promise.resolve();
            }
        }

        var resolved = resolveEndpoint(endpointRef.replace(/^@/, ''));
        if (!resolved) {
            console.warn('[QS] requestMagicLink: endpoint not found in registry:', endpointRef);
            return Promise.resolve();
        }

        return fetch(resolved.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: emailValue })
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json().catch(function() { return {}; });
            })
            .then(function(data) {
                QS._lastFetchResult = data;
                var target = (returnTo !== undefined && returnTo !== null && returnTo !== '') ? returnTo : null;
                if (target) QS.redirect(target);
                return data;
            })
            .catch(function(err) {
                console.warn('[QS] requestMagicLink: request failed:', err);
            });
    };

    /**
     * Server-side logout — invalidates the session on the auth API. The
     * Tier 1 logout pair (clearToken removes the client token; this verb
     * tells the server to forget the session / revoke the refresh token).
     * Both should chain together for a clean logout.
     *
     * Thin wrapper around QS.fetch so the registry's auth (bearer token,
     * cookie credentials) is applied automatically — the server needs to
     * identify which session is being invalidated. Configure the endpoint
     * as POST in /admin/apis; auth inherits the API-level setting (usually
     * bearer with auth.tokenSource pointing at the same key clearToken
     * will remove afterwards).
     *
     * @param {string} endpointRef Registry ref like '@auth-api/logout'.
     * @returns {Promise} QS.fetch's Promise (resolves on success or HTTP
     *                    error). Failures are logged by QS.fetch's own
     *                    error path — this verb intentionally doesn't
     *                    surface them, because logout should "try" but
     *                    always allow the chained clearToken + redirect
     *                    to proceed (the user wants out either way).
     */
    QS.logoutServer = function(endpointRef) {
        if (typeof endpointRef !== 'string' || endpointRef === '') {
            console.warn('[QS] logoutServer: endpoint is required (e.g. @auth-api/logout)');
            return Promise.resolve();
        }
        // Always resolve, even when QS.fetch rejects. The chained clearToken
        // + redirect MUST run regardless of whether the server-side logout
        // call succeeded — the user wants out either way, and a stuck
        // session on the server is recoverable on next login while a stuck
        // local token is not. Direct callers (devtools console) also
        // benefit: no UnhandledPromiseRejection noise when the endpoint
        // doesn't exist yet during initial wiring.
        return QS.fetch(endpointRef).catch(function (err) {
            console.warn('[QS] logoutServer: server logout failed (proceeding with local clearToken):', err);
        });
    };

    /**
     * Custom error thrown by QS.validate to abort the action chain
     * (e.g. {{call:validate:event,#form}};{{call:fetch:...}} - the
     * fetch must NOT run if validation failed). Inline event handlers
     * naturally stop on the first thrown error, so throwing aborts
     * the rest of the {{call:...}} chain without extra plumbing.
     */
    function QSValidationError(message) {
        const err = new Error(message || 'QS.validate: form invalid');
        err.name = 'QSValidationError';
        return err;
    }

    /**
     * Validate a form using HTML5 Constraint Validation API.
     *
     * For each input/textarea/select inside formSelector:
     *   - Calls element.checkValidity().
     *   - If invalid, writes element.validationMessage into a sibling
     *     [data-error-for="<name>"] container (or descendant of the
     *     form with that attribute).
     *   - If valid, clears the matching [data-error-for] container.
     *
     * When called with an event (e.g. `{{call:validate:event,#form}}` on
     * onsubmit), `event.preventDefault()` fires UPFRONT — regardless of
     * the validation outcome. Reasoning: handing us the event signals
     * "I'm handling this submission programmatically, don't let HTML
     * also submit the form natively". Without this, the form's native
     * submission races with any subsequent {{call:fetch:...}} in the
     * chain — page navigates before the fetch completes, leaving an
     * "NetworkError when attempting to fetch resource" in the console.
     *
     * If you want native submission to still happen on valid forms, do
     * NOT pass the event — call validate with just the selector:
     * `{{call:validate:#form}}`.
     *
     * On any invalid field:
     *   - Throws QSValidationError, aborting the rest of the
     *     {{call:...}} chain. (preventDefault already done.)
     *
     * Usage in JSON:
     *   "onsubmit": "{{call:validate:event,#contact-form}};{{call:fetch:POST,/api/contact,body=#contact-form}}"
     *
     * @param {Event|string} eventOrFormSelector - Event object OR form CSS selector.
     * @param {string} [formSelectorIfEvent] - Form CSS selector when first arg is an event.
     * @returns {boolean} true if all fields valid (chain continues).
     */
    QS.validate = function(eventOrFormSelector, formSelectorIfEvent) {
        let evt = null;
        let formSelector = eventOrFormSelector;

        // Polymorphic first arg, mirroring QS.filter: event-or-selector.
        if (eventOrFormSelector && typeof eventOrFormSelector === 'object'
            && typeof eventOrFormSelector.preventDefault === 'function') {
            evt = eventOrFormSelector;
            formSelector = formSelectorIfEvent;
        }

        // Take over the submit upfront — see jsdoc above for the why.
        // Must happen BEFORE the misconfig early-return paths, otherwise
        // a typo in formSelector would silently let the form submit
        // natively while we returned true.
        if (evt) {
            evt.preventDefault();
        }

        if (!formSelector || typeof formSelector !== 'string') {
            console.warn('[QS] validate: formSelector required');
            return true; // No-op rather than abort if misconfigured.
        }

        const form = document.querySelector(formSelector);
        if (!form) {
            console.warn('[QS] validate: form not found:', formSelector);
            return true;
        }

        const fields = form.querySelectorAll('input, textarea, select');
        let allValid = true;

        fields.forEach(field => {
            // Skip non-validatable fields (submit/reset/button etc. via willValidate).
            if (field.willValidate === false) return;

            const isValid = field.checkValidity();
            const name = field.getAttribute('name');
            // Look for the error container by name first, then by id as fallback.
            let errBox = null;
            if (name) {
                errBox = form.querySelector('[data-error-for="' + name + '"]');
            }
            if (!errBox && field.id) {
                errBox = form.querySelector('[data-error-for="' + field.id + '"]');
            }

            if (isValid) {
                if (errBox) errBox.textContent = '';
            } else {
                allValid = false;
                if (errBox) errBox.textContent = field.validationMessage || 'Invalid';
            }
        });

        if (!allValid) {
            throw QSValidationError();
        }

        return true;
    };

    // =========================================================================
    // AUTH STATE (UI helpers)
    // =========================================================================
    // Declarative reactions to login state, building on the Tier 1/2 token
    // verbs. Everything re-applies on load and whenever qs:auth:saved /
    // qs:auth:cleared fire (saveToken / clearToken / refresh all emit those).

    /**
     * Is there a non-empty value at the given storage source? Shared presence
     * check behind QS.isAuthed and the data-*-show bindings.
     * @param {string} source "localStorage:key" / "sessionStorage:key"
     * @returns {boolean}
     */
    function _hasValue(source) {
        const v = getTokenValue(source);
        return v !== null && v !== undefined && v !== '';
    }

    /**
     * Is there a non-empty token at the given storage source? Auth-facing
     * alias of _hasValue.
     * @param {string} source "localStorage:key" / "sessionStorage:key"
     * @returns {boolean}
     */
    QS.isAuthed = function(source) {
        return _hasValue(source);
    };

    /**
     * Manually trigger a token refresh for an API, reusing the Tier 2 flow.
     * Handy for a "Refresh" button. Resolves true if a new token was stored;
     * fires qs:auth:saved on success (so auth-state bindings re-render).
     * @param {string} apiRef "@apiId" (or "@apiId/endpointId")
     * @returns {Promise<boolean>}
     */
    QS.refresh = function(apiRef) {
        const ref = String(apiRef || '').replace(/^@/, '');
        const apiId = ref.split('/')[0];
        const registry = window.QS_API_ENDPOINTS || {};
        const api = registry[apiId];
        if (!api || !api.auth || !api.auth.refreshEndpoint) {
            console.warn('[QS] refresh: no refresh config for', apiRef);
            return Promise.resolve(false);
        }
        return refreshAuthToken(api.auth);
    };

    /**
     * Resolve the storage source for an auth-state element: its own
     * data-auth-source, else the nearest ancestor's, else null.
     */
    function _resolveAuthSource(el) {
        let node = el;
        while (node && node.nodeType === 1) {
            const s = node.getAttribute('data-auth-source');
            if (s) return s;
            node = node.parentElement;
        }
        return null;
    }

    // Transient exchange-state cursor — set by qs:auth:exchange-started and
    // qs:auth:exchange-failed (dispatched by QS.exchangeMagicLink in beta.8
    // A3). Drives the 'connecting' / 'failed' modes of data-auth-show. Stays
    // in-memory only — refresh resets to null so a fresh page always shows
    // the appropriate state from the verb's lifecycle, not a stale phase.
    //   null         → no exchange in flight; 'connecting' / 'failed'
    //                  elements hidden; 'in' / 'out' fall through to the
    //                  token-presence check.
    //   'connecting' → between qs:auth:exchange-started and the next
    //                  qs:auth:saved (success) or qs:auth:exchange-failed.
    //   'failed'     → the verb's catch fired; stays until qs:auth:saved
    //                  (retry succeeded) or qs:auth:cleared (logout).
    var _exchangeState = null;

    /**
     * Apply declarative auth/storage-state bindings across the document:
     *   data-auth-show="in" | "out"          → show by token presence (Tier 1)
     *   data-auth-show="connecting"          → show while a magic-link / OAuth
     *                                          exchange is in flight (beta.8
     *                                          A3 Tier 3)
     *   data-auth-show="failed"              → show after the exchange's catch
     *                                          fires; cleared on next success
     *                                          or explicit clearToken
     *   data-storage-show="has:loc:key"      → show when a storage key is present
     *   data-storage-show="missing:loc:key"  → show when it is absent
     *   data-storage-value="loc:key"         → element text = the stored value
     * data-auth-show resolves its source from data-auth-source on the element
     * or any ancestor (set it once on a wrapper); the data-storage-* attrs
     * carry their source inline. Plain data-* namespace (NOT data-qs-*, which
     * the editor reserves and strips) so these stay user-authorable.
     * Re-applies on load + qs:auth:saved / qs:auth:cleared / qs:auth:exchange-*.
     * Exposed as QS.applyAuthState to re-scan after injecting DOM or a
     * non-auth store.
     */
    function applyAuthState() {
        // Auth sugar: login-state + exchange-state show/hide. 'in' / 'out'
        // need a data-auth-source (the token-presence check). 'connecting' /
        // 'failed' don't — they read the in-memory _exchangeState cursor.
        document.querySelectorAll('[data-auth-show]').forEach(function (el) {
            const mode = el.getAttribute('data-auth-show');
            if (mode === 'connecting') {
                el.style.display = (_exchangeState === 'connecting') ? '' : 'none';
                return;
            }
            if (mode === 'failed') {
                el.style.display = (_exchangeState === 'failed') ? '' : 'none';
                return;
            }
            if (mode !== 'in' && mode !== 'out') return;
            const source = _resolveAuthSource(el);
            if (!source) {
                console.warn('[QS] data-auth-show: no data-auth-source on element or ancestors', el);
                return;
            }
            const present = _hasValue(source);
            el.style.display = ((mode === 'in') ? present : !present) ? '' : 'none';
        });

        // Generic presence show/hide: "has:<source>" / "missing:<source>".
        document.querySelectorAll('[data-storage-show]').forEach(function (el) {
            const spec = el.getAttribute('data-storage-show') || '';
            const idx = spec.indexOf(':');
            const mode = idx === -1 ? '' : spec.slice(0, idx);
            const source = idx === -1 ? '' : spec.slice(idx + 1);
            if ((mode !== 'has' && mode !== 'missing') || !source) {
                console.warn('[QS] data-storage-show: expected "has:loc:key" or "missing:loc:key", got', spec);
                return;
            }
            const present = _hasValue(source);
            el.style.display = ((mode === 'has') ? present : !present) ? '' : 'none';
        });

        // Consent-gated visibility: "granted:<category>" / "denied:<category>".
        // Sibling to data-auth-show — shows an element only when the visitor has
        // (or hasn't) consented to a category. For inline "enable analytics to
        // view" placeholders. Independent of whether the layer is enabled
        // (hasConsent is essential→true / else cookie-driven).
        document.querySelectorAll('[data-consent-show]').forEach(function (el) {
            const spec = el.getAttribute('data-consent-show') || '';
            const idx = spec.indexOf(':');
            const mode = idx === -1 ? '' : spec.slice(0, idx);
            const category = idx === -1 ? '' : spec.slice(idx + 1);
            if ((mode !== 'granted' && mode !== 'denied') || !category) {
                console.warn('[QS] data-consent-show: expected "granted:category" or "denied:category", got', spec);
                return;
            }
            const granted = QS.hasConsent(category);
            el.style.display = ((mode === 'granted') ? granted : !granted) ? '' : 'none';
        });

        // Generic value display: element text = the stored value.
        document.querySelectorAll('[data-storage-value]').forEach(function (el) {
            const v = getTokenValue(el.getAttribute('data-storage-value'));
            el.textContent = (v === null || v === undefined) ? '' : v;
        });
    }
    QS.applyAuthState = applyAuthState;

    // qs:auth:saved → exchange (if any) succeeded OR a regular login completed;
    // either way the 'connecting' / 'failed' states no longer apply. Clearing
    // the cursor lets the welcome message ('in') show without the connecting
    // spinner lingering.
    document.addEventListener('qs:auth:saved', function () {
        _exchangeState = null;
        applyAuthState();
    });
    // qs:auth:cleared → explicit logout; reset any lingering exchange state
    // so the user sees a clean logged-out UI on the next render.
    document.addEventListener('qs:auth:cleared', function () {
        _exchangeState = null;
        applyAuthState();
    });
    // qs:storage:changed → a non-auth QS.store write; re-scan data-storage-*
    // bindings so they reflect the new value (the auth events already do this).
    document.addEventListener('qs:storage:changed', function () {
        applyAuthState();
    });
    // qs:consent:changed → the visitor updated consent; re-scan data-consent-show
    // bindings so gated placeholders reflect the new choice.
    document.addEventListener('qs:consent:changed', function () {
        applyAuthState();
    });
    // Beta.8 A3 — exchange lifecycle events dispatched by
    // QS.exchangeMagicLink. The cursor drives 'connecting' / 'failed'
    // visibility; the welcome path goes via qs:auth:saved (above) which
    // also clears the cursor before applyAuthState runs.
    document.addEventListener('qs:auth:exchange-started', function () {
        _exchangeState = 'connecting';
        applyAuthState();
    });
    document.addEventListener('qs:auth:exchange-failed', function () {
        _exchangeState = 'failed';
        applyAuthState();
    });
    function _qsAuthAndConsentInit() {
        applyAuthState();
        _initConsentBanner();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _qsAuthAndConsentInit);
    } else {
        _qsAuthAndConsentInit();
    }

    // =========================================================================
    // STATE STORES (stateful, endpoint-bound interaction state)
    // =========================================================================
    // Per-page definitions arrive in window.QS_STATE_STORES (emitted by the
    // page render). Each store:
    //   { endpoint:"@api/ep", fetchOnLoad?:bool,
    //     fields: { <name>: { dir, init?, default?, from?, append? } } }
    //   dir    : "request" (sent) | "response" (received) | "both"
    //   init   : initial value for sent fields — a literal, or
    //            "query:x" / "param:x" / "localStorage:x" / "sessionStorage:x"
    //   default: fallback when init's source is missing
    //   from   : response dot-path for received fields
    //   append : received list fields append instead of replace
    // Live values live in QS._stores[id].state. Runtime-agnostic by design:
    // beta.8's server data-resolver will read the same definition shape.

    QS._stores = {};

    // Resolve a field's initial value from its init source (or the default).
    function _resolveInit(init, def) {
        var fallback = (def !== undefined ? def : null);
        if (init === undefined || init === null) return fallback;
        if (typeof init === 'string') {
            var ci = init.indexOf(':');
            if (ci !== -1) {
                var prefix = init.slice(0, ci);
                var key = init.slice(ci + 1);
                if (prefix === 'query') {
                    var qv = new URLSearchParams(location.search).get(key);
                    return (qv === null || qv === '') ? fallback : qv;
                }
                if (prefix === 'param') {
                    var pv = QS.routeParams ? QS.routeParams[key] : undefined;
                    return (pv === undefined || pv === null || pv === '') ? fallback : pv;
                }
                if (prefix === 'localStorage' || prefix === 'sessionStorage') {
                    var sv = window[prefix].getItem(key);
                    return (sv === null || sv === '') ? fallback : sv;
                }
            }
        }
        return init; // literal
    }

    // WeakSet of <nav data-state-pagenav> elements that have already had
    // their delegated click listener attached. Used by the paged-navigator
    // block inside _renderStore to avoid re-binding on every re-render
    // (every store update calls _renderStore, which rebuilds the nav's
    // children — but the parent listener is on the <nav> itself, not the
    // buttons, so it survives the rebuild).
    var _pageNavBound = new WeakSet();

    // DOM ← store: set the text of [data-state-value="storeId.field"] elements.
    function _renderStore(storeId) {
        var store = QS._stores[storeId];
        if (!store) return;
        document.querySelectorAll('[data-state-value]').forEach(function (el) {
            var ref = el.getAttribute('data-state-value') || '';
            var dot = ref.indexOf('.');
            if (dot === -1 || ref.slice(0, dot) !== storeId) return;
            var v = store.state[ref.slice(dot + 1)];
            el.textContent = (v === null || v === undefined) ? ''
                : (typeof v === 'object' ? JSON.stringify(v) : String(v));
        });
        // List fields: a [data-state-list="storeId.field"] container renders the
        // field's array via renderList (template = its first child /
        // [data-list-template]). store.state holds the FULL (appended) array, so
        // re-rendering the whole array covers both replace (offset) and append
        // (infinite) modes. Optional data-state-empty = empty-state text.
        document.querySelectorAll('[data-state-list]').forEach(function (el) {
            var ref = el.getAttribute('data-state-list') || '';
            var dot = ref.indexOf('.');
            if (dot === -1 || ref.slice(0, dot) !== storeId) return;
            var arr = store.state[ref.slice(dot + 1)];
            renderList('[data-state-list="' + ref + '"]',
                Array.isArray(arr) ? arr : (arr === undefined || arr === null ? [] : [arr]),
                el.getAttribute('data-state-empty') || undefined);
        });
        // Conditional visibility: [data-state-show="storeId.field"] shows the
        // element when the field is truthy, hides it otherwise. Falsy = null,
        // undefined, '', 0, false, or [] (empty array). Toggles the standard
        // `hidden` attribute (CSS-overridable). Typical use:
        //   <button data-state-show="people.nextPage">Next</button>  (hides at last page)
        //   <p data-state-show="people.total">Found <span ...>total</span></p>
        document.querySelectorAll('[data-state-show]').forEach(function (el) {
            var ref = el.getAttribute('data-state-show') || '';
            var dot = ref.indexOf('.');
            if (dot === -1 || ref.slice(0, dot) !== storeId) return;
            var v = store.state[ref.slice(dot + 1)];
            var truthy = !(v === null || v === undefined || v === '' || v === 0 || v === false
                || (Array.isArray(v) && v.length === 0));
            el.hidden = !truthy;
        });

        // Beta.8 A2 Slice 6 — inverse of data-state-show: visible when
        // the referenced state field is FALSY (null / undefined / '' / 0 /
        // false / empty array). Used by routes with the resolver's
        // `onMiss: 'render-empty'` config so the template can carry a
        // 'No data found' fallback alongside its happy-path content:
        //   <div data-state-show="product.name"> <!-- happy path --> </div>
        //   <div data-state-show-empty="product.name">Sorry, not found.</div>
        // Also generally useful for client-side stores anytime an "empty
        // state" UI needs to differ from a "loaded state" UI without
        // hand-written JS.
        document.querySelectorAll('[data-state-show-empty]').forEach(function (el) {
            var ref = el.getAttribute('data-state-show-empty') || '';
            var dot = ref.indexOf('.');
            if (dot === -1 || ref.slice(0, dot) !== storeId) return;
            var v = store.state[ref.slice(dot + 1)];
            var truthy = !(v === null || v === undefined || v === '' || v === 0 || v === false
                || (Array.isArray(v) && v.length === 0));
            el.hidden = truthy;
        });

        // Paged-navigator binding: [data-state-pagenav="storeId"] is a
        // <nav> whose numbered buttons are rendered (and re-rendered)
        // every time the bound store updates. Reads the configured
        // `totalPages` field to size itself, marks the current page
        // (`page` field) with aria-current. Hides itself when
        // totalPages is missing or <= 1.
        //
        // Click handling: a single delegated listener is attached the
        // FIRST time a navigator is rendered (tracked via a WeakSet
        // so we don't double-bind across re-renders). Buttons carry
        // data-page="N"; the listener reads it + dispatches
        // QS.setState(storeId, pageField, N) → QS.fetchState(storeId).
        document.querySelectorAll('[data-state-pagenav]').forEach(function (nav) {
            if (nav.getAttribute('data-state-pagenav') !== storeId) return;
            var pageField = nav.getAttribute('data-state-pagenav-page-field') || 'page';
            var totalField = nav.getAttribute('data-state-pagenav-totalpages-field') || 'totalPages';
            var win = parseInt(nav.getAttribute('data-state-pagenav-window') || '2', 10);
            if (!isFinite(win) || win < 0) win = 2;
            var includePrevNext = nav.getAttribute('data-state-pagenav-prev-next') === 'true';

            var totalPages = parseInt(store.state[totalField], 10);
            var current = parseInt(store.state[pageField], 10);
            if (!isFinite(current) || current < 1) current = 1;

            // No / one-page navigation — hide entirely.
            if (!isFinite(totalPages) || totalPages <= 1) {
                nav.hidden = true;
                return;
            }
            nav.hidden = false;

            // Compute the visible page set with smart ellipsis. Always
            // include first + last + current ± window; insert '...'
            // wherever there's a gap > 1.
            var pageSet = {};
            pageSet[1] = true;
            pageSet[totalPages] = true;
            for (var i = Math.max(1, current - win); i <= Math.min(totalPages, current + win); i++) {
                pageSet[i] = true;
            }
            var sorted = Object.keys(pageSet).map(function (n) { return parseInt(n, 10); }).sort(function (a, b) { return a - b; });
            var visible = []; // entries: number OR the string '...'
            for (var j = 0; j < sorted.length; j++) {
                if (j > 0) {
                    var gap = sorted[j] - sorted[j - 1];
                    // Gap of exactly 2 = one page missing; render that
                    // page literally (an ellipsis would take the same
                    // horizontal space without the affordance to click).
                    // Gap > 2 = multiple pages skipped → real ellipsis.
                    if (gap === 2) visible.push(sorted[j - 1] + 1);
                    else if (gap > 2) visible.push('...');
                }
                visible.push(sorted[j]);
            }

            // Rebuild children. createElement + textContent only — no
            // innerHTML, per CLAUDE.md HTML-in-JS hygiene.
            nav.textContent = '';
            if (includePrevNext) {
                var prev = document.createElement('button');
                prev.type = 'button';
                prev.className = 'paged-nav__btn paged-nav__btn--prev';
                prev.textContent = '‹';   // ‹
                prev.setAttribute('aria-label', 'Previous page');
                prev.setAttribute('data-page', String(Math.max(1, current - 1)));
                if (current <= 1) {
                    prev.disabled = true;
                    prev.setAttribute('aria-disabled', 'true');
                }
                nav.appendChild(prev);
            }
            visible.forEach(function (entry) {
                if (entry === '...') {
                    var gap = document.createElement('span');
                    gap.className = 'paged-nav__gap';
                    gap.textContent = '…';   // …
                    gap.setAttribute('aria-hidden', 'true');
                    nav.appendChild(gap);
                    return;
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'paged-nav__btn';
                if (entry === current) {
                    btn.className += ' paged-nav__btn--current';
                    btn.setAttribute('aria-current', 'page');
                }
                btn.textContent = String(entry);
                btn.setAttribute('data-page', String(entry));
                btn.setAttribute('aria-label', 'Go to page ' + entry);
                nav.appendChild(btn);
            });
            if (includePrevNext) {
                var next = document.createElement('button');
                next.type = 'button';
                next.className = 'paged-nav__btn paged-nav__btn--next';
                next.textContent = '›';   // ›
                next.setAttribute('aria-label', 'Next page');
                next.setAttribute('data-page', String(Math.min(totalPages, current + 1)));
                if (current >= totalPages) {
                    next.disabled = true;
                    next.setAttribute('aria-disabled', 'true');
                }
                nav.appendChild(next);
            }

            // Attach the delegated click listener ONCE per nav element.
            // _pageNavBound is a WeakSet so removed navs don't pin entries.
            if (!_pageNavBound.has(nav)) {
                _pageNavBound.add(nav);
                nav.addEventListener('click', function (e) {
                    var target = e.target;
                    while (target && target !== nav && (!target.classList || !target.classList.contains('paged-nav__btn'))) {
                        target = target.parentNode;
                    }
                    if (!target || target === nav || target.disabled) return;
                    var pageStr = target.getAttribute('data-page');
                    var page = parseInt(pageStr, 10);
                    if (!isFinite(page) || page < 1) return;
                    // Same store-id captured at render time. Re-read attrs
                    // in case the user swapped the binding to a different
                    // store on the fly (unusual but harmless to support).
                    var sid = nav.getAttribute('data-state-pagenav');
                    var pf = nav.getAttribute('data-state-pagenav-page-field') || 'page';
                    if (typeof QS.setState === 'function') QS.setState(sid, pf, page);
                    if (typeof QS.fetchState === 'function') QS.fetchState(sid).catch(function () {});
                });
            }
        });
    }

    /**
     * Set a store field. `value` is a literal, or a "#id"/".class" selector
     * whose element value (or textContent) is read. Re-renders the store.
     */
    QS.setState = function (storeId, field, value) {
        var store = QS._stores[storeId];
        if (!store) { console.warn('[QS] setState: unknown store', storeId); return; }
        if (!store.def.fields || !(field in store.def.fields)) {
            console.warn('[QS] setState: unknown field "' + field + '" on store', storeId); return;
        }
        var v = value;
        if (typeof value === 'string' && (value.charAt(0) === '#' || value.charAt(0) === '.')) {
            var el = document.querySelector(value);
            v = el ? (el.value !== undefined && el.value !== null ? el.value : el.textContent) : '';
        }
        store.state[field] = v;
        // A user-driven setState (resetting page / search query / cursor)
        // signals intent to re-fetch — clear the exhausted flag so scroll
        // triggers re-arm. See QS.onScrollFetchState below.
        store._exhausted = false;
        _renderStore(storeId);
    };

    /** Read a store field, or the whole state object when `field` is omitted. */
    QS.getState = function (storeId, field) {
        var store = QS._stores[storeId];
        if (!store) return undefined;
        return field === undefined ? store.state : store.state[field];
    };

    /**
     * Fire a store's endpoint using its request/both fields, then apply the
     * response into its response/both fields (append where flagged) and
     * re-render. Reuses QS.fetch (auth, refresh-on-401, responseBindings).
     */
    QS.fetchState = function (storeId) {
        var store = QS._stores[storeId];
        if (!store) { console.warn('[QS] fetchState: unknown store', storeId); return Promise.resolve(); }
        // Skip if a previous fetchState for this store is still in flight —
        // prevents overlapping fetches when a trigger fires faster than the
        // network (scroll bursts especially).
        if (store._inFlight) return Promise.resolve();
        store._inFlight = true;

        var fields = store.def.fields || {};
        var opts = [];
        // Capture pre-fetch values of `both`-direction fields so we can detect
        // a stalled cursor (response cursor === request cursor → no advance).
        var preCursor = {};
        for (var name in fields) {
            if (!Object.prototype.hasOwnProperty.call(fields, name)) continue;
            var dir = fields[name].dir;
            if (dir === 'request' || dir === 'both') {
                var val = store.state[name];
                if (val !== undefined && val !== null && val !== '') {
                    opts.push(name + '=' + val);
                }
            }
            if (dir === 'both') preCursor[name] = store.state[name];
        }
        // The store renders its own DOM (data-state-value / data-state-list),
        // so suppress the endpoint's responseBindings for this call.
        opts.push('noBindings=1');

        return QS.fetch.apply(QS, [store.def.endpoint].concat(opts))
            .then(function (data) {
                // Apply response / both fields into the store's state.
                for (var n in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, n)) continue;
                    var f = fields[n];
                    if ((f.dir === 'response' || f.dir === 'both') && f.from) {
                        var rv = getNestedValue(data, f.from);
                        if (f.append) {
                            var cur = Array.isArray(store.state[n]) ? store.state[n] : [];
                            store.state[n] = cur.concat(
                                Array.isArray(rv) ? rv : (rv === undefined || rv === null ? [] : [rv])
                            );
                        } else {
                            store.state[n] = rv;
                        }
                    }
                }
                // Exhausted heuristic — set true so scroll triggers stop firing.
                // Cleared by any QS.setState (user-driven re-query). One of:
                //   - explicit `hasMore: false` in the response (fast path),
                //   - any append-mode field returned 0 items,
                //   - any `both` cursor came back unchanged (no advance).
                if (data && data.hasMore === false) {
                    store._exhausted = true;
                } else {
                    var exhausted = false;
                    for (var n2 in fields) {
                        if (!Object.prototype.hasOwnProperty.call(fields, n2)) continue;
                        var f2 = fields[n2];
                        if (f2.append) {
                            var rv2 = getNestedValue(data, f2.from);
                            if (!Array.isArray(rv2) || rv2.length === 0) { exhausted = true; break; }
                        }
                        if (f2.dir === 'both' && preCursor[n2] === store.state[n2]) {
                            exhausted = true; break;
                        }
                    }
                    if (exhausted) store._exhausted = true;
                }
                _renderStore(storeId);
                store._inFlight = false;
                return data;
            }, function (err) {
                // Treat any fetch failure (4xx/5xx/network) as end-of-stream
                // so scroll triggers don't keep hammering a broken endpoint.
                store._exhausted = true;
                store._inFlight = false;
                throw err;
            });
    };

    // Per-store registration guard for QS.onScrollFetchState (idempotent).
    var _ssScrollRegistered = {};

    /**
     * Register (once per store) a debounced window-scroll listener that fires
     * QS.fetchState(storeId) when the viewport bottom is within `triggerPx`
     * of the page bottom — and STOPS firing once the store is marked
     * `_exhausted` (HTTP error, response items empty, or a `both` cursor that
     * didn't advance). Use as a page-event `onload` action for the
     * infinite-scroll pattern; pair with an `append:true` list field for the
     * classic "load more on scroll" UX:
     *   onload: {{call:onScrollFetchState:scrollingStore,200,100}}
     *           args = storeId, triggerPx (default 200), debounceMs (default 100).
     */
    QS.onScrollFetchState = function (storeId, triggerPx, debounceMs) {
        if (_ssScrollRegistered[storeId]) return;
        var store = QS._stores[storeId];
        if (!store) { console.warn('[QS] onScrollFetchState: unknown store', storeId); return; }

        var px = parseInt(triggerPx, 10);
        if (!isFinite(px) || px < 0) px = 200;
        var debounce = parseInt(debounceMs, 10);
        if (!isFinite(debounce) || debounce < 0) debounce = 100;

        var timer = null;
        var trigger = function () {
            if (store._inFlight || store._exhausted) return;
            var docHeight = document.documentElement.scrollHeight;
            var viewportEnd = window.scrollY + window.innerHeight;
            if (docHeight - viewportEnd > px) return;
            QS.fetchState(storeId).catch(function () { /* QS.fetch already surfaced */ });
        };
        var schedule = function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(trigger, debounce);
        };

        window.addEventListener('scroll', schedule, { passive: true });
        _ssScrollRegistered[storeId] = true;
        // Fire once after fetchOnLoad settles — handles the "list shorter than
        // viewport, can't scroll" case so the user still gets auto-load-more
        // until the page is scrollable (or exhausted).
        setTimeout(trigger, 200);
    };

    // Build live stores from the per-page definitions, seed initial values,
    // render scalars, and fire fetchOnLoad stores.
    //
    // Beta.8 A2 Slice 5 — hydration handoff. When the server-side resolver
    // fired against the same endpoint a store is bound to, window.QS_RESOLVED
    // arrives populated as { storeId: { fieldName: value, ... } }. Stores
    // that have hydration data:
    //   - seed state[fieldName] from the resolved values instead of the
    //     default/init source (only for response/both fields — request-dir
    //     fields still come from init: query/param/storage/literal),
    //   - SKIP the initial fetchOnLoad fetch (the data is already on screen,
    //     no point round-tripping for the same response on first paint),
    //   - keep subsequent fetchState() calls working normally — Load More,
    //     search, paginate all hit the upstream as before.
    // Stores without hydration follow the unchanged client-only path.
    function _initStores() {
        var defs = window.QS_STATE_STORES || {};
        var hydrationAll = window.QS_RESOLVED || {};
        for (var storeId in defs) {
            if (!Object.prototype.hasOwnProperty.call(defs, storeId)) continue;
            var def = defs[storeId];
            var fields = def.fields || {};
            var state = {};
            var hydration = (hydrationAll && typeof hydrationAll === 'object' && hydrationAll[storeId])
                ? hydrationAll[storeId]
                : null;
            for (var name in fields) {
                if (!Object.prototype.hasOwnProperty.call(fields, name)) continue;
                var f = fields[name];
                if (hydration && Object.prototype.hasOwnProperty.call(hydration, name)) {
                    // Server-resolved value wins for this field — the
                    // server already extracted from response.<from> via the
                    // resolver's expose mapping, so we use it directly
                    // without consulting `default` or `init`.
                    state[name] = hydration[name];
                } else if (f.dir === 'response') {
                    state[name] = ('default' in f) ? f.default : (f.append ? [] : null);
                } else {
                    state[name] = _resolveInit(f.init, f.default);
                }
            }
            QS._stores[storeId] = { def: def, state: state, _inFlight: false, _exhausted: false };
            _renderStore(storeId);
            // Skip fetchOnLoad when hydration covered this store. Any
            // subsequent QS.fetchState(storeId) (e.g. from a Load-More
            // button or a search input handler) fires upstream normally.
            if (def.fetchOnLoad && !hydration) {
                QS.fetchState(storeId).catch(function () { /* QS.fetch already surfaced the error */ });
            }
        }
    }

    // Exposed so callers can (re)build stores after setting QS_STATE_STORES
    // dynamically (and for console testing before the admin UI exists).
    QS.initStores = _initStores;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _initStores);
    } else {
        _initStores();
    }

    // Expose to window
    window.QS = QS;

})(window);
