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
    // Auth / session — nothing auth-related touches browser storage. The
    // session lives in an HttpOnly cookie the page cannot read, and the
    // per-session token is in-memory + page-embedded. The old
    // 'quicksite_admin_token' / 'quicksite_admin_remember' keys survive only as
    // literals inside api.js clearToken() for one-time legacy cleanup.
    adminPrefs:          'quicksite_admin_prefs',
    pendingMessage:      'quicksite_pending_message',

    // External API registry
    apiAuthTokens:       'qs_api_auth_tokens',

    // AI provider settings
    aiKeysV2:            'quicksite_ai_keys_v2',          // deprecated: read-only for v2->v3 migration
    aiConnectionsV3:     'quicksite_ai_connections_v3',   // current AI connections store
    aiDefaultProvider:   'quicksite_ai_default_provider',
    aiPersist:           'quicksite_ai_persist',
    aiAutoPreview:       'quicksite_ai_auto_preview',
    aiAutoExecute:       'quicksite_ai_auto_execute',

    // Visual editor — Source tab draft (A3 slice 4). Persists unsaved
    // edits so the user can recover if they navigate away or refresh
    // before saving. Cleared on successful save or explicit discard.
    styleSourceDraft:    'qs_style_source_draft',

    // Operator update notice. Written only in a browser signed in as an account
    // named in operator.php — nobody else runs update-notice.js at all.
    //   updateCheckAt      epoch ms of the last update check, so a panel
    //                      navigation does not spend a GitHub API call each time
    //                      (unauthenticated GitHub allows 60/hour per address).
    //   updateNoticeHidden the version string the operator dismissed. Stored as
    //                      the VERSION, not a boolean, so the next release shows
    //                      the notice again instead of staying dismissed forever.
    //   updateLastSeen     the newest version the last check reported, so the
    //                      banner can be re-shown inside the throttle window
    //                      without spending another GitHub call.
    updateCheckAt:       'qs_update_check_at',
    updateNoticeHidden:  'qs_update_notice_hidden',
    updateLastSeen:      'qs_update_last_seen',
});
