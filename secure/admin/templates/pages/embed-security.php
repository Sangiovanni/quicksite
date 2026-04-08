<?php
/**
 * Embed Security Settings Page
 * 
 * Manages iframe sandbox rules — domain allowlists and permission levels.
 * 
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script>
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.baseUrl = '<?= $baseUrl ?>/management';
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/embed-security.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/embed-security.js') ?>"></script>

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
            Embed Sandbox Rules
        </h2>
        <button type="button" class="admin-btn admin-btn--small admin-btn--primary" id="btn-add-rule">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Rule
        </button>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            When an embed tag (iframe, video, audio) is added to a page, its content is sandboxed by default. Add rules below to grant specific permissions to trusted domains.
        </p>
        <div id="rules-container">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                Loading rules...
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
            Default Policy
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            This policy applies to iframes whose domain does not match any rule above.
        </p>
        <div class="admin-form-group">
            <label class="admin-label" for="default-policy">Unmatched domains</label>
            <select id="default-policy" class="admin-select" style="max-width: 350px;">
                <option value="">Block everything (recommended)</option>
                <option value="allow-scripts">Allow scripts only</option>
                <option value="allow-scripts allow-same-origin">Allow scripts + same-origin</option>
            </select>
            <p class="admin-hint">Empty sandbox ("Block everything") prevents all scripts and interactions.</p>
        </div>
        <button type="button" class="admin-btn admin-btn--primary" id="btn-save-default">
            Save Default
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
            Never Allowed
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            These permissions are always stripped by the system, regardless of any rule configuration. This prevents embedded content from hijacking the parent page.
        </p>
        <div id="never-allowed-list"></div>
    </div>
</div>

<!-- Add/Edit Rule Modal -->
<div class="admin-modal" id="rule-modal" style="display: none;">
    <div class="admin-modal__backdrop" data-close-modal></div>
    <div class="admin-modal__content" style="max-width: 520px;">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title" id="rule-modal-title">Add Sandbox Rule</h3>
            <button type="button" class="admin-modal__close" data-close-modal>&times;</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-form-group">
                <label class="admin-label" for="rule-tag">Tag</label>
                <select id="rule-tag" class="admin-select">
                    <!-- Populated dynamically from valid_tags -->
                </select>
                <p class="admin-hint">Select the HTML embed tag this rule applies to.</p>
            </div>
            <div class="admin-form-group">
                <label class="admin-label" for="rule-domain">Domain</label>
                <input type="text" id="rule-domain" class="admin-input" placeholder="youtube.com" style="font-family: monospace;" />
                <p class="admin-hint">Enter a full domain name. Subdomains are automatically included (youtube.com also matches www.youtube.com).</p>
            </div>
            <div class="admin-form-group">
                <label class="admin-label">Sandbox permissions</label>
                <div id="permission-checkboxes" style="display: grid; gap: var(--space-xs);"></div>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--secondary" data-close-modal>Cancel</button>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-save-rule">Save Rule</button>
        </div>
    </div>
</div>
