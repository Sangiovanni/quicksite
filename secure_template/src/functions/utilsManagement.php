<?php

/**
 * Counts the number of existing external links in a menu array
 * and returns the next sequential number for a new link label (e.g., if count is 3, return 4).
 *
 * @param array $menu_array The array of menu items loaded from the config file.
 * @return int The next available sequential number (starting at 1 if none exist).
 */
function get_next_external_link_number(array $menu_array): int
{
    // Use array_filter to keep only the elements where 'path' is null,
    // which signifies an external link configuration.
    $external_links = array_filter($menu_array, function ($item) {
        // We strictly check for null to ensure we only count external links.
        return array_key_exists('path', $item) && $item['path'] === null;
    });

    // The count of the filtered array gives us the number of existing external links.
    $existing_count = count($external_links);

    // The next number is always (existing count + 1).
    return $existing_count + 1;
}