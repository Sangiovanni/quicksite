<?php
/**
 * Admin Settings Page
 * 
 * Displays current configuration and allows viewing system info.
 * 
 * @version 1.6.0
 */
?>

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

<!-- Tutorial Settings -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
            </svg>
            <?= __admin('tutorial.tutorialSettings') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);"><?= __admin('tutorial.tutorialDescription') ?></p>
        
        <div id="tutorial-status" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('common.loading') ?>
        </div>
        
        <div id="tutorial-actions" style="display: none; margin-top: var(--space-md);">
            <button type="button" class="admin-btn admin-btn--primary" onclick="restartTutorial()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M1 4v6h6"/>
                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                </svg>
                <?= __admin('tutorial.restartTutorial') ?>
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadSystemInfo();
    loadConfigInfo();
    loadRoutes();
    loadLanguages();
    loadPreferences();
    loadTutorialStatus();
    loadPermissionsInfo();
});

async function loadPermissionsInfo() {
    const container = document.getElementById('permissions-info');
    
    try {
        const result = await QuickSiteAdmin.apiCall('getMyPermissions', 'GET');
        
        if (result.ok && result.data?.data) {
            const data = result.data.data;
            const role = data.role;
            const isSuperAdmin = data.is_superadmin;
            const commandCount = data.command_count;
            
            // Determine badge class
            let badgeClass = 'info';
            if (isSuperAdmin) badgeClass = 'warning';
            else if (role === 'admin') badgeClass = 'success';
            else if (role === 'developer' || role === 'designer') badgeClass = 'info';
            else if (role === 'editor') badgeClass = 'info';
            else badgeClass = 'muted';
            
            // Role descriptions
            const roleDescriptions = {
                '*': 'Full system access including token and role management',
                'admin': 'Full access except token and role management',
                'developer': 'Build, deploy, and full content editing',
                'designer': 'Style editing plus content management',
                'editor': 'Content editing including structure, translations, assets',
                'viewer': 'Read-only access to view content and settings'
            };
            
            container.innerHTML = `
                <dl class="admin-definition-list">
                    <dt>Your Role</dt>
                    <dd>
                        <span class="admin-badge admin-badge--${badgeClass}">
                            ${isSuperAdmin ? '⭐ Superadmin' : role}
                        </span>
                    </dd>
                    
                    <dt>Access Level</dt>
                    <dd>${roleDescriptions[role] || 'Custom role'}</dd>
                    
                    <dt>Available Commands</dt>
                    <dd>${isSuperAdmin ? 'All (77 commands)' : commandCount + ' commands'}</dd>
                </dl>
                ${!isSuperAdmin ? `
                <details style="margin-top: var(--space-md);">
                    <summary class="admin-text-muted" style="cursor: pointer;">View your accessible commands</summary>
                    <div style="margin-top: var(--space-sm); max-height: 200px; overflow-y: auto;">
                        <div style="display: flex; flex-wrap: wrap; gap: var(--space-xs);">
                            ${data.commands.map(cmd => `<code style="font-size: var(--font-size-xs);">${cmd}</code>`).join('')}
                        </div>
                    </div>
                </details>
                ` : ''}
            `;
        } else {
            container.innerHTML = '<p class="admin-text-muted">Could not load permissions</p>';
        }
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
}

async function loadSystemInfo() {
    const container = document.getElementById('system-info');
    
    try {
        // Get basic system info from help command
        const result = await QuickSiteAdmin.apiRequest('help');
        
        if (result.ok && result.data.data) {
            const info = result.data.data;
            
            container.innerHTML = `
                <dl class="admin-definition-list">
                    <dt>API Version</dt>
                    <dd><code>${info.version || '<?= defined('QUICKSITE_VERSION') ? QUICKSITE_VERSION : '1.6.0' ?>'}</code></dd>
                    
                    <dt>Total Commands</dt>
                    <dd>${info.total || Object.keys(info.commands || {}).length || 0}</dd>
                    
                    <dt>Base URL</dt>
                    <dd><code>${info.base_url ? info.base_url.replace(/\/+/g, '/').replace(':/', '://') : '<?= rtrim(BASE_URL, '/') ?>/management'}</code></dd>
                    
                    <dt>Server Time</dt>
                    <dd>${new Date().toLocaleString()}</dd>
                </dl>
            `;
        } else {
            container.innerHTML = '<p class="admin-text-muted">Could not load system info</p>';
        }
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
}

async function loadConfigInfo() {
    const container = document.getElementById('config-info');
    
    try {
        // Use getStyles to check if styles are working
        const stylesResult = await QuickSiteAdmin.apiRequest('getStyles');
        const langResult = await QuickSiteAdmin.apiRequest('getLangList');
        const routesResult = await QuickSiteAdmin.apiRequest('getRoutes');
        
        const langCount = langResult.ok ? (langResult.data.data?.languages?.length || 0) : 0;
        const routeCount = routesResult.ok ? (routesResult.data.data?.routes?.length || 0) : 0;
        const isMultilingual = langCount > 1;
        
        container.innerHTML = `
            <dl class="admin-definition-list">
                <dt>Multilingual Mode</dt>
                <dd>
                    <span class="admin-badge admin-badge--${isMultilingual ? 'success' : 'info'}">
                        ${isMultilingual ? 'Enabled' : 'Single Language'}
                    </span>
                </dd>
                
                <dt>Languages</dt>
                <dd>${langCount} configured</dd>
                
                <dt>Routes</dt>
                <dd>${routeCount} active</dd>
                
                <dt>Styles Status</dt>
                <dd>
                    <span class="admin-badge admin-badge--${stylesResult.ok ? 'success' : 'error'}">
                        ${stylesResult.ok ? 'Loaded' : 'Error'}
                    </span>
                </dd>
            </dl>
        `;
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
}

async function loadRoutes() {
    const container = document.getElementById('routes-list');
    
    try {
        const result = await QuickSiteAdmin.apiRequest('getRoutes');
        
        if (result.ok && result.data.data?.flat_routes) {
            const routes = result.data.data.flat_routes;
            
            if (routes.length === 0) {
                container.innerHTML = '<p class="admin-text-muted">No routes configured</p>';
                return;
            }
            
            let html = '<div class="admin-tag-list">';
            routes.forEach(route => {
                html += `
                    <span class="admin-tag admin-tag--route">
                        <code>/${route}</code>
                        <a href="<?= $router->getBaseUrl() ?>/command/deleteRoute?route=${encodeURIComponent(route)}" 
                           class="admin-tag__action" title="Delete route">×</a>
                    </span>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="admin-text-muted">Could not load routes</p>';
        }
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
}

async function loadLanguages() {
    const container = document.getElementById('languages-list');
    
    try {
        const result = await QuickSiteAdmin.apiRequest('getLangList');
        
        if (result.ok && result.data.data?.languages) {
            const data = result.data.data;
            const langs = data.languages;
            const defaultLang = data.default_language;
            const langNames = data.language_names || {};
            
            if (langs.length === 0) {
                container.innerHTML = '<p class="admin-text-muted">No languages configured</p>';
                return;
            }
            
            let html = '<div class="admin-lang-list">';
            langs.forEach(lang => {
                const isDefault = lang === defaultLang;
                const name = langNames[lang] || lang;
                html += `
                    <div class="admin-lang-item ${isDefault ? 'admin-lang-item--default' : ''}">
                        <div class="admin-lang-info">
                            <code class="admin-lang-code">${lang.toUpperCase()}</code>
                            <span class="admin-lang-name">${QuickSiteAdmin.escapeHtml(name)}</span>
                            ${isDefault ? '<span class="admin-badge admin-badge--success">Default</span>' : ''}
                        </div>
                        ${!isDefault ? `
                            <button class="admin-btn admin-btn--small admin-btn--ghost" 
                                    onclick="setDefaultLang('${lang}')" 
                                    title="Set as default language">
                                Set Default
                            </button>
                        ` : ''}
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="admin-text-muted">Could not load languages</p>';
        }
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
}

async function setDefaultLang(langCode) {
    if (!confirm(`Set "${langCode.toUpperCase()}" as the default language?`)) {
        return;
    }
    
    try {
        const result = await QuickSiteAdmin.apiRequest('setDefaultLang', 'PATCH', { lang: langCode });
        
        if (result.ok) {
            QuickSiteAdmin.showToast(result.data.message || 'Default language updated', 'success');
            // Reload languages list to reflect the change
            loadLanguages();
            // Also reload system info to update the display
            loadSystemInfo();
        } else {
            QuickSiteAdmin.showToast(result.data.message || 'Failed to update default language', 'error');
        }
    } catch (error) {
        QuickSiteAdmin.showToast(`Error: ${error.message}`, 'error');
    }
}

function loadPreferences() {
    const prefs = JSON.parse(localStorage.getItem('quicksite_admin_prefs') || '{}');
    
    document.getElementById('pref-shortcuts').checked = prefs.shortcuts !== false;
    document.getElementById('pref-confirm').checked = prefs.confirmDestructive !== false;
    document.getElementById('pref-toast-duration').value = prefs.toastDuration || '4000';
}

function savePreferences() {
    const prefs = {
        shortcuts: document.getElementById('pref-shortcuts').checked,
        confirmDestructive: document.getElementById('pref-confirm').checked,
        toastDuration: document.getElementById('pref-toast-duration').value
    };
    
    localStorage.setItem('quicksite_admin_prefs', JSON.stringify(prefs));
    
    // Update QuickSiteAdmin.prefs immediately so changes take effect without reload
    QuickSiteAdmin.prefs = prefs;
    
    QuickSiteAdmin.showToast('Preferences saved', 'success');
}

async function loadTutorialStatus() {
    const container = document.getElementById('tutorial-status');
    const actions = document.getElementById('tutorial-actions');
    
    try {
        // Use admin-specific tutorial API
        const response = await fetch('<?= $router->getBaseUrl() ?>/tutorial-api.php?action=get', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const result = await response.json();
        
        if (result.success && result.data) {
            const data = result.data;
            const statusLabels = {
                'pending': { text: '<?= __adminJs('tutorial.step1Preview') ?>', badge: 'info' },
                'skipped': { text: 'Skipped', badge: 'warning' },
                'completed': { text: '<?= __adminJs('tutorial.tutorialComplete') ?>', badge: 'success' }
            };
            
            const status = statusLabels[data.status] || statusLabels['pending'];
            const totalSteps = 11;
            let stepText;
            if (data.status === 'completed') {
                stepText = 'Completed';
            } else if (data.step) {
                stepText = `Step ${data.step}/${totalSteps}`;
            } else {
                stepText = 'Not started';
            }
            
            container.innerHTML = `
                <dl class="admin-definition-list">
                    <dt>Status</dt>
                    <dd>
                        <span class="admin-badge admin-badge--${status.badge}">
                            ${data.status === 'completed' ? 'Completed ✓' : (data.status === 'skipped' ? 'Skipped' : 'In Progress')}
                        </span>
                    </dd>
                    
                    <dt>Progress</dt>
                    <dd>${stepText}</dd>
                </dl>
            `;
            
            actions.style.display = 'block';
        } else {
            container.innerHTML = '<p class="admin-text-muted">Tutorial status not available</p>';
            actions.style.display = 'block';
        }
    } catch (error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
        actions.style.display = 'block';
    }
}

async function restartTutorial() {
    if (!confirm('<?= __adminJs('tutorial.freshStartWarning') ?>')) {
        return;
    }
    
    try {
        // Use admin-specific tutorial API
        const response = await fetch('<?= $router->getBaseUrl() ?>/tutorial-api.php?action=update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                step: 1,
                substep: 1,
                status: 'pending'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Clear all tutorial localStorage data
            localStorage.removeItem('quicksite_tutorial_started');
            localStorage.removeItem('quicksite_tutorial_progress');
            QuickSiteAdmin.showToast('Tutorial reset! Redirecting...', 'success');
            setTimeout(() => {
                window.location.href = '<?= $router->url('dashboard') ?>';
            }, 1000);
        } else {
            QuickSiteAdmin.showToast('Failed to reset tutorial: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        QuickSiteAdmin.showToast('Error: ' + error.message, 'error');
    }
}
</script>

<style>
.admin-definition-list {
    margin: 0;
}

.admin-definition-list dt {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin-bottom: var(--space-xs);
}

.admin-definition-list dd {
    margin: 0 0 var(--space-md) 0;
    font-size: var(--font-size-base);
}

.admin-definition-list dd code {
    background: var(--admin-bg);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
}

.admin-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    border-radius: var(--radius-full);
}

.admin-badge--success {
    background: var(--admin-success-bg);
    color: var(--admin-success);
}

.admin-badge--info {
    background: var(--admin-info-bg);
    color: var(--admin-info);
}

.admin-badge--error {
    background: var(--admin-error-bg);
    color: var(--admin-error);
}

.admin-tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}

.admin-tag {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--admin-bg-tertiary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
}

.admin-tag code {
    color: var(--admin-accent);
}

.admin-tag--primary {
    border-color: var(--admin-accent);
    background: var(--admin-accent-muted);
}

.admin-tag__action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    margin-left: var(--space-xs);
    color: var(--admin-text-muted);
    text-decoration: none;
    border-radius: 50%;
    transition: all var(--transition-fast);
}

.admin-tag__action:hover {
    background: var(--admin-error-bg);
    color: var(--admin-error);
}

.admin-tag__badge {
    font-size: var(--font-size-xs);
    padding: 2px 6px;
    background: var(--admin-accent);
    color: white;
    border-radius: var(--radius-sm);
    margin-left: var(--space-xs);
}

.admin-toggle-group {
    margin-bottom: var(--space-sm);
}

.admin-toggle {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    cursor: pointer;
}

.admin-toggle input {
    display: none;
}

.admin-toggle__slider {
    position: relative;
    width: 44px;
    height: 24px;
    background: var(--admin-bg-tertiary);
    border-radius: var(--radius-full);
    transition: background var(--transition-fast);
}

.admin-toggle__slider::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background: white;
    border-radius: 50%;
    transition: transform var(--transition-fast);
}

.admin-toggle input:checked + .admin-toggle__slider {
    background: var(--admin-accent);
}

.admin-toggle input:checked + .admin-toggle__slider::after {
    transform: translateX(20px);
}

.admin-toggle__label {
    font-size: var(--font-size-sm);
    color: var(--admin-text);
}

kbd {
    display: inline-block;
    padding: 2px 6px;
    font-family: var(--font-family-mono);
    font-size: var(--font-size-xs);
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-sm);
}

/* Language list styles */
.admin-lang-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.admin-lang-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    transition: border-color var(--transition-fast);
}

.admin-lang-item--default {
    border-color: var(--admin-accent);
    background: var(--admin-accent-muted);
}

.admin-lang-info {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.admin-lang-code {
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-bold);
    color: var(--admin-accent);
    min-width: 2.5rem;
}

.admin-lang-name {
    font-size: var(--font-size-sm);
    color: var(--admin-text);
}
</style>
