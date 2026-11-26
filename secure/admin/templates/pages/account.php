<?php
/**
 * My Account page.
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
 *                              AND the username, typed and checked by the server
 *
 * Only the middle one is a command. The password change and the deletion are
 * account self-service, served by /admin/self — the command surface is a CLI for
 * DEVELOPING a project, and neither of those is that.
 *
 * THE USERNAME IS NOT ON THIS PAGE — not shown, not in the page's script config,
 * not as a placeholder. It is the private half of the sign-in, and this page opens
 * for whoever holds the session: a browser left signed in, a stolen cookie. Knowing
 * it would hand them half a credential. (Hiding it is not what stops them locking
 * the owner out: failed password changes and deletions on this page count against
 * the owner's login throttle, username or not.) The deletion asks the owner to type
 * it and the server checks it (qs_account_delete).
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
    minPasswordLength: <?= (int)$__minPasswordLength ?>,
    hasLocalPassword: <?= $__hasLocalPassword ? 'true' : 'false' ?>,
    loginUrl: <?= json_encode($router->url('login')) ?>
};
</script>
<?= __adminWords(['account.password', 'account.sessions', 'account.delete', 'login.throttled']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/account.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/account.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <?= adminEscape(__admin('account.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('account.subtitle')) ?></p>
</div>

<!-- Identity — read-only, rendered server-side from the session: the public
     name, the account id and the role. Never the username (see the header). -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('account.identity.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <dl class="account-identity">
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= adminEscape(__admin('account.identity.name')) ?></dt>
                    <dd class="account-identity__value"><?= adminEscape($__name) ?></dd>
                </div>
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= adminEscape(__admin('account.identity.userId')) ?></dt>
                    <dd class="account-identity__value"><code><?= adminEscape($__userId) ?></code></dd>
                </div>
                <?php if ($__role !== null && $__project !== ''): ?>
                <div class="account-identity__row">
                    <dt class="account-identity__label"><?= adminEscape(__admin('account.identity.role')) ?></dt>
                    <dd class="account-identity__value">
                        <span class="members-role-chip members-role-chip--<?= adminEscape((string)$__role) ?>"><?= adminEscape(__adminRole((string)$__role)) ?></span>
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
    <?= adminEscape(__admin('account.externallyManaged')) ?>
</div>
<?php endif; ?>

<?php if ($__hasLocalPassword): ?>
<!-- Change password. The command re-verifies the current password on the login
     throttle, then bumps the session generation and re-stamps THIS session:
     your other browsers are signed out, this one is not. -->
<section class="admin-section" id="account-password-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('account.password.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= adminEscape(__admin('account.password.hint')) ?></p>
            <div class="account-form-grid">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-current-password"><?= adminEscape(__admin('account.password.currentLabel')) ?></label>
                    <input type="password" id="account-current-password" class="admin-input" autocomplete="current-password">
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-new-password"><?= adminEscape(__admin('account.password.newLabel')) ?></label>
                    <input type="password" id="account-new-password" class="admin-input" autocomplete="new-password" minlength="<?= (int)$__minPasswordLength ?>">
                    <p class="admin-hint"><?= adminEscape(__admin('account.password.minHint', ['min' => $__minPasswordLength])) ?></p>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-confirm-password"><?= adminEscape(__admin('account.password.confirmLabel')) ?></label>
                    <input type="password" id="account-confirm-password" class="admin-input" autocomplete="new-password">
                </div>
            </div>
            <button type="button" id="btn-change-password" class="admin-btn admin-btn--primary"><?= adminEscape(__admin('account.password.submit')) ?></button>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Sessions. One integer on the account record ends them all — there is no
     session list to walk, so there is nothing here to enumerate. -->
<section class="admin-section" id="account-sessions-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('account.sessions.title')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <p class="admin-hint"><?= adminEscape(__admin('account.sessions.hint')) ?></p>
            <button type="button" id="btn-logout-everywhere" class="admin-btn admin-btn--outline"><?= adminEscape(__admin('account.sessions.submit')) ?></button>
        </div>
    </div>
</section>

<?php if ($__hasLocalPassword): ?>
<!-- Delete account — irreversible. The deletion refuses while you are the
     sole owner of any project (that would leave it unownable AND undeletable
     forever); the refusal names them and account.js lists them here. -->
<section class="admin-section" id="account-delete-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('account.delete.title')) ?></h2>
    <div class="admin-card account-danger-zone">
        <div class="admin-card__body">
            <p class="admin-warning"><?= adminEscape(__admin('account.delete.warn')) ?></p>
            <div class="account-form-grid">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-delete-password"><?= adminEscape(__admin('account.delete.passwordLabel')) ?></label>
                    <input type="password" id="account-delete-password" class="admin-input" autocomplete="current-password">
                </div>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="account-delete-typed">
                        <?= adminEscape(__admin('account.delete.typedLabel')) ?>
                    </label>
                    <input type="text" id="account-delete-typed" class="admin-input" autocomplete="off" autocapitalize="none" spellcheck="false">
                </div>
            </div>
            <div id="account-delete-blockers"></div>
            <button type="button" id="btn-delete-account" class="admin-btn admin-btn--danger"><?= adminEscape(__admin('account.delete.submit')) ?></button>
        </div>
    </div>
</section>
<?php endif; ?>
