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
     * Can this page actually run `command` right now?
     *
     * Permission is only half the question. A PROJECT-SCOPED command with no
     * project bound never reaches the server at all — api.js's buildCommandPath
     * returns null and the call is refused client-side — so the block that
     * consumes the result can only ever render an error. At zero membership that
     * is every project-scoped call on the page.
     *
     * beta.10 C13 13.4(b) swept the admin PAGES and the /admin/api arms for "no
     * arm assumes a bound project" and passed; it did not sweep a page's own
     * client-side calls, which is why the dashboard kept firing getSizeInfo and
     * logging "getSizeInfo failed: Unknown error" for an account that is a member
     * of nothing. Gate on BOTH, in one place, so a future call inherits it.
     */
    function canRun(command) {
        const admin = window.QuickSiteAdmin;
        const api = window.QuickSiteAPI;
        if (!(admin?.hasPermission(command) ?? true)) return false;
        // Unknown scope => treat as project-scoped, mirroring api.js and the
        // server's own 'scope' ?? 'project' default (fail closed).
        if (api && !api.isProjectScoped(command)) return true;
        return !!(api ? api.getCurrentProject() : (window.QUICKSITE_CONFIG?.currentProject));
    }

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
    // The builds page needed the same formatting, so the implementation moved to
    // QuickSiteUtils rather than being copied. Same output, one definition.
    function formatSize(bytes) {
        return window.QuickSiteUtils.formatSize(bytes);
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
     * One route row — a link carrying its icon, name, path and the
     * external-link marker.
     * @returns {HTMLElement}
     */
    function _renderRouteLink(url, name, routePath, cls, depth, iconPath, iconCls) {
        const a = QSDom.el('a', {
            href: url,
            target: '_blank',
            class: cls,
            title: url,
            style: '--depth: ' + depth
        }, [
            QSDom.iconEl(iconPath, 14, iconCls),
            QSDom.el('span', { class: 'sitemap__route-name', text: name }),
            QSDom.el('span', { class: 'sitemap__route-path', text: routePath }),
            QSDom.iconEl(QuickSiteUtils.ICON_PATHS.externalLink, 12, 'sitemap__route-external')
        ]);
        return a;
    }

    /**
     * Render the route tree.
     *
     * Returns a DocumentFragment rather than one element because a level of
     * the tree is a SIBLING LIST — the markup this replaces concatenated
     * siblings into a string with no wrapper, and adding one would change the
     * CSS the sitemap depends on.
     *
     * @returns {DocumentFragment}
     */
    function renderRouteTree(tree, lang, depth = 0) {
        const frag = document.createDocumentFragment();
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

            if (!route) return;

            const url = route.urls[lang] || route.urls['default'];
            const routePath = route.path;

            if (hasChildren) {
                const isExpanded = depth === 0;
                const toggle = QSDom.el('button', {
                    class: 'sitemap__tree-toggle',
                    type: 'button',
                    'aria-label': 'Toggle'
                }, [QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronRight, 12)]);

                const header = QSDom.el('div', { class: 'sitemap__tree-header' }, [
                    toggle,
                    _renderRouteLink(url, name, routePath,
                        'sitemap__route sitemap__route--parent', depth,
                        QuickSiteUtils.ICON_PATHS.folder, 'sitemap__route-icon')
                ]);

                const kids = QSDom.el('div', { class: 'sitemap__tree-children' }, [
                    renderRouteTree(node, lang, depth + 1)
                ]);

                frag.appendChild(QSDom.el('div', {
                    class: 'sitemap__tree-node' + (isExpanded ? ' sitemap__tree-node--open' : ''),
                    style: '--depth: ' + depth
                }, [header, kids]));
            } else {
                frag.appendChild(_renderRouteLink(url, name, routePath,
                    'sitemap__route sitemap__route--leaf', depth,
                    isHome ? QuickSiteUtils.ICON_PATHS.home : QuickSiteUtils.ICON_PATHS.file,
                    'sitemap__route-icon'));
            }
        });

        return frag;
    }

    /**
     * The counts line above the sitemap: URLs, routes, and either the language
     * count or the "single language" badge.
     * @returns {HTMLElement}
     */
    function _renderSitemapSummary(data, routes, languages, multilingual, sitemap) {
        const dot = () => QSDom.el('span', { class: 'sitemap__divider', text: '\u2022' });
        const children = [
            QSDom.el('span', {
                class: 'sitemap__total',
                text: data.totalUrls + ' ' + (sitemap.urls || 'URLs')
            }),
            dot(),
            QSDom.el('span', { text: routes.length + ' ' + (sitemap.routes || 'routes') }),
            dot(),
            multilingual
                ? QSDom.el('span', {
                    text: languages.length + ' ' + (sitemap.languages || 'languages')
                })
                : QSDom.el('span', {
                    class: 'badge badge--ghost',
                    text: sitemap.monolingual || 'Single language'
                })
        ];
        return QSDom.el('div', { class: 'sitemap__summary' }, children);
    }

    /**
     * One collapsible language block of the sitemap: its header (flag, name,
     * default badge, page count, coverage) and its route tree.
     * @returns {HTMLElement}
     */
    function _renderSitemapLanguage(lang, isDefault, languageNames, coverage, routeTree, routes, sitemap) {
        const langName = languageNames[lang] || lang.toUpperCase();
        const coveragePercent = coverage[lang]?.coverage_percent ?? null;

        const header = QSDom.el('div', {
            class: 'sitemap__lang-header',
            dataset: { toggleLang: lang }
        }, [
            QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronRight, 16, 'sitemap__lang-toggle'),
            QSDom.el('span', { class: 'sitemap__lang-flag', text: getFlagEmoji(lang) }),
            QSDom.el('span', { class: 'sitemap__lang-name', text: langName }),
            isDefault
                ? QSDom.el('span', { class: 'badge badge--primary', text: sitemap.default || 'Default' })
                : null,
            QSDom.el('span', {
                class: 'sitemap__lang-count',
                text: routes.length + ' ' + (sitemap.pages || 'pages')
            }),
            coveragePercent !== null
                ? QSDom.el('span', {
                    class: 'sitemap__lang-coverage ' + getCoverageClass(coveragePercent),
                    text: coveragePercent + '%'
                })
                : null
        ]);

        const routesBox = QSDom.el('div', {
            class: 'sitemap__routes sitemap__routes--tree'
        }, [renderRouteTree(routeTree, lang)]);

        // The default language is the one that starts open.
        return QSDom.el('div', {
            class: 'sitemap__lang' + (isDefault ? ' sitemap__lang--open' : ''),
            dataset: { lang: lang }
        }, [header, routesBox]);
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
            const hp = canRun;

            // getRoutes was the one stat call with no gate at all — not even a
            // permission check — so it fired for every account on every load.
            if (hp('getRoutes')) {
                const routesResult = await QuickSiteAdmin.apiRequest('getRoutes');
                if (routesResult.ok) {
                    document.getElementById('stat-routes').textContent = routesResult.data.data?.count || 0;
                }
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

            const root = QSDom.el('div', { class: 'sitemap' }, [
                _renderSitemapSummary(data, routes, languages, multilingual, sitemap)
            ]);

            if (multilingual) {
                const sortedLangs = [...languages].sort((a, b) => {
                    if (a === defaultLang) return -1;
                    if (b === defaultLang) return 1;
                    return a.localeCompare(b);
                });

                const langsBox = QSDom.el('div', { class: 'sitemap__languages' });
                sortedLangs.forEach((lang) => {
                    langsBox.appendChild(_renderSitemapLanguage(
                        lang, lang === defaultLang, languageNames, coverage,
                        routeTree, routes, sitemap
                    ));
                });
                root.appendChild(langsBox);
            } else {
                root.appendChild(QSDom.el('div', {
                    class: 'sitemap__routes sitemap__routes--flat sitemap__routes--tree'
                }, [renderRouteTree(routeTree, 'default')]));
            }

            QSDom.clear(container);
            container.appendChild(root);

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
            QSDom.clear(container);
            container.appendChild(_renderEmpty(sitemap.error || 'Failed to load site map', true));
        }
    }

    // ========================================================================
    // Recent Commands
    // ========================================================================

    /**
     * One row of the recent-commands table.
     * @returns {HTMLElement}
     */
    function _renderRecentCommandRow(entry, common) {
        const httpStatus = entry.result?.http_status || entry.result?.status;
        const isSuccess = typeof httpStatus === 'number'
            ? httpStatus >= 200 && httpStatus < 300
            : httpStatus === 'success';

        return QSDom.el('tr', null, [
            QSDom.el('td', null, [QSDom.el('code', { text: entry.command })]),
            QSDom.el('td', null, [
                QSDom.el('span', {
                    class: 'badge ' + (isSuccess ? 'badge--success' : 'badge--error'),
                    text: isSuccess ? (common.success || 'Success') : (common.error || 'Error')
                })
            ]),
            QSDom.el('td', { text: entry.duration_ms + 'ms' }),
            QSDom.el('td', { text: new Date(entry.timestamp).toLocaleString() })
        ]);
    }

    /**
     * The recent-commands table: header row plus one row per entry.
     * @returns {HTMLElement}
     */
    function _renderRecentCommandsTable(entries, cols, common) {
        const head = QSDom.el('thead', null, [
            QSDom.el('tr', null, [
                QSDom.el('th', { text: cols.command || 'Command' }),
                QSDom.el('th', { text: cols.status || 'Status' }),
                QSDom.el('th', { text: cols.duration || 'Duration' }),
                QSDom.el('th', { text: cols.time || 'Time' })
            ])
        ]);

        const body = QSDom.el('tbody');
        entries.forEach(entry => body.appendChild(_renderRecentCommandRow(entry, common)));

        return QSDom.el('table', { class: 'admin-table' }, [head, body]);
    }

    async function loadRecentCommands() {
        const container = document.getElementById('recent-commands');
        const cols = t('dashboard.history.columns', {});
        const common = t('common', {});
        const noHistoryText = t('dashboard.noHistory', 'No recent commands');

        const showEmpty = () => {
            QSDom.clear(container);
            container.appendChild(_renderEmpty(noHistoryText, true));
        };

        try {
            const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, []);

            if (result.ok && result.data.data?.entries?.length > 0) {
                const entries = result.data.data.entries.slice(0, 5);
                QSDom.clear(container);
                container.appendChild(_renderRecentCommandsTable(entries, cols, common));
            } else {
                showEmpty();
            }
        } catch (error) {
            showEmpty();
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

    // Folder icon as an ELEMENT. QuickSiteUtils.icon* return HTML STRINGS; the
    // element-shaped route through the same catalogue is QSDom.iconEl.
    //
    // ⚠ This one keeps its own path rather than moving to ICON_PATHS.folder:
    // the two are DIFFERENT drawings (this is the tabbed folder, the catalogue's
    // is the square-cornered one), so switching would quietly change the icon
    // in the project-manager rows. Unifying them is a design call, not a
    // refactor.
    function _folderIcon(size) {
        return QSDom.svgIcon('M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', size || 18);
    }

    /** The panel's spinner-plus-label loading block. @returns {HTMLElement} */
    function _renderLoading(text) {
        const spinner = QSDom.el('span', { class: 'admin-spinner' });
        return QSDom.el('div', { class: 'admin-loading' }, [spinner, ' ' + text]);
    }

    /** A red alert box holding one message. @returns {HTMLElement} */
    function _renderAlertError(message) {
        return QSDom.el('div', { class: 'admin-alert admin-alert--error', text: message });
    }

    /** The "nothing here" block. @returns {HTMLElement} */
    function _renderEmpty(text, pad) {
        return QSDom.el('div',
            pad ? { class: 'admin-empty', style: 'padding: var(--space-lg);' }
                : { class: 'admin-empty' },
            [QSDom.el('p', { text: text })]);
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
            // Your own quota is a fact about your ACCOUNT, not about a project
            // being developed, so it comes from /admin/self (S6).
            const result = await QuickSiteAdmin.accountRequest(
                refresh ? 'space-usage?refresh=1' : 'space-usage', 'GET'
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
            const quota = data.quota || {};

            // WHAT THE FULL BAR MEANS depends on whether a quota exists. With one,
            // the bar is the ALLOWANCE and the unfilled remainder is the free space —
            // the gap IS the figure, which is why free space needs no segment of its
            // own. With no quota there is no allowance to draw, so the bar falls back
            // to meaning "100% = what you have used" and only the proportions between
            // categories are readable. Over quota, the denominator becomes the total
            // again so the segments still add up to a full bar instead of overflowing.
            const denom = (quota.configured && quota.limit > 0)
                ? Math.max(quota.limit, total)
                : total;

            ['content', 'backups', 'builds', 'exports'].forEach(cat => {
                const bytes = cats[cat]?.size || 0;
                const seg = document.getElementById('owner-space-seg-' + cat);
                const val = document.getElementById('owner-space-val-' + cat);
                if (seg) {
                    const pct = denom > 0 ? (bytes / denom) * 100 : 0;
                    seg.style.width = pct > 0 ? Math.max(pct, 1) + '%' : '0%';
                }
                if (val) val.textContent = cats[cat]?.size_formatted || formatSize(bytes);
            });

            // Free space: shown only when a quota is actually configured. On the
            // default install there is no ceiling, so there is no "remaining" to
            // report and the row stays hidden rather than reading 0 or Unlimited.
            const freeRow = document.getElementById('owner-space-legend-free');
            const freeVal = document.getElementById('owner-space-val-free');
            if (freeRow) freeRow.style.display = quota.configured ? '' : 'none';
            if (freeVal && quota.configured) {
                freeVal.textContent = quota.over
                    ? t('dashboard.storage.overQuota', 'none — over the {limit} limit')
                        .replace('{limit}', quota.limit_formatted || formatSize(quota.limit || 0))
                    : (quota.free_formatted || formatSize(quota.free || 0))
                        + ' / ' + (quota.limit_formatted || formatSize(quota.limit || 0));
            }

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
                // Same rule as the load-time blocks (13.6b): all three panels are
                // project-scoped, so with no project bound they would each fire a
                // call api.js refuses client-side and then render "Error loading
                // data" three times over. Gated at the CALL SITE rather than inside
                // each loader so the loaders stay untouched.
                if (canRun('getBuild'))     loadManageSpaceBuilds();
                if (canRun('getSizeInfo'))  loadManageSpaceExports();
                if (canRun('listBackups'))  loadManageSpaceBackups();
            }
        });
    }

    // Retention is one build per project, so this panel shows THE build or an
    // empty row - never a list. getBuild answers 404 when there is none, which
    // is the empty state rather than an error.
    async function loadManageSpaceBuilds() {
        const list = document.getElementById('manage-builds-list');
        const count = document.getElementById('manage-builds-count');
        if (!list) return;

        const emptyRow = () => QSDom.el('div', { class: 'manage-space__empty', text: t('dashboard.storage.noItems', 'No items') });

        QSDom.clear(list);
        list.appendChild(QSDom.el('div', { class: 'manage-space__loading', text: t('common.loading', 'Loading...') }));

        try {
            const result = await QuickSiteAdmin.apiRequest('getBuild');
            const build = (result.ok && result.data?.data?.exists) ? result.data.data : null;

            count.textContent = build ? '1' : '0';
            QSDom.clear(list);
            if (!build) { list.appendChild(emptyRow()); return; }

            list.appendChild(_renderManageSpaceBuild(build, list, count, emptyRow));
        } catch (error) {
            QSDom.clear(list);
            list.appendChild(QSDom.el('div', { class: 'manage-space__empty', text: t('common.error', 'Error loading data') }));
        }
    }

    function _renderManageSpaceBuild(build, list, count, emptyRow) {
        const sizeMb = build.size_mb || 0;
        const sizeStr = sizeMb < 1 ? (sizeMb * 1024).toFixed(0) + ' KB' : sizeMb.toFixed(2) + ' MB';
        const date = build.created ? new Date(build.created).toLocaleDateString() : '--';

        const nameChildren = [build.name];
        if (build.complete === false) {
            nameChildren.push(QSDom.el('span', {
                class: 'manage-space__item-badge',
                text: t('dashboard.storage.incomplete', 'incomplete')
            }));
        }

        // An incomplete build cannot be downloaded - the command refuses it, so
        // the button would only ever produce an error toast.
        const dlBtn = build.complete === false ? null : QSDom.el('button', {
            type: 'button',
            class: 'manage-space__delete-btn',
            title: t('dashboard.storage.download', 'Download')
        }, [QSDom.svgIcon('M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2', 14)]);

        const delBtn = QSDom.el('button', {
            type: 'button',
            class: 'manage-space__delete-btn',
            title: t('common.delete', 'Delete')
        }, [QSDom.svgIcon('M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 14)]);

        const item = QSDom.el('div', { class: 'manage-space__item', dataset: { build: build.name } }, [
            QSDom.el('div', { class: 'manage-space__item-info' }, [
                QSDom.el('span', { class: 'manage-space__item-name' }, nameChildren),
                QSDom.el('span', { class: 'manage-space__item-meta', text: date + ' \u00b7 ' + sizeStr }),
            ]),
            dlBtn,
            delBtn,
        ]);

        if (dlBtn) {
            dlBtn.addEventListener('click', async function () {
                this.disabled = true;
                try {
                    // Not a link: the management surface needs an Authorization
                    // header, which an anchor cannot send. downloadFile fetches
                    // with the header and hands the blob to the browser.
                    const res = await QuickSiteAdmin.downloadFile('downloadBuild');
                    if (!res.ok) {
                        QuickSiteAdmin.showToast(res.data?.message || 'Download failed', 'error');
                    }
                } catch (e) {
                    QuickSiteAdmin.showToast('Download failed', 'error');
                }
                this.disabled = false;
            });
        }

        delBtn.addEventListener('click', async function () {
            if (!confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + build.name)) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('deleteBuild', 'POST', {});
                if (res.ok) {
                    item.remove();
                    count.textContent = '0';
                    list.appendChild(emptyRow());
                    loadStorageOverview();
                    QuickSiteAdmin.showToast(t('dashboard.storage.deleted', 'Deleted') + ': ' + build.name, 'success');
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

    async function loadManageSpaceExports() {
        const list = document.getElementById('manage-exports-list');
        const count = document.getElementById('manage-exports-count');
        if (!list) return;

        const emptyRow = () => QSDom.el('div', { class: 'manage-space__empty', text: t('dashboard.storage.noItems', 'No items') });

        QSDom.clear(list);
        list.appendChild(QSDom.el('div', { class: 'manage-space__loading', text: t('common.loading', 'Loading...') }));

        try {
            const result = await QuickSiteAdmin.apiRequest('getSizeInfo');
            // getSizeInfo returns { summary, public, secure } — the folder map is
            // secure.folders. The old `secure_folders` path never existed, so this
            // panel always reported 0 files.
            const exports = result.data?.data?.secure?.folders?.exports || {};
            const totalBytes = exports.size || 0;
            const fileCount = exports.files || 0;
            count.textContent = fileCount;

            QSDom.clear(list);
            if (fileCount === 0) { list.appendChild(emptyRow()); return; }

            list.appendChild(_renderManageSpaceExports(fileCount, totalBytes, list, count, emptyRow));
        } catch (error) {
            QSDom.clear(list);
            list.appendChild(QSDom.el('div', { class: 'manage-space__empty', text: t('common.error', 'Error loading data') }));
        }
    }

    function _renderManageSpaceExports(fileCount, totalBytes, list, count, emptyRow) {
        const clearBtn = QSDom.el('button', {
            type: 'button',
            class: 'manage-space__clear-btn',
            id: 'clear-exports-btn',
            text: t('dashboard.storage.clearAll', 'Clear All')
        });

        const item = QSDom.el('div', { class: 'manage-space__item manage-space__item--summary' }, [
            QSDom.el('div', { class: 'manage-space__item-info' }, [
                QSDom.el('span', {
                    class: 'manage-space__item-name',
                    text: fileCount + ' ' + t('dashboard.storage.exportFiles', 'export file(s)')
                }),
                QSDom.el('span', { class: 'manage-space__item-meta', text: formatSize(totalBytes) }),
            ]),
            clearBtn,
        ]);

        clearBtn.addEventListener('click', async function () {
            if (!confirm(t('dashboard.storage.confirmClearExports', 'Clear all export files?'))) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('clearExports', 'POST', { confirm: true });
                if (res.ok) {
                    count.textContent = '0';
                    item.remove();
                    list.appendChild(emptyRow());
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

        return item;
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

    /**
     * A typed project name, made into an id: lowercase letters, digits, `-` and
     * `_`, anything else becoming `-`. The one rule New Project, Clone and Import
     * share; the server still validates what it receives.
     */
    function normalizeProjectName(value) {
        return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '-');
    }

    /**
     * The name Import suggests for an archive: its file name without `.zip`, a
     * browser's duplicate-download marker (` (1)`), and the `_export_<date>_<time>`
     * suffix exportProject names every archive with. So
     * `test2_export_20260918_101500.zip` suggests `test2` — the project it was
     * exported from, which is also the name the server itself would use. A file
     * named any other way is suggested whole.
     */
    function importNameFromFile(fileName) {
        const base = String(fileName || '')
            .replace(/\.zip$/i, '')
            .replace(/\s*\(\d+\)$/, '')
            .replace(/_export_\d{8}_\d{6}$/, '');
        return normalizeProjectName(base);
    }

    function setupProjectManagerEvents() {
        const proj = t('dashboard.projects', {});
        const common = t('common', {});
        
        // Switch project
        document.getElementById('btn-switch-project').addEventListener('click', async function() {
            const selector = document.getElementById('project-selector');
            const newProject = selector.value;
            
            if (!newProject || newProject === currentProject) return;
            
            QSDom.setButtonBusy(this, common.loading || 'Switching...');
            try {
                // C9 — the dashboard's project switch changes which project you EDIT,
                // the SAME as the header picker; it does NOT change the served main
                // (quicksite stays at the site root). Panel state, not a command.
                const result = await QuickSiteAdmin.setSelectedProject(newProject);
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
        function openCreateProjectModal() {
            document.getElementById('modal-create-project').style.display = 'flex';
            document.getElementById('create-project-name').focus();
        }

        document.getElementById('btn-create-project').addEventListener('click', openCreateProjectModal);

        // ?create=project opens the form directly. The memberships page's
        // zero-membership empty state links here rather than carrying its own
        // copy of this modal, and a link that only gets you to the right PAGE
        // leaves the reader hunting for the button.
        if (new URLSearchParams(window.location.search).get('create') === 'project') {
            openCreateProjectModal();
        }
        
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
            const name = normalizeProjectName(nameInput.value);
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            QSDom.setButtonBusy(this, proj.cloning || 'Cloning...');
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
            const name = normalizeProjectName(nameInput.value);
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            QSDom.setButtonBusy(this, common.loading || 'Creating...');
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
            const originalText = QSDom.setButtonBusy(this, proj.exporting || 'Exporting...');

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
                    // The `.catch(() => ({}))` this replaces did not throw, so
                    // this path was never broken — but it turned every non-JSON
                    // failure into the same generic toast with the status code
                    // thrown away. readResponseBody keeps the status in the
                    // sentence and cannot throw either.
                    const errorData = (await window.QuickSiteAPI.readResponseBody(response)) || {};
                    QuickSiteAdmin.showToast(errorData.message || 'Failed to export project', 'error');
                }
            } catch (error) {
                console.error('Export error:', error);
                QuickSiteAdmin.showToast('Failed to export project', 'error');
            }
            
            this.disabled = false;
            this.textContent = originalText;
        });
        
        // Import project: choose the archive, then name the project it becomes.
        // The name is pre-filled from the archive's file name (importNameFromFile);
        // the server still validates it and refuses an id already in use (409), so
        // on any refusal the modal stays open to try another name with the same file.
        let importFile = null;

        document.getElementById('btn-import-project').addEventListener('click', function() {
            document.getElementById('import-file-input').click();
        });

        document.getElementById('import-file-input').addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            importFile = this.files[0];
            // Cleared now, so choosing the same archive again still fires `change`.
            this.value = '';

            const nameInput = document.getElementById('import-project-name');
            document.getElementById('import-file-name').textContent = importFile.name;
            nameInput.value = importNameFromFile(importFile.name);
            document.getElementById('modal-import-project').style.display = 'flex';
            nameInput.focus();
            nameInput.select();
        });

        document.getElementById('btn-confirm-import').addEventListener('click', async function() {
            if (!importFile) return;
            const name = normalizeProjectName(document.getElementById('import-project-name').value);
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('file', importFile);
            formData.append('name', name);

            const originalText = QSDom.setButtonBusy(this, proj.importing || 'Importing project...');
            // importProject is a GLOBAL command, so upload() sends it to the global
            // endpoint with no project marker. It reads the body without assuming
            // JSON: an archive is the biggest upload QuickSite accepts, so it is the
            // likeliest to be refused by the web server in front of PHP with an
            // HTML error page, and that refusal must reach the toast as a size
            // limit rather than as a parse error.
            let result = null;
            try {
                result = await QuickSiteAPI.upload('importProject', formData);
            } catch (error) {
                console.error('Import error:', error);
            }
            if (result && result.ok) {
                QuickSiteAdmin.showToast(result.data?.message || proj.imported || 'Project imported successfully', 'success');
                // Full reload: a new project changes the header picker, the nav
                // permission set and the storage totals — not just this card.
                window.location.href = window.location.pathname + '?t=' + Date.now();
                return;
            }
            QuickSiteAdmin.showToast(result?.data?.message || 'Failed to import project', 'error');
            this.disabled = false;
            this.textContent = originalText;
        });
        
        // Backup project
        document.getElementById('btn-backup-project').addEventListener('click', async function() {
            const originalText = QSDom.setButtonBusy(this, proj.backing_up || 'Creating backup...');
            
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
            this.textContent = originalText;
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

        QSDom.clear(container);
        container.appendChild(_renderLoading(common.loading || 'Loading...'));

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

        // 13.6b: the viewer's own calls (listPages / listComponents / getStructure)
        // are project-scoped. This panel is user-TRIGGERED rather than load-time, so
        // it never showed up in the console — but with no project bound every one of
        // its buttons leads to a refusal. Disable the controls instead, the same way
        // _setProjectActionsEnabled already handles the project-action buttons.
        if (!canRun('getStructure')) {
            typeSelect.disabled = true;
            nameSelect.disabled = true;
            loadBtn.disabled = true;
            return;
        }

        const trans = window.QUICKSITE_CONFIG?.translations?.structure?.select || {};
        
        typeSelect.addEventListener('change', async function() {
            const type = this.value;
            
            if (!type) {
                QSDom.setSelectPlaceholder(nameSelect, trans.typeFirst || 'Select type first...');
                nameSelect.disabled = true;
                loadBtn.disabled = true;
                return;
            }
            
            if (type === 'menu' || type === 'footer') {
                QSDom.setSelectPlaceholder(nameSelect, (trans.notRequired || 'Not required for :type').replace(':type', type));
                nameSelect.disabled = true;
                loadBtn.disabled = false;
            } else {
                nameSelect.disabled = true;
                QSDom.setSelectPlaceholder(nameSelect, t('common.loading'));
                
                try {
                    const endpoint = type === 'page' ? 'pages' : 'components';
                    const options = await QuickSiteAdmin.fetchHelperData(endpoint);
                    
                    QSDom.setSelectPlaceholder(nameSelect, (trans.selectType || 'Select :type...').replace(':type', type));
                    options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        nameSelect.appendChild(option);
                    });
                    nameSelect.disabled = false;
                } catch (error) {
                    QSDom.setSelectPlaceholder(nameSelect, t('commands.errorLoadingOptions'));
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
        
        QSDom.clear(treeContainer);
        treeContainer.appendChild(_renderLoading(trans.loading || 'Loading structure...'));
        
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
                QSDom.clear(treeContainer);
                treeContainer.appendChild(_renderAlertError(
                    result.data?.message || trans.loadFailed || 'Failed to load structure'
                ));
            }
        } catch (error) {
            QSDom.clear(treeContainer);
            treeContainer.appendChild(_renderAlertError(
                t('common.error') + ': ' + error.message
            ));
        }
    }

    /**
     * Render structure tree in dashboard
     */
    function renderDashboardStructureTree(structure, container) {
        if (!structure || (Array.isArray(structure) && structure.length === 0)) {
            const trans = window.QUICKSITE_CONFIG?.translations?.structure?.tree || {};
            QSDom.clear(container);
            container.appendChild(_renderEmpty(trans.isEmpty || 'Structure is empty'));
            return;
        }
        
        const tree = Array.isArray(structure) ? structure : [structure];
        QSDom.clear(container);
        container.appendChild(renderDashboardNodes(tree, 0, ''));
    }

    /**
     * The label spans describing one node: <tag#id.class>, <Component/>,
     * {{translation.key}}, "raw text", or <unknown> — then its [nodeId].
     *
     * The angle brackets are TEXT here. They were &lt;/&gt; entities while this
     * was built as a string; textContent takes the characters themselves, so
     * the rendered result is identical.
     *
     * @returns {Node[]} the spans, in order
     */
    function _renderNodeLabel(node, element, attributes, nodeId) {
        const span = (cls, text) => QSDom.el('span', { class: cls, text: text });
        const parts = [];

        if (node.component) {
            parts.push(span('admin-tree__component', '<' + node.component + '/>'));
        } else if (node.tag) {
            parts.push(span('admin-tree__element', '<' + element));
            if (attributes.id) {
                parts.push(span('admin-tree__attr-id', '#' + attributes.id));
            }
            if (attributes.class) {
                const classes = Array.isArray(attributes.class)
                    ? attributes.class.join(' ')
                    : attributes.class;
                parts.push(span('admin-tree__attr-class', '.' + classes.replace(/\s+/g, '.')));
            }
            parts.push(span('admin-tree__element', '>'));
        } else if (node.textKey) {
            parts.push(span('admin-tree__trans', '{{' + node.textKey + '}}'));
        } else if (node.text) {
            const preview = node.text.length > 30
                ? node.text.substring(0, 30) + '...'
                : node.text;
            parts.push(span('admin-tree__text', '"' + preview + '"'));
        } else {
            parts.push(span('admin-tree__element', '<unknown>'));
        }

        parts.push(span('admin-tree__node-id', '[' + nodeId + ']'));
        return parts;
    }

    /**
     * The expand/collapse triangle for a node that has children.
     *
     * What this replaces carried the entire toggle as an inline onclick
     * attribute, quote-escaped through two levels of string literal. Same
     * behaviour, as a listener.
     *
     * @returns {HTMLElement}
     */
    function _renderTreeToggle() {
        const toggle = QSDom.el('span', { class: 'admin-tree__toggle', text: '\u25b6' });
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const item = toggle.closest('.admin-tree__item');
            if (!item) return;
            const expanded = item.classList.toggle('admin-tree__item--expanded');
            toggle.textContent = expanded ? '\u25bc' : '\u25b6';
        });
        return toggle;
    }

    /**
     * Render tree nodes recursively for dashboard.
     * @returns {HTMLElement} ONE <ul>
     */
    function renderDashboardNodes(nodes, depth, parentPath) {
        const ul = QSDom.el('ul', { class: 'admin-tree' });

        nodes.forEach((node, index) => {
            const nodePath = parentPath ? parentPath + '.' + index : String(index);
            const nodeId = node._nodeId ?? nodePath;

            const element = node.tag || node.component || (node.textKey ? 'text' : (node.text ? 'raw' : 'node'));
            const hasChildren = node.children && node.children.length > 0;
            const attributes = node.params || {};

            const row = QSDom.el('div', { class: 'admin-tree__row' }, [
                hasChildren ? _renderTreeToggle() : QSDom.el('span', { class: 'admin-tree__spacer' })
            ]);
            _renderNodeLabel(node, element, attributes, nodeId).forEach(n => row.appendChild(n));

            const li = QSDom.el('li', {
                class: 'admin-tree__item' + (hasChildren ? ' admin-tree__item--has-children' : ''),
                dataset: { nodeId: String(nodeId) }
            }, [row]);

            if (hasChildren) {
                li.appendChild(renderDashboardNodes(node.children, depth + 1, nodePath));
            }

            ul.appendChild(li);
        });

        return ul;
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

        // The button's resting label, rebuilt after a failed restore.
        const restoreLabel = () => [
            QSDom.iconEl(QuickSiteUtils.ICON_PATHS.refresh, 16),
            ' ' + (t('dashboard.projects', {}).restoreBtn || 'Restore')
        ];

        confirmRestoreBtn.addEventListener('click', async function() {
            if (!pendingRestoreBackup) return;
            
            const proj = t('dashboard.projects', {});
            const createBackup = document.getElementById('restore-create-backup').checked;
            
            QSDom.setButtonBusy(this, proj.restoring || 'Restoring...', { size: 16 });
            
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
                    QSDom.clear(this);
                    restoreLabel().forEach(n => this.append(n));
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to restore backup', 'error');
                this.disabled = false;
                QSDom.clear(this);
                restoreLabel().forEach(n => this.append(n));
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

        const hp = canRun;

        // Load dashboard data, skipping sections the current user lacks permission
        // for OR that are project-scoped with no project bound (13.6b).
        await Promise.all([
            loadDashboardStats(),
            hp('getSiteMap')         ? loadSiteMap()         : Promise.resolve(),
            hp('getCommandHistory')  ? loadRecentCommands()  : Promise.resolve(),
            hp('listProjects')       ? loadProjectManager()  : Promise.resolve(),
            hp('getSizeInfo')        ? loadStorageOverview() : Promise.resolve(),
            // Owner-wide usage walks every owned project, so it loads alongside
            // rather than gating anything; it hides itself when you own nothing.
            // No canRun() gate: it stopped being a command in S6, so there is no
            // permission to test — every authenticated account may ask for its
            // own quota, and the answer is empty when you own nothing.
            loadOwnerSpaceUsage(false)
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
