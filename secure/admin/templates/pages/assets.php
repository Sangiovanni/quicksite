<?php
/**
 * Admin Asset Management Page
 * 
 * Dedicated page for managing project assets: upload, browse, edit, delete.
 * Multi-file upload, visual grid with previews, inline rename, batch delete.
 * 
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/assets.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/assets.js') ?>"></script>

<div class="asset-page">

    <!-- Page Header -->
    <div class="admin-page-header">
        <h1 class="admin-page-header__title">Assets</h1>
        <p class="admin-page-header__subtitle">Upload, browse, and manage your project files</p>
    </div>

    <!-- Upload Zone -->
    <section class="asset-upload-zone admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Upload
            </h2>
        </div>
        <div class="admin-card__body">
            <!-- Drop zone -->
            <div class="asset-dropzone" id="asset-dropzone">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p>Drop files here or <label for="asset-file-input" class="asset-dropzone__browse">click to browse</label></p>
                <small class="asset-dropzone__hint" id="asset-extensions-hint">Loading allowed extensions...</small>
                <input type="file" id="asset-file-input" multiple hidden>
            </div>

            <!-- URL inputs -->
            <div class="asset-url-section">
                <div class="asset-url-section__divider"><span>and / or paste URLs</span></div>
                <div class="asset-url-inputs" id="asset-url-inputs">
                    <input type="text" class="admin-input asset-url-input" 
                           placeholder="https://example.com/image.png" autocomplete="off">
                </div>
            </div>

            <!-- Unified upload queue -->
            <div class="asset-queue" id="asset-queue" style="display:none">
                <div class="asset-queue__header">
                    <span id="asset-queue-count">0 files ready</span>
                    <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" id="asset-queue-clear">Clear All</button>
                </div>
                <ul class="asset-queue__list" id="asset-queue-list"></ul>
                <button type="button" class="admin-btn admin-btn--primary" id="asset-upload-btn" disabled>
                    Upload All
                </button>
            </div>

            <!-- Upload progress -->
            <div class="asset-upload-progress" id="asset-upload-progress" style="display:none"></div>
        </div>
    </section>

    <!-- Edit Area (hidden by default, shown when editing an asset) -->
    <section class="asset-edit-area" id="asset-edit-area" style="display:none">
        <div class="admin-card">
            <div class="admin-card__header">
                <h2 class="admin-card__title" id="asset-edit-title">Edit Asset</h2>
                <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" id="asset-edit-close">&times; Close</button>
            </div>
            <div class="admin-card__body asset-edit-area__content">
                <div class="asset-edit-area__preview" id="asset-edit-preview"></div>
                <div class="asset-edit-area__fields">
                    <div class="asset-edit-area__info" id="asset-edit-info"></div>
                    <div class="admin-form-group">
                        <label class="admin-label">Alt Text</label>
                        <input type="text" class="admin-input" id="asset-edit-alt" placeholder="Image alt text (accessibility)" maxlength="250" autocomplete="off">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-label">Description</label>
                        <textarea class="admin-input asset-edit-description" id="asset-edit-description" placeholder="File description" maxlength="500" rows="3" autocomplete="off"></textarea>
                    </div>
                    <div class="asset-edit-area__actions">
                        <button type="button" class="admin-btn admin-btn--primary" id="asset-edit-save">Save Changes</button>
                        <span class="asset-edit-area__status" id="asset-edit-status"></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Asset Browser -->
    <section class="asset-browser" id="asset-browser">
        <div class="asset-browser__toolbar">
            <div class="asset-tabs" id="asset-tabs">
                <button class="asset-tabs__tab asset-tabs__tab--active" data-category="all">All <span class="asset-tabs__count" id="count-all">0</span></button>
                <button class="asset-tabs__tab" data-category="images">Images <span class="asset-tabs__count" id="count-images">0</span></button>
                <button class="asset-tabs__tab" data-category="font">Font <span class="asset-tabs__count" id="count-font">0</span></button>
                <button class="asset-tabs__tab" data-category="audio">Audio <span class="asset-tabs__count" id="count-audio">0</span></button>
                <button class="asset-tabs__tab" data-category="videos">Videos <span class="asset-tabs__count" id="count-videos">0</span></button>
                <button type="button" class="asset-tabs__tab asset-tabs__select" id="asset-select-mode">&#9744; Select</button>
            </div>
            <div class="asset-browser__actions">
                <div class="asset-search">
                    <svg class="asset-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" class="admin-input asset-search__input" id="asset-search" placeholder="Search files..." autocomplete="off">
                </div>
            </div>
        </div>

        <!-- Asset Grid -->
        <div class="asset-grid" id="asset-grid">
            <div class="admin-loading" id="asset-loading">
                <span class="admin-spinner"></span> Loading assets...
            </div>
        </div>

        <!-- Empty State -->
        <div class="asset-empty" id="asset-empty" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            <p id="asset-empty-text">No assets yet. Drop files above to get started.</p>
        </div>

        <!-- Batch action bar -->
        <div class="asset-batch-bar" id="asset-batch-bar" style="display:none">
            <span id="asset-batch-count">0 selected</span>
            <div>
                <button type="button" class="admin-btn admin-btn--small" id="asset-batch-select-all">Select All</button>
                <button type="button" class="admin-btn admin-btn--small admin-btn--danger" id="asset-batch-delete">Delete Selected</button>
            </div>
        </div>
    </section>

</div>
