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

    /**
     * Filter elements based on text input
     * Call from input element's oninput event
     * @param {Event|string} eventOrSelector - Event object OR items selector (for backward compat)
     * @param {string} [itemsSelector] - Selector for items to filter (when event passed)
     * @param {string} [matchAttr] - Attribute to match against (default: textContent)
     * @param {string} [hideClass] - Class to add/remove (default: 'hidden')
     * 
     * Usage in JSON: "oninput": "{{call:filter:event,.card,data-title}}"
     * Pass 'event' as first arg to get input value from the triggering element
     */
    QS.filter = function(eventOrSelector, itemsSelector, matchAttr, hideClass) {
        let searchValue = '';
        let selector = itemsSelector;
        let attr = matchAttr;
        let hideCls = hideClass || DEFAULT_HIDDEN_CLASS;
        
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
        
        items.forEach(el => {
            let matchValue = '';
            
            if (!attr || attr === 'textContent') {
                matchValue = el.textContent || '';
            } else if (attr.startsWith('data-') || attr.startsWith('[')) {
                // data-* attribute or [attr] selector syntax
                const attrName = attr.replace(/^\[|\]$/g, ''); // Remove brackets if present
                matchValue = el.getAttribute(attrName) || el.textContent || '';
            } else {
                matchValue = el.getAttribute(attr) || el.textContent || '';
            }

            matchValue = matchValue.toLowerCase();

            if (searchValue === '' || matchValue.includes(searchValue)) {
                el.classList.remove(hideCls);
            } else {
                el.classList.add(hideCls);
            }
        });
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
        
        // Build request options
        const fetchOpts = { method: method, headers: {} };
        
        // Always send Accept-Language for multilingual API support
        fetchOpts.headers['Accept-Language'] = pageLang;
        
        // Apply auth if from registry
        if (opts._auth) {
            applyAuth(fetchOpts, opts._auth);
        }
        
        // Build body if specified
        if (opts.body) {
            const bodyData = collectBody(opts.body);
            if (bodyData) {
                fetchOpts.body = JSON.stringify(bodyData);
                fetchOpts.headers['Content-Type'] = 'application/json';
            }
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
                
                return data;
            })
            .catch(error => {
                // Custom error handler
                if (opts.onError && typeof window[opts.onError] === 'function') {
                    window[opts.onError](error);
                } else if (!opts.silent) {
                    QS.toast(error.message || 'Request failed', 'error');
                }
                
                throw error;
            });
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
     * Apply response bindings to DOM
     * Supports scalar bindings and list rendering (renderMode: 'list')
     * @param {object} data - Response data
     * @param {array} bindings - [{selector, field, attribute?, renderMode?, container?, emptyText?}]
     */
    function applyBindings(data, bindings) {
        if (!Array.isArray(bindings)) return;
        
        bindings.forEach(binding => {
            const { field } = binding;
            if (!field) return;
            
            // Get value from data using dot notation
            const value = getNestedValue(data, field);
            if (value === undefined) return;
            
            // List rendering mode
            if (binding.renderMode === 'list') {
                const container = binding.container;
                if (!container) return;
                renderList(container, value, binding.emptyText);
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

    // Expose to window
    window.QS = QS;

})(window);
