<?php
/**
 * getComponent - Get a single component by name
 * 
 * @method GET
 * @url /management/getComponent
 * @auth required
 * @permission read
 * @param string $name Required - Component name (filename without .json)
 * 
 * Returns full component data including structure, variables, and
 * an expanded preview structure with nested component references resolved.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Expand nested component references in a component structure for preview.
 * Replaces component nodes with their full tag tree.
 */
function expandComponentForPreview(array $node, string $componentsDir, int $depth = 0): array {
    // Prevent infinite recursion from circular references
    if ($depth > 10) return $node;
    
    if (isset($node['component'])) {
        $componentName = $node['component'];
        $componentData = $node['data'] ?? [];
        
        $componentPath = $componentsDir . '/' . $componentName . '.json';
        if (file_exists($componentPath)) {
            $content = @file_get_contents($componentPath);
            if ($content !== false) {
                $compStructure = json_decode($content, true);
                if (is_array($compStructure)) {
                    $resolved = resolveComponentPlaceholdersForPreview($compStructure, $componentData);
                    return expandComponentForPreview($resolved, $componentsDir, $depth + 1);
                }
            }
        }
        
        return ['tag' => 'div', 'params' => ['data-component' => $componentName]];
    }
    
    $result = $node;
    if (isset($node['children']) && is_array($node['children'])) {
        $result['children'] = [];
        foreach ($node['children'] as $child) {
            $result['children'][] = expandComponentForPreview($child, $componentsDir, $depth);
        }
    }
    
    return $result;
}

/**
 * Replace {{varName}} placeholders in a component structure with data values
 */
function resolveComponentPlaceholdersForPreview(array $node, array $data): array {
    $result = [];
    
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $result['textKey'] = preg_replace_callback('/\{\{(\$?\w+)\}\}/', function($m) use ($data) {
            return $data[$m[1]] ?? $m[0];
        }, $node['textKey']);
        if (!isset($node['tag'])) return $result;
    }
    
    if (isset($node['tag'])) $result['tag'] = $node['tag'];
    
    if (isset($node['params'])) {
        $result['params'] = [];
        foreach ($node['params'] as $key => $value) {
            if (is_string($value)) {
                $result['params'][$key] = preg_replace_callback('/\{\{(\$?\w+)\}\}/', function($m) use ($data) {
                    return $data[$m[1]] ?? $m[0];
                }, $value);
            } else {
                $result['params'][$key] = $value;
            }
        }
    }
    
    if (isset($node['children']) && is_array($node['children'])) {
        $result['children'] = [];
        foreach ($node['children'] as $child) {
            $result['children'][] = resolveComponentPlaceholdersForPreview($child, $data);
        }
    }
    
    return $result;
}

/**
 * Collect all textKey values from a structure (recursive)
 */
function collectComponentTextKeys(array $node, array &$keys): void {
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $keys[] = $node['textKey'];
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (is_array($child)) collectComponentTextKeys($child, $keys);
        }
    }
}

/**
 * Load translation values for textKeys from project translation files
 */
function loadComponentTranslations(array $textKeys, string $projectPath): array {
    $translations = [];
    $translatePath = $projectPath . '/translate';
    
    if (!is_dir($translatePath) || empty($textKeys)) return $translations;
    
    $langFiles = glob($translatePath . '/*.json');
    foreach ($langFiles as $langFile) {
        $lang = basename($langFile, '.json');
        if ($lang === 'translations_index') continue;
        
        $content = @file_get_contents($langFile);
        $langData = $content ? json_decode($content, true) : [];
        if (!is_array($langData)) continue;
        
        foreach ($textKeys as $textKey) {
            // Support dot-notation keys
            $parts = explode('.', $textKey);
            $current = $langData;
            foreach ($parts as $part) {
                if (!is_array($current) || !isset($current[$part])) { $current = null; break; }
                $current = $current[$part];
            }
            if ($current !== null && is_string($current)) {
                if (!isset($translations[$lang])) $translations[$lang] = [];
                $translations[$lang][$textKey] = $current;
            }
        }
    }
    
    return $translations;
}

/**
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getComponent(array $params = [], array $urlParams = []): ApiResponse {
    $componentName = $params['name'] ?? null;
    
    if (!$componentName) {
        return ApiResponse::create(400, 'components.name_required')
            ->withMessage('Component name is required');
    }
    
    // Sanitize name: only allow alphanumeric, dash, underscore
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $componentName)) {
        return ApiResponse::create(400, 'components.invalid_name')
            ->withMessage('Invalid component name');
    }
    
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    $filePath = $componentsDir . '/' . $componentName . '.json';
    
    if (!file_exists($filePath)) {
        return ApiResponse::create(404, 'components.not_found')
            ->withMessage('Component not found: ' . $componentName);
    }
    
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return ApiResponse::create(500, 'components.read_error')
            ->withMessage('Failed to read component file');
    }
    
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(422, 'components.invalid_json')
            ->withMessage('Invalid JSON: ' . json_last_error_msg());
    }
    
    // Extract variables using the same logic as listComponents
    require_once SECURE_FOLDER_PATH . '/management/command/listComponents.php';
    $variables = [];
    if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
        extractVariables($structure, $variables);
    } elseif (is_array($structure)) {
        foreach ($structure as $node) {
            extractVariables($node, $variables);
        }
    }
    
    // Build preview: expand nested component references
    $previewStructure = $structure;
    $hasNestedComponents = false;
    
    // Check if structure has component references
    $checkComponents = function($node) use (&$checkComponents, &$hasNestedComponents) {
        if (!is_array($node)) return;
        if (isset($node['component'])) { $hasNestedComponents = true; return; }
        if (isset($node['children'])) {
            foreach ($node['children'] as $child) $checkComponents($child);
        }
    };
    
    if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
        $checkComponents($structure);
    } elseif (is_array($structure)) {
        foreach ($structure as $node) $checkComponents($node);
    }
    
    if ($hasNestedComponents) {
        if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
            $previewStructure = expandComponentForPreview($structure, $componentsDir);
        } elseif (is_array($structure)) {
            $previewStructure = array_map(fn($n) => expandComponentForPreview($n, $componentsDir), $structure);
        }
    }
    
    // Collect textKeys and load translations
    $textKeys = [];
    if (isset($previewStructure['tag']) || isset($previewStructure['textKey'])) {
        collectComponentTextKeys($previewStructure, $textKeys);
    } elseif (is_array($previewStructure)) {
        foreach ($previewStructure as $node) {
            if (is_array($node)) collectComponentTextKeys($node, $textKeys);
        }
    }
    
    $translations = [];
    if (!empty($textKeys)) {
        $translations = loadComponentTranslations($textKeys, PROJECT_PATH);
    }
    
    $result = [
        'name' => $componentName,
        'structure' => $structure,
        'variables' => $variables,
        'uses_components' => findUsedComponents($structure),
    ];
    
    if ($hasNestedComponents) {
        $result['previewStructure'] = $previewStructure;
    }
    
    if (!empty($translations)) {
        $result['translations'] = $translations;
    }
    
    return ApiResponse::create(200, 'components.get_success')
        ->withMessage('Component loaded: ' . $componentName)
        ->withData($result);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getComponent($trimParams->params(), $trimParams->additionalParams())->send();
}
