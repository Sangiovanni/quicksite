<?php
/**
 * AI Settings Page - BYOK (Bring Your Own Key) Configuration
 * 
 * Allows users to configure multiple AI API keys from different providers.
 * Keys are stored in browser sessionStorage only (never on server).
 * 
 * @version 2.0.0 - Multi-provider support
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 32px; height: 32px; margin-right: 8px;">
            <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
            <circle cx="7.5" cy="14.5" r="1.5"/>
            <circle cx="16.5" cy="14.5" r="1.5"/>
        </svg>
        AI Configuration
    </h1>
    <p class="admin-page-header__subtitle">
        Configure your AI provider API keys to enable AI-powered content generation.
        <strong>Your keys stay in your browser only</strong> and are never stored on the server.
    </p>
</div>

<!-- Security Notice -->
<div class="admin-alert admin-alert--info" style="margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
    <div class="admin-alert__content">
        <strong>BYOK Security Model</strong>
        <p style="margin: 0;">
            Your API keys are stored in your browser's sessionStorage. They're sent to the server only during AI requests (to bypass CORS), 
            used once, and immediately discarded. Keys are never written to any file or database.
            Closing your browser tab will clear all keys.
        </p>
    </div>
</div>

<div class="admin-grid admin-grid--cols-1">
    <!-- Configured Providers -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                </svg>
                Configured Providers
            </h2>
            <p class="admin-text-muted" style="margin: 0;">Add API keys for multiple AI providers. Use the star to set your default.</p>
        </div>
        <div class="admin-card__body">
            <div id="configured-providers">
                <!-- Will be populated by JS -->
            </div>
            
            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                <button type="button" class="admin-btn admin-btn--primary" onclick="AISettings.showAddKeyModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Provider Key
                </button>
            </div>
        </div>
    </div>
    
    <!-- Storage Settings -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Storage Settings
            </h2>
        </div>
        <div class="admin-card__body">
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="persist-key" onchange="AISettings.updateStoragePreference()">
                    <span class="admin-checkbox__label">Keep keys after closing browser (localStorage)</span>
                </label>
                <p class="admin-hint">
                    By default, keys are cleared when you close the browser tab (sessionStorage).
                    Enable this to keep them in localStorage (persistent).
                </p>
            </div>
            
            <div id="storage-status" class="admin-text-muted" style="margin-top: var(--space-md);">
                Loading...
            </div>
        </div>
    </div>
    
    <!-- Automation Settings -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/>
                </svg>
                Automation Settings
            </h2>
            <p class="admin-text-muted" style="margin: 0;">Configure automation behavior for AI spec execution.</p>
        </div>
        <div class="admin-card__body">
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="setting-auto-preview" onchange="AISettings.updateAutomationSetting('autoPreview', this.checked)">
                    <span class="admin-checkbox__label">Auto-preview commands when valid JSON is detected</span>
                </label>
                <p class="admin-hint">
                    Automatically show the command preview when valid JSON is pasted or received from the AI.
                </p>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="setting-auto-execute" onchange="AISettings.updateAutomationSetting('autoExecute', this.checked)">
                    <span class="admin-checkbox__label">Auto-execute commands after preview</span>
                </label>
                <p class="admin-hint">
                    Automatically execute commands after the preview. <strong>Use with caution!</strong>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Providers Reference -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                Available Providers
            </h2>
        </div>
        <div class="admin-card__body">
            <div id="providers-list" class="admin-loading">
                <span class="admin-spinner"></span>
                Loading providers...
            </div>
        </div>
    </div>
</div>

<!-- Add Key Modal -->
<div id="add-key-modal" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop" onclick="AISettings.hideAddKeyModal()"></div>
    <div class="admin-modal__content" style="max-width: 500px;">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title">Add API Key</h3>
            <button type="button" class="admin-modal__close" onclick="AISettings.hideAddKeyModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-form-group">
                <label class="admin-label" for="new-api-key">API Key</label>
                <div class="admin-input-group">
                    <input type="password" 
                           id="new-api-key" 
                           class="admin-input admin-input--monospace" 
                           placeholder="Paste your API key here (sk-..., sk-ant-..., AIza...)"
                           autocomplete="off"
                           spellcheck="false">
                    <button type="button" class="admin-btn admin-btn--icon" onclick="toggleNewKeyVisibility()" title="Show/hide key">
                        <svg id="new-eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <p class="admin-hint">
                    Get your key from: 
                    <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a> |
                    <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic</a> |
                    <a href="https://aistudio.google.com/apikey" target="_blank">Google AI</a> 🆓 |
                    <a href="https://console.mistral.ai/api-keys" target="_blank">Mistral</a>
                </p>
                <p class="admin-hint admin-text-muted" style="font-size: 0.85em; margin-top: 0.5em;">
                    🆓 = Free tier available. Google AI offers generous free limits (15 RPM with gemini-1.5-flash). Others require billing.
                </p>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-label">Detected Provider</label>
                <div id="modal-detected-provider" class="admin-text-muted">
                    Enter your API key above to auto-detect the provider
                </div>
            </div>
            
            <div class="admin-form-group" id="modal-provider-select-group" style="display: none;">
                <label class="admin-label" for="modal-provider">Select Provider</label>
                <select id="modal-provider" class="admin-select">
                    <option value="">-- Select provider --</option>
                </select>
                <p class="admin-hint">Provider could not be auto-detected. Please select manually.</p>
            </div>
            
            <div id="modal-test-result" style="display: none; margin-top: var(--space-md);"></div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--secondary" onclick="AISettings.hideAddKeyModal()">
                Cancel
            </button>
            <button type="button" class="admin-btn admin-btn--primary" onclick="AISettings.testAndAddKey()" id="modal-add-btn" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Test & Add Key
            </button>
        </div>
    </div>
</div>

<style>
.provider-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-sm);
}

.provider-card--default {
    border: 2px solid var(--color-primary);
}

.provider-card__info {
    flex: 1;
}

.provider-card__name {
    font-weight: 600;
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.provider-card__meta {
    font-size: 0.875rem;
    color: var(--color-text-muted);
    margin: 0;
}

.provider-card__actions {
    display: flex;
    gap: var(--space-xs);
}

.provider-card__star {
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 4px;
}

.provider-card__star:hover,
.provider-card__star--active {
    color: #ffc107;
}

.admin-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-modal__backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.admin-modal__content {
    position: relative;
    background: var(--color-bg);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    width: 90%;
    max-height: 90vh;
    overflow: auto;
}

.admin-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}

.admin-modal__title {
    margin: 0;
}

.admin-modal__close {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-text-muted);
    padding: 4px;
}

.admin-modal__close:hover {
    color: var(--color-text);
}

.admin-modal__body {
    padding: var(--space-md);
}

.admin-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-md);
    border-top: 1px solid var(--color-border);
}

.no-providers {
    text-align: center;
    padding: var(--space-lg);
    color: var(--color-text-muted);
}

.no-providers svg {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-sm);
    opacity: 0.5;
}

/* Model checkbox group styles */
.model-checkbox-group {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-xs);
}

.model-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.model-checkbox:hover {
    background: var(--color-bg-secondary);
}

.model-checkbox--default {
    background: color-mix(in srgb, var(--color-primary) 10%, transparent);
    border-left: 3px solid var(--color-primary);
}

.model-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    accent-color: var(--color-primary);
}

.model-checkbox__label {
    flex: 1;
    font-size: 0.9rem;
    cursor: pointer;
    user-select: none;
}

.admin-link {
    background: none;
    border: none;
    color: var(--color-primary);
    cursor: pointer;
    padding: 0;
    font-size: inherit;
    text-decoration: underline;
}

.admin-link:hover {
    text-decoration: none;
}
</style>

<script>
// Multi-Provider AI Settings Manager
const AISettings = {
    // Storage keys - v2 uses objects for multi-provider support
    storageKeyV2: 'quicksite_ai_keys_v2',  // { providerId: { key, models, defaultModel } }
    defaultProviderKey: 'quicksite_ai_default_provider',
    persistKey: 'quicksite_ai_persist',
    autoPreviewKey: 'quicksite_ai_auto_preview',
    autoExecuteKey: 'quicksite_ai_auto_execute',
    
    providers: [],
    configuredProviders: {},  // { providerId: { key, name, models, defaultModel } }
    defaultProvider: null,
    
    // Modal state
    modalDetectedProvider: null,
    modalAvailableModels: [],
    
    // Initialize on page load
    init: async function() {
        await this.loadProviders();
        this.loadStoredSettings();
        this.loadAutomationSettings();
        this.setupEventListeners();
        this.renderConfiguredProviders();
        this.updateStorageStatus();
    },
    
    // Load available providers from API
    loadProviders: async function() {
        const container = document.getElementById('providers-list');
        try {
            const result = await QuickSiteAdmin.apiRequest('listAiProviders', 'GET');
            if (result.ok && result.data?.data?.providers) {
                this.providers = result.data.data.providers;
                this.renderProvidersList(container);
                this.populateProviderSelect();
            } else {
                container.innerHTML = '<p class="admin-text-error">Could not load providers</p>';
            }
        } catch (error) {
            container.innerHTML = `<p class="admin-text-error">Error: ${error.message}</p>`;
        }
    },
    
    // Render providers reference list
    renderProvidersList: function(container) {
        let html = '<div class="admin-table-wrapper"><table class="admin-table">';
        html += '<thead><tr><th>Provider</th><th>Key Prefix</th><th>Get API Key</th></tr></thead>';
        html += '<tbody>';
        
        const keyUrls = {
            'openai': 'https://platform.openai.com/api-keys',
            'anthropic': 'https://console.anthropic.com/settings/keys',
            'google': 'https://aistudio.google.com/apikey',
            'mistral': 'https://console.mistral.ai/api-keys'
        };
        
        this.providers.forEach(p => {
            const prefix = p.has_prefix_detection ? 
                (p.id === 'openai' ? '<code>sk-...</code>' : 
                 p.id === 'anthropic' ? '<code>sk-ant-...</code>' : 
                 p.id === 'google' ? '<code>AIza...</code>' : '<em>varies</em>') : 
                '<em>No prefix</em>';
            const url = keyUrls[p.id] || '#';
            
            html += `<tr>
                <td><strong>${p.name}</strong></td>
                <td>${prefix}</td>
                <td><a href="${url}" target="_blank" class="admin-link">Get Key →</a></td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        container.innerHTML = html;
    },
    
    // Populate provider dropdown in modal
    populateProviderSelect: function() {
        const select = document.getElementById('modal-provider');
        select.innerHTML = '<option value="">-- Select provider --</option>';
        this.providers.forEach(p => {
            select.innerHTML += `<option value="${p.id}">${p.name}</option>`;
        });
    },
    
    // Load settings from storage
    loadStoredSettings: function() {
        const persist = localStorage.getItem(this.persistKey) === 'true';
        document.getElementById('persist-key').checked = persist;
        
        const storage = persist ? localStorage : sessionStorage;
        
        // Load configured providers (v2 format)
        const storedData = storage.getItem(this.storageKeyV2);
        if (storedData) {
            try {
                this.configuredProviders = JSON.parse(storedData);
            } catch (e) {
                this.configuredProviders = {};
            }
        }
        
        // Load default provider
        this.defaultProvider = storage.getItem(this.defaultProviderKey);
        
        // If no default but we have providers, set first one as default
        if (!this.defaultProvider && Object.keys(this.configuredProviders).length > 0) {
            this.defaultProvider = Object.keys(this.configuredProviders)[0];
            this.saveDefaultProvider(this.defaultProvider);
        }
        
        // Migration: Check for old v1 format
        this.migrateFromV1(storage);
    },
    
    // Migrate from v1 single-key format
    migrateFromV1: function(storage) {
        const oldKey = storage.getItem('quicksite_ai_key');
        const oldProvider = storage.getItem('quicksite_ai_provider');
        const oldModel = storage.getItem('quicksite_ai_model');
        
        if (oldKey && oldProvider && !this.configuredProviders[oldProvider]) {
            // Migrate to v2
            const providerInfo = this.providers.find(p => p.id === oldProvider);
            this.configuredProviders[oldProvider] = {
                key: oldKey,
                name: providerInfo?.name || oldProvider,
                models: providerInfo?.default_models || [],
                defaultModel: oldModel || null
            };
            this.defaultProvider = oldProvider;
            
            // Save in new format
            this.saveAllProviders();
            this.saveDefaultProvider(oldProvider);
            
            // Clean up old keys
            storage.removeItem('quicksite_ai_key');
            storage.removeItem('quicksite_ai_provider');
            storage.removeItem('quicksite_ai_model');
            
            console.log('Migrated AI settings from v1 to v2');
        }
    },
    
    // Load automation settings
    loadAutomationSettings: function() {
        const autoPreview = localStorage.getItem(this.autoPreviewKey) === 'true';
        const autoExecute = localStorage.getItem(this.autoExecuteKey) === 'true';
        
        const autoPreviewCheckbox = document.getElementById('setting-auto-preview');
        const autoExecuteCheckbox = document.getElementById('setting-auto-execute');
        
        if (autoPreviewCheckbox) autoPreviewCheckbox.checked = autoPreview;
        if (autoExecuteCheckbox) autoExecuteCheckbox.checked = autoExecute;
    },
    
    // Update automation setting
    updateAutomationSetting: function(setting, value) {
        const key = setting === 'autoPreview' ? this.autoPreviewKey : this.autoExecuteKey;
        localStorage.setItem(key, value);
        QuickSiteAdmin.showToast(`${setting === 'autoPreview' ? 'Auto-preview' : 'Auto-execute'} ${value ? 'enabled' : 'disabled'}`, 'success');
    },
    
    // Setup event listeners
    setupEventListeners: function() {
        const keyInput = document.getElementById('new-api-key');
        let debounceTimer;
        
        keyInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                this.onModalKeyInput(e.target.value);
            }, 300);
        });
        
        keyInput.addEventListener('paste', (e) => {
            setTimeout(() => {
                this.onModalKeyInput(e.target.value);
            }, 50);
        });
        
        document.getElementById('modal-provider').addEventListener('change', (e) => {
            this.modalDetectedProvider = e.target.value;
            this.updateModalAddButton();
        });
    },
    
    // Render configured providers
    renderConfiguredProviders: function() {
        const container = document.getElementById('configured-providers');
        const providers = Object.entries(this.configuredProviders);
        
        if (providers.length === 0) {
            container.innerHTML = `
                <div class="no-providers">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
                    </svg>
                    <p>No API keys configured yet.</p>
                    <p>Click "Add Provider Key" to get started.</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        providers.forEach(([providerId, data]) => {
            const isDefault = providerId === this.defaultProvider;
            const modelCount = data.models?.length || 0;
            const maskedKey = data.key ? '••••••••' + data.key.slice(-4) : 'No key';
            
            html += `
                <div class="provider-card ${isDefault ? 'provider-card--default' : ''}">
                    <div class="provider-card__info">
                        <p class="provider-card__name">
                            ${data.name}
                            ${isDefault ? '<span class="admin-badge admin-badge--primary" style="font-size: 0.75rem;">Default</span>' : ''}
                        </p>
                        <p class="provider-card__meta">
                            Key: ${maskedKey} • ${modelCount} models available
                            ${data.defaultModel ? ` • Using: ${data.defaultModel}` : ''}
                        </p>
                    </div>
                    <div class="provider-card__actions">
                        <button type="button" 
                                class="provider-card__star ${isDefault ? 'provider-card__star--active' : ''}" 
                                onclick="AISettings.setDefaultProvider('${providerId}')"
                                title="${isDefault ? 'Default provider' : 'Set as default'}">
                            <svg viewBox="0 0 24 24" fill="${isDefault ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                            </svg>
                        </button>
                        <button type="button" 
                                class="admin-btn admin-btn--sm admin-btn--secondary"
                                onclick="AISettings.editProviderModel('${providerId}')"
                                title="Change model">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                        </button>
                        <button type="button" 
                                class="admin-btn admin-btn--sm admin-btn--danger"
                                onclick="AISettings.removeProvider('${providerId}')"
                                title="Remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    },
    
    // Show add key modal
    showAddKeyModal: function() {
        document.getElementById('add-key-modal').style.display = 'flex';
        document.getElementById('new-api-key').value = '';
        document.getElementById('modal-detected-provider').innerHTML = 'Enter your API key above to auto-detect the provider';
        document.getElementById('modal-detected-provider').className = 'admin-text-muted';
        document.getElementById('modal-provider-select-group').style.display = 'none';
        document.getElementById('modal-test-result').style.display = 'none';
        document.getElementById('modal-add-btn').disabled = true;
        this.modalDetectedProvider = null;
        this.modalAvailableModels = [];
        
        // Focus input
        setTimeout(() => document.getElementById('new-api-key').focus(), 100);
    },
    
    // Hide add key modal
    hideAddKeyModal: function() {
        document.getElementById('add-key-modal').style.display = 'none';
    },
    
    // Handle key input in modal
    onModalKeyInput: function(key) {
        const addBtn = document.getElementById('modal-add-btn');
        const detectedEl = document.getElementById('modal-detected-provider');
        const providerGroup = document.getElementById('modal-provider-select-group');
        
        if (!key || key.length < 20) {
            addBtn.disabled = true;
            detectedEl.innerHTML = 'Enter your API key above to auto-detect the provider';
            detectedEl.className = 'admin-text-muted';
            providerGroup.style.display = 'none';
            return;
        }
        
        // Check if provider already configured
        this.detectModalProvider(key);
    },
    
    // Detect provider in modal
    detectModalProvider: async function(key) {
        const detectedEl = document.getElementById('modal-detected-provider');
        const providerGroup = document.getElementById('modal-provider-select-group');
        
        detectedEl.innerHTML = '<span class="admin-spinner" style="width: 16px; height: 16px;"></span> Detecting provider...';
        
        try {
            const result = await QuickSiteAdmin.apiRequest('detectProvider', 'POST', { key: key });
            
            if (result.ok && result.data?.data?.provider) {
                this.modalDetectedProvider = result.data.data.provider;
                const providerName = result.data.data.name;
                
                // Check if already configured
                if (this.configuredProviders[this.modalDetectedProvider]) {
                    detectedEl.innerHTML = `
                        <span class="admin-badge admin-badge--warning">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-right: 4px;">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            ${providerName} already configured
                        </span>
                        <span class="admin-text-muted" style="margin-left: 8px;">This will replace the existing key</span>
                    `;
                } else {
                    detectedEl.innerHTML = `
                        <span class="admin-badge admin-badge--success">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-right: 4px;">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            ${providerName}
                        </span>
                        <span class="admin-text-muted" style="margin-left: 8px;">Detected from key prefix</span>
                    `;
                }
                detectedEl.className = '';
                providerGroup.style.display = 'none';
            } else {
                this.modalDetectedProvider = null;
                detectedEl.innerHTML = `
                    <span class="admin-badge admin-badge--warning">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-right: 4px;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        Unknown prefix
                    </span>
                `;
                providerGroup.style.display = 'block';
            }
            
            this.updateModalAddButton();
        } catch (error) {
            detectedEl.innerHTML = `<span class="admin-text-error">Detection failed: ${error.message}</span>`;
        }
    },
    
    // Update modal add button state
    updateModalAddButton: function() {
        const key = document.getElementById('new-api-key').value;
        const addBtn = document.getElementById('modal-add-btn');
        addBtn.disabled = !key || key.length < 20 || !this.modalDetectedProvider;
    },
    
    // Test and add key
    testAndAddKey: async function() {
        const key = document.getElementById('new-api-key').value;
        const resultEl = document.getElementById('modal-test-result');
        const addBtn = document.getElementById('modal-add-btn');
        
        if (!key || !this.modalDetectedProvider) return;
        
        addBtn.disabled = true;
        addBtn.innerHTML = '<span class="admin-spinner" style="width: 16px; height: 16px;"></span> Testing...';
        resultEl.style.display = 'block';
        resultEl.innerHTML = '<div class="admin-loading"><span class="admin-spinner"></span> Validating key...</div>';
        
        try {
            const result = await QuickSiteAdmin.apiRequest('testAiKey', 'POST', {
                key: key,
                provider: this.modalDetectedProvider
            });
            
            if (result.ok && result.data?.data?.valid) {
                const data = result.data.data;
                this.modalAvailableModels = data.models || [];
                
                // Add provider
                this.configuredProviders[this.modalDetectedProvider] = {
                    key: key,
                    name: data.name,
                    models: this.modalAvailableModels,
                    defaultModel: this.modalAvailableModels[0] || null
                };
                
                // If first provider, set as default
                if (Object.keys(this.configuredProviders).length === 1) {
                    this.defaultProvider = this.modalDetectedProvider;
                    this.saveDefaultProvider(this.modalDetectedProvider);
                }
                
                this.saveAllProviders();
                this.renderConfiguredProviders();
                this.updateStorageStatus();
                
                // Close modal and show success
                this.hideAddKeyModal();
                QuickSiteAdmin.showToast(`${data.name} configured successfully with ${this.modalAvailableModels.length} models`, 'success');
                
            } else {
                const error = result.data?.data?.error || result.data?.message || 'Unknown error';
                resultEl.innerHTML = `
                    <div class="admin-alert admin-alert--error">
                        <strong>Invalid API Key</strong>
                        <p style="margin: 0;">${result.data?.message || error}</p>
                    </div>
                `;
            }
        } catch (error) {
            resultEl.innerHTML = `
                <div class="admin-alert admin-alert--error">
                    <strong>Connection Error</strong>
                    <p style="margin: 0;">${error.message}</p>
                </div>
            `;
        }
        
        addBtn.disabled = false;
        addBtn.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            Test & Add Key
        `;
    },
    
    // Set default provider
    setDefaultProvider: function(providerId) {
        this.defaultProvider = providerId;
        this.saveDefaultProvider(providerId);
        this.renderConfiguredProviders();
        
        const providerName = this.configuredProviders[providerId]?.name || providerId;
        QuickSiteAdmin.showToast(`${providerName} set as default`, 'success');
    },
    
    // Edit provider model selection
    editProviderModel: function(providerId) {
        const provider = this.configuredProviders[providerId];
        if (!provider || !provider.models || provider.models.length === 0) {
            QuickSiteAdmin.showToast('No models available for this provider', 'warning');
            return;
        }
        
        const models = provider.models;
        const currentModel = provider.defaultModel || models[0];
        const enabledModels = provider.enabledModels || models; // Default: all enabled
        
        // Build checkboxes for each model
        let checkboxes = models.map(m => {
            const isEnabled = enabledModels.includes(m);
            const isDefault = m === currentModel;
            return `
                <label class="model-checkbox ${isDefault ? 'model-checkbox--default' : ''}">
                    <input type="checkbox" value="${m}" ${isEnabled ? 'checked' : ''} ${isDefault ? 'data-default="true"' : ''}>
                    <span class="model-checkbox__label">
                        ${m}
                        ${isDefault ? '<span class="admin-badge admin-badge--primary" style="font-size: 0.7rem; margin-left: 4px;">default</span>' : ''}
                    </span>
                </label>
            `;
        }).join('');
        
        const html = `
            <div class="admin-modal" id="model-select-modal" style="display: flex;">
                <div class="admin-modal__backdrop" onclick="document.getElementById('model-select-modal').remove()"></div>
                <div class="admin-modal__content" style="max-width: 500px;">
                    <div class="admin-modal__header">
                        <h3 class="admin-modal__title">Configure Models - ${provider.name}</h3>
                        <button type="button" class="admin-modal__close" onclick="document.getElementById('model-select-modal').remove()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="admin-modal__body">
                        <p class="admin-text-muted" style="margin-bottom: var(--space-md);">
                            Check the models you want to show in the AI Integration page. 
                            Click a model name to set it as default.
                        </p>
                        <div class="admin-form-group">
                            <div class="model-checkbox-group" id="model-checkbox-group">
                                ${checkboxes}
                            </div>
                            <p class="admin-hint" style="margin-top: var(--space-sm);">
                                <button type="button" class="admin-link" onclick="AISettings.toggleAllModels(true)">Select All</button>
                                &nbsp;|&nbsp;
                                <button type="button" class="admin-link" onclick="AISettings.toggleAllModels(false)">Deselect All</button>
                            </p>
                        </div>
                    </div>
                    <div class="admin-modal__footer">
                        <button type="button" class="admin-btn admin-btn--secondary" onclick="document.getElementById('model-select-modal').remove()">
                            Cancel
                        </button>
                        <button type="button" class="admin-btn admin-btn--primary" onclick="AISettings.saveProviderModels('${providerId}')">
                            Save
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', html);
        
        // Add click handlers for setting default
        document.querySelectorAll('.model-checkbox__label').forEach(label => {
            label.addEventListener('click', (e) => {
                if (e.target.tagName !== 'INPUT') {
                    const checkbox = label.parentElement.querySelector('input');
                    const model = checkbox.value;
                    this.setModalDefaultModel(model);
                }
            });
        });
    },
    
    // Set default model in modal
    setModalDefaultModel: function(model) {
        document.querySelectorAll('.model-checkbox').forEach(el => {
            el.classList.remove('model-checkbox--default');
            el.querySelector('input').removeAttribute('data-default');
            const badge = el.querySelector('.admin-badge');
            if (badge) badge.remove();
        });
        
        const checkbox = document.querySelector(`.model-checkbox input[value="${model}"]`);
        if (checkbox) {
            checkbox.setAttribute('data-default', 'true');
            checkbox.checked = true; // Auto-enable if setting as default
            checkbox.parentElement.classList.add('model-checkbox--default');
            
            const label = checkbox.nextElementSibling;
            label.insertAdjacentHTML('beforeend', '<span class="admin-badge admin-badge--primary" style="font-size: 0.7rem; margin-left: 4px;">default</span>');
        }
    },
    
    // Toggle all models in modal
    toggleAllModels: function(checked) {
        document.querySelectorAll('#model-checkbox-group input[type="checkbox"]').forEach(cb => {
            // Don't uncheck the default model
            if (!checked && cb.getAttribute('data-default') === 'true') return;
            cb.checked = checked;
        });
    },
    
    // Save provider models (enabled list + default)
    saveProviderModels: function(providerId) {
        const checkboxes = document.querySelectorAll('#model-checkbox-group input[type="checkbox"]');
        const enabledModels = [];
        let defaultModel = null;
        
        checkboxes.forEach(cb => {
            if (cb.checked) {
                enabledModels.push(cb.value);
            }
            if (cb.getAttribute('data-default') === 'true') {
                defaultModel = cb.value;
            }
        });
        
        // Ensure at least one model is enabled
        if (enabledModels.length === 0) {
            QuickSiteAdmin.showToast('Please enable at least one model', 'warning');
            return;
        }
        
        // Ensure default is in enabled list
        if (defaultModel && !enabledModels.includes(defaultModel)) {
            enabledModels.unshift(defaultModel);
        }
        
        // If no default set, use first enabled
        if (!defaultModel) {
            defaultModel = enabledModels[0];
        }
        
        this.configuredProviders[providerId].enabledModels = enabledModels;
        this.configuredProviders[providerId].defaultModel = defaultModel;
        this.saveAllProviders();
        this.renderConfiguredProviders();
        
        document.getElementById('model-select-modal').remove();
        QuickSiteAdmin.showToast(`${enabledModels.length} models enabled, default: ${defaultModel}`, 'success');
    },
    
    // Remove provider
    removeProvider: function(providerId) {
        const providerName = this.configuredProviders[providerId]?.name || providerId;
        if (!confirm(`Remove ${providerName}? You will need to enter the API key again to use it.`)) {
            return;
        }
        
        delete this.configuredProviders[providerId];
        
        // If removed default, set new default
        if (this.defaultProvider === providerId) {
            const remaining = Object.keys(this.configuredProviders);
            this.defaultProvider = remaining.length > 0 ? remaining[0] : null;
            this.saveDefaultProvider(this.defaultProvider);
        }
        
        this.saveAllProviders();
        this.renderConfiguredProviders();
        this.updateStorageStatus();
        
        QuickSiteAdmin.showToast(`${providerName} removed`, 'success');
    },
    
    // Save all providers to storage
    saveAllProviders: function() {
        const persist = document.getElementById('persist-key').checked;
        const storage = persist ? localStorage : sessionStorage;
        storage.setItem(this.storageKeyV2, JSON.stringify(this.configuredProviders));
        (persist ? sessionStorage : localStorage).removeItem(this.storageKeyV2);
    },
    
    // Save default provider
    saveDefaultProvider: function(providerId) {
        const persist = document.getElementById('persist-key').checked;
        const storage = persist ? localStorage : sessionStorage;
        if (providerId) {
            storage.setItem(this.defaultProviderKey, providerId);
        } else {
            storage.removeItem(this.defaultProviderKey);
        }
        (persist ? sessionStorage : localStorage).removeItem(this.defaultProviderKey);
    },
    
    // Update storage status display
    updateStorageStatus: function() {
        const statusEl = document.getElementById('storage-status');
        const persist = document.getElementById('persist-key').checked;
        const count = Object.keys(this.configuredProviders).length;
        
        if (count > 0) {
            const defaultName = this.configuredProviders[this.defaultProvider]?.name || 'Not set';
            statusEl.innerHTML = `
                <span class="admin-badge admin-badge--success">${count} Provider${count > 1 ? 's' : ''} Configured</span>
                <span style="margin-left: 8px;">
                    Default: <strong>${defaultName}</strong> |
                    Storage: <strong>${persist ? 'localStorage (persistent)' : 'sessionStorage (session only)'}</strong>
                </span>
            `;
        } else {
            statusEl.innerHTML = `No API keys currently stored. Storage: ${persist ? 'localStorage' : 'sessionStorage'}`;
        }
    },
    
    // Update storage preference
    updateStoragePreference: function() {
        const persist = document.getElementById('persist-key').checked;
        localStorage.setItem(this.persistKey, persist);
        
        // Move existing data
        const fromStorage = persist ? sessionStorage : localStorage;
        const toStorage = persist ? localStorage : sessionStorage;
        
        const data = fromStorage.getItem(this.storageKeyV2);
        const defaultProvider = fromStorage.getItem(this.defaultProviderKey);
        
        if (data) {
            toStorage.setItem(this.storageKeyV2, data);
            fromStorage.removeItem(this.storageKeyV2);
        }
        if (defaultProvider) {
            toStorage.setItem(this.defaultProviderKey, defaultProvider);
            fromStorage.removeItem(this.defaultProviderKey);
        }
        
        this.updateStorageStatus();
        QuickSiteAdmin.showToast(`Keys will be stored in ${persist ? 'localStorage (persistent)' : 'sessionStorage (session only)'}`, 'info');
    },
    
    // Get active provider config (for use by spec page)
    getActiveProvider: function() {
        if (!this.defaultProvider || !this.configuredProviders[this.defaultProvider]) {
            return null;
        }
        return {
            id: this.defaultProvider,
            ...this.configuredProviders[this.defaultProvider]
        };
    },
    
    // Get specific provider config
    getProvider: function(providerId) {
        if (!this.configuredProviders[providerId]) {
            return null;
        }
        return {
            id: providerId,
            ...this.configuredProviders[providerId]
        };
    }
};

// Global helper function for other pages to get AI config
window.getAIConfig = function() {
    const persist = localStorage.getItem('quicksite_ai_persist') === 'true';
    const storage = persist ? localStorage : sessionStorage;
    
    const storedData = storage.getItem('quicksite_ai_keys_v2');
    const defaultProvider = storage.getItem('quicksite_ai_default_provider');
    
    if (!storedData || !defaultProvider) {
        return null;
    }
    
    try {
        const providers = JSON.parse(storedData);
        const provider = providers[defaultProvider];
        if (!provider) return null;
        
        return {
            providerId: defaultProvider,
            key: provider.key,
            name: provider.name,
            model: provider.defaultModel,
            models: provider.models
        };
    } catch (e) {
        return null;
    }
};

// Toggle key visibility in modal
function toggleNewKeyVisibility() {
    const input = document.getElementById('new-api-key');
    const icon = document.getElementById('new-eye-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    AISettings.init();
});
</script>
