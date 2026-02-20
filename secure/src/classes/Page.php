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
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="/<?= PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '' ?>assets/favicon.png">
    <link rel="stylesheet" href="/<?= PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '' ?>style/style.css">
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
    // Include custom functions if file exists and has content
    $customJsPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-custom.js';
    if (file_exists($customJsPath) && filesize($customJsPath) > 500): ?>
    <script src="/<?= PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '' ?>scripts/qs-custom.js"></script>
    <?php endif; ?>
    <?php 
    // Include API endpoint config if file exists and has real content
    $apiConfigPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-api-config.js';
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