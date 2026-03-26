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
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="/<?= $spacePrefix ?>assets/favicon.png">
    <link rel="stylesheet" href="/<?= $spacePrefix ?>style/style.css?v=<?= $cssVersion ?>">
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