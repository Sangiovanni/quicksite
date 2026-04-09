<!-- TEXT MODE Content -->
<div class="preview-contextual-section preview-contextual-section--text" id="contextual-text" data-mode="text" style="display: none;">
    <div class="preview-contextual-default" id="contextual-text-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="4 7 4 4 20 4 20 7"/>
            <line x1="9" y1="20" x2="15" y2="20"/>
            <line x1="12" y1="4" x2="12" y2="20"/>
        </svg>
        <span><?= __admin('preview.textModeHint') ?? 'Click any text in the preview to edit it inline' ?></span>
    </div>
    <!-- Text-only node actions (shown when a text-only node is selected) -->
    <div class="preview-contextual-info" id="contextual-text-info" style="display: none;">
        <div class="preview-contextual-info__summary" id="ctx-text-summary">
            <span class="preview-contextual-info__label"><?= __admin('preview.textKey') ?? 'Text key' ?>:</span>
            <code id="ctx-text-key">-</code>
        </div>
        <div class="preview-contextual-info__actions">
            <label class="admin-checkbox" id="ctx-text-keep-keys-label" title="<?= __admin('preview.keepTranslationKeysHint') ?? 'Keep the translation key in language files after deleting the node' ?>">
                <input type="checkbox" id="ctx-text-keep-keys">
                <span><?= __admin('preview.keepTranslationKeys') ?? 'Keep translation keys' ?></span>
            </label>
            <button type="button" class="admin-btn admin-btn--danger" id="ctx-text-delete" title="<?= __admin('preview.deleteTextNode') ?? 'Delete this text node' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                <span><?= __admin('preview.deleteText') ?? 'Delete Text' ?></span>
            </button>
        </div>
    </div>
</div>
