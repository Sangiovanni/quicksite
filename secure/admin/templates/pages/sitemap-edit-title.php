<?php
/**
 * Sitemap — Edit page-title modal
 *
 * Opens from the sitemap row's context menu (⋮ → "Edit title"). Shows
 * the `page.titles.<route>` translation for EVERY configured project
 * language as a `<input>` row, so a multi-language site can be fixed
 * in one place. Save writes only the rows the user actually changed.
 *
 * Wired by: public/admin/assets/js/pages/sitemap.js (search for
 * `editTitleModal`). Submits via `setTranslationKeys` per changed lang.
 *
 */
?>
<div class="sitemap-edit-title-modal" id="sitemap-edit-title-modal" style="display: none;">
    <div class="sitemap-edit-title-modal__backdrop"></div>
    <div class="sitemap-edit-title-modal__content">
        <div class="sitemap-edit-title-modal__header">
            <h3>
                <?= adminEscape(__admin('sitemapPage.editTitleTitle')) ?>
                <code id="sitemap-edit-title-route" style="font-size: 0.9em; color: var(--admin-text-muted);"></code>
            </h3>
            <button type="button" class="admin-modal__close" id="sitemap-edit-title-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>

        <div class="sitemap-edit-title-modal__body">
            <p style="margin: 0 0 var(--space-md); color: var(--admin-text);">
                <?= adminEscapeCode(__admin('sitemapPage.editTitleHint')) ?>
            </p>

            <!-- Per-language input rows are added by the JS handler on open. -->
            <div id="sitemap-edit-title-rows"></div>

            <p class="admin-hint" id="sitemap-edit-title-status" style="margin: var(--space-sm) 0 0; min-height: 1.2em;"></p>
        </div>

        <div class="sitemap-edit-title-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="sitemap-edit-title-cancel">
                <?= adminEscape(__admin('common.cancel')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="sitemap-edit-title-save">
                <?= adminEscape(__admin('common.save')) ?>
            </button>
        </div>
    </div>
</div>
