<?php
/**
 * Sitemap — Configure-resolver modal
 *
 * Opens from the sitemap row's context menu (⋮ → "Configure resolver").
 * Authors the per-route resolver sidecar (route-resolvers.json) entry
 * through a structured form instead of hand-editing JSON or POSTing to
 * setRouteResolver directly.
 *
 * Wired by: public/admin/assets/js/pages/sitemap.js (search for
 * `openResolverModal`). Submits via `setRouteResolver` POST; a resolver is
 * removed by its row's bin in the list view.
 *
 * This partial scaffolds the modal + endpoint picker. sitemap.js renders
 * the inputs / expose / cacheTTL / onMiss editors into their placeholder
 * containers (`sitemap-resolver-*-section`), which stay stable so each
 * editor touches a named slot, not the surrounding markup.
 *
 * Class hierarchy mirrors `sitemap-edit-title-modal__*` — the geometry
 * is the same modal shell. Kept parallel (not consolidated to a shared
 * `.sitemap-modal__*` base).
 */
?>
<div class="sitemap-resolver-modal" id="sitemap-resolver-modal" style="display: none;">
    <div class="sitemap-resolver-modal__backdrop"></div>
    <div class="sitemap-resolver-modal__content">
        <div class="sitemap-resolver-modal__header">
            <h3>
                <?= adminEscape(__admin('sitemapPage.resolverTitle')) ?>
                <code id="sitemap-resolver-route" style="font-size: 0.9em; color: var(--admin-text-muted);"></code>
            </h3>
            <button type="button" class="admin-modal__close" id="sitemap-resolver-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>

        <div class="sitemap-resolver-modal__body">
            <p style="margin: 0 0 var(--space-md); color: var(--admin-text);">
                <?= adminEscape(__admin('sitemapPage.resolverHint')) ?>
            </p>

            <!-- Endpoint picker — populated on open from listApiEndpoints. -->
            <div class="sitemap-resolver-section">
                <label for="sitemap-resolver-endpoint" class="sitemap-resolver-label">
                    <?= adminEscape(__admin('sitemapPage.resolverEndpoint')) ?>
                    <span class="sitemap-resolver-label__required" title="<?= adminAttr(__admin('common.required')) ?>">*</span>
                </label>
                <select id="sitemap-resolver-endpoint" class="admin-input"></select>
                <p class="sitemap-resolver-hint" id="sitemap-resolver-endpoint-meta"></p>
            </div>

            <!-- Editor slots — filled by sitemap.js (inputs / expose / cache / onMiss).
                 Container ids stay stable so each editor touches just one slot. -->
            <div class="sitemap-resolver-section" id="sitemap-resolver-inputs-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-expose-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-cache-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-onmiss-section"></div>

            <p class="admin-hint" id="sitemap-resolver-status" style="margin: var(--space-sm) 0 0; min-height: 1.2em;"></p>
        </div>

        <div class="sitemap-resolver-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="sitemap-resolver-cancel">
                <?= adminEscape(__admin('common.cancel')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="sitemap-resolver-save" disabled>
                <?= adminEscape(__admin('common.save')) ?>
            </button>
        </div>
    </div>
</div>
