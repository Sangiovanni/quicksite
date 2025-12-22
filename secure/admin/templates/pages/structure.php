<?php
/**
 * Admin Structure Viewer Page
 * 
 * Visual tree view of page/component structures.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('structure.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('structure.subtitle') ?></p>
</div>

<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <?= __admin('structure.selectStructure') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-grid admin-grid--cols-3">
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('structure.label.type') ?></label>
                <select id="structure-type" class="admin-select">
                    <option value=""><?= __admin('structure.select.type') ?></option>
                    <option value="page"><?= __admin('structure.type.page') ?></option>
                    <option value="menu"><?= __admin('structure.type.menu') ?></option>
                    <option value="footer"><?= __admin('structure.type.footer') ?></option>
                    <option value="component"><?= __admin('structure.type.component') ?></option>
                </select>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('structure.label.name') ?></label>
                <select id="structure-name" class="admin-select" disabled>
                    <option value=""><?= __admin('structure.select.typeFirst') ?></option>
                </select>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-label">&nbsp;</label>
                <button type="button" id="load-structure" class="admin-btn admin-btn--primary" disabled>
                    <?= __admin('structure.loadStructure') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Structure Tree View -->
<div class="admin-card" style="margin-top: var(--space-lg);" id="structure-card" style="display: none;">
    <div class="admin-card__header">
        <h2 class="admin-card__title" id="structure-title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span><?= __admin('structure.tree.title') ?></span>
        </h2>
        <div class="admin-card__actions">
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="expandAll()">
                <?= __admin('structure.tree.expandAll') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="collapseAll()">
                <?= __admin('structure.tree.collapseAll') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="copyStructure()">
                <?= __admin('structure.tree.copyJson') ?>
            </button>
        </div>
    </div>
    <div class="admin-card__body">
        <div id="structure-tree" class="admin-structure-tree">
            <div class="admin-empty">
                <p><?= __admin('structure.tree.empty') ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Node Details Panel -->
<div id="node-details" class="admin-node-details" style="display: none;">
    <div class="admin-node-details__header">
        <h3><?= __admin('structure.nodeDetails.title') ?></h3>
        <button type="button" class="admin-node-details__close" onclick="closeNodeDetails()">×</button>
    </div>
    <div class="admin-node-details__body" id="node-details-content">
    </div>
</div>

<script>
let currentStructure = null;
let currentType = '';
let currentName = '';
const COMMAND_BASE_URL = '<?= $router->url('command') ?>';

document.addEventListener('DOMContentLoaded', function() {
    initStructureSelectors();
});

function initStructureSelectors() {
    const typeSelect = document.getElementById('structure-type');
    const nameSelect = document.getElementById('structure-name');
    const loadBtn = document.getElementById('load-structure');
    
    typeSelect.addEventListener('change', async function() {
        const type = this.value;
        
        if (!type) {
            nameSelect.innerHTML = '<option value="">Select type first...</option>';
            nameSelect.disabled = true;
            loadBtn.disabled = true;
            return;
        }
        
        if (type === 'menu' || type === 'footer') {
            // These don't need a name
            nameSelect.innerHTML = '<option value="">Not required for ' + type + '</option>';
            nameSelect.disabled = true;
            loadBtn.disabled = false;
        } else {
            // Load pages or components
            nameSelect.disabled = true;
            nameSelect.innerHTML = '<option value="">Loading...</option>';
            
            try {
                const endpoint = type === 'page' ? 'pages' : 'components';
                const options = await QuickSiteAdmin.fetchHelperData(endpoint);
                
                nameSelect.innerHTML = '<option value="">Select ' + type + '...</option>';
                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.label;
                    nameSelect.appendChild(option);
                });
                nameSelect.disabled = false;
            } catch (error) {
                nameSelect.innerHTML = '<option value="">Error loading options</option>';
            }
            
            loadBtn.disabled = true;
        }
    });
    
    nameSelect.addEventListener('change', function() {
        loadBtn.disabled = !this.value && !['menu', 'footer'].includes(typeSelect.value);
    });
    
    loadBtn.addEventListener('click', loadStructure);
}

async function loadStructure() {
    const type = document.getElementById('structure-type').value;
    const name = document.getElementById('structure-name').value;
    const treeContainer = document.getElementById('structure-tree');
    const structureCard = document.getElementById('structure-card');
    const titleEl = document.getElementById('structure-title').querySelector('span');
    
    if (!type) return;
    
    // Store current context for later use
    currentType = type;
    currentName = name;
    
    structureCard.style.display = 'block';
    treeContainer.innerHTML = '<div class="admin-loading"><span class="admin-spinner"></span> Loading structure...</div>';
    
    try {
        let urlParams = [type];
        if (name && (type === 'page' || type === 'component')) {
            urlParams.push(name);
        }
        // Add showIds to get node identifiers
        urlParams.push('showIds');
        
        const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
        
        if (result.ok && result.data.data?.structure) {
            currentStructure = result.data.data.structure;
            titleEl.textContent = `Structure: ${type}${name ? '/' + name : ''}`;
            renderStructureTree(currentStructure);
        } else {
            treeContainer.innerHTML = `
                <div class="admin-alert admin-alert--error">
                    ${result.data?.message || 'Failed to load structure'}
                </div>
            `;
        }
    } catch (error) {
        treeContainer.innerHTML = `
            <div class="admin-alert admin-alert--error">
                Error: ${error.message}
            </div>
        `;
    }
}

function renderStructureTree(structure) {
    const container = document.getElementById('structure-tree');
    
    if (!structure || (Array.isArray(structure) && structure.length === 0)) {
        container.innerHTML = '<div class="admin-empty"><p>Structure is empty</p></div>';
        return;
    }
    
    const tree = Array.isArray(structure) ? structure : [structure];
    container.innerHTML = renderNodes(tree, 0);
}

function renderNodes(nodes, depth, parentPath = '') {
    let html = '<ul class="admin-tree">';
    
    nodes.forEach((node, index) => {
        // Build node path for ID (0, 0.1, 0.1.2, etc.)
        const nodePath = parentPath ? `${parentPath}.${index}` : `${index}`;
        // Use _nodeId if present (from showIds), otherwise use computed path
        const nodeId = node._nodeId ?? nodePath;
        
        // Handle different node types:
        // - tag: HTML element (section, div, h1, etc.)
        // - component: reusable component reference
        // - textKey: translation key (leaf node with text)
        // - text: raw text content
        const element = node.tag || node.component || (node.textKey ? 'text' : (node.text ? 'raw' : 'node'));
        const hasChildren = node.children && node.children.length > 0;
        const attributes = node.params || {};
        
        // Build label
        let label = '';
        if (node.component) {
            label = `<span class="admin-tree__component">&lt;${node.component}/&gt;</span>`;
        } else if (node.tag) {
            label = `<span class="admin-tree__element">&lt;${element}</span>`;
            
            if (attributes.id) {
                label += `<span class="admin-tree__attr-id">#${attributes.id}</span>`;
            }
            if (attributes.class) {
                const classes = Array.isArray(attributes.class) ? attributes.class.join(' ') : attributes.class;
                label += `<span class="admin-tree__attr-class">.${classes.replace(/\s+/g, '.')}</span>`;
            }
            
            label += `<span class="admin-tree__element">&gt;</span>`;
        } else if (node.textKey) {
            label = `<span class="admin-tree__trans">{{${node.textKey}}}</span>`;
        } else if (node.text) {
            const preview = node.text.length > 30 ? node.text.substring(0, 30) + '...' : node.text;
            label = `<span class="admin-tree__text">"${escapeHtml(preview)}"</span>`;
        } else {
            label = `<span class="admin-tree__element">&lt;unknown&gt;</span>`;
        }
        
        label += `<span class="admin-tree__node-id">[${nodeId}]</span>`;
        
        html += `
            <li class="admin-tree__item ${hasChildren ? 'admin-tree__item--has-children' : ''}" data-node-id="${nodeId}">
                <div class="admin-tree__row" onclick="selectNode(this, ${JSON.stringify(node).replace(/"/g, '&quot;')}, '${nodeId}')">
                    ${hasChildren ? '<span class="admin-tree__toggle" onclick="event.stopPropagation(); toggleNode(this)">▶</span>' : '<span class="admin-tree__spacer"></span>'}
                    ${label}
                </div>
        `;
        
        if (hasChildren) {
            html += renderNodes(node.children, depth + 1, nodePath);
        }
        
        html += '</li>';
    });
    
    html += '</ul>';
    return html;
}

function toggleNode(toggleEl) {
    const item = toggleEl.closest('.admin-tree__item');
    item.classList.toggle('admin-tree__item--expanded');
    toggleEl.textContent = item.classList.contains('admin-tree__item--expanded') ? '▼' : '▶';
}

function expandAll() {
    document.querySelectorAll('.admin-tree__item--has-children').forEach(item => {
        item.classList.add('admin-tree__item--expanded');
        const toggle = item.querySelector('.admin-tree__toggle');
        if (toggle) toggle.textContent = '▼';
    });
}

function collapseAll() {
    document.querySelectorAll('.admin-tree__item--has-children').forEach(item => {
        item.classList.remove('admin-tree__item--expanded');
        const toggle = item.querySelector('.admin-tree__toggle');
        if (toggle) toggle.textContent = '▶';
    });
}

function selectNode(rowEl, node, nodeId) {
    // Remove previous selection
    document.querySelectorAll('.admin-tree__row--selected').forEach(el => {
        el.classList.remove('admin-tree__row--selected');
    });
    
    rowEl.classList.add('admin-tree__row--selected');
    showNodeDetails(node, nodeId);
}

function showNodeDetails(node, nodeId) {
    const panel = document.getElementById('node-details');
    const content = document.getElementById('node-details-content');
    
    // Use params instead of attributes (actual JSON format)
    const attributes = node.params || {};
    const attrKeys = Object.keys(attributes);
    
    // Determine element type
    let elementType = 'unknown';
    if (node.tag) elementType = node.tag;
    else if (node.component) elementType = `component: ${node.component}`;
    else if (node.textKey) elementType = 'text (translation)';
    else if (node.text) elementType = 'text (raw)';
    
    let html = `
        <dl class="admin-definition-list">
            <dt>Node ID</dt>
            <dd><code>${nodeId || node._nodeId || 'N/A'}</code></dd>
            
            <dt>Element</dt>
            <dd><code>${elementType}</code></dd>
    `;
    
    // Show raw text
    if (node.text) {
        html += `
            <dt>Text Content</dt>
            <dd>"${escapeHtml(node.text)}"</dd>
        `;
    }
    
    // Show translation key (textKey in JSON)
    if (node.textKey) {
        html += `
            <dt>Translation Key</dt>
            <dd><code>{{${node.textKey}}}</code></dd>
        `;
    }
    
    // Show component reference
    if (node.component) {
        html += `
            <dt>Component</dt>
            <dd><code>${node.component}</code></dd>
        `;
    }
    
    // Show attributes/params
    if (attrKeys.length > 0) {
        html += `
            <dt>Attributes</dt>
            <dd>
                <pre class="admin-node-details__json">${escapeHtml(JSON.stringify(attributes, null, 2))}</pre>
            </dd>
        `;
    }
    
    // Build edit URL with query parameters
    const editParams = new URLSearchParams();
    editParams.set('type', currentType);
    if (currentName) editParams.set('name', currentName);
    if (nodeId) editParams.set('nodeId', nodeId);
    editParams.set('action', 'update'); // Default to update when clicking from node details
    const editUrl = `${COMMAND_BASE_URL}/editStructure?${editParams.toString()}`;
    
    html += `
            <dt>Children</dt>
            <dd>${node.children ? node.children.length : 0} child node(s)</dd>
        </dl>
        
        <div class="admin-node-details__actions">
            <a href="${editUrl}" class="admin-btn admin-btn--small admin-btn--primary">
                Edit This Node
            </a>
            <a href="${COMMAND_BASE_URL}/editStructure?type=${currentType}${currentName ? '&name=' + currentName : ''}" 
               class="admin-btn admin-btn--small admin-btn--secondary">
                Edit Full Structure
            </a>
        </div>
    `;
    
    content.innerHTML = html;
    panel.style.display = 'block';
}

function closeNodeDetails() {
    document.getElementById('node-details').style.display = 'none';
    document.querySelectorAll('.admin-tree__row--selected').forEach(el => {
        el.classList.remove('admin-tree__row--selected');
    });
}

function copyStructure() {
    if (currentStructure) {
        navigator.clipboard.writeText(JSON.stringify(currentStructure, null, 2)).then(() => {
            QuickSiteAdmin.showToast('Structure JSON copied to clipboard', 'success');
        });
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
.admin-structure-tree {
    max-height: 600px;
    overflow: auto;
    font-family: var(--font-family-mono);
    font-size: var(--font-size-sm);
}

.admin-tree {
    list-style: none;
    margin: 0;
    padding-left: var(--space-lg);
}

.admin-tree:first-child {
    padding-left: 0;
}

.admin-tree__item {
    position: relative;
}

.admin-tree__item--has-children > .admin-tree {
    display: none;
}

.admin-tree__item--has-children.admin-tree__item--expanded > .admin-tree {
    display: block;
}

.admin-tree__row {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition-fast);
}

.admin-tree__row:hover {
    background: var(--admin-bg-tertiary);
}

.admin-tree__row--selected {
    background: var(--admin-accent-muted);
}

.admin-tree__toggle {
    width: 16px;
    text-align: center;
    color: var(--admin-text-muted);
    cursor: pointer;
    user-select: none;
}

.admin-tree__spacer {
    width: 16px;
}

.admin-tree__element {
    color: var(--admin-info);
}

.admin-tree__attr-id {
    color: var(--admin-warning);
}

.admin-tree__attr-class {
    color: var(--admin-success);
}

.admin-tree__node-id {
    color: var(--admin-text-light);
    font-size: var(--font-size-xs);
}

.admin-tree__text {
    color: var(--admin-text-muted);
    font-style: italic;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-tree__trans {
    color: var(--admin-accent);
}

.admin-tree__component {
    color: var(--admin-warning);
    font-weight: 500;
}

/* Node Details Panel */
.admin-node-details {
    position: fixed;
    top: calc(var(--admin-header-height) + var(--space-lg));
    right: var(--space-lg);
    width: 360px;
    max-height: calc(100vh - var(--admin-header-height) - var(--space-2xl));
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    z-index: 50;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.admin-node-details__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--admin-border);
}

.admin-node-details__header h3 {
    margin: 0;
    font-size: var(--font-size-base);
    color: var(--admin-text);
}

.admin-node-details__close {
    background: none;
    border: none;
    color: var(--admin-text-muted);
    font-size: var(--font-size-xl);
    cursor: pointer;
    padding: 0;
    line-height: 1;
}

.admin-node-details__close:hover {
    color: var(--admin-text);
}

.admin-node-details__body {
    padding: var(--space-lg);
    overflow-y: auto;
    flex: 1;
}

.admin-node-details__json {
    margin: 0;
    padding: var(--space-sm);
    background: var(--admin-bg);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    white-space: pre-wrap;
    word-break: break-all;
}

.admin-node-details__actions {
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-definition-list dt {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
    margin-bottom: 2px;
}

.admin-definition-list dd {
    margin: 0 0 var(--space-md) 0;
}

.admin-definition-list dd code {
    background: var(--admin-bg);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    color: var(--admin-accent);
}
</style>
