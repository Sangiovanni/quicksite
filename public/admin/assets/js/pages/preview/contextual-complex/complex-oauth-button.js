/**
 * OAuth Button wizard — kind 'oauth-button'.
 *
 * Picks a provider from the listOAuthProviders catalogue (union of
 * admin + per-project oauth-presets.json), shows whether the per-
 * provider routes already exist, and orchestrates the multi-step setup
 * on submit:
 *
 *   1. addRoute   /auth/oauth/<provider>/start    (skip-if-exists)
 *   2. addRoute   /auth/oauth/<provider>/callback (skip-if-exists)
 *   3. setRouteResolver on start    (oauth-start kind)
 *   4. setRouteResolver on callback (oauth-callback kind)
 *   5. addComplexElement (host handles step 5 after preSubmit resolves)
 *
 * Steps 1-4 fire in preSubmit (new optional wizard hook in preview.js
 * addComplexNode). When routes already exist the UX warns the author
 * the wizard will reuse them — see DESIGN_DECISIONS "OAuth-button
 * Complex Element shape" Q5.
 *
 * Server-side builder: secure/src/classes/complexElements/OAuthButton.php
 * Provider listing:    secure/management/command/listOAuthProviders.php
 */
(function () {
    'use strict';

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

    // Force a refresh on next render (preSubmit creates routes, so the
    // cached setup status goes stale).
    function invalidateProvidersCache() {
        _providersCache = null;
        _routesCache = null;
    }

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

    function _flattenLiteralRoutes(routes, prefix) {
        const out = [];
        if (!routes || typeof routes !== 'object') return out;
        prefix = prefix || '';
        Object.keys(routes).forEach(function (key) {
            if (key.charAt(0) === ':') return;
            const path = (key === 'home' && prefix === '') ? '/' : (prefix + '/' + key);
            out.push(path);
            const child = routes[key];
            if (child && typeof child === 'object' && !Array.isArray(child)) {
                _flattenLiteralRoutes(child, prefix + '/' + key).forEach(function (p) { out.push(p); });
            }
        });
        return out;
    }

    function _renderLabel(text, required) {
        const l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            const star = document.createElement('span');
            star.className = 'admin-text-danger';
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
    function _renderCode(text) {
        const c = document.createElement('code');
        c.textContent = text;
        return c;
    }
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
    function _renderCookieNoteBody() {
        const body = document.createElement('div');
        body.className = 'qs-oauth-cookie-note__body';

        body.appendChild(_renderParagraph([
            'In the standard flow (user clicks this button on your site, ',
            'gets redirected to the provider, comes back to ',
            _renderCode('/auth/oauth/<provider>/callback'),
            ') the OAuth cookies are FIRST-PARTY and work everywhere.',
        ]));

        body.appendChild(_renderParagraph([
            'If you EMBED this site in an ',
            _renderCode('<iframe>'),
            ' on a different origin (e.g. a partner portal) then Safari ',
            'Intelligent Tracking Prevention and Firefox Enhanced Tracking ',
            'Protection may treat the OAuth cookies as third-party and ',
            'BLOCK them — the user appears to log in but the callback ',
            'drops their session.',
        ]));

        body.appendChild(_renderParagraph([
            'Workarounds when embedding: open the sign-in flow in a new ',
            'tab/popup (',
            _renderCode('target="_blank"'),
            ') so the cookies are set in a first-party context, or use ',
            _renderAnchor(
                'https://developer.mozilla.org/docs/Web/API/Storage_Access_API',
                'the Storage Access API'
            ),
            ' to request cookie access. Full guidance lives in ',
            _renderCode('ADMIN_PANEL.md §9.5'),
            ' under the OAuth tier.',
        ]));

        return body;
    }

    function renderWizard(container) {
        const wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--oauth-button';

        // ---- provider picker ------------------------------------------
        const providerGroup = _renderGroup();
        providerGroup.appendChild(_renderLabel('Provider', true));
        const providerSelect = document.createElement('select');
        providerSelect.className = 'admin-input';
        providerSelect.disabled = true;
        const loadingOption = document.createElement('option');
        loadingOption.textContent = 'Loading providers…';
        loadingOption.value = '';
        providerSelect.appendChild(loadingOption);
        providerGroup.appendChild(providerSelect);
        providerGroup.appendChild(_renderHint(
            'Pick a provider from oauth-presets.json. Add new providers '
            + 'at secure/projects/<active>/data/oauth-presets.json or '
            + 'secure/admin/config/oauth-presets.json.'
        ));
        wrap.appendChild(providerGroup);

        // ---- setup-status panel ---------------------------------------
        const statusGroup = _renderGroup();
        statusGroup.appendChild(_renderLabel('Setup status'));
        const statusPanel = document.createElement('div');
        statusPanel.className = 'qs-oauth-status-panel';
        statusPanel.textContent = 'Pick a provider to see what the wizard will create.';
        statusGroup.appendChild(statusPanel);
        wrap.appendChild(statusGroup);

        // ---- label textKey --------------------------------------------
        const labelGroup = _renderGroup();
        labelGroup.appendChild(_renderLabel('Button label key', true));
        const labelMount = document.createElement('div');
        labelGroup.appendChild(labelMount);
        labelGroup.appendChild(_renderHint(
            'Translation key for the button label. Defaults to '
            + '"form.signin.<provider>" when you pick a provider.'
        ));
        wrap.appendChild(labelGroup);

        // ---- optional icon class --------------------------------------
        const iconGroup = _renderGroup();
        iconGroup.appendChild(_renderLabel('Icon CSS class (optional)'));
        const iconInput = document.createElement('input');
        iconInput.type = 'text';
        iconInput.className = 'admin-input';
        iconInput.placeholder = 'e.g. icon-google';
        iconInput.autocomplete = 'off';
        iconGroup.appendChild(iconInput);
        iconGroup.appendChild(_renderHint(
            'Adds <span class="qs-oauth-button__icon <yourClass>" '
            + 'aria-hidden="true"></span> inside the link. Leave empty '
            + 'for a label-only button.'
        ));
        wrap.appendChild(iconGroup);

        // ---- optional "redirect after login" --------------------------
        const returnGroup = _renderGroup();
        returnGroup.appendChild(_renderLabel('Redirect after login (optional)'));
        const returnSelect = document.createElement('select');
        returnSelect.className = 'admin-input';
        const homeOpt = document.createElement('option');
        homeOpt.value = '';
        homeOpt.textContent = '(homepage — / by default)';
        returnSelect.appendChild(homeOpt);
        returnSelect.disabled = true;
        const loadingOpt = document.createElement('option');
        loadingOpt.value = '';
        loadingOpt.textContent = 'Loading routes…';
        returnSelect.appendChild(loadingOpt);
        returnGroup.appendChild(returnSelect);
        returnGroup.appendChild(_renderHint(
            'Path on this site users land on after sign-in. Restricted to '
            + 'existing routes (server rejects unknown / off-site paths to '
            + 'prevent open-redirect abuse). To land on a :param route, '
            + 'leave this empty and set ?return=/path/manually via the '
            + 'element params editor after insertion.'
        ));
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

        // ---- third-party cookie note (Slice 6) ------------------------
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
        cookieSummary.textContent = 'Third-party cookies note — relevant if you embed this site in iframes';
        cookieNote.appendChild(cookieSummary);
        cookieNote.appendChild(_renderCookieNoteBody());
        wrap.appendChild(cookieNote);

        container.appendChild(wrap);

        // ---- labelKey picker init -------------------------------------
        const labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: 'e.g. form.signin.google',
            value: '',
        });

        // ---- providers fetch + picker population ----------------------
        let providersData = [];
        let lastSelectedId = '';
        let labelHasBeenEdited = false;

        loadProvidersOnce().then(function (providers) {
            providersData = providers || [];
            providerSelect.innerHTML = '';
            if (providersData.length === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '(no providers configured)';
                providerSelect.appendChild(opt);
                statusPanel.textContent = 'No providers found in oauth-presets.json. Add one and reload.';
                return;
            }
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Pick a provider…';
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
                statusPanel.textContent = 'Pick a provider to see what the wizard will create.';
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
            lines.appendChild(row('Start route ' + setup.start_route_path, setup.start_route_exists));
            lines.appendChild(row('Callback route ' + setup.callback_route_path, setup.callback_route_exists));
            statusPanel.appendChild(lines);

            const summary = document.createElement('div');
            summary.className = 'qs-oauth-status-panel__summary' + (setup.fully_set_up ? ' qs-oauth-status-panel__summary--warn' : '');
            summary.textContent = setup.fully_set_up
                ? 'Routes already exist — wizard will reuse them and add a button on this page.'
                : 'Wizard will create the missing route(s) + attach oauth-start / oauth-callback resolvers, then insert the button.';
            statusPanel.appendChild(summary);

            const reminder = document.createElement('div');
            reminder.className = 'qs-oauth-status-panel__reminder';
            reminder.textContent = 'Remember: fill in client_id + client_secret for "'
                + provider.id + '" in secure/admin/config/oauth-secrets.php '
                + 'OR secure/projects/<active>/data/oauth-secrets.json before clicking the button.';
            statusPanel.appendChild(reminder);
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
            if (!cfg.provider) return 'Pick an OAuth provider.';
            if (!cfg.labelKey) return 'Provide a label translation key (e.g. form.signin.google).';
            return null;
        }

        async function preSubmit() {
            const cfg = getConfig();
            const provider = cfg.provider;
            if (!provider) throw new Error('No provider selected.');

            const adminApi = window.QuickSiteAdmin;
            if (!adminApi || typeof adminApi.apiRequest !== 'function') {
                throw new Error('QuickSiteAdmin not available — cannot run setup.');
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
                        const msg = (r && r.data && r.data.message) || 'Route creation failed';
                        throw new Error('addRoute ' + path + ': ' + msg);
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
                    const msg = (r && r.data && r.data.message) || 'setRouteResolver failed';
                    throw new Error('setRouteResolver ' + body.route + ': ' + msg);
                }
            }

            // After preSubmit succeeds the host calls addComplexElement;
            // the new routes are immediately visible to subsequent picker
            // renders, so dump the cache.
            invalidateProvidersCache();
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
        label: 'Sign in with OAuth',
        description: 'A &lt;a&gt; "Sign in with X" button. The wizard also creates the start + callback routes and attaches the matching oauth resolvers in one pass — only the secrets file needs manual setup.',
        renderWizard: renderWizard
    };
})();
