<?php
/**
 * Builds page.
 *
 * The project's ONE build: make it, download it, delete it. Retention is N = 1,
 * so this page shows a single build or none — never a list.
 *
 * Reachable by any member rank (the page is gated on `getBuild`, which is
 * content.read). The three ACTIONS are gated separately in builds.js against
 * the `build` category, so a viewer sees the same information and none of the
 * controls rather than being bounced off the page.
 *
 * Lean PHP shell: static section shells live here; every dynamic row is
 * rendered by builds.js with createElement + named _render* helpers, per the
 * CLAUDE.md HTML-in-JS hygiene rule.
 */

$baseUrl = rtrim(BASE_URL, '/');

require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php'; // qs_inline_script_json
?>

<script>
window.QS_BUILDS_CONFIG = {
    project: <?= qs_inline_script_json($router->getCurrentProject()) ?>,
    // How the page names the project: "Name #id" (projectIdentity.php). A name is
    // free text, so it goes through the inline-script encoder.
    projectLabel: <?= qs_inline_script_json(qs_project_label((string)$router->getCurrentProject())) ?>,
    // The project's build is roughly the size of its content, so this is where
    // "will my next build still fit" gets answered. Kept with the page rather
    // than hardcoded in JS so the shape is visible to whoever tunes it: warn
    // once a build would eat more than this fraction of what is available.
    spaceWarnFraction: 0.5,
    // Where a build's OAuth warning sends the reader to enter the missing build keys.
    oauthPageUrl: <?= qs_inline_script_json($router->url('oauth-providers')) ?>,
};
</script>
<?php // The words builds.js reads, at their own path (QSPanelWords); `common` comes with the layout. ?>
<?= __adminWords(['builds']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/builds.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/builds.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
            <line x1="12" y1="22.08" x2="12" y2="12"/>
        </svg>
        <?= adminEscape(__admin('builds.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('builds.subtitle')) ?></p>
</div>

<section class="admin-section">
    <h2 class="admin-section__title">
        <?= adminEscape(__admin('builds.sectionTitle')) ?>
        <span class="builds-project-chip" id="builds-project-chip"></span>
    </h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-body">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<section class="admin-section" id="builds-space-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('builds.spaceTitle')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-space">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>
