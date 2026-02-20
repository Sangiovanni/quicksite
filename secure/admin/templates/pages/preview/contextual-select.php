<!-- SELECT MODE Content (active by default) -->
<div class="preview-contextual-section preview-contextual-section--select preview-contextual-section--active" id="contextual-select" data-mode="select">
    <div class="preview-contextual-default" id="contextual-select-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
            <path d="M13 13l6 6"/>
        </svg>
        <span><?= __admin('preview.selectModeHint') ?? 'Click any element in the preview to inspect it' ?></span>
    </div>
    <div class="preview-contextual-info" id="contextual-select-info" style="display: none;">
        <!-- Element info now displayed in global info bar at bottom of workspace -->
        
        <!-- Navigation Buttons -->
        <div class="preview-contextual-info__nav" id="ctx-node-nav">
            <button type="button" class="preview-nav-btn" id="ctx-nav-parent" title="<?= __admin('preview.goToParent') ?? 'Go to Parent (↑)' ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18 15 12 9 6 15"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-prev" title="<?= __admin('preview.goToPrevSibling') ?? 'Previous Sibling (←)' ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-next" title="<?= __admin('preview.goToNextSibling') ?? 'Next Sibling (→)' ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-child" title="<?= __admin('preview.goToFirstChild') ?? 'First Child (↓)' ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        
        <div class="preview-contextual-info__actions">
            <button type="button" class="admin-btn admin-btn--success" id="ctx-node-add" title="<?= __admin('preview.addNode') ?? 'Add Element' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <span><?= __admin('preview.addNode') ?? 'Add' ?></span>
            </button>
            <button type="button" class="admin-btn admin-btn--info" id="ctx-node-duplicate" title="<?= __admin('preview.duplicateNode') ?? 'Duplicate Element' ?> (D)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span><?= __admin('preview.duplicateNode') ?? 'Duplicate' ?></span>
            </button>
            <button type="button" class="admin-btn admin-btn--warning" id="ctx-node-save-snippet" title="<?= __admin('preview.saveAsSnippet') ?? 'Save as Snippet' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19.562 12.097l1.531 2.653a3.5 3.5 0 0 1-3.03 5.25H5.937a3.5 3.5 0 0 1-3.031-5.25l1.53-2.653"/>
                    <path d="M12 15V2"/>
                    <path d="M8 6l4-4 4 4"/>
                </svg>
                <span><?= __admin('preview.saveSnippet') ?? 'Save Snippet' ?></span>
            </button>
            <button type="button" class="admin-btn admin-btn--danger" id="ctx-node-delete" title="<?= __admin('common.delete') ?> (Del)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                <span><?= __admin('common.delete') ?? 'Delete' ?></span>
            </button>
            <!-- Variables button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn admin-btn--secondary" id="ctx-node-variables" title="<?= __admin('preview.variables') ?? 'Variables' ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                </svg>
                <span><?= __admin('preview.variables') ?? 'Variables' ?></span>
            </button>
        </div>
    </div>
    
    <!-- Variables Panel (component-only) -->
    <?php include __DIR__ . '/contextual-variables.php'; ?>

    <!-- Save as Snippet Form (shown when save-snippet clicked) -->
    <div class="preview-contextual-form save-snippet-form" id="contextual-save-snippet-form" style="display: none;">
        <div class="preview-contextual-form__header">
            <h4><?= __admin('preview.saveAsSnippet') ?? 'Save as Snippet' ?></h4>
            <button type="button" class="preview-contextual-form__close" id="save-snippet-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <!-- Snippet Name -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-name"><?= __admin('preview.snippetName') ?? 'Name' ?> <span class="required">*</span></label>
            <input type="text" id="save-snippet-name" class="admin-input admin-input--sm" placeholder="<?= __admin('preview.snippetNamePlaceholder') ?? 'My Snippet' ?>" required>
            <small class="preview-contextual-form__hint"><?= __admin('preview.snippetNameHint') ?? 'Display name for the snippet' ?></small>
        </div>
        
        <!-- Snippet ID (auto-generated) -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-id"><?= __admin('preview.snippetId') ?? 'ID' ?> <span class="required">*</span></label>
            <input type="text" id="save-snippet-id" class="admin-input admin-input--sm" placeholder="my-snippet" required pattern="[a-zA-Z][a-zA-Z0-9_\-]*">
            <small class="preview-contextual-form__hint"><?= __admin('preview.snippetIdHint') ?? 'Unique identifier (letters, numbers, dashes)' ?></small>
        </div>
        
        <!-- Category -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-category"><?= __admin('preview.snippetCategory') ?? 'Category' ?></label>
            <select id="save-snippet-category" class="admin-input admin-input--sm">
                <option value="other"><?= __admin('preview.snippetCategoryOther') ?? 'Other' ?></option>
                <option value="nav"><?= __admin('preview.snippetCategoryNav') ?? 'Navigation' ?></option>
                <option value="forms"><?= __admin('preview.snippetCategoryForms') ?? 'Forms' ?></option>
                <option value="cards"><?= __admin('preview.snippetCategoryCards') ?? 'Cards' ?></option>
                <option value="layouts"><?= __admin('preview.snippetCategoryLayouts') ?? 'Layouts' ?></option>
                <option value="content"><?= __admin('preview.snippetCategoryContent') ?? 'Content' ?></option>
                <option value="lists"><?= __admin('preview.snippetCategoryLists') ?? 'Lists' ?></option>
            </select>
        </div>
        
        <!-- Description -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-desc"><?= __admin('preview.snippetDescription') ?? 'Description' ?> <small>(<?= __admin('common.optional') ?? 'optional' ?>)</small></label>
            <textarea id="save-snippet-desc" class="admin-input admin-input--sm" rows="2" placeholder="<?= __admin('preview.snippetDescPlaceholder') ?? 'Brief description of this snippet...' ?>"></textarea>
        </div>
        
        <!-- Structure Preview (read-only) -->
        <div class="preview-contextual-form__field">
            <label><?= __admin('preview.snippetStructure') ?? 'Structure Preview' ?></label>
            <div class="save-snippet-form__preview" id="save-snippet-structure-preview">
                <code>&lt;?&gt;</code>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary" id="save-snippet-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="save-snippet-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <?= __admin('preview.saveSnippetBtn') ?? 'Save Snippet' ?>
            </button>
        </div>
    </div>
</div>
