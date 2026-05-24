# QuickSite Admin Panel

_Last updated: 2026-04-23._

> Canonical reference for the admin panel's JS architecture, boot flow, and module map. See [ARCHITECTURE.md](ARCHITECTURE.md) for the system-level overview, [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) for the workflow engine, and [COMMAND_API.md](COMMAND_API.md) for the API commands the panel calls.

> _Maintainers note:_ re-check this doc when changing `public/admin/assets/js/core/storage-keys.js` (§6 storage keys), `secure/admin/templates/pages/preview/sidebar-tools.php` (§8.1 visual editor modes), or `secure/admin/templates/layout.php` (§2 boot order).

The admin panel is a server-rendered PHP shell plus a set of vanilla JS modules loaded per route. There is **no module bundler**. Load order is controlled by `secure/admin/templates/layout.php` and is the single most important contract in the front end — reorder a `<script>` tag and the panel breaks silently.

State lives on a small set of `window.*` globals owned by core files. There is one custom event for cross-module signalling: `quicksite:command-executed`.

---

## 1. Layered runtime model

```
secure/admin/AdminRouter.php                 → route + auth
secure/admin/templates/layout.php            → shell + script loader
                                               injects window.QUICKSITE_CONFIG
secure/admin/templates/pages/<page>.php      → page shell + page-specific
                                               config injection
public/admin/assets/js/core/                 → api.js, utils.js, storage-keys.js
public/admin/assets/admin.js                 → window.QuickSiteAdmin singleton
public/admin/assets/js/components/           → colorpicker.js, miniplayer.js
public/admin/assets/js/pages/                → page controllers
public/admin/assets/js/pages/ai/             → AI sub-pages
public/admin/assets/js/pages/preview/        → visual editor subsystem
public/admin/assets/js/lib/css-refiner/      → CSS analysis library
                                               (consumed by optimize.js)
```

---

## 2. Boot flow

`layout.php` emits `<script>` tags in this exact order:

1. Inline `<script>` defines `window.QUICKSITE_CONFIG` (apiBase, adminBase, baseUrl, publicSpace, defaultLang, multilingual, translations, token, …).
2. `core/storage-keys.js` → defines `window.QuickSiteStorageKeys` (single source of truth for localStorage / sessionStorage key strings).
3. `core/api.js` → defines `window.QuickSiteAPI` (`request()`, `upload()`; emits `quicksite:command-executed` after every successful write).
4. `core/utils.js` → defines `window.QuickSiteUtils` (toasts, confirms, escaping, prefs, pending messages).
5. `admin.js` → defines `window.QuickSiteAdmin` (singleton: permissions load, user badge, nav, page utility delegation).
6. The page template loads its page-specific module(s). For preview pages, `preview-config.php` runs first and exposes `window.PreviewConfig`.

Any reorder breaks the chain. Treat the load order as a published contract.

---

## 3. Global object ownership

| Global | Owner | Consumers |
|---|---|---|
| `window.QUICKSITE_CONFIG` | `layout.php` (extended by some page templates) | every JS file |
| `window.QuickSiteStorageKeys` | `js/core/storage-keys.js` | `api.js`, `utils.js`, all pages that touch storage |
| `window.QuickSiteAPI` | `js/core/api.js` | `admin.js`, every page module |
| `window.QuickSiteUtils` | `js/core/utils.js` | `admin.js`, `history.js`, `sitemap.js`, preview modules |
| `window.QuickSiteAdmin` | `admin.js` | every page module (most broadly coupled global) |
| `window.PreviewConfig` | `templates/pages/preview-config.php` | `preview.js`, all `pages/preview/*` modules |
| `window.PreviewState` | `pages/preview/preview-state.js` | all `pages/preview/*` modules |
| `window.QSColorPicker` | `js/components/colorpicker.js` | `preview-style-editor.js`, `preview-style-theme.js` |
| `window.CSSRefiner` | `js/lib/css-refiner/*.js` (loaded by `optimize.php`) | `pages/optimize.js` |

There is **no** `pages/ai.js`, `pages/batch.js`, or `pages/docs.js` in the current codebase.

---

## 4. Event bus

One custom event system-wide:

```
emitter:   js/core/api.js  (after successful non-GET command responses)
event:     CustomEvent('quicksite:command-executed', { detail: { command, response } })

listeners: js/components/miniplayer.js                    (reloads floating preview iframe)
           js/pages/preview/preview-miniplayer.js          (preview-page instance)
```

Payload shape is implicit — there is no schema or version field.

---

## 5. File inventory

File lists are grouped by role. Where useful, the entry point or main exported function is named in the Purpose column. The codebase is in active beta development — do not treat these tables as a contract; treat them as a map.

### 5.1 Core

| File | Purpose |
|---|---|
| `js/core/api.js` | `QuickSiteAPI.request` + `upload`; token storage; emits `quicksite:command-executed`. |
| `js/core/utils.js` | `QuickSiteUtils`: toasts, confirms, escaping, prefs storage, pending messages. |
| `js/core/storage-keys.js` | Constants for every localStorage / sessionStorage key (`window.QuickSiteStorageKeys`). |
| `admin.js` | Singleton `QuickSiteAdmin`: permissions, user badge, nav, delegation. |

### 5.2 Shared components

| File | Purpose |
|---|---|
| `js/components/colorpicker.js` | `QSColorPicker` widget (used by preview style/theme tools). |
| `js/components/miniplayer.js` | Floating preview iframe; listens to `quicksite:command-executed`. |

### 5.3 Top-level page controllers

| File | Purpose |
|---|---|
| `pages/dashboard.js` | Stats cards, command activity, recent history. |
| `pages/command.js` | Command index list, permission-aware filter. |
| `pages/command-form.js` | Dynamic per-command form, helper widgets, execution. |
| `pages/history.js` | Command history listing + detail modal. |
| `pages/settings.js` | User settings + AI config status. |
| `pages/apis.js` | External API registry CRUD + endpoint test UI. |
| `pages/assets.js` | Asset browser, upload, edit, delete. |
| `pages/sitemap.js` | Route tree + reachability graph + sitemap controls. |
| `pages/embed-security.js` | Embed security configuration UI. |
| `pages/optimize.js` | Admin shell for the CSS Refiner library. |

### 5.4 AI sub-pages (`pages/ai/`)

| File | Purpose |
|---|---|
| `pages/ai/ai-index.js` | Workflow / spec listing, search, filters → `/admin/api/ai-spec/list`. |
| `pages/ai/ai-editor.js` | Spec editor (validation, preview, save) → `/admin/api/ai-spec/{get,save,delete}`. |
| `pages/ai/ai-connections.js` | BYOK connection wizard + connection list (cloud + local). Persists to the v3 store; no PHP roundtrip. |
| `pages/ai/ai-spec.js` | Workflow execution: resolve → render-prompt → execute. AI dispatch goes browser-direct via `QSAiCall`. |
| `pages/ai/lib/provider-catalog.js` | Pure data + per-provider HTTP helpers (URL / headers / body / parser). Used by `ai-connections.js` and `ai-call.js`. |
| `pages/ai/lib/connections-store.js` | v3 storage CRUD (`aiConnectionsV3`), `getActive()`, `recordStatus()`. |
| `pages/ai/lib/ai-call.js` | Browser-direct AI dispatcher (`QSAiCall.call`) with SSE streaming + abort + typed errors. |
| `pages/ai/lib/local-presets.js` | Prefill values for the local AI wizard (Ollama, LM Studio) including origin-scoped CORS instructions. |

### 5.5 Preview subsystem (`pages/preview/`)

| File | Purpose |
|---|---|
| `preview.js` | Main orchestrator — mode/node/iframe/layout/component/snippet/drag/mobile. **Monolith.** |
| `preview-state.js` | Shared state hub (getter/setter/listener). |
| `preview-navigation.js` | Route / language / device switching. |
| `preview-iframe-inject.js` | Iframe DOM injection + cross-doc messaging. |
| `preview-sidebar-resize.js` | Sidebar resize + persistence. |
| `preview-miniplayer.js` | Preview-page floating player. |
| `preview-style-selectors.js` | Selector browser. |
| `preview-style-editor.js` | Property editor (color picker, `var()` support). |
| `preview-style-animations.js` | Animation / keyframe editor. |
| `preview-style-theme.js` | Theme variable editor (light / dark scope). |
| `preview-transition-editor.js` | Transition-property editor. |
| `preview-js-interactions.js` | Element-level interaction (event) editor. |
| `preview-drag.js` | Drag-mode UX. |

### 5.6 CSS Refiner library (`lib/css-refiner/`)

A self-contained CSS analysis library consumed exclusively by `pages/optimize.js`:

- **Core** — `constants.js`, `css-parser.js`, `utils.js`
- **Analyzers** — `empty-rules.js`, `color-normalize.js`, `duplicates.js`, `media-queries.js`, `fuzzy-values.js`, `near-duplicates.js`, `design-tokens.js`
- **UI** — `components.js`, `diff-view.js`

All analyzers consume an AST and emit suggestions with diff metadata.

---

## 6. Storage key registry

Every localStorage / sessionStorage key is declared as a constant in `js/core/storage-keys.js`. Direct string-literal access in older page modules is the legacy pattern and should be migrated when those files are touched.

| Constant | Storage | Used by |
|---|---|---|
| `TOKEN` | localStorage + sessionStorage | `api.js`, `admin.js` |
| `REMEMBER` | localStorage | `api.js` |
| `PREFS` | localStorage | `utils.js`, `admin.js`, `settings.js`, `preview-sidebar-resize.js` |
| `PENDING_MESSAGE` | sessionStorage | `utils.js`, `admin.js` |
| `API_AUTH_TOKENS` | localStorage | `apis.js` |
| `AI_CONNECTIONS_V3` | localStorage | `ai-connections.js`, `ai-spec.js`, `connections-store.js` (canonical) |
| `AI_KEYS_V2` | localStorage / sessionStorage | `ai-spec.js`, `settings.js` (deprecated, read-only fallback) |
| `AI_DEFAULT_PROVIDER` | localStorage / sessionStorage | `ai-spec.js`, `settings.js` (legacy v2 selector) |
| `AI_PERSIST` | localStorage | `ai-spec.js`, `settings.js` |
| `AI_AUTO_PREVIEW` | localStorage | `settings.js`, `ai-spec.js` |
| `AI_AUTO_EXECUTE` | localStorage | `settings.js`, `ai-spec.js` |

---

## 7. PHP → JS config injection

| PHP file | Injects into | Notable fields |
|---|---|---|
| `templates/layout.php` | `window.QUICKSITE_CONFIG` | `apiBase`, `adminBase`, `baseUrl`, `publicSpace`, `defaultLang`, `multilingual`, `translations`, `token`, `apiUrl` |
| `templates/pages/settings.php` | extends `QUICKSITE_CONFIG` | `commandUrl`, `aiSettingsUrl`, `quicksiteVersion` |
| `templates/pages/apis.php` | inline `window.translations` | `apis` namespace |
| `templates/pages/preview-config.php` | `window.PreviewConfig` | full preview runtime data — routes, components, settings, i18n, token (200+ fields) |
| `templates/pages/ai/*.php` | `data-precomputed` on `.admin-ai-page` | precomputed components/routes snapshot |
| `templates/pages/optimize.php` | inline readiness flag | `window.__cssRefinerLibReady` |

There is no schema or runtime validation on injected objects, and page-level extensions of `QUICKSITE_CONFIG` can silently overwrite global fields. Treat the injected globals as read-only by convention.

---

## 8. Visual editor (preview subsystem)

The visual editor is the panel's flagship feature. It runs as a sidebar UI in the admin shell driving a preview iframe loaded with `?_editor=1`. The renderer (server side) emits `data-qs-node` and `data-qs-struct` attributes so the iframe can map clicks back to JSON paths.

### 8.1 Modes

The sidebar exposes one action button (Refresh) plus five editing modes — six toolbar buttons total. Source: `secure/admin/templates/pages/preview/sidebar-tools.php`.

| Button | Type | What it does |
|---|---|---|
| **Refresh** | Action | Reloads the preview iframe. |
| **Select** | Mode | Click an element → inspect node, add before/after/inside, delete or duplicate. |
| **Drag** | Mode | Drag elements to reorder within parent (`preview-drag.js`). |
| **Text** | Mode | Inline-edit a text node's translation **for the currently selected language**. Intentionally primitive: no rich text, no line breaks. Edits the value, never the key. |
| **CSS** | Mode | Click an element → CSS panel; edits apply to the element's selector with full pseudo-state support. |
| **Interactions** | Mode | Element-level event editor (`preview-js-interactions.js`); binds `{{call:fn:args}}` handlers using QS.* functions. |

### 8.2 Context selectors

The toolbar carries a **Page** dropdown (any route in `routes.php`) and a **Language** dropdown (any active language). Switching either reloads the iframe; in Text mode the language dropdown decides which translation file is being edited.

### 8.3 Device preview

Three breakpoints: Desktop (1920), Tablet (768), Mobile (375). The iframe is resized; the project's media queries apply automatically.

### 8.4 Shared regions

Menu and footer are global — editing them in any page's preview applies site-wide. The editor surfaces them inline so the operator always sees the page as visitors will.

### 8.5 How it works (technical)

```
┌───────────────┐   postMessage   ┌────────────────┐
│  Admin sidebar│ ◄─────────────► │ Preview iframe │
│  (UI modules) │                 │ (?_editor=1)   │
└──────┬────────┘                 └────────┬───────┘
       │ Management API calls               │ data-qs-* attrs added by
       ▼                                    ▼ JsonToHtmlRenderer in editor mode
┌───────────────┐                 ┌────────────────┐
│ /management/* │                 │ Server-rendered│
│ (commands)    │                 │ HTML           │
└───────────────┘                 └────────────────┘
```

1. Iframe loads with `?_editor=1` → renderer adds `data-qs-node` / `data-qs-struct`.
2. Click → iframe sends node path via `postMessage`.
3. Sidebar shows properties of the selected node.
4. Edit → API call to `editStructure` / `editNode` / `setStyleRule` / `editTranslation` / etc.
5. Preview reloads, or updates inline for text-only changes.

### 8.6 Commands the editor calls

| Group | Commands |
|---|---|
| Routes / structure | `getRoutes`, `getStructure`, `editStructure`, `addElement`, `deleteElement`, `addRoute`, `deleteRoute`, `editRoute`, `setLayout` |
| Components | `listComponents`, `addComponent`, `editComponent`, `deleteComponent` |
| Snippets | `saveSnippet`, `listSnippets`, `deleteSnippet` |
| Translations | `getTranslation`, `editTranslation` |
| Styles | `getStyles`, `setStyle`, `addStyle`, `deleteStyle`, `listStyleRules` |
| Theme | `getRootVariables`, `setRootVariables` |
| Animations | `getKeyframes`, `setKeyframes`, `deleteKeyframes` |
| Interactions | `getInteractions`, `setInteractions` |

For the full per-command reference see [COMMAND_API.md](COMMAND_API.md).

### 8.7 Complex element wizard

The fourth tab of **Add Element** (alongside HTML Tag, Component,
Snippet). Builds multi-node subtrees that would be tedious to assemble
node-by-node — form scaffolds with the validate+fetch chain pre-wired,
selects with all their options, lists, field rows with the
`[data-error-for=...]` span already in place.

**Where it lives in the UI**

`secure/admin/templates/pages/preview/contextual-add.php` adds the
"Complex" tab. When selected, the wizard area shows:
1. A **kind picker** (dropdown) listing every registered wizard.
2. The **kind-specific form** rendered by that wizard's
   `renderWizard(container)`.
3. The standard footer **Save** button calls the controller's
   `validate()` then `getConfig()`, then `POST /management/addComplexElement`.

The top-of-page "Add Element" quick-add button is hidden while the
Complex tab is active — the wizard needs config first, so the
quick-add doesn't fit.

**Architecture — builder + wizard pairs**

| Side | File pattern | Job |
|---|---|---|
| Server | `secure/src/classes/complexElements/<Kind>.php` (class extends `ComplexElementBuilder`) | Pure: config in → node spec out. No I/O. Validates the config, throws `ComplexElementBuilderException` on bad input. |
| Client | `public/admin/assets/js/pages/preview/contextual-complex/complex-<kind>.js` | Renders the wizard form, returns `{ getConfig, validate, destroy }`, registers itself under `window.QSComplexWizard.registry[<kind>]`. |

After save, the emitted subtree is **indistinguishable** from a
hand-built one — same JSON shape, same renderer. The wizard is
build-time only; nothing at render time knows the element came from
here. You can re-edit any of its nodes with the normal visual-editor
tools.

**Builder contract** (`ComplexElementBuilder` abstract base)

```php
abstract public function kind(): string;              // 'list', 'select', etc.
abstract public function build(array $config): array; // → single root node spec
public function declaredTextKeys(array $config): array; // → string[] (optional)
```

Helpers exposed to subclasses: `stripWizardKeys`, `requireField`,
`validateHtmlId`. The dispatcher (`addComplexElement` command)
auto-discovers builders by globbing `complexElements/*.php` — drop a
new file, no registration.

**Wizard contract** (JS)

```js
window.QSComplexWizard.registry['<kind>'] = {
    label: 'Human label',
    description: 'Short HTML-safe description.',
    renderWizard: function (container) {
        // Populate `container`. Return:
        return {
            getConfig: function () { /* → JSON-serialisable config */ },
            validate:  function () { /* → null on OK, error string on failure */ },
            destroy:   function () { /* clean up listeners + DOM */ }
        };
    }
};
```

Same auto-discovery pattern client-side — `preview-config.php` globs
`complex-*.js` so dropping a new wizard file is enough.

**Shared primitives** for wizard authors:

| Primitive | What it does |
|---|---|
| `QSComplexWizard.createRowEditor` | Variable-cardinality row list with reorder/add/delete + keyboard nav. Used by every wizard that has a "list of N things" section (Select options, FormScaffold fields, List items). |
| `QSComplexWizard.createTextKeyPicker` | Translation-key picker — searchable list of existing keys + inline "Create" form for new ones. Matches the component-variables panel UX. |

**Built-in kinds** (beta.7)

| Kind | Emits | Notes |
|---|---|---|
| `field-row` | `<div class="field"><label/><input/><span data-error-for/></div>` | Single form field, ready for `QS.validate` per-field error wiring. Building block for Form Scaffold. |
| `form-scaffold` | `<form>` + N field rows + submit button, with `onsubmit` chain pre-wired (validate + fetch + optional post-submit actions) | Headline API-objective integration. Reuses `FieldRow` internally for each field. |
| `select` | `<div class="field"><label/><select/><span data-error-for/></div>` with N `<option>` children | Same outer shape as field-row so a select sits alongside text inputs in a form-scaffold and picks up the same QS.validate hook. Optional placeholder option + required + multiple. |
| `list` | `<ul>` or `<ol>` with N `<li><textKey/></li>` children | Simple flat list. `<ol>` supports `start` and `reversed`. |

**Adding a new kind — 2-file recipe**

1. Drop `secure/src/classes/complexElements/<MyKind>.php` declaring a
   class that extends `ComplexElementBuilder`. Auto-loaded.
2. Drop `public/admin/assets/js/pages/preview/contextual-complex/complex-<my-kind>.js`
   that registers itself under `QSComplexWizard.registry['<my-kind>']`.
   Auto-loaded.
3. Reload the admin page. The kind appears in the picker, the wizard
   renders, save dispatches to your builder.

No `routes.php` edits, no `roles.php` edits — those are wired once at
the `addComplexElement` command level.

**textKey allocation**

When a wizard emits a translatable string (label, placeholder,
button text, list-item label), the wizard's textKey picker either
points at an existing translation key OR creates one with an empty
value on save. The picker uses the standard `setTranslationKeys`
command, which now also enforces the leaf-vs-branch collision rule
(can't have both `form.email.label` AND `form.email.label.placeholder`
in the file — pick a different sibling).

**Atomicity**

The whole subtree is spliced under one file lock by reusing
`addNode`'s insertion helper. If the builder throws or returns a
malformed node, nothing is written. Half-built subtrees never reach
the structure file.

---

## 9. Other pages — what they do

| Page | What it does |
|---|---|
| **Dashboard** (`dashboard.js`) | Stats cards (route count, language count, recent build), command activity feed, recent history. Calls `help`, `getRoutes`, `getCommandHistory`. |
| **Command** (`command.js`) | Permission-filtered command index. |
| **Command form** (`command-form.js`) | Renders a dynamic form for any command from `help` metadata, then executes it. The escape hatch into raw API. |
| **History** (`history.js`) | Browses `getCommandHistory`; modal with full request/response. |
| **Settings** (`settings.js`) | User profile, language, theme, AI provider config status. |
| **APIs** (`apis.js`) | External API registry — see §9.1. Commands: `listApiEndpoints`, `addApi`, `editApi`, `deleteApi`, `getApiEndpoint`, `testApiEndpoint`. |
| **Assets** (`assets.js`) | Asset browser + uploader: `listAssets`, `uploadAsset`, `editAsset`, `deleteAsset`. |
| **Sitemap** (`sitemap.js`) | Route tree, reachability, ordering. |
| **Embed security** (`embed-security.js`) | `getEmbedSecurity` / `setEmbedSecurity`. |
| **Optimize** (`optimize.js`) | UI for the CSS Refiner library; runs analyzers, presents diffs, applies edits via `editStyles` / `setRootVariables`. |
| **AI workspace** (`pages/ai/*`) | Listing, editing, executing AI workflow specs against `/admin/api/ai-spec/*`, `/admin/api/workflow/*`, `/admin/api/batch/execute`. AI dispatch itself is browser-direct (`QSAiCall`). See [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). |

### 9.1 API Registry (/admin/apis)

Per-project registry of external HTTP APIs. The user declares APIs
once in this admin page; runtime `QS.fetch(...)` on the public site
resolves them by ID and substitutes parameters at call time.

**Storage**: `secure/projects/<project>/data/api-endpoints.json`,
managed by `secure/src/classes/ApiEndpointManager.php`.

**Runtime config** (auto-regenerated on every CRUD): the manager
writes `public/scripts/qs-api-config.js` which exposes
`window.QS_API_ENDPOINTS = { "<apiId>": { baseUrl, auth, endpoints } }`.
Pages get this script included automatically when at least one API
is registered (see `PageManagement::render()`).

**Endpoint schema** (fields the modal exposes):

| Field | Purpose |
|---|---|
| `id` | Stable identifier (matches `/^[a-z0-9][a-z0-9\-_]*$/i`). |
| `name` | Human label shown in the picker. |
| `method` | `GET / POST / PUT / PATCH / DELETE`. |
| `path` | Relative to API `baseUrl`. May contain `:placeholders` (see below). |
| `parameters` | Optional array of `{ name, type, required, description }`. Type ∈ `string / number / integer / boolean`. |
| `auth` | Per-endpoint override: `none / inherit / required`. Inherits from API-level auth by default. |
| `requestSchema` | Optional JSON Schema for POST/PUT/PATCH bodies. Drives the test modal's dynamic form. |
| `responseSchema` | Optional JSON Schema. Drives the response-bindings picker (componentList / count / etc., see beta.7 work). |

**Path templating** — `:name` placeholders in the `path` are
substituted at fetch time. Example:

```
path:       /users/:id/posts/:postId
parameters: [{ name: "id", required: true }, { name: "postId", required: true }]
call:       QS.fetch('@my-api/get-post', 'id=42', 'postId=7')
result:     GET <baseUrl>/users/42/posts/7
```

Validation rules (`ApiEndpointManager::validateEndpoint`):
- Every `:name` in `path` must appear in `parameters`.
- Parameter names match `/^[a-zA-Z][a-zA-Z0-9_]*$/`, no duplicates.
- Unknown types or shapes are rejected with a clear message.

Missing **required** parameters at runtime → `QS.fetch` rejects
with a toast. Missing **optional** parameters leave the `:name`
literal in the URL (so the user can see what's empty in the test
panel and in dev tools).

Non-placeholder `opts` keys (anything not in
`{body, onSuccess, onError, silent, _auth, _endpoint}`) get appended
as query-string parameters. Example:
`QS.fetch('@my-api/users', 'page=2', 'sort=name')` →
`GET <baseUrl>/users?page=2&sort=name`.

**`baseUrl` cleanup**: trailing `/` or `\` is stripped before
storage / validation, so a Windows-paste like `"http://test.api\\"`
round-trips cleanly.

**Import**: two-screen modal (paste → preview → confirm).
Auto-detects foreign formats (currently: a file-manager-style JSON
with `endpoints.{public, secured, ...}` groups) and converts to our
shape before saving. The converter:
- Iterates **every** top-level group under `endpoints`; each becomes
  its own API named `<base>-<group>`.
- Group with `{ authentication: {...}, endpoints: [...] }` shape →
  bearer/basic/apiKey auth based on `type`.
- Group as a flat array → no auth.
- Rebuilds each endpoint's path with `:placeholders` based on its
  declared parameters and the API's `route_format` hint
  (`"path segments"` → `/name/:name`; otherwise leaves params as
  query-string-bound).

### 9.2 Component list binding

A response-binding render mode that fills a container with one cloned
component instance per item of an array response field. The component
template acts as the per-item shape; a `fieldMap` says which API field
feeds which template variable, with optional enum resolution.

**Where it lives in the UI**

The picker is part of the **JS Interactions** modal (per-element
event handler authoring). Component-list mode appears when:
- The interaction is in **Action Type: API Call** (not Function — see
  the discoverability note in [BACKLOG.md](../NOTES/planning/BACKLOG.md)
  → "fetch Function-vs-API-mode unification").
- The selected endpoint has a `responseSchema`.
- The selected field is an `array`.

Then a **render-mode radio** appears below the field picker:
`( ) data-bind template   ( ) Component per item`.
Picking "Component per item" reveals: component selector + fieldMap
table + data-bind coverage warning + empty-text input.

**Picker mechanics**

| UI | Behaviour |
|---|---|
| Field dropdown | Dot-paths now supported. Recurses into `type: object` properties (`data.commandsList` etc.). Arrays terminate recursion — their item shape drives the fieldMap. |
| Component dropdown | Populated from `listComponents`. |
| FieldMap table | One row per target var. Target vars = union of `{{placeholder}}` keys + `__enums__` short keys in the component template. "From" column auto-defaults to name-match against the array's `items.properties`; user can override. "Enum" column shows a checkbox for vars declared in `__enums__`, pre-checked with the fully-qualified `<componentName>.<shortKey>` name. |
| Warning banner | Yellow banner when one or more fieldMap target vars are missing a `data-bind="<var>"` attribute on the component template. The `{{<var>}}` placeholders are server-resolved once at hidden-template render time; the runtime needs `data-bind` to know which DOM nodes to update on each cloned item. |
| Empty-text input | Optional. Rendered as a `<p class="qs-list-empty">` in the container when the API returns an empty array. |

**Persisted shape**

The picker writes to the endpoint's `responseBindings` array via
`editApi`. Component-list bindings have this shape:

```json
{
  "field":      "data.commandsList",
  "renderMode": "componentList",
  "container":  "#commands-list",
  "component":  "component-command-card",
  "fieldMap": {
    "name":         { "from": "name" },
    "desc":         { "from": "description" },
    "method_text":  { "from": "method", "enum": "component-command-card.method_text" },
    "method_class": { "from": "method", "enum": "component-command-card.method_class" }
  },
  "emptyText": "No commands found"
}
```

**Page-side setup**

The page needs three things:

1. A **container** with a stable id matching `binding.container`
   (e.g. `<div id="commands-list">`).
2. **Inside it**, a **hidden component instance** marked as the
   template via call-site `params`:
   ```json
   {
     "component": "component-command-card",
     "data": { "name": "—", "method": "get", ... },
     "params": { "data-list-template": "true", "style": "display:none" }
   }
   ```
   The renderer (`JsonToHtmlRenderer`) merges these call-site params
   into the rendered template root — class concatenates, style
   concatenates, everything else overrides. This is what makes the
   first child of `#commands-list` discoverable by `qs.js`'s
   `[data-list-template]` lookup AND invisible until cloned.
3. The component template must declare `data-bind="<varName>"` on the
   elements the runtime should update. The picker warns when a
   fieldMap target lacks a matching `data-bind` — the binding will
   save fine, but the runtime won't be able to find the slot.

**Authoring `data-bind` on a component**

The picker scope intentionally stops at writing the binding. Adding
`data-bind` attributes to a component template is currently a manual
edit of the component JSON (or via the regular visual editor's
attribute UI, with the component opened for editing). When both
text content AND an attribute need to be bound on the same conceptual
element, use a nested element pair (outer = attribute via
`data-bind` + `data-bind-attr`, inner = text via `data-bind`).
See `component-command-card.json` for a worked example.

**Runtime flow**

```
QS.fetch resolves the endpoint                  (public/scripts/qs.js)
   ↓
applyBindings(data, responseBindings)
   ↓  binding.renderMode === 'componentList'
renderComponentList(container, items, fieldMap, emptyText)
   ↓  per item: walk fieldMap → build mapped object
           if spec.enum: mapped[var] = QS.enum(spec.enum, raw, raw)
           else:         mapped[var] = raw
   ↓
clone the cached [data-list-template] node, drop the marker + display:none
   ↓
populateTemplate(clone, mapped) — existing data-bind walker
   ↓
container.appendChild(clone)
```

The hidden template is cloned once (cached per container) and
populated per item. The clone has the `data-list-template` attribute
removed and inline `display:none` stripped before insertion.

**Enum resolution**

`spec.enum` names a fully-qualified entry in `window.QS_ENUMS`
(populated by `qs-enums.js`). `QS.enum(name, value, fallback)`
returns the mapped value if found, else the fallback, else the raw
value (with a `console.warn` when the named table is missing). The
runtime is forgiving — a stale binding doesn't break the page.

**Generation of `qs-enums.js`** is documented in `docs/ARCHITECTURE.md`
under "Enum sync".

### 9.3 Count binding

A response-binding render mode that writes the count of a fetched
value into a target element, optionally as a translated sentence with
zero/one/many branching and `{n}` substitution.

**Where it lives in the UI**

Same picker as component list — when the binding's field is an
array, the render-mode radio shows three options: `data-bind template`,
`Component per item`, **`Count`**. Picking Count reveals:

- **Target** — the `selector` field is the element whose
  `textContent` gets the count. Reuses the same searchable picker
  as other binding modes.
- **Format** sub-radio:
  - `Just the number` — writes the count number verbatim.
  - `Translated sentence` — picks one of three translation keys
    based on the count and substitutes `{n}` with the number.
- **Sentence pickers** (when Format is Translated sentence): three
  textKey pickers labelled Zero / One / Many. Uses the same shared
  `QSComplexWizard.createTextKeyPicker` primitive — searchable, with
  inline "Create" form.
- **Fallback** — number used when the field resolves to null, missing,
  or a falsy non-array (0 / "" / false). Defaults to 0.

**Count semantics**

| Value type | Count |
|---|---|
| Array | `value.length` |
| null / undefined | fallback |
| 0 / "" / false | fallback |
| any other truthy (object, non-zero number, non-empty string, true) | 1 |

**Persisted shape** (in `secure/projects/<p>/data/api-endpoints.json`)

Just-the-number form:

```json
{
  "field":      "data.commandsList",
  "renderMode": "count",
  "selector":   "#cmd-counter",
  "fallback":   0
}
```

Translated-sentence form — stores **keys**, not the resolved strings:

```json
{
  "field":      "data.commandsList",
  "renderMode": "count",
  "selector":   "#cmd-counter",
  "fallback":   0,
  "format":     "sentence",
  "zeroKey":    "home.commandsZero",
  "oneKey":     "home.commandsOne",
  "manyKey":    "home.commandsMany"
}
```

**Compile-time translation**

`ApiEndpointManager::transformBindingsForCompile()` is called when
writing `qs-api-config.js`. For each count-sentence binding, it
resolves `zeroKey` / `oneKey` / `manyKey` to translated strings via
`Translator::translate(...)` and emits them as `zeroStr` / `oneStr` /
`manyStr` in the compiled JS. The runtime receives **resolved
strings**, not keys — PHP stays the only translation engine.

What the runtime sees in `qs-api-config.js`:

```js
{
  field:      "data.commandsList",
  renderMode: "count",
  selector:   "#cmd-counter",
  fallback:   0,
  format:     "sentence",
  zeroStr:    "No commands",
  oneStr:     "1 command",
  manyStr:    "{n} commands"
}
```

**Multi-language limitation** (current beta.7)

`qs-api-config.js` is project-scoped, not per-language. Translation
happens once at `editApi` / `switchProject` / `build` time using
whatever language is active in that request. Multi-language sites
should re-trigger one of those after a language switch, OR ship the
proper fix tracked in [BACKLOG.md](../NOTES/planning/BACKLOG.md)
("Per-language count bindings via inline translation registry").

**Runtime flow**

```
QS.fetch resolves                                (public/scripts/qs.js)
   ↓
applyBindings(data, responseBindings)
   ↓  binding.renderMode === 'count'
renderCount(value, binding)
   ↓  n = computeCount(value, binding.fallback)
   ↓  if binding.format === 'sentence':
   ↓      template = n === 0 ? zeroStr : n === 1 ? oneStr : manyStr
   ↓      output = template.replace('{n}', String(n))
   ↓  else:
   ↓      output = String(n)
   ↓
elements.forEach(el => el.textContent = output)
```

Sentence-mode is forgiving: if the matching string is missing (e.g.
the translation key didn't resolve), the runtime falls back to the
raw number and emits a `console.warn`.

### 9.4 Fetch picker (Action Type: API Call)

The structured authoring UI for fetch interactions in the JS panel.
Lives in `contextual-js.php` (template) and
`preview-js-interactions.js` (behaviour). Reached via Add Interaction
→ Action Type **API Call**.

**Top-level layout**

```
Event       [ onsubmit                                    ▼ ]
Action Type [ API Call                                    ▼ ]

── API ────────────────────────────────────────────────────────
Mode        ( ) From registry   ( ) Direct URL
[ registry mode shown ]                | [ direct mode shown ]
  API       [ test-api-public      ▼ ] |   Method  [ POST  ▼ ]
  Endpoint  [ GET /listFile        ▼ ] |   URL     [ ...      ]
  Target    @test-api-public/list-files
[ Path params (only if path has :placeholders) ]
  :nameContains  [ value or #selector.value          ]
Body source       [ #form (hidden on GET / DELETE)   ]

▼ Advanced (collapsed by default)
  On success   [ form.contact.success    ▼ textKey ]  ☐ silent
  On error     [ form.contact.error      ▼ textKey ]  ☐ silent
  Post-fetch actions
    [ + Add action ]
      [ verb ▼ ] [ args... ] [×]
      [ verb ▼ ] [ args... ] [×]

Preview      {{call:fetch:@test-api/help,toastSuccessKey=Saved!}};{{call:hide:#form}}
```

**Mode toggle**

- **From registry** (default): pick from the project's API + endpoint
  catalogue. Picker writes `@apiId/endpointId` as the first arg.
- **Direct URL**: pick a method + type a URL (supports `{{lang}}` and
  `:placeholders`). Picker writes `METHOD,URL` as the first two args.

Both modes produce the same runtime call shape; QS.fetch handles them
via its `target.startsWith('@')` branch.

**Path params** (registry mode + direct mode)

When the selected endpoint's path (or the typed URL) contains
`:placeholders`, the picker auto-renders one row per placeholder. Each
row is a free-text value input that the user fills (literal value
like `42` or a runtime selector like `#search-input.value` — the
runtime decides). Values are emitted as keyword args.

The placeholder substitution happens client-side in `qs.js` (see
`docs/ADMIN_PANEL.md §9.1` "Path templating"). The picker just
surfaces the placeholders as configurable inputs.

**Body source**

Hidden when the effective method is GET or DELETE (the browser
forbids a body on those; qs.js already flattens such bodies to
query-string args). Otherwise free-text — typically `#form-id`.

**Toast messages** (Advanced fold)

Two textKey pickers (success / error) + per-side silent checkbox.
Translation happens at compile time via the
`TRANSLATABLE_KEYWORD_ARGS` table in `JsonToHtmlRenderer` (see
`docs/ARCHITECTURE.md` for the metadata pattern). The compiled chain
ships translated **strings**, not keys. Multi-language works for
free — each page render runs `transformCallSyntax` in the request's
language.

`silent` opts out of BOTH success and error toasts (matches the
existing `silent=true` semantics in qs.js). Per-side silent in the
picker UI is a UX convenience that collapses to the runtime's
single-flag model — if either side is silent, `silent=true` is
emitted.

**Post-fetch action list** (Advanced fold)

Authors chain steps that fire AFTER the fetch resolves. The list is
NOT stored alongside the main interaction — each row is persisted as
a **sibling interaction** on the same event. The renderer's
`transformCallSyntax` then compiles the chain in storage order with
`await` between awaitable steps (see
`docs/ARCHITECTURE.md §8.0.1`).

Save model is **replace-on-save**: when the user clicks Save, the
picker deletes any existing sibling interactions for this event
(those that come AFTER the main fetch's index) and re-adds the
current state in order. The picker's state is the source of truth.

Adding a row picks a verb from the available functions (excluding
`fetch` — chained fetches are unusual and easier to author as a
top-level sibling). Verb-specific arg inputs render via the same
`_createArgRow` helper used in Function-mode authoring.

**Edit semantics**

Saved interactions don't carry an explicit "authored in API mode"
marker. The picker detects the mode on edit:
- First param starts with `@` → registry mode.
- First param matches a known HTTP method → direct mode.

Kwargs (`body=...`, `toastSuccessKey=...`, `:placeholder=...`, etc.)
are parsed back from the param tail and populated into the right
form fields.

**Toast keys on edit**: the saved chain has the *resolved* string
(PHP translated it at compile time), not the key. The picker's
textKey picker shows KEYS — so on edit, the toast pickers start
empty. Re-picking re-emits the key. Documented limitation; the
"save the key alongside the resolved string in api-endpoints.json"
fix is filed in BACKLOG.md.

**Compile-time translation pattern (reusable)**

The metadata table for `fetch` is the first consumer:

```php
private const TRANSLATABLE_KEYWORD_ARGS = [
    'fetch' => ['toastSuccessKey', 'toastErrorKey'],
];
```

Future verbs that introduce translatable kwargs (e.g. `confirm`,
`prompt`) add one line. `JsonToHtmlRenderer::buildQsCallJs` walks
each call's args and translates the value whenever the key is in
the list.

### 9.5 Auth flows — Tier 1 (token persistence), cookie pattern, Tier 2 (refresh on 401)

Token-flow primitives across two shipped tiers (persistence + refresh)
plus the cookie auth type. Tier 3 (reserved `/auth/*` routes for
magic-link / OAuth) is still planned — see
`NOTES/planning/BETA7_AUTH_FLOWS.md`.

#### `QS.saveToken(storage, key, path)` / `QS.clearToken(storage, key)`

Runtime verbs (qs.js) that move a value from the last fetch's
response into browser storage — and back out on logout.

| Verb | Args | What it does |
|---|---|---|
| `saveToken` | `storage` (`localStorage`/`sessionStorage`), `key` (storage key name), `path` (dot-notation into `QS._lastFetchResult`) | Reads the value, writes to `window[storage].setItem(key, value)`, fires `qs:auth:saved` on `document`. |
| `clearToken` | `storage`, `key` | Removes from storage, fires `qs:auth:cleared`. |

Events carry `detail: { storage, key, tokenKey, value? }`. UI components
that change with login state can listen on `qs:auth:saved` /
`qs:auth:cleared` and re-render.

Forgiving by design: invalid storage type, missing fetch result,
empty path resolution, or storage write failure → `console.warn` +
no-op. Doesn't throw. A failing saveToken in a chain doesn't abort
the subsequent steps.

**Typical login chain** (authored via the picker):

```
onsubmit:
  {{call:validate:event,#login-form}};
  {{call:fetch:@auth-api/login,body=#login-form}};
  {{call:saveToken:localStorage,authToken,token}};
  {{call:redirect:/dashboard}}
```

#### Picker auth-helper hint

The fetch picker auto-detects token-shaped fields in the selected
endpoint's `responseSchema` (`token`, `accessToken`, `refreshToken`,
`jwt`) and surfaces a one-click banner above the body source:

> Token field detected: `token`  &nbsp; **[+ saveToken("token")]**

Clicking pushes a pre-filled `saveToken` row into the post-fetch
actions list (`localStorage` / `<leaf-name>` / `<path>`). The user
can then edit the storage / key / path or remove the row.

The hint only shows in **Registry mode** (Direct URL has no
schema). Nested paths are supported — e.g. a response with
`data.access_token` surfaces the button as `[+ saveToken("access_token")]`
and the action's `path` arg is `data.access_token`.

#### Cookie auth type (Pattern X)

New value for `auth.type` in the API admin form: **`cookie`** —
**Cookie (browser-managed)**. Use when the API and the web app
share an origin and the API sets a session cookie on login
(legacy PHP/Rails/Django apps, internal admin tools).

What it does: `QS.fetch` adds `credentials: 'include'` to the
underlying browser `fetch()` call. The browser owns the cookie;
no `tokenSource` plumbing, no `saveToken` call, no `Authorization`
header. The server's `Set-Cookie` header on login is
self-contained.

What it doesn't do: CSRF token handling. If your API expects
a CSRF token echoed in a header (e.g. `X-CSRF-Token` from a cookie),
that's currently manual via a separate interaction. Documented
limit; filed in BACKLOG for a future CSRF helper.

`credentials: 'include'` also works cross-origin if the server sets
the right CORS headers (`Access-Control-Allow-Credentials: true`
plus a concrete `Access-Control-Allow-Origin` — not `*`).

#### Tier 2 — Refresh on 401

When a bearer-authed endpoint returns **401**, `QS.fetch`
transparently obtains a fresh access token and retries the original
request once — no toast, no chain change. Configured per-API in the
**Refresh (optional)** section of the API admin auth form (shown only
when auth type is `bearer`). The `auth` config grows five optional
fields — the first four move together (all-or-nothing), the last is
truly optional:

| Field | Meaning |
|---|---|
| `refreshEndpoint` | `@apiId/endpointId` of the endpoint that issues new tokens |
| `refreshTokenSource` | `localStorage:key` / `sessionStorage:key` where the refresh token lives |
| `refreshTokenBodyField` | body field name carrying the refresh token in the refresh request |
| `responseTokenPath` | dot-path in the refresh response to the new access token |
| `responseRefreshTokenPath` | (optional) dot-path to a rotated refresh token to store back |

Runtime flow (`qs.js`) on a 401 from an endpoint whose auth declares
`refreshEndpoint`:

1. Read the refresh token from `refreshTokenSource`. If absent, skip
   refresh — the 401 flows to the normal error path.
2. POST to `refreshEndpoint` (resolved from the registry, running with
   **its own** configured auth) with a body of the refresh token under
   `refreshTokenBodyField`. The refresh call uses plain `fetch`, so it
   can never recurse into another refresh.
3. On success: write the new access token (`responseTokenPath`) to
   `tokenSource`; if `responseRefreshTokenPath` is set and present in
   the response, rotate the stored refresh token. Then retry the
   original request once with the new bearer.
4. On failure (refresh token rejected, or no token in the response):
   clear **both** stored tokens — firing `qs:auth:cleared` so a
   listener can redirect to login — then let the original 401 surface.

Notes:
- **401 only.** Refresh triggers on 401 (the convention for an
  expired/invalid access token), not 403 (forbidden). APIs should
  return 401 for expired tokens.
- **Retry once.** A second 401 after the refresh flows to the error
  path — no refresh loop.
- **Concurrency.** Concurrent 401s sharing a `refreshTokenSource`
  collapse to a single in-flight refresh request (`QS._refreshInFlight`).
- The token write reuses the same `setStoredToken` helper as
  `QS.saveToken`, so a refresh also fires `qs:auth:saved`.
- Validation (`ApiEndpointManager::validateAuth`) enforces bearer-only,
  the all-or-nothing rule for the first four fields, and restricts the
  refresh-token store to `localStorage` / `sessionStorage` (never
  `config`, which would publish the refresh token to every visitor).

#### Auth-state UI primitives

With tokens persisting (Tier 1) and refreshing (Tier 2), the runtime
offers declarative, *authorable* ways to react to login / storage state —
enough to build login / logout / status UIs without hand-written JS. All
bindings re-apply on page load and on `qs:auth:saved` / `qs:auth:cleared`
(`saveToken` / `clearToken` / `refresh` emit those).

**Verbs**

| Verb | Use |
|---|---|
| `QS.isAuthed("localStorage:key")` | boolean — is a non-empty value stored at that source |
| `QS.refresh("@apiId")` | manually run the Tier 2 refresh for an API (button-friendly). Resolves the API's auth config and reuses the auto-refresh flow. A raw fetch can't replace it — only this injects the stored refresh token. |
| `QS.applyAuthState()` | re-scan the bindings below after injecting DOM dynamically |

`refresh` is in the interaction picker (`listJsFunctions`); `applyAuthState`
is allowlisted for hand-authoring but not surfaced (niche).

**Declarative bindings** — plain `data-*` (NOT `data-qs-*`, which the editor
reserves for its own markers and strips from user params):

| Attribute | Effect |
|---|---|
| `data-auth-show="in"` / `"out"` | show only when logged in / out. Token source from `data-auth-source` on the element or any ancestor (set once on a wrapper). |
| `data-storage-show="has:localStorage:key"` / `"missing:localStorage:key"` | generic presence show/hide for any storage key |
| `data-storage-value="localStorage:key"` | sets the element's text to the stored value |

`data-auth-show` is auth sugar over "is the token present"; the
`data-storage-*` pair is the general form (any key, auth or not).
**Gotcha**: the show/hide elements must be **siblings**, not nested — a
hidden parent hides its children regardless of their own state.

A scaffold workflow that emits this structure correctly, plus a generic
`QS.store` verb (+ `qs:storage:changed` event) for non-auth writes, are
filed in `BACKLOG.md` as the ergonomic follow-up.

---

### 9.6 State stores

State stores give page interactions memory — named, page-scoped client state
bound to one API endpoint. The runtime and definition shape live in
[ARCHITECTURE.md §8.3](ARCHITECTURE.md); this section covers the editor UX.

**Where.** Visual editor → **JS mode** → the **"State stores"** area, a collapsible
section directly under **Page Events** (both are page-level, independent of the
selected element). It lists the current page's stores and opens the wizard.

**Store card.** Each store is a row: the store id, its `@apiId/endpointId` + field
count (plus an "onload" marker when `fetchOnLoad` is set), with edit and delete
actions. Delete is read-modify-write — it re-saves the route's remaining stores
(clearing the route entirely when the last store goes).

**Wizard (new / edit).**
- **Store id** — starts with a letter, then letters / digits / `_` / `-`.
- **Endpoint** — an API `<select>` + endpoint `<select>` from the registry.
- **Fetch on page load** — fire the store once on load.
- **Fields** — one card per field: name, **direction** (`→ request` / `← response`
  / `⇄ both`), then conditional rows that toggle with direction — **init** +
  **default** for request/both, **from** + **append** for response/both. **`init`
  is the initial value** (leave it blank to use the default); the field *name* is
  the request parameter key, not `init`.
- **Auto-seed** — picking an endpoint that declares request/response schemas
  pre-fills the fields (request properties → `request`, response leaves →
  `response`, a name in both → `both`). It seeds only when no field has been entered
  yet (never clobbers) and is a no-op when the endpoint has no schema — a
  non-binding, fully editable starting point.

**Triggering.** `setState` and `fetchState` appear in the interaction picker's
**Function** dropdown (both the element form and the page-event form). Their `store`
argument is a dropdown of the page's stores; `setState` adds `field` and `value` (a
literal, or a `#id` / `.class` selector read live). They compile to
`{{call:setState:store,field,value}}` / `{{call:fetchState:store}}` — chain them for
"go to page N", search-as-you-type, or scroll-to-load-more.

**Rendering.** The store updates elements carrying `data-state-value="store.field"`
(scalars) and `data-state-list="store.field"` (arrays — the container's first child
is the item template, `data-bind` on descendants, optional `data-state-empty`).
These bindings are authored in the page structure: the wizard defines the *data*,
not the render target.

| Concern | Where |
|---|---|
| Panel + wizard markup | `secure/admin/templates/pages/preview/contextual-js.php` |
| Panel + wizard logic | `public/admin/assets/js/pages/preview/preview-js-interactions.js` (State Stores section) |
| Verbs in the picker | `listJsFunctions` (`setState` / `fetchState`, `store` inputType) |
| Read / write | `getStateStores` / `setStateStores` → `StateStoreManager` |

---

## 10. Risk hotspots

These are the live concerns to keep in mind when touching the panel.

| ID | Risk | Where |
|---|---|---|
| R1 | Mutable globals without contracts | `QUICKSITE_CONFIG` extended by 6+ PHP pages; `QuickSiteAdmin` mixes DOM + permissions state. |
| R2 | Implicit boot load order | Sequential `<script>` tags; no module system; silent breakage on reorder. |
| R3 | HTML-string rendering in JS | `preview-style-editor.js`, `sitemap.js`, `history.js` build large `innerHTML` strings without an enforced escape policy. |
| R4 | Mixed API call patterns | Some files use `QuickSiteAdmin.apiRequest`, others go straight through `QuickSiteAPI.request`. |
| R5 | Preview monolith | `preview.js` is 9,374 lines and growing. |
| R6 | Async race on theme scope switch | Mitigated by `themeLoadSeq` guard; AbortController would be cleaner. |
| R7 | `PreviewConfig` size | i18n + config grow with every preview feature. Per-panel lazy load would help. |
| R8 | No `destroy()` contract on widgets | Color picker / event listeners can accumulate on panel re-renders. |
| R9 | Inline `onclick` in generated HTML | Pollutes `window`. Replace with delegated `data-*` handlers. |

---

## 11. PHP integration files

| File | Role |
|---|---|
| `secure/admin/AdminRouter.php` | Route parsing, auth gate, URL helpers. |
| `secure/admin/templates/layout.php` | Shell + nav + script loader; injects `window.QUICKSITE_CONFIG`. |
| `secure/admin/templates/pages/preview.php` | Preview page composition. |
| `secure/admin/templates/pages/preview-config.php` | Generates `window.PreviewConfig`. Critical boot dependency for all preview modules. |
| `secure/src/classes/WorkflowManager.php` | Workflow loader / validator / prompt renderer / step resolver. |
| `secure/src/classes/CommandRunner.php` | Internal command execution for trusted workflow data needs. |
| `secure/src/classes/CssParser.php` | CSS manipulation backing the style commands. |
