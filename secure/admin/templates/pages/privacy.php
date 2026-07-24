<?php
/**
 * Privacy helper admin page (beta.9 — privacy / data-sharing surface).
 *
 * Lean PHP shell — header + hint host + content root + script include. All
 * rendering (collected-data, hosts, endpoint atoms, coverage) lives in
 * privacy.js, which calls getPrivacyStatus. Mirrors storage.php.
 *
 * The privacy helper is the data-SHARING half of the compliance story (what the
 * site sends to APIs / third parties); the storage registry is the browser-
 * storage half. See NOTES/planning/PRIVACY_POLICY_GENERATOR.md.
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/privacy.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/privacy.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon privacy-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>
        </svg>
        Privacy helper
    </h1>
    <p class="admin-page-header__subtitle">What this site sends out — built from your API registry (third-party data sharing) + sign-in providers. Map each field the site sends to a "data collected" entry to cover your privacy policy.</p>
</div>

<p id="privacy-desclang-hint" class="admin-hint privacy-desclang-hint" hidden></p>

<div id="privacy-root" class="privacy-root" role="region" aria-label="Privacy helper">
    <div class="privacy-root__loading" style="padding: 24px; color: var(--admin-text-muted, #555);">Loading privacy status…</div>
</div>

<div id="privacy-modal-root" data-privacy-modal-root></div>
