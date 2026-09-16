<?php
/**
 * AI Connections page (v3 store).
 *
 * Browser-managed list of AI connections (cloud BYOK + local). Replaces the
 * old single-key-per-provider "AI API Keys" page. Calls go browser -> provider
 * direct via QSAiCall; this page is purely a UI over QSConnectionsStore.
 *
 * Old route: /admin/ai-settings -> 301 redirect (see AdminRouter).
 *
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');
$libBase = ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/lib/';

// The sub-trees ai-connections.js renders from, verbatim and under the same
// dot paths PHP uses, so the JS asks for the path PHP would.
$qsAiConnectionsI18n = [];
foreach (['aiConnections', 'common'] as $qsSubtree) {
    $qsAiConnectionsI18n[$qsSubtree] = AdminTranslation::getInstance()->getRaw($qsSubtree) ?: new stdClass();
}
?>

<script>
window.QSAC_ASSET_BASE = '<?= $baseUrl ?>/admin/assets';
window.QS_AI_CONNECTIONS_I18N = <?= json_encode($qsAiConnectionsI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/provider-catalog.js?v=<?= filemtime($libBase . 'provider-catalog.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/local-presets.js?v=<?= filemtime($libBase . 'local-presets.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/connections-store.js?v=<?= filemtime($libBase . 'connections-store.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/stream-parsers.js?v=<?= filemtime($libBase . 'stream-parsers.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/ai-call.js?v=<?= filemtime($libBase . 'ai-call.js') ?>"></script>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/ai-connections.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/ai/ai-connections.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-right:8px;">
            <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
            <circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/>
        </svg>
        <?= __admin('aiConnections.title') ?>
    </h1>
    <p class="admin-page-header__subtitle">
        <?= __admin('aiConnections.subtitleLead') ?>
        <?= str_replace(':direct', '<strong>' . __admin('aiConnections.subtitleDirect') . '</strong>', __admin('aiConnections.subtitleTail')) ?>
    </p>
</div>

<div class="admin-grid admin-grid--cols-1">
    <div class="admin-card">
        <div class="admin-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-md);">
            <div>
                <h2 class="admin-card__title"><?= __admin('aiConnections.connections') ?></h2>
                <p class="admin-text-muted" style="margin:0;"><?= __admin('aiConnections.connectionsHint') ?></p>
            </div>
            <button type="button" class="admin-btn admin-btn--primary" id="qsac-add-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('aiConnections.addConnection') ?>
            </button>
        </div>
        <div class="admin-card__body">
            <div id="qsac-list"><!-- populated by JS --></div>
        </div>
    </div>

    <!-- Defaults & automation -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= __admin('aiConnections.defaultsTitle') ?></h2>
        </div>
        <div class="admin-card__body">
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-persist">
                    <span class="admin-checkbox__label"><?= __admin('aiConnections.persistLabel') ?></span>
                </label>
                <p class="admin-hint"><?= __admin('aiConnections.persistHint') ?></p>
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-auto-execute">
                    <span class="admin-checkbox__label"><?= __admin('aiConnections.autoExecuteLabel') ?></span>
                </label>
                <p class="admin-hint"><?= str_replace(':caution', '<strong>' . __admin('aiConnections.autoExecuteCaution') . '</strong>', __admin('aiConnections.autoExecuteHint')) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit connection wizard modal -->
<div id="qsac-modal" class="admin-modal" style="display:none;">
    <div class="admin-modal__backdrop" data-qsac-close></div>
    <div class="admin-modal__content" style="max-width:560px;">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title" id="qsac-modal-title"><?= __admin('aiConnections.modalTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-qsac-close>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal__body" id="qsac-modal-body">
            <!-- step content rendered by JS -->
        </div>
        <div class="admin-modal__footer" id="qsac-modal-footer">
            <!-- buttons rendered by JS -->
        </div>
    </div>
</div>
