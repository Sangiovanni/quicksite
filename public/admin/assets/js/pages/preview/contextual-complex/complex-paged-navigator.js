/**
 * Paged navigator wizard — kind 'paged-navigator'.
 *
 * Emits a <nav class="paged-nav"> bound to a state store; the runtime
 * (`[data-state-pagenav]` binding in `secure/src/runtime/qs.js`) populates
 * the numbered buttons + Prev/Next chevrons every time the store
 * updates. Hides itself when totalPages is missing or ≤ 1.
 *
 * Wizard fields:
 *  - Store id (dropdown of the current page's state stores)
 *  - Page field name (default 'page' — what buttons WRITE on click)
 *  - Total-pages field name (default 'totalPages' — what the nav READS)
 *  - Window size (number of sibling pages each side of current; smart
 *                 ellipsis fills the rest. Default 2.)
 *  - Include Prev / Next chevrons (default ON)
 *  - Optional HTML id for the <nav>
 *
 * Per CLAUDE.md HTML-in-JS hygiene: this file uses createElement +
 * textContent throughout (no innerHTML for static structure).
 *
 * Server-side builder: secure/src/classes/complexElements/PagedNavigator.php
 */
(function () {
    'use strict';

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
            star.className = 'admin-text-danger';
            star.textContent = ' *';
            l.appendChild(star);
        }
        return l;
    }
    function _renderHint(text) {
        var p = document.createElement('p');
        p.className = 'admin-hint';
        p.textContent = text;
        return p;
    }
    function _renderGroup(child) {
        var g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }
    function _renderTextInput(id, placeholder, value) {
        var i = document.createElement('input');
        i.type = 'text';
        i.className = 'admin-input';
        i.id = id;
        if (placeholder) i.placeholder = placeholder;
        if (value !== undefined && value !== null) i.value = value;
        i.autocomplete = 'off';
        return i;
    }

    // Read the current page route the user is editing. PreviewState
    // exposes `selectedStruct` which is `'page-<route>'` when editing a
    // page (or 'menu' / 'footer' / 'component-X' for those contexts —
    // state stores don't exist there, so we return null for callers to
    // gracefully degrade).
    function _getCurrentRoute() {
        try {
            if (window.PreviewState && typeof window.PreviewState.get === 'function') {
                var ss = window.PreviewState.get('selectedStruct');
                if (typeof ss === 'string' && ss.indexOf('page-') === 0) {
                    return ss.substring('page-'.length);
                }
            }
        } catch (e) {}
        return null;
    }

    // Fetch the current page's state stores (if any) so the store
    // dropdown shows real options. Returns Promise<{storeId: def}>.
    // Re-fetches per wizard instance — catalogue is small + the user
    // might've added a store in another tab.
    function _fetchCurrentPageStores() {
        var api = window.QuickSiteAdmin;
        if (!api) return Promise.resolve({});
        var route = _getCurrentRoute();
        if (!route) {
            // Not on a page (menu / footer / component context); state
            // stores don't apply, return empty so the dropdown surfaces
            // the appropriate "no stores" message.
            return Promise.resolve({});
        }
        return api.apiRequest('getStateStores', 'POST', { route: route }).then(function (res) {
            var payload = (res && res.data && (res.data.data || res.data)) || {};
            var stores = payload.stores;
            // getStateStores returns the per-route map for that route.
            // CRITICAL: when called WITHOUT a route, it returns the
            // all-routes shape `{<route>: {<storeId>: def}}` — Object.keys
            // would give back ROUTE names, not store names (which would
            // then fail validation as the storeId regex rejects '/').
            // _getCurrentRoute() must return a real route for the call
            // shape to match the per-route response we expect here.
            if (!stores || typeof stores !== 'object' || Array.isArray(stores)) return {};
            return stores;
        }).catch(function (err) {
            console.warn('[PagedNavigator wizard] getStateStores failed:', err);
            return {};
        });
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--paged-navigator';

        // -- store picker --
        var storeGroup = _renderGroup();
        storeGroup.appendChild(_renderLabel('Bound state store', true));
        var storeSelect = document.createElement('select');
        storeSelect.className = 'admin-input';
        storeSelect.id = 'ce-pn-store';
        (function () {
            var loadingOpt = document.createElement('option');
            loadingOpt.value = '';
            loadingOpt.textContent = '(loading…)';
            storeSelect.appendChild(loadingOpt);
        })();
        storeGroup.appendChild(storeSelect);
        storeGroup.appendChild(_renderHint('The store whose fields the navigator reads (totalPages) and writes (page). Configure on this page via JS mode → State stores.'));
        wrap.appendChild(storeGroup);

        // -- page field --
        var pageFieldInput = _renderTextInput('ce-pn-page-field', 'e.g. page', 'page');
        var pageFieldGroup = _renderGroup();
        pageFieldGroup.appendChild(_renderLabel('Page field (REQUEST — buttons WRITE on click)'));
        pageFieldGroup.appendChild(pageFieldInput);
        wrap.appendChild(pageFieldGroup);

        // -- totalPages field --
        var totalFieldInput = _renderTextInput('ce-pn-total-field', 'e.g. totalPages', 'totalPages');
        var totalFieldGroup = _renderGroup();
        totalFieldGroup.appendChild(_renderLabel('Total-pages field (RESPONSE — navigator READS to size itself)'));
        totalFieldGroup.appendChild(totalFieldInput);
        wrap.appendChild(totalFieldGroup);

        // -- window size --
        var windowInput = document.createElement('input');
        windowInput.type = 'number';
        windowInput.className = 'admin-input';
        windowInput.id = 'ce-pn-window';
        windowInput.min = '0';
        windowInput.max = '10';
        windowInput.value = '2';
        windowInput.style.maxWidth = '120px';
        var windowGroup = _renderGroup();
        windowGroup.appendChild(_renderLabel('Window size (siblings each side of the current page)'));
        windowGroup.appendChild(windowInput);
        windowGroup.appendChild(_renderHint('Smart-ellipsis layout: always shows first + last + N siblings around the current page (e.g. "1 … 4 5 [6] 7 8 … 100"). 0–10. Default 2.'));
        wrap.appendChild(windowGroup);

        // -- include prev/next --
        var prevNextLabel = document.createElement('label');
        prevNextLabel.className = 'admin-checkbox';
        var prevNextCb = document.createElement('input');
        prevNextCb.type = 'checkbox';
        prevNextCb.id = 'ce-pn-prev-next';
        prevNextCb.checked = true;
        var prevNextSpan = document.createElement('span');
        prevNextSpan.textContent = 'Include ‹ Prev / Next › chevrons';
        prevNextLabel.appendChild(prevNextCb);
        prevNextLabel.appendChild(prevNextSpan);
        wrap.appendChild(_renderGroup(prevNextLabel));

        // -- optional HTML id --
        var idInput = _renderTextInput('ce-pn-id', 'e.g. results-pager (optional)');
        var idGroup = _renderGroup();
        idGroup.appendChild(_renderLabel('HTML id (optional)'));
        idGroup.appendChild(idInput);
        idGroup.appendChild(_renderHint('Set an id only if you need to target the <nav> from CSS / JS / interactions.'));
        wrap.appendChild(idGroup);

        container.appendChild(wrap);

        // ---- async populate the store dropdown ----
        var loadedStoreIds = [];
        _fetchCurrentPageStores().then(function (stores) {
            loadedStoreIds = Object.keys(stores);
            storeSelect.innerHTML = '';
            if (loadedStoreIds.length === 0) {
                var noneOpt = document.createElement('option');
                noneOpt.value = '';
                noneOpt.textContent = '(no state stores on this page — add one in JS mode first)';
                noneOpt.disabled = true;
                noneOpt.selected = true;
                storeSelect.appendChild(noneOpt);
                return;
            }
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- pick a store --';
            storeSelect.appendChild(placeholder);
            loadedStoreIds.forEach(function (sid) {
                var opt = document.createElement('option');
                opt.value = sid;
                opt.textContent = sid;
                storeSelect.appendChild(opt);
            });
        });

        // ---- controller ----
        function getConfig() {
            var cfg = {
                storeId: storeSelect.value || '',
                pageField: pageFieldInput.value.trim() || 'page',
                totalPagesField: totalFieldInput.value.trim() || 'totalPages',
                window: parseInt(windowInput.value, 10),
                includePrevNext: !!prevNextCb.checked,
            };
            if (!isFinite(cfg.window) || cfg.window < 0) cfg.window = 2;
            var id = idInput.value.trim();
            if (id !== '') cfg.id = id;
            return cfg;
        }

        function validate() {
            var cfg = getConfig();
            if (!cfg.storeId) return 'Pick a state store.';
            if (!/^[a-zA-Z][\w-]*$/.test(cfg.storeId)) {
                return 'storeId must start with a letter; use letters, digits, hyphens, underscores.';
            }
            if (!/^[a-zA-Z_][\w]*$/.test(cfg.pageField)) {
                return 'pageField must be a valid identifier (letters, digits, _).';
            }
            if (!/^[a-zA-Z_][\w]*$/.test(cfg.totalPagesField)) {
                return 'totalPagesField must be a valid identifier.';
            }
            if (cfg.window < 0 || cfg.window > 10) {
                return 'window must be between 0 and 10.';
            }
            if (cfg.id && !/^[a-zA-Z][\w-]*$/.test(cfg.id)) {
                return 'HTML id must start with a letter; use letters, digits, hyphens, underscores.';
            }
            return null;
        }

        function destroy() {
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['paged-navigator'] = {
        label: 'Paged navigator (numbered)',
        description: 'A &lt;nav&gt; bound to a state store. Reads `totalPages` to size itself; numbered buttons + optional Prev/Next chevrons. Click a number → setState(page) → fetchState. Hides when totalPages is missing or ≤ 1.',
        renderWizard: renderWizard
    };
})();
