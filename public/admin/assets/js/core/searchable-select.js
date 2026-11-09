/**
 * QSSearchableSelect — reusable searchable combobox that WRAPS a native
 * <select>. The native element stays in the DOM (hidden) as the data
 * store; all existing code that reads `.value`, listens to `change`
 * events, or inspects `<option>` data-attributes keeps working
 * untouched. The wrapper just renders a custom trigger + dropdown
 * with inline search on top of it.
 *
 * An option's data-description is shown as a line under its item (two lines
 * at most) and, whole, as the item's title, which the panel's bubble shows.
 *
 * The trigger takes the select's name — its aria-labelledby or aria-label,
 * else the <label for> that points at it — with the value kept after it, as a
 * native select reads ("Kind: Accordion"). A <label for> is pointed at the
 * trigger, so a click on the label opens the list; destroy() points it back.
 * A label that wraps the select already has the trigger as its control.
 *
 * With the option fitOptions, the dropdown is as wide as its longest row, where
 * it would otherwise be as wide as the trigger. With the option currentCopy
 * ({ title, done }), the line currentOnTop draws has a button copying the chosen
 * option's value.
 *
 * With the option currentOnTop, the chosen option's text is the dropdown's first
 * line, whole, above the search field, and the list under it holds the others:
 * the header's project list, whose badge may cut a long name.
 *
 * Its words are the panel's (common.selectPlaceholder, common.search,
 * common.noResults, common.noMatchesFor, common.createFromSearch, which
 * layout.php ships on every page); a caller's own option wins.
 *
 * Used by:
 *   - the project list: the header's project menu (layout.php)
 *   - the JS-function picker (preview-js-interactions.js)
 *   - the event picker of an element's interaction (preview-js-interactions.js)
 *   - the Complex tab's kind picker and the preview's target (preview.js)
 *   - the route, API and storage-key inputType pickers
 *   - the console's pickers of project data (command-form.js)
 *   - the New Project dialog's language (dashboard.js)
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

    /** One of the panel's words; an unset one shows its key, as QuickSiteUtils.tCommon does. */
    function word(name) {
        const utils = window.QuickSiteUtils;
        return utils && typeof utils.tCommon === 'function' ? utils.tCommon(name) : 'common.' + name;
    }

    let idSeq = 0;
    /** An id for an element the widget names or points a label at. */
    function nextId(kind) {
        idSeq += 1;
        return 'qs-ss-' + kind + '-' + idSeq;
    }

    class QSSearchableSelect {
        constructor(nativeSelect, options) {
            if (!nativeSelect || nativeSelect.tagName !== 'SELECT') {
                throw new Error('QSSearchableSelect requires a <select> element');
            }
            this.nativeSelect = nativeSelect;
            this.options = options || {};
            this.placeholder = this.options.placeholder || word('selectPlaceholder');
            this.searchPlaceholder = this.options.searchPlaceholder || word('search');
            this.emptyText = this.options.emptyText || word('noResults');

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
            this._nameEl = null;
            this._pointedLabels = []; // [label, the for it had, whether it had an id], put back by destroy()

            this._mount();
            this._takeName();
            this._wireNativeListeners();
            this._updateTriggerLabel();
            this.triggerEl.disabled = !!this.nativeSelect.disabled;
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

        /**
         * Name the trigger after its select. The hidden select keeps no name a
         * user can reach, so the trigger is labelled by what named the select,
         * then by its own value text: a screen reader hears "Kind: Accordion".
         * A <label for> is pointed at the trigger, so clicking it opens the list.
         */
        _takeName() {
            const select = this.nativeSelect;
            const ids = [];
            const labelledBy = (select.getAttribute('aria-labelledby') || '').trim();
            const ariaLabel = (select.getAttribute('aria-label') || '').trim();
            if (labelledBy !== '') {
                ids.push(...labelledBy.split(/\s+/));
            } else if (ariaLabel !== '') {
                this._nameEl = document.createElement('span');
                this._nameEl.className = 'qs-searchable-select__name';
                this._nameEl.id = nextId('name');
                this._nameEl.textContent = ariaLabel;
                this.containerEl.appendChild(this._nameEl);
                ids.push(this._nameEl.id);
            }
            const labels = Array.from(select.labels || []).filter((label) => !label.contains(select));
            if (labels.length > 0) {
                if (!this.triggerEl.id) this.triggerEl.id = nextId('trigger');
                labels.forEach((label) => {
                    const hadId = label.id !== '';
                    if (labelledBy === '' && ariaLabel === '') {
                        if (!hadId) label.id = nextId('label');
                        ids.push(label.id);
                    }
                    this._pointedLabels.push([label, label.htmlFor, hadId]);
                    label.htmlFor = this.triggerEl.id;
                });
            }
            if (ids.length > 0) {
                this.triggerTextEl.id = nextId('value');
                this.triggerEl.setAttribute('aria-labelledby', ids.concat(this.triggerTextEl.id).join(' '));
            }
        }

        /** Is this element (or what holds it) one of the labels that open this widget? */
        _isOwnLabel(target) {
            return this._pointedLabels.some(([label]) => label.contains(target));
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
         * and re-adding options), or changes its value or its disabled
         * state without a change event, calls refresh() to nudge the
         * wrapper into re-reading the new state. The trigger label and
         * the trigger's disabled state are updated, and any open dropdown
         * is rebuilt with the new options.
         */
        refresh() {
            this._updateTriggerLabel();
            this.triggerEl.disabled = !!this.nativeSelect.disabled;
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

            // Width matches the trigger exactly (both min + max), or the
            // caller's minWidth when the trigger is narrower (a compact
            // badge). Without a max-width, long item descriptions push the
            // dropdown past the trigger's container, across the whole width
            // of the page.
            const rect = this.triggerEl.getBoundingClientRect();
            const width = Math.max(rect.width, Number(this.options.minWidth) || 0);
            this.dropdownEl.style.width = width + 'px';
            this.dropdownEl.style.maxWidth = width + 'px';
            // With fitOptions the list is as wide as its longest row (a list whose rows are short
            // names, not descriptions), at least its trigger, and never wider than the window.
            if (this.options.fitOptions) {
                this.dropdownEl.style.width = 'max-content';
                this.dropdownEl.style.minWidth = width + 'px';
                this.dropdownEl.style.maxWidth = 'calc(100vw - 1rem)';
            }

            const chosen = this.options.currentOnTop ? this.nativeSelect.selectedOptions[0] : null;
            if (chosen && chosen.value !== '') this.dropdownEl.appendChild(this._renderCurrent(chosen));

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
                // Without it, with only 1 visible item the dropdown sits
                // far away from the selector, because the anchor uses the
                // stale (full list) height.
                this._positionDropdown(this.triggerEl.getBoundingClientRect());
            });
            this.searchInputEl.addEventListener('keydown', (e) => this._handleSearchKey(e));

            // Mount first (positioned via initial top below) so we can
            // measure the rendered height, then flip up if needed. A list
            // wider than its trigger moves left as far as it must to stay
            // inside the page.
            this.dropdownEl.style.top = (rect.bottom + 4) + 'px';
            document.body.appendChild(this.dropdownEl);
            let drawn = width;
            if (this.options.fitOptions) {
                // Measured once with every row in it, so a search does not narrow it under the typing.
                drawn = this.dropdownEl.offsetWidth;
                this.dropdownEl.style.width = drawn + 'px';
            }
            let left = rect.left;
            if (drawn > rect.width) {
                left = Math.max(8, Math.min(rect.left, document.documentElement.clientWidth - 8 - drawn));
            }
            this.dropdownEl.style.left = left + 'px';
            this._positionDropdown(rect);

            // A close() in the same tick has dropped the field already.
            setTimeout(() => { if (this.searchInputEl) this.searchInputEl.focus(); }, 0);

            // A click on one of the widget's labels is the widget's own: the
            // label clicks the trigger next, which toggles the list.
            this._closeHandler = (e) => {
                if (!this.containerEl.contains(e.target) && (!this.dropdownEl || !this.dropdownEl.contains(e.target))
                    && !this._isOwnLabel(e.target)) {
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
         * read its rendered height. When the trigger is already low on
         * the page, the dropdown opens upward, like the preview-toggle
         * popover does.
         *
         * Three measurements keep it inside the visible page:
         *   - the height it WANTS is measured under the stylesheet's own
         *     max-height, never under a cap an earlier call set. Measured
         *     against its own earlier cap, a full list re-filled after a
         *     search came out a border taller than the room it had been
         *     given, and flipped where the first opening had not.
         *   - the room is capped by that stylesheet max-height too: the
         *     inline cap only ever shrinks the dropdown, so a long list
         *     no longer grows to fill everything below the trigger.
         *   - the visible bottom is documentElement.clientHeight, which
         *     leaves out a horizontal scrollbar; window.innerHeight
         *     counts it, and the list's last rows ended up under it.
         */
        _positionDropdown(triggerRect) {
            if (!this.dropdownEl) return;
            const margin = 8;
            const gap = 4;

            this.dropdownEl.style.maxHeight = '';
            const wanted = this.dropdownEl.offsetHeight;
            const styleCap = parseFloat(getComputedStyle(this.dropdownEl).maxHeight) || Infinity;
            // max-height sizes the content box; the borders sit outside it.
            const frame = wanted - this.dropdownEl.clientHeight;

            const spaceBelow = document.documentElement.clientHeight - triggerRect.bottom - margin;
            const spaceAbove = triggerRect.top - margin;
            const flipUp = (wanted > spaceBelow) && (spaceAbove > spaceBelow);

            const maxH = Math.max(120, Math.min(styleCap, (flipUp ? spaceAbove : spaceBelow) - frame));
            this.dropdownEl.style.maxHeight = maxH + 'px';
            const height = Math.min(wanted, maxH + frame);

            if (flipUp) {
                // Position bottom-aligned to the trigger's top edge.
                this.dropdownEl.style.top = Math.max(margin, triggerRect.top - height - gap) + 'px';
                this.dropdownEl.classList.add('qs-searchable-select__dropdown--flip-up');
            } else {
                this.dropdownEl.style.top = (triggerRect.bottom + gap) + 'px';
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

            // With currentOnTop, the chosen option is the line above the search, not a row of the list.
            const current = this.options.currentOnTop ? this.nativeSelect.value : null;
            const listed = (o) => o.value !== '' && o.value !== current;
            const children = Array.from(this.nativeSelect.children);
            children.forEach((child) => {
                if (child.tagName === 'OPTGROUP') {
                    const opts = Array.from(child.children)
                        .filter((o) => o.tagName === 'OPTION' && listed(o));
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
                } else if (child.tagName === 'OPTION' && listed(child) && this._matches(child, filterLower)) {
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
                        : word('createFromSearch').replace(':query', rawQuery);
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
                    empty.textContent = rawQuery ? word('noMatchesFor').replace(':query', rawQuery) : this.emptyText;
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

        /** The chosen option, whole, as the dropdown's first line (currentOnTop). */
        _renderCurrent(optionEl) {
            const line = document.createElement('div');
            line.className = 'qs-searchable-select__current';
            line.textContent = optionEl.textContent;
            if (this.options.currentCopy) line.appendChild(this._renderCurrentCopy(optionEl.value));
            return line;
        }

        /** A button copying the chosen option's value (currentCopy: { title, done }). */
        _renderCurrentCopy(value) {
            const { title, done } = this.options.currentCopy;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'qs-searchable-select__current-copy';
            button.title = title;
            button.setAttribute('aria-label', title);
            const ns = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('width', '14');
            svg.setAttribute('height', '14');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            svg.setAttribute('aria-hidden', 'true');
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', 'M8 8h12v12H8zM16 8V4H4v12h4');
            svg.appendChild(path);
            button.appendChild(svg);
            button.addEventListener('click', () => window.QuickSiteUtils.copyToClipboard(value, done));
            return button;
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
                // The line stops at two lines; the bubble shows the whole text on hover.
                item.title = desc;
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
         * dropdown, points the labels back at the select, restores the
         * native select's visibility, and unhooks listeners.
         * Idempotent — safe to call twice.
         */
        destroy() {
            this.close();
            this._pointedLabels.forEach(([label, oldFor, hadId]) => {
                label.htmlFor = oldFor;
                if (!hadId) label.removeAttribute('id');
            });
            this._pointedLabels = [];
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
