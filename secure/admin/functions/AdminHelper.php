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
        'route_management' => [
            'label' => 'Route Management',
            'icon' => 'route',
            'commands' => ['addRoute', 'deleteRoute', 'setRouteLayout', 'setRouteResolver', 'getRoutes', 'getSiteMap', 'setSiteMapConfig', 'analyzeReachability']
        ],
        'structure_management' => [
            'label' => 'Structure Management',
            'icon' => 'structure',
            'commands' => ['getStructure', 'editStructure', 'getComponent', 'listComponents', 'listPages', 'findComponentUsages', 'renameComponent', 'duplicateComponent']
        ],
        'node_management' => [
            'label' => 'Node Management',
            'icon' => 'nodes',
            'commands' => ['moveNode', 'deleteNode', 'addNode', 'addComplexElement', 'duplicateNode', 'editNode', 'addComponentToNode', 'editComponentToNode']
        ],
        'alias_management' => [
            'label' => 'URL Aliases',
            'icon' => 'link',
            'commands' => ['createAlias', 'deleteAlias', 'listAliases']
        ],
        'translation_management' => [
            'label' => 'Translations',
            'icon' => 'translate',
            'commands' => ['getTranslation', 'getTranslations', 'setTranslationKeys', 'deleteTranslationKeys', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys', 'analyzeTranslations', 'cleanOrphanTranslations', 'importStructureTranslations']
        ],
        'language_management' => [
            'label' => 'Languages',
            'icon' => 'language',
            'commands' => ['getLangList', 'getLanguageList', 'setMultilingual', 'checkStructureMulti', 'addLang', 'deleteLang', 'setDefaultLang']
        ],
        'asset_management' => [
            'label' => 'Assets',
            'icon' => 'image',
            'commands' => ['uploadAsset', 'deleteAsset', 'listAssets', 'editAsset']
        ],
        'style_management' => [
            'label' => 'Styles',
            'icon' => 'palette',
            'commands' => ['getStyles', 'editStyles']
        ],
        'css_variables_rules' => [
            'label' => 'CSS Variables & Rules',
            'icon' => 'css',
            'commands' => ['getRootVariables', 'setRootVariables', 'setThemeMode', 'listStyleRules', 'getStyleRule', 'setStyleRule', 'deleteStyleRule']
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
            'commands' => ['build', 'getBuild', 'deleteBuild', 'cleanResolverCache', 'deployBuild', 'downloadBuild']
        ],
        'project_management' => [
            'label' => 'Project Management',
            'icon' => 'folder-tree',
            'commands' => ['listProjects', 'createProject', 'cloneProject', 'deleteProject', 'exportProject', 'importProject', 'downloadExport', 'clearExports', 'backupProject', 'listBackups', 'restoreBackup', 'deleteBackup']
        ],
        // C8 8.3a/8.3b — membership consent model. Marker-scoped roster
        // management, request adjudication + the join-policy knob + the
        // any-member sponsor lane…
        'member_management' => [
            'label' => 'Project Members',
            'icon' => 'users',
            'commands' => ['listMembers', 'getProjectRoster', 'inviteMember', 'cancelInvitation', 'changeMemberRole', 'removeMember', 'transferOwnership', 'approveJoinRequest', 'denyJoinRequest', 'proposeMember', 'setJoinPolicy', 'setProjectVisibility', 'reconcileMemberships']
        ],
        // The caller's OWN membership surface used to sit here as a
        // 'my_memberships' section. It is gone — not emptied — because all nine
        // of its entries stopped being commands in beta.11 S6: joining a project,
        // leaving one, and looking somebody up in order to invite them are
        // operations on an ACCOUNT, not steps in developing a project. They are
        // served by /admin/self and reached from the My Memberships page. An
        // emptied section renders as a category with nothing in it, which is why
        // this one was removed with its last command.
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
            // listRoles / getMyPermissions left the command surface in beta.11 S6:
            // reading the role catalogue and your own effective role is
            // authorization introspection about an ACCOUNT, not project
            // development. Both are served by /admin/self.
            'commands' => ['createRole', 'editRole', 'deleteRole']
        ],
        // C5b session lifecycle. (This slot previously held the removed
        // generateToken/listTokens/revokeToken trio under a duplicate
        // 'authentication' key that the OAuth block below silently clobbered —
        // the trio never actually rendered. Unique keys now.)
        'auth_session' => [
            'label' => 'Authentication / Session',
            'icon' => 'key',
            // changePassword / deleteMyAccount left the command surface in
            // beta.11 S6 — managing the login you sign in with is not project
            // development. Both are served by /admin/self and reached from
            // the Account page. login/logoutSession/register stay: a CLI that
            // cannot authenticate is not headlessly usable.
            'commands' => ['login', 'logoutSession', 'register']
        ],
        'js_functions' => [
            'label' => 'JavaScript Functions / Interactions',
            'icon' => 'zap',
            'commands' => ['listJsFunctions', 'listDataBindings', 'listInteractions', 'addInteraction', 'editInteraction', 'deleteInteraction', 'getPageEvents', 'addPageEvent', 'editPageEvent', 'deletePageEvent', 'getStateStores', 'setStateStores']
        ],
        'api_registry' => [
            'label' => 'API Registry',
            'icon' => 'globe',
            'commands' => ['listApiEndpoints', 'getApiEndpoint', 'addApi', 'editApi', 'deleteApi', 'testApiEndpoint']
        ],
        'oauth_providers' => [
            'label' => 'OAuth Providers',
            'icon' => 'shield',
            'commands' => ['listOAuthProviders', 'addOAuthProvider', 'editOAuthProvider', 'deleteOAuthProvider']
        ],
        'storage_registry' => [
            'label' => 'Storage Registry',
            'icon' => 'database',
            'commands' => ['listStorageItems', 'addStorageItem', 'editStorageItem', 'deleteStorageItem', 'setStorageDescLang', 'scanStorageUsage', 'getConsentStatus', 'generateConsentLayer', 'generateCookiePolicy', 'deleteCookiePolicy']
        ],
        'privacy_registry' => [
            'label' => 'Privacy',
            'icon' => 'shield',
            'commands' => ['getPrivacyStatus', 'setCollectedDatum', 'deleteCollectedDatum', 'setPrivacyDescLang', 'setPrivacyMapping', 'setPrivacyHost', 'generatePrivacyPolicy', 'deletePrivacyPolicy', 'setPrivacyCookieSection']
        ],
        'snippet_management' => [
            'label' => 'Snippets',
            'icon' => 'puzzle',
            'commands' => ['listSnippets', 'getSnippet', 'createSnippet', 'deleteSnippet', 'duplicateSnippet', 'insertSnippet', 'injectSnippetCss']
        ],
        // Per-project embed policy (data/iframe_sandbox.json). The label is the
        // one the panel already uses for this feature — nav.embedSecurity, the
        // /admin/embed-security page — so the console section and the nav entry
        // read as the same thing rather than as two names for one concept.
        'embed_security' => [
            'label' => 'Embed Security',
            'icon' => 'shield',
            'commands' => ['getIframeSandbox', 'setIframeSandbox', 'removeIframeSandbox']
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
