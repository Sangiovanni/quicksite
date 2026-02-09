<?php
/**
 * Admin Dashboard Page
 * 
 * Main admin panel landing page after login.
 * Shows site stats, site map, and recent commands.
 * 
 * @version 2.0.0 - External JS/CSS
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/dashboard.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/dashboard.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('dashboard.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('dashboard.subtitle') ?></p>
</div>

<!-- Storage Overview -->
<section class="admin-section">
    <div class="storage-overview" id="storage-overview">
        <div class="storage-overview__header">
            <h3 class="storage-overview__title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M22 12H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>
                </svg>
                <?= __admin('dashboard.storage.title') ?>
            </h3>
            <span class="storage-overview__total" id="storage-total">--</span>
        </div>
        <div class="storage-overview__bar" id="storage-bar">
            <div class="storage-overview__segment storage-overview__segment--projects" id="storage-seg-projects" style="width: 0%"></div>
            <div class="storage-overview__segment storage-overview__segment--backups" id="storage-seg-backups" style="width: 0%"></div>
            <div class="storage-overview__segment storage-overview__segment--admin" id="storage-seg-admin" style="width: 0%"></div>
            <div class="storage-overview__segment storage-overview__segment--other" id="storage-seg-other" style="width: 0%"></div>
        </div>
        <div class="storage-overview__legend">
            <div class="storage-overview__legend-item">
                <span class="storage-overview__legend-color storage-overview__legend-color--projects"></span>
                <span class="storage-overview__legend-label"><?= __admin('dashboard.storage.projects') ?></span>
                <span class="storage-overview__legend-value" id="storage-val-projects">--</span>
            </div>
            <div class="storage-overview__legend-item">
                <span class="storage-overview__legend-color storage-overview__legend-color--backups"></span>
                <span class="storage-overview__legend-label"><?= __admin('dashboard.storage.backups') ?></span>
                <span class="storage-overview__legend-value" id="storage-val-backups">--</span>
            </div>
            <div class="storage-overview__legend-item">
                <span class="storage-overview__legend-color storage-overview__legend-color--admin"></span>
                <span class="storage-overview__legend-label"><?= __admin('dashboard.storage.admin') ?></span>
                <span class="storage-overview__legend-value" id="storage-val-admin">--</span>
            </div>
            <div class="storage-overview__legend-item">
                <span class="storage-overview__legend-color storage-overview__legend-color--other"></span>
                <span class="storage-overview__legend-label"><?= __admin('dashboard.storage.other') ?></span>
                <span class="storage-overview__legend-value" id="storage-val-other">--</span>
            </div>
        </div>
    </div>
</section>

<!-- Project Manager -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.projects.title') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <div class="project-manager">
                <!-- Current Project Info -->
                <div class="project-manager__current" id="current-project-info">
                    <div class="admin-loading">
                        <span class="admin-spinner"></span>
                        <span><?= __admin('common.loading') ?></span>
                    </div>
                </div>
                
                <!-- Project Actions -->
                <div class="project-manager__actions">
                    <!-- Switch Project -->
                    <div class="project-manager__action-group">
                        <label class="admin-label"><?= __admin('dashboard.projects.switch') ?></label>
                        <div class="project-manager__select-row">
                            <select id="project-selector" class="admin-input admin-input--select" disabled>
                                <option value=""><?= __admin('common.loading') ?></option>
                            </select>
                            <button type="button" id="btn-switch-project" class="admin-btn admin-btn--primary" disabled>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                </svg>
                                <?= __admin('dashboard.projects.switchBtn') ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Actions Row -->
                    <div class="project-manager__quick-actions">
                        <!-- Create Project -->
                        <button type="button" id="btn-create-project" class="admin-btn admin-btn--ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                                <line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>
                            </svg>
                            <?= __admin('dashboard.projects.create') ?>
                        </button>
                        
                        <!-- Backup/Restore Group -->
                        <div class="project-manager__action-divider"></div>
                        
                        <!-- Backup Project -->
                        <button type="button" id="btn-backup-project" class="admin-btn admin-btn--ghost" title="<?= __admin('dashboard.projects.backupTooltip') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                            </svg>
                            <?= __admin('dashboard.projects.backup') ?>
                        </button>
                        
                        <!-- Restore Backup -->
                        <button type="button" id="btn-restore-backup" class="admin-btn admin-btn--ghost" title="<?= __admin('dashboard.projects.restoreTooltip') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                            </svg>
                            <?= __admin('dashboard.projects.restore') ?>
                        </button>
                        
                        <!-- Export/Import Group -->
                        <div class="project-manager__action-divider"></div>
                        
                        <!-- Export Project -->
                        <button type="button" id="btn-export-project" class="admin-btn admin-btn--ghost" title="<?= __admin('dashboard.projects.exportTooltip') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <?= __admin('dashboard.projects.export') ?>
                        </button>
                        
                        <!-- Import Project -->
                        <button type="button" id="btn-import-project" class="admin-btn admin-btn--ghost" title="<?= __admin('dashboard.projects.importTooltip') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <?= __admin('dashboard.projects.import') ?>
                        </button>
                        
                        <!-- Danger Zone -->
                        <div class="project-manager__action-divider"></div>
                        
                        <!-- Delete Project -->
                        <button type="button" id="btn-delete-project" class="admin-btn admin-btn--ghost admin-btn--danger">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                            <?= __admin('dashboard.projects.delete') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Hidden file input for import -->
<input type="file" id="import-file-input" accept=".zip" style="display: none;">

<!-- Restore Backup Modal -->
<div id="modal-restore-backup" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content admin-modal__content--wide">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.restoreTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <div id="backup-list-container">
                <div class="admin-loading">
                    <span class="admin-spinner"></span>
                    <span><?= __admin('common.loading') ?></span>
                </div>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.close') ?></button>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div id="modal-restore-confirm" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.confirmRestore') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <p style="margin-bottom: var(--space-md);">
                <?= __admin('dashboard.projects.restoreFrom') ?>: <strong id="restore-backup-name"></strong>
            </p>
            
            <div class="admin-alert admin-alert--warning" style="margin-bottom: var(--space-md);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <div>
                    <strong><?= __admin('dashboard.projects.restoreWarningTitle') ?></strong>
                    <p id="restore-warning-text" style="margin: 0.25rem 0 0 0; font-size: 0.875rem;"></p>
                </div>
            </div>
            
            <label class="admin-checkbox-group" style="cursor: pointer;">
                <input type="checkbox" id="restore-create-backup" class="admin-checkbox">
                <span class="admin-checkbox-label"><?= __admin('dashboard.projects.createBackupBeforeRestore') ?></span>
            </label>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" id="btn-confirm-restore" class="admin-btn admin-btn--primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                </svg>
                <?= __admin('dashboard.projects.restoreBtn') ?>
            </button>
        </div>
    </div>
</div>

<!-- Create Project Modal -->
<div id="modal-create-project" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.createTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('dashboard.projects.nameLabel') ?></label>
                <input type="text" id="create-project-name" class="admin-input" placeholder="my-new-site" pattern="[a-z0-9_\-]+" />
                <small class="admin-help"><?= __admin('dashboard.projects.nameHelp') ?></small>
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="create-project-activate" checked />
                    <span><?= __admin('dashboard.projects.activateAfterCreate') ?></span>
                </label>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" id="btn-confirm-create" class="admin-btn admin-btn--primary"><?= __admin('dashboard.projects.createBtn') ?></button>
        </div>
    </div>
</div>

<!-- Delete Project Modal -->
<div id="modal-delete-project" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.deleteTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <p class="admin-warning"><?= __admin('dashboard.projects.deleteWarning') ?></p>
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('dashboard.projects.selectToDelete') ?></label>
                <select id="delete-project-selector" class="admin-input admin-input--select">
                    <option value=""><?= __admin('dashboard.projects.selectProject') ?></option>
                </select>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" id="btn-confirm-delete" class="admin-btn admin-btn--danger" disabled><?= __admin('dashboard.projects.deleteBtn') ?></button>
        </div>
    </div>
</div>

<!-- Quick Stats (loaded via AJAX) -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.stats.title') ?></h2>
    <div class="admin-grid admin-grid--cols-4" id="admin-stats">
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-routes">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.routes') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-pages">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.pages') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-components">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.components') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-languages">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.languages') ?></div>
        </div>
    </div>
</section>

<!-- Site Map -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.sitemap.title') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-container">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
    </div>
</section>

<!-- Structure Viewer (Collapsible) -->
<section class="admin-section">
    <div class="admin-card">
        <div class="admin-card__header admin-card__header--collapsible" id="structure-panel-header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <?= __admin('dashboard.structure.title') ?>
            </h2>
            <svg class="admin-card__toggle" id="structure-panel-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </div>
        <div class="admin-card__body" id="structure-panel-body" style="display: none;">
            <!-- Structure Selector -->
            <div class="admin-grid admin-grid--cols-3" style="margin-bottom: var(--space-md);">
                <div class="admin-form-group">
                    <label class="admin-label"><?= __admin('structure.label.type') ?></label>
                    <select id="dash-structure-type" class="admin-select">
                        <option value=""><?= __admin('structure.select.type') ?></option>
                        <option value="page"><?= __admin('structure.type.page') ?></option>
                        <option value="menu"><?= __admin('structure.type.menu') ?></option>
                        <option value="footer"><?= __admin('structure.type.footer') ?></option>
                        <option value="component"><?= __admin('structure.type.component') ?></option>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label"><?= __admin('structure.label.name') ?></label>
                    <select id="dash-structure-name" class="admin-select" disabled>
                        <option value=""><?= __admin('structure.select.typeFirst') ?></option>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">&nbsp;</label>
                    <button type="button" id="dash-load-structure" class="admin-btn admin-btn--primary" disabled>
                        <?= __admin('structure.loadStructure') ?>
                    </button>
                </div>
            </div>
            
            <!-- Structure Tree -->
            <div id="dash-structure-tree" class="admin-structure-tree">
                <div class="admin-empty">
                    <p><?= __admin('structure.tree.empty') ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Recent Command History -->
<section class="admin-section">
    <div class="admin-section__header">
        <h2 class="admin-section__title"><?= __admin('dashboard.recentCommands') ?></h2>
        <a href="<?= $router->url('command') ?>?tab=history" class="admin-btn admin-btn--ghost">
            <?= __admin('dashboard.viewAllHistory') ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </a>
    </div>
    <div class="admin-card">
        <div class="admin-card__body" id="recent-commands">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
    </div>
</section>

