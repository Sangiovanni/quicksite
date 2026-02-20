<!-- JS MODE Content -->
<div class="preview-contextual-section preview-contextual-section--js" id="contextual-js" data-mode="js" style="display: none;">

    <!-- Page Events section (always visible, independent of element selection) -->
    <div class="preview-contextual-js-page-events" id="js-page-events">
        <div class="preview-contextual-js-page-events__header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="preview-contextual-js-page-events__icon">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
            <span class="preview-contextual-js-page-events__title"><?= __admin('preview.pageEvents') ?? 'Page Events' ?></span>
            <span class="preview-contextual-js-page-events__count" id="js-page-events-count" title="<?= __admin('preview.interactionCount') ?? 'Number of page-level interactions' ?>">0</span>
            <button type="button" class="preview-contextual-js-page-events__toggle" id="js-page-events-toggle" title="<?= __admin('preview.expandCollapse') ?? 'Expand / Collapse' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="preview-contextual-js-page-events__chevron">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        <div class="preview-contextual-js-page-events__body" id="js-page-events-body" style="display: none;">
            <div class="preview-contextual-js-page-events__list" id="js-page-events-list">
                <p class="preview-contextual-js-empty"><?= __admin('preview.noPageEvents') ?? 'No page-level events.' ?></p>
            </div>
            <div class="preview-contextual-js-page-events__actions">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="js-page-event-add">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= __admin('preview.addPageEvent') ?? 'Add Page Event' ?>
                </button>
            </div>
            <!-- Inline add form for page events -->
            <div class="preview-contextual-js-page-events__form" id="js-page-event-form" style="display: none;">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.event') ?? 'Event' ?></label>
                    <select class="preview-contextual-js-form-select" id="js-page-event-event">
                        <option value="onload"><?= __admin('preview.eventOnload') ?? 'onload (page ready)' ?></option>
                        <option value="onscroll"><?= __admin('preview.eventOnscroll') ?? 'onscroll' ?></option>
                        <option value="onresize"><?= __admin('preview.eventOnresize') ?? 'onresize' ?></option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.actionType') ?? 'Action Type' ?></label>
                    <select class="preview-contextual-js-form-select" id="js-page-event-action-type">
                        <option value="function"><?= __admin('preview.actionTypeFunction') ?? 'Function' ?></option>
                        <option value="api"><?= __admin('preview.actionTypeApi') ?? 'API Call' ?></option>
                    </select>
                </div>
                <!-- Function section -->
                <div id="js-page-event-function-section">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= __admin('preview.function') ?? 'Function' ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-function">
                            <option value=""><?= __admin('preview.selectFunction') ?? '-- Select function --' ?></option>
                        </select>
                    </div>
                </div>
                <!-- API section (hidden by default) -->
                <div id="js-page-event-api-section" class="preview-contextual-js-form-api-section">
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= __admin('preview.selectApi') ?? 'API' ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-api">
                            <option value=""><?= __admin('preview.selectApiPlaceholder') ?? '-- Select API --' ?></option>
                        </select>
                    </div>
                    <div class="preview-contextual-js-form-row">
                        <label class="preview-contextual-js-form-label"><?= __admin('preview.selectEndpoint') ?? 'Endpoint' ?></label>
                        <select class="preview-contextual-js-form-select" id="js-page-event-endpoint" disabled>
                            <option value=""><?= __admin('preview.selectEndpointPlaceholder') ?? '-- Select endpoint --' ?></option>
                        </select>
                    </div>
                </div>
                <!-- Response bindings (populated dynamically when endpoint selected) -->
                <div class="preview-contextual-js-response-bindings" id="js-page-event-bindings" style="display: none;">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.responseMapping') ?? 'Response Mapping' ?></label>
                    <p class="preview-contextual-js-response-bindings__hint"><?= __admin('preview.responseMappingHint') ?? 'Map API response fields to page elements' ?></p>
                    <div class="preview-contextual-js-response-bindings__rows" id="js-page-event-bindings-rows"></div>
                    <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-response-bindings__add" id="js-page-event-bindings-add">
                        + <?= __admin('preview.addMapping') ?? 'Add Mapping' ?>
                    </button>
                </div>
                <!-- Dynamic params -->
                <div class="preview-contextual-js-form-params" id="js-page-event-params"></div>
                <!-- Preview -->
                <div class="preview-contextual-js-form-preview">
                    <span class="preview-contextual-js-form-label"><?= __admin('preview.previewCode') ?? 'Preview' ?>:</span>
                    <code class="preview-contextual-js-form-code" id="js-page-event-preview">-</code>
                </div>
                <div class="preview-contextual-js-form-actions">
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="js-page-event-cancel">
                        <?= __admin('common.cancel') ?? 'Cancel' ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="js-page-event-save" disabled>
                        <?= __admin('preview.addPageEvent') ?? 'Add' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="preview-contextual-default" id="contextual-js-default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
        <span><?= __admin('preview.jsModeHint') ?? 'Click an element to manage its JavaScript interactions' ?></span>
    </div>
    
    <!-- JS Info (shown when element selected) -->
    <div class="preview-contextual-js-info" id="contextual-js-info" style="display: none;">
        <div class="preview-contextual-js-element">
            <span class="preview-contextual-js-label"><?= __admin('preview.element') ?? 'Element' ?>:</span>
            <code class="preview-contextual-js-value" id="js-element-info">-</code>
        </div>
    </div>
    
    <!-- Interactions List -->
    <div class="preview-contextual-js-content" id="contextual-js-content" style="display: none;">
        <div class="preview-contextual-js-list" id="js-interactions-list">
            <p class="preview-contextual-js-empty"><?= __admin('preview.noInteractions') ?? 'No interactions yet.' ?></p>
        </div>
        
        <!-- Add Interaction Form (hidden by default) -->
        <div class="preview-contextual-js-form" id="js-add-form" style="display: none;">
            <div class="preview-contextual-js-form-header">
                <strong><?= __admin('preview.newInteraction') ?? 'New Interaction' ?></strong>
                <button type="button" class="preview-contextual-js-form-close" id="js-form-close" title="<?= __admin('common.cancel') ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            
            <div class="preview-contextual-js-form-row">
                <label class="preview-contextual-js-form-label"><?= __admin('preview.event') ?? 'Event' ?></label>
                <select class="preview-contextual-js-form-select" id="js-form-event">
                    <option value=""><?= __admin('preview.selectEvent') ?? '-- Select event --' ?></option>
                </select>
            </div>
            
            <div class="preview-contextual-js-form-row">
                <label class="preview-contextual-js-form-label"><?= __admin('preview.actionType') ?? 'Action Type' ?></label>
                <select class="preview-contextual-js-form-select" id="js-form-action-type">
                    <option value="function"><?= __admin('preview.actionTypeFunction') ?? 'Function' ?></option>
                    <option value="api"><?= __admin('preview.actionTypeApi') ?? 'API Call' ?></option>
                </select>
            </div>
            
            <!-- Function section (shown when action type = function) -->
            <div id="js-form-function-section">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.function') ?? 'Function' ?></label>
                    <select class="preview-contextual-js-form-select" id="js-form-function">
                        <option value=""><?= __admin('preview.selectFunction') ?? '-- Select function --' ?></option>
                    </select>
                </div>
            </div>
            
            <!-- API section (shown when action type = api, hidden by default) -->
            <div id="js-form-api-section" class="preview-contextual-js-form-api-section">
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.selectApi') ?? 'API' ?></label>
                    <select class="preview-contextual-js-form-select" id="js-form-api">
                        <option value=""><?= __admin('preview.selectApiPlaceholder') ?? '-- Select API --' ?></option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.selectEndpoint') ?? 'Endpoint' ?></label>
                    <select class="preview-contextual-js-form-select" id="js-form-endpoint" disabled>
                        <option value=""><?= __admin('preview.selectEndpointPlaceholder') ?? '-- Select endpoint --' ?></option>
                    </select>
                </div>
                <div class="preview-contextual-js-form-row" id="js-form-api-body-row">
                    <label class="preview-contextual-js-form-label"><?= __admin('preview.requestBody') ?? 'Body source' ?></label>
                    <input type="text" class="preview-contextual-js-form-input" id="js-form-api-body" placeholder="<?= __admin('preview.bodySourcePlaceholder') ?? '#form or CSS selector' ?>">
                    <small class="preview-contextual-js-form-hint"><?= __admin('preview.bodySourceHint') ?? 'Use #form to collect from a form, or a CSS selector' ?></small>
                </div>
            </div>
            <!-- Response bindings for element interactions -->
            <div class="preview-contextual-js-response-bindings" id="js-form-bindings" style="display: none;">
                <label class="preview-contextual-js-form-label"><?= __admin('preview.responseMapping') ?? 'Response Mapping' ?></label>
                <p class="preview-contextual-js-response-bindings__hint"><?= __admin('preview.responseMappingHint') ?? 'Map API response fields to page elements' ?></p>
                <div class="preview-contextual-js-response-bindings__rows" id="js-form-bindings-rows"></div>
                <button type="button" class="admin-btn admin-btn--xs admin-btn--ghost preview-contextual-js-response-bindings__add" id="js-form-bindings-add">
                    + <?= __admin('preview.addMapping') ?? 'Add Mapping' ?>
                </button>
            </div>
            
            <div class="preview-contextual-js-form-params" id="js-form-params">
                <!-- Dynamic params will be populated based on selected function -->
            </div>
            
            <div class="preview-contextual-js-form-preview">
                <span class="preview-contextual-js-form-label"><?= __admin('preview.previewCode') ?? 'Preview' ?>:</span>
                <code class="preview-contextual-js-form-code" id="js-preview-code">-</code>
            </div>
            
            <div class="preview-contextual-js-form-actions">
                <button type="button" class="admin-btn admin-btn--sm admin-btn--ghost" id="js-form-cancel">
                    <?= __admin('common.cancel') ?? 'Cancel' ?>
                </button>
                <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="js-form-save" disabled>
                    <?= __admin('preview.addInteraction') ?? 'Add' ?>
                </button>
            </div>
        </div>
        
        <!-- Add button -->
        <div class="preview-contextual-js-actions" id="js-panel-actions">
            <button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="js-add-interaction">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <?= __admin('preview.addInteraction') ?? 'Add Interaction' ?>
            </button>
        </div>
    </div>
</div>
