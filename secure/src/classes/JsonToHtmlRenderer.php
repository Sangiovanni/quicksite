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
     * Render a component in isolation (for component editor preview)
     * 
     * @param string $componentName Name of the component to render
     * @param array $sampleData Optional sample data for component variables
     * @return string Rendered HTML
     */
    public function renderComponent(string $componentName, array $sampleData = []): string {
        // Set structure context for editor mode - component editing
        $this->currentStructure = 'component-' . $componentName;
        $this->currentNodePath = [];
        $this->inComponent = false; // Start as NOT in component (we're editing the component itself)
        
        // Load component template
        $componentTemplate = $this->loadComponent($componentName);
        if ($componentTemplate === null) {
            return "<!-- Component not found: {$componentName} -->";
        }
        
        // If no sample data provided, generate placeholder data from template
        if (empty($sampleData)) {
            $sampleData = $this->generatePlaceholderData($componentTemplate);
        }
        
        // Process placeholders with sample data
        $processedTemplate = $this->processComponentTemplate($componentTemplate, $sampleData);
        
        // Render the processed template
        return $this->renderNode($processedTemplate, false);
    }

    /**
     * Generate placeholder data from a component template
     * Finds all placeholders like {{varName}} and creates sample values
     * 
     * @param array $template Component template structure
     * @return array Sample data with placeholder names as keys
     */
    private function generatePlaceholderData(array $template): array {
        $placeholders = [];
        $this->extractPlaceholders($template, $placeholders);
        
        $sampleData = [];
        foreach ($placeholders as $key) {
            // Show placeholder name as-is for component preview
            $sampleData[$key] = "{{" . $key . "}}";
        }
        
        return $sampleData;
    }

    /**
     * Recursively extract placeholder names from a template
     * 
     * @param array $node Current node to scan
     * @param array &$placeholders Array to collect placeholder names
     */
    private function extractPlaceholders(array $node, array &$placeholders): void {
        // Check textKey for placeholders
        if (isset($node['textKey']) && preg_match_all('/\{\{(\w+)\}\}/', $node['textKey'], $matches)) {
            foreach ($matches[1] as $key) {
                if (!in_array($key, $placeholders)) {
                    $placeholders[] = $key;
                }
            }
        }
        
        // Check params for placeholders
        if (isset($node['params']) && is_array($node['params'])) {
            array_walk_recursive($node['params'], function($value) use (&$placeholders) {
                if (is_string($value) && preg_match_all('/\{\{(\w+)\}\}/', $value, $matches)) {
                    foreach ($matches[1] as $key) {
                        if (!in_array($key, $placeholders)) {
                            $placeholders[] = $key;
                        }
                    }
                }
            });
        }
        
        // Check children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->extractPlaceholders($child, $placeholders);
                }
            }
        }
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

        // Check if it's a variable placeholder (e.g., {{varName}}) - display as-is
        if (preg_match('/^\{\{\w+\}\}$/', $textKey)) {
            $displayText = htmlspecialchars($textKey, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // In editor mode, wrap with data attribute for visibility
            if ($this->editorMode) {
                $escapedKey = htmlspecialchars($textKey, ENT_QUOTES);
                return '<span data-qs-textkey="' . $escapedKey . '" data-qs-variable="true">' . $displayText . '</span>';
            }
            return $displayText;
        }

        // Get translated text
        $translatedText = htmlspecialchars($this->translator->translate($textKey), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // In editor mode, wrap in span with data-qs-textkey for inline editing
        if ($this->editorMode) {
            $escapedKey = htmlspecialchars($textKey, ENT_QUOTES);
            return '<span data-qs-textkey="' . $escapedKey . '">' . $translatedText . '</span>';
        }

        return $translatedText;
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
        
        // SECURITY: Block dangerous tags that could execute scripts or inject styles
        $blockedTags = ['script', 'noscript', 'style', 'template', 'slot'];
        if (in_array(strtolower($tag), $blockedTags, true)) {
            error_log("Blocked dangerous tag: {$tag}");
            return "<!-- Blocked tag -->";
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
    
        // Handle event handler attributes (on*)
        // Block raw JS, but allow {{call:...}} syntax which gets transformed to safe QS.* calls
        if (preg_match('/^on[a-z]+$/i', $name)) {
            if (is_string($value) && strpos($value, '{{call:') !== false) {
                // Transform {{call:...}} to QS.* function calls
                $transformedValue = $this->transformCallSyntax($value);
                // Double-check the result doesn't contain suspicious patterns
                if ($this->isValidTransformedHandler($transformedValue)) {
                    $escapedValue = htmlspecialchars($transformedValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    return ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '="' . $escapedValue . '"';
                }
            }
            // Block if not using {{call:...}} syntax or transformation failed
            error_log("Event handler blocked (use {{call:...}} syntax): {$name}");
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
     * Transform {{call:functionName:arg1,arg2}} syntax to QS.functionName('arg1', 'arg2')
     * 
     * Supports:
     * - {{call:hide:#modal}} → QS.hide('#modal')
     * - {{call:toggleClass:#menu,open}} → QS.toggleClass('#menu', 'open')
     * - {{call:filter:event,.card,data-title}} → QS.filter(event, '.card', 'data-title')
     * - Multiple calls: {{call:hide:#a}};{{call:show:#b}} → QS.hide('#a'); QS.show('#b')
     * 
     * Special keywords (not quoted): event, this
     * 
     * @param string $value The raw {{call:...}} syntax
     * @return string Transformed JavaScript code
     */
    public function transformCallSyntaxPublic(string $value): string {
        return $this->transformCallSyntax($value);
    }

    /**
     * Transform {{call:...}} syntax into safe QS.*() JavaScript calls (internal)
     * 
     * @param string $value The attribute value containing {{call:...}} placeholders
     * @return string Transformed JavaScript code
     */
    private function transformCallSyntax(string $value): string {
        // Match all {{call:functionName:args}} or {{call:functionName}} patterns
        return preg_replace_callback(
            '/\{\{call:([a-zA-Z][a-zA-Z0-9]*)(:[^}]*)?\}\}/',
            function ($matches) {
                $functionName = $matches[1];
                $argsString = isset($matches[2]) ? substr($matches[2], 1) : ''; // Remove leading ':'
                
                // Get allowed function names dynamically (core + custom)
                $allowedFunctions = $this->getAllowedJsFunctions();
                
                if (!in_array($functionName, $allowedFunctions, true)) {
                    error_log("Unknown QS function: {$functionName}");
                    return '/* invalid function */';
                }
                
                // Parse arguments (comma-separated)
                if (empty($argsString)) {
                    return "QS.{$functionName}()";
                }
                
                // Special keywords that should not be quoted (JS variables)
                $jsKeywords = ['event', 'this'];
                
                // Split by comma, but be careful with selectors that might contain commas
                // For simplicity, treat each segment as a string argument
                $args = array_map('trim', explode(',', $argsString));
                $quotedArgs = array_map(function($arg) use ($jsKeywords) {
                    // Don't quote JS keywords
                    if (in_array($arg, $jsKeywords, true)) {
                        return $arg;
                    }
                    // Escape single quotes in the argument
                    $escaped = str_replace("'", "\\'", $arg);
                    return "'{$escaped}'";
                }, $args);
                
                return "QS.{$functionName}(" . implode(', ', $quotedArgs) . ")";
            },
            $value
        );
    }

    /**
     * Get all allowed JS function names (core + custom)
     * Cached for performance within single render
     * 
     * @return array
     */
    private function getAllowedJsFunctions(): array {
        static $allowedFunctions = null;
        
        if ($allowedFunctions === null) {
            // Core functions (always available)
            $allowedFunctions = [
                'show', 'hide', 'toggle', 'toggleHide', 'addClass', 'removeClass',
                'setValue', 'redirect', 'filter', 'scrollTo', 'focus', 'blur', 'fetch',
                'renderList', 'toast'
            ];
            
            // Add custom functions if JsFunctionManager is available
            $managerPath = SECURE_FOLDER_PATH . '/src/classes/JsFunctionManager.php';
            if (file_exists($managerPath)) {
                require_once $managerPath;
                $manager = new \JsFunctionManager();
                $customFuncs = $manager->getCustomFunctions();
                foreach ($customFuncs as $func) {
                    $allowedFunctions[] = $func['name'];
                }
            }
        }
        
        return $allowedFunctions;
    }

    /**
     * Validate that a transformed event handler only contains safe QS.* calls
     * 
     * @param string $handler The transformed handler string
     * @return bool True if safe, false if suspicious
     */
    private function isValidTransformedHandler(string $handler): bool {
        // Remove all valid QS.functionName(...) calls and see what's left
        $stripped = preg_replace('/QS\.[a-zA-Z]+\([^)]*\)/', '', $handler);
        
        // After removing QS calls, only semicolons, spaces, and comments should remain
        $stripped = preg_replace('/[;\s]/', '', $stripped);
        $stripped = preg_replace('/\/\*[^*]*\*\//', '', $stripped); // Remove /* comments */
        
        // If anything else remains, it's suspicious
        if (!empty($stripped)) {
            error_log("Suspicious content in transformed handler: {$stripped}");
            return false;
        }
        
        return true;
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
    
    /**
     * Render a single node from a structure at a specific path
     * Used for dynamic DOM updates without full page reload
     * 
     * @param array $structure The full structure
     * @param string $nodePath Node path like "0.1.2"
     * @param string $structureName Structure name for editor mode (e.g., 'page-home', 'menu')
     * @return string|null Rendered HTML or null if node not found
     */
    public function renderNodeAtPath(array $structure, string $nodePath, string $structureName = ''): ?string {
        // Set structure context for editor mode
        if ($structureName) {
            $this->currentStructure = $structureName;
        }
        $this->inComponent = false;
        
        // Parse the node path
        $indices = array_map('intval', explode('.', $nodePath));
        
        // Detect component structure (single object with 'tag') vs page structure (array of nodes)
        $isComponent = isset($structure['tag']);
        
        // Navigate to the node
        $node = null;
        $current = $structure;
        
        foreach ($indices as $i => $index) {
            if ($i === 0) {
                if ($isComponent) {
                    // Component: first index is into the root object's children
                    if (!isset($current['children'][$index])) {
                        return null;
                    }
                    $node = $current['children'][$index];
                } else {
                    // Page: first index is into the root array
                    if (!isset($current[$index])) {
                        return null;
                    }
                    $node = $current[$index];
                }
                $current = $node;
            } else {
                // Subsequent indices are into children
                if (!isset($current['children'][$index])) {
                    return null;
                }
                $node = $current['children'][$index];
                $current = $node;
            }
        }
        
        if ($node === null) {
            return null;
        }
        
        // Set the node path for editor mode attributes
        $this->currentNodePath = $indices;
        array_pop($this->currentNodePath); // Remove last since renderNode will push it
        
        // Render the node
        $this->currentNodePath[] = end($indices);
        $html = $this->renderNode($node);
        array_pop($this->currentNodePath);
        
        return $html;
    }
}