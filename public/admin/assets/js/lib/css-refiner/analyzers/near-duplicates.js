/* =============================================================================
   CSS REFINER — Analyzer: Near-Duplicate Detection
   Finds rules whose declarations are highly similar (≥ threshold %).
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;

    /**
     * @param {Array}  ast
     * @param {string} cssText     Original source
     * @param {Object} options     { threshold: 80 } (percent 0-100)
     * @return {Array} suggestions
     */
    function analyze(ast, cssText, options) {
        var threshold = (options && options.threshold != null)
            ? options.threshold
            : NODE.DEFAULT_SIMILARITY_THRESHOLD;
        var ignoreProps = (options && options.ignoreProps) ? options.ignoreProps : [];

        var rules       = [];
        var suggestions = [];

        collectRules(ast, rules);

        /* Pre-group by sorted property-name set to reduce O(N²) */
        var groups = groupByPropertySignature(rules, ignoreProps);
        var sigKeys = Object.keys(groups);

        for (var g = 0; g < sigKeys.length; g++) {
            var bucket = groups[sigKeys[g]];
            if (bucket.length < 2) { continue; }

            for (var i = 0; i < bucket.length; i++) {
                for (var j = i + 1; j < bucket.length; j++) {
                    var ruleA = bucket[i];
                    var ruleB = bucket[j];

                    /* skip if rules are in different @media contexts */
                    if (ruleA._context !== ruleB._context) { continue; }

                    /* skip if same selector (handled by duplicates tool) */
                    if (ruleA.selectorText.trim().toLowerCase() ===
                        ruleB.selectorText.trim().toLowerCase()) {
                        continue;
                    }

                    var sim = computeSimilarity(ruleA.declarations, ruleB.declarations, ignoreProps);
                    if (sim.percent < threshold) { continue; }

                    /* 100% identical is handled by duplicates tool */
                    if (sim.percent >= 100) { continue; }

                    var mergedDecls = mergeNearDuplicates(ruleA, ruleB);
                    var mergedSel   = combineSelectorTexts(ruleA, ruleB);
                    var mergedRaw   = buildRuleString(mergedSel, mergedDecls);

                    var edits = [
                        { start: ruleB.startIdx, end: ruleB.endIdx, replacement: '' },
                        { start: ruleA.startIdx, end: ruleA.endIdx, replacement: mergedRaw }
                    ];

                    var diffLines = buildDiffDescription(ruleA, ruleB, sim);

                    suggestions.push({
                        id:          U.generateId('near'),
                        type:        NODE.TOOL_NEAR_DUPLICATES,
                        description: t('tools.nearDuplicates.found', {
                            selectorA: ruleA.selectorText,
                            selectorB: ruleB.selectorText,
                            percent:   sim.percent
                        }),
                        meta:        diffLines,
                        enabled:     true,
                        edits:       edits,
                        diff: {
                            before: ruleA.raw + '\n\n' + ruleB.raw,
                            after:  mergedRaw
                        }
                    });
                }
            }
        }

        return suggestions;
    }

    /* ── Similarity computation ── */

    function computeSimilarity(declsA, declsB, ignoreProps) {
        var mapA = declMapByProp(declsA);
        var mapB = declMapByProp(declsB);

        var allProps = mergeKeys(mapA, mapB);
        var total    = 0;
        var matching = 0;
        var diffs    = [];

        for (var i = 0; i < allProps.length; i++) {
            var prop = allProps[i];
            if (ignoreProps && ignoreProps.indexOf(prop) !== -1) { continue; }
            total++;
            var vA   = mapA[prop];
            var vB   = mapB[prop];

            if (vA && vB && U.normalizeValue(vA) === U.normalizeValue(vB)) {
                matching++;
            } else {
                diffs.push({ property: prop, valueA: vA || null, valueB: vB || null });
            }
        }

        return {
            percent: total === 0 ? 0 : Math.round((matching / total) * 100),
            diffs:   diffs
        };
    }

    function declMapByProp(decls) {
        var map = {};
        for (var i = 0; i < decls.length; i++) {
            map[decls[i].property.toLowerCase().trim()] = decls[i].value;
        }
        return map;
    }

    function mergeKeys(a, b) {
        var set = {};
        var key;
        for (key in a) { if (a.hasOwnProperty(key)) { set[key] = true; } }
        for (key in b) { if (b.hasOwnProperty(key)) { set[key] = true; } }
        return Object.keys(set);
    }

    /* ── Merge helpers ── */

    function mergeNearDuplicates(ruleA, ruleB) {
        /* Take all properties from A, then add any from B that A doesn't have. */
        var map   = {};
        var order = [];
        var i, prop;

        for (i = 0; i < ruleA.declarations.length; i++) {
            prop = ruleA.declarations[i].property.toLowerCase().trim();
            map[prop] = ruleA.declarations[i];
            order.push(prop);
        }
        for (i = 0; i < ruleB.declarations.length; i++) {
            prop = ruleB.declarations[i].property.toLowerCase().trim();
            if (!map[prop]) {
                map[prop] = ruleB.declarations[i];
                order.push(prop);
            }
        }

        return order.map(function (p) { return map[p]; });
    }

    function combineSelectorTexts(ruleA, ruleB) {
        var sels = [];
        var i;
        for (i = 0; i < ruleA.selectors.length; i++) {
            if (sels.indexOf(ruleA.selectors[i]) === -1) { sels.push(ruleA.selectors[i]); }
        }
        for (i = 0; i < ruleB.selectors.length; i++) {
            if (sels.indexOf(ruleB.selectors[i]) === -1) { sels.push(ruleB.selectors[i]); }
        }
        return sels.join(',\n');
    }

    function buildRuleString(selectorText, declarations) {
        var lines = [selectorText + ' {'];
        for (var i = 0; i < declarations.length; i++) {
            lines.push('    ' + declarations[i].property + ': ' + declarations[i].value + ';');
        }
        lines.push('}');
        return lines.join('\n');
    }

    function buildDiffDescription(ruleA, ruleB, sim) {
        var lines = [];
        for (var i = 0; i < sim.diffs.length; i++) {
            var d = sim.diffs[i];
            if (d.valueA && d.valueB) {
                lines.push(t('tools.nearDuplicates.diffValues', {
                    property: d.property,
                    valueA:   d.valueA,
                    valueB:   d.valueB
                }));
            } else if (d.valueA) {
                lines.push(t('tools.nearDuplicates.onlyInA', {
                    selector: ruleA.selectorText
                }) + ': ' + d.property + ': ' + d.valueA);
            } else {
                lines.push(t('tools.nearDuplicates.onlyInB', {
                    selector: ruleB.selectorText
                }) + ': ' + d.property + ': ' + d.valueB);
            }
        }
        return lines.join('\n');
    }

    /* ── Pre-grouping ── */

    function groupByPropertySignature(rules, ignoreProps) {
        var groups = {};
        for (var i = 0; i < rules.length; i++) {
            var rule  = rules[i];
            var props = rule.declarations
                .map(function (d) { return d.property.toLowerCase().trim(); })
                .filter(function (p) { return !ignoreProps || ignoreProps.indexOf(p) === -1; })
                .sort()
                .join(',');
            /* use a loose signature: sorted property names */
            if (!groups[props]) { groups[props] = []; }
            groups[props].push(rule);
        }
        return groups;
    }

    function collectRules(ast, list, context) {
        var ctx = context || 'top';
        for (var i = 0; i < ast.length; i++) {
            var node = ast[i];
            if (node.type === NODE.NODE_RULE) {
                node._context = ctx;
                list.push(node);
            } else if (node.type === NODE.NODE_MEDIA) {
                var mediaCtx = (node.condition || '').replace(/\s+/g, ' ').trim().toLowerCase();
                collectRules(node.rules || [], list, '@media ' + mediaCtx);
            } else if (node.rules) {
                collectRules(node.rules, list, ctx);
            }
        }
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.nearDuplicates = { analyze: analyze };

})();
