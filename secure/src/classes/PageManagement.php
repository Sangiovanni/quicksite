<?php
class PageManagement {
    private $title;
    private $content;
    private $lang;
    private $meta;
    private $scripts;
    private $links;

    public function __construct($title, $content, $lang, $meta = [], $scripts = [], $links = []) {
        $this->title = $title;
        $this->content = $content;
        $this->lang = $lang;
        $this->meta = $meta;
        $this->scripts = $scripts;
        $this->links = $links;
    }

    public function render() {
        if($this->lang === null){
            $this->lang = 'en';
        }

        // ── Theme resolution (Step 3) ─────────────────────────────────────
        // Compute server-side initial data-theme value. Client-side script
        // overrides it from localStorage before first paint when needed.
        $themeEnabled   = defined('THEME_MODE_ENABLED') && THEME_MODE_ENABLED;
        $themeDefault   = defined('THEME_DEFAULT') ? THEME_DEFAULT : 'light';
        $toggleEnabled  = defined('THEME_USER_TOGGLE_ENABLED') && THEME_USER_TOGGLE_ENABLED;
        $projectKey     = defined('PROJECT_NAME') ? PROJECT_NAME : 'default';

        // Server-side initial value: "light" or "dark"; "system" falls back to
        // "light" here — the inline script will correct it before first paint.
        $themeAttr = '';
        if ($themeEnabled) {
            $themeAttr = ' data-theme="' . (($themeDefault === 'dark') ? 'dark' : 'light') . '"';
        }

        // Inline anti-flicker script: runs synchronously in <head>, sets
        // data-theme from localStorage (user choice) or prefers-color-scheme
        // (when default is "system"), before any painting occurs.
        $themeScript = '';
        if ($themeEnabled && ($toggleEnabled || $themeDefault === 'system')) {
            $js = '(function(){try{';
            if ($toggleEnabled) {
                $js .= 'var s=localStorage.getItem("qs-theme-' . $projectKey . '");';
                $js .= 'if(s==="dark"||s==="light"){document.documentElement.setAttribute("data-theme",s);return;}';
            }
            if ($themeDefault === 'system') {
                $js .= 'if(window.matchMedia&&window.matchMedia("(prefers-color-scheme:dark)").matches){document.documentElement.setAttribute("data-theme","dark");}';
            }
            $js .= '}catch(e){}})();';
            $themeScript = '<script>' . $js . '</script>';
        }
        // ─────────────────────────────────────────────────────────────────

        // C15 15.4 (R1): the base every emitted URL composes against. On the render
        // path this is QS_PUBLIC_BASE (root-relative path form, exactly one trailing
        // slash — which is also what kills the old `//` in these joins); the BASE_URL
        // fallback keeps non-render callers behaving exactly as before.
        $base = defined('QS_PUBLIC_BASE') ? QS_PUBLIC_BASE
            : ((defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/');

        $header = "<!DOCTYPE html>";
        $header .= '<html lang="' . htmlspecialchars($this->lang) . '"' . $themeAttr . '>';
        $header .="<head>";
        $header .= "<title>" . htmlspecialchars($this->title) . "</title>";
        // Favicon: prefer CONFIG['FAVICON_PATH'] (project-configurable).
        // Accepts an absolute URL (https?:// or data:) emitted as-is, or a
        // root-relative path (joined with the public base). Default falls back
        // to the project's conventional assets path. Kept in sync with the
        // built-page renderer in src/classes/Page.php.
        $faviconPath = (defined('CONFIG') && isset(CONFIG['FAVICON_PATH']) && CONFIG['FAVICON_PATH'] !== '')
            ? CONFIG['FAVICON_PATH']
            : '/assets/images/favicon.png';
        $faviconHref = preg_match('#^(https?:)?//|^data:#i', $faviconPath)
            ? $faviconPath
            : ($base . ltrim($faviconPath, '/'));
        $header .= '<link rel="icon" href="' . htmlspecialchars($faviconHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        $stylePath = PUBLIC_CONTENT_PATH . '/style/style.css';
        $cssVersion = file_exists($stylePath) ? filemtime($stylePath) : time();
        $header .= '<link rel="stylesheet" href="' . $base . 'style/style.css?v=' . $cssVersion . '">';
        if (!empty($this->links)) {
            foreach ($this->links as $rel => $href) {
                $header .= '<link rel="' . htmlspecialchars($rel) . '" href="' . htmlspecialchars($href) . '">';
            }
        }
        
        $header .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        if (!empty($this->meta)) {
            foreach ($this->meta as $name => $content) {
                $header .= '<meta name="' . htmlspecialchars($name) . '" content="' . htmlspecialchars($content) . '">';
            }
        }

        $header .= $themeScript;
        $header .= "</head>";
        $body = "<body>";

        require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
        require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';

        $translator = new Translator($this->lang);
        $trimParameters = new TrimParameters();
        
        // Check for editor mode (visual editor preview)
        $editorMode = isset($_GET['_editor']) && $_GET['_editor'] === '1';

        // Pass context with baseUrl, lang, and route info
        $context = [
            'baseUrl' => $base,
            'lang' => MULTILINGUAL_SUPPORT ? $trimParameters->lang() : '',
            // New nested route properties
            'route' => $trimParameters->route(),           // ['guides', 'installation']
            'routePath' => $trimParameters->routePath(),   // 'guides/installation' OR 'products/:slug' for param routes
            'params' => $trimParameters->params(),
            // Beta.8 A1 — captured URL path-param values for `:name` route segments.
            // E.g., for /products/red-vase matching route 'products/:slug':
            //   routeParams === ['slug' => 'red-vase']
            // Empty when the matched route has no `:name` segments.
            // JsonToHtmlRenderer substitutes `{{param:NAME}}` placeholders in
            // textKey / RAW text using this dict.
            'routeParams' => $trimParameters->routeParams(),
            // Legacy compatibility (deprecated)
            'page' => $trimParameters->page(),             // Last segment for backward compat
            'id' => $trimParameters->id(),                 // First param for backward compat
            // Editor mode
            'editorMode' => $editorMode,
        ];

        $renderer = new JsonToHtmlRenderer($translator, $context);

        // Load route layout settings (menu/footer visibility)
        require_once SECURE_FOLDER_PATH . '/src/classes/RouteLayoutManager.php';
        $layoutManager = new RouteLayoutManager();
        $layout = $layoutManager->getEffectiveLayout($trimParameters->routePath());

        // Render the main content with conditional menu/footer
        if ($layout['menu']) {
            $body .= $renderer->renderMenu();
        }
        $body .= $this->content;
        if ($layout['footer']) {
            $body .= $renderer->renderFooter();
        }

        // Consent layer (banner + popup) — global, site-wide (not per-route like
        // menu/footer). Rendered whenever generated; '' otherwise. Hidden by
        // default; qs.js shows it on the live site, the editor toolbar toggles
        // it for styling. See generateConsentLayer + consentLayerHelpers.php.
        $body .= $renderer->renderConsentLayer();

        // ── The runtime handoff ───────────────────────────────────────────
        // Every <script> this page hands the browser runtime, in the one order
        // they have to be in, written by the SHARED emitter that a compiled
        // page also uses. The two used to be separate runs that had drifted:
        // this one emitted seven blocks, a built page four — so a built site
        // lost the consent map and both resolver blocks.
        //
        // Gathering stays here, because the SOURCES are what legitimately
        // differ: a live render reads this route's stores and events out of
        // data/, a compiled page already has them baked in.
        require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php';

        $__routePath              = $trimParameters->routePath();
        $__handoffStores          = [];
        $__handoffResolverConfigs = [];
        $__handoffResolvedVars    = [];
        $__handoffConsentPayload  = null;
        $__handoffPageEvents      = '';

        // Editor mode is excluded from all of these for one reason: an editor
        // preview is emulation-driven rather than resolver-fired, and its
        // consent banner is toggled by the toolbar rather than by a visitor's
        // choice. Page events are skipped there for the same reason.
        if (!$editorMode) {
            $storesFile = PROJECT_PATH . '/data/state-stores.json';
            if (file_exists($storesFile)) {
                $storesContent = @file_get_contents($storesFile);
                $storesAll = $storesContent !== false ? json_decode($storesContent, true) : [];
                $routeStores = is_array($storesAll) ? ($storesAll[$__routePath] ?? []) : [];
                if (!empty($routeStores)) {
                    $__handoffStores = $routeStores;
                }
            }

            require_once SECURE_FOLDER_PATH . '/src/functions/resolverHelpers.php';
            $__handoffResolverConfigs = getResolversForRoute($__routePath);
            $__handoffResolvedVars    = getResolvedVars();

            require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
            $__handoffConsentPayload = qs_consent_payload();

            $__handoffPageEvents = $this->buildPageEventsScript($__routePath, $renderer);
        }

        $body .= qs_runtime_handoff([
            'base'               => $base,
            'contentPath'        => PUBLIC_CONTENT_PATH,
            'projectKey'         => $projectKey,
            'themeEnabled'       => $themeEnabled,
            'themeToggleEnabled' => $toggleEnabled,
            'consentPayload'     => $__handoffConsentPayload,
            'stateStores'        => $__handoffStores,
            'resolverConfigs'    => $__handoffResolverConfigs,
            'resolvedVars'       => $__handoffResolvedVars,
            'extraScripts'       => $this->scripts ?? [],
            'pageEventsScript'   => $__handoffPageEvents,
        ]);
        $body .= "</body>";
        $body .= "</html>";

        print($header.$body);
    }

    /**
     * This route's page-level events, compiled to one <script> tag.
     *
     * ⚠ EACH HANDLER'S CALLS ARE JOINED INTO ONE CHAIN AND TRANSFORMED ONCE.
     * Transforming them separately gives every call its own isolated async
     * IIFE, so a later step runs before an awaited earlier one resolves — which
     * breaks exactly the chains that need ordering, like
     * exchangeMagicLink → saveToken → redirect.
     *
     * @param string $routePath The matched route.
     * @param object $renderer  Supplies the call-syntax transform.
     */
    private function buildPageEventsScript(string $routePath, $renderer): string
    {
        $file = PROJECT_PATH . '/data/page-events.json';
        if (!file_exists($file)) {
            return '';
        }
        $content = @file_get_contents($file);
        $all = $content !== false ? json_decode($content, true) : [];
        $events = is_array($all) ? ($all[$routePath] ?? []) : [];
        if (empty($events)) {
            return '';
        }

        $listeners = [
            'onload'   => ['document', 'DOMContentLoaded'],
            'onresize' => ['window', 'resize'],
            'onscroll' => ['window', 'scroll'],
        ];

        $scripts = [];
        foreach ($listeners as $name => [$target, $event]) {
            if (empty($events[$name])) {
                continue;
            }
            $chain = implode('', $events[$name]);
            $transformed = $renderer->transformCallSyntaxPublic($chain);
            if ($transformed && $transformed !== $chain) {
                $scripts[] = $target . '.addEventListener("' . $event . '",function(){' . $transformed . '});';
            }
        }

        return empty($scripts) ? '' : '<script>' . implode('', $scripts) . '</script>';
    }
}
?>