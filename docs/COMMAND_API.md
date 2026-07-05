# QuickSite Management API

> Reference for the Management API — the single HTTP surface every QuickSite client (admin panel, CLI, external apps) talks to.

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

returns full documentation for **all 152 commands** — parameters, examples, validation rules, and error codes. For a specific command:

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

The 152 commands group into the categories below. Use `GET /management/help` for the full per-command spec.

> **AI is browser-direct (BYOK).** There is no `callAi` / `testAiKey` / `detectProvider` / `listAiProviders` server command — the admin panel calls AI providers directly from the browser using credentials stored in `aiConnectionsV3` (localStorage). The Management API only handles workflow specs and command execution.

Each row enumerates the commands in that category — comma-separated, alphabetical within the category — followed by what the category covers. Categories are derived from `secure/management/routes.php`; if a command isn't here, it isn't routed.

| Category | Commands & detail |
|---|---|
| **Meta** | `help` — single self-documenting endpoint; the only command callable without a bearer token. |
| **Pages** | `listPages`, `createAlias`, `deleteAlias`, `listAliases`, `editFavicon`, `editTitle` — page metadata, title, favicon, alias routes. |
| **Routes & sitemap** | `addRoute`, `deleteRoute`, `getRoutes`, `getSiteMap`, `setRouteLayout`, `analyzeReachability` — URL routing tree CRUD, sitemap export, dead-route audit. `setRouteResolver` is under "Server-side data resolvers" below since the route layer just hosts the resolver config. |
| **Structure** | `getStructure`, `editStructure`, `addNode`, `addComplexElement`, `addComponentToNode`, `editComponentToNode`, `editNode`, `moveNode`, `deleteNode`, `duplicateNode` — edit nodes inside a page tree. |
| **Components** | `listComponents`, `getComponent`, `findComponentUsages`, `renameComponent`, `duplicateComponent` — reusable component definitions (NOT the in-tree snippet shortcuts below). |
| **Translations** | `getTranslation`, `getTranslations`, `getTranslationKeys`, `setTranslationKeys`, `deleteTranslationKeys`, `cleanOrphanTranslations`, `validateTranslations`, `getUnusedTranslationKeys`, `analyzeTranslations`, `importStructureTranslations` — translation keys per language; audits + structure-aware bulk operations. |
| **Languages** | `getLangList`, `getLanguageList`, `addLang`, `deleteLang`, `setDefaultLang`, `setMultilingual`, `checkStructureMulti` — site language config (add/remove, default, multilingual gate). |
| **Assets** | `uploadAsset`, `listAssets`, `editAsset`, `deleteAsset` — upload, list, delete files in `public/assets/{images,font,audio,videos}/`, with metadata (alt text, dimensions). |
| **Styles** | `getStyles`, `editStyles`, `listStyleRules`, `getStyleRule`, `setStyleRule`, `deleteStyleRule` — `style.css` blocks + scoped CSS rule management. |
| **CSS variables** | `getRootVariables`, `setRootVariables`, `setThemeMode` — CSS custom-property registries used by the color picker and theme switcher. |
| **Animations** | `listKeyframes`, `getKeyframes`, `setKeyframes`, `deleteKeyframes`, `getAnimatedSelectors` — named keyframes + per-element animation bindings. |
| **Builds** | `build`, `listBuilds`, `getBuild`, `deleteBuild`, `cleanBuilds`, `deployBuild`, `downloadBuild` — compile a project to a static `public/build/<name>/` deliverable; list / deploy (to the install root, or a root listed in `secure/management/config/deploy-roots.php`) / clean. |
| **Projects** | `listProjects`, `getActiveProject`, `switchProject`, `createProject`, `cloneProject`, `deleteProject` — per-project CRUD + active-project switching under `secure/projects/`. |
| **Backups** | `backupProject`, `listBackups`, `restoreBackup`, `deleteBackup` — snapshot / restore (configurable scope). |
| **Export / Import** | `exportProject`, `importProject`, `downloadExport`, `clearExports` — pack a project as ZIP for portability; import a ZIP back. |
| **Tokens** | `generateToken`, `listTokens`, `revokeToken` — bearer token CRUD, with role assignment. |
| **Roles** | `listRoles`, `getMyPermissions`, `createRole`, `editRole`, `deleteRole` — role + per-command permission management. |
| **Snippets** | `listSnippets`, `getSnippet`, `createSnippet`, `deleteSnippet`, `duplicateSnippet`, `insertSnippet`, `injectSnippetCss` — reusable in-tree snippets (nav, cards, forms…); insert / inject into a page's structure. |
| **JS functions & data bindings** | `listJsFunctions`, `listDataBindings` — catalog read endpoints. `listJsFunctions` returns the QS.* verb catalog (consumed by the admin picker; see [ADMIN_PANEL.md §9.9](ADMIN_PANEL.md)). `listDataBindings` returns the `data-qs-*` attribute catalog. |
| **Interactions** | `listInteractions`, `addInteraction`, `editInteraction`, `deleteInteraction` — bind triggers (click, hover, scroll…) to verb chains on a node. |
| **Page events** | `getPageEvents`, `addPageEvent`, `editPageEvent`, `deletePageEvent` — page-level lifecycle hooks (`onload`, `onresize`, `onscroll`) per route. |
| **API endpoints** | `listApiEndpoints`, `getApiEndpoint`, `addApi`, `editApi`, `deleteApi`, `testApiEndpoint` — manage external API integrations callable from page interactions; live test endpoint with replay capture. |
| **Authentication** | `listOAuthProviders`, `addOAuthProvider`, `editOAuthProvider`, `deleteOAuthProvider` — OAuth provider preset CRUD. `listOAuthProviders` returns the union of admin + per-project presets (from `oauth-presets.json`) with a per-provider `setup` summary describing whether the `/auth/oauth/<provider>/start` + `/callback` routes already exist. Drives the `oauth-button` Complex Element wizard. The OAuth flow itself runs through route-resolvers (`oauth-start` / `oauth-callback` / `oauth-logout` kinds) attached via `setRouteResolver` — not standalone commands. See [ADMIN_PANEL.md §9.5 "Tier 4 — OAuth"](ADMIN_PANEL.md). |
| **Storage & consent** | `listStorageItems`, `addStorageItem`, `editStorageItem`, `deleteStorageItem`, `setStorageDescLang`, `scanStorageUsage`, `getConsentStatus`, `generateConsentLayer`, `generateCookiePolicy`, `deleteCookiePolicy` — the browser-storage registry (every `localStorage` / `sessionStorage` / `cookie` key the site uses, with a GDPR `category` + `retention`) and the cookie-consent layer generated from it. CRUD the registry (`*StorageItem`); descriptions are keyed in `translate/` and authored in one description language (`setStorageDescLang` moves them); `scanStorageUsage` reconciles declared keys against actual build usage (ok / undeclared / dangling-read / orphan); `generateConsentLayer` builds the banner + preferences popup (rendered globally like menu/footer) and enables runtime write-gating; `generateCookiePolicy` writes a deterministic cookie-policy page from the registry; `getConsentStatus` + `deleteCookiePolicy` drive the `/admin/storage` consent management UI. See [ADMIN_PANEL.md §9.10](ADMIN_PANEL.md). |
| **Privacy** | `getPrivacyStatus`, `setCollectedDatum`, `deleteCollectedDatum`, `setPrivacyDescLang`, `setPrivacyMapping`, `setPrivacyHost`, `setPrivacyCookieSection`, `generatePrivacyPolicy`, `deletePrivacyPolicy` — the data-**sharing** half of compliance (what the site sends to APIs + sign-in providers), a per-project registry (`data/privacy.json`) reconciled against a scan of the API registry. `getPrivacyStatus` returns the registry joined with the scan — outbound `(endpoint, field)` atoms from declared `parameters` + `requestSchema`, coverage (unmapped atoms / body endpoints with no schema / unclassified hosts), and OAuth/magic-link auto-seed. Author "data collected" entries (`setCollectedDatum` / `deleteCollectedDatum`, prose keyed in `translate/`; `setPrivacyDescLang` moves the language), map atoms to them (`setPrivacyMapping`), classify each API host as your server or a third party (`setPrivacyHost`), then `generatePrivacyPolicy` writes a deterministic page (collect table + per-third-party sharing + OAuth + cookie cross-link + disclaimer); `setPrivacyCookieSection` chooses whether the page links / hints / omits the cookie policy; `deletePrivacyPolicy` removes it. See [ADMIN_PANEL.md §9.11](ADMIN_PANEL.md). |
| **State stores** | `getStateStores`, `setStateStores` — per-page named client state bound to one API endpoint; fields with direction (request/response/both), init source, and response path. Gives interactions memory (pagination, search, filters, infinite scroll). |
| **Server-side data resolvers** | `setRouteResolver`, `cleanResolverCache` — per-route declaration that fires a server-side fetch BEFORE template render and exposes the response as template variables (SEO/AEO/first-paint payoff). `setRouteResolver` is idempotent six-shape (set / clear / patch / append / remove single slot). File-based cache with TTL + auth-cacheable gating; manual invalidation via `cleanResolverCache`. Read via `getSiteMap` (per-route subset under `routeResolvers`). See [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md). |
| **System updates** | `checkForUpdates`, `applyUpdate` — pull engine updates, run migrations, inspect engine version. |
| **System** | `getCommandHistory`, `clearCommandHistory`, `getSizeInfo`, `getIframeSandbox`, `setIframeSandbox`, `removeIframeSandbox` — engine-level state (audit log of executed commands, project size info, iframe sandbox config for the visual editor). |
| **Workflow tooling** | `listWorkflowBlocks`, `lintWorkflows` — enumerate reusable prompt blocks in `secure/admin/workflows/{blocks,pins,warnings,examples}/` for the editor's multi-select dropdowns; report paragraphs that occur in 3+ workflow templates as candidates for extraction. Both are admin-tier. |

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
- The whitelist of valid commands is `secure/management/routes.php` (152 entries — this file is the single source of truth for which commands exist).
- Shared helpers live in `secure/src/functions/utilsManagement.php` (e.g., `varExportNested()`, `SPECIAL_PAGES`, role helpers).
- Internal callers (visual editor data gathering, workflow steps) bypass the HTTP layer and invoke commands through `secure/src/classes/CommandRunner.php`. CommandRunner currently carries a **hardcoded read-only allowlist** of ~50 `get*` / `list*` commands it will execute internally.
- Workflow execution adds its own role check via `WorkflowManager::setTokenInfo()` so steps respect the calling token's permissions.

## Update detection

Two commands manage in-place upgrades against the GitHub repo:

| Command | Method | Notes |
|---|---|---|
| `checkForUpdates` | GET | Reads the local `VERSION` file, fetches the latest GitHub release tag, compares with PHP's `version_compare`. Returns `update_available`, `current_version`, `latest_version`, `release_url`, `install_method` (`git`\|`zip`). |
| `applyUpdate` | POST (superadmin only) | Performs `git pull` (git installs) or downloads + extracts the release ZIP. |

`version_compare` natively orders pre-release tags correctly: `1.0.0-beta.5 < 1.0.0-beta.10 < 1.0.0-rc.1 < 1.0.0`. The installed version is read from the local `VERSION` file, so that file's contents are what `checkForUpdates` compares against the latest GitHub release tag.

## See also

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — where the API sits in the three-layer model.
- [docs/WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — how multi-command workflows compose API calls.
- [docs/PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — on-disk layout.
