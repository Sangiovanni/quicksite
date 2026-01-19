# QuickSite Architecture & Design

> A deep dive into QuickSite's architecture, concepts, and implementation details.

---

## Table of Contents

1. [Philosophy & Core Concepts](#philosophy--core-concepts)
2. [High-Level Architecture](#high-level-architecture)
3. [Directory Structure](#directory-structure)
4. [The JSON-to-HTML Pipeline](#the-json-to-html-pipeline)
5. [Request Lifecycle](#request-lifecycle)
6. [Multi-Project Architecture](#multi-project-architecture)
7. [The Management API](#the-management-api)
8. [Translation System](#translation-system)
9. [Style Management](#style-management)
10. [Interactions System](#interactions-system)
11. [Build & Deploy System](#build--deploy-system)
12. [Security Architecture](#security-architecture)
13. [Admin Panel](#admin-panel)
14. [Design Decisions](#design-decisions)

---

## Philosophy & Core Concepts

### Origin & Vision

This project didn't start with a grand philosophy - it evolved into one. What began as a simple template gradually revealed a deeper goal: **making web development accessible without hiding its complexity**.

QuickSite is designed for two audiences that rarely share the same tool:

1. **Beginners** who want to build websites without deep coding knowledge, but who might want to learn and grow. That's why the Admin Panel exposes the underlying commands - not just a pretty preview with AI magic. You can see what's happening, understand the structure, and gradually dig deeper.

2. **Advanced developers** who want speed and flexibility without sacrificing control. The API-first design, JSON structures, and build system are all built with professional workflows in mind.

This dual focus explains why QuickSite is:
- **Open source** - Transparency isn't just about licensing; it's about letting people see how things work
- **Thoroughly documented** - From high-level concepts to implementation details
- **Layered** - Use the Visual Editor if you want simplicity, or dive into JSON/API if you want control

### The "Configure, Don't Code" Paradigm

QuickSite is built on a fundamental principle: **website content and structure should be data, not code**. Instead of writing PHP/HTML templates by hand, you define everything in JSON:

```json
{
  "tag": "section",
  "params": { "class": "hero" },
  "children": [
    { "tag": "h1", "children": [{ "textKey": "home.hero.title" }] },
    { "tag": "p", "children": [{ "textKey": "home.hero.subtitle" }] }
  ]
}
```

This JSON gets:
- **Rendered** at runtime (development) via `JsonToHtmlRenderer`
- **Compiled** to optimized PHP (production) via `JsonToPhpCompiler`

### Why File-Based?

QuickSite intentionally avoids databases:

| Database CMS | QuickSite |
|--------------|-----------|
| Requires MySQL/PostgreSQL setup | Just PHP files |
| Migration headaches | No migrations |
| Can't version control content easily | Full Git support |
| Complex backup/restore | Copy folders |
| Harder to deploy | rsync and done |

**Perfect for**: Landing pages, portfolios, microsites, documentation sites, small business websites.

**Can work for** (with external backend): User-generated content, e-commerce, dynamic apps.
QuickSite handles the frontend; you bring your own API for data storage.

**Not ideal for**: Apps requiring real-time sync (WebSockets), or when you need an all-in-one solution without external services.

### API-First Design

Every operation in QuickSite can be performed via HTTP API:
- Add pages, edit content, manage translations
- Upload assets, modify CSS, configure settings
- Build production deployments, create backups

This enables:
- **Headless operation**: Manage from any client (Flutter, React, CLI)
- **Automation**: CI/CD pipelines, batch operations
- **AI Integration**: LLMs can call the API to build sites

---

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           BROWSER / CLIENT                               │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
            ┌───────────────────────┼───────────────────────┐
            │                       │                       │
            ▼                       ▼                       ▼
    ┌───────────────┐      ┌───────────────┐      ┌───────────────┐
    │  Public Site  │      │  Admin Panel  │      │ Management API │
    │   /           │      │   /admin/     │      │  /management/  │
    └───────────────┘      └───────────────┘      └───────────────┘
            │                       │                       │
            └───────────────────────┼───────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         public/ (Apache DocumentRoot)                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐ │
│  │  index.php  │  │  init.php   │  │   admin/    │  │  assets/style/  │ │
│  │  (Router)   │  │  (Bootstrap)│  │   (SPA)     │  │  (Static files) │ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ require_once
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         secure/ (Not web-accessible)                     │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │                        management/                                  │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │ │
│  │  │  routes.php  │  │   config/    │  │       command/           │  │ │
│  │  │  (100 cmds)  │  │  auth, roles │  │  100 command files       │  │ │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │                          src/                                       │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │ │
│  │  │   classes/   │  │  functions/  │  │          js/             │  │ │
│  │  │  Renderer    │  │  PathMgmt    │  │  qs-interactions.js      │  │ │
│  │  │  Compiler    │  │  FileSystem  │  │  (runtime engine)        │  │ │
│  │  │  Translator  │  │  Security    │  │                          │  │ │
│  │  │  CssParser   │  │              │  │                          │  │ │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │                        projects/                                    │ │
│  │  ┌─────────────────────────────────────────────────────────────┐   │ │
│  │  │                    {project-name}/                           │   │ │
│  │  │  config.php  routes.php  templates/  translate/  data/       │   │ │
│  │  └─────────────────────────────────────────────────────────────┘   │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  ┌──────────────────────┐  ┌──────────────────────────────────────────┐ │
│  │ interaction-schemas/ │  │              admin/                      │ │
│  │  core/ + custom/     │  │  Admin panel PHP templates               │ │
│  └──────────────────────┘  └──────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure

### `public/` - Web Root

The only folder exposed to the web (Apache DocumentRoot).

```
public/
├── index.php          # Main router - handles all page requests
├── init.php           # Bootstrap - defines constants, loads config
├── .htaccess          # URL rewriting rules
├── admin/             # Admin panel SPA
│   ├── index.php      # Admin entry point
│   └── api/           # Admin-specific API proxy
├── assets/            # Static files (copied from project on switch)
│   ├── images/
│   ├── fonts/
│   └── videos/
├── style/
│   └── style.css      # Compiled CSS (synced from project)
└── build/             # Production build outputs (ZIPs)
```

### `secure/` - Protected Backend

Never directly accessible via web. Contains all logic and data.

```
secure/
├── management/        # API layer
│   ├── routes.php     # Array of 100 command names
│   ├── config/
│   │   ├── auth.php   # Tokens and authentication
│   │   ├── roles.php  # Role definitions (viewer → superadmin)
│   │   └── target.php # Active project pointer
│   └── command/       # 100 command implementations
│       ├── addRoute.php
│       ├── editStructure.php
│       ├── build.php
│       └── ...
│
├── src/               # Core engine
│   ├── classes/
│   │   ├── JsonToHtmlRenderer.php   # Runtime JSON→HTML
│   │   ├── JsonToPhpCompiler.php    # Build-time JSON→PHP
│   │   ├── Translator.php           # i18n system
│   │   ├── CssParser.php            # CSS manipulation
│   │   ├── ApiResponse.php          # Standardized responses
│   │   ├── CommandRunner.php        # Internal command execution
│   │   └── TrimParameters*.php      # URL/body parsing
│   ├── functions/
│   │   ├── PathManagement.php       # Path traversal protection
│   │   ├── FileSystem.php           # File operations
│   │   └── Security.php             # Validation helpers
│   └── js/
│       └── qs-interactions.js       # Client-side runtime
│
├── projects/          # Multi-project storage
│   └── {name}/        # Each project is self-contained
│       ├── config.php
│       ├── routes.php
│       ├── templates/
│       │   ├── model/json/    # Source: JSON structures
│       │   └── pages/         # Compiled: PHP templates
│       ├── translate/         # Language files
│       ├── data/              # Aliases, metadata
│       ├── interactions/      # Interaction configs
│       └── backups/           # Project backups
│
├── interaction-schemas/       # Shared interaction blueprints
│   ├── core/                  # Built-in (show, hide, filter, api...)
│   └── custom/                # User-defined
│
├── admin/             # Admin panel templates
│   ├── AdminRouter.php
│   └── templates/
│
└── exports/           # Temporary export ZIPs
```

---

## The JSON-to-HTML Pipeline

### JSON Structure Format

Every page, menu, footer, and component is defined in JSON:

```json
{
  "structure": [
    {
      "tag": "div",
      "params": { "class": "container", "id": "main" },
      "children": [
        { "tag": "h1", "children": [{ "textKey": "page.title" }] },
        { "tag": "p", "children": [{ "textKey": "page.intro" }] },
        { "component": "contact-form" }
      ]
    }
  ]
}
```

### Node Types

| Type | Structure | Description |
|------|-----------|-------------|
| **Tag Node** | `{ tag, params?, children? }` | HTML element |
| **Text Node** | `{ textKey }` | Translated text |
| **Raw Text** | `{ textKey: "__RAW__..." }` | Non-translated text |
| **Component** | `{ component, data? }` | Reusable component |
| **Conditional** | `{ if, then, else? }` | Conditional rendering |

### Rendering vs Compiling

```
                    JSON Structure
                         │
          ┌──────────────┴──────────────┐
          │                             │
          ▼                             ▼
   ┌─────────────────┐          ┌─────────────────┐
   │ JsonToHtmlRenderer│        │ JsonToPhpCompiler │
   │   (Runtime)      │          │    (Build)       │
   └─────────────────┘          └─────────────────┘
          │                             │
          ▼                             ▼
   ┌─────────────────┐          ┌─────────────────┐
   │   HTML Output   │          │   PHP Template   │
   │   (Dynamic)     │          │   (Optimized)    │
   └─────────────────┘          └─────────────────┘
```

**Runtime Rendering** (development):
- `JsonToHtmlRenderer` parses JSON on every request
- Supports `?_editor=1` mode with `data-qs-*` attributes
- Flexible, instant changes, slower

**Compiled Templates** (production):
- `JsonToPhpCompiler` generates static PHP files
- No JSON parsing overhead
- Translation calls are still dynamic
- Fast, optimized, requires build step

### Component System

Components are reusable JSON structures:

```json
// components/hero.json
{
  "name": "hero",
  "structure": {
    "tag": "section",
    "params": { "class": "hero {{variant}}" },
    "children": [
      { "tag": "h1", "children": [{ "textKey": "{{titleKey}}" }] }
    ]
  }
}
```

Used in pages:

```json
{ "component": "hero", "data": { "variant": "dark", "titleKey": "home.title" } }
```

---

## Request Lifecycle

### Public Site Request

```
GET /fr/about
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Apache .htaccess → RewriteRule → public/index.php               │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ init.php                                                         │
│  - Define constants (PUBLIC_FOLDER_ROOT, SECURE_FOLDER_PATH)    │
│  - Load target.php → determine active project                    │
│  - Load project config.php → CONFIG constant                     │
│  - Load project routes.php → ROUTES constant                     │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ index.php                                                        │
│  - Check URL aliases (redirect or rewrite)                       │
│  - TrimParameters parses URL → extract lang, route               │
│  - Validate route exists in ROUTES                               │
│  - 404 if not found                                              │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Route Resolution                                                 │
│  - Route 'about' → templates/pages/about/about.php              │
│  - Route 'guides/install' → templates/pages/guides/install/...  │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Page Template (about.php)                                        │
│  - Instantiate Translator with detected language                 │
│  - Instantiate JsonToHtmlRenderer                                │
│  - Render page structure → HTML string                           │
│  - Pass to Page class for full HTML document                     │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Page::render()                                                   │
│  - Output <!DOCTYPE html>                                        │
│  - Include <head> with title, favicon, CSS                       │
│  - Include menu.php (rendered from menu.json)                    │
│  - Output page content                                           │
│  - Include footer.php (rendered from footer.json)                │
└─────────────────────────────────────────────────────────────────┘
```

### Management API Request

```
POST /management/addRoute
Authorization: Bearer tvt_xxx
Content-Type: application/json
{"route": "contact", "title": {"en": "Contact", "fr": "Contact"}}
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ public/management/index.php                                      │
│  - Parse Authorization header                                    │
│  - Validate token exists in auth.php                             │
│  - Get token's role                                              │
│  - Check role has permission for 'addRoute'                      │
│  - 401/403 if unauthorized                                       │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Route to Command                                                 │
│  - Check 'addRoute' in secure/management/routes.php              │
│  - TrimParametersManagement parses body                          │
│  - Include secure/management/command/addRoute.php                │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ addRoute.php                                                     │
│  - Validate parameters (route format, length, characters)        │
│  - Check route doesn't exist                                     │
│  - Create folder structure                                       │
│  - Create JSON template file                                     │
│  - Update routes.php                                             │
│  - Return ApiResponse with success/error                         │
└─────────────────────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────────────────────┐
│ ApiResponse::send()                                              │
│  - Set HTTP status code                                          │
│  - Set Content-Type: application/json                            │
│  - Output JSON response                                          │
│  - Log command execution (if enabled)                            │
└─────────────────────────────────────────────────────────────────┘
```

---

## Multi-Project Architecture

### Concept

One QuickSite installation can manage multiple independent websites:

```
secure/projects/
├── portfolio/          # Personal portfolio site
├── client-website/     # Client's business site
└── documentation/      # Product documentation
```

### Switching Projects

```
PATCH /management/switchProject
{"project": "client-website"}
```

What happens:
1. `target.php` updated to point to new project
2. Project's `public/` folder synced to live `public/` (assets, favicon)
3. Project's `style/` synced to live `public/style/`
4. All subsequent requests use new project's config, routes, templates

### Project Isolation

Each project has its own:
- `config.php` - Languages, settings
- `routes.php` - Page routes
- `templates/` - Page structures
- `translate/` - Language files
- `interactions/` - Interaction configs
- `backups/` - Project-specific backups

Shared across projects:
- Management API (commands)
- Interaction schemas (`secure/interaction-schemas/`)
- Authentication tokens
- Admin panel

---

## The Management API

### Command Pattern

Every API endpoint is a "command" - a single PHP file that:
1. Validates input parameters
2. Performs the operation
3. Returns an `ApiResponse`

```php
// secure/management/command/addRoute.php

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$params = $trimParametersManagement->params();
$route = $params['route'] ?? null;

// Validation
if ($route === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Route is required')
        ->send();
}

// Operation
// ... create route files ...

// Success response
ApiResponse::create(201, 'route.created')
    ->withMessage('Route created successfully')
    ->withData(['route' => $route])
    ->send();
```

### Response Format

All API responses follow a consistent structure:

```json
{
  "status": 201,
  "code": "route.created",
  "message": "Route created successfully",
  "data": {
    "route": "contact"
  }
}
```

Error responses include detailed information:

```json
{
  "status": 400,
  "code": "validation.invalid_format",
  "message": "Invalid route format",
  "errors": [
    { "field": "route", "value": "Contact Us!", "reason": "invalid_characters" }
  ]
}
```

### Command Categories

| Category | Commands | Description |
|----------|----------|-------------|
| **Pages** | addRoute, deleteRoute, getRoutes | Route CRUD |
| **Structure** | getStructure, editStructure, addNode, moveNode | JSON structure manipulation |
| **Translation** | getTranslations, setTranslationKeys, validateTranslations | i18n management |
| **Assets** | uploadAsset, deleteAsset, listAssets | File management |
| **Styles** | getStyles, setStyleRule, setKeyframes | CSS editing |
| **Build** | build, listBuilds, deployBuild | Production builds |
| **Projects** | createProject, switchProject, backupProject | Multi-project |
| **Auth** | generateToken, revokeToken, listRoles | Security |
| **Interactions** | addInteraction, buildInteractions | JS behaviors |
| **AI** | callAi, testAiKey, listAiProviders | AI integration |

---

## Translation System

### File Structure

```
project/translate/
├── en.json      # English translations
├── fr.json      # French translations
└── default.json # Fallback (mono-language mode)
```

### Translation File Format

```json
{
  "page": {
    "titles": {
      "home": "Welcome",
      "about": "About Us"
    }
  },
  "menu": {
    "home": "Home",
    "about": "About"
  },
  "home": {
    "hero": {
      "title": "Build Websites Fast",
      "subtitle": "No coding required"
    }
  }
}
```

### Usage in Structures

```json
{ "textKey": "home.hero.title" }
```

Rendered as:
```html
Build Websites Fast
```

### Translator Class

```php
$translator = new Translator('fr');
echo $translator->translate('home.hero.title');
// → "Créez des sites web rapidement"

// With interpolation
echo $translator->translate('welcome.message', ['name' => 'John']);
// "Welcome, {{name}}!" → "Welcome, John!"
```

### Translation Validation

The API provides comprehensive translation health checking:

- `validateTranslations` - Find missing keys
- `getUnusedTranslationKeys` - Find orphaned keys
- `analyzeTranslations` - Full health report

---

## Style Management

### CSS Structure Model

QuickSite models CSS as four distinct layers, each with dedicated API commands:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              style.css                                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │  1. :ROOT VARIABLES                                                │  │
│  │  ─────────────────                                                 │  │
│  │  :root {                                                           │  │
│  │    --primary-color: #007bff;                                       │  │
│  │    --spacing: 1rem;                                                │  │
│  │    --font-family: 'Inter', sans-serif;                             │  │
│  │  }                                                                 │  │
│  │                                                                    │  │
│  │  Commands: getRootVariables, setRootVariables                      │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │  2. SELECTORS (Global)                                             │  │
│  │  ────────────────────                                              │  │
│  │  .btn { padding: 0.5rem 1rem; background: var(--primary-color); }  │  │
│  │  .btn:hover { background: #0056b3; }                               │  │
│  │  .card { border-radius: 8px; box-shadow: 0 2px 4px rgba(...); }    │  │
│  │  header nav a { color: inherit; text-decoration: none; }           │  │
│  │                                                                    │  │
│  │  Commands: listStyleRules, getStyleRule, setStyleRule,             │  │
│  │            deleteStyleRule, getAnimatedSelectors                   │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │  3. @KEYFRAMES (Global)                                            │  │
│  │  ─────────────────────                                             │  │
│  │  @keyframes fadeIn {                                               │  │
│  │    from { opacity: 0; }                                            │  │
│  │    to { opacity: 1; }                                              │  │
│  │  }                                                                 │  │
│  │  @keyframes slideUp { 0% { ... } 100% { ... } }                    │  │
│  │                                                                    │  │
│  │  Commands: listKeyframes, getKeyframes, setKeyframes,              │  │
│  │            deleteKeyframes                                         │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │  4. @MEDIA QUERIES                                                 │  │
│  │  ─────────────────                                                 │  │
│  │  @media (max-width: 768px) {                                       │  │
│  │    ┌─────────────────────────────────────────────────────────┐    │  │
│  │    │  Selectors (scoped to this breakpoint)                  │    │  │
│  │    │  .btn { padding: 0.25rem 0.5rem; }                      │    │  │
│  │    │  .card { margin: 0.5rem; }                              │    │  │
│  │    └─────────────────────────────────────────────────────────┘    │  │
│  │    ┌─────────────────────────────────────────────────────────┐    │  │
│  │    │  @keyframes (scoped - rare but valid)                   │    │  │
│  │    │  @keyframes mobileSlide { ... }  ← only active when     │    │  │
│  │    │                                    media query matches  │    │  │
│  │    └─────────────────────────────────────────────────────────┘    │  │
│  │  }                                                                 │  │
│  │                                                                    │  │
│  │  Commands: getStyleRule (with mediaQuery param),                   │  │
│  │            setStyleRule (with mediaQuery param)                    │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### CSS Parser

The `CssParser.php` class provides programmatic access to all four layers:

- **Parse** CSS into a structured AST
- **Extract/modify** `:root` variables independently
- **CRUD** operations on any selector (global or within media query)
- **Manage** `@keyframes` animations
- **Query** selectors with transitions/animations (orphan detection)

### Style Commands

```bash
# Get all CSS variables
GET /management/getRootVariables
→ { "--primary-color": "#007bff", "--spacing": "1rem" }

# Update a variable
POST /management/setRootVariables
{"--primary-color": "#ff6600"}

# Get styles for a selector
GET /management/getStyleRule/.btn
→ { "background": "var(--primary-color)", "padding": "0.5rem 1rem" }

# Update selector styles
POST /management/setStyleRule
{"selector": ".btn:hover", "styles": {"background": "#0056b3"}}
```

### Visual Editor Integration

The CSS system supports the Visual Editor with:

- `getCssForStructure` - Get CSS relevant to a page's selectors
- `getAnimatedSelectors` - Find selectors with transitions/animations
- Orphan detection for unused transition states

---

## Interactions System

### Concept

Instead of injecting arbitrary JavaScript, users define **declarative interactions** in JSON. QuickSite compiles these to optimized JS at build time.

### Two-Layer Architecture

```
┌────────────────────────────────┐
│     INTERACTION SCHEMAS        │  "What CAN exist" (blueprints)
│  secure/interaction-schemas/   │  Shared across all projects
│  ├── core/                     │
│  │   ├── show.json             │
│  │   ├── hide.json             │
│  │   ├── filter.json           │
│  │   └── api.json              │
│  └── custom/                   │  User-defined types
└────────────────────────────────┘
                │
                │ referenced by
                ▼
┌────────────────────────────────┐
│    PROJECT INTERACTIONS        │  "What IS configured" (instances)
│  project/interactions/         │  Per-project configurations
│  ├── search-cards.json         │
│  └── contact-form.json         │
└────────────────────────────────┘
                │
                │ buildInteractions
                ▼
┌────────────────────────────────┐
│     COMPILED RUNTIME           │
│  public/assets/scripts/        │
│  └── interactions.js           │
└────────────────────────────────┘
```

### Schema Definition

```json
{
  "name": "filter",
  "description": "Filter elements based on text input",
  "category": "dom",
  "parameters": {
    "required": {
      "target": { "type": "selector", "description": "Elements to filter" }
    },
    "optional": {
      "hideClass": { "type": "string", "default": "hidden" },
      "debounce": { "type": "number", "default": 150 }
    }
  },
  "supports": {
    "onSuccess": false,
    "onFailed": false
  }
}
```

### Interaction Instance

```json
{
  "name": "search-products",
  "schema": "filter",
  "trigger": {
    "selector": "#search-input",
    "event": "input"
  },
  "params": {
    "target": ".product-card",
    "hideClass": "hidden",
    "debounce": 200
  }
}
```

### Core Schemas

| Schema | Purpose |
|--------|---------|
| `show` | Reveal hidden elements |
| `hide` | Hide elements |
| `toggleClass` | Add/remove CSS classes |
| `filter` | Client-side search/filter |
| `setValue` | Update element content |
| `redirect` | Navigate to URL |
| `api` | External API calls (with proxy option) |

---

## Build & Deploy System

### Build Process

```
POST /management/build
{"public": "www", "secure": "app"}
```

**Steps:**

1. **Lock** - Prevent concurrent builds
2. **Validate** - Check folder names, available space
3. **Create structure** - Build output directory
4. **Compile pages** - JSON → PHP via `JsonToPhpCompiler`
5. **Compile menu/footer** - Same process
6. **Build interactions** - Compile JS if project has interactions
7. **Copy assets** - Images, fonts, videos, styles
8. **Sanitize config** - Remove database credentials
9. **Generate init.php** - Adjusted for new folder names
10. **Create ZIP** - Package everything
11. **Report** - Return build stats

### Build Output

```
secure/builds/build_20260115_143022/
├── www/                    # Public folder (renamed)
│   ├── index.php
│   ├── init.php            # Adjusted constants
│   ├── assets/
│   │   └── scripts/
│   │       └── interactions.js
│   └── style/
└── app/                    # Secure folder (renamed)
    └── projects/
        └── {name}/
            ├── config.php  # Sanitized (no DB creds)
            └── templates/
                └── pages/  # Compiled PHP
```

### Deploy

```
POST /management/deployBuild
{"name": "build_20260115_143022", "target": "/var/www/production"}
```

---

## Security Architecture

### Authentication

```
Authorization: Bearer tvt_xxxxx
```

Tokens are stored in `secure/management/config/auth.php`:

```php
return [
    'tvt_dev_default_change_me_in_production' => [
        'name' => 'Default Dev Token',
        'role' => 'admin',
        'created' => '2026-01-01'
    ]
];
```

### Role-Based Access Control

| Role | Access Level |
|------|-------------|
| `viewer` | Read-only (list*, get*, help) |
| `editor` | Content editing (add*, edit*, delete content) |
| `designer` | + CSS/style commands |
| `developer` | + Build, deploy, AI, interactions |
| `admin` | Everything except token management |
| `*` (superadmin) | Full access including token/role management |

### Security Measures

1. **Path Traversal Protection**
   - All paths validated against `..` sequences
   - Paths normalized before use
   - Whitelist-based directory access

2. **XSS Prevention**
   - Tag blacklist: `script`, `noscript`, `style`, `template`, `slot`
   - Output escaping in all rendered content
   - CSP headers (configurable)

3. **File Upload Security**
   - MIME type validation (actual content, not just extension)
   - File size limits
   - JS uploads blocked (scripts category removed)
   - Allowed categories: images, fonts, videos, documents

4. **API Security**
   - All endpoints require authentication
   - Rate limiting (configurable)
   - Request logging
   - CORS configuration

---

## Admin Panel

### Architecture

The admin panel is a PHP-based SPA at `/admin/`:

```
public/admin/
├── index.php           # Entry point, routes to AdminRouter
└── api/                # Admin-specific API calls

secure/admin/
├── AdminRouter.php     # Page routing
├── AdminHelper.php     # Command categorization, UI helpers
├── templates/
│   ├── layout.php      # Main layout (nav, header)
│   └── pages/
│       ├── dashboard.php
│       ├── pages.php
│       ├── structure.php
│       ├── translations.php
│       ├── styles.php
│       ├── interactions.php
│       └── ...
└── translations/
    ├── en.json         # Admin UI translations
    └── fr.json
```

### Visual Editor

The Visual Editor (`/admin/structure`) is the flagship feature embodying QuickSite's "configure, don't code" philosophy. It allows users to build and modify pages **visually**, seeing changes in real-time without writing code or refreshing the browser.

#### Purpose

```
Traditional CMS                          QuickSite Visual Editor
─────────────────                        ───────────────────────
1. Edit code/template                    1. Click element
2. Save file                             2. Change property in panel
3. Refresh browser                       3. See change instantly
4. Check result                          4. Done ✓
5. Repeat if wrong...
```

The goal is to **minimize the gap between intention and result**. When possible, users interact through UI controls (color pickers, dropdowns, sliders) rather than typing code.

#### Editor Modes

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Visual Editor Toolbar                                                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                    │
│  │ 🔍 SELECT │ │ ✋ DRAG   │ │ ✏️ TEXT   │ │ 🎨 STYLE  │                    │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘                    │
│                                                                          │
│  SELECT Mode (default)                                                   │
│  ├─ Click any element to select it                                       │
│  ├─ View node information (tag, classes, attributes, path)               │
│  ├─ Add new element: before, after, or inside selected node              │
│  └─ Delete or duplicate the selected node                                │
│                                                                          │
│  DRAG Mode                                                               │
│  ├─ Drag elements to reorder within parent                               │
│  ├─ Drop between siblings to change position                             │
│  └─ Visual guides show drop targets                                      │
│                                                                          │
│  TEXT Mode                                                               │
│  ├─ Click text to edit its translation value inline                      │
│  ├─ Edits the translation for the currently selected LANGUAGE            │
│  ├─ Does NOT change the translation key - only its value                 │
│  ├─ Intentionally primitive: no rich text, no line breaks                │
│  ├─ Press Enter to confirm change (not for newlines)                     │
│  └─ Separates concerns: structure (select/drag) vs content (text)        │
│                                                                          │
│  STYLE Mode                                                              │
│  ├─ Click element to open CSS panel                                      │
│  ├─ Edit styles visually (spacing, colors, typography)                   │
│  ├─ Changes apply to element's CSS selector                              │
│  └─ Supports pseudo-states (:hover, :focus, :active)                     │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Device Preview

Responsive design preview without leaving the editor:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Device Selector                                                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐                                 │
│  │ 🖥️ Desktop │ │ 📱 Tablet │ │ 📱 Mobile │                                 │
│  │  1920px   │ │  768px   │ │  375px   │                                 │
│  └──────────┘ └──────────┘ └──────────┘                                 │
│                                                                          │
│  Preview iframe resizes to simulate device width                         │
│  CSS media queries apply automatically                                   │
│  Edit styles per breakpoint in Style Mode                                │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Context Selectors

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Context Bar                                                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Page: [ home ▼ ]                        Language: [ EN ▼ ]             │
│                                                                          │
│  Page Selector                                                           │
│  ├─ Switch between pages without leaving editor                          │
│  └─ Dropdown lists all routes (home, about, contact, guides/install...)  │
│                                                                          │
│  Language Selector                                                       │
│  ├─ Preview site in different languages                                  │
│  ├─ Text edits (TEXT mode) update the selected language's translations   │
│  └─ Useful for checking layout with longer/shorter text                  │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Shared Elements (Menu & Footer)

Menu and footer are **global** - they appear on every page. When you edit them
in the Visual Editor (on any page), changes apply **site-wide**:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Page Structure in Editor                                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │  MENU (shared across all pages)                                 │    │
│  │  ⚠️  Editing here affects the entire website                     │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │  PAGE CONTENT (unique per page)                                 │    │
│  │  Edits only affect the currently selected page                  │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │  FOOTER (shared across all pages)                               │    │
│  │  ⚠️  Editing here affects the entire website                     │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

This unified view keeps the editing experience cohesive - you always see the
full page as visitors would, without switching between separate "menu editor"
or "footer editor" views.

#### How It Works (Technical)

```
┌─────────────────┐     postMessage      ┌─────────────────┐
│   Admin Panel   │ ◄──────────────────► │  Preview iframe │
│   (Editor UI)   │                      │   (?_editor=1)  │
└─────────────────┘                      └─────────────────┘
        │                                        │
        │ API calls                              │ data-qs-* attributes
        ▼                                        ▼
┌─────────────────┐                      ┌─────────────────┐
│  Management API │                      │ JsonToHtmlRenderer │
│  (editNode, etc)│                      │ (editor mode)   │
└─────────────────┘                      └─────────────────┘
```

1. Preview loads page with `?_editor=1` query parameter
2. `JsonToHtmlRenderer` adds `data-qs-node` and `data-qs-struct` attributes
3. User clicks element → iframe sends node path via `postMessage`
4. Admin panel shows properties for selected node
5. User edits → API call to `editNode`, `editStructure`, etc.
6. Preview refreshes (or updates inline for text changes)

---

## Design Decisions

### Why JSON over YAML/TOML?

- Native PHP support (`json_encode`/`json_decode`)
- No external dependencies
- Strict format prevents ambiguity
- Easy for AI to generate/parse

### Why Compile to PHP Instead of Caching HTML?

- Translations remain dynamic (no rebuild for language changes)
- Conditional content still works
- Lower storage (one template, many outputs)
- Familiar deployment (just PHP files)

### Why File-Based Over SQLite?

- Simpler deployment (copy folders)
- Better Git integration
- Easier debugging (human-readable files)
- No connection management
- Works on any PHP host

### Why Separate Public/Secure?

- Security: sensitive files never web-accessible
- Clarity: clear deployment boundary
- Flexibility: rename folders per deployment
- Hosting: some hosts require specific public folder names

### Why Role-Based (Not Permission-Based)?

- Simpler mental model
- Covers 99% of use cases
- Easy to extend (custom roles supported)
- Predictable (role → set of commands)

---

## Further Reading

- [README.md](README.md) - Quick start guide
- `/management/help` - Full API documentation

---

*Last updated: January 15, 2026*
