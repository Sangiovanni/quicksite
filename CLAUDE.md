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
| `MAINTAINING.md` | You are performing one specific procedure — adding or removing a **command**, cutting a **release**, checking which `docs/` file your change obliges you to re-check, or reviewing a `docs/` file. Those are long, exact, and dormant the rest of the time; keeping them here made this file long enough that its real rules got skimmed. |
| `CLAUDE.local.md` | Never — it is machine-local and gitignored. It holds one developer's paths, interpreters and working preferences, which are not project facts. |

⚠ **Changed a file? Check the trigger table in `MAINTAINING.md` before you
finish.** Editing source without re-checking the doc it is documented in is how
`docs/` goes stale, and the table is the only list of which is which.

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
  - ⚠ **The rule is about values that VARY, not about constants as a category.** A hardcoded source constant — `MAX_ROUTE_DEPTH = 5`, `SPECIAL_PAGES`, a byte cap — prints identically on every installation and discloses nothing, so `help` states its **value**. Naming it instead would be actively worse: several are file-local `const` declarations an API consumer cannot resolve, and the surrounding `validation` prose already states the number, so naming would make one entry disagree with itself two lines apart. Ruled 2026-09-03, after a `help`-text generator applied the literal reading and produced exactly that contradiction.
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

## What belongs in which doc — content hygiene

The `docs/` files are **user-facing**. Their reader is somebody trying to understand what QuickSite is, how it works, or how to use a feature. They are NOT internal status logs.

**Belongs in `docs/`** (`ARCHITECTURE.md`, `ADMIN_PANEL.md`, `COMMAND_API.md`, `WORKFLOW_SYSTEM.md`, `PROJECT_STRUCTURE.md`):
- What the system does and how it behaves
- How a user reaches a feature, what arguments it takes, what the output looks like
- Architectural facts (file boundaries, data flow, security model)
- Stable cross-references between sections + to other user-facing docs

**Does NOT belong in `docs/`**:
- **Maintainers notes** — the doc-maintenance trigger table lives ONLY in `MAINTAINING.md`. Do not duplicate it as a `_Maintainers note_` admonition at the top of each doc.
- **`_Last updated_` dates** — git history is authoritative.
- **Slice / track / phase numbers** woven into prose — `(Slice 7)`, `(beta.9 A4 Slice 6)`, `(Q1 lock)`, `(Track 2d)`, `(post-Slice-4 polish)`. These mean nothing to a reader who wasn't in the room.
- **Forward-looking dev process** — "filed in the backlog", "deferred to beta.10", "tracked as a chip", "will move to superadmin once that role lands", "future polish".
- **Historical commentary** — "the legacy X was removed", "previously did Y but switched to Z", "before this change …", "as of v1.0.0-beta.6". Document the current state; git log explains the change.
- **Bug logs** — caught-and-fixed bugs belong in the commit, or in a local learnings note if they are useful to the next concern's author; never in user-facing architecture docs.
- **Tech-debt commentary** — "this is a shortcut and should eventually be replaced" is dev process, not architecture.
- **Pointers to the maintainer's local planning notes** — those live in a gitignored working directory. A reader who cloned the repository cannot open them, so a `docs/` file must never cite one by path or by filename. The same applies to any tracked file: **if a pointer's target is not itself tracked, the pointer is dead for everyone but its author.** State the rule or the reasoning inline instead, and let the citation go.
- **Roadmap notes** — `> _Roadmap note:_ this will grow when beta.X ships` is committee-meeting language.

**`docs/DESIGN_DECISIONS.md` is special**: append-only history of locked design decisions. Each entry has Decision + Reasoning + Alternatives + Source. Its purity rules:
- Allowed time tag: `beta.X` (git tags are stable anchors); `(locked YYYY-MM-DD)` dates in headings (the timestamping convention).
- NOT allowed: Slice numbers, Q-locks (Q1/Q2/…), track designators (A1/A2/…), planning-doc references. These are dev artifacts that meant something at lock time but don't help anyone reading the rationale later. Refer to neighbouring entries with "see entry below" / "see above" — sequencing is positional in the file, not numbered.
- Source field: name what triggered the lock without slice numbers — "locked during beta.9", "design round 2026-06-21", "kickoff design sweep" — not "A4 Slice 6+".
- Because it is append-only and public, its **existing** entries are never silently rewritten — including their citations. A sweep that strips dead pointers from the rest of the tree stops at this file.

**Design-decision discipline**: when a non-trivial design choice gets locked (during a sprint, a design round, or any session where alternatives were weighed), append a new entry to `docs/DESIGN_DECISIONS.md` **once it is implemented** — not at lock time. A decision that is not built yet is not a design decision, it is a plan: its rationale stays in the sprint's own working notes until the code lands, and only then does it earn an entry here. (Exception: if Sangio explicitly asks for an entry before implementation, write it.) Each entry: decision + reasoning + alternatives considered + source. The file is **append-only** for historical entries; never silently rewrite a past entry. When a locked decision later changes, add a NEW dated entry that says `**Supersedes**: <link to old entry>` and mark the old entry's title with `(superseded YYYY-MM-DD)`. The historical thinking stays visible; the evolution is explicit. This file — not a per-release planning document — is the home of the *why*.

**`CLAUDE.md` (this file)** is where the standards that bind every change live: the vision, the architecture principles, the runtime floor, and this hygiene rule itself. It is tracked, and it is written for somebody CHANGING QuickSite rather than using it — that audience split, not visibility, is what separates it from `docs/`. Maintainer notes that "belong somewhere" belong here, or in `MAINTAINING.md` when they are procedures — not in the public docs. Anything specific to one machine or one developer's preferences belongs in `CLAUDE.local.md`, which is gitignored.

When you find yourself wanting to add any of the "does NOT belong" categories to a `docs/` file, ask: is this explaining what the system DOES, or is it about how the system was BUILT? If the latter, it goes to `CLAUDE.md`, `MAINTAINING.md`, `DESIGN_DECISIONS.md`, or a local working note.
