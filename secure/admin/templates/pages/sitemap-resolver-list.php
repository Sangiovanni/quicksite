<?php
/**
 * Sitemap — Resolver list-view modal
 *
 * Opens from the sitemap row's context menu (⋮ → "Configure resolver").
 * The list view is the entry point for ALL resolver authoring (single
 * AND multi). The per-config modal (sitemap-resolver.php) is the
 * per-resolver editor REACHED FROM the list view.
 *
 * UX flow:
 *   1. Context menu → list view opens with the route's resolvers (or
 *      empty state if none).
 *   2. A row's pencil → list view closes, per-config modal opens
 *      scoped to that slot's index. Save closes the modal and re-opens
 *      the list view with refreshed data.
 *   3. A row's bin → confirms, deletes that slot, refreshes the
 *      list. Last-resolver removal collapses the route to "no resolver".
 *   4. Drag handle reorder → immediate apply via setRouteResolver
 *      array-replace (no separate Save order button).
 *   5. "+ Add resolver" → list view closes, per-config modal opens in
 *      append mode with index = current array length. Save inserts at
 *      end, re-opens the list view.
 *
 * Backward compat: single-resolver routes (scalar shape on disk) show
 * in the list view with ONE entry. Editing routes through the per-
 * config modal like any other entry, just one extra click through the
 * list view. Removing the only resolver clears the sidecar entry
 * entirely (back to scalar-with-no-route).
 *
 * Wired by: public/admin/assets/js/pages/sitemap.js (search for
 * `openResolverListModal`). Submits via `setRouteResolver` POSTs with
 * the body shapes setRouteResolver defines for multi-resolver routes.
 */
?>
<div class="sitemap-resolver-list-modal" id="sitemap-resolver-list-modal" style="display: none;">
    <div class="sitemap-resolver-list-modal__backdrop"></div>
    <div class="sitemap-resolver-list-modal__content">
        <div class="sitemap-resolver-list-modal__header">
            <h3>
                <?= adminEscape(__admin('sitemapPage.resolverListTitle')) ?>
                <code id="sitemap-resolver-list-route" style="font-size: 0.9em; color: var(--admin-text-muted);"></code>
                <span class="sitemap-resolver-list__count" id="sitemap-resolver-list-count"></span>
            </h3>
            <button type="button" class="admin-modal__close" id="sitemap-resolver-list-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>

        <div class="sitemap-resolver-list-modal__body">
            <p style="margin: 0 0 var(--space-md); color: var(--admin-text);">
                <?= adminEscapeCode(__admin('sitemapPage.resolverListHint')) ?>
            </p>

            <!-- Row container — populated dynamically by the list-view JS.
                 Drag-and-drop reorder runs on the rows directly via native
                 HTML5 draggable + dragover/drop events. -->
            <div id="sitemap-resolver-list-rows" class="sitemap-resolver-list-rows"></div>

            <!-- Empty state shown when the route has no resolvers yet.
                 Toggled by the JS via display: none / ''. The "+ Add
                 resolver" button below still works in this state — it's
                 how the author creates the first resolver. -->
            <p id="sitemap-resolver-list-empty" class="sitemap-resolver-list__empty" style="display: none;">
                <?= adminEscape(__admin('sitemapPage.resolverListEmpty')) ?>
            </p>

            <button type="button" class="sitemap-resolver-list__add admin-btn admin-btn--ghost" id="sitemap-resolver-list-add">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= adminEscape(__admin('sitemapPage.resolverListAdd')) ?>
            </button>

            <p class="admin-hint" id="sitemap-resolver-list-status" style="margin: var(--space-sm) 0 0; min-height: 1.2em;"></p>
        </div>
    </div>
</div>
