/**
 * QuickSite Admin - History Page
 * 
 * Command execution history with filtering, pagination, and detail view.
 * 
 * Dependencies:
 * - QuickSiteAdmin (admin.js)
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';

    // State
    let currentPage = 1;
    const pageSize = 50;
    let totalEntries = 0;
    let totalPages = 0;

    // DOM Elements (cached after init)
    let filterDate, filterCommand, filterStatus;
    let historyContent, pagination, pageInfo, prevBtn, nextBtn;
    let detailModal, detailContent;

    /**
     * Initialize the history page
     */
    function init() {
        // Cache DOM elements
        filterDate = document.getElementById('filter-date');
        filterCommand = document.getElementById('filter-command');
        filterStatus = document.getElementById('filter-status');
        historyContent = document.getElementById('history-content');
        pagination = document.getElementById('history-pagination');
        pageInfo = document.getElementById('page-info');
        prevBtn = document.getElementById('prev-page');
        nextBtn = document.getElementById('next-page');
        detailModal = document.getElementById('detail-modal');
        detailContent = document.getElementById('detail-content');

        // Set default date to today
        if (filterDate) {
            filterDate.valueAsDate = new Date();
        }

        // Load initial history
        loadHistory();

        // Enter key to search
        if (filterCommand) {
            filterCommand.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') loadHistory();
            });
        }

        // Escape to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Load history from API with current filters
     */
    async function loadHistory() {
        const t = window.QUICKSITE_CONFIG?.translations?.common || {};
        
        historyContent.innerHTML = `
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span>${t.loading || 'Loading...'}</span>
            </div>
        `;

        // Build query parameters
        const date = filterDate?.value || '';
        const commandFilter = filterCommand?.value.trim() || '';
        const statusFilter = filterStatus?.value || '';

        const queryParams = {
            page: currentPage,
            limit: pageSize
        };

        if (date) {
            queryParams.start_date = date;
            queryParams.end_date = date;
        }

        if (commandFilter) {
            queryParams.command = commandFilter;
        }

        if (statusFilter) {
            queryParams.status = statusFilter;
        }

        try {
            const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, [], queryParams);

            if (result.ok && result.data?.data) {
                const entries = result.data.data.entries || [];
                const paginationData = result.data.data.pagination || {};

                totalEntries = paginationData.total || entries.length;
                totalPages = paginationData.pages || Math.ceil(totalEntries / pageSize);
                currentPage = paginationData.page || currentPage;

                if (entries.length === 0) {
                    renderEmpty();
                    return;
                }

                renderHistoryTable(entries);
                updatePagination();
            } else {
                renderError(result.data?.message || 'Unknown error');
            }
        } catch (error) {
            renderError(error.message);
        }
    }

    /**
     * Render empty state
     */
    function renderEmpty() {
        const t = window.QUICKSITE_CONFIG?.translations?.history || {};
        
        historyContent.innerHTML = `
            <div class="admin-empty">
                <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <h3 class="admin-empty__title">${t.noHistory || 'No history found'}</h3>
                <p class="admin-empty__desc">${t.noHistoryFiltered || 'No command history found for the selected filters.'}</p>
            </div>
        `;
        pagination.style.display = 'none';
    }

    /**
     * Render error state
     */
    function renderError(message) {
        historyContent.innerHTML = `
            <div class="admin-alert admin-alert--error">
                Failed to load history: ${QuickSiteAdmin.escapeHtml(message)}
            </div>
        `;
        pagination.style.display = 'none';
    }

    /**
     * Render the history table
     */
    function renderHistoryTable(entries) {
        const t = window.QUICKSITE_CONFIG?.translations?.history?.columns || {};
        const commandUrl = window.QUICKSITE_CONFIG?.commandUrl || '';

        let html = `
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>${t.timestamp || 'Timestamp'}</th>
                            <th>${t.command || 'Command'}</th>
                            <th>${t.method || 'Method'}</th>
                            <th>${t.status || 'Status'}</th>
                            <th>${t.duration || 'Duration'}</th>
                            <th>${t.details || 'Details'}</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        entries.forEach(entry => {
            const httpStatus = entry.result?.http_status || entry.result?.status;
            const isSuccess = typeof httpStatus === 'number'
                ? httpStatus >= 200 && httpStatus < 300
                : httpStatus === 'success';
            const statusClass = isSuccess ? 'badge--success' : 'badge--error';
            const statusText = isSuccess ? 'Success' : 'Error';
            const timestamp = new Date(entry.timestamp).toLocaleString();
            const entryJson = JSON.stringify(entry).replace(/'/g, "\\'").replace(/"/g, '&quot;');

            html += `
                <tr>
                    <td><small>${timestamp}</small></td>
                    <td>
                        <a href="${commandUrl}/${entry.command}" class="admin-link">
                            <code>${QuickSiteAdmin.escapeHtml(entry.command)}</code>
                        </a>
                    </td>
                    <td><span class="badge badge--${entry.method.toLowerCase()}">${entry.method}</span></td>
                    <td><span class="badge ${statusClass}">${statusText}</span></td>
                    <td>${entry.duration_ms}ms</td>
                    <td>
                        <button class="admin-btn admin-btn--ghost admin-btn--sm" onclick="showDetail('${entryJson}')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        historyContent.innerHTML = html;
    }

    /**
     * Update pagination controls
     */
    function updatePagination() {
        if (totalPages <= 1) {
            pagination.style.display = 'none';
            return;
        }

        pagination.style.display = 'flex';
        pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${totalEntries} entries)`;

        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    /**
     * Change page
     */
    function changePage(delta) {
        currentPage += delta;
        loadHistory();
    }

    /**
     * Clear date filter and show all (last 7 days)
     */
    function clearDateFilter() {
        if (filterDate) filterDate.value = '';
        currentPage = 1;
        loadHistory();
    }

    /**
     * Clear all filters
     */
    function clearFilters() {
        if (filterDate) filterDate.valueAsDate = new Date();
        if (filterCommand) filterCommand.value = '';
        if (filterStatus) filterStatus.value = '';
        currentPage = 1;
        loadHistory();
    }

    /**
     * Show detail modal for an entry
     */
    function showDetail(entryJson) {
        const entry = typeof entryJson === 'string' ? JSON.parse(entryJson.replace(/&quot;/g, '"')) : entryJson;
        
        detailContent.innerHTML = `
            <div class="admin-detail-grid">
                <div class="admin-detail-item">
                    <label>ID</label>
                    <code>${QuickSiteAdmin.escapeHtml(entry.id)}</code>
                </div>
                <div class="admin-detail-item">
                    <label>Timestamp</label>
                    <span>${new Date(entry.timestamp).toLocaleString()}</span>
                </div>
                <div class="admin-detail-item">
                    <label>Command</label>
                    <code>${QuickSiteAdmin.escapeHtml(entry.command)}</code>
                </div>
                <div class="admin-detail-item">
                    <label>Method</label>
                    <span class="badge badge--${entry.method.toLowerCase()}">${entry.method}</span>
                </div>
                <div class="admin-detail-item">
                    <label>Duration</label>
                    <span>${entry.duration_ms}ms</span>
                </div>
                <div class="admin-detail-item">
                    <label>Publisher</label>
                    <span>${QuickSiteAdmin.escapeHtml(entry.publisher || 'N/A')}</span>
                </div>
            </div>

            <h4>Request Body</h4>
            <div class="admin-code">
                <pre>${QuickSiteAdmin.escapeHtml(JSON.stringify(entry.body, null, 2))}</pre>
            </div>

            <h4>Response</h4>
            <div class="admin-code admin-code--response">
                <pre>${QuickSiteAdmin.escapeHtml(JSON.stringify(entry.result, null, 2))}</pre>
            </div>
        `;

        detailModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     * Close detail modal
     */
    function closeModal() {
        if (detailModal) {
            detailModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', init);

    // Export functions for onclick handlers
    window.loadHistory = loadHistory;
    window.changePage = changePage;
    window.clearDateFilter = clearDateFilter;
    window.clearFilters = clearFilters;
    window.showDetail = showDetail;
    window.closeModal = closeModal;

})();
