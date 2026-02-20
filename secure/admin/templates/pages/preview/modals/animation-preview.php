<!-- Animation Preview Modal (Phase 9.5) -->
<div class="preview-keyframe-modal animation-preview" id="animation-preview-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content animation-preview__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= __admin('preview.animationPreview') ?? 'Animation Preview' ?></h3>
            <span class="animation-preview__keyframe-name" id="animation-preview-name">@keyframes name</span>
            <button type="button" class="preview-keyframe-modal__close" id="animation-preview-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-keyframe-modal__body animation-preview__body">
            <!-- Preview Stage -->
            <div class="animation-preview__stage" id="animation-preview-stage">
                <img src="<?= $baseUrl ?>/admin/assets/images/favicon.png" alt="Preview" class="animation-preview__logo" id="animation-preview-logo">
            </div>
            
            <!-- Animation Controls -->
            <div class="animation-preview__controls">
                <!-- Duration -->
                <div class="animation-preview__control">
                    <label for="animation-preview-duration"><?= __admin('preview.animDuration') ?? 'Duration' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-duration" class="admin-input" value="1000" min="100" max="10000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Timing Function -->
                <div class="animation-preview__control">
                    <label for="animation-preview-timing"><?= __admin('preview.timingFunction') ?? 'Timing Function' ?>:</label>
                    <select id="animation-preview-timing" class="admin-select">
                        <option value="ease" selected>ease</option>
                        <option value="linear">linear</option>
                        <option value="ease-in">ease-in</option>
                        <option value="ease-out">ease-out</option>
                        <option value="ease-in-out">ease-in-out</option>
                    </select>
                </div>
                
                <!-- Delay -->
                <div class="animation-preview__control">
                    <label for="animation-preview-delay"><?= __admin('preview.animDelay') ?? 'Delay' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-delay" class="admin-input" value="0" min="0" max="5000" step="100">
                        <span class="animation-preview__unit">ms</span>
                    </div>
                </div>
                
                <!-- Iteration Count -->
                <div class="animation-preview__control">
                    <label for="animation-preview-count"><?= __admin('preview.animIterations') ?? 'Iterations' ?>:</label>
                    <div class="animation-preview__input-group">
                        <input type="number" id="animation-preview-count" class="admin-input" value="1" min="1" max="100" step="1">
                        <label class="animation-preview__checkbox">
                            <input type="checkbox" id="animation-preview-infinite">
                            <span><?= __admin('preview.infinite') ?? 'Infinite' ?></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Generated Animation CSS -->
            <div class="animation-preview__css-preview">
                <label><?= __admin('preview.generatedCSS') ?? 'Generated CSS' ?>:</label>
                <code id="animation-preview-css">animation: 1000ms ease 0ms keyframeName; animation-iteration-count: 1;</code>
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div class="preview-keyframe-modal__footer-left">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="animation-preview-play">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <?= __admin('preview.playAnimation') ?? 'Play Animation' ?>
                </button>
            </div>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="animation-preview-done"><?= __admin('common.close') ?? 'Close' ?></button>
            </div>
        </div>
    </div>
</div>
