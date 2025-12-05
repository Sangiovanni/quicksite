<?php

/**
 * JsonToHtmlRenderer
 * 
 * Renders JSON page structures to HTML.
 * Supports:
 * - Tag nodes: {tag, params, children}
 * - Text nodes: {textKey}
 * - Components: {component, data}
 */
class JsonToHtmlRenderer {
    private $context = [];
    private $translator;
    private $componentCache = [];
    private $componentsPath;

    /**
     * @param Translator $translator Instance of Translator for text resolution
     * @param array $context Context data (lang, page, etc.)
     */
    public function __construct($translator, $context = []) {
        $this->translator = $translator;
        $this->context = $context;
        $this->componentsPath = SECURE_FOLDER_PATH . '/templates/model/json/components/';
    }

    /**
     * Render a page from its JSON file
     * 
     * @param string $pageName Name of the page (e.g., 'home', 'about')
     * @return string Rendered HTML
     */
    public function renderPage(string $pageName): string {
        return $this->renderJsonFile("/templates/model/json/pages/{$pageName}.json");
    }

    /**
     * Render menu - just load and render the JSON
     */
    public function renderMenu(): string {
        return $this->renderJsonFile('/templates/model/json/menu.json');
    }

    /**
     * Render footer - just load and render the JSON
     */
    public function renderFooter(): string {
        return $this->renderJsonFile('/templates/model/json/footer.json');
    }

    /**
     * Render JSON file
     * 
     * @param string $relativePath Path relative to SECURE_FOLDER_PATH
     * @return string Rendered HTML
     */
    private function renderJsonFile(string $relativePath): string {
        $jsonPath = SECURE_FOLDER_PATH . $relativePath;
        
        if (!file_exists($jsonPath)) {
            error_log("JSON file not found: {$jsonPath}");
            return "<!-- JSON not found: {$relativePath} -->";
        }

        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            error_log("Failed to read JSON: {$jsonPath}");
            return "<!-- Failed to read JSON -->";
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON: {$jsonPath} - " . json_last_error_msg());
            return "<!-- Invalid JSON -->";
        }

        if (!is_array($data)) {
            error_log("JSON must be an array: {$jsonPath}");
            return "<!-- JSON must be an array -->";
        }

        return $this->renderNodes($data);
    }

    /**
     * Render an array of nodes
     * 
     * @param array $nodes Array of node objects
     * @return string Rendered HTML
     */
    private function renderNodes(array $nodes): string {
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->renderNode($node);
        }
        return $html;
    }

    /**
     * Render a single node
     * 
     * @param mixed $node Node object (tag, text, or component)
     * @return string Rendered HTML
     */
    private function renderNode($node): string {
        if (!is_array($node)) {
            error_log("Invalid node: must be an array");
            return "<!-- Invalid node -->";
        }

        // ✅ Handle component node FIRST
        if (isset($node['component'])) {
            $componentName = $node['component'];
            $componentData = $node['data'] ?? [];
            
            // Process placeholders in component data
            $componentData = $this->processDataPlaceholders($componentData);
            
            // Load component template
            $componentTemplate = $this->loadComponent($componentName);
            if ($componentTemplate === null) {
                error_log("Component not found: {$componentName}");
                return "<!-- Component not found: {$componentName} -->";
            }
            
            // Replace placeholders with data
            $processedTemplate = $this->processComponentTemplate($componentTemplate, $componentData);
            
            // Render the processed template
            return $this->renderNode($processedTemplate);
        }

        // Handle text node
        if (isset($node['textKey'])) {
            return $this->renderTextNode($node);
        }

        // Handle tag node
        if (isset($node['tag'])) {
            return $this->renderTagNode($node);
        }

        error_log("Unknown node type: " . json_encode($node));
        return "<!-- Unknown node type -->";
    }

    /**
     * Render a text node
     * 
     * @param array $node Text node with 'textKey'
     * @return string Escaped translated text
     */
    private function renderTextNode(array $node): string {
        $textKey = $node['textKey'];
        
        if (empty($textKey)) {
            return '';
        }

        // Check if it's raw text (not a translation key)
        if (strpos($textKey, '__RAW__') === 0) {
            $rawText = substr($textKey, 7); // Remove __RAW__ prefix
            return htmlspecialchars($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Use Translator::translate() which already uses htmlspecialchars
        return htmlspecialchars($this->translator->translate($textKey), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render a tag node (HTML element)
     * 
     * @param array $node Tag node with 'tag', 'params', 'children'
     * @return string Rendered HTML element
     */
    private function renderTagNode(array $node): string {
        $tag = $node['tag'] ?? null;
        
        if (empty($tag)) {
            error_log("Tag node missing 'tag' property");
            return "<!-- Missing tag -->";
        }

        // Sanitize tag name (only allow alphanumeric and hyphen)
        if (!preg_match('/^[a-z0-9-]+$/i', $tag)) {
            error_log("Invalid tag name: {$tag}");
            return "<!-- Invalid tag name -->";
        }

        $params = $node['params'] ?? [];
        $children = $node['children'] ?? null;

        // Check if it's a void/self-closing element
        $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 
                         'link', 'meta', 'param', 'source', 'track', 'wbr'];
        $isVoid = in_array(strtolower($tag), $voidElements);

        $html = '<' . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Render attributes
        if (is_array($params) && !empty($params)) {
            foreach ($params as $attrName => $attrValue) {
                $html .= $this->renderAttribute($attrName, $attrValue);
            }
        }

        if ($isVoid) {
            // Self-closing tag
            $html .= '>';
        } else {
            $html .= '>';
            
            // Render children
            if (is_array($children)) {
                $html .= $this->renderNodes($children);
            }
            
            $html .= '</' . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '>';
        }

        return $html;
    }

    /**
     * Render an HTML attribute
     * 
     * @param string $name Attribute name
     * @param mixed $value Attribute value
     * @return string Rendered attribute (e.g., ' class="value"')
     */
    private function renderAttribute(string $name, $value): string {
        // Sanitize attribute name
        if (!preg_match('/^[a-z0-9_:-]+$/i', $name)) {
            error_log("Invalid attribute name: {$name}");
            return '';
        }
    
        // Block event handler attributes (XSS vector)
        if (preg_match('/^on[a-z]+$/i', $name)) {
            error_log("Event handler attributes not allowed: {$name}");
            return '';
        }

        // Handle conditional attributes
        if (is_array($value) && isset($value['condition'])) {
            // Format: {"condition": "someKey", "value": "attrValue"}
            if (empty($this->context[$value['condition']])) {
                return '';
            }
            $value = $value['value'];
        }

        // Handle boolean attributes
        if (is_bool($value)) {
            return $value ? ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        }

        // Handle null/empty values
        if ($value === null || $value === '') {
            return '';
        }

        // Special handling for URL attributes
        $urlAttributes = ['href', 'src', 'data', 'poster', 'action', 'formaction', 'cite', 'srcset'];
        if (in_array($name, $urlAttributes, true) && is_string($value) && !empty($value)) {
            $value = $this->processUrl($value);
        }

        // Convert value to string and escape
        $escapedValue = htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '="' . $escapedValue . '"';
    }

    /**
     * Load a component template from file
     * 
     * @param string $componentName Name of the component
     * @return array|null Component template or null if not found
     */
    private function loadComponent(string $componentName) {
        // Check cache first
        if (isset($this->componentCache[$componentName])) {
            return $this->componentCache[$componentName];
        }

        $componentPath = $this->componentsPath . $componentName . '.json';
        
        if (!file_exists($componentPath)) {
            return null;
        }

        $json = @file_get_contents($componentPath);
        if ($json === false) {
            error_log("Failed to read component: {$componentPath}");
            return null;
        }

        $componentData = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON in component: {$componentPath} - " . json_last_error_msg());
            return null;
        }

        // Cache the component
        $this->componentCache[$componentName] = $componentData;
        
        return $componentData;
    }

    /**
     * Process component template by replacing {{placeholders}} with data
     * 
     * @param mixed $template Component template (can be nested arrays)
     * @param array $data Data to replace placeholders
     * @return mixed Processed template
     */
    private function processComponentTemplate($template, array $data) {
        if (is_string($template)) {
            // Replace {{placeholder}} with actual value
            return preg_replace_callback('/\{\{(\w+)\}\}/', function($matches) use ($data) {
                $key = $matches[1];
                return $data[$key] ?? $matches[0]; // Keep placeholder if no data
            }, $template);
        }

        if (is_array($template)) {
            $processed = [];
            foreach ($template as $key => $value) {
                $processed[$key] = $this->processComponentTemplate($value, $data);
            }
            return $processed;
        }

        return $template;
    }

    /**
     * Process system placeholders in component data
     * Replaces {{__placeholder}} with actual values
     * 
     * @param mixed $data Component data
     * @return mixed Processed data
     */
    private function processDataPlaceholders($data) {
        $systemPlaceholders = $this->getSystemPlaceholders();
        
        if (is_string($data)) {
            // Replace all {{__placeholder}} occurrences
            return preg_replace_callback('/\{\{(__\w+)\}\}/', function($matches) use ($systemPlaceholders) {
                $key = $matches[1];
                return $systemPlaceholders[$key] ?? $matches[0];
            }, $data);
        }
        
        if (is_array($data)) {
            $processed = [];
            foreach ($data as $key => $value) {
                $processed[$key] = $this->processDataPlaceholders($value);
            }
            return $processed;
        }
        
        return $data;
    }

    /**
     * Get system placeholder values
     * 
     * @return array System placeholders
     */
    private function getSystemPlaceholders(): array {
        // Get current page from URL
        $currentPage = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        // Remove language prefix if present
        if (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED'])) {
            $currentPage = preg_replace('/^(' . implode('|', CONFIG['LANGUAGES_SUPPORTED']) . ')\//', '', $currentPage);
        }
        $currentPage = empty($currentPage) ? '' : $currentPage;
        
        return [
            '__current_page' => $currentPage,
            '__lang' => $this->context['lang'] ?? (defined('LANGUAGE_DEFAULT') ? LANGUAGE_DEFAULT : 'en'),
            '__base_url' => defined('BASE_URL') ? BASE_URL : '',
            '__public_folder' => defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public',
            '__current_route' => $this->context['page'] ?? 'home',
        ];
    }

    /**
     * Process URL - convert relative URLs to absolute
     * 
     * @param string $url URL to process
     * @return string Processed URL
     */
    private function processUrl(string $url): string {
        // Don't modify absolute URLs (http://, https://, //)
        if (preg_match('/^(https?:)?\/\//i', $url)) {
            return $url;
        }
    
        // Block dangerous protocols
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            error_log("Dangerous URL protocol blocked: {$url}");
            return '#'; // Safe fallback
        }
        
        // Don't modify anchors, mailto, tel, etc.
        if (preg_match('/^(#|mailto:|tel:)/i', $url)) {
            return $url;
        }
        
        // It's a relative URL - build the full URL
        $fullUrl = defined('BASE_URL') ? BASE_URL : '';
        
        // Add language prefix if multilingual and not a static asset
        if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT && !empty($this->context['lang'])) {
            // Don't add language to asset paths (/assets/, /style/)
            if (!preg_match('/^\/(assets|style)\//i', $url)) {
                $fullUrl .= $this->context['lang'] . '/';
            }
        }
        
        // Remove leading slash from URL (BASE_URL already has trailing slash)
        $url = ltrim($url, '/');
        
        return $fullUrl . $url;
    }

    /**
     * Set context data
     * 
     * @param array $context Context data to merge
     */
    public function setContext(array $context) {
        $this->context = array_merge($this->context, $context);
    }
}