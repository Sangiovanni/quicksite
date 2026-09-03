<?php
/**
 * My Account page (beta.11 S1.3).
 *
 * The self-service surface for the SIGNED-IN account itself — the one thing
 * the panel had no page for. Every command it drives is global-scope
 * `account.self` / `auth.session` (access 'any'), so this page is open to
 * every authenticated user including one who belongs to no project: there is
 * no role gate and it must not acquire one.
 *
 * Three things you can do to your own account, in ascending order of finality:
 *   change the password        — also ends your OTHER sessions (the
 *                              generation counter; this one survives)
 *   logoutSession {everywhere} — ends every session including this one
 *   delete the account         — irreversible, behind the current password
 *                              AND a typed confirmation
 *
 * Only the middle one is a command. The password change and the deletion are
 * account self-service, served by /admin/self since beta.11 S6 — the command
 * surface is a CLI for DEVELOPING a project, and neither of those is that.
 *
 * Lean PHP shell: identity, section shells and form skeletons live here; every
 * dynamic row, result and confirm modal is built by account.js with
 * createElement + _render* helpers (CLAUDE.md HTML-in-JS hygiene).
 */

$baseUrl = rtrim(BASE_URL, '/');

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$__auth     = qs_session_auth();
$__user     = !empty($__auth['valid']) ? ($__auth['user'] ?? []) : [];
$__userId   = (string)($__auth['userId'] ?? '');
$__name     = (string)($__user['name'] ?? '');
$__username = (string)($__user['username'] ?? '');
$__role     = $router->getTokenRole();     // role on the EDITED project, may be null
$__project  = (string)($router->getCurrentProject() ?? '');

// An externally-managed account (password_hash null) can neither prove its
// current password nor set a new one, so both password-gated actions are
// refused by the commands themselves. Say so up front instead of offering
// forms that can only fail.
$__hasLocalPassword = is_string($__user['password_hash'] ?? null) && ($__user['password_hash'] !== '');

// The single source of the minimum — auth.php registration.min_password_length,
// the same value the password change enforces server-side.
$__minPasswordLength = qs_registration_config()['min_password_length'];
?>

<script>
window.QS_ACCOUNT_CONFIG = {
    username: <?= json_encode($__username) ?>,
    minPasswordLength: <?= (int)$__minPasswordLength ?>,
    hasLocalPassword: <?= $__hasLocalPassword ? 'true' : 'false' ?>,
    loginUrl: <?= json_encode($router->url('login')) ?>
};
window.QS_ACCOUNT_I18N = <?= json_encode([
    'cancel'              => __admin('common.cancel'),
    'error'               => __admin('common.error'),
    'passwordMismatch'    => __admin('account.password.mismatch', 'The two new passwords do not match.'),
    'passwordTooShort'    => __admin('account.password.tooShort', 'The new password is too short.'),
    'passwordRequired'    => __admin('account.password.required', 'Fill in your current password and the new one.'),
    'passwordSameAsOld'   => __admin('account.password.sameAsOld', 'The new password is the same as the current one.'),
    'passwordChanged'     => __admin('account.password.changedMsg', 'Password changed — your other sessions have been signed out.'),
    'everywhereTitle'     => __admin('account.sessions.confirmTitle', 'Sign out everywhere?'),
    'everywhereBody'      => __admin('account.sessions.confirmBody', 'Every session of your account ends, including this one. You will be asked to sign in again.'),
    'everywhereBtn'       => __admin('account.sessions.submit', 'Sign out everywhere'),
    'everywhereDone'      => __admin('account.sessions.doneMsg', 'Signed out everywhere — redirecting to the login page.'),
    'deleteTitle'         => __admin('account.delete.confirmTitle', 'Delete your account — final confirmation'),
    'deleteBody'          => __admin('account.delete.confirmBody', 'This permanently deletes your account, ends every session, and removes you from every project you belong to. It cannot be undone.'),
    'deleteBtn'           => __admin('account.delete.submit', 'Delete my account'),
    'deleteNeedsPassword' => __admin('account.delete.needsPassword', 'Enter your current password.'),
    'deleteNeedsTyped'    => __admin('account.delete.needsTyped', 'Type your username exactly to confirm.'),
    'deleteDone'          => __admin('account.delete.doneMsg', 'Your account has been deleted.'),
    'soleOwnerTitle'      => __admin('account.delete.soleOwnerTitle', 'You still own these projects'),
    'soleOwnerHint'       => __admin('account.delete.soleOwnerHint', 'Transfer ownership on the Project Members page, or delete the project, then come back.'),
    'members'             => __admin('account.delete.memberCount', 'members'),
]) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/account.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/account.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <?= __admin('account.title', 'My account') ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= __admin('account.subtitle', 'Your password, your sessions and your account itself. Nothing here touches other people.') ?></p>
</div>

<!-- Identity — read-only. Rendered server-side from the session: the panel
     already knows who you are, and there is no command that returns your own
     username (it is the PRIVATE login identifier). -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('account.identity.title', 'Identity') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <dl class="account-identity">
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= __admin('account.identity.name', 'Display name') ?></dt>
                    <dd class="account-identity__value"><?= adminEscape($__name) ?></dd>
                </div>
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= __admin('account.identity.username', 'Username') ?></dt>
                    <dd class="account-identity__value"><code><?= adminEscape($__username) ?></code>
                        <span class="admin-hint"><?= __admin('account.identity.usernameHint', 'private — used only to sign in') ?></span>
                    </dd>
                </div>
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= __admin('account.identity.userId', 'Account id') ?></dt>
                    <dd class="account-identity__value"><code><?= adminEscape($__userId) ?></code></dd>
                </div>
                <?php if ($__role !== null && $__project !== ''): ?>
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= __admin('account.identity.role', 'Role on the project you are editing') ?></dt>
                    <dd class="account-identity__value">
                        <span class="members-role-chip members-role-chip--<?= adminEscape((string)$__role) ?>"><?= adminEscape((string)$__role) ?></span>
                        <span class="admin-hint"><?= adminEscape($__project) ?></span>
                    </dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</section>

<?php if (!$__hasLocalPassword): ?>
<div class="admin-alert admin-alert--info">
    <?= __admin('account.externallyManaged', 'This account has no local password — it is managed by the platform that created it. Changing or deleting it must happen there.') ?>
</div>
<?php endif; ?>

<?php if ($__hasLocalPassword): ?>
<!-- Change password. The command re-verifies the current password on the login
     throttle, then bumps the session generation and re-stamps THIS session:
     your other browsers are signed out, this one is not. -->
<section class="admin-section" id="account-password-section">
    <h2 class="admin-section__title"><?= __admin('account.password.title', 'Change password') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= __admin('account.password.hint', 'Changing your password signs out every OTHER session of your account — this is how you lock out someone who has your old password. The browser you are using now stays signed in.') ?></p>
            <div class="account-form-grid">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-current-password"><?= __admin('account.password.currentLabel', 'Current password') ?></label>
                    <input type="password" id="account-current-password" class="admin-input" autocomplete="current-password">
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-new-password"><?= __admin('account.password.newLabel', 'New password') ?></label>
                    <input type="password" id="account-new-password" class="admin-input" autocomplete="new-password" minlength="<?= (int)$__minPasswordLength ?>">
                    <p class="admin-hint"><?= __admin('account.password.minHint', ['min' => $__minPasswordLength]) ?></p>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-confirm-password"><?= __admin('account.password.confirmLabel', 'Repeat the new password') ?></label>
                    <input type="password" id="account-confirm-password" class="admin-input" autocomplete="new-password">
                </div>
            </div>
            <button type="button" id="btn-change-password" class="admin-btn admin-btn--primary"><?= __admin('account.password.submit', 'Change password') ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Sessions. One integer on the account record ends them all — there is no
     session list to walk, so there is nothing here to enumerate. -->
<section class="admin-section" id="account-sessions-section">
    <h2 class="admin-section__title"><?= __admin('account.sessions.title', 'Sessions') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= __admin('account.sessions.hint', 'Signs you out of every browser, phone and tab where this account is signed in — including this one. Use it when you think somebody else holds a session of yours, or when you have left one open somewhere you cannot reach.') ?></p>
            <button type="button" id="btn-logout-everywhere" class="admin-btn admin-btn--outline"><?= __admin('account.sessions.submit', 'Sign out everywhere') ?></button>
        </div>
    </div>
</section>

<?php if ($__hasLocalPassword): ?>
<!-- Delete account — irreversible. The deletion refuses while you are the
     sole owner of any project (that would leave it unownable AND undeletable
     forever); the refusal names them and account.js lists them here. -->
<section class="admin-section" id="account-delete-section">
    <h2 class="admin-section__title admin-text-danger"><?= __admin('account.delete.title', 'Delete my account') ?></h2>
    <div class="admin-card account-danger-zone">
        <div class="admin-card__body">
            <p class="admin-warning"><?= __admin('account.delete.warn', 'Permanently deletes your account, ends every session, and removes you from every project you belong to. There is no undo and no operator lane to restore it. You cannot delete an account that is the sole owner of a project — transfer or delete those projects first.') ?></p>
            <div class="account-form-grid">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-delete-password"><?= __admin('account.delete.passwordLabel', 'Your current password') ?></label>
                    <input type="password" id="account-delete-password" class="admin-input" autocomplete="current-password">
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-delete-typed">
                        <?= __admin('account.delete.typedLabel', 'Type your username to confirm') ?>
                    </label>
                    <input type="text" id="account-delete-typed" class="admin-input" autocomplete="off" autocapitalize="none" spellcheck="false"
                           placeholder="<?= adminAttr($__username) ?>">
                </div>
            </div>
            <div id="account-delete-blockers"></div>
            <button type="button" id="btn-delete-account" class="admin-btn admin-btn--danger"><?= __admin('account.delete.submit', 'Delete my account') ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<div id="account-modal-root"></div>
