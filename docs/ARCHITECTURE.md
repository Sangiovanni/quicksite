# QuickSite Architecture

> Canonical high-level overview of how QuickSite is structured and how a request flows through it. For the admin panel internals, see [ADMIN_PANEL.md](ADMIN_PANEL.md). For the workflow engine, see [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). For the full command reference, see [COMMAND_API.md](COMMAND_API.md). For the on-disk layout, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes. It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

---

## 1. Three-layer model

QuickSite separates concerns into three top-level layers. Each one has a clear boundary and is responsible for a different audience.

| Layer | Folder | Audience | Purpose |
|---|---|---|---|
| **Project** | `secure/projects/{name}/` | Site owner | The actual website data: routes, page structures (JSON), translations, components, interactions, styles, assets. |
| **Management** | `secure/management/` | API client (admin panel, scripts) | The 178 commands that read or mutate project data. Single entry point: `public/management/index.php`. Token + role enforced. AI calls bypass this layer entirely (browser-direct). |
| **Admin** | `public/admin/` + `secure/admin/` | Human operator | The browser UI that calls Management commands. Includes the visual editor, sitemap, theme editor, AI workspace, workflow runner. |

```
            ┌──────────────────────────────────────────────┐
            │                  BROWSER                     │
            └──────────────┬───────────────────────────────┘
                           │
        ┌──────────────────┼──────────────────────┐
        │                  │                      │
        ▼                  ▼                      ▼
   Public site         Admin panel          Management API
   GET /…              GET /admin/…         POST /management/{command}
        │                  │                      │
        └──────────────────┴──────────────────────┘
                           │
                           ▼
               ┌──────────────────────┐
               │      public/         │  Apache DocumentRoot only
               │  index.php · init.php│
               │  admin/ · style/ · …│
               └──────────┬───────────┘
                          │ require_once
                          ▼
               ┌──────────────────────┐
               │      secure/         │  Never web-accessible
               │  management/         │  (commands)
               │  src/                │  (Renderer, Compiler, Translator…)
               │  projects/{name}/    │  (sites)
               │  admin/              │  (admin templates + workflows)
               │  interaction-schemas/│
               └──────────────────────┘
```

The split between `public/` and `secure/` is the **security boundary** (see §7). Apache only serves `public/`. Everything in `secure/` is reached only through PHP `require_once` from inside `public/`.

---

## 2. Project layer — data model

A project is a self-contained website on disk. One QuickSite installation can host multiple projects; one is "active" at any time, pointed to by `secure/management/config/target.php`.

```
secure/projects/{name}/
├── config.php           # languages, default lang, multilingual flag, etc.
├── routes.php           # ['home' => …, 'about' => …, 'docs/install' => …]
├── templates/
│   ├── model/json/      # Source of truth: JSON structure per page
│   ├── pages/           # (build only) compiled PHP templates
│   ├── menu.json
│   ├── footer.json
│   └── components/      # Reusable JSON components
├── translate/           # en.json, fr.json, … (or default.json mono-lang)
├── data/                # Aliases, metadata
├── interactions/        # Custom interaction configs (v2)
├── style/               # Project CSS source
├── assets/              # Project images / fonts / videos
└── backups/
```

### JSON structure format

Every page, menu, footer, and component is defined as a tree of nodes:

```json
{
  "tag": "section",
  "params": { "class": "hero" },
  "children": [
    { "tag": "h1", "children": [{ "textKey": "home.hero.title" }] },
    { "tag": "p",  "children": [{ "textKey": "home.hero.subtitle" }] },
    { "component": "cta-button", "data": { "labelKey": "home.hero.cta" } }
  ]
}
```

#### Why JSON, not HTML

Page structure is JSON rather than HTML for a handful of pragmatic reasons:

- **API-first contract.** Every external client — admin panel, CLI, AI agent — produces JSON natively. An HTML-to-JSON parser would be lossy and a security pit.
- **Deterministic AI output.** Validating that an LLM produced a tree with known keys is tractable; validating that it produced safe HTML is famously not.
- **Security by construction.** A single chokepoint applies the tag blacklist (see §7) and escapes attribute values. There is no path for raw `<script>` to slip through.
- **Translation separation.** `textKey` cleanly decouples content from structure. The same tree renders in any active language without template duplication.
- **Tree addressability.** Every node has a stable path (`data-qs-node="0.1.2"` in editor mode). The visual editor maps an iframe click back to the exact JSON node and back again — trivial on a tree, painful on free-form HTML.
- **Single source, two outputs.** The same JSON feeds the runtime renderer (`JsonToHtmlRenderer`) and the build-time compiler (`JsonToPhpCompiler`). Editing HTML twice is exactly the bit-rot we want to avoid.
- **Git-friendly diffs.** Tree-shaped JSON diffs tell you what changed semantically; HTML diffs are string diffs.
- **Future formats reuse the tree.** Sitemaps, JSON-LD, RSS, build-time PDF exports all consume the same structure.

#### Node kinds

| Kind | Shape | Notes |
|---|---|---|
| Tag | `{ tag, params?, children? }` | Standard HTML element. |
| Text | `{ textKey: "…" }` | Resolved through the translator. |
| Raw text | `{ textKey: "__RAW__…" }` | Literal, never translated. |
| Component | `{ component, data? }` | Inlines a component's JSON, with `{{var}}` interpolation. |

Attributes also accept a `{ condition, value }` shape for conditional rendering of a single attribute (evaluated server-side at render time).

#### Special prefixes & placeholders

A small vocabulary of markers controls rendering. They are described by intent — pick the one that matches what you mean, even when the renderer would accept either.

- **`__RAW__`** — for **text content**. Marks a `textKey` value as literal: do not look it up in translations, just emit it (escaped). Use this when a textual node really is a fixed string, not a key.
- **`__LIT__`** — for **attribute or component params**. Marks the value as literal so it is used as-is everywhere it can appear. In the renderer `__LIT__` and `__RAW__` overlap for text and translatable attributes; the distinction is one of intent for the next reader, not a behavioural difference.
- **`__enums__`** — a root-level metadata block on a component template that derives variables from a single source key. Each entry maps a derived variable name to a value map plus an optional default. Use `listComponents` at runtime to see what a component exposes.
- **`{{var}}` and `{{$var}}`** — placeholders interpolated from the caller's `data` when a component is inlined. Variable names accept letters, digits, underscores, and hyphens.
- **`{{__system__}}` placeholders** — runtime values resolved by the renderer, for example `{{__current_page;lang=en}}`. The full list lives in the relevant command's `help` output, not here.
- **Translatable attributes** (`placeholder`, `title`, `alt`, `aria-label`, `aria-placeholder`, `aria-description`) auto-resolve their value as a translation key when it looks like one — lowercase identifiers separated by dots, e.g. `form.contact.placeholder`. Prefix with `__RAW__` or `__LIT__` to opt out.
- **URL attributes** (`href`, `src`, `data`, `poster`, `action`, `formaction`, `cite`, `srcset`) get base-URL and language-prefix processing automatically. System placeholders inside them (`{{__current_page;…}}`) are resolved before URL processing.

This is the single source of truth for page content. Everything the admin panel does ultimately writes back to one of these JSON files (or to `routes.php` / `translate/*.json` / `style/style.css`).

---

## 3. Management layer — the API

Every operation in QuickSite runs through one HTTP endpoint:

```
{POST|GET} /management/{command}
Authorization: Bearer qsa_xxx        ← short-lived access token from /management/login
Content-Type: application/json
```

Mutations use POST; pure read commands (e.g. `help`, `getRoutes`, `listAssets`) accept GET as well.

### How a command is implemented

Each command is a single PHP file under `secure/management/command/`. The file:

1. Reads validated parameters via `TrimParametersManagement`.
2. Performs the operation against project files.
3. Returns an `ApiResponse` (status code + machine code + message + optional `data` / `errors`).

```php
// secure/management/command/addRoute.php
$params = $trimParametersManagement->params();
$route  = $params['route'] ?? null;

if ($route === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route is required')
        ->send();
}
// … create folder, JSON, update routes.php …
ApiResponse::create(201, 'route.created')
    ->withData(['route' => $route])
    ->send();
```

The full list of 178 commands is registered in `secure/management/routes.php`. See [COMMAND_API.md](COMMAND_API.md) for the catalogue and a per-command reference (also obtainable at runtime via `GET /management/help`).

### Response shape

```json
{
  "status": 201,
  "code":   "route.created",
  "message":"Route created successfully",
  "data":   { "route": "contact" }
}
```

Errors include a structured `errors` array with `field` / `value` / `reason`.

### Authentication & roles

Credentials are **username + password** (the username is the private login identifier; public identity is the display name + the user id — there is no email field): `users.php` holds each user's `password_hash` (a `null` hash marks an externally-managed account — its sessions are minted by an embedding platform, never by password login). The public `login` command exchanges them for a **session** — a short-lived access token (sent as `Authorization: Bearer` on every call) plus a rotating refresh token (`refreshSession`; reusing a rotated refresh token after a short grace window revokes the whole session family — a theft becomes visible instead of silent). Runtime sessions live hashed in `secure/management/config/sessions.json`; the TTL knobs and the registration policy live in `auth.php` alongside CORS. The admin panel holds its session server-side in the PHP session and only ever exposes the short-lived access token to the browser.

Accounts are **self-created only**: the public `register` command (and the matching `/admin/register` page, linked from the login page) creates an account when `auth.php` `registration.allow_self_registration` is true — the default is **false**, meaning a fresh install accepts no registrations and accounts exist from setup only. Registration is flood-controlled (per-IP rate, install-wide hourly cap, optional absolute account cap) and enumeration-safe (a duplicate username is indistinguishable from a success — login identifiers are private). `changePassword` lets an authenticated user rotate their own password — it requires the current password, shares the login throttle, and revokes the user's other sessions on success. `deleteMyAccount` is its destructive counterpart: current password plus an explicit confirmation, every session ended, and the caller detached from every project they belong to. No command creates, disables, or deletes an account **for someone else** — authority in QuickSite is per project, so a person is parted with per project (`removeMember`) or, at the operator level, by editing `users.php`; server-to-project provisioning is deliberately deferred to the platform-integration design. Because a project must always have exactly one owner, self-deletion is refused while the caller solely owns a project — ownership is handed over or the project destroyed first, each an explicit act of its own.

Authorization is **per project**. Each project's `config/members.json` assigns its members one of six fixed roles:

| Role | rank | Adds (cumulative) |
|---|---|---|
| `viewer` | 1 | read structure, content, styles; see the project roster (members only — every rank can see who is on the project); propose new members (every member may vouch an outsider — validation stays admin+) |
| `editor` | 2 | edit content, translations, routes, assets, interactions, privacy copy; read integration config |
| `designer` | 3 | styles, CSS variables, animations, theme |
| `developer` | 4 | builds + server-side route resolvers |
| `admin` | 5 | deploy, API / OAuth config, iframe sandbox, backup / export, command history; manage members (invite, adjudicate join requests, join policy) |
| `owner` | 6 | delete the project + transfer ownership; the single top of the project, cannot be removed by others |

> AI is not a permissioned column: AI calls happen in the browser via `QSAiCall` against per-user credentials in `aiConnectionsV3` (localStorage). Any authenticated admin can use the AI workspace; gating happens at the connection level, not the role level.

Roles are **fixed** — there is no superadmin and no custom roles. A role is defined as a set of trust-coherent command **categories** in `secure/management/config/categories.php` (e.g. `content.read`, `style.write`, `deploy`, `project.delete`); `roles.php` grants each role a `rank` and its categories, which are expanded to a per-command allowlist at load time. Every command belongs to exactly one category, and its category also fixes its **scope**: *global* (a set any authenticated user may run — `createProject`, `importProject`, `listProjects`, `getMySpaceUsage`, `setSelectedProject`, `changePassword`, `deleteMyAccount`, `findUser`, the membership self-service commands, `listRoles`, `getMyPermissions`, `checkForUpdates`, `listWorkflowBlocks`, `lintWorkflows`) or *project* (requires membership). A global category is either open to any authenticated user or closed to everyone; there is no owner-gated global tier, because "owner" is a fact about one project and cannot authorize an action that has no project. Anything installation-wide — applying an engine update, choosing the served main — is therefore either operator-side or expressed as a project-scoped action on the project it affects. Global reads are still caller-relative: `listProjects` returns only the projects the caller is a member of (with their role on each) and `getMySpaceUsage` aggregates disk usage only across the projects the caller *owns* — there is no all-projects API view, and project-scoped reads report only the project targeted in the URL. `owner` is the top of each project; `rank` orders the roles and governs role management — a granter may only assign a role strictly below their own (the self-escalation guard). Permissions are checked before the command file is included.

Membership itself changes on a **consent model**: an admin or owner *invites* an existing account to a role (rank-checked at send), the invitee sees the offer in their own invitation inbox, and the grant materializes only on `acceptInvitation` — where the inviter's authority is **re-validated** (a demoted or removed inviter's offer is void). Pending invitations live in a separate `invitations` block of `members.json`, so a pending entry is structurally unable to grant access — every permission check reads `members` only. Users are targeted by their opaque `user_id` (discovered by exact public-name lookup, `findUser`); membership output references people as `{user_id, name}` and never exposes the private login username. Ownership rotates atomically via `transferOwnership` (owner-only, member-only target, confirmation required); removals and project deletions leave a dismissable notice in the affected user's own project list, while self-initiated exits (leave, decline, withdraw) leave none.

Membership can also start from the **other side**. With the project's `join_policy` set to `open` (`setJoinPolicy`, default `closed`), an authenticated outsider may `requestToJoin` — a mandatory-note ask, fixed at the `viewer` role, that an admin/owner answers with `approveJoinRequest` (joins immediately: both consents now exist) or `denyJoinRequest` (mandatory reason, dismissable `refused` notice; re-asking is blocked until the notice is dismissed). Separately, ANY member may `proposeMember` — vouch an outsider with a mandatory note, at a role no higher than the sponsor's own rank (a viewer proposes viewers, an editor up to editors); the proposal grants nothing, the person is told nothing, and on validation it *converts into a normal invitation* carried by the approver's rank (`sponsored_by` preserved), which the person accepts or declines like any invite — membership always materializes on exactly two consents: the person's and a ranking authority's. Enumeration posture: a private project with a closed policy answers `requestToJoin` identically to a nonexistent one; opening the policy on a *private* project deliberately makes it knockable-by-id (flagged by the command); on public projects existence is already public via `/p/<id>/`, so a closed lane answers honestly. A requester's own inbox shows a private project's *id*, never its site name, until they are a member. Two read surfaces complete the picture: `getProjectRoster` gives every member rank the active-members roster (no pending queue — adjudication data stays admin/owner via `listMembers`), and `listMyProposals` gives a sponsor their own outgoing proposals (pending validation, or approved and awaiting the person's answer).

### Extending one command via builders — `addComplexElement`

Most commands do one thing. `addComplexElement` is the exception: it's a single command that dispatches to a registry of **builders** auto-discovered from `secure/src/classes/complexElements/*.php`. Each builder is a `ComplexElementBuilder` subclass that turns a wizard-supplied config into a node spec (pure: config in → node out, no I/O), which the command then splices into the structure under one file lock by reusing `addNode`'s insertion helper.

The pattern means new wizard kinds (field-row, form-scaffold, select, list — and future radio, checkbox, table, …) ship as a single PHP file drop. No `routes.php` / `roles.php` / `help.php` edits, no new commands. The dispatcher globs the directory at request time and registers every subclass by its declared `kind()`.

After save, the emitted subtree is indistinguishable from a hand-built one — same JSON shape, same renderer, editable with the regular visual-editor tools. The wizard is build-time only; nothing at render time knows the element came from there.

See [ADMIN_PANEL.md §8.7](ADMIN_PANEL.md#87-complex-element-wizard) for the per-kind catalogue, the matching client-side wizard contract, and the full 2-file recipe for adding a new kind.

---

## 4. JSON → HTML pipeline

Page structure JSON becomes HTML in two ways depending on context:

```
              JSON structure (templates/model/json/*.json)
                            │
              ┌─────────────┴─────────────┐
              ▼                           ▼
   JsonToHtmlRenderer            JsonToPhpCompiler
   (runtime / dev / editor)      (build step)
              │                           │
              ▼                           ▼
     HTML string per request      Static .php template
     ?_editor=1 adds              No JSON parsing on
     data-qs-* attributes         hot path
```

- **Runtime** (`secure/src/classes/JsonToHtmlRenderer.php`): parses JSON on every request. Used in development and inside the editor preview iframe (which appends `?_editor=1` so the renderer also emits `data-qs-node` / `data-qs-struct` attributes for click selection).
- **Build** (`secure/src/classes/JsonToPhpCompiler.php`): emits a self-contained PHP template per page. Translation calls remain dynamic, but the structural traversal happens at build time. This is what production sites serve.

Components are inlined at parse time. `{{var}}` placeholders inside a component are interpolated from the caller's `data`.

---

## 5. Request lifecycle

### 5.1 Public site request

```
GET /fr/about
  │
  ▼  Apache .htaccess → public/index.php
  │
init.php
  ├── defines PUBLIC_FOLDER_ROOT, SECURE_FOLDER_PATH
  ├── reads target.php → active project name
  ├── loads project config.php → CONFIG
  └── loads project routes.php → ROUTES
  │
index.php
  ├── checks aliases (data/aliases.json) → may redirect
  ├── TrimParameters parses URL → (lang, route, params)  ── see §5.3
  ├── validates route ∈ ROUTES (else 404)
  └── includes templates/pages/{route}/{leaf}.php
  │
Page template
  ├── new Translator($lang)
  ├── new JsonToHtmlRenderer (or in build mode, static PHP runs directly)
  ├── renders structure → HTML body
  └── Page::render() emits <!doctype>, <head>, menu, body, footer
```

A request under `/p/<projectId>/` (the per-project live view, §6) is intercepted at the top of `public/index.php` — before `init.php` binds the main project — and scoped to that project's own folder: it gates access by visibility + membership, then either live-renders the page through the same pipeline above or serves a static sub-resource through the prefix-checked passthrough (§7). The main project keeps rendering exactly as shown.

### 5.2 Management API request

```
POST /management/addRoute       Authorization: Bearer qsa_xxx
  │
public/management/index.php
  ├── validates the access token → resolves user (sessions.json → users.php)
  │     (expired → 401 auth.token_expired; the client refreshes + retries)
  ├── checks the user's project role permits 'addRoute'
  │     (members.json role → categories.php → roles.php) → 401/403 if not
  ├── resolves command via secure/management/routes.php
  └── includes secure/management/command/addRoute.php
  │
addRoute.php
  ├── validates params (route format, length, charset)
  ├── ensures route does not exist
  ├── creates folder + JSON template
  ├── updates routes.php (via varExportNested — string keys preserved)
  └── ApiResponse::create(201, 'route.created')->send()
```

The same pattern — parse → validate → mutate files → `ApiResponse` — is used by all 178 commands.

### 5.3 Routing — exact and parameterised routes

A route declared in a project's `secure/management/routes.php` is one of:

- **Exact** — a literal path like `'about'` or `'blog/2026/announcement'`. Matches the URL bit-for-bit. The historical default; still the right choice for one-off pages.
- **Parameterised** — a path with one or more `:name` segments, like `'products/:slug'` or `'users/:id/posts/:postId'`. One template serves many URLs; the captured values are exposed to PHP (`$slug`, `$id`) and to qs.js (`QS.routeParams.slug`).

#### Param syntax

`:name` follows the Express convention. The identifier rules match the addRoute validation:

- starts with a lowercase letter or underscore
- continues with lowercase letters, digits, or underscores
- no hyphens, no uppercase, no special characters inside the identifier

NTFS reserves `:` in filenames, so the on-disk page folder stores the segment as a doubled-underscore prefix (e.g. `pages/products/__slug/__slug.php`). The translation between the URL form (`:slug`) and the on-disk form (`__slug`) lives in `secure/src/functions/routeHelpers.php`. Always require that file from consumers — never inline the pattern.

#### Matching algorithm

`secure/src/classes/TrimParameters.php` walks `ROUTES` for each incoming request and picks the **most specific** match:

1. Split the URL path into segments. Drop the language prefix if present.
2. For each route in `ROUTES`, compare segments. A literal segment must equal the URL segment exactly; a `:name` segment captures whatever's there.
3. **Score** each matching route by the count of literal (non-`:`) segments. The highest score wins.
4. On a tie, the route declared first in `routes.php` wins.

Worked example — three registered routes:

| Route | Score | `/shop/sale/clearance` | `/shop/sale/red-vase` | `/shop/winter/jacket` |
|---|---|---|---|---|
| `shop/sale/clearance` | 3 | matches → wins | doesn't match | doesn't match |
| `shop/sale/:item` | 2 | matches | matches → wins | doesn't match |
| `shop/:cat/:item` | 1 | matches | matches | matches → wins |

Captured values are URL-decoded before exposure, matching PHP's `$_GET` convention: `/products/red%20vase` exposes `slug = 'red vase'`.

#### How captured params flow

- **Server (PHP)** — `Page::render()` injects each captured value as a template variable named after the param. Inside a page's PHP template, `$slug`, `$id`, etc. sit alongside `$translator` and other request-scoped variables. Inside renderer-driven JSON pages, a `{{param:NAME}}` placeholder is substituted in both raw text and translated text by `JsonToHtmlRenderer::renderTextNode`. The literal `param:` prefix is required so it doesn't collide with component-variable patterns.
- **Client (qs.js)** — The build emits `public/scripts/qs-route-schema.js` listing every route's pattern + param shape. qs.js's synchronous IIFE walks the schema against `location.pathname` on load and exposes three globals: `QS.routeParams` (a dict of captured values), `QS.routePath` (the matched pattern), `QS.routeFound` (a boolean). State stores can initialise a field from `init: 'param:slug'` — a fifth source kind alongside the existing `query:` / `localStorage:` / `sessionStorage:` / literal. The matcher is purely client-side; for a deeper URL → live data loop (server-rendered authed pages, SEO) the server data resolver builds on the same schema.

#### Conflict detection

`addRoute` runs the same matcher against the existing route set when creating or editing a route. It **blocks** exact duplicates, and **warns** in two cases:

- Param route added at a level that has exact siblings — e.g. registering `/products/:slug` when `/products/featured` already exists. The legitimate "curated landing + param catch-all" pattern every CMS supports. Runtime is safe via specificity scoring; the warn surfaces intent.
- Two param routes at the same depth — e.g. `/products/:slug` and `/products/:id`. Ambiguous which name captures a given URL segment; declaration order decides at runtime.

The response carries a `warnings` array of `{ type, message }` entries. The sitemap UI renders each warning as a toast after the success toast.

#### Limitations

- **Wildcards** (`:*`, `**`). A param captures one segment.
- **Per-param type matching** beyond string. The schema's optional `type` field is reserved for a future `'integer'` form but unused at the matcher today.
- **Param defaults**. A route with required params 404s when the URL is missing a segment. Users who want optional captures hand-author both shapes (`/products/:product` and `/products/:product/:variant`).
- **Case-insensitive matching**. Paths are case-sensitive, matching Unix filesystem + HTTP convention.

---

## 6. Multi-project model

One QuickSite installation hosts any number of projects, each with its own config, routes, templates, translations, interactions, assets, and backups:

```
secure/projects/
├── portfolio/
├── client-website/
└── documentation/
```

A project is reachable three ways — the **three surfaces**:

- **Main site (root).** `secure/management/config/target.php` names one **main project**, served at the site root (`/`, or under a configured URL space). Its `assets/`, `style/`, `build/` and `sitemap.txt` are materialised into the live `public/` folder so the root site matches — the only surface that materialises anything. Setting the main project is `switchProject`, which is **owner-only and project-scoped**: it is targeted as `/management/p/<projectId>/switchProject`, so only an owner of that project may make it the served main. Deleting the project that is currently served clears the pointer rather than promoting another project — the next main is always chosen deliberately.
- **Per-project live view — `/p/<projectId>/`.** Every *other* project is live-rendered from its own `secure/projects/<id>/public/` folder under `/p/<projectId>/`, with no materialisation. The HTML runs through the same renderer as the main site; static sub-resources (style, scripts, images, fonts) are served through a canonicalised, prefix-checked passthrough that exposes **only** that project's `public/` subtree (§7). `/p/<mainProject>/` redirects to the root. The `/p/` prefix keeps a project view from ever shadowing a main-site route.
- **Visual editor.** The admin panel edits one project at a time — the main project at the root, any other via its `/p/<id>/` view in editor mode.

**Project visibility.** Each project's `config/members.json` carries a `visibility` flag:

- `public` — the `/p/<id>/` view is open to anonymous visitors (a shareable site).
- `private` — the `/p/<id>/` view requires membership (owner / member / viewer). Membership is presented by a short-lived, HttpOnly `qs_preview` cookie the admin panel sets from the caller's access token (a bearer header is accepted too).

A refused `/p/<id>/` request (no identity → `401`, identity but not a member → `403`) renders the **main project's** error page with that status: a dedicated `401` / `403` page when the main project has one (the special-pages set is `404`, `500`, `403`, `401` — create `templates/model/json/pages/401/…` like any page), else its `404` page. The refusal page never names the project.

**Per-user editing.** A user's `selected_project` (in `users.php`, set via `setSelectedProject`) names the project their admin panel edits. It is a per-user preference, **never an authorization input** — every request is re-authorized against the target project's `members.json`. Because each project is served *and* edited from its own folder, two users can edit two different projects at once without colliding, and the main project (`target.php`) is independent of any user's `selected_project`.

**Generated client artifacts are per-project.** The compiled `qs-api-config.js` / `qs-enums.js` / `qs-route-schema.js` are written into each project's own `public/scripts/` when its API / enums / routes change, plus a mirror into the live `public/` when the edited project is the main one (so the root site stays in sync). Serving `/p/<id>/` regenerates any missing artifact on demand.

Tokens, role/category definitions, command code, interaction schemas, and the admin panel are shared across all projects.

---

## 7. Security boundary

The single most important architectural rule:

> **`public/` is the only thing Apache serves. Everything in `secure/` is reachable only via PHP `require_once` from inside `public/`.**

This puts auth tokens, project sources, command implementations, builds, backups, and logs out of reach of any direct HTTP request, even with directory traversal.

Other layered protections:

| Concern | Protection |
|---|---|
| Path traversal | All file paths normalised and checked against `..`; whitelisted base directories; helpers in `secure/src/functions/PathManagement.php`. |
| XSS in JSON content | Only allowlisted tags render: a tag must be on `TagRegistry::ALLOWED_TAGS` and off the blacklist (`script`, `noscript`, `style`, `template`, `slot`, `object`, `embed`, `applet`) — anything else is dropped. URL attributes (`href`, `src`, `xlink:href`, `ping`, `srcset`, …) accept only `http` / `https` / `mailto` / `tel` schemes plus relative / anchor / protocol-relative values; everything else becomes `#`. All attribute values are HTML-escaped. The renderer and the compiler enforce this identically (`TagRegistry::isRenderable`, `UrlPolicy`), so preview and built output agree. |
| Inline JS injection | `on*` attributes only accept the `{{call:fn:args}}` syntax (see §8), transformed to allowlisted `QS.*()` calls (`CallTransformer`); raw JS is rejected. The tag, URL-scheme and `on*` policies are also enforced by the node writers (`addNode` / `editNode` / `editStructure`), so unsafe values are refused on write, not just dropped at render. |
| File upload | MIME sniffed from content (not extension); per-category size cap; JS uploads disallowed. |
| AuthN / AuthZ | Username+password login → short-lived access token (Bearer) + rotating refresh token with reuse-detection (family revoke); role check on every Management call; logged. Session tokens are stored hashed; failed logins are throttled per username. |
| CSRF on admin | Admin panel sends the Bearer access token via `fetch`; the refresh token never reaches the browser (held server-side in the PHP session). |
| CORS | Configurable per deployment. |
| Per-project serving (`/p/<id>/`) | Static sub-resources are served **only** from the project's own `public/` subtree via a `realpath` canonicalisation + prefix check (a jail): any path resolving outside `…/public/` is refused, so `config/` (members.json), `data/` (api-endpoints.json), `routes.php`, `config.php`, `templates/`, `translate/` are unreachable, and encoded / backslash / absolute traversals are rejected. HTML is always live-rendered, never served as raw project files; the view runs the same render/compile sanitisation as the built site, plus a Content-Security-Policy. Private projects require membership. |

---

## 8. Interactions (v2)

Behaviour is declared **inline in the JSON structure** rather than injected as raw JavaScript:

```json
{
  "tag": "button",
  "params": {
    "class": "close-btn",
    "onclick": "{{call:hide:#contact-modal}}"
  }
}
```

The renderer turns `{{call:fn:arg1,arg2}}` into a namespaced call to a registered function. Only registered functions can be called; arbitrary inline JS is rejected. The keyword `event` passes through unquoted so handlers like `oninput="QS.filter(event, '.card')"` work.

A single argument can contain literal commas by escaping them with `\,`. For example, `{{call:filter:event,.cmd-card,.cmd-name\, .cmd-description,hidden}}` passes `.cmd-name, .cmd-description` as a single `matchAttr` value (a comma-separated child-selector list, see `QS.filter`), not two separate args. Both the JSON-side parser (`interactionHelpers.parseCallSyntax`) and the shared transform (`CallTransformer::transform`) honour the escape and unescape it before quoting.

**Reserved class names.** `qs.js` self-injects `<style id="qs-hidden-style">.hidden{display:none!important}</style>` into the document head at script-load time so projects without a `.hidden` rule still get a working hide. This makes `.hidden` a **reserved QuickSite class**: do not redefine it in your CSS — the `!important` will win regardless and the override won't apply. To get animated/custom hide behaviour, define your own class (e.g. `.fade-out`) and pass it as the `hideClass` arg to `QS.show`/`QS.hide`/`QS.toggleHide`/`QS.filter` instead.

The core registry currently exposes 26 built-ins:

| Group | Functions |
|---|---|
| Visibility | `QS.show`, `QS.hide`, `QS.toggle`, `QS.toggleHide` |
| Classes | `QS.addClass`, `QS.removeClass` |
| Forms | `QS.setValue`, `QS.focus`, `QS.blur`, `QS.validate` |
| Navigation | `QS.redirect`, `QS.scrollTo` |
| Data | `QS.filter`, `QS.fetch`, `QS.renderList` |
| Feedback | `QS.toast` |
| Auth / storage | `QS.saveToken`, `QS.store`, `QS.clearToken`, `QS.refresh`, `QS.exchangeMagicLink`, `QS.requestMagicLink`, `QS.logoutServer` |
| State stores | `QS.setState`, `QS.fetchState`, `QS.onScrollFetchState` |

Use the live `listJsFunctions` command for the authoritative list. The catalog itself is declared **once** in `secure/src/functions/qsVerbCatalog.php` (the single source of truth) and is read by three consumers: the `listJsFunctions` command (picker payload), `JsonToHtmlRenderer` (runtime `{{call:fn:...}}` allowlist), and `JsonToPhpCompiler` (build-time allowlist). Add a new QS.* verb by adding an entry to that catalog file — the picker, the renderer, and the compiler all pick it up automatically; an unknown verb wired anyway is dropped with a `console.warn('[QS] unknown verb …')` in the rendered page. (The renderer allowlist also accepts `applyAuthState` for hand-authored manual re-scans; it is intentionally not surfaced in the picker.)

Beyond the `{{call:…}}` verbs, the auth-flows runtime adds **declarative bindings** read by `qs.js` on load + on `qs:auth:*` events — `data-auth-show` (with four modes: `in` / `out` for token presence, `connecting` / `failed` for the Tier 3 magic-link exchange lifecycle) / `data-auth-source` and the generic `data-storage-show` / `data-storage-value` (any storage key) and `data-consent-show="granted|denied:<category>"` (visibility gated on cookie-consent state) — plus the `QS.isAuthed(source)` query. The Tier 3 magic-link verbs (`exchangeMagicLink`, `requestMagicLink`, `logoutServer`) dispatch `qs:auth:exchange-started` / `qs:auth:exchange-failed` to drive the connecting/failed modes. All documented in `ADMIN_PANEL.md §9.5`.

`QS.filter` accepts a polymorphic `matchAttr` (3rd arg): omit it (or pass `textContent`) to match the element's text; pass a `data-*` name to match an attribute; pass a CSS selector starting with `.`, `#`, `>` or space to match the concatenated `textContent` of one or more **descendant** elements (e.g. `.cmd-name, .cmd-description` for "search across both"). The descendant-text and textContent modes also highlight matches in place via an XSS-safe DOM walk (skipped above a 500-node budget).

Custom logic is expressed by composing the core functions or, for richer client behaviour, registered API endpoints (see `addApi` / `testApiEndpoint`).

#### 8.0.1 Chain execution & ordering

Calls in a handler attribute (`{{call:a}};{{call:b}};…`) are compiled by `CallTransformer::transform` (shared by the renderer and the compiler) with three rules:

1. **Sync prelude** — verbs in `CHAIN_SYNC_PRELUDE` (`validate`) emit first as plain `QS.foo(...)` statements. They run inside the event tick and can still call `event.preventDefault()` or `throw` to abort the rest.
2. **Async wrap** — if the remaining body contains at least one verb from `CHAIN_AWAITABLE` (`fetch`, plus the Tier 3 magic-link verbs `exchangeMagicLink` / `requestMagicLink` / `logoutServer`) AND there is more than one call, the body is wrapped in `(async()=>{await A;await B;…})().catch(e=>console.warn('[QS] chain aborted:',e))`. Every call gets `await`'d, so "fetch then hide" — or "exchangeMagicLink then saveToken then redirect" — actually waits for the response.
3. **Backward-compat** — a sole `{{call:fetch:...}}` (or a chain with no awaitable verb) stays as-is. Single-call handlers and pure-sync chains don't pay the microtask cost.

Side channel: `QS.fetch` also dispatches `qs:fetch:loaded` / `qs:fetch:error` DOM events (with `detail.{ref, data}` or `detail.{ref, error}`) and caches the latest result per ref in `QS._fetchCache`. Use this when an action must react to a fetch fired from a different handler/page — see `QS.after(eventSuffix, handler)` and `QS.onceCached(eventSuffix, handler)` in `qs.js`. The chain rules above stay the default UX; events are the escape hatch.

`event.preventDefault()` caveat: after the chain enters its async IIFE, calling preventDefault is a no-op (the default action has already fired). `validate` is in the sync prelude precisely for this reason. Any future verb that needs preventDefault must be added to `CHAIN_SYNC_PRELUDE`.

#### 8.0.2 Compile-time translation resolution

The renderer resolves translation keys at compile time so the rendered HTML carries the per-language **string**, never the key — `qs.js` at runtime has no access to translation files.

Two parallel resolution paths in `CallTransformer` (`buildCallJs`):

1. **Keyword-arg path** (the original) — a `TRANSLATABLE_KEYWORD_ARGS` const lists per-verb kwarg names. The first consumer was `fetch`'s `toastSuccessKey` / `toastErrorKey`. Pattern: `{{call:fetch:@api/ep,toastSuccessKey=form.contact.success}}` → resolved kwarg value substituted in the rendered call.

2. **Positional-arg path** (catalog-driven) — reads `qsVerbCatalog()` and collects positional indices flagged `inputType: 'translationKey'` per verb (cached per verb on first lookup). For each such arg in the chain, `Translator::translate(value)` is called; if the result is the missing marker (`{translation missing: <key>}`), the value passes through unchanged (the `allowFreeText` fallback path for raw Custom Text inputs).

Today's positional users: `toast.message` (with `allowFreeText: true`). Future verbs declaring `inputType: 'translationKey'` on a positional arg are picked up automatically — no renderer code change required.

The build path (`JsonToPhpCompiler`) calls the same `CallTransformer::transform`, so render and compile stay in lockstep. Multi-language sites work natively: source JSON is identical across languages; each per-request render produces a per-language compiled chain.

See [ADMIN_PANEL.md §9.9](ADMIN_PANEL.md) for the authoring UX (translationKey picker + Custom Text sentinel in §9.9.7) and the full inputType taxonomy (§9.9.4).

### 8.1 External API registry (`QS.fetch`)

`QS.fetch('@<apiId>/<endpointId>', 'name=value', …)` resolves the
target against `window.QS_API_ENDPOINTS`, a registry compiled
server-side from per-project `data/api-endpoints.json`.

| Concern | Where |
|---|---|
| Storage (per project) | `secure/projects/<project>/data/api-endpoints.json` |
| Server class | `secure/src/classes/ApiEndpointManager.php` |
| Public bundle | `public/scripts/qs-api-config.js` (auto-regenerated on every `addApi` / `editApi` / `deleteApi`) |
| Runtime | `public/scripts/qs.js` → `QS.fetch` |
| Admin UI | `/admin/apis` — see [ADMIN_PANEL.md §9.1](ADMIN_PANEL.md). |

**Path templating**: endpoint `path` may contain `:placeholder`
segments. At runtime, `QS.fetch` substitutes each `:name` with
`opts.name` (URL-encoded). Missing **required** placeholders reject
with a toast; missing **optional** ones stay literal so the
omission is visible. Remaining (non-reserved) opts become
query-string parameters.

**Path templating example**:
```
endpoint.path: /users/:id/posts/:postId
call:          QS.fetch('@my/get-post', 'id=42', 'postId=7', 'expand=author')
result URL:    GET <baseUrl>/users/42/posts/7?expand=author
```

**Foreign-format import**: the admin page's import modal accepts
both our native shape (`{ "apis": {...} }`) and recognised foreign
formats (currently: a file-manager-style JSON with
`endpoints.{public, secured, …}` groups). The converter:
- Treats every top-level group under `endpoints` as its own API
  (named `<base>-<group>`).
- Detects bearer/basic/apiKey auth from a group's `authentication.type`.
- Rewrites each endpoint's `path` to include `:placeholders` based
  on declared parameters + the source's `route_format` hint
  (e.g. `"path segments"` → `/name/:name`).
- Preserves an existing `:placeholder` if one is already present.

### 8.2 Enum sync (`qs-enums.js`)

A small runtime registry keeps client-side `componentList` bindings
type-aware without re-fetching component templates. The picker UX is
documented in [ADMIN_PANEL.md §9.2](ADMIN_PANEL.md#92-component-list-binding);
this section covers the back-end synchronisation.

**The invariant**

```
binding references  ⊆  qs-enums.js contents  ⊆  union of all components' __enums__
```

- Components' `__enums__` blocks (in `<project>/templates/model/json/components/*.json`) are the source of truth. Each entry: `{ source, map: {key: '__RAW__VALUE' | '__LIT__VALUE' | ...} }`.
- `public/scripts/qs-enums.js` is a project-scoped runtime registry. Contains **exactly** the enums that at least one binding references — no more, no less. Loaded by every page (when present) as `window.QS_ENUMS`.
- Bindings reference enums by fully-qualified name: `<componentFilename>.<shortKey>` (e.g. `component-command-card.method_text`). Resolved at runtime via `QS.enum(name, value, fallback)`.

**The helper**

`secure/src/classes/EnumSyncHelper.php` exposes one method:

```php
EnumSyncHelper::sync($projectPath = null, $publicScriptsPath = null)
```

Algorithm (per call):
1. Scan all endpoints' `responseBindings.fieldMap.*.enum` → set of referenced fully-qualified names.
2. Scan every `<project>/templates/model/json/components/*.json` for `__enums__` → map of available names.
3. Validate: every referenced name has a definition. Missing references become **warnings**, not errors — the runtime gracefully degrades via `QS.enum`'s fallback (`fallback ?? value`).
4. Build output: only entries that are BOTH available AND referenced. Sort keys for stable diffs.
5. Strip `__RAW__` / `__LIT__` markers from values (renderer-only prefixes; the runtime reads plain strings).
6. Write `public/scripts/qs-enums.js` with:
   ```js
   window.QS_ENUMS = { "component-command-card.method_text": { post: "POST", get: "GET", ... }, ... };
   ```

Forgiving by design: a bad binding doesn't block the save. The
helper returns `{ ok, written, count, warnings, unreferenced }` so
the calling command can surface warnings in the API response.

**Hooks**

| Caller | When | Effect |
|---|---|---|
| `editApi` | after `writeCompiledJs` | Resyncs on every endpoint add/edit/delete. Response includes `enumSync` block. |
| `switchProject` | after qs-api-config regeneration | Rebuilds the registry against the new project's components + bindings (the previous project's registry would be stale). |
| `build` | after `writeCompiledJs` to the build folder | Writes `qs-enums.js` into the build's `scripts/` so deployed sites have the registry. |

No component CRUD commands exist today, so changes to a component's
`__enums__` only refresh on the next binding edit / project switch.
Documented; not a blocker.

**Naming convention**

`<componentFilename>.<shortKey>` is the rule, enforced by the helper
(it prefixes on write). Filenames are unique within a project, so
two components can't collide on the same qualified name even if they
declare `__enums__` keys with the same short string.

Components keep declaring `__enums__` with **short** keys:

```json
"__enums__": {
  "method_text":  { "source": "method", "map": { ... } },
  "method_class": { "source": "method", "map": { ... } }
}
```

The runtime `QS.enum` lookup uses the **long** (qualified) name —
bindings store the qualified form; the helper provides the prefix.

### 8.3 State stores (`QS.setState` / `QS.fetchState`)

Interactions are otherwise stateless — a fetch fires, a response renders, nothing
is remembered. A **state store** gives them memory: a named, **page-scoped** client
view-model bound to **one** endpoint, whose fields seed from somewhere, mutate on
triggers, and update from responses. It underpins pagination, search, filters and
infinite scroll, and is the client half of the server-side data resolver
(the definition is runtime-agnostic JSON — one shape, two executors).

| Concern | Where |
|---|---|
| Storage (per project, keyed by route then store id) | `secure/projects/<project>/data/state-stores.json` |
| Server class | `secure/src/classes/StateStoreManager.php` |
| Read / write commands | `getStateStores` (read) / `setStateStores` (editStructure) |
| Runtime | `public/scripts/qs.js` → `QS._stores`, `QS.setState`, `QS.getState`, `QS.fetchState` |
| Page emit — live | `secure/src/classes/PageManagement.php` → `window.QS_STATE_STORES` |
| Page emit — built | `secure/src/classes/JsonToPhpCompiler.php` → `Page.php` (baked inline at build) |
| Admin UI | `/admin` visual editor → JS mode → "State stores" — see [ADMIN_PANEL.md §9.6](ADMIN_PANEL.md). |

Definition shape:

```json
{
  "home": {
    "commandsList": {
      "endpoint": "@help-api/list",
      "fetchOnLoad": true,
      "fields": {
        "page":  { "dir": "request",  "init": "query:page", "default": 1 },
        "total": { "dir": "response", "from": "meta.total" },
        "items": { "dir": "response", "from": "data", "append": false }
      }
    }
  }
}
```

Each **field** declares a **direction** vs the endpoint — `request` (sent only),
`response` (set from the response only), or `both` (sent from its current value,
then updated from the response). Sent fields (`request` / `both`) carry an **init**
(a literal, or a `query:`, `localStorage:` or `sessionStorage:` source) and a
**default** fallback for when that source is missing. Received fields (`response` /
`both`) carry a **from** response dot-path plus an optional **append** flag so a
list field grows (infinite scroll) instead of replacing. The field name *is* the
request parameter key. `both` is the canonical pagination cursor (`init` 0, `from`
the response's next-cursor field).

Verbs:
- `QS.setState(storeId, field, value)` — set a field to a literal, or to the live
  value of a `#id` / `.class` selector (e.g. a search box); re-renders the store.
  Also clears the store's exhausted flag (see below) — a fresh search re-arms
  scroll triggers.
- `QS.fetchState(storeId)` — build the request from the store's `request` / `both`
  fields, call the bound endpoint (reusing `QS.fetch`, so auth / refresh-on-401 /
  path templating all apply), apply the response into the `response` / `both` fields
  (appending where flagged), then re-render. Skipped while a previous call for
  the same store is in flight (`_inFlight` guard) — overlapping triggers are a
  no-op until the first settles. After settle, marks the store **exhausted**
  if any of: explicit `hasMore:false` in the response · any append-mode field
  returned 0 items · a `both` cursor came back unchanged. Compose them for real
  flows, e.g. `{{call:setState:results,q,#searchBox}};{{call:fetchState:results}}`.
- `QS.onScrollFetchState(storeId, triggerPx=200, debounceMs=100)` — register
  (once per store) a debounced window-scroll listener that fires `fetchState`
  only when the viewport is within `triggerPx` of the page bottom — and STOPS
  once the store is exhausted. Used as a page-event `onload` action for
  infinite scroll: `{{call:onScrollFetchState:list,200,100}}`. Raw
  `onscroll → fetchState` thrashes the API (one call per scroll tick); this
  verb is the safe equivalent. Also fires once 200ms after register to handle
  the "list shorter than viewport, can't scroll" case.

DOM bindings (store → DOM, re-applied on init / `setState` / `fetchState`):
- `data-state-value="storeId.field"` — element text = the scalar field.
- `data-state-list="storeId.field"` — container whose first child is the per-item
  template (`data-bind` on descendants; primitive arrays bind through the template
  root), with optional `data-state-empty` text. Because the store holds the full
  (appended) array, one whole re-render covers both replace and append.
- `data-state-show="storeId.field"` — toggles the standard `hidden` attribute
  based on the field's truthiness (falsy: `null` / `undefined` / `''` / `0` /
  `false` / `[]`). Use it to hide a "Next" button when the response's
  `nextPage` / `nextId` cursor is null at the last page, hide a "Load more"
  trigger when `hasMore` is false, gate a counter on `total > 0`, etc.
- `data-state-pagenav="storeId"` — a `<nav>` whose numbered-page buttons are
  rendered + re-rendered on every store update. Reads the configured
  `totalPages` field to size itself; writes the `page` field on click then
  re-fetches. Smart-ellipsis layout with the configured window size;
  optional Prev/Next chevrons. Emitted by the `paged-navigator` complex
  element, but the binding itself is hand-authorable. Companion attributes
  (all optional, defaults shown):
  - `data-state-pagenav-page-field="page"` — store field that holds the
    current page number (written on click).
  - `data-state-pagenav-totalpages-field="totalPages"` — store field that
    holds the page count (read to size the navigator). When this field is
    missing or `≤ 1`, the navigator hides itself.
  - `data-state-pagenav-window="2"` — how many sibling pages to show on
    each side of the current page before ellipsing. `0` collapses to just
    `1 … current … N`.
  - `data-state-pagenav-prev-next="true"` — adds `‹ Prev` / `Next ›`
    chevrons. Omit (or set to anything other than the literal string
    `"true"`) to hide them.
  See `ADMIN_PANEL.md §8.7` for the wizard that builds the nav; see §9.6
  for using it with an offset-pagination store.

**The store owns rendering.** `fetchState` passes a `noBindings` opt to `QS.fetch`
so the endpoint's own `responseBindings` are skipped on store fetches — otherwise an
append would flicker (bindings replace, then the store appends). Drive a store's
list via `data-state-list`, not via that endpoint's `responseBindings`.

### 8.4 Server-side data resolvers

The server-rendering layer. Where state stores (§8.3) give interactions memory
on the **client** post-load, a **resolver** declares "before this page renders,
fetch from API X and expose its response as template variables." The initial HTML
goes out with API data already baked in — SEO + AEO + first-paint win that state
stores alone couldn't deliver (crawlers see empty content until JS runs; AI
crawlers are even more conservative about running JS).

| Concern | Where |
|---|---|
| Storage (per project) | `secure/projects/<project>/data/route-resolvers.json` |
| Server-side execution | `secure/src/classes/DataResolver.php` → `resolveMany()` (handles single- and multi-resolver routes uniformly) |
| Server-side fetch | `secure/src/functions/serverFetch.php` → `serverFetch()` (single) / `serverFetchMulti()` (parallel via `curl_multi_*`) |
| Outbound SSRF guard | `secure/src/classes/OutboundUrlPolicy.php` — every server-side fetch is restricted to `http`/`https`, and in `production` the target is refused if it resolves to a loopback, private, or cloud-metadata address; the validated IP is pinned so DNS cannot rebind between check and connect. `development` (see `secure/management/config/environment.php`) lifts the internal-address block so a local/LAN API can be reached while building. Resolver fetches do **not** follow HTTP redirects. |
| Storage + validation helpers | `secure/src/functions/resolverHelpers.php` |
| File-based cache + observability | `secure/src/functions/resolverCache.php` + `X-QS-Resolver-Cache` header |
| Commands | `setRouteResolver` (set / clear / patch / append / remove), `cleanResolverCache` |
| Lifecycle position | `public/index.php` — AFTER auth gate (yes/no), BEFORE template render (`DataResolver::resolveMany()` fires once per request) |
| Hydration handoff | `secure/src/classes/PageManagement.php` → `window.QS_RESOLVED` (store-keyed for state-store skip-fetch) + `window.QS_RESOLVED_BY_INDEX` (resolver-index-keyed mirror of PHP `$r0` / `$r1`) |
| Admin UI | `/admin/sitemap` context menu — list view + per-config modal — see [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md). |

Sidecar shape supports both **scalar** (single config object) and **array** (list
of configs, multi-resolver). The on-disk shape is the only thing that differs — both
flow through the same `getResolversForRoute()` accessor which returns a normalised
array. Single resolvers stay scalar on disk for backward compat.

Multi-resolver semantics:
- **Parallel execution** via `curl_multi_*`. Total latency = max(individuals).
- **Cache key is endpoint + canonical inputs**, route-agnostic. Two routes — or two
  resolvers on one route — that hit the same endpoint with the same inputs share
  the cached entry.
- **Exposed vars merge into a flat namespace** (`{{resolved:NAME}}`). Collisions
  across resolvers are **rejected at save time** by
  `resolverHelpers.php::validateResolverConfigs`. Authors disambiguate by renaming
  OR by using the **namespaced address** (`{{resolved:r0.NAME}}` / `$r0['NAME']`
  in templates; `window.QS_RESOLVED_BY_INDEX.r0.NAME` in JS) — always available
  regardless of flat-namespace state.
- **Per-resolver `onMiss`** applies independently. `render-empty` on a failed
  resolver exposes its vars as null and the page continues rendering. Any
  failure WITHOUT `onMiss='render-empty'` short-circuits the whole page (404 or
  500 driven by the **first** unrecovered failure).

The headline architectural payoff: state-store JSON and resolver JSON are
**runtime-agnostic** — one shape, two executors. The same declaration that drives
a client-side store can drive a server-side resolver with minor extensions (the
`param:` source kind and the optional `cacheTTL` / `onMiss` keys).

**Auth gate vs auth data** — two distinct concepts that share a token/cookie
but operate at different lifecycle positions:

- The **auth gate** (yes/no decision: is this user allowed to access this
  route?) is framework-hardwired middleware running EARLIEST in the request
  lifecycle — before any resolver. Produces the session context (`$_SESSION` /
  cookie data) that downstream resolvers can read from via the `session:`
  source kind.
- The **auth data fetch** (who is this user? populate `$user` for the
  template) is just a regular resolver with `inputs: ['userId' => 'session:userId']`,
  sitting in the standard resolver lifecycle position (AFTER auth gate,
  BEFORE template render). It consumes the gate's session context to
  fetch user info from the user-api.

They share the token; they don't share the model. Treating both as
user-configurable resolvers would have overloaded the resolver concept with
framework-special behaviour at the earliest position. Treating them as one
mechanism (e.g. auth-gate-as-resolver) would have coupled the gate's
framework position to user-configurable lifecycle. Keeping them distinct
preserves a clean separation: the framework enforces access; the user
configures data. Locked rationale in
[DESIGN_DECISIONS.md](DESIGN_DECISIONS.md) under "Auth-gate vs auth-data —
distinct concepts".

**Side-effect resolver kinds** — `oauth-start`, `oauth-callback`,
`oauth-logout` extend the resolver pattern with a new archetype:
resolvers that **short-circuit the render** with a 302 redirect +
optional session cookie instead of feeding template vars. The
dispatcher routes side-effect kinds to `OAuthHandler` BEFORE the
data-fetch path; `validateResolverConfigs` rejects mixing side-effect
and data resolvers on the same route ("incoherent" — one short-
circuits while the other expects render to proceed). Storage shape is
identical to data resolvers (per-route sidecar entry; scalar or array
for single / multi). `RESOLVER_ALLOWED_KINDS` in
`secure/src/functions/resolverHelpers.php` lists all four. The
detailed flow + per-kind config schema lives in
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) — Tier 4 OAuth; the rationale
(kind dispatch, callback hook = resolver kind, BFF token custody)
lives in [DESIGN_DECISIONS.md](DESIGN_DECISIONS.md) OAuth section.

---

## 9. Style management

CSS is modelled as four addressable layers, all manipulated through commands rather than free-text edits:

| Layer | Commands |
|---|---|
| `:root` variables | `getRootVariables`, `setRootVariables` |
| Global selectors | `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule`, `getAnimatedSelectors` |
| `@keyframes` | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes` |
| `@media` queries | `getStyleRule` / `setStyleRule` with a `mediaQuery` parameter (selectors and keyframes can be scoped) |

`secure/src/classes/CssParser.php` parses the active project's CSS into an AST so any of those layers can be queried or mutated atomically.

---

## 10. Build & deploy

```
POST /management/build  { "public": "www", "secure": "app" }
```

Build steps, in order:

1. Acquire build lock (one at a time).
2. Validate output names and free space.
3. Create the build directory.
4. Compile every page JSON → PHP via `JsonToPhpCompiler`.
5. Compile menu and footer the same way.
6. Build interactions JS if the project uses them.
7. Copy `assets/` and `style/`.
8. Sanitise `config.php` (strip credentials).
9. Generate an `init.php` adjusted for the renamed public/secure folders.
10. Package into a ZIP under `secure/builds/`.
11. Return build stats.

Deploy is a separate command (`deployBuild`) that copies the ZIP contents into a target path. The renamed `public/` and `secure/` folders are part of the security model — anyone scanning the deployed server cannot guess paths from the open-source repo layout.

---

## 11. Translation system

```
project/translate/
├── en.json
├── fr.json
└── default.json   # fallback when multilingual is off
```

Files are nested JSON objects. `{ "textKey": "home.hero.title" }` resolves at render time via `Translator::translate('home.hero.title')`. Interpolation supports `{{name}}` placeholders.

**Multilingual vs monolingual.** When `MULTILINGUAL_SUPPORT = true`, `Translator` loads `{active-lang}.json` (`default.json` is the fallback when a key is missing from the active language). When `MULTILINGUAL_SUPPORT = false`, `Translator` loads `default.json` exclusively — `LANGUAGES_SUPPORTED` entries are NOT consulted at render time. The admin's Translation Manager mode is `default.json`-aware in monolingual mode (the language picker hides and writes target `default.json`).

Health is checked by dedicated commands:

- `validateTranslations` — keys missing from a language.
- `getUnusedTranslationKeys` — keys defined but never referenced.
- `analyzeTranslations` — full report.
- `getTranslationKeys` — scans every page / menu / footer / component structure and returns the union grouped by source (`keys_by_source: { 'home': [...], 'menu': [...], 'component:reassurance-item': [...] }`). Drives the Translation Manager's scope picker.

The admin panel reads the **composed** view via `public/admin/api/index.php` case `'translation-keys-grouped'`, which calls `getTranslation` + `getUnusedTranslationKeys` + `validateTranslations` and partitions the result into `{used, unset, unused}`. See [ADMIN_PANEL.md §8.9](ADMIN_PANEL.md) for the Translation Manager UI.

Key-format validation lives in `secure/src/functions/translationHelpers.php`'s `isValidTranslationKey()` — single source of truth, permissive (is_string + non-empty + no null byte). Translation values are user-controlled UTF-8 strings; the panel uses `textContent` everywhere (no `innerHTML`) to keep XSS off the table.

---

## 12. Where to look next

| If you want to … | Read |
|---|---|
| Understand the admin panel JS architecture | [ADMIN_PANEL.md](ADMIN_PANEL.md) |
| Drive the API from a script or an LLM | [COMMAND_API.md](COMMAND_API.md) |
| Run or write a workflow | [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) |
| Map the on-disk layout | [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) |
| See it running | the project [README](../README.md) |
