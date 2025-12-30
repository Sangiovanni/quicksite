<?php
/**
 * listProjects Command
 * 
 * Lists all projects in secure/projects/ folder with metadata.
 * Can be called via API or internally from admin panel.
 * 
 * @method GET
 * @route /management/listProjects
 * @auth required
 * 
 * @return ApiResponse List of projects with metadata
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_listProjects(array $params = [], array $urlParams = []): ApiResponse {
    $projectsDir = SECURE_FOLDER_PATH . '/projects';
    
    // Check projects directory exists
    if (!is_dir($projectsDir)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No projects directory found')
            ->withData([
                'projects' => [],
                'count' => 0,
                'active_project' => null
            ]);
    }
    
    // Get active project from target.php
    $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
    $activeProject = null;
    if (file_exists($targetFile)) {
        $targetConfig = include $targetFile;
        $activeProject = $targetConfig['project'] ?? null;
    }
    
    // Scan projects directory
    $projects = [];
    $dirs = glob($projectsDir . '/*', GLOB_ONLYDIR);
    
    foreach ($dirs as $dir) {
        $projectName = basename($dir);
        $projectInfo = getProjectInfo($dir, $projectName);
        $projectInfo['is_active'] = ($projectName === $activeProject);
        $projects[] = $projectInfo;
    }
    
    // Sort: active first, then alphabetically
    usort($projects, function($a, $b) {
        if ($a['is_active'] && !$b['is_active']) return -1;
        if (!$a['is_active'] && $b['is_active']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
    
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Projects listed successfully')
        ->withData([
            'projects' => $projects,
            'count' => count($projects),
            'active_project' => $activeProject,
            'projects_path' => 'secure/projects/'
        ]);
}

/**
 * Get detailed info about a project
 * 
 * @param string $projectPath Full path to project folder
 * @param string $projectName Project folder name
 * @return array Project metadata
 */
function getProjectInfo(string $projectPath, string $projectName): array {
    $info = [
        'name' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'exists' => true,
        'has_config' => file_exists($projectPath . '/config.php'),
        'has_routes' => file_exists($projectPath . '/routes.php'),
        'has_templates' => is_dir($projectPath . '/templates'),
        'has_translations' => is_dir($projectPath . '/translate'),
        'has_public_backup' => is_dir($projectPath . '/public'),
    ];
    
    // Get config details if available
    if ($info['has_config']) {
        $config = @include($projectPath . '/config.php');
        if (is_array($config)) {
            $info['site_name'] = $config['SITE_NAME'] ?? null;
            $info['default_language'] = $config['LANGUAGE_DEFAULT'] ?? null;
            $info['languages'] = $config['LANGUAGES_SUPPORTED'] ?? [];
            $info['multilingual'] = $config['MULTILINGUAL_SUPPORT'] ?? false;
        }
    }
    
    // Get routes count
    if ($info['has_routes']) {
        $routes = @include($projectPath . '/routes.php');
        $info['routes_count'] = is_array($routes) ? count($routes) : 0;
    }
    
    // Get pages count (JSON files)
    $pagesDir = $projectPath . '/templates/model/json/pages';
    if (is_dir($pagesDir)) {
        $info['pages_count'] = count(glob($pagesDir . '/*.json'));
    }
    
    // Get translations count
    if ($info['has_translations']) {
        $langFiles = glob($projectPath . '/translate/*.json');
        $info['translation_files'] = array_map(function($f) {
            return basename($f, '.json');
        }, $langFiles);
    }
    
    // Get folder size (approximate)
    $info['size_bytes'] = getDirectorySize($projectPath);
    $info['size_human'] = formatBytes($info['size_bytes']);
    
    // Get modification time
    $info['modified'] = date('Y-m-d H:i:s', filemtime($projectPath));
    
    return $info;
}

/**
 * Calculate directory size recursively
 */
function getDirectorySize(string $path): int {
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listProjects()->send();
}