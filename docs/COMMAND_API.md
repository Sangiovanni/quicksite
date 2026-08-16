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

returns the per-command reference — parameters, examples, validation rules, and error codes. For a specific command:

```
GET /management/help/addRoute
```

The `help` endpoint is publicly accessible, takes no token, and is the contract for how a command behaves: what it accepts, what it answers, and how it fails. This document is a high-level map on top of it. Which commands *exist* is decided elsewhere — `secure/management/routes.php` is the routable allowlist, and a command is reachable whether or not `help` carries an entry for it.

## Authentication

- Credentials are **username + password** (`users.php` stores a `password_hash`; a `null` hash marks an externally-managed account that cannot use password login). The username is the **private** login identifier — never shown to other users; public identity is the display name + the user id (there is no email field). `POST /management/login` exchanges the credentials for a **session**:
  ```json
  { "username": "your-username", "password": "…" }
  → sets the session cookie `QSSESSID`, and returns
    { "token_type": "Bearer", "session_token": "…64 hex…", "user": { … } }
  ```
  Add `"remember": true` to give the cookie a lifetime so it survives a browser restart.
- Every other endpoint requires **both halves**: the session cookie, and that session token in a header.
  ```
  Cookie: QSSESSID=…
  Authorization: Bearer <session_token>
  ```
  A command-line client therefore needs a cookie jar (`curl -c jar -b jar`) as well as the header. Both are required on purpose. The cookie alone would let any page on the internet drive this API through a visitor's browser — browsers send cookies on cross-site requests automatically. The token alone grants nothing: it is meaningless without the session it belongs to. Together they say the request came from something that could read a page of this session, which another origin cannot do. A refusal is always `401 auth.unauthorized`: there is nothing to refresh, so it always means sign in again. `logoutSession` ends a session explicitly, and `logoutSession {"everywhere": true}` ends every session of the account.
- Three commands are public: `help`, `login`, `register`. `login` is self-authenticating (the credentials in the body ARE the authentication); `register` is self-gating — it enforces the `registration.allow_self_registration` flag server-side (default: **disabled**) plus flood controls (attempts per IP per minute, successful registrations per hour install-wide, and an optional absolute account cap). Failed logins are throttled per username (doubling cooldown after 5 attempts). A duplicate username at `register` returns the same success response as a real creation — login identifiers are private, so no account-existence oracle.
- `changePassword` (authenticated) changes the caller's own password: it requires the current password, shares the login throttle, and ends every **other** session of the user on success (the session performing the change survives).
- `deleteMyAccount` (authenticated) permanently deletes the caller's **own** account: it requires the current password plus `confirm=true`, and ends every session. There is no command that deletes someone else's account — authorization in QuickSite is per project, so the ways to part with a person are `removeMember` (evict them from one project) and, for the operator, editing `users.php` directly. Deletion is **refused** while the caller is the sole owner of any project: the response lists them, and each must be handed over with `transferOwnership` or destroyed with `deleteProject` first. On success the caller is removed from every project they belong to, along with any invitation addressed to them and any join request they filed. References to them *inside other people's* pending entries (who invited or sponsored whom) are deliberately kept so a third party never loses an invitation, and render with a `null` name.
- Session lifetimes (`idle_ttl` — inactivity before a session stops being accepted, slid forward as the caller works; `remember_ttl` — how long a "remember me" cookie survives a browser restart; `sweep_divisor` — the 1-in-N chance that a login also tidies the session store, 0 to never) and the registration policy (`allow_self_registration`, `min_password_length`, `max_users`, `throttle.per_ip_per_minute`, `throttle.global_per_hour` — 0 disables a limit) live in `secure/management/config/auth.php` (gitignored, auto-created from `.example`). Sessions are PHP's own, written under `secure/tmp/sessions` rather than the shared system path so another application on the same host cannot garbage-collect them out from under a working user. QuickSite tidies that directory itself, on its own idle rule — after a login on the die above, or on demand with `php secure/cli/session-sweep.php` (add `--dry-run` to see what would go). It is a script and not a command because clearing the session store is installation-wide, and every permission here is per project.
- Presenting a `QSSESSID` that names no session gets no session: the call is answered as an anonymous one and no new cookie is set. A client that discards cookies therefore has to log in again rather than being handed a fresh empty session on every request.
- Authorization is **per project**: a user's role comes from the target project's `config/members.json`. The six fixed roles (`viewer` … `owner`) are defined by trust-coherent command **categories** in `categories.php`; `roles.php` grants each role a `rank` and its categories, expanded to a per-command allowlist at load time. There is no superadmin and no custom roles.
- There is **no default account and no default password**. While no account exists, every admin URL shows a first-run page that creates the first one. It asks for a **setup token**, which the engine writes to `secure/management/config/setup-token.txt` — being able to read that file is the authorisation, so no command line is needed. The token is destroyed on use and the page disappears permanently once an account exists. The requirement is enforced at the shared account-creation path, not in the page: while the registry is empty, `register` cannot create an account either (403 `auth.setup_required`), whatever `allow_self_registration` says. To bootstrap by hand instead, copy `users.php.example` to `users.php` and follow the instructions in that file.

## Response shape

Every command — success or failure — returns the same JSON envelope. Command results are assembled by `secure/src/classes/ApiResponse.php`; a few refusals raised before dispatch, such as a missing `Authorization` header, are written directly but use the same shape.

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
| `status` | integer | HTTP-style status code (200, 201, 204, 207, 400, 401, 403, 404, 409, 422, 500…). Mirrors the actual HTTP response code. |
| `code` | string | Stable dotted identifier (`route.created`, `validation.required`, `auth.forbidden`…). Suitable for client-side branching and i18n. |
| `message` | string | Human-readable summary. Localized when called from the admin panel. |
| `data` | object (optional) | Command-specific payload. **Omitted entirely when there is nothing to report** — read it as "absent or an object", not as "always present, sometimes null". |
| `errors` | array (optional) | On validation failures, structured entries with `field` / `value` / `reason`. Omitted when empty. |
| `hint` | string (optional) | Appears on some `401` refusals with a concrete next step (which header to send, for example). Advisory text for a human — never branch on it. |

There is **no separate error envelope**. A failed call uses the same `status` / `code` / `message` triple with a non-2xx status and an error code, and carries `data` only when it has something useful to say — a `404` for an unknown command, for instance, echoes back the name that was requested. This keeps clients simple: parse once, branch on `status` or `code`.

If the envelope itself cannot be serialised — malformed UTF-8 somewhere in the payload, or a structure nested deeper than JSON encoding allows — the response becomes a `500` with code `server.internal_error` rather than the original status with an empty body. A client never receives a `2xx` whose body is missing.

### File paths in a response are relative

Whenever the envelope names a file or directory — `data.file`, `data.path`, `data.build_directory`, a path interpolated into `message`, a `value` inside `errors` — it is **relative, never absolute**. The installation's location on disk is not part of the API.

| Where the file lives | How it appears | Example |
|---|---|---|
| Inside the project the request targeted | Relative to that project's root | `templates/model/json/pages/home/home.json`, `public/style/style.css` |
| Elsewhere in the installation | Relative to the installation root | `secure/projects/other-site`, `public/build/b.zip` |
| The project root or installation root itself | A single dot | `.` |

A rewritten path always uses `/` as its separator, on every platform. A path the **caller** supplied — a deploy target outside the installation, for instance — is left exactly as given, separators included, since it discloses nothing the caller did not already have.

The rule applies to the whole envelope, not just to fields that look like paths: `data` at any depth (values *and* keys, so a payload keyed by filename is covered), `errors`, and the `message` string, which often interpolates a path into a sentence. It applies identically however a command's result leaves the engine — over HTTP, through the admin panel's internal relay, or through `CommandRunner`.

The relative form is the same in development and production. Only *diagnostic* detail varies by environment: an uncaught exception's message and a fatal's file/line appear in a response body only when `secure/management/config/environment.php` declares the install as development, and go to the PHP error log otherwise.

### Partial success (`207`)

A command that acts on a **set** — deleting several builds, clearing a folder of archives, sweeping orphaned files — can finish with some members done and some refused. Those commands answer `207` with the code `operation.partial_success`:

```json
{
    "status":  207,
    "code":    "operation.partial_success",
    "message": "4 of 5 export file(s) deleted; 1 could not be removed",
    "data":    { "deleted_count": 4, "failed_count": 1, "failed_files": ["…"] },
    "errors":  [ { "file": "…", "reason": "delete_failed" } ]
}
```

The work that succeeded is real and is not rolled back. `errors` names every member that did not complete. When **nothing** in the set succeeded, the command answers `5xx` instead — a total failure is not a partial success.

`207` is a 2xx, so a client testing only "was this a 2xx" will read it as success. **Branch on `code`, or on `data.failed_count`, whenever a command can act on more than one thing.** Commands that use it: `clearExports`, `deleteBuild`, `cleanBuilds`.

`cleanOrphanTranslations` is the exception: it reports the same `operation.partial_success` code with `errors` populated, but keeps `status: 200`, because it runs as one phase of a longer chain that stops on a non-2xx.

## Command catalogue

The 176 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

> **AI is browser-direct (BYOK).** There is no `callAi` / `testAiKey` / `detectProvider` / `listAiProviders` server command — the admin panel calls AI providers directly from the browser using credentials stored in `aiConnectionsV3` (localStorage). The Management API only handles workflow specs and command execution.

Each row enumerates the commands in that category — comma-separated, alphabetical within the category — followed by what the category covers. Categories are derived from `secure/management/routes.php`; if a command isn't here, it isn't routed.

| Category | Commands & detail |
|---|---|
| **Meta** | `help` — self-documenting endpoint, callable without authentication. |
| **Session & account** | `login`, `logoutSession`, `register`, `changePassword`, `deleteMyAccount` — username+password login → a session (cookie + session token); explicit logout, optionally everywhere; flag-gated flood-controlled self-registration (public); self-service password change and account deletion (authenticated — both require the current password; deletion also needs `confirm=true`, is refused while the caller solely owns a project, and ends every session). See *Authentication* above. |
| **Pages** | `listPages`, `createAlias`, `deleteAlias`, `listAliases`, `editFavicon`, `editTitle` — page metadata, title, favicon, alias routes. |
| **Routes & sitemap** | `addRoute`, `deleteRoute`, `getRoutes`, `getSiteMap`, `setSiteMapConfig`, `setRouteLayout`, `analyzeReachability` — URL routing tree CRUD, sitemap export, dead-route audit. `getSiteMap` is **read-only**; `setSiteMapConfig` owns the two sitemap writes (the excluded-routes / custom-URLs sidecar, and publishing the project's own `public/sitemap.txt`) and needs a write role, because both decide what the published sitemap contains. `getSiteMap` URLs are absolute: a `baseUrl` body param (validated URL) wins; otherwise the deployment's `QS_PUBLIC_BASE_URL` env var, else the project's own `/p/<id>/` URL on the install. `setRouteResolver` is under "Server-side data resolvers" below since the route layer just hosts the resolver config. |
| **Structure** | `getStructure`, `editStructure`, `addNode`, `addComplexElement`, `addComponentToNode`, `editComponentToNode`, `editNode`, `moveNode`, `deleteNode`, `duplicateNode` — edit nodes inside a page tree. |
| **Components** | `listComponents`, `getComponent`, `findComponentUsages`, `renameComponent`, `duplicateComponent` — reusable component definitions (NOT the in-tree snippet shortcuts below). |
| **Translations** | `getTranslation`, `getTranslations`, `getTranslationKeys`, `setTranslationKeys`, `deleteTranslationKeys`, `cleanOrphanTranslations`, `validateTranslations`, `getUnusedTranslationKeys`, `analyzeTranslations`, `importStructureTranslations` — translation keys per language; audits + structure-aware bulk operations. |
| **Languages** | `getLangList`, `getLanguageList`, `addLang`, `deleteLang`, `setDefaultLang`, `setMultilingual`, `checkStructureMulti` — site language config (add/remove, default, multilingual gate). |
| **Assets** | `uploadAsset`, `listAssets`, `editAsset`, `deleteAsset` — upload, list, delete files in the project's own `public/assets/{images,font,audio,videos}/`, with metadata (alt text, dimensions). |
| **Styles** | `getStyles`, `editStyles`, `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule` — `style.css` blocks + scoped CSS rule management. |
| **CSS variables** | `getRootVariables`, `setRootVariables`, `setThemeMode` — CSS custom-property registries used by the color picker and theme switcher. |
| **Animations** | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes`, `getAnimatedSelectors` — named keyframes + per-element animation bindings. |
| **Builds** | `build`, `listBuilds`, `getBuild`, `deleteBuild`, `cleanBuilds`, `deployBuild`, `downloadBuild` — compile a project to a static deliverable under its own `public/build/<name>/`; list / deploy (to the install root, or a root listed in `secure/management/config/deploy-roots.php`) / clean. `build` copies a project's `style/` and `assets/` through a **publish allowlist**, so a file existing inside a project and a file being published to a web-served directory are two separate decisions — anything not publishable is left out of the build and reported in the response. The permitted extensions are configurable in `secure/management/config/import-policy.php` (see *Archive import limits* below). |
| **Projects** | `listProjects`, `getMySpaceUsage`, `setSelectedProject`, `createProject`, `cloneProject`, `deleteProject` — per-project CRUD under `secure/projects/`. No project is privileged, so none of these decides what a domain serves: that is a web-server mapping (see ARCHITECTURE.md). `listProjects` is membership-filtered: it returns only the caller's projects, each with `my_role` (no all-projects view). `getMySpaceUsage` answers "how much disk do my projects use": an owner-wide total, a category breakdown, and one row per project, aggregated across every project where the caller's role is `owner`. It is global (an owner-wide total is not a fact about any one project, so it carries no marker) and takes no project parameter; ownership is resolved per project from `members.json`, so it can only ever describe projects you own — owning nothing returns a zeroed report. Sizes come from a short-lived shared cache (`refresh=true` forces a re-walk); the project **set** is never cached, so gaining, losing or transferring a project is reflected immediately. Names of backups and exports are never returned — only sizes and counts. For one project in depth, use the project-scoped `getSizeInfo`. `setSelectedProject` sets the caller's **edited** project (per-user, member-only — drives the admin panel + preview). It is the only project pointer there is: what a production domain serves is decided by the web server, not by a command, so no command can publish a project at a domain root (see [ARCHITECTURE.md §6](ARCHITECTURE.md)). `createProject`'s `switch_to` likewise sets only the creator's edited project. `setProjectVisibility` (`private`/`public`, **owner-only**) flips whether the project is served to the public internet via surface-B — a graver exposure decision than the admin-tier `setJoinPolicy`, so it sits at the delete/transfer tier; making a project private while its join policy is open re-creates the knockable-by-id state (an advisory note is returned). See [ARCHITECTURE.md §6](ARCHITECTURE.md). |
| **Project members** | `listMembers`, `getProjectRoster`, `inviteMember`, `cancelInvitation`, `changeMemberRole`, `removeMember`, `transferOwnership`, `approveJoinRequest`, `denyJoinRequest`, `proposeMember`, `setJoinPolicy` — the project's roster, on a consent model: `getProjectRoster` is the reduced roster for EVERY member rank — active members only (`{user_id, name, role, rank, is_owner}`, rank-descending), no pending queue, so any member can see who is on the project with them; the full `listMembers` (roster + pending invitations/requests) stays admin/owner. Otherwise: an admin/owner *invites* an existing account (by `user_id`, discovered via `findUser`) and membership materializes only when the invitee accepts. Incoming join requests and member proposals (see *My memberships*) are adjudicated with `approveJoinRequest` (a self-request joins immediately; a sponsored proposal converts into a real invitation carried by the approver's rank, `sponsored_by` kept — the approver may name the `role` to grant, defaulting to the requested/proposed one, so approval and role assignment are one atomic, rank-checked step) and `denyJoinRequest` (mandatory `note` — a refusal always carries its reason; a denied self-request leaves a dismissable `refused` notice, a denied proposal tells the never-engaged target nothing). `proposeMember` is the sponsor lane: ANY member — viewer included — vouches an outsider with a mandatory note, at a role no higher than the sponsor's own rank; nothing is granted and the person is told nothing until validation. `setJoinPolicy` (`open`/`closed`, default closed, admin+) gates only the self-service request door — proposals always reach the queue, and closing never purges pending requests. Rank rules throughout: you can only offer, change to, cancel, approve, deny, or remove roles of strictly lower rank than your own (nobody can veto what they could not grant); `cancelInvitation` withdraws invites only (requests are adjudicated, never silently cancelled); the owner's role is immutable except via `transferOwnership` (owner-only, member-only target, `confirm: true`, departing owner keeps `old_owner_role` — default `admin`). Members are referenced as `{user_id, name}` — the public display name and the opaque id; the private login username never appears. `reconcileMemberships` (admin/owner) is the maintenance sweep: it heals every member's users.php membership cache for the project against the authoritative members.json — rebuilding derivable statuses (member / pending) while **preserving** the non-derivable tombstones (`refused` / `removed` / `deleted`, which live only in the cache) and pruning stale positives; it aborts rather than wipe real memberships if the authority is unreadable. All are project-scoped on the URL marker (`/management/p/<projectId>/…`) and ignore any project named in the body. |
| **My memberships** | `findUser`, `listMyInvitations`, `listMyProposals`, `acceptInvitation`, `declineInvitation`, `leaveProject`, `dismissProjectNotice`, `requestToJoin`, `withdrawJoinRequest` — the caller's own membership surface (global, any authenticated user; self-service is deliberately not project-marker-scoped since an invitee is not yet a member). `findUser` = exact public-name lookup returning `{user_id, name}` matches (names are not unique — the id disambiguates). `listMyInvitations` = the inbox: pending invitations, the caller's own join requests, and terminal notices (`removed` / `deleted` / `refused`) awaiting `dismissProjectNotice` — all pending detail read from the project's authoritative members.json. `acceptInvitation` re-validates the inviter's authority at accept time — a demoted or removed inviter's offer is void and never grants. `requestToJoin` (mandatory `note`, fixed `viewer` ask) knocks on a project whose join policy is open: on a **private** open project a successful knock confirms the project exists (an explicit owner trade, flagged by `setJoinPolicy`), and the requester's own inbox shows the project *id* — never the site name — until membership; a private **closed** project answers exactly like a nonexistent one. A standing `refused`/`removed` notice blocks re-requesting until dismissed. `withdrawJoinRequest` retracts any pending ask the caller authored (their own request, or a proposal they sponsored) with no notice kept. `listMyProposals` is the sponsor's view of their own outgoing proposals across their projects — pending ones awaiting validation plus approved ones still awaiting the person's answer (`sponsored_by` attribution); a proposal absent from both lists was adjudicated (refusal reasons are not delivered to sponsors). The owner cannot `leaveProject` (transfer ownership first). |
| **Backups** | `backupProject`, `listBackups`, `restoreBackup`, `deleteBackup` — snapshot / restore (configurable scope). All are project-scoped on the URL marker and act only on the targeted project; a project named in the body must match the marker or the call is refused (`400 project.mismatch`). Backups never include `config/members.json`, so a restore never touches membership. |
| **Export / Import** | `exportProject`, `downloadExport`, `clearExports`, `importProject` — pack a project as ZIP for portability; import a ZIP back. `exportProject` is project-scoped (marker-bound, like the backups) and **excludes `config/members.json`** from the archive (the membership graph + private invitation notes never travel). `importProject` is **global** (create-from-archive, any authenticated user, like `createProject`): it mints a NEW project and **birth-writes the importer as sole owner**, discarding any `members.json` the archive carried (an untrusted roster is never accepted). An archive is treated as untrusted input throughout: entries are accepted against an **extension allowlist**, **hidden paths are refused** (no path segment may begin with a dot, so a `.git/`, `.svn/` or `.idea/` directory never becomes project content), each entry's **content is checked against what its name claims** (signature for binary formats, parseable JSON for `.json`, no PHP opening tag for text, sanitisation for SVG), and archive **resource limits** — entry count, total and per-entry uncompressed size, per-entry compression ratio — are enforced from the ZIP headers before anything is written. A refused entry is skipped and listed in the response with its reason; the rest of the archive still imports. Both the permitted extensions and the limits are configurable — see *Archive import limits* below. `cloneProject` (see *Projects*) does the same birth-write — a clone/import is a fresh project owned solely by you; collaborators are not carried over. **A project id is unique across the installation and an import never reassigns one**: if the id is already taken the import answers `409 resource.already_exists` and writes nothing, with no option that overrides it. An id is a project's identity *and* the namespace its browser storage lives in, so reusing one means deleting the existing project first — an owner-gated action of its own, not a side effect of an upload. |
| **Roles** | `listRoles`, `getMyPermissions` — read the fixed roles and your own effective permissions. (`createRole` / `editRole` / `deleteRole` are disabled: roles are a fixed set, not customisable.) |
| **Snippets** | `listSnippets`, `getSnippet`, `createSnippet`, `deleteSnippet`, `duplicateSnippet`, `insertSnippet`, `injectSnippetCss` — reusable in-tree snippets (nav, cards, forms…); insert / inject into a page's structure. Snippets come from three tiers — **core** (shipped with the engine, read-only), **personal** (yours, reusable across every project you work on), and **project** (this project only). `listSnippets` returns core + your own personal library + the targeted project's, each row tagged with the tier it came from. `createSnippet` takes an optional `scope`: `project` (the default) or `personal`; `global` is accepted as a legacy spelling of `personal`, and an unrecognised value falls back to `project`. See *Snippet tiers* below. |
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
| **System** | `getCommandHistory`, `clearCommandHistory`, `getSizeInfo`, `getIframeSandbox`, `setIframeSandbox`, `removeIframeSandbox` — engine-level state (audit log of executed commands, project size info, iframe sandbox config for the visual editor). The command history is **per project**: both commands act only on the project named by the URL marker, and there is no installation-wide view. See *Command history storage* below. |
| **Workflow tooling** | `listWorkflowBlocks`, `lintWorkflows` — enumerate reusable prompt blocks in `secure/admin/workflows/{blocks,pins,warnings,examples}/`; report paragraphs that occur in 3+ workflow templates as candidates for extraction. Both are authoring aids for the shipped workflow catalogue — they read QuickSite's own files rather than any project's data, so both are global (any authenticated user) and take no project marker. |

## Snippet tiers

A snippet is a reusable chunk of page structure — a nav bar, a card, a contact form — that can be inserted into any page. Snippets come from three places:

| Tier | Who can use it | Where it lives | Written by |
|---|---|---|---|
| **core** | everyone on the installation | `secure/snippets/core/` | ships with the engine; read-only |
| **personal** | you, in every project you work on | `secure/snippets/custom/<userId>/` | `createSnippet` with `scope: "personal"` |
| **project** | anyone with access to that one project | `secure/projects/<projectId>/snippets/` | `createSnippet` (default) |

**A personal snippet belongs to the person who wrote it, not to the installation.** It follows you across every project you are a member of, and it is invisible to everybody else: another user cannot list it, read it, insert it into their own pages, or delete it — not even a member of the same project. The library is keyed by your user id, so two people can hold snippets of the same name without either seeing the other's.

`createSnippet` chooses the tier with an optional `scope` parameter:

| `scope` | Result |
|---|---|
| `"project"` | Saved to the targeted project only. **This is the default** when `scope` is absent. |
| `"personal"` | Saved to your own library, reusable across your projects. |
| `"global"` | Legacy spelling of `"personal"`, kept so existing callers and stored payloads keep working. Same destination, same visibility. |
| anything else | Treated as `"project"` — an unrecognised value never widens where a snippet lands. |

Reads resolve **most specific first**: the targeted project, then your own personal library, then core. A project snippet therefore shadows a personal one of the same name, and a personal one shadows a core one. `listSnippets` returns all three sets together with each row tagged by the tier it came from, plus per-tier counts. `deleteSnippet` looks in the project and then in your own library; core snippets are never deletable.

Creating a snippet whose id already exists is refused, but the check only ever looks at the targeted project and your own library — never at anyone else's. Reporting a collision against a stranger's snippet would answer "does this person have a snippet called X", which is not a question the API should answer.

## Upload size limits

Three different ceilings decide whether an upload survives, and they fail in three different places. Two of them belong to the **server**, not to QuickSite, and are read from PHP at request time rather than written into the engine — they differ per install and can differ per directory.

| Ceiling | Set by | What happens when it is exceeded |
|---|---|---|
| `post_max_size` | the server's PHP configuration | PHP discards the whole request body **before any command runs**. Answered `413 request.body_too_large`. |
| `upload_max_filesize` | the server's PHP configuration | The individual file is rejected while the rest of the request survives. Answered `400 asset.upload_failed` with `error_code: 1`. |
| Per-category cap | QuickSite | The file arrived and is refused on its size: 5 MB images, 2 MB fonts, 10 MB audio, 50 MB video. Answered `400 asset.file_too_large`. |

The first is the one worth understanding, because the request reaching the command carries no trace of it: `$_POST` and `$_FILES` are both empty, so without an explicit check the command sees a request with no file and says so — which is false, and sends the caller looking for a fault in their own form. The check compares `CONTENT_LENGTH` against the configured limit and answers with the real numbers:

```json
{
  "status": 413,
  "code": "request.body_too_large",
  "message": "The request is larger than this server accepts. It was 60 MB and the server accepts at most 50 MB per request, so PHP discarded it before QuickSite could read it. …",
  "data": {
    "content_length": 62914852,
    "post_max_size": 52428800,
    "upload_max_filesize": 52428800,
    "max_file_size": 52424704,
    "max_file_size_human": "50 MB"
  }
}
```

It applies to every command, not only the two that take files, because the body is gone before the request is dispatched.

**The effective limit is the smallest of the three,** and which one binds depends on the server. A multipart body is larger than the file it carries, so on an install where `post_max_size` equals `upload_max_filesize` — a common default — `post_max_size` binds for every upload and `upload_max_filesize` can never be reached at all. The admin panel's upload zone shows the effective per-category figures, and `GET /admin/api/upload-limits` returns them.

## Archive import limits

`importProject` treats an uploaded ZIP as untrusted input. Before a single byte is extracted it reads the archive's own central directory — the per-entry headers, which declare each entry's compressed and uncompressed size — and refuses the whole archive if any limit is exceeded. Nothing is decompressed to reach that decision, because decompressing is the cost being defended against.

| Limit | Default | What it bounds |
|---|---|---|
| `max_entries` | 10,000 | How many entries the archive may contain. |
| `max_total_bytes` | 200 MB | Total uncompressed size of every entry added together. |
| `max_entry_bytes` | 50 MB | Uncompressed size of any single entry. |
| `max_ratio` | 300 | Uncompressed-to-compressed ratio of any single entry. Entries of 1 KB or smaller are exempt — a few hundred bytes expanding from a dozen is ordinary compression, not an attack. |

The two byte caps are the real ceiling: whatever ratio an entry declares, no entry may allocate more than `max_entry_bytes` and no archive more than `max_total_bytes`. `max_ratio` catches the shape of a decompression bomb — a tiny upload that expands enormously — so in practice it governs how much an attacker has to upload to reach that ceiling rather than how large the ceiling is. It is deliberately generous, because a page tree is repetitive by construction (every node repeats its tag, classes, styles and children) and compresses far better than hand-written text; a tight ratio would refuse a legitimate export of a large page.

Exceeding any limit answers `413 validation.size_limit_exceeded`, reporting the value measured and the limit it crossed — plus the offending entry's name, where a single entry is at fault. This is stricter than a refused *entry*: an entry rejected by the extension allowlist or the content check is skipped and reported while the rest of the archive still imports, but a limit breach refuses the archive whole and no project is created.

### Changing the limits

The defaults are built into the engine and apply with no configuration at all. To change them, copy `secure/management/config/import-policy.php.example` to `secure/management/config/import-policy.php` and edit the values — that file also carries the import and publish extension allowlists.

The two kinds of setting merge differently. Limits merge **per key**: a limit you omit keeps its default, and a value that is not a positive whole number is ignored. Each extension list **replaces** the corresponding default outright, so you can narrow as well as widen — copy the full list and edit it rather than listing only your additions.

If the file is absent or malformed, the built-in defaults stay in force; the policy never fails open. A syntax error in it is recorded in the server error log rather than taking every import down, so the mistake is discoverable.

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
- The whitelist of valid commands is `secure/management/routes.php` (176 entries — this file is the single source of truth for which commands exist).
- Shared helpers live in `secure/src/functions/utilsManagement.php` (e.g., `varExportNested()`, `SPECIAL_PAGES`, role helpers).
- An unknown command answers `404` with code `route.not_found` and echoes back the name that was requested, so a typo is diagnosable. It does **not** enumerate the commands that do exist — `help` is where the catalogue lives, and it is a deliberate decision to publish it there rather than from every mistyped call.
- Internal callers (visual editor data gathering, workflow steps) bypass the HTTP layer and invoke commands through `secure/src/classes/CommandRunner.php`. CommandRunner carries a **hardcoded read-only allowlist** of 26 commands it will execute internally — reads and audits, mostly `get*` / `list*` but also `help` and the `analyze*` / `validate*` / `checkStructureMulti` inspectors. Membership and other mutating commands are not on it.
- When an internally-invoked command throws, the caller gets the command name and a fixed message. The exception's own message, file and line go to the PHP error log instead — unless the install declares itself development, in which case the real message is passed through (see *File paths in a response are relative*).
- Because that allowlist bypasses the permission check, it **is** the boundary: every command on it is reachable by the lowest membership tier, so it holds only commands inside the `viewer` grant (`content.read`) or a global any-authenticated category. Admin-tier reads — command history, backup listings — are deliberately absent and must go through the HTTP layer, where the role check applies.
- Workflow execution adds a second, per-command role check via `WorkflowManager::setTokenInfo()`, so a workflow's declared data commands are authorized against the calling user and the targeted project before they run.

## Command history storage

Every successful command (plus authentication failures) is appended to a daily
JSON file. The store is **partitioned by project**, so a project's audit trail is
readable only by someone who holds the `history` category **on that project**:

```
secure/logs/p/<projectId>/commands_<YYYY-MM-DD>.json   one directory per project
secure/logs/_global/commands_<YYYY-MM-DD>.json         commands that target no project
```

- `getCommandHistory` and `clearCommandHistory` read and delete **only** inside the
  directory of the project named by the URL marker. Clearing one project's history
  can never touch another's, and there is no query that spans projects.
- **Deleting a project deletes its history with it.** A project id is a folder name
  and can be re-used, so the directory is purged on `deleteProject` — a new project
  reusing an old name starts with an empty trail. The deletion event itself is
  recorded in `_global`, since the project it refers to no longer exists.
- A project's history counts toward that project's disk usage in `getSizeInfo`
  (category `logs`), not toward installation core files.

### What is recorded, and what is stripped

Each entry holds the command, the HTTP method, the caller (user id + display name),
the response status and code, the duration, and the request body. **Credentials are
never stored.** Sanitization is deny-by-default and command-independent:

- Any body key whose name looks like a credential — `password`, `secret`, `token`,
  `credential`, `key` / `apiKey`, `auth`, `authorization`, `signature`, `salt` — has
  its **value** replaced with `[redacted]`, at every depth of a nested body. A
  matching key discards its entire value, so an `auth` or `credentials` object is
  removed whole rather than walked into. The key itself is kept, so the trail still
  records *that* a credential was submitted.
- Authentication commands (`login`, `register`, `logoutSession`,
  `changePassword`, `deleteMyAccount`) log **no body at all**. The entry still
  records who acted, when, and with what result.
- `uploadAsset` records file metadata instead of file content, and `editStyles`
  truncates stylesheets over 5 KB.

Because the rule keys on the *shape of the body* rather than on a list of known
commands, a newly added command carrying a credential is protected automatically —
no change to the logging layer is required. Keys that merely resemble the pattern
are redacted too (a `tokenSource` pointer, for instance); erring toward redaction is
deliberate.

### The `_global` bucket — operators should manage this directly

Commands that belong to no project — account registration and deletion, password
changes, project creation, invitation and membership self-service — are recorded in
`secure/logs/_global/`. **No API command reads this directory.** It exists so that
account-level and membership-level actions leave a forensic trail rather than going
unrecorded; there is no installation-wide administrator role to expose it to.

Because nothing serves or rotates it, `_global` grows without bound and is the
operator's to manage. Treat it like any other server-side log: archive or delete
files on whatever schedule your retention policy requires (a scheduled task, a
logrotate rule, or manual deletion are all fine — the files are plain JSON and
nothing references them). Credentials are stripped before writing (see above), but
the entries still describe who did what and when, so treat them like any other
server log containing operational detail.

## Update detection

One command reports upgrades against the GitHub repo:

| Command | Method | Notes |
|---|---|---|
| `checkForUpdates` | GET | Reads the local `VERSION` file, fetches the latest GitHub release tag, compares with PHP's `version_compare`. Returns `update_available`, `current_version`, `latest_version`, `release_url`, `install_method` (`git`\|`zip`). |

Applying an update is **not** an API command, and there is no unrouted command file for it either. It replaces the code that runs every project on the installation, and authority in QuickSite is per-project — a project role cannot sanely imply "may rewrite the shared engine". Applying is done by `update.sh` / `update.bat` at the install root, which are operator/CLI entry points with no HTTP surface; the panel only *reports* available updates, through `checkForUpdates`.

Who sees that report in the panel is decided by `secure/management/config/operator.php` — a list of user ids, written at first run, that grants nothing and only controls whether the notice renders. The command itself stays callable by any authenticated account.

`version_compare` natively orders pre-release tags correctly: `1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0`. The installed version is read from the local `VERSION` file, so that file's contents are what `checkForUpdates` compares against the latest GitHub release tag.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — where the API sits in the three-layer model.
- [docs/WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — how multi-command workflows compose API calls.
- [docs/PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — on-disk layout.
