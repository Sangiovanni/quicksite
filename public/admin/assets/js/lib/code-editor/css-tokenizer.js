/**
 * QSCodeEditor — CSS tokenizer.
 *
 * Registers as `QSCodeEditor.tokenizers.css`. Returns an HTML string with
 * `qs-tk-*` spans wrapping tokens.
 *
 * Tracks a small context stack so selectors inside @media (and other
 * block at-rules) are styled as selectors, not properties. Recognised
 * token classes: comment, string, atrule, selector, property, number,
 * var, punct.
 *
 * This is a tokenizer, not a CSS parser — invalid CSS will still render,
 * just with some tokens classified loosely. That matches the lightened
 * A3 scope: the iframe is the validity feedback, not the editor.
 */
(function () {
    'use strict';

    if (!window.QSCodeEditor) {
        // code-editor.js must load first.
        return;
    }

    var escapeHtml = window.QSCodeEditor.escapeHtml;

    function tokenizeCss(text) {
        var out = [];
        var len = text.length;
        var i = 0;
        // Frame stack: 'rule' (declarations expected) or 'atrule' (more
        // selectors/at-rules expected, like inside @media body).
        var frames = [];

        function inRuleBody() {
            return frames.length > 0 && frames[frames.length - 1] === 'rule';
        }
        function emit(cls, str) {
            out.push('<span class="qs-tk-' + cls + '">' + escapeHtml(str) + '</span>');
        }
        function plain(str) {
            out.push(escapeHtml(str));
        }

        while (i < len) {
            var ch = text[i];

            // ── Comment ──
            if (ch === '/' && text[i + 1] === '*') {
                var ce = text.indexOf('*/', i + 2);
                var cstop = (ce === -1) ? len : ce + 2;
                emit('comment', text.slice(i, cstop));
                i = cstop;
                continue;
            }

            // ── String ──
            if (ch === '"' || ch === "'") {
                var sStop = readString(text, i, len);
                emit('string', text.slice(i, sStop));
                i = sStop;
                continue;
            }

            // ── @-rule (anywhere except inside a rule body) ──
            if (ch === '@' && !inRuleBody()) {
                var aend = i + 1;
                while (aend < len && /[\w-]/.test(text[aend])) aend++;
                emit('atrule', text.slice(i, aend));
                i = aend;
                // The prelude (between the @keyword and `{` or `;`) reads as
                // plain text — bail to the main loop and let the brace /
                // semicolon handlers below take it from there.
                continue;
            }

            // ── Braces ──
            if (ch === '{') {
                emit('punct', '{');
                // Decide what kind of frame we just opened. Walk backwards
                // through `out` until we find the most recent atrule emit
                // since the previous open brace — if found and no rule-style
                // selector text intervenes, this is an at-rule body.
                frames.push(detectFrameKind(out));
                i++;
                continue;
            }
            if (ch === '}') {
                emit('punct', '}');
                frames.pop();
                i++;
                continue;
            }
            if (ch === ';') {
                emit('punct', ';');
                i++;
                continue;
            }

            // ── Rule body: property : value ; ──
            if (inRuleBody()) {
                // Read a property name up to ':' (bail on ; } { /* " ' )
                var pend = i;
                while (pend < len) {
                    var pc = text[pend];
                    if (pc === ':' || pc === ';' || pc === '{' || pc === '}') break;
                    if (pc === '/' && text[pend + 1] === '*') break;
                    if (pc === '"' || pc === "'") break;
                    pend++;
                }
                var pslice = text.slice(i, pend);
                if (text[pend] === ':') {
                    var pm = pslice.match(/^(\s*)(.*?)(\s*)$/);
                    if (pm) {
                        if (pm[1]) plain(pm[1]);
                        if (pm[2]) emit('property', pm[2]);
                        if (pm[3]) plain(pm[3]);
                    } else {
                        plain(pslice);
                    }
                    i = pend;
                    emit('punct', ':');
                    i++;
                    // Read the value up to ';' or '}' (skipping strings + comments)
                    var vend = i;
                    while (vend < len && text[vend] !== ';' && text[vend] !== '}') {
                        if (text[vend] === '/' && text[vend + 1] === '*') {
                            var vce = text.indexOf('*/', vend + 2);
                            vend = (vce === -1) ? len : vce + 2;
                            continue;
                        }
                        if (text[vend] === '"' || text[vend] === "'") {
                            vend = readString(text, vend, len);
                            continue;
                        }
                        vend++;
                    }
                    tokenizeValue(text.slice(i, vend), out);
                    i = vend;
                    continue;
                }
                // No ':' found before a structural char — emit as plain.
                if (pslice) plain(pslice);
                i = pend;
                continue;
            }

            // ── Selector context (top-level or inside an at-rule body) ──
            // Accumulate until '{', '}', ';', '@', a comment, or a string.
            var selEnd = i;
            while (selEnd < len) {
                var sc = text[selEnd];
                if (sc === '{' || sc === '}' || sc === ';' || sc === '@') break;
                if (sc === '/' && text[selEnd + 1] === '*') break;
                if (sc === '"' || sc === "'") break;
                selEnd++;
            }
            if (selEnd > i) {
                var slice = text.slice(i, selEnd);
                var trimmed = slice.replace(/^\s+|\s+$/g, '');
                if (trimmed === '') {
                    plain(slice);
                } else {
                    var sm = slice.match(/^(\s*)(.*?)(\s*)$/);
                    if (sm) {
                        if (sm[1]) plain(sm[1]);
                        if (sm[2]) emit('selector', sm[2]);
                        if (sm[3]) plain(sm[3]);
                    } else {
                        emit('selector', slice);
                    }
                }
                i = selEnd;
                continue;
            }

            // Fallthrough — single character we don't classify.
            plain(ch);
            i++;
        }

        return out.join('');
    }

    function readString(text, start, len) {
        var quote = text[start];
        var j = start + 1;
        while (j < len && text[j] !== quote) {
            if (text[j] === '\\') j++;
            j++;
        }
        return (j < len) ? j + 1 : len;
    }

    // Decide whether a freshly opened `{` introduces a rule body (where
    // declarations live) or an at-rule body (where more selectors/at-rules
    // live, e.g. inside @media or @supports). Heuristic: scan the most
    // recently emitted spans since the last `{` or start — if the only
    // typed content we emitted between then and now is an atrule keyword
    // (plus plain text and whitespace), this is an at-rule body. If we
    // emitted a `qs-tk-selector` span, this is a rule body.
    function detectFrameKind(out) {
        for (var i = out.length - 2; i >= 0; i--) { // -2 skips the just-pushed `{` punct
            var s = out[i];
            if (s.indexOf('qs-tk-selector') !== -1) return 'rule';
            if (s.indexOf('qs-tk-atrule')   !== -1) return 'atrule';
            if (s.indexOf('class="qs-tk-punct">{')  !== -1) break;
            if (s.indexOf('class="qs-tk-punct">}')  !== -1) break;
        }
        // If we saw neither, default to 'rule' — that matches the most
        // common case (a selector that came through without classification).
        return 'rule';
    }

    function tokenizeValue(value, out) {
        var n = value.length;
        var i = 0;
        while (i < n) {
            var ch = value[i];
            if (ch === '"' || ch === "'") {
                var send = readString(value, i, n);
                out.push('<span class="qs-tk-string">' + escapeHtml(value.slice(i, send)) + '</span>');
                i = send;
                continue;
            }
            if (ch === '/' && value[i + 1] === '*') {
                var ce = value.indexOf('*/', i + 2);
                var cstop = (ce === -1) ? n : ce + 2;
                out.push('<span class="qs-tk-comment">' + escapeHtml(value.slice(i, cstop)) + '</span>');
                i = cstop;
                continue;
            }
            // CSS variable reference (`--foo`)
            if (ch === '-' && value[i + 1] === '-' && /\w/.test(value[i + 2] || '')) {
                var vend = i + 2;
                while (vend < n && /[\w-]/.test(value[vend])) vend++;
                out.push('<span class="qs-tk-var">' + escapeHtml(value.slice(i, vend)) + '</span>');
                i = vend;
                continue;
            }
            // Number (`42`, `1.5px`, `-0.3em`, `100%`, `#fff` hex via the next branch)
            if (/[\d.]/.test(ch) || (ch === '-' && /[\d.]/.test(value[i + 1] || ''))) {
                var nend = i + 1;
                while (nend < n && /[\w%.\-]/.test(value[nend])) nend++;
                out.push('<span class="qs-tk-number">' + escapeHtml(value.slice(i, nend)) + '</span>');
                i = nend;
                continue;
            }
            // Hex colour (`#fff` / `#abcdef`)
            if (ch === '#' && /[0-9a-fA-F]/.test(value[i + 1] || '')) {
                var hend = i + 1;
                while (hend < n && /[0-9a-fA-F]/.test(value[hend])) hend++;
                out.push('<span class="qs-tk-number">' + escapeHtml(value.slice(i, hend)) + '</span>');
                i = hend;
                continue;
            }
            out.push(escapeHtml(ch));
            i++;
        }
    }

    window.QSCodeEditor.tokenizers.css = tokenizeCss;
})();
