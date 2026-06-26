# QuickSite Admin Panel

> Canonical reference for the admin panel's JS architecture, boot flow, and module map. See [ARCHITECTURE.md](ARCHITECTURE.md) for the system-level overview, [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) for the workflow engine, and [COMMAND_API.md](COMMAND_API.md) for the API commands the panel calls.

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

File lists are grouped by role. Where useful, the entry point or main exported function is named in the Purpose column. Treat these tables as a map, not a contract.

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

### 5.7 Code editor library (`lib/code-editor/`)

A small in-tree code-editor widget consumed by the Source view in CSS mode (§8.10):

- **`code-editor.js`** — textarea-over-pre widget. A transparent `<textarea>` handles all input (caret, IME, undo/redo, paste, selection); a `<pre>` behind it renders the tokenizer's coloured output; a left-side `<div>` gutter paints line numbers. All three scroll in sync. Pluggable tokenizer via `QSCodeEditor.tokenizers.<langKey>`.
- **`css-tokenizer.js`** — CSS tokenizer registered as `QSCodeEditor.tokenizers.css`. A frame stack tracks block nesting so selectors inside `@media` and other block at-rules classify as selectors, not properties. Token classes: `qs-tk-comment`, `qs-tk-string`, `qs-tk-atrule`, `qs-tk-selector`, `qs-tk-property`, `qs-tk-number`, `qs-tk-var`, `qs-tk-punct`.

The widget exposes `getValue / setValue / focus / destroy / resetScroll / setMatches / clearMatches / scrollRangeIntoView / scrollToLine`. Search-match overlay (used by Source view) paints into a separate `<pre>` layer between the highlight and the textarea.

### 5.8 Easing picker library (`lib/easing-picker/`)

A self-contained `cubic-bezier()` curve picker consumed by the Motion tab (§8.11) — the Animation Preview modal's timing-function field and the Transition wizard's easing input both open it.

- **`easing-picker.js`** — popover dialog with a 220×220 SVG curve canvas, two `pointer`-event-driven control-point handles, 5 preset chips (`linear` / `ease` / `ease-in` / `ease-out` / `ease-in-out`), 4 numeric inputs (`x1`, `y1`, `x2`, `y2`) and an animated dot preview that runs the current curve over a horizontal bar (replay button to retrigger). Anchor-positioned (clamps to viewport, flips above the anchor if no room below) or centered fallback when no anchor is supplied.

Public API: `QSEasingPicker.open({ anchor, value, onConfirm, onCancel })` → on confirm returns the resulting easing string (named preset if values match exactly, otherwise `cubic-bezier(x1, y1, x2, y2)`). Also exposes `close()`, `PRESETS` (name → `[x1, y1, x2, y2]` array), and `parseValue(string)`.

---

## 6. Storage key registry

Every localStorage / sessionStorage key is declared as a constant in `js/core/storage-keys.js`.

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
| `STYLE_SOURCE_DRAFT` | localStorage | `preview-style-source.js` (visual-editor CSS mode → Source view; debounced draft of unsaved edits, restored on next entry, cleared on save / discard) |

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

The sidebar exposes eight modes, each backed by a `data-mode="..."` button. Source: `secure/admin/templates/pages/preview/sidebar-tools.php`.

| Button | Mode | What it does |
|---|---|---|
| **Preview** | `preview` | Reloads the iframe and re-enters preview-only behaviour (no selection / hover overlays). |
| **Select** | `select` | Click an element → inspect node, add a sibling/child, **edit its params + class + mandatory attrs in place** (via the Edit Params button), delete, duplicate, or save as snippet / component. |
| **Drag** | `drag` | Drag elements to reorder within parent (`preview-drag.js`). |
| **Text** | `text` | Inline-edit a text node's translation **for the currently selected language**. Intentionally primitive: no rich text, no line breaks. Edits the value, never the key. |
| **CSS** | `style` | Click an element → CSS panel (Theme / Selectors / Motion tabs); edits apply to the element's selector with full pseudo-state support. The Theme tab includes an inline **+ Add variable** form per section (Colors / Fonts / Spacing) for scope-aware writes (current light/dark scope, optional "also other scope" when dark mode is enabled). The Motion tab (formerly Animations) is reshaped around two sections: **Selectors with motion** (transitions + animations + a "hover-state-only" diagnostic) and the **Keyframes library** below — see §8.11. Designer / developer / admin roles also see a top-row **Source** advanced view that opens the whole `style.css` in a code editor — see §8.10. |
| **Interactions** | `js` | Event editor (`preview-js-interactions.js`). Three CRUD-capable panels: **per-element interactions** (the selected node's `onclick`/`oninput`/etc.), **Page Events** (page-level `onload`/`onresize`/`onscroll`), and **State Stores** (page-scoped state). Each interaction is a `{{call:fn:args}}` chain using a QS.* verb from the catalog. |
| **Translations** | `translation` | Site-wide translation key manager (`preview-translation.js`). Per-language coverage % + counts, scope picker (Pages / Layout / Components), inline row-expand editor, per-row + bulk delete. Mono- and multi-lingual. See §8.9. |
| **AI tools** | `ai-tools` | In-editor workflow runner. Searchable + tag-filtered list of workflow specs grouped by category; picking one swaps the panel into a 3-zone runner (INPUTS / AI EXCHANGE / EXECUTION). Element clicks in the iframe behave as in Select mode — workflows with a `selector` parameter auto-fill from the current selection. See §8.12. |

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
| Translations | `getTranslation`, `getLangList`, `getTranslationKeys`, `getUnusedTranslationKeys`, `validateTranslations`, `setTranslationKeys`, `deleteTranslationKeys`, `editTranslation` |
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

**Built-in kinds**

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
| `tab-set` | `<div class="tabset" id="<setId>">` + `<div role="tablist">` + N `<button role="tab">` + N `<div role="tabpanel">` | ARIA-correct tab semantics. Click-to-switch wired via existing QS verbs (`removeClass` / `addClass` / `hide` / `show` chain in each tab's `onclick`) — no new runtime needed. User-provided `setId` scopes the per-tab click chain so multiple tab sets coexist on one page. Each panel content is a single `<p>` with a `textKey` — edit further via the regular editor after save. |
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
scanner.

### 8.8 Text authoring (Add → Text tab, inline edit, RAW + key)

Text in a page lives in `{ "textKey": "…" }` nodes. There are two flavours:
a **translation key** (looked up against the language file at render time, e.g.
`home.greeting`) and a **RAW literal** (`__RAW__`-prefixed, rendered verbatim —
for separators / one-off labels like `" / "`, `"—"`). Both are first-class.

**Tag elements add empty.** A new tag has no text child. To add text to a tag, use **Add → Text** (below) or **Text mode** inline.

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

The Add button (bottom Cancel / Add) posts `addNode` with `nodeKind:"text"` plus either `textRaw:true + textValue` (RAW) or the picked `textKey` (key). Default position respects the radio (`after` / `inside` / `before`).

**Text mode — inline editing of both flavours.** The Text-mode tool clicks any
text node in the preview and makes it `contenteditable`. On commit:
- For a translation-key node, the new value is saved via `setTranslationKeys`
  to the current-language translation file.
- For a `__RAW__`/`__LIT__` literal, the new value is saved via `editStructure`
  by updating the node's `textKey` to `"__RAW__"+value` — *not* a translation
  entry (literals don't have one).

In the renderer (`JsonToHtmlRenderer::renderText`), editor mode wraps both
key-based AND `__RAW__`/`__LIT__` text in the `data-qs-textkey` selection span
(with a `data-qs-raw="true"` marker on literals), so RAW text gets a selection
handle just like translation-key text.

| Concern | Where |
|---|---|
| Add Text tab markup | `secure/admin/templates/pages/preview/contextual-add.php` |
| Add Text dispatch + picker mount | `public/admin/assets/js/pages/preview/preview.js` (`addTextNode`, `ensureAddTextKeyPicker`) |
| TextKey picker primitive | `public/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js` |
| Inline RAW-vs-key save branch | `preview.js` `handleTextEdited` + `saveRawTextEdit` |
| Renderer editor wrapping | `secure/src/classes/JsonToHtmlRenderer.php` `renderText()` |
| Backend insert | `secure/management/command/addNode.php` (`nodeKind:'text'` mode) |

### 8.9 Translation Manager (Translations mode)

A sixth editor mode that **manages translation keys site-wide for the current project** — a dense table view of every key the site references plus every key the chosen language file has, classified into three buckets. Complements Text mode: Text mode edits values inline on the rendered page; Translations mode is the bird's-eye where you find orphans, set bulk values, and audit coverage.

**Where you land it from:** sidebar Translations icon → `setMode('translation')` → `#contextual-translation` shows + `PreviewTranslation.enter()` triggers the lazy data fetch on first entry.

**Status taxonomy** — every key is one of:

| Status | Definition | Lives where |
|---|---|---|
| 🟢 **Used** | Referenced by a page/menu/footer/component structure AND has a non-empty value in this language | translations + structures |
| 🔴 **Unset** | Referenced by a structure but missing from translations OR has `""` value | structures only |
| 🟡 **Unused** | In the translation file but no structure references it (orphan) | translations only |

These three are mutually exclusive — a key never appears in more than one bucket.

**Coverage math** — `used / (used + unset)`. Unused does NOT distort the denominator; orphaned translations are a clean-up concern, not a "translation done" signal. `0/0 → 100%` (nothing to translate = done).

**Panel layout (top to bottom)**

| Region | What it does |
|---|---|
| Toolbar — Language picker | Switches the language file being managed. Hidden when only one language exists (monolingual, or multilingual with a single config'd lang). |
| Toolbar — Scope picker | "Whole site" / Pages optgroup (page routes) / Layout optgroup (menu, footer) / Components optgroup (`component:<name>`). |
| Toolbar — Coverage strip | `Coverage: 78% (142/172)` text. Recomputed per-scope. |
| Filter row — substring | Case-insensitive contains-match on key name. Counts in chips narrow with substring. |
| Filter row — status chips | 🟢 Used / 🔴 Unset / 🟡 Unused, each with a count + visible label. Toggle to filter rows. Counts are scope-aware (and substring-aware) but NOT status-aware (a chip can't depend on its own checked state). |
| Row list | One row per filtered key: status dot · `<code>key.name</code>` · value preview (100-char truncate, full value in `title=`) · hover-revealed Edit + Delete icon buttons. Alphabetical by key name. |
| Bottom action | "Remove all unused (site-wide)" button. Enabled iff site-wide unused count > 0. Opens the bulk-confirm panel (replaces row list temporarily). |

**Inline edit (row expansion, not modal)** — clicking the pencil icon on a row expands an editor panel directly below: textarea pre-filled with current value, Save + Cancel buttons. Saves via `setTranslationKeys` (dot-notation, server normalises to nested). Keyboard: **Esc** = cancel, **Ctrl/Cmd+Enter** = save. The textarea auto-focuses with cursor at the end of existing text.

For unset rows the Edit icon becomes a `+` (Set value) icon and the panel pre-fills empty — the textarea is the same; the affordance just signals "this is fresh-set, not a re-edit".

After save success the panel runs `_refetchAndRender()` so the row's status flips naturally (unset → used, etc. — the "stale-sibling sweep"), then calls `PreviewState.reloadPreview()` so the iframe shows the new value live.

**Per-row delete (list-first confirm, no bare prompt)** — clicking the trash icon expands a red-bordered confirm panel below the row: `Delete {key} from {LANG}.json?` plus a value preview, then Cancel + Delete buttons. Multi-language opt-in checkbox appears beneath when ≥2 languages exist (default OFF — you're usually editing one language). Confirm runs `deleteTranslationKeys` for the target language(s) via `Promise.all`; 404 from a non-current language is treated as success (key already absent in that file).

**Bulk remove-unused** — same list-first pattern applied at scale. Button opens a full-panel confirm that replaces the row list: header `These {n} unused keys will be deleted from {LANG}.json:`, scrollable list of every unused key + truncated value preview, multi-language opt-in (default ON — orphaned keys are orphaned everywhere), then Cancel + Delete-N buttons. Single `deleteTranslationKeys` call per target language, parallel.

Partial failure (some languages succeed, others fail) is surfaced inline: `Failed for 1/2 languages: FR: <details>`. State stays restorable — user can retry without losing the confirm context.

**Scope-aware counts** — chip counts + coverage % reflect the active scope + substring filter (status excluded). Site-wide totals drive ONLY: the "no translation keys yet" empty state, and the bulk-button enabled gate. So `Used 8` next to the chip when scoped to `home` page is correct ("8 keys on the home page are used"); the bulk button stays enabled iff *any* unused keys exist site-wide, not just on the scoped page.

Unused keys naturally drop out for non-`site` scopes — they belong to no source by definition.

**Monolingual mode (`MULTILINGUAL_SUPPORT = false`)** — the runtime ignores per-language files and loads `default.json` exclusively (see [Translator.php:25-27](../secure/src/classes/Translator.php#L25)). The panel detects this from `getLangList`'s `multilingual_enabled` field and:
- Sets `_availableLangs = ['default']` (NOT the configured `LANGUAGES_SUPPORTED`)
- Hides the language picker + its label
- All writes target `default.json`
- The multi-language checkbox auto-hides (one language, no choice to surface)

This matters because `LANGUAGES_SUPPORTED` for monolingual projects often lists `'en'` or similar — managing `en.json` would be invisible to the rendered site, which loads `default.json`. All translation commands accept `'default'` as a valid language code for monolingual writes.

**Default.json visibility (multilingual)** — hidden from the language picker. It's a plumbing file (mono-language fallback), not a user-facing translation target.

**Live reload after writes** — every write path (save, per-row delete, bulk delete) ends with `_reloadPreviewIframe()` → `PreviewState.reloadPreview()`, the same hook every other write flow (style, JS interactions, animations) uses. Authoring feels real-time.

**Iframe nav-guard** — translation mode is registered in `isEditorMode()` in `preview-iframe-inject.js` so link clicks + form submits in the preview are swallowed (same as select/text/style/js/add). Without this, accidentally clicking a link in the preview would navigate the iframe to a different page than the editor's "page" picker — silent edit-target drift.

**Files**

| Concern | Where |
|---|---|
| Panel template | `secure/admin/templates/pages/preview/contextual-translation.php` |
| Sidebar mode button | `secure/admin/templates/pages/preview/sidebar-tools.php` |
| JS module | `public/admin/assets/js/pages/preview/preview-translation.js` |
| Mode wiring | `public/admin/assets/js/pages/preview/preview.js` (`setMode('translation')`) |
| i18n keys exposed to JS | `secure/admin/templates/pages/preview-config.php` (`i18nPanels.translation` block) |
| CSS | `public/admin/assets/admin.css` (`.preview-contextual-translation__*`) |
| Server: read-side composing helper | `public/admin/api/index.php` case `'translation-keys-grouped'` |
| Server: scan commands | `getTranslationKeys.php`, `getUnusedTranslationKeys.php`, `validateTranslations.php` |
| Server: write commands | `setTranslationKeys.php`, `deleteTranslationKeys.php` |
| Server: shared key validator | `secure/src/functions/translationHelpers.php` (`isValidTranslationKey`) |
| Runtime translator | `secure/src/classes/Translator.php` (mono = `default.json`; multi = `{lang}.json`) |

### 8.10 Source view (CSS mode)

An advanced sub-view of CSS mode that exposes the entire `style.css` as a code-editor surface for designers / developers who need to read or edit the whole file in context, without leaving the visual editor. Hidden for roles without the `editStyles` permission.

**Where you land it from:** CSS sidebar mode → top-row **Source** button above the Theme / Selectors / Animations tabs. Activating it hides the three tabs and swaps the iframe canvas for the editor; deactivating restores both.

**Canvas (editor surface):**

| Region | What it shows |
|---|---|
| Toolbar | Filename label (`style.css`) on the left; search bar on the right (Find input, ▲ ▼ nav buttons, match count). |
| Gutter | Line numbers, scroll-synced with the editor. |
| Editor | Textarea-over-pre widget (§5.7) — a transparent `<textarea>` handles input while a `<pre>` behind it renders the CSS tokenizer's coloured tokens. |

**Sidebar (narrow column while Source is active):**

| Element | What it shows / does |
|---|---|
| File row | Static `File: style.css` label. |
| Status indicator | Green dot + "All saved" when content matches the server; amber dot + "Unsaved changes" otherwise. |
| Save button | Disabled when clean. Saves via `editStyles` (whole-file write) then hot-reloads the iframe's `<link rel="stylesheet">`. |
| Cancel button | Disabled when clean. Re-fetches the file from server with a confirm. |
| "Refine in CSS Refiner →" link | Same-tab navigation to `/admin/optimize`. When dirty, a custom confirm prompts before navigating; the native `beforeunload` prompt is suppressed once to avoid a double-prompt. Modifier-clicks (Ctrl/Cmd/Shift/middle) skip the confirm — they don't leave the current page. |

**Search bar:**

| Action | Shortcut |
|---|---|
| Focus the Find input | `Ctrl/Cmd+F` (while Source is active) |
| Focus with `:` prefilled (jump-to-line trigger) | `Ctrl/Cmd+G` |
| Next match | `Enter` or ▼ button |
| Previous match | `Shift+Enter` or ▲ button |
| Close + clear matches | `Esc` |
| Jump to line N | Type `:N` in the Find input + `Enter` |

Match highlighting paints into a separate `<pre>` overlay layer (between the highlight and the textarea) so every match is visible at once; the current match gets a stronger amber + outline. Substring search is case-insensitive.

**Live preview.** Every keystroke (debounced ~200ms) injects / updates a `<style id="qs-source-live-styles">` element appended to the iframe's `<head>`. The iframe is hidden while Source is active, but exiting Source (tab click, mode switch) immediately shows the unsaved edits applied — no save needed. The injection is removed on save (replaced by a real `hotReloadCss` of `style.css`) and on Cancel (the iframe reverts to the server file).

**Draft persistence.** On every input the editor's content is debounced-written (500ms) to `localStorage` under `STYLE_SOURCE_DRAFT` (see §6). On the next Source entry, if the draft differs from the server file, a restore banner appears between the toolbar and the editor offering **Restore** (load the draft + mark dirty) or **Discard** (clear the draft). The draft is also cleared on successful save / Cancel.

**Dirty guards:**
- Switching from Source to Theme / Selectors / Animations while dirty → confirm before discarding.
- Switching to another sidebar mode while dirty → same prompt (via `setMode`'s guard).
- Closing the browser tab / refreshing while dirty → native `beforeunload` prompt.

**Cross-tab cache invalidation.** A Source save / Cancel rewrites or re-reads the whole `style.css`, so the three structured tabs' caches are invalidated (`PreviewStyleTheme.invalidate()` + `PreviewSelectorBrowser.reset()` + `PreviewStyleAnimations.reset()`). The next view of any tab triggers a fresh fetch — visible both from the tab-click handler and from `deactivateSource()` so all exit paths converge. See `DESIGN_DECISIONS.md` "Source / structured-tabs cross-tab cache invalidation" for the accepted trade-off (a structured tab's unsaved edits are lost on the next view after a Source save).

**Files**

| Concern | Where |
|---|---|
| Source button + advanced row + sidebar panel | `secure/admin/templates/pages/preview/contextual-style.php` |
| Canvas mount + toolbar + restore banner | `secure/admin/templates/pages/preview/main-area.php` |
| JS module | `public/admin/assets/js/pages/preview/preview-style-source.js` |
| Code-editor widget library | `public/admin/assets/js/lib/code-editor/` (see §5.7) |
| Mode wiring + cross-tab guards | `public/admin/assets/js/pages/preview/preview.js` (`activateSource` / `deactivateSource` / `initStyleSource` / `initStyleTabs`) |
| i18n keys exposed to JS | `secure/admin/templates/pages/preview-config.php` (`i18nPanels.source` block) |
| CSS | `public/admin/assets/admin.css` (`.preview-source-canvas__*`, `.preview-source-sidebar__*`, `.preview-contextual-style-source-btn*`, `.qs-code-editor__*`, `.qs-tk-*`, `.qs-search-match*`) |
| Storage key | `STYLE_SOURCE_DRAFT` in `js/core/storage-keys.js` (see §6) |
| Server: read | `getStyles` (returns `{ content, file, size, modified }`) |
| Server: write | `editStyles` (whole-file replace; basic CSS injection blacklist; writes both live + project copies) |

### 8.11 Motion tab (CSS mode)

The third tab of CSS mode — manages CSS transitions, animations, and the `@keyframes` library for the current project. Renamed from "Animations" (the old label collided with the inner "animations" sub-group and led to the "Animations > Animations" head-scratcher).

**Where you land it from:** CSS sidebar mode → click the **Motion** tab.

**Two top-level sections:**

| Section | Default state | Content |
|---|---|---|
| **Selectors with motion** | Expanded | Transitions / Animations / Hover-state-only sub-groups. Lists every selector currently using a `transition:` or `animation:` declaration. |
| **Keyframes library** | Expanded | Every `@keyframes` rule in the stylesheet, with per-row actions to preview, apply to a selector, edit, and delete. |

**Selectors with motion** lists three sub-groups:

| Sub-group | What goes here |
|---|---|
| **Transitions** | Selectors with a `transition:` declaration. Tops the list with a "+ Add transition" button that opens the Transition wizard (below). |
| **Animations** | Selectors with an `animation:` declaration. |
| **Hover-state-only** | Diagnostic group (collapsed by default). Selectors that change appearance in `:hover` / `:focus` / etc. without declaring a `transition` — common authoring oversight. The header carries an ⓘ icon + tooltip explaining what the group means. |

Clicking any row opens the existing State & Animation Editor (`modals/transition.php`) scoped to that selector.

**Keyframes library** lists `@keyframes` rules with four row actions:

| Action | What it does |
|---|---|
| ▶ Preview | Opens the existing Animation Preview modal (`modals/animation-preview.php`) running the keyframe over a sample logo. |
| → Apply to selector… | Opens the **Apply-keyframe modal** — pick a selector from a searchable list; writes `animation: <name> 1s ease;` to that rule via `setStyleRule`. Default value is one-shot; users can refine via Selectors → Edit Styles. |
| ✎ Edit | Opens the keyframe editor (`modals/keyframe.php`) on this keyframe. |
| 🗑 Delete | Removes the `@keyframes` rule via `deleteKeyframes`. |

If the keyframe is used by ≥ 1 selector, a **used by N** chip appears next to the frame count. Clicking it expands an inline list below the row, each line showing a selector + an ✕ remove button that strips the `animation:` declaration from that rule (via `setStyleRule` with `removeProperties: ['animation']`). The data is pivoted from the same `animatedSelectorsData.animations` cache that drives the Animations sub-group, so the badge count updates immediately on any apply / remove inside the session.

**Transition wizard** (modal opened from "+ Add transition" at the top of the Transitions sub-group):

| Field | Affordance |
|---|---|
| Selector | Search input + filterable list (same source as the Selectors tab). |
| Property | Preset chips (opacity / transform / color / background-color / border-color / box-shadow / all) plus a free-text input for any custom CSS property. Chip and input stay in sync. |
| Duration | Number input (ms; default 300). |
| Easing | Button showing the current value; opens `QSEasingPicker` (see §5.8) pre-loaded with that value. |
| Delay | Number input (ms; default 0; omitted from the output when 0). |

If the picked selector already has a transition, the form pre-fills from `animatedSelectorsData.transitions[i].parsed[0]` (CSS times normalised to integer ms) + an amber "will overwrite" hint appears. Submit writes `transition: <prop> <dur>ms <easing> <delay>ms;` via `setStyleRule`. The structured-tabs caches invalidate so the new entry surfaces in the Transitions sub-group on the next view; `PreviewState.hotReloadCss()` flushes the iframe's stylesheet.

**Easing picker** (`QSEasingPicker`, §5.8) is shared between the wizard's easing field and the Animation Preview modal's Timing Function "custom curve" button.

**Files**

| Concern | Where |
|---|---|
| Panel template (sections + groups + tooltip) | `secure/admin/templates/pages/preview/contextual-style.php` |
| JS module | `public/admin/assets/js/pages/preview/preview-style-motion.js` (renamed from `preview-style-animations.js`; window global `PreviewStyleMotion`) |
| Apply-keyframe modal | `secure/admin/templates/pages/preview/modals/apply-keyframe.php` |
| Add-transition wizard modal | `secure/admin/templates/pages/preview/modals/add-transition.php` |
| Easing picker | `public/admin/assets/js/lib/easing-picker/` (see §5.8) |
| i18n keys exposed to JS | `secure/admin/templates/pages/preview-config.php` (`i18nPanels.animations` block — the panel key is kept on the old "animations" name for backward compat with the existing routing) |
| CSS | `public/admin/assets/admin.css` (`.preview-keyframe-item*`, `.preview-animations-*`, `.apply-keyframe-modal*`, `.add-transition-modal*`, `.qs-easing-picker*`) |
| Server: read | `listKeyframes`, `getAnimatedSelectors` |
| Server: write | `setStyleRule` (apply-keyframe, remove-from-selector, add-transition all go through it; the remove path uses `removeProperties: ['animation']`); `setKeyframes`, `deleteKeyframes` |

### 8.12 AI tools (mode `ai-tools`)

The AI tools mode lets the operator pick a workflow spec, fill its parameters, and run it without leaving the visual editor. Workflows expose themselves as a searchable + tag-filtered list; picking one swaps the panel into a runner organised in three zones — INPUTS, AI EXCHANGE, EXECUTION.

#### Backup-first banner

A persistent amber-bordered banner sits at the top of the panel: `⚠ AI tools modify your site directly. Changes cannot be easily reverted. We recommend creating a backup before running any workflow you're not sure about.` The banner is not dismissable. The button calls the existing `backupProject` command (timestamped folder copy into `secure/projects/<active>/backups/<timestamp>/`) and confirms via toast on success or failure.

#### Workflow list

- **Search** — case-insensitive substring across title, description, tags
- **Tag chip filter** with a `Match: Any | All` toggle (toggle row appears only when ≥2 chips are active). Chips show the top 6 tags by frequency plus a `+N more` expander; any active chip outside the top 6 stays visible so it can always be deselected
- **Category grouping** — canonical order `Creation → Template → Modification → Content → Style → Advanced → WIP`. Sub-headers between groups; alphabetical within
- **`Show 10 more` pagination** at the end of the list — global across categories
- **Cards** carry the workflow's emoji icon, title, AI 🤖 / Steps 📦 badge, 2-line description, colour-coded difficulty chip (`beginner` green, `intermediate` amber, `advanced` red), and a violet `custom` chip when the workflow is user-authored

#### Runner — INPUTS zone (accent border)

- **Your prompt** (AI workflows only) — free-form textarea for the user's request, appended to the assembled prompt after a `**User Request:**` marker
- **Parameters** — workflow-declared input form; types include `text`, `textarea`, `select` (inline options or `optionsFrom` data-driven), `tag-select` (scrollable checkbox list), `checkbox` (rendered inline `[☑] Label / help`), `number`, `selector` (read-only card showing the current iframe selection), `hidden`. Validation surfaces per-param with red border + error text; Run is gated on no visible-param validation failing
- **Model: [dropdown]** (BYOK only) — inline above the action buttons; writes to the active connection's `defaultModel` via `QSConnectionsStore`
- **Primary action button** — context-aware label: `Run` (deterministic) / `Run with AI` (BYOK + auto-execute on) / `Send to AI` (BYOK + auto-execute off) / `Generate prompt` (no BYOK). Label morphs through phases during execution: `Generating prompt…` → `Sending to {model}…` → `Running…`
- **Secondary `Generate for copy` button** (AI + BYOK) — assembles the prompt without sending; auto-expands + scrolls + focuses the General prompt section + auto-copies the result to the clipboard with a toast confirmation. Lets BYOK users still inspect / re-use the prompt elsewhere

#### Runner — AI EXCHANGE zone (dashed border, AI workflows only)

- **General prompt** — read-only textarea showing the assembled final prompt (rendered template + user prompt). Copy button. Auto-populates during BYOK pipelines; manually filled by the Generate-for-copy or no-BYOK Generate path
- **AI response** — textarea that doubles as paste target (no BYOK) or live stream sink (BYOK). Status line below the textarea cycles through `Sending to {model}… (X.Xs)` → `Receiving from {model}… (N chars, X.Xs)` → `N commands ready ✓` (green) or `Invalid JSON: …` / `No commands found in response.` (red). When no BYOK is configured, contextual hints appear under the textareas (`Copy this to your AI assistant, then paste the reply below.` / `Paste the JSON reply here.`)

#### Runner — EXECUTION zone (dashed border)

- **Auto-execute toggle** (AI workflows only) — when checked, valid responses fire a 1.5s grace timer (`Auto-executing in 1.5s — edit response to cancel`); any textarea edit cancels and reschedules
- **Batch (collapsible)** — auto-populates with the upcoming command list the moment the AI response parses (or, for deterministic workflows, the moment params resolve server-side via `/api/workflow-generate-steps`). State header cycles `Idle` → `N commands ready` → `Running 2/N` → `Done (N succeeded)` or `Done (X/N succeeded, Y failed)`. Each row shows the command name + a truncated `key: "value" • …` params summary; clicking the row toggles the full params JSON inline
- **Execute commands button** — visible only when commands are queued AND auto-execute is off (covers the BYOK-stop-after-send and no-BYOK-paste flows)

After at least one command succeeds, the iframe reloads via `window.PreviewManager.reload()` so visual changes appear immediately. The `quicksite:workflow-complete` window event also fires for any listening sibling module (the miniplayer, etc.).

#### Footer

Single line: `Touches: cmd1, cmd2, cmd3` — the workflow's `relatedCommands` listed inline in mono font. Model is in the INPUTS zone, not the footer.

#### Element selection

AI tools mode forwards as `select` to the iframe overlay, so hover-highlight + click-to-select work just like in Select mode. The footer/menu structure-mismatch confirm prompts are suppressed — selection in AI tools is informational, not edit-context-switching. The current selection is available through `window.PreviewManager.getSelectedNode()` as `{ struct, node, component, tag, classes }`. Workflows declaring a `selector` parameter receive this object as the param value and refresh live as the user picks different elements; see [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) for the parameter-type contract.

#### BYOK calls

The AI call is browser-direct via `QSAiCall.call(...)` (see `public/admin/assets/js/pages/ai/lib/ai-call.js`). When the active connection has streaming enabled (default), the response fills the AI response textarea token-by-token through the `onChunk` callback; status line ticks chars + elapsed seconds every 300ms. No PHP proxy hop — the API key never touches the server.

#### Files

| Concern | File |
|---|---|
| Panel logic | `public/admin/assets/js/pages/preview/preview-ai-tools.js` |
| DOM scaffold | `secure/admin/templates/pages/preview/contextual-ai-tools.php` |
| Styles | `public/admin/assets/css/preview-ai-tools.css` |
| Workflow detail endpoint | `public/admin/api/index.php` (`ai-spec-raw`, `ai-spec`) |
| Deterministic resolver endpoint | `public/admin/api/index.php` (`workflow-generate-steps`) |
| Per-command execution | direct `POST /management/{command}` |
| AI dispatch | `public/admin/assets/js/pages/ai/lib/ai-call.js` (+ `provider-catalog.js`, `connections-store.js`, `stream-parsers.js`) |
| Workflow framework | `secure/src/classes/WorkflowManager.php` |

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
| `callableFrom` | `client` / `server` / `both`, or absent for auto-derive. Server-only endpoints are **filtered out of `qs-api-config.js`** at build emit time (clients literally can't call them). Auto-derive rule: `apiKey → server`; everything else → `both`. Set explicitly in the form via the "Callable from" select; "Auto" shows a live preview of the derived value. |
| `requestSchema` | Optional JSON Schema for POST/PUT/PATCH bodies. Drives the test modal's dynamic form. |
| `responseSchema` | Optional JSON Schema. Drives the response-bindings picker (componentList / count / etc.). |

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

**Import**: two-screen modal (paste → preview → confirm). The paste
screen accepts JSON in three shapes; the detector inspects the
top-level keys and picks the appropriate converter:

| Detected when | Format | Notes |
|---|---|---|
| Top-level `apis: {...}` | Native | Pasted as-is. The shape exported by the page's Export button. |
| Top-level `openapi: "3.x"` | OpenAPI 3.x | Converted by the OpenAPI converter (below). |
| Top-level `swagger: "2.0"` | Swagger 2.0 | Detected and explicitly unsupported — the preview points the user at `converter.swagger.io` to convert to 3.x first. |
| Top-level `endpoints.{public, secured, ...}` | File-manager | Legacy converter — each group becomes its own API named `<base>-<group>`; bearer/basic/apiKey auth derived from the group's `authentication.type`; `:placeholders` rebuilt from each endpoint's declared parameters + `route_format`. |

**OpenAPI 3.x converter** (`openapi-converter.js`). Maps an OpenAPI
document to one QuickSite API + one endpoint per `paths.<path>.<method>`:

| OpenAPI field | QuickSite field | Notes |
|---|---|---|
| `info.title` | API `name` | Slugified into the `apiId`. |
| `servers[0].url` | API `baseUrl` | Relative URLs surface a dedicated input in the preview (must be made absolute before Import). |
| `components.securitySchemes` | API `auth` | Most-used scheme wins; first-mappable becomes the API auth. apiKey-in-header → `apiKey` + `tokenSource: 'header:<name>'`. http-bearer / oauth2 / openIdConnect → `bearer`. http-basic → `basic`. apiKey-in-cookie → `cookie` (with a "verify Pattern X" warning). |
| `paths.<p>.<method>.operationId` | Endpoint `id` | Slugified to dash-case. Falls back to `<method>-<slugified-path>` if absent. Collisions append `-2`, `-3`, ... and are noted in the preview. |
| `paths.<p>.<method>.summary` | Endpoint `name` | Falls back to `operationId`, then to `<METHOD> <path>`. |
| `paths.<p>.<method>.description` | Endpoint `description` | |
| `paths.<p>.<method>.parameters[in=path \| query]` | Endpoint `parameters[]` | `in: header` is dropped (QuickSite carries headers at the API level via `auth.tokenSource`); the count is surfaced in a note. `$ref` parameters are resolved against `components.parameters`. |
| `paths.<p>.<method>.requestBody.content.<media>.schema` | Endpoint `requestSchema` | First declared content-type wins (`application/json` preferred). `$ref` is inlined; `allOf` is merged into a flat object schema; `oneOf` / `anyOf` are skipped with a note. |
| `paths.<p>.<method>.responses.2xx.content.<media>.schema` | Endpoint `responseSchema` | Status preference: `200` → `201` → `202` → `204` → `default` → any other 2xx. |
| `paths.<p>.<method>.security` (vs spec-level `security`) | Endpoint `auth` | `inherit` when the operation references the picked API scheme. `none` when effective security is empty (op explicitly `[]` or no security anywhere). `required` when the operation references a different scheme — the preview notes which alternates were seen. |
| `example:` on a schema property | Copied | Stripped on credential-named keys (`token`, `accessToken`, `password`, `apiKey`, `secret`, `bearer`, `authorization`, etc.) and on anything under `securitySchemes`. Kept-vs-stripped counts appear in the preview. |
| `$ref: "#/components/..."` | Inlined | Local refs only; external URL refs are skipped with a note. Cycles are broken by emitting an empty-object placeholder so a circular schema doesn't infinite-loop. |
| `xml` metadata on schemas | Dropped | OpenAPI-specific; meaningless to QuickSite. |

**Preview screen** (after the converter runs):

1. **Summary line** — detected format, API count, endpoint count, and notes (alternate auth schemes, dropped header params, schema-example counts, slug collisions, etc.).
2. **Base URL fixer** — appears when any API has a relative `baseUrl`. One labeled input per affected API; edits mirror live into the JSON dump; Import is blocked until every baseUrl matches `http(s)://`.
3. **Tree view** — one section per API. The header has a select-all checkbox, the API name + ID, and a count badge that reads `19 endpoints` when all are selected and `15 / 19 endpoints` when partial. Each endpoint row shows: a checkbox (default checked), method badge, endpoint ID, path, auth indicator (`public` / `🔐 inherit (bearer)` / `🔐 required`), and `req` / `resp` chips when the endpoint has schemas attached. Unchecked endpoints are dropped at Import.
4. **Advanced: raw JSON** — collapsed by default. The full converted JSON, editable. Edits here take effect on Import alongside the tree-selection state.

**After import** — the converter is intentionally lossy in a few places
that need authoring on top:

- **Response bindings** (which fields feed which DOM selectors / state stores) are project-specific and authored in the visual editor — the imported schemas drive the binding picker, but no bindings are emitted.
- **OAuth handler config** — when a security scheme is `oauth2` or `openIdConnect`, the converter sets the API auth to `bearer` (oauth2 yields a bearer token at runtime). Configure the OAuth handler details via the API edit form.
- **Auth token storage** — the converter sets sensible `tokenSource` defaults (`localStorage:token` for bearer, `localStorage:basicAuth` for basic, `header:<name>` for apiKey). The actual token value is entered per-API in the registry card's Auth Token field.
- **Alternate security schemes** — endpoints flagged `auth: 'required'` (different scheme from the API default) need manual refinement. The preview's notes name which schemes were seen.

### 9.2 Component list binding

A response-binding render mode that fills a container with one cloned
component instance per item of an array response field. The component
template acts as the per-item shape; a `fieldMap` says which API field
feeds which template variable, with optional enum resolution.

**Where it lives in the UI**

The picker is part of the **JS Interactions** modal (per-element
event handler authoring). Component-list mode appears when:
- The interaction is in **Action Type: API Call** (not Function).
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

**Multi-language limitation**

`qs-api-config.js` is project-scoped, not per-language. Translation
happens once at `editApi` / `switchProject` / `build` time using
whatever language is active in that request. Multi-language sites
should re-trigger one of those after a language switch.

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
empty. Re-picking re-emits the key.

**Compile-time translation pattern (reusable)**

The metadata table for `fetch` is the first consumer:

```php
private const TRANSLATABLE_KEYWORD_ARGS = [
    'fetch' => ['toastSuccessKey', 'toastErrorKey'],
];
```

Future verbs that introduce translatable **kwargs** (e.g. `confirm`,
`prompt`) add one line. `JsonToHtmlRenderer::buildQsCallJs` walks
each call's args and translates the value whenever the key is in
the list.

Parallel path for translatable **positional** args (catalog-driven):
args declaring `inputType: 'translationKey'` in `qsVerbCatalog.php` are
resolved automatically at render time — no per-verb code change
required. See §9.9.7 + ARCHITECTURE §8.0.2 for the resolution mechanics.

### 9.5 Auth flows — Tier 1 (token persistence), cookie pattern, Tier 2 (refresh on 401), Tier 3 (magic-link)

Token-flow primitives across four tiers (persistence + refresh + magic-link + OAuth) plus the cookie auth type. Auth routes are author-owned, with `addRoute`'s conflict-detection acting as the safety rail.

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
that's currently manual via a separate interaction.

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
| `data-auth-show="connecting"` / `"failed"` | (Tier 3) show during / after a magic-link exchange. Drives the "Signing you in…" + "invalid or expired link" messages in the `magic-link-handler` component. Driven by `qs:auth:exchange-started` / `qs:auth:exchange-failed` events from `QS.exchangeMagicLink`; cleared on `qs:auth:saved` (success path) or `qs:auth:cleared` (logout). |
| `data-storage-show="has:localStorage:key"` / `"missing:localStorage:key"` | generic presence show/hide for any storage key |
| `data-storage-value="localStorage:key"` | sets the element's text to the stored value |

`data-auth-show` is auth sugar over "is the token present"; the
`data-storage-*` pair is the general form (any key, auth or not).
**Gotcha**: the show/hide elements must be **siblings**, not nested — a
hidden parent hides its children regardless of their own state.

#### Tier 3 — Magic-link

Magic-link sign-in: the user types their email, the auth API mails a
single-use code, the user clicks the link, lands on `/auth/magic/<code>`,
the page automatically exchanges the code for a real session token. No
password.

**Why a code, not the token directly.** Putting the actual session token
in the URL leaks via email forwarding, browser history, corporate HTTPS
proxy logs, and mail-client link prefetchers (many prefetch links for
preview thumbnails, "consuming" the token before the user clicks). The
URL value is a single-use code; the page exchanges it for the real token
immediately on page load.

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

**The verb family** (qs.js):

| Verb | Purpose | Typical chain |
|---|---|---|
| `QS.exchangeMagicLink(endpoint, paramName, returnTo?)` | Landing-page exchange. Reads `QS.routeParams[paramName]` (populated by the path matcher — see [ARCHITECTURE §5.3](ARCHITECTURE.md)), POSTs `{key:<code>}`, stores response in `QS._lastFetchResult`. Dispatches `qs:auth:exchange-started` before fetch + `qs:auth:exchange-failed` in catch so the `magic-link-handler` component's `data-auth-show="connecting"` / `"failed"` UI morphs. | `exchangeMagicLink` → `saveToken` × 2 → `redirect` |
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

- **No built-in `/auth/*` reservation** — you author the routes yourself. The conflict-detection in `addRoute` is the safety rail; conflicting param routes warn at create time.
- **Auto-redirect interplay** — `exchangeMagicLink`'s `returnTo` arg queues navigation IMMEDIATELY on success. Chained `saveToken` calls still run before the browser processes the navigation (they're sync, in the same microtask), but chained verbs that are themselves async would race. Keep auth-side state changes in synchronous `saveToken` calls; don't chain another async verb between exchange and redirect.
- **Email enumeration oracle** — your `requestMagicLink` server-side endpoint should ALWAYS return 200, even for unknown emails. Don't let attackers probe your user list. Auth-API responsibility, not the verb's.

| Concern | Where |
|---|---|
| Runtime verbs | `public/scripts/qs.js` (search for `QS.exchangeMagicLink`, `QS.requestMagicLink`, `QS.logoutServer`) |
| Catalog metadata | `secure/src/functions/qsVerbCatalog.php` |
| Async-chain wrapping | `secure/src/classes/JsonToHtmlRenderer.php` `CHAIN_AWAITABLE` |
| Lifecycle events | `qs:auth:exchange-started` / `qs:auth:exchange-failed` on `document`; cleared by `qs:auth:saved` / `qs:auth:cleared` |

#### Tier 4 — OAuth

OAuth 2.0 Authorization Code + PKCE flow with provider-side identity
(Google / Meta / Amazon / GitHub presets shipped; authors add others).
Server-side token custody — provider tokens never reach the browser.

**When to use OAuth vs Tier 3 magic-link**:

- **OAuth** when you want "Sign in with Google / GitHub / Meta / Amazon"
  branded buttons backed by the provider's identity. Lets users sign in
  with accounts they already have. Server-side session, ~14-day default.
- **Magic-link (Tier 3)** when you own the identity store (your own
  user database, your own email sender). Browser-side token, you
  control everything end-to-end.

The two coexist — same site can offer both.

**Token custody — BFF (Backend-For-Frontend)**: the OAuth flow's
provider tokens (`access_token`, `refresh_token`) are kept server-side
after the code exchange. The browser receives a first-party
`HttpOnly; Secure; SameSite=Lax` session cookie (`qs_oauth_user`)
mapping to a server-side session record. Provider tokens never reach
JavaScript — closes the XSS exfil surface that
browser-localStorage-stored OAuth tokens create. Locked design (see
DESIGN_DECISIONS.md "OAuth token custody"); the IETF "OAuth 2.0 for
Browser-Based Apps" BCP and every major provider recommend this
pattern for confidential web-server clients.

**Setting up a provider — 3 steps**:

1. **Preset** — engine catalogue at `secure/admin/config/oauth-presets.json`
   ships Google / Meta / Amazon / GitHub + a test-oauth fixture. To
   add a custom provider, append an entry there (see `_schema` for
   the field list). Per-project overrides land at
   `secure/projects/<active>/data/oauth-presets.json` — same shape,
   wins over admin per-provider (full-entry replace, not field
   merge). Useful when one project wants extra scopes or a custom
   provider not in the engine catalogue.
2. **Credentials** — copy `secure/admin/config/oauth-secrets.php.example`
   to `oauth-secrets.php` (gitignored), fill in
   `client_id` + `client_secret` for each provider you use. Most
   projects use per-project credentials instead — drop a
   `secure/projects/<active>/data/oauth-secrets.json` with the same
   shape. Per-project wins over admin. Real-world: each project
   registers its own OAuth app with each provider (different
   `client_id` per project, blast-radius isolation).
3. **Button + routes** — open the visual editor on a page, click
   "Add Element" → **Sign in with OAuth**, pick a provider in the
   wizard. The wizard creates the start + callback routes
   (`/auth/oauth/<provider>/start` + `/callback`), attaches the
   `oauth-start` + `oauth-callback` resolvers, and emits the button
   in one pass. Re-running the wizard for the same provider on
   another page reuses the existing routes (with an explicit
   warning) and just adds the button.

**The flow** (no author interaction past step 3):

1. User clicks the button → 302 to
   `http://your-site/auth/oauth/<provider>/start`
2. `OAuthHandler::handleStart` generates state + PKCE verifier,
   stores them server-side, redirects to the provider's authorize URL
3. User logs in at the provider, approves the scope grant
4. Provider redirects to
   `http://your-site/auth/oauth/<provider>/callback?code=…&state=…`
5. `OAuthHandler::handleCallback` validates state, POSTs the code to
   the provider's token endpoint (client_secret_basic auth +
   code_verifier), fetches userinfo with the access_token, generates
   a session id, stores `{provider, sub, email, name, tokens}`
   server-side, returns a 302 to the homepage (or to the
   `?return=/path` query param if provided) + sets the
   `qs_oauth_user` cookie

Authors customise the post-login landing by setting the wizard's
"Redirect after login" field — appends `?return=/path` to the button's
href. Server-side sanitisation rejects off-site URLs (open-redirect
guard).

**Template helpers** (always available — loaded by init.php):

| Helper | Returns |
|---|---|
| `isOAuthLoggedIn(): bool` | true if the current request has a valid OAuth session |
| `getOAuthUser(): ?array` | `{provider, sub, email, name}` or null. **Identity-only** — access_token / refresh_token / scope are NEVER exposed to templates (BFF custody). |

Anonymous visitors (no `qs_oauth_user` cookie) trigger neither
session creation nor lookup cost — both helpers early-return.

```php
<?php if (isOAuthLoggedIn()): ?>
    <p>Welcome, <?= htmlspecialchars(getOAuthUser()['email']) ?></p>
<?php else: ?>
    <p>Please <a href="/auth/oauth/google/start">sign in</a></p>
<?php endif; ?>
```

**Logout**: drop an `oauth-logout` resolver onto any route (e.g.
`/auth/oauth/logout`, `/sign-out`). The dispatcher reads the
`qs_oauth_user` cookie → finds the session → POSTs the access_token
to the provider's `revoke_url` (when the preset declares one — Google,
Amazon, the test-oauth fixture all do; GitHub + Meta use non-RFC-7009
revoke flows so local-only logout there) → clears the server session
→ expires the cookie → redirects to `?return=/path` or `/`. Idempotent
(no-session → just expire the cookie, no errors). Provider field on
`oauth-logout` is optional — declared value acts as a sanity check
against the cookie's session.

**Failure-mode UX**: callback redirects to `returnTo` (or `/`) with
`?oauth_error=<code>` appended on:
`invalid_state` / `missing_code` / `token_exchange_failed` /
`userinfo_failed` / `userinfo_missing_sub`, plus the provider's own
`error` query param when the user denied consent (`access_denied`,
etc.). The author owns the UX layer on the landing page — read the
`oauth_error` query param and surface a sensible message.

**Third-party cookies** (Safari ITP / Firefox ETP): the cookies set
in the standard flow are FIRST-PARTY (user navigates directly to your
site for start + callback). The edge case where this breaks: your
site is EMBEDDED in a cross-origin iframe on another domain (partner
portal, embedded preview, etc.). Browsers may treat the cookies as
third-party and block them — user appears to log in but the callback
drops their session. Workarounds when embedding:

- Open the sign-in flow in a new tab / popup
  (`target="_blank"` on the button) so cookies are set first-party
- Use the [Storage Access API](https://developer.mozilla.org/docs/Web/API/Storage_Access_API)
  to request cookie access from the embedding context

The oauth-button wizard surfaces a collapsed "Third-party cookies
note" with this guidance so authors who embed don't get caught off-
guard.

**Touchpoints**:

| Aspect | File |
|---|---|
| Server-side handler | `secure/src/classes/OAuthHandler.php` |
| State + session storage | `secure/src/functions/oauthStateStore.php` (PHP-session-backed; swappable abstraction) |
| Resolver kind registration + validation | `secure/src/functions/resolverHelpers.php` (`oauth-start` / `oauth-callback` / `oauth-logout` in `RESOLVER_ALLOWED_KINDS`) |
| Dispatcher | `public/index.php` OAuth branch (substitutes `{:routeParam}` placeholders, dispatches to handleStart / handleCallback / handleLogout) |
| Provider presets | `secure/admin/config/oauth-presets.json` (admin catalogue) + per-project `data/oauth-presets.json` (override) |
| Provider credentials | `secure/admin/config/oauth-secrets.php` (admin fallback) + per-project `data/oauth-secrets.json` (primary) |
| Provider listing | `secure/management/command/listOAuthProviders.php` (union of admin + per-project, with per-provider setup status) |
| Visual element | `secure/src/classes/complexElements/OAuthButton.php` (builder) + `public/admin/.../contextual-complex/complex-oauth-button.js` (wizard) |
| Template helpers | `secure/src/functions/oauthStateStore.php` (`isOAuthLoggedIn`, `getOAuthUser`) — loaded globally by `public/init.php` |
| Locked design decisions | [docs/DESIGN_DECISIONS.md](DESIGN_DECISIONS.md) — OAuth section |

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
pages — is intentionally out of scope.

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

Both patterns are demonstrated in the test pages `test/paged` (offset) and `test/state` (cursor).

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
splits).

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

Single-resolver routes stay scalar; the runtime accepts both shapes via
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

The **auth-cacheable rule**:

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
| Resolver fails + **config bug** (endpoint missing from registry, `apiKey` not set, `callableFrom: client` server-side call, etc.) | Any resolver fails this way | **500** — verbose inline page surfacing the misconfig (route, error message, fix hint). |

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
  expose key, editable as text inputs. Schema-driven defaults pre-fill
  the expose inputs from the endpoint's `responseSchema` when one is
  declared.
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

### 9.9 Verb picker (interactions, page events, complex wizards)

The structured authoring UI for every `{{call:verb:args}}` interaction in
the admin. Lives in `preview-js-interactions.js`. This section documents
the surface — the catalog metadata + the picker UI contract. See
[docs/DESIGN_DECISIONS.md](DESIGN_DECISIONS.md) "Picker overhaul" for the
design rationale + alternatives weighed.

**Where the picker appears**

- **Interactions panel** (element-level) — Add Interaction form, both the
  verb dropdown and per-arg inputs.
- **Page events panel** (page-level) — same shape, parallel form
  (`peForm*` references in the JS).
- **Complex element wizards** — wizards that author chained verbs
  internally (e.g. magic-link landing page presets) reuse the same
  `_createArgRow` helper.

#### 9.9.1 Verb categorisation

Each entry in `secure/src/functions/qsVerbCatalog.php` declares an
optional `category` field. The picker groups verbs by category in a
locked render order (most-authored first):

| Category | Display label | Verbs (today's catalog) |
|---|---|---|
| `dom-toggle` | DOM toggles | show, hide, toggle, toggleHide, addClass, removeClass, setValue |
| `form` | Forms | validate |
| `fetch` | Fetch / network | fetch |
| `auth` | Auth | saveToken, clearToken, refresh, exchangeMagicLink, requestMagicLink, logoutServer |
| `nav` | Navigation | redirect, scrollTo |
| `state-store` | State stores | setState, fetchState, onScrollFetchState |
| `focus` | DOM focus | focus, blur |
| `display` | Rendering / display | filter, renderList |
| `general` | General | toast (intentionally cross-cutting) |
| *(missing)* | Uncategorized | *defensive bucket — verb forgot to declare* |

The `general` bucket is INTENTIONAL placement for cross-cutting
utilities. The `uncategorized` bucket is a DEFENSIVE fallback, rendered
LAST so authors notice that the verb forgot to declare a category.

#### 9.9.2 Search-as-you-type (QSSearchableSelect)

Every dropdown in the picker (verb selection, apiEndpoint, route,
routeParam, translationKey) is wrapped with `QSSearchableSelect` — a
reusable combobox primitive at
`public/admin/assets/js/core/searchable-select.js`. Behaviours:

- Wraps a native `<select>` (kept in DOM as data store, visually hidden).
  All existing code that reads `.value`, listens to `change`, or
  inspects `<option>` data-attributes keeps working.
- Trigger button shows the current value + chevron; clicking opens a
  dropdown with a search input at the top.
- **Search matches**: case-insensitive substring against option
  `value`, `textContent`, AND `data-description` (either field counts).
  Empty query shows everything.
- Optgroups become labeled categories; empty groups hidden when
  filtering; empty result shows `No matches for "<query>"`.
- Keyboard nav: ArrowUp/Down move focus, Enter selects, Escape closes
  + returns focus to trigger.
- Dropdown uses `position: fixed` to escape `overflow:hidden` parents.
  Width matches trigger exactly. Flips upward when trigger is low on
  the viewport. Re-anchors after every filter so a 1-item visible list
  hugs the trigger.

`min-width: 200px` applied globally so short labels don't collapse the trigger.

#### 9.9.3 Event filtering

Each verb in `qsVerbCatalog.php` declares an `events: [...]` array of
DOM events it's meant for (`onclick`, `onsubmit`, `onload`, …). The
picker filters the verb dropdown by the currently-edited event:

- `onScrollFetchState` declares `events: ['onload']` → only appears when
  authoring `onload`.
- `exchangeMagicLink` declares `events: ['onload']` → page-event only.
- `redirect` declares `events: ['onclick', 'ondblclick', 'oncontextmenu',
  'onkeydown', 'onkeyup']` → element-interaction events.

A **Show all** checkbox above the verb picker overrides the filter for
the unusual cases (e.g. authoring a custom event chain). The filter
runs in `_filterFunctionsByEvent` (`preview-js-interactions.js`).

#### 9.9.4 inputType taxonomy — canonical reference

Each verb's arg can declare an `inputType` hint in
`secure/src/functions/qsVerbCatalog.php`. The catalog metadata IS the
dispatch table — `_createArgRow` in the picker JS reads
`arg.inputType` and routes to the matching handler.

This table is the canonical inputType reference; the consumer columns
(picker UI + metadata fields + example) are everything an author or
maintainer needs to know about a given inputType. The server-side
validator (`interactionHelpers.php::validateInteractionArgs`) honours
these hints too — today checks required-arg presence and the
`routeParam` constraint that values must match the page's `:name`
segments.

| inputType | Picker UI | Metadata fields | Used by (today's verbs) |
|---|---|---|---|
| *(default)* | Plain `<input type="text">` | — | Any arg without an inputType hint |
| `selector` | Searchable autocomplete from page's `#id` / `.class` / tag tokens | — | show.target, hide.target, focus.target, scrollTo.target, … |
| `class` | Searchable autocomplete from page's classes (no leading `.`) | — | show.hideClass, addClass.className, … |
| `eventArg` | Auto-injected hidden input (the `event` keyword arg) | — | validate.event |
| `matchTarget` | GitHub-style tokenfield — selectors OR attribute names | — | filter.matchAttr |
| `enum` | Native `<select>` from arg `options` | `options: [string,…]` (required), `default` (optional) | scrollTo.behavior, toast.type, saveToken.storage, clearToken.storage |
| `store` | Native `<select>` of current page's state-store IDs | — | setState.storeId, fetchState.storeId, onScrollFetchState.storeId |
| `api` | QSSearchableSelect of `@apiId` (no endpoint segment) | — | refresh.apiRef |
| `apiEndpoint` | QSSearchableSelect of `@apiId/endpointId` | — | exchangeMagicLink.endpoint, requestMagicLink.endpoint, logoutServer.endpoint |
| `route` | QSSearchableSelect of project routes; with `allowExternal: true`, a `Custom URL…` sentinel swaps the row to free-text input | `allowExternal: bool` (default `false`) | redirect.url *(allowExternal)*, exchangeMagicLink.returnTo, requestMagicLink.returnTo |
| `routeParam` | QSSearchableSelect of `:name` segments extracted from the current page's route slug | — | exchangeMagicLink.paramName |
| `translationKey` | `QSComplexWizard.createTextKeyPicker` (searchable tree + inline "Create new key" form); with `allowFreeText: true`, a `Custom text…` sentinel swaps the row to free-text input. Catalog-flagged positional args also get **compile-time resolution** at render (§9.9.7) | `allowFreeText: bool` (default `false`) | toast.message *(allowFreeText)* |

#### 9.9.5 Hybrid-picker pattern (`allowExternal` / `allowFreeText`)

Two inputType handlers carry a per-arg flag that opts in to a "Custom
…" sentinel — a button at the top of the dropdown that swaps the row
from picker mode to a free-text input + a `←` back button.

- `route` + `allowExternal: true` (today: `redirect.url`) — picker shows
  registered routes; the `Custom URL…` sentinel lets the author type
  any URL (external, anchor, scheme-prefixed). Picker mode is the
  default; back button returns to it.
- `translationKey` + `allowFreeText: true` (today: `toast.message`) —
  picker shows the project's translation keys; the `Custom text…`
  sentinel lets the author type a raw string for one-off / debug
  toasts. Back button returns to the picker.

The hybrid resolves the typo-vs-flexibility tension: typing the URL
or raw string is one click away, but the picker is the primary path.

Edit pre-fill auto-swaps to custom mode based on a heuristic:
- `allowExternal`: value doesn't start with single `/` → custom mode.
- `allowFreeText`: value doesn't match dotted-identifier pattern OR
  contains whitespace → custom mode.

The auto-swap means re-editing an interaction with a saved
`redirect.url = "https://other.com"` correctly shows the URL in custom
mode + pre-filled (vs. dumping it as a "(legacy)" picker option).

#### 9.9.6 Required-arg validation

Save is blocked when any `required: true` catalog arg has an empty
value at its positional index. Two-layer check:

**Client side** — `_validateRequiredArgs` reads `availableFunctions`
(the same catalog the picker uses) before building the POST payload.
On failure:
- Red border on each offending input
  (`.preview-contextual-js-form-input--error`).
- Inline chip below each offending row
  (`⚠ <argName> is required`).
- Summary toast: `Missing required parameter(s): <names>`.
- Form stays open; save did not fire.

Marks lift on first edit of the offending field (one-shot `input` /
`change` listener attached when the marks are painted).

**Server side** — `validateInteractionArgs` in
`secure/src/functions/interactionHelpers.php` reads `qsVerbCatalog()`
and walks the same argspec. Wired into `addInteraction`,
`editInteraction`, `addPageEvent`, `editPageEvent`. Returns `400` with
field-level `withErrors([{field, index, reason, hint}, ...])` when a
required arg is empty, `422` when the verb itself is unknown to the
catalog. Defense in depth for direct API callers and batch imports.

#### 9.9.7 Compile-time translation (catalog-driven)

When a verb arg declares `inputType: 'translationKey'`, the saved
value is stored as a translation key (e.g.
`{{call:toast:hello.world,info,4000}}`). At render time,
`JsonToHtmlRenderer::buildQsCallJs` reads the catalog, sees that the
`message` arg of `toast` carries the translationKey hint, and
substitutes the per-language string before the chain is compiled into
the JS attribute:

```
en.json:  hello.world = "Hello world"
fr.json:  hello.world = "Bonjour le monde"
```

Rendered output (English request):
```html
<button onclick="QS.toast('Hello world', 'info', '4000')">…</button>
```

Rendered output (French request):
```html
<button onclick="QS.toast('Bonjour le monde', 'info', '4000')">…</button>
```

The source JSON is identical for both languages — multilingual works
natively. Custom Text mode (when `allowFreeText: true`) passes
through unchanged: a raw string like `"Hello world!"` triggers
`Translator::translate`'s missing-marker, which the resolver falls
back from to the raw value.

Mirrored in `JsonToPhpCompiler::transformCallSyntax` for the build
path. Future verbs gain compile-time translation by declaring the
inputType in the catalog — zero renderer code changes.

#### 9.9.8 Files

| Concern | Where |
|---|---|
| Catalog (single source of truth) | `secure/src/functions/qsVerbCatalog.php` |
| Picker JS (verb + arg rendering, validation, save) | `public/admin/assets/js/pages/preview/preview-js-interactions.js` |
| Combobox primitive | `public/admin/assets/js/core/searchable-select.js` |
| TranslationKey picker primitive | `public/admin/assets/js/pages/preview/contextual-complex/text-key-picker.js` |
| Route datalist primitive (complex wizards) | `public/admin/assets/js/pages/preview/contextual-complex/route-input.js` |
| CSS (picker + hybrid layouts + validation styles) | `public/admin/assets/admin.css` (search for `.qs-searchable-select`, `.qs-route-picker`, `.qs-translation-key-picker`, `.preview-contextual-js-form-input--error`) |
| Server validator | `secure/src/functions/interactionHelpers.php` (`validateInteractionArgs`, `routeParamsForPageSlug`) |
| Save commands wired | `secure/management/command/addInteraction.php`, `editInteraction.php`, `addPageEvent.php`, `editPageEvent.php` |
| Compile-time translation | `secure/src/classes/JsonToHtmlRenderer.php` (`getTranslatablePositionalIndices`, `resolveTranslationKeyOrFallback`) + `JsonToPhpCompiler.php` |
| Inputs catalogue | [COMMAND_API.md "Catalog inputType reference"](COMMAND_API.md) |

#### 9.9.9 Authoring a new inputType

Adding a new inputType is a four-touchpoint change:

1. **Catalog** — declare it on the relevant arg in
   `secure/src/functions/qsVerbCatalog.php` (`'inputType' => 'newType'`
   + any metadata fields like `options`, `allowExternal`,
   `allowFreeText`).
2. **Picker JS** — add a branch in `_createArgRow`
   (preview-js-interactions.js) that calls a `_renderNewTypeArgRow()`
   helper. Helpers return the visible row element; the param
   collector reads from a `.preview-contextual-js-form-input`-classed
   descendant.
3. **Server validator** *(if inputType-specific constraints apply)* —
   extend `validateInteractionArgs` in
   `secure/src/functions/interactionHelpers.php` with a new `reason`
   (e.g. `invalid_<type>`) and the constraint check.
4. **Docs** — update the table in §9.9.4 above (this is the canonical
   reference; no parallel table exists in COMMAND_API.md). Append a
   `DESIGN_DECISIONS.md` entry under "Picker overhaul" capturing why
   this new type instead of overloading an existing one.

The minimum-viable new inputType is text-only — no metadata fields,
no validation; just a rendered picker.

---

## 10. Data attribute reference

QuickSite's runtime understands a small set of `data-*` attributes that
turn plain HTML elements into bindings (state-store readers, auth-state
toggles, storage value displays, complex-element markers, …). Every
attribute on this page is **catalogued in
`secure/src/functions/qsDataAttributeCatalog.php`** — the single source
of truth read by `GET /management/listDataBindings` and by the in-editor
autocomplete + smart widgets, available on **both** the Add Element
wizard's Advanced custom-params section AND the Edit Params surface
(action panel button next to Add / Duplicate). The same picker —
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
  → VALUE field: type "people.nextPage" (smart-widget store/field picker
    auto-renders when the attribute's value shape is `store-field-ref`)
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
  → Companion hint: "No data-auth-source found on element
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
delete + recreate.

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
autocomplete surfaces them as "+ Add companion" hints.

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
automatically. Same pattern as `qsVerbCatalog.php`.

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
