/**
 * OAuth Button wizard — kind 'oauth-button'.
 *
 * Picks one of the providers the installation offers (listOAuthProviders;
 * the list is a file the installation's operator edits), shows whether
 * the per-provider routes already exist and whether this project has
 * entered its preview keys, and orchestrates the multi-step setup on
 * submit:
 *
 *   1. addRoute   /auth/oauth/<provider>/start    (skip-if-exists)
 *   2. addRoute   /auth/oauth/<provider>/callback (skip-if-exists)
 *   3. setRouteResolver on start    (oauth-start kind)
 *   4. setRouteResolver on callback (oauth-callback kind)
 *   5. addComplexElement (host handles step 5 after preSubmit resolves),
 *      which also writes the label's starting text where its key has none
 *      (OAuthButtonBuilder::startingTexts()).
 *
 * Steps 1-4 fire in preSubmit (new optional wizard hook in preview.js
 * addComplexNode). When routes already exist the UX warns the author
 * the wizard will reuse them (skip-with-warn, by design: DESIGN_DECISIONS.md
 * "OAuth-button Complex Element").
 *
 * Server-side builder: secure/src/classes/complexElements/OAuthButton.php
 * Provider listing:    secure/management/command/listOAuthProviders.php
 */
(function () {
    'use strict';

    const t = window.QSComplexWizard.t;

    let _providersCache = null;
    let _providersPromise = null;
    let _routesCache = null;
    let _routesPromise = null;

    function loadProvidersOnce() {
        if (_providersCache !== null) return Promise.resolve(_providersCache);
        if (_providersPromise) return _providersPromise;
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            _providersCache = [];
            return Promise.resolve(_providersCache);
        }
        _providersPromise = (async function () {
            try {
                const r = await adminApi.apiRequest('listOAuthProviders', 'GET');
                const payload = (r && r.data && (r.data.data || r.data)) || {};
                _providersCache = Array.isArray(payload.providers) ? payload.providers : [];
            } catch (err) {
                console.warn('[OAuthButton] failed to load providers:', err);
                _providersCache = [];
            } finally {
                _providersPromise = null;
            }
            return _providersCache;
        })();
        return _providersPromise;
    }

    // Force a refresh on next render: the Edit list's refreshEditList() says the routes changed
    // (this wizard's setup among the causes), so the cached setup status is stale.
    function invalidateProvidersCache() {
        _providersCache = null;
        _routesCache = null;
    }
    window.addEventListener('quicksite:routes-changed', invalidateProvidersCache);

    /**
     * Load the project's literal routes for the "Redirect after login"
     * picker. Skips :param routes since the wizard can't predict what
     * value to substitute; authors who need a param-route landing
     * configure it via the element's params editor after insertion.
     * 'home' is normalised to '/' (matches the public-site root).
     *
     * Server-side OAuthHandler::sanitiseReturnTo enforces the same
     * "must be a registered route" rule — this client-side picker is
     * UX, not security.
     */
    function loadRoutesOnce() {
        if (_routesCache !== null) return Promise.resolve(_routesCache);
        if (_routesPromise) return _routesPromise;
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            _routesCache = ['/'];
            return Promise.resolve(_routesCache);
        }
        _routesPromise = (async function () {
            try {
                const r = await adminApi.apiRequest('getRoutes', 'GET');
                const payload = (r && r.data && (r.data.data || r.data)) || {};
                _routesCache = _flattenLiteralRoutes(payload.routes || {});
            } catch (err) {
                console.warn('[OAuthButton] failed to load routes:', err);
                _routesCache = ['/'];
            } finally {
                _routesPromise = null;
            }
            return _routesCache;
        })();
        return _routesPromise;
    }

    /**
     * The literal paths of getRoutes' nested `routes`. A level is a map of names, and it reaches here as a
     * JSON list when its children are named 0, 1, 2…, because PHP encodes such an array as one: its keys
     * are still the names, and an empty level, a list too, has none.
     */
    function _flattenLiteralRoutes(routes, prefix) {
        const out = [];
        if (!routes || typeof routes !== 'object') return out;
        prefix = prefix || '';
        Object.keys(routes).forEach(function (key) {
            if (key.charAt(0) === ':') return;
            const path = (key === 'home' && prefix === '') ? '/' : (prefix + '/' + key);
            out.push(path);
            _flattenLiteralRoutes(routes[key], prefix + '/' + key).forEach(function (p) { out.push(p); });
        });
        return out;
    }

    function _renderLabel(text, required) {
        const l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            const star = document.createElement('span');
            star.textContent = ' *';
            l.appendChild(star);
        }
        return l;
    }
    function _renderHint(text) {
        const p = document.createElement('p');
        p.className = 'admin-hint';
        p.textContent = text;
        return p;
    }
    function _renderGroup(child) {
        const g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }

    // Tiny helpers for the cookie-note body — multiple paragraphs with
    // inline <code> / <a> fragments. Keeping them as named _render*
    // helpers per the CLAUDE.md HTML-in-JS hygiene rule (createElement +
    // textContent over innerHTML strings).
    function _renderAnchor(href, text) {
        const a = document.createElement('a');
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = text;
        return a;
    }
    function _renderParagraph(parts) {
        const p = document.createElement('p');
        parts.forEach(function (part) {
            if (typeof part === 'string') {
                p.appendChild(document.createTextNode(part));
            } else {
                p.appendChild(part);
            }
        });
        return p;
    }
    /**
     * One paragraph of the cookie note: the word's {name} markers are code (`codes`, through
     * QSComplexWizard.codeText()), and `link` ({ name, href, text }), when given, is the one marker
     * that is a link.
     */
    function _renderNoteParagraph(word, codes, link) {
        const pieces = link ? String(word).split('{' + link.name + '}') : [word];
        const parts = [];
        pieces.forEach(function (piece, i) {
            if (i > 0) parts.push(_renderAnchor(link.href, link.text));
            window.QSComplexWizard.codeText(piece, codes).forEach(function (part) { parts.push(part); });
        });
        return _renderParagraph(parts);
    }
    function _renderCookieNoteBody() {
        const body = document.createElement('div');
        body.className = 'qs-oauth-cookie-note__body';

        body.appendChild(_renderNoteParagraph(t('oauthButton.cookieNoteFlow'),
            { callback: '/auth/oauth/<provider>/callback' }));

        body.appendChild(_renderNoteParagraph(t('oauthButton.cookieNoteEmbed'), { iframe: '<iframe>' }));

        body.appendChild(_renderNoteParagraph(t('oauthButton.cookieNoteWorkarounds'),
            { target: 'target="_blank"', doc: 'ADMIN_PANEL.md §9.5' },
            {
                name: 'api',
                href: 'https://developer.mozilla.org/docs/Web/API/Storage_Access_API',
                text: t('oauthButton.cookieNoteApi'),
            }));

        return body;
    }

    function renderWizard(container) {
        const wrap = document.createElement('div');

        // ---- provider picker ------------------------------------------
        const providerGroup = _renderGroup();
        providerGroup.appendChild(_renderLabel(t('oauthButton.provider'), true));
        const providerSelect = document.createElement('select');
        providerSelect.className = 'admin-input';
        providerSelect.disabled = true;
        const loadingOption = document.createElement('option');
        loadingOption.textContent = t('oauthButton.providersLoading');
        loadingOption.value = '';
        providerSelect.appendChild(loadingOption);
        providerGroup.appendChild(providerSelect);
        providerGroup.appendChild(_renderHint(t('oauthButton.providerHint')));
        wrap.appendChild(providerGroup);

        // ---- setup-status panel ---------------------------------------
        const statusGroup = _renderGroup();
        statusGroup.appendChild(_renderLabel(t('oauthButton.setupStatus')));
        const statusPanel = document.createElement('div');
        statusPanel.className = 'qs-oauth-status-panel';
        statusPanel.textContent = t('oauthButton.statusIdle');
        statusGroup.appendChild(statusPanel);
        wrap.appendChild(statusGroup);

        // ---- label textKey --------------------------------------------
        const labelGroup = _renderGroup();
        labelGroup.appendChild(_renderLabel(t('oauthButton.labelKey'), true));
        const labelMount = document.createElement('div');
        labelGroup.appendChild(labelMount);
        labelGroup.appendChild(QSDom.el('p', { class: 'admin-hint' },
            window.QSComplexWizard.codeText(t('oauthButton.labelKeyHint'),
                { key: 'form.signin.<provider>' })));
        wrap.appendChild(labelGroup);

        // ---- optional icon class --------------------------------------
        const iconGroup = _renderGroup();
        iconGroup.appendChild(_renderLabel(t('oauthButton.iconClass')));
        const iconInput = document.createElement('input');
        iconInput.type = 'text';
        iconInput.className = 'admin-input';
        iconInput.placeholder = t('oauthButton.iconPlaceholder');
        iconInput.autocomplete = 'off';
        iconGroup.appendChild(iconInput);
        iconGroup.appendChild(_renderHint(t('oauthButton.iconHint')));
        wrap.appendChild(iconGroup);

        // ---- optional "redirect after login" --------------------------
        const returnGroup = _renderGroup();
        returnGroup.appendChild(_renderLabel(t('oauthButton.redirect')));
        const returnSelect = document.createElement('select');
        returnSelect.className = 'admin-input';
        const homeOpt = document.createElement('option');
        homeOpt.value = '';
        homeOpt.textContent = t('oauthButton.redirectHome');
        returnSelect.appendChild(homeOpt);
        returnSelect.disabled = true;
        const loadingOpt = document.createElement('option');
        loadingOpt.value = '';
        loadingOpt.textContent = t('oauthButton.routesLoading');
        returnSelect.appendChild(loadingOpt);
        returnGroup.appendChild(returnSelect);
        returnGroup.appendChild(_renderHint(t('oauthButton.redirectHint')));
        wrap.appendChild(returnGroup);

        loadRoutesOnce().then(function (routes) {
            // Remove the loading placeholder; keep the "(homepage)" option
            while (returnSelect.options.length > 1) returnSelect.remove(1);
            routes.forEach(function (r) {
                const opt = document.createElement('option');
                opt.value = r;
                opt.textContent = r;
                returnSelect.appendChild(opt);
            });
            returnSelect.disabled = false;
        });

        // ---- third-party cookie note ----------------------------------
        // BFF cookies are first-party in the standard OAuth flow (page →
        // provider → callback all hit the QuickSite-built site directly,
        // so the cookies set on the QuickSite origin are first-party from
        // the browser's perspective). The edge case where this breaks:
        // when the site is EMBEDDED in a cross-origin iframe (think
        // "your QuickSite site embedded on a partner's portal"). Safari
        // ITP / Firefox ETP treat cookies set from inside such iframes
        // as third-party and may block them — the OAuth flow then loses
        // its session between /start and /callback. Surfaced as a
        // collapsed details block so authors who don't care don't see
        // noise, but authors who do embed get the heads-up.
        const cookieNote = document.createElement('details');
        cookieNote.className = 'qs-oauth-cookie-note';
        const cookieSummary = document.createElement('summary');
        cookieSummary.className = 'qs-oauth-cookie-note__summary';
        cookieSummary.textContent = t('oauthButton.cookieNote');
        cookieNote.appendChild(cookieSummary);
        cookieNote.appendChild(_renderCookieNoteBody());
        wrap.appendChild(cookieNote);

        container.appendChild(wrap);

        // ---- labelKey picker init -------------------------------------
        const labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: t('oauthButton.labelPlaceholder'),
            value: '',
        });

        // ---- providers fetch + picker population ----------------------
        let providersData = [];
        let lastSelectedId = '';
        let labelHasBeenEdited = false;

        loadProvidersOnce().then(function (providers) {
            providersData = providers || [];
            QSDom.clear(providerSelect);
            if (providersData.length === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = t('oauthButton.noProviders');
                providerSelect.appendChild(opt);
                statusPanel.textContent = t('oauthButton.noProvidersStatus');
                return;
            }
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = t('oauthButton.pickProvider');
            providerSelect.appendChild(placeholder);
            providersData.forEach(function (p) {
                const o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.name + ' (' + p.id + ')';
                providerSelect.appendChild(o);
            });
            providerSelect.disabled = false;
        });

        // Treat any user input on the label picker as "manual edit" so we
        // stop auto-overwriting it when the provider changes.
        const labelMutationObserver = new MutationObserver(function () {
            // Picker writes its value back into a hidden field / input;
            // observing the mount catches any update. Mark dirty when the
            // current value differs from the default we computed.
            const cur = (labelPicker.getValue() || '').trim();
            if (cur !== '' && cur !== defaultLabelForCurrentProvider()) {
                labelHasBeenEdited = true;
            }
        });
        labelMutationObserver.observe(labelMount, { childList: true, subtree: true, characterData: true });

        function defaultLabelForCurrentProvider() {
            return providerSelect.value ? 'form.signin.' + providerSelect.value : '';
        }

        function findProvider(id) {
            for (let i = 0; i < providersData.length; i++) {
                if (providersData[i].id === id) return providersData[i];
            }
            return null;
        }

        function renderStatus(provider) {
            statusPanel.textContent = '';
            if (!provider) {
                statusPanel.textContent = t('oauthButton.statusIdle');
                return;
            }
            const setup = provider.setup || {};
            const lines = document.createElement('div');

            function row(text, isDone) {
                const r = document.createElement('div');
                r.className = 'qs-oauth-status-panel__row' + (isDone ? ' qs-oauth-status-panel__row--done' : '');
                r.textContent = (isDone ? '✓ ' : '○ ') + text;
                return r;
            }
            lines.appendChild(row(t('oauthButton.startRoute', { path: setup.start_route_path }), setup.start_route_exists));
            lines.appendChild(row(t('oauthButton.callbackRoute', { path: setup.callback_route_path }), setup.callback_route_exists));
            statusPanel.appendChild(lines);

            // Not fully set up means one route or both are missing: the summary counts them.
            const missingRoutes = (setup.start_route_exists ? 0 : 1) + (setup.callback_route_exists ? 0 : 1);
            const summary = document.createElement('div');
            summary.className = 'qs-oauth-status-panel__summary' + (setup.fully_set_up ? ' qs-oauth-status-panel__summary--warn' : '');
            summary.textContent = setup.fully_set_up
                ? t('oauthButton.statusReuse')
                : QSPanelWords.count('complexWizard.oauthButton.statusCreate', missingRoutes);
            statusPanel.appendChild(summary);

            if (provider.credentials_status !== 'set') {
                const reminder = document.createElement('div');
                reminder.className = 'qs-oauth-status-panel__reminder';
                reminder.setAttribute('role', 'alert');
                reminder.appendChild(QSDom.svgIcon('M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', 16));
                const reminderText = document.createElement('span');
                reminderText.textContent = t('oauthButton.noPreviewKeys', { provider: provider.name });
                reminder.appendChild(reminderText);
                statusPanel.appendChild(reminder);
            }
        }

        providerSelect.addEventListener('change', function () {
            const id = providerSelect.value;
            lastSelectedId = id;
            renderStatus(findProvider(id));
            // Re-set the default labelKey unless the author already edited
            // it to something custom.
            if (!labelHasBeenEdited) {
                const def = defaultLabelForCurrentProvider();
                if (typeof labelPicker.setValue === 'function') {
                    labelPicker.setValue(def);
                }
            }
        });

        // ---- contract back to host ------------------------------------
        function getConfig() {
            const cfg = {
                provider: providerSelect.value,
                labelKey: (labelPicker.getValue() || '').trim(),
            };
            const icon = iconInput.value.trim();
            if (icon !== '') cfg.iconClass = icon;
            const ret = returnSelect.value.trim();
            if (ret !== '') cfg.returnTo = ret;
            return cfg;
        }

        function validate() {
            const cfg = getConfig();
            if (!cfg.provider) return t('oauthButton.errors.provider');
            if (!cfg.labelKey) return t('oauthButton.errors.labelKey');
            return null;
        }

        async function preSubmit() {
            const cfg = getConfig();
            const provider = cfg.provider;
            if (!provider) throw new Error(t('oauthButton.errors.noProvider'));

            const adminApi = window.QuickSiteAdmin;
            if (!adminApi || typeof adminApi.apiRequest !== 'function') {
                throw new Error(t('oauthButton.errors.noAdminApi'));
            }

            const startPath    = 'auth/oauth/' + provider + '/start';
            const callbackPath = 'auth/oauth/' + provider + '/callback';

            // Step 1+2: addRoute (skip-if-exists). The route command returns
            // 400 + code 'route.already_exists' when the route is in place;
            // we treat that as success.
            for (const path of [startPath, callbackPath]) {
                const r = await adminApi.apiRequest('addRoute', 'POST', { route: path });
                if (!r || !r.ok) {
                    const code = (r && r.data && r.data.code) || '';
                    if (code !== 'route.already_exists') {
                        const msg = (r && r.data && r.data.message) || t('oauthButton.errors.routeFailed');
                        throw new Error(t('oauthButton.errors.setupStep', { command: 'addRoute', route: path, message: msg }));
                    }
                }
            }

            // Step 3+4: setRouteResolver for each. The resolver body is
            // just the kind + provider; callback_url defaults to the
            // /auth/oauth/<provider>/callback convention. setRouteResolver
            // overwrites whatever was there before — acceptable: this
            // wizard owns the OAuth setup, and re-running it should keep
            // configs consistent rather than silently leaving stale ones.
            const startResolverBody = {
                route: startPath,
                resolver: { kind: 'oauth-start', provider: provider },
            };
            const callbackResolverBody = {
                route: callbackPath,
                resolver: { kind: 'oauth-callback', provider: provider },
            };
            for (const body of [startResolverBody, callbackResolverBody]) {
                const r = await adminApi.apiRequest('setRouteResolver', 'POST', body);
                if (!r || !r.ok) {
                    const msg = (r && r.data && r.data.message) || t('oauthButton.errors.resolverFailed');
                    throw new Error(t('oauthButton.errors.setupStep', { command: 'setRouteResolver', route: body.route, message: msg }));
                }
            }
            // After preSubmit, succeeded or not, the host reads the Edit list again and
            // calls addComplexElement if it succeeded.
        }

        function destroy() {
            try { labelMutationObserver.disconnect(); } catch (e) {}
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, preSubmit: preSubmit, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['oauth-button'] = {
        label: t('oauthButton.label'),
        description: t('oauthButton.description'),
        renderWizard: renderWizard
    };
})();
