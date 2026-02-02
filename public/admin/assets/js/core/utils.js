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
                    <p class="admin-modal__message">${escapeHtml(message)}</p>
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
        copyToClipboard
    };

})();
