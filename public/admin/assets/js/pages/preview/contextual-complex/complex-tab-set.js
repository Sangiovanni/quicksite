/**
 * Tab set wizard — kind 'tab-set'.
 *
 * Emits an ARIA-correct tab set with click-to-switch wired through
 * existing QS.* verbs (no new runtime needed). See TabSet.php for the
 * emitted shape + onclick chain details.
 *
 * Wizard fields:
 *  - setId (HTML id of the wrapper, used to scope per-tab click chains)
 *  - items[] = { labelKey, contentKey } — variable cardinality
 *
 * Server-side builder: secure/src/classes/complexElements/TabSet.php
 */
(function () {
    'use strict';

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
            star.className = 'admin-text-danger';
            star.textContent = ' *';
            l.appendChild(star);
        }
        return l;
    }
    function _renderHint(text) {
        var p = document.createElement('p');
        p.className = 'admin-hint';
        p.textContent = text;
        return p;
    }
    function _renderGroup(child) {
        var g = document.createElement('div');
        g.className = 'admin-form-group';
        if (child) g.appendChild(child);
        return g;
    }

    function _renderItemRow(data, pickerMap) {
        var d = data || {};
        var row = document.createElement('div');
        row.className = 'qs-tabset-row';
        row.style.display = 'flex';
        row.style.flexDirection = 'column';
        row.style.gap = '6px';

        var labelMount = document.createElement('div');
        labelMount.className = 'qs-tabset-row__label-mount';

        var contentMount = document.createElement('div');
        contentMount.className = 'qs-tabset-row__content-mount';

        var labelHint = document.createElement('div');
        labelHint.className = 'admin-hint';
        labelHint.textContent = 'Tab label';

        var contentHint = document.createElement('div');
        contentHint.className = 'admin-hint';
        contentHint.textContent = 'Panel content (wrapped in <p>)';

        row.appendChild(labelHint);
        row.appendChild(labelMount);
        row.appendChild(contentHint);
        row.appendChild(contentMount);

        var labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: 'e.g. settings.tabs.account',
            value: d.labelKey || '',
        });
        var contentPicker = window.QSComplexWizard.createTextKeyPicker({
            container: contentMount,
            placeholder: 'e.g. settings.account.body',
            value: d.contentKey || '',
        });
        pickerMap.set(row, { label: labelPicker, content: contentPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--tab-set';

        // -- setId --
        var setIdGroup = _renderGroup();
        setIdGroup.appendChild(_renderLabel('Tab set id (HTML id of the wrapper)', true));
        var setIdInput = document.createElement('input');
        setIdInput.type = 'text';
        setIdInput.className = 'admin-input';
        setIdInput.id = 'ce-ts-set-id';
        setIdInput.placeholder = 'e.g. settings-tabs';
        setIdInput.autocomplete = 'off';
        setIdGroup.appendChild(setIdInput);
        setIdGroup.appendChild(_renderHint('Used to scope each tab\'s click chain so multiple tab sets on the same page don\'t interfere. Letters/digits/hyphens/underscores; must start with a letter.'));
        wrap.appendChild(setIdGroup);

        // -- items --
        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel('Tabs', true));
        itemsGroup.appendChild(_renderHint('Each tab = one label + one panel content. The first tab is the initial active one. Each panel can be edited as a normal node after save (e.g. to add lists, images, or other complex elements).'));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: '+ Add tab',
            minRows: 1,
            initialRows: [
                { labelKey: '', contentKey: '' },
                { labelKey: '', contentKey: '' },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                return {
                    labelKey:   rec.label   ? (rec.label.getValue()   || '').trim() : '',
                    contentKey: rec.content ? (rec.content.getValue() || '').trim() : '',
                };
            }
        });

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.labelKey !== '' || r.contentKey !== ''; });
            return {
                setId: setIdInput.value.trim(),
                items: rows,
            };
        }

        function validate() {
            var cfg = getConfig();
            if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(cfg.setId)) {
                return 'Tab set id must start with a letter and use only letters, digits, hyphens, underscores.';
            }
            if (cfg.items.length === 0) return 'Add at least one tab.';
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                if (!it.labelKey)   return 'Tab #' + (i + 1) + ' is missing a label key.';
                if (!it.contentKey) return 'Tab #' + (i + 1) + ' is missing a content key.';
            }
            return null;
        }

        function destroy() {
            try { editor.destroy(); } catch (e) {}
            container.textContent = '';
        }

        return { getConfig: getConfig, validate: validate, destroy: destroy };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.registry = window.QSComplexWizard.registry || {};
    window.QSComplexWizard.registry['tab-set'] = {
        label: 'Tab set',
        description: 'An ARIA tab set: &lt;div role="tablist"&gt; + N &lt;button role="tab"&gt; + N &lt;div role="tabpanel"&gt;. Click-to-switch wired with QS verbs (no extra runtime).',
        renderWizard: renderWizard
    };
})();
