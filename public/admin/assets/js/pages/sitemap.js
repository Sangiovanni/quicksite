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
    // DOM render helpers (createElement style — CLAUDE.md HTML-in-JS hygiene)
    // ========================================================================
    //
    // The render functions below build DOM trees via createElement +
    // textContent + setAttribute, returning Elements / DocumentFragments.
    // Trusted SVG snippets from QuickSiteUtils are injected via
    // wrapper.innerHTML and then unwrapped — the CLAUDE.md exemption
    // covers small static icons. No user data flows through innerHTML.

    /**
     * Convert a trusted HTML string (typically a QuickSiteUtils icon helper
     * return value) into a live Element. Pulls out the first child of a
     * staging wrapper so the caller gets the element directly, not the
     * wrapper. Returns null when the input is empty / unparseable.
     */
    function _htmlToEl(html) {
        if (!html) return null;
        const tmp = document.createElement('span');
        tmp.innerHTML = html;
        return tmp.firstElementChild;
    }

    /**
     * Beta.8 A1 — Render the route path with param-segment styling +
     * a trailing param-count badge.
     *
     * Splits the path on '/' and wraps any ':name' segment in a span
     * with the --param accent class. Static (literal) segments stay as
     * text nodes. If any param segments are present, appends a small
     * "<N> param[s]" badge so the user can spot variable routes at a
     * glance.
     *
     * Example output for '/products/:slug':
     *   <span class="sitemap__route-path">
     *     /products/<span class="sitemap__route-path-segment--param">:slug</span>
     *     <span class="sitemap__route-badge--params">1 param</span>
     *   </span>
     *
     * @param {string} pathString — e.g. '/products/:slug'
     * @returns {HTMLElement}
     */
    function _renderRoutePath(pathString) {
        const wrapper = document.createElement('span');
        wrapper.className = 'sitemap__route-path';

        const path = String(pathString || '');
        const segments = path.split('/');
        let paramCount = 0;

        segments.forEach((seg, idx) => {
            // Re-emit the leading slash between segments. segments[0] is
            // empty when path starts with '/' — handled by skipping idx 0.
            if (idx > 0) wrapper.appendChild(document.createTextNode('/'));
            if (seg.startsWith(':')) {
                paramCount++;
                const paramSpan = document.createElement('span');
                paramSpan.className = 'sitemap__route-path-segment--param';
                paramSpan.textContent = seg;
                wrapper.appendChild(paramSpan);
            } else if (seg !== '') {
                wrapper.appendChild(document.createTextNode(seg));
            }
        });

        if (paramCount > 0) {
            const badge = document.createElement('span');
            badge.className = 'sitemap__route-badge--params';
            const labelKey = paramCount === 1 ? 'paramSingular' : 'paramPlural';
            const labelFallback = paramCount === 1 ? 'param' : 'params';
            badge.textContent = paramCount + ' ' + t(labelKey, labelFallback);
            wrapper.appendChild(badge);
        }

        return wrapper;
    }

    /**
     * Render the per-route layout toggles (menu / footer visibility).
     * Each is a button carrying data-action + data-route consumed by the
     * delegated click listener in bindEvents.
     */
    function _renderLayoutToggles(routePath, layout) {
        const wrap = document.createElement('span');
        wrap.className = 'sitemap-layout-toggles';

        const menuBtn = document.createElement('button');
        menuBtn.type = 'button';
        menuBtn.className = 'sitemap-layout-toggle' + (layout.menu ? ' sitemap-layout-toggle--on' : '');
        menuBtn.setAttribute('data-action', 'toggle-menu');
        menuBtn.setAttribute('data-route', routePath);
        menuBtn.title = layout.menu ? t('menuOn', 'Menu: visible') : t('menuOff', 'Menu: hidden');
        menuBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
        wrap.appendChild(menuBtn);

        const footerBtn = document.createElement('button');
        footerBtn.type = 'button';
        footerBtn.className = 'sitemap-layout-toggle' + (layout.footer ? ' sitemap-layout-toggle--on' : '');
        footerBtn.setAttribute('data-action', 'toggle-footer');
        footerBtn.setAttribute('data-route', routePath);
        footerBtn.title = layout.footer ? t('footerOn', 'Footer: visible') : t('footerOff', 'Footer: hidden');
        footerBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="15" x2="21" y2="15"/></svg>';
        wrap.appendChild(footerBtn);

        return wrap;
    }

    /**
     * Render the row-hover action buttons (add-child + kebab context menu).
     */
    function _renderRouteActions(routePath, canAddChild, isHome, hasChildren, routeDepth) {
        const wrap = document.createElement('span');
        wrap.className = 'sitemap-actions';

        if (canAddChild) {
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'sitemap-action sitemap-action--add';
            addBtn.setAttribute('data-action', 'add-child');
            addBtn.setAttribute('data-route', routePath);
            addBtn.title = t('addChild');
            addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            wrap.appendChild(addBtn);
        }

        const ctxBtn = document.createElement('button');
        ctxBtn.type = 'button';
        ctxBtn.className = 'sitemap-action sitemap-action--menu';
        ctxBtn.setAttribute('data-action', 'context-menu');
        ctxBtn.setAttribute('data-route', routePath);
        ctxBtn.setAttribute('data-is-home', String(isHome));
        ctxBtn.setAttribute('data-has-children', String(hasChildren));
        ctxBtn.setAttribute('data-depth', String(routeDepth));
        ctxBtn.title = 'Actions';
        ctxBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
        wrap.appendChild(ctxBtn);

        return wrap;
    }

    // ========================================================================
    // Step 2: Render Route Tree (with action buttons for Steps 4-6)
    // ========================================================================

    /**
     * Render the route tree as a DocumentFragment. Recursive — child trees
     * are produced by sub-calls and grafted into a wrapping container.
     * Caller appends the returned fragment to its target element.
     */
    function renderRouteTree(tree, depth = 0, parentPath = '') {
        const fragment = document.createDocumentFragment();
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
            const layout = layouts[routePath] || { menu: true, footer: true };

            if (hasChildren) {
                const treeNode = document.createElement('div');
                treeNode.className = 'sitemap__tree-node' + (isExpanded ? ' sitemap__tree-node--open' : '');
                treeNode.style.setProperty('--depth', String(depth));
                treeNode.setAttribute('data-route-path', routePath);

                const header = document.createElement('div');
                header.className = 'sitemap__tree-header';

                const toggleBtn = document.createElement('button');
                toggleBtn.className = 'sitemap__tree-toggle';
                toggleBtn.type = 'button';
                toggleBtn.setAttribute('aria-label', 'Toggle');
                toggleBtn.innerHTML = QuickSiteUtils.iconChevronRight(12);
                header.appendChild(toggleBtn);

                const routeSpan = document.createElement('span');
                routeSpan.className = 'sitemap__route sitemap__route--parent';
                const folderIcon = _htmlToEl(QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.folder, 14, 'sitemap__route-icon'));
                if (folderIcon) routeSpan.appendChild(folderIcon);
                const nameSpan = document.createElement('span');
                nameSpan.className = 'sitemap__route-name';
                nameSpan.textContent = name;
                routeSpan.appendChild(nameSpan);
                routeSpan.appendChild(_renderRoutePath(route.path));
                header.appendChild(routeSpan);

                header.appendChild(_renderLayoutToggles(routePath, layout));
                header.appendChild(_renderRouteActions(routePath, canAddChild, isHome, hasChildren, routeDepth));
                treeNode.appendChild(header);

                const childrenWrap = document.createElement('div');
                childrenWrap.className = 'sitemap__tree-children';
                childrenWrap.appendChild(renderRouteTree(node, depth + 1, routePath));
                treeNode.appendChild(childrenWrap);

                fragment.appendChild(treeNode);
            } else {
                const leafNode = document.createElement('div');
                leafNode.className = 'sitemap__route sitemap__route--leaf';
                leafNode.style.setProperty('--depth', String(depth));
                leafNode.setAttribute('data-route-path', routePath);

                const iconPath = isHome ? QuickSiteUtils.ICON_PATHS.home : QuickSiteUtils.ICON_PATHS.file;
                const leafIcon = _htmlToEl(QuickSiteUtils.svgIcon(iconPath, 14, 'sitemap__route-icon'));
                if (leafIcon) leafNode.appendChild(leafIcon);

                const nameSpan = document.createElement('span');
                nameSpan.className = 'sitemap__route-name';
                nameSpan.textContent = name;
                leafNode.appendChild(nameSpan);

                leafNode.appendChild(_renderRoutePath(route.path));
                leafNode.appendChild(_renderLayoutToggles(routePath, layout));
                leafNode.appendChild(_renderRouteActions(routePath, canAddChild, isHome, hasChildren, routeDepth));

                fragment.appendChild(leafNode);
            }
        });

        return fragment;
    }

    // ========================================================================
    // Step 2: Summary bar
    // ========================================================================

    function renderSummary(data, validationData) {
        const routes = data.routes || [];
        const dashSitemap = window.QUICKSITE_CONFIG?.translations?.dashboard?.sitemap || {};

        const summary = document.createElement('div');
        summary.className = 'sitemap__summary';

        const totalSpan = document.createElement('span');
        totalSpan.className = 'sitemap__total';
        totalSpan.textContent = data.totalUrls + ' ' + (dashSitemap.urls || 'URLs');
        summary.appendChild(totalSpan);

        const dividerA = document.createElement('span');
        dividerA.className = 'sitemap__divider';
        dividerA.textContent = '•';
        summary.appendChild(dividerA);

        const routesSpan = document.createElement('span');
        routesSpan.textContent = routes.length + ' ' + (dashSitemap.routes || 'routes');
        summary.appendChild(routesSpan);

        const dividerB = document.createElement('span');
        dividerB.className = 'sitemap__divider';
        dividerB.textContent = '•';
        summary.appendChild(dividerB);

        summary.appendChild(renderCoverage(data, validationData));
        return summary;
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
            const ghost = document.createElement('span');
            ghost.className = 'badge badge--ghost';
            ghost.textContent = languages[0]?.toUpperCase() || 'EN';
            return ghost;
        }

        const sorted = [...languages].sort((a, b) => {
            if (a === defaultLang) return -1;
            if (b === defaultLang) return 1;
            return a.localeCompare(b);
        });

        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex; gap:var(--space-sm); flex-wrap:wrap; align-items:center;';

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

            const badge = document.createElement('span');
            badge.className = 'sitemap-lang-badge' + (coverageClass ? ' ' + coverageClass : '');
            badge.title = tooltip;

            const flag = document.createElement('span');
            flag.className = 'sitemap-lang-badge__flag';
            flag.textContent = getFlagEmoji(lang);
            badge.appendChild(flag);

            const code = document.createElement('span');
            code.className = 'sitemap-lang-badge__code';
            code.textContent = lang.toUpperCase();
            badge.appendChild(code);

            if (isDefault) {
                const star = document.createElement('span');
                star.className = 'sitemap-lang-badge__default';
                star.textContent = '*';
                badge.appendChild(star);
            }

            if (coveragePercent !== null) {
                const pct = document.createElement('span');
                pct.className = 'sitemap-lang-badge__pct';
                pct.textContent = coveragePercent + '%';
                badge.appendChild(pct);
            }

            wrap.appendChild(badge);
        });

        return wrap;
    }

    // ========================================================================
    // Step 3: Reachability Graph
    // ========================================================================

    /**
     * Build the 3-column stats strip (total / reachable / orphans).
     */
    function _renderReachabilityStats(data) {
        const { total_routes, reachable_count, orphan_count } = data;
        const stats = document.createElement('div');
        stats.style.cssText = 'display:flex; gap:var(--space-lg); flex-wrap:wrap; margin-bottom:var(--space-lg);';

        function statBlock(value, label, color) {
            const block = document.createElement('div');
            block.style.textAlign = 'center';
            const val = document.createElement('div');
            val.style.cssText = 'font-size:var(--font-size-2xl); font-weight:var(--font-weight-bold);' + (color ? ' color:' + color + ';' : '');
            val.textContent = String(value);
            const lbl = document.createElement('div');
            lbl.style.cssText = 'font-size:var(--font-size-sm); color:var(--admin-text-muted);';
            lbl.textContent = label;
            block.appendChild(val);
            block.appendChild(lbl);
            return block;
        }

        stats.appendChild(statBlock(total_routes, t('totalRoutes', 'Total Routes')));
        stats.appendChild(statBlock(reachable_count, t('reachable', 'Reachable'), 'var(--admin-success)'));
        stats.appendChild(statBlock(orphan_count, t('orphans', 'Orphans'),
            orphan_count > 0 ? 'var(--admin-error)' : 'var(--admin-text-muted)'));
        return stats;
    }

    /**
     * Orphan-routes section. Returns null when there are no orphans so the
     * caller can skip the divider visually.
     */
    function _renderReachabilityOrphans(orphans) {
        if (!orphans || orphans.length === 0) return null;
        const section = document.createElement('div');
        section.style.marginBottom = 'var(--space-lg)';

        const h3 = document.createElement('h3');
        h3.style.cssText = 'margin-bottom:var(--space-sm); color:var(--admin-error);';
        h3.textContent = t('unreachableRoutes', 'Unreachable Routes');
        section.appendChild(h3);

        const list = document.createElement('div');
        list.style.cssText = 'display:flex; gap:var(--space-xs); flex-wrap:wrap;';
        orphans.forEach(r => {
            const badge = document.createElement('span');
            badge.className = 'admin-badge admin-badge--error';
            badge.textContent = '/' + r;
            list.appendChild(badge);
        });
        section.appendChild(list);

        const hint = document.createElement('p');
        hint.style.cssText = 'font-size:var(--font-size-sm); color:var(--admin-text-muted); margin-top:var(--space-xs);';
        hint.textContent = t('unreachableHint');
        section.appendChild(hint);
        return section;
    }

    /**
     * Global menu / footer link rows.
     */
    function _renderReachabilityGlobalNav(globalLinks) {
        const section = document.createElement('div');
        section.style.marginBottom = 'var(--space-lg)';

        const h3 = document.createElement('h3');
        h3.style.marginBottom = 'var(--space-sm)';
        h3.textContent = t('globalNav', 'Global Navigation Links');
        section.appendChild(h3);

        function navRow(label, links, isLast) {
            const row = document.createElement('div');
            if (!isLast) row.style.marginBottom = 'var(--space-xs)';
            const strong = document.createElement('strong');
            strong.textContent = label + ':';
            row.appendChild(strong);
            row.appendChild(document.createTextNode(' '));
            if (links && links.length > 0) {
                links.forEach((r, i) => {
                    const badge = document.createElement('span');
                    badge.className = 'admin-badge admin-badge--info';
                    badge.textContent = '/' + r;
                    row.appendChild(badge);
                    if (i < links.length - 1) row.appendChild(document.createTextNode(' '));
                });
            } else {
                const em = document.createElement('em');
                em.textContent = t('none', 'none');
                row.appendChild(em);
            }
            return row;
        }

        section.appendChild(navRow(t('menu', 'Menu'), globalLinks?.menu, false));
        section.appendChild(navRow(t('footer', 'Footer'), globalLinks?.footer, true));
        return section;
    }

    /**
     * Link-graph table: each route → its outgoing internal links.
     */
    function _renderReachabilityLinkGraph(graph, orphans) {
        const section = document.createElement('div');

        const h3 = document.createElement('h3');
        h3.style.marginBottom = 'var(--space-sm)';
        h3.textContent = t('linkGraph', 'Link Graph');
        section.appendChild(h3);

        const desc = document.createElement('p');
        desc.style.cssText = 'font-size:var(--font-size-sm); color:var(--admin-text-muted); margin-bottom:var(--space-sm);';
        desc.textContent = t('linkGraphDesc');
        section.appendChild(desc);

        const table = document.createElement('table');
        table.className = 'admin-table';
        table.style.width = '100%';

        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        const th1 = document.createElement('th');
        th1.textContent = t('route', 'Route');
        headerRow.appendChild(th1);
        headerRow.appendChild(document.createElement('th'));
        const th3 = document.createElement('th');
        th3.textContent = t('linksTo', 'Links To');
        headerRow.appendChild(th3);
        thead.appendChild(headerRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        const sortedRoutes = Object.keys(graph || {}).sort();
        sortedRoutes.forEach(route => {
            const targets = (graph[route] || []).sort();
            const isOrphan = orphans && orphans.includes(route);
            const row = document.createElement('tr');

            const td1 = document.createElement('td');
            const routeBadge = document.createElement('span');
            routeBadge.className = 'admin-badge ' + (isOrphan ? 'admin-badge--error' : 'admin-badge--success');
            routeBadge.textContent = '/' + route;
            td1.appendChild(routeBadge);
            row.appendChild(td1);

            const td2 = document.createElement('td');
            td2.style.fontSize = 'var(--font-size-sm)';
            td2.textContent = '→';
            row.appendChild(td2);

            const td3 = document.createElement('td');
            if (targets.length > 0) {
                targets.forEach((target, idx) => {
                    const cls = (orphans && orphans.includes(target)) ? 'admin-badge--error' : 'admin-badge--muted';
                    const badge = document.createElement('span');
                    badge.className = 'admin-badge admin-badge--small ' + cls;
                    badge.textContent = '/' + target;
                    td3.appendChild(badge);
                    if (idx < targets.length - 1) td3.appendChild(document.createTextNode(' '));
                });
            } else {
                const em = document.createElement('em');
                em.style.color = 'var(--admin-text-muted)';
                em.textContent = t('noLinks', 'no links');
                td3.appendChild(em);
            }
            row.appendChild(td3);
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        section.appendChild(table);
        return section;
    }

    /**
     * Top-level reachability render. Returns a DocumentFragment so the
     * caller can swap it into the section via replaceChildren.
     */
    function renderReachability(data) {
        if (!data) {
            const p = document.createElement('p');
            p.style.color = 'var(--admin-text-muted)';
            p.textContent = 'Could not load reachability data.';
            return p;
        }

        const fragment = document.createDocumentFragment();
        const { orphan_count, orphans, graph, global_links } = data;
        const allGood = orphan_count === 0;

        // Status banner
        const banner = document.createElement('div');
        banner.className = 'admin-alert ' + (allGood ? 'admin-alert--success' : 'admin-alert--warning');
        banner.style.marginBottom = 'var(--space-lg)';
        const bannerIconHtml = allGood
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        const bannerIcon = _htmlToEl(bannerIconHtml);
        if (bannerIcon) banner.appendChild(bannerIcon);
        const bannerStrong = document.createElement('strong');
        bannerStrong.textContent = allGood
            ? t('allReachable')
            : orphan_count + ' ' + t('orphansFound', 'orphan route(s) found').replace('{count}', orphan_count);
        banner.appendChild(bannerStrong);
        fragment.appendChild(banner);

        fragment.appendChild(_renderReachabilityStats(data));
        const orphansEl = _renderReachabilityOrphans(orphans);
        if (orphansEl) fragment.appendChild(orphansEl);
        fragment.appendChild(_renderReachabilityGlobalNav(global_links));
        fragment.appendChild(_renderReachabilityLinkGraph(graph, orphans));

        return fragment;
    }

    // ========================================================================
    // Step 4: Inline Add Route Form
    // ========================================================================

    function showInlineAddForm(parentRoute) {
        // Remove any existing inline form
        hideInlineAddForm();

        const isRoot = !parentRoute;
        const label = isRoot ? t('newRoute', 'New route') : `${t('newChildOf', 'New child of')} "${parentRoute}"`;

        const form = document.createElement('div');
        form.className = 'sitemap-inline-form';
        form.id = 'sitemap-add-form';

        const labelSpan = document.createElement('span');
        labelSpan.className = 'sitemap-inline-form__label';
        labelSpan.textContent = label + ':';
        form.appendChild(labelSpan);

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'admin-input admin-input--sm';
        nameInput.id = 'sitemap-new-route-name';
        nameInput.placeholder = isRoot
            ? t('routeNameNested', 'route-name or parent/child')
            : t('routeName', 'route-name');
        nameInput.setAttribute('pattern', '[a-z0-9\\-/]+');
        nameInput.autofocus = true;
        form.appendChild(nameInput);

        const createBtn = document.createElement('button');
        createBtn.type = 'button';
        createBtn.className = 'admin-btn admin-btn--primary admin-btn--sm';
        createBtn.id = 'sitemap-create-btn';
        createBtn.textContent = t('create', 'Create');
        form.appendChild(createBtn);

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'admin-btn admin-btn--ghost admin-btn--sm';
        cancelBtn.id = 'sitemap-cancel-btn';
        cancelBtn.textContent = t('cancel', 'Cancel');
        form.appendChild(cancelBtn);

        const errorSpan = document.createElement('span');
        errorSpan.className = 'sitemap-inline-form__error';
        errorSpan.id = 'sitemap-add-error';
        form.appendChild(errorSpan);

        if (isRoot) {
            // Insert at top of tree container
            const treeContainer = document.getElementById('sitemap-tree-container');
            treeContainer.prepend(form);
        } else {
            // Insert after the route's tree header or leaf element
            const routeEl = document.querySelector(`[data-route-path="${CSS.escape(parentRoute)}"]`);
            if (routeEl) {
                const header = routeEl.querySelector('.sitemap__tree-header') || routeEl;
                header.after(form);
            }
        }

        nameInput.focus();

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
            createBtn.textContent = '';
            const spinnerEl = _htmlToEl(QuickSiteUtils.htmlSpinner());
            if (spinnerEl) createBtn.appendChild(spinnerEl);
            createBtn.appendChild(document.createTextNode(' ' + t('create')));

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

    /**
     * Build one context-menu button. iconSvg is a trusted static SVG
     * string (from QuickSiteUtils.* OR inline). data-ctx-action +
     * data-route attrs feed the delegated click handler below.
     */
    function _ctxMenuItem({ action, route, iconSvg, label, danger, disabled, hasChildren }) {
        const btn = document.createElement('button');
        btn.className = 'sitemap-ctx__item'
            + (danger ? ' sitemap-ctx__item--danger' : '')
            + (disabled ? ' sitemap-ctx__item--disabled' : '');
        if (action) btn.setAttribute('data-ctx-action', action);
        if (route !== undefined) btn.setAttribute('data-route', route);
        if (hasChildren !== undefined) btn.setAttribute('data-has-children', String(hasChildren));
        if (disabled) btn.disabled = true;
        const iconEl = _htmlToEl(iconSvg);
        if (iconEl) btn.appendChild(iconEl);
        btn.appendChild(document.createTextNode(' ' + label));
        return btn;
    }

    // Trash icon used by both the delete + disabled-home items.
    const _CTX_TRASH_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

    function showContextMenu(button) {
        closeContextMenu();

        const route = button.dataset.route;
        const isHome = button.dataset.isHome === 'true';
        const hasChildren = button.dataset.hasChildren === 'true';
        const depth = parseInt(button.dataset.depth || '0');
        const canAddChild = depth < 4 && !isHome;

        const menu = document.createElement('div');
        menu.className = 'sitemap-ctx';

        if (canAddChild) {
            menu.appendChild(_ctxMenuItem({
                action: 'add-child', route,
                label: t('addChild', 'Add child'),
                iconSvg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
            }));
        }

        menu.appendChild(_ctxMenuItem({
            action: 'view-in-editor', route,
            label: t('viewInEditor', 'View in editor'),
            iconSvg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        }));

        menu.appendChild(_ctxMenuItem({
            action: 'open-page', route,
            label: t('openPage', 'Open page'),
            iconSvg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        }));

        menu.appendChild(_ctxMenuItem({
            action: 'edit-title', route,
            label: t('editTitle', 'Edit title'),
            iconSvg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        }));

        const divider = document.createElement('div');
        divider.className = 'sitemap-ctx__divider';
        menu.appendChild(divider);

        if (!isHome) {
            menu.appendChild(_ctxMenuItem({
                action: 'delete', route, hasChildren,
                label: t('deleteRoute', 'Delete route'),
                danger: true, iconSvg: _CTX_TRASH_SVG,
            }));
        } else {
            menu.appendChild(_ctxMenuItem({
                disabled: true,
                label: t('cannotDeleteHome', 'Cannot delete home'),
                iconSvg: _CTX_TRASH_SVG,
            }));
        }

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
            const empty = document.createElement('div');
            empty.className = 'admin-empty';
            empty.style.padding = 'var(--space-lg)';
            const p = document.createElement('p');
            p.textContent = t('emptyHint');
            empty.appendChild(p);
            treeContainer.replaceChildren(empty);
        } else {
            const routeTree = buildRouteTree(routes);
            const routesWrap = document.createElement('div');
            routesWrap.className = 'sitemap__routes sitemap__routes--flat sitemap__routes--tree';
            routesWrap.appendChild(renderRouteTree(routeTree));
            treeContainer.replaceChildren(renderSummary(sd, vd), routesWrap);
        }

        // Reachability
        const reachContainer = document.getElementById('sitemap-reachability-container');
        reachContainer.replaceChildren(renderReachability(rd));
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
                    if (reachContainer) reachContainer.replaceChildren(renderReachability(reachabilityData));
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

            // Render interactive URL list — one row per route with all its URLs.
            // Inline SVGs preserved here (not via QuickSiteUtils.iconCheck) because
            // they carry specific classes (sitemap-url__check / __cross) for the
            // CSS-driven toggle reveal AND the check uses stroke-width 2.5 for
            // visual emphasis (the QuickSiteUtils helpers hardcode 2).
            const urlsFragment = document.createDocumentFragment();
            const allRoutes = data.routes || [];
            const checkSvgHtml = '<svg class="sitemap-url__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>';
            const crossSvgHtml = '<svg class="sitemap-url__cross" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            allRoutes.forEach(route => {
                const isExcluded = excludedSet.has(route.name);
                const urls = Object.values(route.urls || {});
                const displayUrl = urls[0] || route.path;

                const row = document.createElement('div');
                row.className = 'sitemap-url' + (isExcluded ? ' sitemap-url--excluded' : '');
                row.setAttribute('data-sitemap-route', route.name);

                const toggle = document.createElement('span');
                toggle.className = 'sitemap-url__toggle';
                toggle.title = t('toggleInclude', 'Toggle include/exclude');
                const checkEl = _htmlToEl(checkSvgHtml);
                const crossEl = _htmlToEl(crossSvgHtml);
                if (checkEl) toggle.appendChild(checkEl);
                if (crossEl) toggle.appendChild(crossEl);
                row.appendChild(toggle);

                const text = document.createElement('span');
                text.className = 'sitemap-url__text';
                text.textContent = displayUrl;
                if (urls.length > 1) {
                    text.appendChild(document.createTextNode(' '));
                    const langCount = document.createElement('span');
                    langCount.className = 'sitemap-url__lang-count';
                    langCount.textContent = '×' + urls.length + ' ' + t('langs', 'langs');
                    text.appendChild(langCount);
                }
                row.appendChild(text);

                urlsFragment.appendChild(row);
            });
            urlList.replaceChildren(urlsFragment);

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
            const alert = document.createElement('div');
            alert.className = 'admin-alert admin-alert--error';
            alert.textContent = err.message;
            treeContainer.replaceChildren(alert);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
