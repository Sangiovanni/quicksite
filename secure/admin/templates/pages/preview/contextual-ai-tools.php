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

        <!-- Each switch keeps only the rows it names; neither is remembered -->
        <div class="preview-contextual-ai-tools__switches">
            <label class="preview-contextual-ai-tools__switch">
                <input type="checkbox" id="ai-tools-switch-no-ai" autocomplete="off">
                <span><?= __admin('preview.workflowsSwitchNoAi', 'No AI') ?></span>
            </label>
            <label class="preview-contextual-ai-tools__switch">
                <input type="checkbox" id="ai-tools-switch-nothing-deleted" autocomplete="off">
                <span><?= __admin('preview.workflowsSwitchNothingDeleted', 'Nothing deleted') ?></span>
            </label>
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
        </div>
    </div>

    <!-- Runner view (populated when a workflow is picked) -->
    <div class="preview-contextual-ai-tools__runner-view" id="ai-tools-runner-view" style="display: none;">
    </div>
</div>
