<?php
/**
 * getSnippet - Get a single snippet by ID
 * 
 * @method GET
 * @url /management/getSnippet
 * @auth required
 * @permission read
 * @param string $id Required - Snippet ID
 * @param string $project Optional - Project name (defaults to active project)
 * 
 * Returns full snippet data including structure, translations, and CSS.
 * Searches project snippets first, then falls back to core snippets.
 * For component-based snippets, expands the component structure for preview.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';

/**
 * Expand component references in a snippet structure for preview rendering.
 * Recursively traverses the structure and replaces component nodes with
 * the component's full tag tree, resolving {{$varName}} placeholders.
 */
function expandSnippetForPreview(array $node, string $projectPath): array {
    // If this is a component node, expand it
    if (isset($node['component'])) {
        $componentName = $node['component'];
        $componentData = $node['data'] ?? [];
        
        $componentPath = $projectPath . '/templates/model/json/components/' . $componentName . '.json';
        if (file_exists($componentPath)) {
            $compContent = @file_get_contents($componentPath);
            if ($compContent !== false) {
                $compStructure = json_decode($compContent, true);
                if (is_array($compStructure)) {
                    return resolveComponentPlaceholders($compStructure, $componentData);
                }
            }
        }
        
        // Fallback: placeholder div
        return ['tag' => 'div', 'params' => ['data-component' => $componentName]];
    }
    
    // For regular nodes, recurse into children
    $result = $node;
    if (isset($node['children']) && is_array($node['children'])) {
        $result['children'] = [];
        foreach ($node['children'] as $child) {
            $result['children'][] = expandSnippetForPreview($child, $projectPath);
        }
    }
    
    return $result;
}

/**
 * Replace {{$varName}} placeholders in a component structure with data values
 */
function resolveComponentPlaceholders(array $node, array $data): array {
    $result = [];
    
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $textKey = preg_replace_callback('/\{\{(\$?\w+)\}\}/', function($m) use ($data) {
            return $data[$m[1]] ?? $m[0];
        }, $node['textKey']);
        $result['textKey'] = $textKey;
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
            $result['children'][] = resolveComponentPlaceholders($child, $data);
        }
    }
    
    return $result;
}

/**
 * Collect all textKey values from an expanded structure
 */
function collectTextKeysFromStructure(array $node, array &$keys): void {
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        $keys[] = $node['textKey'];
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            collectTextKeysFromStructure($child, $keys);
        }
    }
}

/**
 * Get a nested value from an array using dot notation
 */
function getNestedValueFromArray(array $arr, string $key) {
    $parts = explode('.', $key);
    $current = $arr;
    foreach ($parts as $part) {
        if (!is_array($current) || !isset($current[$part])) return null;
        $current = $current[$part];
    }
    return $current;
}

/**
 * Load translation values for a set of textKeys from project translation files.
 * Returns: ['en' => ['key' => 'value', ...], 'fr' => [...], ...]
 */
function loadPreviewTranslations(array $textKeys, string $projectPath): array {
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
            $value = getNestedValueFromArray($langData, $textKey);
            if ($value !== null && is_string($value)) {
                if (!isset($translations[$lang])) $translations[$lang] = [];
                $translations[$lang][$textKey] = $value;
            }
        }
    }
    
    return $translations;
}

/**
 * Check if a snippet structure contains any component references (recursive)
 */
function structureHasComponent(array $node): bool {
    if (isset($node['component'])) return true;
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (is_array($child) && structureHasComponent($child)) return true;
        }
    }
    return false;
}

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getSnippet(array $params = [], array $urlParams = []): ApiResponse {
    $snippetId = $params['id'] ?? null;
    $projectName = $params['project'] ?? null;
    
    if (!$snippetId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Snippet ID is required');
    }
    
    // Get project name if not provided
    if (!$projectName) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }
    
    // Get snippet by ID
    $snippet = getSnippetById($snippetId, $projectName);
    
    if ($snippet === null) {
        return ApiResponse::create(404, 'snippets.not_found')
            ->withMessage('Snippet not found: ' . $snippetId);
    }
    
    // Remove internal fields
    unset($snippet['_filePath']);
    
    // Expand component references for preview rendering
    if (isset($snippet['structure']) && $projectName && structureHasComponent($snippet['structure'])) {
        $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
        
        $expanded = expandSnippetForPreview($snippet['structure'], $projectPath);
        $snippet['previewStructure'] = $expanded;
        
        // Collect textKeys from expanded structure and load translations
        $textKeys = [];
        collectTextKeysFromStructure($expanded, $textKeys);
        if (!empty($textKeys)) {
            $previewTranslations = loadPreviewTranslations($textKeys, $projectPath);
            if (!empty($previewTranslations)) {
                $snippet['translations'] = $previewTranslations;
            }
        }
    }
    
    // Check CSS selector availability in current project stylesheet
    if (!empty($snippet['selectors']) && $projectName) {
        require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
        $stylesheetPath = SECURE_FOLDER_PATH . '/projects/' . $projectName . '/public/style/style.css';
        $selectorStatus = [];
        
        if (file_exists($stylesheetPath)) {
            $cssContent = file_get_contents($stylesheetPath);
            $parser = new CssParser($cssContent);
            $allSelectors = $parser->listSelectors();
            $allSelectorNames = array_map(fn($s) => $s['selector'], $allSelectors);
            
            foreach ($snippet['selectors']['classes'] ?? [] as $class) {
                $selector = '.' . $class;
                $selectorStatus[] = [
                    'selector' => $selector,
                    'type' => 'class',
                    'exists' => in_array($selector, $allSelectorNames, true)
                ];
            }
            foreach ($snippet['selectors']['ids'] ?? [] as $id) {
                $selector = '#' . $id;
                $selectorStatus[] = [
                    'selector' => $selector,
                    'type' => 'id',
                    'exists' => in_array($selector, $allSelectorNames, true)
                ];
            }
        } else {
            // No stylesheet — all missing
            foreach ($snippet['selectors']['classes'] ?? [] as $class) {
                $selectorStatus[] = ['selector' => '.' . $class, 'type' => 'class', 'exists' => false];
            }
            foreach ($snippet['selectors']['ids'] ?? [] as $id) {
                $selectorStatus[] = ['selector' => '#' . $id, 'type' => 'id', 'exists' => false];
            }
        }
        
        $snippet['selectorStatus'] = $selectorStatus;
    }
    
    return ApiResponse::create(200, 'snippets.get_success')
        ->withMessage('Snippet loaded: ' . $snippet['name'])
        ->withData($snippet);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getSnippet($trimParams->params(), $trimParams->additionalParams())->send();
}
