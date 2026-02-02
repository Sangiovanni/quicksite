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
    // Token Management
    // ============================================

    /**
     * Get stored authentication token
     * Checks localStorage first (remembered), then sessionStorage
     * @returns {string|null} The stored token or null
     */
    function getToken() {
        return localStorage.getItem(TOKEN_KEY) || 
               sessionStorage.getItem(TOKEN_KEY);
    }

    /**
     * Store authentication token
     * @param {string} token - The token to store
     * @param {boolean} [remember=false] - Whether to persist across sessions
     */
    function setToken(token, remember = false) {
        if (remember) {
            localStorage.setItem(TOKEN_KEY, token);
            localStorage.setItem(REMEMBER_KEY, 'true');
        } else {
            sessionStorage.setItem(TOKEN_KEY, token);
            localStorage.removeItem(TOKEN_KEY);
            localStorage.removeItem(REMEMBER_KEY);
        }
    }

    /**
     * Clear stored authentication token
     */
    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
        sessionStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(REMEMBER_KEY);
    }

    /**
     * Check if user is authenticated
     * @returns {boolean} True if token exists
     */
    function isAuthenticated() {
        return !!getToken();
    }

    // ============================================
    // Core API Methods
    // ============================================

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
     */
    async function request(command, method = 'GET', data = null, urlParams = [], queryParams = {}) {
        const token = getToken();
        if (!token) {
            return {
                ok: false,
                data: { success: false, error: 'No authentication token' },
                status: 401
            };
        }

        // Build URL
        let url = `${config.apiBase}/${command}`;
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

            return {
                ok: response.ok,
                status: response.status,
                data: result
            };
        } catch (error) {
            console.error('API request error:', error);
            return {
                ok: false,
                data: { success: false, error: error.message || 'Network error' },
                status: 0
            };
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
    async function upload(command, formData, urlParams = []) {
        const token = getToken();
        if (!token) {
            return {
                ok: false,
                data: { success: false, error: 'No authentication token' },
                status: 401
            };
        }

        let url = `${config.apiBase}/${command}`;
        if (urlParams.length > 0) {
            url += '/' + urlParams.join('/');
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`
                    // Don't set Content-Type - browser will set it with boundary for FormData
                },
                body: formData
            });

            const result = await response.json();
            
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
            return {
                ok: false,
                data: { success: false, error: error.message || 'Upload failed' },
                status: 0
            };
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
    async function fetchHelper(action, params = []) {
        const token = getToken();
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
    }

    // ============================================
    // Public API
    // ============================================

    return {
        // Configuration
        config,
        
        // Token Management
        getToken,
        setToken,
        clearToken,
        isAuthenticated,
        
        // API Methods
        request,
        upload,
        fetchHelper
    };

})();
