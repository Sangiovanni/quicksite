/* =============================================================================
   CSS REFINER — Analyzer: Empty Rules
   Detects CSS rulesets with no declarations (or only whitespace/comments).
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;

    /* ── Main analyzer ── */

    function analyze(ast, cssText) {
        var suggestions = [];
        collectEmpty(ast, suggestions, cssText, null);
        return suggestions;
    }

    function collectEmpty(nodes, suggestions, cssText, mediaContext) {
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];

            if (node.type === NODE.NODE_RULE || node.type === NODE.NODE_FONTFACE) {
                if (isEmptyRule(node)) {
                    var selector = node.selectorText || '@font-face';
                    var ctx = mediaContext ? ' (' + mediaContext + ')' : '';
                    suggestions.push({
                        id:          U.generateId('empty'),
                        type:        NODE.TOOL_EMPTY_RULES,
                        description: t('tools.emptyRules.found', { selector: selector }) + ctx,
                        meta:        t('tools.emptyRules.action'),
                        enabled:     true,
                        edits: [{
                            start:       node.startIdx,
                            end:         node.endIdx,
                            replacement: ''
                        }],
                        diff: {
                            type:   'removed',
                            before: cssText.substring(node.startIdx, node.endIdx).trim()
                        }
                    });
                }
            } else if (node.type === NODE.NODE_MEDIA) {
                var ctx2 = '@media ' + (node.condition || '').trim();
                collectEmpty(node.rules || [], suggestions, cssText, ctx2);

                /* Also check if the entire @media block is empty */
                if (isEmptyMediaBlock(node)) {
                    suggestions.push({
                        id:          U.generateId('empty'),
                        type:        NODE.TOOL_EMPTY_RULES,
                        description: t('tools.emptyRules.emptyMedia', { condition: node.condition || '?' }),
                        meta:        t('tools.emptyRules.action'),
                        enabled:     true,
                        edits: [{
                            start:       node.startIdx,
                            end:         node.endIdx,
                            replacement: ''
                        }],
                        diff: {
                            type:   'removed',
                            before: cssText.substring(node.startIdx, node.endIdx).trim()
                        }
                    });
                }
            } else if (node.rules) {
                collectEmpty(node.rules, suggestions, cssText, mediaContext);
            }
        }
    }

    function isEmptyRule(node) {
        if (!node.declarations || node.declarations.length === 0) {
            return true;
        }
        /* Check if all declarations have empty values (shouldn't happen but be safe) */
        for (var d = 0; d < node.declarations.length; d++) {
            if (node.declarations[d].property.trim() !== '' &&
                node.declarations[d].value.trim() !== '') {
                return false;
            }
        }
        return true;
    }

    function isEmptyMediaBlock(node) {
        var rules = node.rules || [];
        if (rules.length === 0) { return true; }
        /* A media block is "empty" if all its rules are empty */
        for (var i = 0; i < rules.length; i++) {
            if (rules[i].type === NODE.NODE_RULE || rules[i].type === NODE.NODE_FONTFACE) {
                if (!isEmptyRule(rules[i])) { return false; }
            } else if (rules[i].type === NODE.NODE_MEDIA) {
                if (!isEmptyMediaBlock(rules[i])) { return false; }
            } else {
                return false;
            }
        }
        return true;
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.emptyRules = { analyze: analyze };

})();
