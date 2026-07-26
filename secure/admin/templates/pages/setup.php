<?php
/**
 * Admin First-Run Page (C14)
 *
 * Renders ONLY while the user registry is empty (the router sends every admin
 * URL here in that state, and redirects away the moment an account exists).
 * QuickSite ships no default credential, so this is how the first account comes
 * into being.
 *
 * THE AUTHORISATION IS A FILE. On first render the engine writes a random token
 * to secure/management/config/setup-token.txt; this page names that path and
 * asks for its contents. Reading it requires filesystem access to the install,
 * which is a strictly stronger position than any account it can mint — so
 * "can read the file" is the right bar, and the deployer needs no CLI PHP.
 *
 * The rule is NOT enforced here. It lives at qs_user_create(), the single mint
 * path, so the public `register` command and every other creation route inherit
 * it. This page only collects strings and shows what the shared gate answered.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

$setupError = null;
$setupRetryAfter = 0;
$setupMinLength = 0;
$passwordMinLength = qs_registration_config()['min_password_length'];

// Mint on first render (create-if-absent — never regenerated, or the value the
// deployer is reading would go stale while they read it).
$tokenState = qs_setup_token_ensure();
$tokenWritable = $tokenState['ok'];

// SHOWN RELATIVE TO THE INSTALL ROOT, never absolute. This page is reachable by
// anyone until the first account exists, and an anonymous visitor has no business
// learning the server's filesystem layout. The deployer loses nothing: they
// unpacked QuickSite themselves and ran setup in that very folder.
//
// Composed from SECURE_FOLDER_NAME rather than by stripping a prefix off the
// absolute path — that constant already carries a renamed or nested secure
// folder (e.g. 'backend/project1'), so the instruction stays correct for any
// install layout. Normalised to the platform separator so a Windows deployer is
// not handed a mixed-separator path to paste into Explorer.
$tokenPath = str_replace(
    ['/', '\\'],
    DIRECTORY_SEPARATOR,
    (defined('SECURE_FOLDER_NAME') ? SECURE_FOLDER_NAME : 'secure') . '/management/config/setup-token.txt'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = (string)($_POST['name'] ?? '');
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token = (string)($_POST['setup_token'] ?? '');

    $result = $router->attemptSetup($name, $username, $password, $token);
    if ($result === null) {
        // Created. No auto-login — the login page stays the single
        // session-establishing point (it shows the "account created" banner).
        $router->redirect('login');
    }
    if (strpos($result, 'throttled:') === 0) {
        $setupError = 'throttled';
        $setupRetryAfter = (int)substr($result, strlen('throttled:'));
    } elseif (strpos($result, 'password_too_short:') === 0) {
        $setupError = 'password_too_short';
        $setupMinLength = (int)substr($result, strlen('password_too_short:'));
    } else {
        // 'invalid_token' | 'missing_fields' | 'invalid_username'
        // | 'name_equals_username' | 'setup_complete' | 'server'
        $setupError = $result;
    }
}
?>

<div class="admin-login">
    <div class="admin-login__header">
        <!-- Intrinsic size as well as the CSS one. This is the first page a
             deployer ever sees, sometimes on an install whose assets do not
             resolve yet (a misconfigured DocumentRoot 404s admin.css). Without
             a width/height the browser falls back to the default replaced-element
             size and the logo swallows the page, pushing the token instructions
             and any error message below the fold. The CSS rule still wins when
             it loads, so nothing changes on a healthy install. -->
        <svg class="admin-login__logo" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
        </svg>
        <h1 class="admin-login__title"><?= __admin('setup.title', 'Create your first account') ?></h1>
        <p class="admin-login__subtitle"><?= __admin('setup.subtitle', 'This installation has no accounts yet.') ?></p>
    </div>

    <div class="admin-card">
        <div class="admin-card__body">
            <?php if (!$tokenWritable): ?>
            <div class="admin-alert admin-alert--error">
                <strong><?= __admin('setup.tokenWriteFailed.title', 'The setup token could not be created') ?></strong>
                <p><?= __admin('setup.tokenWriteFailed.body', 'QuickSite needs to write this file inside your QuickSite folder, and the web server user cannot:') ?></p>
                <p><code><?= adminAttr($tokenPath) ?></code></p>
                <p><?= __admin('setup.tokenWriteFailed.fix', 'Give the web server user write access to that folder and reload this page. On Linux this usually means chown to www-data, nginx or your site user.') ?></p>
            </div>
            <?php endif; ?>

            <?php if ($setupError === 'throttled'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.throttled', ['seconds' => $setupRetryAfter]) ?></div>
            <?php elseif ($setupError === 'invalid_token'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.invalidToken', 'That setup token is not valid. Open the file named below and copy the whole line.') ?></div>
            <?php elseif ($setupError === 'password_too_short'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.passwordTooShort', ['min' => $setupMinLength]) ?></div>
            <?php elseif ($setupError === 'invalid_username'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.invalidUsername', 'Use 3-32 characters: lowercase letters, digits, dash or underscore.') ?></div>
            <?php elseif ($setupError === 'name_equals_username'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.nameEqualsUsername', 'Your display name must be different from your username — the username is private.') ?></div>
            <?php elseif ($setupError === 'setup_complete'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.alreadyDone', 'An account already exists on this installation. Sign in instead.') ?></div>
            <?php elseif ($setupError === 'missing_fields'): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.missingFields', 'Every field is required.') ?></div>
            <?php elseif ($setupError !== null): ?>
            <div class="admin-alert admin-alert--error"><?= __admin('setup.serverError', 'The account could not be created.') ?></div>
            <?php endif; ?>

            <div class="admin-alert admin-alert--info">
                <strong><?= __admin('setup.tokenWhere.title', 'Step 1 — read the setup token') ?></strong>
                <p><?= __admin('setup.tokenWhere.body', 'It was written on the server, inside your QuickSite folder. Open this file and copy its single line:') ?></p>
                <p><code><?= adminAttr($tokenPath) ?></code></p>
                <p class="admin-hint"><?= __admin('setup.tokenWhere.why', 'Being able to read that file is what proves you own this installation. The token is used once and then destroyed.') ?></p>
            </div>

            <form id="admin-setup-form" method="POST" action="">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="setup_token">
                        <?= __admin('setup.tokenLabel', 'Setup token') ?>
                    </label>
                    <input
                        type="text"
                        id="setup_token"
                        name="setup_token"
                        class="admin-input"
                        value=""
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                    >
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="name">
                        <?= __admin('setup.nameLabel', 'Display name') ?>
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="admin-input"
                        value="<?= adminAttr((string)($_POST['name'] ?? '')) ?>"
                        maxlength="200"
                        autocomplete="name"
                        required
                    >
                    <p class="admin-hint"><?= __admin('setup.nameHint', 'Shown to other users. Must differ from your username.') ?></p>
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="username">
                        <?= __admin('setup.usernameLabel', 'Username') ?>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="admin-input"
                        value="<?= adminAttr((string)($_POST['username'] ?? '')) ?>"
                        maxlength="32"
                        pattern="[a-zA-Z0-9_-]{3,32}"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                    >
                    <p class="admin-hint"><?= __admin('setup.usernameHint', 'Private — you sign in with it and nobody else sees it. 3-32 characters: letters, digits, dash or underscore.') ?></p>
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="password">
                        <?= __admin('setup.passwordLabel', 'Password') ?>
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
                    <p class="admin-hint"><?= __admin('setup.passwordHint', ['min' => $passwordMinLength]) ?></p>
                </div>

                <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg admin-btn--block">
                    <?= __admin('setup.submit', 'Create account') ?>
                </button>
            </form>
        </div>
    </div>

    <div class="admin-login__help">
        <div class="admin-login__help-title"><?= __admin('setup.help.title', 'Why a token?') ?></div>
        <p class="admin-login__help-text"><?= __admin('setup.help.text', 'This page can create an account without anyone signing in first, so it must be proven that you are the person who installed QuickSite — not a passer-by who found the address. Reading a file on the server is that proof. This page disappears for good once the first account exists.') ?></p>
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
