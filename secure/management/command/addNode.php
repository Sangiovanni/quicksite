<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * addNode - Adds a new HTML tag node to a structure
 * 
 * @method POST
 * @url /management/addNode
 * @auth required
 * @permission editStructure
 * 
 * Inserts a new tag node at a specified position with:
 * - Tag-specific mandatory parameters (href for <a>, src/alt for <img>, etc.)
 * - Auto-generated textKey for content (creates empty translation entry)
 * - Smart handling of position "inside" (moves existing text children)
 * 
 * NOTE: For components, use addComponentToNode command instead.
 */

// =============================================================================
// TAG CLASSIFICATION CONSTANTS
// =============================================================================

/**
 * Block-level tags: textKey OPTIONAL if class is provided, REQUIRED if no class
 */
const BLOCK_TAGS = [
    'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 
    'figure', 'figcaption', 'blockquote', 'pre', 'form', 'fieldset',
    'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr'
];

/**
 * Inline tags: textKey ALWAYS required (they display text)
 */
const INLINE_TAGS = [
    'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 
    'a', 'button', 'label', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark',
    'li', 'td', 'th', 'dt', 'dd', 'caption', 'legend',
    'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address'
];

/**
 * Self-closing tags: NO textKey needed (no text content)
 */
const SELF_CLOSING_TAGS = [
    'img', 'input', 'br', 'hr', 'meta', 'link', 'area', 'base', 'col', 
    'embed', 'source', 'track', 'wbr'
];

/**
 * Tags that can have children
 */
const CONTAINER_TAGS = [
    'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside',
    'ul', 'ol', 'li', 'form', 'table', 'tr', 'thead', 'tbody', 'tfoot', 'figure',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button',
    'blockquote', 'pre', 'label', 'td', 'th', 'figcaption', 'strong', 'em',
    'fieldset', 'legend', 'details', 'summary', 'dialog', 'dt', 'dd',
    'code', 'kbd', 'samp', 'var', 'cite', 'q', 'abbr', 'time', 'address',
    'b', 'i', 'u', 'small', 'mark', 'caption', 'select', 'optgroup', 'datalist'
];

/**
 * Mandatory parameters per tag type
 * NOTE: 'alt' is handled separately - auto-generated as translation key like textKey
 * Format: {prefix}.item{N}.alt
 */
const MANDATORY_PARAMS = [
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
const TAGS_WITH_ALT = ['img', 'area'];

/**
 * Reserved params that are auto-managed and cannot be set manually
 * These are translatable attributes handled by the translation system
 */
const RESERVED_PARAMS = ['alt', 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'aria-description'];

/**
 * All allowed tags
 */
const ALLOWED_TAGS = [
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

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function getTagCategory(string $tag): string {
    if (in_array($tag, SELF_CLOSING_TAGS, true)) return 'self-closing';
    if (in_array($tag, INLINE_TAGS, true)) return 'inline';
    if (in_array($tag, BLOCK_TAGS, true)) return 'block';
    return 'block';
}

function tagRequiresTextKey(string $tag, array $params): bool {
    $category = getTagCategory($tag);
    if ($category === 'self-closing') return false;
    if ($category === 'inline') return true;
    return empty($params['class']); // Block: required if no class
}

function generateAutoTextKey(string $structureType, ?string $structureName): string {
    $prefix = ($structureType === 'page' && $structureName) ? $structureName : $structureType;
    
    $translationFile = PROJECT_PATH . '/translate/default.json';
    $existingKeys = [];
    
    if (file_exists($translationFile)) {
        $content = @file_get_contents($translationFile);
        if ($content !== false) {
            $translations = json_decode($content, true);
            if (is_array($translations)) {
                $existingKeys = flattenTranslationKeys_addNode($translations);
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

function flattenTranslationKeys_addNode(array $arr, string $prefix = ''): array {
    $keys = [];
    foreach ($arr as $key => $value) {
        $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenTranslationKeys_addNode($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }
    return $keys;
}

function createEmptyTranslation(string $textKey): bool {
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

function isComponentNode(array $node): bool {
    return isset($node['component']);
}

function findDirectTextKeyChildren(array $children): array {
    $indices = [];
    foreach ($children as $index => $child) {
        if (isset($child['textKey']) && !isset($child['tag']) && !isset($child['component'])) {
            $indices[] = $index;
        }
    }
    return $indices;
}

// =============================================================================
// MAIN COMMAND FUNCTION
// =============================================================================

function __command_addNode(array $params = [], array $urlParams = []): ApiResponse {
    // Required parameters
    if (!isset($params['type'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'type', 'reason' => 'missing']]);
    }
    if (!isset($params['targetNodeId'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withErrors([['field' => 'targetNodeId', 'reason' => 'missing']]);
    }
    if (!isset($params['tag'])) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("tag is required. For components, use addComponentToNode command.")
            ->withErrors([['field' => 'tag', 'reason' => 'missing']]);
    }
    
    $type = $params['type'];
    $name = $params['name'] ?? null;
    $targetNodeId = $params['targetNodeId'];
    $position = $params['position'] ?? 'after';
    $tag = $params['tag'];
    $nodeParams = $params['params'] ?? [];
    $textKey = $params['textKey'] ?? null;
    
    // Filter out reserved params (translatable attributes) from nodeParams
    // These are auto-managed and cannot be set manually
    foreach (RESERVED_PARAMS as $reserved) {
        unset($nodeParams[$reserved]);
    }
    
    // SECURITY: Validate no reserved data-qs-* attributes
    // These are auto-generated by QuickSite for Visual Editor functionality
    $reservedQsParam = findReservedQsParam($nodeParams);
    if ($reservedQsParam !== null) {
        return ApiResponse::create(400, 'validation.reserved_attribute')
            ->withMessage("Cannot use reserved attribute '{$reservedQsParam}'. Attributes starting with 'data-qs-' are reserved for QuickSite. Use a different prefix like 'data-custom-' or 'data-app-'.")
            ->withErrors([['field' => 'params.' . $reservedQsParam, 'reason' => 'reserved_attribute']]);
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
    if (!RegexPatterns::match('node_id', $targetNodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid targetNodeId format. Use dot notation like '0.2.1'")
            ->withErrors([RegexPatterns::validationError('node_id', 'targetNodeId', $targetNodeId)]);
    }
    
    // Validate position
    if (!in_array($position, ['before', 'after', 'inside'], true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid position. Must be 'before', 'after', or 'inside'")
            ->withErrors([['field' => 'position', 'value' => $position]]);
    }
    
    // Validate tag
    if (!in_array($tag, ALLOWED_TAGS, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid tag '{$tag}'.")
            ->withErrors([['field' => 'tag', 'value' => $tag]]);
    }
    
    // Validate mandatory params for tag
    if (isset(MANDATORY_PARAMS[$tag])) {
        $missing = [];
        foreach (MANDATORY_PARAMS[$tag] as $requiredParam) {
            if (!isset($nodeParams[$requiredParam]) || $nodeParams[$requiredParam] === '') {
                $missing[] = $requiredParam;
            }
        }
        if (!empty($missing)) {
            return ApiResponse::create(400, 'validation.required')
                ->withMessage("Tag <{$tag}> requires params: " . implode(', ', MANDATORY_PARAMS[$tag]))
                ->withErrors([['field' => 'params', 'missing' => $missing, 'tag' => $tag]]);
        }
    }
    
    // Auto-generate alt key for tags that require it (img, area)
    $autoGeneratedAltKey = null;
    if (in_array($tag, TAGS_WITH_ALT, true) && empty($nodeParams['alt'])) {
        $autoGeneratedAltKey = generateAutoTextKey($type, $name) . '.alt';
        $nodeParams['alt'] = $autoGeneratedAltKey;
        // Create empty translation entry for alt
        createEmptyTranslation($autoGeneratedAltKey);
    }
    
    // TextKey handling
    $autoGeneratedTextKey = false;
    if (tagRequiresTextKey($tag, $nodeParams) && empty($textKey)) {
        $textKey = generateAutoTextKey($type, $name);
        $autoGeneratedTextKey = true;
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
    
    // Verify target node exists
    $targetNode = NodeNavigator::getNode($structure, $targetNodeId);
    if ($targetNode === null) {
        return ApiResponse::create(404, 'node.not_found')
            ->withMessage("Target node not found at: {$targetNodeId}");
    }
    
    // Position "inside" validation
    if ($position === 'inside') {
        if (isComponentNode($targetNode)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("Cannot insert inside a component. Components are atomic.");
        }
        if (isset($targetNode['textKey']) && !isset($targetNode['tag'])) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("Cannot insert inside a text node.");
        }
    }
    
    // Check if we'll be moving existing textKey children (for "inside" position)
    $movedTextKeys = [];
    $willMoveTextKeys = false;
    if ($position === 'inside' && isset($targetNode['children']) && is_array($targetNode['children'])) {
        $movedTextKeys = findDirectTextKeyChildren($targetNode['children']);
        $willMoveTextKeys = !empty($movedTextKeys);
    }
    
    // Build new node
    $newNode = ['tag' => $tag];
    if (!empty($nodeParams)) $newNode['params'] = $nodeParams;
    
    // For container tags, initialize children array
    // If textKey is set, add it as a child node (NOT at same level as tag)
    // BUT: Don't add auto-generated textKey if we're moving existing textKey children
    if (in_array($tag, CONTAINER_TAGS, true)) {
        $newNode['children'] = [];
        if (!empty($textKey) && !$willMoveTextKeys) {
            // textKey goes INSIDE children as a separate node
            // Only if we're NOT moving existing text children
            $newNode['children'][] = ['textKey' => $textKey];
        }
    }
    
    // Insert node
    $targetIndices = array_map('intval', explode('.', $targetNodeId));
    $insertResult = insertNodeIntoStructure_addNode($structure, $targetIndices, $newNode, $position, $movedTextKeys);
    
    if (!$insertResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage("Failed to insert node: " . $insertResult['error']);
    }
    
    $structure = $insertResult['structure'];
    $newNodeId = $insertResult['newNodeId'];
    
    // Create translation if auto-generated AND we didn't move existing text
    $translationCreated = false;
    if ($autoGeneratedTextKey && !empty($textKey) && !$willMoveTextKeys) {
        $translationCreated = createEmptyTranslation($textKey);
    }
    
    // Write back
    $json_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($json_file, $json_content, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Failed to write structure file");
    }
    
    // Render HTML for live DOM update
    $renderedHtml = '';
    try {
        // Build structure name for data-qs-struct attribute
        if ($type === 'page') {
            $structureName = 'page-' . str_replace('/', '-', $name);
        } else {
            $structureName = $type;
        }
        
        // Create renderer with editor mode enabled
        $lang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        $translator = new Translator($lang);
        $renderer = new JsonToHtmlRenderer($translator, ['editorMode' => true]);
        
        $renderedHtml = $renderer->renderNodeAtPath($structure, $newNodeId, $structureName);
    } catch (Exception $e) {
        // Non-fatal - we can still return success without rendered HTML
        error_log("Failed to render node HTML: " . $e->getMessage());
    }
    
    // Response
    $responseData = [
        'type' => $type,
        'name' => $name,
        'newNodeId' => $newNodeId,
        'newNode' => $newNode,
        'position' => $position,
        'targetNodeId' => $targetNodeId,
        'file' => $json_file,
        'html' => $renderedHtml
    ];
    
    if ($autoGeneratedTextKey && !$willMoveTextKeys) {
        $responseData['textKeyGenerated'] = true;
        $responseData['textKey'] = $textKey;
        $responseData['translationCreated'] = $translationCreated;
    }
    
    if ($autoGeneratedAltKey) {
        $responseData['altKeyGenerated'] = true;
        $responseData['altKey'] = $autoGeneratedAltKey;
    }
    
    if ($willMoveTextKeys) {
        $responseData['movedTextKeyChildren'] = count($movedTextKeys);
    }
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Node added successfully')
        ->withData($responseData);
}

// =============================================================================
// STRUCTURE MANIPULATION
// =============================================================================

function insertNodeIntoStructure_addNode(array $structure, array $targetIndices, array $newNode, string $position, array $textKeyIndicesToMove = []): array {
    if (empty($targetIndices)) {
        return ['success' => false, 'error' => 'Empty target indices'];
    }
    
    if ($position === 'inside') {
        $ref = &$structure;
        for ($i = 0; $i < count($targetIndices); $i++) {
            $index = $targetIndices[$i];
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
        
        if (!isset($ref['children'])) $ref['children'] = [];
        
        // Move textKey children into new node (prepend to existing children)
        if (!empty($textKeyIndicesToMove) && isset($newNode['children'])) {
            rsort($textKeyIndicesToMove);
            $movedChildren = [];
            foreach ($textKeyIndicesToMove as $idx) {
                if (isset($ref['children'][$idx])) {
                    array_unshift($movedChildren, $ref['children'][$idx]);
                    array_splice($ref['children'], $idx, 1);
                }
            }
            // Prepend moved children to existing children (which may include auto-generated textKey)
            $newNode['children'] = array_merge($movedChildren, $newNode['children']);
        }
        
        array_unshift($ref['children'], $newNode);
        $newNodeId = implode('.', $targetIndices) . '.0';
        
        return ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
    }
    
    // Before/after: insert as sibling
    $parentPath = array_slice($targetIndices, 0, -1);
    $targetIndex = end($targetIndices);
    
    $ref = &$structure;
    for ($i = 0; $i < count($parentPath); $i++) {
        $index = $parentPath[$i];
        if (!isset($ref[$index])) {
            return ['success' => false, 'error' => "Parent node not found at index {$index}"];
        }
        $ref = &$ref[$index];
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

// =============================================================================
// DIRECT EXECUTION
// =============================================================================

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addNode($trimParams->params(), $trimParams->additionalParams())->send();
}
