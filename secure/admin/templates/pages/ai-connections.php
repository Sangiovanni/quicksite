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
$libBase = PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/lib/';
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/provider-catalog.js?v=<?= filemtime($libBase . 'provider-catalog.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/local-presets.js?v=<?= filemtime($libBase . 'local-presets.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/connections-store.js?v=<?= filemtime($libBase . 'connections-store.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/stream-parsers.js?v=<?= filemtime($libBase . 'stream-parsers.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/lib/ai-call.js?v=<?= filemtime($libBase . 'ai-call.js') ?>"></script>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/ai/ai-connections.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/ai/ai-connections.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-right:8px;">
            <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
            <circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/>
        </svg>
        AI Connections
    </h1>
    <p class="admin-page-header__subtitle">
        Cloud (BYOK) and local AI endpoints, named and managed in your browser.
        Calls go <strong>browser → provider directly</strong> — keys never touch the server.
    </p>
</div>

<div class="admin-grid admin-grid--cols-1">
    <div class="admin-card">
        <div class="admin-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-md);">
            <div>
                <h2 class="admin-card__title">Connections</h2>
                <p class="admin-text-muted" style="margin:0;">Click the dot to test in place. The starred connection is the default.</p>
            </div>
            <button type="button" class="admin-btn admin-btn--primary" id="qsac-add-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add connection
            </button>
        </div>
        <div class="admin-card__body">
            <div id="qsac-list"><!-- populated by JS --></div>
        </div>
    </div>

    <!-- Defaults & automation -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">Defaults &amp; automation</h2>
        </div>
        <div class="admin-card__body">
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-persist">
                    <span class="admin-checkbox__label">Keep cloud keys after closing the browser (localStorage)</span>
                </label>
                <p class="admin-hint">Off = cleared on tab close (sessionStorage). Local connections always persist (URLs aren't sensitive).</p>
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-auto-preview">
                    <span class="admin-checkbox__label">Auto-preview commands when valid JSON is detected</span>
                </label>
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="qsac-auto-execute">
                    <span class="admin-checkbox__label">Auto-execute commands after preview</span>
                </label>
                <p class="admin-hint"><strong>Use with caution.</strong></p>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit connection wizard modal -->
<div id="qsac-modal" class="admin-modal" style="display:none;">
    <div class="admin-modal__backdrop" data-qsac-close></div>
    <div class="admin-modal__content" style="max-width:560px;">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title" id="qsac-modal-title">Add a connection</h3>
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
