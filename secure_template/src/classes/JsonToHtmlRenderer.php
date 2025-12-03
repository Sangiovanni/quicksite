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
        $jsonPath = SECURE_FOLDER_PATH . "/templates/model/json/pages/{$pageName}.json";
        
        if (!file_exists($jsonPath)) {
            error_log("Page JSON not found: {$jsonPath}");
            return "<!-- Page JSON not found: {$pageName} -->";
        }

        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            error_log("Failed to read page JSON: {$jsonPath}");
            return "<!-- Failed to read page JSON: {$pageName} -->";
        }

        $pageData = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON in page: {$jsonPath} - " . json_last_error_msg());
            return "<!-- Invalid JSON in page: {$pageName} -->";
        }

        if (!is_array($pageData)) {
            error_log("Page JSON must be an array: {$jsonPath}");
            return "<!-- Page JSON must be an array: {$pageName} -->";
        }

        return $this->renderNodes($pageData);
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

        // Check if it's a component
        if (isset($node['component'])) {
            return $this->renderComponent($node);
        }

        // Check if it's a text node
        if (isset($node['textKey'])) {
            return $this->renderTextNode($node);
        }

        // Check if it's a tag node
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
        $translated = $this->translator->translate($textKey);
        
        // Additional escaping to be safe (translate should already escape)
        return htmlspecialchars($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        if (!preg_match('/^[a-z0-9]+$/i', $tag)) {
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

        $url_attributes = ['href', 'src', 'data', 'poster', 'action', 'formaction', 'cite', 'srcset'];
        // Special handling for href and src attributes
        if (in_array($name, $url_attributes, true) && is_string($value) && !empty($value)) {
            $value = $this->processUrl($value);
        }

        // Convert value to string and escape
        $escapedValue = htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '="' . $escapedValue . '"';
    }

    /**
     * Render a component (reusable template)
     * 
     * @param array $node Component node with 'component' and 'data'
     * @return string Rendered HTML
     */
    private function renderComponent(array $node): string {
        $componentName = $node['component'] ?? null;
        $data = $node['data'] ?? [];

        if (empty($componentName)) {
            error_log("Component node missing 'component' property");
            return "<!-- Missing component name -->";
        }

        // Load component template
        $componentTemplate = $this->loadComponent($componentName);
        
        if ($componentTemplate === null) {
            error_log("Component not found: {$componentName}");
            return "<!-- Component not found: {$componentName} -->";
        }

        // Replace placeholders with data
        $processedTemplate = $this->processComponentTemplate($componentTemplate, $data);

        // Render the processed template
        return $this->renderNode($processedTemplate);
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
        $allData = array_merge($this->context, $data);
        
        if (is_string($template)) {
            // Replace {{placeholder}} with actual value
            return preg_replace_callback('/\{\{(\w+)\}\}/', function($matches) use ($allData) {
                $key = $matches[1];
                return $allData[$key] ?? $matches[0]; // Keep placeholder if no data
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

    public function setContext(array $context) {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * Render menu with dynamic URL processing
     */
    public function renderMenu(): string {
        $menuJson = $this->loadMenuJson();
        if ($menuJson === null) {
            return "<!-- Menu JSON not found -->";
        }
        
        // Process the menu structure
        $processedMenu = $this->processMenuStructure($menuJson);
        
        return $this->renderNodes($processedMenu);
    }

    /**
     * Load menu JSON file
     */
    private function loadMenuJson() {
        $jsonPath = SECURE_FOLDER_PATH . '/templates/model/json/menu.json';
        
        if (!file_exists($jsonPath)) {
            error_log("Menu JSON not found: {$jsonPath}");
            return null;
        }

        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            error_log("Failed to read menu JSON: {$jsonPath}");
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid menu JSON - " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Process menu structure to inject runtime data
     */
    private function processMenuStructure(array $menuStructure): array {
    $processed = [];
    
    foreach ($menuStructure as $node) {
        // Process placeholders in this node first
        $processedNode = $this->processComponentTemplate($node, []);
        
        // Then check if it has children that need special processing
        if (isset($processedNode['children'])) {
            $processedChildren = [];
            
            foreach ($processedNode['children'] as $child) {
                if (isset($child['component']) && $child['component'] === 'menu-link') {
                    // Process menu link with runtime data
                    $processedChildren[] = $this->processMenuLink($child);
                } else {
                    // Process other children for placeholders
                    $processedChildren[] = $this->processComponentTemplate($child, []);
                }
            }
            
            $processedNode['children'] = $processedChildren;
        }
        
        $processed[] = $processedNode;
        }
        
        return $processed;
    }

    /**
     * Process a single menu link with runtime URL logic
     */
    private function processMenuLink(array $linkNode): array {
        $data = $linkNode['data'] ?? [];
        
        // Build the URL
        $sep = '';
        if (MULTILINGUAL_SUPPORT && !empty($this->context['lang'])) {
            $sep = $this->context['lang'] . '/';
        }
        
        $url = !empty($data['absoluteLink']) 
            ? $data['absoluteLink'] 
            : BASE_URL  . $sep . $data['path'];
        
        // Build rel attribute
        $rel = '';
        if (!empty($data['target']) && $data['target'] === '_blank') {
            $rel = 'noopener noreferrer';
        }
        
        // Build link children (img + text or just text)
        $linkChildren = [];

        if (!empty($data['logo'])) {
            $linkChildren[] = [
                'tag' => 'img',
                'params' => [
                    'src' => BASE_URL . 'assets/images/' . $data['logo'],
                    'alt' => $data['label'] . ' Logo',
                    'class' => 'menu-logo'
                ],
                'children' => null
            ];
        }

        $linkChildren[] = ['textKey' => $data['label']];

        // Return fully built structure (no component needed)
        return [
            'tag' => 'div',
            'params' => ['class' => 'menu-label'],
            'children' => [
                [
                    'tag' => 'a',
                    'params' => [
                        'href' => $url,
                        'target' => $data['target'] ?? '_self',
                        'rel' => $rel
                    ],
                    'children' => $linkChildren
                ]
            ]
        ];
    }

    /**
     * Render footer with dynamic URL processing and language switcher
     */
    public function renderFooter(): string {
        $footerJson = $this->loadFooterJson();
        if ($footerJson === null) {
            return "<!-- Footer JSON not found -->";
        }
        
        // Process the footer structure
        $processedFooter = $this->processFooterStructure($footerJson);
        
        return $this->renderNodes($processedFooter);
    }

    /**
     * Load footer JSON file
     */
    private function loadFooterJson() {
        $jsonPath = SECURE_FOLDER_PATH . '/templates/model/json/footer.json';
        
        if (!file_exists($jsonPath)) {
            error_log("Footer JSON not found: {$jsonPath}");
            return null;
        }

        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            error_log("Failed to read footer JSON: {$jsonPath}");
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid footer JSON - " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Process footer structure to inject runtime data
     */
    private function processFooterStructure(array $footerStructure): array {
        $processed = [];
        
        foreach ($footerStructure as $node) {
            // Process placeholders in this node first
            $processedNode = $this->processComponentTemplate($node, []);
            
            // Special handling for footer div - inject language switcher if multilingual
            if (isset($processedNode['tag']) && $processedNode['tag'] === 'div' && 
                isset($processedNode['params']['class']) && $processedNode['params']['class'] === 'footer') {
                
                // Process footer links
                if (isset($processedNode['children'][0]['children'])) {
                    $footerBlock = $processedNode['children'][0];
                    $processedLinks = [];
                    
                    foreach ($footerBlock['children'] as $child) {
                        if (isset($child['component']) && $child['component'] === 'footer-link') {
                            $processedLinks[] = $this->processFooterLink($child);
                        } else {
                            $processedLinks[] = $this->processComponentTemplate($child, []);
                        }
                    }
                    
                    $processedNode['children'][0]['children'] = $processedLinks;
                }
                
                // Add language switcher block if multilingual
                if (MULTILINGUAL_SUPPORT) {
                    $langBlock = $this->buildLanguageSwitcher();
                    $processedNode['children'][] = $langBlock;
                }
            }
            
            $processed[] = $processedNode;
        }
        
        return $processed;
    }

    /**
     * Process a single footer link with runtime URL logic
     */
    private function processFooterLink(array $linkNode): array {
        $data = $linkNode['data'] ?? [];
        
        // Build the URL
        $sep = '';
        if (MULTILINGUAL_SUPPORT && !empty($this->context['lang'])) {
            $sep = $this->context['lang'] . '/';
        }
        
        $url = !empty($data['absoluteLink']) 
            ? $data['absoluteLink'] 
            : BASE_URL  . $sep . $data['path'];
        
        // Build rel attribute
        $rel = '';
        if (!empty($data['target']) && $data['target'] === '_blank') {
            $rel = 'noopener noreferrer';
        }
        
        // Return fully built structure
        return [
            'tag' => 'div',
            'params' => [],
            'children' => [
                [
                    'tag' => 'a',
                    'params' => [
                        'href' => $url,
                        'class' => 'footer-option',
                        'target' => $data['target'] ?? '_self',
                        'rel' => $rel
                    ],
                    'children' => [
                        ['textKey' => $data['label']]
                    ]
                ]
            ]
        ];
    }

    /**
     * Build language switcher block from CONFIG
     */
    private function buildLanguageSwitcher(): array {
        $langLinks = [];
        
        if (defined('CONFIG') && isset(CONFIG['LANGUAGES_NAME'])) {
            foreach (CONFIG['LANGUAGES_NAME'] as $langCode => $langName) {
                // Build URL for language switch using TrimParameters context
                $url = $this->context['samePageUrlBase'] ?? BASE_URL;
                
                // Build the URL with proper language code
                if (MULTILINGUAL_SUPPORT) {
                    // Use the base URL and reconstruct with new language
                    $url = $this->buildSamePageUrl($langCode);
                }
                
                $langLinks[] = [
                    'tag' => 'div',
                    'params' => [],
                    'children' => [
                        [
                            'tag' => 'a',
                            'params' => [
                                'href' => $url,
                                'class' => 'footer-option'
                            ],
                            'children' => [
                                ['textKey' => '__RAW__' . $langName] // Special marker for raw text
                            ]
                        ]
                    ]
                ];
            }
        }
        
        return [
            'tag' => 'div',
            'params' => ['class' => 'footer-block'],
            'children' => $langLinks
        ];
    }

    /**
     * Build same page URL with different language
     */
    private function buildSamePageUrl(string $langCode): string {
        $url = BASE_URL  . $langCode;
        
        // Add current page if not home
        if (isset($this->context['page']) && $this->context['page'] !== 'home') {
            $url .= '/' . $this->context['page'];
        }
        
        // Add id if present
        if (isset($this->context['id']) && !empty($this->context['id'])) {
            $url .= '/' . $this->context['id'];
        }
        
        // Add params if present
        if (isset($this->context['params']) && !empty($this->context['params'])) {
            $url .= '/' . implode('/', $this->context['params']);
        }
        
        return $url;
    }

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
        $fullUrl = BASE_URL;
        
        // Add language prefix if multilingual and not a static asset
        if (MULTILINGUAL_SUPPORT && !empty($this->context['lang'])) {
            // Don't add language to asset paths (/assets/, /style/)
            if (!preg_match('/^\/(assets|style)\//i', $url)) {
                $fullUrl .= $this->context['lang'] . '/';
            }
        }
        
        // Remove leading slash from URL (BASE_URL already has trailing slash)
        $url = ltrim($url, '/');
        
        return $fullUrl . $url;
    }
}