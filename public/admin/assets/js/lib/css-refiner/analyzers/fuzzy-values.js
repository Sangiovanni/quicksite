/* =============================================================================
   CSS REFINER — Analyzer: Fuzzy Value Matching
   Finds declarations with close numeric values that could be unified.
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    var t    = CSSRefiner.t;
    var U    = CSSRefiner.Utils;
    var NODE = CSSRefiner.CONST;

    /**
     * @param {Array}  ast
     * @param {string} cssText
     * @param {Object} options  { tolerance: 2, unit: 'all' }
     * @return {Array} suggestions
     */
    function analyze(ast, cssText, options) {
        var filterUnit = (options && options.unit) ? options.unit : 'all';
        var toleranceOverride = (options && options.tolerance != null)
            ? options.tolerance : null;

        var suggestions = [];
        var entries     = [];

        /* Collect every numeric declaration from every rule */
        collectNumericEntries(ast, entries);

        /* If a specific unit is chosen, keep only that unit */
        if (filterUnit !== 'all') {
            entries = entries.filter(function (e) { return e.unit === filterUnit; });
        }

        /* Group by (property, unit, context) */
        var groups = groupByPropertyUnit(entries);
        var keys   = Object.keys(groups);

        for (var k = 0; k < keys.length; k++) {
            var bucket = groups[keys[k]];
            if (bucket.length < 2) { continue; }

            /* Determine the tolerance for this bucket's unit */
            var bucketUnit = bucket[0].unit;
            var tolerance;
            if (toleranceOverride !== null) {
                tolerance = toleranceOverride;
            } else {
                tolerance = NODE.FUZZY_TOLERANCE_MAP[bucketUnit] || NODE.DEFAULT_FUZZY_TOLERANCE_PX;
            }

            /* Sort by numeric value */
            bucket.sort(function (a, b) { return a.numericValue - b.numericValue; });

            /* Walk sorted list, compare adjacent entries */
            for (var i = 0; i < bucket.length; i++) {
                for (var j = i + 1; j < bucket.length; j++) {
                    var entryA = bucket[i];
                    var entryB = bucket[j];

                    /* skip identical values */
                    if (entryA.numericValue === entryB.numericValue) { continue; }

                    /* skip entries from the same rule */
                    if (entryA.rule === entryB.rule) { continue; }

                    var diff = Math.abs(entryB.numericValue - entryA.numericValue);
                    if (diff > tolerance) { break; } /* sorted, so no need to continue */

                    /* Suggest unifying to the LARGER value */
                    var suggested = Math.max(entryA.numericValue, entryB.numericValue);
                    var unit      = entryA.unit;
                    var suggestedStr = suggested + unit;

                    /* Build edit: replace the smaller value with the larger */
                    var target = (entryA.numericValue < entryB.numericValue) ? entryA : entryB;
                    var targetDeclRaw = target.decl.property + ': ' + target.decl.value;
                    var newDeclRaw    = target.decl.property + ': ' + suggestedStr;

                    /* Find exact position of this declaration in source */
                    var declPos = cssText.indexOf(targetDeclRaw, target.rule.startIdx);
                    if (declPos === -1) {
                        /* fallback: try without exact whitespace */
                        continue;
                    }

                    var edits = [{
                        start:       declPos,
                        end:         declPos + targetDeclRaw.length,
                        replacement: newDeclRaw
                    }];

                    suggestions.push({
                        id:             U.generateId('fuzzy'),
                        type:           NODE.TOOL_FUZZY_VALUES,
                        description:    t('tools.fuzzyValues.found', {
                            property:  entryA.decl.property,
                            valueA:    entryA.decl.value,
                            selectorA: entryA.rule.selectorText || '@-rule',
                            valueB:    entryB.decl.value,
                            selectorB: entryB.rule.selectorText || '@-rule'
                        }),
                        meta:           t('tools.fuzzyValues.diff', { diff: diff + unit }),
                        enabled:        true,
                        edits:          edits,
                        suggestedValue: suggestedStr,
                        diff: {
                            before: targetDeclRaw,
                            after:  newDeclRaw
                        }
                    });
                }
            }
        }

        return suggestions;
    }

    /* ── Helpers ── */

    /**
     * Collect all declarations that have a parseable numeric value.
     */
    function collectNumericEntries(ast, list, context) {
        var ctx = context || 'top';
        for (var i = 0; i < ast.length; i++) {
            var node = ast[i];

            if (node.type === NODE.NODE_RULE) {
                addNumericDecls(node, list, ctx);
            } else if (node.type === NODE.NODE_FONTFACE) {
                addNumericDecls(node, list, ctx);
            } else if (node.type === NODE.NODE_MEDIA) {
                var mediaCtx = (node.condition || '').replace(/\s+/g, ' ').trim().toLowerCase();
                collectNumericEntries(node.rules || [], list, '@media ' + mediaCtx);
            } else if (node.rules) {
                collectNumericEntries(node.rules, list, ctx);
            }
        }
    }

    function addNumericDecls(rule, list, context) {
        var decls = rule.declarations;
        if (!decls) { return; }

        for (var d = 0; d < decls.length; d++) {
            var parsed = U.parseNumericValue(decls[d].value.trim());
            if (!parsed || parsed.unit === '') { continue; }

            list.push({
                rule:         rule,
                decl:         decls[d],
                numericValue: parsed.number,
                unit:         parsed.unit,
                property:     decls[d].property.toLowerCase().trim(),
                context:      context
            });
        }
    }

    /**
     * Group entries by "property|unit|context" key.
     */
    function groupByPropertyUnit(entries) {
        var map = {};
        for (var i = 0; i < entries.length; i++) {
            var key = entries[i].property + '|' + entries[i].unit + '|' + entries[i].context;
            if (!map[key]) { map[key] = []; }
            map[key].push(entries[i]);
        }
        return map;
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.fuzzyValues = { analyze: analyze };

})();
