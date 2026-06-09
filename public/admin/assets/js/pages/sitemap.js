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
     * Beta.8 A2 Slice 7 — also appends a "resolver" badge when the
     * route has a server-side data resolver configured. Presence is
     * passed in by the caller (renderRouteTree) reading
     * `sitemapData.routeResolvers[routeName]` from the getSiteMap
     * response. Sparse map: undefined/null = no resolver, no badge.
     *
     * Example output for '/products/:slug' with a resolver:
     *   <span class="sitemap__route-path">
     *     /products/<span class="sitemap__route-path-segment--param">:slug</span>
     *     <span class="sitemap__route-badge--params">1 param</span>
     *     <span class="sitemap__route-badge--resolver">resolver</span>
     *   </span>
     *
     * @param {string} pathString — e.g. '/products/:slug'
     * @param {object|null} resolverConfig — the route's resolver block,
     *   or null/undefined when no resolver is attached.
     * @returns {HTMLElement}
     */
    function _renderRoutePath(pathString, resolverConfig) {
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

        if (resolverConfig && typeof resolverConfig === 'object') {
            // Beta.8 A2 Slice 7.5.D — handle both scalar AND array shapes.
            // Sidecar entry can be either:
            //   - object  → single resolver, show "resolver" badge (same as
            //               pre-7.5 behaviour)
            //   - array   → N resolvers, show "resolver × N" counter
            const isArrayShape = Array.isArray(resolverConfig);
            const configs = isArrayShape ? resolverConfig : [resolverConfig];
            const count = configs.length;

            const badge = document.createElement('span');
            badge.className = 'sitemap__route-badge--resolver';
            badge.textContent = count === 1
                ? t('resolverBadge', 'resolver')
                : t('resolverBadge', 'resolver') + ' × ' + count;
            // Tooltip surfaces each endpoint id so authors can see "which
            // endpoints does this route fetch?" without opening the list.
            const endpoints = configs
                .map((c) => (c && typeof c.endpoint === 'string') ? c.endpoint : '')
                .filter((e) => e !== '');
            badge.title = endpoints.length > 0
                ? t('resolverBadgeTitle', 'Server-side resolver') + ' — ' + endpoints.join(', ')
                : t('resolverBadgeTitle', 'Server-side resolver');
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
        // Beta.8 A2 Slice 7 — sparse map of routePath → resolver config.
        // Routes without a resolver are absent (not present with a null).
        // _renderRoutePath receives undefined → no resolver badge.
        const resolvers = sitemapData?.routeResolvers || {};

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
                routeSpan.appendChild(_renderRoutePath(route.path, resolvers[routePath]));
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

                leafNode.appendChild(_renderRoutePath(route.path, resolvers[routePath]));
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

        // Beta.8 A2 Slice 7 — Configure-resolver opens the per-route data
        // resolver editor (route-resolvers.json sidecar). Server-rack icon
        // signals "server-side data fetch" — distinct from edit-title's
        // pencil (content) and the layout-toggle hamburger/footer icons.
        menu.appendChild(_ctxMenuItem({
            action: 'configure-resolver', route,
            label: t('configureResolver', 'Configure resolver'),
            iconSvg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
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
            case 'configure-resolver':
                // Beta.8 A2 Slice 7.5.D — always go through the list view.
                // For single-resolver routes the list shows ONE entry +
                // "+ Add resolver"; for multi it shows them all. The
                // per-resolver modal opens FROM the list (Edit / + Add).
                openResolverListModal(route);
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
    // Configure-resolver modal (beta.8 A2 Slice 7)
    // ========================================================================
    // Opens from sitemap context menu (⋮ → "Configure resolver"). Authors
    // the per-route resolver sidecar (route-resolvers.json) through a
    // structured form: endpoint picker + (Steps 3-5) inputs / expose /
    // cacheTTL / onMiss editors. Submits via setRouteResolver — same
    // idempotent set/clear endpoint used by direct callers.
    //
    // Module-scoped state mirrors the edit-title pattern: refs cache,
    // single-instance "current selection," module-level lazy fetch cache
    // for the API endpoints list (rebuilt only when stale enough to
    // matter — page session is the lifetime). The modal is single-
    // instance: only one route's resolver is being edited at a time.

    let _resolverRefs = null;
    let _resolverApiEndpointsCache = null;   // populated by _loadApiEndpointsForResolver
    let _resolverCurrentRoute = null;        // route key the modal is editing
    let _resolverCurrentConfig = null;       // pre-edit snapshot of the resolver block

    // Beta.8 A2 Slice 7 Step 4 — character validation for names.
    // Two different rules because the fields end up in different places:
    //
    //   - Input name: becomes an endpoint param key (URL path / query /
    //     body). Allow hyphens so kebab-case APIs (`api-key`,
    //     `content-type`) work without quoting / encoding gymnastics.
    //
    //   - Expose name: becomes a $<name> PHP template variable. MUST
    //     be a valid PHP identifier — anything else silently breaks the
    //     template at render time. Strict letters/digits/underscore.
    //
    // Both must start with a letter or underscore (no leading digit).
    // Server-side checks the same rules in resolverHelpers.php so direct
    // POST callers don't slip through.
    const RESOLVER_INPUT_NAME_RE  = /^[a-zA-Z_][a-zA-Z0-9_\-]*$/;
    const RESOLVER_EXPOSE_NAME_RE = /^[a-zA-Z_][a-zA-Z0-9_]*$/;

    /**
     * Extract param segment names from a route path. `:name` segments are
     * the only legal source for `param:<name>` input specs — the value
     * picker for kind=param uses this list. Routes with no params (e.g.
     * `home`, `collection`) return [], which disables kind=param in the
     * inputs editor.
     */
    function _extractParamSegments(routePath) {
        if (typeof routePath !== 'string' || routePath === '') return [];
        return routePath.split('/')
            .filter((s) => s.startsWith(':'))
            .map((s) => s.slice(1))
            .filter((s) => s !== '');
    }

    /**
     * Split a source spec into its (kind, value) parts. The on-disk
     * convention (per resolverHelpers.php + BETA8_DATA_RESOLVER.md):
     *   "param:<name>"   → {kind:'param',   value:<name>}
     *   "query:<name>"   → {kind:'query',   value:<name>}
     *   "session:<name>" → {kind:'session', value:<name>}
     *   "<literal>"      → {kind:'literal', value:<literal>}  (bare value)
     *
     * Strings with no recognised prefix fall through to kind='literal'.
     * Non-strings (shouldn't happen — validator rejects them) default
     * to an empty literal so the form can still render the row safely.
     */
    function _parseSourceSpec(spec) {
        if (typeof spec !== 'string') return { kind: 'literal', value: '' };
        const m = spec.match(/^(param|query|session):(.*)$/);
        if (m) return { kind: m[1], value: m[2] };
        return { kind: 'literal', value: spec };
    }

    /** Inverse of _parseSourceSpec — composes a kind + value back into
     *  the on-disk spec string. Used at save time. */
    function _buildSourceSpec(kind, value) {
        if (kind === 'literal') return value;
        return kind + ':' + value;
    }

    /**
     * Walk a JSON-Schema-shaped object and enumerate every reachable
     * dot-path through it. Used by the expose-editor's autocomplete
     * datalist — so authors typing a path see live suggestions shaped
     * like the endpoint's actual response.
     *
     * Conventions match resolverHelpers.php::_generateSampleFromSchemaPath:
     *   - Object types: walk `properties.<name>`
     *   - Array types: walk into `items` (suggests `<key>.items.<sub>`)
     *   - Empty string '' represents the root (whole response) and is
     *     included as a first suggestion
     *
     * Depth-capped to defend against cyclic schemas (rare but possible
     * when authors reference shared definitions). Cap chosen high
     * enough that realistic schemas (3-5 levels deep) all enumerate
     * fully; deeper-than-cap paths can still be typed by hand.
     *
     * @param {object|null} schema  JSON Schema node
     * @param {string} prefix       Current dot-path accumulator
     * @param {number} maxDepth     Remaining recursion budget
     * @param {string[]} acc        Mutated accumulator (function returns it)
     * @returns {string[]} Sorted, deduplicated paths
     */
    function _walkSchemaToDotPaths(schema, prefix = '', maxDepth = 6, acc = null) {
        if (acc === null) acc = [];
        if (!schema || typeof schema !== 'object') return acc;
        if (maxDepth < 0) return acc;

        // Add the current path. The root call adds '' (whole response).
        if (acc.indexOf(prefix) < 0) acc.push(prefix);

        if (schema.type === 'object' && schema.properties && typeof schema.properties === 'object') {
            Object.entries(schema.properties).forEach(([key, propSchema]) => {
                const next = prefix ? prefix + '.' + key : key;
                _walkSchemaToDotPaths(propSchema, next, maxDepth - 1, acc);
            });
        }
        if (schema.type === 'array' && schema.items && typeof schema.items === 'object') {
            const next = prefix ? prefix + '.items' : 'items';
            _walkSchemaToDotPaths(schema.items, next, maxDepth - 1, acc);
        }

        // Top-level call returns sorted unique paths. The empty-string
        // root sits first; the rest sort alphabetically.
        if (prefix === '' && maxDepth === 6) {
            return acc.sort((a, b) => {
                if (a === '') return -1;
                if (b === '') return 1;
                return a.localeCompare(b);
            });
        }
        return acc;
    }

    /**
     * Look up the currently-selected endpoint's declared parameters.
     * Returns [] when no endpoint is picked, the picked endpoint is an
     * orphan, or the endpoint declares no parameters. Used by the
     * inputs editor to drive the NAME picker — symmetric to how
     * _findCurrentEndpointSchema drives the expose PATH picker.
     */
    function _findCurrentEndpointParameters() {
        const refs = _resolverRefs;
        if (!refs || !refs.endpoint) return [];
        const value = refs.endpoint.value;
        if (!value) return [];
        const selected = refs.endpoint.options[refs.endpoint.selectedIndex];
        if (!selected || (selected.dataset && selected.dataset.orphan === 'true')) return [];
        const apiId = selected.dataset && selected.dataset.apiId;
        const epId  = selected.dataset && selected.dataset.endpointId;
        const api = (_resolverApiEndpointsCache || []).find((a) => a.apiId === apiId);
        const ep  = api && api.endpoints ? api.endpoints.find((e) => e.id === epId) : null;
        return (ep && Array.isArray(ep.parameters)) ? ep.parameters : [];
    }

    /**
     * Normalise an auth value (endpoint OR api level) to its string type.
     *
     * The api-endpoints.json schema accepts auth in TWO shapes (legacy
     * from the apis.js fix that persists endpoint auth: 'none' explicit
     * + load missing as 'inherit'):
     *   - string: 'none' / 'apiKey' / 'bearer' / 'cookie' / 'basic' /
     *     'inherit' (special: means "fall through to API-level")
     *   - object: {type: '<one of the above>', ...config}
     *
     * Returns null when the value means "inherit" or is malformed —
     * callers fall through to the parent (API) level.
     *
     * Previously this was inlined as `(x && x.type)` which fails when
     * x is a bare string ('none'.type === undefined), causing endpoints
     * with explicit 'none' auth to silently inherit the API's bearer
     * and show "disabled for bearer" in the cache badge.
     */
    function _normalizeAuthValue(authValue) {
        if (typeof authValue === 'string' && authValue !== '' && authValue !== 'inherit') {
            return authValue;
        }
        if (authValue && typeof authValue === 'object' && typeof authValue.type === 'string' && authValue.type !== '') {
            return authValue.type;
        }
        return null;
    }

    /**
     * Look up the currently-selected endpoint's effective auth type
     * (string: 'none' / 'apiKey' / 'bearer' / 'cookie' / 'basic').
     * Endpoint-level auth wins over API-level auth; missing /
     * 'inherit' fields fall through to API-level, then to 'none' as
     * final fallback. Used by the cacheTTL section to drive the
     * shared-cache-safe badge (locked in BETA8_DATA_RESOLVER.md
     * Slice 4 — TTL is enforced disabled for bearer/cookie/basic).
     */
    function _findCurrentEndpointAuth() {
        const refs = _resolverRefs;
        if (!refs || !refs.endpoint) return null;
        const value = refs.endpoint.value;
        if (!value) return null;
        const selected = refs.endpoint.options[refs.endpoint.selectedIndex];
        if (!selected || (selected.dataset && selected.dataset.orphan === 'true')) return null;
        const apiId = selected.dataset && selected.dataset.apiId;
        const epId  = selected.dataset && selected.dataset.endpointId;
        const api = (_resolverApiEndpointsCache || []).find((a) => a.apiId === apiId);
        const ep  = api && api.endpoints ? api.endpoints.find((e) => e.id === epId) : null;
        if (!ep) return null;
        const epAuth = _normalizeAuthValue(ep.auth);
        if (epAuth !== null) return epAuth;
        const apiAuth = api ? _normalizeAuthValue(api.auth) : null;
        return apiAuth || 'none';
    }

    /**
     * Is this auth type safe to share cache entries across users?
     *
     * 'none' and 'apiKey' — yes (no per-user identity in the request,
     * so two users hitting the same endpoint+inputs share the cached
     * response safely).
     *
     * 'bearer' / 'cookie' / 'basic' — no (per-user credentials;
     * caching would leak one user's data to another). The server-side
     * DataResolver disables caching for these auth types REGARDLESS
     * of cacheTTL — the badge surfaces that runtime rule in the form.
     */
    function _isAuthCacheable(authType) {
        return authType === 'none' || authType === 'apiKey';
    }

    /**
     * Look up the currently-selected endpoint's responseSchema, if any.
     * Returns null when no endpoint is picked, the picked endpoint is
     * an orphan (not in the registry), or the endpoint has no schema
     * declared. The datalist refresh handler uses this to recompute
     * paths whenever the endpoint changes.
     */
    function _findCurrentEndpointSchema() {
        const refs = _resolverRefs;
        if (!refs || !refs.endpoint) return null;
        const value = refs.endpoint.value;
        if (!value) return null;
        const selected = refs.endpoint.options[refs.endpoint.selectedIndex];
        if (!selected || (selected.dataset && selected.dataset.orphan === 'true')) return null;
        const apiId = selected.dataset && selected.dataset.apiId;
        const epId  = selected.dataset && selected.dataset.endpointId;
        const api = (_resolverApiEndpointsCache || []).find((a) => a.apiId === apiId);
        const ep  = api && api.endpoints ? api.endpoints.find((e) => e.id === epId) : null;
        return (ep && ep.responseSchema && typeof ep.responseSchema === 'object') ? ep.responseSchema : null;
    }

    function _ensureResolverRefs() {
        if (_resolverRefs) return _resolverRefs;
        _resolverRefs = {
            modal:        document.getElementById('sitemap-resolver-modal'),
            close:        document.getElementById('sitemap-resolver-close'),
            cancel:       document.getElementById('sitemap-resolver-cancel'),
            save:         document.getElementById('sitemap-resolver-save'),
            clear:        document.getElementById('sitemap-resolver-clear'),
            route:        document.getElementById('sitemap-resolver-route'),
            endpoint:     document.getElementById('sitemap-resolver-endpoint'),
            endpointMeta: document.getElementById('sitemap-resolver-endpoint-meta'),
            status:       document.getElementById('sitemap-resolver-status'),
        };
        if (_resolverRefs.close)  _resolverRefs.close.addEventListener('click', closeResolverModal);
        if (_resolverRefs.cancel) _resolverRefs.cancel.addEventListener('click', closeResolverModal);
        if (_resolverRefs.save)   _resolverRefs.save.addEventListener('click', submitResolverModal);
        if (_resolverRefs.clear)  _resolverRefs.clear.addEventListener('click', clearResolverFromModal);
        if (_resolverRefs.endpoint) {
            _resolverRefs.endpoint.addEventListener('change', _onResolverEndpointChange);
        }
        if (_resolverRefs.modal) {
            _resolverRefs.modal.addEventListener('click', (e) => {
                if (e.target && e.target.classList && e.target.classList.contains('sitemap-resolver-modal__backdrop')) {
                    closeResolverModal();
                }
            });
            // Same admin-chrome-click-eater guard as edit-title — see the
            // BACKLOG "Mystery admin click handler" note in the
            // _ensureEditTitleRefs comment above for context. Stop clicks
            // on the content card from bubbling to the mystery listener
            // that page-reloads the sitemap.
            const content = _resolverRefs.modal.querySelector('.sitemap-resolver-modal__content');
            if (content) {
                content.addEventListener('click',     (e) => e.stopPropagation());
                content.addEventListener('mousedown', (e) => e.stopPropagation());
            }
        }
        return _resolverRefs;
    }

    /**
     * Lazy-fetch the API endpoints list (listApiEndpoints command). Cached
     * for the page session — endpoint configs change rarely enough that
     * a per-page-load cache is fine. Invalidation: refresh the page.
     *
     * Returns the `apis` array shape from listApiEndpoints (each item is
     * `{apiId, name, baseUrl, auth, endpoints: [...]}`), or [] on failure.
     */
    async function _loadApiEndpointsForResolver() {
        if (_resolverApiEndpointsCache) return _resolverApiEndpointsCache;
        try {
            const res = await QuickSiteAdmin.apiRequest('listApiEndpoints');
            const payload = (res && res.data && (res.data.data || res.data)) || {};
            _resolverApiEndpointsCache = Array.isArray(payload.apis) ? payload.apis : [];
        } catch (err) {
            console.warn('[sitemap] listApiEndpoints failed', err);
            _resolverApiEndpointsCache = [];
        }
        return _resolverApiEndpointsCache;
    }

    /**
     * Build the cascading API → endpoint dropdown. One <select> with one
     * <optgroup> per API; values are the canonical `@apiId/endpointId`
     * reference shape consumed by setRouteResolver + DataResolver.
     *
     * Client-only endpoints (callableFrom === 'client') are filtered out
     * because the resolver can't call them — surfacing them would let the
     * author pick something that would fail validation on save.
     *
     * When the route's existing config points at an endpoint that's no
     * longer in the registry (deleted, renamed), an orphan option keeps
     * the picker showing the saved value so the author can see what was
     * there and decide what to do. dataset.orphan flag drives the warning
     * in _onResolverEndpointChange.
     */
    function _populateResolverEndpointPicker(apis, currentEndpointRef) {
        const refs = _ensureResolverRefs();
        if (!refs.endpoint) return;
        refs.endpoint.textContent = '';

        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = t('resolverEndpointPlaceholder', '— Select an endpoint —');
        refs.endpoint.appendChild(blank);

        apis.forEach((api) => {
            const group = document.createElement('optgroup');
            // Use the auth-shape normaliser — `(api.auth && api.auth.type)`
            // breaks when api.auth is a bare string ('bearer'.type ===
            // undefined → silently shows 'none' in the label).
            const authType = _normalizeAuthValue(api.auth) || 'none';
            group.label = api.name + ' (' + authType + ')';
            (api.endpoints || []).forEach((ep) => {
                // callableFrom defaults to 'both' when unset (matches
                // ApiEndpointManager::effectiveCallableFrom's auto-derive
                // for non-secret auth types). We're conservative here:
                // skip only explicit 'client'. 'server'/'both' both go in.
                const callableFrom = ep.callableFrom || (api.auth && api.auth.callableFrom) || 'both';
                if (callableFrom === 'client') return;
                const opt = document.createElement('option');
                opt.value = '@' + api.apiId + '/' + ep.id;
                opt.textContent = ep.name + ' (' + ep.method + ' ' + ep.path + ')';
                opt.dataset.apiId = api.apiId;
                opt.dataset.endpointId = ep.id;
                group.appendChild(opt);
            });
            if (group.children.length > 0) {
                refs.endpoint.appendChild(group);
            }
        });

        if (currentEndpointRef) {
            refs.endpoint.value = currentEndpointRef;
            // If the saved endpoint no longer exists in the registry,
            // setting .value above silently leaves the picker on the
            // empty option. Detect that miss + append an orphan row so
            // the author sees what was previously configured.
            if (refs.endpoint.value !== currentEndpointRef) {
                const orphan = document.createElement('option');
                orphan.value = currentEndpointRef;
                orphan.textContent = '⚠ ' + currentEndpointRef + ' — ' + t('resolverEndpointOrphanShort', 'not in registry');
                orphan.dataset.orphan = 'true';
                refs.endpoint.appendChild(orphan);
                refs.endpoint.value = currentEndpointRef;
            }
        }
        _onResolverEndpointChange();
    }

    /**
     * Run on every endpoint-picker change. Updates the meta hint below
     * the picker (showing METHOD baseUrl+path + auth type) AND gates the
     * Save button — Save is disabled when no endpoint is picked, because
     * setRouteResolver requires it.
     */
    function _onResolverEndpointChange() {
        const refs = _ensureResolverRefs();
        if (!refs.endpoint) return;
        const value = refs.endpoint.value;
        refs.save.disabled = !value;
        if (!value) {
            refs.endpointMeta.textContent = '';
            return;
        }
        const selected = refs.endpoint.options[refs.endpoint.selectedIndex];
        if (selected && selected.dataset && selected.dataset.orphan === 'true') {
            refs.endpointMeta.textContent = t(
                'resolverEndpointOrphan',
                'This endpoint id is not in the current API registry. Re-register it at /admin/apis or pick another endpoint.'
            );
            refs.endpointMeta.style.color = 'var(--admin-warning, #b3691e)';
            return;
        }
        const apiId = selected && selected.dataset ? selected.dataset.apiId : null;
        const epId  = selected && selected.dataset ? selected.dataset.endpointId : null;
        const api = (_resolverApiEndpointsCache || []).find((a) => a.apiId === apiId);
        const ep  = api && api.endpoints ? api.endpoints.find((e) => e.id === epId) : null;
        if (ep) {
            // Use the auth-shape normaliser instead of inline `.type`
            // chaining — same bug as the optgroup label otherwise (bare
            // string auth misreports as 'none').
            const epAuth = _normalizeAuthValue(ep.auth)
                || _normalizeAuthValue(api.auth)
                || 'none';
            refs.endpointMeta.textContent = ep.method + ' ' + (api.baseUrl || '') + ep.path
                + ' · ' + t('resolverEndpointAuth', 'auth') + ': ' + epAuth;
            refs.endpointMeta.style.color = '';
        }

        // Beta.8 A2 Slice 7 Step 4 — refresh every expose row's path
        // cell when the endpoint changes. Different endpoints have
        // different responseSchema → different paths → maybe a
        // different cell type (select ↔ input). Each row's
        // _refreshPathCell hook preserves the current value across the
        // swap and surfaces orphan paths as warning options. Safe
        // no-op when the expose section hasn't been rendered yet (e.g.
        // first call from openResolverModal before
        // _renderResolverExposeSection runs).
        const newSchema = _findCurrentEndpointSchema();
        // Pass raw schema (null when none declared) — see openResolverModal's
        // docblock for why we skip the `|| {}` fallback.
        const newPaths = _walkSchemaToDotPaths(newSchema);
        const exposeWrap = document.getElementById('sitemap-resolver-expose-rows');
        if (exposeWrap) {
            exposeWrap.querySelectorAll('.sitemap-resolver-expose-row').forEach((row) => {
                if (typeof row._refreshPathCell === 'function') {
                    row._refreshPathCell(newPaths);
                }
            });
        }
        // Toggle the no-schema warning hint based on whether the new
        // endpoint declares a schema.
        const noSchemaHint = document.getElementById('sitemap-resolver-expose-no-schema-hint');
        if (noSchemaHint) {
            noSchemaHint.style.display = newPaths.length > 0 ? 'none' : '';
        }

        // Beta.8 A2 Slice 7 Step 4 follow-up — refresh inputs row name
        // fields based on the new endpoint's declared parameters. Same
        // refresh-in-place pattern as the expose path cell, with the
        // same value preservation + orphan-option handling.
        const newParams = _findCurrentEndpointParameters();
        const inputsWrap = document.getElementById('sitemap-resolver-inputs-rows');
        if (inputsWrap) {
            inputsWrap.querySelectorAll('.sitemap-resolver-input-row').forEach((row) => {
                if (typeof row._refreshNameField === 'function') {
                    row._refreshNameField(newParams);
                }
            });
        }
        // Toggle the no-params warning hint.
        const noParamsHint = document.getElementById('sitemap-resolver-inputs-no-params-hint');
        if (noParamsHint) {
            noParamsHint.style.display = newParams.length > 0 ? 'none' : '';
        }

        // Beta.8 A2 Slice 7 Step 5 — refresh the cache badge AND TTL
        // input disabled state based on the new endpoint's auth. The
        // typed value persists across the swap (disabled preserves
        // it) so the author isn't punished for switching to peek at
        // a per-user endpoint.
        _refreshCacheControls(_findCurrentEndpointAuth());
    }

    /**
     * Build one row of the inputs editor — `[name] = [kind] [value] [×]`.
     *
     * The value cell is rebuilt whenever the kind changes:
     *   - kind=param   → <select> of the route's :name segments
     *   - kind=query   → <input type=text> placeholder "query string key"
     *   - kind=session → <input type=text> placeholder "session field"
     *   - kind=literal → <input type=text> placeholder "literal value"
     *
     * Current-value preservation: when the user swaps kind between two
     * free-text kinds (e.g. query→literal), the typed value carries over.
     * Swapping to param resets to the segment dropdown's first option
     * unless the current value happens to match an existing segment.
     *
     * Routes with no :name segments mark kind=param as disabled in the
     * dropdown — the picker is shown but unselectable, which makes the
     * "param: doesn't apply here" state explicit.
     */
    function _renderResolverInputRow(name, sourceSpec, paramSegments, endpointParams) {
        const parsed = _parseSourceSpec(sourceSpec);
        // If the existing spec uses kind=param but the route has no
        // segments (config inherited from a renamed route?), fall back
        // to literal so the row still renders sanely.
        let initialKind = parsed.kind;
        if (initialKind === 'param' && paramSegments.length === 0) initialKind = 'literal';
        const initialValue = parsed.value;

        const row = document.createElement('div');
        row.className = 'sitemap-resolver-input-row';

        // Name cell — rendered as <select> when the endpoint declares
        // parameters, plain <input> otherwise. Symmetric to the expose
        // path cell pattern, with the same orphan handling for saved
        // names that don't match the current endpoint's declared
        // parameters.
        const nameCell = document.createElement('span');
        nameCell.className = 'sitemap-resolver-input-row__name-cell';
        row.appendChild(nameCell);

        function _refreshNameField(params) {
            const oldEl = nameCell.firstElementChild;
            const currentValue = oldEl ? (oldEl.value || '') : (name || '');

            nameCell.textContent = '';
            const hasParams = Array.isArray(params) && params.length > 0;
            let el;
            if (hasParams) {
                el = document.createElement('select');
                el.className = 'admin-input sitemap-resolver-input-row__name';
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = t('resolverInputNamePlaceholder', '— pick a param —');
                el.appendChild(blank);
                params.forEach((p) => {
                    const opt = document.createElement('option');
                    opt.value = p.name || '';
                    // Decorate with type + required flag so authors see
                    // what they're picking. e.g. "id (string) *"
                    const type = (p.type || (p.schema && p.schema.type) || '').toString();
                    const req  = p.required ? ' *' : '';
                    opt.textContent = (p.name || '') + (type ? ' (' + type + ')' : '') + req;
                    el.appendChild(opt);
                });
                el.value = currentValue;
                // Orphan handling — saved name not in declared params.
                if (el.value !== currentValue && currentValue !== '') {
                    const orphan = document.createElement('option');
                    orphan.value = currentValue;
                    orphan.textContent = '⚠ ' + currentValue + ' — '
                        + t('resolverInputNameOrphan', 'not in endpoint params');
                    orphan.dataset.orphan = 'true';
                    el.appendChild(orphan);
                    el.value = currentValue;
                }
            } else {
                el = document.createElement('input');
                el.type = 'text';
                el.className = 'admin-input sitemap-resolver-input-row__name';
                el.placeholder = t('resolverInputName', 'param name');
                el.value = currentValue;
            }

            // Live-clear listener — same logic as before, now lives
            // inside _refreshNameField so it re-binds when the cell
            // type swaps (select <-> input).
            const eventName = (el.tagName === 'SELECT') ? 'change' : 'input';
            el.addEventListener(eventName, () => {
                if ((el.value || '').trim() !== '') {
                    const valueEl = row.querySelector('.sitemap-resolver-input-row__value > *');
                    const valueOk = valueEl && (valueEl.value || '').trim() !== '';
                    if (valueOk) row.classList.remove('sitemap-resolver-input-row--error');
                }
                _maybeClearResolverStatus();
            });
            nameCell.appendChild(el);
        }
        _refreshNameField(endpointParams);
        // Hook for _onResolverEndpointChange to refresh in place when
        // the endpoint (and its declared parameters) changes.
        row._refreshNameField = _refreshNameField;

        const sep = document.createElement('span');
        sep.className = 'sitemap-resolver-input-row__sep';
        sep.textContent = '=';
        row.appendChild(sep);

        // Kind selector.
        const kindSelect = document.createElement('select');
        kindSelect.className = 'admin-input sitemap-resolver-input-row__kind';
        [
            { v: 'param',   l: t('resolverKindParam',   'param:') },
            { v: 'query',   l: t('resolverKindQuery',   'query:') },
            { v: 'session', l: t('resolverKindSession', 'session:') },
            { v: 'literal', l: t('resolverKindLiteral', 'literal') },
        ].forEach(({ v, l }) => {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = l;
            if (v === 'param' && paramSegments.length === 0) {
                opt.disabled = true;
                opt.textContent += ' ' + t('resolverKindParamNoSegments', '(route has no :params)');
            }
            kindSelect.appendChild(opt);
        });
        kindSelect.value = initialKind;
        row.appendChild(kindSelect);

        // Value cell — rebuilt by renderValue() each time kind changes.
        const valueCell = document.createElement('span');
        valueCell.className = 'sitemap-resolver-input-row__value';
        row.appendChild(valueCell);

        function renderValue(currentValue) {
            valueCell.textContent = '';
            const k = kindSelect.value;
            let el;
            if (k === 'param') {
                el = document.createElement('select');
                el.className = 'admin-input';
                paramSegments.forEach((p) => {
                    const opt = document.createElement('option');
                    opt.value = p;
                    opt.textContent = ':' + p;
                    el.appendChild(opt);
                });
                // Preserve value if it matches a known segment; else pick
                // the first segment so the row always has a usable value.
                el.value = paramSegments.includes(currentValue)
                    ? currentValue
                    : (paramSegments[0] || '');
            } else {
                el = document.createElement('input');
                el.type = 'text';
                el.className = 'admin-input';
                el.placeholder =
                    k === 'query'   ? t('resolverValueQuery',   'query string key') :
                    k === 'session' ? t('resolverValueSession', 'session field name') :
                                      t('resolverValueLiteral', 'literal value');
                el.value = currentValue || '';
            }
            valueCell.appendChild(el);

            // Live-clear the row's --error highlight + the status hint
            // when the value becomes non-empty (and the name is also
            // non-empty). 'input' fires on every keystroke for text
            // inputs; 'change' fires when a <select> commits. Both are
            // hooked because renderValue swaps element types.
            const eventName = (el.tagName === 'SELECT') ? 'change' : 'input';
            el.addEventListener(eventName, () => {
                if ((el.value || '').trim() !== '') {
                    const nameEl = row.querySelector('.sitemap-resolver-input-row__name');
                    const nameOk = nameEl && (nameEl.value || '').trim() !== '';
                    if (nameOk) row.classList.remove('sitemap-resolver-input-row--error');
                }
                _maybeClearResolverStatus();
            });
        }
        renderValue(initialValue);

        kindSelect.addEventListener('change', () => {
            // Carry the current typed value across the kind swap so the
            // user doesn't lose work when they tweak the kind dropdown.
            const oldEl = valueCell.firstElementChild;
            const oldValue = oldEl ? (oldEl.value || '') : '';
            renderValue(oldValue);
        });

        // Remove button (× icon).
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'sitemap-resolver-input-row__remove admin-btn admin-btn--ghost admin-btn--sm';
        removeBtn.title = t('resolverRemoveInput', 'Remove input');
        const xIcon = _htmlToEl('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>');
        if (xIcon) removeBtn.appendChild(xIcon);
        removeBtn.addEventListener('click', () => row.remove());
        row.appendChild(removeBtn);

        return row;
    }

    /**
     * Render the entire Inputs section into its placeholder slot. Called
     * by openResolverModal. The section's id (`sitemap-resolver-inputs-
     * section`) was reserved in the modal partial so each step just
     * fills its own slot — no markup shuffling needed.
     */
    function _renderResolverInputsSection(inputs, paramSegments, endpointParams) {
        const section = document.getElementById('sitemap-resolver-inputs-section');
        if (!section) return;
        section.textContent = '';

        const label = document.createElement('label');
        label.className = 'sitemap-resolver-label';
        label.textContent = t('resolverInputs', 'Inputs');
        section.appendChild(label);

        const hint = document.createElement('p');
        hint.className = 'sitemap-resolver-hint';
        hint.textContent = t(
            'resolverInputsHint',
            'Wire endpoint parameters to URL params, query string, session, or literal values. When the endpoint declares parameters, names are picked from a dropdown — otherwise free-text.'
        );
        section.appendChild(hint);

        // Symmetric "endpoint has no declared parameters" warning to
        // the expose section's no-schema hint. Stable id so endpoint
        // change can toggle visibility.
        const noParamsHint = document.createElement('p');
        noParamsHint.id = 'sitemap-resolver-inputs-no-params-hint';
        noParamsHint.className = 'sitemap-resolver-hint sitemap-resolver-hint--warning';
        noParamsHint.textContent = t(
            'resolverInputsNoParamsHint',
            '⚠ This endpoint does not declare any parameters. Names are free-text — typo-prone. Declaring parameters in /admin/apis enables dropdown-driven name selection.'
        );
        noParamsHint.style.display = (endpointParams && endpointParams.length > 0) ? 'none' : '';
        section.appendChild(noParamsHint);

        const rowsWrap = document.createElement('div');
        rowsWrap.id = 'sitemap-resolver-inputs-rows';
        rowsWrap.className = 'sitemap-resolver-rows';
        section.appendChild(rowsWrap);

        const entries = inputs && typeof inputs === 'object' ? Object.entries(inputs) : [];
        entries.forEach(([n, s]) => {
            rowsWrap.appendChild(_renderResolverInputRow(n, s, paramSegments, endpointParams));
        });

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'sitemap-resolver-add admin-btn admin-btn--ghost admin-btn--sm';
        const plus = _htmlToEl('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>');
        if (plus) addBtn.appendChild(plus);
        addBtn.appendChild(document.createTextNode(' ' + t('resolverInputAdd', 'Add input')));
        addBtn.addEventListener('click', () => {
            // New row uses the most up-to-date endpoint parameters so it
            // gets the right cell type immediately.
            const currentParams = _findCurrentEndpointParameters();
            rowsWrap.appendChild(_renderResolverInputRow('', '', paramSegments, currentParams));
        });
        section.appendChild(addBtn);
    }

    /**
     * Clear the modal's inline error status line when no rows in EITHER
     * the inputs editor OR the expose editor still have an --error
     * class. Called from the live-typing listeners on row fields so a
     * fixed row erases its own stale error message without waiting for
     * the next Save.
     *
     * Distinguishing error status from transient status ("Loading…",
     * "Saving…") is by inline color — error status sets the danger
     * colour; loading/saving leave it default. We only clear when the
     * colour is danger so a Saving… status that overlaps with a
     * keystroke doesn't get wiped.
     */
    function _maybeClearResolverStatus() {
        const refs = _resolverRefs;
        if (!refs || !refs.status) return;
        const inputsWrap = document.getElementById('sitemap-resolver-inputs-rows');
        const exposeWrap = document.getElementById('sitemap-resolver-expose-rows');
        if (inputsWrap && inputsWrap.querySelector('.sitemap-resolver-input-row--error')) return;
        if (exposeWrap && exposeWrap.querySelector('.sitemap-resolver-expose-row--error')) return;
        const c = (refs.status.style.color || '');
        const isErrorStatus = c.indexOf('rgb') === 0
            || c.indexOf('var(--admin-danger') >= 0
            || c === 'var(--admin-danger, #b3261e)';
        if (isErrorStatus) {
            refs.status.textContent = '';
            refs.status.style.color = '';
        }
    }

    /**
     * Read the current state of the inputs rows. Returns `{result, errors}`:
     *   - result: `{name: spec}` object, or null when no usable rows exist
     *     (so the caller can omit `inputs` from the saved config — per the
     *     schema, inputs is optional).
     *   - errors: array of `{rowNumber, reason, element}` for invalid
     *     rows. The caller surfaces these inline + focuses the first
     *     errant element; save is blocked until errors clear.
     *
     * Three row-handling rules — all surface as blocking errors so an
     * incomplete row never silently disappears from the saved config:
     *
     *   - Totally-empty row (no name, no value):  reason `empty_row`.
     *     The author either forgot to fill it after clicking + Add, or
     *     means to delete it — make the choice explicit via × button.
     *   - Filled value, empty name:               reason `missing_name`.
     *     Author typed a value but forgot to name it; silent-skip would
     *     hide their work.
     *   - Filled name, empty value:               reason `missing_value`.
     *     No legitimate inputs case wants an empty value (param: dropdown
     *     is auto-picked, query:/session: with empty key is meaningless,
     *     empty literal is ~always a mistake). Inputs-specific rule —
     *     expose (Step 4) has a different policy because empty dot-path
     *     means "expose the whole response" per the design doc.
     *
     * Side effect: rows are visually marked with the --error class as
     * each is evaluated, so the user sees every offender at once.
     */
    function _collectResolverInputs() {
        const wrap = document.getElementById('sitemap-resolver-inputs-rows');
        if (!wrap) return { result: null, errors: [] };
        const out = {};
        const errors = [];

        wrap.querySelectorAll('.sitemap-resolver-input-row').forEach((row, idx) => {
            const nameInput  = row.querySelector('.sitemap-resolver-input-row__name');
            const kindSelect = row.querySelector('.sitemap-resolver-input-row__kind');
            const valueEl    = row.querySelector('.sitemap-resolver-input-row__value > *');
            if (!nameInput || !kindSelect || !valueEl) return;

            // Clear any stale error highlight before re-evaluating.
            row.classList.remove('sitemap-resolver-input-row--error');

            const name  = (nameInput.value || '').trim();
            const value = (valueEl.value   || '').trim();
            const hasName  = name  !== '';
            const hasValue = value !== '';

            if (!hasName && !hasValue) {
                row.classList.add('sitemap-resolver-input-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'empty_row', element: nameInput });
                return;
            }
            if (!hasName) {
                row.classList.add('sitemap-resolver-input-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'missing_name', element: nameInput });
                return;
            }
            if (!RESOLVER_INPUT_NAME_RE.test(name)) {
                row.classList.add('sitemap-resolver-input-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'invalid_input_name_chars', element: nameInput });
                return;
            }
            if (!hasValue) {
                row.classList.add('sitemap-resolver-input-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'missing_value', element: valueEl });
                return;
            }
            out[name] = _buildSourceSpec(kindSelect.value, value);
        });

        return {
            result: Object.keys(out).length > 0 ? out : null,
            errors: errors,
        };
    }

    // ====================================================================
    // Expose editor (beta.8 A2 Slice 7 Step 4)
    // ====================================================================
    // Mirror of inputs editor structurally: `[varName] ← [dotPath] [×]`
    // rows in the #sitemap-resolver-expose-section slot. Two differences
    // worth flagging:
    //
    //   1. Empty dot-path is LEGITIMATE (means "expose the whole
    //      response as varName" per BETA8_DATA_RESOLVER.md). The
    //      filled-name + empty-path case is ACCEPTED, unlike the
    //      inputs editor which blocks empty values.
    //
    //   2. Hybrid schema autocomplete: when the picked endpoint has a
    //      responseSchema, a shared <datalist> seeds the path field
    //      with every dot-path the schema enumerates. The author can
    //      still type custom paths (e.g. dynamic keys, undocumented
    //      fields). Endpoint changes refresh the datalist.

    /**
     * Build one row of the expose editor — `[varName] ← [dotPath] [×]`.
     *
     * The path cell is rendered based on whether the picked endpoint
     * declares a `responseSchema`:
     *   - schemaPaths non-empty → `<select>` populated with the schema's
     *     enumerated paths (clearer UX than datalist's quirky two-click;
     *     also creates discipline pressure for endpoints to declare
     *     schemas — better authoring experience is the reward).
     *   - schemaPaths empty → plain `<input>` (free-text fallback when
     *     no schema declared, e.g. third-party APIs without docs).
     *
     * On endpoint change, the row's `_refreshPathCell(newPaths)` hook is
     * called from _onResolverEndpointChange to re-render the cell. The
     * current value is preserved across the swap; if it's not in the
     * new schema, an orphan option is added with a warning (same
     * pattern as the endpoint picker's orphan handling).
     */
    function _renderResolverExposeRow(varName, dotPath, schemaPaths) {
        const row = document.createElement('div');
        row.className = 'sitemap-resolver-expose-row';

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'admin-input sitemap-resolver-expose-row__name';
        nameInput.placeholder = t('resolverExposeName', 'variable name');
        nameInput.value = varName || '';
        nameInput.addEventListener('input', () => {
            if ((nameInput.value || '').trim() !== '') {
                row.classList.remove('sitemap-resolver-expose-row--error');
            }
            _maybeClearResolverStatus();
        });
        row.appendChild(nameInput);

        const sep = document.createElement('span');
        sep.className = 'sitemap-resolver-expose-row__sep';
        sep.textContent = '←';
        row.appendChild(sep);

        // Path cell — rendered by _refreshPathCell so endpoint-change
        // can re-render in place.
        const pathCell = document.createElement('span');
        pathCell.className = 'sitemap-resolver-expose-row__path-cell';
        row.appendChild(pathCell);

        function _refreshPathCell(paths) {
            // Preserve current value across re-renders so swapping
            // endpoints doesn't drop the author's typed/selected path.
            const oldEl = pathCell.firstElementChild;
            const currentValue = oldEl ? (oldEl.value || '') : (dotPath || '');

            pathCell.textContent = '';
            const hasSchema = Array.isArray(paths) && paths.length > 0;
            let el;
            if (hasSchema) {
                el = document.createElement('select');
                el.className = 'admin-input sitemap-resolver-expose-row__path';
                paths.forEach((p) => {
                    const opt = document.createElement('option');
                    opt.value = p;
                    opt.textContent = (p === '')
                        ? t('resolverExposeWholeResponse', '(whole response)')
                        : p;
                    el.appendChild(opt);
                });
                el.value = currentValue;
                // Orphan handling: if the saved path isn't in the new
                // schema, surface it explicitly so the author sees the
                // mismatch instead of the value silently snapping to
                // the first option.
                if (el.value !== currentValue) {
                    const orphan = document.createElement('option');
                    orphan.value = currentValue;
                    orphan.textContent = '⚠ ' + currentValue + ' — '
                        + t('resolverExposePathOrphan', 'not in schema');
                    orphan.dataset.orphan = 'true';
                    el.appendChild(orphan);
                    el.value = currentValue;
                }
            } else {
                el = document.createElement('input');
                el.type = 'text';
                el.className = 'admin-input sitemap-resolver-expose-row__path';
                el.placeholder = t('resolverExposePath', 'data.field.path (empty = whole response)');
                el.value = currentValue;
            }

            // Listener (same change-clears-error pattern as inputs).
            const eventName = (el.tagName === 'SELECT') ? 'change' : 'input';
            el.addEventListener(eventName, () => {
                if ((nameInput.value || '').trim() !== '') {
                    row.classList.remove('sitemap-resolver-expose-row--error');
                }
                _maybeClearResolverStatus();
            });
            pathCell.appendChild(el);
        }
        _refreshPathCell(schemaPaths);
        // Expose the re-render hook so _onResolverEndpointChange can
        // refresh all rows when the endpoint changes (and therefore
        // the schema paths change).
        row._refreshPathCell = _refreshPathCell;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'sitemap-resolver-expose-row__remove admin-btn admin-btn--ghost admin-btn--sm';
        removeBtn.title = t('resolverRemoveExpose', 'Remove expose');
        const xIcon = _htmlToEl('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>');
        if (xIcon) removeBtn.appendChild(xIcon);
        removeBtn.addEventListener('click', () => row.remove());
        row.appendChild(removeBtn);

        return row;
    }

    /**
     * Render the entire Expose section into its placeholder slot. Called
     * by openResolverModal AFTER the endpoint picker is populated so
     * the per-row path cell reflects the current endpoint's schema.
     *
     * When schemaPaths is non-empty, rows use a <select> seeded with
     * the enumerated paths. When schemaPaths is empty (endpoint has
     * no responseSchema declared), rows fall back to plain <input>.
     * Endpoint change → _onResolverEndpointChange calls each row's
     * `_refreshPathCell(newPaths)` hook to swap the cell type in place.
     */
    function _renderResolverExposeSection(expose, schemaPaths) {
        const section = document.getElementById('sitemap-resolver-expose-section');
        if (!section) return;
        section.textContent = '';

        const label = document.createElement('label');
        label.className = 'sitemap-resolver-label';
        label.textContent = t('resolverExpose', 'Expose');
        section.appendChild(label);

        const hint = document.createElement('p');
        hint.className = 'sitemap-resolver-hint';
        hint.textContent = t(
            'resolverExposeHint',
            'Map response fields to template variables. Empty path means "expose the whole response as this variable." When the endpoint declares a response schema, paths are picked from a dropdown — otherwise free-text.'
        );
        section.appendChild(hint);

        // No-schema warning. Always rendered (stable id) so endpoint
        // change can toggle visibility instead of re-rendering the
        // section. Visible when schemaPaths is empty.
        const noSchemaHint = document.createElement('p');
        noSchemaHint.id = 'sitemap-resolver-expose-no-schema-hint';
        noSchemaHint.className = 'sitemap-resolver-hint sitemap-resolver-hint--warning';
        noSchemaHint.textContent = t(
            'resolverExposeNoSchemaHint',
            '⚠ This endpoint does not declare a response schema. Paths are free-text — typo-prone, no autocomplete. Adding a schema in /admin/apis enables dropdown-driven path selection.'
        );
        noSchemaHint.style.display = (schemaPaths && schemaPaths.length > 0) ? 'none' : '';
        section.appendChild(noSchemaHint);

        const rowsWrap = document.createElement('div');
        rowsWrap.id = 'sitemap-resolver-expose-rows';
        rowsWrap.className = 'sitemap-resolver-rows';
        section.appendChild(rowsWrap);

        const entries = expose && typeof expose === 'object' ? Object.entries(expose) : [];
        entries.forEach(([n, p]) => {
            rowsWrap.appendChild(_renderResolverExposeRow(n, p, schemaPaths));
        });

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'sitemap-resolver-add admin-btn admin-btn--ghost admin-btn--sm';
        const plus = _htmlToEl('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>');
        if (plus) addBtn.appendChild(plus);
        addBtn.appendChild(document.createTextNode(' ' + t('resolverExposeAdd', 'Add expose')));
        addBtn.addEventListener('click', () => {
            // New row uses the most up-to-date schema paths so it gets
            // the right cell type immediately. Null schema → [] paths →
            // input fallback (see openResolverModal docblock).
            const currentPaths = _walkSchemaToDotPaths(_findCurrentEndpointSchema());
            rowsWrap.appendChild(_renderResolverExposeRow('', '', currentPaths));
        });
        section.appendChild(addBtn);
    }

    /**
     * Read the current state of the expose rows. Returns `{result, errors}`
     * with the same shape as _collectResolverInputs.
     *
     * Two row-handling rules — different from inputs because expose's
     * empty-value case is legitimate:
     *
     *   - Totally-empty row (no name, no path):  reason `empty_row`.
     *     Same as inputs — author either forgot or wants to delete.
     *   - Empty name + filled path:              reason `missing_name`.
     *     Author typed a path but forgot to name it.
     *   - Filled name + empty path:              ACCEPT.
     *     Empty path = whole response per the design doc. Common case
     *     for "give me the whole API response as $data".
     */
    function _collectResolverExpose() {
        const wrap = document.getElementById('sitemap-resolver-expose-rows');
        if (!wrap) return { result: null, errors: [] };
        const out = {};
        const errors = [];

        wrap.querySelectorAll('.sitemap-resolver-expose-row').forEach((row, idx) => {
            const nameInput = row.querySelector('.sitemap-resolver-expose-row__name');
            const pathInput = row.querySelector('.sitemap-resolver-expose-row__path');
            if (!nameInput || !pathInput) return;

            row.classList.remove('sitemap-resolver-expose-row--error');

            const name = (nameInput.value || '').trim();
            const path = (pathInput.value || '').trim();
            const hasName = name !== '';
            const hasPath = path !== '';

            if (!hasName && !hasPath) {
                row.classList.add('sitemap-resolver-expose-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'empty_row', element: nameInput });
                return;
            }
            if (!hasName) {
                row.classList.add('sitemap-resolver-expose-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'missing_name', element: nameInput });
                return;
            }
            if (!RESOLVER_EXPOSE_NAME_RE.test(name)) {
                row.classList.add('sitemap-resolver-expose-row--error');
                errors.push({ rowNumber: idx + 1, reason: 'invalid_expose_name_chars', element: nameInput });
                return;
            }
            // Filled name + any path (including empty) — accept.
            out[name] = path;
        });

        return {
            result: Object.keys(out).length > 0 ? out : null,
            errors: errors,
        };
    }

    // ====================================================================
    // Cache TTL section (beta.8 A2 Slice 7 Step 5)
    // ====================================================================
    // Number input (seconds) + shared-cache-safe badge. The badge is a
    // read-only surface for the server-side rule: caching is force-
    // disabled for bearer/cookie/basic auth REGARDLESS of TTL (locked
    // in BETA8_DATA_RESOLVER.md Slice 4 — would leak per-user data
    // across users via shared cache).

    /**
     * Refresh both the auth-cacheable badge AND the TTL input's
     * disabled state. The input is disabled when caching isn't allowed
     * — the value would otherwise be silently ignored by the server,
     * misleading the author. Disabled preserves the typed value (so
     * swapping back to a cacheable endpoint restores it).
     *
     * Both reads from the DOM (so _onResolverEndpointChange can call
     * this without re-rendering the whole section).
     */
    function _refreshCacheControls(authType) {
        const badge = document.getElementById('sitemap-resolver-cache-badge');
        const input = document.getElementById('sitemap-resolver-cache-ttl');
        const at = authType || 'none';
        const cacheable = _isAuthCacheable(at);
        if (badge) {
            badge.classList.remove('sitemap-resolver-cache-badge--ok', 'sitemap-resolver-cache-badge--blocked');
            if (cacheable) {
                badge.classList.add('sitemap-resolver-cache-badge--ok');
                badge.textContent = t('resolverCacheCacheable', 'cacheable');
                badge.title = t('resolverCacheCacheableTitle', 'Auth: ' + at + ' — shared-safe, server honours cacheTTL.');
            } else {
                badge.classList.add('sitemap-resolver-cache-badge--blocked');
                badge.textContent = t('resolverCacheBlocked', 'disabled for ' + at);
                badge.title = t('resolverCacheBlockedTitle', 'Per-user auth (' + at + ') cannot share cache entries between users — server forces no-cache regardless of TTL.');
            }
        }
        if (input) {
            input.disabled = !cacheable;
        }
    }

    function _renderResolverCacheSection(cacheTTL, authType) {
        const section = document.getElementById('sitemap-resolver-cache-section');
        if (!section) return;
        section.textContent = '';

        const label = document.createElement('label');
        label.className = 'sitemap-resolver-label';
        label.setAttribute('for', 'sitemap-resolver-cache-ttl');
        label.textContent = t('resolverCacheTTL', 'Cache TTL');
        section.appendChild(label);

        const row = document.createElement('div');
        row.className = 'sitemap-resolver-cache-row';

        const input = document.createElement('input');
        input.type = 'number';
        input.id = 'sitemap-resolver-cache-ttl';
        input.className = 'admin-input sitemap-resolver-cache-input';
        input.min = '0';
        input.step = '1';
        input.placeholder = '0';
        input.value = (typeof cacheTTL === 'number' && cacheTTL >= 0) ? String(cacheTTL) : '';
        // Live-clear listener so a stale "invalid cache TTL" error
        // hint clears as soon as the user types a valid number.
        input.addEventListener('input', () => {
            _maybeClearResolverStatus();
        });
        row.appendChild(input);

        const unit = document.createElement('span');
        unit.className = 'sitemap-resolver-cache-unit';
        unit.textContent = t('resolverCacheTTLUnit', 'seconds');
        row.appendChild(unit);

        const badge = document.createElement('span');
        badge.id = 'sitemap-resolver-cache-badge';
        badge.className = 'sitemap-resolver-cache-badge';
        row.appendChild(badge);

        section.appendChild(row);

        // Both the badge text/colour AND the input's disabled state
        // are driven by the auth type. Call _refreshCacheControls AFTER
        // the row is in the document so the getElementById lookups
        // resolve to the freshly-appended nodes.
        _refreshCacheControls(authType);

        const hint = document.createElement('p');
        hint.className = 'sitemap-resolver-hint';
        hint.textContent = t(
            'resolverCacheTTLHint',
            'Server-side cache duration in seconds. 0 (or empty) = no cache, every request fires fresh. Caching is server-force-disabled for bearer / cookie / basic auth (would leak per-user data through shared cache).'
        );
        section.appendChild(hint);
    }

    /**
     * Read the cacheTTL input. Empty / 0 → null (omitted from saved
     * config — semantically identical to "no cache"). Positive integer
     * → number. Anything else → error.
     */
    function _collectResolverCacheTTL() {
        const input = document.getElementById('sitemap-resolver-cache-ttl');
        if (!input) return { result: null, errors: [] };
        const raw = (input.value || '').trim();
        if (raw === '') return { result: null, errors: [] };
        const num = Number(raw);
        if (!Number.isFinite(num) || num < 0 || !Number.isInteger(num)) {
            return {
                result: null,
                errors: [{ reason: 'invalid_cache_ttl', element: input }],
            };
        }
        // 0 → null (omit from config); positive → keep.
        return { result: num > 0 ? num : null, errors: [] };
    }

    // ====================================================================
    // onMiss section (beta.8 A2 Slice 7 Step 5)
    // ====================================================================
    // Single-select for the failure-mode behaviour. Two options now
    // (default + render-empty); future values (redirect:<url> etc.)
    // reserved per BETA8_DATA_RESOLVER.md but not in v1 scope.

    function _renderResolverOnMissSection(onMiss) {
        const section = document.getElementById('sitemap-resolver-onmiss-section');
        if (!section) return;
        section.textContent = '';

        const label = document.createElement('label');
        label.className = 'sitemap-resolver-label';
        label.setAttribute('for', 'sitemap-resolver-onmiss');
        label.textContent = t('resolverOnMiss', 'On miss');
        section.appendChild(label);

        const select = document.createElement('select');
        select.id = 'sitemap-resolver-onmiss';
        select.className = 'admin-input sitemap-resolver-onmiss-select';
        [
            { v: '', l: t('resolverOnMissDefault',     '(default — fail loud: 404 / 500)') },
            { v: 'render-empty', l: t('resolverOnMissRenderEmpty', 'render-empty — expose null vars, render template with empty-state UI') },
        ].forEach(({ v, l }) => {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = l;
            select.appendChild(opt);
        });
        select.value = onMiss || '';
        section.appendChild(select);

        const hint = document.createElement('p');
        hint.className = 'sitemap-resolver-hint';
        hint.textContent = t(
            'resolverOnMissHint',
            'How to respond when the resolver fetch fails or returns non-2xx. Default sends 404 / 500 to the user. render-empty keeps the page rendering with null/empty exposed vars — pair with data-state-show-empty in the template for graceful "no data" UI.'
        );
        section.appendChild(hint);
    }

    /**
     * Read the onMiss select. Empty (default) → null (omitted from
     * saved config). Non-empty value → string. v1 ONLY allows
     * 'render-empty' in the dropdown; future values reserved per the
     * design doc but not surfaced here.
     */
    function _collectResolverOnMiss() {
        const select = document.getElementById('sitemap-resolver-onmiss');
        if (!select) return { result: null, errors: [] };
        const value = (select.value || '').trim();
        return { result: value || null, errors: [] };
    }

    /**
     * Open the per-resolver config modal.
     *
     * Beta.8 A2 Slice 7.5.D — now always invoked from the list view.
     * The optional `options` second arg carries:
     *   - config:    the resolver config to pre-populate from (NEW: pass
     *                explicitly; we no longer read from sitemapData here
     *                because the list view has its own normalised array
     *                snapshot)
     *   - editIndex: which slot of the array this submit will target
     *                (= setRouteResolver `index` param). For new
     *                resolvers via "+ Add resolver", this equals the
     *                current array length (= append).
     *
     * Backward-compat path: when called with no options (legacy
     * single-resolver path), still reads from sitemapData. This branch
     * isn't used by the new context-menu flow but stays for any future
     * callsites that haven't migrated.
     */
    async function openResolverModal(route, options) {
        const refs = _ensureResolverRefs();
        if (!refs.modal) {
            console.warn('[sitemap] Resolver modal markup not found in DOM');
            return;
        }
        options = options || {};

        _resolverCurrentRoute = route;
        // When called from list view, the caller passes the config
        // directly + the index. When called legacy-style, fall back to
        // reading sitemapData (single-resolver shape only).
        if (options.config !== undefined || options.editIndex !== undefined) {
            _resolverCurrentConfig = options.config || null;
        } else {
            const raw = (sitemapData && sitemapData.routeResolvers && sitemapData.routeResolvers[route]) || null;
            _resolverCurrentConfig = Array.isArray(raw) ? (raw[0] || null) : raw;
        }

        refs.route.textContent = '"' + route + '"';
        refs.save.disabled = true;
        // Clear button hidden in list-view mode — per-row × Remove on
        // the list view handles individual removal; the Done button on
        // the list closes everything cleanly.
        if (_resolverEditFromList) {
            refs.clear.style.display = 'none';
        } else {
            refs.clear.style.display = _resolverCurrentConfig ? '' : 'none';
            refs.clear.disabled = false;
        }
        refs.modal.style.display = '';

        refs.status.textContent = t('loading', 'Loading…');
        refs.status.style.color = '';

        const apis = await _loadApiEndpointsForResolver();
        _populateResolverEndpointPicker(apis, (_resolverCurrentConfig && _resolverCurrentConfig.endpoint) || '');

        // Beta.8 A2 Slice 7 Step 3 — render the inputs editor into its
        // placeholder slot. paramSegments comes from the route path
        // (e.g. `user/:id/posts/:postid` → ['id','postid']) and drives
        // the kind=param value picker. endpointParams comes from the
        // selected endpoint's declared parameters and drives the input
        // NAME picker (added in Step 4 follow-up).
        const paramSegments = _extractParamSegments(route);
        const endpointParams = _findCurrentEndpointParameters();
        _renderResolverInputsSection(
            (_resolverCurrentConfig && _resolverCurrentConfig.inputs) || null,
            paramSegments,
            endpointParams
        );

        // Beta.8 A2 Slice 7 Step 4 — render the expose editor into its
        // placeholder slot. Schema-paths come from the picked endpoint's
        // responseSchema. When no schema is declared, the walker is
        // called with null (NOT {} — that'd still produce a single
        // empty-string root path, which would force a select with one
        // useless "whole response" option) → returns []. Row renderer
        // then falls back to plain <input> so authors can type custom
        // paths against undocumented endpoints.
        const schemaPaths = _walkSchemaToDotPaths(_findCurrentEndpointSchema());
        _renderResolverExposeSection(
            (_resolverCurrentConfig && _resolverCurrentConfig.expose) || null,
            schemaPaths
        );

        // Beta.8 A2 Slice 7 Step 5 — render cacheTTL + onMiss sections.
        // After this lands, the modal fully surfaces every resolver
        // config field — the snapshot carry-over in submitResolverModal
        // can drop its remaining cacheTTL/onMiss preservation block.
        const authType = _findCurrentEndpointAuth();
        _renderResolverCacheSection(
            (_resolverCurrentConfig && typeof _resolverCurrentConfig.cacheTTL === 'number')
                ? _resolverCurrentConfig.cacheTTL
                : null,
            authType
        );
        _renderResolverOnMissSection(
            (_resolverCurrentConfig && _resolverCurrentConfig.onMiss) || null
        );

        refs.status.textContent = '';
    }

    /**
     * Close the per-resolver modal. When opened FROM the list view
     * (Cancel / X / backdrop click), returns the user to the list
     * automatically — the per-config modal isn't a destination, it's
     * a sub-screen of the list view.
     *
     * `returnToList`:
     *   - true (default) — used by Cancel / X / backdrop click. Returns
     *     to list view if applicable.
     *   - false — used by submitResolverModal after a successful save,
     *     because that path needs to await refreshAll() before re-
     *     opening the list view (otherwise the list shows stale data).
     */
    function closeResolverModal(returnToList) {
        if (returnToList === undefined) returnToList = true;
        if (_resolverRefs && _resolverRefs.modal) {
            _resolverRefs.modal.style.display = 'none';
        }
        // Stash list-mode handshake before resetting so the re-open
        // logic below sees the original values.
        const fromList = _resolverEditFromList;
        const listRoute = _resolverEditFromListRoute;

        _resolverCurrentRoute = null;
        _resolverCurrentConfig = null;
        _resolverEditFromList = false;
        _resolverEditFromListRoute = null;
        _resolverEditIndex = null;

        if (returnToList && fromList && listRoute) {
            openResolverListModal(listRoute);
        }
    }

    async function submitResolverModal() {
        const refs = _ensureResolverRefs();
        if (!_resolverCurrentRoute) return;
        const endpoint = refs.endpoint.value;
        if (!endpoint) return;

        // Pre-flight: validate ALL form sections before going to the
        // network. inputs + expose use the per-row collectors; cacheTTL
        // / onMiss use single-field collectors. All errors aggregate
        // into one status message + first errant element gets focus.
        const collectedInputs   = _collectResolverInputs();
        const collectedExpose   = _collectResolverExpose();
        const collectedCacheTTL = _collectResolverCacheTTL();
        const collectedOnMiss   = _collectResolverOnMiss();
        const allErrors = [];
        collectedInputs.errors.forEach((e) => allErrors.push(Object.assign({ section: 'input' },  e)));
        collectedExpose.errors.forEach((e) => allErrors.push(Object.assign({ section: 'expose' }, e)));
        collectedCacheTTL.errors.forEach((e) => allErrors.push(Object.assign({ section: 'cache' },  e)));
        collectedOnMiss.errors.forEach((e) => allErrors.push(Object.assign({ section: 'onmiss' }, e)));

        if (allErrors.length > 0) {
            const msgs = allErrors.map((e) => {
                let reasonLabel;
                switch (e.reason) {
                    case 'empty_row':
                        reasonLabel = t('resolverErrEmptyRow', 'row is empty — fill it or × remove it');
                        break;
                    case 'missing_name':
                        reasonLabel = t('resolverErrMissingName', 'name is required');
                        break;
                    case 'missing_value':
                        reasonLabel = t('resolverErrMissingValue', 'value is required');
                        break;
                    case 'invalid_input_name_chars':
                        // Input names allow hyphens (kebab-case for API params).
                        reasonLabel = t('resolverErrInvalidInputNameChars',
                            'name must start with a letter or underscore and use only letters, digits, underscores, or hyphens');
                        break;
                    case 'invalid_expose_name_chars':
                        // Expose names become PHP template vars — strict identifier rule.
                        reasonLabel = t('resolverErrInvalidExposeNameChars',
                            'name must start with a letter or underscore and use only letters, digits, and underscores (becomes a $variable in the template)');
                        break;
                    case 'invalid_cache_ttl':
                        reasonLabel = t('resolverErrInvalidCacheTTL',
                            'cache TTL must be a non-negative integer (seconds), or empty for no cache');
                        break;
                    default:
                        reasonLabel = e.reason;
                }
                // Section label per error origin — row-based sections
                // include the row number; single-field sections (cache /
                // onmiss) skip the row number.
                let sectionLabel;
                let withRow = true;
                switch (e.section) {
                    case 'input':  sectionLabel = t('resolverErrInputRow',  'Input row'); break;
                    case 'expose': sectionLabel = t('resolverErrExposeRow', 'Expose row'); break;
                    case 'cache':  sectionLabel = t('resolverErrCacheField',  'Cache TTL');   withRow = false; break;
                    case 'onmiss': sectionLabel = t('resolverErrOnMissField', 'On miss');     withRow = false; break;
                    default:       sectionLabel = e.section; withRow = false;
                }
                return sectionLabel + (withRow ? ' ' + e.rowNumber : '') + ': ' + reasonLabel;
            });
            refs.status.textContent = msgs.join('; ');
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
            // Focus the first errant element so the user knows where to fix.
            if (allErrors[0] && allErrors[0].element) {
                try { allErrors[0].element.focus(); } catch (e) {}
            }
            return;
        }

        // Step 5 closer — every resolver field now comes from the form,
        // so the snapshot carry-over block drops entirely. Saving an
        // empty cacheTTL / onMiss form-side correctly OMITS the field
        // from the config (which is the schema-correct "default
        // behaviour" signal — see _collectResolverCacheTTL /
        // _collectResolverOnMiss docblocks for the per-field nulling
        // rules).
        const config = { endpoint: endpoint };
        if (collectedInputs.result)   config.inputs   = collectedInputs.result;
        if (collectedExpose.result)   config.expose   = collectedExpose.result;
        if (collectedCacheTTL.result !== null) config.cacheTTL = collectedCacheTTL.result;
        if (collectedOnMiss.result)   config.onMiss   = collectedOnMiss.result;

        refs.save.disabled = true;
        refs.clear.disabled = true;
        refs.status.textContent = t('saving', 'Saving…');
        refs.status.style.color = '';

        try {
            // Beta.8 A2 Slice 7.5.D — when opened from the list view,
            // include `index` in the POST so the command patches/appends
            // that specific slot instead of replacing the whole entry.
            const body = {
                route: _resolverCurrentRoute,
                resolver: config,
            };
            if (_resolverEditFromList && _resolverEditIndex !== null) {
                body.index = _resolverEditIndex;
            }
            const res = await QuickSiteAdmin.apiRequest('setRouteResolver', 'POST', body);
            if (res.ok) {
                QuickSiteAdmin.showToast(t('resolverSaved', 'Resolver saved'), 'success');
                // Stash the route + list-mode flag BEFORE close clears them.
                // Pass false to closeResolverModal so it doesn't auto-
                // return — we need to await refreshAll() first, then
                // re-open the list view with fresh data.
                const fromList = _resolverEditFromList;
                const listRoute = _resolverEditFromListRoute;
                closeResolverModal(false);
                await refreshAll();
                if (fromList && listRoute) {
                    openResolverListModal(listRoute);
                }
            } else {
                // Surface validateResolverConfig errors. setRouteResolver
                // returns {errors: [{field, reason, hint?}]} on 400; show
                // the hint when present (more actionable than reason).
                const errors = (res.data && Array.isArray(res.data.errors)) ? res.data.errors : [];
                const summary = errors.length > 0
                    ? errors.map((e) => e.hint || e.reason || e.field).filter(Boolean).join(' · ')
                    : ((res.data && res.data.message) || 'Save failed');
                refs.status.textContent = summary;
                refs.status.style.color = 'var(--admin-danger, #b3261e)';
                refs.save.disabled = false;
                refs.clear.disabled = false;
            }
        } catch (err) {
            refs.status.textContent = err.message || 'Save failed';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
            refs.save.disabled = false;
            refs.clear.disabled = false;
        }
    }

    async function clearResolverFromModal() {
        const refs = _ensureResolverRefs();
        if (!_resolverCurrentRoute) return;
        if (!confirm(t('resolverClearConfirm', 'Clear the resolver from this route? The route will render again without server-side data.'))) {
            return;
        }

        refs.clear.disabled = true;
        refs.save.disabled = true;
        refs.status.textContent = t('clearing', 'Clearing…');
        refs.status.style.color = '';

        try {
            const res = await QuickSiteAdmin.apiRequest('setRouteResolver', 'POST', {
                route: _resolverCurrentRoute,
                // No `resolver` field — setRouteResolver treats absence as
                // the clear signal (idempotent — safe even when nothing
                // was attached).
            });
            if (res.ok) {
                QuickSiteAdmin.showToast(t('resolverCleared', 'Resolver cleared'), 'success');
                closeResolverModal();
                await refreshAll();
            } else {
                refs.status.textContent = (res.data && res.data.message) || 'Clear failed';
                refs.status.style.color = 'var(--admin-danger, #b3261e)';
                refs.clear.disabled = false;
                refs.save.disabled = !refs.endpoint.value;
            }
        } catch (err) {
            refs.status.textContent = err.message || 'Clear failed';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
            refs.clear.disabled = false;
            refs.save.disabled = !refs.endpoint.value;
        }
    }

    // ========================================================================
    // Resolver list-view modal (beta.8 A2 Slice 7.5.D)
    // ========================================================================
    // The entry point for ALL resolver authoring. Opens from the sitemap
    // context menu (⋮ → "Configure resolver") with a row per resolver
    // configured on the route + a "+ Add resolver" button. Per-row Edit
    // opens the existing per-config modal scoped to that index; × removes
    // a single slot; drag-and-drop reorders.
    //
    // Backward compat: single-resolver routes show with ONE entry. They
    // grow to multi by clicking "+ Add resolver" — no extra setup needed.
    // Removing the only resolver clears the sidecar entry entirely.

    let _resolverListRefs = null;
    let _resolverListCurrentRoute   = null;     // route the list is editing
    let _resolverListCurrentConfigs = [];       // local snapshot (array)
    let _resolverListDragSrcIndex   = null;     // drag-and-drop source idx

    // Cross-talk between list view and per-config modal:
    //   - When per-config modal opens FROM the list, we record both flag
    //     + route so the modal's submit handler can re-open the list with
    //     refreshed data afterwards (instead of closing entirely).
    //   - For ADD flow, editIndex equals current array length (= append).
    //   - For EDIT flow, editIndex is the row's array index.
    let _resolverEditFromList = false;
    let _resolverEditFromListRoute = null;
    let _resolverEditIndex = null;

    function _ensureResolverListRefs() {
        if (_resolverListRefs) return _resolverListRefs;
        _resolverListRefs = {
            modal:  document.getElementById('sitemap-resolver-list-modal'),
            close:  document.getElementById('sitemap-resolver-list-close'),
            done:   document.getElementById('sitemap-resolver-list-done'),
            add:    document.getElementById('sitemap-resolver-list-add'),
            route:  document.getElementById('sitemap-resolver-list-route'),
            count:  document.getElementById('sitemap-resolver-list-count'),
            rows:   document.getElementById('sitemap-resolver-list-rows'),
            empty:  document.getElementById('sitemap-resolver-list-empty'),
            status: document.getElementById('sitemap-resolver-list-status'),
        };
        if (_resolverListRefs.close) _resolverListRefs.close.addEventListener('click', closeResolverListModal);
        if (_resolverListRefs.done)  _resolverListRefs.done.addEventListener('click', closeResolverListModal);
        if (_resolverListRefs.add)   _resolverListRefs.add.addEventListener('click', _handleResolverListAdd);
        if (_resolverListRefs.modal) {
            _resolverListRefs.modal.addEventListener('click', (e) => {
                if (e.target && e.target.classList && e.target.classList.contains('sitemap-resolver-list-modal__backdrop')) {
                    closeResolverListModal();
                }
            });
            // Same admin-chrome-click-eater guard as the other modals.
            const content = _resolverListRefs.modal.querySelector('.sitemap-resolver-list-modal__content');
            if (content) {
                content.addEventListener('click',     (e) => e.stopPropagation());
                content.addEventListener('mousedown', (e) => e.stopPropagation());
            }
        }
        return _resolverListRefs;
    }

    /**
     * Open the list view for a route. Reads the resolver config from
     * sitemapData.routeResolvers[route] — that's already array-aware as
     * of the badge update so scalar / array shapes both land here. We
     * normalise to a local array snapshot so the rest of the list-view
     * code doesn't have to think about the on-disk shape.
     */
    function openResolverListModal(route) {
        const refs = _ensureResolverListRefs();
        if (!refs.modal) {
            console.warn('[sitemap] Resolver list-view modal markup not found in DOM');
            return;
        }

        _resolverListCurrentRoute = route;
        const raw = (sitemapData && sitemapData.routeResolvers && sitemapData.routeResolvers[route]) || null;
        _resolverListCurrentConfigs = Array.isArray(raw)
            ? raw.slice()
            : (raw && typeof raw === 'object' ? [raw] : []);

        refs.route.textContent = '"' + route + '"';
        refs.status.textContent = '';
        refs.status.style.color = '';
        refs.modal.style.display = '';

        _renderResolverListRows();
    }

    function closeResolverListModal() {
        if (_resolverListRefs && _resolverListRefs.modal) {
            _resolverListRefs.modal.style.display = 'none';
        }
        _resolverListCurrentRoute = null;
        _resolverListCurrentConfigs = [];
        _resolverListDragSrcIndex = null;
    }

    /**
     * Render the rows container based on _resolverListCurrentConfigs.
     * Toggles the empty-state hint when there are zero rows. Always
     * shown is the count chip in the header + the "+ Add resolver"
     * button at the bottom.
     */
    function _renderResolverListRows() {
        const refs = _ensureResolverListRefs();
        if (!refs.rows) return;
        refs.rows.textContent = '';

        const configs = _resolverListCurrentConfigs;
        const count = configs.length;

        // Count chip in the header — keeps the single-vs-multi state
        // explicit even when the list itself is short.
        if (refs.count) {
            refs.count.textContent = count === 0
                ? ''
                : '(' + count + ' ' + (count === 1
                    ? t('resolverListCountOne', 'resolver')
                    : t('resolverListCountMany', 'resolvers')) + ')';
        }

        if (count === 0) {
            if (refs.empty) refs.empty.style.display = '';
        } else {
            if (refs.empty) refs.empty.style.display = 'none';
            configs.forEach((cfg, idx) => {
                refs.rows.appendChild(_renderResolverListRow(idx, cfg, count));
            });
        }
    }

    /**
     * Render one row in the list view. Row carries data-index so the
     * delegated handlers + drag events can identify which slot they're
     * operating on without re-walking the DOM.
     */
    function _renderResolverListRow(idx, config, total) {
        const row = document.createElement('div');
        row.className = 'sitemap-resolver-list-row';
        row.setAttribute('draggable', 'true');
        row.dataset.index = String(idx);

        // Drag handle — visual affordance + the row itself is draggable.
        // Showing a handle even on single-row lists is fine; it just
        // means a no-op drag (drop onto itself = no reorder).
        const handle = document.createElement('span');
        handle.className = 'sitemap-resolver-list-row__handle';
        handle.title = t('resolverListReorderHint', 'Drag to reorder');
        handle.textContent = '⋮⋮';
        row.appendChild(handle);

        const indexLabel = document.createElement('span');
        indexLabel.className = 'sitemap-resolver-list-row__index';
        indexLabel.textContent = 'r' + idx;
        row.appendChild(indexLabel);

        const endpointLabel = document.createElement('span');
        endpointLabel.className = 'sitemap-resolver-list-row__endpoint';
        const endpoint = (config && typeof config.endpoint === 'string') ? config.endpoint : '';
        endpointLabel.textContent = endpoint || t('resolverListNoEndpoint', '(no endpoint)');
        if (!endpoint) endpointLabel.style.color = 'var(--admin-warning, #b3691e)';
        row.appendChild(endpointLabel);

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'sitemap-resolver-list-row__edit admin-btn admin-btn--ghost admin-btn--sm';
        editBtn.textContent = t('resolverListEdit', 'Edit');
        editBtn.addEventListener('click', () => _handleResolverListEdit(idx));
        row.appendChild(editBtn);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'sitemap-resolver-list-row__remove admin-btn admin-btn--ghost admin-btn--sm';
        removeBtn.title = t('resolverListRemove', 'Remove this resolver');
        const xIcon = _htmlToEl('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>');
        if (xIcon) removeBtn.appendChild(xIcon);
        removeBtn.addEventListener('click', () => _handleResolverListRemove(idx));
        row.appendChild(removeBtn);

        // Drag-and-drop handlers — native HTML5. Reorder applies
        // immediately on drop (no separate "Save order" button — locked
        // UX decision per Slice 7.5.D kickoff).
        row.addEventListener('dragstart', (e) => {
            _resolverListDragSrcIndex = idx;
            row.classList.add('sitemap-resolver-list-row--dragging');
            // Setting dataTransfer is required by Firefox for the drag
            // to actually fire; the payload itself is unused (we use
            // module state).
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', String(idx)); } catch (_e) {}
            }
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('sitemap-resolver-list-row--dragging');
        });
        row.addEventListener('dragover', (e) => {
            // Calling preventDefault is what marks the row as a valid
            // drop target.
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
            row.classList.add('sitemap-resolver-list-row--drag-target');
        });
        row.addEventListener('dragleave', () => {
            row.classList.remove('sitemap-resolver-list-row--drag-target');
        });
        row.addEventListener('drop', (e) => {
            e.preventDefault();
            row.classList.remove('sitemap-resolver-list-row--drag-target');
            const fromIdx = _resolverListDragSrcIndex;
            const toIdx   = idx;
            _resolverListDragSrcIndex = null;
            if (fromIdx === null || fromIdx === toIdx) return;
            _handleResolverListReorder(fromIdx, toIdx);
        });

        return row;
    }

    /**
     * Edit row N → open per-config modal scoped to that index. Closes
     * the list view first so we don't end up with stacked modals;
     * submitResolverModal re-opens the list with refreshed data on save.
     */
    function _handleResolverListEdit(idx) {
        const route = _resolverListCurrentRoute;
        const config = _resolverListCurrentConfigs[idx] || null;
        if (!route || !config) return;
        _resolverEditFromList = true;
        _resolverEditFromListRoute = route;
        _resolverEditIndex = idx;
        closeResolverListModal();
        openResolverModal(route, { config: config, editIndex: idx });
    }

    /**
     * Add a new resolver → open per-config modal in append mode (index
     * equals current array length, so save lands at end).
     */
    function _handleResolverListAdd() {
        const route = _resolverListCurrentRoute;
        if (!route) return;
        const appendIndex = _resolverListCurrentConfigs.length;
        _resolverEditFromList = true;
        _resolverEditFromListRoute = route;
        _resolverEditIndex = appendIndex;
        closeResolverListModal();
        openResolverModal(route, { config: null, editIndex: appendIndex });
    }

    /**
     * Remove row N → confirm + POST setRouteResolver with the index
     * (no resolver body = remove-at-index per the locked command
     * surface). On success, refresh + re-render the list view in place.
     */
    async function _handleResolverListRemove(idx) {
        const refs = _ensureResolverListRefs();
        const route = _resolverListCurrentRoute;
        if (!route) return;
        const totalBefore = _resolverListCurrentConfigs.length;
        const isLast = totalBefore === 1;
        const confirmMsg = isLast
            ? t('resolverListRemoveLastConfirm', 'Remove the only resolver on this route? The route will render without server-side data.')
            : t('resolverListRemoveConfirm', 'Remove resolver r' + idx + ' from this route?');
        if (!confirm(confirmMsg)) return;

        refs.status.textContent = t('removing', 'Removing…');
        refs.status.style.color = '';

        try {
            const res = await QuickSiteAdmin.apiRequest('setRouteResolver', 'POST', {
                route: route,
                index: idx,
            });
            if (res.ok) {
                QuickSiteAdmin.showToast(t('resolverRemoved', 'Resolver removed'), 'success');
                await refreshAll();
                // Re-open the list view with the refreshed data. If the
                // last resolver was removed, the route now has none —
                // the list view shows the empty state.
                openResolverListModal(route);
            } else {
                refs.status.textContent = (res.data && res.data.message) || 'Remove failed';
                refs.status.style.color = 'var(--admin-danger, #b3261e)';
            }
        } catch (err) {
            refs.status.textContent = err.message || 'Remove failed';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
        }
    }

    /**
     * Reorder via drag-and-drop — swap the snapshot, POST the full
     * array, refresh + re-render. No optimistic update; we wait for the
     * server's confirmation before showing the new order (avoids a
     * brief flicker if the save fails for some reason — e.g. validation
     * rejected the new order because... well, validation doesn't reject
     * order today, but it might in some future cache-collision check).
     */
    async function _handleResolverListReorder(fromIdx, toIdx) {
        const refs = _ensureResolverListRefs();
        const route = _resolverListCurrentRoute;
        if (!route) return;

        // Build the reordered array.
        const next = _resolverListCurrentConfigs.slice();
        const [moved] = next.splice(fromIdx, 1);
        next.splice(toIdx, 0, moved);

        refs.status.textContent = t('reordering', 'Reordering…');
        refs.status.style.color = '';

        try {
            const res = await QuickSiteAdmin.apiRequest('setRouteResolver', 'POST', {
                route: route,
                resolver: next,
            });
            if (res.ok) {
                QuickSiteAdmin.showToast(t('resolverReordered', 'Order updated'), 'success');
                await refreshAll();
                openResolverListModal(route);
            } else {
                refs.status.textContent = (res.data && res.data.message) || 'Reorder failed';
                refs.status.style.color = 'var(--admin-danger, #b3261e)';
            }
        } catch (err) {
            refs.status.textContent = err.message || 'Reorder failed';
            refs.status.style.color = 'var(--admin-danger, #b3261e)';
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
