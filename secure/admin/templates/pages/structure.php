<?php
/**
 * Admin Structure Viewer Page
 * 
 * Visual tree view of page/component structures.
 * 
 * @version 1.7.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script>
// Page config for structure.js
window.QUICKSITE_CONFIG = window.QUICKSITE_CONFIG || {};
window.QUICKSITE_CONFIG.commandUrl = '<?= $router->url('command') ?>';
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/structure.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/structure.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('structure.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('structure.subtitle') ?></p>
</div>

<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <?= __admin('structure.selectStructure') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-grid admin-grid--cols-3">
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('structure.label.type') ?></label>
                <select id="structure-type" class="admin-select">
                    <option value=""><?= __admin('structure.select.type') ?></option>
                    <option value="page"><?= __admin('structure.type.page') ?></option>
                    <option value="menu"><?= __admin('structure.type.menu') ?></option>
                    <option value="footer"><?= __admin('structure.type.footer') ?></option>
                    <option value="component"><?= __admin('structure.type.component') ?></option>
                </select>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('structure.label.name') ?></label>
                <select id="structure-name" class="admin-select" disabled>
                    <option value=""><?= __admin('structure.select.typeFirst') ?></option>
                </select>
            </div>
            
            <div class="admin-form-group">
                <label class="admin-label">&nbsp;</label>
                <button type="button" id="load-structure" class="admin-btn admin-btn--primary" disabled>
                    <?= __admin('structure.loadStructure') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Structure Tree View -->
<div class="admin-card" style="margin-top: var(--space-lg);" id="structure-card" style="display: none;">
    <div class="admin-card__header">
        <h2 class="admin-card__title" id="structure-title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span><?= __admin('structure.tree.title') ?></span>
        </h2>
        <div class="admin-card__actions">
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="expandAll()">
                <?= __admin('structure.tree.expandAll') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="collapseAll()">
                <?= __admin('structure.tree.collapseAll') ?>
            </button>
            <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="copyStructure()">
                <?= __admin('structure.tree.copyJson') ?>
            </button>
        </div>
    </div>
    <div class="admin-card__body">
        <div id="structure-tree" class="admin-structure-tree">
            <div class="admin-empty">
                <p><?= __admin('structure.tree.empty') ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Node Details Panel -->
<div id="node-details" class="admin-node-details" style="display: none;">
    <div class="admin-node-details__header">
        <h3><?= __admin('structure.nodeDetails.title') ?></h3>
        <button type="button" class="admin-node-details__close" onclick="closeNodeDetails()">×</button>
    </div>
    <div class="admin-node-details__body" id="node-details-content">
    </div>
</div>
