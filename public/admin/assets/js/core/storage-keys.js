/**
 * QuickSite Admin — Centralized Storage Key Registry
 *
 * Single source of truth for all localStorage / sessionStorage key strings
 * used across the admin panel. Import before any page module that reads or
 * writes these keys (load this before api.js in layout.php).
 *
 * Usage:
 *   localStorage.getItem(QuickSiteStorageKeys.aiKeysV2)
 *
 * @version 1.0.0
 */
window.QuickSiteStorageKeys = Object.freeze({
    // Auth / session
    adminToken:          'quicksite_admin_token',
    adminRemember:       'quicksite_admin_remember',
    adminPrefs:          'quicksite_admin_prefs',
    pendingMessage:      'quicksite_pending_message',

    // Batch queue
    batchQueue:          'admin_batch_queue',

    // External API registry
    apiAuthTokens:       'qs_api_auth_tokens',

    // AI provider settings
    aiKeysV2:            'quicksite_ai_keys_v2',
    aiDefaultProvider:   'quicksite_ai_default_provider',
    aiPersist:           'quicksite_ai_persist',
    aiAutoPreview:       'quicksite_ai_auto_preview',
    aiAutoExecute:       'quicksite_ai_auto_execute',
});
