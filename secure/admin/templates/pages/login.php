<?php
/**
 * Admin Login Page
 *
 * Username + password authentication (C5b; username identity C8 8.0b). The
 * form POSTs to this page; the router verifies the credentials through the
 * shared login gate and establishes the PHP session that IS the login. There
 * is no access token and no refresh token; "remember me" simply gives the
 * session cookie a lifetime so it survives a browser restart. No credential is
 * ever stored in browser JS storage.
 *
 * The form carries a CSRF token (double-submit against an HttpOnly cookie the
 * router plants) — a login form is forgeable like any other, and being logged
 * into an account somebody else chose is a real attack, not a nuisance.
 *
 * @version 1.6.0
 */

$loginError = null;
$loginRetryAfter = 0;

// The username the register page or the first-run page just created — neither
// logs you in, both send you here, and this page pre-fills it (see
// QS_REGISTER_FLASH for the two notes and how long each lasts).
//
// Registration's is NOT consumed here. The server chose that username and this
// is the only place the person can read it, so it stays on screen, with the
// warning to save it, through reloads and failed attempts until a sign-in
// succeeds in this browser — qs_session_establish() drops it then — or until it
// is QS_REGISTER_FLASH_TTL old, whichever comes first: qs_register_note() drops
// it then, and no visit here moves that clock. First-run's is shown once: the
// operator typed that one.
$assignedUsername = qs_register_note();
$registerNoteHours = intdiv(QS_REGISTER_FLASH_TTL, 3600);
$setupUsername = $_SESSION[QS_SETUP_FLASH] ?? '';
$setupUsername = is_string($setupUsername) ? $setupUsername : '';
// Guarded: a visitor with no session has no $_SESSION at all, and on PHP 8.0 an
// unset() of an offset on it raises an "undefined variable" warning.
if (isset($_SESSION[QS_SETUP_FLASH])) {
    unset($_SESSION[QS_SETUP_FLASH]);
}
$flashUsername = $assignedUsername !== '' ? $assignedUsername : $setupUsername;

// An install with no accounts never reaches this page: the router sends every
// admin URL to the first-run page while the registry is empty.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF gate FIRST — before the credentials are even looked at. A forged
    // cross-site POST must not be able to log the visitor into an account the
    // attacker chose, and must not be able to spend their login-throttle
    // budget probing usernames either.
    if (!$router->formTokenValid((string)($_POST['form_token'] ?? ''))) {
        $loginError = 'csrf';
    } else {
        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $remember = isset($_POST['remember']);

        $result = $router->attemptLogin($username, $password, $remember);
        if ($result === null) {
            $router->redirect('dashboard');
        }
        if (strpos($result, 'throttled:') === 0) {
            $loginError = 'throttled';
            $loginRetryAfter = (int)substr($result, strlen('throttled:'));
        } else {
            $loginError = $result; // 'invalid_credentials' | 'server'
        }
    }
}
?>

<div class="admin-login">
    <div class="admin-login__header">
        <!-- Intrinsic width/height so the mark stays 64px even if admin.css fails to
             load; without them an SVG with only a viewBox fills its container and pushes
             the form below the fold. The CSS rule still wins when it loads, so nothing
             changes on a healthy install. -->
        <svg class="admin-login__logo" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
        </svg>
        <h1 class="admin-login__title"><?= __admin('login.title') ?></h1>
        <p class="admin-login__subtitle"><?= __admin('login.subtitle') ?></p>
    </div>

    <div class="admin-card">
        <div class="admin-card__body">
            <?php if ($assignedUsername !== ''): ?>
            <?php /* One box, because the name and the instruction to save it are one
                     message: splitting them read as two unrelated notices. */ ?>
            <div class="admin-alert admin-alert--success">
                <strong><?= __admin('login.registered.title') ?></strong>
                <p><?= __admin('login.registered.intro') ?></p>
                <p class="admin-alert__highlight"><code><?= adminAttr($assignedUsername) ?></code></p>
                <p class="admin-alert__note">
                    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span><?= __admin('login.registered.warning', ['hours' => $registerNoteHours]) ?></span>
                </p>
            </div>
            <?php elseif ($setupUsername !== ''): ?>
            <div class="admin-alert admin-alert--success"><?= __admin('login.registerSuccess') ?></div>
            <?php endif; ?>

            <?php if ($loginError === 'csrf'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.csrfFailed') ?></div>
            <?php elseif ($loginError === 'throttled'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.throttled', ['seconds' => $loginRetryAfter]) ?></div>
            <?php elseif ($loginError === 'server'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.serverError') ?></div>
            <?php elseif ($loginError === 'missing_fields'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.missingFields') ?></div>
            <?php elseif ($loginError !== null): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.invalidCredentials') ?></div>
            <?php endif; ?>

            <form id="admin-login-form" method="POST" action="">
                <input type="hidden" name="form_token" value="<?= adminAttr($router->formToken()) ?>">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="username">
                        <?= __admin('login.usernameLabel') ?>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="admin-input"
                        placeholder="<?= adminAttr(__admin('login.usernamePlaceholder')) ?>"
                        <?php /* A submitted value wins (a failed login keeps what was typed);
                                 otherwise the just-created username, if there is one. */ ?>
                        value="<?= adminAttr((string)($_POST['username'] ?? $flashUsername)) ?>"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                    >
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="password">
                        <?= __admin('login.passwordLabel') ?>
                    </label>
                    <div style="position: relative;">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="admin-input"
                            style="padding-right: 2.75rem;"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            type="button"
                            id="password-toggle"
                            aria-label="<?= adminAttr(__admin('login.showPassword')) ?>"
                            title="<?= adminAttr(__admin('login.showPassword')) ?>"
                            style="position: absolute; top: 50%; right: 0.5rem; transform: translateY(-50%); background: none; border: none; padding: 0.25rem; cursor: pointer; color: inherit; opacity: 0.65; line-height: 0;"
                        >
                            <svg id="password-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="password-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="admin-form-group">
                    <div class="admin-checkbox-group">
                        <input
                            type="checkbox"
                            id="remember"
                            name="remember"
                            class="admin-checkbox"
                            <?= isset($_POST['remember']) ? 'checked' : '' ?>
                        >
                        <label for="remember" class="admin-checkbox-label">
                            <?= __admin('login.rememberSession') ?>
                        </label>
                    </div>
                    <p class="admin-hint"><?= __admin('login.rememberHint') ?></p>
                </div>

                <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg admin-btn--block">
                    <?= __admin('login.submit') ?>
                </button>

                <p class="admin-hint" style="margin-top: var(--space-md); text-align: center;">
                    <?= __admin('login.privacyNote') ?>
                </p>

                <?php if ($router->isRegistrationOpen()): ?>
                <p class="admin-hint" style="margin-top: var(--space-md); text-align: center;">
                    <a href="<?= adminAttr($router->url('register')) ?>"><?= __admin('login.registerLink') ?></a>
                </p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="admin-login__help">
        <div class="admin-login__help-title"><?= __admin('login.help.title') ?></div>
        <p class="admin-login__help-text"><?= $router->isRegistrationOpen() ? __admin('login.help.textRegisterOpen') : __admin('login.help.text') ?></p>
    </div>
</div>

<script>
// Password visibility toggle — lets the user verify a pasted password.
(function () {
    var input = document.getElementById('password');
    var btn = document.getElementById('password-toggle');
    var eye = document.getElementById('password-eye');
    var eyeOff = document.getElementById('password-eye-off');
    if (!input || !btn) return;
    var labels = {
        show: <?= json_encode(__admin('login.showPassword')) ?>,
        hide: <?= json_encode(__admin('login.hidePassword')) ?>
    };
    btn.addEventListener('click', function () {
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        eye.style.display = reveal ? 'none' : '';
        eyeOff.style.display = reveal ? '' : 'none';
        btn.setAttribute('aria-label', reveal ? labels.hide : labels.show);
        btn.setAttribute('title', reveal ? labels.hide : labels.show);
        input.focus();
    });
})();
</script>
