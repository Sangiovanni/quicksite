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

// Commands that change URL structure - need special warning
$urlChangingCommands = ['setPublicSpace']; // Changes admin URL, auto-redirect
$serverConfigCommands = ['renamePublicFolder']; // Requires server config change

$isUrlChanging = in_array($selectedCommand, $urlChangingCommands);
$isServerConfig = in_array($selectedCommand, $serverConfigCommands);

// Load command documentation
$commandDoc = null;
$helpPath = SECURE_FOLDER_PATH . '/management/command/help.php';

// We need to fetch the command info - let's create a helper function
function getCommandDocumentation(string $command): ?array {
    // We'll fetch from the API
    $apiUrl = BASE_URL . '/management/help/' . urlencode($command);
    
    // Since we're server-side, we can include the help file directly
    // But it needs the trimParametersManagement context, so let's read from JSON cache if available
    // For now, we'll use a simplified approach
    
    $helpFile = SECURE_FOLDER_PATH . '/management/command/help.php';
    if (!file_exists($helpFile)) {
        return null;
    }
    
    // Extract commands array from help.php (this is a bit hacky but works)
    $content = file_get_contents($helpFile);
    
    // We can't easily parse PHP, so let's use a different approach
    // Check if we have the command in our static mapping
    return null; // Will be loaded via AJAX
}
?>

<div class="admin-command-form-page"
     data-command-name="<?= adminAttr($selectedCommand) ?>"
     data-batch-url="<?= $router->url('batch') ?>"
     data-t-no-parameters="<?= adminAttr(__admin('commands.noParameters')) ?>"
     data-t-required-params="<?= adminAttr(__admin('commands.requiredParams')) ?>"
     data-t-optional-params="<?= adminAttr(__admin('commands.optionalParams')) ?>"
     data-t-notes="<?= adminAttr(__admin('commands.notes')) ?>"
     data-t-example="<?= adminAttr(__admin('commands.example')) ?>"
     data-t-success-response="<?= adminAttr(__admin('commands.successResponse')) ?>"
     data-t-error-responses="<?= adminAttr(__admin('commands.errorResponses')) ?>">



<?php if ($isUrlChanging): ?>
<div class="admin-alert admin-alert--warning" style="margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <div>
        <strong>⚠️ <?= __admin('commandForm.warning.urlChange.title') ?></strong>
        <p style="margin: var(--space-xs) 0 0;">
            <?= __admin('commandForm.warning.urlChange.message') ?>
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($isServerConfig): ?>
<div class="admin-alert admin-alert--error" style="margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <div>
        <strong>🚨 <?= __admin('commandForm.warning.serverConfig.title') ?></strong>
        <p style="margin: var(--space-xs) 0 0;">
            <strong><?= __admin('commandForm.warning.serverConfig.advanced') ?></strong> <?= __admin('commandForm.warning.serverConfig.message') ?>
        </p>
        <p style="margin: var(--space-xs) 0 0; font-size: var(--font-size-sm); opacity: 0.9;">
            <?= __admin('commandForm.warning.serverConfig.useCase') ?>
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Batch Mode Banner (shown via JavaScript when ?batch=1) -->
<div class="admin-alert admin-alert--info" id="batch-mode-banner" style="display: none; margin-bottom: var(--space-lg);">
    <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="8" y1="6" x2="21" y2="6"/>
        <line x1="8" y1="12" x2="21" y2="12"/>
        <line x1="8" y1="18" x2="21" y2="18"/>
        <line x1="3" y1="6" x2="3.01" y2="6"/>
        <line x1="3" y1="12" x2="3.01" y2="12"/>
        <line x1="3" y1="18" x2="3.01" y2="18"/>
    </svg>
    <div>
        <strong>📋 <?= __admin('commandForm.batchMode.title') ?></strong>
        <p style="margin: var(--space-xs) 0 0;">
            <?= __admin('commandForm.batchMode.message') ?>
        </p>
    </div>
</div>

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
        <?php if ($isUrlChanging): ?>
        <span class="badge badge--warning" style="margin-left: var(--space-sm); font-size: var(--font-size-xs);">
            <?= __admin('commandForm.badge.changesUrls') ?>
        </span>
        <?php endif; ?>
        <?php if ($isServerConfig): ?>
        <span class="badge badge--error" style="margin-left: var(--space-sm); font-size: var(--font-size-xs);">
            ⚠️ <?= __admin('commandForm.badge.serverConfigRequired') ?>
        </span>
        <?php endif; ?>
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
                    <a href="<?= $router->url('batch') ?>" class="admin-btn admin-btn--secondary" id="cancel-batch-btn" style="display: none;">
                        Cancel
                    </a>
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

<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/command-form.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/command-form.js') ?>"></script>

</div> <!-- .admin-command-form-page -->

