<?php
/**
 * API Registry Page - External API Endpoint Management
 * 
 * Manage external API definitions (REST endpoints) for use in the visual editor.
 * APIs are stored per-project in data/api-endpoints.json.
 * 
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/apis.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/pages/apis.js') ?>"></script>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 32px; height: 32px; margin-right: 8px;">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22 6 12 13 2 6"/>
        </svg>
        <?= __admin('apis.title') ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= __admin('apis.subtitle') ?></p>
</div>

<!-- Actions Bar -->
<div class="admin-toolbar apis-toolbar">
    <div class="admin-toolbar__left">
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-api">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('apis.addApi') ?>
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-refresh">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M23 4v6h-6M1 20v-6h6"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            <?= __admin('common.refresh') ?>
        </button>
    </div>
    <div class="admin-toolbar__right">
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-import" title="<?= __admin('apis.importJson') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <?= __admin('apis.import') ?>
        </button>
        <button type="button" class="admin-btn admin-btn--ghost" id="btn-export" title="<?= __admin('apis.exportJson') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <?= __admin('apis.export') ?>
        </button>
    </div>
</div>

<!-- Stats Overview -->
<div class="admin-grid admin-grid--cols-4 apis-stats">
    <div class="admin-stat-card">
        <div class="admin-stat-card__value" id="stat-apis">-</div>
        <div class="admin-stat-card__label"><?= __admin('apis.stats.apis') ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value" id="stat-endpoints">-</div>
        <div class="admin-stat-card__label"><?= __admin('apis.stats.endpoints') ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value" id="stat-get">-</div>
        <div class="admin-stat-card__label">GET</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value" id="stat-post">-</div>
        <div class="admin-stat-card__label">POST</div>
    </div>
</div>

<!-- Loading State -->
<div id="apis-loading" class="admin-card">
    <div class="admin-card__body apis-loading">
        <div class="admin-spinner"></div>
        <p class="admin-text-muted"><?= __admin('common.loading') ?></p>
    </div>
</div>

<!-- Empty State -->
<div id="apis-empty" class="admin-card" style="display: none;">
    <div class="admin-card__body apis-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="64" height="64" class="apis-empty__icon">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22 6 12 13 2 6"/>
        </svg>
        <h3 class="apis-empty__title"><?= __admin('apis.empty.title') ?></h3>
        <p class="admin-text-muted apis-empty__desc"><?= __admin('apis.empty.description') ?></p>
        <button type="button" class="admin-btn admin-btn--primary" id="btn-add-api-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('apis.addFirstApi') ?>
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
            <h2 class="admin-modal__title" id="modal-api-title"><?= __admin('apis.addApi') ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal">&times;</button>
        </div>
        <form id="form-api">
            <div class="admin-modal__body">
                <input type="hidden" id="api-edit-mode" value="add">
                <input type="hidden" id="api-original-id">
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-id"><?= __admin('apis.form.apiId') ?> *</label>
                    <input type="text" class="admin-input" id="api-id" name="apiId" required 
                           pattern="[a-zA-Z0-9][a-zA-Z0-9\-_]*"
                           placeholder="main-backend">
                    <p class="admin-hint"><?= __admin('apis.form.apiIdHint') ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-name"><?= __admin('apis.form.name') ?> *</label>
                    <input type="text" class="admin-input" id="api-name" name="name" required
                           placeholder="Main Backend API">
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-base-url"><?= __admin('apis.form.baseUrl') ?> *</label>
                    <input type="url" class="admin-input" id="api-base-url" name="baseUrl" required
                           placeholder="https://api.example.com">
                    <p class="admin-hint"><?= __admin('apis.form.baseUrlHint') ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="api-description"><?= __admin('apis.form.description') ?></label>
                    <textarea class="admin-input" id="api-description" name="description" rows="2"
                              placeholder="Optional description..."></textarea>
                </div>
                
                <fieldset class="admin-fieldset">
                    <legend><?= __admin('apis.form.authentication') ?></legend>
                    
                    <div class="admin-form-group">
                        <label class="admin-label" for="api-auth-type"><?= __admin('apis.form.authType') ?></label>
                        <select class="admin-select" id="api-auth-type" name="authType">
                            <option value="none"><?= __admin('apis.auth.none') ?></option>
                            <option value="bearer"><?= __admin('apis.auth.bearer') ?></option>
                            <option value="apiKey"><?= __admin('apis.auth.apiKey') ?></option>
                            <option value="basic"><?= __admin('apis.auth.basic') ?></option>
                        </select>
                    </div>
                    
                    <div class="admin-form-group" id="auth-token-source-group" style="display: none;">
                        <label class="admin-label" for="api-token-source"><?= __admin('apis.form.storageLocation') ?></label>
                        <select class="admin-select" id="api-token-source-prefix">
                            <option value="localStorage"><?= __admin('apis.form.storageLocalStorage') ?></option>
                            <option value="sessionStorage"><?= __admin('apis.form.storageSessionStorage') ?></option>
                            <option value="config"><?= __admin('apis.form.storageConfig') ?></option>
                        </select>
                        <label class="admin-label apis-auth-token-label"><?= __admin('apis.form.storageKeyName') ?></label>
                        <input type="text" class="admin-input apis-auth-token-input" id="api-token-source-key" 
                               placeholder="authToken">
                        <p class="admin-hint"><?= __admin('apis.form.storageKeyHint') ?></p>
                        <div id="auth-config-warning" class="admin-alert admin-alert--warning apis-auth-config-warning" style="display: none;">
                            <small><?= __admin('apis.form.configWarning') ?></small>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= __admin('common.cancel') ?></button>
                <button type="submit" class="admin-btn admin-btn--primary" id="btn-save-api"><?= __admin('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Endpoint Modal -->
<div id="modal-endpoint" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title" id="modal-endpoint-title"><?= __admin('apis.addEndpoint') ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal">&times;</button>
        </div>
        <form id="form-endpoint">
            <div class="admin-modal__body">
                <input type="hidden" id="endpoint-api-id">
                <input type="hidden" id="endpoint-edit-mode" value="add">
                <input type="hidden" id="endpoint-original-id">
                
                <div class="admin-grid admin-grid--cols-2">
                    <div class="admin-form-group">
                        <label class="admin-label" for="endpoint-id"><?= __admin('apis.form.endpointId') ?> *</label>
                        <input type="text" class="admin-input" id="endpoint-id" name="id" required
                               pattern="[a-zA-Z0-9][a-zA-Z0-9\-_]*"
                               placeholder="my-endpoint">
                    </div>
                    
                    <div class="admin-form-group">
                        <label class="admin-label" for="endpoint-method"><?= __admin('apis.form.method') ?> *</label>
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
                    <label class="admin-label" for="endpoint-name"><?= __admin('apis.form.endpointName') ?> *</label>
                    <input type="text" class="admin-input" id="endpoint-name" name="name" required
                           placeholder="">
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-path"><?= __admin('apis.form.path') ?> *</label>
                    <input type="text" class="admin-input" id="endpoint-path" name="path" required
                           placeholder="/">
                    <p class="admin-hint"><?= __admin('apis.form.pathHint') ?></p>
                </div>
                
                <div class="admin-form-group">
                    <label class="admin-label" for="endpoint-description"><?= __admin('apis.form.description') ?></label>
                    <textarea class="admin-input" id="endpoint-description" name="description" rows="2"
                              placeholder=""></textarea>
                </div>
                
                <div class="admin-form-group" id="endpoint-auth-group" style="display: none;">
                    <label class="admin-label" for="endpoint-auth"><?= __admin('apis.form.endpointAuth') ?></label>
                    <select class="admin-input" id="endpoint-auth" name="auth">
                        <option value="none"><?= __admin('apis.form.authNone') ?></option>
                        <option value="inherit"><?= __admin('apis.form.authInherit') ?></option>
                        <option value="required"><?= __admin('apis.form.authRequired') ?></option>
                    </select>
                    <p class="admin-hint"><?= __admin('apis.form.endpointAuthHint') ?></p>
                </div>
                
                <fieldset class="admin-fieldset">
                    <legend><?= __admin('apis.form.requestSchema') ?></legend>
                    <p class="admin-hint apis-schema-hint"><?= __admin('apis.form.schemaHint') ?></p>
                    <div class="admin-schema-editor">
                        <div class="admin-schema-editor__toolbar">
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-schema-template="request">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M9 3v18M3 9h18"/>
                                </svg>
                                <?= __admin('apis.form.template') ?>
                            </button>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-format-json="endpoint-request-schema">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M21 10H3M21 6H3M21 14H3M21 18H3"/>
                                </svg>
                                <?= __admin('common.format') ?>
                            </button>
                            <span class="admin-schema-editor__status" id="request-schema-status"></span>
                        </div>
                        <textarea class="admin-input admin-input--code" id="endpoint-request-schema" rows="6"
                                  placeholder='<?= __admin('apis.form.requestSchemaPlaceholder') ?>'></textarea>
                    </div>
                </fieldset>
                
                <fieldset class="admin-fieldset">
                    <legend><?= __admin('apis.form.responseSchema') ?></legend>
                    <div class="admin-schema-editor">
                        <div class="admin-schema-editor__toolbar">
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-schema-template="response">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M9 3v18M3 9h18"/>
                                </svg>
                                <?= __admin('apis.form.template') ?>
                            </button>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" data-format-json="endpoint-response-schema">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M21 10H3M21 6H3M21 14H3M21 18H3"/>
                                </svg>
                                <?= __admin('common.format') ?>
                            </button>
                            <span class="admin-schema-editor__status" id="response-schema-status"></span>
                        </div>
                        <textarea class="admin-input admin-input--code" id="endpoint-response-schema" rows="6"
                                  placeholder='<?= __admin('apis.form.responseSchemaPlaceholder') ?>'></textarea>
                    </div>
                </fieldset>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= __admin('common.cancel') ?></button>
                <button type="submit" class="admin-btn admin-btn--primary"><?= __admin('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Test Endpoint Modal -->
<div id="modal-test" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title"><?= __admin('apis.testEndpoint') ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal">&times;</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-grid admin-grid--cols-2 apis-test-grid">
                <!-- Request Panel -->
                <div>
                    <h4 class="apis-test-section-title"><?= __admin('apis.test.request') ?></h4>
                    <div id="test-request-info" class="admin-code-block apis-test-info"></div>
                    
                    <!-- Dynamic form fields from schema -->
                    <div id="test-params-form" class="apis-test-params-form">
                        <!-- Generated dynamically -->
                    </div>
                    
                    <!-- Collapsible raw JSON (advanced) -->
                    <details class="admin-details apis-test-details">
                        <summary class="admin-details__summary"><?= __admin('apis.test.rawJson') ?></summary>
                        <div class="apis-test-details-content">
                            <div class="admin-form-group" id="test-body-group">
                                <label class="admin-label"><?= __admin('apis.test.requestBody') ?></label>
                                <textarea class="admin-input admin-input--code" id="test-request-body" rows="4"
                                          placeholder='{"key": "value"}'></textarea>
                            </div>
                            
                            <div class="admin-form-group" id="test-query-group">
                                <label class="admin-label"><?= __admin('apis.test.queryParams') ?></label>
                                <textarea class="admin-input admin-input--code" id="test-query-params" rows="3"
                                          placeholder='{"page": 1, "limit": 10}'></textarea>
                            </div>
                        </div>
                    </details>
                    
                    <button type="button" class="admin-btn admin-btn--primary apis-test-run-btn" id="btn-run-test">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <?= __admin('apis.test.run') ?>
                    </button>
                </div>
                
                <!-- Response Panel -->
                <div>
                    <h4 class="apis-test-section-title"><?= __admin('apis.test.response') ?></h4>
                    <div id="test-response-status" class="apis-test-response-status"></div>
                    <div id="test-response-time" class="admin-text-muted apis-test-response-time"></div>
                    <div id="test-response-body" class="admin-code-block apis-test-response-body">
                        <span class="admin-text-muted"><?= __admin('apis.test.noResponse') ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= __admin('common.close') ?></button>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div id="modal-import" class="admin-modal admin-modal--apis" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title"><?= __admin('apis.importJson') ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal">&times;</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-alert admin-alert--warning apis-import-warning">
                <svg class="admin-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <div><?= __admin('apis.importModal.warning') ?></div>
            </div>
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('apis.importModal.pasteJson') ?></label>
                <textarea class="admin-input admin-input--code" id="import-json" rows="12"
                          placeholder='{"apis": {...}}'></textarea>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-do-import"><?= __admin('apis.importModal.button') ?></button>
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div id="modal-confirm-delete" class="admin-modal admin-modal--apis admin-modal--small" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h2 class="admin-modal__title"><?= __admin('common.confirmDelete') ?></h2>
            <button type="button" class="admin-modal__close" data-dismiss="modal">&times;</button>
        </div>
        <div class="admin-modal__body">
            <p id="confirm-delete-message"></p>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-dismiss="modal"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--danger" id="btn-confirm-delete"><?= __admin('common.delete') ?></button>
        </div>
    </div>
</div>
