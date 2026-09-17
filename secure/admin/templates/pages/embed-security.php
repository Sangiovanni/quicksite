<?php
/**
 * Embed Security page — READ-ONLY view of the install-wide embed policy.
 *
 * The policy is set at deployment (<secure>/management/config/embed-policy.json)
 * and cannot be changed from the panel; this page shows what is in force so an
 * author whose embed is sandboxed can see why and who to ask. All rendering is
 * driven by embed-security.js from getIframeSandbox.
 *
 * @version 2.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');

// The embedSecurity sub-tree, verbatim and under the same dot paths PHP uses,
// so the JS asks for the path PHP would.
$qsEmbedSecurityI18n = ['embedSecurity' => AdminTranslation::getInstance()->getRaw('embedSecurity') ?: new stdClass()];
?>

<script>
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.baseUrl = '<?= $baseUrl ?>/management';
window.QS_EMBED_SECURITY_I18N = <?= json_encode($qsEmbedSecurityI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/embed-security.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/embed-security.js') ?>"></script>

<div class="admin-page-header">
    <h1><?= __admin('embedSecurity.title') ?></h1>
    <p class="admin-subtitle"><?= __admin('embedSecurity.subtitle') ?></p>
</div>

<!-- Read-only notice: where the policy lives and who can change it -->
<div class="admin-card admin-card--info">
    <div class="admin-card__body">
        <p><strong><?= __admin('embedSecurity.readonlyNotice') ?></strong></p>
        <p class="admin-hint"><?= str_replace(':path', '<code>' . htmlspecialchars('<secure>/management/config/embed-policy.json', ENT_QUOTES, 'UTF-8') . '</code>', __admin('embedSecurity.policyLocation')) ?></p>
        <p class="admin-hint"><?= __admin('embedSecurity.howToChange') ?></p>
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
            <?= __admin('embedSecurity.sandboxRules') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= __admin('embedSecurity.intro') ?>
        </p>
        <div id="rules-container">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <?= __admin('embedSecurity.loadingRules') ?>
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
            <?= __admin('embedSecurity.defaultPolicy') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= __admin('embedSecurity.defaultPolicyHint') ?>
        </p>
        <div id="default-policy-value"></div>
        <p class="admin-hint"><?= __admin('embedSecurity.emptySandboxHint') ?></p>
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
            <?= __admin('embedSecurity.neverAllowed') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            <?= __admin('embedSecurity.neverAllowedHint') ?>
        </p>
        <div id="never-allowed-list"></div>
    </div>
</div>
