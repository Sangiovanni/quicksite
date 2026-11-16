# QuickSite Workflow System — Complete Reference

> Reference for understanding, authoring, and debugging workflows.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Files Involved](#files-involved)
4. [Workflow Types](#workflow-types)
5. [Adding a workflow](#adding-a-workflow)
6. [Site templates](#site-templates)
7. [JSON Spec Reference](#json-spec-reference)
8. [Markdown Template Reference](#markdown-template-reference)
9. [Execution Flow](#execution-flow)
10. [Condition Syntax](#condition-syntax)
11. [Known Patterns & Gotchas](#known-patterns--gotchas)

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
User fills parameters in the Workflows panel
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
│  3. getWorkflowPhases()             │
│     each phase, when reached:       │
│     generateSteps()  [its steps]    │
│     renderPrompt()   [an AI phase]  │
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
| `<secure>/admin/workflows/templates/*.json` | Site templates: what *Start from a template* builds, one file per template (see *Site templates*) |
| `public/admin/assets/template-previews/<template>/` | Each template's built preview, written by `<secure>/tools/template-previews.php` |
| `public/admin/assets/images/templates/<template>.jpg` | Each template's picture in the picker (`.webp` and `.png` are read too) |

**Workflows are extensible by file.** There is no in-app authoring surface and no
authoring command: a workflow *is* a JSON spec, plus a Markdown template beside it
when it drives an AI. Anyone with file access to the installation can add one —
see *Adding a workflow* below. The panel runs workflows; it does not create them.

### Admin UI

Workflows are run from the visual editor's **Workflows** mode (`ai-tools`)
(`/admin/preview` → Workflows). Any `/admin/workflows*` URL redirects there.

| File | Purpose |
|------|---------|
| `<secure>/admin/templates/pages/preview/contextual-ai-tools.php` | DOM scaffold — workflow list + runner |
| `public/admin/assets/js/pages/preview/preview-ai-tools.js` | Client-side logic (list, filters, parameter forms, streaming, batch execution) |
| `public/admin/assets/js/pages/ai/lib/ai-call.js` | Browser-direct AI dispatch (BYOK) — no PHP proxy |

See [ADMIN_PANEL.md §8.12](ADMIN_PANEL.md) for the runner UX.

### API Endpoints (via admin router)

The helper endpoints live in `public/admin/api/index.php`. Both are
project-scoped, so the project marker is **required**, not optional — a call
without it is refused with `400`:

```
/admin/api/p/<projectId>/<action>
```


| Action | Method | Purpose |
|----------|--------|---------|
| `ai-spec-raw/<id>` | GET | Load the spec definition itself (parameter schema, metadata) |
| `workflow-generate-steps` | POST | A workflow's phases, each with its steps resolved: the whole run (the plan), or one phase when the run reaches it — an AI phase's prompt (`prompt: true`), an AI answer's commands read back with what they delete or change (`answer`) |

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

A workflow with both `steps` and a `promptTemplate` runs as an AI workflow: its prompt is built and its AI's answer runs; its `steps` are not run. To run steps before or after an AI prompt, put them in a workflow of their own and name it in `preWorkflows` or `postWorkflows` (see *Sub-Workflows*).

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

Every workflow runs in phases: each pre-workflow, then the workflow itself, then each post-workflow, in order — manual and AI workflows alike, and any of them may be an AI workflow. A sub-workflow's condition is decided when the run is planned, and again each time the run reaches a phase; a phase is resolved when the run reaches it, its own data requirements fetched against the project as the earlier phases left it, so it sees what they changed. Each phase has a key — the entries that lead to it and, for a repeated entry, its item — and the run asks for a phase by its key: when a condition or a `forEach` that reads the project's data now plans another phase in its place, the run stops there with *The run's plan changed: start the run again*. An AI phase builds its prompt when it is reached: an AI workflow's pre-workflows run before its prompt is built, its post-workflows after its commands. A sub-workflow takes the parameters its parent passes it and its own defaults for the rest. An entry with `forEach` runs its sub-workflow once per item, read like a step's `forEach` and `filter`, whenever the condition is, `{{$value}}` in its `params`. An AI sub-workflow whose entry says `"request": "parent"` takes its parent's *Your prompt* instead of a field of its own; each review before it shows that request, editable for the phases still to run. A sub-workflow's own pre- and post-workflows are phases of their own; a cycle is left out where it closes. Between two phases the runner pauses for a review unless *Run without stopping* is on, and what a phase deletes or changes is confirmed before it runs ([ADMIN_PANEL.md §8.12](ADMIN_PANEL.md), *A run, phase by phase*).

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
        "category": "look",
        "answerLength": "short",
        "tags": ["design", "colors", "variables", "theme"]
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
"meta": { "icon": "🎨", "name": "My Workflow", "description": "What it does", "category": "look" }
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

5. **Reload the panel and run it.** Open the visual editor, switch to
   **Workflows**, and your workflow appears in the list under its group, its row
   saying what a run does. Running it is the check — see below.

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
component before it resets styles. It keeps the words the consent layer and the storage and privacy
registries own, as those outlive the pages: its `setTranslationKeys` steps name them in `keep`. A workflow
that replaces a language's texts does the same.

---

## Site templates

*Start from a template* (`start-from-template.json`) is one workflow for every ready-made site. Its first
parameter is a picker of templates; its steps are the picked template's. It asks no model.

**A template is one file**, `<secure>/admin/workflows/templates/<id>.json`. Put a file there and the picker
offers it: nothing else is edited. Like workflows, templates are shipped files: only that folder is read, and
a run names a template by its id.

```json
{
    "id": "my-template",
    "icon": "🏠",
    "title": "My template",
    "description": "One line under the name",
    "languages": ["en", "fr"],
    "asks": ["businessName", "phone"],
    "steps": [
        { "command": "editStructure", "params": { "type": "menu", "structure": [
            { "tag": "nav", "children": [
                { "tag": "a", "params": { "href": "/" }, "children": [{ "textKey": "site.name" }] },
                { "$if": "param.darkMode === true", "component": "theme-switch", "data": {} }
            ] }
        ] } },
        { "command": "setTranslationKeys", "condition": "param.multilingual !== true", "forEach": "param.language",
          "params": { "language": "{{$value}}", "translations": "{{texts.{{$value}}}}" } },
        { "command": "setTranslationKeys", "condition": "param.multilingual === true", "forEach": "param.languages",
          "params": { "language": "{{$value}}", "translations": "{{texts.{{$value}}}}" } }
    ],
    "data": {
        "texts": { "en": { "site": { "name": "My site" } }, "fr": { "site": { "name": "Mon site" } } },
        "dark": { "--color-bg": "#111111" }
    }
}
```

| Key | What it is |
|---|---|
| `id` | The file's name without `.json`. A file whose `id` is not its name is left out. |
| `title`, `description` | The name and the line on the template's card, as written. `titleKey` and `descriptionKey` name a translation instead (the shipped templates use them). |
| `icon` | Shown on the card when the template has no picture |
| `languages` | The languages the template is written in. A run offers these and no other. |
| `asks` | The optional fields the template asks a run for, by the row's parameter ids: `businessName`, `phone`, `email`, `address`, `bookingUrl`. The form shows a field only for a template that lists it. Left out, the template asks for none. |
| `steps` | The template's commands, read as any workflow's steps (see *`steps`*): `condition`, `forEach`, `filter`, placeholders and `$if`. |
| `data` | What the steps read, by key: `{{texts.fr}}`, `{{dark}}`. A template whose `data.dark` is not empty is offered with a dark mode. |

**What a run chooses, and what the steps read:**

| Field | In a step | What the row does with it |
|---|---|---|
| Template | `param.template` | The steps are that file's |
| Several languages | `param.multilingual` | Runs *Setup Languages* with the languages ticked, then *Update Language Switch* |
| Languages | `param.languages` | The template's languages, at least two ticked |
| Main language | `param.language` | The site's default language, one of the template's |
| Include dark mode | `param.darkMode` | Runs *Theme Switch Button*; shown only for a template with dark colours |
| Business name, Phone number, E-mail address, Address, Booking or ordering link | `param.businessName`, `param.phone`, `param.email`, `param.address`, `param.bookingUrl` | Nothing: the template's own steps read them. Each is shown only for a template whose `asks` lists it, and is empty when left empty |

A run goes through *Fresh Start*, *Setup Languages*, *Update Language Switch* when the site has several
languages, *Theme Switch Button* when it has a dark mode, then the template's steps. The languages are set
before the template's steps, since a language's texts can only be sent once the language exists.

**A field a template asks for is optional, and the template holds the sample that stands in for it.** The template always sends its
sample texts; a step under a condition (`"condition": "param.multilingual !== true && param.phone"`) then sends what was typed over
them, as a text of the site, the same in every language. A link is written in its two variants, each under `$if`: the field's value
(`"$if": "param.phone"`, `"href": "tel:{{param.phone | tel}}"`) or the sample's (`"$if": "!param.phone"`). The `tel` filter keeps a
number's digits and a leading `+`, which is what a `tel:` address takes. For one value to fill every place, a template keeps the
business name out of its sentences: it is a text of its own (`site.name`), shown where a name stands alone.

**What a shipped template shows.** A template by type of business holds what a person of that trade needs on one site, with sample
content that cannot pass for real: every business and person name carries *Sample* (*Exemple* in French), every address is of
`example.com` or of a street and a town that do not exist, every phone number is one that is never assigned, and a review says that
it is a sample. Nothing it shows comes from outside: its pictures are its own photos (below) or what its stylesheet draws. A way
to book, call, order or ask for a quote is a link (`tel:`, `mailto:`, or the address of the service the author uses), not a form.

**Photos in a template.** A template's photos are QuickSite's own, shipped in `<secure>/admin/workflows/templates/photos/`
beside `photos.json`, the record of how each was made (the model, the prompt, any edit, the terms; the photos are CC0). One
folder holds every template's photos: a photo's name starts with its template's id, and is the asset's name in a project. A run
brings each into the project's images with one `uploadAsset` step, before the pages that show it:

```json
{ "command": "uploadAsset", "params": { "templatePhoto": "trades-job.webp" } }
```

`templatePhoto` reads that folder alone and fetches nothing, so a run brings its photos the same way on a server and on a local
install; the photo then goes through every check of an upload. A second run on the same project finds the same photo there and
adds no copy. A page shows a photo as an image (`"tag": "img"`, `"src": "/assets/images/trades-job.webp"`), never as a stylesheet
background, so an author swaps it in the editor like any image; its `alt` is a key of the template's texts, so it follows the
language. No text is laid on a photo: words sit beside or under it, so their contrast is the palette's whatever photo an author
puts in its place. The previews' tool keeps a template's photos once for both themes, and a photo that changes makes its
template's preview stale.

**Adding a language to a template:** add its code to `languages` and its texts under `data.texts`. The
shipped templates send each chosen language's texts with one step, `{{texts.{{$value}}}}`, so nothing else
changes.

**A template fits a phone, a tablet and a computer.** QuickSite has two limits, the panel's own: a phone up
to `40rem` (640 px), a tablet up to `64rem` (1024 px), a computer above. A template is never made for one of them
alone, and it fits from the narrowest phone in use, 320 px wide, up.

- **Wrap first.** A layout that needs no width uses none: a grid's columns as `minmax(min(320px, 100%), 1fr)`, a
  title's size as a `clamp()` whose largest value is the computer's, `flex-wrap: wrap` on a row of links,
  `overflow-wrap: break-word` for a word longer than the screen. Each fits every width with no media query.
- **When a width is needed,** only `@media (max-width: 64rem)` (the tablet) and `@media (max-width: 40rem)` (the
  phone), written exactly so: at most one block of each, at the end of the stylesheet, the tablet's before the
  phone's, and no plain rule after them, which would override them. A template that needs neither has none. A
  `rem` in a media query is the browser's default text size, never the site's root size: the limits stay 640 and
  1024 px whatever the site sets on `html`, and grow only for a visitor who raised their browser's text size.
- **Nothing is out of reach.** At every width, no page is wider than the screen, no text is cut, and every link and
  button can be reached. A menu that does not fit wraps onto another row; on a phone, a bar fixed or sticky at
  the top scrolls with the page, so it never covers what is under it.
- **What the run's options add fits too:** the language switch and the light/dark button take their look from the
  template's stylesheet.

The tool below refuses a template whose stylesheet names another width, holds a second block of either, puts the
phone's before the tablet's, or a plain rule after them: it builds no preview for it, and `--check` names it. A
media query that names no width (`prefers-reduced-motion`, say) is not this rule's.

**The picture and the preview.** A template's picture is `public/admin/assets/images/templates/<id>.jpg`
(`.webp` or `.png`); without one its card shows its icon. Its preview is built by a tool, from a shell:

```
php <secure>/tools/template-previews.php
```

It builds each template with every one of its languages, in its light theme and in its dark theme when it
has one, and keeps the pages as static files under `public/admin/assets/template-previews/<id>/`. The panel
shows a preview only while it matches the template's file: change the file, and its card shows no **Preview**
button until the tool has run again. `--check` builds nothing and says which previews are stale.

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
        "category": "tools"        // see groups below
    }
}
```

> Core workflows use `titleKey`/`descriptionKey` (i18n translation keys) instead of `name`/`description`.

### Groups (`meta.category`)

The Workflows panel lists the workflows by what a person wants to do, in this order:

| Value | Group, on screen | Under it |
|---|---|---|
| `start` | Start a new site | Replaces what is there |
| `add` | Add to my site | Keeps what is there |
| `look` | Change the look | Colours, styles, animations |
| `texts` | Texts and languages | Words, translations, the switch |
| `privacy` | Cookies and privacy | Your consent banner and pages |
| `tools` | Tools | For when you know what you want |

The list is `schema.json`'s enum, and its order is the panel's; a group's words are `ai.categories.<value>.title` and `.desc` in the
panel's translations. A group with no workflow shows nothing. Another name still runs, as the engine checks only that `category`
names a group, and the panel lists it last, under its own name.

### What a workflow's row says

Under its title, a row says what a run does, read from the workflow's file and every workflow it may reach
(`WorkflowManager::rowFacts()`), so a new file says it with nothing to declare:

- **AI or not, and how many requests:** a `promptTemplate` in its reach asks a model, unless that workflow is `pasteOnly`; one
  request, or several when its reach holds two AI workflows or a `forEach` repeats one. `meta.answerLength` adds how long the
  answer runs.
- **Can delete** and **Changes:** each step of its reach as its file writes it, and each command an AI answer may send
  (`relatedCommands`), read as a run's review reads them (*What a run warns about*, below). As nothing is fetched, each says what a
  run *can* do: a step repeated over a project's pages deletes nothing where there is none.
- **Can run any command, one that deletes included:** `answerRules.anyCommand`.
- **What it needs:** `meta.needsKey`.

### Optional Fields

#### `meta.description` / `meta.descriptionKey`
Short description shown on the workflow's row, cut at two lines.

#### `meta.tags` (array of strings)
Words the list's search reads and nothing shows. Example: `["create", "website", "multi-page"]`

#### `meta.answerLength`
`short` | `long` — how long the AI's answer runs, added to the row's words (*Uses AI: one request, short answer*): short is up to
about 5,000 characters, long is the whole of a site. Set it only from a measure or an estimate that stands far from the line; left
out, the row says how many requests and no more.

#### `meta.needsKey`
A translation key of a short text naming what the project must hold before a run makes sense, shown on the row: *Needs a cookie
banner*. It is shown in the panel's language only where that language holds the key.

#### `pasteOnly` (boolean)
The commands are pasted by hand and no model is asked: the row says *No AI: you paste the commands*, and the requests a run sends
count none for it. Its `promptTemplate` is what the paste box asks for. `run-batch` is the one.

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
- `selector` — auto-fills from the visual editor's current iframe selection. Read-only display in the Workflows panel; the param value is a structured object `{ tag, classes, struct, node }`. Workflow steps reference subfields with `{{param.X.tag}}`, `{{param.X.struct}}`, etc.
- `template` — a picker of site templates: one radio card per file of `templates/`, with its picture, its name and its line. Its value is the template's id. It takes no `options`: the engine lists the folder. See *Site templates*.
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

Data that is a map rather than a list gives one option per entry, its key the value and its value the label (`value` / `label` are not read): Translate Site's two fields read `getLangList`'s `language_names` so, showing each language's name and sending its code.

`filterFrom` cascades the resolved options from another parameter's value: the option list is restricted to entries whose `value` appears in the referenced parameter's current selection. The cascade only applies when the referenced parameter is currently visible (its own `condition` is truthy) AND has a non-empty value — otherwise the full list is used. The auto-correct rule: when the cascade narrows the option set and the dependent's current value is no longer valid, the dependent flips to the first available option.

Example: setup-languages's `defaultLanguage` uses `filterFrom: "languages"`. With Multilingual unchecked, Languages is hidden, so the cascade is skipped and Default language shows all 230 languages. Check Multilingual and pick `[en, fr]` in Languages, and Default language re-renders with just English + Français.

`excludeFrom` names another parameter whose current value — or values, for a list — is left out of the options, read as `filterFrom` reads its parameter (only while that one is visible and has a value), drawn again on the same changes and with the same auto-correct. Translate Site's *Language to write* has `"excludeFrom": "from"`, so it never offers the *Translate from* language.

`"searchable": true` beside the `type` of a `select` or a `tag-select` gives its list a search that matches an option's name and its value: the `select` becomes a dropdown you type in, the `tag-select` gets a search field above its checkboxes that hides the options that do not match but never one already ticked. On any other type it is ignored.

**Conditional visibility:** `"condition": "multilingual === true"` — references another parameter's ID (not prefixed with `param.`).

**Fields that follow a picked template.** Beside a `template` parameter:
- a condition reads the template picked as `chosen.<the parameter's id>`, which is empty until one is picked: `"condition": "chosen.template && chosen.template.dark === true"`. It holds the template's `languages` and `dark`.
- `filterFrom` may name the `template` parameter: the options are then limited to the picked template's languages. `filterFrom` also takes a list of parameters, each narrowing the options in turn: `"filterFrom": ["template", "languages"]`.
- when a template is picked, a `tag-select` its languages filter starts with all of them ticked.

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
List of API command names. Used for three things:

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
3. **Bounding an AI answer** — an answer runs only the commands named here, unless
   `answerRules` says otherwise. A command it does not name is left out, and the
   panel says so before anything runs.

#### `promptTemplate` (string)
Filename of the markdown template (e.g., `"create-website.md"`). Loaded from the same folder as the JSON spec.

#### `answerRules` (object)
What an AI answer of this workflow may run, beyond its `relatedCommands`. `WorkflowManager::answerSteps()` reads
them each time an answer comes back: a step they refuse never becomes a step, and the answer's status line in the
panel names it. Each is optional.

| Rule | What it does | Example |
|------|--------------|---------|
| `anyCommand` | The answer may send any command, each still passing the user's role. For a workflow whose purpose is any command. | `run-batch` |
| `selectorStarts` | Where a step names a `selector`, every selector of its list begins with one of these, as a whole name: `#a b` begins with `#a`, `#a-b` does not. A `+` or `~` straight after the start's own part is refused, as it selects what follows the element, not what is inside it; one deeper in is not. A selector with an escape or a comment is refused, as a browser reads it otherwise than its text. | `["#qs-consent-banner", "#qs-consent-popup"]` |
| `forbiddenProperties` | A step whose `styles` declare one of these is left out, whatever the case of its name. An object's values are read as declarations too, and a property name with anything but letters, digits, `-` and `_` (an escape, say) is refused, as a browser could read it as one of these. | `["display", "position"]` |

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

**A part of `params` kept only on a condition.** An object anywhere inside `params` that carries `$if` is kept, without that key, when its condition holds, and left out when it does not. The condition is read as a step's `condition`. A structure can hold a node for one choice only:

```json
{ "$if": "param.darkMode === true", "component": "theme-switch", "data": {} }
```

A step that fails is counted and the run carries on, unless it sets `abortOnFail: true`: the run then stops at that step — the later steps and phases do not run, and the rest is kept for later. `retryOn` lists the HTTP statuses that send the step again, up to `maxRetries` times (default 3), `retryDelayMs` apart (default 1000).

**What a run warns about.** Before a step runs, the panel says what it deletes or changes, read from the step itself (`WorkflowManager::stepEffect()`): a read and a command that adds something are never warned; a step deletes when its command's name begins with delete, remove, clear, reset or purge, when its help marks it destructive, when it deletes a node, or when it gives a component an empty structure; any other write to the site's content changes it. There is nothing to declare: a new deleting step is warned as one. A step whose help marks it destructive and that deletes nothing stops a run the same way and says what it does (`restoreBackup`: it puts a backup back, over the project's current state).

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

#### `stepsFrom` (string)
The id of one of the workflow's parameters, of type `template`. The workflow's steps are then the picked template's, and the template's `data` is the run's static data; the workflow's own `steps` stays empty. A run with no template picked has no phase. A run that names something that is no template is refused whole: none of its phases is resolved, the workflows before its steps included. A workflow with `stepsFrom` has no `promptTemplate`. See *Site templates*.

#### `preWorkflows` / `postWorkflows` (array)
Sub-workflows to run before/after. Can be a simple string ID or an object with `id`, `params`, `condition`, `forEach` with its `filter` and `label`, and `request`. Each one runs as a phase of its own (one per item with `forEach`), and may be an AI workflow (see *Sub-Workflows*).

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
- `commands` — each command the workflow's `relatedCommands` lists, keyed by its name, its `help` entry as the value

**Loop variables:**
- `{{@key}}` — current key (index for arrays, key for objects)
- `{{this}}` — current value (formatted)
- `{{this.field}}` — access object property

A command's documentation is written with the partial form (*Pass 0.5*), not with a loop:

```markdown
{{> command.$relatedCommands}}
```

or for inline insertion of a single command's docs interleaved with prose:

```markdown
First, add a route:

{{> command.addRoute}}

Then wire styles:

{{> command.editStyles}}
```

The partial form routes to `formatCommandFull()`, which writes the description, the method, the parameters with their types and one fenced example: the first `example_*` of the command's help, as `{"command": …, "params": …}` when its line shows a request's body, the shape an answer uses, and as the line itself when it shows none.

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
5. **Show command docs** via `{{> command.$relatedCommands}}`
6. **Be explicit about order** — AI needs to know command dependency order
7. **Add examples** — concrete JSON examples help AI accuracy dramatically

---

## Execution Flow

### AI Workflow Execution (in `preview/preview-ai-tools.js`)

```
1. User fills the parameter form, and a Your prompt per AI phase (one for every phase that takes its parent's)
2. Click the primary action button
3. POST /admin/api/p/<projectId>/workflow-generate-steps  { workflowId, params }
   → the run's phases (getWorkflowPhases()), each resolved against the project as it is
4. Each pre-workflow phase runs, resolved again when reached, a review between
5. The same request with { phase, prompt: true } for the AI phase
   → WorkflowManager.renderPrompt(): the markdown, built from the project as the
     pre-workflows left it
6. Prompt goes to the AI — browser-direct via QSAiCall when a BYOK
   connection is configured, otherwise copied out and pasted back
7. Client validates the JSON response structure
8. The same request with { phase, answer } reads the commands back, with what each
   deletes or changes and, in "refused", what the workflow does not allow; each kept
   command is POSTed to /management/p/<projectId>/{command} in turn, results shown
   per-row in the Batch panel
9. Each post-workflow phase runs
```

### Manual Workflow Execution

```
1. User fills parameter form
2. POST /admin/api/p/<projectId>/workflow-generate-steps  { workflowId, params }
   → the phases (getWorkflowPhases()), each with its steps resolved against the
     project as it is now
3. The phases and their steps shown in the Batch panel
4. User clicks "Run"
5. Each phase is resolved again when the run reaches it
   ({ workflowId, params, phase }), then its steps are POSTed one by one to the
   Management API; between two phases, a review (unless Run without stopping)
```

### Step Generation Pipeline (one phase)

```
1. Merge staticData into fetchedData
2. Build context: { param, data, config }
3. For each main step:
   a. Evaluate condition → skip if false
   b. If forEach: expand into N steps (one per item)
      - Apply filter if present
      - Create item context with $key, $value, $item
   c. Resolve params: {{param.x}}, {{data.x}}, {{$value}}, etc.
   d. Resolve $each loops in params (for generating arrays)
4. Return the phase's resolved commands
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

In a step condition and a sub-workflow's condition, the right side of a comparison is a literal (a quoted string, a number, `true`, `false`, `null`) or a `{{path}}`, read from the context as the left side is: `param.defaultLanguage !== {{langData.default_language}}` compares two values. A right side without braces is a literal. A forEach filter reads its right side the same way.

A `*` in a path reads every item of a list: `switchNow.children.*.params.data-lang` is each child's `data-lang`, as a list.

### Value Normalization

Boolean-like strings are automatically normalized:
- `"true"`, `"on"`, `"1"` → `true`
- `"false"`, `"off"`, `"0"` → `false`

That is how the prompt's `{{#if}}` reads a `param.` value and how a data requirement's `condition` reads its parameter. A step condition and a sub-workflow's condition normalize a value read from the context, on either side, from `"true"` / `"1"` and `"false"` / `"0"` only: `"on"` and `"off"` stay text.

### Filter Syntax (step params)

```json
"params": {
    "langName": "{{$value | langname}}"
}
```

Available filters: `uppercase`/`upper`, `lowercase`/`lower`, `ucfirst`/`capitalize`, `ucwords`/`title`, `trim`, `tel` (a phone number as a `tel:` address takes it: its digits and a leading `+`, a `(0)` written after a country code left out), `langname`/`language`

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
