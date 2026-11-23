/**
 * QuickSite Admin — a srcset read and written (QSSrcset).
 *
 * A `srcset` lists the files of one picture, each with a descriptor: a density (`2x`) or a width (`480w`); a browser
 * loads one of them. The panel splits it here exactly as the engine does, by the HTML standard's "parse a srcset
 * attribute" steps: UrlPolicy::srcsetAddresses() on the server and qs.js _qsSrcsetAddresses() in the browser are its
 * two copies, and the three change together (MAINTAINING.md). The rules they share:
 *   - white space is the standard's ASCII set alone (space, tab, line feed, form feed, carriage return);
 *   - skip white space and commas; the address runs to the next white space, so it may hold a comma, but a comma
 *     ENDING it ends the file (every such comma removed) and no descriptor follows;
 *   - otherwise the descriptors follow, up to a comma outside parentheses: an open parenthesis holds until the next
 *     closing one (a flag, not a depth).
 *
 * On top of the split, what the editor's srcset field needs, none of it touching the page:
 *   - each file's descriptors read as the standard reads them, and the file a browser would skip;
 *   - whether rows can hold a value (one kind of descriptor for the whole list, no duplicate, a density the slider
 *     can set), and the first reason they cannot;
 *   - rows written back into a line, sorted by their descriptor, and each row's problem.
 * A value the person did not change is never rewritten: the field compares what rows would write with what the
 * value's own rows write, and keeps the value as it was written when they match.
 */
window.QSSrcset = (function () {
    'use strict';

    var WS = ' \t\n\f\r';
    /** One of the standard's ASCII white space characters (an empty string, past the end, is none). */
    function isWs(c) { return c !== '' && WS.indexOf(c) !== -1; }

    /** The densities the field's slider can set: 1x to 4x by 0.5. */
    var DENSITY_MIN = 1;
    var DENSITY_MAX = 4;
    var DENSITY_STEP = 0.5;

    /** A picture's format by its file's extension, when Media holds none: the formats a <source>'s type names. */
    var FORMAT_BY_EXTENSION = {
        avif: 'image/avif',
        webp: 'image/webp',
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        png: 'image/png',
        gif: 'image/gif',
        svg: 'image/svg+xml'
    };

    /**
     * A candidate's descriptors, as the standard's descriptor tokeniser cuts them: tokens apart by white space, a
     * comma outside parentheses ending them, a parenthesis holding white space and commas inside one token.
     */
    function tokens(text) {
        var out = [];
        var current = '';
        var state = 'in';
        for (var i = 0; i <= text.length; i++) {
            var c = i < text.length ? text.charAt(i) : '';
            var end = i === text.length;
            if (state === 'in') {
                if (end) { if (current) out.push(current); break; }
                if (isWs(c)) {
                    if (current) { out.push(current); current = ''; }
                    state = 'after';
                } else {
                    current += c;
                    if (c === '(') state = 'parens';
                }
            } else if (state === 'parens') {
                if (end) { out.push(current); break; }
                current += c;
                if (c === ')') state = 'in';
            } else if (!isWs(c)) {
                if (end) break;
                state = 'in';
                i--;
            }
        }
        return out;
    }

    /**
     * Every file of a srcset in order, as the twins split it: {at, length, address} (where the address sits, in
     * UTF-16 units), and {descriptorText} (what follows the address up to the comma ending the file, white space
     * trimmed) with {descriptors} (its tokens). A file whose address ended with a comma has none.
     */
    function candidates(value) {
        var s = String(value == null ? '' : value);
        var n = s.length;
        var i = 0;
        var out = [];
        for (;;) {
            while (i < n && (isWs(s.charAt(i)) || s.charAt(i) === ',')) i++;
            if (i >= n) return out;
            var start = i;
            while (i < n && !isWs(s.charAt(i))) i++;
            var end = i;
            if (s.charAt(end - 1) === ',') {
                while (s.charAt(end - 1) === ',') end--;
                out.push({ at: start, length: end - start, address: s.slice(start, end), descriptorText: '', descriptors: [] });
                continue;
            }
            var from = i;
            var inParens = false;
            var stop = n;
            for (; i < n; i++) {
                var c = s.charAt(i);
                if (inParens) inParens = c !== ')';
                else if (c === '(') inParens = true;
                else if (c === ',') { stop = i; i++; break; }
            }
            var text = s.slice(from, stop).replace(/^[ \t\n\f\r]+|[ \t\n\f\r]+$/g, '');
            out.push({ at: start, length: end - start, address: s.slice(start, end), descriptorText: text, descriptors: tokens(text) });
        }
    }

    /** Where each file's address sits, as [offset, length] pairs: the twins' own answer. */
    function addresses(value) {
        return candidates(value).map(function (c) { return [c.at, c.length]; });
    }

    var NON_NEGATIVE_INTEGER = /^[0-9]+$/;
    var FLOATING_POINT = /^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/;

    /**
     * A candidate's descriptors read as the standard reads them: {width}, {density}, {height} (a "future-compat"
     * height), and {skipped: true} when a browser would drop the file (a descriptor that is no width, density or
     * height, two of a kind, a width beside a density, a zero, a height with no width).
     */
    function descriptorsOf(list) {
        var r = { width: null, density: null, height: null, skipped: false };
        list.forEach(function (d) {
            if (r.skipped) return;
            var last = d.charAt(d.length - 1);
            var head = d.slice(0, -1);
            if (last === 'w') {
                if (r.width !== null || r.density !== null || !NON_NEGATIVE_INTEGER.test(head) || Number(head) === 0) r.skipped = true;
                else r.width = Number(head);
            } else if (last === 'x') {
                if (r.width !== null || r.density !== null || r.height !== null || !FLOATING_POINT.test(head) || Number(head) < 0) r.skipped = true;
                else r.density = Number(head);
            } else if (last === 'h') {
                if (r.height !== null || r.density !== null || !NON_NEGATIVE_INTEGER.test(head) || Number(head) === 0) r.skipped = true;
                else r.height = Number(head);
            } else {
                r.skipped = true;
            }
        });
        if (!r.skipped && r.height !== null && r.width === null) r.skipped = true;
        return r;
    }

    /** Whether the slider can set a density: 1 to 4 by 0.5. */
    function onSlider(density) {
        return density >= DENSITY_MIN && density <= DENSITY_MAX && Math.abs(density / DENSITY_STEP - Math.round(density / DENSITY_STEP)) < 1e-9;
    }

    /** A density as the line writes it: 1, 1.5, 2… */
    function densityText(d) {
        return String(Math.round(d * 1000) / 1000);
    }

    /** A row's descriptor in the line's words: `480w` or `2x`; '' when the row has none to write. */
    function descriptorText(kind, row) {
        if (kind === 'width') return isWidth(row.width) ? String(row.width) + 'w' : '';
        return typeof row.density === 'number' && isFinite(row.density) ? densityText(row.density) + 'x' : '';
    }

    function isWidth(w) { return typeof w === 'number' && isFinite(w) && w > 0 && Math.floor(w) === w; }

    /** A candidate as written, for a reason's words: its address and descriptors. */
    function candidateText(c) {
        return c.descriptorText ? c.address + ' ' + c.descriptorText : c.address;
    }

    /**
     * A value read into the field's rows: {ok: true, kind: 'width' | 'density' | '', rows: [{address, width | density}]},
     * or {ok: false, fault: true|false, code, candidate, descriptor} with the first reason rows cannot hold it.
     * A fault is what a browser reads wrongly (a file it skips, a mixed list, two files with one descriptor); the rest
     * is valid HTML beyond the rows (a density off the slider, a height). An empty value is no rows, of no kind.
     */
    function read(value) {
        var list = candidates(value);
        var parsed = list.map(function (c) { return { c: c, d: descriptorsOf(c.descriptors) }; });
        var skipped = parsed.filter(function (x) { return x.d.skipped; })[0];
        if (skipped) return { ok: false, fault: true, code: 'skipped', candidate: candidateText(skipped.c) };
        var widths = parsed.filter(function (x) { return x.d.width !== null; });
        if (widths.length && widths.length !== parsed.length) return { ok: false, fault: true, code: 'mixed' };
        var kind = !parsed.length ? '' : (widths.length ? 'width' : 'density');
        var seen = {};
        for (var k = 0; k < parsed.length; k++) {
            var d = kind === 'width' ? parsed[k].d.width : (parsed[k].d.density === null ? 1 : parsed[k].d.density);
            if (seen[d]) return { ok: false, fault: true, code: 'duplicate', descriptor: kind === 'width' ? d + 'w' : densityText(d) + 'x' };
            seen[d] = true;
        }
        var tall = parsed.filter(function (x) { return x.d.height !== null; })[0];
        if (tall) return { ok: false, fault: false, code: 'height', candidate: candidateText(tall.c) };
        if (kind === 'density') {
            var off = parsed.filter(function (x) { return !onSlider(x.d.density === null ? 1 : x.d.density); })[0];
            if (off) return { ok: false, fault: false, code: 'density', descriptor: densityText(off.d.density) + 'x' };
        }
        return {
            ok: true,
            kind: kind,
            rows: parsed.map(function (x) {
                return kind === 'width'
                    ? { address: x.c.address, width: x.d.width }
                    : { address: x.c.address, density: x.d.density === null ? 1 : x.d.density };
            })
        };
    }

    /** The rows that write something: an address given. */
    function filled(rows) {
        return (rows || []).filter(function (r) { return r && String(r.address || '') !== ''; });
    }

    /** The rows sorted by their descriptor, smallest first; a row with none to write last; the order kept between equals. */
    function sorted(kind, rows) {
        var key = function (r) {
            if (kind === 'width') return isWidth(r.width) ? r.width : Infinity;
            return typeof r.density === 'number' && isFinite(r.density) ? r.density : Infinity;
        };
        return (rows || []).map(function (r, i) { return { r: r, i: i }; })
            .sort(function (a, b) { return key(a.r) - key(b.r) || a.i - b.i; })
            .map(function (x) { return x.r; });
    }

    /** The line the rows write: each filled row's address and descriptor, sorted, a comma and a space between. */
    function write(kind, rows) {
        return sorted(kind, filled(rows)).map(function (r) {
            var d = descriptorText(kind, r);
            return d ? r.address + ' ' + d : r.address;
        }).join(', ');
    }

    /**
     * Each row's problem, or null: {rows: [code | null], first: {index, code, descriptor} | null}. A row with no
     * address writes nothing and has none. The codes: 'space' (an address holding white space), 'comma' (an
     * address starting or ending with a comma, which the split would cut), 'width' (no width in widths mode),
     * 'duplicate' (another row has the same descriptor).
     */
    function check(kind, rows) {
        var list = rows || [];
        var codes = list.map(function () { return null; });
        var byDescriptor = {};
        list.forEach(function (r, i) {
            var address = String((r && r.address) || '');
            if (address === '') return;
            if (/[ \t\n\f\r]/.test(address)) { codes[i] = 'space'; return; }
            if (address.charAt(0) === ',' || address.charAt(address.length - 1) === ',') { codes[i] = 'comma'; return; }
            if (kind === 'width' && !isWidth(r.width)) { codes[i] = 'width'; return; }
            var d = descriptorText(kind, r);
            (byDescriptor[d] = byDescriptor[d] || []).push(i);
        });
        Object.keys(byDescriptor).forEach(function (d) {
            if (byDescriptor[d].length > 1) byDescriptor[d].forEach(function (i) { codes[i] = 'duplicate'; });
        });
        var first = null;
        codes.forEach(function (code, i) {
            if (code && !first) first = { index: i, code: code, descriptor: code === 'duplicate' ? descriptorText(kind, list[i]) : '' };
        });
        return { rows: codes, first: first };
    }

    /** A picture's format: Media's stored one when it is a picture's, else the address's extension; '' when unknown. */
    function formatOf(address, mime) {
        if (typeof mime === 'string' && mime.indexOf('image/') === 0) return mime;
        var path = String(address || '').split(/[?#]/)[0];
        var dot = path.lastIndexOf('.');
        if (dot === -1 || dot < path.lastIndexOf('/')) return '';
        return FORMAT_BY_EXTENSION[path.slice(dot + 1).toLowerCase()] || '';
    }

    /** The formats a <source>'s type may name, in the order the field offers them. */
    function formats() {
        var out = [];
        Object.keys(FORMAT_BY_EXTENSION).forEach(function (ext) {
            if (out.indexOf(FORMAT_BY_EXTENSION[ext]) === -1) out.push(FORMAT_BY_EXTENSION[ext]);
        });
        return out;
    }

    /**
     * Densities for rows that had widths, on a switch to densities: each width over the smallest, to the slider's
     * nearest step (1x to 4x); a row with no width takes the first density no other row has.
     */
    function densitiesFor(widths) {
        var known = widths.filter(isWidth);
        var least = known.length ? Math.min.apply(null, known) : 0;
        var taken = {};
        var out = widths.map(function (w) {
            if (!isWidth(w)) return null;
            var d = Math.round((w / least) / DENSITY_STEP) * DENSITY_STEP;
            d = Math.min(DENSITY_MAX, Math.max(DENSITY_MIN, d));
            taken[d] = true;
            return d;
        });
        return out.map(function (d) {
            if (d !== null) return d;
            for (var v = DENSITY_MIN; v <= DENSITY_MAX; v += DENSITY_STEP) {
                if (!taken[v]) { taken[v] = true; return v; }
            }
            return DENSITY_MAX;
        });
    }

    /** The first density no row has, for a new row: 1x, then 2x, 3x, 4x, then the half steps. */
    function nextDensity(rows) {
        var used = (rows || []).map(function (r) { return r.density; });
        var order = [1, 2, 3, 4, 1.5, 2.5, 3.5];
        for (var k = 0; k < order.length; k++) if (used.indexOf(order[k]) === -1) return order[k];
        return DENSITY_MIN;
    }

    return {
        DENSITY_MIN: DENSITY_MIN,
        DENSITY_MAX: DENSITY_MAX,
        DENSITY_STEP: DENSITY_STEP,
        candidates: candidates,
        addresses: addresses,
        descriptorsOf: descriptorsOf,
        read: read,
        write: write,
        sorted: sorted,
        check: check,
        descriptorText: descriptorText,
        formatOf: formatOf,
        formats: formats,
        densitiesFor: densitiesFor,
        nextDensity: nextDensity
    };
})();
