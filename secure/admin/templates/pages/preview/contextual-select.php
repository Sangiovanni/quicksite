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
        <!-- Element info is displayed in its own row, above the workspace -->
        
        <!-- Navigation Buttons (visible in both select-info and variables modes) -->
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
        
        <!-- Every action of the panel is its icon and its name, one per line; with the tools' names off (Show names,
             preview.js) it is its icon alone, in one row that wraps, its title the tooltip and its aria-label the name. -->
        <div class="preview-contextual-info__actions" id="ctx-node-actions">
            <button type="button" class="admin-btn admin-btn--success" id="ctx-node-add"<?= qs_editor_control_attrs('select.add') ?> title="<?= __admin('preview.addNode') ?? 'Add Element' ?>" aria-label="<?= adminAttr(__admin('preview.addNode')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <span><?= __admin('preview.addNode') ?? 'Add' ?></span>
            </button>
            <!-- Edit Params: the accent, and the shared pencil -->
            <button type="button" class="admin-btn admin-btn--primary" id="ctx-node-edit-params"<?= qs_editor_control_attrs('select.edit-params') ?> data-qs-icon="edit"
                    title="<?= adminAttr(__admin('preview.editParams')) ?>" aria-label="<?= adminAttr(__admin('preview.editParams')) ?>">
                <span><?= __admin('preview.editParams') ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-duplicate"<?= qs_editor_control_attrs('select.duplicate') ?> title="<?= __admin('preview.duplicateNode') ?? 'Duplicate Element' ?>" aria-label="<?= adminAttr(__admin('preview.duplicateNode')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span><?= __admin('preview.duplicateNode') ?? 'Duplicate' ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-save-snippet"<?= qs_editor_control_attrs('select.save-snippet') ?> title="<?= __admin('preview.saveAsSnippet') ?? 'Save as Snippet' ?>" aria-label="<?= adminAttr(__admin('preview.saveSnippet')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19.562 12.097l1.531 2.653a3.5 3.5 0 0 1-3.03 5.25H5.937a3.5 3.5 0 0 1-3.031-5.25l1.53-2.653"/>
                    <path d="M12 15V2"/>
                    <path d="M8 6l4-4 4 4"/>
                </svg>
                <span><?= __admin('preview.saveSnippet') ?? 'Save Snippet' ?></span>
            </button>
            <button type="button" class="admin-btn" id="ctx-node-save-component"<?= qs_editor_control_attrs('select.save-component') ?> title="<?= __admin('preview.saveAsComponent') ?? 'Save as Component' ?>" aria-label="<?= adminAttr(__admin('preview.saveComponent')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span><?= __admin('preview.saveComponent') ?? 'Save Component' ?></span>
            </button>
            <!-- Delete: red, and the shared bin; it asks first. Del deletes too (preview.js). -->
            <button type="button" class="admin-btn admin-btn--danger" id="ctx-node-delete"<?= qs_editor_control_attrs('select.delete') ?> data-qs-icon="trash"
                    title="<?= adminAttr(__admin('common.delete')) ?> (Del)" aria-label="<?= adminAttr(__admin('common.delete')) ?>" aria-keyshortcuts="Delete">
                <span><?= __admin('common.delete') ?></span>
            </button>
            <!-- Variables button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-variables"<?= qs_editor_control_attrs('select.variables') ?> title="<?= __admin('preview.variables') ?? 'Variables' ?>" aria-label="<?= adminAttr(__admin('preview.variables')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                </svg>
                <span><?= __admin('preview.variables') ?? 'Variables' ?></span>
            </button>
            <!-- Enums button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-enums"<?= qs_editor_control_attrs('select.enums') ?> title="<?= __admin('preview.enums') ?? 'Enums' ?>" aria-label="<?= adminAttr(__admin('preview.enums')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
                <span><?= __admin('preview.enums') ?? 'Enums' ?></span>
            </button>
            <!-- Emulation button (component-only, shown/hidden by JS) -->
            <button type="button" class="admin-btn" id="ctx-node-emulation"<?= qs_editor_control_attrs('select.emulation') ?> title="<?= __admin('preview.emulation') ?? 'Variable Emulation' ?>" aria-label="<?= adminAttr(__admin('preview.emulation')) ?>" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span><?= __admin('preview.emulation') ?? 'Emulation' ?></span>
            </button>
            <!-- Translate from CSV button (complex-element only — currently
                 only Table; shown when selected node has data-qs-complex='table').
                 Opens the translate-csv modal. -->
            <button type="button" class="admin-btn" id="ctx-node-translate-csv"<?= qs_editor_control_attrs('select.translate-csv') ?> title="<?= __admin('preview.translateCsv', 'Translate from CSV') ?>" aria-label="<?= adminAttr(__admin('preview.translateCsv', 'Translate from CSV')) ?>" style="display: none;">
                <!-- "language / translate" icon: two stacked horizontal text-lines + a tiny arrow hint -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 5h12"/><path d="M9 3v2"/>
                    <path d="M5 9c0 4 3 6 6 6"/><path d="M13 9c0 4-3 6-6 6"/>
                    <path d="M21 21l-4-9-4 9"/><path d="M14.5 17h5"/>
                </svg>
                <span><?= __admin('preview.translateCsv', 'Translate from CSV') ?></span>
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
            <h4><?= __admin('preview.saveAsSnippet') ?? 'Save as Snippet' ?></h4>
            <button type="button" class="admin-modal__close" id="save-snippet-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
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
                <?php
                // The snippet categories (qs_snippet_categories(), the one list), the default first.
                require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';
                foreach (array_unique(array_merge([SNIPPET_DEFAULT_CATEGORY], qs_snippet_categories())) as $qsSnippetCategory):
                ?>
                <option value="<?= htmlspecialchars($qsSnippetCategory, ENT_QUOTES) ?>"><?= htmlspecialchars(__admin('preview.snippetCategory' . ucfirst($qsSnippetCategory), SNIPPET_CATEGORIES[$qsSnippetCategory]), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
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
        
        <!-- Checked: the snippet goes to the author's own library (scope "personal"), which
             every project of theirs can use; unchecked: to this project's snippets. -->
        <div class="preview-contextual-form__field">
            <label class="admin-checkbox">
                <input type="checkbox" id="save-snippet-personal">
                <span><?= __admin('preview.saveSnippetPersonal') ?></span>
            </label>
        </div>
        
        <!-- Actions -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn" id="save-snippet-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="save-snippet-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <?= __admin('preview.saveSnippetBtn') ?? 'Save Snippet' ?>
            </button>
        </div>
    </div>

    <!-- Save as Component Form (shown when save-component clicked) -->
    <div class="preview-contextual-form save-component-form" id="contextual-save-component-form" style="display: none;">
        <div class="preview-contextual-form__header">
            <h4><?= __admin('preview.saveAsComponent') ?? 'Save as Component' ?></h4>
            <button type="button" class="admin-modal__close" id="save-component-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        
        <!-- Component Name -->
        <div class="preview-contextual-form__field">
            <label for="save-component-name"><?= __admin('preview.componentName') ?? 'Name' ?> <span class="required">*</span></label>
            <input type="text" id="save-component-name" class="admin-input admin-input--sm" placeholder="<?= __admin('preview.componentNamePlaceholder') ?? 'my-component' ?>" required pattern="[a-zA-Z0-9][a-zA-Z0-9_\-]*">
            <small class="preview-contextual-form__hint"><?= __admin('preview.componentNameHint') ?? 'Letters, numbers, hyphens (used as identifier)' ?></small>
        </div>
        
        <!-- Structure Preview (read-only) -->
        <div class="preview-contextual-form__field">
            <label><?= __admin('preview.componentStructure') ?? 'Structure Preview' ?></label>
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
            <span><?= __admin('preview.componentPlaceholderInfo') ?? 'Text keys will be converted to {{placeholder}} variables automatically.' ?></span>
        </div>
        
        <!-- Actions -->
        <div class="preview-contextual-form__actions">
            <button type="button" class="admin-btn" id="save-component-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary" id="save-component-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <?= __admin('preview.saveComponentBtn') ?? 'Save Component' ?>
            </button>
        </div>
    </div>
</div>
