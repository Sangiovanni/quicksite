<?php

class Page {
    private $title;
    private $content;
    private $lang;

    public function __construct($title, $content, $lang) {
        $this->title = $title;
        $this->content = $content;
        $this->lang = $lang;
    }

    public function render() {
        $title = $this->title;
        $content = $this->content;
        $lang = $this->lang;
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
    <link rel="stylesheet" href="/style/style.scss">
</head>
<body>
    <header>
        <?php require_once SECURE_FOLDER_PATH . '/templates/menu.php'; ?>
    </header>
    <main>
        <?= $content ?>
    </main>
    <footer>
        <?php require_once SECURE_FOLDER_PATH . '/templates/footer.php'; ?>
    </footer>
</body>
</html>
        <?php
    }
}