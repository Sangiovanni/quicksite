# QuickSite Management API

> Reference for the Management API — the single HTTP surface every QuickSite client (admin panel, CLI, external apps) talks to.
>
> _Last updated: 2026-06-09._

> _Maintainers note:_ re-check this doc when changing `secure/management/routes.php` (command count), `secure/src/classes/ApiResponse.php` (response shape), `secure/src/classes/CommandRunner.php` (internal allowlist), `secure/management/command/setRouteResolver.php` (resolver body shapes — scalar / array / index / collision errors), `secure/management/command/cleanResolverCache.php` (cache invalidation surface), or `secure/management/command/addRoute.php` / `deleteRoute.php` / `editRoute.php` (route body shape — param syntax + warnings array).

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

returns full documentation for **all 130 commands** — parameters, examples, validation rules, and error codes. For a specific command:

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

The 130 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

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
| **Page events** | Page-level lifecycle hooks (`onload`, `onresize`, `onscroll`). Add / edit / delete page-event interactions per route (`addPageEvent`, `editPageEvent`, `deletePageEvent`, `getPageEvents`). |
| **API endpoints** | Manage external API integrations callable from page interactions. |
| **Authentication** | `listOAuthProviders` returns the union of admin + per-project OAuth presets (from `oauth-presets.json`) with a per-provider `setup` summary describing whether the `/auth/oauth/<provider>/start` + `/callback` routes already exist. Drives the `oauth-button` Complex Element wizard. The OAuth flow itself runs through route-resolvers (`oauth-start` / `oauth-callback` / `oauth-logout` kinds) attached via `setRouteResolver` — not standalone commands. See [ADMIN_PANEL.md §9.5 "Tier 4 — OAuth"](ADMIN_PANEL.md). |
| **State stores** | Per-page named client state bound to one API endpoint — fields with direction (request/response/both), init source, and response path. Gives interactions memory (pagination, search, filters, infinite scroll). Read/write via `getStateStores` / `setStateStores`. |
| **Server-side data resolvers** | Per-route declaration that fires a server-side fetch BEFORE template render and exposes the response as template variables — SEO/AEO/first-paint payoff (initial HTML carries API content). Single resolver per route OR an array of resolvers firing in parallel (`curl_multi_*`) for multi-endpoint pages. Same JSON-style declaration as state stores (one shape, two executors). Read via `getSiteMap` (per-route subset under `routeResolvers`); write via `setRouteResolver` (the idempotent six-shape command — set / clear / patch / append / remove single slot). File-based cache with TTL + auth-cacheable gating; manual invalidation via `cleanResolverCache`. See [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md). |
| **System updates** | Pull updates, run migrations, inspect engine version. |
| **Workflow tooling** | `listWorkflowBlocks` enumerates the reusable prompt blocks in `secure/admin/workflows/{blocks,pins,warnings,examples}/` for the editor's multi-select dropdowns; `lintWorkflows` reports paragraphs that occur in 3+ workflow templates as candidates for extraction. Both are admin-tier (will move to superadmin once that role lands — beta.7 sub-task). |

## Calling the API

Any HTTP client works. Examples:

```bash
# List routes
curl -H "Authorization: Bearer $TOKEN" http://local.quicksite/management/getRoutes

# Add an exact route (POST + JSON body)
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name":"about","title":"About"}' \
     http://local.quicksite/management/addRoute

# Add a parameterised route — the ':slug' segment captures any URL value
# at request time (one template serves many URLs). See ARCHITECTURE §5.3
# for the matching algorithm + how params flow to PHP / qs.js.
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"name":"products/:slug","title":"Product"}' \
     http://local.quicksite/management/addRoute
# Response data may carry a 'warnings' array when the new route shadows
# exact siblings (curated landing + param catch-all is supported and not
# blocked — the warning surfaces intent so the user can confirm).

# Read the live spec for a command
curl http://local.quicksite/management/help/addRoute

# Attach a server-side data resolver to a route (single — scalar shape).
# Body shape: {route, resolver}. The resolver fires once per request,
# server-side, before the page template renders.
curl -X POST \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "route": "products/:slug",
       "resolver": {
         "endpoint": "@products-api/get-product",
         "inputs":   { "id": "param:slug" },
         "expose":   { "product": "data.product" },
         "cacheTTL": 300
       }
     }' \
     http://local.quicksite/management/setRouteResolver

# Same command, multi-resolver shape (array). Resolvers fire concurrently
# via curl_multi_*. Save is REJECTED with reason 'collision' if any two
# resolvers expose the same flat-namespace key — disambiguate by renaming
# OR by using the namespaced address ($r0.title / $r1.title in templates,
# window.QS_RESOLVED_BY_INDEX.r0.title / .r1.title in JS).
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{
       "route": "compare/:a/vs/:b",
       "resolver": [
         {"endpoint":"@products-api/get-product","inputs":{"id":"param:a"},"expose":{"productA":"data.product"}},
         {"endpoint":"@products-api/get-product","inputs":{"id":"param:b"},"expose":{"productB":"data.product"}}
       ]
     }' \
     http://local.quicksite/management/setRouteResolver

# Patch one resolver slot in a multi-resolver route (the `index` param
# targets a specific slot). Same command supports append (index === length),
# remove (no `resolver` body + `index`), and clear-all (no `resolver` + no
# `index`) — see help/setRouteResolver for the full body-shape matrix.
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"route":"compare/:a/vs/:b","resolver":{"endpoint":"@products-api/get-product","inputs":{"id":"param:a"},"expose":{"productA":"data.product"}},"index":0}' \
     http://local.quicksite/management/setRouteResolver
```

## Internals

- Command handlers live in `secure/management/command/<command>.php`, one file per command.
- The whitelist of valid commands is `secure/management/routes.php` (129 entries — this file is the single source of truth for which commands exist).
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
