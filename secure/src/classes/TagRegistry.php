<?php

/**
 * TagRegistry — Single source of truth for ALL HTML tag classification.
 * 
 * Used by: addNode, editNode, editStructure, JsonToHtmlRenderer,
 *          _tag-selector.php, add-node.php modal.
 * 
 * NEVER define tag lists anywhere else. Always reference this class.
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
        'details', 'summary', 'dialog', 'select', 'option', 'optgroup', 'textarea',
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
        'fieldset', 'legend', 'details', 'summary', 'dialog', 'dt', 'dd',
        'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
        'b', 'i', 'u', 'small', 'mark', 'caption', 'select', 'optgroup', 'datalist'
    ];

    // =========================================================================
    // TAG PARAMETERS
    // =========================================================================

    /**
     * Mandatory parameters per tag type.
     * NOTE: 'alt' handled separately — auto-generated as translation key.
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
        'track' => ['src'],
        'link' => ['href', 'rel'],
    ];

    /**
     * Tags that get auto-generated alt translation key.
     */
    const TAGS_WITH_ALT = ['img', 'area'];

    /**
     * Reserved params: auto-managed by the translation system, cannot be set manually.
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
                    'dialog' => ['desc' => __admin('preview.tagDesc.dialog') ?? 'Dialog box'],
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
                    'optgroup' => ['desc' => __admin('preview.tagDesc.optgroup') ?? 'Option group'],
                    'fieldset' => ['desc' => __admin('preview.tagDesc.fieldset') ?? 'Field group'],
                    'legend' => ['desc' => __admin('preview.tagDesc.legend') ?? 'Fieldset caption'],
                    'datalist' => ['desc' => __admin('preview.tagDesc.datalist') ?? 'Autocomplete list'],
                    'output' => ['desc' => __admin('preview.tagDesc.output') ?? 'Calculation result'],
                    'progress' => ['desc' => __admin('preview.tagDesc.progress') ?? 'Progress bar'],
                    'meter' => ['desc' => __admin('preview.tagDesc.meter') ?? 'Scalar measurement'],
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
