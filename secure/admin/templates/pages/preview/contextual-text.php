<!-- TEXT MODE Content -->
<div class="preview-contextual-section preview-contextual-section--text" id="contextual-text" data-mode="text" style="display: none;">
    <div class="preview-contextual-default" id="contextual-text-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="4 7 4 4 20 4 20 7"/>
            <line x1="9" y1="20" x2="15" y2="20"/>
            <line x1="12" y1="4" x2="12" y2="20"/>
        </svg>
        <span><?= adminEscape(__admin('preview.textModeHint')) ?></span>
    </div>
    <!-- Text-only node actions (shown when a text-only node is selected) -->
    <div class="preview-contextual-info" id="contextual-text-info" style="display: none;">
        <div id="ctx-text-summary">
            <span class="preview-contextual-info__label"><?= adminEscape(__admin('preview.textKey')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
            <code id="ctx-text-key">-</code>
        </div>
        <div class="preview-contextual-info__actions" id="ctx-text-actions">
            <label class="admin-checkbox" id="ctx-text-keep-keys-label" title="<?= adminAttr(__admin('preview.keepTranslationKeysHint')) ?>">
                <input type="checkbox" id="ctx-text-keep-keys">
                <span><?= adminEscape(__admin('preview.keepTranslationKeys')) ?></span>
            </label>
            <!-- Delete: red, its bin and its name as Select's actions are, or its bin alone with the tools' names off
                 (Show names, preview.js), its title the tooltip and its aria-label the name; it asks first -->
            <button type="button" class="admin-btn admin-btn--danger" id="ctx-text-delete" data-qs-icon="trash"
                    title="<?= adminAttr(__admin('preview.deleteTextNode')) ?>" aria-label="<?= adminAttr(__admin('preview.deleteTextNode')) ?>">
                <span><?= adminEscape(__admin('preview.deleteTextNode')) ?></span>
            </button>
        </div>
    </div>
</div>
