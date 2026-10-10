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

<script>
window.QS_OAUTH_I18N = <?= qs_inline_script_json([
    'none'            => __admin('oauthProviders.none'),
    'loadFailed'      => __admin('oauthProviders.loadFailed'),
    'keysPreview'     => __admin('oauthProviders.keysPreview'),
    'keysPreviewHint' => __admin('oauthProviders.keysPreviewHint'),
    'keysBuild'       => __admin('oauthProviders.keysBuild'),
    'keysBuildHint'   => __admin('oauthProviders.keysBuildHint'),
    'statusSet'       => __admin('oauthProviders.statusSet'),
    'statusMissing'   => __admin('oauthProviders.statusMissing'),
    'routesReady'     => __admin('oauthProviders.routesReady'),
    'routesPartial'   => __admin('oauthProviders.routesPartial'),
    'routesNone'      => __admin('oauthProviders.routesNone'),
    'usedByOne'       => __admin('oauthProviders.usedByOne'),
    'usedByMany'      => __admin('oauthProviders.usedByMany'),
    'clientId'        => __admin('oauthProviders.clientId'),
    'clientSecret'    => __admin('oauthProviders.clientSecret'),
    'secretKeep'      => __admin('oauthProviders.secretKeep'),
    'secretNew'       => __admin('oauthProviders.secretNew'),
    'save'            => __admin('oauthProviders.save'),
    'saved'           => __admin('oauthProviders.saved'),
    'clear'           => __admin('oauthProviders.clear'),
    'clearConfirm'    => __admin('oauthProviders.clearConfirm'),
    'cleared'         => __admin('oauthProviders.cleared'),
    'saveFailed'      => __admin('oauthProviders.saveFailed'),
    'readOnly'        => __admin('oauthProviders.readOnly'),
    'howTo'           => __admin('oauthProviders.howTo'),
    'howStep1'        => __admin('oauthProviders.howStep1'),
    'howStep1Link'    => __admin('oauthProviders.howStep1Link'),
    'howStep2'        => __admin('oauthProviders.howStep2'),
    'howStep3'        => __admin('oauthProviders.howStep3'),
    'howStep3Example' => __admin('oauthProviders.howStep3Example'),
    'howStep4'        => __admin('oauthProviders.howStep4'),
    'copy'            => __admin('common.copy'),
    'copied'          => __admin('oauthProviders.copied'),
    'notes'           => [
        'google' => __admin('oauthProviders.noteGoogle'),
        'github' => __admin('oauthProviders.noteGithub'),
        'amazon' => __admin('oauthProviders.noteAmazon'),
        'meta'   => __admin('oauthProviders.noteMeta'),
    ],
]) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/oauth-providers.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/oauth-providers.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon oauth-providers-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <?= htmlspecialchars(__admin('oauthProviders.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= htmlspecialchars(__admin('oauthProviders.subtitle')) ?></p>
</div>

<div class="admin-toolbar oauth-providers-toolbar">
    <div class="admin-toolbar__left">
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh-oauth-providers">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            <?= htmlspecialchars(__admin('oauthProviders.refresh')) ?>
        </button>
    </div>
</div>

<div id="oauth-providers-list" class="oauth-providers-list" role="region" aria-label="<?= htmlspecialchars(__admin('oauthProviders.title')) ?>">
    <div class="oauth-providers-list__loading"><?= htmlspecialchars(__admin('oauthProviders.loading')) ?></div>
</div>
