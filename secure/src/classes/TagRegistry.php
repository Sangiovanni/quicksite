<?php

require_once __DIR__ . '/RegexPatterns.php';

/**
 * TagRegistry — Single source of truth for ALL HTML tag classification.
 *
 * Used by: addNode, editNode, editStructure, JsonToHtmlRenderer, JsonToPhpCompiler,
 *          the commands that make, copy or remove a node's translation keys,
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
     * server-side, and there is no custom-JS feature), and no QS.*
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
     * Parameters of which a tag needs ONE, per group: a `<source>` names its file by `src` (in a video or an
     * audio) or by `srcset` (in a picture), and neither alone is required. A group's params are named, in
     * order, as the form offers them. addNode refuses a node with none of a group, editNode refuses removing
     * the last one, and the editor's Add form asks for one of them (TAG_INFO).
     */
    const MANDATORY_ONE_OF = [
        'source' => [['src', 'srcset']],
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
     * feature does not exist, so there is no code path from an authored
     * page to `play()`. `controls` is the only thing that makes the element
     * usable, and the editor was not emitting it.
     *
     * ⚠ VALUES ARE STRINGS, NOT `true`. The renderer emits a PHP boolean as a
     * bare attribute (`controls`) while the build compiler runs it through
     * `var_export` + `htmlspecialchars` and emits `controls="1"` — same meaning
     * to a browser, but a preview/build difference of exactly the kind the
     * shared policies exist to remove. `'controls'` as a string produces identical
     * markup from both, and HTML explicitly permits a boolean attribute to
     * carry its own name as its value.
     */
    const DEFAULT_PARAMS = [
        'video' => ['controls' => 'controls'],
        'audio' => ['controls' => 'controls'],
    ];

    /**
     * No TAGS_WITH_ALT: no tag's `alt` gets a server-generated translation
     * key. TRANSLATION_KEY_PARAMS below says which param on which tag is a
     * translation key, rather than assuming the param is always `alt`.
     */

    /**
     * OPTIONAL params, per tag, whose value is a TRANSLATION KEY.
     *
     * The renderer already translates these attributes when the value looks
     * like a key (TRANSLATABLE_ATTRIBUTES below),
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

    /**
     * Attributes whose value is translated when it looks like a translation key: the
     * reserved ones, which the translation system fills, and `alt`, which an author may
     * also write as text. The renderer and the compiler translate this set (a preview and
     * a build say the same thing); the commands that make, copy or remove a node's keys
     * read it, and the editor's snippet preview through editorPayload(). In this order:
     * duplicateNode numbers the keys it makes in it, deleteNode answers the keys it
     * removes in it.
     */
    const TRANSLATABLE_ATTRIBUTES = ['alt', ...self::RESERVED_PARAMS];

    /**
     * Attributes written even when empty, where empty is the meaning: `alt=""` marks an
     * image as decorative (with no alt at all a screen reader reads the file name), and
     * `aria-label=""` likewise. Every other empty attribute drops, in a preview, in a
     * build and in the editor's snippet preview.
     */
    const EMPTY_MEANINGFUL_ATTRIBUTES = ['alt', 'aria-label'];

    /**
     * Elements whose text cannot show formatting: inside one (at any depth) a translation's markers
     * (textMarkers.php) are removed and the words kept. An option's and a textarea's text is plain; a select,
     * an optgroup and a datalist show their text only through their options; an iframe's own text is never
     * shown; and inside an svg a marker would end the drawing there, the rest of the page drawn outside it.
     * The renderer, the compiler, the editor's snippet preview and its update of a saved text read this list
     * (the editor through editorPayload()).
     */
    const PLAIN_TEXT_TAGS = ['option', 'textarea', 'select', 'optgroup', 'datalist', 'svg', 'iframe'];

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
     * Renderable iff the name is well-formed, the tag is
     * NOT blocked, AND it is on the allowlist.
     */
    public static function isRenderable(string $tag): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+$/iD', $tag)
            && !self::isBlocked($tag)
            && self::isAllowed($tag);
    }

    /**
     * Single "may this attribute NAME be emitted at all?" gate.
     *
     * The attribute-level sibling of isRenderable(). THREE writers ask it and
     * they must all get the same answer, or the same page says different
     * things in preview, in a build, and at the moment it is stored:
     *
     *   - JsonToHtmlRenderer::renderAttribute  — /p/<id>/ preview
     *   - JsonToPhpCompiler::compileTagNode    — the build
     *   - firstUnsafeParam                     — addNode / editNode / editStructure
     *
     * ⚠ A MALFORMED NAME IS DROPPED, NEVER ESCAPED. An attribute name is an
     * IDENTIFIER, not text: there is no encoding of `x" . phpinfo() . "y` that
     * means anything as an attribute, so escaping it would only preserve
     * nonsense and invite the reader to treat names as a value type. The
     * compiler emits the name into a double-quoted PHP string literal, so a
     * name carrying a quote used to close that literal and turn the rest into
     * executable PHP in the compiled page — the renderer refused it, the build
     * did not, and the build was the surface that ran.
     *
     * The charset lives in RegexPatterns ('html_attribute_name') so this rule
     * exists as ONE regex in the tree rather than one copy per consumer.
     */
    public static function isRenderableAttributeName(string $name): bool
    {
        return RegexPatterns::match('html_attribute_name', $name);
    }

    /**
     * The groups of MANDATORY_ONE_OF a tag's params hold none of (a param counts when it is set and not empty):
     * addNode refuses a node with one, editNode a node it would leave with one.
     */
    public static function missingOneOf(string $tag, array $params): array
    {
        $missing = [];
        foreach (self::MANDATORY_ONE_OF[$tag] ?? [] as $group) {
            $held = array_filter($group, static fn($p) => isset($params[$p]) && $params[$p] !== '');
            if ($held === []) {
                $missing[] = $group;
            }
        }
        return $missing;
    }

    /**
     * Every param a tag needs: its MANDATORY_PARAMS and each MANDATORY_ONE_OF group's. editNode's tag change
     * takes off the old tag's that the new tag does not need.
     */
    public static function neededParams(string $tag): array
    {
        $needed = self::MANDATORY_PARAMS[$tag] ?? [];
        foreach (self::MANDATORY_ONE_OF[$tag] ?? [] as $group) {
            foreach ($group as $param) {
                $needed[] = $param;
            }
        }
        return array_values(array_unique($needed));
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
     * $params without the reserved ones (RESERVED_PARAMS: the translation system owns their
     * value) — except those $tag declares in TRANSLATION_KEY_PARAMS, whose key the author
     * chooses in the editor (an iframe's title). addNode and editNode filter a tag's params
     * through this. A null $tag (not known yet) keeps those any tag declares: call it again
     * once the tag is known.
     */
    public static function withoutReservedParams(?string $tag, array $params): array
    {
        $chosen = $tag === null
            ? array_merge(...array_values(self::TRANSLATION_KEY_PARAMS))
            : self::TRANSLATION_KEY_PARAMS[strtolower($tag)] ?? [];
        foreach (self::RESERVED_PARAMS as $reserved) {
            if (!in_array($reserved, $chosen, true)) {
                unset($params[$reserved]);
            }
        }
        return $params;
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
        require_once __DIR__ . '/../functions/textMarkers.php';
        return [
            'ALLOWED_TAGS'            => self::ALLOWED_TAGS,
            'BLOCKED_TAGS'            => self::BLOCKED_TAGS,
            'SELF_CLOSING_TAGS'       => self::SELF_CLOSING_TAGS,
            'CONTAINER_TAGS'          => self::CONTAINER_TAGS,
            'MANDATORY_PARAMS'        => self::MANDATORY_PARAMS,
            'MANDATORY_ONE_OF'        => self::MANDATORY_ONE_OF,
            'DEFAULT_PARAMS'          => self::DEFAULT_PARAMS,
            'TRANSLATION_KEY_PARAMS'  => self::TRANSLATION_KEY_PARAMS,
            'RESERVED_PARAMS'         => self::RESERVED_PARAMS,
            'TRANSLATABLE_ATTRIBUTES' => self::TRANSLATABLE_ATTRIBUTES,
            'EMPTY_MEANINGFUL_ATTRIBUTES' => self::EMPTY_MEANINGFUL_ATTRIBUTES,
            'PLAIN_TEXT_TAGS'         => self::PLAIN_TEXT_TAGS,
            'TEXT_MARKERS'            => QS_TEXT_MARKERS,
        ];
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
                'label' => __admin('preview.layoutTags'),
                'tags' => [
                    'div' => ['desc' => __admin('preview.tagDesc.div')],
                    'section' => ['desc' => __admin('preview.tagDesc.section')],
                    'article' => ['desc' => __admin('preview.tagDesc.article')],
                    'header' => ['desc' => __admin('preview.tagDesc.header')],
                    'footer' => ['desc' => __admin('preview.tagDesc.footer')],
                    'nav' => ['desc' => __admin('preview.tagDesc.nav')],
                    'main' => ['desc' => __admin('preview.tagDesc.main')],
                    'aside' => ['desc' => __admin('preview.tagDesc.aside')],
                    'figure' => ['desc' => __admin('preview.tagDesc.figure')],
                    'figcaption' => ['desc' => __admin('preview.tagDesc.figcaption')],
                ]
            ],
            'text' => [
                'label' => __admin('preview.textTags'),
                'tags' => [
                    'p' => ['desc' => __admin('preview.tagDesc.p')],
                    'h1' => ['desc' => __admin('preview.tagDesc.h1')],
                    'h2' => ['desc' => __admin('preview.tagDesc.h2')],
                    'h3' => ['desc' => __admin('preview.tagDesc.h3')],
                    'h4' => ['desc' => __admin('preview.tagDesc.h4')],
                    'h5' => ['desc' => __admin('preview.tagDesc.h5')],
                    'h6' => ['desc' => __admin('preview.tagDesc.h6')],
                    'span' => ['desc' => __admin('preview.tagDesc.span')],
                    'strong' => ['desc' => __admin('preview.tagDesc.strong')],
                    'em' => ['desc' => __admin('preview.tagDesc.em')],
                    'b' => ['desc' => __admin('preview.tagDesc.b')],
                    'i' => ['desc' => __admin('preview.tagDesc.i')],
                    'u' => ['desc' => __admin('preview.tagDesc.u')],
                    'small' => ['desc' => __admin('preview.tagDesc.small')],
                    'mark' => ['desc' => __admin('preview.tagDesc.mark')],
                    'blockquote' => ['desc' => __admin('preview.tagDesc.blockquote')],
                    'pre' => ['desc' => __admin('preview.tagDesc.pre')],
                    'code' => ['desc' => __admin('preview.tagDesc.code')],
                    'q' => ['desc' => __admin('preview.tagDesc.q')],
                    'cite' => ['desc' => __admin('preview.tagDesc.cite')],
                    'abbr' => ['desc' => __admin('preview.tagDesc.abbr')],
                    'time' => ['desc' => __admin('preview.tagDesc.time')],
                    'address' => ['desc' => __admin('preview.tagDesc.address')],
                ]
            ],
            'interactive' => [
                'label' => __admin('preview.interactiveTags'),
                'tags' => [
                    'a' => ['desc' => __admin('preview.tagDesc.a'), 'required' => true],
                    'button' => ['desc' => __admin('preview.tagDesc.button')],
                    'details' => ['desc' => __admin('preview.tagDesc.details')],
                    'summary' => ['desc' => __admin('preview.tagDesc.summary')],
                ]
            ],
            'list' => [
                'label' => __admin('preview.listTags'),
                'tags' => [
                    'ul' => ['desc' => __admin('preview.tagDesc.ul')],
                    'ol' => ['desc' => __admin('preview.tagDesc.ol')],
                    'li' => ['desc' => __admin('preview.tagDesc.li')],
                    'dl' => ['desc' => __admin('preview.tagDesc.dl')],
                    'dt' => ['desc' => __admin('preview.tagDesc.dt')],
                    'dd' => ['desc' => __admin('preview.tagDesc.dd')],
                ]
            ],
            'media' => [
                'label' => __admin('preview.mediaTags'),
                'tags' => [
                    'img' => ['desc' => __admin('preview.tagDesc.img'), 'required' => true],
                    'picture' => ['desc' => __admin('preview.tagDesc.picture')],
                    'video' => ['desc' => __admin('preview.tagDesc.video'), 'required' => true],
                    'audio' => ['desc' => __admin('preview.tagDesc.audio'), 'required' => true],
                    'iframe' => ['desc' => __admin('preview.tagDesc.iframe'), 'required' => true],
                    'source' => ['desc' => __admin('preview.tagDesc.source'), 'required' => true],
                    'track' => ['desc' => __admin('preview.tagDesc.track'), 'required' => true],
                    'canvas' => ['desc' => __admin('preview.tagDesc.canvas')],
                    'svg' => ['desc' => __admin('preview.tagDesc.svg')],
                ]
            ],
            'form' => [
                'label' => __admin('preview.formTags'),
                'tags' => [
                    'form' => ['desc' => __admin('preview.tagDesc.form'), 'required' => true],
                    'input' => ['desc' => __admin('preview.tagDesc.input'), 'required' => true],
                    'textarea' => ['desc' => __admin('preview.tagDesc.textarea'), 'required' => true],
                    'label' => ['desc' => __admin('preview.tagDesc.label'), 'required' => true],
                    'select' => ['desc' => __admin('preview.tagDesc.select'), 'required' => true],
                    'option' => ['desc' => __admin('preview.tagDesc.option')],
                    'optgroup' => ['desc' => __admin('preview.tagDesc.optgroup'), 'required' => true],
                    'fieldset' => ['desc' => __admin('preview.tagDesc.fieldset')],
                    'legend' => ['desc' => __admin('preview.tagDesc.legend')],
                    'datalist' => ['desc' => __admin('preview.tagDesc.datalist')],
                    'output' => ['desc' => __admin('preview.tagDesc.output')],
                    'progress' => ['desc' => __admin('preview.tagDesc.progress')],
                    'meter' => ['desc' => __admin('preview.tagDesc.meter'), 'required' => true],
                ]
            ],
            'table' => [
                'label' => __admin('preview.tableTags'),
                'tags' => [
                    'table' => ['desc' => __admin('preview.tagDesc.table')],
                    'thead' => ['desc' => __admin('preview.tagDesc.thead')],
                    'tbody' => ['desc' => __admin('preview.tagDesc.tbody')],
                    'tfoot' => ['desc' => __admin('preview.tagDesc.tfoot')],
                    'tr' => ['desc' => __admin('preview.tagDesc.tr')],
                    'th' => ['desc' => __admin('preview.tagDesc.th')],
                    'td' => ['desc' => __admin('preview.tagDesc.td')],
                    'caption' => ['desc' => __admin('preview.tagDesc.caption')],
                    'colgroup' => ['desc' => __admin('preview.tagDesc.colgroup')],
                    'col' => ['desc' => __admin('preview.tagDesc.col')],
                ]
            ],
            'other' => [
                'label' => __admin('preview.otherTags'),
                'tags' => [
                    'br' => ['desc' => __admin('preview.tagDesc.br')],
                    'hr' => ['desc' => __admin('preview.tagDesc.hr')],
                    'wbr' => ['desc' => __admin('preview.tagDesc.wbr')],
                ]
            ],
        ];
    }
}
