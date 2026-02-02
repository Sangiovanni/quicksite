/**
 * QuickSite Admin - Structure Viewer Page
 * 
 * Visual tree view of page/component structures.
 * 
 * Dependencies:
 * - QuickSiteAdmin (admin.js)
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';

    // State
    let currentStructure = null;
    let currentType = '';
    let currentName = '';

    // Config (set by PHP)
    const COMMAND_BASE_URL = window.QUICKSITE_CONFIG?.commandUrl || '';

    // DOM Elements
    let typeSelect, nameSelect, loadBtn;
    let structureCard, treeContainer, titleEl;
    let nodeDetailsPanel, nodeDetailsContent;

    /**
     * Initialize the structure page
     */
    function init() {
        // Cache DOM elements
        typeSelect = document.getElementById('structure-type');
        nameSelect = document.getElementById('structure-name');
        loadBtn = document.getElementById('load-structure');
        structureCard = document.getElementById('structure-card');
        treeContainer = document.getElementById('structure-tree');
        titleEl = document.getElementById('structure-title')?.querySelector('span');
        nodeDetailsPanel = document.getElementById('node-details');
        nodeDetailsContent = document.getElementById('node-details-content');

        initStructureSelectors();
    }

    /**
     * Initialize structure type/name selectors
     */
    function initStructureSelectors() {
        if (!typeSelect || !nameSelect || !loadBtn) return;

        const t = window.QUICKSITE_CONFIG?.translations?.structure?.select || {};

        typeSelect.addEventListener('change', async function() {
            const type = this.value;

            if (!type) {
                nameSelect.innerHTML = `<option value="">${t.typeFirst || 'Select type first...'}</option>`;
                nameSelect.disabled = true;
                loadBtn.disabled = true;
                return;
            }

            if (type === 'menu' || type === 'footer') {
                nameSelect.innerHTML = `<option value="">${(t.notRequired || 'Not required for :type').replace(':type', type)}</option>`;
                nameSelect.disabled = true;
                loadBtn.disabled = false;
            } else {
                nameSelect.disabled = true;
                nameSelect.innerHTML = '<option value="">Loading...</option>';

                try {
                    const endpoint = type === 'page' ? 'pages' : 'components';
                    const options = await QuickSiteAdmin.fetchHelperData(endpoint);

                    nameSelect.innerHTML = `<option value="">${(t.selectType || 'Select :type...').replace(':type', type)}</option>`;
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

    /**
     * Load and display structure
     */
    async function loadStructure() {
        const type = typeSelect?.value;
        const name = nameSelect?.value;

        if (!type) return;

        currentType = type;
        currentName = name;

        const t = window.QUICKSITE_CONFIG?.translations?.structure || {};

        structureCard.style.display = 'block';
        treeContainer.innerHTML = `<div class="admin-loading"><span class="admin-spinner"></span> ${t.tree?.loading || 'Loading structure...'}</div>`;

        try {
            let urlParams = [type];
            if (name && (type === 'page' || type === 'component')) {
                urlParams.push(name);
            }
            urlParams.push('showIds');

            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);

            if (result.ok && result.data?.data?.structure) {
                currentStructure = result.data.data.structure;
                if (titleEl) {
                    titleEl.textContent = `Structure: ${type}${name ? '/' + name : ''}`;
                }
                renderStructureTree(currentStructure);
            } else {
                treeContainer.innerHTML = `
                    <div class="admin-alert admin-alert--error">
                        ${result.data?.message || t.errors?.loadFailed || 'Failed to load structure'}
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

    /**
     * Render the structure tree
     */
    function renderStructureTree(structure) {
        if (!structure || (Array.isArray(structure) && structure.length === 0)) {
            const t = window.QUICKSITE_CONFIG?.translations?.structure?.tree || {};
            treeContainer.innerHTML = `<div class="admin-empty"><p>${t.isEmpty || 'Structure is empty'}</p></div>`;
            return;
        }

        const tree = Array.isArray(structure) ? structure : [structure];
        treeContainer.innerHTML = renderNodes(tree, 0);
    }

    /**
     * Render tree nodes recursively
     */
    function renderNodes(nodes, depth, parentPath = '') {
        let html = '<ul class="admin-tree">';

        nodes.forEach((node, index) => {
            const nodePath = parentPath ? `${parentPath}.${index}` : `${index}`;
            const nodeId = node._nodeId ?? nodePath;

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

            const nodeJson = JSON.stringify(node).replace(/"/g, '&quot;');

            html += `
                <li class="admin-tree__item ${hasChildren ? 'admin-tree__item--has-children' : ''}" data-node-id="${nodeId}">
                    <div class="admin-tree__row" onclick="selectNode(this, '${nodeJson}', '${nodeId}')">
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

    /**
     * Toggle tree node expand/collapse
     */
    function toggleNode(toggleEl) {
        const item = toggleEl.closest('.admin-tree__item');
        item.classList.toggle('admin-tree__item--expanded');
        toggleEl.textContent = item.classList.contains('admin-tree__item--expanded') ? '▼' : '▶';
    }

    /**
     * Expand all tree nodes
     */
    function expandAll() {
        document.querySelectorAll('.admin-tree__item--has-children').forEach(item => {
            item.classList.add('admin-tree__item--expanded');
            const toggle = item.querySelector('.admin-tree__toggle');
            if (toggle) toggle.textContent = '▼';
        });
    }

    /**
     * Collapse all tree nodes
     */
    function collapseAll() {
        document.querySelectorAll('.admin-tree__item--has-children').forEach(item => {
            item.classList.remove('admin-tree__item--expanded');
            const toggle = item.querySelector('.admin-tree__toggle');
            if (toggle) toggle.textContent = '▶';
        });
    }

    /**
     * Select a node and show details
     */
    function selectNode(rowEl, nodeJson, nodeId) {
        // Remove previous selection
        document.querySelectorAll('.admin-tree__row--selected').forEach(el => {
            el.classList.remove('admin-tree__row--selected');
        });

        rowEl.classList.add('admin-tree__row--selected');
        
        const node = typeof nodeJson === 'string' ? JSON.parse(nodeJson.replace(/&quot;/g, '"')) : nodeJson;
        showNodeDetails(node, nodeId);
    }

    /**
     * Show node details panel
     */
    function showNodeDetails(node, nodeId) {
        const attributes = node.params || {};
        const attrKeys = Object.keys(attributes);

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

        if (node.text) {
            html += `
                <dt>Text Content</dt>
                <dd>"${escapeHtml(node.text)}"</dd>
            `;
        }

        if (node.textKey) {
            html += `
                <dt>Translation Key</dt>
                <dd><code>{{${node.textKey}}}</code></dd>
            `;
        }

        if (node.component) {
            html += `
                <dt>Component</dt>
                <dd><code>${node.component}</code></dd>
            `;
        }

        if (attrKeys.length > 0) {
            html += `
                <dt>Attributes</dt>
                <dd>
                    <pre class="admin-node-details__json">${escapeHtml(JSON.stringify(attributes, null, 2))}</pre>
                </dd>
            `;
        }

        const editParams = new URLSearchParams();
        editParams.set('type', currentType);
        if (currentName) editParams.set('name', currentName);
        if (nodeId) editParams.set('nodeId', nodeId);
        editParams.set('action', 'update');
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

        nodeDetailsContent.innerHTML = html;
        nodeDetailsPanel.style.display = 'block';
    }

    /**
     * Close node details panel
     */
    function closeNodeDetails() {
        if (nodeDetailsPanel) {
            nodeDetailsPanel.style.display = 'none';
        }
        document.querySelectorAll('.admin-tree__row--selected').forEach(el => {
            el.classList.remove('admin-tree__row--selected');
        });
    }

    /**
     * Copy structure JSON to clipboard
     */
    function copyStructure() {
        if (currentStructure) {
            const t = window.QUICKSITE_CONFIG?.translations?.structure?.toast || {};
            navigator.clipboard.writeText(JSON.stringify(currentStructure, null, 2)).then(() => {
                QuickSiteAdmin.showToast(t.jsonCopied || 'Structure JSON copied to clipboard', 'success');
            });
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', init);

    // Export functions for onclick handlers
    window.toggleNode = toggleNode;
    window.expandAll = expandAll;
    window.collapseAll = collapseAll;
    window.selectNode = selectNode;
    window.closeNodeDetails = closeNodeDetails;
    window.copyStructure = copyStructure;

})();
