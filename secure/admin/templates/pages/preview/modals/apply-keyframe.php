<!-- Apply-Keyframe-to-Selector Modal
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
            <button type="button" class="admin-modal__close" id="apply-keyframe-modal-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
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
            <button type="button" class="admin-btn admin-btn--ghost" id="apply-keyframe-modal-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="apply-keyframe-modal-apply" disabled>
                <?= __admin('preview.applyKeyframeAction', 'Apply') ?>
            </button>
        </div>
    </div>
</div>
