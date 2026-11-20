/**
 * Social previews page — the card a shared link of the site gets on each platform, and the values it is drawn from.
 *
 * ONE READ: getSocialPreview (every role) answers the site's values, every page's values, picture and tags per
 * language, the warnings per platform, the platform table and the territories. The page draws everything from that
 * answer, and reads it again after each save.
 *
 * ONE CHOICE: the list at the top names the whole site or one page. The form below holds what is chosen (the site's
 * values, or the page's: its picture and card type, the site's as their first choice, its title and its description),
 * and the cards are the chosen page's; for the whole site, the home page's, as every page that sets no picture or card
 * type of its own draws them.
 *
 * EVERY FIGURE FROM THE TABLE. A card's crop is its platform's `picture.shape`, its picture's place the table's
 * minimum, file size, formats and `picture.thumbnail`, its cut the table's `title` and `description` lengths (through
 * QSSocialFigures), its line counts the table's `lines` for the device shown. A figure the platform does not publish
 * draws nothing (a picture whole, a text whole) and the card says so. This file holds no platform's figure: what it
 * holds per platform is the arrangement of its card (where the picture and the texts sit), which is not a figure.
 * The width of a card per device is the stylesheet's (css/social-previews.css), the look-alike's own.
 *
 * WRITES ONLY FOR A ROLE THAT CAN. The forms send setSocialPreview (route.write); they are drawn only when
 * QuickSiteAdmin.canRun('setSocialPreview'), and carry the role mark the panel's filter reads. A viewer gets the same
 * values as text. A value emptied is sent empty, which removes it.
 *
 * Built with QSDom.el and named _render* helpers, each returning one element (the CLAUDE.md HTML-in-JS rule).
 */
(function () {
    'use strict';

    var CFG = window.QS_SOCIAL_CONFIG || {};
    var T = window.QS_SOCIAL_I18N || {};
    var el = window.QSDom.el;
    var F = window.QSSocialFigures;
    var DEVICE_KEY = window.QuickSiteStorageKeys && window.QuickSiteStorageKeys.socialPreviewDevice;

    /**
     * How each platform's card is arranged: the texts it draws, in its order, and where its picture sits ('top' across
     * the card, 'side' a small picture beside the texts, 'card' as the card type asks: top when large, side when small).
     * Its wrapper ('bubble': a chat message holding the card; 'bar': a coloured edge at its left). Not a figure: the
     * figures come from the table.
     */
    var ARRANGEMENT = {
        'facebook': { picture: 'top', texts: ['domain', 'title', 'description'] },
        'x-large':  { picture: 'top', texts: ['domain', 'title', 'description'] },
        'x-small':  { picture: 'side', texts: ['domain', 'title', 'description'] },
        'linkedin': { picture: 'top', texts: ['title', 'description', 'domain'] },
        'whatsapp': { picture: 'top', texts: ['title', 'description', 'domain'], wrap: 'bubble', link: true },
        'discord':  { picture: 'card', texts: ['siteName', 'title', 'description'], wrap: 'bar', themed: true, sideRight: true },
        'slack':    { picture: 'card', texts: ['siteName', 'title', 'description'], wrap: 'bar', sideRight: true },
        'telegram': { picture: 'top-after', texts: ['siteName', 'title', 'description'], wrap: 'bar' },
        'imessage': { picture: 'top', texts: ['title', 'domain'], wrap: 'bubble' }
    };
    var DEFAULT_ARRANGEMENT = { picture: 'top', texts: ['domain', 'title', 'description'] };

    var state = {
        report: null,
        // What the list at the top names: '' the whole site, or a route. `route` is the page whose cards are drawn.
        target: '',
        route: null,
        lang: CFG.defaultLang || 'en',
        device: 'computer',
        canWrite: false,
        pictures: null,
        colourPicker: null
    };

    // ── small helpers ─────────────────────────────────────────────────────────────────────────────────

    function fmt(text, vars) {
        return String(text == null ? '' : text).replace(/\{(\w+)\}/g, function (m, k) {
            return vars && vars[k] != null ? String(vars[k]) : m;
        });
    }

    function toast(message, type) {
        window.QuickSiteAdmin.showToast(message, type || 'info');
    }

    function serverMessage(res) {
        return (res && res.data && (res.data.message || res.data.error)) || String(res && res.status || '');
    }

    /** A list joined in the panel's language ("a, b and c"). */
    function joinList(items) {
        try {
            return new Intl.ListFormat(CFG.panelLang || 'en', { style: 'long', type: 'conjunction' }).format(items);
        } catch (e) {
            return items.join(', ');
        }
    }

    function platformName(id, row) {
        return (T.platformName && T.platformName[id]) || row.name || id;
    }

    function unitWord(unit) {
        return unit === 'bytes' ? T.unitBytes : T.unitCharacters;
    }

    /** A table date (YYYY-MM-DD) in the panel's language. */
    function dateWord(iso) {
        var d = new Date(String(iso) + 'T00:00:00Z');
        if (isNaN(d.getTime())) return String(iso || '');
        try {
            return d.toLocaleDateString(CFG.panelLang || 'en', { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });
        } catch (e) {
            return String(iso);
        }
    }

    function tagOf(tags, name) {
        for (var i = 0; i < (tags || []).length; i++) {
            if (tags[i].name === name) return tags[i].content;
        }
        return null;
    }

    function hostOf(url) {
        try {
            return new URL(url).host;
        } catch (e) {
            return '';
        }
    }

    function pageData() {
        var pages = state.report && state.report.pages;
        return pages && state.route && pages[state.route] ? pages[state.route] : null;
    }

    function langData() {
        var p = pageData();
        return p && p.languages ? (p.languages[state.lang] || null) : null;
    }

    function siteValues() {
        return (state.report && state.report.site) || {};
    }

    /** The routes in the order the page offers them: the home page first. */
    function routesInOrder() {
        var routes = Object.keys((state.report && state.report.pages) || {});
        routes.sort(function (a, b) { return (a === 'home' ? 0 : 1) - (b === 'home' ? 0 : 1); });
        return routes;
    }

    function pictureNames() {
        return (state.pictures || []).map(function (p) { return p.value; });
    }

    /** Is the site's picture missing from Media? Known from Media's list, else from a page that uses it. */
    function sitePictureMissing() {
        var key = siteValues().picture;
        if (!key) return false;
        if (state.pictures) return pictureNames().indexOf(key) < 0;
        var pages = (state.report && state.report.pages) || {};
        return Object.keys(pages).some(function (r) {
            var p = pages[r].picture;
            return p && p.from === 'site' && p.inMedia === false;
        });
    }

    // ── reading ─────────────────────────────────────────────────────────────────────────────────────

    async function load() {
        var res = await window.QuickSiteAdmin.apiRequest('getSocialPreview', 'GET');
        if (!res.ok || !res.data || !res.data.data) {
            var host = document.getElementById('social-values');
            if (host) host.replaceChildren(_renderLoadFailed(serverMessage(res)));
            return false;
        }
        state.report = res.data.data;
        var routes = routesInOrder();
        if (state.target && routes.indexOf(state.target) < 0) state.target = '';
        state.route = cardsRoute();
        var langs = state.report.languages || [];
        if (langs.indexOf(state.lang) < 0) state.lang = langs.indexOf(CFG.defaultLang) >= 0 ? CFG.defaultLang : (langs[0] || state.lang);
        return true;
    }

    /** The page whose cards are drawn: the one chosen, or the home page for the whole site. */
    function cardsRoute() {
        var routes = routesInOrder();
        if (state.target) return state.target;
        return routes.indexOf('home') >= 0 ? 'home' : (routes[0] || null);
    }

    function drawAll() {
        drawPickers();
        drawValues();
        drawCards();
    }

    // ── the values of what is chosen ─────────────────────────────────────────────────────────────────

    /**
     * The one form (or, for a role that cannot write, the values as text) of the whole site or of the page chosen. `kept`
     * (field id => value) puts back what was typed in a field every language shares, when only the language changed.
     */
    function drawValues(kept) {
        var host = document.getElementById('social-values');
        var title = document.getElementById('social-values-title');
        if (!host) return;
        // The colour picker keeps a popup of its own in the body: the old one goes with the form it served.
        if (state.colourPicker) {
            state.colourPicker.destroy();
            state.colourPicker = null;
        }
        if (title) title.textContent = state.target ? fmt(T.pageValuesTitle, { page: state.target }) : T.siteTitle;
        if (!state.target) {
            host.replaceChildren(state.canWrite ? _renderSiteForm() : _renderSiteValues());
            putBack(kept);
            if (state.canWrite) mountSiteForm(host);
            return;
        }
        if (!pageData()) {
            host.replaceChildren();
            return;
        }
        host.replaceChildren(state.canWrite ? _renderPageForm() : _renderPageValues());
        putBack(kept);
        if (state.canWrite) mountPageForm(host);
    }

    /** The fields every language shares: a language switch keeps what was typed in them. */
    var SHARED_FIELDS = ['social-site-picture', 'social-site-card', 'social-site-x', 'social-site-theme', 'social-page-picture', 'social-page-card'];

    function sharedValues() {
        var kept = {};
        SHARED_FIELDS.forEach(function (id) {
            var e = document.getElementById(id);
            if (e) kept[id] = e.value;
        });
        return kept;
    }

    function putBack(kept) {
        Object.keys(kept || {}).forEach(function (id) {
            var e = document.getElementById(id);
            if (e) e.value = kept[id];
        });
    }

    /** Whether the project shows more than one language: the language list, and a language named by a field. */
    function manyLanguages() {
        return !!(state.report && state.report.multilingual) && ((state.report && state.report.languages) || []).length > 1;
    }

    function _renderLoadFailed(message) {
        return el('p', { class: 'admin-alert admin-alert--error', text: fmt(T.loadFailed, { error: message }) });
    }

    /** A field: its label, its control, a line saying every language shares it (`shared`, several languages), its hint. */
    function _renderField(forId, label, control, hint, shared) {
        return el('div', { class: 'admin-form-group' }, [
            el('label', { class: 'admin-label', for: forId, text: label }),
            control,
            shared && manyLanguages() ? el('p', { class: 'admin-hint social-previews__shared', text: T.sameEveryLanguage }) : null,
            hint ? el('p', { class: 'admin-hint', text: hint }) : null
        ]);
    }

    function _renderOption(value, label, selected) {
        var o = el('option', { value: value, text: label });
        if (selected) o.selected = true;
        return o;
    }

    /**
     * A picture dropdown: its first choice (no picture, or the site's), Media's pictures, and the current one marked
     * missing when Media no longer holds it.
     */
    function _renderPictureSelect(id, current, firstLabel) {
        var select = el('select', { id: id, class: 'admin-select' });
        select.appendChild(_renderOption('', firstLabel, !current));
        // Media's list unread (null): the current picture is offered as it is, never called missing.
        var known = state.pictures !== null;
        if (current && (!known || pictureNames().indexOf(current) < 0)) {
            select.appendChild(_renderOption(current, known ? fmt(T.pictureMissingOption, { name: current }) : current, true));
        }
        (state.pictures || []).forEach(function (p) {
            select.appendChild(_renderOption(p.value, p.label, p.value === current));
        });
        return select;
    }

    function _renderCardTypeSelect(id, current, firstLabel) {
        return el('select', { id: id, class: 'admin-select' }, [
            _renderOption('', firstLabel, !current),
            _renderOption('large', T.cardLarge, current === 'large'),
            _renderOption('small', T.cardSmall, current === 'small')
        ]);
    }

    function _renderMissing(name, selectId) {
        return el('div', { class: 'admin-alert admin-alert--warning social-previews__missing' }, [
            el('span', { text: fmt(T.pictureMissing, { name: name }) }),
            selectId ? el('button', {
                type: 'button',
                class: 'admin-btn admin-btn--outline',
                text: T.pickAnother,
                onclick: function () {
                    var s = document.getElementById(selectId);
                    if (s && s._qsPicker) s._qsPicker.open();
                    else if (s) s.focus();
                }
            }) : null
        ]);
    }

    /** A territory's name in the panel's language, from the browser (no table of names here), with its code. */
    function territoryName(code) {
        try {
            var name = new Intl.DisplayNames([CFG.panelLang || 'en'], { type: 'region' }).of(code);
            return name && name !== code ? name + ' (' + code + ')' : code;
        } catch (e) {
            return code;
        }
    }

    /** A language's territories (getSocialPreview's `territories`): its usual one, and every one it may be given. */
    function territoriesOf(code) {
        var t = state.report && state.report.territories && state.report.territories[code];
        return { usual: (t && t.usual) || null, codes: (t && t.codes) || [] };
    }

    /** The first choice of a language's territory: none, which announces the language's usual one when it has one. */
    function noTerritoryLabel(code) {
        var usual = territoriesOf(code).usual;
        return usual ? fmt(T.territoryUsual, { territory: territoryName(usual) }) : T.noTerritory;
    }

    /**
     * The territory of the language shown: none (its usual one), then the language's territories, named in the panel's
     * language and in the order of their names. Its language is `data-territory-for`: the panel's own language menu
     * answers any click on an element carrying `data-lang`, anywhere in the page (layout.php).
     */
    function _renderTerritorySelect(code, current) {
        var codes = territoriesOf(code).codes;
        var select = el('select', { id: 'social-territory', class: 'admin-select social-previews__territory', 'data-territory-for': code });
        select.appendChild(_renderOption('', noTerritoryLabel(code), !current));
        var named = codes.map(function (t) { return [t, territoryName(t)]; });
        named.sort(function (a, b) { return a[1].localeCompare(b[1], CFG.panelLang || 'en'); });
        named.forEach(function (pair) { select.appendChild(_renderOption(pair[0], pair[1], pair[0] === current)); });
        if (!codes.length) select.disabled = true;
        return select;
    }

    /** The territory's field, for the language shown (its code in the label when there are several). */
    function _renderTerritoryField(current) {
        var none = territoriesOf(state.lang).codes.length === 0;
        return _renderField('social-territory', territoryLabel(), _renderTerritorySelect(state.lang, current), none ? T.territoryNone : T.territoryHint);
    }

    function territoryLabel() {
        return T.territory + (manyLanguages() ? ' (' + state.lang.toUpperCase() + ')' : '');
    }

    function _renderSaveRow(id) {
        return el('div', { class: 'social-previews__save' }, [
            el('button', { type: 'button', id: id, class: 'admin-btn admin-btn--primary', text: T.save })
        ]);
    }

    function _renderSiteForm() {
        var site = siteValues();
        var form = el('div', { class: 'social-previews__form', id: 'social-site-form', 'data-requires-command': 'setSocialPreview' });
        form.appendChild(el('p', { class: 'admin-hint', text: T.siteHint }));
        if (sitePictureMissing()) form.appendChild(_renderMissing(site.picture, 'social-site-picture'));
        var grid = el('div', { class: 'social-previews__grid' }, [
            _renderField('social-site-picture', T.picture, _renderPictureSelect('social-site-picture', site.picture || '', T.pictureNone), T.pictureHint, true),
            _renderField('social-site-card', T.cardType, _renderCardTypeSelect('social-site-card', site.cardType || '', T.cardTypeDerived), T.cardTypeHint, true),
            _renderField('social-site-x', T.xAccount, el('input', {
                type: 'text', id: 'social-site-x', class: 'admin-input', autocomplete: 'off', placeholder: '@', value: site.xAccount || ''
            }), T.xAccountHint, true),
            _renderField('social-site-theme', T.themeColor, el('input', {
                type: 'text', id: 'social-site-theme', class: 'admin-input social-previews__colour', autocomplete: 'off', placeholder: '#', value: site.themeColor || ''
            }), T.themeColorHint, true),
            _renderTerritoryField((site.locales || {})[state.lang] || '')
        ]);
        form.appendChild(grid);
        form.appendChild(_renderSaveRow('social-save'));
        return form;
    }

    function _renderValueRow(label, value) {
        return el('div', { class: 'social-previews__value' }, [
            el('dt', { text: label }),
            el('dd', { text: value })
        ]);
    }

    function _renderSiteValues() {
        var site = siteValues();
        var chosen = (site.locales || {})[state.lang];
        var list = el('dl', { class: 'social-previews__values', id: 'social-site-values' }, [
            _renderValueRow(T.picture, site.picture ? (sitePictureMissing() ? fmt(T.pictureMissingOption, { name: site.picture }) : site.picture) : T.pictureNone),
            _renderValueRow(T.cardType, site.cardType ? (site.cardType === 'large' ? T.cardLarge : T.cardSmall) : T.cardTypeDerived),
            _renderValueRow(T.xAccount, site.xAccount || T.notSet),
            _renderValueRow(T.themeColor, site.themeColor || T.notSet),
            _renderValueRow(territoryLabel(), chosen ? territoryName(chosen) : noTerritoryLabel(state.lang))
        ]);
        return list;
    }

    /**
     * A dropdown with a search. Every first choice of this page is a choice of its own (no picture, the site's picture,
     * no territory, the whole site), chosen again after another: emptyChoice lists it with the others.
     */
    function makeSearchable(select, placeholder) {
        if (!select || !window.QSSearchableSelect || select._qsPicker) return;
        select._qsPicker = new window.QSSearchableSelect(select, {
            placeholder: placeholder,
            searchPlaceholder: T.search,
            emptyText: T.noResults,
            emptyChoice: true
        });
    }

    function mountSiteForm(host) {
        window.QuickSiteAdmin.filterByPermissions(host);
        makeSearchable(document.getElementById('social-site-picture'), T.pictureNone);
        makeSearchable(document.getElementById('social-territory'), noTerritoryLabel(state.lang));
        var theme = document.getElementById('social-site-theme');
        if (theme && window.QSColorPicker) {
            state.colourPicker = new window.QSColorPicker(theme, { showAlpha: false, format: 'hex' });
        }
        var save = document.getElementById('social-save');
        if (save) save.addEventListener('click', saveSite);
    }

    /** What the site's form changed: only those are sent, an emptied value as empty. */
    function siteChanges() {
        var site = siteValues();
        var body = {};
        var read = function (id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; };
        if (read('social-site-picture') !== (site.picture || '')) body.picture = read('social-site-picture');
        if (read('social-site-card') !== (site.cardType || '')) body.cardType = read('social-site-card');
        var x = read('social-site-x');
        if (x !== '' && x.charAt(0) !== '@') x = '@' + x;
        if (x !== (site.xAccount || '')) body.xAccount = x;
        var colour = read('social-site-theme');
        if (colour.toLowerCase() !== String(site.themeColor || '').toLowerCase()) body.themeColor = colour;
        // The territory of the language shown: only that language's is sent (the engine merges language by language).
        var territory = document.getElementById('social-territory');
        if (territory) {
            var code = territory.getAttribute('data-territory-for');
            if (territory.value !== ((site.locales || {})[code] || '')) {
                body.locales = {};
                body.locales[code] = territory.value;
            }
        }
        return body;
    }

    async function saveSite() {
        var body = siteChanges();
        if (!Object.keys(body).length) {
            toast(T.nothingChanged, 'info');
            return;
        }
        await send(body);
    }

    /** Send the form's changes and read everything again: the form, the list at the top and the cards. */
    async function send(body) {
        var button = document.getElementById('social-save');
        if (button) button.disabled = true;
        var res = await window.QuickSiteAdmin.apiRequest('setSocialPreview', 'POST', body);
        if (button) button.disabled = false;
        if (!res.ok) {
            toast(fmt(T.saveFailed, { error: serverMessage(res) }), 'error');
            return false;
        }
        toast(T.saved, 'success');
        if (await load()) drawAll();
        return true;
    }

    // ── the list at the top, and the language ────────────────────────────────────────────────────────

    function drawPickers() {
        var targetSelect = document.getElementById('social-target');
        if (targetSelect) {
            targetSelect.replaceChildren.apply(targetSelect, [_renderOption('', T.siteTitle, state.target === '')].concat(routesInOrder().map(function (r) {
                return _renderOption(r, r, r === state.target);
            })));
            if (!targetSelect._qsBound) {
                targetSelect._qsBound = true;
                targetSelect.addEventListener('change', function () {
                    state.target = targetSelect.value;
                    state.route = cardsRoute();
                    drawValues();
                    drawCards();
                });
                makeSearchable(targetSelect, T.siteTitle);
            }
            if (targetSelect._qsPicker) targetSelect._qsPicker.refresh();
        }
        var langs = (state.report && state.report.languages) || [];
        var group = document.getElementById('social-lang-group');
        var langSelect = document.getElementById('social-lang');
        if (group) group.hidden = !manyLanguages();
        if (langSelect) {
            langSelect.replaceChildren.apply(langSelect, langs.map(function (c) {
                return _renderOption(c, c.toUpperCase(), c === state.lang);
            }));
            if (!langSelect._qsBound) {
                langSelect._qsBound = true;
                langSelect.addEventListener('change', function () {
                    // What every language shares stays as typed; the language's own fields show that language's.
                    var kept = sharedValues();
                    state.lang = langSelect.value;
                    drawValues(kept);
                    drawCards();
                });
            }
        }
    }

    function pagePictureMissing() {
        var p = pageData();
        return !!(p && p.picture && p.picture.from === 'page' && p.picture.inMedia === false);
    }

    function siteChoiceLabel() {
        var site = siteValues();
        return site.picture ? fmt(T.pictureSite, { name: site.picture }) : T.pictureSiteNone;
    }

    function siteCardLabel() {
        var site = siteValues();
        if (!site.cardType) return T.cardTypeDerived;
        return fmt(T.cardTypeSite, { type: site.cardType === 'large' ? T.cardLarge : T.cardSmall });
    }

    function _renderTitleShown(title) {
        return el('div', { class: 'admin-form-group' }, [
            el('p', { class: 'admin-label', text: T.titleShown + (manyLanguages() ? ' (' + state.lang.toUpperCase() + ')' : '') }),
            el('p', { class: 'social-previews__title-shown', text: title }),
            el('p', { class: 'admin-hint', text: T.titleShownHint })
        ]);
    }

    /** The page shown, its own values and its texts in the language shown. */
    function pageParts() {
        var p = pageData();
        return { p: p, values: (p && p.values) || {}, lang: langData() || {} };
    }

    function _renderPageForm() {
        var parts = pageParts(), p = parts.p, values = parts.values, lang = parts.lang;
        var form = el('div', { class: 'social-previews__form', id: 'social-page-form', 'data-requires-command': 'setSocialPreview' });
        form.appendChild(el('p', { class: 'admin-hint', text: T.pageHint }));
        if (pagePictureMissing()) form.appendChild(_renderMissing(p.picture.key, 'social-page-picture'));
        form.appendChild(el('div', { class: 'social-previews__grid' }, [
            _renderField('social-page-picture', T.picture, _renderPictureSelect('social-page-picture', values.picture || '', siteChoiceLabel()), T.pictureHint, true),
            _renderField('social-page-card', T.cardType, _renderCardTypeSelect('social-page-card', values.cardType || '', siteCardLabel()), T.cardTypeHint, true)
        ]));
        form.appendChild(_renderTitleShown(lang.title || ''));
        var area = el('textarea', {
            id: 'social-page-description',
            class: 'admin-input social-previews__description',
            rows: '3'
        });
        // The engine's longest description (QS_SOCIAL_DESCRIPTION_MAX), from the template; none written here.
        if (CFG.descriptionMax) area.maxLength = Number(CFG.descriptionMax);
        area.value = lang.description || '';
        form.appendChild(_renderField('social-page-description', T.description + (manyLanguages() ? ' (' + state.lang.toUpperCase() + ')' : ''), area, T.descriptionHint));
        form.appendChild(el('p', { class: 'social-previews__cut', id: 'social-page-description-cut', hidden: '' }));
        form.appendChild(_renderSaveRow('social-save'));
        return form;
    }

    function _renderPageValues() {
        var parts = pageParts(), values = parts.values, lang = parts.lang;
        var picture = values.picture
            ? (pagePictureMissing() ? fmt(T.pictureMissingOption, { name: values.picture }) : values.picture)
            : siteChoiceLabel();
        return el('dl', { class: 'social-previews__values', id: 'social-page-values' }, [
            _renderValueRow(T.picture, picture),
            _renderValueRow(T.cardType, values.cardType ? (values.cardType === 'large' ? T.cardLarge : T.cardSmall) : siteCardLabel()),
            _renderValueRow(T.titleShown, lang.title || ''),
            _renderValueRow(T.description, lang.description || T.notSet)
        ]);
    }

    /** The platforms a description is longer than, judged by the table's figures as it is typed (its words, descriptionWords()). */
    function _renderCutList(warnings) {
        return el('span', { text: fmt(T.cutBy, {
            list: joinList(warnings.map(function (w) {
                return fmt(T.cutItem, { platform: platformName(w.platform, w), limit: w.limit, unit: unitWord(w.unit) });
            }))
        }) });
    }

    function syncDescriptionCut() {
        var area = document.getElementById('social-page-description');
        var out = document.getElementById('social-page-description-cut');
        var p = pageData();
        if (!area || !out || !p) return;
        var warnings = F.textWarnings(descriptionWords(area.value.trim()), 'description', state.report.platforms, p.cardType && p.cardType.value);
        out.hidden = warnings.length === 0;
        out.replaceChildren(warnings.length ? _renderCutList(warnings) : '');
    }

    /**
     * A description's words as a page's tags carry them, the length a platform cuts: its formatting markers removed
     * and their words kept (QSTextMarkers, with the engine's list from the template), as the page's og:description
     * is written.
     */
    function descriptionWords(text) {
        return window.QSTextMarkers && CFG.textMarkers ? window.QSTextMarkers.remove(text, CFG.textMarkers) : text;
    }

    function mountPageForm(host) {
        window.QuickSiteAdmin.filterByPermissions(host);
        makeSearchable(document.getElementById('social-page-picture'), siteChoiceLabel());
        var area = document.getElementById('social-page-description');
        if (area) area.addEventListener('input', syncDescriptionCut);
        syncDescriptionCut();
        var save = document.getElementById('social-save');
        if (save) save.addEventListener('click', savePage);
    }

    async function savePage() {
        var parts = pageParts(), values = parts.values, lang = parts.lang;
        if (!parts.p) return;
        var body = { route: state.route };
        var read = function (id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; };
        if (read('social-page-picture') !== (values.picture || '')) body.picture = read('social-page-picture');
        if (read('social-page-card') !== (values.cardType || '')) body.cardType = read('social-page-card');
        if (read('social-page-description') !== (lang.description || '')) {
            body.description = read('social-page-description');
            body.lang = state.lang;
        }
        if (Object.keys(body).length === 1) {
            toast(T.nothingChanged, 'info');
            return;
        }
        await send(body);
    }

    // ── the cards ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Where a platform draws the page's picture: 'large' when it passes every rule the table publishes for the large
     * card (its minimum, its file size, its widest shape, its formats), 'thumb' when the rules it misses are all named
     * in `picture.thumbnail`, else 'none'. A picture Media no longer holds is never drawn (the page writes no og:image).
     */
    function pictureFit(row, pic) {
        if (!pic || pic.inMedia === false) return 'none';
        var f = row.picture || {};
        var failed = [];
        var w = typeof pic.width === 'number' ? pic.width : null;
        var h = typeof pic.height === 'number' ? pic.height : null;
        var min = f.min;
        if (min && min.published && typeof min.width === 'number' && w !== null && h !== null
            && (w < min.width || (typeof min.height === 'number' && h < min.height))) failed.push('min');
        var maxBytes = f.maxBytes;
        if (maxBytes && maxBytes.published && typeof maxBytes.value === 'number' && typeof pic.bytes === 'number'
            && pic.bytes > maxBytes.value) failed.push('maxBytes');
        var shape = f.shape;
        if (shape && shape.published && typeof shape.maxRatio === 'number' && w && h && w / h > shape.maxRatio) failed.push('shape');
        var formats = f.formats;
        if (formats && formats.published && Array.isArray(formats.value) && pic.type
            && formats.value.indexOf(String(pic.type).toLowerCase()) < 0) failed.push('formats');
        if (!failed.length) return 'large';
        var thumb = f.thumbnail;
        var allowed = thumb && thumb.published && Array.isArray(thumb.value) ? thumb.value : [];
        return failed.every(function (r) { return allowed.indexOf(r) >= 0; }) ? 'thumb' : 'none';
    }

    /** The picture, cropped to the platform's published shape, or whole where it publishes none. */
    function _renderPicture(row, pic, place) {
        var src = String((window.QUICKSITE_CONFIG && window.QUICKSITE_CONFIG.projectContentBase) || '').replace(/\/+$/, '') + '/' + pic.path;
        var img = el('img', { src: src, alt: '', decoding: 'async' });
        var shape = row.picture && row.picture.shape;
        var ratio = shape && shape.published && typeof shape.value === 'number' ? shape.value : null;
        var frame = el('div', { class: 'social-card__picture social-card__picture--' + place + (ratio ? ' social-card__picture--cropped' : ' social-card__picture--whole') }, [img]);
        if (ratio) frame.style.aspectRatio = String(ratio);
        frame.dataset.ratio = ratio ? String(ratio) : '';
        return frame;
    }

    /** One text of the card: cut to the platform's length, held to its line count for the device; absent at 0 lines. */
    function _renderText(kind, text, row) {
        var figure = kind === 'title' || kind === 'description' ? row[kind] : null;
        var shown = figure ? F.cut(text, figure) : { text: text, cut: false };
        var node = el('p', { class: 'social-card__' + kind, text: shown.text });
        node.dataset.cut = shown.cut ? '1' : '0';
        var lines = row.lines && row.lines[kind];
        var count = lines && lines.published ? lines[state.device] : null;
        if (typeof count === 'number' && count > 0) {
            node.classList.add('social-card__clamped');
            node.style.setProperty('-webkit-line-clamp', String(count));
            node.dataset.lines = String(count);
        }
        return node;
    }

    function cardTexts(arrangement, row, tags) {
        var texts = [];
        arrangement.texts.forEach(function (kind) {
            var value = kind === 'title' ? tagOf(tags, 'og:title')
                : kind === 'description' ? tagOf(tags, 'og:description')
                : kind === 'siteName' ? tagOf(tags, 'og:site_name')
                : hostOf(tagOf(tags, 'og:url'));
            if (!value) return;
            var lines = row.lines && row.lines[kind];
            if (lines && lines.published && lines[state.device] === 0) return;
            texts.push(_renderText(kind, value, row));
        });
        return texts;
    }

    function pictureSide(arrangement, cardType) {
        if (arrangement.picture === 'card') return cardType === 'small' ? 'side' : 'top-after';
        return arrangement.picture;
    }

    /** The look-alike: the card as its app arranges it, for the device shown. */
    function _renderLookAlike(id, row, page, lang) {
        var arrangement = ARRANGEMENT[id] || DEFAULT_ARRANGEMENT;
        var tags = lang.tags || [];
        var cardType = page.cardType && page.cardType.value;
        var fit = pictureFit(row, page.picture);
        var side = pictureSide(arrangement, cardType);
        var place = fit === 'thumb' ? 'side' : side;
        var picture = fit === 'none' ? null : _renderPicture(row, page.picture, place === 'side' ? 'side' : 'top');
        var body = el('div', { class: 'social-card__body' }, cardTexts(arrangement, row, tags));
        var card = el('div', { class: 'social-card__card social-card__card--' + (place === 'side' ? 'side' : 'top') });
        // A side picture sits before the texts, or after them where the app puts it at the right; a top picture
        // above them, or below where the app draws it under the texts.
        var after = place === 'top-after' || (place === 'side' && arrangement.sideRight);
        if (picture && !after) card.appendChild(picture);
        card.appendChild(body);
        if (picture && after) card.appendChild(picture);
        card.dataset.picture = fit;
        var frame = el('div', { class: 'social-card__frame social-card__frame--' + state.device + (arrangement.wrap ? ' social-card__frame--' + arrangement.wrap : '') });
        if (arrangement.wrap === 'bar' && arrangement.themed) {
            var theme = tagOf(tags, 'theme-color');
            if (theme) frame.style.borderLeftColor = theme;
        }
        frame.appendChild(card);
        if (arrangement.link) {
            var url = tagOf(tags, 'og:url');
            if (url) frame.appendChild(el('p', { class: 'social-card__link', text: url }));
        }
        return frame;
    }

    function _renderWarning(w, row) {
        var name = platformName(w.platform, row);
        var size = function (pair) {
            return Array.isArray(pair) && typeof pair[1] === 'number'
                ? fmt(T.sizeWh, { w: pair[0], h: pair[1] })
                : fmt(T.sizeW, { w: Array.isArray(pair) ? pair[0] : pair });
        };
        var text;
        switch (w.code) {
            case 'picture.too_small': text = fmt(T.wTooSmall, { platform: name, size: size(w.limit) }); break;
            case 'picture.small': text = fmt(T.wSmall, { platform: name, size: size(w.limit) }); break;
            case 'picture.too_large': text = fmt(showsSmall(row, 'maxBytes') ? T.wTooLargeSmall : T.wTooLarge, { platform: name, bytes: window.QuickSiteUtils.formatSize(Number(w.limit) || 0) }); break;
            case 'picture.format': text = fmt(T.wFormat, { platform: name, formats: joinList([].concat(w.limit || [])), format: w.actual }); break;
            case 'title.cut': text = fmt(T.wTitleCut, { platform: name, limit: w.limit, unit: unitWord(row.title && row.title.unit) }); break;
            case 'description.cut': text = fmt(T.wDescriptionCut, { platform: name, limit: w.limit, unit: unitWord(row.description && row.description.unit) }); break;
            default: text = w.message || w.code;
        }
        return el('li', { class: 'social-card__warning', 'data-code': w.code, text: text });
    }

    /** Whether a platform still shows a picture that misses this rule, small beside the text (its `picture.thumbnail`). */
    function showsSmall(row, rule) {
        var thumb = row && row.picture && row.picture.thumbnail;
        return !!(thumb && thumb.published && Array.isArray(thumb.value) && thumb.value.indexOf(rule) >= 0);
    }

    function _renderWarnings(id, row, warnings) {
        var mine = (warnings || []).filter(function (w) { return w.platform === id; });
        if (!mine.length) return null;
        return el('ul', { class: 'social-card__warnings' }, mine.map(function (w) { return _renderWarning(w, row); }));
    }

    /** What this platform does not publish of what its card would use, in one line, or null. */
    function _renderNotPublished(id, row) {
        var arrangement = ARRANGEMENT[id] || DEFAULT_ARRANGEMENT;
        var parts = [];
        var shape = row.picture && row.picture.shape;
        if (!(shape && shape.published && typeof shape.value === 'number')) parts.push(T.npCrop);
        var draws = function (kind) { return arrangement.texts.indexOf(kind) >= 0; };
        ['title', 'description'].forEach(function (kind) {
            var f = row[kind];
            if (draws(kind) && !(f && f.published && typeof f.value === 'number')) parts.push(kind === 'title' ? T.npTitle : T.npDescription);
        });
        var lines = row.lines || {};
        var linesKnown = ['title', 'description'].some(function (k) { return draws(k) && lines[k] && lines[k].published; });
        if (!linesKnown) parts.push(T.npLines);
        if (!parts.length) return null;
        return el('p', { class: 'social-card__not-published', text: fmt(T.notPublished, { platform: platformName(id, row), parts: joinList(parts) }) });
    }

    function _renderChecker(id, row) {
        var name = platformName(id, row);
        if (!row.checker || !row.checker.url) return el('span', { class: 'social-card__checker', text: fmt(T.noChecker, { platform: name }) });
        var link = el('a', { class: 'social-card__checker', href: row.checker.url, target: '_blank', rel: 'noopener noreferrer', text: fmt(T.checker, { platform: name }) });
        if (!row.checker.refreshes) return link;
        return el('span', { class: 'social-card__checker' }, [link, ' · ' + T.checkerRefreshes]);
    }

    function _renderCard(id, row, page, lang) {
        var name = platformName(id, row);
        return el('article', { class: 'social-card social-card--' + id, 'data-platform': id }, [
            el('h3', { class: 'social-card__name', text: name }),
            _renderLookAlike(id, row, page, lang),
            _renderWarnings(id, row, lang.warnings),
            _renderNotPublished(id, row),
            el('p', { class: 'social-card__meta' }, [
                el('span', { class: 'social-card__checked', text: fmt(T.checked, { date: dateWord(row.checked) }) }),
                ' · ',
                _renderChecker(id, row)
            ])
        ]);
    }

    function drawCards() {
        var host = document.getElementById('social-cards');
        var of = document.getElementById('social-cards-of');
        var insta = document.getElementById('social-instagram');
        if (!host) return;
        var page = pageData();
        var lang = langData();
        if (!page || !lang) {
            host.replaceChildren();
            return;
        }
        if (of) {
            // For the whole site, the home page's cards: as a page that sets no picture or card type of its own draws them,
            // unless the home page sets one (then said so).
            var own = !state.target && page.values && (page.values.picture || page.values.cardType);
            of.textContent = (state.target ? fmt(T.cardsOf, { page: state.route }) : T.cardsOfSite + (own ? ' ' + T.cardsOfSiteOwn : ''))
                + (state.report.multilingual ? ' · ' + state.lang.toUpperCase() : '');
        }
        var platforms = state.report.platforms || {};
        var rows = F.cardRows(platforms, page.cardType && page.cardType.value);
        host.dataset.device = state.device;
        host.replaceChildren.apply(host, rows.map(function (pair) { return _renderCard(pair[0], pair[1], page, lang); }));
        var ig = Object.keys(platforms).filter(function (id) { return platforms[id].drawsCard === false; })[0];
        if (insta) {
            insta.textContent = ig ? fmt(T.instagram, { date: dateWord(platforms[ig].checked) }) : '';
            insta.hidden = !ig;
        }
        syncDeviceButtons();
    }

    function syncDeviceButtons() {
        document.querySelectorAll('.social-previews__device-btn').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.device === state.device ? 'true' : 'false');
        });
    }

    /** The device remembered in this browser, read as the script runs: the switch is right before the first paint. */
    function readDevice() {
        try {
            if (DEVICE_KEY && localStorage.getItem(DEVICE_KEY) === 'phone') state.device = 'phone';
        } catch (e) { /* no storage: a computer */ }
        syncDeviceButtons();
    }

    function bindDevice() {
        document.querySelectorAll('.social-previews__device-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                state.device = b.dataset.device === 'phone' ? 'phone' : 'computer';
                try {
                    if (DEVICE_KEY) localStorage.setItem(DEVICE_KEY, state.device);
                } catch (e) { /* kept for this visit only */ }
                drawCards();
            });
        });
    }

    // The script follows the switch in the template: it is there to set before anything is drawn.
    readDevice();

    document.addEventListener('DOMContentLoaded', async function () {
        await (window.QuickSiteAdmin && window.QuickSiteAdmin.permissionsReady
            ? window.QuickSiteAdmin.permissionsReady : Promise.resolve());
        state.canWrite = window.QuickSiteAdmin.canRun('setSocialPreview');
        var readOnly = document.getElementById('social-readonly');
        if (readOnly) readOnly.hidden = state.canWrite;
        bindDevice();
        if (state.canWrite && window.QSMediaPictures) state.pictures = await window.QSMediaPictures.list();
        if (await load()) drawAll();
    });

    // Read by the page's probes: the state the page draws from, never written from outside.
    window.QSSocialPreviewsPage = Object.freeze({
        state: function () { return { target: state.target, route: state.route, lang: state.lang, device: state.device, canWrite: state.canWrite }; },
        pictureFit: pictureFit
    });
})();
