<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * editNode - Edits an existing node in a structure
 * 
 * @method POST
 * @url /management/editNode
 * @auth required
 * @permission editStructure
 * 
 * Modifies an existing node:
 * - Change tag type (with mandatory params validation)
 * - Add/update specific params
 * - Remove specific params (can't remove mandatory!)
 * - Change textKey reference (edge case)
 * 
 * NOTE: Does NOT edit translation values (use setTranslationKeys for that)
 * NOTE: Cannot edit component nodes (use editComponentToNode for that)
 */

// =============================================================================
// TAG CONSTANTS (shared with addNode.php)
// =============================================================================

/**
 * Mandatory parameters per tag type
 * NOTE: 'alt' is handled separately - auto-generated as translation key
 */
const EDIT_NODE_MANDATORY_PARAMS = [
    'a' => ['href'],
    'img' => ['src'],  // alt is auto-generated as translation key
    'input' => ['type'],
    'form' => ['action'],
    'iframe' => ['src'],
    'video' => ['src'],
    'audio' => ['src'],
    'source' => ['src'],
    'label' => ['for'],
    'select' => ['name'],
    'textarea' => ['name'],
    'area' => ['href'],  // alt is auto-generated as translation key
    'embed' => ['src'],
    'object' => ['data'],
    'track' => ['src'],
    'link' => ['href', 'rel'],
];

/**
 * Tags that require auto-generated alt translation key
 */
const EDIT_NODE_TAGS_WITH_ALT = ['img', 'area'];

/**
 * Reserved params that are auto-managed and cannot be set/modified manually
 * These are translatable attributes handled by the translation system
 */
const EDIT_NODE_RESERVED_PARAMS = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];

const EDIT_NODE_ALLOWED_TAGS = [
    'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
    'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
    'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr',
    'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
    'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
    'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
    'img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col',
    'embed', 'source', 'track', 'wbr',
    'details', 'summary', 'dialog', 'select', 'option', 'optgroup', 'textarea',
    'iframe', 'video', 'audio', 'canvas', 'svg', 'picture', 'object',
    'progress', 'meter', 'output', 'datalist', 'colgroup'
];

const EDIT_NODE_SELF_CLOSING_TAGS = [
    'img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col', 
    'embed', 'source', 'track', 'wbr'
];

const EDIT_NODE_CONTAINER_TAGS = [
    'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
    'ul', 'ol', 'li', 'form', 'table', 'tr', 'thead', 'tbody', 'tfoot', 'figure',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button',
    'blockquote', 'pre', 'label', 'td', 'th', 'figcaption', 'strong', 'em',
    'fieldset', 'legend', 'details', 'summary', 'dialog', 'dt', 'dd',
    'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
    'b', 'i', 'u', 'small', 'mark', 'caption', 'select', 'optgroup', 'datalist'
];

// =============================================================================
// HELPER FUNCTIONS (for auto textKey generation)
// =============================================================================

function generateUniqueTextKey(array $structure, string $prefix): string {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    $existingKeys = [];
    
    if (file_exists($translationFile)) {
        $content = @file_get_contents($translationFile);
        if ($content !== false) {
            $translations = json_decode($content, true);
            if (is_array($translations)) {
                $existingKeys = flattenTranslationKeys_editNode($translations);
            }
        }
    }
    
    $pattern = '/^' . preg_quote($prefix, '/') . '\.item(\d+)$/';
    $maxN = 0;
    foreach ($existingKeys as $key) {
        if (preg_match($pattern, $key, $matches)) {
            $maxN = max($maxN, (int)$matches[1]);
        }
    }
    
    return $prefix . '.item' . ($maxN + 1);
}

function flattenTranslationKeys_editNode(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenTranslationKeys_editNode($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

function createEmptyTranslation_editNode(string $textKey): bool {
    $translationFile = PROJECT_PATH . '/translate/default.json';
    
    $translations = [];
    if (file_exists($translationFile)) {
        $content = @file_get_contents($translationFile);
        if ($content !== false) {
            $translations = json_decode($content, true) ?? [];
        }
    }
    
    $keys = explode('.', $textKey);
    $ref = &$translations;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            if (!isset($ref[$key])) $ref[$key] = '';
        } else {
            if (!isset($ref[$key]) || !is_array($ref[$key])) $ref[$key] = [];
            $ref = &$ref[$key];
        }
    }
    
    $json = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($translationFile, $json, LOCK_EX) !== false;
}

// =============================================================================
// MAIN COMMAND FUNCTION
// =============================================================================

function __command_editNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required parameters
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
    
    // Optional edit parameters
    $newTag = $params['tag'] ?? null;
    $addParams = $params['addParams'] ?? [];
    $removeParams = $params['removeParams'] ?? [];
    $newTextKey = $params['textKey'] ?? null;
    
    // Filter out reserved params (translatable attributes) from addParams
    // These are auto-managed and cannot be set manually
    foreach (EDIT_NODE_RESERVED_PARAMS as $reserved) {
        unset($addParams[$reserved]);
    }
    
    // SECURITY: Validate no reserved data-qs-* attributes in addParams
    // These are auto-generated by QuickSite for Visual Editor functionality
    $reservedQsParam = findReservedQsParam($addParams);
    if ($reservedQsParam !== null) {
        return ApiResponse::create(400, 'validation.reserved_attribute')
            ->withMessage("Cannot use reserved attribute '{$reservedQsParam}'. Attributes starting with 'data-qs-' are reserved for QuickSite. Use a different prefix like 'data-custom-' or 'data-app-'.")
            ->withErrors([['field' => 'addParams.' . $reservedQsParam, 'reason' => 'reserved_attribute']]);
    }
    
    // Validate structure type
    $allowed_types = ['menu', 'footer', 'page', 'component'];
    if (!in_array($type, $allowed_types, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid type. Must be one of: " . implode(', ', $allowed_types))
            ->withErrors([['field' => 'type', 'value' => $type, 'allowed' => $allowed_types]]);
    }
    
    if (($type === 'page' || $type === 'component') && empty($name)) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'name', 'reason' => "required for type={$type}"]]);
    }
    
    // Validate node ID format
    if (!RegexPatterns::match('node_id', $nodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid nodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'nodeId', $nodeId)]);
    }
    
    // Validate new tag if provided
    if ($newTag !== null && !in_array($newTag, EDIT_NODE_ALLOWED_TAGS, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid tag '{$newTag}'.")
            ->withErrors([['field' => 'tag', 'value' => $newTag]]);
    }
    
    // Load structure file
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
    
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found");
    }
    
    $content = @file_get_contents($json_file);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read structure file");
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON: " . json_last_error_msg());
    }
    
    // Find the target node
    $targetNode = NodeNavigator::getNode($structure, $nodeId);
    if ($targetNode === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Node not found at: {$nodeId}");
    }
    
    // Cannot edit component nodes with this command
    if (isset($targetNode['component'])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot edit component nodes with editNode. Use editComponentToNode instead.");
    }
    
    // Cannot edit pure text nodes (nodes with only textKey)
    if (isset($targetNode['textKey']) && !isset($targetNode['tag'])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot edit text-only nodes. Use setTranslationKeys to change text.");
    }
    
    // Must be a tag node
    if (!isset($targetNode['tag'])) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Node is not a tag node.");
    }
    
    $currentTag = $targetNode['tag'];
    $finalTag = $newTag ?? $currentTag;
    
    // Build the new params
    $currentParams = $targetNode['params'] ?? [];
    $finalParams = $currentParams;
    
    // If changing tag, auto-remove params that were mandatory for the OLD tag
    // but are not relevant to the NEW tag (e.g., src/alt when changing img→div)
    if ($newTag !== null && $newTag !== $currentTag) {
        $oldMandatory = EDIT_NODE_MANDATORY_PARAMS[$currentTag] ?? [];
        $newMandatory = EDIT_NODE_MANDATORY_PARAMS[$newTag] ?? [];
        foreach ($oldMandatory as $oldParam) {
            // Remove old mandatory params if they're not mandatory for new tag
            // and were not explicitly added by the user
            if (!in_array($oldParam, $newMandatory, true) && !isset($addParams[$oldParam])) {
                unset($finalParams[$oldParam]);
            }
        }
    }
    
    // Add/update params
    foreach ($addParams as $key => $value) {
        $finalParams[$key] = $value;
    }
    
    // Remove params (but validate mandatory ones first)
    $mandatoryForTag = EDIT_NODE_MANDATORY_PARAMS[$finalTag] ?? [];
    $cannotRemove = [];
    foreach ($removeParams as $param) {
        if (in_array($param, $mandatoryForTag, true)) {
            $cannotRemove[] = $param;
        } else {
            unset($finalParams[$param]);
        }
    }
    
    if (!empty($cannotRemove)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Cannot remove mandatory params for <{$finalTag}>: " . implode(', ', $cannotRemove))
            ->withErrors([['field' => 'removeParams', 'cannotRemove' => $cannotRemove, 'tag' => $finalTag]]);
    }
    
    // Validate mandatory params exist for the final tag
    $missingMandatory = [];
    foreach ($mandatoryForTag as $required) {
        if (!isset($finalParams[$required]) || $finalParams[$required] === '') {
            $missingMandatory[] = $required;
        }
    }
    
    if (!empty($missingMandatory)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("Tag <{$finalTag}> requires params: " . implode(', ', $mandatoryForTag) . ". Missing: " . implode(', ', $missingMandatory))
            ->withErrors([['field' => 'params', 'missing' => $missingMandatory, 'tag' => $finalTag]]);
    }
    
    // Auto-generate alt key if changing to img/area and no alt provided
    $autoGeneratedAltKey = null;
    if (in_array($finalTag, EDIT_NODE_TAGS_WITH_ALT, true) && empty($finalParams['alt'])) {
        $prefix = $name ?: $type;
        $autoGeneratedAltKey = generateUniqueTextKey($structure, $prefix) . '.alt';
        $finalParams['alt'] = $autoGeneratedAltKey;
        // Create empty translation entry for alt
        createEmptyTranslation_editNode($autoGeneratedAltKey);
    }
    
    // Apply changes to the node
    $nodeIndices = array_map('intval', explode('.', $nodeId));
    $editResult = editNodeInStructure($structure, $nodeIndices, $finalTag, $finalParams, $newTextKey, $currentTag, $type, $name);
    
    if (!$editResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to edit node: " . $editResult['error']);
    }
    
    $structure = $editResult['structure'];
    
    // Write back
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file");
    }
    
    // Build response
    $responseData = [
        'type' => $type,
        'name' => $name,
        'nodeId' => $nodeId,
        'file' => $json_file
    ];
    
    $changes = [];
    if ($newTag !== null && $newTag !== $currentTag) {
        $changes['tag'] = ['from' => $currentTag, 'to' => $newTag];
    }
    if (!empty($addParams)) {
        $changes['addedParams'] = array_keys($addParams);
    }
    if (!empty($removeParams)) {
        $changes['removedParams'] = array_diff($removeParams, $cannotRemove);
    }
    if ($newTextKey !== null) {
        $changes['textKey'] = $newTextKey;
    }
    
    // Include textKey generation info if applicable
    if (!empty($editResult['textKeyGenerated'])) {
        $responseData['textKeyGenerated'] = true;
        $responseData['textKey'] = $editResult['textKey'];
        $responseData['translationCreated'] = true;
    }
    
    // Include alt key generation info if applicable
    if ($autoGeneratedAltKey) {
        $responseData['altKeyGenerated'] = true;
        $responseData['altKey'] = $autoGeneratedAltKey;
    }
    
    if (!empty($changes)) {
        $responseData['changes'] = $changes;
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node edited successfully')
        ->withData($responseData);
}

// =============================================================================
// STRUCTURE MANIPULATION
// =============================================================================

function editNodeInStructure(array $structure, array $nodeIndices, string $tag, array $params, ?string $textKey, string $currentTag = '', string $type = '', ?string $name = null): array {
    if (empty($nodeIndices)) {
        return ['success' => false, 'error' => 'Empty node indices'];
    }
    
    $ref = &$structure;
    
    // Detect if root is an array of nodes (page) or single object (component)
    // For components, the root is an object with 'tag' key, so we navigate through children
    $isRootObject = isset($structure['tag']);
    
    // Navigate to the node
    for ($i = 0; $i < count($nodeIndices); $i++) {
        $index = $nodeIndices[$i];
        
        // For root object (component), first index accesses children directly
        if ($isRootObject && $i === 0) {
            if (!isset($ref['children'][$index])) {
                return ['success' => false, 'error' => "Node not found at index {$index}"];
            }
            if ($i < count($nodeIndices) - 1) {
                // Not at the target yet, go to children of this node
                $ref = &$ref['children'][$index];
                if (!isset($ref['children'])) {
                    return ['success' => false, 'error' => 'Path interrupted: no children array'];
                }
                $ref = &$ref['children'];
            } else {
                // At the target node
                $ref = &$ref['children'][$index];
            }
        } else {
            // Standard array navigation
            if (!isset($ref[$index])) {
                return ['success' => false, 'error' => "Node not found at index {$index}"];
            }
            
            if ($i < count($nodeIndices) - 1) {
                // Not at the target yet, go to children
                $ref = &$ref[$index];
                if (!isset($ref['children'])) {
                    return ['success' => false, 'error' => 'Path interrupted: no children array'];
                }
                $ref = &$ref['children'];
            } else {
                // At the target node
                $ref = &$ref[$index];
            }
        }
    }
    
    // Apply changes
    $ref['tag'] = $tag;
    
    if (!empty($params)) {
        $ref['params'] = $params;
    } else {
        unset($ref['params']);
    }
    
    // Handle textKey change
    if ($textKey !== null) {
        // Find first textKey child and update it
        if (isset($ref['children']) && is_array($ref['children'])) {
            $found = false;
            foreach ($ref['children'] as &$child) {
                if (isset($child['textKey']) && !isset($child['tag']) && !isset($child['component'])) {
                    $child['textKey'] = $textKey;
                    $found = true;
                    break;
                }
            }
            // If no textKey child found and tag is a container, add one
            if (!$found && in_array($tag, EDIT_NODE_CONTAINER_TAGS, true)) {
                array_unshift($ref['children'], ['textKey' => $textKey]);
            }
        } elseif (in_array($tag, EDIT_NODE_CONTAINER_TAGS, true)) {
            // No children array, create one with textKey
            $ref['children'] = [['textKey' => $textKey]];
        }
    }
    
    // If changing to self-closing tag, remove children
    if (in_array($tag, EDIT_NODE_SELF_CLOSING_TAGS, true)) {
        unset($ref['children']);
    }
    // If changing FROM self-closing TO container tag, auto-generate textKey
    elseif (in_array($currentTag, EDIT_NODE_SELF_CLOSING_TAGS, true) && 
            in_array($tag, EDIT_NODE_CONTAINER_TAGS, true) && 
            !isset($ref['children'])) {
        // Generate a textKey like addNode does
        $prefix = $name ?: $type;
        $generatedTextKey = generateUniqueTextKey($structure, $prefix);
        $ref['children'] = [['textKey' => $generatedTextKey]];
        
        // Create empty translation entry in default.json
        createEmptyTranslation_editNode($generatedTextKey);
        
        return ['success' => true, 'structure' => $structure, 'textKeyGenerated' => true, 'textKey' => $generatedTextKey];
    }
    
    return ['success' => true, 'structure' => $structure];
}

// =============================================================================
// DIRECT EXECUTION
// =============================================================================

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editNode($trimParams->params(), $trimParams->additionalParams())->send();
}
