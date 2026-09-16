/**
 * API Registry Page JavaScript
 * Manages external API definitions and endpoints for the QuickSite admin interface.
 *
 * Strings come from window.QS_APIS_I18N, emitted by apis.php: the `apis` and
 * `common` translation sub-trees under the same dot paths PHP uses. Read it
 * through t() below, never directly.
 *
 * DOM is built with createElement + textContent through QSDom and named
 * _render* helpers, per the CLAUDE.md HTML-in-JS hygiene rule.
 *
 * @version 1.0.0
 */

(function() {
    'use strict';

    /**
     * Resolve one admin string by its FULL dot path.
     *
     * A path that resolves to nothing returns THE PATH ITSELF, so an unset
     * string is visible on screen and findable by a scan instead of being
     * hidden behind an English fallback.
     *
     * @param {string} path      e.g. 'apis.form.paramName'
     * @param {Object} [params]  :name markers to substitute, as PHP's t() does
     * @returns {string}
     */
    function t(path, params) {
        let node = window.QS_APIS_I18N || {};
        for (const part of String(path).split('.')) {
            if (node === null || typeof node !== 'object' || !(part in node)) return path;
            node = node[part];
        }
        if (typeof node !== 'string') return path;
        let value = node;
        if (params) {
            for (const name of Object.keys(params)) {
                value = value.split(':' + name).join(String(params[name]));
            }
        }
        return value;
    }

    // State
    let apisData = {};
    let currentTestEndpoint = null;
    let pendingDelete = null;
    const AUTH_TOKEN_STORAGE_KEY = 'qs_api_auth_tokens';

    // DOM Ready
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        bindEvents();
        loadApis();
    }

    // =========================================================================
    // Event Binding
    // =========================================================================

    function bindEvents() {
        // Toolbar actions
        document.getElementById('btn-add-api')?.addEventListener('click', () => openApiModal('add'));
        document.getElementById('btn-add-api-empty')?.addEventListener('click', () => openApiModal('add'));
        document.getElementById('btn-refresh')?.addEventListener('click', loadApis);
        document.getElementById('btn-import')?.addEventListener('click', openImportModal);
        document.getElementById('btn-export')?.addEventListener('click', exportApis);

        // API form
        document.getElementById('form-api')?.addEventListener('submit', handleApiSubmit);
        document.getElementById('api-auth-type')?.addEventListener('change', updateAuthFields);
        document.getElementById('api-token-source-prefix')?.addEventListener('change', updateStorageWarning);

        // Endpoint form
        document.getElementById('form-endpoint')?.addEventListener('submit', handleEndpointSubmit);
        document.getElementById('btn-add-endpoint-param')?.addEventListener('click', () => addParamRow());
        document.getElementById('endpoint-callable-from')?.addEventListener('change', updateCallableFromAutoPreview);

        // Schema JSON validation & formatting
        document.getElementById('endpoint-request-schema')?.addEventListener('input', (e) => validateJsonField(e.target, 'request-schema-status'));
        document.getElementById('endpoint-response-schema')?.addEventListener('input', (e) => validateJsonField(e.target, 'response-schema-status'));
        document.querySelectorAll('[data-format-json]').forEach(btn => {
            btn.addEventListener('click', () => formatJsonField(btn.dataset.formatJson));
        });
        
        // Schema template buttons
        document.querySelectorAll('[data-schema-template]').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.schemaTemplate;
                const textareaId = type === 'request' ? 'endpoint-request-schema' : 'endpoint-response-schema';
                insertSchemaTemplate(textareaId, type);
            });
        });

        // Test panel
        document.getElementById('btn-run-test')?.addEventListener('click', runTest);
        
        // Sync raw JSON with form changes
        document.getElementById('test-request-body')?.addEventListener('input', syncFormFromRawJson);
        document.getElementById('test-query-params')?.addEventListener('input', syncFormFromRawJson);

        // Import (two-step flow: paste → preview → confirm)
        document.getElementById('btn-import-next')?.addEventListener('click', handleImportNext);
        document.getElementById('btn-import-back')?.addEventListener('click', handleImportBack);
        document.getElementById('btn-import-confirm')?.addEventListener('click', handleImportConfirm);
        document.getElementById('btn-load-example-ours')?.addEventListener('click', () => loadExampleJson('ours'));

        // Delete confirmation
        document.getElementById('btn-confirm-delete')?.addEventListener('click', confirmDelete);

        // Modal close handlers
        document.querySelectorAll('[data-dismiss="modal"]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.closest('.admin-modal')));
        });

        // Close modals on backdrop click
        document.querySelectorAll('.admin-modal__backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', () => closeModal(backdrop.closest('.admin-modal')));
        });

        // Close modals on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.admin-modal[style*="flex"]');
                if (openModal) closeModal(openModal);
            }
        });

        // Toggle expand/collapse styling (delegated)
        document.addEventListener('click', (e) => {
            const card = e.target.closest('.api-card');
            if (card) {
                const body = card.querySelector('.api-card__body');
                const chevron = card.querySelector('.api-card__chevron');
                if (card.classList.contains('api-card--expanded')) {
                    body.style.display = 'block';
                    chevron.style.transform = 'rotate(90deg)';
                } else {
                    body.style.display = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                }
            }
        });
    }

    // =========================================================================
    // API Loading
    // =========================================================================

    async function loadApis() {
        showLoading(true);
        
        try {
            const response = await QuickSiteAdmin.apiRequest('listApiEndpoints', 'GET');
            
            if (response.ok && response.data) {
                const apisList = response.data.data?.apis || response.data.apis || [];
                const totalEndpoints = response.data.data?.totalEndpoints || response.data.totalEndpoints || 0;
                
                // Convert array to object keyed by apiId for easy lookups
                apisData = {};
                for (const api of apisList) {
                    apisData[api.apiId] = api;
                }
                
                updateStats(apisData, totalEndpoints);
                renderApisList(apisData);
            } else {
                showToast(response.data?.message || t('apis.toast.loadFailed'), 'error');
            }
        } catch (error) {
            console.error('Failed to load APIs:', error);
            showToast(t('apis.toast.loadFailedDetail', { message: error.message }), 'error');
        } finally {
            showLoading(false);
        }
    }

    function showLoading(show) {
        // While loading: hide both empty and list, show spinner.
        // After loading: hide the spinner only — renderApisList owns
        // the list/empty toggle so the final view matches the data.
        document.getElementById('apis-loading').style.display = show ? 'block' : 'none';
        if (show) {
            document.getElementById('apis-empty').style.display = 'none';
            document.getElementById('apis-list').style.display = 'none';
        }
    }

    function updateStats(apis, totalEndpoints) {
        const apiCount = Object.keys(apis).length;
        let getCount = 0;
        let postCount = 0;

        Object.values(apis).forEach(api => {
            (api.endpoints || []).forEach(ep => {
                if (ep.method === 'GET') getCount++;
                else if (ep.method === 'POST') postCount++;
            });
        });

        document.getElementById('stat-apis').textContent = apiCount;
        document.getElementById('stat-endpoints').textContent = totalEndpoints;
        document.getElementById('stat-get').textContent = getCount;
        document.getElementById('stat-post').textContent = postCount;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    function renderApisList(apis) {
        const container = document.getElementById('apis-list');
        const apiIds = Object.keys(apis);

        // Always clear stale cards first — without this, deleting the
        // last API leaves the previous markup behind when we early-return.
        container.innerHTML = '';

        if (apiIds.length === 0) {
            container.style.display = 'none';
            document.getElementById('apis-empty').style.display = 'block';
            return;
        }

        document.getElementById('apis-empty').style.display = 'none';
        container.style.display = 'block';

        apiIds.forEach(apiId => container.appendChild(renderApiCard(apiId, apis[apiId])));

        // Bind card events
        container.querySelectorAll('.api-card__header').forEach(header => {
            header.addEventListener('click', (e) => {
                if (!e.target.closest('button')) {
                    header.closest('.api-card').classList.toggle('api-card--expanded');
                }
            });
        });

        // Bind action buttons
        container.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', handleAction);
        });
        
        // Bind auth token toggle buttons
        container.querySelectorAll('.api-auth-token__toggle').forEach(btn => {
            btn.addEventListener('click', toggleTokenVisibility);
        });
        
        // Bind auth token save buttons
        container.querySelectorAll('.api-auth-token__save').forEach(btn => {
            btn.addEventListener('click', saveApiAuthToken);
        });
    }

    /**
     * One API's card: header, optional auth-token row, endpoint table.
     *
     * Every data-* attribute here is set with setAttribute rather than spliced
     * into markup. That is what retires the 14 sites where the TEXT escaper
     * stood in an ATTRIBUTE slot: escapeHtml deliberately leaves quotes alone
     * (core/utils.js says so), so `data-api="${escapeHtml(id)}"` would break
     * the attribute on a value carrying a quote. There is no escaper to pick
     * wrongly once the value goes through setAttribute.
     *
     * @returns {HTMLElement} one .api-card
     */
    function renderApiCard(apiId, api) {
        const endpoints = api.endpoints || [];
        const hasAuth = !!(api.auth?.type && api.auth.type !== 'none');

        const tbody = QSDom.el('tbody');
        if (endpoints.length > 0) {
            endpoints.forEach(ep => tbody.appendChild(renderEndpointRow(apiId, ep)));
        } else {
            tbody.appendChild(QSDom.el('tr', null, [
                QSDom.el('td', {
                    colspan: '5',
                    class: 'admin-text-muted',
                    style: 'text-align: center; padding: var(--space-md);',
                    text: t('apis.noEndpoints')
                })
            ]));
        }

        const heading = QSDom.el('h3', {
            style: 'margin: 0; display: flex; align-items: center; gap: var(--space-xs);'
        }, [api.name || apiId]);
        if (hasAuth) {
            heading.appendChild(QSDom.el('span', {
                class: 'admin-badge admin-badge--info', text: api.auth.type
            }));
        }

        const body = QSDom.el('div', { class: 'api-card__body', style: 'display: none;' });
        if (api.description) {
            body.appendChild(QSDom.el('p', {
                class: 'admin-text-muted',
                style: 'margin-bottom: var(--space-md);',
                text: api.description
            }));
        }
        if (hasAuth) body.appendChild(_renderAuthTokenRow(apiId, api.auth.type));
        body.appendChild(QSDom.el('table', { class: 'admin-table admin-table--striped' }, [
            QSDom.el('thead', null, [
                QSDom.el('tr', null, [
                    QSDom.el('th', { style: 'width: 80px;', text: t('apis.form.method') }),
                    QSDom.el('th', { text: t('apis.columns.endpoint') }),
                    QSDom.el('th', { text: t('apis.form.path') }),
                    QSDom.el('th', { style: 'width: 120px;', text: t('apis.columns.actions') })
                ])
            ]),
            tbody
        ]));

        return QSDom.el('div', {
            class: 'admin-card api-card',
            dataset: { apiId: apiId }
        }, [
            QSDom.el('div', {
                class: 'api-card__header',
                style: 'cursor: pointer; display: flex; align-items: center; justify-content: space-between;'
            }, [
                QSDom.el('div', {
                    style: 'display: flex; align-items: center; gap: var(--space-sm);'
                }, [
                    QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronRight, 20, 'api-card__chevron'),
                    QSDom.el('div', null, [
                        heading,
                        QSDom.el('p', {
                            class: 'admin-text-muted',
                            style: 'margin: 0; font-size: var(--font-sm);'
                        }, [
                            QSDom.el('code', { text: apiId }),
                            ' · ' + api.baseUrl + ' · ' + t(endpoints.length !== 1
                                ? 'apis.endpointCountMany'
                                : 'apis.endpointCountOne', { count: endpoints.length })
                        ])
                    ])
                ]),
                QSDom.el('div', { style: 'display: flex; gap: var(--space-xs);' }, [
                    _renderApiAction('add-endpoint', apiId, t('apis.addEndpoint'),
                        QuickSiteUtils.ICON_PATHS.plus),
                    _renderApiAction('edit-api', apiId, t('apis.editApi'),
                        QuickSiteUtils.ICON_PATHS.edit),
                    _renderApiAction('delete-api', apiId, t('apis.deleteApi'),
                        QuickSiteUtils.ICON_PATHS.trash, 'admin-btn--danger-hover')
                ])
            ]),
            body
        ]);
    }

    /** One card-header action button. @returns {HTMLElement} */
    function _renderApiAction(action, apiId, title, iconPath, extraClass) {
        return QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--sm admin-btn--ghost'
                + (extraClass ? ' ' + extraClass : ''),
            'data-action': action,
            'data-api': apiId,
            title: title
        }, [QSDom.iconEl(iconPath, 16)]);
    }

    /**
     * The saved-token row shown on an API that declares authentication.
     * @returns {HTMLElement} one .api-auth-token
     */
    function _renderAuthTokenRow(apiId, authType) {
        const eyeOff = QSDom.iconEl(QuickSiteUtils.ICON_PATHS.eyeOff, 16, 'icon-eye-off');
        if (eyeOff) eyeOff.style.display = 'none';
        return QSDom.el('div', {
            class: 'api-auth-token',
            style: 'margin-bottom: var(--space-md); padding: var(--space-sm); background: var(--bg-secondary); border-radius: var(--radius-md); display: flex; align-items: center; gap: var(--space-sm);'
        }, [
            QSDom.el('label', {
                style: 'font-weight: 500; white-space: nowrap;',
                text: t('apis.authTokenLabel')
            }),
            QSDom.el('div', { style: 'flex: 1; display: flex; gap: var(--space-xs);' }, [
                QSDom.el('input', {
                    type: 'password',
                    class: 'admin-input admin-input--sm api-auth-token__input',
                    'data-api': apiId,
                    value: loadAuthToken(apiId),
                    placeholder: t('apis.authTokenPlaceholder', { type: authType }),
                    style: 'flex: 1;'
                }),
                QSDom.el('button', {
                    type: 'button',
                    class: 'admin-btn admin-btn--sm admin-btn--ghost api-auth-token__toggle',
                    'data-api': apiId,
                    title: t('apis.test.showHide')
                }, [
                    QSDom.iconEl(QuickSiteUtils.ICON_PATHS.eye, 16, 'icon-eye'),
                    eyeOff
                ]),
                QSDom.el('button', {
                    type: 'button',
                    class: 'admin-btn admin-btn--sm admin-btn--ghost api-auth-token__save',
                    'data-api': apiId,
                    title: t('apis.test.saveToken')
                }, [QSDom.iconEl(QuickSiteUtils.ICON_PATHS.save, 16)])
            ])
        ]);
    }

    function renderEndpointRow(apiId, endpoint) {
        const methodClass = {
            'GET': 'admin-badge--success',
            'POST': 'admin-badge--primary',
            'PUT': 'admin-badge--warning',
            'PATCH': 'admin-badge--warning',
            'DELETE': 'admin-badge--danger'
        }[endpoint.method] || 'admin-badge--default';

        // Auth badge based on effective auth
        // No auth property = public (none), like OpenAPI security: []
        const api = apisData[apiId];
        const apiAuthType = api?.auth?.type || 'none';
        const endpointAuth = endpoint.auth || 'none'; // undefined = public
        
        let authLabel = null;
        if (endpointAuth === 'required') {
            // Explicitly requires auth
            authLabel = apiAuthType !== 'none' ? apiAuthType : 'required';
        } else if (endpointAuth === 'inherit' && apiAuthType !== 'none') {
            // Inherits from API (and API has auth)
            authLabel = apiAuthType;
        }
        // endpointAuth === 'none' is public: no badge.

        const idCell = QSDom.el('td', null, [
            QSDom.el('code', { text: endpoint.id }),
            ' '
        ]);
        if (authLabel) {
            idCell.appendChild(QSDom.el('span', {
                class: 'admin-badge admin-badge--info',
                title: t('apis.authBadgeTitle', { type: authLabel }),
                style: 'font-size: 0.7em;',
                text: '🔐 ' + authLabel
            }));
        }
        idCell.appendChild(QSDom.el('br'));
        idCell.appendChild(QSDom.el('small', {
            class: 'admin-text-muted', text: endpoint.name || ''
        }));

        return QSDom.el('tr', null, [
            QSDom.el('td', null, [
                QSDom.el('span', {
                    class: 'admin-badge ' + methodClass, text: endpoint.method
                })
            ]),
            idCell,
            QSDom.el('td', null, [QSDom.el('code', { text: endpoint.path })]),
            QSDom.el('td', null, [
                QSDom.el('div', { style: 'display: flex; gap: var(--space-xs);' }, [
                    _renderEndpointAction('test-endpoint', apiId, endpoint.id,
                        t('apis.actions.test'), QuickSiteUtils.ICON_PATHS.play, 14),
                    _renderEndpointAction('edit-endpoint', apiId, endpoint.id,
                        t('common.edit'), QuickSiteUtils.ICON_PATHS.edit, 14),
                    _renderEndpointAction('delete-endpoint', apiId, endpoint.id,
                        t('common.delete'), QuickSiteUtils.ICON_PATHS.trash, 14,
                        'admin-btn--danger-hover')
                ])
            ])
        ]);
    }

    /** One endpoint-row action button. @returns {HTMLElement} */
    function _renderEndpointAction(action, apiId, endpointId, title, iconPath, size, extraClass) {
        return QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--xs admin-btn--ghost'
                + (extraClass ? ' ' + extraClass : ''),
            'data-action': action,
            'data-api': apiId,
            'data-endpoint': endpointId,
            title: title
        }, [QSDom.iconEl(iconPath, size)]);
    }

    // =========================================================================
    // Action Handlers
    // =========================================================================

    function handleAction(e) {
        const btn = e.currentTarget;
        const action = btn.dataset.action;
        const apiId = btn.dataset.api;
        const endpointId = btn.dataset.endpoint;

        switch (action) {
            case 'add-endpoint':
                openEndpointModal('add', apiId);
                break;
            case 'edit-api':
                openApiModal('edit', apiId);
                break;
            case 'delete-api':
                promptDeleteApi(apiId);
                break;
            case 'test-endpoint':
                openTestModal(apiId, endpointId);
                break;
            case 'edit-endpoint':
                openEndpointModal('edit', apiId, endpointId);
                break;
            case 'delete-endpoint':
                promptDeleteEndpoint(apiId, endpointId);
                break;
        }
    }

    // =========================================================================
    // API Modal
    // =========================================================================

    function openApiModal(mode, apiId = null) {
        const modal = document.getElementById('modal-api');
        const form = document.getElementById('form-api');
        const title = document.getElementById('modal-api-title');
        
        form.reset();
        document.getElementById('api-edit-mode').value = mode;
        document.getElementById('api-original-id').value = apiId || '';

        if (mode === 'edit' && apiId && apisData[apiId]) {
            const api = apisData[apiId];
            title.textContent = t('apis.editApi');
            document.getElementById('api-id').value = apiId;
            document.getElementById('api-name').value = api.name || '';
            document.getElementById('api-base-url').value = api.baseUrl || '';
            document.getElementById('api-description').value = api.description || '';
            document.getElementById('api-auth-type').value = api.auth?.type || 'none';
            
            if (api.auth?.tokenSource) {
                const parts = api.auth.tokenSource.split(':');
                document.getElementById('api-token-source-prefix').value = parts[0] || 'localStorage';
                document.getElementById('api-token-source-key').value = parts.slice(1).join(':') || '';
            }

            populateRefreshEndpointDropdown(api.auth?.refreshEndpoint || '');
            if (api.auth?.refreshEndpoint) {
                document.getElementById('api-refresh-endpoint').value = api.auth.refreshEndpoint;
            }
            if (api.auth?.refreshTokenSource) {
                const rParts = api.auth.refreshTokenSource.split(':');
                document.getElementById('api-refresh-token-source-prefix').value = rParts[0] || 'localStorage';
                document.getElementById('api-refresh-token-source-key').value = rParts.slice(1).join(':') || '';
            }
            document.getElementById('api-refresh-body-field').value = api.auth?.refreshTokenBodyField || '';
            document.getElementById('api-refresh-response-token-path').value = api.auth?.responseTokenPath || '';
            document.getElementById('api-refresh-response-refresh-token-path').value = api.auth?.responseRefreshTokenPath || '';
            // Open the <details> when any refresh field is wired, so the
            // existing config is visible at a glance on edit.
            document.getElementById('auth-refresh-group').open = !!api.auth?.refreshEndpoint;
        } else {
            title.textContent = t('apis.addApi');
            populateRefreshEndpointDropdown('');
            document.getElementById('auth-refresh-group').open = false;
        }

        updateAuthFields();
        openModal(modal);
        document.getElementById('api-id').focus();
    }

    function updateAuthFields() {
        const authType = document.getElementById('api-auth-type').value;
        const tokenGroup = document.getElementById('auth-token-source-group');
        const refreshGroup = document.getElementById('auth-refresh-group');

        // 'cookie' (Pattern X — same-origin session cookies) doesn't
        // need a tokenSource; the browser owns the cookie. Treat it
        // like 'none' for the storage-location group's purposes.
        const needsTokenSource = authType !== 'none' && authType !== 'cookie';

        if (!needsTokenSource) {
            tokenGroup.style.display = 'none';
        } else {
            tokenGroup.style.display = 'block';
            updateStorageWarning();
        }

        // Refresh is meaningful only for bearer auth; collapse + hide
        // for any other type so the form stays uncluttered.
        if (refreshGroup) {
            refreshGroup.style.display = authType === 'bearer' ? 'block' : 'none';
            if (authType !== 'bearer') refreshGroup.open = false;
        }
    }

    /**
     * Build the refresh-endpoint <select> from apisData (all registered
     * @apiId/endpointId pairs). Called on form open so the list always
     * reflects the current registry.
     */
    function populateRefreshEndpointDropdown(selected) {
        const select = document.getElementById('api-refresh-endpoint');
        if (!select) return;

        const noneText = t('apis.form.refreshNone');
        // Drop existing dynamic options but keep the empty default.
        while (select.firstChild) select.removeChild(select.firstChild);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = noneText;
        select.appendChild(placeholder);

        const refs = [];
        for (const apiId in apisData) {
            if (!Object.prototype.hasOwnProperty.call(apisData, apiId)) continue;
            const endpoints = (apisData[apiId] && apisData[apiId].endpoints) || [];
            for (const ep of endpoints) {
                if (ep && ep.id) refs.push('@' + apiId + '/' + ep.id);
            }
        }
        refs.sort();
        for (const ref of refs) {
            const opt = document.createElement('option');
            opt.value = ref;
            opt.textContent = ref;
            select.appendChild(opt);
        }

        // If the saved value isn't in the current registry (e.g. endpoint
        // was deleted), still surface it so the user sees the staleness.
        if (selected && !refs.includes(selected)) {
            const stale = document.createElement('option');
            stale.value = selected;
            stale.textContent = selected + ' (missing)';
            select.appendChild(stale);
        }
    }
    
    function updateStorageWarning() {
        const prefix = document.getElementById('api-token-source-prefix').value;
        const warning = document.getElementById('auth-config-warning');
        if (warning) {
            warning.style.display = prefix === 'config' ? 'block' : 'none';
        }
    }

    /**
     * Validate that a parsed schema has the JSON-Schema shape downstream
     * features expect: an object with a top-level `type`, and `properties`
     * when type is "object". Catches the most common authoring mistake —
     * pasting the whole `{"responseSchema": {...}}` envelope instead of
     * the inner schema. Returns null when OK, or a user-facing error.
     */
    function _validateSchemaShape(schema, label) {
        if (schema === null || typeof schema !== 'object' || Array.isArray(schema)) {
            return t('apis.schema.mustBeObject', { label: label });
        }
        // Double-wrap detection: the user pasted the outer container.
        if (!schema.type && (schema.responseSchema || schema.requestSchema)) {
            const wrapKey = schema.responseSchema ? 'responseSchema' : 'requestSchema';
            return t('apis.schema.doubleWrapped', { label: label, wrapKey: wrapKey });
        }
        if (!schema.type) {
            return t('apis.schema.needsType', { label: label });
        }
        const typeIsValid = typeof schema.type === 'string' || Array.isArray(schema.type);
        if (!typeIsValid) {
            return t('apis.schema.typeShape', { label: label });
        }
        const isObjectType = schema.type === 'object'
            || (Array.isArray(schema.type) && schema.type.indexOf('object') !== -1);
        if (isObjectType && (!schema.properties || typeof schema.properties !== 'object' || Array.isArray(schema.properties))) {
            return t('apis.schema.needsProperties', { label: label });
        }
        return null;
    }

    async function handleApiSubmit(e) {
        e.preventDefault();
        
        const mode = document.getElementById('api-edit-mode').value;
        const originalId = document.getElementById('api-original-id').value;
        
        const apiId = document.getElementById('api-id').value.trim();
        const name = document.getElementById('api-name').value.trim();
        const baseUrl = document.getElementById('api-base-url').value.trim();
        const description = document.getElementById('api-description').value.trim();
        const authType = document.getElementById('api-auth-type').value;
        
        // Build auth config. 'cookie' goes as { type: 'cookie' } only —
        // no tokenSource, since the browser owns the session cookie.
        let auth = { type: authType };
        if (authType !== 'none' && authType !== 'cookie') {
            const prefix = document.getElementById('api-token-source-prefix').value;
            const key = document.getElementById('api-token-source-key').value.trim();
            auth.tokenSource = key ? `${prefix}:${key}` : `${prefix}:token`;
        }

        // Refresh config (Tier 2). Only collected when type === 'bearer'.
        // Symmetric requirement: the four primary fields move together.
        // responseRefreshTokenPath is optional (only set when the endpoint
        // rotates the refresh token).
        if (authType === 'bearer') {
            const rEndpoint = document.getElementById('api-refresh-endpoint').value.trim();
            const rPrefix = document.getElementById('api-refresh-token-source-prefix').value;
            const rKey = document.getElementById('api-refresh-token-source-key').value.trim();
            const rBody = document.getElementById('api-refresh-body-field').value.trim();
            const rPath = document.getElementById('api-refresh-response-token-path').value.trim();
            const rRefreshPath = document.getElementById('api-refresh-response-refresh-token-path').value.trim();

            const anyRefresh = rEndpoint || rKey || rBody || rPath || rRefreshPath;
            if (anyRefresh) {
                const missing = [];
                if (!rEndpoint) missing.push(t('apis.refreshMissing.endpoint'));
                if (!rKey) missing.push(t('apis.refreshMissing.storageKey'));
                if (!rBody) missing.push(t('apis.refreshMissing.bodyField'));
                if (!rPath) missing.push(t('apis.refreshMissing.tokenPath'));
                if (missing.length) {
                    showToast(t('apis.toast.refreshIncomplete', { list: missing.join(', ') }), 'error');
                    return;
                }
                auth.refreshEndpoint = rEndpoint;
                auth.refreshTokenSource = `${rPrefix}:${rKey}`;
                auth.refreshTokenBodyField = rBody;
                auth.responseTokenPath = rPath;
                if (rRefreshPath) auth.responseRefreshTokenPath = rRefreshPath;
            }
        }

        // Prepare command params. Always send `auth` — even { type: 'none' }.
        // editApi treats an OMITTED auth as "leave unchanged", so sending
        // undefined on None made a previously-set bearer/apiKey/etc stick
        // (you couldn't switch an API back to None). auth is always built
        // as { type: authType } above, and editApi does a full replacement,
        // so this also drops stale tokenSource/refresh fields on downgrade.
        const params = {
            apiId: apiId,
            name: name,
            baseUrl: baseUrl,
            description: description,
            auth: auth
        };

        try {
            let response;
            if (mode === 'edit') {
                // For edit, we need to handle ID change
                if (originalId !== apiId) {
                    // Delete old, create new
                    await QuickSiteAdmin.apiRequest('deleteApi', 'POST', { apiId: originalId });
                }
                response = await QuickSiteAdmin.apiRequest('editApi', 'POST', params);
            } else {
                response = await QuickSiteAdmin.apiRequest('addApi', 'POST', params);
            }

            if (response.ok) {
                showToast(response.data?.message || t('apis.toast.apiSaved'), 'success');
                closeModal(document.getElementById('modal-api'));
                loadApis();
            } else {
                showToast(response.data?.message || t('apis.toast.apiSaveFailed'), 'error');
            }
        } catch (error) {
            console.error('Failed to save API:', error);
            showToast(t('apis.toast.apiSaveFailedDetail', { message: error.message }), 'error');
        }
    }

    // =========================================================================
    // Endpoint Modal
    // =========================================================================

    function openEndpointModal(mode, apiId, endpointId = null) {
        const modal = document.getElementById('modal-endpoint');
        const form = document.getElementById('form-endpoint');
        const title = document.getElementById('modal-endpoint-title');
        const authGroup = document.getElementById('endpoint-auth-group');
        
        form.reset();
        document.getElementById('endpoint-api-id').value = apiId;
        document.getElementById('endpoint-edit-mode').value = mode;
        document.getElementById('endpoint-original-id').value = endpointId || '';

        // Show auth option only if API has authentication configured
        const api = apisData[apiId];
        const apiHasAuth = api?.auth?.type && api.auth.type !== 'none';
        authGroup.style.display = apiHasAuth ? 'block' : 'none';

        // Clear parameter rows; populated either from existing endpoint or left empty.
        const paramRows = document.getElementById('endpoint-params-rows');
        if (paramRows) QSDom.clear(paramRows);

        if (mode === 'edit' && endpointId && apisData[apiId]) {
            const endpoint = (apisData[apiId].endpoints || []).find(ep => ep.id === endpointId);
            if (endpoint) {
                title.textContent = t('apis.editEndpoint');
                document.getElementById('endpoint-id').value = endpoint.id;
                document.getElementById('endpoint-method').value = endpoint.method;
                document.getElementById('endpoint-name').value = endpoint.name || '';
                document.getElementById('endpoint-path').value = endpoint.path;
                document.getElementById('endpoint-description').value = endpoint.description || '';
                // An explicit callableFrom value, or empty for auto-derive.
                document.getElementById('endpoint-callable-from').value = endpoint.callableFrom || '';
                // An absent auth field means "inherit from API" -- that is how
                // serverFetch and the manager normaliser read it -- so the
                // picker shows 'inherit' for it. 'none' is saved literally, so
                // an endpoint set to public stays public.
                document.getElementById('endpoint-auth').value = endpoint.auth || 'inherit';
                document.getElementById('endpoint-request-schema').value =
                    endpoint.requestSchema ? JSON.stringify(endpoint.requestSchema, null, 2) : '';
                document.getElementById('endpoint-response-schema').value =
                    endpoint.responseSchema ? JSON.stringify(endpoint.responseSchema, null, 2) : '';
                // Parameters: array of {name, type?, required?, description?}
                (endpoint.parameters || []).forEach(p => addParamRow(p));
            }
        } else {
            title.textContent = t('apis.addEndpoint');
            // Default to 'inherit' for new endpoints when API has auth
            document.getElementById('endpoint-auth').value = 'inherit';
            // New endpoints default to auto-derive.
            document.getElementById('endpoint-callable-from').value = '';
        }

        // Sync the auto-derive preview with the API's current auth type
        // so the user immediately sees what "Auto" would resolve to.
        updateCallableFromAutoPreview();

        openModal(modal);
        document.getElementById('endpoint-id').focus();
    }

    /**
     * Refresh the "Auto resolves to: <value>" hint shown when the
     * callableFrom select is set to the empty (Auto) option. Reads
     * the parent API's SAVED auth type from apisData[apiId] (not the
     * API edit form, which is a separate modal and not open while
     * the endpoint modal is) and applies the same derivation rule as
     * ApiEndpointManager::deriveCallableFrom server-side: 'apiKey' →
     * 'server'; everything else → 'both'. Per the locked design
     * of 2026-06-04 for the `callableFrom` marker.
     * Beta.8 Track A4.
     */
    function updateCallableFromAutoPreview() {
        const select  = document.getElementById('endpoint-callable-from');
        const preview = document.getElementById('callable-from-auto-preview');
        const valueEl = document.getElementById('callable-from-auto-value');
        if (!select || !preview || !valueEl) return;

        // Read from the parent API's STORED auth type. The endpoint
        // modal carries the parent API id in a hidden field — read it
        // and look up the saved auth.type. Fallback to 'none' when the
        // API has no auth declared (matches server-side default).
        const apiId = document.getElementById('endpoint-api-id')?.value;
        const apiAuth = (apiId && apisData && apisData[apiId]) ? apisData[apiId].auth : null;
        const authType = apiAuth?.type || 'none';
        const derived  = (authType === 'apiKey') ? 'server' : 'both';
        valueEl.textContent = derived;

        // Show the preview only when the select is on "Auto" (empty value).
        preview.style.display = (select.value === '') ? '' : 'none';
    }

    // -------------------------------------------------------------------------
    // Parameter rows (for endpoints with :placeholders or query-string params)
    // -------------------------------------------------------------------------

    const PARAM_TYPES = ['string', 'number', 'integer', 'boolean'];

    function addParamRow(existing) {
        const rows = document.getElementById('endpoint-params-rows');
        if (!rows) return;
        const row = QSDom.el('div', { class: 'apis-param-row' });

        const typeSelect = QSDom.el('select', { class: 'admin-input apis-param-row__type' });
        PARAM_TYPES.forEach(type => {
            const opt = document.createElement('option');
            opt.value = type;
            opt.textContent = type;
            if (existing?.type === type) opt.selected = true;
            typeSelect.appendChild(opt);
        });

        const requiredInput = QSDom.el('input', {
            type: 'checkbox', class: 'apis-param-row__required-input'
        });
        requiredInput.checked = !!existing?.required;

        const requiredLabel = t('apis.form.paramRequired');
        const removeLabel = t('common.delete');

        row.appendChild(QSDom.el('input', {
            type: 'text',
            class: 'admin-input apis-param-row__name',
            placeholder: t('apis.form.paramName'),
            value: existing?.name || ''
        }));
        row.appendChild(typeSelect);
        row.appendChild(QSDom.el('label', {
            class: 'apis-param-row__required', title: requiredLabel
        }, [
            requiredInput,
            QSDom.el('span', { text: requiredLabel })
        ]));
        row.appendChild(QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--ghost admin-btn--xs apis-param-row__delete',
            title: removeLabel,
            'aria-label': removeLabel,
            text: '×'
        }));

        rows.appendChild(row);
    }

    // Delegated click handler — survives any innerHTML rebuilds and
    // doesn't depend on per-row closure captures (the per-row attach
    // was unreliable in some browsers when the row was re-inserted).
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.apis-param-row__delete');
        if (!btn) return;
        // Guard: only handle clicks inside the endpoint params editor.
        if (!btn.closest('#endpoint-params-rows')) return;
        e.preventDefault();
        e.stopPropagation();
        const row = btn.closest('.apis-param-row');
        if (row) row.remove();
    });

    function collectParamRows() {
        const rows = document.querySelectorAll('#endpoint-params-rows .apis-param-row');
        const out = [];
        rows.forEach(r => {
            const name = r.querySelector('.apis-param-row__name').value.trim();
            if (!name) return; // empty rows are dropped silently
            out.push({
                name,
                type: r.querySelector('.apis-param-row__type').value,
                required: r.querySelector('.apis-param-row__required-input').checked
            });
        });
        return out;
    }

    async function handleEndpointSubmit(e) {
        e.preventDefault();
        
        const apiId = document.getElementById('endpoint-api-id').value;
        const mode = document.getElementById('endpoint-edit-mode').value;
        const originalId = document.getElementById('endpoint-original-id').value;
        
        const endpointId = document.getElementById('endpoint-id').value.trim();
        const method = document.getElementById('endpoint-method').value;
        const name = document.getElementById('endpoint-name').value.trim();
        const path = document.getElementById('endpoint-path').value.trim();
        const description = document.getElementById('endpoint-description').value.trim();
        
        // Only get auth value if API has auth configured
        const api = apisData[apiId];
        const apiHasAuth = api?.auth?.type && api.auth.type !== 'none';
        const auth = apiHasAuth ? document.getElementById('endpoint-auth').value : null;
        
        // Parse schemas
        let requestSchema = null;
        let responseSchema = null;
        
        try {
            const reqSchemaText = document.getElementById('endpoint-request-schema').value.trim();
            if (reqSchemaText) {
                requestSchema = JSON.parse(reqSchemaText);
                const shapeErr = _validateSchemaShape(requestSchema, t('apis.schema.request'));
                if (shapeErr) {
                    showToast(shapeErr, 'error');
                    return;
                }
            }
        } catch (err) {
            showToast(t('apis.toast.invalidRequestSchema'), 'error');
            return;
        }

        try {
            const resSchemaText = document.getElementById('endpoint-response-schema').value.trim();
            if (resSchemaText) {
                responseSchema = JSON.parse(resSchemaText);
                const shapeErr = _validateSchemaShape(responseSchema, t('apis.schema.response'));
                if (shapeErr) {
                    showToast(shapeErr, 'error');
                    return;
                }
            }
        } catch (err) {
            showToast(t('apis.toast.invalidResponseSchema'), 'error');
            return;
        }

        // Collect parameter rows (name + type + required); skip empty-name rows.
        const parameters = collectParamRows();

        // Send explicit empties (not undefined) for the managed optional
        // fields. JSON.stringify keeps '' and [] but strips undefined —
        // dropping the key was what hid clears before (the backend then
        // merged and the old value survived). The backend treats an empty
        // value as "remove the key" (absent = default/none), so clearing
        // a field in the editor now actually clears it.
        const endpoint = {
            id: endpointId,
            method: method,
            name: name,
            path: path,
            description: description,
            // Beta.8 A2 Slice 4 follow-up: send the explicit picker value,
            // including 'none'. Previously 'none' was collapsed to '' which
            // the backend's normalizer strips entirely, making "explicit
            // public" indistinguishable from "auth field absent" (which
            // serverFetch interprets as "inherit from API"). Persisting
            // 'none' literally lets downstream consumers — including the
            // resolver's cache-eligibility check — see the author's intent.
            // Other inherited string values stay literal; an empty
            // picker value (no selection) still strips the field.
            auth: auth || '',
            parameters: parameters,
            requestSchema: requestSchema || '',
            responseSchema: responseSchema || '',
            // beta.8 Track A4 — empty string = auto-derive (backend
            // drops the key); explicit 'client'/'server'/'both' persists.
            callableFrom: document.getElementById('endpoint-callable-from').value || ''
        };

        // Build editApi params
        const params = { apiId: apiId };
        
        if (mode === 'edit') {
            const { id, ...updates } = endpoint;
            if (originalId !== endpointId) {
                // ID changed → atomic server-side rename. The backend preserves
                // the endpoint's own fields (unmanaged kept via merge, managed
                // overlaid from `updates`) AND re-points every external reference
                // (interactions, page events, refreshEndpoint) from the old id
                // to the new one — no delete+add, no data loss.
                params.renameEndpoint = { from: originalId, to: endpointId, updates: updates };
            } else {
                // Same ID - field edit only.
                params.editEndpoint = { id: endpointId, updates: updates };
            }
        } else {
            params.addEndpoint = endpoint;
        }

        try {
            const response = await QuickSiteAdmin.apiRequest('editApi', 'POST', params);

            if (response.ok) {
                showToast(response.data?.message || t('apis.toast.endpointSaved'), 'success');
                closeModal(document.getElementById('modal-endpoint'));
                loadApis();
            } else {
                showToast(response.data?.message || t('apis.toast.endpointSaveFailed'), 'error');
            }
        } catch (error) {
            console.error('Failed to save endpoint:', error);
            showToast(t('apis.toast.endpointSaveFailedDetail', { message: error.message }), 'error');
        }
    }

    // =========================================================================
    // Delete Operations
    // =========================================================================

    function promptDeleteApi(apiId) {
        const api = apisData[apiId];
        const endpointCount = (api?.endpoints || []).length;
        let message = t('apis.confirm.deleteApi', { name: api?.name || apiId });
        if (endpointCount > 0) {
            message += ' ' + t(endpointCount !== 1
                ? 'apis.confirm.deleteApiEndpointsMany'
                : 'apis.confirm.deleteApiEndpointsOne', { count: endpointCount });
        }
        
        pendingDelete = { type: 'api', apiId: apiId };
        document.getElementById('confirm-delete-message').textContent = message;
        openModal(document.getElementById('modal-confirm-delete'));
    }

    function promptDeleteEndpoint(apiId, endpointId) {
        const api = apisData[apiId];
        const endpoint = (api?.endpoints || []).find(ep => ep.id === endpointId);
        const message = t('apis.confirm.deleteEndpoint', { name: endpoint?.name || endpointId });
        
        pendingDelete = { type: 'endpoint', apiId: apiId, endpointId: endpointId };
        document.getElementById('confirm-delete-message').textContent = message;
        openModal(document.getElementById('modal-confirm-delete'));
    }

    async function confirmDelete() {
        if (!pendingDelete) return;
        
        try {
            let response;
            if (pendingDelete.type === 'api') {
                response = await QuickSiteAdmin.apiRequest('deleteApi', 'POST', { apiId: pendingDelete.apiId });
            } else {
                response = await QuickSiteAdmin.apiRequest('editApi', 'POST', { 
                    apiId: pendingDelete.apiId, 
                    deleteEndpoint: pendingDelete.endpointId 
                });
            }

            if (response.ok) {
                showToast(response.data?.message || t('apis.toast.deleted'), 'success');
                closeModal(document.getElementById('modal-confirm-delete'));
                await loadApis();
            } else {
                showToast(response.data?.message || t('apis.toast.deleteFailed'), 'error');
            }
        } catch (error) {
            console.error('Failed to delete:', error);
            showToast(t('apis.toast.deleteFailedDetail', { message: error.message }), 'error');
        }
        
        pendingDelete = null;
    }

    // =========================================================================
    // Test Endpoint
    // =========================================================================

    function openTestModal(apiId, endpointId) {
        const api = apisData[apiId];
        const endpoint = (api?.endpoints || []).find(ep => ep.id === endpointId);
        
        if (!api || !endpoint) {
            showToast(t('apis.toast.endpointNotFound'), 'error');
            return;
        }

        currentTestEndpoint = { apiId, endpointId, api, endpoint };
        
        // Determine effective auth for this endpoint
        // No auth property = public (none), like OpenAPI security: []
        const apiAuthType = api.auth?.type || 'none';
        const endpointAuth = endpoint.auth || 'none'; // undefined = public
        let effectiveAuth = null;
        if (endpointAuth === 'none') {
            // Public
            effectiveAuth = null;
        } else if (endpointAuth === 'required') {
            effectiveAuth = apiAuthType !== 'none' ? apiAuthType : 'required';
        } else if (endpointAuth === 'inherit' && apiAuthType !== 'none') {
            effectiveAuth = apiAuthType;
        }
        
        // Show request info
        const fullUrl = api.baseUrl.replace(/\/$/, '') + endpoint.path;
        const requestInfo = document.getElementById('test-request-info');
        QSDom.clear(requestInfo);
        requestInfo.appendChild(QSDom.el('strong', { text: endpoint.method }));
        requestInfo.appendChild(document.createTextNode(' ' + fullUrl));
        if (effectiveAuth) {
            requestInfo.appendChild(QSDom.el('br'));
            requestInfo.appendChild(QSDom.el('small', {
                text: t('apis.authBadgeTitle', { type: effectiveAuth })
            }));
        }

        // Show/hide body/query based on method
        const hasBody = ['POST', 'PUT', 'PATCH'].includes(endpoint.method);
        document.getElementById('test-body-group').style.display = hasBody ? 'block' : 'none';
        document.getElementById('test-query-group').style.display = 'block';

        // Always reset both textareas first
        document.getElementById('test-request-body').value = '';
        document.getElementById('test-query-params').value = '';

        // Generate inputs for :placeholders in the path, plus form fields from schema
        generateTestPathParams(endpoint);
        generateTestForm(endpoint, hasBody);

        // Pre-fill raw JSON with example from schema
        if (endpoint.requestSchema) {
            const example = schemaToExample(endpoint.requestSchema);
            if (hasBody) {
                document.getElementById('test-request-body').value = JSON.stringify(example, null, 2);
            } else {
                document.getElementById('test-query-params').value = JSON.stringify(example, null, 2);
            }
        }
        
        // Reset response
        QSDom.clear(document.getElementById('test-response-status'));
        document.getElementById('test-response-time').textContent = '';
        _setResponseBody(QSDom.el('span', {
            class: 'admin-text-muted', text: t('apis.test.noResponse')
        }));

        openModal(document.getElementById('modal-test'));
    }
    
    /**
     * Render an input per :placeholder in the endpoint path so the user
     * can fill them in before running the test. Inputs sit in the
     * `#test-path-params-form` container, separate from the schema form.
     *
     * Uses `endpoint.parameters` for type / required metadata when the
     * placeholder name matches a declared parameter. Unknown placeholders
     * default to string + optional.
     */
    function generateTestPathParams(endpoint) {
        let container = document.getElementById('test-path-params-form');
        if (!container) {
            // First time: lazily insert the container above the schema form.
            const paramsForm = document.getElementById('test-params-form');
            if (!paramsForm) return;
            container = document.createElement('div');
            container.id = 'test-path-params-form';
            container.className = 'apis-test-path-params';
            paramsForm.parentNode.insertBefore(container, paramsForm);
        }

        const path = endpoint.path || '';
        const placeholders = [...path.matchAll(/:([a-zA-Z][a-zA-Z0-9_]*)/g)].map(m => m[1]);

        if (placeholders.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }

        // Build a map from declared parameters for type / required hints
        const declared = {};
        (endpoint.parameters || []).forEach(p => { if (p && p.name) declared[p.name] = p; });

        const group = QSDom.el('div', { class: 'admin-test-params' });
        for (const name of placeholders) {
            const def = declared[name] || {};
            const type = def.type || 'string';
            const fieldId = `test-pathparam-${name}`;
            const inputType = (type === 'integer' || type === 'number') ? 'number' : 'text';

            const label = QSDom.el('label', {
                class: 'admin-label', for: fieldId
            }, [
                ':' + name + ' ',
                QSDom.el('small', { class: 'admin-text-muted', text: '(' + type + ')' })
            ]);
            if (def.required) {
                label.appendChild(document.createTextNode(' '));
                label.appendChild(QSDom.el('span', { class: 'admin-text-danger', text: '*' }));
            }

            const input = QSDom.el('input', {
                type: inputType,
                class: 'admin-input admin-input--sm',
                id: fieldId,
                'data-path-param': name
            });
            if (type === 'integer') input.setAttribute('step', '1');
            else if (type === 'number') input.setAttribute('step', 'any');

            group.appendChild(QSDom.el('div', {
                class: 'admin-form-group admin-form-group--compact'
            }, [label, input]));
        }

        QSDom.clear(container);
        container.appendChild(QSDom.el('h4', {
            class: 'apis-test-section-title', text: t('apis.form.pathParameters')
        }));
        container.appendChild(group);
        container.style.display = '';
    }

    /**
     * Read the path-param inputs back into a plain object.
     * Empty values are dropped (left as `:name` literals at the server).
     */
    function collectTestPathParams() {
        const out = {};
        document.querySelectorAll('#test-path-params-form [data-path-param]').forEach(el => {
            const name = el.dataset.pathParam;
            const v = el.value;
            if (v !== '' && v !== null && v !== undefined) {
                out[name] = v;
            }
        });
        return out;
    }

    /**
     * Generate form fields from endpoint's requestSchema
     */
    function generateTestForm(endpoint, hasBody) {
        const container = document.getElementById('test-params-form');
        const schema = endpoint.requestSchema;
        
        if (!schema || !schema.properties || Object.keys(schema.properties).length === 0) {
            QSDom.clear(container);
            container.appendChild(QSDom.el('p', {
                class: 'admin-text-muted admin-hint', text: t('apis.test.noSchemaParams')
            }));
            return;
        }

        const required = schema.required || [];
        const group = QSDom.el('div', { class: 'admin-test-params' });

        for (const [fieldName, fieldDef] of Object.entries(schema.properties)) {
            const isRequired = required.includes(fieldName);
            const fieldType = fieldDef.type || 'string';
            const fieldId = `test-param-${fieldName}`;

            const label = QSDom.el('label', {
                class: 'admin-label', for: fieldId, text: fieldName
            });
            if (isRequired) {
                label.appendChild(document.createTextNode(' '));
                label.appendChild(QSDom.el('span', { class: 'admin-text-danger', text: '*' }));
            }

            const wrapper = QSDom.el('div', {
                class: 'admin-form-group admin-form-group--compact'
            }, [label]);

            // Generate field based on type
            if (fieldDef.enum && fieldDef.enum.length > 0) {
                // Enum → Select
                const select = QSDom.el('select', {
                    class: 'admin-input admin-input--sm', id: fieldId,
                    'data-field': fieldName, 'data-type': fieldType
                });
                if (!isRequired) {
                    QSDom.setSelectPlaceholder(select, t('apis.test.selectPlaceholder'));
                }
                for (const opt of fieldDef.enum) {
                    const option = document.createElement('option');
                    option.value = opt;
                    option.textContent = opt;
                    if (opt === fieldDef.default) option.selected = true;
                    select.appendChild(option);
                }
                wrapper.appendChild(select);
            } else if (fieldType === 'boolean') {
                // Boolean → Checkbox
                const cb = QSDom.el('input', {
                    type: 'checkbox', id: fieldId,
                    'data-field': fieldName, 'data-type': 'boolean'
                });
                cb.checked = fieldDef.default === true;
                wrapper.appendChild(QSDom.el('label', { class: 'admin-checkbox' }, [
                    cb, QSDom.el('span', { text: t('common.yes') })
                ]));
            } else if (fieldType === 'integer' || fieldType === 'number') {
                // Number → Number input
                const input = QSDom.el('input', {
                    type: 'number', class: 'admin-input admin-input--sm', id: fieldId,
                    'data-field': fieldName, 'data-type': fieldType,
                    value: fieldDef.default !== undefined ? fieldDef.default : '',
                    step: fieldType === 'integer' ? '1' : 'any'
                });
                if (fieldDef.minimum !== undefined) input.setAttribute('min', fieldDef.minimum);
                if (fieldDef.maximum !== undefined) input.setAttribute('max', fieldDef.maximum);
                wrapper.appendChild(input);
            } else if (fieldType === 'array') {
                // Array → one comma-separated input
                wrapper.appendChild(QSDom.el('input', {
                    type: 'text', class: 'admin-input admin-input--sm', id: fieldId,
                    'data-field': fieldName, 'data-type': 'array',
                    placeholder: t('apis.test.arrayPlaceholder')
                }));
                wrapper.appendChild(QSDom.el('p', {
                    class: 'admin-hint', text: t('apis.test.arrayHint')
                }));
            } else {
                // String → Input if short (maxLength < 255), textarea otherwise
                const maxLen = fieldDef.maxLength;
                const placeholder = fieldDef.format
                    ? t('apis.test.formatPlaceholder', { format: fieldDef.format })
                    : '';
                if (maxLen && maxLen < 255) {
                    wrapper.appendChild(QSDom.el('input', {
                        type: 'text', class: 'admin-input admin-input--sm', id: fieldId,
                        'data-field': fieldName, 'data-type': 'string',
                        placeholder: placeholder, maxlength: maxLen
                    }));
                } else {
                    wrapper.appendChild(QSDom.el('textarea', {
                        class: 'admin-input admin-input--sm', id: fieldId,
                        'data-field': fieldName, 'data-type': 'string',
                        rows: '3', placeholder: placeholder
                    }));
                }
            }

            group.appendChild(wrapper);
        }

        QSDom.clear(container);
        container.appendChild(group);

        // Add event listeners to sync with raw JSON
        container.querySelectorAll('[data-field]').forEach(field => {
            const eventType = field.type === 'checkbox' ? 'change' : 'input';
            field.addEventListener(eventType, updateRawJsonFromForm);
        });
    }
    
    /**
     * Collect form data and update raw JSON textareas
     */
    function updateRawJsonFromForm() {
        if (!currentTestEndpoint) return;
        
        const container = document.getElementById('test-params-form');
        const fields = container.querySelectorAll('[data-field]');
        const data = {};
        
        fields.forEach(field => {
            const name = field.dataset.field;
            const type = field.dataset.type;
            let value;
            
            if (field.type === 'checkbox') {
                value = field.checked;
            } else if (type === 'integer') {
                value = field.value ? parseInt(field.value, 10) : undefined;
            } else if (type === 'number') {
                value = field.value ? parseFloat(field.value) : undefined;
            } else if (type === 'boolean') {
                value = field.value === 'true';
            } else if (type === 'array') {
                value = field.value ? field.value.split(',').map(s => s.trim()).filter(s => s) : undefined;
            } else {
                value = field.value || undefined;
            }
            
            if (value !== undefined && value !== '') {
                data[name] = value;
            }
        });
        
        const json = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
        
        // Update appropriate textarea based on method
        const hasBody = ['POST', 'PUT', 'PATCH'].includes(currentTestEndpoint.endpoint.method);
        if (hasBody) {
            document.getElementById('test-request-body').value = json;
        } else {
            document.getElementById('test-query-params').value = json;
        }
    }
    
    /**
     * Sync form fields from raw JSON (when user edits raw JSON)
     */
    function syncFormFromRawJson(e) {
        // Only sync if user is editing raw JSON directly
        // We could implement this but it adds complexity - for now just leave form as-is
    }
    
    /**
     * Load auth token for an API
     */
    function loadAuthToken(apiId) {
        try {
            const tokens = JSON.parse(localStorage.getItem(AUTH_TOKEN_STORAGE_KEY) || '{}');
            return tokens[apiId] || '';
        } catch (e) {
            return '';
        }
    }
    
    /**
     * Toggle token visibility in API card
     */
    function toggleTokenVisibility(e) {
        const btn = e.currentTarget;
        const card = btn.closest('.api-card');
        if (!card) return;
        const input = card.querySelector('.api-auth-token__input');
        if (!input) return;
        const iconEye = btn.querySelector('.icon-eye');
        const iconEyeOff = btn.querySelector('.icon-eye-off');
        
        if (input.type === 'password') {
            input.type = 'text';
            if (iconEye) iconEye.style.display = 'none';
            if (iconEyeOff) iconEyeOff.style.display = '';
        } else {
            input.type = 'password';
            if (iconEye) iconEye.style.display = '';
            if (iconEyeOff) iconEyeOff.style.display = 'none';
        }
    }
    
    /**
     * Save auth token from API card
     */
    function saveApiAuthToken(e) {
        const btn = e.currentTarget;
        const apiId = btn.dataset.api;
        const card = btn.closest('.api-card');
        const input = card.querySelector('.api-auth-token__input');
        const token = input.value.trim();
        
        try {
            const tokens = JSON.parse(localStorage.getItem(AUTH_TOKEN_STORAGE_KEY) || '{}');
            if (token) {
                tokens[apiId] = token;
            } else {
                delete tokens[apiId];
            }
            localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, JSON.stringify(tokens));
            showToast(t(token ? 'apis.toast.tokenSaved' : 'apis.toast.tokenCleared'), 'success');
        } catch (e) {
            showToast(t('apis.toast.tokenSaveFailed'), 'error');
        }
    }

    async function runTest() {
        if (!currentTestEndpoint) return;
        
        const { apiId, endpointId } = currentTestEndpoint;
        const btn = document.getElementById('btn-run-test');
        
        // Get auth token from API card (not test modal)
        const api = apisData[apiId];
        const endpoint = (api?.endpoints || []).find(ep => ep.id === endpointId);
        const apiAuthType = api?.auth?.type || 'none';
        const endpointAuth = endpoint?.auth || 'none'; // undefined = public
        
        // Determine if auth should be sent based on endpoint auth setting
        // Only send auth if 'required', or 'inherit' with API having auth
        let authToken = null;
        if (endpointAuth === 'required' || (endpointAuth === 'inherit' && apiAuthType !== 'none')) {
            // Get token from API card's token input (if visible)
            const apiCardTokenInput = document.querySelector(`#api-row-${apiId} .api-auth-token-input`);
            authToken = apiCardTokenInput?.value.trim() || loadAuthToken(apiId) || '';
        }
        
        // Parse body if present
        let body = null;
        const bodyText = document.getElementById('test-request-body').value.trim();
        if (bodyText) {
            try {
                body = JSON.parse(bodyText);
            } catch (err) {
                showToast(t('apis.toast.invalidBodyJson'), 'error');
                return;
            }
        }

        // Parse query params
        let queryParams = null;
        const queryText = document.getElementById('test-query-params').value.trim();
        if (queryText) {
            try {
                queryParams = JSON.parse(queryText);
            } catch (err) {
                showToast(t('apis.toast.invalidQueryJson'), 'error');
                return;
            }
        }

        QSDom.setButtonBusy(btn, t('apis.test.testing'));

        QSDom.clear(document.getElementById('test-response-status'));
        document.getElementById('test-response-time').textContent = t('apis.test.executing');
        _setResponseBody(QSDom.el('span', {
            class: 'admin-text-muted', text: t('apis.test.waiting')
        }));

        // Collect :placeholder values for path substitution
        const pathParams = collectTestPathParams();

        try {
            const params = {
                apiId: apiId,
                endpointId: endpointId,
                testData: body,
                queryParams: queryParams,
                pathParams: Object.keys(pathParams).length > 0 ? pathParams : undefined,
                authToken: authToken || undefined
            };

            const response = await QuickSiteAdmin.apiRequest('testApiEndpoint', 'POST', params);

            if (response.ok && response.data?.data) {
                const testResult = response.data.data;
                const statusCode = testResult.response?.status;
                const statusClass = statusCode >= 200 && statusCode < 300 
                    ? 'admin-badge--success' 
                    : 'admin-badge--danger';
                
                _setResponseStatus(String(statusCode || t('apis.test.noStatus')), statusClass);
                document.getElementById('test-response-time').textContent =
                    testResult.timing?.duration_ms ? `${testResult.timing.duration_ms}ms` : '';

                // Format response body
                const responseBody = testResult.response?.body;
                if (responseBody !== undefined && responseBody !== null && responseBody !== '') {
                    let text;
                    try {
                        const parsed = typeof responseBody === 'string' ? JSON.parse(responseBody) : responseBody;
                        text = JSON.stringify(parsed, null, 2);
                    } catch {
                        text = String(responseBody);
                    }
                    _setResponseBody(QSDom.el('pre', { text: text }));
                } else {
                    _setResponseBody(QSDom.el('span', {
                        class: 'admin-text-muted', text: t('apis.test.emptyResponse')
                    }));
                }
            } else {
                _setResponseStatus(t('apis.test.errorBadge'), 'admin-badge--danger');
                _setResponseBody(QSDom.el('pre', {
                    class: 'admin-text-danger',
                    text: response.data?.message || t('apis.test.requestFailed')
                }));
            }
        } catch (error) {
            console.error('Test failed:', error);
            _setResponseStatus(t('apis.test.errorBadge'), 'admin-badge--danger');
            _setResponseBody(QSDom.el('pre', {
                class: 'admin-text-danger', text: error.message
            }));
        } finally {
            btn.disabled = false;
            QSDom.clear(btn);
            btn.appendChild(QSDom.iconEl(QuickSiteUtils.ICON_PATHS.play, 16));
            btn.appendChild(document.createTextNode(' ' + t('apis.test.run')));
        }
    }

    /**
     * A whole-sentence key whose :name markers become real nodes.
     *
     * One key per sentence, not one per fragment: a translator needs the whole
     * sentence to order it correctly, and the markup that carried the link or
     * the <code> spans is supplied here instead of inside the string. Markers
     * are matched longest-first so :public cannot eat :publicPath.
     *
     * @param {string} key            a translation path
     * @param {Object} parts          markerName -> Node
     * @returns {DocumentFragment}
     */
    function _renderMarked(key, parts) {
        const names = Object.keys(parts).sort((a, b) => b.length - a.length);
        const frag = document.createDocumentFragment();
        let rest = t(key);
        while (rest) {
            let best = null;
            for (const name of names) {
                const at = rest.indexOf(':' + name);
                if (at !== -1 && (best === null || at < best.at)) best = { at, name };
            }
            if (!best) break;
            if (best.at > 0) frag.appendChild(document.createTextNode(rest.slice(0, best.at)));
            frag.appendChild(parts[best.name].cloneNode(true));
            rest = rest.slice(best.at + best.name.length + 1);
        }
        if (rest) frag.appendChild(document.createTextNode(rest));
        return frag;
    }

    /** Show one node in the import modal's error box. */
    function _setImportError(node) {
        const errBox = document.getElementById('import-parse-error');
        if (!errBox) return;
        QSDom.clear(errBox);
        errBox.appendChild(node);
        errBox.style.display = '';
    }

    /** Replace the test modal's response body with one element. */
    function _setResponseBody(element) {
        const box = document.getElementById('test-response-body');
        if (!box) return;
        QSDom.clear(box);
        box.appendChild(element);
    }

    /** Replace the test modal's status badge. */
    function _setResponseStatus(text, badgeClass) {
        const box = document.getElementById('test-response-status');
        if (!box) return;
        QSDom.clear(box);
        box.appendChild(QSDom.el('span', {
            class: 'admin-badge ' + badgeClass, text: text
        }));
    }

    // =========================================================================
    // Import / Export — two-step flow (paste → detect+convert → preview → confirm)
    // =========================================================================

    // Holds the parsed/converted payload between the two screens.
    let _importPending = null;

    function openImportModal() {
        _importPending = null;
        document.getElementById('import-json').value = '';
        showImportScreen('paste');
        openModal(document.getElementById('modal-import'));
    }

    function showImportScreen(which) {
        const isPaste = which === 'paste';
        document.getElementById('import-screen-paste').style.display = isPaste ? '' : 'none';
        document.getElementById('import-screen-preview').style.display = isPaste ? 'none' : '';
        document.getElementById('btn-import-next').style.display = isPaste ? '' : 'none';
        document.getElementById('btn-import-back').style.display = isPaste ? 'none' : '';
        document.getElementById('btn-import-confirm').style.display = isPaste ? 'none' : '';
        document.getElementById('import-title').textContent = isPaste
            ? t('apis.importJson')
            : t('apis.importModal.previewTitle');
        // Clear inline error
        const errBox = document.getElementById('import-parse-error');
        if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
        // Reset preview-only widgets when returning to paste so a fresh
        // conversion starts from a clean slate.
        if (isPaste) {
            const fixerRows = document.getElementById('import-baseurl-fixer-rows');
            const fixerWrap = document.getElementById('import-baseurl-fixer');
            if (fixerRows) fixerRows.replaceChildren();
            if (fixerWrap) fixerWrap.style.display = 'none';
            const tree = document.getElementById('import-tree');
            if (tree) tree.replaceChildren();
        }
    }

    // Provides a single ready-to-edit example so new users don't face a
    // blank textarea. The "Load test.api example" button was removed in
    // Step 6 of API_REGISTRY_DEMO; the *converter* for foreign formats
    // (incl. test.api) stays in place — paste any recognised foreign JSON
    // by hand and it still detects + converts on Next.
    function loadExampleJson(kind) {
        const ours = {
            apis: {
                "main-backend": {
                    name: "Main Backend",
                    baseUrl: "https://api.example.com",
                    description: "Example API in our native format.",
                    auth: { type: "bearer", tokenSource: "localStorage:authToken" },
                    endpoints: [
                        {
                            id: "list-users",
                            name: "List Users",
                            method: "GET",
                            path: "/users",
                            description: "Paged user list.",
                            parameters: [
                                { name: "page", type: "integer", required: false },
                                { name: "limit", type: "integer", required: false }
                            ]
                        },
                        {
                            id: "get-user",
                            name: "Get User",
                            method: "GET",
                            path: "/users/:id",
                            parameters: [
                                { name: "id", type: "string", required: true }
                            ]
                        }
                    ]
                }
            }
        };
        document.getElementById('import-json').value = JSON.stringify(ours, null, 2);
    }

    /**
     * Detect the foreign-format signature of a parsed JSON object.
     * Returns: 'ours' | 'testapi-filemanager' | 'openapi-3' | 'swagger-2'
     * | 'unknown'. Swagger 2.0 is recognised distinctly so the caller can
     * surface a "convert to 3.x first" hint instead of a generic error.
     */
    function detectImportFormat(data) {
        if (!data || typeof data !== 'object') return 'unknown';
        if (data.apis && typeof data.apis === 'object' && !Array.isArray(data.apis)) {
            return 'ours';
        }
        // test.api file-manager shape: top-level endpoints.{public,secured}
        if (data.endpoints && typeof data.endpoints === 'object' &&
            (Array.isArray(data.endpoints.public) ||
             (data.endpoints.secured && Array.isArray(data.endpoints.secured.endpoints)))) {
            return 'testapi-filemanager';
        }
        if (window.QSApiImport && typeof window.QSApiImport.detectOpenApi === 'function') {
            const kind = window.QSApiImport.detectOpenApi(data);
            if (kind === 'openapi-3') return 'openapi-3';
            if (kind === 'swagger-2') return 'swagger-2';
        }
        return 'unknown';
    }

    /** Build an endpoint id from a name ("List Files" → "list-files"). */
    function slugifyEndpointId(name) {
        return String(name || 'endpoint')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/^([0-9])/, 'x$1')         // mustn't start with a digit
            || 'endpoint';
    }

    /**
     * Given a foreign URL + its parameters list + the API's declared
     * route_format, produce the path in our native shape where every
     * parameter that lives in the URL appears as `:name`.
     *
     * Three cases handled:
     *  - URL already has `:name`   → leave it.
     *  - URL has `/name/` literal  → rewrite as `:name`.
     *  - Otherwise + route_format = "path segments"
     *                              → append `/name/:name`.
     *  - Otherwise (query-string)  → leave the path; QS.fetch routes
     *                                the leftover to the query string.
     */
    function buildOurPath(foreignUrl, params, routeFormat) {
        let path = String(foreignUrl || '/');
        if (!Array.isArray(params) || params.length === 0) return path;
        const pathSegmentMode = (routeFormat || '').toLowerCase().includes('path segment');

        for (const p of params) {
            const name = p && p.name;
            if (!name) continue;
            if (path.indexOf(':' + name) !== -1) continue;  // already templated
            const literalSeg = new RegExp('(/)' + name + '(/|$)');
            if (literalSeg.test(path)) {
                path = path.replace(literalSeg, '$1:' + name + '$2');
                continue;
            }
            if (pathSegmentMode) {
                path += '/' + name + '/:' + name;
            }
            // else: leave; the runtime puts it in the query string.
        }
        return path;
    }

    function convertEndpoint(ep, routeFormat) {
        const params = Array.isArray(ep.parameters) ? ep.parameters : [];
        const out = {
            id: slugifyEndpointId(ep.name),
            name: ep.name || 'Endpoint',
            method: (ep.method || 'GET').toUpperCase(),
            path: buildOurPath(ep.url || '/', params, routeFormat),
            description: ep.description || undefined
        };
        if (params.length > 0) {
            const cleaned = params.map(p => ({
                name: p.name,
                type: p.type || 'string',
                required: !!p.required,
                description: p.description || undefined
            })).filter(p => p.name);
            if (cleaned.length > 0) out.parameters = cleaned;
        }
        return out;
    }

    /**
     * Convert a foreign-format payload to our native shape:
     *   { apis: { "<apiId>": { name, baseUrl, auth, endpoints: [...] } } }
     *
     * Returns { converted, notes } where `notes` is a list of
     * human-readable strings the preview should surface (e.g. slug
     * collisions, dropped header params, relative base URLs).
     *
     * For the test.api file-manager format, EVERY group under
     * `endpoints` (public, auth, pagination, secured, ...) becomes its
     * own API named `test-api-<group>`. Groups can be either a flat
     * array of endpoints OR an object `{ authentication, endpoints }`
     * (the bearer-auth shape).
     */
    function convertImportPayload(data, format) {
        if (format === 'ours') return { converted: data, notes: [] };

        if (format === 'testapi-filemanager') {
            const baseUrl = String(data.base_url || '').trim();
            const routeFormat = data.route_format || '';
            const apiNameRoot = data.api_name || 'Test API';
            const apis = {};

            const groups = (data.endpoints && typeof data.endpoints === 'object') ? data.endpoints : {};
            for (const groupName of Object.keys(groups)) {
                const group = groups[groupName];
                let endpoints;
                let auth = { type: 'none' };

                if (Array.isArray(group)) {
                    endpoints = group;
                } else if (group && Array.isArray(group.endpoints)) {
                    endpoints = group.endpoints;
                    const authType = (group.authentication?.type || '').toLowerCase();
                    if (authType.includes('bearer')) {
                        auth = { type: 'bearer', tokenSource: 'localStorage:authToken' };
                    } else if (authType.includes('basic')) {
                        auth = { type: 'basic', tokenSource: 'localStorage:basicAuth' };
                    } else if (authType.includes('api') && authType.includes('key')) {
                        auth = { type: 'apiKey', tokenSource: 'header:X-API-Key' };
                    }
                } else {
                    continue;  // unrecognised group shape
                }

                if (!endpoints.length) continue;

                apis['test-api-' + groupName] = {
                    name: apiNameRoot + ' (' + groupName + ')',
                    baseUrl,
                    description: groupName + ' endpoints.',
                    auth,
                    endpoints: endpoints.map(ep => convertEndpoint(ep, routeFormat))
                };
            }

            return { converted: { apis }, notes: [] };
        }

        if (format === 'openapi-3' && window.QSApiImport
                && typeof window.QSApiImport.convertOpenApi === 'function') {
            return window.QSApiImport.convertOpenApi(data);
        }

        return null;
    }

    /**
     * Slug a collected-data label into a stable id (mirrors privacy.js / the
     * server-side _privacySanitizeId convention closely enough for matching).
     */
    function _privSlug(label) {
        return String(label || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    /**
     * Inspect optional `privacy` blocks in a (native) import payload for the
     * preview: counts of data-collected / classified hosts / field mappings, plus
     * ⚠ flags for endpoint fields referencing labels not declared in their API's
     * `collects`, and per-baseUrl host-kind conflicts across APIs.
     */
    function _computeImportPrivacy(apis) {
        const datumIds = {};
        const hostSet = {};
        const hostKinds = {};
        let mappings = 0;
        const flags = [];
        for (const apiId of Object.keys(apis)) {
            const a = apis[apiId] || {};
            const p = a.privacy || {};
            const labels = {};
            if (Array.isArray(p.collects)) {
                for (const c of p.collects) {
                    if (c && c.label) { labels[c.label] = true; datumIds[_privSlug(c.label)] = true; }
                }
            }
            if (p.host === 'self' || p.host === 'third-party') {
                hostSet[a.baseUrl] = true;
                if (hostKinds[a.baseUrl] && hostKinds[a.baseUrl] !== p.host) {
                    flags.push(t('apis.importModal.flagHostBothWays', { host: a.baseUrl }));
                }
                hostKinds[a.baseUrl] = p.host;
            }
            for (const ep of (a.endpoints || [])) {
                const f = ep.privacy && ep.privacy.fields;
                if (!f) continue;
                const epId = ep.id || ep.name || ep.path;
                for (const field of Object.keys(f)) {
                    if (labels[f[field]]) mappings++;
                    else flags.push(t('apis.importModal.flagFieldUnknown', {
                        endpoint: apiId + '/' + epId, field: field, label: f[field] }));
                }
            }
        }
        return { datums: Object.keys(datumIds).length, hosts: Object.keys(hostSet).length, mappings, flags };
    }

    /**
     * Build the import preview summary as a DOM Element. The caller is
     * expected to use `container.replaceChildren(summarisePayload(...))`,
     * not innerHTML. Notes (slug collisions, dropped header params, etc.)
     * render as a bullet list under the summary line.
     */
    function summarisePayload(converted, format, notes) {
        notes = Array.isArray(notes) ? notes : [];
        const apis = (converted && converted.apis) || {};
        const apiIds = Object.keys(apis);
        const totalEndpoints = apiIds.reduce(
            (n, id) => n + ((apis[id].endpoints && apis[id].endpoints.length) || 0), 0);
        const existing = apiIds.filter(id => apisData[id]);
        const fmtLabel = format === 'ours' ? t('apis.importModal.formatNative')
            : format === 'testapi-filemanager' ? 'test.api file-manager'
            : format === 'openapi-3' ? 'OpenAPI 3.x'
            : t('apis.importModal.formatUnknown');

        const root = document.createElement('div');
        root.className = 'apis-import-summary';

        const line = document.createElement('p');
        line.className = 'apis-import-summary__line';
        line.appendChild(document.createTextNode(t('apis.importModal.detectedFormat') + ' '));
        const strong = document.createElement('strong');
        strong.textContent = fmtLabel;
        line.appendChild(strong);
        line.appendChild(document.createTextNode(t('apis.importModal.summaryCreate', {
            apis: t(apiIds.length === 1 ? 'apis.apiCountOne' : 'apis.apiCountMany',
                { count: apiIds.length }),
            endpoints: t(totalEndpoints === 1 ? 'apis.endpointCountOne' : 'apis.endpointCountMany',
                { count: totalEndpoints })
        })));
        if (existing.length > 0) {
            line.appendChild(document.createTextNode(t('apis.importModal.summaryReplaces', {
                count: existing.length, list: existing.join(', ')
            })));
        }
        root.appendChild(line);

        // Optional privacy blocks (native format): summary line + ⚠ flags.
        const priv = _computeImportPrivacy(apis);
        if (priv.datums || priv.hosts || priv.mappings || priv.flags.length) {
            const pLine = document.createElement('p');
            pLine.className = 'apis-import-summary__line';
            pLine.appendChild(document.createTextNode(t('apis.importModal.privacySummary', {
                datums: priv.datums,
                hosts: t(priv.hosts === 1
                    ? 'apis.importModal.hostCountOne'
                    : 'apis.importModal.hostCountMany', { count: priv.hosts }),
                fields: t(priv.mappings === 1
                    ? 'apis.importModal.fieldCountOne'
                    : 'apis.importModal.fieldCountMany', { count: priv.mappings })
            })));
            root.appendChild(pLine);
        }

        const allNotes = notes.concat(priv.flags.map(f => '⚠ ' + f));
        if (allNotes.length) {
            const list = document.createElement('ul');
            list.className = 'apis-import-notes';
            for (const note of allNotes) {
                const li = document.createElement('li');
                li.textContent = note;
                list.appendChild(li);
            }
            root.appendChild(list);
        }

        return root;
    }

    const METHOD_BADGE_CLASS = {
        GET: 'admin-badge--success',
        POST: 'admin-badge--primary',
        PUT: 'admin-badge--warning',
        PATCH: 'admin-badge--warning',
        DELETE: 'admin-badge--danger',
        HEAD: 'admin-badge--default',
        OPTIONS: 'admin-badge--default',
        TRACE: 'admin-badge--default'
    };

    /**
     * Build the per-API + per-endpoint checkbox tree. Tree state is the
     * source of truth for which endpoints get sent on Import; the raw
     * JSON textarea handles everything else (descriptions, schemas).
     *
     * Selection model: checked = include. A "select all" checkbox in
     * each API header toggles all of its endpoint checkboxes; endpoint
     * checkboxes feed back into the header's indeterminate / checked
     * state. Defaults to all-checked.
     */
    function _renderImportTree(converted) {
        const host = document.getElementById('import-tree');
        if (!host) return;
        host.replaceChildren();

        const apis = (converted && converted.apis) || {};
        const apiIds = Object.keys(apis);
        if (apiIds.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'admin-text-muted';
            empty.textContent = t('apis.importModal.noApis');
            host.appendChild(empty);
            return;
        }

        for (const apiId of apiIds) {
            host.appendChild(_renderImportTreeApi(apiId, apis[apiId] || {}));
        }
    }

    function _renderImportTreeApi(apiId, apiData) {
        const section = document.createElement('div');
        section.className = 'admin-card import-tree-api';
        section.dataset.apiId = apiId;
        section.style.padding = 'var(--space-sm)';
        section.style.marginBottom = 'var(--space-sm)';

        const endpoints = Array.isArray(apiData.endpoints) ? apiData.endpoints : [];

        // ─ Header: select-all + name + apiId + endpoint count ────────────
        const header = document.createElement('div');
        header.className = 'import-tree-api__header';
        header.style.display = 'flex';
        header.style.alignItems = 'center';
        header.style.gap = 'var(--space-sm)';
        header.style.marginBottom = 'var(--space-xs)';

        const selectAll = document.createElement('input');
        selectAll.type = 'checkbox';
        selectAll.checked = true;
        selectAll.dataset.apiSelectAll = apiId;
        header.appendChild(selectAll);

        const titleWrap = document.createElement('div');
        titleWrap.style.flex = '1';
        const title = document.createElement('strong');
        title.textContent = apiData.name || apiId;
        titleWrap.appendChild(title);
        titleWrap.appendChild(document.createTextNode(' '));
        const idCode = document.createElement('code');
        idCode.style.fontSize = 'var(--font-sm)';
        idCode.textContent = apiId;
        titleWrap.appendChild(idCode);
        header.appendChild(titleWrap);

        const countBadge = document.createElement('span');
        countBadge.className = 'admin-badge admin-badge--default';
        header.appendChild(countBadge);

        section.appendChild(header);

        const total = endpoints.length;
        const suffix = ' endpoint' + (total === 1 ? '' : 's');

        // Single source of truth for the header state: recomputes the
        // select-all tri-state AND the count badge ("19" vs "15 / 19")
        // from the current endpoint checkboxes. Called on every toggle.
        function refreshState() {
            const boxes = section.querySelectorAll('input[type="checkbox"][data-endpoint-id]');
            let checked = 0;
            for (const b of boxes) if (b.checked) checked += 1;
            if (boxes.length === 0 || checked === boxes.length) {
                selectAll.checked = boxes.length > 0;
                selectAll.indeterminate = false;
            } else if (checked === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
            countBadge.textContent = (checked === total)
                ? total + suffix
                : checked + ' / ' + total + suffix;
        }

        selectAll.addEventListener('change', () => {
            const boxes = section.querySelectorAll('input[type="checkbox"][data-endpoint-id]');
            for (const b of boxes) b.checked = selectAll.checked;
            refreshState();
        });

        // ─ Endpoint list ────────────────────────────────────────────────
        if (total === 0) {
            const note = document.createElement('p');
            note.className = 'admin-text-muted';
            note.style.margin = '0';
            note.style.fontSize = 'var(--font-sm)';
            note.textContent = t('apis.importModal.noEndpointsInApi');
            section.appendChild(note);
            refreshState();
            return section;
        }

        const list = document.createElement('ul');
        list.className = 'import-tree-endpoints';
        list.style.listStyle = 'none';
        list.style.padding = '0';
        list.style.margin = '0';

        const apiAuthType = (apiData.auth && apiData.auth.type) || 'none';
        for (const ep of endpoints) {
            list.appendChild(_renderImportTreeEndpoint(apiId, ep, apiAuthType, refreshState));
        }
        section.appendChild(list);
        refreshState();

        return section;
    }

    function _renderImportTreeEndpoint(apiId, endpoint, apiAuthType, onToggle) {
        const li = document.createElement('li');
        li.className = 'import-tree-endpoint';
        li.style.display = 'flex';
        li.style.alignItems = 'center';
        li.style.gap = 'var(--space-xs)';
        li.style.padding = 'var(--space-xs) 0';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = true;
        cb.dataset.apiId = apiId;
        cb.dataset.endpointId = endpoint.id || '';
        cb.addEventListener('change', onToggle);
        li.appendChild(cb);

        const methodBadge = document.createElement('span');
        const methodClass = METHOD_BADGE_CLASS[endpoint.method] || 'admin-badge--default';
        methodBadge.className = 'admin-badge ' + methodClass;
        methodBadge.style.minWidth = '54px';
        methodBadge.style.textAlign = 'center';
        methodBadge.textContent = endpoint.method || '?';
        li.appendChild(methodBadge);

        const idCode = document.createElement('code');
        idCode.style.fontSize = 'var(--font-sm)';
        idCode.textContent = endpoint.id || '';
        li.appendChild(idCode);

        const pathCode = document.createElement('code');
        pathCode.className = 'admin-text-muted';
        pathCode.style.fontSize = 'var(--font-sm)';
        pathCode.style.flex = '1';
        pathCode.textContent = endpoint.path || '';
        li.appendChild(pathCode);

        // Auth indicator — surface inherit vs required vs none. The card
        // table later (post-import) collapses inherit + required when the
        // API has auth, but in the preview we want the converter's intent
        // visible so the author can spot scheme-divergence at a glance.
        const authEl = document.createElement('span');
        authEl.className = 'admin-badge admin-badge--default';
        authEl.style.fontSize = '0.7em';
        const epAuth = endpoint.auth || 'inherit';
        if (epAuth === 'none') {
            authEl.textContent = t('apis.importModal.authPublic');
        } else if (epAuth === 'required') {
            authEl.textContent = t('apis.importModal.authRequired');
            authEl.title = t('apis.importModal.authMismatch');
        } else {
            // inherit
            authEl.textContent = apiAuthType === 'none'
                ? t('apis.importModal.authInherit')
                : t('apis.importModal.authInheritFrom', { type: apiAuthType });
        }
        li.appendChild(authEl);

        if (endpoint.requestSchema) {
            const reqBadge = document.createElement('span');
            reqBadge.className = 'admin-badge admin-badge--info';
            reqBadge.style.fontSize = '0.7em';
            reqBadge.title = t('apis.importModal.hasRequestSchema');
            reqBadge.textContent = t('apis.importModal.badgeReq');
            li.appendChild(reqBadge);
        }
        if (endpoint.responseSchema) {
            const respBadge = document.createElement('span');
            respBadge.className = 'admin-badge admin-badge--info';
            respBadge.style.fontSize = '0.7em';
            respBadge.title = t('apis.importModal.hasResponseSchema');
            respBadge.textContent = t('apis.importModal.badgeResp');
            li.appendChild(respBadge);
        }

        return li;
    }

    /**
     * Walk the tree's checkboxes and produce a filtered `apis` map.
     * Endpoints unchecked are dropped; APIs with zero remaining endpoints
     * are dropped entirely. Endpoints present in `toImport.apis` but not
     * in the tree (e.g. added via the raw-JSON advanced edit after the
     * tree was rendered) default to included — the tree is opt-OUT, not
     * opt-IN.
     */
    function _filterApisByTreeSelection(toImport) {
        const sourceApis = (toImport && toImport.apis) || {};
        const filtered = { apis: {} };

        for (const apiId of Object.keys(sourceApis)) {
            const apiData = sourceApis[apiId];
            const endpoints = Array.isArray(apiData.endpoints) ? apiData.endpoints : [];

            const excluded = new Set();
            const boxes = document.querySelectorAll(
                '#import-tree input[type="checkbox"][data-endpoint-id][data-api-id="' +
                _cssEscape(apiId) + '"]'
            );
            for (const b of boxes) {
                if (!b.checked) excluded.add(b.dataset.endpointId);
            }

            const kept = endpoints.filter(ep => !excluded.has(ep.id));
            if (kept.length === 0) continue;
            filtered.apis[apiId] = Object.assign({}, apiData, { endpoints: kept });
        }
        return filtered;
    }

    /** CSS.escape polyfill — for apiIds with `.`/`:` we don't expect, but
     *  cheap insurance for the selector lookup. */
    function _cssEscape(s) {
        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') return CSS.escape(s);
        return String(s).replace(/(["'\\.])/g, '\\$1');
    }

    /**
     * Build an editable "Base URL: <input>" row per API whose baseUrl
     * isn't absolute (http:// or https://). Keeps the JSON preview in
     * sync as the user types, so the textarea + the input never disagree.
     * Returns true when at least one row was rendered.
     */
    function _renderBaseUrlFixer(converted) {
        const wrap = document.getElementById('import-baseurl-fixer');
        const rows = document.getElementById('import-baseurl-fixer-rows');
        if (!wrap || !rows) return false;
        rows.replaceChildren();

        const apis = (converted && converted.apis) || {};
        let shown = 0;
        for (const apiId of Object.keys(apis)) {
            const url = (apis[apiId] && apis[apiId].baseUrl) || '';
            if (/^https?:\/\//i.test(url)) continue;

            const row = document.createElement('div');
            row.className = 'admin-form-group admin-form-group--compact';

            const label = document.createElement('label');
            label.className = 'admin-label';
            label.appendChild(document.createTextNode(apiId + ' baseUrl:'));

            const input = document.createElement('input');
            input.type = 'url';
            input.className = 'admin-input';
            input.value = url;
            input.placeholder = 'https://api.example.com' + (url || '');
            input.dataset.apiId = apiId;
            input.addEventListener('input', () => _syncBaseUrlToJson(apiId, input.value));

            row.appendChild(label);
            row.appendChild(input);
            rows.appendChild(row);
            shown += 1;
        }

        wrap.style.display = shown > 0 ? '' : 'none';
        return shown > 0;
    }

    /**
     * Mirror a baseUrl-fixer input value into the JSON preview textarea
     * so the two surfaces stay aligned. Silently no-ops when the textarea
     * is mid-edit and unparseable — user owns the JSON surface.
     */
    function _syncBaseUrlToJson(apiId, newUrl) {
        const ta = document.getElementById('import-preview-json');
        if (!ta) return;
        let parsed;
        try { parsed = JSON.parse(ta.value); }
        catch { return; }
        if (parsed && parsed.apis && parsed.apis[apiId]) {
            parsed.apis[apiId].baseUrl = newUrl;
            ta.value = JSON.stringify(parsed, null, 2);
        }
    }

    /** Screen 1 → Screen 2: parse, detect, convert, render preview. */
    function handleImportNext() {
        const errBox = document.getElementById('import-parse-error');
        const raw = document.getElementById('import-json').value.trim();
        if (!raw) {
            errBox.textContent = t('apis.importModal.pasteFirst');
            errBox.style.display = '';
            return;
        }
        let data;
        try { data = JSON.parse(raw); }
        catch (err) {
            errBox.textContent = t('apis.importModal.invalidJson', { message: err.message });
            errBox.style.display = '';
            return;
        }
        const fmt = detectImportFormat(data);
        if (fmt === 'swagger-2') {
            _setImportError(_renderMarked('apis.importModal.swagger2', {
                link: QSDom.el('a', {
                    href: 'https://converter.swagger.io',
                    target: '_blank', rel: 'noopener', text: 'converter.swagger.io'
                })
            }));
            return;
        }
        if (fmt === 'unknown') {
            _setImportError(_renderMarked('apis.importModal.unknownFormat', {
                shape: QSDom.el('code', { text: '{"apis": {...}}' }),
                openapi: QSDom.el('code', { text: 'openapi: "3.x.x"' }),
                publicPath: QSDom.el('code', { text: 'endpoints.public' }),
                securedPath: QSDom.el('code', { text: 'endpoints.secured' })
            }));
            return;
        }
        const result = convertImportPayload(data, fmt);
        if (!result || !result.converted) {
            errBox.textContent = t('apis.importModal.converterEmpty');
            errBox.style.display = '';
            return;
        }
        const converted = result.converted;
        const notes = Array.isArray(result.notes) ? result.notes : [];
        _importPending = { converted, format: fmt, notes };
        document.getElementById('import-preview-json').value = JSON.stringify(converted, null, 2);
        document.getElementById('import-preview-summary').replaceChildren(
            summarisePayload(converted, fmt, notes)
        );
        _renderBaseUrlFixer(converted);
        _renderImportTree(converted);
        showImportScreen('preview');
    }

    function handleImportBack() {
        _importPending = null;
        showImportScreen('paste');
    }

    /** Screen 2 confirm: save APIs + endpoints, then reload list. */
    /**
     * Apply a native import's optional `privacy` blocks to the privacy registry,
     * via the existing setCollectedDatum / setPrivacyHost / setPrivacyMapping
     * commands. Labels are resolved to slug ids; endpoint fields referencing a
     * label not in this API's `collects` are skipped (flagged in the preview).
     * Best-effort — failures here don't abort the API import.
     */
    async function _applyImportPrivacy(apiId, apiData) {
        const p = (apiData && apiData.privacy) || {};
        const labelToId = {};
        if (Array.isArray(p.collects)) {
            for (const c of p.collects) {
                if (!c || !c.label) continue;
                const id = _privSlug(c.label);
                if (!id) continue;
                labelToId[c.label] = id;
                try {
                    await QuickSiteAdmin.apiRequest('setCollectedDatum', 'POST', { id, label: c.label, purpose: c.purpose || '' });
                } catch (e) { console.warn('[apis] setCollectedDatum failed', id, e); }
            }
        }
        if (p.host === 'self' || p.host === 'third-party') {
            try {
                await QuickSiteAdmin.apiRequest('setPrivacyHost', 'POST', { baseUrl: apiData.baseUrl, kind: p.host, name: p.name || '', privacyUrl: p.url || '' });
            } catch (e) { console.warn('[apis] setPrivacyHost failed', apiData.baseUrl, e); }
        }
        for (const endpoint of (apiData.endpoints || [])) {
            const fields = endpoint.privacy && endpoint.privacy.fields;
            if (!fields) continue;
            const epId = endpoint.id || endpoint.name || endpoint.path;
            if (!epId) continue;
            for (const field of Object.keys(fields)) {
                const id = labelToId[fields[field]];
                if (!id) continue; // unknown label — flagged in preview, skip
                try {
                    await QuickSiteAdmin.apiRequest('setPrivacyMapping', 'POST', { endpoint: apiId + '/' + epId, field: field, datum: id });
                } catch (e) { console.warn('[apis] setPrivacyMapping failed', apiId, epId, field, e); }
            }
        }
    }

    async function handleImportConfirm() {
        if (!_importPending) return;

        // Re-parse from the preview textarea so manual edits the author
        // made (fixing a relative baseUrl, dropping endpoints, adjusting
        // auth) actually take effect. Empty textarea falls back to the
        // original converted payload — anything else must be valid JSON
        // with a top-level `apis` object, else we block with a clear error.
        let toImport = _importPending.converted;
        const previewText = document.getElementById('import-preview-json').value.trim();
        if (previewText) {
            let parsed;
            try { parsed = JSON.parse(previewText); }
            catch (err) {
                showToast(t('apis.toast.previewInvalid', { message: err.message }), 'error');
                return;
            }
            if (!parsed || typeof parsed !== 'object' || !parsed.apis || typeof parsed.apis !== 'object') {
                showToast(t('apis.toast.previewNoApis'), 'error');
                return;
            }
            toImport = parsed;
        }

        // Overlay any baseUrl-fixer input values onto the parsed payload
        // (they take precedence over the JSON-derived baseUrl), then
        // filter by tree selection — endpoints the author unchecked are
        // dropped here, APIs with zero selected endpoints are removed
        // entirely. Finally preflight every remaining API for an absolute
        // URL so the server doesn't 400 mid-import-loop.
        const fixerInputs = document.querySelectorAll('#import-baseurl-fixer-rows input[data-api-id]');
        for (const inp of fixerInputs) {
            const apiId = inp.dataset.apiId;
            const v = inp.value.trim();
            if (toImport.apis && toImport.apis[apiId] && v) toImport.apis[apiId].baseUrl = v;
        }
        const filtered = _filterApisByTreeSelection(toImport);
        const apis = filtered.apis;
        if (Object.keys(apis).length === 0) {
            showToast(t('apis.toast.nothingSelected'), 'error');
            return;
        }
        for (const apiId of Object.keys(apis)) {
            const u = (apis[apiId] && apis[apiId].baseUrl) || '';
            if (!/^https?:\/\//i.test(u)) {
                showToast(t('apis.toast.baseUrlInvalid', { api: apiId }), 'error');
                return;
            }
        }

        const btn = document.getElementById('btn-import-confirm');
        btn.disabled = true;
        const origLabel = btn.textContent;
        btn.textContent = t('apis.importModal.importing');

        let imported = 0;
        let errors = 0;

        for (const [apiId, apiData] of Object.entries(apis)) {
            try {
                // If the API already exists, delete it first so we get a clean replace.
                if (apisData[apiId]) {
                    await QuickSiteAdmin.apiRequest('deleteApi', 'POST', { apiId });
                }
                // Add the API shell (without endpoints — addApi rejects unknown fields).
                const addRes = await QuickSiteAdmin.apiRequest('addApi', 'POST', {
                    apiId,
                    name: apiData.name || apiId,
                    baseUrl: apiData.baseUrl,
                    description: apiData.description,
                    auth: apiData.auth
                });
                if (!addRes.ok) { errors++; continue; }
                // Then attach endpoints one by one (validation runs per endpoint).
                // Strip any optional `privacy` block so it never lands in
                // api-endpoints.json — it is applied to the privacy registry below.
                for (const endpoint of (apiData.endpoints || [])) {
                    const epClean = Object.assign({}, endpoint);
                    delete epClean.privacy;
                    await QuickSiteAdmin.apiRequest('editApi', 'POST', {
                        apiId,
                        addEndpoint: epClean
                    });
                }
                // Apply optional privacy blocks (collects + host + field mappings).
                await _applyImportPrivacy(apiId, apiData);
                imported++;
            } catch (err) {
                console.error('Import error for', apiId, err);
                errors++;
            }
        }

        btn.disabled = false;
        btn.textContent = origLabel;
        closeModal(document.getElementById('modal-import'));
        await loadApis();

        if (errors === 0) {
            showToast(t('apis.toast.importedOk', { count: imported }), 'success');
        } else {
            showToast(t('apis.toast.importedWithErrors', { count: imported, errors: errors }), 'warning');
        }
    }

    function exportApis() {
        const exportData = { apis: apisData };
        const json = JSON.stringify(exportData, null, 2);
        
        // Download as file
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `api-endpoints-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast(t('apis.toast.exported'), 'success');
    }

    // =========================================================================
    // Modal Helpers
    // =========================================================================

    function openModal(modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Convert JSON Schema to example object
     * Supports: type, properties, items, example, default, enum
     */
    function schemaToExample(schema) {
        if (!schema || typeof schema !== 'object') return {};
        
        // If schema has an explicit example, use it
        if (schema.example !== undefined) return schema.example;
        if (schema.default !== undefined) return schema.default;
        
        const type = schema.type || 'object';
        
        switch (type) {
            case 'string':
                if (schema.enum && schema.enum.length > 0) return schema.enum[0];
                if (schema.format === 'email') return 'user@example.com';
                if (schema.format === 'date') return '2026-01-01';
                if (schema.format === 'date-time') return '2026-01-01T12:00:00Z';
                if (schema.format === 'uri') return 'https://example.com';
                return '';
                
            case 'number':
            case 'integer':
                if (schema.enum && schema.enum.length > 0) return schema.enum[0];
                return schema.minimum || 0;
                
            case 'boolean':
                return false;
                
            case 'array':
                if (schema.items) {
                    return [schemaToExample(schema.items)];
                }
                return [];
                
            case 'object':
                const obj = {};
                if (schema.properties) {
                    for (const [key, propSchema] of Object.entries(schema.properties)) {
                        obj[key] = schemaToExample(propSchema);
                    }
                }
                return obj;
                
            case 'null':
                return null;
                
            default:
                return null;
        }
    }
    
    /**
     * Get a JSON Schema template for the given type
     */
    function getSchemaTemplate(type) {
        const templates = {
            request: {
                type: 'object',
                required: ['name', 'email'],
                properties: {
                    name: { type: 'string' },
                    email: { type: 'string', format: 'email' },
                    message: { type: 'string' }
                }
            },
            response: {
                type: 'object',
                properties: {
                    success: { type: 'boolean' },
                    data: {
                        type: 'object',
                        properties: {
                            id: { type: 'integer' },
                            createdAt: { type: 'string', format: 'date-time' }
                        }
                    },
                    message: { type: 'string' }
                }
            }
        };
        return templates[type] || templates.request;
    }
    
    /**
     * Insert a JSON Schema template into a textarea
     */
    function insertSchemaTemplate(textareaId, type) {
        const textarea = document.getElementById(textareaId);
        if (!textarea) return;
        
        const template = getSchemaTemplate(type);
        textarea.value = JSON.stringify(template, null, 2);
        
        // Trigger validation
        const statusId = textareaId === 'endpoint-request-schema' ? 'request-schema-status' : 'response-schema-status';
        validateJsonField(textarea, statusId);
        
        showToast(t('apis.toast.templateInserted'), 'success');
    }

    // =========================================================================
    // JSON Schema Helpers
    // =========================================================================

    /**
     * Validate JSON in a textarea and update status indicator
     */
    function validateJsonField(textarea, statusId) {
        const statusEl = document.getElementById(statusId);
        const editorEl = textarea.closest('.admin-schema-editor');
        const value = textarea.value.trim();
        
        // Empty is valid (optional field)
        if (!value) {
            statusEl.textContent = '';
            statusEl.className = 'admin-schema-editor__status';
            editorEl?.classList.remove('admin-schema-editor--invalid');
            return true;
        }
        
        try {
            JSON.parse(value);
            statusEl.textContent = t('apis.schema.validJson');
            statusEl.className = 'admin-schema-editor__status admin-schema-editor__status--valid';
            editorEl?.classList.remove('admin-schema-editor--invalid');
            return true;
        } catch (e) {
            statusEl.textContent = t('apis.schema.invalidJson');
            statusEl.className = 'admin-schema-editor__status admin-schema-editor__status--invalid';
            editorEl?.classList.add('admin-schema-editor--invalid');
            return false;
        }
    }

    /**
     * Format/prettify JSON in a textarea
     */
    function formatJsonField(textareaId) {
        const textarea = document.getElementById(textareaId);
        if (!textarea) return;
        
        const value = textarea.value.trim();
        if (!value) return;
        
        try {
            const parsed = JSON.parse(value);
            textarea.value = JSON.stringify(parsed, null, 2);
            
            // Re-validate to update status
            const statusId = textareaId === 'endpoint-request-schema' ? 'request-schema-status' : 'response-schema-status';
            validateJsonField(textarea, statusId);
            
            showToast(t('apis.toast.jsonFormatted'), 'success');
        } catch (e) {
            showToast(t('apis.toast.cannotFormat'), 'error');
        }
    }

    function showToast(message, type = 'info') {
        if (window.Toast) {
            window.Toast[type]?.(message) || window.Toast.show?.(message, type);
        } else if (QuickSiteAdmin?.showToast) {
            QuickSiteAdmin.showToast(message, type);
        } else {
            console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }

})();

