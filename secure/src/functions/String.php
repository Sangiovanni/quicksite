<?php

require_once __DIR__ . '/../classes/RegexPatterns.php';

function removePrefix(string $haystack, string $prefix): string {
    // 1. Check if the $haystack string starts with the $prefix
    if (str_starts_with($haystack, $prefix)) {
        // 2. If it does, remove the $prefix part.
        //    The length of the $prefix is used as the starting offset for substr().
        return substr($haystack, strlen($prefix));
    }

    // 3. If it doesn't start with the $prefix, return the original string.
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