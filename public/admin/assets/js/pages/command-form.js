/**
 * Command Form Page JavaScript — /admin/command/<name>.
 * Extracted from command-form.php for browser caching.
 *
 * Dependencies:
 * - QuickSiteAdmin global (from admin.js) — apiRequest, populateSelect,
 *   appendOptionsToSelect, fetchHelperData, showToast, displayResponse
 * - QSDom (from js/core/dom.js, loaded in the layout <head>) — el,
 *   setSelectPlaceholder, clear
 * - QuickSiteUtils (from js/core/utils.js) — svgIcon, ICON_PATHS
 * - window.QS_COMMAND_FORM_I18N, emitted by command-form.php: the
 *   commandForm / commands / common translation sub-trees, under the same
 *   dot paths PHP uses. Read it through t() below, never directly.
 *
 * DOM is built with createElement + textContent through QSDom and named
 * _render* helpers, per the CLAUDE.md HTML-in-JS hygiene rule.
 *
 * @version 1.0.0
 */
(function() {
    'use strict';

    function init() {
        // Wait for QuickSiteAdmin to be available
        if (typeof QuickSiteAdmin === 'undefined') {
            setTimeout(init, 50);
            return;
        }

        // Get configuration from data attributes
        const container = document.querySelector('.admin-command-form-page');
        if (!container) return;

const COMMAND_NAME = container.dataset.commandName || '';

/**
 * Resolve one admin string by its FULL dot path, from the sub-trees
 * command-form.php emits.
 *
 * A path that resolves to nothing returns THE PATH ITSELF. That is
 * deliberate: an untranslated string has to be visible on screen and
 * findable by a scan, which a hardcoded English fallback would hide.
 *
 * @param {string} path            e.g. 'commandForm.select.typeFirst'
 * @param {Object} [params]        :name markers to substitute, as PHP's t() does
 * @returns {string}
 */
function t(path, params) {
    let node = window.QS_COMMAND_FORM_I18N || {};
    const parts = String(path).split('.');
    for (const part of parts) {
        if (node === null || typeof node !== 'object' || !(part in node)) return path;
        node = node[part];
    }
    if (typeof node !== 'string') return path;
    let value = node;
    if (params) {
        for (const name of Object.keys(params)) {
            value = value.split(':' + name).join(String(params[name]));
        }
    }
    return value;
}

/**
 * A one-line hint/blurb paragraph.
 * @param {Array<Node|string|null>} children
 * @param {string} [cls]
 * @returns {HTMLParagraphElement}
 */
function _renderHint(children, cls) {
    return QSDom.el('p', { class: cls || 'admin-hint' }, children);
}

/**
 * A form label.
 * @param {string} text
 * @param {string} [cls]
 * @param {string} [forId]
 * @returns {HTMLLabelElement}
 */
function _renderLabel(text, cls, forId) {
    const props = { class: cls || 'admin-label', text: text };
    if (forId) props.for = forId;
    return QSDom.el('label', props);
}

/**
 * A <button type="button">, so it never submits the form it sits in.
 * @param {string} id
 * @param {string} cls
 * @param {string} text
 * @param {string} [title]
 * @returns {HTMLButtonElement}
 */
function _renderButton(id, cls, text, title) {
    const props = { type: 'button', class: cls, text: text };
    if (id) props.id = id;
    if (title) props.title = title;
    return QSDom.el('button', props);
}

/**
 * Render one translated sentence that carries inline markup.
 *
 * The alternative is splitting a sentence across four keys so that one word
 * can be bold, which is unusable for whoever translates it. Instead the key
 * holds the whole sentence with :name markers, and each marker is replaced
 * by a real node — so the markup survives and the translator still sees a
 * sentence.
 *
 * @param {string} template  a resolved string containing :name markers
 * @param {Object<string,Node|string>} parts  marker name -> node or text
 * @returns {DocumentFragment}
 */
function _renderRich(template, parts) {
    const frag = document.createDocumentFragment();
    const names = Object.keys(parts || {});
    // Longest marker first, so :confirmValue is not eaten by :confirm.
    names.sort((a, b) => b.length - a.length);
    const pattern = names.length
        ? new RegExp(':(' + names.map(n => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'g')
        : null;
    let cursor = 0;
    if (pattern) {
        let match;
        while ((match = pattern.exec(template)) !== null) {
            if (match.index > cursor) {
                frag.appendChild(document.createTextNode(template.slice(cursor, match.index)));
            }
            const part = parts[match[1]];
            frag.appendChild(typeof part === 'string' ? document.createTextNode(part) : part);
            cursor = match.index + match[0].length;
        }
    }
    if (cursor < template.length) {
        frag.appendChild(document.createTextNode(template.slice(cursor)));
    }
    return frag;
}

/**
 * The page's own error block, for the two failures that leave no form to fill.
 * @param {string} message
 * @returns {HTMLDivElement}
 */
function _renderAlertError(message) {
    return QSDom.el('div', { class: 'admin-alert admin-alert--error', text: message });
}

/**
 * The centred placeholder line inside a component-data field area.
 * @param {string} text
 * @param {boolean} [isError]
 * @returns {HTMLParagraphElement}
 */
function _renderCenteredHint(text, isError) {
    return QSDom.el('p', {
        class: isError ? 'admin-hint admin-hint--error' : 'admin-hint',
        style: 'text-align: center; padding: var(--space-md);',
        text: text
    });
}

/**
 * The component-data builder shell, shared by addComponentToNode and
 * editComponentToNode — same structure, different wording and field id.
 *
 * @param {string} fieldsId    id for the fields area the form later fills
 * @param {string} subtitleKey translation path for the header subtitle
 * @param {string} emptyKey    translation path for the initial empty line
 * @returns {HTMLDivElement}
 */
function _renderComponentDataBuilder(fieldsId, subtitleKey, emptyKey) {
    return QSDom.el('div', { class: 'admin-component-data-builder' }, [
        QSDom.el('div', { class: 'admin-component-data-header' }, [
            QSDom.el('span', { class: 'admin-label', text: t('commandForm.componentData.title') }),
            QSDom.el('span', { class: 'admin-hint', text: t(subtitleKey) })
        ]),
        QSDom.el('div', { class: 'admin-component-data-fields', id: fieldsId }, [
            _renderCenteredHint(t(emptyKey))
        ])
    ]);
}

/** Replace a container's contents with one centred hint line. */
function _showCenteredHint(container, text, isError) {
    if (!container) return;
    QSDom.clear(container);
    container.appendChild(_renderCenteredHint(text, isError));
}

/**
 * One editable row per component variable, shared by addComponentToNode and
 * editComponentToNode — the two differ only in the id prefix and in whether
 * they seed the inputs with the node's current data.
 *
 * The variable's NAME and TYPE come from the project's own component
 * definitions through fetchHelperData. They land in an id, two data-*
 * attributes, a label and a placeholder, all set through the DOM rather than
 * glued into a markup string.
 *
 * @param {Array<{name: string, type?: string}>} variables
 * @param {string} idPrefix                 e.g. 'data-var-'
 * @param {Object<string,string>} [currentData]  existing values, by variable name
 * @returns {HTMLDivElement} the table wrapper — ONE element
 */
function _renderComponentDataTable(variables, idPrefix, currentData) {
    const table = QSDom.el('div', { class: 'admin-data-table' });
    const data = currentData || {};

    for (const variable of variables) {
        const varName = variable.name;
        const varType = variable.type || 'string';
        const inputId = idPrefix + varName;
        const currentValue = data[varName] || '';

        const input = QSDom.el('input', {
            type: 'text',
            id: inputId,
            class: 'admin-input',
            'data-var-input': varName,
            placeholder: varType === 'textKey'
                ? t('commandForm.componentData.textKeyPlaceholder')
                : t('commandForm.componentData.valuePlaceholder', { name: varName })
        });
        if (currentValue) input.value = currentValue;

        let inputArea;
        if (varType === 'textKey') {
            const keySelect = QSDom.el('select', {
                id: inputId + '-select',
                class: 'admin-select admin-select--textkey',
                'data-target': inputId
            });
            QSDom.setSelectPlaceholder(keySelect, t('commandForm.componentData.loadingKeys'));
            inputArea = QSDom.el('div', { class: 'admin-textkey-selector' }, [
                keySelect,
                QSDom.el('span', {
                    class: 'admin-hint',
                    text: t('commandForm.componentData.orEnterManually')
                }),
                input
            ]);
        } else {
            inputArea = input;
        }

        table.appendChild(QSDom.el('div', {
            class: 'admin-data-row',
            dataset: { varName: varName, varType: varType }
        }, [
            QSDom.el('div', { class: 'admin-data-label' }, [
                _renderLabel(varName, 'admin-label', inputId),
                QSDom.el('span', { class: 'admin-badge admin-badge--small', text: varType })
            ]),
            QSDom.el('div', { class: 'admin-data-input' }, [inputArea])
        ]));
    }

    return table;
}

// Load documentation immediately (init() already waits for DOM ready)
loadCommandDocumentation();

async function loadCommandDocumentation() {
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET', null, [COMMAND_NAME]);
        
        if (result.ok && result.data.data) {
            const doc = result.data.data;
            renderCommandForm(doc);
            renderCommandDocs(doc);
            // Initialize enhanced features after form renders
            await initEnhancedFeatures();
        } else {
            const target = document.getElementById('command-params');
            QSDom.clear(target);
            target.appendChild(_renderAlertError(t('commandForm.errors.docNotFound')));
        }
    } catch (error) {
        const target = document.getElementById('command-params');
        QSDom.clear(target);
        target.appendChild(_renderAlertError(
            t('commandForm.errors.docLoadFailed') + ' ' + error.message
        ));
    }
}

/**
 * Initialize enhanced features for complex commands
 */
/**
 * The identifier-picker table.
 *
 * Command forms whose field names something that ALREADY EXISTS: a page, a
 * route, an API, an endpoint, a snippet, a language. One mechanism wired many
 * times rather than many implementations - a row here is the whole wiring, and
 * a new command is a row.
 *
 * A row is one spec, or an ARRAY of them when a command carries two pickers
 * (insertSnippet takes a page AND a snippet; importStructureTranslations takes
 * a route AND a language).
 *
 * ⚠ A field is only ever wired when its own help.php description says it
 * REFERENCES an existing thing. A field that NAMES something being created
 * (createProject.name, addStorageItem.id) and a field that merely ECHOES the
 * URL marker (backupProject.name - "the project backed up is ALWAYS the one in
 * the URL marker") are both left as text boxes. A dropdown on either would
 * offer a choice that is refused if taken.
 *
 * `kind` picks the shape:
 *   'select'    the route must already exist -> a real dropdown of routes.
 *   'suggest'   a valid value may be OUTSIDE the list -> keep the text input,
 *               hang a <datalist> on it and say so in a hint. Only three
 *               commands need this: setStateStores accepts the special pages
 *               404/500/403/401 that getRoutes does not return, and the two
 *               policy generators NAME a route they are about to create.
 *   'structure' the parameter means a page OR a component depending on a
 *               type/structType field beside it -> convert both to selects and
 *               cascade, the shape editStructure already uses.
 *
 * `allowEmpty` keeps the blank option selectable where blank is meaningful:
 * getStateStores reads "omit to retrieve stores for ALL routes".
 */
// Documented value sets, read from each parameter's own help.php description.
// Bare labels and no translation keys: these are the literal strings the
// command receives, and the boolean renderer below labels its options
// 'true'/'false' for the same reason.
const ENUM_VALUES = {
    // "Where to insert: before, after, or inside". The labels REUSE the keys
    // initAddComponentToNodeForm already uses for the same three values - one
    // set of words for one concept, and they are prose worth translating.
    position: [
        { value: 'before', labelKey: 'commandForm.addComponent.positionBefore' },
        { value: 'after', labelKey: 'commandForm.addComponent.positionAfter' },
        { value: 'inside', labelKey: 'commandForm.addComponent.positionInside' }
    ],
    // "Page-level event the interaction sits on: onload, onresize or onscroll".
    // Literal DOM event names, not prose - labelled bare, the same reasoning
    // the boolean renderer uses for 'true'/'false'.
    pageEvent: [
        { value: 'onload' }, { value: 'onresize' }, { value: 'onscroll' }
    ]
};

const FIELD_PICKERS = {
    // --- the route must already exist: a real dropdown ---------------------
    setRouteResolver:            { kind: 'select', param: 'route', source: 'routes' },
    importStructureTranslations: [
        { kind: 'select', param: 'route', source: 'routes' },
        { kind: 'select', param: 'language', source: 'languages' }
    ],
    getStateStores:              { kind: 'select', param: 'route', source: 'routes', allowEmpty: true },
    addPageEvent:                { kind: 'select', param: 'pageName', source: 'routes' },
    editPageEvent: [
        { kind: 'select', param: 'pageName', source: 'routes' },
        { kind: 'enum', param: 'event', values: 'pageEvent' }
    ],
    deletePageEvent: [
        { kind: 'select', param: 'pageName', source: 'routes' },
        { kind: 'enum', param: 'event', values: 'pageEvent' }
    ],
    getPageEvents:               { kind: 'select', param: 'pageName', source: 'routes' },

    // --- a valid value may be OUTSIDE the list: input + suggestions --------
    // setStateStores accepts the special pages 404/500/403/401, which getRoutes
    // does not return; the two policy commands NAME a route they are about to
    // create. A <select> would refuse all three.
    setStateStores:              { kind: 'suggest', param: 'route' },
    generateCookiePolicy:        { kind: 'suggest', param: 'route' },
    generatePrivacyPolicy:       { kind: 'suggest', param: 'route' },

    // --- API and endpoint. Endpoints arrive nested inside each api, so the
    //     cascade filters what it already has instead of fetching again. A
    //     blank api means "no filter", which is what the optional apiId
    //     parameters document, so the endpoint list then spans every api.
    editApi:            { kind: 'api', apiParam: 'apiId' },
    deleteApi:          { kind: 'api', apiParam: 'apiId' },
    listApiEndpoints:   { kind: 'api', apiParam: 'apiId', allowEmpty: true },
    getApiEndpoint:     { kind: 'api', apiParam: 'apiId', endpointParam: 'endpointId', allowEmpty: true },
    testApiEndpoint:    { kind: 'api', apiParam: 'apiId', endpointParam: 'endpointId', allowEmpty: true },
    cleanResolverCache: [
        { kind: 'api', apiParam: 'apiId', allowEmpty: true },
        // "Exact endpoint reference (@apiId/endpointId)" - the compound form.
        { kind: 'api', endpointRefParam: 'endpoint', allowEmpty: true }
    ],

    // --- Snippet -----------------------------------------------------------
    getSnippet:       { kind: 'snippet', param: 'id' },
    injectSnippetCss: { kind: 'snippet', param: 'id' },
    duplicateSnippet: { kind: 'snippet', param: 'id' },
    // ⚠ user snippets only: "Core snippets cannot be deleted", so offering one
    // would offer a choice the command refuses.
    deleteSnippet:    { kind: 'snippet', param: 'id', userOnly: true },

    // --- Language: the languages the project ALREADY HAS -------------------
    setStorageDescLang: { kind: 'select', param: 'lang', source: 'languages' },
    setPrivacyDescLang: { kind: 'select', param: 'lang', source: 'languages' },

    // --- the parameter is a page OR a component, per its type field -------
    moveNode:          { kind: 'structure', typeParam: 'type',       param: 'name' },
    deleteNode:        { kind: 'structure', typeParam: 'type',       param: 'name' },
    duplicateNode:     { kind: 'structure', typeParam: 'type',       param: 'name' },
    addNode: [
        { kind: 'structure', typeParam: 'type', param: 'name' },
        // "default after" — optional, so the select shows what it will send.
        { kind: 'enum', param: 'position', values: 'position', defaultValue: 'after' }
    ],
    editNode:          { kind: 'structure', typeParam: 'type',       param: 'name' },
    insertSnippet: [
        { kind: 'structure', typeParam: 'type', param: 'name' },
        { kind: 'snippet', param: 'snippetId' }
    ],
    addComplexElement: [
        { kind: 'structure', typeParam: 'structType', param: 'pageName' },
        // 'Default "after"' — optional.
        { kind: 'enum', param: 'position', values: 'position', defaultValue: 'after' }
    ],
    listInteractions:  { kind: 'structure', typeParam: 'structType', param: 'pageName' },
    addInteraction:    { kind: 'structure', typeParam: 'structType', param: 'pageName' },
    editInteraction:   { kind: 'structure', typeParam: 'structType', param: 'pageName' },
    deleteInteraction: { kind: 'structure', typeParam: 'structType', param: 'pageName' }
};

/**
 * Swap a text input for one carrying a <datalist> of the project's routes.
 *
 * The input stays an input, so any value remains typable; the list is a
 * suggestion, not a constraint.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a PAGE_PICKERS row
 * @returns {Promise<void>}
 */
async function _initRouteSuggest(form, cfg) {
    const input = form.querySelector('[name="' + cfg.param + '"]');
    if (!input || input.tagName === 'SELECT' || input.list) return;

    const listId = 'qs-routes-' + cfg.param;
    const datalist = QSDom.el('datalist', { id: listId });
    input.setAttribute('list', listId);
    input.parentNode.insertBefore(datalist, input.nextSibling);

    let routes = [];
    try {
        routes = await QuickSiteAdmin.fetchHelperData('routes');
    } catch (error) {
        return; // no suggestions; the field still works as a plain text box
    }
    if (!Array.isArray(routes)) return;

    QSDom.clear(datalist);
    routes.forEach(r => {
        datalist.appendChild(QSDom.el('option', { value: r.value }));
    });

    if (!input.placeholder) {
        input.placeholder = t('commandForm.select.routeSuggest');
    }

    // Say out loud that the list is a suggestion. Without this the field is
    // indistinguishable from a plain text box until the caller happens to
    // click it, which is how it read as "not implemented".
    if (!input.parentNode.querySelector('.qs-route-suggest-hint')) {
        input.parentNode.insertBefore(
            QSDom.el('p', {
                class: 'admin-hint qs-route-suggest-hint',
                text: t('commandForm.hint.routeSuggest')
            }),
            datalist.nextSibling);
    }
}

/**
 * Swap a text input for a dropdown fed by one of the /admin/api helper arms.
 *
 * Used where the parameter's own description says the value must already
 * exist, so constraining it refuses nothing valid.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a FIELD_PICKERS row; cfg.source names the arm
 * @returns {Promise<HTMLSelectElement|null>}
 */
async function _initArmSelect(form, cfg) {
    const select = _swapForSelect(form, cfg.param);
    if (!select) return null;

    // allowEmpty keeps the blank option meaningful: getStateStores reads
    // "omit to retrieve stores for ALL routes".
    const placeholder = cfg.source === 'languages'
        ? t('commandForm.select.language')
        : (cfg.allowEmpty ? t('commandForm.select.allRoutes') : t('commandForm.select.route'));

    await QuickSiteAdmin.populateSelect(select, cfg.source || 'routes', [], placeholder);
    return select;
}

/**
 * Replace a form field with an empty <select> carrying its name, its required
 * flag and its url-param marker. Returns null when the field is missing or has
 * already been converted, so every caller can no-op safely.
 *
 * @param {HTMLFormElement} form
 * @param {string} paramName
 * @returns {HTMLSelectElement|null}
 */
function _swapForSelect(form, paramName) {
    const input = form.querySelector('[name="' + paramName + '"]');
    if (!input || input.tagName === 'SELECT') return null;

    const select = QSDom.el('select', { name: paramName, class: 'admin-select' });
    if (input.required) select.required = true;
    if (input.dataset.urlParam !== undefined) select.dataset.urlParam = '';
    input.replaceWith(select);
    return select;
}

/**
 * Fill a <select> with {value, label} rows behind a placeholder.
 *
 * @param {HTMLSelectElement} select
 * @param {Array<{value: string, label: string}>} rows
 * @param {string} placeholder
 */
function _fillSelect(select, rows, placeholder) {
    QSDom.setSelectPlaceholder(select, placeholder);
    rows.forEach(r => {
        select.appendChild(QSDom.el('option', { value: r.value, text: r.label }));
    });
}

/**
 * Replace a text input with a select of documented literal values.
 *
 * No fetch and no source: the values come from the parameter's own description.
 * cfg.defaultValue preselects the documented default, so a field that would
 * otherwise default SERVER-side shows what it is about to send. A parameter
 * with no documented default starts on the blank placeholder instead - which
 * on a required field is what makes it an explicit choice.
 *
 * ⚠ Every value is offered even where the description says one is FORCED under
 * some condition (position becomes "inside" when targetNodeId="root"). The
 * server enforces that; a dropdown that pre-empted it would be guessing at
 * state this form does not have.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a FIELD_PICKERS row
 */
function _initEnumSelect(form, cfg) {
    const select = _swapForSelect(form, cfg.param);
    if (!select) return;

    _fillSelect(
        select,
        (ENUM_VALUES[cfg.values] || []).map(v => ({
            value: v.value,
            label: v.labelKey ? t(v.labelKey) : v.value
        })),
        t('commandForm.field.selectPlaceholder'));

    if (cfg.defaultValue) select.value = cfg.defaultValue;
}

/**
 * Snippet picker, read from the listSnippets COMMAND.
 *
 * There is no /admin/api arm for snippets; preview.js reads the command
 * directly and so does this, which keeps the caller's own permission check as
 * the gate rather than adding a registration layer for the same data.
 *
 * ⚠ cfg.userOnly drops the core snippets. deleteSnippet's description is
 * "Core snippets cannot be deleted", so listing one would offer a choice the
 * command refuses - the same defect as a dropdown on an echo-check field.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a FIELD_PICKERS row
 * @returns {Promise<void>}
 */
async function _initSnippetSelect(form, cfg) {
    const select = _swapForSelect(form, cfg.param);
    if (!select) return;

    QSDom.setSelectPlaceholder(select, t('commandForm.select.snippet'));

    let snippets = [];
    try {
        const res = await QuickSiteAdmin.apiRequest('listSnippets', 'GET');
        snippets = (res && res.ok && res.data && res.data.data && res.data.data.snippets) || [];
    } catch (error) {
        return; // the placeholder stands; the field is still submittable
    }

    const rows = snippets
        .filter(s => !(cfg.userOnly && s.isCore))
        .map(s => ({
            value: s.id,
            label: s.name ? s.name + ' (' + s.id + ')' : s.id
        }));

    _fillSelect(select, rows, t('commandForm.select.snippet'));
}

/**
 * API picker, and the endpoint picker that cascades from it.
 *
 * listApiEndpoints returns every api with its endpoints NESTED inside it, so
 * one fetch feeds both levels and changing the api filters what is already in
 * hand. A blank api means "no filter" - which is what the optional apiId
 * parameters document - so the endpoint list then spans every api and its
 * labels carry the api id to tell duplicates apart.
 *
 * cfg.endpointRefParam handles cleanResolverCache's compound
 * "@apiId/endpointId" form instead of a plain endpoint id.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a FIELD_PICKERS row
 * @returns {Promise<void>}
 */
async function _initApiEndpointPicker(form, cfg) {
    const apiSelect = cfg.apiParam ? _swapForSelect(form, cfg.apiParam) : null;
    const endpointSelect = cfg.endpointParam ? _swapForSelect(form, cfg.endpointParam) : null;
    const refSelect = cfg.endpointRefParam ? _swapForSelect(form, cfg.endpointRefParam) : null;
    if (!apiSelect && !endpointSelect && !refSelect) return;

    const apiPlaceholder = cfg.allowEmpty
        ? t('commandForm.select.allApis')
        : t('commandForm.select.api');
    if (apiSelect) QSDom.setSelectPlaceholder(apiSelect, apiPlaceholder);
    if (endpointSelect) QSDom.setSelectPlaceholder(endpointSelect, t('commandForm.select.endpoint'));
    if (refSelect) QSDom.setSelectPlaceholder(refSelect, t('commandForm.select.endpointRef'));

    let apis = [];
    try {
        const res = await QuickSiteAdmin.apiRequest('listApiEndpoints', 'GET');
        apis = (res && res.ok && res.data && res.data.data && res.data.data.apis) || [];
    } catch (error) {
        return;
    }

    if (apiSelect) {
        _fillSelect(apiSelect,
            apis.map(a => ({ value: a.apiId, label: a.name ? a.name + ' (' + a.apiId + ')' : a.apiId })),
            apiPlaceholder);
    }

    /** Endpoints of one api, or of every api when none is chosen. */
    function endpointRows(apiId, compound) {
        const chosen = apiId ? apis.filter(a => a.apiId === apiId) : apis;
        const rows = [];
        chosen.forEach(a => {
            (a.endpoints || []).forEach(e => {
                const label = (e.name || e.id) + (apiId ? '' : ' — ' + a.apiId);
                rows.push({ value: compound ? '@' + a.apiId + '/' + e.id : e.id, label: label });
            });
        });
        return rows;
    }

    if (endpointSelect) {
        _fillSelect(endpointSelect, endpointRows(apiSelect ? apiSelect.value : '', false),
            t('commandForm.select.endpoint'));
    }
    if (refSelect) {
        _fillSelect(refSelect, endpointRows('', true), t('commandForm.select.endpointRef'));
    }

    // The api drives the endpoint list, so it belongs ABOVE it. The form
    // renders required parameters first and `apiId` is an optional filter,
    // which put the field you pick FIRST in second place.
    if (apiSelect && endpointSelect) {
        const apiGroup = apiSelect.closest('.admin-form-group');
        const endpointGroup = endpointSelect.closest('.admin-form-group');
        if (apiGroup && endpointGroup && apiGroup.parentNode === endpointGroup.parentNode) {
            endpointGroup.parentNode.insertBefore(apiGroup, endpointGroup);
        }
    }

    if (apiSelect && (endpointSelect || refSelect)) {
        apiSelect.addEventListener('change', () => {
            const id = apiSelect.value;
            if (endpointSelect) {
                _fillSelect(endpointSelect, endpointRows(id, false), t('commandForm.select.endpoint'));
            }
            if (refSelect) {
                _fillSelect(refSelect, endpointRows(id, true), t('commandForm.select.endpointRef'));
            }
        });
    }
}

/**
 * Convert a type/structType field and its page-or-component field into
 * cascading selects.
 *
 * Which list the second field takes depends on the first: a page name when the
 * type is "page", a component name when it is "component", and nothing at all
 * for menu/footer, where the parameter is not required.
 *
 * @param {HTMLFormElement} form
 * @param {Object} cfg  a PAGE_PICKERS row
 * @returns {Promise<void>}
 */
async function _initStructurePicker(form, cfg) {
    const typeInput = form.querySelector('[name="' + cfg.typeParam + '"]');
    const nameInput = form.querySelector('[name="' + cfg.param + '"]');
    if (!typeInput || !nameInput) return;

    let typeSelect = typeInput;
    if (typeInput.tagName !== 'SELECT') {
        typeSelect = QSDom.el('select', {
            name: cfg.typeParam,
            class: 'admin-select'
        });
        if (typeInput.required) typeSelect.required = true;
        if (typeInput.dataset.urlParam !== undefined) typeSelect.dataset.urlParam = '';
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(
            typeSelect, 'structure-types', [], t('commandForm.select.structureType'));
    }

    let nameSelect = nameInput;
    if (nameInput.tagName !== 'SELECT') {
        nameSelect = QSDom.el('select', {
            name: cfg.param,
            class: 'admin-select'
        });
        if (nameInput.dataset.urlParam !== undefined) nameSelect.dataset.urlParam = '';
        nameInput.replaceWith(nameSelect);
        QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.typeFirst'));
        nameSelect.disabled = true;
    }

    typeSelect.addEventListener('change', async () => {
        const type = typeSelect.value;
        if (type === 'page') {
            nameSelect.disabled = false;
            await QuickSiteAdmin.populateSelect(
                nameSelect, 'pages', [], t('commandForm.select.page'));
        } else if (type === 'component') {
            nameSelect.disabled = false;
            await QuickSiteAdmin.populateSelect(
                nameSelect, 'components', [], t('commandForm.select.component'));
        } else {
            QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
            nameSelect.disabled = true;
        }
    });
}

/**
 * Wire whichever picker this command's table row asks for.
 *
 * Runs after the per-command switch, so a command may appear in both without
 * either clobbering the other - each helper no-ops on a field already
 * converted.
 *
 * @returns {Promise<void>}
 */
async function applyPagePickers() {
    const specs = FIELD_PICKERS[COMMAND_NAME];
    if (!specs) return;

    const form = document.getElementById('command-form');
    if (!form) return;

    for (const cfg of (Array.isArray(specs) ? specs : [specs])) {
        if (cfg.kind === 'select') {
            await _initArmSelect(form, cfg);
        } else if (cfg.kind === 'suggest') {
            await _initRouteSuggest(form, cfg);
        } else if (cfg.kind === 'structure') {
            await _initStructurePicker(form, cfg);
        } else if (cfg.kind === 'snippet') {
            await _initSnippetSelect(form, cfg);
        } else if (cfg.kind === 'api') {
            await _initApiEndpointPicker(form, cfg);
        } else if (cfg.kind === 'enum') {
            _initEnumSelect(form, cfg);
        }
    }
}

async function initEnhancedFeatures() {
    const form = document.getElementById('command-form');
    
    // Initialize JSON editors
    form.querySelectorAll('textarea[data-json-editor]').forEach(textarea => {
        QuickSiteAdmin.initJsonEditor(textarea);
    });
    
    // Check for special commands that need enhanced forms
    switch (COMMAND_NAME) {
        case 'editStructure':
            await initEditStructureForm();
            break;
        case 'deleteAsset':
            await initDeleteAssetForm();
            break;
        case 'editAsset':
            await initAssetSelectForm();
            initEditAssetExtensionHint();
            break;
        case 'listAssets':
            await initListAssetsForm();
            break;
        case 'getStructure':
            await initGetStructureForm();
            break;
        case 'deleteRoute':
        case 'setRouteLayout':
            await initRouteSelectForm();
            break;
        case 'addRoute':
            await initAddRouteParentSelect();
            break;
        case 'getTranslation':
        case 'getTranslationKeys':
        case 'validateTranslations':
        case 'getUnusedTranslationKeys':
        case 'analyzeTranslations':
        case 'setDefaultLang':
            await initLanguageSelectForm();
            break;
        case 'createAlias':
            await initCreateAliasForm();
            break;
        case 'deleteAlias':
            await initDeleteAliasForm();
            break;
        case 'setTranslationKeys':
            await initSetTranslationKeysForm();
            break;
        case 'deleteTranslationKeys':
            await initDeleteTranslationKeysForm();
            break;
        case 'uploadAsset':
            await initUploadAssetForm();
            break;
        case 'editFavicon':
            await initEditFaviconForm();
            break;
        case 'editTitle':
            await initEditTitleForm();
            break;
        case 'editStyles':
            await initEditStylesForm();
            break;
        case 'setRootVariables':
            await initSetRootVariablesForm();
            break;
        case 'getStyleRule':
        case 'setStyleRule':
        case 'deleteStyleRule':
            await initStyleRuleForm();
            break;
        case 'getKeyframes':
        case 'deleteKeyframes':
            await initKeyframeSelectForm();
            break;
        case 'setKeyframes':
            await initSetKeyframesForm();
            break;
        // deployBuild ONLY. getBuild / deleteBuild / downloadBuild stopped
        // taking a build name when retention went to one build per project:
        // there is nothing to pick between, so they render with no name field
        // and this picker would have had nothing to put in it.
        case 'deployBuild':
            await initBuildSelectForm();
            break;
        case 'clearCommandHistory':
            await initClearHistoryForm();
            break;
        case 'findComponentUsages':
            await initFindComponentUsagesForm();
            break;
        case 'renameComponent':
            await initRenameComponentForm();
            break;
        case 'duplicateComponent':
            await initDuplicateComponentForm();
            break;
        case 'addComponentToNode':
            await initAddComponentToNodeForm();
            break;
        case 'editComponentToNode':
            await initEditComponentToNodeForm();
            break;
    }

    // The page/route group: twenty-one forms whose page field is wired from one
    // table rather than a case each. After the switch, so a command listed in
    // both gets both.
    await applyPagePickers();
}

/**
 * Initialize editStructure form with cascading selects
 * Also handles URL parameters for pre-filling from Structure page
 */
async function initEditStructureForm() {
    const form = document.getElementById('command-form');
    
    // Read URL parameters (from Structure page navigation)
    const urlParams = new URLSearchParams(window.location.search);
    const prefillType = urlParams.get('type');
    const prefillName = urlParams.get('name');
    const prefillNodeId = urlParams.get('nodeId');
    const prefillAction = urlParams.get('action');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], t('commandForm.select.structureType'));
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.typeFirst'));
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert action input to select
    const actionInput = form.querySelector('[name="action"]');
    if (actionInput && actionInput.tagName !== 'SELECT') {
        const actionSelect = document.createElement('select');
        actionSelect.name = 'action';
        actionSelect.className = 'admin-select';
        actionInput.replaceWith(actionSelect);
        await QuickSiteAdmin.populateSelect(actionSelect, 'edit-actions', [], t('commandForm.select.action'));
    }
    
    // Convert nodeId input to select (if exists)
    const nodeIdInput = form.querySelector('[name="nodeId"]');
    if (nodeIdInput && nodeIdInput.tagName !== 'SELECT') {
        const nodeIdSelect = document.createElement('select');
        nodeIdSelect.name = 'nodeId';
        nodeIdSelect.className = 'admin-select';
        QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.select.structureFirst'));
        nodeIdSelect.disabled = true;
        nodeIdInput.replaceWith(nodeIdSelect);
    }
    
    // Move structure field to the end (before the action buttons)
    const structureGroup = form.querySelector('[name="structure"]')?.closest('.admin-form-group');
    const formActions = form.querySelector('.admin-form-actions');
    if (structureGroup && formActions) {
        formActions.parentNode.insertBefore(structureGroup, formActions);
    }

    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const nodeIdSelect = form.querySelector('[name="nodeId"]');
    const actionSelect = form.querySelector('[name="action"]');
    const structureTextarea = form.querySelector('[name="structure"]');
    
    // Function to load node options and return them for selection
    async function loadNodeOptions(selectValue = null) {
        if (!nodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.select.typeFirst'));
            nodeIdSelect.disabled = true;
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.select.nameFirst'));
            nodeIdSelect.disabled = true;
            return;
        }
        
        nodeIdSelect.disabled = false;
        
        // Build params based on type
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.select.nodeOptional'));
            QuickSiteAdmin.appendOptionsToSelect(nodeIdSelect, nodes);
            
            // Select the prefilled value if provided
            if (selectValue) {
                nodeIdSelect.value = selectValue;
                // Trigger the node content loading
                await loadNodeContent();
            }
        } catch (error) {
            QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.errors.loadNodes'));
        }
    }
    
    // Function to load node content when action is 'update' and nodeId is selected
    async function loadNodeContent() {
        if (!structureTextarea || !nodeIdSelect) return;
        
        const action = actionSelect?.value;
        const nodeId = nodeIdSelect?.value;
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        // Only load for update action with a selected nodeId
        if (action !== 'update' || !nodeId) return;
        
        // Build API params
        const apiParams = [type];
        if (type === 'page' || type === 'component') {
            apiParams.push(name);
        }
        apiParams.push(nodeId); // This fetches the specific node
        
        try {
            structureTextarea.placeholder = t('commandForm.placeholder.loadingNode');
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, apiParams);
            
            if (result.ok && result.data.data?.node) {
                // Format the node JSON nicely
                const nodeJson = JSON.stringify(result.data.data.node, null, 2);
                structureTextarea.value = nodeJson;
                structureTextarea.placeholder = t('commandForm.placeholder.nodeLoaded');
                QuickSiteAdmin.showToast(t('commandForm.toast.nodeLoaded'), 'info');
            }
        } catch (error) {
            console.error('Failed to load node content:', error);
            structureTextarea.placeholder = t('commandForm.placeholder.newStructure');
        }
    }
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], t('commandForm.select.page'));
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], t('commandForm.select.component'));
            } else {
                QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
                nameSelect.disabled = true;
                // For menu/footer, load nodes directly
                await loadNodeOptions();
            }
            
            // Reset nodeId for page/component (will be populated after name selection)
            if (nodeIdSelect && (type === 'page' || type === 'component')) {
                QSDom.setSelectPlaceholder(nodeIdSelect, t('commandForm.select.nameFirst'));
                nodeIdSelect.disabled = true;
            }
            
            // Clear structure textarea when type changes
            if (structureTextarea) {
                structureTextarea.value = '';
            }
        });
        
        // When name changes, populate nodeId options
        nameSelect.addEventListener('change', async () => {
            await loadNodeOptions();
            // Clear structure textarea when name changes
            if (structureTextarea) {
                structureTextarea.value = '';
            }
        });
    }
    
    // When nodeId changes and action is 'update', load the node content
    if (nodeIdSelect) {
        nodeIdSelect.addEventListener('change', loadNodeContent);
    }
    
    // When action changes to 'update' with a nodeId, load the node content
    // Also toggle structure required based on action
    if (actionSelect) {
        actionSelect.addEventListener('change', () => {
            loadNodeContent();
            // Structure is not required for 'delete' action
            if (structureTextarea) {
                if (actionSelect.value === 'delete') {
                    structureTextarea.removeAttribute('required');
                    structureTextarea.placeholder = t('commandForm.placeholder.notRequiredDelete');
                } else {
                    structureTextarea.setAttribute('required', '');
                    structureTextarea.placeholder = t('commandForm.placeholder.jsonStructure');
                }
            }
        });
    }
    
    // Pre-fill form from URL parameters
    if (prefillType) {
        typeSelect.value = prefillType;
        
        // Trigger cascading for name select
        if (prefillType === 'page' || prefillType === 'component') {
            const endpoint = prefillType === 'page' ? 'pages' : 'components';
            await QuickSiteAdmin.populateSelect(nameSelect, endpoint, [], `Select ${prefillType}...`);
            nameSelect.disabled = false;
            
            if (prefillName) {
                nameSelect.value = prefillName;
                // Load node options and select the prefilled nodeId
                await loadNodeOptions(prefillNodeId);
            }
        } else {
            // menu/footer
            QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
            nameSelect.disabled = true;
            await loadNodeOptions(prefillNodeId);
        }
    }
    
    // Pre-fill action
    if (prefillAction && actionSelect) {
        actionSelect.value = prefillAction;
        // If action is update and nodeId is set, load the node content
        if (prefillAction === 'update' && prefillNodeId) {
            await loadNodeContent();
        }
    }
}

/**
 * Initialize getStructure form with cascading selects
 */
async function initGetStructureForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        if (typeInput.dataset.urlParam !== undefined) {
            typeSelect.dataset.urlParam = '';
        }
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], t('commandForm.select.structureType'));
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.typeFirst'));
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert option input to select (for showIds, summary, nodeId)
    const optionInput = form.querySelector('[name="option"]');
    if (optionInput && optionInput.tagName !== 'SELECT') {
        const optionSelect = document.createElement('select');
        optionSelect.name = 'option';
        optionSelect.className = 'admin-select';
        if (optionInput.dataset.urlParam !== undefined) {
            optionSelect.dataset.urlParam = '';
        }
        QSDom.setSelectPlaceholder(optionSelect, t('commandForm.getStructure.optionNone'));
        // showIds / summary are the command's own argument values, so they stay
        // literal; only the parenthetical explanation is translated.
        QuickSiteAdmin.appendOptionsToSelect(optionSelect, [
            { value: 'showIds', label: t('commandForm.getStructure.optionShowIds') },
            { value: 'summary', label: t('commandForm.getStructure.optionSummary') }
        ]);
        optionInput.replaceWith(optionSelect);
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], t('commandForm.select.page'));
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], t('commandForm.select.component'));
            } else {
                // menu and footer don't need name
                QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
                nameSelect.disabled = true;
            }
        });
    }
}

/**
 * Initialize asset-related commands with category filter and file selection.
 * Category is no longer a form param (auto-detected from extension).
 * A category filter dropdown is added for UX convenience.
 */
async function initAssetSelectForm() {
    const form = document.getElementById('command-form');
    
    // Convert filename input to a select
    const filenameInput = form.querySelector('[name="filename"]');
    if (filenameInput && filenameInput.tagName !== 'SELECT') {
        // Add a category filter (not a form param, just for UX)
        const categoryFilter = QSDom.el('select', {
            class: 'admin-select',
            id: 'asset-category-filter'
        });
        QSDom.setSelectPlaceholder(categoryFilter, t('commandForm.select.allCategories'));
        const filterGroup = QSDom.el('div', { class: 'admin-form-group' }, [
            _renderLabel(t('commandForm.assets.filterByCategory')),
            categoryFilter
        ]);
        filenameInput.closest('.admin-form-group')?.before(filterGroup);
        
        // Populate category filter options
        try {
            const categories = await QuickSiteAdmin.fetchHelperData('asset-categories', []);
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.value;
                option.textContent = cat.label;
                categoryFilter.appendChild(option);
            });
        } catch (e) { /* non-critical */ }
        
        const filenameSelect = document.createElement('select');
        filenameSelect.name = 'filename';
        filenameSelect.className = 'admin-select';
        filenameSelect.required = filenameInput.required;
        QSDom.setSelectPlaceholder(filenameSelect, t('commandForm.status.loadingFiles'));
        filenameInput.replaceWith(filenameSelect);
        
        // Load all assets initially (no category filter)
        await populateAssetFilenames(filenameSelect, '');
        
        // Re-populate when category filter changes
        categoryFilter.addEventListener('change', async () => {
            await populateAssetFilenames(filenameSelect, categoryFilter.value);
        });
        
        // Refresh select after successful asset operations
        form.addEventListener('command-success', (e) => {
            const { command, data, result } = e.detail || {};
            if (command === 'deleteAsset') {
                // Single delete: remove filename from select
                if (data?.filename) {
                    const option = filenameSelect.querySelector(`option[value="${CSS.escape(data.filename)}"]`);
                    if (option) option.remove();
                }
                // Batch delete: remove all deleted filenames
                if (result?.deleted) {
                    result.deleted.forEach(d => {
                        const option = filenameSelect.querySelector(`option[value="${CSS.escape(d.filename)}"]`);
                        if (option) option.remove();
                    });
                }
                filenameSelect.value = '';
            }
            if (command === 'editAsset' && result?.oldFilename) {
                // Rename: update the option value and text
                const option = filenameSelect.querySelector(`option[value="${CSS.escape(result.oldFilename)}"]`);
                if (option) {
                    option.value = result.filename;
                    option.textContent = result.filename;
                    filenameSelect.value = result.filename;
                }
            }
        });
    }
}

/**
 * Populate a select with asset filenames, optionally filtered by category.
 */
async function populateAssetFilenames(selectEl, category) {
    try {
        const args = category ? [category] : [];
        await QuickSiteAdmin.populateSelect(selectEl, 'assets', args, t('commandForm.select.file'));
    } catch (e) {
        QSDom.setSelectPlaceholder(selectEl, t('commandForm.errors.loadFiles'));
    }
}

/**
 * For editAsset: show the file extension as a read-only suffix next to newFilename input.
 * Updates when the filename select changes.
 */
function initEditAssetExtensionHint() {
    const form = document.getElementById('command-form');
    const filenameSelect = form.querySelector('[name="filename"]');
    const newFilenameInput = form.querySelector('[name="newFilename"]');
    if (!filenameSelect || !newFilenameInput) return;

    // Create suffix element
    const wrapper = document.createElement('div');
    wrapper.className = 'admin-input-group';
    wrapper.style.display = 'flex';
    wrapper.style.alignItems = 'center';
    wrapper.style.gap = '0';
    newFilenameInput.parentNode.insertBefore(wrapper, newFilenameInput);
    wrapper.appendChild(newFilenameInput);
    newFilenameInput.style.borderTopRightRadius = '0';
    newFilenameInput.style.borderBottomRightRadius = '0';
    newFilenameInput.style.borderRight = 'none';

    const suffix = document.createElement('span');
    suffix.className = 'admin-input-suffix';
    suffix.style.cssText = 'padding:0.5rem 0.75rem;background:var(--admin-bg-tertiary,#374151);border:1px solid var(--admin-border,#4b5563);border-top-right-radius:0.375rem;border-bottom-right-radius:0.375rem;color:var(--admin-text-secondary,#9ca3af);font-family:monospace;white-space:nowrap;';
    suffix.textContent = '';
    wrapper.appendChild(suffix);

    // Hint below the input
    const hint = document.createElement('small');
    hint.style.cssText = 'display:block;margin-top:0.25rem;color:var(--admin-text-tertiary,#6b7280);font-size:0.75rem;';
    hint.textContent = t('commandForm.hint.renameNoExtension');
    wrapper.parentNode.insertBefore(hint, wrapper.nextSibling);

    // Update suffix when filename changes
    function updateSuffix() {
        const selected = filenameSelect.value;
        if (selected) {
            const ext = selected.includes('.') ? '.' + selected.split('.').pop() : '';
            suffix.textContent = ext;
        } else {
            suffix.textContent = '';
        }
    }

    filenameSelect.addEventListener('change', updateSuffix);
    updateSuffix();

    // Update placeholder based on selection
    newFilenameInput.setAttribute('placeholder', 'new-name (without extension)');
}

/**
 * Initialize deleteAsset form with checkbox-based multi-select.
 */
async function initDeleteAssetForm() {
    const form = document.getElementById('command-form');

    // Remove the auto-generated filename input
    const filenameInput = form.querySelector('[name="filename"]');
    const filenameGroup = filenameInput?.closest('.admin-form-group');
    if (!filenameGroup) return;

    /** One line of the file list — a status message, not a file. */
    function _renderListNotice(text, isError) {
        return QSDom.el('div', {
            style: 'padding:0.75rem;color:var(--admin-' + (isError ? 'error' : 'text-secondary') + ')',
            text: text
        });
    }

    /** One selectable file row. */
    function _renderFileRow(file) {
        return QSDom.el('label', {
            class: 'admin-checkbox-list__item',
            style: 'display:flex;align-items:center;gap:0.5rem;padding:0.375rem 0.75rem;cursor:pointer;',
            title: file.value
        }, [
            QSDom.el('input', { type: 'checkbox', class: 'delete-file-cb', value: file.value }),
            QSDom.el('span', { style: 'flex:1;font-size:0.875rem;', text: file.label })
        ]);
    }

    // Build the container
    const selectAllCheckbox = QSDom.el('input', { type: 'checkbox', id: 'delete-select-all' });
    const fileListEl = QSDom.el('div', {
        id: 'delete-file-list',
        class: 'admin-checkbox-list',
        style: 'max-height:320px;overflow-y:auto;border:1px solid var(--admin-border,#4b5563);'
             + 'border-radius:0.375rem;padding:0.25rem 0;'
    }, [_renderListNotice(t('commandForm.status.loadingFiles'))]);
    const countHint = QSDom.el('small', {
        id: 'delete-count-hint',
        style: 'display:block;margin-top:0.25rem;color:var(--admin-text-tertiary,#6b7280);font-size:0.75rem;'
    });

    const container = QSDom.el('div', { class: 'admin-form-group' }, [
        _renderLabel(t('commandForm.deleteAsset.filesToDelete')),
        QSDom.el('div', { style: 'display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem;' }, [
            QSDom.el('label', {
                style: 'display:flex;align-items:center;gap:0.3rem;font-size:0.8rem;'
                     + 'color:var(--admin-text-secondary,#9ca3af);white-space:nowrap;cursor:pointer;'
            }, [selectAllCheckbox, ' ' + t('commandForm.deleteAsset.selectAll')])
        ]),
        fileListEl,
        countHint
    ]);
    filenameGroup.replaceWith(container);

    // Load and render file checkboxes
    async function loadFiles() {
        QSDom.clear(fileListEl);
        fileListEl.appendChild(_renderListNotice(t('common.loading')));
        try {
            const files = await QuickSiteAdmin.fetchHelperData('assets', []);
            QSDom.clear(fileListEl);
            if (!files || files.length === 0) {
                fileListEl.appendChild(_renderListNotice(t('commandForm.empty.noFiles')));
                updateCount();
                return;
            }

            files.forEach(f => fileListEl.appendChild(_renderFileRow(f)));
            updateCount();
        } catch (e) {
            QSDom.clear(fileListEl);
            fileListEl.appendChild(_renderListNotice(t('commandForm.errors.loadFiles'), true));
        }
    }

    function getChecked() {
        return Array.from(fileListEl.querySelectorAll('.delete-file-cb:checked')).map(cb => cb.value);
    }

    // deleteAsset declares TWO parameters, `filename` and `filenames`. The
    // checkbox list above replaced the `filename` field, but `filenames` is a
    // separate field of its own and stayed on screen, empty, whatever you
    // ticked — which reads as a broken form. Mirror the ticked files into it so
    // the visible field shows what the request will carry.
    const filenamesField = form.querySelector('[name="filenames"]');

    function updateCount() {
        const checked = getChecked();
        countHint.textContent = checked.length === 0 ? '' : t(
            checked.length === 1
                ? 'commandForm.deleteAsset.selectedOne'
                : 'commandForm.deleteAsset.selectedMany',
            { count: checked.length }
        );
        selectAllCheckbox.checked = fileListEl.querySelectorAll('.delete-file-cb').length > 0 &&
            fileListEl.querySelectorAll('.delete-file-cb:not(:checked)').length === 0;
        if (filenamesField) {
            filenamesField.value = JSON.stringify(checked, null, 2);
        }
    }

    selectAllCheckbox.addEventListener('change', () => {
        fileListEl.querySelectorAll('.delete-file-cb').forEach(cb => {
            cb.checked = selectAllCheckbox.checked;
        });
        updateCount();
    });

    fileListEl.addEventListener('change', updateCount);

    await loadFiles();

    // Override form submission: send batch or single delete
    form.addEventListener('submit', async function(e) {
        const checked = getChecked();
        if (checked.length === 0) return; // let normal validation handle it

        e.preventDefault();
        e.stopPropagation();

        const submitBtn = document.getElementById('submit-btn');
        submitBtn.disabled = true;
        // Hold the button's own child nodes so they go back verbatim, rather
        // than round-tripping the label through an innerHTML string.
        const originalBtnNodes = Array.from(submitBtn.childNodes);
        QSDom.clear(submitBtn);
        submitBtn.appendChild(QSDom.el('span', { class: 'admin-spinner' }));
        submitBtn.appendChild(document.createTextNode(' ' + t(
            checked.length === 1
                ? 'commandForm.deleteAsset.deletingOne'
                : 'commandForm.deleteAsset.deletingMany',
            { count: checked.length }
        )));

        const responseDiv = document.getElementById('command-response');

        try {
            let result;
            if (checked.length === 1) {
                result = await QuickSiteAdmin.apiRequest('deleteAsset', 'DELETE', { filename: checked[0] });
            } else {
                result = await QuickSiteAdmin.apiRequest('deleteAsset', 'DELETE', { filenames: checked });
            }

            QuickSiteAdmin.displayResponse(responseDiv, result);

            if (result.ok) {
                // Remove deleted files from the checkbox list using the checked array
                // (204 responses have no body, so we rely on what we sent)
                checked.forEach(fname => {
                    const cb = fileListEl.querySelector(`.delete-file-cb[value="${CSS.escape(fname)}"]`);
                    if (cb) cb.closest('.admin-checkbox-list__item')?.remove();
                });
                updateCount();

                const msg = checked.length === 1 ? 'File deleted!' : `${checked.length} files deleted!`;
                QuickSiteAdmin.showToast(msg, 'success');
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Delete failed', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.displayResponse(responseDiv, { ok: false, status: 0, data: { error: error.message } });
            QuickSiteAdmin.showToast(t('commandForm.toast.deleteFailed') + ' ' + error.message, 'error');
        }

        QSDom.clear(submitBtn);
        originalBtnNodes.forEach(node => submitBtn.appendChild(node));
        submitBtn.disabled = false;
    }, true);
}

/**
 * Initialize listAssets form with optional category select
 */
async function initListAssetsForm() {
    const form = document.getElementById('command-form');
    
    // Convert category to select (it's a URL segment parameter)
    const categoryInput = form.querySelector('[name="category"]');
    if (categoryInput && categoryInput.tagName !== 'SELECT') {
        const categorySelect = document.createElement('select');
        categorySelect.name = 'category';
        categorySelect.className = 'admin-select';
        // Not required - it's optional for listAssets
        categorySelect.required = false;
        
        // Preserve URL param attribute if present
        if (categoryInput.dataset.urlParam !== undefined) {
            categorySelect.dataset.urlParam = '';
        }
        
        categoryInput.replaceWith(categorySelect);
        
        // Add "All categories" option first, then populate with categories
        QSDom.setSelectPlaceholder(categorySelect, t('commandForm.select.allCategories'));
        try {
            const categories = await QuickSiteAdmin.fetchHelperData('asset-categories', []);
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.value;
                option.textContent = cat.label;
                categorySelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }
}

/**
 * Initialize uploadAsset form — single file upload (atomic command).
 * For multi-file upload, use the dedicated Asset Management page (/admin/assets).
 * Category is auto-detected from file extension (no category select needed).
 */
async function initUploadAssetForm() {
    const form = document.getElementById('command-form');
    
    // Fetch allowed extensions for hint display
    let allExtensions = [];
    try {
        const extensionsMap = await QuickSiteAdmin.fetchHelperData('asset-extensions');
        allExtensions = Object.values(extensionsMap).flat();
    } catch (e) { /* extensions hint is non-critical */ }
    
    // Set file accept attribute to all allowed extensions
    const fileInput = form.querySelector('input[type="file"]');
    if (fileInput && allExtensions.length) {
        fileInput.setAttribute('accept', allExtensions.map(e => '.' + e).join(','));
    }
    
    // Show allowed extensions hint below file input
    if (fileInput && allExtensions.length) {
        const hint = document.createElement('small');
        hint.className = 'admin-form-hint';
        hint.textContent = `Allowed: ${allExtensions.join(', ')} · Category auto-detected from extension`;
        const group = fileInput.closest('.admin-form-group');
        if (group) group.appendChild(hint);
    }
    
    // Add "or" divider + URL field after file input group
    const fileGroup = fileInput?.closest('.admin-form-group');
    const urlInput = form.querySelector('[name="url"]');
    if (fileGroup && urlInput) {
        const urlGroup = urlInput.closest('.admin-form-group');
        if (urlGroup) {
            const divider = QSDom.el('div', { class: 'admin-url-upload__divider' }, [
                QSDom.el('span', { text: t('commandForm.uploadAsset.or') })
            ]);
            fileGroup.after(divider);
            divider.after(urlGroup);
        }
        
        // Mutual visual disable: selecting a file dims the URL, typing a URL dims the file
        urlInput.addEventListener('input', () => {
            if (urlInput.value.trim()) {
                fileGroup.classList.add('admin-file-input--dimmed');
            } else {
                fileGroup.classList.remove('admin-file-input--dimmed');
            }
        });
        
        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    const urlGroup = urlInput.closest('.admin-form-group');
                    if (urlGroup) urlGroup.classList.add('admin-url-upload--dimmed');
                    urlInput.value = '';
                } else {
                    const urlGroup = urlInput.closest('.admin-form-group');
                    if (urlGroup) urlGroup.classList.remove('admin-url-upload--dimmed');
                }
            });
        }
    }
    
    // Add link to Asset Management page for multi-file uploads
    const pageHeader = document.querySelector('.admin-page-header');
    if (pageHeader) {
        const tip = _renderHint([
            _renderRich(t('commandForm.uploadAsset.multiFileTip'), {
                // The asset page is routed 'media', NOT 'assets' — a directory
                // named 'assets' would shadow it, and public/admin/assets/ is a
                // real one. See AdminRouter::$validPages.
                link: QSDom.el('a', {
                    href: (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/media',
                    text: t('commandForm.uploadAsset.assetManagement')
                })
            })
        ], 'admin-form-hint');
        tip.style.marginTop = 'var(--space-sm)';
        pageHeader.appendChild(tip);
    }
}

/**
 * Initialize editFavicon form with image selector from assets/images
 */
async function initEditFaviconForm() {
    const form = document.getElementById('command-form');
    const imageNameInput = form.querySelector('[name="imageName"]');
    
    if (imageNameInput && imageNameInput.tagName !== 'SELECT') {
        const imageSelect = document.createElement('select');
        imageSelect.name = 'imageName';
        imageSelect.className = 'admin-select';
        imageSelect.required = imageNameInput.required;
        
        imageNameInput.replaceWith(imageSelect);
        
        // Load images from assets/images
        QSDom.setSelectPlaceholder(imageSelect, t('commandForm.status.loadingImages'));
        
        try {
            const images = await QuickSiteAdmin.fetchHelperData('assets', ['images']);
            QSDom.setSelectPlaceholder(imageSelect, t('commandForm.select.faviconImage'));
            
            // Filter for PNG images (favicon requires PNG)
            images.forEach(img => {
                const option = document.createElement('option');
                option.value = img.value;
                option.textContent = img.label;
                // Highlight PNG files
                if (img.value.toLowerCase().endsWith('.png')) {
                    option.textContent = '✓ ' + img.label;
                }
                imageSelect.appendChild(option);
            });
            
            if (images.length === 0) {
                QSDom.setSelectPlaceholder(imageSelect, t('commandForm.empty.noImages'));
            }
        } catch (error) {
            QSDom.setSelectPlaceholder(imageSelect, t('commandForm.errors.loadImages'));
        }
    }
    
    // Add hint about PNG requirement
    const hint = _renderHint([t('commandForm.editFavicon.pngOnlyHint')]);
    const formGroup = form.querySelector('[name="imageName"]')?.parentNode;
    if (formGroup) {
        formGroup.appendChild(hint);
    }
}

/**
 * Initialize editTitle form with route and language selectors
 */
async function initEditTitleForm() {
    const form = document.getElementById('command-form');
    const routeInput = form.querySelector('[name="route"]');
    const langInput = form.querySelector('[name="lang"]');
    const titleInput = form.querySelector('[name="title"]');
    
    // Convert route input to select
    if (routeInput && routeInput.tagName !== 'SELECT') {
        const routeSelect = document.createElement('select');
        routeSelect.name = 'route';
        routeSelect.className = 'admin-select';
        routeSelect.required = routeInput.required;
        routeInput.replaceWith(routeSelect);
        await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], t('commandForm.select.route'));
    }
    
    // Convert lang input to select
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'lang';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        langInput.replaceWith(langSelect);
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], t('commandForm.select.language'));
    }
    
    // Function to load current title
    const loadCurrentTitle = async () => {
        const routeSelect = form.querySelector('[name="route"]');
        const langSelect = form.querySelector('[name="lang"]');
        
        if (!routeSelect || !langSelect || !titleInput) return;
        
        const route = routeSelect.value;
        const lang = langSelect.value;
        
        if (!route || !lang) return;
        
        try {
            const result = await QuickSiteAdmin.fetchHelperData('page-title', [route, lang]);
            if (result && result.title !== undefined) {
                titleInput.value = result.title;
                titleInput.placeholder = result.title ? 'Current: ' + result.title : 'No title set for this route/language';
            }
        } catch (error) {
            console.error('Error loading current title:', error);
            titleInput.placeholder = t('commandForm.placeholder.newTitle');
        }
    };
    
    // Add change listeners to load title when route or lang changes
    const routeSelect = form.querySelector('[name="route"]');
    const langSelect = form.querySelector('[name="lang"]');
    
    if (routeSelect) {
        routeSelect.addEventListener('change', loadCurrentTitle);
    }
    if (langSelect) {
        langSelect.addEventListener('change', loadCurrentTitle);
    }
}

/**
 * Initialize the build-name select (deployBuild).
 *
 * At one build per project this is a 0-or-1 option list, and deployBuild is the
 * only command still named against a specific build.
 */
async function initBuildSelectForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        nameSelect.required = nameInput.required;
        // Preserve data-url-param attribute if present (for GET commands)
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        nameInput.replaceWith(nameSelect);
        await QuickSiteAdmin.populateSelect(nameSelect, 'builds', [], t('commandForm.select.build'));

        // Refresh after a delete performed elsewhere in the panel.
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteBuild') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'builds', [], t('commandForm.select.build'));
                nameSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize clearCommandHistory form with date picker
 */
async function initClearHistoryForm() {
    const form = document.getElementById('command-form');

    // `before` declares ui_type 'date' in help.php, so renderFormField has
    // ALREADY built the date-picker widget: a date input, an Apply button, and
    // the "Or enter manually" field that carries the submitted value.
    //
    // This function used to replace that manual field with a second date input.
    // Two consequences: the Apply button wrote to an element id that no longer
    // existed, so it silently did nothing; and a field labelled "enter manually"
    // was itself a date picker. Enhance the picker the form already has instead.
    const today = new Date().toISOString().split('T')[0];
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
    const defaultDate = thirtyDaysAgo.toISOString().split('T')[0];

    const beforeInput = form.querySelector('[name="before"]');
    // The picker's own date input is bound to the value field by id — the
    // association renderFormField sets up — so find it that way rather than
    // rebuilding the id here.
    const picker = beforeInput && beforeInput.id
        ? form.querySelector('[data-datetime-date="' + beforeInput.id + '"]')
        : null;
    if (picker) {
        picker.max = today;            // nothing later than today is clearable
        picker.value = defaultDate;
    }
    // Seed the submitted field too, so the form arrives ready to run — what the
    // old conversion gave — and so Apply visibly changes something.
    if (beforeInput && !beforeInput.value) {
        beforeInput.value = defaultDate;
    }

    // Add hint. `confirm` is the command's own parameter name, so it stays
    // literal inside the <code> — only the sentence around it is translated.
    const hint = _renderHint([
        _renderRich(t('commandForm.clearHistory.hint'), {
            before: QSDom.el('strong', { text: t('commandForm.clearHistory.hintBefore') }),
            confirm: QSDom.el('code', { text: 'confirm' })
        })
    ]);
    const formGroup = form.querySelector('[name="before"]')?.parentNode;
    if (formGroup && !formGroup.querySelector('.admin-hint')) {
        formGroup.appendChild(hint);
    }
}

/**
 * Initialize editStyles form - loads current CSS content into textarea
 */
async function initEditStylesForm() {
    const form = document.getElementById('command-form');
    const contentTextarea = form.querySelector('[name="content"]');
    
    if (contentTextarea) {
        // Add loading indicator
        contentTextarea.placeholder = t('commandForm.placeholder.loadingStyles');
        contentTextarea.disabled = true;
        
        // Add helper buttons above textarea
        const loadBtn = _renderButton('load-styles-btn',
            'admin-btn admin-btn--secondary admin-btn--small',
            t('commandForm.editStyles.reload'));
        const formatBtn = _renderButton('format-css-btn',
            'admin-btn admin-btn--outline admin-btn--small',
            t('commandForm.editStyles.formatCss'));
        const helperDiv = QSDom.el('div', { class: 'admin-style-helper' }, [
            QSDom.el('div', {
                style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm); flex-wrap: wrap;'
            }, [loadBtn, formatBtn]),
            _renderHint([t('commandForm.editStyles.replaceWarning')])
        ]);
        contentTextarea.parentNode.insertBefore(helperDiv, contentTextarea);

        // Function to load current styles
        async function loadCurrentStyles() {
            contentTextarea.disabled = true;
            contentTextarea.placeholder = t('common.loading');
            
            try {
                const data = await QuickSiteAdmin.fetchHelperData('current-styles', []);
                // Normalize line endings for textarea display
                contentTextarea.value = data.content ? data.content.replace(/\r\n/g, '\n') : '';
                contentTextarea.placeholder = t('commandForm.placeholder.cssContent');
            } catch (error) {
                contentTextarea.placeholder = t('commandForm.placeholder.stylesFailed');
                QuickSiteAdmin.showToast(t('commandForm.toast.stylesFailed'), 'error');
            }
            
            contentTextarea.disabled = false;
        }
        
        // Load styles on init
        await loadCurrentStyles();
        
        // Reload button
        loadBtn.addEventListener('click', async () => {
            await loadCurrentStyles();
            QuickSiteAdmin.showToast(t('commandForm.toast.stylesReloaded'), 'success');
        });
        
        // Basic CSS formatting (just normalizes whitespace)
        formatBtn.addEventListener('click', () => {
            let css = contentTextarea.value;
            // Basic formatting: ensure newlines after { and ;, before }
            css = css
                .replace(/\s*{\s*/g, ' {\n  ')
                .replace(/;\s*/g, ';\n  ')
                .replace(/\s*}\s*/g, '\n}\n')
                .replace(/\n\s+\n/g, '\n')
                .replace(/  }/g, '}')
                .trim();
            contentTextarea.value = css;
            QuickSiteAdmin.showToast(t('commandForm.toast.cssFormatted'), 'success');
        });
        
        // Make textarea taller for CSS editing
        contentTextarea.style.minHeight = '400px';
        contentTextarea.style.fontFamily = 'monospace';
    }
}

/**
 * Initialize setRootVariables form with variable selector
 */
async function initSetRootVariablesForm() {
    const form = document.getElementById('command-form');
    const variablesTextarea = form.querySelector('[name="variables"]');
    
    if (variablesTextarea) {
        // Add helper above textarea
        const varSelector = QSDom.el('select', {
            id: 'var-selector', class: 'admin-select', style: 'flex: 1;', size: '6'
        });
        QSDom.setSelectPlaceholder(varSelector, t('commandForm.setRootVariables.loadingVariables'),
            { disabled: true });
        const varValueInput = QSDom.el('input', {
            type: 'text', id: 'var-value-input', class: 'admin-input',
            placeholder: t('commandForm.setRootVariables.newValue'), style: 'width: 180px;'
        });
        const currentValueSpan = QSDom.el('span', {
            id: 'var-current-value', style: 'font-style: italic;', text: '-'
        });
        const addBtn = _renderButton('add-var-btn', 'admin-btn admin-btn--secondary',
            t('commandForm.setRootVariables.addUpdate'));
        const clearBtn = _renderButton('clear-vars-btn', 'admin-btn admin-btn--outline',
            t('common.clearAll'));

        const helperDiv = QSDom.el('div', { class: 'admin-variable-selector' }, [
            _renderLabel(t('commandForm.setRootVariables.title')),
            QSDom.el('div', {
                style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);'
            }, [
                varSelector,
                QSDom.el('div', {
                    style: 'display: flex; flex-direction: column; gap: var(--space-xs);'
                }, [
                    varValueInput,
                    QSDom.el('p', { class: 'admin-hint', style: 'margin: 0;' },
                        [t('common.current') + ' ', currentValueSpan]),
                    addBtn,
                    clearBtn
                ])
            ]),
            _renderHint([t('commandForm.setRootVariables.hint')])
        ]);
        variablesTextarea.parentNode.insertBefore(helperDiv, variablesTextarea);

        // Store variables data
        let variablesData = [];
        
        // Load variables
        async function loadVariables() {
            QSDom.setSelectPlaceholder(varSelector, t('common.loading'), { disabled: true });
            try {
                variablesData = await QuickSiteAdmin.fetchHelperData('root-variables', []);
                QSDom.clear(varSelector);
                
                if (variablesData.length === 0) {
                    QSDom.setSelectPlaceholder(varSelector, t('commandForm.empty.noVariables'), { disabled: true });
                    return;
                }
                
                variablesData.forEach(v => {
                    const option = document.createElement('option');
                    option.value = v.value;
                    option.textContent = v.label;
                    option.dataset.currentValue = v.currentValue;
                    varSelector.appendChild(option);
                });
            } catch (error) {
                QSDom.setSelectPlaceholder(varSelector, t('commandForm.errors.loadVariables'), { disabled: true });
            }
        }
        
        await loadVariables();
        
        // Show current value on select
        varSelector.addEventListener('change', () => {
            const selected = varSelector.selectedOptions[0];
            if (selected && selected.dataset.currentValue) {
                currentValueSpan.textContent = selected.dataset.currentValue;
                // Pre-fill input with current value for easy editing
                varValueInput.value = selected.dataset.currentValue;
            } else {
                currentValueSpan.textContent = '-';
            }
        });
        
        // Add variable button
        addBtn.addEventListener('click', () => {
            const varName = varSelector.value;
            const varValue = varValueInput.value.trim();
            
            if (!varName) {
                QuickSiteAdmin.showToast(t('commandForm.toast.selectVariable'), 'warning');
                return;
            }
            if (!varValue) {
                QuickSiteAdmin.showToast(t('commandForm.toast.enterValue'), 'warning');
                return;
            }
            
            // Parse current variables object
            let variables = {};
            try {
                variables = JSON.parse(variablesTextarea.value || '{}');
            } catch {
                variables = {};
            }
            
            // Add/update variable
            variables[varName] = varValue;
            
            // Update textarea
            variablesTextarea.value = JSON.stringify(variables, null, 2);
            
            // Clear input
            varValueInput.value = '';
            currentValueSpan.textContent = '-';
            
            QuickSiteAdmin.showToast(`Added: ${varName}`, 'success');
        });
        
        // Clear button
        clearBtn.addEventListener('click', () => {
            variablesTextarea.value = '{}';
        });
        
        // Refresh after successful command
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'setRootVariables') {
                await loadVariables();
                variablesTextarea.value = '{}';
                currentValueSpan.textContent = '-';
            }
        });
    }
}

/**
 * Initialize getStyleRule/setStyleRule/deleteStyleRule form with selector picker
 */
async function initStyleRuleForm() {
    const form = document.getElementById('command-form');
    const selectorInput = form.querySelector('[name="selector"]');
    const mediaQueryInput = form.querySelector('[name="mediaQuery"]');
    
    if (selectorInput) {
        // Create a helper section above the selector input
        const ruleSelector = QSDom.el('select', {
            id: 'rule-selector', class: 'admin-select', style: 'flex: 1;', size: '8'
        });
        QSDom.setSelectPlaceholder(ruleSelector, t('commandForm.styleRule.loadingSelectors'),
            { disabled: true });
        const helperDiv = QSDom.el('div', { class: 'admin-style-rule-helper' }, [
            _renderLabel(t('commandForm.styleRule.title')),
            QSDom.el('div', {
                style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);'
            }, [ruleSelector]),
            _renderHint([t('commandForm.styleRule.hint')])
        ]);
        selectorInput.parentNode.parentNode.insertBefore(helperDiv, selectorInput.parentNode);
        
        // Store selectors with their media queries
        let selectorsData = [];
        
        // Load selectors
        async function loadSelectors() {
            QSDom.setSelectPlaceholder(ruleSelector, t('common.loading'), { disabled: true });
            try {
                const data = await QuickSiteAdmin.fetchHelperData('style-rules', []);
                QSDom.clear(ruleSelector);
                selectorsData = [];
                
                // Build optgroups
                data.forEach(group => {
                    if (group.type === 'optgroup') {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = group.label;
                        
                        group.options.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            option.dataset.mediaQuery = opt.mediaQuery || '';
                            optgroup.appendChild(option);
                            
                            selectorsData.push({
                                selector: opt.value,
                                mediaQuery: opt.mediaQuery
                            });
                        });
                        
                        ruleSelector.appendChild(optgroup);
                    }
                });
                
                if (ruleSelector.options.length === 0) {
                    QSDom.setSelectPlaceholder(ruleSelector, t('commandForm.empty.noCssRules'), { disabled: true });
                }
            } catch (error) {
                QSDom.setSelectPlaceholder(ruleSelector, t('commandForm.errors.loadSelectors'), { disabled: true });
            }
        }
        
        await loadSelectors();
        
        // When a selector is chosen, fill in the form fields
        ruleSelector.addEventListener('change', () => {
            const selected = ruleSelector.selectedOptions[0];
            if (selected) {
                // Fill selector input
                if (selectorInput.tagName === 'INPUT') {
                    selectorInput.value = selected.value;
                }
                
                // Fill media query input if present
                if (mediaQueryInput) {
                    const mediaQuery = selected.dataset.mediaQuery || '';
                    if (mediaQueryInput.tagName === 'INPUT') {
                        mediaQueryInput.value = mediaQuery;
                    }
                }
            }
        });
        
        // Refresh after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteStyleRule') {
                await loadSelectors();
            }
        });
    }
}

/**
 * Initialize getKeyframes/deleteKeyframes form with keyframe name selector
 */
async function initKeyframeSelectForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        nameSelect.required = nameInput.required;
        
        // Preserve URL param attribute if present
        if (nameInput.dataset.urlParam !== undefined) {
            nameSelect.dataset.urlParam = '';
        }
        
        nameInput.replaceWith(nameSelect);
        
        // Add empty option for "all keyframes" on getKeyframes
        const isGetCommand = COMMAND_NAME === 'getKeyframes';
        const placeholder = isGetCommand
            ? t('commandForm.select.allKeyframes')
            : t('commandForm.select.keyframe');

        QSDom.setSelectPlaceholder(nameSelect, placeholder);

        try {
            const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
            keyframes.forEach(kf => {
                const option = document.createElement('option');
                option.value = kf.value;
                option.textContent = kf.label;
                nameSelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load keyframes:', error);
        }
        
        // Refresh after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteKeyframes') {
                // Reload the select
                QSDom.setSelectPlaceholder(nameSelect, placeholder);
                try {
                    const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
                    keyframes.forEach(kf => {
                        const option = document.createElement('option');
                        option.value = kf.value;
                        option.textContent = kf.label;
                        nameSelect.appendChild(option);
                    });
                } catch (error) {
                    console.error('Failed to reload keyframes:', error);
                }
            }
        });
    }
}

/**
 * Initialize setKeyframes form with name selector/input and frame builder
 */
async function initSetKeyframesForm() {
    const form = document.getElementById('command-form');
    const nameInput = form.querySelector('[name="name"]');
    const framesTextarea = form.querySelector('[name="frames"]');
    
    if (nameInput && framesTextarea) {
        // Store existing keyframes for reference
        let existingKeyframes = {};
        
        // Add helper section above the name input
        const kfNameSelector = QSDom.el('select', {
            id: 'kf-name-selector', class: 'admin-select', style: 'margin-bottom: var(--space-xs);'
        });
        QSDom.setSelectPlaceholder(kfNameSelector, t('commandForm.select.existingOrNew'));
        const kfNameInput = QSDom.el('input', {
            type: 'text', id: 'kf-name-input', class: 'admin-input',
            placeholder: t('commandForm.setKeyframes.newNamePlaceholder')
        });
        const kfCurrentFrames = QSDom.el('div', {
            id: 'kf-current-frames', class: 'admin-hint',
            style: 'background: var(--color-bg-secondary); padding: var(--space-sm); '
                 + 'border-radius: var(--radius-sm); min-height: 60px; font-family: monospace; '
                 + 'font-size: 12px; overflow: auto; max-height: 100px;',
            text: t('commandForm.setKeyframes.noAnimationSelected')
        });
        // from / to / 0% … are CSS keyframe selectors — the command's own values,
        // not labels, so only "Custom…" is translated.
        const kfFrameKey = QSDom.el('select', { id: 'kf-frame-key', class: 'admin-select' });
        QuickSiteAdmin.appendOptionsToSelect(kfFrameKey, [
            { value: 'from', label: 'from' },
            { value: 'to', label: 'to' },
            { value: '0%', label: '0%' },
            { value: '25%', label: '25%' },
            { value: '50%', label: '50%' },
            { value: '75%', label: '75%' },
            { value: '100%', label: '100%' },
            { value: 'custom', label: t('common.custom') }
        ]);
        const kfFrameKeyCustom = QSDom.el('input', {
            type: 'text', id: 'kf-frame-key-custom', class: 'admin-input',
            placeholder: t('commandForm.setKeyframes.customKeyPlaceholder'),
            style: 'margin-top: var(--space-xs); display: none;'
        });
        const kfFrameValue = QSDom.el('input', {
            type: 'text', id: 'kf-frame-value', class: 'admin-input',
            placeholder: t('commandForm.setKeyframes.framePropsPlaceholder')
        });
        // A shortcut into the value box, built like the frame-key select above:
        // the common animatable properties, then Custom last.
        //
        // The box stays the authoritative field rather than being revealed only
        // for Custom, because a keyframe value is a DECLARATION LIST —
        // "opacity: 0; transform: scale(.5)" — which a property select alone
        // cannot express. Picking a property types "property: " into the box and
        // puts the cursor after it; Custom just hands the box back empty-handed.
        // Nothing you could write before is unreachable now.
        const KEYFRAME_PROPERTIES = [
            'opacity', 'transform', 'color', 'background-color', 'width', 'height',
            'top', 'left', 'right', 'bottom', 'margin', 'padding',
            'border-radius', 'border-color', 'box-shadow', 'filter', 'visibility'
        ];
        const kfFrameProperty = QSDom.el('select', {
            id: 'kf-frame-property', class: 'admin-select',
            style: 'margin-bottom: var(--space-xs);'
        });
        QSDom.setSelectPlaceholder(kfFrameProperty, t('commandForm.setKeyframes.insertProperty'));
        QuickSiteAdmin.appendOptionsToSelect(kfFrameProperty, [
            ...KEYFRAME_PROPERTIES.map(prop => ({ value: prop, label: prop })),
            { value: 'custom', label: t('common.custom') }
        ]);
        const kfAddFrameBtn = _renderButton('kf-add-frame-btn', 'admin-btn admin-btn--secondary',
            t('commandForm.setKeyframes.addFrame'));
        const kfApplyNameBtn = _renderButton('kf-apply-name-btn',
            'admin-btn admin-btn--outline admin-btn--small',
            t('commandForm.setKeyframes.applyName'));
        const kfClearBtn = _renderButton('kf-clear-btn',
            'admin-btn admin-btn--outline admin-btn--small',
            t('commandForm.setKeyframes.clearFrames'));
        const kfLoadExistingBtn = _renderButton('kf-load-existing-btn',
            'admin-btn admin-btn--outline admin-btn--small',
            t('commandForm.setKeyframes.loadExisting'));

        const helperDiv = QSDom.el('div', { class: 'admin-keyframe-builder' }, [
            _renderLabel(t('commandForm.setKeyframes.title')),
            QSDom.el('div', {
                style: 'display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm); '
                     + 'margin-bottom: var(--space-sm);'
            }, [
                QSDom.el('div', null, [
                    _renderLabel(t('commandForm.setKeyframes.animationName'), 'admin-label admin-label--small'),
                    kfNameSelector,
                    kfNameInput
                ]),
                QSDom.el('div', null, [
                    _renderLabel(t('commandForm.setKeyframes.currentFrames'), 'admin-label admin-label--small'),
                    kfCurrentFrames
                ])
            ]),
            QSDom.el('div', {
                style: 'border: 1px solid var(--color-border); border-radius: var(--radius-sm); '
                     + 'padding: var(--space-sm); margin-bottom: var(--space-sm);'
            }, [
                _renderLabel(t('commandForm.setKeyframes.addFrame'), 'admin-label admin-label--small'),
                QSDom.el('div', {
                    style: 'display: flex; gap: var(--space-sm); align-items: flex-start;'
                }, [
                    QSDom.el('div', { style: 'width: 120px;' }, [kfFrameKey, kfFrameKeyCustom]),
                    QSDom.el('div', { style: 'flex: 1;' }, [kfFrameProperty, kfFrameValue]),
                    kfAddFrameBtn
                ])
            ]),
            QSDom.el('div', {
                style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);'
            }, [kfApplyNameBtn, kfClearBtn, kfLoadExistingBtn]),
            _renderHint([t('commandForm.setKeyframes.hint')])
        ]);
        nameInput.parentNode.parentNode.insertBefore(helperDiv, nameInput.parentNode);

        // Load existing keyframes
        async function loadKeyframes() {
            try {
                const keyframes = await QuickSiteAdmin.fetchHelperData('keyframes', []);
                QSDom.setSelectPlaceholder(kfNameSelector, t('commandForm.select.existingOrNew'));
                existingKeyframes = {};
                
                keyframes.forEach(kf => {
                    const option = document.createElement('option');
                    option.value = kf.value;
                    option.textContent = kf.label;
                    kfNameSelector.appendChild(option);
                    existingKeyframes[kf.value] = kf.frames;
                });
            } catch (error) {
                console.error('Failed to load keyframes:', error);
            }
        }
        
        await loadKeyframes();
        
        // Show/hide custom key input
        // Picking a property appends "property: " to the value box and leaves the
        // cursor there. It APPENDS rather than replaces so a second property can
        // be added to the same frame, which is what a declaration list is for.
        kfFrameProperty.addEventListener('change', () => {
            const property = kfFrameProperty.value;
            kfFrameProperty.selectedIndex = 0;   // a shortcut, not a stored choice
            kfFrameValue.focus();
            if (!property || property === 'custom') return;
            const current = kfFrameValue.value.trim();
            const needsSeparator = current !== '' && !current.endsWith(';');
            kfFrameValue.value = current + (needsSeparator ? '; ' : current ? ' ' : '') + property + ': ';
        });

        kfFrameKey.addEventListener('change', () => {
            kfFrameKeyCustom.style.display = kfFrameKey.value === 'custom' ? 'block' : 'none';
        });
        
        /**
         * The read-only "current frames" panel: one `key: value` line per frame.
         * Both come from the project's own style.css via fetchHelperData, so
         * they are built as text nodes rather than glued into markup.
         * @param {Object<string,string>} frames
         * @returns {DocumentFragment|null} null when there is nothing to show
         */
        function _renderFrameList(frames) {
            const entries = Object.entries(frames || {});
            if (entries.length === 0) return null;
            const frag = document.createDocumentFragment();
            for (const [key, value] of entries) {
                frag.appendChild(QSDom.el('strong', { text: key + ':' }));
                frag.appendChild(document.createTextNode(' ' + value));
                frag.appendChild(QSDom.el('br'));
            }
            return frag;
        }

        /** Replace the frames panel with a single line of text. */
        function _showFramesNotice(key) {
            QSDom.clear(kfCurrentFrames);
            kfCurrentFrames.appendChild(document.createTextNode(t(key)));
        }

        // When selecting existing keyframe, show its frames
        kfNameSelector.addEventListener('change', () => {
            const name = kfNameSelector.value;
            if (name && existingKeyframes[name]) {
                kfNameInput.value = '';
                const list = _renderFrameList(existingKeyframes[name]);
                if (list) {
                    QSDom.clear(kfCurrentFrames);
                    kfCurrentFrames.appendChild(list);
                } else {
                    _showFramesNotice('commandForm.setKeyframes.noFrames');
                }
            } else {
                _showFramesNotice('commandForm.setKeyframes.noAnimationSelected');
            }
        });

        // Clear selector when typing new name
        kfNameInput.addEventListener('input', () => {
            if (kfNameInput.value) {
                kfNameSelector.selectedIndex = 0;
                _showFramesNotice('commandForm.setKeyframes.newAnimation');
            }
        });
        
        // Add frame button
        kfAddFrameBtn.addEventListener('click', () => {
            let frameKey = kfFrameKey.value;
            if (frameKey === 'custom') {
                frameKey = kfFrameKeyCustom.value.trim();
            }
            const frameValue = kfFrameValue.value.trim();
            
            if (!frameKey) {
                QuickSiteAdmin.showToast(t('commandForm.toast.selectFrameKey'), 'warning');
                return;
            }
            if (!frameValue) {
                QuickSiteAdmin.showToast(t('commandForm.toast.enterCssProps'), 'warning');
                return;
            }
            
            // Parse current frames
            let frames = {};
            try {
                frames = JSON.parse(framesTextarea.value || '{}');
            } catch {
                frames = {};
            }
            
            // Add frame
            frames[frameKey] = frameValue;
            
            // Update textarea
            framesTextarea.value = JSON.stringify(frames, null, 2);
            
            // Clear value input
            kfFrameValue.value = '';
            
            QuickSiteAdmin.showToast(`Added frame: ${frameKey}`, 'success');
        });
        
        // Apply name button
        kfApplyNameBtn.addEventListener('click', () => {
            const name = kfNameInput.value || kfNameSelector.value;
            if (name) {
                nameInput.value = name;
                QuickSiteAdmin.showToast(`Name set: ${name}`, 'success');
            } else {
                QuickSiteAdmin.showToast(t('commandForm.toast.selectAnimationName'), 'warning');
            }
        });
        
        // Clear frames button
        kfClearBtn.addEventListener('click', () => {
            framesTextarea.value = '{}';
            QuickSiteAdmin.showToast(t('commandForm.toast.framesCleared'), 'success');
        });
        
        // Load existing frames into textarea
        kfLoadExistingBtn.addEventListener('click', () => {
            const name = kfNameSelector.value;
            if (name && existingKeyframes[name]) {
                framesTextarea.value = JSON.stringify(existingKeyframes[name], null, 2);
                nameInput.value = name;
                QuickSiteAdmin.showToast(`Loaded frames for: ${name}`, 'success');
            } else {
                QuickSiteAdmin.showToast(t('commandForm.toast.selectExistingAnimation'), 'warning');
            }
        });
        
        // Refresh after successful command
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'setKeyframes') {
                await loadKeyframes();
                framesTextarea.value = '{}';
                nameInput.value = '';
                _showFramesNotice('commandForm.setKeyframes.noAnimationSelected');
            }
        });
    }
}

/**
 * Initialize route selection for deleteRoute
 */
async function initRouteSelectForm() {
    const form = document.getElementById('command-form');
    const routeInput = form.querySelector('[name="route"]');
    
    if (routeInput && routeInput.tagName !== 'SELECT') {
        // Convert to select
        const routeSelect = document.createElement('select');
        routeSelect.name = 'route';
        routeSelect.className = 'admin-select';
        routeSelect.required = routeInput.required;
        
        if (routeInput.dataset.urlParam !== undefined) {
            routeSelect.dataset.urlParam = '';
        }
        
        routeInput.replaceWith(routeSelect);
        
        await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], t('commandForm.select.route'));

        // Refresh route list after successful delete
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteRoute') {
                await QuickSiteAdmin.populateSelect(routeSelect, 'routes', [], t('commandForm.select.route'));
                routeSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize addRoute form: convert parent field to route select dropdown
 */
async function initAddRouteParentSelect() {
    const form = document.getElementById('command-form');
    const parentInput = form.querySelector('[name="parent"]');
    
    if (parentInput && parentInput.tagName !== 'SELECT') {
        const parentSelect = document.createElement('select');
        parentSelect.name = 'parent';
        parentSelect.className = 'admin-select';
        // parent is optional — no required attribute
        
        if (parentInput.dataset.urlParam !== undefined) {
            parentSelect.dataset.urlParam = '';
        }
        
        parentInput.replaceWith(parentSelect);
        
        // Add "None (root level)" as first option
        const noneOption = document.createElement('option');
        noneOption.value = '';
        noneOption.textContent = t('commandForm.select.noneRoot');
        parentSelect.appendChild(noneOption);
        
        await QuickSiteAdmin.populateSelect(parentSelect, 'routes', [], t('commandForm.select.noneRoot'));

        // Refresh route list after successful add
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'addRoute') {
                await QuickSiteAdmin.populateSelect(parentSelect, 'routes', [], t('commandForm.select.noneRoot'));
            }
        });
    }
}

/**
 * Initialize findComponentUsages form with component select
 */
async function initFindComponentUsagesForm() {
    const form = document.getElementById('command-form');
    const componentInput = form.querySelector('[name="component"]');
    
    if (componentInput && componentInput.tagName !== 'SELECT') {
        const componentSelect = document.createElement('select');
        componentSelect.name = 'component';
        componentSelect.className = 'admin-select';
        componentSelect.required = componentInput.required;
        
        if (componentInput.dataset.urlParam !== undefined) {
            componentSelect.dataset.urlParam = '';
        }
        
        componentInput.replaceWith(componentSelect);
        await QuickSiteAdmin.populateSelect(componentSelect, 'components', [], t('commandForm.select.component'));
    }
}

/**
 * Initialize renameComponent form with oldName component select
 */
async function initRenameComponentForm() {
    const form = document.getElementById('command-form');
    const oldNameInput = form.querySelector('[name="oldName"]');
    
    if (oldNameInput && oldNameInput.tagName !== 'SELECT') {
        const oldNameSelect = document.createElement('select');
        oldNameSelect.name = 'oldName';
        oldNameSelect.className = 'admin-select';
        oldNameSelect.required = oldNameInput.required;
        oldNameInput.replaceWith(oldNameSelect);
        await QuickSiteAdmin.populateSelect(oldNameSelect, 'components', [], t('commandForm.select.componentRename'));

        // Refresh component list after successful rename
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'renameComponent') {
                await QuickSiteAdmin.populateSelect(oldNameSelect, 'components', [], t('commandForm.select.componentRename'));
                oldNameSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize duplicateComponent form with source component select
 */
async function initDuplicateComponentForm() {
    const form = document.getElementById('command-form');
    const sourceInput = form.querySelector('[name="source"]');
    
    if (sourceInput && sourceInput.tagName !== 'SELECT') {
        const sourceSelect = document.createElement('select');
        sourceSelect.name = 'source';
        sourceSelect.className = 'admin-select';
        sourceSelect.required = sourceInput.required;
        sourceInput.replaceWith(sourceSelect);
        await QuickSiteAdmin.populateSelect(sourceSelect, 'components', [], t('commandForm.select.componentDuplicate'));

        // Refresh component list after successful duplicate
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'duplicateComponent') {
                await QuickSiteAdmin.populateSelect(sourceSelect, 'components', [], t('commandForm.select.componentDuplicate'));
                sourceSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize addComponentToNode form with cascading selects and dynamic data fields
 */
async function initAddComponentToNodeForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], t('commandForm.select.structureType'));
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.typeFirst'));
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert targetNodeId input to select
    const targetNodeIdInput = form.querySelector('[name="targetNodeId"]');
    if (targetNodeIdInput && targetNodeIdInput.tagName !== 'SELECT') {
        const targetNodeIdSelect = document.createElement('select');
        targetNodeIdSelect.name = 'targetNodeId';
        targetNodeIdSelect.className = 'admin-select';
        targetNodeIdSelect.required = targetNodeIdInput.required;
        QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.structureFirst'));
        targetNodeIdSelect.disabled = true;
        targetNodeIdInput.replaceWith(targetNodeIdSelect);
    }
    
    // Convert position input to select
    const positionInput = form.querySelector('[name="position"]');
    if (positionInput && positionInput.tagName !== 'SELECT') {
        const positionSelect = document.createElement('select');
        positionSelect.name = 'position';
        positionSelect.className = 'admin-select';
        QSDom.setSelectPlaceholder(positionSelect, t('commandForm.addComponent.selectPosition'));
        QuickSiteAdmin.appendOptionsToSelect(positionSelect, [
            { value: 'inside', label: t('commandForm.addComponent.positionInside') },
            { value: 'before', label: t('commandForm.addComponent.positionBefore') },
            { value: 'after', label: t('commandForm.addComponent.positionAfter') }
        ]);
        positionInput.replaceWith(positionSelect);
    }
    
    // Convert component input to select
    const componentInput = form.querySelector('[name="component"]');
    if (componentInput && componentInput.tagName !== 'SELECT') {
        const componentSelect = document.createElement('select');
        componentSelect.name = 'component';
        componentSelect.className = 'admin-select';
        componentSelect.required = componentInput.required;
        componentInput.replaceWith(componentSelect);
        await QuickSiteAdmin.populateSelect(componentSelect, 'components', [], t('commandForm.select.componentAdd'));
    }
    
    // Find or create data field container
    const dataInput = form.querySelector('[name="data"]');
    let dataContainer = null;
    if (dataInput) {
        // Create a container for the dynamic variable fields
        dataContainer = _renderComponentDataBuilder(
            'component-data-fields',
            'commandForm.addComponent.dataSubtitle',
            'commandForm.addComponent.dataEmpty'
        );
        dataInput.parentNode.insertBefore(dataContainer, dataInput);
        dataInput.style.display = 'none'; // Hide the original JSON textarea
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const targetNodeIdSelect = form.querySelector('[name="targetNodeId"]');
    const componentSelect = form.querySelector('[name="component"]');
    
    // Function to load node options
    async function loadNodeOptions() {
        if (!targetNodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.typeFirst'));
            targetNodeIdSelect.disabled = true;
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.nameFirst'));
            targetNodeIdSelect.disabled = true;
            return;
        }
        
        targetNodeIdSelect.disabled = false;
        
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.targetNode'));
            QuickSiteAdmin.appendOptionsToSelect(targetNodeIdSelect, nodes);
        } catch (error) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.errors.loadNodes'));
        }
    }
    
    // Function to build dynamic data fields based on component variables
    async function buildDataFields(componentName) {
        const fieldsContainer = document.getElementById('component-data-fields');
        if (!fieldsContainer || !componentName) {
            _showCenteredHint(fieldsContainer, t('commandForm.addComponent.dataEmpty'));
            return;
        }

        try {
            const componentData = await QuickSiteAdmin.fetchHelperData('component-variables', [componentName]);
            const variables = componentData.variables || [];

            if (variables.length === 0) {
                _showCenteredHint(fieldsContainer, t('commandForm.componentData.noVariables'));
                return;
            }

            QSDom.clear(fieldsContainer);
            fieldsContainer.appendChild(_renderComponentDataTable(variables, 'data-var-'));

            // Initialize textKey selectors with translation keys
            const textKeySelects = fieldsContainer.querySelectorAll('.admin-select--textkey');
            if (textKeySelects.length > 0) {
                await initTextKeySelectors(textKeySelects);
            }

            // Set up event listeners to sync to hidden data field
            setupDataFieldSync();

        } catch (error) {
            console.error('Failed to load component variables:', error);
            _showCenteredHint(fieldsContainer, t('commandForm.componentData.loadFailed'), true);
        }
    }
    
    // Initialize textKey selectors with translation keys
    async function initTextKeySelectors(selects) {
        try {
            // Get available languages first
            const languages = await QuickSiteAdmin.fetchHelperData('languages', []);
            const defaultLang = languages[0]?.value || 'en';
            
            // Fetch translation keys
            const keys = await QuickSiteAdmin.fetchHelperData('translation-keys', [defaultLang]);
            
            selects.forEach(select => {
                const targetId = select.dataset.target;
                QSDom.setSelectPlaceholder(select, t('commandForm.select.translationKey'));
                
                keys.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key.value;
                    option.textContent = key.label;
                    select.appendChild(option);
                });
                
                // When select changes, update the input field
                select.addEventListener('change', () => {
                    const input = document.getElementById(targetId);
                    if (input && select.value) {
                        input.value = select.value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        } catch (error) {
            console.error('Failed to load translation keys:', error);
            selects.forEach(select => {
                QSDom.setSelectPlaceholder(select, t('commandForm.errors.loadKeys'));
            });
        }
    }
    
    // Sync all variable inputs to the hidden data JSON field
    function setupDataFieldSync() {
        const dataField = form.querySelector('[name="data"]');
        if (!dataField) return;
        
        const varInputs = document.querySelectorAll('[data-var-input]');
        varInputs.forEach(input => {
            input.addEventListener('input', () => {
                const data = {};
                varInputs.forEach(inp => {
                    const varName = inp.dataset.varInput;
                    const value = inp.value.trim();
                    if (value) {
                        data[varName] = value;
                    }
                });
                dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
            });
        });
    }
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], t('commandForm.select.page'));
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], t('commandForm.select.component'));
            } else {
                QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
                nameSelect.disabled = true;
                await loadNodeOptions();
            }
            
            if (targetNodeIdSelect && (type === 'page' || type === 'component')) {
                QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.nameFirst'));
                targetNodeIdSelect.disabled = true;
            }
        });
        
        nameSelect.addEventListener('change', loadNodeOptions);
    }
    
    // When component is selected, build the data fields
    if (componentSelect) {
        componentSelect.addEventListener('change', () => {
            buildDataFields(componentSelect.value);
        });
    }
}

/**
 * Initialize editComponentToNode form with cascading selects and dynamic data fields
 */
async function initEditComponentToNodeForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        typeSelect.required = typeInput.required;
        typeInput.replaceWith(typeSelect);
        await QuickSiteAdmin.populateSelect(typeSelect, 'structure-types', [], t('commandForm.select.structureType'));
    }
    
    // Convert name input to select
    const nameInput = form.querySelector('[name="name"]');
    if (nameInput && nameInput.tagName !== 'SELECT') {
        const nameSelect = document.createElement('select');
        nameSelect.name = 'name';
        nameSelect.className = 'admin-select';
        QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.typeFirst'));
        nameSelect.disabled = true;
        nameInput.replaceWith(nameSelect);
    }
    
    // Convert targetNodeId input to select
    const targetNodeIdInput = form.querySelector('[name="targetNodeId"]');
    if (targetNodeIdInput && targetNodeIdInput.tagName !== 'SELECT') {
        const targetNodeIdSelect = document.createElement('select');
        targetNodeIdSelect.name = 'targetNodeId';
        targetNodeIdSelect.className = 'admin-select';
        targetNodeIdSelect.required = targetNodeIdInput.required;
        QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.structureFirst'));
        targetNodeIdSelect.disabled = true;
        targetNodeIdInput.replaceWith(targetNodeIdSelect);
    }
    
    // Find or create data field container
    const dataInput = form.querySelector('[name="data"]');
    let dataContainer = null;
    if (dataInput) {
        dataContainer = _renderComponentDataBuilder(
            'edit-component-data-fields',
            'commandForm.editComponent.dataSubtitle',
            'commandForm.editComponent.dataEmpty'
        );
        dataInput.parentNode.insertBefore(dataContainer, dataInput);
        dataInput.style.display = 'none';
    }
    
    // Set up cascading behavior
    const typeSelect = form.querySelector('[name="type"]');
    const nameSelect = form.querySelector('[name="name"]');
    const targetNodeIdSelect = form.querySelector('[name="targetNodeId"]');
    
    // Store component nodes data for extracting component names
    let componentNodesData = [];
    
    // Function to load node options - only show component nodes
    async function loadComponentNodes() {
        if (!targetNodeIdSelect) return;
        
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        if (!type) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.typeFirst'));
            targetNodeIdSelect.disabled = true;
            componentNodesData = [];
            return;
        }
        
        if ((type === 'page' || type === 'component') && !name) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.nameFirst'));
            targetNodeIdSelect.disabled = true;
            componentNodesData = [];
            return;
        }
        
        targetNodeIdSelect.disabled = false;
        
        const params = (type === 'page' || type === 'component') ? [type, name] : [type];
        
        try {
            const nodes = await QuickSiteAdmin.fetchHelperData('structure-nodes', params);
            componentNodesData = nodes.filter(n => n.label && n.label.includes('[component:'));
            
            if (componentNodesData.length === 0) {
                QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.empty.noComponentNodes'));
            } else {
                QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.componentNode'));
                QuickSiteAdmin.appendOptionsToSelect(targetNodeIdSelect, componentNodesData);
            }
        } catch (error) {
            QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.errors.loadNodes'));
            componentNodesData = [];
        }
        
        // Clear data fields
        _showCenteredHint(document.getElementById('edit-component-data-fields'),
            t('commandForm.editComponent.dataEmpty'));
    }
    
    // Extract component name from node label (e.g., "div [component:hero]" -> "hero")
    function getComponentNameFromNode(nodeValue) {
        const node = componentNodesData.find(n => n.value === nodeValue);
        if (!node || !node.label) return null;
        
        const match = node.label.match(/\[component:([^\]]+)\]/);
        return match ? match[1] : null;
    }
    
    // Build dynamic data fields for the component
    async function buildDataFields(componentName, currentData = {}) {
        const fieldsContainer = document.getElementById('edit-component-data-fields');
        if (!fieldsContainer || !componentName) {
            _showCenteredHint(fieldsContainer, t('commandForm.editComponent.dataEmpty'));
            return;
        }

        try {
            const componentData = await QuickSiteAdmin.fetchHelperData('component-variables', [componentName]);
            const variables = componentData.variables || [];

            if (variables.length === 0) {
                _showCenteredHint(fieldsContainer, t('commandForm.componentData.noVariables'));
                return;
            }

            QSDom.clear(fieldsContainer);
            fieldsContainer.appendChild(
                _renderComponentDataTable(variables, 'edit-data-var-', currentData));

            // Initialize textKey selectors
            const textKeySelects = fieldsContainer.querySelectorAll('.admin-select--textkey');
            if (textKeySelects.length > 0) {
                await initTextKeySelectors(textKeySelects, currentData);
            }

            setupDataFieldSync();

        } catch (error) {
            console.error('Failed to load component variables:', error);
            _showCenteredHint(fieldsContainer, t('commandForm.componentData.loadFailed'), true);
        }
    }
    
    // Initialize textKey selectors
    async function initTextKeySelectors(selects, currentData = {}) {
        try {
            const languages = await QuickSiteAdmin.fetchHelperData('languages', []);
            const defaultLang = languages[0]?.value || 'en';
            const keys = await QuickSiteAdmin.fetchHelperData('translation-keys', [defaultLang]);
            
            selects.forEach(select => {
                const targetId = select.dataset.target;
                const input = document.getElementById(targetId);
                const currentValue = input?.value || '';
                
                QSDom.setSelectPlaceholder(select, t('commandForm.select.translationKey'));
                
                keys.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key.value;
                    option.textContent = key.label;
                    if (key.value === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                
                select.addEventListener('change', () => {
                    if (input && select.value) {
                        input.value = select.value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        } catch (error) {
            console.error('Failed to load translation keys:', error);
            selects.forEach(select => {
                QSDom.setSelectPlaceholder(select, t('commandForm.errors.loadKeys'));
            });
        }
    }
    
    // Sync variable inputs to hidden data field
    function setupDataFieldSync() {
        const dataField = form.querySelector('[name="data"]');
        if (!dataField) return;
        
        const varInputs = document.querySelectorAll('[data-var-input]');
        
        // Initial sync
        const data = {};
        varInputs.forEach(inp => {
            const varName = inp.dataset.varInput;
            const value = inp.value.trim();
            if (value) {
                data[varName] = value;
            }
        });
        dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
        
        // Listen for changes
        varInputs.forEach(input => {
            input.addEventListener('input', () => {
                const data = {};
                varInputs.forEach(inp => {
                    const varName = inp.dataset.varInput;
                    const value = inp.value.trim();
                    if (value) {
                        data[varName] = value;
                    }
                });
                dataField.value = Object.keys(data).length > 0 ? JSON.stringify(data, null, 2) : '';
            });
        });
    }
    
    // Fetch current node data when a component node is selected
    async function loadCurrentNodeData(nodeId) {
        const type = typeSelect?.value;
        const name = nameSelect?.value;
        
        const componentName = getComponentNameFromNode(nodeId);
        if (!componentName) {
            return;
        }
        
        try {
            // Fetch the node to get current data
            const params = [type];
            if (type === 'page' || type === 'component') {
                params.push(name);
            }
            params.push(nodeId);
            
            const result = await QuickSiteAdmin.apiRequest('getStructure', 'GET', null, params);
            const currentData = result.data?.data?.node?.data || {};
            
            await buildDataFields(componentName, currentData);
        } catch (error) {
            console.error('Failed to load node data:', error);
            await buildDataFields(componentName, {});
        }
    }
    
    if (typeSelect && nameSelect) {
        typeSelect.addEventListener('change', async () => {
            const type = typeSelect.value;
            nameSelect.disabled = false;
            
            if (type === 'page') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'pages', [], t('commandForm.select.page'));
            } else if (type === 'component') {
                await QuickSiteAdmin.populateSelect(nameSelect, 'components', [], t('commandForm.select.component'));
            } else {
                QSDom.setSelectPlaceholder(nameSelect, t('commandForm.select.notRequiredForType'));
                nameSelect.disabled = true;
                await loadComponentNodes();
            }
            
            if (targetNodeIdSelect && (type === 'page' || type === 'component')) {
                QSDom.setSelectPlaceholder(targetNodeIdSelect, t('commandForm.select.nameFirst'));
                targetNodeIdSelect.disabled = true;
            }
        });
        
        nameSelect.addEventListener('change', loadComponentNodes);
    }
    
    // When a component node is selected, load its current data and build fields
    if (targetNodeIdSelect) {
        targetNodeIdSelect.addEventListener('change', async () => {
            const nodeId = targetNodeIdSelect.value;
            if (nodeId) {
                await loadCurrentNodeData(nodeId);
            }
        });
    }
}

/**
 * Initialize language selection for getTranslation, validateTranslations and the
 * other read-only language commands.
 */
async function initLanguageSelectForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="lang"]');
    
    if (langInput && langInput.tagName !== 'SELECT') {
        // Convert to select
        const langSelect = document.createElement('select');
        langSelect.name = 'lang';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], t('commandForm.select.language'));

        // No refresh-after-success listener: every command that reaches this form
        // is read-only, so the language list cannot go stale under it. The one
        // command here that DID delete a language takes `code` rather than
        // `lang`, so it renders a different form entirely.
    }
}

/**
 * Initialize createAlias form with type and target selects
 */
async function initCreateAliasForm() {
    const form = document.getElementById('command-form');
    
    // Convert type input to select
    const typeInput = form.querySelector('[name="type"]');
    if (typeInput && typeInput.tagName !== 'SELECT') {
        const typeSelect = document.createElement('select');
        typeSelect.name = 'type';
        typeSelect.className = 'admin-select';
        
        if (typeInput.dataset.urlParam !== undefined) {
            typeSelect.dataset.urlParam = '';
        }
        
        typeInput.replaceWith(typeSelect);
        
        await QuickSiteAdmin.populateSelect(typeSelect, 'alias-types', [], t('commandForm.select.aliasType'));
    }
    
    // Convert target input to select with routes
    const targetInput = form.querySelector('[name="target"]');
    if (targetInput && targetInput.tagName !== 'SELECT') {
        const targetSelect = document.createElement('select');
        targetSelect.name = 'target';
        targetSelect.className = 'admin-select';
        targetSelect.required = targetInput.required;
        
        if (targetInput.dataset.urlParam !== undefined) {
            targetSelect.dataset.urlParam = '';
        }
        
        targetInput.replaceWith(targetSelect);
        
        // Fetch routes and format with leading slash
        const routesData = await QuickSiteAdmin.fetchHelperData('routes', []);
        QSDom.setSelectPlaceholder(targetSelect, t('commandForm.select.targetRoute'));
        routesData.forEach(route => {
            const option = document.createElement('option');
            option.value = '/' + route.value; // Add leading slash for target
            option.textContent = '/' + route.label;
            targetSelect.appendChild(option);
        });
    }
}

/**
 * Initialize deleteAlias form with alias select
 */
async function initDeleteAliasForm() {
    const form = document.getElementById('command-form');
    const aliasInput = form.querySelector('[name="alias"]');
    
    if (aliasInput && aliasInput.tagName !== 'SELECT') {
        const aliasSelect = document.createElement('select');
        aliasSelect.name = 'alias';
        aliasSelect.className = 'admin-select';
        aliasSelect.required = aliasInput.required;
        
        if (aliasInput.dataset.urlParam !== undefined) {
            aliasSelect.dataset.urlParam = '';
        }
        
        aliasInput.replaceWith(aliasSelect);
        
        await QuickSiteAdmin.populateSelect(aliasSelect, 'aliases', [], t('commandForm.select.aliasDelete'));
        
        // Listen for successful deletion to refresh the select
        form.addEventListener('command-success', async (e) => {
            if (e.detail.command === 'deleteAlias') {
                // Refresh the select after successful deletion
                await QuickSiteAdmin.populateSelect(aliasSelect, 'aliases', [], t('commandForm.select.aliasDelete'));
                // Reset to default option
                aliasSelect.selectedIndex = 0;
            }
        });
    }
}

/**
 * Initialize setTranslationKeys form with language select and key selector helper
 */
async function initSetTranslationKeysForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="language"]');
    const translationsTextarea = form.querySelector('[name="translations"]');
    
    // Convert language to select if needed
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'language';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], t('commandForm.select.language'));
        
        // Add key selector helper above the translations textarea
        if (translationsTextarea) {
            const keySelector = QSDom.el('select', {
                id: 'key-selector', class: 'admin-select', style: 'flex: 1;', size: '8'
            });
            QSDom.setSelectPlaceholder(keySelector, t('commandForm.select.languageFirst'),
                { disabled: true });
            // A textarea, not a single-line input: a translation value can be a
            // paragraph, and the author needs to see what they are editing. It is
            // resizable VERTICALLY only — a horizontal drag would widen this
            // column and squeeze the key list beside it, which is the problem
            // this layout had when the current value was printed as a hint.
            const valueInput = QSDom.el('textarea', {
                id: 'value-input', class: 'admin-textarea', rows: '3',
                placeholder: t('commandForm.setTranslationKeys.valuePlaceholder'),
                style: 'resize: vertical;'
            });
            const addBtn = _renderButton('add-translation-btn', 'admin-btn admin-btn--secondary',
                t('commandForm.setTranslationKeys.addTranslation'));
            const clearBtn = _renderButton('clear-translations-btn', 'admin-btn admin-btn--outline',
                t('common.clearAll'), t('commandForm.setTranslationKeys.clearAllTitle'));

            const helperDiv = QSDom.el('div', { class: 'admin-key-selector' }, [
                _renderLabel(t('commandForm.setTranslationKeys.title')),
                QSDom.el('div', {
                    style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);'
                }, [
                    keySelector,
                    // Fixed width, flex:none — nothing in this column can grow and
                    // steal space from the key list, whatever a value contains.
                    QSDom.el('div', {
                        style: 'display: flex; flex-direction: column; gap: var(--space-xs); '
                             + 'width: 220px; flex: none;'
                    }, [valueInput, addBtn, clearBtn])
                ]),
                _renderHint([t('commandForm.setTranslationKeys.legend')])
            ]);
            translationsTextarea.parentNode.insertBefore(helperDiv, translationsTextarea);

            // Store current translations data for showing current values
            let currentTranslations = {};
            
            // Load keys when language changes
            langSelect.addEventListener('change', async () => {
                const lang = langSelect.value;
                if (lang) {
                    try {
                        // Fetch grouped keys (used/unused/unset)
                        const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                        QSDom.clear(keySelector);
                        
                        // Add unset keys first (most important - needs translation)
                        if (data.unset && data.unset.length > 0) {
                            const unsetGroup = document.createElement('optgroup');
                            unsetGroup.label = `🔴 Unset Keys (${data.unset.length}) - Need Translation`;
                            data.unset.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                option.dataset.isUnset = 'true';
                                unsetGroup.appendChild(option);
                            });
                            keySelector.appendChild(unsetGroup);
                        }
                        
                        // Add used keys
                        if (data.used && data.used.length > 0) {
                            const usedGroup = document.createElement('optgroup');
                            usedGroup.label = `🟢 Used Keys (${data.used.length})`;
                            data.used.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                usedGroup.appendChild(option);
                            });
                            keySelector.appendChild(usedGroup);
                        }
                        
                        // Add unused keys
                        if (data.unused && data.unused.length > 0) {
                            const unusedGroup = document.createElement('optgroup');
                            unusedGroup.label = `🟡 Unused Keys (${data.unused.length})`;
                            data.unused.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                unusedGroup.appendChild(option);
                            });
                            keySelector.appendChild(unusedGroup);
                        }
                        
                        // Also fetch full translations for current value display
                        const fullData = await QuickSiteAdmin.fetchHelperData('translation-full', [lang]);
                        currentTranslations = fullData || {};
                    } catch (error) {
                        QSDom.setSelectPlaceholder(keySelector, t('commandForm.errors.loadKeys'), { disabled: true });
                    }
                } else {
                    QSDom.setSelectPlaceholder(keySelector, t('commandForm.select.languageFirst'), { disabled: true });
                    currentTranslations = {};
                }
                valueInput.value = '';
            });

            // Selecting a key seeds the value box with what is stored, so an edit
            // starts from what is there — the same affordance the Variable
            // Selector on setRootVariables gives. The box IS the display of the
            // current value; there is no separate readout to keep in step.
            // An UNSET key seeds nothing: there is no current value to edit, and
            // pre-filling one would invite saving a value the author never typed.
            // The key list already marks those keys, so nothing is lost by it.
            keySelector.addEventListener('change', () => {
                const key = keySelector.value;
                const isUnset = keySelector.selectedOptions[0]?.dataset.isUnset === 'true';
                const value = (key && !isUnset) ? getNestedValue(currentTranslations, key) : undefined;
                valueInput.value = value !== undefined ? value : '';
            });
            
            // Add translation button
            addBtn.addEventListener('click', () => {
                const key = keySelector.value;
                const value = valueInput.value;
                
                if (!key) {
                    QuickSiteAdmin.showToast(t('commandForm.toast.selectKey'), 'warning');
                    return;
                }
                if (!value) {
                    QuickSiteAdmin.showToast(t('commandForm.toast.enterValue'), 'warning');
                    return;
                }
                
                // Parse current translations object
                let translations = {};
                try {
                    translations = JSON.parse(translationsTextarea.value || '{}');
                } catch {
                    translations = {};
                }
                
                // Convert dot notation to nested object and merge
                setNestedValue(translations, key, value);
                
                // Update textarea
                translationsTextarea.value = JSON.stringify(translations, null, 2);
                
                // Clear inputs
                valueInput.value = '';
                
                QuickSiteAdmin.showToast(`Added: ${key}`, 'success');
            });
            
            // Clear button
            clearBtn.addEventListener('click', () => {
                translationsTextarea.value = '{}';
            });
            
            // Helper function to refresh key selector
            async function refreshKeySelector() {
                const lang = langSelect.value;
                if (!lang) return;
                
                try {
                    const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                    QSDom.clear(keySelector);
                    
                    // Add unset keys first
                    if (data.unset && data.unset.length > 0) {
                        const unsetGroup = document.createElement('optgroup');
                        unsetGroup.label = `🔴 Unset Keys (${data.unset.length}) - Need Translation`;
                        data.unset.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            option.dataset.isUnset = 'true';
                            unsetGroup.appendChild(option);
                        });
                        keySelector.appendChild(unsetGroup);
                    }
                    
                    // Add used keys
                    if (data.used && data.used.length > 0) {
                        const usedGroup = document.createElement('optgroup');
                        usedGroup.label = `🟢 Used Keys (${data.used.length})`;
                        data.used.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            usedGroup.appendChild(option);
                        });
                        keySelector.appendChild(usedGroup);
                    }
                    
                    // Add unused keys
                    if (data.unused && data.unused.length > 0) {
                        const unusedGroup = document.createElement('optgroup');
                        unusedGroup.label = `🟡 Unused Keys (${data.unused.length})`;
                        data.unused.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            unusedGroup.appendChild(option);
                        });
                        keySelector.appendChild(unusedGroup);
                    }
                    
                    // Refresh full translations too
                    const fullData = await QuickSiteAdmin.fetchHelperData('translation-full', [lang]);
                    currentTranslations = fullData || {};
                } catch (error) {
                    console.error('Error refreshing keys:', error);
                }
            }
            
            // Refresh selector after successful command
            form.addEventListener('command-success', async (e) => {
                if (e.detail.command === 'setTranslationKeys') {
                    await refreshKeySelector();
                    // Clear the textarea for next input
                    translationsTextarea.value = '{}';
                    valueInput.value = '';
                }
            });
        }
    }
}

/**
 * Get nested value from object using dot notation
 */
function getNestedValue(obj, path) {
    return path.split('.').reduce((current, key) => 
        current && current[key] !== undefined ? current[key] : undefined, obj);
}

/**
 * Set nested value in object using dot notation
 */
function setNestedValue(obj, path, value) {
    const keys = path.split('.');
    let current = obj;
    
    for (let i = 0; i < keys.length - 1; i++) {
        const key = keys[i];
        if (!(key in current) || typeof current[key] !== 'object') {
            current[key] = {};
        }
        current = current[key];
    }
    
    current[keys[keys.length - 1]] = value;
}

/**
 * Initialize deleteTranslationKeys form with key selector helper
 */
async function initDeleteTranslationKeysForm() {
    const form = document.getElementById('command-form');
    const langInput = form.querySelector('[name="language"]');
    const keysTextarea = form.querySelector('[name="keys"]');
    
    // Convert language to select if needed
    if (langInput && langInput.tagName !== 'SELECT') {
        const langSelect = document.createElement('select');
        langSelect.name = 'language';
        langSelect.className = 'admin-select';
        langSelect.required = langInput.required;
        
        if (langInput.dataset.urlParam !== undefined) {
            langSelect.dataset.urlParam = '';
        }
        
        langInput.replaceWith(langSelect);
        
        await QuickSiteAdmin.populateSelect(langSelect, 'languages', [], t('commandForm.select.language'));
        
        // Add key selector helper above the keys textarea
        if (keysTextarea) {
            const keySelector = QSDom.el('select', {
                id: 'key-selector', class: 'admin-select', style: 'flex: 1;',
                multiple: 'multiple', size: '8'
            });
            QSDom.setSelectPlaceholder(keySelector, t('commandForm.select.languageFirst'),
                { disabled: true });
            const addSelectedBtn = _renderButton('add-selected-btn', 'admin-btn admin-btn--secondary',
                t('commandForm.deleteTranslationKeys.addSelected'));
            const addAllUnusedBtn = _renderButton('add-all-unused-btn', 'admin-btn admin-btn--outline',
                t('commandForm.deleteTranslationKeys.addAllUnused'),
                t('commandForm.deleteTranslationKeys.addAllUnusedTitle'));
            const clearKeysBtn = _renderButton('clear-keys-btn', 'admin-btn admin-btn--outline',
                t('commandForm.deleteTranslationKeys.clearList'),
                t('commandForm.deleteTranslationKeys.clearListTitle'));

            const helperDiv = QSDom.el('div', { class: 'admin-key-selector' }, [
                _renderLabel(t('commandForm.deleteTranslationKeys.title')),
                QSDom.el('div', {
                    style: 'display: flex; gap: var(--space-sm); margin-bottom: var(--space-sm);'
                }, [
                    keySelector,
                    QSDom.el('div', {
                        style: 'display: flex; flex-direction: column; gap: var(--space-xs);'
                    }, [addSelectedBtn, addAllUnusedBtn, clearKeysBtn])
                ]),
                _renderHint([t('commandForm.deleteTranslationKeys.hint')])
            ]);
            keysTextarea.parentNode.insertBefore(helperDiv, keysTextarea);

            // Store unused keys for bulk add
            let unusedKeys = [];
            
            // Load keys when language changes
            langSelect.addEventListener('change', async () => {
                const lang = langSelect.value;
                if (lang) {
                    // Fetch grouped keys (used/unused)
                    QSDom.setSelectPlaceholder(keySelector, t('common.loading'), { disabled: true });
                    try {
                        const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                        QSDom.clear(keySelector);
                        
                        // Store unused keys for bulk add
                        unusedKeys = (data.unused || []).map(k => k.value);
                        
                        // Add Used keys optgroup
                        if (data.used && data.used.length > 0) {
                            const usedGroup = document.createElement('optgroup');
                            usedGroup.label = `✓ Used Keys (${data.used.length})`;
                            data.used.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                usedGroup.appendChild(option);
                            });
                            keySelector.appendChild(usedGroup);
                        }
                        
                        // Add Unused keys optgroup
                        if (data.unused && data.unused.length > 0) {
                            const unusedGroup = document.createElement('optgroup');
                            unusedGroup.label = `⚠ Unused Keys (${data.unused.length})`;
                            data.unused.forEach(opt => {
                                const option = document.createElement('option');
                                option.value = opt.value;
                                option.textContent = opt.label;
                                unusedGroup.appendChild(option);
                            });
                            keySelector.appendChild(unusedGroup);
                        }
                        
                        if (!data.used?.length && !data.unused?.length) {
                            QSDom.setSelectPlaceholder(keySelector, t('commandForm.empty.noKeys'), { disabled: true });
                        }
                    } catch (error) {
                        QSDom.setSelectPlaceholder(keySelector, t('commandForm.errors.loadKeys'), { disabled: true });
                    }
                } else {
                    QSDom.setSelectPlaceholder(keySelector, t('commandForm.select.languageFirst'), { disabled: true });
                    unusedKeys = [];
                }
            });
            
            // Add selected keys button handler
            addSelectedBtn.addEventListener('click', () => {
                const selectedOptions = Array.from(keySelector.selectedOptions);
                if (selectedOptions.length === 0) return;
                
                // Parse current keys
                let currentKeys = [];
                try {
                    currentKeys = JSON.parse(keysTextarea.value || '[]');
                } catch {
                    currentKeys = [];
                }
                
                // Add selected keys
                selectedOptions.forEach(opt => {
                    if (opt.value && !currentKeys.includes(opt.value)) {
                        currentKeys.push(opt.value);
                    }
                });
                
                keysTextarea.value = JSON.stringify(currentKeys, null, 2);
            });
            
            // Add all unused keys button handler
            addAllUnusedBtn.addEventListener('click', () => {
                if (unusedKeys.length === 0) {
                    QuickSiteAdmin.showToast(t('commandForm.toast.noUnusedKeys'), 'warning');
                    return;
                }
                
                // Parse current keys
                let currentKeys = [];
                try {
                    currentKeys = JSON.parse(keysTextarea.value || '[]');
                } catch {
                    currentKeys = [];
                }
                
                // Add all unused keys
                unusedKeys.forEach(key => {
                    if (!currentKeys.includes(key)) {
                        currentKeys.push(key);
                    }
                });
                
                keysTextarea.value = JSON.stringify(currentKeys, null, 2);
                QuickSiteAdmin.showToast(`Added ${unusedKeys.length} unused keys`, 'success');
            });
            
            // Clear keys button handler
            clearKeysBtn.addEventListener('click', () => {
                keysTextarea.value = '[]';
            });
            
            // Helper function to refresh key selector
            async function refreshKeySelector() {
                const lang = langSelect.value;
                if (!lang) return;
                
                QSDom.setSelectPlaceholder(keySelector, t('commandForm.status.refreshing'), { disabled: true });
                try {
                    const data = await QuickSiteAdmin.fetchHelperData('translation-keys-grouped', [lang]);
                    QSDom.clear(keySelector);
                    
                    // Store unused keys for bulk add
                    unusedKeys = (data.unused || []).map(k => k.value);
                    
                    // Add Used keys optgroup
                    if (data.used && data.used.length > 0) {
                        const usedGroup = document.createElement('optgroup');
                        usedGroup.label = `✓ Used Keys (${data.used.length})`;
                        data.used.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            usedGroup.appendChild(option);
                        });
                        keySelector.appendChild(usedGroup);
                    }
                    
                    // Add Unused keys optgroup
                    if (data.unused && data.unused.length > 0) {
                        const unusedGroup = document.createElement('optgroup');
                        unusedGroup.label = `⚠ Unused Keys (${data.unused.length})`;
                        data.unused.forEach(opt => {
                            const option = document.createElement('option');
                            option.value = opt.value;
                            option.textContent = opt.label;
                            unusedGroup.appendChild(option);
                        });
                        keySelector.appendChild(unusedGroup);
                    }
                    
                    if (!data.used?.length && !data.unused?.length) {
                        QSDom.setSelectPlaceholder(keySelector, t('commandForm.empty.noKeys'), { disabled: true });
                    }
                } catch (error) {
                    QSDom.setSelectPlaceholder(keySelector, t('commandForm.errors.refreshKeys'), { disabled: true });
                }
            }
            
            // Refresh selector after successful deletion
            form.addEventListener('command-success', async (e) => {
                if (e.detail.command === 'deleteTranslationKeys') {
                    await refreshKeySelector();
                    // Clear the textarea
                    keysTextarea.value = '[]';
                }
            });
        }
    }
}

/**
 * Format file size for display
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function renderCommandForm(doc) {
    const form = document.getElementById('command-form');
    const paramsContainer = document.getElementById('command-params');
    const descriptionEl = document.getElementById('command-description');
    const methodBadge = document.getElementById('method-badge');
    
    // Set description
    descriptionEl.textContent = doc.description || '';
    
    // Set method badge
    const method = doc.method || 'GET';
    methodBadge.textContent = method;
    methodBadge.className = 'badge badge--' + method.toLowerCase();
    form.dataset.method = method;

    // Commands that answer BYTES rather than a JSON envelope (downloadBuild,
    // downloadExport) are declared as such in help.php with the code 'binary'.
    // The executor reads this flag and saves the response as a file instead of
    // parsing it — without it the page reads a ZIP as text, fails to parse it,
    // and prints megabytes of mangled binary into the response panel.
    // Driven by the spec, not by a list of command names, so declaring a new
    // streaming command in help.php is all that is needed.
    if (doc.success_response && doc.success_response.code === 'binary') {
        form.dataset.binaryResponse = '1';
    } else {
        delete form.dataset.binaryResponse;
    }

    // Destructive commands get an "are you sure" before they run. The panel
    // already has this command's help doc in hand, so the flag rides across on
    // the form exactly as binaryResponse above does — admin.js reads it in
    // isDestructiveCommand() and there is no second help fetch.
    //
    // The flag names only what a command's NAME does not already reveal
    // (transferOwnership, restoreBackup, importProject, deployBuild …); the
    // gate falls back to a delete/remove/clear/reset/purge naming convention,
    // so an obvious deleteFoo is covered whether or not anyone flagged it.
    if (doc.destructive === true) {
        form.dataset.destructive = '1';
    } else {
        delete form.dataset.destructive;
    }
    
    // Generate form fields
    const params = doc.parameters || {};
    const paramKeys = Object.keys(params);
    
    QSDom.clear(paramsContainer);

    if (paramKeys.length === 0) {
        paramsContainer.appendChild(
            QSDom.el('div', { class: 'admin-empty', style: 'padding: var(--space-md);' }, [
                QSDom.el('p', { text: t('commands.noParameters') })
            ]));
        return;
    }

    // Separate required and optional
    const required = paramKeys.filter(k => params[k].required);
    const optional = paramKeys.filter(k => !params[k].required);

    if (required.length > 0) {
        paramsContainer.appendChild(QSDom.el('h4', {
            class: 'admin-form-section-title', text: t('commands.requiredParams')
        }));
        required.forEach(key => {
            paramsContainer.appendChild(renderFormField(key, params[key], true));
        });
    }

    if (optional.length > 0) {
        paramsContainer.appendChild(QSDom.el('h4', {
            class: 'admin-form-section-title',
            style: 'margin-top: var(--space-lg);',
            text: t('commands.optionalParams')
        }));
        optional.forEach(key => {
            paramsContainer.appendChild(renderFormField(key, params[key], false));
        });
    }
}

/**
 * Build ONE parameter's form group, as an element.
 *
 * Every value that reaches the DOM here — the parameter name, its example,
 * its description, its validation string — comes from help.php's spec through
 * the API. They are set as properties and text nodes, so the escaping that the
 * old string build depended on is no longer load-bearing.
 *
 * @param {string} rawName   the spec's key, possibly '{name}' or '{name?}'
 * @param {Object} param     the spec entry
 * @param {boolean} required
 * @returns {HTMLDivElement} ONE .admin-form-group
 */
function renderFormField(rawName, param, required) {
    const type = param.type || 'string';
    const uiType = param.ui_type || null; // Custom UI type for special inputs
    const description = param.description || '';
    const example = param.example || '';
    const validation = param.validation || '';

    // Detect URL parameters from curly braces in name (e.g., {lang}, {type}, {name?})
    const isUrlParam = rawName.startsWith('{') && rawName.endsWith('}');
    // Strip {} and optional ? marker: {name?} -> name
    let name = rawName;
    if (isUrlParam) {
        name = rawName.slice(1, -1); // Remove { and }
        if (name.endsWith('?')) {
            name = name.slice(0, -1); // Remove trailing ?
        }
    }
    const displayName = isUrlParam
        ? name + ' ' + t('commandForm.field.urlSegment')
        : name;

    const inputId = 'param-' + name;

    /** The attributes every field input shares. */
    function _fieldProps(extra) {
        const props = Object.assign({ name: name, id: inputId }, extra || {});
        if (required) props.required = 'required';
        if (isUrlParam) props['data-url-param'] = '';
        return props;
    }

    /** A two-digit 00..max option list, used by the hour and minute pickers. */
    function _renderTwoDigitSelect(id, dataAttr, count) {
        const props = { id: id, class: 'admin-select admin-select--small' };
        props[dataAttr] = inputId;
        const select = QSDom.el('select', props);
        for (let i = 0; i < count; i++) {
            const label = String(i).padStart(2, '0');
            const option = QSDom.el('option', { value: label, text: label });
            if (i === 0) option.selected = true;
            select.appendChild(option);
        }
        return select;
    }

    /** One of the two eye icons on the password toggle. */
    function _renderEye(marker, paths, hidden) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute(marker, '');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '20');
        svg.setAttribute('height', '20');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        if (hidden) svg.setAttribute('style', 'display: none;');
        paths.forEach(spec => {
            const shape = document.createElementNS(ns, spec.tag);
            Object.keys(spec.attrs).forEach(k => shape.setAttribute(k, spec.attrs[k]));
            svg.appendChild(shape);
        });
        return svg;
    }

    let inputArea = null;

    // Handle special UI types first
    if (uiType === 'datetime' || uiType === 'date') {
        const includeTime = (uiType === 'datetime');
        const today = new Date().toISOString().split('T')[0];
        const defaultExample = example || today;

        const dateProps = { type: 'date', id: inputId + '-date', class: 'admin-input admin-input--date', value: today };
        dateProps['data-datetime-date'] = inputId;

        const selectors = [
            QSDom.el('div', { class: 'admin-form-group admin-form-group--inline' }, [
                _renderLabel(t('commandForm.field.date'), 'admin-label admin-label--small'),
                QSDom.el('input', dateProps)
            ])
        ];
        if (includeTime) {
            selectors.push(QSDom.el('div', { class: 'admin-form-group admin-form-group--inline' }, [
                _renderLabel(t('commandForm.field.hour'), 'admin-label admin-label--small'),
                _renderTwoDigitSelect(inputId + '-hour', 'data-datetime-hour', 24)
            ]));
            selectors.push(QSDom.el('div', { class: 'admin-form-group admin-form-group--inline' }, [
                _renderLabel(t('commandForm.field.minute'), 'admin-label admin-label--small'),
                _renderTwoDigitSelect(inputId + '-min', 'data-datetime-min', 60)
            ]));
        }
        // A real listener, not an inline onclick attribute. An attribute is
        // compiled in GLOBAL scope, and applyDateTimeToField lives inside this
        // file's IIFE, so the attribute could never reach it — clicking Apply
        // threw a ReferenceError and did nothing. QSDom.el turns a FUNCTION
        // passed as `onclick` into an addEventListener, which closes over the
        // function properly.
        selectors.push(QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--small admin-btn--secondary',
            onclick: () => applyDateTimeToField(inputId, includeTime),
            text: t('commandForm.field.apply')
        }));

        inputArea = QSDom.el('div', { class: 'admin-datetime-picker', 'data-target': inputId }, [
            QSDom.el('div', { class: 'admin-datetime-picker__selectors' }, selectors),
            QSDom.el('div', { class: 'admin-form-group', style: 'margin-top: var(--space-sm);' }, [
                _renderLabel(t('commandForm.field.orEnterManually'), 'admin-label admin-label--small'),
                QSDom.el('input', _fieldProps({
                    type: 'text', class: 'admin-input', placeholder: String(defaultExample)
                }))
            ])
        ]);

    // Explicit ui_type overrides type heuristics
    } else if (uiType === 'textarea') {
        inputArea = QSDom.el('textarea', _fieldProps({
            class: 'admin-textarea', rows: '4', placeholder: String(example || '')
        }));
    } else if (uiType === 'text') {
        inputArea = QSDom.el('input', _fieldProps({
            type: 'text', class: 'admin-input', placeholder: String(example || '')
        }));
    } else if (uiType === 'password') {
        // Masked input + visibility toggle (login/register params declare
        // ui_type 'password' in help.php).
        const toggle = QSDom.el('button', {
            type: 'button',
            'aria-label': t('commandForm.field.showPassword'),
            title: t('commandForm.field.showPassword'),
            'data-password-toggle': inputId,
            style: 'position: absolute; top: 50%; right: 0.5rem; transform: translateY(-50%); '
                 + 'background: none; border: none; padding: 0.25rem; cursor: pointer; '
                 + 'color: inherit; opacity: 0.65; line-height: 0;'
        }, [
            _renderEye('data-eye', [
                { tag: 'path', attrs: { d: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z' } },
                { tag: 'circle', attrs: { cx: '12', cy: '12', r: '3' } }
            ], false),
            _renderEye('data-eye-off', [
                { tag: 'path', attrs: { d: 'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94' } },
                { tag: 'path', attrs: { d: 'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19' } },
                { tag: 'path', attrs: { d: 'M14.12 14.12a3 3 0 1 1-4.24-4.24' } },
                { tag: 'line', attrs: { x1: '1', y1: '1', x2: '23', y2: '23' } }
            ], true)
        ]);
        inputArea = QSDom.el('div', { style: 'position: relative;' }, [
            QSDom.el('input', _fieldProps({
                type: 'password', class: 'admin-input',
                style: 'padding-right: 2.75rem;', autocomplete: 'new-password'
            })),
            toggle
        ]);
    } else if (uiType === 'checkbox') {
        inputArea = QSDom.el('label', {
            class: 'admin-checkbox',
            style: 'display:flex;align-items:center;gap:0.5rem;cursor:pointer;'
        }, [
            QSDom.el('input', (function () {
                const props = { type: 'checkbox', name: name, id: inputId, value: 'true' };
                if (isUrlParam) props['data-url-param'] = '';
                return props;
            })()),
            QSDom.el('span', { text: description })
        ]);
    } else {
        switch (type) {
            case 'boolean': {
                const select = QSDom.el('select', (function () {
                    const props = { name: name, id: inputId, class: 'admin-select' };
                    if (isUrlParam) props['data-url-param'] = '';
                    return props;
                })());
                QSDom.setSelectPlaceholder(select, t('commandForm.field.selectPlaceholder'));
                QuickSiteAdmin.appendOptionsToSelect(select, [
                    { value: 'true', label: 'true' },
                    { value: 'false', label: 'false' }
                ]);
                inputArea = select;
                break;
            }

            case 'file': {
                const props = { type: 'file', name: name, id: inputId, class: 'admin-input' };
                if (isUrlParam) props['data-url-param'] = '';
                inputArea = QSDom.el('input', props);
                break;
            }

            case 'array':
            case 'object': {
                const placeholderValue = example
                    ? JSON.stringify(example, null, 2)
                    : (type === 'array' ? '[]' : '{}');
                const props = { name: name, id: inputId, class: 'admin-textarea', placeholder: placeholderValue };
                props['data-json-editor'] = '';
                if (isUrlParam) props['data-url-param'] = '';
                inputArea = QSDom.el('textarea', props, [type === 'array' ? '[]' : '{}']);
                break;
            }

            default: {
                // Check for select options in validation
                if (validation && validation.includes('|')) {
                    const options = validation.split(',')[0]?.split('|').map(o => o.trim()) || [];
                    if (options.length > 1) {
                        const select = QSDom.el('select', _fieldProps({ class: 'admin-select' }));
                        QSDom.setSelectPlaceholder(select, t('commandForm.field.selectPlaceholder'));
                        QuickSiteAdmin.appendOptionsToSelect(select,
                            options.map(opt => ({ value: opt, label: opt })));
                        inputArea = select;
                        break;
                    }
                }

                // Fields that should use textarea for better visibility
                const textareaFields = ['structure', 'content', 'data', 'json', 'value', 'keys', 'translations', 'variables', 'properties', 'rule', 'keyframes'];
                const nameLower = name.toLowerCase();
                const needsTextarea = textareaFields.some(f => nameLower.includes(f)) ||
                                      (example && typeof example === 'string' && (example.includes('{') || example.includes('[') || example.length > 50));

                // Fields that should have JSON editor
                const jsonEditorFields = ['structure', 'translations', 'keys', 'data', 'json', 'properties', 'variables'];
                const needsJsonEditor = jsonEditorFields.some(f => nameLower.includes(f));

                if (needsTextarea) {
                    const props = _fieldProps({
                        class: 'admin-textarea', rows: '6', placeholder: String(example || '')
                    });
                    if (needsJsonEditor) props['data-json-editor'] = '';
                    inputArea = QSDom.el('textarea', props);
                    break;
                }

                // Default text input
                inputArea = QSDom.el('input', _fieldProps({
                    type: 'text', class: 'admin-input', placeholder: String(example || '')
                }));
            }
        }
    }

    const group = QSDom.el('div', { class: 'admin-form-group' }, [
        QSDom.el('label', {
            class: 'admin-label' + (required ? ' admin-label--required' : ''),
            for: inputId
        }, [
            displayName + ' ',
            QSDom.el('span', { class: 'admin-label__type', text: '(' + type + ')' })
        ]),
        inputArea,
        _renderHint([description])
    ]);

    if (validation) {
        group.appendChild(_renderHint([
            QSDom.el('strong', { text: t('commandForm.field.validationLabel') }),
            ' ' + validation
        ]));
    }

    return group;
}

/**
 * ui_type 'password' visibility toggle — DELEGATED click handler.
 * Delegation, not an inline onclick attribute: this whole file is IIFE-scoped,
 * so an attribute compiled in global scope cannot reach its functions. The
 * fields themselves are built and inserted after the page loads, which is why
 * the listener sits on `document` rather than on each button.
 */
document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-password-toggle]');
    if (!btn) return;
    const input = document.getElementById(btn.getAttribute('data-password-toggle'));
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    const eye = btn.querySelector('[data-eye]');
    const eyeOff = btn.querySelector('[data-eye-off]');
    if (eye) eye.style.display = reveal ? 'none' : '';
    if (eyeOff) eyeOff.style.display = reveal ? '' : 'none';
    const label = t(reveal ? 'commandForm.field.hidePassword' : 'commandForm.field.showPassword');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
    input.focus();
});

function renderCommandDocs(doc) {
    const container = document.getElementById('command-docs');

    /** One titled block of the documentation panel. */
    function _renderDocSection(title, body) {
        return QSDom.el('div', { class: 'admin-doc-section' }, [
            QSDom.el('h4', { text: title }),
            body
        ]);
    }

    /** A preformatted code block. */
    function _renderCodeBlock(text) {
        return QSDom.el('div', { class: 'admin-code' }, [
            QSDom.el('pre', { text: text })
        ]);
    }

    const sections = [];

    // Notes
    if (doc.notes) {
        sections.push(_renderDocSection(t('commands.notes'),
            QSDom.el('p', { text: doc.notes })));
    }

    // Example
    if (doc.example_get || doc.example_post) {
        sections.push(_renderDocSection(t('commands.example'),
            _renderCodeBlock(doc.example_get || doc.example_post)));
    }

    // Success Response
    if (doc.success_response) {
        sections.push(_renderDocSection(t('commands.successResponse'),
            _renderCodeBlock(JSON.stringify(doc.success_response, null, 2))));
    }

    // Error Responses
    if (doc.error_responses && Object.keys(doc.error_responses).length > 0) {
        const list = QSDom.el('ul', { class: 'admin-error-list' });
        Object.entries(doc.error_responses).forEach(([code, message]) => {
            list.appendChild(QSDom.el('li', null, [
                QSDom.el('code', { text: code }),
                ': ' + message
            ]));
        });
        sections.push(_renderDocSection(t('commands.errorResponses'), list));
    }

    QSDom.clear(container);
    if (sections.length === 0) {
        container.appendChild(QSDom.el('p', { text: t('commandForm.noDocumentation') }));
        return;
    }
    sections.forEach(section => container.appendChild(section));
}

/**
 * Apply date/time picker values to the target input field
 * @param {string} fieldId - The ID of the target input field
 * @param {boolean} includeTime - Whether to include time in the value
 */
function applyDateTimeToField(fieldId, includeTime = true) {
    const dateInput = document.getElementById(fieldId + '-date');
    const targetInput = document.getElementById(fieldId);
    
    if (!dateInput || !targetInput) return;
    
    let value = dateInput.value;
    
    if (!value) {
        // Default to today
        value = new Date().toISOString().split('T')[0];
    }
    
    if (includeTime) {
        const hourSelect = document.getElementById(fieldId + '-hour');
        const minSelect = document.getElementById(fieldId + '-min');
        
        const hour = hourSelect ? hourSelect.value : '00';
        const min = minSelect ? minSelect.value : '00';
        
        value = `${value}T${hour}:${min}:00`;
    }
    
    targetInput.value = value;
    targetInput.focus();
    
    // Trigger change event for any listeners
    targetInput.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Initialize datetime pickers - set up auto-apply on change (optional)
 */
function initDateTimePickers() {
    document.querySelectorAll('.admin-datetime-picker').forEach(picker => {
        const targetId = picker.dataset.target;
        const dateInput = picker.querySelector('[data-datetime-date]');
        
        // Optional: auto-apply when date changes
        if (dateInput) {
            dateInput.addEventListener('change', () => {
                // Only auto-apply if user has interacted with date picker
                const targetInput = document.getElementById(targetId);
                if (targetInput && !targetInput.value) {
                    // Don't auto-apply if user hasn't clicked Apply yet
                }
            });
        }
    });
}

// Initialize datetime pickers (init() already ensures DOM is ready)
initDateTimePickers();
    } // end init()
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
