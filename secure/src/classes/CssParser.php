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

    public function __construct(string $content) {
        $this->content = $content;
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

            // Find the next opening brace (the prelude ends here)
            $bracePos = $this->findNextOpenBrace($pos);
            if ($bracePos === false) break;

            $prelude = trim(substr($this->content, $pos, $bracePos - $pos));
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
        preg_match_all('/(--.+?)\s*:\s*([^;]+);/s', $inner, $matches, PREG_SET_ORDER);
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
                $varPattern = '/(' . preg_quote($varName, '/') . '\s*:\s*)([^;]+)(;)/s';
                if (preg_match($varPattern, $newInner)) {
                    $safeValue  = str_replace(['\\', '$'], ['\\\\', '\\$'], $varValue);
                    $newInner   = preg_replace($varPattern, '${1}' . $safeValue . '${3}', $newInner);
                    $updated[$varName] = $varValue;
                } else {
                    $newInner         = rtrim($newInner) . "\n    " . $varName . ': ' . $varValue . ";\n";
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
     * Build a regex pattern for matching a scope block.
     * @deprecated Use findTopLevelBlock() instead.
     */
    private function buildScopePattern(string $selector): string {
        $trimmed = trim($selector);
        if ($trimmed === '[data-theme="dark"]' || $trimmed === "[data-theme='dark']") {
            return '/\[data-theme\s*=\s*["\']dark["\']\]\s*\{([^}]+)\}/s';
        }
        $escapedSelector = preg_quote($selector, '/');
        return '/' . $escapedSelector . '\s*\{([^}]+)\}/s';
    }

    /**
     * Set/update :root variables (wrapper around setVariablesInScope for :root)
     * @param array $variables Associative array of variable name => value
     * @return array Summary of changes
     */
    public function setRootVariables(array $variables): array {
        return $this->setVariablesInScope($variables, ':root');
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
    
    /**
     * Parse CSS style declarations into an associative array
     * @param string $styles CSS declarations string
     * @return array Property => value pairs
     */
    private function parseStyleDeclarations(string $styles): array {
        $result = [];
        // Split by semicolons, but be careful with values containing semicolons (rare but possible)
        $declarations = preg_split('/;\s*/', trim($styles), -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($declarations as $declaration) {
            $declaration = trim($declaration);
            if (empty($declaration)) continue;
            
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
     * Get all @keyframes animations
     * @return array List of keyframe names and their content
     */
    public function getKeyframes(): array {
        $keyframes = [];
        
        preg_match_all('/@keyframes\s+([\w-]+)\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $this->content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $name = trim($match[1]);
            $framesContent = trim($match[2]);
            
            // Parse individual frames
            $frames = [];
            preg_match_all('/([\d%,\s]+|from|to)\s*\{([^}]*)\}/s', $framesContent, $frameMatches, PREG_SET_ORDER);
            
            foreach ($frameMatches as $frame) {
                $key = trim($frame[1]);
                $value = trim($frame[2]);
                $frames[$key] = $value;
            }
            
            $keyframes[$name] = $frames;
        }
        
        return $keyframes;
    }
    
    /**
     * Set/update a @keyframes animation
     * @param string $name Animation name
     * @param array $frames Associative array of frame => styles
     * @return array Operation result
     */
    public function setKeyframes(string $name, array $frames): array {
        $escapedName = preg_quote($name, '/');
        $action = 'added';
        
        // Build the keyframes content
        $framesContent = '';
        foreach ($frames as $key => $styles) {
            $formattedStyles = $this->formatStyles($styles, '        ');
            $framesContent .= "    " . $key . " {\n" . $formattedStyles . "\n    }\n";
        }
        
        $newKeyframes = "@keyframes " . $name . " {\n" . $framesContent . "}";
        
        // Check if keyframes already exists
        $pattern = '/@keyframes\s+' . $escapedName . '\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s';
        
        if (preg_match($pattern, $this->content)) {
            // Update existing
            $this->content = preg_replace($pattern, $newKeyframes, $this->content);
            $action = 'updated';
        } else {
            // Add new
            $this->content = $this->appendToCustomSection("\n" . $newKeyframes);
        }
        
        return [
            'action' => $action,
            'name' => $name,
            'frames' => array_keys($frames)
        ];
    }
    
    /**
     * Delete a @keyframes animation
     * @param string $name Animation name
     * @return bool True if deleted, false if not found
     */
    public function deleteKeyframes(string $name): bool {
        $escapedName = preg_quote($name, '/');
        $pattern = '/\s*@keyframes\s+' . $escapedName . '\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}\s*/s';
        
        if (preg_match($pattern, $this->content)) {
            $this->content = preg_replace($pattern, "\n", $this->content);
            return true;
        }
        
        return false;
    }
    
    /**
     * Format CSS styles with proper indentation.
     */
    private function formatStyles(string $styles, string $indent = '    '): string {
        $declarations = array_filter(array_map('trim', explode(';', $styles)));
        $formatted    = [];
        foreach ($declarations as $declaration) {
            if ($declaration !== '') {
                $formatted[] = $indent . trim($declaration) . ';';
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