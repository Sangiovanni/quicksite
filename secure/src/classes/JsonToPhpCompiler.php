<?php

require_once __DIR__ . '/../functions/qsVerbCatalog.php';
// utilsManagement carries the array_is_list() polyfill compilePage() depends on.
// Required explicitly rather than relying on build.php's load order: on PHP 8.0.30
// (the supported floor) the bare call is a fatal, so the dependency has to be
// stated by the file that uses it.
require_once __DIR__ . '/../functions/utilsManagement.php';
require_once __DIR__ . '/Translator.php';
require_once __DIR__ . '/UrlPolicy.php';
require_once __DIR__ . '/CallTransformer.php';
require_once __DIR__ . '/TagRegistry.php';

/**
 * JsonToPhpCompiler
 *
 * Compiles JSON page structures into static PHP code for production builds.
 * Converts JSON nodes into PHP string concatenation with translator calls.
 */
class JsonToPhpCompiler {

    /**
     * Set while compiling a structure that contains an <iframe>, so the
     * generated page requires IframeSandbox only when it has one to police.
     */
    private bool $needsIframeSandbox = false;

    /**
     * The system placeholders a compiled page defines as variables.
     *
     * Mirrors JsonToHtmlRenderer::getSystemPlaceholders()'s map — the two
     * writers must recognise the same set or the same page says different
     * things in preview and in production. Every entry REPORTS a value; none
     * composes a URL, which is why there is no `__base_url` here.
     */
    private const SYSTEM_PLACEHOLDERS = [
        '__current_page',
        '__lang',
        '__public_folder',
        '__current_route',
    ];

    /**
     * Compile a full page JSON structure to PHP code
     * 
     * @param array $structure The page JSON structure
     * @param string $pageTitle The page title key for translation
     * @param bool $showMenu Whether to show the menu (default: true)
     * @param bool $showFooter Whether to show the footer (default: true)
     * @return string The compiled PHP code
     */
    public function compilePage(array $structure, string $pageTitle, bool $showMenu = true, bool $showFooter = true, array $pageEvents = [], array $stateStores = []): string {
        $this->needsIframeSandbox = false;
        $output = "<?php\n\n";
        $output .= "require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';\n";
        $output .= "\$trimParameters = new TrimParameters();\n";
        $output .= "require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';\n";
        $output .= "\$translator = new Translator(\$trimParameters->lang());\n";
        $output .= "\$lang = \$trimParameters->lang();\n";
        // {{param:}} and {{resolved:}} are REQUEST-time values — the URL being
        // served, and the data the resolver fetched for it — so a compiled page
        // cannot fold them in the way it folds a translation key. It calls the
        // same two functions the live renderer calls, with this request's params.
        $output .= "require_once SECURE_FOLDER_PATH . '/src/functions/runtimePlaceholders.php';\n";
        $output .= "\$__routeParams = \$trimParameters->routeParams();\n\n";
        
        // Add system variables for placeholders
        $output .= $this->generateSystemVariables();
        
        // Generate title from translation using page title parameter
        $output .= "// Get page title from translation\n";
        $output .= "\$pageTitle = \$translator->translate('page.titles.{$pageTitle}');\n\n";
        $output .= "\$content = '';\n";
        // Normalize: if structure is a single node (associative array), wrap in array
        if (!empty($structure) && !array_is_list($structure)) {
            $structure = [$structure];
        }
        // Compiled BEFORE it is appended, because compiling is what discovers
        // whether this page needs the iframe-sandbox runtime.
        $nodesOutput = $this->compileNodes($structure);
        $output .= $this->iframeSandboxRequire();
        $output .= $nodesOutput;
        
        // Compile page-level events (onload, onresize, onscroll) into a script tag
        $pageEventsScript = $this->compilePageEvents($pageEvents);

        // Pass layout settings to Page constructor
        $showMenuStr = $showMenu ? 'true' : 'false';
        $showFooterStr = $showFooter ? 'true' : 'false';
        $pageEventsScriptStr = addcslashes($pageEventsScript, "'\\");

        // The state stores travel as DATA, not as a ready-made <script> tag.
        // The tag is written by the shared runtime handoff, which also needs the
        // stores in array form to match them against this route's resolvers for
        // skip-fetch hydration — a tag it could only re-parse.
        $stateStoresLiteral = var_export($stateStores, true);

        $output .= "\nrequire_once SECURE_FOLDER_PATH . '/src/classes/Page.php';\n";
        $output .= "\$page = new Page(\$pageTitle, \$content, \$lang, {$showMenuStr}, {$showFooterStr}, '{$pageEventsScriptStr}', {$stateStoresLiteral});\n";
        $output .= "\$page->render();\n";
        
        return $output;
    }
    

    /**
     * Compile page-level events into a <script> tag
     *
     * Converts page-events.json entries for a single route into JavaScript event listeners.
     * - onload → document.addEventListener("DOMContentLoaded", ...)
     * - onresize → window.addEventListener("resize", ...)
     * - onscroll → window.addEventListener("scroll", ...)
     * 
     * @param array $events The events for one page route, e.g. ['onload' => ['{{call:show:#modal}}']]
     * @return string The compiled <script> tag, or empty string if no events
     */
    public function compilePageEvents(array $events): string {
        if (empty($events)) {
            return '';
        }
        
        $eventScripts = [];
        
        // Map event names to their JS listener wrappers
        $eventMap = [
            'onload' => ['target' => 'document', 'event' => 'DOMContentLoaded'],
            'onresize' => ['target' => 'window', 'event' => 'resize'],
            'onscroll' => ['target' => 'window', 'event' => 'scroll'],
        ];
        
        foreach ($eventMap as $eventName => $listener) {
            if (!empty($events[$eventName]) && is_array($events[$eventName])) {
                // ⚠ JOIN FIRST, TRANSFORM ONCE — the same rule the live render
                // follows. Transforming each entry separately gives every call
                // its own isolated async IIFE, so a later step runs before an
                // awaited earlier one has resolved. That silently breaks
                // exactly the chains that need ordering: an onload
                // exchangeMagicLink → saveToken → redirect would redirect
                // before the token was ever saved. The awaitable-verb detection
                // can only see a chain it is handed whole.
                $chain = implode('', $events[$eventName]);
                $transformed = CallTransformer::transform($chain);
                // Keep any transformed result — a real QS.* call, or the
                // console.warn CallTransformer emits for an unknown verb. Skip
                // only when nothing was transformed at all.
                if ($transformed && $transformed !== $chain) {
                    $eventScripts[] = $listener['target'] . '.addEventListener("' . $listener['event'] . '",function(){' . $transformed . '});';
                }
            }
        }
        
        if (empty($eventScripts)) {
            return '';
        }
        
        return '<script>' . implode('', $eventScripts) . '</script>';
    }
    
    /**
     * Compile menu/footer JSON structure to PHP code
     */
    public function compileMenuOrFooter(array $structure): string {
        $this->needsIframeSandbox = false;
        $output = "<?php\n";
        $output .= "// This file is auto-generated by build command\n\n";
        
        // Add translator context
        $output .= "require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';\n";
        $output .= "\$trimParameters = new TrimParameters();\n";
        $output .= "require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';\n";
        $output .= "\$translator = new Translator(\$trimParameters->lang());\n";
        $output .= "\$lang = \$trimParameters->lang();\n";
        // {{param:}} and {{resolved:}} are REQUEST-time values — the URL being
        // served, and the data the resolver fetched for it — so a compiled page
        // cannot fold them in the way it folds a translation key. It calls the
        // same two functions the live renderer calls, with this request's params.
        $output .= "require_once SECURE_FOLDER_PATH . '/src/functions/runtimePlaceholders.php';\n";
        $output .= "\$__routeParams = \$trimParameters->routeParams();\n\n";
        
        // Add system variables for placeholders
        $output .= $this->generateSystemVariables();
        
        $nodesOutput = $this->compileNodes($structure, true);
        $output .= $this->iframeSandboxRequire();
        $output .= $nodesOutput;
        return $output;
    }
    
    /**
     * The require line a compiled structure needs for its iframes, or ''.
     *
     * Emitted only when compiling found an <iframe>: IframeSandbox is 470
     * lines, and a page with no iframe should not parse it on every request.
     */
    private function iframeSandboxRequire(): string {
        return $this->needsIframeSandbox
            ? "require_once SECURE_FOLDER_PATH . '/src/classes/IframeSandbox.php';\n"
            : '';
    }

    /**
     * Generate PHP code for system variables ({{__placeholder}} support)
     */
    private function generateSystemVariables(): string {
        $output = "// System variables for {{__placeholder}} support\n";
        $output .= "\$__current_page = trim(parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');\n\n";
        
        // Remove PUBLIC_FOLDER_SPACE prefix, then the language prefix.
        //
        // The emitted patterns end `\/` — an escaped SLASH, the delimiter. They
        // used to be emitted with an escaped BACKSLASH, so every one of them
        // matched a literal backslash and stripped nothing: on a built site
        // with a URL space or a language segment, {{__current_page}} kept the
        // prefix it exists to remove. Nothing in a source install noticed,
        // because this code is only ever written into a build.
        $output .= "if (defined('PUBLIC_FOLDER_SPACE') && PUBLIC_FOLDER_SPACE !== '') {\n";
        $output .= "    \$__current_page = preg_replace('/^' . preg_quote(trim(PUBLIC_FOLDER_SPACE, '/'), '/') . '\\\\//', '', \$__current_page);\n";
        $output .= "}\n\n";

        // Remove language prefix
        $output .= "if (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED'])) {\n";
        $output .= "    \$__current_page = preg_replace('/^(' . implode('|', array_map(function (\$l) { return preg_quote(\$l, '/'); }, CONFIG['LANGUAGES_SUPPORTED'])) . ')\\\\//', '', \$__current_page);\n";
        $output .= "} else {\n";
        $output .= "    \$__current_page = preg_replace('/^(en|fr)\\\\//', '', \$__current_page);\n";
        $output .= "}\n\n";
        $output .= "\$__current_page = empty(\$__current_page) ? '' : \$__current_page;\n";
        // Every variable emitted here REPORTS a value. None of them composes a
        // URL: an author who needs one writes a root-relative path and
        // processUrl() composes it against the base, correctly and once.
        $output .= "\$__lang = \$lang;\n";
        $output .= "\$__public_folder = defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public';\n";
        $output .= "\$__current_route = basename(parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH));\n\n";
        
        // Add processUrl helper function WITH function_exists check
        $output .= <<<'PHP'
    // Helper function to process URLs (add language prefix, handle absolute URLs)
    if (!function_exists('processUrl')) {
        function processUrl($url, $lang) {
            // Don't modify absolute URLs (http://, https://, //)
            if (preg_match('/^(https?:)?\/\//i', $url)) {
                return $url;
            }
            
            // Block dangerous protocols
            if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
                return '#';
            }
            
            // Don't modify anchors, mailto, tel, etc.
            if (preg_match('/^(#|mailto:|tel:)/i', $url)) {
                return $url;
            }
            
            // Don't add language to asset paths
            if (preg_match('/^\/(assets|style)\//i', $url)) {
                return (defined('BASE_URL') ? BASE_URL : '') . ltrim($url, '/');
            }
            
            // Build URL with language prefix
            $fullUrl = defined('BASE_URL') ? BASE_URL : '';
            if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT && !empty($lang)) {
                // Don't add language if URL already starts with a language code
                $supportedLangs = defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) ? CONFIG['LANGUAGES_SUPPORTED'] : ['en', 'fr'];
                $langPattern = '/^\\/(' . implode('|', $supportedLangs) . ')(\\/|$)/';
                if (!preg_match($langPattern, $url)) {
                    $fullUrl .= $lang . '/';
                }
            }
            $fullUrl .= ltrim($url, '/');
            
            // If URL is now empty (was "/" for home), fullUrl already has proper ending
            if (empty(ltrim($url, '/'))) {
                // fullUrl already ends with trailing slash (from BASE_URL or lang/)
                return $fullUrl;
            }
            
            // Ensure trailing slash if URL is just a language code. The codes are
            // the PROJECT's own (projectLanguage.php travels into the build and is
            // already loaded by the page's TrimParameters require), not a fixed
            // pair — a built site that speaks es/de gets the same treatment en/fr
            // used to get for free. Empty on a mono-language build, where a URL
            // that looks like a language code is an ordinary route.
            $__langCodes = function_exists('qs_project_languages') ? qs_project_languages() : [];
            if (!empty($__langCodes)) {
                $__quoted = [];
                foreach ($__langCodes as $__lc) {
                    $__quoted[] = preg_quote((string) $__lc, '/');
                }
                if (preg_match('/^(' . implode('|', $__quoted) . ')$/i', ltrim($url, '/'))) {
                    $fullUrl .= '/';
                }
            }

            return $fullUrl;
        }
    }
    
    // Helper function to build language switch URLs
    if (!function_exists('buildLanguageSwitchUrl')) {
        function buildLanguageSwitchUrl($targetLang) {
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
    }

    PHP;
        
        return $output;
    }
    
    /**
     * Compile an array of nodes to PHP code
     */
    private function compileNodes(array $nodes, bool $echo = false): string {
        $output = '';
        foreach ($nodes as $node) {
            $output .= $this->compileNode($node, $echo);
        }
        return $output;
    }
    
    /**
     * Compile a single node to PHP code
     */
    private function compileNode(array $node, bool $echo = false): string {
        // Handle component node
        if (isset($node['component'])) {
            return $this->compileComponent($node, $echo);
        }
        
        // Handle text node
        if (isset($node['textKey'])) {
            return $this->compileTextNode($node, $echo);
        }
        
        // Handle tag node
        if (isset($node['tag'])) {
            return $this->compileTagNode($node, $echo);
        }
        
        return "// Unknown node type\n";
    }
    
    /**
     * Compile a text node to PHP code
     */
    private function compileTextNode(array $node, bool $echo = false): string {
        $textKey = $node['textKey'];
        $prefix = $echo ? 'echo ' : '$content .= ';

        if (strpos($textKey, '__RAW__') === 0) {
            // Raw text. A literal is fully known here, so the substitution call
            // is emitted ONLY when the text actually carries a placeholder —
            // most raw text does not, and a built page should not pay for a
            // scan that can never match.
            $rawText = substr($textKey, 7);
            $expr = var_export($rawText, true);
            if ($this->hasRuntimePlaceholder($rawText)) {
                $expr = 'qs_apply_runtime_placeholders(' . $expr . ', $__routeParams)';
            }
            return $prefix . 'htmlspecialchars(' . $expr . ', ENT_QUOTES | ENT_HTML5, \'UTF-8\');' . "\n";
        } else {
            // Translation key. The TRANSLATED string is what may carry a
            // placeholder ("Welcome, {{param:slug}}!"), and which translation
            // file is read depends on the request's language — so unlike raw
            // text this cannot be decided at compile time and the call is
            // always emitted. qs_apply_runtime_placeholders fast-paths on a
            // strpos when there is nothing to substitute.
            return $prefix . 'htmlspecialchars(qs_apply_runtime_placeholders($translator->translate('
                   . var_export($textKey, true)
                   . '), $__routeParams), ENT_QUOTES | ENT_HTML5, \'UTF-8\');' . "\n";
        }
    }

    /**
     * Does this literal carry a request-time placeholder?
     *
     * Only `{{param:}}` and `{{resolved:}}` count. System placeholders
     * (`{{__lang}}` and friends) are a different mechanism, folded in at
     * compile time by convertPlaceholdersToPhp.
     */
    private function hasRuntimePlaceholder(string $text): bool {
        return strpos($text, '{{param:') !== false
            || strpos($text, '{{resolved:') !== false;
    }
    
    /**
     * Compile a tag node to PHP code
     */
    private function compileTagNode(array $node, bool $echo = false): string {
        $tag = $node['tag'] ?? '';

        // SECURITY (beta.10 F-h): the compiler previously emitted ANY tag, so a
        // stored blocked <script>/<style> shipped to the build even though the
        // renderer drops it. Enforce the SAME gate as the renderer here so
        // preview and deploy agree (name well-formed + not blocked + allowed).
        if (!TagRegistry::isRenderable($tag)) {
            error_log("Compiler skipped non-renderable tag: {$tag}");
            return '';
        }

        $params = $node['params'] ?? [];
        $children = $node['children'] ?? [];
        $prefix = $echo ? 'echo ' : '$content .= ';

        // SECURITY — the iframe sandbox policy, enforced exactly as the live
        // renderer enforces it. It used to hold at /p/<id>/ and vanish in a
        // build: the compiler had no notion of it, so a per-domain policy the
        // author configured simply stopped existing in production.
        //
        // An author-supplied `sandbox` is dropped rather than merged — the
        // system decides this attribute, and letting page JSON widen it would
        // make the policy advisory.
        //
        // The ATTRIBUTE is computed at request time even though the src is
        // known now, because the POLICY is project data that can change without
        // the page changing. The argument is the raw authored src, which is
        // exactly what the renderer passes.
        $isIframe = strtolower($tag) === 'iframe';
        $iframeSandboxExpr = '';
        if ($isIframe) {
            $this->needsIframeSandbox = true;
            $iframeSrc = '';
            foreach ($params as $attrName => $attrValue) {
                if (strtolower($attrName) === 'src' && is_string($attrValue)) {
                    $iframeSrc = $attrValue;
                }
            }
            foreach (array_keys($params) as $attrName) {
                if (strtolower($attrName) === 'sandbox') {
                    unset($params[$attrName]);
                }
            }
            $iframeSandboxExpr = '" . IframeSandbox::getSandboxAttribute('
                . var_export($iframeSrc, true) . ') . "';
        }
        
        $output = '';
        
        // Build opening tag with attributes
        if (empty($params) && !$isIframe) {
            // Simple tag without attributes
            $output .= $prefix . '"<' . $tag . '>";' . "\n";
        } elseif (empty($params)) {
            // An iframe with no attributes at all still gets the policy.
            $output .= $prefix . '"<' . $tag . ' ' . $iframeSandboxExpr . '>";' . "\n";
        } else {
            // Tag with attributes - need to handle system placeholders
            $output .= $prefix . '"<' . $tag;
            
            // URL-sink recognition + rewriting scope come from the shared
            // UrlPolicy (R-6, same class the renderer uses) — see per-attr below.
            foreach ($params as $attrName => $attrValue) {
                // Handle event handler attributes (on*) - only allow {{call:...}} syntax
                if (preg_match('/^on[a-z]+$/i', $attrName)) {
                    if (is_string($attrValue) && strpos($attrValue, '{{call:') !== false) {
                        $transformedValue = CallTransformer::transform($attrValue);
                        if (CallTransformer::isValidHandler($transformedValue)) {
                            $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(';
                            $output .= var_export($transformedValue, true);
                            $output .= ', ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                        }
                        // Skip if transformation failed (blocked)
                    }
                    // Skip raw JS event handlers (blocked)
                    continue;
                }
                
                // Scheme safety applies to ANY URL sink (namespace-aware, e.g.
                // xlink:href); BASE_URL/language rewriting only to the classic
                // set (unchanged behaviour).
                $isUrlAttr    = UrlPolicy::isUrlAttribute($attrName);
                $needsRewrite = UrlPolicy::isRewritableUrlAttribute($attrName);
                
                // Check if value contains system placeholders
                if (is_string($attrValue) && strpos($attrValue, '{{__') !== false) {
                    // Contains system placeholder - generate dynamic PHP
                    $phpValue = $this->convertPlaceholdersToPhp($attrValue);
                    
                    // Check if this is a buildLanguageSwitchUrl() call - these return complete URLs
                    $isLanguageSwitch = strpos($phpValue, 'buildLanguageSwitchUrl(') !== false;
                    
                    if ($needsRewrite && !$isLanguageSwitch) {
                        // Wrap with processUrl (BASE_URL/lang). Placeholders are
                        // system-generated URLs, not attacker-controlled schemes.
                        $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(processUrl(' . $phpValue . ', $__lang), ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                    } else {
                        $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(' . $phpValue . ', ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                    }
                } else {
                    // Static value — the attacker-controlled case. Make it
                    // scheme-SAFE at COMPILE time (shared UrlPolicy) so the
                    // deployed literal can never carry a dangerous scheme; this
                    // covers the non-rewritable sinks (xlink:href, …) too.
                    if ($isUrlAttr && is_string($attrValue)) {
                        $safeValue = UrlPolicy::sanitize($attrValue);
                        if ($needsRewrite) {
                            // Still BASE_URL/lang-rewritten at deploy, on a safe value.
                            $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(processUrl(' . var_export($safeValue, true) . ', $__lang), ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                        } else {
                            $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(' . var_export($safeValue, true) . ', ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                        }
                    } else {
                        $output .= ' ' . $attrName . '=\\"" . htmlspecialchars(';
                        $output .= var_export($attrValue, true);
                        $output .= ', ENT_QUOTES | ENT_HTML5, \'UTF-8\') . "\\"';
                    }
                }
            }
            
            if ($isIframe) {
                $output .= ' ' . $iframeSandboxExpr;
            }
            $output .= '>";' . "\n";
            }
        
        // Void elements don't have children or closing tags
        $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 
                         'link', 'meta', 'param', 'source', 'track', 'wbr'];
        $isVoid = in_array(strtolower($tag), $voidElements);
        
        // Render children
        if (!$isVoid && !empty($children)) {
            foreach ($children as $child) {
                $output .= $this->compileNode($child, $echo);
            }
        }
        
        // Closing tag
        if (!$isVoid) {
            $output .= $prefix . '"</' . $tag . '>";' . "\n";
        }
        
        return $output;
    }
    
    /**
     * Compile a component node (inline component structure)
     */
    private function compileComponent(array $node, bool $echo = false): string {
        $componentName = $node['component'] ?? null;
        $data = $node['data'] ?? [];
        
        if (empty($componentName)) {
            return "// Missing component name\n";
        }
        
        // Load component JSON from development location
        $componentPath = PROJECT_PATH . '/templates/model/json/components/' . $componentName . '.json';
        
        if (!file_exists($componentPath)) {
            return "// Component not found: {$componentName}\n";
        }
        
        $componentJson = @file_get_contents($componentPath);
        if ($componentJson === false) {
            return "// Failed to read component: {$componentName}\n";
        }
        
        $componentStructure = json_decode($componentJson, true);
        
        if (!$componentStructure) {
            return "// Invalid component JSON: {$componentName}\n";
        }
        
        // Process system placeholders in data FIRST
        $data = $this->processDataPlaceholders($data);
        
        // Extract __enums__ metadata and strip it from template before processing
        $enums = $componentStructure['__enums__'] ?? null;
        unset($componentStructure['__enums__']);
        
        // Resolve enum variables: enrich data with mapped values
        if ($enums) {
            $data = $this->resolveEnumVariables($enums, $data, $componentName);
        }
        
        // Then process component template with data
        $processedComponent = $this->processComponentTemplate($componentStructure, $data);
        
        // Compile the processed component
        return $this->compileNode($processedComponent, $echo);
    }
    
    /**
     * Process component template by replacing {{placeholders}} with data
     */
    private function processComponentTemplate($template, array $data) {
        if (is_string($template)) {
            // Replace {{placeholder}} with actual value
            // [\w-]+ allows hyphens in variable names (e.g. alt-logo)
            return preg_replace_callback('/\{\{([\w-]+)\}\}/', function($matches) use ($data) {
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
     * Resolve enum variables from __enums__ metadata.
     * For each enum definition, looks up the source key in data,
     * finds the mapped value, and adds the derived variable to data.
     *
     * @param array $enums The __enums__ definitions from the component
     * @param array $data The component instance data
     * @param string $componentName For logging
     * @return array Enriched data with resolved enum values
     */
    private function resolveEnumVariables(array $enums, array $data, string $componentName): array {
        foreach ($enums as $varName => $enumDef) {
            if (!is_array($enumDef) || !isset($enumDef['source']) || !isset($enumDef['map']) || !is_array($enumDef['map'])) {
                continue;
            }

            $sourceKey = $enumDef['source'];
            $map = $enumDef['map'];
            $mapKeys = array_keys($map);

            if (empty($mapKeys)) {
                continue;
            }

            // Get the chosen key from instance data, or use default
            $chosenKey = $data[$sourceKey] ?? null;
            $defaultKey = $enumDef['default'] ?? $mapKeys[0];

            if ($chosenKey === null || !isset($map[$chosenKey])) {
                if ($chosenKey !== null) {
                    error_log("Component '{$componentName}': enum '{$varName}' has unknown key '{$chosenKey}', using default '{$defaultKey}'");
                }
                $chosenKey = $defaultKey;
            }

            $data[$varName] = $map[$chosenKey];
        }

        return $data;
    }
    
    /**
     * Process system placeholders in component data
     * Replaces {{__placeholder}} with special marker for later conversion
     */
    private function processDataPlaceholders($data) {
        if (is_string($data)) {
            // Check if contains system placeholders
            if (strpos($data, '{{__') !== false) {
                // Keep the placeholder - will be converted to PHP in compileTagNode
                return $data;
            }
            return $data;
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
     * Convert system placeholders in string to PHP variable concatenation
     * Handles both {{__placeholder}} and {{__placeholder;param=value}} syntax
     * Example: "/en/{{__current_page}}" becomes '"/en/" . $__current_page'
     * Example: "{{__current_page;lang=en}}" becomes buildLanguageSwitchUrl('en')
     */
    private function convertPlaceholdersToPhp(string $value): string {
        // Split by placeholders (including those with parameters)
        $parts = preg_split('/(\{\{__\w+(?:;[^}]+)?\}\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $phpParts = [];
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            if (preg_match('/\{\{(__\w+)(?:;([^}]+))?\}\}/', $part, $matches)) {
                // System placeholder
                $key = $matches[1];
                $paramString = $matches[2] ?? '';
                
                // Special handling for __current_page with lang parameter
                if ($key === '__current_page' && !empty($paramString) && strpos($paramString, 'lang=') !== false) {
                    // Parse the lang parameter
                    preg_match('/lang=(\w+)/', $paramString, $langMatch);
                    $targetLang = $langMatch[1] ?? 'en';
                    // Generate call to buildLanguageSwitchUrl function
                    $phpParts[] = "buildLanguageSwitchUrl(" . var_export($targetLang, true) . ")";
                } elseif (in_array($key, self::SYSTEM_PLACEHOLDERS, true)) {
                    // Regular placeholder - convert to PHP variable
                    $phpParts[] = '$' . $key;
                } else {
                    // Not a placeholder this engine defines. Emit the text
                    // VERBATIM, which is what the renderer does with it
                    // (getSystemPlaceholders() lookup, `?? $matches[0]`).
                    // Emitting `$__whatever` instead would put an
                    // undefined-variable warning and an empty string into a
                    // built page where the live render shows the literal.
                    $phpParts[] = var_export($part, true);
                }
            } else {
                // Static string
                $phpParts[] = var_export($part, true);
            }
        }

        return implode(' . ', $phpParts);
    }


}