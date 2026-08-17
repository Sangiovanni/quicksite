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
│   │   │   ├── *-throttle.json   # Login + registration backoff counters, hashed keys (machine-written, gitignored)
│   │   │   ├── roles.php         # Role definitions (gitignored)
│   │   │   ├── environment.php   # production | development — SSRF/error gate (gitignored; default production)
│   │   │   ├── operator.php      # Accounts that see operator notices — display only, grants nothing (gitignored; written at first run; default: nobody)
│   │   │   ├── setup-token.txt   # First-run credential (gitignored; minted when the first-run page renders, destroyed on use)
│   │   │   ├── deploy-roots.php  # deployBuild allowed target roots (gitignored; default SERVER_ROOT only)
│   │   │   └── import-policy.php # Archive import + publish allowlists and archive size limits (gitignored; optional, built-in defaults apply without it)
│   │   └── routes.php            # Command whitelist
│   ├── admin/                    # Admin panel backend
│   │   ├── AdminRouter.php       # Admin routing and page rendering
│   │   ├── config/               # Admin panel configuration
│   │   ├── functions/            # Admin helper functions
│   │   ├── templates/            # Admin panel page templates
│   │   ├── translations/         # Admin UI translations
│   │   └── workflows/            # Visual editor workflow specs
│   ├── src/                      # Shared engine code
│   │   ├── classes/              # Core classes (ApiResponse, JsonToHtmlRenderer,
│   │   │                         #   JsonToPhpCompiler, CssParser, Translator, etc.)
│   │   └── functions/            # Utility functions (auth, paths, logging, etc.)
│   ├── projects/                 # Project data (one folder per project)
│   │   └── quicksite/            # Default project
│   │       ├── config.php        # Project config (languages, settings)
│   │       ├── config/           # Access control — members.json: owner, visibility, join_policy,
│   │       │                     #   members {userId → role} + pending invitations/requests (gitignored)
│   │       ├── routes.php        # Public route definitions
│   │       ├── templates/        # Page and component JSON structures
│   │       ├── translate/        # Translation files (en.json, fr.json, etc.)
│   │       ├── data/             # Project data (aliases, asset metadata, API
│   │       │                     #   endpoints, state stores, route resolvers)
│   │       ├── public/           # What /p/<id>/ serves — the project's own web files
│   │       │   ├── assets/       #   images / font / audio / videos
│   │       │   ├── style/        #   style.css (editable via API)
│   │       │   ├── scripts/      #   generated qs-api-config / qs-enums / qs-route-schema
│   │       │   ├── sitemap.txt   #   published sitemap (generated)
│   │       │   └── build/        #   production builds (generated, gitignored)
│   │       ├── snippets/         # Snippets belonging to this project alone
│   │       ├── exports/          # This project's export ZIPs (generated)
│   │       └── backups/          # Project backups (gitignored)
│   ├── snippets/                 # Reusable content snippets (nav, cards, forms, etc.)
│   │   ├── core/                 #   shipped with the engine, read-only
│   │   └── custom/               #   personal libraries — one folder per user
│   │       └── usr_<id>/         #     that user's own snippets, private to them
│   ├── deploy/                   # Apache + nginx vhost examples for the install
│   ├── nginx/                    # Auto-generated nginx config (dynamic_routes.conf)
│   ├── cron/                     # Optional cron scripts (nginx reload fallback)
│   ├── tmp/                      # Scratch space (gitignored)
│   │   └── sessions/             #   PHP session files — kept here, not in the shared
│   │                             #   system path, so another application on the same
│   │                             #   host cannot expire QuickSite's sessions
│   └── logs/                     # Command execution logs, partitioned per project (gitignored)
│
├── docs/                         # Documentation (this folder)
├── tests/                        # Test suite
├── setup.sh                      # Interactive setup wizard (Linux/macOS/Git Bash)
├── setup.bat                     # Setup script (Windows)
├── VERSION                       # Current version
├── LICENSE                       # AGPL-3.0
├── PHILOSOPHY.md                 # Design principles
└── README.md
```

Note what is **not** under `public/`: no site assets, styles, generated scripts,
sitemap or builds. Those belong to a project and live in that project's own
`secure/projects/<id>/public/`, reached through `/p/<id>/`. The web root holds
the two entry points and nothing else, which is what leaves it free for an
operator's own site.

## Key concepts

- **`public/`** is the only folder exposed to the web. Everything else is behind the firewall.
- **`public/management/`** is the API gateway. Any client (admin panel, curl, Flutter app, custom UI) talks to QuickSite through this endpoint.
- **The shared `qs.js` runtime is engine-owned** (`secure/src/runtime/qs.js`) and served to every project. It handles front-end features like show/hide triggers, filtering, fetches, and state stores. What lands in a project's own `public/scripts/` is generated per-project config — the `qs-api-config` / `qs-enums` / `qs-route-schema` trio.
- **`secure/management/config/`** holds sensitive files (sessions, auth policy, the user registry) that are gitignored. `auth.php` and `roles.php` are auto-created from `.example` templates on first load; `users.php` is written when the first account is created.
- **Projects** are fully isolated in `secure/projects/`. Each has its own pages, translations, routes, and assets, and each is served from its own folder under its own `/p/<projectId>/` view — no project is privileged. Change which one you are *editing* with `setSelectedProject` (the admin header picker).
- **Snippets live in three tiers.** `secure/snippets/core/` ships with the engine and is read-only. `secure/snippets/custom/<userId>/` is one author's own library — private to them, and available to them in every project they work on. `secure/projects/<id>/snippets/` belongs to that project alone. See [COMMAND_API.md](COMMAND_API.md) for how a snippet is saved to each and how reads resolve between them.

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
