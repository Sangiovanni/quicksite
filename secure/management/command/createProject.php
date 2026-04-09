<?php
/**
 * createProject Command
 * 
 * Creates a new empty project with basic structure.
 * Can be called via API or internally from admin panel.
 * 
 * @method POST
 * @route /management/createProject
 * @auth required (admin permission)
 * 
 * @param string $name Project name (required)
 * @param string $site_name Display name for the site (optional)
 * @param string $language Default language code (optional, default: en)
 * @param bool $switch_to Switch to this project after creation (optional, default: false)
 * 
 * @return ApiResponse Creation result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_createProject(array $params = [], array $urlParams = []): ApiResponse {
    // Validate project name
    $projectName = trim($params['name'] ?? '');
    
    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Validate project name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', $projectName)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid project name format')
            ->withErrors(['name' => 'Must start with letter, contain only alphanumeric/dash/underscore, max 50 chars']);
    }
    
    // Reserved names
    $reserved = ['admin', 'management', 'src', 'logs', 'config', 'projects'];
    if (in_array(strtolower($projectName), $reserved)) {
        return ApiResponse::create(400, 'validation.reserved_name')
            ->withMessage("Project name '$projectName' is reserved")
            ->withErrors(['name' => 'This name is reserved for system use']);
    }
    
    // Optional parameters
    $siteName = trim($params['site_name'] ?? ucfirst($projectName));
    $defaultLang = trim($params['language'] ?? 'en');
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Validate language code
    if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLang)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid language code format')
            ->withErrors(['language' => 'Use ISO format: en, fr, de, en-US, etc.']);
    }
    
    // Check project doesn't already exist
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;
    
    if (is_dir($projectPath)) {
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("Project '$projectName' already exists")
            ->withData(['existing_path' => 'secure/projects/' . $projectName]);
    }
    
    // Create project structure
    $folders = [
        '',
        '/config',
        '/templates',
        '/templates/pages',
        '/templates/model',
        '/templates/model/json',
        '/templates/model/json/pages',
        '/templates/model/json/components',
        '/translate',
        '/data',
        '/public',
        '/public/assets',
        '/public/assets/images',
        '/public/assets/font',
        '/public/assets/audio',
        '/public/assets/videos',
        '/public/style',
        '/public/build'
    ];
    
    foreach ($folders as $folder) {
        $path = $projectPath . $folder;
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            return ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage('Failed to create project structure')
                ->withData(['failed_path' => $folder]);
        }
    }

    // Create index.php for directory listing protection in all asset folders
    $indexPhpContent = "<?php\n\nif (!defined('BASE_URL')) {\n    \$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off' || \$_SERVER['SERVER_PORT'] == 443) ? \"https://\" : \"http://\";\n    \$host = \$_SERVER['HTTP_HOST'];\n    define('BASE_URL', \$protocol . \$host);\n}\nheader(\"Location: \".BASE_URL);\n";
    $assetDirs = ['images', 'font', 'audio', 'videos'];
    foreach ($assetDirs as $dir) {
        file_put_contents($projectPath . '/public/assets/' . $dir . '/index.php', $indexPhpContent, LOCK_EX);
    }
    
    // Create config.php
    $configContent = createProjectConfig($siteName, $defaultLang);
    if (file_put_contents($projectPath . '/config.php', $configContent, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to create config.php');
    }
    
    // Create routes.php with home route (associative array format)
    $routesContent = "<?php\n/**\n * Route definitions for $siteName\n * Created: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'home' => [],\n];\n";
    if (file_put_contents($projectPath . '/routes.php', $routesContent, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to create routes.php');
    }
    
    // Create empty aliases.json
    file_put_contents($projectPath . '/data/aliases.json', '{}', LOCK_EX);
    
    // Create empty assets_metadata.json
    file_put_contents($projectPath . '/data/assets_metadata.json', '{}', LOCK_EX);
    
    // Create default iframe sandbox config (empty = strictest)
    $defaultSandbox = json_encode(['tags' => ['iframe' => (object)[]], 'default' => ''], JSON_PRETTY_PRINT);
    file_put_contents($projectPath . '/data/iframe_sandbox.json', $defaultSandbox, LOCK_EX);
    
    // Create default translation file
    $defaultTranslations = createDefaultTranslations($siteName);
    file_put_contents($projectPath . '/translate/default.json', json_encode($defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents($projectPath . '/translate/' . $defaultLang . '.json', json_encode($defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // Create basic page templates
    createBasicPageTemplates($projectPath);
    
    // Create menu.json and footer.json
    createMenuAndFooter($projectPath, $siteName);
    
    // Create .htaccess with space-aware FallbackResource + security headers
    $fallbackPath = PUBLIC_FOLDER_SPACE !== '' ? '/' . trim(PUBLIC_FOLDER_SPACE, '/') . '/index.php' : '/index.php';
    $htaccess = <<<HTACCESS
RewriteEngine On
FallbackResource $fallbackPath

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
HTACCESS;
    file_put_contents($projectPath . '/public/.htaccess', $htaccess . "\n", LOCK_EX);
    
    // Create basic style.css
    createBasicStyles($projectPath);
    
    $result = [
        'project' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'site_name' => $siteName,
        'default_language' => $defaultLang,
        'created' => true,
        'switched_to' => false
    ];
    
    // Switch to project if requested
    if ($switchTo) {
        // Sync live public files back to the previous project before switching
        // This prevents CSS/asset loss when creating multiple projects in sequence
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $prevTarget = include $targetFile;
            $prevName = is_array($prevTarget) ? ($prevTarget['project'] ?? null) : $prevTarget;
            if ($prevName && $prevName !== $projectName) {
                $prevProjectPath = SECURE_FOLDER_PATH . '/projects/' . $prevName;
                if (is_dir($prevProjectPath)) {
                    $prevPublicPath = $prevProjectPath . '/public';
                    if (!is_dir($prevPublicPath)) {
                        mkdir($prevPublicPath, 0755, true);
                    }
                    foreach (['style', 'assets'] as $folder) {
                        $liveSrc = PUBLIC_CONTENT_PATH . '/' . $folder;
                        $projDst = $prevPublicPath . '/' . $folder;
                        if (is_dir($liveSrc)) {
                            // Remove old and copy fresh from live
                            if (is_dir($projDst)) {
                                $di = new RecursiveDirectoryIterator($projDst, FilesystemIterator::SKIP_DOTS);
                                $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
                                foreach ($ri as $file) {
                                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                                }
                                rmdir($projDst);
                            }
                            // Copy live folder to project
                            $si = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($liveSrc, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::SELF_FIRST
                            );
                            mkdir($projDst, 0755, true);
                            foreach ($si as $item) {
                                $dest = $projDst . '/' . $si->getSubPathname();
                                $item->isDir() ? (is_dir($dest) || mkdir($dest, 0755, true)) : copy($item->getPathname(), $dest);
                            }
                        }
                    }
                    $result['previous_project_synced'] = $prevName;
                }
            }
        }

        $targetContent = "<?php\n/**\n * Active Project Target Configuration\n * Updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'project' => '" . addslashes($projectName) . "'\n];\n";
        
        // Use fopen/fwrite/fflush for explicit sync
        $handle = fopen($targetFile, 'w');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $bytesWritten = fwrite($handle, $targetContent);
                fflush($handle);
                flock($handle, LOCK_UN);
                if ($bytesWritten === strlen($targetContent)) {
                    $result['switched_to'] = true;
                }
            }
            fclose($handle);
            
            // Clear all caches to ensure next request sees new file
            clearstatcache(true, $targetFile);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($targetFile, true);
            }
        }
    }
    
    return ApiResponse::create(201, 'resource.created')
        ->withMessage("Project '$projectName' created successfully")
        ->withData($result);
}

/**
 * Generate config.php content
 */
function createProjectConfig(string $siteName, string $defaultLang): string {
    // Get display name for default language
    $commonLanguages = [
        'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch',
        'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'ru' => 'Русский',
        'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어', 'ar' => 'العربية'
    ];
    $defaultLangName = $commonLanguages[$defaultLang] ?? ucfirst($defaultLang);
    
    return "<?php\n/**\n * Site Configuration\n * Created: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n    'SITE_NAME' => '" . addslashes($siteName) . "',\n    'LANGUAGE_DEFAULT' => '$defaultLang',\n    'LANGUAGES_SUPPORTED' => ['$defaultLang'],\n    'LANGUAGES_NAME' => ['$defaultLang' => '$defaultLangName'],\n    'MULTILINGUAL_SUPPORT' => false\n];\n";
}

/**
 * Generate default translations
 */
function createDefaultTranslations(string $siteName): array {
    return [
        'site' => [
            'name' => $siteName,
            'tagline' => 'Welcome to ' . $siteName
        ],
        'menu' => [
            'home' => 'Home'
        ],
        'page' => [
            'titles' => [
                'home' => $siteName . ' - Home',
                '404' => 'Page Not Found'
            ]
        ],
        'footer' => [
            'copyright' => '© ' . date('Y') . ' ' . $siteName
        ],
        '404' => [
            'title' => 'Page Not Found',
            'message' => 'The page you are looking for does not exist.',
            'backHome' => 'Back to Home'
        ]
    ];
}

/**
 * Create basic page templates
 * Uses folder structure: pages/home/home.php, pages/404/404.php
 */
function createBasicPageTemplates(string $projectPath): void {
    // Create directories for folder structure
    @mkdir($projectPath . '/templates/pages/home', 0755, true);
    @mkdir($projectPath . '/templates/pages/404', 0755, true);
    @mkdir($projectPath . '/templates/model/json/pages/home', 0755, true);
    @mkdir($projectPath . '/templates/model/json/pages/404', 0755, true);
    
    // home.php - use centralized generate_page_template()
    file_put_contents($projectPath . '/templates/pages/home/home.php', generate_page_template('home'), LOCK_EX);
    
    // 404.php - use centralized generate_page_template()
    file_put_contents($projectPath . '/templates/pages/404/404.php', generate_page_template('404'), LOCK_EX);
    
    // home.json — use generate_page_json for consistent minimal root
    file_put_contents($projectPath . '/templates/model/json/pages/home/home.json', generate_page_json('home'), LOCK_EX);
    
    // 404.json — use generate_page_json for consistent minimal root
    file_put_contents($projectPath . '/templates/model/json/pages/404/404.json', generate_page_json('404'), LOCK_EX);
}

/**
 * Create menu.json and footer.json
 */
function createMenuAndFooter(string $projectPath, string $siteName): void {
    // menu.json — minimal root, content added by user/workflows
    $menuJson = [
        ['tag' => 'nav', 'params' => ['class' => 'main-nav'], 'children' => []]
    ];
    file_put_contents($projectPath . '/templates/model/json/menu.json', json_encode($menuJson, JSON_PRETTY_PRINT), LOCK_EX);
    
    // footer.json — minimal root, content added by user/workflows
    $footerJson = [
        ['tag' => 'footer', 'params' => ['class' => 'main-footer'], 'children' => []]
    ];
    file_put_contents($projectPath . '/templates/model/json/footer.json', json_encode($footerJson, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Note: No menu.php or footer.php needed - PageManagement renders JSON directly
}

/**
 * Create basic stylesheet
 */
function createBasicStyles(string $projectPath): void {
    $css = ":root {\n    --primary-color: #3498db;\n    --text-color: #333;\n    --bg-color: #fff;\n}\n\n* {\n    margin: 0;\n    padding: 0;\n    box-sizing: border-box;\n}\n\nbody {\n    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;\n    color: var(--text-color);\n    background: var(--bg-color);\n    line-height: 1.6;\n}\n\n.hero {\n    text-align: center;\n    padding: 4rem 2rem;\n}\n\n.hero h1 {\n    font-size: 2.5rem;\n    margin-bottom: 1rem;\n}\n\n.main-nav {\n    display: flex;\n    justify-content: space-between;\n    align-items: center;\n    padding: 1rem 2rem;\n    background: var(--primary-color);\n    color: white;\n}\n\n.main-nav a {\n    color: white;\n    text-decoration: none;\n}\n\n.nav-links {\n    display: flex;\n    list-style: none;\n    gap: 1rem;\n}\n\nfooter {\n    text-align: center;\n    padding: 2rem;\n    background: #f5f5f5;\n}\n\n.error-page {\n    text-align: center;\n    padding: 4rem 2rem;\n}\n";
    file_put_contents($projectPath . '/public/style/style.css', $css, LOCK_EX);
    
    // index.php for style folder
    file_put_contents($projectPath . '/public/style/index.php', "<?php\n// Directory listing disabled\nhttp_response_code(403);\n", LOCK_EX);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_createProject($trimParams->params(), $trimParams->additionalParams())->send();
}