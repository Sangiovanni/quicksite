<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$lang = $trimParameters->lang();

$content = "<h1>".$translator->translate("termOfUse.title")."</h1>
<div class='center'>".$translator->translate("termOfUse.content")."</div>";


// Now use this constant to include files from your src folder
require_once SECURE_FOLDER_PATH . '/src/classes/Page.php';

$page = new Page("Sangio Stuff", $content, $lang);
$page->render();