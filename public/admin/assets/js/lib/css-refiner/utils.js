/* =============================================================================
   CSS REFINER — Utility functions
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    /* ── General helpers ── */

    /**
     * Debounce a function call.
     */
    function debounce(fn, ms) {
        var timer;
        return function () {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    /**
     * Simple djb2-style string hash → unsigned 32-bit integer.
     */
    function hashString(str) {
        var hash = 5381;
        for (var i = 0; i < str.length; i++) {
            hash = ((hash << 5) + hash + str.charCodeAt(i)) >>> 0;
        }
        return hash;
    }

    /**
     * Format a byte count for display.
     */
    function formatBytes(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    /**
     * Normalise whitespace in a declaration value for comparison.
     */
    function normalizeValue(val) {
        return val.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    /**
     * Normalise a full declaration block for hashing.
     * Sorts declarations alphabetically, lowercases, trims.
     */
    function normalizeDeclarations(declarations) {
        return declarations
            .map(function (d) { return d.property.toLowerCase().trim() + ':' + normalizeValue(d.value); })
            .sort()
            .join(';');
    }

    /**
     * Parse a numeric value + unit from a CSS value string.
     * Returns { number, unit } or null.
     */
    function parseNumericValue(value) {
        var match = value.match(/^([+-]?\d+(?:\.\d+)?)\s*(px|em|rem|%|vw|vh|vmin|vmax|pt|cm|mm|in|ch|ex|cap|fr|deg|rad|turn|s|ms)?$/i);
        if (!match) { return null; }
        return {
            number: parseFloat(match[1]),
            unit:   (match[2] || '').toLowerCase()
        };
    }

    /**
     * Extract the numeric breakpoint from a media condition string.
     * e.g. "(max-width: 768px)" → { type: 'max-width', value: 768, unit: 'px' }
     */
    function parseMediaBreakpoint(condition) {
        var match = condition.match(/(min-width|max-width|min-height|max-height)\s*:\s*(\d+(?:\.\d+)?)\s*(px|em|rem)/i);
        if (!match) { return null; }
        return {
            type:  match[1].toLowerCase(),
            value: parseFloat(match[2]),
            unit:  match[3].toLowerCase()
        };
    }

    /**
     * Apply an array of text edits (sorted by start desc) to a string.
     * Each edit: { start, end, replacement }
     * Edits must not overlap and must be sorted descending by start.
     */
    function applyEdits(original, edits) {
        var result = original;
        for (var i = 0; i < edits.length; i++) {
            var e = edits[i];
            result = result.substring(0, e.start) + e.replacement + result.substring(e.end);
        }
        return result;
    }

    /**
     * Check if two edit ranges overlap.
     */
    function editsOverlap(a, b) {
        return a.start < b.end && b.start < a.end;
    }

    /**
     * Generate a unique suggestion id.
     */
    var idCounter = 0;
    function generateId(prefix) {
        idCounter++;
        return prefix + '-' + idCounter;
    }

    /* ── Public API ── */
    CSSRefiner.Utils = {
        debounce:              debounce,
        hashString:            hashString,
        formatBytes:           formatBytes,
        normalizeValue:        normalizeValue,
        normalizeDeclarations: normalizeDeclarations,
        parseNumericValue:     parseNumericValue,
        parseMediaBreakpoint:  parseMediaBreakpoint,
        applyEdits:            applyEdits,
        editsOverlap:          editsOverlap,
        generateId:            generateId
    };

})();
