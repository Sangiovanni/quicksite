<?php
/**
 * Admin Panel Helper Functions
 * 
 * Utility functions for the admin panel.
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
 * The command console's categories, in the order it lists them: each one's label in the panel's
 * language, its icon, and its commands. Hand-curated: a command shows on /admin/command only once it
 * is named here. A label is the word the panel already gives the same feature where it has one (its
 * menu entry, or its page's title), so the console and that page read the same; any other label is
 * its own word, under commands.categoryNames.
 */
function getCommandCategories(): array {
    return [
        'route_management' => [
            'label' => __admin('commands.categoryNames.route_management'),
            'icon' => 'route',
            'commands' => ['addRoute', 'deleteRoute', 'setRouteLayout', 'setRouteResolver', 'getRoutes', 'getSiteMap', 'setSiteMapConfig', 'getSocialPreview', 'setSocialPreview', 'analyzeReachability']
        ],
        'structure_management' => [
            'label' => __admin('commands.categoryNames.structure_management'),
            'icon' => 'structure',
            'commands' => ['getStructure', 'editStructure', 'getComponent', 'listComponents', 'listPages', 'findComponentUsages', 'renameComponent', 'duplicateComponent']
        ],
        'node_management' => [
            'label' => __admin('commands.categoryNames.node_management'),
            'icon' => 'nodes',
            'commands' => ['moveNode', 'deleteNode', 'addNode', 'addComplexElement', 'duplicateNode', 'editNode', 'addComponentToNode', 'editComponentToNode']
        ],
        'alias_management' => [
            'label' => __admin('commands.categoryNames.alias_management'),
            'icon' => 'link',
            'commands' => ['createAlias', 'deleteAlias', 'listAliases']
        ],
        'translation_management' => [
            'label' => __admin('nav.translations'),
            'icon' => 'translate',
            'commands' => ['getTranslation', 'getTranslations', 'setTranslationKeys', 'deleteTranslationKeys', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys', 'analyzeTranslations', 'cleanOrphanTranslations', 'importStructureTranslations']
        ],
        'language_management' => [
            'label' => __admin('commands.categoryNames.language_management'),
            'icon' => 'language',
            'commands' => ['getLangList', 'getLanguageList', 'checkStructureMulti', 'addLang', 'deleteLang', 'setDefaultLang']
        ],
        'asset_management' => [
            'label' => __admin('commands.categoryNames.asset_management'),
            'icon' => 'image',
            'commands' => ['uploadAsset', 'deleteAsset', 'listAssets', 'editAsset']
        ],
        'style_management' => [
            'label' => __admin('commands.categoryNames.style_management'),
            'icon' => 'palette',
            'commands' => ['getStyles', 'editStyles']
        ],
        'css_variables_rules' => [
            'label' => __admin('commands.categoryNames.css_variables_rules'),
            'icon' => 'css',
            'commands' => ['getRootVariables', 'setRootVariables', 'setThemeMode', 'listStyleRules', 'getStyleRule', 'setStyleRule', 'deleteStyleRule']
        ],
        'css_animations' => [
            'label' => __admin('commands.categoryNames.css_animations'),
            'icon' => 'animation',
            'commands' => ['listKeyframes', 'getKeyframes', 'getAnimatedSelectors', 'setKeyframes', 'deleteKeyframes']
        ],
        'site_customization' => [
            'label' => __admin('commands.categoryNames.site_customization'),
            'icon' => 'settings',
            'commands' => ['editFavicon', 'editTitle']
        ],
        'builds' => [
            'label' => __admin('nav.builds'),
            'icon' => 'package',
            'commands' => ['build', 'getBuild', 'deleteBuild', 'cleanResolverCache', 'downloadBuild']
        ],
        'project_management' => [
            'label' => __admin('commands.categoryNames.project_management'),
            'icon' => 'folder-tree',
            'commands' => ['listProjects', 'createProject', 'cloneProject', 'renameProject', 'deleteProject', 'exportProject', 'importProject', 'downloadExport', 'clearExports', 'backupProject', 'listBackups', 'restoreBackup', 'deleteBackup']
        ],
        // Membership consent model. Marker-scoped roster
        // management, request adjudication + the join-policy knob + the
        // any-member sponsor lane…
        'member_management' => [
            'label' => __admin('members.title'),
            'icon' => 'users',
            'commands' => ['listMembers', 'getProjectRoster', 'inviteMember', 'cancelInvitation', 'changeMemberRole', 'removeMember', 'transferOwnership', 'approveJoinRequest', 'denyJoinRequest', 'proposeMember', 'setJoinPolicy', 'reconcileMemberships']
        ],
        // The caller's OWN memberships are not here: joining a project, leaving
        // one, and looking somebody up in order to invite them are operations on
        // an ACCOUNT, not steps in developing a project. They are served by
        // /admin/self and reached from the My Memberships page. A category with
        // no command is left out, never kept empty: an empty one renders as a
        // category with nothing in it.
        'command_history' => [
            'label' => __admin('history.title'),
            'icon' => 'history',
            'commands' => ['getCommandHistory', 'clearCommandHistory']
        ],
        // Session lifecycle.
        'auth_session' => [
            'label' => __admin('commands.categoryNames.auth_session'),
            'icon' => 'key',
            // changePassword / deleteMyAccount are not commands — managing
            // the login you sign in with is not project
            // development. Both are served by /admin/self and reached from
            // the Account page. login/logoutSession/register stay: a CLI that
            // cannot authenticate is not headlessly usable.
            'commands' => ['login', 'logoutSession', 'register']
        ],
        'js_functions' => [
            'label' => __admin('commands.categoryNames.js_functions'),
            'icon' => 'zap',
            'commands' => ['listJsFunctions', 'listDataBindings', 'listInteractions', 'addInteraction', 'editInteraction', 'deleteInteraction', 'getPageEvents', 'addPageEvent', 'editPageEvent', 'deletePageEvent', 'getStateStores', 'setStateStores']
        ],
        'api_registry' => [
            'label' => __admin('apis.title'),
            'icon' => 'globe',
            'commands' => ['listApiEndpoints', 'getApiEndpoint', 'addApi', 'editApi', 'deleteApi', 'testApiEndpoint']
        ],
        'oauth_providers' => [
            'label' => __admin('commands.categoryNames.oauth_providers'),
            'icon' => 'shield',
            'commands' => ['listOAuthProviders', 'setOAuthCredentials']
        ],
        'storage_registry' => [
            'label' => __admin('commands.categoryNames.storage_registry'),
            'icon' => 'database',
            'commands' => ['listStorageItems', 'addStorageItem', 'editStorageItem', 'deleteStorageItem', 'scanStorageUsage', 'getConsentStatus', 'generateConsentLayer', 'generateCookiePolicy', 'deleteCookiePolicy']
        ],
        'privacy_registry' => [
            'label' => __admin('nav.privacy'),
            'icon' => 'shield',
            'commands' => ['getPrivacyStatus', 'setCollectedDatum', 'deleteCollectedDatum', 'setPrivacyMapping', 'setPrivacyHost', 'generatePrivacyPolicy', 'deletePrivacyPolicy', 'setPrivacyCookieSection']
        ],
        'snippet_management' => [
            'label' => __admin('commands.categoryNames.snippet_management'),
            'icon' => 'puzzle',
            'commands' => ['listSnippets', 'getSnippet', 'createSnippet', 'deleteSnippet', 'duplicateSnippet', 'insertSnippet', 'injectSnippetCss']
        ],
        // Install-wide embed policy: it is set at deployment and no command
        // writes it, so the console lists the read alone. The label is the one
        // the panel already uses for this feature — nav.embed-security, the
        // /admin/embed-security page — so the console section and the nav entry
        // read as the same thing.
        'embed_security' => [
            'label' => __admin('nav.embed-security'),
            'icon' => 'shield',
            'commands' => ['getIframeSandbox']
        ],
        'documentation' => [
            'label' => __admin('commands.categoryNames.documentation'),
            'icon' => 'book',
            'commands' => ['help']
        ]
    ];
}
