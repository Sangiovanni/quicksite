/* =============================================================================
   CSS REFINER — Analyzer: Media Query Consolidation
   Merges identical @media blocks and flags close breakpoints.
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
     * @param {Object} options  { proximity: 50 } (px)
     * @return {Array} suggestions
     */
    function analyze(ast, cssText, options) {
        var proximity = (options && options.proximity != null)
            ? options.proximity
            : NODE.DEFAULT_BREAKPOINT_PROXIMITY;

        var suggestions = [];
        var mediaNodes  = [];

        /* Collect all @media nodes at top level */
        for (var i = 0; i < ast.length; i++) {
            if (ast[i].type === NODE.NODE_MEDIA) {
                mediaNodes.push(ast[i]);
            }
        }

        findExactDuplicateMedia(mediaNodes, suggestions, cssText);
        findCloseBreakpoints(mediaNodes, suggestions, cssText, proximity);

        return suggestions;
    }

    /* ── Exact duplicate @media blocks ── */

    function findExactDuplicateMedia(mediaNodes, suggestions, cssText) {
        var condMap = {};

        for (var i = 0; i < mediaNodes.length; i++) {
            var cond = normalizeCondition(mediaNodes[i].condition);
            if (!condMap[cond]) { condMap[cond] = []; }
            condMap[cond].push(mediaNodes[i]);
        }

        var keys = Object.keys(condMap);
        for (var k = 0; k < keys.length; k++) {
            var group = condMap[keys[k]];
            if (group.length < 2) { continue; }

            /* Merge all blocks into the first one, deduplicating inner rules */
            var first = group[0];
            var mergedInnerRaw = deduplicateInnerRules(group, cssText);

            var mergedRaw = '@media ' + first.condition + ' {\n' +
                mergedInnerRaw + '\n}';

            var edits = [];
            /* remove all except first */
            for (var r = 1; r < group.length; r++) {
                edits.push({ start: group[r].startIdx, end: group[r].endIdx, replacement: '' });
            }
            /* replace first with merged content */
            edits.push({ start: first.startIdx, end: first.endIdx, replacement: mergedRaw });

            var totalRules = 0;
            for (var c = 0; c < group.length; c++) {
                totalRules += group[c].rules ? group[c].rules.length : 0;
            }

            suggestions.push({
                id:          U.generateId('mq-dup'),
                type:        NODE.TOOL_MEDIA_QUERIES,
                description: t('tools.mediaQueries.exactDuplicate', {
                    count:     group.length,
                    condition: first.condition
                }),
                meta:        t('tools.mediaQueries.rulesInside', { count: totalRules }),
                enabled:     true,
                edits:       edits,
                diff: {
                    before: group.map(function (n) { return n.raw; }).join('\n\n'),
                    after:  mergedRaw
                }
            });
        }
    }

    /* ── Close breakpoints ── */

    function findCloseBreakpoints(mediaNodes, suggestions, cssText, proximity) {
        /* Parse breakpoints from UNIQUE conditions, but keep a map of cond→nodes */
        var bpList   = [];
        var seenCond = {};
        var condMap  = {};

        for (var i = 0; i < mediaNodes.length; i++) {
            var cond = normalizeCondition(mediaNodes[i].condition);
            if (!condMap[cond]) { condMap[cond] = []; }
            condMap[cond].push(mediaNodes[i]);

            if (seenCond[cond]) { continue; }
            seenCond[cond] = true;

            var bp = U.parseMediaBreakpoint(mediaNodes[i].condition);
            if (!bp) { continue; }

            bpList.push({
                node:      mediaNodes[i],
                condition: mediaNodes[i].condition,
                bp:        bp
            });
        }

        /* Sort by breakpoint value */
        bpList.sort(function (a, b) { return a.bp.value - b.bp.value; });

        /* Compare adjacent pairs */
        for (var j = 0; j < bpList.length - 1; j++) {
            var a = bpList[j];
            var b = bpList[j + 1];

            /* only compare same type + unit */
            if (a.bp.type !== b.bp.type || a.bp.unit !== b.bp.unit) { continue; }

            var diff = b.bp.value - a.bp.value;
            if (diff <= 0 || diff > proximity) { continue; }

            /* suggest consolidating to the larger value */
            var suggested = b.bp.value + b.bp.unit;
            var newCond   = '(' + b.bp.type + ': ' + suggested + ')';

            /* Build edits: rewrite condition of all @media nodes matching A */
            var edits  = [];
            var normA  = normalizeCondition(a.condition);
            var nodesA = condMap[normA] || [];

            for (var n = 0; n < nodesA.length; n++) {
                var node = nodesA[n];
                var raw  = cssText.substring(node.startIdx, node.endIdx);
                var braceOffset = raw.indexOf('{');
                if (braceOffset === -1) { continue; }

                /* Search for the condition only in the preamble (before '{') */
                var preamble = raw.substring(0, braceOffset);
                var condIdx  = preamble.indexOf(node.condition);
                if (condIdx === -1) { continue; }

                var condStart = node.startIdx + condIdx;
                var condEnd   = condStart + node.condition.length;
                edits.push({ start: condStart, end: condEnd, replacement: newCond });
            }

            suggestions.push({
                id:             U.generateId('mq-close'),
                type:           NODE.TOOL_MEDIA_QUERIES,
                description:    t('tools.mediaQueries.closeBreakpoints', {
                    condA: a.condition,
                    condB: b.condition,
                    diff:  diff + b.bp.unit
                }),
                meta:           t('tools.mediaQueries.suggestedBreakpoint', { value: suggested }),
                suggestedValue: suggested,
                enabled:        false,                   /* default off — user opt-in */
                edits:          edits,
                diff: {
                    before: a.condition + '\n' + b.condition,
                    after:  newCond
                }
            });
        }
    }

    /* ── Helpers ── */

    function normalizeCondition(cond) {
        return cond.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    /**
     * Extract the CSS text inside a @media block's braces.
     */
    function extractInner(cssText, node) {
        var openBrace = cssText.indexOf('{', node.startIdx);
        if (openBrace === -1) { return ''; }
        var closeBrace = node.endIdx - 1;
        while (closeBrace > openBrace && cssText[closeBrace] !== '}') { closeBrace--; }
        return cssText.substring(openBrace + 1, closeBrace).trim();
    }

    /**
     * Merge inner rules from multiple @media blocks, deduplicating
     * rules that share the same selector (last-value-wins per property).
     */
    function deduplicateInnerRules(mediaNodes, cssText) {
        var selectorOrder = [];
        var selectorMap   = {};

        for (var m = 0; m < mediaNodes.length; m++) {
            var rules = mediaNodes[m].rules || [];
            for (var r = 0; r < rules.length; r++) {
                var rule = rules[r];

                /* non-rule nodes (nested @keyframes, comments) — keep as raw */
                if (rule.type !== NODE.NODE_RULE || !rule.selectorText) {
                    var uid = '__raw_' + m + '_' + r;
                    selectorOrder.push(uid);
                    selectorMap[uid] = { raw: rule.raw || '' };
                    continue;
                }

                var key = rule.selectorText.trim().toLowerCase();
                if (!selectorMap[key]) {
                    selectorOrder.push(key);
                    selectorMap[key] = { selector: rule.selectorText, declMap: {}, declOrder: [] };
                }
                var entry = selectorMap[key];
                var decls = rule.declarations || [];
                for (var d = 0; d < decls.length; d++) {
                    var prop = decls[d].property.toLowerCase().trim();
                    if (!entry.declMap[prop]) { entry.declOrder.push(prop); }
                    entry.declMap[prop] = decls[d];
                }
            }
        }

        /* Rebuild inner CSS */
        var lines = [];
        for (var s = 0; s < selectorOrder.length; s++) {
            var entry = selectorMap[selectorOrder[s]];
            if (!entry || entry.raw !== undefined) {
                if (entry && entry.raw) {
                    lines.push('    ' + entry.raw.trim().replace(/\n/g, '\n    '));
                }
            } else {
                lines.push('    ' + entry.selector + ' {');
                for (var p = 0; p < entry.declOrder.length; p++) {
                    var decl = entry.declMap[entry.declOrder[p]];
                    lines.push('        ' + decl.property + ': ' + decl.value + ';');
                }
                lines.push('    }');
            }
        }
        return lines.join('\n');
    }

    /* ── Public API ── */
    CSSRefiner.Analyzers = CSSRefiner.Analyzers || {};
    CSSRefiner.Analyzers.mediaQueries = { analyze: analyze };

})();
