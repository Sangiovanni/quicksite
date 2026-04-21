/* =============================================================================
   CSS REFINER — Constants & Configuration
   ============================================================================= */
(function () {
    'use strict';

    window.CSSRefiner = window.CSSRefiner || {};

    CSSRefiner.CONST = Object.freeze({
        /* ── Analyzer defaults ── */
        DEFAULT_SIMILARITY_THRESHOLD:  80,
        MIN_SIMILARITY_THRESHOLD:      50,
        MAX_SIMILARITY_THRESHOLD:      100,

        DEFAULT_FUZZY_TOLERANCE_PX:    2,
        MIN_FUZZY_TOLERANCE:           0,
        MAX_FUZZY_TOLERANCE:           20,

        /* Per-unit default tolerances for fuzzy matching */
        FUZZY_TOLERANCE_MAP: {
            px:   2,
            em:   0.1,
            rem:  0.1,
            '%':  2,
            vw:   1,
            vh:   1,
            vmin: 1,
            vmax: 1,
            pt:   1,
            cm:   0.1,
            mm:   1,
            'in': 0.05,
            ch:   0.5,
            ex:   0.5,
            cap:  0.5,
            fr:   0.25,
            deg:  5,
            rad:  0.1,
            turn: 0.05,
            s:    0.1,
            ms:   50
        },

        /* Design tokens — minimum occurrences to suggest extraction */
        TOKENS_MIN_OCCURRENCES:        3,
        TOKENS_MIN_OCCURRENCES_MIN:    2,
        TOKENS_MIN_OCCURRENCES_MAX:    20,

        DEFAULT_BREAKPOINT_PROXIMITY:  50,
        MIN_BREAKPOINT_PROXIMITY:      0,
        MAX_BREAKPOINT_PROXIMITY:      200,

        /* ── CSS numeric units recognised by fuzzy analyser ── */
        NUMERIC_UNITS: [
            'px', 'em', 'rem', '%',
            'vw', 'vh', 'vmin', 'vmax',
            'pt', 'cm', 'mm', 'in',
            'ch', 'ex', 'cap',
            'fr', 'deg', 'rad', 'turn',
            's', 'ms'
        ],

        /* ── AST node types ── */
        NODE_RULE:      'rule',
        NODE_MEDIA:     'media',
        NODE_KEYFRAMES: 'keyframes',
        NODE_FONTFACE:  'font-face',
        NODE_COMMENT:   'comment',
        NODE_OTHER_AT:  'other-at',

        /* ── Tool identifiers ── */
        TOOL_DUPLICATES:       'duplicates',
        TOOL_NEAR_DUPLICATES:  'nearDuplicates',
        TOOL_FUZZY_VALUES:     'fuzzyValues',
        TOOL_MEDIA_QUERIES:    'mediaQueries',
        TOOL_EMPTY_RULES:      'emptyRules',
        TOOL_COLOR_NORMALIZE:  'colorNormalize',
        TOOL_DESIGN_TOKENS:    'designTokens',

        /* ── Local-storage keys ── */
        STORAGE_THEME: 'css-refiner-theme',
        STORAGE_LANG:  'css-refiner-lang',

        /* ── Pipeline ── */
        MAX_PIPELINE_ITERATIONS: 10,

        /* ── Misc ── */
        DEBOUNCE_MS: 300
    });

})();
