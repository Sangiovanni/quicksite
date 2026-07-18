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

    // Project-level settings knobs (C8 8.3b; 8.4 adds setProjectVisibility, …).
    // setJoinPolicy opens/closes the self-service request lane. admin+.
    'project.settings' => [
        'scope' => 'project',
        'commands' => ['setJoinPolicy'],
    ],

    // Membership management (C8 8.3a/8.3b) — consent model: invitations +
    // join-request adjudication, never direct adds. Rank rules (canManageRole
    // strictly-below) enforced in-command, in-lock. Targeting is by user_id
    // only. admin+.
    'project.members' => [
        'scope' => 'project',
        'commands' => ['listMembers', 'inviteMember', 'cancelInvitation', 'changeMemberRole', 'removeMember', 'approveJoinRequest', 'denyJoinRequest'],
    ],

    // Reduced roster for EVERY member rank (C8 8.3c): active members only —
    // {user_id, name, role} rows, NO pending queue (adjudication data stays
    // admin+ via project.members/listMembers). Exists so any member can see
    // "who is on this project with me" (the R4 page requirement).
    'project.roster' => [
        'scope' => 'project',
        'commands' => ['getProjectRoster'],
    ],

    // The sponsor lane (C8 8.3b, model A): ANY member — viewer included — may
    // VOUCH an outsider (direction:'request' entry, mandatory note, no
    // engagement of the target). Authority stays with approveJoinRequest:
    // a proposal grants nothing and notifies nobody until validated.
    'project.propose' => [
        'scope' => 'project',
        'commands' => ['proposeMember'],
    ],

    // Ownership rotation — owner only.
    'project.ownership' => [
        'scope' => 'project',
        'commands' => ['transferOwnership'],
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

    // Session lifecycle (C5b) + self-registration (C8). Served pre-auth
    // (index.php PUBLIC_COMMANDS) — each command is SELF-authenticating /
    // self-gating (email+password / the refresh token itself / the
    // registration flag + flood controls), so hasPermission never actually
    // gates them; mapped here so the command↔category coverage stays 1:1.
    'auth.session' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['login', 'refreshSession', 'logoutSession', 'register'],
    ],

    // Authenticated self-service on the caller's OWN account (C8). Unlike
    // auth.session these DO require a bearer; any authenticated user.
    'account.self' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['changePassword'],
    ],

    // Exact PUBLIC-name lookup (C8 8.3a) — the "invite someone" primitive:
    // response is {user_id, name} pairs ONLY. The PRIVATE username is never
    // searchable and never returned (C8 8.0b privacy rule).
    'users.lookup' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['findUser'],
    ],

    // Membership self-service (C8 8.3a/8.3b): the caller's OWN invitations,
    // join requests, memberships and notices. GLOBAL on purpose — an invitee/
    // requester is not a member, so the '/p/<id>/' marker gate would 403 them
    // before the command runs; `project` is an F1-validated DATA parameter and
    // every command acts only on entries the caller owns or authored
    // (withdrawJoinRequest's `by == caller` rule covers sponsored proposals).
    'membership.self' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listMyInvitations', 'acceptInvitation', 'declineInvitation', 'leaveProject', 'dismissProjectNotice', 'requestToJoin', 'withdrawJoinRequest', 'listMyProposals'],
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

    // Per-user EDITING target (selected_project) — a benign self-write: sets which
    // project THIS user's panel edits (C9). UX only, never an authz input; the command
    // still refuses selecting a project you are not a member of. any-auth.
    'projects.select' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['setSelectedProject'],
    ],

    // Read-only update check (hardcoded GitHub URL, no request input — C2 PASS).
    'system.read' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['checkForUpdates'],
    ],

    // INTERIM owner-only home for the operator commands pending relocation:
    //   applyUpdate + switchProject (set served project) → operator/deploy in beta.11
    //   (AUTH_REWORK §2.3 GAP A). Until then, only a project owner may run these.
    //   (The generateToken/listTokens/revokeToken trio was REMOVED in C5b —
    //   sessions via login/refreshSession replace lifetime tokens.)
    'system.admin' => [
        'scope' => 'global',
        'access' => 'owner',
        'commands' => [
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
