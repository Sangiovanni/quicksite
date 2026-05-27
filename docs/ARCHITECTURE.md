# QuickSite Architecture

_Last updated: 2026-04-23._

> Canonical high-level overview of how QuickSite is structured and how a request flows through it. For the admin panel internals, see [ADMIN_PANEL.md](ADMIN_PANEL.md). For the workflow engine, see [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). For the full command reference, see [COMMAND_API.md](COMMAND_API.md). For the on-disk layout, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

> _Maintainers note:_ re-check this doc when changing `secure/management/routes.php` (§3 command count), `secure/management/config/roles.php` (§3 roles), `secure/src/classes/JsonToHtmlRenderer.php` (§2 node kinds, §7 tag blacklist), or `secure/src/functions/qsVerbCatalog.php` (§8 QS.* registry — single source of truth for verb metadata, consumed by `listJsFunctions`, `JsonToHtmlRenderer`, and `JsonToPhpCompiler`).

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes. It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

---

## 1. Three-layer model

QuickSite separates concerns into three top-level layers. Each one has a clear boundary and is responsible for a different audience.

| Layer | Folder | Audience | Purpose |
|---|---|---|---|
| **Project** | `secure/projects/{name}/` | Site owner | The actual website data: routes, page structures (JSON), translations, components, interactions, styles, assets. |
| **Management** | `secure/management/` | API client (admin panel, scripts) | The 124 commands that read or mutate project data. Single entry point: `public/management/index.php`. Token + role enforced. AI calls bypass this layer entirely (browser-direct). |
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
Authorization: Bearer tvt_xxx
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

The full list of 124 commands is registered in `secure/management/routes.php`. See [COMMAND_API.md](COMMAND_API.md) for the catalogue and a per-command reference (also obtainable at runtime via `GET /management/help`).

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

Tokens are stored in `secure/management/config/auth.php` and mapped to one of:

| Role | Reads | Mutates content | Mutates style | Build / deploy | Tokens |
|---|---|---|---|---|---|
| `viewer` | ✓ | | | | |
| `editor` | ✓ | ✓ | | | |
| `designer` | ✓ | ✓ | ✓ | | |
| `developer` | ✓ | ✓ | ✓ | ✓ | |
| `admin` | ✓ | ✓ | ✓ | ✓ | |
| `*` (superadmin) | ✓ | ✓ | ✓ | ✓ | ✓ |

> AI is no longer a permissioned column: AI calls happen in the browser via `QSAiCall` against per-user credentials in `aiConnectionsV3` (localStorage). Any authenticated admin can use the AI workspace; gating happens at the connection level, not the role level.

Named roles (`viewer`, `editor`, `designer`, `developer`, `admin`) and any custom role live in `secure/management/config/roles.php`. The superadmin `*` is **hardcoded** in `secure/src/functions/AuthManagement.php` (and `AdminRouter.php`) — it bypasses the roles file entirely and gets unconditional access to every command, including token and role management. It is the only role that cannot be created, edited, or deleted at runtime. Permissions are checked before the command file is included.

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
  ├── TrimParameters parses URL → (lang, route)
  ├── validates route ∈ ROUTES (else 404)
  └── includes templates/pages/{route}/{leaf}.php
  │
Page template
  ├── new Translator($lang)
  ├── new JsonToHtmlRenderer (or in build mode, static PHP runs directly)
  ├── renders structure → HTML body
  └── Page::render() emits <!doctype>, <head>, menu, body, footer
```

### 5.2 Management API request

```
POST /management/addRoute       Authorization: Bearer tvt_xxx
  │
public/management/index.php
  ├── parses bearer token → looks up role in auth.php
  ├── checks role permits 'addRoute' (roles.php) → 401/403 if not
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

The same pattern — parse → validate → mutate files → `ApiResponse` — is used by all 124 commands.

---

## 6. Multi-project model

One QuickSite installation can host any number of projects:

```
secure/projects/
├── portfolio/
├── client-website/
└── documentation/
```

`secure/management/config/target.php` is a single line that names the active project. Switching projects is one command:

```
POST /management/switchProject  { "project": "client-website" }
```

On switch, QuickSite syncs the project's `assets/` and compiled `style.css` into the live `public/` folder so the served site matches. Each project carries its own config, routes, templates, translations, interactions, and backups. Tokens, role definitions, command code, interaction schemas, and the admin panel are shared across all projects.

---

## 7. Security boundary

The single most important architectural rule:

> **`public/` is the only thing Apache serves. Everything in `secure/` is reachable only via PHP `require_once` from inside `public/`.**

This puts auth tokens, project sources, command implementations, builds, backups, and logs out of reach of any direct HTTP request, even with directory traversal.

Other layered protections:

| Concern | Protection |
|---|---|
| Path traversal | All file paths normalised and checked against `..`; whitelisted base directories; helpers in `secure/src/functions/PathManagement.php`. |
| XSS in JSON content | Tag blacklist (`script`, `noscript`, `style`, `template`, `slot`, `object`, `embed`, `applet`); attribute escaping in renderer. |
| Inline JS injection | `on*` attributes only accept the `{{call:fn:args}}` syntax (see §8). Raw JS is rejected. |
| File upload | MIME sniffed from content (not extension); per-category size cap; JS uploads disallowed. |
| AuthN / AuthZ | Bearer token + role check on every Management call; logged. |
| CSRF on admin | Admin panel uses the same Bearer token via `fetch`; no cookie-only auth. |
| CORS | Configurable per deployment. |

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

A single argument can contain literal commas by escaping them with `\,`. For example, `{{call:filter:event,.cmd-card,.cmd-name\, .cmd-description,hidden}}` passes `.cmd-name, .cmd-description` as a single `matchAttr` value (a comma-separated child-selector list, see `QS.filter`), not two separate args. Both the JSON-side parser (`interactionHelpers.parseCallSyntax`) and the renderer (`JsonToHtmlRenderer.transformCallSyntax`) honour the escape and unescape it before quoting.

**Reserved class names.** `qs.js` self-injects `<style id="qs-hidden-style">.hidden{display:none!important}</style>` into the document head at script-load time so projects without a `.hidden` rule still get a working hide. This makes `.hidden` a **reserved QuickSite class**: do not redefine it in your CSS — the `!important` will win regardless and the override won't apply. To get animated/custom hide behaviour, define your own class (e.g. `.fade-out`) and pass it as the `hideClass` arg to `QS.show`/`QS.hide`/`QS.toggleHide`/`QS.filter` instead.

The core registry currently exposes 22 built-ins:

| Group | Functions |
|---|---|
| Visibility | `QS.show`, `QS.hide`, `QS.toggle`, `QS.toggleHide` |
| Classes | `QS.addClass`, `QS.removeClass` |
| Forms | `QS.setValue`, `QS.focus`, `QS.blur`, `QS.validate` |
| Navigation | `QS.redirect`, `QS.scrollTo` |
| Data | `QS.filter`, `QS.fetch`, `QS.renderList` |
| Feedback | `QS.toast` |
| Auth / storage | `QS.saveToken`, `QS.clearToken`, `QS.refresh` |
| State stores | `QS.setState`, `QS.fetchState`, `QS.onScrollFetchState` |

Use the live `listJsFunctions` command for the authoritative list. The catalog itself is declared **once** in `secure/src/functions/qsVerbCatalog.php` (the single source of truth) and is read by three consumers: the `listJsFunctions` command (picker payload), `JsonToHtmlRenderer` (runtime `{{call:fn:...}}` allowlist), and `JsonToPhpCompiler` (build-time allowlist). Add a new QS.* verb by adding an entry to that catalog file — the picker, the renderer, and the compiler all pick it up automatically; an unknown verb wired anyway is dropped with a `console.warn('[QS] unknown verb …')` in the rendered page. (The renderer allowlist also accepts `applyAuthState` for hand-authored manual re-scans; it is intentionally not surfaced in the picker.)

Beyond the `{{call:…}}` verbs, the auth-flows runtime adds **declarative bindings** read by `qs.js` on load + on `qs:auth:*` events — `data-auth-show` / `data-auth-source` (login-state show/hide) and the generic `data-storage-show` / `data-storage-value` (any storage key) — plus the `QS.isAuthed(source)` query. These are documented in `ADMIN_PANEL.md §9.5`.

`QS.filter` accepts a polymorphic `matchAttr` (3rd arg): omit it (or pass `textContent`) to match the element's text; pass a `data-*` name to match an attribute; pass a CSS selector starting with `.`, `#`, `>` or space to match the concatenated `textContent` of one or more **descendant** elements (e.g. `.cmd-name, .cmd-description` for "search across both"). The descendant-text and textContent modes also highlight matches in place via an XSS-safe DOM walk (skipped above a 500-node budget).

> _Roadmap note:_ this list will grow with beta.6 (interactions + search/filter), beta.7 (client-side API) and beta.8 (server-side API). Re-check this section when releasing those versions.

A project-scoped "custom JS function" feature existed in earlier betas (`addJsFunction` / `qs-custom.js`); it was removed in beta.3 for security reasons. Custom logic is now expressed by composing the core functions or, for richer client behaviour, registered API endpoints (see `addApi` / `testApiEndpoint`).

#### 8.0.1 Chain execution & ordering

Calls in a handler attribute (`{{call:a}};{{call:b}};…`) are compiled by `JsonToHtmlRenderer::transformCallSyntax` with three rules:

1. **Sync prelude** — verbs in `CHAIN_SYNC_PRELUDE` (`validate`) emit first as plain `QS.foo(...)` statements. They run inside the event tick and can still call `event.preventDefault()` or `throw` to abort the rest.
2. **Async wrap** — if the remaining body contains at least one verb from `CHAIN_AWAITABLE` (`fetch`) AND there is more than one call, the body is wrapped in `(async()=>{await A;await B;…})().catch(e=>console.warn('[QS] chain aborted:',e))`. Every call gets `await`'d, so "fetch then hide" actually waits for the response.
3. **Backward-compat** — a sole `{{call:fetch:...}}` (or a chain with no awaitable verb) stays as-is. Single-call handlers and pure-sync chains don't pay the microtask cost.

Side channel: `QS.fetch` also dispatches `qs:fetch:loaded` / `qs:fetch:error` DOM events (with `detail.{ref, data}` or `detail.{ref, error}`) and caches the latest result per ref in `QS._fetchCache`. Use this when an action must react to a fetch fired from a different handler/page — see `QS.after(eventSuffix, handler)` and `QS.onceCached(eventSuffix, handler)` in `qs.js`. The chain rules above stay the default UX; events are the escape hatch.

`event.preventDefault()` caveat: after the chain enters its async IIFE, calling preventDefault is a no-op (the default action has already fired). `validate` is in the sync prelude precisely for this reason. Any future verb that needs preventDefault must be added to `CHAIN_SYNC_PRELUDE`.

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
infinite scroll, and is the client half of beta.8's planned server-side data
resolver (the definition is runtime-agnostic JSON — one shape, two executors).

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

**The store owns rendering.** `fetchState` passes a `noBindings` opt to `QS.fetch`
so the endpoint's own `responseBindings` are skipped on store fetches — otherwise an
append would flicker (bindings replace, then the store appends). Drive a store's
list via `data-state-list`, not via that endpoint's `responseBindings`.

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

Health is checked by dedicated commands:

- `validateTranslations` — keys missing from a language.
- `getUnusedTranslationKeys` — keys defined but never referenced.
- `analyzeTranslations` — full report.

---

## 12. Where to look next

| If you want to … | Read |
|---|---|
| Understand the admin panel JS architecture | [ADMIN_PANEL.md](ADMIN_PANEL.md) |
| Drive the API from a script or an LLM | [COMMAND_API.md](COMMAND_API.md) |
| Run or write a workflow | [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) |
| Map the on-disk layout | [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) |
| See it running | the project [README](../README.md) |
