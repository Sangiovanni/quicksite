<?php
require_once 'init.php';

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();

if(in_array($trimParameters->page(), ROUTES)){
    $page = $trimParameters->page();
} else {
    $page = '404';
}
if($trimParameters->page() == ''){
    $page = 'home';
}

if($page == "404"){
    http_response_code(404);
}
require_once SECURE_FOLDER_PATH . '/templates/pages/'. $page .'.php';