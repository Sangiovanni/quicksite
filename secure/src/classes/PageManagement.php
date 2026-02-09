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
        $header = "<!DOCTYPE html>";
        $header .= '<html lang="' . htmlspecialchars($this->lang) . '">';
        $header .="<head>";
        $header .= "<title>" . htmlspecialchars($this->title) . "</title>";
        $header .= '<link rel="icon" type="image/png" href="' . BASE_URL . '/assets/images/favicon.png">';
        $header .= '<link rel="stylesheet" href="' . BASE_URL . '/style/style.css">';
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
        // Include custom functions if file exists and is not empty
        $customJsPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-custom.js';
        if (file_exists($customJsPath) && filesize($customJsPath) > 500) {
            $body .= '<script src="' . BASE_URL . '/scripts/qs-custom.js"></script>';
        }
        // Include API endpoint config if file exists and has real content
        $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
        if (file_exists($apiConfigPath) && filesize($apiConfigPath) > 100) {
            $body .= '<script src="' . BASE_URL . '/scripts/qs-api-config.js"></script>';
        }

        // Include additional scripts
        if (!empty($this->scripts)) {
            foreach ($this->scripts as $script) {
                $body .= '<script src="' . htmlspecialchars($script) . '"></script>'; 
            }
        }
        $body .= "</body>";
        $body .= "</html>";

        print($header.$body);
    }
}
?>