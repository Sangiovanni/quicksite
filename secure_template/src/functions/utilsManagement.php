<?php


/**
 * Generate page template content for a new route
 * 
 * @param string $route The route name
 * @return string The complete PHP page template content
 */
function generate_page_template(string $route): string {
    return <<<PHP
<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
\$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
\$translator = new Translator(\$trimParameters->lang());
\$lang = \$trimParameters->lang();

require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
\$renderer = new JsonToHtmlRenderer(\$translator, ['lang' => \$lang, 'page' => '{$route}']);

\$content = \$renderer->renderPage('{$route}');

require_once SECURE_FOLDER_PATH . '/src/classes/PageManagement.php';

// Get page title from translation
\$pageTitle = \$translator->translate('page.titles.{$route}');

\$page = new PageManagement(\$pageTitle, \$content, \$lang);
\$page->render();

PHP;
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


function validateStructureDepth($node, $depth = 0, $maxDepth = 50): bool {
    if ($depth > $maxDepth) {
        return false;
    }
    
    if (!is_array($node)) {
        return true;
    }
    
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (!validateStructureDepth($child, $depth + 1, $maxDepth)) {
                return false;
            }
        }
    }
    
    return true;
}


function countNodes($structure): int {
    if (!is_array($structure)) {
        return 0;
    }
    
    $count = 1;
    
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            $count += countNodes($child);
        }
    }
    
    return $count;
}