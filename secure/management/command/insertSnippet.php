<?php
/**
 * insertSnippet - Insert a snippet's full structure into a page/component
 * 
 * @method POST
 * @url /management/insertSnippet
 * @auth required
 * @permission editStructure
 * 
 * @param string $type Required - Structure type: 'page', 'menu', 'footer', 'component'
 * @param string $name Required for page/component - Route name or component name
 * @param string $targetNodeId Required - Target node ID (e.g., "0.1.2")
 * @param string $position Required - 'before', 'after', or 'inside'
 * @param string $snippetId Required - Snippet ID to insert
 * 
 * Inserts the full snippet structure with:
 * - Recursive node insertion (full tree)
 * - Auto-generated unique textKeys for all translatable content
 * - Translations copied to project's translation files
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';

/**
 * Generate a unique text key prefix based on structure type and name
 */
function generateSnippetTextKeyPrefix(string $type, ?string $name): string {
    if (($type === 'page' || $type === 'component') && $name) {
        return $type === 'component' 
            ? 'component.' . $name
            : $name;
    }
    return $type;
}

/**
 * Get the next available item number for a prefix
 */
function getNextItemNumber(string $prefix): int {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    $existingKeys = [];
    
    if (file_exists($translationFile)) {
        $content = @file_get_contents($translationFile);
        if ($content !== false) {
            $translations = json_decode($content, true);
            if (is_array($translations)) {
                $existingKeys = flattenKeys($translations);
            }
        }
    }
    
    $pattern = '/^' . preg_quote($prefix, '/') . '\.item(\d+)/';
    $maxN = 0;
    foreach ($existingKeys as $key) {
        if (preg_match($pattern, $key, $matches)) {
            $maxN = max($maxN, (int)$matches[1]);
        }
    }
    
    return $maxN + 1;
}

/**
 * Flatten translation keys
 */
function flattenKeys(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

/**
 * Process snippet structure recursively:
 * - Replace textKeys with new unique keys
 * - Build mapping of old -> new keys for translations
 */
function processSnippetStructure(array $node, string $prefix, int &$itemCounter, array &$keyMapping): array {
    $processed = [];
    
    // Handle textKey nodes
    if (isset($node['textKey']) && !isset($node['tag'])) {
        $oldKey = $node['textKey'];
        $newKey = $prefix . '.item' . $itemCounter;
        $itemCounter++;
        $keyMapping[$oldKey] = $newKey;
        return ['textKey' => $newKey];
    }
    
    // Handle tag nodes
    if (isset($node['tag'])) {
        $processed['tag'] = $node['tag'];
        
        // Copy params but handle translatable attributes
        if (isset($node['params'])) {
            $processed['params'] = [];
            foreach ($node['params'] as $key => $value) {
                // For translatable attributes like alt, placeholder, etc.
                if (in_array($key, ['alt', 'placeholder', 'title', 'aria-label']) && 
                    is_string($value) && strpos($value, '.') !== false) {
                    // This might be a translation key - map it
                    $newKey = $prefix . '.item' . $itemCounter;
                    $itemCounter++;
                    $keyMapping[$value] = $newKey;
                    $processed['params'][$key] = $newKey;
                } else {
                    $processed['params'][$key] = $value;
                }
            }
        }
        
        // Process children recursively
        if (isset($node['children']) && is_array($node['children'])) {
            $processed['children'] = [];
            foreach ($node['children'] as $child) {
                $processed['children'][] = processSnippetStructure($child, $prefix, $itemCounter, $keyMapping);
            }
        }
    }
    
    // Handle component references (rare in snippets but possible)
    if (isset($node['component'])) {
        $processed['component'] = $node['component'];
        if (isset($node['data'])) {
            $processed['data'] = $node['data'];
        }
    }
    
    return $processed;
}

/**
 * Add translations to project translation files
 */
function addSnippetTranslations(array $keyMapping, array $snippetTranslations): array {
    $addedKeys = [];
    
    // Get all language files
    $translatePath = PROJECT_PATH . '/translate';
    if (!is_dir($translatePath)) {
        return $addedKeys;
    }
    
    $langFiles = glob($translatePath . '/*.json');
    
    foreach ($langFiles as $langFile) {
        $filename = basename($langFile, '.json');
        
        // Skip certain files
        if (in_array($filename, ['home', 'translations_index'])) {
            continue;
        }
        
        $content = @file_get_contents($langFile);
        $translations = $content ? json_decode($content, true) : [];
        if (!is_array($translations)) {
            $translations = [];
        }
        
        // Add each mapped translation
        foreach ($keyMapping as $oldKey => $newKey) {
            // Find translation value from snippet
            $value = '';
            
            // Check snippet translations for this language
            if (isset($snippetTranslations[$filename][$oldKey])) {
                $value = $snippetTranslations[$filename][$oldKey];
            } elseif (isset($snippetTranslations['en'][$oldKey])) {
                // Fallback to English
                $value = $snippetTranslations['en'][$oldKey];
            } else {
                // Use the old key as placeholder text
                $value = ucwords(str_replace(['.', '_', '-'], ' ', basename($oldKey)));
            }
            
            // Set nested key
            setNestedKey($translations, $newKey, $value);
            $addedKeys[$newKey] = true;
        }
        
        // Write back
        file_put_contents($langFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    
    return array_keys($addedKeys);
}

/**
 * Set a nested key in an array using dot notation
 */
function setNestedKey(array &$arr, string $key, $value): void {
    $parts = explode('.', $key);
    $current = &$arr;
    
    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            $current[$part] = $value;
        } else {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
    }
}

/**
 * Insert node into structure at specified position
 * Uses same logic as addNode.php for consistency
 */
function insertNodeAtPosition(array $structure, array $targetIndices, array $newNode, string $position): array {
    if (empty($targetIndices)) {
        return ['success' => false, 'error' => 'Empty target indices'];
    }
    
    // Detect if root is a single object (component/page) or array of nodes
    $isRootObject = isset($structure['tag']);
    
    if ($position === 'inside') {
        $ref = &$structure;
        for ($i = 0; $i < count($targetIndices); $i++) {
            $index = $targetIndices[$i];
            
            if ($isRootObject && $i === 0) {
                if (!isset($ref['children'][$index])) {
                    return ['success' => false, 'error' => "Node not found at index {$index}"];
                }
                $ref = &$ref['children'][$index];
                if ($i < count($targetIndices) - 1) {
                    if (!isset($ref['children'])) {
                        return ['success' => false, 'error' => 'Parent node has no children array'];
                    }
                    $ref = &$ref['children'];
                }
            } else {
                if (!isset($ref[$index])) {
                    return ['success' => false, 'error' => "Node not found at index {$index}"];
                }
                $ref = &$ref[$index];
                if ($i < count($targetIndices) - 1) {
                    if (!isset($ref['children'])) {
                        return ['success' => false, 'error' => 'Parent node has no children array'];
                    }
                    $ref = &$ref['children'];
                }
            }
        }
        
        if (!isset($ref['children'])) $ref['children'] = [];
        array_unshift($ref['children'], $newNode);
        $newNodeId = implode('.', $targetIndices) . '.0';
        
        return ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
    }
    
    // Before/after: insert as sibling
    $parentPath = array_slice($targetIndices, 0, -1);
    $targetIndex = end($targetIndices);
    
    $ref = &$structure;
    
    // For root object with empty parent path, we insert into children array
    if ($isRootObject && empty($parentPath)) {
        if (!isset($ref['children'][$targetIndex])) {
            return ['success' => false, 'error' => "Target node not found at index {$targetIndex}"];
        }
        
        $insertIndex = ($position === 'before') ? $targetIndex : $targetIndex + 1;
        array_splice($ref['children'], $insertIndex, 0, [$newNode]);
        
        $newNodeId = (string)$insertIndex;
        return ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
    }
    
    for ($i = 0; $i < count($parentPath); $i++) {
        $index = $parentPath[$i];
        
        if ($isRootObject && $i === 0) {
            if (!isset($ref['children'][$index])) {
                return ['success' => false, 'error' => "Parent node not found at index {$index}"];
            }
            $ref = &$ref['children'][$index];
        } else {
            if (!isset($ref[$index])) {
                return ['success' => false, 'error' => "Parent node not found at index {$index}"];
            }
            $ref = &$ref[$index];
        }
        
        if (!isset($ref['children'])) {
            return ['success' => false, 'error' => 'Parent node has no children array'];
        }
        $ref = &$ref['children'];
    }
    
    if (!isset($ref[$targetIndex])) {
        return ['success' => false, 'error' => "Target node not found at index {$targetIndex}"];
    }
    
    $insertIndex = ($position === 'before') ? $targetIndex : $targetIndex + 1;
    array_splice($ref, $insertIndex, 0, [$newNode]);
    
    $newNodeId = empty($parentPath) ? (string)$insertIndex : implode('.', $parentPath) . '.' . $insertIndex;
    
    return ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
}

/**
 * Command function
 */
function __command_insertSnippet(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required params
    $type = $params['type'] ?? null;
    $name = $params['name'] ?? null;
    $targetNodeId = $params['targetNodeId'] ?? null;
    $position = $params['position'] ?? 'after';
    $snippetId = $params['snippetId'] ?? null;
    
    if (!$type || !in_array($type, ['page', 'menu', 'footer', 'component'])) {
        return ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('Invalid structure type. Must be: page, menu, footer, or component');
    }
    
    if (($type === 'page' || $type === 'component') && !$name) {
        return ApiResponse::create(400, 'validation.name_required')
            ->withMessage('Name is required for page/component structures');
    }
    
    if (!$targetNodeId) {
        return ApiResponse::create(400, 'validation.target_required')
            ->withMessage('Target node ID is required');
    }
    
    // Handle special 'root' targetNodeId
    $isRootTarget = ($targetNodeId === 'root');
    if ($isRootTarget) {
        $position = 'inside';
    }
    
    if (!in_array($position, ['before', 'after', 'inside'])) {
        return ApiResponse::create(400, 'validation.invalid_position')
            ->withMessage('Position must be: before, after, or inside');
    }
    
    if (!$snippetId) {
        return ApiResponse::create(400, 'validation.snippet_required')
            ->withMessage('Snippet ID is required');
    }
    
    // Get target info from target.php
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    if (!file_exists($targetFile)) {
        return ApiResponse::create(500, 'config.no_target')
            ->withMessage('No project target configured');
    }
    $target = include $targetFile;
    $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
    
    // Load snippet
    $snippet = getSnippetById($snippetId, $projectName);
    if (!$snippet) {
        return ApiResponse::create(404, 'snippets.not_found')
            ->withMessage('Snippet not found: ' . $snippetId);
    }
    
    if (!isset($snippet['structure'])) {
        return ApiResponse::create(400, 'snippets.no_structure')
            ->withMessage('Snippet has no structure defined');
    }
    
    // Load structure file
    switch ($type) {
        case 'page':
            $json_file = resolvePageJsonPath($name);
            if ($json_file === null) {
                return ApiResponse::create(404, 'structure.not_found')
                    ->withMessage('Page structure not found: ' . $name);
            }
            break;
        case 'component':
            $json_file = PROJECT_PATH . '/templates/model/json/components/' . $name . '.json';
            break;
        default:
            $json_file = PROJECT_PATH . '/templates/model/json/' . $type . '.json';
            break;
    }
    
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'structure.not_found')
            ->withMessage('Structure file not found: ' . basename($json_file));
    }
    
    $content = file_get_contents($json_file);
    $structure = json_decode($content, true);
    if (!is_array($structure)) {
        return ApiResponse::create(500, 'structure.invalid')
            ->withMessage('Invalid structure JSON');
    }
    
    // Generate text key prefix and starting item number
    $prefix = generateSnippetTextKeyPrefix($type, $name);
    $itemCounter = getNextItemNumber($prefix);
    
    // Process snippet structure - generate new unique textKeys
    $keyMapping = [];
    $snippetStructure = $snippet['structure'];
    
    // Handle array of nodes at root level
    if (isset($snippetStructure[0])) {
        // Multiple root nodes - wrap in a container or process first one
        $processedStructure = processSnippetStructure($snippetStructure[0], $prefix, $itemCounter, $keyMapping);
    } else {
        $processedStructure = processSnippetStructure($snippetStructure, $prefix, $itemCounter, $keyMapping);
    }
    
    // Add translations to project
    $addedTranslations = [];
    if (!empty($keyMapping) && isset($snippet['translations'])) {
        $addedTranslations = addSnippetTranslations($keyMapping, $snippet['translations']);
    }
    
    // Insert processed structure into target
    if ($isRootTarget) {
        // Direct insertion into root's children (prepend like normal 'inside')
        if (!isset($structure['children'])) $structure['children'] = [];
        array_unshift($structure['children'], $processedStructure);
        $newNodeId = '0';
        $insertResult = ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
    } else {
        $targetPath = array_map('intval', explode('.', $targetNodeId));
        $insertResult = insertNodeAtPosition($structure, $targetPath, $processedStructure, $position);
    }
    
    if (!$insertResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage('Failed to insert snippet: ' . ($insertResult['error'] ?? 'Unknown error'));
    }
    
    $structure = $insertResult['structure'];
    $newNodeId = $insertResult['newNodeId'];
    
    // Save structure
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write structure file');
    }
    
    // Render HTML for live DOM update
    $renderedHtml = '';
    try {
        if ($type === 'page') {
            $structureName = 'page-' . str_replace('/', '-', $name);
        } elseif ($type === 'component' && $name) {
            $structureName = 'component-' . $name;
        } else {
            $structureName = $type;
        }
        
        $lang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        $translator = new Translator($lang);
        $renderer = new JsonToHtmlRenderer($translator, ['editorMode' => true]);
        
        $renderedHtml = $renderer->renderNodeAtPath($structure, $newNodeId, $structureName);
    } catch (Exception $e) {
        error_log("Failed to render snippet HTML: " . $e->getMessage());
    }
    
    return ApiResponse::create(200, 'snippets.inserted')
        ->withMessage('Snippet inserted successfully')
        ->withData([
            'snippetId' => $snippetId,
            'snippetName' => $snippet['name'] ?? $snippetId,
            'newNodeId' => $newNodeId,
            'position' => $position,
            'targetNodeId' => $targetNodeId,
            'translationsAdded' => count($addedTranslations),
            'keyMapping' => $keyMapping,
            'html' => $renderedHtml
        ]);
}

// Execute if called directly via API
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_insertSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
