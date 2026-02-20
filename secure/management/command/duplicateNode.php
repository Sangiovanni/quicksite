<?php
/**
 * duplicateNode - Duplicate a node with new translation keys
 * 
 * @method POST
 * @url /management/duplicateNode
 * @auth required
 * @permission editStructure
 * 
 * Creates a deep copy of a node and all its children, generating new
 * translation keys for all textKey and translatable attributes (alt, title, etc).
 * Optionally copies translation values from the source to the new keys.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Translatable attributes that need new keys when duplicating
 */
const DUPLICATE_TRANSLATABLE_ATTRS = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];

/**
 * Get all existing translation keys from default.json
 */
function getExistingTranslationKeys(): array {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    if (!file_exists($translationFile)) {
        return [];
    }
    
    $content = @file_get_contents($translationFile);
    if ($content === false) {
        return [];
    }
    
    $translations = json_decode($content, true);
    if (!is_array($translations)) {
        return [];
    }
    
    return flattenKeysRecursive($translations);
}

/**
 * Flatten nested array keys into dot notation
 */
function flattenKeysRecursive(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeysRecursive($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

/**
 * Generate a new unique text key based on prefix
 */
function generateNextTextKey(string $prefix, array &$existingKeys): string {
    $pattern = '/^' . preg_quote($prefix, '/') . '\.item(\d+)$/';
    $maxN = 0;
    
    foreach ($existingKeys as $key) {
        if (preg_match($pattern, $key, $matches)) {
            $maxN = max($maxN, (int)$matches[1]);
        }
    }
    
    $newKey = $prefix . '.item' . ($maxN + 1);
    // Add to existing keys to prevent collision in same batch
    $existingKeys[] = $newKey;
    
    return $newKey;
}

/**
 * Get translation value for a key
 */
function getTranslationValue(string $key): ?string {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    if (!file_exists($translationFile)) {
        return null;
    }
    
    $content = @file_get_contents($translationFile);
    if ($content === false) {
        return null;
    }
    
    $translations = json_decode($content, true);
    if (!is_array($translations)) {
        return null;
    }
    
    $parts = explode('.', $key);
    $ref = $translations;
    foreach ($parts as $part) {
        if (!isset($ref[$part])) {
            return null;
        }
        $ref = $ref[$part];
    }
    
    return is_string($ref) ? $ref : null;
}

/**
 * Set a translation key with a value
 */
function setTranslationKey(string $key, string $value): bool {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    
    $translations = [];
    if (file_exists($translationFile)) {
        $content = @file_get_contents($translationFile);
        if ($content !== false) {
            $translations = json_decode($content, true) ?? [];
        }
    }
    
    $parts = explode('.', $key);
    $ref = &$translations;
    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            $ref[$part] = $value;
        } else {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
    }
    
    $json = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($translationFile, $json, LOCK_EX) !== false;
}

/**
 * Recursively process a node and replace translation keys
 * 
 * @param array $node The node to process
 * @param string $prefix The key prefix for this structure
 * @param array &$existingKeys Reference to existing keys (updated as new keys are created)
 * @param bool $copyValues Whether to copy translation values
 * @param array &$keyMappings Track old->new key mappings for reporting
 * @return array The processed node with new keys
 */
function processNodeTranslationKeys(
    array $node, 
    string $prefix, 
    array &$existingKeys, 
    bool $copyValues,
    array &$keyMappings
): array {
    // Process textKey
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $oldKey = $node['textKey'];
        $newKey = generateNextTextKey($prefix, $existingKeys);
        $node['textKey'] = $newKey;
        
        // Copy translation value if requested
        $oldValue = $copyValues ? getTranslationValue($oldKey) : '';
        setTranslationKey($newKey, $oldValue ?? '');
        
        $keyMappings[$oldKey] = $newKey;
    }
    
    // Process translatable attributes in params
    if (isset($node['params']) && is_array($node['params'])) {
        foreach (DUPLICATE_TRANSLATABLE_ATTRS as $attr) {
            if (isset($node['params'][$attr]) && is_string($node['params'][$attr])) {
                $oldKey = $node['params'][$attr];
                // Only process if it looks like a translation key (has dots, not a URL)
                if (strpos($oldKey, '.') !== false && !preg_match('/^https?:/', $oldKey)) {
                    $newKey = generateNextTextKey($prefix, $existingKeys);
                    // Append .alt/.title/etc suffix for alt-type keys
                    $newKey .= '.' . $attr;
                    $existingKeys[] = $newKey; // Also track the suffixed key
                    $node['params'][$attr] = $newKey;
                    
                    // Copy translation value if requested
                    $oldValue = $copyValues ? getTranslationValue($oldKey) : '';
                    setTranslationKey($newKey, $oldValue ?? '');
                    
                    $keyMappings[$oldKey] = $newKey;
                }
            }
        }
    }
    
    // Recursively process children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $i => $child) {
            if (is_array($child)) {
                $node['children'][$i] = processNodeTranslationKeys(
                    $child, $prefix, $existingKeys, $copyValues, $keyMappings
                );
            }
        }
    }
    
    return $node;
}

/**
 * Command function for duplicateNode
 * 
 * @param array $params Body parameters: type, name?, nodeId, copyTranslations?
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_duplicateNode(array $params = [], array $urlParams = []): ApiResponse {
    // Validate required parameters
    if (!isset($params['type'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'type', 'reason' => 'missing']]);
    }
    
    if (!isset($params['nodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'nodeId', 'reason' => 'missing']]);
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $nodeId = $params['nodeId'];
    $copyTranslations = $params['copyTranslations'] ?? true;
    
    // Validate type
    $allowed_types = ['menu', 'footer', 'page', 'component'];
    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }
    
    // Name required for page/component
    if (($type === 'page' || $type === 'component') && empty($name)) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => "required for type={$type}"]]);
    }
    
    // Handle special 'root' nodeId
    $isRootTarget = ($nodeId === 'root');
    if ($isRootTarget) {
        if ($type !== 'component') {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage('Root duplication is only supported for components');
        }
    } elseif (!RegexPatterns::match('node_id', $nodeId)) {
        // Validate node ID format
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid nodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'nodeId', $nodeId)]);
    }
    
    // Build file path
    if ($type === 'page') {
        $json_file = resolvePageJsonPath($name);
        if ($json_file === null) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$name}' does not exist");
        }
    } elseif ($type === 'component') {
        $json_file = PROJECT_PATH . '/templates/model/json/components/' . $name . '.json';
    } else {
        $json_file = PROJECT_PATH . '/templates/model/json/' . $type . '.json';
    }
    
    // Read existing structure
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found")
            ->withData(['file' => $json_file]);
    }
    
    $content = @file_get_contents($json_file);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read structure file");
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON in structure file: " . json_last_error_msg());
    }
    
    // Handle root duplication for components: duplicate all children
    if ($isRootTarget) {
        $children = $structure['children'] ?? [];
        if (empty($children)) {
            return ApiResponse::create(400, 'operation.failed')
                ->withMessage('Component root has no children to duplicate');
        }
        
        // Determine key prefix
        $keyPrefix = 'component.' . $name;
        $existingKeys = getExistingTranslationKeys();
        $totalKeyMappings = [];
        $clonedChildren = [];
        
        foreach ($children as $child) {
            $cloned = json_decode(json_encode($child), true);
            if (is_array($cloned)) {
                removeNodeIdFields($cloned);
                $keyMappings = [];
                $processed = processNodeTranslationKeys($cloned, $keyPrefix, $existingKeys, $copyTranslations, $keyMappings);
                $clonedChildren[] = $processed;
                $totalKeyMappings = array_merge($totalKeyMappings, $keyMappings);
                // Update existing keys with newly created ones to avoid conflicts
                foreach ($keyMappings as $old => $new) {
                    $existingKeys[] = $new;
                }
            }
        }
        
        // Append cloned children to structure
        $firstNewIndex = count($structure['children']);
        foreach ($clonedChildren as $clonedChild) {
            $structure['children'][] = $clonedChild;
        }
        
        // Write back
        $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json_content === false) {
            return ApiResponse::create(500, 'server.internal_error')
                ->withMessage('Failed to encode structure to JSON');
        }
        if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage('Failed to write structure file');
        }
        
        $newNodeId = (string)$firstNewIndex;
        
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Component children duplicated successfully')
            ->withData([
                'type' => $type,
                'name' => $name,
                'sourceNodeId' => 'root',
                'newNodeId' => $newNodeId,
                'translationKeysMapped' => count($totalKeyMappings),
                'keyMappings' => $totalKeyMappings,
                'translationsCopied' => $copyTranslations,
                'html' => '' // Full reload needed for root duplication
            ]);
    }
    
    // Get the source node
    $sourceNode = NodeNavigator::getNode($structure, $nodeId);
    if ($sourceNode === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found at: {$nodeId}")
            ->withErrors([['field' => 'nodeId', 'value' => $nodeId]]);
    }
    
    // Deep clone the node
    $clonedNode = json_decode(json_encode($sourceNode), true);
    
    // Remove _nodeId fields if present
    removeNodeIdFields($clonedNode);
    
    // Determine key prefix based on structure type
    if (($type === 'page' || $type === 'component') && $name) {
        $keyPrefix = $type === 'component' ? 'component.' . $name : $name;
    } else {
        $keyPrefix = $type;
    }
    
    // Get existing keys and process the cloned node
    $existingKeys = getExistingTranslationKeys();
    $keyMappings = [];
    
    $processedNode = processNodeTranslationKeys(
        $clonedNode, 
        $keyPrefix, 
        $existingKeys, 
        $copyTranslations,
        $keyMappings
    );
    
    // Insert the processed node after the source
    $insertResult = NodeNavigator::insertNode($structure, $nodeId, $processedNode, 'after');
    
    if (!$insertResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to insert duplicated node: " . ($insertResult['error'] ?? 'Unknown error'));
    }
    
    // Write back to file
    $json_content = json_encode($insertResult['structure'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Failed to encode structure to JSON");
    }
    
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file");
    }
    
    // Calculate new node ID using the actual insertion index
    $parts = explode('.', $nodeId);
    array_pop($parts); // Remove the original index
    $parts[] = (string)$insertResult['insertedAt']; // Use the actual inserted index
    $newNodeId = implode('.', $parts);
    
    // Render HTML for live DOM update
    $renderedHtml = '';
    try {
        // Build structure name for data-qs-struct attribute
        if ($type === 'page') {
            $structureName = 'page-' . str_replace('/', '-', $name);
        } elseif ($type === 'component' && $name) {
            $structureName = 'component-' . $name;
        } else {
            $structureName = $type;
        }
        
        // Create renderer with editor mode enabled
        $lang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        $translator = new Translator($lang);
        $renderer = new JsonToHtmlRenderer($translator, ['editorMode' => true]);
        
        $renderedHtml = $renderer->renderNodeAtPath($insertResult['structure'], $newNodeId, $structureName);
    } catch (Exception $e) {
        // Non-fatal - we can still return success without rendered HTML
        error_log("Failed to render duplicated node HTML: " . $e->getMessage());
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node duplicated successfully')
        ->withData([
            'type' => $type,
            'name' => $name,
            'sourceNodeId' => $nodeId,
            'newNodeId' => $newNodeId,
            'translationKeysMapped' => count($keyMappings),
            'keyMappings' => $keyMappings,
            'translationsCopied' => $copyTranslations,
            'html' => $renderedHtml
        ]);
}

/**
 * Recursively remove _nodeId fields from a node structure
 */
function removeNodeIdFields(array &$node): void {
    unset($node['_nodeId']);
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as &$child) {
            if (is_array($child)) {
                removeNodeIdFields($child);
            }
        }
    }
}

// Execute via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_duplicateNode($params, [])->send();
}
