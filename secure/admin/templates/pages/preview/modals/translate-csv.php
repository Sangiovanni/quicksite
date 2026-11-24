<?php
/**
 * Translate-from-CSV modal — opens from the select-mode action toolbar
 * when a <table data-qs-complex="table"> is selected. Pastes a TSV/CSV
 * grid in another language → writes values to that language's
 * translation file for every key the table already references.
 *
 * Wired by: public/admin/assets/js/pages/preview/preview.js (search
 * for translateCsvModal). Submits to POST /management/importStructureTranslations.
 */
?>
<div class="preview-translate-csv-modal" id="preview-translate-csv-modal" style="display: none;">
    <div class="preview-translate-csv-modal__backdrop"></div>
    <div class="preview-translate-csv-modal__content">
        <div class="preview-translate-csv-modal__header">
            <h3>
                <?= adminEscape(__admin('preview.translateCsvTitle')) ?>
                <code id="translate-csv-structure-id" style="font-size: 0.9em; color: var(--admin-text-muted);"></code>
            </h3>
            <button type="button" class="admin-modal__close" id="translate-csv-modal-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>

        <div class="preview-translate-csv-modal__body">
            <p class="admin-hint" style="margin-top: 0;">
                <?= adminEscape(__admin('preview.translateCsvHint')) ?>
            </p>

            <div class="admin-form-group">
                <label class="admin-label" for="translate-csv-lang">
                    <?= adminEscape(__admin('preview.translateCsvWriteToLang')) ?>
                    <span>*</span>
                </label>
                <select class="admin-input" id="translate-csv-lang" style="max-width: 200px;">
                    <option value=""><?= adminEscape(__admin('preview.translateCsvLoading')) ?></option>
                </select>
            </div>

            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="translate-csv-has-head" checked>
                    <span><?= adminEscape(__admin('preview.translateCsvIncludeHeader')) ?></span>
                </label>
            </div>

            <p id="translate-csv-expected-dims" style="margin: 0 0 var(--space-sm); font-weight: 600; color: var(--admin-text);">
                -
            </p>

            <div class="admin-form-group">
                <label class="admin-label" for="translate-csv-paste">
                    <?= adminEscape(__admin('preview.translateCsvPaste')) ?>
                    <span>*</span>
                </label>
                <textarea class="admin-input" id="translate-csv-paste" rows="8" placeholder="<?= adminAttr(__admin('preview.translateCsvPlaceholder')) ?>"></textarea>
            </div>

            <p class="admin-hint" id="translate-csv-status" style="margin: 0; min-height: 1.2em;"></p>
        </div>

        <div class="preview-translate-csv-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="translate-csv-cancel">
                <?= adminEscape(__admin('common.cancel')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="translate-csv-apply">
                <?= adminEscape(__admin('preview.translateCsvApply')) ?>
            </button>
        </div>
    </div>
</div>
