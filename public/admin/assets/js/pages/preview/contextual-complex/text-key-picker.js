/**
 * Translation-key picker — a key of the site's words, picked or created. One picker for every field that asks for a
 * text key: the Complex wizards, the Add and Edit forms (the Text tab, and key parameters such as alt and title), the
 * Interactions tool's sentences and a component's Variables panel (preview.js).
 *
 *   const picker = QSComplexWizard.createTextKeyPicker({
 *     container: HTMLElement,       // required
 *     value:     '',                // optional initial key
 *     placeholder: 'Search…',       // optional
 *     onChange: (key) => void,      // optional, called when selection changes
 *   });
 *
 *   picker.getValue() → string
 *   picker.setValue(k)
 *   picker.input          the text field, for a host that focuses it
 *   picker.destroy()
 *
 * Behaviour:
 *  - The keys come from the panel's one list of a site's words (QSSiteWords, js/core/site-words.js), read when the
 *    field takes the focus: every key any language holds, each language's words read under that language, the word
 *    shown beside a key the language being edited's (else the first language holding one). A key made elsewhere
 *    (another picker, the Translations page in another tab, a workflow) shows at the next opening; a failed read keeps
 *    the last list and says so.
 *  - Searching narrows the list (80 shown at most).
 *  - Create is the panel's one Create (QSSiteWords.create()), offered when the key typed is not one of the list: the
 *    word typed is written in the language being edited only, nothing in the others; a word is required; a key a
 *    language holds a text at is picked as it is, never written over; a refusal shows the server's words and changes
 *    nothing. It is drawn only for a role that may run setTranslationKeys.
 *
 * Loaded by: secure/admin/templates/pages/preview-config.php BEFORE any complex-*.js file (kinds depend on it).
 */
(function () {
    'use strict';

    const MAX_SHOWN = 80;

    function t(key, params) {
        return window.QSComplexWizard.t('textKeyPicker.' + key, params);
    }

    function truncate(s, max) {
        if (!s) return '';
        return s.length > max ? s.substring(0, max) + '…' : s;
    }

    /** The language being edited: the editor's toolbar language, else the one remembered, else the default. */
    function editingCode(list) {
        try {
            const sel = document.getElementById('preview-lang');
            if (sel && sel.value) return sel.value;
        } catch (e) { /* no toolbar on this page */ }
        return list ? window.QSSiteWords.editingLanguage(list.codes, list.defaultLanguage) : null;
    }

    function languageName(list, file) {
        const code = file === 'default' ? list.defaultLanguage : file;
        return (list.names && list.names[code]) || code;
    }

    /** The word shown beside a key: the language being edited's, else the first language holding a text. */
    function sampleWord(list, key) {
        const SW = window.QSSiteWords;
        const own = SW.word(list, SW.shownLanguage(list, editingCode(list)), key);
        if (own) return own;
        const holding = SW.languagesWithText(list, key);
        return holding.length ? SW.word(list, holding[0], key) : '';
    }

    function mayCreate() {
        const admin = window.QuickSiteAdmin;
        return !!(admin && admin.permissions && admin.permissions.loaded && typeof admin.canRun === 'function'
            && admin.canRun('setTranslationKeys'));
    }

    function toast(message, type) {
        const admin = window.QuickSiteAdmin;
        if (admin && typeof admin.showToast === 'function') admin.showToast(message, type);
    }

    // -------- factory -----------------------------------------------------

    function createTextKeyPicker(opts) {
        if (!opts || !(opts.container instanceof HTMLElement)) {
            throw new Error('[TextKeyPicker] container (HTMLElement) is required');
        }
        const SW = window.QSSiteWords;
        const onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};
        let selectedKey = opts.value || '';

        // ---- DOM ---------------------------------------------------------
        const input = QSDom.el('input', {
            type: 'text', class: 'admin-input qs-textkey-picker__input', autocomplete: 'off',
            placeholder: opts.placeholder || t('searchPlaceholder'),
        });
        input.value = selectedKey;
        const note = QSDom.el('div', { class: 'qs-textkey-picker__note', hidden: 'hidden' });
        const dropdown = QSDom.el('div', { class: 'qs-textkey-picker__dropdown', hidden: 'hidden' });
        const createLabel = QSDom.el('div', { class: 'qs-textkey-picker__create-label' });
        const createValInput = QSDom.el('input', {
            type: 'text', class: 'admin-input qs-textkey-picker__create-value', placeholder: t('valuePlaceholder'),
            'aria-label': t('valuePlaceholder'),
        });
        const createBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--success', text: t('create') });
        const createError = QSDom.el('div', { class: 'qs-textkey-picker__error', role: 'alert' });
        const createForm = QSDom.el('div', { class: 'qs-textkey-picker__create', hidden: 'hidden' }, [
            createLabel,
            QSDom.el('div', { class: 'qs-textkey-picker__create-row' }, [createValInput, createBtn]),
            createError,
        ]);
        const root = QSDom.el('div', { class: 'qs-textkey-picker' }, [input, note, dropdown, createForm]);
        opts.container.appendChild(root);

        // ---- rendering ---------------------------------------------------

        function _renderItem(list, key) {
            const item = QSDom.el('div', {
                class: 'qs-textkey-picker__item' + (key === selectedKey ? ' qs-textkey-picker__item--selected' : ''),
            }, [QSDom.el('span', { class: 'qs-textkey-picker__item-key', text: key })]);
            const sample = list ? sampleWord(list, key) : '';
            if (sample) {
                item.appendChild(QSDom.el('span', { class: 'qs-textkey-picker__item-value', text: truncate(sample, 30), title: sample }));
            }
            // mousedown so the input doesn't blur before we pick.
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                setValue(key);
                close();
            });
            return item;
        }

        function renderDropdown(query) {
            const list = SW.current();
            const keys = SW.keys(list);
            const q = (query || '').toLowerCase().trim();
            let exactMatch = false;
            const shown = [];
            keys.forEach(function (key) {
                if (q && key.toLowerCase().indexOf(q) === -1) return;
                if (key.toLowerCase() === q) exactMatch = true;
                if (shown.length < MAX_SHOWN) shown.push(_renderItem(list, key));
            });
            dropdown.replaceChildren(...shown);

            const failed = SW.readFailed();
            note.textContent = failed ? t('readFailed') : '';
            note.hidden = !failed;

            const offerCreate = !!q && !exactMatch && list !== null && mayCreate();
            if (offerCreate) {
                createLabel.textContent = t('createQuery', { query: query.trim() });
            } else {
                createError.textContent = '';
            }
            createForm.hidden = !offerCreate;
            dropdown.hidden = shown.length === 0;
        }

        function close() {
            dropdown.hidden = true;
            createForm.hidden = true;
            createError.textContent = '';
            isOpen = false;
        }

        // ---- input wiring -----------------------------------------------

        let isOpen = false;
        function open() {
            renderDropdown(input.value);
            isOpen = true;
            // Read when the field opens: a key made elsewhere since shows as soon as the list answers.
            SW.read().then(function () { if (isOpen) renderDropdown(input.value); });
        }
        function closeAfterBlur() {
            // Delay so option mousedown can fire before we hide. Skip the close if focus moved INSIDE the picker
            // (the create form's word or its button): otherwise typing a new key's word would be impossible.
            setTimeout(function () {
                if (root.contains(document.activeElement)) return;
                close();
            }, 120);
        }

        input.addEventListener('focus', function () { if (!isOpen) open(); });
        input.addEventListener('input', function () {
            // Typing is a new (unconfirmed) selection: the host sees the same string the user typed.
            selectedKey = input.value;
            onChange(selectedKey);
            renderDropdown(input.value);
            isOpen = true;
        });
        input.addEventListener('blur', closeAfterBlur);
        createValInput.addEventListener('blur', closeAfterBlur);
        createValInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                create();
            }
        });
        createBtn.addEventListener('click', create);

        async function create() {
            const key = input.value.trim();
            if (!key) return;
            createError.textContent = '';
            createBtn.disabled = true;
            let done;
            try {
                done = await SW.create(key, createValInput.value, editingCode(SW.current()));
            } finally {
                createBtn.disabled = false;
            }
            const list = SW.current();
            if (done.ok) {
                setValue(done.key);
                createValInput.value = '';
                close();
                toast(t('created', { key: done.key, language: list ? languageName(list, SW.shownLanguage(list, done.language)) : done.language }), 'success');
                return;
            }
            if (done.reason === 'exists') {
                // A key with a text in a language is the key it is: picked, never written over.
                setValue(key);
                createValInput.value = '';
                close();
                toast(t('exists', { key: key, languages: done.languages.map(function (l) { return languageName(list, l); }).join(', ') }), 'info');
                return;
            }
            createError.textContent = createWords(done, key, list);
        }

        function createWords(done, key, list) {
            const names = function () { return (done.languages || []).map(function (l) { return languageName(list, l); }).join(', '); };
            switch (done.reason) {
                case 'keyInvalid': return t('keyInvalid');
                case 'wordRequired': return t('wordRequired');
                case 'clash': return t('clash', { key: key, languages: names() });
                case 'unread': return t('unread');
                default: return t('createFailed', { reason: done.message || '' });
            }
        }

        // ---- controller -------------------------------------------------

        function getValue() { return selectedKey; }
        function setValue(v) {
            selectedKey = v || '';
            input.value = selectedKey;
            onChange(selectedKey);
        }
        function destroy() {
            if (root.parentNode) root.parentNode.removeChild(root);
        }

        return { getValue: getValue, setValue: setValue, destroy: destroy, input: input,
            _refresh: function () { renderDropdown(input.value); } };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.createTextKeyPicker = createTextKeyPicker;
})();
