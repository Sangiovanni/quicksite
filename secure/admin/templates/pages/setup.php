<?php
/**
 * Admin First-Run Page
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

// A username to OFFER when the field is otherwise empty. Generated per render —
// a failed POST re-renders with whatever was typed, so this never overwrites a
// value someone chose (see the field below). Random by design, not derived from
// the display name: qs_suggest_username() explains why.
$usernameSuggestion = qs_suggest_username();

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
    // CSRF gate FIRST — the setup token is the real authorisation here, but a
    // forged POST must not get to spend the setup throttle guessing it.
    if (!$router->formTokenValid((string)($_POST['form_token'] ?? ''))) {
        $setupError = 'csrf';
    } else {
        $name = (string)($_POST['name'] ?? '');
        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $token = (string)($_POST['setup_token'] ?? '');

        $result = $router->attemptSetup($name, $username, $password, $passwordConfirm, $token);
        if ($result === null) {
            // Created. No auto-login — the login page stays the single
            // session-establishing point (it shows the "account created" banner).
            $router->redirect('login');
        }
        if (strpos($result, 'throttled:') === 0) {
            $setupError = 'throttled';
            $setupRetryAfter = (int)substr($result, strlen('throttled:'));
        } elseif (strpos($result, 'invalid_token:') === 0) {
            // A wrong token whose refusal started a wait: both are said.
            $setupError = 'invalid_token';
            $setupRetryAfter = (int)substr($result, strlen('invalid_token:'));
        } elseif (strpos($result, 'password_too_short:') === 0) {
            $setupError = 'password_too_short';
            $setupMinLength = (int)substr($result, strlen('password_too_short:'));
        } else {
            // 'invalid_token' | 'missing_fields' | 'password_mismatch'
            // | 'invalid_username' | 'name_equals_username' | 'setup_complete' | 'server'
            $setupError = $result;
        }
    }
}
?>

<div class="admin-login">
    <div class="admin-login__header">
        <!-- Intrinsic size as well as the CSS one. This is the first page a
             deployer ever sees, sometimes on an install whose assets do not
             resolve yet (a misconfigured DocumentRoot 404s admin.css). Without
             a width/height the wordmark takes its own 1025 pixel width and the logo
             swallows the page, pushing the token instructions and any error message
             below the fold, and a 404 of the images shows the alt at this size too.
             The CSS rule still wins when it loads, so nothing changes on a healthy
             install. -->
        <img class="admin-login__mark" src="<?= $baseUrl ?>/admin/assets/images/logo.svg" alt="" width="69" height="64">
        <img class="admin-login__wordmark" src="<?= $baseUrl ?>/admin/assets/images/quicksite.svg" alt="<?= adminAttr(__admin('common.appName')) ?>" width="140" height="44">
        <h1 class="admin-login__title"><?= adminEscape(__admin('setup.title')) ?></h1>
        <p class="admin-login__subtitle"><?= adminEscape(__admin('setup.subtitle')) ?></p>
    </div>

    <div class="admin-card">
        <div class="admin-card__body">
            <?php if (!$tokenWritable): ?>
            <div class="admin-alert admin-alert--error">
                <strong><?= adminEscape(__admin('setup.tokenWriteFailed.title')) ?></strong>
                <p><?= adminEscape(__admin('setup.tokenWriteFailed.body')) ?></p>
                <p><code><?= adminAttr($tokenPath) ?></code></p>
                <p><?= adminEscape(__admin('setup.tokenWriteFailed.fix')) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($setupError === 'csrf'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('login.csrfFailed')) ?></div>
            <?php elseif ($setupError === 'throttled'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.throttled', ['duration' => __adminDuration($setupRetryAfter)])) ?></div>
            <?php elseif ($setupError === 'invalid_token'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.invalidToken')) ?><?php if ($setupRetryAfter > 0): ?><br><?= adminEscape(__admin('setup.throttled', ['duration' => __adminDuration($setupRetryAfter)])) ?><?php endif; ?></div>
            <?php elseif ($setupError === 'password_too_short'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.passwordTooShort', ['min' => $setupMinLength])) ?></div>
            <?php elseif ($setupError === 'password_mismatch'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.passwordMismatch')) ?></div>
            <?php elseif ($setupError === 'invalid_username'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.invalidUsername')) ?></div>
            <?php elseif ($setupError === 'name_equals_username'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.nameEqualsUsername')) ?></div>
            <?php elseif ($setupError === 'setup_complete'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.alreadyDone')) ?></div>
            <?php elseif ($setupError === 'missing_fields'): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.missingFields')) ?></div>
            <?php elseif ($setupError !== null): ?>
            <div class="admin-alert admin-alert--error"><?= adminEscape(__admin('setup.serverError')) ?></div>
            <?php endif; ?>

            <div class="admin-alert admin-alert--info">
                <strong><?= adminEscape(__admin('setup.tokenWhere.title')) ?></strong>
                <p><?= adminEscape(__admin('setup.tokenWhere.body')) ?></p>
                <p><code><?= adminAttr($tokenPath) ?></code></p>
                <p class="admin-hint"><?= adminEscape(__admin('setup.tokenWhere.why')) ?></p>
            </div>

            <form id="admin-setup-form" method="POST" action="">
                <input type="hidden" name="form_token" value="<?= adminAttr($router->formToken()) ?>">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="setup_token">
                        <?= adminEscape(__admin('setup.tokenLabel')) ?>
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
                        <?= adminEscape(__admin('setup.nameLabel')) ?>
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
                    <p class="admin-hint"><?= adminEscape(__admin('setup.nameHint')) ?></p>
                </div>

                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="username">
                        <?= adminEscape(__admin('setup.usernameLabel')) ?>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="admin-input"
                        <?php /* The POSTed value wins whenever there is one, so a rejected
                                 submission hands back what was typed rather than a fresh
                                 suggestion the person never saw. */ ?>
                        value="<?= adminAttr(($_POST['username'] ?? '') !== '' ? (string)$_POST['username'] : $usernameSuggestion) ?>"
                        maxlength="32"
                        <?php /* The '-' is ESCAPED. Browsers now compile the pattern attribute
                                 with the RegExp `v` flag, which reserves '-' inside a character
                                 class; an unescaped one makes the whole pattern invalid, and an
                                 invalid pattern is IGNORED — silently dropping client-side
                                 validation on this field. Every other pattern in the panel
                                 already writes it this way. */ ?>
                        pattern="[a-zA-Z0-9_\-]{3,32}"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                    >
                    <p class="admin-hint"><?= adminEscape(__admin('setup.usernameHint')) ?></p>
                    <p class="admin-hint"><?= adminEscape(__admin('setup.usernameSuggested')) ?></p>
                </div>

                <?php /* The password twice, as on the register form: typed once, a slip
                         would only surface at the first sign-in. The router compares the
                         two before the gate, so a mismatch costs no throttle budget. Both
                         fields share this markup and the toggle script below. */
                $passwordFields = [
                    ['id' => 'password',         'label' => __admin('setup.passwordLabel'), 'hint' => __admin('setup.passwordHint', ['min' => $passwordMinLength])],
                    ['id' => 'password_confirm', 'label' => __admin('setup.passwordConfirmLabel'),      'hint' => null],
                ];
                foreach ($passwordFields as $field): ?>
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="<?= $field['id'] ?>">
                        <?= adminEscape($field['label']) ?>
                    </label>
                    <div style="position: relative;">
                        <input
                            type="password"
                            id="<?= $field['id'] ?>"
                            name="<?= $field['id'] ?>"
                            class="admin-input"
                            style="padding-right: 2.75rem;"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            type="button"
                            data-password-toggle="<?= $field['id'] ?>"
                            aria-label="<?= adminAttr(__admin('login.showPassword')) ?>"
                            title="<?= adminAttr(__admin('login.showPassword')) ?>"
                            style="position: absolute; top: 50%; right: 0.5rem; transform: translateY(-50%); background: none; border: none; padding: 0.25rem; cursor: pointer; color: inherit; opacity: 0.65; line-height: 0;"
                        >
                            <svg data-eye="show" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg data-eye="hide" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                    <?php if ($field['hint'] !== null): ?>
                    <p class="admin-hint"><?= adminEscape($field['hint']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg admin-btn--block">
                    <?= adminEscape(__admin('setup.submit')) ?>
                </button>
            </form>
        </div>
    </div>

    <div class="admin-login__help">
        <div class="admin-login__help-title"><?= adminEscape(__admin('setup.help.title')) ?></div>
        <p class="admin-login__help-text"><?= adminEscape(__admin('setup.help.text')) ?></p>
    </div>
</div>

<?= __adminWords(['login.showPassword', 'login.hidePassword']) ?>
<script>
// Password visibility toggles — same behaviour as the login page, one per field:
// each button names the input it reveals.
(function () {
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        var input = document.getElementById(btn.getAttribute('data-password-toggle'));
        var eye = btn.querySelector('[data-eye="show"]');
        var eyeOff = btn.querySelector('[data-eye="hide"]');
        if (!input || !eye || !eyeOff) return;
        btn.addEventListener('click', function () {
            var reveal = input.type === 'password';
            var label = QSPanelWords.t(reveal ? 'login.hidePassword' : 'login.showPassword');
            input.type = reveal ? 'text' : 'password';
            eye.style.display = reveal ? 'none' : '';
            eyeOff.style.display = reveal ? '' : 'none';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            input.focus();
        });
    });
})();
</script>
