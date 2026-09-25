<?php

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