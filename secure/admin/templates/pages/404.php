<?php
/**
 * Admin 404 Page
 * 
 * Page not found template.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-empty">
    <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <path d="M16 16s-1.5-2-4-2-4 2-4 2"/>
        <line x1="9" y1="9" x2="9.01" y2="9"/>
        <line x1="15" y1="9" x2="15.01" y2="9"/>
    </svg>
    <h2 class="admin-empty__title"><?= __admin('errors.404.title') ?></h2>
    <p class="admin-empty__text"><?= __admin('errors.404.message') ?></p>
    <a href="<?= $router->url('dashboard') ?>" class="admin-btn admin-btn--primary">
        <?= __admin('errors.404.backToDashboard') ?>
    </a>
</div>
