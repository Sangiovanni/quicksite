<!-- Preview Controls - Row 1: Context + Actions -->
<div class="preview-toolbar preview-toolbar--row1">
    <!-- Left: Structure & Language selectors -->
    <div class="preview-toolbar__left">
        <!-- Unified Edit Target (Pages + Components) -->
        <div class="preview-toolbar__group">
            <label class="preview-toolbar__label" for="preview-target"><?= __admin('preview.editTarget') ?? 'Edit' ?>:</label>
            <select id="preview-target" class="preview-toolbar__select">
                <optgroup label="🏗️ <?= __admin('preview.layout') ?? 'Layout' ?>">
                    <option value="layout:menu"><?= __admin('preview.menu') ?? 'Menu (header)' ?></option>
                    <option value="layout:footer"><?= __admin('preview.footer') ?? 'Footer' ?></option>
                </optgroup>
                <optgroup label="📄 <?= __admin('preview.pages') ?? 'Pages' ?>">
                    <?php foreach ($routes as $route): ?>
                        <option value="page:<?= adminEscape($route) ?>"><?= adminEscape($route) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php if (!empty($components)): ?>
                <optgroup label="🧩 <?= __admin('preview.components') ?? 'Components' ?>">
                    <?php foreach ($components as $component): ?>
                        <option value="component:<?= adminEscape($component) ?>"><?= adminEscape($component) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
            <button type="button" id="preview-create-component" class="preview-toolbar__add-btn" title="<?= __admin('preview.createComponent') ?? 'New component' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
            </button>
            <button type="button" id="preview-delete-component" class="preview-toolbar__delete-btn" style="display: none;" title="<?= __admin('preview.deleteComponent') ?? 'Delete component' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
        </div>
        
        <!-- Language Selector (for multilingual sites) -->
        <?php if ($isMultilingual && count($languages) > 1): ?>
        <div class="preview-toolbar__group">
            <label class="preview-toolbar__label" for="preview-lang"><?= __admin('preview.language') ?>:</label>
            <select id="preview-lang" class="preview-toolbar__select preview-toolbar__select--sm">
                <?php foreach ($languages as $lang): ?>
                    <option value="<?= adminEscape($lang) ?>" <?= $lang === $defaultLang ? 'selected' : '' ?>><?= strtoupper(adminEscape($lang)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- Route Layout Toggles (shown only when editing a page) -->
        <div class="preview-toolbar__group preview-toolbar__layout-toggles" id="preview-layout-toggles" style="display: none;" title="<?= __admin('preview.layoutTogglesHint') ?? 'Show or hide shared sections on this page' ?>">
            <label class="preview-toolbar__toggle" title="<?= __admin('preview.menuToggleHint') ?? 'Show or hide the menu on this page' ?>">
                <input type="checkbox" id="preview-toggle-menu" checked>
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.menuToggle') ?? 'Menu' ?></span>
            </label>
            <label class="preview-toolbar__toggle" title="<?= __admin('preview.footerToggleHint') ?? 'Show or hide the footer on this page' ?>">
                <input type="checkbox" id="preview-toggle-footer" checked>
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.footerToggle') ?? 'Footer' ?></span>
            </label>
        </div>
    </div>
    
    <!-- Right: Actions -->
    <div class="preview-toolbar__right">
        <button type="button" id="preview-reload" class="admin-btn admin-btn--ghost admin-btn--icon-only" title="<?= __admin('preview.reload') ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/>
                <polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
        </button>
        <!-- Miniplayer Toggle -->
        <button type="button" id="preview-miniplayer-toggle" class="admin-btn admin-btn--ghost admin-btn--icon-only" title="<?= __admin('preview.miniplayer') ?>">
            <svg class="admin-btn__icon preview-miniplayer-icon--minimize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="14" y="14" width="8" height="8" rx="1"/>
                <path d="M4 4h12v12H4z" opacity="0.3"/>
                <path d="M20 10V4h-6"/>
            </svg>
            <svg class="admin-btn__icon preview-miniplayer-icon--expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <path d="M9 3v18M3 9h18"/>
            </svg>
        </button>
    </div>
</div>
