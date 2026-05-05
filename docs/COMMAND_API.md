# QuickSite Management API

> Reference for the Management API — the single HTTP surface every QuickSite client (admin panel, CLI, external apps) talks to.
>
> _Last updated: 2026-04-23._

> _Maintainers note:_ re-check this doc when changing `secure/management/routes.php` (command count), `secure/src/classes/ApiResponse.php` (response shape), or `secure/src/classes/CommandRunner.php` (internal allowlist).

## Endpoint

All API calls go through one entry point:

```
http(s)://<your-domain>/management/<command>[/<urlParam>]
```

`public/management/index.php` authenticates the request, dispatches to a command handler in `secure/management/command/`, and returns a uniform JSON response.

## Self-documenting

The API documents itself. Once installed:

```
GET /management/help
```

returns full documentation for **all 118 commands** — parameters, examples, validation rules, and error codes. For a specific command:

```
GET /management/help/addRoute
```

The `help` endpoint is the only publicly accessible command and is the canonical source of truth for the API surface. This document is a high-level map; `help` is the contract.

## Authentication

- All endpoints except `help` require a bearer token:
  ```
  Authorization: Bearer <token>
  ```
- Tokens are defined in `secure/management/config/auth.php` (gitignored, auto-created from `.example`).
- Tokens are scoped to **roles** defined in `secure/management/config/roles.php`. Roles grant granular, command-level permissions.
- Default install ships with a placeholder token and prompts you on first admin login to generate a real one and revoke the placeholder before going public.

## Response shape

Every command — success or failure — returns the same JSON envelope (built by `secure/src/classes/ApiResponse.php`):

```json
{
    "status":  201,
    "code":    "route.created",
    "message": "Route created successfully",
    "data":    { "route": "contact" }
}
```

| Field | Type | Notes |
|---|---|---|
| `status` | integer | HTTP-style status code (200, 201, 400, 401, 403, 404, 409, 422, 500…). Mirrors the actual HTTP response code. |
| `code` | string | Stable dotted identifier (`route.created`, `validation.required`, `auth.forbidden`…). Suitable for client-side branching and i18n. |
| `message` | string | Human-readable summary. Localized when called from the admin panel. |
| `data` | object\|null | Command-specific payload on success. |
| `errors` | array (optional) | On validation failures, structured entries with `field` / `value` / `reason`. |

There is **no separate error envelope**. A failed call uses the same four fields with a non-2xx `status`, an error `code`, and `data: null`. This keeps clients simple: parse once, branch on `status`.

## Command catalogue

The 118 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

> **AI is browser-direct (BYOK).** As of v1.0.0-beta.6 there is no `callAi` / `testAiKey` / `detectProvider` / `listAiProviders` server command — the admin panel calls AI providers directly from the browser using credentials stored in `aiConnectionsV3` (localStorage). The Management API only handles workflow specs and command execution.

| Category | What it covers |
|---|---|
| **Pages** | Create / read / update / delete page JSON structures, page metadata, titles, favicons. |
| **Structure** | Edit nodes inside a page tree (`getStructure`, `editStructure`, `addNode`, `removeNode`, `moveNode`, etc.). |
| **Translations** | Add / edit / delete translation keys per language; sidecar files per page. |
| **Languages** | Add / remove site languages, set default and admin locales. |
| **Assets** | Upload / list / delete files in `public/assets/{images,font,audio,videos}/`, with metadata (alt text, dimensions). |
| **Styles** | Edit `style.css` blocks, scoped selectors, theme variables. |
| **CSS variables** | Manage CSS custom-property registries used by the visual editor's color picker and refiner. |
| **Animations** | Define and apply named animation presets to nodes. |
| **Builds** | Compile a project to a static `public/build/<name>/` deliverable; list / delete builds. |
| **Projects** | Create / list / switch / delete projects under `secure/projects/`. |
| **Backups** | Snapshot a project (configurable scope), list snapshots, restore. |
| **Export / Import** | Pack a project as a ZIP for portability; import a ZIP back. |
| **Tokens** | Generate / revoke / list bearer tokens, assign roles. |
| **Roles** | Define / edit / delete roles and their command permissions. |
| **Snippets** | Manage reusable component snippets (nav, cards, forms…) shared across pages. |
| **JS functions** | Register named JS functions invoked by Interactions v2 (`{{call:fn:args}}`). |
| **Interactions** | Bind triggers (click, hover, scroll…) to actions on a node. |
| **Page events** | Page-level lifecycle hooks (on load, on enter, etc.). |
| **API endpoints** | Manage external API integrations callable from page interactions. |
| **System updates** | Pull updates, run migrations, inspect engine version. |

## Calling the API

Any HTTP client works. Examples:

```bash
# List routes
curl -H "Authorization: Bearer $TOKEN" http://local.quicksite/management/getRoutes

# Add a route (POST + JSON body)
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name":"about","title":"About"}' \
     http://local.quicksite/management/addRoute

# Read the live spec for a command
curl http://local.quicksite/management/help/addRoute
```

## Internals

- Command handlers live in `secure/management/command/<command>.php`, one file per command.
- The whitelist of valid commands is `secure/management/routes.php` (118 entries — this file is the single source of truth for which commands exist).
- Shared helpers live in `secure/src/functions/utilsManagement.php` (e.g., `varExportNested()`, `SPECIAL_PAGES`, role helpers).
- Internal callers (visual editor data gathering, workflow steps) bypass the HTTP layer and invoke commands through `secure/src/classes/CommandRunner.php`. CommandRunner currently carries a **hardcoded read-only allowlist** of ~50 `get*` / `list*` commands it will execute internally; this is a tech-debt shortcut and should eventually be replaced by reusing the role/permission helpers in `secure/src/functions/AuthManagement.php` so there is one source of truth for "what is safe to call internally".
- Workflow execution adds its own role check via `WorkflowManager::setTokenInfo()` so steps respect the calling token's permissions.

## Update detection

Two commands manage in-place upgrades against the GitHub repo:

| Command | Method | Notes |
|---|---|---|
| `checkForUpdates` | GET | Reads the local `VERSION` file, fetches the latest GitHub release tag, compares with PHP's `version_compare`. Returns `update_available`, `current_version`, `latest_version`, `release_url`, `install_method` (`git`\|`zip`). |
| `applyUpdate` | POST (superadmin only) | Performs `git pull` (git installs) or downloads + extracts the release ZIP. |

`version_compare` natively orders pre-release tags correctly: `1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0`. **Always bump the `VERSION` file before tagging a release** — otherwise `checkForUpdates` will misreport the installed version.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — where the API sits in the three-layer model.
- [docs/WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — how multi-command workflows compose API calls.
- [docs/PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — on-disk layout.
