/**
 * QuickSite Admin Panel JavaScript
 * 
 * Handles client-side functionality for the admin interface.
 * Delegates to core modules (QuickSiteAPI, QuickSiteUtils) for shared functionality.
 * 
 * @version 1.6.0
 * @requires js/core/api.js
 * @requires js/core/utils.js
 */

const QuickSiteAdmin = {
    // Configuration - delegates to QuickSiteAPI.config with additional admin-specific values
    config: {
        get apiBase() {
            return window.QuickSiteAPI?.config.apiBase || window.QUICKSITE_CONFIG?.apiBase || '/management';
        },
        get adminBase() {
            return window.QuickSiteAPI?.config.adminBase || window.QUICKSITE_CONFIG?.adminBase || '/admin';
        },
        get baseUrl() {
            return window.QuickSiteAPI?.config.baseUrl || window.QUICKSITE_CONFIG?.baseUrl || '';
        },
        get publicSpace() {
            return window.QuickSiteAPI?.config.publicSpace || window.QUICKSITE_CONFIG?.publicSpace || '';
        },
        get token() {
            // Try QUICKSITE_CONFIG first, then cookie
            if (window.QUICKSITE_CONFIG?.token) {
                return window.QUICKSITE_CONFIG.token;
            }
            // Read from cookie
            const match = document.cookie.match(/(?:^|; )admin_token=([^;]*)/);
            return match ? decodeURIComponent(match[1]) : '';
        },
        get defaultLang() {
            return window.QUICKSITE_CONFIG?.defaultLang || 'en';
        },
        get multilingual() {
            return window.QUICKSITE_CONFIG?.multilingual || false;
        },
        tokenStorageKey: 'quicksite_admin_token',
        rememberStorageKey: 'quicksite_admin_remember',
        prefsStorageKey: 'quicksite_admin_prefs'
    },

    // ============================================
    // Permission System
    // ============================================
    
    /**
     * Current user's permissions (loaded from API)
     */
    permissions: {
        loaded: false,
        role: null,
        commands: [],
        isSuperAdmin: false
    },

    /**
     * Load user permissions from API
     * Call this on page load before rendering command lists
     */
    async loadPermissions() {
        const token = this.getToken();
        if (!token) {
            this.permissions = { loaded: true, role: null, commands: [], isSuperAdmin: false, tokenName: null };
            this.updateUserBadge();
            return;
        }

        try {
            const response = await this.apiRequest('getMyPermissions', 'POST');
            if (response.ok && response.data?.data) {
                this.permissions = {
                    loaded: true,
                    role: response.data.data.role,
                    commands: response.data.data.commands || [],
                    isSuperAdmin: response.data.data.is_superadmin || false,
                    tokenName: response.data.data.token_name || null
                };
            } else {
                // Fallback - treat as no permissions if API fails
                this.permissions = { loaded: true, role: 'unknown', commands: [], isSuperAdmin: false, tokenName: null };
            }
        } catch (error) {
            console.error('Failed to load permissions:', error);
            this.permissions = { loaded: true, role: 'error', commands: [], isSuperAdmin: false, tokenName: null };
        }
        
        // Update user badge in header
        this.updateUserBadge();
        
        // Trigger permission-based filtering
        this.filterByPermissions();
    },

    /**
     * Update the user badge in the header with current user info
     */
    updateUserBadge() {
        const nameEl = document.getElementById('admin-user-name');
        const roleEl = document.getElementById('admin-user-role');
        
        if (!nameEl || !roleEl) return;
        
        if (!this.permissions.loaded || !this.permissions.role) {
            nameEl.textContent = 'Not logged in';
            roleEl.textContent = '';
            roleEl.removeAttribute('data-role');
            return;
        }
        
        // Display token name (shortened if too long)
        const name = this.permissions.tokenName || 'Unknown';
        nameEl.textContent = name.length > 20 ? name.substring(0, 20) + '...' : name;
        nameEl.title = name;
        
        // Display role with special formatting for superadmin
        const role = this.permissions.role;
        roleEl.textContent = role === '*' ? 'Superadmin' : role;
        roleEl.setAttribute('data-role', role);
    },

    /**
     * Check if current user has permission for a command
     */
    hasPermission(command) {
        if (this.permissions.isSuperAdmin) return true;
        return this.permissions.commands.includes(command);
    },

    /**
     * Check if user has all commands in a list
     */
    hasAllPermissions(commands) {
        if (this.permissions.isSuperAdmin) return true;
        return commands.every(cmd => this.permissions.commands.includes(cmd));
    },

    /**
     * Check if user has any command in a list
     */
    hasAnyPermission(commands) {
        if (this.permissions.isSuperAdmin) return true;
        return commands.some(cmd => this.permissions.commands.includes(cmd));
    },

    /**
     * Filter UI elements based on permissions
     * Hides elements with data-requires-command that user doesn't have access to
     */
    filterByPermissions() {
        // Skip if permissions not loaded yet
        if (!this.permissions.loaded) return;
        
        // Superadmin sees everything
        if (this.permissions.isSuperAdmin) return;
        
        // Filter command links
        document.querySelectorAll('[data-command]').forEach(el => {
            const command = el.dataset.command;
            if (!this.hasPermission(command)) {
                el.classList.add('admin-hidden-permission');
                el.setAttribute('aria-hidden', 'true');
            }
        });
        
        // Filter elements requiring specific commands
        document.querySelectorAll('[data-requires-command]').forEach(el => {
            const required = el.dataset.requiresCommand.split(',').map(c => c.trim());
            if (!this.hasAllPermissions(required)) {
                el.classList.add('admin-hidden-permission');
                el.setAttribute('aria-hidden', 'true');
            }
        });
        
        // Update category counts after filtering
        document.querySelectorAll('.admin-category').forEach(category => {
            const visible = category.querySelectorAll('.admin-command-link:not(.admin-hidden-permission)');
            const countEl = category.querySelector('.admin-category__count');
            if (countEl) {
                countEl.textContent = visible.length;
            }
            // Hide category if no visible commands
            if (visible.length === 0) {
                category.classList.add('admin-hidden-permission');
            }
        });
    },

    /**
     * Initialize the admin panel
     */
    init() {
        this.loadPreferences();
        this.initNavGroups();
        this.initCategoryToggles();
        this.initForms();
        this.initCopyButtons();
        this.initKeyboardShortcuts();
        this.checkPendingMessage();
        
        // Load permissions (async - will filter UI when complete)
        this.loadPermissions();
    },

    /**
     * Initialize collapsible navigation groups (hover-based)
     */
    initNavGroups() {
        const groups = document.querySelectorAll('.admin-nav__group');
        let closeTimeout = null;
        
        groups.forEach(group => {
            const toggle = group.querySelector('.admin-nav__group-toggle');
            if (!toggle) return;
            
            // Open on hover
            group.addEventListener('mouseenter', () => {
                // Clear any pending close
                if (closeTimeout) {
                    clearTimeout(closeTimeout);
                    closeTimeout = null;
                }
                
                // Close other groups immediately
                groups.forEach(other => {
                    if (other !== group) {
                        other.classList.remove('admin-nav__group--open');
                    }
                });
                
                // Open this group
                group.classList.add('admin-nav__group--open');
            });
            
            // Close on mouse leave (with small delay for better UX)
            group.addEventListener('mouseleave', () => {
                closeTimeout = setTimeout(() => {
                    group.classList.remove('admin-nav__group--open');
                }, 150); // Small delay to prevent accidental close
            });
            
            // Also support click for accessibility/mobile
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                group.classList.toggle('admin-nav__group--open');
            });
        });
        
        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                groups.forEach(group => {
                    group.classList.remove('admin-nav__group--open');
                });
            }
        });
    },
    
    /**
     * Expand a nav group programmatically
     * @param {string} groupName - 'build' or 'inspect'
     */
    expandNavGroup(groupName) {
        const group = document.querySelector(`.admin-nav__group[data-nav-group="${groupName}"]`);
        if (group) {
            group.classList.add('admin-nav__group--open');
        }
    },

    /**
     * Check for pending message from redirect - delegates to QuickSiteUtils
     */
    checkPendingMessage() {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.checkPendingMessage();
        }
        
        // Fallback
        const pending = sessionStorage.getItem('quicksite_pending_message');
        if (pending) {
            sessionStorage.removeItem('quicksite_pending_message');
            try {
                const msg = JSON.parse(pending);
                // Small delay to ensure page is loaded
                setTimeout(() => {
                    this.showToast(msg.message, msg.type || 'success', msg.duration || 6000);
                }, 500);
            } catch (e) {
                console.error('Failed to parse pending message:', e);
            }
        }
    },

    /**
     * Store a message to show after redirect - delegates to QuickSiteUtils
     */
    setPendingMessage(message, type = 'success', duration = 6000) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.setPendingMessage(message, type, duration);
        }
        // Fallback
        sessionStorage.setItem('quicksite_pending_message', JSON.stringify({
            message,
            type,
            duration
        }));
    },

    /**
     * Load user preferences from localStorage
     */
    loadPreferences() {
        this.prefs = JSON.parse(localStorage.getItem(this.config.prefsStorageKey) || '{}');
    },

    /**
     * Get a preference value with default
     */
    getPref(key, defaultValue) {
        // Delegate to core utils if available, otherwise use local prefs
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.getPref(key, defaultValue);
        }
        return this.prefs[key] !== undefined ? this.prefs[key] : defaultValue;
    },

    /**
     * Get stored token - delegates to QuickSiteAPI
     */
    getToken() {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.getToken();
        }
        // Fallback for when core module isn't loaded
        return localStorage.getItem(this.config.tokenStorageKey) || 
               sessionStorage.getItem(this.config.tokenStorageKey);
    },

    /**
     * Store token - delegates to QuickSiteAPI
     */
    setToken(token, remember = false) {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.setToken(token, remember);
        }
        // Fallback
        if (remember) {
            localStorage.setItem(this.config.tokenStorageKey, token);
            localStorage.setItem(this.config.rememberStorageKey, 'true');
        } else {
            sessionStorage.setItem(this.config.tokenStorageKey, token);
            localStorage.removeItem(this.config.tokenStorageKey);
            localStorage.removeItem(this.config.rememberStorageKey);
        }
    },

    /**
     * Clear stored token - delegates to QuickSiteAPI
     */
    clearToken() {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.clearToken();
        }
        // Fallback
        localStorage.removeItem(this.config.tokenStorageKey);
        sessionStorage.removeItem(this.config.tokenStorageKey);
        localStorage.removeItem(this.config.rememberStorageKey);
    },

    /**
     * Make an API request - delegates to QuickSiteAPI
     */
    async apiRequest(command, method = 'GET', data = null, urlParams = [], queryParams = {}) {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.request(command, method, data, urlParams, queryParams);
        }
        
        // Fallback implementation
        const token = this.getToken();
        if (!token) {
            throw new Error('No authentication token');
        }

        let url = `${this.config.apiBase}/${command}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }
        
        // Add query parameters for GET requests
        if (Object.keys(queryParams).length > 0) {
            const searchParams = new URLSearchParams();
            for (const [key, value] of Object.entries(queryParams)) {
                if (value !== null && value !== undefined && value !== '') {
                    searchParams.append(key, value);
                }
            }
            const queryString = searchParams.toString();
            if (queryString) {
                url += '?' + queryString;
            }
        }

        const options = {
            method: method,
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            }
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        
        // Handle 204 No Content responses (empty body)
        let result = null;
        if (response.status !== 204) {
            const text = await response.text();
            if (text) {
                try {
                    result = JSON.parse(text);
                } catch {
                    result = { message: text };
                }
            }
        }
        
        // For 204, create a success response
        if (response.status === 204) {
            result = { status: 204, code: 'operation.success', message: 'Operation completed successfully' };
        }
        
        // Dispatch event for successful write operations (non-GET)
        // This allows miniplayer to auto-reload preview
        if (response.ok && method !== 'GET') {
            window.dispatchEvent(new CustomEvent('quicksite:command-executed', {
                detail: { command, method, success: true }
            }));
        }

        return {
            ok: response.ok,
            status: response.status,
            data: result
        };
    },

    /**
     * Make an API request with file upload - delegates to QuickSiteAPI
     */
    async apiUpload(command, formData, urlParams = []) {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.upload(command, formData, urlParams);
        }
        
        // Fallback implementation
        const token = this.getToken();
        if (!token) {
            throw new Error('No authentication token');
        }

        let url = `${this.config.apiBase}/${command}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`
            },
            body: formData
        });

        const result = await response.json();

        return {
            ok: response.ok,
            status: response.status,
            data: result
        };
    },

    /**
     * Initialize category toggle functionality
     */
    initCategoryToggles() {
        document.querySelectorAll('.admin-category__header').forEach(header => {
            header.addEventListener('click', () => {
                const category = header.closest('.admin-category');
                category.classList.toggle('admin-category--open');
            });
        });
    },

    /**
     * Initialize form handling
     */
    initForms() {
        // Login form
        const loginForm = document.getElementById('admin-login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', (e) => this.handleLogin(e));
        }

        // Command execution forms
        document.querySelectorAll('.admin-command-form').forEach(form => {
            form.addEventListener('submit', (e) => this.handleCommandSubmit(e));
        });
    },

    /**
     * Handle login form submission
     */
    async handleLogin(e) {
        e.preventDefault();
        
        const form = e.target;
        const token = form.querySelector('[name="token"]').value.trim();
        const remember = form.querySelector('[name="remember"]')?.checked || false;
        const submitBtn = form.querySelector('[type="submit"]');
        const errorDiv = form.querySelector('.admin-alert--error');
        
        // Validate token format
        if (!token) {
            this.showFormError(errorDiv, 'Please enter your API token');
            return;
        }

        // Disable button and show loading
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="admin-spinner"></span> Verifying...';
        
        // Hide previous error
        if (errorDiv) errorDiv.style.display = 'none';

        try {
            // Test the token by making a simple API call (help command is always available)
            this.setToken(token, remember);
            const result = await this.apiRequest('help');
            
            if (result.ok) {
                // Token is valid, submit the form to set server-side session
                form.submit();
            } else {
                this.clearToken();
                this.showFormError(errorDiv, result.data?.message || 'Invalid token');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Login';
            }
        } catch (error) {
            this.clearToken();
            this.showFormError(errorDiv, 'Could not connect to API');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Login';
        }
    },

    /**
     * Commands that require confirmation before execution
     */
    destructiveCommands: [
        'deleteRoute', 'deleteAsset', 'removeLang', 'resetAll', 
        'deleteComponent', 'clearLogs', 'removeBackup'
    ],

    /**
     * Commands that change critical paths and need special handling
     * Note: renameSecureFolder doesn't change public URLs, so it's not included
     */
    pathChangingCommands: ['setPublicSpace', 'renamePublicFolder'],

    /**
     * Handle command form submission
     */
    async handleCommandSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        
        // Check if form is in batch mode - if so, don't execute (batch handler will take over)
        if (form.dataset.batchMode === 'true') {
            return;
        }
        
        const command = form.dataset.command;
        const method = form.dataset.method || 'POST';
        const submitBtn = form.querySelector('[type="submit"]');
        const responseDiv = document.getElementById('command-response');
        
        // Check if this is a destructive command that needs confirmation
        if (this.destructiveCommands.includes(command)) {
            const confirmed = await this.confirm(
                `You are about to execute "${command}". This action may make permanent changes. Continue?`,
                {
                    title: 'Confirm Action',
                    type: 'warning',
                    confirmText: 'Execute',
                    confirmClass: 'primary'
                }
            );
            
            if (!confirmed) {
                return;
            }
        }
        
        // Special warning for path-changing commands
        if (this.pathChangingCommands.includes(command)) {
            const confirmed = await this.confirm(
                `⚠️ WARNING: This command will change your site's URL structure!\n\n` +
                `• The admin panel URL will change\n` +
                `• You will be redirected to the new location\n` +
                `• Make sure you remember the new URL\n\n` +
                `Do you want to continue?`,
                {
                    title: 'URL Structure Change',
                    type: 'warning',
                    confirmText: 'Yes, Change URL',
                    confirmClass: 'primary'
                }
            );
            
            if (!confirmed) {
                return;
            }
        }
        
        // Collect form data
        const formData = new FormData(form);
        const data = {};
        const urlParams = [];
        
        // Check if this is a file upload
        let hasFile = false;
        for (const [key, value] of formData.entries()) {
            if (value instanceof File && value.size > 0) {
                hasFile = true;
                break;
            }
        }
        
        if (hasFile) {
            // Use FormData for file uploads
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="admin-spinner"></span> Uploading...';
            
            try {
                const result = await this.apiUpload(command, formData, urlParams);
                this.displayResponse(responseDiv, result);
                
                // Show toast notification
                if (result.ok) {
                    this.showToast('Command executed successfully!', 'success');
                } else {
                    this.showToast(result.data?.message || 'Command failed', 'error');
                }
            } catch (error) {
                this.displayResponse(responseDiv, {
                    ok: false,
                    status: 0,
                    data: { error: error.message }
                });
                this.showToast('Error: ' + error.message, 'error');
            }
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Execute Command';
        } else {
            // Build JSON data
            for (const [key, value] of formData.entries()) {
                // Handle URL parameters (marked with data-url-param)
                const input = form.querySelector(`[name="${key}"]`);
                if (input?.dataset.urlParam !== undefined) {
                    if (value) urlParams.push(value);
                } else if (value) {
                    // Try to parse JSON values
                    try {
                        data[key] = JSON.parse(value);
                    } catch {
                        data[key] = value;
                    }
                }
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="admin-spinner"></span> Executing...';
            
            try {
                const result = await this.apiRequest(command, method, method === 'GET' ? null : data, urlParams);
                this.displayResponse(responseDiv, result);
                
                // Show toast notification
                if (result.ok) {
                    // Special handling for path-changing commands
                    if (this.pathChangingCommands.includes(command) && result.data?.data) {
                        this.handlePathChange(command, result.data.data, data);
                        return; // Don't reset button, we're redirecting
                    }
                    
                    this.showToast('Command executed successfully!', 'success');
                    
                    // Dispatch custom event for command success
                    // This allows forms to react (e.g., refresh selects after deletion)
                    form.dispatchEvent(new CustomEvent('command-success', {
                        detail: { command, data, result: result.data }
                    }));
                } else {
                    this.showToast(result.data?.message || 'Command failed', 'error');
                }
            } catch (error) {
                this.displayResponse(responseDiv, {
                    ok: false,
                    status: 0,
                    data: { error: error.message }
                });
                this.showToast('Error: ' + error.message, 'error');
            }
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Execute Command';
        }
    },

    /**
     * Display API response
     */
    displayResponse(container, result) {
        if (!container) return;
        
        const statusClass = result.ok ? 'admin-alert--success' : 'admin-alert--error';
        const statusText = result.ok ? 'Success' : 'Error';
        const responseJson = JSON.stringify(result.data, null, 2);
        
        container.innerHTML = `
            <div class="admin-alert ${statusClass}">
                <strong>${statusText}</strong> (Status: ${result.status})
            </div>
            <div class="admin-code admin-code--response">
                <div class="admin-code__header">
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="QuickSiteAdmin.copyResponse(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        Copy
                    </button>
                </div>
                <pre>${this.escapeHtml(responseJson)}</pre>
            </div>
        `;
        
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    /**
     * Copy command response to clipboard
     */
    copyResponse(button) {
        const pre = button.closest('.admin-code').querySelector('pre');
        if (pre) {
            this.utils.copyToClipboard(pre.textContent, 'Response copied to clipboard!');
        }
    },

    /**
     * Handle path-changing commands (setPublicSpace, etc.)
     * Calculates new admin URL and redirects
     */
    handlePathChange(command, responseData, requestData) {
        let newAdminUrl;
        const protocol = window.location.protocol;
        const host = window.location.host;
        
        if (command === 'setPublicSpace') {
            // Get the new destination from the response or request
            const newSpace = responseData.destination ?? requestData.destination ?? '';
            
            if (newSpace) {
                newAdminUrl = `${protocol}//${host}/${newSpace}/admin/dashboard`;
            } else {
                newAdminUrl = `${protocol}//${host}/admin/dashboard`;
            }
        } else if (command === 'renamePublicFolder' || command === 'renameSecureFolder') {
            // For folder rename, the public space doesn't change, so just reload
            newAdminUrl = this.config.adminBase + '/dashboard';
        }
        
        // Store success message to show after redirect
        this.setPendingMessage(
            `✅ ${command} executed successfully! URL structure updated.`,
            'success',
            8000
        );
        
        // Show countdown message
        const responseDiv = document.getElementById('command-response');
        if (responseDiv) {
            let countdown = 3;
            const updateCountdown = () => {
                responseDiv.innerHTML = `
                    <div class="admin-alert admin-alert--success">
                        <strong>Success!</strong> Redirecting to new admin URL in ${countdown}...
                    </div>
                    <div class="admin-code admin-code--response">
                        <pre>${this.escapeHtml(JSON.stringify(responseData, null, 2))}</pre>
                    </div>
                    <p style="margin-top: var(--space-md);">
                        <strong>New URL:</strong> <a href="${newAdminUrl}">${newAdminUrl}</a>
                    </p>
                `;
            };
            
            updateCountdown();
            
            const interval = setInterval(() => {
                countdown--;
                if (countdown <= 0) {
                    clearInterval(interval);
                    window.location.href = newAdminUrl;
                } else {
                    updateCountdown();
                }
            }, 1000);
        } else {
            // No response div, redirect immediately
            setTimeout(() => {
                window.location.href = newAdminUrl;
            }, 500);
        }
    },

    /**
     * Show form error message
     */
    showFormError(errorDiv, message) {
        if (errorDiv) {
            errorDiv.innerHTML = `
                <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>${this.escapeHtml(message)}</span>
            `;
            errorDiv.style.display = 'flex';
        }
    },

    /**
     * Initialize copy to clipboard buttons
     */
    initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.querySelector(btn.dataset.copy);
                if (target) {
                    navigator.clipboard.writeText(target.textContent).then(() => {
                        const originalText = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(() => btn.textContent = originalText, 2000);
                    });
                }
            });
        });
    },

    /**
     * Escape HTML to prevent XSS - delegates to QuickSiteUtils
     */
    escapeHtml(text) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.escapeHtml(text);
        }
        // Fallback
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Format JSON for display - delegates to QuickSiteUtils
     */
    formatJson(data) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.formatJson(data);
        }
        return JSON.stringify(data, null, 2);
    },

    // ============================================
    // Helper API Methods for Dynamic Form Options
    // ============================================

    /**
     * Fetch data from admin helper API - delegates to QuickSiteAPI
     */
    async fetchHelperData(action, params = []) {
        if (window.QuickSiteAPI) {
            return window.QuickSiteAPI.fetchHelper(action, params);
        }
        
        // Fallback
        const token = this.getToken();
        if (!token) {
            throw new Error('No authentication token');
        }

        let url = `/admin/api/${action}`;
        if (params.length > 0) {
            url += '/' + params.join('/');
        }

        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });

        const result = await response.json();
        
        if (response.ok && result.success) {
            return result.data;
        }
        
        throw new Error(result.error || 'Failed to fetch data');
    },

    /**
     * Populate a select element with options (supports optgroups)
     */
    async populateSelect(selectElement, action, params = [], placeholder = 'Select...') {
        if (!selectElement) return;
        
        // Show loading state
        selectElement.disabled = true;
        selectElement.innerHTML = `<option value="">${placeholder}</option>`;
        
        try {
            const options = await this.fetchHelperData(action, params);
            selectElement.innerHTML = `<option value="">${placeholder}</option>`;
            
            // Handle flat array or hierarchical structure
            if (Array.isArray(options)) {
                this.appendOptionsToSelect(selectElement, options);
            }
        } catch (error) {
            console.error('Failed to populate select:', error);
            const errorMsg = error.message || 'Error loading options';
            selectElement.innerHTML = `<option value="">Error: ${errorMsg}</option>`;
        }
        
        selectElement.disabled = false;
    },
    
    /**
     * Recursively append options/optgroups to a select element
     */
    appendOptionsToSelect(parent, options) {
        options.forEach(opt => {
            if (opt.type === 'optgroup') {
                // Create optgroup
                const optgroup = document.createElement('optgroup');
                optgroup.label = opt.label;
                
                // Recursively add children
                if (opt.options && opt.options.length > 0) {
                    this.appendOptionsToSelect(optgroup, opt.options);
                }
                
                parent.appendChild(optgroup);
            } else {
                // Regular option
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                parent.appendChild(option);
            }
        });
    },
    
    /**
     * Populate a select with grouped options (e.g., used/unused keys)
     */
    async populateSelectGrouped(selectElement, action, params = [], placeholder = 'Select...', groups = {}) {
        if (!selectElement) return;
        
        // Show loading state
        selectElement.disabled = true;
        selectElement.innerHTML = `<option value="">${placeholder}</option>`;
        
        try {
            const data = await this.fetchHelperData(action, params);
            selectElement.innerHTML = `<option value="">${placeholder}</option>`;
            
            // data should be an object with group keys
            // groups maps data keys to display labels
            for (const [dataKey, groupLabel] of Object.entries(groups)) {
                if (data[dataKey] && data[dataKey].length > 0) {
                    const optgroup = document.createElement('optgroup');
                    optgroup.label = `${groupLabel} (${data[dataKey].length})`;
                    
                    data[dataKey].forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        optgroup.appendChild(option);
                    });
                    
                    selectElement.appendChild(optgroup);
                }
            }
        } catch (error) {
            console.error('Failed to populate grouped select:', error);
            selectElement.innerHTML = `<option value="">Error loading options</option>`;
        }
        
        selectElement.disabled = false;
    },

    /**
     * Initialize cascading selects for a form
     * @param {Object} config - Configuration object with dependencies
     */
    initCascadingSelects(config) {
        const { container, selects } = config;
        const form = typeof container === 'string' ? document.querySelector(container) : container;
        if (!form) return;

        selects.forEach((selectConfig, index) => {
            const select = form.querySelector(`[name="${selectConfig.name}"]`);
            if (!select) return;

            // Initial population for selects without dependencies
            if (!selectConfig.dependsOn) {
                this.populateSelect(select, selectConfig.action, [], selectConfig.placeholder);
            }

            // Add change listener for selects that others depend on
            select.addEventListener('change', async () => {
                // Find dependent selects and update them
                selects.forEach((depConfig, depIndex) => {
                    if (depConfig.dependsOn === selectConfig.name) {
                        const depSelect = form.querySelector(`[name="${depConfig.name}"]`);
                        if (depSelect) {
                            const parentValue = select.value;
                            if (parentValue) {
                                const params = typeof depConfig.params === 'function' 
                                    ? depConfig.params(form)
                                    : [parentValue];
                                this.populateSelect(depSelect, depConfig.action, params, depConfig.placeholder);
                            } else {
                                // Reset dependent select
                                depSelect.innerHTML = `<option value="">${depConfig.placeholder}</option>`;
                            }
                        }
                    }
                });
            });
        });
    },

    // ============================================
    // Toast Notifications
    // ============================================

    /**
     * Show a toast notification - delegates to QuickSiteUtils
     */
    showToast(message, type = 'info', duration = null) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.showToast(message, type, duration);
        }
        
        // Fallback implementation
        // Use preference duration if not explicitly provided
        if (duration === null) {
            duration = parseInt(this.getPref('toastDuration', 4000));
        }
        
        // Create toast container if it doesn't exist
        let container = document.querySelector('.admin-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'admin-toast-container';
            document.body.appendChild(container);
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.className = `admin-toast admin-toast--${type}`;
        
        const icons = {
            success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
        };

        toast.innerHTML = `
            <span class="admin-toast__icon">${icons[type] || icons.info}</span>
            <span class="admin-toast__message">${this.escapeHtml(message)}</span>
            <button class="admin-toast__close" onclick="this.parentElement.remove()">×</button>
        `;

        container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => toast.classList.add('admin-toast--visible'));

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                toast.classList.remove('admin-toast--visible');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        return toast;
    },

    // ============================================
    // Confirmation Dialogs
    // ============================================

    /**
     * Show a confirmation dialog - delegates to QuickSiteUtils
     */
    async confirm(message, options = {}) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.confirm(message, options);
        }
        
        // Fallback implementation
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'admin-modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'admin-modal admin-modal--confirm';
            modal.innerHTML = `
                <div class="admin-modal__content">
                    <div class="admin-modal__icon admin-modal__icon--${options.type || 'warning'}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <h3 class="admin-modal__title">${options.title || 'Confirm Action'}</h3>
                    <p class="admin-modal__message">${this.escapeHtml(message)}</p>
                    <div class="admin-modal__actions">
                        <button class="admin-btn admin-btn--secondary admin-modal__cancel">
                            ${options.cancelText || 'Cancel'}
                        </button>
                        <button class="admin-btn admin-btn--${options.confirmClass || 'primary'} admin-modal__confirm">
                            ${options.confirmText || 'Confirm'}
                        </button>
                    </div>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Animate in
            requestAnimationFrame(() => overlay.classList.add('admin-modal-overlay--visible'));

            const cleanup = (result) => {
                overlay.classList.remove('admin-modal-overlay--visible');
                setTimeout(() => overlay.remove(), 300);
                resolve(result);
            };

            modal.querySelector('.admin-modal__cancel').addEventListener('click', () => cleanup(false));
            modal.querySelector('.admin-modal__confirm').addEventListener('click', () => cleanup(true));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) cleanup(false);
            });
        });
    },

    /**
     * Confirm destructive action - delegates to QuickSiteUtils
     */
    async confirmDelete(itemName) {
        if (window.QuickSiteUtils) {
            return window.QuickSiteUtils.confirmDelete(itemName);
        }
        // Fallback
        return this.confirm(`Are you sure you want to delete "${itemName}"? This action cannot be undone.`, {
            title: 'Confirm Deletion',
            type: 'danger',
            confirmText: 'Delete',
            confirmClass: 'danger'
        });
    },

    // ============================================
    // JSON Editor Helper
    // ============================================

    /**
     * Initialize JSON editor for a textarea
     */
    initJsonEditor(textarea) {
        if (!textarea) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'admin-json-editor';
        textarea.parentNode.insertBefore(wrapper, textarea);
        wrapper.appendChild(textarea);

        // Add toolbar
        const toolbar = document.createElement('div');
        toolbar.className = 'admin-json-editor__toolbar';
        toolbar.innerHTML = `
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" data-action="format">
                Format JSON
            </button>
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" data-action="validate">
                Validate
            </button>
            <span class="admin-json-editor__status"></span>
        `;
        wrapper.insertBefore(toolbar, textarea);

        // Add validation indicator
        const statusEl = toolbar.querySelector('.admin-json-editor__status');

        // Format button
        toolbar.querySelector('[data-action="format"]').addEventListener('click', () => {
            try {
                const parsed = JSON.parse(textarea.value);
                textarea.value = JSON.stringify(parsed, null, 2);
                this.setJsonStatus(statusEl, 'Valid JSON', 'success');
            } catch (e) {
                this.setJsonStatus(statusEl, 'Invalid JSON: ' + e.message, 'error');
            }
        });

        // Validate button
        toolbar.querySelector('[data-action="validate"]').addEventListener('click', () => {
            try {
                JSON.parse(textarea.value);
                this.setJsonStatus(statusEl, 'Valid JSON', 'success');
            } catch (e) {
                this.setJsonStatus(statusEl, 'Invalid: ' + e.message, 'error');
            }
        });

        // Real-time validation on input
        textarea.addEventListener('input', () => {
            if (!textarea.value.trim()) {
                statusEl.textContent = '';
                return;
            }
            try {
                JSON.parse(textarea.value);
                this.setJsonStatus(statusEl, '✓', 'success');
            } catch {
                this.setJsonStatus(statusEl, '✗', 'error');
            }
        });
    },

    /**
     * Set JSON editor status
     */
    setJsonStatus(el, message, type) {
        el.textContent = message;
        el.className = `admin-json-editor__status admin-json-editor__status--${type}`;
    },

    // ============================================
    // Keyboard Shortcuts
    // ============================================

    /**
     * Initialize keyboard shortcuts
     */
    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Check preference dynamically so changes take effect immediately
            if (!this.getPref('shortcuts', true)) return;
            
            // Don't trigger when typing in inputs
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                // Allow Escape to blur inputs
                if (e.key === 'Escape') {
                    e.target.blur();
                }
                return;
            }

            // ? - Show shortcuts help
            if (e.key === '?' || (e.shiftKey && e.key === '/')) {
                e.preventDefault();
                this.showShortcutsHelp();
                return;
            }

            // g + key combinations (vim-style navigation)
            if (e.key === 'g' && !e.ctrlKey && !e.metaKey) {
                this.waitingForG = true;
                setTimeout(() => { this.waitingForG = false; }, 1000);
                return;
            }

            if (this.waitingForG) {
                this.waitingForG = false;
                switch (e.key) {
                    case 'd': // go to dashboard
                        window.location.href = this.config.adminBase + '/dashboard';
                        break;
                    case 'c': // go to commands
                        window.location.href = this.config.adminBase + '/command';
                        break;
                    case 'h': // go to history
                        window.location.href = this.config.adminBase + '/history';
                        break;
                    case 's': // go to structure
                        window.location.href = this.config.adminBase + '/structure';
                        break;
                    case 't': // go to settings
                        window.location.href = this.config.adminBase + '/settings';
                        break;
                }
                return;
            }

            // / - Focus search input
            if (e.key === '/' && !e.ctrlKey) {
                const searchInput = document.querySelector('.admin-input[type="text"][placeholder*="Search"], #command-search, #search-input');
                if (searchInput) {
                    e.preventDefault();
                    searchInput.focus();
                }
                return;
            }

            // Escape - Close modals/panels
            if (e.key === 'Escape') {
                const modal = document.querySelector('.admin-modal-overlay--visible');
                if (modal) {
                    modal.querySelector('.admin-modal__cancel')?.click();
                }
                const nodeDetails = document.getElementById('node-details');
                if (nodeDetails && nodeDetails.style.display !== 'none') {
                    nodeDetails.style.display = 'none';
                }
            }
        });
    },

    /**
     * Show keyboard shortcuts help modal
     */
    showShortcutsHelp() {
        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay admin-modal-overlay--visible';
        
        const modal = document.createElement('div');
        modal.className = 'admin-modal admin-modal--shortcuts';
        modal.innerHTML = `
            <div class="admin-modal__content">
                <h3 class="admin-modal__title">Keyboard Shortcuts</h3>
                <div class="admin-shortcuts-grid">
                    <div class="admin-shortcut-group">
                        <h4>Navigation (g + key)</h4>
                        <div class="admin-shortcut"><kbd>g</kbd> <kbd>d</kbd> <span>Dashboard</span></div>
                        <div class="admin-shortcut"><kbd>g</kbd> <kbd>c</kbd> <span>Commands</span></div>
                        <div class="admin-shortcut"><kbd>g</kbd> <kbd>h</kbd> <span>History</span></div>
                        <div class="admin-shortcut"><kbd>g</kbd> <kbd>s</kbd> <span>Structure</span></div>
                        <div class="admin-shortcut"><kbd>g</kbd> <kbd>t</kbd> <span>Settings</span></div>
                    </div>
                    <div class="admin-shortcut-group">
                        <h4>Actions</h4>
                        <div class="admin-shortcut"><kbd>/</kbd> <span>Focus search</span></div>
                        <div class="admin-shortcut"><kbd>?</kbd> <span>Show this help</span></div>
                        <div class="admin-shortcut"><kbd>Esc</kbd> <span>Close modal / blur input</span></div>
                    </div>
                </div>
                <div class="admin-modal__actions">
                    <button class="admin-btn admin-btn--primary admin-modal__close">Close</button>
                </div>
            </div>
        `;

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        const close = () => {
            overlay.classList.remove('admin-modal-overlay--visible');
            setTimeout(() => overlay.remove(), 300);
        };

        modal.querySelector('.admin-modal__close').addEventListener('click', close);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
        });

        // Close on escape
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                close();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    },

    // ============================================
    // History Export
    // ============================================

    /**
     * Export command history to JSON file
     */
    async exportHistory() {
        try {
            const result = await this.apiRequest('getCommandHistory');
            
            if (result.ok && result.data.data?.history) {
                const dataStr = JSON.stringify(result.data.data.history, null, 2);
                const dataBlob = new Blob([dataStr], { type: 'application/json' });
                
                const link = document.createElement('a');
                link.href = URL.createObjectURL(dataBlob);
                link.download = `command-history-${new Date().toISOString().split('T')[0]}.json`;
                link.click();
                
                this.showToast('History exported successfully', 'success');
            } else {
                this.showToast('No history to export', 'warning');
            }
        } catch (error) {
            this.showToast('Failed to export history', 'error');
        }
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    QuickSiteAdmin.init();
});

// Export for use in other scripts
window.QuickSiteAdmin = QuickSiteAdmin;
