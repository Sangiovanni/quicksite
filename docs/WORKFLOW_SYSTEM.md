# QuickSite Workflow System — Complete Reference

> Reference for understanding, authoring, and debugging workflows.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Files Involved](#files-involved)
4. [Workflow Types](#workflow-types)
5. [Adding a workflow](#adding-a-workflow)
6. [JSON Spec Reference](#json-spec-reference)
7. [Markdown Template Reference](#markdown-template-reference)
8. [Execution Flow](#execution-flow)
9. [Condition Syntax](#condition-syntax)
10. [Known Patterns & Gotchas](#known-patterns--gotchas)

---

## Overview

The workflow system orchestrates multi-step operations — either manual (predefined API commands executed in sequence) or AI-assisted (a markdown prompt is rendered with live data, sent to an AI, and the AI's JSON response is executed as commands).

Every workflow has two possible components:
- **JSON spec** (`.json`) — defines the workflow: metadata, parameters, data requirements, steps, and/or a prompt template reference
- **Markdown template** (`.md`) — the AI prompt, rendered with dynamic data from the spec

A workflow can have steps (manual), a promptTemplate (AI), or both.

---

## Architecture

```
User fills parameters in the AI tools panel
        │
        ▼
┌─────────────────────────────────────┐
│         WorkflowManager.php         │
│                                     │
│  1. loadWorkflow(id)                │
│  2. fetchDataRequirements()         │
│     → CommandRunner executes        │
│       read-only API commands        │
│       (re-gated per command)        │
│  3a. generateSteps()    [manual]    │
│  3b. renderPrompt()     [AI]        │
└─────────────────────────────────────┘
        │                    │
    [manual]              [AI]
        │                    │
        ▼                    ▼
  Steps in the         Rendered markdown
  Batch panel          → sent browser-direct (BYOK)
        │                or copied out + pasted back
        │              → JSON response parsed
        │                    │
        └────────┬───────────┘
                 ▼
   Each command POSTed to /management/p/<projectId>/<command>
   in turn, with the caller's session cookie + bearer token
```

---

## Files Involved

### Core Engine

| File | Purpose |
|------|---------|
| `<secure>/src/classes/WorkflowManager.php` | Core orchestrator — loading, data fetching, step generation, prompt rendering, spec validation |
| `<secure>/src/classes/CommandRunner.php` | Executes API commands internally (used by `fetchDataRequirements`) |

### Workflow Definitions

| Path | Content |
|------|---------|
| `<secure>/admin/workflows/schema.json` | JSON Schema defining the spec format |
| `<secure>/admin/workflows/core/*.json` | Workflow specs. **The only directory the engine reads** — a workflow you add goes here, beside the shipped ones. |
| `<secure>/admin/workflows/core/*.md` | Markdown templates, beside the spec that names them |
| `<secure>/admin/workflows/blocks/*.md` | Reusable prompt blocks injected via `{{> name}}` |
| `<secure>/admin/workflows/pins/*.md` | Pinned reminders auto-injected at top when listed in `pins: [...]` |
| `<secure>/admin/workflows/warnings/*.md` | Warnings auto-injected at top when listed in `warnings: [...]` |
| `<secure>/admin/workflows/examples/*.md` | Example fragments referenced via `{{> example.X}}` |

**Workflows are extensible by file.** There is no in-app authoring surface and no
authoring command: a workflow *is* a JSON spec, plus a Markdown template beside it
when it drives an AI. Anyone with file access to the installation can add one —
see *Adding a workflow* below. The panel runs workflows; it does not create them.

### Admin UI

Workflows are run from the visual editor's **AI tools** mode
(`/admin/preview` → AI tools). Any `/admin/workflows*` URL redirects there.

| File | Purpose |
|------|---------|
| `<secure>/admin/templates/pages/preview/contextual-ai-tools.php` | DOM scaffold — workflow list + runner |
| `public/admin/assets/js/pages/preview/preview-ai-tools.js` | Client-side logic (list, filters, parameter forms, streaming, batch execution) |
| `public/admin/assets/js/pages/ai/lib/ai-call.js` | Browser-direct AI dispatch (BYOK) — no PHP proxy |

See [ADMIN_PANEL.md §8.12](ADMIN_PANEL.md) for the runner UX.

### API Endpoints (via admin router)

The helper endpoints live in `public/admin/api/index.php`. All three are
project-scoped, so the project marker is **required**, not optional — a call
without it is refused with `400`:

```
/admin/api/p/<projectId>/<action>
```


| Action | Method | Purpose |
|----------|--------|---------|
| `ai-spec/<id>` | GET | Load a spec with its data requirements fetched and its prompt rendered |
| `ai-spec-raw/<id>` | GET | Load the spec definition itself (parameter schema, metadata) |
| `workflow-generate-steps` | POST | Resolve steps for deterministic workflow execution |

Resolved commands are **not** executed through a batch endpoint. The client runs
them one at a time against the Management API — `POST
/management/p/<projectId>/<command>`, carrying the session cookie and the
caller's bearer token — so every step passes the same authorization as any other
API call. The marker is part of the base URL the panel hands the runner, which is
why a step never names a project itself.

---

## Workflow Types

### Manual Workflows

Have `steps` array, no `promptTemplate`. Steps are predefined API commands with parameter placeholders.

**Example:** `fresh-start.json` — deletes all routes, assets, languages, components, then resets styles. Each step is a concrete command with `forEach` loops over live data.

**Use case:** Deterministic operations that don't need AI creativity.

### AI Workflows

Have `promptTemplate` pointing to a `.md` file. The markdown is rendered with live system data and user parameters, then the user sends it to an AI assistant.

**Example:** `create-website.json` + `create-website.md` — fetches help docs for relevant commands, renders a detailed prompt with conditionals based on whether multilingual is enabled.

**Use case:** Creative operations where AI generates the command sequence (page structures, styles, content).

### Hybrid Workflows

Have both `steps` AND `promptTemplate`. The steps provide a fallback or complementary manual flow.

### Sub-Workflows

Workflows can reference other workflows via `preWorkflows` and `postWorkflows`:

```json
"preWorkflows": ["fresh-start"],
"postWorkflows": [
    {
        "id": "update-lang-switch",
        "condition": "param.multilingual === true",
        "params": { "languages": "{{param.languages}}" }
    }
]
```

Sub-workflows are fully resolved (their own data requirements fetched, steps generated) and injected before/after the main steps.

---

## Adding a workflow

A workflow is one or two files on disk. Nothing else registers it — no command,
no database row, no build step. Drop the files in and the panel picks them up on
the next page load.

Both files go in the same directory, and it is the only one the engine reads:

```
<secure>/admin/workflows/core/my-workflow.json     the spec  — always
<secure>/admin/workflows/core/my-workflow.md       the template — AI workflows only
```

The spec's filename **is** the workflow id the engine looks up, so
`my-workflow.json` must declare `"id": "my-workflow"`.

### The smallest thing that works

This is `global-design`, shipped under `core/`, quoted whole. It is an AI
workflow with no parameters — the least a real spec can carry:

```json
{
    "$schema": "../schema.json",
    "id": "global-design",
    "version": "1.0.0",
    "meta": {
        "icon": "🎨",
        "titleKey": "ai.specs.globalDesign.title",
        "descriptionKey": "ai.specs.globalDesign.description",
        "category": "style",
        "tags": ["design", "colors", "variables", "theme"],
        "difficulty": "beginner"
    },
    "parameters": [],
    "dataRequirements": [
        {
            "id": "rootVariables",
            "command": "getRootVariables",
            "extract": "data.variables"
        },
        {
            "id": "helpSetRootVariables",
            "command": "help",
            "urlParams": ["setRootVariables"],
            "extract": "data"
        }
    ],
    "relatedCommands": ["setRootVariables"],
    "promptTemplate": "global-design.md"
}
```

Required: `id`, `version`, and a `meta` carrying `icon`, `category`, and one of
`name` or `titleKey` — plus either `steps` or `promptTemplate`. Everything else
above is optional.

⚠ `schema.json` lists only `icon` and `category` as required inside `meta`, so an
editor validating against the schema will accept a spec with no name. The engine
will not: it refuses one at render time. Give every workflow a `name`.

⚠ **Use `name` and `description`, not `titleKey` and `descriptionKey`.** The
shipped workflows use translation keys because their text lives in the admin
locale files. A key that resolves to nothing falls back to the workflow's raw id,
so your own workflow will display as `my-workflow` until you add translations.
Direct strings need none:

```json
"meta": { "icon": "🎨", "name": "My Workflow", "description": "What it does", "category": "style" }
```

### How the template consumes what the spec declares

This is the whole contract between the two files. Every reference in the
template resolves against something the spec named:

| In `global-design.md` | Comes from |
|---|---|
| `{{json rootVariables}}` | the `dataRequirements` entry with `"id": "rootVariables"` |
| `{{> command.setRootVariables}}` | the name listed in `relatedCommands` |
| `{{> output-json-only}}` | `<secure>/admin/workflows/blocks/output-json-only.md` |
| `{{> example.global-design-output}}` | `<secure>/admin/workflows/examples/global-design-output.md` |

So the rule is: **a template can only reach data the spec asked for.** Adding
`{{json somethingElse}}` to the template does nothing unless a
`dataRequirements` entry with that `id` fetches it first. The same holds in the
other direction for manual workflows — a step's `forEach` and `{{data.x}}`
placeholders resolve against `dataRequirements` ids, not against arbitrary names.

### Steps 1 to 5

1. **Write the spec.** Copy a shipped one whose shape matches what you want —
   `global-design.json` for an AI workflow, `setup-theme-switch.json` for a
   manual one — and change the `id` to match your filename. Point `$schema` at
   `../schema.json` so an editor that understands JSON Schema will complete and
   check the shape as you type.

2. **Declare the data you need.** Each `dataRequirements` entry runs one
   read-only command through the workflow engine before anything renders. Its
   `id` is the name your template and steps will use. `extract` picks a path out
   of the response — without it you get the whole `data` object.

3. **Write the template** (AI workflows only), named exactly as `promptTemplate`
   says, in the same directory. Reference your data requirements by their ids.
   `{{> command.X}}` inlines the API documentation for command `X`, which is the
   most reliable way to tell an AI what a command actually accepts.

4. **List `relatedCommands`.** Every command the workflow will end up calling.
   This is what decides whether the workflow is offered to a given role — leave a
   command out and the workflow is shown to people who cannot run it.

5. **Reload the panel and run it.** Open the visual editor, switch to **AI
   tools**, and your workflow appears in the list under its category. Running it
   is the check — see below.

### How you know it worked

**There is no validator, no linter and no dry run.** The only check the product
offers is running the workflow and seeing whether it does what you intended. That
is deliberate: a spec is only correct with respect to what you wanted, and
nothing but a run can tell you that.

What the engine does check, and what it does not, decides where a mistake shows
up:

| Mistake | Where you find out |
|---|---|
| The JSON does not parse, or has no `id` | The workflow never appears in the list at all. |
| The shape is wrong — no `category`, a parameter with no `type`, neither `steps` nor `promptTemplate` | The card appears, and running it answers with the validation error list. |
| A `dataRequirements` command name is misspelt, or your role cannot run it | The run fails on that data requirement. |
| A template references data no `dataRequirements` entry fetched | The placeholder renders empty. |
| A partial name is misspelt | `{{> yourtypo}}` appears verbatim in the rendered prompt, and a line goes to the server's PHP error log. |
| A step calls a command your role lacks | That step is refused when it executes; earlier steps have already run. |

⚠ **A manual workflow's steps are not a transaction.** They execute one at a
time against the Management API, and a failure part-way leaves the earlier
commands applied. Test a destructive workflow on a project you can throw away, or take a backup
first — the shipped `fresh-start` deletes every route, asset, language and
component before it resets styles.

---

## JSON Spec Reference

### Required Fields

```json
{
    "id": "my-workflow",           // kebab-case, unique
    "version": "1.0.0",           // semver
    "meta": {
        "icon": "🎯",             // emoji for display
        "name": "My Workflow",     // display name (alternative to titleKey)
        "category": "advanced"     // see categories below
    }
}
```

> Core workflows use `titleKey`/`descriptionKey` (i18n translation keys) instead of `name`/`description`.

### Categories

`creation` | `modification` | `content` | `style` | `advanced` | `wip` | `template`

### Optional Fields

#### `meta.description` / `meta.descriptionKey`
Short description shown in the workflow browser.

#### `meta.tags` (array of strings)
For filtering/search in the browser. Example: `["create", "website", "multi-page"]`

#### `meta.difficulty`
`beginner` | `intermediate` | `advanced` — shown as a visual indicator.

#### `parameters` (array)
User input form fields. Each parameter:

```json
{
    "id": "languages",                    // referenced as {{param.languages}}
    "type": "text",                       // see types below
    "label": "Language",                  // direct text
    "labelKey": "specs.params.lang.label",// OR translation key
    "placeholderKey": "...",              // optional
    "helpKey": "...",                     // optional help text
    "default": "en",                     // optional default value
    "required": true,                    // optional
    "condition": "multilingual === true", // show/hide based on other param
    "validation": {                      // optional constraints
        "minLength": 2,
        "maxLength": 100,
        "pattern": "^[a-z]+"
    }
}
```

**Validation constraints**:
- `minLength` / `maxLength` — character count limits (for `text` and `textarea`)
- `pattern` — a JavaScript regular expression the value must match (for `text`). The browser anchors it automatically; for example, the pattern in the snippet above restricts input to lowercase letters only.
- `min` / `max` — numeric range limits (for `number`)
- `minItems` / `maxItems` — array-length limits (for `tag-select`)

Hidden parameters (those failing their own `condition`) skip validation entirely — useful for "Languages must contain ≥2 entries, but only when Multilingual is checked" style rules.

**Parameter types:**
- `text` — single-line input
- `textarea` — multi-line input
- `select` — dropdown (needs `options` or `optionsFrom`)
- `tag-select` — multi-select (needs `optionsFrom`; backed by an array value)
- `checkbox` — boolean toggle
- `number` — numeric input (renders `<input type="number">`)
- `nodeSelector` — visual node picker from page structures
- `selector` — auto-fills from the visual editor's current iframe selection. Read-only display in the AI tools panel; the param value is a structured object `{ tag, classes, struct, node }`. Workflow steps reference subfields with `{{param.X.tag}}`, `{{param.X.struct}}`, etc.
- `hidden` — not shown in UI, value set programmatically

**Default values from data**: `default` accepts either a literal value or a template string referencing fetched `dataRequirements`. For example:

```json
{
    "id": "languages",
    "type": "tag-select",
    "default": "{{data.langData.languages}}"
}
```

`WorkflowManager` resolves the template against the fetched data before serving the spec to the UI, so the parameter initializes with live system state. Use this to make workflows context-aware — e.g. preselect the project's current languages, current default route, current theme — instead of static seeds.

**Static options:**
```json
"options": [
    { "value": "en", "label": "English" },
    { "value": "fr", "labelKey": "specs.params.french" }
]
```
Each option has `value` and either `label` (direct text) or `labelKey` (translation key).

**Dynamic options via `optionsFrom`:**
Populates a `select` from data fetched by `dataRequirements`.

```json
"optionsFrom": "routes"
```
Shorthand — uses the data array directly as values.

```json
"optionsFrom": {
    "data": "routes",
    "value": "path",
    "label": "title",
    "prepend": [{ "value": "", "label": "None" }],
    "filterFrom": "languages"
}
```
Full form — `data` references a `dataRequirements` ID, `value`/`label` pick fields from each item, `prepend` adds fixed options at the top.

`filterFrom` cascades the resolved options from another parameter's value: the option list is restricted to entries whose `value` appears in the referenced parameter's current selection. The cascade only applies when the referenced parameter is currently visible (its own `condition` is truthy) AND has a non-empty value — otherwise the full list is used. The auto-correct rule: when the cascade narrows the option set and the dependent's current value is no longer valid, the dependent flips to the first available option.

Example: setup-languages's `defaultLanguage` uses `filterFrom: "languages"`. With Multilingual unchecked, Languages is hidden, so the cascade is skipped and Default language shows all 40 languages. Check Multilingual and pick `[en, fr]` in Languages, and Default language re-renders with just English + Français.

**Conditional visibility:** `"condition": "multilingual === true"` — references another parameter's ID (not prefixed with `param.`).

#### `dataRequirements` (array)
System data fetched before execution via `CommandRunner`:

```json
{
    "id": "routes",                       // becomes {{data.routes}} or forEach source
    "command": "getRoutes",               // API command name
    "extract": "data.flat_routes",        // optional: extract nested path from response
    "params": {},                         // optional: command params
    "urlParams": ["addRoute"],            // optional: URL params (for help command)
    "condition": "multilingual === true"  // optional: skip if condition is false
}
```

The `extract` field navigates into the API response. Without it, the full `response.data` is stored.

#### `staticData` (object)
Hardcoded data available as `data.*` in steps and templates:

```json
"staticData": {
    "assetCategories": ["images", "font", "audio", "videos"]
}
```

#### `relatedCommands` (array of strings)
List of API command names. Used for two things:

1. **Deciding whether the workflow is listed at all.** The panel hides a workflow
   from anyone whose role does not grant every command in `relatedCommands` — and
   every command named by a `steps` entry. This is a visibility filter, not the
   enforcement: each command is authorized again when it actually runs, once by
   the workflow engine for a data requirement and once by the Management API for
   a step. A workflow that slips through the filter still fails at the refused
   command.
2. **Building command documentation into the prompt** — `{{> command.X}}`,
   `{{> command.$relatedCommands}}` and the `{{#each commands}}` context all draw
   on help data fetched for these names.

#### `promptTemplate` (string)
Filename of the markdown template (e.g., `"create-website.md"`). Loaded from the same folder as the JSON spec.

#### `steps` (array)
For manual workflows. Each step:

```json
{
    "command": "deleteRoute",
    "method": "POST",                     // default: POST
    "comment": "Remove all non-home routes", // optional, ignored during execution
    "params": {
        "route": "{{$value}}"             // resolved from context
    },
    "condition": "param.keepAssets !== true",
    "forEach": "routes",                  // iterate over data requirement
    "filter": "{{$value}} !== 'home'",    // filter items
    "abortOnFail": false,                 // continue on error
    "retryOn": [409],                     // retry on these status codes
    "maxRetries": 2,
    "retryDelayMs": 500
}
```

**Filter operators**:
- `===`, `!==`, `==`, `!=` — equality comparison on the forEach `$value` / `$value.field` / `$key`. Right side accepts literals, `{{$value}}` placeholders, or `{{path}}` references resolved against the context.
- `in`, `not_in` — membership check against an array. Left is a value, right is an array reference (typically `{{data.X}}` or `{{param.X}}`). The right side must resolve to an array; otherwise `in` returns false and `not_in` returns true (matches the intuitive read of an empty list).
- Multiple sub-expressions can be chained with `&&` (AND). All must pass for the item to be kept.

The `in` / `not_in` operators are the foundation of diff-style workflows. For example, a "remove only the languages the user un-picked" step in setup-languages:

```json
{
    "forEach": "data.langData.languages",
    "filter": "{{$value}} not_in {{param.languages}}",
    "command": "deleteLang",
    "params": { "code": "{{$value}}" }
}
```

Paired with the symmetric `not_in` step against `param.languages` filtered by `data.langData.languages`, this lets a workflow synchronise the project state with the user's selection — only adding new items and only deleting absent ones, leaving the rest untouched.

#### `preWorkflows` / `postWorkflows` (array)
Sub-workflows to run before/after. Can be a simple string ID or an object with `id`, `params`, and `condition`.

---

## Markdown Template Reference

Templates are processed by `WorkflowManager::renderPrompt()` through a **7-pass rendering engine**.

### Pass 0: Pin/Warning Auto-Injection

If the workflow JSON declares `pins: [...]` or `warnings: [...]`, the renderer prepends:

```markdown
## Pins

{{> pin.lang-switch}}

## Warnings

{{> warning.json-only}}

---
```

at the top of the template before any other pass. Each ID maps to a file under `<secure>/admin/workflows/pins/` or `<secure>/admin/workflows/warnings/`. Set `meta.suppressPinsHeader: true` to opt out (rare).

### Pass 0.5: Partials — `{{> name}}`

Reusable prompt blocks. Resolved BEFORE conditionals/loops, so an inlined block is itself a first-class template fragment (its `{{#if}}`, `{{param.x}}`, etc. all run normally).

| Form | Resolves to |
|---|---|
| `{{> blockname}}` | `<secure>/admin/workflows/blocks/blockname.md` |
| `{{> pin.X}}` | `<secure>/admin/workflows/pins/X.md` |
| `{{> warning.X}}` | `<secure>/admin/workflows/warnings/X.md` |
| `{{> example.X}}` | `<secure>/admin/workflows/examples/X.md` |
| `{{> command.X}}` | `formatCommandFull(X, helpCache[X])` — inline command docs |
| `{{> command.$relatedCommands}}` | One `formatCommandFull` per item in the workflow's `relatedCommands` list, joined by `---` |

Recursion is depth-limited (max 5 levels). Cycles are detected and broken. Missing or mistyped partials are LEFT INTACT in the rendered prompt — you see `{{> yourtypo}}` in the output rather than a silent gap — and a line is written to the server's PHP error log.

**`relatedCommands` decides who sees the workflow; `{{> command.X}}` decides what the prompt says.** Keep a command listed in `relatedCommands` even when you reference it only through a partial — otherwise the workflow is offered to people whose role cannot run it, and they find out when a step is refused.

### Pass 1: Conditionals — `{{#if}}`

```markdown
{{#if param.multilingual === true}}
Content shown only for multilingual workflows.
{{else}}
Content shown for single-language workflows.
{{/if}}
```

**Supported in conditions:**
- `param.xxx` — user parameter values
- `data.xxx` — fetched data values
- Bare references — checked in fetchedData, then userParams
- Operators: `===`, `==`, `!==`, `!=`, `>`, `>=`, `<`, `<=`
- Negation: `{{#if !param.skipStyles}}`
- Boolean normalization: `"true"`, `"on"`, `"1"` → `true`

**Note:** No `&&` / `||` support in template conditionals (unlike step conditions). Use nested `{{#if}}` blocks instead.

### Pass 2: Loops — `{{#each}}`

```markdown
{{#each data.languages}}
- {{@key}}: {{this}}
{{/each}}
```

**Loop sources:**
- `data.xxx` — any data from `dataRequirements`
- Bare names — checked in `fetchedData`
- `commands` — superseded by the partial form, see below

**Loop variables:**
- `{{@key}}` — current key (index for arrays, key for objects)
- `{{this}}` — current value (formatted)
- `{{this.field}}` — access object property

**Superseded form:**

```markdown
{{#each commands}}{{formatCommand @key this}}{{/each}}   <!-- deprecated -->
```

Use the partial form instead:

```markdown
{{> command.$relatedCommands}}                            <!-- canonical -->
```

or for inline insertion of a single command's docs interleaved with prose:

```markdown
First, add a route:

{{> command.addRoute}}

Then wire styles:

{{> command.editStyles}}
```

The partial form routes to `formatCommandFull()` which produces cleaner markdown (description, method, parameters with types, one fenced example). The `{{formatCommand}}` helper still works, but emits an HTML comment marking it deprecated into the rendered prompt and writes a notice to the server's PHP error log.

### Pass 3: JSON Export — `{{json}}`

```markdown
```json
{{json rootVariables}}
```⁠
```

Outputs `json_encode($fetchedData[key], JSON_PRETTY_PRINT)`. Useful for showing current system state to the AI.

### Pass 4: Data References — `{{data.xxx}}`

```markdown
Current language: {{data.langData.defaultLang}}
```

Accesses `$fetchedData['langData']['defaultLang']`. Supports arbitrary depth (e.g., `{{data.a.b.c.d}}`).

### Pass 5: Parameter References — `{{param.xxx}}`

```markdown
Languages requested: {{param.languages}}
Pages to create: {{param.pages}}
```

Direct substitution from user input.

### Pass 6: Helpers — `{{helpers.xxx}}`

```markdown
Generated on: {{helpers.date}}
```

Available helpers:
- `{{helpers.date}}` — `Y-m-d`
- `{{helpers.datetime}}` — `Y-m-d H:i:s`
- `{{helpers.timestamp}}` — Unix timestamp

### Pass 7: Bare References — `{{xxx}}`

```markdown
{{rootVariables}}
```

Fallback: checks `$fetchedData[key]`. Unknown placeholders are left as-is.

### Template Writing Tips

1. **Start with output rules** — tell the AI to output JSON only, no explanations
2. **Show the expected format** — `[{ "command": "...", "params": {...} }]`
3. **Use conditionals** for branches (multilingual vs single-language)
4. **Include current state** via `{{json dataId}}` so the AI knows what exists
5. **Show command docs** via `{{> command.$relatedCommands}}` (the `{{#each commands}}{{formatCommand @key this}}{{/each}}` form is deprecated)
6. **Be explicit about order** — AI needs to know command dependency order
7. **Add examples** — concrete JSON examples help AI accuracy dramatically

---

## Execution Flow

### AI Workflow Execution (in `preview/preview-ai-tools.js`)

```
1. User fills parameter form
2. Click the primary action button
3. GET /admin/api/ai-spec/{id}?<params>
   → WorkflowManager.renderPrompt() → returns markdown
4. Prompt goes to the AI — browser-direct via QSAiCall when a BYOK
   connection is configured, otherwise copied out and pasted back
5. Client validates the JSON response structure
6. Client normalizes commands (ensures correct format)
7. Each command is POSTed to /management/p/<projectId>/{command} in turn,
   results shown per-row in the Batch panel
```

### Manual Workflow Execution

```
1. User fills parameter form
2. POST /admin/api/workflow-generate-steps  { workflowId, params }
   → WorkflowManager resolves phases:
     a. getWorkflowPhases() → [{type: preWorkflow, id}, {type: main}, ...]
     b. For each phase: resolveSubWorkflow() 
        → fetchDataRequirements + generateSteps
   → Returns all expanded steps
3. Steps shown in the Batch panel
4. User clicks "Execute"
5. Same per-command execution against the Management API
```

### Step Generation Pipeline (manual workflows)

```
1. Merge staticData into fetchedData
2. Build context: { param, data, config }
3. Expand preWorkflows (recursive — sub-workflows can have their own pre/post)
4. For each main step:
   a. Evaluate condition → skip if false
   b. If forEach: expand into N steps (one per item)
      - Apply filter if present
      - Create item context with $key, $value, $item
   c. Resolve params: {{param.x}}, {{data.x}}, {{$value}}, etc.
   d. Resolve $each loops in params (for generating arrays)
5. Expand postWorkflows (same as pre)
6. Return flat array of resolved commands
```

---

## Condition Syntax

Conditions are used in multiple places with slightly different capabilities:

| Context | `&&` | `||` | `!` | Comparison ops | Supported paths |
|---------|------|------|-----|----------------|-----------------|
| Step conditions | ✅ | ✅ | ✅ | `=== == !== != > >= < <=` | `param.x`, `data.x`, `config.x` |
| Data requirement conditions | ✅ | ✅ | ✅ | `=== == !== != > >= < <=` | Direct param names |
| Template `{{#if}}` | ❌ | ❌ | ✅ | `=== == !== != > >= < <=` | `param.x`, `data.x`, bare |
| Parameter visibility | ❌ | ❌ | ❌ | `=== !==` | Direct param IDs |
| forEach filter | ✅ | ❌ | ❌ | `=== == !== !=` | `$key`, `$value`, `$value.field` |

### Value Normalization

Boolean-like strings are automatically normalized:
- `"true"`, `"on"`, `"1"` → `true`
- `"false"`, `"off"`, `"0"` → `false`

### Filter Syntax (step params)

```json
"params": {
    "langName": "{{$value | langname}}"
}
```

Available filters: `uppercase`/`upper`, `lowercase`/`lower`, `ucfirst`/`capitalize`, `ucwords`/`title`, `trim`, `langname`/`language`

---

## Known Patterns & Gotchas

### 1. Template conditionals don't support `&&`/`||`
Use nested `{{#if}}` blocks instead of `{{#if param.x === true && param.y === true}}`. Step conditions and data conditions DO support `&&`/`||`.

### 2. `dataRequirements` conditions use bare param names
In `dataRequirements`, write `"condition": "multilingual === true"` (not `param.multilingual`).

### 3. Step conditions use prefixed paths
In `steps`, write `"condition": "param.keepAssets !== true"` (with `param.` prefix).

### 4. `extract` navigates the API response
`"extract": "data.flat_routes"` means: take `response.data.flat_routes`. Without extract, you get the full `response.data`.

### 5. `forEach` source paths
`"forEach": "routes"` looks in `fetchedData['routes']`. For nested: `"forEach": "assetsData.images"`.

### 6. Sub-workflow params are resolved from parent context
`"params": { "languages": "{{param.languages}}" }` forwards the parent's `languages` parameter to the sub-workflow.

### 7. System placeholders (`{{__xxx}}`)
Placeholders starting with `__` (like `{{__current_page;lang=en}}`) are preserved through step resolution — they're resolved at execution time by the command handlers, not by WorkflowManager.

### 8. `$each` in step params — late resolution for arrays
```json
"params": {
    "translations": {
        "$each": "{{param.languages}}",
        "$item": {
            "lang": "{{$value}}",
            "keys": { "page.titles.home": "Home" }
        }
    }
}
```
This generates an array of translation objects, one per language.

### 9. Naming convention
- **Meta section:** `titleKey`/`descriptionKey` (translation keys) are preferred; `name`/`description` (direct strings) are accepted.
- **Parameters:** Use `label` (direct text) OR `labelKey` (translation key). Non-hidden parameters must have one or the other. `labelKey` is resolved through the admin locale files, then returned as-is.

### 10. When a spec is checked, and when it is not
Loading a spec does **not** validate it. `loadWorkflow()` only requires the file
to be valid JSON carrying an `id`, so a spec with a broken shape still appears in
the workflow list and still looks runnable.

The check happens when the runner asks for the rendered prompt. That request
refuses an invalid spec outright, answering `500` with the error list rather than
rendering half a prompt. `WorkflowManager::validateWorkflow()` checks:
- Required fields: `id`, `version`, `meta`
- Meta must have `titleKey` or `name`, and `category`
- Category must be valid
- Parameters need `id` and `type`
- Data requirements need `id` and `command`
- Must have either `steps` or `promptTemplate`

It is a **shape** check and nothing more. It does not confirm that a command you
named exists, that a `dataRequirements` id you referenced is the one your template
reads, or that a partial you wrote resolves to a file. Those surface when you run
the workflow — see *Adding a workflow*.
