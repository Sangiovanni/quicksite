<?php


require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$lang = $trimParameters->lang();


require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
$renderer = new JsonToHtmlRenderer($translator);

$content = $renderer->renderPage('404');

// Now use this constant to include files from your src folder
require_once SECURE_FOLDER_PATH . '/src/classes/PageManagement.php';

// Get page title from translation
$pageTitle = $translator->translate('page.titles.404');

$page = new PageManagement($pageTitle, $content, $lang);
$page->render();

