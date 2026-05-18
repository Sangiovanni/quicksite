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
     * Get all elements matching selector
     * @param {string} selector - CSS selector
     * @returns {NodeList}
     */
    function getElements(selector) {
        try {
            return document.querySelectorAll(selector);
        } catch (e) {
            console.warn('[QS] Invalid selector:', selector);
            return [];
        }
    }

    /**
     * Show element(s) - removes hidden class
     * @param {string} target - CSS selector
     * @param {string} [hideClass] - Class to remove (default: 'hidden')
     */
    QS.show = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.remove(hideClass));
    };

    /**
     * Hide element(s) - adds hidden class
     * @param {string} target - CSS selector
     * @param {string} [hideClass] - Class to add (default: 'hidden')
     */
    QS.hide = function(target, hideClass) {
        hideClass = hideClass || DEFAULT_HIDDEN_CLASS;
        const elements = getElements(target);
        elements.forEach(el => el.classList.add(hideClass));
    };

    /**
     * Toggle element(s) visibility (show/hide flip-switch)
     * @param {string} target - CSS selector
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
     * @param {string} target - CSS selector
     * @param {string} [behavior] - 'smooth' (default) or 'instant'
     */
    QS.scrollTo = function(target, behavior) {
        behavior = behavior || 'smooth';
        const element = document.querySelector(target);
        if (element) {
            element.scrollIntoView({ behavior: behavior, block: 'start' });
        }
    };

    /**
     * Focus an element
     * @param {string} target - CSS selector
     */
    QS.focus = function(target) {
        const element = document.querySelector(target);
        if (element && element.focus) {
            element.focus();
        }
    };

    /**
     * Blur (unfocus) an element
     * @param {string} target - CSS selector
     */
    QS.blur = function(target) {
        const element = document.querySelector(target);
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
     * - onSuccess=functionName (custom success handler)
     * - onError=functionName (custom error handler)
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
            'body', 'onSuccess', 'onError', 'silent', '_auth', '_endpoint'
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

        // Make the request
        return fetch(url, fetchOpts)
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

                // Apply response bindings if defined
                if (opts._endpoint && opts._endpoint.responseBindings) {
                    applyBindings(data, opts._endpoint.responseBindings);
                }

                // Custom success handler
                if (opts.onSuccess && typeof window[opts.onSuccess] === 'function') {
                    window[opts.onSuccess](data);
                } else if (!opts.silent) {
                    QS.toast('Success', 'success');
                }

                // Notify any listener (e.g. cross-page count, post-fetch chains).
                // Fires AFTER bindings + onSuccess so listeners see a fully
                // settled DOM if they react to it.
                document.dispatchEvent(new CustomEvent('qs:fetch:loaded', {
                    detail: { ref: ref, data: data }
                }));

                return data;
            })
            .catch(error => {
                // Custom error handler
                if (opts.onError && typeof window[opts.onError] === 'function') {
                    window[opts.onError](error);
                } else if (!opts.silent) {
                    QS.toast(error.message || 'Request failed', 'error');
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

    // Expose to window
    window.QS = QS;

})(window);
