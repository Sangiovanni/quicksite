# QuickSite Project Structure

> On-disk layout of a QuickSite installation, with notes on which folders are public, which are private, and which are generated.
>
> _Last updated: 2026-04-23._

> _Maintainers note:_ re-check this doc when adding top-level folders, renaming engine directories, or changing what setup scripts customize.

## Tree

```
quicksite/
├── public/                       # Web root (Apache DocumentRoot points here)
│   ├── index.php                 # Front controller — routes requests to pages
│   ├── init.php                  # Bootstrap — defines all path constants
│   ├── .htaccess                 # URL rewriting (FallbackResource → index.php)
│   ├── admin/                    # Admin panel UI (HTML/JS/CSS)
│   ├── management/               # API entry point
│   │   ├── index.php             # API router — auth, dispatch, logging
│   │   └── .htaccess             # Routes all /management/* to this index.php
│   ├── assets/                   # Uploaded files organized by type
│   │   ├── images/               # Image assets
│   │   ├── font/                 # Font files
│   │   ├── audio/                # Audio files
│   │   └── videos/               # Video files
│   ├── scripts/                  # Core front-end JS
│   │   ├── qs.js                 # QuickSite runtime (show/hide, sort, routing)
│   │   └── qs-api-config.js      # Client-side API endpoint configuration
│   ├── style/                    # Site styles
│   │   ├── style.css             # Main stylesheet (editable via API)
│   │   └── index.php             # Style route handler
│   └── build/                    # Production builds output (generated, gitignored)
│
├── secure/                       # Backend (outside web root, not publicly accessible)
│   ├── management/               # API engine (shared across all projects)
│   │   ├── command/              # 122 command handler files (one per command)
│   │   ├── config/               # API configuration
│   │   │   ├── target.php        # Active project selector (gitignored)
│   │   │   ├── auth.php          # Tokens and CORS config (gitignored)
│   │   │   └── roles.php         # Role definitions (gitignored)
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
│   │       ├── routes.php        # Public route definitions
│   │       ├── templates/        # Page and component JSON structures
│   │       ├── translate/        # Translation files (en.json, fr.json, etc.)
│   │       ├── data/             # Project data (aliases, asset metadata)
│   │       ├── public/           # Project-specific public files
│   │       └── backups/          # Project backups (gitignored)
│   ├── snippets/                 # Reusable component snippets (nav, cards, forms, etc.)
│   ├── nginx/                    # Auto-generated nginx config (dynamic_routes.conf)
│   ├── cron/                     # Optional cron scripts (nginx reload fallback)
│   ├── exports/                  # Project export ZIPs (generated)
│   └── logs/                     # Command execution logs (gitignored)
│
├── docs/                         # Documentation (this folder)
├── tests/                        # Test suite
├── setup.sh                      # Interactive setup wizard (Linux/macOS/Git Bash)
├── setup.bat                     # Setup script (Windows)
├── VERSION                       # Current version
├── LICENSE                       # AGPL-3.0
└── README.md
```

## Key concepts

- **`public/`** is the only folder exposed to the web. Everything else is behind the firewall.
- **`public/management/`** is the API gateway. Any client (admin panel, curl, Flutter app, custom UI) talks to QuickSite through this endpoint.
- **`public/scripts/`** contains the core JS runtime. `qs.js` handles front-end features like show/hide triggers, sorting, and dynamic behavior.
- **`secure/management/config/`** holds sensitive files (tokens, auth) that are gitignored. They are auto-created from `.example` templates on first load.
- **Projects** are fully isolated in `secure/projects/`. Each has its own pages, translations, routes, and assets. Switch between them with `switchProject`.

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
