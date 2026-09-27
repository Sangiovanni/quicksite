<?php
/**
 * utilsStyleManagement.php
 * 
 * Shared helpers for CSS file path resolution, locking, and dual-write
 * operations (live stylesheet + project backup copy).
 * 
 * All CSS write commands should use these helpers to guarantee both the live
 * stylesheet and the project backup copy stay in sync.
 */

/**
 * Maximum bytes any CSS write may land on disk, and the largest stylesheet the
 * CssParser will process. The two are ONE number on purpose: every writer must
 * produce a file the parser can then read back.
 *
 * Sized to the parser's real capacity, not to taste. CssParser peaks at ~140-210x
 * the input in memory (block-tree + substr copies), so at the install's 128 MB
 * limit the 8.0.30 FLOOR fatals around 0.9 MB. 512 KB parses at ~77 MB peak on the
 * floor (≈40% headroom) and is still 12x the largest real stylesheet on this
 * install (quicksite, 40 KB). A cap the floor cannot parse would let one oversized
 * write break every CssParser-using command for that project.
 */
const CSS_MAX_BYTES = 512 * 1024;

/**
 * Brace confinement. `{` and `}` are the only characters that open or close a
 * CSS block, so a selector / media prelude / variable name / declaration that
 * carries either can break out of its rule and emit arbitrary CSS. No legitimate
 * value in any of those positions contains a brace — the `>` child combinator,
 * quotes, `[attr="x"]` selectors, `var()`, `calc()` are all fine and pass. This is
 * the CSS-structural guard only; HTML metacharacters are handled at the render
 * boundary, not here, because CSS values legitimately contain quotes.
 *
 * @param string $fragment A single CSS input (selector, media prelude, variable
 *                         name/value, or declaration block) — never a whole sheet.
 * @return bool true if safe to emit, false if it must be refused.
 */
function qs_css_confine(string $fragment): bool {
    return strpos($fragment, '{') === false && strpos($fragment, '}') === false;
}

/**
 * The constructs no project stylesheet may hold: ONE list for every path that puts
 * CSS text into a stylesheet — editStyles, setStyleRule, setKeyframes,
 * setRootVariables, injectSnippetCss and the import's content check — so a sheet
 * one of them accepts is never one another refuses. qs_css_first_danger() runs each
 * entry on the normalised copy (qs_css_normalize_for_scan), where a comment between
 * a keyword's letters or an escape inside them no longer hides it.
 *
 * `@import` is refused only when it names a scheme or another host: a relative
 * import is legal in a sheet and inert inside a rule, while a remote one loads a
 * stylesheet from elsewhere, against the dependency-free policy. CSS needs no
 * whitespace after the keyword, so the entry needs none either.
 */
const QS_CSS_DENYLIST = [
    '/javascript\s*:/i'           => 'JavaScript protocol',
    '/vbscript\s*:/i'             => 'VBScript protocol',
    '/expression\s*\(/i'          => 'CSS expression (IE-specific JS)',
    '/(?<!scroll-)behavior\s*:/i' => 'CSS behavior (IE-specific)',
    '/-moz-binding\s*:/i'         => 'XBL binding (Firefox-specific)',
    '/@import\s*(?:url\(\s*)?["\']?\s*(?:[a-z][a-z0-9+.\-]*:|\/\/)/i' => 'Remote @import (external stylesheet — blocked by the dependency-free policy)',
    '/data\s*:\s*text\/html/i'    => 'Data URI with HTML',
    '/<\s*script/i'               => 'HTML script tag',
    '/<\s*style/i'                => 'HTML style tag',
];

/**
 * The first dangerous construct in CSS text a writer is about to store, or null.
 *
 * Pass the text as it will sit in the stylesheet: a whole sheet, or the rule,
 * block or declaration a writer adds with its pieces joined the way the writer
 * joins them, because a denylisted sequence can span two pieces — a variable's
 * name and its value, say, which the writer joins with `: `.
 *
 * A PHP opening tag is tested on the raw bytes with the import's own test
 * (qs_policy_has_php_open_tag): the import refuses a stylesheet that holds one, so
 * a sheet any writer accepts always imports again.
 *
 * @param string $css CSS text as it will be written.
 * @return string|null What was found, worded for the refusal; null when clean.
 */
function qs_css_first_danger(string $css): ?string {
    require_once __DIR__ . '/filePolicy.php';   // qs_policy_has_php_open_tag
    if (qs_policy_has_php_open_tag($css)) {
        return 'PHP opening tag';
    }
    $scan = qs_css_normalize_for_scan($css);
    foreach (QS_CSS_DENYLIST as $pattern => $description) {
        if (preg_match($pattern, $scan)) {
            return $description;
        }
    }
    return null;
}

/**
 * Normalise CSS for a SECURITY SCAN only (never for writing): the text the browser
 * parses, with its comments removed and its escapes decoded. A byte-level denylist
 * misses the two things CSS lets you write without changing meaning:
 *   - comments between tokens:   `behavior/**​/:`  ->  `behavior:`
 *   - escapes inside identifiers: `b\65 havior`    ->  `behavior`
 *
 * A comment runs from where the CSS tokenizer reads one. Inside a quoted string
 * and inside an unquoted url(…) the two characters that open a comment are
 * ordinary text: a comment opened there never reaches past the string or the URL,
 * because removing text beyond it that the browser still reads would hide that
 * text from the scan. A comment opened AND closed inside one is removed from it
 * all the same — the way a lax parser read it — which only joins text the scan then
 * sees. The tokenizer's own rules decide where those two contexts begin and end — a
 * line break ends a string, and `url(` opens an unquoted URL only when the
 * identifier before the parenthesis is exactly `url`.
 *
 * An escape — a backslash with up to six hex digits and one optional whitespace, or
 * with any other character but a line break — is decoded everywhere, strings and
 * url(…) included, because the browser decodes it there too. The dangerous keywords
 * are ASCII, so ASCII is what must decode faithfully; a NUL escape decodes to
 * nothing, which joins its neighbours — the strict side. The copy is used only to run
 * the patterns; the original bytes are what is stored.
 *
 * @param string $css Raw CSS to scan.
 * @return string The comment-free, escape-decoded copy for pattern matching.
 */
function qs_css_normalize_for_scan(string $css): string {
    $out   = '';
    $len   = strlen($css);
    $i     = 0;
    $ident = '';     // the identifier ending at $i, decoded and lower-cased (capped: only "url" matters)
    $open  = true;   // false when that identifier follows `#` or `@`: a hash or an at-keyword, never a function
    while ($i < $len) {
        // Ordinary text, up to the next character that can open a comment, a string,
        // an escape or a function's parenthesis.
        $run = strcspn($css, "/\"'\\(", $i);
        if ($run > 0) {
            $chunk = substr($css, $i, $run);
            $out  .= $chunk;
            $k = $run;
            while ($k > 0 && qs_css_is_name_byte($chunk[$k - 1])) {
                $k--;
            }
            if ($k === 0) {
                $ident .= strtolower($chunk);
            } else {
                $ident = strtolower(substr($chunk, $k));
                $open  = $chunk[$k - 1] !== '#' && $chunk[$k - 1] !== '@';
            }
            if (strlen($ident) > 3) {
                $ident = '....';
            }
            $i += $run;
            continue;
        }
        $c = $css[$i];
        if ($c === '/') {
            if ($i + 1 < $len && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 2;
            } else {
                $out .= '/';
                $i++;
            }
            $ident = '';
            $open  = true;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $out  .= qs_css_scan_string($css, $i);
            $ident = '';
            $open  = true;
            continue;
        }
        if ($c === '\\') {
            if ($i + 1 < $len && strpos("\n\r\f", $css[$i + 1]) === false) {
                // A valid escape is part of the identifier it sits in.
                $decoded = qs_css_scan_escape($css, $i);
                $out    .= $decoded;
                $ident  .= strtolower($decoded);
                if (strlen($ident) > 3) {
                    $ident = '....';
                }
            } else {
                $out  .= '\\';
                $i++;
                $ident = '';
                $open  = true;
            }
            continue;
        }
        // $c === '(': an unquoted url(…) is read to its closing parenthesis, whole.
        $out .= '(';
        $i++;
        if ($open && $ident === 'url') {
            $out .= qs_css_scan_url($css, $i);
        }
        $ident = '';
        $open  = true;
    }
    return $out;
}

/** A byte that continues a CSS identifier: an ASCII letter or digit, `-`, `_`, or any non-ASCII byte. */
function qs_css_is_name_byte(string $b): bool {
    return ($b >= 'a' && $b <= 'z') || ($b >= 'A' && $b <= 'Z') || ($b >= '0' && $b <= '9')
        || $b === '-' || $b === '_' || ord($b) >= 0x80;
}

/**
 * Decode the escape whose backslash is at $i, for the scan, and move $i past it.
 * The caller has established that a character follows and that it is not a line break.
 */
function qs_css_scan_escape(string $css, int &$i): string {
    $len = strlen($css);
    $i++;
    if ($i >= $len) {
        return '';
    }
    $hex = strspn($css, '0123456789abcdefABCDEF', $i, 6);
    if ($hex === 0) {
        return $css[$i++];
    }
    $cp = hexdec(substr($css, $i, $hex));
    $i += $hex;
    if ($i < $len) {
        if ($css[$i] === "\r" && $i + 1 < $len && $css[$i + 1] === "\n") {
            $i += 2;
        } elseif (strpos(" \t\n\r\f", $css[$i]) !== false) {
            $i++;
        }
    }
    if ($cp === 0) {
        return '';
    }
    if ($cp >= 0x20 && $cp <= 0x7E) {
        return chr($cp);
    }
    $replacement = "\xEF\xBF\xBD";   // U+FFFD, what the browser substitutes
    if ($cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF) || !function_exists('mb_chr')) {
        return $replacement;
    }
    $ch = mb_chr($cp, 'UTF-8');
    return $ch === false ? $replacement : $ch;
}

/**
 * The quoted string opening at $i, escapes decoded, quotes kept; $i moves past it.
 * An unescaped line break ends it as the browser ends it, and is left to be read
 * next; an escaped one continues the string and is dropped. A comment opened and
 * closed inside it is removed (qs_css_normalize_for_scan says why).
 */
function qs_css_scan_string(string $css, int &$i): string {
    $len   = strlen($css);
    $quote = $css[$i];
    $text  = '';
    $close = '';
    $i++;
    while ($i < $len) {
        $run   = strcspn($css, $quote . "\\\n\r\f", $i);
        $text .= substr($css, $i, $run);
        $i    += $run;
        if ($i >= $len) {
            break;
        }
        $c = $css[$i];
        if ($c === $quote) {
            $close = $quote;
            $i++;
            break;
        }
        if ($c !== '\\') {
            break;
        }
        if ($i + 1 < $len && strpos("\n\r\f", $css[$i + 1]) !== false) {
            $i += ($css[$i + 1] === "\r" && $i + 2 < $len && $css[$i + 2] === "\n") ? 3 : 2;
            continue;
        }
        $text .= qs_css_scan_escape($css, $i);
    }
    return $quote . qs_css_scan_drop_inner_comments($text) . $close;
}

/** $text with every comment opened AND closed inside it removed; an unclosed opener stays text. */
function qs_css_scan_drop_inner_comments(string $text): string {
    return strpos($text, '/*') === false ? $text : (preg_replace('~/\*.*?\*/~s', '', $text) ?? $text);
}

/**
 * What follows `url(` at $i. A quoted argument is left to be read as a string;
 * otherwise the unquoted URL runs to its closing parenthesis (or the end), escapes
 * decoded, and a comment opened in it never reaches past it — though one opened
 * and closed inside it is removed (qs_css_normalize_for_scan says why). $i moves
 * past what was read.
 */
function qs_css_scan_url(string $css, int &$i): string {
    $len = strlen($css);
    $ws  = strspn($css, " \t\n\r\f", $i);
    $out = substr($css, $i, $ws);
    $i  += $ws;
    if ($i < $len && ($css[$i] === '"' || $css[$i] === "'")) {
        return $out;
    }
    $text  = '';
    $close = '';
    while ($i < $len) {
        $run   = strcspn($css, ")\\", $i);
        $text .= substr($css, $i, $run);
        $i    += $run;
        if ($i >= $len) {
            break;
        }
        if ($css[$i] === ')') {
            $close = ')';
            $i++;
            break;
        }
        if ($i + 1 < $len && strpos("\n\r\f", $css[$i + 1]) === false) {
            $text .= qs_css_scan_escape($css, $i);
        } else {
            $text .= '\\';
            $i++;
        }
    }
    return $out . qs_css_scan_drop_inner_comments($text) . $close;
}

/**
 * Returns the path to the live stylesheet for the current project.
 * This is the file actively served to site visitors.
 */
function cssLivePath(): string {
    return PUBLIC_CONTENT_PATH . '/style/style.css';
}

/**
 * Returns the path to the project backup stylesheet for the current project.
 * This copy mirrors the live file and is used during builds and deployments.
 */
function cssProjectPath(): string {
    return PROJECT_PATH . '/public/style/style.css';
}

/**
 * Acquires an exclusive file lock for CSS write operations.
 * The lock is keyed on the live stylesheet path.
 * 
 * @param string $styleFile Path to the live stylesheet (used to derive the lock key).
 * @return resource|null File handle with lock held, or null if the lock could not be acquired.
 */
function cssAcquireLock(string $styleFile) {
    $lockFile = sys_get_temp_dir() . '/quicksite_style_' . md5($styleFile) . '.lock';
    $lock = fopen($lockFile, 'w');
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return null;
    }
    return $lock;
}

/**
 * Releases and closes a CSS write lock previously acquired by cssAcquireLock().
 * 
 * @param resource $lock File handle returned by cssAcquireLock().
 */
function cssReleaseLock($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * Writes CSS content to the live stylesheet and the project backup copy.
 * 
 * If both paths resolve to the same file, the content is written only once.
 * Ensures the project backup directory exists before writing.
 * The caller must hold the lock (via cssAcquireLock) before calling this.
 * 
 * @param string $content    Updated CSS content to write.
 * @param string $livePath   Path to the live stylesheet.
 * @param string $projectPath Path to the project backup stylesheet.
 * @throws Exception If a directory cannot be created or a write fails.
 */
function cssWriteAllTargets(string $content, string $livePath, string $projectPath): void {
    // The single enforcement point for the write cap. Every rule-level
    // writer (setStyleRule / setRootVariables / setKeyframes / delete*) reaches disk
    // through here, so capping here caps all of them at a size the CssParser can
    // read back. editStyles and injectSnippetCss write directly for their own
    // backup/response reasons and check CSS_MAX_BYTES at their own boundary.
    if (strlen($content) > CSS_MAX_BYTES) {
        throw new Exception('Stylesheet exceeds the maximum size ('
            . (int) round(CSS_MAX_BYTES / 1024) . ' KB)');
    }

    if (file_put_contents($livePath, $content) === false) {
        throw new Exception('Failed to write live style file');
    }

    if ($projectPath !== $livePath) {
        $projectDir = dirname($projectPath);
        if (!is_dir($projectDir) && !mkdir($projectDir, 0755, true)) {
            throw new Exception('Failed to create project style directory');
        }
        if (file_put_contents($projectPath, $content) === false) {
            throw new Exception('Failed to write project style file');
        }
    }
}

/**
 * Extract every CSS class, ID and tag named by a JSON structure, recursively.
 *
 * Walks a page / menu / footer / component / snippet subtree, resolving
 * `component` references against the project's component folder so a component's
 * own selectors count too, and returns the three selector sets a CssParser query
 * needs.
 *
 * Shared: `injectSnippetCss` reaches it through `extractSnippetCss()` in
 * SnippetManagement.php, which `createSnippet` and `duplicateSnippet` call.
 *
 * THE COMPONENTS DIRECTORY IS PASSED IN, NOT READ FROM AMBIENT STATE. An
 * ambient global is the wrong shape for a function whose caller already knows
 * which project it is working on: `extractSnippetCss()` is handed a project
 * NAME, so a request-bound constant would read components from whichever
 * project the REQUEST bound rather than the one being extracted. The caller
 * derives the directory from that name and hands it down.
 *
 * (Its stylesheet lookup still prefers the request-bound PUBLIC_CONTENT_PATH and
 * only falls back to the named project's own copy, so the two halves agree only
 * while both callers pass the marker project — which today they both do.)
 *
 * A COMPONENT'S SLOTS ARE BOUND, NOT READ RAW. A reference supplies values for
 * the slots its component leaves open — `{"component": "menu-link", "data":
 * {"imgClass": "menu-icon"}}` — and this walk binds them through the shared
 * `qs_resolve_component_placeholders()`, the same substitution the renderer
 * performs, so the selectors collected here are the ones the visitor's page
 * will really carry. Reading only `data['class']` and `data['id']` would miss
 * the real class of a component whose class slot has any other name, and leave
 * the matching rules out of the stored snippet CSS.
 *
 * An UNBOUND slot is dropped rather than stored. A class named `{{imgClass}}`
 * matches no rule — it contributes no CSS either way — so keeping it only
 * misreports which selectors a snippet uses.
 *
 * Requires `componentPolicy.php` (qs_resolve_component_path,
 * qs_resolve_component_placeholders) to be in scope.
 *
 * @param array  $structure     Array of nodes.
 * @param string $componentsDir Project's components folder. Pass '' to skip
 *                              component resolution entirely.
 * @param array  $components    Component cache, filled as references resolve.
 *                              Holds the RAW component structure: slots bind
 *                              during the walk, so one cached copy serves
 *                              every reference to it whatever its data.
 * @param array  $data          Slot values in scope for this subtree — the
 *                              `data` map of the component reference that led
 *                              here. Empty at the top level, where a node's
 *                              classes are already literal.
 * @return array ['classes' => [...], 'ids' => [...], 'tags' => [...]]
 */
function extractCssSelectorsFromStructure(array $structure, string $componentsDir, array &$components = [], array $data = []): array {
    $classes = [];
    $ids = [];
    $tags = [];

    foreach ($structure as $node) {
        // Handle component references
        if (isset($node['component'])) {
            $componentName = $node['component'];

            // Bind this reference's own data map against the slots in scope
            // HERE, before it becomes the scope one level down. That ordering
            // is what makes a nested reference resolve: menu-link's inner
            // {"component": "img-dynamic", "data": {"class": "{{imgClass}}"}}
            // has to become {"class": "menu-icon"} from menu-link's data
            // before img-dynamic's own {{class}} slot has anything to bind to.
            // The renderer expands a component in exactly this order.
            $nodeData = [];
            if (isset($node['data']) && is_array($node['data'])) {
                foreach ($node['data'] as $slot => $value) {
                    $nodeData[$slot] = is_string($value)
                        ? qs_resolve_component_placeholders($value, $data)
                        : $value;
                }
            }

            // Load component if not already loaded
            if (!isset($components[$componentName]) && $componentsDir !== '') {
                // A stored reference, jailed by the shared resolver.
                $componentPath = qs_resolve_component_path($componentName, $componentsDir);
                if ($componentPath !== null) {
                    $componentContent = @file_get_contents($componentPath);
                    if ($componentContent !== false) {
                        $componentData = json_decode($componentContent, true);
                        if (is_array($componentData)) {
                            $components[$componentName] = $componentData;
                        }
                    }
                }
            }

            // Recursively extract from component
            if (isset($components[$componentName])) {
                // $componentsDir and $nodeData are both threaded through: a
                // component may itself reference a component, and a recursion
                // that dropped either would resolve nothing one level down —
                // no file for the directory, no slot values for the data.
                $componentSelectors = extractCssSelectorsFromStructure(
                    is_array($components[$componentName][0] ?? null) ? $components[$componentName] : [$components[$componentName]],
                    $componentsDir,
                    $components,
                    $nodeData
                );
                $classes = array_merge($classes, $componentSelectors['classes']);
                $ids = array_merge($ids, $componentSelectors['ids']);
                $tags = array_merge($tags, $componentSelectors['tags']);
            }

            // Also extract from the reference's own data (a component may take
            // its class straight from the referencing node, the way the
            // starter project's 404 page hands img-dynamic a literal class).
            if (isset($nodeData['class']) && is_string($nodeData['class'])) {
                $nodeClasses = preg_split('/\s+/', trim($nodeData['class']));
                $classes = array_merge($classes, $nodeClasses);
            }
            if (isset($nodeData['id']) && is_string($nodeData['id'])) {
                $ids[] = $nodeData['id'];
            }

            continue;
        }

        // Handle regular tags
        if (isset($node['tag'])) {
            $tags[] = $node['tag'];

            // Extract params. Inside a component these carry that component's
            // slots, so they bind against the data in scope; at the top level
            // $data is empty and the substitution is a no-op. The is_string
            // guards are the type contract of the shared resolver — a stored
            // `"class": ["a"]` would otherwise reach trim() and fatal on PHP 8.
            if (isset($node['params']) && is_array($node['params'])) {
                if (isset($node['params']['class']) && is_string($node['params']['class'])) {
                    $boundClass = qs_resolve_component_placeholders($node['params']['class'], $data);
                    $nodeClasses = preg_split('/\s+/', trim($boundClass));
                    $classes = array_merge($classes, $nodeClasses);
                }
                if (isset($node['params']['id']) && is_string($node['params']['id'])) {
                    $ids[] = qs_resolve_component_placeholders($node['params']['id'], $data);
                }
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            $childSelectors = extractCssSelectorsFromStructure($node['children'], $componentsDir, $components, $data);
            $classes = array_merge($classes, $childSelectors['classes']);
            $ids = array_merge($ids, $childSelectors['ids']);
            $tags = array_merge($tags, $childSelectors['tags']);
        }
    }

    // An entry still holding `{{…}}` here is a slot nothing supplied, and no
    // outer level can supply it — slot values flow inward only, so what is
    // unbound at this depth stays unbound. Drop it: it names no rule the
    // parser could match, and storing it would claim a snippet uses a
    // selector that will never exist on the page.
    $bound = static function ($selector): bool {
        // `$selector &&` is the default array_filter truthiness test; the
        // placeholder rule is added to it.
        return $selector && (!is_string($selector) || strpos($selector, '{{') === false);
    };

    // array_values because both array_filter and array_unique preserve keys,
    // and these three lists are stored: `createSnippet` writes them to the
    // snippet's `selectors`, where a gap in the keys makes json_encode emit an
    // OBJECT instead of an array. Dropping unbound placeholders above opens
    // exactly such a gap — {"0":"img-fluid"} where the shape everything else
    // documents, and every reader iterates, is a list.
    return [
        'classes' => array_values(array_unique(array_filter($classes, $bound))),
        'ids' => array_values(array_unique(array_filter($ids, $bound))),
        'tags' => array_values(array_unique(array_filter($tags, $bound)))
    ];
}
