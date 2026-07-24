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
    let restoreTargetProject = null; // the project whose backups the restore modal is showing
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

    // The project the manager ACTS ON = the one chosen in the selector (falls back
    // to the edited project). Every project-manager command sends this as the URL
    // marker (opts.project); the server binds + re-authorizes it (C8 8.4).
    function getTargetProject() {
        const sel = document.getElementById('project-selector');
        return (sel && sel.value) ? sel.value : currentProject;
    }

    // Folder icon as an ELEMENT (QuickSiteUtils.icon* return HTML strings, which
    // can't be QSDom children without re-introducing innerHTML).
    function _folderIcon(size) {
        return QSDom.svgIcon('M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', size || 18);
    }

    async function loadProjectManager() {
        const infoContainer = document.getElementById('current-project-info');
        const selector = document.getElementById('project-selector');
        const switchBtn = document.getElementById('btn-switch-project');
        const proj = t('dashboard.projects', {});

        // The dashboard reflects the project you are EDITING (selected_project).
        // listProjects is membership-filtered (my_role) and lists no privileged project.
        currentProject = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.currentProject) || null;

        let listResult;
        try {
            listResult = await QuickSiteAdmin.apiRequest('listProjects');
        } catch (error) {
            console.error('Failed to load project manager:', error);
            listResult = { ok: false };
        }
        allProjects = (listResult.ok && listResult.data?.data?.projects) ? listResult.data.data.projects : [];

        QSDom.clear(infoContainer);

        // 0-membership empty state — no doomed project-scoped calls (they'd 400/403).
        if (allProjects.length === 0) {
            infoContainer.appendChild(_renderProjectManagerEmpty(proj));
            selector.disabled = true;
            switchBtn.disabled = true;
            _setProjectActionsEnabled(false);
            return;
        }

        // Default the edited project when the client emitted none / a stale one.
        if (!currentProject || !allProjects.some(p => p.name === currentProject)) {
            currentProject = allProjects[0].name;
        }
        const edited = allProjects.find(p => p.name === currentProject) || allProjects[0];
        infoContainer.appendChild(_renderProjectChip(edited, proj));

        QSDom.clear(selector);
        allProjects.forEach(p => {
            const label = p.name + (p.my_role ? ' — ' + p.my_role : '');
            const opt = QSDom.el('option', { value: p.name, text: label });
            if (p.name === currentProject) opt.selected = true;
            selector.appendChild(opt);
        });
        selector.disabled = false;
        switchBtn.disabled = false;
        _setProjectActionsEnabled(true);

        updateDeleteSelector();
    }

    function _renderProjectChip(edited, proj) {
        return QSDom.el('div', { class: 'project-manager__info' }, [
            QSDom.el('div', { class: 'project-manager__name' }, [
                _folderIcon(20),
                QSDom.el('span', { text: edited.name }),
                QSDom.el('span', { class: 'badge badge--primary', text: proj.editing || 'Editing' }),
                edited.my_role ? QSDom.el('span', { class: 'badge', text: edited.my_role }) : null,
            ]),
            edited.site_name ? QSDom.el('div', { class: 'project-manager__meta', text: edited.site_name }) : null,
        ]);
    }

    function _renderProjectManagerEmpty(proj) {
        return QSDom.el('div', { class: 'project-manager__info' }, [
            QSDom.el('div', { class: 'project-manager__name' }, [
                QSDom.el('span', { text: proj.noProjects || 'You are not a member of any project yet.' }),
            ]),
            QSDom.el('div', { class: 'project-manager__meta', text: proj.noProjectsHint || 'Create a project to get started.' }),
        ]);
    }

    // Only the project-SCOPED actions depend on having a project. `btn-create-project`
    // and `btn-import-project` are GLOBAL (create / create-from-archive) — a member of
    // nothing must still be able to make or import their first project (C8 8.4).
    function _setProjectActionsEnabled(enabled) {
        ['btn-clone-project', 'btn-backup-project', 'btn-restore-backup',
         'btn-export-project', 'btn-delete-project'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.disabled = !enabled;
        });
    }

    // ========================================================================
    // Storage Overview
    // ========================================================================

    /**
     * One row in the owner-space project list. Returns ONE element.
     * Built with createElement + textContent: project names are user-authored.
     */
    function _renderOwnerSpaceRow(project, grandTotal) {
        const row = document.createElement('div');
        row.className = 'owner-space__project';

        const name = document.createElement('span');
        name.className = 'owner-space__project-name';
        name.textContent = project.name;
        row.appendChild(name);

        const bar = document.createElement('span');
        bar.className = 'owner-space__project-bar';
        const fill = document.createElement('span');
        fill.className = 'owner-space__project-fill';
        const pct = grandTotal > 0 ? (project.total / grandTotal) * 100 : 0;
        fill.style.width = pct > 0 ? Math.max(pct, 1) + '%' : '0%';
        bar.appendChild(fill);
        row.appendChild(bar);

        const size = document.createElement('span');
        size.className = 'owner-space__project-size';
        size.textContent = project.total_formatted || formatSize(project.total || 0);
        row.appendChild(size);

        // Backup/export counts only when there is something to say.
        const extras = [];
        if (project.backups?.count) {
            extras.push(project.backups.count + ' ' + t('dashboard.storage.backups', 'Backups').toLowerCase());
        }
        if (project.exports?.count) {
            extras.push(project.exports.count + ' ' + t('dashboard.storage.exports', 'Exports').toLowerCase());
        }
        if (extras.length) {
            const meta = document.createElement('span');
            meta.className = 'owner-space__project-meta';
            meta.textContent = extras.join(' · ');
            row.appendChild(meta);
        }

        return row;
    }

    /**
     * Owner-wide space usage — every project the caller OWNS, above the
     * per-project overview. Loaded independently so a slow disk walk never
     * blocks the rest of the dashboard.
     */
    async function loadOwnerSpaceUsage(refresh) {
        const section = document.getElementById('owner-space-section');
        if (!section) return;

        try {
            const result = await QuickSiteAdmin.apiRequest(
                'getMySpaceUsage', 'POST', refresh ? { refresh: true } : {}
            );
            const data = result.ok ? result.data?.data : null;

            // Owning nothing is a normal state, not an error: stay hidden.
            if (!data || !data.project_count) {
                section.style.display = 'none';
                return;
            }
            section.style.display = '';

            const total = data.total?.size || 0;
            document.getElementById('owner-space-total').textContent =
                data.total?.size_formatted || formatSize(total);

            const count = document.getElementById('owner-space-count');
            count.textContent = data.project_count === 1
                ? t('dashboard.storage.ownerOneProject', '1 project')
                : t('dashboard.storage.ownerProjects', '{n} projects').replace('{n}', data.project_count);

            const cats = data.by_category || {};
            ['content', 'backups', 'builds', 'exports'].forEach(cat => {
                const bytes = cats[cat]?.size || 0;
                const seg = document.getElementById('owner-space-seg-' + cat);
                const val = document.getElementById('owner-space-val-' + cat);
                if (seg) {
                    const pct = total > 0 ? (bytes / total) * 100 : 0;
                    seg.style.width = pct > 0 ? Math.max(pct, 1) + '%' : '0%';
                }
                if (val) val.textContent = cats[cat]?.size_formatted || formatSize(bytes);
            });

            const list = document.getElementById('owner-space-projects');
            list.replaceChildren(
                ...(data.projects || []).map(p => _renderOwnerSpaceRow(p, total))
            );

            const hint = document.getElementById('owner-space-hint');
            if (hint) {
                hint.textContent = data.cache?.from_cache
                    ? t('dashboard.storage.cachedHint', 'Cached — refresh to recalculate')
                    : '';
            }
        } catch (error) {
            console.error('Failed to load owner space usage:', error);
            section.style.display = 'none';
        }
    }

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
            // getSizeInfo returns { summary, public, secure } — the folder map is
            // secure.folders. The old `secure_folders` path never existed, so this
            // panel always reported 0 files.
            const exports = result.data?.data?.secure?.folders?.exports || {};
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

        QSDom.clear(list);
        list.appendChild(QSDom.el('div', { class: 'manage-space__loading', text: t('common.loading', 'Loading...') }));

        // The manage-space backups list is scoped to the EDITED project.
        const managed = currentProject;
        const emptyRow = () => QSDom.el('div', { class: 'manage-space__empty', text: t('dashboard.storage.noItems', 'No items') });

        try {
            const result = await QuickSiteAdmin.apiRequest('listBackups', 'GET', null, [], {}, { project: managed });
            const backups = result.data?.data?.backups || [];
            count.textContent = backups.length;

            QSDom.clear(list);
            if (backups.length === 0) { list.appendChild(emptyRow()); return; }

            backups.forEach(b => list.appendChild(_renderManageSpaceItem(b, managed, list, count, emptyRow)));
        } catch (error) {
            QSDom.clear(list);
            list.appendChild(QSDom.el('div', { class: 'manage-space__empty', text: t('common.error', 'Error loading data') }));
        }
    }

    function _renderManageSpaceItem(b, managed, list, count, emptyRow) {
        const date = b.created_relative || b.created_formatted || '--';
        const size = b.size_formatted || '--';
        const nameChildren = [b.name];
        if (b.type !== 'manual') nameChildren.push(QSDom.el('span', { class: 'manage-space__item-badge', text: b.type }));

        const delBtn = QSDom.el('button', { type: 'button', class: 'manage-space__delete-btn', title: t('common.delete', 'Delete') },
            [QSDom.svgIcon('M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 14)]);
        const item = QSDom.el('div', { class: 'manage-space__item', dataset: { backup: b.name } }, [
            QSDom.el('div', { class: 'manage-space__item-info' }, [
                QSDom.el('span', { class: 'manage-space__item-name' }, nameChildren),
                QSDom.el('span', { class: 'manage-space__item-meta', text: date + ' · ' + size }),
            ]),
            delBtn,
        ]);
        delBtn.addEventListener('click', async function () {
            if (!confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + b.name)) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: b.name }, [], {}, { project: managed });
                if (res.ok) {
                    item.remove();
                    const remaining = list.querySelectorAll('.manage-space__item').length;
                    count.textContent = remaining;
                    if (remaining === 0) list.appendChild(emptyRow());
                    loadStorageOverview();
                    QuickSiteAdmin.showToast(t('dashboard.storage.deleted', 'Deleted') + ': ' + b.name, 'success');
                } else {
                    QuickSiteAdmin.showToast(res.data?.message || 'Delete failed', 'error');
                    this.disabled = false;
                }
            } catch (e) {
                QuickSiteAdmin.showToast('Delete failed', 'error');
                this.disabled = false;
            }
        });
        return item;
    }

    // ========================================================================
    // Delete Selector
    // ========================================================================

    function updateDeleteSelector() {
        const deleteSelector = document.getElementById('delete-project-selector');
        const proj = t('dashboard.projects', {});

        QSDom.clear(deleteSelector);
        deleteSelector.appendChild(QSDom.el('option', { value: '', text: proj.selectProject || 'Select a project...' }));

        // Every project you own is deletable: no project is privileged any more, so there
        // is no "can't delete the active one" case to filter out.
        allProjects.forEach(p => {
            deleteSelector.appendChild(QSDom.el('option', { value: p.name, text: p.name }));
        });
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
                // C9 — the dashboard's project switch changes which project you EDIT
                // (setSelectedProject), the SAME as the header picker; it does NOT change the
                // served main (quicksite stays at the site root).
                const result = await QuickSiteAdmin.apiRequest('setSelectedProject', 'POST', { project: newProject });
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
            const target = getTargetProject();
            document.getElementById('clone-source-name').textContent = target;
            document.getElementById('clone-project-name').value = target + '-copy';
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
                // Source = the selected target project (bound to the URL marker
                // server-side); body carries only the new name + switch_to.
                const target = getTargetProject();
                const result = await QuickSiteAdmin.apiRequest('cloneProject', 'POST', {
                    name: name,
                    switch_to: activateCheckbox.checked
                }, [], {}, { project: target });
                
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
                // exportProject streams a binary ZIP (can't go through request()), but
                // it is project-scoped: it MUST carry the C7 '/p/<id>/' marker or the
                // dispatcher answers 400 project.required. The command binds the marker
                // as the target (C8 8.4 containment), so no ?name is needed.
                const target = getTargetProject();
                const response = await fetch(window.QUICKSITE_CONFIG.apiBase + '/p/' + encodeURIComponent(target) + '/exportProject', {
                    method: 'GET',
                    headers: {
                        'Authorization': 'Bearer ' + QuickSiteAdmin.getToken()
                    }
                });

                if (response.ok) {
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = target + '_export.zip';
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

            try {
                QuickSiteAdmin.showToast(proj.importing || 'Importing project...', 'info');

                // importProject is GLOBAL (create-from-archive) — no project marker.
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
                    // Full reload: a new project changes the header picker, the nav
                    // permission set and the storage totals — not just this card.
                    this.value = '';
                    window.location.href = window.location.pathname + '?t=' + Date.now();
                    return;
                }
                QuickSiteAdmin.showToast(result.message || 'Failed to import project', 'error');
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
                const result = await QuickSiteAdmin.apiRequest('backupProject', 'GET', null, [], {}, { project: getTargetProject() });

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
        
        // Restore backup modal — pin the target project the modal operates on.
        document.getElementById('btn-restore-backup').addEventListener('click', async function() {
            restoreTargetProject = getTargetProject();
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
                // Target the project being deleted via the URL marker (opts.project);
                // the server authorizes THAT project (owner-only) and refuses if the
                // body name disagrees (confused-deputy fix, C8).
                const result = await QuickSiteAdmin.apiRequest('deleteProject', 'POST', {
                    name: projectToDelete,
                    confirm: true
                }, [], {}, { project: projectToDelete });
                
                if (result.ok) {
                    QuickSiteAdmin.showToast(proj.deleted || 'Project deleted', 'success');
                    closeAllModals();
                    // If we just deleted the project we were EDITING, currentProject now
                    // points at a dead project — any further project-scoped call (e.g.
                    // getSizeInfo behind loadStorageOverview) would fire with that stale
                    // marker and 403. Reload so the server re-resolves the effective
                    // project instead of refreshing in place.
                    if (projectToDelete === currentProject) {
                        window.location.href = window.location.pathname + '?t=' + Date.now();
                        return;
                    }
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
        const target = restoreTargetProject || currentProject;

        container.innerHTML = QuickSiteUtils.htmlLoading(common.loading || 'Loading...');

        let result;
        try {
            result = await QuickSiteAdmin.apiRequest('listBackups', 'GET', null, [], {}, { project: target });
        } catch (error) {
            console.error('Failed to load backups:', error);
            QSDom.clear(container);
            container.appendChild(QSDom.el('p', { class: 'admin-error', text: 'Failed to load backups' }));
            return;
        }
        if (!result.ok) {
            QSDom.clear(container);
            container.appendChild(QSDom.el('p', { class: 'admin-error', text: result.data?.message || 'Failed to load backups' }));
            return;
        }

        const data = result.data.data;
        const backups = data.backups || [];

        QSDom.clear(container);
        if (backups.length === 0) {
            container.appendChild(QSDom.el('div', { class: 'backup-empty' }, [
                QSDom.svgIcon('M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z', 48),
                QSDom.el('p', { class: 'backup-empty__title', text: proj.no_backups || 'No backups yet' }),
                QSDom.el('p', { class: 'backup-empty__hint', text: proj.backup_hint || 'Click "Backup" to create your first backup' }),
            ]));
            return;
        }

        container.appendChild(QSDom.el('div', { class: 'backup-summary' }, [
            QSDom.el('span', { text: data.count + ' ' + (proj.backups_count || 'backup(s)') }),
            QSDom.el('span', { class: 'backup-summary__divider', text: '•' }),
            QSDom.el('span', { text: data.total_size_formatted + ' ' + (proj.total_size || 'total') }),
        ]));
        const listEl = QSDom.el('div', { class: 'backup-list' });
        backups.forEach(b => listEl.appendChild(_renderBackupItem(b, proj, target)));
        container.appendChild(listEl);
    }

    function _renderBackupItem(backup, proj, target) {
        let typeBadge = null;
        if (backup.type === 'pre-restore') typeBadge = QSDom.el('span', { class: 'badge badge--warning', text: proj.pre_restore || 'Pre-restore' });
        else if (backup.type === 'auto') typeBadge = QSDom.el('span', { class: 'badge badge--info', text: proj.auto_backup || 'Auto' });

        const restoreBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--sm admin-btn--primary btn-restore-this' }, [
            QSDom.svgIcon('M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15', 14),
            ' ' + (proj.restore_btn || 'Restore'),
        ]);
        const delBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--sm admin-btn--ghost admin-btn--danger btn-delete-backup' }, [
            QSDom.svgIcon('M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 14),
        ]);

        restoreBtn.addEventListener('click', () => openRestoreConfirmModal(backup.name));
        delBtn.addEventListener('click', async function () {
            if (!confirm((proj.confirm_delete_backup || 'Delete backup') + ': ' + backup.name + '?')) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: backup.name }, [], {}, { project: target });
                if (res.ok) {
                    QuickSiteAdmin.showToast(proj.backup_deleted || 'Backup deleted', 'success');
                    await loadBackupList();
                } else {
                    QuickSiteAdmin.showToast(res.data?.message || 'Failed to delete backup', 'error');
                    this.disabled = false;
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to delete backup', 'error');
                this.disabled = false;
            }
        });

        return QSDom.el('div', { class: 'backup-item', dataset: { backup: backup.name } }, [
            QSDom.el('div', { class: 'backup-item__info' }, [
                QSDom.el('div', { class: 'backup-item__name' }, [
                    QSDom.svgIcon('M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z', 16),
                    QSDom.el('span', { text: backup.name }),
                    typeBadge,
                ]),
                QSDom.el('div', { class: 'backup-item__meta' }, [
                    QSDom.el('span', { text: backup.size_formatted }),
                    QSDom.el('span', { class: 'backup-item__divider', text: '•' }),
                    QSDom.el('span', { text: backup.files + ' ' + (proj.files || 'files') }),
                    QSDom.el('span', { class: 'backup-item__divider', text: '•' }),
                    QSDom.el('span', { text: backup.created_relative }),
                ]),
            ]),
            QSDom.el('div', { class: 'backup-item__actions' }, [restoreBtn, delBtn]),
        ]);
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
                }, [], {}, { project: restoreTargetProject || currentProject });

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
            loadStorageOverview(),
            // Owner-wide usage walks every owned project, so it loads alongside
            // rather than gating anything; it hides itself when you own nothing.
            hp('getMySpaceUsage')    ? loadOwnerSpaceUsage(false) : Promise.resolve()
        ]);

        // Refresh control — the escape hatch for the measurement cache TTL.
        const ownerRefresh = document.getElementById('owner-space-refresh');
        if (ownerRefresh) {
            ownerRefresh.addEventListener('click', async function () {
                this.disabled = true;
                try {
                    await loadOwnerSpaceUsage(true);
                } finally {
                    this.disabled = false;
                }
            });
        }
        
        // Setup project manager event listeners
        setupProjectManagerEvents();
        
        // Setup manage space toggle
        setupManageSpace();
        
        // Setup structure panel (collapsible)
        setupStructurePanel();
    });

})();
