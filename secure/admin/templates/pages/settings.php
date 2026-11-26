<?php
/**
 * Admin Settings Page
 * 
 * Displays current configuration and allows viewing system info.
 */

$baseUrl = rtrim(BASE_URL, '/');
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
?>

<script>
// Page config for settings.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.aiSettingsUrl = <?= qs_inline_script_json($router->url('ai-settings')) ?>;
// The installation's version (its VERSION file), or null when it cannot be read.
window.QUICKSITE_CONFIG.quicksiteVersion = <?= qs_inline_script_json(qs_local_version()) ?>;
</script>
<?= __adminWords([
    'settings.permissions', 'settings.errors.permissionsFailed', 'settings.errors.sysInfoFailed',
    'settings.sysInfo.version', 'settings.sysInfo.versionUnknown', 'settings.sysInfo.totalCommands', 'settings.toast.preferencesSaved',
    'settings.ai.status', 'settings.ai.configured', 'settings.ai.providers', 'settings.ai.providersConfiguredCount',
    'settings.ai.defaultProvider', 'settings.ai.storage', 'settings.ai.storagePersistent', 'settings.ai.storageSession',
    'settings.ai.noneConfigured', 'settings.ai.addApiKey', 'settings.ai.runWithoutStoppingOn', 'settings.ai.runWithoutStoppingOff',
]) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/settings.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/settings.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <?= adminEscape(__admin('settings.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('settings.subtitle')) ?></p>
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
                <?= adminEscape(__admin('settings.systemInfo')) ?>
            </h2>
        </div>
        <div class="admin-card__body">
            <div id="system-info" class="admin-loading">
                <span class="admin-spinner"></span>
                <?= adminEscape(__admin('common.loading')) ?>
            </div>
        </div>
    </div>

    <!-- Your Permissions -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                <?= adminEscape(__admin('settings.yourPermissions')) ?>
            </h2>
        </div>
        <div class="admin-card__body">
            <div id="permissions-info" class="admin-loading">
                <span class="admin-spinner"></span>
                <?= adminEscape(__admin('settings.loadingPermissions')) ?>
            </div>
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
            <?= adminEscape(__admin('settings.preferences')) ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <label class="admin-label"><?= adminEscape(__admin('settings.keyboardShortcuts')) ?></label>
            <div class="admin-toggle-group">
                <label class="admin-toggle">
                    <input type="checkbox" id="pref-shortcuts" checked>
                    <span class="admin-toggle__slider"></span>
                    <span class="admin-toggle__label"><?= adminEscape(__admin('settings.pref.enableShortcuts')) ?></span>
                </label>
            </div>
            <p class="admin-hint"><?= str_replace('{key}', '<kbd>?</kbd>', adminEscape(__admin('settings.pref.shortcutsHint'))) ?></p>
        </div>
        
        <div class="admin-form-group">
            <label class="admin-label"><?= adminEscape(__admin('settings.toastDuration')) ?></label>
            <select id="pref-toast-duration" class="admin-select" style="max-width: 200px;">
                <option value="2000"><?= adminEscape(__admin('settings.pref.duration.2s')) ?></option>
                <option value="4000" selected><?= adminEscape(__admin('settings.pref.duration.4s')) ?></option>
                <option value="6000"><?= adminEscape(__admin('settings.pref.duration.6s')) ?></option>
                <option value="0"><?= adminEscape(__admin('settings.pref.duration.noHide')) ?></option>
            </select>
        </div>
        
        <button type="button" class="admin-btn admin-btn--primary" onclick="savePreferences()">
            <?= adminEscape(__admin('settings.savePreferences')) ?>
        </button>
    </div>
</div>


<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
                <circle cx="7.5" cy="14.5" r="1.5"/>
                <circle cx="16.5" cy="14.5" r="1.5"/>
            </svg>
            <?= adminEscape(__admin('settings.ai.title')) ?>
        </h2>
        <a href="<?= $router->url('ai-settings') ?>" class="admin-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9"/>
            </svg>
            <?= adminEscape(__admin('settings.ai.manageKeys')) ?>
        </a>
    </div>
    <div class="admin-card__body">
        <div id="ai-config-status">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <?= adminEscape(__admin('settings.ai.loadingStatus')) ?>
            </div>
        </div>
        
        <div class="admin-form-group" style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--admin-border);">
            <label class="admin-label"><?= adminEscape(__admin('settings.ai.automationOptions')) ?></label>
            <p class="admin-hint" style="margin-bottom: var(--space-sm);"><?= adminEscape(__admin('settings.ai.automationHint')) ?></p>
            
            <div class="admin-checkbox-group">
                <input type="checkbox" id="ai-auto-execute" class="admin-checkbox" onchange="updateAiAutomation('autoExecute', this.checked)">
                <label for="ai-auto-execute" class="admin-checkbox-label"><?= adminEscape(__admin('settings.ai.runWithoutStoppingLabel')) ?> <span style="color: var(--admin-warning);"><?= adminEscape(__admin('settings.ai.autoExecuteCaution')) ?></span></label>
            </div>
        </div>
    </div>
</div>

