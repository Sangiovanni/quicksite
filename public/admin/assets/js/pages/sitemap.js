/**
 * Sitemap Page JavaScript
 * 
 * Visual route tree, reachability graph, route CRUD, and layout management.
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';

    // ========================================================================
    // Module State
    // ========================================================================

    let sitemapData = null;
    let reachabilityData = null;
    let expandedNodes = new Set();      // track which tree nodes are open
    let openContextMenu = null;         // currently open [⋯] menu element

    // ========================================================================
    // Helpers
    // ========================================================================

    function t(key, fallback) {
        const translations = window.QUICKSITE_CONFIG?.translations?.sitemapPage || {};
        return translations[key] || fallback || key;
    }

    const esc = (s) => QuickSiteAdmin.escapeHtml(String(s));

    function getCoverageClass(percent) {
        if (percent >= 95) return 'sitemap__coverage--excellent';
        if (percent >= 80) return 'sitemap__coverage--good';
        if (percent >= 50) return 'sitemap__coverage--warning';
        return 'sitemap__coverage--poor';
    }

    function getFlagEmoji(langCode) {
        const flags = {
            'en': '🇬🇧', 'fr': '🇫🇷', 'es': '🇪🇸', 'de': '🇩🇪', 'it': '🇮🇹',
            'pt': '🇵🇹', 'nl': '🇳🇱', 'ru': '🇷🇺', 'zh': '🇨🇳', 'ja': '🇯🇵',
            'ko': '🇰🇷', 'ar': '🇸🇦', 'hi': '🇮🇳', 'tr': '🇹🇷', 'pl': '🇵🇱',
            'sv': '🇸🇪', 'da': '🇩🇰', 'fi': '🇫🇮', 'no': '🇳🇴', 'cs': '🇨🇿',
            'el': '🇬🇷', 'he': '🇮🇱', 'th': '🇹🇭', 'vi': '🇻🇳', 'id': '🇮🇩',
            'ms': '🇲🇾', 'uk': '🇺🇦', 'ro': '🇷🇴', 'hu': '🇭🇺', 'bg': '🇧🇬'
        };
        return flags[langCode?.toLowerCase()] || '🌐';
    }

    // ========================================================================
    // Tree Building (adapted from dashboard.js)
    // ========================================================================

    function buildRouteTree(routes) {
        const tree = {};
        routes.forEach(route => {
            const parts = String(route.name).split('/');
            let current = tree;
            parts.forEach((part, index) => {
                if (!current[part]) current[part] = {};
                if (index === parts.length - 1) current[part]._route = route;
                current = current[part];
            });
        });
        return tree;
    }

    /**
     * Count children (non-_route keys) recursively
     */
    function countDescendants(node) {
        let count = 0;
        for (const [key, child] of Object.entries(node)) {
            if (key === '_route') continue;
            count++;
            count += countDescendants(child);
        }
        return count;
    }

    /**
     * Get the depth of a route path (segments count - 1)
     */
    function getRouteDepth(routePath) {
        return String(routePath).split('/').length - 1;
    }

    // ========================================================================
    // Step 2: Render Route Tree (with action buttons for Steps 4-6)
    // ========================================================================

    function renderRouteTree(tree, depth = 0, parentPath = '') {
        let html = '';
        const entries = Object.entries(tree).filter(([key]) => key !== '_route');
        const layouts = sitemapData?.routeLayouts || {};

        entries.sort(([a], [b]) => {
            if (a === 'home') return -1;
            if (b === 'home') return 1;
            return a.localeCompare(b);
        });

        entries.forEach(([name, node]) => {
            const route = node._route;
            if (!route) return;

            const children = Object.entries(node).filter(([key]) => key !== '_route');
            const hasChildren = children.length > 0;
            const isHome = name === 'home';
            const routePath = route.name;
            const routeDepth = getRouteDepth(routePath);
            const canAddChild = routeDepth < 4 && !isHome; // max depth 5 = index 4
            const isExpanded = expandedNodes.has(routePath) || (expandedNodes.size === 0 && depth === 0);

            // Layout toggles (menu/footer)
            const layout = layouts[routePath] || { menu: true, footer: true };
            const layoutHtml = `<span class="sitemap-layout-toggles">
                <button type="button" class="sitemap-layout-toggle${layout.menu ? ' sitemap-layout-toggle--on' : ''}" data-action="toggle-menu" data-route="${esc(routePath)}" title="${layout.menu ? t('menuOn', 'Menu: visible') : t('menuOff', 'Menu: hidden')}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <button type="button" class="sitemap-layout-toggle${layout.footer ? ' sitemap-layout-toggle--on' : ''}" data-action="toggle-footer" data-route="${esc(routePath)}" title="${layout.footer ? t('footerOn', 'Footer: visible') : t('footerOff', 'Footer: hidden')}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
                </button>
            </span>`;

            // Action buttons
            const addChildBtn = canAddChild
                ? `<button type="button" class="sitemap-action sitemap-action--add" data-action="add-child" data-route="${esc(routePath)}" title="${t('addChild')}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                   </button>`
                : '';

            const contextBtn = `<button type="button" class="sitemap-action sitemap-action--menu" data-action="context-menu" data-route="${esc(routePath)}" data-is-home="${isHome}" data-has-children="${hasChildren}" data-depth="${routeDepth}" title="Actions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>`;

            const actionsHtml = `<span class="sitemap-actions">${addChildBtn}${contextBtn}</span>`;

            if (hasChildren) {
                html += `<div class="sitemap__tree-node${isExpanded ? ' sitemap__tree-node--open' : ''}" style="--depth: ${depth}" data-route-path="${esc(routePath)}">
                    <div class="sitemap__tree-header">
                        <button class="sitemap__tree-toggle" type="button" aria-label="Toggle">
                            ${QuickSiteUtils.iconChevronRight(12)}
                        </button>
                        <span class="sitemap__route sitemap__route--parent">
                            ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.folder, 14, 'sitemap__route-icon')}
                            <span class="sitemap__route-name">${esc(name)}</span>
                            <span class="sitemap__route-path">${esc(route.path)}</span>
                        </span>
                        ${layoutHtml}
                        ${actionsHtml}
                    </div>
                    <div class="sitemap__tree-children">
                        ${renderRouteTree(node, depth + 1, routePath)}
                    </div>
                </div>`;
            } else {
                const iconPath = isHome
                    ? QuickSiteUtils.ICON_PATHS.home
                    : QuickSiteUtils.ICON_PATHS.file;

                html += `<div class="sitemap__route sitemap__route--leaf" style="--depth: ${depth}" data-route-path="${esc(routePath)}">
                    <svg class="sitemap__route-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        ${iconPath}
                    </svg>
                    <span class="sitemap__route-name">${esc(name)}</span>
                    <span class="sitemap__route-path">${esc(route.path)}</span>
                    ${layoutHtml}
                    ${actionsHtml}
                </div>`;
            }
        });

        return html;
    }

    // ========================================================================
    // Step 2: Summary bar
    // ========================================================================

    function renderSummary(data, validationData) {
        const routes = data.routes || [];
        const languages = data.languages || [];
        const dashSitemap = window.QUICKSITE_CONFIG?.translations?.dashboard?.sitemap || {};

        const coverageBadges = renderCoverage(data, validationData);

        return `<div class="sitemap__summary">
            <span class="sitemap__total">${data.totalUrls} ${dashSitemap.urls || 'URLs'}</span>
            <span class="sitemap__divider">•</span>
            <span>${routes.length} ${dashSitemap.routes || 'routes'}</span>
            <span class="sitemap__divider">•</span>
            ${coverageBadges}
        </div>`;
    }

    // ========================================================================
    // Step 2: Language Coverage
    // ========================================================================

    function renderCoverage(data, validationData) {
        const multilingual = data.multilingual || false;
        const defaultLang = data.defaultLang || 'en';
        const languages = data.languages || [];
        const languageNames = data.languageNames || {};
        const coverage = validationData || {};

        if (!multilingual || languages.length <= 1) {
            return `<span class="badge badge--ghost">${languages[0]?.toUpperCase() || 'EN'}</span>`;
        }

        const sorted = [...languages].sort((a, b) => {
            if (a === defaultLang) return -1;
            if (b === defaultLang) return 1;
            return a.localeCompare(b);
        });

        let html = '<div style="display:flex; gap:var(--space-sm); flex-wrap:wrap; align-items:center;">';
        sorted.forEach(lang => {
            const langName = languageNames[lang] || lang.toUpperCase();
            const isDefault = lang === defaultLang;
            const langCoverage = coverage[lang];
            const coveragePercent = langCoverage?.coverage_percent ?? null;
            const missingCount = langCoverage?.total_missing ?? 0;

            const coverageClass = coveragePercent !== null ? getCoverageClass(coveragePercent) : '';
            const tooltip = coveragePercent !== null
                ? `${langName}: ${coveragePercent}% coverage` + (missingCount > 0 ? ` (${missingCount} missing keys)` : '')
                : langName;

            html += `<span class="sitemap-lang-badge ${coverageClass}" title="${esc(tooltip)}">
                <span class="sitemap-lang-badge__flag">${getFlagEmoji(lang)}</span>
                <span class="sitemap-lang-badge__code">${esc(lang.toUpperCase())}</span>
                ${isDefault ? '<span class="sitemap-lang-badge__default">*</span>' : ''}
                ${coveragePercent !== null ? `<span class="sitemap-lang-badge__pct">${coveragePercent}%</span>` : ''}
            </span>`;
        });
        html += '</div>';
        return html;
    }

    // ========================================================================
    // Step 3: Reachability Graph
    // ========================================================================

    function renderReachability(data) {
        if (!data) return '<p style="color:var(--admin-text-muted);">Could not load reachability data.</p>';

        const { total_routes, reachable_count, orphan_count, reachable, orphans, graph, global_links } = data;
        const allGood = orphan_count === 0;

        // Status banner
        const bannerClass = allGood ? 'admin-alert--success' : 'admin-alert--warning';
        const bannerIcon = allGood
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

        // Stats
        const statsHtml = `<div style="display:flex; gap:var(--space-lg); flex-wrap:wrap; margin-bottom:var(--space-lg);">
            <div style="text-align:center;">
                <div style="font-size:var(--font-size-2xl); font-weight:var(--font-weight-bold);">${total_routes}</div>
                <div style="font-size:var(--font-size-sm); color:var(--admin-text-muted);">${t('totalRoutes', 'Total Routes')}</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:var(--font-size-2xl); font-weight:var(--font-weight-bold); color:var(--admin-success);">${reachable_count}</div>
                <div style="font-size:var(--font-size-sm); color:var(--admin-text-muted);">${t('reachable', 'Reachable')}</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:var(--font-size-2xl); font-weight:var(--font-weight-bold); color:${orphan_count > 0 ? 'var(--admin-error)' : 'var(--admin-text-muted)'};">${orphan_count}</div>
                <div style="font-size:var(--font-size-sm); color:var(--admin-text-muted);">${t('orphans', 'Orphans')}</div>
            </div>
        </div>`;

        // Orphans list
        let orphansHtml = '';
        if (orphans && orphans.length > 0) {
            orphansHtml = `<div style="margin-bottom:var(--space-lg);">
                <h3 style="margin-bottom:var(--space-sm); color:var(--admin-error);">${t('unreachableRoutes', 'Unreachable Routes')}</h3>
                <div style="display:flex; gap:var(--space-xs); flex-wrap:wrap;">
                    ${orphans.map(r => `<span class="admin-badge admin-badge--error">/${esc(r)}</span>`).join('')}
                </div>
                <p style="font-size:var(--font-size-sm); color:var(--admin-text-muted); margin-top:var(--space-xs);">${t('unreachableHint')}</p>
            </div>`;
        }

        // Global nav
        const menuBadges = (global_links?.menu || []).map(r => `<span class="admin-badge admin-badge--info">/${esc(r)}</span>`).join(' ');
        const footerBadges = (global_links?.footer || []).map(r => `<span class="admin-badge admin-badge--info">/${esc(r)}</span>`).join(' ');
        const globalHtml = `<div style="margin-bottom:var(--space-lg);">
            <h3 style="margin-bottom:var(--space-sm);">${t('globalNav', 'Global Navigation Links')}</h3>
            <div style="margin-bottom:var(--space-xs);"><strong>${t('menu', 'Menu')}:</strong> ${menuBadges || `<em>${t('none', 'none')}</em>`}</div>
            <div><strong>${t('footer', 'Footer')}:</strong> ${footerBadges || `<em>${t('none', 'none')}</em>`}</div>
        </div>`;

        // Link graph table
        let graphRows = '';
        const sortedRoutes = Object.keys(graph || {}).sort();
        for (const route of sortedRoutes) {
            const targets = (graph[route] || []).sort();
            const isOrphan = orphans && orphans.includes(route);
            const routeBadgeClass = isOrphan ? 'admin-badge--error' : 'admin-badge--success';
            const targetBadges = targets.map(t => {
                const cls = (orphans && orphans.includes(t)) ? 'admin-badge--error' : 'admin-badge--muted';
                return `<span class="admin-badge admin-badge--small ${cls}">/${esc(t)}</span>`;
            }).join(' ');
            graphRows += `<tr>
                <td><span class="admin-badge ${routeBadgeClass}">/${esc(route)}</span></td>
                <td style="font-size:var(--font-size-sm);">→</td>
                <td>${targetBadges || `<em style="color:var(--admin-text-muted);">${t('noLinks', 'no links')}</em>`}</td>
            </tr>`;
        }
        const graphHtml = `<div>
            <h3 style="margin-bottom:var(--space-sm);">${t('linkGraph', 'Link Graph')}</h3>
            <p style="font-size:var(--font-size-sm); color:var(--admin-text-muted); margin-bottom:var(--space-sm);">${t('linkGraphDesc')}</p>
            <table class="admin-table" style="width:100%;">
                <thead><tr><th>${t('route', 'Route')}</th><th></th><th>${t('linksTo', 'Links To')}</th></tr></thead>
                <tbody>${graphRows}</tbody>
            </table>
        </div>`;

        return `
            <div class="admin-alert ${bannerClass}" style="margin-bottom:var(--space-lg);">
                ${bannerIcon}
                <strong>${allGood ? t('allReachable') : orphan_count + ' ' + t('orphansFound', 'orphan route(s) found').replace('{count}', orphan_count)}</strong>
            </div>
            ${statsHtml}
            ${orphansHtml}
            ${globalHtml}
            ${graphHtml}
        `;
    }

    // ========================================================================
    // Step 4: Inline Add Route Form
    // ========================================================================

    function showInlineAddForm(parentRoute) {
        // Remove any existing inline form
        hideInlineAddForm();

        const isRoot = !parentRoute;
        const label = isRoot ? t('newRoute', 'New route') : `${t('newChildOf', 'New child of')} "${parentRoute}"`;

        const formHtml = `<div class="sitemap-inline-form" id="sitemap-add-form">
            <span class="sitemap-inline-form__label">${esc(label)}:</span>
            <input type="text" class="admin-input admin-input--sm" id="sitemap-new-route-name"
                   placeholder="${isRoot ? t('routeNameNested', 'route-name or parent/child') : t('routeName', 'route-name')}" pattern="[a-z0-9\\-/]+" autofocus />
            <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="sitemap-create-btn">${t('create', 'Create')}</button>
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="sitemap-cancel-btn">${t('cancel', 'Cancel')}</button>
            <span class="sitemap-inline-form__error" id="sitemap-add-error"></span>
        </div>`;

        if (isRoot) {
            // Insert at top of tree container
            const treeContainer = document.getElementById('sitemap-tree-container');
            treeContainer.insertAdjacentHTML('afterbegin', formHtml);
        } else {
            // Insert after the route's tree header or leaf element
            const routeEl = document.querySelector(`[data-route-path="${CSS.escape(parentRoute)}"]`);
            if (routeEl) {
                const header = routeEl.querySelector('.sitemap__tree-header') || routeEl;
                header.insertAdjacentHTML('afterend', formHtml);
            }
        }

        const nameInput = document.getElementById('sitemap-new-route-name');
        const createBtn = document.getElementById('sitemap-create-btn');
        const cancelBtn = document.getElementById('sitemap-cancel-btn');

        nameInput?.focus();

        // Beta.8 A1 — segment validation matches the server-side rules in
        // addRoute.php. Two valid shapes:
        //   1. Literal: lowercase letters / digits / hyphens (no leading/
        //      trailing hyphen).
        //   2. Param: ':' followed by a lowercase identifier
        //      ([a-z_][a-z0-9_]*). Captures URL path-segment values into
        //      the matched route at request time.
        // Final / leading slash + empty segments are not accepted.
        const isLiteralSeg = (s) => /^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/.test(s);
        const isParamSeg   = (s) => /^:[a-z_][a-z0-9_]*$/.test(s);

        const doCreate = async () => {
            const name = nameInput.value.trim();
            if (!name) return;

            // Split + validate each segment. Empty segments (e.g. leading
            // or trailing slash, double slash) fail both checks → invalid.
            const segments = name.split('/');
            const badSeg = segments.find(s => !isLiteralSeg(s) && !isParamSeg(s));
            if (badSeg !== undefined) {
                document.getElementById('sitemap-add-error').textContent =
                    'Invalid segment "' + badSeg + '". Use lowercase letters, numbers, hyphens (no leading/trailing hyphens), or ":name" for a path parameter, separated by /.';
                return;
            }

            createBtn.disabled = true;
            createBtn.innerHTML = `${QuickSiteUtils.htmlSpinner()} ${t('create')}`;

            try {
                // Build full route path — addRoute handles cascade parent creation
                const fullRoute = parentRoute ? parentRoute + '/' + name : name;
                const body = { route: fullRoute };

                const result = await QuickSiteAdmin.apiRequest('addRoute', 'POST', body);
                if (result.ok) {
                    // The server's ApiResponse envelope is
                    //   {status, code, message, data: {…inner payload…}}
                    // so the addRoute response data (cascade_created, warnings,
                    // route, etc.) lives at result.data.data — NOT result.data,
                    // which is the envelope itself.
                    const payload = result.data?.data || {};

                    // Remember expanded state + expand parent
                    saveExpandedState();
                    if (parentRoute) expandedNodes.add(parentRoute);
                    // Also expand any cascade-created parents
                    if (Array.isArray(payload.cascade_created)) {
                        payload.cascade_created.forEach(r => expandedNodes.add(r));
                    }
                    QuickSiteAdmin.showToast(t('routeCreated', 'Route created successfully'), 'success');

                    // Beta.8 A1 — surface server-side conflict warnings as
                    // toasts. Each warning carries 'type' (i18n key for future
                    // localisation) + EN 'message' fallback we display today.
                    // Future polish could render inline beneath the form.
                    if (Array.isArray(payload.warnings) && payload.warnings.length > 0) {
                        payload.warnings.forEach((w) => {
                            const msg = w.message || w.type || 'Route warning';
                            QuickSiteAdmin.showToast(msg, 'warning');
                        });
                    }

                    await refreshAll();
                } else {
                    document.getElementById('sitemap-add-error').textContent = result.data?.message || 'Error';
                    createBtn.disabled = false;
                    createBtn.textContent = t('create', 'Create');
                }
            } catch (err) {
                document.getElementById('sitemap-add-error').textContent = err.message;
                createBtn.disabled = false;
                createBtn.textContent = t('create', 'Create');
            }
        };

        createBtn?.addEventListener('click', doCreate);
        nameInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') doCreate();
            if (e.key === 'Escape') hideInlineAddForm();
        });
        cancelBtn?.addEventListener('click', hideInlineAddForm);
    }

    function hideInlineAddForm() {
        document.getElementById('sitemap-add-form')?.remove();
    }

    // ========================================================================
    // Step 5: Context Menu
    // ========================================================================

    function showContextMenu(button) {
        closeContextMenu();

        const route = button.dataset.route;
        const isHome = button.dataset.isHome === 'true';
        const hasChildren = button.dataset.hasChildren === 'true';
        const depth = parseInt(button.dataset.depth || '0');
        const canAddChild = depth < 4 && !isHome;

        let items = '';

        if (canAddChild) {
            items += `<button class="sitemap-ctx__item" data-ctx-action="add-child" data-route="${esc(route)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                ${t('addChild', 'Add child')}
            </button>`;
        }

        items += `<button class="sitemap-ctx__item" data-ctx-action="view-in-editor" data-route="${esc(route)}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            ${t('viewInEditor', 'View in editor')}
        </button>`;

        items += `<button class="sitemap-ctx__item" data-ctx-action="open-page" data-route="${esc(route)}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            ${t('openPage', 'Open page')}
        </button>`;

        items += `<button class="sitemap-ctx__item" data-ctx-action="edit-title" data-route="${esc(route)}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            ${t('editTitle', 'Edit title')}
        </button>`;

        if (!isHome) {
            items += `<div class="sitemap-ctx__divider"></div>`;
            items += `<button class="sitemap-ctx__item sitemap-ctx__item--danger" data-ctx-action="delete" data-route="${esc(route)}" data-has-children="${hasChildren}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                ${t('deleteRoute', 'Delete route')}
            </button>`;
        } else {
            items += `<div class="sitemap-ctx__divider"></div>`;
            items += `<button class="sitemap-ctx__item sitemap-ctx__item--disabled" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                ${t('cannotDeleteHome', 'Cannot delete home')}
            </button>`;
        }

        const menu = document.createElement('div');
        menu.className = 'sitemap-ctx';
        menu.innerHTML = items;

        // Position using fixed positioning relative to button
        const rect = button.getBoundingClientRect();
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 4) + 'px';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        menu.style.left = 'auto';
        document.body.appendChild(menu);
        openContextMenu = menu;

        // Handle clicks on context menu items
        menu.addEventListener('click', (e) => {
            const ctxItem = e.target.closest('[data-ctx-action]');
            if (!ctxItem) return;
            e.preventDefault();
            e.stopPropagation();
            const action = ctxItem.dataset.ctxAction;
            const itemRoute = ctxItem.dataset.route;

            closeContextMenu();
            handleContextAction(action, itemRoute, ctxItem);
        });

        // Close on outside click
        setTimeout(() => {
            document.addEventListener('click', closeContextMenuOnOutside);
        }, 0);
    }

    function closeContextMenu() {
        if (openContextMenu) {
            openContextMenu.remove();
            openContextMenu = null;
        }
        document.removeEventListener('click', closeContextMenuOnOutside);
    }

    function closeContextMenuOnOutside(e) {
        if (openContextMenu && !openContextMenu.contains(e.target)) {
            closeContextMenu();
        }
    }

    // ========================================================================
    // Step 5: Delete Route
    // ========================================================================

    async function deleteRoute(route, hasChildren) {
        const childCount = hasChildren ? countDescendantsForRoute(route) : 0;
        const message = hasChildren
            ? t('deleteConfirmChildren', 'Delete route and children?').replace('{route}', route).replace('{count}', childCount)
            : t('deleteConfirm', 'Delete route?').replace('{route}', route);

        if (!confirm(message)) return;

        try {
            const body = { route };
            if (hasChildren) body.force = true;

            const result = await QuickSiteAdmin.apiRequest('deleteRoute', 'POST', body);
            if (result.ok) {
                saveExpandedState();
                expandedNodes.delete(route);
                QuickSiteAdmin.showToast(t('routeDeleted', 'Route deleted'), 'success');
                await refreshAll();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Delete failed', 'error');
            }
        } catch (err) {
            QuickSiteAdmin.showToast('Error: ' + err.message, 'error');
        }
    }

    function countDescendantsForRoute(routePath) {
        // Walk the sitemapData routes to count children under routePath
        if (!sitemapData?.routes) return 0;
        return sitemapData.routes.filter(r => r.name !== routePath && r.name.startsWith(routePath + '/')).length;
    }

    // ========================================================================
    // Step 6: Layout Toggle
    // ========================================================================

    // ========================================================================
    // State Management
    // ========================================================================

    function saveExpandedState() {
        expandedNodes = new Set();
        document.querySelectorAll('.sitemap__tree-node--open').forEach(node => {
            const path = node.dataset.routePath;
            if (path) expandedNodes.add(path);
        });
    }

    // ========================================================================
    // Data Loading & Full Render
    // ========================================================================

    async function loadSitemapData() {
        const [sitemapResult, reachabilityResult, validationResult] = await Promise.all([
            QuickSiteAdmin.apiRequest('getSiteMap'),
            QuickSiteAdmin.apiRequest('analyzeReachability'),
            QuickSiteAdmin.apiRequest('validateTranslations')
        ]);

        if (!sitemapResult.ok || !sitemapResult.data?.data) {
            throw new Error(sitemapResult.data?.message || t('error'));
        }

        sitemapData = sitemapResult.data.data;
        reachabilityData = reachabilityResult.ok ? reachabilityResult.data?.data : null;

        const validationData = validationResult.ok ? validationResult.data?.data?.validation_results : {};

        return { sitemapData, reachabilityData, validationData };
    }

    function renderAll(data) {
        const { sitemapData: sd, reachabilityData: rd, validationData: vd } = data;

        // Tree
        const treeContainer = document.getElementById('sitemap-tree-container');
        const routes = sd.routes || [];

        if (routes.length === 0) {
            treeContainer.innerHTML = `<div class="admin-empty" style="padding:var(--space-lg);">
                <p>${t('emptyHint')}</p>
            </div>`;
        } else {
            const routeTree = buildRouteTree(routes);
            treeContainer.innerHTML = renderSummary(sd, vd) +
                '<div class="sitemap__routes sitemap__routes--flat sitemap__routes--tree">' +
                renderRouteTree(routeTree) +
                '</div>';
        }

        // Reachability
        const reachContainer = document.getElementById('sitemap-reachability-container');
        reachContainer.innerHTML = renderReachability(rd);
    }

    async function refreshAll() {
        try {
            const data = await loadSitemapData();
            renderAll(data);
        } catch (err) {
            console.error('Sitemap refresh error:', err);
        }
    }

    // ========================================================================
    // Context Action Handler (shared by tree delegation + context menu)
    // ========================================================================

    async function toggleRouteLayout(btn) {
        const route = btn.dataset.route;
        const field = btn.dataset.action === 'toggle-menu' ? 'menu' : 'footer';
        const layouts = sitemapData?.routeLayouts || {};
        const current = layouts[route] || { menu: true, footer: true };
        const newValue = !current[field];

        // Optimistic UI update
        btn.classList.toggle('sitemap-layout-toggle--on', newValue);
        btn.title = field === 'menu'
            ? (newValue ? t('menuOn', 'Menu: visible') : t('menuOff', 'Menu: hidden'))
            : (newValue ? t('footerOn', 'Footer: visible') : t('footerOff', 'Footer: hidden'));

        try {
            const result = await QuickSiteAdmin.apiRequest('setRouteLayout', 'POST', {
                route: route,
                menu: field === 'menu' ? newValue : current.menu,
                footer: field === 'footer' ? newValue : current.footer
            });
            if (result.ok) {
                // Update local data
                if (!sitemapData.routeLayouts) sitemapData.routeLayouts = {};
                sitemapData.routeLayouts[route] = {
                    menu: field === 'menu' ? newValue : current.menu,
                    footer: field === 'footer' ? newValue : current.footer
                };
                // Refresh reachability (menu/footer visibility affects reachable routes)
                const reachResult = await QuickSiteAdmin.apiRequest('analyzeReachability');
                if (reachResult.ok) {
                    reachabilityData = reachResult.data?.data || null;
                    const reachContainer = document.getElementById('sitemap-reachability-container');
                    if (reachContainer) reachContainer.innerHTML = renderReachability(reachabilityData);
                }
            } else {
                // Revert
                btn.classList.toggle('sitemap-layout-toggle--on', !newValue);
                QuickSiteAdmin.showToast(result.data?.message || 'Error', 'error');
            }
        } catch (err) {
            btn.classList.toggle('sitemap-layout-toggle--on', !newValue);
            QuickSiteAdmin.showToast('Error: ' + err.message, 'error');
        }
    }

    function handleContextAction(action, route, element) {
        switch (action) {
            case 'add-child':
                showInlineAddForm(route);
                break;
            case 'view-in-editor':
                window.location.href = QUICKSITE_CONFIG.adminBase + '/preview?target=page-' + encodeURIComponent(route);
                break;
            case 'open-page': {
                const routeData = sitemapData?.routes?.find(r => r.name === route);
                const url = routeData?.urls?.[sitemapData.defaultLang] || routeData?.urls?.['default'] || '/' + route;
                window.open(url, '_blank');
                break;
            }
            case 'delete':
                deleteRoute(route, element?.dataset?.hasChildren === 'true');
                break;
            case 'edit-title':
                openEditTitleModal(route);
                break;
        }
    }

    // ========================================================================
    // Edit page title (modal triggered from context menu)
    // ========================================================================
    // The `page.titles.<route>` translation lives in the per-language
    // translation files. To fix missing/wrong titles without digging into
    // the translation editor, this modal shows ONE input per configured
    // language pre-filled with the current value (empty when missing).
    // Save writes only the languages the user actually changed.

    // Module-scoped cache of full per-language translation trees. Keyed
    // by language code. Populated lazily on first modal open, reused on
    // subsequent opens within the same page session. Invalidated locally
    // after a successful save so re-opening the same route shows the
    // just-written values.
    const _editTitleTranslationsCache = {};

    // DOM refs cached on first call.
    let _editTitleRefs = null;
    function _ensureEditTitleRefs() {
        if (_editTitleRefs) return _editTitleRefs;
        _editTitleRefs = {
            modal:  document.getElementById('sitemap-edit-title-modal'),
            close:  document.getElementById('sitemap-edit-title-close'),
            cancel: document.getElementById('sitemap-edit-title-cancel'),
            save:   document.getElementById('sitemap-edit-title-save'),
            route:  document.getElementById('sitemap-edit-title-route'),
            rows:   document.getElementById('sitemap-edit-title-rows'),
            status: document.getElementById('sitemap-edit-title-status'),
        };
        // Wire one-time listeners.
        if (_editTitleRefs.close)  _editTitleRefs.close.addEventListener('click', closeEditTitleModal);
        if (_editTitleRefs.cancel) _editTitleRefs.cancel.addEventListener('click', closeEditTitleModal);
        if (_editTitleRefs.save)   _editTitleRefs.save.addEventListener('click', submitEditTitleModal);
        if (_editTitleRefs.modal) {
            _editTitleRefs.modal.addEventListener('click', (e) => {
                if (e.target && e.target.classList && e.target.classList.contains('sitemap-edit-title-modal__backdrop')) {
                    closeEditTitleModal();
                }
            });
            // Stop click + mousedown bubbling on the CONTENT card so the
            // event can't reach a higher-up handler somewhere in the
            // admin chrome that triggers a page reload (observed during
            // testing: clicking inputs caused the sitemap to reload
            // before this guard was added). The actual offender isn't
            // identified — see BACKLOG "Mystery admin click handler
            // navigates on clicks inside fixed-position panels". The
            // backdrop sits OUTSIDE the content, so backdrop clicks
            // still close the modal (via the listener above).
            const content = _editTitleRefs.modal.querySelector('.sitemap-edit-title-modal__content');
            if (content) {
                content.addEventListener('click',     (e) => e.stopPropagation());
                content.addEventListener('mousedown', (e) => e.stopPropagation());
            }
        }
        return _editTitleRefs;
    }

    // Walk a nested translation object via dot-notation. Returns the
    // resolved string OR null when any segment is missing or not a
    // string (e.g. a parent that's a branch object).
    function _readDotKey(tree, dotPath) {
        if (!tree || typeof tree !== 'object') return null;
        const parts = dotPath.split('.');
        let cursor = tree;
        for (let i = 0; i < parts.length; i++) {
            if (!cursor || typeof cursor !== 'object') return null;
            cursor = cursor[parts[i]];
        }
        return (typeof cursor === 'string') ? cursor : null;
    }

    async function _fetchLanguageTranslations(lang) {
        if (_editTitleTranslationsCache[lang] !== undefined) {
            return _editTitleTranslationsCache[lang];
        }
        try {
            const res = await QuickSiteAdmin.apiRequest('getTranslation', 'GET', null, [lang]);
            // apiRequest returns {ok, status, data:envelope}; envelope is
            // {status, code, data:{language, translations, file}}. Use the
            // defensive unwrap pattern that other admin code uses.
            const payload = (res && res.data && (res.data.data || res.data)) || {};
            const tree = (payload && typeof payload.translations === 'object') ? payload.translations : {};
            _editTitleTranslationsCache[lang] = tree;
            return tree;
        } catch (err) {
            console.warn('[sitemap] getTranslation failed for', lang, err);
            _editTitleTranslationsCache[lang] = {};
            return {};
        }
    }

    async function openEditTitleModal(route) {
        const refs = _ensureEditTitleRefs();
        if (!refs.modal) {
            console.warn('[sitemap] Edit-title modal markup not found in DOM');
            return;
        }
        const langs = (sitemapData && Array.isArray(sitemapData.languages))
            ? sitemapData.languages
            : (sitemapData && sitemapData.defaultLang ? [sitemapData.defaultLang] : ['en']);
        const titleKey = 'page.titles.' + route;

        // Reset modal UI.
        refs.route.textContent = '"' + route + '"';
        refs.rows.innerHTML = '';
        refs.status.textContent = 'Loading…';
        refs.status.style.color = '';
        refs.save.disabled = true;
        refs.modal.style.display = '';

        // Fetch every language's tree in parallel (cached after first call).
        const trees = await Promise.all(langs.map(_fetchLanguageTranslations));

        // Build one input row per language. Marked --missing when the
        // key resolves to null/empty in that language so the user
        // immediately sees which need filling.
        //
        // createElement throughout: the project's escapeHtml is a
        // text-node escaper (textContent→innerHTML), not an attribute
        // escaper — it does NOT encode `"`, so passing a value
        // containing a double-quote into a `value="${esc(v)}"`
        // template would close the attribute early and corrupt the
        // row markup (the user could end up with phantom links /
        // unexpected click behaviour, e.g. page-reloading clicks).
        // createElement + setAttribute / property assignment defers
        // quoting to the browser, which is always correct.
        refs.rows.textContent = '';
        langs.forEach((lang, idx) => {
            const value = _readDotKey(trees[idx], titleKey);
            const hasValue = (typeof value === 'string' && value.length > 0);
            const row = document.createElement('div');
            row.className = 'sitemap-edit-title-row' + (hasValue ? '' : ' sitemap-edit-title-row--missing');

            const langSpan = document.createElement('span');
            langSpan.className = 'sitemap-edit-title-row__lang';
            langSpan.textContent = lang;

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'admin-input sitemap-edit-title-row__input';
            input.setAttribute('data-lang', lang);
            input.setAttribute('data-original', hasValue ? value : '');
            input.value = hasValue ? value : '';
            input.placeholder = 'Title in ' + lang + '…';

            row.appendChild(langSpan);
            row.appendChild(input);
            refs.rows.appendChild(row);
        });

        refs.status.textContent = '';
        refs.save.disabled = false;
        // Focus the first input for fast keyboard editing.
        const firstInput = refs.rows.querySelector('input');
        if (firstInput) firstInput.focus();
    }

    function closeEditTitleModal() {
        if (_editTitleRefs && _editTitleRefs.modal) {
            _editTitleRefs.modal.style.display = 'none';
        }
    }

    async function submitEditTitleModal() {
        const refs = _ensureEditTitleRefs();
        if (!refs.modal) return;
        const inputs = refs.rows.querySelectorAll('input.sitemap-edit-title-row__input');
        if (inputs.length === 0) return;

        // Read which route the modal was opened on from the displayed id
        // (no separate state variable needed — the modal is single-instance
        // and the route shows as a literal in the header).
        const routeTxt = refs.route.textContent || '';
        const route = routeTxt.replace(/^"|"$/g, '');
        if (!route) {
            refs.status.textContent = 'Could not determine target route — close and try again.';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
            return;
        }

        // Gather changes: per-language new value WHEN the input differs
        // from its `data-original`. Untouched languages are NOT written
        // (keeps the translation files clean).
        const changes = [];
        inputs.forEach(input => {
            const lang = input.getAttribute('data-lang');
            const original = input.getAttribute('data-original') || '';
            const value = input.value;
            if (value !== original) changes.push({ lang, value });
        });
        if (changes.length === 0) {
            refs.status.textContent = 'No changes to save.';
            refs.status.style.color = '';
            return;
        }

        refs.save.disabled = true;
        refs.status.textContent = 'Saving (' + changes.length + ' language' + (changes.length === 1 ? '' : 's') + ')…';
        refs.status.style.color = '';

        // Build the nested translation tree for `page.titles.<route>`
        // once per language and dispatch setTranslationKeys in parallel.
        const titleKey = 'page.titles.' + route;
        const parts = titleKey.split('.');
        const promises = changes.map(({ lang, value }) => {
            // Build a fresh nested object per call (don't share refs).
            const translations = {};
            let cursor = translations;
            for (let i = 0; i < parts.length - 1; i++) {
                cursor[parts[i]] = {};
                cursor = cursor[parts[i]];
            }
            cursor[parts[parts.length - 1]] = value;
            return QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', {
                language: lang,
                translations,
            });
        });

        try {
            const results = await Promise.all(promises);
            const failures = results.filter(r => !(r && r.ok));
            if (failures.length > 0) {
                refs.status.textContent = 'Saved ' + (results.length - failures.length) + '/' + results.length + ' — see console.';
                refs.status.style.color = 'var(--admin-warning, #b3691e)';
                console.warn('[sitemap] Some edit-title saves failed:', failures);
                refs.save.disabled = false;
                return;
            }
            // Success — invalidate the cache for changed langs so a
            // subsequent re-open of the same route shows fresh values.
            changes.forEach(({ lang }) => { delete _editTitleTranslationsCache[lang]; });
            refs.status.textContent = '✓ Saved ' + changes.length + ' translation' + (changes.length === 1 ? '' : 's') + '.';
            refs.status.style.color = 'var(--admin-success, #2c7a3f)';
            // Close after a short pause so the user sees the confirmation.
            setTimeout(closeEditTitleModal, 700);
        } catch (err) {
            console.error('[sitemap] edit-title save failed:', err);
            refs.status.textContent = 'Save failed — see console.';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
            refs.save.disabled = false;
        }
    }

    // ========================================================================
    // Event Delegation
    // ========================================================================

    function bindEvents() {
        const treeContainer = document.getElementById('sitemap-tree-container');

        // Tree expand/collapse + action buttons
        treeContainer.addEventListener('click', (e) => {
            const toggle = e.target.closest('.sitemap__tree-toggle');
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                const nodeEl = toggle.closest('.sitemap__tree-node');
                if (nodeEl) nodeEl.classList.toggle('sitemap__tree-node--open');
                return;
            }

            // Add child button
            const addBtn = e.target.closest('[data-action="add-child"]');
            if (addBtn) {
                e.preventDefault();
                e.stopPropagation();
                closeContextMenu();
                showInlineAddForm(addBtn.dataset.route);
                return;
            }

            // Context menu button
            const ctxBtn = e.target.closest('[data-action="context-menu"]');
            if (ctxBtn) {
                e.preventDefault();
                e.stopPropagation();
                showContextMenu(ctxBtn);
                return;
            }

            // Layout toggle buttons (menu/footer)
            const layoutBtn = e.target.closest('[data-action="toggle-menu"], [data-action="toggle-footer"]');
            if (layoutBtn) {
                e.preventDefault();
                e.stopPropagation();
                toggleRouteLayout(layoutBtn);
                return;
            }
        });

        // Add root route button
        document.getElementById('add-root-route-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            closeContextMenu();
            showInlineAddForm(null);
        });

        // Sitemap.txt generator
        document.getElementById('sitemap-generate-btn')?.addEventListener('click', previewSitemap);
        document.getElementById('sitemap-save-btn')?.addEventListener('click', saveSitemapFile);

        // Delegate clicks inside the URL list (exclude/include toggles)
        document.getElementById('sitemap-url-list')?.addEventListener('click', (e) => {
            const row = e.target.closest('[data-sitemap-route]');
            if (!row) return;
            e.preventDefault();
            const route = row.dataset.sitemapRoute;
            row.classList.toggle('sitemap-url--excluded');
            updateUrlCount();
        });
    }

    // ========================================================================
    // Sitemap.txt Generator (preview → save flow)
    // ========================================================================

    let sitemapPreviewData = null; // stores last preview data for save

    function updateUrlCount() {
        const countEl = document.getElementById('sitemap-url-count');
        if (!countEl) return;
        const included = document.querySelectorAll('#sitemap-url-list [data-sitemap-route]:not(.sitemap-url--excluded)').length;
        const customText = (document.getElementById('sitemap-custom-urls')?.value || '').trim();
        const customCount = customText ? customText.split('\n').filter(l => l.trim()).length : 0;
        const total = included + customCount;
        countEl.textContent = total + ' ' + (total === 1 ? 'URL' : 'URLs');
    }

    async function previewSitemap() {
        const baseUrlInput = document.getElementById('sitemap-base-url');
        const preview = document.getElementById('sitemap-preview');
        const urlList = document.getElementById('sitemap-url-list');
        const customUrlsArea = document.getElementById('sitemap-custom-urls');
        const btn = document.getElementById('sitemap-generate-btn');

        const baseUrl = baseUrlInput?.value?.trim() || '';
        if (!baseUrl) {
            QuickSiteAdmin.showToast(t('baseUrlRequired', 'Please enter a base URL'), 'error');
            baseUrlInput?.focus();
            return;
        }

        btn.disabled = true;
        try {
            // Fetch with no exclusions applied (we get all routes + stored config)
            const result = await QuickSiteAdmin.apiRequest('getSiteMap', 'POST', { baseUrl });

            if (!result.ok || !result.data?.data) {
                QuickSiteAdmin.showToast(result.data?.message || 'Error', 'error');
                return;
            }

            const data = result.data.data;
            sitemapPreviewData = data;
            const config = data.sitemapConfig || { excludedRoutes: [], customUrls: [] };
            const excludedSet = new Set(config.excludedRoutes || []);

            // Render interactive URL list — one row per route with all its URLs
            let html = '';
            const allRoutes = data.routes || [];
            allRoutes.forEach(route => {
                const isExcluded = excludedSet.has(route.name);
                const urls = Object.values(route.urls || {});
                const displayUrl = urls[0] || route.path;
                const extraCount = urls.length > 1 ? ` <span class="sitemap-url__lang-count">×${urls.length} ${t('langs', 'langs')}</span>` : '';
                html += `<div class="sitemap-url${isExcluded ? ' sitemap-url--excluded' : ''}" data-sitemap-route="${esc(route.name)}">
                    <span class="sitemap-url__toggle" title="${t('toggleInclude', 'Toggle include/exclude')}">
                        <svg class="sitemap-url__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
                        <svg class="sitemap-url__cross" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </span>
                    <span class="sitemap-url__text">${esc(displayUrl)}${extraCount}</span>
                </div>`;
            });
            urlList.innerHTML = html;

            // Populate custom URLs textarea from config
            customUrlsArea.value = (config.customUrls || []).join('\n');

            // Listen for textarea changes to update count
            customUrlsArea.oninput = updateUrlCount;

            preview.style.display = '';
            updateUrlCount();
        } catch (err) {
            QuickSiteAdmin.showToast('Error: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    async function saveSitemapFile() {
        const baseUrlInput = document.getElementById('sitemap-base-url');
        const customUrlsArea = document.getElementById('sitemap-custom-urls');
        const btn = document.getElementById('sitemap-save-btn');

        const baseUrl = baseUrlInput?.value?.trim() || '';
        if (!baseUrl) {
            QuickSiteAdmin.showToast(t('baseUrlRequired', 'Please enter a base URL'), 'error');
            return;
        }

        // Collect excluded routes from UI
        const excludedEls = document.querySelectorAll('#sitemap-url-list .sitemap-url--excluded');
        const excludedRoutes = Array.from(excludedEls).map(el => el.dataset.sitemapRoute).filter(Boolean);

        // Collect custom URLs from textarea
        const customUrls = (customUrlsArea?.value || '').split('\n').map(l => l.trim()).filter(l => l);

        btn.disabled = true;
        try {
            // Save config + generate file in one call
            const result = await QuickSiteAdmin.apiRequest('getSiteMap', 'POST', {
                baseUrl,
                save: true,
                saveConfig: { excludedRoutes, customUrls }
            }, ['text']);

            if (result.ok) {
                const data = result.data?.data || {};
                const urlCount = data.urlCount || 0;
                QuickSiteAdmin.showToast(
                    t('sitemapSaved', 'sitemap.txt saved successfully') + ` (${urlCount} URLs)`,
                    'success'
                );
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Error saving sitemap', 'error');
            }
        } catch (err) {
            QuickSiteAdmin.showToast('Error: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    // ========================================================================
    // Init
    // ========================================================================

    async function init() {
        bindEvents();

        try {
            const data = await loadSitemapData();
            renderAll(data);
        } catch (err) {
            const treeContainer = document.getElementById('sitemap-tree-container');
            treeContainer.innerHTML = `<div class="admin-alert admin-alert--error">${esc(err.message)}</div>`;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
