<?php

require_once __DIR__ . '/../classes/RegexPatterns.php';

// Polyfill for str_starts_with (PHP <8.0)
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function removePrefix(string $haystack, string $prefix): string {
    // Normalize both to trim trailing slashes for comparison
    $haystackNorm = rtrim($haystack, '/');
    $prefixNorm = rtrim($prefix, '/');

    // If haystack is exactly the prefix (with or without trailing slash), return empty string
    if ($haystackNorm === $prefixNorm) {
        return '';
    }
    // If haystack starts with prefix (with trailing slash), remove it
    if (str_starts_with($haystack, $prefix)) {
        return substr($haystack, strlen($prefix));
    }
    // If haystack starts with prefix (no trailing slash), remove it and any leading slash
    if ($prefix && str_starts_with($haystack, $prefixNorm)) {
        $rest = substr($haystack, strlen($prefixNorm));
        return ltrim($rest, '/');
    }
    // Otherwise, return original string
    return $haystack;
}

/**
 * Checks if a string is composed ONLY of 26 lowercase English letters (a-z).
 * * @param string $input The string to validate.
 * @return bool True if the string contains only a-z, false otherwise.
 */
function is_valid_route_name(string $input): bool
{
    if (empty($input)) {
        return false;
    }
    return RegexPatterns::match('route_name_simple', $input);
}

/**
 * Checks if a string is composed ONLY of letters (including international/accented),
 * numbers (0-9), and hyphens (-).
 *
 * @param string $input The string to validate.
 * @return bool True if the string is a valid international route name, false otherwise.
 */
function is_valid_word_string(string $input): bool
{
    if (empty($input)) {
        return false;
    }
    // Using raw pattern here since this is a generic utility function
    // that may need Unicode support beyond what's in RegexPatterns
    $pattern = '/^[\p{L}0-9-]+$/u';
    
    return (bool)preg_match($pattern, $input);
}

/**
 * Checks if a string is a valid absolute URL (requires protocol like http:// or https://).
 *
 * @param string $url The string to validate.
 * @return bool True if the string is a valid URL, false otherwise.
 */
function is_valid_absolute_url(string $url): bool
{
    // FILTER_VALIDATE_URL checks for proper URL syntax and requires a scheme (e.g., http/https).
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}