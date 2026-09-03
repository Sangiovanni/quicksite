# QuickSite Management API

> Reference for the Management API — the single HTTP surface every QuickSite client (admin panel, CLI, external apps) talks to.

## Endpoint

All API calls go through one entry point:

```
http(s)://<your-domain>/management/<command>[/<urlParam>]
```

`public/management/index.php` authenticates the request, dispatches to a command handler in `secure/management/command/`, and returns a uniform JSON response.

**These commands develop a project.** Anything about the installation itself, your account, your access to projects, or the admin panel's own state is deliberately *not* here: the panel serves those from its own endpoints (`/admin/api`, `/admin/state`, `/admin/self`), so a script driving this API gets a surface that is about websites and nothing else. If you are looking for one of those, see *What is deliberately not a command* below.

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
- **Changing your password and deleting your account are not commands.** The command surface is a CLI for *developing a project*; managing the login you sign in with is not that, so both are served by the admin panel at `POST /admin/self/change-password` and `POST /admin/self/delete`. Nothing about them got cheaper in the move: each still requires the **current password**, still shares the login throttle, and still runs behind the same session credential as every command. A password change ends every **other** session of the user (the one performing the change survives).
- Account deletion permanently removes the caller's **own** account: it requires the current password plus `confirm=true`, and ends every session. There is no way to delete someone else's account at all — authorization in QuickSite is per project, so the ways to part with a person are `removeMember` (evict them from one project) and, for the operator, editing `users.php` directly. Deletion is **refused** while the caller is the sole owner of any project: the response lists them, and each must be handed over with `transferOwnership` or destroyed with `deleteProject` first. On success the caller is removed from every project they belong to, along with any invitation addressed to them and any join request they filed. References to them *inside other people's* pending entries (who invited or sponsored whom) are deliberately kept so a third party never loses an invitation, and render with a `null` name.
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
| `message` | string | Human-readable summary, always written by the command itself — there is no per-code default, so the text describes what actually happened rather than what the code generally means. A refusal for a missing parameter names the parameter. Localized when called from the admin panel. |
| `data` | object (optional) | Command-specific payload. **Omitted entirely when there is nothing to report** — read it as "absent or an object", not as "always present, sometimes null". |
| `errors` | array (optional) | On validation failures, structured entries with `field` / `value` / `reason`. Omitted when empty. |
| `hint` | string (optional) | Appears on some `401` refusals with a concrete next step (which header to send, for example). Advisory text for a human — never branch on it. |

There is **no separate error envelope**. A failed call uses the same `status` / `code` / `message` triple with a non-2xx status and an error code, and carries `data` only when it has something useful to say — a `404` for an unknown command, for instance, echoes back the name that was requested. This keeps clients simple: parse once, branch on `status` or `code`.

If the envelope itself cannot be serialised — malformed UTF-8 somewhere in the payload, or a structure nested deeper than JSON encoding allows — the response becomes a `500` with code `server.internal_error` rather than the original status with an empty body. A client never receives a `2xx` whose body is missing.

The same is true of a response built with no `message` at all: it becomes a `500` with code `server.internal_error`, and the failure is logged with the file and line that produced it. This is a bug in the command rather than a state a caller can provoke, and it is reported as one instead of being filled in with generic text — an envelope that looks like an answer while describing nothing is the harder failure to notice.

### File paths in a response are relative

Whenever the envelope names a file or directory — `data.file`, `data.path`, `data.build_directory`, a path interpolated into `message`, a `value` inside `errors` — it is **relative, never absolute**. The installation's location on disk is not part of the API.

| Where the file lives | How it appears | Example |
|---|---|---|
| Inside the project the request targeted | Relative to that project's root | `templates/model/json/pages/home/home.json`, `public/style/style.css` |
| Elsewhere in the installation | Relative to the installation root | `secure/projects/other-site`, `public/style/style.css` |
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

`207` is a 2xx, so a client testing only "was this a 2xx" will read it as success. **Branch on `code`, or on `data.failed_count`, whenever a command can act on more than one thing.** Commands that use it: `clearExports`, `cleanOrphanTranslations`.

`cleanOrphanTranslations` is the exception: it reports the same `operation.partial_success` code with `errors` populated, but keeps `status: 200`, because it runs as one phase of a longer chain that stops on a non-2xx.

## Command catalogue

The 153 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

> **AI is browser-direct (BYOK).** There is no `callAi` / `testAiKey` / `detectProvider` / `listAiProviders` server command — the admin panel calls AI providers directly from the browser using credentials stored in `aiConnectionsV3` (localStorage). The Management API only handles workflow specs and command execution.

Each row enumerates the commands in that category — comma-separated, alphabetical within the category — followed by what the category covers. Categories are derived from `secure/management/routes.php`; if a command isn't here, it isn't routed.

| Category | Commands & detail |
|---|---|
| **Meta** | `help` — self-documenting endpoint, callable without authentication. |
| **Session** | `login`, `logoutSession`, `register` — username+password login → a session (cookie + session token); explicit logout, optionally everywhere; flag-gated flood-controlled self-registration (public). These three stay commands because a CLI that cannot authenticate is not headlessly usable. Changing your password and deleting your account are **not** commands — see *Authentication* above and *What is deliberately not a command* below. |
| **Pages** | `listPages`, `createAlias`, `deleteAlias`, `listAliases`, `editFavicon`, `editTitle` — page metadata, title, favicon, alias routes. `editFavicon` stores a **pointer**: it writes the chosen asset's path into the project config and copies nothing, so choosing a favicon neither duplicates the image nor leaves timestamped backups behind. Any favicon-capable image already in `assets/images/` may be chosen — `ico`, `png`, `svg`, `gif`, `jpg`, `jpeg`, `webp`, `avif` — and passing `null` clears the choice, returning the site to its default. Exactly one asset is the favicon, so choosing another replaces it. Renaming the chosen asset follows the pointer and deleting it clears the pointer, so the setting cannot outlive the file it names. Because a build copies the project config verbatim, the choice travels into a built site with no extra step. |
| **Routes & sitemap** | `addRoute`, `deleteRoute`, `getRoutes`, `getSiteMap`, `setSiteMapConfig`, `setRouteLayout`, `analyzeReachability` — URL routing tree CRUD, sitemap export, dead-route audit. `getSiteMap` is **read-only**; `setSiteMapConfig` owns the two sitemap writes (the excluded-routes / custom-URLs sidecar, and publishing the project's own `public/sitemap.txt`) and needs a write role, because both decide what the published sitemap contains. `getSiteMap` URLs are absolute: a `baseUrl` body param (validated URL) wins; otherwise the deployment's `QS_PUBLIC_BASE_URL` env var, else the project's own `/p/<id>/` URL on the install. `setRouteResolver` is under "Server-side data resolvers" below since the route layer just hosts the resolver config. |
| **Structure** | `getStructure`, `editStructure`, `addNode`, `addComplexElement`, `addComponentToNode`, `editComponentToNode`, `editNode`, `moveNode`, `deleteNode`, `duplicateNode` — edit nodes inside a page tree. |
| **Components** | `listComponents`, `getComponent`, `findComponentUsages`, `renameComponent`, `duplicateComponent` — reusable component definitions (NOT the in-tree snippet shortcuts below). |
| **Translations** | `getTranslation`, `getTranslations`, `getTranslationKeys`, `setTranslationKeys`, `deleteTranslationKeys`, `cleanOrphanTranslations`, `validateTranslations`, `getUnusedTranslationKeys`, `analyzeTranslations`, `importStructureTranslations` — translation keys per language; audits + structure-aware bulk operations. |
| **Languages** | `getLangList`, `getLanguageList`, `addLang`, `deleteLang`, `setDefaultLang`, `setMultilingual`, `checkStructureMulti` — site language config (add/remove, default, multilingual gate). |
| **Assets** | `uploadAsset`, `listAssets`, `editAsset`, `deleteAsset` — upload, list, delete files in the project's own `public/assets/{images,font,audio,videos}/`, with metadata (alt text, dimensions). An upload is checked twice: its extension must be one the taxonomy knows (which is what decides its category), and its server-detected type must belong to that category. `listAssets` also reports which asset is the site favicon, so a caller can show the current choice without a second request. |
| **Styles** | `getStyles`, `editStyles`, `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule` — `style.css` blocks + scoped CSS rule management. |
| **CSS variables** | `getRootVariables`, `setRootVariables`, `setThemeMode` — CSS custom-property registries used by the color picker and theme switcher. |
| **Animations** | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes`, `getAnimatedSelectors` — named keyframes + per-element animation bindings. |
| **Builds** | `build`, `getBuild`, `deleteBuild`, `downloadBuild`, `deployBuild` — compile a project to a self-contained deliverable under its own `qs_build/<name>/`, then inspect / download / delete / deploy it. **Deploying is refused unless the installation's operator enabled it** (`<secure>/management/config/deploy.php`; absent means no) — see *Self-deploy* below, which also covers the target allowlist and the route-collision check. **A build lives outside the project's `public/`**, which is the only directory `/p/<id>/` serves — so no URL reaches a build, on a public project or a private one, and `downloadBuild` is the only way to fetch one. It archives the folder on demand and streams it, so the download carries the same authentication as every other command and nothing stale is kept on disk. **A project holds at most one build**: `build` answers `409 conflict.already_exists` while one exists rather than overwriting it, and its response names `downloadBuild` and `deleteBuild` as the way forward. A build that fails removes its own partial directory; should that removal fail too, the leftover has no `build_manifest.json` (written last for exactly this reason) and `getBuild` reports `complete: false` — an incomplete build can be deleted, but not downloaded or deployed. `getBuild`, `deleteBuild` and `downloadBuild` therefore take **no parameters at all**, and `deployBuild`'s `name` is optional — supplying it asserts which build was meant rather than selecting between several. `build` copies a project's `style/` and `assets/` through a **publish allowlist**, so a file existing inside a project and a file being published to a web-served directory are two separate decisions — anything not publishable is left out of the build and reported in the response. The permitted extensions are configurable in `<secure>/management/config/import-policy.php` (see *Archive import limits* below). **A build that cannot serve is not reported as a success**: before answering, `build` walks the finished tree along the request path — the file its `.htaccess` funnels to, that file's parameters, `config.php` and `routes.php`, the runtime the compiled pages require, the menu and footer, every compiled route's page at the exact path routing will compute for it, and the 404 — and answers `500 server.internal_error` with `data.problems` naming what is missing, discarding the partial. The site it emits carries the project's REAL id, so a built site and the same project at `/p/<projectId>/` share one browser-storage namespace. `build_manifest.json` records whether the build carries **OAuth client secrets**, and `getBuild` reports it — that changes what the deliverable is (a credential, not just a website) and the moment it matters is the download, long after `build`'s own response is gone. |
| **Projects** | `listProjects`, `createProject`, `cloneProject`, `deleteProject`, `setProjectVisibility` — per-project CRUD under `secure/projects/`. No project is privileged, so none of these decides what a domain serves: that is a web-server mapping (see ARCHITECTURE.md). `listProjects` is membership-filtered: it returns only the caller's projects, each with `my_role` (no all-projects view). **Your storage total is not a command either**: "how much disk do my projects use" is a fact about an *account*, so it is served at `GET /admin/self/space-usage` (add `?refresh=1` to force a re-walk). It answers an owner-wide total, a category breakdown, and one row per project, aggregated across every project where the caller's role is `owner`; ownership is resolved per project from `members.json`, so it can only ever describe projects you own — owning nothing returns a zeroed report. Names of backups and exports are never returned, only sizes and counts. For one project in depth, use the project-scoped `getSizeInfo` command. **No command sets which project you are editing.** That pointer is panel state, written by the admin panel at `POST /admin/state/selected-project` (per-user, member-only), and it is the only project pointer there is: what a production domain serves is decided by the web server, so no command can publish a project at a domain root either (see [ARCHITECTURE.md §6](ARCHITECTURE.md)). Every command names the project it acts on in the URL marker instead. `createProject`'s `switch_to` sets only the creator's edited pointer, through the same writer. `setProjectVisibility` (`private`/`public`, **owner-only**) flips whether the project is served to the public internet via surface-B — a graver exposure decision than the admin-tier `setJoinPolicy`, so it sits at the delete/transfer tier; making a project private while its join policy is open re-creates the knockable-by-id state (an advisory note is returned). See [ARCHITECTURE.md §6](ARCHITECTURE.md). |
| **Project members** | `listMembers`, `getProjectRoster`, `inviteMember`, `cancelInvitation`, `changeMemberRole`, `removeMember`, `transferOwnership`, `approveJoinRequest`, `denyJoinRequest`, `proposeMember`, `setJoinPolicy`, `reconcileMemberships` — the project's roster, on a consent model: `getProjectRoster` is the reduced roster for EVERY member rank — active members only (`{user_id, name, role, rank, is_owner}`, rank-descending), no pending queue, so any member can see who is on the project with them; the full `listMembers` (roster + pending invitations/requests) stays admin/owner. Otherwise: an admin/owner *invites* an existing account (by `user_id`, discovered with the panel's user lookup — see *What is deliberately not a command*) and membership materializes only when the invitee accepts. Incoming join requests and member proposals are adjudicated with `approveJoinRequest` (a self-request joins immediately; a sponsored proposal converts into a real invitation carried by the approver's rank, `sponsored_by` kept — the approver may name the `role` to grant, defaulting to the requested/proposed one, so approval and role assignment are one atomic, rank-checked step) and `denyJoinRequest` (mandatory `note` — a refusal always carries its reason; a denied self-request leaves a dismissable `refused` notice, a denied proposal tells the never-engaged target nothing). `proposeMember` is the sponsor lane: ANY member — viewer included — vouches an outsider with a mandatory note, at a role no higher than the sponsor's own rank; nothing is granted and the person is told nothing until validation. `setJoinPolicy` (`open`/`closed`, default closed, admin+) gates only the self-service request door — proposals always reach the queue, and closing never purges pending requests. Rank rules throughout: you can only offer, change to, cancel, approve, deny, or remove roles of strictly lower rank than your own (nobody can veto what they could not grant); `cancelInvitation` withdraws invites only (requests are adjudicated, never silently cancelled); the owner's role is immutable except via `transferOwnership` (owner-only, member-only target, `confirm: true`, departing owner keeps `old_owner_role` — default `admin`). Members are referenced as `{user_id, name}` — the public display name and the opaque id; the private login username never appears. `reconcileMemberships` (admin/owner) is the maintenance sweep: it heals every member's users.php membership cache for the project against the authoritative members.json — rebuilding derivable statuses (member / pending) while **preserving** the non-derivable tombstones (`refused` / `removed` / `deleted`, which live only in the cache) and pruning stale positives; it aborts rather than wipe real memberships if the authority is unreadable. All are project-scoped on the URL marker (`/management/p/<projectId>/…`) and ignore any project named in the body. |
| **Backups** | `backupProject`, `listBackups`, `restoreBackup`, `deleteBackup` — snapshot / restore (configurable scope). All are project-scoped on the URL marker and act only on the targeted project; a project named in the body must match the marker or the call is refused (`400 project.mismatch`). Backups never include `config/members.json`, so a restore never touches membership. **`restoreBackup` overwrites and takes no snapshot unless you ask for one.** It replaces the project's `config.php`, `routes.php`, `templates/`, `translate/`, `data/` and `public/` from the named backup. The *pre-restore backup* — a snapshot of the project's **current** state, taken before it is overwritten, so the restore itself can be undone by restoring that snapshot — is **opt-in**: pass `create_backup: true`. The default is `false`, in which case no snapshot is taken and the overwritten state is not recoverable through QuickSite. On success `data.pre_restore_backup` names the snapshot (`pre-restore_<timestamp>`) or is `null` when none was requested. The admin panel offers the snapshot as an unchecked box on its restore dialog; a direct API caller gets no prompt at all, so the parameter is the only safeguard. |
| **Export / Import** | `exportProject`, `downloadExport`, `clearExports`, `importProject` — pack a project as ZIP for portability; import a ZIP back. `exportProject` is project-scoped (marker-bound, like the backups) and **excludes `config/members.json`** from the archive (the membership graph + private invitation notes never travel). `importProject` is **global** (create-from-archive, any authenticated user, like `createProject`): it mints a NEW project and **birth-writes the importer as sole owner**, discarding any `members.json` the archive carried (an untrusted roster is never accepted). An archive is treated as untrusted input throughout: entries are accepted against an **extension allowlist**, **hidden paths are refused** (no path segment may begin with a dot, so a `.git/`, `.svn/` or `.idea/` directory never becomes project content), each entry's **content is checked against what its name claims** (signature for binary formats, parseable JSON for `.json`, no PHP opening tag for text, sanitisation for SVG), and archive **resource limits** — entry count, total and per-entry uncompressed size, per-entry compression ratio — are enforced from the ZIP headers before anything is written. A refused entry is skipped and listed in the response with its reason; the rest of the archive still imports. Both the permitted extensions and the limits are configurable — see *Archive import limits* below. `cloneProject` (see *Projects*) does the same birth-write — a clone/import is a fresh project owned solely by you; collaborators are not carried over. **A project id is unique across the installation and an import never reassigns one**: if the id is already taken the import answers `409 resource.already_exists` and writes nothing, with no option that overrides it. An id is a project's identity *and* the namespace its browser storage lives in, so reusing one means deleting the existing project first — an owner-gated action of its own, not a side effect of an upload. |
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
| **System** | `getCommandHistory`, `clearCommandHistory`, `getSizeInfo`, `getIframeSandbox`, `setIframeSandbox`, `removeIframeSandbox` — engine-level state (audit log of executed commands, project size info, iframe sandbox config for the visual editor). The command history is **per project**: both commands act only on the project named by the URL marker, and there is no installation-wide view. See *Command history storage* below. |

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

### A fourth ceiling that is not PHP's

On nginx a limit sits in front of all three. `client_max_body_size` defaults to **1 MB** — smaller than the upload size a normal PHP configuration accepts — and nginx answers an oversized body with **413 and its own HTML error page, before PHP runs at all**. No QuickSite code executes, so none of the answers above is produced and the response is not JSON.

QuickSite computes the value nginx needs from that server's own `post_max_size` and writes it into the generated routing config, on the `/management/` block: uploads reach QuickSite only through that namespace, so raising the limit there raises it nowhere else. The value is deliberately a little above PHP's, so PHP stays the component that refuses an oversized upload and can say why. A vhost that proxies to a second server block needs the directive in the public block too, since that is where the client's bytes arrive.

Apache needs nothing: `LimitRequestBody` is unlimited by default. If you set it, keep it above `post_max_size`.

A client that receives a non-JSON error — from nginx, a proxy, or a gateway — is given a sentence naming the status code rather than a parse failure.

## Per-user resource limits

Creating projects and uploading into them are open to any authenticated account. On a shared install, nothing stops an ordinary signed-in user from filling the disk, so `uploadAsset` and `importProject` enforce two optional per-user ceilings.

**Nothing is limited by default.** With no `secure/management/config/quota.php` both axes are unlimited, and updating QuickSite never changes that — an existing install cannot start refusing uploads because it was upgraded. A malformed file is ignored, with a line in the server error log, and again nothing is limited: a typo in a quota file must not lock every author out of their own site.

| Axis | Setting | Refusal |
|---|---|---|
| Total bytes | `max_total_bytes` | `507 quota.storage_exceeded` |
| Upload rate | `upload_rate.max_uploads` per `upload_rate.period_seconds` | `429 quota.rate_limited`, with `retry_after` in seconds |

Storage is charged to the account that **owns the project receiving the bytes**, which is not always the caller: a member uploading into somebody else's project spends that owner's allowance, because that is whose disk grows. It is measured across every project that owner owns — the same figure `GET /admin/self/space-usage` reports to them and their dashboard shows — counting each project's content, backups, exports and builds. A caller who is not that owner is told only that the owner is out of space and what the per-account ceiling is; the owner's totals are not disclosed, since they aggregate projects the caller may not be a member of. The upload **rate** limit is the opposite case and follows the caller, because it bounds what one actor does rather than where the bytes land. An upload is refused when current usage **plus the incoming file** would cross the ceiling, so the configured number is a ceiling the total never crosses rather than a threshold it overshoots by one file. For an import, the incoming size is the archive's uncompressed total, read from its central directory, and the refusal happens before the project directory is created.

```json
{
  "status": 507,
  "code": "quota.storage_exceeded",
  "message": "This would put your projects over your storage quota. They currently use 1.9 GB of 2 GB, and this upload adds 4 MB. You have 102 MB left. …",
  "data": {
    "used_bytes": 2040109465,
    "incoming_bytes": 4194304,
    "quota_bytes": 2147483648,
    "remaining_bytes": 107374183,
    "owned_projects": 3
  }
}
```

Two consequences of measuring by ownership are worth knowing before setting a number. A project with two owners counts in **full** against both — ownership is a set, not a share. And bytes are attributed to the project they land in, so an invited member's upload counts against that project's **owner**; an account that owns nothing has no storage total to exceed, and it is the rate axis that bounds it.

The rate axis counts uploads that actually wrote something — a refused upload does not spend an allowance — in fixed windows, so the allowance refills on a period boundary rather than sliding. It exists to bound churn (upload, delete, upload again, which never grows a total), while `max_total_bytes` bounds volume.

Sizes come from a short-lived measurement cache. The write paths drop a project's cached measurement after they write to it, so growth is always measured exactly; a **deletion** elsewhere can leave a total reading high for up to five minutes, which makes the quota briefly stricter than reality and never looser. Re-measuring on the spot is what the dashboard's refresh control does (`GET /admin/self/space-usage?refresh=1`), and the refusal message says so.

Copy `secure/management/config/quota.php.example` to `quota.php` to set the numbers; every value is an integer and 0 means unlimited for that axis.

## Self-deploy

`deployBuild` copies a finished build onto a filesystem path — the install's own root by default. It asks for no credentials: the installation writes to itself. That is a legitimate way to publish and it is also a capability the person who set the server up may not want to hand to the people who use it, so three independent gates stand in front of it. All three must pass, and none substitutes for another.

| Gate | Where | Default |
|---|---|---|
| May anyone deploy at all? | `<secure>/management/config/deploy.php` → `allow_deploy` | **Absent means no.** |
| May THIS caller? | `roles.php` — `deployBuild` is alone in the `deploy` category | Project admins and owners |
| WHERE may a permitted deploy write? | `<secure>/management/config/deploy-roots.php` | The install root (`SERVER_ROOT`) only |

**An absent `deploy.php` refuses.** So does an unreadable one, a syntax error, a file that is not PHP, a wrong return type, a missing key, and any value that is not boolean `true` — the string `"true"` and the number `1` included. Only a well-formed file saying exactly `true` opens the gate; a broken one is reported to the server error log rather than taking the request down. `setup.sh` / `setup.bat` offer this as a menu item, and `deploy.php.example` documents it.

Turning it on widens nothing else. The role gate and the target allowlist are unchanged by it; it only lifts the flat refusal that comes before both.

```json
{
  "status": 403,
  "code": "deploy.disabled",
  "message": "Deploying is disabled on this installation",
  "data": {
    "hint": "The operator enables it on the server: copy <secure>/management/config/deploy.php.example to deploy.php and set allow_deploy => true (setup.sh / setup.bat offers this). Building, downloading and deleting a build are unaffected."
  }
}
```

Building, downloading and deleting a build are unaffected on an install with deploy off. The archive can still be produced and fetched; what is closed is QuickSite writing the site onto a path itself.

### Co-tenancy: one document root, several sites

Deploying site B never damages site A. `deployBuild` enforces that with a subtree rule and three named refusals, none of which `overwrite` can answer.

**A build owns its own subtree and nothing else.** That is `<public>/<space>/**` and `<secure>/**`. A spaced build also emits a `.htaccess` for the document root itself — `Options -Indexes` plus headers, no `FallbackResource` — and the document root is *outside* its subtree. Outside the subtree a deploy **creates but never overwrites**, and `overwrite: true` does not reach those paths. Skipped paths come back as `shared_paths_skipped`.

Without that rule, a spaced build deployed over a root build replaced the root site's funnel: its home page kept working (a real `index.php`) and every route 404'd. Order-dependent — root-then-spaced broke, spaced-then-root did not — so it passed a test and broke the next deploy.

#### The deployment marker

On success `deployBuild` writes `<secure>/qs-deployment.json` naming the project, the build, the time, and **where the deployment landed** — the public folder name and the URL space. The secure folder is not web-reachable, so it can carry a project id; and it is the folder whose contents are at stake, which is what makes the check work when two sites use different *public* folder names — the multi-tenant case.

The placement fields have a second reader: the nginx generator below, which has to tell a build sitting at the installation's document root from one sitting beside it. `qs-site.php` holds the same three fields and cannot be used for it — it answers 404 and exits unless the site's own entry point is booting.

A build carries no such identity of its own: `qs-site.php` names the project but lives in the public folder, and `build_manifest.json` is never deployed. A site uploaded by hand from a downloaded archive therefore has no marker, and the first deploy over it reports an unknown owner. A marker written before the placement fields existed is treated as a marker with no placement: it still answers the ownership question, and the next deploy rewrites it.

| At the target | Answer |
|---|---|
| Marker naming **this** project | `409 deploy.update_confirmation_required`, with `deployed_at` and `deployed_build`. `confirmUpdate: true` proceeds. The routine path. |
| Marker naming a **different** project | `409 deploy.secure_folder_in_use`. `replaceDeployment: true` proceeds. |
| Contents, **no** marker | `409 deploy.secure_folder_unmarked`. `adoptSecureFolder: true` proceeds. |
| Empty or absent | Deploys, and the marker is written. |

The two refusals about somebody else's folder say only that the name is not available. They do not name the project that holds it, its build, or when it was deployed — the same reasoning that keeps the installation's own path out of the panel.

**Nothing is ever deleted.** Every path above writes the files the build produces and leaves everything else untouched, including in a folder the deployer chose to write over. Clearing a stale secure folder is a manual act on the server, deliberately: a command that could delete one is a command that can destroy a site by typo.

```json
{
  "status": 409,
  "code": "deploy.secure_folder_in_use",
  "message": "The secure folder name \"app\" is not available at this target",
  "data": {
    "secure_folder": "app",
    "hint": "Another deployment already owns this folder. Build with a different secure folder name, deploy to a different target, or set replaceDeployment=true to overwrite what is there. Nothing is deleted either way: files the new deployment does not write are left untouched."
  }
}
```

### Route collisions at the target

The default deploy target is the installation's own web root, where QuickSite already serves `/admin`, `/management` and `/p`. A site's entry point funnels requests through a fallback that only applies when the URL is **not** a real file or directory — so a directory sitting beside that entry point permanently shadows a same-named route, silently.

`deployBuild` checks for this **at deploy time**, when the target is known, by intersecting the build's top-level route segments with the directories that already exist beside where the site's entry point will land. It is derived from the target on disk, not from a list of reserved names, so it is correct for a target QuickSite has never seen — and it says nothing at all about a target that carries no such directory.

A collision is refused with `409 conflict.route_collision`, naming each route, the segment that clashes and the directory that shadows it. `acceptRouteCollisions: true` deploys anyway; the success response then reports what was shadowed rather than staying quiet about it.

```json
{
  "status": 409,
  "code": "conflict.route_collision",
  "message": "One of this site's routes is already a directory at the deploy target and would never be reachable",
  "data": {
    "collisions": [
      { "route": "admin", "segment": "admin", "shadowed_by": ".../public/admin" }
    ],
    "hint": "A directory beside the site's entry point wins over its routing, so these pages would answer with the directory instead. Rename the route, deploy to a target that does not carry these directories, or set acceptRouteCollisions=true to deploy anyway and leave them unreachable."
  }
}
```

Route names are **not** reserved when a route is created: the names only clash in one of three deployment shapes (a site served at `/p/<id>/` and a build on its own domain have no conflict), so blocking them everywhere would constrain every author for a layout most never use.

#### nginx routing, and PHP's compiled files

An installation generates `<secure>/nginx/dynamic_routes.conf`, whose last block describes the document root. On an installation that root is deliberately **free** — `try_files $uri $uri/ =404;` — because the installation puts no front controller there and must not squat a domain it does not own. A deploy to that same root puts one there, so `deployBuild` **regenerates the file** from what is on disk:

| On disk at the document root | The generated root block |
|---|---|
| No deployed build | `try_files $uri $uri/ =404;` — unchanged |
| A build at the root (no URL space) | A funnel to that build's `index.php` |
| A build under a URL space | Still `=404`, plus a funnel block for that space |

Without it a deployed site serves only its home page — `index index.php` resolves the directory — while every other URL answers nginx's own 404. A server-level `try_files $uri $uri/ /index.php?$args;` in the vhost does not rescue it: server-level `try_files` runs only for requests matching no `location`, and `location /` matches everything.

The response reports the outcome under `nginx`, and never claims a reload that did not happen:

| `nginx.reload` | Meaning |
|---|---|
| `reloaded` | `nginx -t` passed and the running nginx took the new configuration. |
| `pending` | The file was written, the reload failed. `.pending_reload` is left for the optional cron script; a manual `nginx -t && nginx -s reload` is still needed. |
| `not_applicable` | Not an nginx server. Nothing was attempted and no flag was written — an Apache install generates the file and never reads it. |

`nginx.config_regenerated` reports whether the file itself was written; a failure there is a warning on a successful deploy, not a rollback, because the files are already copied and the deployer needs to be told rather than surprised.

`php_opcache.files_invalidated` counts the deployed `.php` files this process invalidated. It is **best effort by nature**: a deployed site normally runs in a different php-fpm pool, and OPcache memory is per-pool, so the invalidation reaches the deploying process's cache rather than the site's. It is exactly right when the pool is shared and harmless when it is not. On a pool tuned with `opcache.validate_timestamps=0` — a normal production setting — a redeploy is otherwise a silent no-op until php-fpm is reloaded, which is why the response says so rather than leaving it to be discovered.

### Parameters

`deployBuild` takes an optional `name`. At one build per project the command finds the build on its own; supplying the name asserts *which* build was meant, and a name that is not the current build is a `404` rather than a silent substitution. `targetPath` defaults to `SERVER_ROOT`, `overwrite` (default false) governs existing files, and `acceptRouteCollisions` (default false) governs the check above. `confirmUpdate`, `replaceDeployment` and `adoptSecureFolder` (all default false) each answer exactly one co-tenancy refusal and nothing else — none of them is implied by `overwrite`, and none implies another. An incomplete build is refused: it carries no manifest, and a half-written site on a server is where a broken deliverable does damage.

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

### What may enter, and what may be published

Three gates decide what a file type is allowed to do, and all three read one taxonomy:

| Gate | What it governs |
|---|---|
| **upload** | Which file types `uploadAsset` accepts, and which category each lands in. |
| **import** | Which archive entries `importProject` may carry into a project. |
| **publish** | Which files `build` may copy into a web-served directory. |

The taxonomy names the media types the engine handles, keyed by asset category:

| Category | Extensions |
|---|---|
| `images` | jpg, jpeg, png, gif, webp, svg, ico, avif, bmp |
| `font` | ttf, otf, woff, woff2, eot |
| `audio` | mp3, wav, ogg |
| `videos` | mp4, webm, ogv |

The import and publish allowlists are **derived** from it: the structural and text types an export legitimately carries — `json`, `css`, `js`, `mjs`, `map`, `txt`, `xml`, `csv`, `pdf` — plus every extension above. So a type is never uploadable but unimportable, and adding a media type widens all three gates in one edit. The structural types are import and publish only: there is no asset category to put a `.json` or a `.pdf` in, so uploading one is refused.

An extension also needs a detected-MIME entry for an *upload* of it to succeed — the extension names the door, the server-side type detection is the lock. Import and publish do not use that list; they check content against the format's signature instead.

### Changing the limits and the lists

The defaults are built into the engine and apply with no configuration at all. To change them, copy `secure/management/config/import-policy.php.example` to `secure/management/config/import-policy.php` and edit the values — that file carries the taxonomy, both extension allowlists, and the limits.

The kinds of setting merge differently:

- **The taxonomy** (`asset_extensions`) merges **per category**. A category you omit keeps its default; a category you supply has its list replaced. The four categories are fixed, because each needs a `public/assets/<category>/` directory that only project creation makes — an unknown category key is ignored rather than invented, and an empty list is treated as "not configured" rather than as a way to disable a category.
- **The two allowlists** (`import_extensions`, `publish_extensions`) default to the derived list and follow the taxonomy. Supplying one **pins** that gate to exactly what you wrote — it replaces the default outright, so you can narrow as well as widen, and it will no longer follow a later taxonomy change.
- **Limits** merge **per key**: a limit you omit keeps its default, and a value that is not a positive whole number is ignored.

If the file is absent or malformed, the built-in defaults stay in force; the policy never fails open. A syntax error in it is recorded in the server error log rather than taking every import down, so the mistake is discoverable.

### How an entry's content is checked

Passing the extension gate is not enough: an entry's bytes must match what its name claims, so a name cannot lie about what a file is. What that means depends on the class of file.

**Text types** — `json`, `css`, `js`, `mjs`, `map`, `txt`, `xml`, `csv`, `svg` — may never open a PHP block. All three spellings are refused, not only the long one: a server with short tags enabled executes the shorthand forms too, and file-type detection reports only the long form as PHP source. A `.json` entry must also parse as JSON, and an SVG is run through the same sanitiser the upload path applies, so what lands on disk is the cleaned markup rather than the bytes uploaded.

**Binary types** must match their format's signature, where one is known. A format may list several signatures: alternatives at the same position (a GIF begins either `GIF87a` or `GIF89a`), and requirements at different positions that must all hold (a WebP and a WAV share the same container marker and are told apart only by a second marker further in). Formats whose signature is ambiguous or absent — avif, mp4, mp3 — are not signature-checked; instead they must not be detected as text at all, which is the shape a disguised payload takes.

A binary entry that *looks* like it opens a PHP block gets one further test: it must also be detected as a real file of a named format, and reported as binary content. That conjunction is what separates a genuine image from a disguise. A few honest bytes followed by a script is detected as plain text, as nothing in particular, or as a named format that is simultaneously reported as ASCII — and is refused. A real image or font is detected as itself and reported as binary, and is accepted even though the byte pattern appears in it by chance, which it does in roughly one real file in twenty-five.

What this deliberately does not refuse is a genuinely valid image with script bytes appended. It cannot be told apart from an ordinary large image by inspecting bytes, and it is inert: no extension either allowlist permits is mapped to an interpreter, so the file is served as the image it is. The control that holds there is the allowlist itself, not the content check.

A refused entry is skipped and reported with its reason under `security.skipped_disallowed`; the rest of the archive still imports.

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
- The whitelist of valid commands is `secure/management/routes.php` (153 entries — this file is the single source of truth for which commands exist).
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

**A delete that does not finish says what is left.** `deleteProject` removes the
whole tree, continuing past a file it cannot remove instead of stopping at the
first one, and answers `500 server.delete_failed` naming the outcome:

```json
{
  "status": 500,
  "code":   "server.delete_failed",
  "message": "Project only partially deleted",
  "data": {
    "project":             "my-site",
    "partial":             true,
    "files_deleted":       17,
    "directories_deleted": 14,
    "survived":  ["templates/model/json/pages/home/home.json", "templates/model/json/pages/home"],
    "retained":  ["config", "config.php", "routes.php"],
    "hint":      "…"
  }
}
```

`survived` is what could **not** be removed — a file held open by another
process, or one the web server has no permission to unlink — with paths
relative to the project, never absolute. `retained` is different in kind: those
entries were kept **on purpose**. `config.php` and `routes.php` are what the
project context boots from and `config/members.json` is the permission gate, so
removing them on the way past would leave a project that could neither be named
nor authorized, and no retry could finish it. They go last, and only once
everything else is gone. Releasing whatever blocked the delete and running the
command again completes it.

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
- Session commands (`login`, `register`, `logoutSession`) log **no body at all**.
  The entry still records who acted, when, and with what result.
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

## What is deliberately not a command

The command surface is a CLI for **developing a project**. Four kinds of thing are
therefore served by the admin panel's own endpoints instead — they are listed here
because this is where people look for them, not because they are part of this API.

All of them authenticate exactly as a command does: the session cookie **and** the
session token as `Authorization: Bearer`. Reads are `GET`, writes are `POST`, and
the method is enforced per route.

| You want to | Endpoint |
|---|---|
| Change your password | `POST /admin/self/change-password` — `{current_password, new_password}` |
| Delete your account | `POST /admin/self/delete` — `{current_password, confirm: true}` |
| See your storage total | `GET /admin/self/space-usage` (`?refresh=1` to re-measure) |
| See your role and what it grants | `GET /admin/self/permissions` |
| Read the fixed role catalogue | `GET /admin/self/roles` |
| Look someone up to invite them | `POST /admin/self/find-user` — `{name}` (exact public display name; returns `{user_id, name}` matches, never the private username) |
| Read your membership inbox | `GET /admin/self/invitations` — pending invitations, your own join requests, and terminal notices |
| Read your outgoing proposals | `GET /admin/self/proposals` |
| Accept or decline an invitation | `POST /admin/self/accept-invitation` / `decline-invitation` — `{project}` |
| Leave a project | `POST /admin/self/leave-project` — `{project}` |
| Clear a refused/removed/deleted notice | `POST /admin/self/dismiss-notice` — `{project}` |
| Ask to join a project | `POST /admin/self/request-to-join` — `{project, note}` |
| Withdraw your own ask | `POST /admin/self/withdraw-request` — `{project}`, or `{project, user_id}` for a proposal you sponsored |
| Change which project the panel edits | `POST /admin/state/selected-project` — `{project}` |
| Check for an update | `GET /admin/api/update-check` |

**Why these and not others.** Managing the login you sign in with, and getting into
or out of a project, are operations on an *account* — they say nothing about a
website's content, and a headless client scripting a site's structure has no use
for them. Inviting somebody, adjudicating their request and managing the roster
*are* commands (see *Project members*), because they are decisions the project's
authority makes about the project. The line falls between the two sides of a
consent: the offer is a project decision, the answer is yours.

**Nothing about their rules changed when they moved.** The behaviour a caller can
rely on is the same in every respect that matters:

- **Consent still materializes the grant.** An invitation grants nothing until the
  invitee accepts, and acceptance re-validates the inviter's authority at that
  moment — a demoted or removed inviter's offer is void and never grants.
- **Each acts only on the caller's own entries.** `project` is a validated data
  parameter, not an authorization input. Withdrawing accepts a `user_id` only for
  a proposal you authored, and the `by == you` rule is what enforces that.
- **The enumeration posture is unchanged.** A project that does not exist answers
  exactly like one that has nothing for you: a private closed project and a
  nonexistent one are indistinguishable, while a *public* project honestly says
  requests are closed, because its existence is already public.
- **Both credential operations still require the current password**, on the same
  throttle as `login`. A stolen session token cannot change a password or delete
  an account on its own. Deletion additionally needs `confirm: true` and is
  refused while you solely own a project — the response lists them, and each must
  be handed over with `transferOwnership` or destroyed with `deleteProject` first.
- **A private login username is never returned by any of them.**

One thing did change, deliberately: these no longer appear in the per-project
command log, because they are no longer commands. They were previously recorded in
the write-only `_global` bucket that no command reads.

## Update detection is not part of this API

There is no command for it. The commands here act on **a project**; whether the
installation has a newer release available is a fact about the installation, so
it is served by the admin panel's own endpoint:

```
GET /admin/api/update-check
```

It reads the local `VERSION` file, fetches the latest GitHub release tag, and
compares them with PHP's `version_compare`, answering `update_available`,
`current_version`, `latest_version`, `release_url` and `install_method`
(`git`\|`zip`). When GitHub cannot be reached it answers `checked: false` rather
than an error — an install with no outbound access is a normal install.

Applying an update has no HTTP surface at all. It replaces the code that runs
every project on the installation, and authority in QuickSite is per-project — a
project role cannot sanely imply "may rewrite the shared engine". Applying is
done on the server with `git pull`; the panel only *reports*.

Who sees that report is decided by `secure/management/config/operator.php` — a
list of user ids, written at first run, that grants nothing and only controls
whether the notice renders. The endpoint itself answers any authenticated
account.

`version_compare` natively orders pre-release tags correctly:
`1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0`. The installed version is
read from the local `VERSION` file, so that file's contents are what the check
compares against the latest GitHub release tag.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — where the API sits in the three-layer model.
- [docs/WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — how multi-command workflows compose API calls.
- [docs/PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — on-disk layout.
