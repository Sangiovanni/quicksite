# QuickSite

A file-based PHP CMS with a built-in visual admin panel. Define page structures in JSON, manage everything through a REST API or the admin UI, and deploy production builds — no database required.

> **Current version: `1.0.0-beta.10.2`** — Actively developed.

<a href="https://www.youtube.com/watch?v=LHheKkI1rLw">
  <img src="https://img.youtube.com/vi/LHheKkI1rLw/maxresdefault.jpg" alt="Watch the demo" width="50%">
</a>

> **▶ [Watch the demo video](https://www.youtube.com/watch?v=LHheKkI1rLw)** — Build websites with structured AI workflows in under 5 minutes.

## What is QuickSite?

QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes.

It is exportable and production-friendly, and while file-native by default, it is designed to integrate quickly with external client-side and server-side APIs when backend capabilities are needed.

QuickSite started as a simple HTML template and evolved into a full CMS. The idea: manage an entire website — pages, translations, styles, assets, components — through a clean API, with all data stored as flat files you can version-control and deploy anywhere.

It now includes a **visual admin panel** with an iframe-based page editor, letting you build and edit sites directly in the browser without writing code. The API remains the backbone — the admin panel is a client of its own API.

QuickSite focuses on **frontend sites** — it manages HTML structure, CSS, translations, and assets. It doesn't handle backend logic or databases, though the built-in [interactions system](docs/ARCHITECTURE.md) can connect your pages to external APIs and services.

### Architecture

For a deeper view of how QuickSite is organized:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — three-layer model (Project / Management / Admin), JSON-to-HTML pipeline, translation system, request lifecycle, multi-project model, security boundary, interactions, style management, build and deploy.
- [docs/ADMIN_PANEL.md](docs/ADMIN_PANEL.md) — admin panel internals: boot flow, page modules, storage keys, visual editor, preview subsystem, `data-*` attribute reference.
- [docs/COMMAND_API.md](docs/COMMAND_API.md) — Management API surface and command catalogue.
- [docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md) — on-disk layout.
- [docs/WORKFLOW_SYSTEM.md](docs/WORKFLOW_SYSTEM.md) — workflow engine reference.
- [docs/DESIGN_DECISIONS.md](docs/DESIGN_DECISIONS.md) — locked design decisions: what was chosen, why, and what was rejected.

### Key features

- **Visual Admin Panel** — iframe-based page editor with drag-and-drop node management, live preview, and component library
- **A complete API** — one RESTful command per operation, covering pages, routes, translations, styles, assets, builds, projects, backups, membership, and more. `GET /management/help` documents the whole surface and reports how many commands this installation routes
- **JSON-Driven Templates** — page structures defined in JSON, compiled to optimized PHP for production
- **Multilingual** — built-in translation system with validation, health checks, and mono/multi-language modes
- **Multi-Project** — host multiple independent sites from one installation
- **Production Builds** — one-command compilation, optimization, and ZIP packaging
- **File-Based** — no database, no migrations. JSON + PHP files, deployable anywhere
- **Role-Based Access** — username + password login establishes a session; authority is **per project**, with six fixed roles (viewer, editor, designer, developer, admin, owner)
- **Update Notices** — the panel tells the operator when a newer version exists; updating itself is done on the server, with git
- **AI Integration (BYOK)** — the admin panel calls AI providers **directly from the browser** with your own API keys; no key ever reaches the server. Presets for OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, xAI and OpenRouter, any other OpenAI-compatible endpoint, and local runtimes such as Ollama and LM Studio

## Requirements

- **PHP** 8.0+ (tested on 8.0 and 8.4)
- **Web server**: Apache with `mod_rewrite`, **or** nginx
- **PHP extensions**: json, mbstring, fileinfo, zip, curl — all five ship
  enabled in a stock PHP build and in every mainstream distribution package.
  `mbstring` measures the password when an account is created or changed, so an
  install without it cannot get past the first-run form. `fileinfo` reads an
  upload's real type, `zip` packages builds, exports and backups, and `curl`
  makes every request the server sends on your behalf — data resolvers, the API
  endpoint test, OAuth back-channels, importing an asset by URL.

**Verified on:** Apache 2.4 (WAMP) and nginx as configured by [CloudPanel](https://www.cloudpanel.io/). Other nginx setups are expected to work — the routing is plain `try_files` and QuickSite generates it for you — but they are not what these instructions were tested against. What differs between nginx installs is the php-fpm upstream name, where `fastcgi_params` lives, and whether your control panel owns the server block; see `secure/deploy/nginx-vhosts.conf.example`.

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

If that directory is **not empty** — a control panel has already put a `public/`
folder, an `index.html` or a `.htaccess` there — `git clone .` refuses with
*"destination path '.' already exists and is not an empty directory"*. Fetch
into it instead; your existing files are kept:

```bash
cd /path/to/your/site
git init
git remote add origin https://github.com/Sangiovanni/quicksite.git
git fetch origin
git checkout -t origin/main
```

> **Reserved paths.** QuickSite claims `public/admin/`, `public/management/`,
> `public/p/` and `public/init.php`. On Apache it also claims `public/.htaccess`
> and one `.htaccess` inside each of those three folders. Anything of yours
> already at those paths will collide — move it first, and merge your own
> rewrite rules into the shipped `.htaccess` files rather than letting either
> side win.

After cloning, configure your web server's virtual host to point its document root to the `public/` folder, then run the setup wizard.

> ### ⚠ QuickSite lives at `/admin/`, not at `/`
>
> **`http://your-domain/admin/`** is the admin panel, and on a new installation
> it is the page that creates your first account. Everything below assumes you
> go there.
>
> **The domain root is deliberately empty.** QuickSite serves nothing at `/` so
> that your own hand-made site can live there — the engine never squats your
> domain. A web server with nothing to serve and no directory listing answers
> **403 Forbidden**, so on a bare install that is what `/` gives you. That is
> correct, not a broken install.
>
> Setup softens this: on its **first** run, if the root has no index file of its
> own, it drops in a small placeholder page pointing at `/admin/`. Delete or
> replace it whenever you like — nothing depends on it, and setup will not put
> it back. If the root already has an `index.php` or `index.html` (you are
> installing alongside an existing site), setup leaves it completely alone.

### Quick setup

Run the interactive setup wizard:

```bash
# Linux / macOS
chmod +x setup.sh
./setup.sh

# Windows
setup.bat
```

You get a **menu**, not a fixed sequence. Every item is independent and safe to
re-run, so changing one setting later never means answering the others again:

| | | |
|---|---|---|
| **1** | Rename the public folder | match your vhost DocumentRoot (e.g. `www`, `public_html`, `www.example.com`). Only needed when your host *forces* a document-root name — if you can edit the vhost, just point `root` at `public/` and skip this |
| **2** | Rename the secure folder | obscure the backend, optionally nest it (e.g. `backend`, `app`, `backends/project1`) |
| **3** | Change the URL space | serve from a subdirectory of the domain (e.g. `mysite` → `http://domain.com/mysite/`) |
| **4** | Switch environment | `production` (default) or `development` — see below |
| **5** | Self-registration on / off | may visitors create their own accounts at `/admin/register`? Default off |
| **6** | Show my setup token | reads the first-run credential off disk once it exists |
| **7** | Storage quotas | per-account ceilings on total size and upload rate. Leave both blank for no limits, which is the default |
| **8** | Self-deploy on / off | may this installation copy a built site onto a filesystem path? **Default off** — building and downloading still work either way |
| **9** | Command console on / off | should the panel offer the raw command runner at `/admin/command`? **Default on.** Turning it off removes reach and discoverability, not access — every command stays reachable at `/management/` to any caller whose role allows it, and the command history stays either way |

The menu header always shows the values currently on disk, so nothing is
guessed. Every item is optional — skip anything you do not need.

**Item 4, environment.** QuickSite fetches URLs from the server on your behalf:
data resolvers, API endpoint tests, OAuth back-channels, importing an asset by
URL. On `production` those fetches may not reach loopback, private or
cloud-metadata addresses. `development` lifts that block so a local author can
call `http://localhost:3000` or a LAN API; the http/https scheme rule and DNS
pinning stay on either way. **Only choose `development` on a machine you trust
and that untrusted callers cannot reach.** It is a server-side file
(`secure/management/config/environment.php`) and deliberately not an API
setting, so a leaked credential can never flip it.

**Item 6, the setup token.** It does not exist yet when you first run setup —
see *Create your first account* below for the order.

Items 1–3 update `init.php` constants and the `.htaccess` routing, and drop the
generated nginx routing config so the engine rebuilds it for the new layout on
the next page load. Item 4 writes `environment.php` and item 5 edits `auth.php`.
Items 7, 8 and 9 write a config file only when you ask for something other than
the default — accept the default and the file stays absent, which is already
what it means, so an install that never opted out never looks as though somebody
opted in. Item 6 only reads.

Outside the menu, the **first** run does one thing more: if the web root has no
index file, it copies `secure/deploy/index.html.example` to `public/index.html`
as a placeholder pointing at `/admin/` (see the callout above). Both conditions
are required — a root that already has a page keeps it, and a later run never
re-creates a placeholder you deleted.

The scripts are **re-runnable** — they save their state to `.quicksite.conf` and
detect current folder names on restart, even after a partial run or crash.

> **Security tip (Linux):** Once the install is set up and your account exists, remove execute and write permissions:  
> `chmod -x -w setup.sh`  
> This prevents accidental runs and unauthorized modifications. To reconfigure later, restore them first:  
> `chmod +x +w setup.sh`  
> Do this **after** you have read your setup token (menu item 6), not before.

> **Linux servers (recommended workflow):** Clone as `root`, then run `chmod +x setup.sh && ./setup.sh`. The script detects `root` and automatically fixes file ownership to your web server user (CloudPanel site user, `www-data`, `nginx`, or `apache`). If auto-detection fails, run manually:  
> `chown -R YOUR_WEB_USER:YOUR_WEB_USER /path/to/quicksite`  
> Replace `YOUR_WEB_USER` with your php-fpm user.

You can also pass the public folder name as an argument — it pre-answers item 1,
then the menu opens as usual:

```bash
./setup.sh www.example.com
```

**Don't want to use scripts?** Rename folders manually and edit `init.php` to match (`PUBLIC_FOLDER_NAME`, `SECURE_FOLDER_NAME`, `PUBLIC_FOLDER_SPACE`). On nginx, you'll see a first-load setup page with the exact `include` directive you need.

### Create your first account

QuickSite ships **no default account and no default password**. Open `http://your-domain/admin/` and you get a first-run page instead of a login form.

It asks for a **setup token**. The token is written on the server, and the page tells you which file to read it from:

```
secure/management/config/setup-token.txt
```

**The order matters**, because that file does not exist until the engine writes
it:

1. Run setup, point your vhost at the public folder, restart your web server.
2. **Open `http://your-domain/admin/`.** Rendering that page is what mints the
   token. Leave the page open — it is the form you are about to fill in.
3. Read the token: open the file above, or run `setup.sh` / `setup.bat` again
   and choose **item 6**, which prints it for you. (Before step 2 that item
   correctly tells you the token does not exist yet, rather than showing you
   nothing.)
4. Paste it into the form with a display name, a username and a password.

Being able to read a file inside `secure/` is what proves the installation is yours — so you need no command-line access and no password ever ships in the repository.

The token is destroyed as soon as it is used, and the page is gone for good once an account exists.

**What that first account gets.** It becomes the **owner of the starter
project** QuickSite ships with, so the panel has a real site to edit the moment
you sign in, and it is named in `secure/management/config/operator.php` — the
list of accounts that see operator notices such as "an update is available".
That file grants nothing; it only decides whether a notice renders. Edit it by
hand to add or remove people.

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

- **On first page load**, QuickSite detects nginx and shows a setup page with the two blocks you need to add to your server block, with your install's paths already filled in. Follow it, reload nginx, and you're done.
- The routing config file (`secure/nginx/dynamic_routes.conf`) is auto-generated — you never edit it manually.

> **CloudPanel: don't rename the public folder.** CloudPanel pre-creates
> `htdocs/<domain>/` as your document root, so setup's *rename the public folder*
> item would be renaming onto a folder that already exists — and it refuses,
> correctly, rather than merge into it. Point the vhost `root` at
> `…/htdocs/public` instead and skip that item entirely. The rename exists for
> hosts that force a document-root name; CloudPanel isn't one.

**Quick version** (for those who want to set it up before the first load):

1. **Set your server block's root** to the `public/` folder:
   ```nginx
   server {
       listen 80;
       server_name quicksite.example.com;
       root /path/to/quicksite/public;

       # QuickSite routing (auto-generated — never edited by hand)
       include /path/to/quicksite/secure/nginx/dynamic_routes.conf;

       # REQUIRED, and QuickSite cannot generate it: only your server knows its
       # PHP upstream. The generated include routes /p/ to this named location.
       location @quicksite_project {
           include fastcgi_params;
           # Hardcoded on purpose — nothing from the request goes into it, so a
           # URL like /photo.jpg/x.php cannot steer PHP at the wrong file.
           # With a URL space this becomes $document_root/<space>/p/index.php;
           # the setup page prints the exact line for your install.
           fastcgi_param SCRIPT_FILENAME $document_root/p/index.php;
           fastcgi_pass unix:/run/php/php-fpm.sock;
       }

       # PHP processing. try_files comes FIRST: without it a request like
       # /photo.jpg/x.php reaches PHP with a path that does not exist, and a
       # PHP built with cgi.fix_pathinfo=1 walks back up and executes the jpg.
       location ~ \.php$ {
           try_files $uri =404;
           fastcgi_pass unix:/run/php/php-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }
   }
   ```

   There is no `index index.php;` — QuickSite has no front controller at the web
   root, on purpose: the root stays free for your own site. The generated
   include carries the routing for all four of QuickSite's namespaces
   (`/admin/api/`, `/management/`, `/admin/`, `/p/`).

   > **⚠ Uploads: nginx allows a 1 MB request body by default**, which is
   > *smaller* than what a normal PHP configuration accepts — and it refuses the
   > excess with its own HTML error page **before PHP runs**, so QuickSite never
   > gets to explain. The generated include carries a
   > `client_max_body_size` line on the `/management/` block, computed from your
   > server's own `post_max_size`, so a single-server-block vhost needs nothing
   > extra. **Two server blocks?** Add the same line to the public one as well —
   > that is the block the client's bytes arrive at. The setup page prints the
   > exact value for your install.
   >
   > Apache needs nothing here: `LimitRequestBody` is unlimited by default.
   >
   > **Already have a `dynamic_routes.conf`?** It is generated when absent and
   > rewritten after every build deploy, but not in between — so an install that
   > has never deployed is still serving the file it was first given, however
   > long ago that was. Delete `secure/nginx/dynamic_routes.conf`, load any page
   > to regenerate it, and reload nginx.

   **Both blocks are required.** Project pages would still render without
   `@quicksite_project`, but every stylesheet, script and image would fail — and
   once the include is in place, project URLs answer `500` with
   `could not find named location` in the nginx error log.

   > **If your vhost already has a static-asset block** — panels usually add
   > something like `location ~* \.(css|js|png|…)$ { expires max; }` — you don't
   > need to change it, **as long as it is in the same server block as the
   > include**. A project's assets live outside the web root so the visibility
   > gate can run, and the generated `/p/` block uses `^~` precisely so that
   > regex can't claim them.

   > **⚠ Two server blocks? (CloudPanel, and panels like it)**
   >
   > Some panels generate *two* `server { }` blocks: a public one on 443 that
   > proxies to a backend one on 8080 — with the static-asset rule in the
   > **public** block and your QuickSite include in the **backend** one. A
   > project stylesheet is then answered from disk by the public block and never
   > proxied at all: **pages render, every asset 404s.** The `^~` in the
   > generated file cannot help, because the request dies a block earlier.
   >
   > Add this to the **public** block as well. Don't invent a target — copy the
   > `proxy_pass` line out of that block's own `location / { }`, whatever it
   > says. On CloudPanel it is a `{{varnish_proxy_pass}}` placeholder; copy that
   > verbatim so the panel keeps filling it in for you.
   >
   > ```nginx
   > client_max_body_size 51m;   # match your PHP post_max_size — see above
   >
   > location ^~ /p/ {
   >     COPY_THE_proxy_pass_LINE_FROM_YOUR_location_slash_BLOCK;
   >     proxy_set_header Host $host;
   > }
   > ```
   >
   > This sends project URLs down the path every other request already takes. It
   > opens nothing — `proxy_pass` dials *out*, it does not listen, and the
   > backend block is already listening either way. If that line points at
   > `127.0.0.1`, that is loopback: the machine talking to itself, never the
   > network.
   >
   > One server block only? Then there is nothing to do here.

2. **Generate the routing config** — visit any page in your browser. QuickSite detects nginx and auto-generates `secure/nginx/dynamic_routes.conf`, then shows a setup page with both blocks to add. On CloudPanel the usual place for the include is right after the existing `include /etc/nginx/global_settings;` line — that's one example, not a requirement; anywhere inside `server { }` works.

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

For subdirectory installs on nginx (e.g., `example.com/mysite/`), set the URL space with menu item 3 — setup drops the old routing config and the engine regenerates it for the new prefix on the next page load. Reload nginx after that page load, not before.

</details>

<details>
<summary><strong>PHP built-in server</strong> (quick testing only)</summary>

```bash
cd public
php -S localhost:8000
```

Open `http://localhost:8000/admin/`. Clean URLs (`/about`, `/en/contact`) won't work — use Apache or nginx for full functionality.

</details>

### Putting a project on its own domain

**A project goes to production as a build.** One install can hold any number of
projects, each edited and previewed at `/p/<projectId>/` on the install's own
hostname for as long as it exists. When a site is ready for the public you
**build** it (the `build` command, or **Builds** in the admin panel) and deploy
that build to its own domain, with its own web root and its own vhost.

A build is not a stripped-down copy. It is the project's pages precompiled to
PHP, plus the runtime that serves them: a self-contained QuickSite serving
exactly one site, with resolvers, param routes, server-side auth and
`serverFetch` all working as they did in development. What it removes is the
work of re-reading the page structure on every request, not what the site can do.

> **Why the install does not serve production directly.** Pointing a domain at
> the install and declaring which project it serves would put uncompiled pages on
> the public internet — every request re-parsing JSON that a build turns into PHP
> once — and it would break the visual editor on that hostname, because a domain
> serving one project has to refuse the `/p/<projectId>/` URLs the editor's
> preview iframe loads. Building is faster for visitors, keeps the install's
> other projects off the public domain entirely, and leaves the authoring
> hostname free to do the one thing it is for.
>
> `SetEnv QS_PROJECT` and `fastcgi_param QS_PROJECT` do nothing: if a vhost of
> yours carries one, it can be deleted. `QS_PUBLIC_BASE_URL` and
> `QS_TRUSTED_HOSTS` are unaffected — see below.

Two per-vhost variables remain on the authoring install, and neither picks a
project. `QS_PUBLIC_BASE_URL` declares the public base your `sitemap.txt` is
generated against — a sitemap has to name the URL the built site will live at,
which the authoring install cannot work out from the request in front of it.
`QS_TRUSTED_HOSTS` optionally pins the Host header. Complete vhosts for both
servers are in `secure/deploy/apache-vhosts.conf.example` and
`secure/deploy/nginx-vhosts.conf.example`.

### First load

On first load, QuickSite auto-creates its config files from `.example` templates:
- `secure/management/config/auth.php` — session TTLs, registration policy, CORS
- `secure/management/config/roles.php` — role definitions

It also mints the setup token, and on nginx it generates the routing config and
shows you the `include` line to add.

Three files are **not** created then, because none of them has a subject yet:

- `users.php` — the user registry, written when you create your first account, so no credential ever ships in the repository.
- `operator.php` — written at the same moment, naming that account. See *Create your first account*.
- The starter project's `config/members.json` — also written then, making that account its owner.

`environment.php` is never created automatically: with no file, QuickSite runs
as `production`, which is the safe answer. Setup item 4 writes it when you
choose, and you can edit or delete it by hand at any time.

## Keeping QuickSite up to date

Updating is done **on the server**, with git. It is not something the admin panel
can do, and that is deliberate: applying an update rewrites the engine every
project on the installation runs on, and QuickSite has no installation-wide role
that could authorise it. The credential is filesystem access to the machine —
whoever can update the code can already edit `users.php`, so they hold strictly
more than any role could grant. It is the same principle as the first-run setup
token.

```bash
cd /path/to/your/site
git pull
```

That is the whole procedure for a git install, which is how QuickSite is meant to
be deployed. If you installed with the non-empty-directory variant above, `git
pull` works there too — it is an ordinary clone with an extra file or two of your
own.

**What it never touches.** Your own files are not in the repository, so an update
has nothing to overwrite them with: everything under
`secure/management/config/` that is yours rather than shipped — `users.php`,
`auth.php`, `roles.php`, `environment.php`, `operator.php`, `quota.php`,
`console.php`, `deploy.php`, `deploy-roots.php`, `import-policy.php` — the
server-side secrets in `secure/admin/config/`, your logs, and every project
under `secure/projects/`. Git only knows tracked files, so it cannot reach any
of them. What it *does* replace is the `.example` template beside each of those,
which is the shipped documentation of the defaults and is meant to move.

**If you have local edits**, git will say so and stop rather than pulling over
them. Commit or stash, then pull again.

**Check what you are running** on `/admin/` — the version is shown in the panel,
and `VERSION` at the install root holds the same string.

**Finding out there is an update at all** is the panel's job: a procedure cannot
tell you something you have to remember to run. The panel shows "an update is
available" to the accounts listed in `secure/management/config/operator.php` —
see *Create your first account*. That list decides who sees the notice and
nothing else.

> **Not installed with git?** Download the current archive from GitHub and unpack
> it over the install, keeping the files listed under *What it never touches*
> above. Take a copy of `secure/` first. A git install avoids all of this, which
> is why it is the documented path.

## Project structure

QuickSite has a strict public/private split:

- `public/` — web root. The `p/` per-project front controller, the admin UI, and the `management/` API gateway. Nothing else — the root itself serves real files only, so it stays free for your own site. (Setup may leave a deletable `index.html` placeholder there on a fresh install; it is gitignored, like anything else you put at the root.)
- `secure/` — backend, outside the web root. API engine, admin backend, shared `src/`, isolated `projects/` (each holding its own public files, builds, exports and backups), snippets, logs.
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
GET /management/help              # full docs for every command, plus the live count
GET /management/help/addRoute     # docs for one command
```

Three commands are public — `help`, `login`, `register`. Every other endpoint requires the session `login` established: its cookie, plus the session token it returned sent as `Authorization: Bearer`. Both are needed, and the request is authorized against the caller's role **on the target project**.

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

**Added a `<source>` and the browser ignores it?**

`<source>` means two different things depending on where it sits, and the
editor's parameter form does not distinguish them. Inside `<picture>` a
source is selected by `srcset` plus `media` or `type`, and a plain `src` is
invalid and ignored. Inside `<video>` or `<audio>` it is `src` (with `type`)
that does the work. The editor asks for `src` in both cases, so a `<source>`
added inside a `<picture>` needs its `srcset` — and its `media` or `type` —
added by hand through the **Advanced → custom parameters** section, and the
`src` removed. Video and audio sources need nothing extra.

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
