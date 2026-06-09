<?php
/**
 * Sitemap — Configure-resolver modal (beta.8 A2 Slice 7)
 *
 * Opens from the sitemap row's context menu (⋮ → "Configure resolver").
 * Authors the per-route resolver sidecar (route-resolvers.json) entry
 * through a structured form instead of hand-editing JSON or POSTing to
 * setRouteResolver directly.
 *
 * Wired by: public/admin/assets/js/pages/sitemap.js (search for
 * `openResolverModal`). Submits via `setRouteResolver` POST. Clear
 * button (visible only when the route currently has a resolver) POSTs
 * with no resolver param — the command treats absence as the clear
 * signal (per its idempotent set/clear contract).
 *
 * Step 2 scaffolds the modal + endpoint picker. The inputs / expose /
 * cacheTTL / onMiss editors are filled by Steps 3-5 of Slice 7; their
 * placeholder containers (`sitemap-resolver-*-section`) stay stable so
 * each step touches a named slot, not the surrounding markup.
 *
 * Class hierarchy mirrors `sitemap-edit-title-modal__*` — the geometry
 * is the same modal shell. Kept parallel (not consolidated to a shared
 * `.sitemap-modal__*` base) until a third modal makes consolidation
 * worth the cross-cutting refactor. Filed as a beta.9+ polish.
 */
?>
<div class="sitemap-resolver-modal" id="sitemap-resolver-modal" style="display: none;">
    <div class="sitemap-resolver-modal__backdrop"></div>
    <div class="sitemap-resolver-modal__content">
        <div class="sitemap-resolver-modal__header">
            <h3>
                <?= __admin('sitemapPage.resolverTitle', 'Configure resolver') ?>
                <code id="sitemap-resolver-route" style="font-size: 0.9em; color: var(--admin-text-secondary);"></code>
            </h3>
            <button type="button" class="sitemap-resolver-modal__close" id="sitemap-resolver-close" title="<?= __admin('common.close', 'Close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="sitemap-resolver-modal__body">
            <p style="margin: 0 0 var(--space-md); color: var(--admin-text);">
                <?= __admin('sitemapPage.resolverHint', 'Attach a server-side data resolver to this route. The resolver fires before the page renders, fetching from the chosen API endpoint and exposing values as template variables.') ?>
            </p>

            <!-- Endpoint picker (Step 2) — populated on open from listApiEndpoints. -->
            <div class="sitemap-resolver-section">
                <label for="sitemap-resolver-endpoint" class="sitemap-resolver-label">
                    <?= __admin('sitemapPage.resolverEndpoint', 'Endpoint') ?>
                    <span class="sitemap-resolver-label__required" title="<?= __admin('common.required', 'Required') ?>">*</span>
                </label>
                <select id="sitemap-resolver-endpoint" class="admin-input"></select>
                <p class="sitemap-resolver-hint" id="sitemap-resolver-endpoint-meta"></p>
            </div>

            <!-- Future slots — filled by Steps 3-5 of beta.8 A2 Slice 7.
                 Container ids stay stable so each step touches just one slot. -->
            <div class="sitemap-resolver-section" id="sitemap-resolver-inputs-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-expose-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-cache-section"></div>
            <div class="sitemap-resolver-section" id="sitemap-resolver-onmiss-section"></div>

            <p class="admin-hint" id="sitemap-resolver-status" style="margin: var(--space-sm) 0 0; min-height: 1.2em;"></p>
        </div>

        <div class="sitemap-resolver-modal__footer">
            <!-- Clear is shown only when a resolver currently exists on this route.
                 Per the Slice 7 mount discussion: keep Clear inside the modal so the
                 context menu stays uncluttered; the user already opened "Configure"
                 with intent, so "Clear" is a button there. -->
            <button type="button" class="admin-btn admin-btn--danger" id="sitemap-resolver-clear" style="display: none; margin-right: auto;">
                <?= __admin('sitemapPage.resolverClear', 'Clear resolver') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--ghost" id="sitemap-resolver-cancel">
                <?= __admin('common.cancel', 'Cancel') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="sitemap-resolver-save" disabled>
                <?= __admin('common.save', 'Save') ?>
            </button>
        </div>
    </div>
</div>
