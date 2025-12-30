<?php
require_once 'init.php';

// --- Check for URL aliases BEFORE TrimParameters processes routes ---
$aliasesFile = PROJECT_PATH . '/data/aliases.json';
$aliasRewrite = null;

if (file_exists($aliasesFile)) {
    $aliases = json_decode(file_get_contents($aliasesFile), true) ?: [];
    
    // Extract the page part from URL (after language code if multilingual)
    $rawUri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $rawUri = $folder ? preg_replace('#^' . preg_quote(trim($folder, '/'), '#') . '/?#', '', $rawUri) : $rawUri;
    
    $parts = array_filter(explode('/', $rawUri), fn($p) => $p !== '');
    $parts = array_values($parts);
    
    // Skip language code if multilingual
    if (MULTILINGUAL_SUPPORT && count($parts) > 0 && in_array($parts[0], CONFIG['LANGUAGES_SUPPORTED'])) {
        $langCode = array_shift($parts);
    }
    
    // Get the potential page/alias
    $potentialPage = count($parts) > 0 ? $parts[0] : '';
    $aliasPath = '/' . $potentialPage;
    
    if (isset($aliases[$aliasPath])) {
        $aliasInfo = $aliases[$aliasPath];
        $targetPath = ltrim($aliasInfo['target'], '/');
        $aliasType = $aliasInfo['type'] ?? 'redirect';
        
        if ($aliasType === 'redirect') {
            // 301 redirect - browser URL changes
            $langPrefix = MULTILINGUAL_SUPPORT ? '/' . ($langCode ?? CONFIG['DEFAULT_LANGUAGE']) : '';
            header('Location: ' . $langPrefix . '/' . $targetPath, true, 301);
            exit;
        } else {
            // Internal rewrite - modify the request so TrimParameters sees the target
            $aliasRewrite = $targetPath;
        }
    }
}

// If we have an alias rewrite, modify REQUEST_URI before TrimParameters
if ($aliasRewrite !== null) {
    $folder = PUBLIC_FOLDER_SPACE ? '/' . trim(PUBLIC_FOLDER_SPACE, '/') : '';
    $langPrefix = MULTILINGUAL_SUPPORT ? '/' . ($langCode ?? CONFIG['DEFAULT_LANGUAGE']) : '';
    $_SERVER['REQUEST_URI'] = $folder . $langPrefix . '/' . $aliasRewrite;
}

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();

$requestedPage = $trimParameters->page();

// --- Route resolution ---
if(in_array($requestedPage, ROUTES)){
    $page = $requestedPage;
} else {
    $page = '404';
}
if($requestedPage == ''){
    $page = 'home';
}

if($page == "404"){
    http_response_code(404);
}
require_once PROJECT_PATH . '/templates/pages/'. $page .'.php';