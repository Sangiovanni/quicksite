/**
 * preview-simple-add.js — the simple Add (Select → Add, in simple mode).
 *
 * A beginner's list of plain elements in place of the Add form's five tabs: Text, Title (Big / Medium / Small),
 * Button, Link, Image, Video, Audio, List, Block. Each item's form is static markup
 * (templates/pages/preview/_simple-add.php); what each one sends is here, in PRESETS. The list is fixed in code:
 * each item needs a form of its own, which a line in a settings file could not bring.
 *
 * Every item goes through the Add the advanced form already uses: `addNode`, and for the List the Complex
 * list (`addComplexElement`, kind `list`), which writes the whole list or nothing. No class, no ID, no
 * key-or-literal choice: each text gets a translation key of its own, written in every language (the text in
 * the language being edited, empty in the others, as the Add form's key picker does), named as the engine names
 * the keys it generates (<page>.item<N>, menu.item<N>, footer.item<N>, component.<name>.item<N>).
 *
 * Order: the keys first, then the element. When the element is refused, the keys this Add wrote are deleted
 * again, so a refused Add leaves nothing behind.
 *
 * Image, Video and Audio pick their file from Media through Choose… (the picker, with an upload in it); nothing opens by itself.
 * An empty element that can hold others (a new Block, an empty form) opens the form on Inside, anything else on After.
 *
 * preview.js decides which form opens (the page's admin-body--simple class), owns the Add form's position,
 * Cancel and Add, and hands this module what it needs through init(). The mode is no permission: what a role
 * may run is the server's to decide, and the Add button itself is offered only to a role holding addNode
 * (editorTools.php).
 */
window.PreviewSimpleAdd = (function () {
    'use strict';

    // ──────────────────────────── The list ────────────────────────────

    /** The three title sizes: the page's main title and the two levels below it. */
    const TITLE_TAGS = Object.freeze({ big: 'h1', medium: 'h2', small: 'h3' });

    /** The Media category each file item picks from (listAssets). */
    const MEDIA_CATEGORY = Object.freeze({ image: 'images', video: 'videos', audio: 'audio' });

    /** What an upload from the picker accepts, per item. */
    const MEDIA_ACCEPT = Object.freeze({ image: 'image/*', video: 'video/*', audio: 'audio/*' });

    /** An outside address must say where it goes in full: one of these, then the rest. */
    const OUTSIDE_SCHEMES = Object.freeze(['https://', 'http://', 'mailto:', 'tel:']);

    /** How many lines a new List starts with: Add an item adds one, each line's × removes it, and one always stays. */
    const LIST_ITEMS = 3;

    /**
     * Each item: how many texts it keys, and the command and body it sends once the keys exist.
     * `v` holds the form's values, `keys` the generated key names (as many as `texts(v)` says), `env` what the
     * page around the target says (`inForm`: the Button lands inside a form).
     */
    const PRESETS = Object.freeze({
        text: {
            texts: () => 1,
            build: (v, keys) => node('p', null, keys[0], [v.text]),
        },
        title: {
            texts: () => 1,
            build: (v, keys) => node(TITLE_TAGS[v.size] || 'h2', null, keys[0], [v.text]),
        },
        button: {
            texts: () => 1,
            // Inside a form a button sends it; anywhere else it waits for an action (Interactions, in Advanced).
            build: (v, keys, env) => node('button', { type: env.inForm ? 'submit' : 'button' }, keys[0], [v.text]),
        },
        link: {
            texts: () => 1,
            build: (v, keys) => {
                const params = { href: v.href };
                if (v.newTab) params.target = '_blank';
                return node('a', params, keys[0], [v.text]);
            },
        },
        image: {
            // The short description is the image's alt, keyed like any text; none given, no alt (nothing invented).
            texts: (v) => (v.alt ? 1 : 0),
            build: (v, keys) => {
                const params = { src: v.file };
                if (v.alt) params.alt = keys[0];
                return node('img', params, null, v.alt ? [v.alt] : []);
            },
        },
        // The engine gives a video and an audio their play controls (TagRegistry's defaults).
        video: { texts: () => 0, build: (v) => node('video', { src: v.file }, null, []) },
        audio: { texts: () => 0, build: (v) => node('audio', { src: v.file }, null, []) },
        list: {
            texts: (v) => v.items.length,
            build: (v, keys) => ({
                command: 'addComplexElement',
                body: { kind: 'list', config: { tag: 'ul', items: keys.map(k => ({ labelKey: k })) } },
                texts: keys.map((k, i) => ({ key: k, value: v.items[i] })),
            }),
        },
        block: { texts: () => 0, build: () => node('div', null, null, []) },
    });

    /** An addNode plan: the tag, its attributes, the key of its text (a child of the element), the texts to key. */
    function node(tag, params, textKey, values) {
        const body = { tag: tag };
        if (params) body.params = params;
        if (textKey) body.textKey = textKey;
        const texts = [];
        if (values.length) {
            const key = textKey || (params && params.alt);
            texts.push({ key: key, value: values[0] });
        }
        return { command: 'addNode', body: body, texts: texts };
    }

    /**
     * The command, body and texts one item sends, without its target (preview.js's to add).
     *
     * @param {string} name   an item of PRESETS
     * @param {Object} values the form's values
     * @param {string[]} keys the generated key names, as many as the item's texts
     * @param {{inForm?: boolean}} env
     * @returns {{command: string, body: Object, texts: {key: string, value: string}[]}}
     */
    function plan(name, values, keys, env) {
        const preset = PRESETS[name];
        if (!preset) throw new Error('No simple Add item named ' + name);
        return preset.build(values, keys, env || {});
    }

    /** How many keys an item needs for these values. */
    function textCount(name, values) {
        return PRESETS[name] ? PRESETS[name].texts(values) : 0;
    }

    /**
     * The prefix of the keys an element of this structure gets, as the engine names the keys it generates:
     * a page's name (a nested page's slashes read as dots), menu, footer, component.<name>.
     */
    function keyPrefix(structInfo) {
        if (!structInfo) return 'page';
        if (structInfo.type === 'component') return 'component.' + structInfo.name;
        if (structInfo.type === 'page' && structInfo.name) return String(structInfo.name).split('/').filter(Boolean).join('.');
        return structInfo.type;
    }

    /** `count` new key names after the highest <prefix>.item<N> among the existing keys. */
    function nextKeys(prefix, existing, count) {
        const head = prefix + '.item';
        let max = 0;
        (existing || []).forEach(k => {
            if (k.indexOf(head) !== 0) return;
            const rest = k.slice(head.length);
            if (/^[0-9]+$/.test(rest)) max = Math.max(max, parseInt(rest, 10));
        });
        const out = [];
        for (let i = 1; i <= count; i++) out.push(head + (max + i));
        return out;
    }

    /** A nested translations object from dot-separated keys: {a: {b: value}}. */
    function nested(pairs) {
        const out = {};
        pairs.forEach(p => {
            const parts = p.key.split('.');
            let ref = out;
            for (let i = 0; i < parts.length - 1; i++) {
                if (typeof ref[parts[i]] !== 'object' || ref[parts[i]] === null) ref[parts[i]] = {};
                ref = ref[parts[i]];
            }
            ref[parts[parts.length - 1]] = p.value;
        });
        return out;
    }

    /** Every key of a translations object, dot-joined. */
    function flatKeys(obj, prefix, out) {
        if (!obj || typeof obj !== 'object') return out;
        Object.keys(obj).forEach(k => {
            const full = prefix ? prefix + '.' + k : k;
            if (obj[k] && typeof obj[k] === 'object') flatKeys(obj[k], full, out);
            else out.push(full);
        });
        return out;
    }

    // ──────────────────────────── The form ────────────────────────────

    let ctx = null;
    let current = 'text';
    let root = null;

    function $(id) { return document.getElementById(id); }

    function word(key, fallback) {
        const words = (window.PreviewConfig && PreviewConfig.i18n && PreviewConfig.i18n.simpleAdd) || {};
        return typeof words[key] === 'string' ? words[key] : fallback;
    }

    /**
     * Wire the form. `context` comes from preview.js:
     *   target()          {struct, node, frameNode}: the selection the Add form acts on
     *   position()        'before' | 'after' | 'inside'
     *   structInfo()      parseStruct(struct): {type, name}
     *   frameDocument()   the preview's document, or null
     *   editedLanguage()  the language being edited
     *   openAssetPicker(input, category, options)
     *   holdsChildren()   whether the selected element can hold others
     *   setPosition(p)    put the form on a position
     *   finish(result)    the element was added: {command, data, position, target}
     */
    function init(context) {
        ctx = context;
        root = $('add-simple');
        if (!root) return;
        root.querySelectorAll('.preview-simple-add__item').forEach(btn => {
            btn.addEventListener('click', () => pick(btn.dataset.preset));
        });
        const more = $('simple-add-list-more');
        if (more) more.addEventListener('click', () => {
            const rows = $('simple-add-list-items');
            rows.appendChild(_renderListRow());
            renumberList();
            rows.lastElementChild.querySelector('input').focus();
        });
        const rows = $('simple-add-list-items');
        if (rows) rows.addEventListener('click', (e) => {
            const remove = e.target.closest('.preview-simple-add__list-remove');
            if (!remove || rows.children.length <= 1) return;
            remove.closest('.preview-simple-add__list-row').remove();
            renumberList();
        });
        root.querySelectorAll('input[name="simple-add-size"]').forEach(r => r.addEventListener('change', refresh));
        const page = $('simple-add-link-page');
        if (page) page.addEventListener('change', syncLinkTarget);
        const choose = $('simple-add-file-choose');
        if (choose) choose.addEventListener('click', openPicker);
    }

    /** One line of the List: its field and its remove button. Returns ONE Element (renumberList() names it). */
    function _renderListRow() {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'admin-input';
        input.autocomplete = 'off';
        input.setAttribute('data-simple-add-list-item', '');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'admin-btn admin-btn--ghost preview-simple-add__list-remove';
        remove.setAttribute('aria-label', word('listRemove', 'Remove this item'));
        remove.title = word('listRemove', 'Remove this item');
        remove.textContent = '×';
        const row = document.createElement('div');
        row.className = 'preview-simple-add__list-row';
        row.append(input, remove);
        return row;
    }

    /** The List's lines numbered in order (their ids and placeholders), and the remove button hidden on the last one left. */
    function renumberList() {
        const rows = Array.from(($('simple-add-list-items') || document.createElement('div')).children);
        rows.forEach((row, i) => {
            const input = row.querySelector('input');
            input.id = 'simple-add-list-' + (i + 1);
            input.placeholder = word('listItem', 'Item {n}').split('{n}').join(String(i + 1));
            row.querySelector('.preview-simple-add__list-remove').style.display = rows.length > 1 ? '' : 'none';
        });
    }

    /** The List back to its first three empty lines. */
    function resetList() {
        const rows = $('simple-add-list-items');
        if (!rows) return;
        rows.replaceChildren();
        for (let i = 0; i < LIST_ITEMS; i++) rows.appendChild(_renderListRow());
        renumberList();
    }

    /** Show the simple form, fresh: Text picked, every field empty. */
    function open() {
        if (!root) return;
        root.style.display = '';
        resetList();
        root.querySelectorAll('input[type="text"], input[type="url"], textarea').forEach(el => { el.value = ''; });
        const newTab = $('simple-add-link-new-tab');
        if (newTab) newTab.checked = false;
        const page = $('simple-add-link-page');
        if (page) page.value = '';
        // The Title starts on Big where the page has no big title yet, on Medium where it has one.
        const size = root.querySelector('input[name="simple-add-size"][value="' + (pageHasBigTitle() ? 'medium' : 'big') + '"]');
        if (size) size.checked = true;
        document.querySelector('.preview-asset-picker')?.remove();
        const row = $('simple-add-file')?.closest('.preview-contextual-form__param-row');
        row?.querySelector('.preview-asset-inline')?.remove();
        loadPages();
        // An empty element that can hold others is there to be filled: Inside. Anything else keeps the form's After.
        const target = frameElement();
        if (target && ctx.holdsChildren() && target.children.length === 0 && target.textContent.trim() === '') ctx.setPosition('inside');
        pick('text');
    }

    /** Hide the simple form (the advanced form is open, or the Add form closed). */
    function close() {
        if (root) root.style.display = 'none';
        document.querySelector('.preview-asset-picker')?.remove();
    }

    function isOpen() {
        return !!root && root.style.display !== 'none';
    }

    /** Pick an item: its form shows. Image, Video and Audio pick their file with Choose…, which opens the Media picker. */
    function pick(name) {
        if (!PRESETS[name] || !root) return;
        const was = current;
        current = name;
        root.querySelectorAll('.preview-simple-add__item').forEach(btn => {
            btn.setAttribute('aria-pressed', btn.dataset.preset === name ? 'true' : 'false');
        });
        root.querySelectorAll('[data-preset-fields]').forEach(el => {
            el.style.display = el.dataset.presetFields.split(' ').indexOf(name) !== -1 ? '' : 'none';
        });
        const isMedia = !!MEDIA_CATEGORY[name];
        if (isMedia) {
            const alt = $('simple-add-alt-field');
            if (alt) alt.style.display = name === 'image' ? '' : 'none';
            const controls = $('simple-add-media-controls');
            if (controls) controls.style.display = name === 'image' ? 'none' : '';
            // A file picked for another kind of media is not this one's.
            if (was !== name) {
                const file = $('simple-add-file');
                if (file) file.value = '';
                file?.closest('.preview-contextual-form__param-row')?.querySelector('.preview-asset-inline')?.remove();
            }
        }
        refresh();
    }

    /** The hints that follow the page and the position: the Title's big-title hint, the Button's form. */
    function refresh() {
        if (!root) return;
        const big = root.querySelector('input[name="simple-add-size"]:checked')?.value === 'big';
        const hint = $('simple-add-title-hint');
        if (hint) hint.style.display = big && pageHasBigTitle() ? '' : 'none';
        const inForm = landsInForm();
        const inHint = $('simple-add-button-in-form');
        const noHint = $('simple-add-button-no-form');
        if (inHint) inHint.style.display = inForm ? '' : 'none';
        if (noHint) noHint.style.display = inForm ? 'none' : '';
    }

    function syncLinkTarget() {
        const page = $('simple-add-link-page');
        const urlField = $('simple-add-link-url-field');
        const outside = page && page.value === 'outside';
        if (urlField) urlField.style.display = outside ? '' : 'none';
        if (outside) $('simple-add-link-url')?.focus();
    }

    function openPicker() {
        const input = $('simple-add-file');
        if (!input || !ctx || !MEDIA_CATEGORY[current]) return;
        ctx.openAssetPicker(input, MEDIA_CATEGORY[current], { upload: true, accept: MEDIA_ACCEPT[current] });
    }

    /** The site's pages for the Link, a page with a parameter left out (it needs a value to link to). */
    async function loadPages() {
        const select = $('simple-add-link-page');
        if (!select || !window.QuickSiteAdmin) return;
        const outside = select.querySelector('[data-simple-add-outside]');
        select.querySelectorAll('option[data-simple-add-page]').forEach(o => o.remove());
        let routes = [];
        try {
            routes = await QuickSiteAdmin.fetchHelperData('routes', []);
        } catch (e) {
            routes = [];
        }
        (Array.isArray(routes) ? routes : []).forEach(route => {
            const value = String(route.value || '');
            if (value.indexOf(':') !== -1) return;
            const option = document.createElement('option');
            option.value = '/' + value;
            option.textContent = '/' + String(route.label || value);
            option.setAttribute('data-simple-add-page', '');
            select.insertBefore(option, outside);
        });
    }

    // ──────────────────────────── What the page says ────────────────────────────

    function frameElement() {
        const doc = ctx && ctx.frameDocument();
        const t = ctx && ctx.target();
        if (!doc || !t || t.struct == null) return null;
        const esc = (s) => (window.CSS && CSS.escape) ? CSS.escape(String(s)) : String(s).replace(/"/g, '\\"');
        const nodeId = t.frameNode != null ? t.frameNode : t.node;
        return doc.querySelector('[data-qs-struct="' + esc(t.struct) + '"][data-qs-node="' + esc(nodeId) + '"]')
            || doc.querySelector('[data-qs-struct="' + esc(t.struct) + '"] [data-qs-node="' + esc(nodeId) + '"]');
    }

    /** Whether the page shown already has a big title (an h1), menu and footer included. */
    function pageHasBigTitle() {
        const doc = ctx && ctx.frameDocument();
        return !!(doc && doc.querySelector('h1'));
    }

    /** Whether an element added at the chosen position lands inside a form. */
    function landsInForm() {
        const el = frameElement();
        if (!el) return false;
        const pos = ctx.position();
        const holder = pos === 'inside' ? el : el.parentElement;
        return !!(holder && holder.closest('form'));
    }

    // ──────────────────────────── Adding ────────────────────────────

    /** The form's values for the item picked. */
    function read() {
        const val = (id) => ($(id)?.value || '').trim();
        const page = $('simple-add-link-page')?.value || '';
        return {
            text: current === 'title' ? val('simple-add-title-text')
                : current === 'button' ? val('simple-add-button-text')
                : current === 'link' ? val('simple-add-link-text')
                : val('simple-add-text'),
            size: root.querySelector('input[name="simple-add-size"]:checked')?.value || 'medium',
            href: page === 'outside' ? val('simple-add-link-url') : page,
            outside: page === 'outside',
            newTab: !!$('simple-add-link-new-tab')?.checked,
            file: val('simple-add-file'),
            alt: val('simple-add-alt'),
            // An item left empty starts with its own placeholder's words, so the list always starts with three.
            items: Array.from(root.querySelectorAll('[data-simple-add-list-item]')).map(el => el.value.trim() || el.placeholder),
        };
    }

    /** What is missing before this item can be added: [message, the field to focus], or null. */
    function missing(v) {
        if (['text', 'title', 'button', 'link'].indexOf(current) !== -1 && v.text === '') {
            const id = { text: 'simple-add-text', title: 'simple-add-title-text', button: 'simple-add-button-text', link: 'simple-add-link-text' }[current];
            return [word('textRequired', 'Type the text first.'), id];
        }
        if (current === 'link') {
            if (v.href === '') {
                return v.outside ? [word('urlRequired', 'Type the address.'), 'simple-add-link-url']
                    : [word('pageRequired', 'Choose a page, or an outside address.'), 'simple-add-link-page'];
            }
            if (v.outside && !OUTSIDE_SCHEMES.some(s => v.href.toLowerCase().indexOf(s) === 0)) {
                return [word('urlInvalid', 'Type the full address, starting with https://'), 'simple-add-link-url'];
            }
        }
        if (MEDIA_CATEGORY[current] && v.file === '') {
            return [word('fileRequired', 'Choose a file first.'), 'simple-add-file-choose'];
        }
        return null;
    }

    async function languages() {
        const res = await QuickSiteAdmin.apiRequest('getLangList', 'GET');
        const data = (res && res.data && (res.data.data || res.data)) || {};
        const list = Array.isArray(data.languages) ? data.languages : [];
        return list.length ? list : [ctx.editedLanguage()];
    }

    async function existingKeys(lang) {
        const res = await QuickSiteAdmin.apiRequest('getTranslations', 'GET', null, [lang]);
        if (!res || !res.ok) throw new Error(res?.data?.message || 'getTranslations');
        const data = (res.data && (res.data.data || res.data)) || {};
        // The keys come under their language file: {translations: {<lang>: {...}}}, and on a one-language site under `default`
        // (its one file, default.json, which setTranslationKeys writes whatever language it is given there).
        const all = data.translations || {};
        const tree = all[lang] && typeof all[lang] === 'object' ? all[lang]
            : (all.default && typeof all.default === 'object' ? all.default : all);
        return flatKeys(tree, '', []);
    }

    /** Write the texts in every language (the text in the edited one, empty in the others). */
    async function writeKeys(texts, langs, edited) {
        const done = [];
        for (const lang of langs) {
            const res = await QuickSiteAdmin.apiRequest('setTranslationKeys', 'POST', {
                language: lang,
                translations: nested(texts.map(t => ({ key: t.key, value: lang === edited ? t.value : '' }))),
            });
            if (!res || !res.ok) {
                await deleteKeys(texts.map(t => t.key), done);
                throw new Error(res?.data?.message || word('keysFailed', 'The text could not be saved, so nothing was added.'));
            }
            done.push(lang);
        }
    }

    async function deleteKeys(keys, langs) {
        for (const lang of langs) {
            try {
                await QuickSiteAdmin.apiRequest('deleteTranslationKeys', 'POST', { language: lang, keys: keys });
            } catch (e) {
                console.warn('[SimpleAdd] could not take back the keys in ' + lang, e);
            }
        }
    }

    /**
     * Add the item picked at the Add form's target and position. Resolves when it is added; throws with the
     * message to show when it is not (nothing then stays behind).
     */
    async function submit() {
        const v = read();
        const gap = missing(v);
        if (gap) {
            if (window.QuickSiteAdmin && QuickSiteAdmin.showToast) QuickSiteAdmin.showToast(gap[0], 'warning');
            $(gap[1])?.focus();
            return false;
        }
        const t = ctx.target();
        const info = ctx.structInfo();
        if (!info || !info.type) throw new Error('Invalid structure type');
        const isRoot = !t.node && t.node !== 0;
        const position = isRoot ? 'inside' : ctx.position();
        const env = { inForm: current === 'button' && landsInForm() };

        const count = textCount(current, v);
        let langs = [];
        let keys = [];
        if (count > 0) {
            langs = await languages();
            keys = nextKeys(keyPrefix(info), await existingKeys(langs[0]), count);
        }
        const p = plan(current, v, keys, env);

        let body;
        if (p.command === 'addComplexElement') {
            body = Object.assign({}, p.body, {
                structType: info.type, pageName: info.name || undefined,
                targetNodeId: isRoot ? 'root' : String(t.node), position: position,
            });
        } else {
            body = Object.assign({ type: info.type, targetNodeId: isRoot ? 'root' : String(t.node), position: position }, p.body);
            if (info.name) body.name = info.name;
        }

        if (p.texts.length) await writeKeys(p.texts, langs, ctx.editedLanguage());
        const res = await QuickSiteAdmin.apiRequest(p.command, 'POST', body);
        if (!res || !res.ok) {
            if (p.texts.length) await deleteKeys(p.texts.map(x => x.key), langs);
            throw new Error(res?.data?.message || word('addFailed', 'The element could not be added.'));
        }
        ctx.finish({ command: p.command, data: (res.data && res.data.data) || {}, position: position, target: t });
        return true;
    }

    /** Whether anything has been typed or picked in the simple form (a switch of mode would drop it). */
    function hasInput() {
        if (!isOpen()) return false;
        return Array.from(root.querySelectorAll('input[type="text"], input[type="url"], textarea')).some(el => el.value.trim() !== '')
            || !!$('simple-add-link-new-tab')?.checked
            || ($('simple-add-link-page')?.value || '') !== '';
    }

    return {
        init, open, close, isOpen, pick, refresh, submit, hasInput,
        // For the probes: what an item sends, and how its keys are named.
        plan, textCount, keyPrefix, nextKeys, nested,
        TITLE_TAGS, PRESETS,
    };
})();
