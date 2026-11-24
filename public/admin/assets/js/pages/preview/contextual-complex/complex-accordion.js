/**
 * Accordion wizard — kind 'accordion'.
 *
 * Emits a <div class="accordion"> wrapping N <details> elements, each
 * with a <summary> (summary text key) and a <p> (content text key).
 * Per-item "open by default" toggle adds the `open` attribute.
 *
 * Server-side builder: secure/src/classes/complexElements/Accordion.php
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
        row.style.gap = '6px';

        // Top: summary picker (full width).
        var summaryWrap = document.createElement('div');
        var summaryLabel = document.createElement('div');
        summaryLabel.className = 'admin-hint';
        summaryLabel.textContent = t('accordion.summary');
        var summaryMount = document.createElement('div');
        summaryWrap.appendChild(summaryLabel);
        summaryWrap.appendChild(summaryMount);

        // Middle: content picker (full width).
        var contentWrap = document.createElement('div');
        var contentLabel = document.createElement('div');
        contentLabel.className = 'admin-hint';
        contentLabel.textContent = t('accordion.content');
        var contentMount = document.createElement('div');
        contentWrap.appendChild(contentLabel);
        contentWrap.appendChild(contentMount);

        // Bottom: openByDefault checkbox.
        var openLabel = document.createElement('label');
        openLabel.className = 'admin-checkbox';
        var openCb = document.createElement('input');
        openCb.type = 'checkbox';
        openCb.className = 'qs-accordion-row__open';
        if (d.openByDefault) openCb.checked = true;
        var openSpan = document.createElement('span');
        openSpan.textContent = t('accordion.openByDefault');
        openLabel.appendChild(openCb);
        openLabel.appendChild(openSpan);

        row.appendChild(summaryWrap);
        row.appendChild(contentWrap);
        row.appendChild(openLabel);

        var summaryPicker = window.QSComplexWizard.createTextKeyPicker({
            container: summaryMount,
            placeholder: t('accordion.summaryPlaceholder'),
            value: d.summaryKey || '',
        });
        var contentPicker = window.QSComplexWizard.createTextKeyPicker({
            container: contentMount,
            placeholder: t('accordion.contentPlaceholder'),
            value: d.contentKey || '',
        });
        pickerMap.set(row, { summary: summaryPicker, content: contentPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel(t('common.items'), true));
        itemsGroup.appendChild(_renderHint(t('accordion.itemsHint')));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: t('common.addItem'),
            minRows: 1,
            initialRows: [
                { summaryKey: '', contentKey: '', openByDefault: false },
                { summaryKey: '', contentKey: '', openByDefault: false },
            ],
            renderRow: function (data) { return _renderItemRow(data, pickerMap); },
            readRow: function (contentEl) {
                var rec = pickerMap.get(contentEl) || {};
                var openCb = contentEl.querySelector('.qs-accordion-row__open');
                return {
                    summaryKey: rec.summary ? (rec.summary.getValue() || '').trim() : '',
                    contentKey: rec.content ? (rec.content.getValue() || '').trim() : '',
                    openByDefault: !!(openCb && openCb.checked),
                };
            }
        });

        function getConfig() {
            var rows = editor.getRows()
                .filter(function (r) { return r.summaryKey !== '' || r.contentKey !== ''; });
            return { items: rows };
        }

        function validate() {
            var cfg = getConfig();
            if (cfg.items.length === 0) return t('accordion.errors.noItem');
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                var nth = { number: QSPanelWords.number(i + 1) };
                if (!it.summaryKey) return t('accordion.errors.summaryKey', nth);
                if (!it.contentKey) return t('accordion.errors.contentKey', nth);
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
    window.QSComplexWizard.registry['accordion'] = {
        label: t('accordion.label'),
        description: t('accordion.description'),
        renderWizard: renderWizard
    };
})();
