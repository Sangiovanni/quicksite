# QuickSite Admin Panel

_Last updated: 2026-06-09._

> Canonical reference for the admin panel's JS architecture, boot flow, and module map. See [ARCHITECTURE.md](ARCHITECTURE.md) for the system-level overview, [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) for the workflow engine, and [COMMAND_API.md](COMMAND_API.md) for the API commands the panel calls.

> _Maintainers note:_ re-check this doc when changing `public/admin/assets/js/core/storage-keys.js` (§6 storage keys), `secure/admin/templates/pages/preview/sidebar-tools.php` (§8.1 visual editor modes), `secure/admin/templates/layout.php` (§2 boot order), `public/admin/assets/js/pages/sitemap.js` / `secure/management/command/addRoute.php` (§9.8 sitemap + param-route authoring), the state-stores wizard's init source kinds in `public/admin/assets/js/pages/preview/preview-js-interactions.js` (§9.6), `secure/src/classes/DataResolver.php` / `secure/src/functions/serverFetch.php` / `secure/src/functions/resolverHelpers.php` / `secure/admin/templates/pages/sitemap-resolver*.php` (§9.7 server-side data resolvers), or the auth verbs in `public/scripts/qs.js` / `secure/src/functions/qsVerbCatalog.php` / `secure/src/classes/JsonToHtmlRenderer.php` `CHAIN_AWAITABLE` (§9.5 Tier 1+2+3 auth flows).

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
| **Select** | Mode | Click an element → inspect node, add a sibling/child, **edit its params + class + mandatory attrs in place** (via the Edit Params button), delete, duplicate, or save as snippet / component. |
| **Drag** | Mode | Drag elements to reorder within parent (`preview-drag.js`). |
| **Text** | Mode | Inline-edit a text node's translation **for the currently selected language**. Intentionally primitive: no rich text, no line breaks. Edits the value, never the key. |
| **CSS** | Mode | Click an element → CSS panel; edits apply to the element's selector with full pseudo-state support. |
| **Interactions** | Mode | Event editor (`preview-js-interactions.js`). Three CRUD-capable panels: **per-element interactions** (the selected node's `onclick`/`oninput`/etc.), **Page Events** (page-level `onload`/`onresize`/`onscroll`), and **State Stores** (page-scoped state). Each interaction is a `{{call:fn:args}}` chain using a QS.* verb from the catalog. |

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
| `form-scaffold` | `<form>` + N field rows + submit button, with `onsubmit` chain pre-wired (validate + fetch + optional post-submit actions) | Headline API-objective integration. Reuses `FieldRow` internally for each field. **Auto-seed from request schema**: when the picked API endpoint declares a `requestSchema.properties`, a `⤓ Pre-fill from request schema` button appears in the API group — click to populate every field row with `{name, type, required, labelKey}` inferred from the schema. Type inference: standard JSON Schema (`boolean` → checkbox; `number` / `integer` → number); QuickSite-permissive aliasing — any HTML5 input name typed directly into `type` (`password` / `email` / `tel` / `url` / `date` / `color` / …) maps to that input type; `format` on a `string` (email / tel / url / date / time / date-time / color / **password**) → matching HTML5 type; `writeOnly: true` → password; property name matches `/password/i` → password (last-resort); else `text`. **Reset behaviour**: with fields already populated, the button label flips to `⤴ Reset to schema fields (clears N)` and prompts for confirmation before the destructive replace — useful after picking the wrong endpoint or to recover from typos. |
| `select` | `<div class="field"><label/><select/><span data-error-for/></div>` with N `<option>` children | Same outer shape as field-row so a select sits alongside text inputs in a form-scaffold and picks up the same QS.validate hook. Optional placeholder option + required + multiple. |
| `list` | `<ul>` or `<ol>` with N `<li><textKey/></li>` children | Simple flat list. `<ol>` supports `start` and `reversed`. |
| `radio-group` | `<fieldset class="field">` + `<legend>` + N (`<label><input type="radio">`) + `<span data-error-for>` | All radios share one `name`. Layout `inline` / `stacked` (adds `field--inline` class). Optional default-selected value (a `<select>` rebuilt from the live option values). |
| `checkbox-group` | Same as radio-group with `type="checkbox"` | Optional `arraySubmit` toggle emits `name="<name>[]"` for PHP-style array submission (error span still uses the bare name). Multi-select default values. |
| `table` | `<table>` + optional `<caption>` + optional `<thead>` + `<tbody>` of M rows × N columns of `<td>` | Spreadsheet paste (TSV/CSV) with hybrid mode radio — **RAW** (`__RAW__`-prefixed literals, same in every language) or **Translatable** (auto-generated keys `table.<id>.head.<col>` + `table.<id>.body.<r>.<c>` written via `setTranslationKeys` for a user-picked language). Empty pasted cells skipped (no orphan keys). When `id` is set the `<table>` is stamped with `data-qs-complex="table"` + `data-qs-complex-id="<id>"` so the **Translate from CSV** workflow (see below) can find it. |
| `definition-list` | `<dl>` with N (`<dt>`, `<dd>`) pairs | One translation key per term and per description. MVP is 1:1 (single `<dt>` per `<dd>`). |
| `accordion` | `<div class="accordion">` with N native `<details>` / `<summary>` disclosure items | No JS or ARIA wiring — browser handles toggling. Per-item `openByDefault` adds the `open` attribute. |
| `nav-menu` | `<nav>` / `<ul>` with N `<li><a href="…">` items | Per-item **external** toggle adds `target="_blank" rel="noopener"`. Each `href` input attaches the shared routes datalist for autocomplete (external URLs still work). |
| `breadcrumb` | `<nav aria-label="Breadcrumb">` / `<ol class="breadcrumb">` with N `<li>` | Last item auto-renders as plain text with `aria-current="page"` (no link); its href input is auto-greyed-out in the wizard. Same routes datalist as nav-menu for intermediate hrefs. |
| `tab-set` | `<div class="tabset" id="<setId>">` + `<div role="tablist">` + N `<button role="tab">` + N `<div role="tabpanel">` | ARIA-correct tab semantics. Click-to-switch wired via existing QS verbs (`removeClass` / `addClass` / `hide` / `show` chain in each tab's `onclick`) — no new runtime needed. User-provided `setId` scopes the per-tab click chain so multiple tab sets coexist on one page. Each panel content is a single `<p>` with a `textKey` — edit further via the regular editor after save. Arrow-key keyboard nav between tabs is filed in BACKLOG. |
| `paged-navigator` | `<nav class="paged-nav" data-state-pagenav="<storeId>" …>` (empty at build time; runtime populates buttons) | Numbered-page navigator bound to a state store. Reads the store's `totalPages` field to size itself, marks the current `page` with `aria-current="page"`. Smart-ellipsis windowing (`1 … 4 5 [6] 7 8 … 100`). Optional ‹ Prev / Next › chevrons. Hides itself when `totalPages` is missing or ≤ 1. Click delegation: one listener attached to the `<nav>` (via WeakSet guard) survives re-renders — calls `setState(storeId, pageField, N)` then `fetchState(storeId)`. Pair with an offset-pagination store (see §9.6). |

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

**Translate from CSV** (currently: Table only)

Complex-element builders that opt in by stamping the
`data-qs-complex="<kind>"` + `data-qs-complex-id="<id>"` attribute pair
on their root node become **translatable in bulk**: select the rendered
element in Select mode, click **Translate from CSV** in the action
toolbar (button auto-shows when the marker is present), paste a CSV
in another language, pick the target language, click Apply. The
`POST /management/importStructureTranslations` command validates that
the existing structure's dimensions match the pasted grid exactly
(returns a 422 with a diff on mismatch — no partial writes) and writes
the values into the picked language's translation file for every key
the element references. The page JSON is **not modified** — this is a
translation-only workflow.

Today only the Table builder stamps the markers. Future complex
elements opt in by emitting the same two attributes on their root and
either reusing the `kind: 'table'` handler in `importStructureTranslations`
or adding a `case 'their-kind':` branch with the equivalent dimension
scanner. See `BETA7_TABLE_TRANSLATION_CSV.md` for the design rationale.

### 8.8 Text authoring (Add → Text tab, inline edit, RAW + key)

Text in a page lives in `{ "textKey": "…" }` nodes. There are two flavours:
a **translation key** (looked up against the language file at render time, e.g.
`home.greeting`) and a **RAW literal** (`__RAW__`-prefixed, rendered verbatim —
for separators / one-off labels like `" / "`, `"—"`). Both are first-class.

**Tag elements add EMPTY.** The legacy auto-generated placeholder textKey on
`addNode` was removed — it used to attach a translation-key child to every new
tag to give zero-dimension elements a clickable footprint, but it produced
span-in-span nesting when authoring bound elements (`data-state-*`, `data-bind`)
and is no longer needed (empty elements get their own editor footprint). To add
text to a tag, use **Add → Text** (below) or **Text mode** inline.

**Add → "Text" tab.** A 5th tab in the Add panel (alongside Snippet / Component /
Complex / HTML Tag). Pick the type:
- **Translation key** — opens a searchable picker (the same
  `QSComplexWizard.createTextKeyPicker` the variables panel uses): type to
  filter existing keys; if your query doesn't match an existing key, an inline
  "Create '<query>'" form appears with a value input. Saving creates the key
  (via `setTranslationKeys`) with the value in the current language (empty in
  others) and the picker carries the new key.
- **RAW (literal)** — a plain value input. Stored as the node's `textKey` =
  `"__RAW__"+value`, rendered verbatim in every language.

The Add button (bottom Cancel / Add — the legacy top quick-add button was
removed) posts `addNode` with `nodeKind:"text"` plus either `textRaw:true + textValue`
(RAW) or the picked `textKey` (key). Default position respects the radio
(`after` / `inside` / `before`).

**Text mode — inline editing of both flavours.** The Text-mode tool clicks any
text node in the preview and makes it `contenteditable`. On commit:
- For a translation-key node, the new value is saved via `setTranslationKeys`
  to the current-language translation file.
- For a `__RAW__`/`__LIT__` literal, the new value is saved via `editStructure`
  by updating the node's `textKey` to `"__RAW__"+value` — *not* a translation
  entry (literals don't have one).

In the renderer (`JsonToHtmlRenderer::renderText`), editor mode now wraps both
key-based AND `__RAW__`/`__LIT__` text in the `data-qs-textkey` selection span
(with a `data-qs-raw="true"` marker on literals). Without that, RAW text had
no selection handle — that's why before this change, pages with literal
separators were unselectable in Text mode.

| Concern | Where |
|---|---|
| Add Text tab markup | `secure/admin/templates/pages/preview/contextual-add.php` |
| Add Text dispatch + picker mount | `public/admin/assets/js/pages/preview/preview.js` (`addTextNode`, `ensureAddTextKeyPicker`) |
| TextKey picker primitive | `public/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js` |
| Inline RAW-vs-key save branch | `preview.js` `handleTextEdited` + `saveRawTextEdit` |
| Renderer editor wrapping | `secure/src/classes/JsonToHtmlRenderer.php` `renderText()` |
| Backend insert | `secure/management/command/addNode.php` (`nodeKind:'text'` mode) |

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
| `callableFrom` | Beta.8 A4 marker — `client` / `server` / `both`, or absent for auto-derive. Server-only endpoints are **filtered out of `qs-api-config.js`** at build emit time (clients literally can't call them). Auto-derive rule: `apiKey → server`; everything else → `both`. Set explicitly in the form via the "Callable from" select; "Auto" shows a live preview of the derived value. |
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

### 9.5 Auth flows — Tier 1 (token persistence), cookie pattern, Tier 2 (refresh on 401), Tier 3 (magic-link)

Token-flow primitives across three shipped tiers (persistence + refresh
+ magic-link) plus the cookie auth type. Tier 3 ships in beta.8 as
**magic-link only**; OAuth follows in beta.9 (the design groundwork is
preserved in `NOTES/planning/BETA8_AUTH_TIER_3.md`). The original
"reserved /auth/* routes" plan was dropped in favour of user-owned
routes guarded by `addRoute`'s conflict-detection rail (beta.8 A1).

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
| `data-auth-show="connecting"` / `"failed"` | (Tier 3, beta.8) show during / after a magic-link exchange. Drives the "Signing you in…" + "invalid or expired link" messages in the `magic-link-handler` component. Driven by `qs:auth:exchange-started` / `qs:auth:exchange-failed` events from `QS.exchangeMagicLink`; cleared on `qs:auth:saved` (success path) or `qs:auth:cleared` (logout). |
| `data-storage-show="has:localStorage:key"` / `"missing:localStorage:key"` | generic presence show/hide for any storage key |
| `data-storage-value="localStorage:key"` | sets the element's text to the stored value |

`data-auth-show` is auth sugar over "is the token present"; the
`data-storage-*` pair is the general form (any key, auth or not).
**Gotcha**: the show/hide elements must be **siblings**, not nested — a
hidden parent hides its children regardless of their own state.

A scaffold workflow that emits this structure correctly, plus a generic
`QS.store` verb (+ `qs:storage:changed` event) for non-auth writes, are
filed in `BACKLOG.md` as the ergonomic follow-up.

#### Tier 3 — Magic-link (beta.8)

Magic-link sign-in: the user types their email, the auth API mails a
single-use code, the user clicks the link, lands on `/auth/magic/<code>`,
the page automatically exchanges the code for a real session token. No
password.

**Why a code, not the token directly.** Putting the actual session token
in the URL leaks via email forwarding, browser history, corporate HTTPS
proxy logs, and mail-client link prefetchers (many prefetch links for
preview thumbnails, "consuming" the token before the user clicks). The
URL value is a single-use code; the page exchanges it for the real token
immediately on page load. See `NOTES/planning/BETA8_AUTH_TIER_3.md` for
the full security analysis.

**The flow** — three phases, four actors:

```
Phase 1 — User requests the link:
  User           Browser            QuickSite site    Auth API
   │ email          │                    │              │
   ├───────────────>│ POST /issue-magic  │              │
   │                │ {email}            │              │
   │                ├───────────────────>│ (proxy)      │
   │                │                    ├─────────────>│ generates "abc123",
   │                │                    │              │ stores, emails user
   │                │<───────────────────│ 200 OK       │
   │ "Check your email"                  │              │

Phase 2 — User clicks the email link (out of band):
  User's inbox                                      User's browser
   │ click magic-link URL                                  │
   ├──────────────────────────────────────────────────────>│ navigates

Phase 3 — Page loads, exchange runs:
  Browser              QuickSite site         Auth API
   │ GET /auth/magic/abc123 │                    │
   ├───────────────────────>│ A1 matches /auth/magic/:key
   │<───────────────────────│ renders page (with magic-link-handler component)
   │ onload chain fires:    │                    │
   │ POST /exchange-magic   │                    │
   │ {key:"abc123"}         │                    │
   ├──────────────────────────────────────────────>│ looks up code,
   │                        │                    │ marks USED, issues tokens
   │<──────────────────────────────────────────────│
   │ saveToken token        │                    │
   │ saveToken refreshToken │                    │
   │ ✓ logged in            │                    │
   │ (optional redirect)    │                    │
```

**The verb family** (qs.js — added beta.8 A3):

| Verb | Purpose | Typical chain |
|---|---|---|
| `QS.exchangeMagicLink(endpoint, paramName, returnTo?)` | Landing-page exchange. Reads `QS.routeParams[paramName]` (populated by the beta.8 A1 path matcher — see [ARCHITECTURE §5.3](ARCHITECTURE.md)), POSTs `{key:<code>}`, stores response in `QS._lastFetchResult`. Dispatches `qs:auth:exchange-started` before fetch + `qs:auth:exchange-failed` in catch so the `magic-link-handler` component's `data-auth-show="connecting"` / `"failed"` UI morphs. | `exchangeMagicLink` → `saveToken` × 2 → `redirect` |
| `QS.requestMagicLink(endpoint, email, returnTo?)` | Forward path. POSTs `{email}` to the issue endpoint. `email` accepts a literal address OR a `#selector` / `.selector` to read from an `<input>` (same convention as `setState`'s value arg). | `validate` → `requestMagicLink` → optional `redirect` to "check your email" page |
| `QS.logoutServer(endpoint)` | Server-side logout — POST so the auth API can invalidate the session / revoke the refresh token. Thin wrapper over `QS.fetch` so registry bearer auth is applied. Errors are intentionally swallowed (the user wants out either way). | `logoutServer` BEFORE `clearToken` × 2 → `redirect` |

All three are in `CHAIN_AWAITABLE`, so chains that include them are wrapped in `(async()=>{await ...})()` — subsequent saveToken / clearToken / redirect see resolved state.

##### The `magic-link-handler` component

QuickSite-provided 3-paragraph template. Copy into your project at
`secure/projects/<your-project>/templates/model/json/components/magic-link-handler.json`:

```json
{
    "tag": "div",
    "params": {
        "class": "magic-link-handler",
        "data-auth-source": "localStorage:authToken"
    },
    "children": [
        {"tag": "p", "params": {"data-auth-show": "connecting"}, "children": [{"textKey": "auth.connecting"}]},
        {"tag": "p", "params": {"data-auth-show": "in"},         "children": [{"textKey": "auth.welcome"}]},
        {"tag": "p", "params": {"data-auth-show": "failed"},     "children": [{"textKey": "auth.failed"}]}
    ]
}
```

Then add it to your `/auth/magic/:key` page via the visual editor (Add → Component → magic-link-handler).

**Adjust `data-auth-source`** so the storage location matches the key your `saveToken` chain writes to. The canonical template uses `localStorage:authToken`; if your chain writes to `localStorage:token` instead, change the wrapper attribute accordingly.

##### Translation strings

Add the three keys to your project's `translate/<lang>.json` files:

**EN** (`translate/en.json`):
```json
"auth": {
    "connecting": "Signing you in…",
    "welcome": "Welcome back!",
    "failed": "This link is invalid or expired."
}
```

**FR** (`translate/fr.json`):
```json
"auth": {
    "connecting": "Connexion en cours…",
    "welcome": "Bon retour !",
    "failed": "Ce lien est invalide ou a expiré."
}
```

Any additional languages your project ships need the same shape.

##### What you need to wire up — checklist

1. **Auth API endpoints** — your auth backend serves an `issue-magic` endpoint (POSTs `{email}`, mails the link, always returns 200), an `exchange-magic` endpoint (POSTs `{key}`, returns `{token, refreshToken}` on success or 401), and optionally a `logout` endpoint (POST with bearer, returns 200).
2. **Register the endpoints in `/admin/apis`** — typically as `@auth-api/issue-magic`, `@auth-api/exchange-magic`, `@auth-api/logout`. Set `auth: 'none'` on the issue + exchange endpoints (no token to send pre-login). Set the API-level `tokenSource` to the same key your `saveToken` chain will write to.
3. **Author the route** in `/admin/sitemap` — e.g. `/auth/magic/:key` (the `:key` is the path-param segment that captures the code).
4. **Drop the component JSON** into your project's `components/` dir (canonical above), then add it to the route's page via the visual editor.
5. **Add the onload page-event chain** — via the JS-mode page-events panel on that route. Example for the test project:
   ```
   {{call:exchangeMagicLink:@auth-api/exchange-magic,key}};
   {{call:saveToken:localStorage,authToken,token}};
   {{call:saveToken:localStorage,refreshToken,refreshToken}};
   {{call:redirect:/dashboard}}
   ```
6. **Add the 3 translation keys** to every active language's `translate/<lang>.json`.
7. **(Optional)** For the forward path, build a small email-entry form on a public page using `QS.requestMagicLink`:
   ```html
   <form>
     <input id="email-input" type="email" required>
     <button type="submit"
             onclick="{{call:validate:event,event}};{{call:requestMagicLink:@auth-api/issue-magic,#email-input,/check-email}}">
       Email me a sign-in link
     </button>
   </form>
   ```

##### Limitations / gotchas

- **No built-in `/auth/*` reservation** — you author the routes yourself. The conflict-detection in `addRoute` (beta.8 A1) is the safety rail; conflicting param routes warn at create time.
- **Auto-redirect interplay** — `exchangeMagicLink`'s `returnTo` arg queues navigation IMMEDIATELY on success. Chained `saveToken` calls still run before the browser processes the navigation (they're sync, in the same microtask), but chained verbs that are themselves async would race. Keep auth-side state changes in synchronous `saveToken` calls; don't chain another async verb between exchange and redirect.
- **Email enumeration oracle** — your `requestMagicLink` server-side endpoint should ALWAYS return 200, even for unknown emails. Don't let attackers probe your user list. Auth-API responsibility, not the verb's.
- **OAuth deferred to beta.9** — magic-link covers the common "passwordless email login" case. OAuth (Google / Meta / Amazon / etc.) ships in beta.9 with the same `data-auth-show="connecting"` / `"failed"` UI primitives, a parallel verb family, and per-provider preset configs.

| Concern | Where |
|---|---|
| Runtime verbs | `public/scripts/qs.js` (search for `QS.exchangeMagicLink`, `QS.requestMagicLink`, `QS.logoutServer`) |
| Catalog metadata | `secure/src/functions/qsVerbCatalog.php` (added beta.8 A3) |
| Async-chain wrapping | `secure/src/classes/JsonToHtmlRenderer.php` `CHAIN_AWAITABLE` |
| Lifecycle events | `qs:auth:exchange-started` / `qs:auth:exchange-failed` on `document`; cleared by `qs:auth:saved` / `qs:auth:cleared` |
| Design groundwork | `NOTES/planning/BETA8_AUTH_TIER_3.md` |

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
- **`init` source kind** — a `<select>` (literal · URL query · URL path param ·
  localStorage · sessionStorage) paired with a key input whose placeholder
  swaps to match the kind ("value" for literal, "URL param name" for query,
  "param name" for URL path param, "key name" for the storage kinds). On save
  the wizard composes the canonical storage string (`literal "Hello"` →
  `init: "Hello"`; `localStorage "authToken"` → `init: "localStorage:authToken"`;
  `URL path param "slug"` → `init: "param:slug"`; etc.). On edit it parses the
  stored string back into the pair, so existing `state-stores.json` files
  round-trip unchanged. The **URL path param** kind reads from `QS.routeParams`
  (populated by qs.js's client-side path matcher — see ARCHITECTURE §5.3),
  closing the URL → live data loop: a field with `init: 'param:slug'` on a
  `/products/:slug` page starts with the captured slug. Missing on a static
  route → silent fallback to `default`, matching `query:` semantics.
- **Auto-seed** — picking an endpoint that declares request/response schemas
  pre-fills the fields (request properties → `request`, response leaves →
  `response`, a name in both → `both`). It seeds only when no field has been entered
  yet (never clobbers) and is a no-op when the endpoint has no schema — a
  non-binding, fully editable starting point.

**Import from another page.** Next to **New store** there's an **Import**
button that clones an existing store from a different route into the current
page. Click → inline picker lists every other-route store as `<route> ▸
<storeId>`. Pick → if the storeId already exists on this page, an inline
rename input appears (seeded with `<id>_copy`); otherwise Import is enabled
immediately. The import is an **independent duplicate** (deep-clone of the
def via JSON round-trip); future edits to the source don't propagate. The
live-shared cross-page store variant — one store referenced from multiple
pages — is intentionally out of scope (touches the runtime emit + sidecar
schema + lifecycle questions; deferred).

**Triggering.** `setState` and `fetchState` appear in the interaction picker's
**Function** dropdown (both the element form and the page-event form). Their `store`
argument is a dropdown of the page's stores; `setState` adds `field` and `value` (a
literal, or a `#id` / `.class` selector read live). They compile to
`{{call:setState:store,field,value}}` / `{{call:fetchState:store}}` — chain them for
"go to page N", search-as-you-type, or scroll-to-load-more.

**Rendering.** The store updates elements carrying these bindings on every
init / `setState` / `fetchState`:

- `data-state-value="store.field"` — scalar; sets `textContent` to the field
  value.
- `data-state-list="store.field"` — array container. The container's first child
  is the **item template** that gets cloned per array item.
  - Template descendants with `data-bind="fieldName"` get `textContent` set to
    the matching field on each item; `data-bind-attr="attr"` sets an attribute
    instead. *This is the same `data-bind` mechanism used by componentList
    bindings — see §9.2 for the full template-population reference.*
  - An optional `data-state-empty="some text"` on the container provides the
    text shown when the array is empty (replaces the empty container content
    with that string).
- `data-state-show="store.field"` — toggles the standard `hidden` attribute
  on truthiness (falsy = `null` / `undefined` / `''` / `0` / `false` / `[]`).
  Handy for hiding Next at the last page, "Load more" when `hasMore` is
  false, etc.
- `data-state-pagenav="storeId"` (+ optional `-page-field` / `-totalpages-field`
  / `-window` / `-prev-next` companion attributes — see `ARCHITECTURE.md §8`)
  — runtime-rendered numbered-page navigator. Emitted by the `paged-navigator`
  complex element wizard; hand-author the bindings if you want a custom shape.

These bindings are authored in the page structure: the State-stores wizard
defines the *data*, not the render target.

| Concern | Where |
|---|---|
| Panel + wizard markup | `secure/admin/templates/pages/preview/contextual-js.php` |
| Panel + wizard logic | `public/admin/assets/js/pages/preview/preview-js-interactions.js` (State Stores section) |
| Verbs in the picker | `listJsFunctions` (`setState` / `fetchState`, `store` inputType) |
| Read / write | `getStateStores` / `setStateStores` → `StateStoreManager` |

#### Pagination patterns (state-store configs, no extra runtime)

Pagination is **configuration of a State store**, not a separate engine. The
common patterns:

**Offset (replace, with prev / next / numbered buttons)** — endpoint returns
`page` / `limit` (request echoes), `total` / `totalPages`, and `prevPage` /
`nextPage` (integers, null at the ends). Store fields:

| name | dir | init | default | from | append |
|---|---|---|---|---|---|
| `page` | request | `query:page` | `1` | — | — |
| `limit` | request | — | `10` | — | — |
| `q` | request | — | — | — | — |
| `total` / `totalPages` | response | — | — | echo | — |
| `nextPage` / `prevPage` | response | — | — | echo | — |
| `items` | response | — | — | `items` | ☐ off (replace) |

The Next/Prev buttons read the rendered cursor via a hidden span — no client-
side increment needed (`setState` can't add 1, but reading `#np`'s textContent
works):

```html
<span hidden id="np" data-state-value="people.nextPage"></span>
<span hidden id="pp" data-state-value="people.prevPage"></span>
<button data-state-show="people.prevPage"
        onclick="{{call:setState:people,page,#pp}} {{call:fetchState:people}}">Prev</button>
<button data-state-show="people.nextPage"
        onclick="{{call:setState:people,page,#np}} {{call:fetchState:people}}">Next</button>
```

`data-state-show` on Prev/Next gates them on the cursors so the buttons disappear
at the ends.

For a **numbered navigator** (1 2 3 … N from `totalPages`) — add a
**paged-navigator** complex element via the Add → Complex tab. Pick the store
above, leave `page` / `totalPages` as the field names, set window size + Prev/Next
toggle. The navigator hides itself until the first fetch settles, then renders +
re-renders on every store update with smart-ellipsis layout.

**Cursor / infinite (append, on scroll or load-more)** — endpoint returns a
forward cursor (`nextId` / `nextCursor`). Store fields:

| name | dir | init | default | from | append |
|---|---|---|---|---|---|
| `startId` (or cursor) | both | `query:startId` | `0` | `nextId` | — |
| `count` | request | — | `20` | — | — |
| `items` | response | — | — | `items` | ☑ ON (grow) |

Trigger via a page event in JS mode → Page Events → **Add Page Event** →
`onload` → `onScrollFetchState:list,200,100`. That registers (once) a debounced
window-scroll listener that fires `fetchState(list)` only when the viewport is
within 200px of the page bottom (debounced 100ms), and **stops** firing once
the store is exhausted (HTTP error, response items empty, or a `both` cursor
that didn't advance). A subsequent `setState` (e.g. new search query) clears
the exhausted flag and re-arms the trigger.

> Don't wire a raw `onscroll` → `fetchState` page event for infinite scroll:
> the runtime fires the handler on every scroll tick (many per second) and the
> store has no built-in stopping condition there — it will thrash the API and
> loop past end-of-list. `onScrollFetchState` is the safe equivalent.

Both patterns are demonstrated in the test pages `test/paged` (offset) and
`test/state` (cursor) — see `BETA7_ORDER.md` for the full beta.7 context.

### 9.7 Server-side data resolvers

A **resolver** is a per-route declaration that fires a server-side fetch
**before** the page renders, and exposes the response as template
variables. The initial HTML carries the API content already populated —
search engines and AI crawlers see real text on the first byte, not an
empty shell waiting for JS. State stores (§9.6) still handle the
*client-side, post-load* path (search, paginate, refresh); the resolver
is the *server-side, first-paint* path. Same JSON-style declaration,
two executors.

A route may have **one** resolver (the common case) or **multiple**
resolvers firing in parallel (for pages that need data from several
endpoints — comparison pages, multi-source mashups, content + metadata
splits). Multi-resolver storage + execution + UI shipped in beta.8
Slice 7.5.

| Concern | Where |
|---|---|
| Storage (per project, keyed by route) | `secure/projects/<project>/data/route-resolvers.json` |
| Server-side execution | `secure/src/classes/DataResolver.php` → `resolveMany()` |
| Server-side fetch (single + parallel) | `secure/src/functions/serverFetch.php` → `serverFetch()` / `serverFetchMulti()` |
| Storage + validation helpers | `secure/src/functions/resolverHelpers.php` |
| Cache layer | `secure/src/functions/resolverCache.php` + `cleanResolverCache` command |
| Read / write commands | `setRouteResolver` (set / clear / patch / append / remove single slot) |
| Page emit — flat namespace | `secure/src/classes/Page.php` (templates read via `{{resolved:NAME}}` substitution) |
| Page emit — `r0`/`r1` namespaced | Same, plus `secure/src/classes/PageManagement.php` for the JS-side mirror |
| Hydration handoff to client | `secure/src/classes/PageManagement.php` → `window.QS_RESOLVED` + `window.QS_RESOLVED_BY_INDEX` |
| Admin UI — list view + per-config modal | `/admin/sitemap` → context menu ⋯ → "Configure resolver" — partials `sitemap-resolver-list.php` + `sitemap-resolver.php`; logic in `public/admin/assets/js/pages/sitemap.js` |

#### Sidecar shape

`route-resolvers.json` is a route-keyed object. Each value is either a
**single config** (object) or an **array of configs**:

```json
{
    "products/:slug": {
        "endpoint": "@products-api/get-product",
        "inputs":   { "id": "param:slug" },
        "expose":   { "product": "data.product" },
        "cacheTTL": 300
    },
    "compare/:a/vs/:b": [
        {
            "endpoint": "@products-api/get-product",
            "inputs":   { "id": "param:a" },
            "expose":   { "productA": "data.product" },
            "cacheTTL": 300
        },
        {
            "endpoint": "@products-api/get-product",
            "inputs":   { "id": "param:b" },
            "expose":   { "productB": "data.product" },
            "cacheTTL": 300
        }
    ]
}
```

Backward compatibility: single-resolver routes written before
beta.8 Slice 7.5 stay scalar; the runtime accepts both shapes via
`getResolversForRoute()`, which always returns a normalised array.
Write path picks the shape per length — scalar when one config, array
when two or more.

#### Config field reference

| Field | Required | Type | Notes |
|---|---|---|---|
| `endpoint` | yes | string | `@apiId/endpointId` reference (matches the API registry from §9.1). Must be `callableFrom: server` or `both` — `client`-only endpoints are rejected at save time + at runtime. |
| `inputs` | no | `{name: spec}` | Map of endpoint parameter name → source spec (see below). Omit when the endpoint takes no inputs. |
| `expose` | no | `{varName: dotPath}` | Map of template variable name → response dot-path. Omit when the resolver's only purpose is a side-effect (rare). |
| `cacheTTL` | no | integer (seconds) | 0 or omitted = no cache. Positive = cache window. **Cache is force-disabled by the server for `bearer` / `cookie` / `basic` auth regardless of TTL** — see auth-cacheable rule below. |
| `onMiss` | no | string | `render-empty` to fall back to null-valued exposes on failure (page still renders). Omitted = fail-loud (page short-circuits to 404 / 500). Future values reserved. |

#### Inputs — source specs

The same prefix convention as state-store `init` (§9.6) — one
authoring vocabulary, two consumers:

```
"id"     => "param:slug"       // URL path param (TrimParameters routeParams)
"lang"   => "query:locale"     // URL query string
"userId" => "session:userId"   // server-side session (depends on Tier 3 wiring)
"tag"    => "featured"         // bare literal (no recognised prefix)
```

Missing sources resolve to `null` and get dropped from the request
entirely (the upstream's default applies), instead of being sent as an
empty placeholder. Path placeholders that don't resolve are left as
the literal `:name` in the URL so the upstream 404 surfaces the
misconfig visibly.

#### Expose — response dot-paths

Each entry maps a template variable name to a dot-path through the
response JSON. `data.product.name` walks `$response['data']['product']
['name']`. An **empty string path** is shorthand for "expose the whole
response":

```json
"expose": {
    "product":   "data.product",
    "fullPayload": ""
}
```

Templates substitute via `{{resolved:NAME}}` for flat names and
`{{resolved:NAME.path.through.value}}` for further dot-path traversal
into the exposed shape. PHP code can also read the values directly
through `getResolvedVars()` from `secure/src/functions/resolverHelpers.php`.

When the **endpoint declares a `responseSchema`**, the expose editor's
dot-path field is a `<select>` of the enumerated paths (with an orphan
warning option if the saved path isn't in the schema). When the
endpoint has no schema, the field is a plain `<input>` with a
free-text fallback and a warning hint suggesting the author add a
schema in `/admin/apis` for autocomplete-driven authoring.

#### Cache — TTL + auth gating

The cache is keyed by `sha256(endpoint + canonicalised inputs)` — it
is **route-agnostic**. Two routes hitting the same endpoint + inputs
share the cached response. A route's `resolver` declaration is a
*trigger* (fires the resolver when this route is requested), not a
*cache scope*.

The **auth-cacheable rule** (locked in `BETA8_DATA_RESOLVER.md`
Slice 4):

| Auth type | Cacheable? | Reason |
|---|---|---|
| `none` | yes | No per-user identity in the request — shared response is safe. |
| `apiKey` | yes | Server-side shared secret, identical for every caller. |
| `bearer` | **no** | Per-user token. Sharing the cache would leak one user's data to others. Server forces no-cache regardless of TTL. |
| `cookie` | **no** | Same: per-user session. |
| `basic` | **no** | Same: per-user credentials. |

The Configure-resolver modal surfaces this rule live: a **green
"cacheable"** badge next to the TTL field when the endpoint's effective
auth is `none` / `apiKey`, a **warning "disabled for &lt;auth-type&gt;"** badge
otherwise. When disabled, the TTL input is also greyed out — the typed
value would be silently ignored by the server, so the form makes the
constraint visible.

Cache writes happen only on **2xx** responses. 4xx / 5xx / transport
failures aren't cached, so a transient blip doesn't lock in a "not
found" for the full TTL window. The `cleanResolverCache` command
clears entries (by endpoint id, API id, expired-only, or all) — useful
for development and for the auto-clear hook `editApi` runs when an
endpoint config changes.

**Cache observability** — every document response carries an
`X-QS-Resolver-Cache` header with one of `hit` / `miss` / `skip` /
`disabled` per resolver, comma-separated for multi-resolver routes
(`hit,miss,disabled`). DevTools Network tab shows the resolver's
cache state without needing to tail logs.

#### `onMiss` — failure-mode fallback

Per-resolver, locked values:

| Value | Behaviour |
|---|---|
| (absent, "fail-loud") | A failure short-circuits the page — see failure-mode table. |
| `render-empty` | Failure exposes the resolver's expose keys as **null**; page renders with null vars; template uses `data-state-show-empty` (§10) for graceful "no data" UI. |

Future values (e.g. `redirect:<url>`) are reserved.

#### Multi-resolver — parallel execution + namespaced access

When a route has more than one resolver, `DataResolver::resolveMany()`
fires them all concurrently via `curl_multi_*` — total latency =
max(individuals), not sum. Each resolver gets its **own** cache lookup
(two resolvers within a route hitting the same endpoint + inputs
share the cache entry — same key-derivation rule applies).

**Exposed vars merge into the flat namespace** (`{{resolved:NAME}}`).
That merge is **collision-free**: `setRouteResolver` rejects at save
time any save where two resolvers expose the same key name to the
flat namespace.

```json
// REJECTED with reason: collision
[
    { "endpoint": "@books-api/get-book",    "expose": { "title": "data.title" } },
    { "endpoint": "@books-api/get-chapter", "expose": { "title": "data.title" } }
]
```

Authors disambiguate by **renaming** (`{ "bookTitle": "data.title" }`
on one, `{ "chapterTitle": "data.title" }` on the other) OR by using
the **namespaced-by-index** form, which is always available regardless
of flat collisions:

| Layer | Flat access | Namespaced access |
|---|---|---|
| PHP — template substitution | `{{resolved:bookTitle}}` | `{{resolved:r0.title}}` |
| PHP — array | `$vars['bookTitle']` | `$vars['r0']['title']` |
| JS — `window.QS_RESOLVED` | (store-keyed, not flat) | — |
| JS — `window.QS_RESOLVED_BY_INDEX` | — | `.r0.title` |

`r0` / `r1` / ... follow the resolver's position in the array. The
namespaced form is also emitted for single-resolver routes (`r0` with
the same data the template sees as flat), so authors who switch a
route between single and multi don't have to rewrite anything.

**Reorder is cache-safe.** The cache key is endpoint + inputs, not
position. Drag-handle reorder in the list view changes the `r0` / `r1`
addressing (and the render-time execution order) but never invalidates
cache entries.

#### Hydration handoff — `window.QS_RESOLVED` + `window.QS_RESOLVED_BY_INDEX`

Two globals get embedded in the rendered page when applicable:

```html
<script>
window.QS_RESOLVED = {
    "authProbe": { "sessionToken": "tok_...", "userEmail": "..." }
};
window.QS_RESOLVED_BY_INDEX = {
    "r0": { "sessionToken": "tok_...", "userEmail": "...", "wholeResponse": {…} },
    "r1": { "items": [...], "total": 97, "hasMore": true }
};
</script>
```

- `QS_RESOLVED` is **store-keyed** for state-store skip-fetch
  hydration. When a state store (§9.6) on the route is bound to an
  endpoint that *any* resolver on the route also called, the store's
  matching `response` / `both` fields seed from the resolver's
  exposed values. `qs.js`'s `_initStores` reads this AND skips the
  initial `fetchOnLoad`; the data is already in the DOM, no
  duplicate round-trip. Subsequent `fetchState` calls (search,
  paginate, refresh) work normally.
- `QS_RESOLVED_BY_INDEX` is **resolver-index-keyed**, mirroring the
  PHP-side `$r0` / `$r1` namespace for client-side code that wants
  explicit per-resolver addressing.

Editor mode skips both — emulation drives the preview's resolved
values, not the production resolver.

#### Failure modes — what makes the page 200 / 404 / 500

`onMiss` operates per-resolver. With multi-resolver routes, **the
strictest unrecovered failure wins**: any single resolver that fails
without `render-empty` short-circuits the whole page.

| Single-resolver outcome | Multi-resolver outcome | Page result |
|---|---|---|
| Resolver succeeds | All resolvers succeed | **200** — template renders with vars populated |
| Resolver fails + `onMiss: render-empty` + upstream 4xx/5xx | At least one resolver fails this way; all other failures are also `render-empty` | **200** — template renders with null vars for the failed resolver(s); template's `data-state-show-empty` (§10) drives the "no data" UI |
| Resolver fails + no `onMiss` + upstream **4xx** | Any resolver fails this way | **404** — render the project's `templates/pages/404.php` (the slug doesn't exist; matches the `/products/red-vase` not-found pattern) |
| Resolver fails + no `onMiss` + upstream **5xx or curl error** | Any resolver fails this way | **500** — render the project's `templates/pages/500.php` (the upstream is down; the page can't render meaningfully) |
| Resolver fails + **config bug** (endpoint missing from registry, `apiKey` not set, `callableFrom: client` server-side call, etc.) | Any resolver fails this way | **500** — currently a verbose inline page surfacing the misconfig (route, error message, fix hint). Loud dev signal; **filed for dev-vs-prod presentation polish** before tag — see backlog. |

`$GLOBALS['__qs_resolver_failure']` is populated for `404` / `500`
template branches with `{ route, status, error }` so the template can
log or surface the cause. The multi-resolver case carries the
**first unrecovered failure's** details (`resolverIndex` included) —
the short-circuit policy means subsequent failures aren't reached.

#### Editor emulation panel

`?_editor=1` activates emulation: the production resolver is **not
fired**, and routeParams + resolved vars come from a base64-encoded
`_emulate` JSON payload in the URL. The visual editor's emulation
panel (extended from the param-route emulation) provides:

- **Inputs panel** — every route `:name` segment + every resolver
  expose key, editable as text inputs. Schema-driven defaults
  (Track 2d) pre-fill the expose inputs from the endpoint's
  `responseSchema` when one is declared.
- **Live-data toggle** (`?_live=1`) — runs the REAL resolver instead
  of the emulation. Useful for validating the page against real API
  responses while still using the editor's emulated routeParams.

#### Admin authoring — list view + per-config modal

Sitemap → context menu ⋯ → "Configure resolver" opens the **list
view** modal (`sitemap-resolver-list.php`). Single-resolver routes
show with one entry; multi-resolver routes show all entries; routes
without a resolver show an empty state with "+ Add resolver".

| Action | Effect |
|---|---|
| Per-row Edit | Opens the per-config modal (`sitemap-resolver.php`) scoped to that index. Save patches that slot via `setRouteResolver { route, resolver, index: N }`. Cancel / X / backdrop return to the list view. |
| Per-row × Remove | Confirms (with stronger wording on last-resolver removal), POSTs `setRouteResolver { route, index: N }` (no `resolver` body = remove-at-index). |
| Drag handle ⋮⋮ | Drag-and-drop reorder. Applies immediately via `setRouteResolver { route, resolver: [reorderedArray] }` — no separate "Save order" button. |
| + Add resolver | Opens the per-config modal in append mode (`editIndex = current length`). Save inserts at end. |

The per-config modal's sections (endpoint picker, inputs editor,
expose editor, cacheTTL, onMiss) all carry live UX scaffolds —
endpoint picker filters out `client`-only endpoints + surfaces
orphans; inputs name picker uses the endpoint's declared `parameters`
when present (free-text fallback otherwise); expose path picker uses
the `responseSchema` when present (free-text fallback otherwise);
cacheTTL input disables when auth isn't cacheable. Save validation
runs both client-side (per-row + cross-row collision) and server-side
(per-config + cross-resolver collision detection in `validateResolverConfigs`).

The sitemap badge on resolver-bound routes shows `resolver` (single)
or `resolver × N` (multi). Tooltip lists each endpoint comma-
separated.

---

### 9.8 Sitemap (/admin/sitemap)

Visual route tree, reachability analyzer, layout toggles, and `sitemap.txt`
generator — all backed by `getSiteMap` + `addRoute` / `deleteRoute` /
`editRoute` + `setRouteLayout` + `analyzeReachability`. The runtime + matching
algorithm live in [ARCHITECTURE §5.3](ARCHITECTURE.md); this section covers
the editor UX.

**Tree**. One row per route, hierarchical. Each row carries:

- **Name** (left) — the leaf segment, monospaced.
- **Path** (right) — the full path, muted by default. Param routes render
  their `:name` segments in the project's accent color, with a small
  `[N param(s)]` chip appended. Static routes show no chip.
- **Layout toggles** — two icons that flip the route's visibility in the
  global **menu** / **footer** (calls `setRouteLayout`). Optimistic UI;
  the reachability section re-renders after each toggle since visibility
  affects which routes count as reachable.
- **Actions** (hover-revealed) — `+` to add a child, `⋯` to open the
  context menu (Add child / View in editor / Open page / Edit title /
  Delete route, with Delete greyed for the home route).

The tree-toggle chevron expands / collapses parent nodes. Expanded state is
remembered locally so re-renders (after add / delete / layout toggle) keep
their shape.

**Inline Add Route form**. The `+ Add root route` button (or the per-row
add-child action) opens a one-line form right where it'll land in the tree.
Each segment of the name is validated client-side against the same rules
the server enforces in `addRoute.php`:

- **Literal segment** — lowercase letters / digits / hyphens (no leading or
  trailing hyphen).
- **Param segment** — a `:` prefix followed by a lowercase identifier
  (starts with a letter or underscore, then letters / digits / underscores).

Separators are `/` for nested paths. An invalid segment surfaces inline
("Invalid segment `:UPPER`…") without hitting the API.

**Conflict warnings**. When the server accepts the route but flags it as
ambiguous (param at a level with exact siblings, or two params at the same
depth), the success toast is followed by one warning toast per entry in the
response's `warnings` array. The matching rule still picks a winner at
runtime (more literal segments wins; declaration order breaks ties — see
ARCHITECTURE §5.3), so the toast is a "did you mean this?" surface rather
than a block.

**Edit title**. The context menu's `Edit title` opens a modal with one
input per active language pre-filled from `page.titles.<route>` (via
`getTranslation`). Save dispatches `setTranslationKeys` once per changed
language and re-fetches on the next open.

**Reachability**. Below the tree, the reachability section runs
`analyzeReachability`: total / reachable / orphan counts, an orphans list
with a hint, the global menu + footer links, and a per-route link graph
table. The banner turns red when any orphan is detected.

**Sitemap.txt generator**. At the bottom: a base-URL input + Preview
button calls `getSiteMap` with the base, lists every route's URLs (with
×N langs counts when multilingual), and lets the user toggle rows to
exclude. A custom-URLs textarea takes one URL per line (for pages outside
the route system). Save writes the file via `getSiteMap` + `save: true`.

| Concern | Where |
|---|---|
| Tree + reachability + add form logic | `public/admin/assets/js/pages/sitemap.js` |
| Page shell | `secure/admin/templates/pages/sitemap.php` + `sitemap-edit-title.php` partial |
| Server commands | `addRoute`, `deleteRoute`, `editRoute`, `getSiteMap`, `setRouteLayout`, `analyzeReachability` |
| Route schema | A project's `secure/management/routes.php` (writable via the commands; `varExportNested()` preserves nested string keys) |
| Param syntax helpers | `secure/src/functions/routeHelpers.php` — single source for the `:name` ↔ `__name` filesystem sanitisation (NTFS reserves `:`) |

---

## 10. Data attribute reference

QuickSite's runtime understands a small set of `data-*` attributes that
turn plain HTML elements into bindings (state-store readers, auth-state
toggles, storage value displays, complex-element markers, …). Every
attribute on this page is **catalogued in
`secure/src/functions/qsDataAttributeCatalog.php`** — the single source
of truth read by `GET /management/listDataBindings` and (late beta.7+)
by the in-editor autocomplete + smart widgets, available on **both** the
Add Element wizard's Advanced custom-params section AND the Edit Params
surface (action panel button next to Add / Duplicate). The same picker —
autocomplete dropdown, per-attribute description box, smart widgets
(store/field cascader, enum select, storage-spec composer), reserved-
storage-key blocking — applies whether you're authoring a new element OR
editing an existing one's bindings.

This section is the task-oriented index for those attributes. For
per-feature deep-dives (semantics, edge cases, full examples), see the
existing feature sections — each table row links to its canonical
deep-dive.

### 10.1 Scannable reference

| Attribute | Category | Value shape | What it does | Deep dive |
|---|---|---|---|---|
| `data-state-value` | state | `storeId.field` | Element text = scalar field of a state store | §9.6 |
| `data-state-list` | state | `storeId.field` | Container becomes a list — first child is the per-item template | §9.6 |
| `data-state-empty` | state | text | Text shown when `data-state-list`'s array is empty | §9.6 |
| `data-state-show` | state | `storeId.field` | Toggles `hidden` on truthiness — gate Next/Prev on cursors, "Load more" on hasMore, etc. | §9.6 |
| `data-state-pagenav` | state | `storeId` | Runtime-rendered numbered-page navigator. Companion attrs: `-page-field`, `-totalpages-field`, `-window`, `-prev-next` | §8.7 (paged-navigator) |
| `data-auth-show` | auth | `in` / `out` | Show only when logged in / out. Needs `data-auth-source` on element or ancestor | §9.5 |
| `data-auth-source` | auth | `localStorage:key` | Where the token lives for `data-auth-show` resolution | §9.5 |
| `data-storage-show` | storage | `has:loc:key` / `missing:loc:key` | Generic show/hide on any storage key presence | §9.5 |
| `data-storage-value` | storage | `localStorage:key` | Element text = the stored value | §9.5 |
| `data-bind` | template | field name | Per-item template field (inside `data-state-list` OR componentList) | §9.2 |
| `data-bind-attr` | template | attribute name | Variant of `data-bind` — sets the named attribute instead of textContent | §9.2 |
| `data-list-template` | template | `true` | Marks a hidden element as the per-item template for componentList | §9.2 |
| `data-error-for` | form | field `name` | Container for `QS.validate` error messages — set value to the input's `name` | §8.7 (field-row / form-scaffold) |
| `data-qs-complex` | complex | `table` (today) | Marks a complex-element subtree root — enables Translate-from-CSV | §8.7 (Translate from CSV) |
| `data-qs-complex-id` | complex | identifier | Companion to `data-qs-complex` — identifies the structure for cross-language lookup | §8.7 |

**Editor-only chrome** (auto-emitted by the renderer in editor mode;
users should NOT author these): `data-qs-textkey`, `data-qs-raw`,
`data-qs-textonly`, `data-qs-node`, `data-qs-struct`. The picker
hides these by default. Pass `GET /management/listDataBindings/all`
to see them.

### 10.2 How to author these — admin-panel click paths

Three common scenarios, end-to-end. Each is reachable from the visual
editor with no JSON editing.

#### Scenario A — Hide a Next button at the last page (`data-state-show`)

```
Admin Panel → Visual Editor → Select mode (cursor icon in sidebar)
  → Click the "Next" button in the iframe to select it
  → Sidebar action panel → Advanced → "+ Add custom param"
  → KEY field: type "data-"
    Autocomplete dropdown opens, grouped by category. Pick data-state-show.
    Description appears above the value field:
      data-state-show — toggles the standard hidden attribute on
      truthiness. Use to gate Prev/Next on cursors, Load-more on
      hasMore, counters on total > 0.
  → VALUE field: type "people.nextPage" (or pick from the smart widget
    when slice-5 ships — store picker → field picker)
  → Save → Button hides automatically when people.nextPage is null
```

#### Scenario B — Logout button visible only when logged in (`data-auth-show`)

```
Admin Panel → Visual Editor → Select mode
  → Click empty area in the page to add at root → "+ Add"
  → Add Element → HTML Tag tab → tag = button, text = "Logout"
  → Advanced → "+ Add custom param"
  → type "data-" → pick data-auth-show
    Description: "show only when logged in (in) or out (out). Needs
    data-auth-source on element or ancestor."
  → VALUE field: select widget shows [in | out] (the catalog declares
    valueShape: enum). Pick "in".
  → Companion hint (slice-7): "No data-auth-source found on element
    or ancestors. + Add to <body>?"
  → Click "Add to <body>" → wires the source automatically
  → Done. Logout button shows only when localStorage.authToken is set.
```

#### Scenario C — Hand-author a form field error span (`data-error-for`)

The Form Scaffold + Field Row complex elements do this for you. This
walkthrough is for users who hand-author forms or add custom error
spans to a generated form.

```
Admin Panel → Visual Editor → Select mode
  → Click a <span> placed after an <input name="email">
  → "+ Add custom param"
  → type "data-" → pick data-error-for
    Description: "Container for QS.validate error messages. Set the
    value to the input's name attribute."
  → VALUE field: text input, placeholder "fieldName"
  → Type "email" → done
```

#### Scenario D — Change an existing element's binding (`Edit Params`)

Same picker, applied to existing elements instead of authoring new ones.
The autocomplete, smart widgets, reserved-key blocking, and companion
hints all behave the same as in Scenarios A–C.

```
Admin Panel → Visual Editor → Select mode
  → Click any element that already has data-* attrs (or a class, or a
    mandatory attr like src/href)
  → Sidebar action panel → "Edit Params" button (primary blue, between
    Add and Duplicate)
  → Form opens pre-populated:
    - Class tokens land in the CSS Class combobox as chips
    - Per-tag mandatory attrs (src for img, href for a, …) fill their widgets
    - All other params become custom rows — the picker auto-attaches and
      smart widgets render under the value field for known catalog entries
  → Edit any value → Save
    `editNode` receives a diff (addParams = new + changed, removeParams =
    keys removed from the form); the iframe updates in place, no full reload
  → Cancel or Back prompts before discarding if the form is dirty
```

**Out of scope on this surface**: tag swap (`<div>` → `<section>`),
editing component-call params, editing pure text nodes. For tag swap,
delete + recreate; BACKLOG carries the broader "Edit Element" entry.

### 10.3 Companion attributes

Several attributes are designed to pair with another:

| If you use… | You'll usually need… |
|---|---|
| `data-state-list` | `data-bind` on descendants (per-field display) + optional `data-state-empty` |
| `data-state-pagenav` | (Optional companions) `-page-field`, `-totalpages-field`, `-window`, `-prev-next` |
| `data-auth-show` | `data-auth-source` on element or ancestor |
| `data-qs-complex` | `data-qs-complex-id` |
| `data-bind` | A surrounding `data-state-list` OR `data-list-template` container |

The catalog encodes these in each entry's `companion` field; the
autocomplete (slice-7) surfaces them as "+ Add companion" hints.

### 10.4 Tldr by family

- **`data-state-*`** = bind to a state store (live data flow). See §9.6.
- **`data-auth-*`** / **`data-storage-*`** = bind to localStorage / sessionStorage
  (auth + storage UI). See §9.5.
- **`data-qs-complex*`** = mark a structure as bulk-translatable. See §8.7.
- **`data-bind`** / **`data-bind-attr`** / **`data-list-template`** = template-clone
  mechanics shared by `data-state-list` AND componentList rendering. See §9.2.
- **`data-error-for`** = `QS.validate` per-field error target. See §8.7.
- **`data-qs-textkey`** / **`-raw`** / **`-textonly`** / **`-node`** / **`-struct`** = editor-only
  selection chrome — do not author by hand.

### 10.5 Catalog file conventions

| Field | Purpose |
|---|---|
| `name` | The attribute (e.g. `data-state-show`) |
| `description` | One-sentence English; rendered as the autocomplete tooltip |
| `category` | `state` / `auth` / `storage` / `template` / `form` / `complex` / `internal` — drives optgroup grouping in the picker |
| `valueShape` | `store-field-ref` / `selector` / `enum` / `storage-spec` / `plain-string` / `boolean-string` — drives the value-field widget |
| `valueOptions` | (enum only) allowed values list |
| `companion` | Names of attributes commonly paired — surfaces a "+ Add companion" hint |
| `internal` | `true` for editor chrome (hidden from user picker by default) |
| `docAnchor` | Link target for "Full reference" in the picker |
| `examplePayload` | Short usage snippet |
| `since` | Version the attribute was introduced |

To add a new data-* attribute: implement the runtime hook (in `qs.js`
or wherever), then add one entry to the catalog. The picker, this
section, and any future renderer-side validation pick it up
automatically. Same pattern as `qsVerbCatalog.php` (the verb catalog
that shipped beta.7 commit `142c277`).

_Maintainers note: the canonical attribute list lives in
`secure/src/functions/qsDataAttributeCatalog.php`. If you add /
rename / remove an entry there, re-check the table in §10.1 above and
the cross-references in §8.7 / §9.5 / §9.6. The CLAUDE.md
doc-maintenance trigger table also lists the catalog file._

---

## 11. Risk hotspots

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

## 12. PHP integration files

| File | Role |
|---|---|
| `secure/admin/AdminRouter.php` | Route parsing, auth gate, URL helpers. |
| `secure/admin/templates/layout.php` | Shell + nav + script loader; injects `window.QUICKSITE_CONFIG`. |
| `secure/admin/templates/pages/preview.php` | Preview page composition. |
| `secure/admin/templates/pages/preview-config.php` | Generates `window.PreviewConfig`. Critical boot dependency for all preview modules. |
| `secure/src/classes/WorkflowManager.php` | Workflow loader / validator / prompt renderer / step resolver. |
| `secure/src/classes/CommandRunner.php` | Internal command execution for trusted workflow data needs. |
| `secure/src/classes/CssParser.php` | CSS manipulation backing the style commands. |
