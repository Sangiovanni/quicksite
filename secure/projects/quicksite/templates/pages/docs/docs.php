<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$lang = $trimParameters->lang();

require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
$renderer = new JsonToHtmlRenderer($translator, ['lang' => $lang, 'page' => 'docs']);

$content = $renderer->renderPage('docs');

require_once SECURE_FOLDER_PATH . '/src/classes/PageManagement.php';

// Get page title from translation
$pageTitle = $translator->translate('page.titles.docs');

$page = new PageManagement($pageTitle, $content, $lang);
$page->render();
