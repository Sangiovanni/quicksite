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
        row.className = 'qs-accordion-row';
        row.style.display = 'flex';
        row.style.flexDirection = 'column';
        row.style.gap = '6px';

        // Top: summary picker (full width).
        var summaryWrap = document.createElement('div');
        var summaryLabel = document.createElement('div');
        summaryLabel.className = 'admin-hint';
        summaryLabel.textContent = 'Summary (title shown when collapsed)';
        var summaryMount = document.createElement('div');
        summaryMount.className = 'qs-accordion-row__summary-mount';
        summaryWrap.appendChild(summaryLabel);
        summaryWrap.appendChild(summaryMount);

        // Middle: content picker (full width).
        var contentWrap = document.createElement('div');
        var contentLabel = document.createElement('div');
        contentLabel.className = 'admin-hint';
        contentLabel.textContent = 'Content (text shown when expanded — wrapped in <p>)';
        var contentMount = document.createElement('div');
        contentMount.className = 'qs-accordion-row__content-mount';
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
        openSpan.textContent = 'Open by default';
        openLabel.appendChild(openCb);
        openLabel.appendChild(openSpan);

        row.appendChild(summaryWrap);
        row.appendChild(contentWrap);
        row.appendChild(openLabel);

        var summaryPicker = window.QSComplexWizard.createTextKeyPicker({
            container: summaryMount,
            placeholder: 'e.g. faq.shipping.q1',
            value: d.summaryKey || '',
        });
        var contentPicker = window.QSComplexWizard.createTextKeyPicker({
            container: contentMount,
            placeholder: 'e.g. faq.shipping.a1',
            value: d.contentKey || '',
        });
        pickerMap.set(row, { summary: summaryPicker, content: contentPicker });
        return row;
    }

    function renderWizard(container) {
        var wrap = document.createElement('div');
        wrap.className = 'qs-complex-wizard qs-complex-wizard--accordion';

        var itemsGroup = _renderGroup();
        itemsGroup.appendChild(_renderLabel('Items', true));
        itemsGroup.appendChild(_renderHint('Each item is a (summary, content) pair rendered as a native <details>/<summary> disclosure widget.'));
        var itemsHost = document.createElement('div');
        itemsGroup.appendChild(itemsHost);
        wrap.appendChild(itemsGroup);

        container.appendChild(wrap);

        var pickerMap = new WeakMap();
        var editor = window.QSComplexWizard.createRowEditor({
            container: itemsHost,
            addButtonLabel: '+ Add item',
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
            if (cfg.items.length === 0) return 'Add at least one item.';
            for (var i = 0; i < cfg.items.length; i++) {
                var it = cfg.items[i];
                if (!it.summaryKey) return 'Item #' + (i + 1) + ' is missing a summary key.';
                if (!it.contentKey) return 'Item #' + (i + 1) + ' is missing a content key.';
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
        label: 'Accordion (FAQ)',
        description: 'A &lt;div class="accordion"&gt; with N native &lt;details&gt;/&lt;summary&gt; disclosure items. No JS or ARIA wiring — the browser handles toggling.',
        renderWizard: renderWizard
    };
})();
