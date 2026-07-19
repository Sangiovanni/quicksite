# QuickSite Management API

> Reference for the Management API — the single HTTP surface every QuickSite client (admin panel, CLI, external apps) talks to.

## Endpoint

All API calls go through one entry point:

```
http(s)://<your-domain>/management/<command>[/<urlParam>]
```

`public/management/index.php` authenticates the request, dispatches to a command handler in `secure/management/command/`, and returns a uniform JSON response.

## Self-documenting

The API documents itself. Once installed:

```
GET /management/help
```

returns full documentation for **all 178 commands** — parameters, examples, validation rules, and error codes. For a specific command:

```
GET /management/help/addRoute
```

The `help` endpoint is publicly accessible and is the canonical source of truth for the API surface. This document is a high-level map; `help` is the contract.

## Authentication

- Credentials are **username + password** (`users.php` stores a `password_hash`; a `null` hash marks an externally-managed account that cannot use password login). The username is the **private** login identifier — never shown to other users; public identity is the display name + the user id (there is no email field). `POST /management/login` exchanges the credentials for a **session**:
  ```json
  { "username": "your-username", "password": "…" }
  → { "access_token": "qsa_…", "expires_in": 900,
      "refresh_token": "qsr_…", "refresh_expires_in": 2592000, … }
  ```
- Every other endpoint requires the short-lived **access token**:
  ```
  Authorization: Bearer <access_token>
  ```
  When it expires, requests return `401` with code `auth.token_expired` — call `refreshSession` with the refresh token and retry. Each refresh **rotates** the refresh token (always store both returned tokens); presenting an already-rotated refresh token after a short grace window is treated as theft and revokes the whole session family. `logoutSession` ends a session explicitly.
- Five commands are public: `help`, `login`, `refreshSession`, `logoutSession`, `register`. The session commands are self-authenticating; `register` is self-gating — it enforces the `registration.allow_self_registration` flag server-side (default: **disabled**) plus flood controls (attempts per IP per minute, successful registrations per hour install-wide, and an optional absolute account cap). Failed logins are throttled per username (doubling cooldown after 5 attempts). A duplicate username at `register` returns the same success response as a real creation — login identifiers are private, so no account-existence oracle.
- `changePassword` (authenticated) changes the caller's own password: it requires the current password, shares the login throttle, and revokes every **other** session of the user on success (the session performing the change survives).
- `deleteMyAccount` (authenticated) permanently deletes the caller's **own** account: it requires the current password plus `confirm=true`, and ends every session. There is no command that deletes someone else's account — authorization in QuickSite is per project, so the ways to part with a person are `removeMember` (evict them from one project) and, for the operator, editing `users.php` directly. Deletion is **refused** while the caller is the sole owner of any project: the response lists them, and each must be handed over with `transferOwnership` or destroyed with `deleteProject` first. On success the caller is removed from every project they belong to, along with any invitation addressed to them and any join request they filed. References to them *inside other people's* pending entries (who invited or sponsored whom) are deliberately kept so a third party never loses an invitation, and render with a `null` name.
- Session TTLs (`access_ttl`, `refresh_ttl`, `reuse_grace`) and the registration policy (`allow_self_registration`, `min_password_length`, `max_users`, `throttle.per_ip_per_minute`, `throttle.global_per_hour` — 0 disables a limit) live in `secure/management/config/auth.php` (gitignored, auto-created from `.example`); runtime session state lives in `sessions.json` next to it (machine-written, hashed at rest — never edit).
- Authorization is **per project**: a user's role comes from the target project's `config/members.json`. The six fixed roles (`viewer` … `owner`) are defined by trust-coherent command **categories** in `categories.php`; `roles.php` grants each role a `rank` and its categories, expanded to a per-command allowlist at load time. There is no superadmin and no custom roles.
- Default install ships with a placeholder account whose default password is documented in `users.php.example`; the admin panel warns until you change its password and id.

## Response shape

Every command — success or failure — returns the same JSON envelope (built by `secure/src/classes/ApiResponse.php`):

```json
{
    "status":  201,
    "code":    "route.created",
    "message": "Route created successfully",
    "data":    { "route": "contact" }
}
```

| Field | Type | Notes |
|---|---|---|
| `status` | integer | HTTP-style status code (200, 201, 400, 401, 403, 404, 409, 422, 500…). Mirrors the actual HTTP response code. |
| `code` | string | Stable dotted identifier (`route.created`, `validation.required`, `auth.forbidden`…). Suitable for client-side branching and i18n. |
| `message` | string | Human-readable summary. Localized when called from the admin panel. |
| `data` | object\|null | Command-specific payload on success. |
| `errors` | array (optional) | On validation failures, structured entries with `field` / `value` / `reason`. |

There is **no separate error envelope**. A failed call uses the same four fields with a non-2xx `status`, an error `code`, and `data: null`. This keeps clients simple: parse once, branch on `status`.

## Command catalogue

The 178 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

> **AI is browser-direct (BYOK).** There is no `callAi` / `testAiKey` / `detectProvider` / `listAiProviders` server command — the admin panel calls AI providers directly from the browser using credentials stored in `aiConnectionsV3` (localStorage). The Management API only handles workflow specs and command execution.

Each row enumerates the commands in that category — comma-separated, alphabetical within the category — followed by what the category covers. Categories are derived from `secure/management/routes.php`; if a command isn't here, it isn't routed.

| Category | Commands & detail |
|---|---|
| **Meta** | `help` — self-documenting endpoint, callable without authentication. |
| **Session & account** | `login`, `refreshSession`, `logoutSession`, `register`, `changePassword`, `deleteMyAccount` — username+password login → access + refresh pair; refresh rotation with reuse-detection (family revoke); explicit logout; flag-gated flood-controlled self-registration (public); self-service password change and account deletion (authenticated — both require the current password; deletion also needs `confirm=true`, is refused while the caller solely owns a project, and ends every session). See *Authentication* above. |
| **Pages** | `listPages`, `createAlias`, `deleteAlias`, `listAliases`, `editFavicon`, `editTitle` — page metadata, title, favicon, alias routes. |
| **Routes & sitemap** | `addRoute`, `deleteRoute`, `getRoutes`, `getSiteMap`, `setRouteLayout`, `analyzeReachability` — URL routing tree CRUD, sitemap export, dead-route audit. `setRouteResolver` is under "Server-side data resolvers" below since the route layer just hosts the resolver config. |
| **Structure** | `getStructure`, `editStructure`, `addNode`, `addComplexElement`, `addComponentToNode`, `editComponentToNode`, `editNode`, `moveNode`, `deleteNode`, `duplicateNode` — edit nodes inside a page tree. |
| **Components** | `listComponents`, `getComponent`, `findComponentUsages`, `renameComponent`, `duplicateComponent` — reusable component definitions (NOT the in-tree snippet shortcuts below). |
| **Translations** | `getTranslation`, `getTranslations`, `getTranslationKeys`, `setTranslationKeys`, `deleteTranslationKeys`, `cleanOrphanTranslations`, `validateTranslations`, `getUnusedTranslationKeys`, `analyzeTranslations`, `importStructureTranslations` — translation keys per language; audits + structure-aware bulk operations. |
| **Languages** | `getLangList`, `getLanguageList`, `addLang`, `deleteLang`, `setDefaultLang`, `setMultilingual`, `checkStructureMulti` — site language config (add/remove, default, multilingual gate). |
| **Assets** | `uploadAsset`, `listAssets`, `editAsset`, `deleteAsset` — upload, list, delete files in `public/assets/{images,font,audio,videos}/`, with metadata (alt text, dimensions). |
| **Styles** | `getStyles`, `editStyles`, `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule` — `style.css` blocks + scoped CSS rule management. |
| **CSS variables** | `getRootVariables`, `setRootVariables`, `setThemeMode` — CSS custom-property registries used by the color picker and theme switcher. |
| **Animations** | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes`, `getAnimatedSelectors` — named keyframes + per-element animation bindings. |
| **Builds** | `build`, `listBuilds`, `getBuild`, `deleteBuild`, `cleanBuilds`, `deployBuild`, `downloadBuild` — compile a project to a static `public/build/<name>/` deliverable; list / deploy (to the install root, or a root listed in `secure/management/config/deploy-roots.php`) / clean. |
| **Projects** | `listProjects`, `getActiveProject`, `getMySpaceUsage`, `setSelectedProject`, `switchProject`, `createProject`, `cloneProject`, `deleteProject` — per-project CRUD under `secure/projects/`. `listProjects` is membership-filtered: it returns only the caller's projects, each with `my_role` (no all-projects view). `getMySpaceUsage` answers "how much disk do my projects use": an owner-wide total, a category breakdown, and one row per project, aggregated across every project where the caller's role is `owner`. It is global (an owner-wide total is not a fact about any one project, so it carries no marker) and takes no project parameter; ownership is resolved per project from `members.json`, so it can only ever describe projects you own — owning nothing returns a zeroed report. Sizes come from a short-lived shared cache (`refresh=true` forces a re-walk); the project **set** is never cached, so gaining, losing or transferring a project is reflected immediately. Names of backups and exports are never returned — only sizes and counts. For one project in depth, use the project-scoped `getSizeInfo`. Two distinct project pointers: `setSelectedProject` sets the caller's **edited** project (per-user, member-only — drives the admin panel + preview), while `switchProject` sets the globally **served** main project. `switchProject` is **owner-only and project-scoped** — targeted as `/management/p/<projectId>/switchProject`, so only an owner of that project may make it the served main; a body `project` that disagrees with the targeted one is refused. It rewrites `target.php` and materialises that project's static surface into the live `public/` folder; it does not copy anything back into the previously served project (each project's own `public/` is authoritative, and asset/style writes mirror into it as they happen). Deleting the served project clears the pointer instead of promoting another project. `createProject`'s `switch_to` sets only the creator's edited project — it never changes the served main. `setProjectVisibility` (`private`/`public`, **owner-only**) flips whether the project is served to the public internet via surface-B — a graver exposure decision than the admin-tier `setJoinPolicy`, so it sits at the delete/transfer tier; making a project private while its join policy is open re-creates the knockable-by-id state (an advisory note is returned). See [ARCHITECTURE.md §6](ARCHITECTURE.md). |
| **Project members** | `listMembers`, `getProjectRoster`, `inviteMember`, `cancelInvitation`, `changeMemberRole`, `removeMember`, `transferOwnership`, `approveJoinRequest`, `denyJoinRequest`, `proposeMember`, `setJoinPolicy` — the project's roster, on a consent model: `getProjectRoster` is the reduced roster for EVERY member rank — active members only (`{user_id, name, role, rank, is_owner}`, rank-descending), no pending queue, so any member can see who is on the project with them; the full `listMembers` (roster + pending invitations/requests) stays admin/owner. Otherwise: an admin/owner *invites* an existing account (by `user_id`, discovered via `findUser`) and membership materializes only when the invitee accepts. Incoming join requests and member proposals (see *My memberships*) are adjudicated with `approveJoinRequest` (a self-request joins immediately; a sponsored proposal converts into a real invitation carried by the approver's rank, `sponsored_by` kept — the approver may name the `role` to grant, defaulting to the requested/proposed one, so approval and role assignment are one atomic, rank-checked step) and `denyJoinRequest` (mandatory `note` — a refusal always carries its reason; a denied self-request leaves a dismissable `refused` notice, a denied proposal tells the never-engaged target nothing). `proposeMember` is the sponsor lane: ANY member — viewer included — vouches an outsider with a mandatory note, at a role no higher than the sponsor's own rank; nothing is granted and the person is told nothing until validation. `setJoinPolicy` (`open`/`closed`, default closed, admin+) gates only the self-service request door — proposals always reach the queue, and closing never purges pending requests. Rank rules throughout: you can only offer, change to, cancel, approve, deny, or remove roles of strictly lower rank than your own (nobody can veto what they could not grant); `cancelInvitation` withdraws invites only (requests are adjudicated, never silently cancelled); the owner's role is immutable except via `transferOwnership` (owner-only, member-only target, `confirm: true`, departing owner keeps `old_owner_role` — default `admin`). Members are referenced as `{user_id, name}` — the public display name and the opaque id; the private login username never appears. `reconcileMemberships` (admin/owner) is the maintenance sweep: it heals every member's users.php membership cache for the project against the authoritative members.json — rebuilding derivable statuses (member / pending) while **preserving** the non-derivable tombstones (`refused` / `removed` / `deleted`, which live only in the cache) and pruning stale positives; it aborts rather than wipe real memberships if the authority is unreadable. All are project-scoped on the URL marker (`/management/p/<projectId>/…`) and ignore any project named in the body. |
| **My memberships** | `findUser`, `listMyInvitations`, `listMyProposals`, `acceptInvitation`, `declineInvitation`, `leaveProject`, `dismissProjectNotice`, `requestToJoin`, `withdrawJoinRequest` — the caller's own membership surface (global, any authenticated user; self-service is deliberately not project-marker-scoped since an invitee is not yet a member). `findUser` = exact public-name lookup returning `{user_id, name}` matches (names are not unique — the id disambiguates). `listMyInvitations` = the inbox: pending invitations, the caller's own join requests, and terminal notices (`removed` / `deleted` / `refused`) awaiting `dismissProjectNotice` — all pending detail read from the project's authoritative members.json. `acceptInvitation` re-validates the inviter's authority at accept time — a demoted or removed inviter's offer is void and never grants. `requestToJoin` (mandatory `note`, fixed `viewer` ask) knocks on a project whose join policy is open: on a **private** open project a successful knock confirms the project exists (an explicit owner trade, flagged by `setJoinPolicy`), and the requester's own inbox shows the project *id* — never the site name — until membership; a private **closed** project answers exactly like a nonexistent one. A standing `refused`/`removed` notice blocks re-requesting until dismissed. `withdrawJoinRequest` retracts any pending ask the caller authored (their own request, or a proposal they sponsored) with no notice kept. `listMyProposals` is the sponsor's view of their own outgoing proposals across their projects — pending ones awaiting validation plus approved ones still awaiting the person's answer (`sponsored_by` attribution); a proposal absent from both lists was adjudicated (refusal reasons are not delivered to sponsors). The owner cannot `leaveProject` (transfer ownership first). |
| **Backups** | `backupProject`, `listBackups`, `restoreBackup`, `deleteBackup` — snapshot / restore (configurable scope). All are project-scoped on the URL marker and act only on the targeted project; a project named in the body must match the marker or the call is refused (`400 project.mismatch`). Backups never include `config/members.json`, so a restore never touches membership. |
| **Export / Import** | `exportProject`, `downloadExport`, `clearExports`, `importProject` — pack a project as ZIP for portability; import a ZIP back. `exportProject` is project-scoped (marker-bound, like the backups) and **excludes `config/members.json`** from the archive (the membership graph + private invitation notes never travel). `importProject` is **global** (create-from-archive, any authenticated user, like `createProject`): it mints a NEW project and **birth-writes the importer as sole owner**, discarding any `members.json` the archive carried (an untrusted roster is never accepted). `cloneProject` (see *Projects*) does the same birth-write — a clone/import is a fresh project owned solely by you; collaborators are not carried over. |
| **Roles** | `listRoles`, `getMyPermissions` — read the fixed roles and your own effective permissions. (`createRole` / `editRole` / `deleteRole` are disabled: roles are a fixed set, not customisable.) |
| **Snippets** | `listSnippets`, `getSnippet`, `createSnippet`, `deleteSnippet`, `duplicateSnippet`, `insertSnippet`, `injectSnippetCss` — reusable in-tree snippets (nav, cards, forms…); insert / inject into a page's structure. |
| **JS functions & data bindings** | `listJsFunctions`, `listDataBindings` — catalog read endpoints. `listJsFunctions` returns the QS.* verb catalog (consumed by the admin picker; see [ADMIN_PANEL.md §9.9](ADMIN_PANEL.md)). `listDataBindings` returns the `data-qs-*` attribute catalog. |
| **Interactions** | `listInteractions`, `addInteraction`, `editInteraction`, `deleteInteraction` — bind triggers (click, hover, scroll…) to verb chains on a node. |
| **Page events** | `getPageEvents`, `addPageEvent`, `editPageEvent`, `deletePageEvent` — page-level lifecycle hooks (`onload`, `onresize`, `onscroll`) per route. |
| **API endpoints** | `listApiEndpoints`, `getApiEndpoint`, `addApi`, `editApi`, `deleteApi`, `testApiEndpoint` — manage external API integrations callable from page interactions; live test endpoint with replay capture. |
| **Authentication** | `listOAuthProviders`, `addOAuthProvider`, `editOAuthProvider`, `deleteOAuthProvider` — OAuth provider preset CRUD. `listOAuthProviders` returns the union of admin + per-project presets (from `oauth-presets.json`) with a per-provider `setup` summary describing whether the `/auth/oauth/<provider>/start` + `/callback` routes already exist. Drives the `oauth-button` Complex Element wizard. The OAuth flow itself runs through route-resolvers (`oauth-start` / `oauth-callback` / `oauth-logout` kinds) attached via `setRouteResolver` — not standalone commands. See [ADMIN_PANEL.md §9.5 "Tier 4 — OAuth"](ADMIN_PANEL.md). |
| **Storage & consent** | `listStorageItems`, `addStorageItem`, `editStorageItem`, `deleteStorageItem`, `setStorageDescLang`, `scanStorageUsage`, `getConsentStatus`, `generateConsentLayer`, `generateCookiePolicy`, `deleteCookiePolicy` — the browser-storage registry (every `localStorage` / `sessionStorage` / `cookie` key the site uses, with a GDPR `category` + `retention`) and the cookie-consent layer generated from it. CRUD the registry (`*StorageItem`); descriptions are keyed in `translate/` and authored in one description language (`setStorageDescLang` moves them); `scanStorageUsage` reconciles declared keys against actual build usage (ok / undeclared / dangling-read / orphan); `generateConsentLayer` builds the banner + preferences popup (rendered globally like menu/footer) and enables runtime write-gating; `generateCookiePolicy` writes a deterministic cookie-policy page from the registry; `getConsentStatus` + `deleteCookiePolicy` drive the `/admin/storage` consent management UI. See [ADMIN_PANEL.md §9.10](ADMIN_PANEL.md). |
| **Privacy** | `getPrivacyStatus`, `setCollectedDatum`, `deleteCollectedDatum`, `setPrivacyDescLang`, `setPrivacyMapping`, `setPrivacyHost`, `setPrivacyCookieSection`, `generatePrivacyPolicy`, `deletePrivacyPolicy` — the data-**sharing** half of compliance (what the site sends to APIs + sign-in providers), a per-project registry (`data/privacy.json`) reconciled against a scan of the API registry. `getPrivacyStatus` returns the registry joined with the scan — outbound `(endpoint, field)` atoms from declared `parameters` + `requestSchema`, coverage (unmapped atoms / body endpoints with no schema / unclassified hosts), and OAuth/magic-link auto-seed. Author "data collected" entries (`setCollectedDatum` / `deleteCollectedDatum`, prose keyed in `translate/`; `setPrivacyDescLang` moves the language), map atoms to them (`setPrivacyMapping`), classify each API host as your server or a third party (`setPrivacyHost`), then `generatePrivacyPolicy` writes a deterministic page (collect table + per-third-party sharing + OAuth + cookie cross-link + disclaimer); `setPrivacyCookieSection` chooses whether the page links / hints / omits the cookie policy; `deletePrivacyPolicy` removes it. See [ADMIN_PANEL.md §9.11](ADMIN_PANEL.md). |
| **State stores** | `getStateStores`, `setStateStores` — per-page named client state bound to one API endpoint; fields with direction (request/response/both), init source, and response path. Gives interactions memory (pagination, search, filters, infinite scroll). |
| **Server-side data resolvers** | `setRouteResolver`, `cleanResolverCache` — per-route declaration that fires a server-side fetch BEFORE template render and exposes the response as template variables (SEO/AEO/first-paint payoff). `setRouteResolver` is idempotent six-shape (set / clear / patch / append / remove single slot). File-based cache with TTL + auth-cacheable gating; manual invalidation via `cleanResolverCache`. Read via `getSiteMap` (per-route subset under `routeResolvers`). See [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md). |
| **System updates** | `checkForUpdates` — inspect the engine version and report whether a newer release exists. Applying an update is an operator/CLI action, not an API command. |
| **System** | `getCommandHistory`, `clearCommandHistory`, `getSizeInfo`, `getIframeSandbox`, `setIframeSandbox`, `removeIframeSandbox` — engine-level state (audit log of executed commands, project size info, iframe sandbox config for the visual editor). |
| **Workflow tooling** | `listWorkflowBlocks`, `lintWorkflows` — enumerate reusable prompt blocks in `secure/admin/workflows/{blocks,pins,warnings,examples}/` for the editor's multi-select dropdowns; report paragraphs that occur in 3+ workflow templates as candidates for extraction. Both read QuickSite's own shipped catalogue rather than any project's data, so both are global (any authenticated user) and take no project marker. |

## Calling the API

Any HTTP client works. Examples:

```bash
# List routes
curl -H "Authorization: Bearer $TOKEN" http://local.quicksite/management/getRoutes

# Add an exact route (POST + JSON body)
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name":"about","title":"About"}' \
     http://local.quicksite/management/addRoute

# Add a parameterised route — the ':slug' segment captures any URL value
# at request time (one template serves many URLs). See ARCHITECTURE §5.3
# for the matching algorithm + how params flow to PHP / qs.js.
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name":"products/:slug","title":"Product"}' \
     http://local.quicksite/management/addRoute
# Response data may carry a 'warnings' array when the new route shadows
# exact siblings (curated landing + param catch-all is supported and not
# blocked — the warning surfaces intent so the user can confirm).

# Read the live spec for a command
curl http://local.quicksite/management/help/addRoute

# Attach a server-side data resolver to a route (single — scalar shape).
# Body shape: {route, resolver}. The resolver fires once per request,
# server-side, before the page template renders.
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "route": "products/:slug",
       "resolver": {
         "endpoint": "@products-api/get-product",
         "inputs":   { "id": "param:slug" },
         "expose":   { "product": "data.product" },
         "cacheTTL": 300
       }
     }' \
     http://local.quicksite/management/setRouteResolver

# Same command, multi-resolver shape (array). Resolvers fire concurrently
# via curl_multi_*. Save is REJECTED with reason 'collision' if any two
# resolvers expose the same flat-namespace key — disambiguate by renaming
# OR by using the namespaced address ($r0.title / $r1.title in templates,
# window.QS_RESOLVED_BY_INDEX.r0.title / .r1.title in JS).
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{
       "route": "compare/:a/vs/:b",
       "resolver": [
         {"endpoint":"@products-api/get-product","inputs":{"id":"param:a"},"expose":{"productA":"data.product"}},
         {"endpoint":"@products-api/get-product","inputs":{"id":"param:b"},"expose":{"productB":"data.product"}}
       ]
     }' \
     http://local.quicksite/management/setRouteResolver

# Patch one resolver slot in a multi-resolver route (the `index` param
# targets a specific slot). Same command supports append (index === length),
# remove (no `resolver` body + `index`), and clear-all (no `resolver` + no
# `index`) — see help/setRouteResolver for the full body-shape matrix.
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"route":"compare/:a/vs/:b","resolver":{"endpoint":"@products-api/get-product","inputs":{"id":"param:a"},"expose":{"productA":"data.product"}},"index":0}' \
     http://local.quicksite/management/setRouteResolver
```

## Internals

- Command handlers live in `secure/management/command/<command>.php`, one file per command.
- The whitelist of valid commands is `secure/management/routes.php` (178 entries — this file is the single source of truth for which commands exist).
- Shared helpers live in `secure/src/functions/utilsManagement.php` (e.g., `varExportNested()`, `SPECIAL_PAGES`, role helpers).
- Internal callers (visual editor data gathering, workflow steps) bypass the HTTP layer and invoke commands through `secure/src/classes/CommandRunner.php`. CommandRunner carries a **hardcoded read-only allowlist** (~35 `get*` / `list*` commands) it will execute internally; membership and other mutating commands are not on it.
- Workflow execution adds its own role check via `WorkflowManager::setTokenInfo()` so steps respect the calling token's permissions.

## Update detection

Two commands manage in-place upgrades against the GitHub repo:

| Command | Method | Notes |
|---|---|---|
| `checkForUpdates` | GET | Reads the local `VERSION` file, fetches the latest GitHub release tag, compares with PHP's `version_compare`. Returns `update_available`, `current_version`, `latest_version`, `release_url`, `install_method` (`git`\|`zip`). |

Applying an update is **not** an API command. It replaces the code that runs every project on the installation, and authority in QuickSite is per-project — a project role cannot sanely imply "may rewrite the shared engine". `secure/management/command/applyUpdate.php` is therefore unrouted and invoked from the deploy/CLI side; the panel still reports available updates through `checkForUpdates`.

`version_compare` natively orders pre-release tags correctly: `1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0`. The installed version is read from the local `VERSION` file, so that file's contents are what `checkForUpdates` compares against the latest GitHub release tag.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — where the API sits in the three-layer model.
- [docs/WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — how multi-command workflows compose API calls.
- [docs/PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — on-disk layout.
