<?php
/**
 * My Memberships page.
 *
 * The caller's OWN membership surface — works for EVERY authenticated account,
 * 0-membership included (the freshly-registered flow lands here to accept its
 * first invitation). Fires only GLOBAL self-service commands, so it produces
 * zero 403 noise for non-members.
 *
 * Lean PHP shell: static section shells + the request-to-join form live here;
 * all dynamic rows (projects / inbox / requests / proposals / notices) are
 * rendered by memberships.js (createElement + _render* helpers per the
 * CLAUDE.md HTML-in-JS hygiene rule).
 */

$baseUrl = rtrim(BASE_URL, '/');

// The caller's own opaque public id — the "you" marker + self-row logic need
// it and no API response carries it (deliberate; it is page-render data).
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
$__myAuth = qs_session_auth();
$__myUserId = !empty($__myAuth['valid']) ? (string)($__myAuth['userId'] ?? '') : '';

// Which roles can actually use the builds page, DERIVED from roles.php +
// categories.php rather than named here.
//
// This page lists projects, and the caller's role differs per project — so the
// panel-wide permission set (/admin/self/permissions, which answers for the EDITED
// project only) cannot gate a per-project link. The row already carries
// `my_role`; this is the other half of the comparison. `getBuild` is the read
// the page itself is gated on, so a role that holds it is a role that can open
// the page and see something.
//
// Server-derived on purpose: a change to roles.php moves this with it, and no
// list of role names is written down twice.
$__buildRoles = [];
foreach (array_keys(loadRolesConfig()) as $__role) {
    if (in_array('getBuild', getRoleCommands($__role) ?? [], true)) {
        $__buildRoles[] = $__role;
    }
}
?>

<script>
window.QS_MEMBERSHIPS_CONFIG = {
    myUserId: <?= json_encode($__myUserId) ?>,
    editedProject: <?= json_encode($router->getCurrentProject()) ?>,
    buildRoles: <?= json_encode($__buildRoles) ?>,
    buildsUrl: <?= json_encode($router->url('builds')) ?>,
    // Project creation lives on the dashboard; the zero-membership empty state
    // links there rather than carrying a second copy of the create modal.
    createUrl: <?= json_encode($router->url('dashboard') . '?create=project') ?>
};
</script>
<?= __adminWords(['memberships.myProjects', 'memberships.inbox', 'memberships.requests', 'memberships.proposals', 'memberships.notices', 'memberships.request']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/memberships.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/memberships.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <?= adminEscape(__admin('memberships.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('memberships.subtitle')) ?></p>
</div>

<!-- My projects -->
<section class="admin-section" id="memberships-projects-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.myProjects.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-projects" role="region" aria-label="<?= adminAttr(__admin('memberships.myProjects.title')) ?>">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- Invitation inbox -->
<section class="admin-section" id="memberships-inbox-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.inbox.title')) ?> <span class="members-count-chip" id="memberships-inbox-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-inbox">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- My join requests -->
<section class="admin-section" id="memberships-requests-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.requests.title')) ?> <span class="members-count-chip" id="memberships-requests-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-requests">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- My proposals (sponsor lane) -->
<section class="admin-section" id="memberships-proposals-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.proposals.title')) ?> <span class="members-count-chip" id="memberships-proposals-count" hidden>0</span></h2>
    <p class="admin-hint"><?= adminEscape(__admin('memberships.proposals.hint')) ?></p>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-proposals">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- Notices -->
<section class="admin-section" id="memberships-notices-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.notices.title')) ?> <span class="members-count-chip" id="memberships-notices-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-notices">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- Request to join -->
<section class="admin-section" id="memberships-request-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('memberships.request.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= adminEscape(__admin('memberships.request.hint')) ?></p>
            <div class="admin-form-group">
                <label class="admin-label" for="request-project-id"><?= adminEscape(__admin('memberships.request.projectLabel')) ?> <span>*</span></label>
                <input type="text" id="request-project-id" class="admin-input" placeholder="#k7m2p9xq4" autocomplete="off">
            </div>
            <div class="admin-form-group">
                <label class="admin-label" for="request-note"><?= adminEscape(__admin('memberships.request.noteLabel')) ?> <span>*</span></label>
                <textarea id="request-note" class="admin-input" rows="3" maxlength="500"></textarea>
            </div>
            <button type="button" id="btn-send-join-request" class="admin-btn admin-btn--primary"><?= adminEscape(__admin('memberships.request.submit')) ?></button>
        </div>
    </div>
</section>
