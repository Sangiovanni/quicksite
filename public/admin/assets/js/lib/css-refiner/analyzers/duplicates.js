/* =============================================================================
   CSS REFINER — Analyzer: Exact Duplicates
   Detects identical selectors and identical declaration blocks.
   Also handles @keyframes and @font-face duplicates.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;

    /**
     * Run duplicate analysis on an AST.
     *
     * @param  {Array}  ast         Parsed CSS AST
     * @param  {string} cssText     Original CSS source
     * @return {Array}              Array of suggestion objects
     */
    function analyze(ast, cssText) {
        var suggestions = [];

        findDuplicateSelectors(ast, suggestions);
        findIdenticalBlocks(ast, suggestions);
        findDuplicateKeyframes(ast, suggestions);
        findDuplicateFontFaces(ast, suggestions);

        return suggestions;
    }

    /* ── Duplicate selectors (same selector appears multiple times) ── */

    function findDuplicateSelectors(ast, suggestions) {
        var selectorMap = {};

        collectRules(ast, function (rule, context) {
            var key = context + '||' + rule.selectorText.trim().toLowerCase();
            if (!selectorMap[key]) { selectorMap[key] = []; }
            selectorMap[key].push(rule);
        });

        var keys = Object.keys(selectorMap);
        for (var i = 0; i < keys.length; i++) {
            var group = selectorMap[keys[i]];
            if (group.length < 2) { continue; }

            /* keep the LAST occurrence, remove earlier ones */
            var last    = group[group.length - 1];
            var removed = group.slice(0, group.length - 1);
            var edits   = [];

            for (var r = 0; r < removed.length; r++) {
                edits.push({
                    start:       removed[r].startIdx,
                    end:         removed[r].endIdx,
                    replacement: ''
                });
            }

            /* merge unique declarations from removed into last */
            var mergedDecls   = mergeDeclarations(group);
            var mergedRaw     = buildRuleString(last.selectorText, mergedDecls);

            edits.push({
                start:       last.startIdx,
                end:         last.endIdx,
                replacement: mergedRaw
            });

            suggestions.push({
                id:          U.generateId('dup-sel'),
                type:        NODE.TOOL_DUPLICATES,
                description: t('tools.duplicates.sameSelector', {
                    selector: group[0].selectorText,
                    count:    group.length
                }),
                enabled:     true,
                edits:       edits,
                diff: {
                    before: group.map(function (r) { return r.raw; }).join('\n\n'),
                    after:  mergedRaw
                }
            });
        }
    }

    /* ── Identical declaration blocks (different selectors, same styles) ── */

    function findIdenticalBlocks(ast, suggestions) {
        var hashMap = {};

        collectRules(ast, function (rule, context) {
            var key = U.normalizeDeclarations(rule.declarations);
            var hash = context + '||' + String(U.hashString(key));
            if (!hashMap[hash]) { hashMap[hash] = []; }
            hashMap[hash].push(rule);
        });

        var hashes = Object.keys(hashMap);
        for (var i = 0; i < hashes.length; i++) {
            var group = hashMap[hashes[i]];
            if (group.length < 2) { continue; }

            /* skip if they already share the same selector (handled above) */
            var selSet = {};
            var allSame = true;
            for (var g = 0; g < group.length; g++) {
                var key = group[g].selectorText.trim().toLowerCase();
                if (selSet[key]) { allSame = false; }
                selSet[key] = true;
            }
            if (Object.keys(selSet).length === 1) { continue; }

            /* merge into one rule with combined selectors */
            var combinedSelectors = [];
            for (var s = 0; s < group.length; s++) {
                var sels = group[s].selectors;
                for (var si = 0; si < sels.length; si++) {
                    if (combinedSelectors.indexOf(sels[si]) === -1) {
                        combinedSelectors.push(sels[si]);
                    }
                }
            }
            var mergedSelector = combinedSelectors.join(',\n');
            var mergedRaw = buildRuleString(mergedSelector, group[0].declarations);

            var edits = [];
            /* remove all except the first; replace first with merged */
            for (var r = 1; r < group.length; r++) {
                edits.push({ start: group[r].startIdx, end: group[r].endIdx, replacement: '' });
            }
            edits.push({ start: group[0].startIdx, end: group[0].endIdx, replacement: mergedRaw });

            suggestions.push({
                id:          U.generateId('dup-blk'),
                type:        NODE.TOOL_DUPLICATES,
                description: t('tools.duplicates.sameBlock', {
                    selectors: combinedSelectors.join(', ')
                }),
                enabled:     true,
                edits:       edits,
                diff: {
                    before: group.map(function (r) { return r.raw; }).join('\n\n'),
                    after:  mergedRaw
                }
            });
        }
    }

    /* ── Duplicate @keyframes ── */

    function findDuplicateKeyframes(ast, suggestions) {
        var kfMap = {};

        for (var i = 0; i < ast.length; i++) {
            var node = ast[i];
            if (node.type !== NODE.NODE_KEYFRAMES) { continue; }
            var normalized = normalizeKeyframes(node);
            var hash = String(U.hashString(normalized));
            if (!kfMap[hash]) { kfMap[hash] = []; }
            kfMap[hash].push(node);
        }

        var hashes = Object.keys(kfMap);
        for (var h = 0; h < hashes.length; h++) {
            var group = kfMap[hashes[h]];
            if (group.length < 2) { continue; }

            var names = group.map(function (n) { return n.name; });
            var edits = [];
            for (var r = 1; r < group.length; r++) {
                edits.push({ start: group[r].startIdx, end: group[r].endIdx, replacement: '' });
            }

            suggestions.push({
                id:          U.generateId('dup-kf'),
                type:        NODE.TOOL_DUPLICATES,
                description: t('tools.duplicates.sameKeyframes', { names: names.join(', ') }),
                enabled:     true,
                edits:       edits,
                diff: {
                    before: group.map(function (n) { return n.raw; }).join('\n\n'),
                    after:  group[0].raw
                }
            });
        }
    }

    /* ── Duplicate @font-face ── */

    function findDuplicateFontFaces(ast, suggestions) {
        var ffList = [];

        for (var i = 0; i < ast.length; i++) {
            if (ast[i].type === NODE.NODE_FONTFACE) { ffList.push(ast[i]); }
        }
        if (ffList.length < 2) { return; }

        var hashMap = {};
        for (var f = 0; f < ffList.length; f++) {
            var key  = U.normalizeDeclarations(ffList[f].declarations);
            var hash = String(U.hashString(key));
            if (!hashMap[hash]) { hashMap[hash] = []; }
            hashMap[hash].push(ffList[f]);
        }

        var hashes = Object.keys(hashMap);
        for (var h = 0; h < hashes.length; h++) {
            var group = hashMap[hashes[h]];
            if (group.length < 2) { continue; }

            var edits = [];
            for (var r = 1; r < group.length; r++) {
                edits.push({ start: group[r].startIdx, end: group[r].endIdx, replacement: '' });
            }

            suggestions.push({
                id:          U.generateId('dup-ff'),
                type:        NODE.TOOL_DUPLICATES,
                description: t('tools.duplicates.sameFontFace'),
                enabled:     true,
                edits:       edits,
                diff: {
                    before: group.map(function (n) { return n.raw; }).join('\n\n'),
                    after:  group[0].raw
                }
            });
        }
    }

    /* ── Helpers ── */

    /**
     * Walk an AST collecting all rule nodes, tracking @media context.
     * Callback receives (rule, contextKey) where contextKey is 'top' or
     * the normalised @media condition. Rules in different contexts are
     * never mixed during duplicate detection.
     */
    function collectRules(ast, callback, context) {
        var ctx = context || 'top';
        for (var i = 0; i < ast.length; i++) {
            var node = ast[i];
            if (node.type === NODE.NODE_RULE) {
                callback(node, ctx);
            } else if (node.type === NODE.NODE_MEDIA) {
                var mediaCtx = (node.condition || '').replace(/\s+/g, ' ').trim().toLowerCase();
                collectRules(node.rules || [], callback, '@media ' + mediaCtx);
            } else if (node.rules) {
                collectRules(node.rules, callback, ctx);
            }
        }
    }

    /**
     * Merge declarations from multiple rules (keep last value for each property).
     */
    function mergeDeclarations(rules) {
        var map    = {};
        var order  = [];

        for (var r = 0; r < rules.length; r++) {
            var decls = rules[r].declarations;
            for (var d = 0; d < decls.length; d++) {
                var prop = decls[d].property.toLowerCase().trim();
                if (!map[prop]) { order.push(prop); }
                map[prop] = decls[d];
            }
        }

        return order.map(function (p) { return map[p]; });
    }

    /**
     * Build a CSS rule string from selector + declarations.
     */
    function buildRuleString(selectorText, declarations) {
        var lines = [selectorText + ' {'];
        for (var i = 0; i < declarations.length; i++) {
            lines.push('    ' + declarations[i].property + ': ' + declarations[i].value + ';');
        }
        lines.push('}');
        return lines.join('\n');
    }

    /**
     * Normalise keyframes content for comparison.
     */
    function normalizeKeyframes(kfNode) {
        return kfNode.steps.map(function (step) {
            return step.selector + ':' + U.normalizeDeclarations(step.declarations);
        }).join('|');
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.duplicates = { analyze: analyze };

})();
