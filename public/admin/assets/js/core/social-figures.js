/**
 * QuickSite Admin — a shared link's texts judged by the platform table.
 *
 * The table is getSocialPreview's `platforms` (the engine's QS_SOCIAL_PLATFORMS): every figure used here is read
 * from it, never written here. A length is in the figure's `unit`: characters (Unicode code points, as the engine's
 * mb_strlen counts them) or bytes (UTF-8, as the engine's strlen counts them, Discord's unit). A figure a platform
 * does not publish (value null) judges nothing and cuts nothing.
 *
 * Read by the Social previews page (pages/social-previews.js: each card's cut) and by the editor's Description field
 * (preview.js: its length warning while it is typed). They agree with the engine's own warnings,
 * qs_social_warnings(): a text is past a platform's length when its length is greater than the figure.
 *
 *   QSSocialFigures.cardRows(platforms, 'large')        the [id, row] pairs that draw this page's card, in the table's order
 *   QSSocialFigures.length('été', 'bytes')              5
 *   QSSocialFigures.cut(text, row.title)                {text, cut}: the text as the platform shows it, '…' where it cut
 *   QSSocialFigures.textWarnings(text, 'description', platforms, cardType)
 *                                                       [{platform, name, limit, actual, unit}]: each platform the text passes
 */
window.QSSocialFigures = (function () {
    'use strict';

    var encoder = typeof TextEncoder === 'function' ? new TextEncoder() : null;

    /** A text's length in a unit: 'bytes' (UTF-8) or characters (code points). */
    function length(text, unit) {
        var s = String(text == null ? '' : text);
        if (unit === 'bytes' && encoder) return encoder.encode(s).length;
        return Array.from(s).length;
    }

    /** A figure's limit, or null when the platform does not publish one. */
    function limitOf(figure) {
        return figure && figure.published && typeof figure.value === 'number' ? figure.value : null;
    }

    /**
     * The text as a platform shows it: whole when it fits or when no length is published; else cut to the
     * figure in its unit, with '…' after.
     */
    function cut(text, figure) {
        var s = String(text == null ? '' : text);
        var max = limitOf(figure);
        var unit = figure && figure.unit === 'bytes' ? 'bytes' : 'characters';
        if (max === null || length(s, unit) <= max) return { text: s, cut: false };
        var out = '';
        var used = 0;
        var chars = Array.from(s);
        for (var i = 0; i < chars.length; i++) {
            var add = length(chars[i], unit);
            if (used + add > max) break;
            out += chars[i];
            used += add;
        }
        return { text: out.replace(/\s+$/, '') + '…', cut: true };
    }

    /**
     * The table's rows that draw this page's card: X by the card type the page asks for, a platform that draws no
     * link card (Instagram) left out. In the table's order.
     */
    function cardRows(platforms, cardType) {
        var out = [];
        Object.keys(platforms || {}).forEach(function (id) {
            var row = platforms[id];
            if (!row || row.drawsCard === false) return;
            if (row.card && row.card !== cardType) return;
            out.push([id, row]);
        });
        return out;
    }

    /** Each platform drawing this card whose `what` ('title' or 'description') length the text passes. */
    function textWarnings(text, what, platforms, cardType) {
        var out = [];
        cardRows(platforms, cardType).forEach(function (pair) {
            var figure = pair[1][what];
            var max = limitOf(figure);
            if (max === null) return;
            var unit = figure.unit === 'bytes' ? 'bytes' : 'characters';
            var actual = length(text, unit);
            if (actual > max) out.push({ platform: pair[0], name: pair[1].name, limit: max, actual: actual, unit: unit });
        });
        return out;
    }

    return Object.freeze({ length: length, cut: cut, cardRows: cardRows, textWarnings: textWarnings });
})();
