/**
 * AI Editor Page JavaScript
 * 
 * Handles spec editing: JSON validation, preview, save functionality.
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';
    
    // Get config from PHP
    const config = window.QUICKSITE_CONFIG || {};
    const apiBaseUrl = config.apiBaseUrl || '';
    const token = config.token || '';
    const isNew = config.isNew || false;
    const originalSpecId = config.originalSpecId || '';
    const translations = config.translations || {};
    
    // Helper for translations
    function t(key, fallback) {
        return translations[key] || fallback;
    }
    
    // DOM elements
    let jsonEditor;
    let templateEditor;
    let jsonValidation;
    let templatePreview;
    let previewBtn;
    let saveBtn;
    
    let validationTimeout;
    
    /**
     * Initialize the AI editor page
     */
    function init() {
        // Get DOM elements
        jsonEditor = document.getElementById('spec-json');
        templateEditor = document.getElementById('spec-template');
        jsonValidation = document.getElementById('json-validation');
        templatePreview = document.getElementById('template-preview');
        previewBtn = document.getElementById('preview-btn');
        saveBtn = document.getElementById('save-btn');
        
        // Set up tab switching
        document.querySelectorAll('.ai-editor-tab').forEach(tab => {
            tab.addEventListener('click', () => handleTabClick(tab));
        });
        
        // JSON validation on input
        if (jsonEditor) {
            jsonEditor.addEventListener('input', () => {
                clearTimeout(validationTimeout);
                validationTimeout = setTimeout(validateJson, 500);
            });
        }
        
        // Preview button
        if (previewBtn) {
            previewBtn.addEventListener('click', handlePreview);
        }
        
        // Save button
        if (saveBtn) {
            saveBtn.addEventListener('click', handleSave);
        }
        
        // Initial validation
        validateJson();
    }
    
    /**
     * Handle tab click
     * @param {HTMLElement} tab - The clicked tab
     */
    function handleTabClick(tab) {
        const tabId = tab.dataset.tab;
        
        document.querySelectorAll('.ai-editor-tab').forEach(t => t.classList.remove('ai-editor-tab--active'));
        document.querySelectorAll('.ai-editor-tab-content').forEach(c => c.classList.remove('ai-editor-tab-content--active'));
        
        tab.classList.add('ai-editor-tab--active');
        const tabContent = document.getElementById('tab-' + tabId);
        if (tabContent) {
            tabContent.classList.add('ai-editor-tab-content--active');
        }
    }
    
    /**
     * Validate JSON spec definition
     * @returns {Object} Validation result with valid flag and parsed spec
     */
    function validateJson() {
        if (!jsonEditor || !jsonValidation) return { valid: false };
        
        try {
            const spec = JSON.parse(jsonEditor.value);
            
            // Basic validation
            const errors = [];
            if (!spec.id) errors.push('Missing required field: id');
            if (!spec.version) errors.push('Missing required field: version');
            if (!spec.meta) errors.push('Missing required field: meta');
            if (!spec.dataRequirements) errors.push('Missing required field: dataRequirements');
            if (!spec.relatedCommands) errors.push('Missing required field: relatedCommands');
            
            if (spec.id && !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(spec.id)) {
                errors.push('Invalid ID format: must be kebab-case');
            }
            
            if (spec.version && !/^\d+\.\d+\.\d+$/.test(spec.version)) {
                errors.push('Invalid version format: must be semver (x.y.z)');
            }
            
            if (errors.length > 0) {
                jsonValidation.className = 'ai-editor-validation ai-editor-validation--invalid';
                jsonValidation.innerHTML = `
                    <div class="ai-editor-validation__title">⚠️ ${t('validationErrors', 'Validation Errors')}</div>
                    <ul class="ai-editor-validation__list">
                        ${errors.map(e => `<li>${e}</li>`).join('')}
                    </ul>
                `;
            } else {
                jsonValidation.className = 'ai-editor-validation ai-editor-validation--valid';
                jsonValidation.innerHTML = `<div class="ai-editor-validation__title">✓ ${t('validJson', 'Valid JSON - All required fields present')}</div>`;
            }
            
            return { valid: errors.length === 0, spec };
        } catch (e) {
            jsonValidation.className = 'ai-editor-validation ai-editor-validation--invalid';
            jsonValidation.innerHTML = `
                <div class="ai-editor-validation__title">❌ ${t('invalidJson', 'Invalid JSON')}</div>
                <ul class="ai-editor-validation__list">
                    <li>${e.message}</li>
                </ul>
            `;
            return { valid: false };
        }
    }
    
    /**
     * Handle preview button click
     */
    async function handlePreview() {
        const validation = validateJson();
        if (!validation.valid) {
            showMessage('error', t('fixErrors', 'Please fix JSON errors before previewing'));
            return;
        }
        
        const originalHtml = previewBtn.innerHTML;
        previewBtn.disabled = true;
        previewBtn.innerHTML = `<span class="admin-spinner"></span> ${t('loading', 'Loading...')}`;
        
        try {
            const response = await fetch(`${apiBaseUrl}/api/ai-spec-preview`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    spec: validation.spec,
                    template: templateEditor.value
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                templatePreview.textContent = data.data.prompt;
                templatePreview.classList.remove('ai-editor-preview--empty');
                
                // Switch to preview tab
                const previewTab = document.querySelector('[data-tab="preview"]');
                if (previewTab) {
                    previewTab.click();
                }
            } else {
                showMessage('error', data.error || 'Preview failed');
            }
        } catch (error) {
            showMessage('error', error.message);
        }
        
        previewBtn.disabled = false;
        previewBtn.innerHTML = originalHtml;
    }
    
    /**
     * Handle save button click
     */
    async function handleSave() {
        const validation = validateJson();
        if (!validation.valid) {
            showMessage('error', t('fixErrors', 'Please fix JSON errors before saving'));
            return;
        }
        
        if (!templateEditor.value.trim()) {
            showMessage('error', t('templateRequired', 'Prompt template is required'));
            return;
        }
        
        const originalHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = `<span class="admin-spinner"></span> ${t('saving', 'Saving...')}`;
        
        try {
            const response = await fetch(`${apiBaseUrl}/api/ai-spec-save`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    spec: validation.spec,
                    template: templateEditor.value,
                    isNew: isNew,
                    originalSpecId: originalSpecId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage('success', t('saved', 'Workflow saved successfully!'));
                
                // Redirect to the workflow page after a short delay
                const workflowsUrl = config.workflowsUrl || (apiBaseUrl + '/admin/workflows');
                setTimeout(() => {
                    window.location.href = `${workflowsUrl}/${validation.spec.id}`;
                }, 1500);
            } else {
                showMessage('error', data.error || 'Save failed');
            }
        } catch (error) {
            showMessage('error', error.message);
        }
        
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalHtml;
    }
    
    /**
     * Show a message notification
     * @param {string} type - 'success' or 'error'
     * @param {string} message - Message text
     */
    function showMessage(type, message) {
        const existing = document.querySelector('.ai-editor-message');
        if (existing) existing.remove();
        
        const div = document.createElement('div');
        div.className = `ai-editor-message ai-editor-message--${type}`;
        div.textContent = message;
        document.body.appendChild(div);
        
        setTimeout(() => div.remove(), 4000);
    }
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
