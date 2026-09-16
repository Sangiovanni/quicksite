/**
 * Settings Page JavaScript
 *
 * Handles loading and displaying system info, config, routes, languages,
 * preferences, permissions, and AI configuration status.
 *
 * Strings come from window.QS_SETTINGS_I18N, emitted by settings.php: the
 * `settings` and `common` translation sub-trees under the same dot paths PHP
 * uses. Read it through t() below, never directly.
 *
 * DOM is built with createElement + textContent through QSDom and named
 * _render* helpers, per the CLAUDE.md HTML-in-JS hygiene rule.
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

    /**
     * Resolve one admin string by its FULL dot path.
     *
     * A path that resolves to nothing returns THE PATH ITSELF, so an
     * untranslated string is visible on screen and findable by a scan
     * instead of being hidden behind an English fallback.
     *
     * @param {string} path       e.g. 'settings.ai.configured'
     * @param {Object} [params]   :name markers to substitute, as PHP's t() does
     * @returns {string}
     */
    function t(path, params) {
        let node = window.QS_SETTINGS_I18N || {};
        for (const part of String(path).split('.')) {
            if (node === null || typeof node !== 'object' || !(part in node)) return path;
            node = node[part];
        }
        if (typeof node !== 'string') return path;
        let value = node;
        if (params) {
            for (const name of Object.keys(params)) {
                value = value.split(':' + name).join(String(params[name]));
            }
        }
        return value;
    }

    // AI Storage Keys
    const AI_STORAGE_KEYS = {
        keysV2: QuickSiteStorageKeys.aiKeysV2,
        defaultProvider: QuickSiteStorageKeys.aiDefaultProvider,
        persist: QuickSiteStorageKeys.aiPersist,
        autoExecute: QuickSiteStorageKeys.aiAutoExecute
    };

    // ========================================================================
    // Render helpers — each returns ONE Element (CLAUDE.md, ruling 8)
    // ========================================================================

    /**
     * A <dl> of label/value rows.
     * @param {Array<[string, Node|string]>} items  value may be a node or text
     * @returns {HTMLElement}
     */
    function _renderDefinitionList(items) {
        const dl = QSDom.el('dl', { class: 'admin-definition-list' });
        items.forEach(([label, value]) => {
            dl.appendChild(QSDom.el('dt', { text: label }));
            dl.appendChild(QSDom.el('dd', null, [
                typeof value === 'string' ? document.createTextNode(value) : value
            ]));
        });
        return dl;
    }

    /** A coloured badge. @returns {HTMLElement} */
    function _renderBadge(text, variant) {
        return QSDom.el('span', { class: 'admin-badge admin-badge--' + variant, text: text });
    }

    /**
     * The collapsible list of commands a non-superadmin may run.
     * @param {Array<string>} commands
     * @returns {HTMLElement} one <details>
     */
    function _renderCommandList(commands) {
        const chips = QSDom.el('div', {
            style: 'display: flex; flex-wrap: wrap; gap: var(--space-xs);'
        });
        commands.forEach(cmd => {
            chips.appendChild(QSDom.el('code', {
                style: 'font-size: var(--font-size-xs);',
                text: cmd
            }));
        });
        return QSDom.el('details', { style: 'margin-top: var(--space-md);' }, [
            QSDom.el('summary', {
                class: 'admin-text-muted',
                style: 'cursor: pointer;',
                text: t('settings.permissions.viewAccessible')
            }),
            QSDom.el('div', {
                style: 'margin-top: var(--space-sm); max-height: 200px; overflow-y: auto;'
            }, [chips])
        ]);
    }

    /** Replace a container's contents with one element. */
    function _replace(container, element) {
        QSDom.clear(container);
        container.appendChild(element);
    }

    function showError(container, error) {
        _replace(container, QSDom.el('p', {
            class: 'admin-text-error',
            text: t('settings.errors.generic', { message: error.message })
        }));
    }
    function showMuted(container, text) {
        _replace(container, QSDom.el('p', { class: 'admin-text-muted', text: text }));
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
        const autoExecuteEl = document.getElementById('ai-auto-execute');
        if (autoExecuteEl) autoExecuteEl.checked = localStorage.getItem(AI_STORAGE_KEYS.autoExecute) !== 'false';
        
        // Check for configured providers
        const storedData = storage.getItem(AI_STORAGE_KEYS.keysV2);
        const defaultProvider = storage.getItem(AI_STORAGE_KEYS.defaultProvider);
        
        if (storedData && defaultProvider) {
            try {
                const providers = JSON.parse(storedData);
                const providerCount = Object.keys(providers).length;
                const defaultName = providers[defaultProvider]?.name || defaultProvider;
                
                _replace(container, _renderDefinitionList([
                    [t('settings.ai.status'), _renderBadge(t('settings.ai.configured'), 'success')],
                    [t('settings.ai.providers'), t(providerCount > 1
                        ? 'settings.ai.providersCount'
                        : 'settings.ai.providersCountOne', { count: providerCount })],
                    [t('settings.ai.defaultProvider'), defaultName],
                    [t('settings.ai.storage'), persist
                        ? t('settings.ai.storagePersistent')
                        : t('settings.ai.storageSession')]
                ]));
            } catch (e) {
                showMuted(container, t('settings.ai.noneConfigured'));
            }
        } else {
            QSDom.clear(container);
            container.appendChild(QSDom.el('p', {
                class: 'admin-text-muted',
                text: t('settings.ai.noneConfigured')
            }));
            container.appendChild(QSDom.el('a', {
                href: aiSettingsUrl,
                class: 'admin-btn admin-btn--primary',
                style: 'margin-top: var(--space-sm);'
            }, [
                QSDom.iconEl(QuickSiteUtils.ICON_PATHS.plus, 16),
                ' ' + t('settings.ai.addApiKey')
            ]));
        }
    }
    
    /**
     * Update AI automation settings
     */
    window.updateAiAutomation = function(setting, value) {
        if (setting !== 'autoExecute') return;
        localStorage.setItem(AI_STORAGE_KEYS.autoExecute, value);
        QuickSiteAdmin.showToast(t(value
            ? 'settings.ai.autoExecuteEnabled'
            : 'settings.ai.autoExecuteDisabled'), 'success');
    };
    
    /**
     * Load permissions info
     */
    async function loadPermissionsInfo() {
        const container = document.getElementById('permissions-info');
        if (!container) return;
        
        try {
            const result = await QuickSiteAdmin.accountRequest('permissions', 'GET');
            
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
                
                // Role descriptions, by the role name the API returns
                const ROLE_KEYS = {
                    '*': 'settings.permissions.role.all',
                    'admin': 'settings.permissions.role.admin',
                    'developer': 'settings.permissions.role.developer',
                    'designer': 'settings.permissions.role.designer',
                    'editor': 'settings.permissions.role.editor',
                    'viewer': 'settings.permissions.role.viewer'
                };

                QSDom.clear(container);
                container.appendChild(_renderDefinitionList([
                    [t('settings.permissions.yourRole'), _renderBadge(
                        isSuperAdmin ? t('settings.permissions.superadmin') : role, badgeClass)],
                    [t('settings.permissions.accessLevel'), ROLE_KEYS[role]
                        ? t(ROLE_KEYS[role])
                        : t('settings.permissions.customRole')],
                    [t('settings.permissions.availableCommands'), isSuperAdmin
                        ? t('settings.permissions.allCommands', { count: commandCount })
                        : t('settings.permissions.commandCount', { count: commandCount })]
                ]));
                if (!isSuperAdmin) {
                    container.appendChild(_renderCommandList(data.commands || []));
                }
            } else {
                showMuted(container, t('settings.errors.permissionsFailed'));
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
                
                _replace(container, _renderDefinitionList([
                    [t('settings.sysInfo.version'), QSDom.el('code', { text: version })],
                    [t('settings.sysInfo.totalCommands'), String(totalCommands)],
                    [t('settings.sysInfo.baseUrl'), QSDom.el('code', { text: realBaseUrl })],
                    [t('settings.sysInfo.serverTime'), new Date().toLocaleString()]
                ]));
            } else {
                showMuted(container, t('settings.errors.sysInfoFailed'));
            }
        } catch (error) {
            showError(container, error);
        }
    }
    
    /**
     * Load user preferences
     */
    function loadPreferences() {
        // Through getPref for the same reason savePreferences goes through
        // setPref: one store, one cache, one API. Reading the key by hand here
        // would answer from localStorage while everything else answers from
        // the cache.
        const shortcutsEl = document.getElementById('pref-shortcuts');
        const toastEl = document.getElementById('pref-toast-duration');

        if (shortcutsEl) shortcutsEl.checked = QuickSiteUtils.getPref('shortcuts', true) !== false;
        if (toastEl) toastEl.value = QuickSiteUtils.getPref('toastDuration', '4000');
    }
    
    /**
     * Save user preferences
     */
    window.savePreferences = function() {
        // Through QuickSiteUtils.setPref, never straight to localStorage.
        // setPref updates the in-memory cache getPref serves from AND
        // persists. Writing the key by hand persists without telling the
        // cache, so every reader keeps answering with the old value until
        // the page is reloaded.
        QuickSiteUtils.setPref('shortcuts',
            document.getElementById('pref-shortcuts')?.checked ?? true);
        QuickSiteUtils.setPref('toastDuration',
            document.getElementById('pref-toast-duration')?.value || '4000');

        QuickSiteAdmin.showToast(t('settings.toast.preferencesSaved'), 'success');
    };
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
