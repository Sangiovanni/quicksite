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
| `pages/ai/ai-settings.js` | AI provider setup, BYOK keys → `/admin/api/ai-providers`. |
| `pages/ai/ai-spec.js` | Workflow execution: resolve → render-prompt → execute → `/admin/api/workflow/*` and `/admin/api/batch/execute`. |

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
| `AI_KEYS_V2` | localStorage / sessionStorage | `ai-spec.js`, `ai-settings.js`, `settings.js` |
| `AI_DEFAULT_PROVIDER` | localStorage / sessionStorage | `ai-spec.js`, `ai-settings.js`, `settings.js` |
| `AI_PERSIST` | localStorage | `ai-spec.js`, `ai-settings.js`, `settings.js` |
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

---

## 9. Other pages — what they do

| Page | What it does |
|---|---|
| **Dashboard** (`dashboard.js`) | Stats cards (route count, language count, recent build), command activity feed, recent history. Calls `help`, `getRoutes`, `getCommandHistory`. |
| **Command** (`command.js`) | Permission-filtered command index. |
| **Command form** (`command-form.js`) | Renders a dynamic form for any command from `help` metadata, then executes it. The escape hatch into raw API. |
| **History** (`history.js`) | Browses `getCommandHistory`; modal with full request/response. |
| **Settings** (`settings.js`) | User profile, language, theme, AI provider config status. |
| **APIs** (`apis.js`) | External API registry: `listApiEndpoints`, `addApiDefinition`, `editApiDefinition`, `deleteApiDefinition`, `testApiEndpoint`. |
| **Assets** (`assets.js`) | Asset browser + uploader: `listAssets`, `uploadAsset`, `editAsset`, `deleteAsset`. |
| **Sitemap** (`sitemap.js`) | Route tree, reachability, ordering. |
| **Embed security** (`embed-security.js`) | `getEmbedSecurity` / `setEmbedSecurity`. |
| **Optimize** (`optimize.js`) | UI for the CSS Refiner library; runs analyzers, presents diffs, applies edits via `editStyles` / `setRootVariables`. |
| **AI workspace** (`pages/ai/*`) | Listing, editing, executing AI workflow specs against `/admin/api/ai-spec/*`, `/admin/api/ai-providers`, `/admin/api/workflow/*`, `/admin/api/batch/execute`. See [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md). |

---

## 10. Risk hotspots

These are the live concerns to keep in mind when touching the panel. They are tracked in detail in `NOTES/planning/ADMIN_JS_DEPENDENCY_GRAPH.md`'s refactor backlog.

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
