<?php
require_once 'init.php';

// --- Component Preview Mode (for Visual Editor) ---
// If ?_component={name}&_editor=1 is present, render just the component in isolation
if (isset($_GET['_component']) && isset($_GET['_editor']) && $_GET['_editor'] === '1') {
    $componentName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['_component']); // Sanitize
    
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
    $trimParameters = new TrimParameters();
    $lang = $trimParameters->lang() ?: (CONFIG['LANGUAGE_DEFAULT'] ?? 'en');
    
    require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
    $translator = new Translator($lang);
    
    require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
    $renderer = new JsonToHtmlRenderer($translator, [
        'editorMode' => true,
        'baseUrl' => BASE_URL,
        'lang' => $lang,
    ]);
    
    // Render component in isolation
    $componentHtml = $renderer->renderComponent($componentName);
    
    // Output minimal HTML wrapper with component
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Component: <?= htmlspecialchars($componentName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/style/style.css">
    <style>
        /* Component preview container */
        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .component-preview-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }
        .component-preview-label {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 12px;
            color: #666;
            background: #f5f5f5;
            margin-bottom: 12px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px dashed #ddd;
        }
        .component-preview-label code {
            background: #e9ecef;
            color: #333;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="component-preview-wrapper">
        <div class="component-preview-label">
            Component: <code><?= htmlspecialchars($componentName) ?></code>
        </div>
        <?= $componentHtml ?>
    </div>
    <script src="<?= BASE_URL ?>/scripts/qs.js"></script>
</body>
</html><?php
    exit;
}

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
    
    // Get the potential page/alias (now supports nested paths)
    $potentialPath = count($parts) > 0 ? '/' . implode('/', $parts) : '';
    
    if (isset($aliases[$potentialPath])) {
        $aliasInfo = $aliases[$potentialPath];
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

// --- Route Resolution with Nested Routes Support ---
$route = $trimParameters->route();         // e.g., ['guides', 'installation']
$routePath = $trimParameters->routePath(); // e.g., 'guides/installation'
$routeFound = $trimParameters->routeFound();

// Handle 404
if (!$routeFound || $routePath === '404') {
    http_response_code(404);
    $templateFile = PROJECT_PATH . '/templates/pages/404/404.php';
    if (!file_exists($templateFile)) {
        // Fallback to flat structure during migration
        $templateFile = PROJECT_PATH . '/templates/pages/404.php';
    }
    if (file_exists($templateFile)) {
        require_once $templateFile;
    } else {
        echo '<h1>404 - Page Not Found</h1>';
    }
    exit;
}

// --- Resolve route to file path ---
// Convention:
//   - Route without children: guides/installation → guides/installation.php
//   - Route with children: guides → guides/guides.php (because it has children)

/**
 * Resolve route path to template file
 * Convention: ALL routes use folder structure - route/route.php
 */
function resolveTemplateFile(array $route, string $projectPath): string {
    $basePath = $projectPath . '/templates/pages/';
    $routeName = end($route);
    
    // All routes use folder structure: guides → guides/guides.php
    return $basePath . implode('/', $route) . '/' . $routeName . '.php';
}

$templateFile = resolveTemplateFile($route, PROJECT_PATH);

// Fallback: try flat structure (for backward compatibility during migration)
if (!file_exists($templateFile)) {
    // Try simple flat file: routePath.php (e.g., guides.php)
    $flatFile = PROJECT_PATH . '/templates/pages/' . $routePath . '.php';
    if (file_exists($flatFile)) {
        $templateFile = $flatFile;
    } else {
        // Try legacy single-segment fallback
        $legacyFile = PROJECT_PATH . '/templates/pages/' . $route[0] . '.php';
        if (file_exists($legacyFile)) {
            $templateFile = $legacyFile;
        }
    }
}

// Final check - if still not found, 404
if (!file_exists($templateFile)) {
    http_response_code(404);
    $notFoundFile = PROJECT_PATH . '/templates/pages/404.php';
    if (file_exists($notFoundFile)) {
        require_once $notFoundFile;
    } else {
        echo '<h1>404 - Page Not Found</h1>';
        echo '<p>Template file not found: ' . htmlspecialchars(str_replace(PROJECT_PATH, '', $templateFile)) . '</p>';
    }
    exit;
}

require_once $templateFile;