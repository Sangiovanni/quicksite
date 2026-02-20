<?php
/**
 * AI Settings Page - BYOK (Bring Your Own Key) Configuration
 * 
 * Allows users to configure multiple AI API keys from different providers.
 * Keys are stored in browser sessionStorage only (never on server).
 * 
 * @version 2.1.0 - External JS/CSS
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai-settings.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/ai-settings.js') ?>"></script>

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
