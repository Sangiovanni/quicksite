<?php
/**
 * CSS Parser Utility Class
 *
 * Parses and manipulates CSS content using a structured block-tree approach.
 * Instead of fragile regex patterns for brace matching, the parser tokenizes
 * the CSS content into top-level block descriptors (rules and at-rules) that
 * correctly handle nested braces, comments, and string literals.
 *
 * Supported operations:
 *  - Read / write CSS custom properties inside :root or [data-theme="dark"] scopes
 *  - Read / write / delete style rules (global scope and inside @media)
 *  - List all selectors in the stylesheet
 *  - Read / write / delete @keyframes animations
 *  - Extract all CSS rules relevant to a set of classes / IDs / tags
 */
class CssParser {

    private string $content;

    /**
     * When the input is larger than the parser can process within the
     * install's memory_limit, every parse method degrades to empty/no-op instead
     * of allocating ~140-210x the input and fatally exhausting memory. This guards
     * the paths that land a stylesheet on disk WITHOUT going through the write cap
     * (importProject writes public/style/style.css directly), so a read command
     * like getRootVariables cannot be made to OOM by a planted oversized sheet.
     * The ceiling mirrors CSS_MAX_BYTES (utilsStyleManagement.php); the fallback
     * covers callers that don't load that helper.
     */
    private bool $tooLarge;

    public function __construct(string $content) {
        $this->content  = $content;
        $ceiling        = defined('CSS_MAX_BYTES') ? CSS_MAX_BYTES : 512 * 1024;
        $this->tooLarge = strlen($content) > $ceiling;
    }

    /**
     * Get the current CSS content (after any modifications).
     */
    public function getContent(): string {
        return $this->content;
    }

    // =========================================================================
    // Parse Tree Infrastructure (private)
    // =========================================================================

    /**
     * Skip over a CSS string literal starting at $pos (which must be a quote char).
     * Returns the position AFTER the closing quote.
     */
    private function skipString(int $pos): int {
        $quote = $this->content[$pos];
        $len   = strlen($this->content);
        $pos++;
        while ($pos < $len) {
            $ch = $this->content[$pos];
            if ($ch === '\\')   { $pos += 2; continue; }
            if ($ch === $quote) { $pos++;    break;     }
            $pos++;
        }
        return $pos;
    }

    /**
     * Find the next `{` starting at $from, skipping comments and string literals.
     * Returns false when no opening brace exists beyond $from.
     */
    private function findNextOpenBrace(int $from): int|false {
        $len = strlen($this->content);
        $pos = $from;
        while ($pos < $len) {
            $ch = $this->content[$pos];
            if ($ch === '/' && ($pos + 1) < $len && $this->content[$pos + 1] === '*') {
                $end = strpos($this->content, '*/', $pos + 2);
                $pos = ($end !== false) ? $end + 2 : $len;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $pos = $this->skipString($pos); continue; }
            if ($ch === '{') return $pos;
            $pos++;
        }
        return false;
    }

    /**
     * Find the next `{` OR `;` starting at $from, skipping comments and
     * string literals. Used to distinguish a regular rule / block-at-rule
     * (which uses `{...}`) from a body-less at-rule like `@import url(...);`
     * or `@charset "utf-8";` which ends at `;`. Without this distinction,
     * the parser would treat `@import ... ; :root {...}` as a single
     * at-rule whose body is the `:root` block — and `findTopLevelBlock(':root')`
     * would then return null.
     */
    private function findNextBraceOrSemicolon(int $from): int|false {
        $len = strlen($this->content);
        $pos = $from;
        while ($pos < $len) {
            $ch = $this->content[$pos];
            if ($ch === '/' && ($pos + 1) < $len && $this->content[$pos + 1] === '*') {
                $end = strpos($this->content, '*/', $pos + 2);
                $pos = ($end !== false) ? $end + 2 : $len;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $pos = $this->skipString($pos); continue; }
            if ($ch === '{' || $ch === ';') return $pos;
            $pos++;
        }
        return false;
    }

    /**
     * Given the position of `{`, find the position ONE PAST the matching `}`.
     * Properly handles nested braces, comments, and strings.
     */
    private function findMatchingClose(int $openPos): int {
        $len   = strlen($this->content);
        $depth = 1;
        $pos   = $openPos + 1;
        while ($pos < $len && $depth > 0) {
            $ch = $this->content[$pos];
            if ($ch === '/' && ($pos + 1) < $len && $this->content[$pos + 1] === '*') {
                $end = strpos($this->content, '*/', $pos + 2);
                $pos = ($end !== false) ? $end + 2 : $len;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $pos = $this->skipString($pos); continue; }
            if ($ch === '{') $depth++;
            if ($ch === '}') $depth--;
            $pos++;
        }
        return $pos; // position AFTER the closing }
    }

    /**
     * Normalize a CSS selector or at-rule prelude for comparison:
     * collapses all whitespace and normalises single quotes to double quotes.
     */
    private function normalizeSelector(string $selector): string {
        $s = preg_replace('/\s+/', ' ', trim($selector));
        return str_replace("'", '"', $s);
    }

    /**
     * Parse the CSS content into an array of top-level block descriptors.
     *
     * Each entry contains:
     *   'type'       => 'rule' | 'atrule'
     *   'selector'   => string  (rules only)
     *   'keyword'    => string  (at-rules only, e.g. 'media', 'keyframes')
     *   'prelude'    => string  (at-rules only, e.g. '(max-width: 768px)')
     *   'start'      => int     byte offset of the first char of the prelude
     *   'end'        => int     byte offset ONE PAST the closing '}'
     *   'innerStart' => int     byte offset of the first char after '{'
     *   'innerEnd'   => int     byte offset of the closing '}'
     */
    private function parseTopLevelBlocks(): array {
        if ($this->tooLarge) return [];   // an oversized sheet: refuse before allocating the block tree
        $blocks = [];
        $len    = strlen($this->content);
        $pos    = 0;

        while ($pos < $len) {
            // Skip leading whitespace
            while ($pos < $len && ctype_space($this->content[$pos])) {
                $pos++;
            }
            if ($pos >= $len) break;

            // Skip comments
            if ($pos + 1 < $len && $this->content[$pos] === '/' && $this->content[$pos + 1] === '*') {
                $end = strpos($this->content, '*/', $pos + 2);
                $pos = ($end !== false) ? $end + 2 : $len;
                continue;
            }

            $blockStart = $pos;

            // Look for whichever comes first: `;` (body-less at-rule
            // terminator) or `{` (block opener). This handles
            // `@import url(...);` and `@charset "utf-8";` without
            // mistakenly merging them with the following rule.
            $terminator = $this->findNextBraceOrSemicolon($pos);
            if ($terminator === false) break;

            // Body-less at-rule: terminates at ';' with no '{...}' body.
            if ($this->content[$terminator] === ';') {
                $preludeRaw = trim(substr($this->content, $pos, $terminator - $pos));
                if ($preludeRaw !== '' && str_starts_with($preludeRaw, '@')) {
                    preg_match('/^@([\w-]+)\s*(.*)/s', $preludeRaw, $m);
                    $blocks[] = [
                        'type'       => 'atrule',
                        'keyword'    => $m[1] ?? '',
                        'prelude'    => trim($m[2] ?? ''),
                        'start'      => $blockStart,
                        'end'        => $terminator + 1,
                        'innerStart' => $terminator,   // no body
                        'innerEnd'   => $terminator,
                        'noBody'     => true,
                    ];
                }
                // Whether it was a real at-rule or just a stray ';', advance past it.
                $pos = $terminator + 1;
                continue;
            }

            // Otherwise it's a `{` — a regular rule or block at-rule.
            $bracePos = $terminator;
            $prelude  = trim(substr($this->content, $pos, $bracePos - $pos));
            if ($prelude === '') {
                // No prelude — malformed or an empty rule; skip past this brace
                $pos = $bracePos + 1;
                continue;
            }

            // Find matching closing brace
            $closePos = $this->findMatchingClose($bracePos); // ONE PAST '}'

            if (str_starts_with($prelude, '@')) {
                preg_match('/^@([\w-]+)\s*(.*)/s', $prelude, $m);
                $blocks[] = [
                    'type'       => 'atrule',
                    'keyword'    => $m[1] ?? '',
                    'prelude'    => trim($m[2] ?? ''),
                    'start'      => $blockStart,
                    'end'        => $closePos,
                    'innerStart' => $bracePos + 1,
                    'innerEnd'   => $closePos - 1,
                ];
            } else {
                $blocks[] = [
                    'type'       => 'rule',
                    'selector'   => $prelude,
                    'start'      => $blockStart,
                    'end'        => $closePos,
                    'innerStart' => $bracePos + 1,
                    'innerEnd'   => $closePos - 1,
                ];
            }

            $pos = $closePos;
        }

        return $blocks;
    }

    /**
     * Find a top-level rule block by selector (normalised comparison).
     * Returns the block descriptor array or null.
     */
    private function findTopLevelBlock(string $selector): ?array {
        $normalized = $this->normalizeSelector($selector);
        foreach ($this->parseTopLevelBlocks() as $block) {
            if ($block['type'] === 'rule'
                && $this->normalizeSelector($block['selector']) === $normalized) {
                return $block;
            }
        }
        return null;
    }

    /**
     * Find a top-level @-rule block by keyword and optional prelude.
     * If $prelude is non-empty it must match (normalised); otherwise any prelude matches.
     */
    private function findTopLevelAtrule(string $keyword, string $prelude = ''): ?array {
        $normPrelude = ($prelude !== '') ? $this->normalizeSelector($prelude) : null;
        foreach ($this->parseTopLevelBlocks() as $block) {
            if ($block['type'] !== 'atrule' || $block['keyword'] !== $keyword) continue;
            if ($normPrelude !== null
                && $this->normalizeSelector($block['prelude']) !== $normPrelude) continue;
            return $block;
        }
        return null;
    }

    // =========================================================================
    // Variable Scope Operations
    // =========================================================================

    /**
     * Extract all :root variables.
     *
     * @return array Associative array of variable name => value
     */
    public function getRootVariables(): array {
        return $this->getVariablesInScope(':root');
    }
    /**
     * Get CSS custom properties defined in a specific selector scope.
     *
     * @param string $selector CSS selector to search (e.g. ':root', '[data-theme="dark"]')
     * @return array Associative array of variable name => value
     */
    public function getVariablesInScope(string $selector): array {
        $block = $this->findTopLevelBlock($selector);
        if ($block === null) return [];

        $inner = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
        $variables = [];
        // The semicolon after the LAST declaration in a block is optional in CSS,
        // so the terminator is "a semicolon or the end of the block". Requiring
        // the semicolon silently dropped the final variable of any hand-authored
        // stylesheet that omitted it, and the Theme panel then never showed it.
        // The value class still cannot cross a semicolon, so every earlier
        // declaration terminates exactly where it did before.
        preg_match_all('/(--.+?)\s*:\s*([^;]+)(?:;|$)/s', $inner, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $variables[trim($match[1])] = trim($match[2]);
        }
        return $variables;
    }

    /**
     * Set/update CSS custom properties in a specific selector scope.
     * Creates the scope block if it does not exist (appended at end of stylesheet).
     *
     * @param array  $variables Associative array of variable name => value
     * @param string $selector  CSS selector that owns the variables block
     * @return array Summary of changes: added, updated, total_changes
     */
    public function setVariablesInScope(array $variables, string $selector): array {
        $added   = [];
        $updated = [];

        $block = $this->findTopLevelBlock($selector);

        if ($block !== null) {
            $inner    = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
            $newInner = $inner;

            foreach ($variables as $varName => $varValue) {
                if (!str_starts_with((string)$varName, '--')) {
                    $varName = '--' . $varName;
                }
                // Same optional-semicolon rule as the reader: a block's last
                // declaration may end at the block instead of at a ';'. Without
                // this the update found nothing, fell to the append branch, and
                // left TWO declarations of the same variable behind. The
                // trailing run of whitespace is captured and put back so the
                // line break before the closing brace survives the rewrite.
                $varPattern = '/(' . preg_quote($varName, '/') . '\s*:\s*)([^;]+?)(\s*)(;|$)/s';
                if (preg_match($varPattern, $newInner)) {
                    $safeValue  = str_replace(['\\', '$'], ['\\\\', '\\$'], $varValue);
                    $newInner   = preg_replace($varPattern, '${1}' . $safeValue . '${3}${4}', $newInner);
                    $updated[$varName] = $varValue;
                } else {
                    // Terminate the declaration we are appending after. If the
                    // block's last one had no semicolon, gluing a new line onto
                    // it produced a single declaration whose VALUE contained the
                    // new one — which the reader then returned as one mangled
                    // variable.
                    $newInner = rtrim($newInner);
                    if ($newInner !== '' && substr($newInner, -1) !== ';') {
                        $newInner .= ';';
                    }
                    $newInner        .= "\n    " . $varName . ': ' . $varValue . ";\n";
                    $added[$varName]  = $varValue;
                }
            }

            // Splice new inner content back into the full CSS using byte positions
            $this->content = substr($this->content, 0, $block['innerStart'])
                           . $newInner
                           . substr($this->content, $block['innerEnd']);
        } else {
            // Block doesn't exist — append a new one at the end of the stylesheet
            $newBlock = "\n" . $selector . " {\n";
            foreach ($variables as $varName => $varValue) {
                if (!str_starts_with((string)$varName, '--')) {
                    $varName = '--' . $varName;
                }
                $newBlock        .= "    " . $varName . ': ' . $varValue . ";\n";
                $added[$varName] = $varValue;
            }
            $newBlock .= "}\n";
            $this->content .= $newBlock;
        }

        return [
            'added'         => $added,
            'updated'       => $updated,
            'total_changes' => count($added) + count($updated),
        ];
    }

    /**
     * Get all selectors in the stylesheet.
     *
     * @return array List of ['selector' => string, 'mediaQuery' => string|null]
     */
    public function listSelectors(): array {
        $selectors = [];
        foreach ($this->parseTopLevelBlocks() as $block) {
            if ($block['type'] === 'atrule' && $block['keyword'] === 'media') {
                $mediaQuery   = $block['prelude'];
                $innerContent = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
                $innerParser  = new self($innerContent);
                foreach ($innerParser->parseTopLevelBlocks() as $inner) {
                    if ($inner['type'] === 'rule') {
                        $sel = trim($inner['selector']);
                        if ($sel !== '' && !str_starts_with($sel, '/*')) {
                            $selectors[] = ['selector' => $sel, 'mediaQuery' => $mediaQuery];
                        }
                    }
                }
            } elseif ($block['type'] === 'rule') {
                $sel = trim($block['selector']);
                if ($sel !== ''
                    && !str_starts_with($sel, '/*')
                    && $sel !== ':root') {
                    $selectors[] = ['selector' => $sel, 'mediaQuery' => null];
                }
            }
        }
        return $selectors;
    }
    
    /**
     * Get styles for a specific selector.
     *
     * @param string      $selector   CSS selector
     * @param string|null $mediaQuery Optional media query context
     * @return array|null ['selector', 'styles', 'mediaQuery'] or null if not found
     */
    public function getStyleRule(string $selector, ?string $mediaQuery = null): ?array {
        if ($mediaQuery !== null) {
            $mediaBlock = $this->findTopLevelAtrule('media', $mediaQuery);
            if ($mediaBlock === null) return null;

            $innerContent = substr($this->content, $mediaBlock['innerStart'], $mediaBlock['innerEnd'] - $mediaBlock['innerStart']);
            $innerParser  = new self($innerContent);
            $rule         = $innerParser->findTopLevelBlock($selector);
            if ($rule === null) return null;

            $inner = substr($innerContent, $rule['innerStart'], $rule['innerEnd'] - $rule['innerStart']);
            return ['selector' => $selector, 'styles' => trim($inner), 'mediaQuery' => $mediaQuery];
        }

        // Global scope
        $block = $this->findTopLevelBlock($selector);
        if ($block === null) return null;

        $inner = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
        return ['selector' => $selector, 'styles' => trim($inner), 'mediaQuery' => null];
    }
    
    // =========================================================================
    // Declarations — read the way the CSS tokenizer reads them
    // =========================================================================

    /** The rule every declaration list a command writes into a stylesheet must meet. */
    public const DECLARATION_RULE = 'each declaration is property: value, and every quote, comment and bracket it opens is closed';

    /**
     * A property name, a colon, a value. A custom property (--name) may have an empty
     * value, as CSS allows; any other property may not.
     */
    private const DECLARATION = '/^(?:--(?:[A-Za-z0-9_-]|[\x80-\xFF]|\\\\.)*\s*:.*|-?(?:[A-Za-z_]|[\x80-\xFF]|\\\\.)(?:[A-Za-z0-9_-]|[\x80-\xFF]|\\\\.)*\s*:\s*\S.*)$/sD';

    /**
     * Split a declaration list at each `;` that ends a declaration: one outside a
     * string, a comment and brackets, so `content: "a;b"` and a data URI in url()
     * stay whole. A string ends at its closing quote or, as in CSS, at a line break;
     * a comment ends at its closing mark.
     *
     * @return array{0: string[], 1: string[], 2: ?string} the declarations as written;
     *   the same with each comment turned into a space; and what the text leaves open
     *   at its end ('quote', 'comment' or 'bracket'), or null when it closes everything
     */
    private static function readDeclarations(string $text): array {
        $len      = strlen($text);
        $raw      = [];
        $bare     = [];
        $open     = null;
        $brackets = [];
        $start    = 0;
        $buffer   = '';
        $i        = 0;
        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '/' && ($i + 1) < $len && $text[$i + 1] === '*') {
                $end = strpos($text, '*/', $i + 2);
                if ($end === false) {
                    $open = $open ?? 'comment';
                    break;
                }
                $buffer .= ' ';
                $i = $end + 2;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                $closed = false;
                while ($j < $len) {
                    $c = $text[$j];
                    if ($c === '\\') { $j += 2; continue; }
                    if ($c === $ch) { $closed = true; $j++; break; }
                    if ($c === "\n" || $c === "\r" || $c === "\f") break;
                    $j++;
                }
                if (!$closed) {
                    $open = $open ?? 'quote';
                }
                $j = min($j, $len);
                $buffer .= substr($text, $i, $j - $i);
                $i = $j;
                continue;
            }
            if ($ch === '\\') {
                $buffer .= substr($text, $i, 2);
                $i += 2;
                continue;
            }
            if ($ch === '(' || $ch === '[') {
                $brackets[] = $ch === '(' ? ')' : ']';
            } elseif (($ch === ')' || $ch === ']') && $brackets && end($brackets) === $ch) {
                array_pop($brackets);
            } elseif ($ch === ';' && !$brackets) {
                $raw[]  = substr($text, $start, $i - $start);
                $bare[] = $buffer;
                $start  = $i + 1;
                $buffer = '';
                $i++;
                continue;
            }
            $buffer .= $ch;
            $i++;
        }
        $raw[]  = (string) substr($text, $start);
        $bare[] = $buffer;
        if ($brackets) {
            $open = $open ?? 'bracket';
        }
        return [$raw, $bare, $open];
    }

    /**
     * Why a declaration list cannot go into a stylesheet as it is, or null when it can
     * (DECLARATION_RULE). A quote, a comment or a bracket left open reads on into the
     * rules after it, in the browser and in this parser alike.
     *
     * @return string|null 'unclosed_quote', 'unclosed_comment', 'unclosed_bracket'
     *                     or 'not_a_declaration'
     */
    public static function declarationProblem(string $declarations): ?string {
        [, $bare, $open] = self::readDeclarations($declarations);
        if ($open !== null) {
            return 'unclosed_' . $open;
        }
        foreach ($bare as $declaration) {
            $declaration = trim($declaration);
            if ($declaration !== '' && !preg_match(self::DECLARATION, $declaration)) {
                return 'not_a_declaration';
            }
        }
        return null;
    }

    /**
     * Parse CSS style declarations into an associative array
     * @param string $styles CSS declarations string
     * @return array Property => value pairs
     */
    private function parseStyleDeclarations(string $styles): array {
        $result = [];
        foreach (self::readDeclarations($styles)[0] as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') continue;
            
            // Split on first colon only
            $colonPos = strpos($declaration, ':');
            if ($colonPos === false) continue;
            
            $property = trim(substr($declaration, 0, $colonPos));
            $value = trim(substr($declaration, $colonPos + 1));
            
            if (!empty($property) && $value !== '') {
                $result[$property] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Merge new styles with existing styles
     * @param string $existingStyles Current CSS declarations
     * @param string $newStyles New CSS declarations to merge
     * @param array $removeProperties Properties to remove from the result
     * @return string Merged and formatted CSS declarations
     */
    private function mergeStyles(string $existingStyles, string $newStyles, array $removeProperties = []): string {
        $existing = $this->parseStyleDeclarations($existingStyles);
        $new = $this->parseStyleDeclarations($newStyles);
        
        // Merge - new values override existing
        $merged = array_merge($existing, $new);
        
        // Remove specified properties
        foreach ($removeProperties as $prop) {
            unset($merged[$prop]);
        }
        
        // Convert back to CSS string with proper formatting
        $lines = [];
        foreach ($merged as $property => $value) {
            $lines[] = '    ' . $property . ': ' . $value . ';';
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Set/update a style rule (merges with existing properties).
     *
     * @param string      $selector         CSS selector
     * @param string      $styles           CSS declarations
     * @param string|null $mediaQuery       Optional media query context
     * @param array       $removeProperties Properties to remove
     * @return array Operation result ['action', 'selector', 'mediaQuery']
     */
    public function setStyleRule(string $selector, string $styles, ?string $mediaQuery = null, array $removeProperties = []): array {
        $action = 'added';

        if ($mediaQuery !== null) {
            $mediaBlock = $this->findTopLevelAtrule('media', $mediaQuery);
            if ($mediaBlock !== null) {
                $innerContent = substr($this->content, $mediaBlock['innerStart'], $mediaBlock['innerEnd'] - $mediaBlock['innerStart']);
                $innerParser  = new self($innerContent);
                $rule         = $innerParser->findTopLevelBlock($selector);

                if ($rule !== null) {
                    $existingStyles = substr($innerContent, $rule['innerStart'], $rule['innerEnd'] - $rule['innerStart']);
                    $mergedStyles   = $this->mergeStyles($existingStyles, $styles, $removeProperties);

                    if (trim($mergedStyles) === '') {
                        // Remove this rule from the media content
                        $newInnerContent = substr($innerContent, 0, $rule['start'])
                                         . substr($innerContent, $rule['end']);
                        $newInnerContent = preg_replace('/\n{3,}/', "\n\n", $newInnerContent);
                        if (trim($newInnerContent) === '') {
                            // Media query is now empty — remove entire block
                            $this->content = substr($this->content, 0, $mediaBlock['start'])
                                           . substr($this->content, $mediaBlock['end']);
                            $this->content = preg_replace('/\n{3,}/', "\n\n", $this->content);
                            return ['action' => 'deleted', 'selector' => $selector, 'mediaQuery' => $mediaQuery];
                        }
                        $newMediaContent = '@media ' . $mediaBlock['prelude'] . " {\n" . $newInnerContent . "\n}";
                        $this->content   = substr($this->content, 0, $mediaBlock['start'])
                                         . $newMediaContent
                                         . substr($this->content, $mediaBlock['end']);
                        return ['action' => 'deleted', 'selector' => $selector, 'mediaQuery' => $mediaQuery];
                    }

                    // Update the rule in inner content
                    $newRule         = $rule['selector'] . " {\n" . $mergedStyles . "\n}";
                    $newInnerContent = substr($innerContent, 0, $rule['start'])
                                     . $newRule
                                     . substr($innerContent, $rule['end']);
                    $action = 'updated';

                } else {
                    // Add new rule to existing media block
                    $formattedStyles = $this->formatStyles($styles);
                    $newRule         = "    " . $selector . " {\n" . $formattedStyles . "\n    }";
                    $newInnerContent = rtrim($innerContent) . "\n" . $newRule . "\n";
                }

                $newMediaContent = '@media ' . $mediaBlock['prelude'] . " {\n" . $newInnerContent . "}";
                $this->content   = substr($this->content, 0, $mediaBlock['start'])
                                 . $newMediaContent
                                 . substr($this->content, $mediaBlock['end']);
            } else {
                // Media query doesn't exist — create it
                $formattedStyles = $this->formatStyles($styles);
                $newMediaBlock   = "\n\n@media " . $mediaQuery . " {\n    " . $selector . " {\n" . $formattedStyles . "\n    }\n}";
                $this->content   = $this->appendToCustomSection($newMediaBlock);
            }

        } else {
            // Global scope
            $block = $this->findTopLevelBlock($selector);

            if ($block !== null) {
                $existingStyles = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
                $mergedStyles   = $this->mergeStyles($existingStyles, $styles, $removeProperties);

                if (trim($mergedStyles) === '') {
                    $this->content = substr($this->content, 0, $block['start'])
                                   . substr($this->content, $block['end']);
                    $this->content = preg_replace('/\n{3,}/', "\n\n", $this->content);
                    return ['action' => 'deleted', 'selector' => $selector, 'mediaQuery' => null];
                }

                $newBlock      = $block['selector'] . " {\n" . $mergedStyles . "\n}";
                $this->content = substr($this->content, 0, $block['start'])
                               . $newBlock
                               . substr($this->content, $block['end']);
                $action = 'updated';
            } else {
                $formattedStyles = $this->formatStyles($styles);
                $newRule         = "\n" . $selector . " {\n" . $formattedStyles . "\n}";
                $this->content   = $this->appendToCustomSection($newRule);
            }
        }

        return ['action' => $action, 'selector' => $selector, 'mediaQuery' => $mediaQuery];
    }
    
    /**
     * Delete a style rule.
     *
     * @param string      $selector   CSS selector
     * @param string|null $mediaQuery Optional media query context
     * @return bool True if deleted, false if not found
     */
    public function deleteStyleRule(string $selector, ?string $mediaQuery = null): bool {
        if ($mediaQuery !== null) {
            $mediaBlock = $this->findTopLevelAtrule('media', $mediaQuery);
            if ($mediaBlock === null) return false;

            $innerContent = substr($this->content, $mediaBlock['innerStart'], $mediaBlock['innerEnd'] - $mediaBlock['innerStart']);
            $innerParser  = new self($innerContent);
            $rule         = $innerParser->findTopLevelBlock($selector);
            if ($rule === null) return false;

            $newInnerContent = substr($innerContent, 0, $rule['start'])
                             . substr($innerContent, $rule['end']);
            $newInnerContent = preg_replace('/\n{3,}/', "\n\n", $newInnerContent);

            if (trim($newInnerContent) === '') {
                // Media query is now empty — remove entire block
                $this->content = substr($this->content, 0, $mediaBlock['start'])
                               . substr($this->content, $mediaBlock['end']);
            } else {
                $newMediaBlock = '@media ' . $mediaBlock['prelude'] . " {\n" . $newInnerContent . "\n}";
                $this->content = substr($this->content, 0, $mediaBlock['start'])
                               . $newMediaBlock
                               . substr($this->content, $mediaBlock['end']);
            }
            $this->content = preg_replace('/\n{3,}/', "\n\n", $this->content);
            return true;
        }

        // Global scope
        $block = $this->findTopLevelBlock($selector);
        if ($block === null) return false;

        $this->content = substr($this->content, 0, $block['start'])
                       . substr($this->content, $block['end']);
        $this->content = preg_replace('/\n{3,}/', "\n\n", $this->content);
        return true;
    }
    
    /**
     * The at-rules a @keyframes block may sit inside: CSS allows one in a
     * conditional group rule, and every @keyframes command reaches it there.
     */
    private const KEYFRAMES_HOSTS = ['media', 'supports', 'layer', 'container', 'document', 'scope'];

    /**
     * Every @keyframes block, in document order, with its name and byte range, read
     * by the block tree: a brace inside a string or a comment never ends one early,
     * and text inside a comment or a string is never one. The name is a single word
     * of letters, digits, `_` and `-`; any other prelude is not taken as a name.
     *
     * @param int $offset added to every position (the block's place in the content
     *                    of the parser this one was cut from)
     * @return array<int, array{name: string, start: int, end: int, innerStart: int, innerEnd: int}>
     */
    private function keyframesBlocks(int $offset = 0): array {
        $found = [];
        foreach ($this->parseTopLevelBlocks() as $block) {
            if ($block['type'] !== 'atrule' || !empty($block['noBody'])) continue;
            $keyword = strtolower($block['keyword']);
            if ($keyword === 'keyframes') {
                if (preg_match('/^[\w-]+$/D', $block['prelude'])) {
                    $found[] = [
                        'name'       => $block['prelude'],
                        'start'      => $offset + $block['start'],
                        'end'        => $offset + $block['end'],
                        'innerStart' => $offset + $block['innerStart'],
                        'innerEnd'   => $offset + $block['innerEnd'],
                    ];
                }
            } elseif (in_array($keyword, self::KEYFRAMES_HOSTS, true)) {
                $inner = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);
                foreach ((new self($inner))->keyframesBlocks($offset + $block['innerStart']) as $nested) {
                    $found[] = $nested;
                }
            }
        }
        return $found;
    }

    /**
     * Get all @keyframes animations
     * @return array List of keyframe names and their content
     */
    public function getKeyframes(): array {
        if ($this->tooLarge) return [];   // an oversized sheet: refuse before the block tree allocates
        $keyframes = [];
        foreach ($this->keyframesBlocks() as $block) {
            $framesContent = substr($this->content, $block['innerStart'], $block['innerEnd'] - $block['innerStart']);

            // The frames, read by the block tree like any other rule: a frame's key
            // is the prelude before its brace, trimmed and otherwise verbatim — a
            // decimal (`12.5%`), a keyword list (`from, to`) — so setKeyframes takes
            // back exactly what this returns.
            $frames = [];
            $frameParser = new self($framesContent);
            foreach ($frameParser->parseTopLevelBlocks() as $frame) {
                if ($frame['type'] === 'rule') {
                    $frames[$frame['selector']] = trim(substr($framesContent, $frame['innerStart'], $frame['innerEnd'] - $frame['innerStart']));
                }
            }

            $keyframes[$block['name']] = $frames;
        }
        return $keyframes;
    }

    /**
     * Set/update a @keyframes animation. Every block of that name is replaced; with
     * none, the animation is appended.
     * @param string $name Animation name
     * @param array $frames Associative array of frame => styles
     * @return array Operation result
     */
    public function setKeyframes(string $name, array $frames): array {
        // On an oversized sheet, leave content untouched (the write cap
        // rejects the oversized file downstream).
        if ($this->tooLarge) {
            return ['action' => 'unchanged', 'name' => $name, 'frames' => array_keys($frames)];
        }
        $action = 'added';

        // Build the keyframes content
        $framesContent = '';
        foreach ($frames as $key => $styles) {
            $formattedStyles = $this->formatStyles($styles, '        ');
            $framesContent .= "    " . $key . " {\n" . $formattedStyles . "\n    }\n";
        }

        $newKeyframes = "@keyframes " . $name . " {\n" . $framesContent . "}";

        $blocks = array_filter($this->keyframesBlocks(), static fn(array $block) => $block['name'] === $name);
        if ($blocks) {
            // The last block first, so the positions of the earlier ones still hold.
            foreach (array_reverse($blocks) as $block) {
                $this->content = substr($this->content, 0, $block['start'])
                               . $newKeyframes
                               . substr($this->content, $block['end']);
            }
            $action = 'updated';
        } else {
            $this->content = $this->appendToCustomSection("\n" . $newKeyframes);
        }

        return [
            'action' => $action,
            'name' => $name,
            'frames' => array_keys($frames)
        ];
    }

    /**
     * Delete a @keyframes animation: every block of that name, with the whitespace
     * around each, which becomes one line break.
     * @param string $name Animation name
     * @return bool True if deleted, false if not found
     */
    public function deleteKeyframes(string $name): bool {
        if ($this->tooLarge) return false;   // an oversized sheet: refuse before the block tree allocates
        $blocks = array_filter($this->keyframesBlocks(), static fn(array $block) => $block['name'] === $name);
        if (!$blocks) {
            return false;
        }
        foreach (array_reverse($blocks) as $block) {
            $start = $block['start'];
            $end   = $block['end'];
            while ($start > 0 && ctype_space($this->content[$start - 1])) {
                $start--;
            }
            $length = strlen($this->content);
            while ($end < $length && ctype_space($this->content[$end])) {
                $end++;
            }
            $this->content = substr($this->content, 0, $start) . "\n" . substr($this->content, $end);
        }
        return true;
    }
    
    /**
     * Format CSS styles with proper indentation.
     */
    private function formatStyles(string $styles, string $indent = '    '): string {
        $formatted = [];
        foreach (self::readDeclarations($styles)[0] as $declaration) {
            $declaration = trim($declaration);
            if ($declaration !== '') {
                $formatted[] = $indent . $declaration . ';';
            }
        }
        return implode("\n", $formatted);
    }

    /**
     * Append content to the end of the stylesheet.
     */
    private function appendToCustomSection(string $content): string {
        return rtrim($this->content) . "\n" . $content . "\n";
    }

    /**
     * Remove a rule from global scope (not inside @media).
     * Returns the updated CSS content string.
     * Kept as a public method for external callers (e.g. injectSnippetCss.php).
     *
     * @param string $selector CSS selector to remove
     * @return string Updated CSS content
     */
    public function removeGlobalRule(string $selector): string {
        $block = $this->findTopLevelBlock($selector);
        if ($block === null) return $this->content;

        $result = substr($this->content, 0, $block['start'])
                . substr($this->content, $block['end']);
        return preg_replace('/\n{3,}/', "\n\n", $result);
    }
    
    /**
     * Extract CSS rules that match a set of classes, IDs, and tags
     * @param array $classes List of CSS class names (without .)
     * @param array $ids List of element IDs (without #)
     * @param array $tags List of HTML tag names
     * @param bool $includeRelated Include related selectors (e.g., .class:hover, .class::before)
     * @return array CSS rules grouped by scope (global, media queries)
     */
    public function getCssForSelectors(array $classes = [], array $ids = [], array $tags = [], bool $includeRelated = true): array {
        $result = [
            'global' => [],
            'mediaQueries' => [],
            'keyframes' => [],
            'rootVariables' => []
        ];
        
        // Build match patterns
        $patterns = [];
        
        foreach ($classes as $class) {
            $class = ltrim($class, '.');
            // Match .class anywhere in selector
            $patterns[] = '\.' . preg_quote($class, '/') . '(?![a-zA-Z0-9_-])';
        }
        
        foreach ($ids as $id) {
            $id = ltrim($id, '#');
            $patterns[] = '#' . preg_quote($id, '/') . '(?![a-zA-Z0-9_-])';
        }
        
        foreach ($tags as $tag) {
            // Match tag at word boundaries (not preceded/followed by alphanumeric)
            $patterns[] = '(?<![a-zA-Z0-9_-])' . preg_quote($tag, '/') . '(?![a-zA-Z0-9_-])';
        }
        
        if (empty($patterns)) {
            return $result;
        }
        
        $combinedPattern = '/(' . implode('|', $patterns) . ')/i';
        
        // Get all selectors (returns flat array with 'selector' and 'mediaQuery' keys)
        $allSelectors = $this->listSelectors();
        
        // Process each selector
        foreach ($allSelectors as $selectorInfo) {
            $selector = $selectorInfo['selector'];
            $mediaQuery = $selectorInfo['mediaQuery'];
            
            if (preg_match($combinedPattern, $selector)) {
                $rule = $this->getStyleRule($selector, $mediaQuery);
                if ($rule !== null) {
                    if ($mediaQuery === null) {
                        // Global rule
                        $result['global'][$selector] = $rule['styles'];
                    } else {
                        // Media query rule
                        if (!isset($result['mediaQueries'][$mediaQuery])) {
                            $result['mediaQueries'][$mediaQuery] = [];
                        }
                        $result['mediaQueries'][$mediaQuery][$selector] = $rule['styles'];
                    }
                }
            }
        }
        
        // Check if any matched rules use animations
        $allCss = implode(' ', array_values($result['global']));
        foreach ($result['mediaQueries'] as $rules) {
            $allCss .= ' ' . implode(' ', array_values($rules));
        }
        
        // Extract animation names used
        if (preg_match_all('/animation(?:-name)?:\s*([a-zA-Z0-9_-]+)/i', $allCss, $animMatches)) {
            $keyframes = $this->getKeyframes();
            foreach (array_unique($animMatches[1]) as $animName) {
                if (isset($keyframes[$animName])) {
                    $result['keyframes'][$animName] = $keyframes[$animName];
                }
            }
        }
        
        // Extract CSS variables used
        if (preg_match_all('/var\((--[a-zA-Z0-9_-]+)\)/i', $allCss, $varMatches)) {
            $rootVars = $this->getRootVariables();
            foreach (array_unique($varMatches[1]) as $varName) {
                if (isset($rootVars[$varName])) {
                    $result['rootVariables'][$varName] = $rootVars[$varName];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Format extracted CSS as a string
     * @param array $cssData Output from getCssForSelectors
     * @return string Formatted CSS
     */
    public function formatExtractedCss(array $cssData): string {
        $output = '';
        
        // Root variables
        if (!empty($cssData['rootVariables'])) {
            $output .= ":root {\n";
            foreach ($cssData['rootVariables'] as $name => $value) {
                // $name already includes -- prefix
                $output .= "    {$name}: {$value};\n";
            }
            $output .= "}\n\n";
        }
        
        // Global rules
        foreach ($cssData['global'] as $selector => $styles) {
            $output .= "{$selector} {\n{$styles}\n}\n\n";
        }
        
        // Media queries
        foreach ($cssData['mediaQueries'] as $media => $rules) {
            $output .= "@media {$media} {\n";
            foreach ($rules as $selector => $styles) {
                // Indent styles
                $indentedStyles = preg_replace('/^/m', '    ', $styles);
                $output .= "    {$selector} {\n{$indentedStyles}\n    }\n";
            }
            $output .= "}\n\n";
        }
        
        // Keyframes
        foreach ($cssData['keyframes'] as $name => $frames) {
            $output .= "@keyframes {$name} {\n";
            foreach ($frames as $key => $styles) {
                $output .= "    {$key} {\n        {$styles}\n    }\n";
            }
            $output .= "}\n\n";
        }
        
        return trim($output);
    }
    
}