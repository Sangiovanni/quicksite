/**
 * data-attr-picker.js — autocomplete for the Add Element wizard's
 * Advanced custom-params section. When the user types `data-` in a
 * KEY input, a dropdown shows matching attributes from the
 * QuickSite-runtime catalog (loaded once from listDataBindings).
 *
 * Public API (under window.QSComplexWizard):
 *
 *   QSComplexWizard.attachDataAttrPicker(keyInput, valueInput?, opts?)
 *     Attaches autocomplete behaviour to one KEY input. When the
 *     user types `data-`, a dropdown opens listing matching catalog
 *     entries (grouped by category). Picking one fills the key input
 *     and reveals a description box just below the row showing what
 *     the attribute does + the value placeholder + example payload.
 *
 *     The dropdown is purely a SUGGESTION — the user can type any
 *     `data-*` (or any other key) and the form still accepts it.
 *
 *     keyInput   HTMLInputElement   the KEY field (required)
 *     valueInput HTMLInputElement   optional VALUE field — its
 *                                   placeholder updates to the picked
 *                                   attribute's valuePlaceholder so
 *                                   the user knows what to type
 *     opts       Object             reserved for slice 5+ extensions
 *
 *   QSComplexWizard.ensureDataAttrCatalog() → Promise<Array>
 *     Resolves to the user-facing catalog entries. Cached project-
 *     wide (one fetch per editor session). Used internally by the
 *     picker; exposed for any caller that wants the raw list.
 *
 *   QSComplexWizard.invalidateDataAttrCatalog()
 *     Drops the cache. Call this if the catalog file is edited
 *     mid-session (rare; mostly for the dev workflow).
 *
 * Design notes:
 *   - One floating dropdown <div> per session, reused across all
 *     attached inputs (kept under document.body, repositioned on
 *     each open).
 *   - DOM via createElement + textContent per CLAUDE.md HTML-in-JS
 *     hygiene. No innerHTML string-gluing of catalog values
 *     (description / example) — catalog values are project-internal
 *     today but using textContent is the right default.
 *   - Description box is appended as a SIBLING to the row (NOT
 *     inside it) so it doesn't fight the row's flex layout. The
 *     attach call inserts it immediately after the row.
 *   - "Internal" entries (editor chrome) are NOT shown to users.
 *     The catalog already filters them via listDataBindings without
 *     /all; we just consume what the command returns.
 *
 * Loaded by: secure/admin/templates/pages/preview-config.php BEFORE
 * any complex-*.js file (sits next to route-input.js / wizard-row-
 * editor.js / text-key-picker.js — shared primitives, load order
 * matters: shared first, then per-kind).
 */
(function () {
    'use strict';

    var DROPDOWN_ID = 'qs-data-attr-picker-dropdown';
    var DESC_CLASS = 'qs-data-attr-picker-desc';

    // ─── Catalog cache ──────────────────────────────────────────────
    var catalogPromise = null;

    function fetchCatalog() {
        if (catalogPromise) return catalogPromise;
        catalogPromise = QuickSiteAdmin.apiRequest('listDataBindings', 'GET')
            .then(function (result) {
                if (!result.ok || !result.data || !result.data.data) {
                    console.warn('[data-attr-picker] listDataBindings failed; autocomplete disabled');
                    return { entries: [], by_category: {} };
                }
                return {
                    entries: result.data.data.entries || [],
                    by_category: result.data.data.by_category || {},
                };
            })
            .catch(function (err) {
                console.warn('[data-attr-picker] error loading catalog:', err);
                return { entries: [], by_category: {} };
            });
        return catalogPromise;
    }

    function invalidateCatalog() {
        catalogPromise = null;
    }

    // ─── Floating dropdown ──────────────────────────────────────────
    var dropdownEl = null;
    var currentInput = null;     // input the dropdown is attached to
    var currentRow = null;       // row containing currentInput (for desc-box placement)
    var currentValueInput = null;

    function ensureDropdown() {
        if (dropdownEl && document.body.contains(dropdownEl)) return dropdownEl;
        dropdownEl = document.createElement('div');
        dropdownEl.id = DROPDOWN_ID;
        dropdownEl.setAttribute('role', 'listbox');
        dropdownEl.style.position = 'absolute';
        dropdownEl.style.zIndex = '10000';
        dropdownEl.style.background = 'var(--admin-bg-secondary, #1f1f1f)';
        dropdownEl.style.border = '1px solid var(--admin-border, #444)';
        dropdownEl.style.borderRadius = '4px';
        dropdownEl.style.maxHeight = '300px';
        dropdownEl.style.overflowY = 'auto';
        dropdownEl.style.minWidth = '280px';
        dropdownEl.style.maxWidth = '420px';
        dropdownEl.style.boxShadow = '0 4px 12px rgba(0,0,0,0.4)';
        dropdownEl.style.display = 'none';
        dropdownEl.style.fontSize = '0.85rem';
        document.body.appendChild(dropdownEl);

        // Outside-click dismissal
        document.addEventListener('mousedown', function (e) {
            if (dropdownEl.style.display === 'none') return;
            if (e.target === currentInput) return;
            if (dropdownEl.contains(e.target)) return;
            hideDropdown();
        });

        // Close on Escape (when an input has focus)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dropdownEl.style.display !== 'none') {
                hideDropdown();
            }
        });

        return dropdownEl;
    }

    function positionDropdown(input) {
        // Defensive: positionDropdown may be called before ensureDropdown
        // depending on the call order in openIfRelevant. Initialise here
        // if needed.
        var d = ensureDropdown();
        var rect = input.getBoundingClientRect();
        var viewportHeight = window.innerHeight;
        var spaceBelow = viewportHeight - rect.bottom;
        var spaceAbove = rect.top;
        var preferredMaxHeight = 300;
        var minSpaceForBelow = 200; // need ≥ 200px below to prefer below-mode

        // Smart direction: if there isn't enough room below AND there's more
        // room above, open upward. Otherwise default to opening below.
        // The Add Element wizard's Advanced params live near the bottom of
        // the sidebar, where below-mode forces the user to scroll — flipping
        // up keeps the dropdown in view.
        var openAbove = (spaceBelow < minSpaceForBelow && spaceAbove > spaceBelow);

        if (openAbove) {
            // Anchor the dropdown's BOTTOM edge to (input.top - 2px) via
            // CSS transform: translateY(-100%). The dropdown then grows
            // upward from a fixed bottom point — actual rendered height
            // adapts automatically (a 1-item dropdown sticks just above
            // the input; a full-height one fills the available space).
            // This avoids measuring with JS.
            var maxH = Math.max(120, Math.min(preferredMaxHeight, spaceAbove - 10));
            d.style.maxHeight = maxH + 'px';
            d.style.top = (window.scrollY + rect.top - 2) + 'px';
            d.style.transform = 'translateY(-100%)';
        } else {
            // Below-mode: anchor TOP to (input.bottom + 2px), no transform.
            var maxH2 = Math.max(120, Math.min(preferredMaxHeight, spaceBelow - 10));
            d.style.maxHeight = maxH2 + 'px';
            d.style.top = (window.scrollY + rect.bottom + 2) + 'px';
            d.style.transform = 'none';
        }
        d.style.left = (window.scrollX + rect.left) + 'px';
        // Match input width if possible (with floor / ceiling clamps)
        var w = Math.min(420, Math.max(280, rect.width));
        d.style.width = w + 'px';
    }

    function hideDropdown() {
        if (dropdownEl) dropdownEl.style.display = 'none';
        currentInput = null;
        currentRow = null;
        currentValueInput = null;
    }

    // ─── Render: filtered + grouped list ────────────────────────────
    function renderDropdown(query, catalog) {
        var d = ensureDropdown();
        // Clear without innerHTML
        while (d.firstChild) d.removeChild(d.firstChild);

        var q = (query || '').toLowerCase();
        // Filter: substring match on attribute NAME only. We deliberately do
        // NOT search descriptions — descriptions cross-reference each other
        // ("data-state-empty" mentions "data-state-list" by name), which
        // would leak unrelated entries into the user's filter results.
        // The user is typing an attribute name; show name matches only.
        var matches = catalog.entries.filter(function (e) {
            if (!q) return true;
            return e.name.toLowerCase().indexOf(q) >= 0;
        });

        if (matches.length === 0) {
            var none = document.createElement('div');
            none.style.padding = '8px 12px';
            none.style.color = 'var(--admin-text-muted, #888)';
            none.textContent = 'No matching data-* attributes. You can still type any value — the field is not constrained.';
            d.appendChild(none);
            return;
        }

        // Group filtered matches by category, preserving category order
        var byCategory = {};
        var categoryOrder = [];
        matches.forEach(function (e) {
            var cat = e.category || 'other';
            if (!byCategory[cat]) {
                byCategory[cat] = [];
                categoryOrder.push(cat);
            }
            byCategory[cat].push(e);
        });

        categoryOrder.forEach(function (cat) {
            var hdr = document.createElement('div');
            hdr.style.padding = '6px 12px 4px';
            hdr.style.fontSize = '0.7rem';
            hdr.style.textTransform = 'uppercase';
            hdr.style.letterSpacing = '0.05em';
            hdr.style.color = 'var(--admin-text-muted, #888)';
            hdr.style.background = 'var(--admin-bg-tertiary, #2a2a2a)';
            hdr.style.borderTop = '1px solid var(--admin-border, #444)';
            hdr.textContent = cat;
            d.appendChild(hdr);

            byCategory[cat].forEach(function (entry) {
                d.appendChild(renderEntry(entry));
            });
        });
    }

    function renderEntry(entry) {
        var item = document.createElement('div');
        item.setAttribute('role', 'option');
        item.style.padding = '8px 12px';
        item.style.cursor = 'pointer';
        item.style.borderBottom = '1px solid var(--admin-border, #333)';

        var name = document.createElement('div');
        name.style.fontWeight = '600';
        name.style.color = 'var(--admin-text, #eee)';
        name.style.fontFamily = 'monospace';
        name.textContent = entry.name;
        item.appendChild(name);

        var desc = document.createElement('div');
        desc.style.color = 'var(--admin-text-muted, #aaa)';
        desc.style.fontSize = '0.78rem';
        desc.style.marginTop = '2px';
        // Truncate the dropdown description; full description shows in the
        // info box after selection.
        var d = entry.description || '';
        desc.textContent = d.length > 80 ? d.slice(0, 80) + '…' : d;
        item.appendChild(desc);

        item.addEventListener('mouseenter', function () {
            item.style.background = 'var(--admin-bg-hover, #333)';
        });
        item.addEventListener('mouseleave', function () {
            item.style.background = '';
        });
        item.addEventListener('mousedown', function (e) {
            // mousedown (not click) so the input doesn't lose focus + close
            // the dropdown before we handle the pick
            e.preventDefault();
            pickEntry(entry);
        });

        return item;
    }

    // ─── Pick: write key + install in-row widget + show desc box ────
    function pickEntry(entry) {
        if (!currentInput) return;
        currentInput.value = entry.name;
        // Remember what was picked on this row so openIfRelevant can detect
        // when the user edits away (e.g. types past the picked name) and
        // tear down the widget + description box accordingly.
        if (currentRow) currentRow.dataset.dataAttrPicked = entry.name;
        // NOTE: deliberately do NOT dispatch a synthetic 'input' here.
        // It would re-trigger our own openIfRelevant listener (key value
        // still starts with 'data-'), reopening the dropdown only to be
        // hidden again at the end of this function. Other listeners that
        // need the value can read currentInput.value directly.

        if (currentValueInput) {
            // Clean up any previous widget rendered for an earlier pick
            // on this row (also unhides the value input).
            removeWidgetFromRow(currentRow, currentValueInput);

            if (entry.valuePlaceholder) {
                currentValueInput.setAttribute('placeholder', entry.valuePlaceholder);
            }
            // Render and install widget IN THE ROW (replacing the value
            // input visually). Widget writes to the hidden value input so
            // form serialisation works unchanged.
            var widget = renderValueWidget(entry, currentValueInput);
            if (widget) {
                installWidgetInRow(currentRow, currentValueInput, widget);
            }
        }

        showDescriptionBox(entry);
        hideDropdown();
    }

    // ─── In-row widget install / teardown ───────────────────────────
    var WIDGET_CLASS = 'qs-data-attr-widget';

    function installWidgetInRow(row, valueInput, widgetEl) {
        if (!row || !valueInput || !widgetEl) return;
        widgetEl.classList.add(WIDGET_CLASS);
        // Hide the original input via CSS (keeps it in the DOM so the
        // form serialiser still reads `.preview-contextual-form__param-value`).
        valueInput.style.display = 'none';
        valueInput.parentNode.insertBefore(widgetEl, valueInput.nextSibling);
    }

    function removeWidgetFromRow(row, valueInput) {
        if (!row) return;
        var existing = row.querySelector('.' + WIDGET_CLASS);
        if (existing) existing.remove();
        if (valueInput) valueInput.style.display = '';
    }

    function removeDescriptionBoxAfter(row) {
        if (!row) return;
        var next = row.nextElementSibling;
        if (next && next.classList && next.classList.contains(DESC_CLASS)) {
            next.remove();
        }
    }

    // ─── Description box (appears under the row) ────────────────────
    function showDescriptionBox(entry) {
        if (!currentRow) return;
        // Remove any existing description box from this row's sibling
        var existing = currentRow.nextElementSibling;
        if (existing && existing.classList && existing.classList.contains(DESC_CLASS)) {
            existing.remove();
        }

        var box = document.createElement('div');
        box.className = DESC_CLASS;
        box.style.margin = '4px 0 8px';
        box.style.padding = '8px 12px';
        box.style.background = 'var(--admin-bg-tertiary, #2a2a2a)';
        box.style.border = '1px solid var(--admin-border, #444)';
        box.style.borderLeft = '3px solid var(--admin-accent, #5a9fd4)';
        box.style.borderRadius = '3px';
        box.style.fontSize = '0.8rem';

        // Description text
        var desc = document.createElement('div');
        desc.style.color = 'var(--admin-text, #ddd)';
        desc.textContent = entry.description || '';
        box.appendChild(desc);

        // Optional value placeholder hint
        if (entry.valuePlaceholder) {
            var vh = document.createElement('div');
            vh.style.marginTop = '6px';
            vh.style.color = 'var(--admin-text-muted, #aaa)';
            vh.style.fontFamily = 'monospace';
            vh.style.fontSize = '0.75rem';
            var vhLabel = document.createElement('span');
            vhLabel.style.color = 'var(--admin-text-muted, #888)';
            vhLabel.style.fontFamily = 'sans-serif';
            vhLabel.textContent = 'Value shape: ';
            vh.appendChild(vhLabel);
            vh.appendChild(document.createTextNode(entry.valuePlaceholder));
            box.appendChild(vh);
        }

        // Companion hint (light version — slice 7 will add an action button)
        if (entry.companion && entry.companion.length) {
            var ch = document.createElement('div');
            ch.style.marginTop = '6px';
            ch.style.color = 'var(--admin-text-muted, #aaa)';
            ch.style.fontSize = '0.75rem';
            var chLabel = document.createElement('span');
            chLabel.style.color = 'var(--admin-text-muted, #888)';
            chLabel.textContent = 'Often paired with: ';
            ch.appendChild(chLabel);
            var first = true;
            entry.companion.forEach(function (n) {
                if (!first) ch.appendChild(document.createTextNode(', '));
                first = false;
                var code = document.createElement('code');
                code.textContent = n;
                ch.appendChild(code);
            });
            box.appendChild(ch);
        }

        // Smart value-field widget rendering moved INTO the row
        // (slice 5 final shape, 2026-06-02). See pickEntry +
        // installWidgetInRow above. The description box now contains
        // description / valueShape hint / companions only.

        // Insert after the row
        currentRow.parentNode.insertBefore(box, currentRow.nextSibling);
    }

    // ─── Smart value-field widgets (slice 5) ────────────────────────
    //
    // Dispatcher: pick the right widget based on entry.valueShape.
    // Returns the widget element (or null if no widget applies — value
    // input remains the only authoring surface).

    function renderValueWidget(entry, valueInput) {
        if (!valueInput) return null;
        switch (entry.valueShape) {
            case 'enum':           return renderEnumWidget(entry, valueInput);
            case 'store-field-ref':return renderStoreFieldWidget(entry, valueInput);
            case 'storage-spec':   return renderStorageSpecWidget(entry, valueInput);
            default:               return null;  // plain-string / selector / dot-path → text input is fine
        }
    }

    // --- enum widget --------------------------------------------------
    // For attributes whose value is a constrained list (valueOptions).
    // Examples: data-auth-show (in/out), data-state-pagenav-prev-next
    // (true/false), data-list-template (true), data-qs-complex (table).

    function renderEnumWidget(entry, valueInput) {
        if (!entry.valueOptions || !entry.valueOptions.length) return null;
        var sel = document.createElement('select');
        sel.className = 'admin-select admin-select--sm';
        sel.style.maxWidth = '260px';

        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = '(pick a value)';
        sel.appendChild(ph);
        entry.valueOptions.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v;
            o.textContent = v;
            sel.appendChild(o);
        });

        // Pre-select if the input already holds one of the valid options.
        if (entry.valueOptions.indexOf(valueInput.value) >= 0) {
            sel.value = valueInput.value;
        }
        sel.addEventListener('change', function () {
            valueInput.value = sel.value;
            valueInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        return sel;
    }

    // --- store-field-ref widget --------------------------------------
    // For attributes whose value is `storeId.fieldName` (data-state-value,
    // -list, -show, etc.) OR just `storeId` (data-state-pagenav). We
    // detect the simpler shape by `valuePlaceholder === 'storeId'`.
    //
    // Fetches the current page's state stores once (cached project-wide),
    // renders two cascading <select>s. If the current page has no stores
    // OR the user is not on a page (menu / footer / component context),
    // renders a hint instead.

    function renderStoreFieldWidget(entry, valueInput) {
        var wantsField = entry.valuePlaceholder !== 'storeId';  // 'storeId.fieldName' or anything else assumed to need a field

        var wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.gap = '6px';
        wrap.style.alignItems = 'center';
        wrap.style.flexWrap = 'wrap';

        // Loading placeholder
        var loading = document.createElement('div');
        loading.style.color = 'var(--admin-text-muted, #888)';
        loading.style.fontSize = '0.75rem';
        loading.textContent = 'Loading state stores…';
        wrap.appendChild(loading);

        fetchCurrentPageStores().then(function (stores) {
            wrap.removeChild(loading);

            var storeNames = Object.keys(stores);
            if (storeNames.length === 0) {
                var empty = document.createElement('div');
                empty.style.color = 'var(--admin-text-muted, #888)';
                empty.style.fontSize = '0.75rem';
                empty.textContent = 'No state stores on this page yet. Create one in the State stores panel (JS mode), then come back to bind.';
                wrap.appendChild(empty);
                return;
            }

            var storeSel = document.createElement('select');
            storeSel.className = 'admin-select admin-select--sm';
            storeSel.style.maxWidth = '160px';
            var phS = document.createElement('option');
            phS.value = '';
            phS.textContent = '(pick store)';
            storeSel.appendChild(phS);
            storeNames.forEach(function (sid) {
                var o = document.createElement('option');
                o.value = sid;
                o.textContent = sid;
                storeSel.appendChild(o);
            });
            wrap.appendChild(storeSel);

            var fieldSel = null;
            if (wantsField) {
                var dot = document.createElement('span');
                dot.textContent = '.';
                dot.style.color = 'var(--admin-text-muted, #888)';
                wrap.appendChild(dot);

                fieldSel = document.createElement('select');
                fieldSel.className = 'admin-select admin-select--sm';
                fieldSel.style.maxWidth = '160px';
                fieldSel.disabled = true;
                var phF = document.createElement('option');
                phF.value = '';
                phF.textContent = '(pick field)';
                fieldSel.appendChild(phF);
                wrap.appendChild(fieldSel);
            }

            // Parse existing value if any. Format: storeId  OR  storeId.fieldName
            var current = valueInput.value || '';
            var dotIdx = current.indexOf('.');
            var preStore = dotIdx > 0 ? current.substring(0, dotIdx) : current;
            var preField = dotIdx > 0 ? current.substring(dotIdx + 1) : '';
            if (storeNames.indexOf(preStore) >= 0) {
                storeSel.value = preStore;
                if (fieldSel) populateFieldOptions(stores, preStore, fieldSel, preField);
            }

            function compose() {
                if (!wantsField) {
                    valueInput.value = storeSel.value;
                } else {
                    valueInput.value = storeSel.value && fieldSel.value
                        ? storeSel.value + '.' + fieldSel.value
                        : storeSel.value || '';
                }
                valueInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            storeSel.addEventListener('change', function () {
                if (fieldSel) populateFieldOptions(stores, storeSel.value, fieldSel, '');
                compose();
            });
            if (fieldSel) fieldSel.addEventListener('change', compose);
        });

        return wrap;
    }

    function populateFieldOptions(stores, storeId, fieldSel, preSelectField) {
        // Clear all but the placeholder
        while (fieldSel.childNodes.length > 1) fieldSel.removeChild(fieldSel.lastChild);
        var store = stores[storeId];
        if (!store || !store.fields) {
            fieldSel.disabled = true;
            return;
        }
        fieldSel.disabled = false;
        Object.keys(store.fields).forEach(function (fname) {
            var o = document.createElement('option');
            o.value = fname;
            o.textContent = fname;
            fieldSel.appendChild(o);
        });
        if (preSelectField && Object.keys(store.fields).indexOf(preSelectField) >= 0) {
            fieldSel.value = preSelectField;
        }
    }

    // --- storage-spec widget -----------------------------------------
    // For attributes whose value is a storage-spec string:
    //   `localStorage:key` / `sessionStorage:key`              (data-storage-value, data-auth-source)
    //   `has:localStorage:key` / `missing:localStorage:key`    (data-storage-show)
    //
    // The mode prefix is opt-in: detect by the entry's valuePlaceholder.

    function renderStorageSpecWidget(entry, valueInput) {
        // Detect the two flavours via the catalog's valuePlaceholder.
        // A placeholder starting with `has:` (or `missing:`) means this
        // attribute uses the 3-part shape; otherwise it's the 2-part shape.
        var ph = entry.valuePlaceholder || '';
        var hasMode = ph.indexOf('has:') === 0 || ph.indexOf('missing:') === 0;

        // Outer vertical wrapper: controls row above, warning row below
        // (warning hidden by default; shown when key collides with admin).
        var outer = document.createElement('div');
        outer.style.display = 'flex';
        outer.style.flexDirection = 'column';
        outer.style.gap = '4px';
        outer.style.flex = '1 1 auto';

        var wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.gap = '6px';
        wrap.style.alignItems = 'center';
        wrap.style.flexWrap = 'wrap';
        outer.appendChild(wrap);

        var modeSel = null;
        if (hasMode) {
            modeSel = document.createElement('select');
            modeSel.className = 'admin-select admin-select--sm';
            modeSel.style.maxWidth = '110px';
            ['has', 'missing'].forEach(function (m) {
                var o = document.createElement('option');
                o.value = m;
                o.textContent = m;
                modeSel.appendChild(o);
            });
            wrap.appendChild(modeSel);

            var colon1 = document.createElement('span');
            colon1.textContent = ':';
            colon1.style.color = 'var(--admin-text-muted, #888)';
            wrap.appendChild(colon1);
        }

        var storageSel = document.createElement('select');
        storageSel.className = 'admin-select admin-select--sm';
        storageSel.style.maxWidth = '150px';
        ['localStorage', 'sessionStorage'].forEach(function (s) {
            var o = document.createElement('option');
            o.value = s;
            o.textContent = s;
            storageSel.appendChild(o);
        });
        wrap.appendChild(storageSel);

        var colon2 = document.createElement('span');
        colon2.textContent = ':';
        colon2.style.color = 'var(--admin-text-muted, #888)';
        wrap.appendChild(colon2);

        var keyInp = document.createElement('input');
        keyInp.type = 'text';
        keyInp.className = 'admin-input admin-input--sm';
        keyInp.placeholder = 'key';
        keyInp.style.maxWidth = '180px';
        wrap.appendChild(keyInp);

        // Warning element for admin-key collisions
        var warningEl = document.createElement('div');
        warningEl.style.fontSize = '0.72rem';
        warningEl.style.lineHeight = '1.3';
        warningEl.style.display = 'none';
        outer.appendChild(warningEl);

        // Parse existing valueInput value, if any
        var current = valueInput.value || '';
        if (current) {
            var parts = current.split(':');
            if (hasMode && parts.length >= 3) {
                if (['has', 'missing'].indexOf(parts[0]) >= 0) modeSel.value = parts[0];
                if (['localStorage', 'sessionStorage'].indexOf(parts[1]) >= 0) storageSel.value = parts[1];
                keyInp.value = parts.slice(2).join(':');  // permit colons in key (rare)
            } else if (!hasMode && parts.length >= 2) {
                if (['localStorage', 'sessionStorage'].indexOf(parts[0]) >= 0) storageSel.value = parts[0];
                keyInp.value = parts.slice(1).join(':');
            }
        }

        function applyValidation() {
            // Block any key matching a reserved admin-namespace prefix.
            // Returns true when the key is OK to compose, false when blocked.
            var v = keyInp.value;
            if (v && isReservedStorageKey(v)) {
                keyInp.style.borderColor = 'var(--admin-danger, #d44)';
                keyInp.setAttribute('title', RESERVED_KEY_MESSAGE);
                warningEl.style.color = 'var(--admin-danger, #d44)';
                warningEl.textContent = '⛔ ' + RESERVED_KEY_MESSAGE;
                warningEl.style.display = 'block';
                return false;
            }
            keyInp.style.borderColor = '';
            keyInp.removeAttribute('title');
            warningEl.style.display = 'none';
            return true;
        }

        function compose() {
            var ok = applyValidation();
            // For blocked keys: clear the composed value so the form doesn't
            // carry a dangerous reference even if the user saves. The key
            // input still shows what they typed (so they can correct it).
            if (!ok) {
                valueInput.value = '';
                valueInput.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }
            var v;
            if (hasMode) {
                v = modeSel.value + ':' + storageSel.value + ':' + keyInp.value;
            } else {
                v = storageSel.value + ':' + keyInp.value;
            }
            valueInput.value = v;
            valueInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (modeSel) modeSel.addEventListener('change', compose);
        storageSel.addEventListener('change', compose);
        keyInp.addEventListener('input', compose);

        // Pre-validate on render so a pre-existing bad key surfaces the
        // warning immediately (not only on next keystroke).
        applyValidation();

        return outer;
    }

    // ─── Reserved storage-key namespace ─────────────────────────────
    //
    // The admin panel and the user's site share the browser's storage
    // origin. If a user authors `data-storage-value="localStorage:<X>"`
    // where <X> collides with an admin-side key (auth tokens, AI
    // settings, etc.), the rendered page can READ or CLEAR the admin's
    // state. To prevent this, we reserve four naming prefixes that the
    // admin uses and BLOCK user keys matching them.
    //
    // Single regex, no per-key audit / drift. Catches:
    //   quicksite_*  (snake)   e.g. quicksite_admin_token
    //   quicksite-*  (kebab)   e.g. quicksite-tools-show-names
    //   qs_*         (snake)   e.g. qs_api_auth_tokens
    //   qs-*         (kebab)   e.g. qs-add-last-tab
    //
    // The user's site keys should use a project-specific prefix (their
    // app's name) — there's no good reason to share namespace with the
    // admin.
    //
    // SECURITY NOTE: this is the client-side check (UX). Defence in
    // depth requires the SERVER to refuse the same patterns when
    // writing structure params — see secure/src/functions/reservedStorageKeys.php
    // (sub-slice 5b, planned). Without the server check, a user with a
    // valid token can POST directly to addNode/editStructure and bypass
    // this picker entirely.

    var RESERVED_KEY_PATTERN = /^(quicksite[_-]|qs[_-])/i;
    var RESERVED_KEY_MESSAGE = 'Reserved admin-namespace prefix (quicksite_ / quicksite- / qs_ / qs-). These are used by the admin panel and would collide with admin state. Pick a project-specific prefix.';

    function isReservedStorageKey(key) {
        return !!key && RESERVED_KEY_PATTERN.test(key);
    }

    // ─── State-store fetch helper (shared with paged-navigator pattern) ───
    var storesPromise = null;

    function _getCurrentRoute() {
        try {
            if (window.PreviewState && typeof window.PreviewState.get === 'function') {
                var ss = window.PreviewState.get('selectedStruct');
                if (typeof ss === 'string' && ss.indexOf('page-') === 0) {
                    return ss.substring('page-'.length);
                }
            }
        } catch (e) {}
        return null;
    }

    function fetchCurrentPageStores() {
        if (storesPromise) return storesPromise;
        var api = window.QuickSiteAdmin;
        var route = _getCurrentRoute();
        if (!api || !route) {
            storesPromise = Promise.resolve({});
            return storesPromise;
        }
        storesPromise = api.apiRequest('getStateStores', 'POST', { route: route }).then(function (res) {
            var payload = (res && res.data && (res.data.data || res.data)) || {};
            var stores = payload.stores;
            if (!stores || typeof stores !== 'object' || Array.isArray(stores)) return {};
            return stores;
        }).catch(function (err) {
            console.warn('[data-attr-picker] getStateStores failed:', err);
            return {};
        });
        return storesPromise;
    }

    // Invalidate the cache if state stores get mutated mid-session
    // (e.g. user adds a store in the JS-mode panel, then comes back here).
    // Not auto-wired today; expose for future use.
    function invalidateStoresCache() { storesPromise = null; }

    // ─── Attach to a KEY input ──────────────────────────────────────
    function attachPicker(keyInput, valueInput, opts) {
        if (!keyInput) return;
        if (keyInput.dataset.dataAttrPickerAttached === '1') return;
        keyInput.dataset.dataAttrPickerAttached = '1';

        // Find the row this input lives in (for description-box placement)
        var row = keyInput.closest('.preview-contextual-form__param-row');

        function openIfRelevant() {
            var v = keyInput.value || '';

            // Tear down the in-row widget + description box if the user has
            // edited the key away from a previously-picked attribute (e.g.
            // cleared the field, retyped, typed extra characters past the
            // picked name). This restores the plain value text input.
            var picked = row && row.dataset.dataAttrPicked;
            if (picked && v !== picked) {
                removeWidgetFromRow(row, valueInput);
                removeDescriptionBoxAfter(row);
                delete row.dataset.dataAttrPicked;
            }

            // Open ONLY when the typed value is either:
            //   (a) a full `data-` prefix or beyond (e.g. "data-", "data-s", "data-state-show")
            //   (b) a partial of "data-" itself (e.g. "d", "da", "dat", "data")
            // Empty input does NOT open the dropdown (avoid surprising the
            // user who just clicked into the field). Typing anything else
            // (e.g. "class") doesn't open either.
            var isDataPath = v.length > 0 && (v.indexOf('data-') === 0 || 'data-'.indexOf(v) === 0);
            if (!isDataPath) {
                hideDropdown();
                return;
            }
            currentInput = keyInput;
            currentRow = row;
            currentValueInput = valueInput || null;
            // Ensure the dropdown element exists BEFORE positioning / rendering.
            // (Race fixed 2026-06-02: positionDropdown previously assumed
            // dropdownEl was already created.)
            var d = ensureDropdown();
            fetchCatalog().then(function (catalog) {
                if (currentInput !== keyInput) return; // raced; another input took over
                // The query for filtering is the post-"data-" suffix. While
                // the user is still typing toward "data-" (e.g. v = "data"),
                // the query is empty so the dropdown shows ALL entries —
                // which is fine: at that point they've signalled intent and
                // the full list is useful for browsing.
                var query = (v.indexOf('data-') === 0) ? v.replace(/^data-/, '') : '';
                positionDropdown(keyInput);
                renderDropdown(query, catalog);
                d.style.display = 'block';
            });
        }

        keyInput.addEventListener('input', openIfRelevant);
        keyInput.addEventListener('focus', openIfRelevant);
        keyInput.addEventListener('blur', function () {
            // Delayed so a click on the dropdown can register first
            setTimeout(function () {
                if (currentInput === keyInput) hideDropdown();
            }, 150);
        });
    }

    // ─── Public API registration ────────────────────────────────────
    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.attachDataAttrPicker = attachPicker;
    window.QSComplexWizard.ensureDataAttrCatalog = function () {
        return fetchCatalog().then(function (c) { return c.entries; });
    };
    window.QSComplexWizard.invalidateDataAttrCatalog = invalidateCatalog;
    // Slice 5: state stores feed the store-field-ref widget. The State
    // stores panel (preview-js-interactions.js) should call this after
    // any setStateStores write so subsequent picks see fresh stores.
    window.QSComplexWizard.invalidateDataAttrStoresCache = invalidateStoresCache;
})();
