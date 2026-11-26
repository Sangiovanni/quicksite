/**
 * Privacy helper admin page. Shows getPrivacyStatus (coverage summary, collected
 * data, host classification, endpoint atoms, the OAuth/magic-link auto-seed) and
 * edits it: collected data, host classification, field mapping, the policy page
 * and its cookie section.
 *
 * A datum's label and purpose are each language's own words. The page shows one
 * language at a time, the panel's editing language (QSSiteWords.editingLanguage() /
 * rememberLanguage(), shared with the editor and the Translations page): the top
 * selector and the datum dialog's picker choose it, in step. Each of the project's
 * languages is read with getPrivacyStatus' `lang`; a save writes the languages
 * edited and only them, one setCollectedDatum each.
 *
 * Every control that sends a command carries that command's role mark
 * (data-requires-command), filtered once the role is known. The dialogs are the
 * panel's one modal (QuickSiteUtils.openModal()), each with its × at the top right.
 *
 * Built with createElement + textContent + named _render* helpers per the
 * CLAUDE.md HTML-in-JS hygiene rule (no innerHTML string-glueing). Styling lives
 * in public/admin/assets/css/privacy-admin.css. Mirrors storage.js.
 */
(function () {
    'use strict';

    // Until the status answers: the project's languages come from getPrivacyStatus.
    var DEFAULT_LANG = (window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.defaultLang) || 'en';

    var state = {
        status: null,
        languages: [DEFAULT_LANG],     // the PROJECT's languages, never the panel's
        languageNames: {},             // {code: name}, as getPrivacyStatus names them
        defaultLanguage: DEFAULT_LANG,
        lang: DEFAULT_LANG,            // the language the page shows (the editing language)
        words: {},                     // {code: {id: {label, purpose}}}: each language read, as its pages show it
    };

    // The page's words are the `privacy` section of the panel's words, which privacy.php hands: each is read
    // by its key's whole path through QSPanelWords (core/panel-words.js).

    /** Items listed in the panel's language, as a plain enumeration ("a, b, c" in English). */

    /** A failure the user must see: the panel's error toast. */
    function showError(text) {
        QuickSiteUtils.showToast(text, 'error');
    }

    function api(cmd, method, body) {
        var adminApi = window.QuickSiteAdmin;
        if (!adminApi || typeof adminApi.apiRequest !== 'function') {
            // Its message is shown: a dialog's error line, an error toast.
            return Promise.reject(new Error(QSPanelWords.t('common.adminUnavailable')));
        }
        return adminApi.apiRequest(cmd, method, body);
    }

    /** A refusal in the server's own words (QSSiteWords.serverWords()), else its message. */
    function _serverWords(r, fallback) {
        if (window.QSSiteWords && typeof window.QSSiteWords.serverWords === 'function' && r && r.data) {
            return window.QSSiteWords.serverWords(r);
        }
        return (r && r.data && r.data.message) || fallback;
    }

    /** The role mark of the command a control sends: the panel's filter hides it from a role without it. */
    function _mark(el, command) {
        el.setAttribute('data-requires-command', command);
        return el;
    }

    /** Hide, under `root`, every control whose command the role cannot run. */
    function _filterControls(root) {
        if (root && window.QuickSiteAdmin && typeof window.QuickSiteAdmin.filterByPermissions === 'function') {
            window.QuickSiteAdmin.filterByPermissions(root);
        }
    }

    function _canRun(command) {
        return !!(window.QuickSiteAdmin && typeof window.QuickSiteAdmin.canRun === 'function' && window.QuickSiteAdmin.canRun(command));
    }

    function multilingual() { return state.languages.length > 1; }

    /** A language as the selectors name it: its name and its code. */
    function langLabel(code) {
        var name = state.languageNames[code];
        return name && name !== code ? name + ' (' + code + ')' : code;
    }
    function langName(code) { return state.languageNames[code] || code; }

    /** The project's languages as a select, `selected` chosen. */
    function _renderLanguageSelect(selected) {
        var sel = QSDom.el('select', { class: 'admin-input' });
        state.languages.forEach(function (code) {
            var o = QSDom.el('option', { value: code, text: langLabel(code) });
            if (code === selected) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    /** Show the page in a language and remember it as the editing language: the cards and the top selector follow. */
    function setLanguage(code) {
        if (state.languages.indexOf(code) === -1) return;
        state.lang = code;
        if (window.QSSiteWords && typeof window.QSSiteWords.rememberLanguage === 'function') {
            window.QSSiteWords.rememberLanguage(code);
        }
        render();
    }

    /** What a language says now for a datum ({label, purpose}), or null when that language could not be read. */
    function storedWords(id, code) {
        var words = state.words[code];
        if (!words) return null;
        return Object.prototype.hasOwnProperty.call(words, id) ? words[id] : { label: '', purpose: '' };
    }

    function _pill(text, kind) {
        return QSDom.el('span', { class: 'privacy-pill privacy-pill--' + kind, text: text });
    }

    function _section(title, sub, body) {
        var s = QSDom.el('section', { class: 'privacy-section' });
        var head = QSDom.el('div', { class: 'privacy-section__head' });
        head.appendChild(QSDom.el('h2', { class: 'privacy-section__title', text: title }));
        if (sub) head.appendChild(QSDom.el('span', { class: 'privacy-section__count', text: sub }));
        s.appendChild(head);
        if (body) s.appendChild(body);
        return s;
    }

    // ---- coverage summary -------------------------------------------------

    function _renderCoverage(cov) {
        var box = QSDom.el('div', { class: 'privacy-coverage' + (cov.complete ? ' privacy-coverage--ok' : '') });
        var unverifiable = (cov.undeclaredEndpoints || []).length;
        var items = [
            {
                label: QSPanelWords.t('privacy.coverage.fieldsMapped'),
                value: QSPanelWords.t('privacy.coverage.fraction', {
                    mapped: QSPanelWords.number(cov.mappedAtoms), total: QSPanelWords.number(cov.totalAtoms),
                }),
                warn: cov.unmappedAtoms > 0,
            },
            { label: QSPanelWords.t('privacy.coverage.unverifiableEndpoints'), value: QSPanelWords.number(unverifiable), warn: unverifiable > 0 },
            { label: QSPanelWords.t('privacy.coverage.unclassifiedHosts'), value: QSPanelWords.number(cov.unclassifiedHosts), warn: cov.unclassifiedHosts > 0 },
        ];
        items.forEach(function (it) {
            var cell = QSDom.el('div', { class: 'privacy-coverage__cell' + (it.warn ? ' privacy-coverage__cell--warn' : '') });
            cell.appendChild(QSDom.el('span', { class: 'privacy-coverage__value', text: it.value }));
            cell.appendChild(QSDom.el('span', { class: 'privacy-coverage__label', text: it.label }));
            box.appendChild(cell);
        });
        var status = QSDom.el('div', { class: 'privacy-coverage__status' });
        status.appendChild(_pill(cov.complete ? QSPanelWords.t('privacy.coverage.complete') : QSPanelWords.t('privacy.coverage.incomplete'),
            cov.complete ? 'ok' : 'warn'));
        box.appendChild(status);
        return box;
    }

    // ---- collected data ---------------------------------------------------

    function _slug(s) {
        return String(s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    function _renderCollectedSection(list) {
        var s = QSDom.el('section', { class: 'privacy-section' });
        var head = QSDom.el('div', { class: 'privacy-section__head' });
        head.appendChild(QSDom.el('h2', { class: 'privacy-section__title', text: QSPanelWords.t('privacy.collected.title') }));
        head.appendChild(QSDom.el('span', { class: 'privacy-section__count', text: QSPanelWords.number(list.length) }));

        var actions = QSDom.el('div', { class: 'privacy-section__actions' });
        if (multilingual()) {
            // The language the cards show, in step with the datum dialog's picker. Changing it moves nothing.
            actions.appendChild(QSDom.el('label', { class: 'privacy-desclang__label', for: 'privacy-shown-language', text: QSPanelWords.t('privacy.languages.shownIn') }));
            var langSel = _renderLanguageSelect(state.lang);
            langSel.id = 'privacy-shown-language';
            langSel.classList.add('privacy-desclang__select');
            langSel.addEventListener('change', function () { setLanguage(langSel.value); });
            actions.appendChild(langSel);
        }
        actions.appendChild(_mark(QSDom.el('button', { class: 'admin-btn admin-btn--primary privacy-add-btn', type: 'button', text: QSPanelWords.t('privacy.collected.add'), 'data-focus-key': 'datum-add', onclick: function () { openDatumModal(null); } }), 'setCollectedDatum'));
        head.appendChild(actions);
        s.appendChild(head);

        if (!list.length) {
            s.appendChild(QSDom.el('p', { class: 'privacy-empty', text: QSPanelWords.t('privacy.collected.empty') }));
            return s;
        }
        var wrap = QSDom.el('div', { class: 'privacy-collected' });
        list.forEach(function (d) {
            var card = QSDom.el('div', { class: 'privacy-collected__card' });
            card.appendChild(_renderDatumWords(d));
            var acts = QSDom.el('div', { class: 'privacy-collected__actions' });
            var name = (storedWords(d.id, state.lang) || {}).label || d.id;
            acts.appendChild(QSControls.edit(QSPanelWords.t('privacy.actions.edit', { name: name }), function () { openDatumModal(d); },
                { command: 'setCollectedDatum', focusKey: 'datum-edit:' + d.id }));
            acts.appendChild(QSControls.bin(QSPanelWords.t('privacy.actions.delete', { name: name }), function () { onDeleteDatum(d); },
                { command: 'deleteCollectedDatum', focusKey: 'datum-delete:' + d.id }));
            card.appendChild(acts);
            wrap.appendChild(card);
        });
        s.appendChild(wrap);
        return s;
    }

    /** A card's words in the language shown: its label (the id when that language has none) and its purpose. */
    function _renderDatumWords(d) {
        var words = storedWords(d.id, state.lang);
        var name = langName(state.lang);
        var body = QSDom.el('div', { class: 'privacy-collected__body' });
        body.appendChild(QSDom.el('span', { class: 'privacy-collected__label', text: (words && words.label) || d.id }));
        if (words === null) {
            body.appendChild(QSDom.el('span', { class: 'privacy-collected__purpose privacy-collected__purpose--none', text: QSPanelWords.t('privacy.languages.unread', { language: name }) }));
            return body;
        }
        if (!words.label && multilingual()) {
            body.appendChild(QSDom.el('span', { class: 'privacy-collected__purpose privacy-collected__purpose--none', text: QSPanelWords.t('privacy.languages.noLabelIn', { language: name }) }));
        }
        body.appendChild(QSDom.el('span', {
            class: 'privacy-collected__purpose' + (words.purpose ? '' : ' privacy-collected__purpose--none'),
            text: words.purpose || (multilingual() ? QSPanelWords.t('privacy.languages.noPurposeIn', { language: name }) : '—'),
        }));
        return body;
    }

    // ---- collected-data modal + actions -----------------------------------

    /**
     * A dialog in the panel's one modal (QuickSiteUtils.openModal): its title with the × at the top right, its
     * fields, the error line and Cancel / the action. opts.save() answers true to close, else shows its error and
     * keeps the dialog open. Answers {modal, close, error(text)}.
     */
    function _openDialog(opts) {
        var id = 'privacy-dialog-' + Date.now();
        var errorLine = QSDom.el('p', { class: 'privacy-dialog__error', role: 'alert', hidden: 'hidden' });
        var closeBtn = QSControls.close(null, null);
        var cancelBtn = QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--ghost', text: QSPanelWords.t('common.cancel') });
        var saveBtn = _mark(QSDom.el('button', { type: 'button', class: 'admin-btn admin-btn--primary', text: opts.action }), opts.command);
        var modal = QSDom.el('div', {
            class: 'admin-modal-dialog privacy-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id,
        }, [
            QSDom.el('div', { class: 'privacy-dialog__header' }, [
                QSDom.el('h2', { class: 'admin-modal-dialog__title privacy-dialog__title', id: id, text: opts.title }),
                closeBtn,
            ]),
            QSDom.el('div', { class: 'privacy-dialog__body' }, opts.body.concat([errorLine])),
            QSDom.el('div', { class: 'privacy-dialog__actions' }, [cancelBtn, saveBtn]),
        ]);
        var shown = null;
        var close = function () {
            if (!shown) return;
            var s = shown;
            shown = null;
            s.close();
        };
        var error = function (text) {
            errorLine.textContent = text || '';
            errorLine.hidden = !text;
            if (text && typeof errorLine.scrollIntoView === 'function') errorLine.scrollIntoView({ block: 'nearest' });
        };
        closeBtn.addEventListener('click', close);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async function () {
            error('');
            saveBtn.disabled = true;
            var done = false;
            try {
                done = await opts.save(error);
            } catch (e) {
                error((e && e.message) || QSPanelWords.t('privacy.errors.network'));
            }
            saveBtn.disabled = false;
            if (done) close();
        });
        _filterControls(modal);
        shown = QuickSiteUtils.openModal(modal, {
            focusable: function () {
                return [closeBtn].concat(Array.prototype.slice.call(modal.querySelectorAll('input, select'))
                    .filter(function (el) { return !el.disabled && el.offsetParent !== null; }), [cancelBtn, saveBtn]);
            },
            first: opts.first || saveBtn,
            dismiss: close,
        });
        return { modal: modal, close: close, error: error };
    }

    /** A labelled field: `control` (the input, or a row holding it), its label naming `input`. */
    function _renderField(labelText, input, control) {
        var id = 'privacy-field-' + Math.random().toString(36).slice(2, 10);
        input.id = id;
        return QSDom.el('div', { class: 'admin-form-group privacy-dialog__field' }, [
            QSDom.el('label', { class: 'admin-label', for: id, text: labelText }), control || input,
        ]);
    }

    /**
     * The datum dialog: the label and the purpose in one language at a time, the project's languages in one picker
     * beside the label (a project of two or more, a role that runs setCollectedDatum), which moves the page's
     * language with it; what was typed in a language is kept while another shows, until Save. Save writes each
     * language edited (one setCollectedDatum each, a label required in each); a language not edited keeps its words.
     */
    function openDatumModal(datum, opts) {
        opts = opts || {};
        var isEdit = !!datum;
        var drafts = {};   // {code: {label, purpose}}
        var shown = state.lang;
        var stored = function (code) { return isEdit ? storedWords(datum.id, code) : { label: '', purpose: '' }; };
        if (!isEdit && opts.prefillLabel) drafts[shown] = { label: opts.prefillLabel, purpose: '' };

        var labelInput = QSDom.el('input', { type: 'text', class: 'admin-input privacy-dialog__label-input', autocomplete: 'off', placeholder: QSPanelWords.t('privacy.collectedDialog.labelPlaceholder') });
        var purposeInput = QSDom.el('input', { type: 'text', class: 'admin-input', autocomplete: 'off', placeholder: QSPanelWords.t('privacy.collectedDialog.purposePlaceholder') });
        var purposeField = _renderField('', purposeInput);
        var purposeLabel = purposeField.querySelector('label');
        var hint = QSDom.el('p', { class: 'admin-hint' });

        function remember() { drafts[shown] = { label: labelInput.value, purpose: purposeInput.value }; }
        function showLanguage(code) {
            shown = code;
            var words = stored(code);
            var draft = Object.prototype.hasOwnProperty.call(drafts, code) ? drafts[code] : null;
            labelInput.disabled = purposeInput.disabled = words === null && draft === null;
            labelInput.value = draft ? draft.label : ((words && words.label) || '');
            purposeInput.value = draft ? draft.purpose : ((words && words.purpose) || '');
            purposeLabel.textContent = multilingual()
                ? QSPanelWords.t('privacy.languages.purposeIn', { language: langName(code) }) : QSPanelWords.t('privacy.languages.purpose');
            hint.textContent = words === null ? QSPanelWords.t('privacy.languages.unread', { language: langName(code) })
                : (isEdit ? QSPanelWords.t('privacy.collectedDialog.editHint') : QSPanelWords.t('privacy.collectedDialog.addHint'));
        }
        labelInput.addEventListener('input', remember);
        purposeInput.addEventListener('input', remember);

        var labelRow = QSDom.el('div', { class: 'privacy-dialog__row' });
        var picker = null;
        if (multilingual() && _canRun('setCollectedDatum')) {
            picker = _mark(_renderLanguageSelect(shown), 'setCollectedDatum');
            picker.classList.add('privacy-dialog__lang');
            picker.setAttribute('aria-label', QSPanelWords.t('privacy.languages.language'));
            picker.title = QSPanelWords.t('privacy.languages.language');
            picker.addEventListener('change', function () {
                remember();
                showLanguage(picker.value);
                setLanguage(picker.value);
            });
            labelRow.appendChild(picker);
        }
        labelRow.appendChild(labelInput);
        // The label's field holds the picker and the input on one row.
        var labelField = _renderField(QSPanelWords.t('privacy.languages.label'), labelInput, labelRow);
        showLanguage(shown);

        /** The writes a save makes, {lang, label, purpose} each, or a refusal's words. */
        function plan() {
            remember();
            var writes = [];
            var codes = Object.keys(drafts);
            for (var i = 0; i < codes.length; i++) {
                var code = codes[i];
                var label = String(drafts[code].label).trim();
                var purpose = String(drafts[code].purpose).trim();
                var words = stored(code);
                if (words === null) continue;
                if (isEdit ? (label === String(words.label || '').trim() && purpose === String(words.purpose || '').trim())
                    : (label === '' && purpose === '')) continue;
                if (label === '') {
                    return {
                        refused: multilingual() ? QSPanelWords.t('privacy.languages.labelRequiredIn', { language: langName(code) })
                            : QSPanelWords.t('privacy.collectedDialog.labelRequired'),
                        lang: code,
                    };
                }
                writes.push({ lang: code, label: label, purpose: purpose });
            }
            if (!isEdit && writes.length === 0) return { refused: QSPanelWords.t('privacy.collectedDialog.labelRequired'), lang: shown };
            // The default language first: a new datum's id comes from its label there when it has one.
            writes.sort(function (a, b) { return (a.lang === state.defaultLanguage ? 0 : 1) - (b.lang === state.defaultLanguage ? 0 : 1); });
            return { writes: writes };
        }

        _openDialog({
            title: isEdit ? QSPanelWords.t('privacy.collectedDialog.titleEdit') : QSPanelWords.t('privacy.collectedDialog.titleAdd'),
            action: isEdit ? QSPanelWords.t('common.save') : QSPanelWords.t('common.add'),
            command: 'setCollectedDatum',
            body: [labelField, purposeField, hint],
            first: labelInput,
            save: async function (error) {
                var p = plan();
                if (p.refused) {
                    if (p.lang !== shown && picker) { picker.value = p.lang; showLanguage(p.lang); setLanguage(p.lang); }
                    error(p.refused);
                    return false;
                }
                var id = isEdit ? datum.id : _slug(p.writes[0].label);
                if (!id) { error(QSPanelWords.t('privacy.collectedDialog.noId')); return false; }
                for (var i = 0; i < p.writes.length; i++) {
                    var w = p.writes[i];
                    var r = await api('setCollectedDatum', 'POST', { id: id, label: w.label, purpose: w.purpose, lang: w.lang });
                    if (!r || !r.ok) {
                        var said = _serverWords(r, QSPanelWords.t('common.saveFailed'));
                        error(multilingual() ? QSPanelWords.t('privacy.collectedDialog.failedIn', { language: langName(w.lang), message: said }) : said);
                        if (i > 0) await refresh(true);
                        return false;
                    }
                }
                if (typeof opts.afterSave === 'function') { await opts.afterSave(id); }
                else { await refresh(); }
                return true;
            },
        });
    }

    function openHostModal(h) {
        var kind = h.kind || 'self';
        var name = 'privacy-host-kind-' + Date.now();
        var selfRadio = QSDom.el('input', { type: 'radio', name: name, value: 'self' });
        if (kind === 'self') selfRadio.checked = true;
        var thirdRadio = QSDom.el('input', { type: 'radio', name: name, value: 'third-party' });
        if (kind === 'third-party') thirdRadio.checked = true;
        var nameInput = QSDom.el('input', { type: 'text', class: 'admin-input', placeholder: QSPanelWords.t('privacy.hostDialog.namePlaceholder'), value: h.name || '' });
        var urlInput = QSDom.el('input', { type: 'text', class: 'admin-input', placeholder: 'https://…/privacy', value: h.privacyUrl || '' });
        var tpWrap = QSDom.el('div', { class: 'privacy-host-tp' }, [
            _renderField(QSPanelWords.t('privacy.hostDialog.name'), nameInput),
            _renderField(QSPanelWords.t('privacy.hostDialog.privacyUrl'), urlInput),
        ]);
        function syncTp() { tpWrap.style.display = thirdRadio.checked ? '' : 'none'; }
        selfRadio.addEventListener('change', syncTp);
        thirdRadio.addEventListener('change', syncTp);
        syncTp();
        _openDialog({
            title: QSPanelWords.t('privacy.hostDialog.title'),
            action: QSPanelWords.t('common.save'),
            command: 'setPrivacyHost',
            body: [
                QSDom.el('code', { class: 'privacy-host__url', text: h.baseUrl }),
                QSDom.el('label', { class: 'privacy-radio' }, [selfRadio, ' ' + QSPanelWords.t('privacy.hostDialog.self')]),
                QSDom.el('label', { class: 'privacy-radio' }, [thirdRadio, ' ' + QSPanelWords.t('privacy.hostDialog.thirdParty')]),
                tpWrap,
            ],
            first: kind === 'third-party' ? thirdRadio : selfRadio,
            save: async function (error) {
                var k = thirdRadio.checked ? 'third-party' : 'self';
                var body = { baseUrl: h.baseUrl, kind: k };
                if (k === 'third-party') { body.name = nameInput.value.trim(); body.privacyUrl = urlInput.value.trim(); }
                var r = await api('setPrivacyHost', 'POST', body);
                if (!r || !r.ok) { error(_serverWords(r, QSPanelWords.t('common.saveFailed'))); return false; }
                await refresh();
                return true;
            },
        });
    }

    /** A datum's delete, asked first: a refusal stays in the dialog, and the cards are drawn again before it closes. */
    function onDeleteDatum(d) {
        return QuickSiteUtils.confirm(QSPanelWords.t('privacy.dialogs.deleteDatumText'), {
            title: QSPanelWords.t('privacy.dialogs.deleteDatum', { name: d.label || d.id }),
            type: 'danger',
            confirmText: QSPanelWords.t('privacy.dialogs.delete'),
            action: async function () {
                var r = await api('deleteCollectedDatum', 'POST', { id: d.id });
                if (!r || !r.ok) return { ok: false, message: _serverWords(r, QSPanelWords.t('privacy.errors.deleteFailed')) };
                await refresh();
                return true;
            },
        });
    }

    // ---- atom -> datum mapping (click an atom chip) -----------------------

    var _atomMenuEl = null;
    function _closeAtomMenu() {
        if (_atomMenuEl && _atomMenuEl.parentNode) _atomMenuEl.parentNode.removeChild(_atomMenuEl);
        _atomMenuEl = null;
        document.removeEventListener('mousedown', _onMenuOutside, true);
    }
    function _onMenuOutside(e) {
        if (_atomMenuEl && !_atomMenuEl.contains(e.target)) _closeAtomMenu();
    }

    function openAtomMenu(endpoint, field, currentDatum, anchorEl) {
        _closeAtomMenu();
        var menu = QSDom.el('div', { class: 'privacy-atom-menu' });
        menu.appendChild(QSDom.el('div', { class: 'privacy-atom-menu__head', text: QSPanelWords.t('privacy.mapping.title', { field: field }) }));
        var listing = QSDom.el('div', { class: 'privacy-atom-menu__list' });
        var data = (state.status && state.status.collectedData) || [];
        if (!data.length) {
            listing.appendChild(QSDom.el('div', { class: 'privacy-atom-menu__empty', text: QSPanelWords.t('privacy.mapping.empty') }));
        }
        data.forEach(function (d) {
            listing.appendChild(QSDom.el('button', {
                type: 'button',
                class: 'privacy-atom-menu__item' + (d.id === currentDatum ? ' privacy-atom-menu__item--active' : ''),
                text: d.label || d.id,
                onclick: function () { _closeAtomMenu(); setMapping(endpoint, field, d.id); },
            }));
        });
        menu.appendChild(listing);
        if (currentDatum) {
            var unset = QSDom.el('button', { type: 'button', class: 'privacy-atom-menu__action privacy-atom-menu__action--delete', onclick: function () { _closeAtomMenu(); askUnset(endpoint, field, currentDatum); } });
            unset.appendChild(QSDom.iconEl(QuickSiteUtils.ICON_PATHS.trash, 14));
            unset.appendChild(document.createTextNode(' ' + QSPanelWords.t('privacy.mapping.unset')));
            menu.appendChild(unset);
        }
        menu.appendChild(_mark(QSDom.el('button', {
            type: 'button', class: 'privacy-atom-menu__action privacy-atom-menu__action--new',
            text: QSPanelWords.t('privacy.mapping.newDatum'),
            onclick: function () { _closeAtomMenu(); openDatumModal(null, { prefillLabel: field, afterSave: function (id) { return setMapping(endpoint, field, id); } }); },
        }), 'setCollectedDatum'));

        document.body.appendChild(menu);
        _filterControls(menu);
        var rect = anchorEl.getBoundingClientRect();
        menu.style.position = 'fixed';
        // Under the chip when it fits, else above it; never past the window's edge.
        var below = rect.bottom + 4;
        var room = window.innerHeight - 8;
        var top = below + menu.offsetHeight <= room ? below : rect.top - 4 - menu.offsetHeight;
        menu.style.top = Math.max(8, Math.min(top, room - menu.offsetHeight)) + 'px';
        menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 12)) + 'px';
        _atomMenuEl = menu;
        setTimeout(function () { document.addEventListener('mousedown', _onMenuOutside, true); }, 0);
    }

    /** Unset a field's datum, asked first: the field stops counting as that datum on the privacy page. */
    function askUnset(endpoint, field, datumId) {
        var datum = (state.status && state.status.collectedData || []).filter(function (d) { return d.id === datumId; })[0];
        var name = datum ? ((storedWords(datum.id, state.lang) || {}).label || datum.id) : datumId;
        return QuickSiteUtils.confirm(QSPanelWords.t('privacy.dialogs.unsetMappingText', { datum: name }), {
            title: QSPanelWords.t('privacy.dialogs.unsetMapping', { field: field }),
            type: 'danger',
            confirmText: QSPanelWords.t('privacy.mapping.unset'),
            // The menu that opened the question is gone: the focus goes back to the field's chip, drawn again after a save.
            returnFocus: function () { return QSDom.byFocusKey('atom:' + endpoint + '/' + field); },
            action: async function () {
                var r = await api('setPrivacyMapping', 'POST', { endpoint: endpoint, field: field, datum: '__unset__' });
                if (!r || !r.ok) return { ok: false, message: _serverWords(r, QSPanelWords.t('privacy.errors.mappingFailed')) };
                await refresh();
                return true;
            },
        });
    }

    function setMapping(endpoint, field, datumId) {
        return api('setPrivacyMapping', 'POST', { endpoint: endpoint, field: field, datum: datumId || '__unset__' })
            .then(function (r) {
                if (!r || !r.ok) { showError((r && r.data && r.data.message) || QSPanelWords.t('privacy.errors.mappingFailed')); return; }
                return refresh();
            })
            .catch(function (e) { showError((e && e.message) || QSPanelWords.t('privacy.errors.network')); });
    }

    // ---- hosts ------------------------------------------------------------

    function _renderHosts(hosts) {
        if (!hosts.length) {
            return QSDom.el('p', { class: 'privacy-empty', text: QSPanelWords.t('privacy.hosts.empty') });
        }
        var wrap = QSDom.el('div', { class: 'privacy-hosts' });
        hosts.forEach(function (h) {
            var row = QSDom.el('div', { class: 'privacy-host' });
            var main = QSDom.el('div', { class: 'privacy-host__main' });
            main.appendChild(QSDom.el('code', { class: 'privacy-host__url', text: h.baseUrl }));
            var kind = h.kind === 'third-party'
                ? _pill(h.name ? QSPanelWords.t('privacy.hosts.thirdPartyNamed', { name: h.name }) : QSPanelWords.t('privacy.hosts.thirdParty'), 'third')
                : h.kind === 'self' ? _pill(QSPanelWords.t('privacy.hosts.self'), 'self')
                : _pill(QSPanelWords.t('privacy.hosts.unclassified'), 'warn');
            main.appendChild(kind);
            main.appendChild(QSDom.el('span', { class: 'privacy-host__apis', text: QSPanelWords.list(h.apiIds || [], 'unit') }));
            row.appendChild(main);
            row.appendChild(h.kind
                ? QSControls.edit(QSPanelWords.t('privacy.actions.editHost', { host: h.baseUrl }), function () { openHostModal(h); },
                    { command: 'setPrivacyHost', focusKey: 'host-edit:' + h.baseUrl, className: 'privacy-host__classify' })
                : _mark(QSDom.el('button', {
                    class: 'admin-btn admin-btn--ghost privacy-host__classify', type: 'button', text: QSPanelWords.t('privacy.hosts.classify'),
                    'data-focus-key': 'host-edit:' + h.baseUrl, onclick: function () { openHostModal(h); },
                }), 'setPrivacyHost'));
            wrap.appendChild(row);
        });
        return wrap;
    }

    // ---- endpoints / atoms ------------------------------------------------

    function _renderEndpoints(endpoints) {
        if (!endpoints.length) {
            return QSDom.el('p', { class: 'privacy-empty', text: QSPanelWords.t('privacy.endpoints.empty') });
        }
        var wrap = QSDom.el('div', { class: 'privacy-endpoints' });
        endpoints.forEach(function (ep) {
            var card = QSDom.el('div', { class: 'privacy-endpoint' });
            var head = QSDom.el('div', { class: 'privacy-endpoint__head' });
            head.appendChild(_pill(ep.method, 'method'));
            head.appendChild(QSDom.el('code', { class: 'privacy-endpoint__key', text: ep.key }));
            if (ep.undeclaredBody) head.appendChild(_pill(QSPanelWords.t('privacy.endpoints.noDeclaredBody'), 'warn'));
            card.appendChild(head);

            if (ep.fields && ep.fields.length) {
                var atoms = QSDom.el('div', { class: 'privacy-endpoint__atoms' });
                var mayMap = _canRun('setPrivacyMapping');
                ep.fields.forEach(function (f) {
                    var mapped = f.datum != null;
                    var cls = 'privacy-atom' + (mapped ? ' privacy-atom--mapped' : ' privacy-atom--unset');
                    var chip = mayMap
                        ? QSDom.el('button', { type: 'button', class: cls, title: QSPanelWords.t('privacy.endpoints.clickToMap'), 'data-focus-key': 'atom:' + ep.key + '/' + f.field })
                        : QSDom.el('span', { class: cls + ' privacy-atom--static' });
                    chip.appendChild(QSDom.el('span', { class: 'privacy-atom__field', text: f.field }));
                    chip.appendChild(QSDom.el('span', {
                        class: 'privacy-atom__datum',
                        text: mapped ? QSPanelWords.t('privacy.endpoints.mappedTo', { datum: f.datum }) : QSPanelWords.t('privacy.endpoints.unset'),
                    }));
                    if (mayMap) chip.addEventListener('click', function () { openAtomMenu(ep.key, f.field, f.datum, chip); });
                    atoms.appendChild(chip);
                });
                card.appendChild(atoms);
            } else if (!ep.undeclaredBody) {
                // (undeclared-body endpoints already carry the head pill)
                card.appendChild(QSDom.el('span', { class: 'privacy-endpoint__nofields', text: QSPanelWords.t('privacy.endpoints.noFields') }));
            }
            wrap.appendChild(card);
        });
        return wrap;
    }

    // ---- auth seed --------------------------------------------------------

    function _renderAuthSeed(seed) {
        if (!seed || (!seed.oauth.wired && !seed.magicLink.wired)) {
            return QSDom.el('p', { class: 'privacy-empty', text: QSPanelWords.t('privacy.signIn.none') });
        }
        var wrap = QSDom.el('div', { class: 'privacy-authseed' });
        if (seed.oauth.wired) {
            var o = QSDom.el('div', { class: 'privacy-authseed__row' });
            o.appendChild(QSDom.el('span', { class: 'privacy-authseed__name', text: QSPanelWords.t('privacy.signIn.oauth') }));
            o.appendChild(QSDom.el('span', {
                class: 'privacy-authseed__detail',
                text: QSPanelWords.t('privacy.signIn.oauthDetail', {
                    providers: QSPanelWords.list(seed.oauth.providers.map(function (p) { return p.name; }), 'unit'),
                    collects: QSPanelWords.list(seed.oauth.collectedSuggestions, 'unit'),
                }),
            }));
            wrap.appendChild(o);
        }
        if (seed.magicLink.wired) {
            var m = QSDom.el('div', { class: 'privacy-authseed__row' });
            m.appendChild(QSDom.el('span', { class: 'privacy-authseed__name', text: QSPanelWords.t('privacy.signIn.magicLink') }));
            m.appendChild(QSDom.el('span', {
                class: 'privacy-authseed__detail',
                text: QSPanelWords.t('privacy.signIn.magicLinkDetail', { collects: QSPanelWords.list(seed.magicLink.collectedSuggestions, 'unit') }),
            }));
            wrap.appendChild(m);
        }
        return wrap;
    }

    // ---- cookie cross-link note ------------------------------------------

    function _renderCookieNote(s) {
        var note = QSDom.el('p', { class: 'admin-hint privacy-cookie-note' });
        if (s.cookieSection === 'omit') {
            note.textContent = QSPanelWords.t('privacy.cookies.omitted');
        } else if (s.cookie && s.cookie.policyRoute && s.cookie.policyRouteExists) {
            note.textContent = QSPanelWords.t('privacy.cookies.detected', { route: s.cookie.policyRoute });
        } else {
            note.textContent = QSPanelWords.t('privacy.cookies.none');
        }
        return note;
    }

    // ---- privacy page generation (Generate / Update / Delete) -------------

    /** "Generated at /route": one word holding the route's <code>, its text drawn as the row's label. */
    function _renderGeneratedAt(route) {
        var row = QSDom.el('div', { class: 'privacy-page-gen' });
        var code = QSDom.el('code', { class: 'privacy-host__url', text: route });
        QSPanelWords.parts('privacy.page.generatedAt', { route: code }).forEach(function (part) {
            if (typeof part !== 'string') { row.appendChild(part); return; }
            var text = part.trim();
            if (text) row.appendChild(QSDom.el('span', { class: 'admin-label', text: text }));
        });
        return row;
    }

    function _renderPrivacyPageSection(s) {
        var sec = QSDom.el('section', { class: 'privacy-section' });
        var head = QSDom.el('div', { class: 'privacy-section__head' });
        head.appendChild(QSDom.el('h2', { class: 'privacy-section__title', text: QSPanelWords.t('privacy.page.title') }));
        sec.appendChild(head);

        var hasPage = !!(s.privacyRoute && s.privacyRouteExists);
        if (hasPage) {
            sec.appendChild(_renderGeneratedAt(s.privacyRoute));
            var acts = QSDom.el('div', { class: 'privacy-page-actions' });
            acts.appendChild(_mark(QSDom.el('button', { class: 'admin-btn admin-btn--primary', type: 'button', text: QSPanelWords.t('privacy.page.update'), onclick: function () { onGeneratePrivacy(String(s.privacyRoute).replace(/^\/+|\/+$/g, ''), true); } }), 'generatePrivacyPolicy'));
            acts.appendChild(_mark(QSDom.el('button', { class: 'admin-btn admin-btn--danger', type: 'button', text: QSPanelWords.t('privacy.dialogs.delete'), onclick: onDeletePrivacy }), 'deletePrivacyPolicy'));
            sec.appendChild(acts);
            sec.appendChild(QSDom.el('p', { class: 'admin-hint', text: QSPanelWords.t('privacy.page.updateHint') }));
        } else {
            var input = QSDom.el('input', { type: 'text', class: 'admin-input', placeholder: QSPanelWords.t('privacy.page.routePlaceholder'), value: s.privacyRoute ? String(s.privacyRoute).replace(/^\/+/, '') : '' });
            var gen = QSDom.el('button', { class: 'admin-btn admin-btn--primary', type: 'button', text: QSPanelWords.t('privacy.page.generate'), onclick: function () { var r = input.value.trim().replace(/^\/+|\/+$/g, ''); if (!r) { showError(QSPanelWords.t('privacy.errors.routeRequired')); return; } onGeneratePrivacy(r, false); } });
            sec.appendChild(_mark(QSDom.el('label', { class: 'admin-label', text: QSPanelWords.t('privacy.page.route') }), 'generatePrivacyPolicy'));
            sec.appendChild(_mark(QSDom.el('div', { class: 'privacy-page-gen' }, [input, gen]), 'generatePrivacyPolicy'));
            if (s.privacyRoute && !s.privacyRouteExists) {
                sec.appendChild(QSDom.el('p', { class: 'admin-hint', text: QSPanelWords.t('privacy.page.staleRoute', { route: s.privacyRoute }) }));
            }
        }

        var cb = QSDom.el('input', { type: 'checkbox' });
        if (s.cookieSection === 'omit') cb.checked = true;
        cb.addEventListener('change', function () { onToggleCookieSection(cb.checked); });
        sec.appendChild(_mark(QSDom.el('label', { class: 'privacy-radio privacy-cookie-toggle' }, [cb, ' ' + QSPanelWords.t('privacy.page.omitCookies')]), 'setPrivacyCookieSection'));
        sec.appendChild(_renderCookieNote(s));
        return sec;
    }

    function onGeneratePrivacy(route, overwrite) {
        var body = overwrite ? { route: route, overwrite: true } : { route: route };
        return api('generatePrivacyPolicy', 'POST', body).then(function (r) {
            var d = (r && r.data && (r.data.data || r.data)) || {};
            if (r && !r.ok && d.needsConfirm) {
                return QuickSiteUtils.confirm(QSPanelWords.t('privacy.dialogs.overwritePage', { route: route }), {
                    type: 'danger', confirmText: QSPanelWords.t('privacy.dialogs.overwrite'),
                }).then(function (ok) {
                    if (!ok) return;
                    return api('generatePrivacyPolicy', 'POST', { route: route, overwrite: true }).then(function (r2) {
                        if (!r2 || !r2.ok) { showError((r2 && r2.data && r2.data.message) || QSPanelWords.t('privacy.errors.generateFailed')); return; }
                        return refresh();
                    });
                });
            }
            if (!r || !r.ok) { showError((r && r.data && r.data.message) || QSPanelWords.t('privacy.errors.generateFailed')); return; }
            return refresh();
        }).catch(function (e) { showError((e && e.message) || QSPanelWords.t('privacy.errors.network')); });
    }

    function onDeletePrivacy() {
        return QuickSiteUtils.confirm(QSPanelWords.t('privacy.dialogs.deletePage'), { type: 'danger', confirmText: QSPanelWords.t('privacy.dialogs.delete') }).then(function (ok) {
            if (!ok) return;
            return api('deletePrivacyPolicy', 'POST', {}).then(function (r) {
                if (!r || !r.ok) { showError((r && r.data && r.data.message) || QSPanelWords.t('privacy.errors.deleteFailed')); return; }
                return refresh();
            });
        }).catch(function (e) { showError((e && e.message) || QSPanelWords.t('privacy.errors.network')); });
    }

    function onToggleCookieSection(omit) {
        return api('setPrivacyCookieSection', 'POST', { cookieSection: omit ? 'omit' : 'auto' }).then(function (r) {
            if (!r || !r.ok) { showError((r && r.data && r.data.message) || QSPanelWords.t('privacy.errors.cookieSectionFailed')); }
            return refresh();
        }).catch(function (e) { showError((e && e.message) || QSPanelWords.t('privacy.errors.network')); });
    }

    /** The hint above the page: what a language's words are and where they show (multilingual projects only). */
    function renderHint() {
        var host = document.getElementById('privacy-desclang-hint');
        if (!host) return;
        if (!multilingual()) { host.hidden = true; host.textContent = ''; return; }
        host.hidden = false;
        host.textContent = QSPanelWords.t('privacy.languages.hint');
    }

    function render() {
        var root = document.getElementById('privacy-root');
        if (!root) return;
        // A redraw under the focus (a language, a save, a delete) gives it to the control that took its place.
        var focusBack = root.contains(document.activeElement) ? QSDom.rememberFocus() : null;
        _draw(root);
        if (focusBack) focusBack.restore();
    }

    function _draw(root) {
        root.textContent = '';
        root.className = 'privacy-root';
        var s = state.status;
        if (!s) {
            root.appendChild(QSDom.el('p', { class: 'privacy-empty', text: QSPanelWords.t('privacy.errors.loadFailed') }));
            return;
        }
        root.appendChild(_renderCoverage(s.coverage));
        root.appendChild(_renderPrivacyPageSection(s));
        root.appendChild(_renderCollectedSection(s.collectedData));
        root.appendChild(_section(QSPanelWords.t('privacy.hosts.title'), QSPanelWords.number(s.hosts.length), _renderHosts(s.hosts)));
        root.appendChild(_section(QSPanelWords.t('privacy.signIn.title'), null, _renderAuthSeed(s.authSeed)));
        root.appendChild(_section(QSPanelWords.t('privacy.endpoints.title'), QSPanelWords.number(s.endpoints.length), _renderEndpoints(s.endpoints)));
        _filterControls(root);
    }

    /** One language's status, or null when the read failed. */
    async function _readLanguage(code) {
        try {
            var r = await api('getPrivacyStatus', 'POST', code ? { lang: code } : {});
            var payload = r && r.ok && r.data && (r.data.data || r.data);
            return payload && Array.isArray(payload.collectedData) ? payload : null;
        } catch (e) {
            console.warn('[privacy] load failed:', e);
            return null;
        }
    }

    /** {id: {label, purpose}} of one language's status. */
    function _wordsOf(status) {
        var out = {};
        status.collectedData.forEach(function (d) { out[d.id] = { label: d.label || '', purpose: d.purpose || '' }; });
        return out;
    }

    /**
     * Read the status: in the project's default language first (everything the page shows, and the project's
     * languages), then each other language's collected-data words. The page shows the editing language.
     * `wordsOnly`: the status is kept, only the words are read again (a save stopped half-way).
     */
    async function refresh(wordsOnly) {
        // The controls drawn depend on the role: wait for it, as every page that draws them does.
        try { await (QuickSiteAdmin.permissionsReady || Promise.resolve()); } catch (e) { /* drawn as a role without them */ }
        var first = await _readLanguage(null);
        if (!wordsOnly || first) state.status = first;
        state.words = {};
        if (first) {
            if (Array.isArray(first.languages) && first.languages.length) state.languages = first.languages;
            if (first.languageNames && typeof first.languageNames === 'object') state.languageNames = first.languageNames;
            var answered = typeof first.lang === 'string' && first.lang ? first.lang : state.languages[0];
            state.defaultLanguage = typeof first.defaultLanguage === 'string' && first.defaultLanguage ? first.defaultLanguage : answered;
            state.words[answered] = _wordsOf(first);
            var others = state.languages.filter(function (code) { return code !== answered; });
            var reads = await Promise.all(others.map(function (code) { return _readLanguage(code); }));
            reads.forEach(function (read, i) { if (read) state.words[others[i]] = _wordsOf(read); });
        }
        var remembered = window.QSSiteWords && typeof window.QSSiteWords.editingLanguage === 'function'
            ? window.QSSiteWords.editingLanguage(state.languages, state.defaultLanguage) : state.defaultLanguage;
        state.lang = state.languages.indexOf(remembered) !== -1 ? remembered : state.defaultLanguage;
        render();
        renderHint();
    }

    function init() {
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
