/**
 * QSSearchableSelect — reusable searchable combobox that WRAPS a native
 * <select>. The native element stays in the DOM (hidden) as the data
 * store; all existing code that reads `.value`, listens to `change`
 * events, or inspects `<option>` data-attributes keeps working
 * untouched. The wrapper just renders a custom trigger + dropdown
 * with inline search on top of it.
 *
 * Used by:
 *   - beta.9 A2 Slice 2: JS-function picker (preview-js-interactions.js)
 *   - beta.9 A2 Slice 5: route inputType picker (planned)
 *   - future surfaces where the existing tag/property picker pattern
 *     applies but a fully custom DOM stack would be overkill
 *
 * Search behaviour: case-insensitive substring match against the
 * option's value, textContent, AND data-description attribute. Either
 * field matching counts. Empty query shows everything. Hides
 * <optgroup>s with zero matches. Keyboard nav: ArrowUp/Down move
 * within visible items, Enter selects, Escape closes.
 *
 * Built with createElement + textContent per CLAUDE.md HTML-in-JS
 * hygiene rule (no innerHTML string-glueing). Visual styling lives
 * in public/admin/assets/css/searchable-select.css.
 *
 * @example
 *   const picker = new QSSearchableSelect(document.getElementById('my-select'), {
 *       placeholder: 'Select something',
 *       searchPlaceholder: 'Search…',
 *       emptyText: 'No matches',
 *   });
 *   // ...later, after repopulating the native select with new options:
 *   picker.refresh();
 *   // ...and to clean up:
 *   picker.destroy();
 */
(function () {
    'use strict';

    if (window.QSSearchableSelect) return; // load-guard for double include

    class QSSearchableSelect {
        constructor(nativeSelect, options) {
            if (!nativeSelect || nativeSelect.tagName !== 'SELECT') {
                throw new Error('QSSearchableSelect requires a <select> element');
            }
            this.nativeSelect = nativeSelect;
            this.options = options || {};
            this.placeholder = this.options.placeholder || 'Select…';
            this.searchPlaceholder = this.options.searchPlaceholder || 'Search…';
            this.emptyText = this.options.emptyText || 'No matches';

            this.containerEl = null;
            this.triggerEl = null;
            this.triggerTextEl = null;
            this.dropdownEl = null;
            this.searchInputEl = null;
            this.listEl = null;
            this.isOpen = false;
            this.focusedIndex = -1;
            this.allItemEls = [];
            this._closeHandler = null;
            this._onNativeChange = null;
            this._onTriggerKeydown = null;

            this._mount();
            this._wireNativeListeners();
            this._updateTriggerLabel();
        }

        _mount() {
            this.nativeSelect.style.display = 'none';
            // Mark the native select so other code can spot it (e.g. for
            // styling overrides or to avoid double-wrapping).
            this.nativeSelect.dataset.qsSearchableWrapped = 'true';

            this.containerEl = document.createElement('div');
            this.containerEl.className = 'qs-searchable-select';

            this.triggerEl = document.createElement('button');
            this.triggerEl.type = 'button';
            this.triggerEl.className = 'qs-searchable-select__trigger';
            this.triggerEl.setAttribute('aria-haspopup', 'listbox');
            this.triggerEl.setAttribute('aria-expanded', 'false');

            this.triggerTextEl = document.createElement('span');
            this.triggerTextEl.className = 'qs-searchable-select__text';
            this.triggerEl.appendChild(this.triggerTextEl);

            const chevron = this._buildChevronIcon();
            this.triggerEl.appendChild(chevron);

            this.triggerEl.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.isOpen) this.close();
                else this.open();
            });

            this._onTriggerKeydown = (e) => {
                if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (!this.isOpen) this.open();
                }
            };
            this.triggerEl.addEventListener('keydown', this._onTriggerKeydown);

            this.containerEl.appendChild(this.triggerEl);
            this.nativeSelect.parentNode.insertBefore(this.containerEl, this.nativeSelect);
        }

        _buildChevronIcon() {
            const ns = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 12 12');
            svg.setAttribute('width', '12');
            svg.setAttribute('height', '12');
            svg.setAttribute('aria-hidden', 'true');
            svg.classList.add('qs-searchable-select__chevron');
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', 'M2 4l4 4 4-4');
            path.setAttribute('stroke', 'currentColor');
            path.setAttribute('stroke-width', '1.5');
            path.setAttribute('fill', 'none');
            svg.appendChild(path);
            return svg;
        }

        _wireNativeListeners() {
            // External code (legacy callers + populate routines) does
            // `nativeSelect.value = X` then `nativeSelect.dispatchEvent('change')`.
            // The change event fires whether the dispatcher is the user
            // (via our combobox) or external code (programmatic value
            // update). Either way we re-read + update the trigger label.
            this._onNativeChange = () => this._updateTriggerLabel();
            this.nativeSelect.addEventListener('change', this._onNativeChange);
        }

        _updateTriggerLabel() {
            if (!this.triggerTextEl) return;
            const v = this.nativeSelect.value;
            const opt = Array.from(this.nativeSelect.options).find((o) => o.value === v);
            const isPlaceholder = !opt || opt.value === '';
            this.triggerTextEl.textContent = isPlaceholder ? this.placeholder : opt.textContent;
            this.triggerTextEl.classList.toggle('qs-searchable-select__text--placeholder', isPlaceholder);
        }

        /**
         * External code that repopulates the native select (clearing
         * and re-adding options) calls refresh() to nudge the wrapper
         * into re-reading the new state. The trigger label is updated
         * and any open dropdown is rebuilt with the new options.
         */
        refresh() {
            this._updateTriggerLabel();
            if (this.isOpen && this.listEl && this.searchInputEl) {
                this._renderList(this.searchInputEl.value.trim().toLowerCase());
            }
        }

        open() {
            if (this.isOpen) return;
            this.isOpen = true;
            this.triggerEl.classList.add('qs-searchable-select__trigger--open');
            this.triggerEl.setAttribute('aria-expanded', 'true');

            this.dropdownEl = document.createElement('div');
            this.dropdownEl.className = 'qs-searchable-select__dropdown';
            this.dropdownEl.setAttribute('role', 'listbox');

            // Fixed positioning to escape overflow:hidden parents.
            this.dropdownEl.style.position = 'fixed';

            // Width matches the trigger exactly (both min + max). Without
            // a max-width, long item descriptions push the dropdown past
            // the trigger's container — surfaced in Slice 2 verification
            // ("takes the whole width of the page").
            const rect = this.triggerEl.getBoundingClientRect();
            this.dropdownEl.style.width = rect.width + 'px';
            this.dropdownEl.style.maxWidth = rect.width + 'px';

            this.searchInputEl = document.createElement('input');
            this.searchInputEl.type = 'search';
            this.searchInputEl.className = 'qs-searchable-select__search';
            this.searchInputEl.placeholder = this.searchPlaceholder;
            this.searchInputEl.setAttribute('aria-label', this.searchPlaceholder);
            this.searchInputEl.autocomplete = 'off';
            this.dropdownEl.appendChild(this.searchInputEl);

            this.listEl = document.createElement('div');
            this.listEl.className = 'qs-searchable-select__list';
            this.dropdownEl.appendChild(this.listEl);

            this._renderList('');

            this.searchInputEl.addEventListener('input', (e) => {
                this._renderList(e.target.value.trim().toLowerCase());
                // Re-anchor after content change so flip-up mode hugs
                // the trigger when filtering shrinks the dropdown.
                // Slice 2 verification feedback: with only 1 visible
                // item the dropdown was "really far away from the
                // selector" because the anchor used the stale (full
                // list) height.
                this._positionDropdown(this.triggerEl.getBoundingClientRect());
            });
            this.searchInputEl.addEventListener('keydown', (e) => this._handleSearchKey(e));

            // Mount first (positioned via initial top below) so we can
            // measure the rendered height, then flip up if needed.
            this.dropdownEl.style.top = (rect.bottom + 4) + 'px';
            this.dropdownEl.style.left = rect.left + 'px';
            document.body.appendChild(this.dropdownEl);
            this._positionDropdown(rect);

            setTimeout(() => this.searchInputEl.focus(), 0);

            this._closeHandler = (e) => {
                if (!this.containerEl.contains(e.target) && (!this.dropdownEl || !this.dropdownEl.contains(e.target))) {
                    this.close();
                }
            };
            // Defer the document-level listener by a tick so the click
            // that opened the dropdown doesn't immediately close it.
            setTimeout(() => document.addEventListener('click', this._closeHandler), 0);
        }

        /**
         * Position the dropdown above OR below the trigger based on
         * available viewport space. Mirrors the standard combobox
         * pattern: prefer below; flip above when there isn't enough
         * room. Also caps the max-height so the dropdown never
         * extends past the viewport edge (avoids scrolled-off
         * content + the second scrollbar admin pages would otherwise
         * get).
         *
         * Called after the dropdown is appended to the DOM so we can
         * read its rendered height. Slice 2 verification feedback
         * ("when the function is already really low on the page" it
         * should open upward like the preview-toggle popover does).
         */
        _positionDropdown(triggerRect) {
            if (!this.dropdownEl) return;
            const margin = 8;
            const dropdownHeight = this.dropdownEl.offsetHeight;
            const spaceBelow = window.innerHeight - triggerRect.bottom - margin;
            const spaceAbove = triggerRect.top - margin;
            const flipUp = (dropdownHeight > spaceBelow) && (spaceAbove > spaceBelow);

            if (flipUp) {
                // Position bottom-aligned to the trigger's top edge.
                const maxH = Math.max(120, spaceAbove);
                this.dropdownEl.style.maxHeight = maxH + 'px';
                this.dropdownEl.style.top = Math.max(margin, triggerRect.top - Math.min(dropdownHeight, maxH) - 4) + 'px';
                this.dropdownEl.classList.add('qs-searchable-select__dropdown--flip-up');
            } else {
                const maxH = Math.max(120, spaceBelow);
                this.dropdownEl.style.maxHeight = maxH + 'px';
                this.dropdownEl.style.top = (triggerRect.bottom + 4) + 'px';
                this.dropdownEl.classList.remove('qs-searchable-select__dropdown--flip-up');
            }
        }

        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            this.triggerEl.classList.remove('qs-searchable-select__trigger--open');
            this.triggerEl.setAttribute('aria-expanded', 'false');
            if (this.dropdownEl) {
                this.dropdownEl.remove();
                this.dropdownEl = null;
            }
            this.searchInputEl = null;
            this.listEl = null;
            this.focusedIndex = -1;
            this.allItemEls = [];
            if (this._closeHandler) {
                document.removeEventListener('click', this._closeHandler);
                this._closeHandler = null;
            }
        }

        _handleSearchKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (this.allItemEls.length === 0) return;
                this.focusedIndex = Math.min(this.focusedIndex + 1, this.allItemEls.length - 1);
                this._updateFocusedItem();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.allItemEls.length === 0) return;
                this.focusedIndex = Math.max(this.focusedIndex - 1, 0);
                this._updateFocusedItem();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.focusedIndex >= 0 && this.allItemEls[this.focusedIndex]) {
                    this.allItemEls[this.focusedIndex].click();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
                this.triggerEl.focus();
            }
        }

        _renderList(filterLower) {
            this.listEl.textContent = '';
            this.allItemEls = [];

            const children = Array.from(this.nativeSelect.children);
            children.forEach((child) => {
                if (child.tagName === 'OPTGROUP') {
                    const opts = Array.from(child.children)
                        .filter((o) => o.tagName === 'OPTION' && o.value !== '');
                    const filtered = opts.filter((o) => this._matches(o, filterLower));
                    if (filtered.length === 0) return;

                    const groupEl = document.createElement('div');
                    groupEl.className = 'qs-searchable-select__group';
                    const label = document.createElement('div');
                    label.className = 'qs-searchable-select__group-label';
                    label.textContent = child.label;
                    groupEl.appendChild(label);
                    filtered.forEach((o) => groupEl.appendChild(this._renderItem(o)));
                    this.listEl.appendChild(groupEl);
                } else if (child.tagName === 'OPTION' && child.value !== '' && this._matches(child, filterLower)) {
                    this.listEl.appendChild(this._renderItem(child));
                }
            });

            if (this.allItemEls.length === 0) {
                // create-from-search: when nothing matches a typed query and the
                // consumer supplied onCreateFromSearch, offer an actionable
                // "➕ Create '<query>'" row (keyboard-selectable) instead of a
                // dead "no matches" message.
                const rawQuery = this.searchInputEl ? this.searchInputEl.value.trim() : '';
                if (this.options.onCreateFromSearch && rawQuery) {
                    const createItem = document.createElement('button');
                    createItem.type = 'button';
                    createItem.className = 'qs-searchable-select__item qs-searchable-select__create';
                    createItem.setAttribute('role', 'option');
                    createItem.textContent = this.options.createLabel
                        ? this.options.createLabel(rawQuery)
                        : ('➕ Create "' + rawQuery + '"');
                    createItem.addEventListener('click', () => {
                        this.close();
                        this.options.onCreateFromSearch(rawQuery);
                    });
                    this.listEl.appendChild(createItem);
                    this.allItemEls.push(createItem);
                    this.focusedIndex = 0;
                    this._updateFocusedItem();
                } else {
                    const empty = document.createElement('div');
                    empty.className = 'qs-searchable-select__empty';
                    empty.textContent = filterLower ? ('No matches for "' + filterLower + '"') : this.emptyText;
                    this.listEl.appendChild(empty);
                    this.focusedIndex = -1;
                }
            } else {
                this.focusedIndex = 0;
                this._updateFocusedItem();
            }
        }

        _matches(optionEl, filterLower) {
            if (!filterLower) return true;
            const value = (optionEl.value || '').toLowerCase();
            const text = (optionEl.textContent || '').toLowerCase();
            const desc = (optionEl.dataset.description || '').toLowerCase();
            return value.indexOf(filterLower) !== -1
                || text.indexOf(filterLower) !== -1
                || desc.indexOf(filterLower) !== -1;
        }

        _renderItem(optionEl) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'qs-searchable-select__item';
            item.setAttribute('role', 'option');

            const label = document.createElement('span');
            label.className = 'qs-searchable-select__item-label';
            label.textContent = optionEl.textContent;
            item.appendChild(label);

            const desc = optionEl.dataset.description;
            if (desc) {
                const descEl = document.createElement('span');
                descEl.className = 'qs-searchable-select__item-desc';
                descEl.textContent = desc;
                item.appendChild(descEl);
            }

            item.addEventListener('click', () => this._selectValue(optionEl.value));
            item.addEventListener('mouseenter', () => {
                this.focusedIndex = this.allItemEls.indexOf(item);
                this._updateFocusedItem();
            });

            this.allItemEls.push(item);
            return item;
        }

        _selectValue(value) {
            this.nativeSelect.value = value;
            // change event fires our own _onNativeChange listener (which
            // updates the trigger label) AND any external listeners
            // (handleFunctionChange etc.) — matches what a user clicking
            // the native select would have done.
            this.nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            this.close();
            this.triggerEl.focus();
        }

        _updateFocusedItem() {
            this.allItemEls.forEach((item, i) => {
                const focused = (i === this.focusedIndex);
                item.classList.toggle('qs-searchable-select__item--focused', focused);
                if (focused) item.scrollIntoView({ block: 'nearest' });
            });
        }

        /**
         * Public value accessors. Most consumers should just keep using
         * the native select directly (`.value`); these exist as a
         * convenience when working primarily with the wrapper instance.
         */
        getValue() { return this.nativeSelect.value; }
        setValue(value) {
            this.nativeSelect.value = value;
            this.nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        /**
         * Tear down the wrapper. Removes the trigger + any open
         * dropdown, restores the native select's visibility, and
         * unhooks listeners. Idempotent — safe to call twice.
         */
        destroy() {
            this.close();
            if (this._onNativeChange) {
                this.nativeSelect.removeEventListener('change', this._onNativeChange);
                this._onNativeChange = null;
            }
            if (this.triggerEl && this._onTriggerKeydown) {
                this.triggerEl.removeEventListener('keydown', this._onTriggerKeydown);
                this._onTriggerKeydown = null;
            }
            if (this.containerEl) {
                this.containerEl.remove();
                this.containerEl = null;
            }
            this.nativeSelect.style.display = '';
            delete this.nativeSelect.dataset.qsSearchableWrapped;
        }
    }

    window.QSSearchableSelect = QSSearchableSelect;
})();
