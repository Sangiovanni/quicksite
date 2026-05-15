/**
 * Translation-key picker — shared primitive for Complex Element wizards.
 *
 * Reproduces the UX of the component-variables panel's "Translation key"
 * picker (preview.js → buildValueEditor type='translation'), as a clean
 * stand-alone helper that doesn't depend on private preview-state.
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
 *   picker.destroy()
 *
 * Behaviour:
 *  - On first mount of ANY picker, fetches all translation keys + values
 *    via getTranslationKeys (cached project-wide across instances).
 *  - Searching narrows the dropdown (max 80 visible).
 *  - When the query doesn't exactly match an existing key, an inline
 *    "Create '<query>'" form appears with a value input. Saving creates
 *    the key for every active language (empty value in non-current
 *    languages, the typed value in the current one) via setTranslationKeys
 *    — exactly the variables-panel behaviour.
 *
 * Loaded by: secure/admin/templates/pages/preview-config.php BEFORE
 * any complex-*.js file (kinds depend on it).
 */
(function () {
    'use strict';

    // -------- module-scope cache (shared across all picker instances) -----
    let _keys   = null;            // string[]
    let _values = null;            // { key: firstLangValue }
    let _langs  = null;            // string[]
    let _loadingPromise = null;    // single in-flight fetch

    function loadKeysOnce() {
        if (_keys !== null) return Promise.resolve();
        if (_loadingPromise) return _loadingPromise;
        const adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            console.warn('[TextKeyPicker] QuickSiteAdmin.apiRequest unavailable; running with empty key list');
            _keys = []; _values = {}; _langs = [];
            return Promise.resolve();
        }
        _loadingPromise = (async function () {
            try {
                // List the languages we know about so we can write keys in all of them on create.
                const langsRes = await adminApi.apiRequest('getLangList', 'GET');
                const langList = (langsRes && langsRes.data && (langsRes.data.data || langsRes.data)) || {};
                _langs = Array.isArray(langList.languages) ? langList.languages
                    : (Array.isArray(langList) ? langList : []);
                if (!_langs.length) _langs = ['en'];

                // Pick any language to get the key list — keys are the same set across all.
                const probeLang = _langs[0];
                const t = await adminApi.apiRequest('getTranslations/' + encodeURIComponent(probeLang), 'GET');
                const payload = (t && t.data && (t.data.data || t.data)) || {};
                const translations = payload.translations || payload || {};
                _keys = []; _values = {};
                flattenKeys(translations, '', _keys, _values);
                _keys.sort();
            } catch (err) {
                console.warn('[TextKeyPicker] failed to load translation keys:', err);
                _keys = _keys || []; _values = _values || {}; _langs = _langs || ['en'];
            } finally {
                _loadingPromise = null;
            }
        })();
        return _loadingPromise;
    }

    /** Flatten a nested translation object into dot-notation keys + sample value map. */
    function flattenKeys(obj, prefix, keysOut, valuesOut) {
        if (!obj || typeof obj !== 'object') return;
        for (const k in obj) {
            if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
            const v = obj[k];
            const path = prefix ? prefix + '.' + k : k;
            if (v && typeof v === 'object' && !Array.isArray(v)) {
                flattenKeys(v, path, keysOut, valuesOut);
            } else {
                keysOut.push(path);
                valuesOut[path] = (typeof v === 'string') ? v : '';
            }
        }
    }

    function truncate(s, max) {
        if (!s) return '';
        return s.length > max ? s.substring(0, max) + '…' : s;
    }

    function getCurrentLang() {
        // Mirrors preview.js — falls back to 'en' if nothing else is available.
        try {
            const sel = document.getElementById('preview-lang-select');
            if (sel && sel.value) return sel.value;
        } catch (e) {}
        try {
            const cfg = window.PreviewConfig;
            if (cfg && cfg.defaultLang) return cfg.defaultLang;
        } catch (e) {}
        return 'en';
    }

    /** Build a nested object from a dot-notation key + value. */
    function nestedFromKey(key, value) {
        const out = {};
        const parts = key.split('.');
        let ref = out;
        for (let i = 0; i < parts.length - 1; i++) {
            ref[parts[i]] = {};
            ref = ref[parts[i]];
        }
        ref[parts[parts.length - 1]] = value;
        return out;
    }

    // -------- factory -----------------------------------------------------

    function createTextKeyPicker(opts) {
        if (!opts || !(opts.container instanceof HTMLElement)) {
            throw new Error('[TextKeyPicker] container (HTMLElement) is required');
        }
        const cfg = {
            container:   opts.container,
            placeholder: opts.placeholder || 'Search or type a translation key',
            onChange:    typeof opts.onChange === 'function' ? opts.onChange : function () {},
        };
        let selectedKey = opts.value || '';

        // ---- DOM ---------------------------------------------------------
        const root = document.createElement('div');
        root.className = 'qs-textkey-picker';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'admin-input qs-textkey-picker__input';
        input.placeholder = cfg.placeholder;
        input.value = selectedKey;
        input.autocomplete = 'off';
        root.appendChild(input);

        const dropdown = document.createElement('div');
        dropdown.className = 'qs-textkey-picker__dropdown';
        dropdown.style.display = 'none';
        root.appendChild(dropdown);

        // "Create new key" inline form (shown when query doesn't match any existing key)
        const createForm = document.createElement('div');
        createForm.className = 'qs-textkey-picker__create';
        createForm.style.display = 'none';

        const createLabel = document.createElement('div');
        createLabel.className = 'qs-textkey-picker__create-label';
        createForm.appendChild(createLabel);

        const createRow = document.createElement('div');
        createRow.className = 'qs-textkey-picker__create-row';

        const createValInput = document.createElement('input');
        createValInput.type = 'text';
        createValInput.className = 'admin-input qs-textkey-picker__create-value';
        createValInput.placeholder = 'Value (current language only)';
        createRow.appendChild(createValInput);

        const createBtn = document.createElement('button');
        createBtn.type = 'button';
        createBtn.className = 'admin-btn admin-btn--success admin-btn--sm';
        createBtn.textContent = 'Create';
        createRow.appendChild(createBtn);

        createForm.appendChild(createRow);
        root.appendChild(createForm);

        cfg.container.appendChild(root);

        // ---- rendering ---------------------------------------------------

        function renderDropdown(query) {
            dropdown.innerHTML = '';
            const q = (query || '').toLowerCase().trim();
            let exactMatch = false;
            let count = 0;

            (_keys || []).forEach(key => {
                if (q && key.toLowerCase().indexOf(q) === -1) return;
                if (key.toLowerCase() === q) exactMatch = true;
                if (count >= 80) return;
                count++;

                const item = document.createElement('div');
                item.className = 'qs-textkey-picker__item';
                if (key === selectedKey) item.classList.add('qs-textkey-picker__item--selected');

                const keySpan = document.createElement('span');
                keySpan.className = 'qs-textkey-picker__item-key';
                keySpan.textContent = key;
                item.appendChild(keySpan);

                const sample = _values && _values[key];
                if (sample) {
                    const valSpan = document.createElement('span');
                    valSpan.className = 'qs-textkey-picker__item-value';
                    valSpan.textContent = truncate(sample, 30);
                    valSpan.title = sample;
                    item.appendChild(valSpan);
                }
                // mousedown so the input doesn't blur before we pick.
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    setValue(key);
                    dropdown.style.display = 'none';
                    createForm.style.display = 'none';
                });
                dropdown.appendChild(item);
            });

            // Inline create when query is non-empty and no exact match.
            if (q && !exactMatch) {
                createLabel.textContent = 'Create "' + q + '"';
                createForm.style.display = '';
            } else {
                createForm.style.display = 'none';
            }
            dropdown.style.display = (count > 0 || (q && !exactMatch)) ? '' : 'none';
        }

        async function ensureLoaded() {
            await loadKeysOnce();
        }

        // ---- input wiring -----------------------------------------------

        let isOpen = false;
        async function openIfNotLoading() {
            if (isOpen) return;
            await ensureLoaded();
            renderDropdown(input.value);
            isOpen = true;
        }
        function closeAfterBlur() {
            // Delay so option mousedown can fire before we hide.
            // Skip the close if focus moved INSIDE the picker (e.g. into
            // the inline create-form's value input or Create button) —
            // otherwise typing a new key value would be impossible.
            setTimeout(function () {
                if (root.contains(document.activeElement)) return;
                dropdown.style.display = 'none';
                createForm.style.display = 'none';
                isOpen = false;
            }, 120);
        }

        input.addEventListener('focus', openIfNotLoading);
        input.addEventListener('input', function () {
            // Treat typing as a new (unconfirmed) selection; surface it on
            // change so the wizard sees the same string the user typed.
            selectedKey = input.value;
            cfg.onChange(selectedKey);
            ensureLoaded().then(() => { renderDropdown(input.value); isOpen = true; });
        });
        input.addEventListener('blur', closeAfterBlur);

        createBtn.addEventListener('click', async function () {
            const newKey = input.value.trim();
            if (!newKey) return;
            const newValue = createValInput.value.trim() || newKey;

            createBtn.disabled = true;
            try {
                const currentLang = getCurrentLang();
                const targetLangs = (_langs && _langs.length) ? _langs : [currentLang];
                const adminApi = window.QuickSiteAdmin;
                if (!adminApi) throw new Error('QuickSiteAdmin unavailable');

                const promises = targetLangs.map(function (lang) {
                    const valForLang = (lang === currentLang) ? newValue : '';
                    return adminApi.apiRequest('setTranslationKeys', 'POST', {
                        language: lang,
                        translations: nestedFromKey(newKey, valForLang),
                    });
                });
                await Promise.all(promises);

                // Update shared cache.
                if (_keys) {
                    _keys.push(newKey); _keys.sort();
                    if (_values) _values[newKey] = newValue;
                }
                setValue(newKey);
                dropdown.style.display = 'none';
                createForm.style.display = 'none';
                if (typeof window.showToast === 'function') {
                    window.showToast('Translation key created', 'success');
                }
            } catch (err) {
                console.error('[TextKeyPicker] create failed:', err);
                if (typeof window.showToast === 'function') {
                    window.showToast('Failed to create translation key', 'error');
                }
            } finally {
                createBtn.disabled = false;
            }
        });

        // ---- controller -------------------------------------------------

        function getValue() { return selectedKey; }
        function setValue(v) {
            selectedKey = v || '';
            input.value = selectedKey;
            cfg.onChange(selectedKey);
        }
        function destroy() {
            if (root.parentNode) root.parentNode.removeChild(root);
        }

        return { getValue, setValue, destroy, _refresh: function () { renderDropdown(input.value); } };
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.createTextKeyPicker = createTextKeyPicker;
})();
