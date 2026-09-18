# Maintaining QuickSite

Procedures for changing the engine itself — each one dormant most of the time,
and exact when it is not. They live here rather than in `CLAUDE.md` so that the
rules governing *every* change stay short enough to actually be read.

> **This is not the contributor guide.** How to report a bug, translate, or open
> a pull request is in `README.md` under *Contributing*. This file is for someone
> already inside the codebase performing one of the procedures below.

Read `CLAUDE.md` first — it holds the standards that bind every change
(dependency-free, centralize shared logic, HTML-in-JS hygiene, the data-shape
rule, and the `docs/` content-hygiene rules).

> **Why these checklists are so specific.** Every layer named below was found the
> hard way: a command that was routable and completely unusable because it had no
> category; a deleted command that survived in an allowlist nobody remembered; a
> registration layer in the admin API that mirrors nothing and had to be edited by
> hand. The lists are long because the failure modes are silent.

## Contents

| Procedure | Consult it when |
|---|---|
| [Documentation maintenance — the trigger table](#documentation-maintenance--the-trigger-table) | **Every change.** You edited a file and need to know which `docs/` file that obliges you to re-check. |
| [Adding a new command](#adding-a-new-command--checklist) | You are adding a `secure/management/command/<name>.php`. |
| [Removing a command](#removing-a-command--the-checklist-is-eight-layers-and-it-is-longer-than-adding) | You are deleting one. It is a longer list than adding. |
| [Release discipline](#release-discipline) | You are about to cut a git tag. |
| [How to write and review a `docs/` file](#how-to-write-and-review-a-docs-file) | You are restructuring or reviewing one of the six canonical docs. |

---
## Documentation maintenance — the trigger table

The six canonical docs in `docs/` (`ARCHITECTURE.md`, `ADMIN_PANEL.md`,
`COMMAND_API.md`, `WORKFLOW_SYSTEM.md`, `PROJECT_STRUCTURE.md`,
`DESIGN_DECISIONS.md`) drift fast. When editing the source files listed below,
re-check the corresponding doc.

| When you change… | Re-check |
|---|---|
| `secure/management/routes.php` (command count) | `ARCHITECTURE.md` §3, `COMMAND_API.md` (catalogue + count) |
| `secure/management/config/roles.php` (roles or permissions) | `ARCHITECTURE.md` §3 (roles table) |
| `secure/admin/functions/AdminHelper.php` (`getCommandCategories()`) | None — but **REQUIRED** for any new command that should appear on `/admin/command`. See *Adding a new command* checklist step 6. |
| `public/admin/assets/js/core/storage-keys.js` | `ADMIN_PANEL.md` §6 (storage key registry) |
| `secure/admin/templates/pages/preview/sidebar-tools.php` | `ADMIN_PANEL.md` §8.1 (visual editor modes) |
| `secure/src/classes/JsonToHtmlRenderer.php` (node kinds, tag blacklist, QS.* registry) | `ARCHITECTURE.md` §2, §8, §9 |
| `secure/src/classes/ApiResponse.php` (envelope shape) | `COMMAND_API.md` (response shape) |
| `secure/src/classes/CommandRunner.php` (allowlist) | `COMMAND_API.md` (internals) |
| `secure/src/functions/filePolicy.php` (archive limits — `max_entries` / `max_total_bytes` / `max_entry_bytes` / `max_ratio`; import + publish extension allowlists; override merge rules) **and** `secure/management/config/import-policy.php.example` — the two must ALWAYS change together, the `.example` is the shipped documentation of the defaults | `COMMAND_API.md` (*Archive import limits* — the table of defaults + the override section) |
| `secure/src/classes/IframeSandbox.php` (the install-wide iframe embed policy: file shape, host matching, the `VALID_PERMISSIONS` ceiling, the same-origin / own-host / `srcdoc` guard rails), the **two render paths that apply it** — `secure/src/classes/JsonToHtmlRenderer.php` and `secure/src/classes/JsonToPhpCompiler.php`, which call one entry point and must keep doing so, or the same iframe is sandboxed differently in preview and in a built site — **and** `secure/management/config/embed-policy.json.example`: the class reads the live `embed-policy.json`, the `.example` is its shipped documentation and the source both setup scripts copy, and a build bundles the policy at `<secure>/data/embed-policy.json`, refusing to build when it does not parse (`build.php`) | `ARCHITECTURE.md` §8.1 (CSP `frame-src`), `COMMAND_API.md` (`getIframeSandbox`), `ADMIN_PANEL.md` (Embed security page) |
| `secure/src/functions/nodeParamPolicy.php` (the write-side attribute gate: `firstUnsafeParam` and the whole-structure verifier), **any command or helper that writes a structure file** — each one calls `qs_first_unsafe_structure_param()` on exactly what it is about to write (`qs_first_unsafe_param_in_tree()` before copying a whole tree) and refuses with `qs_unsafe_structure_param_response()`; there is no shared writer, so a new writer that skips the call is one the gate does not reach — **and** `secure/src/classes/RegexPatterns.php` `html_attribute_name`, the name charset the renderer, the compiler and the write gate share (its `D` modifier is load-bearing) | `ARCHITECTURE.md` §8 (the *XSS in JSON content* and *Inline JS injection* rows state the name rule and which writers enforce the policies) |
| `secure/src/classes/TrimParameters.php` / `secure/src/functions/routeHelpers.php` / a project's `secure/management/routes.php` schema (param routes) | `ARCHITECTURE.md` §6.3 (routing — matching algorithm, param syntax, NTFS `:` ↔ `__` sanitisation) |
| `secure/management/command/addRoute.php` / `deleteRoute.php` (route body shape, conflict warnings) | `ADMIN_PANEL.md` §9.8 (sitemap UX + add-form validation), `COMMAND_API.md` (param-route example) |
| `public/admin/assets/js/pages/sitemap.js` (tree rendering, add form, badges, resolver list view + per-config modal) | `ADMIN_PANEL.md` §9.7 (resolvers) + §9.8 (sitemap) |
| `secure/src/functions/resolverHelpers.php` (sidecar shape — scalar vs array; validation; flat-namespace collision rule) | `ADMIN_PANEL.md` §9.7 (resolver authoring), `ARCHITECTURE.md` §9.4 (resolver subsection) |
| `secure/src/classes/DataResolver.php` (`resolve` / `resolveMany`; per-resolver `onMiss`; flat + namespaced exposure) | `ADMIN_PANEL.md` §9.7 (failure-mode table, namespaced access), `ARCHITECTURE.md` §9.4 |
| `secure/src/functions/serverFetch.php` (single + multi; `_serverFetchPrepare`; auth handling; cache eligibility) | `ADMIN_PANEL.md` §9.7 (auth-cacheable rule, cache observability header), `ARCHITECTURE.md` §9.4 |
| `secure/management/command/setRouteResolver.php` (body shapes + `index` param + collision errors) | `COMMAND_API.md` (catalogue + example), `ADMIN_PANEL.md` §9.7 |
| `secure/management/command/cleanResolverCache.php` | `COMMAND_API.md` (catalogue) |
| `secure/admin/templates/pages/sitemap-resolver.php` / `sitemap-resolver-list.php` (modal markup) | `ADMIN_PANEL.md` §9.7 (admin authoring section) |
| `secure/src/functions/runtimeHandoff.php` (hydration emit — it writes `window.QS_RESOLVED` + `window.QS_RESOLVED_BY_INDEX`; `PageManagement.php` only calls `qs_runtime_handoff()`) | `ADMIN_PANEL.md` §9.7 (hydration handoff), `ARCHITECTURE.md` §9.4 |
| `public/admin/assets/js/pages/preview/preview-js-interactions.js` (state-store wizard init source kinds) | `ADMIN_PANEL.md` §9.6 (init source kind picker) |
| `secure/src/runtime/qs.js` auth verbs (saveToken / clearToken / refresh / exchangeMagicLink / requestMagicLink / logoutServer) + `applyAuthState` modes | `ADMIN_PANEL.md` §9.5 (auth flows Tier 1+2+3), `ARCHITECTURE.md` §9 (verb count + auth/storage row) |
| `secure/src/classes/CallTransformer.php` `CHAIN_AWAITABLE` list | `ARCHITECTURE.md` §9.0.1 (chain execution rules), `ADMIN_PANEL.md` §9.5 (gotcha at end of Tier 3) |
| `secure/src/functions/surfaceB.php` (surface-B `/p/<id>/` serving — `/p/` detection, visibility/membership gate, L11 static-passthrough jail, render setup) | `ARCHITECTURE.md` §6.1, §7, §8 (per-project serving row), `ADMIN_PANEL.md` §8.0 |
| `secure/src/functions/projectPublicArtifacts.php` (per-project generated-artifact targets + served-base mirror + on-serve regen) / the `public/index.php` `/p/` hook / the `public/management/index.php` per-project `PUBLIC_CONTENT_PATH` override | `ARCHITECTURE.md` §6.1, §7 |
| `secure/admin/AdminRouter.php` `getCurrentProject` (edited project) / `getServedProject` (main) + `secure/admin/templates/layout.php` (project picker) + `secure/admin/templates/pages/preview.php` (iframe target) | `ADMIN_PANEL.md` §7 (`currentProject`), §8.0 (per-user editing) |
| `secure/src/functions/SessionManagement.php` (session boot/establish/destroy/touch, cookie attributes, save path) or `AuthManagement.php` `qs_session_auth` / `validateBearerToken` / the generation counter | `ARCHITECTURE.md` §3 + §7 (auth rows), `COMMAND_API.md` (*Authentication*), `ADMIN_PANEL.md` §6 |
| `secure/admin/functions/panelState.php` / `public/admin/state/index.php` (per-user edited-project pointer — panel state, not a command) | `ADMIN_PANEL.md` §8.0, `ARCHITECTURE.md` §7 |
| `secure/admin/functions/updateCheck.php` / the `update-check` arm in `public/admin/api/index.php` | `COMMAND_API.md` (*Update detection is not part of this API*), `ADMIN_PANEL.md` §9.14 |
| `secure/admin/functions/adminJsonEndpoint.php` (the shared admin JSON auth gate) | `ARCHITECTURE.md` §8 (entry-point count in the error-hygiene row) |
| `secure/src/functions/LoggingManagement.php` (what is logged, the redaction list, which bucket a command lands in) **or** `secure/src/functions/securityLog.php` (the install-wide security trail) | `COMMAND_API.md` (*Command history storage* + *The security trail*) **and** `ADMIN_PANEL.md` §9.18 (what a history row represents — refusals are recorded, not only executed commands) |
| `secure/admin/functions/accountSelf.php` / `membershipSelf.php` / `directory.php` / `public/admin/self/index.php` (account + membership self-service and the two directory lookups — not commands) | `COMMAND_API.md` (*What is deliberately not a command* — the route table AND the guarantees paragraph), `ARCHITECTURE.md` §3 (global-scope list, consent-model prose), `ADMIN_PANEL.md` §9.13 (My account) + §9.12 (Memberships & Project members) |
| **Adding ANY new directory under `public/admin/`** (a JSON endpoint, an asset folder — anything) | Check **both** directions. **Shadowing** — Apache resolves a real directory before `public/admin/.htaccess`'s `FallbackResource`, so a directory named like an `AdminRouter::$validPages` entry silently shadows that page. This has shipped for real: an endpoint named `account` while `/admin/account` was the My Account page. It is also why the asset page is routed `media`, not `assets`. **Routing** — a directory holding its own `index.php` is a front controller. Apache picks up each directory's own `.htaccess` automatically; nginx has a central list instead, so give it a location block in `generate_nginx_config()` (`secure/src/functions/NginxConfig.php`) rather than relying on `location /admin/` falling through. `/admin/api/`, `/admin/self/` and `/admin/state/` each have one. |
| Implementing a design decision that was locked earlier (sprint design round, scope reduction, alternative chosen, etc.) | `DESIGN_DECISIONS.md` — append a new entry **once the code lands**. The full rule, including what an entry must contain and what must never go in one, is *Design-decision discipline* in `CLAUDE.md`. |

---
## Adding a new command — checklist

Adding a `secure/management/command/<name>.php` is the first of **six** steps: the file, then **five** registrations. Until every registration is done the command is missing at a different layer — router, category map, role permissions, `help` documentation, and the `/admin/command` UI listing.

1. **Create** `secure/management/command/<name>.php`. **Two file styles are legitimate — pick by whether the command must be callable in-process:**
   - **Top-level script** (50 of the 172 command files). The file simply executes on include: it reads its parameters, does the work, and calls `->send()`. The dispatcher includes exactly one command file per request, so this is safe and is the simpler default.
   - **`__command_<name>()` function + HTTP-dispatch guard** (122 of 172). The file defines `function __command_<name>(array $params = [], array $urlParams = []): ApiResponse` and ends with `if (!defined('COMMAND_INTERNAL_CALL')) { … __command_<name>(…)->send(); }`. The guard lets another command `require_once` this file and call the function directly without the include also firing an HTTP response.
   - **The function+guard style is REQUIRED** when the command is (or may become) an entry in `CommandRunner`'s allowlist, or when another command file calls it directly. Every one of `CommandRunner`'s 25 allowlisted commands defines its function — that invariant is what makes in-process execution work, so a new allowlist entry must be converted first. When in doubt, use the function+guard style: it costs three lines and keeps the option open.
2. **Append** `'<name>'` to the array in `secure/management/routes.php` (the single-line returns array; alphabetisation isn't enforced, place it near its conceptual neighbours). Makes the command **routable**.
3. **Add to `secure/management/config/categories.php`** — every routed command must belong to **exactly one** category, either an existing one or a new `'<category>' => ['scope' => …, 'access' => …, 'commands' => [...]]` block. The category fixes the command's **scope** (global vs project-marker) as well as its permission group. **This is not optional**: `routes.php` ↔ `categories.php` must stay 1:1 in *both* directions, and a routed-but-uncategorised command fails closed at `hasPermission` — it is routable and completely unusable. Note the shape: `category => ['scope','commands']`, **not** a flat command→category map.
4. **Add to roles** in `secure/management/config/roles.php` AND `roles.php.example` — at minimum the `editor` / `designer` / `developer` / `admin` tiers as appropriate. Use a **string key** (`'<name>' => '<name>'`) to avoid the duplicate-int-key clobber bug that pre-existed in this file (now patched, but the safer pattern stays). Makes the command **permitted** for each role. **Skip this step only for a category that is `scope: global` + `access: 'any'`** — those are open to every authenticated user and are never role-listed; adding one here is inventing an edit.
5. **`help.php` `$GLOBALS['__help_commands']`** — append an entry describing parameters, success/error shapes, notes. Makes the command **documented** (consumed by the runtime `help` endpoint AND by callers that want spec info). Don't smoke-test routing via `help` — POST to the endpoint directly. **Name constants, never their values** — see *`help` states no deployment-specific value* in `CLAUDE.md`. ⚠ **If the command destroys or overwrites data and its NAME does not begin with `delete` / `remove` / `clear` / `reset` / `purge`, set `'destructive' => true` in its help entry** — `/admin/command` reads that flag (via `data-destructive` on the form) and falls back to the name convention, so without it the console executes the command with no confirmation. The failure is silent: a missing flag is not an error, it is a missing "are you sure".
6. **`secure/admin/functions/AdminHelper.php` → `getCommandCategories()`** — add the command name to the appropriate category's `'commands'` array. Makes the command **visible on `/admin/command`** (the categorisation that drives the UI list is hand-curated here, not derived from routes.php). Easy to miss; without it the command exists, is routable, has permissions, has docs — but doesn't show up in the admin UI list.
7. **OPcache caveat** — after editing `routes.php`, the dispatcher won't see the new command until OPcache refreshes. Restart WAMP or `opcache_reset()` during dev. `AdminHelper.php` and `roles.php` are also OPcache-cached but get hot-reloaded on most admin page navigations; `routes.php` is the strictest.
8. **"Command not found" can mean four things** — when debugging, rule out in this order:
   - Hand-built URL with a double slash. `managementUrl` ends with `/`; `managementUrl + '/foo'` produces `/management//foo` and the dispatcher reads an empty command. **Always use `QuickSiteAdmin.apiRequest(cmd, method, body, urlParams?)`** instead of hand-building.
   - OPcache holding old `routes.php`. Restart WAMP / reset OPcache.
   - Missing entry in `routes.php`. Confirm with grep.
   - Missing entry in `categories.php` — an uncategorised command fails closed at `hasPermission`, which surfaces as a permission error, not a routing one.
   - Missing role permission — check `secure/management/config/roles.php` for the current user's tier.
9. **"Command exists but doesn't appear on /admin/command"** — almost always step 6 (missing from `AdminHelper.php → getCommandCategories()`). Confirm with grep + hard-refresh the page.
10. **Doc trigger** — see the *Documentation maintenance* table above: routes.php change ⇒ re-check `ARCHITECTURE.md §3` + `COMMAND_API.md` (command count).

### REMOVING a command — the checklist is EIGHT layers, and it is longer than adding

Adding needs the registrations above. **Removing** needs every place that can hold a
command NAME, and two of them are outside the add-checklist entirely — both were missed
until a beta.10 deletion (`switchProject` + `getActiveProject`) tripped over them:

1. `secure/management/routes.php` — the routable allowlist.
2. `secure/management/config/categories.php` — the command→category map. **The 1:1
   invariant must hold in BOTH directions**: no routed-but-uncategorised command
   (fails closed at `hasPermission`) and no categorised-but-unrouted stray.
3. `secure/management/config/roles.php` **and** `roles.php.example` — if the command's
   category is role-granted. (Global `access:'any'` categories are never role-listed.)
4. `secure/management/command/help.php` — the `$GLOBALS['__help_commands']` entry.
5. `secure/admin/functions/AdminHelper.php` → `getCommandCategories()` — the
   `/admin/command` UI list.
6. **`secure/src/classes/CommandRunner.php`** — its hardcoded read-only allowlist for
   in-process execution. It is NOT derived from routes.php, so a deleted command can
   linger here as a dead entry. This is where `listTokens` survived one beta, and
   `getActiveProject` survived into the next.
7. **`public/admin/assets/js/core/api.js`** — `FALLBACK_GLOBAL_COMMANDS`, the client's
   defensive scope list used when the page fails to emit the live set.
8. **`public/admin/api/index.php`** — `QS_API_ARM_COMMANDS` (the arm-name →
   command permission map) **and** the `switch` case that calls
   `makeInternalApiCall('<name>')`. This is a REGISTRATION layer, not a mirror:
   the admin API arms its own endpoints from it. Missed until beta.11 found
   `listBuilds` living here.
9. **Non-registration references the grep must still catch**: shipped workflows
   under `secure/admin/workflows/`, admin page JS (`dashboard.js` and friends),
   `docs/` catalogues, and explanatory COMMENTS in neighbouring commands. None
   of these break dispatch, but all of them go stale.

⚠ The starter project's own content pages
(`secure/projects/quicksite/templates/model/json/pages/` + its translations) also
name commands. Treat that as a SEPARATE decision, not part of a removal: that page
already documents three commands deleted in beta.10 (`switchProject`,
`getActiveProject`, `listTokens`) and covers well under half the command surface,
so patching two entries there is arbitrary rather than corrective.

Then: delete the command file itself, re-check the doc trigger table (`ARCHITECTURE.md`
§3 + `COMMAND_API.md` count + catalogue row), and **prove the 1:1 invariant
programmatically** rather than by eye. Grep the whole tree for the command name before
declaring it gone — prose comments and the starter project's own content pages can also
reference it.

---
## Release discipline

- **Bump the `VERSION` file on every git tag.** `secure/admin/functions/updateCheck.php` (reached at `GET /admin/api/update-check`) reads this file via `qs_local_version()` and compares it (via PHP's `version_compare`, which natively handles `beta < release`) against the latest GitHub release. A stale `VERSION` makes the in-app update notice misreport. Bump `VERSION` *before* tagging.
- **Tag format**: `v1.0.0-beta.X` (matches GitHub release tags and PHP's `version_compare` ordering).

---
## How to write and review a `docs/` file

These conventions were settled during a full claim-by-claim review of
`ARCHITECTURE.md`, and they govern every session that touches a `docs/` file, not
just an active doc pass:

- **A schema beats a long text** — a diagram and a table where five dense paragraphs were.
- **A file gets a field table, not a paragraph** — shape first, then fields with defaults and meaning.
- **A section named after a file must describe that file** — and say what the file does *not* hold.
- **Ask "does this belong in this file at all?" before "is it in the right order?"**
- **Check the destination before cutting** — of six apparent duplicates in that review, two turned out to be the authoritative copy.
- **A catalogue with a runtime source belongs beside that source** — verb lists, `data-*` lists, command lists all have a generator and an endpoint; a third hand-maintained copy is how one goes wrong.
- **Don't name one web server when the behaviour is server-agnostic.**
- **One spelling per concept** — the project id had eight.
- **Judge historical commentary grammatically, not lexically** — the tell is a past-tense sentence describing what the system did before; a keyword scan misses them.
- **Convert the tense, don't delete the reasoning** — "let a param carry a scheme past the check" becomes "would let"; the changelog voice goes, the security rationale stays.
- **State which docs have been reviewed and which have not.** Not every `docs/`
  file has had a claim-by-claim pass. Before relying on one, establish whether it
  has — and say so in the review you write. Content transcribed *into* an
  unreviewed file is not thereby verified.

**Renumbering sections is its own procedure.** Section numbers are cited from
other docs, from `CLAUDE.md`, and from the trigger table above, and a renumber
silently breaks every one of them. Two defect classes exist and only the first is
loud: a reference that no longer resolves, and a reference that still resolves but
now lands on the wrong section. Record what moved from which number to which, and
prove the references with a script that walks every `§` in the tree against the
real headings — an eyeball pass is what let six stale references survive a review
that reported itself verified.
