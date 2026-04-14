<?php
/**
 * Snippet Management Functions
 * 
 * Helper functions for managing snippets (core, global, and project-specific).
 * Snippets are pre-built content structures users can insert into pages.
 * 
 * Three-tier snippet scope:
 *   Core    = shipped, read-only (secure/snippets/core/)
 *   Global  = user-created, shared across projects (secure/snippets/custom/)
 *   Project = user-created, project-only (secure/projects/{proj}/snippets/)
 */

/**
 * Get path to core snippets directory
 * 
 * @return string Path to core snippets directory
 */
function getCoreSnippetsPath(): string {
    return SECURE_FOLDER_PATH . '/snippets/core';
}

/**
 * Get path to global (custom) snippets directory
 * 
 * @return string Path to global snippets directory
 */
function getGlobalSnippetsPath(): string {
    return SECURE_FOLDER_PATH . '/snippets/custom';
}

/**
 * Get path to project snippets directory
 * 
 * @param string $projectName Project name
 * @return string Path to project snippets directory
 */
function getProjectSnippetsPath(string $projectName): string {
    return SECURE_FOLDER_PATH . '/projects/' . $projectName . '/snippets';
}

/**
 * Get path to snippets directory (legacy compatibility)
 * 
 * @param string|null $projectName Project name (null for core snippets)
 * @return string Path to snippets directory
 */
function getSnippetsPath(?string $projectName = null): string {
    if ($projectName === null) {
        return getCoreSnippetsPath();
    }
    return getProjectSnippetsPath($projectName);
}

/**
 * Ensure project snippets directory exists
 * 
 * @param string $projectName Project name
 * @return bool True if directory exists or was created
 */
function ensureProjectSnippetsDir(string $projectName): bool {
    $path = getSnippetsPath($projectName);
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

/**
 * List all snippets from a directory (single level or with categories)
 * 
 * @param string $basePath Base path to search
 * @param string $source Source identifier: "core", "global", or "project"
 * @return array List of snippets with metadata
 */
function listSnippetsFromPath(string $basePath, string $source = 'core'): array {
    $snippets = [];
    
    if (!is_dir($basePath)) {
        return $snippets;
    }
    
    // Check for category subdirectories
    $categories = ['nav', 'forms', 'cards', 'layouts', 'content', 'lists', 'other'];
    $hasCategories = false;
    
    foreach ($categories as $category) {
        if (is_dir($basePath . '/' . $category)) {
            $hasCategories = true;
            break;
        }
    }
    
    if ($hasCategories) {
        // Scan category subdirectories
        foreach ($categories as $category) {
            $categoryPath = $basePath . '/' . $category;
            if (is_dir($categoryPath)) {
                $files = glob($categoryPath . '/*.json');
                foreach ($files as $file) {
                    $snippet = loadSnippetFile($file, $source);
                    if ($snippet !== null) {
                        $snippets[] = $snippet;
                    }
                }
            }
        }
        
        // Also check for any other directories (user-created categories)
        $dirs = glob($basePath . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            if (!in_array($dirName, $categories)) {
                $files = glob($dir . '/*.json');
                foreach ($files as $file) {
                    $snippet = loadSnippetFile($file, $source);
                    if ($snippet !== null) {
                        $snippets[] = $snippet;
                    }
                }
            }
        }
    }
    
    // Also check root level (for flat structure)
    $rootFiles = glob($basePath . '/*.json');
    foreach ($rootFiles as $file) {
        $snippet = loadSnippetFile($file, $source);
        if ($snippet !== null) {
            $snippets[] = $snippet;
        }
    }
    
    return $snippets;
}

/**
 * Load a single snippet file and return metadata
 * 
 * @param string $filePath Path to snippet JSON file
 * @param string $source Source identifier: "core", "global", or "project"
 * @return array|null Snippet metadata or null if invalid
 */
function loadSnippetFile(string $filePath, string $source = 'core'): ?array {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);
    
    if (!is_array($data) || !isset($data['id']) || !isset($data['name'])) {
        return null;
    }
    
    // Build snippet metadata (don't include full structure in list)
    return [
        'id' => $data['id'],
        'name' => $data['name'],
        'category' => $data['category'] ?? 'other',
        'description' => $data['description'] ?? '',
        'source' => $source,
        'isCore' => $source === 'core',
        'hasTranslations' => isset($data['translations']) && !empty($data['translations']),
        'hasCss' => isset($data['css']) && !empty($data['css']),
        'file' => basename($filePath)
    ];
}

/**
 * Get full snippet data by ID
 * Searches: project → global → core (most specific first)
 * 
 * @param string $snippetId Snippet ID
 * @param string|null $projectName Project name (null for active project)
 * @return array|null Full snippet data or null if not found
 */
function getSnippetById(string $snippetId, ?string $projectName = null): ?array {
    // Get project name if not provided
    if ($projectName === null) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }
    
    // 1. Check project snippets first
    if ($projectName) {
        $projectSnippetsPath = getProjectSnippetsPath($projectName);
        $snippet = findSnippetInPath($snippetId, $projectSnippetsPath, 'project');
        if ($snippet !== null) {
            return $snippet;
        }
    }
    
    // 2. Check global (custom) snippets
    $globalSnippetsPath = getGlobalSnippetsPath();
    $snippet = findSnippetInPath($snippetId, $globalSnippetsPath, 'global');
    if ($snippet !== null) {
        return $snippet;
    }
    
    // 3. Check core snippets
    $coreSnippetsPath = getCoreSnippetsPath();
    return findSnippetInPath($snippetId, $coreSnippetsPath, 'core');
}

/**
 * Find snippet by ID in a directory (searches categories)
 * 
 * @param string $snippetId Snippet ID to find
 * @param string $basePath Base path to search
 * @param string $source Source identifier: "core", "global", or "project"
 * @return array|null Full snippet data or null if not found
 */
function findSnippetInPath(string $snippetId, string $basePath, string $source = 'core'): ?array {
    if (!is_dir($basePath)) {
        return null;
    }
    
    // Search in category subdirectories
    $categories = ['nav', 'forms', 'cards', 'layouts', 'content', 'lists'];
    foreach ($categories as $category) {
        $categoryPath = $basePath . '/' . $category;
        if (is_dir($categoryPath)) {
            $files = glob($categoryPath . '/*.json');
            foreach ($files as $file) {
                $snippet = loadFullSnippet($file, $snippetId, $source);
                if ($snippet !== null) {
                    return $snippet;
                }
            }
        }
    }
    
    // Check other directories
    $dirs = glob($basePath . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $dirName = basename($dir);
        if (!in_array($dirName, $categories)) {
            $files = glob($dir . '/*.json');
            foreach ($files as $file) {
                $snippet = loadFullSnippet($file, $snippetId, $source);
                if ($snippet !== null) {
                    return $snippet;
                }
            }
        }
    }
    
    // Check root level
    $rootFiles = glob($basePath . '/*.json');
    foreach ($rootFiles as $file) {
        $snippet = loadFullSnippet($file, $snippetId, $source);
        if ($snippet !== null) {
            return $snippet;
        }
    }
    
    return null;
}

/**
 * Load full snippet data if ID matches
 * 
 * @param string $filePath Path to snippet file
 * @param string $snippetId ID to match
 * @param string $source Source identifier: "core", "global", or "project"
 * @return array|null Full snippet data or null if ID doesn't match
 */
function loadFullSnippet(string $filePath, string $snippetId, string $source = 'core'): ?array {
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);
    
    if (!is_array($data) || !isset($data['id'])) {
        return null;
    }
    
    if ($data['id'] !== $snippetId) {
        return null;
    }
    
    // Add source and legacy isCore flag
    $data['source'] = $source;
    $data['isCore'] = $source === 'core';
    $data['_filePath'] = $filePath;
    
    return $data;
}

/**
 * Extract CSS selectors and matching CSS rules from a snippet structure
 * 
 * Uses the same extraction logic as getCssForStructure: scans the structure
 * tree for all classes and IDs, then queries the project stylesheet.
 * Tags are intentionally excluded — they match too broadly and pull in
 * unrelated rules (e.g. a bare `p` tag would match `.feature-card p`).
 * 
 * @param array $structure Snippet structure (single node or array of nodes)
 * @param string $projectName Project name (for stylesheet path)
 * @return array ['selectors' => ['classes' => [...], 'ids' => [...]], 'css' => string]
 */
function extractSnippetCss(array $structure, string $projectName): array {
    require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
    require_once SECURE_FOLDER_PATH . '/management/command/getCssForStructure.php';

    // Normalize: extractCssSelectorsFromStructure expects an array of nodes
    $nodes = isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])
        ? [$structure]
        : $structure;

    $components = [];
    $allSelectors = extractCssSelectorsFromStructure($nodes, $components);

    // Only keep classes and IDs for snippet CSS — tags are too broad
    $selectors = [
        'classes' => $allSelectors['classes'] ?? [],
        'ids' => $allSelectors['ids'] ?? [],
    ];

    // Load project CSS
    $stylesheetPath = SECURE_FOLDER_PATH . '/projects/' . $projectName . '/public/style/style.css';
    if (!file_exists($stylesheetPath)) {
        return ['selectors' => $selectors, 'css' => ''];
    }

    $cssContent = file_get_contents($stylesheetPath);
    if (!$cssContent) {
        return ['selectors' => $selectors, 'css' => ''];
    }

    $parser = new CssParser($cssContent);
    $extracted = $parser->getCssForSelectors(
        $selectors['classes'],
        $selectors['ids'],
        [] // No tags — intentionally excluded
    );

    $css = $parser->formatExtractedCss($extracted);

    return ['selectors' => $selectors, 'css' => $css];
}

/**
 * Check if CSS selectors already exist in project stylesheet
 * 
 * @param string $css CSS content to check
 * @param string $stylesheetPath Path to project stylesheet
 * @return bool True if CSS already exists
 */
function snippetCssExists(string $css, string $stylesheetPath): bool {
    if (!file_exists($stylesheetPath)) {
        return false;
    }
    
    $existingCss = file_get_contents($stylesheetPath);
    
    // Extract selector names from snippet CSS (qs-snippet-* classes)
    if (preg_match_all('/\.qs-snippet-[\w-]+/', $css, $matches)) {
        $selectors = array_unique($matches[0]);
        // Check if any of these selectors exist in stylesheet
        foreach ($selectors as $selector) {
            if (strpos($existingCss, $selector) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Append snippet CSS to project stylesheet
 * 
 * @param string $css CSS content to append
 * @param string $stylesheetPath Path to project stylesheet
 * @return bool True on success
 */
function appendSnippetCss(string $css, string $stylesheetPath): bool {
    // Ensure directory exists
    $dir = dirname($stylesheetPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Add comment header
    $cssWithComment = "\n\n/* Snippet CSS - Auto-added */\n" . $css . "\n";
    
    return file_put_contents($stylesheetPath, $cssWithComment, FILE_APPEND) !== false;
}

/**
 * Save snippet to the appropriate directory based on scope
 * 
 * @param array $snippetData Snippet data to save
 * @param string $projectName Project name
 * @param string $scope Save scope: "project" (default) or "global"
 * @return array ['success' => bool, 'path' => string, 'error' => string|null]
 */
function saveProjectSnippet(array $snippetData, string $projectName, string $scope = 'project'): array {
    $category = $snippetData['category'] ?? 'other';
    $snippetId = $snippetData['id'] ?? null;
    
    if (!$snippetId) {
        return ['success' => false, 'path' => '', 'error' => 'Snippet ID is required'];
    }
    
    // Determine base path based on scope
    if ($scope === 'global') {
        $basePath = getGlobalSnippetsPath();
    } else {
        // Ensure project snippets directory exists
        if (!ensureProjectSnippetsDir($projectName)) {
            return ['success' => false, 'path' => '', 'error' => 'Failed to create snippets directory'];
        }
        $basePath = getProjectSnippetsPath($projectName);
    }
    
    // Create category directory if needed
    $categoryPath = $basePath . '/' . $category;
    if (!is_dir($categoryPath)) {
        mkdir($categoryPath, 0755, true);
    }
    
    // Remove internal flags
    unset($snippetData['isCore']);
    unset($snippetData['source']);
    unset($snippetData['_filePath']);
    
    $filePath = $categoryPath . '/' . $snippetId . '.json';
    $json = json_encode($snippetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($filePath, $json) === false) {
        return ['success' => false, 'path' => '', 'error' => 'Failed to write snippet file'];
    }
    
    return ['success' => true, 'path' => $filePath, 'error' => null];
}

/**
 * Delete snippet by ID from project or global scope
 * 
 * @param string $snippetId Snippet ID
 * @param string $projectName Project name
 * @return array ['success' => bool, 'error' => string|null, 'source' => string|null]
 */
function deleteProjectSnippet(string $snippetId, string $projectName): array {
    // Try project snippets first
    $projectSnippetsPath = getProjectSnippetsPath($projectName);
    $snippet = findSnippetInPath($snippetId, $projectSnippetsPath, 'project');
    
    if ($snippet === null) {
        // Try global snippets
        $globalSnippetsPath = getGlobalSnippetsPath();
        $snippet = findSnippetInPath($snippetId, $globalSnippetsPath, 'global');
    }
    
    if ($snippet === null) {
        return ['success' => false, 'error' => 'Snippet not found in project or global snippets', 'source' => null];
    }
    
    $filePath = $snippet['_filePath'] ?? null;
    
    if (!$filePath || !file_exists($filePath)) {
        return ['success' => false, 'error' => 'Snippet file not found', 'source' => null];
    }
    
    if (!unlink($filePath)) {
        return ['success' => false, 'error' => 'Failed to delete snippet file', 'source' => null];
    }
    
    return ['success' => true, 'error' => null, 'source' => $snippet['source'] ?? 'project'];
}
