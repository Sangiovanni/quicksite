<?php
/**
 * RegexPatterns - Centralized regex pattern management
 * 
 * Provides named, documented regex patterns for validation across the project.
 * Supports different character sets (Latin, Extended Latin, Cyrillic, etc.)
 * 
 * Usage:
 *   RegexPatterns::match('language_name', $value)
 *   RegexPatterns::getDescription('language_name')
 *   RegexPatterns::validationError('language_name', 'name', $value)
 * 
 * Adding a pattern:
 *   - A validator is anchored `^…$` AND carries the `D` modifier. Without `D`,
 *     `$` also matches just before a final newline, so `/^[a-z]+$/` accepts
 *     "home\n" — a value no legitimate input has.
 *   - Where a validated value may hold whitespace, the pattern names the
 *     whitespace it means: a plain space in a label, CSS whitespace (space,
 *     tab, LF, CR, FF) in CSS text. Not `\s`, which also matches a vertical
 *     tab and, under `u`, every Unicode space and line separator.
 *   - A detector searches anywhere in a value. It has no `^…$`, so `D` changes
 *     nothing, and a broad whitespace class in it widens what it refuses.
 *   - A pattern is added together with its caller. The catalogue holds only
 *     patterns something uses.
 * 
 * @version 1.1.0
 */

class RegexPatterns
{
    /**
     * Pattern definitions
     * Each pattern has:
     * - 'pattern': The regex pattern
     * - 'description': Human-readable description for error messages
     * - 'examples': Valid examples
     */
    private static array $patterns = [
        // === IDENTIFIERS (strict ASCII) ===
        
        'language_code' => [
            'pattern' => '/^[a-z]{2,3}$/D',
            'description' => '2-3 lowercase letters (ISO 639-1 or 639-2)',
            'examples' => ['en', 'fr', 'es', 'zho']
        ],
        
        'language_code_extended' => [
            'pattern' => '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/D',
            'description' => 'Language code with optional region (e.g., en, en-US, zh-Hans)',
            'examples' => ['en', 'fr', 'en-US', 'zh-Hans']
        ],
        
        'identifier_alphanum' => [
            'pattern' => '/^[a-zA-Z0-9_-]+$/D',
            'description' => 'Letters, numbers, underscores, and hyphens',
            'examples' => ['myComponent', 'user-card', 'item_123']
        ],
        
        'component_name' => [
            'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D',
            'description' => 'Component reference: starts with a letter, then letters, numbers, hyphens and underscores (no path separators, no dots)',
            'examples' => ['lang-switch', 'feature-card', 'img-dynamic', 'my_card']
        ],
        
        'file_name_with_ext' => [
            'pattern' => '/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$/D',
            'description' => 'Filename with extension (alphanumeric name, dot, extension)',
            'examples' => ['image.png', 'my-file.pdf', 'document.json']
        ],
        
        'keyframe_name' => [
            'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/D',
            'description' => 'CSS keyframe/animation name (starts with letter)',
            'examples' => ['fadeIn', 'slide-up', 'bounce_effect']
        ],
        
        // CSS whitespace between the percentages, not only a space: the
        // stylesheet parser hands a frame list out with its inner line breaks
        // ("0%,\n    100%"), and the console sends it back as it came.
        'keyframe_selector' => [
            'pattern' => '/^(\d+%([ \t\n\r\f]*,[ \t\n\r\f]*\d+%)*|from|to)$/iD',
            'description' => 'Keyframe selector (percentage, from, or to)',
            'examples' => ['0%', '50%', '100%', 'from', 'to', '0%, 50%, 100%']
        ],
        
        'build_name' => [
            'pattern' => '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}$/D',
            'description' => 'Build folder name (auto-generated like build_YYYYMMDD_HHMMSS or custom name)',
            'examples' => ['build_20241217_143052', 'v2-staging', 'my_production_build']
        ],
        
        // === HUMAN-READABLE TEXT (Unicode support) ===
        
        'language_name' => [
            'pattern' => '/^[\p{L} \-\'\.]+$/uD',
            'description' => 'Language display name (any letters, spaces, hyphens, apostrophes, dots)',
            'examples' => ['English', 'Français', 'Español', 'Русский', '日本語', "Kreyòl ayisyen"]
        ],
        
        // === STRUCTURED DATA ===
        
        'node_id' => [
            'pattern' => '/^[0-9]+(\.[0-9]+)*$/D',
            'description' => 'Node identifier (dot-separated indices)',
            'examples' => ['0', '0.1', '0.1.2', '3.0.1']
        ],
        
        // === DATE/TIME ===
        
        'date_iso' => [
            'pattern' => '/^\d{4}-\d{2}-\d{2}$/D',
            'description' => 'ISO date format (YYYY-MM-DD)',
            'examples' => ['2024-12-17', '2025-01-01']
        ],
        
        // === SECURITY-SENSITIVE ===
        
        'url_alias' => [
            'pattern' => '/^\/[a-zA-Z0-9\/_-]*$/D',
            'description' => 'URL alias path (starts with /, alphanumeric)',
            'examples' => ['/app', '/v2/api', '/old-page']
        ],
        
        // === HTML/CSS SECURITY ===
        
        // `D` is load-bearing: without it `$` also matches before a final
        // newline, so `src\n` would pass while a browser reads it as `src`.
        'html_attribute_name' => [
            'pattern' => '/^[a-z0-9_:-]+$/iD',
            'description' => 'Valid HTML attribute name',
            'examples' => ['class', 'data-id', 'aria-label', 'xml:lang']
        ],
        
        'css_injection' => [
            'pattern' => '/[<>{}]|javascript:|expression\s*\(/i',
            'description' => 'Potentially dangerous CSS content (for blocking)',
            'examples' => ['javascript:alert(1)', 'expression(alert(1))', '<script>']
        ],
        
        // === MEDIA QUERY ===
        
        // One character set for both: letters, digits and - _ ( ) : , . / < > = + *,
        // which is everything a media query is written with, ratios (16/9) and
        // range syntax (width >= 600px) included. CSS whitespace goes only
        // between tokens, for the reason given at keyframe_selector.
        'media_query_basic' => [
            'pattern' => '/^\([\w\-(:,.\/<>=+* \t\n\r\f]+\)$/D',
            'description' => 'A single media feature in parentheses',
            'examples' => ['(max-width: 768px)', '(orientation: landscape)', '(400px <= width <= 700px)']
        ],
        
        'media_query_chars' => [
            'pattern' => '/^[\w\-():,.\/<>=+*]+([ \t\n\r\f]+[\w\-():,.\/<>=+*]+)*$/D',
            'description' => 'Media query: letters, digits and - _ ( ) : , . / < > = + *, with whitespace between words',
            'examples' => ['max-width: 768px', 'screen and (color)', 'print', 'screen and (width >= 768px)']
        ],
    ];
    
    /**
     * Test if a value matches a named pattern
     * 
     * @param string $patternName The pattern identifier
     * @param string $value The value to test
     * @return bool True if matches
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function match(string $patternName, string $value): bool
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return (bool) preg_match(self::$patterns[$patternName]['pattern'], $value);
    }
    
    /**
     * Get the human-readable description for error messages
     * 
     * @param string $patternName The pattern identifier
     * @return string The description
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function getDescription(string $patternName): string
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return self::$patterns[$patternName]['description'];
    }
    
    /**
     * Get examples of valid values
     * 
     * @param string $patternName The pattern identifier
     * @return array Valid examples
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function getExamples(string $patternName): array
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return self::$patterns[$patternName]['examples'];
    }
    
    /**
     * Validate and return formatted error data for API responses
     * 
     * @param string $patternName The pattern identifier
     * @param string $field The field name being validated
     * @param mixed  $value The value that failed validation. Deliberately NOT
     *        typed `string`: callers reach here precisely because the value was
     *        the wrong shape, and a `string` type would make the error path
     *        itself fatal when handed `?field[]=x`. Non-strings are rendered
     *        for display rather than echoed back raw.
     * @return array Error data array for API response
     */
    public static function validationError(string $patternName, string $field, $value): array
    {
        if (!is_string($value)) {
            $value = is_scalar($value) || $value === null
                ? var_export($value, true)
                : '(' . gettype($value) . ')';
        }
        return [
            'field' => $field,
            'value' => $value,
            'expected' => self::getDescription($patternName),
            'examples' => self::getExamples($patternName)
        ];
    }
}
