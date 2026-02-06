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

        // Import
        document.getElementById('btn-do-import')?.addEventListener('click', handleImport);

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
        document.getElementById('apis-loading').style.display = show ? 'block' : 'none';
        document.getElementById('apis-empty').style.display = 'none';
        document.getElementById('apis-list').style.display = show ? 'none' : 'block';
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
                        <svg class="api-card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="transition: transform 0.2s;">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" 
                                data-action="edit-api" data-api="${escapeHtml(apiId)}" title="Edit API">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost admin-btn--danger-hover" 
                                data-action="delete-api" data-api="${escapeHtml(apiId)}" title="Delete API">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
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
                                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="display: none;">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost api-auth-token__save" 
                                    data-api="${escapeHtml(apiId)}" title="Save token">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                                    <polyline points="17,21 17,13 7,13 7,21"/>
                                    <polyline points="7,3 7,8 15,8"/>
                                </svg>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" 
                                data-action="edit-endpoint" data-api="${escapeHtml(apiId)}" data-endpoint="${escapeHtml(endpoint.id)}" 
                                title="Edit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost admin-btn--danger-hover" 
                                data-action="delete-endpoint" data-api="${escapeHtml(apiId)}" data-endpoint="${escapeHtml(endpoint.id)}" 
                                title="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }

    // Toggle expand/collapse styling
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
        } else {
            title.textContent = window.translations?.apis?.addApi || 'Add API';
        }

        updateAuthFields();
        openModal(modal);
        document.getElementById('api-id').focus();
    }

    function updateAuthFields() {
        const authType = document.getElementById('api-auth-type').value;
        const tokenGroup = document.getElementById('auth-token-source-group');
        
        if (authType === 'none') {
            tokenGroup.style.display = 'none';
        } else {
            tokenGroup.style.display = 'block';
            updateStorageWarning();
        }
    }
    
    function updateStorageWarning() {
        const prefix = document.getElementById('api-token-source-prefix').value;
        const warning = document.getElementById('auth-config-warning');
        if (warning) {
            warning.style.display = prefix === 'config' ? 'block' : 'none';
        }
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
        
        // Build auth config
        let auth = { type: authType };
        if (authType !== 'none') {
            const prefix = document.getElementById('api-token-source-prefix').value;
            const key = document.getElementById('api-token-source-key').value.trim();
            auth.tokenSource = key ? `${prefix}:${key}` : `${prefix}:token`;
        }

        // Prepare command params
        const params = {
            apiId: apiId,
            name: name,
            baseUrl: baseUrl,
            description: description || undefined,
            auth: authType !== 'none' ? auth : undefined
        };

        try {
            let response;
            if (mode === 'edit') {
                // For edit, we need to handle ID change
                if (originalId !== apiId) {
                    // Delete old, create new
                    await QuickSiteAdmin.apiRequest('deleteApi', 'DELETE', { apiId: originalId });
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

        if (mode === 'edit' && endpointId && apisData[apiId]) {
            const endpoint = (apisData[apiId].endpoints || []).find(ep => ep.id === endpointId);
            if (endpoint) {
                title.textContent = window.translations?.apis?.editEndpoint || 'Edit Endpoint';
                document.getElementById('endpoint-id').value = endpoint.id;
                document.getElementById('endpoint-method').value = endpoint.method;
                document.getElementById('endpoint-name').value = endpoint.name || '';
                document.getElementById('endpoint-path').value = endpoint.path;
                document.getElementById('endpoint-description').value = endpoint.description || '';
                // No auth property = public (none), like OpenAPI security: []
                document.getElementById('endpoint-auth').value = endpoint.auth || 'none';
                document.getElementById('endpoint-request-schema').value = 
                    endpoint.requestSchema ? JSON.stringify(endpoint.requestSchema, null, 2) : '';
                document.getElementById('endpoint-response-schema').value = 
                    endpoint.responseSchema ? JSON.stringify(endpoint.responseSchema, null, 2) : '';
            }
        } else {
            title.textContent = window.translations?.apis?.addEndpoint || 'Add Endpoint';
            // Default to 'inherit' for new endpoints when API has auth
            document.getElementById('endpoint-auth').value = 'inherit';
        }

        openModal(modal);
        document.getElementById('endpoint-id').focus();
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
            if (reqSchemaText) requestSchema = JSON.parse(reqSchemaText);
        } catch (err) {
            showToast('Invalid request schema JSON', 'error');
            return;
        }
        
        try {
            const resSchemaText = document.getElementById('endpoint-response-schema').value.trim();
            if (resSchemaText) responseSchema = JSON.parse(resSchemaText);
        } catch (err) {
            showToast('Invalid response schema JSON', 'error');
            return;
        }

        const endpoint = {
            id: endpointId,
            method: method,
            name: name,
            path: path,
            description: description || undefined,
            // Only save auth if 'inherit' or 'required' (no property = public/none)
            auth: (auth && auth !== 'none') ? auth : undefined,
            requestSchema: requestSchema || undefined,
            responseSchema: responseSchema || undefined
        };

        // Build editApi params
        const params = { apiId: apiId };
        
        if (mode === 'edit') {
            if (originalId !== endpointId) {
                // ID changed - delete old and add new
                params.deleteEndpoint = originalId;
                params.addEndpoint = endpoint;
            } else {
                // Same ID - use editEndpoint with updates format
                const { id, ...updates } = endpoint;
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
                response = await QuickSiteAdmin.apiRequest('deleteApi', 'DELETE', { apiId: pendingDelete.apiId });
            } else {
                response = await QuickSiteAdmin.apiRequest('editApi', 'POST', { 
                    apiId: pendingDelete.apiId, 
                    deleteEndpoint: pendingDelete.endpointId 
                });
            }

            if (response.ok) {
                showToast(response.data?.message || 'Deleted successfully', 'success');
                closeModal(document.getElementById('modal-confirm-delete'));
                loadApis();
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
    
    // Storage key for auth tokens
    const AUTH_TOKEN_STORAGE_KEY = 'qs_api_auth_tokens';

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

        // Generate form fields from schema
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
        const apiId = btn.dataset.api;
        const card = btn.closest('.api-card');
        const input = card.querySelector('.api-auth-token__input');
        const iconEye = btn.querySelector('.icon-eye');
        const iconEyeOff = btn.querySelector('.icon-eye-off');
        
        if (input.type === 'password') {
            input.type = 'text';
            iconEye.style.display = 'none';
            iconEyeOff.style.display = 'block';
        } else {
            input.type = 'password';
            iconEye.style.display = 'block';
            iconEyeOff.style.display = 'none';
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
        btn.innerHTML = '<span class="admin-spinner admin-spinner--sm"></span> Testing...';
        
        document.getElementById('test-response-status').innerHTML = '';
        document.getElementById('test-response-time').textContent = 'Executing...';
        document.getElementById('test-response-body').innerHTML = '<span class="admin-text-muted">Waiting for response...</span>';

        try {
            const params = {
                apiId: apiId,
                endpointId: endpointId,
                testData: body,
                queryParams: queryParams,
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                Run Test
            `;
        }
    }

    // =========================================================================
    // Import / Export
    // =========================================================================

    function openImportModal() {
        document.getElementById('import-json').value = '';
        openModal(document.getElementById('modal-import'));
    }

    async function handleImport() {
        const jsonText = document.getElementById('import-json').value.trim();
        
        if (!jsonText) {
            showToast('Please paste JSON to import', 'error');
            return;
        }

        let importData;
        try {
            importData = JSON.parse(jsonText);
        } catch (err) {
            showToast('Invalid JSON format', 'error');
            return;
        }

        // Validate structure
        if (!importData.apis || typeof importData.apis !== 'object') {
            showToast('Invalid format: expected {"apis": {...}}', 'error');
            return;
        }

        // Import each API
        let imported = 0;
        let errors = 0;

        for (const [apiId, apiData] of Object.entries(importData.apis)) {
            try {
                // Add the API
                const response = await QuickSiteAdmin.apiRequest('addApi', 'POST', {
                    apiId: apiId,
                    name: apiData.name || apiId,
                    baseUrl: apiData.baseUrl,
                    description: apiData.description,
                    auth: apiData.auth
                });

                if (response.ok) {
                    // Add endpoints
                    for (const endpoint of (apiData.endpoints || [])) {
                        await QuickSiteAdmin.apiRequest('editApi', 'POST', {
                            apiId: apiId,
                            addEndpoint: endpoint
                        });
                    }
                    imported++;
                } else {
                    errors++;
                }
            } catch (err) {
                console.error('Import error for', apiId, err);
                errors++;
            }
        }

        closeModal(document.getElementById('modal-import'));
        loadApis();
        
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

