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
            'listSnippets', 'getSnippet', 'getIframeSandbox', 'getBuild',
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
        // setSiteMapConfig writes the sitemap sidecar AND publishes sitemap.txt —
        // it decides which routes the world sees, so it belongs with the route
        // writers, not with the read-only getSiteMap.
        'commands' => ['addRoute', 'deleteRoute', 'setRouteLayout', 'createAlias', 'deleteAlias', 'setSiteMapConfig'],
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

    // Build lifecycle (the getBuild read is content.read). developer+.
    'build' => [
        'scope' => 'project',
        'commands' => ['build', 'deleteBuild', 'cleanResolverCache', 'downloadBuild'],
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
            'downloadExport', 'clearExports', 'cloneProject',
        ],
    ],

    // Command history — logs may hold secret request bodies (F7). admin+.
    'history' => [
        'scope' => 'project',
        'commands' => ['getCommandHistory', 'clearCommandHistory'],
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
        'commands' => ['listMembers', 'inviteMember', 'cancelInvitation', 'changeMemberRole', 'removeMember', 'approveJoinRequest', 'denyJoinRequest', 'reconcileMemberships'],
    ],

    // Owner-only project exposure (C8 8.4). setProjectVisibility flips surface-B
    // public/private serving — the gravest exposure a project carries (public =
    // anyone on the internet reads the site), so it sits at the delete/transfer
    // tier, NOT the admin-tier project.settings that setJoinPolicy uses.
    'project.visibility' => [
        'scope' => 'project',
        'commands' => ['setProjectVisibility'],
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
        'commands' => ['login', 'logoutSession', 'register'],
    ],

    // NOTE — account self-service, membership self-service and the two directory
    // lookups are NOT commands (beta.11 S6). The command surface is a CLI for
    // DEVELOPING A PROJECT; managing your login, getting into or out of a
    // project, and looking a person up in order to invite them are none of those
    // things. Four categories held them and were deleted with their last command
    // rather than left empty: 'account.self' (changePassword, deleteMyAccount),
    // 'users.lookup' (findUser), 'roles.read' (listRoles, getMyPermissions) and
    // 'membership.self' (the eight invitation/request/notice verbs). They are
    // served by /admin/self now; the logic lives in
    // <secure>/admin/functions/{accountSelf,membershipSelf,directory}.php.
    // All four were global + access 'any', so hasPermission contributed
    // authentication and nothing else — which the shared admin gate establishes.

    // "My projects" surface (OUTPUT filtered to memberships by C7/C8; the command
    // itself is any-auth). getMySpaceUsage sat here until beta.11 S6 — a quota is
    // a fact about an account, not about a project being developed — and is now
    // GET /admin/self/space-usage.
    'projects.list' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listProjects'],
    ],

    // Any authenticated user may create a project (and becomes its sole owner).
    // importProject is create-from-archive: it mints a NEW project (no marker can
    // exist for a project that does not exist yet), birth-writes the importer as
    // sole owner, and discards any archived roster — so it is GLOBAL like
    // createProject, not the project-scoped admin-tier project.data it used to sit
    // in (C8 8.4). The deep ZIP-internal path/zip-slip sweep stays C11.
    'projects.create' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['createProject', 'importProject'],
    ],

    // NOTE — there is deliberately NO owner-gated global category. The former
    // 'system.admin' (access 'owner') was retired in C8 8.5 along with the rule
    // itself: hasPermission resolved it as "owns ANY project anywhere",
    // target-independent, while projects.create is access 'any' — so any account
    // minted that ownership in one call (the F-C8-8.1-1 mechanism). Its last member
    // applied updates; that is now `git pull` on the server, which has no HTTP
    // surface at all, so no token can reach the code that updates the installation.
    // (The generateToken/listTokens/revokeToken trio was REMOVED in C5b; switchProject
    // was deleted in C15 along with the served-project concept it existed to repoint.)
    //
    // ALSO GONE, beta.11 S6: 'system.read' (checkForUpdates) and 'projects.select'
    // (setSelectedProject). The command surface is a CLI for DEVELOPING a project;
    // an update check is about the installation and an editing pointer is panel
    // state, so neither is a command. Both still work — the panel reaches them at
    // /admin/api's update-check arm and /admin/state/selected-project — and both
    // keep the permissions they had (any authenticated caller, membership still
    // enforced for the project pointer). Their categories were deleted rather than
    // left empty: the routes.php <-> categories.php 1:1 invariant cannot see an
    // empty category and the /admin/command UI cannot use one.
    // Global categories may only declare an access in QS_GLOBAL_ACCESS_GRANTING
    // ('any') or the explicit deny 'none' — see AuthManagement.php.

    // DISABLED — denied to everyone ('none' is not a granting rule → hasPermission
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
