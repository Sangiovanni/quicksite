/**
 * API Registry Page JavaScript
 * Manages external API definitions and endpoints for the QuickSite admin interface.
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';

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
                showToast(response.data?.message || 'Failed to load APIs', 'error');
            }
        } catch (error) {
            console.error('Failed to load APIs:', error);
            showToast('Failed to load APIs: ' + error.message, 'error');
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

        const html = apiIds.map(apiId => renderApiCard(apiId, apis[apiId])).join('');
        container.innerHTML = html;

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

    function renderApiCard(apiId, api) {
        const endpoints = api.endpoints || [];
        const authBadge = api.auth?.type && api.auth.type !== 'none' 
            ? `<span class="admin-badge admin-badge--info">${api.auth.type}</span>` 
            : '';
        
        const endpointsHtml = endpoints.length > 0 
            ? endpoints.map(ep => renderEndpointRow(apiId, ep)).join('')
            : `<tr><td colspan="5" class="admin-text-muted" style="text-align: center; padding: var(--space-md);">
                    ${window.translations?.apis?.noEndpoints || 'No endpoints defined'}
               </td></tr>`;

        return `
            <div class="admin-card api-card" data-api-id="${escapeHtml(apiId)}">
                <div class="api-card__header" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: var(--space-sm);">
                        ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.chevronRight, 20, 'api-card__chevron')}
                        <div>
                            <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-xs);">
                                ${escapeHtml(api.name || apiId)}
                                ${authBadge}
                            </h3>
                            <p class="admin-text-muted" style="margin: 0; font-size: var(--font-sm);">
                                <code>${escapeHtml(apiId)}</code> · ${escapeHtml(api.baseUrl)}
                                · ${endpoints.length} endpoint${endpoints.length !== 1 ? 's' : ''}
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; gap: var(--space-xs);">
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" 
                                data-action="add-endpoint" data-api="${escapeHtml(apiId)}" title="Add Endpoint">
                            ${QuickSiteUtils.iconPlus(16)}
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" 
                                data-action="edit-api" data-api="${escapeHtml(apiId)}" title="Edit API">
                            ${QuickSiteUtils.iconEdit(16)}
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost admin-btn--danger-hover" 
                                data-action="delete-api" data-api="${escapeHtml(apiId)}" title="Delete API">
                            ${QuickSiteUtils.iconTrash(16)}
                        </button>
                    </div>
                </div>
                <div class="api-card__body" style="display: none;">
                    ${api.description ? `<p class="admin-text-muted" style="margin-bottom: var(--space-md);">${escapeHtml(api.description)}</p>` : ''}
                    
                    <!-- Auth Token Section -->
                    ${api.auth?.type && api.auth.type !== 'none' ? `
                    <div class="api-auth-token" style="margin-bottom: var(--space-md); padding: var(--space-sm); background: var(--bg-secondary); border-radius: var(--radius-md); display: flex; align-items: center; gap: var(--space-sm);">
                        <label style="font-weight: 500; white-space: nowrap;">🔑 Auth Token:</label>
                        <div style="flex: 1; display: flex; gap: var(--space-xs);">
                            <input type="password" class="admin-input admin-input--sm api-auth-token__input" 
                                   data-api="${escapeHtml(apiId)}"
                                   value="${escapeHtml(loadAuthToken(apiId))}"
                                   placeholder="Enter your ${api.auth.type} token"
                                   style="flex: 1;">
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost api-auth-token__toggle" 
                                    data-api="${escapeHtml(apiId)}" title="Show/Hide">
                                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.eye, 16, 'icon-eye')}
                                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.eyeOff, 16, 'icon-eye-off', 'style="display: none;"')}
                            </button>
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost api-auth-token__save" 
                                    data-api="${escapeHtml(apiId)}" title="Save token">
                                ${QuickSiteUtils.iconSave(16)}
                            </button>
                        </div>
                    </div>
                    ` : ''}
                    
                    <table class="admin-table admin-table--striped">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Method</th>
                                <th>Endpoint</th>
                                <th>Path</th>
                                <th style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${endpointsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
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
        
        let authBadge = '';
        if (endpointAuth === 'none') {
            // Public - no badge needed
        } else if (endpointAuth === 'required') {
            // Explicitly requires auth
            const authLabel = apiAuthType !== 'none' ? apiAuthType : 'required';
            authBadge = `<span class="admin-badge admin-badge--info" title="Auth: ${authLabel}" style="font-size: 0.7em;">🔐 ${authLabel}</span>`;
        } else if (endpointAuth === 'inherit' && apiAuthType !== 'none') {
            // Inherits from API (and API has auth)
            authBadge = `<span class="admin-badge admin-badge--info" title="Auth: ${apiAuthType}" style="font-size: 0.7em;">🔐 ${apiAuthType}</span>`;
        }

        return `
            <tr>
                <td><span class="admin-badge ${methodClass}">${endpoint.method}</span></td>
                <td>
                    <code>${escapeHtml(endpoint.id)}</code> ${authBadge}
                    <br><small class="admin-text-muted">${escapeHtml(endpoint.name || '')}</small>
                </td>
                <td><code>${escapeHtml(endpoint.path)}</code></td>
                <td>
                    <div style="display: flex; gap: var(--space-xs);">
                        <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" 
                                data-action="test-endpoint" data-api="${escapeHtml(apiId)}" data-endpoint="${escapeHtml(endpoint.id)}" 
                                title="Test">
                            ${QuickSiteUtils.iconPlay(14)}
                        </button>
                        <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" 
                                data-action="edit-endpoint" data-api="${escapeHtml(apiId)}" data-endpoint="${escapeHtml(endpoint.id)}" 
                                title="Edit">
                            ${QuickSiteUtils.iconEdit()}
                        </button>
                        <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost admin-btn--danger-hover" 
                                data-action="delete-endpoint" data-api="${escapeHtml(apiId)}" data-endpoint="${escapeHtml(endpoint.id)}" 
                                title="Delete">
                            ${QuickSiteUtils.iconTrash()}
                        </button>
                    </div>
                </td>
            </tr>
        `;
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
            title.textContent = window.translations?.apis?.editApi || 'Edit API';
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
            title.textContent = window.translations?.apis?.addApi || 'Add API';
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

        const noneText = window.translations?.apis?.form?.refreshNone || '(none — no refresh configured)';
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
            return label + ' must be a JSON object — e.g. { "type": "object", "properties": {...} }.';
        }
        // Double-wrap detection: the user pasted the outer container.
        if (!schema.type && (schema.responseSchema || schema.requestSchema)) {
            const wrapKey = schema.responseSchema ? 'responseSchema' : 'requestSchema';
            return label + ' looks double-wrapped — paste only the inner schema (the value of "' + wrapKey + '"), not the whole envelope.';
        }
        if (!schema.type) {
            return label + ' must have a top-level "type" field (e.g. "object", "array", "string").';
        }
        const typeIsValid = typeof schema.type === 'string' || Array.isArray(schema.type);
        if (!typeIsValid) {
            return label + '\'s "type" must be a string or array of strings.';
        }
        const isObjectType = schema.type === 'object'
            || (Array.isArray(schema.type) && schema.type.indexOf('object') !== -1);
        if (isObjectType && (!schema.properties || typeof schema.properties !== 'object' || Array.isArray(schema.properties))) {
            return label + ' is type "object" but missing a "properties" object. Add "properties": {} even if empty.';
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
                if (!rEndpoint) missing.push('endpoint');
                if (!rKey) missing.push('storage key');
                if (!rBody) missing.push('body field');
                if (!rPath) missing.push('response token path');
                if (missing.length) {
                    showToast('Refresh config incomplete. Missing: ' + missing.join(', '), 'error');
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
                showToast(response.data?.message || 'API saved successfully', 'success');
                closeModal(document.getElementById('modal-api'));
                loadApis();
            } else {
                showToast(response.data?.message || 'Failed to save API', 'error');
            }
        } catch (error) {
            console.error('Failed to save API:', error);
            showToast('Failed to save API: ' + error.message, 'error');
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
        if (paramRows) paramRows.innerHTML = '';

        if (mode === 'edit' && endpointId && apisData[apiId]) {
            const endpoint = (apisData[apiId].endpoints || []).find(ep => ep.id === endpointId);
            if (endpoint) {
                title.textContent = window.translations?.apis?.editEndpoint || 'Edit Endpoint';
                document.getElementById('endpoint-id').value = endpoint.id;
                document.getElementById('endpoint-method').value = endpoint.method;
                document.getElementById('endpoint-name').value = endpoint.name || '';
                document.getElementById('endpoint-path').value = endpoint.path;
                document.getElementById('endpoint-description').value = endpoint.description || '';
                // beta.8 Track A4 — explicit callableFrom value, or empty for auto-derive
                document.getElementById('endpoint-callable-from').value = endpoint.callableFrom || '';
                // Beta.8 A2 Slice 4 follow-up: an absent auth field is
                // semantically "inherit from API" (that's how serverFetch
                // + the existing manager normalizer interpret it). Pre-
                // this-fix the picker loaded missing auth as 'none' which
                // was misleading — author saw 'none' but the endpoint
                // actually inherited the parent API's auth at runtime.
                // Default to 'inherit' now; author can explicitly switch
                // to 'none' for endpoints that should be truly public,
                // and the save path persists 'none' literally instead of
                // collapsing it to an absent field.
                document.getElementById('endpoint-auth').value = endpoint.auth || 'inherit';
                document.getElementById('endpoint-request-schema').value =
                    endpoint.requestSchema ? JSON.stringify(endpoint.requestSchema, null, 2) : '';
                document.getElementById('endpoint-response-schema').value =
                    endpoint.responseSchema ? JSON.stringify(endpoint.responseSchema, null, 2) : '';
                // Parameters: array of {name, type?, required?, description?}
                (endpoint.parameters || []).forEach(p => addParamRow(p));
            }
        } else {
            title.textContent = window.translations?.apis?.addEndpoint || 'Add Endpoint';
            // Default to 'inherit' for new endpoints when API has auth
            document.getElementById('endpoint-auth').value = 'inherit';
            // beta.8 Track A4 — new endpoints default to auto-derive
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
     * 2026-06-04 (BETA8_DATA_RESOLVER.md "callableFrom marker").
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
        const row = document.createElement('div');
        row.className = 'apis-param-row';
        row.innerHTML = `
            <input type="text"
                   class="admin-input apis-param-row__name"
                   placeholder="${ (window.translations?.apis?.paramName) || 'name' }"
                   value="${ existing?.name ? escapeAttr(existing.name) : '' }">
            <select class="admin-input apis-param-row__type">
                ${PARAM_TYPES.map(t =>
                    `<option value="${t}"${existing?.type === t ? ' selected' : ''}>${t}</option>`
                ).join('')}
            </select>
            <label class="apis-param-row__required" title="${ (window.translations?.apis?.paramRequired) || 'required' }">
                <input type="checkbox" class="apis-param-row__required-input"${existing?.required ? ' checked' : ''}>
                <span>${ (window.translations?.apis?.paramRequired) || 'required' }</span>
            </label>
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--xs apis-param-row__delete" title="${ (window.translations?.common?.delete) || 'Remove' }" aria-label="${ (window.translations?.common?.delete) || 'Remove' }">&times;</button>
        `;
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

    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
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
                const shapeErr = _validateSchemaShape(requestSchema, 'Request schema');
                if (shapeErr) {
                    showToast(shapeErr, 'error');
                    return;
                }
            }
        } catch (err) {
            showToast('Invalid request schema JSON', 'error');
            return;
        }

        try {
            const resSchemaText = document.getElementById('endpoint-response-schema').value.trim();
            if (resSchemaText) {
                responseSchema = JSON.parse(resSchemaText);
                const shapeErr = _validateSchemaShape(responseSchema, 'Response schema');
                if (shapeErr) {
                    showToast(shapeErr, 'error');
                    return;
                }
            }
        } catch (err) {
            showToast('Invalid response schema JSON', 'error');
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
                showToast(response.data?.message || 'Endpoint saved successfully', 'success');
                closeModal(document.getElementById('modal-endpoint'));
                loadApis();
            } else {
                showToast(response.data?.message || 'Failed to save endpoint', 'error');
            }
        } catch (error) {
            console.error('Failed to save endpoint:', error);
            showToast('Failed to save endpoint: ' + error.message, 'error');
        }
    }

    // =========================================================================
    // Delete Operations
    // =========================================================================

    function promptDeleteApi(apiId) {
        const api = apisData[apiId];
        const endpointCount = (api?.endpoints || []).length;
        const message = `Are you sure you want to delete the API "${api?.name || apiId}"? ${
            endpointCount > 0 ? `This will also delete ${endpointCount} endpoint${endpointCount !== 1 ? 's' : ''}.` : ''
        }`;
        
        pendingDelete = { type: 'api', apiId: apiId };
        document.getElementById('confirm-delete-message').textContent = message;
        openModal(document.getElementById('modal-confirm-delete'));
    }

    function promptDeleteEndpoint(apiId, endpointId) {
        const api = apisData[apiId];
        const endpoint = (api?.endpoints || []).find(ep => ep.id === endpointId);
        const message = `Are you sure you want to delete the endpoint "${endpoint?.name || endpointId}"?`;
        
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
                showToast(response.data?.message || 'Deleted successfully', 'success');
                closeModal(document.getElementById('modal-confirm-delete'));
                await loadApis();
            } else {
                showToast(response.data?.message || 'Failed to delete', 'error');
            }
        } catch (error) {
            console.error('Failed to delete:', error);
            showToast('Failed to delete: ' + error.message, 'error');
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
            showToast('Endpoint not found', 'error');
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
        document.getElementById('test-request-info').innerHTML = `
            <strong>${endpoint.method}</strong> ${escapeHtml(fullUrl)}
            ${effectiveAuth ? `<br><small>Auth: ${effectiveAuth}</small>` : ''}
        `;

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
        document.getElementById('test-response-status').innerHTML = '';
        document.getElementById('test-response-time').textContent = '';
        document.getElementById('test-response-body').innerHTML = '<span class="admin-text-muted">Run test to see response</span>';

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

        const heading = window.translations?.apis?.pathParameters || 'Path parameters';
        let html = `<h4 class="apis-test-section-title">${escapeHtml(heading)}</h4>`;
        html += '<div class="admin-test-params">';
        for (const name of placeholders) {
            const def = declared[name] || {};
            const type = def.type || 'string';
            const required = !!def.required;
            const fieldId = `test-pathparam-${name}`;
            const requiredMark = required ? ' <span class="admin-text-danger">*</span>' : '';
            const inputType = (type === 'integer' || type === 'number') ? 'number' : 'text';
            const step = type === 'integer' ? ' step="1"' : (type === 'number' ? ' step="any"' : '');
            html += `<div class="admin-form-group admin-form-group--compact">`;
            html += `<label class="admin-label" for="${fieldId}">:${escapeHtml(name)} <small class="admin-text-muted">(${escapeHtml(type)})</small>${requiredMark}</label>`;
            html += `<input type="${inputType}"${step} class="admin-input admin-input--sm" id="${fieldId}" data-path-param="${escapeHtml(name)}">`;
            html += `</div>`;
        }
        html += '</div>';
        container.innerHTML = html;
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
            container.innerHTML = '<p class="admin-text-muted admin-hint">No parameters defined in schema</p>';
            return;
        }
        
        const required = schema.required || [];
        const isGetMethod = endpoint.method === 'GET';
        
        let html = `<div class="admin-test-params">`;
        
        for (const [fieldName, fieldDef] of Object.entries(schema.properties)) {
            const isRequired = required.includes(fieldName);
            const fieldType = fieldDef.type || 'string';
            const fieldId = `test-param-${fieldName}`;
            
            html += `<div class="admin-form-group admin-form-group--compact">`;
            html += `<label class="admin-label" for="${fieldId}">${escapeHtml(fieldName)}`;
            if (isRequired) html += ` <span class="admin-text-danger">*</span>`;
            html += `</label>`;
            
            // Generate field based on type
            if (fieldDef.enum && fieldDef.enum.length > 0) {
                // Enum → Select
                html += `<select class="admin-input admin-input--sm" id="${fieldId}" data-field="${fieldName}" data-type="${fieldType}">`;
                if (!isRequired) html += `<option value="">-- Select --</option>`;
                for (const opt of fieldDef.enum) {
                    const selected = opt === fieldDef.default ? ' selected' : '';
                    html += `<option value="${escapeHtml(opt)}"${selected}>${escapeHtml(opt)}</option>`;
                }
                html += `</select>`;
            } else if (fieldType === 'boolean') {
                // Boolean → Checkbox
                const checked = fieldDef.default === true ? ' checked' : '';
                html += `<label class="admin-checkbox">`;
                html += `<input type="checkbox" id="${fieldId}" data-field="${fieldName}" data-type="boolean"${checked}>`;
                html += `<span>Yes</span></label>`;
            } else if (fieldType === 'integer' || fieldType === 'number') {
                // Number → Number input
                const min = fieldDef.minimum !== undefined ? ` min="${fieldDef.minimum}"` : '';
                const max = fieldDef.maximum !== undefined ? ` max="${fieldDef.maximum}"` : '';
                const defVal = fieldDef.default !== undefined ? fieldDef.default : '';
                html += `<input type="number" class="admin-input admin-input--sm" id="${fieldId}" data-field="${fieldName}" data-type="${fieldType}" value="${defVal}"${min}${max} step="${fieldType === 'integer' ? '1' : 'any'}">`;
            } else if (fieldType === 'array') {
                // Array → Textarea (comma-separated or JSON)
                html += `<input type="text" class="admin-input admin-input--sm" id="${fieldId}" data-field="${fieldName}" data-type="array" placeholder="value1, value2, value3">`;
                html += `<p class="admin-hint">Comma-separated values</p>`;
            } else {
                // String → Input if short (maxLength < 255), textarea otherwise
                const maxLen = fieldDef.maxLength;
                const placeholder = fieldDef.format ? `Format: ${fieldDef.format}` : '';
                if (maxLen && maxLen < 255) {
                    html += `<input type="text" class="admin-input admin-input--sm" id="${fieldId}" data-field="${fieldName}" data-type="string" placeholder="${placeholder}" maxlength="${maxLen}">`;
                } else {
                    html += `<textarea class="admin-input admin-input--sm" id="${fieldId}" data-field="${fieldName}" data-type="string" rows="3" placeholder="${placeholder}"></textarea>`;
                }
            }
            
            html += `</div>`;
        }
        
        html += `</div>`;
        container.innerHTML = html;
        
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
            showToast(token ? 'Token saved' : 'Token cleared', 'success');
        } catch (e) {
            showToast('Failed to save token', 'error');
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
                showToast('Invalid JSON in request body', 'error');
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
                showToast('Invalid JSON in query params', 'error');
                return;
            }
        }

        btn.disabled = true;
        btn.innerHTML = QuickSiteUtils.htmlSpinner() + ' Testing...';
        
        document.getElementById('test-response-status').innerHTML = '';
        document.getElementById('test-response-time').textContent = 'Executing...';
        document.getElementById('test-response-body').innerHTML = '<span class="admin-text-muted">Waiting for response...</span>';

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
                
                document.getElementById('test-response-status').innerHTML = 
                    `<span class="admin-badge ${statusClass}">${statusCode || 'N/A'}</span>`;
                document.getElementById('test-response-time').textContent = 
                    testResult.timing?.duration_ms ? `${testResult.timing.duration_ms}ms` : '';
                
                // Format response body
                let bodyHtml = '';
                const responseBody = testResult.response?.body;
                if (responseBody !== undefined && responseBody !== null && responseBody !== '') {
                    try {
                        const parsed = typeof responseBody === 'string' ? JSON.parse(responseBody) : responseBody;
                        bodyHtml = `<pre>${escapeHtml(JSON.stringify(parsed, null, 2))}</pre>`;
                    } catch {
                        bodyHtml = `<pre>${escapeHtml(String(responseBody))}</pre>`;
                    }
                } else {
                    bodyHtml = '<span class="admin-text-muted">Empty response</span>';
                }
                document.getElementById('test-response-body').innerHTML = bodyHtml;
            } else {
                document.getElementById('test-response-status').innerHTML = 
                    `<span class="admin-badge admin-badge--danger">Error</span>`;
                document.getElementById('test-response-body').innerHTML = 
                    `<pre class="admin-text-danger">${escapeHtml(response.data?.message || 'Request failed')}</pre>`;
            }
        } catch (error) {
            console.error('Test failed:', error);
            document.getElementById('test-response-status').innerHTML = 
                `<span class="admin-badge admin-badge--danger">Error</span>`;
            document.getElementById('test-response-body').innerHTML = 
                `<pre class="admin-text-danger">${escapeHtml(error.message)}</pre>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = `
                ${QuickSiteUtils.iconPlay(16)}
                Run Test
            `;
        }
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
            ? (window.translations?.apis?.importJson || 'Import APIs from JSON')
            : (window.translations?.apis?.importModal?.previewTitle || 'Preview & confirm');
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
                    flags.push('Host "' + a.baseUrl + '" is classified both ways across APIs — the last import wins.');
                }
                hostKinds[a.baseUrl] = p.host;
            }
            for (const ep of (a.endpoints || [])) {
                const f = ep.privacy && ep.privacy.fields;
                if (!f) continue;
                const epId = ep.id || ep.name || ep.path;
                for (const field of Object.keys(f)) {
                    if (labels[f[field]]) mappings++;
                    else flags.push('Endpoint "' + apiId + '/' + epId + '" field "' + field + '" → label "' + f[field] + '" not in this API\'s collects — will be skipped.');
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
        const fmtLabel = format === 'ours' ? 'native'
            : format === 'testapi-filemanager' ? 'test.api file-manager'
            : format === 'openapi-3' ? 'OpenAPI 3.x'
            : 'unknown';

        const root = document.createElement('div');
        root.className = 'apis-import-summary';

        const line = document.createElement('p');
        line.className = 'apis-import-summary__line';
        line.appendChild(document.createTextNode('Detected format: '));
        const strong = document.createElement('strong');
        strong.textContent = fmtLabel;
        line.appendChild(strong);
        line.appendChild(document.createTextNode(
            '. Will create ' + apiIds.length + ' API' + (apiIds.length === 1 ? '' : 's') +
            ', ' + totalEndpoints + ' endpoint' + (totalEndpoints === 1 ? '' : 's') + '.'
        ));
        if (existing.length > 0) {
            line.appendChild(document.createTextNode(
                ' Replaces ' + existing.length + ' existing: ' + existing.join(', ') + '.'
            ));
        }
        root.appendChild(line);

        // Optional privacy blocks (native format): summary line + ⚠ flags.
        const priv = _computeImportPrivacy(apis);
        if (priv.datums || priv.hosts || priv.mappings || priv.flags.length) {
            const pLine = document.createElement('p');
            pLine.className = 'apis-import-summary__line';
            pLine.appendChild(document.createTextNode(
                'Privacy: will create ' + priv.datums + ' data-collected, classify ' +
                priv.hosts + ' host' + (priv.hosts === 1 ? '' : 's') + ', map ' +
                priv.mappings + ' field' + (priv.mappings === 1 ? '' : 's') + '.'
            ));
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
            empty.textContent = 'No APIs to import.';
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
            note.textContent = 'No endpoints in this API.';
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
            authEl.textContent = 'public';
        } else if (epAuth === 'required') {
            authEl.textContent = '🔐 required';
            authEl.title = 'Operation declares a security scheme different from the API auth — refine after import.';
        } else {
            // inherit
            authEl.textContent = apiAuthType === 'none' ? 'inherit' : ('🔐 inherit (' + apiAuthType + ')');
        }
        li.appendChild(authEl);

        if (endpoint.requestSchema) {
            const reqBadge = document.createElement('span');
            reqBadge.className = 'admin-badge admin-badge--info';
            reqBadge.style.fontSize = '0.7em';
            reqBadge.title = 'Has request schema';
            reqBadge.textContent = 'req';
            li.appendChild(reqBadge);
        }
        if (endpoint.responseSchema) {
            const respBadge = document.createElement('span');
            respBadge.className = 'admin-badge admin-badge--info';
            respBadge.style.fontSize = '0.7em';
            respBadge.title = 'Has response schema';
            respBadge.textContent = 'resp';
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
            errBox.textContent = 'Paste JSON first (or use one of the example buttons).';
            errBox.style.display = '';
            return;
        }
        let data;
        try { data = JSON.parse(raw); }
        catch (err) {
            errBox.textContent = 'Invalid JSON: ' + err.message;
            errBox.style.display = '';
            return;
        }
        const fmt = detectImportFormat(data);
        if (fmt === 'swagger-2') {
            errBox.innerHTML = 'Swagger 2.0 detected — not yet supported. ' +
                'Convert your spec to OpenAPI 3.x first ' +
                '(e.g. <a href="https://converter.swagger.io" target="_blank" rel="noopener">converter.swagger.io</a>).';
            errBox.style.display = '';
            return;
        }
        if (fmt === 'unknown') {
            errBox.innerHTML = 'Unrecognised format. Expected <code>{"apis": {...}}</code>, ' +
                'OpenAPI 3.x (<code>openapi: "3.x.x"</code>), ' +
                'or test.api file-manager (<code>endpoints.public</code> / <code>endpoints.secured</code>).';
            errBox.style.display = '';
            return;
        }
        const result = convertImportPayload(data, fmt);
        if (!result || !result.converted) {
            errBox.textContent = 'Converter returned no APIs.';
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
                showToast('Preview JSON is invalid: ' + err.message, 'error');
                return;
            }
            if (!parsed || typeof parsed !== 'object' || !parsed.apis || typeof parsed.apis !== 'object') {
                showToast('Preview JSON must have a top-level "apis" object', 'error');
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
            showToast('Nothing selected — check at least one endpoint to import.', 'error');
            return;
        }
        for (const apiId of Object.keys(apis)) {
            const u = (apis[apiId] && apis[apiId].baseUrl) || '';
            if (!/^https?:\/\//i.test(u)) {
                showToast('Base URL for "' + apiId + '" must start with http:// or https:// — set it in the form above the JSON preview.', 'error');
                return;
            }
        }

        const btn = document.getElementById('btn-import-confirm');
        btn.disabled = true;
        const origLabel = btn.textContent;
        btn.textContent = 'Importing…';

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
            showToast(`Imported ${imported} API(s) successfully`, 'success');
        } else {
            showToast(`Imported ${imported} API(s) with ${errors} error(s)`, 'warning');
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
        
        showToast('APIs exported successfully', 'success');
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

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

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
        
        showToast('Template inserted', 'success');
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
            statusEl.textContent = '✓ Valid JSON';
            statusEl.className = 'admin-schema-editor__status admin-schema-editor__status--valid';
            editorEl?.classList.remove('admin-schema-editor--invalid');
            return true;
        } catch (e) {
            statusEl.textContent = '✗ Invalid JSON';
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
            
            showToast('JSON formatted', 'success');
        } catch (e) {
            showToast('Cannot format: invalid JSON', 'error');
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

