/**
 * route-input.js — shared `<datalist>` of project routes for any
 * complex-element wizard whose form has href / url fields.
 *
 * Public API (under window.QSComplexWizard):
 *
 *   QSComplexWizard.ensureRoutesDatalist() → Promise<string>
 *     Resolves to the id of a <datalist> attached to document.body.
 *     First call: fires getRoutes (cached project-wide thereafter),
 *     populates the datalist with `/` + every flat route path.
 *     Subsequent calls: returns the same id without re-fetching.
 *
 *     Usage in a wizard:
 *       QSComplexWizard.ensureRoutesDatalist().then(function (id) {
 *         hrefInput.setAttribute('list', id);
 *       });
 *
 *   QSComplexWizard.invalidateRoutesDatalist()
 *     Drops the cache so the next ensureRoutesDatalist() refetches.
 *     Call this after addRoute / deleteRoute / renameRoute if the
 *     admin offers those during the same session (currently they
 *     reload the editor, so this is dormant).
 *
 * Design notes:
 *  - <datalist> is purely an autocomplete suggestion — the user can
 *    still type any string (e.g. an external URL like https://...).
 *    This matches the planning spec ("each input has a route picker
 *    AND still works for external URLs").
 *  - One datalist per editor session, shared across N inputs. Cheap.
 *  - getRoutes returns `flat_routes` as an array of dot/slash paths
 *    (e.g. ["test/complex-element", "documentation/commands"]). We
 *    prepend "/" for the canonical href form.
 *
 * Loaded by: secure/admin/templates/pages/preview-config.php BEFORE
 * any complex-*.js file.
 */
(function () {
    'use strict';

    var DATALIST_ID = 'qs-routes-datalist';
    var _datalist = null;
    var _loadingPromise = null;

    function _buildDatalistEl() {
        var dl = document.createElement('datalist');
        dl.id = DATALIST_ID;
        document.body.appendChild(dl);
        return dl;
    }

    function _populate(dl, flatRoutes) {
        dl.textContent = '';
        // Conventional shortcuts the user might want as the canonical
        // home or the absolute-URL hint.
        var home = document.createElement('option');
        home.value = '/';
        dl.appendChild(home);

        if (!Array.isArray(flatRoutes)) return;
        flatRoutes.forEach(function (route) {
            if (typeof route !== 'string' || route === '') return;
            var opt = document.createElement('option');
            opt.value = '/' + route.replace(/^\//, ''); // tolerate stored paths with or without leading /
            dl.appendChild(opt);
        });
    }

    function ensureRoutesDatalist() {
        if (_datalist && document.body.contains(_datalist)) {
            return Promise.resolve(DATALIST_ID);
        }
        if (_loadingPromise) return _loadingPromise;

        _datalist = _buildDatalistEl();
        var api = window.QuickSiteAdmin;
        if (!api) {
            // No admin API → just return the empty datalist id; inputs
            // will still work as plain text inputs with no suggestions.
            return Promise.resolve(DATALIST_ID);
        }
        // QuickSiteAdmin.apiRequest returns {ok, status, data: <envelope>}
        // where the ApiResponse envelope wraps the payload AGAIN in `.data`.
        // So flat_routes sits at res.data.data.flat_routes. Use the defensive
        // `(payload.data || payload)` unwrap pattern from text-key-picker.js
        // loadKeysOnce() in case any future apiRequest refactor smooths it.
        _loadingPromise = api.apiRequest('getRoutes', 'GET').then(function (res) {
            var payload = (res && res.data && (res.data.data || res.data)) || {};
            var flat = Array.isArray(payload.flat_routes) ? payload.flat_routes : [];
            _populate(_datalist, flat);
            _loadingPromise = null;
            return DATALIST_ID;
        }).catch(function (err) {
            console.warn('[route-input] getRoutes failed, datalist will be empty:', err);
            _loadingPromise = null;
            return DATALIST_ID;
        });
        return _loadingPromise;
    }

    function invalidateRoutesDatalist() {
        if (_datalist && _datalist.parentNode) {
            _datalist.parentNode.removeChild(_datalist);
        }
        _datalist = null;
        _loadingPromise = null;
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.ensureRoutesDatalist = ensureRoutesDatalist;
    window.QSComplexWizard.invalidateRoutesDatalist = invalidateRoutesDatalist;
})();
