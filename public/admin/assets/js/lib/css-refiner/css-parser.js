/* =============================================================================
   CSS REFINER — CSS Parser
   Tokenises raw CSS text into an AST with character-position tracking.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var NODE = CSSRefiner.CONST;

    /* ── Public entry point ── */

    /**
     * Parse a CSS string into an AST.
     *
     * @param  {string} css  Raw CSS text
     * @return {Array}       Array of AST nodes
     */
    function parse(css) {
        return parseBlock(css, 0, css.length, false);
    }

    /* ── Core recursive parser ── */

    /**
     * Parse a region of the CSS text into AST nodes.
     *
     * @param {string}  css        Full CSS source
     * @param {number}  regionStart  Char index where this region begins
     * @param {number}  regionEnd    Char index where this region ends
     * @param {boolean} isNested     True when parsing inside an at-rule body
     * @returns {Array} AST nodes
     */
    function parseBlock(css, regionStart, regionEnd, isNested) {
        var nodes = [];
        var i = regionStart;

        while (i < regionEnd) {
            /* skip whitespace */
            i = skipWhitespace(css, i, regionEnd);
            if (i >= regionEnd) { break; }

            /* ── comment ── */
            if (css[i] === '/' && i + 1 < regionEnd && css[i + 1] === '*') {
                var commentEnd = css.indexOf('*/', i + 2);
                if (commentEnd === -1) { commentEnd = regionEnd - 2; }
                commentEnd += 2; /* include closing * / */
                nodes.push({
                    type:     NODE.NODE_COMMENT,
                    text:     css.substring(i, commentEnd),
                    startIdx: i,
                    endIdx:   commentEnd
                });
                i = commentEnd;
                continue;
            }

            /* ── closing brace (end of nested block) ── */
            if (css[i] === '}') {
                i++;
                continue;
            }

            /* ── at-rule ── */
            if (css[i] === '@') {
                var atNode = parseAtRule(css, i, regionEnd);
                if (atNode) {
                    nodes.push(atNode);
                    i = atNode.endIdx;
                    continue;
                }
                /* fallback: skip to next semicolon or brace */
                var skip = findNext(css, i, regionEnd, [';', '{']);
                i = (skip === -1) ? regionEnd : skip + 1;
                continue;
            }

            /* ── regular rule ── */
            var ruleNode = parseRule(css, i, regionEnd);
            if (ruleNode) {
                nodes.push(ruleNode);
                i = ruleNode.endIdx;
            } else {
                /* safety: advance at least one char to avoid infinite loops */
                i++;
            }
        }

        return nodes;
    }

    /* ── At-rule parser ── */

    function parseAtRule(css, start, limit) {
        /* find the at-keyword */
        var nameEnd = start + 1;
        while (nameEnd < limit && /[\w-]/.test(css[nameEnd])) { nameEnd++; }
        var keyword = css.substring(start + 1, nameEnd).toLowerCase();

        /* ── @media ── */
        if (keyword === 'media') {
            return parseBlockAtRule(css, start, limit, NODE.NODE_MEDIA, keyword);
        }

        /* ── @keyframes / @-webkit-keyframes ── */
        if (keyword === 'keyframes' || keyword === '-webkit-keyframes' || keyword === '-moz-keyframes') {
            return parseKeyframes(css, start, limit, keyword);
        }

        /* ── @font-face ── */
        if (keyword === 'font-face') {
            return parseFontFace(css, start, limit);
        }

        /* ── Other at-rules with block (e.g. @supports, @layer) ── */
        var bracePos = findNextUnquoted(css, nameEnd, limit, '{');
        if (bracePos !== -1) {
            return parseBlockAtRule(css, start, limit, NODE.NODE_OTHER_AT, keyword);
        }

        /* ── Statement at-rules: @import, @charset, etc. ── */
        var semi = findNextUnquoted(css, nameEnd, limit, ';');
        var end  = (semi !== -1) ? semi + 1 : limit;
        return {
            type:      NODE.NODE_OTHER_AT,
            keyword:   keyword,
            raw:       css.substring(start, end),
            startIdx:  start,
            endIdx:    end
        };
    }

    /**
     * Parse an at-rule that has a block body (e.g. @media, @supports).
     */
    function parseBlockAtRule(css, start, limit, nodeType, keyword) {
        var bracePos = findNextUnquoted(css, start, limit, '{');
        if (bracePos === -1) { return null; }

        var condition = css.substring(start + keyword.length + 1, bracePos).trim();
        /* strip leading '@media' etc. since we captured keyword separately */
        condition = condition.replace(/^@[\w-]+\s*/, '');

        var closePos = findMatchingBrace(css, bracePos, limit);
        if (closePos === -1) { closePos = limit; }

        var innerStart = bracePos + 1;
        var innerEnd   = closePos;
        var rules      = parseBlock(css, innerStart, innerEnd, true);
        var endIdx     = closePos + 1;

        return {
            type:      nodeType,
            keyword:   keyword,
            condition: condition,
            rules:     rules,
            raw:       css.substring(start, endIdx),
            startIdx:  start,
            endIdx:    endIdx
        };
    }

    /**
     * Parse @keyframes … { … }
     */
    function parseKeyframes(css, start, limit, keyword) {
        var bracePos = findNextUnquoted(css, start, limit, '{');
        if (bracePos === -1) { return null; }

        var preamble = css.substring(start, bracePos).trim();
        /* extract name: e.g. "@keyframes fadeIn" → "fadeIn" */
        var nameMatch = preamble.match(/@[\w-]+\s+([\w-]+)/);
        var name = nameMatch ? nameMatch[1] : '';

        var closePos = findMatchingBrace(css, bracePos, limit);
        if (closePos === -1) { closePos = limit; }

        /* parse steps inside keyframes body as rules */
        var innerStart = bracePos + 1;
        var innerEnd   = closePos;
        var steps      = parseKeyframeSteps(css, innerStart, innerEnd);
        var endIdx     = closePos + 1;

        return {
            type:     NODE.NODE_KEYFRAMES,
            keyword:  keyword,
            name:     name,
            steps:    steps,
            raw:      css.substring(start, endIdx),
            startIdx: start,
            endIdx:   endIdx
        };
    }

    /**
     * Parse keyframe steps (0% { … }, to { … }, etc.)
     */
    function parseKeyframeSteps(css, regionStart, regionEnd) {
        var steps = [];
        var i = regionStart;

        while (i < regionEnd) {
            i = skipWhitespace(css, i, regionEnd);
            if (i >= regionEnd) { break; }

            if (css[i] === '/' && i + 1 < regionEnd && css[i + 1] === '*') {
                var cEnd = css.indexOf('*/', i + 2);
                i = (cEnd === -1) ? regionEnd : cEnd + 2;
                continue;
            }

            if (css[i] === '}') { i++; continue; }

            var bracePos = findNextUnquoted(css, i, regionEnd, '{');
            if (bracePos === -1) { break; }

            var selector = css.substring(i, bracePos).trim();
            var closePos = findMatchingBrace(css, bracePos, regionEnd);
            if (closePos === -1) { closePos = regionEnd; }

            var decls = parseDeclarations(css, bracePos + 1, closePos);

            steps.push({
                selector:     selector,
                declarations: decls,
                startIdx:     i,
                endIdx:       closePos + 1
            });

            i = closePos + 1;
        }

        return steps;
    }

    /**
     * Parse @font-face { … }
     */
    function parseFontFace(css, start, limit) {
        var bracePos = findNextUnquoted(css, start, limit, '{');
        if (bracePos === -1) { return null; }

        var closePos = findMatchingBrace(css, bracePos, limit);
        if (closePos === -1) { closePos = limit; }

        var decls  = parseDeclarations(css, bracePos + 1, closePos);
        var endIdx = closePos + 1;

        return {
            type:         NODE.NODE_FONTFACE,
            declarations: decls,
            raw:          css.substring(start, endIdx),
            startIdx:     start,
            endIdx:       endIdx
        };
    }

    /* ── Regular rule parser ── */

    function parseRule(css, start, limit) {
        var bracePos = findNextUnquoted(css, start, limit, '{');
        if (bracePos === -1) {
            /* No block found — might be trailing text. Skip to end or semicolon. */
            return null;
        }

        var selectorText = css.substring(start, bracePos).trim();
        if (selectorText === '') { return null; }

        var closePos = findMatchingBrace(css, bracePos, limit);
        if (closePos === -1) { closePos = limit; }

        var selectors = selectorText
            .split(',')
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s.length > 0; });

        var decls  = parseDeclarations(css, bracePos + 1, closePos);
        var endIdx = closePos + 1;

        return {
            type:         NODE.NODE_RULE,
            selectors:    selectors,
            selectorText: selectorText,
            declarations: decls,
            raw:          css.substring(start, endIdx),
            startIdx:     start,
            endIdx:       endIdx
        };
    }

    /* ── Declaration parser ── */

    /**
     * Parse "property: value; property: value; ..." from a region.
     */
    function parseDeclarations(css, start, end) {
        var decls = [];
        var text  = css.substring(start, end);
        var i     = 0;
        var len   = text.length;

        while (i < len) {
            i = skipWhitespaceIn(text, i);
            if (i >= len) { break; }

            /* skip comments inside declaration blocks */
            if (text[i] === '/' && i + 1 < len && text[i + 1] === '*') {
                var cEnd = text.indexOf('*/', i + 2);
                i = (cEnd === -1) ? len : cEnd + 2;
                continue;
            }

            /* find colon separator */
            var colonPos = findCharOutsideParens(text, i, len, ':');
            if (colonPos === -1) { break; }

            var property = text.substring(i, colonPos).trim();

            /* find end of value: semicolon or end of block */
            var valueEnd = findCharOutsideParens(text, colonPos + 1, len, ';');
            var value;
            if (valueEnd === -1) {
                value = text.substring(colonPos + 1).trim();
                i = len;
            } else {
                value = text.substring(colonPos + 1, valueEnd).trim();
                i = valueEnd + 1;
            }

            if (property.length > 0) {
                decls.push({
                    property: property,
                    value:    value,
                    startIdx: start + (colonPos - (colonPos - property.length)),
                    endIdx:   start + (valueEnd !== -1 ? valueEnd + 1 : len)
                });
            }
        }

        return decls;
    }

    /* ── Low-level helpers ── */

    function skipWhitespace(css, i, limit) {
        while (i < limit && /\s/.test(css[i])) { i++; }
        return i;
    }

    function skipWhitespaceIn(str, i) {
        while (i < str.length && /\s/.test(str[i])) { i++; }
        return i;
    }

    /**
     * Find the next occurrence of `char` that is not inside a string or comment.
     */
    function findNextUnquoted(css, start, limit, ch) {
        var i       = start;
        var inStr   = false;
        var strChar = '';

        while (i < limit) {
            var c = css[i];

            if (inStr) {
                if (c === '\\') { i += 2; continue; }
                if (c === strChar) { inStr = false; }
                i++;
                continue;
            }

            if (c === '"' || c === "'") {
                inStr = true;
                strChar = c;
                i++;
                continue;
            }

            if (c === '/' && i + 1 < limit && css[i + 1] === '*') {
                var cEnd = css.indexOf('*/', i + 2);
                i = (cEnd === -1) ? limit : cEnd + 2;
                continue;
            }

            if (c === ch) { return i; }
            i++;
        }
        return -1;
    }

    /**
     * Find the closing } that matches the { at position `openPos`.
     */
    function findMatchingBrace(css, openPos, limit) {
        var depth   = 1;
        var i       = openPos + 1;
        var inStr   = false;
        var strChar = '';

        while (i < limit && depth > 0) {
            var c = css[i];

            if (inStr) {
                if (c === '\\') { i += 2; continue; }
                if (c === strChar) { inStr = false; }
                i++;
                continue;
            }

            if (c === '"' || c === "'") {
                inStr = true;
                strChar = c;
                i++;
                continue;
            }

            if (c === '/' && i + 1 < limit && css[i + 1] === '*') {
                var cEnd = css.indexOf('*/', i + 2);
                i = (cEnd === -1) ? limit : cEnd + 2;
                continue;
            }

            if (c === '{') { depth++; }
            else if (c === '}') { depth--; }

            if (depth === 0) { return i; }
            i++;
        }
        return -1;
    }

    /**
     * Find one of the target characters, scanning forward from `start`.
     */
    function findNext(css, start, limit, chars) {
        for (var i = start; i < limit; i++) {
            if (chars.indexOf(css[i]) !== -1) { return i; }
        }
        return -1;
    }

    /**
     * Find `ch` while respecting parentheses nesting (for calc(), url(), etc.)
     */
    function findCharOutsideParens(text, start, limit, ch) {
        var depth   = 0;
        var inStr   = false;
        var strChar = '';

        for (var i = start; i < limit; i++) {
            var c = text[i];

            if (inStr) {
                if (c === '\\') { i++; continue; }
                if (c === strChar) { inStr = false; }
                continue;
            }

            if (c === '"' || c === "'") { inStr = true; strChar = c; continue; }
            if (c === '(') { depth++; continue; }
            if (c === ')') { depth--; continue; }

            if (depth === 0 && c === ch) { return i; }
        }
        return -1;
    }

    /* ── Serialiser: AST → CSS text ── */

    function serialize(nodes, indent) {
        var pad = indent || '';
        var out = [];

        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];

            switch (node.type) {
                case NODE.NODE_COMMENT:
                    out.push(pad + node.text);
                    break;

                case NODE.NODE_RULE:
                    out.push(pad + node.selectorText + ' {');
                    for (var d = 0; d < node.declarations.length; d++) {
                        var decl = node.declarations[d];
                        out.push(pad + '    ' + decl.property + ': ' + decl.value + ';');
                    }
                    out.push(pad + '}');
                    break;

                case NODE.NODE_MEDIA:
                case NODE.NODE_OTHER_AT:
                    out.push(pad + '@' + node.keyword + ' ' + node.condition + ' {');
                    out.push(serialize(node.rules, pad + '    '));
                    out.push(pad + '}');
                    break;

                case NODE.NODE_KEYFRAMES:
                    out.push(pad + '@' + node.keyword + ' ' + node.name + ' {');
                    for (var s = 0; s < node.steps.length; s++) {
                        var step = node.steps[s];
                        out.push(pad + '    ' + step.selector + ' {');
                        for (var sd = 0; sd < step.declarations.length; sd++) {
                            var sdecl = step.declarations[sd];
                            out.push(pad + '        ' + sdecl.property + ': ' + sdecl.value + ';');
                        }
                        out.push(pad + '    }');
                    }
                    out.push(pad + '}');
                    break;

                case NODE.NODE_FONTFACE:
                    out.push(pad + '@font-face {');
                    for (var f = 0; f < node.declarations.length; f++) {
                        var fdecl = node.declarations[f];
                        out.push(pad + '    ' + fdecl.property + ': ' + fdecl.value + ';');
                    }
                    out.push(pad + '}');
                    break;

                default:
                    if (node.raw) { out.push(pad + node.raw); }
                    break;
            }
        }

        return out.join('\n');
    }

    /* ── Public API ── */
    CSSRefiner.Parser = {
        parse:     parse,
        serialize: serialize
    };

})();
