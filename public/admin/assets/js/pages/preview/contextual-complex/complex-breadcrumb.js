/**
 * Breadcrumb wizard — kind 'breadcrumb'.
 *
 * Emits <nav aria-label="Breadcrumb">/<ol class="breadcrumb"> with N
 * <li>. Every item except the last is an <a>; the last is plain text
 * with aria-current="page".
 *
 * Wizard mirrors the Nav-menu shape (labelKey picker + href input per
 * row) with one twist: the LAST row's href field is greyed out + its
 * label says "(current page — href ignored)". This is purely UX hint;
 * the builder ignores the last item's href regardless.
 *
 * Server-side builder: secure/src/classes/complexElements/Breadcrumb.php
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
        row.className = 'qs-bc-row';
        row.style.display = 'flex';
        row.style.flexDirection = 'column';
        row.style.gap = '4px';

        var labelMount = document.createElement('div');
        labelMount.className = 'qs-bc-row__label-mount';

        var hrefInput = document.createElement('input');
        hrefInput.type = 'text';
        hrefInput.className = 'admin-input qs-bc-row__href';
        hrefInput.placeholder = 'href (e.g. /docs)';
        hrefInput.autocomplete = 'off';
        if (d.href !== undefined) hrefInput.value = d.href;
        // Attach the shared routes datalist (autocomplete only — external
        // URLs still work freely; the last crumb's href is disabled below
        // by _markLastAsCurrent regardless).
        if (window.QSComplexWizard && typeof window.QSComplexWizard.ensureRoutesDatalist === 'function') {
            window.QSComplexWizard.ensureRoutesDatalist().then(function (id) {
                hrefInput.setAttribute('list', id);
            });
        }

        row.appendChild(labelMount);
        row.appendChild(hrefInput);

        var picker = window.QSComplexWizard.createTextKeyPicker({
            container: labelMount,
            placeholder: 'label key (e.g. nav.docs)',
            value: d.labelKey || '',
        });
        pickerMap.set(row, { label: picker });
        return row;
    }

    // Visually mark the last row as the current-page entry. Re-applies
    // on every editor onChange so it follows row add/remove/reorder.
    function _markLastAsCurrent(itemsHost) {
        var contents = itemsHost.querySelectorAll(':scope > .qs-wizard-row > .qs-wizard-row__content');
        contents.forEach(function (row, idx) {
            var hrefEl = row.querySelector('.qs-bc-row__href');
            if (!hrefEl) return;
            var isLast = (idx === contents.length - 1);
            hrefEl.disabled = isLast;
            hrefEl.placeholder = isLast
                ? '(ignored — last item is the current page)'
                : 'href (e.g. /docs)';
            if (isLast) {
                hrefEl.style.opacity = '0.5';
            } else {
                hrefEl.style.opacity = '';
            }
        });
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--breadcrumb';

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel('Crumbs', true));
        itemsGroup.appendChild(_renderHint('Top-to-bottom = left-to-right in the rendered breadcrumb. Last item = current page; its href is ignored.'));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: '+ Add crumb',
            minRows: 1,
            initialRows: [
                { labelKey: '', href: '/' },
                { labelKey: '', href: '' },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                var hrefEl = contentEl.querySelector('.qs-bc-row__href');
                return {
                    labelKey: rec.label ? (rec.label.getValue() || '').trim() : '',
                    href: hrefEl ? hrefEl.value.trim() : '',
                };
            },
            onChange: function () { _markLastAsCurrent(itemsHost); }
        });
        _markLastAsCurrent(itemsHost);

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.labelKey !== '' || r.href !== ''; });
            return { items: rows };
        }

        function validate() {
            var cfg = getConfig();
            if (cfg.items.length === 0) return 'Add at least one crumb.';
            var lastIdx = cfg.items.length - 1;
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                if (!it.labelKey) return 'Crumb #' + (i + 1) + ' is missing a label key.';
                // The LAST crumb may have an empty href (it's the current page);
                // every earlier crumb must have one.
                if (i !== lastIdx && !it.href) {
                    return 'Crumb #' + (i + 1) + ' is missing an href.';
                }
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
    window.QSComplexWizard.registry['breadcrumb'] = {
        label: 'Breadcrumb',
        description: 'A &lt;nav aria-label="Breadcrumb"&gt;/&lt;ol&gt; with N items. The last item renders as plain text (aria-current="page") with no link.',
        renderWizard: renderWizard
    };
})();
