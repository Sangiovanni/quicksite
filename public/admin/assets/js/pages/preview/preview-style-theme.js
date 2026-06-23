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
    let _loadAbortCtrl = null;          // AbortController for in-flight loadThemeVariables calls
    
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
    async function fetchScopeVariables(scope, signal) {
        // Use the shared API layer to keep auth/query handling consistent.
        if (window.QuickSiteAPI?.request) {
            const result = await QuickSiteAPI.request(
                'getRootVariables',
                'GET',
                null,
                [],
                scope === 'dark' ? { themeTarget: 'dark' } : {}
            );
            if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
            if (result.ok && result.data?.data?.variables) {
                return result.data.data.variables;
            }
            throw new Error(result.data?.message || result.data?.error || 'Failed to load theme variables');
        }

        // Fallback for contexts where QuickSiteAPI is unavailable.
        const managementUrl = getManagementUrl();
        const authToken = getAuthToken();
        const url = managementUrl + 'getRootVariables' + (scope === 'dark' ? '?themeTarget=dark' : '');
        const response = await fetch(url, {
            headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {},
            signal: signal ?? undefined
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

        // Abort any in-flight load and start a fresh controller.
        if (_loadAbortCtrl) _loadAbortCtrl.abort();
        _loadAbortCtrl = new AbortController();
        const { signal } = _loadAbortCtrl;

        const i18n = getI18n();
        
        themeLoading.style.display = '';
        themeContent.style.display = 'none';
        if (themeSaveBtn) themeSaveBtn.disabled = true;
        if (themeResetBtn) themeResetBtn.disabled = true;
        themeVariablesLoaded = false;
        originalThemeVariables = {};
        currentThemeVariables = {};
        
        try {
            // Always load :root so we have canonical variable names
            lightVariables = await fetchScopeVariables('light', signal);

            let displayVars = lightVariables;

            if (currentScope === 'dark') {
                // Load dark overrides; fall back to light values for any missing variable
                const darkVars = await fetchScopeVariables('dark', signal);
                displayVars = {};
                for (const [name, lightVal] of Object.entries(lightVariables)) {
                    displayVars[name] = darkVars[name] !== undefined ? darkVars[name] : lightVal;
                }
                // Also pick up any dark-only variables not in :root
                for (const [name, darkVal] of Object.entries(darkVars)) {
                    if (!(name in displayVars)) displayVars[name] = darkVal;
                }
            }

            // Ignore results if a newer load was triggered while we were awaiting.
            if (signal.aborted) return;

            originalThemeVariables = { ...displayVars };
            currentThemeVariables  = { ...displayVars };
            themeVariablesLoaded   = true;
            
            populateThemeEditor(displayVars);
            
            themeLoading.style.display = 'none';
            themeContent.style.display = '';
            if (themeSaveBtn) themeSaveBtn.disabled = false;
            if (themeResetBtn) themeResetBtn.disabled = false;
        } catch (error) {
            if (error.name === 'AbortError') return; // superseded by a newer load — ignore silently
            console.error('[PreviewStyleTheme] Error loading theme variables:', error);
            themeLoading.innerHTML = `
                ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.alertCircle, 24, null, 'style="color: #ef4444;"')}
                <span style="color: #ef4444;">${i18n.themeLoadError || 'Failed to load theme'}</span>
            `;
            if (themeSaveBtn) themeSaveBtn.disabled = true;
            if (themeResetBtn) themeResetBtn.disabled = true;
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
        if (!themeVariablesLoaded) {
            showToast(getI18n().themeLoadError || 'Theme data is still loading', 'warning');
            return;
        }
        
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
            const changedVariables = Object.fromEntries(
                Object.entries(currentThemeVariables).filter(([name, value]) => value !== originalThemeVariables[name])
            );

            if (Object.keys(changedVariables).length === 0) {
                showToast(i18n.noChanges || 'No changes to save', 'info');
                return;
            }

            const body = { variables: changedVariables };
            if (currentScope === 'dark') body.themeTarget = 'dark';

            let data;
            if (window.QuickSiteAPI?.request) {
                const result = await QuickSiteAPI.request('setRootVariables', 'POST', body);
                if (!result.ok) {
                    throw new Error(result.data?.message || result.data?.error || 'Failed to save theme variables');
                }
                data = result.data;
            } else {
                const headers = { 'Content-Type': 'application/json' };
                if (authToken) headers['Authorization'] = `Bearer ${authToken}`;

                const response = await fetch(managementUrl + 'setRootVariables', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(body)
                });
                data = await response.json();
            }
            
            if (data.status === 200 || data.status === 201) {
                // Update baseline only for the values we persisted.
                for (const [name, value] of Object.entries(changedVariables)) {
                    originalThemeVariables[name] = value;
                }
                currentThemeVariables = { ...originalThemeVariables };
                
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

        // Remove transient preview override from previous scope to avoid stale values
        // leaking when switching between :root and [data-theme="dark"].
        try {
            const iframe = getIframe();
            const iframeDoc = iframe?.contentDocument || iframe?.contentWindow?.document;
            iframeDoc?.getElementById('quicksite-theme-preview')?.remove();
        } catch (e) { /* cross-origin guard */ }

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
        if (themeSaveBtn) themeSaveBtn.disabled = true;
        if (themeResetBtn) themeResetBtn.disabled = true;
        loadThemeVariables();

        // A3 slice 6 — any open quick-add forms now show a stale scope
        // banner. Easiest correct behaviour: close them. Refreshing the
        // banners mid-edit is also possible but risks the user submitting
        // against the wrong scope they intended.
        closeAllAddForms();
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
                    // Reflect new config immediately in this panel and toolbar visibility.
                    syncThemeConfigDependents();

                    // Keep editor scope aligned with configured default when dark mode is enabled.
                    if (themeConfigEnabled?.checked) {
                        switchScope(themeConfigDefault?.value === 'dark' ? 'dark' : 'light');
                    } else {
                        switchScope('light');
                    }

                    // Theme mode impacts frontend rendering conditions (e.g. visitor toggle).
                    // Force a preview refresh so controls appear/disappear without full tab reload.
                    reloadPreview();

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

    // ==================== Quick-add variable (A3 slice 6) ====================
    // Each Theme section (Colors / Fonts / Spacing) carries an inline
    // `.preview-theme-add` container with a toggle + form. Clicking the
    // toggle opens the form, which is scope-aware (banner reflects the
    // currently-selected light/dark scope) and, when dark mode is
    // enabled, offers an "Also add to the other scope" checkbox so the
    // user can populate both scopes in one action. Writes go through
    // the existing `setRootVariables` command.

    const SECTION_PREFIXES = {
        colors:  '--color-',
        fonts:   '--font-',
        spacing: '--spacing-'
    };

    function initThemeAddForms() {
        const containers = document.querySelectorAll('.preview-theme-add');
        containers.forEach(wireThemeAddForm);
    }

    function wireThemeAddForm(container) {
        const section = container.dataset.section;
        const toggle  = container.querySelector('[data-action="toggle"]');
        const form    = container.querySelector('[data-form]');
        const banner  = container.querySelector('[data-scope-banner]');
        const alsoOtherCb = container.querySelector('[data-also-other]');
        const nameInput   = container.querySelector('[data-input-name]');
        const valueInput  = container.querySelector('[data-input-value]');
        const cancelBtn   = container.querySelector('[data-action="cancel"]');
        const submitBtn   = container.querySelector('[data-action="submit"]');
        if (!toggle || !form || !nameInput || !valueInput) return;

        const prefix = SECTION_PREFIXES[section] || '--';

        function openForm() {
            form.hidden = false;
            updateScopeBanner(banner);
            if (alsoOtherCb) alsoOtherCb.checked = false;
            nameInput.value = prefix;
            valueInput.value = '';
            nameInput.focus();
            try { nameInput.setSelectionRange(prefix.length, prefix.length); } catch (e) { /* no-op */ }
        }

        function closeForm() {
            form.hidden = true;
            nameInput.value = '';
            valueInput.value = '';
        }

        toggle.addEventListener('click', function () {
            if (form.hidden) openForm(); else closeForm();
        });
        if (cancelBtn) cancelBtn.addEventListener('click', closeForm);
        if (submitBtn) submitBtn.addEventListener('click', function () {
            submitThemeAddVariable(container, nameInput, valueInput, alsoOtherCb, submitBtn, closeForm);
        });
        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); valueInput.focus(); }
            else if (e.key === 'Escape') { e.preventDefault(); closeForm(); }
        });
        valueInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitThemeAddVariable(container, nameInput, valueInput, alsoOtherCb, submitBtn, closeForm);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeForm();
            }
        });
    }

    function updateScopeBanner(banner) {
        if (!banner) return;
        const i18n = getI18n();
        banner.textContent = currentScope === 'dark'
            ? (i18n.themeAddVariableTargetDark  || 'Adding to: Dark scope')
            : (i18n.themeAddVariableTargetLight || 'Adding to: Light scope');
    }

    function refreshAllScopeBanners() {
        document.querySelectorAll('.preview-theme-add [data-scope-banner]').forEach(updateScopeBanner);
    }

    function closeAllAddForms() {
        document.querySelectorAll('.preview-theme-add [data-form]').forEach(function (form) {
            form.hidden = true;
            const c = form.closest('.preview-theme-add');
            if (!c) return;
            const n = c.querySelector('[data-input-name]');
            const v = c.querySelector('[data-input-value]');
            if (n) n.value = '';
            if (v) v.value = '';
        });
    }

    async function submitThemeAddVariable(container, nameInput, valueInput, alsoOtherCb, submitBtn, closeForm) {
        const i18n = getI18n();
        let name = (nameInput.value || '').trim();
        const value = (valueInput.value || '').trim();

        if (!name || name === '--') {
            showToast(i18n.themeAddVariableNameRequired || 'Variable name is required', 'warning');
            nameInput.focus();
            return;
        }
        if (!value) {
            showToast(i18n.themeAddVariableValueRequired || 'Value is required', 'warning');
            valueInput.focus();
            return;
        }
        if (!name.startsWith('--')) name = '--' + name;

        if (Object.prototype.hasOwnProperty.call(originalThemeVariables, name)
            || Object.prototype.hasOwnProperty.call(currentThemeVariables, name)) {
            const msg = (i18n.themeAddVariableNameExists || 'Variable {name} already exists').replace('{name}', name);
            showToast(msg, 'warning');
            nameInput.focus();
            return;
        }

        const alsoOther = !!(alsoOtherCb && alsoOtherCb.checked);
        if (submitBtn) submitBtn.disabled = true;
        try {
            await postSetRootVariable(name, value, currentScope);
            if (alsoOther) {
                const otherScope = (currentScope === 'dark') ? 'light' : 'dark';
                await postSetRootVariable(name, value, otherScope);
            }

            // Local state: only the current-scope value matters for the
            // editor's diff baseline. The other-scope write is a no-op
            // for our in-memory state (we'd see it on next scope switch).
            originalThemeVariables[name] = value;
            currentThemeVariables[name] = value;

            // Re-render to include the new variable in the appropriate
            // section's grid. The add-form containers live OUTSIDE the
            // grid divs that get cleared, so they stay intact.
            populateThemeEditor(originalThemeVariables);

            const tpl = alsoOther
                ? (i18n.themeAddVariableAddedBoth || 'Variable {name} added to both scopes')
                : (i18n.themeAddVariableAdded     || 'Variable {name} added');
            showToast(tpl.replace('{name}', name), 'success');
            hotReloadCss();
            closeForm();
        } catch (err) {
            const tpl = i18n.themeAddVariableAddError || 'Failed to add variable: {error}';
            showToast(tpl.replace('{error}', (err && err.message) || ''), 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    async function postSetRootVariable(name, value, scope) {
        const body = { variables: { [name]: value } };
        if (scope === 'dark') body.themeTarget = 'dark';

        if (window.QuickSiteAPI && QuickSiteAPI.request) {
            const result = await QuickSiteAPI.request('setRootVariables', 'POST', body);
            if (!result.ok) {
                throw new Error((result.data && (result.data.message || result.data.error)) || 'Save failed');
            }
            return result.data;
        }
        const headers = { 'Content-Type': 'application/json' };
        const authToken = getAuthToken();
        if (authToken) headers['Authorization'] = 'Bearer ' + authToken;
        const response = await fetch(getManagementUrl() + 'setRootVariables', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(body)
        });
        const data = await response.json();
        if (data.status !== 200 && data.status !== 201) {
            throw new Error(data.message || 'Save failed');
        }
        return data;
    }

    // ==================== Event Listeners ====================

    if (themeResetBtn) {
        themeResetBtn.addEventListener('click', resetThemeVariables);
    }

    if (themeSaveBtn) {
        themeSaveBtn.addEventListener('click', saveThemeVariables);
    }

    // A3 slice 6 — wire all `.preview-theme-add` toggles + forms.
    initThemeAddForms();

    // ==================== Public API ====================
    
    /**
     * Mark the in-memory cache stale so the next view triggers a fresh
     * fetch. Used by Source (A3 slice 6 fix) when its save / cancel
     * rewrites style.css — without this, the Theme tab would keep
     * showing the variables it loaded before the Source write.
     */
    function invalidate() {
        themeVariablesLoaded = false;
    }

    window.PreviewStyleTheme = {
        load: loadThemeVariables,
        save: saveThemeVariables,
        reset: resetThemeVariables,
        invalidate,
        switchScope,
        getScope: () => currentScope,
        isLoaded: () => themeVariablesLoaded,
        getOriginal: () => ({ ...originalThemeVariables }),
        getCurrent: () => ({ ...currentThemeVariables })
    };
    
    console.log('[PreviewStyleTheme] Module loaded');
    
})();
