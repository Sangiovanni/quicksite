<?php
/**
 * Embed Security Settings Page
 * 
 * Manages iframe sandbox rules — domain allowlists and permission levels.
 * 
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');

// The sub-trees embed-security.js renders from, verbatim and under the same
// dot paths PHP uses, so the JS asks for the path PHP would.
$qsEmbedSecurityI18n = [];
foreach (['embedSecurity', 'common'] as $qsSubtree) {
    $qsEmbedSecurityI18n[$qsSubtree] = AdminTranslation::getInstance()->getRaw($qsSubtree) ?: new stdClass();
}
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

<!-- Iframe Sandbox Rules -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <line x1="3" y1="9" x2="21" y2="9"/>
                <line x1="9" y1="21" x2="9" y2="9"/>
            </svg>
            <?= __admin('embedSecurity.sandboxRules') ?>
        </h2>
        <button type="button" class="admin-btn admin-btn--small admin-btn--primary" id="btn-add-rule">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('embedSecurity.addRule') ?>
        </button>
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
        <div class="admin-form-group">
            <label class="admin-label" for="default-policy"><?= __admin('embedSecurity.unmatchedDomains') ?></label>
            <select id="default-policy" class="admin-select" style="max-width: 350px;">
                <option value=""><?= __admin('embedSecurity.blockEverythingRecommended') ?></option>
                <option value="allow-scripts"><?= __admin('embedSecurity.allowScriptsOnly') ?></option>
                <option value="allow-scripts allow-same-origin"><?= __admin('embedSecurity.allowScriptsSameOrigin') ?></option>
            </select>
            <p class="admin-hint"><?= __admin('embedSecurity.emptySandboxHint') ?></p>
        </div>
        <button type="button" class="admin-btn admin-btn--primary" id="btn-save-default">
            <?= __admin('embedSecurity.saveDefault') ?>
        </button>
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

<!-- Add/Edit Rule Modal -->
<div class="admin-modal" id="rule-modal" style="display: none;">
    <div class="admin-modal__backdrop" data-close-modal></div>
    <div class="admin-modal__content" style="max-width: 520px;">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title" id="rule-modal-title"><?= __admin('embedSecurity.addSandboxRule') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>&times;</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-form-group">
                <label class="admin-label" for="rule-tag"><?= __admin('embedSecurity.tagLabel') ?></label>
                <select id="rule-tag" class="admin-select">
                    <!-- Populated dynamically from valid_tags -->
                </select>
                <p class="admin-hint"><?= __admin('embedSecurity.tagHint') ?></p>
            </div>
            <div class="admin-form-group">
                <label class="admin-label" for="rule-domain"><?= __admin('embedSecurity.domainLabel') ?></label>
                <input type="text" id="rule-domain" class="admin-input" placeholder="<?= __admin('embedSecurity.domainPlaceholder') ?>" style="font-family: monospace;" />
                <p class="admin-hint"><?= __admin('embedSecurity.domainHint') ?></p>
            </div>
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('embedSecurity.sandboxPermissions') ?></label>
                <div id="permission-checkboxes" style="display: grid; gap: var(--space-xs);"></div>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--secondary" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-save-rule"><?= __admin('embedSecurity.saveRule') ?></button>
        </div>
    </div>
</div>
