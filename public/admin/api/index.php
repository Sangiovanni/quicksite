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
        
    case 'routes':
        // Return list of routes
        $result = makeInternalApiCall('getRoutes', $token);
        if ($result['success']) {
            $routes = array_map(function($route) {
                return ['value' => $route, 'label' => $route];
            }, $result['data']['routes'] ?? []);
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
 * Make an internal API call using cURL
 */
function makeInternalApiCall(string $endpoint, string $token): array {
    $baseUrl = rtrim(BASE_URL, '/');
    $url = $baseUrl . '/management/' . $endpoint;
    
    // For local development, use localhost if BASE_URL is virtual
    if (strpos($url, 'template.vitrine') !== false) {
        // Replace with localhost since we're calling from the same server
        $localUrl = str_replace('http://template.vitrine', 'http://127.0.0.1', $url);
        // Add Host header
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Host: template.vitrine'
        ];
    } else {
        $localUrl = $url;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $localUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Network error: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $data['data'] ?? $data];
    }
    
    return ['success' => false, 'error' => $data['message'] ?? 'API error'];
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
