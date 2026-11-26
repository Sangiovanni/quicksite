<?php
/**
 * Project Members page.
 *
 * The membership management surface for the EDITED project (the header
 * picker's project — the page banner re-states which project is being
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
$__myAuth = qs_session_auth();
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

// join_policy for the admin-only policy zone — read server-side
// from members.json (no API read surface for join_policy exists, deliberately:
// the page renders on the same engine). Emitted ONLY for admin/owner, the
// setJoinPolicy audience.
$__joinPolicy = null;
if ($__isAdmin && $__project !== '') {
    $__membersData = loadProjectMembers($__project);
    $__joinPolicy = $__membersData['join_policy'] ?? 'closed';
}
?>

<script>
window.QS_MEMBERS_CONFIG = {
    project: <?= json_encode($__project) ?>,
    myUserId: <?= json_encode($__myUserId) ?>,
    myRank: <?= (int)$__myRank ?>,
    roleRanks: <?= json_encode($__roleRanks) ?>,
    joinPolicy: <?= json_encode($__joinPolicy) ?>
};
</script>
<?= __adminWords(['members.roster', 'members.queue', 'members.invite', 'members.contacts', 'members.propose', 'members.policy', 'members.transfer']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/members.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/members.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <?= adminEscape(__admin('members.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('members.subtitle')) ?></p>
</div>

<!-- Which project is being worked on — restated loudly, follows the header picker -->
<div class="members-banner" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
    </svg>
    <span><?= adminEscape(__admin('members.banner.managing')) ?></span>
    <strong class="members-banner__project"><?= adminEscape(qs_project_label($__project)) ?></strong>
    <?php if ($__myRole !== null): ?>
    <span class="members-banner__role-label"><?= adminEscape(__admin('members.banner.yourRole')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
    <span class="members-role-chip members-role-chip--<?= adminEscape((string)$__myRole) ?>"><?= adminEscape(__adminRole((string)$__myRole)) ?></span>
    <?php endif; ?>
</div>

<!-- The projects other than this one where a join request waits for you, each with its number and
     the way to it: the menu's count covers every project you run. Filled by members.js from
     members-badge.js's per-project numbers; hidden when none waits. -->
<div class="admin-alert admin-alert--warning members-elsewhere" id="members-elsewhere" role="status" hidden></div>

<!-- Roster — every member rank -->
<section class="admin-section" id="members-roster-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.roster.title')) ?> <span class="members-count-chip" id="members-roster-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="members-roster" role="region" aria-label="<?= adminAttr(__admin('members.roster.title')) ?>">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<?php if ($__isAdmin): ?>
<!-- Pending queue — admin/owner (listMembers audience) -->
<section class="admin-section" id="members-queue-section" data-requires-command="listMembers">
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.queue.title')) ?> <span class="members-count-chip" id="members-queue-count" hidden>0</span></h2>
    <div class="admin-card">
        <div class="admin-card__body members-list" id="members-queue">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<!-- Invite — admin/owner -->
<section class="admin-section" id="members-invite-section" data-requires-command="inviteMember">
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.invite.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= adminEscape(__admin('members.invite.hint')) ?></p>
            <!-- Contacts shortcut. Stays hidden until members.js has a list to
                 put in it — a new account has nobody, and an empty panel above
                 the search box helps no one. -->
            <div class="members-contacts" id="invite-contacts" hidden>
                <h3 class="members-contacts__title" id="invite-contacts-title"><?= adminEscape(__admin('members.contacts.title')) ?></h3>
                <p class="admin-hint"><?= adminEscape(__admin('members.contacts.hint')) ?></p>
                <div class="members-find-row">
                    <div class="admin-form-group members-find-row__name">
                        <select id="invite-contacts-select" class="admin-input" aria-labelledby="invite-contacts-title"></select>
                    </div>
                    <button type="button" id="btn-invite-contact-forget" class="admin-btn admin-btn--ghost admin-action admin-action--delete" data-qs-icon="trash"
                            aria-label="<?= adminAttr(__admin('members.contacts.forgetOne')) ?>" title="<?= adminAttr(__admin('members.contacts.forgetOne')) ?>" disabled></button>
                </div>
            </div>
            <div class="members-find-row">
                <div class="admin-form-group members-find-row__name">
                    <label class="admin-label" for="invite-find-name"><?= adminEscape(__admin('members.invite.nameLabel')) ?> <span>*</span></label>
                    <input type="text" id="invite-find-name" class="admin-input" autocomplete="off">
                </div>
                <button type="button" id="btn-invite-find" class="admin-btn admin-btn--ghost"><?= adminEscape(__admin('members.invite.search')) ?></button>
            </div>
            <div class="members-find-results" id="invite-find-results"></div>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="invite-role"><?= adminEscape(__admin('members.invite.roleLabel')) ?></label>
                    <select id="invite-role" class="admin-input"></select>
                </div>
                <div class="admin-form-group members-form-row__note">
                    <label class="admin-label" for="invite-note"><?= adminEscape(__admin('members.invite.noteLabel')) ?></label>
                    <input type="text" id="invite-note" class="admin-input" maxlength="500">
                </div>
            </div>
            <button type="button" id="btn-send-invite" class="admin-btn admin-btn--primary" disabled><?= adminEscape(__admin('members.invite.send')) ?></button>
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
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.propose.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <?php if ($__isAdmin): ?>
            <p class="admin-hint"><?= adminEscape(__admin('members.propose.hintAdmin')) ?></p>
            <?php else: ?>
            <p class="admin-hint"><?= adminEscape(__admin('members.propose.hint')) ?></p>
            <?php endif; ?>
            <div class="members-contacts" id="propose-contacts" hidden>
                <h3 class="members-contacts__title" id="propose-contacts-title"><?= adminEscape(__admin('members.contacts.title')) ?></h3>
                <p class="admin-hint"><?= adminEscape(__admin('members.contacts.hint')) ?></p>
                <div class="members-find-row">
                    <div class="admin-form-group members-find-row__name">
                        <select id="propose-contacts-select" class="admin-input" aria-labelledby="propose-contacts-title"></select>
                    </div>
                    <button type="button" id="btn-propose-contact-forget" class="admin-btn admin-btn--ghost admin-action admin-action--delete" data-qs-icon="trash"
                            aria-label="<?= adminAttr(__admin('members.contacts.forgetOne')) ?>" title="<?= adminAttr(__admin('members.contacts.forgetOne')) ?>" disabled></button>
                </div>
            </div>
            <div class="members-find-row">
                <div class="admin-form-group members-find-row__name">
                    <label class="admin-label" for="propose-find-name"><?= adminEscape(__admin('members.invite.nameLabel')) ?> <span>*</span></label>
                    <input type="text" id="propose-find-name" class="admin-input" autocomplete="off">
                </div>
                <button type="button" id="btn-propose-find" class="admin-btn admin-btn--ghost"><?= adminEscape(__admin('members.invite.search')) ?></button>
            </div>
            <div class="members-find-results" id="propose-find-results"></div>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="propose-role"><?= adminEscape(__admin('members.propose.roleLabel')) ?></label>
                    <select id="propose-role" class="admin-input"></select>
                </div>
                <div class="admin-form-group members-form-row__note">
                    <label class="admin-label" for="propose-note"><?= adminEscape(__admin('members.propose.noteLabel')) ?> <span>*</span></label>
                    <input type="text" id="propose-note" class="admin-input" maxlength="500">
                </div>
            </div>
            <button type="button" id="btn-send-propose" class="admin-btn admin-btn--primary" disabled><?= adminEscape(__admin('members.propose.send')) ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($__isAdmin): ?>
<!-- Join policy — admin/owner -->
<section class="admin-section" id="members-policy-section" data-requires-command="setJoinPolicy">
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.policy.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= adminEscape(__admin('members.policy.hint')) ?></p>
            <p class="admin-hint"><?= adminEscape(__admin('members.policy.notVisibility')) ?></p>
            <div class="members-policy-row" id="members-policy-row">
                <span class="admin-label"><?= adminEscape(__admin('members.policy.label')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
                <span class="members-policy-value" id="members-policy-value">…</span>
                <button type="button" id="btn-toggle-policy" class="admin-btn admin-btn--ghost" disabled><?= adminEscape(__admin('members.policy.toggle')) ?></button>
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
    <h2 class="admin-section__title"><?= adminEscape(__admin('members.transfer.title')) ?></h2>
    <div class="admin-card members-danger-zone">
        <div class="admin-card__body">
            <p class="admin-warning"><?= adminEscape(__admin('members.transfer.warn')) ?></p>
            <div class="members-form-row">
                <div class="admin-form-group">
                    <label class="admin-label" for="transfer-member"><?= adminEscape(__admin('members.transfer.memberLabel')) ?></label>
                    <select id="transfer-member" class="admin-input"></select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label" for="transfer-old-role"><?= adminEscape(__admin('members.transfer.oldRoleLabel')) ?></label>
                    <select id="transfer-old-role" class="admin-input"></select>
                </div>
            </div>
            <label class="admin-checkbox-group members-transfer-confirm">
                <input type="checkbox" id="transfer-confirm" class="admin-checkbox">
                <span class="admin-checkbox-label"><?= adminEscape(__admin('members.transfer.confirmLabel')) ?></span>
            </label>
            <button type="button" id="btn-transfer-ownership" class="admin-btn admin-btn--danger"><?= adminEscape(__admin('members.transfer.submit')) ?></button>
        </div>
    </div>
</section>
<?php endif; ?>
