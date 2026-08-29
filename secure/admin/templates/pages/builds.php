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

// ---------------------------------------------------------------------------
// THE DEPLOY CONTROL'S TWO GATES — asked here, so the control is absent from
// the HTML rather than hidden in it.
// ---------------------------------------------------------------------------
// They are genuinely independent and both must hold:
//
//   1. The INSTALLATION allows deploying at all. An operator decision, made on
//      the server in deploy.php; absent means no. Nothing in the panel can
//      change it.
//   2. THIS CALLER may deploy. `deployBuild` is alone in the `deploy` category,
//      granted to admin and owner — so a developer who can build and download
//      still cannot push.
//
// builds.js re-checks both before filling the shell, from the same two facts
// (the flag emitted below, and getMyPermissions). The doubling is deliberate:
// this decides whether the section EXISTS, and that decides whether it gets
// controls, so a shell that somehow survived cannot acquire a deploy button.
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/deployPolicy.php';

$__deployEnabled = qs_deploy_allowed();
$__deployRole    = $router->getTokenRole();
$__deployMayRun  = $__deployRole !== null
                && in_array('deployBuild', getRoleCommands($__deployRole) ?? [], true);
$__deployShow    = $__deployEnabled && $__deployMayRun;
?>

<script>
window.QS_BUILDS_CONFIG = {
    project: <?= json_encode($router->getCurrentProject()) ?>,
    // The project's build is roughly the size of its content, so this is where
    // "will my next build still fit" gets answered. Kept with the page rather
    // than hardcoded in JS so the shape is visible to whoever tunes it: warn
    // once a build would eat more than this fraction of what is available.
    spaceWarnFraction: 0.5,
    // Gate 1 only. The role gate is asked separately in builds.js so the two
    // stay visibly distinct — a caller who sees `true` here may still not be
    // allowed to deploy.
    deployEnabled: <?= $__deployEnabled ? 'true' : 'false' ?>,
<?php if ($__deployShow): ?>
    // Emitted only to a caller who passes both gates.
    //
    // ⚠ THE INSTALL'S OWN PATH IS DELIBERATELY NOT HERE. publicPaths.php exists
    // because this project decided install-root paths do not belong in responses,
    // and emitting one into a page contradicted that for no gain. Deploying to
    // this installation's own root sends NO targetPath at all — deployBuild
    // already defaults to SERVER_ROOT — so the capability is kept and the path
    // never reaches the browser.
    //
    // These two are folder NAMES, not paths. They are what the panel compares
    // the build against to say whether its folders would merge into QuickSite's
    // own or land beside them, and they disclose nothing about where this
    // installation lives.
    installPublicName: <?= json_encode(PUBLIC_FOLDER_NAME) ?>,
    installSecureName: <?= json_encode(SECURE_FOLDER_NAME) ?>,
<?php endif; ?>
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
    // --- Build options ------------------------------------------------------
    'optTitle' => __admin('builds.optTitle', 'Folder names and URL space'),
    'optPublicLabel' => __admin('builds.optPublicLabel', 'Public folder name'),
    'optPublicHint' => __admin('builds.optPublicHint', 'The deployed site\'s document root. Change it only when the server you are deploying to forces a name — some hosts insist on htdocs or public_html.'),
    'optSecureLabel' => __admin('builds.optSecureLabel', 'Secure folder name'),
    'optSecureHint' => __admin('builds.optSecureHint', 'The sibling folder holding the engine and your pages. It must sit OUTSIDE the document root wherever you deploy; renaming it is obscurity, not protection.'),
    'optSpaceLabel' => __admin('builds.optSpaceLabel', 'URL space'),
    'optSpaceHint' => __admin('builds.optSpaceHint', 'Serve the site from a subdirectory instead of the domain root: shop makes it answer at /shop/. Leave empty for the root.'),
    'optPreview' => __admin('builds.optPreview', 'The build will contain'),
    'optSpaceServes' => __admin('builds.optSpaceServes', 'the site itself — it answers at /{space}/, and a bare / is NOT this site'),

    // --- Deploy ------------------------------------------------------------
    'deployTitle'      => __admin('builds.deployTitle', 'Deploy'),
    'deployIntro'      => __admin('builds.deployIntro', 'Copy this build onto a path on this server. That is all deploying does — it does not point a document root, edit your web server configuration, or restart anything.'),
    'deployTargetLabel'=> __admin('builds.deployTargetLabel', 'Deploy to'),
    'deployTargetHint' => __admin('builds.deployTargetHint', 'Where the site\'s folders will be created. It has to be listed in deploy-roots.php on the server, or the deploy is refused — apart from this installation\'s own root, which is always allowed.'),
    'deployWillCreate' => __admin('builds.deployWillCreate', 'This will create'),
    'deployDocRoot'    => __admin('builds.deployDocRoot', 'the document root'),
    'deployOutside'    => __admin('builds.deployOutside', 'must stay OUTSIDE the document root'),
    'deployMergeTitle' => __admin('builds.deployMergeTitle', 'These folders will merge into QuickSite\'s own'),
    'deployMergeBody'  => __admin('builds.deployMergeBody', 'You are deploying into this installation\'s root, and the build uses the same folder names the installation does. The build\'s files will be copied INTO QuickSite\'s own {public}/ and {secure}/ rather than beside them. Build with different public and secure folder names, or deploy somewhere else.'),
    'deployBesideTitle'=> __admin('builds.deployBesideTitle', 'Nothing will serve these until you point a document root'),
    'deployBesideBody' => __admin('builds.deployBesideBody', 'You are deploying into this installation\'s root, so the build\'s folders land beside QuickSite\'s own {public}/ and {secure}/. No web server is looking at them, so the deploy will report success and the site will be invisible until a document root points at {docroot}.'),
    'deployBtn'        => __admin('builds.deployBtn', 'Deploy'),
    'deploying'        => __admin('builds.deploying', 'Copying files…'),
    'deployOverwrite'  => __admin('builds.deployOverwrite', 'Replace files that already exist at the target'),
    'deployOverwriteHint' => __admin('builds.deployOverwriteHint', 'Needed when you are updating a site you deployed before. Without it the deploy stops and lists what it would have replaced.'),
    'deployedMsg'      => __admin('builds.deployedMsg', 'Deployed'),
    'deployFailed'     => __admin('builds.deployFailed', 'Deploy failed'),
    'deployCopied'     => __admin('builds.deployCopied', 'Copied {files} files to {target}'),
    'deployFilesExist' => __admin('builds.deployFilesExist', 'Files already exist at the target'),
    'deployFilesExistBody' => __admin('builds.deployFilesExistBody', '{n} file(s) there would be replaced. Nothing was copied.'),
    'deployReplaceBtn' => __admin('builds.deployReplaceBtn', 'Replace them and deploy'),
    'deployCollisionTitle' => __admin('builds.deployCollisionTitle', 'Some pages would never be reachable'),
    'deployCollisionBody'  => __admin('builds.deployCollisionBody', 'A directory of the same name already sits beside where this site\'s entry point will land, and a real directory wins over the site\'s own routing. These pages would answer with the directory instead:'),
    'deployAnywayBtn'  => __admin('builds.deployAnywayBtn', 'Deploy anyway, leaving them unreachable'),

    // --- Serving guidance, shown after a successful deploy ------------------
    'serveTitle'       => __admin('builds.serveTitle', 'Does it serve?'),
    'serveCheckFirst'  => __admin('builds.serveCheckFirst', 'Check before you change anything. Open the site in a browser — if your pages load, you are done and nothing below applies.'),
    'serveRedeploy'    => __admin('builds.serveRedeploy', 'That is the normal outcome when you are updating a site you set up earlier: deploying is only a file copy, so the web server configuration you already have keeps working.'),
    'serveRealCheck'   => __admin('builds.serveRealCheck', 'The check worth doing is a URL that does not exist. It should answer with YOUR 404 page. If you get the web server\'s own grey error page instead, the routing below is not reaching the site.'),
    'serveIfNotTitle'  => __admin('builds.serveIfNotTitle', 'If it does not serve yet'),
    'serveDocRoot'     => __admin('builds.serveDocRoot', 'One thing is required, and only you can do it: point the site\'s document root at'),
    'serveSpaceNote'   => __admin('builds.serveSpaceNote', 'This build is mounted under a URL space, so it answers at /{space}/ and not at /. The document root is still the folder above it.'),
    'serveDangerTitle' => __admin('builds.serveDangerTitle', 'Never point it at the folder above'),
    'serveDangerBody'  => __admin('builds.serveDangerBody', 'A document root at {target} would serve {secure}/ as well — the engine, your configuration and the source of every page. Renaming those folders is obscurity, not a control. The control is that {secure}/ sits outside the document root.'),
    'serveApacheTitle' => __admin('builds.serveApacheTitle', 'Apache'),
    'serveApacheBody'  => __admin('builds.serveApacheBody', 'Nothing to add. The build ships the .htaccess files that do the routing — they take effect once the document root is right, mod_rewrite is on, and the directory allows overrides (AllowOverride All, or at least FileInfo + Options + Indexes).'),
    'serveNginxTitle'  => __admin('builds.serveNginxTitle', 'nginx'),
    'serveNginxBody'   => __admin('builds.serveNginxBody', 'nginx does not read .htaccess. The build ships a snippet describing this site; include it inside the server block that contains the PHP handler, then reload.'),
    'serveNginxOptional' => __admin('builds.serveNginxOptional', 'If a page answers with nginx\'s own grey error page instead of yours, include this file inside the server block that holds the PHP handler, then reload. Read the top of it first — it says exactly what the include adds, and names one line your PHP handler must already have.'),
    'serveMore'        => __admin('builds.serveMore', 'The full instructions — permissions, both web servers, and how to test — ship as README.txt inside the downloaded archive.'),

    // --- Deploy target mode, co-tenancy refusals, field unlock --------------
    'deployTargetPlaceholder' => __admin('builds.deployTargetPlaceholder', '/var/www/mysite'),
    'deployUseInstall' => __admin('builds.deployUseInstall', 'Deploy to this installation\'s own root'),
    'deployToInstall' => __admin('builds.deployToInstall', 'Deploying into this installation\'s own root.'),
    'deployElsewhere' => __admin('builds.deployElsewhere', 'Choose a different path'),
    'deployInsideInstall' => __admin('builds.deployInsideInstall', 'Inside this installation\'s own root. The panel does not show that path — the server fills it in.'),
    'deployInstallRootLabel' => __admin('builds.deployInstallRootLabel', 'this installation\'s own root'),
    'deploySharedTitle' => __admin('builds.deploySharedTitle', 'Some files were left as they were'),
    'deploySharedBody' => __admin('builds.deploySharedBody', '{n} file(s) outside this site\'s own folder already existed, so they were left untouched: {paths}. They belong to whatever else is served from that document root — another site deployed there, most likely. Replacing files never reaches outside a site\'s own subtree.'),
    'deployUpdateTitle' => __admin('builds.deployUpdateTitle', 'This target already holds this project'),
    'deployUpdateBody' => __admin('builds.deployUpdateBody', 'A deployment of this project was made here on {date}. Updating it replaces the files this build produces. Nothing else at the target is touched, and nothing is deleted.'),
    'deployConfirmUpdateBtn' => __admin('builds.deployConfirmUpdateBtn', 'Update the existing deployment'),
    'deployInUseTitle' => __admin('builds.deployInUseTitle', 'That secure folder name is taken'),
    'deployInUseBody' => __admin('builds.deployInUseBody', 'The folder “{folder}” at this target already belongs to a deployment. Building with a different secure folder name, or deploying somewhere else, is usually what you want. Going ahead writes this site\'s files over what is there — nothing is deleted, but that site stops working.'),
    'deployReplaceOtherBtn' => __admin('builds.deployReplaceOtherBtn', 'Write over it anyway'),
    'deployUnmarkedTitle' => __admin('builds.deployUnmarkedTitle', 'That secure folder already exists'),
    'deployUnmarkedBody' => __admin('builds.deployUnmarkedBody', 'The folder “{folder}” at this target has contents and carries no QuickSite deployment marker, so who owns it cannot be established — a deployment made before markers existed, or something else entirely. Going ahead writes this site\'s files into it; nothing is deleted.'),
    'deployAdoptBtn' => __admin('builds.deployAdoptBtn', 'Use it anyway'),
    'optUnlock' => __admin('builds.optUnlock', 'I want to change this'),
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

<?php if ($__deployShow): ?>
<!-- Deploy. The whole section is absent — not hidden, not disabled — unless the
     installation allows deploying AND this caller's role holds it. See the two
     gates at the top of this file. -->
<section class="admin-section" id="builds-deploy-section">
    <h2 class="admin-section__title"><?= __admin('builds.deployTitle', 'Deploy') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-deploy">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="admin-section" id="builds-space-section">
    <h2 class="admin-section__title"><?= __admin('builds.spaceTitle', 'Space') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="builds-space">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<div id="builds-modal-root"></div>
