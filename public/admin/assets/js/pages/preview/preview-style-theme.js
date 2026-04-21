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
    let originalThemeVariables = {};    // values for current scope
    let currentThemeVariables = {};     // edited values for current scope
    let lightVariables = {};            // :root values (always loaded)
    let currentScope = 'light';         // 'light' | 'dark'
    
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
     * Fetch variables for one scope from the API
     * @param {string} scope 'light' | 'dark'
     * @returns {Promise<Object>} variable map
     */
    async function fetchScopeVariables(scope) {
        const managementUrl = getManagementUrl();
        const authToken = getAuthToken();
        const url = managementUrl + 'getRootVariables' + (scope === 'dark' ? '?themeTarget=dark' : '');
        const response = await fetch(url, {
            headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
        });
        const data = await response.json();
        if (data.status === 200 && data.data?.variables) {
            return data.data.variables;
        }
        throw new Error(data.message || 'Failed to load theme variables');
    }

    /**
     * Load theme variables for the current scope.
     * For 'dark': always loads :root names, then overlays saved dark overrides.
     */
    async function loadThemeVariables() {
        if (!themeLoading || !themeContent) return;
        
        const i18n = getI18n();
        
        themeLoading.style.display = '';
        themeContent.style.display = 'none';
        
        try {
            // Always load :root so we have canonical variable names
            lightVariables = await fetchScopeVariables('light');

            let displayVars = lightVariables;

            if (currentScope === 'dark') {
                // Load dark overrides; fall back to light values for any missing variable
                const darkVars = await fetchScopeVariables('dark');
                displayVars = {};
                for (const [name, lightVal] of Object.entries(lightVariables)) {
                    displayVars[name] = darkVars[name] !== undefined ? darkVars[name] : lightVal;
                }
                // Also pick up any dark-only variables not in :root
                for (const [name, darkVal] of Object.entries(darkVars)) {
                    if (!(name in displayVars)) displayVars[name] = darkVal;
                }
            }

            originalThemeVariables = { ...displayVars };
            currentThemeVariables  = { ...displayVars };
            themeVariablesLoaded   = true;
            
            populateThemeEditor(displayVars);
            
            themeLoading.style.display = 'none';
            themeContent.style.display = '';
        } catch (error) {
            console.error('[PreviewStyleTheme] Error loading theme variables:', error);
            themeLoading.innerHTML = `
                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.alertCircle, 24, null, 'style="color: #ef4444;"')}
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
     * Render text-based variable inputs (fonts, spacing, other)
     */
    function renderTextInputs(container, variables, emptyMsg, placeholder) {
        if (!container) return;
        container.innerHTML = '';
        
        if (variables.length === 0) {
            if (emptyMsg) container.innerHTML = `<p class="preview-theme-empty">${emptyMsg}</p>`;
            return;
        }
        
        variables.forEach(({ name, value }) => {
            const item = document.createElement('div');
            item.className = 'preview-theme-input';
            item.innerHTML = `
                <label class="preview-theme-input__label">${formatVariableName(name)}</label>
                <input type="text" class="preview-theme-input__field" value="${value}" data-var="${name}" data-original="${value}"${placeholder ? ` placeholder="${placeholder}"` : ''}>
            `;
            
            const input = item.querySelector('.preview-theme-input__field');
            input.addEventListener('change', (e) => handleVariableChange(name, e.target.value));
            input.addEventListener('input', (e) => previewThemeVariable(name, e.target.value));
            
            container.appendChild(item);
        });
    }
    
    function renderFontInputs(container, variables) {
        const i18n = getI18n();
        renderTextInputs(container, variables, i18n.noFontVariables || 'No font variables');
    }
    
    function renderSpacingInputs(container, variables) {
        const i18n = getI18n();
        renderTextInputs(container, variables, i18n.noSpacingVariables || 'No spacing variables', 'e.g. 1rem, 16px');
    }
    
    function renderOtherInputs(container, variables) {
        renderTextInputs(container, variables);
    }
    
    // ==================== Change Handling ====================
    
    /**
     * Handle variable value change
     */
    function handleVariableChange(name, value) {
        currentThemeVariables[name] = value;
    }
    
    /**
     * Live preview a theme variable change in the iframe.
     * For dark scope, injects overrides into [data-theme="dark"] block.
     */
    function previewThemeVariable(name, value) {
        currentThemeVariables[name] = value;
        
        try {
            const iframe = getIframe();
            if (!iframe) return;
            
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;
            
            let previewStyle = iframeDoc.getElementById('quicksite-theme-preview');
            if (!previewStyle) {
                previewStyle = iframeDoc.createElement('style');
                previewStyle.id = 'quicksite-theme-preview';
                iframeDoc.head.appendChild(previewStyle);
            }
            
            const modifiedVars = Object.entries(currentThemeVariables)
                .filter(([k, v]) => v !== originalThemeVariables[k])
                .map(([k, v]) => `${k}: ${v};`)
                .join('\n    ');

            const selector = currentScope === 'dark' ? '[data-theme="dark"]' : ':root';
            previewStyle.textContent = `${selector} {\n    ${modifiedVars}\n}`;
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
            
            const body = { variables: currentThemeVariables };
            if (currentScope === 'dark') body.themeTarget = 'dark';

            const response = await fetch(managementUrl + 'setRootVariables', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(body)
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
    
    // ==================== Scope Switcher ====================

    /**
     * Switch between light and dark variable scope.
     * Reloads variables and updates iframe preview theme.
     */
    function switchScope(scope) {
        if (scope === currentScope) return;
        currentScope = scope;

        // Update scope switcher buttons
        document.querySelectorAll('[data-scope]').forEach(btn => {
            btn.classList.toggle('preview-theme-scope__btn--active', btn.dataset.scope === scope);
        });

        // Sync toolbar toggle buttons
        document.querySelectorAll('[data-theme-preview]').forEach(btn => {
            btn.classList.toggle('preview-toolbar__seg-btn--active', btn.dataset.themePreview === scope);
        });

        // Set data-theme on iframe <html> so the preview renders in the right mode
        try {
            const iframe = getIframe();
            if (iframe) {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                if (iframeDoc?.documentElement) {
                    iframeDoc.documentElement.setAttribute('data-theme', scope);
                }
            }
        } catch (e) { /* cross-origin guard */ }

        // Reload variables for the new scope (resets unsaved changes)
        themeVariablesLoaded = false;
        loadThemeVariables();
    }

    // Wire scope switcher buttons (inside style panel)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-scope]');
        if (btn) switchScope(btn.dataset.scope);
    });

    // Wire toolbar preview toggle buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-theme-preview]');
        if (btn) switchScope(btn.dataset.themePreview);
    });

    // ==================== Theme Mode Config Panel ====================

    const themeConfigToggle   = document.getElementById('theme-config-toggle');
    const themeConfigBody     = document.getElementById('theme-config-body');
    const themeConfigSaveBtn  = document.getElementById('theme-config-save-btn');
    const themeConfigEnabled  = document.getElementById('theme-config-enabled');
    const themeConfigDefault  = document.getElementById('theme-config-default');
    const themeConfigToggleEl = document.getElementById('theme-config-usertoggle');

    /**
     * Sync the dependent controls (default mode, visitor toggle) based on
     * whether dark mode support is enabled. When disabled, reset to defaults
     * and prevent interaction.
     */
    function syncThemeConfigDependents() {
        if (!themeConfigEnabled) return;
        const enabled = themeConfigEnabled.checked;

        // Dependent config controls
        if (themeConfigDefault)  { themeConfigDefault.disabled  = !enabled; }
        if (themeConfigToggleEl) { themeConfigToggleEl.disabled = !enabled; }
        if (!enabled) {
            if (themeConfigDefault)  themeConfigDefault.value    = 'light';
            if (themeConfigToggleEl) themeConfigToggleEl.checked = false;
        }

        // Scope switcher inside style panel
        const scopeSwitcher = document.getElementById('theme-scope-switcher');
        if (scopeSwitcher) scopeSwitcher.style.display = enabled ? '' : 'none';

        // Toolbar theme toggle group
        const toolbarGroup = document.getElementById('preview-theme-toggle-group');
        if (toolbarGroup) toolbarGroup.style.display = enabled ? '' : 'none';

        // If dark mode is being disabled and we're currently in dark scope, switch back to light
        if (!enabled && currentScope === 'dark') {
            switchScope('light');
        }
    }

    if (themeConfigEnabled) {
        themeConfigEnabled.addEventListener('change', syncThemeConfigDependents);
        // Run once on init to set correct state based on current PHP-rendered value
        syncThemeConfigDependents();
    }

    if (themeConfigToggle && themeConfigBody) {
        themeConfigToggle.addEventListener('click', function() {
            const open = themeConfigBody.style.display !== 'none';
            themeConfigBody.style.display = open ? 'none' : '';
            themeConfigToggle.setAttribute('aria-expanded', String(!open));
        });
    }

    if (themeConfigSaveBtn) {
        themeConfigSaveBtn.addEventListener('click', async function() {
            const managementUrl = getManagementUrl();
            const authToken = getAuthToken();
            const i18n = getI18n();

            const body = {};
            if (themeConfigEnabled  !== null) body.enabled    = themeConfigEnabled.checked;
            if (themeConfigDefault  !== null) body.default    = themeConfigDefault.value;
            if (themeConfigToggleEl !== null) body.userToggle = themeConfigToggleEl.checked;

            themeConfigSaveBtn.disabled = true;
            try {
                const headers = { 'Content-Type': 'application/json' };
                if (authToken) headers['Authorization'] = `Bearer ${authToken}`;
                const res = await fetch(managementUrl + 'setThemeMode', {
                    method: 'POST', headers, body: JSON.stringify(body)
                });
                const data = await res.json();
                if (data.status === 200) {
                    showToast(i18n.themeConfigSaved || 'Theme settings saved', 'success');
                } else {
                    throw new Error(data.message || 'Failed to save');
                }
            } catch (err) {
                showToast(i18n.themeSaveError || 'Failed to save theme settings', 'error');
                console.error('[PreviewStyleTheme] setThemeMode error:', err);
            } finally {
                themeConfigSaveBtn.disabled = false;
            }
        });
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
        switchScope,
        getScope: () => currentScope,
        isLoaded: () => themeVariablesLoaded,
        getOriginal: () => ({ ...originalThemeVariables }),
        getCurrent: () => ({ ...currentThemeVariables })
    };
    
    console.log('[PreviewStyleTheme] Module loaded');
    
})();
