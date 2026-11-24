<!-- JS MODE Content -->
<div class="preview-contextual-section preview-contextual-section--js" id="contextual-js" data-mode="js" style="display: none;">

    <!-- Page Events section (always visible, independent of element selection) -->
    <div class="preview-contextual-js-page-events" id="js-page-events">
        <div class="preview-contextual-js-page-events__header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="preview-contextual-js-page-events__icon">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
            <span class="preview-contextual-js-page-events__title"><?= adminEscape(__admin('preview.pageEvents')) ?></span>
            <span class="preview-contextual-js-page-events__count" id="js-page-events-count" title="<?= adminAttr(__admin('preview.interactionCount')) ?>">0</span>
            <button type="button" class="preview-contextual-js-page-events__toggle" id="js-page-events-toggle" title="<?= adminAttr(__admin('preview.expandCollapse')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="preview-contextual-js-page-events__chevron">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        <div class="preview-contextual-js-page-events__body" id="js-page-events-body" style="display: none;">
            <div class="preview-contextual-js-page-events__list" id="js-page-events-list">
                <p class="preview-contextual-js-empty"><?= adminEscape(__admin('preview.noPageEvents')) ?></p>
            </div>
            <div class="preview-contextual-js-page-events__actions">
                <button type="button" class="admin-btn admin-btn--ghost" id="js-page-event-add">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= adminEscape(__admin('preview.addPageEvent')) ?>
                </button>
            </div>
            <!-- Inline add form for page events -->
            <div class="preview-contextual-js-page-events__form" id="js-page-event-form" style="display: none;">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.event')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-page-event-event">
                        <option value="onload"><?= adminEscape(__admin('preview.eventOnload')) ?></option>
                        <option value="onscroll"><?= adminEscape(__admin('preview.eventOnscroll')) ?></option>
                        <option value="onresize"><?= adminEscape(__admin('preview.eventOnresize')) ?></option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.actionType')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-page-event-action-type">
                        <option value="function"><?= adminEscape(__admin('preview.actionTypeFunction')) ?></option>
                        <option value="api"><?= adminEscape(__admin('preview.actionTypeApi')) ?></option>
                    </select>
                </div>
                <!-- Function section -->
                <div id="js-page-event-function-section">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label" for="js-page-event-function"><?= adminEscape(__admin('preview.function')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-function">
                            <option value=""><?= adminEscape(__admin('preview.selectFunction')) ?></option>
                        </select>
                    </div>
                    <!-- Show-all toggle for the page-event picker. Same role as
                         the element-level one above — bypass the event filter. -->
                    <div class="preview-contextual-js-form-row">
                        <label>
                            <input type="checkbox" id="js-page-event-function-show-all">
                            <span><?= adminEscape(__admin('preview.showAllFunctions')) ?></span>
                        </label>
                    </div>
                </div>
                <!-- API section (hidden by default) -->
                <div id="js-page-event-api-section" class="preview-contextual-js-form-api-section">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsApiLabel')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-api">
                            <option value=""><?= adminEscape(__admin('preview.selectApiPlaceholder')) ?></option>
                        </select>
                    </div>
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsEndpointLabel')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-endpoint" disabled>
                            <option value=""><?= adminEscape(__admin('preview.selectEndpointPlaceholder')) ?></option>
                        </select>
                    </div>
                </div>
                <!-- Response bindings (populated dynamically when endpoint selected) -->
                <div class="preview-contextual-js-response-bindings" id="js-page-event-bindings" style="display: none;">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.responseMapping')) ?></label>
                    <p class="preview-contextual-js-response-bindings__hint"><?= adminEscape(__admin('preview.responseMappingHint')) ?></p>
                    <div class="preview-contextual-js-response-bindings__rows" id="js-page-event-bindings-rows"></div>
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-response-bindings__add" id="js-page-event-bindings-add">
                        + <?= adminEscape(__admin('preview.addMapping')) ?>
                    </button>
                </div>
                <!-- Dynamic params -->
                <div class="preview-contextual-js-form-params" id="js-page-event-params"></div>
                <!-- Preview -->
                <div class="preview-contextual-js-form-preview">
                    <span class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.previewCode')) ?>:</span>
                    <code class="preview-contextual-js-form-code" id="js-page-event-preview">-</code>
                </div>
                <div class="preview-contextual-js-form-actions">
                    <button type="button" class="admin-btn admin-btn--ghost" id="js-page-event-cancel">
                        <?= adminEscape(__admin('common.cancel')) ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--primary" id="js-page-event-save" disabled>
                        <?= adminEscape(__admin('preview.addPageEvent')) ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- State stores section (always visible, independent of element selection) -->
    <div class="preview-contextual-js-page-events" id="js-state-stores">
        <div class="preview-contextual-js-page-events__header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="preview-contextual-js-page-events__icon">
                <ellipse cx="12" cy="5" rx="8" ry="3"/>
                <path d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/>
                <path d="M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
            </svg>
            <span class="preview-contextual-js-page-events__title"><?= adminEscape(__admin('preview.stateStores')) ?></span>
            <span class="preview-contextual-js-page-events__count" id="js-state-stores-count" title="<?= adminAttr(__admin('preview.stateStoresCount')) ?>">0</span>
            <button type="button" class="preview-contextual-js-page-events__toggle" id="js-state-stores-toggle" title="<?= adminAttr(__admin('preview.expandCollapse')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="preview-contextual-js-page-events__chevron">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        <div class="preview-contextual-js-page-events__body" id="js-state-stores-body" style="display: none;">
            <div class="preview-contextual-js-page-events__list" id="js-state-stores-list">
                <p class="preview-contextual-js-empty"><?= adminEscape(__admin('preview.noStateStores')) ?></p>
            </div>
            <div class="preview-contextual-js-page-events__actions">
                <button type="button" class="admin-btn admin-btn--ghost" id="js-state-store-add">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= adminEscape(__admin('preview.addStateStore')) ?>
                </button>
                <button type="button" class="admin-btn admin-btn--ghost" id="js-state-store-import" title="<?= adminAttr(__admin('preview.stateStoreImportPick')) ?>">
                    <!-- "download / import" arrow into a tray -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                        <path d="M12 3v12"/>
                        <polyline points="6 11 12 17 18 11"/>
                        <line x1="4" y1="21" x2="20" y2="21"/>
                    </svg>
                    <?= adminEscape(__admin('preview.stateStoreImport')) ?>
                </button>
            </div>
            <!-- Import picker (populated by getStateStores with no route → all routes) -->
            <div class="preview-contextual-js-page-events__form" id="js-state-store-import-picker" style="display: none;">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.stateStoreImportPick')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-state-store-import-select">
                        <option value="">--</option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row" id="js-state-store-import-rename-row" style="display: none;">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.stateStoreImportRename')) ?></label>
                    <input type="text" class="preview-contextual-js-form-input" id="js-state-store-import-rename-input" autocomplete="off">
                </div>
                <div class="preview-contextual-js-form-actions">
                    <button type="button" class="admin-btn admin-btn--ghost" id="js-state-store-import-cancel"><?= adminEscape(__admin('common.cancel')) ?></button>
                    <button type="button" class="admin-btn admin-btn--primary" id="js-state-store-import-confirm" disabled><?= adminEscape(__admin('preview.stateStoreImportConfirm')) ?></button>
                </div>
            </div>
            <!-- New / edit store wizard -->
            <div class="preview-contextual-js-page-events__form" id="js-state-store-form" style="display: none;">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.stateStoreId')) ?></label>
                    <input type="text" class="preview-contextual-js-form-input" id="js-state-store-id" placeholder="commandsList" autocomplete="off">
                </div>
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsApiLabel')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-state-store-api">
                        <option value=""><?= adminEscape(__admin('preview.selectApiPlaceholder')) ?></option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsEndpointLabel')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-state-store-endpoint" disabled>
                        <option value=""><?= adminEscape(__admin('preview.selectEndpointPlaceholder')) ?></option>
                    </select>
                </div>
                <label class="preview-contextual-js-form-checkbox">
                    <input type="checkbox" id="js-state-store-fetch-on-load">
                    <span><?= adminEscape(__admin('preview.stateStoreFetchOnLoad')) ?></span>
                </label>
                <div class="preview-contextual-js-state-stores__fields">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.stateStoreFields')) ?></label>
                    <p class="preview-contextual-js-response-bindings__hint"><?= adminEscape(__admin('preview.stateStoreFieldsHint')) ?></p>
                    <div class="preview-contextual-js-state-stores__fields-rows" id="js-state-store-fields-rows"></div>
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost" id="js-state-store-field-add">
                        + <?= adminEscape(__admin('preview.stateStoreAddField')) ?>
                    </button>
                </div>
                <div class="preview-contextual-js-form-preview">
                    <span class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.previewCode')) ?>:</span>
                    <code class="preview-contextual-js-form-code" id="js-state-store-preview">-</code>
                </div>
                <div class="preview-contextual-js-form-actions">
                    <button type="button" class="admin-btn admin-btn--ghost" id="js-state-store-cancel">
                        <?= adminEscape(__admin('common.cancel')) ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--primary" id="js-state-store-save" disabled>
                        <?= adminEscape(__admin('preview.saveStateStore')) ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="preview-contextual-default" id="contextual-js-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
        <span><?= adminEscape(__admin('preview.jsModeHint')) ?></span>
    </div>
    
    <!-- JS Info (shown when element selected) -->
    <div id="contextual-js-info" style="display: none;">
        <div class="preview-contextual-js-element">
            <span class="preview-contextual-js-label"><?= adminEscape(__admin('preview.element')) ?>:</span>
            <code class="preview-contextual-js-value" id="js-element-info">-</code>
        </div>
        <!-- Navigation Buttons (mirror of select-mode nav, scoped to JS panel) -->
        <div class="preview-contextual-info__nav" id="js-node-nav">
            <button type="button" class="preview-nav-btn" id="js-nav-parent" title="<?= adminAttr(__admin('preview.goToParent')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18 15 12 9 6 15"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="js-nav-prev" title="<?= adminAttr(__admin('preview.goToPrevSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="js-nav-next" title="<?= adminAttr(__admin('preview.goToNextSibling')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <button type="button" class="preview-nav-btn" id="js-nav-child" title="<?= adminAttr(__admin('preview.goToFirstChild')) ?>" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Interactions List -->
    <div class="preview-contextual-js-content" id="contextual-js-content" style="display: none;">
        <div class="preview-contextual-js-list" id="js-interactions-list">
            <p class="preview-contextual-js-empty"><?= adminEscape(__admin('preview.noInteractions')) ?></p>
        </div>
        
        <!-- Add Interaction Form (hidden by default) -->
        <div class="preview-contextual-js-form" id="js-add-form" style="display: none;">
            <div class="preview-contextual-js-form-header">
                <strong><?= adminEscape(__admin('preview.newInteraction')) ?></strong>
                <button type="button" class="admin-modal__close" id="js-form-close" data-qs-icon="close" aria-label="<?= adminAttr(__admin('common.close')) ?>" title="<?= adminAttr(__admin('common.close')) ?>"></button>
            </div>
            
            <div class="preview-contextual-js-form-row">
                <label class="preview-contextual-js-form-label" for="js-form-event"><?= adminEscape(__admin('preview.event')) ?></label>
                <select class="preview-contextual-js-form-select" id="js-form-event">
                    <option value=""><?= adminEscape(__admin('preview.selectEvent')) ?></option>
                </select>
            </div>
            
            <div class="preview-contextual-js-form-row">
                <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.actionType')) ?></label>
                <select class="preview-contextual-js-form-select" id="js-form-action-type">
                    <option value="function"><?= adminEscape(__admin('preview.actionTypeFunction')) ?></option>
                    <option value="api"><?= adminEscape(__admin('preview.actionTypeApi')) ?></option>
                </select>
            </div>
            
            <!-- Function section (shown when action type = function) -->
            <div id="js-form-function-section">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label" for="js-form-function"><?= adminEscape(__admin('preview.function')) ?></label>
                    <select class="preview-contextual-js-form-select" id="js-form-function">
                        <option value=""><?= adminEscape(__admin('preview.selectFunction')) ?></option>
                    </select>
                </div>
                <!-- Show-all toggle: by default the function list is filtered to verbs
                     that declare the chosen event in their qsVerbCatalog `events` field.
                     Authors hitting an unusual case toggle this to see every verb. -->
                <div class="preview-contextual-js-form-row">
                    <label>
                        <input type="checkbox" id="js-form-function-show-all">
                        <span><?= adminEscape(__admin('preview.showAllFunctions')) ?></span>
                    </label>
                </div>
                <!-- Function details panel: full description + example.
                     Populated on function-select change. Hidden when no selection. -->
                <div class="preview-contextual-js-form-details" id="js-form-function-details" hidden></div>
            </div>
            
            <!-- API section (shown when action type = api, hidden by default) -->
            <div id="js-form-api-section" class="preview-contextual-js-form-api-section">
                <!-- Mode radio: From registry (default) vs Direct URL.
                     Toggles which sub-form is visible. Registry mode picks
                     an API + endpoint from the catalogue; Direct URL lets
                     the user type any METHOD + URL (with {{lang}} and
                     :placeholders supported). -->
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.apiMode')) ?></label>
                    <div class="preview-contextual-js-form-mode">
                        <label class="preview-contextual-js-form-mode-option">
                            <input type="radio" name="js-form-api-mode" value="registry" checked>
                            <span><?= adminEscape(__admin('preview.apiModeRegistry')) ?></span>
                        </label>
                        <label class="preview-contextual-js-form-mode-option">
                            <input type="radio" name="js-form-api-mode" value="direct">
                            <span><?= adminEscape(__admin('preview.apiModeDirect')) ?></span>
                        </label>
                    </div>
                </div>

                <!-- Registry-mode fields -->
                <div id="js-form-api-registry-fields">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsApiLabel')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-form-api">
                            <option value=""><?= adminEscape(__admin('preview.selectApiPlaceholder')) ?></option>
                        </select>
                    </div>
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.interactionsEndpointLabel')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-form-endpoint" disabled>
                            <option value=""><?= adminEscape(__admin('preview.selectEndpointPlaceholder')) ?></option>
                        </select>
                    </div>
                    <!-- Read-only "target" chip showing the resolved
                         @apiId/endpointId form. Useful for confirming the
                         compiled call without scrolling to the Preview
                         section. Hidden until both dropdowns are filled. -->
                    <div class="preview-contextual-js-form-row preview-contextual-js-form-target-row" id="js-form-target-row" style="display: none;">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.target')) ?></label>
                        <code class="preview-contextual-js-form-target" id="js-form-target-label">—</code>
                    </div>
                </div>

                <!-- Direct-URL-mode fields -->
                <div id="js-form-api-direct-fields" style="display: none;">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.method')) ?></label>
                        <select class="preview-contextual-js-form-select" id="js-form-api-method">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </div>
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.url')) ?></label>
                        <input type="text" class="preview-contextual-js-form-input" id="js-form-api-url" placeholder="https://api.example.com/users/:id" autocomplete="off">
                        <small class="preview-contextual-js-form-hint"><?= adminEscape(__admin('preview.urlHint')) ?></small>
                    </div>
                </div>

                <!-- Path params — auto-shown when the picked endpoint's
                     path has :placeholders. Each placeholder becomes one
                     row (param name + value or selector). Compiled into
                     `paramName=value` kwargs on the call. -->
                <div class="preview-contextual-js-form-row" id="js-form-path-params-row" style="display: none;">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.pathParams')) ?></label>
                    <div class="preview-contextual-js-form-path-params-rows" id="js-form-path-params-rows"></div>
                </div>

                <!-- Auth helper hint (Tier 1).
                     Detected when the endpoint's responseSchema has a
                     token-shaped field. Click the button to drop a
                     pre-filled saveToken row into the post-fetch
                     actions list. -->
                <div class="preview-contextual-js-form-auth-hint" id="js-form-auth-hint" style="display: none;"></div>

                <!-- Body source — shared between registry and direct
                     modes. Hidden when method is GET/DELETE (the browser
                     forbids a body on those, see qs.js GET/HEAD handling). -->
                <div class="preview-contextual-js-form-row" id="js-form-api-body-row">
                    <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.requestBody')) ?></label>
                    <input type="text" class="preview-contextual-js-form-input" id="js-form-api-body" placeholder="<?= adminAttr(__admin('preview.bodySourcePlaceholder')) ?>">
                    <small class="preview-contextual-js-form-hint"><?= adminEscape(__admin('preview.bodySourceHint')) ?></small>
                </div>

                <!-- Advanced collapsible — hosts toast labels, silent
                     opt-outs, post-fetch actions, and the JS-function
                     escape hatch. Collapsed by default to keep the
                     common-case picker compact. -->
                <details class="preview-contextual-js-form-advanced" id="js-form-api-advanced">
                    <summary class="preview-contextual-js-form-advanced-summary">
                        <?= adminEscape(__admin('preview.advanced')) ?>
                    </summary>
                    <div class="preview-contextual-js-form-advanced-body">

                        <!-- Toast messages.
                             textKey pickers are mounted by JS into the
                             *-mount slots. Silent checkboxes opt out of
                             the toast entirely for that side. -->
                        <div class="preview-contextual-js-form-row">
                            <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.toastOnSuccess')) ?></label>
                            <div class="preview-contextual-js-form-toast-row">
                                <div class="preview-contextual-js-form-toast-mount" id="js-form-toast-success-mount"></div>
                                <label class="preview-contextual-js-form-toast-silent">
                                    <input type="checkbox" id="js-form-toast-success-silent">
                                    <span><?= adminEscape(__admin('preview.silent')) ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="preview-contextual-js-form-row">
                            <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.toastOnError')) ?></label>
                            <div class="preview-contextual-js-form-toast-row">
                                <div class="preview-contextual-js-form-toast-mount" id="js-form-toast-error-mount"></div>
                                <label class="preview-contextual-js-form-toast-silent">
                                    <input type="checkbox" id="js-form-toast-error-silent">
                                    <span><?= adminEscape(__admin('preview.silent')) ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Post-fetch actions (Step 5).
                             Each row is a small verb picker + arg inputs.
                             Compiled and appended to the chain after the
                             fetch step. The async wrap in
                             transformCallSyntax makes them await fetch. -->
                        <div class="preview-contextual-js-form-row preview-contextual-js-form-actions-row">
                            <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.postFetchActions')) ?></label>
                            <div class="preview-contextual-js-form-actions-list" id="js-form-actions-list"></div>
                            <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-form-actions-add" id="js-form-actions-add">
                                + <?= adminEscape(__admin('preview.addAction')) ?>
                            </button>
                        </div>

                    </div>
                </details>
            </div>
            <!-- Response bindings for element interactions -->
            <div class="preview-contextual-js-response-bindings" id="js-form-bindings" style="display: none;">
                <label class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.responseMapping')) ?></label>
                <p class="preview-contextual-js-response-bindings__hint"><?= adminEscape(__admin('preview.responseMappingHint')) ?></p>
                <div class="preview-contextual-js-response-bindings__rows" id="js-form-bindings-rows"></div>
                <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-response-bindings__add" id="js-form-bindings-add">
                    + <?= adminEscape(__admin('preview.addMapping')) ?>
                </button>
            </div>
            
            <div class="preview-contextual-js-form-params" id="js-form-params">
                <!-- Dynamic params will be populated based on selected function -->
            </div>
            
            <div class="preview-contextual-js-form-preview">
                <span class="preview-contextual-js-form-label"><?= adminEscape(__admin('preview.previewCode')) ?>:</span>
                <code class="preview-contextual-js-form-code" id="js-preview-code">-</code>
            </div>
            
            <div class="preview-contextual-js-form-actions">
                <button type="button" class="admin-btn admin-btn--ghost" id="js-form-cancel">
                    <?= adminEscape(__admin('common.cancel')) ?>
                </button>
                <button type="button" class="admin-btn admin-btn--primary" id="js-form-save" disabled>
                    <?= adminEscape(__admin('preview.addInteraction')) ?>
                </button>
            </div>
        </div>
        
        <!-- Add button -->
        <div class="preview-contextual-js-actions" id="js-panel-actions">
            <button type="button" class="admin-btn admin-btn--primary" id="js-add-interaction">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= adminEscape(__admin('preview.addInteraction')) ?>
            </button>
        </div>
    </div>
</div>
