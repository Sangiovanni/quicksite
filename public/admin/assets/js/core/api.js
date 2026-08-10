/**
 * QuickSite Admin - Core API Module
 * Handles all API communication, authentication, and token management.
 * 
 * @module core/api
 * @requires window.QUICKSITE_CONFIG (from layout.php)
 */

window.QuickSiteAPI = (function() {
    'use strict';

    // ============================================
    // Configuration
    // ============================================

    // Legacy pre-C5b storage keys — referenced ONLY to clean up entries left
    // by older versions (tokens no longer touch browser storage).
    const TOKEN_KEY = 'quicksite_admin_token';
    const REMEMBER_KEY = 'quicksite_admin_remember';

    /**
     * Get configuration value
     * Falls back to data attributes on body element if QUICKSITE_CONFIG not available
     */
    const config = {
        get apiBase() {
            return window.QUICKSITE_CONFIG?.apiBase || 
                   document.body?.dataset?.apiBase || 
                   '/management';
        },
        get adminBase() {
            return window.QUICKSITE_CONFIG?.adminBase || 
                   document.body?.dataset?.adminBase || 
                   '/admin';
        },
        get baseUrl() {
            return window.QUICKSITE_CONFIG?.baseUrl || 
                   document.body?.dataset?.baseUrl || 
                   '';
        },
        get publicSpace() {
            return window.QUICKSITE_CONFIG?.publicSpace || 
                   document.body?.dataset?.publicSpace || 
                   '';
        }
    };

    // ============================================
    // Session token
    // ============================================
    // The panel's session is the PHP session: the browser holds its cookie and
    // the server holds everything else. The page is handed ONE value — the
    // per-session token (QUICKSITE_CONFIG.token) — which every request sends
    // back as `Authorization: Bearer`. It is not a credential on its own: the
    // cookie is. Sending it is how a request proves it came from something that
    // could READ this page, which is what stops another site driving the API
    // through the visitor's browser.
    //
    // There is nothing to refresh and nothing to expire client-side. The
    // session ends when the user signs out, when it goes idle, or when the
    // account's session generation is bumped ("log out everywhere") — all of
    // which surface as a 401 on the next call, which sends the user to login.
    // Nothing auth-related touches localStorage/sessionStorage.

    // In-memory session token, seeded from the server-rendered page config.
    let currentToken = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.token) || null;

    // Token-SOURCE seam: a deployment embedding QuickSite (e.g. a SaaS
    // platform) can plug its own async token provider here.
    let customTokenSource = null;

    /**
     * Get the current session token
     * @returns {string|null}
     */
    function getToken() {
        return currentToken ||
               (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.token) || null;
    }

    /**
     * Adopt a token: update this module + the page-embedded globals so the
     * hand-built fetch sites (PreviewConfig.authToken readers,
     * QUICKSITE_CONFIG.token readers) stay valid.
     * @param {string} token
     */
    function applyToken(token) {
        currentToken = token;
        if (window.QUICKSITE_CONFIG) window.QUICKSITE_CONFIG.token = token;
        if (window.PreviewConfig) window.PreviewConfig.authToken = token;
    }

    /**
     * Seam — plug a custom token source (async function resolving to a token
     * string). Used by deployments whose auth lives outside QuickSite.
     * @param {(() => Promise<string|null>)|null} fn
     */
    function setTokenSource(fn) {
        customTokenSource = (typeof fn === 'function') ? fn : null;
    }

    // Single-flight: a burst of concurrent 401s collapses into ONE call to the
    // custom source. With no source there is nothing to obtain — the built-in
    // session either works or is over.
    let refreshInFlight = null;

    /**
     * Obtain a token from the custom source, when one is plugged in.
     * @returns {Promise<string|null>}
     */
    function refreshAccessToken() {
        if (!customTokenSource) return Promise.resolve(null);
        if (refreshInFlight) return refreshInFlight;
        refreshInFlight = (async () => {
            try {
                const token = await customTokenSource();
                if (token) { applyToken(token); return token; }
                return null;
            } catch {
                return null;
            } finally {
                refreshInFlight = null;
            }
        })();
        return refreshInFlight;
    }

    /**
     * Clear the in-memory token (and any retired storage entries left by older
     * versions — one-time upgrade hygiene).
     */
    function clearToken() {
        currentToken = null;
        if (window.QUICKSITE_CONFIG) window.QUICKSITE_CONFIG.token = '';
        try {
            localStorage.removeItem(TOKEN_KEY);
            sessionStorage.removeItem(TOKEN_KEY);
            localStorage.removeItem(REMEMBER_KEY);
        } catch { /* storage may be unavailable; nothing to clean */ }
    }

    /**
     * Check if user is authenticated
     * @returns {boolean} True if token exists
     */
    function isAuthenticated() {
        return !!getToken();
    }

    // ============================================
    // Project scope transport (C8 8.W)
    // ============================================
    // C7 requires the '/management/p/<projectId>/<cmd>' marker for project-scoped
    // commands; global commands stay '/management/<cmd>'. This module decides which
    // is which and builds the path accordingly. The authoritative scope set and the
    // default project both come from the server (QUICKSITE_CONFIG, emitted from
    // categories.php + the user's selected_project) so client + server agree.

    // The project the client targets by default. Seeded from the server; a future
    // project picker can override it via setCurrentProject(). UX DEFAULT ONLY — the
    // server re-validates membership on every request, so this is never authz.
    let currentProject = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.currentProject) || null;

    function getCurrentProject() {
        return currentProject;
    }

    function setCurrentProject(projectId) {
        currentProject = (projectId === undefined || projectId === '') ? null : projectId;
    }

    // Defensive mirror of categories.php scope==='global' — used ONLY if the page
    // failed to emit QUICKSITE_CONFIG.globalCommands, so the panel can still
    // authenticate + list/create projects. The emitted set is authoritative.
    const FALLBACK_GLOBAL_COMMANDS = [
        'help', 'getMyPermissions', 'listRoles', 'listProjects',
        'createProject', 'checkForUpdates'
    ];

    function globalCommandSet() {
        const emitted = window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.globalCommands;
        return (Array.isArray(emitted) && emitted.length > 0) ? emitted : FALLBACK_GLOBAL_COMMANDS;
    }

    // A command is project-scoped unless it is in the global set (mirrors the
    // server's 'scope' ?? 'project' default: unmapped/unknown => project-scoped).
    function isProjectScoped(command) {
        return !globalCommandSet().includes(command);
    }

    // Build the management path for a command (WITHOUT the /management prefix):
    // project-scoped commands get the 'p/<project>/' marker, globals don't.
    // `projectOverride` (optional) targets a SPECIFIC project for this one call
    // instead of the panel default — used by the dashboard project-manager, where
    // the acted-on project is chosen in the modal, not the edited project. The
    // server still re-authorizes the marker project on every request. Returns null
    // when a project-scoped command has no project — the caller surfaces a clean
    // error rather than firing '/management/p//<cmd>' (dispatcher reads command 'p' → 404).
    function buildCommandPath(command, projectOverride) {
        if (!isProjectScoped(command)) return command;
        const project = projectOverride || currentProject;
        if (!project) return null;
        return 'p/' + encodeURIComponent(project) + '/' + command;
    }

    // Shared client-side error for a project-scoped call with no project selected.
    function noProjectError(command) {
        return clientError('client.project_required',
            'No project selected for project-scoped command: ' + command, 0);
    }

    // Every failure this module answers WITHOUT reaching the server used to carry
    // only `error`, while the server's own envelope (ApiResponse) carries `message`.
    // Callers overwhelmingly read `result.data.message` — 89 sites across the admin
    // panel against 18 that read `.error` — so a client-side refusal printed each
    // caller's generic fallback and the real reason never surfaced. That is how
    // "getSizeInfo failed: Unknown error" reached the dashboard console at zero
    // membership with no hint that the cause was a missing project (beta.10 C13
    // 13.6b). Both keys are emitted: `message` so the common path works, `error`
    // so the 18 existing readers keep working.
    function clientError(code, text, status) {
        return {
            ok: false,
            status: status,
            data: {
                success: false,
                code: code,
                message: text,
                error: text
            }
        };
    }

    // C8 8.4: the project-manager fence is LIFTED. Every project.data command
    // (backup/restore/clone/export/deleteBackup/listBackups) is now marker-contained
    // server-side (target bound to PROJECT_NAME, body mismatch → 400 project.mismatch)
    // and the dashboard targets each call with an explicit opts.project = the selected
    // project (marker == target). importProject became GLOBAL (create-from-archive,
    // caller = owner) so it needs no marker at all. deleteProject was lifted earlier
    // (8.0 round 5) on the same pattern.

    // ============================================
    // Core API Methods
    // ============================================

    /**
     * @typedef {Object} ApiResponse
     * @property {boolean} ok - True if HTTP response was successful (2xx)
     * @property {number} status - HTTP status code (0 on network error)
     * @property {Object} data - Parsed JSON response body
     */

    let redirectingToLogin = false;

    /**
     * Make an API request to the QuickSite management API
     * 
     * @param {string} command - API command/endpoint name (e.g., 'getStructure', 'listAssets')
     * @param {string} [method='GET'] - HTTP method (GET, POST, PUT, DELETE)
     * @param {Object|null} [data=null] - Request body data for POST/PUT
     * @param {Array} [urlParams=[]] - URL path parameters (e.g., ['page', 'home'])
     * @param {Object} [queryParams={}] - Query string parameters
     * @returns {Promise<{ok: boolean, data: Object, status: number}>} Response object
     * 
     * @example
     * // Simple GET
     * const result = await QuickSiteAPI.request('getStructure');
     * 
     * // GET with URL params: /management/getStructure/page/home/showIds
     * const result = await QuickSiteAPI.request('getStructure', 'GET', null, ['page', 'home', 'showIds']);
     * 
     * // POST with data
     * const result = await QuickSiteAPI.request('addRoute', 'POST', { route: '/about', page: 'about' });
     * 
     * // GET with query params: /management/getCommandHistory?limit=50&offset=0
     * const result = await QuickSiteAPI.request('getCommandHistory', 'GET', null, [], { limit: 50, offset: 0 });
     *
     * // Target a SPECIFIC project (dashboard project-manager): opts.project sets the marker
     * const result = await QuickSiteAPI.request('deleteProject', 'POST', { confirm: true }, [], {}, { project: 'prj_x' });
     *
     * @param {Object} [opts] - { project?: string } marker override for this call
     */
    async function request(command, method = 'GET', data = null, urlParams = [], queryParams = {}, _isRetry = false, opts = {}) {
        const token = getToken();
        if (!token) {
            return clientError('client.no_token', 'No authentication token', 401);
        }

        // Build URL — project-scoped commands carry the C7 '/p/<projectId>/' marker;
        // opts.project targets a specific project for this call (else the panel default).
        const commandPath = buildCommandPath(command, opts.project);
        if (commandPath === null) {
            return noProjectError(command);
        }
        let url = `${config.apiBase}/${commandPath}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }

        // Add query parameters
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
            // The session cookie is the credential; the header proves this call
            // came from a page of that session. 'same-origin' is fetch's default
            // and is stated here because the request does not work without it.
            credentials: 'same-origin',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            }
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);
            
            // Handle 204 No Content responses
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

            // A 401 with auth.invalid_credentials is a COMMAND-level credential
            // check (e.g. changePassword's current password) — the session
            // itself is alive, so surface it to the caller (C8). Any other 401
            // means the session is over (signed out, idle, or ended elsewhere)
            // → login. An embedding platform that plugged its own token source
            // gets one transparent retry through it first.
            if (response.status === 401) {
                if (!_isRetry && customTokenSource) {
                    const fresh = await refreshAccessToken();
                    if (fresh) {
                        return request(command, method, data, urlParams, queryParams, true, opts);
                    }
                }
                if (result && result.code === 'auth.invalid_credentials') {
                    return { ok: false, status: 401, data: result };
                }
                if (!redirectingToLogin) {
                    redirectingToLogin = true;
                    clearToken();
                    window.location.href = config.adminBase + '/login';
                }
                return { ok: false, status: 401, data: result };
            }

            return {
                ok: response.ok,
                status: response.status,
                data: result
            };
        } catch (error) {
            console.error('API request error:', error);
            return clientError('client.network_error', error.message || 'Network error', 0);
        }
    }

    /**
     * Upload a file via the API
     * 
     * @param {string} command - The upload command/endpoint
     * @param {FormData} formData - Form data containing the file and other fields
     * @param {Array} [urlParams=[]] - URL path parameters
     * @returns {Promise<{ok: boolean, data: Object, status: number}>} Response object
     * 
     * @example
     * const formData = new FormData();
     * formData.append('file', fileInput.files[0]);
     * const result = await QuickSiteAPI.upload('uploadAsset', formData);
     */
    async function upload(command, formData, urlParams = [], _isRetry = false) {
        const token = getToken();
        if (!token) {
            return clientError('client.no_token', 'No authentication token', 401);
        }

        // Project-scoped commands (uploadAsset) carry the C7 '/p/<projectId>/' marker (8.W)
        const commandPath = buildCommandPath(command);
        if (commandPath === null) {
            return noProjectError(command);
        }
        let url = `${config.apiBase}/${commandPath}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin', // the session cookie is the credential
                headers: {
                    'Authorization': `Bearer ${token}`
                    // Don't set Content-Type - browser will set it with boundary for FormData
                },
                body: formData
            });

            const result = await response.json();

            // Same 401 handling as request(), kept symmetric.
            if (response.status === 401) {
                if (!_isRetry && customTokenSource) {
                    const fresh = await refreshAccessToken();
                    if (fresh) {
                        return upload(command, formData, urlParams, true);
                    }
                }
                if (result && result.code === 'auth.invalid_credentials') {
                    return { ok: false, status: 401, data: result };
                }
                if (!redirectingToLogin) {
                    redirectingToLogin = true;
                    clearToken();
                    window.location.href = config.adminBase + '/login';
                }
                return { ok: false, status: 401, data: result };
            }

            // Dispatch event for successful uploads
            if (response.ok) {
                window.dispatchEvent(new CustomEvent('quicksite:command-executed', {
                    detail: { command, method: 'POST', success: true }
                }));
            }

            return {
                ok: response.ok,
                status: response.status,
                data: result
            };
        } catch (error) {
            console.error('API upload error:', error);
            return clientError('client.upload_failed', error.message || 'Upload failed', 0);
        }
    }

    /**
     * Fetch data from admin helper API endpoints
     * Used for dynamic form options (routes, languages, assets, etc.)
     * 
     * @param {string} action - Helper API action (e.g., 'routes', 'languages')
     * @param {Array} [params=[]] - URL path parameters
     * @returns {Promise<any>} The data from the API
     * @throws {Error} If request fails or returns error
     * 
     * @example
     * const routes = await QuickSiteAPI.fetchHelper('routes');
     * const langKeys = await QuickSiteAPI.fetchHelper('translation-keys', ['en']);
     */
    /**
     * Build the /admin/api path segment for a helper ACTION, carrying the edited
     * project as a URL marker the same way buildCommandPath() does for
     * /management. THE single place that knows the helper marker convention —
     * fetchHelper uses it, and the pages that hand-build a helper URL against
     * their own base (preview AI tools, translations) call it too.
     *
     * C8 8.X: the helper endpoint authorizes each arm's underlying command
     * against THIS project and binds its context, so a project-scoped arm reads
     * the project you are EDITING rather than the one the site happens to serve.
     * Emitted unconditionally when a project is known — arms that expose no
     * project data ignore it, which keeps the scope decision on the server
     * instead of duplicating the arm list on the client.
     *
     * @param {string} action - helper action, e.g. 'pages' or 'ai-spec'
     * @returns {string} e.g. 'p/my-project/pages' or 'pages'
     */
    function helperPath(action) {
        const marker = currentProject ? `p/${encodeURIComponent(currentProject)}/` : '';
        return `${marker}${action}`;
    }

    async function fetchHelper(action, params = []) {
        const token = getToken();
        if (!token) {
            throw new Error('No authentication token');
        }

        let url = `${config.adminBase}/api/${helperPath(action)}`;
        if (params.length > 0) {
            url += '/' + params.join('/');
        }

        const response = await fetch(url, {
            credentials: 'same-origin', // the session cookie is the credential
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });

        const result = await response.json();
        
        if (response.ok && result.success) {
            return result.data;
        }
        
        throw new Error(result.error || 'Failed to fetch data');
    }

    // ============================================
    // Public API
    // ============================================

    return {
        // Configuration
        config,

        // Session token (the credential is the session cookie; see the top of
        // this module). refreshAccessToken only does anything when an embedding
        // platform plugged its own source via setTokenSource.
        getToken,
        clearToken,
        isAuthenticated,
        refreshAccessToken,
        setTokenSource,

        // Project scope (C8 8.W)
        getCurrentProject,
        setCurrentProject,
        isProjectScoped,

        // API Methods
        request,
        upload,
        fetchHelper,
        helperPath
    };

})();
