<?php
/**
 * Privacy helper admin page (privacy / data-sharing surface).
 *
 * Lean PHP shell — header + hint host + content root + script include. All
 * rendering (collected-data, hosts, endpoint atoms, coverage) and the dialogs
 * (the panel's one modal) live in privacy.js, which calls getPrivacyStatus and
 * marks each control that sends a command with its role mark. Mirrors storage.php.
 *
 * The privacy helper is the data-SHARING half of the compliance story (what the
 * site sends to APIs / third parties); the storage registry is the browser-
 * storage half.
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<!-- The page's words (QSPanelWords), before its script: its own section; common and nav come from the layout -->
<?= __adminWords(['privacy']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/privacy.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/privacy.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>
        </svg>
        <?= adminEscape(__admin('privacy.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('privacy.subtitle')) ?></p>
</div>

<!-- What a language's words are and where they show (multilingual projects only): privacy.js writes it. -->
<p id="privacy-desclang-hint" class="admin-hint privacy-desclang-hint" hidden></p>

<div id="privacy-root" class="privacy-root" role="region" aria-label="<?= adminAttr(__admin('privacy.title')) ?>">
    <div style="padding: 24px; color: var(--admin-text-muted, #555);"><?= adminEscape(__admin('privacy.loading')) ?></div>
</div>
