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
 * @param string $language The project's first language: a code from the
 *                         installation's language list (optional, default: the
 *                         installation's default language, qs_language_default())
 * @param bool $switch_to Make the new project the CREATOR's editing target
 *                        (their per-user selected_project) after creation
 *                        (optional, default: false). Never changes the served
 *                        project served anywhere else.
 *
 * @return ApiResponse Creation result
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectSettings.php';
require_once SECURE_FOLDER_PATH . '/src/functions/FileSystem.php'; // qs_delete_tree
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php'; // qs_invalidate_space_cache

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_createProject(array $params = [], array $urlParams = []): ApiResponse {
    // Validate project name
    // qs_param_string: `?name[]=x` would reach trim() as a TypeError.
    $projectName = trim(qs_param_string($params, 'name', ''));
    
    if (empty($projectName)) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('Project name is required')
            ->withErrors(['name' => 'Required field']);
    }
    
    // Validate project name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/D', $projectName)) {
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
    
    // Optional parameters: absent (or null) takes the default; any other value
    // must be a string (an array reached trim() as a TypeError).
    foreach (['site_name', 'language'] as $field) {
        if (isset($params[$field]) && !is_string($params[$field])) {
            return ApiResponse::create(400, 'validation.invalid_type')
                ->withMessage("The {$field} parameter must be a string.")
                ->withErrors([['field' => $field, 'reason' => 'invalid_type', 'expected' => 'string']]);
        }
    }
    $siteName = mb_substr(trim(qs_param_string($params, 'site_name', ucfirst($projectName))), 0, 200);
    // Cap length + strip control bytes (\x00-\x1F, \x7F). Byte-wise strip is
    // UTF-8-safe: control bytes never occur inside a multibyte sequence. Display
    // titles keep spaces / punctuation / accents — no strict format enforced.
    $siteName = preg_replace('/[\x00-\x1F\x7F]/', '', $siteName);
    $defaultLang = trim(qs_param_string($params, 'language') ?? qs_language_default());
    $switchTo = filter_var($params['switch_to'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // The project's first language is a NEW language: a code from the
    // installation's language list.
    if (!qs_language_is_listed($defaultLang)) {
        return qs_language_not_listed_response($defaultLang, 'language');
    }

    $config = [
        'SITE_NAME' => $siteName,
        'LANGUAGE_DEFAULT' => $defaultLang,
        'LANGUAGES_SUPPORTED' => [$defaultLang],
        'MULTILINGUAL_SUPPORT' => false,
    ];
    $refusal = qs_project_settings_guard($config, array_keys($config));
    if ($refusal !== null) {
        return $refusal;
    }

    // Check project doesn't already exist
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $projectName;

    if (is_dir($projectPath)) {
        return ApiResponse::create(409, 'resource.already_exists')
            ->withMessage("Project '$projectName' already exists")
            ->withData(['existing_path' => SECURE_FOLDER_NAME . '/projects/' . $projectName]);
    }

    // The storage quota: a new project is the caller's, and an owner already over
    // the ceiling cannot start another. Checked before anything is written.
    if (qs_quota_storage_limited()) {
        $breach = qs_quota_check_storage((string)(getCurrentUser()['id'] ?? ''), 0, null, ['kind' => 'create']);
        if ($breach !== null) {
            return ApiResponse::create(507, 'quota.storage_exceeded')
                ->withMessage($breach['message'])
                ->withData($breach['data']);
        }
    }

    // The project root is created exclusively, so two creates of one name cannot
    // share a folder, and every failure below removes a folder this request made:
    // a create that answers an error leaves nothing behind.
    if (!@mkdir($projectPath, 0755)) {
        return is_dir($projectPath)
            ? ApiResponse::create(409, 'resource.already_exists')
                ->withMessage("Project '$projectName' already exists")
                ->withData(['existing_path' => SECURE_FOLDER_NAME . '/projects/' . $projectName])
            : ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage('Failed to create project structure')
                ->withData(['failed_path' => '']);
    }
    $fail = static function (ApiResponse $response) use ($projectPath): ApiResponse {
        qs_delete_tree($projectPath);
        return $response;
    };
    // A measurement left by an earlier project of the same name is not this one's.
    qs_invalidate_space_cache($projectName);

    // Create project structure
    $folders = [
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
        '/public/style'
    ];
    
    foreach ($folders as $folder) {
        $path = $projectPath . $folder;
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            return $fail(ApiResponse::create(500, 'server.directory_create_failed')
                ->withMessage('Failed to create project structure')
                ->withData(['failed_path' => $folder]));
        }
    }

    // Create index.php for directory listing protection in all asset folders
    $indexPhpContent = "<?php\n\nif (!defined('BASE_URL')) {\n    \$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off' || \$_SERVER['SERVER_PORT'] == 443) ? \"https://\" : \"http://\";\n    \$host = \$_SERVER['HTTP_HOST'];\n    define('BASE_URL', \$protocol . \$host);\n}\nheader(\"Location: \".BASE_URL);\n";
    $assetDirs = ['images', 'font', 'audio', 'videos'];
    foreach ($assetDirs as $dir) {
        file_put_contents($projectPath . '/public/assets/' . $dir . '/index.php', $indexPhpContent, LOCK_EX);
    }
    
    // Create config.php
    $configContent = createProjectConfig($config);
    if (file_put_contents($projectPath . '/config.php', $configContent, LOCK_EX) === false) {
        return $fail(ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to create config.php'));
    }

    // Create routes.php with home route (associative array format)
    $routesContent = "<?php\n/**\n * Route definitions (auto-generated)\n * Created: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . varExportNested(['home' => []]) . ";\n";
    if (file_put_contents($projectPath . '/routes.php', $routesContent, LOCK_EX) === false) {
        return $fail(ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to create routes.php'));
    }
    
    // Create empty aliases.json
    file_put_contents($projectPath . '/data/aliases.json', '{}', LOCK_EX);
    
    // Create empty assets_metadata.json
    file_put_contents($projectPath . '/data/assets_metadata.json', '{}', LOCK_EX);

    // Create default translation file
    $defaultTranslations = createDefaultTranslations($siteName);
    qs_json_write($projectPath . '/translate/default.json', $defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, LOCK_EX);
    qs_json_write($projectPath . '/translate/' . $defaultLang . '.json', $defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, LOCK_EX);
    
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
    
    // An empty style.css
    createEmptyStylesheet($projectPath);

    // --- Membership: the creator becomes the project's sole owner ---
    // No project may exist without a members.json. Requires AuthManagement.
    if (!function_exists('getCurrentUser')) {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    }
    $creator   = getCurrentUser();
    $creatorId = $creator['id'] ?? null;

    // Birth-write the trust file via the single canonical path — creator
    // as sole owner. A create with no resolvable owner is invalid (an ownerless,
    // inaccessible project); fail loudly rather than mint one.
    if (!qs_project_birth_write_members($projectPath, $creatorId)) {
        return $fail(ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to initialise project membership'));
    }

    // Update the creator's derived project index (users.php) — cache only, NO
    // role key (role is authoritative in members.json). With switch_to,
    // ONLY the creator's selected_project (their per-user EDITING target) moves
    // to the new project — a command never repoints what a deployment serves;
    // the site root keeps serving the fixed main and the new project is edited
    // at /p/<id>/ (the fixed-main model).
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
        'path' => SECURE_FOLDER_NAME . '/projects/' . $projectName,
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
 * Generate config.php content. var_export writes every value as a PHP literal,
 * so a quote or a backslash in the site name is stored exactly as it was sent.
 * A project stores language codes only; their names come from the
 * installation's language list when they are shown.
 */
function createProjectConfig(array $config): string {
    return "<?php\n/**\n * Site Configuration\n * Created: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($config, true) . ";\n";
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
    qs_json_write($projectPath . '/templates/model/json/menu.json', $menuJson, JSON_PRETTY_PRINT, LOCK_EX);
    
    // footer.json — minimal root, content added by user/workflows
    $footerJson = [
        ['tag' => 'footer', 'params' => ['class' => 'main-footer'], 'children' => []]
    ];
    qs_json_write($projectPath . '/templates/model/json/footer.json', $footerJson, JSON_PRETTY_PRINT, LOCK_EX);
    
    // Note: No menu.php or footer.php needed - PageManagement renders JSON directly
}

/**
 * The project's stylesheet, empty: a new site shows only what its author adds. The starter
 * structures keep their class names (main-nav, main-footer, container) as hooks, unstyled. The
 * commands that edit the stylesheet only need the file to exist: setRootVariables creates the
 * :root block when there is none.
 */
function createEmptyStylesheet(string $projectPath): void {
    file_put_contents($projectPath . '/public/style/style.css', '', LOCK_EX);

    // index.php for style folder
    file_put_contents($projectPath . '/public/style/index.php', "<?php\n// Directory listing disabled\nhttp_response_code(403);\n", LOCK_EX);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_createProject($trimParams->params(), $trimParams->additionalParams())->send();
}