# CLAUDE.md — QuickSite engineering standards

The rules that govern every change to this codebase, whoever or whatever makes
it. Read at the start of every session.

> **This file is tracked, but it is not one of the six canonical `docs/` files
> and it does not freeze with them.** `docs/` is written for somebody *using*
> QuickSite; this file is written for somebody *changing* it. Correct it whenever
> the codebase moves under it.

**Two companion files, deliberately not here:**

| File | Read it when |
|---|---|
| `MAINTAINING.md` | You are adding or removing a **command**. Those are nine-layer procedures that are dormant the rest of the time — keeping them here made this file long enough that its real rules got skimmed. |
| `CLAUDE.local.md` | Never — it is machine-local and gitignored. It holds one developer's paths, interpreters and working preferences, which are not project facts. |

---
## Project Vision

> **QuickSite is a file-based, API-first website operations platform with a visual editor and workflow engine for deterministic and AI-assisted site changes.**
> (canonical positioning, mirrored from `README.md`)

- If a proposed change drifts from this — e.g. introducing a database dependency, adding non-deterministic black-box behaviour, putting site data outside the project folder, or reaching for a heavy framework where the file-based + command-based model already fits — flag it before implementing.
- Documentation rule: no regex literals in `docs/`. Express patterns in plain English (the regex stays in code where it belongs).

## Architecture Principles

- **Centralize shared logic.** If a feature, constant, or utility is used (or could be used) by multiple commands, place it in a shared location (e.g., `utilsManagement.php`) rather than duplicating it. Always check if something similar already exists before creating new code.
- **Think globally.** When implementing a feature for one command, consider whether other commands need the same treatment. Proactively flag when duplication exists.
- **Dependency-free, always.** No npm packages, no Composer additions, no CDN `<script>` / `<link>` URLs, no CSS `@import url('https://…')` (Google Fonts, normalize.css, etc.), no Web Components polyfills. Pure vanilla PHP + vanilla JS + plain CSS. If a feature seems to *need* a library, propose a small in-tree helper instead and flag the trade-off. The existing `QuickSiteAdmin` / `QSComplexWizard` / `QS` namespaces are the right hooks for shared client-side helpers. When you (Claude) spot an existing external dep that violates this rule while working on something unrelated — flag it to the user; don't silently fix it (the user decides whether to schedule cleanup), and don't add new ones.
- **HTML-in-JS hygiene.** When building DOM dynamically in JS, prefer `createElement` + `textContent` + named `_render*` helpers (each returning ONE Element) over `innerHTML = '<...>'` strings. Static structure belongs in PHP partials under `secure/admin/templates/`; dynamic structure belongs in JS helpers. The default is createElement + textNode.
  - It is OK to have *some* — small SVG icons and short trusted snippets where the indirection costs more than it gains.
  - It is NOT OK to have multi-line interpolated HTML strings, string-glued user data, or `<chunk> + variable + <chunk>` concatenations.
  - **When you (Claude) are about to modify code that violates this rule** — rewriting the offender properly is a **priority** and happens **before** the new change goes in. Don't stack new logic on a broken foundation, and don't keep bad coding patterns lingering in your working context. Surface the offender, propose the rewrite, get the user's nod, rewrite, then add the change on top.
  - **When you (Claude) encounter a violation in code you are only reading** (not modifying for the current task), raise it to the user and let them decide whether to schedule a cleanup. Don't silently rewrite code outside the current concern.
  - **When you (Claude) are tempted to use `innerHTML` to glue HTML** — stop. Extract a small helper instead, or use multiple `createElement` calls. If you genuinely think it's the right tool for this specific case, say so explicitly so the user can confirm.
- **Flag hardcoded values that could be flexible.** When implementing a feature, if a value (field name, response path, body shape, default behaviour, etc.) is being hardcoded that could reasonably be user-configurable, **raise it to the user before committing to the hardcoded approach**. Don't silently lock in inflexibility — the user should always be the one to choose between "configurable" and "convention". This goes both ways: don't over-engineer a config knob when a convention is fine either, but always surface the choice.
- **Data shape: JSON for the author's website data.** Per-project data — anything under `secure/projects/<p>/` that describes the author's website — defaults to JSON. QuickSite engine + admin panel itself stays PHP (QuickSite is and will remain a PHP app). The line: if the data describes the author's website, it's JSON; if it's QuickSite plumbing, it's PHP. Forward-compatible reasoning: the website QuickSite builds could conceivably target a different runtime later (or be exported / mirrored independently); JSON for project data keeps that option open. Carve-out: admin config that users routinely EXTEND (OAuth provider presets, future plugin registries, etc.) defaults to JSON too — so extension doesn't require PHP knowledge. Internal admin config consumed only by the engine (`roles.php`, `auth.php`, `api-secrets.php`, `qsVerbCatalog.php`) stays PHP. When you (Claude) introduce NEW per-project data, default to JSON. When you encounter EXISTING per-project PHP-array files (notable known candidate: `secure/projects/<p>/management/routes.php`), flag as a chip — don't silently rewrite (migration has per-file design questions). Engine/admin PHP arrays stay PHP without flag.
- **`help` states no deployment-specific value.** The `help` command's output must be **identical on every installation**. It is a specification of the API, not a report about this server. So: name a **deployment-varying** constant, never print its value (`SECURE_FOLDER_PATH`, `SERVER_ROOT`, `PUBLIC_FOLDER_SPACE` — the name is the documentation); write paths against the `<secure>/…` placeholder rather than a literal `secure/…`, because the secure folder is renameable and a literal is simply wrong wherever it was renamed; and never interpolate `$_SERVER`, `getenv()`, resolved paths, versions, or counts of what happens to exist here. This is both a correctness rule (the docs stay true off the default layout) and a disclosure rule (`help` is in `$PUBLIC_COMMANDS` — it answers before authentication, so anything it prints is public). The same applies to any user-facing string that names a QuickSite-internal path, including error messages that tell an operator which config file to edit.
  - ⚠ **The rule is about values that VARY, not about constants as a category.** A hardcoded source constant — `MAX_ROUTE_DEPTH = 5`, `SPECIAL_PAGES`, a byte cap — prints identically on every installation and discloses nothing, so `help` states its **value**. Naming it instead would be actively worse: several are file-local `const` declarations an API consumer cannot resolve, and the surrounding `validation` prose already states the number, so naming would make one entry disagree with itself two lines apart. Ruled 2026-09-03 after the S5.6b generator applied the literal reading and produced exactly that contradiction.
- **Admin panel deprioritizes mobile.** Don't proactively invest in mobile/tablet responsive admin chrome — the visual editor is a desktop authoring tool by nature (Webflow, Framer, Figma, VS Code, WordPress admin: all desktop-first). The sidebar + canvas + tools layout, drag-resize, multi-row action chips, and complex modals fundamentally fight a phone screen. The visual editor's **device-emulator modes** (mobile / tablet / desktop preview of the USER's site) ARE part of the product — those exist so designers can author responsive output. But the admin tooling itself stays desktop. The existing partial mobile chrome (`main-area.php`'s `mobile-ctx-*` row, `preview-mobile-section__*`) stays for consistency, just don't build NEW mobile-specific surfaces. Flag mobile-CSS work + ask before investing time.


## Runtime floor

**PHP 8.0.30 is the supported floor.** The engine uses `match()` and other 8.0+
syntax, so 7.4 cannot boot it.

⚠ **Run every test suite on the floor AND on whatever your Apache actually
serves.** A suite green on only one proves nothing about deployments at the other
end of the supported range — beta.10 found a data-destruction bug that was live on
the floor and invisible on the runtime.

## Reference points

- **Key shared file**: `secure/src/functions/utilsManagement.php` — constants and utilities used across 20+ command files.
- **Special pages**: defined as the `SPECIAL_PAGES` constant in `utilsManagement.php`.
- **Route export**: `varExportNested()` in `utilsManagement.php` — forces string keys to avoid PHP's numeric key auto-casting.

## Release Discipline

- **Bump the `VERSION` file on every git tag.** `secure/admin/functions/updateCheck.php` (reached at `GET /admin/api/update-check`) reads this file via `qs_local_version()` and compares it (via PHP's `version_compare`, which natively handles `beta < release`) against the latest GitHub release. A stale `VERSION` makes the in-app update notice misreport. Bump `VERSION` *before* tagging.
- **Tag format**: `v1.0.0-beta.X` (matches GitHub release tags and PHP's `version_compare` ordering).

## Documentation Maintenance

The six canonical docs in `docs/` (`ARCHITECTURE.md`, `ADMIN_PANEL.md`, `COMMAND_API.md`, `WORKFLOW_SYSTEM.md`, `PROJECT_STRUCTURE.md`, `DESIGN_DECISIONS.md`) drift fast. When editing the source files listed below, re-check the corresponding doc:

**Design-decision discipline**: when a non-trivial design choice gets locked (during a sprint, a design round, or any session where alternatives were weighed), append a new entry to `docs/DESIGN_DECISIONS.md` **once it is implemented** — not at lock time. A decision that is not built yet is not a design decision, it is a plan: its rationale lives in the sprint's `NOTES/planning/` concern doc until the code lands, and only then does it earn an entry here. (Exception: if Sangio explicitly asks for an entry before implementation, write it.) Each entry: decision + reasoning + alternatives considered + source. The file is **append-only** for historical entries; never silently rewrite a past entry. When a locked decision later changes, add a NEW dated entry that says `**Supersedes**: <link to old entry>` and mark the old entry's title with `(superseded YYYY-MM-DD)`. The historical thinking stays visible; the evolution is explicit. This file replaces per-release planning docs (`NOTES/planning/BETA*_*.md`) as the home of the *why*; planning docs can be deleted once their rationale is migrated.

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
| `secure/admin/functions/accountSelf.php` / `membershipSelf.php` / `directory.php` / `public/admin/self/index.php` (account + membership self-service and the two directory lookups — not commands) | `COMMAND_API.md` (*What is deliberately not a command* — the route table AND the guarantees paragraph), `ARCHITECTURE.md` §3 (global-scope list, consent-model prose), `ADMIN_PANEL.md` §9.13 (My account) + the memberships section |
| **Adding ANY new directory under `public/admin/`** (a JSON endpoint, an asset folder — anything) | ⚠ **RUN `NOTES/tests/beta11/s27_admin_route_collision_probe.php` BEFORE calling the slice done.** It checks **both** directions and each has drawn blood once. **Shadowing** — Apache resolves a real directory before `public/admin/.htaccess`'s `FallbackResource`, so a directory named like an `AdminRouter::$validPages` entry silently shadows that page (beta.11 S6.2 shipped this by naming an endpoint `account`; it is also why the asset page is routed `media`). **Unreachability** — the converse: a directory with its own `index.php` is a front controller and needs a location block in `generate_nginx_config()` (`secure/src/functions/NginxConfig.php`), or `location /admin/` swallows it and the panel answers an **HTML 404 to a JSON client**. S6.1 and S6.2 both shipped that way for `/admin/state/` and `/admin/self/` and it went unnoticed until S5.d, **because Apache picks up each directory's own `.htaccess` automatically and only nginx has a central list to fall out of step with**. A new front controller means a new block. |

| Implementing a design decision that was locked earlier (sprint design round, scope reduction, alternative chosen, etc.) | `DESIGN_DECISIONS.md` (append a new entry once the code lands — decision + reasoning + alternatives + source). While it is still unbuilt, the rationale belongs in the sprint's `NOTES/planning/` concern doc. |

### How to write and review a `docs/` file

The conventions decided during the beta.11 `ARCHITECTURE.md` review live in
`NOTES/planning/DOC_REVIEW_CONVENTIONS.md` — one copy, kept where the worker
briefs and the renumber log already are. **Read it before reviewing or
restructuring any `docs/` file.** The ones that govern every session, not just an
active doc pass:

- **A schema beats a long text** (A1) — a diagram and a table where five dense paragraphs were.
- **A file gets a field table, not a paragraph** (A2) — shape first, then fields with defaults and meaning.
- **A section named after a file must describe that file** (A3) — and say what the file does *not* hold.
- **Ask "does this belong in this file at all?" before "is it in the right order?"** (B1).
- **Check the destination before cutting** (B2) — of six apparent duplicates in that review, two were the authoritative copy.
- **A catalogue with a runtime source belongs beside that source** (B4) — verb lists, `data-*` lists, command lists all have a generator and an endpoint; a third hand-maintained copy is how one goes wrong.
- **Don't name one web server when the behaviour is server-agnostic** (C3).
- **One spelling per concept** (C4) — the project id had eight.
- **Judge historical commentary grammatically, not lexically** (F1) — the tell is a past-tense sentence describing what the system did before; a keyword scan misses them.
- **Convert the tense, don't delete the reasoning** (F2) — "let a param carry a scheme past the check" becomes "would let"; the changelog voice goes, the security rationale stays.
- **State which docs have been reviewed and which have not** (G3) — as of beta.11 only `ARCHITECTURE.md` has had a claim-by-claim pass. Content transcribed *into* an unreviewed file is not thereby verified.

Section renumbering has its own log and probe — see `NOTES/planning/DOC_SECTION_RENUMBER_LOG.md`.

### What belongs in which doc — content hygiene

The `docs/` files are **user-facing**. Their reader is somebody trying to understand what QuickSite is, how it works, or how to use a feature. They are NOT internal status logs.

**Belongs in `docs/`** (`ARCHITECTURE.md`, `ADMIN_PANEL.md`, `COMMAND_API.md`, `WORKFLOW_SYSTEM.md`, `PROJECT_STRUCTURE.md`):
- What the system does and how it behaves
- How a user reaches a feature, what arguments it takes, what the output looks like
- Architectural facts (file boundaries, data flow, security model)
- Stable cross-references between sections + to other user-facing docs

**Does NOT belong in `docs/`**:
- **Maintainers notes** — the doc-maintenance trigger table lives ONLY in this file (CLAUDE.md). Do not duplicate it as a `_Maintainers note_` admonition at the top of each doc.
- **`_Last updated_` dates** — git history is authoritative.
- **Slice / track / phase numbers** woven into prose — `(Slice 7)`, `(beta.9 A4 Slice 6)`, `(Q1 lock)`, `(Track 2d)`, `(post-Slice-4 polish)`. These mean nothing to a reader who wasn't in the room.
- **Forward-looking dev process** — "filed in BACKLOG.md", "deferred to beta.10", "tracked as a chip", "will move to superadmin once that role lands", "future polish".
- **Historical commentary** — "the legacy X was removed", "previously did Y but switched to Z", "before this change …", "as of v1.0.0-beta.6". Document the current state; git log explains the change.
- **Bug logs** — caught-and-fixed bugs belong in the commit or a learnings doc (`NOTES/planning/<concern>_LEARNINGS.md` if useful for the next concern's author), never in user-facing architecture docs.
- **Tech-debt commentary** — "this is a shortcut and should eventually be replaced" is dev process, not architecture.
- **Pointers to `NOTES/planning/*.md`** — those are local-only dev planning docs (NOTES/ is gitignored). Users can't open them.
- **Roadmap notes** — `> _Roadmap note:_ this will grow when beta.X ships` is committee-meeting language.

**`docs/DESIGN_DECISIONS.md` is special**: append-only history of locked design decisions. Each entry has Decision + Reasoning + Alternatives + Source. Its purity rules:
- Allowed time tag: `beta.X` (git tags are stable anchors); `(locked YYYY-MM-DD)` dates in headings (the timestamping convention).
- NOT allowed: Slice numbers, Q-locks (Q1/Q2/…), track designators (A1/A2/…), planning-doc references. These are dev artifacts that meant something at lock time but don't help anyone reading the rationale later. Refer to neighbouring entries with "see entry below" / "see above" — sequencing is positional in the file, not numbered.
- Source field: name what triggered the lock without slice numbers — "locked during beta.9", "design round 2026-06-21", "kickoff design sweep" — not "A4 Slice 6+".

**`CLAUDE.md` (this file)** is where engineering metadata lives: the trigger table, release discipline, this hygiene rule itself. It is tracked, and it is written for somebody CHANGING QuickSite rather than using it — that audience split, not visibility, is what separates it from `docs/`. Maintainer notes that "belong somewhere" belong here (or in `MAINTAINING.md` when they are procedures), not in the public docs. Anything specific to one machine or one developer's preferences belongs in `CLAUDE.local.md`, which is gitignored.

When you find yourself wanting to add any of the "does NOT belong" categories to a `docs/` file, ask: is this explaining what the system DOES, or is it about how the system was BUILT? If the latter, it goes to CLAUDE.md, `DESIGN_DECISIONS.md`, or a local NOTES/ file.
