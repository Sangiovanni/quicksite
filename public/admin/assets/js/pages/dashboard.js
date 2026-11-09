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
     * Without the second half, the dashboard fires getSizeInfo and logs
     * "getSizeInfo failed: Unknown error" for an account that is a member of
     * nothing. Gate on BOTH, in one place, so a future call inherits it.
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

        return QSDom.tableWrapper(QSDom.el('table', { class: 'admin-table' }, [head, body]));
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
    // The Edited Project
    // ========================================================================

    /** The project you edit, as the page was served it: My total space's rows load apart from the project list, and may draw first. */
    function editedProjectId() {
        return currentProject || (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.currentProject) || null;
    }

    /** A project of listProjects' answer, by id, or null. */
    function projectById(id) {
        return allProjects.find(p => p.project === id) || null;
    }

    /** "Name #id" for a project id (the id alone when it is not in the list). */
    function labelOf(id) {
        const p = projectById(id);
        return QuickSiteAdmin.projectLabel(id, p ? p.name : null);
    }

    /** Rename is the OWNER's (renameProject is owner-only): the button follows your role on the edited project. */
    function _syncRenameButton() {
        const btn = document.getElementById('btn-rename-project');
        if (!btn) return;
        const p = projectById(currentProject);
        btn.disabled = !(p && p.my_role === 'owner');
    }

    /** The panel's spinner-plus-label loading block. @returns {HTMLElement} */
    function _renderLoading(text) {
        const spinner = QSDom.el('span', { class: 'admin-spinner' });
        return QSDom.el('div', { class: 'admin-loading' }, [spinner, ' ' + text]);
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
            _setProjectActionsEnabled(false);
            _syncRenameButton();
            return;
        }

        // Default the edited project when the client emitted none / a stale one.
        if (!currentProject || !projectById(currentProject)) {
            currentProject = allProjects[0].project;
        }
        const edited = projectById(currentProject) || allProjects[0];
        infoContainer.appendChild(_renderProjectChip(edited, proj));

        _setProjectActionsEnabled(true);
        _syncRenameButton();
    }

    /** The page's first line: the edited project's star, its name, that you edit it, and your role on it. @returns {HTMLElement} */
    function _renderProjectChip(edited, proj) {
        const star = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--ghost admin-project-star', id: 'btn-star-project' });
        QuickSiteAdmin.syncStarToggle(star, edited.project);
        QuickSiteAdmin.bindStarToggle(star);
        return QSDom.el('div', { class: 'dashboard-current__line' }, [
            star,
            QSDom.el('span', { class: 'dashboard-current__name', text: QuickSiteAdmin.projectLabel(edited.project, edited.name) }),
            _renderCopyIdButton(edited.project, proj),
            QSDom.el('span', { class: 'badge badge--primary', text: proj.editing || 'Editing' }),
            edited.my_role ? QSDom.el('span', { class: 'members-role-chip members-role-chip--' + edited.my_role, text: edited.my_role }) : null,
        ]);
    }

    /** A button copying the project's id: what someone types to ask to join it. @returns {HTMLElement} */
    function _renderCopyIdButton(projectId, proj) {
        return QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--ghost admin-btn--icon-only',
            title: proj.copyId,
            'aria-label': proj.copyId,
            onClick: function () {
                window.QuickSiteUtils.copyToClipboard(projectId, proj.idCopied);
            },
        }, [QSDom.svgIcon('M8 8h12v12H8zM16 8V4H4v12h4', 14)]);
    }

    /** The same line for an account in no project yet. @returns {HTMLElement} */
    function _renderProjectManagerEmpty(proj) {
        return QSDom.el('div', {}, [
            QSDom.el('div', { class: 'dashboard-current__line', text: proj.noProjects || 'You are not a member of any project yet.' }),
            QSDom.el('div', { class: 'dashboard-current__hint', text: proj.noProjectsHint || 'Create a project to get started.' }),
        ]);
    }

    // Only the project-SCOPED actions depend on having a project. `btn-create-project`
    // and `btn-import-project` are GLOBAL (create / create-from-archive) — a member of
    // nothing must still be able to make or import their first project.
    function _setProjectActionsEnabled(enabled) {
        ['btn-clone-project', 'btn-backup-project', 'btn-restore-backup',
         'btn-export-project'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.disabled = !enabled;
        });
    }

    // ========================================================================
    // Storage Overview
    // ========================================================================

    /**
     * A project's bin, in My total space: that list is the projects you own, and deleting one is
     * the owner's (the server authorizes the project in the URL marker). One question names the
     * project, what goes with it, and whether it is the one you are editing; then the page reloads,
     * as a project gone leaves the header's list, this list and the Members counts.
     *
     * @returns {HTMLElement}
     */
    function _renderDeleteProjectButton(project) {
        const proj = t('dashboard.projects', {});
        const label = QuickSiteAdmin.projectLabel(project.project, project.name);
        const title = t('dashboard.projects.deleteTitle', 'Delete Project');
        const button = QSDom.el('button', {
            type: 'button',
            class: 'manage-space__delete-btn',
            title: title,
            'aria-label': title + ': ' + label,
        }, [QSDom.svgIcon('M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 14)]);

        button.addEventListener('click', async function () {
            // A function, so a project's name is taken as it is: a string would read `$&` in it as a pattern.
            let question = t('dashboard.projects.deleteConfirmNamed', 'dashboard.projects.deleteConfirmNamed').replace(':name', () => label);
            if (project.project === editedProjectId()) {
                question += '\n\n' + t('dashboard.projects.deleteConfirmEditing', 'This is the project you are editing.');
            }
            const confirmed = await QuickSiteAdmin.confirm(question, {
                title: title,
                type: 'danger',
                confirmText: t('dashboard.projects.deleteBtn', 'Delete Project'),
                confirmClass: 'danger'
            });
            if (!confirmed) return;

            this.disabled = true;
            try {
                // Target the project being deleted via the URL marker (opts.project); the server
                // authorizes THAT project (owner-only) and refuses if the body name disagrees.
                const result = await QuickSiteAdmin.apiRequest('deleteProject', 'POST', {
                    name: project.project,
                    confirm: true
                }, [], {}, { project: project.project });
                if (result.ok) {
                    reloadWithMessage(proj.deleted || 'Project deleted');
                    return;
                }
                QuickSiteAdmin.showToast(result.data?.message || 'Failed to delete project', 'error');
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to delete project', 'error');
            }
            this.disabled = false;
        });
        return button;
    }

    /**
     * A row's name: another project's switches to it, after one question; the edited project's
     * is only its name. The same write as the header's list, panel state and not a command.
     *
     * @returns {HTMLElement}
     */
    function _renderProjectRowName(project) {
        const label = QuickSiteAdmin.projectLabel(project.project, project.name);
        if (project.project === editedProjectId()) {
            return QSDom.el('span', { class: 'owner-space__project-name', text: label, title: label });
        }
        const title = t('dashboard.projects.switch', 'Switch Project');
        const name = QSDom.el('button', {
            type: 'button',
            class: 'owner-space__project-name owner-space__project-switch',
            text: label,
            title: title + ': ' + label,
        });
        name.addEventListener('click', async function () {
            const proj = t('dashboard.projects', {});
            const confirmed = await QuickSiteAdmin.confirm(
                t('dashboard.projects.switchConfirmNamed', 'Switch to ":name"? Every page of the panel then works on this project.').replace(':name', () => label),
                { title: title, confirmText: t('dashboard.projects.switchBtn', 'Switch') }
            );
            if (!confirmed) return;
            this.disabled = true;
            try {
                const result = await QuickSiteAdmin.setSelectedProject(project.project);
                if (result.ok) {
                    reloadWithMessage((proj.switched || 'Switched to project') + ': ' + label);
                    return;
                }
                QuickSiteAdmin.showToast(result.data?.message || 'Failed to switch project', 'error');
            } catch (error) {
                QuickSiteAdmin.showToast('Failed to switch project', 'error');
            }
            this.disabled = false;
        });
        return name;
    }

    /**
     * One row in the owner-space project list. Returns ONE element.
     * Built with createElement + textContent: project names are user-authored.
     */
    function _renderOwnerSpaceRow(project, grandTotal) {
        const row = document.createElement('div');
        row.className = 'owner-space__project';

        row.appendChild(_renderProjectRowName(project));

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
        row.appendChild(_renderDeleteProjectButton(project));

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

    // My total space lists its five largest projects (the answer comes largest
    // first), every one after "Show all", or every match of its search; the total,
    // the bar and the legend always count every project owned.
    const OWNER_SPACE_TOP = 5;
    let ownerSpaceRows = [];
    let ownerSpaceGrandTotal = 0;
    let ownerSpaceExpanded = false;

    /** The rows My total space shows now, with its search, its button and its no-match line. */
    function renderOwnerSpaceRows() {
        const list = document.getElementById('owner-space-projects');
        if (!list) return;
        const searchWrap = document.getElementById('owner-space-search-wrap');
        const search = document.getElementById('owner-space-search');
        const more = document.getElementById('owner-space-more');
        const noMatch = document.getElementById('owner-space-no-match');
        const many = ownerSpaceRows.length > OWNER_SPACE_TOP;
        const typed = many && search ? search.value.trim() : '';
        // a project id may be typed as it is shown, after a #
        const query = typed.replace(/^#/, '').toLowerCase();
        let shown;
        if (query !== '') {
            shown = ownerSpaceRows.filter(p => String(p.project || '').toLowerCase().includes(query)
                || String(p.name || '').toLowerCase().includes(query));
        } else {
            shown = (ownerSpaceExpanded || !many) ? ownerSpaceRows : ownerSpaceRows.slice(0, OWNER_SPACE_TOP);
        }
        list.replaceChildren(...shown.map(p => _renderOwnerSpaceRow(p, ownerSpaceGrandTotal)));
        if (searchWrap) searchWrap.hidden = !many;
        if (noMatch) {
            noMatch.hidden = !(query !== '' && shown.length === 0);
            noMatch.textContent = noMatch.hidden ? ''
                : t('dashboard.storage.noMatch', 'dashboard.storage.noMatch').replace('{query}', typed);
        }
        if (more) {
            more.hidden = !many || query !== '';
            more.textContent = ownerSpaceExpanded
                ? t('dashboard.storage.showTop', 'dashboard.storage.showTop').replace('{n}', OWNER_SPACE_TOP)
                : t('dashboard.storage.showAll', 'dashboard.storage.showAll').replace('{n}', ownerSpaceRows.length);
            more.setAttribute('aria-expanded', ownerSpaceExpanded ? 'true' : 'false');
        }
    }

    /** My total space's search and its Show all / Show the 5 largest button. */
    function setupOwnerSpaceList() {
        const search = document.getElementById('owner-space-search');
        if (search) search.addEventListener('input', renderOwnerSpaceRows);
        const more = document.getElementById('owner-space-more');
        if (more) {
            more.addEventListener('click', () => {
                ownerSpaceExpanded = !ownerSpaceExpanded;
                renderOwnerSpaceRows();
            });
        }
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
            // being developed, so it comes from /admin/self.
            const result = await QuickSiteAdmin.accountRequest(
                refresh ? 'space-usage?refresh=1' : 'space-usage', 'GET'
            );
            const data = result.ok ? result.data?.data : null;

            // Owning nothing is a normal state, not an error: the card shows only
            // Manage Space, or nothing.
            if (!data || !data.project_count) {
                showOwnerSpace(section, false);
                return;
            }
            showOwnerSpace(section, true);

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

            ['content', 'backups', 'builds', 'exports', 'snippets'].forEach(cat => {
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

            // Over the quota: a warning that says what is refused and how to get room back.
            const overBox = document.getElementById('owner-space-over');
            if (overBox) overBox.style.display = (quota.configured && quota.over) ? '' : 'none';

            ownerSpaceRows = data.projects || [];
            ownerSpaceGrandTotal = total;
            renderOwnerSpaceRows();

            const hint = document.getElementById('owner-space-hint');
            if (hint) {
                hint.textContent = data.cache?.from_cache
                    ? t('dashboard.storage.cachedHint', 'Cached — refresh to recalculate')
                    : '';
            }
        } catch (error) {
            console.error('Failed to load owner space usage:', error);
            showOwnerSpace(section, false);
        }
    }

    /**
     * My total space, with Manage Space at its foot. Your own space shows when you own
     * a project. Manage Space is about the EDITED project, so it stays for an account
     * that owns nothing but can read one of its three lists: the card then holds it
     * alone. With neither, the card stays hidden.
     */
    function showOwnerSpace(section, ownsProjects) {
        const manageReach = canRun('getBuild') || canRun('getSizeInfo') || canRun('listBackups');
        const card = section.querySelector('.owner-space');
        if (card) card.classList.toggle('owner-space--manage-only', !ownsProjects);
        section.style.display = (ownsProjects || manageReach) ? '' : 'none';
    }

    /**
     * After something on this page changed what a project takes: the edited project's
     * figures and your own, re-measured, or a deletion reads high until the cache lapses.
     */
    function refreshSpaceFigures() {
        loadStorageOverview();
        loadOwnerSpaceUsage(true);
    }

    /** The same after a backup made or deleted from Project management, with Manage Space's list if it has been opened. */
    function afterBackupsChanged() {
        refreshSpaceFigures();
        if (manageSpaceLoaded && canRun('listBackups')) loadManageSpaceBackups();
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
                // Same rule as the load-time blocks: all three panels are
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

        // A finished build that may carry the OAuth secrets is only for a role that may set
        // the project's keys: downloadBuild and deleteBuild refuse any other, so neither
        // button is offered. The builds page, linked above this list, says why.
        const outOfReach = build.complete !== false && build.oauth_secrets_included !== false && !canRun('setOAuthCredentials');

        // An incomplete build cannot be downloaded - the command refuses it, so
        // the button would only ever produce an error toast.
        const dlBtn = (build.complete === false || outOfReach) ? null : QSDom.el('button', {
            type: 'button',
            class: 'manage-space__delete-btn',
            title: t('dashboard.storage.download', 'Download')
        }, [QSDom.svgIcon('M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2', 14)]);

        const delBtn = outOfReach ? null : QSDom.el('button', {
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

        if (delBtn) {
            delBtn.addEventListener('click', async function () {
                if (!(await QuickSiteUtils.confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + build.name, { type: 'danger' }))) return;
                this.disabled = true;
                try {
                    const res = await QuickSiteAdmin.apiRequest('deleteBuild', 'POST', {});
                    if (res.ok) {
                        item.remove();
                        count.textContent = '0';
                        list.appendChild(emptyRow());
                        refreshSpaceFigures();
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
        }

        return item;
    }

    async function loadManageSpaceExports() {
        const section = document.getElementById('manage-space-exports');
        const list = document.getElementById('manage-exports-list');
        const count = document.getElementById('manage-exports-count');
        if (!list) return;

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
            // The section shows only when the project holds a saved export: the panel's Export
            // keeps none on the server, and an API caller's save=true is what leaves one.
            if (fileCount === 0) { section.style.display = 'none'; return; }

            section.style.display = '';
            list.appendChild(_renderManageSpaceExports(fileCount, totalBytes, section));
        } catch (error) {
            QSDom.clear(list);
            section.style.display = '';
            list.appendChild(QSDom.el('div', { class: 'manage-space__empty', text: t('common.error', 'Error loading data') }));
        }
    }

    function _renderManageSpaceExports(fileCount, totalBytes, section) {
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
            if (!(await QuickSiteUtils.confirm(t('dashboard.storage.confirmClearExports', 'Clear all export files?'), { type: 'danger' }))) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('clearExports', 'POST', { confirm: true });
                if (res.ok) {
                    section.style.display = 'none';
                    item.remove();
                    refreshSpaceFigures();
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
            projectLabel.textContent = '(' + labelOf(currentProject) + ')';
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
            if (!(await QuickSiteUtils.confirm(t('dashboard.storage.confirmDelete', 'Delete this item?') + '\n' + b.name, { type: 'danger' }))) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: b.name }, [], {}, { project: managed });
                if (res.ok) {
                    item.remove();
                    const remaining = list.querySelectorAll('.manage-space__item').length;
                    count.textContent = remaining;
                    if (remaining === 0) list.appendChild(emptyRow());
                    refreshSpaceFigures();
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
    // The Edited Project's Buttons
    // ========================================================================

    /**
     * A typed project name as it is sent: trimmed. A name is free text (the
     * server's rule: 1 to 60 characters, no control character); the project's
     * id is drawn by the server, never made from the name.
     */
    function cleanProjectName(value) {
        return String(value || '').trim();
    }

    /**
     * The name Import suggests when the archive carries none: its file name
     * without `.zip`, a browser's duplicate-download marker (` (1)`), and the
     * `_export_<date>_<time>` suffix exportProject names every archive with
     * (with its `_<n>` when several were saved in one second).
     */
    function importNameFromFile(fileName) {
        const base = String(fileName || '')
            .replace(/\.zip$/i, '')
            .replace(/\s*\(\d+\)$/, '')
            .replace(/_export_\d{8}_\d{6}(?:_\d+)?$/, '')
            .trim();
        return Array.from(base).slice(0, 60).join('');
    }

    /**
     * The project name an archive carries: the `name` in its `config/project.json`
     * (at the archive's root or in its one project folder, as exportProject writes
     * it), read in the browser before anything is uploaded, so the Import dialog
     * opens with the source project's name. Reads the ZIP's central directory and
     * that one entry — stored, or deflated and inflated with the browser's own
     * DecompressionStream. Resolves to null when the archive cannot be read this
     * way or names nothing; the dialog then suggests the file name, and the server
     * decides in any case.
     *
     * @param {File} file
     * @returns {Promise<?string>}
     */
    async function readArchiveProjectName(file) {
        try {
            const tailSize = Math.min(file.size, 65557);   // the end record + its longest comment
            const tail = new DataView(await file.slice(file.size - tailSize).arrayBuffer());
            let eocd = -1;
            for (let i = tailSize - 22; i >= 0; i--) {
                if (tail.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
            }
            if (eocd < 0) return null;
            const cdSize = tail.getUint32(eocd + 12, true);
            const cdOffset = tail.getUint32(eocd + 16, true);
            if (cdOffset + cdSize > file.size) return null;
            const cd = new DataView(await file.slice(cdOffset, cdOffset + cdSize).arrayBuffer());
            const decoder = new TextDecoder();
            for (let p = 0; p + 46 <= cd.byteLength && cd.getUint32(p, true) === 0x02014b50;) {
                const method = cd.getUint16(p + 10, true);
                const compSize = cd.getUint32(p + 20, true);
                const nameLen = cd.getUint16(p + 28, true);
                const extraLen = cd.getUint16(p + 30, true);
                const commentLen = cd.getUint16(p + 32, true);
                const localOffset = cd.getUint32(p + 42, true);
                const name = decoder.decode(new Uint8Array(cd.buffer, cd.byteOffset + p + 46, nameLen));
                p += 46 + nameLen + extraLen + commentLen;
                if (!/^([^/]+\/)?config\/project\.json$/.test(name) || compSize > 65536) continue;
                const local = new DataView(await file.slice(localOffset, localOffset + 30).arrayBuffer());
                if (local.getUint32(0, true) !== 0x04034b50) return null;
                const start = localOffset + 30 + local.getUint16(26, true) + local.getUint16(28, true);
                let bytes = file.slice(start, start + compSize);
                if (method === 8) {
                    if (typeof DecompressionStream !== 'function') return null;
                    bytes = await new Response(bytes.stream().pipeThrough(new DecompressionStream('deflate-raw'))).blob();
                } else if (method !== 0) {
                    return null;
                }
                const data = JSON.parse(await bytes.text());
                const value = data && typeof data.name === 'string' ? data.name.trim() : '';
                return value !== '' && Array.from(value).length <= 60 ? value : null;
            }
        } catch (error) {
            // Not an archive this can read: the file name stands in.
        }
        return null;
    }

    /**
     * Reload the dashboard after a success, its toast carried across the reload.
     * A toast shown just before a reload is wiped by it, and a reload that stays
     * on the same project then confirms nothing; admin.js shows the pending
     * message on the next page load. A failure that changed the project reloads
     * too, with its type ('error') and a duration of 0, so it stays until closed.
     */
    function reloadWithMessage(message, type, duration) {
        QuickSiteAdmin.setPendingMessage(message, type, duration);
        window.location.href = window.location.pathname + '?t=' + Date.now();
    }

    function setupProjectManagerEvents() {
        const proj = t('dashboard.projects', {});
        const common = t('common', {});
        
        // The project's first language, offered from the installation's language
        // list with the installation's default language preselected — the one
        // createProject uses when none is sent. getLanguageList returns both, and
        // it is global, so it answers before any project exists. Filled once, the
        // first time the dialog opens.
        let createLanguagesLoading = null;

        async function fillCreateLanguageSelect() {
            const select = document.getElementById('create-project-language');
            if (!select) return;
            QSDom.setSelectPlaceholder(select, select.dataset.placeholder);
            try {
                const res = await QuickSiteAdmin.apiRequest('getLanguageList', 'GET');
                const data = (res && res.ok && res.data && res.data.data) || {};
                const languages = data.languages || [];
                languages.forEach(l => {
                    select.appendChild(QSDom.el('option', {
                        value: l.code,
                        text: (l.name && l.name !== l.code) ? l.name + ' (' + l.code + ')' : l.code
                    }));
                });
                if (languages.some(l => l.code === data.default_language)) {
                    select.value = data.default_language;
                }
            } catch (error) {
                // The placeholder stands: a create sent without a language takes
                // the command's own default.
            }
            if (window.QSSearchableSelect && !select._qsPicker) {
                select._qsPicker = new window.QSSearchableSelect(select, {
                    placeholder: select.dataset.placeholder,
                    searchPlaceholder: select.dataset.searchPlaceholder,
                    emptyText: select.dataset.emptyText
                });
            }
        }

        // Create project modal
        function openCreateProjectModal() {
            document.getElementById('modal-create-project').style.display = 'flex';
            document.getElementById('create-project-name').focus();
            if (!createLanguagesLoading) {
                createLanguagesLoading = fillCreateLanguageSelect();
            }
        }

        document.getElementById('btn-create-project').addEventListener('click', openCreateProjectModal);

        // ?create=project opens the form directly. The memberships page's
        // zero-membership empty state links here rather than carrying its own
        // copy of this modal, and a link that only gets you to the right PAGE
        // leaves the reader hunting for the button.
        if (new URLSearchParams(window.location.search).get('create') === 'project') {
            openCreateProjectModal();
        }
        
        // The selector's choice moves what Rename may act on, and what the star stars.

        // Clone project modal: prefilled with the source's name (the copy gets
        // an id of its own, drawn by the server).
        document.getElementById('btn-clone-project').addEventListener('click', function() {
            const target = currentProject;
            const source = projectById(target);
            document.getElementById('clone-source-name').textContent = labelOf(target);
            document.getElementById('clone-project-name').value = source ? source.name : target;
            document.getElementById('modal-clone-project').style.display = 'flex';
            document.getElementById('clone-project-name').focus();
            document.getElementById('clone-project-name').select();
        });

        // Rename project modal: the edited project, its current name prefilled. The
        // button is enabled only for its owner.
        document.getElementById('btn-rename-project').addEventListener('click', function() {
            const target = currentProject;
            const current = projectById(target);
            if (!current || current.my_role !== 'owner') return;
            document.getElementById('rename-project-current').textContent = labelOf(target);
            const input = document.getElementById('rename-project-name');
            input.value = current.name;
            document.getElementById('modal-rename-project').style.display = 'flex';
            input.focus();
            input.select();
        });

        document.getElementById('btn-confirm-rename').addEventListener('click', async function() {
            const target = currentProject;
            const name = cleanProjectName(document.getElementById('rename-project-name').value);
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            const originalText = QSDom.setButtonBusy(this, proj.renaming || 'Renaming...');
            let result = null;
            try {
                result = await QuickSiteAdmin.apiRequest('renameProject', 'POST', { name: name }, [], {}, { project: target });
            } catch (error) {
                console.error('Rename error:', error);
            }
            if (result && result.ok) {
                // Full reload: the name shows in the header picker, which the
                // server renders, as well as on this page.
                const d = result.data?.data || {};
                reloadWithMessage((proj.renamed || 'Project renamed') + ': ' + QuickSiteAdmin.projectLabel(d.project || target, d.name || name));
                return;
            }
            QuickSiteAdmin.showToast(result?.data?.message || 'Failed to rename project', 'error');
            this.disabled = false;
            this.textContent = originalText;
        });

        // Confirm clone project
        document.getElementById('btn-confirm-clone').addEventListener('click', async function() {
            const nameInput = document.getElementById('clone-project-name');
            const activateCheckbox = document.getElementById('clone-project-activate');
            const name = cleanProjectName(nameInput.value);
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            QSDom.setButtonBusy(this, proj.cloning || 'Cloning...');
            try {
                // Source = the selected target project (bound to the URL marker
                // server-side); body carries only the new name + switch_to.
                const target = currentProject;
                const result = await QuickSiteAdmin.apiRequest('cloneProject', 'POST', {
                    name: name,
                    switch_to: activateCheckbox.checked
                }, [], {}, { project: target });
                
                if (result.ok) {
                    const d = result.data?.data || {};
                    const filesCopied = d.files_copied || '';
                    const msg = (proj.cloned || 'Project cloned') + ': ' + QuickSiteAdmin.projectLabel(d.project, d.name || name)
                        + (filesCopied ? ' (' + filesCopied + ' files)' : '');
                    // A clone never carries the source's OAuth keys: say which to create again.
                    // The warning stays until closed, and survives the reload. Its words come
                    // with the clone dialog (dashboard.php), as the template hands its others.
                    const keysLeft = Array.isArray(d.oauth_keys_not_copied) ? d.oauth_keys_not_copied : [];
                    const keysWords = document.getElementById('modal-clone-project')?.dataset.oauthKeysText || '';
                    const keysMsg = keysLeft.length
                        ? keysWords.split('{providers}').join(keysLeft.join(', ')) : '';
                    closeAllModals();
                    if (activateCheckbox.checked) {
                        if (keysMsg) reloadWithMessage(msg + '. ' + keysMsg, 'warning', 0);
                        else reloadWithMessage(msg);
                    } else {
                        QuickSiteAdmin.showToast(msg, 'success');
                        if (keysMsg) QuickSiteAdmin.showToast(keysMsg, 'warning', 0);
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
            const name = cleanProjectName(nameInput.value);
            
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }
            
            QSDom.setButtonBusy(this, common.loading || 'Creating...');
            try {
                const body = { name: name, switch_to: activateCheckbox.checked };
                const languageSelect = document.getElementById('create-project-language');
                if (languageSelect && languageSelect.value) {
                    body.language = languageSelect.value;
                }
                const result = await QuickSiteAdmin.apiRequest('createProject', 'POST', body);
                
                if (result.ok) {
                    const d = result.data?.data || {};
                    const msg = (proj.created || 'Project created') + ': ' + QuickSiteAdmin.projectLabel(d.project, d.name || name);
                    closeAllModals();
                    if (activateCheckbox.checked) {
                        reloadWithMessage(msg);
                    } else {
                        QuickSiteAdmin.showToast(msg, 'success');
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
        
        // Export project: its dialog first, which asks whether the archive carries the
        // project's OAuth build keys (unticked each time it opens).
        document.getElementById('btn-export-project').addEventListener('click', function() {
            document.getElementById('export-source-name').textContent = labelOf(currentProject);
            document.getElementById('export-include-secrets').checked = false;
            document.getElementById('modal-export-project').style.display = 'flex';
        });

        document.getElementById('btn-confirm-export').addEventListener('click', async function() {
            // The button's icon and words, put back as they were once the export is done.
            const restore = Array.from(this.childNodes);
            QSDom.setButtonBusy(this, proj.exporting || 'Exporting...');

            try {
                // exportProject streams a binary ZIP: the client's download path
                // carries the '/p/<id>/' marker for the target (the command binds it
                // as the target, so no ?name is needed), reads a refusal as an
                // envelope, and keeps the write allowance current like every call.
                const target = currentProject;
                const withKeys = document.getElementById('export-include-secrets').checked;
                const res = await QuickSiteAdmin.downloadFile('exportProject', [],
                    withKeys ? { include_oauth_secrets: 'true' } : {},
                    { project: target, filename: target + '_export.zip' });

                if (res.ok) {
                    closeAllModals();
                    QuickSiteAdmin.showToast(proj.exported || 'Project exported', 'success');
                } else {
                    QuickSiteAdmin.showToast((res.data && res.data.message) || 'Failed to export project', 'error');
                }
            } catch (error) {
                console.error('Export error:', error);
                QuickSiteAdmin.showToast('Failed to export project', 'error');
            }
            
            this.disabled = false;
            this.replaceChildren(...restore);
        });
        
        // Import project: choose the archive, then name the project it becomes.
        // The name is pre-filled from the archive's own name (readArchiveProjectName),
        // else its file name; the imported project always gets an id of its own,
        // drawn by the server. On any refusal the modal stays open to try again
        // with the same file.
        let importFile = null;

        document.getElementById('btn-import-project').addEventListener('click', function() {
            document.getElementById('import-file-input').click();
        });

        document.getElementById('import-file-input').addEventListener('change', async function() {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];
            importFile = file;
            // Cleared now, so choosing the same archive again still fires `change`.
            this.value = '';

            const fromArchive = await readArchiveProjectName(file);
            if (importFile !== file) return;   // another archive was chosen meanwhile
            const nameInput = document.getElementById('import-project-name');
            document.getElementById('import-file-name').textContent = file.name;
            nameInput.value = fromArchive || importNameFromFile(file.name);
            document.getElementById('modal-import-project').style.display = 'flex';
            nameInput.focus();
            nameInput.select();
        });

        document.getElementById('btn-confirm-import').addEventListener('click', async function() {
            if (!importFile) return;
            const name = cleanProjectName(document.getElementById('import-project-name').value);
            if (!name) {
                QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('file', importFile);
            formData.append('name', name);
            formData.append('switch_to', document.getElementById('import-project-activate').checked ? 'true' : 'false');

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
                // Full reload: a new project changes the header picker, the nav
                // permission set and the storage totals — not just this card.
                reloadWithMessage(result.data?.message || proj.imported || 'Project imported successfully');
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
                const result = await QuickSiteAdmin.apiRequest('backupProject', 'GET', null, [], {}, { project: currentProject });

                if (result.ok && result.data?.data?.backup) {
                    const data = result.data.data;
                    QuickSiteAdmin.showToast(
                        t('dashboard.projects.backup_created', 'dashboard.projects.backup_created') + ': ' + data.backup.name + ' (' + data.backup.size_formatted + ')',
                        'success'
                    );
                    afterBackupsChanged();
                } else if (result.data?.code === 'backup.incomplete') {
                    // Nothing was kept, so nothing on the page changed: a red message that
                    // stays until it is closed, naming what could not be copied.
                    const items = (result.data.data?.failed_items || []).join(', ');
                    QuickSiteAdmin.showToast(
                        t('dashboard.projects.backup_incomplete', 'dashboard.projects.backup_incomplete').replace(':items', items),
                        'error',
                        0
                    );
                } else {
                    // The server's own words: a quota refusal carries the figures and the
                    // way out, so it stays until it is closed too.
                    QuickSiteAdmin.showToast(
                        result.data?.message || t('dashboard.projects.backup_failed', 'dashboard.projects.backup_failed'),
                        'error',
                        result.data?.code === 'quota.storage_exceeded' ? 0 : undefined
                    );
                }
            } catch (error) {
                console.error('Backup error:', error);
                QuickSiteAdmin.showToast(t('dashboard.projects.backup_failed', 'dashboard.projects.backup_failed'), 'error');
            }

            this.disabled = false;
            this.textContent = originalText;
        });
        
        // Restore backup modal — pin the target project the modal operates on.
        document.getElementById('btn-restore-backup').addEventListener('click', async function() {
            restoreTargetProject = currentProject;
            document.getElementById('modal-restore-backup').style.display = 'flex';
            await loadBackupList();
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
        document.getElementById('restore-delete-backup').checked = false;

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

        const restoreBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--sm admin-btn--primary btn-restore-this' }, [
            QSDom.svgIcon('M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15', 14),
            ' ' + (proj.restore_btn || 'Restore'),
        ]);
        const delBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--sm admin-btn--ghost admin-btn--danger btn-delete-backup' }, [
            QSDom.svgIcon('M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 14),
        ]);

        restoreBtn.addEventListener('click', () => openRestoreConfirmModal(backup.name));
        delBtn.addEventListener('click', async function () {
            if (!(await QuickSiteUtils.confirm((proj.confirm_delete_backup || 'Delete backup') + ': ' + backup.name + '?', { type: 'danger' }))) return;
            this.disabled = true;
            try {
                const res = await QuickSiteAdmin.apiRequest('deleteBackup', 'DELETE', { backup: backup.name }, [], {}, { project: target });
                if (res.ok) {
                    QuickSiteAdmin.showToast(proj.backup_deleted || 'Backup deleted', 'success');
                    await loadBackupList();
                    afterBackupsChanged();
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
            ' ' + t('dashboard.projects.restoreBtn', 'dashboard.projects.restoreBtn')
        ];
        const restoreFailed = t('dashboard.projects.restore_failed', 'dashboard.projects.restore_failed');

        confirmRestoreBtn.addEventListener('click', async function() {
            if (!pendingRestoreBackup) return;

            const createBackup = document.getElementById('restore-create-backup').checked;
            const deleteBackup = document.getElementById('restore-delete-backup').checked;

            QSDom.setButtonBusy(this, t('dashboard.projects.restoring', 'dashboard.projects.restoring'), { size: 16 });

            try {
                const result = await QuickSiteAdmin.apiRequest('restoreBackup', 'POST', {
                    backup: pendingRestoreBackup,
                    create_backup: createBackup,
                    delete_backup: deleteBackup
                }, [], {}, { project: restoreTargetProject || currentProject });

                if (result.ok) {
                    closeAllModals();
                    reloadWithMessage(t('dashboard.projects.restore_success', 'dashboard.projects.restore_success'));
                } else if (result.data?.code === 'restore.incomplete') {
                    // Part of the backup came back, so the project has changed: the page
                    // reloads to show it as it now is, with a message that stays until
                    // it is closed and names what the restore could not bring back.
                    const items = (result.data.data?.failed_items || []).join(', ');
                    closeAllModals();
                    reloadWithMessage(
                        t('dashboard.projects.restore_incomplete', 'dashboard.projects.restore_incomplete').replace(':items', items),
                        'error',
                        0
                    );
                } else {
                    // A quota refusal names the way out (free space, or delete this backup
                    // once it is restored), so it stays until it is closed.
                    QuickSiteAdmin.showToast(result.data?.message || restoreFailed, 'error',
                        result.data?.code === 'quota.storage_exceeded' ? 0 : undefined);
                    this.disabled = false;
                    QSDom.clear(this);
                    restoreLabel().forEach(n => this.append(n));
                }
            } catch (error) {
                QuickSiteAdmin.showToast(restoreFailed, 'error');
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
        setupOwnerSpaceList();

        // Wait for admin.js to finish loading permissions before checking them
        await (window.QuickSiteAdmin?.permissionsReady || Promise.resolve());

        const hp = canRun;

        // Load dashboard data, skipping sections the current user lacks permission
        // for OR that are project-scoped with no project bound.
        await Promise.all([
            loadDashboardStats(),
            hp('getSiteMap')         ? loadSiteMap()         : Promise.resolve(),
            hp('getCommandHistory')  ? loadRecentCommands()  : Promise.resolve(),
            hp('listProjects')       ? loadProjectManager()  : Promise.resolve(),
            hp('getSizeInfo')        ? loadStorageOverview() : Promise.resolve(),
            // Owner-wide usage walks every owned project, so it loads alongside
            // rather than gating anything; when you own nothing it keeps only Manage
            // Space, or hides itself.
            // No canRun() gate: it is not a command, so there is no
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
    });

})();
