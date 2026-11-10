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
        
        <!-- Language Selector: shown for a project with more than one language. A run can change that while the page is open, so it is always there (preview.js rebuildLanguageList()) -->
        <div class="preview-toolbar__group"<?= $isMultilingual && count($languages) > 1 ? '' : ' style="display: none;"' ?>>
            <label class="preview-toolbar__label" for="preview-lang"><?= __admin('preview.language') ?>:</label>
            <select id="preview-lang" class="preview-toolbar__select preview-toolbar__select--sm">
                <?php foreach ($languages as $lang): ?>
                    <option value="<?= adminEscape($lang) ?>" <?= $lang === $defaultLang ? 'selected' : '' ?>><?= strtoupper(adminEscape($lang)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Page title: the page's title in the language shown (shown while a page is the edit target) -->
        <div class="preview-toolbar__group" id="preview-page-title-group" style="display: none;">
            <label class="preview-toolbar__label" for="preview-page-title"><?= __admin('preview.pageTitle') ?>:</label>
            <input type="text" id="preview-page-title" class="preview-toolbar__input" maxlength="200" autocomplete="off"
                   title="<?= adminEscape(__admin('preview.pageTitleHint')) ?>">
        </div>

        <!-- Theme Preview Toggle (always rendered; visibility managed by JS/config) -->
        <div class="preview-toolbar__group" id="preview-theme-toggle-group" style="display: <?= ($editConfig['THEME_MODE_ENABLED'] ?? false) ? '' : 'none' ?>;">
            <label class="preview-toolbar__label"><?= __admin('preview.themePreview') ?? 'Theme' ?>:</label>
            <div class="preview-toolbar__segmented" id="preview-theme-mode-switcher">
                <button type="button" class="preview-toolbar__seg-btn preview-toolbar__seg-btn--active" data-theme-preview="light" title="<?= __admin('preview.themeLight') ?? 'Preview light mode' ?>">
                    <span aria-hidden="true">☀</span> <?= __admin('preview.light') ?? 'Light' ?>
                </button>
                <button type="button" class="preview-toolbar__seg-btn" data-theme-preview="dark" title="<?= __admin('preview.themeDark') ?? 'Preview dark mode' ?>">
                    <span aria-hidden="true">🌙</span> <?= __admin('preview.dark') ?? 'Dark' ?>
                </button>
            </div>
        </div>
        <!-- Layout toggles (shown only when editing a page) -->
        <div class="preview-toolbar__group preview-toolbar__layout-toggles" id="preview-layout-toggles" style="display: none;" title="<?= __admin('preview.layoutTogglesHint') ?? 'Show or hide shared sections on this page' ?>">
            <label class="preview-toolbar__toggle" title="<?= __admin('preview.menuToggleHint') ?? 'Show or hide the menu on this page' ?>">
                <input type="checkbox" id="preview-toggle-menu" checked>
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.menuToggle') ?? 'Menu' ?></span>
            </label>
            <label class="preview-toolbar__toggle" title="<?= __admin('preview.footerToggleHint') ?? 'Show or hide the footer on this page' ?>">
                <input type="checkbox" id="preview-toggle-footer" checked>
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.footerToggle') ?? 'Footer' ?></span>
            </label>
            <label class="preview-toolbar__toggle" id="preview-toggle-consent-banner-label" style="display: none;" title="<?= __admin('preview.consentBannerToggleHint', 'Show or hide the cookie banner in this preview (does not persist)') ?>">
                <input type="checkbox" id="preview-toggle-consent-banner">
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.consentBannerToggle', 'Cookie banner') ?></span>
            </label>
            <label class="preview-toolbar__toggle" id="preview-toggle-consent-popup-label" style="display: none;" title="<?= __admin('preview.consentPopupToggleHint', 'Show or hide the cookie preferences popup in this preview (does not persist)') ?>">
                <input type="checkbox" id="preview-toggle-consent-popup">
                <span class="preview-toolbar__toggle-label"><?= __admin('preview.consentPopupToggle', 'Cookie popup') ?></span>
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
        <!-- Hides the editor's rows and panel, and brings them back: the page alone under the header -->
        <button type="button" id="preview-rows-toggle" class="admin-btn admin-btn--ghost admin-btn--icon-only" aria-pressed="false"
                title="<?= adminEscape(__admin('preview.rowsHide', "Hide the editor's rows and show the whole page")) ?>"
                data-title-hide="<?= adminEscape(__admin('preview.rowsHide', "Hide the editor's rows and show the whole page")) ?>"
                data-title-show="<?= adminEscape(__admin('preview.rowsShow', "Show the editor's rows")) ?>">
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
