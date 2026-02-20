<div class="preview-add-node-modal" id="preview-add-node-modal" style="display: none;">
    <div class="preview-add-node-modal__backdrop"></div>
    <div class="preview-add-node-modal__content">
        <div class="preview-add-node-modal__header">
            <h3><?= __admin('preview.addElement') ?? 'Add Element' ?></h3>
            <button type="button" class="preview-add-node-modal__close" id="add-node-modal-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <!-- Selection Context (collapsible) - shows where we're adding -->
        <div class="preview-add-node-modal__context" id="add-node-context">
            <button type="button" class="preview-add-node-modal__context-toggle" id="add-node-context-toggle">
                <svg class="preview-add-node-modal__context-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="preview-add-node-modal__context-title"><?= __admin('preview.selectionContext') ?? 'Selected Element' ?></span>
                <code class="preview-add-node-modal__context-summary" id="add-node-context-summary">-</code>
            </button>
            <div class="preview-add-node-modal__context-details" id="add-node-context-details">
                <div class="preview-add-node-modal__context-row">
                    <span class="preview-add-node-modal__context-label"><?= __admin('preview.nodeId') ?? 'Node' ?>:</span>
                    <code class="preview-add-node-modal__context-value" id="add-node-context-id">-</code>
                </div>
                <div class="preview-add-node-modal__context-row">
                    <span class="preview-add-node-modal__context-label"><?= __admin('preview.nodeTag') ?? 'Tag' ?>:</span>
                    <span class="preview-add-node-modal__context-value" id="add-node-context-tag">-</span>
                </div>
                <div class="preview-add-node-modal__context-row">
                    <span class="preview-add-node-modal__context-label"><?= __admin('preview.nodeClasses') ?? 'Classes' ?>:</span>
                    <code class="preview-add-node-modal__context-value" id="add-node-context-classes">-</code>
                </div>
            </div>
        </div>
        
        <div class="preview-add-node-modal__body">
            <!-- Element Type Selection: Tabs -->
            <div class="preview-add-node-modal__field">
                <div class="preview-add-node-modal__tabs" id="add-node-type-tabs">
                    <button type="button" class="preview-add-node-modal__tab active" data-type="tag">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;">
                            <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                        </svg>
                        <?= __admin('preview.htmlTag') ?? 'HTML Tag' ?>
                    </button>
                    <button type="button" class="preview-add-node-modal__tab" data-type="component">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;">
                            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                        </svg>
                        <?= __admin('preview.component') ?? 'Component' ?>
                    </button>
                </div>
                <input type="hidden" name="add-node-type" id="add-node-type-input" value="tag">
            </div>
            
            <!-- Tag Selection (for HTML Tag type) -->
            <div class="preview-add-node-modal__field" id="add-node-tag-field">
                <label for="add-node-tag"><?= __admin('preview.selectTag') ?? 'Tag' ?>:</label>
                <select id="add-node-tag" class="admin-input">
                    <optgroup label="<?= __admin('preview.layoutTags') ?? 'Layout' ?>">
                        <option value="div">div</option>
                        <option value="section">section</option>
                        <option value="article">article</option>
                        <option value="header">header</option>
                        <option value="footer">footer</option>
                        <option value="nav">nav</option>
                        <option value="main">main</option>
                        <option value="aside">aside</option>
                        <option value="figure">figure</option>
                        <option value="figcaption">figcaption</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.textTags') ?? 'Text' ?>">
                        <option value="p">p (paragraph)</option>
                        <option value="h1">h1</option>
                        <option value="h2">h2</option>
                        <option value="h3">h3</option>
                        <option value="h4">h4</option>
                        <option value="h5">h5</option>
                        <option value="h6">h6</option>
                        <option value="span">span</option>
                        <option value="strong">strong</option>
                        <option value="em">em</option>
                        <option value="small">small</option>
                        <option value="mark">mark</option>
                        <option value="blockquote">blockquote</option>
                        <option value="pre">pre</option>
                        <option value="code">code</option>
                        <option value="q">q (quote)</option>
                        <option value="cite">cite</option>
                        <option value="abbr">abbr</option>
                        <option value="time">time</option>
                        <option value="address">address</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.interactiveTags') ?? 'Interactive' ?>">
                        <option value="a">a (link) *</option>
                        <option value="button">button</option>
                        <option value="details">details</option>
                        <option value="summary">summary</option>
                        <option value="dialog">dialog</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.listTags') ?? 'Lists' ?>">
                        <option value="ul">ul (unordered list)</option>
                        <option value="ol">ol (ordered list)</option>
                        <option value="li">li (list item)</option>
                        <option value="dl">dl (description list)</option>
                        <option value="dt">dt (term)</option>
                        <option value="dd">dd (description)</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.mediaTags') ?? 'Media' ?>">
                        <option value="img">img * (image)</option>
                        <option value="picture">picture</option>
                        <option value="video">video *</option>
                        <option value="audio">audio *</option>
                        <option value="iframe">iframe *</option>
                        <option value="embed">embed *</option>
                        <option value="object">object *</option>
                        <option value="source">source *</option>
                        <option value="track">track *</option>
                        <option value="canvas">canvas</option>
                        <option value="svg">svg</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.formTags') ?? 'Form' ?>">
                        <option value="form">form *</option>
                        <option value="input">input *</option>
                        <option value="textarea">textarea *</option>
                        <option value="label">label *</option>
                        <option value="select">select *</option>
                        <option value="option">option</option>
                        <option value="optgroup">optgroup</option>
                        <option value="fieldset">fieldset</option>
                        <option value="legend">legend</option>
                        <option value="datalist">datalist</option>
                        <option value="output">output</option>
                        <option value="progress">progress</option>
                        <option value="meter">meter</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.tableTags') ?? 'Table' ?>">
                        <option value="table">table</option>
                        <option value="thead">thead</option>
                        <option value="tbody">tbody</option>
                        <option value="tfoot">tfoot</option>
                        <option value="tr">tr</option>
                        <option value="th">th</option>
                        <option value="td">td</option>
                        <option value="caption">caption</option>
                        <option value="colgroup">colgroup</option>
                        <option value="col">col</option>
                    </optgroup>
                    <optgroup label="<?= __admin('preview.otherTags') ?? 'Other' ?>">
                        <option value="br">br (line break)</option>
                        <option value="hr">hr (horizontal rule)</option>
                        <option value="wbr">wbr (word break)</option>
                    </optgroup>
                </select>
                <small class="preview-add-node-modal__hint">* <?= __admin('preview.requiresParams') ?? 'Requires additional parameters' ?></small>
            </div>
            
            <!-- Position Selection -->
            <div class="preview-add-node-modal__field">
                <label for="add-node-position"><?= __admin('preview.position') ?? 'Position' ?>:</label>
                <select id="add-node-position" class="admin-input">
                    <option value="after"><?= __admin('preview.positionAfter') ?? 'After selected element' ?></option>
                    <option value="before"><?= __admin('preview.positionBefore') ?? 'Before selected element' ?></option>
                    <option value="inside"><?= __admin('preview.positionInside') ?? 'Inside (as first child)' ?></option>
                </select>
            </div>
            
            <!-- Mandatory Parameters Section (dynamic based on tag) -->
            <div class="preview-add-node-modal__section" id="add-node-mandatory-params" style="display: none;">
                <label class="preview-add-node-modal__section-label">
                    <?= __admin('preview.requiredParams') ?? 'Required Parameters' ?>:
                </label>
                <div class="preview-add-node-modal__mandatory-fields" id="mandatory-params-container">
                    <!-- Dynamically populated -->
                </div>
            </div>
            
            <!-- CSS Class Input -->
            <div class="preview-add-node-modal__field" id="add-node-class-field">
                <label for="add-node-class"><?= __admin('preview.cssClass') ?? 'CSS Class' ?> <small>(<?= __admin('common.optional') ?? 'optional' ?>)</small>:</label>
                <input type="text" id="add-node-class" class="admin-input" placeholder="my-class another-class">
            </div>
            
            <!-- Custom Parameters Section (expandable) -->
            <div class="preview-add-node-modal__section">
                <button type="button" class="preview-add-node-modal__expand-btn" id="add-node-expand-params">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?= __admin('preview.addCustomParam') ?? 'Add custom parameter' ?>
                </button>
                <div class="preview-add-node-modal__custom-params" id="custom-params-container" style="display: none;">
                    <div class="preview-add-node-modal__param-list" id="custom-params-list">
                        <!-- Dynamically added param rows -->
                    </div>
                    <button type="button" class="preview-add-node-modal__add-param-btn" id="add-another-param">
                        + <?= __admin('preview.addAnother') ?? 'Add another' ?>
                    </button>
                </div>
            </div>
            
            <!-- TextKey Info (read-only, informational) -->
            <div class="preview-add-node-modal__info" id="add-node-textkey-info" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= __admin('preview.textKeyWillGenerate') ?? 'Text key will be auto-generated' ?>: <code id="generated-textkey-preview">-</code></span>
            </div>
            
            <!-- Alt Key Info for img/area (read-only, informational) -->
            <div class="preview-add-node-modal__info" id="add-node-altkey-info" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span><?= __admin('preview.altKeyWillGenerate') ?? 'Alt text key will be auto-generated' ?>: <code id="generated-altkey-preview">-</code></span>
            </div>
            
            <!-- Component Selection (for Component type) -->
            <div class="preview-add-node-modal__field" id="add-node-component-field" style="display: none;">
                <label for="add-node-component"><?= __admin('preview.selectComponent') ?? 'Select Component' ?>:</label>
                <select id="add-node-component" class="admin-input">
                    <option value=""><?= __admin('preview.selectComponentPlaceholder') ?? '-- Select a component --' ?></option>
                </select>
            </div>
            
            <!-- Component Variables (for Component type) -->
            <div class="preview-add-node-modal__section" id="add-node-component-vars" style="display: none;">
                <label class="preview-add-node-modal__section-label">
                    <?= __admin('preview.componentVariables') ?? 'Component Variables' ?>:
                </label>
                <div class="preview-add-node-modal__component-vars-list" id="component-vars-container">
                    <!-- Dynamically populated: textKey vars (read-only), param vars (input) -->
                </div>
                <div class="preview-add-node-modal__info" id="component-no-vars" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span><?= __admin('preview.componentNoVars') ?? 'This component has no configurable variables' ?></span>
                </div>
            </div>
        </div>
        <div class="preview-add-node-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" id="add-node-cancel"><?= __admin('common.cancel') ?></button>
            <button type="button" class="admin-btn admin-btn--success" id="add-node-confirm"><?= __admin('preview.addElement') ?? 'Add Element' ?></button>
        </div>
    </div>
</div>
