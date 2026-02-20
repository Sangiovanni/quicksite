<?php
/**
 * checkStructureMulti - Audit structures for multilingual-specific content
 * 
 * @method GET
 * @url /management/checkStructureMulti
 * @auth required
 * @permission read
 * 
 * Scans all JSON structures (pages, menu, footer, components) for patterns
 * that would break in mono-language mode, such as lang= in href attributes.
 * 
 * Use this before switching to mono-language mode with setMultilingual.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Recursively scan a structure for lang-specific patterns
 */
function scanForLangPatterns_check(array $structure, string $source, string $path = ''): array {
    $findings = [];
    
    foreach ($structure as $key => $value) {
        $currentPath = $path ? "{$path}.{$key}" : (string)$key;
        
        if (is_array($value)) {
            // Recurse into nested structures
            $findings = array_merge($findings, scanForLangPatterns_check($value, $source, $currentPath));
        } elseif (is_string($value)) {
            // Check for lang= patterns
            $patterns = [
                '/lang=[a-z]{2,3}/i' => 'lang= parameter',
                '/\{\{__current_page;lang=[a-z]{2,3}\}\}/i' => '{{__current_page;lang=XX}} placeholder',
                '/\?lang=[a-z]{2,3}/i' => '?lang= query parameter',
                '/&lang=[a-z]{2,3}/i' => '&lang= query parameter',
                '/\/[a-z]{2}\//' => 'potential /XX/ language path segment'
            ];
            
            foreach ($patterns as $pattern => $description) {
                if (preg_match($pattern, $value, $matches)) {
                    // Skip false positives for common words
                    if ($description === 'potential /XX/ language path segment') {
                        // Only flag if it looks like a language code at start of path
                        if (!preg_match('/^\/[a-z]{2}\//', $value)) {
                            continue;
                        }
                    }
                    
                    $findings[] = [
                        'source' => $source,
                        'path' => $currentPath,
                        'pattern' => $description,
                        'match' => $matches[0],
                        'value' => strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value
                    ];
                }
            }
        }
    }
    
    return $findings;
}

/**
 * Scan a JSON file for lang patterns
 */
function scanJsonFile_check(string $filePath, string $sourceName): array {
    if (!file_exists($filePath)) {
        return [];
    }
    
    $content = file_get_contents($filePath);
    $structure = json_decode($content, true);
    
    if ($structure === null) {
        return [];
    }
    
    return scanForLangPatterns_check($structure, $sourceName);
}

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_checkStructureMulti(array $params = [], array $urlParams = []): ApiResponse {
    $allFindings = [];
    $scannedFiles = [];

    // Scan pages
    $pagesDir = PROJECT_PATH . '/templates/model/json/pages';
    if (is_dir($pagesDir)) {
        $pageFiles = glob($pagesDir . '/*.json');
        foreach ($pageFiles as $pageFile) {
            $pageName = basename($pageFile, '.json');
            $findings = scanJsonFile_check($pageFile, "page:{$pageName}");
            $allFindings = array_merge($allFindings, $findings);
            $scannedFiles['pages'][] = $pageName;
        }
    }

    // Scan menu
    $menuPath = PROJECT_PATH . '/templates/model/json/menu.json';
    if (file_exists($menuPath)) {
        $findings = scanJsonFile_check($menuPath, 'menu');
        $allFindings = array_merge($allFindings, $findings);
        $scannedFiles['menu'] = true;
    }

    // Scan footer
    $footerPath = PROJECT_PATH . '/templates/model/json/footer.json';
    if (file_exists($footerPath)) {
        $findings = scanJsonFile_check($footerPath, 'footer');
        $allFindings = array_merge($allFindings, $findings);
        $scannedFiles['footer'] = true;
    }

    // Scan components
    $componentsDir = PROJECT_PATH . '/templates/model/json/components';
    if (is_dir($componentsDir)) {
        $componentFiles = glob($componentsDir . '/*.json');
        foreach ($componentFiles as $componentFile) {
            $componentName = basename($componentFile, '.json');
            $findings = scanJsonFile_check($componentFile, "component:{$componentName}");
            $allFindings = array_merge($allFindings, $findings);
            $scannedFiles['components'][] = $componentName;
        }
    }

    // Group findings by source
    $groupedFindings = [];
    foreach ($allFindings as $finding) {
        $source = $finding['source'];
        if (!isset($groupedFindings[$source])) {
            $groupedFindings[$source] = [];
        }
        $groupedFindings[$source][] = [
            'path' => $finding['path'],
            'pattern' => $finding['pattern'],
            'match' => $finding['match'],
            'value' => $finding['value']
        ];
    }

    // Determine status
    $totalFindings = count($allFindings);
    $status = $totalFindings === 0 ? 'clean' : 'has_multilingual_content';
    $message = $totalFindings === 0 
        ? 'No multilingual-specific content found. Safe to switch to mono-language mode.'
        : "Found {$totalFindings} multilingual-specific pattern(s). Review before switching to mono-language mode.";

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($message)
        ->withData([
            'status' => $status,
            'total_findings' => $totalFindings,
            'findings_by_source' => $groupedFindings,
            'affected_sources' => array_keys($groupedFindings),
            'scanned' => $scannedFiles,
            'recommendation' => $totalFindings > 0 
                ? 'Remove or update lang-specific content before switching to mono-language mode, or these links may break.'
                : 'You can safely use setMultilingual to switch to mono-language mode.'
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_checkStructureMulti()->send();
}
