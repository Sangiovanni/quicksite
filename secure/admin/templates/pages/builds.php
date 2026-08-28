<?php
/**
 * Builds page (beta.11 S3.8).
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
?>

<script>
window.QS_BUILDS_CONFIG = {
    project: <?= json_encode($router->getCurrentProject()) ?>,
    // The project's build is roughly the size of its content, so this is where
    // "will my next build still fit" gets answered. Kept with the page rather
    // than hardcoded in JS so the shape is visible to whoever tunes it: warn
    // once a build would eat more than this fraction of what is available.
    spaceWarnFraction: 0.5
};
window.QS_BUILDS_I18N = <?= json_encode([
    'loading'          => __admin('common.loading'),
    'error'            => __admin('common.error'),
    'cancel'           => __admin('common.cancel'),
    'close'            => __admin('common.close', 'Close'),
    'none'             => __admin('builds.none', 'This project has no build yet.'),
    'noneHint'         => __admin('builds.noneHint', 'A build compiles the project into a standalone site you can download and put on a server.'),
    'buildBtn'         => __admin('builds.buildBtn', 'Build now'),
    'building'         => __admin('builds.building', 'Building… this can take a while on a large project.'),
    'builtMsg'         => __admin('builds.builtMsg', 'Build completed'),
    'downloadBtn'      => __admin('builds.downloadBtn', 'Download'),
    'downloading'      => __admin('builds.downloading', 'Preparing the archive…'),
    'downloadFailed'   => __admin('builds.downloadFailed', 'Download failed'),
    'deleteBtn'        => __admin('builds.deleteBtn', 'Delete'),
    'deletedMsg'       => __admin('builds.deletedMsg', 'Build deleted'),
    'deleteFailed'     => __admin('builds.deleteFailed', 'Delete failed'),
    'deleteTitle'      => __admin('builds.deleteTitle', 'Delete this build?'),
    'deleteBody'       => __admin('builds.deleteBody', 'This is the only copy. Nothing else on the server keeps it, and deleting cannot be undone.'),
    'deleteWhy'        => __admin('builds.deleteWhy', 'Deleting is also how you make room for the next one — a project holds one build at a time.'),
    'deleteDownloadFirst' => __admin('builds.deleteDownloadFirst', 'Download it first'),
    'deleteDownloaded' => __admin('builds.deleteDownloaded', 'Downloaded — the archive is in your browser\'s downloads.'),
    'oneAtATime'       => __admin('builds.oneAtATime', 'One build at a time'),
    'oneAtATimeBody'   => __admin('builds.oneAtATimeBody', 'This project already has a build, so a new one is refused rather than silently replacing it. Download this build if you want to keep a copy, then delete it and build again.'),
    'incomplete'       => __admin('builds.incomplete', 'incomplete'),
    'incompleteBody'   => __admin('builds.incompleteBody', 'This build did not finish, so it carries no manifest and cannot be downloaded or deployed. Delete it and build again.'),
    'fieldCreated'     => __admin('builds.fieldCreated', 'Created'),
    'fieldSize'        => __admin('builds.fieldSize', 'Size'),
    'fieldFiles'       => __admin('builds.fieldFiles', 'Files'),
    'fieldPages'       => __admin('builds.fieldPages', 'Pages'),
    'fieldLanguages'   => __admin('builds.fieldLanguages', 'Languages'),
    'fieldSiteName'    => __admin('builds.fieldSiteName', 'Site identity'),
    'fieldPublic'      => __admin('builds.fieldPublic', 'Public folder'),
    'fieldSecure'      => __admin('builds.fieldSecure', 'Secure folder'),
    'fieldSpace'       => __admin('builds.fieldSpace', 'URL space'),
    'spaceRoot'        => __admin('builds.spaceRoot', '(served from the root)'),
    'oauthWarn'        => __admin('builds.oauthWarn', 'This build carries OAuth client secrets. Treat the archive as a credential, not just a website.'),
    'noControls'       => __admin('builds.noControls', 'Your role on this project can view builds but not create, download or delete them. Ask a project admin if you need one.'),
    'spaceTitle'       => __admin('builds.spaceTitle', 'Space'),
    'spaceProject'     => __admin('builds.spaceProject', 'This project uses'),
    'spaceOfWhich'     => __admin('builds.spaceOfWhich', 'of which the build is'),
    'spaceFree'        => __admin('builds.spaceFree', 'Left in your quota'),
    'spaceNoQuota'     => __admin('builds.spaceNoQuota', 'No storage quota is configured on this installation, so there is no ceiling to run into.'),
    'spaceNotOwner'    => __admin('builds.spaceNotOwner', 'The quota that applies here belongs to the project\'s owner, not to you, so there is no figure to show.'),
    'spaceWarnTitle'   => __admin('builds.spaceWarnTitle', 'A build may not fit for much longer'),
    'spaceWarnBody'    => __admin('builds.spaceWarnBody', 'A build is roughly the size of the project\'s content — about {need} today — and you have {free} of quota available for it. Past half, growth starts to squeeze out the build itself. Free some space, or ask for a larger quota, before it does.'),
    'refresh'          => __admin('builds.refresh', 'Re-measure'),
]) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/builds.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/builds.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
            <line x1="12" y1="22.08" x2="12" y2="12"/>
        </svg>
        <?= __admin('builds.title', 'Builds') ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= __admin('builds.subtitle', 'Compile this project into a standalone site, download it, and clear it away when you are done. One build at a time.') ?></p>
</div>

<section class="admin-section">
    <h2 class="admin-section__title">
        <?= __admin('builds.sectionTitle', 'This project\'s build') ?>
        <span class="builds-project-chip" id="builds-project-chip"></span>
    </h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-body">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<section class="admin-section" id="builds-space-section">
    <h2 class="admin-section__title"><?= __admin('builds.spaceTitle', 'Space') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-space">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<div id="builds-modal-root"></div>
