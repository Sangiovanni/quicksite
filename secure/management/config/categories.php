<?php
/**
 * Command CATEGORIES — the trust-coherent authorization map (beta.10 C6).
 *
 * This is the single source of truth for "which commands live in which capability
 * bucket". Roles (roles.php) grant *categories*, not individual commands; the
 * permission check (AuthManagement::hasPermission) resolves
 *   command -> category -> scope -> (role grant | global access rule).
 *
 * Engine plumbing → stays PHP (CLAUDE.md: internal admin config the engine consumes;
 * NOT author-extended — there are no custom roles/categories, L8). Every command in
 * routes.php belongs to exactly ONE category (verified 1:1). Adding a new command =
 * add its name to exactly one category here (see CLAUDE.md "Adding a new command").
 *
 * Category shape:
 *   'scope'   => 'project' | 'global'
 *   'access'  => (GLOBAL only) 'any' = any authenticated user
 *                              'owner' = interim, requires effective role == owner
 *   'commands'=> string[]  (may be empty for a forward-declared category)
 *
 * PROJECT categories are granted through roles (roles.php `categories`). GLOBAL
 * categories are NOT role-granted — they are gated by their `access` rule.
 * There is NO superadmin and NO '*' bypass; `owner` is the top of each project.
 */

return [
    // ====================================================================
    // PROJECT-SCOPED — require membership; granted via a role's categories
    // ====================================================================

    // Read-only content + analysis. No secrets. viewer+.
    'content.read' => [
        'scope' => 'project',
        'commands' => [
            'getRoutes', 'getSiteMap', 'getStructure', 'getComponent', 'listComponents',
            'listPages', 'findComponentUsages', 'getTranslation', 'getTranslations',
            'getTranslationKeys', 'getLangList', 'getLanguageList', 'checkStructureMulti',
            'validateTranslations', 'getUnusedTranslationKeys', 'analyzeTranslations',
            'analyzeReachability', 'listAssets', 'getStyles', 'getRootVariables',
            'listStyleRules', 'getStyleRule', 'listKeyframes', 'getKeyframes',
            'getAnimatedSelectors', 'listAliases', 'getSizeInfo', 'listJsFunctions',
            'listDataBindings', 'listInteractions', 'getPageEvents', 'getStateStores',
            'listSnippets', 'getSnippet', 'getIframeSandbox', 'getBuild', 'listBuilds',
        ],
    ],

    // Integration config metadata (secrets ALWAYS redacted regardless of role, F7).
    // Not "content" — a read-only viewer should not enumerate your integrations. editor+.
    'config.read' => [
        'scope' => 'project',
        'commands' => [
            'listApiEndpoints', 'getApiEndpoint', 'listOAuthProviders',
            'listStorageItems', 'scanStorageUsage', 'getConsentStatus', 'getPrivacyStatus',
        ],
    ],

    // Structure / node / component / snippet + title edits. editor+.
    'content.write' => [
        'scope' => 'project',
        'commands' => [
            'editStructure', 'addNode', 'editNode', 'moveNode', 'deleteNode', 'duplicateNode',
            'addComplexElement', 'addComponentToNode', 'editComponentToNode', 'renameComponent',
            'duplicateComponent', 'createSnippet', 'deleteSnippet', 'duplicateSnippet',
            'insertSnippet', 'editTitle',
        ],
    ],

    // Translations + language management. editor+.
    'translation.write' => [
        'scope' => 'project',
        'commands' => [
            'setTranslationKeys', 'deleteTranslationKeys', 'addLang', 'deleteLang',
            'setDefaultLang', 'setMultilingual', 'cleanOrphanTranslations', 'importStructureTranslations',
        ],
    ],

    // Routes + aliases. editor+.
    'route.write' => [
        'scope' => 'project',
        'commands' => ['addRoute', 'deleteRoute', 'setRouteLayout', 'createAlias', 'deleteAlias'],
    ],

    // Server-side data source wiring — a developer capability, split out of route.write. developer+.
    'resolver.manage' => [
        'scope' => 'project',
        'commands' => ['setRouteResolver'],
    ],

    // Assets + favicon. editor+ (SSRF via uploadAsset URL mode is guarded in C4).
    'asset.write' => [
        'scope' => 'project',
        'commands' => ['uploadAsset', 'editAsset', 'deleteAsset', 'editFavicon'],
    ],

    // Interactions / page events / state stores (JS-verb surface). editor+.
    'interaction.write' => [
        'scope' => 'project',
        'commands' => [
            'addInteraction', 'editInteraction', 'deleteInteraction', 'addPageEvent',
            'editPageEvent', 'deletePageEvent', 'setStateStores',
        ],
    ],

    // Privacy / consent / cookie copy — legally sensitive but content-level. editor+.
    'privacy.manage' => [
        'scope' => 'project',
        'commands' => [
            'addStorageItem', 'editStorageItem', 'deleteStorageItem', 'setStorageDescLang',
            'generateConsentLayer', 'generateCookiePolicy', 'deleteCookiePolicy',
            'setCollectedDatum', 'deleteCollectedDatum', 'setPrivacyMapping', 'setPrivacyHost',
            'setPrivacyDescLang', 'setPrivacyCookieSection', 'generatePrivacyPolicy', 'deletePrivacyPolicy',
        ],
    ],

    // CSS / variables / keyframes / theme / snippet CSS injection. designer+.
    'style.write' => [
        'scope' => 'project',
        'commands' => [
            'editStyles', 'setStyleRule', 'deleteStyleRule', 'setKeyframes', 'deleteKeyframes',
            'setRootVariables', 'setThemeMode', 'injectSnippetCss',
        ],
    ],

    // Build lifecycle (reads listBuilds/getBuild are content.read). developer+.
    'build' => [
        'scope' => 'project',
        'commands' => ['build', 'deleteBuild', 'cleanBuilds', 'cleanResolverCache', 'downloadBuild'],
    ],

    // Push to production. admin+.
    'deploy' => [
        'scope' => 'project',
        'commands' => ['deployBuild'],
    ],

    // API registry writes — secrets + testApiEndpoint SSRF. admin+.
    'api.manage' => [
        'scope' => 'project',
        'commands' => ['addApi', 'editApi', 'deleteApi', 'testApiEndpoint'],
    ],

    // OAuth provider writes — client secrets. admin+.
    'oauth.manage' => [
        'scope' => 'project',
        'commands' => ['addOAuthProvider', 'editOAuthProvider', 'deleteOAuthProvider'],
    ],

    // Iframe sandbox control (embed security). admin+.
    'iframe.manage' => [
        'scope' => 'project',
        'commands' => ['setIframeSandbox', 'removeIframeSandbox'],
    ],

    // Full-data dumps + zip-slip surface (backup/export/import/clone). admin+.
    'project.data' => [
        'scope' => 'project',
        'commands' => [
            'backupProject', 'listBackups', 'restoreBackup', 'deleteBackup', 'exportProject',
            'importProject', 'downloadExport', 'clearExports', 'cloneProject',
        ],
    ],

    // Command history — logs may hold secret request bodies (F7). admin+.
    'history' => [
        'scope' => 'project',
        'commands' => ['getCommandHistory', 'clearCommandHistory'],
    ],

    // Workflow-editor tooling (reads global admin workflow templates). admin+.
    'workflow' => [
        'scope' => 'project',
        'commands' => ['listWorkflowBlocks', 'lintWorkflows'],
    ],

    // Owner-only project operations.
    'project.delete' => [
        'scope' => 'project',
        'commands' => ['deleteProject'],
    ],

    // Forward-declared (C8 populates) — kept so role grants + the rank guard resolve.
    'project.settings' => [
        'scope' => 'project',
        'commands' => [], // C8: setProjectVisibility, …
    ],
    'project.members' => [
        'scope' => 'project',
        'commands' => [], // C8: addMember, removeMember, changeMemberRole, listMembers
    ],
    'project.ownership' => [
        'scope' => 'project',
        'commands' => [], // C8: transferOwnership
    ],

    // ====================================================================
    // GLOBAL — no project membership; gated by `access`
    // ====================================================================

    // Documentation. Also served pre-auth (index.php PUBLIC_COMMANDS).
    'documentation' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['help'],
    ],

    // Self / role catalog reads.
    'roles.read' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listRoles', 'getMyPermissions'],
    ],

    // "My projects" surface (OUTPUT filtered to memberships by C7/C8; the command
    // itself is any-auth). getActiveProject = "my current/last project".
    'projects.list' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listProjects', 'getActiveProject'],
    ],

    // Any authenticated user may create a project (and becomes its sole owner).
    'projects.create' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['createProject'],
    ],

    // Read-only update check (hardcoded GitHub URL, no request input — C2 PASS).
    'system.read' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['checkForUpdates'],
    ],

    // INTERIM owner-only home for the god/operator commands pending relocation.
    //   token trio → REMOVED in C8 (login-page signup replaces them). NOTE:
    //     generateToken is already broken on the C5 token shape (writes a legacy
    //     {name,role} token with no userId → fails closed / unusable) — kept
    //     owner-gated per the C6 task; C8 deletes it.
    //   applyUpdate + switchProject (set served project) → operator/deploy in beta.11
    //   (AUTH_REWORK §2.3 GAP A). Until then, only a project owner may run these.
    'system.admin' => [
        'scope' => 'global',
        'access' => 'owner',
        'commands' => [
            'generateToken', 'listTokens', 'revokeToken',
            'applyUpdate', 'switchProject',
        ],
    ],

    // DISABLED — denied to everyone (access neither 'any' nor 'owner' → hasPermission
    // returns false). Custom-role management has no place in the fixed-role model
    // (L8); editRole additionally fatals on the category-based roles.php shape. These
    // are vestigial pending C8's command-surface decision (remove or reconceive).
    // Mapped here (not left unmapped) so the deny is explicit + documented.
    'disabled' => [
        'scope' => 'global',
        'access' => 'none',
        'commands' => ['createRole', 'editRole', 'deleteRole'],
    ],
];
