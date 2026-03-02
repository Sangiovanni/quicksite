# QuickSite

A file-based PHP CMS with a built-in visual admin panel. Define page structures in JSON, manage everything through a REST API or the admin UI, and deploy production builds — no database required.

> **Current version: `1.0.0-beta.1`** — Actively developed. Tutorials and documentation are on their way.

## What is QuickSite?

QuickSite started as a simple HTML template and evolved into a full CMS. The idea: manage an entire website — pages, translations, styles, assets, components — through a clean API, with all data stored as flat files you can version-control and deploy anywhere.

It now includes a **visual admin panel** with an iframe-based page editor, letting you build and edit sites directly in the browser without writing code. The API remains the backbone — the admin panel is a client of its own API.

### Key features

- **Visual Admin Panel** — iframe-based page editor with drag-and-drop node management, live preview, and component library
- **119 API Commands** — RESTful endpoints covering pages, translations, styles, assets, builds, projects, backups, AI integration, and more
- **JSON-Driven Templates** — page structures defined in JSON, compiled to optimized PHP for production
- **Multilingual** — built-in translation system with validation, health checks, and mono/multi-language modes
- **Multi-Project** — host multiple independent sites from one installation
- **Production Builds** — one-command compilation, optimization, and ZIP packaging
- **File-Based** — no database, no migrations. JSON + PHP files, deployable anywhere
- **Role-Based Access** — bearer token auth with granular permissions (viewer, editor, designer, developer, admin, superadmin)
- **Self-Updating** — built-in update checker and updater via GitHub
- **AI Integration (BYOK)** — proxy AI requests through the server with your own API keys (OpenAI, Anthropic, Google, Mistral)

## Requirements

- **PHP** 7.4+ (tested up to 8.4)
- **Apache** with `mod_rewrite`
- **PHP extensions**: json, fileinfo, zip

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Sangiovanni/quicksite.git
   cd quicksite
   ```

2. **Point a virtual host to `public/`**
   ```apache
   <VirtualHost *:80>
       ServerName quicksite.local
       DocumentRoot "/path/to/quicksite/public"
       <Directory "/path/to/quicksite/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

3. **Open the admin panel**
   Navigate to `http://quicksite.local/admin/` in your browser.
   Config files (`target.php`, `auth.php`, `roles.php`) are auto-created from `.example` templates on first load. The default token is in `auth.php` — change it before going to production.

> **Why a virtual host?** QuickSite uses Apache's `FallbackResource` for clean URLs (`/about`, `/en/contact`) instead of query strings (`?page=about&lang=en`). This is the same approach used by WordPress, Laravel, and most modern PHP projects. The virtual host makes your local development environment match production — your `public/` folder is the document root, just like it would be on a real server. Subdirectory mode (e.g., `http://localhost/quicksite/public/`) is not supported.

## Project structure

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
│   │   ├── qs-custom.js          # User-defined JS functions (managed via API)
│   │   └── qs-api-config.js      # Client-side API endpoint configuration
│   ├── style/                    # Site styles
│   │   ├── style.css             # Main stylesheet (editable via API)
│   │   └── index.php             # Style route handler
│   └── build/                    # Production builds output (generated, gitignored)
│
├── secure/                       # Backend (outside web root, not publicly accessible)
│   ├── management/               # API engine (shared across all projects)
│   │   ├── command/              # 119 command handler files (one per command)
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
│   ├── config/                   # Global config (currently unused, reserved)
│   ├── exports/                  # Project export ZIPs (generated)
│   └── logs/                     # Command execution logs (gitignored)
│
├── docs/                         # Documentation (coming soon)
├── tests/                        # Test suite
├── VERSION                       # Current version (1.0.0-beta.1)
├── LICENSE                       # AGPL-3.0
└── README.md
```

**Key concepts:**
- **`public/`** is the only folder exposed to the web. Everything else is behind the firewall.
- **`public/management/`** is the API gateway. Any client (admin panel, curl, Flutter app, custom UI) talks to QuickSite through this endpoint.
- **`public/scripts/`** contains the core JS runtime. `qs.js` handles front-end features like show/hide triggers, sorting, and dynamic behavior. `qs-custom.js` holds user-defined JS functions managed via the `addJsFunction` / `editJsFunction` API commands.
- **`secure/management/config/`** holds sensitive files (tokens, auth) that are gitignored. They are auto-created from `.example` templates on first load.
- **Projects** are fully isolated in `secure/projects/`. Each has its own pages, translations, routes, and assets. Switch between them with `switchProject`.

### Folder customization

Three commands let you adapt the folder structure to match your hosting environment after installation:

| Command | What it does | Example |
|---------|-------------|---------|
| `renamePublicFolder` | Renames the `public/` folder (e.g., to `www/` or `public_html/`). Updates `init.php` constants. Requires updating your Apache vhost after. | Shared hosting with a fixed `public_html/` document root |
| `renameSecureFolder` | Renames the `secure/` folder (e.g., to `backend/` or `app/`). Updates `init.php` constants. | Convention matching or security by obscurity |
| `setPublicSpace` | Moves public files into a subdirectory inside the document root, adjusting all `.htaccess` files and `init.php`. | Shared hosting where your site lives at `www.example.com/mysite/` — set space to `mysite` |

## API overview

The API is self-documenting. Once installed, call:

```
GET /management/help
```

This returns full documentation for all 119 commands, including parameters, examples, validation rules, and error codes. For a specific command:

```
GET /management/help/addRoute
```

**Command categories**: pages, structure, translations, languages, assets, styles, CSS variables, animations, builds, projects, backups, export/import, tokens, roles, AI, snippets, JS functions, interactions, page events, API endpoints, system updates.

**Authentication**: All endpoints require a bearer token. Tokens are scoped to roles with granular command-level permissions.

## Tutorials

Step-by-step tutorials demonstrating the admin panel and API workflows are coming soon.

## Vision

QuickSite is built on a **file-based, zero-database philosophy**. It targets a specific niche: sites that don't need a database — landing pages, portfolios, documentation sites, microsites — but still deserve proper tooling for content management, translations, and deployment.

The admin panel makes it accessible to non-developers, while the API keeps it powerful for automation and integration. Read more about the project's design principles in [PHILOSOPHY.md](PHILOSOPHY.md).

## Contributing

Contributions are welcome under the AGPL-3.0 License.

1. Fork the repository
2. Create a feature branch
3. Submit a Pull Request

All contributions go through code review for security, quality, and consistency.

## Acknowledgments

Built using a **hybrid human-AI development approach** — architecture and design decisions are human-driven, implementation assisted by GitHub Copilot (Claude). More about this workflow in [PHILOSOPHY.md](PHILOSOPHY.md).

## License

AGPL-3.0 — free to use, modify, and self-host. If you offer QuickSite as a hosted service, you must share your modifications under the same license. See [LICENSE](LICENSE).

## Support

- **Issues**: [GitHub Issues](https://github.com/Sangiovanni/quicksite/issues)
- **API Reference**: `GET /management/help` — built into every installation
- **Buy Me a Coffee**: [buymeacoffee.com/sangio](https://buymeacoffee.com/sangio)

---

Made by [Sangio](https://github.com/Sangiovanni)
