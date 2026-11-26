<!-- What the element info's toggle opens: inside the workspace, so it lies over the panel and the page, and no taller than they are -->
<div class="preview-element-info__details" id="preview-element-info-details">
    <div class="preview-element-info__row">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.nodeId')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <code class="preview-element-info__value" id="info-node-id">-</code>
    </div>
    <div class="preview-element-info__row">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.nodeTag')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <code class="preview-element-info__value" id="info-node-tag">-</code>
    </div>
    <div class="preview-element-info__row">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.nodeClasses')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <code class="preview-element-info__value" id="info-node-classes">-</code>
    </div>
    <div class="preview-element-info__row">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.nodeChildren')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <span class="preview-element-info__value" id="info-node-children">-</span>
    </div>
    <div class="preview-element-info__row" id="info-node-params-row" style="display: none;">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.nodeParams')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <span class="preview-element-info__value" id="info-node-params"></span>
    </div>
    <div class="preview-element-info__row" id="info-node-textkey-row" style="display: none;">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.textKey')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <code class="preview-element-info__value preview-element-info__value--textkey" id="info-node-textkey">-</code>
    </div>
    <div class="preview-element-info__row" id="info-node-component-row" style="display: none;">
        <span class="preview-element-info__label"><?= adminEscape(__admin('preview.componentName')) ?><?= adminEscape(__admin('common.join.colon')) ?></span>
        <code class="preview-element-info__value preview-element-info__value--component" id="info-node-component">-</code>
    </div>
</div>
