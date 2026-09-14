<?php
/**
 * Admin Command Form Page
 * 
 * Shows the form to execute a specific command.
 * Dynamically generates form fields based on help.php documentation.
 * 
 * @version 1.6.0
 */

// $selectedCommand is already set from command.php
// The documentation is fetched by command-form.js from the `help` command.
?>

<div class="admin-command-form-page"
     data-command-name="<?= adminAttr($selectedCommand) ?>">



<div class="admin-page-header">
    <div class="admin-breadcrumb">
        <a href="<?= $router->url('command') ?>" class="admin-breadcrumb__link" id="breadcrumb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            <?= __admin('commands.title') ?>
        </a>
    </div>
    <h1 class="admin-page-header__title">
        <code><?= adminEscape($selectedCommand) ?></code>
    </h1>
    <p class="admin-page-header__subtitle" id="command-description">
        <?= __admin('common.loading') ?>
    </p>
</div>

<div class="admin-grid admin-grid--cols-2">
    <!-- Command Form -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= __admin('commands.execute') ?></h2>
            <p class="admin-card__subtitle" id="command-method">
                <span class="badge" id="method-badge">...</span>
            </p>
        </div>
        <div class="admin-card__body">
            <form id="command-form" class="admin-command-form" data-command="<?= adminAttr($selectedCommand) ?>">
                <div id="command-params">
                    <div class="admin-loading">
                        <span class="admin-spinner"></span>
                        <span><?= __admin('common.loading') ?></span>
                    </div>
                </div>
                
                <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg" id="submit-btn">
                        <?= __admin('commands.execute') ?>
                    </button>
                    <button type="reset" class="admin-btn admin-btn--outline">
                        <?= __admin('common.reset') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Documentation Panel -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= __admin('commands.viewDocs') ?></h2>
        </div>
        <div class="admin-card__body" id="command-docs">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Response Area -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title"><?= __admin('commands.response') ?></h2>
    </div>
    <div class="admin-card__body" id="command-response">
        <div class="admin-empty" style="padding: var(--space-lg);">
            <p><?= __admin('commands.tryIt') ?></p>
        </div>
    </div>
</div>

<?php
// The strings command-form.js needs, emitted under the SAME dot-paths PHP uses.
// Whole sub-trees, verbatim — a hand-copied mirror that renames levels while it
// copies is how the panel ended up with JS asking for paths nothing supplies.
// JSON_HEX_TAG is what stops a translation value closing this script element.
$qsCommandFormI18n = [];
foreach (['commandForm', 'commands', 'common'] as $qsSubtree) {
    $qsCommandFormI18n[$qsSubtree] = AdminTranslation::getInstance()->getRaw($qsSubtree) ?: new stdClass();
}

// Installation settings that decide whether a listed command could run here AT
// ALL. Not authorization — permissions do that server-side, per call — but the
// difference between a command this installation offers and one it is switched
// off for, which the operator otherwise discovers only from a 403.
//
// The row stays listed and documented either way: only the SUBMIT closes, the
// same treatment login and logoutSession get. A console that hid a command would
// stop being a complete view of the API and become a second source of truth
// about what exists.
// BOTH, and in this order. qs_registration_config() lives in SessionManagement
// but reads loadAuthConfig(), which lives in AuthManagement — requiring only the
// first fatals the page wherever the router has not already pulled the second
// in. members.php requires what it uses for the same reason.
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SessionManagement.php';
$qsConsoleFlags = [
    'selfRegistration' => (bool)qs_registration_config()['allow_self_registration'],
];
?>
<script>
    window.QS_COMMAND_FORM_I18N = <?= json_encode($qsCommandFormI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.QS_CONSOLE_FLAGS = <?= json_encode($qsConsoleFlags, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/command-form.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/command-form.js') ?>"></script>

</div> <!-- .admin-command-form-page -->

