<?php
require_once __DIR__ . '/TagRegistry.php';
require_once __DIR__ . '/UrlPolicy.php';
require_once __DIR__ . '/CallTransformer.php';
require_once __DIR__ . '/IframeSandbox.php';
// {{param:}} / {{resolved:}} — request-time placeholders, defined once and
// shared with the compiler so a built page substitutes them identically.
require_once __DIR__ . '/../functions/runtimePlaceholders.php';
require_once __DIR__ . '/Translator.php';
require_once __DIR__ . '/../functions/qsVerbCatalog.php';
// The single point for the project's language set — processUrl() asks it which
// codes count as a language rather than carrying its own list.
require_once __DIR__ . '/../functions/projectLanguage.php';
// Beta.8 A1 — paramRoutePathToFs for sanitising ':slug' → '__slug' in file lookup
require_once __DIR__ . '/../functions/routeHelpers.php';
// S2.8 — qs_render_public_base(): the base relative URLs compose against, in
// EVERY context this class renders in (surface B, and /management/ fragments).
// Requiring this file defines no constants outside a surface-B request.
require_once __DIR__ . '/../functions/renderBootstrap.php';

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

    /**
     * The base every relative URL this render emits composes against — resolved
     * ONCE, at construction, from the request context (S2.8). Read by
     * processUrl() and the 404 branch of the language switcher, so the two can
     * no longer disagree. It is deliberately NOT exposed to authors as a
     * placeholder: a value that is already based, pasted in front of a path
     * that processUrl() will base again, produces a doubled URL.
     */
    private $publicBase = '';

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

        // S2.8 — where THIS project is served, resolved for whichever request
        // is doing the rendering. A surface-B render and a /management/
        // fragment render of the same node now compose against one value.
        $this->publicBase = qs_render_public_base();

        // S2.8 — and against one LANGUAGE. processUrl() prefixes non-asset URLs
        // with the current language on a multilingual project, so a render that
        // arrives with no language emits `/about` where the served page emits
        // `/en/about` — the same insert-vs-reload divergence wearing a second
        // hat. The six fragment commands already compute this value for the
        // Translator; defaulting it here means they cannot forget to pass it,
        // and a caller that supplies one (every page template does) is
        // untouched.
        if (empty($this->context['lang']) && defined('CONFIG')) {
            $this->context['lang'] = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        }

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
        // Set structure context for editor mode — uses the unsanitised
        // pattern (':slug') so client-side selectors map back to routes.php
        // keys correctly. Only the file lookup below uses the sanitised form.
        $this->currentStructure = 'page-' . $pageName;
        $this->currentNodePath = [];
        $this->inComponent = false;

        // Support both flat name ('home') and path ('guides/getting-started')
        // Convention: ALL pages use folder structure - page/page.json
        $routePath = trim($pageName, '/');
        // Beta.8 A1 — param-route segments (':slug') sanitised to '__slug'
        // for filesystem lookup. Routes.php key stays ':slug' (matches
        // doc URL syntax). See routeHelpers.php for the canonical helper.
        $fsRoutePath = paramRoutePathToFs($routePath);
        $segments = explode('/', $fsRoutePath);
        $leafName = end($segments);

        // Try folder structure first: path/name/name.json
        $folderPath = "/templates/model/json/pages/{$fsRoutePath}/{$leafName}.json";
        if (file_exists(PROJECT_PATH . $folderPath)) {
            return $this->renderJsonFile($folderPath);
        }

        // Fallback to flat structure for backward compat: path/name.json
        return $this->renderJsonFile("/templates/model/json/pages/{$fsRoutePath}.json");
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
     * Render the consent layer (banner + popup) — global structures seeded by
     * generateConsentLayer, rendered on every page like menu/footer. Returns ''
     * when the layer hasn't been generated (files absent). qs.js controls
     * show/hide; the structures are hidden by default (the `hidden` attribute).
     */
    public function renderConsentLayer(): string {
        $out = '';
        foreach (['consent-banner', 'consent-popup'] as $name) {
            $rel = '/templates/model/json/' . $name . '.json';
            if (!file_exists(PROJECT_PATH . $rel)) {
                continue;
            }
            $this->currentStructure = $name;
            $this->currentNodePath = [];
            $this->inComponent = false;
            $out .= $this->renderJsonFile($rel);
        }
        return $out;
    }

    /**
     * Render a component in isolation (for component editor preview)
     * 
     * @param string $componentName Name of the component to render
     * @param array $sampleData Optional sample data for component variables
     * @return string Rendered HTML
     */
    public function renderComponent(string $componentName, array $sampleData = [], array $emulateOverrides = []): string {
        // Set structure context for editor mode - component editing
        $this->currentStructure = 'component-' . $componentName;
        $this->currentNodePath = [];
        $this->inComponent = false; // Start as NOT in component (we're editing the component itself)
        
        // Load component template
        $componentTemplate = $this->loadComponent($componentName);
        if ($componentTemplate === null) {
            return "<!-- Component not found: {$componentName} -->";
        }
        
        // Extract __enums__ metadata and strip it from template before processing
        $enums = $componentTemplate['__enums__'] ?? null;
        unset($componentTemplate['__enums__']);
        
        // If no sample data provided, generate placeholder data from template
        if (empty($sampleData)) {
            $sampleData = $this->generatePlaceholderData($componentTemplate);
            
            // Apply emulation overrides (editor preview only)
            $emulatedRawValues = [];
            if (!empty($emulateOverrides)) {
                foreach ($emulateOverrides as $key => $value) {
                    if (!is_string($value) || $value === '') continue;
                    // For enum variables, set the source key so resolveEnumVariables picks it up
                    if ($enums && isset($enums[$key]['source'])) {
                        $sampleData[$enums[$key]['source']] = $value;
                    } else {
                        $sampleData[$key] = $value;
                        $emulatedRawValues[] = $value;
                    }
                }
            }
        }
        
        // Resolve enum variables: enrich sample data with mapped values
        if ($enums) {
            $sampleData = $this->resolveEnumVariables($enums, $sampleData, $componentName);
        }
        
        // Process placeholders with sample data
        $processedTemplate = $this->processComponentTemplate($componentTemplate, $sampleData);
        
        // In emulation mode, mark resolved textKeys as raw to prevent translation lookup
        if (!empty($emulatedRawValues)) {
            $this->rawifyEmulatedTextKeys($processedTemplate, $emulatedRawValues);
        }
        
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

        // A structure with NO nodes. On the live site that is correctly nothing
        // at all. In the EDITOR it is a dead end: selection is anchored to
        // elements carrying data-qs-node, so a page whose last node was deleted
        // offers nothing to click, and the add form — which only opens with a
        // selection — can never be reached. The author has emptied the page and
        // cannot put anything back.
        if ($data === []) {
            return $this->editorMode ? $this->renderEmptyStructurePlaceholder() : '';
        }

        // Array of nodes - render each
        return $this->renderNodes($data);
    }

    /**
     * The editor-only stand-in for a structure with no nodes.
     *
     * Carries `data-qs-node=""`, which is the editor's existing spelling for
     * "the structure root" (preview.js treats an empty selectedNode as root and
     * sends targetNodeId='root', position='inside'). So selecting it and adding
     * an element inserts the first node exactly as adding at root always has.
     *
     * Never emitted outside editor mode — a live page with no content stays a
     * page with no content.
     */
    private function renderEmptyStructurePlaceholder(): string {
        $struct = htmlspecialchars($this->currentStructure, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Plain English, not a translation lookup: Translator holds the
        // PROJECT's strings, and asking it for an admin key would both render a
        // "{translation missing}" marker and write to the project's error log on
        // every render of an empty page. Editor chrome inside the preview iframe
        // is the panel's concern — its styling is injected by
        // preview-iframe-inject.js as `.qs-empty-structure`.
        return '<div class="qs-empty-structure" data-qs-struct="' . $struct . '" data-qs-node="">'
            . 'This page is empty. Select this area, then add your first element.'
            . '</div>';
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
            
            // Extract __enums__ metadata and strip it from template before processing
            $enums = $componentTemplate['__enums__'] ?? null;
            unset($componentTemplate['__enums__']);
            
            // Resolve enum variables: enrich data with mapped values
            if ($enums) {
                $componentData = $this->resolveEnumVariables($enums, $componentData, $componentName);
            }
            
            // Replace placeholders with data
            $processedTemplate = $this->processComponentTemplate($componentTemplate, $componentData);

            // Merge call-site `params` into the processed template's root.
            // Without this, a page that writes
            //   {component: "x", data: {...}, params: {"class": "extra", "data-list-template": true}}
            // sees the params silently dropped — the rendered root carries only
            // what the component template declared. That's wrong: `params` on a
            // {component:...} call-site is the documented escape hatch for "this
            // instance of the component is a bit different" (hidden template
            // marker, extra class, custom style, etc.).
            //
            // Merge rules (kept narrow on purpose — broader merging would mask
            // template bugs):
            //   - `class`: concatenate (space-separated, dedupe via single-pass).
            //   - `style`: concatenate (semicolon-separated, trim stray semis).
            //   - everything else (incl. `data-*`, `id`, `aria-*`, etc.): the
            //     call-site value overrides the template value. This is the
            //     usual "instance wins over default" expectation.
            $callSiteParams = isset($node['params']) && is_array($node['params'])
                ? $node['params'] : [];
            if (!empty($callSiteParams) && is_array($processedTemplate)) {
                if (!isset($processedTemplate['params']) || !is_array($processedTemplate['params'])) {
                    $processedTemplate['params'] = [];
                }
                foreach ($callSiteParams as $pk => $pv) {
                    if ($pk === 'class' && isset($processedTemplate['params']['class'])
                        && $processedTemplate['params']['class'] !== ''
                    ) {
                        $existing = preg_split('/\s+/', trim((string)$processedTemplate['params']['class']));
                        $added    = preg_split('/\s+/', trim((string)$pv));
                        $merged   = [];
                        foreach (array_merge($existing, $added) as $cls) {
                            if ($cls !== '' && !in_array($cls, $merged, true)) {
                                $merged[] = $cls;
                            }
                        }
                        $processedTemplate['params']['class'] = implode(' ', $merged);
                    } elseif ($pk === 'style' && isset($processedTemplate['params']['style'])
                        && $processedTemplate['params']['style'] !== ''
                    ) {
                        $existing = rtrim(trim((string)$processedTemplate['params']['style']), ';');
                        $added    = ltrim(trim((string)$pv), ';');
                        $processedTemplate['params']['style'] = $existing . '; ' . $added;
                    } else {
                        $processedTemplate['params'][$pk] = $pv;
                    }
                }
            }

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
    /**
     * Substitute `{{param:NAME}}` placeholders with values from
     * $this->context['routeParams']. Beta.8 A1 — text-level template
     * substitution for URL path-params (e.g., :slug from /products/:slug).
     *
     * - Fast path: returns unchanged when no routeParams in context OR
     *   when the text doesn't contain '{{param:'.
     * - Unknown param names: left as the literal placeholder so the
     *   author can spot the typo.
     * - Caller is responsible for htmlspecialchars after substitution
     *   so the substituted value is properly escaped.
     */
    private function applyRouteParams(string $text): string {
        return qs_apply_route_params($text, $this->context['routeParams'] ?? []);
    }

    /**
     * Substitute `{{resolved:NAME}}` and `{{resolved:NAME.dot.path}}`
     * placeholders with values from the server-side data resolver
     * (beta.8 A2 Slice 3).
     *
     * Source resolution order:
     *   1. $this->context['resolved'] when explicitly passed by the
     *      page template.
     *   2. Otherwise getResolvedVars() — populated by public/index.php
     *      after firing DataResolver for the matched route. Legacy
     *      templates (those that don't pass 'resolved') still benefit
     *      via this fallback.
     *
     * - Fast path: returns unchanged when the text doesn't contain
     *   '{{resolved:' OR when no resolved vars exist for this request.
     * - Dot-path support: 'product.name' walks
     *   $resolved['product']['name'].
     * - Unknown names / out-of-range paths leave the literal placeholder
     *   so the author can spot the typo (mirrors applyRouteParams).
     * - Array / object values render as compact JSON; primitives cast
     *   to string. Caller is responsible for htmlspecialchars after
     *   substitution so the substituted value is properly escaped.
     */
    private function applyResolved(string $text): string {
        return qs_apply_resolved($text, $this->context['resolved'] ?? null);
    }

    private function renderTextNode(array $node): string {
        $textKey = $node['textKey'];
        
        if (empty($textKey)) {
            return '';
        }

        // Drift detector: text nodes have no `params` slot in the renderer,
        // so any attribute (typically a stray onclick written by an old
        // addInteraction call) is silently dropped. Log it so future drift
        // is visible without changing rendered output.
        if (!empty($node['params']) && is_array($node['params'])) {
            error_log('[JsonToHtmlRenderer] textKey node has params (will be ignored): '
                . $textKey . ' params=' . json_encode(array_keys($node['params'])));
        }

        // Raw text (__RAW__) / literal (__LIT__): rendered verbatim, no
        // translation lookup. In editor mode we STILL wrap it in the
        // data-qs-textkey span (with a data-qs-raw marker) so the Text tool can
        // select + edit it — it reads the prefix to edit it as raw rather than
        // as a translation key. Without this, raw text has no selection handle.
        if (strpos($textKey, '__RAW__') === 0 || strpos($textKey, '__LIT__') === 0) {
            $rawText = substr($textKey, 7); // strip the 7-char __RAW__/__LIT__ prefix
            // Beta.8 A1 — substitute `{{param:NAME}}` placeholders from the
            // captured URL path-params (e.g., :slug from /products/:slug)
            // BEFORE htmlspecialchars so the substituted value gets escaped.
            // Beta.8 A2 — also substitute `{{resolved:NAME[.dot.path]}}`
            // from the server-side data resolver. resolved first so a
            // routeParam value containing a literal {{resolved:...}} can't
            // accidentally inject a real placeholder.
            $rawText = $this->applyResolved($rawText);
            $rawText = $this->applyRouteParams($rawText);
            $escapedRaw = htmlspecialchars($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->editorMode) {
                $escapedKey = htmlspecialchars($textKey, ENT_QUOTES);
                $nodePath = implode('.', $this->currentNodePath);
                $struct = htmlspecialchars($this->currentStructure, ENT_QUOTES);
                $inComponent = $this->inComponent ? ' data-qs-in-component' : '';
                return '<span data-qs-textkey="' . $escapedKey . '" data-qs-raw="true" data-qs-node="' . $nodePath . '" data-qs-struct="' . $struct . '" data-qs-textonly="true"' . $inComponent . '>' . $escapedRaw . '</span>';
            }
            return $escapedRaw;
        }

        // Check if it's a variable placeholder (e.g., {{varName}} or {{$varName}}) - display as-is
        if (preg_match('/^\{\{\$?\w+\}\}$/', $textKey)) {
            $displayText = htmlspecialchars($textKey, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // In editor mode, wrap with data attribute for visibility
            if ($this->editorMode) {
                $escapedKey = htmlspecialchars($textKey, ENT_QUOTES);
                $nodePath = implode('.', $this->currentNodePath);
                $struct = htmlspecialchars($this->currentStructure, ENT_QUOTES);
                $inComponent = $this->inComponent ? ' data-qs-in-component' : '';
                return '<span data-qs-textkey="' . $escapedKey . '" data-qs-variable="true" data-qs-node="' . $nodePath . '" data-qs-struct="' . $struct . '" data-qs-textonly="true"' . $inComponent . '>' . $displayText . '</span>';
            }
            return $displayText;
        }

        // Get translated text
        // Beta.8 A1 — substitute `{{param:NAME}}` placeholders from the
        // captured URL path-params AFTER translation lookup, BEFORE
        // htmlspecialchars. Translations can author the placeholder; e.g.
        // an EN string "Welcome, {{param:slug}}!" renders as the URL value.
        // Beta.8 A2 — also substitute `{{resolved:NAME[.dot.path]}}` from
        // the server-side data resolver, applied first (see applyResolved).
        $translatedRaw = $this->applyResolved($this->translator->translate($textKey));
        $translatedRaw = $this->applyRouteParams($translatedRaw);
        $translatedText = htmlspecialchars($translatedRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // In editor mode, wrap in span with data-qs-textkey for inline editing
        if ($this->editorMode) {
            $escapedKey = htmlspecialchars($textKey, ENT_QUOTES);
            $nodePath = implode('.', $this->currentNodePath);
            $struct = htmlspecialchars($this->currentStructure, ENT_QUOTES);
            $inComponent = $this->inComponent ? ' data-qs-in-component' : '';
            return '<span data-qs-textkey="' . $escapedKey . '" data-qs-node="' . $nodePath . '" data-qs-struct="' . $struct . '" data-qs-textonly="true"' . $inComponent . '>' . $translatedText . '</span>';
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
        if (TagRegistry::isBlocked($tag)) {
            error_log("Blocked dangerous tag: {$tag}");
            return "<!-- Blocked tag -->";
        }

        // SECURITY (beta.10 F-g): only ALLOWED tags render — non-allowed tags
        // (e.g. raw SVG <rect>/<text>/<set>, <foreignObject>) are dropped, so
        // the renderer, the compiler, and the writers all agree. SVG stays a
        // decorative-only container.
        if (!TagRegistry::isAllowed($tag)) {
            error_log("Tag not allowed (skipped): {$tag}");
            return "<!-- Tag not allowed -->";
        }

        $params = $node['params'] ?? [];
        $children = $node['children'] ?? null;

        // Check if it's a void/self-closing element
        $isVoid = TagRegistry::isVoidElement($tag);

        $html = '<' . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Add editor mode attributes if enabled
        if ($this->editorMode) {
            $html .= $this->renderEditorAttributes($isComponentRoot);
        }

        // Render attributes
        if (is_array($params) && !empty($params)) {
            foreach ($params as $attrName => $attrValue) {
                // SECURITY: Strip user-provided sandbox on iframes — system enforces its own
                if (strtolower($tag) === 'iframe' && strtolower($attrName) === 'sandbox') {
                    continue;
                }
                $html .= $this->renderAttribute($attrName, $attrValue);
            }
        }

        // SECURITY: Enforce iframe sandbox attribute
        if (strtolower($tag) === 'iframe') {
            $iframeSrc = $params['src'] ?? '';
            $html .= ' ' . IframeSandbox::getSandboxAttribute($iframeSrc);
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
                // Transform {{call:...}} to QS.* function calls (shared R-6 helper)
                $transformedValue = CallTransformer::transform($value);
                // Double-check the result doesn't contain suspicious patterns
                if (CallTransformer::isValidHandler($transformedValue)) {
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
            // Check for __LIT__ prefix - same as __RAW__ for translatable attributes
            elseif (strpos($value, '__LIT__') === 0) {
                $value = substr($value, 7); // Remove __LIT__ prefix
            }
            // Check if value looks like a translation key (contains dots, alphanumeric/underscore, no spaces)
            elseif (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/i', $value)) {
                // It's a translation key - translate it
                $value = $this->translator->translate($value);
            }
        }

        // Special handling for URL attributes. Scheme safety is value-based +
        // namespace-aware (covers xlink:href, ping, …) via the shared UrlPolicy
        // (R-6, the same class the compiler uses). BASE_URL/language rewriting
        // stays scoped to the classic rewritable set. Closes F-b + F-d.
        if (UrlPolicy::isUrlAttribute($name) && is_string($value) && $value !== '') {
            // A language-switch substitution ({{__current_page;lang=xx}}) resolves
            // to a COMPLETE URL — base, language and route already in place. It has
            // to be recognised BEFORE the substitution, because afterwards the
            // result is indistinguishable from an ordinary root-relative path, and
            // processUrl() would compose it against the base a second time.
            //
            // The compiler already exempts exactly this case (JsonToPhpCompiler's
            // $isLanguageSwitch, "these return complete URLs"); this is the same
            // rule on the render path, so one node yields one href either way.
            $isLanguageSwitch = false;
            // Process placeholders first (e.g., {{__current_page;lang=en}})
            if (strpos($value, '{{__') !== false) {
                $isLanguageSwitch = $this->hasLanguageSwitchPlaceholder($value);
                $value = $this->processDataPlaceholders($value);
            }
            // Scheme safety FIRST: allowlist + strip/deny control chars.
            $value = UrlPolicy::sanitize($value);
            // Then rewrite (add base URL, language prefix, etc.) — classic set
            // only, and only if not already a complete URL.
            if (UrlPolicy::isRewritableUrlAttribute($name)
                && !$isLanguageSwitch
                && !preg_match('/^(https?:)?\/\//i', $value)) {
                $value = $this->processUrl($value);
            }
        }

        // Strip __LIT__ prefix from any attribute value (literal values used as-is everywhere)
        if (is_string($value) && strpos($value, '__LIT__') === 0) {
            $value = substr($value, 7);
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
        return CallTransformer::transform($value);
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
            // Replace {{placeholder}} or {{$placeholder}} with actual value
            // [\w-]+ allows hyphens in variable names (e.g. alt-logo)
            return preg_replace_callback('/\{\{(\$?[\w-]+)\}\}/', function($matches) use ($data) {
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
     * In emulation mode, mark resolved textKey values as raw to prevent translation lookup.
     * Only affects textKeys that exactly match an emulated regular variable value.
     */
    private function rawifyEmulatedTextKeys(array &$node, array $emulatedValues): void {
        if (isset($node['textKey']) && is_string($node['textKey'])) {
            $tk = $node['textKey'];
            if ($tk !== '' && strpos($tk, '__RAW__') !== 0 && strpos($tk, '__LIT__') !== 0 && in_array($tk, $emulatedValues, true)) {
                $node['textKey'] = '__RAW__' . $tk;
            }
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as &$child) {
                if (is_array($child)) {
                    $this->rawifyEmulatedTextKeys($child, $emulatedValues);
                }
            }
        }
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
                if ($this->isLanguageSwitchPlaceholder($key, $params)) {
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
    /**
     * Whether one parsed placeholder is the language switch.
     *
     * The single test, so the substitution and the URL-rewrite exemption in
     * renderAttribute() can never disagree about what a language switch is.
     *
     * @param string $key    The placeholder name, e.g. '__current_page'.
     * @param array  $params Its parsed `;k=v` parameters.
     */
    private function isLanguageSwitchPlaceholder(string $key, array $params): bool {
        return $key === '__current_page' && isset($params['lang']);
    }

    /**
     * Whether an attribute value contains a language-switch placeholder.
     *
     * Asked BEFORE substitution: the value it resolves to is a complete URL,
     * and once substituted it looks exactly like an ordinary root-relative
     * path. Same scan and same predicate as processDataPlaceholders(), so the
     * two cannot drift.
     */
    private function hasLanguageSwitchPlaceholder(string $value): bool {
        if (!preg_match_all('/\{\{(__\w+)(?:;([^}]+))?\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $match) {
            $params = isset($match[2]) ? $this->parseParameters($match[2]) : [];
            if ($this->isLanguageSwitchPlaceholder($match[1], $params)) {
                return true;
            }
        }
        return false;
    }

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
            // C15 15.4 (R1): compose against the render-scoped public base.
            // S2.8: that base is now resolved for every render context, not
            // only surface B (see the constructor).
            $url = $this->publicBase;
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
        
        // Every placeholder here REPORTS a value. None of them composes a URL:
        // an author who needs a URL writes a root-relative one and processUrl()
        // composes it against the base, correctly and once.
        return [
            '__current_page' => $currentPage,
            '__lang' => $this->context['lang'] ?? (defined('LANGUAGE_DEFAULT') ? LANGUAGE_DEFAULT : 'en'),
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
        
        // It's a relative URL - build the full URL. C15 15.4 (R1): the base is
        // the root-relative path form with exactly one trailing slash, so links
        // stay host- and scheme-agnostic. S2.8: resolved at construction for
        // whichever request is rendering, so an editor fragment and the served
        // page compose against the same value instead of the install root.
        $fullUrl = $this->publicBase;
        
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
        
        // Ensure trailing slash if URL is just a language code. The codes are the
        // PROJECT's own, not a fixed pair: a site that speaks es/de has to get the
        // same treatment en/fr used to get for free. Empty on a mono-language
        // project, where a URL that looks like a language code is an ordinary
        // route and must not gain a slash.
        $langCodes = qs_project_languages();
        if (!empty($langCodes)) {
            $langOnly = '/^(' . implode('|', array_map(static fn($l) => preg_quote((string) $l, '/'), $langCodes)) . ')$/i';
            if (preg_match($langOnly, $url)) {
                $url .= '/';
            }
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