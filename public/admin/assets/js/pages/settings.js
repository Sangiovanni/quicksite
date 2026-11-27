/**
 * Settings Page JavaScript
 *
 * Handles loading and displaying system info, config, routes, languages,
 * preferences, permissions, and AI configuration status.
 *
 * Its words are the ones settings.php hands (QSPanelWords), each read by its
 * key's whole path.
 *
 * DOM is built with createElement + textContent through QSDom and named
 * _render* helpers, per the CLAUDE.md HTML-in-JS hygiene rule.
 */

(function() {
    'use strict';

    // Get config from PHP
    const config = window.QUICKSITE_CONFIG || {};
    const aiSettingsUrl = config.aiSettingsUrl || '';

    // AI Storage Keys
    const AI_STORAGE_KEYS = {
        keysV2: QuickSiteStorageKeys.aiKeysV2,
        defaultProvider: QuickSiteStorageKeys.aiDefaultProvider,
        persist: QuickSiteStorageKeys.aiPersist,
        autoExecute: QuickSiteStorageKeys.aiAutoExecute
    };

    // ========================================================================
    // Render helpers — each returns ONE Element (CLAUDE.md)
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
                style: 'cursor: pointer;',
                text: QSPanelWords.t('settings.permissions.viewAccessible')
            }),
            QSDom.el('div', {
                style: 'margin-top: var(--space-sm); max-height: 200px; overflow-y: auto;'
            }, [chips])
        ]);
    }

    /** Replace a container's contents with one element. */
    function _replace(container, element) {
        QSDom.clear(container);
        // Loaded: no longer the spinner's centred box.
        container.classList.remove('admin-loading');
        container.appendChild(element);
    }

    function showError(container, error) {
        _replace(container, QSDom.el('p', {
            text: QSPanelWords.t('common.errorDetail', { message: error.message })
        }));
    }
    function showMuted(container, text) {
        _replace(container, QSDom.el('p', { text: text }));
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
        // "Run without stopping" is off until switched on (the AI tools runner reads it the same way).
        if (autoExecuteEl) autoExecuteEl.checked = localStorage.getItem(AI_STORAGE_KEYS.autoExecute) === 'true';
        
        // Check for configured providers
        const storedData = storage.getItem(AI_STORAGE_KEYS.keysV2);
        const defaultProvider = storage.getItem(AI_STORAGE_KEYS.defaultProvider);
        
        if (storedData && defaultProvider) {
            try {
                const providers = JSON.parse(storedData);
                const providerCount = Object.keys(providers).length;
                const defaultName = providers[defaultProvider]?.name || defaultProvider;
                
                _replace(container, _renderDefinitionList([
                    [QSPanelWords.t('settings.ai.status'), _renderBadge(QSPanelWords.t('settings.ai.configured'), 'success')],
                    [QSPanelWords.t('settings.ai.providers'), QSPanelWords.count('settings.ai.providersConfiguredCount', providerCount)],
                    [QSPanelWords.t('settings.ai.defaultProvider'), defaultName],
                    [QSPanelWords.t('settings.ai.storage'), QSPanelWords.t(persist
                        ? 'settings.ai.storagePersistent'
                        : 'settings.ai.storageSession')]
                ]));
            } catch (e) {
                showMuted(container, QSPanelWords.t('settings.ai.noneConfigured'));
            }
        } else {
            QSDom.clear(container);
            container.appendChild(QSDom.el('p', {
                text: QSPanelWords.t('settings.ai.noneConfigured')
            }));
            container.appendChild(QSDom.el('a', {
                href: aiSettingsUrl,
                class: 'admin-btn admin-btn--primary',
                style: 'margin-top: var(--space-sm);'
            }, [
                QSDom.iconEl(QuickSiteUtils.ICON_PATHS.plus, 16),
                ' ' + QSPanelWords.t('settings.ai.addApiKey')
            ]));
        }
    }
    
    /**
     * Update AI automation settings
     */
    window.updateAiAutomation = function(setting, value) {
        if (setting !== 'autoExecute') return;
        localStorage.setItem(AI_STORAGE_KEYS.autoExecute, value);
        QuickSiteAdmin.showToast(QSPanelWords.t(value
            ? 'settings.ai.runWithoutStoppingOn'
            : 'settings.ai.runWithoutStoppingOff'), 'success');
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
                // A role the table does not name is a custom role.
                const accessKey = ROLE_KEYS[role] || 'settings.permissions.customRole';

                QSDom.clear(container);
                // Loaded: no longer the spinner's centred box (it would lay the list and the commands side by side).
                container.classList.remove('admin-loading');
                container.appendChild(_renderDefinitionList([
                    [QSPanelWords.t('settings.permissions.yourRole'), _renderBadge(
                        isSuperAdmin ? QSPanelWords.t('settings.permissions.superadmin') : QuickSiteUtils.roleName(role), badgeClass)],
                    [QSPanelWords.t('settings.permissions.accessLevel'), QSPanelWords.t(accessKey)],
                    [QSPanelWords.t('settings.permissions.availableCommands'), QSPanelWords.count(isSuperAdmin
                        ? 'settings.permissions.allCommandsCount'
                        : 'settings.permissions.commandsCount', commandCount)]
                ]));
                if (!isSuperAdmin) {
                    container.appendChild(_renderCommandList(data.commands || []));
                }
            } else {
                showMuted(container, QSPanelWords.t('settings.errors.permissionsFailed'));
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
                const version = typeof config.quicksiteVersion === 'string'
                    ? config.quicksiteVersion
                    : QSPanelWords.t('settings.sysInfo.versionUnknown');
                const totalCommands = info.total || Object.keys(info.commands || {}).length || 0;

                _replace(container, _renderDefinitionList([
                    [QSPanelWords.t('settings.sysInfo.version'), QSDom.el('code', { text: version })],
                    [QSPanelWords.t('settings.sysInfo.totalCommands'), String(totalCommands)]
                ]));
            } else {
                showMuted(container, QSPanelWords.t('settings.errors.sysInfoFailed'));
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

        QuickSiteAdmin.showToast(QSPanelWords.t('settings.toast.preferencesSaved'), 'success');
    };
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
