<div class="preview-main">
    <!-- Component Warning Banner (shown when editing a component) -->
    <div class="preview-component-warning" id="preview-component-warning" style="display: none;">
        <svg class="preview-component-warning__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span class="preview-component-warning__text" id="preview-component-warning-text"></span>
    </div>

    <!-- Iframe Warning Banner (shown when page contains iframe nodes) -->
    <div class="preview-iframe-warning" id="preview-iframe-warning" style="display: none;">
        <svg class="preview-iframe-warning__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <span class="preview-iframe-warning__text">
            <?= __admin('preview.iframeWarningText') ?>
        </span>
        <a href="<?= $router->url('embed-security') ?>" class="preview-iframe-warning__link"><?= __admin('preview.iframeWarningLink') ?> &rarr;</a>
        <button type="button" class="preview-iframe-warning__close" id="preview-iframe-warning-close" title="<?= __admin('preview.iframeWarningDismiss') ?>">&times;</button>
    </div>

    <!-- Source canvas (full-CSS code editor host). Hidden by default;
         preview.js shows it (and hides #preview-container) when Source is
         active inside Style mode. The editor mounts into
         #preview-source-canvas-mount on first activation. -->
    <div class="preview-source-canvas" id="preview-source-canvas" style="display: none;">
        <div class="preview-source-canvas__toolbar">
            <div class="preview-source-canvas__file-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <polyline points="16 18 22 12 16 6"/>
                    <polyline points="8 6 2 12 8 18"/>
                </svg>
                <code id="preview-source-canvas-file">style.css</code>
            </div>
            <!-- Search bar. Always visible. Empty input = no
                 search active. ':42' + Enter jumps to line 42; otherwise the
                 query is a plain case-insensitive substring search. -->
            <div class="preview-source-canvas__search" id="preview-source-search">
                <div class="preview-source-canvas__search-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text"
                           id="preview-source-search-input"
                           class="preview-source-canvas__search-input"
                           placeholder="<?= __admin('preview.styleSourceFindPlaceholder', 'Find… (type :N to jump to line N)') ?>"
                           autocomplete="off"
                           spellcheck="false">
                </div>
                <span class="preview-source-canvas__search-count" id="preview-source-search-count" aria-live="polite"></span>
                <button type="button"
                        id="preview-source-search-prev"
                        class="preview-source-canvas__search-btn"
                        title="<?= __admin('preview.styleSourceFindPrev', 'Previous match (Shift+Enter)') ?>"
                        disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="18 15 12 9 6 15"/>
                    </svg>
                </button>
                <button type="button"
                        id="preview-source-search-next"
                        class="preview-source-canvas__search-btn"
                        title="<?= __admin('preview.styleSourceFindNext', 'Next match (Enter)') ?>"
                        disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
            </div>
        </div>
        <!-- Restore-draft banner. Shown when Source mounts if this project has a
             localStorage draft that differs from the server content. JS sets the
             detail text (when the draft was made, and whether style.css has
             changed since) + wires the buttons. -->
        <div class="preview-source-canvas__restore-banner" id="preview-source-restore-banner" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/>
                <polyline points="3 3 3 8 8 8"/>
            </svg>
            <div class="preview-source-canvas__restore-text">
                <span class="preview-source-canvas__restore-title"><?= __admin('preview.styleSourceRestoreTitle', 'Unsaved draft available') ?></span>
                <span class="preview-source-canvas__restore-detail" id="preview-source-restore-detail"></span>
            </div>
            <div class="preview-source-canvas__restore-actions">
                <button type="button"
                        id="preview-source-restore-accept"
                        class="admin-btn admin-btn--sm admin-btn--primary">
                    <?= __admin('preview.styleSourceRestoreAccept', 'Restore') ?>
                </button>
                <button type="button"
                        id="preview-source-restore-decline"
                        class="admin-btn admin-btn--sm admin-btn--ghost">
                    <?= __admin('preview.styleSourceRestoreDecline', 'Discard') ?>
                </button>
            </div>
        </div>
        <!-- Conflict notice, beside the restore banner and in its look. Shown while
             Source holds unsaved edits on a version of style.css that has changed
             since (saved from another tab, window or person): found when Source
             opens, or when Save reads the file first. Save waits for a choice
             here. JS shows it and wires the buttons. -->
        <div class="preview-source-canvas__restore-banner" id="preview-source-conflict-notice" role="status" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div class="preview-source-canvas__restore-text">
                <span class="preview-source-canvas__restore-title"><?= __admin('preview.styleSourceConflictTitle') ?></span>
                <span class="preview-source-canvas__restore-detail"><?= __admin('preview.styleSourceConflictDetail') ?></span>
            </div>
            <div class="preview-source-canvas__restore-actions">
                <button type="button"
                        id="preview-source-conflict-reload"
                        class="admin-btn admin-btn--sm admin-btn--primary"
                        title="<?= __admin('preview.styleSourceConflictReloadHint') ?>">
                    <?= __admin('preview.styleSourceConflictReload') ?>
                </button>
                <button type="button"
                        id="preview-source-conflict-overwrite"
                        class="admin-btn admin-btn--sm admin-btn--ghost"
                        title="<?= __admin('preview.styleSourceConflictOverwriteHint') ?>">
                    <?= __admin('preview.styleSourceConflictOverwrite') ?>
                </button>
            </div>
        </div>
        <div class="preview-source-canvas__mount" id="preview-source-canvas-mount">
            <!-- QSCodeEditor mounts here on first Source activation. -->
            <div class="preview-source-canvas__loading" id="preview-source-canvas-loading">
                <svg class="preview-theme-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                </svg>
                <span><?= __admin('preview.styleSourceLoading', 'Loading style.css…') ?></span>
            </div>
        </div>
    </div>

    <!-- Preview Frame Container -->
    <div class="preview-container" id="preview-container">
        <!-- Miniplayer floating controls (visible only in miniplayer mode) -->
        <div class="preview-miniplayer-controls" id="preview-miniplayer-controls">
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-reload" title="<?= __admin('preview.reload') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </button>
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-expand" title="<?= __admin('preview.expand') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 3 21 3 21 9"/>
                    <polyline points="9 21 3 21 3 15"/>
                    <line x1="21" y1="3" x2="14" y2="10"/>
                    <line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
            </button>
            <button type="button" class="preview-miniplayer-controls__btn" id="miniplayer-close" title="<?= __admin('common.close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="preview-frame-wrapper" id="preview-frame-wrapper">
            <iframe 
                id="preview-iframe"
                class="preview-iframe"
                src="<?= $siteUrl ?>"
                title="<?= __admin('preview.iframeTitle') ?>"
            ></iframe>
            
            <!-- Loading Overlay -->
            <div class="preview-loading" id="preview-loading">
                <div class="preview-loading__spinner"></div>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>

<!-- Device Frame (for tablet/mobile) -->
        <div class="preview-device-frame" id="preview-device-frame"></div>
    </div>
</div>
