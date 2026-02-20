/**
 * Preview Style Theme Module
 * 
 * Handles CSS theme variable editing in the Visual Editor.
 * Allows viewing and modifying :root CSS custom properties.
 * 
 * Features:
 * - Load theme variables from API
 * - Live preview changes in iframe
 * - Save changes to server
 * - Reset to original values
 * - Color picker integration
 * 
 * Dependencies:
 * - PreviewConfig (configuration and i18n)
 * - PreviewState (for iframe access and showToast)
 * - QSColorPicker (optional, for color inputs)
 */
(function() {
    'use strict';
    
    // ==================== DOM Elements ====================
    
    const themePanel = document.getElementById('theme-panel');
    const themeLoading = document.getElementById('theme-loading');
    const themeContent = document.getElementById('theme-content');
    const themeColorsGrid = document.getElementById('theme-colors-grid');
    const themeFontsGrid = document.getElementById('theme-fonts-grid');
    const themeSpacingGrid = document.getElementById('theme-spacing-grid');
    const themeOtherGrid = document.getElementById('theme-other-grid');
    const themeOtherSection = document.getElementById('theme-other-section');
    const themeResetBtn = document.getElementById('theme-reset-btn');
    const themeSaveBtn = document.getElementById('theme-save-btn');
    
    // ==================== State ====================
    
    let themeVariablesLoaded = false;
    let originalThemeVariables = {};
    let currentThemeVariables = {};
    
    // ==================== Configuration ====================
    
    function getConfig() {
        return window.PreviewConfig || {};
    }
    
    function getI18n() {
        return getConfig().i18n || {};
    }
    
    function getManagementUrl() {
        return getConfig().managementUrl || '';
    }
    
    function getAuthToken() {
        return getConfig().authToken || null;
    }
    
    function getIframe() {
        return window.PreviewState?.getIframe?.() || document.getElementById('preview-iframe');
    }
    
    function showToast(message, type) {
        if (window.QuickSiteAdmin?.toast) {
            QuickSiteAdmin.toast(message, type);
        } else {
            console.log(`[Toast ${type}]`, message);
        }
    }
    
    function reloadPreview() {
        if (window.PreviewState?.reloadPreview) {
            PreviewState.reloadPreview();
        } else if (window.Preview?.reload) {
            Preview.reload();
        } else {
            // Fallback - try to reload iframe directly
            const iframe = getIframe();
            if (iframe) iframe.src = iframe.src;
        }
    }

    function hotReloadCss() {
        if (window.PreviewState?.hotReloadCss) {
            PreviewState.hotReloadCss();
        } else {
            reloadPreview();
        }
    }
    
    // ==================== Utility Functions ====================
    
    /**
     * Check if a value looks like a color
     */
    function isColorValue(value) {
        if (!value) return false;
        value = value.trim().toLowerCase();
        return value.startsWith('#') || 
               value.startsWith('rgb') || 
               value.startsWith('hsl') ||
               value.startsWith('var(--color');
    }
    
    /**
     * Check if a value looks like a size (px, rem, em, etc.)
     */
    function isSizeValue(value) {
        if (!value) return false;
        return /^\d+(\.\d+)?(px|rem|em|%|vh|vw|vmin|vmax)$/.test(value.trim());
    }
    
    /**
     * Format variable name for display (--color-primary -> Color Primary)
     */
    function formatVariableName(name) {
        return name
            .replace(/^--/, '')
            .split('-')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }
    
    // ==================== Load Theme Variables ====================
    
    /**
     * Load theme variables from the API
     */
    async function loadThemeVariables() {
        if (!themeLoading || !themeContent) return;
        
        const i18n = getI18n();
        const managementUrl = getManagementUrl();
        const authToken = getAuthToken();
        
        // Show loading state
        themeLoading.style.display = '';
        themeContent.style.display = 'none';
        
        try {
            const response = await fetch(managementUrl + 'getRootVariables', {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            const data = await response.json();
            
            if (data.status === 200 && data.data?.variables) {
                originalThemeVariables = { ...data.data.variables };
                currentThemeVariables = { ...data.data.variables };
                themeVariablesLoaded = true;
                
                // Populate the theme editor UI
                populateThemeEditor(data.data.variables);
                
                // Show content, hide loading
                themeLoading.style.display = 'none';
                themeContent.style.display = '';
            } else {
                throw new Error(data.message || 'Failed to load theme variables');
            }
        } catch (error) {
            console.error('[PreviewStyleTheme] Error loading theme variables:', error);
            themeLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color: #ef4444;">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span style="color: #ef4444;">${i18n.themeLoadError || 'Failed to load theme'}</span>
            `;
        }
    }
    
    // ==================== Populate UI ====================
    
    /**
     * Categorize and populate the theme editor with variables
     */
    function populateThemeEditor(variables) {
        const categories = {
            colors: [],
            fonts: [],
            spacing: [],
            other: []
        };
        
        // Categorize variables by prefix
        for (const [name, value] of Object.entries(variables)) {
            if (name.startsWith('--color-') || name.includes('color') || isColorValue(value)) {
                categories.colors.push({ name, value });
            } else if (name.startsWith('--font-') || name.includes('font')) {
                categories.fonts.push({ name, value });
            } else if (name.startsWith('--spacing-') || name.startsWith('--gap-') || name.startsWith('--margin-') || name.startsWith('--padding-') || name.includes('size') || isSizeValue(value)) {
                categories.spacing.push({ name, value });
            } else {
                categories.other.push({ name, value });
            }
        }
        
        // Render each category
        renderColorInputs(themeColorsGrid, categories.colors);
        renderFontInputs(themeFontsGrid, categories.fonts);
        renderSpacingInputs(themeSpacingGrid, categories.spacing);
        renderOtherInputs(themeOtherGrid, categories.other);
        
        // Show/hide "Other" section based on content
        if (themeOtherSection) {
            themeOtherSection.style.display = categories.other.length > 0 ? '' : 'none';
        }
    }
    
    // ==================== Render Functions ====================
    
    /**
     * Render color inputs
     */
    function renderColorInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        const i18n = getI18n();
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${i18n.noColorVariables || 'No color variables'}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-color';
            item.innerHTML = `
                <div class="preview-theme-color__swatch" style="background-color: ${value};" data-var="${name}"></div>
                <div class="preview-theme-color__info">
                    <span class="preview-theme-color__name">${formatVariableName(name)}</span>
                    <input type="text" class="preview-theme-color__value" value="${value}" data-var="${name}" data-original="${value}">
                </div>
            `;
            
            const swatch = item.querySelector('.preview-theme-color__swatch');
            const input = item.querySelector('.preview-theme-color__value');
            
            // Initialize QSColorPicker on the input if available
            if (typeof QSColorPicker !== 'undefined') {
                new QSColorPicker(input, {
                    onChange: (color) => {
                        swatch.style.backgroundColor = color;
                        handleVariableChange(name, color);
                        previewThemeVariable(name, color);
                    }
                });
            }
            
            // Big swatch click → trigger input click (opens QSColorPicker)
            swatch.addEventListener('click', () => input.click());
            
            // Manual input change handler (for typing hex values)
            input.addEventListener('change', (e) => {
                handleVariableChange(name, e.target.value);
                swatch.style.backgroundColor = e.target.value;
            });
            input.addEventListener('input', (e) => {
                // Live preview on input
                previewThemeVariable(name, e.target.value);
                swatch.style.backgroundColor = e.target.value;
            });
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render font inputs
     */
    function renderFontInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        const i18n = getI18n();
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${i18n.noFontVariables || 'No font variables'}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render spacing inputs
     */
    function renderSpacingInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        const i18n = getI18n();
        
        if (variables.length === 0) {
            container.innerHTML = `<p class="preview-theme-empty">${i18n.noSpacingVariables || 'No spacing variables'}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}" placeholder="e.g. 1rem, 16px">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    /**
     * Render other inputs
     */
    function renderOtherInputs(container, variables) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) return;
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}">
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    // ==================== Change Handling ====================
    
    /**
     * Handle variable value change
     */
    function handleVariableChange(name, value) {
        currentThemeVariables[name] = value;
    }
    
    /**
     * Live preview a theme variable change in the iframe
     */
    function previewThemeVariable(name, value) {
        currentThemeVariables[name] = value;
        
        try {
            const iframe = getIframe();
            if (!iframe) return;
            
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            
            // Find or create the preview style element
            let previewStyle = iframeDoc.getElementById('quicksite-theme-preview');
            if (!previewStyle) {
                previewStyle = iframeDoc.createElement('style');
                previewStyle.id = 'quicksite-theme-preview';
                iframeDoc.head.appendChild(previewStyle);
            }
            
            // Build CSS from all modified variables
            const modifiedVars = Object.entries(currentThemeVariables)
                .filter(([k, v]) => v !== originalThemeVariables[k])
                .map(([k, v]) => `${k}: ${v};`)
                .join('\n');
            
            previewStyle.textContent = `:root {\n${modifiedVars}\n}`;
        } catch (e) {
            console.warn('[PreviewStyleTheme] Could not preview theme variable:', e);
        }
    }
    
    // ==================== Save / Reset ====================
    
    /**
     * Save theme variables to the server
     */
    async function saveThemeVariables() {
        if (!themeSaveBtn) return;
        
        const i18n = getI18n();
        const managementUrl = getManagementUrl();
        const authToken = getAuthToken();
        
        // Disable button and show loading
        themeSaveBtn.disabled = true;
        const originalText = themeSaveBtn.innerHTML;
        themeSaveBtn.innerHTML = `
            <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <circle cx="12" cy="12" r="10" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
            </svg>
            ${i18n.saving || 'Saving...'}
        `;
        
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (authToken) headers['Authorization'] = `Bearer ${authToken}`;
            
            const response = await fetch(managementUrl + 'setRootVariables', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ variables: currentThemeVariables })
            });
            
            const data = await response.json();
            
            if (data.status === 200 || data.status === 201) {
                // Update original values to current
                originalThemeVariables = { ...currentThemeVariables };
                
                // Show success toast
                showToast(i18n.themeSaved || 'Theme saved', 'success');
                
                // Hot-reload CSS without full page reload
                hotReloadCss();
            } else {
                throw new Error(data.message || 'Failed to save theme variables');
            }
        } catch (error) {
            console.error('[PreviewStyleTheme] Error saving theme variables:', error);
            showToast(i18n.themeSaveError || 'Failed to save theme', 'error');
        } finally {
            // Restore button
            themeSaveBtn.disabled = false;
            themeSaveBtn.innerHTML = originalText;
        }
    }
    
    /**
     * Reset theme variables to original values
     */
    function resetThemeVariables() {
        const i18n = getI18n();
        
        currentThemeVariables = { ...originalThemeVariables };
        
        // Update all inputs
        document.querySelectorAll('[data-var]').forEach(el => {
            const varName = el.dataset.var;
            const originalValue = originalThemeVariables[varName];
            
            if (el.classList.contains('preview-theme-color__swatch')) {
                el.style.backgroundColor = originalValue;
            } else if (el.classList.contains('preview-theme-color__value') || el.classList.contains('preview-theme-input__field')) {
                el.value = originalValue;
            }
        });
        
        // Remove preview style from iframe
        try {
            const iframe = getIframe();
            if (iframe) {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const previewStyle = iframeDoc?.getElementById('quicksite-theme-preview');
                if (previewStyle) {
                    previewStyle.remove();
                }
            }
        } catch (e) {
            console.warn('[PreviewStyleTheme] Could not reset iframe preview:', e);
        }
        
        showToast(i18n.themeReset || 'Theme reset', 'info');
    }
    
    // ==================== Event Listeners ====================
    
    if (themeResetBtn) {
        themeResetBtn.addEventListener('click', resetThemeVariables);
    }
    
    if (themeSaveBtn) {
        themeSaveBtn.addEventListener('click', saveThemeVariables);
    }
    
    // ==================== Public API ====================
    
    window.PreviewStyleTheme = {
        load: loadThemeVariables,
        save: saveThemeVariables,
        reset: resetThemeVariables,
        isLoaded: () => themeVariablesLoaded,
        getOriginal: () => ({ ...originalThemeVariables }),
        getCurrent: () => ({ ...currentThemeVariables })
    };
    
    console.log('[PreviewStyleTheme] Module loaded');
    
})();
