<?php
/**
 * Admin Media Page
 * 
 * Dedicated page for managing project assets: upload, browse, edit, delete.
 * Multi-file upload, visual grid with previews, inline rename, batch delete.
 */

$baseUrl = rtrim(BASE_URL, '/');

// The page's words, assets.js reading each by its key's whole path; `common` comes with every page.
?>
<?= __adminWords(['media']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/core/media-alt.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/media-alt.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/assets.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/assets.js') ?>"></script>

<div class="asset-page">

    <!-- Page Header -->
    <div class="admin-page-header">
        <h1 class="admin-page-header__title">
            <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            <?= adminEscape(__admin('media.title')) ?>
        </h1>
        <p class="admin-page-header__subtitle"><?= adminEscape(__admin('media.subtitle')) ?></p>
    </div>

    <!-- Upload Zone: for a role that can upload -->
    <section class="admin-card" data-requires-command="uploadAsset">
        <div class="admin-card__header">
            <h2 class="admin-card__title">
                <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <?= adminEscape(__admin('media.uploadTitle')) ?>
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
                <p><?= str_replace('{browse}', '<label for="asset-file-input" class="asset-dropzone__browse">' . adminEscape(__admin('media.browseLink')) . '</label>', adminEscape(__admin('media.dropHere'))) ?></p>
                <small class="asset-dropzone__hint" id="asset-extensions-hint"><?= adminEscape(__admin('media.loadingExtensions')) ?></small>
                <input type="file" id="asset-file-input" multiple hidden>
            </div>

            <!-- URL inputs -->
            <div class="asset-url-section">
                <div class="asset-url-section__divider"><span><?= adminEscape(__admin('media.orPasteUrls')) ?></span></div>
                <div class="asset-url-inputs" id="asset-url-inputs">
                    <input type="text" class="admin-input asset-url-input" 
                           placeholder="https://example.com/image.png" autocomplete="off">
                </div>
            </div>

            <!-- Unified upload queue -->
            <div class="asset-queue" id="asset-queue" style="display:none">
                <div class="asset-queue__header">
                    <span id="asset-queue-count"><?= adminEscape(__adminCount('media.queueReady', 0)) ?></span>
                    <button type="button" class="admin-btn" id="asset-queue-clear"><?= adminEscape(__admin('common.clearAll')) ?></button>
                </div>
                <ul class="asset-queue__list" id="asset-queue-list"></ul>
                <button type="button" class="admin-btn admin-btn--primary" id="asset-upload-btn" disabled>
                    <?= adminEscape(__admin('media.uploadAll')) ?>
                </button>
            </div>

            <!-- Upload progress -->
            <div class="asset-upload-progress" id="asset-upload-progress" style="display:none"></div>
        </div>
    </section>

    <!-- Edit Area (hidden by default, shown when editing an asset) -->
    <section class="asset-edit-area" id="asset-edit-area" style="display:none">
        <div class="admin-card">
            <div class="admin-card__header asset-edit-area__header">
                <h2 class="admin-card__title" id="asset-edit-title"><?= adminEscape(__admin('media.editFile')) ?></h2>
                <button type="button" class="admin-modal__close" id="asset-edit-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
            </div>
            <div class="admin-card__body asset-edit-area__content">
                <div class="asset-edit-area__preview" id="asset-edit-preview"></div>
                <div class="asset-edit-area__fields">
                    <div class="asset-edit-area__info" id="asset-edit-info"></div>
                    <!-- A picture's alt text, per language of the PROJECT (never the panel's); its key stays out of sight.
                         assets.js fills the selector and shows the group for a picture only. -->
                    <div class="admin-form-group" id="asset-edit-alt-group" data-requires-command="setTranslationKeys,deleteTranslationKeys,editAsset" hidden>
                        <label class="admin-label" for="asset-edit-alt"><?= adminEscape(__admin('media.altLabel')) ?></label>
                        <div class="asset-edit-area__alt-row">
                            <select class="admin-select asset-edit-area__lang" id="asset-edit-alt-lang" aria-label="<?= adminAttr(__admin('media.altLanguage')) ?>" title="<?= adminAttr(__admin('media.altLanguage')) ?>"></select>
                            <input type="text" class="admin-input asset-edit-area__alt" id="asset-edit-alt" placeholder="<?= adminAttr(__admin('media.altLongPlaceholder')) ?>" maxlength="250" autocomplete="off" aria-describedby="asset-edit-alt-hint">
                        </div>
                        <p class="admin-hint" id="asset-edit-alt-hint"></p>
                    </div>
                    <div class="admin-form-group" data-requires-command="editAsset">
                        <label class="admin-label" for="asset-edit-description"><?= adminEscape(__admin('media.descriptionLabel')) ?></label>
                        <textarea class="admin-input asset-edit-description" id="asset-edit-description" placeholder="<?= adminAttr(__admin('media.descriptionLongPlaceholder')) ?>" maxlength="500" rows="3" autocomplete="off"></textarea>
                    </div>
                    <div class="asset-edit-area__actions" data-requires-command="editAsset">
                        <button type="button" class="admin-btn admin-btn--primary" id="asset-edit-save"><?= adminEscape(__admin('media.saveChanges')) ?></button>
                        <span class="asset-edit-area__status" id="asset-edit-status" role="status"></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Asset Browser -->
    <section id="asset-browser">
        <div class="asset-browser__toolbar">
            <div class="asset-tabs" id="asset-tabs">
                <button class="asset-tabs__tab asset-tabs__tab--active" data-category="all"><?= adminEscape(__admin('media.tabAll')) ?> <span class="asset-tabs__count" id="count-all">0</span></button>
                <button class="asset-tabs__tab" data-category="images"><?= adminEscape(__admin('media.tabImages')) ?> <span class="asset-tabs__count" id="count-images">0</span></button>
                <button class="asset-tabs__tab" data-category="font"><?= adminEscape(__admin('media.tabFont')) ?> <span class="asset-tabs__count" id="count-font">0</span></button>
                <button class="asset-tabs__tab" data-category="audio"><?= adminEscape(__admin('media.tabAudio')) ?> <span class="asset-tabs__count" id="count-audio">0</span></button>
                <button class="asset-tabs__tab" data-category="videos"><?= adminEscape(__admin('media.tabVideos')) ?> <span class="asset-tabs__count" id="count-videos">0</span></button>
                <button type="button" class="asset-tabs__tab asset-tabs__select" id="asset-select-mode" data-requires-command="deleteAsset"><?= adminEscape(__admin('media.selectOff')) ?></button>
            </div>
            <div class="asset-browser__actions">
                <div class="asset-search">
                    <svg class="asset-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" class="admin-input asset-search__input" id="asset-search" placeholder="<?= adminAttr(__admin('media.searchPlaceholder')) ?>" autocomplete="off">
                </div>
            </div>
        </div>

        <!-- Asset Grid -->
        <div class="asset-grid" id="asset-grid">
            <div class="admin-loading" id="asset-loading">
                <span class="admin-spinner"></span> <?= adminEscape(__admin('media.loadingFiles')) ?>
            </div>
        </div>

        <!-- Empty State -->
        <div class="asset-empty" id="asset-empty" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            <p id="asset-empty-text"><?= adminEscape(__admin('media.noneYet')) ?></p>
        </div>

        <!-- Batch action bar -->
        <div class="asset-batch-bar" id="asset-batch-bar" style="display:none" data-requires-command="deleteAsset">
            <span id="asset-batch-count"><?= adminEscape(__adminCount('media.selectedCount', 0)) ?></span>
            <div>
                <button type="button" class="admin-btn" id="asset-batch-select-all"><?= adminEscape(__admin('media.selectAll')) ?></button>
                <button type="button" class="admin-btn admin-btn--danger" id="asset-batch-delete"><?= adminEscape(__admin('media.deleteSelected')) ?></button>
            </div>
        </div>
    </section>

</div>
