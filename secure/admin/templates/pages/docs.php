<?php
/**
 * Admin API Documentation Page
 * 
 * Displays API documentation from the help command.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('docs.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('docs.subtitle') ?></p>
</div>

<!-- Quick Reference -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <?= __admin('docs.quickReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-docs-quick">
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.apiBaseUrl') ?></h3>
                <code class="admin-docs-item__code"><?= rtrim(BASE_URL, '/') ?>/management</code>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.authentication') ?></h3>
                <p><?= __admin('docs.quickRef.includeTokenVia') ?></p>
                <ul class="admin-docs-list">
                    <li><?= __admin('docs.quickRef.header') ?> <code>X-Auth-Token: your_token</code></li>
                    <li><?= __admin('docs.quickRef.query') ?> <code>?token=your_token</code></li>
                    <li><?= __admin('docs.quickRef.postBody') ?> <code>{"token": "your_token"}</code></li>
                </ul>
            </div>
            
            <div class="admin-docs-item">
                <h3 class="admin-docs-item__title"><?= __admin('docs.quickRef.responseFormat') ?></h3>
                <pre class="admin-docs-pre">{
  "success": true|false,
  "data": {...} | null,
  "message": "Optional message"
}</pre>
            </div>
        </div>
    </div>
</div>

<!-- Command Search -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <?= __admin('docs.commandReference') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <input type="text" id="docs-search" class="admin-input" 
                   placeholder="<?= __admin('docs.searchPlaceholder') ?>">
        </div>
        
        <div id="docs-loading" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('docs.loading') ?>
        </div>
        
        <div id="docs-container" class="admin-docs-commands" style="display: none;"></div>
    </div>
</div>

<!-- Page-specific JavaScript -->
<script>
    // Pass PHP values to JavaScript
    window.QuickSiteDocsConfig = {
        commandUrl: '<?= $router->url('command') ?>',
        baseUrl: '<?= rtrim(BASE_URL, '/') ?>'
    };
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/docs.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/docs.js') ?>"></script>
