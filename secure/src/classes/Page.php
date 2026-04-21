<?php

class Page {
    private $title;
    private $content;
    private $lang;
    private $showMenu;
    private $showFooter;
    private $pageEventsScript;

    public function __construct($title, $content, $lang, $showMenu = true, $showFooter = true, $pageEventsScript = '') {
        $this->title = $title;
        $this->content = $content;
        $this->lang = $lang;
        $this->showMenu = $showMenu;
        $this->showFooter = $showFooter;
        $this->pageEventsScript = $pageEventsScript;
    }

    public function render() {
        $title = $this->title;
        $content = $this->content;
        $lang = $this->lang;
        $showMenu = $this->showMenu;
        $showFooter = $this->showFooter;
        $pageEventsScript = $this->pageEventsScript;
        $spacePrefix = PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '';
        $stylePath = (defined('PUBLIC_CONTENT_PATH') ? PUBLIC_CONTENT_PATH : dirname(__DIR__, 3) . '/' . (defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public')) . '/style/style.css';
        $cssVersion = file_exists($stylePath) ? filemtime($stylePath) : time();

        // ── Theme resolution ──────────────────────────────────────────────
        $themeEnabled  = defined('THEME_MODE_ENABLED') && THEME_MODE_ENABLED;
        $themeDefault  = defined('THEME_DEFAULT') ? THEME_DEFAULT : 'light';
        $toggleEnabled = defined('THEME_USER_TOGGLE_ENABLED') && THEME_USER_TOGGLE_ENABLED;
        $projectKey    = defined('PROJECT_NAME') ? PROJECT_NAME : 'default';

        $themeAttr = '';
        if ($themeEnabled) {
            $themeAttr = ' data-theme="' . (($themeDefault === 'dark') ? 'dark' : 'light') . '"';
        }

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
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"<?= $themeAttr ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="/<?= $spacePrefix ?>assets/favicon.png">
    <link rel="stylesheet" href="/<?= $spacePrefix ?>style/style.css?v=<?= $cssVersion ?>">
    <?= $themeScript ?>
</head>
<body>
    <?php if ($showMenu): ?>
    <header>
        <?php require_once PROJECT_PATH . '/templates/menu.php'; ?>
    </header>
    <?php endif; ?>
    <main>
        <?= $content ?>
    </main>
    <?php if ($showFooter): ?>
    <footer>
        <?php require_once PROJECT_PATH . '/templates/footer.php'; ?>
    </footer>
    <?php endif; ?>
    <script src="/<?= PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '' ?>scripts/qs.js"></script>
    <?php
    // Inject theme toggle behaviour (mirrors PageManagement implementation)
    if ($themeEnabled && $toggleEnabled):
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
    ?>
    <script><?= $js ?></script>
    <?php endif; ?>
    <?php 
    // Include API endpoint config if file exists and has real content
    $apiConfigPath = PUBLIC_CONTENT_PATH . '/scripts/qs-api-config.js';
    if (file_exists($apiConfigPath) && filesize($apiConfigPath) > 100): ?>
    <script src="/<?= PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '' ?>scripts/qs-api-config.js"></script>
    <?php endif; ?>
    <?php if (!empty($pageEventsScript)): ?>
    <?= $pageEventsScript ?>
    <?php endif; ?>
</body>
</html>
        <?php
    }
}