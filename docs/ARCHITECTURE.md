# QuickSite Architecture

_Last updated: 2026-04-23._

> Canonical high-level overview of how QuickSite is structured and how a request flows through it. For the admin panel internals, see [ADMIN_PANEL.md](ADMIN_PANEL.md). For the workflow engine, see [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). For the full command reference, see [COMMAND_API.md](COMMAND_API.md). For the on-disk layout, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

> _Maintainers note:_ re-check this doc when changing `secure/management/routes.php` (§3 command count), `secure/management/config/roles.php` (§3 roles), or `secure/src/classes/JsonToHtmlRenderer.php` (§2 node kinds, §7 tag blacklist, §8 QS.* registry).

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes. It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

---

## 1. Three-layer model

QuickSite separates concerns into three top-level layers. Each one has a clear boundary and is responsible for a different audience.

| Layer | Folder | Audience | Purpose |
|---|---|---|---|
| **Project** | `secure/projects/{name}/` | Site owner | The actual website data: routes, page structures (JSON), translations, components, interactions, styles, assets. |
| **Management** | `secure/management/` | API client (admin panel, scripts, AI) | The 122 commands that read or mutate project data. Single entry point: `public/management/index.php`. Token + role enforced. |
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

The full list of 122 commands is registered in `secure/management/routes.php`. See [COMMAND_API.md](COMMAND_API.md) for the catalogue and a per-command reference (also obtainable at runtime via `GET /management/help`).

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

| Role | Reads | Mutates content | Mutates style | Build/deploy/AI | Tokens |
|---|---|---|---|---|---|
| `viewer` | ✓ | | | | |
| `editor` | ✓ | ✓ | | | |
| `designer` | ✓ | ✓ | ✓ | | |
| `developer` | ✓ | ✓ | ✓ | ✓ | |
| `admin` | ✓ | ✓ | ✓ | ✓ | |
| `*` (superadmin) | ✓ | ✓ | ✓ | ✓ | ✓ |

Named roles (`viewer`, `editor`, `designer`, `developer`, `admin`) and any custom role live in `secure/management/config/roles.php`. The superadmin `*` is **hardcoded** in `secure/src/functions/AuthManagement.php` (and `AdminRouter.php`) — it bypasses the roles file entirely and gets unconditional access to every command, including token and role management. It is the only role that cannot be created, edited, or deleted at runtime. Permissions are checked before the command file is included.

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

The same pattern — parse → validate → mutate files → `ApiResponse` — is used by all 122 commands.

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

The core registry currently exposes 16 built-ins:

| Group | Functions |
|---|---|
| Visibility | `QS.show`, `QS.hide`, `QS.toggle`, `QS.toggleHide` |
| Classes | `QS.addClass`, `QS.removeClass` |
| Forms | `QS.setValue`, `QS.focus`, `QS.blur`, `QS.validate` |
| Navigation | `QS.redirect`, `QS.scrollTo` |
| Data | `QS.filter`, `QS.fetch`, `QS.renderList` |
| Feedback | `QS.toast` |

Use the live `listJsFunctions` command for the authoritative list — it is the registry the visual editor and `addInteraction` validate against.

> _Roadmap note:_ this list will grow with beta.6 (interactions + search/filter), beta.7 (client-side API) and beta.8 (server-side API). Re-check this section when releasing those versions.

A project-scoped "custom JS function" feature existed in earlier betas (`addJsFunction` / `qs-custom.js`); it was removed in beta.3 for security reasons. Custom logic is now expressed by composing the core functions or, for richer client behaviour, registered API endpoints (see `addApi` / `testApiEndpoint`).

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
