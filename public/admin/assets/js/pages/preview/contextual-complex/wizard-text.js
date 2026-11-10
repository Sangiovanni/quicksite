/**
 * The complex wizards' words (Add Element → Complex tab).
 *
 *   QSComplexWizard.t(key, params)
 *     The `complexWizard` translations preview-config.php emits as
 *     window.QS_COMPLEX_WIZARD_I18N. A key the file lacks shows as its own path
 *     (`complexWizard.<key>`), so a missing one is seen rather than hidden.
 *     `{name}` placeholders take `params[name]`.
 *
 *   QSComplexWizard.codeText(text, codes)
 *     A sentence whose `{name}` markers are code: each becomes a <code> element
 *     holding `codes[name]`, the rest stays text. Returns the parts, ready for
 *     QSDom.el's children — a hint can name an HTML attribute or a call without
 *     markup in its translation.
 *
 *   QSComplexWizard.requiredMark()
 *     The red asterisk a required field's label carries.
 *
 * Loaded by preview-config.php before every other wizard file.
 */
(function () {
    'use strict';

    function t(key, params) {
        const tree = (window.QS_COMPLEX_WIZARD_I18N && window.QS_COMPLEX_WIZARD_I18N.complexWizard) || {};
        let value = String(key).split('.').reduce(function (node, part) {
            return node && typeof node === 'object' ? node[part] : undefined;
        }, tree);
        if (typeof value !== 'string') return 'complexWizard.' + key;
        if (params) {
            Object.keys(params).forEach(function (name) {
                value = value.split('{' + name + '}').join(String(params[name]));
            });
        }
        return value;
    }

    function codeText(text, codes) {
        const parts = [];
        String(text).split(/(\{[A-Za-z0-9_]+\})/).forEach(function (piece) {
            const m = /^\{([A-Za-z0-9_]+)\}$/.exec(piece);
            if (m && codes && Object.prototype.hasOwnProperty.call(codes, m[1])) {
                parts.push(QSDom.el('code', { text: codes[m[1]] }));
            } else if (piece !== '') {
                parts.push(piece);
            }
        });
        return parts;
    }

    /** The red asterisk a required field's label carries. */
    function requiredMark() {
        return QSDom.el('span', { text: '*' });
    }

    window.QSComplexWizard = window.QSComplexWizard || {};
    window.QSComplexWizard.t = t;
    window.QSComplexWizard.codeText = codeText;
    window.QSComplexWizard.requiredMark = requiredMark;
})();
