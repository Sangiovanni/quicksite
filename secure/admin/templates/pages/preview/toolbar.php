<!-- Preview Controls - Row 1: Context + Actions -->
<div class="preview-toolbar preview-toolbar--row1">
    <!-- Left: Structure & Language selectors -->
    <div class="preview-toolbar__left">
        <!-- Unified Edit Target (Pages + Components) -->
        <div class="preview-toolbar__group">
            <label class="preview-toolbar__label" for="preview-target"><?= adminEscape(__admin('preview.editTarget')) ?>:</label>
            <select id="preview-target" class="preview-toolbar__select">
                <optgroup label="🏗️ <?= adminAttr(__admin('preview.layout')) ?>">
                    <option value="layout:menu"><?= adminEscape(__admin('preview.menu')) ?></option>
                    <option value="layout:footer"><?= adminEscape(__admin('preview.footer')) ?></option>
                </optgroup>
                <optgroup label="📄 <?= adminAttr(__admin('preview.pages')) ?>">
                    <?php foreach ($routes as $route): ?>
                        <option value="page:<?= adminEscape($route) ?>"><?= adminEscape($route) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php if (!empty($components)): ?>
                <optgroup label="🧩 <?= adminAttr(__admin('preview.components')) ?>">
                    <?php foreach ($components as $component): ?>
                        <option value="component:<?= adminEscape($component) ?>"><?= adminEscape($component) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
            <button type="button" id="preview-create-component" class="preview-toolbar__add-btn" title="<?= adminAttr(__admin('preview.createComponent')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
            </button>
            <!-- The component being edited: its bin, shown while a component is the target (preview.js names it) -->
            <button type="button" id="preview-delete-component" class="admin-btn admin-btn--ghost admin-action admin-action--delete" data-qs-icon="trash" style="display: none;"
                    title="<?= adminAttr(__admin('preview.deleteComponent')) ?>" aria-label="<?= adminAttr(__admin('preview.deleteComponent')) ?>"></button>
        </div>
        
        <!-- Language Selector: shown for a project with more than one language. A run can change that while the page is open, so it is always there (preview.js rebuildLanguageList()) -->
        <div class="preview-toolbar__group"<?= $isMultilingual && count($languages) > 1 ? '' : ' style="display: none;"' ?>>
            <label class="preview-toolbar__label" for="preview-lang"><?= adminEscape(__admin('preview.language')) ?>:</label>
            <select id="preview-lang" class="preview-toolbar__select preview-toolbar__select--sm">
                <?php foreach ($languages as $lang): ?>
                    <option value="<?= adminEscape($lang) ?>" <?= $lang === $defaultLang ? 'selected' : '' ?>><?= strtoupper(adminEscape($lang)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Page title: the page's title in the language shown (shown while a page is the edit target, to a role that can change it) -->
        <div class="preview-toolbar__group" id="preview-page-title-group" style="display: none;"<?= qs_editor_control_attrs('toolbar.title') ?>>
            <label class="preview-toolbar__label" for="preview-page-title"><?= adminEscape(__admin('preview.pageTitle')) ?>:</label>
            <input type="text" id="preview-page-title" class="preview-toolbar__input" maxlength="200" autocomplete="off"
                   title="<?= adminAttr(__admin('preview.pageTitleHint')) ?>">
        </div>

        <!-- Page description: the page's description in the language shown, beside its title (a page of the site, not a
             special page: those carry no link-preview tag). Its longest text is the engine's (QS_SOCIAL_DESCRIPTION_MAX);
             the button after it opens the whole text in a dialog, and the mark names the platforms that would cut it
             (preview.js, from getSocialPreview's table). -->
        <?php require_once SECURE_FOLDER_PATH . '/src/functions/socialPreviewHelpers.php'; ?>
        <div class="preview-toolbar__group" id="preview-page-description-group" style="display: none;"<?= qs_editor_control_attrs('toolbar.description') ?>>
            <label class="preview-toolbar__label" for="preview-page-description"><?= adminEscape(__admin('preview.pageDescription')) ?>:</label>
            <input type="text" id="preview-page-description" class="preview-toolbar__input" maxlength="<?= (int) QS_SOCIAL_DESCRIPTION_MAX ?>" autocomplete="off"
                   title="<?= adminAttr(__admin('preview.pageDescriptionHint')) ?>" aria-describedby="preview-page-description-cut">
            <button type="button" id="preview-page-description-expand" class="admin-btn admin-btn--ghost admin-btn--icon-only preview-toolbar__expand"
                    title="<?= adminAttr(__admin('preview.pageDescriptionExpand')) ?>" aria-label="<?= adminAttr(__admin('preview.pageDescriptionExpand')) ?>" aria-haspopup="dialog">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <polyline points="15 3 21 3 21 9"/>
                    <polyline points="9 21 3 21 3 15"/>
                    <line x1="21" y1="3" x2="14" y2="10"/>
                    <line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
            </button>
            <span class="preview-toolbar__cut" id="preview-page-description-cut" hidden></span>
        </div>

        <!-- Theme Preview Toggle (always rendered; visibility managed by JS/config) -->
        <div class="preview-toolbar__group" id="preview-theme-toggle-group" style="display: <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : 'none' ?>;">
            <label class="preview-toolbar__label"><?= adminEscape(__admin('preview.themePreview')) ?>:</label>
            <div class="preview-toolbar__segmented" id="preview-theme-mode-switcher">
                <button type="button" class="preview-toolbar__seg-btn preview-toolbar__seg-btn--active" data-theme-preview="light" title="<?= adminAttr(__admin('preview.themeLight')) ?>">
                    <span aria-hidden="true">☀</span> <?= adminEscape(__admin('preview.light')) ?>
                </button>
                <button type="button" class="preview-toolbar__seg-btn" data-theme-preview="dark" title="<?= adminAttr(__admin('preview.themeDark')) ?>">
                    <span aria-hidden="true">🌙</span> <?= adminEscape(__admin('preview.dark')) ?>
                </button>
            </div>
        </div>
        <!-- Layout toggles (shown only when editing a page) -->
        <div class="preview-toolbar__group preview-toolbar__layout-toggles" id="preview-layout-toggles" style="display: none;" title="<?= adminAttr(__admin('preview.layoutTogglesHint')) ?>">
            <label class="preview-toolbar__toggle" title="<?= adminAttr(__admin('preview.menuToggleHint')) ?>">
                <input type="checkbox" id="preview-toggle-menu" checked>
                <span class="preview-toolbar__toggle-label"><?= adminEscape(__admin('preview.menuToggle')) ?></span>
            </label>
            <label class="preview-toolbar__toggle" title="<?= adminAttr(__admin('preview.footerToggleHint')) ?>">
                <input type="checkbox" id="preview-toggle-footer" checked>
                <span class="preview-toolbar__toggle-label"><?= adminEscape(__admin('preview.footerToggle')) ?></span>
            </label>
            <label class="preview-toolbar__toggle" id="preview-toggle-consent-banner-label" style="display: none;" title="<?= adminAttr(__admin('preview.consentBannerToggleHint')) ?>">
                <input type="checkbox" id="preview-toggle-consent-banner">
                <span class="preview-toolbar__toggle-label"><?= adminEscape(__admin('preview.consentBannerToggle')) ?></span>
            </label>
            <label class="preview-toolbar__toggle" id="preview-toggle-consent-popup-label" style="display: none;" title="<?= adminAttr(__admin('preview.consentPopupToggleHint')) ?>">
                <input type="checkbox" id="preview-toggle-consent-popup">
                <span class="preview-toolbar__toggle-label"><?= adminEscape(__admin('preview.consentPopupToggle')) ?></span>
            </label>
        </div>
    </div>
    
    <!-- Right: Actions -->
    <div class="preview-toolbar__right">
        <button type="button" id="preview-reload" class="admin-btn admin-btn--ghost admin-btn--icon-only" title="<?= adminAttr(__admin('preview.reload')) ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/>
                <polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
        </button>
        <!-- Hides the editor's rows and panel, and brings them back: the page alone under the header -->
        <button type="button" id="preview-rows-toggle" class="admin-btn admin-btn--ghost admin-btn--icon-only" aria-pressed="false"
                title="<?= adminAttr(__admin('preview.rowsHide')) ?>">
            <svg class="admin-btn__icon preview-rows-toggle__hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="17 11 12 6 7 11"/>
                <polyline points="17 18 12 13 7 18"/>
            </svg>
            <svg class="admin-btn__icon preview-rows-toggle__show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="7 13 12 18 17 13"/>
                <polyline points="7 6 12 11 17 6"/>
            </svg>
        </button>
    </div>
</div>
