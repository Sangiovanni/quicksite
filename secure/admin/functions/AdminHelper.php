<?php
/**
 * Admin Panel Helper Functions
 * 
 * Utility functions for the admin panel.
 * 
 * @version 1.6.0
 */

/**
 * Escape HTML to prevent XSS
 */
function adminEscape(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Escape for use in HTML attributes
 */
function adminAttr(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a timestamp for display
 */
function adminFormatDate(string $timestamp, string $format = 'Y-m-d H:i:s'): string {
    try {
        $date = new DateTime($timestamp);
        return $date->format($format);
    } catch (Exception $e) {
        return $timestamp;
    }
}

/**
 * Format JSON for display
 */
function adminFormatJson($data, bool $pretty = true): string {
    return json_encode($data, $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : JSON_UNESCAPED_UNICODE);
}

/**
 * Get CSS class for status badge
 */
function adminStatusClass(string $status): string {
    return match ($status) {
        'success' => 'badge--success',
        'error' => 'badge--error',
        'warning' => 'badge--warning',
        'info' => 'badge--info',
        default => 'badge--default'
    };
}

/**
 * Generate a unique ID for HTML elements
 */
function adminUniqueId(string $prefix = 'admin'): string {
    static $counter = 0;
    return $prefix . '-' . (++$counter);
}

/**
 * Check if request is AJAX
 */
function isAdminAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Get command categories from help.php structure
 */
function getCommandCategories(): array {
    // This will be populated from help.php response
    return [
        'folder_management' => [
            'label' => 'Folder Management',
            'icon' => 'folder',
            'commands' => ['setPublicSpace', 'renameSecureFolder', 'renamePublicFolder']
        ],
        'route_management' => [
            'label' => 'Route Management',
            'icon' => 'route',
            'commands' => ['addRoute', 'deleteRoute', 'setRouteLayout', 'getRoutes', 'getSiteMap']
        ],
        'structure_management' => [
            'label' => 'Structure Management',
            'icon' => 'structure',
            'commands' => ['getStructure', 'editStructure', 'listComponents', 'listPages', 'findComponentUsages', 'renameComponent', 'duplicateComponent']
        ],
        'node_management' => [
            'label' => 'Node Management',
            'icon' => 'nodes',
            'commands' => ['moveNode', 'deleteNode', 'addNode', 'editNode', 'addComponentToNode', 'editComponentToNode']
        ],
        'alias_management' => [
            'label' => 'URL Aliases',
            'icon' => 'link',
            'commands' => ['createAlias', 'deleteAlias', 'listAliases']
        ],
        'translation_management' => [
            'label' => 'Translations',
            'icon' => 'translate',
            'commands' => ['getTranslation', 'getTranslations', 'setTranslationKeys', 'deleteTranslationKeys', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys', 'analyzeTranslations']
        ],
        'language_management' => [
            'label' => 'Languages',
            'icon' => 'language',
            'commands' => ['getLangList', 'setMultilingual', 'checkStructureMulti', 'addLang', 'deleteLang', 'setDefaultLang']
        ],
        'asset_management' => [
            'label' => 'Assets',
            'icon' => 'image',
            'commands' => ['uploadAsset', 'deleteAsset', 'listAssets', 'updateAssetMeta']
        ],
        'style_management' => [
            'label' => 'Styles',
            'icon' => 'palette',
            'commands' => ['getStyles', 'editStyles']
        ],
        'css_variables_rules' => [
            'label' => 'CSS Variables & Rules',
            'icon' => 'css',
            'commands' => ['getRootVariables', 'setRootVariables', 'listStyleRules', 'getStyleRule', 'setStyleRule', 'deleteStyleRule']
        ],
        'css_animations' => [
            'label' => 'CSS Animations',
            'icon' => 'animation',
            'commands' => ['listKeyframes', 'getKeyframes', 'getAnimatedSelectors', 'setKeyframes', 'deleteKeyframes']
        ],
        'site_customization' => [
            'label' => 'Site Customization',
            'icon' => 'settings',
            'commands' => ['editFavicon', 'editTitle']
        ],
        'build_deployment' => [
            'label' => 'Build & Deploy',
            'icon' => 'package',
            'commands' => ['build', 'listBuilds', 'getBuild', 'deleteBuild', 'cleanBuilds', 'deployBuild', 'downloadBuild']
        ],
        'project_management' => [
            'label' => 'Project Management',
            'icon' => 'folder-tree',
            'commands' => ['listProjects', 'getActiveProject', 'switchProject', 'createProject', 'deleteProject', 'exportProject', 'importProject', 'downloadExport', 'clearExports', 'backupProject', 'listBackups', 'restoreBackup', 'deleteBackup']
        ],
        'storage_monitoring' => [
            'label' => 'Storage',
            'icon' => 'database',
            'commands' => ['getSizeInfo']
        ],
        'command_history' => [
            'label' => 'Command History',
            'icon' => 'history',
            'commands' => ['getCommandHistory', 'clearCommandHistory']
        ],
        'role_management' => [
            'label' => 'Roles & Permissions',
            'icon' => 'shield',
            'commands' => ['listRoles', 'getMyPermissions', 'createRole', 'editRole', 'deleteRole']
        ],
        'authentication' => [
            'label' => 'Authentication',
            'icon' => 'key',
            'commands' => ['generateToken', 'listTokens', 'revokeToken']
        ],
        'ai_integration' => [
            'label' => 'AI Integration',
            'icon' => 'bot',
            'commands' => ['listAiProviders', 'detectProvider', 'testAiKey', 'callAi']
        ],
        'js_functions' => [
            'label' => 'JavaScript Functions / Interactions',
            'icon' => 'zap',
            'commands' => ['listJsFunctions', 'addJsFunction', 'editJsFunction', 'deleteJsFunction', 'listInteractions', 'addInteraction', 'editInteraction', 'deleteInteraction']
        ],
        'api_registry' => [
            'label' => 'API Registry',
            'icon' => 'globe',
            'commands' => ['listApiEndpoints', 'getApiEndpoint', 'addApi', 'editApi', 'deleteApi', 'testApiEndpoint']
        ],
        'documentation' => [
            'label' => 'Documentation',
            'icon' => 'book',
            'commands' => ['help']
        ]
    ];
}

/**
 * Get method badge color
 */
function getMethodBadgeClass(string $method): string {
    return match (strtoupper($method)) {
        'GET' => 'badge--get',
        'POST' => 'badge--post',
        'PUT' => 'badge--put',
        'DELETE' => 'badge--delete',
        default => 'badge--default'
    };
}
