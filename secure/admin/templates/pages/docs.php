<?php
/**
 * Admin API Documentation Page
 * 
 * Displays API documentation from the help command.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('docs.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('docs.subtitle') ?></p>
</div>

<!-- Quick Reference -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <?= __admin('docs.quickReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-docs-quick">
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.apiBaseUrl') ?></h3>
                <code class="admin-docs-item__code"><?= rtrim(BASE_URL, '/') ?>/management</code>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.authentication') ?></h3>
                <p><?= __admin('docs.quickRef.includeTokenVia') ?></p>
                <ul class="admin-docs-list">
                    <li><?= __admin('docs.quickRef.header') ?> <code>X-Auth-Token: your_token</code></li>
                    <li><?= __admin('docs.quickRef.query') ?> <code>?token=your_token</code></li>
                    <li><?= __admin('docs.quickRef.postBody') ?> <code>{"token": "your_token"}</code></li>
                </ul>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.responseFormat') ?></h3>
                <pre class="admin-docs-pre">{
  "success": true|false,
  "data": {...} | null,
  "message": "Optional message"
}</pre>
            </div>
        </div>
    </div>
</div>

<!-- Command Search -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <?= __admin('docs.commandReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <input type="text" id="docs-search" class="admin-input" 
                   placeholder="<?= __admin('docs.searchPlaceholder') ?>" oninput="filterDocs(this.value)">
        </div>
        
        <div id="docs-loading" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('docs.loading') ?>
        </div>
        
        <div id="docs-container" class="admin-docs-commands" style="display: none;"></div>
    </div>
</div>

<style>
.admin-docs-quick {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--space-lg);
    max-width: 900px;
}

.admin-docs-item {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
}

.admin-docs-item__title {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
    margin: 0 0 var(--space-sm) 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.admin-docs-item__code {
    display: block;
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    color: var(--admin-accent);
    word-break: break-all;
}

.admin-docs-list {
    margin: var(--space-sm) 0 0 var(--space-lg);
    padding: 0;
    font-size: var(--font-size-sm);
}

.admin-docs-list li {
    margin-bottom: var(--space-xs);
}

.admin-docs-list code {
    background: var(--admin-bg);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

.admin-docs-pre {
    margin: var(--space-sm) 0 0 0;
    padding: var(--space-sm);
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
    overflow-x: auto;
}

.admin-docs-commands {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.admin-docs-command {
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.admin-docs-command__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    cursor: pointer;
    transition: background 0.2s;
}

.admin-docs-command__header:hover {
    background: var(--admin-surface);
}

.admin-docs-command__name {
    font-family: var(--font-mono);
    font-weight: var(--font-weight-medium);
    color: var(--admin-accent);
}

.admin-docs-command__method {
    font-size: var(--font-size-xs);
    padding: 2px 8px;
    background: var(--admin-accent);
    color: #000;
    border-radius: var(--radius-sm);
    font-weight: var(--font-weight-bold);
    margin-left: var(--space-sm);
}

.admin-docs-command__desc {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin-left: auto;
    margin-right: var(--space-md);
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-docs-command__toggle {
    width: 20px;
    height: 20px;
    transition: transform 0.2s;
}

.admin-docs-command--open .admin-docs-command__toggle {
    transform: rotate(180deg);
}

.admin-docs-command__body {
    display: none;
    padding: var(--space-md);
    border-top: 1px solid var(--admin-border);
}

.admin-docs-command--open .admin-docs-command__body {
    display: block;
}

.admin-docs-section {
    margin-bottom: var(--space-md);
}

.admin-docs-section:last-child {
    margin-bottom: 0;
}

.admin-docs-section__title {
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    color: var(--admin-text-muted);
    text-transform: uppercase;
    margin: 0 0 var(--space-sm) 0;
}

.admin-docs-params {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.admin-docs-param {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    padding: var(--space-sm);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-sm);
}

.admin-docs-param__name {
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    color: var(--admin-accent);
    min-width: 120px;
}

.admin-docs-param__required {
    color: var(--admin-danger);
    margin-left: var(--space-xs);
}

.admin-docs-param__type {
    font-size: var(--font-size-xs);
    padding: 2px 6px;
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    color: var(--admin-text-muted);
}

.admin-docs-param__desc {
    flex: 1;
    font-size: var(--font-size-sm);
    color: var(--admin-text);
}

.admin-docs-actions {
    display: flex;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--admin-border);
}

.admin-docs-command--hidden {
    display: none;
}
</style>

<script>
let allDocs = [];

document.addEventListener('DOMContentLoaded', function() {
    loadDocumentation();
});

async function loadDocumentation() {
    const loading = document.getElementById('docs-loading');
    const container = document.getElementById('docs-container');
    
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET');
        
        if (result.ok && result.data.data) {
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

function renderDocs(commands) {
    const container = document.getElementById('docs-container');
    
    let html = '';
    commands.forEach(cmd => {
        const params = cmd.parameters || [];
        const hasParams = params.length > 0;
        
        html += `
            <div class="admin-docs-command" data-command="${QuickSiteAdmin.escapeHtml(cmd.name)}">
                <div class="admin-docs-command__header" onclick="toggleDoc(this)">
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
                        <a href="<?= $router->url('command') ?>/${cmd.name}" class="admin-btn admin-btn--primary admin-btn--small">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            Execute
                        </a>
                        <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" onclick="copyEndpoint('${cmd.name}')">
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

function toggleDoc(header) {
    const command = header.closest('.admin-docs-command');
    command.classList.toggle('admin-docs-command--open');
}

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

function copyEndpoint(command) {
    const url = `<?= rtrim(BASE_URL, '/') ?>/management/${command}`;
    navigator.clipboard.writeText(url).then(() => {
        QuickSiteAdmin.showToast('URL copied to clipboard', 'success');
    }).catch(() => {
        QuickSiteAdmin.showToast('Could not copy URL', 'error');
    });
}
</script>
