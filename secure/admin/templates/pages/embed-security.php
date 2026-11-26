<?php
/**
 * Embed Security page — READ-ONLY view of the install-wide embed policy.
 *
 * The policy is set at deployment (<secure>/management/config/embed-policy.json)
 * and cannot be changed from the panel; this page shows what is in force so an
 * author whose embed is sandboxed can see why and who to ask. All rendering is
 * driven by embed-security.js from getIframeSandbox.
 */

$baseUrl = rtrim(BASE_URL, '/');

// An installation can decline to show this page (setup item 11, embed-security.php;
// ABSENT MEANS SHOWN, see embedSecurityPolicy.php). Hidden, it says so plainly, as
// the console's page does, rather than answer a 404 that would send the next person
// to debug routing. ⚠ It removes a page, not a rule: the policy stays in force, and
// getIframeSandbox keeps answering on /management to every role that holds it.
if (!$router->isEmbedSecurityShown()):
?>
<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?= adminEscape(__admin('embedSecurity.title')) ?>
    </h1>
</div>
<div class="admin-empty">
    <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
    </svg>
    <h2 class="admin-empty__title"><?= adminEscape(__admin('embedSecurity.disabled.title')) ?></h2>
    <p class="admin-empty__text"><?= adminEscape(__admin('embedSecurity.disabled.text')) ?></p>
</div>
<?php
    return;
endif;
?>

<script>
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.baseUrl = '<?= $baseUrl ?>/management';
</script>
<?php // The words embed-security.js draws: the load failure, an empty token list, no allowed host. ?>
<?= __adminWords(['embedSecurity.errors', 'embedSecurity.blockEverything', 'embedSecurity.noRules']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/embed-security.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/embed-security.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?= adminEscape(__admin('embedSecurity.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('embedSecurity.subtitle')) ?></p>
</div>

<!-- Read-only notice: where the policy lives and who can change it -->
<div class="admin-card">
    <div class="admin-card__body">
        <p><strong><?= adminEscape(__admin('embedSecurity.readonlyNotice')) ?></strong></p>
        <p class="admin-hint"><?= str_replace('{path}', '<code>' . htmlspecialchars('<secure>/management/config/embed-policy.json', ENT_QUOTES, 'UTF-8') . '</code>', adminEscape(__admin('embedSecurity.policyLocation'))) ?></p>
        <p class="admin-hint"><?= adminEscape(__admin('embedSecurity.howToChange')) ?></p>
    </div>
</div>

<!-- Allowed hosts -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <line x1="3" y1="9" x2="21" y2="9"/>
                <line x1="9" y1="21" x2="9" y2="9"/>
            </svg>
            <?= adminEscape(__admin('embedSecurity.sandboxRules')) ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= adminEscape(__admin('embedSecurity.intro')) ?>
        </p>
        <div id="rules-container">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <?= adminEscape(__admin('embedSecurity.loadingRules')) ?>
            </div>
        </div>
    </div>
</div>

<!-- Default Policy -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <?= adminEscape(__admin('embedSecurity.defaultPolicy')) ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= adminEscape(__admin('embedSecurity.defaultPolicyHint')) ?>
        </p>
        <div id="default-policy-value"></div>
        <p class="admin-hint"><?= adminEscape(__admin('embedSecurity.emptySandboxHint')) ?></p>
    </div>
</div>

<!-- Never Allowed -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
            </svg>
            <?= adminEscape(__admin('embedSecurity.neverAllowed')) ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= adminEscape(__admin('embedSecurity.neverAllowedHint')) ?>
        </p>
        <div id="never-allowed-list"></div>
    </div>
</div>
