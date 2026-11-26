<!-- Animation Preview Modal -->
<div class="preview-keyframe-modal" id="animation-preview-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content animation-preview__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= adminEscape(__admin('preview.animationPreview')) ?></h3>
            <span class="animation-preview__keyframe-name" id="animation-preview-name">@keyframes name</span>
            <button type="button" class="admin-modal__close" id="animation-preview-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        
        <div class="preview-keyframe-modal__body animation-preview__body">
            <!-- Preview Stage -->
            <div class="animation-preview__stage" id="animation-preview-stage">
                <img src="<?= $baseUrl ?>/admin/assets/images/logo.svg" alt="<?= adminAttr(__admin('preview.animationPreviewAlt')) ?>" class="animation-preview__logo" id="animation-preview-logo" width="80" height="80">
            </div>
            
            <!-- Animation Controls -->
            <div class="animation-preview__controls">
                <!-- Duration -->
                <div class="animation-preview__control">
                    <label for="animation-preview-duration"><?= adminEscape(__admin('preview.animDuration')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-duration" class="admin-input" value="1000" min="100" max="10000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Timing Function -->
                <div class="animation-preview__control">
                    <label for="animation-preview-timing"><?= adminEscape(__admin('preview.timingFunction')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                    <select id="animation-preview-timing" class="admin-select">
                        <option value="ease" selected>ease</option>
                        <option value="linear">linear</option>
                        <option value="ease-in">ease-in</option>
                        <option value="ease-out">ease-out</option>
                        <option value="ease-in-out">ease-in-out</option>
                    </select>
                    <!-- Opens QSEasingPicker with
                         the select's current value pre-loaded; confirm adds the
                         resulting cubic-bezier as a new option + selects it. -->
                    <button type="button"
                            class="admin-btn admin-btn--ghost"
                            id="animation-preview-timing-custom"
                            title="<?= adminAttr(__admin('preview.easingPickerTitle')) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                            <path d="M3 18 C 9 18, 9 6, 21 6"/>
                            <circle cx="3" cy="18" r="1.5" fill="currentColor"/>
                            <circle cx="21" cy="6" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                </div>
                
                <!-- Delay -->
                <div class="animation-preview__control">
                    <label for="animation-preview-delay"><?= adminEscape(__admin('preview.animDelay')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-delay" class="admin-input" value="0" min="0" max="5000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Iteration Count -->
                <div class="animation-preview__control">
                    <label for="animation-preview-count"><?= adminEscape(__admin('preview.animIterations')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-count" class="admin-input" value="1" min="1" max="100" step="1">
                        <label class="animation-preview__checkbox">
                            <input type="checkbox" id="animation-preview-infinite">
                            <span><?= adminEscape(__admin('preview.infinite')) ?></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Generated Animation CSS -->
            <div class="animation-preview__css-preview">
                <label><?= adminEscape(__admin('preview.generatedCSS')) ?><?= adminEscape(__admin('common.join.colon')) ?></label>
                <code id="animation-preview-css">animation: 1000ms ease 0ms keyframeName; animation-iteration-count: 1;</code>
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div>
                <button type="button" class="admin-btn admin-btn--primary" id="animation-preview-play">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= adminEscape(__admin('preview.playAnimation')) ?>
                </button>
            </div>
        </div>
    </div>
</div>
