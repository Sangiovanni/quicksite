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
    
    // Shared template helpers
    function renderDefinitionList(items) {
        return `<dl class="admin-definition-list">${items.map(([label, value]) =>
            `<dt>${label}</dt><dd>${value}</dd>`).join('')}</dl>`;
    }
    function showError(container, error) {
        container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
    }
    function showMuted(container, text) {
        container.innerHTML = `<p class="admin-text-muted">${text}</p>`;
    }
    
    /**
     * Initialize the settings page
     */
    function init() {
        loadSystemInfo();
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
                
                container.innerHTML = renderDefinitionList([
                    ['Status', '<span class="admin-badge admin-badge--success">Configured</span>'],
                    ['Providers', `${providerCount} provider${providerCount > 1 ? 's' : ''} configured`],
                    ['Default Provider', defaultName],
                    ['Storage', persist ? 'Persistent (localStorage)' : 'Session only (cleared on tab close)']
                ]);
            } catch (e) {
                container.innerHTML = '<p class="admin-text-muted">No AI providers configured</p>';
            }
        } else {
            container.innerHTML = `
                <p class="admin-text-muted">No AI providers configured.</p>
                <a href="${aiSettingsUrl}" class="admin-btn admin-btn--primary" style="margin-top: var(--space-sm);">
                    ${QuickSiteUtils.iconPlus(16)}
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
                
                container.innerHTML = renderDefinitionList([
                    ['Your Role', `<span class="admin-badge admin-badge--${badgeClass}">${isSuperAdmin ? '⭐ Superadmin' : role}</span>`],
                    ['Access Level', roleDescriptions[role] || 'Custom role'],
                    ['Available Commands', isSuperAdmin ? `All (${commandCount} commands)` : commandCount + ' commands']
                ]) + (!isSuperAdmin ? `
                    <details style="margin-top: var(--space-md);">
                        <summary class="admin-text-muted" style="cursor: pointer;">View your accessible commands</summary>
                        <div style="margin-top: var(--space-sm); max-height: 200px; overflow-y: auto;">
                            <div style="display: flex; flex-wrap: wrap; gap: var(--space-xs);">
                                ${data.commands.map(cmd => `<code style="font-size: var(--font-size-xs);">${cmd}</code>`).join('')}
                            </div>
                        </div>
                    </details>
                    ` : '');
            } else {
                showMuted(container, 'Could not load permissions');
            }
        } catch (error) {
            showError(container, error);
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
                const version = config.quicksiteVersion || 'unknown';
                const totalCommands = info.total || Object.keys(info.commands || {}).length || 0;
                const realBaseUrl = info.base_url || baseUrl;
                
                container.innerHTML = renderDefinitionList([
                    ['Version', `<code>${version}</code>`],
                    ['Total Commands', totalCommands],
                    ['Base URL', `<code>${realBaseUrl}</code>`],
                    ['Server Time', new Date().toLocaleString()]
                ]);
            } else {
                showMuted(container, 'Could not load system info');
            }
        } catch (error) {
            showError(container, error);
        }
    }
    
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
