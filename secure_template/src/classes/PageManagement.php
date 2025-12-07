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
        $header .= '<link rel="stylesheet" href="' . BASE_URL . '/style/style.scss">';
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

        // Pass context with baseUrl and lang
        $context = [
            'baseUrl' => BASE_URL,
            'lang' => MULTILINGUAL_SUPPORT ? $trimParameters->lang() : '',
            'page' => $trimParameters->page(),
            'id' => $trimParameters->id(),
            'params' => $trimParameters->params()
        ];

        $renderer = new JsonToHtmlRenderer($translator, $context);

        // Render the main content
        $body .= $renderer->renderMenu();
        $body .= $this->content;
        $body .= $renderer->renderFooter();

        // Include scripts
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