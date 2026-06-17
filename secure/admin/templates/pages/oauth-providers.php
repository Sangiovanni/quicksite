<?php
/**
 * OAuth Providers admin page (beta.9 A1 Slice 8).
 *
 * Lean PHP shell — header + toolbar + container + script include.
 * All rendering of the provider cards, the add/edit modal, and the
 * CRUD orchestration lives in oauth-providers.js (calls
 * listOAuthProviders / addOAuthProvider / editOAuthProvider /
 * deleteOAuthProvider).
 *
 * Locked design 2026-06-15, DESIGN_DECISIONS.md "OAuth providers
 * admin page shape".
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/oauth-providers.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/oauth-providers.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon oauth-providers-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        OAuth providers
    </h1>
    <p class="admin-page-header__subtitle">Provider catalogue + per-project overrides. The oauth-button wizard reads from here.</p>
</div>

<div class="admin-toolbar oauth-providers-toolbar">
    <div class="admin-toolbar__left">
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-oauth-provider">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add provider
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh-oauth-providers">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Refresh
        </button>
    </div>
    <div class="admin-toolbar__right" id="oauth-providers-filter-bar"></div>
</div>

<div id="oauth-providers-list" class="oauth-providers-list" role="region" aria-label="OAuth providers list">
    <div class="oauth-providers-list__loading" style="padding: 24px; color: var(--admin-text-muted, #555);">Loading providers…</div>
</div>

<div id="oauth-provider-modal-root" data-oauth-provider-modal-root></div>
