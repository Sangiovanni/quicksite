<?php
/**
 * Storage registry admin page (beta.9 — storage registry, slice 2).
 *
 * Lean PHP shell — header + toolbar + list + modal root + script include.
 * All rendering of the item cards, the add/edit modal, and CRUD
 * orchestration lives in storage.js (calls listStorageItems /
 * addStorageItem / editStorageItem / deleteStorageItem).
 *
 * The registry is the GDPR / cookie-consent data layer — see
 * NOTES/planning/BETA9_STORAGE_REGISTRY.md.
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/storage.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/storage.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon storage-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
        </svg>
        Storage registry
    </h1>
    <p class="admin-page-header__subtitle">Every browser-storage key the site uses (localStorage / sessionStorage / cookie). The GDPR / cookie-consent data layer and the storageKey picker source.</p>
</div>

<div class="admin-toolbar storage-toolbar">
    <div class="admin-toolbar__left">
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-storage-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add storage key
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-scan-storage">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            Scan / reconcile
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh-storage">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Refresh
        </button>
    </div>
    <div class="admin-toolbar__right" id="storage-filter-bar"></div>
</div>

<div id="storage-scan-panel" class="storage-scan" role="region" aria-label="Storage scan results" hidden></div>

<div id="storage-list" class="storage-list" role="region" aria-label="Storage registry list">
    <div class="storage-list__loading" style="padding: 24px; color: var(--admin-text-muted, #555);">Loading storage registry…</div>
</div>

<div id="storage-modal-root" data-storage-modal-root></div>
