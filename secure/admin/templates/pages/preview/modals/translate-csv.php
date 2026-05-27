<?php
/**
 * Translate-from-CSV modal — opens from the select-mode action toolbar
 * when a <table data-qs-complex="table"> is selected. Pastes a TSV/CSV
 * grid in another language → writes values to that language's
 * translation file for every key the table already references.
 *
 * Wired by: public/admin/assets/js/pages/preview/preview.js (search
 * for translateCsvModal). Submits to POST /management/importStructureTranslations.
 *
 * Design: BETA7_TABLE_TRANSLATION_CSV.md
 */
?>
<div class="preview-translate-csv-modal" id="preview-translate-csv-modal" style="display: none;">
    <div class="preview-translate-csv-modal__backdrop"></div>
    <div class="preview-translate-csv-modal__content">
        <div class="preview-translate-csv-modal__header">
            <h3>
                <?= __admin('preview.translateCsvTitle', 'Translate from CSV') ?>
                <code id="translate-csv-structure-id" style="font-size: 0.9em; color: var(--admin-text-secondary);"></code>
            </h3>
            <button type="button" class="preview-translate-csv-modal__close" id="translate-csv-modal-close" title="<?= __admin('common.close', 'Close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="preview-translate-csv-modal__body">
            <p class="admin-hint" style="margin-top: 0;">
                <?= __admin('preview.translateCsvHint', 'Paste a TSV / CSV in the target language. The grid must match the existing table\'s dimensions exactly — the backend refuses partial writes. No structural change is made; only translation values are written.') ?>
            </p>

            <div class="admin-form-group">
                <label class="admin-label" for="translate-csv-lang">
                    <?= __admin('preview.translateCsvWriteToLang', 'Write values to language') ?>
                    <span class="admin-text-danger">*</span>
                </label>
                <select class="admin-input" id="translate-csv-lang" style="max-width: 200px;">
                    <option value="">(loading…)</option>
                </select>
            </div>

            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="translate-csv-has-head" checked>
                    <span><?= __admin('preview.translateCsvIncludeHeader', 'Include header row (first pasted line becomes <thead>)') ?></span>
                </label>
            </div>

            <p id="translate-csv-expected-dims" style="margin: 0 0 var(--space-sm); font-weight: 600; color: var(--admin-text);">
                -
            </p>

            <div class="admin-form-group">
                <label class="admin-label" for="translate-csv-paste">
                    <?= __admin('preview.translateCsvPaste', 'Paste TSV / CSV') ?>
                    <span class="admin-text-danger">*</span>
                </label>
                <textarea class="admin-input" id="translate-csv-paste" rows="8" placeholder="<?= __admin('preview.translateCsvPlaceholder', 'Paste TSV (copy from Excel / Sheets) or CSV…') ?>"></textarea>
            </div>

            <p class="admin-hint" id="translate-csv-status" style="margin: 0; min-height: 1.2em;"></p>
        </div>

        <div class="preview-translate-csv-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="translate-csv-cancel">
                <?= __admin('common.cancel', 'Cancel') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="translate-csv-apply">
                <?= __admin('preview.translateCsvApply', 'Apply') ?>
            </button>
        </div>
    </div>
</div>
