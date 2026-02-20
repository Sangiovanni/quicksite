<?php
/**
 * Admin Login Page
 * 
 * Authentication page for the admin panel.
 * Token is validated via AJAX before form submission.
 * 
 * @version 1.6.0
 */

// Handle form submission (after JS validation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (!empty($token)) {
        $router->setToken($token, $remember);
        $router->redirect('dashboard');
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
            <!-- Error message container (hidden by default) -->
            <div class="admin-alert admin-alert--error" style="display: none;"></div>
            
            <form id="admin-login-form" method="POST" action="">
                <div class="admin-form-group">
                    <label class="admin-label admin-label--required" for="token">
                        <?= __admin('login.tokenLabel') ?>
                    </label>
                    <input 
                        type="password" 
                        id="token" 
                        name="token" 
                        class="admin-input" 
                        placeholder="<?= adminAttr(__admin('login.tokenPlaceholder')) ?>"
                        autocomplete="off"
                        required
                    >
                </div>
                
                <div class="admin-form-group">
                    <div class="admin-checkbox-group">
                        <input 
                            type="checkbox" 
                            id="remember" 
                            name="remember" 
                            class="admin-checkbox"
                        >
                        <label for="remember" class="admin-checkbox-label">
                            <?= __admin('login.rememberToken') ?>
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
// Check if token is remembered in localStorage
document.addEventListener('DOMContentLoaded', function() {
    const remembered = localStorage.getItem('quicksite_admin_remember');
    const token = localStorage.getItem('quicksite_admin_token');
    
    if (remembered && token) {
        // Auto-fill token if remembered
        document.getElementById('token').value = token;
        document.getElementById('remember').checked = true;
    }
});
</script>
