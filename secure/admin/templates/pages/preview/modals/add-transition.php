<!-- Add-Transition Wizard Modal (A3-companion Motion Slice 4)
     Opened from the "+ Add transition" button at the top of the Transitions
     group in the Motion tab. Picks a selector, a property, duration, easing
     (via QSEasingPicker), and delay; writes the `transition:` declaration
     via setStyleRule. If the picked selector already has a transition,
     the form pre-fills from it + shows a "will overwrite" hint. -->
<div class="add-transition-modal" id="add-transition-modal" hidden>
    <div class="add-transition-modal__backdrop" id="add-transition-modal-backdrop"></div>
    <div class="add-transition-modal__dialog" role="dialog" aria-labelledby="add-transition-modal-title">
        <div class="add-transition-modal__header">
            <h3 class="add-transition-modal__title" id="add-transition-modal-title">
                <?= __admin('preview.addTransitionTitle', 'Add transition') ?>
            </h3>
            <button type="button" class="add-transition-modal__close" id="add-transition-modal-close" title="<?= __admin('common.close') ?? 'Close' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="add-transition-modal__body">
            <!-- Selector picker -->
            <div class="add-transition-modal__field">
                <label class="add-transition-modal__label"><?= __admin('preview.addTransitionSelector', 'Selector') ?></label>
                <input type="text"
                       class="admin-input add-transition-modal__search"
                       id="add-transition-selector-search"
                       placeholder="<?= __admin('preview.addTransitionSelectorSearchPlaceholder', 'Search selectors…') ?>"
                       autocomplete="off"
                       spellcheck="false">
                <div class="add-transition-modal__selector-list" id="add-transition-selector-list" role="listbox">
                    <!-- Populated by JS -->
                </div>
                <!-- Hint shown when the selected selector already has a transition -->
                <div class="add-transition-modal__existing-hint" id="add-transition-existing-hint" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span><?= __admin('preview.addTransitionExistingHint', 'This selector already has a transition. Submitting will overwrite it.') ?></span>
                </div>
            </div>

            <!-- Property picker -->
            <div class="add-transition-modal__field">
                <label class="add-transition-modal__label"><?= __admin('preview.addTransitionProperty', 'Property') ?></label>
                <div class="add-transition-modal__chips" id="add-transition-property-chips">
                    <!-- Preset property chips. The set is opinionated: the most-
                         transitioned properties on the web. Custom value lands
                         via the input below. -->
                    <button type="button" class="add-transition-modal__chip" data-property="opacity">opacity</button>
                    <button type="button" class="add-transition-modal__chip" data-property="transform">transform</button>
                    <button type="button" class="add-transition-modal__chip" data-property="color">color</button>
                    <button type="button" class="add-transition-modal__chip" data-property="background-color">background-color</button>
                    <button type="button" class="add-transition-modal__chip" data-property="border-color">border-color</button>
                    <button type="button" class="add-transition-modal__chip" data-property="box-shadow">box-shadow</button>
                    <button type="button" class="add-transition-modal__chip" data-property="all">all</button>
                </div>
                <input type="text"
                       class="admin-input add-transition-modal__property-input"
                       id="add-transition-property-input"
                       placeholder="<?= __admin('preview.addTransitionPropertyPlaceholder', 'or type a custom property') ?>"
                       autocomplete="off"
                       spellcheck="false">
            </div>

            <!-- Duration + Delay (number inputs) -->
            <div class="add-transition-modal__row">
                <div class="add-transition-modal__field add-transition-modal__field--inline">
                    <label class="add-transition-modal__label" for="add-transition-duration"><?= __admin('preview.addTransitionDuration', 'Duration') ?></label>
                    <div class="add-transition-modal__number-group">
                        <input type="number"
                               id="add-transition-duration"
                               class="admin-input add-transition-modal__number"
                               min="0"
                               step="50"
                               value="300">
                        <span class="add-transition-modal__unit"><?= __admin('preview.addTransitionMs', 'ms') ?></span>
                    </div>
                </div>
                <div class="add-transition-modal__field add-transition-modal__field--inline">
                    <label class="add-transition-modal__label" for="add-transition-delay"><?= __admin('preview.addTransitionDelay', 'Delay') ?></label>
                    <div class="add-transition-modal__number-group">
                        <input type="number"
                               id="add-transition-delay"
                               class="admin-input add-transition-modal__number"
                               min="0"
                               step="50"
                               value="0">
                        <span class="add-transition-modal__unit"><?= __admin('preview.addTransitionMs', 'ms') ?></span>
                    </div>
                </div>
            </div>

            <!-- Easing (button opens QSEasingPicker) -->
            <div class="add-transition-modal__field">
                <label class="add-transition-modal__label"><?= __admin('preview.addTransitionEasing', 'Easing') ?></label>
                <button type="button"
                        class="add-transition-modal__easing-btn"
                        id="add-transition-easing-btn"
                        data-easing="ease">
                    <span class="add-transition-modal__easing-value" id="add-transition-easing-value">ease</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                        <path d="M3 18 C 9 18, 9 6, 21 6"/>
                    </svg>
                    <span class="add-transition-modal__easing-edit"><?= __admin('preview.addTransitionEasingEditBtn', 'Edit curve…') ?></span>
                </button>
            </div>

            <!-- Generated declaration preview -->
            <div class="add-transition-modal__preview">
                <span class="add-transition-modal__preview-label"><?= __admin('preview.addTransitionPreviewLabel', 'Preview') ?>:</span>
                <code class="add-transition-modal__preview-value" id="add-transition-preview-value">transition: opacity 300ms ease;</code>
            </div>
        </div>
        <div class="add-transition-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="add-transition-modal-cancel">
                <?= __admin('common.cancel') ?? 'Cancel' ?>
            </button>
            <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="add-transition-modal-submit" disabled>
                <?= __admin('preview.addTransitionSubmit', 'Add transition') ?>
            </button>
        </div>
    </div>
</div>
