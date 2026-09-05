# QuickSite Project Structure

> On-disk layout of a QuickSite installation, with notes on which folders are public, which are private, and which are generated.

## Tree

```
quicksite/
├── public/                       # Web root (Apache DocumentRoot points here)
│   ├── init.php                  # Bootstrap — defines the install-wide path constants
│   ├── .htaccess                 # No fallback — the web root serves real files only
│   ├── p/                        # Per-project live views  (/p/<projectId>/)
│   │   ├── index.php             # Front controller — renders a project from its own folder
│   │   └── .htaccess             # Routes all /p/* to this index.php
│   ├── admin/                    # Admin panel UI (HTML/JS/CSS)
│   │   ├── index.php             # Panel router — pages, auth, rendering
│   │   ├── api/                  # Read-only helper arms (form options, update check)
│   │   ├── self/                 # The signed-in account and its project memberships
│   │   └── state/                # The panel's own per-user state (which project it edits)
│   └── management/               # API entry point
│       ├── index.php             # API router — auth, dispatch, logging
│       └── .htaccess             # Routes all /management/* to this index.php
│
├── secure/                       # Backend (outside web root, not publicly accessible)
│   ├── management/               # API engine (shared across all projects)
│   │   ├── command/              # Command handler files (one per command; see COMMAND_API.md)
│   │   ├── config/               # API configuration
│   │   │   ├── auth.php          # Session lifetimes, registration policy (self-registration + flood controls), CORS (gitignored)
│   │   │   ├── users.php         # User registry: name + username + password_hash + session generation + per-user project list (gitignored)
│   │   │   ├── *-throttle.json   # Login, registration and upload backoff counters, hashed keys (machine-written, gitignored)
│   │   │   ├── roles.php         # Role definitions (gitignored)
│   │   │   ├── categories.php    # Each command's category and scope. TRACKED, not operator config —
│   │   │   │                     #   it is what makes a command global or project-scoped, and what a
│   │   │   │                     #   role's granted categories expand to
│   │   │   ├── environment.php   # production | development — SSRF/error gate (gitignored; default production)
│   │   │   ├── operator.php      # Accounts that see operator notices — display only, grants nothing (gitignored; written at first run; default: nobody)
│   │   │   ├── setup-token.txt   # First-run credential (gitignored; minted when the first-run page renders, destroyed on use)
│   │   │   ├── deploy.php        # allow_deploy — may this install deploy at all (gitignored; ABSENT MEANS NO)
│   │   │   ├── deploy-roots.php  # deployBuild allowed target roots (gitignored; default SERVER_ROOT only)
│   │   │   ├── quota.php         # Per-account storage ceiling and upload rate (gitignored; optional, ABSENT MEANS NO LIMIT)
│   │   │   ├── console.php       # Is the /admin/command console offered here (optional; ABSENT MEANS OFFERED)
│   │   │   └── import-policy.php # Archive import + publish allowlists and archive size limits (gitignored; optional, built-in defaults apply without it)
│   │   └── routes.php            # Command whitelist
│   ├── admin/                    # Admin panel backend
│   │   ├── AdminRouter.php       # Admin routing and page rendering
│   │   ├── config/               # Server-side secrets the browser never sees — data-resolver
│   │   │                         #   API keys and OAuth client secrets (gitignored, with tracked
│   │   │                         #   .example templates), plus the OAuth provider presets
│   │   ├── functions/            # Admin helper functions
│   │   ├── templates/            # Admin panel page templates
│   │   ├── translations/         # Admin UI translations
│   │   └── workflows/            # Workflow specs, prompt templates and their schema — the
│   │                             #   manual and AI-assisted runs offered on /admin/workflows
│   ├── src/                      # Shared engine code
│   │   ├── classes/              # Core classes (ApiResponse, JsonToHtmlRenderer,
│   │   │                         #   JsonToPhpCompiler, CssParser, Translator, etc.)
│   │   ├── functions/            # Utility functions (auth, paths, logging, etc.)
│   │   └── runtime/              # What ships to a SITE rather than running the engine
│   │       ├── qs.js             #   the browser runtime, copied into every build
│   │       └── site/index.php    #   a built site's front controller, copied verbatim
│   ├── projects/                 # Project data (one folder per project)
│   │   └── quicksite/            # Default project
│   │       ├── config.php        # Project config (languages, settings)
│   │       ├── config/           # Per-project settings that are not page content:
│   │       │                     #   members.json (owner, visibility, join_policy, members
│   │       │                     #   {userId → role} + pending invitations/requests — gitignored),
│   │       │                     #   route-layout.json, sitemap-config.json, custom-js-functions.json
│   │       ├── routes.php        # Public route definitions
│   │       ├── templates/        # Page and component JSON structures
│   │       ├── translate/        # Translation files (en.json, fr.json, etc.)
│   │       ├── data/             # Project data (aliases, asset metadata, API endpoints,
│   │       │                     #   state stores, route resolvers, page events, the storage
│   │       │                     #   and consent registries). oauth-secrets.json is the one
│   │       │                     #   secret here and is gitignored
│   │       ├── public/           # What /p/<projectId>/ serves — the project's own web files
│   │       │   ├── assets/       #   images / font / audio / videos
│   │       │   ├── style/        #   style.css (editable via API)
│   │       │   ├── scripts/      #   generated qs-api-config / qs-enums / qs-route-schema
│   │       │   └── sitemap.txt   #   published sitemap (generated)
│   │       ├── qs_build/         # The project's build (generated, gitignored)
│   │       │   └── <name>/       #   OUTSIDE public/, so no URL reaches it; at most one
│   │       │                     #   build, fetched via downloadBuild. A self-contained
│   │       │                     #   site: <public>/ (front controller, qs-site.php,
│   │       │                     #   .htaccess, style, assets, scripts) beside <secure>/
│   │       │                     #   (precompiled pages, the request-time runtime,
│   │       │                     #   translations, and the project data a served
│   │       │                     #   page reads — resolvers, API registry, consent)
│   │       ├── snippets/         # Snippets belonging to this project alone
│   │       ├── exports/          # This project's export ZIPs (generated)
│   │       └── backups/          # Project backups (gitignored)
│   ├── snippets/                 # Reusable content snippets (nav, cards, forms, etc.)
│   │   ├── core/                 #   shipped with the engine, read-only
│   │   └── custom/               #   personal libraries — one folder per user
│   │       └── <userId>/         #     that user's own snippets, private to them. The folder
│   │                             #     name IS the user id, which is minted as usr_ + hex
│   ├── deploy/                   # Apache + nginx vhost examples for the install
│   ├── nginx/                    # Auto-generated nginx config, dynamic_routes.conf (gitignored)
│   ├── cron/                     # Optional cron scripts (nginx reload fallback)
│   ├── cli/                      # Scripts an operator runs on the machine, not through the API,
│   │                             #   because they act on the whole installation rather than on one
│   │                             #   project — session-sweep.php tidies the session store
│   ├── cache/                    # Generated caches, safe to delete (gitignored)
│   │   ├── resolver/             #   data-resolver responses, held for their TTL
│   │   └── space/                #   measured per-project disk sizes
│   ├── tmp/                      # Scratch space (gitignored)
│   │   └── sessions/             #   PHP session files — kept here, not in the shared
│   │                             #   system path, so another application on the same
│   │                             #   host cannot expire QuickSite's sessions
│   └── logs/                     # Command history and the security trail (gitignored)
│       ├── p/<projectId>/        #   one bucket per project — the commands run against it
│       ├── _global/              #   commands that target no project
│       └── _security/            #   the installation-wide security trail: sign-ins, refusals
│                                 #     and account changes. Not a command log — a `_` prefix
│                                 #     marks a bucket no project id can collide with
│
├── docs/                         # Documentation (this folder)
├── tests/                        # Test suite
├── setup.sh                      # Interactive setup wizard (Linux/macOS/Git Bash)
├── setup.bat                     # Setup script (Windows)
├── VERSION                       # Current version
├── LICENSE                       # AGPL-3.0
├── PHILOSOPHY.md                 # Design principles
├── CLAUDE.md                     # Engineering standards — for somebody CHANGING QuickSite
├── MAINTAINING.md                # The procedures for adding or removing a command
└── README.md
```

Note what is **not** under `public/`: no site assets, styles, generated scripts,
sitemap or builds. Those belong to a project and live in that project's own
`secure/projects/<projectId>/public/`, reached through `/p/<projectId>/`. The web root holds
QuickSite's entry points and nothing else, which is what leaves it free for an
operator's own site.

## Key concepts

- **`public/`** is the only folder exposed to the web. Everything else is behind the firewall.
- **`public/management/`** is the API gateway. Any client (admin panel, curl, Flutter app, custom UI) talks to QuickSite through this endpoint.
- **The shared `qs.js` runtime is engine-owned** (`secure/src/runtime/qs.js`) and served to every project. It handles front-end features like show/hide triggers, filtering, fetches, and state stores. What lands in a project's own `public/scripts/` is generated per-project config — the `qs-api-config` / `qs-enums` / `qs-route-schema` trio.
- **`secure/src/runtime/` is what ships to a site**, as opposed to `src/classes/` and `src/functions/`, which run the engine. `qs.js` is its browser half; `site/index.php` is its server half — the front controller a build copies verbatim into the site it produces. Neither is used by the install itself: the install's own web root is deliberately free of an entry point, so QuickSite never squats the domain.
- **`secure/management/config/`** holds the installation's own sensitive files — auth policy, the user registry, the first-run token, the deploy and import policies — and every one of those is gitignored. Session *files* are not here; they live under `secure/tmp/sessions/`. `auth.php` and `roles.php` are auto-created from `.example` templates on first load; `users.php` is written when the first account is created. `categories.php` and `assetCategories.php` sit alongside them but are tracked engine registries rather than operator config.
- **Projects** are fully isolated in `secure/projects/`. Each has its own pages, translations, routes, and assets, and each is served from its own folder under its own `/p/<projectId>/` view — no project is privileged. Change which one you are *editing* with the admin header picker.
- **Snippets live in three tiers.** `secure/snippets/core/` ships with the engine and is read-only. `secure/snippets/custom/<userId>/` is one author's own library — private to them, and available to them in every project they work on. `secure/projects/<projectId>/snippets/` belongs to that project alone. See [COMMAND_API.md](COMMAND_API.md) for how a snippet is saved to each and how reads resolve between them.

## Folder customization

The setup scripts (`setup.sh` / `setup.bat`) handle all folder customization:

| Step | What it does | Example |
|------|-------------|--------|
| **1. Public folder** | Renames `public/` to match your vhost DocumentRoot. Updates `init.php`. | `public_html`, `www`, `www.example.com` |
| **2. Secure folder** | Renames `secure/` for obscurity, supports nesting. Updates `init.php`. | `backend`, `app`, `backends/project1` |
| **3. URL space** | Moves files into a subdirectory, adjusts `.htaccess`, nginx config, and `init.php`. | `mysite` → `http://domain/mysite/` |

All steps support renaming, nesting, un-nesting, and are re-runnable. On nginx, changing the space regenerates `secure/nginx/dynamic_routes.conf` and attempts an automatic reload. On Apache, `.htaccess` changes take effect immediately.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — three-layer model and request lifecycle.
- [docs/COMMAND_API.md](COMMAND_API.md) — Management API surface.
- [docs/ADMIN_PANEL.md](ADMIN_PANEL.md) — admin panel internals.
