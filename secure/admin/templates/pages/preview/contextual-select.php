<!-- SELECT MODE Content (active by default) -->
<div class="preview-contextual-section preview-contextual-section--select preview-contextual-section--active" id="contextual-select" data-mode="select">
    <div class="preview-contextual-default" id="contextual-select-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/>
            <path d="M13 13l6 6"/>
        </svg>
        <span><?= adminEscape(__admin('preview.selectModeHint')) ?></span>
    </div>
    <div class="preview-contextual-info" id="contextual-select-info" style="display: none;">
        <!-- Element info is displayed in its own row, above the workspace -->
        
        <!-- Navigation Buttons (visible in both select-info and variables modes) -->
        <div class="preview-contextual-info__nav" id="ctx-node-nav">
            <button type="button" class="preview-nav-btn" id="ctx-nav-parent" title="<?= adminAttr(__admin('preview.goToParent')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18 15 12 9 6 15"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-prev" title="<?= adminAttr(__admin('preview.goToPrevSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-next" title="<?= adminAttr(__admin('preview.goToNextSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="ctx-nav-child" title="<?= adminAttr(__admin('preview.goToFirstChild')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        
        <!-- Every action of the panel is its icon and its name, one per line; with the tools' names off (Show names,
             preview.js) it is its icon alone, in one row that wraps, its title the tooltip and its aria-label the name. -->
        <div class="preview-contextual-info__actions" id="ctx-node-actions">
            <button type="button" class="admin-btn admin-btn--success" id="ctx-node-add"<?= qs_editor_control_attrs('select.add') ?> title="<?= adminAttr(__admin('preview.addNode')) ?>" aria-label="<?= adminAttr(__admin('preview.addNode')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <span><?= adminEscape(__admin('preview.addNode')) ?></span>
            </button>
            <!-- Edit Params: the accent, and the shared pencil -->
            <button type="button" class="admin-btn admin-btn--primary" id="ctx-node-edit-params"<?= qs_editor_control_attrs('select.edit-params') ?> data-qs-icon="edit"
                    title="<?= adminAttr(__admin('preview.editParams')) ?>" aria-label="<?= adminAttr(__admin('preview.editParams')) ?>">
                <span><?= adminEscape(__admin('preview.editParams')) ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-duplicate"<?= qs_editor_control_attrs('select.duplicate') ?> title="<?= adminAttr(__admin('preview.duplicateNode')) ?>" aria-label="<?= adminAttr(__admin('preview.duplicateNode')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span><?= adminEscape(__admin('preview.duplicateNode')) ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-save-snippet"<?= qs_editor_control_attrs('select.save-snippet') ?> title="<?= adminAttr(__admin('preview.saveAsSnippet')) ?>" aria-label="<?= adminAttr(__admin('preview.saveSnippet')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19.562 12.097l1.531 2.653a3.5 3.5 0 0 1-3.03 5.25H5.937a3.5 3.5 0 0 1-3.031-5.25l1.53-2.653"/>
                    <path d="M12 15V2"/>
                    <path d="M8 6l4-4 4 4"/>
                </svg>
                <span><?= adminEscape(__admin('preview.saveSnippet')) ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-save-component"<?= qs_editor_control_attrs('select.save-component') ?> title="<?= adminAttr(__admin('preview.saveAsComponent')) ?>" aria-label="<?= adminAttr(__admin('preview.saveComponent')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span><?= adminEscape(__admin('preview.saveComponent')) ?></span>
            </button>
            <!-- Delete: red, and the shared bin; it asks first. Del deletes too (preview.js). -->
            <button type="button" class="admin-btn admin-btn--danger" id="ctx-node-delete"<?= qs_editor_control_attrs('select.delete') ?> data-qs-icon="trash"
                    title="<?= adminAttr(__admin('common.delete')) ?> (Del)" aria-label="<?= adminAttr(__admin('common.delete')) ?>" aria-keyshortcuts="Delete">
                <span><?= adminEscape(__admin('common.delete')) ?></span>
            </button>
            <!-- Variables button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-variables"<?= qs_editor_control_attrs('select.variables') ?> title="<?= adminAttr(__admin('preview.variables')) ?>" aria-label="<?= adminAttr(__admin('preview.variables')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                </svg>
                <span><?= adminEscape(__admin('preview.variables')) ?></span>
            </button>
            <!-- Enums button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-enums"<?= qs_editor_control_attrs('select.enums') ?> title="<?= adminAttr(__admin('preview.enums')) ?>" aria-label="<?= adminAttr(__admin('preview.enums')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
                <span><?= adminEscape(__admin('preview.enums')) ?></span>
            </button>
            <!-- Emulation button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-emulation"<?= qs_editor_control_attrs('select.emulation') ?> title="<?= adminAttr(__admin('preview.emulation')) ?>" aria-label="<?= adminAttr(__admin('preview.emulation')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span><?= adminEscape(__admin('preview.emulation')) ?></span>
            </button>
            <!-- Translate from CSV button (complex-element only — currently
                 only Table; shown when selected node has data-qs-complex='table').
                 Opens the translate-csv modal. -->
            <button type="button" class="admin-btn" id="ctx-node-translate-csv"<?= qs_editor_control_attrs('select.translate-csv') ?> title="<?= adminAttr(__admin('preview.translateCsv')) ?>" aria-label="<?= adminAttr(__admin('preview.translateCsv')) ?>" style="display: none;">
                <!-- "language / translate" icon: two stacked horizontal text-lines + a tiny arrow hint -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 5h12"/><path d="M9 3v2"/>
                    <path d="M5 9c0 4 3 6 6 6"/><path d="M13 9c0 4-3 6-6 6"/>
                    <path d="M21 21l-4-9-4 9"/><path d="M14.5 17h5"/>
                </svg>
                <span><?= adminEscape(__admin('preview.translateCsv')) ?></span>
            </button>
        </div>
    </div>
    
    <!-- Variables Panel (component-only) -->
    <?php include __DIR__ . '/contextual-variables.php'; ?>

    <!-- Enums Panel (component-only) -->
    <?php include __DIR__ . '/contextual-enums.php'; ?>

    <!-- Variable Emulation Panel (component-only) -->
    <?php include __DIR__ . '/contextual-emulation.php'; ?>

    <!-- Save as Snippet Form (shown when save-snippet clicked) -->
    <div class="preview-contextual-form save-snippet-form" id="contextual-save-snippet-form" style="display: none;">
        <div class="preview-contextual-form__header">
            <h4><?= adminEscape(__admin('preview.saveAsSnippet')) ?></h4>
            <button type="button" class="admin-modal__close" id="save-snippet-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        
        <!-- Snippet Name -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-name"><?= adminEscape(__admin('preview.snippetName')) ?> <span class="required">*</span></label>
            <input type="text" id="save-snippet-name" class="admin-input admin-input--sm" placeholder="<?= adminAttr(__admin('preview.snippetNamePlaceholder')) ?>" required>
            <small class="preview-contextual-form__hint"><?= adminEscape(__admin('preview.snippetNameHint')) ?></small>
        </div>
        
        <!-- Snippet ID (auto-generated) -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-id"><?= adminEscape(__admin('preview.snippetId')) ?> <span class="required">*</span></label>
            <input type="text" id="save-snippet-id" class="admin-input admin-input--sm" placeholder="my-snippet" required pattern="[a-zA-Z][a-zA-Z0-9_\-]*">
            <small class="preview-contextual-form__hint"><?= adminEscape(__admin('preview.snippetIdHint')) ?></small>
        </div>
        
        <!-- Category -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-category"><?= adminEscape(__admin('preview.snippetCategory')) ?></label>
            <select id="save-snippet-category" class="admin-input admin-input--sm">
                <?php
                // The snippet categories (qs_snippet_categories(), the one list), the default first.
                require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';
                foreach (array_unique(array_merge([SNIPPET_DEFAULT_CATEGORY], qs_snippet_categories())) as $qsSnippetCategory):
                ?>
                <option value="<?= htmlspecialchars($qsSnippetCategory, ENT_QUOTES) ?>"><?= adminEscape(__admin('preview.snippetCategory' . ucfirst($qsSnippetCategory))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Description -->
        <div class="preview-contextual-form__field">
            <label for="save-snippet-desc"><?= adminEscape(__admin('preview.snippetDescription')) ?> <small>(<?= adminEscape(__admin('common.optional')) ?>)</small></label>
            <textarea id="save-snippet-desc" class="admin-input admin-input--sm" rows="2" placeholder="<?= adminAttr(__admin('preview.snippetDescPlaceholder')) ?>"></textarea>
        </div>
        
        <!-- Structure Preview (read-only) -->
        <div class="preview-contextual-form__field">
            <label><?= adminEscape(__admin('preview.snippetStructure')) ?></label>
            <div class="save-snippet-form__preview" id="save-snippet-structure-preview">
                <code>&lt;?&gt;</code>
            </div>
        </div>
        
        <!-- Checked: the snippet goes to the author's own library (scope "personal"), which
             every project of theirs can use; unchecked: to this project's snippets. -->
        <div class="preview-contextual-form__field">
            <label class="admin-checkbox">
                <input type="checkbox" id="save-snippet-personal">
                <span><?= adminEscape(__admin('preview.saveSnippetPersonal')) ?></span>
            </label>
        </div>
        
        <!-- Actions -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn" id="save-snippet-cancel">
                <?= adminEscape(__admin('common.cancel')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="save-snippet-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <?= adminEscape(__admin('preview.saveSnippetBtn')) ?>
            </button>
        </div>
    </div>

    <!-- Save as Component Form (shown when save-component clicked) -->
    <div class="preview-contextual-form save-component-form" id="contextual-save-component-form" style="display: none;">
        <div class="preview-contextual-form__header">
            <h4><?= adminEscape(__admin('preview.saveAsComponent')) ?></h4>
            <button type="button" class="admin-modal__close" id="save-component-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        
        <!-- Component Name -->
        <div class="preview-contextual-form__field">
            <label for="save-component-name"><?= adminEscape(__admin('preview.componentName')) ?> <span class="required">*</span></label>
            <input type="text" id="save-component-name" class="admin-input admin-input--sm" placeholder="<?= adminAttr(__admin('preview.componentNamePlaceholder')) ?>" required pattern="[a-zA-Z0-9][a-zA-Z0-9_\-]*">
            <small class="preview-contextual-form__hint"><?= adminEscape(__admin('preview.componentNameHint')) ?></small>
        </div>
        
        <!-- Structure Preview (read-only) -->
        <div class="preview-contextual-form__field">
            <label><?= adminEscape(__admin('preview.componentStructure')) ?></label>
            <div class="save-component-form__preview" id="save-component-structure-preview">
                <code>&lt;?&gt;</code>
            </div>
        </div>
        
        <!-- Info about placeholder conversion -->
        <div class="preview-contextual-form__info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <span><?= adminEscape(__admin('preview.componentPlaceholderInfo')) ?></span>
        </div>
        
        <!-- Actions -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn" id="save-component-cancel">
                <?= adminEscape(__admin('common.cancel')) ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="save-component-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <?= adminEscape(__admin('preview.saveComponentBtn')) ?>
            </button>
        </div>
    </div>
</div>
