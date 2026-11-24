/**
 * The complex wizards' words (Add Element → Complex tab).
 *
 *   QSComplexWizard.t(key, params)
 *     A word of the `complexWizard` section, `complexWizard.<key>`, read through
 *     the panel's one lookup (QSPanelWords): a word no file holds shows its path,
 *     and `{name}` placeholders take `params[name]`. The editor hands the whole
 *     section; the APIs page hands `complexWizard.storageKeyPicker`, all its
 *     storage-key picker reads.
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
        return window.QSPanelWords.t('complexWizard.' + key, params);
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
