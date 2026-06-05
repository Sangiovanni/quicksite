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

        $header = "<!DOCTYPE html>";
        $header .= '<html lang="' . htmlspecialchars($this->lang) . '"' . $themeAttr . '>';
        $header .="<head>";
        $header .= "<title>" . htmlspecialchars($this->title) . "</title>";
        // Favicon: prefer CONFIG['FAVICON_PATH'] (project-configurable).
        // Accepts an absolute URL (https?:// or data:) emitted as-is, or a
        // root-relative path (joined with BASE_URL). Default falls back to
        // the project's conventional assets path. Kept in sync with the
        // built-page renderer in src/classes/Page.php.
        $faviconPath = (defined('CONFIG') && isset(CONFIG['FAVICON_PATH']) && CONFIG['FAVICON_PATH'] !== '')
            ? CONFIG['FAVICON_PATH']
            : '/assets/images/favicon.png';
        $faviconHref = preg_match('#^(https?:)?//|^data:#i', $faviconPath)
            ? $faviconPath
            : (BASE_URL . '/' . ltrim($faviconPath, '/'));
        $header .= '<link rel="icon" href="' . htmlspecialchars($faviconHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        $stylePath = PUBLIC_CONTENT_PATH . '/style/style.css';
        $cssVersion = file_exists($stylePath) ? filemtime($stylePath) : time();
        $header .= '<link rel="stylesheet" href="' . BASE_URL . '/style/style.css?v=' . $cssVersion . '">';
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
            'baseUrl' => BASE_URL,
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

        // Beta.8 A1 Build Slice 2 — emit routes schema BEFORE qs.js so the
        // client-side path matcher (which runs synchronously in qs.js's
        // IIFE) can read window.QS_ROUTES and populate QS.routeParams +
        // QS.routePath. Without this ordering, the matcher would see
        // undefined and fall back to empty params.
        $routesMetaPath = PUBLIC_CONTENT_PATH . '/scripts/qs-route-schema.js';
        if (file_exists($routesMetaPath)) {
            $body .= '<script src="' . BASE_URL . '/scripts/qs-route-schema.js"></script>';
        }

        // Always include QuickSite core library for {{call:...}} interactions
        $body .= '<script src="' . BASE_URL . '/scripts/qs.js"></script>';

        // Inject theme toggle behaviour when THEME_USER_TOGGLE_ENABLED is true.
        // Finds all [data-theme-toggle] buttons, updates icon/label on load and
        // on click, and persists the choice to localStorage.
        if ($themeEnabled && $toggleEnabled) {
            $key = 'qs-theme-' . $projectKey;
            $js  = '(function(){';
            $js .= 'var key="' . $key . '";';
            $js .= 'function apply(t){document.documentElement.setAttribute("data-theme",t);try{localStorage.setItem(key,t);}catch(e){}}';
            $js .= 'function sync(t){document.querySelectorAll("[data-theme-toggle]").forEach(function(b){';
            $js .= 'var icon=b.querySelector(".theme-switch-icon");';
            $js .= 'var lbl=b.querySelector(".theme-switch-label");';
            $js .= 'if(icon)icon.textContent=t==="dark"?"☀️":"🌙";';
            $js .= 'if(lbl)lbl.textContent=t==="dark"?"Light mode":"Dark mode";';
            $js .= 'b.setAttribute("aria-pressed",t==="dark"?"true":"false");';
            $js .= '});}';
            $js .= 'document.addEventListener("DOMContentLoaded",function(){';
            $js .= 'var cur=document.documentElement.getAttribute("data-theme")||"light";';
            $js .= 'sync(cur);';
            $js .= 'document.querySelectorAll("[data-theme-toggle]").forEach(function(b){';
            $js .= 'b.addEventListener("click",function(){';
            $js .= 'var cur=document.documentElement.getAttribute("data-theme")||"light";';
            $js .= 'var next=cur==="dark"?"light":"dark";apply(next);sync(next);';
            $js .= '});});';
            $js .= '});';
            $js .= '})();';
            $body .= '<script>' . $js . '</script>';
        }

        // Include API endpoint config if file exists and has real content
        $apiConfigPath = PUBLIC_CONTENT_PATH . '/scripts/qs-api-config.js';
        if (file_exists($apiConfigPath) && filesize($apiConfigPath) > 100) {
            $body .= '<script src="' . BASE_URL . '/scripts/qs-api-config.js"></script>';
        }

        // Include enum registry alongside qs-api-config.js. Generated
        // by EnumSyncHelper on editApi / switchProject. Loaded
        // unconditionally when it exists — even an empty registry
        // (window.QS_ENUMS = {}) avoids "table not loaded" warnings
        // from QS.enum at runtime.
        $enumsPath = PUBLIC_CONTENT_PATH . '/scripts/qs-enums.js';
        if (file_exists($enumsPath)) {
            $body .= '<script src="' . BASE_URL . '/scripts/qs-enums.js"></script>';
        }

        // Inject this page's state-store definitions for the client runtime
        // (qs.js reads window.QS_STATE_STORES). Per-page, sourced from
        // data/state-stores.json keyed by route path. Emitted after
        // qs-api-config (the store endpoints resolve against QS_API_ENDPOINTS)
        // and before the page-events script (so onload fetchState sees the
        // stores). Skipped in editor mode, like page events.
        if (!$editorMode) {
            $storesFile = PROJECT_PATH . '/data/state-stores.json';
            if (file_exists($storesFile)) {
                $storesContent = @file_get_contents($storesFile);
                $storesAll = $storesContent !== false ? json_decode($storesContent, true) : [];
                $currentStores = is_array($storesAll) ? ($storesAll[$trimParameters->routePath()] ?? []) : [];
                if (!empty($currentStores)) {
                    $body .= '<script>window.QS_STATE_STORES=' . json_encode($currentStores, JSON_UNESCAPED_SLASHES) . ';</script>';
                }
            }
        }

        // Include additional scripts
        if (!empty($this->scripts)) {
            foreach ($this->scripts as $script) {
                $body .= '<script src="' . htmlspecialchars($script) . '"></script>'; 
            }
        }

        // Inject page-level events (onload, onresize, onscroll)
        if (!$editorMode) {
            $pageEventsFile = PROJECT_PATH . '/data/page-events.json';
            if (file_exists($pageEventsFile)) {
                $pageEventsContent = @file_get_contents($pageEventsFile);
                $pageEventsAll = $pageEventsContent !== false ? json_decode($pageEventsContent, true) : [];
                $currentRoutePath = $trimParameters->routePath();
                $currentPageEvents = $pageEventsAll[$currentRoutePath] ?? [];
                
                if (!empty($currentPageEvents)) {
                    $eventScripts = [];
                    
                    // Page events: each handler's array of {{call:...}} entries is
                    // JOINED into ONE chain string then transformed ONCE so the
                    // renderer's CHAIN_AWAITABLE detection sees the full chain. The
                    // previous per-entry transform broke async chaining (each call
                    // got its own isolated async IIFE; subsequent saveToken/redirect
                    // entries ran synchronously before the awaited fetch resolved).
                    // Surfaced by beta.8 A3 magic-link's exchangeMagicLink → saveToken
                    // → redirect onload chain — the first real multi-step async page
                    // event in the wild.

                    // onload → DOMContentLoaded
                    if (!empty($currentPageEvents['onload'])) {
                        $chainString = implode('', $currentPageEvents['onload']);
                        $transformed = $renderer->transformCallSyntaxPublic($chainString);
                        if ($transformed && $transformed !== $chainString) {
                            $eventScripts[] = 'document.addEventListener("DOMContentLoaded",function(){' . $transformed . '});';
                        }
                    }

                    // onresize → window resize listener
                    if (!empty($currentPageEvents['onresize'])) {
                        $chainString = implode('', $currentPageEvents['onresize']);
                        $transformed = $renderer->transformCallSyntaxPublic($chainString);
                        if ($transformed && $transformed !== $chainString) {
                            $eventScripts[] = 'window.addEventListener("resize",function(){' . $transformed . '});';
                        }
                    }

                    // onscroll → window scroll listener
                    if (!empty($currentPageEvents['onscroll'])) {
                        $chainString = implode('', $currentPageEvents['onscroll']);
                        $transformed = $renderer->transformCallSyntaxPublic($chainString);
                        if ($transformed && $transformed !== $chainString) {
                            $eventScripts[] = 'window.addEventListener("scroll",function(){' . $transformed . '});';
                        }
                    }
                    
                    if (!empty($eventScripts)) {
                        $body .= '<script>' . implode('', $eventScripts) . '</script>';
                    }
                }
            }
        }

        $body .= "</body>";
        $body .= "</html>";

        print($header.$body);
    }
}
?>