/* =============================================================================
   CSS REFINER — Analyzer: Color Normalization
   Converts all CSS color values to a unified format (hex, rgb, or hsl).
   Run BEFORE Duplicates to reveal hidden duplicates across color formats.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;
    /* We reuse color utilities from the designTokens analyzer */
    var DT;

    function getDT() {
        if (!DT) { DT = CSSRefiner.Analyzers.designTokens; }
        return DT;
    }

    /* Properties whose values may contain colours */
    var COLOR_PROPERTIES = [
        'color', 'background-color', 'background', 'border-color',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'outline-color', 'text-decoration-color', 'fill', 'stroke',
        'box-shadow', 'text-shadow', 'border',
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'caret-color', 'column-rule-color', 'accent-color',
        'stop-color', 'flood-color', 'lighting-color'
    ];

    /* ── Helpers ── */

    function isColorProperty(prop) {
        var lp = prop.toLowerCase().trim();
        if (COLOR_PROPERTIES.indexOf(lp) !== -1) { return true; }
        if (lp.indexOf('color') !== -1) { return true; }
        return false;
    }

    /**
     * Check if the character before pos is a word/hyphen char.
     */
    function isPropBoundary(cssText, pos) {
        if (pos <= 0) { return true; }
        var ch = cssText[pos - 1];
        return !(/[a-zA-Z0-9_-]/).test(ch);
    }

    /**
     * Extract individual color tokens from a value string.
     * Handles multi-value properties like "1px solid #ff0000".
     * Returns array of { original, parsed, start, end } relative to the value string.
     */
    function extractColorTokens(valueStr) {
        var tokens = [];
        var dt = getDT();
        var i = 0;
        var len = valueStr.length;

        while (i < len) {
            /* Skip whitespace */
            while (i < len && /\s/.test(valueStr[i])) { i++; }
            if (i >= len) { break; }

            /* Try function-form: rgb(...), hsl(...), rgba(...), hsla(...) */
            var funcMatch = valueStr.substring(i).match(/^(rgba?|hsla?)\s*\(/i);
            if (funcMatch) {
                var parenStart = i + funcMatch[0].length - 1;
                var depth = 1;
                var j = parenStart + 1;
                while (j < len && depth > 0) {
                    if (valueStr[j] === '(') { depth++; }
                    if (valueStr[j] === ')') { depth--; }
                    j++;
                }
                var colorStr = valueStr.substring(i, j);
                var parsed = dt.parseColor(colorStr);
                if (parsed) {
                    tokens.push({ original: colorStr, parsed: parsed, start: i, end: j });
                }
                i = j;
                continue;
            }

            /* Try hex: #xxx, #xxxxxx, #xxxxxxxx */
            var hexMatch = valueStr.substring(i).match(/^#([0-9a-fA-F]{3,8})\b/);
            if (hexMatch) {
                var hexStr = hexMatch[0];
                var parsedHex = dt.parseColor(hexStr);
                if (parsedHex) {
                    tokens.push({ original: hexStr, parsed: parsedHex, start: i, end: i + hexStr.length });
                }
                i += hexStr.length;
                continue;
            }

            /* Try named colors — grab the next word-like token */
            var wordMatch = valueStr.substring(i).match(/^[a-zA-Z]+/);
            if (wordMatch) {
                var word = wordMatch[0];
                var parsedNamed = dt.parseColor(word);
                if (parsedNamed) {
                    tokens.push({ original: word, parsed: parsedNamed, start: i, end: i + word.length });
                }
                i += word.length;
                continue;
            }

            /* Skip other characters (numbers, commas, etc.) */
            i++;
        }

        return tokens;
    }

    /* ── Main analyzer ── */

    function analyze(ast, cssText, options) {
        var targetFormat = (options && options.colorFormat) || 'hex';
        var suggestions = [];

        collectColorNorms(ast, suggestions, cssText, targetFormat, null);
        return suggestions;
    }

    function collectColorNorms(nodes, suggestions, cssText, targetFormat, mediaContext) {
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];

            if (node.type === NODE.NODE_RULE || node.type === NODE.NODE_FONTFACE) {
                processRule(node, suggestions, cssText, targetFormat, mediaContext);
            } else if (node.type === NODE.NODE_MEDIA) {
                var ctx = '@media ' + (node.condition || '').trim();
                collectColorNorms(node.rules || [], suggestions, cssText, targetFormat, ctx);
            } else if (node.rules) {
                collectColorNorms(node.rules, suggestions, cssText, targetFormat, mediaContext);
            }
        }
    }

    function processRule(rule, suggestions, cssText, targetFormat, mediaContext) {
        var decls = rule.declarations;
        if (!decls) { return; }

        var selector = rule.selectorText || '@font-face';
        var searchStart = rule.startIdx;
        var dt = getDT();

        for (var d = 0; d < decls.length; d++) {
            var prop = decls[d].property;
            var val  = decls[d].value.trim();

            /* Only process properties that can contain colors */
            if (!isColorProperty(prop)) { continue; }
            /* Skip if already a variable */
            if (val.indexOf('var(') !== -1) { continue; }

            /* Find the value position in the source */
            var declStr = prop + ':';
            var declPos = cssText.indexOf(declStr, searchStart);
            while (declPos !== -1 && !isPropBoundary(cssText, declPos)) {
                declPos = cssText.indexOf(declStr, declPos + 1);
            }
            if (declPos === -1 || declPos >= rule.endIdx) { continue; }

            var colonPos = declPos + declStr.length;
            while (colonPos < cssText.length && /\s/.test(cssText[colonPos])) {
                colonPos++;
            }

            /* Extract color tokens from the value */
            var colorTokens = extractColorTokens(val);
            if (colorTokens.length === 0) { continue; }

            var edits = [];
            var changes = [];

            for (var c = 0; c < colorTokens.length; c++) {
                var token = colorTokens[c];
                var converted = dt.colorToFormat(token.parsed, targetFormat);

                /* Skip if already in the target format */
                if (converted.toLowerCase() === token.original.toLowerCase()) { continue; }

                edits.push({
                    start:       colonPos + token.start,
                    end:         colonPos + token.end,
                    replacement: converted
                });
                changes.push(token.original + ' → ' + converted);
            }

            if (edits.length === 0) { continue; }

            /* Advance searchStart */
            searchStart = colonPos + val.length;

            var ctx = mediaContext ? ' (' + mediaContext + ')' : '';
            var newVal = val;
            /* Build the preview of the converted value */
            for (var e = colorTokens.length - 1; e >= 0; e--) {
                var tk = colorTokens[e];
                var conv = dt.colorToFormat(tk.parsed, targetFormat);
                if (conv.toLowerCase() !== tk.original.toLowerCase()) {
                    newVal = newVal.substring(0, tk.start) + conv + newVal.substring(tk.end);
                }
            }

            suggestions.push({
                id:          U.generateId('colnorm'),
                type:        NODE.TOOL_COLOR_NORMALIZE,
                description: t('tools.colorNormalize.found', {
                    selector: selector,
                    property: prop
                }) + ctx,
                meta:        changes.join('\n'),
                enabled:     true,
                edits:       edits,
                diff: {
                    type:   'modified',
                    before: prop + ': ' + val,
                    after:  prop + ': ' + newVal
                }
            });
        }
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.colorNormalize = { analyze: analyze };

})();
