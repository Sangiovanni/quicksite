# QuickSite Workflow System — Complete Reference

> Internal documentation for understanding, creating, and debugging workflows.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Files Involved](#files-involved)
4. [Workflow Types](#workflow-types)
5. [JSON Spec Reference](#json-spec-reference)
6. [Markdown Template Reference](#markdown-template-reference)
7. [Execution Flow](#execution-flow)
8. [Creating a Custom Workflow](#creating-a-custom-workflow)
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
User fills parameters in admin UI
        │
        ▼
┌─────────────────────────────────────┐
│         WorkflowManager.php         │
│                                     │
│  1. loadWorkflow(id)                │
│  2. fetchDataRequirements()         │
│     → CommandRunner executes        │
│       read-only API commands        │
│  3a. generateSteps()    [manual]    │
│  3b. renderPrompt()     [AI]        │
└─────────────────────────────────────┘
        │                    │
    [manual]              [AI]
        │                    │
        ▼                    ▼
  Steps preview        Rendered markdown
  → Execute batch      → User sends to AI
                       → Paste JSON response
                       → Execute batch
```

---

## Files Involved

### Core Engine

| File | Purpose |
|------|---------|
| `secure/src/classes/WorkflowManager.php` (~1540 lines) | Core orchestrator — loading, validation, data fetching, step generation, prompt rendering |
| `secure/src/classes/CommandRunner.php` | Executes API commands internally (used by `fetchDataRequirements`) |

### Workflow Definitions

| Path | Content |
|------|---------|
| `secure/admin/workflows/schema.json` | JSON Schema defining the spec format |
| `secure/admin/workflows/core/*.json` | Core workflow specs (shipped with QuickSite) |
| `secure/admin/workflows/core/*.md` | Core markdown templates |
| `secure/admin/workflows/custom/*.json` | User-created workflow specs |
| `secure/admin/workflows/custom/*.md` | User-created markdown templates |

### Admin UI

| File | Purpose |
|------|---------|
| `secure/admin/templates/pages/workflows/index.php` | Workflow browser — lists all workflows by category |
| `secure/admin/templates/pages/workflows/spec.php` | Workflow executor — parameter form, prompt preview, execution |
| `secure/admin/templates/pages/workflows/editor.php` | Workflow creator/editor — JSON + markdown side by side |
| `public/admin/assets/js/pages/ai-spec.js` (~1500 lines) | Client-side execution logic (parameter forms, streaming, batch execution) |
| `public/admin/assets/js/pages/ai-editor.js` | Editor page JS (validation, save, preview) |

### API Endpoints (via admin router)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/admin/api/workflow/render-prompt` | POST | Render a markdown template with parameters + data |
| `/admin/api/workflow/resolve` | POST | Resolve steps for manual workflow execution |
| `/admin/api/workflow/save` | POST | Save a custom workflow (JSON + optional markdown) |
| `/admin/api/batch/execute` | POST | Execute an array of commands (streaming) |

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

## JSON Spec Reference

### Required Fields

```json
{
    "id": "my-workflow",           // kebab-case, unique
    "version": "1.0.0",           // semver
    "meta": {
        "icon": "🎯",             // emoji for display
        "name": "My Workflow",     // display name (custom workflows)
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
    "label": "Language",                  // direct text (custom workflows)
    "labelKey": "specs.params.lang.label",// OR translation key (core workflows)
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

**Validation constraints** (mapped to HTML input attributes, enforced by the browser):
- `minLength` / `maxLength` — character count limits (for `text` and `textarea`)
- `pattern` — JavaScript regex the value must match (for `text`). Anchored automatically by the browser — `^[a-z]+$` enforces lowercase only.
- `min` / `max` — numeric range limits (for `number`)

**Parameter types:**
- `text` — single-line input
- `textarea` — multi-line input
- `select` — dropdown (needs `options` or `optionsFrom`)
- `checkbox` — boolean toggle
- `number` — numeric input (renders `<input type="number">`)
- `nodeSelector` — visual node picker from page structures
- `hidden` — not shown in UI, value set programmatically

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
    "prepend": [{ "value": "", "label": "None" }]
}
```
Full form — `data` references a `dataRequirements` ID, `value`/`label` pick fields from each item, `prepend` adds fixed options at the top.

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
List of API command names. Used for:
1. Permission checking (user must have access to these commands)
2. Building `{{#each commands}}` context in markdown templates (auto-fetches help data)

#### `loadPreset` (object)
Shows a dropdown that loads existing data into the parameter form. Useful for "edit existing" workflows.

```json
"loadPreset": {
    "command": "listBuilds",
    "dataPath": "builds",
    "labelKey": "workflows.specs.buildAndDeploy.loadPreset.label",
    "placeholderKey": "workflows.specs.buildAndDeploy.loadPreset.placeholder",
    "valueField": "name",
    "setParam": "buildExists",
    "fieldMap": {
        "buildName": "name",
        "publicFolder": "public",
        "secureFolder": "secure"
    }
}
```

- `command` — API endpoint to call for the preset list
- `dataPath` — path in the response to the items array (e.g., `"builds"`)
- `valueField` — field used as the unique identifier in each item
- `setParam` — hidden parameter toggled to `"true"` when a preset is selected
- `fieldMap` — maps form parameter IDs to data fields (fills the form on selection)
- `labelKey` / `placeholderKey` — translation keys for the dropdown label and placeholder

#### `examples` (array)
Pre-built example scenarios shown as clickable cards. Clicking one fills the form with preset parameters.

```json
"examples": [
    {
        "id": "example-blog",
        "titleKey": "workflows.createWebsite.example1.title",
        "promptKey": "workflows.createWebsite.example1.prompt",
        "params": {
            "pages": "home, about, blog",
            "style": "modern"
        }
    }
]
```

- `titleKey` — translation key for the example card title
- `promptKey` — translation key for the description (also shown as subtitle)
- `params` — pre-filled parameter values applied when clicked

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

#### `preWorkflows` / `postWorkflows` (array)
Sub-workflows to run before/after. Can be a simple string ID or an object with `id`, `params`, and `condition`.

---

## Markdown Template Reference

Templates are processed by `WorkflowManager::renderPrompt()` through a **7-pass rendering engine**.

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
{{#each commands}}
{{formatCommand @key this}}
---
{{/each}}

{{#each data.languages}}
- {{@key}}: {{this}}
{{/each}}
```

**Loop sources:**
- `commands` — the built commands context (from `relatedCommands` + help data)
- `data.xxx` — any data from `dataRequirements`
- Bare names — checked in `fetchedData`

**Loop variables:**
- `{{@key}}` — current key (index for arrays, key for objects)
- `{{this}}` — current value (formatted)
- `{{this.field}}` — access object property

**Special helper:**
- `{{formatCommand @key this}}` — formats a command's help data as markdown (### heading + bullet list of params)

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
5. **Show command docs** via `{{#each commands}}{{formatCommand @key this}}{{/each}}`
6. **Be explicit about order** — AI needs to know command dependency order
7. **Add examples** — concrete JSON examples help AI accuracy dramatically

---

## Execution Flow

### AI Workflow Execution (in `ai-spec.js`)

```
1. User fills parameter form
2. Click "Generate Prompt"
3. POST /admin/api/workflow/render-prompt
   → WorkflowManager.renderPrompt() → returns markdown
4. User copies rendered prompt
5. User sends to AI assistant (external)
6. User pastes AI's JSON response
7. Client validates JSON structure
8. Client normalizes commands (ensures correct format)
9. POST /admin/api/batch/execute
   → Streaming execution, results shown per-command
```

### Manual Workflow Execution

```
1. User fills parameter form
2. Click "Preview Steps"
3. POST /admin/api/workflow/resolve
   → WorkflowManager resolves phases:
     a. getWorkflowPhases() → [{type: preWorkflow, id}, {type: main}, ...]
     b. For each phase: resolveSubWorkflow() 
        → fetchDataRequirements + generateSteps
   → Returns all expanded steps
4. Steps shown in preview panel
5. User clicks "Execute"
6. POST /admin/api/batch/execute
   → Same streaming execution
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

## Creating a Custom Workflow

### Minimal Manual Workflow

```json
{
    "id": "cleanup-images",
    "version": "1.0.0",
    "meta": {
        "icon": "🗑️",
        "name": "Delete All Images",
        "description": "Removes all images from the assets folder",
        "category": "advanced",
        "difficulty": "beginner"
    },
    "dataRequirements": [
        {
            "id": "assets",
            "command": "listAssets",
            "extract": "data.assets"
        }
    ],
    "steps": [
        {
            "forEach": "assets.images",
            "command": "deleteAsset",
            "params": {
                "filename": "{{$value.filename}}"
            }
        }
    ]
}
```

### Minimal AI Workflow

**my-workflow.json:**
```json
{
    "id": "my-workflow",
    "version": "1.0.0",
    "meta": {
        "icon": "✨",
        "name": "My AI Workflow",
        "description": "AI-assisted page creation",
        "category": "creation",
        "difficulty": "intermediate"
    },
    "parameters": [
        {
            "id": "description",
            "type": "textarea",
            "labelKey": "Description",
            "required": true
        }
    ],
    "dataRequirements": [
        {
            "id": "helpAddRoute",
            "command": "help",
            "urlParams": ["addRoute"],
            "extract": "data"
        }
    ],
    "relatedCommands": ["addRoute", "editStructure"],
    "promptTemplate": "my-workflow.md"
}
```

**my-workflow.md:**
```markdown
# Create a Page

Output JSON only. No explanations.

## User Request
{{param.description}}

## Available Commands
{{#each commands}}
{{formatCommand @key this}}
---
{{/each}}

## Output Format
```⁠json
[{ "command": "addRoute", "params": { "route": "..." } }]
```⁠
```

### Save Location

Custom workflows are saved to `secure/admin/workflows/custom/`. The JSON and MD files must share the same base name as the workflow `id`.

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

### 9. Core vs Custom — naming convention
- **Meta section:** Core workflows use `titleKey`/`descriptionKey` (translation keys). Custom workflows use `name`/`description` (direct strings).
- **Parameters:** Use `label` (direct text) OR `labelKey` (translation key). Non-hidden parameters must have one or the other. `labelKey` is resolved through the translation sidecar first, then admin locale files, then returned as-is.

### 10. Editor validation
The editor page validates JSON on every keystroke and shows errors immediately. The validation in `WorkflowManager::validateWorkflow()` checks:
- Required fields: `id`, `version`, `meta`
- Meta must have `titleKey` or `name`, and `category`
- Category must be valid
- Parameters need `id` and `type`
- Data requirements need `id` and `command`
- Must have either `steps` or `promptTemplate`
