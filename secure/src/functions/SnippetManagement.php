<?php
/**
 * Snippet Management Functions
 * 
 * Helper functions for managing snippets (core and project-specific).
 * Snippets are pre-built content structures users can insert into pages.
 */

/**
 * Get path to snippets directory
 * 
 * @param string|null $projectName Project name (null for core snippets)
 * @return string Path to snippets directory
 */
function getSnippetsPath(?string $projectName = null): string {
    if ($projectName === null) {
        // Core snippets
        return SECURE_FOLDER_PATH . '/snippets';
    }
    // Project-specific snippets
    return SECURE_FOLDER_PATH . '/projects/' . $projectName . '/snippets';
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
 * @param bool $isCore Whether these are core snippets
 * @return array List of snippets with metadata
 */
function listSnippetsFromPath(string $basePath, bool $isCore = false): array {
    $snippets = [];
    
    if (!is_dir($basePath)) {
        return $snippets;
    }
    
    // Check for category subdirectories
    $categories = ['nav', 'forms', 'cards', 'layouts', 'content', 'lists'];
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
                    $snippet = loadSnippetFile($file, $isCore);
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
                    $snippet = loadSnippetFile($file, $isCore);
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
        $snippet = loadSnippetFile($file, $isCore);
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
 * @param bool $isCore Whether this is a core snippet
 * @return array|null Snippet metadata or null if invalid
 */
function loadSnippetFile(string $filePath, bool $isCore = false): ?array {
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
        'isCore' => $isCore,
        'hasTranslations' => isset($data['translations']) && !empty($data['translations']),
        'hasCss' => isset($data['css']) && !empty($data['css']),
        'file' => basename($filePath)
    ];
}

/**
 * Get full snippet data by ID
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
    
    // First check project snippets
    if ($projectName) {
        $projectSnippetsPath = getSnippetsPath($projectName);
        $snippet = findSnippetInPath($snippetId, $projectSnippetsPath, false);
        if ($snippet !== null) {
            return $snippet;
        }
    }
    
    // Then check core snippets
    $coreSnippetsPath = getSnippetsPath(null);
    return findSnippetInPath($snippetId, $coreSnippetsPath, true);
}

/**
 * Find snippet by ID in a directory (searches categories)
 * 
 * @param string $snippetId Snippet ID to find
 * @param string $basePath Base path to search
 * @param bool $isCore Whether these are core snippets
 * @return array|null Full snippet data or null if not found
 */
function findSnippetInPath(string $snippetId, string $basePath, bool $isCore = false): ?array {
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
                $snippet = loadFullSnippet($file, $snippetId, $isCore);
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
                $snippet = loadFullSnippet($file, $snippetId, $isCore);
                if ($snippet !== null) {
                    return $snippet;
                }
            }
        }
    }
    
    // Check root level
    $rootFiles = glob($basePath . '/*.json');
    foreach ($rootFiles as $file) {
        $snippet = loadFullSnippet($file, $snippetId, $isCore);
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
 * @param bool $isCore Whether this is a core snippet
 * @return array|null Full snippet data or null if ID doesn't match
 */
function loadFullSnippet(string $filePath, string $snippetId, bool $isCore = false): ?array {
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);
    
    if (!is_array($data) || !isset($data['id'])) {
        return null;
    }
    
    if ($data['id'] !== $snippetId) {
        return null;
    }
    
    // Add isCore flag
    $data['isCore'] = $isCore;
    $data['_filePath'] = $filePath;
    
    return $data;
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
 * Save snippet to project snippets folder
 * 
 * @param array $snippetData Snippet data to save
 * @param string $projectName Project name
 * @return array ['success' => bool, 'path' => string, 'error' => string|null]
 */
function saveProjectSnippet(array $snippetData, string $projectName): array {
    // Ensure project snippets directory exists
    if (!ensureProjectSnippetsDir($projectName)) {
        return ['success' => false, 'path' => '', 'error' => 'Failed to create snippets directory'];
    }
    
    $category = $snippetData['category'] ?? 'other';
    $snippetId = $snippetData['id'] ?? null;
    
    if (!$snippetId) {
        return ['success' => false, 'path' => '', 'error' => 'Snippet ID is required'];
    }
    
    // Create category directory if needed
    $categoryPath = getSnippetsPath($projectName) . '/' . $category;
    if (!is_dir($categoryPath)) {
        mkdir($categoryPath, 0755, true);
    }
    
    // Remove isCore flag if present (user snippets are never core)
    unset($snippetData['isCore']);
    unset($snippetData['_filePath']);
    
    $filePath = $categoryPath . '/' . $snippetId . '.json';
    $json = json_encode($snippetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($filePath, $json) === false) {
        return ['success' => false, 'path' => '', 'error' => 'Failed to write snippet file'];
    }
    
    return ['success' => true, 'path' => $filePath, 'error' => null];
}

/**
 * Delete project snippet by ID
 * 
 * @param string $snippetId Snippet ID
 * @param string $projectName Project name
 * @return array ['success' => bool, 'error' => string|null]
 */
function deleteProjectSnippet(string $snippetId, string $projectName): array {
    $projectSnippetsPath = getSnippetsPath($projectName);
    $snippet = findSnippetInPath($snippetId, $projectSnippetsPath, false);
    
    if ($snippet === null) {
        return ['success' => false, 'error' => 'Snippet not found in project'];
    }
    
    $filePath = $snippet['_filePath'] ?? null;
    
    if (!$filePath || !file_exists($filePath)) {
        return ['success' => false, 'error' => 'Snippet file not found'];
    }
    
    if (!unlink($filePath)) {
        return ['success' => false, 'error' => 'Failed to delete snippet file'];
    }
    
    return ['success' => true, 'error' => null];
}
