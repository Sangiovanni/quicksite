/**
 * Settings Page JavaScript
 * 
 * Handles loading and displaying system info, config, routes, languages, 
 * preferences, permissions, and AI configuration status.
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';
    
    // Get config from PHP
    const config = window.QUICKSITE_CONFIG || {};
    const baseUrl = config.baseUrl || '';
    const commandUrl = config.commandUrl || '';
    const aiSettingsUrl = config.aiSettingsUrl || '';
    const translations = config.translations || {};
    
    // AI Storage Keys
    const AI_STORAGE_KEYS = {
        keysV2: 'quicksite_ai_keys_v2',
        defaultProvider: 'quicksite_ai_default_provider',
        persist: 'quicksite_ai_persist',
        autoPreview: 'quicksite_ai_auto_preview',
        autoExecute: 'quicksite_ai_auto_execute'
    };
    
    /**
     * Initialize the settings page
     */
    function init() {
        loadSystemInfo();
        loadConfigInfo();
        loadRoutes();
        loadLanguages();
        loadPreferences();
        loadPermissionsInfo();
        loadAiConfigStatus();
    }
    
    /**
     * Load AI configuration status
     */
    function loadAiConfigStatus() {
        const container = document.getElementById('ai-config-status');
        if (!container) return;
        
        const persist = localStorage.getItem(AI_STORAGE_KEYS.persist) === 'true';
        const storage = persist ? localStorage : sessionStorage;
        
        // Load automation settings
        const autoPreviewEl = document.getElementById('ai-auto-preview');
        const autoExecuteEl = document.getElementById('ai-auto-execute');
        if (autoPreviewEl) autoPreviewEl.checked = localStorage.getItem(AI_STORAGE_KEYS.autoPreview) === 'true';
        if (autoExecuteEl) autoExecuteEl.checked = localStorage.getItem(AI_STORAGE_KEYS.autoExecute) === 'true';
        
        // Check for configured providers
        const storedData = storage.getItem(AI_STORAGE_KEYS.keysV2);
        const defaultProvider = storage.getItem(AI_STORAGE_KEYS.defaultProvider);
        
        if (storedData && defaultProvider) {
            try {
                const providers = JSON.parse(storedData);
                const providerCount = Object.keys(providers).length;
                const defaultName = providers[defaultProvider]?.name || defaultProvider;
                
                container.innerHTML = `
                    <dl class="admin-definition-list">
                        <dt>Status</dt>
                        <dd><span class="admin-badge admin-badge--success">Configured</span></dd>
                        
                        <dt>Providers</dt>
                        <dd>${providerCount} provider${providerCount > 1 ? 's' : ''} configured</dd>
                        
                        <dt>Default Provider</dt>
                        <dd>${defaultName}</dd>
                        
                        <dt>Storage</dt>
                        <dd>${persist ? 'Persistent (localStorage)' : 'Session only (cleared on tab close)'}</dd>
                    </dl>
                `;
            } catch (e) {
                container.innerHTML = '<p class="admin-text-muted">No AI providers configured</p>';
            }
        } else {
            container.innerHTML = `
                <p class="admin-text-muted">No AI providers configured.</p>
                <a href="${aiSettingsUrl}" class="admin-btn admin-btn--primary" style="margin-top: var(--space-sm);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add API Key
                </a>
            `;
        }
    }
    
    /**
     * Update AI automation settings
     */
    window.updateAiAutomation = function(setting, value) {
        const key = setting === 'autoPreview' ? AI_STORAGE_KEYS.autoPreview : AI_STORAGE_KEYS.autoExecute;
        localStorage.setItem(key, value);
        QuickSiteAdmin.showToast(`${setting === 'autoPreview' ? 'Auto-preview' : 'Auto-execute'} ${value ? 'enabled' : 'disabled'}`, 'success');
    };
    
    /**
     * Load permissions info
     */
    async function loadPermissionsInfo() {
        const container = document.getElementById('permissions-info');
        if (!container) return;
        
        try {
            const result = await QuickSiteAdmin.apiRequest('getMyPermissions', 'GET');
            
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
    
    /**
     * Load system info
     */
    async function loadSystemInfo() {
        const container = document.getElementById('system-info');
        if (!container) return;
        
        try {
            const result = await QuickSiteAdmin.apiRequest('help');
            
            if (result.ok && result.data.data) {
                const info = result.data.data;
                const version = config.quicksiteVersion || info.version || '1.6.0';
                const realBaseUrl = info.base_url ? info.base_url.replace(/\/+/g, '/').replace(':/', '://') : baseUrl;
                
                container.innerHTML = `
                    <dl class="admin-definition-list">
                        <dt>API Version</dt>
                        <dd><code>${version}</code></dd>
                        
                        <dt>Total Commands</dt>
                        <dd>${info.total || Object.keys(info.commands || {}).length || 0}</dd>
                        
                        <dt>Base URL</dt>
                        <dd><code>${realBaseUrl}</code></dd>
                        
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
    
    /**
     * Load config info
     */
    async function loadConfigInfo() {
        const container = document.getElementById('config-info');
        if (!container) return;
        
        try {
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
    
    /**
     * Load routes list
     */
    async function loadRoutes() {
        const container = document.getElementById('routes-list');
        if (!container) return;
        
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
                            <a href="${commandUrl}/deleteRoute?route=${encodeURIComponent(route)}" 
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
    
    /**
     * Load languages list
     */
    async function loadLanguages() {
        const container = document.getElementById('languages-list');
        if (!container) return;
        
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
    
    /**
     * Set default language
     */
    window.setDefaultLang = async function(langCode) {
        if (!confirm(`Set "${langCode.toUpperCase()}" as the default language?`)) {
            return;
        }
        
        try {
            const result = await QuickSiteAdmin.apiRequest('setDefaultLang', 'PATCH', { lang: langCode });
            
            if (result.ok) {
                QuickSiteAdmin.showToast(result.data.message || 'Default language updated', 'success');
                loadLanguages();
                loadSystemInfo();
            } else {
                QuickSiteAdmin.showToast(result.data.message || 'Failed to update default language', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast(`Error: ${error.message}`, 'error');
        }
    };
    
    /**
     * Load user preferences
     */
    function loadPreferences() {
        const prefs = JSON.parse(localStorage.getItem('quicksite_admin_prefs') || '{}');
        
        const shortcutsEl = document.getElementById('pref-shortcuts');
        const confirmEl = document.getElementById('pref-confirm');
        const toastEl = document.getElementById('pref-toast-duration');
        
        if (shortcutsEl) shortcutsEl.checked = prefs.shortcuts !== false;
        if (confirmEl) confirmEl.checked = prefs.confirmDestructive !== false;
        if (toastEl) toastEl.value = prefs.toastDuration || '4000';
    }
    
    /**
     * Save user preferences
     */
    window.savePreferences = function() {
        const prefs = {
            shortcuts: document.getElementById('pref-shortcuts')?.checked ?? true,
            confirmDestructive: document.getElementById('pref-confirm')?.checked ?? true,
            toastDuration: document.getElementById('pref-toast-duration')?.value || '4000'
        };
        
        localStorage.setItem('quicksite_admin_prefs', JSON.stringify(prefs));
        
        // Update QuickSiteAdmin.prefs immediately
        if (window.QuickSiteAdmin) {
            QuickSiteAdmin.prefs = prefs;
        }
        
        QuickSiteAdmin.showToast('Preferences saved', 'success');
    };
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
