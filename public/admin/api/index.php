<?php
/**
 * Admin Panel API Helper
 * 
 * Provides data for dynamic form fields.
 * This is called via AJAX from the admin panel.
 * 
 * @version 1.6.0
 */

require_once __DIR__ . '/../../init.php';
require_once SECURE_FOLDER_PATH . '/admin/AdminRouter.php';
require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';

header('Content-Type: application/json');

// Get the router to check authentication
$router = new AdminRouter();

// Check for Bearer token in Authorization header (for AJAX calls)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = null;

if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
} elseif ($router->isAuthenticated()) {
    // Fall back to session token
    $token = $router->getToken();
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get the action from URL
$requestUri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts = explode('/', $requestUri);
// Remove admin/api prefix
// Remove PUBLIC_FOLDER_SPACE segments if present
$spaceSegments = array_filter(explode('/', PUBLIC_FOLDER_SPACE));
foreach ($spaceSegments as $_) {
    array_shift($parts);
}
array_shift($parts); // admin
array_shift($parts); // api
$action = array_shift($parts) ?? '';
$params = $parts;

// Handle different actions
switch ($action) {
    case 'structure-types':
        // Return available structure types for editStructure
        echo json_encode([
            'success' => true,
            'data' => [
                ['value' => 'page', 'label' => 'Page'],
                ['value' => 'menu', 'label' => 'Menu'],
                ['value' => 'footer', 'label' => 'Footer'],
                ['value' => 'component', 'label' => 'Component']
            ]
        ]);
        break;
        
    case 'pages':
        // Return list of pages
        $result = makeInternalApiCall('listPages', $token);
        if ($result['success']) {
            $pages = array_map(function($page) {
                // Pages come as objects with 'name' property
                $name = is_array($page) ? ($page['name'] ?? $page) : $page;
                return ['value' => $name, 'label' => $name];
            }, $result['data']['pages'] ?? []);
            
            // Check if 404 page exists and add it (it's not a route but has a structure file)
            $page404File = SECURE_FOLDER_PATH . '/templates/model/json/pages/404.json';
            if (file_exists($page404File)) {
                // Only add if not already in the list
                $has404 = false;
                foreach ($pages as $p) {
                    if ($p['value'] === '404') {
                        $has404 = true;
                        break;
                    }
                }
                if (!$has404) {
                    $pages[] = ['value' => '404', 'label' => '404 (Error Page)'];
                }
            }
            
            echo json_encode(['success' => true, 'data' => $pages]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'components':
        // Return list of components
        $result = makeInternalApiCall('listComponents', $token);
        if ($result['success']) {
            $components = array_map(function($comp) {
                return ['value' => $comp['name'], 'label' => $comp['name']];
            }, $result['data']['components'] ?? []);
            echo json_encode(['success' => true, 'data' => $components]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'component-variables':
        // Return variables for a specific component
        $componentName = $params[0] ?? '';
        if (!$componentName) {
            echo json_encode(['success' => false, 'error' => 'Component name required']);
            break;
        }
        
        $result = makeInternalApiCall('listComponents', $token);
        if ($result['success']) {
            $component = null;
            foreach ($result['data']['components'] ?? [] as $comp) {
                if ($comp['name'] === $componentName) {
                    $component = $comp;
                    break;
                }
            }
            
            if ($component) {
                // Return variables with their types
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'name' => $component['name'],
                        'variables' => $component['variables'] ?? [],
                        'slots' => $component['slots'] ?? []
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Component not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'routes':
        // Return list of routes
        $result = makeInternalApiCall('getRoutes', $token);
        if ($result['success']) {
            // Use flat_routes which is a simple array of route names
            $routes = array_map(function($route) {
                return ['value' => $route, 'label' => $route];
            }, $result['data']['flat_routes'] ?? []);
            echo json_encode(['success' => true, 'data' => $routes]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'languages':
        // Return list of languages
        $result = makeInternalApiCall('getLangList', $token);
        if ($result['success']) {
            $langs = array_map(function($lang) {
                return ['value' => $lang, 'label' => strtoupper($lang)];
            }, $result['data']['languages'] ?? []);
            echo json_encode(['success' => true, 'data' => $langs]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'assets':
        // Return list of assets, optionally filtered by category
        $category = $params[0] ?? '';
        
        $url = 'listAssets';
        if ($category) {
            $url .= '/' . $category;
        }
        
        $result = makeInternalApiCall($url, $token);
        if ($result['success']) {
            $files = [];
            // listAssets returns data in ['assets'][$category] format
            $fileData = [];
            if ($category && isset($result['data']['assets'][$category])) {
                $fileData = $result['data']['assets'][$category];
            } elseif (isset($result['data']['assets'])) {
                // If no category specified, flatten all categories
                foreach ($result['data']['assets'] as $catFiles) {
                    $fileData = array_merge($fileData, $catFiles);
                }
            }
            foreach ($fileData as $file) {
                // Use filename for value (what deleteAsset expects), full path for label
                $files[] = [
                    'value' => $file['filename'], 
                    'label' => $file['filename'] . ' (' . formatBytes($file['size'] ?? 0) . ')'
                ];
            }
            echo json_encode(['success' => true, 'data' => $files]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'asset-categories':
        // Return asset categories
        echo json_encode([
            'success' => true,
            'data' => [
                ['value' => 'images', 'label' => 'Images'],
                ['value' => 'scripts', 'label' => 'Scripts'],
                ['value' => 'font', 'label' => 'Fonts'],
                ['value' => 'audio', 'label' => 'Audio'],
                ['value' => 'videos', 'label' => 'Videos']
            ]
        ]);
        break;
        
    case 'builds':
        // Return list of builds
        $result = makeInternalApiCall('listBuilds', $token);
        if ($result['success']) {
            $buildsData = $result['data']['builds'] ?? [];
            $builds = array_map(function($build) {
                // Handle both array and object formats
                $name = is_array($build) ? $build['name'] : (isset($build->name) ? $build->name : $build);
                // Size is in folder_size_mb or zip_size_mb fields
                $folderSize = is_array($build) ? ($build['folder_size_mb'] ?? null) : (isset($build->folder_size_mb) ? $build->folder_size_mb : null);
                $zipSize = is_array($build) ? ($build['zip_size_mb'] ?? null) : (isset($build->zip_size_mb) ? $build->zip_size_mb : null);
                $sizeStr = $folderSize !== null ? $folderSize . ' MB' : ($zipSize !== null ? $zipSize . ' MB (zip)' : 'Unknown');
                return ['value' => $name, 'label' => $name . ' (' . $sizeStr . ')'];
            }, $buildsData);
            echo json_encode(['success' => true, 'data' => $builds]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unknown error']);
        }
        break;
        
    case 'aliases':
        // Return list of aliases for deleteAlias
        $result = makeInternalApiCall('listAliases', $token);
        if ($result['success']) {
            $aliases = [];
            // listAliases returns array of objects with 'alias' and 'target' properties
            foreach ($result['data']['aliases'] ?? [] as $aliasInfo) {
                $aliasPath = is_array($aliasInfo) ? $aliasInfo['alias'] : $aliasInfo;
                $targetPath = is_array($aliasInfo) ? $aliasInfo['target'] : '';
                $aliases[] = [
                    'value' => $aliasPath,
                    'label' => $aliasPath . ' → ' . $targetPath
                ];
            }
            echo json_encode(['success' => true, 'data' => $aliases]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    case 'alias-types':
        // Return available alias types for createAlias
        echo json_encode([
            'success' => true,
            'data' => [
                ['value' => 'redirect', 'label' => 'Redirect (301)'],
                ['value' => 'internal', 'label' => 'Internal (Rewrite)']
            ]
        ]);
        break;
        
    case 'translation-keys':
        // Return list of translation keys from a specific language
        $lang = $params[0] ?? 'en';
        
        $result = makeInternalApiCall('getTranslation/' . $lang, $token);
        if ($result['success']) {
            $keys = flattenTranslationKeysForSelect($result['data']['translations'] ?? []);
            echo json_encode(['success' => true, 'data' => $keys]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'translation-full':
        // Return full translation data for a language (for showing current values)
        $lang = $params[0] ?? 'en';
        
        $result = makeInternalApiCall('getTranslation/' . $lang, $token);
        if ($result['success']) {
            echo json_encode(['success' => true, 'data' => $result['data']['translations'] ?? []]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'page-title':
        // Get current title for a specific route and language
        $route = $params[0] ?? null;
        $lang = $params[1] ?? null;
        
        if (!$route || !$lang) {
            echo json_encode(['success' => false, 'error' => 'Route and language parameters required']);
            break;
        }
        
        // Get translation for the language
        $result = makeInternalApiCall('getTranslation/' . $lang, $token);
        if ($result['success']) {
            $translations = $result['data']['translations'] ?? [];
            // Look for page.titles.{route}
            $title = $translations['page']['titles'][$route] ?? '';
            echo json_encode(['success' => true, 'data' => ['title' => $title]]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'translation-keys-grouped':
        // Return translation keys grouped by used/unused/unset status
        $lang = $params[0] ?? 'en';
        
        // Get all translation keys
        $translationResult = makeInternalApiCall('getTranslation/' . $lang, $token);
        if (!$translationResult['success']) {
            echo json_encode(['success' => false, 'error' => $translationResult['error']]);
            break;
        }
        
        // Get unused keys (keys in translation but not used in structure)
        $unusedResult = makeInternalApiCall('getUnusedTranslationKeys/' . $lang, $token);
        $unusedKeys = [];
        if ($unusedResult['success'] && isset($unusedResult['data']['results'][$lang]['unused_keys'])) {
            $unusedKeys = $unusedResult['data']['results'][$lang]['unused_keys'];
        }
        
        // Get missing/unset keys (keys in structure but not in translation)
        $validateResult = makeInternalApiCall('validateTranslations/' . $lang, $token);
        $missingKeys = [];
        if ($validateResult['success'] && isset($validateResult['data']['validation_results'][$lang]['missing_keys'])) {
            $missingKeys = $validateResult['data']['validation_results'][$lang]['missing_keys'];
        }
        
        // Flatten all existing keys
        $allKeys = flattenTranslationKeysForSelect($translationResult['data']['translations'] ?? []);
        
        // Group existing keys into used/unused
        $usedKeys = [];
        $unused = [];
        
        foreach ($allKeys as $keyData) {
            if (in_array($keyData['value'], $unusedKeys)) {
                $unused[] = $keyData;
            } else {
                $usedKeys[] = $keyData;
            }
        }
        
        // Format missing keys for select (these don't exist in translation yet)
        $unset = array_map(function($key) {
            return ['value' => $key, 'label' => $key];
        }, $missingKeys);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'used' => $usedKeys,
                'unused' => $unused,
                'unset' => $unset
            ]
        ]);
        break;
    
    case 'edit-actions':
        // Return available actions for editStructure
        echo json_encode([
            'success' => true,
            'data' => [
                ['value' => 'update', 'label' => 'Update (Replace node content)'],
                ['value' => 'delete', 'label' => 'Delete (Remove node)'],
                ['value' => 'insertBefore', 'label' => 'Insert Before (Add sibling before)'],
                ['value' => 'insertAfter', 'label' => 'Insert After (Add sibling after)']
            ]
        ]);
        break;
    
    case 'structure-nodes':
        // Return hierarchical node structure for a given type/name
        $type = $params[0] ?? null;
        $name = $params[1] ?? null;
        
        if (!$type) {
            echo json_encode(['success' => false, 'error' => 'Type parameter required']);
            break;
        }
        
        // Build endpoint based on type
        if ($type === 'page' || $type === 'component') {
            if (!$name) {
                echo json_encode(['success' => false, 'error' => 'Name parameter required for ' . $type]);
                break;
            }
            $endpoint = "getStructure/{$type}/{$name}/showIds";
        } else {
            $endpoint = "getStructure/{$type}/showIds";
        }
        
        $result = makeInternalApiCall($endpoint, $token);
        if ($result['success']) {
            $structure = $result['data']['structure'] ?? [];
            $nodes = buildHierarchicalNodeOptions($structure);
            echo json_encode(['success' => true, 'data' => $nodes]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'current-styles':
        // Return current CSS content for editStyles
        $result = makeInternalApiCall('getStyles', $token);
        if ($result['success']) {
            echo json_encode([
                'success' => true, 
                'data' => [
                    'content' => $result['data']['content'] ?? '',
                    'size' => $result['data']['size'] ?? 0,
                    'modified' => $result['data']['modified'] ?? null
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'root-variables':
        // Return CSS :root variables for setRootVariables selector
        $result = makeInternalApiCall('getRootVariables', $token);
        if ($result['success']) {
            $variables = $result['data']['variables'] ?? [];
            // Return as array with name and value for selector
            $varList = [];
            foreach ($variables as $name => $value) {
                $varList[] = [
                    'value' => $name,
                    'label' => $name . ': ' . (strlen($value) > 30 ? substr($value, 0, 30) . '...' : $value),
                    'currentValue' => $value
                ];
            }
            echo json_encode(['success' => true, 'data' => $varList]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'keyframes':
        // Return list of keyframe animation names
        $result = makeInternalApiCall('getKeyframes', $token);
        if ($result['success']) {
            $keyframes = $result['data']['keyframes'] ?? [];
            $list = [];
            foreach ($keyframes as $name => $frames) {
                // Show frame count in label
                $frameCount = count($frames);
                $list[] = [
                    'value' => $name,
                    'label' => $name . ' (' . $frameCount . ' frames)',
                    'frames' => $frames
                ];
            }
            echo json_encode(['success' => true, 'data' => $list]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    case 'style-rules':
        // Return CSS selectors grouped by global/media for getStyleRule/setStyleRule/deleteStyleRule
        $result = makeInternalApiCall('listStyleRules', $token);
        if ($result['success']) {
            $grouped = $result['data']['grouped'] ?? [];
            $selectors = $result['data']['selectors'] ?? [];
            
            // Build options with optgroups
            $options = [];
            
            // Global selectors
            if (!empty($grouped['global'])) {
                $globalOptions = [];
                foreach ($grouped['global'] as $selector) {
                    $globalOptions[] = [
                        'value' => $selector,
                        'label' => $selector,
                        'mediaQuery' => null
                    ];
                }
                $options[] = [
                    'type' => 'optgroup',
                    'label' => 'Global Selectors (' . count($grouped['global']) . ')',
                    'options' => $globalOptions
                ];
            }
            
            // Media query selectors
            if (!empty($grouped['media'])) {
                foreach ($grouped['media'] as $mediaQuery => $mediaSelectors) {
                    $mediaOptions = [];
                    foreach ($mediaSelectors as $selector) {
                        $mediaOptions[] = [
                            'value' => $selector,
                            'label' => $selector,
                            'mediaQuery' => $mediaQuery
                        ];
                    }
                    $options[] = [
                        'type' => 'optgroup',
                        'label' => '@media ' . $mediaQuery . ' (' . count($mediaSelectors) . ')',
                        'options' => $mediaOptions,
                        'mediaQuery' => $mediaQuery
                    ];
                }
            }
            
            echo json_encode(['success' => true, 'data' => $options]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
    
    // ========================================================================
    // AI Spec System Endpoints
    // ========================================================================
    
    case 'ai-specs':
        // List all available AI specs
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        $specs = $manager->listWorkflows();
        
        // Return just the metadata for listing
        $specList = array_map(function($spec) {
            return [
                'id' => $spec['id'],
                'version' => $spec['version'],
                'meta' => $spec['meta'],
                'source' => $spec['_source'] ?? 'core',
                'relatedCommands' => $spec['relatedCommands'] ?? [],
                'hasSteps' => !empty($spec['steps'])
            ];
        }, $specs);
        
        echo json_encode(['success' => true, 'data' => $specList]);
        break;
    
    case 'ai-spec':
        // Get a specific AI spec (rendered with data)
        if (empty($params[0])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing spec ID']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        $specId = $params[0];
        
        // Load the spec
        $spec = $manager->loadWorkflow($specId);
        if (!$spec) {
            http_response_code(404);
            echo json_encode(['error' => 'Spec not found: ' . $specId]);
            break;
        }
        
        // Validate the spec
        $validation = $manager->validateWorkflow($spec);
        if (!$validation['valid']) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Invalid spec',
                'validationErrors' => $validation['errors']
            ]);
            break;
        }
        
        // Get user parameters from query string
        $userParams = [];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, ['action'])) {
                $userParams[$key] = $value;
            }
        }
        
        // Fetch data requirements (pass userParams for condition evaluation)
        $data = $manager->fetchDataRequirements($spec, $userParams);
        
        // Render the prompt (signature: workflow, userParams, fetchedData)
        $prompt = $manager->renderPrompt($spec, $userParams, $data);
        
        // Get execution phases for phase-based execution
        $phases = $manager->getWorkflowPhases($spec, $userParams);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $spec['id'],
                'version' => $spec['version'],
                'meta' => $spec['meta'],
                'prompt' => $prompt,
                'phases' => $phases,
                'dataFetched' => array_keys($data),
                'userParams' => $userParams
            ]
        ]);
        break;
    
    case 'ai-spec-raw':
        // Get raw spec JSON (for debugging/editing)
        if (empty($params[0])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing spec ID']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        $spec = $manager->loadWorkflow($params[0]);
        
        if (!$spec) {
            http_response_code(404);
            echo json_encode(['error' => 'Spec not found: ' . $params[0]]);
            break;
        }
        
        // Include template content for debugging
        $templateFile = $spec['promptTemplate'] ?? $spec['id'] . '.md';
        $templatePath = $spec['_folder'] . '/' . $templateFile;
        $templateContent = file_exists($templatePath) ? file_get_contents($templatePath) : null;
        
        echo json_encode([
            'success' => true,
            'data' => [
                'spec' => $spec,
                'template' => $templateContent,
                'validation' => $manager->validateWorkflow($spec)
            ]
        ]);
        break;
    
    case 'ai-spec-preview':
        // Preview a spec with custom JSON and template
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['spec']) || !isset($input['template'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing spec or template data']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        
        $spec = $input['spec'];
        $template = $input['template'];
        
        // Validate the spec
        $validation = $manager->validateWorkflow($spec);
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid spec',
                'validationErrors' => $validation['errors']
            ]);
            break;
        }
        
        // Fetch data requirements (if any exist and are valid)
        $data = [];
        try {
            $data = $manager->fetchDataRequirements($spec);
        } catch (Exception $e) {
            // If data fetching fails, continue with empty data for preview
            $data = ['_error' => $e->getMessage()];
        }
        
        // Render the prompt using the provided template
        $prompt = $manager->renderTemplateString($template, $data);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'prompt' => $prompt,
                'dataFetched' => array_keys($data),
                'validation' => $validation
            ]
        ]);
        break;
    
    case 'ai-spec-save':
        // Save a custom spec to the custom folder
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['spec']) || !isset($input['template'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing spec or template data']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        
        $spec = $input['spec'];
        $template = $input['template'];
        $isNew = $input['isNew'] ?? true;
        $originalSpecId = $input['originalSpecId'] ?? '';
        
        // Validate the spec
        $validation = $manager->validateWorkflow($spec);
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid spec: ' . implode(', ', $validation['errors']),
                'validationErrors' => $validation['errors']
            ]);
            break;
        }
        
        $specId = $spec['id'];
        $customFolder = SECURE_FOLDER_PATH . '/admin/workflows/custom';
        
        // Ensure custom folder exists
        if (!is_dir($customFolder)) {
            mkdir($customFolder, 0755, true);
        }
        
        // Check if trying to overwrite a core spec
        $coreFolder = SECURE_FOLDER_PATH . '/admin/workflows/core';
        if (file_exists($coreFolder . '/' . $specId . '.json')) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot overwrite core spec: ' . $specId]);
            break;
        }
        
        // If editing and ID changed, delete old files
        if (!$isNew && $originalSpecId && $originalSpecId !== $specId) {
            $oldJsonPath = $customFolder . '/' . $originalSpecId . '.json';
            $oldMdPath = $customFolder . '/' . $originalSpecId . '.md';
            if (file_exists($oldJsonPath)) unlink($oldJsonPath);
            if (file_exists($oldMdPath)) unlink($oldMdPath);
        }
        
        // Set the promptTemplate reference
        $spec['promptTemplate'] = $specId . '.md';
        
        // Write JSON file
        $jsonPath = $customFolder . '/' . $specId . '.json';
        $jsonContent = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($jsonPath, $jsonContent) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write spec JSON file']);
            break;
        }
        
        // Write MD file
        $mdPath = $customFolder . '/' . $specId . '.md';
        if (file_put_contents($mdPath, $template) === false) {
            // Clean up JSON if MD write failed
            unlink($jsonPath);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write template MD file']);
            break;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $specId,
                'jsonPath' => $jsonPath,
                'mdPath' => $mdPath
            ]
        ]);
        break;
    
    case 'workflow-generate-steps':
        // Generate steps for a manual workflow
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['workflowId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing workflowId']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        
        $workflow = $manager->loadWorkflow($input['workflowId']);
        if (!$workflow) {
            http_response_code(404);
            echo json_encode(['error' => 'Workflow not found']);
            break;
        }
        
        // Get user params
        $userParams = $input['params'] ?? [];
        
        // Fetch data requirements
        $data = [];
        $dataError = null;
        try {
            $data = $manager->fetchDataRequirements($workflow, $userParams);
        } catch (\Throwable $e) {
            $dataError = $e->getMessage();
            $data = ['_error' => $e->getMessage()];
        }
        
        // Generate expanded steps
        $stepsError = null;
        try {
            $steps = $manager->generateSteps($workflow, $userParams, $data, []);
        } catch (\Throwable $e) {
            $stepsError = $e->getMessage();
            $steps = [];
        }
        
        $response = [
            'success' => true,
            'data' => [
                'steps' => $steps,
                'count' => count($steps)
            ]
        ];
        
        // Add debug info when step count is unexpectedly low
        if (count($steps) === 0 || $dataError || $stepsError) {
            $response['data']['_debug'] = [
                'dataKeys' => array_keys($data),
                'dataSummary' => array_map(function($v) {
                    if ($v === null) return 'null';
                    if (is_array($v)) return 'array(' . count($v) . ')';
                    return gettype($v) . ':' . substr(json_encode($v), 0, 100);
                }, $data),
                'dataError' => $dataError,
                'stepsError' => $stepsError,
                'workflowHasSteps' => isset($workflow['steps']) && count($workflow['steps']) > 0,
                'workflowStepCount' => count($workflow['steps'] ?? []),
            ];
        }
        
        echo json_encode($response);
        break;
    
    case 'workflow-phases':
        // Get execution phases for a workflow (metadata only)
        if (empty($params[0])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing workflow ID']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        
        $workflow = $manager->loadWorkflow($params[0]);
        if (!$workflow) {
            http_response_code(404);
            echo json_encode(['error' => 'Workflow not found: ' . $params[0]]);
            break;
        }
        
        // Get user params from query string
        $userParams = [];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, ['action'])) {
                $userParams[$key] = $value;
            }
        }
        
        $phases = $manager->getWorkflowPhases($workflow, $userParams);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'workflowId' => $params[0],
                'phases' => $phases
            ]
        ]);
        break;
    
    case 'workflow-resolve-phase':
        // Resolve a single sub-workflow phase with fresh data
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['workflowId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing workflowId']);
            break;
        }
        
        require_once SECURE_FOLDER_PATH . '/src/classes/WorkflowManager.php';
        $manager = new WorkflowManager();
        
        $result = $manager->resolveSubWorkflow($input['workflowId'], $input['params'] ?? []);
        
        if (isset($result['error'])) {
            http_response_code(404);
            echo json_encode(['error' => $result['error']]);
            break;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}

/**
 * Format bytes to human readable size
 */
function formatBytes(int $bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Execute a management command directly in-process (no HTTP call).
 * 
 * Parses endpoint like "listBuilds" or "getTranslation/en" or "getStructure/page/home/showIds"
 * into command name + URL params, then calls the __command_* function directly.
 */
function makeInternalApiCall(string $endpoint, string $token): array {
    // Parse endpoint: first segment is the command, rest are URL params
    $segments = explode('/', trim($endpoint, '/'));
    $command = array_shift($segments);
    $urlParams = $segments;
    
    // Validate command exists in routes
    if (!defined('ROUTES_MANAGEMENT_PATH')) {
        define('ROUTES_MANAGEMENT_PATH', SERVER_ROOT . '/' . SECURE_FOLDER_NAME . '/management/routes.php');
    }
    if (!defined('ROUTES_MANAGEMENT')) {
        define('ROUTES_MANAGEMENT', require ROUTES_MANAGEMENT_PATH);
    }
    
    if (!in_array($command, ROUTES_MANAGEMENT)) {
        return ['success' => false, 'error' => "Command not found: {$command}"];
    }
    
    // Load command file with COMMAND_INTERNAL_CALL to prevent auto-execution
    if (!defined('COMMAND_INTERNAL_CALL')) {
        define('COMMAND_INTERNAL_CALL', true);
    }
    
    $commandFile = SECURE_FOLDER_PATH . '/management/command/' . $command . '.php';
    if (!file_exists($commandFile)) {
        return ['success' => false, 'error' => "Command file not found: {$command}"];
    }
    
    require_once $commandFile;
    
    $functionName = '__command_' . $command;
    if (!function_exists($functionName)) {
        return ['success' => false, 'error' => "Command function not found: {$functionName}"];
    }
    
    try {
        /** @var ApiResponse $response */
        $response = $functionName([], $urlParams);
        $status = $response->getStatus();
        $data = $response->getData();
        
        if ($status >= 200 && $status < 300) {
            return ['success' => true, 'data' => $data ?? []];
        }
        
        return ['success' => false, 'error' => $response->toArray()['message'] ?? 'Command error'];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Command execution error: ' . $e->getMessage()];
    }
}

/**
 * Extract node IDs from a structure recursively
 */
function extractNodeIds(array $structure, string $prefix = ''): array {
    $nodes = [];
    
    foreach ($structure as $index => $node) {
        $nodeId = $node['id'] ?? $index;
        $nodeLabel = $node['element'] ?? $node['type'] ?? 'node';
        
        if (isset($node['attributes']['id'])) {
            $nodeLabel .= '#' . $node['attributes']['id'];
        } elseif (isset($node['attributes']['class'])) {
            $classes = is_array($node['attributes']['class']) 
                ? implode('.', $node['attributes']['class'])
                : $node['attributes']['class'];
            $nodeLabel .= '.' . str_replace(' ', '.', $classes);
        }
        
        $displayPath = $prefix ? $prefix . ' > ' . $nodeLabel : $nodeLabel;
        
        $nodes[] = [
            'value' => (string)$nodeId,
            'label' => $displayPath . ' (id: ' . $nodeId . ')'
        ];
        
        // Recurse into children
        if (!empty($node['children'])) {
            $childNodes = extractNodeIds($node['children'], $displayPath);
            $nodes = array_merge($nodes, $childNodes);
        }
    }
    
    return $nodes;
}

/**
 * Flatten nested translation array to dot notation keys for select options
 */
function flattenTranslationKeysForSelect(array $translations, string $prefix = ''): array {
    $keys = [];
    
    foreach ($translations as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        
        if (is_array($value)) {
            // Recurse into nested structure
            $childKeys = flattenTranslationKeysForSelect($value, $fullKey);
            $keys = array_merge($keys, $childKeys);
        } else {
            // Leaf node - add truncated preview
            $preview = is_string($value) ? substr($value, 0, 40) : '';
            if (strlen($value) > 40) $preview .= '...';
            
            $keys[] = [
                'value' => $fullKey,
                'label' => $fullKey . ($preview ? ' = "' . $preview . '"' : '')
            ];
        }
    }
    
    return $keys;
}

/**
 * Build hierarchical node options for structure editing
 * 
 * Since HTML doesn't support nested optgroups, we use:
 * - Visual indentation with dashes to show hierarchy
 * - Flat list of options with correct full nodeId paths
 * - Tag/class info for identification
 */
function buildHierarchicalNodeOptions(array $structure, string $prefix = '', int $depth = 0): array {
    $options = [];
    $indent = str_repeat('─', $depth); // Use box-drawing character for cleaner look
    $spacer = $depth > 0 ? ' ' : '';
    
    foreach ($structure as $index => $node) {
        // Build the full nodeId path
        $nodeId = $prefix === '' ? (string)$index : $prefix . $index;
        
        // If structure has _nodeId from showIds, prefer that
        if (isset($node['_nodeId'])) {
            $nodeId = $node['_nodeId'];
        }
        
        // Build descriptive info
        $tag = $node['tag'] ?? 'node';
        $info = $tag;
        
        if (isset($node['params']['class'])) {
            $class = is_array($node['params']['class']) 
                ? implode(' ', $node['params']['class']) 
                : $node['params']['class'];
            // Show first class only to keep it short
            $firstClass = explode(' ', $class)[0];
            $info .= '.' . $firstClass;
        }
        if (isset($node['params']['id'])) {
            $info .= '#' . $node['params']['id'];
        }
        if (isset($node['textKey']) && strpos($node['textKey'], '__RAW__') !== 0) {
            $info = '[' . $node['textKey'] . ']';
        }
        
        // Create the option
        $label = $indent . $spacer . $nodeId . ' (' . $info . ')';
        
        $options[] = [
            'type' => 'option',
            'value' => $nodeId,
            'label' => $label
        ];
        
        // Recurse into children
        if (!empty($node['children']) && is_array($node['children'])) {
            $childOptions = buildHierarchicalNodeOptions($node['children'], $nodeId . '.', $depth + 1);
            $options = array_merge($options, $childOptions);
        }
    }
    
    return $options;
}
