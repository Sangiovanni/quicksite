<?php
/**
 * Project Members page (C8 8.3c).
 *
 * The membership management surface for the EDITED project (the header
 * picker's project — R4: the page banner re-states which project is being
 * worked on). Audience is EVERY member rank: all ranks see the roster
 * (getProjectRoster) and may propose (proposeMember); the queue / invite /
 * join-policy zones render for admin+owner, the transfer zone for the owner
 * (server-side branch on the caller's role; the management API re-authorizes
 * every call regardless).
 *
 * Lean PHP shell: banner + section shells + form skeletons live here; all
 * dynamic rows and confirm modals are rendered by members.js (createElement +
 * _render* helpers per the CLAUDE.md HTML-in-JS hygiene rule).
 */

$baseUrl = rtrim(BASE_URL, '/');

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
$__myAuth = validateBearerToken('Bearer ' . (string)$router->getToken());
$__myUserId = !empty($__myAuth['valid']) ? (string)($__myAuth['userId'] ?? '') : '';
$__project  = (string)($router->getCurrentProject() ?? '');
$__myRole   = $router->getTokenRole();          // role on the EDITED project
$__myRank   = roleRank((string)$__myRole);      // 0 when not a member
$__isAdmin  = $__myRank >= 5;
$__isOwner  = ($__myRole === 'owner');

// Role → rank map for the client-side pickers ("strictly below my rank" for
// invite/changeRole; "below owner" for propose). roles.php is the source; the
// server re-checks every rank rule in-lock anyway.
$__roleRanks = [];
foreach (loadRolesConfig() as $__rName => $__rCfg) {
    if (is_array($__rCfg) && isset($__rCfg['rank'])) {
        $__roleRanks[$__rName] = (int)$__rCfg['rank'];
    }
}

// join_policy + visibility for the admin-only policy zone — read server-side
// from members.json (no API read surface for join_policy exists, deliberately:
// the page renders on the same engine). Emitted ONLY for admin/owner, the
// setJoinPolicy audience.
$__joinPolicy = null;
$__visibility = null;
if ($__isAdmin && $__project !== '') {
    $__membersData = loadProjectMembers($__project);
    $__joinPolicy = $__membersData['join_policy'] ?? 'closed';
    $__visibility = $__membersData['visibility'] ?? 'private';
}
?>

<script>
window.QS_MEMBERS_CONFIG = {
    project: <?= json_encode($__project) ?>,
    myUserId: <?= json_encode($__myUserId) ?>,
    myRole: <?= json_encode($__myRole) ?>,
    myRank: <?= (int)$__myRank ?>,
    roleRanks: <?= json_encode($__roleRanks) ?>,
    joinPolicy: <?= json_encode($__joinPolicy) ?>,
    visibility: <?= json_encode($__visibility) ?>
};
window.QS_MEMBERS_I18N = <?= json_encode([
    'loading'          => __admin('common.loading'),
    'error'            => __admin('common.error'),
    'cancel'           => __admin('common.cancel'),
    'you'              => __admin('members.roster.you', 'you'),
    'rosterEmpty'      => __admin('members.roster.empty', 'No members (this should not happen — a project always has its owner).'),
    'queueEmpty'       => __admin('members.queue.empty', 'Nothing pending.'),
    'chipInvite'       => __admin('members.queue.chipInvite', 'invitation'),
    'chipRequest'      => __admin('members.queue.chipRequest', 'join request'),
    'chipProposal'     => __admin('members.queue.chipProposal', 'proposal'),
    'invitedBy'        => __admin('members.queue.invitedBy', 'invited by'),
    'askedBy'          => __admin('members.queue.askedBy', 'asked by'),
    'proposedBy'       => __admin('members.queue.proposedBy', 'proposed by'),
    'approve'          => __admin('members.queue.approve', 'Approve'),
    'deny'             => __admin('members.queue.deny', 'Deny'),
    'cancelInvite'     => __admin('members.queue.cancelInvite', 'Cancel'),
    'approveTitle'     => __admin('members.queue.approveTitle', 'Approve this request?'),
    'approveRoleLabel' => __admin('members.queue.approveRoleLabel', 'Role to grant'),
    'approvedMsg'      => __admin('members.queue.approvedMsg', 'Approved — they are now a member'),
    'convertedMsg'     => __admin('members.queue.convertedMsg', 'Proposal approved — invitation sent, awaiting their answer'),
    'denyTitle'        => __admin('members.queue.denyTitle', 'Deny this request'),
    'denyNoteLabel'    => __admin('members.queue.denyNoteLabel', 'Reason (required — shown to the requester)'),
    'denyNoteRequired' => __admin('members.queue.denyNoteRequired', 'The refusal reason is required.'),
    'deniedMsg'        => __admin('members.queue.deniedMsg', 'Request denied'),
    'cancelTitle'      => __admin('members.queue.cancelTitle', 'Cancel this invitation?'),
    'cancelledMsg'     => __admin('members.queue.cancelledMsg', 'Invitation cancelled'),
    'changeRole'       => __admin('members.roster.changeRole', 'Change role'),
    'changeRoleTitle'  => __admin('members.roster.changeRoleTitle', 'Change role'),
    'newRoleLabel'     => __admin('members.roster.newRoleLabel', 'New role'),
    'apply'            => __admin('members.roster.apply', 'Apply'),
    'roleChangedMsg'   => __admin('members.roster.roleChangedMsg', 'Role updated'),
    'remove'           => __admin('members.roster.remove', 'Remove'),
    'removeTitle'      => __admin('members.roster.removeTitle', 'Remove member'),
    'removeNoteLabel'  => __admin('members.roster.removeNoteLabel', 'Reason (optional — shown to them)'),
    'removedMsg'       => __admin('members.roster.removedMsg', 'Member removed'),
    'searchNoMatch'    => __admin('members.invite.noMatches', 'No account with this exact public name.'),
    'select'           => __admin('members.invite.select', 'Select'),
    'selected'         => __admin('members.invite.selected', 'Selected'),
    'inviteSentMsg'    => __admin('members.invite.sentMsg', 'Invitation sent'),
    'proposeSentMsg'   => __admin('members.propose.sentMsg', 'Proposal recorded — an admin or the owner must validate it'),
    'nameRequired'     => __admin('members.invite.nameRequired', 'Type the exact public name first.'),
    'pickRequired'     => __admin('members.invite.pickRequired', 'Search and select a person first.'),
    'vouchRequired'    => __admin('members.propose.vouchRequired', 'The vouch note is required.'),
    'policyOpen'       => __admin('members.policy.open', 'open'),
    'policyClosed'     => __admin('members.policy.closed', 'closed'),
    'policySavedMsg'   => __admin('members.policy.savedMsg', 'Join policy updated'),
    'policyAdvisory'   => __admin('members.policy.advisory', 'Privacy note: this project is PRIVATE with an OPEN join policy — any authenticated account that knows or guesses its id can send a request and thereby learn the project exists.'),
    'policyConfirmOpenPrivate' => __admin('members.policy.confirmOpenPrivate', 'This project is PRIVATE. Opening the join policy makes it knockable by id: anyone authenticated who knows or guesses the id can send a request and learn the project exists. Open anyway?'),
    'transferConfirmRequired' => __admin('members.transfer.confirmRequired', 'Tick the confirmation checkbox first.'),
    'transferMemberRequired'  => __admin('members.transfer.memberRequired', 'Pick the member who becomes the new owner.'),
    'transferTitle'    => __admin('members.transfer.modalTitle', 'Transfer ownership — final confirmation'),
    'transferBody'     => __admin('members.transfer.modalBody', 'The project is handed to {name}. Your role becomes {role}. This cannot be undone from your side.'),
    'transferBtn'      => __admin('members.transfer.submit', 'Transfer ownership'),
    'transferredMsg'   => __admin('members.transfer.doneMsg', 'Ownership transferred'),
]) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/members.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/members.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <?= __admin('members.title', 'Project Members') ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= __admin('members.subtitle', 'Roster, invitations and join requests of the project you are editing.') ?></p>
</div>

<!-- R4: which project is being worked on — restated loudly, follows the header picker -->
<div class="members-banner" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
    </svg>
    <span><?= __admin('members.banner.managing', 'Managing members of') ?></span>
    <strong class="members-banner__project"><?= adminEscape($__project) ?></strong>
    <?php if ($__myRole !== null): ?>
    <span class="members-banner__role-label"><?= __admin('members.banner.yourRole', 'your role') ?>:</span>
    <span class="members-role-chip members-role-chip--<?= adminEscape((string)$__myRole) ?>"><?= adminEscape((string)$__myRole) ?></span>
    <?php endif; ?>
</div>

<!-- Roster — every member rank -->
<section class="admin-section" id="members-roster-section">
    <h2 class="admin-section__title"><?= __admin('members.roster.title', 'Roster') ?> <span class="members-count-chip" id="members-roster-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="members-roster" role="region" aria-label="<?= adminEscape(__admin('members.roster.title', 'Roster')) ?>">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<?php if ($__isAdmin): ?>
<!-- Pending queue — admin/owner (listMembers audience) -->
<section class="admin-section" id="members-queue-section" data-requires-command="listMembers">
    <h2 class="admin-section__title"><?= __admin('members.queue.title', 'Pending — invitations, requests & proposals') ?> <span class="members-count-chip" id="members-queue-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="members-queue">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= __admin('common.loading') ?></span></div>
        </div>
    </div>
</section>

<!-- Invite — admin/owner -->
<section class="admin-section" id="members-invite-section" data-requires-command="inviteMember">
    <h2 class="admin-section__title"><?= __admin('members.invite.title', 'Invite someone') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= __admin('members.invite.hint', 'Look the person up by their EXACT public name, confirm the right account by its id, pick a role below yours, and send. They join only when they accept.') ?></p>
            <div class="members-find-row">
                <div class="admin-form-group members-find-row__name">
                    <label class="admin-label" for="invite-find-name"><?= __admin('members.invite.nameLabel', 'Their public name') ?> <span class="admin-text-danger">*</span></label>
                    <input type="text" id="invite-find-name" class="admin-input" autocomplete="off">
                </div>
                <button type="button" id="btn-invite-find" class="admin-btn admin-btn--ghost"><?= __admin('members.invite.search', 'Search') ?></button>
            </div>
            <div class="members-find-results" id="invite-find-results"></div>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="invite-role"><?= __admin('members.invite.roleLabel', 'Role to offer') ?></label>
                    <select id="invite-role" class="admin-input admin-input--select"></select>
                </div>
                <div class="admin-form-group members-form-row__note">
                    <label class="admin-label" for="invite-note"><?= __admin('members.invite.noteLabel', 'Note (optional)') ?></label>
                    <input type="text" id="invite-note" class="admin-input" maxlength="500">
                </div>
            </div>
            <button type="button" id="btn-send-invite" class="admin-btn admin-btn--primary" disabled><?= __admin('members.invite.send', 'Send invitation') ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Propose — the sponsor lane, for every rank EXCEPT the owner.
     The owner is the top validator: a proposal of theirs would only ever be
     approved by themselves, so the lane is pure ceremony for them (they can
     inviteMember every role they could propose). An ADMIN still needs it —
     proposeMember caps at the sponsor's OWN rank (<=) while inviteMember
     requires strictly-below, so proposing a fellow ADMIN is how an admin asks
     the owner to sign off on a peer-rank member. -->
<?php if (!$__isOwner): ?>
<section class="admin-section" id="members-propose-section" data-requires-command="proposeMember">
    <h2 class="admin-section__title"><?= __admin('members.propose.title', 'Propose a member') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <?php if ($__isAdmin): ?>
            <p class="admin-hint"><?= __admin('members.propose.hintAdmin', 'You can invite anyone below your own rank directly (above). Use a proposal only to ask the OWNER to sign off on someone you cannot invite yourself — another admin. Your note IS the vouch.') ?></p>
            <?php else: ?>
            <p class="admin-hint"><?= __admin('members.propose.hint', 'Vouch for someone: an admin or the owner validates your proposal before the person is even told. Your note IS the vouch — make it count.') ?></p>
            <?php endif; ?>
            <div class="members-find-row">
                <div class="admin-form-group members-find-row__name">
                    <label class="admin-label" for="propose-find-name"><?= __admin('members.invite.nameLabel', 'Their public name') ?> <span class="admin-text-danger">*</span></label>
                    <input type="text" id="propose-find-name" class="admin-input" autocomplete="off">
                </div>
                <button type="button" id="btn-propose-find" class="admin-btn admin-btn--ghost"><?= __admin('members.invite.search', 'Search') ?></button>
            </div>
            <div class="members-find-results" id="propose-find-results"></div>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="propose-role"><?= __admin('members.propose.roleLabel', 'Suggested role') ?></label>
                    <select id="propose-role" class="admin-input admin-input--select"></select>
                </div>
                <div class="admin-form-group members-form-row__note">
                    <label class="admin-label" for="propose-note"><?= __admin('members.propose.noteLabel', 'Why this person?') ?> <span class="admin-text-danger">*</span></label>
                    <input type="text" id="propose-note" class="admin-input" maxlength="500">
                </div>
            </div>
            <button type="button" id="btn-send-propose" class="admin-btn admin-btn--primary" disabled><?= __admin('members.propose.send', 'Propose') ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($__isAdmin): ?>
<!-- Join policy — admin/owner -->
<section class="admin-section" id="members-policy-section" data-requires-command="setJoinPolicy">
    <h2 class="admin-section__title"><?= __admin('members.policy.title', 'Join policy') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= __admin('members.policy.hint', 'Open = anyone authenticated may send a join request (the self-service front door). Closed = requests bounce; invitations and member proposals always work. Closing never discards requests already pending.') ?></p>
            <div class="members-policy-row" id="members-policy-row">
                <span class="admin-label"><?= __admin('members.policy.label', 'Self-service join requests') ?>:</span>
                <span class="members-policy-value" id="members-policy-value">…</span>
                <button type="button" id="btn-toggle-policy" class="admin-btn admin-btn--ghost" disabled><?= __admin('members.policy.toggle', 'Toggle') ?></button>
            </div>
            <div class="admin-alert admin-alert--warning members-policy-advisory" id="members-policy-advisory" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span id="members-policy-advisory-text"></span>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($__isOwner): ?>
<!-- Transfer ownership — owner only -->
<section class="admin-section" id="members-transfer-section" data-requires-command="transferOwnership">
    <h2 class="admin-section__title admin-text-danger"><?= __admin('members.transfer.title', 'Transfer ownership') ?></h2>
    <div class="admin-card members-danger-zone">
        <div class="admin-card__body">
            <p class="admin-warning"><?= __admin('members.transfer.warn', 'Hands the project to another MEMBER. You keep the role chosen below. There is no undo from your side — only the new owner could transfer it back.') ?></p>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="transfer-member"><?= __admin('members.transfer.memberLabel', 'New owner (must be a member)') ?></label>
                    <select id="transfer-member" class="admin-input admin-input--select"></select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label" for="transfer-old-role"><?= __admin('members.transfer.oldRoleLabel', 'Your role after the transfer') ?></label>
                    <select id="transfer-old-role" class="admin-input admin-input--select"></select>
                </div>
            </div>
            <label class="admin-checkbox-group members-transfer-confirm">
                <input type="checkbox" id="transfer-confirm" class="admin-checkbox">
                <span class="admin-checkbox-label"><?= __admin('members.transfer.confirmLabel', 'I understand the project is handed over and my owner rights end.') ?></span>
            </label>
            <button type="button" id="btn-transfer-ownership" class="admin-btn admin-btn--danger"><?= __admin('members.transfer.submit', 'Transfer ownership') ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<div id="members-modal-root"></div>
