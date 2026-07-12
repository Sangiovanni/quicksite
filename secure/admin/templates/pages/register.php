<?php
/**
 * Admin Self-Registration Page (C8)
 *
 * Renders ONLY while auth.php `registration.allow_self_registration` is true
 * (the router redirects to login otherwise; the underlying gate also enforces
 * the flag + flood controls on every POST). The form POSTs to this page and
 * goes through the SAME shared registration gate as the public `register`
 * command. On success the user is sent to the login page with a one-shot
 * "account created" banner — no auto-login (the login page is the single
 * session-establishing point).
 *
 * A duplicate email shows the same success path as a real creation (no
 * account-existence oracle) — the person simply discovers at sign-in.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$registerError = null;
$registerRetryAfter = 0;
$registerMinLength = 0;
$passwordMinLength = qs_registration_config()['min_password_length'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = (string)($_POST['name'] ?? '');
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $result = $router->attemptRegister($name, $email, $password);
    if ($result === null) {
        $router->redirect('login');
    }
    if (strpos($result, 'throttled:') === 0) {
        $registerError = 'throttled';
        $registerRetryAfter = (int)substr($result, strlen('throttled:'));
    } elseif (strpos($result, 'password_too_short:') === 0) {
        $registerError = 'password_too_short';
        $registerMinLength = (int)substr($result, strlen('password_too_short:'));
    } else {
        // 'registration_disabled' | 'registration_closed' | 'missing_fields'
        // | 'invalid_email' | 'server'
        $registerError = $result;
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
        <h1 class="admin-login__title"><?= __admin('register.title') ?></h1>
        <p class="admin-login__subtitle"><?= __admin('register.subtitle') ?></p>
    </div>

    <div class="admin-card">
        <div class="admin-card__body">
            <?php if ($registerError === 'throttled'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.throttled', ['seconds' => $registerRetryAfter]) ?></div>
            <?php elseif ($registerError === 'password_too_short'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.passwordTooShort', ['min' => $registerMinLength]) ?></div>
            <?php elseif ($registerError === 'invalid_email'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.invalidEmail') ?></div>
            <?php elseif ($registerError === 'registration_closed'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.closed') ?></div>
            <?php elseif ($registerError === 'registration_disabled'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.disabled') ?></div>
            <?php elseif ($registerError === 'missing_fields'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.missingFields') ?></div>
            <?php elseif ($registerError !== null): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('register.serverError') ?></div>
            <?php endif; ?>

            <form id="admin-register-form" method="POST" action="">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="name">
                        <?= __admin('register.nameLabel') ?>
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="admin-input"
                        placeholder="<?= adminAttr(__admin('register.namePlaceholder')) ?>"
                        value="<?= adminAttr((string)($_POST['name'] ?? '')) ?>"
                        maxlength="200"
                        autocomplete="name"
                        required
                    >
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="email">
                        <?= __admin('register.emailLabel') ?>
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="admin-input"
                        placeholder="<?= adminAttr(__admin('register.emailPlaceholder')) ?>"
                        value="<?= adminAttr((string)($_POST['email'] ?? '')) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="password">
                        <?= __admin('register.passwordLabel') ?>
                    </label>
                    <div style="position: relative;">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="admin-input"
                            style="padding-right: 2.75rem;"
                            autocomplete="new-password"
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
                    <p class="admin-hint"><?= __admin('register.passwordHint', ['min' => $passwordMinLength]) ?></p>
                </div>

                <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg admin-btn--block">
                    <?= __admin('register.submit') ?>
                </button>

                <p class="admin-hint" style="margin-top: var(--space-md); text-align: center;">
                    <a href="<?= adminAttr($router->url('login')) ?>"><?= __admin('register.backToLogin') ?></a>
                </p>
            </form>
        </div>
    </div>
</div>

<script>
// Password visibility toggle — same behaviour as the login page.
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
