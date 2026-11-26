<?php
/**
 * OAuth Providers admin page.
 *
 * The providers the installation offers (a file its operator edits; no command writes it), and
 * this project's two key sets for each: preview (the sign-in on this installation) and build
 * (carried by a build to the deployed site), with a how-to per provider.
 *
 * Lean PHP shell — header, toolbar, container, strings, script include. The provider cards, the
 * how-to and the key forms are rendered by oauth-providers.js (calls listOAuthProviders /
 * setOAuthCredentials), with createElement + named _render* helpers.
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<?php // The page's words, oauth-providers.js reading each by its key's whole path; `common` comes with every page. ?>
<?= __adminWords(['oauthProviders']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/oauth-providers.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/oauth-providers.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?= adminEscape(__admin('oauthProviders.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('oauthProviders.subtitle')) ?></p>
</div>

<div>
    <div>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh-oauth-providers">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            <?= adminEscape(__admin('oauthProviders.refresh')) ?>
        </button>
    </div>
</div>

<div id="oauth-providers-list" class="oauth-providers-list" role="region" aria-label="<?= adminAttr(__admin('oauthProviders.title')) ?>">
    <div class="oauth-providers-list__loading"><?= adminEscape(__admin('oauthProviders.loading')) ?></div>
</div>
