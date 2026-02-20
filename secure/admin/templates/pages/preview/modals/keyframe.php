<!-- Keyframe Editor Modal (Phase 9.3) -->
<div class="preview-keyframe-modal" id="preview-keyframe-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content">
        <div class="preview-keyframe-modal__header">
            <h3 id="keyframe-modal-title"><?= __admin('preview.editKeyframe') ?? 'Edit Keyframe' ?></h3>
            <button type="button" class="preview-keyframe-modal__close" id="keyframe-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="preview-keyframe-modal__body">
            <!-- Keyframe Name -->
            <div class="preview-keyframe-modal__field">
                <label for="keyframe-name"><?= __admin('preview.keyframeName') ?? 'Name' ?> <span class="admin-required">*</span>:</label>
                <input type="text" id="keyframe-name" class="admin-input" placeholder="fadeIn" required>
                <small class="preview-keyframe-modal__hint"><?= __admin('preview.keyframeNameHint') ?? 'Letters, numbers, hyphens. Start with letter.' ?></small>
            </div>
            
            <!-- Timeline -->
            <div class="preview-keyframe-modal__timeline-section">
                <div class="preview-keyframe-modal__timeline-header">
                    <label><?= __admin('preview.keyframeTimeline') ?? 'Timeline' ?>:</label>
                    <small class="preview-keyframe-modal__timeline-hint"><?= __admin('preview.clickToAddFrame') ?? 'Click on timeline to add frames' ?></small>
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
                <?= __admin('preview.previewAnimation') ?? 'Preview' ?>
            </button>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="keyframe-cancel"><?= __admin('common.cancel') ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="keyframe-save"><?= __admin('common.save') ?></button>
            </div>
        </div>
    </div>
</div>
