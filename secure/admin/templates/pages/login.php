<?php
/**
 * Admin Login Page
 *
 * Email + password authentication (C5b). The form POSTs to this page; the
 * router verifies the credentials through the shared login gate and holds the
 * resulting session (access + refresh pair) server-side in the PHP session.
 * "Remember me" persists the rotating refresh token in an HttpOnly cookie —
 * no credential or token is ever stored in browser JS storage.
 *
 * @version 1.6.0
 */

$loginError = null;
$loginRetryAfter = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    $result = $router->attemptLogin($email, $password, $remember);
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
?>

<div class="admin-login">
    <div class="admin-login__header">
        <svg class="admin-login__logo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
        </svg>
        <h1 class="admin-login__title"><?= __admin('login.title') ?></h1>
        <p class="admin-login__subtitle"><?= __admin('login.subtitle') ?></p>
    </div>

    <div class="admin-card">
        <div class="admin-card__body">
            <?php if ($loginError === 'throttled'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.throttled', ['seconds' => $loginRetryAfter]) ?></div>
            <?php elseif ($loginError === 'server'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.serverError') ?></div>
            <?php elseif ($loginError === 'missing_fields'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.missingFields') ?></div>
            <?php elseif ($loginError !== null): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('login.invalidCredentials') ?></div>
            <?php endif; ?>

            <form id="admin-login-form" method="POST" action="">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="email">
                        <?= __admin('login.emailLabel') ?>
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="admin-input"
                        placeholder="<?= adminAttr(__admin('login.emailPlaceholder')) ?>"
                        value="<?= adminAttr((string)($_POST['email'] ?? '')) ?>"
                        autocomplete="username"
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
            </form>
        </div>
    </div>

    <div class="admin-login__help">
        <div class="admin-login__help-title"><?= __admin('login.help.title') ?></div>
        <p class="admin-login__help-text"><?= __admin('login.help.text') ?></p>
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
