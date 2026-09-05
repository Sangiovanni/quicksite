# Maintaining QuickSite

Procedures for changing the engine itself — dormant most of the time, and exact
when they are not. They live here rather than in `CLAUDE.md` so that the rules
governing *every* change stay short enough to actually be read.

> **This is not the contributor guide.** How to report a bug, translate, or open
> a pull request is in `README.md` under *Contributing*. This file is for someone
> already inside the codebase performing one of the procedures below.

Read `CLAUDE.md` first — it holds the standards that bind every change
(dependency-free, centralize shared logic, HTML-in-JS hygiene, the data-shape
rule, and the documentation-maintenance triggers).

> **Why these checklists are so specific.** Every layer named below was found the
> hard way: a command that was routable and completely unusable because it had no
> category; a deleted command that survived in an allowlist nobody remembered; a
> registration layer in the admin API that mirrors nothing and had to be edited by
> hand. The lists are long because the failure modes are silent.

---
## Adding a new command — checklist

Adding a `secure/management/command/<name>.php` is only the first of **five** registrations. The command is **invisible** at four different layers (router, category map, role permissions, /admin/command UI listing) until each is updated.

1. **Create** `secure/management/command/<name>.php`. **Two file styles are legitimate — pick by whether the command must be callable in-process:**
   - **Top-level script** (50 of the 172 command files). The file simply executes on include: it reads its parameters, does the work, and calls `->send()`. The dispatcher includes exactly one command file per request, so this is safe and is the simpler default.
   - **`__command_<name>()` function + HTTP-dispatch guard** (122 of 172). The file defines `function __command_<name>(array $params = [], array $urlParams = []): ApiResponse` and ends with `if (!defined('COMMAND_INTERNAL_CALL')) { … __command_<name>(…)->send(); }`. The guard lets another command `require_once` this file and call the function directly without the include also firing an HTTP response.
   - **The function+guard style is REQUIRED** when the command is (or may become) an entry in `CommandRunner`'s allowlist, or when another command file calls it directly. Every one of `CommandRunner`'s 25 allowlisted commands defines its function — that invariant is what makes in-process execution work, so a new allowlist entry must be converted first. When in doubt, use the function+guard style: it costs three lines and keeps the option open.
2. **Append** `'<name>'` to the array in `secure/management/routes.php` (the single-line returns array; alphabetisation isn't enforced, place it near its conceptual neighbours). Makes the command **routable**.
3. **Add to `secure/management/config/categories.php`** — every routed command must belong to **exactly one** category, either an existing one or a new `'<category>' => ['scope' => …, 'access' => …, 'commands' => [...]]` block. The category fixes the command's **scope** (global vs project-marker) as well as its permission group. **This is not optional**: `routes.php` ↔ `categories.php` must stay 1:1 in *both* directions, and a routed-but-uncategorised command fails closed at `hasPermission` — it is routable and completely unusable. Note the shape: `category => ['scope','commands']`, **not** a flat command→category map.
4. **Add to roles** in `secure/management/config/roles.php` AND `roles.php.example` — at minimum the `editor` / `designer` / `developer` / `admin` tiers as appropriate. Use a **string key** (`'<name>' => '<name>'`) to avoid the duplicate-int-key clobber bug that pre-existed in this file (now patched, but the safer pattern stays). Makes the command **permitted** for each role. **Skip this step only for a category that is `scope: global` + `access: 'any'`** — those are open to every authenticated user and are never role-listed; adding one here is inventing an edit.
5. **`help.php` `$GLOBALS['__help_commands']`** — append an entry describing parameters, success/error shapes, notes. Makes the command **documented** (consumed by the runtime `help` endpoint AND by callers that want spec info). Don't smoke-test routing via `help` — POST to the endpoint directly. **Name constants, never their values** — see *`help` states no deployment-specific value* below.
6. **`secure/admin/functions/AdminHelper.php` → `getCommandCategories()`** — add the command name to the appropriate category's `'commands'` array. Makes the command **visible on `/admin/command`** (the categorisation that drives the UI list is hand-curated here, not derived from routes.php). Easy to miss; without it the command exists, is routable, has permissions, has docs — but doesn't show up in the admin UI list.
7. **OPcache caveat** — after editing `routes.php`, the dispatcher won't see the new command until OPcache refreshes. Restart WAMP or `opcache_reset()` during dev. `AdminHelper.php` and `roles.php` are also OPcache-cached but get hot-reloaded on most admin page navigations; `routes.php` is the strictest.
8. **"Command not found" can mean four things** — when debugging, rule out in this order:
   - Hand-built URL with a double slash. `managementUrl` ends with `/`; `managementUrl + '/foo'` produces `/management//foo` and the dispatcher reads an empty command. **Always use `QuickSiteAdmin.apiRequest(cmd, method, body, urlParams?)`** instead of hand-building.
   - OPcache holding old `routes.php`. Restart WAMP / reset OPcache.
   - Missing entry in `routes.php`. Confirm with grep.
   - Missing entry in `categories.php` — an uncategorised command fails closed at `hasPermission`, which surfaces as a permission error, not a routing one.
   - Missing role permission — check `secure/management/config/roles.php` for the current user's tier.
9. **"Command exists but doesn't appear on /admin/command"** — almost always step 6 (missing from `AdminHelper.php → getCommandCategories()`). Confirm with grep + hard-refresh the page.
10. **Doc trigger** — see *Documentation Maintenance* table below: routes.php change ⇒ re-check `ARCHITECTURE.md §3` + `COMMAND_API.md` (command count).

### REMOVING a command — the checklist is SIX layers, not four

Adding needs the registrations above. **Removing** needs every place that can hold a
command NAME, and two of them are outside the add-checklist entirely — both were missed
until beta.10 C15 15.3 (deleting `switchProject` + `getActiveProject`) tripped over them:

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
   linger here as a dead entry (this is where `listTokens` survived C5b, and
   `getActiveProject` survived until 15.3).
7. **`public/admin/assets/js/core/api.js`** — `FALLBACK_GLOBAL_COMMANDS`, the client's
   defensive scope list used when the page fails to emit the live set.
8. **`public/admin/api/index.php`** — `QS_API_ARM_COMMANDS` (the arm-name →
   command permission map) **and** the `switch` case that calls
   `makeInternalApiCall('<name>')`. This is a REGISTRATION layer, not a mirror:
   the admin API arms its own endpoints from it. Missed until beta.11 S3.2 found
   `listBuilds` living here.
9. **Non-registration references the grep must still catch**: shipped workflows
   under `secure/admin/workflows/`, admin page JS (`dashboard.js` and friends),
   `docs/` catalogues, and explanatory COMMENTS in neighbouring commands. None
   of these break dispatch, but all of them go stale.

⚠ The starter project's own content pages
(`secure/projects/quicksite/templates/model/json/pages/` + its translations) also
name commands. Treat that as a SEPARATE decision, not part of a removal: as of
beta.11 that page already documents three commands deleted in beta.10
(`switchProject`, `getActiveProject`, `listTokens`) and covers 80 of 176
commands, so patching two entries there is arbitrary rather than corrective.

Then: delete the command file itself, re-check the doc trigger table (`ARCHITECTURE.md`
§3 + `COMMAND_API.md` count + catalogue row), and **prove the 1:1 invariant
programmatically** rather than by eye. Grep the whole tree for the command name before
declaring it gone — prose comments and the starter project's own content pages can also
reference it.

