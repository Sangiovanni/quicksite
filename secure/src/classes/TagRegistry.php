<?php

/**
 * TagRegistry — Single source of truth for ALL HTML tag classification.
 *
 * Used by: addNode, editNode, editStructure, JsonToHtmlRenderer,
 *          _tag-selector.php, and the visual editor's client-side code
 *          (via editorPayload() → PreviewConfig.tagInfo → preview.js).
 *
 * NEVER define tag lists anywhere else. Always reference this class —
 * including from JavaScript, which reads editorPayload() rather than
 * keeping a copy.
 */
class TagRegistry
{
    // =========================================================================
    // SECURITY: Tags that are NEVER allowed
    // =========================================================================

    const BLOCKED_TAGS = [
        'script', 'noscript', 'style', 'template', 'slot',
        'object', 'embed', 'applet'
    ];

    /**
     * ⚠ NOT-BLOCKED-BUT-NOT-ALLOWED: `dialog`.
     *
     * BLOCKED_TAGS is a SECURITY list — every entry is there because emitting
     * it would let an author execute code, load a remote resource, or escape
     * the renderer. `dialog` does none of that; it is simply not usable, so it
     * lives in neither list and the "is it allowed?" gate refuses it as an
     * unknown tag.
     *
     * Why it is not usable: `<dialog>` is `display:none` until it is opened,
     * and opening one properly means calling `showModal()` / `show()`. Authors
     * cannot run JavaScript (`<script>` is blocked, `on*` handlers are refused
     * server-side, the custom-JS feature was removed in beta.3), and no QS.*
     * verb calls either method — so the tag renders as nothing an author can
     * ever reveal by the means the product gives them.
     */

    // =========================================================================
    // ALLOWED TAGS: All tags users can create/assign
    // Must NOT overlap with BLOCKED_TAGS.
    // =========================================================================

    const ALLOWED_TAGS = [
        // Layout
        'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
        'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
        // Lists
        'ul', 'ol', 'dl',
        // Table structure
        'table', 'thead', 'tbody', 'tfoot', 'tr',
        // Inline / text
        'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
        'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
        'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
        // Self-closing / void
        'img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col',
        'source', 'track', 'wbr',
        // Interactive
        // ⚠ `dialog` is deliberately ABSENT — see the note above BLOCKED_TAGS.
        'details', 'summary', 'select', 'option', 'optgroup', 'textarea',
        // Media / embed
        'iframe', 'video', 'audio', 'canvas', 'svg', 'picture',
        // Misc
        'progress', 'meter', 'output', 'datalist', 'colgroup'
    ];

    // =========================================================================
    // TAG CLASSIFICATION
    // =========================================================================

    /**
     * Block-level tags: textKey OPTIONAL if class is provided, REQUIRED if no class.
     */
    const BLOCK_TAGS = [
        'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
        'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
        'ul', 'ol', 'dl', 'table', 'thead', 'tbody', 'tfoot', 'tr'
    ];

    /**
     * Inline tags: textKey ALWAYS required (they display text).
     */
    const INLINE_TAGS = [
        'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
        'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
        'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address'
    ];

    /**
     * Self-closing / void elements: NO textKey needed (no text content).
     */
    const SELF_CLOSING_TAGS = [
        'img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col',
        'source', 'track', 'wbr'
    ];

    /**
     * Tags that can have children.
     */
    const CONTAINER_TAGS = [
        'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
        'ul', 'ol', 'dl', 'li', 'form', 'table', 'tr', 'thead', 'tbody', 'tfoot', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button',
        'blockquote', 'pre', 'label', 'td', 'th', 'figcaption', 'strong', 'em',
        'fieldset', 'legend', 'details', 'summary', 'dt', 'dd',
        'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
        'b', 'i', 'u', 'small', 'mark', 'caption', 'select', 'optgroup', 'datalist'
    ];

    // =========================================================================
    // TAG PARAMETERS
    // =========================================================================

    /**
     * Mandatory parameters per tag type.
     * NOTE: 'alt' is NOT here — it is optional, and it is offered through the
     * translation-key selector (see TRANSLATION_KEY_PARAMS below).
     *
     * ⚠ A mandatory param must be one the AUTHOR supplies a value for: the
     * writers reject an empty string as "missing" (addNode, editNode). A boolean
     * HTML attribute — `controls`, `open`, `multiple` — has no value to supply,
     * so listing one here makes the tag impossible to create. Those belong in
     * DEFAULT_PARAMS below.
     */
    const MANDATORY_PARAMS = [
        'a' => ['href'],
        'img' => ['src'],
        'input' => ['type'],
        'form' => ['action'],
        'iframe' => ['src'],
        'video' => ['src'],
        'audio' => ['src'],
        'source' => ['src'],
        'label' => ['for'],
        'select' => ['name'],
        'textarea' => ['name'],
        'area' => ['href'],
        // <track src> alone is invalid HTML in the common case: `kind` defaults
        // to "subtitles", and the spec REQUIRES srclang whenever kind is
        // subtitles. A track authored without them names no language, so a
        // player cannot label the track or decide when to offer it.
        'track' => ['src', 'kind', 'srclang'],
        'link' => ['href', 'rel'],
        // Required by HTML, and silently wrong without it rather than loudly:
        // a <meter> with no value renders as an empty gauge, and an <optgroup>
        // with no label renders as an unnamed group in the dropdown.
        'meter' => ['value'],
        'optgroup' => ['label'],
    ];

    /**
     * Parameters written onto a NEW node of this tag when the author did not
     * supply them — and only then. A default, never a requirement: the author
     * may change or remove it afterwards.
     *
     * This exists because "the tag needs this to work" and "the author must
     * choose a value" are different statements, and MANDATORY_PARAMS can only
     * make the second one. `<video src="…">` with nothing else is valid HTML
     * that browsers render as a blank rectangle with no play button, and
     * `<audio src="…">` with nothing else has no intrinsic size at all — it
     * renders as literally nothing. Neither is recoverable by the author:
     * `<script>` is blocked, `on*` handlers are refused, and the custom-JS
     * feature was removed in beta.3, so there is no code path from an authored
     * page to `play()`. `controls` is the only thing that makes the element
     * usable, and the editor was not emitting it.
     *
     * ⚠ VALUES ARE STRINGS, NOT `true`. The renderer emits a PHP boolean as a
     * bare attribute (`controls`) while the build compiler runs it through
     * `var_export` + `htmlspecialchars` and emits `controls="1"` — same meaning
     * to a browser, but a preview/build difference of exactly the kind beta.10
     * spent a release removing. `'controls'` as a string produces identical
     * markup from both, and HTML explicitly permits a boolean attribute to
     * carry its own name as its value.
     */
    const DEFAULT_PARAMS = [
        'video' => ['controls' => 'controls'],
        'audio' => ['controls' => 'controls'],
    ];

    /**
     * (Removed, S2.9) TAGS_WITH_ALT. It named the tags whose `alt` got a
     * server-generated translation key — a behaviour that no longer exists,
     * and its last two readers (addNode, editNode) are gone. What replaces it
     * is TRANSLATION_KEY_PARAMS below, which says which param on which tag,
     * rather than assuming the param is always `alt`.
     */

    /**
     * OPTIONAL params, per tag, whose value is a TRANSLATION KEY.
     *
     * The renderer already translates these attributes when the value looks
     * like a key (JsonToHtmlRenderer::renderAttribute → $translatableAttributes),
     * so the only thing missing was a way for the author to *choose* the key.
     * The editor renders one translation-key selector per entry — the same
     * searchable picker the component-variables panel and the Complex Element
     * wizards use, which can also create a key inline.
     *
     * ⚠ ADD A TAG HERE, NOT IN THE EDITOR. This map is emitted to the visual
     * editor by editorPayload() below; the client has no list of its own.
     *
     * `title` is in RESERVED_PARAMS — reserved means "the translation system
     * owns the value", not "the author may not have one". Offering it through
     * the key selector is exactly what the reservation was protecting.
     */
    const TRANSLATION_KEY_PARAMS = [
        'img'    => ['alt'],
        'area'   => ['alt'],
        'iframe' => ['title'],
    ];

    /**
     * Reserved params: auto-managed by the translation system, cannot be set
     * manually as free text. Reachable through the translation-key selector
     * where a tag declares one in TRANSLATION_KEY_PARAMS.
     */
    const RESERVED_PARAMS = [
        'placeholder', 'title', 'aria-label',
        'aria-placeholder', 'aria-description'
    ];

    // =========================================================================
    // VOID ELEMENTS (for the renderer)
    // =========================================================================

    /**
     * HTML void elements that cannot have closing tags.
     * Superset of SELF_CLOSING_TAGS — includes 'param' which isn't user-creatable.
     */
    const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr'
    ];

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    public static function isAllowed(string $tag): bool
    {
        return in_array(strtolower($tag), self::ALLOWED_TAGS, true);
    }

    public static function isBlocked(string $tag): bool
    {
        return in_array(strtolower($tag), self::BLOCKED_TAGS, true);
    }

    /**
     * Single "may this tag be emitted at all?" gate — the shared policy both
     * the renderer AND the compiler enforce so preview and build agree
     * (beta.10 F-g/F-h). Renderable iff the name is well-formed, the tag is
     * NOT blocked, AND it is on the allowlist.
     */
    public static function isRenderable(string $tag): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+$/i', $tag)
            && !self::isBlocked($tag)
            && self::isAllowed($tag);
    }

    public static function isSelfClosing(string $tag): bool
    {
        return in_array(strtolower($tag), self::SELF_CLOSING_TAGS, true);
    }

    public static function isContainer(string $tag): bool
    {
        return in_array(strtolower($tag), self::CONTAINER_TAGS, true);
    }

    public static function isVoidElement(string $tag): bool
    {
        return in_array(strtolower($tag), self::VOID_ELEMENTS, true);
    }

    /**
     * The params a new node of this tag receives when the author supplied none
     * of them. Returns only the ones actually absent, so an author who set
     * `controls` themselves — or deliberately left it off by passing something
     * else — is never overridden.
     *
     * @param array $given The params the author supplied
     * @return array The defaults to merge in (possibly empty)
     */
    public static function defaultParamsFor(string $tag, array $given): array
    {
        $defaults = self::DEFAULT_PARAMS[strtolower($tag)] ?? [];
        if ($defaults === []) {
            return [];
        }

        $missing = [];
        foreach ($defaults as $name => $value) {
            if (!array_key_exists($name, $given)) {
                $missing[$name] = $value;
            }
        }
        return $missing;
    }

    /**
     * Everything the VISUAL EDITOR needs to know about tags, in one array.
     *
     * Emitted into the preview page by
     * secure/admin/templates/pages/preview-config.php as
     * `PreviewConfig.tagInfo`, and read by preview.js as its `TAG_INFO`.
     *
     * ⚠ THIS METHOD EXISTS SO THERE IS NO SECOND COPY. preview.js used to
     * carry a hand-written mirror of these lists, and it had drifted: it
     * offered `embed` and `object` (both BLOCKED here), demanded `alt` on
     * `img` / `area` (never mandatory here), and had lost `dl`. Adding a tag
     * or a param is now ONE edit — this file — because the client has no list
     * to forget.
     *
     * Keys are the constant names verbatim so a reader can diff the two sides
     * by eye without a mapping table.
     */
    public static function editorPayload(): array
    {
        return [
            'ALLOWED_TAGS'            => self::ALLOWED_TAGS,
            'BLOCKED_TAGS'            => self::BLOCKED_TAGS,
            'BLOCK_TAGS'              => self::BLOCK_TAGS,
            'INLINE_TAGS'             => self::INLINE_TAGS,
            'SELF_CLOSING_TAGS'       => self::SELF_CLOSING_TAGS,
            'CONTAINER_TAGS'          => self::CONTAINER_TAGS,
            'MANDATORY_PARAMS'        => self::MANDATORY_PARAMS,
            'DEFAULT_PARAMS'          => self::DEFAULT_PARAMS,
            'TRANSLATION_KEY_PARAMS'  => self::TRANSLATION_KEY_PARAMS,
            'RESERVED_PARAMS'         => self::RESERVED_PARAMS,
        ];
    }

    /**
     * Get tag category for textKey logic: 'self-closing', 'inline', or 'block'.
     */
    public static function getCategory(string $tag): string
    {
        if (in_array($tag, self::SELF_CLOSING_TAGS, true)) return 'self-closing';
        if (in_array($tag, self::INLINE_TAGS, true)) return 'inline';
        if (in_array($tag, self::BLOCK_TAGS, true)) return 'block';
        return 'block';
    }

    /**
     * Tag categories with descriptions for the visual editor UI.
     * Returns: ['categoryId' => ['label' => ..., 'tags' => ['tagName' => [...], ...]]]
     * The __admin() function must be available when calling this.
     */
    public static function getUICategories(): array
    {
        return [
            'layout' => [
                'label' => __admin('preview.layoutTags') ?? 'Layout',
                'tags' => [
                    'div' => ['desc' => __admin('preview.tagDesc.div') ?? 'Generic container'],
                    'section' => ['desc' => __admin('preview.tagDesc.section') ?? 'Thematic grouping'],
                    'article' => ['desc' => __admin('preview.tagDesc.article') ?? 'Self-contained content'],
                    'header' => ['desc' => __admin('preview.tagDesc.header') ?? 'Introductory content'],
                    'footer' => ['desc' => __admin('preview.tagDesc.footer') ?? 'Footer content'],
                    'nav' => ['desc' => __admin('preview.tagDesc.nav') ?? 'Navigation links'],
                    'main' => ['desc' => __admin('preview.tagDesc.main') ?? 'Main content'],
                    'aside' => ['desc' => __admin('preview.tagDesc.aside') ?? 'Side content'],
                    'figure' => ['desc' => __admin('preview.tagDesc.figure') ?? 'Figure with caption'],
                    'figcaption' => ['desc' => __admin('preview.tagDesc.figcaption') ?? 'Figure caption'],
                ]
            ],
            'text' => [
                'label' => __admin('preview.textTags') ?? 'Text',
                'tags' => [
                    'p' => ['desc' => __admin('preview.tagDesc.p') ?? 'Paragraph'],
                    'h1' => ['desc' => __admin('preview.tagDesc.h1') ?? 'Heading level 1'],
                    'h2' => ['desc' => __admin('preview.tagDesc.h2') ?? 'Heading level 2'],
                    'h3' => ['desc' => __admin('preview.tagDesc.h3') ?? 'Heading level 3'],
                    'h4' => ['desc' => __admin('preview.tagDesc.h4') ?? 'Heading level 4'],
                    'h5' => ['desc' => __admin('preview.tagDesc.h5') ?? 'Heading level 5'],
                    'h6' => ['desc' => __admin('preview.tagDesc.h6') ?? 'Heading level 6'],
                    'span' => ['desc' => __admin('preview.tagDesc.span') ?? 'Inline container'],
                    'strong' => ['desc' => __admin('preview.tagDesc.strong') ?? 'Strong importance'],
                    'em' => ['desc' => __admin('preview.tagDesc.em') ?? 'Emphasis'],
                    'small' => ['desc' => __admin('preview.tagDesc.small') ?? 'Side comments'],
                    'mark' => ['desc' => __admin('preview.tagDesc.mark') ?? 'Highlighted text'],
                    'blockquote' => ['desc' => __admin('preview.tagDesc.blockquote') ?? 'Block quotation'],
                    'pre' => ['desc' => __admin('preview.tagDesc.pre') ?? 'Preformatted text'],
                    'code' => ['desc' => __admin('preview.tagDesc.code') ?? 'Code snippet'],
                    'q' => ['desc' => __admin('preview.tagDesc.q') ?? 'Inline quotation'],
                    'cite' => ['desc' => __admin('preview.tagDesc.cite') ?? 'Citation'],
                    'abbr' => ['desc' => __admin('preview.tagDesc.abbr') ?? 'Abbreviation'],
                    'time' => ['desc' => __admin('preview.tagDesc.time') ?? 'Date/time'],
                    'address' => ['desc' => __admin('preview.tagDesc.address') ?? 'Contact info'],
                ]
            ],
            'interactive' => [
                'label' => __admin('preview.interactiveTags') ?? 'Interactive',
                'tags' => [
                    'a' => ['desc' => __admin('preview.tagDesc.a') ?? 'Hyperlink', 'required' => true],
                    'button' => ['desc' => __admin('preview.tagDesc.button') ?? 'Clickable button'],
                    'details' => ['desc' => __admin('preview.tagDesc.details') ?? 'Disclosure widget'],
                    'summary' => ['desc' => __admin('preview.tagDesc.summary') ?? 'Details summary'],
                ]
            ],
            'list' => [
                'label' => __admin('preview.listTags') ?? 'Lists',
                'tags' => [
                    'ul' => ['desc' => __admin('preview.tagDesc.ul') ?? 'Unordered list'],
                    'ol' => ['desc' => __admin('preview.tagDesc.ol') ?? 'Ordered list'],
                    'li' => ['desc' => __admin('preview.tagDesc.li') ?? 'List item'],
                    'dl' => ['desc' => __admin('preview.tagDesc.dl') ?? 'Description list'],
                    'dt' => ['desc' => __admin('preview.tagDesc.dt') ?? 'Description term'],
                    'dd' => ['desc' => __admin('preview.tagDesc.dd') ?? 'Description detail'],
                ]
            ],
            'media' => [
                'label' => __admin('preview.mediaTags') ?? 'Media',
                'tags' => [
                    'img' => ['desc' => __admin('preview.tagDesc.img') ?? 'Image', 'required' => true],
                    'picture' => ['desc' => __admin('preview.tagDesc.picture') ?? 'Responsive images'],
                    'video' => ['desc' => __admin('preview.tagDesc.video') ?? 'Video player', 'required' => true],
                    'audio' => ['desc' => __admin('preview.tagDesc.audio') ?? 'Audio player', 'required' => true],
                    'iframe' => ['desc' => __admin('preview.tagDesc.iframe') ?? 'Embedded frame', 'required' => true],
                    'source' => ['desc' => __admin('preview.tagDesc.source') ?? 'Media source', 'required' => true],
                    'track' => ['desc' => __admin('preview.tagDesc.track') ?? 'Text tracks', 'required' => true],
                    'canvas' => ['desc' => __admin('preview.tagDesc.canvas') ?? 'Drawing canvas'],
                    'svg' => ['desc' => __admin('preview.tagDesc.svg') ?? 'SVG graphics'],
                ]
            ],
            'form' => [
                'label' => __admin('preview.formTags') ?? 'Form',
                'tags' => [
                    'form' => ['desc' => __admin('preview.tagDesc.form') ?? 'Form container', 'required' => true],
                    'input' => ['desc' => __admin('preview.tagDesc.input') ?? 'Input field', 'required' => true],
                    'textarea' => ['desc' => __admin('preview.tagDesc.textarea') ?? 'Text area', 'required' => true],
                    'label' => ['desc' => __admin('preview.tagDesc.label') ?? 'Form label', 'required' => true],
                    'select' => ['desc' => __admin('preview.tagDesc.select') ?? 'Dropdown', 'required' => true],
                    'option' => ['desc' => __admin('preview.tagDesc.option') ?? 'Select option'],
                    'optgroup' => ['desc' => __admin('preview.tagDesc.optgroup') ?? 'Option group', 'required' => true],
                    'fieldset' => ['desc' => __admin('preview.tagDesc.fieldset') ?? 'Field group'],
                    'legend' => ['desc' => __admin('preview.tagDesc.legend') ?? 'Fieldset caption'],
                    'datalist' => ['desc' => __admin('preview.tagDesc.datalist') ?? 'Autocomplete list'],
                    'output' => ['desc' => __admin('preview.tagDesc.output') ?? 'Calculation result'],
                    'progress' => ['desc' => __admin('preview.tagDesc.progress') ?? 'Progress bar'],
                    'meter' => ['desc' => __admin('preview.tagDesc.meter') ?? 'Scalar measurement', 'required' => true],
                ]
            ],
            'table' => [
                'label' => __admin('preview.tableTags') ?? 'Table',
                'tags' => [
                    'table' => ['desc' => __admin('preview.tagDesc.table') ?? 'Table container'],
                    'thead' => ['desc' => __admin('preview.tagDesc.thead') ?? 'Table header'],
                    'tbody' => ['desc' => __admin('preview.tagDesc.tbody') ?? 'Table body'],
                    'tfoot' => ['desc' => __admin('preview.tagDesc.tfoot') ?? 'Table footer'],
                    'tr' => ['desc' => __admin('preview.tagDesc.tr') ?? 'Table row'],
                    'th' => ['desc' => __admin('preview.tagDesc.th') ?? 'Header cell'],
                    'td' => ['desc' => __admin('preview.tagDesc.td') ?? 'Data cell'],
                    'caption' => ['desc' => __admin('preview.tagDesc.caption') ?? 'Table caption'],
                    'colgroup' => ['desc' => __admin('preview.tagDesc.colgroup') ?? 'Column group'],
                    'col' => ['desc' => __admin('preview.tagDesc.col') ?? 'Column'],
                ]
            ],
            'other' => [
                'label' => __admin('preview.otherTags') ?? 'Other',
                'tags' => [
                    'br' => ['desc' => __admin('preview.tagDesc.br') ?? 'Line break'],
                    'hr' => ['desc' => __admin('preview.tagDesc.hr') ?? 'Horizontal rule'],
                    'wbr' => ['desc' => __admin('preview.tagDesc.wbr') ?? 'Word break opportunity'],
                ]
            ],
        ];
    }
}
