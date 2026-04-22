/**
 * QuickSite Admin - Core Utilities Module
 * Provides shared utility functions: toasts, modals, escaping, formatting, preferences.
 * 
 * @module core/utils
 */

window.QuickSiteUtils = (function() {
    'use strict';

    // ============================================
    // Preferences Management
    // ============================================

    const PREFS_KEY = 'quicksite_admin_prefs';
    let preferences = {};

    /**
     * Load preferences from localStorage
     */
    function loadPreferences() {
        try {
            preferences = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
        } catch {
            preferences = {};
        }
    }

    /**
     * Get a preference value
     * @param {string} key - Preference key
     * @param {any} defaultValue - Default value if not set
     * @returns {any} The preference value
     */
    function getPref(key, defaultValue = null) {
        if (Object.keys(preferences).length === 0) {
            loadPreferences();
        }
        return preferences[key] !== undefined ? preferences[key] : defaultValue;
    }

    /**
     * Set a preference value
     * @param {string} key - Preference key
     * @param {any} value - Value to store
     */
    function setPref(key, value) {
        preferences[key] = value;
        try {
            localStorage.setItem(PREFS_KEY, JSON.stringify(preferences));
        } catch (e) {
            console.warn('Failed to save preference:', e);
        }
    }

    // ============================================
    // Pending Messages (for cross-page notifications)
    // ============================================

    const PENDING_MSG_KEY = 'quicksite_pending_message';

    /**
     * Store a message to show after redirect
     * @param {string} message - Message text
     * @param {string} type - Toast type (success, error, warning, info)
     * @param {number} duration - Display duration in ms
     */
    function setPendingMessage(message, type = 'info', duration = 4000) {
        const pendingMessage = { message, type, duration };
        sessionStorage.setItem(PENDING_MSG_KEY, JSON.stringify(pendingMessage));
    }

    /**
     * Check for and display any pending message
     * Should be called on page load
     */
    function checkPendingMessage() {
        const stored = sessionStorage.getItem(PENDING_MSG_KEY);
        if (stored) {
            sessionStorage.removeItem(PENDING_MSG_KEY);
            try {
                const { message, type, duration } = JSON.parse(stored);
                // Small delay to ensure toast container exists
                setTimeout(() => showToast(message, type, duration), 100);
            } catch (e) {
                console.error('Failed to parse pending message:', e);
            }
        }
    }

    // ============================================
    // Text Utilities
    // ============================================

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped HTML string
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Format JSON for display
     * @param {any} data - Data to format
     * @returns {string} Formatted JSON string
     */
    function formatJson(data) {
        return JSON.stringify(data, null, 2);
    }

    /**
     * Truncate text with ellipsis
     * @param {string} text - Text to truncate
     * @param {number} maxLength - Maximum length
     * @returns {string} Truncated text
     */
    function truncate(text, maxLength = 50) {
        if (!text || text.length <= maxLength) return text;
        return text.substring(0, maxLength - 3) + '...';
    }

    // ============================================
    // Toast Notifications
    // ============================================

    const TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };

    /**
     * Show a toast notification
     * 
     * @param {string} message - Message to display
     * @param {string} [type='info'] - Toast type: success, error, warning, info
     * @param {number|null} [duration=null] - Duration in ms (null = use preference)
     * @returns {HTMLElement} The toast element
     * 
     * @example
     * QuickSiteUtils.showToast('File saved!', 'success');
     * QuickSiteUtils.showToast('Something went wrong', 'error', 8000);
     */
    function showToast(message, type = 'info', duration = null) {
        // Use preference duration if not explicitly provided
        if (duration === null) {
            duration = parseInt(getPref('toastDuration', 4000));
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
        
        toast.innerHTML = `
            <span class="admin-toast__icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</span>
            <span class="admin-toast__message">${escapeHtml(message)}</span>
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
    }

    // ============================================
    // Confirmation Dialogs
    // ============================================

    /**
     * Show a confirmation dialog
     * 
     * @param {string} message - Message to display
     * @param {Object} [options={}] - Dialog options
     * @param {string} [options.title='Confirm Action'] - Dialog title
     * @param {string} [options.type='warning'] - Icon type: warning, danger, info
     * @param {string} [options.confirmText='Confirm'] - Confirm button text
     * @param {string} [options.cancelText='Cancel'] - Cancel button text
     * @param {string} [options.confirmClass='primary'] - Confirm button class
     * @returns {Promise<boolean>} True if confirmed, false if cancelled
     * 
     * @example
     * const confirmed = await QuickSiteUtils.confirm('Delete this item?', {
     *     title: 'Confirm Delete',
     *     type: 'danger',
     *     confirmText: 'Delete',
     *     confirmClass: 'danger'
     * });
     */
    async function confirm(message, options = {}) {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'admin-modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'admin-modal-dialog admin-modal-dialog--confirm';
            modal.innerHTML = `
                <div class="admin-modal-dialog__content">
                    <div class="admin-modal-dialog__icon admin-modal-dialog__icon--${options.type || 'warning'}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <h3 class="admin-modal-dialog__title">${options.title || 'Confirm Action'}</h3>
                    <p class="admin-modal-dialog__message">${escapeHtml(message)}</p>
                    <div class="admin-modal-dialog__actions">
                        <button class="admin-btn admin-btn--secondary admin-modal-dialog__cancel">
                            ${options.cancelText || 'Cancel'}
                        </button>
                        <button class="admin-btn admin-btn--${options.confirmClass || 'primary'} admin-modal-dialog__confirm">
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

            modal.querySelector('.admin-modal-dialog__cancel').addEventListener('click', () => cleanup(false));
            modal.querySelector('.admin-modal-dialog__confirm').addEventListener('click', () => cleanup(true));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) cleanup(false);
            });

            // Close on Escape
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    cleanup(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * Shorthand for delete confirmation
     * 
     * @param {string} itemName - Name of item being deleted
     * @returns {Promise<boolean>} True if confirmed
     */
    async function confirmDelete(itemName) {
        return confirm(`Are you sure you want to delete "${itemName}"? This action cannot be undone.`, {
            title: 'Confirm Deletion',
            type: 'danger',
            confirmText: 'Delete',
            confirmClass: 'danger'
        });
    }

    // ============================================
    // Copy to Clipboard
    // ============================================

    /**
     * Copy text to clipboard with feedback
     * Works in both HTTPS (modern API) and HTTP (legacy fallback)
     * 
     * @param {string} text - Text to copy
     * @param {string} [successMessage='Copied!'] - Success toast message
     * @returns {Promise<boolean>} True if successful
     */
    async function copyToClipboard(text, successMessage = 'Copied!') {
        let copied = false;
        
        // Try modern clipboard API first (requires HTTPS or localhost)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch (e) {
                console.warn('Clipboard API failed:', e);
            }
        }
        
        // Fallback for HTTP or if clipboard API failed - use temporary textarea
        if (!copied) {
            const tempTextarea = document.createElement('textarea');
            tempTextarea.value = text;
            tempTextarea.style.position = 'fixed';
            tempTextarea.style.left = '-9999px';
            tempTextarea.style.top = '0';
            document.body.appendChild(tempTextarea);
            tempTextarea.focus();
            tempTextarea.select();
            try {
                copied = document.execCommand('copy');
            } catch (e) {
                console.error('execCommand copy failed:', e);
            }
            document.body.removeChild(tempTextarea);
        }
        
        if (copied) {
            showToast(successMessage, 'success', 2000);
            return true;
        } else {
            showToast('Failed to copy to clipboard', 'error');
            return false;
        }
    }

    // ============================================
    // SVG Icon Helpers
    // ============================================

    /**
     * Build an inline SVG string
     * @param {string} paths - Raw SVG path/shape markup to embed inside the `<svg>`
     * @param {number} [size=14] - Width and height in pixels (0 = no size attributes)
     * @param {string} [cls] - Optional CSS class for the `<svg>` element
     * @param {string} [attrs] - Optional extra attribute string appended to the `<svg>` tag
     * @returns {string} HTML string containing the SVG element
     */
    function svgIcon(paths, size = 14, cls, attrs) {
        const sizeAttr = size ? ` width="${size}" height="${size}"` : '';
        return `<svg${cls ? ` class="${cls}"` : ''}${attrs ? ' ' + attrs : ''} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"${sizeAttr}>${paths}</svg>`;
    }

    const ICON_PATHS = {
        trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        check: '<polyline points="20 6 9 17 4 12"/>',
        refresh: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        chevronRight: '<polyline points="9 18 15 12 9 6"/>',
        chevronLeft: '<polyline points="15 18 9 12 15 6"/>',
        chevronDown: '<polyline points="6 9 12 15 18 9"/>',
        chevronUp: '<polyline points="18 15 12 9 6 15"/>',
        close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        play: '<polygon points="5 3 19 12 5 21 5 3"/>',
        info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        alertCircle: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        spinner: '<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="32" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/></circle>',
        list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        eyeOff: '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        key: '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        file: '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
        arrowRight: '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        ban: '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
        folder: '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        externalLink: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
        copy: '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'
    };

    function iconTrash(size) { return svgIcon(ICON_PATHS.trash, size); }
    function iconEdit(size) { return svgIcon(ICON_PATHS.edit, size); }
    function iconCheck(size) { return svgIcon(ICON_PATHS.check, size); }
    function iconRefresh(size) { return svgIcon(ICON_PATHS.refresh, size); }
    function iconChevronRight(size) { return svgIcon(ICON_PATHS.chevronRight, size); }
    function iconChevronLeft(size) { return svgIcon(ICON_PATHS.chevronLeft, size); }
    function iconChevronDown(size) { return svgIcon(ICON_PATHS.chevronDown, size); }
    function iconChevronUp(size) { return svgIcon(ICON_PATHS.chevronUp, size); }
    function iconClose(size) { return svgIcon(ICON_PATHS.close, size); }
    function iconPlus(size) { return svgIcon(ICON_PATHS.plus, size); }
    function iconPlay(size) { return svgIcon(ICON_PATHS.play, size); }
    function iconInfo(size) { return svgIcon(ICON_PATHS.info, size); }
    function iconAlertCircle(size) { return svgIcon(ICON_PATHS.alertCircle, size); }
    function iconWarning(size) { return svgIcon(ICON_PATHS.warning, size); }
    function iconSpinner(size) { return svgIcon(ICON_PATHS.spinner, size); }
    function iconList(size) { return svgIcon(ICON_PATHS.list, size); }
    function iconClock(size) { return svgIcon(ICON_PATHS.clock, size); }
    function iconEye(size) { return svgIcon(ICON_PATHS.eye, size); }
    function iconEyeOff(size) { return svgIcon(ICON_PATHS.eyeOff, size); }
    function iconKey(size) { return svgIcon(ICON_PATHS.key, size); }
    function iconUpload(size) { return svgIcon(ICON_PATHS.upload, size); }
    function iconFile(size) { return svgIcon(ICON_PATHS.file, size); }
    function iconArrowRight(size) { return svgIcon(ICON_PATHS.arrowRight, size); }
    function iconBan(size) { return svgIcon(ICON_PATHS.ban, size); }
    function iconFolder(size) { return svgIcon(ICON_PATHS.folder, size); }
    function iconExternalLink(size) { return svgIcon(ICON_PATHS.externalLink, size); }
    function iconStar(size) { return svgIcon(ICON_PATHS.star, size); }
    function iconGear(size) { return svgIcon(ICON_PATHS.gear, size); }
    function iconSave(size) { return svgIcon(ICON_PATHS.save, size); }
    function iconCopy(size) { return svgIcon(ICON_PATHS.copy, size); }
    function iconHome(size) { return svgIcon(ICON_PATHS.home, size); }

    // ============================================
    // HTML Fragment Helpers
    // ============================================

    /**
     * Render an animated spinner element
     * @param {number} [size] - Size in pixels (omit to use CSS default)
     * @returns {string} HTML string for a spinner `<span>`
     */
    function htmlSpinner(size) {
        return `<span class="admin-spinner"${size ? ` style="width:${size}px;height:${size}px;"` : ''}></span>`;
    }

    /**
     * Render a loading indicator with optional label text
     * @param {string} text - Label shown beside the spinner
     * @returns {string} HTML string for a loading block
     */
    function htmlLoading(text) {
        return `<div class="admin-loading">${htmlSpinner()} ${text}</div>`;
    }

    /**
     * Render an HTTP method badge (GET, POST, PUT, DELETE, …)
     * @param {string} method - HTTP method string
     * @returns {string} HTML string for a styled method badge
     */
    function htmlMethodBadge(method) {
        return `<span class="badge badge--${escapeHtml(method.toLowerCase())}">${escapeHtml(method)}</span>`;
    }

    /**
     * Render a generic status badge
     * @param {string} text - Badge label
     * @param {string} [type='default'] - Visual variant class suffix (e.g. 'success', 'danger')
     * @returns {string} HTML string for the badge
     */
    function htmlStatusBadge(text, type) {
        return `<span class="admin-badge admin-badge--${type || 'default'}">${escapeHtml(text)}</span>`;
    }

    /**
     * Render an inline alert message box
     * @param {string} message - Alert text
     * @param {string} [type='error'] - Visual variant class suffix (e.g. 'error', 'warning', 'info')
     * @returns {string} HTML string for the alert
     */
    function htmlAlert(message, type) {
        return `<div class="admin-alert admin-alert--${type || 'error'}">${escapeHtml(message)}</div>`;
    }

    /**
     * Render an empty-state placeholder block
     * @param {string|null} icon - SVG icon HTML or null to omit
     * @param {string|null} title - Heading text or null to omit
     * @param {string|null} desc - Description text or null to omit
     * @param {boolean} [compact=false] - Use compact layout variant
     * @returns {string} HTML string for the empty-state element
     */
    function htmlEmptyState(icon, title, desc, compact) {
        const cls = compact ? 'admin-empty admin-empty--compact' : 'admin-empty';
        let html = `<div class="${cls}">`;
        if (icon) html += `<div class="admin-empty__icon">${icon}</div>`;
        if (title) html += `<h3 class="admin-empty__title">${escapeHtml(title)}</h3>`;
        if (desc) html += `<p class="admin-empty__desc">${escapeHtml(desc)}</p>`;
        html += '</div>';
        return html;
    }

    // ============================================
    // Initialize on Load
    // ============================================

    // Load preferences on module init
    loadPreferences();

    // ============================================
    // Public API
    // ============================================

    return {
        // Preferences
        getPref,
        setPref,
        loadPreferences,
        
        // Pending Messages
        setPendingMessage,
        checkPendingMessage,
        
        // Text Utilities
        escapeHtml,
        formatJson,
        truncate,
        
        // Toast Notifications
        showToast,
        
        // Confirmation Dialogs
        confirm,
        confirmDelete,
        
        // Clipboard
        copyToClipboard,
        
        // SVG Icons
        svgIcon,
        ICON_PATHS,
        iconTrash,
        iconEdit,
        iconCheck,
        iconRefresh,
        iconChevronRight,
        iconChevronLeft,
        iconChevronDown,
        iconChevronUp,
        iconClose,
        iconPlus,
        iconPlay,
        iconInfo,
        iconAlertCircle,
        iconWarning,
        iconSpinner,
        iconList,
        iconClock,
        iconEye,
        iconKey,
        iconUpload,
        iconFile,
        iconArrowRight,
        iconBan,
        iconFolder,
        iconExternalLink,
        iconStar,
        iconGear,
        iconSave,
        iconCopy,
        iconHome,
        iconEyeOff,

        // HTML Fragment Helpers
        htmlSpinner,
        htmlLoading,
        htmlMethodBadge,
        htmlStatusBadge,
        htmlAlert,
        htmlEmptyState
    };

})();
