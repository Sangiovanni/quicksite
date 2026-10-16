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
 * - Recursive node insertion (full tree); every root of a snippet that has
 *   several, in order, at the position asked
 * - Auto-generated unique textKeys for all translatable content; a raw or
 *   literal value (`__RAW__` / `__LIT__`) is kept as written, with no key
 * - Translations copied to project's translation files
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectContainment.php';
require_once SECURE_FOLDER_PATH . '/src/functions/componentPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/nodeParamPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal / _guard

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
 * Extract component variables with type detection (for snippet component processing).
 * Mirrors extractComponentVariables from addComponentToNode.
 */
function extractSnippetComponentVariables(array $node, array &$variables = []): array {
    if (!is_array($node)) return $variables;
    
    $translatableParams = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];
    
    if (isset($node['params']) && is_array($node['params'])) {
        foreach ($node['params'] as $paramName => $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) continue;
                    if (!isset($variables[$varName])) {
                        $type = in_array($paramName, $translatableParams, true) ? 'textKey' : 'param';
                        $variables[$varName] = ['name' => $varName, 'type' => $type];
                    }
                }
            }
        }
    }
    
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (preg_match_all('/\{\{([^}]+)\}\}/', $node['textKey'], $matches)) {
            foreach ($matches[1] as $varName) {
                if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) continue;
                if (!isset($variables[$varName])) {
                    $variables[$varName] = ['name' => $varName, 'type' => 'textKey'];
                }
            }
        }
    }
    
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractSnippetComponentVariables($child, $variables);
        }
    }
    
    return $variables;
}

/**
 * Get a nested value from an array using dot notation
 */
function getNestedValue(array $arr, string $key) {
    $parts = explode('.', $key);
    $current = $arr;
    foreach ($parts as $part) {
        if (!is_array($current) || !isset($current[$part])) return null;
        $current = $current[$part];
    }
    return $current;
}

/**
 * Process snippet structure recursively:
 * - Replace textKeys with new unique keys
 * - Build mapping of old -> new keys for translations
 *
 * A raw or literal value (qs_is_literal_text()) is no key: in a text node, a
 * translatable attribute or a component's text variable it is kept as written,
 * with no new key and no translation, and takes no item number.
 *
 * @param array $keyMapping  old key => new key, as the answer reports it (a key the
 *                           snippet uses twice names its last copy)
 * @param array $keySources  new key => old key: every new key with the key its value
 *                           is read from, so each copy gets its translation
 */
function processSnippetStructure(array $node, string $prefix, int &$itemCounter, array &$keyMapping, array &$keySources): array {
    $processed = [];

    // Handle textKey nodes
    if (isset($node['textKey']) && !isset($node['tag'])) {
        $oldKey = $node['textKey'];
        if (qs_is_literal_text($oldKey)) {
            return ['textKey' => $oldKey];
        }
        $newKey = $prefix . '.item' . $itemCounter;
        $itemCounter++;
        $keyMapping[$oldKey] = $newKey;
        $keySources[$newKey] = $oldKey;
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
                    is_string($value) && strpos($value, '.') !== false && !qs_is_literal_text($value)) {
                    // This might be a translation key - map it
                    $newKey = $prefix . '.item' . $itemCounter;
                    $itemCounter++;
                    $keyMapping[$value] = $newKey;
                    $keySources[$newKey] = $value;
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
                $processed['children'][] = processSnippetStructure($child, $prefix, $itemCounter, $keyMapping, $keySources);
            }
        }
    }
    
    // Handle component references - remap data values to new unique textKeys
    if (isset($node['component'])) {
        $processed['component'] = $node['component'];
        if (isset($node['data']) && is_array($node['data'])) {
            $processed['data'] = [];
            
            // Load component definition to detect variable types
            // Stored reference, jailed by the shared resolver.
            $componentPath = qs_resolve_component_path($node['component'], PROJECT_PATH . '/templates/model/json/components');
            $componentVars = [];
            if ($componentPath !== null) {
                $compContent = @file_get_contents($componentPath);
                if ($compContent !== false) {
                    $compStructure = json_decode($compContent, true);
                    if (is_array($compStructure)) {
                        extractSnippetComponentVariables($compStructure, $componentVars);
                    }
                }
            }
            
            foreach ($node['data'] as $varName => $oldValue) {
                // Check if this variable is a textKey type (translatable)
                $isTextKey = isset($componentVars[$varName]) && $componentVars[$varName]['type'] === 'textKey';
                
                if ($isTextKey && is_string($oldValue) && !qs_is_literal_text($oldValue)) {
                    // Generate new unique key and map old -> new
                    $newKey = $prefix . '.item' . $itemCounter;
                    $itemCounter++;
                    $keyMapping[$oldValue] = $newKey;
                    $keySources[$newKey] = $oldValue;
                    $processed['data'][$varName] = $newKey;
                } else {
                    // Non-textKey variables (params like href, class), and raw or
                    // literal texts - keep as-is
                    $processed['data'][$varName] = $oldValue;
                }
            }
        }
    }
    
    return $processed;
}

/**
 * Add translations to project translation files
 *
 * @param array $keySources new key => the snippet's key its value is read from
 *                          (processSnippetStructure()): one value per new key, so
 *                          two copies of one key both get it
 */
function addSnippetTranslations(array $keySources, array $snippetTranslations): array {
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
        foreach ($keySources as $newKey => $oldKey) {
            // Find translation value from snippet
            $value = '';
            
            // Check snippet translations for this language
            if (isset($snippetTranslations[$filename][$oldKey])) {
                $value = $snippetTranslations[$filename][$oldKey];
            } elseif (isset($snippetTranslations['en'][$oldKey])) {
                // Fallback to English
                $value = $snippetTranslations['en'][$oldKey];
            } else {
                // Fallback: look up old key in project translations (for component data remapping)
                $existingValue = getNestedValue($translations, $oldKey);
                if ($existingValue !== null && is_string($existingValue)) {
                    $value = $existingValue;
                } else {
                    // Last resort: formatted key name
                    $value = ucwords(str_replace(['.', '_', '-'], ' ', basename($oldKey)));
                }
            }
            
            // Set nested key
            setNestedKey($translations, $newKey, $value);
            $addedKeys[$newKey] = true;
        }
        
        // Write back, after the size check (qs_small_write_guard): a refusal
        // stops here, takes back the files written before, and the command
        // answers qs_small_write_refused().
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (!qs_small_write_guard($langFile, qs_json_size($translations, $flags))) {
            break;
        }
        qs_json_write($langFile, $translations, $flags, LOCK_EX);
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
    
    if ($targetNodeId === null || $targetNodeId === '') {
        return ApiResponse::create(400, 'validation.target_required')
            ->withMessage('Target node ID is required');
    }
    
    // Handle special 'root' targetNodeId
    $isRootTarget = ($targetNodeId === 'root');
    if ($isRootTarget) {
        $position = 'inside';
    } elseif (!is_scalar($targetNodeId) || !RegexPatterns::match('node_id', (string) $targetNodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid targetNodeId format. Use dot notation like '0.2.1' or 'root'")
            ->withErrors([RegexPatterns::validationError('node_id', 'targetNodeId', $targetNodeId)]);
    }
    
    if (!in_array($position, ['before', 'after', 'inside'])) {
        return ApiResponse::create(400, 'validation.invalid_position')
            ->withMessage('Position must be: before, after, or inside');
    }
    
    if (!$snippetId) {
        return ApiResponse::create(400, 'validation.snippet_required')
            ->withMessage('Snippet ID is required');
    }
    
    // CONTAINMENT: source the snippet from the project this request is
    // authorized for — the URL marker — NOT from an installation-wide default
    // project. The insert itself already writes into the marker (PROJECT_PATH),
    // so reading the source from a different project would make one operation
    // straddle two: every editor on every project would read the same snippet
    // library, and the command would silently misbehave for every other project.
    $bound = qs_bind_marker_project($params, 'insertSnippet');
    if ($bound['refusal'] !== null) {
        return $bound['refusal'];
    }
    $projectName = $bound['project'];

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
    
    // Load structure file: a page by the one page-name rule, a component by the one
    // component-name rule.
    switch ($type) {
        case 'page':
            $refusal = qs_page_name_refusal($name);
            if ($refusal !== null) {
                return $refusal;
            }
            $json_file = resolvePageJsonPath($name);
            if ($json_file === null) {
                return ApiResponse::create(404, 'structure.not_found')
                    ->withMessage('Page structure not found: ' . $name);
            }
            break;
        case 'component':
            $refusal = qs_component_name_refusal($name);
            if ($refusal !== null) {
                return $refusal;
            }
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

    // Inside a component call or a text node, as addNode refuses: neither renders
    // children, so the snippet would be written and never shown. A target that does
    // not exist is the insert's own answer below.
    if (!$isRootTarget && $position === 'inside') {
        $targetNode = NodeNavigator::getNode($structure, (string) $targetNodeId);
        if ($targetNode !== null && isset($targetNode['component'])) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage('Cannot insert inside a component. Components are atomic.');
        }
        if ($targetNode !== null && isset($targetNode['textKey']) && !isset($targetNode['tag'])) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage('Cannot insert inside a text node.');
        }
    }

    // Generate text key prefix and starting item number
    $prefix = generateSnippetTextKeyPrefix($type, $name);
    $itemCounter = getNextItemNumber($prefix);

    // Process snippet structure - generate new unique textKeys. A snippet is one node
    // or a list of roots; every root is processed, the item numbers running on from
    // one root to the next.
    $keyMapping = [];
    $keySources = [];
    $snippetStructure = $snippet['structure'];
    $roots = (is_array($snippetStructure) && $snippetStructure !== [] && array_is_list($snippetStructure))
        ? $snippetStructure
        : [$snippetStructure];
    $processedRoots = [];
    foreach ($roots as $root) {
        if (!is_array($root)) {
            return ApiResponse::create(400, 'snippets.no_structure')
                ->withMessage('Snippet structure holds a root that is not a node');
        }
        $processedRoots[] = processSnippetStructure($root, $prefix, $itemCounter, $keyMapping, $keySources);
    }

    // Insert every root at the target, last one first: each lands in front of the one
    // inserted before it, so the roots end in order. A list root takes the snippet as
    // its first entries, a node root as its first children (the rule every root insert
    // shares); before, after or inside a node, NodeNavigator::insertAt.
    for ($i = count($processedRoots) - 1; $i >= 0; $i--) {
        $insertResult = $isRootTarget
            ? NodeNavigator::insertAtRoot($structure, $processedRoots[$i])
            : NodeNavigator::insertAt($structure, (string) $targetNodeId, $processedRoots[$i], $position);
        if (!$insertResult['success']) {
            return ApiResponse::create(400, 'operation.failed')
                ->withMessage('Failed to insert snippet: ' . ($insertResult['error'] ?? 'Unknown error'));
        }
        $structure = $insertResult['structure'];
    }

    // The first root's id, then its siblings' in order.
    $newNodeId = $insertResult['newNodeId'];
    $idParts = explode('.', $newNodeId);
    $firstIndex = (int) array_pop($idParts);
    $newNodeIds = [];
    foreach (array_keys($processedRoots) as $i) {
        $newNodeIds[] = implode('.', array_merge($idParts, [(string) ($firstIndex + $i)]));
    }
    
    // SECURITY: depth-check the RESULT of the snippet insert.
    if (!qs_structure_depth_ok($structure)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Structure too deeply nested (max 50 levels)')
            ->withErrors([['field' => 'structure', 'reason' => 'exceeds max depth of 50']]);
    }

    // The whole structure about to be written: the snippet's tree and the page
    // it lands in.
    $unsafeStructureParam = qs_first_unsafe_structure_param($structure);
    if ($unsafeStructureParam !== null) {
        return qs_unsafe_structure_param_response($unsafeStructureParam);
    }

    // The snippet's translations, once nothing can refuse the insert: a refused
    // insert writes nothing at all.
    $addedTranslations = [];
    if (!empty($keySources)) {
        $addedTranslations = addSnippetTranslations($keySources, $snippet['translations'] ?? []);
    }

    // Save structure, after the size check the translations had
    // (qs_small_write_guard): a refusal takes them back too.
    $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (qs_small_write_refused() !== null || !qs_small_write_guard($json_file, qs_json_size($structure, $jsonFlags))) {
        return qs_small_write_refused();
    }
    if (!qs_json_write($json_file, $structure, $jsonFlags, LOCK_EX)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write structure file');
    }
    
    // Render HTML for live DOM update
    $renderedHtml = '';
    try {
        if ($type === 'page') {
            $structureName = 'page-' . $name;
        } elseif ($type === 'component' && $name) {
            $structureName = 'component-' . $name;
        } else {
            $structureName = $type;
        }
        
        $lang = qs_project_default_language();
        $translator = new Translator($lang);
        $renderer = new JsonToHtmlRenderer($translator, ['editorMode' => true]);
        
        // Every root, in order.
        foreach ($newNodeIds as $rootId) {
            $renderedHtml .= $renderer->renderNodeAtPath($structure, $rootId, $structureName);
        }
    } catch (Exception $e) {
        error_log("Failed to render snippet HTML: " . $e->getMessage());
    }
    
    return ApiResponse::create(200, 'snippets.inserted')
        ->withMessage('Snippet inserted successfully')
        ->withData([
            'snippetId' => $snippetId,
            'snippetName' => $snippet['name'] ?? $snippetId,
            'newNodeId' => $newNodeId,
            'newNodeIds' => $newNodeIds,
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
