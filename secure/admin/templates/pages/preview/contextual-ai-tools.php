<!-- AI TOOLS MODE Content -->
<div class="preview-contextual-section preview-contextual-section--ai-tools" id="contextual-ai-tools" data-mode="ai-tools" style="display: none;">

    <!-- List view (default; switches to runner view when a tool is picked) -->
    <div class="preview-contextual-ai-tools__list-view" id="ai-tools-list-view">

        <div class="preview-contextual-ai-tools__backup-banner" role="alert">
            <div class="preview-contextual-ai-tools__backup-banner-text">
                <strong><?= __admin('preview.aiToolsBackupWarning', 'AI tools modify your site directly. Changes cannot be easily reverted.') ?></strong>
                <span><?= __admin('preview.aiToolsBackupRecommendation', "We recommend creating a backup before running any workflow you're not sure about.") ?></span>
            </div>
            <button type="button" class="preview-contextual-ai-tools__backup-btn" id="ai-tools-backup-btn">
                <?= __admin('preview.aiToolsBackupBtn', 'Create backup now') ?>
            </button>
        </div>

        <div class="preview-contextual-ai-tools__search-row">
            <input type="search" class="preview-contextual-ai-tools__search" id="ai-tools-search"
                   placeholder="<?= __admin('preview.aiToolsSearchPlaceholder', 'Search AI tools…') ?>"
                   autocomplete="off">
        </div>

        <div class="preview-contextual-ai-tools__tag-mode-row" id="ai-tools-tag-mode-row" style="display: none;">
            <span class="preview-contextual-ai-tools__tag-mode-label">
                <?= __admin('preview.aiToolsMatchLabel', 'Match:') ?>
            </span>
            <button type="button" class="preview-contextual-ai-tools__tag-mode preview-contextual-ai-tools__tag-mode--active"
                    id="ai-tools-tag-mode-any" data-mode="any">
                <?= __admin('preview.aiToolsMatchAny', 'Any') ?>
            </button>
            <button type="button" class="preview-contextual-ai-tools__tag-mode"
                    id="ai-tools-tag-mode-all" data-mode="all">
                <?= __admin('preview.aiToolsMatchAll', 'All') ?>
            </button>
        </div>

        <div class="preview-contextual-ai-tools__tags" id="ai-tools-tags">
            <!-- Tag chips inserted by JS -->
        </div>

        <div class="preview-contextual-ai-tools__section preview-contextual-ai-tools__section--all" id="ai-tools-all-section">
            <div class="preview-contextual-ai-tools__categories" id="ai-tools-all-list">
                <div class="preview-contextual-ai-tools__loading" id="ai-tools-loading">
                    <?= __admin('preview.aiToolsLoading', 'Loading…') ?>
                </div>
            </div>
            <button type="button" class="preview-contextual-ai-tools__show-more" id="ai-tools-show-more" style="display: none;">
                <?= __admin('preview.aiToolsShowMore', 'Show more') ?>
            </button>
            <div class="preview-contextual-ai-tools__empty" id="ai-tools-empty" style="display: none;">
                <?= __admin('preview.aiToolsEmpty', 'No tools match the current filters.') ?>
            </div>
        </div>
    </div>

    <!-- Runner view (populated in A4 when a tool is picked) -->
    <div class="preview-contextual-ai-tools__runner-view" id="ai-tools-runner-view" style="display: none;">
    </div>
</div>
