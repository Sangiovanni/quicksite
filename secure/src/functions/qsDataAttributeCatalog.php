<?php
/**
 * qsDataAttributeCatalog.php — Single source of truth for the QuickSite-
 * runtime `data-*` attributes used in page authoring.
 *
 * Read by (as of late beta.7):
 *   - secure/management/command/listDataBindings.php (admin payload for
 *     the in-editor autocomplete + reference docs)
 *
 * Read by (future):
 *   - The Add Element wizard's Advanced custom-params autocomplete
 *     (beta.7.1 — fed from listDataBindings)
 *   - docs/ADMIN_PANEL.md §10 (referenced; manually-curated prose links
 *     here for the canonical attribute list)
 *   - (stretch) JsonToHtmlRenderer console.warn for unknown data-qs-*
 *     attrs — gates on this catalog's `internal` set
 *
 * Each entry describes ONE attribute. See structure comment below.
 *
 * To add a new data-* attribute:
 *   1. Implement it (qs.js for runtime bindings, or wherever).
 *   2. Add a catalog entry here.
 *   3. The picker, the docs reference, and any future renderer
 *      validation pick it up automatically.
 *
 * Pattern mirrors qsVerbCatalog.php (shipped beta.7, commit 142c277) —
 * same justification: avoid duplicated allowlists drifting from each
 * other.
 *
 * Convention note (late beta.7): descriptions are inline English,
 * matching qsVerbCatalog's convention. i18n keys (per the original
 * design doc) were deferred to keep the catalog file readable + match
 * the existing project pattern. French translation can come later via
 * a wrapping translator if needed.
 */

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

/**
 * Return the full data-attribute catalog.
 *
 * Entry shape:
 *   name             string  the attribute name (e.g. 'data-state-show')
 *   description      string  one-sentence English description (picker tooltip)
 *   category         string  state | auth | storage | template | form | complex | internal
 *   valueShape       string  what the value field expects — drives the
 *                            autocomplete widget:
 *                              store-field-ref  → "storeId.fieldName"
 *                              storage-spec     → "loc:key" or "has:loc:key"
 *                              selector         → "#id" / ".class"
 *                              dot-path         → "data.path.to.field"
 *                              enum             → constrained list (valueOptions)
 *                              plain-string     → free text
 *                              boolean-string   → 'true' / 'false' literal
 *                              none             → no value (rare; chrome markers)
 *   valuePlaceholder string  placeholder text shown in the value input
 *   valueOptions     array   (enum only) allowed values list
 *   tagsAllowed      array   ['*'] for universal, else specific tags
 *                            (informational; not enforced at write today)
 *   companion        array   names of attributes commonly paired with this
 *                            one (drives the picker's "+ Add data-X" hint)
 *   internal         bool    true = editor chrome; hidden from user picker
 *                            by default (use ?includeInternal=1 to see all)
 *   docAnchor        string  markdown anchor in docs/ADMIN_PANEL.md (or
 *                            ARCHITECTURE.md) for "Full reference" link
 *   examplePayload   string  short usage snippet for tooltips / docs
 *   since            string  version the attribute was introduced
 *
 * @return array<int, array<string, mixed>>
 */
function qsDataAttributeCatalog(): array {
    return [
        // ─── STATE STORE BINDINGS (beta.7 #9 — 1ac06d6, bc5b0f3, 5eb3ce2) ───
        [
            'name' => 'data-state-value',
            'description' => 'Bind an element\'s textContent to a state-store scalar field. Updates on init / setState / fetchState.',
            'category' => 'state',
            'valueShape' => 'store-field-ref',
            'valuePlaceholder' => 'storeId.fieldName',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
            'examplePayload' => '<span data-state-value="people.total"></span>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-list',
            'description' => 'Bind a container to a state-store array field. First child is cloned as the per-item template; descendants use data-bind / data-bind-attr.',
            'category' => 'state',
            'valueShape' => 'store-field-ref',
            'valuePlaceholder' => 'storeId.fieldName',
            'tagsAllowed' => ['*'],
            'companion' => ['data-state-empty', 'data-bind', 'data-bind-attr'],
            'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
            'examplePayload' => '<ul data-state-list="people.items"><li><span data-bind="name"></span></li></ul>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-empty',
            'description' => 'Optional text shown inside a data-state-list container when the array is empty. Replaces the container\'s content with this string.',
            'category' => 'state',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'No results yet',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
            'examplePayload' => '<ul data-state-list="people.items" data-state-empty="No people found">…</ul>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-show',
            'description' => 'Toggle the standard `hidden` attribute on truthiness of a state-store field. Falsy (null / "" / 0 / false / []) hides; truthy shows. Handy for gating Next/Prev on cursors, Load-more on hasMore, counters on total>0.',
            'category' => 'state',
            'valueShape' => 'store-field-ref',
            'valuePlaceholder' => 'storeId.fieldName',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
            'examplePayload' => '<button data-state-show="people.nextPage">Next</button>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-show-empty',
            'description' => 'Inverse of data-state-show — visible when the referenced state-store field is FALSY (null / "" / 0 / false / empty array). Pair with data-state-show on the same field to render a "no data" fallback alongside the happy path. Also drives the resolver onMiss:render-empty fallback: when a route opts into render-empty and the resolver fails, the template renders with empty resolved vars and these elements become visible.',
            'category' => 'state',
            'valueShape' => 'store-field-ref',
            'valuePlaceholder' => 'storeId.fieldName',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
            'examplePayload' => '<div data-state-show-empty="product.name">Sorry, we couldn\'t find that product.</div>',
            'since' => 'v1.0.0-beta.8'
        ],
        [
            'name' => 'data-state-pagenav',
            'description' => 'Runtime-rendered numbered-page navigator bound to a state store. Reads totalPages to size itself; writes page on click then re-fetches. Smart-ellipsis windowing. Emitted by the paged-navigator complex element, also hand-authorable.',
            'category' => 'state',
            'valueShape' => 'store-field-ref',
            'valuePlaceholder' => 'storeId',
            'tagsAllowed' => ['nav'],
            'companion' => ['data-state-pagenav-page-field', 'data-state-pagenav-totalpages-field', 'data-state-pagenav-window', 'data-state-pagenav-prev-next'],
            'docAnchor' => 'ADMIN_PANEL.md#87-complex-element-wizard',
            'examplePayload' => '<nav data-state-pagenav="people"></nav>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-pagenav-page-field',
            'description' => 'Override the store field that holds the current page number (default: `page`). Companion to data-state-pagenav.',
            'category' => 'state',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'page',
            'tagsAllowed' => ['nav'],
            'companion' => ['data-state-pagenav'],
            'docAnchor' => 'ARCHITECTURE.md#8',
            'examplePayload' => '<nav data-state-pagenav="people" data-state-pagenav-page-field="currentPage"></nav>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-pagenav-totalpages-field',
            'description' => 'Override the store field that holds the page count (default: `totalPages`). Companion to data-state-pagenav. When missing or ≤ 1, the navigator hides itself.',
            'category' => 'state',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'totalPages',
            'tagsAllowed' => ['nav'],
            'companion' => ['data-state-pagenav'],
            'docAnchor' => 'ARCHITECTURE.md#8',
            'examplePayload' => '<nav data-state-pagenav="people" data-state-pagenav-totalpages-field="pageCount"></nav>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-pagenav-window',
            'description' => 'How many sibling pages to show on each side of the current page before ellipsing (default: 2). Companion to data-state-pagenav. `0` collapses to `1 … current … N`.',
            'category' => 'state',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => '2',
            'tagsAllowed' => ['nav'],
            'companion' => ['data-state-pagenav'],
            'docAnchor' => 'ARCHITECTURE.md#8',
            'examplePayload' => '<nav data-state-pagenav="people" data-state-pagenav-window="3"></nav>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-state-pagenav-prev-next',
            'description' => 'Add ‹ Prev / Next › chevrons to the navigator. Set to literal "true" to enable; omit or set anything else to disable. Companion to data-state-pagenav.',
            'category' => 'state',
            'valueShape' => 'enum',
            'valueOptions' => ['true', 'false'],
            'valuePlaceholder' => 'true',
            'tagsAllowed' => ['nav'],
            'companion' => ['data-state-pagenav'],
            'docAnchor' => 'ARCHITECTURE.md#8',
            'examplePayload' => '<nav data-state-pagenav="people" data-state-pagenav-prev-next="true"></nav>',
            'since' => 'v1.0.0-beta.7'
        ],

        // ─── AUTH-STATE BINDINGS (beta.7 Tier 2 — 1c884ab) ───
        [
            'name' => 'data-auth-show',
            'description' => 'Show this element only when logged IN or OUT. Auth sugar over data-storage-show (presence of a token). Requires data-auth-source on this element or an ancestor.',
            'category' => 'auth',
            'valueShape' => 'enum',
            'valueOptions' => ['in', 'out'],
            'valuePlaceholder' => 'in',
            'tagsAllowed' => ['*'],
            'companion' => ['data-auth-source'],
            'docAnchor' => 'ADMIN_PANEL.md#95-auth-flows',
            'examplePayload' => '<button data-auth-show="in" data-auth-source="localStorage:authToken">Logout</button>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-auth-source',
            'description' => 'Where the token lives for data-auth-show resolution. Set once on a wrapper (descendants inherit) or per element. Format: storage:key.',
            'category' => 'auth',
            'valueShape' => 'storage-spec',
            'valuePlaceholder' => 'localStorage:authToken',
            'tagsAllowed' => ['*'],
            'companion' => ['data-auth-show'],
            'docAnchor' => 'ADMIN_PANEL.md#95-auth-flows',
            'examplePayload' => '<body data-auth-source="localStorage:authToken">…</body>',
            'since' => 'v1.0.0-beta.7'
        ],

        // ─── GENERIC STORAGE BINDINGS (beta.7 Tier 2 — 1c884ab) ───
        [
            'name' => 'data-storage-show',
            'description' => 'Generic presence-based show/hide on any localStorage / sessionStorage key. Format: "has:storage:key" (show when present) or "missing:storage:key" (show when absent). Re-applies on qs:auth:saved / qs:auth:cleared / qs:storage:changed.',
            'category' => 'storage',
            'valueShape' => 'storage-spec',
            'valuePlaceholder' => 'has:localStorage:userPrefs',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#95-auth-flows',
            'examplePayload' => '<div data-storage-show="has:localStorage:userPrefs">…</div>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-storage-value',
            'description' => 'Set the element\'s text to a stored value from localStorage / sessionStorage. Format: storage:key. Re-applies on qs:auth:saved / qs:auth:cleared.',
            'category' => 'storage',
            'valueShape' => 'storage-spec',
            'valuePlaceholder' => 'localStorage:userEmail',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#95-auth-flows',
            'examplePayload' => '<span data-storage-value="localStorage:userEmail"></span>',
            'since' => 'v1.0.0-beta.7'
        ],

        // ─── TEMPLATE / BINDING PRIMITIVES (predates beta.7; still load-bearing) ───
        [
            'name' => 'data-bind',
            'description' => 'Per-item template field. Inside a data-state-list or componentList template, the descendant\'s textContent gets set to the named field from each data item.',
            'category' => 'template',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'fieldName',
            'tagsAllowed' => ['*'],
            'companion' => ['data-bind-attr', 'data-state-list', 'data-list-template'],
            'docAnchor' => 'ADMIN_PANEL.md#92-component-list-binding',
            'examplePayload' => '<span data-bind="name"></span>',
            'since' => 'pre-beta.7'
        ],
        [
            'name' => 'data-bind-attr',
            'description' => 'Variant of data-bind that sets the named ATTRIBUTE instead of textContent. Pair with data-bind to pick which field provides the value.',
            'category' => 'template',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'src',
            'tagsAllowed' => ['*'],
            'companion' => ['data-bind'],
            'docAnchor' => 'ADMIN_PANEL.md#92-component-list-binding',
            'examplePayload' => '<img data-bind="src" data-bind-attr="src">',
            'since' => 'pre-beta.7'
        ],
        [
            'name' => 'data-list-template',
            'description' => 'Marks a hidden child element as the template that QS.applyBindings (componentList renderMode) clones per data item. Usually paired with style="display:none" so the template stays invisible until the first render.',
            'category' => 'template',
            'valueShape' => 'boolean-string',
            'valueOptions' => ['true'],
            'valuePlaceholder' => 'true',
            'tagsAllowed' => ['*'],
            'docAnchor' => 'ADMIN_PANEL.md#92-component-list-binding',
            'examplePayload' => '<div data-list-template="true" style="display:none">…</div>',
            'since' => 'pre-beta.7'
        ],

        // ─── FORM VALIDATION (predates beta.7) ───
        [
            'name' => 'data-error-for',
            'description' => 'Container for QS.validate error messages. Set the value to the input\'s `name` attribute. On invalid submit, QS.validate writes the browser\'s validation message into this element.',
            'category' => 'form',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'email',
            'tagsAllowed' => ['span', 'div', 'p'],
            'docAnchor' => 'ADMIN_PANEL.md#87-complex-element-wizard',
            'examplePayload' => '<span data-error-for="email"></span>',
            'since' => 'pre-beta.7'
        ],

        // ─── COMPLEX ELEMENT MARKERS (beta.7 — 8129b26) ───
        [
            'name' => 'data-qs-complex',
            'description' => 'Marker on the root of a complex-element subtree. Tells the editor "this subtree is a complex element of kind X" — enables features like the Translate-from-CSV workflow (Table only today; future kinds opt in by stamping the same pair).',
            'category' => 'complex',
            'valueShape' => 'enum',
            'valueOptions' => ['table'],
            'valuePlaceholder' => 'table',
            'tagsAllowed' => ['*'],
            'companion' => ['data-qs-complex-id'],
            'docAnchor' => 'ADMIN_PANEL.md#87-complex-element-wizard',
            'examplePayload' => '<table data-qs-complex="table" data-qs-complex-id="q1Sales">…</table>',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-qs-complex-id',
            'description' => 'Companion to data-qs-complex — identifies the structure for cross-language lookup (e.g. "Translate from CSV" matches by id, not by DOM path).',
            'category' => 'complex',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => 'structure-id',
            'tagsAllowed' => ['*'],
            'companion' => ['data-qs-complex'],
            'docAnchor' => 'ADMIN_PANEL.md#87-complex-element-wizard',
            'examplePayload' => '<table data-qs-complex="table" data-qs-complex-id="q1Sales">…</table>',
            'since' => 'v1.0.0-beta.7'
        ],

        // ─── EDITOR CHROME (internal; hidden from user picker by default) ───
        [
            'name' => 'data-qs-textkey',
            'description' => 'Editor-only selection wrapper for translation-key text. Auto-emitted by the renderer in editor mode so Text-tool clicks land on the right element. Users should NOT author this — it\'s chrome.',
            'category' => 'internal',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => '(editor-only)',
            'tagsAllowed' => ['*'],
            'internal' => true,
            'docAnchor' => 'ADMIN_PANEL.md#88-text-authoring',
            'examplePayload' => '(emitted by JsonToHtmlRenderer::renderText in editor mode)',
            'since' => 'pre-beta.7'
        ],
        [
            'name' => 'data-qs-raw',
            'description' => 'Editor-only marker on data-qs-textkey wrappers that hold a __RAW__/__LIT__ literal (vs a translation key). Tells the Text-tool to save via editStructure (literal write) instead of setTranslationKeys.',
            'category' => 'internal',
            'valueShape' => 'enum',
            'valueOptions' => ['true'],
            'valuePlaceholder' => '(editor-only)',
            'tagsAllowed' => ['*'],
            'internal' => true,
            'docAnchor' => 'ADMIN_PANEL.md#88-text-authoring',
            'examplePayload' => '(emitted by JsonToHtmlRenderer::renderText in editor mode for __RAW__ text)',
            'since' => 'v1.0.0-beta.7'
        ],
        [
            'name' => 'data-qs-textonly',
            'description' => 'Editor-only marker on text-node wrappers used by the JS-mode click handler to walk UP to the nearest real tag node (so clicking visible text inside a button selects the button, not its inner span).',
            'category' => 'internal',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => '(editor-only)',
            'tagsAllowed' => ['*'],
            'internal' => true,
            'docAnchor' => 'ARCHITECTURE.md#1',
            'examplePayload' => '(emitted by JsonToHtmlRenderer in editor mode)',
            'since' => 'pre-beta.7'
        ],
        [
            'name' => 'data-qs-node',
            'description' => 'Editor-only node-path marker (e.g. "0.1.2"). Lets the editor map an iframe click back to the exact JSON node and round-trip selection.',
            'category' => 'internal',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => '(editor-only)',
            'tagsAllowed' => ['*'],
            'internal' => true,
            'docAnchor' => 'ARCHITECTURE.md#1',
            'examplePayload' => '(emitted by JsonToHtmlRenderer in editor mode)',
            'since' => 'pre-beta.7'
        ],
        [
            'name' => 'data-qs-struct',
            'description' => 'Editor-only struct-path marker. Companion to data-qs-node for tree addressing in the editor preview.',
            'category' => 'internal',
            'valueShape' => 'plain-string',
            'valuePlaceholder' => '(editor-only)',
            'tagsAllowed' => ['*'],
            'internal' => true,
            'docAnchor' => 'ARCHITECTURE.md#1',
            'examplePayload' => '(emitted by JsonToHtmlRenderer in editor mode)',
            'since' => 'pre-beta.7'
        ],
    ];
}

/**
 * Return just the attribute names — useful for renderer-side validation
 * (future "warn on unknown data-qs-*" feature) and for the picker's
 * starts-with autocomplete.
 *
 * @param bool $includeInternal include editor-chrome attrs in the result
 * @return array<int, string>
 */
function qsDataAttributeNames(bool $includeInternal = false): array {
    $names = [];
    foreach (qsDataAttributeCatalog() as $entry) {
        if (!$includeInternal && !empty($entry['internal'])) {
            continue;
        }
        $names[] = $entry['name'];
    }
    return $names;
}
