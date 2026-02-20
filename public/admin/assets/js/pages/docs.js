/**
 * QuickSite Admin - API Documentation Page
 * 
 * Loads and displays API documentation from the help command.
 * Supports search filtering and expand/collapse.
 * 
 * @module pages/docs
 * @requires QuickSiteAdmin
 */

(function() {
    'use strict';

    let allDocs = [];
    
    // Get config from PHP
    const config = window.QuickSiteDocsConfig || {};
    const baseUrl = config.baseUrl || '';
    const commandUrl = config.commandUrl || '/admin/command';

    /**
     * Load documentation from API
     */
    async function loadDocumentation() {
        const loading = document.getElementById('docs-loading');
        const container = document.getElementById('docs-container');
        
        if (!loading || !container) return;
        
        try {
            const result = await QuickSiteAdmin.apiRequest('help', 'GET');
            
            if (result.ok && result.data?.data) {
                const data = result.data.data;
                // Convert commands object to array with name property
                const commandsObj = data.commands || {};
                allDocs = Object.entries(commandsObj).map(([name, cmd]) => ({
                    name,
                    ...cmd,
                    // Convert parameters object to array if needed
                    parameters: cmd.parameters ? Object.entries(cmd.parameters).map(([pName, pData]) => ({
                        name: pName,
                        ...pData
                    })) : []
                }));
                
                loading.style.display = 'none';
                container.style.display = 'flex';
                
                renderDocs(allDocs);
            } else {
                loading.innerHTML = `<p class="admin-text-muted">Could not load documentation</p>`;
            }
        } catch (error) {
            loading.innerHTML = `<p class="admin-text-error">${QuickSiteAdmin.escapeHtml(error.message)}</p>`;
        }
    }

    /**
     * Render documentation cards
     */
    function renderDocs(commands) {
        const container = document.getElementById('docs-container');
        if (!container) return;
        
        let html = '';
        commands.forEach(cmd => {
            const params = cmd.parameters || [];
            const hasParams = params.length > 0;
            
            html += `
                <div class="admin-docs-command" data-command="${QuickSiteAdmin.escapeHtml(cmd.name)}">
                    <div class="admin-docs-command__header" onclick="QuickSiteDocs.toggleDoc(this)">
                        <span>
                            <span class="admin-docs-command__name">${QuickSiteAdmin.escapeHtml(cmd.name)}</span>
                            <span class="admin-docs-command__method">${cmd.method || 'GET'}</span>
                        </span>
                        <span class="admin-docs-command__desc">${QuickSiteAdmin.escapeHtml(cmd.description || '')}</span>
                        <svg class="admin-docs-command__toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                    <div class="admin-docs-command__body">
                        ${cmd.description ? `
                            <div class="admin-docs-section">
                                <h4 class="admin-docs-section__title">Description</h4>
                                <p>${QuickSiteAdmin.escapeHtml(cmd.description)}</p>
                            </div>
                        ` : ''}
                        
                        ${hasParams ? `
                            <div class="admin-docs-section">
                                <h4 class="admin-docs-section__title">Parameters</h4>
                                <div class="admin-docs-params">
                                    ${params.map(p => `
                                        <div class="admin-docs-param">
                                            <span class="admin-docs-param__name">
                                                ${QuickSiteAdmin.escapeHtml(p.name)}
                                                ${p.required ? '<span class="admin-docs-param__required">*</span>' : ''}
                                            </span>
                                            <span class="admin-docs-param__type">${QuickSiteAdmin.escapeHtml(p.type || 'string')}</span>
                                            <span class="admin-docs-param__desc">${QuickSiteAdmin.escapeHtml(p.description || 'No description')}</span>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        ` : `
                            <div class="admin-docs-section">
                                <p class="admin-text-muted">No parameters required</p>
                            </div>
                        `}
                        
                        <div class="admin-docs-actions">
                            <a href="${commandUrl}/${cmd.name}" class="admin-btn admin-btn--primary admin-btn--small">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <polygon points="5 3 19 12 5 21 5 3"/>
                                </svg>
                                Execute
                            </a>
                            <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" onclick="QuickSiteDocs.copyEndpoint('${cmd.name}')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                </svg>
                                Copy URL
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    /**
     * Toggle documentation card expand/collapse
     */
    function toggleDoc(header) {
        const command = header.closest('.admin-docs-command');
        if (command) {
            command.classList.toggle('admin-docs-command--open');
        }
    }

    /**
     * Filter documentation by search query
     */
    function filterDocs(query) {
        const lowerQuery = query.toLowerCase();
        const commands = document.querySelectorAll('.admin-docs-command');
        
        commands.forEach(cmd => {
            const name = cmd.dataset.command.toLowerCase();
            const desc = cmd.querySelector('.admin-docs-command__desc')?.textContent.toLowerCase() || '';
            
            const matches = name.includes(lowerQuery) || desc.includes(lowerQuery);
            cmd.classList.toggle('admin-docs-command--hidden', !matches);
        });
    }

    /**
     * Copy API endpoint URL to clipboard
     */
    function copyEndpoint(command) {
        const url = `${baseUrl}/management/${command}`;
        navigator.clipboard.writeText(url).then(() => {
            QuickSiteAdmin.showToast('URL copied to clipboard', 'success');
        }).catch(() => {
            QuickSiteAdmin.showToast('Could not copy URL', 'error');
        });
    }

    // Public API for onclick handlers
    window.QuickSiteDocs = {
        toggleDoc: toggleDoc,
        filterDocs: filterDocs,
        copyEndpoint: copyEndpoint
    };
    
    // Also expose filterDocs globally for backward compatibility
    window.filterDocs = filterDocs;

    /**
     * Initialize search input event listener
     */
    function initSearch() {
        const searchInput = document.getElementById('docs-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                filterDocs(this.value);
            });
        }
    }

    // Initialize when DOM is ready
    function init() {
        loadDocumentation();
        initSearch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
