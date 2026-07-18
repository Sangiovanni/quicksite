<?php
/**
 * My Memberships page (C8 8.3c).
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
$__myAuth = validateBearerToken('Bearer ' . (string)$router->getToken());
$__myUserId = !empty($__myAuth['valid']) ? (string)($__myAuth['userId'] ?? '') : '';
?>

<script>
window.QS_MEMBERSHIPS_CONFIG = {
    myUserId: <?= json_encode($__myUserId) ?>,
    editedProject: <?= json_encode($router->getCurrentProject()) ?>
};
window.QS_MEMBERSHIPS_I18N = <?= json_encode([
    'loading'            => __admin('common.loading'),
    'error'              => __admin('common.error'),
    'cancel'             => __admin('common.cancel'),
    'roleChip'           => __admin('memberships.myProjects.roleChip', 'role'),
    'ownedChip'          => __admin('memberships.myProjects.ownedChip', 'OWNED'),
    'editingChip'        => __admin('memberships.myProjects.editingChip', 'editing'),
    'editBtn'            => __admin('memberships.myProjects.editBtn', 'Edit this project'),
    'leaveBtn'           => __admin('memberships.myProjects.leaveBtn', 'Leave'),
    'leaveTitle'         => __admin('memberships.myProjects.leaveTitle', 'Leave project'),
    'leaveConfirm'       => __admin('memberships.myProjects.leaveConfirm', 'You are about to leave {project}. You will need a fresh invitation to rejoin.'),
    'leftMsg'            => __admin('memberships.myProjects.leftMsg', 'You left the project'),
    'projectsEmpty'      => __admin('memberships.myProjects.empty', 'You are not a member of any project yet. Accept an invitation or send a join request below.'),
    'inboxEmpty'         => __admin('memberships.inbox.empty', 'No pending invitations.'),
    'invitedBy'          => __admin('memberships.inbox.invitedBy', 'Invited by'),
    'proposedBy'         => __admin('memberships.inbox.proposedBy', 'proposed by'),
    'offeredRole'        => __admin('memberships.inbox.offeredRole', 'offered role'),
    'accept'             => __admin('memberships.inbox.accept', 'Accept'),
    'decline'            => __admin('memberships.inbox.decline', 'Decline'),
    'acceptedMsg'        => __admin('memberships.inbox.acceptedMsg', 'Invitation accepted — welcome aboard'),
    'declinedMsg'        => __admin('memberships.inbox.declinedMsg', 'Invitation declined'),
    'requestsEmpty'      => __admin('memberships.requests.empty', 'No pending join requests.'),
    'withdraw'           => __admin('memberships.requests.withdraw', 'Withdraw'),
    'withdrawnMsg'       => __admin('memberships.requests.withdrawnMsg', 'Request withdrawn'),
    'proposalsEmpty'     => __admin('memberships.proposals.empty', 'No outgoing proposals.'),
    'proposalFor'        => __admin('memberships.proposals.for', 'for'),
    'pendingValidation'  => __admin('memberships.proposals.pendingValidation', 'awaiting validation'),
    'awaitingAnswer'     => __admin('memberships.proposals.awaitingAnswer', 'invitation sent — awaiting their answer'),
    'proposalWithdrawnMsg' => __admin('memberships.proposals.withdrawnMsg', 'Proposal withdrawn'),
    'noticesEmpty'       => __admin('memberships.notices.empty', 'No notices.'),
    'dismiss'            => __admin('memberships.notices.dismiss', 'Dismiss'),
    'dismissedMsg'       => __admin('memberships.notices.dismissedMsg', 'Notice dismissed'),
    'statusRefused'      => __admin('memberships.notices.statusRefused', 'Join request refused'),
    'statusRemoved'      => __admin('memberships.notices.statusRemoved', 'Removed from project'),
    'statusDeleted'      => __admin('memberships.notices.statusDeleted', 'Project deleted'),
    'reason'             => __admin('memberships.notices.reason', 'Reason'),
    'requestSentMsg'     => __admin('memberships.request.sentMsg', 'Join request sent'),
    'noteRequired'       => __admin('memberships.request.noteRequired', 'The note is required — say why you want to join.'),
    'errUnavailable'     => __admin('memberships.request.errUnavailable', 'This project does not accept join requests (or does not exist).'),
    'errClosed'          => __admin('memberships.request.errClosed', 'This project\'s join requests are closed.'),
    'errAlreadyMember'   => __admin('memberships.request.errAlreadyMember', 'You are already a member of this project.'),
    'errAlreadyInvited'  => __admin('memberships.request.errAlreadyInvited', 'You already have a pending invitation here — accept or decline it above.'),
    'errAlreadyRequested'=> __admin('memberships.request.errAlreadyRequested', 'You already asked — withdraw the request above to re-ask.'),
    'errNoticePending'   => __admin('memberships.request.errNoticePending', 'A notice for this project is still in your inbox — dismiss it first.'),
]) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/memberships.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/memberships.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <?= __admin('memberships.title', 'My Memberships') ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= __admin('memberships.subtitle', 'Your projects, invitations, join requests, proposals and notices — everything about where you belong.') ?></p>
</div>

<!-- My projects -->
<section class="admin-section" id="memberships-projects-section">
    <h2 class="admin-section__title"><?= __admin('memberships.myProjects.title', 'My projects') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-projects" role="region" aria-label="<?= adminEscape(__admin('memberships.myProjects.title', 'My projects')) ?>">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- Invitation inbox -->
<section class="admin-section" id="memberships-inbox-section">
    <h2 class="admin-section__title"><?= __admin('memberships.inbox.title', 'Invitation inbox') ?> <span class="members-count-chip" id="memberships-inbox-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-inbox">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- My join requests -->
<section class="admin-section" id="memberships-requests-section">
    <h2 class="admin-section__title"><?= __admin('memberships.requests.title', 'My join requests') ?> <span class="members-count-chip" id="memberships-requests-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-requests">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- My proposals (sponsor lane) -->
<section class="admin-section" id="memberships-proposals-section">
    <h2 class="admin-section__title"><?= __admin('memberships.proposals.title', 'My proposals') ?> <span class="members-count-chip" id="memberships-proposals-count" hidden>0</span></h2>
    <p class="admin-hint"><?= __admin('memberships.proposals.hint', 'People you vouched for on your projects. A proposal that disappears without joining was either denied or answered by the person.') ?></p>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-proposals">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- Notices -->
<section class="admin-section" id="memberships-notices-section">
    <h2 class="admin-section__title"><?= __admin('memberships.notices.title', 'Notices') ?> <span class="members-count-chip" id="memberships-notices-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="memberships-notices">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- Request to join -->
<section class="admin-section" id="memberships-request-section">
    <h2 class="admin-section__title"><?= __admin('memberships.request.title', 'Request to join a project') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= __admin('memberships.request.hint', 'Ask to join a project by its id. The project must have an open join policy; your note is shown to its admins.') ?></p>
            <div class="admin-form-group">
                <label class="admin-label" for="request-project-id"><?= __admin('memberships.request.projectLabel', 'Project id') ?> <span class="admin-text-danger">*</span></label>
                <input type="text" id="request-project-id" class="admin-input" placeholder="my-project" autocomplete="off">
            </div>
            <div class="admin-form-group">
                <label class="admin-label" for="request-note"><?= __admin('memberships.request.noteLabel', 'Why do you want to join?') ?> <span class="admin-text-danger">*</span></label>
                <textarea id="request-note" class="admin-input" rows="3" maxlength="500"></textarea>
            </div>
            <button type="button" id="btn-send-join-request" class="admin-btn admin-btn--primary"><?= __admin('memberships.request.submit', 'Send request') ?></button>
        </div>
    </div>
</section>

<div id="memberships-modal-root"></div>
