/**
 * Dashboard Page JavaScript
 * 
 * Handles dashboard statistics, sitemap display, project management,
 * and storage overview functionality.
 * 
 * @version 2.0.0 - Extracted from inline PHP
 */

(function() {
    'use strict';

    // ========================================================================
    // Module State
    // ========================================================================
    
    let currentProject = null;
    let allProjects = [];
    let pendingRestoreBackup = null;
    let manageSpaceLoaded = false;
    let dashStructureLoaded = null; // Stores { type, name, structure }

    // ========================================================================
    // Helper Functions
    // ========================================================================

    /**
     * Get translation string with fallback
     */
    function t(path, fallback) {
        const translations = window.QUICKSITE_CONFIG?.translations || {};
        const parts = path.split('.');
        let current = translations;
        for (const part of parts) {
            if (current && typeof current === 'object' && part in current) {
                current = current[part];
            } else {
                return fallback;
            }
        }
        return current || fallback;
    }

    /**
     * Get coverage class based on percentage
     */
    function getCoverageClass(percent) {
        if (percent >= 95) return 'sitemap__coverage--excellent';
        if (percent >= 80) return 'sitemap__coverage--good';
        if (percent >= 50) return 'sitemap__coverage--warning';
        return 'sitemap__coverage--poor';
    }

    /**
     * Get flag emoji for language code
     */
    function getFlagEmoji(langCode) {
        const flags = {
            'en': '🇬🇧', 'fr': '🇫🇷', 'es': '🇪🇸', 'de': '🇩🇪', 'it': '🇮🇹',
            'pt': '🇵🇹', 'nl': '🇳🇱', 'ru': '🇷🇺', 'zh': '🇨🇳', 'ja': '🇯🇵',
            'ko': '🇰🇷', 'ar': '🇸🇦', 'hi': '🇮🇳', 'tr': '🇹🇷', 'pl': '🇵🇱',
            'sv': '🇸🇪', 'da': '🇩🇰', 'fi': '🇫🇮', 'no': '🇳🇴', 'cs': '🇨🇿',
            'el': '🇬🇷', 'he': '🇮🇱', 'th': '🇹🇭', 'vi': '🇻🇳', 'id': '🇮🇩',
            'ms': '🇲🇾', 'uk': '🇺🇦', 'ro': '🇷🇴', 'hu': '🇭🇺', 'bg': '🇧🇬',
            'sk': '🇸🇰', 'hr': '🇭🇷', 'sl': '🇸🇮', 'et': '🇪🇪', 'lv': '🇱🇻',
            'lt': '🇱🇹'
        };
        return flags[langCode.toLowerCase()] || '🌐';
    }

    /**
     * Format bytes to human readable size
     */
    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    /**
     * Build a tree structure from flat route list
     */
    function buildRouteTree(routes) {
        const tree = {};
        
        routes.forEach(route => {
            const parts = String(route.name).split('/');
            let current = tree;
            
            parts.forEach((part, index) => {
                if (!current[part]) {
                    current[part] = {};
                }
                if (index === parts.length - 1) {
                    current[part]._route = route;
                }
                current = current[part];
            });
        });
        
        return tree;
    }

    /**
     * Render route tree as HTML
     */
    function renderRouteTree(tree, lang, depth = 0) {
        let html = '';
        const entries = Object.entries(tree).filter(([key]) => key !== '_route');
        
        entries.sort(([a], [b]) => {
            if (a === 'home') return -1;
            if (b === 'home') return 1;
            return a.localeCompare(b);
        });
        
        entries.forEach(([name, node]) => {
            const route = node._route;
            const children = Object.entries(node).filter(([key]) => key !== '_route');
            const hasChildren = children.length > 0;
            const isHome = name === 'home';
            
            if (route) {
                const url = route.urls[lang] || route.urls['default'];
                const routePath = route.path;
                
                if (hasChildren) {
                    const isExpanded = depth === 0;
                    html += `<div class="sitemap__tree-node${isExpanded ? ' sitemap__tree-node--open' : ''}" style="--depth: ${depth}">
                        <div class="sitemap__tree-header">
                            <button class="sitemap__tree-toggle" type="button" aria-label="Toggle">
                                ${QuickSiteUtils.iconChevronRight(12)}
                            </button>
                            <a href="${QuickSiteAdmin.escapeHtml(url)}" target="_blank" class="sitemap__route sitemap__route--parent" title="${QuickSiteAdmin.escapeHtml(url)}">
                                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.folder, 14, 'sitemap__route-icon')}
                                <span class="sitemap__route-name">${name}</span>
                                <span class="sitemap__route-path">${routePath}</span>
                                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.externalLink, 12, 'sitemap__route-external')}
                            </a>
                        </div>
                        <div class="sitemap__tree-children">
                            ${renderRouteTree(node, lang, depth + 1)}
                        </div>
                    </div>`;
                } else {
                    const iconPath = isHome 
                        ? QuickSiteUtils.ICON_PATHS.home
                        : QuickSiteUtils.ICON_PATHS.file;
                    
                    html += `<a href="${QuickSiteAdmin.escapeHtml(url)}" target="_blank" class="sitemap__route sitemap__route--leaf" style="--depth: ${depth}" title="${QuickSiteAdmin.escapeHtml(url)}">
                        <svg class="sitemap__route-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            ${iconPath}
                        </svg>
                        <span class="sitemap__route-name">${name}</span>
                        <span class="sitemap__route-path">${routePath}</span>
                        ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.externalLink, 12, 'sitemap__route-external')}
                    </a>`;
                }
            }
        });
        
        return html;
    }

    /**
     * Close all modals
     */
    function closeAllModals() {
        document.querySelectorAll('.admin-modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }

    // ========================================================================
    // Dashboard Stats
    // ========================================================================

    async function loadDashboardStats() {
        try {
            const hp = (cmd) => window.QuickSiteAdmin?.hasPermission(cmd) ?? true;

            const routesResult = await QuickSiteAdmin.apiRequest('getRoutes');
            if (routesResult.ok) {
                document.getElementById('stat-routes').textContent = routesResult.data.data?.count || 0;
            }
            
            if (hp('listPages')) {
                const pagesResult = await QuickSiteAdmin.apiRequest('listPages');
                if (pagesResult.ok) {
                    document.getElementById('stat-pages').textContent = pagesResult.data.data?.count || 0;
                }
            }
            
            if (hp('listComponents')) {
                const componentsResult = await QuickSiteAdmin.apiRequest('listComponents');
                if (componentsResult.ok) {
                    document.getElementById('stat-components').textContent = componentsResult.data.data?.count || 0;
                }
            }
            
            if (hp('getLangList')) {
                const langResult = await QuickSiteAdmin.apiRequest('getLangList');
                if (langResult.ok) {
                    document.getElementById('stat-languages').textContent = langResult.data.data?.languages?.length || 1;
                }
            }
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }

    // ========================================================================
    // Site Map
    // ========================================================================

    async function loadSiteMap() {
        const container = document.getElementById('sitemap-container');
        const sitemap = t('dashboard.sitemap', {});
        
        try {
            const [sitemapResult, validationResult] = await Promise.all([
                QuickSiteAdmin.apiRequest('getSiteMap'),
                QuickSiteAdmin.apiRequest('validateTranslations')
            ]);
            
            if (!sitemapResult.ok || !sitemapResult.data?.data) {
                throw new Error(sitemapResult.data?.message || 'Failed to load sitemap');
            }
            
            const data = sitemapResult.data.data;
            const coverage = validationResult.ok ? validationResult.data?.data?.validation_results : {};
            const multilingual = data.multilingual || false;
            const defaultLang = data.defaultLang || 'en';
            const languages = data.languages || [];
            const languageNames = data.languageNames || {};
            const routes = data.routes || [];
            
            const routeTree = buildRouteTree(routes);
            
            let html = '<div class="sitemap">';
            
            if (multilingual) {
                html += `<div class="sitemap__summary">
                    <span class="sitemap__total">${data.totalUrls} ${sitemap.urls || 'URLs'}</span>
                    <span class="sitemap__divider">•</span>
                    <span>${routes.length} ${sitemap.routes || 'routes'}</span>
                    <span class="sitemap__divider">•</span>
                    <span>${languages.length} ${sitemap.languages || 'languages'}</span>
                </div>`;
            } else {
                html += `<div class="sitemap__summary">
                    <span class="sitemap__total">${data.totalUrls} ${sitemap.urls || 'URLs'}</span>
                    <span class="sitemap__divider">•</span>
                    <span>${routes.length} ${sitemap.routes || 'routes'}</span>
                    <span class="sitemap__divider">•</span>
                    <span class="badge badge--ghost">${sitemap.monolingual || 'Single language'}</span>
                </div>`;
            }
            
            if (multilingual) {
                const sortedLangs = [...languages].sort((a, b) => {
                    if (a === defaultLang) return -1;
                    if (b === defaultLang) return 1;
                    return a.localeCompare(b);
                });
                
                html += '<div class="sitemap__languages">';
                
                sortedLangs.forEach((lang) => {
                    const langName = languageNames[lang] || lang.toUpperCase();
                    const isDefault = lang === defaultLang;
                    const isOpen = isDefault;
                    const langCoverage = coverage[lang];
                    const coveragePercent = langCoverage?.coverage_percent ?? null;
                    
                    html += `<div class="sitemap__lang ${isOpen ? 'sitemap__lang--open' : ''}" data-lang="${lang}">`;
                    
                    html += `<div class="sitemap__lang-header" data-toggle-lang="${lang}">
                        ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.chevronRight, 16, 'sitemap__lang-toggle')}
                        <span class="sitemap__lang-flag">${getFlagEmoji(lang)}</span>
                        <span class="sitemap__lang-name">${QuickSiteAdmin.escapeHtml(langName)}</span>
                        ${isDefault ? `<span class="badge badge--primary">${sitemap.default || 'Default'}</span>` : ''}
                        <span class="sitemap__lang-count">${routes.length} ${sitemap.pages || 'pages'}</span>
                        ${coveragePercent !== null ? `<span class="sitemap__lang-coverage ${getCoverageClass(coveragePercent)}">${coveragePercent}%</span>` : ''}
                    </div>`;
                    
                    html += '<div class="sitemap__routes sitemap__routes--tree">';
                    html += renderRouteTree(routeTree, lang);
                    html += '</div>';
                    
                    html += '</div>';
                });
                
                html += '</div>';
            } else {
                html += '<div class="sitemap__routes sitemap__routes--flat sitemap__routes--tree">';
                html += renderRouteTree(routeTree, 'default');
                html += '</div>';
            }
            
            html += '</div>';
            
            container.innerHTML = html;
            
            // Event delegation for toggles
            container.addEventListener('click', function(e) {
                const header = e.target.closest('[data-toggle-lang]');
                if (header) {
                    e.preventDefault();
                    e.stopPropagation();
                    const langEl = header.closest('.sitemap__lang');
                    if (langEl) {
                        langEl.classList.toggle('sitemap__lang--open');
                    }
                }
                
                const treeToggle = e.target.closest('.sitemap__tree-toggle');
                if (treeToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    const nodeEl = treeToggle.closest('.sitemap__tree-node');
                    if (nodeEl) {
                        nodeEl.classList.toggle('sitemap__tree-node--open');
                    }
                }
            });
            
        } catch (error) {
            console.error('Failed to load sitemap:', error);
            container.innerHTML = `
                <div class="admin-empty" style="padding: var(--space-lg);">
                    <p>${sitemap.error || 'Failed to load site map'}</p>
                </div>
            `;
        }
    }

    // ========================================================================
    // Recent Commands
    // ========================================================================

    async function loadRecentCommands() {
        const container = document.getElementById('recent-commands');
        const cols = t('dashboard.history.columns', {});
        const common = t('common', {});
        const noHistoryText = t('dashboard.noHistory', 'No recent commands');
        
        try {
            const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, []);
            
            if (result.ok && result.data.data?.entries?.length > 0) {
                const entries = result.data.data.entries.slice(0, 5);
                
                let html = '<table class="admin-table"><thead><tr>';
                html += `<th>${cols.command || 'Command'}</th><th>${cols.status || 'Status'}</th><th>${cols.duration || 'Duration'}</th><th>${cols.time || 'Time'}</th>`;
                html += '</tr></thead><tbody>';
                
                entries.forEach(entry => {
                    const httpStatus = entry.result?.http_status || entry.result?.status;
                    const isSuccess = typeof httpStatus === 'number' 
                        ? httpStatus >= 200 && httpStatus < 300
                        : httpStatus === 'success';
                    const statusClass = isSuccess ? 'badge--success' : 'badge--error';
                    const statusText = isSuccess ? (common.success || 'Success') : (common.error || 'Error');
                    
                    html += `<tr>
                        <td><code>${QuickSiteAdmin.escapeHtml(entry.command)}</code></td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>${entry.duration_ms}ms</td>
                        <td>${new Date(entry.timestamp).toLocaleString()}</td>
                    </tr>`;
                });
                
                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="admin-empty" style="padding: var(--space-lg);">
                        <p>${noHistoryText}</p>
                    </div>
                `;
            }
        } catch (error) {
            container.innerHTML = `
                <div class="admin-empty" style="padding: var(--space-lg);">
                    <p>${noHistoryText}</p>
                </div>
            `;
        }
    }

    // ========================================================================
    // Project Manager
    // ========================================================================

    async function loadProjectManager() {
        const infoContainer = document.getElementById('current-project-info');
        const selector = document.getElementById('project-selector');
        const switchBtn = document.getElementById('btn-switch-project');
        const proj = t('dashboard.projects', {});
        
        try {
            const [activeResult, listResult] = await Promise.all([
                QuickSiteAdmin.apiRequest('getActiveProject'),
                QuickSiteAdmin.apiRequest('listProjects')
            ]);
            
            if (activeResult.ok && activeResult.data?.data?.project) {
                currentProject = activeResult.data.data.project;
                const created = activeResult.data.data.created_at;
                
                infoContainer.innerHTML = `
                    <div class="project-manager__info">
                        <div class="project-manager__name">
                            ${QuickSiteUtils.iconFolder(20)}
                            <span>${QuickSiteAdmin.escapeHtml(currentProject)}</span>
                            <span class="badge badge--primary">${proj.active || 'Active'}</span>
                        </div>
                        ${created ? `<div class="project-manager__meta">${proj.created || 'Created'}: ${new Date(created).toLocaleDateString()}</div>` : ''}
                    </div>
                `;
            } else {
                currentProject = null;
                const errorMsg = activeResult.data?.message || proj.error || 'Failed to load active project';
                infoContainer.innerHTML = `<p class="admin-error">${QuickSiteAdmin.escapeHtml(errorMsg)}</p>`;
            }
            
            if (listResult.ok && listResult.data?.data?.projects) {
                allProjects = listResult.data.data.projects || [];
                
                selector.innerHTML = '';
                allProjects.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.name;
                    opt.textContent = p.name + (p.is_active ? ` (${proj.active || 'active'})` : '');
                    opt.selected = p.is_active;
                    selector.appendChild(opt);
                });
                
                selector.disabled = false;
                switchBtn.disabled = false;
                
                updateDeleteSelector();
            }
            
        } catch (error) {
            console.error('Failed to load project manager:', error);
            infoContainer.innerHTML = `<p class="admin-error">${proj.error || 'Failed to load project info'}</p>`;
        }
    }

    // ========================================================================
    // Storage Overview
    // ========================================================================

    async function loadStorageOverview() {
        try {
            const result = await QuickSiteAdmin.apiRequest('getSizeInfo');
            
            if (result.ok && result.data?.data?.summary) {
                const summary = result.data.data.summary;
                const byCategory = summary.by_category || {};
                
                document.getElementById('storage-total').textContent = summary.total?.size_formatted || '--';
                
                const totalBytes = summary.total?.size || 1;
                const categories = ['projects', 'backups', 'builds', 'exports', 'admin', 'system'];
                
                const systemBytes = (byCategory.management?.size || 0) + (byCategory.core?.size || 0);
                const sizes = {
                    projects: byCategory.projects?.size || 0,
                    backups: byCategory.backups?.size || 0,
                    builds: byCategory.builds?.size || 0,
                    exports: byCategory.exports?.size || 0,
                    admin: byCategory.admin?.size || 0,
                    system: systemBytes
                };
                
                categories.forEach(cat => {
                    const bytes = sizes[cat] || 0;
                    const pct = (bytes / totalBytes) * 100;
                    
                    const segment = document.getElementById(`storage-seg-${cat}`);
                    const value = document.getElementById(`storage-val-${cat}`);
                    
                    if (segment) {
                        segment.style.width = pct > 0 ? Math.max(pct, 1) + '%' : '0%';
                    }
                    if (value) {
                        value.textContent = formatSize(bytes);
                    }
                });
            } else {
                console.error('getSizeInfo failed:', result.data?.message || 'Unknown error');
            }
        } catch (error) {
            console.error('Failed to load storage info:', error);
        }
    }

    // ========================================================================
    // Manage Space
    // ========================================================================

    function setupManageSpace() {
        const toggle = document.getElementById('manage-space-toggle');
        const body = document.getElementById('manage-space-body');
        if (!toggle || !body) return;

        toggle.addEventListener('click', () => {
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            toggle.classList.toggle('manage-space__toggle--open', !isOpen);
            if (!isOpen && !manageSpaceLoaded) {
                manageSpaceLoaded = true;
                loadManageSpaceBuilds();
                loadManageSpaceExports();
                loadManageSpaceBackups();
            }
        });
    }

    async function loadManageSpaceBuilds() {
        const list = document.getElementById('manage-builds-list');
        const count = document.getElementById('manage-builds-count');
        if (!list) return;

        list.innerHTML = '<div class="manage-space__loading">' + t('common.loading', 'Loading...') + '</div>';

        try {
            const result = await QuickSiteAdmin.apiRequest('listBuilds');
            const builds = result.data?.data?.builds || [];
            count.textContent = builds.length;

            if (builds.length === 0) {
                list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                return;
            }

            list.innerHTML = builds.map(b => {
                const sizeMb = (b.folder_size_mb || 0) + (b.zip_size_mb || 0);
                const sizeStr = sizeMb < 1 ? (sizeMb * 1024).toFixed(0) + ' KB' : sizeMb.toFixed(2) + ' MB';
                const date = b.created ? new Date(b.created).toLocaleDateString() : '--';
                return `<div class="manage-space__item" data-build="${QuickSiteAdmin.escapeHtml(b.name)}">
                    <div class="manage-space__item-info">
                        <span class="manage-space__item-name">${QuickSiteAdmin.escapeHtml(b.name)}</span>
                        <span class="manage-space__item-meta">${date} · ${sizeStr}</span>
                    </div>
                    <button type="button" class="manage-space__delete-btn" title="${t('common.delete', 'Delete')}">
                        ${QuickSiteUtils.iconTrash()}
                    </button>
                </div>`;
            }).join('');

            list.querySelectorAll('.manage-space__delete-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const item = this.closest('.manage-space__item');
                    const name = item.dataset.build;
                    if (!confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + name)) return;
                    this.disabled = true;
                    try {
                        const res = await QuickSiteAdmin.apiRequest('deleteBuild', 'POST', { name });
                        if (res.ok) {
                            item.remove();
                            const remaining = list.querySelectorAll('.manage-space__item').length;
                            count.textContent = remaining;
                            if (remaining === 0) {
                                list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                            }
                            loadStorageOverview();
                            QuickSiteAdmin.showToast(t('dashboard.storage.deleted', 'Deleted') + ': ' + name, 'success');
                        } else {
                            QuickSiteAdmin.showToast(res.data?.message || 'Delete failed', 'error');
                            this.disabled = false;
                        }
                    } catch (e) {
                        QuickSiteAdmin.showToast('Delete failed', 'error');
                        this.disabled = false;
                    }
                });
            });
        } catch (error) {
            list.innerHTML = '<div class="manage-space__empty">' + t('common.error', 'Error loading data') + '</div>';
        }
    }

    async function loadManageSpaceExports() {
        const list = document.getElementById('manage-exports-list');
        const count = document.getElementById('manage-exports-count');
        if (!list) return;

        list.innerHTML = '<div class="manage-space__loading">' + t('common.loading', 'Loading...') + '</div>';

        try {
            const result = await QuickSiteAdmin.apiRequest('getSizeInfo');
            const exports = result.data?.data?.secure_folders?.exports || {};
            const totalBytes = exports.size || 0;
            const fileCount = exports.files || 0;
            count.textContent = fileCount;

            if (fileCount === 0) {
                list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                return;
            }

            list.innerHTML = `<div class="manage-space__item manage-space__item--summary">
                <div class="manage-space__item-info">
                    <span class="manage-space__item-name">${fileCount} ${t('dashboard.storage.exportFiles', 'export file(s)')}</span>
                    <span class="manage-space__item-meta">${formatSize(totalBytes)}</span>
                </div>
                <button type="button" class="manage-space__clear-btn" id="clear-exports-btn">
                    ${t('dashboard.storage.clearAll', 'Clear All')}
                </button>
            </div>`;

            document.getElementById('clear-exports-btn').addEventListener('click', async function() {
                if (!confirm(t('dashboard.storage.confirmClearExports', 'Clear all export files?'))) return;
                this.disabled = true;
                try {
                    const res = await QuickSiteAdmin.apiRequest('clearExports', 'POST', { confirm: true });
                    if (res.ok) {
                        count.textContent = '0';
                        list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                        loadStorageOverview();
                        QuickSiteAdmin.showToast(t('dashboard.storage.exportsCleared', 'Exports cleared'), 'success');
                    } else {
                        QuickSiteAdmin.showToast(res.data?.message || 'Clear failed', 'error');
                        this.disabled = false;
                    }
                } catch (e) {
                    QuickSiteAdmin.showToast('Clear failed', 'error');
                    this.disabled = false;
                }
            });
        } catch (error) {
            list.innerHTML = '<div class="manage-space__empty">' + t('common.error', 'Error loading data') + '</div>';
        }
    }

    async function loadManageSpaceBackups() {
        const list = document.getElementById('manage-backups-list');
        const count = document.getElementById('manage-backups-count');
        const projectLabel = document.getElementById('manage-backups-project');
        const tip = document.getElementById('manage-backups-tip');
        if (!list) return;

        if (projectLabel && currentProject) {
            projectLabel.textContent = '(' + currentProject + ')';
        }
        if (tip) {
            tip.textContent = t('dashboard.storage.backupsTip', 'Switch project to manage other projects\u2019 backups.');
        }

        list.innerHTML = '<div class="manage-space__loading">' + t('common.loading', 'Loading...') + '</div>';

        try {
            const result = await QuickSiteAdmin.apiRequest('listBackups');
            const backups = result.data?.data?.backups || [];
            count.textContent = backups.length;

            if (backups.length === 0) {
                list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                return;
            }

            list.innerHTML = backups.map(b => {
                const date = b.created_relative || b.created_formatted || '--';
                const size = b.size_formatted || '--';
                const typeLabel = b.type !== 'manual' ? ` <span class="manage-space__item-badge">${QuickSiteAdmin.escapeHtml(b.type)}</span>` : '';
                return `<div class="manage-space__item" data-backup="${QuickSiteAdmin.escapeHtml(b.name)}">
                    <div class="manage-space__item-info">
                        <span class="manage-space__item-name">${QuickSiteAdmin.escapeHtml(b.name)}${typeLabel}</span>
                        <span class="manage-space__item-meta">${date} · ${size}</span>
                    </div>
                    <button type="button" class="manage-space__delete-btn" title="${t('common.delete', 'Delete')}">
                        ${QuickSiteUtils.iconTrash()}
                    </button>
                </div>`;
            }).join('');

            list.querySelectorAll('.manage-space__delete-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const item = this.closest('.manage-space__item');
                    const name = item.dataset.backup;
                    if (!confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + name)) return;
                    this.disabled = true;
                    try {
                        const res = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: name });
                        if (res.ok) {
                            item.remove();
                            const remaining = list.querySelectorAll('.manage-space__item').length;
                            count.textContent = remaining;
                            if (remaining === 0) {
                                list.innerHTML = '<div class="manage-space__empty">' + t('dashboard.storage.noItems', 'No items') + '</div>';
                            }
                            loadStorageOverview();
                            QuickSiteAdmin.showToast(t('dashboard.storage.deleted', 'Deleted') + ': ' + name, 'success');
                        } else {
                            QuickSiteAdmin.showToast(res.data?.message || 'Delete failed', 'error');
                            this.disabled = false;
                        }
                    } catch (e) {
                        QuickSiteAdmin.showToast('Delete failed', 'error');
                        this.disabled = false;
                    }
                });
            });
        } catch (error) {
            list.innerHTML = '<div class="manage-space__empty">' + t('common.error', 'Error loading data') + '</div>';
        }
    }

    // ========================================================================
    // Delete Selector
    // ========================================================================

    function updateDeleteSelector() {
        const deleteSelector = document.getElementById('delete-project-selector');
        const proj = t('dashboard.projects', {});
        
        deleteSelector.innerHTML = `<option value="">${proj.selectProject || 'Select a project...'}</option>`;
        
        const deletable = allProjects.filter(p => !p.is_active);
        deletable.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = p.name;
            deleteSelector.appendChild(opt);
        });
        
        if (deletable.length === 0 && allProjects.length > 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = proj.cannotDeleteActive || 'Cannot delete active project';
            opt.disabled = true;
            deleteSelector.appendChild(opt);
        }
    }

    // ========================================================================
    // Project Manager Events
    // ========================================================================

    function setupProjectManagerEvents() {
        const proj = t('dashboard.projects', {});
        const common = t('common', {});
        
        // Switch project
        document.getElementById('btn-switch-project').addEventListener('click', async function() {
            const selector = document.getElementById('project-selector');
            const newProject = selector.value;
            
            if (!newProject || newProject === currentProject) return;
            
            this.disabled = true;
            this.innerHTML = QuickSiteUtils.htmlSpinner() + ' ' + (common.loading || 'Switching...');
            try {
                const result = await QuickSiteAdmin.apiRequest('switchProject', 'POST', { project: newProject });
                if (result.ok) {
                    QuickSiteAdmin.showToast((proj.switched || 'Switched to project') + ': ' + newProject, 'success');
                    window.location.href = window.location.pathname + '?t=' + Date.now();
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to switch project', 'error');
                    this.disabled = false;
                    this.textContent = proj.switch || 'Switch';
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to switch project', 'error');
                this.disabled = false;
                this.textContent = proj.switch || 'Switch';
            }
        });
        
        // Create project modal
        document.getElementById('btn-create-project').addEventListener('click', function() {
            document.getElementById('modal-create-project').style.display = 'flex';
            document.getElementById('create-project-name').focus();
        });
        
        // Clone project modal
        document.getElementById('btn-clone-project').addEventListener('click', function() {
            document.getElementById('clone-source-name').textContent = currentProject;
            document.getElementById('clone-project-name').value = currentProject + '-copy';
            document.getElementById('modal-clone-project').style.display = 'flex';
            document.getElementById('clone-project-name').focus();
            document.getElementById('clone-project-name').select();
        });
        
        // Confirm clone project
        document.getElementById('btn-confirm-clone').addEventListener('click', async function() {
            const nameInput = document.getElementById('clone-project-name');
            const activateCheckbox = document.getElementById('clone-project-activate');
            const name = nameInput.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '-');
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            this.disabled = true;
            this.innerHTML = QuickSiteUtils.htmlSpinner() + ' ' + (proj.cloning || 'Cloning...');
            try {
                const result = await QuickSiteAdmin.apiRequest('cloneProject', 'POST', {
                    source: currentProject,
                    name: name,
                    switch_to: activateCheckbox.checked
                });
                
                if (result.ok) {
                    const filesCopied = result.data?.data?.files_copied || '';
                    const msg = (proj.cloned || 'Project cloned') + ': ' + name + (filesCopied ? ' (' + filesCopied + ' files)' : '');
                    QuickSiteAdmin.showToast(msg, 'success');
                    closeAllModals();
                    if (activateCheckbox.checked) {
                        window.location.href = window.location.pathname + '?t=' + Date.now();
                    } else {
                        loadProjectManager();
                        this.disabled = false;
                        this.textContent = proj.cloneBtn || 'Clone Project';
                    }
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to clone project', 'error');
                    this.disabled = false;
                    this.textContent = proj.cloneBtn || 'Clone Project';
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to clone project', 'error');
                this.disabled = false;
                this.textContent = proj.cloneBtn || 'Clone Project';
            }
        });
        
        // Confirm create project
        document.getElementById('btn-confirm-create').addEventListener('click', async function() {
            const nameInput = document.getElementById('create-project-name');
            const activateCheckbox = document.getElementById('create-project-activate');
            const name = nameInput.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '-');
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            this.disabled = true;
            this.innerHTML = QuickSiteUtils.htmlSpinner() + ' ' + (common.loading || 'Creating...');
            try {
                const result = await QuickSiteAdmin.apiRequest('createProject', 'POST', {
                    name: name,
                    switch_to: activateCheckbox.checked
                });
                
                if (result.ok) {
                    QuickSiteAdmin.showToast((proj.created || 'Project created') + ': ' + name, 'success');
                    closeAllModals();
                    if (activateCheckbox.checked) {
                        window.location.href = window.location.pathname + '?t=' + Date.now();
                    } else {
                        loadProjectManager();
                        this.disabled = false;
                        this.textContent = proj.createModal?.submit || 'Create Project';
                    }
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to create project', 'error');
                    this.disabled = false;
                    this.textContent = proj.createModal?.submit || 'Create Project';
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to create project', 'error');
                this.disabled = false;
                this.textContent = proj.createModal?.submit || 'Create Project';
            }
        });
        
        // Export project
        document.getElementById('btn-export-project').addEventListener('click', async function() {
            this.disabled = true;
            const originalText = this.textContent;
            this.innerHTML = '<span class="spinner"></span> ' + (proj.exporting || 'Exporting...');
            
            try {
                const response = await fetch(window.QUICKSITE_CONFIG.apiBase + '/exportProject?name=' + encodeURIComponent(currentProject), {
                    method: 'GET',
                    headers: {
                        'Authorization': 'Bearer ' + QuickSiteAdmin.getToken()
                    }
                });
                
                if (response.ok) {
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = currentProject + '_export.zip';
                    if (contentDisposition) {
                        const match = contentDisposition.match(/filename="?([^";\n]+)"?/);
                        if (match) filename = match[1];
                    }
                    
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    a.remove();
                    
                    QuickSiteAdmin.showToast(proj.exported || 'Project exported', 'success');
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    QuickSiteAdmin.showToast(errorData.message || 'Failed to export project', 'error');
                }
            } catch (error) {
                console.error('Export error:', error);
                QuickSiteAdmin.showToast('Failed to export project', 'error');
            }
            
            this.disabled = false;
            this.textContent = originalText;
        });
        
        // Import project
        document.getElementById('btn-import-project').addEventListener('click', function() {
            document.getElementById('import-file-input').click();
        });
        
        document.getElementById('import-file-input').addEventListener('change', async function() {
            if (!this.files || !this.files[0]) return;
            
            const file = this.files[0];
            const formData = new FormData();
            formData.append('file', file);
            formData.append('activate', 'false');
            
            try {
                QuickSiteAdmin.showToast(proj.importing || 'Importing project...', 'info');
                
                const response = await fetch(window.QUICKSITE_CONFIG.apiBase + '/importProject', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + QuickSiteAdmin.getToken()
                    },
                    body: formData
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    QuickSiteAdmin.showToast(result.message || proj.imported || 'Project imported successfully', 'success');
                    loadProjectManager();
                } else {
                    QuickSiteAdmin.showToast(result.message || 'Failed to import project', 'error');
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to import project', 'error');
            }
            
            this.value = '';
        });
        
        // Backup project
        document.getElementById('btn-backup-project').addEventListener('click', async function() {
            this.disabled = true;
            const originalText = this.textContent;
            this.innerHTML = '<span class="spinner"></span> ' + (proj.backing_up || 'Creating backup...');
            
            try {
                const result = await QuickSiteAdmin.apiRequest('backupProject', 'GET');
                
                if (result.ok && result.data?.data?.backup) {
                    const data = result.data.data;
                    QuickSiteAdmin.showToast(
                        (proj.backup_created || 'Backup created') + ': ' + data.backup.name + ' (' + data.backup.size_formatted + ')',
                        'success'
                    );
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to create backup', 'error');
                }
            } catch (error) {
                console.error('Backup error:', error);
                QuickSiteAdmin.showToast('Failed to create backup', 'error');
            }
            
            this.disabled = false;
            this.innerHTML = originalText;
        });
        
        // Restore backup modal
        document.getElementById('btn-restore-backup').addEventListener('click', async function() {
            document.getElementById('modal-restore-backup').style.display = 'flex';
            await loadBackupList();
        });
        
        // Delete project modal
        document.getElementById('btn-delete-project').addEventListener('click', function() {
            updateDeleteSelector();
            document.getElementById('modal-delete-project').style.display = 'flex';
        });
        
        // Enable delete button only when project selected
        document.getElementById('delete-project-selector').addEventListener('change', function() {
            document.getElementById('btn-confirm-delete').disabled = !this.value;
        });
        
        // Confirm delete project
        document.getElementById('btn-confirm-delete').addEventListener('click', async function() {
            const selector = document.getElementById('delete-project-selector');
            const projectToDelete = selector.value;
            
            if (!projectToDelete) return;
            
            this.disabled = true;
            try {
                const result = await QuickSiteAdmin.apiRequest('deleteProject', 'POST', {
                    name: projectToDelete,
                    confirm: true
                });
                
                if (result.ok) {
                    QuickSiteAdmin.showToast(proj.deleted || 'Project deleted', 'success');
                    closeAllModals();
                    loadProjectManager();
                    loadStorageOverview();
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to delete project', 'error');
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to delete project', 'error');
            }
            this.disabled = false;
        });
        
        // Modal close handlers
        document.querySelectorAll('[data-close-modal]').forEach(btn => {
            btn.addEventListener('click', closeAllModals);
        });
        
        document.querySelectorAll('.admin-modal__backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', closeAllModals);
        });
    }

    // ========================================================================
    // Restore Confirmation Modal
    // ========================================================================

    function openRestoreConfirmModal(backupName) {
        const proj = t('dashboard.projects', {});
        
        pendingRestoreBackup = backupName;
        
        document.getElementById('restore-backup-name').textContent = backupName;
        document.getElementById('restore-create-backup').checked = false;
        
        updateRestoreWarning();
        
        document.getElementById('modal-restore-confirm').style.display = 'flex';
    }

    function updateRestoreWarning() {
        const proj = t('dashboard.projects', {});
        const checkbox = document.getElementById('restore-create-backup');
        const warningText = document.getElementById('restore-warning-text');
        
        if (checkbox.checked) {
            warningText.textContent = proj.restoreWarningWithBackup || 'A backup of your current project will be created before restoring.';
        } else {
            warningText.textContent = proj.restoreWarningNoBackup || 'Your current project state will be lost! Make sure you have a backup if needed.';
        }
    }

    // ========================================================================
    // Backup List
    // ========================================================================

    async function loadBackupList() {
        const container = document.getElementById('backup-list-container');
        const proj = t('dashboard.projects', {});
        const common = t('common', {});
        
        container.innerHTML = QuickSiteUtils.htmlLoading(common.loading || 'Loading...');
        
        try {
            const result = await QuickSiteAdmin.apiRequest('listBackups', 'GET');
            
            if (!result.ok) {
                container.innerHTML = `<p class="admin-error">${result.data?.message || 'Failed to load backups'}</p>`;
                return;
            }
            
            const data = result.data.data;
            const backups = data.backups || [];
            
            if (backups.length === 0) {
                container.innerHTML = `
                    <div class="backup-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="opacity: 0.4; margin-bottom: 1rem;">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <p style="color: var(--admin-text-muted); margin: 0;">${proj.no_backups || 'No backups yet'}</p>
                        <p style="color: var(--admin-text-muted); font-size: 0.875rem; margin: 0.5rem 0 0 0;">${proj.backup_hint || 'Click "Backup" to create your first backup'}</p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="backup-summary">
                    <span>${data.count} ${proj.backups_count || 'backup(s)'}</span>
                    <span class="backup-summary__divider">•</span>
                    <span>${data.total_size_formatted} ${proj.total_size || 'total'}</span>
                </div>
                <div class="backup-list">
            `;
            
            backups.forEach(backup => {
                const typeLabel = backup.type === 'pre-restore' 
                    ? `<span class="badge badge--warning">${proj.pre_restore || 'Pre-restore'}</span>` 
                    : backup.type === 'auto' 
                        ? `<span class="badge badge--info">${proj.auto_backup || 'Auto'}</span>`
                        : '';
                
                html += `
                    <div class="backup-item" data-backup="${QuickSiteAdmin.escapeHtml(backup.name)}">
                        <div class="backup-item__info">
                            <div class="backup-item__name">
                                ${QuickSiteUtils.iconSave(16)}
                                <span>${QuickSiteAdmin.escapeHtml(backup.name)}</span>
                                ${typeLabel}
                            </div>
                            <div class="backup-item__meta">
                                <span>${backup.size_formatted}</span>
                                <span class="backup-item__divider">•</span>
                                <span>${backup.files} ${proj.files || 'files'}</span>
                                <span class="backup-item__divider">•</span>
                                <span>${backup.created_relative}</span>
                            </div>
                        </div>
                        <div class="backup-item__actions">
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--primary btn-restore-this" data-backup="${QuickSiteAdmin.escapeHtml(backup.name)}">
                                ${QuickSiteUtils.iconRefresh()}
                                ${proj.restore_btn || 'Restore'}
                            </button>
                            <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost admin-btn--danger btn-delete-backup" data-backup="${QuickSiteAdmin.escapeHtml(backup.name)}">
                                ${QuickSiteUtils.iconTrash()}
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
            
            // Add restore handlers
            container.querySelectorAll('.btn-restore-this').forEach(btn => {
                btn.addEventListener('click', function() {
                    const backupName = this.dataset.backup;
                    openRestoreConfirmModal(backupName);
                });
            });
            
            // Add delete handlers
            container.querySelectorAll('.btn-delete-backup').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const backupName = this.dataset.backup;
                    if (!confirm((proj.confirm_delete_backup || 'Delete backup') + ': ' + backupName + '?')) {
                        return;
                    }
                    
                    this.disabled = true;
                    
                    try {
                        const result = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: backupName });
                        
                        if (result.ok) {
                            QuickSiteAdmin.showToast(proj.backup_deleted || 'Backup deleted', 'success');
                            await loadBackupList();
                        } else {
                            QuickSiteAdmin.showToast(result.data?.message || 'Failed to delete backup', 'error');
                            this.disabled = false;
                        }
                    } catch (error) {
                        QuickSiteAdmin.showToast('Failed to delete backup', 'error');
                        this.disabled = false;
                    }
                });
            });
            
        } catch (error) {
            console.error('Failed to load backups:', error);
            container.innerHTML = `<p class="admin-error">Failed to load backups</p>`;
        }
    }

    // ========================================================================
    // Structure Panel
    // ========================================================================

    /**
     * Toggle the structure panel open/closed
     */
    function toggleStructurePanel() {
        const body = document.getElementById('structure-panel-body');
        const toggle = document.getElementById('structure-panel-toggle');
        
        if (!body || !toggle) return;
        
        const isOpen = body.style.display !== 'none';
        
        if (isOpen) {
            body.style.display = 'none';
            toggle.classList.remove('admin-card__toggle--open');
        } else {
            body.style.display = 'block';
            toggle.classList.add('admin-card__toggle--open');
        }
    }

    /**
     * Initialize structure viewer selectors in dashboard
     */
    function initDashboardStructureViewer() {
        const typeSelect = document.getElementById('dash-structure-type');
        const nameSelect = document.getElementById('dash-structure-name');
        const loadBtn = document.getElementById('dash-load-structure');
        
        if (!typeSelect || !nameSelect || !loadBtn) return;
        
        const trans = window.QUICKSITE_CONFIG?.translations?.structure?.select || {};
        
        typeSelect.addEventListener('change', async function() {
            const type = this.value;
            
            if (!type) {
                nameSelect.innerHTML = `<option value="">${trans.typeFirst || 'Select type first...'}</option>`;
                nameSelect.disabled = true;
                loadBtn.disabled = true;
                return;
            }
            
            if (type === 'menu' || type === 'footer') {
                nameSelect.innerHTML = `<option value="">${(trans.notRequired || 'Not required for :type').replace(':type', type)}</option>`;
                nameSelect.disabled = true;
                loadBtn.disabled = false;
            } else {
                nameSelect.disabled = true;
                nameSelect.innerHTML = '<option value="">Loading...</option>';
                
                try {
                    const endpoint = type === 'page' ? 'pages' : 'components';
                    const options = await QuickSiteAdmin.fetchHelperData(endpoint);
                    
                    nameSelect.innerHTML = `<option value="">${(trans.selectType || 'Select :type...').replace(':type', type)}</option>`;
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
        
        loadBtn.addEventListener('click', loadDashboardStructure);
    }

    /**
     * Load structure in dashboard
     */
    async function loadDashboardStructure() {
        const typeSelect = document.getElementById('dash-structure-type');
        const nameSelect = document.getElementById('dash-structure-name');
        const treeContainer = document.getElementById('dash-structure-tree');
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type || !treeContainer) return;
        
        const trans = window.QUICKSITE_CONFIG?.translations?.structure?.tree || {};
        
        treeContainer.innerHTML = QuickSiteUtils.htmlLoading(trans.loading || 'Loading structure...');
        
        try {
            let urlParams = [type];
            if (name && (type === 'page' || type === 'component')) {
                urlParams.push(name);
            }
            urlParams.push('showIds');
            
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, urlParams);
            
            if (result.ok && result.data?.data?.structure) {
                dashStructureLoaded = {
                    type,
                    name,
                    structure: result.data.data.structure
                };
                renderDashboardStructureTree(result.data.data.structure, treeContainer);
            } else {
                treeContainer.innerHTML = `
                    <div class="admin-alert admin-alert--error">
                        ${result.data?.message || trans.loadFailed || 'Failed to load structure'}
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
     * Render structure tree in dashboard
     */
    function renderDashboardStructureTree(structure, container) {
        if (!structure || (Array.isArray(structure) && structure.length === 0)) {
            const trans = window.QUICKSITE_CONFIG?.translations?.structure?.tree || {};
            container.innerHTML = `<div class="admin-empty"><p>${trans.isEmpty || 'Structure is empty'}</p></div>`;
            return;
        }
        
        const tree = Array.isArray(structure) ? structure : [structure];
        container.innerHTML = renderDashboardNodes(tree, 0, '');
    }

    /**
     * Render tree nodes recursively for dashboard
     */
    function renderDashboardNodes(nodes, depth, parentPath) {
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
                label = `<span class="admin-tree__component">&lt;${QuickSiteAdmin.escapeHtml(node.component)}/&gt;</span>`;
            } else if (node.tag) {
                label = `<span class="admin-tree__element">&lt;${element}</span>`;
                
                if (attributes.id) {
                    label += `<span class="admin-tree__attr-id">#${QuickSiteAdmin.escapeHtml(attributes.id)}</span>`;
                }
                if (attributes.class) {
                    const classes = Array.isArray(attributes.class) ? attributes.class.join(' ') : attributes.class;
                    label += `<span class="admin-tree__attr-class">.${QuickSiteAdmin.escapeHtml(classes.replace(/\s+/g, '.'))}</span>`;
                }
                
                label += `<span class="admin-tree__element">&gt;</span>`;
            } else if (node.textKey) {
                label = `<span class="admin-tree__trans">{{${QuickSiteAdmin.escapeHtml(node.textKey)}}}</span>`;
            } else if (node.text) {
                const preview = node.text.length > 30 ? node.text.substring(0, 30) + '...' : node.text;
                label = `<span class="admin-tree__text">"${QuickSiteAdmin.escapeHtml(preview)}"</span>`;
            } else {
                label = `<span class="admin-tree__element">&lt;unknown&gt;</span>`;
            }
            
            label += `<span class="admin-tree__node-id">[${nodeId}]</span>`;
            
            html += `
                <li class="admin-tree__item ${hasChildren ? 'admin-tree__item--has-children' : ''}" data-node-id="${nodeId}">
                    <div class="admin-tree__row">
                        ${hasChildren ? '<span class="admin-tree__toggle" onclick="event.stopPropagation(); this.closest(\'.admin-tree__item\').classList.toggle(\'admin-tree__item--expanded\'); this.textContent = this.closest(\'.admin-tree__item\').classList.contains(\'admin-tree__item--expanded\') ? \'▼\' : \'▶\';">▶</span>' : '<span class="admin-tree__spacer"></span>'}
                        ${label}
                    </div>
            `;
            
            if (hasChildren) {
                html += renderDashboardNodes(node.children, depth + 1, nodePath);
            }
            
            html += '</li>';
        });
        
        html += '</ul>';
        return html;
    }

    /**
     * Setup structure panel event listeners
     */
    function setupStructurePanel() {
        const header = document.getElementById('structure-panel-header');
        if (header) {
            header.addEventListener('click', toggleStructurePanel);
        }
        
        // Initialize the structure viewer selectors
        initDashboardStructureViewer();
    }

    // ========================================================================
    // Restore Confirm Modal
    // ========================================================================

    function setupRestoreConfirmModal() {
        const restoreCheckbox = document.getElementById('restore-create-backup');
        if (restoreCheckbox) {
            restoreCheckbox.addEventListener('change', updateRestoreWarning);
        }
        
        const confirmRestoreBtn = document.getElementById('btn-confirm-restore');
        if (!confirmRestoreBtn) return;

        const restoreSvg = QuickSiteUtils.iconRefresh(16);

        confirmRestoreBtn.addEventListener('click', async function() {
            if (!pendingRestoreBackup) return;
            
            const proj = t('dashboard.projects', {});
            const createBackup = document.getElementById('restore-create-backup').checked;
            
            this.disabled = true;
            this.innerHTML = QuickSiteUtils.htmlSpinner(16) + ' ' + (proj.restoring || 'Restoring...');
            
            try {
                const result = await QuickSiteAdmin.apiRequest('restoreBackup', 'POST', { 
                    backup: pendingRestoreBackup,
                    create_backup: createBackup
                });
                
                if (result.ok) {
                    QuickSiteAdmin.showToast(proj.restore_success || 'Backup restored successfully', 'success');
                    closeAllModals();
                    window.location.href = window.location.pathname + '?restored=' + Date.now();
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Failed to restore backup', 'error');
                    this.disabled = false;
                    this.innerHTML = `${restoreSvg} ${proj.restoreBtn || 'Restore'}`;
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to restore backup', 'error');
                this.disabled = false;
                this.innerHTML = `${restoreSvg} ${proj.restoreBtn || 'Restore'}`;
            }
        });
    }

    // ========================================================================
    // Initialization
    // ========================================================================

    document.addEventListener('DOMContentLoaded', async function() {
        setupRestoreConfirmModal();

        // Wait for admin.js to finish loading permissions before checking them
        await (window.QuickSiteAdmin?.permissionsReady || Promise.resolve());

        const hp = (cmd) => window.QuickSiteAdmin?.hasPermission(cmd) ?? true;

        // Load dashboard data, skipping sections the current user lacks permission for
        await Promise.all([
            loadDashboardStats(),
            hp('getSiteMap')         ? loadSiteMap()         : Promise.resolve(),
            hp('getCommandHistory')  ? loadRecentCommands()  : Promise.resolve(),
            hp('listProjects')       ? loadProjectManager()  : Promise.resolve(),
            loadStorageOverview()
        ]);
        
        // Setup project manager event listeners
        setupProjectManagerEvents();
        
        // Setup manage space toggle
        setupManageSpace();
        
        // Setup structure panel (collapsible)
        setupStructurePanel();
    });

})();
