<?php

/**
 * JsonToHtmlRenderer
 * 
 * Renders JSON page structures to HTML.
 * Supports:
 * - Tag nodes: {tag, params, children}
 * - Text nodes: {textKey}
 * - Components: {component, data}
 * 
 * Editor Mode:
 * When editorMode is enabled, adds data attributes for visual editor:
 * - data-qs-struct: Structure type (menu, footer, page-{name})
 * - data-qs-node: Node path (e.g., "0", "0.1", "0.1.2")
 * - data-qs-component: Component name (on component root elements)
 * - data-qs-in-component: Marker for elements inside a component
 */
class JsonToHtmlRenderer {
    private $context = [];
    private $translator;
    private $componentCache = [];
    private $componentsPath;
    
    // Editor mode state
    private $editorMode = false;
    private $currentStructure = '';      // menu, footer, page-home
    private $currentNodePath = [];       // Path as array [0, 1, 2]
    private $inComponent = false;        // Are we inside a component?
    private $currentComponentName = '';  // Current component name
    private $currentComponentNode = '';  // Node path where component started

    /**
     * @param Translator $translator Instance of Translator for text resolution
     * @param array $context Context data (lang, page, etc.)
     */
    public function __construct($translator, $context = []) {
        $this->translator = $translator;
        $this->context = $context;
        $this->componentsPath = PROJECT_PATH . '/templates/model/json/components/';
        
        // Auto-detect editor mode from query parameter if not explicitly set
        // This ensures ALL renderer instances are in editor mode when ?_editor=1 is present
        if (isset($context['editorMode'])) {
            $this->editorMode = $context['editorMode'];
        } else {
            $this->editorMode = isset($_GET['_editor']) && $_GET['_editor'] === '1';
        }
    }
    
    /**
     * Enable or disable editor mode
     * When enabled, adds data attributes for visual editor element selection
     * 
     * @param bool $enabled Whether to enable editor mode
     */
    public function setEditorMode(bool $enabled): void {
        $this->editorMode = $enabled;
    }

    /**
     * Render a page from its JSON file
     * 
     * @param string $pageName Name of the page (e.g., 'home', 'about') or path (e.g., 'guides/getting-started')
     * @return string Rendered HTML
     */
    public function renderPage(string $pageName): string {
        // Set structure context for editor mode
        $this->currentStructure = 'page-' . str_replace('/', '-', $pageName);
        $this->currentNodePath = [];
        $this->inComponent = false;
        
        // Support both flat name ('home') and path ('guides/getting-started')
        // Convention: ALL pages use folder structure - page/page.json
        $routePath = trim($pageName, '/');
        $segments = explode('/', $routePath);
        $leafName = end($segments);
        
        // Try folder structure first: path/name/name.json
        $folderPath = "/templates/model/json/pages/{$routePath}/{$leafName}.json";
        if (file_exists(PROJECT_PATH . $folderPath)) {
            return $this->renderJsonFile($folderPath);
        }
        
        // Fallback to flat structure for backward compat: path/name.json
        return $this->renderJsonFile("/templates/model/json/pages/{$routePath}.json");
    }

    /**
     * Render menu - just load and render the JSON
     */
    public function renderMenu(): string {
        // Set structure context for editor mode
        $this->currentStructure = 'menu';
        $this->currentNodePath = [];
        $this->inComponent = false;
        
        return $this->renderJsonFile('/templates/model/json/menu.json');
    }

    /**
     * Render footer - just load and render the JSON
     */
    public function renderFooter(): string {
        // Set structure context for editor mode
        $this->currentStructure = 'footer';
        $this->currentNodePath = [];
        $this->inComponent = false;
        
        return $this->renderJsonFile('/templates/model/json/footer.json');
    }

    /**
     * Render JSON file
     * 
     * @param string $relativePath Path relative to PROJECT_PATH
     * @return string Rendered HTML
     */
    private function renderJsonFile(string $relativePath): string {
        $jsonPath = PROJECT_PATH . $relativePath;
        
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

        // Check if this is a single node (has 'tag' or 'component' or 'textKey') vs array of nodes
        if (isset($data['tag']) || isset($data['component']) || isset($data['textKey'])) {
            // Single root node - render directly
            return $this->renderNode($data);
        }

        // Array of nodes - render each
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
        $index = 0;
        foreach ($nodes as $node) {
            // Push current index to path
            $this->currentNodePath[] = $index;
            $html .= $this->renderNode($node);
            // Pop after rendering
            array_pop($this->currentNodePath);
            $index++;
        }
        return $html;
    }

    /**
     * Render a single node
     * 
     * @param mixed $node Node object (tag, text, or component)
     * @param bool $isComponentRoot Whether this is the root element of a component
     * @return string Rendered HTML
     */
    private function renderNode($node, bool $isComponentRoot = false): string {
        if (!is_array($node)) {
            error_log("Invalid node: must be an array");
            return "<!-- Invalid node -->";
        }

        // ✅ Handle component node FIRST
        if (isset($node['component'])) {
            $componentName = $node['component'];
            $componentData = $node['data'] ?? [];
            
            // Save component context before entering
            $prevInComponent = $this->inComponent;
            $prevComponentName = $this->currentComponentName;
            $prevComponentNode = $this->currentComponentNode;
            
            // Enter component context
            $this->inComponent = true;
            $this->currentComponentName = $componentName;
            $this->currentComponentNode = implode('.', $this->currentNodePath);
            
            // Process placeholders in component data
            $componentData = $this->processDataPlaceholders($componentData);
            
            // Load component template
            $componentTemplate = $this->loadComponent($componentName);
            if ($componentTemplate === null) {
                // Restore context
                $this->inComponent = $prevInComponent;
                $this->currentComponentName = $prevComponentName;
                $this->currentComponentNode = $prevComponentNode;
                error_log("Component not found: {$componentName}");
                return "<!-- Component not found: {$componentName} -->";
            }
            
            // Replace placeholders with data
            $processedTemplate = $this->processComponentTemplate($componentTemplate, $componentData);
            
            // Render the processed template (mark as component root)
            $html = $this->renderNode($processedTemplate, true);
            
            // Restore context after exiting component
            $this->inComponent = $prevInComponent;
            $this->currentComponentName = $prevComponentName;
            $this->currentComponentNode = $prevComponentNode;
            
            return $html;
        }

        // Handle text node
        if (isset($node['textKey'])) {
            return $this->renderTextNode($node);
        }

        // Handle tag node
        if (isset($node['tag'])) {
            return $this->renderTagNode($node, $isComponentRoot);
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
     * @param bool $isComponentRoot Whether this is the root element of a component
     * @return string Rendered HTML element
     */
    private function renderTagNode(array $node, bool $isComponentRoot = false): string {
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

        // Add editor mode attributes if enabled
        if ($this->editorMode) {
            $html .= $this->renderEditorAttributes($isComponentRoot);
        }

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
     * Render editor mode data attributes
     * 
     * @param bool $isComponentRoot Whether this is the root element of a component
     * @return string HTML attributes string
     */
    private function renderEditorAttributes(bool $isComponentRoot): string {
        $attrs = '';
        
        // Always add structure identifier
        $attrs .= ' data-qs-struct="' . htmlspecialchars($this->currentStructure, ENT_QUOTES) . '"';
        
        // Add node path
        $nodePath = implode('.', $this->currentNodePath);
        $attrs .= ' data-qs-node="' . htmlspecialchars($nodePath, ENT_QUOTES) . '"';
        
        // If we're inside a component
        if ($this->inComponent) {
            // Mark as in-component (for click handling - bubble up to component root)
            $attrs .= ' data-qs-in-component';
            
            // On component root, add component name and the node where component is defined
            if ($isComponentRoot) {
                $attrs .= ' data-qs-component="' . htmlspecialchars($this->currentComponentName, ENT_QUOTES) . '"';
                $attrs .= ' data-qs-component-node="' . htmlspecialchars($this->currentComponentNode, ENT_QUOTES) . '"';
            }
        }
        
        return $attrs;
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

        // Translatable attributes - auto-translate if value looks like a translation key
        $translatableAttributes = ['placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder', 'aria-description'];
        if (in_array($name, $translatableAttributes, true) && is_string($value)) {
            // Check for __RAW__ prefix - use value as-is without translation
            if (strpos($value, '__RAW__') === 0) {
                $value = substr($value, 7); // Remove __RAW__ prefix
            }
            // Check if value looks like a translation key (contains dots, alphanumeric/underscore, no spaces)
            elseif (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/i', $value)) {
                // It's a translation key - translate it
                $value = $this->translator->translate($value);
            }
        }

        // Special handling for URL attributes
        $urlAttributes = ['href', 'src', 'data', 'poster', 'action', 'formaction', 'cite', 'srcset'];
        if (in_array($name, $urlAttributes, true) && is_string($value) && !empty($value)) {
            // Process placeholders first (e.g., {{__current_page;lang=en}})
            if (strpos($value, '{{__') !== false) {
                $value = $this->processDataPlaceholders($value);
            }
            // Then process URL (add base URL, language prefix, etc.)
            // Only if the value doesn't already look like a complete URL after placeholder processing
            if (!preg_match('/^(https?:)?\/\//i', $value)) {
                $value = $this->processUrl($value);
            }
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
     * Replaces {{__placeholder}} and {{__placeholder;param=value}} with actual values
     * 
     * @param mixed $data Component data
     * @return mixed Processed data
     */
    private function processDataPlaceholders($data) {
        $systemPlaceholders = $this->getSystemPlaceholders();
        
        if (is_string($data)) {
            // Replace all {{__placeholder}} or {{__placeholder;params}} occurrences
            return preg_replace_callback('/\{\{(__\w+)(?:;([^}]+))?\}\}/', function($matches) use ($systemPlaceholders) {
                $key = $matches[1];
                $params = isset($matches[2]) ? $this->parseParameters($matches[2]) : [];
                
                // Special handling for __current_page with lang parameter
                if ($key === '__current_page' && isset($params['lang'])) {
                    return $this->buildLanguageSwitchUrl($params['lang']);
                }
                
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
     * Parse parameters from placeholder syntax: param1=value1;param2=value2
     * 
     * @param string $paramString Parameter string
     * @return array Parsed parameters
     */
    private function parseParameters(string $paramString): array {
        $params = [];
        $pairs = explode(';', $paramString);
        foreach ($pairs as $pair) {
            if (strpos($pair, '=') !== false) {
                list($k, $v) = explode('=', $pair, 2);
                $params[trim($k)] = trim($v);
            }
        }
        return $params;
    }
    
    /**
     * Build URL for language switching (current page in different language)
     * Uses TrimParameters for proper route validation
     * 
     * @param string $targetLang Target language code
     * @return string Complete URL with space prefix and language
     */
    private function buildLanguageSwitchUrl(string $targetLang): string {
        // Use TrimParameters to parse current URL and generate proper URL
        require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
        $trimParams = new TrimParameters();
        
        // Check if current page is valid (not 404)
        if ($trimParams->page() === '404') {
            // Invalid route - redirect to home in target language
            $url = defined('BASE_URL') ? BASE_URL : '';
            if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) {
                $url .= $targetLang . '/';
            }
            return $url;
        }
        
        // Valid route - use TrimParameters to build URL with target language
        return $trimParams->samePageUrl($targetLang);
    }

    /**
     * Get system placeholder values
     * 
     * @return array System placeholders
     */
    private function getSystemPlaceholders(): array {
        // Get current page from URL
        $currentPage = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        
        // Remove PUBLIC_FOLDER_SPACE prefix if present
        if (defined('PUBLIC_FOLDER_SPACE') && PUBLIC_FOLDER_SPACE !== '') {
            $currentPage = preg_replace('/^' . preg_quote(PUBLIC_FOLDER_SPACE, '/') . '\//', '', $currentPage);
        }
        
        // Remove language prefix if present
        if (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED'])) {
            $currentPage = preg_replace('/^(' . implode('|', CONFIG['LANGUAGES_SUPPORTED']) . ')\//', '', $currentPage);
        }
        
        // Keep empty for home page (will result in /lang/ with trailing slash)
        $currentPage = empty($currentPage) ? '' : $currentPage;
        
        // Build space prefix
        $space = '';
        if (defined('PUBLIC_FOLDER_SPACE') && PUBLIC_FOLDER_SPACE !== '') {
            $space = '/' . PUBLIC_FOLDER_SPACE;
        }
        
        return [
            '__current_page' => $currentPage,
            '__lang' => $this->context['lang'] ?? (defined('LANGUAGE_DEFAULT') ? LANGUAGE_DEFAULT : 'en'),
            '__base_url' => defined('BASE_URL') ? BASE_URL : '',
            '__public_folder' => defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public',
            '__current_route' => $this->context['page'] ?? 'home',
            '__space' => $space,
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
                // Don't add language if URL already starts with a language code
                $supportedLangs = defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) ? CONFIG['LANGUAGES_SUPPORTED'] : ['en', 'fr'];
                $langPattern = '/^\/' . '(' . implode('|', $supportedLangs) . ')' . '(\/|$)/';
                if (!preg_match($langPattern, $url)) {
                    $fullUrl .= $this->context['lang'] . '/';
                }
            }
        }
        
        // Remove leading slash from URL (BASE_URL already has trailing slash)
        $url = ltrim($url, '/');
        
        // If URL is now empty (was "/" for home), fullUrl already has proper ending
        if (empty($url)) {
            // fullUrl already ends with trailing slash (from BASE_URL or lang/)
            return $fullUrl;
        }
        
        // Ensure trailing slash if URL is just language code
        if (preg_match('/^(en|fr)$/i', $url)) {
            $url .= '/';
        }
        
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