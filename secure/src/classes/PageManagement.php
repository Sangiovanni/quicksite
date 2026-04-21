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
        $header .= '<link rel="icon" type="image/png" href="' . BASE_URL . '/assets/images/favicon.png">';
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
            'routePath' => $trimParameters->routePath(),   // 'guides/installation'
            'params' => $trimParameters->params(),
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
                    
                    // onload → DOMContentLoaded
                    if (!empty($currentPageEvents['onload'])) {
                        $onloadCalls = [];
                        foreach ($currentPageEvents['onload'] as $callSyntax) {
                            $transformed = $renderer->transformCallSyntaxPublic($callSyntax);
                            if ($transformed && $transformed !== $callSyntax) {
                                $onloadCalls[] = $transformed;
                            }
                        }
                        if (!empty($onloadCalls)) {
                            $eventScripts[] = 'document.addEventListener("DOMContentLoaded",function(){' . implode(';', $onloadCalls) . '});';
                        }
                    }
                    
                    // onresize → window resize listener
                    if (!empty($currentPageEvents['onresize'])) {
                        $resizeCalls = [];
                        foreach ($currentPageEvents['onresize'] as $callSyntax) {
                            $transformed = $renderer->transformCallSyntaxPublic($callSyntax);
                            if ($transformed && $transformed !== $callSyntax) {
                                $resizeCalls[] = $transformed;
                            }
                        }
                        if (!empty($resizeCalls)) {
                            $eventScripts[] = 'window.addEventListener("resize",function(){' . implode(';', $resizeCalls) . '});';
                        }
                    }
                    
                    // onscroll → window scroll listener
                    if (!empty($currentPageEvents['onscroll'])) {
                        $scrollCalls = [];
                        foreach ($currentPageEvents['onscroll'] as $callSyntax) {
                            $transformed = $renderer->transformCallSyntaxPublic($callSyntax);
                            if ($transformed && $transformed !== $callSyntax) {
                                $scrollCalls[] = $transformed;
                            }
                        }
                        if (!empty($scrollCalls)) {
                            $eventScripts[] = 'window.addEventListener("scroll",function(){' . implode(';', $scrollCalls) . '});';
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