<!-- The selected element: its bar, in its own row. What its toggle opens is in element-info-details.php. -->
<div class="preview-element-info" id="preview-element-info">
    <button type="button" class="preview-element-info__toggle" id="preview-element-info-toggle" title="<?= __admin('preview.toggleDetails') ?? 'Toggle details' ?>">
        <svg class="preview-element-info__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
        <span class="preview-element-info__summary" id="preview-element-info-summary">-</span>
    </button>
</div>
