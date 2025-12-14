<?php
/**
 * Admin Command History Page
 * 
 * Shows the command execution history with filtering options.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('history.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('history.subtitle') ?></p>
    <div class="admin-page-header__actions">
        <button type="button" class="admin-btn admin-btn--secondary" onclick="QuickSiteAdmin.exportHistory()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <?= __admin('history.export') ?>
        </button>
    </div>
</div>

<!-- Filters -->
<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card__body">
        <div class="admin-filter-row">
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.date') ?></label>
                <input type="date" id="filter-date" class="admin-input">
            </div>
            
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.command') ?></label>
                <input type="text" id="filter-command" class="admin-input" placeholder="e.g., getStructure">
            </div>
            
            <div class="admin-filter-group">
                <label class="admin-label"><?= __admin('history.filters.status') ?></label>
                <select id="filter-status" class="admin-select">
                    <option value=""><?= __admin('history.filters.all') ?></option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                </select>
            </div>
            
            <div class="admin-filter-group admin-filter-group--actions">
                <button type="button" class="admin-btn admin-btn--primary" onclick="loadHistory()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <?= __admin('common.search') ?>
                </button>
                <button type="button" class="admin-btn admin-btn--outline" onclick="clearFilters()">
                    <?= __admin('common.reset') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- History Table -->
<div class="admin-card">
    <div class="admin-card__body" id="history-content">
        <div class="admin-loading">
            <span class="admin-spinner"></span>
            <span><?= __admin('common.loading') ?></span>
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="admin-pagination" id="history-pagination" style="display: none;">
    <button class="admin-btn admin-btn--outline" id="prev-page" onclick="changePage(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
        <?= __admin('common.previous') ?>
    </button>
    <span class="admin-pagination__info" id="page-info"></span>
    <button class="admin-btn admin-btn--outline" id="next-page" onclick="changePage(1)">
        <?= __admin('common.next') ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="9 18 15 12 9 6"/>
        </svg>
    </button>
</div>

<!-- Detail Modal -->
<div class="admin-modal" id="detail-modal" style="display: none;">
    <div class="admin-modal__backdrop" onclick="closeModal()"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('history.columns.details') ?></h3>
            <button class="admin-modal__close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body" id="detail-content"></div>
    </div>
</div>

<script>
let currentPage = 1;
const pageSize = 20;
let totalEntries = 0;

document.addEventListener('DOMContentLoaded', function() {
    // Set default date to today
    document.getElementById('filter-date').valueAsDate = new Date();
    loadHistory();
});

async function loadHistory() {
    const container = document.getElementById('history-content');
    const pagination = document.getElementById('history-pagination');
    
    container.innerHTML = `
        <div class="admin-loading">
            <span class="admin-spinner"></span>
            <span><?= __admin('common.loading') ?></span>
        </div>
    `;
    
    // Build URL params
    const params = [];
    
    const date = document.getElementById('filter-date').value;
    if (date) {
        params.push(date);
    }
    
    try {
        const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, params);
        
        if (result.ok && result.data.data) {
            let entries = result.data.data.entries || [];
            
            // Client-side filtering
            const commandFilter = document.getElementById('filter-command').value.toLowerCase();
            const statusFilter = document.getElementById('filter-status').value;
            
            if (commandFilter) {
                entries = entries.filter(e => e.command.toLowerCase().includes(commandFilter));
            }
            
            if (statusFilter) {
                entries = entries.filter(e => {
                    const isSuccess = e.result?.status < 300;
                    return statusFilter === 'success' ? isSuccess : !isSuccess;
                });
            }
            
            totalEntries = entries.length;
            
            if (entries.length === 0) {
                container.innerHTML = `
                    <div class="admin-empty">
                        <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <h3 class="admin-empty__title"><?= __admin('history.noHistory') ?></h3>
                    </div>
                `;
                pagination.style.display = 'none';
                return;
            }
            
            // Paginate
            const start = (currentPage - 1) * pageSize;
            const pageEntries = entries.slice(start, start + pageSize);
            
            renderHistoryTable(pageEntries);
            updatePagination();
        } else {
            container.innerHTML = `
                <div class="admin-alert admin-alert--error">
                    Failed to load history: ${result.data?.message || 'Unknown error'}
                </div>
            `;
        }
    } catch (error) {
        container.innerHTML = `
            <div class="admin-alert admin-alert--error">
                Failed to load history: ${error.message}
            </div>
        `;
    }
}

function renderHistoryTable(entries) {
    const container = document.getElementById('history-content');
    
    let html = `
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?= __admin('history.columns.timestamp') ?></th>
                        <th><?= __admin('history.columns.command') ?></th>
                        <th><?= __admin('history.columns.method') ?></th>
                        <th><?= __admin('history.columns.status') ?></th>
                        <th><?= __admin('history.columns.duration') ?></th>
                        <th><?= __admin('history.columns.details') ?></th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    entries.forEach(entry => {
        // Handle both old format (status: "success") and new format (http_status: 200)
        const httpStatus = entry.result?.http_status || entry.result?.status;
        const isSuccess = typeof httpStatus === 'number' 
            ? httpStatus >= 200 && httpStatus < 300
            : httpStatus === 'success';
        const statusClass = isSuccess ? 'badge--success' : 'badge--error';
        const statusText = isSuccess ? 'Success' : 'Error';
        const timestamp = new Date(entry.timestamp).toLocaleString();
        
        html += `
            <tr>
                <td><small>${timestamp}</small></td>
                <td>
                    <a href="<?= $router->url('command') ?>/${entry.command}" class="admin-link">
                        <code>${QuickSiteAdmin.escapeHtml(entry.command)}</code>
                    </a>
                </td>
                <td><span class="badge badge--${entry.method.toLowerCase()}">${entry.method}</span></td>
                <td><span class="badge ${statusClass}">${statusText}</span></td>
                <td>${entry.duration_ms}ms</td>
                <td>
                    <button class="admin-btn admin-btn--ghost admin-btn--sm" onclick='showDetail(${JSON.stringify(entry).replace(/'/g, "\\'")})'>
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
    container.innerHTML = html;
}

function updatePagination() {
    const pagination = document.getElementById('history-pagination');
    const pageInfo = document.getElementById('page-info');
    const prevBtn = document.getElementById('prev-page');
    const nextBtn = document.getElementById('next-page');
    
    const totalPages = Math.ceil(totalEntries / pageSize);
    
    if (totalPages <= 1) {
        pagination.style.display = 'none';
        return;
    }
    
    pagination.style.display = 'flex';
    pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${totalEntries} entries)`;
    
    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages;
}

function changePage(delta) {
    currentPage += delta;
    loadHistory();
}

function clearFilters() {
    document.getElementById('filter-date').valueAsDate = new Date();
    document.getElementById('filter-command').value = '';
    document.getElementById('filter-status').value = '';
    currentPage = 1;
    loadHistory();
}

function showDetail(entry) {
    const modal = document.getElementById('detail-modal');
    const content = document.getElementById('detail-content');
    
    content.innerHTML = `
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
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('detail-modal').style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});
</script>

<style>
.admin-filter-row {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
}

.admin-filter-group {
    flex: 1;
    min-width: 150px;
}

.admin-filter-group--actions {
    display: flex;
    gap: var(--space-sm);
    flex: 0 0 auto;
}

.admin-table-wrapper {
    overflow-x: auto;
}

.admin-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: var(--space-md);
    margin-top: var(--space-lg);
}

.admin-pagination__info {
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
}

.admin-link {
    color: var(--admin-accent);
    text-decoration: none;
}

.admin-link:hover {
    text-decoration: underline;
}

.admin-btn--sm {
    padding: var(--space-xs) var(--space-sm);
}

/* Modal */
.admin-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-lg);
}

.admin-modal__backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
}

.admin-modal__content {
    position: relative;
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-lg);
    max-width: 800px;
    width: 100%;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.admin-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--admin-border);
}

.admin-modal__title {
    margin: 0;
    font-size: var(--font-size-lg);
}

.admin-modal__close {
    background: none;
    border: none;
    color: var(--admin-text-muted);
    cursor: pointer;
    padding: var(--space-xs);
}

.admin-modal__close:hover {
    color: var(--admin-text);
}

.admin-modal__body {
    padding: var(--space-lg);
    overflow-y: auto;
}

.admin-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.admin-detail-item label {
    display: block;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    margin-bottom: var(--space-xs);
    text-transform: uppercase;
}

.admin-detail-item code {
    background: var(--admin-bg);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-sm);
}

.admin-modal__body h4 {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin: var(--space-lg) 0 var(--space-sm) 0;
}

@media (max-width: 768px) {
    .admin-detail-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
