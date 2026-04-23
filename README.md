# QuickSite

A file-based PHP CMS with a built-in visual admin panel. Define page structures in JSON, manage everything through a REST API or the admin UI, and deploy production builds — no database required.

> **Current version: `1.0.0-beta.5`** — Actively developed. _Last updated: 2026-04-23._

<a href="https://www.youtube.com/watch?v=LHheKkI1rLw">
  <img src="https://img.youtube.com/vi/LHheKkI1rLw/maxresdefault.jpg" alt="Watch the demo" width="50%">
</a>

> **▶ [Watch the demo video](https://www.youtube.com/watch?v=LHheKkI1rLw)** — Build websites with structured AI workflows in under 5 minutes.

## What is QuickSite?

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes.

It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

QuickSite started as a simple HTML template and evolved into a full CMS. The idea: manage an entire website — pages, translations, styles, assets, components — through a clean API, with all data stored as flat files you can version-control and deploy anywhere.

It now includes a **visual admin panel** with an iframe-based page editor, letting you build and edit sites directly in the browser without writing code. The API remains the backbone — the admin panel is a client of its own API.

QuickSite focuses on **frontend sites** — it manages HTML structure, CSS, translations, and assets. It doesn't handle backend logic or databases, though the built-in [interactions system](docs/README.md) can connect your pages to external APIs and services.

### Architecture

For a deeper view of how QuickSite is organized:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — three-layer model (Project / Management / Admin), JSON-to-HTML pipeline, request lifecycle, multi-project model, security boundary.
- [docs/ADMIN_PANEL.md](docs/ADMIN_PANEL.md) — admin panel internals: boot flow, page modules, visual editor, preview subsystem.
- [docs/COMMAND_API.md](docs/COMMAND_API.md) — Management API surface and command catalogue.
- [docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md) — on-disk layout.
- [docs/WORKFLOW_SYSTEM.md](docs/WORKFLOW_SYSTEM.md) — workflow engine reference.

### Key features

- **Visual Admin Panel** — iframe-based page editor with drag-and-drop node management, live preview, and component library
- **122 API Commands** — RESTful endpoints covering pages, translations, styles, assets, builds, projects, backups, AI integration, and more
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

QuickSite has a strict public/private split:

- `public/` — web root. Front controller, admin UI, `management/` API gateway, assets.
- `secure/` — backend, outside the web root. API engine, admin backend, shared `src/`, isolated `projects/`, snippets, logs, exports.
- `docs/`, `tests/`, `setup.sh`/`setup.bat`, `VERSION`, `LICENSE`, `README.md` at the repo root.

Full tree, key concepts, and folder-customization details: **[docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md)**.

### Folder customization

The setup scripts (`setup.sh` / `setup.bat`) handle all folder customization:

| Step | What it does | Example |
|------|-------------|--------|
| **1. Public folder** | Renames `public/` to match your vhost DocumentRoot. Updates `init.php`. | `public_html`, `www`, `www.example.com` |
| **2. Secure folder** | Renames `secure/` for obscurity, supports nesting. Updates `init.php`. | `backend`, `app`, `backends/project1` |
| **3. URL space** | Moves files into a subdirectory, adjusts `.htaccess`, nginx config, and `init.php`. | `mysite` → `http://domain/mysite/` |

All steps support renaming, nesting, un-nesting, and are re-runnable. On nginx, changing the space regenerates `secure/nginx/dynamic_routes.conf` and attempts an automatic reload. On Apache, `.htaccess` changes take effect immediately.

## API overview

QuickSite exposes a single self-documenting Management API. Once installed:

```
GET /management/help              # full docs for all 122 commands
GET /management/help/addRoute     # docs for one command
```

All endpoints except `help` require a bearer token (`Authorization: Bearer <token>`), scoped to roles with granular command-level permissions.

Full reference — endpoint shape, response envelope, command catalogue, auth, internals: **[docs/COMMAND_API.md](docs/COMMAND_API.md)**.

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
