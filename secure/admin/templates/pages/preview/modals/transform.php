<!-- Transform Editor Modal (Phase 9.3.1 Step 5) -->
<div class="preview-keyframe-modal transform-editor" id="transform-editor-modal">
    <div class="preview-keyframe-modal__backdrop"></div>
    <div class="preview-keyframe-modal__content transform-editor__content">
        <div class="preview-keyframe-modal__header">
            <h3><?= __admin('preview.transformEditor') ?? 'Transform Editor' ?></h3>
            <button type="button" class="preview-keyframe-modal__close" id="transform-editor-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-keyframe-modal__body transform-editor__body">
            <!-- Current Transform Preview -->
            <div class="transform-editor__preview">
                <label><?= __admin('preview.currentTransform') ?? 'Current Transform' ?>:</label>
                <code id="transform-current-value" class="transform-editor__current">none</code>
            </div>
            
            <!-- Functions List -->
            <div class="transform-editor__functions">
                <div class="transform-editor__functions-header">
                    <label><?= __admin('preview.activeFunctions') ?? 'Active Functions' ?>:</label>
                    <div class="transform-editor__add-wrapper">
                        <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="transform-add-btn">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <?= __admin('preview.addFunction') ?? 'Add Function' ?>
                        </button>
                        <div class="transform-editor__dropdown" id="transform-add-dropdown">
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.translate') ?? 'Translate' ?></div>
                                <button type="button" data-fn="translateX">translateX</button>
                                <button type="button" data-fn="translateY">translateY</button>
                                <button type="button" data-fn="translateZ">translateZ</button>
                                <button type="button" data-fn="translate">translate (X, Y)</button>
                                <button type="button" data-fn="translate3d">translate3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.rotate') ?? 'Rotate' ?></div>
                                <button type="button" data-fn="rotate">rotate</button>
                                <button type="button" data-fn="rotateX">rotateX</button>
                                <button type="button" data-fn="rotateY">rotateY</button>
                                <button type="button" data-fn="rotateZ">rotateZ</button>
                                <button type="button" data-fn="rotate3d">rotate3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.scale') ?? 'Scale' ?></div>
                                <button type="button" data-fn="scale">scale (X, Y)</button>
                                <button type="button" data-fn="scaleX">scaleX</button>
                                <button type="button" data-fn="scaleY">scaleY</button>
                                <button type="button" data-fn="scaleZ">scaleZ</button>
                                <button type="button" data-fn="scale3d">scale3d</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.skew') ?? 'Skew' ?></div>
                                <button type="button" data-fn="skew">skew (X, Y)</button>
                                <button type="button" data-fn="skewX">skewX</button>
                                <button type="button" data-fn="skewY">skewY</button>
                            </div>
                            <div class="transform-editor__dropdown-category">
                                <div class="transform-editor__dropdown-label"><?= __admin('preview.other') ?? 'Other' ?></div>
                                <button type="button" data-fn="perspective">perspective</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="transform-editor__functions-list" id="transform-functions-list">
                    <!-- Function rows will be rendered here -->
                </div>
            </div>
        </div>
        
        <div class="preview-keyframe-modal__footer">
            <div class="preview-keyframe-modal__footer-left">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" id="transform-clear">
                    <?= __admin('preview.clearAll') ?? 'Clear All' ?>
                </button>
            </div>
            <div class="preview-keyframe-modal__footer-right">
                <button type="button" class="admin-btn admin-btn--ghost" id="transform-cancel"><?= __admin('common.cancel') ?></button>
                <button type="button" class="admin-btn admin-btn--primary" id="transform-apply"><?= __admin('preview.apply') ?? 'Apply' ?></button>
            </div>
        </div>
    </div>
</div>
