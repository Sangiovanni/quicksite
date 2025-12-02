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


/**
 * Generate page template content for a new route
 * 
 * @param string $route_name The route name
 * @param string $page_title Optional page title (defaults to route name)
 * @return string The complete PHP page template content
 */
function generate_page_template(string $route_name): string {
    $page_title = ucwords(str_replace('-', ' ', $route_name));
    
    $template = <<<'PHP'
<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$lang = $trimParameters->lang();

require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
$renderer = new JsonToHtmlRenderer($translator);

$content = $renderer->renderPage('{{ROUTE_NAME}}');

require_once SECURE_FOLDER_PATH . '/src/classes/Page.php';

$page = new Page("{{PAGE_TITLE}}", $content, $lang);
$page->render();

PHP;

    // Replace placeholders
    $template = str_replace('{{ROUTE_NAME}}', $route_name, $template);
    $template = str_replace('{{PAGE_TITLE}}', $page_title, $template);
    
    return $template;
}

/**
 * Generate default JSON page structure
 * 
 * @param string $route_name The route name
 * @return string JSON content for the page
 */
function generate_page_json(string $route_name): string {
    $json_structure = [];
    
    return json_encode($json_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}