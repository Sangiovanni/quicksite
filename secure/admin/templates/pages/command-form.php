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
     data-t-no-parameters="<?= adminAttr(__admin('commands.noParameters')) ?>"
     data-t-required-params="<?= adminAttr(__admin('commands.requiredParams')) ?>"
     data-t-optional-params="<?= adminAttr(__admin('commands.optionalParams')) ?>"
     data-t-notes="<?= adminAttr(__admin('commands.notes')) ?>"
     data-t-example="<?= adminAttr(__admin('commands.example')) ?>"
     data-t-success-response="<?= adminAttr(__admin('commands.successResponse')) ?>"
     data-t-error-responses="<?= adminAttr(__admin('commands.errorResponses')) ?>">



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

<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/command-form.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/command-form.js') ?>"></script>

</div> <!-- .admin-command-form-page -->

