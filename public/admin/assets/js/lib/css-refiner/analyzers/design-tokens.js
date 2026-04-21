/* =============================================================================
   CSS REFINER — Analyzer: Design Tokens / Variable Extraction
   Finds repeated CSS values and suggests extracting them into :root variables.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;

    /* ── Value categories ── */
    var CATEGORY_COLOR   = 'color';
    var CATEGORY_SIZE    = 'size';
    var CATEGORY_FONT    = 'font';
    var CATEGORY_SHADOW  = 'shadow';
    var CATEGORY_OTHER   = 'other';

    /* Properties whose values are colours */
    var COLOR_PROPERTIES = [
        'color', 'background-color', 'background', 'border-color',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'outline-color', 'text-decoration-color', 'fill', 'stroke',
        'box-shadow', 'text-shadow', 'border',
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'caret-color', 'column-rule-color', 'accent-color'
    ];

    /* Properties whose values are sizes/spacing */
    var SIZE_PROPERTIES = [
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'gap', 'row-gap', 'column-gap',
        'top', 'right', 'bottom', 'left',
        'font-size', 'line-height', 'letter-spacing', 'word-spacing',
        'border-radius', 'border-width',
        'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-left-radius', 'border-bottom-right-radius',
        'flex-basis', 'grid-gap'
    ];

    var FONT_PROPERTIES = ['font-family'];

    var SHADOW_PROPERTIES = ['box-shadow', 'text-shadow'];

    /* Values to exclude — too generic to be design tokens */
    var EXCLUDED_VALUES = [
        '0', 'none', 'inherit', 'initial', 'unset', 'revert',
        'auto', 'normal', 'transparent', 'currentcolor', 'currentColor',
        '100%', '0px', '0em', '0rem',
        'solid', 'dashed', 'dotted', 'hidden', 'visible',
        'block', 'inline', 'inline-block', 'flex', 'grid', 'table',
        'relative', 'absolute', 'fixed', 'sticky', 'static',
        'pointer', 'default', 'text',
        'left', 'right', 'center', 'top', 'bottom',
        'nowrap', 'wrap', 'bold', 'normal', 'italic',
        'both', 'ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear',
        'row', 'column', 'space-between', 'space-around', 'stretch',
        'baseline', 'start', 'end', 'flex-start', 'flex-end',
        'collapse', 'separate', 'border-box', 'content-box',
        'cover', 'contain', 'no-repeat', 'repeat',
        '1', '-1', '0 0'
    ];

    /* ── Color parsing ── */

    var HEX3_RE  = /^#([0-9a-f]{3})$/i;
    var HEX6_RE  = /^#([0-9a-f]{6})$/i;
    var HEX8_RE  = /^#([0-9a-f]{8})$/i;
    var RGB_RE   = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/i;
    var HSL_RE   = /^hsla?\(\s*(\d{1,3})\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%\s*(?:,\s*([\d.]+)\s*)?\)$/i;

    var NAMED_COLORS = {
        'black': [0,0,0], 'white': [255,255,255], 'red': [255,0,0],
        'green': [0,128,0], 'blue': [0,0,255], 'yellow': [255,255,0],
        'cyan': [0,255,255], 'magenta': [255,0,255], 'silver': [192,192,192],
        'gray': [128,128,128], 'grey': [128,128,128], 'maroon': [128,0,0],
        'olive': [128,128,0], 'lime': [0,255,0], 'aqua': [0,255,255],
        'teal': [0,128,128], 'navy': [0,0,128], 'fuchsia': [255,0,255],
        'purple': [128,0,128], 'orange': [255,165,0],
        'coral': [255,127,80], 'tomato': [255,99,71],
        'gold': [255,215,0], 'khaki': [240,230,140],
        'indigo': [75,0,130], 'violet': [238,130,238],
        'pink': [255,192,203], 'brown': [165,42,42],
        'crimson': [220,20,60], 'darkgray': [169,169,169],
        'darkgrey': [169,169,169], 'lightgray': [211,211,211],
        'lightgrey': [211,211,211], 'whitesmoke': [245,245,245]
    };

    /**
     * Parse any CSS color to { r, g, b, a } or null.
     */
    function parseColor(val) {
        var v = val.trim().toLowerCase();
        var m;

        /* Named colors */
        if (NAMED_COLORS[v]) {
            var c = NAMED_COLORS[v];
            return { r: c[0], g: c[1], b: c[2], a: 1 };
        }

        /* #RGB */
        m = v.match(HEX3_RE);
        if (m) {
            var h3 = m[1];
            return {
                r: parseInt(h3[0] + h3[0], 16),
                g: parseInt(h3[1] + h3[1], 16),
                b: parseInt(h3[2] + h3[2], 16),
                a: 1
            };
        }

        /* #RRGGBB */
        m = v.match(HEX6_RE);
        if (m) {
            return {
                r: parseInt(m[1].substring(0, 2), 16),
                g: parseInt(m[1].substring(2, 4), 16),
                b: parseInt(m[1].substring(4, 6), 16),
                a: 1
            };
        }

        /* #RRGGBBAA */
        m = v.match(HEX8_RE);
        if (m) {
            return {
                r: parseInt(m[1].substring(0, 2), 16),
                g: parseInt(m[1].substring(2, 4), 16),
                b: parseInt(m[1].substring(4, 6), 16),
                a: parseInt(m[1].substring(6, 8), 16) / 255
            };
        }

        /* rgb() / rgba() */
        m = v.match(RGB_RE);
        if (m) {
            return {
                r: parseInt(m[1], 10),
                g: parseInt(m[2], 10),
                b: parseInt(m[3], 10),
                a: m[4] !== undefined ? parseFloat(m[4]) : 1
            };
        }

        /* hsl() / hsla() */
        m = v.match(HSL_RE);
        if (m) {
            var rgb = hslToRgb(parseInt(m[1], 10), parseFloat(m[2]), parseFloat(m[3]));
            return { r: rgb[0], g: rgb[1], b: rgb[2], a: m[4] !== undefined ? parseFloat(m[4]) : 1 };
        }

        return null;
    }

    function hslToRgb(h, s, l) {
        s /= 100;
        l /= 100;
        var a = s * Math.min(l, 1 - l);
        function f(n) {
            var k = (n + h / 30) % 12;
            return l - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
        }
        return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
    }

    function rgbToHex(r, g, b) {
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h = 0, s = 0, l = (max + min) / 2;
        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            if (max === r) { h = (g - b) / d + (g < b ? 6 : 0); }
            else if (max === g) { h = (b - r) / d + 2; }
            else { h = (r - g) / d + 4; }
            h /= 6;
        }
        return [Math.round(h * 360), Math.round(s * 100), Math.round(l * 100)];
    }

    /**
     * Convert a parsed color to a specific format string.
     */
    function colorToFormat(parsed, format) {
        if (format === 'hex') {
            if (parsed.a < 1) {
                var aa = Math.round(parsed.a * 255).toString(16);
                if (aa.length === 1) { aa = '0' + aa; }
                return rgbToHex(parsed.r, parsed.g, parsed.b) + aa;
            }
            return rgbToHex(parsed.r, parsed.g, parsed.b);
        }
        if (format === 'rgb') {
            if (parsed.a < 1) {
                return 'rgba(' + parsed.r + ', ' + parsed.g + ', ' + parsed.b + ', ' + round2(parsed.a) + ')';
            }
            return 'rgb(' + parsed.r + ', ' + parsed.g + ', ' + parsed.b + ')';
        }
        if (format === 'hsl') {
            var hsl = rgbToHsl(parsed.r, parsed.g, parsed.b);
            if (parsed.a < 1) {
                return 'hsla(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%, ' + round2(parsed.a) + ')';
            }
            return 'hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)';
        }
        return rgbToHex(parsed.r, parsed.g, parsed.b);
    }

    function round2(n) { return Math.round(n * 100) / 100; }

    /**
     * Normalise a color value to a canonical key (hex lowercase).
     */
    function normalizeColorKey(val) {
        var parsed = parseColor(val);
        if (!parsed) { return null; }
        if (parsed.a < 1) {
            return rgbToHex(parsed.r, parsed.g, parsed.b) + '_a' + Math.round(parsed.a * 100);
        }
        return rgbToHex(parsed.r, parsed.g, parsed.b);
    }

    /* ── Category detection ── */

    function categorize(prop, val) {
        var lp = prop.toLowerCase().trim();
        var lv = val.trim().toLowerCase();

        /* Skip generic values */
        if (EXCLUDED_VALUES.indexOf(lv) !== -1) { return null; }
        /* Skip values that are just keywords (no numbers, no # or parens) */
        if (/^[a-z-]+$/.test(lv) && !NAMED_COLORS[lv]) { return null; }

        if (SHADOW_PROPERTIES.indexOf(lp) !== -1) { return CATEGORY_SHADOW; }
        if (FONT_PROPERTIES.indexOf(lp) !== -1) { return CATEGORY_FONT; }

        /* Check if value is a color */
        if (COLOR_PROPERTIES.indexOf(lp) !== -1 || lp.indexOf('color') !== -1) {
            if (parseColor(val)) { return CATEGORY_COLOR; }
        }
        if (/^#[0-9a-f]{3,8}$/i.test(lv) || /^(rgb|hsl)a?\(/.test(lv)) {
            return CATEGORY_COLOR;
        }
        if (NAMED_COLORS[lv]) { return CATEGORY_COLOR; }

        /* Size */
        if (SIZE_PROPERTIES.indexOf(lp) !== -1) { return CATEGORY_SIZE; }
        if (/^\d/.test(lv) && /\d(px|em|rem|%|vw|vh|vmin|vmax|pt)$/.test(lv)) {
            return CATEGORY_SIZE;
        }

        return CATEGORY_OTHER;
    }

    /**
     * Generate a CSS variable name from value + category.
     */
    function suggestVarName(category, value, index) {
        var prefix = '--';
        switch (category) {
            case CATEGORY_COLOR:  prefix += 'color-';   break;
            case CATEGORY_SIZE:   prefix += 'size-';    break;
            case CATEGORY_FONT:   prefix += 'font-';    break;
            case CATEGORY_SHADOW: prefix += 'shadow-';  break;
            default:              prefix += 'token-';   break;
        }
        /* Try to create a readable suffix from the value */
        var clean = value.replace(/[^a-zA-Z0-9]/g, '-')
                         .replace(/-+/g, '-')
                         .replace(/^-|-$/g, '')
                         .toLowerCase()
                         .substring(0, 20);
        return prefix + (clean || String(index));
    }

    /* ── Main analyzer ── */

    function analyze(ast, cssText, options) {
        var minOccurrences = (options && options.minOccurrences)
            ? options.minOccurrences
            : NODE.TOKENS_MIN_OCCURRENCES;

        var suggestions = [];
        var entries     = [];

        /* Collect all declaration values */
        collectEntries(ast, entries, cssText);

        /* Group by normalised value within each category */
        var groups = {};
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            var key = e.category + '||' + e.normalKey;
            if (!groups[key]) {
                groups[key] = {
                    category:    e.category,
                    displayValue: e.displayValue,
                    normalKey:   e.normalKey,
                    entries:     []
                };
            }
            groups[key].entries.push(e);
        }

        var keys = Object.keys(groups);
        var tokenIndex = 0;
        for (var k = 0; k < keys.length; k++) {
            var group = groups[keys[k]];
            if (group.entries.length < minOccurrences) { continue; }

            tokenIndex++;

            var displayValue = group.displayValue;

            var varName = suggestVarName(group.category, displayValue, tokenIndex);

            /* Build edits: replace each occurrence with var(--name) */
            var edits = [];
            var locations = [];
            for (var e2 = 0; e2 < group.entries.length; e2++) {
                var entry = group.entries[e2];
                edits.push({
                    start:       entry.valueStart,
                    end:         entry.valueEnd,
                    replacement: 'var(' + varName + ')'
                });
                if (locations.length < 5) {
                    locations.push(entry.selector + ' { ' + entry.property + ': ' + entry.rawValue + ' }');
                }
            }

            var rootLine = varName + ': ' + displayValue + ';';

            var categoryLabel = t('tools.designTokens.category.' + group.category);

            suggestions.push({
                id:          U.generateId('token'),
                type:        NODE.TOOL_DESIGN_TOKENS,
                description: t('tools.designTokens.found', {
                    value: displayValue,
                    count: group.entries.length,
                    category: categoryLabel
                }),
                meta: t('tools.designTokens.varName', { name: varName }) +
                    '\n' + locations.join('\n') +
                    (group.entries.length > 5 ? '\n… ' + t('tools.designTokens.andMore', { count: group.entries.length - 5 }) : ''),
                enabled:        false,
                edits:          edits,
                suggestedValue: varName,
                _rootDecl:      rootLine,
                _category:      group.category,
                diff: {
                    before: locations.slice(0, 3).join('\n'),
                    after:  rootLine + '\n\n' + locations.slice(0, 3).map(function (l) {
                        return l.replace(group.displayValue, 'var(' + varName + ')');
                    }).join('\n')
                }
            });
        }

        /* Sort: colors first, then sizes, then fonts, then others; within each by count desc */
        var catOrder = {};
        catOrder[CATEGORY_COLOR]  = 0;
        catOrder[CATEGORY_SIZE]   = 1;
        catOrder[CATEGORY_FONT]   = 2;
        catOrder[CATEGORY_SHADOW] = 3;
        catOrder[CATEGORY_OTHER]  = 4;

        suggestions.sort(function (a, b) {
            var ca = catOrder[a._category] || 9;
            var cb = catOrder[b._category] || 9;
            if (ca !== cb) { return ca - cb; }
            return b.edits.length - a.edits.length;
        });

        return suggestions;
    }

    /* ── Entry collection ── */

    function collectEntries(ast, list, cssText, context) {
        var ctx = context || 'top';
        for (var i = 0; i < ast.length; i++) {
            var node = ast[i];

            if (node.type === NODE.NODE_RULE || node.type === NODE.NODE_FONTFACE) {
                addDeclEntries(node, list, cssText, ctx);
            } else if (node.type === NODE.NODE_MEDIA) {
                var mediaCtx = '@media ' + (node.condition || '').replace(/\s+/g, ' ').trim().toLowerCase();
                collectEntries(node.rules || [], list, cssText, mediaCtx);
            } else if (node.rules) {
                collectEntries(node.rules, list, cssText, ctx);
            }
        }
    }

    /**
     * Check if the character before pos is a word/hyphen char.
     * If so, the match is inside a longer property name (e.g. "color" in "border-color").
     */
    function isPropBoundary(cssText, pos) {
        if (pos <= 0) { return true; }
        var ch = cssText[pos - 1];
        return !(/[a-zA-Z0-9_-]/).test(ch);
    }

    function addDeclEntries(rule, list, cssText, context) {
        var decls = rule.declarations;
        if (!decls) { return; }

        var selector = rule.selectorText || '@' + (rule.keyword || 'rule');
        var searchStart = rule.startIdx;

        for (var d = 0; d < decls.length; d++) {
            var prop = decls[d].property;
            var val  = decls[d].value.trim();

            /* Skip if already a variable */
            if (val.indexOf('var(') !== -1) { continue; }

            var category = categorize(prop, val);
            if (!category) { continue; }

            /* Find the value position in the source.
               Use word-boundary check to avoid matching "color:" inside "border-color:".
               Advance searchStart past each found declaration to avoid double-matching. */
            var declStr = prop + ':';
            var declPos = cssText.indexOf(declStr, searchStart);
            while (declPos !== -1 && !isPropBoundary(cssText, declPos)) {
                declPos = cssText.indexOf(declStr, declPos + 1);
            }
            if (declPos === -1 || declPos >= rule.endIdx) { continue; }

            /* Find the value after the colon */
            var colonPos = declPos + declStr.length;
            /* Skip whitespace after colon */
            while (colonPos < cssText.length && (cssText[colonPos] === ' ' || cssText[colonPos] === '\t')) {
                colonPos++;
            }

            /* Confirm this is the right value */
            var valueSlice = cssText.substring(colonPos, colonPos + val.length);
            if (normalizeForMatch(valueSlice) !== normalizeForMatch(val)) {
                /* Try scanning more carefully */
                var altStart = cssText.indexOf(val, colonPos);
                if (altStart !== -1 && altStart < colonPos + 100) {
                    colonPos = altStart;
                } else {
                    continue;
                }
            }

            /* Advance searchStart past this declaration for the next iteration */
            searchStart = colonPos + val.length;

            /* Normalise key for grouping */
            var normalKey;
            var displayValue = val;
            if (category === CATEGORY_COLOR) {
                normalKey = normalizeColorKey(val);
                if (!normalKey) { normalKey = val.toLowerCase(); }
            } else {
                normalKey = val.toLowerCase().replace(/\s+/g, ' ');
            }

            list.push({
                property:     prop,
                rawValue:     val,
                displayValue: displayValue,
                normalKey:    normalKey,
                category:     category,
                selector:     selector,
                context:      context,
                valueStart:   colonPos,
                valueEnd:     colonPos + val.length
            });
        }
    }

    function normalizeForMatch(s) {
        return s.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.designTokens = { analyze: analyze, parseColor: parseColor, colorToFormat: colorToFormat };

})();
