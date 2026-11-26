<?php
/**
 * API Registry Page - External API Endpoint Management
 * 
 * Manage external API definitions (REST endpoints) for use in the visual editor.
 * APIs are stored per-project in data/api-endpoints.json.
 */

$baseUrl = rtrim(BASE_URL, '/');

// Which auth types take a token location: the one list, read by the location options below.
require_once SECURE_FOLDER_PATH . '/src/functions/apiRegistry.php';
$qsLocationTypes = static fn(string $location): string => implode(' ', array_keys(array_filter(
    QS_API_TOKEN_LOCATIONS,
    static fn(array $locations): bool => in_array($location, $locations, true)
)));
?>

<?php // The page's words (the import converter's notes among them), and the storage-key picker's own: this page uses one of the wizards' helpers. ?>
<?= __adminWords(['apis', 'complexWizard.storageKeyPicker']) ?>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/preview/contextual-complex/wizard-text.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/wizard-text.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/preview/contextual-complex/storage-key-picker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/preview/contextual-complex/storage-key-picker.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/api-import/openapi-converter.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/api-import/openapi-converter.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/api-import/response-draft.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/api-import/response-draft.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/apis.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/apis.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22 6 12 13 2 6"/>
        </svg>
        <?= adminEscape(__admin('apis.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('apis.subtitle')) ?></p>
</div>

<!-- Actions Bar -->
<div class="apis-toolbar">
    <div>
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-api" data-requires-command="addApi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= adminEscape(__admin('apis.addApi')) ?>
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            <?= adminEscape(__admin('common.refresh')) ?>
        </button>
    </div>
    <div>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-import" title="<?= adminAttr(__admin('apis.importJson')) ?>" data-requires-command="addApi,editApi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <?= adminEscape(__admin('apis.import')) ?>
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-export" title="<?= adminAttr(__admin('apis.exportJson')) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <?= adminEscape(__admin('apis.export')) ?>
        </button>
    </div>
</div>

<!-- The figures: each its label, then its number, on one line; they wrap as the width runs out
     (the panel's shared .admin-figures, as the dashboard's). apis.js writes each number by its id. -->
<div class="admin-figures">
    <div class="admin-figure">
        <span class="admin-figure__label"><?= adminEscape(__admin('apis.stats.apis')) ?></span>
        <span class="admin-figure__value" id="stat-apis">-</span>
    </div>
    <div class="admin-figure">
        <span class="admin-figure__label"><?= adminEscape(__admin('apis.stats.endpoints')) ?></span>
        <span class="admin-figure__value" id="stat-endpoints">-</span>
    </div>
    <div class="admin-figure">
        <span class="admin-figure__label">GET</span>
        <span class="admin-figure__value" id="stat-get">-</span>
    </div>
    <div class="admin-figure">
        <span class="admin-figure__label">POST</span>
        <span class="admin-figure__value" id="stat-post">-</span>
    </div>
</div>

<!-- Loading State -->
<div id="apis-loading" class="admin-card">
    <div class="admin-card__body apis-loading">
        <div class="admin-spinner"></div>
        <p><?= adminEscape(__admin('common.loading')) ?></p>
    </div>
</div>

<!-- Empty State -->
<div id="apis-empty" class="admin-card" style="display: none;">
    <div class="admin-card__body apis-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="64" height="64" class="apis-empty__icon">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22 6 12 13 2 6"/>
        </svg>
        <h3 class="apis-empty__title"><?= adminEscape(__admin('apis.empty.title')) ?></h3>
        <p class="apis-empty__desc"><?= adminEscape(__admin('apis.empty.description')) ?></p>
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-api-empty" data-requires-command="addApi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= adminEscape(__admin('apis.addFirstApi')) ?>
        </button>
    </div>
</div>

<!-- APIs List Container -->
<div id="apis-list" style="display: none;"></div>

<!-- Add/Edit API Modal -->
<div id="modal-api" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title" id="modal-api-title"><?= adminEscape(__admin('apis.addApi')) ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <form id="form-api">
            <div class="admin-modal__body">
                <input type="hidden" id="api-edit-mode" value="add">
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-id"><?= adminEscape(__admin('apis.form.apiId')) ?> *</label>
                    <input type="text" class="admin-input" id="api-id" name="apiId" required 
                           pattern="[a-zA-Z0-9][a-zA-Z0-9\-_]*"
                           placeholder="main-backend">
                    <p class="admin-hint" id="api-id-hint"><?= adminEscape(__admin('apis.form.apiIdHint')) ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-name"><?= adminEscape(__admin('apis.form.name')) ?> *</label>
                    <input type="text" class="admin-input" id="api-name" name="name" required
                           placeholder="<?= adminAttr(__admin('apis.form.namePlaceholder')) ?>">
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-base-url"><?= adminEscape(__admin('apis.form.baseUrl')) ?> *</label>
                    <input type="url" class="admin-input" id="api-base-url" name="baseUrl" required
                           placeholder="https://api.example.com">
                    <p class="admin-hint"><?= adminEscape(__admin('apis.form.baseUrlHint')) ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-description"><?= adminEscape(__admin('apis.form.description')) ?></label>
                    <textarea class="admin-input" id="api-description" name="description" rows="2"
                              placeholder="<?= adminAttr(__admin('apis.form.descriptionPlaceholder')) ?>"></textarea>
                </div>
                
                <fieldset>
                    <legend><?= adminEscape(__admin('apis.form.authentication')) ?></legend>
                    
                    <div class="admin-form-group">
                        <label class="admin-label" for="api-auth-type"><?= adminEscape(__admin('apis.form.authType')) ?></label>
                        <select class="admin-select" id="api-auth-type" name="authType">
                            <option value="none"><?= adminEscape(__admin('apis.auth.none')) ?></option>
                            <option value="bearer"><?= adminEscape(__admin('apis.auth.bearer')) ?></option>
                            <option value="apiKey"><?= adminEscape(__admin('apis.auth.apiKey')) ?></option>
                            <option value="basic"><?= adminEscape(__admin('apis.auth.basic')) ?></option>
                            <option value="cookie"><?= adminEscape(__admin('apis.auth.cookie')) ?></option>
                        </select>
                    </div>
                    
                    <div class="admin-form-group" id="auth-token-source-group" style="display: none;">
                        <label class="admin-label" for="api-token-source-prefix"><?= adminEscape(__admin('apis.form.storageLocation')) ?></label>
                        <select class="admin-select" id="api-token-source-prefix">
                            <option value="localStorage" data-auth-types="<?= $qsLocationTypes('localStorage') ?>"><?= adminEscape(__admin('apis.form.storageLocalStorage')) ?></option>
                            <option value="sessionStorage" data-auth-types="<?= $qsLocationTypes('sessionStorage') ?>"><?= adminEscape(__admin('apis.form.storageSessionStorage')) ?></option>
                            <option value="header" data-auth-types="<?= $qsLocationTypes('header') ?>"><?= adminEscape(__admin('apis.form.storageHeader')) ?></option>
                        </select>
                        <label class="admin-label apis-auth-token-label" id="api-token-source-key-label" for="api-token-source-key"><?= adminEscape(__admin('apis.form.storageKeyName')) ?></label>
                        <input type="text" class="admin-input apis-auth-token-input" id="api-token-source-key"
                               placeholder="authToken">
                        <!-- A storage key is picked or created (apis.js, the shared picker); a header's name is typed above. -->
                        <div class="apis-auth-token-input" id="api-token-source-picker" style="display: none;"></div>
                        <p class="admin-hint" id="api-token-source-hint"><?= adminEscape(__admin('apis.form.storageKeyHint')) ?></p>
                    </div>

                    <!-- Refresh (optional) — bearer-only. The collapsible
                         <details>/<summary> is dependency-free and keeps the
                         section out of the way until needed. -->
                    <details id="auth-refresh-group" style="display: none;">
                        <summary><?= adminEscape(__admin('apis.form.refreshSection')) ?></summary>
                        <div class="admin-form-group">
                            <label class="admin-label" for="api-refresh-endpoint"><?= adminEscape(__admin('apis.form.refreshEndpoint')) ?></label>
                            <select class="admin-select" id="api-refresh-endpoint">
                                <option value=""><?= adminEscape(__admin('apis.form.refreshNone')) ?></option>
                            </select>
                            <p class="admin-hint"><?= adminEscape(__admin('apis.form.refreshEndpointHint')) ?></p>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-label"><?= adminEscape(__admin('apis.form.refreshTokenStorage')) ?></label>
                            <select class="admin-select" id="api-refresh-token-source-prefix">
                                <option value="localStorage"><?= adminEscape(__admin('apis.form.storageLocalStorage')) ?></option>
                                <option value="sessionStorage"><?= adminEscape(__admin('apis.form.storageSessionStorage')) ?></option>
                            </select>
                            <label class="admin-label apis-auth-token-label"><?= adminEscape(__admin('apis.form.refreshTokenKey')) ?></label>
                            <div class="apis-auth-token-input" id="api-refresh-token-source-picker"></div>
                            <p class="admin-hint"><?= adminEscape(__admin('apis.form.refreshTokenKeyHint')) ?></p>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-label" for="api-refresh-body-field"><?= adminEscape(__admin('apis.form.refreshBodyField')) ?></label>
                            <input type="text" class="admin-input" id="api-refresh-body-field"
                                   placeholder="refreshToken">
                            <p class="admin-hint"><?= adminEscapeCode(__admin('apis.form.refreshBodyFieldHint')) ?></p>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-label" for="api-refresh-response-token-path"><?= adminEscape(__admin('apis.form.refreshResponseTokenPath')) ?></label>
                            <input type="text" class="admin-input" id="api-refresh-response-token-path"
                                   placeholder="token">
                            <p class="admin-hint"><?= adminEscapeCode(__admin('apis.form.refreshResponseTokenPathHint')) ?></p>
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-label" for="api-refresh-response-refresh-token-path"><?= adminEscape(__admin('apis.form.refreshResponseRefreshTokenPath')) ?></label>
                            <input type="text" class="admin-input" id="api-refresh-response-refresh-token-path"
                                   placeholder="refreshToken">
                            <p class="admin-hint"><?= adminEscape(__admin('apis.form.refreshResponseRefreshTokenPathHint')) ?></p>
                        </div>
                    </details>
                </fieldset>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= adminEscape(__admin('common.cancel')) ?></button>
                <button type="submit" class="admin-btn admin-btn--primary" id="btn-save-api"><?= adminEscape(__admin('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Endpoint Modal -->
<div id="modal-endpoint" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title" id="modal-endpoint-title"><?= adminEscape(__admin('apis.addEndpoint')) ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <form id="form-endpoint">
            <div class="admin-modal__body">
                <input type="hidden" id="endpoint-api-id">
                <input type="hidden" id="endpoint-edit-mode" value="add">
                <input type="hidden" id="endpoint-original-id">
                
                <div class="admin-grid admin-grid--cols-2">
                    <div class="admin-form-group">
                        <label class="admin-label" for="endpoint-id"><?= adminEscape(__admin('apis.form.endpointId')) ?> *</label>
                        <input type="text" class="admin-input" id="endpoint-id" name="id" required
                               pattern="[a-zA-Z0-9][a-zA-Z0-9\-_]*"
                               placeholder="my-endpoint">
                    </div>
                    
                    <div class="admin-form-group">
                        <label class="admin-label" for="endpoint-method"><?= adminEscape(__admin('apis.form.method')) ?> *</label>
                        <select class="admin-select" id="endpoint-method" name="method" required>
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </div>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-name"><?= adminEscape(__admin('apis.form.endpointName')) ?> *</label>
                    <input type="text" class="admin-input" id="endpoint-name" name="name" required
                           placeholder="">
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-path"><?= adminEscape(__admin('apis.form.path')) ?> *</label>
                    <input type="text" class="admin-input" id="endpoint-path" name="path" required
                           placeholder="/">
                    <p class="admin-hint"><?= adminEscape(__admin('apis.form.pathHint')) ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-description"><?= adminEscape(__admin('apis.form.description')) ?></label>
                    <textarea class="admin-input" id="endpoint-description" name="description" rows="2"
                              placeholder=""></textarea>
                </div>

                <!-- callableFrom marker. Auto-derive
                     defaults from the API's auth type; explicit override
                     wins. Server-only endpoints never appear in
                     qs-api-config.js (filtered at build emit time). -->
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-callable-from"><?= adminEscape(__admin('apis.form.callableFrom')) ?></label>
                    <select class="admin-select" id="endpoint-callable-from" name="callableFrom">
                        <option value=""><?= adminEscape(__admin('apis.form.callableFromAuto')) ?></option>
                        <option value="client"><?= adminEscape(__admin('apis.form.callableFromClient')) ?></option>
                        <option value="server"><?= adminEscape(__admin('apis.form.callableFromServer')) ?></option>
                        <option value="both"><?= adminEscape(__admin('apis.form.callableFromBoth')) ?></option>
                    </select>
                    <p class="admin-hint">
                        <?= adminEscape(__admin('apis.form.callableFromHint')) ?>
                        <span id="callable-from-auto-preview" style="display:none;">
                            <?= adminEscape(__admin('apis.form.callableFromAutoPreview')) ?>
                            <strong id="callable-from-auto-value"><?= adminEscape(__admin('apis.form.callableFromResolves.both')) ?></strong>
                        </span>
                    </p>
                </div>

                <div class="admin-form-group" id="endpoint-auth-group" style="display: none;">
                    <label class="admin-label" for="endpoint-auth"><?= adminEscape(__admin('apis.form.endpointAuth')) ?></label>
                    <select class="admin-input" id="endpoint-auth" name="auth">
                        <option value="none"><?= adminEscape(__admin('apis.form.authNone')) ?></option>
                        <option value="inherit"><?= adminEscape(__admin('apis.form.authInherit')) ?></option>
                        <option value="required"><?= adminEscape(__admin('apis.form.authRequired')) ?></option>
                    </select>
                    <p class="admin-hint"><?= adminEscape(__admin('apis.form.endpointAuthHint')) ?></p>
                </div>

                <fieldset>
                    <legend><?= adminEscape(__admin('apis.form.parameters')) ?></legend>
                    <p class="admin-hint">
                        <?= adminEscapeCode(__admin('apis.form.parametersHint')) ?>
                    </p>
                    <div>
                        <div id="endpoint-params-rows" class="apis-params-editor__rows">
                            <!-- Rows populated by JS -->
                        </div>
                        <button type="button"
                                class="admin-btn admin-btn--ghost admin-btn--xs apis-params-editor__add"
                                id="btn-add-endpoint-param">
                            + <?= adminEscape(__admin('apis.form.addParameter')) ?>
                        </button>
                    </div>
                </fieldset>

                <fieldset>
                    <legend><?= adminEscape(__admin('apis.form.requestSchema')) ?></legend>
                    <p class="admin-hint apis-schema-hint"><?= adminEscape(__admin('apis.form.schemaHint')) ?></p>
                    <div class="admin-schema-editor">
                        <div class="admin-schema-editor__toolbar">
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-schema-template="request">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M9 3v18M3 9h18"/>
                                </svg>
                                <?= adminEscape(__admin('apis.form.template')) ?>
                            </button>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-format-json="endpoint-request-schema">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M21 10H3M21 6H3M21 14H3M21 18H3"/>
                                </svg>
                                <?= adminEscape(__admin('common.format')) ?>
                            </button>
                            <span class="admin-schema-editor__status" id="request-schema-status"></span>
                        </div>
                        <textarea class="admin-input admin-input--code" id="endpoint-request-schema" rows="6"
                                  placeholder='<?= adminAttr(__admin('apis.form.requestSchemaPlaceholder')) ?>'></textarea>
                    </div>
                </fieldset>
                
                <fieldset>
                    <legend><?= adminEscape(__admin('apis.form.responseSchema')) ?></legend>
                    <div class="admin-schema-editor">
                        <div class="admin-schema-editor__toolbar">
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-schema-template="response">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M9 3v18M3 9h18"/>
                                </svg>
                                <?= adminEscape(__admin('apis.form.template')) ?>
                            </button>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-format-json="endpoint-response-schema">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M21 10H3M21 6H3M21 14H3M21 18H3"/>
                                </svg>
                                <?= adminEscape(__admin('common.format')) ?>
                            </button>
                            <span class="admin-schema-editor__status" id="response-schema-status"></span>
                        </div>
                        <textarea class="admin-input admin-input--code" id="endpoint-response-schema" rows="6"
                                  placeholder='<?= adminAttr(__admin('apis.form.responseSchemaPlaceholder')) ?>'></textarea>
                        <!-- What a draft from a test could not determine (apis.js). -->
                        <div id="response-schema-draft" class="admin-alert admin-alert--info apis-draft-notes" style="display: none;"></div>
                    </div>
                </fieldset>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= adminEscape(__admin('common.cancel')) ?></button>
                <button type="submit" class="admin-btn admin-btn--primary"><?= adminEscape(__admin('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Test Endpoint Modal -->
<div id="modal-test" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title"><?= adminEscape(__admin('apis.testEndpoint')) ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-grid admin-grid--cols-2 apis-test-grid">
                <!-- Request Panel -->
                <div>
                    <h4 class="apis-test-section-title"><?= adminEscape(__admin('apis.test.request')) ?></h4>
                    <div id="test-request-info" class="apis-test-info"></div>
                    
                    <!-- Dynamic form fields from schema -->
                    <div id="test-params-form" class="apis-test-params-form">
                        <!-- Generated dynamically -->
                    </div>
                    
                    <!-- Collapsible raw JSON (advanced) -->
                    <details class="admin-details apis-test-details">
                        <summary class="admin-details__summary"><?= adminEscape(__admin('apis.test.rawJson')) ?></summary>
                        <div class="apis-test-details-content">
                            <div class="admin-form-group" id="test-body-group">
                                <label class="admin-label"><?= adminEscape(__admin('apis.test.requestBody')) ?></label>
                                <textarea class="admin-input admin-input--code" id="test-request-body" rows="4"
                                          placeholder='{"key": "value"}'></textarea>
                            </div>
                            
                            <div class="admin-form-group" id="test-query-group">
                                <label class="admin-label"><?= adminEscape(__admin('apis.test.queryParams')) ?></label>
                                <textarea class="admin-input admin-input--code" id="test-query-params" rows="3"
                                          placeholder='{"page": 1, "limit": 10}'></textarea>
                            </div>
                        </div>
                    </details>
                    
                    <button type="button" class="admin-btn admin-btn--primary apis-test-run-btn" id="btn-run-test">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <?= adminEscape(__admin('apis.test.run')) ?>
                    </button>
                </div>
                
                <!-- Response Panel -->
                <div>
                    <h4 class="apis-test-section-title"><?= adminEscape(__admin('apis.test.response')) ?></h4>
                    <div id="test-response-status" class="apis-test-response-status"></div>
                    <div id="test-response-time" class="apis-test-response-time"></div>
                    <div id="test-response-body" class="apis-test-response-body">
                        <span><?= adminEscape(__admin('apis.test.noResponse')) ?></span>
                    </div>
                    <!-- A draft response schema, offered after a 2xx JSON answer (apis.js). -->
                    <div id="test-draft-offer" class="apis-test-draft-offer" style="display: none;"></div>
                </div>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= adminEscape(__admin('common.close')) ?></button>
        </div>
    </div>
</div>

<!-- Import Modal (two screens: paste → preview) -->
<div id="modal-import" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title" id="import-title"><?= adminEscape(__admin('apis.importJson')) ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-alert admin-alert--icon admin-alert--warning apis-import-warning">
                <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <div><?= adminEscape(__admin('apis.importModal.warning')) ?></div>
            </div>

            <!-- Screen 1: paste -->
            <div id="import-screen-paste">
                <p class="admin-hint"><?= adminEscape(__admin('apis.importModal.pasteHint')) ?></p>
                <div class="apis-import-examples">
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" id="btn-load-example-ours">
                        <?= adminEscape(__admin('apis.importModal.loadExampleOurs')) ?>
                    </button>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label" for="import-json"><?= adminEscape(__admin('apis.importModal.pasteJson')) ?></label>
                    <textarea class="admin-input admin-input--code" id="import-json" rows="12"
                              placeholder='{"apis": {...}}'></textarea>
                </div>
                <div id="import-parse-error" class="admin-alert" style="display:none;"></div>
            </div>

            <!-- Screen 2: preview-and-confirm -->
            <div id="import-screen-preview" style="display:none;">
                <div id="import-preview-summary" class="admin-alert admin-alert--info"></div>
                <!-- Shown only when one or more APIs have a relative baseUrl
                     (common with OpenAPI specs whose servers[0].url is a path
                     like /api/v3). Rows are built dynamically in apis.js. -->
                <div id="import-baseurl-fixer" class="admin-form-group" style="display:none;">
                    <label class="admin-label"><?= adminEscape(__admin('apis.importModal.baseUrlFixer')) ?></label>
                    <div id="import-baseurl-fixer-rows"></div>
                </div>
                <!-- Tree view: one section per API + per-endpoint checkboxes
                     so the author can deselect endpoints before importing.
                     Built dynamically in apis.js. -->
                <div class="admin-form-group">
                    <label class="admin-label"><?= adminEscape(__admin('apis.importModal.treeLabel')) ?></label>
                    <div id="import-tree"></div>
                </div>
                <details>
                    <summary><?= adminEscape(__admin('apis.importModal.advancedJson')) ?></summary>
                    <div class="admin-form-group">
                        <textarea class="admin-input admin-input--code" id="import-preview-json" rows="14"></textarea>
                        <p class="admin-hint"><?= adminEscape(__admin('apis.importModal.previewJsonHint')) ?></p>
                    </div>
                </details>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= adminEscape(__admin('common.cancel')) ?></button>
            <button type="button" class="admin-btn admin-btn--ghost" id="btn-import-back" style="display:none;"><?= adminEscape(__admin('common.back')) ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-import-next"><?= adminEscape(__admin('common.next')) ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-import-confirm" style="display:none;"><?= adminEscape(__admin('apis.importModal.button')) ?></button>
        </div>
    </div>
</div>
