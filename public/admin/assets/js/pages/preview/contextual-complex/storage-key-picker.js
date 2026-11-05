/**
 * Storage-key picker — a key of the project's storage registry, picked or created, never typed.
 * One picker for every field that asks for a key: the parameter editor's storage attributes
 * (data-attr-picker.js), the Interactions tool's `storageKey` argument and a state store's start
 * value (preview-js-interactions.js), and the API page's token sources (apis.js).
 *
 *   const picker = QSComplexWizard.createStorageKeyPicker({
 *     container: HTMLElement,        // required
 *     value:     '',                 // the key to show: shown as it is, whatever the registry says
 *     onChange:  (key) => void,      // a pick or a created key; never for a key only shown
 *     getScope:  () => scope|null,   // the scope the list is filtered by (null: local and session)
 *     setScope:  (scope) => void,    // a created key's scope, so the host's scope follows it
 *   });
 *
 *   picker.getValue() → string
 *   picker.setValue(k)   shows a key; the host's value is the host's, so onChange is not called
 *   picker.refresh()     the host's scope changed: the list is filtered again, the key kept
 *   picker.destroy()
 *
 * Behaviour:
 *  - Every picker reads the registry when it opens (listStorageItems), into one cache for all of
 *    them: a key created in one is offered by the next, and a key made elsewhere (the Storage page
 *    in another tab, a workflow) is not called undeclared. A failed read keeps what was known.
 *  - Only local and session keys are offered: a cookie item is not a value these read or write.
 *  - The key shown is never dropped or replaced. Its row says what is true of it: undeclared,
 *    declared for the other scope, or declared as a cookie; nothing while the list is unknown.
 *  - Create is offered only to a role that may run addStorageItem, once the list has answered (its
 *    categories come from there). A refused Create changes nothing and shows the server's words.
 *
 * The prefix chip is QuickSiteUtils.renderStorageKeyPrefixChip() (core/utils.js), which the Storage
 * page shows too. Its rules, like this picker's, are in css/storage-admin.css.
 *
 * Loaded by: secure/admin/templates/pages/preview-config.php, with the other shared wizard helpers
 * (preview-js-interactions.js loads before it, so it reaches this when it renders, not when it
 * loads), and secure/admin/templates/pages/apis.php, with wizard-text.js and only these words.
 */
(function () {
    'use strict';

    /** The scopes these read and write. A cookie item is declared in the registry, never offered. */
    const OFFERED_SCOPES = ['localStorage', 'sessionStorage'];
    const DEFAULT_CATEGORY = 'functional';
    // No declared key can hold a space, so this option never stands for one.
    const CREATE_OPTION = ' create';

    // -------- module-scope cache (shared across all picker instances) -----
    let _items = null;          // every declared item, cookies included; null until a read answers
    let _categories = [];       // the registry's categories, from the same answer
    let _descLang = 'en';       // the language the registry's descriptions are written in, likewise
    let _reading = null;        // the read in flight
    let _generation = 0;        // a created key makes an older read's answer out of date

    /** A key the registry names with digits only comes back a number: an id is always compared as text. */
    function _normalizeItems(items) {
        return items
            .filter(function (it) { return it && typeof it === 'object'; })
            .map(function (it) { return Object.assign({}, it, { id: String(it.id) }); });
    }

    function readRegistry() {
        if (_reading) return _reading;
        const generation = ++_generation;
        _reading = Promise.resolve()
            .then(function () { return window.QuickSiteAdmin.apiRequest('listStorageItems', 'POST', {}); })
            .then(function (response) {
                const payload = (response.ok && response.data && response.data.data) || null;
                if (!payload || !Array.isArray(payload.items)) throw new Error('no storage items');
                if (generation === _generation) {
                    _items = _normalizeItems(payload.items);
                    _categories = Array.isArray(payload.categories) ? payload.categories : [];
                    if (typeof payload.descLang === 'string' && payload.descLang) _descLang = payload.descLang;
                }
            })
            .catch(function (err) {
                console.warn('[StorageKeyPicker] could not read the registry:', err);
            })
            .then(function () { _reading = null; });
        return _reading;
    }

    function rememberCreated(id, scope, category) {
        _generation++;
        _items.push({ id: id, scope: scope, category: category, consentRequired: category !== 'essential' });
    }

    function mayCreate() {
        const admin = window.QuickSiteAdmin;
        return !!(admin && admin.permissions && admin.permissions.loaded && admin.hasPermission('addStorageItem'));
    }

    function t(key, params) {
        return window.QSComplexWizard.t('storageKeyPicker.' + key, params);
    }

    /** What the server answers for a refused Create, its reason first: the message alone says "see errors". */
    function serverWords(response) {
        const envelope = (response && response.data) || {};
        const errors = Array.isArray(envelope.errors) ? envelope.errors : [];
        const withHint = errors.find(function (e) { return e && e.hint; });
        return (withHint && withHint.hint) || envelope.message || t('createFailed');
    }

    /** The row's label and the line under it, for a key that is not one of the keys offered. */
    function savedKeyWords(key, scope) {
        const item = _items.find(function (it) { return it.id === key; });
        if (!item) {
            return { label: t('undeclared'), hint: t('undeclaredHint') };
        }
        if (item.scope === 'cookie') {
            return { label: t('cookie'), hint: t('cookieHint') };
        }
        return {
            label: t('otherScope', { scope: item.scope }),
            hint: t('otherScopeHint', { scope: item.scope, here: scope }),
        };
    }

    function _renderOption(value, text, description) {
        const option = QSDom.el('option', { value: value, text: text });
        if (description) option.dataset.description = description;
        return option;
    }

    /** The fixed prefix, then what names the key: the list of keys, or a new key's name. */
    function _renderKeyField(control) {
        return QSDom.el('div', { class: 'qs-storage-key-picker__key' }, [
            QuickSiteUtils.renderStorageKeyPrefixChip(t('prefixTitle')),
            control,
        ]);
    }

    // -------- factory -----------------------------------------------------

    function createStorageKeyPicker(opts) {
        if (!opts || !(opts.container instanceof HTMLElement)) {
            throw new Error('[StorageKeyPicker] container (HTMLElement) is required');
        }
        const onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};
        const getScope = typeof opts.getScope === 'function' ? opts.getScope : function () { return null; };
        const setScope = typeof opts.setScope === 'function' ? opts.setScope : function () {};
        let selectedKey = opts.value || '';
        let readFailed = false;

        // ---- DOM ---------------------------------------------------------
        const select = QSDom.el('select', {
            class: 'admin-input qs-storage-key-picker__select',
            'aria-label': t('label'),
        });
        const note = QSDom.el('div', { class: 'qs-storage-key-picker__note', hidden: 'hidden' });

        const nameInput = QSDom.el('input', {
            type: 'text', class: 'admin-input', placeholder: t('namePlaceholder'),
            'aria-label': t('namePlaceholder'), autocomplete: 'off',
        });
        const scopeSelect = QSDom.el('select', { class: 'admin-input', 'aria-label': t('scopeLabel') },
            OFFERED_SCOPES.map(function (s) { return _renderOption(s, s); }));
        const categorySelect = QSDom.el('select', { class: 'admin-input', 'aria-label': t('categoryLabel') });
        // Optional: asked here so the author does not have to remember it on the Storage page.
        const descInput = QSDom.el('input', {
            type: 'text', class: 'admin-input qs-storage-key-picker__desc', placeholder: t('descPlaceholder'),
            'aria-label': t('descPlaceholder'),
        });
        const createBtn = QSDom.el('button', {
            type: 'button', class: 'admin-btn admin-btn--primary admin-btn--sm', text: t('create'), onclick: create,
        });
        const cancelBtn = QSDom.el('button', {
            type: 'button', class: 'admin-btn admin-btn--ghost admin-btn--sm', text: QuickSiteUtils.tCommon('cancel'),
            onclick: function () { createForm.hidden = true; },
        });
        const errorLine = QSDom.el('span', { class: 'qs-storage-key-picker__err' });
        const createForm = QSDom.el('div', { class: 'qs-storage-key-picker__create', hidden: 'hidden' },
            [_renderKeyField(nameInput), scopeSelect, categorySelect, descInput, createBtn, cancelBtn, errorLine]);

        const root = QSDom.el('div', { class: 'qs-storage-key-picker' }, [_renderKeyField(select), note, createForm]);
        opts.container.appendChild(root);

        const list = new window.QSSearchableSelect(select, {
            placeholder: t('placeholder'),
            searchPlaceholder: t('searchPlaceholder'),
            emptyText: t('noKeys'),
        });

        // ---- rendering ---------------------------------------------------

        function offeredItems() {
            const scope = getScope();
            return (_items || []).filter(function (it) {
                return scope ? it.scope === scope : OFFERED_SCOPES.indexOf(it.scope) !== -1;
            });
        }

        function canCreateNow() {
            return _items !== null && mayCreate();
        }

        /** The list as it stands: the keys offered, the key shown, and Create where the role may. */
        function repopulate() {
            const items = offeredItems();
            const options = [_renderOption('', t('placeholder'))];
            items.forEach(function (it) {
                options.push(_renderOption(it.id, it.id, it.scope + ' · ' + it.category));
            });

            let line = _items === null && readFailed ? t('listFailed') : '';
            if (selectedKey && !items.some(function (it) { return it.id === selectedKey; })) {
                // While the list is unknown nothing can be said of the key, only that it is the key.
                const words = _items === null ? null : savedKeyWords(selectedKey, getScope());
                options.push(_renderOption(selectedKey, words ? selectedKey + '  ' + words.label : selectedKey,
                    words ? words.hint : ''));
                if (words) line = words.hint;
            }
            if (canCreateNow()) {
                options.push(_renderOption(CREATE_OPTION, t('createOption')));
            }
            select.replaceChildren(...options);
            select.value = selectedKey;

            note.textContent = line;
            note.hidden = line === '';

            if (categorySelect.options.length !== _categories.length) {
                categorySelect.replaceChildren(..._categories.map(function (c) { return _renderOption(c, c); }));
                if (_categories.indexOf(DEFAULT_CATEGORY) !== -1) categorySelect.value = DEFAULT_CATEGORY;
            }

            list.options.onCreateFromSearch = canCreateNow() ? showCreateForm : null;
            list.refresh();
        }

        /**
         * The create form. `prefill` (from a search that found nothing) seeds the key's name, so
         * the author does not retype what they just searched for.
         */
        function showCreateForm(prefill) {
            errorLine.textContent = '';
            scopeSelect.value = getScope() || OFFERED_SCOPES[0];
            createForm.hidden = false;
            select.value = selectedKey;
            list.refresh();
            if (prefill) {
                nameInput.value = prefill;
                createBtn.focus();
            } else if (!nameInput.value) {
                nameInput.focus();
            }
        }

        async function create() {
            errorLine.textContent = '';
            const id = nameInput.value.trim();
            if (id === '') {
                errorLine.textContent = t('nameRequired');
                return;
            }
            const scope = scopeSelect.value;
            const category = categorySelect.value;
            const body = { id: id, scope: scope, category: category };
            const description = descInput.value.trim();
            if (description) {
                body.description = {};
                body.description[_descLang] = description;
            }
            createBtn.disabled = true;
            try {
                const response = await window.QuickSiteAdmin.apiRequest('addStorageItem', 'POST', body);
                if (!response.ok) {
                    errorLine.textContent = serverWords(response);
                    return;
                }
                rememberCreated(id, scope, category);
                selectedKey = id;
                setScope(scope);
                repopulate();
                createForm.hidden = true;
                nameInput.value = '';
                descInput.value = '';
                onChange(selectedKey);
            } catch (err) {
                errorLine.textContent = (err && err.message) || t('networkError');
            } finally {
                createBtn.disabled = false;
            }
        }

        function readThenDraw() {
            return readRegistry().then(function () {
                readFailed = _items === null;
                repopulate();
            });
        }

        // ---- wiring ------------------------------------------------------

        select.addEventListener('change', function () {
            if (select.value === CREATE_OPTION) {
                showCreateForm();
                return;
            }
            createForm.hidden = true;
            selectedKey = select.value;
            repopulate();
            onChange(selectedKey);
        });

        // A list being opened shows what another picker created since this one last drew it, and
        // tries the registry again when it could not be read.
        root.addEventListener('click', function (e) {
            if (!list.isOpen && e.target.closest && e.target.closest('.qs-searchable-select__trigger')) {
                repopulate();
                if (_items === null) readThenDraw();
            }
        }, true);

        repopulate();
        readThenDraw();
        // Create waits for the panel's own permission check, which loads as the page does.
        const admin = window.QuickSiteAdmin;
        if (admin && admin.permissions && !admin.permissions.loaded && admin.permissionsReady) {
            admin.permissionsReady.then(repopulate);
        }

        // ---- controller --------------------------------------------------

        return {
            getValue: function () { return selectedKey; },
            setValue: function (key) {
                selectedKey = key || '';
                repopulate();
            },
            refresh: repopulate,
            destroy: function () {
                list.destroy();
                if (root.parentNode) root.parentNode.removeChild(root);
            },
        };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.createStorageKeyPicker = createStorageKeyPicker;
})();
