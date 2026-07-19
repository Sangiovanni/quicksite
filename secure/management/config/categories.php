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

    // Owner-only "make THIS project the served main" (C8 8.1). switchProject rewrites
    // target.php, the served-main pointer, publishing the project at the site ROOT.
    // It used to live in the GLOBAL system.admin category, whose access 'owner' means
    // "owns any project anywhere" — so any account could createProject (access 'any')
    // and then promote a project it had NO membership on, publishing a private project
    // and bypassing owner-only project.visibility (proven escalation, C8 §8 F-C8-8.1-1).
    // Project-scoped + owner-only makes the gate "owner of the project you promote",
    // enforced by the dispatcher against the URL marker.
    'project.serve' => [
        'scope' => 'project',
        'commands' => ['switchProject'],
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
    // auth.session these DO require a bearer; any authenticated user. Both
    // commands re-verify the current password before acting — 'any' authorizes
    // WHOSE account may be touched (only your own), not how cheaply (C8 8.2).
    'account.self' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['changePassword', 'deleteMyAccount'],
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

    // Workflow-editor tooling. These read ONLY the workflow block/lint templates
    // shipped under secure/admin/workflows — QuickSite's own catalogue, not any
    // project's data — so they were re-tagged GLOBAL in C8 8.5. As project-scoped
    // admin+ they demanded a marker and an admin membership for a read that touches
    // no project at all: the error direction was fail-SAFE, but the tag misdescribed
    // the command and forced the editor to carry a marker it did not need.
    'workflow' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listWorkflowBlocks', 'lintWorkflows'],
    ],

    // "My projects" surface (OUTPUT filtered to memberships by C7/C8; the command
    // itself is any-auth). getActiveProject = "my current/last project".
    // getMySpaceUsage aggregates disk usage across the projects the caller OWNS —
    // global because an owner-wide total is not a fact about any one project and so
    // cannot carry a marker. It takes no project parameter (nothing to retarget)
    // and resolves ownership per project from members.json, so it reports only what
    // the caller owns; it is NOT a return of the installation-wide enumeration
    // removed from getSizeInfo in C8 8.5.
    'projects.list' => [
        'scope' => 'global',
        'access' => 'any',
        'commands' => ['listProjects', 'getActiveProject', 'getMySpaceUsage'],
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

    // NOTE — there is deliberately NO owner-gated global category. The former
    // 'system.admin' (access 'owner') was retired in C8 8.5 along with the rule
    // itself: hasPermission resolved it as "owns ANY project anywhere",
    // target-independent, while projects.create is access 'any' — so any account
    // minted that ownership in one call (the F-C8-8.1-1 mechanism). Its last member,
    // applyUpdate, is now operator/CLI-side per AUTH_REWORK §2.3 GAP A: it is
    // UNROUTED (absent from routes.php) and invoked from the deploy/CLI side, so no
    // token can reach a command that git-pulls the installation. checkForUpdates
    // stays routed under system.read, so the panel still reports available updates.
    // (The generateToken/listTokens/revokeToken trio was REMOVED in C5b; switchProject
    // moved to the project-scoped owner-only project.serve in C8 8.1.)
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
