<?php
/**
 * Admin Settings Page
 * 
 * Displays current configuration and allows viewing system info.
 * 
 * @version 1.7.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script>
// Page config for settings.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.baseUrl = '<?= $baseUrl ?>/management';
window.QUICKSITE_CONFIG.commandUrl = '<?= $router->getBaseUrl() ?>/command';
window.QUICKSITE_CONFIG.aiSettingsUrl = '<?= $router->url('ai-settings') ?>';
window.QUICKSITE_CONFIG.quicksiteVersion = '<?= defined('QUICKSITE_VERSION') ? QUICKSITE_VERSION : '1.6.0' ?>';
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/settings.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/settings.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('settings.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('settings.subtitle') ?></p>
</div>

<div class="admin-grid admin-grid--cols-2">
    <!-- System Information -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <?= __admin('settings.systemInfo') ?>
            </h2>
        </div>
        <div class="admin-card__body">
            <div id="system-info" class="admin-loading">
                <span class="admin-spinner"></span>
                <?= __admin('common.loading') ?>
            </div>
        </div>
    </div>

    <!-- Current Configuration -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                <?= __admin('settings.configuration') ?>
            </h2>
        </div>
        <div class="admin-card__body">
            <div id="config-info" class="admin-loading">
                <span class="admin-spinner"></span>
                <?= __admin('common.loading') ?>
            </div>
        </div>
    </div>
</div>

<!-- Routes Overview -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            <?= __admin('settings.routes') ?>
        </h2>
        <a href="<?= $router->url('command', 'addRoute') ?>" class="admin-btn admin-btn--small admin-btn--primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('settings.addRoute') ?>
        </a>
    </div>
    <div class="admin-card__body">
        <div id="routes-list" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('settings.loadingRoutes') ?>
        </div>
    </div>
</div>

<!-- Languages Overview -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 8l6 6"/>
                <path d="M4 14l6-6 2-3"/>
                <path d="M2 5h12"/>
                <path d="M7 2h1"/>
                <path d="M22 22l-5-10-5 10"/>
                <path d="M14 18h6"/>
            </svg>
            <?= __admin('settings.languages') ?>
        </h2>
        <a href="<?= $router->url('command', 'addLang') ?>" class="admin-btn admin-btn--small admin-btn--primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('settings.addLanguage') ?>
        </a>
    </div>
    <div class="admin-card__body">
        <div id="languages-list" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('settings.loadingLanguages') ?>
        </div>
    </div>
</div>

<!-- Admin Preferences -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <?= __admin('settings.preferences') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <label class="admin-label"><?= __admin('settings.keyboardShortcuts') ?></label>
            <div class="admin-toggle-group">
                <label class="admin-toggle">
                    <input type="checkbox" id="pref-shortcuts" checked>
                    <span class="admin-toggle__slider"></span>
                    <span class="admin-toggle__label">Enable keyboard shortcuts</span>
                </label>
            </div>
            <p class="admin-hint">Press <kbd>?</kbd> to view all shortcuts</p>
        </div>
        
        <div class="admin-form-group">
            <label class="admin-label"><?= __admin('settings.confirmDestructive') ?></label>
            <div class="admin-toggle-group">
                <label class="admin-toggle">
                    <input type="checkbox" id="pref-confirm" checked>
                    <span class="admin-toggle__slider"></span>
                    <span class="admin-toggle__label">Show confirmation for destructive actions</span>
                </label>
            </div>
        </div>
        
        <div class="admin-form-group">
            <label class="admin-label"><?= __admin('settings.toastDuration') ?></label>
            <select id="pref-toast-duration" class="admin-select" style="max-width: 200px;">
                <option value="2000">2 seconds</option>
                <option value="4000" selected>4 seconds</option>
                <option value="6000">6 seconds</option>
                <option value="0">Don't auto-hide</option>
            </select>
        </div>
        
        <button type="button" class="admin-btn admin-btn--primary" onclick="savePreferences()">
            Save Preferences
        </button>
    </div>
</div>

<!-- Your Permissions -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Your Permissions
        </h2>
    </div>
    <div class="admin-card__body">
        <div id="permissions-info" class="admin-loading">
            <span class="admin-spinner"></span>
            Loading permissions...
        </div>
    </div>
</div>

<!-- AI Configuration Quick Access -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
                <circle cx="7.5" cy="14.5" r="1.5"/>
                <circle cx="16.5" cy="14.5" r="1.5"/>
            </svg>
            AI Configuration
        </h2>
        <a href="<?= $router->url('ai-settings') ?>" class="admin-btn admin-btn--small admin-btn--secondary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9"/>
            </svg>
            Manage Keys
        </a>
    </div>
    <div class="admin-card__body">
        <div id="ai-config-status">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                Loading AI status...
            </div>
        </div>
        
        <div class="admin-form-group" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
            <label class="admin-label">Automation Options</label>
            <p class="admin-hint" style="margin-bottom: var(--space-sm);">These settings apply when using AI specs.</p>
            
            <div class="admin-checkbox-group" style="margin-bottom: var(--space-sm);">
                <input type="checkbox" id="ai-auto-preview" class="admin-checkbox" onchange="updateAiAutomation('autoPreview', this.checked)">
                <label for="ai-auto-preview" class="admin-checkbox-label">Auto-preview commands when valid JSON is detected</label>
            </div>
            
            <div class="admin-checkbox-group">
                <input type="checkbox" id="ai-auto-execute" class="admin-checkbox" onchange="updateAiAutomation('autoExecute', this.checked)">
                <label for="ai-auto-execute" class="admin-checkbox-label">Auto-execute commands after preview <span style="color: var(--admin-warning);">(use with caution)</span></label>
            </div>
        </div>
    </div>
</div>

