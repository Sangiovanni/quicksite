<!-- Apply-Keyframe-to-Selector Modal (A3-companion Motion Slice 2)
     Opened from the Keyframes library row's "Apply to selector…" action.
     Lists all selectors (from PreviewSelectorBrowser's cache), filterable
     by substring; on confirm, writes the chosen keyframe's `animation:`
     property to the selected rule via setStyleRule with sensible defaults
     ('<name> 1s ease;' — one-shot, no iteration count). -->
<div class="apply-keyframe-modal" id="apply-keyframe-modal" hidden>
    <div class="apply-keyframe-modal__backdrop" id="apply-keyframe-modal-backdrop"></div>
    <div class="apply-keyframe-modal__dialog" role="dialog" aria-labelledby="apply-keyframe-modal-title">
        <div class="apply-keyframe-modal__header">
            <h3 class="apply-keyframe-modal__title" id="apply-keyframe-modal-title">
                <span><?= __admin('preview.applyKeyframeTitle', 'Apply') ?></span>
                <code id="apply-keyframe-modal-name">@keyframes</code>
                <span><?= __admin('preview.applyKeyframeTitleTo', 'to selector') ?></span>
            </h3>
            <button type="button" class="apply-keyframe-modal__close" id="apply-keyframe-modal-close" title="<?= __admin('common.close') ?? 'Close' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="apply-keyframe-modal__body">
            <input type="text"
                   class="apply-keyframe-modal__search admin-input"
                   id="apply-keyframe-modal-search"
                   placeholder="<?= __admin('preview.searchSelectors', 'Search selectors…') ?>"
                   autocomplete="off"
                   spellcheck="false">
            <div class="apply-keyframe-modal__list" id="apply-keyframe-modal-list" role="listbox">
                <!-- Populated by JS from PreviewSelectorBrowser.getAllSelectors() -->
            </div>
        </div>
        <div class="apply-keyframe-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="apply-keyframe-modal-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="apply-keyframe-modal-apply" disabled>
                <?= __admin('preview.applyKeyframeAction', 'Apply') ?>
            </button>
        </div>
    </div>
</div>
