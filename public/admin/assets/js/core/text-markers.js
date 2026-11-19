/**
 * QSTextMarkers — formatting inside one translated sentence: the editor's side of the grammar whose engine side is
 * secure/src/functions/textMarkers.php (read the rule there). The same parts, the same pairing, read from the same
 * list: every function takes it (TagRegistry::editorPayload()'s TEXT_MARKERS, { name: 'pair' | 'void' }), and this
 * file holds no list of its own.
 *
 *   parse(word, markers)              the word's parts, as qs_text_markers_parse() gives them
 *   read(doc, word, markers, opts)    the word as nodes of `doc` (a DocumentFragment): each working marker an
 *                                     element, every word a text node. opts.editor: a marker kept by a backslash,
 *                                     or one that does not work, in a span saying which (data-qs-marker), as the
 *                                     renderer writes it in the editor's frame
 *   remove(word, markers)             the word where formatting cannot show, as qs_text_markers_remove() gives it
 *   write(container, markers)         the sentence an element holds, written back as one word: each listed
 *                                     element its markers, a line break `<br>`, a span the browser styled bold,
 *                                     italic or underlined the matching marker, any other element its words alone,
 *                                     and a marker-shaped text typed as text written with its backslash. A sentence
 *                                     read in the editor and not changed is written back byte for byte.
 *
 * Loaded by the editor page (preview-config.php) and, by preview.js, into the editor's frame. Nothing here writes
 * markup from a string.
 */
(function (root) {
    'use strict';

    var LETTERS = /^[a-z]+/;

    function kindOf(markers, name) {
        return Object.prototype.hasOwnProperty.call(markers || {}, name) ? markers[name] : null;
    }

    /** The listed marker written at pos, as { text, name, kind: open|close|void }, or null. */
    function markerAt(word, pos, markers) {
        if (word.charAt(pos) !== '<') return null;
        var closing = word.charAt(pos + 1) === '/';
        var from = pos + (closing ? 2 : 1);
        var m = LETTERS.exec(word.slice(from));
        if (!m || word.charAt(from + m[0].length) !== '>') return null;
        var name = m[0];
        var kind = kindOf(markers, name);
        if (kind === null || (closing && kind !== 'pair')) return null;
        return { text: word.slice(pos, from + name.length + 1), name: name, kind: closing ? 'close' : (kind === 'void' ? 'void' : 'open') };
    }

    function backslashRun(word, pos) {
        var k = 0;
        while (word.charAt(pos + k) === '\\') k++;
        return k;
    }

    /** The word's parts: [kind, value] with kind text | open | close | void | kept | loose (textMarkers.php). */
    function parse(word, markers) {
        word = String(word);
        var raw = [];
        var text = '';
        var pos = 0;
        function flushText() { if (text !== '') { raw.push(['text', text]); text = ''; } }
        while (pos < word.length) {
            var c = word.charAt(pos);
            if (c === '\\') {
                var k = backslashRun(word, pos);
                var after = markerAt(word, pos + k, markers);
                if (after === null) {
                    text += word.slice(pos, pos + k);
                    pos += k;
                    continue;
                }
                text += new Array(Math.floor(k / 2) + 1).join('\\');
                flushText();
                raw.push(k % 2 === 1 ? ['kept', after.text] : ['cand', after.text, after.name, after.kind]);
                pos += k + after.text.length;
                continue;
            }
            if (c === '<') {
                var marker = markerAt(word, pos, markers);
                if (marker !== null) {
                    flushText();
                    raw.push(['cand', marker.text, marker.name, marker.kind]);
                    pos += marker.text.length;
                    continue;
                }
            }
            var next = pos + 1;
            while (next < word.length && word.charAt(next) !== '\\' && word.charAt(next) !== '<') next++;
            text += word.slice(pos, next);
            pos = next;
        }
        flushText();

        // The pairs: a closing marker closes the last marker opened and still open, when it is the same one.
        var stack = [];
        var role = {};
        raw.forEach(function (part, i) {
            if (part[0] !== 'cand') return;
            if (part[3] === 'void') { role[i] = 'void'; return; }
            if (part[3] === 'open') { stack.push(i); return; }
            if (stack.length && raw[stack[stack.length - 1]][2] === part[2]) {
                role[stack.pop()] = 'open';
                role[i] = 'close';
            }
        });

        var parts = [];
        raw.forEach(function (part, i) {
            if (part[0] === 'cand') part = role[i] ? [role[i], part[2]] : ['loose', part[1]];
            if (part[0] === 'text' && parts.length && parts[parts.length - 1][0] === 'text') {
                parts[parts.length - 1][1] += part[1];
                return;
            }
            parts.push(part);
        });
        return parts;
    }

    /** One marker kept as text, as the editor's frame shows it: a span saying which, holding the marker's text. */
    function _renderMarkerText(doc, kind, text) {
        var span = doc.createElement('span');
        span.setAttribute('data-qs-marker', kind);
        span.textContent = text;
        return span;
    }

    function read(doc, word, markers, opts) {
        var editor = !!(opts && opts.editor);
        var frag = doc.createDocumentFragment();
        var open = [frag];
        function here() { return open[open.length - 1]; }
        // Words next to each other are one text node, as a browser parses the renderer's HTML.
        function addText(text) {
            var last = here().lastChild;
            if (last && last.nodeType === 3) last.data += text;
            else here().appendChild(doc.createTextNode(text));
        }
        parse(word, markers).forEach(function (part) {
            switch (part[0]) {
                case 'text':
                    addText(part[1]);
                    break;
                case 'kept':
                case 'loose':
                    if (editor) here().appendChild(_renderMarkerText(doc, part[0], part[1]));
                    else addText(part[1]);
                    break;
                case 'open':
                    var el = doc.createElement(part[1]);
                    here().appendChild(el);
                    open.push(el);
                    break;
                case 'close':
                    open.pop();
                    break;
                case 'void':
                    here().appendChild(doc.createElement(part[1]));
                    break;
            }
        });
        return frag;
    }

    var ASCII_SPACE = /^[ \t\n\r\v\f]$/;

    function remove(word, markers) {
        var out = '';
        var space = false;
        parse(word, markers).forEach(function (part) {
            if (part[0] === 'void') {
                space = out !== '' && !ASCII_SPACE.test(out.charAt(out.length - 1));
                return;
            }
            if (part[0] === 'open' || part[0] === 'close') return;
            var text = part[1];
            if (space && text !== '' && !ASCII_SPACE.test(text.charAt(0))) out += ' ';
            space = false;
            out += text;
        });
        return out;
    }

    // ── writing a sentence back ──────────────────────────────────────────────────────────────────────

    /** Whether a text is exactly one listed marker, as written. */
    function isWholeMarker(text, markers) {
        var m = markerAt(text, 0, markers);
        return m !== null && m.text === text;
    }

    /** The markers a styled element stands for (a browser's bold may be a styled span): bold, italic, underline. */
    function styledMarkers(el, markers) {
        var out = [];
        var style = el.style;
        if (!style) return out;
        var weight = String(style.fontWeight || '');
        if ((weight === 'bold' || weight === 'bolder' || Number(weight) >= 600) && kindOf(markers, 'b') === 'pair') out.push('b');
        var fontStyle = String(style.fontStyle || '');
        if ((fontStyle === 'italic' || fontStyle.indexOf('oblique') === 0) && kindOf(markers, 'i') === 'pair') out.push('i');
        var line = String(style.textDecorationLine || style.textDecoration || '');
        if (/\bunderline\b/.test(line) && kindOf(markers, 'u') === 'pair') out.push('u');
        return out;
    }

    /** The items an element's children write: text, open, close, void, kept, loose. */
    function flatten(node, markers, items) {
        Array.prototype.forEach.call(node.childNodes, function (child) {
            if (child.nodeType === 3) {
                items.push({ t: 'text', s: child.data });
                return;
            }
            if (child.nodeType !== 1) return;
            var name = String(child.localName || child.nodeName).toLowerCase();
            var mark = child.getAttribute('data-qs-marker');
            if ((mark === 'kept' || mark === 'loose') && isWholeMarker(child.textContent, markers)) {
                items.push({ t: mark, s: child.textContent });
                return;
            }
            var kind = mark ? null : kindOf(markers, name);
            if (kind === 'void') {
                items.push({ t: 'void', name: name });
                return;
            }
            var wrap = kind === 'pair' ? [name] : styledMarkers(child, markers);
            wrap.forEach(function (n) { items.push({ t: 'open', name: n }); });
            flatten(child, markers, items);
            wrap.slice().reverse().forEach(function (n) { items.push({ t: 'close', name: n }); });
        });
        return items;
    }

    /** The items with consecutive text joined, and every marker-shaped text inside a text split out as typed. */
    function settle(items, markers) {
        var joined = [];
        items.forEach(function (item) {
            if (item.t === 'text' && joined.length && joined[joined.length - 1].t === 'text') {
                joined[joined.length - 1].s += item.s;
            } else {
                joined.push(item.t === 'text' ? { t: 'text', s: item.s } : item);
            }
        });
        var out = [];
        joined.forEach(function (item) {
            if (item.t !== 'text') { out.push(item); return; }
            var s = item.s;
            var from = 0;
            for (var pos = 0; pos < s.length; pos++) {
                var m = markerAt(s, pos, markers);
                if (m === null) continue;
                if (pos > from) out.push({ t: 'text', s: s.slice(from, pos) });
                out.push({ t: 'typed', s: m.text });
                pos += m.text.length - 1;
                from = pos + 1;
            }
            if (from < s.length) out.push({ t: 'text', s: s.slice(from) });
        });
        return out;
    }

    /** The string the items write, with each loose marker in `escaped` written with a backslash. */
    function render(items, escaped) {
        var out = '';
        var expect = [];
        items.forEach(function (item, i) {
            if (item.t === 'text') {
                var s = item.s;
                var nextItem = items[i + 1];
                if (nextItem && nextItem.t !== 'text') {
                    // Backslashes right before a marker count in pairs: each written one is doubled.
                    var k = 0;
                    while (k < s.length && s.charAt(s.length - 1 - k) === '\\') k++;
                    if (k) s = s + new Array(k + 1).join('\\');
                }
                out += s;
                return;
            }
            if (item.t === 'open') { out += '<' + item.name + '>'; expect.push('open:' + item.name); return; }
            if (item.t === 'close') { out += '</' + item.name + '>'; expect.push('close:' + item.name); return; }
            if (item.t === 'void') { out += '<' + item.name + '>'; expect.push('void:' + item.name); return; }
            if (item.t === 'loose' && !escaped[i]) { out += item.s; expect.push('loose:' + item.s); return; }
            out += '\\' + item.s;
            expect.push('kept:' + item.s);
        });
        return { word: out, expect: expect };
    }

    function write(container, markers) {
        var items = settle(flatten(container, markers, []), markers);
        var escaped = {};
        for (var attempt = 0; attempt < items.length + 2; attempt++) {
            var r = render(items, escaped);
            var seen = parse(r.word, markers).filter(function (p) { return p[0] !== 'text'; })
                .map(function (p) { return p[0] + ':' + p[1]; });
            var wrong = [];
            var j = 0;
            items.forEach(function (item, i) {
                if (item.t === 'text') return;
                if (seen[j] !== r.expect[j] && item.t === 'loose' && !escaped[i]) wrong.push(i);
                j++;
            });
            if (seen.join('\n') === r.expect.join('\n')) return r.word;
            // A marker left as written now closes or opens something: it is written with its backslash. When the
            // difference lies elsewhere, every such marker is.
            if (!wrong.length) {
                items.forEach(function (item, i) { if (item.t === 'loose') wrong.push(i); });
            }
            wrong.forEach(function (i) { escaped[i] = true; });
        }
        return render(items, escaped).word;
    }

    root.QSTextMarkers = { parse: parse, read: read, remove: remove, write: write };
})(typeof window !== 'undefined' ? window : globalThis);
