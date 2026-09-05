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
        'help', 'listProjects', 'createProject'
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

    // Read a response body WITHOUT assuming the server produced JSON.
    //
    // Not every answer to a QuickSite request comes from QuickSite. Anything in
    // front of PHP can reply on its own — an nginx `client_max_body_size`
    // refusal is an HTML 413 page emitted before PHP runs at all, and a proxy,
    // a WAF or a gateway timeout answers HTML the same way. `response.json()`
    // throws on all of them, and the caller's catch then reports a JSON parse
    // error where the user needed "your file is too large".
    //
    // The status code survives that, and it is the part that carries meaning, so
    // this returns an envelope shaped like ApiResponse's with a sentence derived
    // from the status. The upstream body is deliberately NOT used as the
    // message: it is an unbounded HTML document, and panel code interpolates
    // `data.message` into the DOM.
    //
    // 204 has no body by contract and is not an error — handled by the caller.
    //
    // A SUCCESSFUL response is passed through exactly as before (parsed JSON, or
    // the raw text under `message`, or null for an empty body) — the synthesis
    // below applies only when the status already says the request failed, so no
    // working call site changes shape.
    async function readResponseBody(response) {
        const text = await response.text();
        if (text) {
            try {
                return JSON.parse(text);
            } catch {
                if (response.ok) {
                    return { message: text };
                }
            }
        } else if (response.ok) {
            return null;
        }

        const sentence = nonJsonMessage(response.status);
        return {
            success: false,
            code: 'client.non_json_response',
            message: sentence,
            error: sentence,
            http_status: response.status
        };
    }

    // What to tell a user when the answer did not come from QuickSite. 413 is
    // the one worth naming precisely: on nginx it is the default 1 MB request
    // body limit, which is SMALLER than the upload size PHP accepts, so it is
    // the failure a deployer actually hits.
    //
    // ⚠ NAMES THE DIRECTIVE, NOT A PATH. This used to point at
    // `secure/deploy/nginx-vhosts.conf.example`, which is wrong on any install
    // whose secure folder was renamed — the same defect the engine avoids by
    // interpolating SECURE_FOLDER_NAME. That constant cannot reach this file
    // without publishing the folder name into every admin page's HTML via
    // QUICKSITE_CONFIG, which is a disclosure trade for one sentence. It is not
    // needed: the person who sees this message is whoever tried the upload, not
    // necessarily the operator, and `client_max_body_size` is the actionable
    // fact. Anyone with server access finds the shipped example by name.
    function nonJsonMessage(status) {
        if (status === 413) {
            return 'The file is too large for this server to accept. It was refused by the web '
                 + 'server before QuickSite saw it — on nginx this is client_max_body_size, '
                 + 'which defaults to 1 MB. Raising it is a server change: see the shipped '
                 + 'nginx-vhosts.conf.example in the deploy folder.';
        }
        if (status === 502 || status === 503 || status === 504) {
            return 'The server is not responding (HTTP ' + status + '). It may be restarting, '
                 + 'or the request may have taken too long.';
        }
        if (status === 0) {
            return 'The request did not complete.';
        }
        return 'The server answered HTTP ' + status + ' with a response QuickSite could not read. '
             + 'It came from the web server or a proxy rather than from QuickSite itself.';
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
                result = await readResponseBody(response);
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
            // check (e.g. the account password change's current password) — the session
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
     * Download a command's response as a FILE the browser saves.
     *
     * A plain <a href> cannot do this. The management surface requires the
     * session cookie AND an Authorization header, and an anchor sends no
     * headers — which is why downloadExport has never been reachable from a
     * link, and why downloadBuild used to answer a URL pointing at a statically
     * served copy instead of streaming (that static path was also how a public
     * project's build ended up anonymously downloadable).
     *
     * So: fetch with the header, take the body as a blob, and hand it to the
     * user through an object URL and a synthetic click. Dependency-free, and one
     * implementation for every command that answers bytes.
     *
     * The filename comes from Content-Disposition when the server sent one,
     * because the server is what knows the real name.
     *
     * ERRORS STILL ARRIVE AS JSON. A refusal (404, 409, 401) answers the usual
     * envelope, so a non-OK response is read as JSON and returned in the same
     * {ok, status, data} shape request() uses — the caller branches once, not
     * twice.
     *
     * @param {string} command - Command that streams a file (downloadBuild, downloadExport)
     * @param {Array}  [urlParams=[]] - URL path segments
     * @param {Object} [queryParams={}] - Query string parameters
     * @param {Object} [opts] - { project?: string, filename?: string }
     * @returns {Promise<{ok: boolean, status: number, data: Object|null, filename?: string}>}
     *
     * @example
     * const res = await QuickSiteAdmin.downloadFile('downloadBuild');
     * if (!res.ok) showToast(res.data?.message || 'Download failed', 'error');
     */
    async function downloadFile(command, urlParams = [], queryParams = {}, opts = {}) {
        const token = getToken();
        if (!token) {
            return clientError('client.no_token', 'No authentication token', 401);
        }

        const commandPath = buildCommandPath(command, opts.project);
        if (commandPath === null) {
            return noProjectError(command);
        }
        let url = `${config.apiBase}/${commandPath}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }
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

        let response;
        try {
            response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Authorization': `Bearer ${token}` }
            });
        } catch (error) {
            console.error('Download error:', error);
            return clientError('client.network_error', error.message || 'Network error', 0);
        }

        // A refusal is a normal envelope — read it as one and let the caller
        // show the message rather than saving an error page as a .zip.
        if (!response.ok) {
            const result = await readResponseBody(response);
            if (response.status === 401 && !redirectingToLogin) {
                redirectingToLogin = true;
                clearToken();
                window.location.href = config.adminBase + '/login';
            }
            return { ok: false, status: response.status, data: result };
        }

        const blob = await response.blob();

        // Prefer the server's own name.
        let filename = opts.filename || command;
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"\n;]+)"?/i);
        if (match && match[1]) {
            filename = match[1].trim();
        }

        const objectUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        // Revoked on the next tick: revoking synchronously can cancel the
        // download the click just started.
        setTimeout(() => URL.revokeObjectURL(objectUrl), 0);

        return { ok: true, status: response.status, data: null, filename };
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

            // NOT response.json(). This is the one call in the panel most likely
            // to be answered by something that is not QuickSite: an oversized
            // upload is refused by the web server in front of PHP, with an HTML
            // error page. Parsing that as JSON threw, and the catch below then
            // reported the parse error — a sentence about tokens where the user
            // needed to be told the file was too big.
            const result = await readResponseBody(response);

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

        // Same reasoning as upload(): a helper request can be answered by the
        // web server rather than by QuickSite, and this function's contract is
        // to throw a message a caller can show. A JSON parse error is not one.
        const result = await readResponseBody(response);

        if (response.ok && result && result.success) {
            return result.data;
        }

        throw new Error((result && (result.error || result.message)) || 'Failed to fetch data');
    }

    /**
     * Change which project THIS user's panel edits (their `selected_project`).
     *
     * NOT a command. The command surface is a CLI for developing a project;
     * which project a person has open is a fact about the panel, so it is
     * served by /admin/state — its own endpoint, because /admin/api is
     * read-only. Membership is still enforced server-side: selecting a project
     * you are not a member of is refused, and the value is never an
     * authorization input (every request re-authorizes against the URL project).
     *
     * Resolves with the same {ok, status, data} shape request() uses, so a
     * caller branches on `res.ok` and reads `res.data.message` exactly as it
     * did when this was a management command.
     *
     * @param {string} projectId
     * @returns {Promise<{ok: boolean, status: number, data: Object|null}>}
     */
    async function setSelectedProject(projectId) {
        const token = getToken();
        if (!token) {
            return clientError('client.no_token', 'No authentication token', 401);
        }

        try {
            const response = await fetch(`${config.adminBase}/state/selected-project`, {
                method: 'POST',
                credentials: 'same-origin',   // the session cookie is the credential
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ project: projectId })
            });
            return {
                ok: response.ok,
                status: response.status,
                data: await readResponseBody(response)
            };
        } catch (error) {
            console.error('Panel state request error:', error);
            return clientError('client.network_error', error.message || 'Network error', 0);
        }
    }

    /**
     * Call the panel's ACCOUNT endpoint (/admin/self).
     *
     * NOT commands. The command surface is a CLI for developing a project;
     * managing the login you sign in with, getting into or out of a project, and
     * looking a person up in order to invite them are operations on an ACCOUNT,
     * so they are served here instead (beta.11 S6).
     *
     * Resolves with the same {ok, status, data} shape request() uses, so a
     * caller branches on `res.ok` and reads `res.data.data` / `res.data.message`
     * exactly as it did when these were management commands.
     *
     * Reads are GET and writes are POST — the server enforces the method per
     * route and answers 405 on a mismatch, so the verb passed here is not a
     * suggestion. `route` may carry a literal query string ('space-usage?refresh=1').
     *
     * @param {string} route - e.g. 'permissions', 'accept-invitation'
     * @param {string} method - 'GET' or 'POST'
     * @param {Object} [body] - JSON body, POST routes only
     * @returns {Promise<{ok: boolean, status: number, data: Object|null}>}
     */
    async function accountRequest(route, method, body) {
        const token = getToken();
        if (!token) {
            return clientError('client.no_token', 'No authentication token', 401);
        }

        const options = {
            method: method,
            credentials: 'same-origin',   // the session cookie is the credential
            headers: { 'Authorization': `Bearer ${token}` }
        };
        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body || {});
        }

        try {
            // '/self', NOT '/account': /admin/account is the My Account PAGE, and a
            // directory of that name under public/admin/ shadows it (Apache resolves
            // a real directory before the panel's FallbackResource).
            const response = await fetch(`${config.adminBase}/self/${route}`, options);
            const body = await readResponseBody(response);

            // A 2xx is NOT enough to call this a success. Every route here answers
            // an ApiResponse envelope ({status, code, …}); anything else on a 2xx
            // did not come from this endpoint, and the most likely sender is the
            // panel's own FallbackResource handing back a PAGE. That is not
            // hypothetical — it is what happened when this function briefly pointed
            // at the wrong directory: /admin/<page>/<route> renders the page with
            // HTTP 200 and an HTML body, readResponseBody passes a 200 through
            // untouched, and the caller read "deleted" from a request that deleted
            // nothing. On a destructive route a false success is the worst possible
            // failure mode, so the shape is checked rather than assumed.
            const fromEndpoint = body && typeof body === 'object'
                && typeof body.status === 'number' && typeof body.code === 'string';
            if (response.ok && !fromEndpoint) {
                return clientError(
                    'client.unexpected_response',
                    'The server answered this request with something other than an account '
                        + 'response. Nothing was changed. Reload the page and try again.',
                    response.status
                );
            }

            return {
                ok: response.ok,
                status: response.status,
                data: body
            };
        } catch (error) {
            console.error('Account request error:', error);
            return clientError('client.network_error', error.message || 'Network error', 0);
        }
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
        // Commands that answer BYTES rather than a JSON envelope
        // (downloadBuild, downloadExport). See the note on downloadFile: a
        // plain link cannot carry the Authorization header this surface needs.
        downloadFile,
        fetchHelper,
        helperPath,

        // Panel state (/admin/state) — not commands. See setSelectedProject.
        setSelectedProject,

        // Account + membership self-service and directory lookups
        // (/admin/self) — not commands either. See accountRequest.
        accountRequest,

        // For the few places that must hand-roll a fetch (importProject is
        // GLOBAL, so upload()'s marker path does not fit it). Exported so they
        // do not hand-roll the body reading too — that is where the assumption
        // "an HTTP answer is JSON" keeps coming back.
        readResponseBody
    };

})();
