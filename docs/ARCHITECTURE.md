# QuickSite Architecture

> Canonical high-level overview of how QuickSite is structured and how a request flows through it. For the admin panel internals, see [ADMIN_PANEL.md](ADMIN_PANEL.md). For the workflow engine, see [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). For the full command reference, see [COMMAND_API.md](COMMAND_API.md). For the on-disk layout, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes. It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

---

## 1. Three-layer model

QuickSite separates concerns into three top-level layers. Each one has a clear boundary and is responsible for a different audience.

| Layer | Folder | Audience | Purpose |
|---|---|---|---|
| **Project** | `secure/projects/{name}/` | Site owner | The actual website data: routes, page structures (JSON), translations, components, interactions, styles, assets. |
| **Management** | `secure/management/` | API client (admin panel, scripts) | The 172 commands that read or mutate project data. Single entry point: `public/management/index.php`. Session + role enforced. AI calls bypass this layer entirely (browser-direct). |
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
               │init.php · p/index.php│
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
               │  snippets/ · logs/   │
               └──────────────────────┘
```

The split between `public/` and `secure/` is the **security boundary** (see §7). Apache only serves `public/`. Everything in `secure/` is reached only through PHP `require_once` from inside `public/`.

---

## 2. Project layer — data model

A project is a self-contained website on disk. One QuickSite installation can host multiple projects; each is served independently from its own folder under its own `/p/<projectId>/` view (§6), and the admin panel edits one of them at a time (§7).

```
secure/projects/{name}/
├── config.php           # languages, default lang, multilingual flag, etc.
├── config/              # members.json — owner, visibility, join policy, roster
├── routes.php           # ['home' => …, 'about' => …, 'docs/install' => …]
├── templates/
│   ├── model/json/      # Source of truth: JSON structure per page
│   ├── pages/           # (build only) compiled PHP templates
│   ├── menu.json
│   ├── footer.json
│   └── components/      # Reusable JSON components
├── translate/           # en.json, fr.json, … (or default.json mono-lang)
├── data/                # Aliases, metadata, API endpoints, state stores, resolvers
├── public/              # What /p/<name>/ serves
│   ├── style/           # Project CSS source
│   ├── assets/          # Project images / fonts / videos
│   ├── scripts/         # Generated client artifacts (the qs-*.js trio)
│   └── sitemap.txt      # Published sitemap
├── qs_build/            # The project's build (generated) — OUTSIDE public/,
│                        # so no URL reaches it; fetched via downloadBuild
├── exports/             # Export ZIPs (generated)
└── backups/
```

Interaction behaviour is not a folder: it is declared inline in the page JSON (§8).

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
- **Security by construction.** A single chokepoint applies the tag blacklist (see §7), requires an attribute name to be a well-formed identifier, and escapes attribute values. There is no path for raw `<script>` to slip through.
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
| Component | `{ component, data? }` | Inlines a component's JSON, with `{{var}}` interpolation. The reference is a bare component **name**, never a path — see §8.7. |

Attributes also accept a `{ condition, value }` shape for conditional rendering of a single attribute (evaluated server-side at render time).

#### Special prefixes & placeholders

A small vocabulary of markers controls rendering. They are described by intent — pick the one that matches what you mean, even when the renderer would accept either.

- **`__RAW__`** — for **text content**. Marks a `textKey` value as literal: do not look it up in translations, just emit it (escaped). Use this when a textual node really is a fixed string, not a key.
- **`__LIT__`** — for **attribute or component params**. Marks the value as literal so it is used as-is everywhere it can appear. In the renderer `__LIT__` and `__RAW__` overlap for text and translatable attributes; the distinction is one of intent for the next reader, not a behavioural difference.
- **`__enums__`** — a root-level metadata block on a component template that derives variables from a single source key. Each entry maps a derived variable name to a value map plus an optional default. Use `listComponents` at runtime to see what a component exposes.
- **`{{var}}` and `{{$var}}`** — placeholders interpolated from the caller's `data` when a component is inlined. Variable names accept letters, digits, underscores, and hyphens.
- **`{{__system__}}` placeholders** — request-time values (`{{__lang}}`, `{{__current_page}}`, `{{__current_route}}`, `{{__public_folder}}`, and the language-switch form `{{__current_page;lang=en}}`). Substituted in text AND in attributes, identically on the served site and in a build — see §8.7 for the order and why it matters.
- **Translatable attributes** (`placeholder`, `title`, `alt`, `aria-label`, `aria-placeholder`, `aria-description`) auto-resolve their value as a translation key when it looks like one — lowercase identifiers separated by dots, e.g. `form.contact.placeholder`. Prefix with `__RAW__` or `__LIT__` to opt out. Where an attribute is meaningful on a tag, the visual editor offers it as a translation-key picker rather than a text box — `alt` on `img` and `area`, `title` on `iframe`. Both commands that write nodes (`addNode`, `editNode`) treat these as ordinary optional params: whatever the author chose is stored verbatim, and choosing nothing writes no attribute.
- **URL attributes** (`href`, `src`, `data`, `poster`, `action`, `formaction`, `cite`, `srcset`) get base-URL and language-prefix processing automatically. EVERY placeholder inside them is substituted before the URL policy inspects the value, so the policy sees what the browser will receive (§8.7).

This is the single source of truth for page content. Everything the admin panel does ultimately writes back to one of these JSON files (or to `routes.php` / `translate/*.json` / `style/style.css`).

---

## 3. Management layer — the API

Every operation in QuickSite runs through one HTTP endpoint:

```
{POST|GET} /management/{command}
Cookie: QSSESSID=…                   ← the session, from /management/login
Authorization: Bearer <session_token>  ← that session's token, from the same response
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

The full list of 172 commands is registered in `secure/management/routes.php`. See [COMMAND_API.md](COMMAND_API.md) for the catalogue and a per-command reference (also obtainable at runtime via `GET /management/help`).

### Response shape

```json
{
  "status": 201,
  "code":   "route.created",
  "message":"Route created successfully",
  "data":   { "route": "contact" }
}
```

Errors include a structured `errors` array with `field` / `value` / `reason`. Any file or directory the envelope names is relative — to the targeted project where it lives inside one, to the installation root otherwise — so a response never discloses where the installation sits on disk. A command that acts on a set can also answer `207` when some members succeeded and some did not. Both are specified in [COMMAND_API.md](COMMAND_API.md).

### Authentication & roles

Credentials are **username + password** (the username is the private login identifier; public identity is the display name + the user id — there is no email field): `users.php` holds each user's `password_hash` (a `null` hash marks an externally-managed account — its sessions are minted by an embedding platform, never by password login). The public `login` command exchanges them for a **session**: it sets an HttpOnly session cookie and returns that session's token. Every later call presents both — the cookie as the credential, the token as `Authorization: Bearer` proving the caller could read a page of the session, which is what keeps a cookie-authenticated API safe from cross-site request forgery. Sessions are PHP's own, stored under `secure/tmp/sessions`; the lifetime knobs and the registration policy live in `auth.php` alongside CORS. Every session is killable from one integer: each user record carries a **session generation** that a session stamps at login and the server compares on every request, so raising it ends every session of that account at once — that is how "log out everywhere" works, and how a password change ends the user's other sessions while keeping the one that made the change.

**Only a deliberate write creates a session.** Presenting a session cookie that names no session gets no session and no `Set-Cookie` — the request is simply anonymous. Logging in creates one, and so does storing something for a visitor who has none (the admin language switch); reading never does. The distinction matters because a caller that ignores `Set-Cookie` — a script, a scanner, a crawler — otherwise asks for a session on every request and is handed a brand-new one each time, leaving a file behind each time.

**QuickSite expires sessions on its own rule and collects them on its own schedule.** A session idle longer than `idle_ttl` stops being accepted, whatever PHP thinks; PHP's own collector cannot do the tidying, because one `gc_maxlifetime` has to cover every session in the store and the longest promise here is "remember me" (30 days by default), so it refuses to touch anything younger than that. The sweep therefore runs on QuickSite's rule instead: opportunistically after a login (a 1-in-`sweep_divisor` chance, `auth.php`) and on demand from `php secure/cli/session-sweep.php`. It removes empty files, sessions already past `idle_ttl`, and non-QuickSite sessions sharing the store once they are past the longest lifetime this install promises anything — and nothing else. It is a script rather than a command on purpose: clearing the session store is installation-wide, and authority in QuickSite is per project, so there is no principal that could authorize it. The credential is filesystem access to the server.

The **first** account is created on the first-run page: while the user registry is empty, every admin URL renders `/admin/setup`, which asks for a setup token the engine writes under `secure/management/config/`. Reading that file is the authorisation — filesystem access to the install, which is strictly stronger than any account it can mint — so no default credential ships and no command line is required. The rule is enforced at the single account-creation path shared by every route, so a direct call to `register` on an empty registry is refused the same way; the token is consumed on use, and the gate independently requires the registry to be empty, so the path dies permanently once an account exists.

Creating that account is also the moment two things can first be written, because neither has a subject before it exists (`secure/src/functions/firstRun.php`). Every project directory that has no `config/members.json` gets one naming the new account as owner — a project that shipped with the download never passed through project creation, and without a roster it reads as private with no members, so this is what makes the starter project reachable by the person who installed it. And `secure/management/config/operator.php` is created naming that same account: the list of accounts that see operator-facing notices such as "an update is available". That file **grants nothing** — it decides whether a notice renders, never what anyone may do — and, like `environment.php` and `deploy-roots.php`, it is operator-controlled: the runtime reads it and no command writes it. Absent, unreadable or malformed, it reads as naming nobody. Neither write can fail the account creation: the account must stay usable, since the panel is where an operator would fix a directory that refused a write.

Beyond that, accounts are **self-created only**: the public `register` command (and the matching `/admin/register` page, linked from the login page) creates an account when `auth.php` `registration.allow_self_registration` is true — the default is **false**, meaning a fresh install accepts no registrations and accounts exist from setup only. Registration is flood-controlled (per-IP rate, install-wide hourly cap, optional absolute account cap) and enumeration-safe (a duplicate username is indistinguishable from a success — login identifiers are private). `changePassword` lets an authenticated user rotate their own password — it requires the current password, shares the login throttle, and revokes the user's other sessions on success. `deleteMyAccount` is its destructive counterpart: current password plus an explicit confirmation, every session ended, and the caller detached from every project they belong to. No command creates, disables, or deletes an account **for someone else** — authority in QuickSite is per project, so a person is parted with per project (`removeMember`) or, at the operator level, by editing `users.php`. Because a project must always have exactly one owner, self-deletion is refused while the caller solely owns a project — ownership is handed over or the project destroyed first, each an explicit act of its own.

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

Roles are **fixed** — there is no superadmin and no custom roles. A role is defined as a set of trust-coherent command **categories** in `secure/management/config/categories.php` (e.g. `content.read`, `style.write`, `deploy`, `project.delete`); `roles.php` grants each role a `rank` and its categories, which are expanded to a per-command allowlist at load time. Every command belongs to exactly one category, and its category also fixes its **scope**: *global* (a set any authenticated user may run — `help`, `createProject`, `importProject`, `listProjects`, `getMySpaceUsage`, `setSelectedProject`, `changePassword`, `deleteMyAccount`, `findUser`, the membership self-service commands, `listRoles`, `getMyPermissions`, `checkForUpdates`, plus the four session commands) or *project* (requires membership). A global category is either open to any authenticated user or closed to everyone; there is no owner-gated global tier, because "owner" is a fact about one project and cannot authorize an action that has no project. Anything installation-wide — applying an engine update, mapping a domain to a project — is therefore either operator-side or expressed as a project-scoped action on the project it affects. Global reads are still caller-relative: `listProjects` returns only the projects the caller is a member of (with their role on each) and `getMySpaceUsage` aggregates disk usage only across the projects the caller *owns* — there is no all-projects API view, and project-scoped reads report only the project targeted in the URL. `owner` is the top of each project; `rank` orders the roles and governs role management — a granter may only assign a role strictly below their own (the self-escalation guard). Permissions are checked before the command file is included.

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
     HTML string per request      Compiled .php template
     ?_editor=1 adds              No JSON parsing on
     data-qs-* attributes         hot path
```

- **Runtime** (`secure/src/classes/JsonToHtmlRenderer.php`): parses JSON on every request. Used in development and inside the editor preview iframe (which appends `?_editor=1` so the renderer also emits `data-qs-node` / `data-qs-struct` attributes for click selection).
- **Build** (`secure/src/classes/JsonToPhpCompiler.php`): emits a self-contained PHP template per page. Translation calls remain dynamic, but the structural traversal happens at build time. This is what production sites serve.

Components are inlined at parse time. `{{var}}` placeholders inside a component are interpolated from the caller's `data`.

---

## 5. Request lifecycle

### 5.1 Public site request

Every project is served under `/p/<projectId>/`, from its own folder — in development and
for preview, which is the whole of a project's life on the authoring install. The web root is
deliberately **free**: QuickSite installs no fallback there, so an operator can put their own
site at the domain root. Production is a **build** (§10): a project reaches the public as a
self-contained deliverable with its own deployment, never by pointing a domain at the
authoring install.

```
GET /p/mysite/fr/about
  │
  ▼  Apache public/p/.htaccess → public/p/index.php
fatal handler  (registered as soon as SECURE_FOLDER_PATH resolves, so the
                gate below is already covered — §7, "Error + path disclosure")
  │
surfaceB  (runs first, before the rest of init.php)
  ├── binds the project from the /p/<projectId>/ URL marker
  ├── gates it by visibility + membership
  └── sets BASE_URL from the validated request origin
  │
init.php  (install-wide constants — shared by every entry point)
  ├── defines PUBLIC_FOLDER_ROOT, SECURE_FOLDER_PATH, ADMIN_ASSET_ROOT
  └── project context is deferred; the request binds its own project
  │
qs_load_project_context(<projectId>)
  ├── loads project config.php → CONFIG
  ├── loads project routes.php → ROUTES
  └── binds PUBLIC_CONTENT_PATH to that project's own public/
  │
renderBootstrap
  └── resolves the PUBLIC BASE once — QS_PUBLIC_BASE_URL env var, else
        derived from the request — into QS_PUBLIC_BASE (root-relative form
        all in-page URLs compose against) + QS_PUBLIC_BASE_ABS (sitemap).
        The same resolution answers on the management path, where the
        editor renders page FRAGMENTS (see below); only the two constants
        are specific to this entry point
  │
public/p/index.php
  ├── checks aliases (data/aliases.json) → may redirect
  ├── TrimParameters parses URL → (lang, route, params)  ── see §5.3
  ├── validates route ∈ ROUTES (else the project's own 404 page)
  └── includes templates/pages/{route}/{leaf}.php
  │
Page template
  ├── new Translator($lang)
  ├── new JsonToHtmlRenderer (or in build mode, the compiled PHP runs directly)
  ├── renders structure → HTML body
  └── Page::render() emits <!doctype>, <head>, menu, body, footer
```

Emitted URLs are **root-relative**: a page links to `/p/mysite/about` on the install's own
hostname and to `/about` once built and deployed — the same rendered HTML carries no scheme or
host, so it survives domain moves, HTTPS switches, and reverse proxies. The absolute base
exists only where a spec demands one (`sitemap.txt`).

That base is a property of the **project**, not of the request that happens to ask for it. The
visual editor renders single nodes through the management API rather than through this entry
point — an element added, edited, duplicated or inserted from a snippet comes back as a rendered
fragment the editor drops straight into the preview — and those fragments compose their URLs
against the same base, resolved the same way. An `/assets/videos/intro.mp4` written by the author
therefore reads `/p/mysite/assets/videos/intro.mp4` whether it arrives as part of a whole page or
as a freshly inserted node, and in a deployed build it reads `/assets/videos/intro.mp4` in both.
The rule holds for every URL attribute the renderer rewrites — `href`, `src`, `srcset`, `poster`,
`action`, `formaction`, `cite`, `data` — and for the language prefix a multilingual project adds
to non-asset URLs. Which codes count as a language is the project's own declared set, so a site
that speaks `es` and `de` is treated exactly as one that speaks `en` and `fr`.

One value is exempt from that rewriting: `{{__current_page;lang=xx}}`, the language-switch link.
It resolves to a **complete** URL — base, language and route already in place — so composing it
against the base again would produce a doubled path. Both writers, the live renderer and the
compiler, recognise it before substituting and leave the result alone, which is what makes a
language picker resolve to the same address in the editor's preview, on the per-project view and
in a deployed build.

A request for a **static sub-resource** under `/p/<projectId>/` — an image, a stylesheet, or the
shared `qs.js` runtime — is served by the same entry point through a prefix-checked passthrough
that cannot escape that project's `public/` folder (§7). The passthrough serves the website as it
is: **hidden paths are never served** — no path segment may begin with a dot, so neither a hidden
file nor anything inside a hidden directory is reachable. A deployment that needs to publish
something at a hidden path, such as a TLS challenge under `/.well-known/`, places it in its own web
root, where the web server serves it directly.

Because PHP is the one sending those bytes, it is also PHP that answers the questions a browser
asks about them: the response carries a validator (`ETag`, `Last-Modified`) so a stale copy can be
revalidated into a `304` rather than refetched, and `Accept-Ranges: bytes` with `206` responses to
`Range`, so media can be seeked (§5.1). None of it is deployment-specific — it behaves the same on
Apache and on nginx, and needs no server module or configuration.

### 5.2 Management API request

```
POST /management/addRoute       Cookie: QSSESSID=… + Authorization: Bearer <session_token>
  │
public/management/index.php
  ├── resolves the session → user (session cookie → users.php), and checks the
  │     header token matches the session and the user's session generation
  │     still matches the one stamped at login (→ 401 auth.unauthorized)
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

The same pattern — parse → validate → mutate files → `ApiResponse` — is used by all 172 commands.

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

- **Server (PHP)** — `Page::render()` injects each captured value as a template variable named after the param. Inside a page's PHP template, `$slug`, `$id`, etc. sit alongside `$translator` and other request-scoped variables. Inside JSON pages a `{{param:NAME}}` placeholder is substituted in raw text, translated text and attributes, on both the served site and a build (§8.7). The literal `param:` prefix is required so it doesn't collide with component-variable patterns.
- **Client (qs.js)** — The build emits the project's own `public/scripts/qs-route-schema.js` listing every route's pattern + param shape. qs.js's synchronous IIFE walks the schema against `location.pathname` on load and exposes three globals: `QS.routeParams` (a dict of captured values), `QS.routePath` (the matched pattern), `QS.routeFound` (a boolean). State stores can initialise a field from `init: 'param:slug'` — a fifth source kind alongside the existing `query:` / `localStorage:` / `sessionStorage:` / literal. The matcher is purely client-side; for a deeper URL → live data loop (server-rendered authed pages, SEO) the server data resolver builds on the same schema.

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

A project is reachable two ways on the authoring install — the **two surfaces**:

- **Per-project live view — `/p/<projectId>/`.** Every project is live-rendered from its own `secure/projects/<id>/public/` folder under `/p/<projectId>/`. No project is privileged: they are all served the same way, from their own folder, with nothing materialised elsewhere. The HTML runs through the shared renderer; static sub-resources (style, scripts, images, fonts) are served through a canonicalised, prefix-checked passthrough that exposes **only** that project's `public/` subtree (§7). Access is gated by the project's visibility and the caller's membership — a private project answers the same refusal to a non-member as to a stranger, so membership is never leaked.

  Those static sub-resources are served as a well-behaved HTTP resource, not just as bytes. Each carries an `ETag` and a `Last-Modified`, so a browser whose copy has gone stale can ask *"has this changed?"* and be told `304 Not Modified` instead of downloading the file again — the everyday cost, since it applies to every asset on every project site. Each also advertises `Accept-Ranges: bytes` and answers a `Range` request with `206 Partial Content` and a `Content-Range`, which is what lets a video be scrubbed and a PDF viewer draw page one without fetching page forty. A range starting past the end of the file is refused with `416` and the file's true length; a range that is malformed, inverted, or names a unit the engine does not implement is ignored and the whole file is sent, which is always a correct answer. The gate runs first and is unaffected by any of it: a `Range` header is read long after visibility and membership have been decided.

  The ETag describes the content and never the filesystem — no path and no inode. For a file up to 1 MiB it is a hash of the bytes, so it changes exactly when the content does; above that it is the modification time and size, the pair Apache and nginx also use by default, because re-reading a large video on every conditional request would cost more than sending it.

  How long a copy may be reused, and by whom, follows the project's visibility. Every asset is cacheable for five minutes, but a **public** project's assets are marked `public` — any cache may store them, shared proxies and CDNs included — while a project that is not public marks them `private`, which permits the visitor's own browser to keep a copy and forbids a shared cache from holding one it could hand to somebody the gate never admitted. `private` and not `no-store`: a member is entitled to the bytes they just fetched, and refusing them a stored copy would also throw away the revalidation above, making every navigation refetch in full to prevent something `private` already prevents. The answer comes from the visibility the gate already decided, so the two can never disagree.
- **Visual editor.** The admin panel edits one project at a time, previewing it through that project's `/p/<id>/` view in editor mode.

**Production is a build.** The authoring install serves projects at `/p/<projectId>/` and
nowhere else; a project reaches the public by being **built** (§10) and deployed as a
self-contained site with its own web root and its own vhost. A domain is never pointed at the
authoring install to serve a project directly out of it. That means the public gets compiled
pages rather than JSON re-parsed on every request, and it means the authoring install's other
projects are not reachable from a production domain at all — there is no path between them to
secure.

Two per-vhost variables remain, and neither selects a project:

- `QS_PUBLIC_BASE_URL` declares the public base that absolute-by-spec artifacts (`sitemap.txt`)
  are generated against. A sitemap has to name the URL the site will be *deployed* at, which the
  authoring install cannot derive from the request it is answering, so the deployer declares it.
  It also covers sub-path mounts and reverse proxies, where the request-derived origin would be
  wrong. In-page links are root-relative and need no declaration.
- `QS_TRUSTED_HOSTS` (optional) pins the Host header: a request presenting any other host has
  its URLs composed against the first listed host instead.

Copy-paste vhosts for both servers ship in `secure/deploy/apache-vhosts.conf.example` and
`secure/deploy/nginx-vhosts.conf.example` (shared hosting can put the same `SetEnv` lines in a
`.htaccess`). A misdeclared value never takes a site down: the engine logs it and degrades to
the derived base.

The **web root carries no QuickSite fallback**: it serves real files only. Nothing is rendered
there, so an operator can place their own hand-made site at the domain root without QuickSite
squatting it (the shipped `.htaccess` also disables directory listings there).

**Project visibility.** Each project's `config/members.json` carries a `visibility` flag:

- `public` — the `/p/<id>/` view is open to anonymous visitors (a shareable site).
- `private` — the `/p/<id>/` view requires membership (owner / member / viewer). Identity is the panel's own session cookie and nothing else: a preview iframe is a plain browser navigation and can carry no header of its own, and the cookie is scoped to the whole origin so `/p/<id>/` receives it. Every request this gate sees is therefore same-origin with the panel, since `/p/<id>/` is the only way a project is served here — the credential that is available is the credential that is required.

A refused `/p/<id>/` request answers `404` with a plain, engine-owned status page — the **same status, headers and bytes** an id that names no project at all gets. Whether the visitor is anonymous or signed in as a non-member makes no difference: a private project is indistinguishable from one that does not exist, so `/p/` cannot be used to discover which project ids are real. (The cost is deliberate: a signed-out member of a private project also sees `404` rather than a prompt to sign in.) The page borrows no project's templates — rendering the requested project's own error page would hand a non-member that project's styling — and it names nothing. A deployment can substitute its own static pages for these engine-owned statuses by declaring `QS_ERROR_PAGE_<status>` (e.g. `SetEnv QS_ERROR_PAGE_404 /404.html`): the value must be a root-relative `.html`/`.htm` file inside the document root, is served with the real status code, and an invalid value is logged and ignored. (A missing *page* inside a project that did resolve is different — that renders the project's own 404 template, no configuration involved.)

**Per-user editing.** A user's `selected_project` (in `users.php`, set via `setSelectedProject`) names the project their admin panel edits. It is a per-user preference, **never an authorization input** — every request is re-authorized against the target project's `members.json`. Because each project is served *and* edited from its own folder, two users can edit two different projects at once without colliding, and nothing any user selects affects what a domain serves.

**Generated client artifacts are per-project.** The compiled `qs-api-config.js` / `qs-enums.js` / `qs-route-schema.js` are written into each project's own `public/scripts/` when its API / enums / routes change — one copy, in the folder that project is served from. Serving `/p/<id>/` regenerates any missing or stale artifact on demand.

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
| XSS in JSON content | Only allowlisted tags render: a tag must be on `TagRegistry::ALLOWED_TAGS` and off the blacklist (`script`, `noscript`, `style`, `template`, `slot`, `object`, `embed`, `applet`) — anything else is dropped. The two lists answer different questions, and a tag can be on neither: the blacklist names tags that would be a *security* problem, while a tag that is merely unusable is simply absent from the allowlist and refused as unknown. URL attributes (`href`, `src`, `xlink:href`, `ping`, `srcset`, …) accept only `http` / `https` / `mailto` / `tel` schemes plus relative / anchor / protocol-relative values; everything else becomes `#`. All attribute values are HTML-escaped, and an attribute *name* must be a well-formed identifier (letters, digits, underscore, colon, hyphen) or the attribute is dropped. The renderer and the compiler enforce all of this identically (`TagRegistry::isRenderable`, `TagRegistry::isRenderableAttributeName`, `UrlPolicy`), so preview and built output agree. |
| Inline JS injection | `on*` attributes only accept the `{{call:fn:args}}` syntax (see §8), transformed to allowlisted `QS.*()` calls (`CallTransformer`); raw JS is rejected. The tag, attribute-name, URL-scheme and `on*` policies are also enforced by the node writers (`addNode` / `editNode` / `editStructure`), so unsafe values are refused on write, not just dropped at render. The write-side check is a second layer, never the only one: projects can already hold structures written before a rule existed, and only the render/compile gate protects a page built from those. |
| File upload | MIME sniffed from content (not extension); per-category size cap; JS uploads disallowed. |
| AuthN / AuthZ | Username+password login → a PHP session (HttpOnly cookie) plus a per-session token; both are required on every Management call. Role is checked per project on every call, and logged. A session generation counter on the user record ends every session of an account at once. Failed logins are throttled per username. |
| CSRF on admin | The session cookie alone never authorizes: a call must also carry the per-session token, which is page-embedded and unreadable to another origin. A cross-site request can make the browser send the cookie but cannot supply the token. The same rule covers the panel's own state-changing forms — signing out is a POST carrying that token, not a link. The three pre-session forms (login, register, first-run) have no session to draw a token from, so they use a double-submit pair instead: an HttpOnly, `SameSite=Strict` cookie scoped to the admin path plus a hidden field, accepted only when the two match. |
| CORS | Configurable per deployment. |
| Per-project serving (`/p/<id>/`) | Static sub-resources are served **only** from the project's own `public/` subtree via a `realpath` canonicalisation + prefix check (a jail): any path resolving outside `…/public/` is refused, so `config/` (members.json), `data/` (api-endpoints.json), `routes.php`, `config.php`, `templates/`, `translate/` are unreachable, and encoded / backslash / absolute traversals are rejected. Hidden paths are refused as well — no segment of a served path may begin with a dot, so neither a dotfile nor anything inside a hidden directory such as `.git/` is reachable (§5.1). HTML is always live-rendered, never served as raw project files; the view runs the same render/compile sanitisation as the built site, plus the Content-Security-Policy described below — the same one a built site sends, from the same writer. Private projects require membership, and that decision is taken before a single byte is read: conditional and range requests are answered inside the same passthrough, so `Range` and `If-None-Match` reach no file the caller could not have fetched whole. |
| Error + path disclosure | A PHP fatal happens outside every `try` a program can write, so all four entry points (`/management`, `/admin/api`, `/admin`, and the per-project view at `/p/<id>/`) register a shared shutdown handler (`secure/src/functions/errorHygiene.php`) that discards the partial output and answers `500` — as a JSON envelope on the API surfaces, as a plain page on the panel and on the public project view. The error's type, message, file and line reach the response **only** on an install that declares itself development (`environment.php`); otherwise they go to the PHP error log. The project view registers it inside `init.php`'s `/p/` block rather than in its own entry file, because that entry file cannot name `secure/` until `SECURE_FOLDER_PATH` has been resolved; registering there also puts the visibility gate itself inside the handler's reach. Separately, no response publishes where the installation lives: paths in a response body are rewritten to be relative before the envelope is assembled (`secure/src/functions/publicPaths.php`), so the disk layout is not disclosed by an ordinary success either. See the note below on `display_errors`. |

### 7.1 Content-Security-Policy — one policy, every surface that serves a page

`secure/src/functions/contentSecurityPolicy.php` composes it; `/p/<projectId>/`
and a built site both call it, and a build carries the file. There was one
writer and two surfaces before: the preview sent a full policy and **a built
site sent none at all** — no `object-src`, no `base-uri`, no `frame-ancestors`
and no `script-src` restriction — so the artifact a visitor actually reaches was
the less protected of the two.

The line is drawn by what a resource can **do**, not by where it comes from.

| Directive | Value | Why |
|---|---|---|
| `default-src` | `'self'` | the floor for anything not named |
| `script-src` | `'self' 'unsafe-inline'` | `script` is a blocked tag, so a project ships no JavaScript of its own; the inline allowance is for the engine's own output (theme toggle, state-store hydration, compiled page-event handlers) |
| `style-src` | `'self' 'unsafe-inline'` | an external stylesheet can exfiltrate through selectors |
| `img-src` / `font-src` / `media-src` | `'self' data: https: http:` | passive, and `UrlPolicy` permits http/https in URL attributes — an author referencing an external image is doing something the engine supports |
| `frame-src` | `'self' https: http:` | external embeds are a shipped feature; `IframeSandbox` exists to give them per-domain sandbox rules |
| `connect-src` | `'self'` + registered API origins | **derived** — see below |
| `object-src` | `'none'` | free: `object`, `embed` and `applet` are blocked tags |
| `base-uri` | `'self'` | an injected `<base>` silently re-points every relative URL |
| `frame-ancestors` | `'self'` | agrees with the `X-Frame-Options: SAMEORIGIN` both surfaces already send |

`form-action` is deliberately **not** set: a form posting to an external endpoint
is something an author can legitimately build, and the directive does not fall
back to `default-src`, so omitting it leaves current behaviour unchanged rather
than tightening it silently.

**`connect-src` is derived from the project's API registry** — `'self'` plus the
origin of every registered API with at least one client-callable endpoint, and
nothing else. A fixed `'self'` blocked every browser-side call an author had
registered, which is the entire client half of the API registry. Deriving it
means registering an API is what declares that the site talks to it; a
server-only API, whose endpoints never reach the browser, never widens the
policy, because the same `qs_api_effective_callable_from()` filters both the
allowlist and the compiled client config. Only the origin is emitted, and a base
URL that does not parse to an http(s) origin is skipped rather than guessed at.

⚠ **A policy that blocks a shipped feature is a bug, not a hardening.** Both of
the permissive rows above are there because the tighter value was measured, in a
browser, to block content the engine renders.

**Deployment requirement — `display_errors` must be off in production.** The fatal handler
above can only replace a response that has not started going out. On a full admin page the
layout is flushed early, so by the time a late fatal happens the status and headers are already
on the wire and no handler can take them back; the visitor gets a truncated page under a `200`.
What stops PHP printing the failing file's absolute path into that page is `display_errors`
being off — which the engine sets for itself on every request, as part of registering the
handler, whenever the install is not declared development.

Two cases fall outside that, and both are the deployment's to close:

- **A fatal raised before the engine has started.** The handler registers within the first few
  lines of each entry point, but a failure in the bootstrap that precedes it — a corrupt
  `init.php`, a broken PHP extension — runs on whatever `php.ini` says. Set `display_errors =
  Off` (and `log_errors = On`) in the php.ini your production install actually loads, so the
  window is closed before PHP hands control to any application code.
- **An install left declared as development.** `environment.php` saying `development`
  deliberately keeps error output on, on top of relaxing the outbound-fetch rules. That is a
  local-authoring setting; a reachable deployment must run as `production`, which is also what
  it does when the file is absent.

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

**An API's `auth` block travels into the client config, so it may hold no secret.** `auth.type` is one of `none` / `bearer` / `basic` / `cookie` / `apiKey`; `apiKey` is the shape whose credential belongs server-side, and endpoints deriving it are filtered out of `qs-api-config.js` entirely (see *callableFrom*, §8.4). `cookie` accepts one optional extra: `csrf: {from: "cookie:NAME", to: "header:NAME"}`, which makes `QS.fetch` echo the named cookie into the named request header — the double-submit token most session-cookie APIs require. A cookie name and a header name are not secrets; the token itself is read from the browser at call time and never stored in the config. This is the **author's API's** auth, never QuickSite's own session. Details in `ADMIN_PANEL.md §9.5`.

**Browser storage is namespaced per project.** Storage is scoped by origin and a path is not part of an origin, so every project served at `/p/<id>/` on one host shares a single `localStorage`. `qs.js` therefore writes and reads each key as `qsp_<projectId>_<key>` — the storage verbs, the `data-storage-*` / `data-auth-source` bindings and a state store's `localStorage:` / `sessionStorage:` init source all resolve through the same helper, so they always address the same slot. Everything an author writes names the bare key; the prefix exists only inside `qs.js`. The project id arrives from the server as `window.QS_PROJECT`, emitted before `qs.js` by `PageManagement::render()` (live) and `Page::render()` (built): at `/p/<id>/` the id is a URL segment, but a deployed build is served from its own root and the path carries no id at all, so deriving it client-side would give development and production different prefixes. The prefix is never stripped at build time — the two must agree on key names. `qsp_` is deliberately outside the admin reservation (`quicksite_`/`quicksite-`/`qs_`/`qs-`, enforced by `secure/src/functions/reservedStorageKeys.php`), which is what keeps a project page from addressing panel state.

**`QS.redirect` enforces a scheme allowlist** (`http`, `https`, `mailto`, `tel` — the same set as the server-side `UrlPolicy` that guards URL *attributes*), refusing anything else with a `console.warn`. Assigning a `javascript:` URL to `location.href` executes in the page's own origin, and the surface-B CSP cannot prevent it because engine pages require `script-src 'unsafe-inline'` for their own handlers. Three callers reach the sink with values the page did not choose: the `redirect` verb, the magic-link verbs' `returnTo` argument, and the `?return=` query parameter they fall back to.

`QS.filter` accepts a polymorphic `matchAttr` (3rd arg): omit it (or pass `textContent`) to match the element's text; pass a `data-*` name to match an attribute; pass a CSS selector starting with `.`, `#`, `>` or space to match the concatenated `textContent` of one or more **descendant** elements (e.g. `.cmd-name, .cmd-description` for "search across both"). The descendant-text and textContent modes also highlight matches in place via an XSS-safe DOM walk (skipped above a 500-node budget).

Custom logic is expressed by composing the core functions or, for richer client behaviour, registered API endpoints (see `addApi` / `testApiEndpoint`).

#### 8.0.1 Chain execution & ordering

Calls in a handler attribute (`{{call:a}};{{call:b}};…`) are compiled by `CallTransformer::transform` (shared by the renderer and the compiler) with three rules:

1. **Sync prelude** — verbs in `CHAIN_SYNC_PRELUDE` (`validate`) emit first as plain `QS.foo(...)` statements. They run inside the event tick and can still call `event.preventDefault()` or `throw` to abort the rest.
2. **Async wrap** — if the remaining body contains at least one verb from `CHAIN_AWAITABLE` (`fetch`, plus the Tier 3 magic-link verbs `exchangeMagicLink` / `requestMagicLink` / `logoutServer`), the body is wrapped in `(async()=>{await A;await B;…})().catch(e=>console.warn('[QS] chain aborted:',e))`. Every call gets `await`'d, so "fetch then hide" — or "exchangeMagicLink then saveToken then redirect" — actually waits for the response. One awaitable verb is enough: a handler holding only `{{call:fetch:…}}` is wrapped too, which is why a failing lone fetch surfaces as `[QS] chain aborted` in the console rather than an unhandled rejection.
3. **No wrap without an awaitable verb** — a chain of purely synchronous verbs stays as-is and doesn't pay the microtask cost.

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
| Public bundle | `secure/projects/<project>/public/scripts/qs-api-config.js` (auto-regenerated on every `addApi` / `editApi` / `deleteApi`) |
| Runtime | `secure/src/runtime/qs.js` → `QS.fetch` |
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
- Each project's own `public/scripts/qs-enums.js` is its runtime registry. Contains **exactly** the enums that at least one binding references — no more, no less. Loaded by every page (when present) as `window.QS_ENUMS`.
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
6. Write the project's `public/scripts/qs-enums.js` with:
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
| serving `/p/<id>/` | when an artifact is missing or stale | Rebuilds that project's registry from its own components + bindings before the page renders. |
| `build` | after `writeCompiledJs` to the build folder | Writes `qs-enums.js` into the build's `scripts/` so deployed sites have the registry. |

No component CRUD commands exist, so changes to a component's
`__enums__` refresh on the next binding edit or on the next serve.

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
| Runtime | `secure/src/runtime/qs.js` → `QS._stores`, `QS.setState`, `QS.getState`, `QS.fetchState` |
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
| Server-side fetch | `secure/src/functions/serverFetch.php` → `serverFetch()` (single) / `serverFetchMulti()` (parallel via `curl_multi_*`). Endpoint lookup goes through `secure/src/functions/apiRegistry.php`, the read half of the API registry. |
| Outbound SSRF guard | `secure/src/classes/OutboundUrlPolicy.php` — every server-side fetch is restricted to `http`/`https`, and in `production` the target is refused if it resolves to a loopback, private, or cloud-metadata address; the validated IP is pinned so DNS cannot rebind between check and connect. `development` (see `secure/management/config/environment.php`) lifts the internal-address block so a local/LAN API can be reached while building. Resolver fetches do **not** follow HTTP redirects. |
| Storage + validation helpers | `secure/src/functions/resolverHelpers.php` (authoring) — the READ half a served page needs is `secure/src/functions/resolverRegistry.php`, which a build carries instead |
| File-based cache + observability | `secure/src/functions/resolverCache.php` + `X-QS-Resolver-Cache` header |
| Commands | `setRouteResolver` (set / clear / patch / append / remove), `cleanResolverCache` |
| Lifecycle | `secure/src/functions/resolverRuntime.php` → `qs_resolve_route_data()`. AFTER the auth gate and the route match, BEFORE the page renders — fires once per request. Called by the `/p/<projectId>/` renderer AND by a built site's front controller, so both surfaces run one implementation. |
| Hydration handoff | `secure/src/functions/runtimeHandoff.php` → `window.QS_RESOLVED` (store-keyed, for state-store skip-fetch) + `window.QS_RESOLVED_BY_INDEX` (resolver-index-keyed mirror of PHP `$r0` / `$r1`). One writer for every surface — see §8.5. |
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

### 8.5 The runtime handoff

Every rendered page ends with a run of `<script>` tags — the contract between
PHP and the browser runtime:

| Block | Carries |
|---|---|
| `qs-route-schema.js` | `window.QS_ROUTES` — the client-side path matcher's table |
| `window.QS_PROJECT` | the project id every browser-storage key is prefixed with |
| `qs.js` | the runtime itself |
| `window.QS_CONSENT` | the key→category map that gates storage writes |
| theme wiring | `[data-theme-toggle]` behaviour, keyed per project |
| `qs-api-config.js` | `window.QS_API_ENDPOINTS` |
| `qs-enums.js` | `window.QS_ENUMS` |
| `window.QS_COUNT_STRINGS` | count-sentence strings, resolved for **this page's** language |
| `window.QS_STATE_STORES` | this route's stores |
| `window.QS_RESOLVED` | store-keyed resolver values, so a hydrated store skips its first fetch |
| `window.QS_RESOLVED_BY_INDEX` | the same values under the `r0` / `r1` addresses templates use |
| page events | the compiled `onload` / `onresize` / `onscroll` chain |

**Order is part of the contract.** The route schema and the storage namespace go
before `qs.js`, because its IIFE reads them synchronously at load. The state
stores go after `qs-api-config.js`, because a store's endpoint resolves against
`window.QS_API_ENDPOINTS`. The page-events script goes last, because an onload
chain can call `fetchState` and needs the stores to exist.

**One writer: `secure/src/functions/runtimeHandoff.php`.** The live renderer and
a compiled page both call it. What each surface supplies is the *values* —
gathering legitimately differs, because a live render reads this route's stores
and events out of `data/`, while a compiled page has them baked in — but the
order and the content of every block are decided in one place.

`window.QS_COUNT_STRINGS` is the block that exists because the artifact beside
it cannot carry the value. A count binding in sentence format names three
translation keys; `qs-api-config.js` is written once per **project**, so a
sentence resolved into it is served to visitors of every language, and a
bilingual site froze in whichever language last wrote the file — on the live
surface and in a build alike. The keys are language-independent and stay in
the compiled config; the sentences are not, and ride the page instead. PHP
remains the only translation engine either way.

`window.QS_CONSENT` is the block where absence is meaningful rather than
neutral: `qs.js` reads a missing value as "this project has no consent layer"
and lets every storage write through. That is correct for a project that never
configured one, and it is why a surface that simply forgot to emit the payload
did not look broken — the gate failed open silently. A build now precomputes the
payload (the live derivation walks the storage registry through authoring
helpers that do not belong in a deployed site) and ships it as data.

### 8.6 What a build carries, and what it does not

A production build runs the same request-time engine the live surface runs; what
it leaves behind is the code that EDITS a site rather than serves one. Several
files were split along that line so both halves could not drift:

| Authoring (install only) | Runtime (travels into a build) |
|---|---|
| `ApiEndpointManager` — add/edit/validate, compile the client bundle | `apiRegistry.php` — look up an API, an endpoint, its `callableFrom` |
| `resolverHelpers.php` — validate a config, generate schema samples | `resolverRegistry.php` — read the sidecar, hold the per-request values |
| `consentHelpers.php` — derive the payload from the storage registry | the precomputed payload, plus `qs_consent_hydration_script()` |
| `storageHelpers.php`, `projectContext.php` | `requestRuntime.php` — the validated host, the project-namespaced cookie name |
| `utilsManagement.php` | `jsonIo.php` — the one JSON writer three runtime files needed |

The authoring file requires the runtime one, so there is a single definition of
each and existing callers are unchanged.

**`{{param:NAME}}` and `{{resolved:NAME}}` are request-time placeholders.** Their
values are not knowable when a page is compiled — a param comes from the URL
being served, a resolved value from an HTTP call made during that request — so a
compiled page emits a CALL to `runtimePlaceholders.php` rather than folding them
in. Both surfaces substitute through the same two functions, including the rule
that resolved values are substituted BEFORE params: a param is visitor-supplied,
and doing params first would let a URL inject a placeholder for the resolved
pass to expand.

**A built site is `production` unless the deployment says otherwise.** The
environment is a property of where a site runs, not of the artifact, so it comes
from a server variable (`QS_ENVIRONMENT`) rather than from anything inside the
build. This is what the outbound-URL policy reads before allowing a resolver to
call an internal address; in production it may not.

**OAuth in a built site** is the AUTHOR's site's own sign-in, not QuickSite's. It
needs PHP sessions, an outbound HTTPS call and a route to return to, and a built
site has all three; it needs nothing from the management API or the admin panel,
neither of which exists in a build. The client secret is read from the server
first (`QS_OAUTH_<PROVIDER>_CLIENT_ID` / `_CLIENT_SECRET`) and from the shipped
`data/oauth-secrets.json` second, so a deployer can keep the credential out of a
build folder that `downloadBuild` hands over whole.

The post-auth record and the pre-auth sign-in state both carry the id of the
project that wrote them, and every read verifies it. Two built sites on one
origin open the same PHP session store — same session name, same save path,
cookie at `/` because a built site lives at the root — so without the stamp the
only thing separating two tenants' visitors would be each site reading its own
project-namespaced cookie name, which is a lookup key and not a check. A record
carrying no stamp is refused rather than trusted.

### 8.7 Render parity — one contract, two implementations

`JsonToHtmlRenderer` renders a page from JSON on every request;
`JsonToPhpCompiler` turns the same JSON into PHP once, at build time. They are
two implementations of a single contract: **the same node must produce the same
HTML on both surfaces**, minus editor-only emissions, which a build has no use
for.

Nothing enforced that until a differential harness was pointed at them, and the
answer was that they disagreed about most of what an attribute can hold. The
rules below are now shared or mirrored, and each names the surface that had it
right.

**Placeholders.** Four kinds, and they are substituted in a fixed order.

| Kind | Value known at | Substituted in |
|---|---|---|
| `{{__lang}}`, `{{__current_page}}`, `{{__current_route}}`, `{{__public_folder}}` | request time | text and attributes |
| `{{resolved:NAME}}` | request time (a server-side fetch) | text and attributes |
| `{{param:NAME}}` | request time (the URL) | text and attributes |
| a translation key | request time (the language) | text and translatable attributes |

⚠ **The order is a security property.** System placeholders first, resolved
values second, **params last** — a param comes straight out of the URL, so it is
the one visitor-controlled input, and substituting it last means nothing it
introduces is re-scanned as a placeholder.

⚠ **And substitution precedes the URL policy.** `UrlPolicy` has to inspect the
value the browser will actually receive. Sanitising the literal placeholder and
substituting afterwards let a route param carry a scheme past the check:
`xlink:href="{{param:slug}}"` served from `/products/javascript:alert(1)` emitted
the raw value, while the same literal authored directly is refused. Path-rewritten
attributes survived only because the base was prefixed in front of the injected
value.

The one exception is `{{__current_page;lang=xx}}`, the language switch: it
resolves to a COMPLETE URL, so both surfaces recognise it *before* substituting
and exempt the result from being composed against the base a second time.

**Component slots.** `{{var}}` and `{{$var}}` are a separate mechanism from the
four above and are bound earlier — when a component is inlined, from the
referencing node's `data` (§2, §4) — so the request-time substitutions see a
node that already reads like any other. A slot name may hold letters, digits,
underscores and hyphens. A slot with no matching key in `data` keeps its
placeholder verbatim; a slot given a value that is not a scalar keeps it too.

The rule has one home, `qs_resolve_component_placeholders()` in
`componentPolicy.php`, beside the rule for what a component *reference* is
(below). Five readers ask it: the renderer, the compiler, the two preview
commands (`getSnippet`, `getComponent`), and the snippet CSS extractor, which
binds slots so the rules it captures are the ones the rendered page will use.
The compiler was the surface that had this wrong — it alone did not accept the
`{{$var}}` spelling, so a component written that way bound in the editor
preview and shipped unbound in the build.

**Attribute names.** A name is an *identifier*, not text. It may hold letters,
digits, underscore, colon and hyphen and nothing else — `class`, `data-id`,
`aria-label`, `xml:lang`. Anything else is **dropped**, on both surfaces, and
logged.

The rule has one home, `TagRegistry::isRenderableAttributeName()`, reading the
`html_attribute_name` pattern from `RegexPatterns`. Three writers ask it: the
renderer, the compiler, and the node writers on the way in
(`addNode` / `editNode` / `editStructure`), where a malformed name is refused
with an error rather than silently dropped later.

A malformed name is dropped rather than escaped, because there is no encoding of
one that means anything as an attribute. It matters more than tidiness: the
compiler writes the name into a PHP string literal, so a name carrying a quote
closed that literal and turned the remainder into executable PHP inside the
compiled page — code that ran when the built site served the route. The renderer
had always refused such names, so the two surfaces disagreed and the build was
the weaker one.

The same principle governs every identifier the compiler emits. A component name
and a page-title key are written as data — logged, or `var_export`ed into a
literal — never interpolated into generated code, not even into a comment.

**Component references.** `{ "component": "lang-switch" }` names a file in the
project's own `templates/model/json/components/` directory. The reference is a
**name, not a path**: it starts with a letter, then letters, digits, hyphens and
underscores, up to 64 characters. It holds no separators, no dots, and no
traversal, so it can only ever name a component of the project being rendered.
A reference that breaks the rule resolves to nothing and the reader reports the
component as not found — refused, not repaired, for the same reason a malformed
attribute name is dropped: there is no rewriting of a path that means anything
as a component name.

Both the containment and the rule live in one place, `qs_resolve_component_path()`
in `src/functions/componentPolicy.php`, and every reader asks it: the `/p/<id>/`
renderer, the compiler, and the commands that expand a component for a preview or
read its variable list. The resolver applies the rule, then confirms that the
file it is about to return really sits inside that project's components
directory, so the two checks fail independently.

The write side is the second layer, not the first. `editStructure`,
`createSnippet`, `addComplexElement` and `importProject` walk a structure before
storing it and refuse a malformed reference with an error, the same way they
already refuse a blocked tag. The read-side jail is what actually protects a
render or a build, because a project may already hold references written before
the rule existed.

**Attribute values.** An attribute carries a string, a boolean, or nothing.

| Authored | Emitted |
|---|---|
| `""` on `alt` / `aria-label` | `alt=""` — empty is the meaning there; it marks an image decorative, and dropping it makes a screen reader announce the file name |
| `""` anywhere else | attribute dropped |
| `null` | attribute dropped |
| `true` | the bare attribute name |
| `false` | attribute dropped — **not** `=""`, which HTML reads as TRUE |
| an array | attribute dropped, and logged |
| a translation key in a translatable attribute | the translation |
| `__RAW__` / `__LIT__` prefix | the prefix stripped, no translation |

Arrays are dropped rather than interpreted. The conditional form
(`{"condition": …, "value": …}`) was the only array shape either surface
understood; no command writes one, no project authors one, and the editor has no
control for it. The compiler emitted `htmlspecialchars(array(…))` for it — a
TypeError at REQUEST time, so a built page answered `200` with a fatal in the
body and everything after the offending tag missing.

**The system placeholders have one source**, `runtimePlaceholders.php`. The
renderer derived them; the compiler GENERATED PHP that re-derived them into every
page, with its own regexes for stripping the URL space and the language prefix
and a hardcoded `(en|fr)` fallback. The language question already has one answer
in `projectLanguage.php`, which travels into a build, so both surfaces ask it —
which is also what makes a mono-language project safe: it answers "no language
here", so a route legitimately named `en/` is not mistaken for a language prefix
and stripped.

`{{__current_route}}` is always the leaf of `{{__current_page}}`, never a
caller-supplied value: the renderer's context is populated differently by
different callers (a page loader passes the whole route path, `PageManagement`
passes the leaf), so honouring it made the same nested route report two
different things.

## 9. Style management

CSS is modelled as four addressable layers, all manipulated through commands rather than free-text edits:

| Layer | Commands |
|---|---|
| `:root` variables | `getRootVariables`, `setRootVariables` |
| Global selectors | `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule`, `getAnimatedSelectors` |
| `@keyframes` | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes` |
| `@media` queries | `getStyleRule` / `setStyleRule` with a `mediaQuery` parameter (selectors and keyframes can be scoped) |

`secure/src/classes/CssParser.php` parses the targeted project's CSS into an AST so any of those layers can be queried or mutated atomically.

---

## 10. Build & deploy

```
POST /management/p/<projectId>/build  { "public": "www", "secure": "app" }
```

**A build is the production form of a project, not a reduced one.** It is the
project's pages precompiled to PHP *plus* the runtime that serves them — a
self-contained QuickSite that serves exactly one site. Resolvers, param routes,
server-side auth and `serverFetch` are part of what is built, so a built site
does what the project did in development. What the build removes is
*editing-time* computation: parsing the page JSON on every request, and the
editor machinery around it. It does not remove features, and anything a built
site cannot do is a defect in the build rather than a property of builds.

Development and production are the two ends of one project's life: a project is
authored and previewed at `/p/<projectId>/` on the install's own hostname for as
long as it exists, and reaches the public as a build with its own deployment.

### 10.1 What a built site is made of

```
your-server/
├── <public>/[<space>/]        document root — the site answers from here
│   ├── index.php              the front controller
│   ├── qs-site.php            its four parameters (project id, folder names, space)
│   ├── .htaccess              funnels every non-file request into index.php
│   └── style/  assets/  scripts/  sitemap.txt
└── <secure>/                  sibling, never web-accessible
    ├── config.php  routes.php  nginx_routes.conf
    ├── data/       aliases, route-resolvers, api-endpoints, iframe_sandbox,
    │               the precomputed consent payload, OAuth presets + secrets
    ├── src/classes/    render + route + translate, plus the server-side data
    │                   path: DataResolver, OutboundUrlPolicy, IframeSandbox,
    │                   OAuthHandler
    ├── src/functions/  the request-time engine (§8.6) — no authoring code
    ├── templates/menu.php  footer.php  consent-banner.php  consent-popup.php
    ├── templates/pages/<route>/<leaf>.php   one precompiled page per route
    └── translate/
```

**The front controller is a real file, shipped verbatim.** It lives in the
engine at `src/runtime/site/index.php` — the same directory as `qs.js`, which
is the browser half of the same idea: code that runs on a *site* rather than in
the engine. The build copies it and writes `qs-site.php` beside it; no PHP
source is rewritten by pattern matching, so the entry point can be linted,
grepped and opened on its own instead of existing only inside a build.

`qs-site.php` is PHP rather than a data file because it sits in the document
root: PHP is executed and never served as text, so the secure folder's name
stays out of reach. A direct request for it answers 404.

An install has no equivalent file, and that is deliberate rather than an
omission — an install's web root is free so a user's own site can occupy the
domain and QuickSite stays inside its namespaces, while a built site *is* the
whole site at its root. The two answer opposite questions.

The front controller derives its paths from its own location, not from
`DOCUMENT_ROOT`: the build created the layout, so the depth is known. Every URL
the site emits composes against a root-relative base built from the URL space,
which survives a domain move, a scheme change and a reverse proxy.

It also carries the two things a public surface owes its visitors regardless of
which web server is in front of it:

- **Fatal hygiene.** A PHP fatal happens outside every `try`, so without a
  handler the interpreter's own message — class, absolute path, line — goes into
  the page under the status that was already set, which is `200`. The front
  controller registers the shared handler (`errorHygiene.php`, the same one
  `/management`, `/admin/api` and `/admin` use) as soon as the secure folder is
  located, and renders inside an output buffer so a fatal part-way through a
  page is still repairable: the visitor gets a neutral page and a `500`. Before
  that point — a missing or malformed `qs-site.php`, an absent secure folder —
  the controller's own boot failure path answers, logging the path and printing
  none. Detail is shown only when the deployment declares itself development
  (`QS_ENVIRONMENT=development`, set on the vhost); production prints nothing
  about the filesystem, and `display_errors` is turned off before anything can
  fail so that holds even where the handler cannot reach.
- **Response headers.** `X-Content-Type-Options`, `X-Frame-Options` and
  `Referrer-Policy` are sent by the site itself, so every page carries them on
  any server. The `.htaccess` and the nginx snippet carry the same three for the
  static files only a web server touches.

### 10.2 Build steps, in order

Before the lock:

1. Read and validate `name`, `public`, `secure`, `space` (type, length, allowed
   characters, at most five levels).
2. Refuse when the public and secure root segments are the same directory.
3. Confirm the source public and secure folders exist.
4. Refuse when the project already has a build; otherwise settle the build
   folder name.
5. Acquire the build lock, scoped to that name.

Then:

6. Create the build directory and its skeleton.
7. **Emit the entry point** — copy `src/runtime/site/index.php`, write
   `qs-site.php`, write the `.htaccess` that funnels requests into it, and,
   when a URL space is set, a second `.htaccess` at the document root so the
   root is not browsable.
8. Copy `style/` and `assets/` through the **publish allowlist** — the boundary
   where a file stops being project data and becomes something a web server
   hands to the public.
9. Copy `LICENSE`, and `sitemap.txt` when the project has one.
10. Copy `routes.php` and `config.php`, the runtime classes and function files
    (§8.6), the translations (all languages when the project is multilingual,
    `default.json` otherwise), and the project data a served page reads:
    aliases, route resolvers, the API registry, the iframe-sandbox policy, and
    the OAuth presets. The consent payload is PRECOMPUTED here rather than
    copied, because deriving it is authoring work. OAuth **secrets** are copied
    separately and reported separately — a build that carries them is a
    credential, not just a website.
11. Compile `menu.php`, `footer.php`, and the consent banner + popup.
12. Write `qs-api-config.js`, `qs-route-schema.js` and `qs-enums.js`, and copy
    `qs.js`.
13. Load the project's page events and state stores, which compile inline into
    the pages that use them.
14. Compile the 404 page, then every route, via `JsonToPhpCompiler`. Param
    segments are written as `__name` folders, because a filesystem cannot hold
    the `:` that `routes.php` uses. The compiler and the renderer are two
    implementations of one contract — see §8.7.
15. Write `README.txt` with deployment instructions, and `nginx_routes.conf`
    describing **this site** for servers that do not read `.htaccess`.
16. Write `build_manifest.json` — last, so its presence marks the build
    complete.
17. Refuse the build if it exceeds `MAX_BUILD_SIZE_MB`.
18. **Check that the build can serve** (§10.3).
19. Release the lock and return build stats.

Interactions do not get their own artifact: they compile into each page's
`on*` attributes and ride the shared `qs.js`.

### 10.3 A build that cannot serve is not a success

Completing and being servable are different claims, and the command checks the
second one before answering. It walks the request path over the finished tree:
the file the `.htaccess` funnels to, the parameters that file reads, the
project's `config.php` and `routes.php`, the runtime the compiled pages
require, the menu and footer they pull in, every compiled route's page at the
exact path routing will compute for it — asked through the same helper the
compiler used to write it, so reader and writer cannot drift — and the 404
page, because a wrong URL is a request too.

Anything missing makes the build a `500` whose `data.problems` names it, and
the partial directory is removed like any other failure. The check is
structural: it proves the request path is complete, not that a given page
renders.

**Where a build lives, and how it is fetched.** The output goes to
`secure/projects/<id>/qs_build/<name>/` — outside the project's `public/`, which
is the only directory `/p/<id>/` serves. No URL reaches a build, and no
web-server configuration is needed to keep it that way. `downloadBuild` is the
fetch path: it archives the folder on demand, streams it, and keeps nothing, so
the download inherits the management surface's authentication and can never be
stale against the build it claims to be.

**One build per project.** A project holds at most one build. `build` refuses
while one exists rather than overwriting it (`409 conflict.already_exists`),
so replacing a build is a deliberate `deleteBuild` first. A build that FAILS
removes its own partial directory; if that removal also fails, the leftover
carries no `build_manifest.json` — written last, precisely so its absence marks
an unfinished build — and `getBuild` reports it as incomplete.

**Renaming the folders is part of the security model** — anyone scanning the
deployed server cannot guess paths from the open-source repo layout — so the
names have to hold everywhere, not only in the directory listing. They reach
the front controller as data, the compiled pages compose every internal path
from the constants it defines rather than naming a folder, and the one file in
the document root that knows the secure folder's name is PHP, so it is executed
instead of read. A URL space works the same way: the site answers under it, its
`.htaccess` funnels only under it, and every link, asset and script URL the page
emits carries it.

Deploy is a separate command (`deployBuild`) that copies the build folder into a target path.

### 10.4 Serving a build: Apache and nginx

Every build ships configuration for both servers, and each is inert on the
other, so moving a site between them needs no rebuild.

| server | file | what it does |
|---|---|---|
| Apache | `<public>/[<space>/].htaccess` | `FallbackResource` into the front controller, `Options -Indexes`, the security headers when `mod_headers` is present |
| Apache | `<public>/.htaccess` (spaced builds only) | makes the document root unbrowsable; deliberately does **not** funnel, because the root is not the site's |
| nginx | `<secure>/nginx_routes.conf` | one `location` block the deployer includes in their own `server { }` |

**A build usually serves before the nginx file is included at all**, and an
install does not — the same asymmetry as §10.1's "an install has no entry point
to copy". A panel-generated vhost carries `try_files $uri $uri/ /index.php…`
plus `index index.php`, because that is what a front-controller application
needs. A build **is** one: `index.php` sits in its document root and every asset
is a real file beneath it, so the panel's own default already routes it. An
install's web root holds no `index.php` — only the `/admin/`, `/management/` and
`/p/` namespaces — so that same fallback lands on a file that does not exist,
and `/p/`'s files are outside the web root entirely, which is why an install
genuinely cannot serve without its generated config. What a build's file adds is
narrower: directory URLs answered by the site rather than by nginx's 403,
headers on static files, and routing declared by the build instead of inherited
from a template the panel may regenerate.

**The nginx file is a fragment, not a vhost.** It has no `server`, no `root` and
no PHP handler: it assumes a working PHP vhost already serves the document root
and adds the routing that turns it into a QuickSite site. Two properties of the
surrounding vhost decide whether it works, and neither can be fixed from inside
an included file — which is why the file itself states both:

- The deployer's `location ~ \.php$` must carry `try_files $uri =404;`.
  Without it, PHP's default path-info resolution executes a real file found
  earlier in the path, so `/assets/images/logo.png/x.php` runs the image as a
  script. Apache does not have this behaviour.
- A vhost split across **two** server blocks — a public one holding the
  static-asset regex, proxying to a backend one holding the PHP handler — puts
  the include in the block that cannot answer assets. Pages then work while
  every stylesheet, script and image 404s. The fix is in the public block, by
  hand.

**The funnel deliberately omits `$uri/`.** nginx's `try_files` resolves a
directory when that entry is present; with no index file inside and listings
off, the answer is `403` — and `/` is a directory request, so the home page of a
built site is what breaks first. Without it, a directory request falls through
to the front controller and gets the site's own 404 page, which is what Apache
does. The install's own `/admin/` block omits `$uri/` for the same reason.

**`nginx -t` is not a test of the deployment.** It parses the configuration; it
does not resolve it, so it reports success for a setup that answers `500` on
every page. What confirms a build is serving is fetching a page — and
specifically fetching a URL that does not exist: the site's own 404 page proves
the request funnel was reached, where the home page alone does not.

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

### 11.1 Which language a request is

One function answers it, in `secure/src/functions/projectLanguage.php`:

| function | answers |
|---|---|
| `qs_project_is_multilingual()` | is this project multilingual at all? |
| `qs_project_languages()` | the supported codes — empty when it is not |
| `qs_project_default_language()` | the configured fallback (never empty) |
| `qs_is_project_language($segment)` | is this URL segment one of them? |
| `qs_project_language_from_path()` | the language this request's URL names, or `null` |
| `qs_resolve_project_language($candidate)` | **the answer** |

`qs_resolve_project_language()` takes, in order: the project default on a
monolingual project; an explicit candidate the caller already resolved, if the
project declares it; the language segment leading the request path; the project
default. An undeclared candidate is discarded rather than trusted, so a value
that reached a caller from the request cannot select a translation file the
project does not declare. The return is always a non-empty code — a caller that
needs to tell "this URL names no language" from "the default" asks
`qs_project_language_from_path()` instead.

`TrimParameters` and `Translator` both call it and neither decides anything on
its own. `TrimParameters::lang()` reports `null` on a monolingual project,
because such a site's URLs carry no language segment and there is nothing to
report.

The path it reads is normalised the way `TrimParameters` normalises it: the
optional `PUBLIC_FOLDER_SPACE` prefix is removed, and on `/p/<projectId>/`
serving the project marker is removed too. The marker matters because that
surface rewrites `REQUEST_URI` part-way through a request — without the strip,
the same request would resolve to different languages depending on when the
question was asked.

**This is the author's site's language, not the admin panel's.** The panel runs
a separate system — `AdminTranslation`, files under
`secure/admin/translations/`, chosen by `?lang=` then the admin session then
`Accept-Language`. The two share no code, no files and no configuration. See
[ADMIN_PANEL.md](ADMIN_PANEL.md) for the panel's own language handling.

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
