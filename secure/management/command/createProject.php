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
 * @param bool $switch_to Make the new project the CREATOR's editing target
 *                        (their per-user selected_project) after creation
 *                        (optional, default: false). Never changes the served
 *                        project served anywhere else.
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
    $siteName = mb_substr(trim($params['site_name'] ?? ucfirst($projectName)), 0, 200);
    // Cap length + strip control bytes (\x00-\x1F, \x7F). Byte-wise strip is
    // UTF-8-safe: control bytes never occur inside a multibyte sequence. Display
    // titles keep spaces / punctuation / accents — no strict format enforced.
    $siteName = preg_replace('/[\x00-\x1F\x7F]/', '', $siteName);
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
    $routesContent = "<?php\n/**\n * Route definitions (auto-generated)\n * Created: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . varExportNested(['home' => []]) . ";\n";
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

    // --- Membership (C5): the creator becomes the project's sole owner ---
    // No project may exist without a members.json (L9). Requires AuthManagement.
    if (!function_exists('getCurrentUser')) {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    }
    $creator   = getCurrentUser();
    $creatorId = $creator['id'] ?? null;

    // Birth-write the trust file via the single canonical path (C8 8.4) — creator
    // as sole owner. A create with no resolvable owner is invalid (an ownerless,
    // inaccessible project); fail loudly rather than mint one.
    if (!qs_project_birth_write_members($projectPath, $creatorId)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to initialise project membership');
    }

    // Update the creator's derived project index (users.php) — cache only, NO
    // role key (role is authoritative in members.json — L5/C5). With switch_to,
    // ONLY the creator's selected_project (their per-user EDITING target) moves
    // to the new project — a command never repoints what a deployment serves;
    // the site root keeps serving the fixed main and the new project is edited
    // at /p/<id>/ (C9 fixed-main model).
    $selectedProjectSet = false;
    if ($creatorId !== null) {
        $written = qs_users_mutate(function (array &$cfg) use ($creatorId, $projectName, $siteName, $switchTo) {
            if (!isset($cfg['users'][$creatorId])) {
                return false;
            }
            $cfg['users'][$creatorId]['projects'][$projectName] = [
                'name'    => $siteName,
                'created' => date('Y-m-d'),
            ];
            if ($switchTo) {
                $cfg['users'][$creatorId]['selected_project'] = $projectName;
            }
            return true;
        });
        $selectedProjectSet = ($written === true && $switchTo);
    }

    $result = [
        'project' => $projectName,
        'path' => 'secure/projects/' . $projectName,
        'site_name' => $siteName,
        'default_language' => $defaultLang,
        'owner_user_id' => $creatorId,
        'created' => true,
        'switched_to' => $selectedProjectSet
    ];

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