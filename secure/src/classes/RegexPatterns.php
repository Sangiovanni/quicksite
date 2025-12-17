<?php
/**
 * RegexPatterns - Centralized regex pattern management
 * 
 * Provides named, documented regex patterns for validation across the project.
 * Supports different character sets (Latin, Extended Latin, Cyrillic, etc.)
 * 
 * Usage:
 *   RegexPatterns::match('language_name', $value)
 *   RegexPatterns::get('route_name')
 *   RegexPatterns::getDescription('language_name')
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
        
        'route_name' => [
            'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'description' => 'Lowercase letters, numbers, and hyphens (no consecutive or trailing hyphens)',
            'examples' => ['home', 'about-us', 'contact-form', '404']
        ],
        
        'route_name_simple' => [
            'pattern' => '/^[a-z0-9-]+$/',
            'description' => 'Lowercase letters, numbers, and hyphens',
            'examples' => ['home', 'about-us', '404']
        ],
        
        'language_code' => [
            'pattern' => '/^[a-z]{2,3}$/',
            'description' => '2-3 lowercase letters (ISO 639-1 or 639-2)',
            'examples' => ['en', 'fr', 'es', 'zho']
        ],
        
        'language_code_extended' => [
            'pattern' => '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/',
            'description' => 'Language code with optional region (e.g., en, en-US, zh-Hans)',
            'examples' => ['en', 'fr', 'en-US', 'zh-Hans']
        ],
        
        'variable_name' => [
            'pattern' => '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'description' => 'Letters, numbers, underscores (cannot start with number)',
            'examples' => ['myVar', 'user_name', '_private']
        ],
        
        'identifier_alphanum' => [
            'pattern' => '/^[a-zA-Z0-9_-]+$/',
            'description' => 'Letters, numbers, underscores, and hyphens',
            'examples' => ['myComponent', 'user-card', 'item_123']
        ],
        
        'css_variable' => [
            'pattern' => '/^--[a-zA-Z][a-zA-Z0-9-]*$/',
            'description' => 'CSS custom property (starts with --)',
            'examples' => ['--color-primary', '--font-size-base', '--spacing-md']
        ],
        
        'css_selector' => [
            'pattern' => '/^[a-zA-Z#.\[\]:\s,>+~*="\'\-_()^$|0-9]+$/',
            'description' => 'Valid CSS selector characters',
            'examples' => ['.class', '#id', 'div > p', '[data-attr="value"]']
        ],
        
        'file_name_safe' => [
            'pattern' => '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/',
            'description' => 'Safe filename (alphanumeric, dots, hyphens, underscores)',
            'examples' => ['image.png', 'my-file_v2.pdf', 'document.json']
        ],
        
        'file_name_with_ext' => [
            'pattern' => '/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$/',
            'description' => 'Filename with extension (alphanumeric name, dot, extension)',
            'examples' => ['image.png', 'my-file.pdf', 'document.json']
        ],
        
        'favicon_file' => [
            'pattern' => '/^[a-z0-9_-]+\.png$/i',
            'description' => 'PNG favicon filename (alphanumeric with .png extension)',
            'examples' => ['favicon.png', 'icon-32.png', 'logo_small.png']
        ],
        
        'token_name' => [
            'pattern' => '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,49}$/',
            'description' => 'Token name (alphanumeric, hyphens, underscores, max 50 chars)',
            'examples' => ['api-token', 'dev_access', 'user123']
        ],
        
        'keyframe_name' => [
            'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/',
            'description' => 'CSS keyframe/animation name (starts with letter)',
            'examples' => ['fadeIn', 'slide-up', 'bounce_effect']
        ],
        
        'keyframe_selector' => [
            'pattern' => '/^(\d+%(\s*,\s*\d+%)*|from|to)$/i',
            'description' => 'Keyframe selector (percentage, from, or to)',
            'examples' => ['0%', '50%', '100%', 'from', 'to', '0%, 50%, 100%']
        ],
        
        'build_name' => [
            'pattern' => '/^build_\d{8}_\d{6}$/',
            'description' => 'Build identifier (build_YYYYMMDD_HHMMSS)',
            'examples' => ['build_20241217_143052', 'build_20240101_000000']
        ],
        
        'build_name_parse' => [
            'pattern' => '/^build_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/',
            'description' => 'Build identifier with capture groups for date/time parts',
            'examples' => ['build_20241217_143052']
        ],
        
        // === HUMAN-READABLE TEXT (Unicode support) ===
        
        'language_name' => [
            'pattern' => '/^[\p{L}\s\-\'\.]+$/u',
            'description' => 'Language display name (any letters, spaces, hyphens, apostrophes, dots)',
            'examples' => ['English', 'Français', 'Español', 'Русский', '日本語', "Kreyòl ayisyen"]
        ],
        
        'display_name' => [
            'pattern' => '/^[\p{L}\p{N}\s\-\'\.,:!?]+$/u',
            'description' => 'Display name with basic punctuation (any letters, numbers, common punctuation)',
            'examples' => ['My Project', "John's Site", 'Café 2024', 'Привет, мир!']
        ],
        
        'title_text' => [
            'pattern' => '/^[\p{L}\p{N}\p{P}\p{S}\s]+$/u',
            'description' => 'Title text (letters, numbers, punctuation, symbols, spaces)',
            'examples' => ['Welcome to My Site!', 'About Us — Our Story', '株式会社 ABC']
        ],
        
        // === STRUCTURED DATA ===
        
        'translation_key' => [
            'pattern' => '/^[a-zA-Z][a-zA-Z0-9_.]*[a-zA-Z0-9]$|^[a-zA-Z]$/',
            'description' => 'Translation key (dot-notation path)',
            'examples' => ['nav.home', 'common.buttons.submit', 'errors.not_found']
        ],
        
        'translation_key_simple' => [
            'pattern' => '/^[a-zA-Z0-9._-]+$/',
            'description' => 'Translation key segment (alphanumeric, dots, underscores, hyphens)',
            'examples' => ['nav.home', 'button_text', 'error-message']
        ],
        
        'json_path' => [
            'pattern' => '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/',
            'description' => 'JSON object path (dot-separated keys)',
            'examples' => ['user.name', 'config.settings.theme', 'data']
        ],
        
        'node_id' => [
            'pattern' => '/^[0-9]+(\.[0-9]+)*$/',
            'description' => 'Node identifier (dot-separated indices)',
            'examples' => ['0', '0.1', '0.1.2', '3.0.1']
        ],
        
        'node_id_with_slots' => [
            'pattern' => '/^[0-9]+(\.(slots\.[a-zA-Z0-9_-]+\.)?[0-9]+)*$/',
            'description' => 'Node identifier with slot support',
            'examples' => ['0', '0.slots.header.1', '0.1.slots.content.0']
        ],
        
        // === DATE/TIME ===
        
        'date_iso' => [
            'pattern' => '/^\d{4}-\d{2}-\d{2}$/',
            'description' => 'ISO date format (YYYY-MM-DD)',
            'examples' => ['2024-12-17', '2025-01-01']
        ],
        
        'log_file_date' => [
            'pattern' => '/commands_(\d{4}-\d{2}-\d{2})\.json$/',
            'description' => 'Log filename with date capture group',
            'examples' => ['commands_2024-12-17.json']
        ],
        
        // === SECURITY-SENSITIVE ===
        
        'safe_path_segment' => [
            'pattern' => '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/',
            'description' => 'Safe path segment (no traversal)',
            'examples' => ['images', 'uploads-2024', 'file.txt']
        ],
        
        'safe_relative_path' => [
            'pattern' => '/^[a-zA-Z0-9_\-]+([\/][a-zA-Z0-9_\-]+)*$/',
            'description' => 'Safe relative path (alphanumeric segments separated by /)',
            'examples' => ['images', 'assets/images', 'uploads/2024/files']
        ],
        
        'no_path_traversal' => [
            'pattern' => '/^(?!.*\.\.)(?!.*[\/\\\\])[\p{L}\p{N}\s._-]+$/u',
            'description' => 'No path traversal characters (no .., /, \\)',
            'examples' => ['my-file.txt', 'document', 'image_2024.png']
        ],
        
        'url_alias' => [
            'pattern' => '/^\/[a-zA-Z0-9\/_-]*$/',
            'description' => 'URL alias path (starts with /, alphanumeric)',
            'examples' => ['/app', '/v2/api', '/old-page']
        ],
        
        'permission_command' => [
            'pattern' => '/^command:[a-zA-Z]+$/',
            'description' => 'Command-specific permission',
            'examples' => ['command:build', 'command:addRoute', 'command:editStructure']
        ],
        
        // === HTML/CSS SECURITY ===
        
        'html_tag_name' => [
            'pattern' => '/^[a-z0-9-]+$/i',
            'description' => 'Valid HTML tag name',
            'examples' => ['div', 'span', 'my-component', 'h1']
        ],
        
        'html_attribute_name' => [
            'pattern' => '/^[a-z0-9_:-]+$/i',
            'description' => 'Valid HTML attribute name',
            'examples' => ['class', 'data-id', 'aria-label', 'xml:lang']
        ],
        
        'event_handler' => [
            'pattern' => '/^on[a-z]+$/i',
            'description' => 'Event handler attribute (blocked for security)',
            'examples' => ['onclick', 'onload', 'onerror']
        ],
        
        'css_injection' => [
            'pattern' => '/[<>{}]|javascript:|expression\s*\(/i',
            'description' => 'Potentially dangerous CSS content (for blocking)',
            'examples' => ['javascript:alert(1)', 'expression(alert(1))', '<script>']
        ],
        
        'dangerous_url_scheme' => [
            'pattern' => '/^(javascript|data|vbscript):/i',
            'description' => 'Dangerous URL schemes (blocked for security)',
            'examples' => ['javascript:alert(1)', 'data:text/html', 'vbscript:msgbox']
        ],
        
        'external_url' => [
            'pattern' => '/^(https?:)?\/\//i',
            'description' => 'External URL (starts with http://, https://, or //)',
            'examples' => ['https://example.com', '//cdn.example.com', 'http://localhost']
        ],
        
        'safe_url_scheme' => [
            'pattern' => '/^(#|mailto:|tel:)/i',
            'description' => 'Safe special URL schemes',
            'examples' => ['#section', 'mailto:info@example.com', 'tel:+1234567890']
        ],
        
        'asset_path' => [
            'pattern' => '/^\/(assets|style)\//i',
            'description' => 'Asset folder path',
            'examples' => ['/assets/images/logo.png', '/style/main.css']
        ],
        
        // === MEDIA QUERY ===
        
        'media_query_basic' => [
            'pattern' => '/^\([^)]+\)$|^screen\s|^print\s|^all\s/i',
            'description' => 'Basic media query format',
            'examples' => ['(max-width: 768px)', 'screen and', 'print']
        ],
        
        'media_query_chars' => [
            'pattern' => '/^[\w\s\-\(\)\:\,\.]+$/',
            'description' => 'Valid media query characters',
            'examples' => ['max-width: 768px', 'screen and (color)']
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
     * Test if a value matches a named pattern (returns matches)
     * 
     * @param string $patternName The pattern identifier
     * @param string $value The value to test
     * @param array &$matches Matches will be stored here
     * @return bool True if matches
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function matchWithCapture(string $patternName, string $value, array &$matches): bool
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return (bool) preg_match(self::$patterns[$patternName]['pattern'], $value, $matches);
    }
    
    /**
     * Get the raw regex pattern
     * 
     * @param string $patternName The pattern identifier
     * @return string The regex pattern
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function get(string $patternName): string
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return self::$patterns[$patternName]['pattern'];
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
     * Get full pattern info (pattern, description, examples)
     * 
     * @param string $patternName The pattern identifier
     * @return array Pattern info
     * @throws InvalidArgumentException If pattern doesn't exist
     */
    public static function getInfo(string $patternName): array
    {
        if (!isset(self::$patterns[$patternName])) {
            throw new InvalidArgumentException("Unknown regex pattern: {$patternName}");
        }
        
        return self::$patterns[$patternName];
    }
    
    /**
     * List all available pattern names
     * 
     * @return array Pattern names
     */
    public static function listPatterns(): array
    {
        return array_keys(self::$patterns);
    }
    
    /**
     * Validate and return formatted error data for API responses
     * 
     * @param string $patternName The pattern identifier
     * @param string $field The field name being validated
     * @param string $value The value that failed validation
     * @return array Error data array for API response
     */
    public static function validationError(string $patternName, string $field, string $value): array
    {
        return [
            'field' => $field,
            'value' => $value,
            'expected' => self::getDescription($patternName),
            'examples' => self::getExamples($patternName)
        ];
    }
}
