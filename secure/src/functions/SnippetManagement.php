<?php
require_once __DIR__ . '/utilsManagement.php'; // qs_json_write
/**
 * Snippet Management Functions
 *
 * Helper functions for managing snippets (core, personal, and project-specific).
 * Snippets are pre-built content structures users can insert into pages.
 *
 * Three-tier snippet scope:
 *   Core     = shipped, read-only (secure/snippets/core/)
 *   Personal = user-created, shared across THAT USER'S projects
 *              (secure/snippets/custom/{userId}/)
 *   Project  = user-created, project-only (secure/projects/{proj}/snippets/)
 *
 * The middle tier used to be called GLOBAL and lived in one flat
 * secure/snippets/custom/ with no owner in the path. Every read and every delete
 * reached it from ANY project marker, while every other write in the same
 * commands is marker-bound (C8 8.5). Proven live in beta.10 C13 13.6b: a member
 * of project A wrote a snippet there, and a member of project B — sharing no
 * project with A, refused 403 on A's own marker — listed it, read its full
 * structure, inserted A's content into project B's page, and deleted A's file.
 * The tier is per-USER now, which is what "available to all projects" was always
 * meant to say: all of MINE.
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
 * Root of the personal-snippet tier. Holds one directory per user; it is NEVER
 * a read or write target itself.
 *
 * @return string Path to the custom snippets root
 */
function getCustomSnippetsRoot(): string {
    return SECURE_FOLDER_PATH . '/snippets/custom';
}

/**
 * Path to ONE user's personal snippet directory.
 *
 * @param string|null $userId Defaults to the authenticated caller.
 * @return string|null null when there is no caller to attribute the snippets to,
 *                     or when the id is not a well-formed user id. Callers treat
 *                     null as "this tier does not exist for you" and skip it —
 *                     fail closed, because this value becomes a path segment.
 */
/**
 * Snippets left in the FLAT pre-13.6b layout (secure/snippets/custom/*.json or
 * custom/<category>/*.json, i.e. not under a usr_ directory).
 *
 * They are no longer served: nothing in the file records who wrote them, so
 * there is no user to attribute them to, and continuing to serve them to
 * everybody is the defect itself. They are NOT deleted either — the bytes stay
 * on disk and this logs them once per call site so an operator can move them
 * into the right secure/snippets/custom/<userId>/ folder. A fresh install ships
 * none (only .gitkeep is tracked), so on most installations this returns [].
 *
 * @return string[] absolute paths of orphaned legacy snippet files
 */
function findLegacyFlatSnippets(): array {
    $root = getCustomSnippetsRoot();
    if (!is_dir($root)) {
        return [];
    }
    $found = array_merge(glob($root . '/*.json') ?: [], glob($root . '/*/*.json') ?: []);
    // Anything directly under a usr_ directory belongs to that user, not to the
    // legacy tier — the second glob above would otherwise sweep it up.
    return array_values(array_filter($found, static function (string $p): bool {
        return preg_match('#/usr_[a-f0-9]{32}/[^/]+\.json$#', str_replace('\\', '/', $p)) !== 1;
    }));
}

/**
 * Log the orphaned legacy snippets, at most once per request.
 *
 * The guard is not cosmetic: this runs from listSnippets, which the editor calls
 * on every snippet listing, so without it an affected installation writes its
 * whole orphan list to the error log on every read. A diagnostic that repeats
 * that often stops being read, which is the opposite of what it is for.
 */
function warnAboutLegacyFlatSnippets(): void {
    static $alreadyWarned = false;
    if ($alreadyWarned) {
        return;
    }
    $legacy = findLegacyFlatSnippets();
    if ($legacy === []) {
        return;
    }
    $alreadyWarned = true;
    error_log('QuickSite [snippets]: ' . count($legacy) . ' snippet(s) remain in the older flat '
        . 'secure/snippets/custom/ layout and are no longer served (no owner recorded). Move each into '
        . 'secure/snippets/custom/<userId>/<category>/ to restore it: ' . implode(', ', $legacy));
}

function getPersonalSnippetsPath(?string $userId = null): ?string {
    if ($userId === null) {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
    }
    // The id is minted as 'usr_' . bin2hex(random_bytes(16)). Pin that shape
    // rather than sanitising: this string is concatenated into a filesystem
    // path, and an allowlist is the only form of that check that cannot be
    // out-thought (C3/C11).
    if (!is_string($userId) || preg_match('/^usr_[a-f0-9]{32}$/', $userId) !== 1) {
        return null;
    }

    return getCustomSnippetsRoot() . '/' . $userId;
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
 * Searches: project → personal (caller's own) → core (most specific first)
 *
 * @param string $snippetId Snippet ID
 * @param string|null $projectName Project name. null = search personal + core only.
 *                    C15 15.3: there is no installation-wide project to fall back to, and
 *                    guessing one let a caller authorized on project A read project B's
 *                    snippets (flagged in C8 8.5 as a cross-project leak vector). All four
 *                    callers pass the marker project explicitly.
 * @return array|null Full snippet data or null if not found
 */
function getSnippetById(string $snippetId, ?string $projectName = null): ?array {
    // 1. Check project snippets first
    if ($projectName) {
        $projectSnippetsPath = getProjectSnippetsPath($projectName);
        $snippet = findSnippetInPath($snippetId, $projectSnippetsPath, 'project');
        if ($snippet !== null) {
            return $snippet;
        }
    }

    // 2. Check the CALLER'S OWN personal snippets — never another user's.
    $personalPath = getPersonalSnippetsPath();
    if ($personalPath !== null) {
        $snippet = findSnippetInPath($snippetId, $personalPath, 'personal');
        if ($snippet !== null) {
            return $snippet;
        }
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
 * Scans the structure tree for all classes and IDs via the shared
 * extractCssSelectorsFromStructure() helper, then queries the project stylesheet.
 * Tags are intentionally excluded — they match too broadly and pull in
 * unrelated rules (e.g. a bare `p` tag would match `.feature-card p`).
 * 
 * @param array $structure Snippet structure (single node or array of nodes)
 * @param string $projectName Project name (for stylesheet path)
 * @return array ['selectors' => ['classes' => [...], 'ids' => [...]], 'css' => string]
 */
function extractSnippetCss(array $structure, string $projectName): array {
    require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
    require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php'; // extractCssSelectorsFromStructure
    require_once SECURE_FOLDER_PATH . '/src/functions/componentPolicy.php';      // qs_resolve_component_path

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

    // Load CSS from live public stylesheet first (source of truth),
    // then fallback to project copy if live file is unavailable.
    $liveStylesheetPath = PUBLIC_CONTENT_PATH . '/style/style.css';
    $projectStylesheetPath = SECURE_FOLDER_PATH . '/projects/' . $projectName . '/public/style/style.css';

    if (file_exists($liveStylesheetPath)) {
        $stylesheetPath = $liveStylesheetPath;
    } else if (file_exists($projectStylesheetPath)) {
        $stylesheetPath = $projectStylesheetPath;
    } else {
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
 * @param string $scope Save scope: "project" (default) or "personal"
 * @return array ['success' => bool, 'path' => string, 'error' => string|null]
 */
function saveProjectSnippet(array $snippetData, string $projectName, string $scope = 'project'): array {
    $category = $snippetData['category'] ?? 'other';
    $snippetId = $snippetData['id'] ?? null;

    if (!$snippetId) {
        return ['success' => false, 'path' => '', 'error' => 'Snippet ID is required'];
    }

    // Determine base path based on scope
    if ($scope === 'personal') {
        $basePath = getPersonalSnippetsPath();
        if ($basePath === null) {
            return ['success' => false, 'path' => '',
                    'error' => 'Personal snippets need an identified caller'];
        }
        if (!is_dir($basePath) && !@mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            return ['success' => false, 'path' => '', 'error' => 'Failed to create the personal snippets directory'];
        }
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

    if (!qs_json_write($filePath, $snippetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) {
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
        // Then the CALLER'S OWN personal snippets. This is the row that mattered
        // most: on the flat layout a member of any project could delete a
        // snippet any other user had authored.
        $personalPath = getPersonalSnippetsPath();
        if ($personalPath !== null) {
            $snippet = findSnippetInPath($snippetId, $personalPath, 'personal');
        }
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
