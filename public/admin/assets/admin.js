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
            // The per-session token, emitted with the page. There is no cookie
            // fallback: the session cookie is HttpOnly by design and JS cannot
            // read it — a page with no token is a page with no session.
            return window.QUICKSITE_CONFIG?.token || '';
        },
        get defaultLang() {
            return window.QUICKSITE_CONFIG?.defaultLang || 'en';
        },
        get multilingual() {
            return window.QUICKSITE_CONFIG?.multilingual || false;
        }
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
            // Your role and the commands it grants: a fact about your ACCOUNT,
            // so it comes from /admin/self rather than from a command.
            const response = await this.accountRequest('permissions', 'GET');
            if (response.ok && response.data?.data) {
                this.permissions = {
                    loaded: true,
                    role: response.data.data.role,
                    commands: (() => { const c = response.data.data.commands; return Array.isArray(c) ? c : Object.values(c || {}); })(),
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
        
        // "Not logged in" ONLY when there is genuinely no session (no token /
        // failed load). An authenticated user with role null is simply a
        // member of no project (C8 — e.g. freshly registered): show who they
        // are with a "no project" chip instead.
        if (!this.permissions.loaded || (!this.permissions.role && !this.permissions.tokenName)) {
            nameEl.textContent = this.t('nav.notLoggedIn');
            roleEl.textContent = '';
            roleEl.removeAttribute('data-role');
            return;
        }

        // Display token name (shortened if too long)
        const name = this.permissions.tokenName || this.t('nav.unknownUser');
        nameEl.textContent = name.length > 20 ? name.substring(0, 20) + '...' : name;
        nameEl.title = name;

        const role = this.permissions.role;
        if (role) {
            roleEl.textContent = role;
            roleEl.setAttribute('data-role', role);
        } else {
            roleEl.textContent = this.t('nav.noProject');
            roleEl.removeAttribute('data-role');
        }
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
        this.initNavGroups();
        this.initCategoryToggles();
        this.initForms();
        this.initCopyButtons();
        this.initKeyboardShortcuts();
        this.checkPendingMessage();
        
        // Permissions are already loading (started at parse time via QuickSiteAdmin.permissionsReady).
        // filterByPermissions() and updateUserBadge() will be called when that fetch resolves.
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
            
            // For anchor toggles, allow click to navigate (don't prevent default)
            // For button toggles (mobile), toggle the dropdown
            toggle.addEventListener('click', (e) => {
                if (toggle.tagName === 'BUTTON') {
                    e.preventDefault();
                    e.stopPropagation();
                    group.classList.toggle('admin-nav__group--open');
                }
                // Anchor clicks will navigate naturally
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
     * Check for pending message from redirect - delegates to QuickSiteUtils
     */
    checkPendingMessage() {
        return window.QuickSiteUtils.checkPendingMessage();
    },

    /**
     * Store a message to show after redirect - delegates to QuickSiteUtils
     */
    setPendingMessage(message, type = 'success', duration = 6000) {
        return window.QuickSiteUtils.setPendingMessage(message, type, duration);
    },

    /**
     * Get a preference value with default
     */
    getPref(key, defaultValue) {
        return window.QuickSiteUtils.getPref(key, defaultValue);
    },

    /**
     * Get the per-session token - delegates to QuickSiteAPI (it never lives in
     * browser storage; the page-embedded config is the fallback)
     */
    getToken() {
        return window.QuickSiteAPI.getToken();
    },

    /**
     * Clear the per-session token - delegates to QuickSiteAPI
     */
    clearToken() {
        return window.QuickSiteAPI.clearToken();
    },

    /**
     * Make an API request - delegates to QuickSiteAPI
     */
    async apiRequest(command, method = 'GET', data = null, urlParams = [], queryParams = {}, opts = {}) {
        return window.QuickSiteAPI.request(command, method, data, urlParams, queryParams, false, opts);
    },

    /**
     * Download a command's response as a file - delegates to QuickSiteAPI.
     *
     * For the commands that answer BYTES instead of a JSON envelope
     * (downloadBuild, downloadExport). A plain link cannot be used: this
     * surface needs an Authorization header as well as the session cookie.
     * There is no fallback implementation - without QuickSiteAPI there is no
     * token plumbing to hand-roll this against.
     *
     * @returns {Promise<{ok: boolean, status: number, data: Object|null, filename?: string}>}
     */
    async downloadFile(command, urlParams = [], queryParams = {}, opts = {}) {
        return window.QuickSiteAPI.downloadFile(command, urlParams, queryParams, opts);
    },

    /**
     * Change which project this user's panel edits - delegates to QuickSiteAPI.
     *
     * NOT a command: the command surface is a CLI for developing a project, and
     * which project somebody has open is panel state. It goes to /admin/state,
     * and resolves with the same {ok, status, data} shape apiRequest uses, so
     * every caller branches on res.ok exactly as before.
     *
     * @returns {Promise<{ok: boolean, status: number, data: Object|null}>}
     */
    async setSelectedProject(projectId) {
        return window.QuickSiteAPI.setSelectedProject(projectId);
    },

    /**
     * Call the account endpoint - delegates to QuickSiteAPI.
     *
     * NOT commands: account self-service, membership self-service and the two
     * directory lookups go to /admin/self, and resolve with the same
     * {ok, status, data} shape apiRequest uses, so every caller branches on
     * res.ok exactly as before.
     *
     * @returns {Promise<{ok: boolean, status: number, data: Object|null}>}
     */
    async accountRequest(route, method, body) {
        return window.QuickSiteAPI.accountRequest(route, method, body);
    },

    /**
     * Make an API request with file upload - delegates to QuickSiteAPI
     */
    async apiUpload(command, formData, urlParams = []) {
        return window.QuickSiteAPI.upload(command, formData, urlParams);
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
        // Login form: plain server-side POST (C5b username+password — the router
        // verifies credentials and holds the session; no JS pre-validation).

        // Command execution forms
        document.querySelectorAll('.admin-command-form').forEach(form => {
            form.addEventListener('submit', (e) => this.handleCommandSubmit(e));
        });
    },

    /**
     * Look up a translation by dot path.
     *
     * Reads the same QUICKSITE_CONFIG.translations object layout.php emits, so
     * a key missing from a language renders as its own dot path — which is how
     * an unset string stays visible and findable instead of hiding behind an
     * English fallback.
     *
     * @param {string} path        e.g. 'commands.execute'
     * @param {*} [fallback]       returned only when the path is absent
     * @returns {*} the value, or `fallback`, or the path itself
     */
    t(path, fallback) {
        const parts = path.split('.');
        let current = window.QUICKSITE_CONFIG?.translations || {};
        for (const part of parts) {
            if (current && typeof current === 'object' && part in current) {
                current = current[part];
            } else {
                return fallback !== undefined ? fallback : path;
            }
        }
        return current === undefined || current === '' ? (fallback !== undefined ? fallback : path) : current;
    },

    /**
     * Does executing this command warrant an "are you sure"?
     *
     * TWO sources, in this order:
     *
     *  1. The command's own `help` entry, handed over by command-form.js as
     *     `data-destructive` on the form. Driven by the spec, exactly like
     *     `data-binary-response` beside it — so declaring a command destructive
     *     is a help.php edit and nothing else, and a deleted command takes its
     *     flag with it.
     *  2. A naming convention, for everything the flag does not name.
     *
     * The convention exists because the list this replaced was SEVEN command
     * names of which FIVE no longer existed: it gated 2 live commands while a
     * dozen genuinely destructive ones ran with no prompt. A convention cannot
     * rot that way — a removed command simply stops existing — and it covers 24
     * of the 153. The flag covers what a name does not reveal
     * (transferOwnership, restoreBackup, importProject, deployBuild …).
     *
     * Reading flag OR convention means forgetting the flag on an obvious
     * `deleteFoo` is harmless.
     *
     * ⚠ This is a safety net, not a security control. Permissions authorise
     * every one of these commands server-side; the prompt only asks a human to
     * look twice.
     *
     * @param {string} command
     * @param {HTMLFormElement} [form]  the submitting form, for the help flag
     * @returns {boolean}
     */
    isDestructiveCommand(command, form) {
        if (form && form.dataset.destructive === '1') return true;
        return /^(delete|remove|clear|reset|purge)/i.test(command || '');
    },

    /**
     * Handle command form submission
     */
    async handleCommandSubmit(e) {
        e.preventDefault();
        
        const form = e.target;

        // A command the console lists and documents but does not run from this
        // surface (command-form.js sets the flag and puts the reason on screen).
        // The submit button is already disabled; this catches every other way a
        // form reaches submit. Refused silently — the banner above the fields is
        // the explanation, and a toast on top of it would only repeat it.
        //
        // ⚠ Not a security control. Permissions authorise every command
        // server-side; this closes one client's submit path, nothing more.
        if (form.dataset.notExecutable === '1') {
            return;
        }

        const command = form.dataset.command;
        const method = form.dataset.method || 'POST';
        const submitBtn = form.querySelector('[type="submit"]');
        const responseDiv = document.getElementById('command-response');
        
        // Check if this is a destructive command that needs confirmation
        if (this.isDestructiveCommand(command, form)) {
            const confirmed = await this.confirm(
                String(this.t('commands.confirmDestructive.message')).replace(':command', command),
                {
                    title: this.t('commands.confirmDestructive.title'),
                    type: 'warning',
                    confirmText: this.t('common.execute'),
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
            QSDom.setButtonBusy(submitBtn, this.t('commands.uploading'));
            
            try {
                const result = await this.apiUpload(command, formData, urlParams);
                this.displayResponse(responseDiv, result);
                
                // Show toast notification
                if (result.ok) {
                    this.showToast(this.t('commands.executedMsg'), 'success');
                } else {
                    this.showToast(result.data?.message || this.t('commands.failedMsg'), 'error');
                }
            } catch (error) {
                this.displayResponse(responseDiv, {
                    ok: false,
                    status: 0,
                    data: { error: error.message }
                });
                this.showToast(this.t('commands.errorPrefix') + error.message, 'error');
            }
            
            submitBtn.disabled = false;
            submitBtn.textContent = this.t('commands.execute');
        } else {
            // Build JSON data
            for (const [key, value] of formData.entries()) {
                // Handle URL parameters (marked with data-url-param)
                const input = form.querySelector(`[name="${key}"]`);
                if (input?.dataset.urlParam !== undefined) {
                    if (value) urlParams.push(value);
                } else if (value) {
                    // Only JSON-parse for JSON editor fields and selects (e.g. booleans)
                    // Plain text inputs/textareas stay as strings to avoid type coercion bugs
                    if (input?.hasAttribute('data-json-editor') || input?.tagName === 'SELECT') {
                        try {
                            data[key] = JSON.parse(value);
                        } catch {
                            data[key] = value;
                        }
                    } else {
                        data[key] = value;
                    }
                }
            }
            
            QSDom.setButtonBusy(submitBtn, this.t('commands.executing'));
            
            try {
                // A command declared 'binary' in help.php streams a FILE. Route it
                // through downloadFile, which fetches with the Authorization header
                // and hands the blob to the browser. apiRequest would read the ZIP
                // as text, fail to parse it, and print the mangled bytes into the
                // response panel.
                // A GET carries its parameters in the QUERY STRING, not a body:
                // the dispatcher reads $_GET into the command's $params. Passing
                // them as the body on GET dropped them entirely, so any GET
                // command with a non-URL parameter answered as if the field had
                // been left blank. downloadFile below has always passed them
                // correctly; this is the same handling for the JSON path.
                const result = form.dataset.binaryResponse === '1'
                    ? await this.downloadFile(command, urlParams, method === 'GET' ? data : {})
                    : await this.apiRequest(
                        command,
                        method,
                        method === 'GET' ? null : data,
                        urlParams,
                        method === 'GET' ? data : {}
                    );

                // A successful download has no envelope to show — displayResponse
                // would print "null". Describe what was saved instead. Errors keep
                // their real envelope: a refusal IS JSON on these commands.
                this.displayResponse(
                    responseDiv,
                    (result.ok && result.filename)
                        ? { ok: true, status: result.status, data: { downloaded: result.filename } }
                        : result
                );
                
                // Show toast notification
                if (result.ok) {
                    this.showToast(
                        result.filename
                            ? this.t('commands.downloadedPrefix') + result.filename
                            : this.t('commands.executedMsg'),
                        'success'
                    );
                    
                    // Dispatch custom event for command success
                    // This allows forms to react (e.g., refresh selects after deletion)
                    form.dispatchEvent(new CustomEvent('command-success', {
                        detail: { command, data, result: result.data }
                    }));
                } else {
                    this.showToast(result.data?.message || this.t('commands.failedMsg'), 'error');
                }
            } catch (error) {
                this.displayResponse(responseDiv, {
                    ok: false,
                    status: 0,
                    data: { error: error.message }
                });
                this.showToast(this.t('commands.errorPrefix') + error.message, 'error');
            }
            
            submitBtn.disabled = false;
            submitBtn.textContent = this.t('commands.execute');
        }
    },

    /**
     * Display API response
     */
    displayResponse(container, result) {
        if (!container) return;
        
        const statusClass = result.ok ? 'admin-alert--success' : 'admin-alert--error';
        const statusText = this.t(result.ok ? 'common.success' : 'common.error');
        const responseJson = JSON.stringify(result.data, null, 2);

        QSDom.clear(container);
        container.appendChild(this._renderResponseAlert(statusClass, statusText, result.status));
        container.appendChild(this._renderResponseBody(responseJson));

        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    /** The status line above a command response. @returns {HTMLElement} */
    _renderResponseAlert(statusClass, statusText, status) {
        return QSDom.el('div', { class: 'admin-alert ' + statusClass }, [
            QSDom.el('strong', { text: statusText }),
            ' (' + this.t('commands.responseStatus') + ': ' + status + ')'
        ]);
    },

    /** The response JSON plus its copy button. @returns {HTMLElement} */
    _renderResponseBody(responseJson) {
        const copyBtn = QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--ghost admin-btn--sm',
            onclick: () => this.copyResponse(copyBtn)
        }, [
            QSDom.iconEl(window.QuickSiteUtils.ICON_PATHS.copy, 16),
            ' ' + this.t('common.copy')
        ]);

        return QSDom.el('div', { class: 'admin-code admin-code--response' }, [
            QSDom.el('div', { class: 'admin-code__header' }, [copyBtn]),
            // textContent, so a response carrying < or & shows the bytes the
            // server actually sent rather than being parsed as markup. This is
            // what escapeHtml() was doing by hand.
            QSDom.el('pre', { text: responseJson })
        ]);
    },

    /**
     * Copy command response to clipboard
     */
    copyResponse(button) {
        const pre = button.closest('.admin-code').querySelector('pre');
        if (pre && window.QuickSiteUtils) {
            window.QuickSiteUtils.copyToClipboard(pre.textContent, 'Response copied to clipboard!');
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
        return window.QuickSiteUtils.escapeHtml(text);
    },

    /**
     * Format JSON for display - delegates to QuickSiteUtils
     */
    formatJson(data) {
        return window.QuickSiteUtils.formatJson(data);
    },

    // ============================================
    // Helper API Methods for Dynamic Form Options
    // ============================================

    /**
     * Fetch data from admin helper API - delegates to QuickSiteAPI
     */
    async fetchHelperData(action, params = []) {
        return window.QuickSiteAPI.fetchHelper(action, params);
    },

    /**
     * Populate a select element with options (supports optgroups)
     */
    async populateSelect(selectElement, action, params = [], placeholder = 'Select...') {
        if (!selectElement) return;
        
        // Show loading state
        selectElement.disabled = true;
        QSDom.setSelectPlaceholder(selectElement, placeholder);
        
        try {
            const options = await this.fetchHelperData(action, params);
            QSDom.setSelectPlaceholder(selectElement, placeholder);
            
            // Handle flat array or hierarchical structure
            if (Array.isArray(options)) {
                this.appendOptionsToSelect(selectElement, options);
            }
        } catch (error) {
            console.error('Failed to populate select:', error);
            const errorMsg = error.message || this.t('commands.errorLoadingOptions');
            QSDom.setSelectPlaceholder(selectElement, this.t('common.error') + ': ' + errorMsg);
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

    // ============================================
    // Toast Notifications
    // ============================================

    /**
     * Show a toast notification - delegates to QuickSiteUtils
     *
     * ⚠ THE FALLBACK BODY BELOW IS UNREACHABLE. layout.php loads
     * js/core/utils.js unconditionally and BEFORE admin.js, and there is no
     * other load path for either file, so window.QuickSiteUtils is always
     * defined by the time this runs. Its innerHTML was left as it is for the
     * same reason as the dead methods above — see NOTES/reports/beta12/S3b.md.
     */
    showToast(message, type = 'info', duration = null) {
        return window.QuickSiteUtils.showToast(message, type, duration);
    },

    // ============================================
    // Confirmation Dialogs
    // ============================================

    /**
     * Show a confirmation dialog - delegates to QuickSiteUtils
     *
     * ⚠ THE FALLBACK BODY BELOW IS UNREACHABLE. layout.php loads
     * js/core/utils.js unconditionally and BEFORE admin.js, and there is no
     * other load path for either file, so window.QuickSiteUtils is always
     * defined by the time this runs. Its innerHTML was left as it is for the
     * same reason as the dead methods above — see NOTES/reports/beta12/S3b.md.
     */
    async confirm(message, options = {}) {
        return window.QuickSiteUtils.confirm(message, options);
    },

    /**
     * Confirm destructive action - delegates to QuickSiteUtils
     */
    async confirmDelete(itemName) {
        return window.QuickSiteUtils.confirmDelete(itemName);
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
        const toolbar = this._renderJsonToolbar();
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
     * The JSON editor's Format / Validate bar plus its status slot.
     * Buttons keep their data-action hooks — initJsonEditor binds by selector.
     * @returns {HTMLElement}
     */
    _renderJsonToolbar() {
        const btn = (action, label) => QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--small admin-btn--secondary',
            dataset: { action: action },
            text: label
        });
        return QSDom.el('div', { class: 'admin-json-editor__toolbar' }, [
            btn('format', this.t('commands.jsonEditor.format')),
            btn('validate', this.t('common.validate')),
            QSDom.el('span', { class: 'admin-json-editor__status' })
        ]);
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
                        window.location.href = this.config.adminBase + '/command?tab=history';
                        break;
                    case 's': // go to settings
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
                    modal.querySelector('.admin-modal-dialog__cancel')?.click();
                }
                const nodeDetails = document.getElementById('node-details');
                if (nodeDetails && nodeDetails.style.display !== 'none') {
                    nodeDetails.style.display = 'none';
                }
            }
        });
    },

    /**
     * One `<kbd>g</kbd> <kbd>d</kbd> <span>Dashboard</span>` row.
     * @param {string[]} keys
     * @param {string} label
     * @returns {HTMLElement}
     */
    _renderShortcutRow(keys, label) {
        const children = [];
        keys.forEach(k => {
            children.push(QSDom.el('kbd', { text: k }));
            children.push(' ');
        });
        children.push(QSDom.el('span', { text: label }));
        return QSDom.el('div', { class: 'admin-shortcut' }, children);
    },

    /**
     * The keyboard-shortcuts help dialog.
     *
     * ⚠ The rows are written from what initKeyboardShortcuts ACTUALLY binds.
     * The markup this replaced listed `g s → Structure` and `g t → Settings`;
     * the switch has no `t` case at all, and its `s` case goes to /settings. So
     * the panel documented one shortcut that does nothing and mislabelled
     * another. Documentation corrected to match behaviour — changing the
     * bindings instead would be a behaviour change, which this slice is not.
     *
     * @returns {HTMLElement}
     */
    _renderShortcutsModal() {
        const nav = QSDom.el('div', { class: 'admin-shortcut-group' }, [
            QSDom.el('h4', { text: this.t('shortcuts.navGroup') }),
            this._renderShortcutRow(['g', 'd'], this.t('nav.dashboard')),
            this._renderShortcutRow(['g', 'c'], this.t('nav.commands')),
            this._renderShortcutRow(['g', 'h'], this.t('nav.history')),
            this._renderShortcutRow(['g', 's'], this.t('nav.settings'))
        ]);

        const actions = QSDom.el('div', { class: 'admin-shortcut-group' }, [
            QSDom.el('h4', { text: this.t('shortcuts.actionsGroup') }),
            this._renderShortcutRow(['/'], this.t('shortcuts.focusSearch')),
            this._renderShortcutRow(['?'], this.t('shortcuts.showHelp')),
            this._renderShortcutRow(['Esc'], this.t('shortcuts.closeModal'))
        ]);

        return QSDom.el('div', { class: 'admin-modal-dialog admin-modal-dialog--shortcuts' }, [
            QSDom.el('div', { class: 'admin-modal-dialog__content' }, [
                QSDom.el('h3', { class: 'admin-modal-dialog__title', text: this.t('shortcuts.title') }),
                QSDom.el('div', { class: 'admin-shortcuts-grid' }, [nav, actions]),
                QSDom.el('div', { class: 'admin-modal-dialog__actions' }, [
                    QSDom.el('button', {
                        class: 'admin-btn admin-btn--primary admin-modal-dialog__close',
                        text: this.t('common.close')
                    })
                ])
            ])
        ]);
    },

    /**
     * Show keyboard shortcuts help modal
     */
    showShortcutsHelp() {
        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay admin-modal-overlay--visible';
        
        const modal = this._renderShortcutsModal();

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        const close = () => {
            overlay.classList.remove('admin-modal-overlay--visible');
            setTimeout(() => overlay.remove(), 300);
        };

        modal.querySelector('.admin-modal-dialog__close').addEventListener('click', close);
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

};

// Kick off the permission fetch immediately at parse time (admin.js is in the footer so
// the DOM is already fully parsed). This lets page scripts whose DOMContentLoaded
// listener fires before init() to await QuickSiteAdmin.permissionsReady for real permissions.
QuickSiteAdmin.permissionsReady = QuickSiteAdmin.loadPermissions();

// Initialize when DOM is ready (remaining sync setup)
document.addEventListener('DOMContentLoaded', () => {
    QuickSiteAdmin.init();
});

// Export for use in other scripts
window.QuickSiteAdmin = QuickSiteAdmin;
