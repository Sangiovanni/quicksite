<!-- WORKFLOWS MODE Content (mode ai-tools: the ids and file names keep the panel's first name, AI tools) -->
<div class="preview-contextual-section" id="contextual-ai-tools" data-mode="ai-tools" style="display: none;">

    <!-- List view (default; switches to runner view when a workflow is picked) -->
    <div class="preview-contextual-ai-tools__list-view" id="ai-tools-list-view">

        <div class="preview-contextual-ai-tools__backup-banner" id="ai-tools-backup-banner" role="alert">
            <div class="preview-contextual-ai-tools__backup-banner-text">
                <strong><?= __admin('preview.aiToolsBackupWarning', 'Workflows modify your site directly. Changes cannot be easily reverted.') ?></strong>
                <span><?= __admin('preview.aiToolsBackupRecommendation', "We recommend creating a backup before running any workflow you're not sure about.") ?></span>
            </div>
            <div class="preview-contextual-ai-tools__backup-actions">
                <button type="button" class="preview-contextual-ai-tools__backup-btn" id="ai-tools-backup-btn">
                    <?= __admin('preview.aiToolsBackupBtn', 'Create backup now') ?>
                </button>
                <button type="button" class="preview-contextual-ai-tools__backup-hide" id="ai-tools-backup-hide">
                    <?= __admin('preview.aiToolsBackupHide', "Don't show this message again") ?>
                </button>
            </div>
        </div>

        <div class="preview-contextual-ai-tools__search-row">
            <input type="search" class="preview-contextual-ai-tools__search" id="ai-tools-search"
                   placeholder="<?= __admin('preview.aiToolsSearchPlaceholder', 'Search workflows…') ?>"
                   autocomplete="off">
        </div>

        <!-- Options, closed by default. Its line says which switches are on and how many rows they hide (the script writes it).
             The two switches keep only the rows they name; the last box, offered only to a role that cannot run some workflow,
             shows those greyed. All four are kept in this browser by the script (autocomplete="off": the browser's own form
             memory never brings one back) -->
        <div class="preview-contextual-ai-tools__options">
            <button type="button" class="preview-contextual-ai-tools__options-toggle" id="ai-tools-options-toggle"
                    aria-expanded="false" aria-controls="ai-tools-options-panel">
                <span class="preview-contextual-ai-tools__options-title"><?= __admin('preview.workflowsOptions', 'Options') ?></span><span class="preview-contextual-ai-tools__options-summary" id="ai-tools-options-summary"></span>
            </button>
            <div class="preview-contextual-ai-tools__switches" id="ai-tools-options-panel" hidden>
                <label class="preview-contextual-ai-tools__switch">
                    <input type="checkbox" id="ai-tools-switch-no-ai" autocomplete="off">
                    <span><?= __admin('preview.workflowsSwitchNoAi', 'No AI') ?></span>
                </label>
                <label class="preview-contextual-ai-tools__switch">
                    <input type="checkbox" id="ai-tools-switch-nothing-deleted" autocomplete="off">
                    <span><?= __admin('preview.workflowsSwitchNothingDeleted', 'Nothing deleted') ?></span>
                </label>
                <label class="preview-contextual-ai-tools__switch">
                    <input type="checkbox" id="ai-tools-switch-compact" autocomplete="off">
                    <span><?= __admin('preview.workflowsSwitchCompact', 'Compact view') ?></span>
                </label>
                <label class="preview-contextual-ai-tools__switch" id="ai-tools-switch-unrunnable-row" hidden>
                    <input type="checkbox" id="ai-tools-switch-unrunnable" autocomplete="off">
                    <span><?= __admin('preview.workflowsSwitchUnrunnable', "Show workflows my role can't run") ?></span>
                </label>
            </div>
        </div>

        <div class="preview-contextual-ai-tools__section" id="ai-tools-all-section">
            <div class="preview-contextual-ai-tools__categories" id="ai-tools-all-list">
                <div class="preview-contextual-ai-tools__loading" id="ai-tools-loading">
                    <?= __admin('preview.aiToolsLoading', 'Loading…') ?>
                </div>
            </div>
            <div class="preview-contextual-ai-tools__empty" id="ai-tools-empty" style="display: none;">
                <?= __admin('preview.aiToolsEmpty', 'No workflow matches your search and switches.') ?>
            </div>
            <!-- The switches hide every row the search keeps: the list says so, and Show all turns them off -->
            <div class="preview-contextual-ai-tools__empty" id="ai-tools-options-empty" hidden>
                <p class="preview-contextual-ai-tools__empty-text"><?= __admin('preview.workflowsOptionsHideAll', 'Your options hide every workflow.') ?></p>
                <button type="button" class="admin-btn admin-btn--outline" id="ai-tools-show-all"><?= __admin('preview.workflowsShowAll', 'Show all') ?></button>
            </div>
        </div>
    </div>

    <!-- Runner view (populated when a workflow is picked) -->
    <div class="preview-contextual-ai-tools__runner-view" id="ai-tools-runner-view" style="display: none;">
    </div>
</div>
