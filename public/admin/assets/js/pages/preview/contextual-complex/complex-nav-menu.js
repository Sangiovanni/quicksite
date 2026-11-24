/**
 * Nav menu wizard — kind 'nav-menu'.
 *
 * Emits a <nav>/<ul>/<li>/<a> menu. Each row of the editor =
 * one menu item with a labelKey (textKey picker), href, and an
 * "external" checkbox that adds target="_blank" rel="noopener".
 *
 * Server-side builder: secure/src/classes/complexElements/NavMenu.php
 */
(function () {
    'use strict';

    var t = window.QSComplexWizard.t;

    function _renderLabel(text, required) {
        var l = document.createElement('label');
        l.className = 'admin-label';
        l.appendChild(document.createTextNode(text));
        if (required) {
            var star = document.createElement('span');
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
        row.style.display = 'flex';
        row.style.flexDirection = 'column';
        row.style.gap = '4px';

        // Top sub-row: labelKey picker (full width).
        var labelMount = document.createElement('div');

        // Bottom sub-row: href input + external checkbox.
        var hrefRow = document.createElement('div');
        hrefRow.style.display = 'flex';
        hrefRow.style.gap = '8px';
        hrefRow.style.alignItems = 'center';

        var hrefInput = document.createElement('input');
        hrefInput.type = 'text';
        hrefInput.className = 'admin-input qs-nav-row__href';
        hrefInput.placeholder = t('navMenu.hrefPlaceholder');
        hrefInput.autocomplete = 'off';
        hrefInput.style.flex = '1';
        hrefInput.style.minWidth = '0';
        if (d.href !== undefined) hrefInput.value = d.href;
        // Attach the shared routes datalist for autocomplete. External
        // URLs still work — datalist only SUGGESTS, doesn't constrain.
        if (window.QSComplexWizard && typeof window.QSComplexWizard.ensureRoutesDatalist === 'function') {
            window.QSComplexWizard.ensureRoutesDatalist().then(function (id) {
                hrefInput.setAttribute('list', id);
            });
        }

        var extLabel = document.createElement('label');
        extLabel.className = 'admin-checkbox';
        extLabel.style.whiteSpace = 'nowrap';
        var extCb = document.createElement('input');
        extCb.type = 'checkbox';
        extCb.className = 'qs-nav-row__external';
        if (d.external) extCb.checked = true;
        var extSpan = document.createElement('span');
        extSpan.textContent = t('navMenu.external');
        extLabel.appendChild(extCb);
        extLabel.appendChild(extSpan);

        hrefRow.appendChild(hrefInput);
        hrefRow.appendChild(extLabel);

        row.appendChild(labelMount);
        row.appendChild(hrefRow);

        var labelPicker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: t('navMenu.labelPlaceholder'),
            value: d.labelKey || '',
        });
        pickerMap.set(row, { label: labelPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel(t('navMenu.items'), true));
        itemsGroup.appendChild(_renderHint(t('navMenu.itemsHint')));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: t('navMenu.addItem'),
            minRows: 1,
            initialRows: [
                { labelKey: '', href: '/', external: false },
                { labelKey: '', href: '', external: false },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                var hrefEl = contentEl.querySelector('.qs-nav-row__href');
                var extEl  = contentEl.querySelector('.qs-nav-row__external');
                return {
                    labelKey: rec.label ? (rec.label.getValue() || '').trim() : '',
                    href: hrefEl ? hrefEl.value.trim() : '',
                    external: !!(extEl && extEl.checked),
                };
            }
        });

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.labelKey !== '' || r.href !== ''; });
            return { items: rows };
        }

        function validate() {
            var cfg = getConfig();
            if (cfg.items.length === 0) return t('navMenu.errors.noItem');
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                var nth = { number: QSPanelWords.number(i + 1) };
                if (!it.labelKey) return t('navMenu.errors.labelKey', nth);
                if (!it.href) return t('navMenu.errors.href', nth);
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
    window.QSComplexWizard.registry['nav-menu'] = {
        label: t('navMenu.label'),
        description: t('navMenu.description'),
        renderWizard: renderWizard
    };
})();
