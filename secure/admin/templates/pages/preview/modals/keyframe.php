<!-- Keyframe Editor Modal -->
<div class="preview-keyframe-modal" id="preview-keyframe-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content">
        <div class="preview-keyframe-modal__header">
            <h3 id="keyframe-modal-title"><?= adminEscape(__admin('preview.editKeyframe')) ?></h3>
            <button type="button" class="admin-modal__close" id="keyframe-modal-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <div class="preview-keyframe-modal__body">
            <!-- Keyframe Name -->
            <div class="preview-keyframe-modal__field">
                <label for="keyframe-name"><?= adminEscape(__admin('preview.keyframeName')) ?> <span>*</span>:</label>
                <input type="text" id="keyframe-name" class="admin-input" placeholder="fadeIn" required>
                <small class="preview-keyframe-modal__hint"><?= adminEscape(__admin('preview.keyframeNameHint')) ?></small>
            </div>
            
            <!-- Timeline -->
            <div class="preview-keyframe-modal__timeline-section">
                <div class="preview-keyframe-modal__timeline-header">
                    <label><?= adminEscape(__admin('preview.keyframeTimeline')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                    <small><?= adminEscape(__admin('preview.clickToAddFrame')) ?></small>
                </div>
                <div class="preview-keyframe-modal__timeline" id="keyframe-timeline">
                    <!-- Timeline markers populated by JS -->
                </div>
            </div>
            
            <!-- Frames Container (each frame's properties) -->
            <div class="preview-keyframe-modal__frames" id="keyframe-frames">
                <!-- Frame editors populated by JS -->
            </div>
        </div>
        <div class="preview-keyframe-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-preview-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                <?= adminEscape(__admin('preview.previewAnimation')) ?>
            </button>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-cancel"><?= adminEscape(__admin('common.cancel')) ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="keyframe-save"><?= adminEscape(__admin('common.save')) ?></button>
            </div>
        </div>
    </div>
</div>
