# QuickSite

A file-based PHP CMS with a built-in visual admin panel. Define page structures in JSON, manage everything through a REST API or the admin UI, and deploy production builds — no database required.

> **Current version: `1.0.0-beta.2`** — Actively developed.

<a href="https://www.youtube.com/watch?v=LHheKkI1rLw">
  <img src="https://img.youtube.com/vi/LHheKkI1rLw/maxresdefault.jpg" alt="Watch the demo" width="50%">
</a>

> **▶ [Watch the demo video](https://www.youtube.com/watch?v=LHheKkI1rLw)** — Build websites with structured AI workflows in under 5 minutes.

## What is QuickSite?

QuickSite started as a simple HTML template and evolved into a full CMS. The idea: manage an entire website — pages, translations, styles, assets, components — through a clean API, with all data stored as flat files you can version-control and deploy anywhere.

It now includes a **visual admin panel** with an iframe-based page editor, letting you build and edit sites directly in the browser without writing code. The API remains the backbone — the admin panel is a client of its own API.

QuickSite focuses on **frontend sites** — it manages HTML structure, CSS, translations, and assets. It doesn't handle backend logic or databases, though the built-in [interactions system](docs/README.md) can connect your pages to external APIs and services.

### Key features

- **Visual Admin Panel** — iframe-based page editor with drag-and-drop node management, live preview, and component library
- **116 API Commands** — RESTful endpoints covering pages, translations, styles, assets, builds, projects, backups, AI integration, and more
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
- **Web server**: Apache with `mod_rewrite` **or** nginx
- **PHP extensions**: json, fileinfo, zip

## Installation

> **Important:** QuickSite requires a **virtual host** (Apache vhost or nginx server block) pointing to the `public/` folder. It does not work as a subdirectory under `localhost/` without a vhost.

```bash
# Option 1: Clone into a new folder
git clone https://github.com/Sangiovanni/quicksite.git
cd quicksite

# Option 2: Clone directly into the current directory (e.g. your vhost parent)
cd /path/to/your/site
git clone https://github.com/Sangiovanni/quicksite.git .
```

After cloning, configure your web server's virtual host to point its document root to the `public/` folder, then run the setup wizard.

### Quick setup

Run the interactive setup wizard:

```bash
# Linux / macOS
chmod +x setup.sh
./setup.sh

# Windows
setup.bat
```

The wizard walks you through 3 optional steps:

1. **Rename the public folder** — match your vhost DocumentRoot (e.g. `www`, `public_html`, `www.example.com`)
2. **Rename the secure folder** — obscure the backend, optionally nest it (e.g. `backend`, `app`, `backends/project1`)
3. **Set a URL space** — serve from a subdirectory path after the domain (e.g. `mysite` → `http://domain.com/mysite/` — for deployment targets, not for replacing vhosts)

All three are optional — press Enter to skip any step. The scripts update `init.php` constants, `.htaccess` files, and nginx routing config automatically. Everything else (config files, nginx setup page) is handled on first page load.

The scripts are **re-runnable** — they save their state to `.quicksite.conf` and detect current folder names on restart, even after a partial run or crash.

> **Security tip (Linux):** After running setup.sh, remove execute and write permissions:  
> `chmod -x -w setup.sh`  
> This prevents accidental re-runs and unauthorized modifications. To reconfigure later, restore permissions first:  
> `chmod +x +w setup.sh`

> **Linux servers (recommended workflow):** Clone as `root`, then run `chmod +x setup.sh && ./setup.sh`. The script detects `root` and automatically fixes file ownership to your web server user (CloudPanel site user, `www-data`, `nginx`, or `apache`). If auto-detection fails, run manually:  
> `chown -R YOUR_WEB_USER:YOUR_WEB_USER /path/to/quicksite`  
> Replace `YOUR_WEB_USER` with your php-fpm user.

You can also pass the public folder name as an argument to skip the interactive prompt:

```bash
./setup.sh www.example.com
setup.bat www.example.com
```

**Don't want to use scripts?** Rename folders manually and edit `init.php` to match (`PUBLIC_FOLDER_NAME`, `SECURE_FOLDER_NAME`, `PUBLIC_FOLDER_SPACE`). On nginx, you'll see a first-load setup page with the exact `include` directive you need.

### Manual setup

<details>
<summary><strong>Apache with virtual host</strong> (recommended)</summary>

This is the standard setup. Point your document root to `public/`:

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

Add `127.0.0.1 quicksite.local` to your hosts file, restart Apache, and open `http://quicksite.local/admin/`.

No file changes needed — the repo defaults work out of the box with a virtual host.

</details>

<details>
<summary><strong>nginx</strong> (including CloudPanel, RunCloud, etc.)</summary>

nginx ignores `.htaccess` files. QuickSite handles this automatically:

- **On first page load**, QuickSite detects nginx and shows a setup page with the exact `include` directive you need to add to your nginx server block. Follow the instructions, reload nginx, and you're done.
- The routing config file (`secure/nginx/dynamic_routes.conf`) is auto-generated — you never edit it manually.

**Quick version** (for those who want to set it up before the first load):

1. **Set your server block's root** to the `public/` folder:
   ```nginx
   server {
       listen 80;
       server_name quicksite.example.com;
       root /path/to/quicksite/public;
       index index.php;

       # PHP processing
       location ~ \.php$ {
           fastcgi_pass unix:/run/php/php-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }

       # QuickSite routing (auto-generated)
       include /path/to/quicksite/secure/nginx/dynamic_routes.conf;
   }
   ```

2. **Generate the routing config** — visit any page in your browser. QuickSite detects nginx and auto-generates `secure/nginx/dynamic_routes.conf`, then shows a setup page with the exact `include` directive to add.

   Alternatively, generate it from the command line:
   ```bash
   php -r "require 'secure/src/functions/NginxConfig.php'; write_nginx_dynamic_routes('', realpath('secure'));"
   ```

3. **Test and reload**:
   ```bash
   sudo nginx -t && sudo nginx -s reload
   ```
   > **CloudPanel users:** You can also just open the vhost tab in CloudPanel and click Save — it triggers a reload automatically.

4. **(Advanced, optional) Enable auto-reload** — when the setup script changes the URL space, it can reload nginx automatically. This requires a sudoers entry:
   ```bash
   echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx' | sudo tee /etc/sudoers.d/quicksite-nginx
   sudo chmod 440 /etc/sudoers.d/quicksite-nginx
   ```
   Replace `www-data` with your PHP process user. Most users won't need this — reload manually or via your hosting panel.

**Renamed the public folder?** Run `setup.sh` — it handles everything. Or manually update `PUBLIC_FOLDER_NAME` in `init.php`.

For subdirectory installs on nginx (e.g., `example.com/mysite/`), set the URL space in `setup.sh` step 3 — the nginx config auto-adjusts.

</details>

<details>
<summary><strong>PHP built-in server</strong> (quick testing only)</summary>

```bash
cd public
php -S localhost:8000
```

Open `http://localhost:8000/admin/`. Clean URLs (`/about`, `/en/contact`) won't work — use Apache or nginx for full functionality.

</details>

### First load

On first load, QuickSite auto-creates sensitive config files from `.example` templates:
- `secure/management/config/auth.php` — API tokens
- `secure/management/config/roles.php` — role definitions
- `secure/management/config/target.php` — active project selector

> **⚠️ Token setup:** The default installation includes a placeholder token. On first login, the admin panel will prompt you to generate a new secure token. Follow the guided steps: generate a new token → log out → log back in with the new token → revoke the default placeholder. Do this before exposing the site publicly.

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
│   │   ├── command/              # 116 command handler files (one per command)
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
├── docs/                         # Documentation (coming soon)
├── tests/                        # Test suite
├── setup.sh                      # Interactive setup wizard (Linux/macOS/Git Bash)
├── setup.bat                     # Setup script (Windows)
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

The setup scripts (`setup.sh` / `setup.bat`) handle all folder customization:

| Step | What it does | Example |
|------|-------------|--------|
| **1. Public folder** | Renames `public/` to match your vhost DocumentRoot. Updates `init.php`. | `public_html`, `www`, `www.example.com` |
| **2. Secure folder** | Renames `secure/` for obscurity, supports nesting. Updates `init.php`. | `backend`, `app`, `backends/project1` |
| **3. URL space** | Moves files into a subdirectory, adjusts `.htaccess`, nginx config, and `init.php`. | `mysite` → `http://domain/mysite/` |

All steps support renaming, nesting, un-nesting, and are re-runnable. On nginx, changing the space regenerates `secure/nginx/dynamic_routes.conf` and attempts an automatic reload. On Apache, `.htaccess` changes take effect immediately.

## API overview

The API is self-documenting. Once installed, call:

```
GET /management/help
```

This returns full documentation for all 116 commands, including parameters, examples, validation rules, and error codes. For a specific command:

```
GET /management/help/addRoute
```

**Command categories**: pages, structure, translations, languages, assets, styles, CSS variables, animations, builds, projects, backups, export/import, tokens, roles, AI, snippets, JS functions, interactions, page events, API endpoints, system updates.

**Authentication**: All endpoints except `help` require a bearer token (`Authorization: Bearer <token>`). The `help` endpoint is publicly accessible — it serves as live API documentation. Tokens are scoped to roles with granular command-level permissions.

## Tutorials

Step-by-step tutorials for the admin panel, visual editor, and API workflows are planned.

## Troubleshooting

**Styles not updating after changes?**

Browsers and CDNs cache CSS aggressively. After changing styles (via API, visual editor, or AI workflows), you may need to hard-refresh:

| Browser | Shortcut |
|---------|----------|
| Chrome / Edge / Firefox | `Ctrl + Shift + R` (Windows/Linux) or `Cmd + Shift + R` (Mac) |
| Safari | `Cmd + Option + R` |

**Using Cloudflare or another CDN?**
- Enable **Development Mode** in Cloudflare dashboard (pauses caching for 3 hours)
- Or use **Purge Cache** → "Purge Everything" after deploying style changes
- Other CDNs: check their cache purge/invalidation settings

This affects the **deployed/built site only** — the admin panel preview and visual editor always load fresh styles.

## Vision

QuickSite is built on a **file-based, zero-database philosophy**. It targets a specific niche: sites that don't need a database — landing pages, portfolios, documentation sites, microsites — but still deserve proper tooling for content management, translations, and deployment.

The admin panel makes it accessible to non-developers, while the API keeps it powerful for automation and integration. Read more about the project's design principles in [PHILOSOPHY.md](PHILOSOPHY.md).

## Contributing

Contributions are welcome under the AGPL-3.0 License — and not just code.

**Ways to contribute:**
- **Bug reports & feature requests** — open an [issue](https://github.com/Sangiovanni/quicksite/issues). Every report helps.
- **Translations** — improve existing translations or add new languages.
- **Workflow specs & templates** — create or improve the structured specs that power AI workflows.
- **Documentation** — if something is unclear, help us make it better.
- **Code** — fork → feature branch → pull request.

All contributions go through review for security, quality, and consistency.

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
