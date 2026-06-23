/**
 * QSCodeEditor — small in-tree code editor widget.
 *
 * Textarea-over-pre pattern: a transparent <textarea> handles all input
 * (caret, IME, undo/redo, paste, selection) while a <pre> sits behind it
 * showing the tokenizer's coloured output. A line-number gutter scrolls
 * in sync. No dependencies; works against any pluggable tokenizer.
 *
 * Token CSS classes are namespaced `qs-tk-*` (e.g. qs-tk-comment,
 * qs-tk-string, qs-tk-property). Tokenizers register on
 * `QSCodeEditor.tokenizers.<langKey>` and return an HTML string given a
 * raw text value.
 *
 * Usage:
 *   const ed = QSCodeEditor.create({
 *     mount:    someElement,
 *     value:    'body { color: red; }',
 *     tokenize: QSCodeEditor.tokenizers.css,
 *     onChange: (newValue) => { ... }
 *   });
 *   ed.getValue(); ed.setValue('...'); ed.focus(); ed.destroy();
 */
(function () {
    'use strict';

    var QSCodeEditor = window.QSCodeEditor || {};
    QSCodeEditor.tokenizers = QSCodeEditor.tokenizers || {};

    function escapeHtml(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c];
        });
    }

    function create(opts) {
        opts = opts || {};
        var mount = opts.mount;
        if (!mount) throw new Error('QSCodeEditor: mount element required');

        // Clear any prior content (e.g. a loading spinner) and tag the host.
        mount.innerHTML = '';
        mount.classList.add('qs-code-editor');

        var gutter = document.createElement('div');
        gutter.className = 'qs-code-editor__gutter';
        gutter.setAttribute('aria-hidden', 'true');

        var wrap = document.createElement('div');
        wrap.className = 'qs-code-editor__wrap';

        var highlight = document.createElement('pre');
        highlight.className = 'qs-code-editor__highlight';
        highlight.setAttribute('aria-hidden', 'true');

        // Search overlay (A3 slice 3). Sits between the tokenized highlight
        // layer and the textarea. Its text is transparent — only the match
        // spans paint a visible background, so all matches appear as
        // highlights over the tokenised text below.
        var searchOverlay = document.createElement('pre');
        searchOverlay.className = 'qs-code-editor__search-overlay';
        searchOverlay.setAttribute('aria-hidden', 'true');

        var textarea = document.createElement('textarea');
        textarea.className = 'qs-code-editor__input';
        textarea.setAttribute('spellcheck', 'false');
        textarea.setAttribute('autocapitalize', 'off');
        textarea.setAttribute('autocomplete', 'off');
        textarea.setAttribute('autocorrect', 'off');
        textarea.setAttribute('wrap', 'off');

        wrap.appendChild(highlight);
        wrap.appendChild(searchOverlay);
        wrap.appendChild(textarea);
        mount.appendChild(gutter);
        mount.appendChild(wrap);

        var _value = opts.value || '';
        var _changeTimer = null;
        var _lastLineCount = -1;
        var tokenize = opts.tokenize || function (text) { return escapeHtml(text); };

        function render() {
            // Trailing space keeps the last line height intact when the
            // value ends in '\n' (otherwise the <pre> collapses the line).
            highlight.innerHTML = tokenize(_value) + ' ';
            var lineCount = countLines(_value);
            if (lineCount !== _lastLineCount) {
                renderGutter(lineCount);
                _lastLineCount = lineCount;
            }
        }

        function countLines(text) {
            var n = 1;
            for (var i = 0; i < text.length; i++) {
                if (text.charCodeAt(i) === 10) n++;
            }
            return n;
        }

        function renderGutter(count) {
            // Rebuild the gutter en bloc — line-number changes are rare
            // (one line break can shift the count by 1, but typing inside
            // a line is the common case and is a no-op here).
            var frag = document.createDocumentFragment();
            for (var i = 1; i <= count; i++) {
                var line = document.createElement('div');
                line.className = 'qs-code-editor__line-number';
                line.textContent = String(i);
                frag.appendChild(line);
            }
            gutter.innerHTML = '';
            gutter.appendChild(frag);
        }

        function syncScroll() {
            highlight.scrollTop  = textarea.scrollTop;
            highlight.scrollLeft = textarea.scrollLeft;
            searchOverlay.scrollTop  = textarea.scrollTop;
            searchOverlay.scrollLeft = textarea.scrollLeft;
            gutter.scrollTop = textarea.scrollTop;
        }

        // ── Search overlay rendering ──
        // Renders match positions as transparent-text spans with visible
        // background. Current match gets an additional class for emphasis.
        function renderSearchOverlay(matches, currentIdx) {
            if (!matches || matches.length === 0) {
                searchOverlay.innerHTML = '';
                return;
            }
            var out = [];
            var i = 0;
            var len = _value.length;
            var m = 0;
            while (i < len && m < matches.length) {
                var match = matches[m];
                if (i < match.start) {
                    out.push(escapeHtml(_value.slice(i, match.start)));
                    i = match.start;
                    continue;
                }
                var cls = 'qs-search-match' + (m === currentIdx ? ' qs-search-match--current' : '');
                out.push('<span class="' + cls + '">' + escapeHtml(_value.slice(match.start, match.end)) + '</span>');
                i = match.end;
                m++;
            }
            if (i < len) out.push(escapeHtml(_value.slice(i)));
            // Trailing space matches the highlight layer's safeguard so
            // the overlay's height matches the tokenised pre when the value
            // ends in '\n'.
            searchOverlay.innerHTML = out.join('') + ' ';
        }

        // ── Line offsets (cached) for jumpToLine + scrollMatchIntoView ──
        var _lineOffsets = null;
        function computeLineOffsets() {
            // Array of char offsets where each line begins. lineOffsets[N]
            // is the offset of the first char of line (N+1).
            var arr = [0];
            for (var i = 0; i < _value.length; i++) {
                if (_value.charCodeAt(i) === 10) arr.push(i + 1);
            }
            _lineOffsets = arr;
            return arr;
        }
        function getLineOffsets() {
            return _lineOffsets || computeLineOffsets();
        }
        function invalidateLineOffsets() { _lineOffsets = null; }

        // Binary search: 1-based line number containing the given char offset.
        function lineFromOffset(offset) {
            var offsets = getLineOffsets();
            var lo = 0, hi = offsets.length - 1, result = 1;
            while (lo <= hi) {
                var mid = (lo + hi) >> 1;
                if (offsets[mid] <= offset) { result = mid + 1; lo = mid + 1; }
                else { hi = mid - 1; }
            }
            return result;
        }

        // Programmatically scroll the textarea so the given char offset's
        // line is visible. Does NOT focus the textarea — important so the
        // search input doesn't lose focus while the user is still typing.
        function scrollOffsetIntoView(charOffset) {
            var styles = getComputedStyle(textarea);
            var lineHeight = parseFloat(styles.lineHeight) || 19.5;
            var paddingTop = parseFloat(styles.paddingTop) || 0;
            var matchY     = paddingTop + (lineFromOffset(charOffset) - 1) * lineHeight;
            var visibleTop    = textarea.scrollTop;
            var visibleHeight = textarea.clientHeight;
            var visibleBottom = visibleTop + visibleHeight;
            var buffer = lineHeight * 2;
            if (matchY < visibleTop + buffer) {
                textarea.scrollTop = Math.max(0, matchY - buffer);
            } else if (matchY + lineHeight > visibleBottom - buffer) {
                textarea.scrollTop = matchY + lineHeight - visibleHeight + buffer;
            }
        }

        function handleInput() {
            _value = textarea.value;
            invalidateLineOffsets();
            render();
            if (opts.onChange) {
                if (_changeTimer) clearTimeout(_changeTimer);
                _changeTimer = setTimeout(function () { opts.onChange(_value); }, 50);
            }
        }

        textarea.addEventListener('input', handleInput);
        textarea.addEventListener('scroll', syncScroll);

        textarea.value = _value;
        // Browsers leave the caret at the end of the text after setting
        // .value. A subsequent focus() then scrolls to make that end
        // visible — meaning a freshly-mounted editor opens at the bottom
        // of the file. Move the caret to (0,0) so focus() lands at the top.
        try { textarea.setSelectionRange(0, 0); } catch (e) { /* no-op */ }
        render();

        return {
            getValue: function () { return _value; },
            setValue: function (v) {
                _value = (v == null) ? '' : String(v);
                textarea.value = _value;
                try { textarea.setSelectionRange(0, 0); } catch (e) { /* no-op */ }
                render();
                syncScroll();
            },
            focus: function () { textarea.focus(); },
            // Reset scroll position to the top. Useful when the editor's
            // host element was hidden + re-shown — browsers can reset the
            // textarea's scrollTop while the <pre> overlay (set
            // programmatically) keeps its position, leaving the two
            // layers out of sync. Calling this on re-entry guarantees
            // both layers start aligned.
            //
            // Also resets the caret to (0,0): otherwise a subsequent
            // focus() will scroll back to wherever the caret was sitting
            // (typically wherever the user last clicked), undoing the
            // scroll reset and re-creating the click-vs-visible mismatch
            // that this method is meant to prevent.
            resetScroll: function () {
                textarea.scrollTop = 0;
                textarea.scrollLeft = 0;
                try { textarea.setSelectionRange(0, 0); } catch (e) { /* no-op */ }
                syncScroll();
            },
            destroy: function () {
                textarea.removeEventListener('input', handleInput);
                textarea.removeEventListener('scroll', syncScroll);
                if (_changeTimer) { clearTimeout(_changeTimer); _changeTimer = null; }
                mount.innerHTML = '';
                mount.classList.remove('qs-code-editor');
            },
            // ── Search (A3 slice 3) ──
            // setMatches paints the overlay; clearMatches removes it.
            // matches: array of { start, end } char offsets; currentIdx is
            // the 0-based index of the currently-emphasised match (or -1).
            setMatches: function (matches, currentIdx) {
                renderSearchOverlay(matches || [], (currentIdx == null ? -1 : currentIdx));
            },
            clearMatches: function () {
                searchOverlay.innerHTML = '';
            },
            // Scroll the textarea so the char range [start, end] is visible.
            // Sets the selection on the textarea but does NOT focus it —
            // any caller that already owns focus (e.g. a search input)
            // keeps it. The browser will show the selection as active when
            // focus eventually returns to the textarea.
            scrollRangeIntoView: function (start, end) {
                try { textarea.setSelectionRange(start, end); } catch (e) { /* no-op */ }
                scrollOffsetIntoView(start);
                syncScroll();
            },
            // Jump-to-line (1-based). Returns true if the line exists.
            // Caret lands at the start of the requested line; focus moves
            // to the textarea since ':N' is a commit action — the user
            // wants to edit at that line.
            scrollToLine: function (lineNumber) {
                var offsets = getLineOffsets();
                if (lineNumber < 1) lineNumber = 1;
                if (lineNumber > offsets.length) lineNumber = offsets.length;
                var pos = offsets[lineNumber - 1];
                try {
                    textarea.focus();
                    textarea.setSelectionRange(pos, pos);
                } catch (e) { /* no-op */ }
                scrollOffsetIntoView(pos);
                syncScroll();
                return true;
            },
            // Slice 3 exposure points (search / jump-to-line)
            getTextarea:  function () { return textarea; },
            getHighlight: function () { return highlight; },
            getGutter:    function () { return gutter; }
        };
    }

    QSCodeEditor.create     = create;
    QSCodeEditor.escapeHtml = escapeHtml;
    window.QSCodeEditor     = QSCodeEditor;
})();
