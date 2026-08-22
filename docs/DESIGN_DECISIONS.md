# QuickSite — Locked Design Decisions

> Canonical log of design decisions that have been **locked** during the
> project's evolution. Each entry captures the *why* — what was chosen,
> the reasoning behind it, the alternatives weighed and rejected, and
> pointers to the source code + behavioural docs.
>
> Behavioural reference (the *what*) lives in [ARCHITECTURE.md](ARCHITECTURE.md),
> [ADMIN_PANEL.md](ADMIN_PANEL.md), [COMMAND_API.md](COMMAND_API.md),
> [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md), and [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).
> This file is the *why*. Together the two halves form the full doc.

> _Maintainers note:_ append a new entry for every non-trivial design decision
> **once the code implementing it lands** — an unbuilt decision is a plan, and
> its rationale belongs with the plan until then. This file
> is **append-only** for the historical decisions; never silently rewrite
> a past entry. When a decision later changes, add a NEW dated entry that
> says `**Supersedes**: <link to old entry>` and mark the old entry's
> status `(superseded YYYY-MM-DD)` in its title. The historical thinking
> stays visible; the evolution is explicit.

## How to add an entry

Each entry uses this shape:

```markdown
### Decision title (locked YYYY-MM-DD)

**Decision**: 1–2 sentences stating what was decided.

**Reasoning**: Why this over the alternatives — the constraint or
trade-off the decision resolves. Keep it concrete; aim for what a
future reader needs to understand the choice.

**Alternatives considered**: Briefly list what was weighed and
rejected, with one-line explanations. Often empty for low-controversy
calls; never empty for hard ones.

**Source**: Code file(s) where the decision is implemented + the
canonical doc section that describes the behaviour.
```

Entries are grouped by **area** (Routing, Server-side data resolver,
etc.) within a release. Newer areas go to the bottom; within an area,
entries are roughly chronological.

---

## Routing (beta.8)

### `:slug` segment syntax — Express precedent (locked 2026-06-02)

**Decision**: Parameterised route segments use the `:name` prefix
(`/products/:slug`, `/users/:id/posts/:postId`). The `:` character
marks the segment as a placeholder that captures any URL value at
request time.

**Reasoning**: The project already used `:name` for API path templating
in `api-endpoints.json` (e.g. `/users/:id/posts`). Using the same
syntax for routes means one mental model for path placeholders across
the system. Express, Fastify, FastAPI, Rails, and most others use the
same convention — authors arriving from those ecosystems read it for
free.

**Alternatives considered**: `[slug]` (Next.js) — adds bracket
escaping concerns and diverges from the project's existing
api-endpoints convention. `{slug}` (Laravel curly) — same divergence
+ HTML/template-literal collision risk.

**Source**: `secure/src/classes/TrimParameters.php`,
`secure/src/functions/routeHelpers.php`. Behaviour:
[ARCHITECTURE.md §5.3](ARCHITECTURE.md).

### Mixed `routes.php` shape — strings OR records (locked 2026-06-04)

**Decision**: A route in `routes.php` can be either a plain string
(when the route has no params — `'about'`) or a record
(when it has params — `['path' => 'products/:slug', 'params' => [...]]`).
Existing string-only sidecars auto-promote on load; new entries are
written in whichever shape matches their content.

**Reasoning**: Forcing every entry to the record form would bloat
sidecars for sites that mostly have static routes — most QuickSite
sites have a handful of pages, none with params. Forcing string-only
would make params impossible. Mixed is the pragmatic shape;
`varExportNested()` already serialises both correctly.

**Alternatives considered**: All-records (rejected — sidecar bloat for
the common case). Separate sidecar file for param-route metadata
(rejected — splits the single source of truth for routes into two
files; harder to keep in sync).

**Source**: `secure/management/routes.php` (the project's sidecar),
`secure/src/functions/utilsManagement.php` (`varExportNested`),
`secure/management/command/addRoute.php` (the writer). Behaviour:
[ARCHITECTURE.md §5.3](ARCHITECTURE.md).

### `urldecode` happens in the matcher, not the consumer (locked 2026-06-04)

**Decision**: When the route matcher captures a path segment into a
param, it `urldecode`s the captured value before exposing it to PHP
or qs.js. Templates and JS see `red vase`, not `red%20vase`.

**Reasoning**: Matches PHP's `$_GET` convention (query strings come
out already decoded). Forcing every consumer to remember to decode
would create a class of "works in dev, breaks on URL-encoded values"
bugs that route params are particularly prone to (slugs with
apostrophes, multi-word IDs, etc.).

**Alternatives considered**: Pass raw + let consumers decode
(rejected — error-prone, inconsistent with `$_GET`). Encode once and
decode at every reader (rejected — silly).

**Source**: `secure/src/classes/TrimParameters.php` (matcher). The
captured values land in `$trimParameters->routeParams()` and
`QS.routeParams` already decoded.

### Specificity wins; ties broken by declaration order (locked 2026-06-04)

**Decision**: When multiple routes match the same URL, the one with
the most **literal** (non-`:param`) segments wins. Ties are broken by
declaration order in `routes.php`. Example: `/products/featured`
(literal score 2) beats `/products/:slug` (score 1) for the URL
`/products/featured`; for `/products/red-vase`, only `/products/:slug`
matches so it wins.

**Reasoning**: Matches Express / Laravel / Rails / FastAPI convention.
Lets sites cleanly mix curated exact pages with param catch-alls — the
bread-and-butter CMS pattern. Without this rule, the order in
`routes.php` would be load-bearing in non-obvious ways.

**Alternatives considered**: Pure declaration order (rejected — fragile;
edits to one route can break unrelated routes). Most-specific based on
segment count alone, no tie-breaker (rejected — silent ambiguity).
Block any potential overlap at save time (rejected — would refuse the
valid curated-exact + catch-all pattern).

**Source**: `secure/src/classes/TrimParameters.php` (the scorer +
matcher), `secure/management/command/addRoute.php` (warns on ambiguous
cases without blocking). Behaviour:
[ARCHITECTURE.md §5.3](ARCHITECTURE.md).

### Sibling-exact + param conflict — WARN, don't BLOCK (revised 2026-06-04)

**Decision**: When `addRoute` would create a param route at the same
depth as an existing exact sibling (e.g. adding `/products/:slug`
while `/products/featured` already exists), the save SUCCEEDS but the
response carries a `warnings[]` array describing the overlap. The admin
UI surfaces these as toast warnings; the route is created either way.

**Reasoning**: The earlier draft BLOCKED this case — the argument was
"silent shadowing is a security hazard." But the specificity rule
(see above) makes the runtime SAFE: `/products/featured` always wins
over `/products/:slug` for the exact URL `/products/featured`. The
warning surfaces "are you sure?" without blocking the **legitimate**
use case (curated landing page + param catch-all), which every CMS
needs.

**Alternatives considered**: BLOCK any overlap (the original draft —
rejected because it would forbid the most common CMS authoring
pattern). Silent allow with no warning (rejected — easy to create the
overlap accidentally and not realise).

**Source**: `secure/management/command/addRoute.php` (`warnings[]`
generation), `public/admin/assets/js/pages/sitemap.js` (toast
surfacing). Behaviour: [ADMIN_PANEL.md §9.8](ADMIN_PANEL.md).

### Case-sensitive paths (locked 2026-06-04)

**Decision**: Paths match case-sensitively. `/Products/red-vase` and
`/products/red-vase` are different URLs.

**Reasoning**: Matches Unix filesystem + HTTP convention. Case-folding
would create canonical-URL ambiguity (which one is the "real" URL? SEO
implications). Authors who want case-insensitive matching can do it at
the route level by registering multiple paths or with `.htaccess`
rewrites.

**Alternatives considered**: Case-insensitive matching. Rejected —
non-standard for HTTP, complicates canonical-URL choice.

**Source**: `secure/src/classes/TrimParameters.php`.

### Route-meta JS schema includes `type` info (locked 2026-06-04)

**Decision**: The build-emitted `public/scripts/qs-route-schema.js`
includes each param's `type` field. qs.js's client matcher uses it to
coerce `:id` to integer at schema-load time.

**Reasoning**: Coercion at load time (once per page load) is cheaper
than coercion at every consumer call site. The size cost is a few
bytes per route.

**Alternatives considered**: Omit type info; coerce in qs.js consumers
(rejected — micro-optimization for a non-bottleneck; coercion at the
boundary is cleaner).

**Source**: `secure/management/command/build.php` (emit),
`public/scripts/qs.js` (consume).

### Sitemap UI — interleaved with badge, not clustered (locked 2026-06-04)

**Decision**: Param routes appear in the sitemap tree alongside their
exact siblings (at the correct depth), with a small `N param[s]` chip
next to the path. They are NOT clustered into a separate "Param
routes" section.

**Reasoning**: Authors think about routes hierarchically (where does
this page live in the site?). Clustering param routes separately would
hide the tree structure for sites with many param routes. The badge is
enough visual distinction without breaking the mental model.

**Alternatives considered**: Clustered section. Rejected — breaks the
tree mental model for users with many param routes.

**Source**: `public/admin/assets/js/pages/sitemap.js` (`_renderRoutePath`).
Behaviour: [ADMIN_PANEL.md §9.8](ADMIN_PANEL.md).

### NTFS `:` ↔ `__` filesystem sanitisation

**Decision**: When a route path containing `:` (e.g. `products/:slug`)
needs to map to a filename on a Windows / NTFS filesystem, the `:`
character is sanitised to `__` (e.g.
`products/__slug.json`). Reads reverse the mapping.

**Reasoning**: `:` is a reserved character on NTFS (used for
alternate data streams). Without sanitisation, file operations on
param-route sidecars fail silently on Windows. The `__` choice avoids
collision with realistic filename characters; the round-trip is
deterministic.

**Alternatives considered**: Slash-encode (`%3A`). Rejected — visually
noisy in file listings, harder to recognise the param when browsing the
filesystem. Single underscore. Rejected — collides with naturally-
authored filenames containing underscores.

**Source**: `secure/src/functions/routeHelpers.php` (single source of
truth for the `:` ↔ `__` mapping). Behaviour:
[ARCHITECTURE.md §5.3](ARCHITECTURE.md).

---

## Server-side data resolver (beta.8)

### Cache TTL per-request default; opt-in per route (locked 2026-06-02)

**Decision**: Resolvers do NOT cache by default. Each route opts into
caching by setting a positive `cacheTTL` (seconds) in its resolver
config. `cacheTTL: 0` (or absent) means "fire fresh on every request."

**Reasoning**: Most authors won't think about caching at all when they
first add a resolver. The conservative default (no cache, every
request hits the upstream) is correct for that case — no stale data,
no surprises. Authors who care about latency / quota costs opt in
explicitly with a TTL they can reason about.

**Alternatives considered**: Cache by default with some "reasonable"
TTL (rejected — would silently freeze data for unintended caches,
harder to debug than "every request hits API"). Mandatory TTL on every
resolver (rejected — adds friction to the simplest case).

**Source**: `secure/src/functions/serverFetch.php`
(`_serverFetchPrepare` cache eligibility check). Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md) (cache section).

### `callableFrom` auto-derive from auth type (locked 2026-06-02)

**Decision**: Each endpoint carries a `callableFrom` marker
(`client` / `server` / `both`) that defaults to a value auto-derived
from the auth type: `none` / `bearer` / `cookie` → `both`; `apiKey`
with a server-stored secret → `server` only. The user can explicitly
override via editApi.

**Reasoning**: Most endpoints don't need any thought about who can call
them — the auth type already conveys the constraint (`apiKey` with a
server-side secret is server-only by construction). Auto-derive
handles the common case silently; explicit override handles the rare
case (e.g. an endpoint that's `bearer`-authed but the server should
not call it).

**Alternatives considered**: Mandatory explicit `callableFrom` on
every endpoint (rejected — extra config burden for the obvious cases).
No `callableFrom` at all (rejected — `apiKey` secrets would leak into
the client bundle).

**Source**: `secure/src/classes/ApiEndpointManager.php` (the
`effectiveCallableFrom` resolver). Behaviour:
[ADMIN_PANEL.md §9.1](ADMIN_PANEL.md) (API registry).

### Cache auto-clear on `editApi` success (locked 2026-06-04)

**Decision**: When `editApi` succeeds for an endpoint, walk
`secure/cache/resolver/` and delete every cache entry whose key
matches that endpoint. Filesystem rare-failure → log + continue
(don't 500 the edit because the cache wipe partially failed).

**Reasoning**: Endpoint config changes are rare. Without the auto-
clear, authors would routinely hit "I changed the endpoint URL but the
site still shows the old response" — a confusing-debug rabbit hole
that costs more than the auto-clear's ~10 LOC.

**Alternatives considered**: Manual invalidation only (rejected —
too easy to forget; the symptom is silent staleness). Smarter
broadcast on related-data mutation (deferred to beta.9+ if real need
surfaces beyond endpoint-config changes).

**Source**: `secure/management/command/editApi.php` (the hook),
`secure/src/functions/resolverCache.php` (the underlying utility),
`secure/management/command/cleanResolverCache.php` (manual invocation).

### `api-secrets.php` location at `secure/admin/config/` (locked 2026-06-04)

**Decision**: Server-side endpoint secrets (e.g. `apiKey` values) live
in `secure/admin/config/api-secrets.php`, gitignored, shipped alongside
an `api-secrets.php.example` template. The `.example` carries an
explicit security disclosure header explaining the file's
considerations.

**Reasoning**: Mirrors the existing `auth.php` / `auth.php.example`
pattern shipped earlier — one place to look for "where do server
secrets live?". Important caveat in the `.example` header: anyone with
filesystem access to `secure/` can read these secrets. This is by
design (server-side code needs them), but means deployments must keep
`secure/` outside the web-served path. Misconfigured deployments are
a deployment bug, not a QuickSite vulnerability.

**Alternatives considered**: Environment variables (rejected — adds
deployment friction for the common shared-hosting case; not all hosts
expose env vars to PHP). Encrypted-at-rest secrets (rejected — adds
key-management problem to what is already a "keep secure/ unreachable"
deployment story).

**Source**: `secure/admin/config/api-secrets.php.example`,
`secure/src/functions/serverFetch.php` (the consumer). Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md).

### `expose` vs `outputs` naming (locked 2026-06-04)

**Decision**: The resolver config key that maps response paths to
template variables is named `expose`, not `outputs`.

**Reasoning**: `expose` reads naturally as "template variables exposed
from this resolver." `outputs` is ambiguous — outputs of what, to
where? The verbs `expose` / `expose to` match how authors think about
the operation ("expose this dot-path as `$product` to the template").

**Alternatives considered**: `outputs`, `variables`, `vars`, `bindings`.
`outputs` rejected for ambiguity. `variables` rejected as too generic.
`bindings` collides with the existing client-side `responseBindings`
concept (different mechanism, different layer).

**Source**: `secure/src/functions/resolverHelpers.php`,
`secure/src/classes/DataResolver.php`. Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md).

### Resolver lifecycle position — AFTER auth gate, BEFORE template render (locked 2026-06-04)

**Decision**: The resolver fires in the request lifecycle AFTER the
auth gate (the yes/no decision: is this user allowed here?) but BEFORE
the page template renders.

**Reasoning**: Auth gate is a framework-level middleware (decides
whether the request gets to render at all). Resolver is user-
configurable data fetching (assumes the request is already allowed,
just needs data for the template). Reversing the order would risk
fetching for unauthorized users (waste of upstream calls + potential
data leak through error messages).

**Alternatives considered**: BEFORE auth gate (rejected — wastes API
calls for unauthorized requests; risks leaking data through verbose
errors). DURING template render (rejected — couples the resolver to
template-rendering internals; harder to test).

**Source**: `public/index.php` (the lifecycle). Behaviour:
[ARCHITECTURE.md §8.4](ARCHITECTURE.md) (lifecycle position),
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md).

### No type coercion of exposed values (locked 2026-06-04)

**Decision**: Resolver values are exposed to templates with whatever
type the API returned. JSON numbers stay numeric, strings stay
strings, booleans stay boolean. Template authors format values for
display as needed.

**Reasoning**: Consistent with client-side state-store rendering
(state stores don't coerce either). Coercion at the resolver level
would create surprise — "why is my `1` rendering as `'1'`?" —
without a corresponding gain (template authors who need formatting
already use existing rendering helpers).

**Alternatives considered**: Coerce all exposed values to string
(rejected — surprise behaviour; harder to do math on numeric values
later). Configurable coercion per expose key (rejected — config
complexity for a non-problem).

**Source**: `secure/src/classes/DataResolver.php` (the readDotPath
helper preserves types). Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md).

### Auth-gate vs auth-data — distinct concepts (locked 2026-06-04)

**Decision**: The "auth gate" (yes/no decision: is this user allowed
here?) is conceptually distinct from "auth data fetch" (who is this
user? populate `$user` for the template). They share a token/cookie
but do different things. Auth-gate is framework-hardwired middleware,
runs EARLIEST. Auth-data is just a regular resolver with `inputs:
['userId' => 'session:userId']` pattern.

**Reasoning**: The "is auth itself a resolver?" question came up
during the design round. The answer: half-yes. The DATA side IS a
regular resolver (a resolver that calls `@user-api/me` and exposes
`user.name`). The GATE side is NOT — it's part of the request
lifecycle, not user-configurable per route.

**Alternatives considered**: Treat auth-gate as a special resolver
type with framework-special behaviour (rejected — overloads the
resolver concept with a different lifecycle position + a different
failure mode, just to share the `session:` source kind).

**Source**: `public/index.php` (the gate + the resolver lifecycle).
Behaviour: [ARCHITECTURE.md §8.4](ARCHITECTURE.md).

### `data-state-show-empty` companion attribute (locked 2026-06-04)

**Decision**: New runtime data-* attribute `data-state-show-empty`,
the inverse of `data-state-show`. Shows the element when the
referenced state field is falsy / null / empty string / 0. Same
`valueShape: store-field-ref` as `data-state-show`, so the picker's
smart widget Just Works for it.

**Reasoning**: Needed for resolver `onMiss: render-empty` fallback
rendering — when the upstream returns null, the template needs a path
to show "no data" UI without conditional templating logic. Generally
useful beyond resolvers (any client-side store binding that wants
"no data" UI vs "loaded data" UI uses it alongside `data-state-show`).

**Alternatives considered**: Negate the existing `data-state-show`
with a `!` prefix (rejected — fragile string-parsing; collides with
the `:` separator convention). Author conditionally in the template
(rejected — defeats the data-attribute model's "no JS for common UI
patterns" promise).

**Source**: `secure/src/functions/qsDataAttributeCatalog.php` (catalog
entry), `public/scripts/qs.js` (the runtime handler). Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md) (in `onMiss` table) +
[ADMIN_PANEL.md §10](ADMIN_PANEL.md) (data-attribute reference).

---

## Multi-resolver routes (beta.8)

### Promoted from beta.9 to beta.8 (rationale, 2026-06-08)

**Decision**: Multi-resolver routes (a route having more than one
resolver firing in parallel) shipped in beta.8 instead of the
originally-planned beta.9.

**Reasoning**: The original beta.9 filing framed multi-resolver as a
"rare case" (most pages need one source). That framing didn't survive
realistic scrutiny: book pages (summary + page-content, same API,
different cache TTLs), product pages (product + reviews + related),
and similar rich-content pages need multi-source server-side
rendering. The escape hatches (aggregator endpoints, state stores)
are friction (aggregator) or miss the SEO-critical requirement (state
stores client-side post-load).

**Alternatives considered**: Keep deferred to beta.9 (rejected after
the realistic-case argument). Build only the storage layer in beta.8
and leave the runtime for beta.9 (rejected — partial ship would
confuse the contract).

**Source**: This decision itself; the implementation spans
`secure/src/classes/DataResolver.php`,
`secure/src/functions/serverFetch.php`, `secure/src/functions/resolverHelpers.php`,
`secure/management/command/setRouteResolver.php`,
`secure/admin/templates/pages/sitemap-resolver-list.php`. Behaviour:
[ADMIN_PANEL.md §9.7](ADMIN_PANEL.md),
[ARCHITECTURE.md §8.4](ARCHITECTURE.md).

### Storage shape — scalar OR array, backward-compat (locked 2026-06-08)

**Decision**: A route's sidecar entry in `route-resolvers.json` is
EITHER a single config object (single resolver) OR a sequential array
of config objects (multi resolver). `getResolversForRoute()`
normalises both to an array internally. Writes pick the shape per
length: scalar when length 1, array when length 2+.

**Reasoning**: Pre-7.5 sidecars are scalar by definition (only one
resolver per route was supported). Switching the on-disk shape to
always-array would force a migration step and lose the readability of
single-resolver routes ("look, one resolver, one JSON object — easy").
Backward-compat reads of both shapes + length-driven writes preserve
the readable single-resolver shape AND enable multi.

**Alternatives considered**: Always-array shape with migration
(rejected — adds a migration step + loses readability of the common
case). Always-scalar with a separate sidecar for multi (rejected —
splits the source of truth across two files).

**Source**: `secure/src/functions/resolverHelpers.php` (the
`_normalizeResolverEntry` helper + `setResolversForRoute` shape
selection logic).

### Array-index addressing for multi-resolver entries (locked 2026-06-08)

**Decision**: When a route has multiple resolvers, they're addressed
by their position in the array (0, 1, 2, ...). Reorder via drag-handle
in the list view, applied immediately. Reorder is cache-safe (cache
key is endpoint + inputs, route- and position-agnostic).

**Reasoning**: Position-based addressing covers everything the current
authoring + runtime model needs — reorder, edit by index, remove by
index. An alternative addressing scheme (stable `id` field per
resolver) would require a uniqueness check + a reserved-name policy +
per-resolver-id telemetry/error-handling + authoring UX for naming —
significant authoring burden for no immediate gain.

**Alternatives considered**: Required `id` per resolver (rejected —
authoring burden, naming-convention bikeshedding for no immediate
gain). Auto-derived ids like `r0` / `r1` (rejected as a storage
addressing scheme — that's what the template-side namespacing already
provides; explicit field for storage is a separate question).

**Source**: `secure/management/command/setRouteResolver.php` (the
`index` body-shape parameter for patch/append/remove operations).

### Execution — parallel only via `curl_multi_*` (locked 2026-06-08)

**Decision**: Multi-resolver routes fire all entries concurrently via
PHP's `curl_multi_*` family. Total latency = max(individual). No
sequential execution, no dependency DAG.

**Reasoning**: For the realistic multi-resolver use cases (rich
content pages pulling from N independent sources), parallel is the
huge win — a page that would take 300ms sequentially renders in 100ms
parallel. Sequential / DAG semantics add significant complexity
(declaration-order validation, cycle detection, partial-failure
semantics) for a narrow use case (server-side dependent fetches),
which the state-store init-from-store pattern (beta.7) already
covers for the most common cases.

**Alternatives considered**: Sequential (rejected — gives up the
latency win; partial-failure semantics get complex; the dependency-
chain use case is rare). Parallel with optional sequential subset
via `dependsOn` (rejected for v1 — additive change, ship parallel
first; revisit if real cases need ordering).

**Source**: `secure/src/functions/serverFetch.php`
(`serverFetchMulti`), `secure/src/classes/DataResolver.php`
(`resolveMany`). Behaviour:
[ARCHITECTURE.md §8.4](ARCHITECTURE.md).

### Var collision — error at save time + namespace-by-index fallback (locked 2026-06-08)

**Decision**: When two resolvers in the same route expose keys with
the same name in the flat namespace (e.g. both expose `title`), the
save is REJECTED with `reason: collision`. Authors disambiguate by
**renaming** (one expose `bookTitle`, another `chapterTitle`) OR by
using the **namespaced address** (`$r0['title']` / `$r1['title']`
in templates; `window.QS_RESOLVED_BY_INDEX.r0.title` in JS), which is
always available regardless of flat collisions.

**Reasoning**: Silent shadowing (last-write-wins) would create
debugging traps — the value the template reads depends on resolver
declaration order in non-obvious ways. Hard error at save time keeps
the runtime template substitution simple (the flat namespace is
collision-free by save-time validation) AND surfaces the conflict at
the moment the author can fix it. The namespaced address gives an
explicit escape hatch for deliberate same-name exposure (e.g. when an
author wants to keep `title` on both resolvers and address them
separately).

**Alternatives considered**: Last-write-wins (rejected — silent
shadowing). First-write-wins (same problem). Reject namespaced
addressing too (rejected — authors who genuinely need same-name
exposure across resolvers would be stuck).

**Source**: `secure/src/functions/resolverHelpers.php`
(`validateResolverConfigs` collision check),
`secure/src/classes/DataResolver.php` (the `exposedByIndex` output).
Behaviour: [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md) (collision rule).

### Failure handling — per-resolver `onMiss`, page-level short-circuit (locked 2026-06-08)

**Decision**: `onMiss` applies independently per resolver. Any
resolver failing WITHOUT `onMiss: 'render-empty'` short-circuits the
whole page (404/500 driven by the FIRST unrecovered failure).
Resolvers with `onMiss: 'render-empty'` expose null vars on failure
and the page continues rendering.

**Reasoning**: Authors can mark each resolver as critical (fail-loud)
or nice-to-have (render-empty). A product page can have:
`getProduct` fail-loud (no product = no page) + `getReviews`
render-empty (no reviews = empty section). Mixing is the feature.
Strictest-wins prevents a single critical failure from being silently
masked by a render-empty sibling.

**Alternatives considered**: Always short-circuit (rejected — loses
the `render-empty` value for secondary content). Always continue
(rejected — defeats the loud-failure signal for critical fetches).
Per-resolver `failureScope` field (rejected — `onMiss` already
expresses this).

**Source**: `secure/src/classes/DataResolver.php` (`resolveMany` —
`firstError` return), `public/index.php` (the 404/500 routing).
Behaviour: [ADMIN_PANEL.md §9.7](ADMIN_PANEL.md) (failure-mode table).

### Duplicate fetches share cache silently — no save-time warning (locked 2026-06-08)

**Decision**: Two resolvers on the same route hitting the same
endpoint + inputs share the cache entry silently. No UI warning, no
validation error. The combination is functionally benign — both
resolvers get the cached response on the second hit.

**Reasoning**: Cache key is endpoint + canonical inputs (route-
agnostic). Authors who deliberately set up two resolvers tracking the
same data with different `expose` mappings would be wrongly blocked
by a validation error; the silent-share path matches their intent
without surprise. The accidental-duplicate case (copy-paste mistake)
costs at most a small UX confusion, not a correctness issue.

**Alternatives considered**: Block the save with a `duplicate` error
(rejected — false positives for the deliberate-duplicate case).
Save-time validation that surfaces a warning without blocking
(rejected as v1 scope — adds a UI surface for an edge case).

**Source**: `secure/src/functions/resolverHelpers.php` (no duplicate
check),
`secure/src/functions/resolverCache.php` (the shared-cache mechanism).

### Command surface — `setRouteResolver` extension with optional `index` (locked 2026-06-08)

**Decision**: The existing `setRouteResolver` command grows six body
shapes for the multi-resolver operations:

| Body shape | Behaviour |
|---|---|
| `{route, resolver}` | Replace whole entry — scalar |
| `{route, resolver: [...]}` | Replace whole entry — array |
| `{route, resolver, index: N}` | Patch resolver at index N |
| `{route, resolver, index: <length>}` | Append at end |
| `{route, index: N}` (no `resolver`) | Remove resolver at index N |
| `{route}` (no `resolver`, no `index`) | Clear all resolvers |

No new commands (no `addRouteResolver` / `removeRouteResolver`).
Empty-body normalisation: `resolver: []` and `resolver: {}` are
treated as `null` (clear).

**Reasoning**: The single-command + body-shape-matrix approach
preserves the idempotent contract (set X means "X is now the state").
Splitting into separate add/remove/update commands would force callers
to read-then-write the whole array. The body-shape matrix is bigger,
but each shape is meaningful in itself, and the validator catches
ambiguous combinations (array + `index` is rejected as a `conflict`).

**Alternatives considered**: Separate commands (rejected — caller
burden, more surfaces to keep in sync). Force callers to always
read-modify-write the whole array (rejected — race-condition risk +
extra round-trips for simple "patch this one slot" cases).

**Source**: `secure/management/command/setRouteResolver.php`.
Behaviour: [COMMAND_API.md](COMMAND_API.md) — `setRouteResolver`
catalogue row + curl examples.

---

## Magic-link authentication (beta.8)

### Magic-link via code-exchange, not save-the-URL-token (locked 2026-05-22)

**Decision**: The URL value in a magic link (e.g. `abc123` in
`/auth/magic/abc123`) is a SHORT-LIVED single-use REFERENCE CODE. The
page POSTs the code to the auth API's exchange endpoint, which returns
the real session token. The code is NOT the session token.

**Reasoning**: Putting the actual session token in the URL leaks via
email-forwarding, browser history, corporate HTTPS proxy logs,
browser-extension URL inspection, and mail-client preview-fetching
(prefetch robots would "consume" the token before the user clicks).
The code-exchange pattern makes the URL value single-use AND short-
lived (~15 min typical), and the real token never appears in any URL
or log. The vulnerability window narrows from "forever" to "seconds."

**Alternatives considered**: Put the token in the URL directly
(rejected — see above). Send a magic-link to a server-side endpoint
that sets an HttpOnly cookie directly (rejected — requires the auth
API and QuickSite to be same-origin or to coordinate cookie config;
the code-exchange pattern works in any cross-origin setup).

**Source**: `public/scripts/qs.js` (`QS.exchangeMagicLink` verb),
`secure/src/functions/qsVerbCatalog.php` (verb registration).
Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) (Tier 3 magic-link
flow).

### `QS.exchangeMagicLink` 3-arg signature (locked 2026-06-04)

**Decision**: `QS.exchangeMagicLink(endpoint, key, returnTo?)` — 3
arguments, with `returnTo` optional. When omitted, falls back to the
`?return=` query string parameter, then to `/`.

**Reasoning**: Matches how the verb is typically chained in a page's
`onload`:
`{{call:exchangeMagicLink:@auth-api/exchange-magic,QS.routeParams.key}}`.
The endpoint and key are required (the verb has no useful default for
either). `returnTo` has a sensible cascade of fallbacks that covers
the common cases.

**Alternatives considered**: 2-arg with `returnTo` only via the query
string (rejected — explicit arg is clearer when reading the chain).
4-arg with separate success/failure redirects (rejected — over-
specified; the `?return=` cascade handles the common case).

**Source**: `public/scripts/qs.js`,
`secure/src/functions/qsVerbCatalog.php`. Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md).

### `magic-link-handler` is a Component, not a Complex Element (locked 2026-06-04)

**Decision**: The reusable "drop this on your magic-link page" piece
that wires the `onload` chain is a 5-line **Component** template (the
existing snippet/component system), not a Complex Element.

**Reasoning**: Components are simpler to author and customise (just
JSON, no builder + wizard pair). The piece is 5 lines — a Complex
Element's framework overhead (builder, wizard, catalogued kind) would
dwarf the actual content. Complex Element promotion deferred until
demand surfaces.

**Alternatives considered**: Complex Element (rejected for the
above). No template at all, user authors the chain by hand (rejected
— magic-link sub-pages are the kind of code authors should be able to
copy-paste, not derive).

**Source**: User-authored on demand; a template lives in the test
project. Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) (the chain pattern).

### Logout from server session — verb + recipe (locked 2026-06-04)

**Decision**: `QS.logoutServer(endpoint)` ships as a small verb (~10
LOC) for the common case. For unusual auth-API shapes, document a
recipe using `QS.fetch` directly.

**Reasoning**: Clearing localStorage tokens (Tier 1 `clearToken`)
doesn't clear server cookies — without a server-side logout, the
user is "logged in" on the server but "logged out" in the browser.
The verb covers the typical case; the recipe handles the rest.

**Alternatives considered**: Make `clearToken` also call the server
logout (rejected — conflates the two layers; some users intentionally
want client-only logout, e.g. for "switch account" UI). Server-only
logout via cookie expiry (rejected — adds time to logout effect; less
explicit).

**Source**: `public/scripts/qs.js`,
`secure/src/functions/qsVerbCatalog.php`. Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md).

### Token storage default — localStorage (locked 2026-06-04)

**Decision**: For magic-link's exchange response,
`saveToken('localStorage', 'authToken', 'token')` is the documented
primary pattern. HttpOnly-cookie storage works (via `auth.type:
'cookie'` on the auth API + `Set-Cookie` from the exchange endpoint),
but isn't the primary documented path.

**Reasoning**: Matches existing Tier 1 patterns. Authors who care
about XSS-safety can opt into the cookie pattern (which has its own
trade-offs — cross-origin config, `credentials: 'include'`
ergonomics).

**Alternatives considered**: Default to sessionStorage (rejected —
re-login friction across browser restarts; not the typical
expectation). Default to HttpOnly cookie (rejected — adds
configuration burden for the simplest case; cross-origin gets
complicated).

**Source**: Documented in [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md);
implementation reuses existing Tier 1 `saveToken` chain.

---

## Data-attribute catalog (late beta.7)

### Single-file catalog pattern — mirrors `qsVerbCatalog` (locked 2026-06-02)

**Decision**: All user-facing `data-*` runtime bindings (state, auth,
storage, template, form, complex) are declared in ONE file —
`secure/src/functions/qsDataAttributeCatalog.php` — and consumed by
every surface that needs to know about them: the
`listDataBindings` command, the in-editor picker, the future
renderer-side validation. Mirrors the `qsVerbCatalog.php` pattern.

**Reasoning**: Drop-a-row authoring (one entry, one place) beats
N-place-update-on-every-change. The pattern was already established
for QS.* verbs; reusing it for data-* bindings keeps the codebase's
single-source-of-truth promise consistent.

**Alternatives considered**: JSON catalog file (rejected — PHP's
expressivity is useful: catalog entries reference constants, use
short helpers; the existing dispatcher already loads PHP catalogs).
Per-attribute-family separate files (rejected — fragmentation; the
catalog is small enough to live in one file).

**Source**: `secure/src/functions/qsDataAttributeCatalog.php`.
Behaviour: [ADMIN_PANEL.md §10](ADMIN_PANEL.md).

### Reserved storage-key namespace (locked 2026-06-02)

**Decision**: Storage-key prefixes `qs_`, `qs-`, `quicksite_`, and
`quicksite-` are reserved for QuickSite's internal use. The picker
blocks them client-side; `addNode` and `editNode` reject them server-
side via a shared `reservedStorageKeys.php` helper.

**Reasoning**: QuickSite uses these prefixes internally (state
stores, auth flags, editor chrome). Allowing user content to overlap
would create silent collisions where author-set values get clobbered
by framework writes or vice versa. The double check (client + server)
is defense in depth — even if a client bypass exists, the server check
catches the malformed data.

**Alternatives considered**: Single-side check (rejected — client-only
fails on direct API callers; server-only loses the in-editor warning
that improves authoring UX). Stricter prefix (e.g. `__qs_`) (rejected
— `qs_` is short and recognisable; the longer form is busier).

**Source**: `secure/src/functions/reservedStorageKeys.php` (the
helper), `secure/management/command/addNode.php` /
`secure/management/command/editNode.php` (the consumers). Behaviour:
[ADMIN_PANEL.md §10](ADMIN_PANEL.md).

### Autocomplete is suggestion, not whitelist (locked 2026-06-02)

**Decision**: Typing `data-` in a key field opens the catalog
autocomplete dropdown. Authors can dismiss the dropdown and type a
non-catalog `data-foo-bar` for their own JS. Saving non-catalog
data-attributes is fine and silent.

**Reasoning**: The catalog is a HELP, not a CONSTRAINT. Authors
legitimately write custom data-* for their own JS (e.g. analytics
attributes, framework attributes for embedded libraries). Whitelisting
would block valid authoring without a security or correctness gain.

**Alternatives considered**: Strict whitelist (rejected — blocks
valid custom attributes; QuickSite is not a closed system). Whitelist
with override flag (rejected — adds friction for a non-rare case).

**Source**: `public/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js`
(the picker), `secure/management/command/addNode.php` /
`editNode.php` (no rejection of non-catalog data-*).

### Smart widgets per `valueShape` with free-text fallback (locked 2026-06-02)

**Decision**: When the user picks a catalog entry, the value field
swaps to a widget appropriate to the entry's `valueShape`
(`store-field-ref` → cascading selects, `enum` → `<select>`,
`storage-spec` → composer, etc.). A "raw" toggle is available to fall
back to plain text input.

**Reasoning**: Smart widgets dramatically improve discoverability AND
prevent typos for the common case. But authors with edge cases (a
selector the cascading picker can't generate, a dot-path the picker
doesn't anticipate) need to author manually. The fallback toggle keeps
them unblocked.

**Alternatives considered**: Widgets only, no fallback (rejected —
trap authors with edge cases). Always plain text (rejected — defeats
the discoverability win).

**Source**: `public/admin/assets/js/pages/preview/contextual-complex/data-attr-picker.js`.
Behaviour: [ADMIN_PANEL.md §10.2](ADMIN_PANEL.md) (admin click paths).

### Internal entries flagged, not omitted (locked 2026-06-02)

**Decision**: Editor-chrome data-* (e.g. `data-qs-textkey`,
`data-qs-node`, `data-qs-struct`) are present in the catalog but
flagged `internal: true`. The default `listDataBindings` response
filters them out; passing `includeInternal: true` returns them too.

**Reasoning**: Keeping internal entries IN the catalog (instead of a
separate file) means the future renderer-side validation can compare
against a single complete set of known attributes ("unknown data-qs-*
→ warn"). Flagging them lets the user-facing picker filter them out
cleanly.

**Alternatives considered**: Omit internal entries from the catalog
entirely (rejected — splits the source of truth + makes future
validation harder). Surface internal entries in the user picker too
(rejected — noise; authors don't need to see editor chrome in the
authoring UX).

**Source**: `secure/src/functions/qsDataAttributeCatalog.php`
(entries flagged `internal: true`),
`secure/management/command/listDataBindings.php` (default-filter
behaviour).

---

## Release shape (beta.9)

### Beta.9 concern ordering — OAuth first, translation manager last (locked 2026-06-11)

**Decision**: Beta.9 ships its four concerns in the order OAuth →
picker overhaul → stylesheet editor → translation manager, after a
small foundation sweep clears backlog items that every concern
benefits from.

**Reasoning**: Beta.8 closed with magic-link auth, server-side fetch,
and the secrets-file pattern all fresh — OAuth rides that momentum
and ships the headline user-value feature first. The translation
manager is the smallest concern and its only prep (extracting a
shared translation helper) happens in the foundation sweep, making it
the natural release-closing win. Accepted cost: OAuth's picker-facing
verbs ship with plain-text arguments and get a small retrofit pass
when the picker overhaul's endpoint-aware input type lands.

**Alternatives considered**: Picker overhaul first (avoids the OAuth
verb retrofit, but delays the headline feature and cools the warm
beta.8 auth context). Translation manager as an early warm-up
(visible editor value sooner, but pushes OAuth out by roughly two
weeks).

**Source**: Beta.9 kickoff design round (2026-06-11). Behaviour lands
across the beta.9 release; see the release notes at tag time.

### Stylesheet editor — full scope committed, no fallback gate (locked 2026-06-11, superseded 2026-06-22)

**Decision**: The in-editor stylesheet editor ships at full scope —
structured rules view plus editable raw view with two-way live sync
and live iframe preview. No fallback shape is pre-committed; the CSS
parser round-trip test still runs at concern start, but as a
diagnostic that scopes parser hardening, not as a gate deciding what
ships.

**Reasoning**: Full scope was already the confirmed direction
(2026-05-30); committing outright avoids designing and maintaining
two shapes, and converts parser risk from scope risk into schedule
risk — acceptable because both in-tree parsers already exist
(`secure/src/classes/CssParser.php` server-side,
`public/admin/assets/js/lib/css-refiner/css-parser.js` client-side)
and the early diagnostic reveals what needs hardening while there is
still room to fix it.

**Alternatives considered**: Spike-gated fallback (the drafted plan —
pre-commit to a lighter two-view shape without live sync, upgrade to
full scope on spike pass; rejected at kickoff: the lighter shape is
meaningfully less polished, and a pre-agreed gate invites shipping
it).

**Source**: `secure/src/classes/CssParser.php`,
`public/admin/assets/js/lib/css-refiner/css-parser.js` (the two
parsers the diagnostic exercises). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) stylesheet-editor section at ship
time.

### CSS Refiner stays separate from the stylesheet editor (locked 2026-06-11)

**Decision**: The CSS Refiner on the optimize page remains a separate
batch-cleanup tool (analyzers + diff + apply); the new stylesheet
editor is the authoring surface. The two cross-link rather than
merge. Parser reuse happens at the library layer: the Refiner's
client-side CSS parser is the candidate for the editor's live
raw-to-structured sync.

**Reasoning**: The two surfaces serve different intents — "find and
apply suggested cleanups in batch" versus "author styles in context."
Folding the Refiner's UI into the editor would grow the release's
riskiest concern; sunsetting it would delete working value (seven
analyzers and a diff view) for a tidier nav entry. Sharing the parser
library captures the real synergy without merging surfaces.

**Alternatives considered**: Fold into the editor (one CSS surface
for users; rejected — scope growth on the riskiest concern). Sunset
the Refiner (rejected — batch auto-refine and the diff view have no
replacement in the editor's scope). Defer the decision to concern
start (rejected — nothing material was going to change the
trade-off).

**Source**: `secure/admin/templates/pages/optimize.php` (Refiner
host), `public/admin/assets/js/lib/css-refiner/` (shared-candidate
parser + analyzers). Behaviour: [ADMIN_PANEL.md](ADMIN_PANEL.md).

### Project-to-workflow exporter ships in beta.9 (locked 2026-06-11, superseded 2026-07-19)

**Decision**: The "save the current project state as a replayable
workflow" tool ships in beta.9 as a self-contained bonus slice
alongside the polish work, rather than waiting for v1.0 preparation.

**Reasoning**: The tool reverse-reads project state through the
command surface, so it needed that surface to stop moving — beta.8
stabilised it, and beta.9's concerns (editor tooling + OAuth) don't
reshape project-state commands. The groundwork is already resolved
(bulk read-then-emit step generation, the two-mode asset approach, a
dedicated admin page), making it a bounded slice that unlocks
template sharing ahead of v1.0 and gets real usage feedback earlier.

**Alternatives considered**: v1.0 prep (rejected — no dependency
forces the wait; the feature synergises with the v1.0 template story
whether it ships now or later).

**Source**: Lands as an admin tool emitting workflow JSON under
`secure/admin/workflows/custom/`; implementation shape settles at its
design round. Behaviour: [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) at
ship time.

---

## OAuth (beta.9)

### OAuth client secrets — dedicated oauth-secrets.php (locked 2026-06-11)

**Decision**: OAuth provider credentials (client id + client secret
per provider) live in a dedicated
`secure/admin/config/oauth-secrets.php` (with a committed `.example`
twin), separate from beta.8's `api-secrets.php`.

**Reasoning**: Identity-provider credentials have a different
lifecycle from general API secrets — they are issued and rotated in
each provider's console, they gate user identity rather than data
access, and their blast radius on leak is account impersonation. A
dedicated file keeps the OAuth setup story self-contained (one file
to create, one example to copy) at the cost of a second secrets file
to gitignore, load, and document.

**Alternatives considered**: Reuse `api-secrets.php` (the groundwork
recommendation — one home for all server-side secrets; rejected at
kickoff in favour of the cleaner provider-credential boundary).

**Source**: `secure/admin/config/oauth-secrets.php(.example)` (lands
with the OAuth concern), consumed by the server-side OAuth handler.
Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth state parameter — server-generated 16-byte hex, single-use (locked 2026-06-11)

**Decision**: The OAuth `state` parameter is generated server-side as
16 random bytes hex-encoded (32 characters), stored server-side at
flow start, expires quickly, and is single-use: the callback handler
compares the returned value against the stored one and rejects
mismatches and replays.

**Reasoning**: This is the standard CSRF guard for the
authorization-code flow — the server proves the callback belongs to a
flow it started. A CSPRNG value with server-side comparison is the
boring, audit-friendly choice; no signing scheme to design or get
wrong.

**Alternatives considered**: Signed stateless state (encode and sign
the payload instead of storing it — rejected: adds a homegrown crypto
surface to a dependency-free codebase for a storage saving that does
not matter at this scale). Client-generated state (rejected — defeats
the purpose; the server must be the issuer).

**Source**: Server-side OAuth handler (lands with the OAuth concern
as `secure/src/classes/OAuthHandler.php`). Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth userinfo caching — user-configurable TTL (locked 2026-06-11)

**Decision**: The userinfo fetch in the OAuth flow is cacheable with
a TTL the site owner configures, following the resolver cache
precedent from beta.8 (a per-config `cacheTTL` in seconds, file-based
cache, zero or absent meaning no cache). No hardcoded cache duration.

**Reasoning**: Cache duration is a per-site, per-provider judgment —
rate limits, profile-freshness needs, and provider terms differ — so
the owner sets it rather than the platform guessing. The resolver
cache already taught users this exact knob (TTL field + clean-cache
command), so reusing the shape costs nothing to learn. Where the TTL
is configured (provider preset vs API auth config), its default
value, and the per-provider terms check before shipping defaults are
settled in the OAuth concern's design round.

**Alternatives considered**: Hardcoded 15-minute TTL (the groundwork
lean — rejected: exactly the kind of value the project's
"configurable vs convention" principle says the user should own). No
caching at all (rejected — the configurable knob subsumes it; owners
who want no cache leave the TTL unset).

**Source**: Server-side OAuth handler + cache helper (lands with the
OAuth concern). Resolver precedent:
`secure/src/functions/serverFetch.php` (cache eligibility),
`secure/src/functions/resolverHelpers.php` (`cacheTTL` validation).
Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth token custody — server-held + session cookie (BFF pattern) (locked 2026-06-14)

**Decision**: OAuth provider tokens (`access_token`, `refresh_token`)
are held server-side after the code exchange. The browser receives a
first-party `HttpOnly; Secure; SameSite=Lax` session cookie that maps
to a server-side session record; provider tokens never reach
JavaScript. This is the BFF (Backend-For-Frontend) pattern. Single
mode — no per-provider toggle to hand tokens to the browser.

**Reasoning**: The beta.10 security threat model (locked 2026-06-12 —
compromised admin / multi-author / SaaS preview-sharing) treats
stored XSS as the primary risk; defaulting OAuth to localStorage
tokens would directly create the credential-harvest surface beta.10
is meant to prevent. The IETF "OAuth 2.0 for Browser-Based Apps" BCP
draft explicitly recommends BFF and treats browser-held tokens as a
security anti-pattern. Every major OAuth provider (Google, Meta,
Amazon, GitHub, Apple) explicitly documents "confidential client +
server-side flow" as the canonical "Web server applications" pattern;
Apple Sign In additionally REQUIRES server-side ID-token verification.
The "login via your own API" pattern (Tier 1/2/3 magic-link)
continues to use the token-to-browser flow it was designed for —
that's the existing user-choice surface and stays available.

**Alternatives considered**: Token-to-browser for OAuth (Tier 1/2/3
`saveToken` pattern applied to provider tokens) — rejected: directly
contradicts the beta.10 threat model; XSS exfil of provider creds is
exactly the failure mode beta.10 prevents. Per-provider configurable
(cookie OR browser) — rejected: ~30-40% scope increase across the
OAuthHandler / preset / oauth-button slices, two code paths to
maintain forever, hands authors a security-implications choice they
often lack context to make well, creates install-to-install
inconsistency. Override path stays open: a per-provider
`tokenDelivery: 'cookie' | 'browser'` flag could be added later if a
real cross-origin-browser-API-call use case surfaces.

**Source**: Server-side OAuth handler (lands with the OAuth concern
as `secure/src/classes/OAuthHandler.php`). Session storage mechanism
settled at Q4 of the OAuth design round. CSRF mitigation via
`SameSite=Lax` cookie attr + server-issued state parameter (locked
2026-06-11). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at
ship time.

### OAuth callback hook — resolver kind `oauth-callback` (locked 2026-06-14)

**Decision**: User-authored callback routes (e.g. `/auth/oauth/:provider/callback`)
hand control to `OAuthHandler` via a route-resolver of kind `oauth-callback`,
attached through `setRouteResolver`. The resolver runs server-side before
render, performs state validation + code exchange + userinfo fetch + session
creation, and short-circuits with a redirect response. This introduces a new
resolver archetype — "resolvers with side effects" — alongside the beta.8
data resolvers.

**Reasoning**: Reuses the resolver-attachment UX authors already learned in
beta.8 testing (configure via the sitemap resolver list view). Keeps the
callback URL flexible — authors pick their own path shape
(`/auth/oauth/:provider/callback`, `/signin/google`, whatever) without a
path-convention dependency. Symmetric with the start-URL resolver kind, so the
OAuth-button wizard creates both routes together with the same authoring
pattern.

**Alternatives considered**: Route marker — new `oauthCallback: true` field
on the route record (rejected: schema growth for a one-off, doesn't extend
to similar flows). Path convention — any route matching the OAuth callback
pattern auto-invokes the handler (rejected: magic; breaks if author wants
a different URL shape; violates the "users own all routes" beta.8 lock).

**Source**: `secure/src/classes/OAuthHandler.php` (handler),
`secure/src/classes/DataResolver.php` (resolver-kind registration),
`secure/management/command/setRouteResolver.php` (authoring command).
Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth start URL — user-authored route + resolver kind `oauth-start` (locked 2026-06-14)

**Decision**: The OAuth flow's start URL is a user-authored route (e.g.
`/auth/oauth/:provider/start`) with a resolver of kind `oauth-start`
attached. The resolver generates the state token server-side, stores it,
builds the provider's authorize URL with all required parameters
(`client_id`, `redirect_uri`, `scope`, `state`, `code_challenge`), and
short-circuits with a 302 to the provider. The `oauth-button` Complex
Element wizard auto-creates BOTH start and callback routes when the author
picks a provider, so the 2-route ergonomics stay hidden behind a single
"Add Google Sign-In" action.

**Reasoning**: Symmetric with the callback resolver (same authoring
pattern, same archetype). Respects "users own all routes" (beta.8 lock).
The state must be server-issued (kickoff lock on state generation), which
rules out pure client-side URL building from the button. Server-resolver
hands control cleanly: one HTTP per click → state issued → 302 to provider.

**Alternatives considered**: Built-in start endpoint
(`/qs/oauth/:provider/start` hardcoded in core, not user-authored —
rejected: breaks "users own all routes" for a flow that doesn't need that
exception). Client-side URL build with a small state-fetch endpoint —
rejected: 2 round-trips per click for no win.

**Source**: `secure/src/classes/OAuthHandler.php`,
`secure/src/classes/complexElements/OAuthButton.php` (wizard that creates
both routes). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth state + session storage — PHP sessions behind a thin abstraction (locked 2026-06-14)

**Decision**: OAuth state (pre-auth, ~10-min single-use) and post-auth
session (cookie-id → user + provider-tokens mapping) both live in PHP
sessions, accessed through a thin storage interface (`storeState` /
`getState` / `storeSession` / `getSession`, ~30 LOC). `session_start()`
is lazy — called only inside `OAuthHandler`, so non-auth page renders pay
no cost. Session cookie attributes: `HttpOnly; Secure; SameSite=Lax`.

**Reasoning**: PHP sessions are designed exactly for this — built-in,
OS-managed storage with restricted permissions, session-cookie shape
configurable via `session_set_cookie_params()`. Storing provider tokens
in project-local files has a real security wrinkle (project folder often
in git, world-readable in dev, copied by backups), which the OS-managed
`session.save_path` avoids. The thin abstraction layer keeps swap-to-file-
storage a one-file change later if multi-language support or
project-local-with-encryption becomes important.

**Alternatives considered**: File transient in project folder — custom
JSON store with TTL + cleanup management (rejected: project-local storage
of provider tokens is a security risk per the beta.10 threat model; also
reinvents `session_handler_interface` for no win). File transient with
per-project encryption-at-rest — rejected for the initial slice: addresses
the security risk but adds ~100 LOC of encryption-helper code + key-
management surface, for marginal gain over PHP sessions today (offered as
future migration path if project-local becomes important).

**Source**: `secure/src/classes/OAuthHandler.php` (consumes the
abstraction), `secure/src/functions/oauthStateStore.php` (the abstraction,
PHP-session-backed implementation). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth PKCE — always-on for all clients (locked 2026-06-14)

**Decision**: Every OAuth flow includes PKCE (Proof Key for Code Exchange,
RFC 7636) regardless of client type. `OAuthHandler` generates a fresh
`code_verifier` per flow, computes
`code_challenge = base64url(SHA256(verifier))`, sends the challenge with
the authorize request, and sends the verifier with the token exchange.

**Reasoning**: PKCE was designed for public clients (SPAs/mobile) but
adds belt-and-braces protection for confidential clients (web app with
`client_secret`) too. If the OAuth `code` somehow leaks (reverse-proxy
logs, server access logs, accidental paste), an attacker still needs
BOTH the leaked code AND the `code_verifier` to redeem it. Negligible
cost — ~64 bytes of state alongside the existing OAuth state. Google
explicitly recommends PKCE for confidential clients. No major provider
rejects PKCE on confidential clients, so no compatibility risk.

**Alternatives considered**: Per-provider toggle (preset declares
`requirePkce: bool`) — rejected: YAGNI; no major provider rejects PKCE
on confidential clients. Off for confidential clients (rely on
`client_secret` alone) — rejected: strictly weaker, no upside.

**Source**: `secure/src/classes/OAuthHandler.php`. Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth provider presets — JSON, single file (locked 2026-06-14)

**Decision**: Provider presets live in
`secure/admin/config/oauth-presets.json` as one JSON document with all
providers (Google, Meta, Amazon, GitHub initially). Each preset declares
`authorize_url`, `token_url`, `userinfo_url`, default scope, and the
JSON path to `sub` / `email` in the userinfo response. Authors extend by
adding entries to the file (Apple, GitLab, Slack, etc.) without needing
PHP knowledge.

**Reasoning**: Aligns with the "Data shape — JSON for the author's
website data, with carve-out for user-extensible admin config" principle
(locked 2026-06-14 — separate entry below). Authors who add a provider
preset don't need PHP knowledge. Single file matches the existing
config-file convention (now JSON-shaped instead of PHP-array) and keeps
the 4-provider initial catalog readable. Per-provider files are a
possible refactor later if a community-presets feature ships and per-file
diffs become important.

**Alternatives considered**: PHP array file (`oauth-presets.php`) —
rejected: contradicts the data-shape principle; presets are
user-extensible admin config, and PHP-array forces PHP knowledge for
extension. Per-provider files
(`secure/admin/config/oauth-providers/<name>.json`) — rejected for the
initial slice: better for community-presets distribution but premature
now; mechanical refactor if it ever matters.

**Source**: `secure/admin/config/oauth-presets.json` (the file),
`secure/src/classes/OAuthHandler.php` (consumer). Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth userinfo cacheTTL — API auth config, default 0, skip TOS check at 0 (locked 2026-06-14)

**Decision**: The userinfo-fetch `cacheTTL` knob lives on the per-endpoint
API auth config (where beta.8's resolver `cacheTTL` already lives), not
on the provider preset. Ships with default `0` (no cache) — the standard
OAuth login flow does a single userinfo fetch per login, so caching is
moot for that path. Per-provider TOS check is deferred until a future
shipped default exceeds 0.

**Reasoning**: API auth config placement matches the beta.8 resolver
`cacheTTL` precedent (per-config, not per-provider) — authors find the
knob in the same place. Default 0 keeps the correctness story simple:
single fetch per login is well within every major provider's normal
usage, no TOS check needed. Authors who add re-fetch-userinfo flows
(e.g., a `/profile` endpoint that resyncs from Google on each request)
can crank the TTL to 300s+ at their discretion + responsibility; at that
point a TOS check on their target provider becomes the author's call.

**Alternatives considered**: Provider preset (per-provider default) —
rejected: doesn't match the resolver `cacheTTL` location authors already
know. Both (preset default + API override) — rejected: complexity for
marginal win. Default of 300s or 900s — rejected: the login flow doesn't
repeat-fetch, so a non-zero default would mostly be unused noise; defer
until a real repeat-fetch pattern emerges.

**Source**: `secure/src/classes/OAuthHandler.php` (reads the TTL from
the consumed API auth config), `secure/src/functions/serverFetch.php`
(cacheable rule already in place from beta.8). Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth handleStart shape — config-array signature, dispatcher-side placeholder substitution, auto-absolute redirect_uri (locked 2026-06-14)

**Decision**: `OAuthHandler::handleStart()` takes the full resolver
config array plus an optional `returnTo` (signature:
`handleStart(array $config, ?string $returnTo)`). `{:routeParam}`
placeholders in config string fields (`provider`, `callback_url`, and
any future fields) are substituted by the dispatcher in
`public/index.php` BEFORE the handler is invoked, using a small shared
helper `substituteRouteParams(string $str, array $routeParams): string`
promoted from the inline regex into
`secure/src/functions/routeHelpers.php`. The handler resolves the OAuth
`redirect_uri` from `$config['callback_url']` (already substituted)
with a default of `/auth/oauth/<provider>/callback`; if the resolved
path is relative, the handler makes it absolute against
`$_SERVER['HTTPS']` + `$_SERVER['HTTP_HOST']`.

**Reasoning**: Config-array signature keeps the handler API stable as
the remaining OAuth slices add more knobs (session TTL, success
redirect, scope override, etc.) without churning the callers. Dispatcher
substitution is forced anyway by the eager `loadPreset()` in the
constructor (which needs a resolved provider id), so promoting the
existing inline regex into a tiny shared helper lets future side-effect
resolver kinds (e.g., `oauth-logout`) reuse the same substitution
without copy-pasting the regex. Auto-absolute from `$_SERVER` is the
standard tutorial path — the registered `redirect_uris` at the provider
IS the security boundary, so a spoofed `HTTP_HOST` simply gets the
redirect rejected by the provider; reverse-proxy gotchas (host/scheme
stripping) get a docs note when ADMIN_PANEL §9.5 ships.

**Alternatives considered**: Explicit-params signature
(`handleStart(?string $callbackUrl, ?string $returnTo)`) — rejected:
forces a signature change for every new config field across the
remaining OAuth slices. Options-bag signature
(`handleStart(array $options)`) — rejected: overkill; loses
type-checking on the common case. Handler-side placeholder substitution
(`OAuthHandler` takes `$routeParams` in constructor) — rejected: leaks
routing into the auth class, and substitution must already happen
pre-construct for `provider`, so the dispatcher is the natural home.
Author-written absolute `redirect_uri` (full
`http://local.quicksite/...` URL in the config) — rejected: terrible UX
across dev/staging/prod environments. Project-level `site_url` config
field as the absolute-URL source — deferred: useful escape hatch for
reverse-proxy / multi-env cases, can land as a follow-up if real demand
surfaces.

**Source**: `secure/src/classes/OAuthHandler.php` (`handleStart()`
implementation), `secure/src/functions/routeHelpers.php`
(`substituteRouteParams()` helper), `public/index.php` (dispatcher
wiring). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth handleCallback shape — Basic client auth + dedicated cURL helper + sessionId cookie + 14d TTL + ?oauth_error redirects (locked 2026-06-15)

**Decision**: `OAuthHandler::handleCallback(array $config, array $query)`
implements the post-redirect half of the OAuth flow with five concrete
shape choices made together:

1. **Token-endpoint client auth**: `client_secret_basic` —
   `Authorization: Basic base64(client_id:client_secret)` header, body
   carries only the OAuth params (`grant_type`, `code`, `redirect_uri`,
   `code_verifier`). Not `client_secret_post`.
2. **HTTP client**: a small dedicated cURL wrapper (private static
   `httpRequest` on `OAuthHandler`, ~30 LOC) for the two back-channel
   calls (token exchange + userinfo). NOT a reuse of beta.8's
   `serverFetch.php`.
3. **Post-auth session cookie**: a separate `qs_oauth_user` cookie
   holding an opaque 32-byte sessionId (64 hex chars), HttpOnly +
   Secure + SameSite=Lax + 14-day Max-Age. Server-side mapping in
   `$_SESSION['oauth_session'][$sessionId]` via the scaffolded
   `storeOAuthSession`. NOT a single-cookie design that reuses
   `qs_oauth_session` (the PHP session cookie).
4. **Session TTL + redirect**: 14-day default session TTL (hardcoded
   `OAuthHandler::SESSION_TTL_SECONDS = 14 * 86400`; per-API-auth-
   config knob deferred). On success, redirect to the sanitised
   `returnTo` recovered from the state record, or `/` if absent.
5. **Provider-error / failure redirect shape**: on `error` in the
   callback query (user denied consent, etc.) OR on recoverable
   internal failure (token exchange 4xx/5xx, userinfo failure,
   missing required `sub`), redirect to `returnTo` (or `/`) with
   `?oauth_error=<code>` appended. Author owns the UX layer (their
   landing page reads the query param).

**Reasoning**:

1. **Basic auth** is RFC 6749 §2.3.1's preferred scheme ("the
   authorization server MUST support" Basic; `_post` is allowed but
   downgrade-only). All four shipped providers (Google, Meta, Amazon,
   GitHub) plus the test.oauth fixture accept Basic. Cleaner
   separation: credentials in header, request data in body.
2. **Dedicated cURL wrapper** wins on focus: OAuth's two back-channel
   calls have very specific shapes (form-urlencoded body for token,
   Bearer for userinfo, no follow-redirect, modest timeouts). Threading
   those through `serverFetch.php`'s config-driven auth flow (designed
   for api-secrets-driven REST consumption) would require adapter
   shims for marginal de-duplication benefit. ~30 LOC of dedicated
   helper is clearer.
3. **Separate sessionId cookie** preserves "swap to file-based
   session storage" as a one-file change later (the PHP session
   cookie would go away, sessionId cookie stays the durable auth).
   Matches the scaffolding shape — `storeOAuthSession($sessionId, …)`
   was already shaped for an externally-supplied id, indicating the
   2a-author had the same model in mind.
4. **14-day TTL** matches the test.oauth fixture's
   `OAUTH_REFRESH_TOKEN_TTL` and is a common SaaS-app default
   (Auth0 / Clerk / Supabase defaults sit in the 7-30 day range).
   `returnTo` reuse: the start handler already sanitised + stored
   the value, callback just consumes the safe version.
5. **`?oauth_error=` query** lets the author decide UX without
   coupling the handler to a specific landing page. Symmetric with
   how successful OAuth on most apps drops users back to the
   destination they were trying to reach.

**Alternatives considered**:

1. **`client_secret_post`** (secret in body alongside other params) —
   accepted by all providers; spec-permissible but downgrade. Rejected
   for the cleaner separation of Basic.
2. **Reuse `serverFetch.php`** for the back-channel calls — its auth
   handling is config-driven (api-secrets.php sources `apiKey` etc.);
   OAuth's per-flow client_secret + Basic header is a foreign shape.
   Adapting serverFetch costs more than the focused helper.
   **Inline curl_*() in handleCallback without a helper** — works but
   smears the same setup logic across two call sites.
3. **Single PHP-session cookie** (reuse `qs_oauth_session`, no
   separate sessionId) — simpler today, but couples the session
   surface to PHP's session subsystem; the planned migration to
   file-based storage (per locked Q4 "swap-to-file becomes a
   one-file change") becomes a two-surface change (must reinvent
   the session-cookie story).
4. **Session lifetime = `access_token` lifetime** (1 hour typical) —
   forces re-login every hour; unusable for the common "open the
   site, come back tomorrow" UX. Refresh tokens are the bridge,
   so session TTL ≥ refresh window makes more sense.
   **Session lifetime = `refresh_token` lifetime** — varies wildly
   by provider (GitHub: no expiry; Google: 6 months; Meta: 60
   days). 14d is the simplest fixed choice that works for all four.
5. **Redirect to `/` with no info** — silent UX failure mode.
   **Throw a 500 page** — server-side error display for a user-
   initiated denial isn't a server error. **Render an error page
   directly in the resolver** — couples the handler to a specific
   UX surface.

**Source**: `secure/src/classes/OAuthHandler.php` (`handleCallback`
implementation + `exchangeCodeForTokens` / `fetchUserInfo` private
methods + `httpRequest` / `dotPath` / `buildErrorRedirect` helpers),
`secure/src/functions/oauthStateStore.php` (post-auth session storage
already scaffolded — consumer landed here). Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time. PII surface
tracked in `NOTES/planning/DATA_FLOWS_INVENTORY.md` (running log).

### OAuth presets + secrets — per-project override over admin fallback (locked 2026-06-15)

**Decision**: OAuth presets and OAuth secrets each have a two-tier
lookup: **per-project file first, admin file as fallback**. The two
files at each tier:

- Presets: `secure/projects/<active>/data/oauth-presets.json` (project,
  JSON) → `secure/admin/config/oauth-presets.json` (admin catalogue,
  JSON).
- Secrets: `secure/projects/<active>/data/oauth-secrets.json` (project,
  JSON) → `secure/admin/config/oauth-secrets.php` (admin fallback, PHP).

Override is at **provider level** (full-entry replace, NOT field-level
merge). If a project file declares `google`, it owns google entirely
for that project; admin's google is ignored. Authors who want to tweak
one field copy the whole admin entry into their project file and edit
it.

Save-time validation accepts the UNION (provider exists in EITHER
file); runtime lookup resolves per-project-first.

**Reasoning**: Provider facts (URLs, scope defaults, userinfo dot-
paths) and credentials (client_id, client_secret) have different
sharing patterns. Provider facts are usually identical across every
project on an install — Google's authorize URL doesn't change per
project. Credentials are usually different — each project registers
its own OAuth app with each provider for security, analytics, and
blast-radius reasons. Two-tier with override accommodates both:

- Solo authors / dev installs: admin catalogue + admin secrets work
  out of the box.
- Multi-project installs: each project drops its own
  `data/oauth-secrets.json` with its own `client_id`/`client_secret`
  per provider. Admin catalogue still supplies the provider facts.
- Custom providers (corporate SSO, niche OAuth servers): per-project
  `oauth-presets.json` adds new keys without touching the engine
  catalogue.

Full-entry replace beats field-level merge because override resolution
is local + predictable: one file to read to know what a project sees.
Field-level merge means "what scope does google actually use here?"
requires reading two files + applying merge rules in your head. The
copy-and-edit overhead for the rare partial-override case (~5 lines of
JSON) is much less surprise-prone than silent magic.

Per-project files are JSON (both presets and secrets) — matches the
locked "data shape" principle (per-project data is JSON; lets authors
edit without PHP knowledge). Admin secrets keep their PHP shape because
they're admin config consumed only by the engine, and PHP allows env-
var interpolation patterns that real deployments use.

**Alternatives considered**:

- **Single-tier per-project only** — clean isolation, but forces
  duplication when every project on an install uses the same Google
  app (common for solo authors). Rejected as too rigid for the common
  case.
- **Single-tier admin only** (current pre-Slice-2.5 state) —
  simplest, but breaks the moment a multi-project install hits
  different OAuth apps per project. Rejected as the flag that
  triggered this slice.
- **Field-level deep merge** instead of provider-level replace — more
  ergonomic for "tweak one field" but the cognitive cost of "what is
  the effective config?" becomes a recurring foot-gun, especially
  when fields like `scope` are space-separated strings that don't
  merge cleanly (concat? union? replace?). Rejected for explicitness.
- **Move all OAuth config to per-project, no admin tier** — same
  rejection as single-tier per-project only.
- **Add an explicit "exclude admin provider X for this project"
  mechanism** — over-engineering for an edge case (block admin's
  google while not providing project's own). Authors who need this
  can override with an unused-but-present entry. Defer until a real
  use case surfaces.

**Source**: `secure/src/classes/OAuthHandler.php` (`loadPreset` +
`loadSecret` + `projectConfigPath` / `readJsonFile` /
`normaliseSecretEntry` helpers), `secure/src/functions/resolverHelpers.php`
(oauth-kind validator now reads union of both files),
`secure/admin/config/oauth-presets.json` (admin catalogue +
`_lookup_order` field on the `_schema` reference entry documenting
the pattern), `secure/admin/config/oauth-secrets.php.example`
(docblock LOOKUP ORDER section documenting the pattern + per-project
JSON shape), `.gitignore`
(`secure/projects/quicksite/data/oauth-secrets.json` explicit ignore
inside the un-ignored quicksite starter template). Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth logout shape — oauth-logout kind + optional-provider sanity check + always-revoke-if-preset-declares + identity-only helpers (locked 2026-06-15)

**Decision**: Logout is implemented as a third resolver kind
`oauth-logout` (symmetric with `oauth-start` / `oauth-callback`) with
four concrete shape choices made together:

1. **Trigger**: `oauth-logout` resolver kind. Author writes a route
   anywhere (e.g., `/auth/oauth/logout` or `/sign-out`), attaches
   `{kind: "oauth-logout"}`, hitting the URL invokes
   `OAuthHandler::handleLogout()`. No fixed engine endpoint (would
   break the "users own all routes" lock that drove the start +
   callback designs).
2. **Provider field on the resolver**: **optional**. When omitted
   (common case), the dispatcher auto-detects the provider from the
   `qs_oauth_user` cookie → session record. When present, used as a
   sanity check — mismatch is `error_log()`-warned but logout proceeds
   with the session's actual provider (cookie is the truth). One
   logout route works for sites with multi-provider login.
3. **Provider-side token revoke**: when the preset declares
   `revoke_url`, logout POSTs the access token to that endpoint with
   `client_secret_basic` auth (RFC 7009). Always-on; no per-resolver
   opt-out. Revoke failure is logged but doesn't block local logout
   (the user's intent of "log me out HERE" succeeds regardless).
   Initial preset `revoke_url` additions: Google
   (`oauth2.googleapis.com/revoke`), Amazon
   (`api.amazon.com/auth/o2/revoke`), test-oauth fixture
   (`test.oauth/revoke.php`). Skipped: Meta (uses non-RFC-7009 Graph
   API DELETE), GitHub (uses non-standard URL pattern
   `applications/{client_id}/token`) — local-only logout for those,
   tokens expire naturally.
4. **Template helpers**: `isOAuthLoggedIn(): bool` +
   `getOAuthUser(): ?array` in `oauthStateStore.php`.
   `getOAuthUser` returns **identity-only** fields (`provider`, `sub`,
   `email`, `name`); access_token / refresh_token / token_expires_at /
   scope are stripped before return. Templates that need to act on
   the user's behalf request the action via a server-side endpoint
   that uses the token directly — the token never leaves the server.

**Reasoning**:

1. **Resolver-kind trigger** is symmetric with start/callback; reuses
   the resolver-attachment UX authors already learned for the rest of
   the OAuth flow; respects the "users own all routes" lock that
   ruled out built-in endpoints during the callback design.
2. **Optional provider with sanity check** lets one logout route serve
   every provider on the site without forcing per-provider duplication.
   Sites with a single provider can still declare it for clarity in
   the admin sitemap UI; the sanity check warns on copy-paste mistakes
   (declared "google" but cookie says "meta") without breaking the
   logout — the user shouldn't be punished for a config error they
   didn't make.
3. **Always-revoke-if-preset-declares** is the security-conscious
   default: provider-side tokens that survive a "logout" past their
   natural expiry are a real concern in shared-device / abandoned-
   session scenarios. Making revoke opt-in (per-resolver field) would
   hand authors a security choice they often lack context to make
   well; making it always-on with preset-declared URLs lets the
   PRESET (provider-fact-level) carry the policy. Failure-doesn't-
   block-local-logout matters because the user-facing intent is "log
   me out from this site" — provider-side cleanup is a hygiene step,
   not a correctness requirement.
4. **Identity-only helper exposure** matches the BFF token-custody
   decision (locked 2026-06-14). Templates need to render
   personalisation ("Welcome, Sara"); they DON'T need raw tokens.
   Exposing tokens to templates would re-create the XSS exfil surface
   BFF was chosen to prevent. The scope field is technically
   identity-adjacent (which permissions did the user grant?), but
   that's deferred to a separate scope-aware-rendering concern —
   chip filed (task_5b20a582).

**Alternatives considered**:

1. **Fixed engine endpoint** (`/qs/oauth/logout` hardcoded in core,
   not user-authored) — rejected: breaks "users own all routes" for
   no win.
2. **Required provider field** (symmetric with start/callback for
   consistency) — rejected: forces per-provider logout duplication on
   sites with multi-provider login, with no benefit (the cookie
   already knows the provider). **Provider absent + no sanity check**
   (auto-detect only, no field even when supplied) — rejected: loses
   the documentation value of an explicit declaration in the sitemap
   UI for single-provider sites.
3. **Opt-in revoke** (per-resolver `revoke: true/false` field) —
   rejected: security choice authors often lack context for, lets
   "I just won't bother with revoke today" become the default. **Never
   revoke** (local-only logout, always) — rejected: leaves provider-
   side token alive until natural expiry, real concern in shared-
   device scenarios. **Block-on-revoke-failure** (5xx on revoke
   failure) — rejected: prioritises hygiene over the user's intent
   ("log me out HERE"), which always succeeds locally.
4. **Expose full session record** (including tokens) to templates —
   directly contradicts BFF token custody (locked 2026-06-14); the
   threat model says templates ARE the XSS surface, exposing tokens
   there is the exfil vector. **Expose scope alongside identity** —
   deferred to a separate slice/chip with concrete UX consumers, per
   "no helpers nobody calls" rule.

**Source**: `secure/src/classes/OAuthHandler.php` (`handleLogout` +
`revokeAtProvider` private method),
`secure/src/functions/oauthStateStore.php` (`isOAuthLoggedIn` +
`getOAuthUser` module-scoped helpers, identity-only return),
`secure/src/functions/resolverHelpers.php` (`oauth-logout` added to
`RESOLVER_ALLOWED_KINDS`; provider becomes optional on that kind;
data-resolver-field rejection hint updated),
`secure/admin/config/oauth-presets.json` (`revoke_url` field added to
google + amazon + test-oauth; `_schema._revoke_url` documents the
field as OPTIONAL + RFC 7009 standard), `public/index.php` (dispatcher
branches on logout to derive provider from session before
constructing handler; falls back to local-only logout if preset is
gone). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.
PII surface tracked in `NOTES/planning/DATA_FLOWS_INVENTORY.md`.

### OAuth-button Complex Element — sign-in only + listOAuthProviders + per-provider literal routes + branded a-link + skip-with-warn + standard insertion (locked 2026-06-15)

**Decision**: The `oauth-button` Complex Element + its
contextual-complex wizard make per-provider OAuth setup a single
drag-and-fill action. Six concrete shape choices made together:

1. **Scope of wizard automation**: **sign-in flow only**. The wizard
   creates the start + callback routes (`/auth/oauth/<provider>/start`
   and `/auth/oauth/<provider>/callback`), attaches the two resolvers
   (`oauth-start`, `oauth-callback`), and emits the visual button.
   Logout is NOT part of the wizard — author drags a separate
   `oauth-logout-button` Complex Element when ready (own micro-slice
   later), or just manually links to a `/logout` route they author
   themselves.
2. **Provider picker source**: **new `listOAuthProviders` command**
   returning the union of admin + per-project `oauth-presets.json`
   (filtering `_schema` / `_comment` ignore-markers). Mirrors
   `listApiEndpoints` which `FormScaffold` already uses. Future-
   proofs: adding a provider entry to the JSON automatically surfaces
   in the wizard.
3. **Route shape created**: **per-provider literal routes**
   (`/auth/oauth/google/start`, `/auth/oauth/github/start`, …) — not
   shared `:provider` param routes. Per-provider literal routes
   preserve the Slice 2.5 per-project preset override capability
   locally (each route's resolver can carry per-provider config) and
   render with the provider name visible in the sitemap.
4. **Button HTML shape**: **branded `<a>` link** —
   `<a class="qs-oauth-button qs-oauth-button--<provider>"
   href="/auth/oauth/<provider>/start"><textKey>Sign in with <X></textKey></a>`
   (with an optional `<span class="qs-oauth-button__icon"
   aria-hidden="true">` for CSS-driven branding). Real anchor, full-
   page navigation — matches the locked redirect-not-popup flow from
   Slice 2c/2d. Provider-specific CSS class lets designers theme per-
   provider without re-emitting structure. No default CSS shipped
   today; authors style via the existing style.css. CSS polish is a
   later concern (post-2026-06-15).
5. **Idempotency when provider routes already exist**: **skip-if-
   exists, with explicit UX warning**. Wizard attempts each addRoute /
   setRouteResolver; existing routes return `route.already_exists`
   which the wizard treats as success and continues to button
   emission. UX shows "Routes for `<provider>` already exist — this
   wizard run will reuse them and add a button on this page" so the
   author isn't surprised when a second Google sign-in button on a
   different page silently shares the setup of the first.
6. **Insertion mechanism**: **standard `addComplexElement` command**
   with `kind: 'oauth-button'` + `targetNodeId` + `position`. The
   wizard's final step calls addComplexElement just like every other
   Complex Element wizard. After the splice, the button subtree is
   indistinguishable from a hand-built one — same JSON shape, same
   renderer, editable with the regular visual-editor tools.

**Reasoning**:

1. **Sign-in only** keeps the first complex-multi-step wizard tight.
   Logout is one more route + resolver pair + visual element — easier
   to add as a sibling micro-slice once we see how sign-in lands.
   Avoids scope creep on the headline feature.
2. **listOAuthProviders command** mirrors a pattern that already
   works (`listApiEndpoints` for FormScaffold). Authors who add a
   custom provider preset see it surface in the picker without code
   changes. Cost: ~50 LOC PHP + 4 registration entries (per CLAUDE.md
   command checklist).
3. **Per-provider literal routes** make the sitemap human-readable
   (`/auth/oauth/google/start` reads better than
   `/auth/oauth/:provider/start` for a sitemap viewer). Locks in
   that each provider's setup is locally tracked — important for the
   per-project override pattern locked in Slice 2.5.
4. **Branded `<a>` link** matches the redirect-not-popup OAuth UX
   locked in earlier slices. CSS-driven branding is cheaper to ship
   AND easier to customise per-project than emitting per-provider
   SVGs or `<img>` references inline. Translatable label via textKey
   keeps multi-language sites first-class.
5. **Skip-with-warn** matches the "idempotent setup" instinct.
   Failing on conflict forces author to delete first ("worse UX —
   they want to add another button, not redo setup"); always
   overwriting destroys prior customisation; skipping silently is the
   one that surprises authors when their second button shares the
   first's setup. Warn + skip is the only option that respects what
   the author probably meant.
6. **Standard addComplexElement insertion** keeps the wizard a thin
   orchestration layer on top of established commands (`addRoute`,
   `setRouteResolver`, `addComplexElement`). Visual editor's
   "Add Element" UI surfaces oauth-button in the catalogue like every
   other Complex Element.

**Alternatives considered**:

1. **Sign-in + sign-out kit** — wizard handles both flows. Rejected
   for first-version scope; logout pattern slots in later as
   `oauth-logout-button` Complex Element. **Just the button (no
   route automation)** — rejected because it doesn't solve the
   "discoverability problem" the wizard is meant for; the author
   would still hand-craft routes + resolvers.
2. **Hardcode the 4 shipped providers in JS** — fastest to ship but
   breaks the moment someone adds a custom provider (which is the
   point of Slice 2.5's override pattern). Rejected. **Wizard reads
   JSON files directly** — JS can't read admin PHP-side files
   without an endpoint anyway; admin endpoint properly auth-gates.
   Rejected.
3. **Shared param routes** (`:provider`) — fewer routes overall, but
   ALL providers share one resolver config, defeating the per-
   project per-provider override capability that motivated Slice
   2.5. Rejected. **Author picks per-call** — extra friction on
   every wizard run for an architectural decision authors shouldn't
   re-make. Rejected.
4. **`<button>` with onclick JS** — needs JS-enabled clients,
   slower first paint, mismatches the redirect-not-popup decision.
   Rejected. **Provider-canonical pixel-perfect buttons** (Google's
   SDK button, etc.) — each provider has brand-guide specs; honouring
   all four = lots of per-provider conditional HTML+CSS. Defer to a
   polish pass; ship a "good enough" branded button now.
5. **Fail-if-exists** — bad UX for "add another button on this page"
   (the common case). Rejected. **Always overwrite** — destroys
   author customisation. Rejected.
6. **Dedicated insertion command** — doesn't match the established
   Complex Element pattern. Rejected.

**Source**: `secure/management/command/listOAuthProviders.php` (new
command — union of admin + per-project presets),
`secure/src/classes/complexElements/OAuthButton.php` (PHP builder —
pure, kind = `'oauth-button'`),
`public/admin/assets/js/pages/preview/contextual-complex/complex-oauth-button.js`
(JS wizard — picker + form + orchestration of addRoute×2 +
setRouteResolver×2 + addComplexElement),
`secure/management/routes.php` (command registration),
`secure/management/config/roles.php` + `.example` (command permission),
`secure/management/command/help.php` (command docs),
`secure/admin/functions/AdminHelper.php` `getCommandCategories()` (UI
list). Behaviour: [ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) at ship time.

### OAuth providers admin page — top-level Authentication nav + full CRUD + strict in-use delete block + first-chars credential reveal + pre-filled override-in-project (locked 2026-06-15)

**Decision**: `/admin/oauth-providers` ships as a top-level admin
page (sibling of /admin/apis / /admin/sitemap / /admin/styles) under
a new **Authentication** nav section. Full CRUD over OAuth provider
presets + credentials at both admin and per-project scope. Five
concrete shape choices made together:

1. **Nav placement**: new top-level "Authentication" section
   (matches the AdminHelper.php `getCommandCategories()`
   "authentication" category added in Slice 4). Future home for
   future auth UIs (magic-link config, role management, OAuth
   session inspection). NOT folded under Settings or under
   /admin/apis.
2. **Delete-when-in-use**: **strict block** with explicit usage list
   surfaced in the UI ("3 buttons / 2 routes use this provider —
   remove them before deleting"). Backend returns HTTP 409 with the
   per-site list. NOT a soft warn-and-allow that would silently
   break the consuming buttons / routes.
3. **Credentials masking**: default `••••••••` placeholder; explicit
   `[Show first chars]` click reveals the first 4-6 characters of the
   stored `client_secret` (e.g., `GOCSPX-Ab12···`). NOT full reveal
   (leak risk on screenshots/videos), NOT write-only (authors need a
   "is this the right credential?" sanity check; provider prefixes
   like Google's `GOCSPX-` and GitHub's `gho_` are the most useful
   disambiguator).
4. **Override-in-project flow**: clicking "Override in project" on
   an admin-scope row opens the edit modal pre-filled with the
   admin entry's values + scope-toggle set to "project". Author
   tweaks the fields they want different + saves; the project
   override is written; admin entry untouched. Same shape as edit;
   one fewer click than "duplicate then edit".
5. **Slice scope**: **full CRUD + override management** — add /
   edit / delete provider, set / update credentials, override
   admin entries per-project, remove override (fall back to admin
   entry). NOT a read-only viewer; NOT a partial slice that defers
   delete or override to a follow-up. The right vertical to ship
   end-to-end.

**Reasoning**:

1. **Top-level Authentication nav** matches the AdminHelper category
   already in place. Authentication is its own concern — folding it
   under Settings buries it; folding under API Registry conflates
   "what APIs do I call" with "how do my users sign in". The
   Authentication section will grow naturally (magic-link config,
   role management UI, future session inspection); top-level
   placement future-proofs that without nav churn.
2. **Strict delete block** matches the data integrity instinct that
   drove the resolver "all-same-kind" rejection (Slice 2b) and the
   /admin/apis "deleteApi" in-use check. Authors who DO want to
   remove a heavily-used provider can do so in two steps (remove the
   consumers, then delete) — explicit beats silent breakage. The
   server-side guard reads the per-provider usage count already
   surfaced in `listOAuthProviders`'s `setup` summary (extended in
   this slice to also count oauth-button references across pages).
3. **First-chars reveal** is the middle ground between "completely
   write-only" (annoying — authors can't sanity-check) and "full
   reveal on click" (screenshot/video leak risk). Reveals ~24 bits
   of entropy on a typical 32-char base62 secret; remaining ~166
   bits is still cryptographically strong. Provider prefixes like
   `GOCSPX-` / `gho_` / `amzn1.*` give authors a real
   disambiguation signal. UI default is masked; reveal is explicit
   click; nothing happens automatically.
4. **Pre-filled override modal** is just edit-with-different-scope.
   No new UI primitive. The "duplicate then edit" alternative is
   two-step UX for the same result.
5. **Full CRUD scope** because half-shipping (e.g. read + add but no
   edit/delete) ships an admin page that immediately surfaces "wait,
   how do I update X?" friction. The vertical is tight; finishing
   it costs ~2 days vs leaving a UX gap that authors will hit on
   day one.

**Alternatives considered**:

1. **Subpage under Settings** — buries the surface, harder to find,
   doesn't scale to future auth UIs. **Subpage under /admin/apis** —
   conflates outbound API calls with inbound user authentication;
   different mental model. Rejected.
2. **Allow delete with auto-cleanup** (cascade-remove consuming
   buttons / routes) — surprising behaviour for the author who just
   wanted to remove the provider; silently breaks consumers without
   their consent. **Allow delete with soft warn** — authors who skim
   the warning destroy their setup; explicit block forces eyes-on.
   Rejected.
3. **Write-only credentials** (strictest — once saved, never
   shown; only replace) — defensible for the highest-security
   environments but creates a "is this the right secret?" friction
   loop that authors solve by overwriting anyway (net negative on
   security). **Full reveal on click** — leaks the entire credential
   to anything that sees the screen; not worth it for a sanity-check
   feature. **Last-chars reveal** (GitHub/AWS pattern) — same
   entropy math as first-chars; first-chars wins because provider
   prefixes carry more signal than suffixes. Rejected.
4. **Two-step override** (duplicate → then edit) — extra click for
   no benefit. **Inline override** (toggle a per-field "override
   this field" checkbox on the admin entry's edit modal) — would
   re-introduce field-level merge complexity that Slice 2.5
   explicitly rejected ("override is at PROVIDER level, full-entry
   replace"). Rejected.
5. **Read + add only** (defer edit/delete) — leaves the surface
   half-done and immediately reveals the gap to first-day users.
   Rejected.

**Source**: `secure/management/command/addOAuthProvider.php`,
`secure/management/command/editOAuthProvider.php`,
`secure/management/command/deleteOAuthProvider.php` (new — full
CRUD), enhanced `listOAuthProviders.php` (adds `credentials_status`
+ `usage_count` per provider), `secure/admin/templates/pages/oauth-providers.php`
(new admin page template), `public/admin/assets/js/pages/oauth-providers.js`
(new — list + form modal + CRUD orchestration), nav entry added to
the admin sidebar / index. Behaviour:
[ADMIN_PANEL.md §9.5](ADMIN_PANEL.md) extended with an "Admin page
walkthrough" subsection at ship time. PII surface unchanged from
existing OAuth row in `NOTES/planning/DATA_FLOWS_INVENTORY.md`
(this slice is admin UX over the same data).

---

## Picker overhaul (beta.9)

### Picker categorisation — optional field + General + Uncategorized buckets (locked 2026-06-17)

**Decision**: The admin's verb / function picker (used in element
interactions, page events, action chains, complex element wizards)
now groups its `<option>`s by a `category` field declared on each
entry in `secure/src/functions/qsVerbCatalog.php`. The field is
**OPTIONAL** with a deliberate two-bucket fallback:

- `category: '<known-slug>'` — placed in the matching named group
  (e.g., `dom-toggle` → "DOM toggles"). Known slugs: `dom-toggle`,
  `form`, `fetch`, `auth`, `nav`, `state-store`, `focus`, `display`.
- `category: 'general'` — INTENTIONAL placement for cross-cutting
  utilities (like `toast`) that don't fit a specialised concern but
  the author deliberately chose to land in a general bucket. Visible
  as a "General" group.
- *No `category` field* — DEFENSIVE fallback into an "Uncategorized"
  group, RENDERED LAST so admins notice that the verb forgot to
  declare a category. Distinct from "General" — uncategorized signals
  oversight; general signals intent.

The picker renders groups in a locked order (most-authored first:
`dom-toggle, form, fetch, auth, nav, state-store, focus, display,
general, uncategorized`), with any unknown-but-declared categories
slotted alphabetically between `display` and `uncategorized`.

This replaces the previous vestigial `core` / `custom` / `other`
optgroup split — the `core` label dated to early beta.7 when the
plan was to support author-registered `custom` functions alongside;
that plan was explicitly dropped during beta.7, leaving `core` as
the lone bucket with zero discriminatory value across 25 (and
growing) verbs.

**Reasoning**:

- **Optional, not required** — making `category` required would
  force a "touch every entry" migration plus block any future verb
  addition pending a category decision. Optional + visible
  "Uncategorized" bucket is the same forcing function (admin sees
  the gap) without the friction.
- **Two buckets** — collapsing "intentional cross-cutting" and
  "forgot to declare" into one "Misc" group conflates two different
  signals. Authors can't tell whether they're looking at deliberate
  placement or a TODO. Splitting `general` (intent) from
  `uncategorized` (oversight) costs nothing visually and recovers
  the signal.
- **Locked render order** — alphabetical would scatter the
  most-authored groups (`dom-toggle`, `form`, `fetch`) below less-
  common ones (`auth`, `display`). Most-authored-first is the
  ergonomic default for repeated authoring. Author can build muscle
  memory for the top of the list.

**Alternatives considered**:

- **Required `category` field** — cleanest catalog contract, but
  forces a touch-everything migration AND blocks every future verb
  addition pending a category decision. Rejected for friction.
- **Hand-curated lookup in JS** (`CATEGORY_BY_VERB` map outside the
  catalog) — adds a second source of truth that has to stay in
  sync with the catalog every time a verb gets added or renamed.
  Rejected; the catalog IS the source of truth for everything else
  verb-related.
- **Single "Misc" fallback** — simpler, but loses the intent-vs-
  oversight signal. Rejected for the diagnostic value of separating
  the two.
- **Alphabetical group order** — neutral but doesn't optimise for
  the common case (DOM toggles are the most-authored verbs by far).
  Rejected.

**Source**: `secure/src/functions/qsVerbCatalog.php` (each entry's
optional `category` field + documented vocabulary in the file's
opening docblock),
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(two picker spots: `populateFunctionDropdown` for element
interactions + `_populateFnSelect` for page events; both use the
same `KNOWN_CATEGORY_ORDER` + `CATEGORY_LABELS` constants — kept
in sync manually for Slice 1; extraction to a shared helper deferred
to Slice 2 if duplication grows).
`secure/management/command/listJsFunctions.php` still decorates
each entry with `type: 'core'` (back-compat for any external consumer
of the API; the picker just doesn't use the field anymore).
Behaviour: [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) at ship time of
the full picker overhaul (Slice 7).

### Picker search-as-you-type — input above select + name+description match (superseded 2026-06-17)

> **Superseded** by "Picker search-as-you-type — combobox wrapper
> with inline search (locked 2026-06-17, supersedes earlier same-day
> input-above-select entry)" below. Kept verbatim for the historical
> record. The "input above the select" shape shipped briefly then was
> reverted within the same session after user feedback that it didn't
> match the established `QSPropertySelector` / tag-picker UX pattern
> already present in the codebase.

**Decision**: An `<input type="search">` is injected above each
function dropdown the first time the picker renders. Typing into it
filters the dropdown's verbs in real-time, matching against the
verb's **name** (primary) AND **description** (secondary) via
case-insensitive substring. A verb matches if EITHER field contains
the query. Groups with zero matches are hidden; empty query restores
the full list. The native `<select>`'s built-in keyboard navigation
handles arrow-key movement through the visible filtered options for
free — no custom combobox needed.

The input persists across re-populates triggered by event-dropdown
changes (the search is the user's intent; the event filter is the
context — orthogonal). Re-population is the implementation: on every
search-input event, the populate routine re-runs and rebuilds the
dropdown's content excluding non-matching verbs (instead of trying
to hide individual `<option>` elements via CSS, which behaves
inconsistently across browsers for keyboard-nav purposes).

**Reasoning**:

- **Both name + description search** — limiting to name only would
  miss the discovery use case ("I want to fetch something" finds
  verbs whose description mentions fetching even when the name
  doesn't). Name + description with EITHER-match is the lowest-cost
  way to capture both.
- **Filter-rebuild over option-hiding** — `option { display: none }`
  hides the visual element but native `<select>` keyboard nav may
  still cycle through hidden options (Chrome ≠ Firefox ≠ Safari).
  Rebuilding the dropdown's content guarantees that arrow keys
  traverse only matches.
- **Input persists across event changes** — clearing it on every
  event change would force the user to re-type after every event
  switch. The search is a separate axis of filtering.
- **No custom combobox** — a fully custom searchable-select would
  add accessibility surface (focus traps, ARIA roles, escape
  handling, click-outside-to-close) for marginal benefit over the
  native select + sibling search input. Defer if a real UX gap
  surfaces.

**Alternatives considered**:

- **Custom combobox** (input + popover + filtered list) — full
  control, more polish potential, but doubles the implementation
  cost + reinvents accessibility. Rejected for first-pass scope.
- **Option-hiding via CSS** — simpler code but cross-browser nav
  inconsistency. Rejected.
- **Search input INSIDE the optgroup as the first option** — clever
  but breaks the native select's option-as-value contract. Rejected.
- **Name-only match** — simpler but misses the discovery use case.
  Rejected; description-match is cheap.
- **In-group ranking by match source** (name-matches first within
  each group) — defensible, but adds reorder churn for marginal
  benefit; the category grouping already does the heavy lifting
  for scannability. Deferred — surface a chip if it matters.

**Source**:
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`_ensureFnSearchInput`, `_getFnSearchValue`, `_filterFnsBySearch`
helpers near `_filterFunctionsByEvent`; both `populateFunctionDropdown`
and `_populateFnSelect` call the helpers). Empty-result placeholder
text adapts to the cause (search miss vs event-filter miss).
Behaviour: [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) at ship time of
the full picker overhaul (Slice 7).

### Picker search-as-you-type — combobox wrapper with inline search (locked 2026-06-17, supersedes earlier same-day input-above-select entry)

**Decision**: The verb picker swaps its native `<select>` for a
custom combobox UI rendered by the new reusable
**`QSSearchableSelect`** class
(`public/admin/assets/js/core/searchable-select.js`). The native
`<select>` stays in the DOM (visually hidden via `display: none`) as
the data store — all existing code that reads `.value`, listens to
`change` events, or inspects `<option>.dataset` keeps working
unchanged. The wrapper:

- Renders a trigger button showing the current value + chevron
- On open, mounts a dropdown (position: fixed, escapes overflow) with:
  - **Inline search input** at the top (auto-focused)
  - **Grouped item list** below, reading `<optgroup>` labels + filtered
    `<option>` items from the native select
- Search filters case-insensitively against `<option>` value + textContent
  + `data-description`; empty groups hidden; empty query shows all
- Keyboard nav: ArrowUp/Down move focus, Enter selects, Escape closes
- On select: sets `nativeSelect.value` + dispatches `change` so external
  listeners fire as if the user had used the native select directly
- Exposes `refresh()` so external code that rebuilds the select's
  options can sync the wrapper's display

Wired in `preview-js-interactions.js` to wrap **both** picker spots
(`jsFormFunction` for element interactions; `peFormFunction` for page
events) at init time, with `refresh()` called after every populate.

**Reasoning**:

- **Matches the established codebase pattern**: `QSPropertySelector`
  (CSS property picker) and the visual editor's tag picker both use
  trigger + dropdown + inline search. Two different patterns in the
  same admin UI was the wrong call. Consistency wins.
- **Wrap rather than replace**: keeping the native `<select>` as the
  data store means ~20 existing references (`.value`, `change`
  listeners, `selectedOption.dataset.args`/`description`/`example`
  readers) keep working unchanged. The pivot becomes a pure UI
  change — zero risk to the existing form-submit + value-handling
  flow.
- **Reusable component, not one-off**: the planning doc already
  hinted at extracting `SearchableSelect` for future surfaces. A2
  Slice 5's `route` inputType picker uses this same component;
  potential future surfaces (any admin select that benefits from
  search) get it for free.

**Alternatives considered**:

- **"Input above select" approach** (the earlier same-day entry,
  marked superseded above) — shipped briefly, then reverted after
  user feedback. The input-above shape doesn't match the trigger +
  dropdown shape that the rest of the admin uses for searchable
  selects. Inconsistency was the deal-breaker.
- **Reuse `QSPropertySelector` directly** — its `_renderList` is
  hardcoded to `CSS_PROPERTY_CATEGORIES` + property-specific item
  shape (type label, exclude-properties, custom-property fallback).
  Generalising it OR carrying CSS-specific code into the verb picker
  were both worse than a fresh sibling class. Rejected.
- **Match the tag-picker pattern** (PHP-template-driven, JS handler
  reads element IDs) — tight coupling to template markup, not
  extractable. The user referenced the tag picker as their UX
  *reference*, not its implementation pattern. Rejected for the
  reusability concern.
- **Replace the native `<select>` entirely** — would require
  refactoring 20+ existing references to read from a wrapper-only
  API. Big-blast-radius change for no UX benefit beyond what the
  wrap-and-hide approach already provides. Rejected.

**Source**:
`public/admin/assets/js/core/searchable-select.js` (new — the
`QSSearchableSelect` class, ~340 LOC, load-guarded so double-include
is a no-op),
`public/admin/assets/css/searchable-select.css` (new — admin-themed
combobox styles using `--admin-*` variables),
`secure/admin/templates/layout.php` (loads the JS + CSS),
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`jsFnPicker` + `peFnPicker` module-scope wrappers; instantiated at
init alongside the native select references; `refresh()` called at
the tail of `populateFunctionDropdown` and `_populateFnSelect`).
Behaviour: [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) at ship time of
the full picker overhaul (Slice 7).

### Picker search persistence — per-picker localStorage key, restored on open (superseded 2026-06-17, reverted)

> **Superseded** by "Picker search persistence — NOT shipped; combobox
> resets on every open" below. The persistence shipped briefly within
> this same session then was reverted after user testing surfaced the
> UX problems (ghost-filter confusion on reopen + cross-reload, no
> common pattern in similar tools, hypothetical Q2 "yes" did not
> survive contact with the actual experience). Preserved verbatim for
> the historical record per the append-only discipline.

**Decision**: `QSSearchableSelect` accepts an optional `persistKey`
option. When set, the search input's current value is written to
`localStorage[persistKey]` on every keystroke, and read back into the
input the next time the dropdown opens. Empty values REMOVE the key
(deliberate clear leaves no stale entry). Storage errors (private-
mode browsers, quota exceeded, disabled storage) are silently
swallowed — persistence is best-effort, never blocks the picker.

Keys live in the central `QuickSiteStorageKeys` registry
(`public/admin/assets/js/core/storage-keys.js`). Slice 3 adds two
keys, one per existing picker:

- `pickerSearchJsFn` → `quicksite_picker_search_js_fn` (element
  interactions form)
- `pickerSearchPeFn` → `quicksite_picker_search_pe_fn` (page events
  form)

Independent keys so the two pickers track their searches separately
(authoring a page event for `onload` rarely shares context with
authoring an element click handler).

**Reasoning**:

- **Optional, not on-by-default** — `QSSearchableSelect` is a generic
  component. Most callers (future surfaces like the route picker)
  may not want persistence. Opt-in via `persistKey` keeps the
  default zero-cost.
- **Per-picker keys** — collapsing the two pickers into one shared
  key would mean a search query typed for an element interaction
  pre-applies to the next page event opened, which is a context
  mismatch the authoring flow doesn't benefit from.
- **localStorage over sessionStorage** — the typical authoring
  pattern is "I was filtering by `magic` yesterday, open the picker
  today, still on `magic`." sessionStorage would lose that on tab
  close.
- **Per-keystroke write, not debounced** — localStorage writes for
  short strings are cheap (sub-millisecond). Debouncing adds
  complexity to recover negligible perf.

**Alternatives considered**:

- **Persist also the "last selected verb"** — already the form's
  job (when editing an existing interaction, the value is loaded
  from saved structure). The picker doesn't need to remember
  selections — only the *filter state* that's NOT part of saved
  structure.
- **Persist whether dropdown was open at last unmount** — minor UX
  win, but auto-opening on page load is usually surprising. Rejected.
- **Persist focused-item index** — fragile across catalog changes
  (verb removed → stale index). Skip.
- **Shared key across all picker types** — see "Per-picker keys"
  reasoning above.

**Source**:
`public/admin/assets/js/core/storage-keys.js` (two new keys),
`public/admin/assets/js/core/searchable-select.js` (`persistKey`
option + `_readPersistedQuery` / `_writePersistedQuery` private
helpers; restore in `open()`, write in the search input's
`input` event),
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(both `jsFnPicker` + `peFnPicker` instantiations pass their
respective `QuickSiteStorageKeys` value as `persistKey`).
Behaviour: [ADMIN_PANEL.md §6](ADMIN_PANEL.md) storage key registry
(two new entries) + [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) picker
section at full-overhaul ship time.

### Picker search persistence — NOT shipped; combobox resets on every open (locked 2026-06-17, supersedes earlier same-day persist-via-localStorage entry)

**Decision**: The verb picker (and the reusable
`QSSearchableSelect` component in general) does **NOT** persist the
search query. Each open starts with a clean empty search input + full
filtered-by-event list. Closing the dropdown discards the query;
reopening starts fresh.

The earlier same-day Slice 3 entry that shipped per-picker
`localStorage`-backed persistence was reverted after user verification
testing — the UX in practice contradicted the hypothetical answer
given at kickoff.

**Reasoning** (why persistence was wrong here):

- **No common pattern matches it** — Google search, GitHub command
  palette, Spotlight, VS Code Quick Open, browser address-bar autocomplete:
  none persist a prior query. The user's mental model is "fresh
  invocation = fresh input."
- **Stale filter is worse than no filter** — a filter the user set
  hours or days ago restores invisibly on next open. They see fewer
  options than expected, may not notice the search box still holds
  text, end up thinking the picker is broken.
- **Authoring sessions don't repeat the same filter** — once an
  author selects a verb for one interaction, the next interaction
  is rarely on the SAME concept (different element, different
  event, different concern). The "I'm wiring up all auth verbs
  today" use case assumed at kickoff turns out to be uncommon.
- **Page-reload restore was especially confusing** — opening a
  fresh editor session and finding the picker pre-filtered with
  yesterday's query reads as a bug, not a feature.

**Alternatives considered** (and now also rejected):

- **`localStorage` persistence across reloads** (the entry above —
  the original Slice 3 shipment). Reverted same day.
- **`sessionStorage`-only** (per-tab session) — milder version, but
  same problem: close+reopen the picker still shows stale filter.
- **Persist for current open dropdown only** — equivalent to "no
  persistence" since the dropdown stays open during a single
  filter-and-select flow anyway. Adds an option for no benefit.
- **Persist + show a visible "stale filter" badge** — over-engineered
  recovery for a feature that shouldn't exist.

**Source**: `public/admin/assets/js/core/searchable-select.js` (the
`persistKey` option + `_readPersistedQuery` + `_writePersistedQuery`
helpers all removed; no localStorage interaction remains).
`public/admin/assets/js/core/storage-keys.js` (the two `pickerSearch*`
entries removed). `public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`persistKey` option dropped from both picker instantiations).
`docs/ADMIN_PANEL.md` §6 (the two new rows removed). Behaviour
documented at the picker section in [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md)
at full-overhaul ship time.

### Picker inputType `apiEndpoint` — single combined combobox (locked 2026-06-17)

**Decision**: The catalog gains two new inputType values for registry-
backed args:

- `inputType: 'apiEndpoint'` — for verb args that take an `@apiId/endpointId`
  ref. Renders ONE `QSSearchableSelect` whose options are the FLAT
  cross-product of every registered API × every endpoint, each with
  value `@api/ep` and a secondary line carrying `METHOD path — description`.
  Used by `exchangeMagicLink.endpoint`, `requestMagicLink.endpoint`,
  `logoutServer.endpoint`.
- `inputType: 'api'` — for verb args that take an `@apiId` alone (no
  endpoint segment). Renders ONE `QSSearchableSelect` with a deduplicated
  list of registered APIs, secondary line carrying endpoint count +
  sample names. Used by `refresh.apiRef` — the lone catalog arg that
  takes just an API.

Both pickers reuse the existing `availableApiEndpoints` global (already
populated by the fetch-mode wizard + page-event wizard via the
`/management/listApiEndpoints` round-trip). Search matches against
value + textContent + data-description per the wrapper's standard rules,
so typing "logout" returns logout endpoints across every API, "auth"
returns every endpoint under `@auth-api`.

**Reasoning**:

- **One combined picker, not two cascading selects** — the existing
  fetch-mode and page-event wizards use a two-`<select>` cascade
  (`jsFormApi` → `jsFormEndpoint`). For a single-arg auth verb like
  `logoutServer.endpoint`, that's two widgets of chrome for one stored
  string. The combined approach also lets the search box span every
  endpoint at once — "logout" surfaces hits across APIs the author
  might not even remember declaring, which matches the A2 trajectory
  of "search > navigate".
- **Why a separate `api` inputType instead of overloading `apiEndpoint`** —
  `refresh.apiRef` is the ONLY catalog arg today that takes `@api` alone.
  Mirroring the existing `store` precedent (one inputType per registry
  shape) keeps the catalog readable: a reader sees `'api'` and knows
  the stored value is `@apiId`; `'apiEndpoint'` and they know it's
  `@apiId/endpointId`. Overloading one type with an `optionalEndpoint`
  flag would force every downstream picker site to reason about the
  flag instead of two distinct types.
- **`fetch.target` deliberately left untouched** — the fetch wizard
  already has a dedicated `registry vs direct URL` radio + per-mode
  fields (method dropdown, body row visibility, path params, auth hint).
  The catalog-driven picker has never been responsible for `fetch.target`
  and converting that wizard is a separate slice, not a freebie. The
  existing two-`<select>` cascade stays for now.
- **Pre-fetch `availableApiEndpoints` eagerly on Add + Edit** — the
  pickers populate options synchronously in `_createArgRow`. The Add
  flow already pre-fetched (line 1012); Edit's function-mode branch
  didn't until this slice (the API-mode branch did at line 865). One
  unconditional `await fetchApiEndpoints()` before the function-mode
  block matches the page-event edit flow's existing pattern (line 3218)
  and keeps the pickers working from the very first arg-row render.
- **`<select>` `change` dispatch on pre-fill** — the edit pre-fill
  loop at setTimeout(100) dispatched `'input'` for every form input,
  but `<select>` (and any future widget wrapping a select) listens for
  `'change'`, not `'input'`. The fix dispatches `'change'` for SELECT
  elements specifically so `QSSearchableSelect`'s trigger-label sync
  fires during edit pre-fill.

**Alternatives considered**:

- **Two cascading `QSSearchableSelects`** (API picker → endpoint picker,
  matching the existing fetch wizard pattern) — pro: same mental model
  as the fetch wizard, pro: API gating prevents wrong-API picks, con:
  two widgets per arg, con: long flat list is fine in practice (auth
  APIs are small). Rejected for combined picker's simpler chrome.
- **Overload `apiEndpoint` with `optionalEndpoint: true` for refresh** —
  saves one inputType slot, costs a flag every downstream picker site
  has to branch on. Rejected for the `store`-precedent symmetry of one
  type per registry shape.
- **Leave `refresh.apiRef` as plain string** — defers the
  inconsistency but the user typing `@auth-api` manually is exactly
  the typo-class A2 is supposed to kill. Rejected as half-measure.
- **Two-line option label** (value + rich description as separate text
  nodes in the option's textContent) — the `QSSearchableSelect`'s
  trigger label is set from option.textContent, so a rich label would
  show "@auth-api/logout — POST /auth/logout — Server-side logout…"
  in the trigger after selection. That's too long for the form column.
  Rejected; the secondary line lives in `data-description` (dropdown
  only, also searchable) instead.

**Source**: `secure/src/functions/qsVerbCatalog.php` (4 catalog args
gained `'inputType' => 'apiEndpoint' | 'api'`).
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`_renderApiEndpointArgSelect` + `_populateApiEndpointOptions` +
`_renderApiArgSelect` + `_populateApiOptions` + `_mountApiPickerWrap`
helpers; dispatch added to `_createArgRow` after the `store` branch;
`await fetchApiEndpoints()` added to `editInteraction` before the
mode-detection block; `'change'` dispatched for `<select>` elements
in both edit pre-fill loops — `editInteraction` setTimeout + the
`editPageEventEntry` function-mode setTimeout). The `refresh.apiRef`
arg is the only `'api'` user today; `apiEndpoint` covers the three
auth verbs. `fetch.target` deliberately stays string-typed (handled
by the existing dedicated fetch wizard, not the catalog-driven picker).
Behaviour documented in [ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) at
full-overhaul ship time (Slice 7).

### Picker inputType `route` — strict picker + per-arg allowExternal hatch (locked 2026-06-17)

**Decision**: A new catalog inputType `route` and a per-arg flag
`allowExternal: bool` lock the previously-loose "URL field" args into
a search-driven picker:

- `inputType: 'route'` — renders a `QSSearchableSelect` whose options
  are the project's registered routes (loaded from the existing
  `getRoutes` command's `flat_routes`, prepended with `/`) plus a `/`
  home shortcut. Used by 3 catalog args today: `redirect.url`,
  `exchangeMagicLink.returnTo`, `requestMagicLink.returnTo`.
- `allowExternal: true` on an arg — adds a `Custom URL…` sentinel
  option to the top of the dropdown. Picking it SWAPS the row from
  the QSSearchableSelect to a free-text `<input type="text">` plus a
  small "←" button that returns to picker mode. The native `<select>`
  and the text input share the same `dataset.paramIndex`; the
  param-collector reads whichever currently carries
  `.preview-contextual-js-form-input` (the swap helpers move the
  class). Default is `false` — strict picker, no free-text escape.
- `allowExternal: false` (the default) — no sentinel, no escape.
  Authors can only pick registered routes. Mirrors the server-side
  `OAuthHandler::sanitiseReturnTo` strictness for post-auth returns.

Per-arg defaults:
- `redirect.url`             → `allowExternal: true` — historic
  behaviour accepts any URL incl. external; locking would break
  existing sites doing `{{call:redirect:https://...}}`.
- `exchangeMagicLink.returnTo` → `allowExternal: false` — matches the
  OAuth pattern (open-redirect class is real).
- `requestMagicLink.returnTo` → `allowExternal: false` — typically a
  "check your email" internal page.

Edit pre-fill auto-swaps to custom mode when the saved value is
external (regex: doesn't start with single `/`). Internal-but-
unknown values fall through to the existing "(legacy)" option
injection in the picker — same pattern as Slice 4.

**Reasoning**:

- **Hybrid combobox over two cascading patterns** — there are two
  existing route surfaces in the admin: the QSSearchableSelect
  ergonomic (Slice 2+4 thesis) and the `<datalist>` from
  `route-input.js` (used by 3 complex-element wizards). Picking
  ONE pattern for the catalog-driven picker (the combobox + sentinel
  hybrid) keeps the verb authoring story coherent — every catalog
  arg with `inputType: 'route'` reads the same way regardless of
  the `allowExternal` flag. The datalist UX stays at the complex
  wizards for now (datalist works, no user complaints) — UX-
  consistency migration deferred to a chip if it becomes load-
  bearing.
- **Per-arg flag, not per-inputType split** — the alternative was
  two inputTypes (`'route'` strict, `'routeOrUrl'` allowExternal).
  But the picker UX is materially the SAME in both cases: same
  options list, same search behaviour, only the sentinel differs.
  One inputType + a one-line flag on the arg keeps the catalog
  vocabulary tight and the picker dispatch a single branch.
- **`/` home shortcut hard-coded** — every project has `/` as a
  meaningful route (home), but it may not appear in `flat_routes`
  if the project's nested routes structure doesn't include it as
  a leaf. Mirrors `route-input.js`'s line 56 (`home.value = '/'`).
- **`Custom URL…` sentinel at top, not bottom** — the escape hatch
  has to be discoverable. Bottom-of-list hides behind every route;
  top-of-list reads as "the first thing you'd do if you wanted to
  type a URL". The QSSearchableSelect search box still finds it
  if the user types "custom".
- **Back-button clears the typed URL on swap-to-picker** — explicit
  "start over" semantic. Preserving the URL across mode swaps
  would leak state (the input would hold a value the form doesn't
  read), and accidental clicks are recoverable (the user retypes).
  Predictable beats "smart" here.
- **Auto-swap to custom mode on external-URL pre-fill** — without
  it, editing a `redirect.url` that's `https://external.com` would
  surface the value as a "(legacy)" picker option. Custom mode is
  the semantically correct view for an external URL — pre-fill
  detects this with a stripped-down regex (anything not starting
  with single `/`) and swaps.

**Alternatives considered**:

- **`<datalist>` for all route args** — what the complex-element
  wizards already use. Simpler, less code. Rejected because the
  catalog-driven picker should match the A2 trajectory (search-
  first prominent dropdown). Datalist's filter-on-typing is
  inconsistent across browsers and gives no visual signal that
  the field IS a picker.
- **Two inputTypes (`'route'` vs `'routeOrUrl'`)** — rejected (see
  above). The shared picker chrome doesn't justify the catalog
  vocabulary cost.
- **Custom mode as a separate row beneath the picker (vertical
  stack)** — keeps both controls visible at once. Rejected: the
  param has ONE value; showing two slots invites confusion about
  which is authoritative. Mode-swap forces a single source of
  truth at any moment.
- **Preserve URL across mode swaps** — would feel smart but leaks
  state. Rejected.
- **Migrate complex-element wizards (breadcrumb, nav-menu,
  oauth-button) to QSSearchableSelect in this slice** — deferred.
  Datalist works; if UX-consistency complaints accumulate, chip
  the migration.

**Source**: `secure/src/functions/qsVerbCatalog.php` (3 args gained
`'inputType' => 'route'`; `redirect.url` also gained `'allowExternal'
=> true`). `public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`availableRoutes` cache; `fetchRoutes` helper; `_isExternalUrl`,
`_populateRouteOptions`, `_renderRouteArgRow`, `_mountRoutePickerWrap`
helpers; dispatch wired into `_createArgRow` after the `apiEndpoint`/
`api` branch; pre-fetch added to all 4 form-entry flows: `handleAddClick`,
`editInteraction`, `handlePageEventAdd`, `editPageEventEntry`; both
edit pre-fill loops delegate to `_qsRoutePicker.setValue` for the
route inputType). `public/admin/assets/admin.css` (`.qs-route-picker`
+ `.qs-route-picker__back` styles — flex row layout + admin-themed
icon button). The existing `<datalist>` in `route-input.js` stays as-
is for the complex-element wizards. Behaviour documented in
[ADMIN_PANEL.md §9.x](ADMIN_PANEL.md) at full-overhaul ship time
(Slice 7).

### Required-arg validation — two-layer check + positional serializer (locked 2026-06-17)

**Decision**: Interaction save now validates that every `required: true`
arg in the verb's catalog entry has a non-empty value at its positional
index. The check fires at TWO layers:

- **Client (UX layer)** — `_validateRequiredArgs` in
  `preview-js-interactions.js` consults `availableFunctions` (the same
  catalog the picker uses) before building the POST payload. On
  failure, `_applyArgErrors` paints a red border on each offending
  input + an inline "⚠ <argName> is required" message under the row
  + lifts the marks on next edit (one-shot `input`/`change` listener).
  A summary toast names the missing arg(s).
- **Server (data-integrity layer)** — `validateInteractionArgs` in
  `interactionHelpers.php` reads `qsVerbCatalog()` and walks the same
  argspec. Returns `400` with field-level `withErrors([{field, index,
  reason, hint}, ...])` when any required arg is empty, `422` when
  the verb itself is unknown to the catalog. Wired into the four
  save commands: `addInteraction`, `editInteraction`, `addPageEvent`,
  `editPageEvent` — symmetric placement, right after the envelope
  validation (`structType`, `nodeId`, `event`, `function`).

Same slice ALSO fixes the positional serializer. The pre-Slice-5
collector at `preview-js-interactions.js:3354-3358` was:

```js
paramInputs.forEach(input => {
    if (input.value.trim()) params.push(input.value.trim());
});
```

The `if`-filter dropped empty positional slots, compacting the array.
For `exchangeMagicLink(endpoint, paramName, returnTo?)` with only
`returnTo='/dashboard'` filled, `['', '', '/dashboard']` collapsed to
`['/dashboard']` — which positionally bound `/dashboard` to the
`endpoint` arg, producing the bogus `{{call:exchangeMagicLink:/dashboard}}`.
The fix is `_collectPositionalParams`: collect all values, strip
ONLY trailing empties (so trailing optional skips stay compact in
the {{call}} output), preserve middle gaps as empty positional slots.
Validation upstream guarantees no required position is empty by the
time the serializer runs.

**Reasoning**:

- **Two layers, not one** — client-only validation catches the typo
  class at form-submit time with the best UX (per-field inline
  errors), but it does nothing for direct API callers, scripted batch
  imports, or future client regressions. Server-only validation
  catches everything but produces a generic toast 200ms after the
  click with no per-field highlight — worse UX for the same outcome.
  Both is the right answer: 95% of the work is the client-side UX;
  the server check is a 30-line symmetric net. Server is the SOURCE
  OF TRUTH for "what's a required arg" (it reads the catalog
  directly); client is the fast-feedback layer.
- **Positional preservation, not compaction** — the alternative
  ("auto-fill empty required slots with placeholders") would hide
  bugs at runtime instead of blocking them at save. Compaction
  silently mis-binds args. Positional preservation + validation +
  strip-trailing-only is the only design that doesn't lie about
  what the user wrote.
- **Validation BEFORE payload build (client)** — alternatives include
  validating on server response + retry. Server-side echo-back is
  300-500ms slower than instant client validation, and the user
  has to wait for the round-trip to learn what's missing. Client
  validation is the same code path the picker UX already builds
  against.
- **One-shot live-clear of error marks** — alternatives are "stay red
  forever until next save", "live-clear on every keystroke (live
  re-validate)". Permanent-until-save is jarring (user fixes the
  field, error stays). Live re-validate is over-engineering for a
  surface that's saved once at click time. One-shot clear (first
  edit dismisses the mark; if the user re-empties, the next save
  re-paints) is the right middle ground.
- **Server returns 400 for missing, 422 for unknown verb** — 422
  ("Unprocessable Entity") matches the semantic of "the verb you
  named doesn't exist". 400 is for "you sent the right shape but
  the values are missing." Splitting the codes lets a hypothetical
  future caller distinguish "fix your field" from "fix your verb
  name".

**Alternatives considered**:

- **Server-only validation** — simpler, but worse UX (see above).
  Rejected.
- **Client-only validation** — rejected. The whole point of the
  server is to be the source of truth; data integrity can't depend
  on a client doing its job.
- **Add validation as a wrapping middleware before all four
  commands** — would centralize the call, but `addInteraction` /
  `editInteraction` / etc. each have different argspecs already
  (envelope-level), so the validation would still be per-command.
  Rejected for marginal benefit.
- **Block save on form-input change (live)** — would prevent the
  user from ever reaching a state where required args are empty.
  Rejected: blocks normal authoring rhythm (the user may want to
  fill `endpoint` last after picking the rest). Validation at save
  time is the right gate.
- **Keep the compacting serializer** — rejected. The bug is the
  serializer, not the validation. Even with perfect validation, an
  un-fixed serializer would still produce subtly wrong output if a
  future verb has optional middle args (which the validator
  legitimately wouldn't catch).

**Source**: `secure/src/functions/interactionHelpers.php`
(`validateInteractionArgs` helper — loads `qsVerbCatalog()`, walks
the argspec, returns `[]` on ok or list of `{field, index, reason,
hint}` on failure). `secure/management/command/addInteraction.php`,
`editInteraction.php`, `addPageEvent.php`, `editPageEvent.php` (each
calls the validator after envelope validation, returns 400 or 422
with `withErrors([])`).
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`_validateRequiredArgs`, `_collectPositionalParams`, `_applyArgErrors`,
`_clearArgErrors` helpers; `handleSave` + `handlePageEventSave` call
the validator before payload build and render inline error chips on
failure; the compacting `if (input.value.trim())` filter replaced by
`_collectPositionalParams`).
`public/admin/assets/admin.css` (`.preview-contextual-js-form-input--error`
+ `.preview-contextual-js-form-error` + `.preview-contextual-js-form-row--error`
styles — red border via `:has()` for QSSearchableSelect triggers).

### Picker inputType `routeParam` — :params from the current page's route (locked 2026-06-17)

**Decision**: A new catalog inputType `routeParam` replaces free-text
for verb args that name a `:param` in the current page's route URL.
Today's lone user: `exchangeMagicLink.paramName` — the verb reads
`QS.routeParams[paramName]` at runtime, so `paramName` MUST be a
`:name` segment declared on the page's route (`/auth/magic/:key` →
`paramName = "key"`). There is no other source for `routeParams`,
so free input adds nothing but typo risk.

The picker reads `currentPageName` (already available — set by
`preview.js`'s `setCurrentPage`) and extracts every `:X` segment.
`currentPageName` carries the literal route slug straight from
`routes.php → flattenRoutes → toolbar option value` — for the test
project's magic-link page it's `auth/magic/:key`. The NTFS-safe
`:` ↔ `__` sanitisation only kicks in when building file-system paths
(`templates/model/json/pages/auth/magic/__key/__key.json`); over the
wire and in memory the slug stays `:name`. The extractor tolerates
both forms defensively (`:X` primary, `__X` fallback) in case any
future surface sends the sanitised version. No server roundtrip
needed; the slug carries the route shape losslessly.

Edge case: page has no `:params` (e.g. `/dashboard` slug `dashboard`):
the picker shows `— No :params on this route — add one at /admin/sitemap —`
and disables the select. The required-arg validator then blocks save
with a clear error.

Server validation is symmetric. `validateInteractionArgs` gained an
optional `$pageName` parameter; when supplied and an arg declares
`inputType: 'routeParam'`, the validator runs `routeParamsForPageSlug($pageName)`
(same `__X → X` extraction) and rejects values not in that list with
`reason: 'invalid_route_param'`. All four save commands now pass
`$pageName` — for add/editInteraction the validation call moved BELOW
the existing pageName-extraction block; for add/editPageEvent the
pageName was already in scope.

**Reasoning**:

- **Picker over free text** — `paramName` has exactly one legitimate
  source (`QS.routeParams`), which is exactly the route's `:name`
  segments. Any other value is either a typo or means the user
  misunderstood the verb. Closing that door is the whole point.
- **Slug carries the route shape** — once we realised the
  filesystem sanitisation (`:` → `__`) is reversible by a single
  segment scan, the entire feature collapsed to ~30 lines per side.
  No new endpoint, no new cache, no new dependency on the routes
  data structure.
- **Validation moved AFTER pageName extraction in add/editInteraction**
  — the alternative was a two-pass approach (basic check before
  pageName, routeParam check after). Reordering keeps the validator
  call single + lets all errors (missing arg, missing pageName,
  invalid routeParam) report from the same code path. The minor
  cost: a user with a typo'd verb name now sees the "page doesn't
  exist" error first if both apply. Acceptable trade — typoed verbs
  shouldn't happen from the admin form (the picker offers a fixed
  list).
- **Single-user inputType is fine** — mirrors the precedent set by
  `inputType: 'api'` in Slice 4 (only `refresh.apiRef`). One type
  per registry/source shape stays clearer than overloading a
  generic 'enum' with a `source: 'routeParam'` flag.

**Alternatives considered**:

- **Free text + server-only validation** — relies on user trial-
  and-error. Rejected; the typo class is exactly what A2 is
  supposed to kill.
- **Fetch :params from a new server endpoint** — would work if the
  slug didn't already carry the shape. Rejected for needless I/O.
- **Generic `inputType: 'fromPageContext'` with a `source` field** —
  would generalise across hypothetical future "data from the page's
  route" args. Rejected for premature abstraction; today's single
  user gets a clearer single-purpose type.
- **Walk the project's ROUTES array on the server** instead of the
  slug scan — would catch the rare case where the slug doesn't
  perfectly mirror the route shape. Rejected; current QuickSite
  convention IS that they mirror exactly, and divergence would
  break other things first.

**Source**: `secure/src/functions/qsVerbCatalog.php` (`exchangeMagicLink.paramName`
gained `inputType: 'routeParam'` and a plain-English description front-loaded
with the concrete `/auth/magic/:key → "key"` example — the old description was
jargon-heavy with "QS.routeParams" and "ARCHITECTURE §5.3" mentions first;
those stay at the end for power-users).
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`_routeParamsForCurrentPage`, `_populateRouteParamOptions`,
`_renderRouteParamArgSelect`, `_mountRouteParamPickerWrap` helpers;
dispatch wired into `_createArgRow` after the `route` branch).
`secure/src/functions/interactionHelpers.php` (`routeParamsForPageSlug`
helper; `validateInteractionArgs` gained optional `$pageName` parameter
+ `invalid_route_param` error reason).
`secure/management/command/addInteraction.php`, `editInteraction.php`
(arg-validation block moved AFTER pageName-extraction block so the
validator gets `$pageName`).
`secure/management/command/addPageEvent.php`, `editPageEvent.php`
(validator call updated to pass already-extracted `$pageName`).
No CSS changes — reuses QSSearchableSelect styling.

### Picker Slice 6 — enum handler + translationKey + compile-time resolution + two reverts (locked 2026-06-19)

**Decision**: Slice 6 ships four locked additions and two principled reverts.

**Additions:**

1. **`inputType: 'enum'` handler in the picker JS.** The catalog had
   `enum` metadata pre-Slice-6 (on `saveToken.storage` / `clearToken.storage`),
   but no JS handler honoured it — those fields rendered as plain text
   throughout beta.7-8. Slice 6's `_renderEnumArgSelect` is the first
   handler. `scrollTo.behavior` and `toast.type` ride on it. Native
   `<select>` (no QSSearchableSelect wrap) because fixed lists of 3-4
   options gain nothing from search.

2. **`inputType: 'translationKey'` + `allowFreeText` flag** on
   `toast.message`. Reuses the existing `QSComplexWizard.createTextKeyPicker`
   primitive. The `Custom text…` sentinel mirrors Slice 5's route picker
   `allowExternal` pattern — same hybrid combobox shape (picker by
   default; sentinel swaps row to text input + back button).

3. **Compile-time translation-key resolution** (THE critical piece).
   `JsonToHtmlRenderer::buildQsCallJs` + `JsonToPhpCompiler::transformCallSyntax`
   now read `qsVerbCatalog()` at compile time, collect positional arg
   indices flagged `inputType: 'translationKey'` (cached per verb), and
   for each such arg in a `{{call:...}}` chain run
   `Translator::translate(value)`. If the result is the missing marker
   (`{translation missing: X}`), the value passes through unchanged —
   that's Custom Text mode's natural fallback path. Multi-language
   works without further code: source JSON keeps the key, per-request
   render substitutes the per-language string. Future verbs gain
   compile-time translation by declaring the inputType in the catalog,
   no renderer code changes.

4. **V5 default keys for count-sentence picker.** Pre-fills the
   zero/one/many textKey slots with `qs.count.zero` / `qs.count.one`
   / `qs.count.many` when no existing binding overrides them. Authors
   replace per-binding via the picker's "Create new key" form.

**Reverts (same Slice 6 commit, post-verification):**

5. **V13 atomic-child demotion** — REVERTED. The original BACKLOG entry
   argued `<td>` outside `<tr>` is bad UX. Verification flipped that:
   no security reason; skilled authors prefer access; the existing
   Suggested optgroup already surfaces context-aware children when a
   relevant parent is selected. The default-category filter was UX
   paranoia, not protection.

6. **`storageKey` autocomplete** (saveToken.key / clearToken.key) —
   REVERTED. The attempt at live-localStorage + APIs'-`auth.tokenSource`
   autocomplete was noisy at runtime AND papered over the wrong
   problem. The right design is a project-level **storage registry**
   (declared model of every storage location with scope / purpose /
   retention / consentRequired) which also solves GDPR cookie consent.
   Filed as `NOTES/planning/BETA9_STORAGE_REGISTRY.md`; lands post-
   Slice-7 of A2 (either as Track A5 of beta.9 or its own beta.10
   candidate depending on remaining budget).

**Reasoning**:

- **Catalog-driven over hand-curated tables** — for the translationKey
  resolution, the alternative was extending the existing
  `TRANSLATABLE_KEYWORD_ARGS` const in JsonToHtmlRenderer with
  positional indices. Rejected: that table grows linearly with each
  new translatable verb arg. Catalog-driven means the renderer code
  stays constant; new args declare themselves.
- **`allowFreeText` mirrors `allowExternal`** — Slice 5 established
  the hybrid-picker pattern (strict default + opt-in escape hatch via
  a sentinel button). Slice 6's `allowFreeText` for translationKey
  reuses the same shape, same mental model. Both flags live on the
  catalog arg, both render the same swap UX.
- **Translation missing-marker as the fallback trigger** — `Translator::translate`
  returns `"{translation missing: <key>}"` on miss (not the input
  string). Detecting that prefix and falling back to the input value
  gives Custom Text mode a natural pass-through with zero extra
  catalog plumbing.
- **Mirroring the resolution in both renderer + compiler** — the
  runtime renderer is the primary surface; the compiler (build path)
  is symmetric for projects that pre-render. The two divergent
  implementations are a pre-existing concern (renderer has kwarg
  translation, compiler doesn't); the new positional translation is
  added to BOTH to keep the new feature consistent across paths.
- **V13 revert was driven by author feedback** — the design was
  defensible in isolation but the user's reaction ("I might be
  paranoid when I agree to this") was the right reading. Reverting
  during verification is cheaper than carrying the divergent UX
  through to deprecation later.
- **storageKey revert + registry pivot** — the BACKLOG entry framed
  the problem as "picker UX". Verification revealed it as "missing
  declared model". The picker was a treatment for the symptom; the
  registry is the cure. Pivoting before commit avoids shipping a
  primitive that gets replaced in 2-3 betas.

**Alternatives considered:**

- **Translate at SAVE time** (compile the {{call}} with the resolved
  string baked into the JSON). Rejected explicitly — defeats
  multilingual sites (one JSON × N languages can't bake N versions
  of the string).
- **Pass a `{{tr:key}}` marker syntax** that the renderer detects
  inline. Rejected for verbosity — the catalog metadata IS the marker
  semantically; the user shouldn't have to type a marker around every
  translation key.
- **Ship V13 as a `Show all`-toggle** (keep demotion default-on, add
  a power-user escape). Rejected — adds chrome for a feature the user
  no longer wants. Revert is cleaner.
- **Ship storageKey now, replace later with registry**. Rejected for
  admin-debt; primitives that are scheduled for replacement in the
  next slice cycle shouldn't ship.

**Source**: `secure/src/functions/qsVerbCatalog.php` (scrollTo.behavior
+ toast.type enum metadata; toast.message → translationKey + allowFreeText).
`public/admin/assets/js/pages/preview/preview-js-interactions.js`
(`_renderEnumArgSelect`, `_renderTranslationKeyArgRow` + swap helpers,
`_qsTranslationKeyPicker` external API; dispatch in `_createArgRow`;
edit pre-fill delegates to `_qsTranslationKeyPicker.setValue`;
count-sentence default values; selector datalist on jsFormApiBody;
i18n catchup — 13 hardcoded strings wrapped with `PreviewConfig.i18n?.X`
fallbacks).
`secure/src/classes/JsonToHtmlRenderer.php` (`getTranslatablePositionalIndices`
cache + `resolveTranslationKeyOrFallback` helper; positional resolution
added to `buildQsCallJs` after the existing kwarg-translation pass).
`secure/src/classes/JsonToPhpCompiler.php` (parallel positional
resolution; `require_once Translator.php` added; the kwarg-translation
gap stays as a pre-existing concern, separate from this slice).
`secure/admin/translations/en.json` + `fr.json` (31 keys added across
both — search/empty texts for function/api/route pickers, route
custom-URL sentinel, route-param hints, validation messages, custom
text sentinel for translationKey).
`public/admin/assets/admin.css` (`.qs-translation-key-picker` hybrid
layout mirroring `.qs-route-picker`).
`secure/admin/templates/pages/preview/_tag-selector.php` (V13 atomic-
child filter REVERTED; original behaviour restored).
`NOTES/planning/BETA9_STORAGE_REGISTRY.md` (new planning doc — design
for the storage registry that replaces the reverted storageKey
autocomplete; post-Slice-7 timing).

---

## Project conventions (beta.9)

### Data shape — JSON for the author's website data, PHP for engine plumbing (locked 2026-06-14)

**Decision**: Per-project data — anything under `secure/projects/<p>/`
that describes the AUTHOR'S WEBSITE — defaults to JSON. QuickSite engine
and admin panel itself stay PHP (QuickSite is and will remain a PHP app).
The line: if the data describes the author's website, it's JSON; if it's
QuickSite plumbing (engine, admin, framework config), it's PHP. Carve-out:
admin config that users routinely EXTEND (OAuth provider presets, future
plugin registries, etc.) defaults to JSON too — so extension doesn't
require PHP knowledge.

**Reasoning**: The website QuickSite BUILDS could conceivably target a
different runtime later (or be exported, mirrored, imported
independently); JSON for project data keeps that option open. Most
per-project data already follows this pattern (translations, state
stores, route resolvers, API endpoints). Notable migration candidate:
`secure/projects/<p>/management/routes.php` — currently PHP-array,
should be JSON; deferred to a future slice (chip filed; natural slot is
beta.11 when the build pipeline touches it). The carve-out for
user-extensible admin config means OAuth presets land as JSON without
contradicting the engine-stays-PHP rule.

**Alternatives considered**: All-PHP — rejected: locks every project to
a PHP runtime, defeats portability and clean export/import scenarios.
All-JSON (including engine/admin) — rejected: loses PHP expressivity
for code-adjacent config (constants, env-var interpolation in
`api-secrets.php`, helper functions inline). Per-file judgement without
a documented principle — rejected: leads to drift; new code lands in
inconsistent shapes.

**Source**: New per-project data lands as JSON by default; existing
PHP-array per-project data files (notably
`secure/projects/<p>/management/routes.php`) flagged as migration
candidates. Workflow rule mirrored in `CLAUDE.md` (Architecture
Principles section). Behaviour: applies forward to every new data file.

## Translation Manager (beta.9)

### Inline editor shape — row expansion, not modal (locked 2026-06-21)

**Decision**: Click Edit/Set value on a row → an editor panel expands
directly BELOW that row (textarea + Save/Cancel/inline error). Same
mechanism reused by the Delete confirm panel (see below). Only one
row can be expanded at a time; toggling Edit on the open row closes it.
Per-row Delete and Edit can swap modes on the same key without closing.

**Reasoning**: Modal overlays disconnect the user from the row they're
editing — context (scope, neighbours, status) is hidden. Row expansion
keeps the surrounding rows visible, which matters when the user is
mid-task ("I'm reviewing scope=home, updating 5 keys in sequence").
Mirrors GitHub Issues' inline comment editor and Linear's row-expand
patterns — both established for management/triage UX where you
trust the column layout to carry context.

**Alternatives considered**: **Modal dialog** — rejected, breaks
context and the Esc/Ctrl-Enter shortcuts feel heavier in a modal.
**Inline replace** (textarea replaces the row in place) — rejected,
shifts row positions which is jarring when you've scrolled to a
specific row. **Side panel** (slides in from the right) — rejected,
takes too much horizontal space and the panel ALREADY lives in a
sidebar (would have produced a sidebar-in-sidebar).

**Source**: locked during beta.9. Implementation: `_renderEditor` /
`_renderDeleteConfirm` in `preview-translation.js` append a sibling
element after the row in `_rowsContainer` and set `_expandedKey` +
`_expandedMode` state. CSS in `admin.css`
(`.preview-contextual-translation__row-editor` / `__row-confirm`).

### Coverage % math — used / (used + unset), unused excluded (locked 2026-06-21)

**Decision**: Coverage percentage is `used / (used + unset)` per
language. The "unused" bucket (keys in translation file but no structure
references them) does NOT enter the denominator. Display:
`Coverage: 78% (142/172)`. `0/0 → 100%` (nothing to translate = done,
not 0% which would falsely suggest urgent work).

**Reasoning**: "Unused" keys are orphans, not pending work. Including
them in coverage would conflate "this language is incomplete" (action:
translate) with "the project has orphans" (action: clean up keys).
Those are unrelated jobs with different urgencies and different
operators (a translator vs. a developer/architect). The chip toolbar
already exposes the unused count separately so visibility isn't lost.

**Alternatives considered**: **`used / (used + unset + unused)`** —
rejected, mixes signals as above; also penalises a project that just
deleted some structures (unused spikes, coverage drops, but nothing
about the language file changed). **`used / total_keys_in_translation_file`**
— rejected, ignores keys that structures want but the file lacks
(the literal definition of "unset" coverage). **Two separate
percentages** — rejected, twice the chrome for marginal info value.

**Source**: locked during beta.9. Implementation: `_render` in
`preview-translation.js` computes the math; scope-aware (see last
entry below) so the percentage drills down to per-page meaningful
values when scoped.

### Bulk delete safety — list-first confirm, no bare prompt (locked 2026-06-21)

**Decision**: "Remove all unused (site-wide)" replaces the row list
with a confirm panel showing EVERY key about to be deleted, each with
its value preview. User reviews the list, then Cancel or Delete-N.
Per-row delete uses the same pattern at single-row scale (key + value
shown before confirm). Native `window.confirm()` is never used.

**Reasoning**: Bulk deletes are easy to regret. A bare "Are you sure?"
prompt forces the user to make the irreversible decision based on
memory of what they THINK is in the unused bucket. Showing the actual
list converts the choice from a leap of faith into an audit: the user
can spot the one key they forgot they were using and bail out without
losing their place. The pattern costs ~10s of additional scroll but
saves orders of magnitude more time on "wait that wasn't supposed to
get deleted" recoveries (which often need backup-restore or manual
re-translation).

**Alternatives considered**: **Bare confirm dialog** — rejected,
unsafe by definition for bulk ops. **Dry-run preview command +
explicit second call to commit** — over-engineered for a client-side
audit; the list IS the dry-run. **Soft-delete with undo** — would
require server schema for tombstones and a 24h sweeper; out of scope
for this iteration, valid future direction.

**Source**: locked during beta.9. Implementation: `_renderBulkConfirm` +
`_renderDeleteConfirm` in `preview-translation.js`; CSS in `admin.css`
(`.preview-contextual-translation__bulk-confirm` /
`__row-confirm`). Multi-language opt-in (next section) compounds the
safety — bulk-unused defaults to all languages, per-row defaults to
current language.

### Multi-language delete defaults — bulk ON, per-row OFF (locked 2026-06-22)

**Decision**: Both per-row delete and bulk remove-unused expose a
"Delete from all N languages" checkbox in the confirm panel. The
checkbox is HIDDEN when only one language exists. Default state:
- **Per-row delete: UNCHECKED** (current language only).
- **Bulk delete: CHECKED** (all languages).

The frontend loops `_availableLangs` and calls `deleteTranslationKeys`
per-lang via `Promise.all`; partial failure is surfaced inline with
per-language error breakdown.

**Reasoning**: The two operations have different mental models that
justify different defaults.
- **Per-row delete** is usually "I want to delete this VALUE in this
  language file" — the user is editing per-language content (e.g.
  cleaning up a bad FR translation while keeping EN). Defaulting to
  all-languages would surprise.
- **Bulk remove-unused** acts on ORPHANED keys — and an orphan in one
  language is an orphan in all (the "unused" status is structurally
  determined, not per-language). Cleaning the orphan from only one
  language leaves the other files dirty for no reason. Defaulting to
  all-languages matches user intent.

The asymmetric defaults are non-obvious from outside, hence the
explicit checkbox in both — the user can always override.

**Alternatives considered**: **Both default OFF (conservative)** —
rejected, bulk-unused users always have to toggle, useless friction.
**Both default ON (consistent)** — rejected, per-row gets dangerous;
a wrong click wipes the key everywhere. **Hide checkbox in bulk
(force all-langs)** — rejected, denies the legitimate case of "I'm
only setting up FR now, leave EN alone."

**Source**: locked during beta.9. Implementation: `_renderMultiLangCheckbox`
in `preview-translation.js`; `_deleteAllLangs` / `_bulkDeleteAllLangs`
state vars reset to safe default on each panel open.

### default.json — hide from picker (multi), use exclusively (mono) (locked 2026-06-22)

**Decision**: The `default.json` file in `secure/projects/<p>/translate/`
serves two different roles depending on `MULTILINGUAL_SUPPORT`:
- **Multilingual** (`MULTILINGUAL_SUPPORT = true`): `default.json` is
  plumbing — `Translator.php` uses it as the fallback when a key is
  missing from the active language. The Translation Manager HIDES it
  from the language picker (filtered out of `_availableLangs`).
- **Monolingual** (`MULTILINGUAL_SUPPORT = false`): `Translator.php`
  loads `default.json` exclusively and ignores per-language files
  (even if `LANGUAGES_SUPPORTED` lists `'en'` or similar). The
  Translation Manager detects this from `getLangList`'s
  `multilingual_enabled` field and uses `default` as the single language,
  hiding the picker + its label entirely.

**Reasoning**: A monolingual project's `LANGUAGES_SUPPORTED = ['en']`
config does NOT mean `en.json` is the active file — the runtime
deliberately reads `default.json` so monolingual sites work as a
"single source of truth" without per-language sprawl. Before this
fix the panel managed `en.json` while the runtime rendered from
`default.json`,
producing wildly wrong "unset" counts (e.g. 175 false positives on the
test project) and silent edit-target drift. Surfacing the actual
rendered file as the one being managed is the only correct behaviour.

For multilingual, `default.json` IS visible in the file system but
conceptually it's an implementation detail of the fallback chain.
Showing it as a "language" in the picker would invite users to edit
it as if it were one, when the real intent is for those values to
come from the active-language file. Full audit + possible removal is
deferred to beta.10.

**Alternatives considered**: **Show `default.json` in multilingual
picker as "Default"** — rejected, conflates the fallback file with
real languages and tempts users to maintain it manually.
**Use `LANGUAGES_SUPPORTED[0]` in monolingual** — rejected, that's
the bug we just fixed (panel manages a file the runtime ignores).
**Force MULTILINGUAL_SUPPORT to be true** — rejected, monolingual is
a deliberate convenience for single-language sites and we don't want
to delete it.

**Source**: locked during beta.9. Five commands had to be patched to
accept `'default'` as a language code (`validateTranslations`,
`getUnusedTranslationKeys`, `getTranslationKeys`, plus the existing
bypass in `getTranslation` / `setTranslationKeys` /
`deleteTranslationKeys`). Two unrelated commands (`analyzeTranslations`,
`editTitle`) still need the bypass — tracked as a chip.

### Component textKey scanning — server-side extension, not client fetch (locked 2026-06-21)

**Decision**: `getTranslationKeys.php` scans
`secure/projects/<p>/templates/model/json/components/*.json` and emits
each component's textKeys under the source key `'component:<basename>'`
in `keys_by_source`. Single round-trip. The Translation Manager's scope
picker routes the `'component:'` prefix into a "Components" optgroup.

**Reasoning**: Considered (and rejected) doing this client-side as a
per-component fetch when the user picks a Component scope. Server-side
extension is one round-trip vs N (one per component); consumers other
than the Translation Manager (e.g. `command-form.js`'s 4 callsites)
benefit for free; the shape stays consistent with menu/footer/pages
which already use a single grouped response. Components are flat
single-file structures (one `.json` per component, no nesting) which
matches the menu/footer scan pattern verbatim — code reuse is high.

The `'component:'` prefix is necessary because page route names and
component names share a flat namespace: a `home` page and a `home`
component would collide in `keys_by_source` without it.

**Alternatives considered**: **JS-side per-component fetch on scope
change** — rejected, N round-trips, no benefit to other consumers,
inconsistent response shape. **Conflate components into a single
`components` source** — rejected, loses the per-component drill-down
that the scope picker wants.

**Source**: locked during beta.9. Implementation:
`getTranslationKeys.php` section 3.5 (after menu/footer);
`_populateScopeSelect` in `preview-translation.js` for the prefix
routing.

### Translation key validation — permissive helper, no character whitelist (locked 2026-06-22)

**Decision**: `deleteTranslationKeys.php` and any other command that
validates a translation key uses
`isValidTranslationKey()` in `secure/src/functions/translationHelpers.php`:
- `is_string($key)`
- Non-empty after `trim`
- No null byte (`\0`)

No character whitelist. The previous `translation_key_simple` regex
(`/^[a-zA-Z0-9._-]+$/`) is kept in `RegexPatterns.php` for backward
compatibility but is no longer referenced by the translation commands.

**Reasoning**: Real translation keys legitimately include characters
the old regex rejected:
- `/` for nested-route title keys (e.g. `page.titles.documentation/commands`)
- `$` for component template variables (e.g.
  `default.test2.reassurance-item1.$icon`)
- arbitrary UTF-8 for translator-chosen identifiers

The runtime (`Translator.php`), `setTranslationKeys`, and the JSON
storage all accept any non-empty string. Only `deleteTranslationKeys`
was validating, producing an asymmetry that bit users every time a new
legitimate character appeared in their keys. Adding chars to the
whitelist one at a time was whack-a-mole; rejecting them outright was
a barrier to legitimate work.

The minimal security floor (no null bytes, non-empty string) is
sufficient because the key is used for dot-notation array access, NOT
filesystem operations — `..` / `/` / `$` are inert in that context.
The `language` param IS used for a file path and has its own
path-traversal guard separately.

**Alternatives considered**: **Add chars to `translation_key_simple`
one at a time** — rejected, whack-a-mole as explained.
**Apply the same validation to `setTranslationKeys` (consistency
through strictness)** — rejected, risks breaking valid setters
(workflow imports, AI tools) for no security gain. **Remove
`translation_key_simple` from `RegexPatterns.php` entirely** —
deferred, removing patterns is a wider sweep. The pattern is unused
but harmless.

**Source**: locked during beta.9. Implementation: new helper in
`translationHelpers.php`; `deleteTranslationKeys.php:83-95` switched
over.

### Chip counts + coverage are scope-aware, not lang-wide (locked 2026-06-22)

**Decision**: The 🟢/🔴/🟡 chip counts and the coverage % reflect the
user's CURRENT SCOPE + SUBSTRING filter (status excluded — each chip
can't depend on its own checked state). Scope changes recompute counts;
typing in the substring filter recomputes counts.

The "no data" empty state (centered illustration + CTA) and the
"Remove all unused" button enabled gate still use SITE-WIDE totals —
they're global signals that scoping shouldn't suppress.

**Reasoning**: A chip labelled `🟢 142` next to a row list showing 8
filtered rows is misleading. The user expects chip counts to reflect
the population that the rows are drawn from. Per-page coverage % is
also more actionable than per-language: "home is 80% translated" tells
you which page to work on next; "EN is 78%" just tells you to keep
going.

The asymmetric handling of the empty state + bulk button preserves the
"this whole project has no translation keys" signal that scoping
shouldn't drown out. If you scope to a page with 0 keys, the rows show
"No keys match the current filters" (the standard filter-empty
message), NOT the global "No translation keys yet — add a text key
from Add Element → Text" message.

**Alternatives considered**: **Lang-wide counts always** — rejected,
misleading next to scoped rows. **Scope-aware including the "no data"
trigger** — rejected, scoping to an empty page should NOT suggest "you
have no translations site-wide" (which would prompt the user to add
text keys when they should instead change scope).

**Source**: locked during beta.9. Implementation: `_applyScopeAndSubstring`
+ `_render` in `preview-translation.js`. The site-wide totals
(`siteTotalKeys`, `siteUnusedCount`) are computed separately for the
empty-state + bulk-button gates.

### Stylesheet editor — lightened scope (locked 2026-06-22)

**Supersedes**: the entry above ("Stylesheet editor — full scope
committed, no fallback gate", locked 2026-06-11).

**Decision**: The in-editor stylesheet editor ships as a focused
addition to the existing CSS sidebar tool, not as a new sidebar mode.
Three deliverables:

1. A **Source tab** as an advanced top-row view above the existing
   Theme / Selectors / Animations tabs. Code-editor surface in the
   canvas (textarea-over-pre with tokenizer + line gutter + search).
   Saves via `editStyles` whole-file. Live iframe preview while
   typing. Role-gated on `editStyles` permission (hidden, not
   disabled, when missing). Dirty-state guard on tab-switch.
2. A **Theme tab quick-add variable** action in the Colors / Fonts /
   Spacing sections — inline name+value row that respects the
   selected light/dark scope and writes via existing
   `setRootVariables`.
3. **CSS Refiner stays at `/admin/optimize`**, unchanged. Source has
   a "Refine in CSS Refiner →" link.

The Animations tab review (rename to Motion, reshape, three
small features — apply-keyframe to selector, easing curve picker,
transition wizard) is carved out as its own concern
(`NOTES/planning/BETA9_MOTION_TAB.md`); it can ship in parallel
with A3 or after.

**Reasoning**: The 2026-06-11 lock committed to a two-pane structured
+ raw view with live two-way sync and an explicit "never `editStyles`
from this panel" rule. Two facts surfaced after that lock that change
the calculation:

- The existing CSS sidebar tool ALREADY has a structured view — its
  Theme / Selectors / Animations tabs are exactly the "Rules view"
  the original doc was inventing. The new concern only needs to add
  the raw escape hatch, not a parallel structured surface.
- The race condition that justified "never `editStyles`" (raw write
  clobbers a concurrent `setStyleRule` write) is theoretical — a
  single session edits one surface at a time. A dirty-state guard on
  tab-switch (both directions) handles the realistic case at far
  lower cost than per-keystroke parsing + cross-pane reconciliation.

Dropping the two-pane live sync removes the entire `CssParser`
fidelity question from A3's critical path (`editStyles` is whole-file
write; the parser is bypassed for the Source save path). The
client-side CSS parser at
`public/admin/assets/js/lib/css-refiner/css-parser.js` keeps its
existing role inside the Refiner and is not pulled into A3.

**Alternatives considered**:

- **Keep the 2026-06-11 full-scope lock** (rejected — the
  engineering risk was material, the user benefit over the lightened
  shape is small once the existing structured tabs are credited as
  the "structured view").
- **Fold the Refiner into the canvas of the Source tab** (rejected —
  loading the Refiner's library files into every editor session, plus
  the canvas-swap state machine, is non-trivial plumbing for a
  feature exercised occasionally. A "Refine in CSS Refiner →" link
  preserves the in-context flow by opening `/admin/optimize` in a new
  tab).
- **Use a `contenteditable` div for the editor surface** (rejected —
  caret position, IME, undo/redo, and paste handling are famously
  fiddly with `contenteditable`. The textarea-over-pre pattern
  delegates input handling to the native `<textarea>` and uses the
  `<pre>` purely for visual rendering, keeping all the native
  behaviour intact).
- **Ship Source with a plain textarea, defer highlighting** (held
  open — the textarea-over-pre pattern needs the tokenizer + `<pre>`
  regardless, since the search-match overlay paints into the `<pre>`
  layer; coloured tokens fall out for ~50 extra lines. Recommend
  shipping highlighting from the start; design-doc Open question 1).

**Source**: design round 2026-06-22 (this conversation). Doc:
`NOTES/planning/BETA9_STYLESHEET_EDITOR.md` (rewritten same day).
Behaviour at ship time: `docs/ADMIN_PANEL.md` §9 (style management)
will gain a Source subsection.

### Source / structured-tabs cross-tab cache invalidation (locked 2026-06-23)

**Decision**: The Source view's save and Cancel actions in CSS mode
invalidate the three sibling structured-tab caches (Theme variables,
Selectors, Animations) by calling each module's `invalidate()` or
`reset()`. The next view of any of those tabs triggers a fresh fetch
from the server. Unsaved changes that the structured tab had pending
when Source saved are LOST on that next view.

The reverse direction — a structured tab's save invalidating Source's
unsaved draft — is not implemented. (Source's own dirty-state guard
already prompts before discarding Source edits when switching tabs.)

**Reasoning**: Source's `editStyles` rewrites the entire `style.css`.
The three structured tabs each read narrow slices of that file
through separate commands and cache the result locally. Without
invalidation, a Source save leaves the structured tabs showing stale
data — and worse, an unaware user who then saves the structured tab
sends only the diff against the stale baseline, which can SILENTLY
OVERWRITE values Source just persisted. Invalidating + reloading on
next view makes the divergence visible (the user sees fresh data) at
the price of losing the structured tab's unsaved edits when those
edits happened to coincide with a Source save.

The realistic frequency of "Theme dirty + user opens Source + saves
Source" is low — users typically work in one editing surface at a
time, and Source's own outbound dirty guard already prompts when
leaving Source with unsaved edits. A symmetric inbound guard would
require dirty-state tracking on Theme / Selectors / Animations plus
matching UI surfaces, which is meaningful engineering for an edge
case most users won't hit.

**Alternatives considered**:

- **Symmetric dirty-state model across all four CSS surfaces** —
  Theme / Selectors / Animations would each track their own dirty
  state, expose `canLeave()`-equivalent guards, and gain Cancel
  buttons. Rejected: three modules to touch, new UI surface in each,
  extra confirms most users will never see, and the resulting
  prompt-blizzard would itself feel hostile.
- **No invalidation at all** — leave the structured tabs caching
  whatever they have. Rejected: the silent-clobber failure mode (a
  Theme save overwrites Source's changes) is the worst outcome
  available; the user only discovers it after the second save, by
  which point both writes are on disk and the loss is irreversible.
- **Lock the structured tabs read-only while Source is dirty** —
  rejected: overly restrictive; the user's editing session would
  feel paused, and structured edits + Source edits are often
  complementary (e.g., tweak a variable in Theme to see what it
  does, then commit a final cleanup pass via Source).

**Source**: A3 close (2026-06-23). Implementation:
`invalidateStructuredTabs()` in
`public/admin/assets/js/pages/preview/preview-style-source.js` (called
from save + Cancel ok paths); consumed by the reload-on-stale check
in `initStyleTabs` (`preview.js`) and in `deactivateSource()`
(`preview.js`) — three exit paths from Source all converge on the
same behaviour. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.10.

### Motion tab — partial rename, public surface only (locked 2026-06-23)

**Decision**: The Animations tab rename to Motion changes only the
**user-facing label** + the **external JS API** + a few section
identifiers. The internal DOM ids (`#animations-panel`,
`#animations-content`, `#animations-loading`, `#keyframes-section`,
`#keyframes-list`, `#transitions-list`, `#animations-list`, `#triggers-list`,
the `data-group` attribute values, etc.), the tab-routing key
(`data-tab="animations"`), the i18n panel name (`PreviewConfig.i18nPanels.animations`),
and the JS internal function names (`loadAnimationsTab`, `populateAnimatedSelectorsList`, …)
all stay on the old `animations` name. The JS module file renamed
(`preview-style-animations.js` → `preview-style-motion.js`) and the
window global renamed (`PreviewStyleAnimations` → `PreviewStyleMotion`).

**Reasoning**: The DOM ids appear in ~12 CSS selectors and a dozen
`document.getElementById` lookups. The routing key + i18n panel name
appear in `preview.js`'s tab dispatch table, in `ensureI18nPanel`
calls, and in `preview-config.php`'s `i18nPanels` block. Renaming all
of them would touch ~25 callsites for zero user-visible payoff — the
tab label is what users see, and that's i18n-driven. The public API
(file path + window global) IS user-visible to module callers and
matches the new conceptual name; renaming there is worth the small
churn (3 callers).

**Alternatives considered**:

- **Full rename** (DOM ids, routing key, i18n panel name, internal
  function names all to `motion`) — rejected as scope creep. ~25
  callsites; risk of subtle breakage from missed references for no
  user benefit.
- **No rename at all, just relabel via i18n** — rejected. The file
  name `preview-style-animations.js` carrying a window global
  `PreviewStyleAnimations` while the user-facing label says "Motion"
  is confusing for anyone reading the codebase fresh.

**Source**: A3-companion Slice 1 (2026-06-23). Implementation:
`git mv` for the file rename; class-name replace via
`Edit replace_all` for the 3 callers + the script tag in
`preview-config.php`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.11.

### Apply-keyframe + Add-transition — write through setStyleRule with opinionated defaults (locked 2026-06-23)

**Decision**: The Motion tab's two write surfaces — "Apply keyframe to
selector" (Slice 2) and the Transition wizard (Slice 4) — write
through `setStyleRule` with single opinionated defaults rather than
exposing every CSS knob in the modal:

- **Apply keyframe** writes `animation: <name> 1s ease;` — one-shot
  (no iteration count), 1-second duration, default easing. Users
  refine via Selectors → Edit Styles.
- **Add transition** writes `transition: <prop> <dur>ms <easing> <delay>ms;`
  with the form's collected values. Delay is omitted when 0. The
  picked selector's existing transition (if any) is overwritten —
  the modal surfaces a hint to make this explicit.

**Reasoning**: The Motion tab is about quick affordances ("apply this
keyframe over there"). A long form with iteration-count / fill-mode /
direction / play-state inputs would make the most common case
(one-shot apply, sensible duration) feel heavy. The existing
Selectors → Edit Styles path remains available for the full
shorthand. The "overwrite existing" choice over "merge / append a
second transition" mirrors the underlying CssParser merge semantics
(setStyleRule merges by property name, and `transition` is a single
property), avoids surprising the user with two transitions stacking
silently, and keeps the wizard idempotent (run it twice with the
same inputs → same result).

**Alternatives considered**:

- **Full-shorthand form** (every animation/transition sub-property as
  an input) — rejected as scope. The wizard would become its own
  panel rather than a small modal; the value would be marginal over
  the existing Edit Styles flow.
- **Append rather than overwrite** for transitions — rejected.
  `setStyleRule` merges by property name, so appending would require
  client-side concatenation of the existing transition value with
  the new one, plus deduplication of the property field — fragile
  and rarely the user's intent (they think "set the transition for
  this selector", not "add another transition layer").

**Source**: A3-companion Slices 2 + 4 (2026-06-23). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.11.

### Section ordering — Selectors-with-motion primary, Keyframes library secondary (locked 2026-06-23)

**Decision**: The Motion tab orders **Selectors with motion** above
**Keyframes library**. Both sections are expanded by default.

**Reasoning**: The intent flow at the editing surface is "I want to
animate X" → pick a selector → reach for keyframes — selector-first
matches the goal. Library-first inverts the panel against the common
intent (browse all keyframes before deciding which to use), which
is a less common authoring mode. The library is reachable as the
second section without scrolling on most viewports.

The balance shifts somewhat between slices: after the apply-keyframe
+ used-by features (Slices 2 + 2b) landed, the Keyframes library
gained more affordances than Selectors-with-motion offered. Slices 3
+ 4 (easing picker + transition wizard) restored the balance by
putting the transition-authoring weight back on
Selectors-with-motion. Revisit the ordering after Slice 5 close if
the felt-balance disagrees with the design intent.

**Alternatives considered**:

- **Keyframes library first** — rejected. The transient impression
  during Slices 2 + 2b that the library felt "heavier" was an
  artifact of the implementation order, not the user's intent.
- **Collapsible-by-default for the secondary section** — rejected
  for now. Both sections fit on a typical viewport without
  collapsing; forcing a click adds friction. Open as a follow-up if
  it ever becomes crowded.

**Source**: A3-companion (locked at design 2026-06-22, confirmed
post-implementation 2026-06-23). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.11.

---

## API import — OpenAPI 3.x converter (beta.9)

### Detect OpenAPI 3.x; route Swagger 2.0 to an external converter (locked 2026-06-24)

**Decision**: The import detector recognises documents whose top-level
`openapi` field matches `3.x.x`. Documents declaring `swagger: "2.0"`
are detected separately and the preview shows a clear error pointing
the user at `converter.swagger.io` to convert to 3.x first, rather
than half-converting them.

**Reasoning**: OpenAPI 3.x is the dominant authoring format in 2026.
Swagger 2.0's shape diverges meaningfully (`host` + `basePath` instead
of `servers`, `definitions` instead of `components.schemas`,
`parameters[in=body]` instead of `requestBody`). Supporting both adds
roughly a third to converter complexity for a shrinking population of
specs. The explicit "convert first" hint moves the work to a
battle-tested external converter and avoids opaque "unsupported"
messages.

**Alternatives considered**: Convert both 2.0 and 3.x — rejected
(complexity vs. value mismatch). Treat 2.0 specs as "unknown" with the
generic error — rejected (the user has to guess what's wrong).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`detectOpenApi`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### `servers[0].url` becomes the API `baseUrl`; relative URLs require an explicit override (locked 2026-06-24)

**Decision**: The converter takes `servers[0].url` as the API's
`baseUrl`. When more than one server is declared, only the first is
used; a preview note surfaces the alternatives. When the URL is
relative (e.g. `/api/v3`), a dedicated baseUrl input appears in the
preview above the JSON dump, mirrors edits back into the JSON dump,
and blocks Import until the URL is absolute (`http://` or `https://`).

**Reasoning**: QuickSite stores one baseUrl per API; picking the first
server matches the OpenAPI spec's own preference order (declaration
order). Relative URLs are common in published specs but unusable as
the base of an external API call from a QuickSite site, and the
server-side `addApi` validation enforces this. A dedicated input field
is far more discoverable than a "edit the JSON" hint, and validating
client-side gives a clearer error than the server's generic 400.

**Alternatives considered**: Auto-prepend `https://` with a placeholder
host — rejected (silently locks in a wrong host the user might miss
in the preview). Block in the converter — rejected (preserves spec
fidelity in the preview; lets the user see exactly what the source
declared before fixing it).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`convertOpenApi`,
[apis.js](../public/admin/assets/js/pages/apis.js) `_renderBaseUrlFixer`.
Behaviour: [ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### Endpoint ID — `operationId` slugified to dash-case, with collision suffix (locked 2026-06-24)

**Decision**: When OpenAPI declares an `operationId`, the converter
slugifies it to dash-case, splitting camelCase / PascalCase first
(`findPetsByStatus` → `find-pets-by-status`). When `operationId` is
absent, the fallback is `<lowercase-method>-<slugified-path>` (e.g.
`GET /users/{id}` → `get-users-id`). If the generated ID collides
with one already produced in the same conversion, the converter
appends `-2`, `-3`, ... and surfaces the suffix in a preview note.

**Reasoning**: Dash-case matches the convention of existing native
endpoint IDs in `api-endpoints.json` (`auth-login`, `list-paged`). The
camelCase splitter handles the common OpenAPI authoring style without
mangling acronyms (`OpenAPI` stays as `open-api`, not `openapi`). The
collision suffix handles the realistic case where `/users/{id}` and a
literal `/users/id` documentation route co-exist — both would slugify
to `get-users-id` without the suffix.

**Alternatives considered**: Underscore separators (a common OpenAPI
convention) — rejected (mixes conventions inside one project). Numeric
suffix on every ID by default — rejected (uglier `-1` on every ID;
surprising in the common no-collision case).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_slugify`, `_makeSlugAllocator`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### API-level auth — pick the most-used `securityScheme` (locked 2026-06-24)

**Decision**: When `components.securitySchemes` declares multiple
schemes, the converter picks one as the API-level `auth` by tallying
references across global `security` (weighted ×100) and per-operation
`security`. The first scheme that maps to a QuickSite auth shape
(apiKey / bearer / basic / cookie) wins. Other schemes referenced by
some endpoints are surfaced as a note pointing at the preview's
auth-edit field.

**Reasoning**: QuickSite stores one auth type per API; OpenAPI allows
multiple per endpoint. A naive "first declared" picker produces the
wrong default when the spec's declaration order doesn't match its
usage pattern. The Petstore spec is a working example: it declares
oauth2 first and an alternate apiKey second, with eight ops using the
oauth2 flow and two using apiKey — usage tally picks the right
majority. Weighting global security ×100 ensures an explicit
document-level default beats any per-op pattern.

**Alternatives considered**: First declared — produces the wrong
default when declaration order doesn't match usage. Splitting one API
into multiple by scheme — rejected (loses the shared `info` + baseUrl
context the spec author signed up for; doubles the post-import
authoring burden).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_pickApiAuth`, `_mapSecurityScheme`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### Per-endpoint auth — inherit by default; override only where the spec disagrees (locked 2026-06-24)

**Decision**: Each endpoint's `auth` is derived from the effective
OpenAPI security (op-level if present, falling back to global). Empty
effective security emits `auth: 'none'`. Effective security that
references the picked API-level scheme emits `auth: 'inherit'`.
Effective security that uses a different scheme emits `auth:
'required'`, and the converter tracks the alternate scheme name for a
preview note.

**Reasoning**: `inherit` is the cleanest default — it lets the API's
auth config flow through without redundant per-endpoint declarations.
Only differences from the picked scheme are worth marking explicitly.
QuickSite's endpoint `auth` field can't carry the actual alternate
scheme identity (the runtime sends whatever the API-level auth
provides), so `required` is the closest available shape; the
converter's preview note tells the author where manual refinement is
needed after import.

**Alternatives considered**: Always emit explicit `required` on
secured endpoints — rejected (clutters the saved JSON; hides
inherited-from-API intent). Always emit `inherit` regardless of op
security — rejected (silently misses public-override endpoints
declared with `security: []`).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_resolveEndpointAuth`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### Cookie-session security — detect, map best-effort, warn (locked 2026-06-24)

**Decision**: A `securitySchemes.<name>` declared as
`{type: apiKey, in: cookie}` maps to QuickSite's
`auth: { type: 'cookie' }`. The preview surfaces a warning that the
server's session cookie shape should be verified against QuickSite's
same-origin session-cookie expectations (browser owns the cookie, no
client-side token retrieval).

**Reasoning**: Not every OpenAPI cookie scheme matches QuickSite's
specific cookie auth pattern. Mapping plus a warning honours the
spec's intent while flagging the boundary the converter can't verify
on its own. Silent failure (treating cookie schemes as unsupported)
loses information; strict rejection blocks specs that ARE compatible.

**Alternatives considered**: Strict map (no warning) — rejected
(authors miss the "verify before going live" cue). Reject cookie
schemes outright — rejected (compatible specs would have to be
hand-corrected after import).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_mapSecurityScheme`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### Schema examples — keep by default; strip on credential-named keys (locked 2026-06-24)

**Decision**: When walking schemas, the converter copies `example`
fields by default. Examples on properties whose name matches
`/^(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|token|secret|bearer|password|authorization)$/i`
are stripped, and so is anything under `securitySchemes.*`. Counts of
kept vs. stripped examples are surfaced in a preview note.

**Reasoning**: OpenAPI specs commonly ship `example: "12345"` on a
`password` property or `example: "Bearer eyJ..."` on an `Authorization`
header. Carrying those into QuickSite-stored schemas risks an author
mistaking a real-looking test token for a production credential.
Property-name matching catches the common cases without the heavier
engineering of value-pattern detection (which would also catch
synthesised credentials, but at much higher false-positive risk). The
note tells the author exactly how many examples each path took.

**Alternatives considered**: No stripping — rejected (the
"production-looking test bearer" risk is genuine; defaults shouldn't
favour leakage). Value-pattern stripping (JWT regex, "Bearer ..."
prefix) — deferred (catches more cases but at a complexity cost the
common case doesn't pay for).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_isCredentialKey`, `_walkProperties`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### No pagination-envelope schema unwrap (locked 2026-06-24)

**Decision**: When an OpenAPI response wraps the resource in a
pagination envelope (e.g. `{data: {...}, meta: {...}}`), the converter
stores the schema verbatim. No heuristic detects the inner "primary"
schema. The response-bindings authoring layer handles envelope
navigation at bind time.

**Reasoning**: Heuristics that infer the "real" payload from envelope
shapes are inherently brittle — every API team picks slightly
different wrappers (`data` vs. `results` vs. `items` vs. `payload`),
and false positives (e.g. a non-paginated response that happens to
have a `data` property) corrupt the schema. The response-bindings
picker already navigates JSON Schema cleanly via dot-paths; one extra
click during binding is a small cost compared to silent schema
mangling.

**Alternatives considered**: Detect common envelope patterns and offer
the inner schema as a hint — rejected (false-positive risk; author
trust erosion when the hint is wrong). Strip envelopes entirely —
rejected (data loss in the saved schema).

**Source**:
[openapi-converter.js](../public/admin/assets/js/pages/api-import/openapi-converter.js)
`_walkSchema`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

### Preview UI — tree view with per-endpoint checkboxes (locked 2026-06-24)

**Decision**: The import preview renders a tree of APIs and endpoints
with a per-endpoint checkbox (checked by default) and a per-API
select-all that tracks tri-state. The header count reads as
`15 / 19 endpoints` when partial and `19 endpoints` when full. The
raw JSON textarea is preserved in a collapsed `<details>` "Advanced"
panel for power-user edits; tree selection and textarea edits are
both honoured at Import time.

**Reasoning**: OpenAPI fan-out is meaningful — a typical REST API has
10–40 endpoints, and authors rarely want all of them on first import
(noise endpoints, legacy paths, debug routes). Checkboxes give the
"pick what I need" affordance directly. Relegating raw-JSON editing
to an advanced panel preserves the power-user path without crowding
the common one. Tree state owns endpoint selection; the JSON textarea
owns everything else (descriptions, schemas) — a clean ownership
boundary at Import time.

**Alternatives considered**: Flat checkbox list with no API grouping
— rejected (multi-API imports lose hierarchy clarity). JSON textarea
only — rejected (per-endpoint exclusion requires JSON-editing
knowledge most authors don't have).

**Source**:
[apis.js](../public/admin/assets/js/pages/apis.js) `_renderImportTree`,
`_filterApisByTreeSelection`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §9.1.

---

## AI tools integration (beta.9)

### AI tools as a visual-editor mode (locked 2026-06-24)

**Decision**: The AI tools surface lives inside the visual editor as a
sidebar mode (`data-mode="ai-tools"`), reusing the existing
contextual-section infrastructure. Workflow runner UX consolidates into
this mode; `/admin/workflows/{spec}` keeps its standalone runner as a
secondary surface but is no longer the primary path.

**Reasoning**: The original AI workspace required the operator to leave
the editor to invoke a workflow, then navigate back to see the result.
That round-trip is the most expensive part of any AI-assisted edit on
a small site. Folding the runner into the editor sidebar cuts the
loop time roughly in half and keeps the iframe preview visible during
execution so the change appears live.

**Alternatives considered**: Standalone `/admin/ai` page kept as the
only surface — rejected (the context switch was the real friction).
Full rebuild with a hierarchical AI orchestrator (multi-layer
goal-to-tools planner) — rejected for now (high implementation cost,
speculative value at the current catalog size of ~25 workflows; can
be revisited when usage data shows demand).

**Source**:
`secure/admin/templates/pages/preview/sidebar-tools.php` (mode button),
`public/admin/assets/js/pages/preview/preview-ai-tools.js` (panel
logic),
`secure/admin/templates/pages/preview/contextual-ai-tools.php`
(scaffold). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.12.

### 3-zone runner layout — INPUTS / AI EXCHANGE / EXECUTION (locked 2026-06-25)

**Decision**: The runner view is split into three visually distinct
zones rather than a flat list of collapsibles. INPUTS (accent border)
holds Your prompt + Parameters + the primary action button(s). AI
EXCHANGE (dashed border) holds Generated prompt + AI response. EXECUTION
(dashed border) holds the auto-execute toggle, the Batch preview /
results, and the optional Execute button.

**Reasoning**: Workflows are a sequence of phases — assemble input →
exchange with the AI → execute commands. Grouping by phase matches
the user's mental model better than a uniform stack of seven
collapsibles. The visual distinction (border colour + style) makes the
panel scannable at a glance — the user knows where to look for "the
input I'm filling" vs "the response that just arrived" without reading
section titles.

**Alternatives considered**: Wizard-style step-by-step (one zone
visible at a time) — rejected (hides context power users want).
Uniform accordion list with smart open/close defaults — was the
starting point, replaced after user testing showed the lack of phase
boundaries was disorienting.

**Source**:
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_renderInputsZone`, `_renderAiExchangeZone`, `_renderExecutionZone`);
`public/admin/assets/css/preview-ai-tools.css`
(`.preview-contextual-ai-tools__zone--*`). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.12.

### Backup-first warning banner over a dry-run + Apply/Revert system (locked 2026-06-26)

**Decision**: Instead of building a per-command dry-run engine plus an
Apply/Revert UI on top of the runner, the panel surfaces a persistent
warning banner at the top — `AI tools modify your site directly.
Changes cannot be easily reverted. We recommend creating a backup
before running any workflow you're not sure about.` — paired with a
one-click `Create backup now` button that calls the existing
`backupProject` command.

**Reasoning**: A dry-run + Apply/Revert layer would re-introduce a
two-step pre-commit gate on top of the streamlined Run pipeline
(generate → send → execute → iframe reload), pulling the UX in the
opposite direction from where users wanted it. The genuine safety
concern is "I can't easily undo a bad AI run", and that is answered
more honestly and pragmatically by explicit project backups than by
visual previews — backups give full restore, preview only shows what
would happen. The banner makes the trade-off legible: the user is
told the system is destructive and given a one-click tool to mitigate
it. The cost is borne every panel open; the discipline is the user's.

**Alternatives considered**: Per-command dry-run + Apply/Revert as
originally scoped — rejected on cost (~3-5 days for 5 commands, each
with custom predict-state logic on server + matching delta-apply on
client) and UX direction (added friction). Force-confirm carve-out
for `delete*` commands only — rejected as a half-measure that doesn't
address the "bad AI run" recovery story for non-delete commands like
mass `editStyles` overwrites. No safety surface at all — rejected
(genuine user-facing risk needs at least an advisory).

**Source**:
`secure/admin/templates/pages/preview/contextual-ai-tools.php`
(banner markup),
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_onBackupClick`), `secure/management/command/backupProject.php`
(backup command — existed). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.12 "Backup-first banner".

### Unified 1.5s grace timer for auto-execute (locked 2026-06-26)

**Decision**: When the AI response is valid and Auto-execute is on,
the panel waits 1.5 seconds before firing the execution. Any edit to
the response textarea cancels and reschedules the timer. The grace
applies to both the BYOK send pipeline and the manual paste flow — one
behaviour for the user to learn. A status-area hint announces the
wait: `Auto-executing in 1.5s — edit response to cancel`.

**Reasoning**: Auto-execute is a "trust the system" affordance; users
who enable it shouldn't have to click a second button to commit. But
they also need a window to abort if the AI's reply looks off — a sub-
second window would feel like a glitch, a multi-second window would
feel like the system stalled. 1.5s is enough to scan the textarea +
the Batch preview and intervene with a keystroke, while still feeling
prompt for users who don't need to inspect.

**Alternatives considered**: Fire immediately on valid response —
rejected (no abort window; pastes that turn out wrong execute before
the user can react). Modal confirm dialog — rejected (re-introduces
the friction Auto-execute is meant to eliminate). 600ms paste-only
debounce — was the v1 shape; raised to 1.5s + unified across paths
after user feedback that the paste-only debounce was too short to
inspect and was inconsistent with the BYOK pipeline which auto-fired
synchronously.

**Source**:
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_scheduleAutoExecute`, `_cancelAutoExecute`). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.12 "Runner — EXECUTION zone".

### Tag-based suggested workflows dropped (locked 2026-06-26)

**Decision**: The "Suggested" section that scored workflows by token
overlap between the iframe selection and each workflow's `tags` array
is removed. The selection-aware capability stays — element clicks
still update `PreviewState` and remain available for workflows that
declare a `selector` parameter — but no suggestion ranking is
performed on top.

**Reasoning**: Workflow tags describe high-level intents (`style`,
`redesign`, `i18n`) rather than element shapes (`button`, `form`,
`input`). The scoring engine was mechanically sound but fed on inputs
that mostly didn't exist in the catalog and weren't expected to in
future authoring. The "what to do with the selection" question is
better answered by **passing the selection into the workflow as an
input** — via the `selector` parameter type — than by guessing which
workflow to surface based on it. Suggestions assume the user already
knows roughly what they want and just needs a shortcut; selection-as-
input lets the workflow define what it does once it has the element.

**Alternatives considered**: Keep the scoring but tune the matching
algorithm (synonyms, semantic embeddings) — rejected (more code on a
foundation that doesn't fit the catalog shape). Keep the scoring code
dormant in case the catalog evolves — rejected (dead code; the
re-introduction cost when actually needed is no higher than fresh
implementation).

**Source**: Removed from
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_renderSuggested`, `_scoreWorkflow`, related DOM refs) and
`secure/admin/templates/pages/preview/contextual-ai-tools.php`
(Suggested section markup, All-workflows header). What stayed:
`PreviewManager.getSelectedNode()` returns `tag` + `classes`; the
iframe forwards `ai-tools` as `select` mode for hover/click;
footer/menu structure-mismatch prompts are suppressed in ai-tools
mode. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §8.12 "Element selection".

### `selector` parameter type — selection as workflow input (locked 2026-06-26)

**Decision**: New parameter type `selector` auto-fills from the
visual editor's current iframe selection. The param value is a
structured object `{ tag, classes, struct, node }`; workflow steps
access subfields via `{{param.X.tag}}`, `{{param.X.struct}}`, etc.
The runner subscribes to `PreviewState.selectedNode` and re-renders
the param's read-only display whenever the user picks a different
element. On the standalone workflow spec page (no iframe context),
the param renders a hint that the workflow needs to be run from the
editor's AI tools panel.

**Reasoning**: Element-acting workflows ("move this", "edit this
attribute", "delete this") need the selection as a first-class input,
not as metadata that gates suggestion ranking. Treating it as a
parameter lets workflow authors declare exactly what they need and
gives them template access to the selection's fields, the same way
they access any other dataRequirement or user-provided param. The
read-only display + live refresh mirrors how authors expect a
"selected element" widget to behave from other tools (Figma, Inspect
in DevTools, etc.).

**Alternatives considered**: Expose the selection as an implicit
global (`{{selection.tag}}`) without param declaration — rejected
(no opt-in; every workflow sees it whether or not it's relevant; no
spec-level documentation of what the workflow needs). Build a
dedicated "element actions" surface separate from the workflow
framework — rejected (duplicates the runner; selector-as-param folds
into the existing parameter system cleanly).

**Source**:
`secure/admin/workflows/schema.json` (`type: "selector"`),
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_renderSelectorInput`, `_refreshSelectorDisplay`,
`_readSelectionForSelector`),
`secure/admin/templates/pages/workflows/spec.php` (editor-only hint),
`public/admin/api/index.php` (`/api/ai-spec` JSON-decode of
selector-shaped query values). Behaviour:
[WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) "Parameter types".

### `default: "{{data.X}}"` template resolution (locked 2026-06-26)

**Decision**: A parameter's `default` field accepts template strings
referencing fetched `dataRequirements` — e.g.
`"default": "{{data.langData.languages}}"`. `WorkflowManager` resolves
the template against `data` (and `param` for cross-references) before
serving the spec to the UI, so the parameter initializes with live
system state instead of a static seed.

**Reasoning**: A workflow that operates on existing project state
(setup-languages, restyle, migrate-route) should default to the
current state, not to an arbitrary literal. Otherwise the user is
asked to re-enter information the system already has, and any
"compute the diff between what I picked and what's there" logic has
to fall back to literal-as-baseline (which won't match reality). The
template syntax is identical to what step `params` and prompt bodies
already use, so workflow authors don't learn a second resolution
language.

**Alternatives considered**: A separate `defaultFromData` field — re-
jected (forces authors to pick between two ways to express defaults).
A JavaScript expression evaluator on the server — rejected (heavier,
opens an arbitrary-code surface for a value-fill use case).

**Source**:
`secure/src/classes/WorkflowManager.php`
(`resolveParameterDefaults`),
`public/admin/api/index.php` (`/api/ai-spec-raw` call site).
Behaviour: [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) "Default values
from data".

### `in` / `not_in` filter operators for diff-style forEach (locked 2026-06-26)

**Decision**: The `forEach` `filter` expression syntax gains two
membership operators: `in` and `not_in`. Left side is a value (a
forEach `$value` reference or any context path); right side is an
array reference (typically `{{data.X}}` or `{{param.X}}`). The
operators evaluate the left-in-right membership and negate
respectively. When the right side doesn't resolve to an array, `in`
returns false and `not_in` returns true.

**Reasoning**: Diff-style workflows ("add the items the user picked
that aren't already in the project; delete the items currently in the
project that the user un-picked") need a way to express "X is not in
this array" inside a forEach filter. The existing filter parser is
regex-based (intentionally — keeping it declarative + sandboxed,
not a JS eval), and the equality operators alone don't compose into
membership checks. Adding two purpose-built operators keeps the
declarative shape and is small enough to extend the parser without
introducing eval-class surface.

**Alternatives considered**: Switch the filter evaluator to a real
expression parser (peg / hand-rolled) supporting `indexOf()`,
arithmetic, etc. — rejected (much larger surface area, security
exposure, marginal payoff for the use case at hand). Add a server-side
helper that pre-filters the array before forEach — rejected (workflow
authors would have to reach for a separate concept; the filter
expression is the natural place).

**Source**:
`secure/src/classes/WorkflowManager.php`
(`evaluateSingleFilterExpr` — membership branch).
Behaviour: [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) "Filter
operators".

### `optionsFrom.filterFrom` cascade with visible + non-empty semantics (locked 2026-06-25)

**Decision**: A `select` / `tag-select` with dynamic
`optionsFrom` can cascade from another parameter by declaring
`optionsFrom.filterFrom: "<otherParamId>"`. The cascade narrows the
resolved options to entries whose value appears in the referenced
parameter's current selection. The narrowing applies only when the
referenced parameter is currently visible (its `condition` is truthy)
AND its value is non-empty — otherwise the full list is used.

**Reasoning**: Cascading selects are a common UX pattern (state →
city, language list → default language) and previously required
either two separate dataRequirements with different commands or a
post-hoc filter in the workflow's steps. Folding it into the
parameter declaration is one line of JSON per cascade and keeps the
form intelligible to the user. The visible-AND-non-empty rule means
the cascade naturally "turns off" when its source isn't relevant
(e.g., when the multilingual flag is unchecked, Languages is hidden,
so Default language reverts to showing all options) instead of
narrowing-to-empty.

**Alternatives considered**: Always apply the cascade regardless of
source visibility — rejected (when source is hidden the dependent
loses all its options, which is hostile to the no-config path).
Require an explicit `filterWhen` condition on the cascade — rejected
(verbose; the visibility-chain rule covers the common case
implicitly).

**Source**:
`secure/admin/workflows/schema.json` (`optionsFrom.filterFrom`),
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_resolveOptionsFromData`, `_updateDependentSelects`). Behaviour:
[WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) "Dynamic options via
optionsFrom".

### `validation.minItems` / `maxItems` for tag-select, with visible-only enforcement (locked 2026-06-25)

**Decision**: Parameter `validation` accepts `minItems` and
`maxItems` integers for `tag-select` arrays, in addition to the
existing `minLength`/`maxLength` (text) and `min`/`max` (number).
Validation is skipped entirely when the parameter's own `condition`
fails — hidden parameters never fail validation.

**Reasoning**: The setup-languages workflow needs "if multilingual is
on, the user must pick at least two languages, otherwise the workflow
is meaningless". This is a per-array-length constraint conditional on
another parameter. The visible-only rule makes the conditional half
of the constraint free — the workflow author doesn't have to express
"validate only when X" explicitly, because the `condition` they
already wrote on the parameter naturally gates validation. Run is
blocked while any visible parameter fails its declared validation,
with the failing parameter shown in red and an inline message.

**Alternatives considered**: A separate `validation.condition` field
— rejected (duplicates the parameter's existing `condition`). Run
unconditionally with server-side post-execution validation — rejected
(the user only sees the error after the AI call / partial commit has
already happened).

**Source**:
`secure/admin/workflows/schema.json` (`validation.minItems`,
`validation.maxItems`),
`public/admin/assets/js/pages/preview/preview-ai-tools.js`
(`_validateAllParams`, `_validateParam`, `_renderParamErrors`).
Behaviour: [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) "Validation
constraints".

### Storage registry is browser-storage-only; privacy policy is a separate concern (locked 2026-06-27)

**Decision**: The storage registry (`data/storage.json`) covers exactly what the
site stores on the visitor's device — `localStorage` / `sessionStorage` /
`cookie` — with a GDPR `category` (`essential` / `functional` / `analytics` /
`marketing`) and `retention` per key. `consentRequired` is **derived** (anything
non-`essential`), never stored. Third-party data sharing / "what a provider
collects" is explicitly out of scope (a later privacy-policy concern).

**Reasoning**: Drawing the line at "data on the device" keeps the feature
coherent and shippable: it's the cookie-consent surface a GDPR banner actually
governs. Capturing the category at declare-time is far cheaper than
retro-classifying later. Deriving `consentRequired` avoids a second field that
could disagree with `category`.

**Alternatives considered**: One combined privacy+storage inventory — rejected as
too broad for one beta (the "data leaves the device" surface is a distinct
concern). Storing `consentRequired` explicitly — rejected (derivable, avoids
drift).

**Source**: `secure/src/functions/storageHelpers.php`
(`storageConsentRequired`), `secure/management/command/*StorageItem.php`,
`scanStorageUsage`. Behaviour: [ADMIN_PANEL.md](ADMIN_PANEL.md) storage registry,
`NOTES/planning/BETA9_STORAGE_REGISTRY.md`.

### Consent gating skips the individual write, fail-closed, at two choke points (locked 2026-06-27)

**Decision**: Runtime consent gating no-ops the **single storage write** whose
key is non-`essential` and unconsented — not the surrounding interaction chain.
It is enforced at exactly two `qs.js` choke points (`setStoredToken`, shared by
`saveToken` + the token-refresh flow, and `QS.store`); removers (`clearToken`,
logout) are never gated. An **undeclared** key is **fail-closed** (blocked). The
guard is dormant unless the project enabled the consent layer (`window.QS_CONSENT`
is emitted live-only when enabled). Consent choices live in a `consent_prefs`
cookie (itself an `essential` item) so the server can respect them too.

**Reasoning**: Skipping just the write keeps a `fetch → store → showResult` chain
working (only the gated write drops) — the least-surprise behaviour. Two choke
points cover the entire write surface (verified: other verbs delegate to these or
don't touch storage). Fail-closed on undeclared keys matches the registry's "no
storage write without a declared entry" model and is the GDPR-safe default. The
dormant-until-enabled rule means existing projects are unaffected until they opt
in.

**Alternatives considered**: Abort the whole chain on a gated write — rejected
(breaks unrelated steps). Fail-open on undeclared keys — rejected (GDPR-unsafe).
Gate per-verb everywhere — unnecessary (the two choke points are complete).

**Source**: `public/scripts/qs.js` (`_consentAllowsWrite`, `hasConsent`,
`setConsent`), `secure/src/functions/consentHelpers.php`,
`secure/src/classes/PageManagement.php` (hydration emit).

### Consent banner + popup follow the menu/footer global-layer pattern, not a component (locked 2026-06-27)

**Decision**: The banner + preferences popup are two ordinary project structures
(`templates/model/json/consent-banner.json` + `consent-popup.json`) generated
from the registry and rendered on every page by the engine
(`renderConsentLayer()`, beside `renderMenu`/`renderFooter`) — not a Complex
Element / component. `qs.js` wires reserved `data-consent-action` /
`data-consent-toggle` attributes; the structures carry the styleable markup. The
visual editor exposes two preview-only toggles beside Menu/Footer.

**Reasoning**: A component needs a host node; the consent layer is
page-independent, exactly like menu/footer. Reusing that pattern makes the layer
styleable in the stylesheet editor and editable in the visual editor for free,
and keeps it independent of per-page footer toggles. Visitor copy uses `textKey`s
so it renders in the visitor's language from day one.

**Alternatives considered**: A consent "Complex Element" — rejected (needs a host
node, not page-global). Hardcoded engine markup — rejected (not author-styleable).

**Source**: `secure/src/functions/consentLayerHelpers.php`
(`buildConsentBannerStructure` / `buildConsentPopupStructure`),
`secure/src/classes/JsonToHtmlRenderer.php` (`renderConsentLayer`),
`secure/management/command/generateConsentLayer.php`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md).

### Cookie-policy page: author-chosen route, registry-owned; consent copy is EN/FR-built-in with English fallback (locked 2026-06-27; consent-copy i18n superseded 2026-07-02)

**Decision**: `generateCookiePolicy` writes a deterministic policy table to a
route the author chooses (warning + confirm before overwriting an existing
route). The route is recorded in `consent.json` and **owned by**
`generateCookiePolicy` / `deleteCookiePolicy` — `generateConsentLayer` only reads
it for the banner link, so a cancelled overwrite never wires up an unrelated
route. Default copy ships built-in for **en + fr**; any other configured language
is seeded with English and reported (`languagesFallback`) so the author can
translate it — never a `{{translation missing}}` placeholder on the live site.
Per-key descriptions are `consent.policy.desc.<key>` textKeys refreshed from the
registry on every regenerate (storage.json is the source of truth), while
structural copy stays author-editable.

**Reasoning**: Author-chosen route keeps the page in the sitemap + SEO and lets
the author place/translate it. Single-ownership of the route prevents the
home-page-overwrite hazard. English fallback is friendlier than a placeholder for
visitors; the admin gets the "translate these" nudge instead. Refreshing
descriptions from the registry avoids two sources of truth for the same text.

**Alternatives considered**: A reserved fixed route — rejected (author can't
choose URL / placement). Erroring on non-EN/FR languages — rejected (breaks
monolingual / no-French sites). `{{translation missing}}` on the live page —
rejected (worse visitor experience than English). Baking descriptions per page —
rejected (not translatable).

**Source**: `secure/management/command/generateCookiePolicy.php`,
`generateConsentLayer.php`, `deleteCookiePolicy.php`, `getConsentStatus.php`;
`secure/src/functions/consentLayerHelpers.php` (`buildCookiePolicyStructure`,
`consentPolicyDescSeed`, `consentOAuthLinks`). Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md).

### Registry descriptions are keyed translations, authored in one language; generated copy is default-language only (locked 2026-07-02)

**Supersedes**: the consent-copy i18n portion of the "Cookie-policy page …" entry
above (route ownership there is unchanged).

**Decision**: Storage- and privacy-registry descriptions are **not** stored inline
per language. They are `translate/` textKeys (`storage.desc.<id>`,
`privacy.collected.<id>.label` / `.purpose`) authored in one registry-level
**description language** — a page-level selector defaulting to the site default.
Editing a description is live (the generated policy page re-renders it without
regeneration); regeneration is only for data changes. Changing the description
language **moves** every description to the new language and leaves the old key as
an empty (missing) translation — never deletes it — warning before overwriting
existing target values. Generated *structural* copy (banner / popup / policy
chrome) is seeded for the **default language only** (built-in en/fr wording, or
English text when the default is another language); other languages surface as
missing in the Translation Manager.

**Reasoning**: One source of truth (`translate/`) instead of an inline copy plus a
derived projection refreshed on every regenerate — kills the dual-source clobber
hazard and makes description edits live. Completeness is delegated to the existing
translation tooling (`analyzeTranslations` / `validateTranslations`), which already
treats empty as missing — so the move empties rather than deletes, turning a
moved-away language into an actionable "translate me" rather than a silent gap. The
old English-fallback seed put English text on a non-English page pretending a
translation existed; default-language-only + the Translation-Manager nudge is
honest and matches how the rest of QuickSite localises.

**Alternatives considered**: Inline `{lang: text}` descriptions refreshed from the
registry on regenerate (the prior model) — rejected (two sources, non-live, clobber
hazard). Seeding all configured languages with English fallback — rejected (silent
wrong-language copy on the live page). Pre-creating empty keys for every language —
rejected (clutter; the tooling already reports absent == missing). A per-item stored
source-language field — rejected (which language a value is in is already recorded
by which `translate/` file holds it; one registry-level language suffices).

**Source**: `secure/src/functions/storageHelpers.php`,
`privacyHelpers.php`, `translationHelpers.php` (`translationGetKey` /
`translationSetKey` / `translationUnsetKey`);
`setStorageDescLang.php` / `setPrivacyDescLang.php`;
`generateCookiePolicy.php` / `generateConsentLayer.php` /
`generatePrivacyPolicy.php`. Behaviour: [ADMIN_PANEL.md §9.10-9.11](ADMIN_PANEL.md).

### Privacy helper: data-sharing compliance derived from the API surface (locked 2026-07-02)

**Decision**: A second compliance registry (`data/privacy.json`) mirrors the
storage registry but covers data **sharing** — what the site sends *out* to APIs
and sign-in providers. It **scans** the API registry (`api-endpoints.json`) into
**atoms** — `(endpoint, field)` pairs from each endpoint's declared `parameters` +
`requestSchema` (response schemas ignored). The author declares **data collected**
(label + purpose), **maps** atoms to those entries, and **classifies** each API
`baseUrl` as a server they operate or a third party (name + privacy URL). A datum's
recipient is **derived** (atom → endpoint → host), never stored on the mapping.
Coverage flags unmapped atoms, body-bearing endpoints that declare no fields
(*unverifiable*), and unclassified hosts. OAuth / magic-link sign-in are
auto-detected as known collection sources. `generatePrivacyPolicy` emits a
deterministic page (collect table + per-third-party sharing + OAuth links + a
cookie cross-link that links / hints / omits + a legal note).

**Reasoning**: GDPR ⊂ privacy policy — the storage registry generated the cookie
half; this generates the rest, deterministically from data the project already
declares. A human-in-the-loop mapping supplies the PII judgment QuickSite can't
derive (it cannot know `startId` isn't personal but `email` is). Schema-driven,
not runtime-observed, so it also serves headless / app targets and is a stable
contract; response-side data that is persisted is already the storage registry's
job, so responses are ignored here. "Third party" cannot be derived, so host
classification is author-declared; the UI says "servers you operate" so a processor
isn't under-disclosed.

**Alternatives considered**: Auto-emitting a host/field table with no human mapping
— rejected (can't classify PII). Folding sharing into the storage registry —
rejected (different data model; muddies a clean feature). Scanning runtime calls
instead of schemas — rejected (schema is the deterministic contract and works
without the site being wired). Deriving third-party status from same-origin
heuristics — rejected (a project runs several first-party hosts; only the author
knows).

**Source**: `secure/src/functions/privacyHelpers.php`, `privacyScanHelpers.php`,
`privacyPageHelpers.php`, `policyPageHelpers.php`;
`secure/management/command/getPrivacyStatus.php` + the `setCollectedDatum` /
`deleteCollectedDatum` / `setPrivacyMapping` / `setPrivacyHost` /
`setPrivacyCookieSection` / `generatePrivacyPolicy` / `deletePrivacyPolicy`
commands. Behaviour: [ADMIN_PANEL.md §9.11](ADMIN_PANEL.md).

### Privacy prefill rides the native API import (locked 2026-07-02)

**Decision**: A native API import may carry optional namespaced `privacy` blocks —
API-level `{ host, name, url, collects: [{label, purpose}] }` and endpoint-level
`{ fields: { <field>: <label> } }` — that populate the privacy registry in one
pass, applied through the existing privacy commands after each API is created.
Labels resolve to slug ids (deduped across APIs); an endpoint field referencing a
label the API didn't declare is **flagged in the import preview and skipped**, not
fatal. **Native format only** — OpenAPI / Swagger imports classify + map in the UI
afterward.

**Reasoning**: Lets a single import stand up an API *and* its privacy story.
Reuses the shipped commands (`setCollectedDatum` / `setPrivacyHost` /
`setPrivacyMapping`) — the import is client-orchestrated, so there is no backend
import command to extend, and the existing preview step is the natural home for the
validation flags. Labels are the friendly authoring surface, resolved to ids once
at import. Named `privacy`, **not** `tier` — "tier" already means the magic-link
Tier 1/2/3 flow and would confuse. Flag-and-skip keeps a partial import useful.

**Alternatives considered**: Hard-failing on unknown labels — rejected (a partial
import is still useful; the preview shows what will be skipped). Referencing datums
by id in the schema — rejected (labels are friendlier; resolve once). Building
OpenAPI `x-` vendor extensions now — rejected (deferred until asked; most specs
carry no privacy intent). A dedicated backend import command — rejected (the
importer is client-orchestrated; the apply rides existing commands).

**Source**: `public/admin/assets/js/pages/apis.js` (`_applyImportPrivacy`,
`_computeImportPrivacy`, `handleImportConfirm`). Behaviour:
[ADMIN_PANEL.md §9.11](ADMIN_PANEL.md).

---

## Identity and authentication (beta.10)

### Passwords replace the lifetime bearer token as the credential (locked 2026-07-11)

**Decision**: A user logs in with a secret they chose — a password, verified
against a bcrypt hash on the user record — and receives a session. The
long-lived `tvt_` API tokens are removed outright, not demoted: the
token→identity map is deleted from the auth config and the commands that minted,
listed and revoked tokens are deleted with it.

**Reasoning**: A lifetime bearer token is a credential with none of the password
ecosystem around it. It cannot be memorised, cannot be stored in a password
manager, cannot be rotated by the person who holds it, and — critically — a copy
of it is valid forever unless an administrator notices and revokes it. Every
property that makes tokens convenient for machines makes them wrong for the
human sitting in front of an admin panel. A middle option was live for a while
(keep tokens but call them "login keys" and mint them per user); it was rejected
once the shape became clear, because a login key is still a lifetime bearer
secret, and building one while the same release ships a registration page meant
building a credential the next release would immediately delete. One gap is accepted deliberately: there is no password-reset
flow, because QuickSite ships no mail infrastructure and the dependency-free
rule keeps it that way — the escape hatch is a deployer editing the user
registry directly.

**Alternatives considered**: Keep tokens for beta.10 and add passwords later
(the original plan — rejected: the identity model was being rebuilt anyway, and
deferring meant migrating users twice). "Login keys" — per-user minted tokens
presented at a login form (rejected: a lifetime bearer secret wearing a
password's clothes). Passkeys / WebAuthn (deferred: real value, but it needs an
account-recovery story QuickSite does not have yet).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_auth_attempt_login`),
`secure/src/functions/SessionManagement.php`,
`secure/management/command/login.php`. Behaviour:
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### Access token plus refresh token, with rotation and reuse detection (locked 2026-07-11) (superseded 2026-08-10)

**Decision**: A login mints a **pair** — a short-lived access token sent on every
request (15 minutes) and a longer-lived refresh token accepted only at the
refresh endpoint (30 days, sliding). Refreshing **rotates**: the presented
refresh token is retired and a new pair issued. Presenting an already-rotated
refresh token after a short grace window is treated as a theft signal and revokes
the entire **session family** — every token descended from that login. Tokens are
stored hashed; the raw values never touch disk.

**Reasoning**: Short access lifetimes bound the damage of a leaked request
credential without asking the user to log in every fifteen minutes. Rotation is
what turns the refresh token from "a lifetime token with extra steps" into a
detector: once a token may only be used once, a second use is evidence that two
parties hold it, and the only safe interpretation is that one of them stole it.
Revoking the family rather than the single token is deliberate — the attacker
may already have rotated ahead of the victim, so killing one token could well
kill the victim's session and leave the attacker's alive. The grace window exists
because honest clients do race: a dropped response or two tabs refreshing
together must not look like theft.

**Alternatives considered**: A single long-lived session token (rejected — the
flaw being fixed). Rotation without reuse detection (rejected — rotation alone
buys almost nothing; the detection is the point). Revoking only the reused token
(rejected — can punish the victim and spare the thief). HttpOnly-cookie-only
custody for the access token (deferred as post-1.0 hardening; the refresh token
already rides an HttpOnly cookie).

**Source**: `secure/src/functions/SessionManagement.php` (`qs_session_issue`,
`qs_session_rotate`, `qs_session_revoke_family`). Behaviour:
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### The admin panel holds one session; the access token lives in memory only (locked 2026-07-11) (superseded 2026-08-10)

**Decision**: The admin panel is the single holder of the browser's session. The
server-side PHP session owns the token pair; the page emits the current access
token into the in-memory client config, and the client keeps it **only** in
memory — never in `localStorage`, never in `sessionStorage`. The refresh token
rides an HttpOnly, SameSite-Strict cookie scoped to `/admin`. The client
refreshes at 80% of the remaining lifetime and retries a request once on an
expired-token response.

**Reasoning**: Browser storage is readable by any script that reaches the page,
which makes it precisely the wrong home for a credential in an application whose
own threat model treats stored XSS as the primary risk. An in-memory token dies
with the tab and is invisible to injected script that arrives after page load.
Making the panel the *single* holder matters as much as the storage choice: when
two places both believe they own the credential they drift, and a stale copy in
`localStorage` outlives the logout that was supposed to end the session.

**Alternatives considered**: Keep the `localStorage` token with a shorter TTL
(rejected — shortens the window without closing it, and left two owners). Put
the access token in a cookie too (rejected for beta.10 — the panel's many
hand-built fetch call sites read a config value; moving them all to cookie auth
is a larger change than the risk justified). Note that the author's *own site*
keeps `localStorage` as its documented default for visitor auth — see the
magic-link entry above; that is a different subsystem with a different threat
profile, and it was not changed.

**Source**: `secure/admin/AdminRouter.php` (`ensureFreshSession`,
`storeSessionPair`), `public/admin/assets/js/core/api.js`. Behaviour:
[ADMIN_PANEL.md §7](ADMIN_PANEL.md).

### Identity is a private username; the email field is removed (locked 2026-07-12)

**Decision**: A user record carries a **public display name**, a **private
username** used only to log in, and an opaque id. The username is lowercase
`a`–`z`, digits, underscore and hyphen, 3–32 characters, unique
case-insensitively, and immutable in beta.10. The email field is deleted from
the record entirely.

**Reasoning**: QuickSite sends no mail — there is no verification, no password
reset, no notification. An email address that nothing ever uses is not identity,
it is personal data held for no reason, and holding it makes the installation a
more attractive target than the projects in it warrant. A chosen username is the
honest replacement: it is the thing the user actually types, it carries no
external meaning, and it does not identify the person outside this installation.
Treating it as **private** — never returned by any command, never rendered on a
shared surface — is what keeps it from becoming an enumeration target in its own
right, which is why the public display name exists as a separate field.

**Alternatives considered**: Keep email as the login identifier (rejected —
personal data with no consumer; also the more valuable secret to leak). Make the
username public and use it for lookups (rejected — it is the login identifier;
publishing it hands out half of every credential). Allow username changes
(deferred — the id is the durable key, so a rename is mechanically possible, but
it needs a story for anything that cached the old value).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_valid_username`,
`findUserByUsername`, `qs_public_user_ref`),
`secure/management/config/users.php.example`. Behaviour:
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### The public display name must differ from the private username (locked 2026-07-12)

**Decision**: Account creation refuses a display name that equals the username,
compared case-insensitively and after control characters are stripped. The rule
is enforced at the single account-creation function, not only at the registration
form, and returns an honest validation error.

**Reasoning**: The display name is published — rosters, invitations, member
lists. The username is private because it is half of a credential. If a user
picks the same string for both, the published name *is* the login identifier,
and the privacy property the whole identity model rests on quietly evaporates for
that account. The check is cheap, the failure is loud, and it happens once at
creation rather than at every read.

**Alternatives considered**: Warn instead of refuse (rejected — a warning that
can be clicked past is not a guarantee). Enforce at the registration page only
(rejected — any other creation path would bypass it; the rule belongs at the
one place that mints an account). Fuzzy matching, so `alice` and `Alice_` also
collide (rejected — exact-match-after-normalisation is predictable and
explainable; fuzzy rules reject names users legitimately want).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_user_create`, and
the shared registration gate). Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### Registration answers identically for a duplicate and a success — because the username is private (locked 2026-07-12)

**Decision**: Public registration returns a byte-identical `200` whether the
requested username was free or already taken, with the work equalised so timing
does not distinguish the two. A **malformed** username, by contrast, returns an
honest `400` naming the format rule. Registration never logs the new account in.

**Reasoning**: The uniform response exists for exactly one reason, and it is
worth stating because it decides the rule: the username is private. If
registration answered "taken", anyone could test a guessed username and learn
whether that account exists — which is a probe against a credential, not against
a directory. A format error leaks nothing, since the rule is published and the
answer is derivable without the server; refusing to explain it would just make
the form hostile. This is the inverse of the posture a *public* identifier would
warrant: when the identifier is public, an honest conflict response is correct
and a uniform one is theatre. Not auto-logging-in keeps a single
session-establishing path in the system rather than two.

**Alternatives considered**: Honest `409` on duplicate (adopted briefly while
usernames were expected to be public, reversed as soon as they were ruled
private — the identifier's visibility, not the endpoint, decides the posture).
Uniform response for format errors too (rejected — no secret, real usability
cost). Auto-login after registration (rejected — a second way to establish a
session is a second way to get it wrong).

**Source**: `secure/src/functions/AuthManagement.php`
(`qs_auth_attempt_register`, `qs_user_create`),
`secure/management/command/register.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Registration is denied by default and flood-controlled on three axes (locked 2026-07-12)

**Decision**: Public self-registration reads a config flag that defaults to
**deny** — a fresh installation does not let strangers create accounts. When
enabled, three independent limits apply, each a config knob where `0` disables
it: a per-IP rate (3 per minute), a global hourly ceiling (30 per hour) and an
absolute cap on the number of accounts the installation will ever hold. Only
successful registrations fill the global window. Minimum password length is a
knob, defaulting to 12.

**Reasoning**: Secure-by-default: the deployer who never reads the config gets
the closed door, and opening it is a deliberate act. The three limits answer
three different abuses — one machine hammering the form, a distributed trickle,
and an installation quietly filling with junk accounts that each get to call
project-creation. Counting only successes in the global window means a burst of
failed attempts cannot deny service to a legitimate signup. Making each limit a
knob with an off value keeps the mechanism honest for the single-user
installation that finds it pointless.

**Alternatives considered**: Per-IP limiting only (rejected — trivially evaded
and does nothing about total volume). A hardcoded minimum password length
(rejected — the deployer, not QuickSite, knows their threat model). Default the
flag to allow (rejected outright — a public account-creation endpoint on by
default is a defect, not a convenience).

**Source**: `secure/management/config/auth.php.example`,
`secure/src/functions/AuthManagement.php` (`qs_auth_attempt_register`).
Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### No `createUser` command — accounts are self-created (locked 2026-07-12)

**Decision**: There is no authenticated command that creates an account for
somebody else. A proposed `createUser` was dropped before it shipped. The only
ways an account comes into existence are public registration (when the deployer
enables it) and the first-run bootstrap described further below.

**Reasoning**: Talked through, an authenticated "create an account for a
server-to-server caller" endpoint is registration with a bearer token attached —
same effect, same surface, second implementation. Two ways to mint an identity
means two places to get the uniqueness rules, the name/username rule, the
password policy and the caps right, and they will drift. Real platform
provisioning — one system creating QuickSite accounts on behalf of its own users
— is a genuine requirement, but it belongs to a designed integration layer, not
to a command bolted on in a security release.

**Alternatives considered**: Ship `createUser` behind a new global
"user management" permission (rejected — see the entry on the retirement of
global owner-level access below: at the time, a global owner-gated category
resolved to "owns any project at all", and project creation is open to every
authenticated account, so the gate would have granted itself). Ship it
unauthenticated for server-to-server use (rejected — that is registration).

**Source**: `secure/management/command/register.php` is the only public creation
path. Behaviour: [COMMAND_API.md](COMMAND_API.md).

### There is no global user-administration lane (locked 2026-07-18)

**Decision**: No command lists, disables or deletes other people's accounts.
`listUsers` and `disableUser` were both dropped rather than built. Per-project
eviction is `removeMember`; suspending an account is an operator action on the
user registry, outside the API.

**Reasoning**: Account *existence* is global in QuickSite, but *authority* never
is — every permission in the model is scoped to a project, and there is no
principal that stands above projects to hold a user-admin power. Inventing one
would have meant a global owner-gated category, which at the time resolved to
"owns any project", reachable by anyone who created a project — the exact
escalation shape the release had just closed elsewhere. `listUsers` was refused
on separate grounds: a directory dump is the enumeration surface that the private
username, the uniform registration response and the exact-match-only user lookup
were all built to prevent. Handing it back through an admin endpoint would undo
all three. `listMembers`, the project roster and exact-match lookup already serve
every legitimate need.

**Alternatives considered**: A global "user manager" role (rejected — see above;
also reintroduces the god-principal the role model deleted). `listUsers`
restricted to project owners (rejected — every account can become an owner by
creating a project, so the restriction restricts nobody).

**Source**: `secure/management/config/categories.php`,
`secure/management/command/listMembers.php`,
`secure/management/command/getProjectRoster.php`,
`secure/management/command/findUser.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Account deletion is self-service, hard, and refused while you solely own a project (locked 2026-07-18)

**Decision**: `deleteMyAccount` deletes the caller's own account and nobody
else's. It requires the current password (on the shared login throttle) plus an
explicit confirmation, **refuses** while the caller is the sole owner of any
project and names those projects, and performs a **hard delete** rather than
marking the record disabled. The cascade removes every membership entry keyed by
the caller — membership, invitation received, own request, proposal filed about
them — and deliberately keeps `by`/`sponsor` references inside entries about
*other* people, which degrade to a null name. If any part of the cascade fails,
the operation aborts before the record is touched. Sessions are revoked last.

**Reasoning**: Requiring the password means a stolen access token cannot erase an
account — deletion is the one operation where possession of a session should not
be enough. Sole ownership blocks deletion because the alternative states are both
worse: cascading the project's destruction hides a second irreversible act inside
the first, and leaving the project ownerless leaves it permanently unownable
*and* undeletable, since ownership transfer requires the caller to be the current
owner and both deletion and transfer are owner-only. Each project must therefore
be handed over or destroyed explicitly, keeping its own confirmation. Hard delete
was chosen over anonymising because the shipped account gates test for the
literal `disabled` status; a new `deleted` status would **fail open** at every
one of them unless each call site changed in the same commit — a rule that
degrades to "allowed" when unrecognised is the wrong shape for a security check.
Keeping third-party references is the same principle applied outward: stripping
them would destroy a stranger's pending invitation as a side effect of someone
else's departure, and the accept-time re-validation already voids anything that
genuinely depended on the departed user's standing.

**Alternatives considered**: Anonymise instead of delete (rejected — the
fail-open status enum above). Cascade-delete solely-owned projects (rejected —
two irreversible acts, one confirmation). Leave the project ownerless (rejected —
proven to strand it permanently). Purge every reference to the user everywhere
(rejected — destroys third parties' pending state).

**Source**: `secure/management/command/deleteMyAccount.php`,
`secure/src/functions/AuthManagement.php` (`qs_members_mutate`). Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### The forward-compatibility seam for external identity is a configurable token source, not a field (locked 2026-07-05)

**Decision**: The user record carries one id and no external-identity field. An
external system that wants to drive QuickSite accounts owns the
`its-user → QuickSite-user` mapping on **its** side. QuickSite's single
forward-compatible hook is that it reads the access token from a configurable
source, defaulting to its own login.

**Reasoning**: An `externalId` column is a guess about a system that does not
exist yet — it fixes a cardinality (one external identity per user), a format,
and an ownership story before anything has argued for them, and every one of
those is easier to change on the outside than in a shipped data file. A token
source, by contrast, is the actual integration point: whatever mints sessions is
where an external identity provider has to plug in, and pointing that at a
different implementation needs no schema at all.

**Alternatives considered**: Keep `externalId` as an inert field for later
(rejected — an unused field in a security-relevant record is a maintenance and
audit liability, and it is not free to remove once installs have data in it).
Design the full external-identity bridge now (rejected — no consumer, and the
bridge is a separate application's concern).

**Source**: `secure/management/config/users.php.example` (six fields, no
external id); `public/admin/assets/js/core/api.js` (`setTokenSource`).

---

## Authorization model (beta.10)

### No superadmin and no global role tier — authority is per-project (locked 2026-07-05)

**Decision**: There is no superadmin, no `global_role` field, and no principal
that holds power across projects. Authority is entirely per-project: a user
belongs to zero or more projects, and `owner` is the top of each one. Every
hardcoded superadmin remnant was removed, including the synthetic-superadmin
escape hatch that a disabled-auth flag used to conjure.

**Reasoning**: A global tier is a single credential whose compromise is total. In
a file-based product that a solo author or a small team runs themselves, it also
buys very little: the operator who would hold it already has the filesystem, and
everything a superadmin was for — updating the engine, inspecting an
installation — is better done from the shell than from a web session. Removing
the tier deletes a whole class of escalation target rather than hardening it. The
disabled-auth hatch went with it for the same reason: a development convenience
that produces a god-principal is a production backdoor waiting for a
misconfiguration.

**Alternatives considered**: Keep superadmin but require a second factor
(rejected — still one credential to total compromise, and QuickSite has no second
factor). Keep the disabled-auth hatch for local development (rejected — the
failure mode is silent and catastrophic; a developer can create an owner account
in seconds instead).

**Source**: `secure/src/functions/AuthManagement.php` (`hasPermission`),
`secure/management/config/roles.php`. Behaviour:
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### Fixed built-in roles with ranks; custom roles removed (locked 2026-07-06)

**Decision**: Six built-in roles, each with a numeric rank — `viewer` 1,
`editor` 2, `designer` 3, `developer` 4, `admin` 5, `owner` 6. No per-project
role files, no custom-role palette, no role-authoring commands: the three
commands that created, edited and deleted roles were moved to a category granted
to nobody, including the owner. Exactly one owner per project, transferable, not
shareable; an admin manages every rank strictly below their own but cannot
delete the project, manage other admins, or transfer ownership.

**Reasoning**: Custom roles are an authorization surface that has to be audited
as carefully as the rules it generates, and every installation ends up with a
slightly different one, so nothing about the model can be reasoned about
generally. Deleting the feature removes that surface entirely — a straight
security win — at the cost of flexibility a small-team product does not need.
Ranks exist so that two rules can be stated once instead of enumerated: manage
only strictly below yourself, and never grant a role at or above your own. The
owner/admin split is what makes the hierarchy lockout-proof in both directions:
an admin cannot nuke the project or evict a peer, and the owner cannot be removed
by the people they appointed.

**Alternatives considered**: Keep custom roles and audit them (rejected — the
audit never ends; each installation is its own model). Leave the role-authoring
commands reachable by the owner (rejected — they were meaningless under a fixed
role set, and one of them crashed outright on the new config shape; denying them
is the honest state). Shared ownership (rejected — "who can remove whom" has no
safe answer with two owners).

**Source**: `secure/management/config/roles.php` + `roles.php.example`,
`secure/src/functions/AuthManagement.php` (`roleRank`, `canManageRole`).
Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### Permissions are granted by category, never by per-command lists (locked 2026-07-06)

**Decision**: A new file, `secure/management/config/categories.php`, maps every
command to exactly one category and declares that category's scope (global or
project) and, for global categories, its access rule. Roles list **categories**,
not commands. The mapping must be 1:1 in both directions — a routed command with
no category fails closed, and a categorised command with no route is a stray.
Categories are designed to be trust-coherent: a bucket that mixes a harmless read
with a destructive write gets split.

**Reasoning**: The previous shape re-listed roughly a hundred command names under
each of five roles — around 550 lines of duplication whose only job was to stay
identical. Duplication in an authorization table does not stay identical; it
drifts, and a drift means one role silently gained or lost a command. Grouping by
category makes registering a new command a one-line edit in one file instead of
an N-role sweep, and it makes the question a reviewer actually needs to answer —
"what class of authority is this?" — the question the file asks. Enforcing the
1:1 invariant programmatically rather than by eye is part of the decision: it is
the property that makes "fails closed" true rather than hoped for.

**Alternatives considered**: Keep the flat per-role command lists (rejected — the
drift above). Derive categories in code from a naming convention (rejected —
implicit, and a rename becomes a silent permission change). Allow a command in
several categories (rejected — the union is then the effective grant, and the
effective grant becomes unreadable).

**Source**: `secure/management/config/categories.php`,
`secure/management/config/roles.php`,
`secure/src/functions/AuthManagement.php` (`getCommandCategory`,
`hasPermission`). Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### Global owner-level access retired outright (locked 2026-07-19)

**Decision**: A **global** category may grant to "any authenticated user" or to
nobody. The owner-level global access rule is gone, replaced by a single
allowlist constant that both permission consumers read, so a reintroduced
`'owner'` — or a typo — falls through to deny.

**Reasoning**: At global scope there is no target project, so an owner check has
nothing to be owner *of*: it resolved to "owns any project anywhere". Project
creation is open to every authenticated account, so the check was satisfiable by
any account willing to create a throwaway project — one call away from being
granted. That is not a gate, it is a formality, and it was the mechanism behind a
proven privilege escalation earlier in the release. Leaving the rule in place
because its last remaining user happened to be harmless would have left a loaded
mechanism for the next global category to pick up by accident. Two consumers had
to change, not one: the permission check itself and the union that feeds the
panel's UI gating — fixing only the first would have left the interface offering
commands the API refuses.

**Alternatives considered**: Keep the rule and re-gate its last user (rejected —
no sound token gate exists for it in a model with no global principal). Keep the
rule unused with a comment warning against it (rejected — a comment is not an
enforcement mechanism; the constant is).

**Source**: `secure/src/functions/AuthManagement.php`
(`QS_GLOBAL_ACCESS_GRANTING`, `hasPermission`, `getTokenPermissions`).
Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### Engine self-update is an operator action, not an API command (locked 2026-07-19)

**Decision**: `applyUpdate` — which git-pulls the installation — is removed from
the routable command list and kept as an operator/CLI file with a header
explaining why. Operator-level work (updating the engine, deciding what a domain
serves, inspecting the whole installation) lives outside the token-gated API.

**Reasoning**: With no global tier, there is no principal to gate an
installation-wide, irreversible, unconfirmed operation on: any rule expressible
in the model resolves to some project role, and no project role should be able to
replace the engine under everyone else. The honest answer is that the operation
has no web-facing home. Removing it cost nothing measurable — it had no callers,
no interface, no place in the admin listing, and was never granted to a role — so
the alternative was maintaining a gate for a command nobody could correctly be
given.

**Alternatives considered**: Gate it on owner-of-every-project (rejected —
unstable as membership changes, and an installation with one project makes it
meaningless). Add a confirmation parameter and keep it routed (rejected — a
confirmation does not fix "who may do this at all"). Delete the file (rejected —
it is genuinely useful from a shell).

**Source**: `secure/management/command/applyUpdate.php` (present, unrouted),
`secure/management/routes.php`. Behaviour: [COMMAND_API.md](COMMAND_API.md).

### The per-user selected project is never an authorization input (locked 2026-07-05)

**Decision**: The user record's `selected_project` is a per-user default view —
which project the panel opens on — and nothing else. It is never read when
deciding whether a request is allowed. The project a request acts on comes from
the request itself.

**Reasoning**: A stored pointer that both *routes* an action and *authorises* it
is a confused deputy waiting to happen: two tabs, a stale page, or a
concurrently-changed pointer, and the request authorised against one project
executes against another. Worse, the pointer is user-writable state, so anything
derived from it is attacker-influenced by construction. Splitting the two makes
the failure mode benign — a wrong pointer opens the wrong page, which the user
immediately sees and corrects, instead of silently widening what they may do.

**Alternatives considered**: Authorise against the pointer and validate it on
write (rejected — validation at write time cannot cover state that changes
between write and use). Drop the pointer entirely (rejected — it is genuinely
useful, and harmless once it authorises nothing).

**Source**: `secure/src/functions/AuthManagement.php` (`hasPermission` takes the
requested project explicitly), `secure/management/command/setSelectedProject.php`.
Behaviour: [ADMIN_PANEL.md §7](ADMIN_PANEL.md).

### The URL marker is the sole source of a project-scoped target; a body value may only echo it (locked 2026-07-19)

**Decision**: For a project-scoped command, the project comes from the URL marker
the dispatcher authorised. If the request body also names a project, it must be
**identical** to the marker; disagreement is a `400`. There is no fallback to any
stored pointer. One shared helper implements this and every project-scoped
command uses it.

**Reasoning**: The dispatcher authorises the marker, so anything the command
subsequently reads from the body is unauthorised input. Left unbound, commands
"authorised for project A, executing on project B" — reads, writes, duplication
and deletion into projects the caller was provably not a member of, an export
archive streamed to a non-member, and one command that enumerated the filenames
it destroyed. The class is a confused deputy, and the fix has to be structural
rather than per-command, because it is not one bug but the same bug in however
many commands forget. Permitting an exact echo, rather than ignoring the body
outright, keeps existing clients working while making the disagreement loud
instead of silent. Removing the stored-pointer fallback is the load-bearing half:
a fallback re-introduces the confused deputy for any request that simply omits
the marker.

**Alternatives considered**: Ignore the body value silently (rejected — a client
sending a mismatched value is confused, and silence hides it). Re-authorise the
body value instead of binding the marker (rejected — it works, but it means every
command carries its own authorization logic; the point is that they should not).
Fix the affected commands individually (rejected — the next command reintroduces
it).

**Source**: `secure/src/functions/projectContainment.php`
(`qs_bind_marker_project`). Behaviour: [ARCHITECTURE.md §3](ARCHITECTURE.md).

### An in-process execution path must reproduce authorize-then-bind (locked 2026-07-19)

**Decision**: The admin panel's helper endpoints, which execute command functions
in-process rather than through the HTTP dispatcher, now do exactly what the
dispatcher does and in the same order: take the project from a URL marker, run
the permission check, then bind the project context. Each helper arm **inherits
the category of the command it runs** through an explicit map, rather than
declaring a permission of its own. Arms are enumerated explicitly, so a newly
added arm is gated by default. Binding a project means binding **both** the
project path and the public content path — the asset, style and build arms read
the second, so binding only the first still serves the wrong project.

**Reasoning**: These endpoints resolved the caller "for role-based permission
checks" and then never used the result — no authorization ran at all across
twenty-eight arms, two of which deleted or overwrote files, reachable by any
account with zero memberships. The correctness bug travelled with it: the arms
ran under whichever project was globally current, so the panel showed one
project's data while editing another. Inheriting the command's category is the
part worth keeping as a rule: a parallel permission model for the same
underlying operation is guaranteed to disagree with the real one eventually, and
the disagreement will be discovered as a vulnerability rather than as a bug.
Enumerating the arms rather than pattern-matching them means the safe default for
new code is "gated", not "forgotten".

**Alternatives considered**: Give the helper endpoints their own permission table
(rejected — the parallel model above). Move the arms behind the real dispatcher
(a good idea, deferred — a larger refactor, and the hole needed closing
immediately). Trust the marker without authorising it (rejected — the marker is
client-asserted; it is safe *because* it is authorised, never because it is
trusted).

**Source**: `public/admin/api/index.php`,
`public/admin/assets/js/core/api.js` (`helperPath`). Behaviour:
[ADMIN_PANEL.md §5](ADMIN_PANEL.md).

### Where a bypass allowlist exists, the allowlist is the boundary — and it is capped at the lowest role (locked 2026-07-24)

**Decision**: The in-process command runner does not consult the permission
system; its hardcoded allowlist **is** the boundary. That allowlist is therefore
pinned to commands the lowest role already holds, and the rule is asserted
against the live category-and-role configuration rather than eyeballed. Two
higher-tier reads that had accumulated in it were evicted. A second re-gate that
existed on paper is documented as fail-open by construction, so the allowlist's
floor is the guarantee and a future unconditional re-gate is an improvement
rather than a silent assumption.

**Reasoning**: A documented bypass is defensible; an *undocumented* one is not,
and neither is one whose contents nobody re-checks. Because the runner never asks
who is calling, every entry is effectively granted to everyone who can reach the
runner — so the only safe invariant is that every entry is something the weakest
principal could already call. Deriving the tier from the live configuration at
test time, rather than maintaining a second list of "safe" commands, means the
check cannot rot when a command is re-categorised.

**Alternatives considered**: Make the runner call the permission check (better,
and the eventual direction — deferred because the caller does not always have an
authenticated principal to check). Trust the allowlist as maintained (rejected —
it had already drifted twice, retaining commands deleted releases earlier).

**Source**: `secure/src/classes/CommandRunner.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

---

## Membership and consent (beta.10)

### Membership is by consent; invitations live where they cannot grant (locked 2026-07-16)

**Decision**: Nobody is added to a project by another person's action alone. An
authorised member **invites**; the invitee accepts or declines. Pending
invitations are stored in a **separate block** of the project's membership file
from the active members, so every consumer that reads authority reads only the
members block and a pending invitation is structurally incapable of granting
access. The membership file is the sole authority for access decisions; the
per-user project list is a rebuildable mirror, never consulted by a permission
check.

**Reasoning**: The earlier design let an authorised member add a user directly.
That is a smaller mechanism, but it means somebody else decides what you are a
member of, and — more practically — an "add" and a "pending invite" that live in
the same list are one forgotten status check away from being the same thing. The
separate block converts that from a discipline into a property: there is no field
to check, because the pending record is not in the structure authority reads.
Making the per-user list a mirror rather than a second source means drift is a
display bug, never an access bug — the worst outcome is a project missing from a
sidebar, which a reconcile pass repairs.

**Alternatives considered**: Direct add by an authorised member (the previous
design — rejected: consent, and the status-field fragility above). One list with
a `pending` flag (rejected — every reader must remember the flag; forgetting it
grants access). Make the per-user list authoritative for speed (rejected — drift
becomes an access bug).

**Source**: `secure/projects/<id>/config/members.json`,
`secure/src/functions/AuthManagement.php` (`qs_members_mutate`),
`secure/management/command/inviteMember.php`,
`secure/management/command/acceptInvitation.php`. Behaviour:
[ARCHITECTURE.md §2](ARCHITECTURE.md).

### Invitations target a user id, never a name or username (locked 2026-07-16)

**Decision**: `inviteMember` takes a `user_id` and nothing else. The id is the
one *public* unique identifier: the display name is public but not unique, and
the username is unique but private. Finding the id is a separate exact-match
lookup that returns `{user_id, name}` and structurally cannot return a username,
because a single shared builder constructs every user reference the API emits.

**Reasoning**: Any invite that accepts a name has to resolve it, and resolution
is enumeration — a caller learns which names exist by watching which ones
resolve. Accepting a username is worse: it makes a private credential half into a
lookup key. Splitting lookup from invitation means the enumeration surface is one
command with one deliberate posture (exact match only, no prefix, no list) rather
than an emergent property of every membership command. Routing every user
reference through one builder is what makes "the username never appears in shared
output" a structural claim instead of a review checklist.

**Alternatives considered**: Accept a username (rejected — publishes the login
identifier). Accept a display name with disambiguation when several match
(rejected — the disambiguation list is the enumeration). Fuzzy or prefix search
(rejected — the same surface, wider).

**Source**: `secure/management/command/inviteMember.php`,
`secure/management/command/findUser.php`,
`secure/src/functions/AuthManagement.php` (`qs_public_user_ref`). Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Standing is re-validated at accept time, inside the lock (locked 2026-07-16)

**Decision**: Accepting an invitation re-checks the **inviter's current
standing** against fresh state, inside the same lock as the write: if the inviter
has since been removed, demoted below the invited role, or is now merely a peer
of it, the invitation is void — the entry is pruned and the accept refused. The
same rule governs approving a join request.

**Reasoning**: An invitation is an authorization decision made at one moment and
consumed at another, potentially days later. Without re-validation, demoting
somebody does not actually remove their reach: everything they issued before the
demotion still lands. That makes demotion advisory, which is not what an
authorization change should mean. Doing the check in-lock rather than before it
closes the race where a concurrent demotion lands between check and write.
Pruning the void entry rather than leaving it means the queue reflects reality
instead of accumulating invitations that can never succeed.

**Alternatives considered**: Honour invitations as issued (rejected — demotion
stops meaning anything). Sweep and cancel invitations when somebody is demoted
(rejected — a sweep must find every affected entry across every project and is
wrong the moment it misses one; validating at use time is correct by
construction). Check before acquiring the lock (rejected — the race).

**Source**: `secure/management/command/acceptInvitation.php`,
`secure/management/command/approveJoinRequest.php`. Behaviour:
[ARCHITECTURE.md §2](ARCHITECTURE.md).

### Two consents, or nothing — the join lane and the proposal lane (locked 2026-07-17)

**Decision**: A membership exists only where **both** consents exist: the
person's and the project's. A self-requested join carries the person's consent
already, so approval completes it and the membership materialises immediately. A
**proposal** — any member vouching for somebody who has not asked — carries only
the project side, so approving it does not create a membership: it **converts**
into a real invitation addressed to the proposed user, which they must still
accept. Until then the proposed person is not engaged at all: no entry in their
mirror, no inbox row, nothing.

**Reasoning**: This is the rule that keeps consent from being quietly optional.
Without it, a proposal plus an approval is a two-insider path to adding somebody
who never agreed — the direct-add the model deliberately dropped, reassembled
from two halves. Making approval produce an invitation rather than a membership
keeps the ledger honest: the artefact that exists is exactly the consent that has
been given. It also composes correctly with re-validation above — the converted
invitation records the approver as its issuer, so the accept re-checks the person
who actually exercised authority, not the member who merely suggested it. Leaving
never-engaged targets completely untouched matters too: being proposed and
rejected should leave no trace for somebody who was never asked.

**Alternatives considered**: Approving a proposal creates the membership directly
(rejected — direct-add in two steps). Notify the proposed person at proposal time
(rejected — turns a private "should we ask them?" into an offer the project has
not agreed to make). Drop proposals entirely (rejected — an admin cannot invite a
peer admin, since invitation is strictly-below; proposing is the only way to ask
the owner to sign one off, and deleting it would have removed that path).

**Source**: `secure/management/command/proposeMember.php`,
`secure/management/command/requestToJoin.php`,
`secure/management/command/approveJoinRequest.php`. Behaviour:
[ARCHITECTURE.md §2](ARCHITECTURE.md).

### The approver sets the granted role, capped strictly below their own (locked 2026-07-18)

**Decision**: Approving a join request takes an optional `role`, defaulting to
the role on record — `viewer` for a self-request, the sponsor's suggestion for a
proposal. The rank check runs in-lock against the **granted** role, so an
approver can never mint a member at or above their own rank. A self-request
materialises directly at the granted role; a proposal converts into an invitation
for it.

**Reasoning**: The authority to set a member's role already existed as a separate
command — this only folds it into the same atomic step, so "approve, then
immediately promote" stops being two writes with a window between them. Checking
the granted role rather than the stored one is the security-relevant half: without
it, an approver could accept a request recorded at a low rank and hand out a high
one, which is exactly the escalation the rank ladder exists to prevent.
Defaulting to the recorded role means leaving the field alone reproduces the
previous behaviour.

**Alternatives considered**: No override at all — approval always materialises
the role on record (adopted earlier in beta.10, then reversed once it was clear
the approver would immediately follow up with a role change anyway). Approve,
then call the role-change command from the interface (rejected — two calls, a
visible window at the wrong role, and a partial failure leaves an unintended
grant standing). Check the rank against the stored role only (rejected — that is
the escalation).

**Source**: `secure/management/command/approveJoinRequest.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### A proposal may not name a role above the sponsor's own (locked 2026-07-18)

**Decision**: `proposeMember` caps the suggested role at the sponsor's own rank,
checked in-lock; above it, the call is refused and nothing is written. The bound
is "at or below", not "strictly below" — a viewer must be able to propose a
viewer.

**Reasoning**: Even though a proposal grants nothing on its own, letting anyone
name any role means the queue fills with asks that outrank the people making
them, and the approver's rank check becomes the only thing standing between a
suggestion and a grant. Capping at the sponsor's rank keeps the vouch
proportionate to the standing behind it. "At or below" is the only workable bound
here, unlike invitation and approval which are strictly-below: strictly-below
would leave the lowest role unable to propose anybody at all, which is not a
restriction, it is a broken feature.

**Alternatives considered**: No cap, with rank checked only at approval (adopted
earlier in beta.10, then reversed — the approve-time check is a backstop, not a
substitute for proportionate authority, and an uncapped queue fills with asks
that outrank the people making them). Strictly-below, matching invitation
(rejected — the lowest role could propose nobody).

**Source**: `secure/management/command/proposeMember.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### The join-request posture matrix, including one deliberate knock-oracle (locked 2026-07-17)

**Decision**: How a join request is refused depends on the project's visibility
and its join policy, and the matrix is deliberate:

- **private + closed** — a `404` byte-identical to the response for a project
  that does not exist. Nothing is revealed.
- **private + open** — the request is recorded. This *does* confirm the project
  exists to any authenticated account that guesses its id, and that is the
  point: the owner opted in. The setter carries an advisory note saying so.
- **public + closed** — an honest `403` "requests are closed". Existence is
  already public.
- **public + open** — recorded; nothing new revealed.

Responses that reveal only the caller's own state — already a member, your own
pending invitation, your own pending request — are `409`s and are never
oracles. A project's display name is withheld until membership: a private
project's entries carry the id the caller already typed, never the site's name.

**Reasoning**: A uniform refusal is only worth its cost where it hides something.
On a public project, existence is already discoverable by fetching the site, so a
uniform `404` would be theatre that an adversary refutes with one request while
genuinely confusing legitimate users. On a private, closed project it hides
something real, so it is exact — identical status, identical message. The
interesting cell is private and open: it is a real, if narrow, existence oracle,
accepted because the alternative is deleting the "private team, knock to join"
flow that owners actually want, and because forbidding the combination would
couple visibility to join policy in a way that the visibility setter would then
have to unpick. The mitigation is that the disclosure is opt-in, the note says
what is being traded, and the id is all a knock confirms — never the name.

**Alternatives considered**: Uniform `404` everywhere (rejected — theatre on
public projects, misleads legitimate users). Forbid private + open (rejected —
kills a wanted flow and couples two independent settings). Reveal the display
name in the requester's own pending entry (rejected — a knock may confirm an id
exists; it must not hand over the site's identity).

**Source**: `secure/management/command/requestToJoin.php`,
`secure/management/command/setJoinPolicy.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Self-initiated exits leave no trace; other-initiated terminations leave a dismissable notice (locked 2026-07-17)

**Decision**: When a user leaves a project or withdraws their own request, their
mirror entry is simply removed. When something happens *to* them — removed by an
admin, refused, or the project deleted — a terminal entry stays in their mirror
until they dismiss it. Somebody who was never engaged at all, such as the subject
of a proposal that was denied, gets nothing.

**Reasoning**: A notice exists to tell you about a decision you did not make.
Leaving a tombstone for your own deliberate exit is noise you then have to clear;
withholding one for a decision made about you means the project quietly vanishes
from your list and you never learn why. The never-engaged case is the same
principle taken to its conclusion: a person who never knew they were being
discussed should not receive a notice that a discussion about them ended. Making
the terminal entries dismissable rather than auto-expiring keeps the
acknowledgement explicit, which the re-request rule then relies on — a standing
refusal blocks re-asking until it is acknowledged.

**Alternatives considered**: Tombstone every exit uniformly (rejected — noise the
user must clear for their own actions). Tombstone nothing (rejected — removals
become silent). Auto-expire notices (rejected — the acknowledgement is
load-bearing for re-request gating).

**Source**: `secure/management/command/dismissProjectNotice.php`,
`secure/management/command/listMyInvitations.php`,
`secure/management/command/leaveProject.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### One writer for the membership file, with an invariant backstop that aborts (locked 2026-07-16)

**Decision**: Every write to a project's membership file goes through one
function: per-project lock, fresh read inside the lock, caller's mutation, then a
structural invariant check, then an atomic swap. The invariants include exactly
one owner-role member matching the recorded owner field, valid role names, no
overlap between the invitation block and the member block, and no invitation at
or above the owner rank. A mutation that would violate any of them **aborts and
writes nothing** — it never repairs, and never conjures a file that was missing
or unreadable.

**Reasoning**: The membership file is the authority every permission check reads,
so a corrupt one is not a data-quality problem, it is an authorization outcome. A
single writer is the only way locking means anything — a second, lock-free writer
makes the first one's lock decorative, which is what the previous duplicated
writers amounted to. Aborting rather than repairing is the deliberate part: a
repair path is code that writes authorization state in a situation nobody
anticipated, which is precisely when it should refuse. Refusing to conjure a
missing file matters for the same reason — an absent authority file must fail
closed, not be helpfully recreated with whatever the caller had in hand.

**Alternatives considered**: Repair-on-detect (rejected — writes authority state
from an unknown state). Validate after writing (rejected — the invalid state has
already been published). Let each command write the file with its own locking
(the previous shape — rejected: duplicated and, in places, lock-free).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_members_mutate`).
Behaviour: [ARCHITECTURE.md §2](ARCHITECTURE.md).

### A cloned or imported project is born with a fresh roster (locked 2026-07-18)

**Decision**: Creating, cloning or importing a project all go through one
birth-write that discards any inherited or archive-supplied membership file and
writes a new one naming the caller as sole owner. An import that carried a
membership file has it dropped and the discard logged. Project exports exclude
the membership file entirely.

**Reasoning**: A clone copies a project's *content*; copying its access list
copies other people's memberships into a project they never agreed to join, and
carries the original owner into a project they do not own. An import is worse,
because the archive is attacker-supplied: a planted membership file would let the
uploader write themselves — or anyone — into the new project's roster at any
rank, which is a roster-hijack shipped inside a zip. Making birth-write the only
way a project's roster comes into existence closes both, and it is the same
helper in all three cases, so a fourth creation path inherits the property rather
than reinventing it. Excluding memberships from the export is the privacy half:
an archive handed to somebody else should not enumerate who worked on it.

**Alternatives considered**: Validate the imported membership file instead of
discarding it (rejected — there is no version of "someone else's access list" that
is correct in a new project). Keep memberships in the export and strip them on
import (rejected — the archive still discloses the roster to whoever holds it).

**Source**: `secure/src/functions/AuthManagement.php`
(`qs_project_birth_write_members`), `secure/management/command/cloneProject.php`,
`secure/management/command/importProject.php`,
`secure/management/command/exportProject.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Making a project world-visible is owner-only (locked 2026-07-18)

**Decision**: `setProjectVisibility` is owner-only, in its own category. The
adjacent join-policy setter stays with admins and owners.

**Reasoning**: Visibility is the switch that exposes a project to the entire
internet, which puts it in the same weight class as deleting the project or
transferring it — decisions whose consequences the owner cannot delegate away
and then disown. Join policy is genuinely weaker: admins already adjudicate the
queue by approving and denying requests, so withholding the on/off switch for
that queue from them would be incoherent — they could admit anyone yet not close
the door.

**Alternatives considered**: Both settings owner-only (rejected — the incoherence
above). Both at admin (rejected — world exposure is not an administrative
routine). Couple visibility to join policy (rejected — see the posture matrix
entry above; they are independent and must stay so).

**Source**: `secure/management/command/setProjectVisibility.php`,
`secure/management/config/categories.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

---

## Project architecture and serving (beta.10)

### Every request carries its project as a URL marker (locked 2026-07-09)

**Decision**: A project-scoped management call is addressed as
`/management/p/<projectId>/<command>`; a global call stays
`/management/<command>`. The dispatcher peels the marker, rejects a malformed id
before anything touches the filesystem, runs the permission check against *that*
project, and only then binds the per-request project context — path constants and
all — for the command to read. A project-scoped command called without a marker
is a `400`, not a guess.

**Reasoning**: The project has to travel with the request, because it is both
what the command acts on and what the request is authorised for; anything the
server derives from stored state instead can disagree with what the caller
intended. A literal `p` segment is what makes the URL unambiguous — without it,
a first segment could be either a project id or a command name, and the parser
has to guess. Parsing it in PHP rather than in rewrite rules keeps the behaviour
identical on every server the product supports. Binding the context *after*
authorization, not before, is the ordering that matters: it means an unauthorised
request never has a project bound at all, so there is nothing for a later mistake
to act on.

**Alternatives considered**: A request header (rejected — invisible in logs and
in a browser address bar, and easy for an intermediary to strip or add). No
marker, disambiguating by whether the first segment matches a known command
(rejected — ambiguous the day a project is named like a command). A query
parameter (rejected — the same ambiguity plus caching and logging quirks).

**Source**: `secure/src/classes/TrimParametersManagement.php`,
`secure/src/functions/projectContext.php` (`qs_load_project_context`),
`public/management/index.php`. Behaviour:
[ARCHITECTURE.md §5](ARCHITECTURE.md).

### The "served project" concept is deleted; the web server decides what sits at root (locked 2026-07-19)

**Decision**: QuickSite no longer knows which project is "the" site. There is no
stored pointer naming a served project, no command to change it, and no mirror
layer copying a project's assets into the web root. Internally there is exactly
one serving path, `/p/<id>/`, for every project. Production mapping is a
**deployment** matter: a vhost maps `example.com/` onto a project, and the
installation's own web root stays free for the user's own files.

**Reasoning**: The served pointer was not merely redundant, it was a privilege —
being the served project meant being materialised at the root — and that
privilege produced five distinct problems over the release, including a proven
escalation where any account could create a throwaway project, become its owner,
and repoint the world-facing root at a project it had no membership on,
publishing it. It also cost a dual-write mirror layer, made two people unable to
edit two projects at once, and made "which project am I looking at?" ambiguous
across the panel. Deleting the concept removes the privilege rather than gating
it. Putting the mapping in the web server is the honest home for it: which
hostname serves which site is a deployment fact, and holding deployment facts as
application runtime state is what created the problem. The decisive constraint
was multi-domain — one installation serving several domains, one project each,
cannot be expressed by any single "this one is special" pointer, so no amount of
hardening would have kept the model.

**Alternatives considered**: Keep the pointer and gate the switch to owners of
the target project (built, then superseded by this decision — it closed the
escalation but kept the privilege, the mirror layer and the ambiguity). Serve
everything from `/p/<id>/` with no root serving at all (rejected —
`example.com/p/mysite/` is not a publishable URL; removing root serving would be
a product regression). Keep a pointer purely as a default (rejected — a default
that changes what the world sees is not a default).

**Source**: `secure/src/functions/surfaceB.php`, `public/p/index.php`,
`secure/deploy/apache-vhosts.conf.example`,
`secure/deploy/nginx-vhosts.conf.example`. Behaviour:
[ARCHITECTURE.md §6](ARCHITECTURE.md).

### Generated links are root-relative by default; absolute is reserved for generation-time output (locked 2026-07-24)

**Decision**: In-page links, asset references and script sources are emitted
against a **root-relative** base — `/`, or `/p/<id>/` when the request came in
that way. An absolute base is computed separately and used only where a URL has
to survive outside the page: the sitemap and build output.

**Reasoning**: The whole point of the serving rework is that the same project
renders correctly under several prefixes — at a mapped domain's root, and under
`/p/<id>/` on the authoring host — often on the same day. A root-relative base is
correct under both without knowing which one it is, so a page cached, mirrored or
previewed under a different prefix keeps working. An absolute base bakes one
answer into the output and is wrong the moment the project moves, which is
exactly when nobody re-renders. Where a URL genuinely leaves the page — a search
engine reading a sitemap — relative is meaningless and absolute is required, so
the two forms are computed side by side rather than one being derived from the
other at the point of use.

**Alternatives considered**: Absolute everywhere (the initial recommendation —
rejected: brittle across prefixes and stale after a domain change). Relative
everywhere including the sitemap (rejected — a sitemap of relative URLs is not a
sitemap). Configure the form per project (rejected — a knob for a question that
has one correct answer per output kind).

**Source**: `secure/src/functions/renderBootstrap.php` (`QS_PUBLIC_BASE`,
`QS_PUBLIC_BASE_ABS`), `secure/src/classes/JsonToHtmlRenderer.php` (`processUrl`),
`secure/src/classes/PageManagement.php`. Behaviour:
[ARCHITECTURE.md §5](ARCHITECTURE.md).

### The public base URL is resolved per request and never stored per project (locked 2026-07-24)

**Decision**: The public base resolves through a short chain, first non-empty
wins: an explicit per-call parameter where one exists, then a server environment
variable, then derivation from the request. There is deliberately **no** stored
per-project base URL and no command to set one. A malformed configured value is
logged and skipped rather than fatal, and the request-derived tier always
resolves, so "no base configured" is not a reachable state.

**Reasoning**: A stored absolute base is deployment truth held as QuickSite
runtime state — the same mistake as the served pointer, in miniature. It goes
stale the moment a domain changes, in a way nothing detects, and it adds a write
path and a command whose only job is to restate what the vhost already declares.
The environment variable puts the value where the deployment already lives,
including on shared hosting where it can be set from a directory config file.
Degrading rather than failing on a bad value is the right posture for a rendering
path: a configuration typo should not take a site down when a well-defined
fallback exists, and the log line is what makes the degradation discoverable.

**Alternatives considered**: A stored per-project base with a setter command (the
earlier design — rejected as above; a stored tier can still be added later
without breaking the chain if a real need appears). Hard-fail on a malformed
value (rejected — turns a typo into an outage). Derive from the request only
(rejected — a site behind a proxy that terminates TLS elsewhere needs to be told).

**Source**: `secure/src/functions/renderBootstrap.php`
(`qs_resolve_public_base`, `qs_public_base_normalize`),
`secure/deploy/apache-vhosts.conf.example`. Behaviour:
[ARCHITECTURE.md §6](ARCHITECTURE.md).

### Static sub-resources are served only from a project's `public/` subtree (locked 2026-07-10)

**Decision**: On the `/p/<id>/` path, HTML is live-rendered through the engine
and every static sub-resource is resolved through a controlled passthrough that
canonicalises the requested path and refuses anything landing outside
`secure/projects/<id>/public/`. The project's configuration, its data directory,
its route file, its templates, translations, backups and exports are unreachable
by construction rather than by rule. That passthrough is the only static-serving
path in the product.

**Reasoning**: The naive implementation — map a URL prefix onto the project
directory — hands out the API secrets, the OAuth secrets, the membership file and
the API endpoint definitions to anyone who can type a path, and it does so
silently. The subtree allowlist inverts the default: reaching a secret requires
escaping the jail, rather than protecting one requiring somebody to have thought
of it. Canonicalising before the prefix check is what makes the check meaningful,
since the interesting attacks are all about producing a path that looks inside
and resolves outside. Being the *only* static path is what turned this from a
guard into a guarantee when the mirror layer was deleted — there is no second
route to the same bytes.

**Alternatives considered**: Map the project directory directly and blocklist the
sensitive subdirectories (rejected — a blocklist is wrong the first time somebody
adds a directory). Serve statics from a copy in the web root (that was the mirror
layer — deleted; see the served-project entry above). Rely on the web server's own
rules (rejected — they differ between Apache and nginx, and a misconfigured
deployment would silently expose everything).

**Source**: `secure/src/functions/surfaceB.php`
(`qs_surface_b_resolve_static`). Behaviour:
[ARCHITECTURE.md §7](ARCHITECTURE.md).

### A private project and a nonexistent project are indistinguishable (locked 2026-07-24) (superseded 2026-08-11)

**Decision**: A request for a project the caller may not see returns exactly what
a request for a project that does not exist returns — same status, same body,
same header set, in the same order. Reaching that took closing two residual
differences beyond the status code: the two refusals were emitted at different
points in the boot sequence, so one carried baseline headers the other did not,
and once the sets matched, one header's **position** still differed.

**Reasoning**: An existence oracle on a private project leaks the one fact the
private setting exists to hide. Status parity alone is not parity — anything an
attacker can measure is part of the response, and header presence and ordering
are both measurable with an ordinary HTTP client. The general lesson is worth
keeping: when a refusal is meant to be indistinguishable, the comparison has to
be made on the wire and on everything the wire carries, not on the branch in the
code that looks equivalent.

**Alternatives considered**: Match the status code only (rejected — measured, and
the header set still distinguished the two). Return `403` for private (rejected —
that *is* the oracle, stated explicitly). Accept the oracle because a mapped
production domain refuses the `/p/` prefix anyway (rejected — that narrows it to
the authoring host, and a deployment that skips the vhost has it everywhere).

**Source**: `secure/src/functions/surfaceB.php` (`qs_sb_deny`). Behaviour:
[ARCHITECTURE.md §6](ARCHITECTURE.md).

### Generated artifacts live under their own project (locked 2026-07-19)

**Decision**: Anything a project generates — its runtime scripts, its builds, its
export archives — is written under that project's own directory. Export archives
moved out of a shared installation-wide directory into
`secure/projects/<id>/exports/`.

**Reasoning**: A shared namespace makes containment a filter: every reader has to
remember to restrict itself to its own project's files, and the commands that
forgot streamed one project's export archive to a non-member and deleted another
project's archives while helpfully listing the filenames destroyed. Moving the
files makes containment structural — a caller bound to one project's directory
cannot see another's, whether or not it remembered to filter. This is the same
argument as the URL marker binding above, applied to storage instead of to
targeting: prefer the arrangement where the mistake is impossible over the one
where it is merely prohibited.

**Alternatives considered**: Keep the shared directory and filter by project on
every read (rejected — that is the mechanism that failed). Prefix filenames with
the project id (rejected — still one namespace, still one forgotten filter away).

**Source**: `secure/src/functions/projectPublicArtifacts.php`,
`secure/management/command/exportProject.php`. Behaviour:
[PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

### Project ids are opaque and minted; the display name lives in the project's own config (locked 2026-07-05)

**Decision**: A project's id is its folder name. New projects mint an opaque
`prj_<hex>` id; the human-readable name lives in the project's own configuration
as the site name. There is no central registry mapping ids to names. The
originally-shipped project keeps its historical readable id.

**Reasoning**: The id appears in URLs, in permission checks and as a filesystem
path, so it wants to be short, shape-validated and stable. A display name wants
to be none of those things — it wants to be editable, translated and full of
punctuation. Deriving one from the other means a rename either breaks every URL
and every membership reference, or leaves the id lying about what it names.
Keeping the name inside the project is also what keeps a project self-contained:
copying the directory copies its name with it, and there is no second file to
keep in sync. The cost is honest — a rename that changes the *id* is a cascade
across membership files and per-user mirrors, and is deliberately not offered
yet.

**Alternatives considered**: Slugify the display name into the id (rejected —
renames break URLs and references, and the slug space collides). A central
`{id: name}` registry (rejected — a second source of truth, and a
whole-installation file that every project read would have to open). Sequential
numeric ids (rejected — they leak how many projects an installation has).

**Source**: `secure/management/command/createProject.php`,
`secure/projects/<id>/config.php`. Behaviour:
[PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

### The per-project styled deny page is retired; error pages are configured at the deployment (locked 2026-07-24)

**Decision**: A refused or missing `/p/` request renders a built-in generic page,
or a static file the deployment names through a `QS_ERROR_PAGE_<status>`
environment variable. The variable's value must be root-relative, is jailed to
the document root by canonical-path check, is restricted to `.html`/`.htm`, and is
served with a plain file read — never an include. The page is returned **at the
original status code**; there is no redirect. The 401 and 403 special pages stay
reserved in the page list but dormant.

**Reasoning**: The previous per-project deny page only ever worked by accident:
it borrowed the served project's template, whose stylesheet was served ungated
from the mirrored web root. With the mirror gone, every project asset rides the
same visibility gate that just refused the visitor — so a private project's
"styled" deny page would render and then have its own stylesheet refused,
shipping a broken feature. Rebuilding it would have meant re-creating an ungated
asset path, which is the hole. Serving a static file instead keeps the
customisation without an authenticated render. Never `include`-ing it is
deliberate: a configuration value that can become executable code is an
execution and source-disclosure primitive, and the value comes from the
environment. Preserving the status code matters because a redirect turns a `404`
into a `302` followed by a `200`, which misleads crawlers, monitoring and caches
about what actually happened.

**Alternatives considered**: Rebuild the per-project deny page and serve its
assets ungated (rejected — reintroduces an unauthenticated asset path into a
private project). Redirect to a static error page (rejected — destroys the status
code). Ship QuickSite's own error files at the web root (rejected — the root
stays free for the user's own site).

**Source**: `secure/src/functions/surfaceB.php` (`qs_sb_deny`),
`secure/deploy/apache-vhosts.conf.example`. Behaviour:
[ARCHITECTURE.md §6](ARCHITECTURE.md).

---

## Command surface and audit trail (beta.10)

### Command history is stored per project, in per-project directories (locked 2026-07-24)

**Decision**: The command log is split into one directory per project, plus a
global bucket for commands that have no project. Every reader and the clearing
command require a project and bind it from the authorised marker. The directory
resolver fails closed — a project id that does not pass the shape check returns
nothing rather than a guessed path, so the caller does not log at all rather than
logging somewhere unintended.

**Reasoning**: The log used to be one installation-wide store, and the permission
that reads it is an ordinary project-level administrative grant — which any
account can hold over a project it creates for itself. With no project dimension
in the store, there was nothing for that permission to be scoped *against*: a
self-minted owner could read every project's history and, with the matching
clear command, erase it. Making the store per-project is what gives the existing
permission something to mean; the alternative — a project field on each entry,
filtered at read time — leaves one shared store where a forgotten filter is a
full disclosure, and where "delete my project's history" has to be an entry-wise
delete over everyone else's data. Directories make the containment a path, which
the filesystem enforces whether or not the query remembered to.

**Alternatives considered**: A project field on each log entry, filtered on read
(rejected — one shared store, one forgotten filter from full disclosure; and
scoped deletion becomes a rewrite of a shared file). Keep the store global and
raise the permission (rejected — there is no higher permission; there is no
global tier). Drop history entirely (rejected — it is the audit trail).

**Source**: `secure/src/functions/LoggingManagement.php` (`qs_log_dir`),
`secure/management/command/getCommandHistory.php`,
`secure/management/command/clearCommandHistory.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Log redaction is deny-by-default (locked 2026-07-24)

**Decision**: The log-body sanitiser refuses to record anything it has not been
told is safe. Per-command rules narrow what is kept, and a universal
credential-shaped-key gate runs last over whatever survives — the per-command
cases fall through into it rather than returning early.

**Reasoning**: The sanitiser was allow-by-default: an empty skip list and a
default branch that returned the body untouched. Its only credential-aware rule
named a command that had been deleted a release earlier, so the moment
authentication moved to passwords, plaintext credentials went into the store and
stayed there. That is the failure mode of allow-by-default in one sentence — it
does not fail when the rules are wrong, it fails when the *world* changes and
nobody revisits the rules. Deny-by-default inverts the maintenance burden onto
the side where forgetting is safe: a new command that nobody teaches the
sanitiser about logs less than it could, instead of logging a secret. Making the
universal gate genuinely run last, rather than being skipped by an early return,
is what stops a per-command rule from accidentally re-opening the general one.

**Alternatives considered**: Extend the skip list to cover the new
authentication commands (rejected — fixes this instance and leaves the shape that
produced it). Hash bodies instead of redacting (rejected — destroys the audit
value the log exists for). Stop logging bodies (rejected — the body is usually the
useful part).

**Source**: `secure/src/functions/LoggingManagement.php` (`sanitizeLogBody`).
Behaviour: [COMMAND_API.md](COMMAND_API.md).

### A project-scoped read reports only its own project; installation-wide totals are owner-scoped instead (locked 2026-07-19)

**Decision**: `getSizeInfo` reports the marker project and nothing else, with
absolute filesystem paths removed. The installation-wide picture is served by a
separate global command that aggregates **what the caller owns** — no project
parameter at all, ownership re-resolved from the authoritative membership files
on every call, and no filenames or paths in the payload.

**Reasoning**: The size command sat at the lowest role in the model and returned
every project on the installation, a real backup name and absolute server paths —
undercutting the membership-filtered project list and the deliberate refusal to
ship a user directory, from a command nobody thinks of as sensitive. The fix has
two halves and both matter. A project-scoped command reporting on projects other
than its own is simply mis-scoped, whatever it reports. But the *legitimate* need
for an overview is real, and the safe inverse of "enumerate what exists" is
"aggregate what you own": it is a different question with a different answer
shape, it takes no project parameter so there is nothing to retarget, and it
follows the same pattern as the project list — global, any authenticated caller,
with the **output** filtered.

**Alternatives considered**: Extend the per-project command with an "all
projects" mode (rejected — re-mixes the scopes that were just separated, and a
marker-bound command cannot honestly answer a question about other projects).
Keep the enumeration for owners only (rejected — every account can become an
owner by creating a project). Drop the overview (rejected — the need is real).

**Source**: `secure/management/command/getSizeInfo.php`,
`secure/management/command/getMySpaceUsage.php`,
`secure/src/functions/spaceUsage.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### A measurement cache may cache sizes, but never the set of projects (locked 2026-07-19)

**Decision**: The owner space overview caches measured byte counts with a short
expiry, keyed **by project** rather than by user, with an explicit refresh
parameter and pruning so a measurement cannot outlive its project. The set of
projects the caller owns is re-resolved from the authoritative membership files
on every single call — never from the derived per-user mirror.

**Reasoning**: Splitting what may age from what may not is what makes the cache
safe. A byte count that is five minutes stale is a cosmetic inaccuracy. A
membership set that is five minutes stale is an authorization answer, and a
transfer, a removal or a deletion would not take effect until it expired. Keying
by project rather than by user follows from the same split: a project's size is
the same fact for everyone who can see it, so per-project keys maximise reuse
while each request still reads only the projects it just re-resolved as its own.
Reading membership from the authority rather than the per-user mirror avoids the
stale-pointer class the mirror already caused once.

**Alternatives considered**: Cache the whole per-user report (rejected — caches
the membership set, which is the part that must not age). Never cache (the
initial recommendation — overruled; a cold walk over every owned project is
measurable, and the cost falls on the dashboard's first paint). Cache with
invalidation hooks on every membership change (rejected — as many hooks as there
are ways to change membership, and one missed hook is a stale authorization
answer).

**Source**: `secure/src/functions/spaceUsage.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### The custom-workflow authoring feature is deleted rather than secured (locked 2026-07-19)

**Decision**: The custom-workflow feature is removed: the authoring page, its
editor script, the folder custom workflows were written into, and the two admin
endpoints that wrote files. The shipped **core** workflow catalogue remains and
is the supported surface; the commands that list and lint workflow blocks serve
it and stay.

**Reasoning**: The feature was an unused authoring artifact carrying a real flaw
vector. Its save endpoint was one of only two file-writing endpoints on an admin
surface that performed no authorization at all, and a crafted workflow
specification could turn the in-process command runner into a proxy for a much
wider command set than the runner's allowlist was meant to permit. Faced with
"gate it or delete it", deleting a feature with no users is strictly better than
maintaining a security boundary around one: the boundary has to stay correct
forever, and the feature was not earning that. The authorization fix on the
surrounding surface was made anyway and independently — verified first that
deletion alone would **not** have closed the hole, since most of the endpoints
have nothing to do with workflows and shipped core specifications drive the
runner too. Both changes were needed; neither is a substitute for the other.

**Supersedes**: the entry "Project-to-workflow exporter ships in beta.9" under
*Release shape (beta.9)* above, whose output landed in the custom-workflow
folder this decision removes.

**Alternatives considered**: Gate the save endpoint and keep the feature
(rejected — maintaining a boundary around an unused feature). Keep the folder
read-only for hand-authored files (rejected — leaves the runner escalation via
crafted specifications). Delete the workflow system entirely (rejected — the core
catalogue is used and is not the vector).

**Source**: `secure/admin/workflows/core/`,
`secure/src/classes/WorkflowManager.php`. Behaviour:
[WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md).

### Writing the sitemap configuration is its own command, out of the read grant (locked 2026-07-25)

**Decision**: `getSiteMap` is a pure read. Both of its former write branches move
to a new `setSiteMapConfig` command in the route-writing category, so the write
requires the editor tier and above. The preview and the published file share
extracted logic so the two cannot diverge.

**Reasoning**: `getSiteMap` sat in the lowest role's read grant and was the only
command in that grant containing a filesystem write. What made it more than a
naming inconsistency is what the written file does: it holds the excluded-route
list and the custom URLs that the generated sitemap is built from. So the lowest
tier could persistently change what the **published** sitemap contains — dropping
real routes, injecting URLs — with the damage surfacing later, when anyone else
regenerated it. That is live-site content integrity reached from a read
permission. Splitting the command puts the write where the permission model can
see it, and the shared logic ensures the preview a user approves is the file that
gets written.

**Alternatives considered**: Guard the write branch in place (rejected — leaves a
write inside a command whose category says "read", which is exactly the confusion
that hid it). Move the whole command to the writing category (rejected — reading
the sitemap is a legitimate viewer operation and would have been lost).

**Source**: `secure/management/command/setSiteMapConfig.php`,
`secure/management/command/getSiteMap.php`,
`secure/src/functions/sitemapHelpers.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

---

## First run and installation (beta.10)

### The first account is created in the browser, gated by a token written to disk (locked 2026-07-25)

**Decision**: On an installation with an empty user registry, the setup page
lazily mints a one-time token into a gitignored file and shows the deployer its
**relative** path. Creating the first account requires reading that file from
the server's filesystem and submitting the token. The rule lives at the single
account-creation function as a parameter that **defaults to refusing**, and both
the token check and the empty-registry check are read inside the same write lock
as the creation, so the token cannot be spent twice by concurrent submissions.
The token is verified consumed before its file is removed. Public registration
**never** bootstraps — it refuses on an empty registry regardless of the
self-registration flag. There is no auto-login: the deployer lands on the login
page.

**Reasoning**: Proving filesystem access is the right test for "are you the
person who installed this?", and it needs no credential to pre-exist. Putting the
rule at the mint function rather than in the page is the load-bearing choice: the
default-refuse parameter means any caller that knows nothing about tokens fails
closed, so a future creation path cannot accidentally bypass first-run
protection. Reading both conditions inside the write lock closes the obvious race
— two submissions arriving together must not both see an empty registry. Refusing
to let public registration bootstrap keeps **one** unauthenticated path to the
first account instead of two, which is one posture to reason about rather than an
interaction between two. Showing the path relative rather than absolute matters
because an anonymous visitor can see that page: an absolute path discloses the
server's filesystem layout for no benefit to the legitimate deployer, who is
standing in the directory already.

**Alternatives considered**: Auto-create a default account with a known password
(rejected — a known credential reachable over the web on every installation).
Print copy-and-edit instructions and require hand-hashing a password (rejected —
error-prone, and the deployer ends up pasting a hash they cannot verify). Have the
installer prompt for a username and password and write the account (built first,
then replaced — it worked, but it put credential rules in three places, could not
be exercised without a real interactive console, and shipped a silent
success-reporting failure that a manual run caught; the browser flow keeps one
implementation and one testable path). An unauthenticated first-run page with no
token (rejected — whoever reaches the installation first becomes its owner).

**Source**: `secure/src/functions/setupToken.php`,
`secure/src/functions/AuthManagement.php` (`qs_user_create`),
`secure/admin/templates/pages/setup.php`. Behaviour:
[ADMIN_PANEL.md §9](ADMIN_PANEL.md).

### A login page with no accounts says so and disables its controls (locked 2026-07-25)

**Decision**: The login page detects an **empty registry** — not a missing file —
and, when it finds one, disables its controls and names the route that creates
the first account. The form's POST branch is unchanged and still refuses
uniformly.

**Reasoning**: Detecting emptiness rather than absence is the point: the registry
file can exist and contain nothing, which is the state a partially-completed
setup leaves behind, and a missing-file check would send that deployer to a login
form that can never succeed. Telling them where to go costs nothing here, because
on an installation with no accounts there is no account to enumerate — the fact
being disclosed is that setup is incomplete, which the setup page itself
announces. Leaving the POST branch untouched keeps the uniform-refusal posture
intact for every other state, so the empty-registry hint cannot become a
back-door oracle once accounts exist.

**Alternatives considered**: Redirect straight to setup (rejected — a redirect
from the login route is surprising, and it hides the state instead of explaining
it). Leave the login page unchanged (rejected — the deployer is left guessing at
a form that cannot work).

**Source**: `secure/admin/templates/pages/login.php`. Behaviour:
[ADMIN_PANEL.md §9](ADMIN_PANEL.md).

---

## Render and input hardening (beta.10)

### URL safety is a value-based scheme allowlist on every URL-sink attribute (locked 2026-07-03)

**Decision**: Any attribute whose value is fetched or navigated to is checked
against an allowlist of `http`, `https`, `mailto` and `tel`, plus relative,
anchor and protocol-relative forms; anything else is replaced with `#`. Leading
whitespace and control characters are trimmed before the check and embedded
control characters reject the value. The check applies by **attribute role**, not
by a fixed list of attribute names, so namespaced and less-common sinks are
covered. One shared policy class implements it and both the renderer and the
compiler consume it.

**Reasoning**: A blocklist of dangerous schemes is a list of the ones somebody
thought of, and browsers keep supplying more; an allowlist of the four schemes
authors actually use in content is complete by construction. Checking the value
rather than trusting the attribute name is what caught the real leak — a
namespaced link attribute inside inline SVG carried `javascript:` straight
through a name-based filter. Stripping leading whitespace and control characters
before deciding matters because that is precisely how the dangerous value is
disguised. Sharing one class between the two rendering engines is not
housekeeping: divergence between them means a payload that the live renderer
blocks and the compiler emits, which is the worst possible split.

**Alternatives considered**: Blocklist `javascript:`, `data:` and `vbscript:`
(rejected — incomplete by nature; the existing one had already gone stale). Check
by attribute name (rejected — measured to miss namespaced sinks). Escape rather
than replace (rejected — an escaped dangerous scheme is still a dangerous
scheme).

**Source**: `secure/src/classes/UrlPolicy.php`,
`secure/src/classes/JsonToHtmlRenderer.php`,
`secure/src/classes/JsonToPhpCompiler.php`. Behaviour:
[ARCHITECTURE.md §7](ARCHITECTURE.md).

### Share the policy, differ the action: writers reject, renderers neutralise (locked 2026-07-04)

**Decision**: The tag allowlist and the URL policy each live in one place. The
commands that **store** structure call a shared validator and refuse the write
with an explicit error. The renderer and the compiler enforce the same rules
independently and silently drop or neutralise what fails. SVG stays a decorative
container: the graphic primitives are not authorable, and the allowlist is
enforced rather than advisory.

**Reasoning**: The render layer is the boundary that must hold, because stored
structure can arrive by paths no writer controls — an import, a restored backup,
a hand-edited file, or a future runtime reading the same JSON. So the renderer
enforces regardless. But a silent drop is a terrible author experience and leaves
the bad value in storage, exported and backed up, waiting for a consumer with a
weaker gate. Rejecting at write time keeps stored structure clean and tells the
author immediately. The two layers need different *actions* precisely because
they answer different questions — "may this be saved?" versus "may this be
emitted?" — but they must never differ on the *rules*, which is why both read the
same registry. Tags lean harder on the writer half than URLs do, because a
dangerous URL can be sanitised into something inert while a dangerous tag can only
be dropped, so the edit-time error is the only feedback the author gets.

**Alternatives considered**: Writer checks only (rejected — misses every
non-writer storage path, which is where imports and backups live). Renderer
checks only (rejected — silent drops, dirty storage, no author feedback). Make
SVG primitives authorable with per-element sanitising (rejected as low-value,
high-surface; if inline SVG matters later, the deliberate feature is uploading a
whole SVG asset through a sanitiser, not exposing primitives as editor nodes).

**Source**: `secure/src/classes/TagRegistry.php` (`isRenderable`),
`secure/src/functions/nodeParamPolicy.php` (`firstUnsafeParam`),
`secure/src/classes/CallTransformer.php`. Behaviour:
[ARCHITECTURE.md §7](ARCHITECTURE.md).

### The `style` attribute is HTML-escaped only — residual CSS injection accepted for beta.10 (locked 2026-07-03)

**Decision**: Inline `style` values are HTML-escaped and not otherwise parsed or
filtered. The residual — CSS injection, including `@import` and other
network-reaching CSS constructs by an author who can already store structure — is
accepted for beta.10 and recorded here rather than fixed.

**Reasoning**: The value is XSS-inert: no browser executes script from a `style`
attribute, so this is not the same class as the URL and tag findings, both of
which were fixed. What remains is an author with structure-writing permission
being able to make a page reach out over the network from CSS — real, bounded,
and requiring a permission that already allows far more direct mischief. Filtering
CSS properly means a CSS parser in the render hot path, which is a meaningful
correctness and performance risk to take for a threat whose precondition is
already trusted. Documenting an accepted risk explicitly is the honest posture;
the alternative is a reader assuming it was considered and handled.

**Alternatives considered**: Parse and filter CSS values, blocking `url()` with
non-allowlisted schemes and `@import` (deferred — real defence, real cost, and
the precondition is a trusted author). Strip `style` entirely (rejected — breaks
legitimate authoring). Say nothing (rejected — an undocumented accepted risk is
indistinguishable from an oversight).

**Source**: `secure/src/classes/JsonToHtmlRenderer.php` (generic attribute
branch) and its compiler counterpart. Behaviour:
[ARCHITECTURE.md §7](ARCHITECTURE.md).

### Path safety is enforced in the shared resolvers, not in each command (locked 2026-07-05)

**Decision**: The guard against traversal in page and project paths lives in the
shared path resolvers and in one shared project-name validator, which every
caller already goes through. A route containing a traversal segment resolves to
nothing, and the caller's existing not-found branch handles it.

**Reasoning**: The traversal findings were the same defect repeated across a
family of commands — the shape of a bug that comes from every command building
its own path. Fixing them individually fixes exactly those commands and leaves
the next one to reintroduce it, because the next author will copy a neighbour
that predates the fix. Putting the check in the resolver covers every current
caller and every future one, and it costs nothing in expressiveness: legitimate
nested routes contain slashes but never traversal segments, so no valid input is
refused. Returning nothing rather than throwing lets each caller fall into the
not-found path it already has, so the change is behavioural at the edge only.

**Alternatives considered**: Add the guard to each affected command (rejected —
more code, misses future callers, and the next copy-paste reintroduces it).
Canonicalise and compare against a root in each command (rejected — same
duplication, and easy to get subtly wrong per site).

**Source**: `secure/src/functions/utilsManagement.php` (`routePathIsSafe`,
`resolvePageJsonPath`, `resolvePagePhpPath`),
`secure/src/functions/PathManagement.php` (`is_valid_project_name`). Behaviour:
[ARCHITECTURE.md §7](ARCHITECTURE.md).

### Outbound fetches are checked at fetch time, not at store time (locked 2026-07-05)

**Decision**: One shared policy guards every server-side outbound request —
registered API fetches, endpoint testing, the OAuth back-channel and
download-by-URL uploads. It validates the URL, allows only `http` and `https`,
resolves the host and refuses if **any** resolved address is internal, and pins
the validated address for the actual connection so DNS cannot change between
check and connect. Redirect following is disabled on the paths that had it, and
retained-with-revalidation where redirects are genuinely needed. A `development`
environment setting lifts the internal-address block — never the scheme
allowlist — so authors can work against local APIs. Storing an internal or
otherwise odd URL is deliberately **not** blocked.

**Reasoning**: Checking at fetch time is what makes the guarantee complete: a URL
can be stored by one path, imported by another, or edited on disk, and only the
fetch is common to all of them. Store-time validation would additionally have to
refuse URLs that are perfectly legitimate on a development installation, so it
buys defence-in-depth at the cost of wrongly rejecting valid configuration.
Pinning the resolved address closes the rebinding window that makes a check-then-
connect design defeatable. Following redirects is where an allowed target hands
control back to the attacker, so the default is not to. The environment gate is
scoped narrowly on purpose — an author needs to reach `localhost`, they never
need `file://`.

**Alternatives considered**: Validate at store time as well (rejected as above;
the shared policy is available to reuse if a later pass wants it). Follow
redirects with per-hop revalidation everywhere (kept only where redirects are
required; rejected as the default — more code on a hot path for a feature few
registered APIs need). A blocklist of known metadata endpoints (rejected — an
enumeration of today's cloud providers).

**Source**: `secure/src/classes/OutboundUrlPolicy.php`,
`secure/src/functions/serverFetch.php`,
`secure/management/config/environment.php.example`. Behaviour:
[ARCHITECTURE.md §8](ARCHITECTURE.md).

### Deployment targets outside the installation must be declared (locked 2026-07-05)

**Decision**: `deployBuild` writes only inside the server root or into a
directory listed in a deploy-roots configuration file. The default file is
empty, which preserves deploying to the installation itself and refuses
everything else. The prefix comparison is boundary-safe, so a sibling directory
whose name merely starts with an allowed path is not accepted.

**Reasoning**: A command that copies a built site to an operator-supplied
absolute path is an arbitrary-write primitive unless something bounds it, and the
person best placed to say which directories are legitimate deployment targets is
the deployer, not QuickSite. An empty default keeps the common case — deploy to
where you already are — working with no configuration, so the guard costs nothing
to the installation that never needed it. Boundary-safe comparison is called out
because naive prefix matching is the standard way this class of allowlist is
defeated.

**Alternatives considered**: Allow any absolute path (the previous behaviour —
rejected: arbitrary write). Restrict to the server root with no configuration
(rejected — deploying elsewhere is a real use). Derive allowed roots from the web
server configuration (rejected — not readable portably, and not the same
question).

**Source**: `secure/management/command/deployBuild.php`,
`secure/management/config/deploy-roots.php.example`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

---

## Runtime state (beta.10)

### Runtime state is JSON, alongside the author's website data (locked 2026-07-11)

**Decision**: Mutable state the engine writes at runtime — session records,
login and registration throttles, per-project membership — is stored as JSON,
not as PHP arrays. Static engine and admin configuration that the operator edits
by hand stays PHP. Each JSON store is written through a single owner function
using a lock plus write-to-temp-and-rename, and each is excluded from version
control.

**Reasoning**: This extends the data-shape rule recorded under project
conventions above rather than contradicting it. That rule split *the author's
website data* from *QuickSite plumbing*; runtime state is a third thing, and the
deciding property is that nothing writes it by hand. A PHP array file has to be
serialised through a code generator and re-executed to be read, which makes an
interrupted or concurrent write a **parse error in an executable file** rather
than a malformed data file — and one of these stores is the authority every
permission check reads. JSON keeps a corrupt write inert and detectable. The
supporting observation, learned the hard way during the release: regenerating a
PHP config file through a serialiser silently destroys the comments that document
it, so a machine-written PHP file and a human-maintained one are genuinely
different kinds of file and should not share a format.

**Alternatives considered**: PHP arrays for consistency with the other config
files (rejected — a corrupt runtime write becomes a fatal parse error, and
serialised regeneration destroys documentation). SQLite (rejected — a dependency,
and the file-based model is the product's premise). One combined state file
(rejected — unrelated write frequencies contending on one lock).

**Source**: `secure/src/functions/SessionManagement.php`,
`secure/projects/<id>/config/members.json`,
`secure/src/functions/AuthManagement.php` (`qs_members_mutate`). Behaviour:
[PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

---

## File and archive boundaries (beta.10)

### A project's `public/` holds the website as it is; hidden paths are refused (locked 2026-07-31)

**Decision**: No segment of a path may begin with a dot. The rule applies where a
visitor's request is served, where an uploaded archive is unpacked into a project,
and where a build copies files into a directory a deployment publishes. It is
deliberately "no segment may *start* with a dot", never "no dots" — a `.css` or
`.png` file must keep its extension. Anything a deployment genuinely needs at a
hidden path — an ACME challenge, server configuration — is served from the
deployment's own web root, which never enters QuickSite's passthrough.

**Reasoning**: The previous check inspected only the final path segment, which
refuses `style/.htaccess` and serves `.hidden/anything` — so a hidden *directory*
published everything inside it. `.git/` is the case that matters: its contents
carry ordinary permitted extensions, so an extension allowlist waves them through,
and what leaks is the source history plus whatever was committed beside it. Making
the rule per-segment rather than per-basename is what closes the directory case,
and stating it as a property of the *path* rather than a list of forbidden names
means a `.svn/` or `.idea/` directory never has to be enumerated. The counterpart
half of the decision matters as much: a project's public directory is the website,
not a deployment surface, so the correct home for hidden deployment files is one
level up, outside the product. Declining to carve out exceptions is what keeps the
rule cheap to state and impossible to get wrong.

**Alternatives considered**: A blocklist of known hidden directories (rejected —
an enumeration of today's tooling, and a new tool ships a new leak). Refusing only
hidden *directories* while still serving hidden files (rejected — `.htaccess` and
`.env` are exactly the files worth refusing). An allowlist of hidden paths a
deployment may serve, so `/.well-known/` could pass through QuickSite (rejected —
it is a feature nobody asked for, and the web server already serves that path from
its own root without QuickSite running at all).

**Source**: `secure/src/functions/filePolicy.php`
(`qs_policy_has_hidden_segment`), `secure/src/functions/surfaceB.php`
(`qs_surface_b_resolve_static`), `secure/management/command/importProject.php`.
Related: *Path safety is enforced in the shared resolvers, not in each command*
(see above). Behaviour: [ARCHITECTURE.md §7](ARCHITECTURE.md),
[COMMAND_API.md](COMMAND_API.md).

### What may be imported and what may be published are allowlists, not blocklists (locked 2026-07-30)

**Decision**: Both file boundaries name the extensions they accept, rather than the
ones they refuse. Import additionally checks each entry's **content** against what
its name claims — a signature for binary formats, parseable JSON for `.json`, no
PHP opening tag for text, sanitisation for SVG. Both lists live in a
deployer-editable config file created from a shipped example, and an override
*replaces* the default list so an installation can narrow as well as widen.

**Reasoning**: A blocklist has to enumerate every dangerous spelling; an allowlist
only has to enumerate the safe ones, and the safe set is short and known — it is
derived from what a legitimate export can actually contain plus the asset types the
engine already accepts at upload. The blocklist this replaced failed in three
independent ways at once, which is the argument in miniature: it matched one
control filename case-sensitively on a platform where both the filesystem and the
web server are case-insensitive, it did not list every executable spelling, and it
never looked at contents, so a PHP payload named `.png` passed unexamined. Checking
content as well as extension is what stops a name from lying about what a file is;
neither check alone is sufficient, because an allowlisted extension is exactly what
an attacker will choose.

**Alternatives considered**: Extend the blocklist with the missing spellings and
make it case-insensitive (rejected — it fixes the three known holes and leaves the
shape that produced them). Content sniffing alone, with no extension rule (rejected
— the extension is what the web server dispatches on, so it has to be constrained
in its own right). A single shared list for both boundaries (rejected — see the
entry below; they are two different questions that happen to have similar answers
today).

**Source**: `secure/src/functions/filePolicy.php` (`qs_import_allows_extension`,
`qs_import_validate_content`, `qs_publish_allows_extension`),
`secure/management/config/import-policy.php.example`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### The publish boundary lives at the build, not in the shared copier (locked 2026-07-30)

**Decision**: The publish allowlist is applied by the build's own copy routine, at
the moment a file stops being project data and becomes something a web server hands
to the public. It is not applied inside the generic recursive copier that other
callers share, and it is a separate list from the import allowlist even where the
two currently hold the same entries. Copies that do not cross into a served
directory — translation files landing in a secure sibling folder, for instance —
deliberately keep the unfiltered copier.

**Reasoning**: A file existing inside a project and a file being published are two
different decisions, and collapsing them either over-filters or under-filters. The
under-filtering case is the one that was live: import filtered, the build did not,
and a hostile file that got past import rode an ordinary build into the web
server's document root. The over-filtering case is just as real — putting the
filter inside the shared copier would silently drop files for callers whose
destination nobody serves, which is a data-loss bug wearing a security fix's
clothes. Placing the gate at the boundary it describes also means the gate is
findable: someone reading the build knows what the build publishes, without
tracing into a utility. Keeping the two lists separate costs one duplicated array
and buys the ability to diverge later without unpicking a shared assumption; a
deployer who narrows what may be published does not thereby narrow what may be
imported.

**Alternatives considered**: Filter inside the shared copier (rejected — it has
callers whose destination is not served, and it would drop their files). Filter at
deploy time instead of build time (rejected — the built artifact is downloadable in
its own right, so it must be clean before deploy is reached). One list for both
boundaries (rejected as above).

**Source**: `secure/src/functions/filePolicy.php`
(`qs_copy_publishable_directory`), `secure/management/command/build.php`,
`secure/src/functions/FileSystem.php` (the generic copier, deliberately
unfiltered). Behaviour: [COMMAND_API.md](COMMAND_API.md).

### The absolute byte caps are the archive ceiling; the compression ratio only prices the attack (locked 2026-08-01)

**Decision**: An uploaded archive is bounded by four limits read from its own
headers before anything is decompressed — entry count, total uncompressed size,
per-entry uncompressed size, and per-entry compression ratio. The two byte caps are
treated as the real ceiling and are set conservatively; the ratio is set generously
(300:1), because it governs how *cheaply* the ceiling can be reached, not how high
the ceiling is. All four are overridable per key in a deployer-editable file, and
all four are documented in the reference docs rather than only in that file's
comments.

**Reasoning**: A tight ratio refuses QuickSite's own export. A page tree is
repetitive by construction — every node repeats its tag, classes, styles and
children — so a large structural document deflates far better than hand-written
text, and the engine was producing archives it would then refuse to read back.
Raising the ratio does not widen what an archive may allocate, because whatever an
entry's ratio it still cannot exceed the per-entry and per-archive byte caps;
relaxing the ratio makes an attack cheaper to mount while leaving the server's
worst case identical. That asymmetry is the whole reasoning: it is safe to be
generous about upload cost and strict about allocation, and confusing the two is
what set the number wrong in the first place. Reading the limits from the central
directory before decompressing is retained deliberately — decompressing is the cost
being defended against, so the decision has to be reachable without paying it.

**Alternatives considered**: Exempt structural JSON from the ratio check by name
(rejected, and the reason generalises: archive entry names are authored by whoever
builds the archive and the source is public, so a name-based exemption disables the
check for anyone who reads the code, while looking more targeted than simply
setting the number correctly). Apply matching limits at export so the engine cannot
produce what it will not accept (rejected for this release — it constrains a
download, which is not the surface under attack, and the round trip is proven
green). Remove the ratio check and rely on the byte caps alone (rejected — the
ratio is the only one of the four that catches a bomb before its size is paid for).

**Source**: `secure/src/functions/filePolicy.php` (`qs_archive_limits`),
`secure/management/config/import-policy.php.example`. Behaviour:
[COMMAND_API.md](COMMAND_API.md) (*Archive import limits*).

---

## Data, generated code and containment (beta.10)

### A write that cannot be encoded aborts before the file is touched (locked 2026-08-02)

**Decision**: Every JSON write in the engine goes through one shared writer that
encodes first, and on failure logs the reason and returns without opening the
target. The existing file is left byte for byte unchanged. Commands that accept
free-text carrying the offending bytes additionally refuse at their own boundary,
so the caller gets a bad-request naming the field rather than a server error.

**Reasoning**: The failure this prevents looks like success at every individual
step, which is why guarding it locally never worked. The encoder returns `false` on
input it cannot represent; writing `false` writes the empty string and reports zero
bytes written; zero is not `false`, so every `=== false` guard in the codebase
passes and the command answers a 2xx over a file it just emptied. A variant with a
trailing newline writes one byte and reports one byte, which passes just as
cleanly. The mechanism had been discovered and patched three separate times in
three places without anyone generalising it, and the third discovery is what
settled the shape: one writer, adopted at every unguarded site, rather than
fifty-five local patches whose completeness nobody can demonstrate. Refusing before
touching the file — rather than writing and repairing — is the part that makes the
guarantee simple to state: a failed write changes nothing.

**Alternatives considered**: Check the encoder's return at each call site (rejected
— fifty-five sites, and the next writer added forgets). Validate encoding at the
input boundary only (kept as an *additional* layer for the commands that carry
prose, rejected as the primary defence — a value can also arrive by import, by
merge, or from a file edited on disk, and only the write is common to all of them).
Write and then verify the result, rolling back on mismatch (rejected — it doubles
the I/O and leaves a window where the file on disk is wrong).

This extends *One writer for the membership file, with an invariant backstop that
aborts* (see above) from one file to every JSON store in the engine.

**Source**: `secure/src/functions/utilsManagement.php` (`qs_json_write`),
`secure/src/functions/AuthManagement.php` (`qs_members_mutate`),
`secure/src/functions/SessionManagement.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Generated code takes its variable parts at runtime, never baked in at generation (locked 2026-08-01)

**Decision**: Code the engine writes to disk is generated from a non-interpolating
template, and anything that varies per page or per project is read at request time
from the runtime rather than substituted into the source at generation time. One
canonical generator serves every path that produces a given artifact — creation and
import share it — so there is no second copy to drift.

**Reasoning**: An interpolating template turns every value it embeds into a
data-to-code boundary, and the value that crossed it here was an archive entry
name: the importer listed the directory it had just unpacked, took each filename,
and substituted it into a quoted string in generated PHP. A name chosen to close
that string made the remainder live code in a file the renderer later includes. No
PHP opening tag was needed, because the injection point was already inside PHP —
which is exactly what kept the payload inside the character set a Windows filename
accepts. Removing the interpolation beats escaping it: an escaper is a thing that
can be wrong, or be applied to five of six substitutions, whereas a template with
no substitutions is inert by construction and provably identical for hostile and
benign input. The runtime already exposed everything the generated file needed, so
nothing was lost. The single-generator half of the decision is the other lesson:
the duplicate generator had also drifted functionally, emitting an older wrapper
shape than the one creation produced.

**Alternatives considered**: Escape the interpolated value (rejected — it leaves a
data-to-code boundary that has to stay correct forever, and it does not make the
output name-independent). Validate archive entry names against a strict shape
(kept in reserve as defence in depth, rejected as the fix — it makes the hostile
name unable to land, but leaves the generator able to execute one if it ever does).
Generate the wrapper at request time instead of writing it (rejected — the file on
disk is what the routing layer resolves, and generating per request trades a
one-time cost for a per-request one).

**Source**: `secure/src/functions/utilsManagement.php`
(`generate_page_template`), `secure/management/command/importProject.php`,
`secure/management/command/createProject.php`.

### A project-scoped target is bound through one shared helper that fails closed (locked 2026-07-31)

**Decision**: Every project-scoped command resolves its target by calling one
shared binder. With no marker in the URL the binder refuses; with a marker, a
matching field in the body is accepted as an echo and a disagreeing one is refused,
whichever field carries the disagreement. No command reads the project from the
request body itself, and no command implements this inline.

**Reasoning**: This is the enforcement idiom for the rule recorded above (*The URL
marker is the sole source of a project-scoped target; a body value may only echo
it*), and it is a separate decision because the rule was already correct while
seven commands still got it wrong. Each had copied the containment inline and
wrapped it in "if a marker is present" — so an *absent* marker skipped the check
entirely and the body value survived. That is fail-open where the shared helper is
fail-closed, and the commands concerned were the ones that export, clone, back up,
restore and delete. Nothing could reach them without a marker at the time, but that
depended on two hand-maintained lists staying correct, and the whole point of a
containment rule is that it does not rest on an inventory. Consolidating deleted
roughly eighty-five lines of duplicated logic and made the refusal message uniform,
but the load-bearing gain is that there is now one place where "which project is
this" is answered, and it answers "none" rather than "whatever you said".

**Alternatives considered**: Fix the seven inline copies in place (rejected — it
preserves seven places for the next divergence). Have the dispatcher bind the
project and pass it down (rejected for this release — global commands legitimately
have no marker, so the dispatcher cannot make binding universal without a second
concept for the exceptions). Refuse a body project field outright rather than
accepting a matching echo (rejected — existing integrations send it, and an echo
that must agree is strictly safer than one that is ignored).

**Source**: `secure/src/functions/projectContainment.php`
(`qs_bind_marker_project`). Behaviour: [COMMAND_API.md](COMMAND_API.md),
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### A snippet saved outside a project belongs to its author, not to the installation (locked 2026-08-07)

**Decision**: Snippets come from three tiers — core (shipped, read-only), personal
(the author's own, reusable across every project they work on), and project. There
is no installation-wide tier. The author's user id is a path segment in the
personal tier, allowlisted against the exact minted shape rather than sanitised, so
an unrecognised value yields nothing and every caller skips the tier instead of
falling back to a shared location. The older scope name survives as an alias
resolving to the same place. Files already sitting in the old shared directory are
not served and not deleted; they are logged once, by name, for an operator to
relocate.

**Reasoning**: The old tier was labelled "available to all projects", and it meant
it: a snippet saved there was listable, readable, insertable and deletable by any
editor on any project, including accounts sharing nothing with the author. Framing
this as a missing gate produced bad options — filter the shared directory by
something, add an ownership field, restrict who may write to it. Framing it as a
naming error produced the right one: the word "global" was the bug in the mental
model as much as in the storage, and what an author actually wants from a snippet
saved outside a project is *their own library*, which is per-user by definition. An
identifier that becomes a path segment is allowlisted rather than escaped, because
the property worth having is that it cannot describe a path at all. The duplicate-id
check is deliberately narrowed to the caller's own library: checking a proposed name
against everyone's would answer "does this user own a snippet by this name" to
anyone willing to guess, which is the same existence-oracle shape closed elsewhere
in this release. The legacy files are logged rather than migrated because nothing
records who created them — assigning them to an account would be a guess, and
continuing to serve them to everybody is the defect itself.

**Alternatives considered**: Keep the shared tier and filter it by an owner field
written at save time (rejected — it leaves every existing file ownerless and keeps
a shared namespace nobody needs). Keep it and restrict writing to a high role
(rejected — it does not stop the reading, which is the reach that was proven).
Delete the legacy files on upgrade (rejected — destroying an author's work to close
a hole they did not open). Migrate them into the first account (rejected — a guess
presented as a migration).

**Source**: `secure/src/functions/SnippetManagement.php`,
`secure/management/command/createSnippet.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md) (*Snippet tiers*),
[PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md),
[ADMIN_PANEL.md](ADMIN_PANEL.md).

---

## Responses and environment (beta.10)

### Paths in a response are relative, scrubbed at one chokepoint, in every environment (locked 2026-08-07)

**Decision**: A response never publishes where the installation sits on disk. A
path inside the targeted project is reported relative to that project; a path
elsewhere under the installation is reported relative to the installation root,
with its real folder names; a root itself is reported as a single dot rather than
an empty string. A path outside the installation is left untouched. The rule is
enforced once, where a response is assembled, and it is **not** gated on the
development/production setting.

**Reasoning**: Three separate choices, each with its own argument. *Relative rather
than removed*, because a path in a response is useful — the scrubbed value rejoins
to the project root and still resolves on disk, so it stays actionable; real folder
names disclose the product's own documented structure, never where the product is
installed. *One chokepoint rather than every emission site*, because a per-site fix
is one whose completeness cannot be demonstrated: a static sweep of the candidate
sites produced both false positives and false negatives, and enforcing at assembly
also covers the readers that never send an HTTP response at all — the internal
admin API and the in-process command runner — which a per-site sweep would have
missed entirely. *Not environment-gated*, because an exception message is
diagnostics and is rightly dev-gated where it is produced, whereas a `file` field in
a successful response is **contract**; a contract that changes shape between
development and production is how environment-only defects are made. Leaving paths
outside the installation alone is the deliberate limit: deciding by shape that a
leading slash means a filesystem path would rewrite every route, asset and endpoint
URL the product returns, so that case is asserted by test rather than guessed at by
rule.

**Alternatives considered**: Strip paths entirely (rejected — callers use them, and
an empty field is indistinguishable from a missing one). Fix each emission site
(rejected as above). Gate the rewrite on production so development keeps absolute
paths for debugging (rejected — divergent contracts). String-replace the install
constant, which is what a few sites already attempted (rejected, and those
attempts were deleted: one path is assembled from a forward-slash document root
glued to a separator-joined project path, so it reaches the response with mixed
separators and no literal replacement of the constant can match it — which is why a
command that appeared to handle this was shipping absolute paths anyway).

**Source**: `secure/src/functions/publicPaths.php`,
`secure/src/classes/ApiResponse.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md), [ARCHITECTURE.md §3](ARCHITECTURE.md).

### One environment gate, dependency-free, and every failure path answers production (locked 2026-07-31)

**Decision**: "Is this a development installation?" is answered by one function
that depends on nothing and locates its own configuration relative to itself, so it
can still be asked after the bootstrap has failed. Every failure path resolves to
*production*: an absent file, an empty one, a file that is not PHP at all, a syntax
error, a wrong shape, an unrecognised value. A malformed configuration file
degrades to the safe default rather than taking the request down.

**Reasoning**: The question had two implementations and could be answered
differently within a single request, which makes every behaviour that depends on it
— error verbosity, the outbound-request internal-address allowance — undecidable by
reading either site. Resolving to production on every failure is the only defensible
default for a switch whose *permissive* setting is the exceptional one. Two hazards
had to be handled rather than one, and both are unusual enough to be worth
recording: a parse error in a required file is not suppressible by the calling
code, so it is caught explicitly; and requiring a file that is not PHP *echoes its
contents*, so the require happens inside a discarded buffer — without which a broken
configuration file prepends its own bytes to the response, which is exactly the
byte-order-mark defect fixed alongside it. Locating the config relative to the
function rather than through the installation constant is deliberate: it is what
lets a fatal handler ask the question after the bootstrap that would have defined
that constant has already failed.

**Alternatives considered**: Read an environment variable instead of a file
(rejected — a deployment can lose an environment variable silently, and the
file-based model is the product's premise). Default to development when unset, on
the grounds that an unconfigured install is probably a laptop (rejected — an
unconfigured install is equally probably a hurried deployment). Let a malformed
config fail the request loudly (rejected — a deployer's typo should not take every
import and every page down; it is logged instead).

**Source**: `secure/src/functions/environment.php` (`qs_is_development`),
`secure/management/config/environment.php.example`. Behaviour:
[ARCHITECTURE.md](ARCHITECTURE.md).

### Internal detail belongs in the log, never in the response body (locked 2026-07-31)

**Decision**: A fatal error produces a generic envelope with a server-error status,
identical in shape wherever it happens. One shared handler serves every entry point
— the management dispatcher, the admin API, the admin page surface — rather than a
copy per dispatcher, and it registers the same discipline everywhere: error display
off outside development, the detail written to the error log, nothing of it in the
response.

**Reasoning**: The two dispatchers disagreed, and the disagreement was the defect:
one converted a fatal into a proper envelope while the other answered a success
status with the raw error text and an absolute filesystem path in the body, and the
page surface registered nothing at all, so a fatal there returned a full stack trace
to any signed-in caller. Extracting the working handler rather than copying it is
what makes "identical in shape" true by construction instead of by vigilance —
three entry points needing three different output shapes (an envelope, an HTML page,
a plain error) is an argument for one handler with three shapes, not for three
handlers. Putting the detail in the log rather than the response is the older half
of the rule and needs little defence; what this locks is that it holds at *every*
entry point, including the ones nobody thinks of as an API.

**Alternatives considered**: Keep per-dispatcher handlers and fix the failing one
(rejected — it leaves two implementations of one policy). Return the detail in
development only (kept for the *message*, which is dev-gated where it is produced;
rejected for the response *shape*, per the entry above). Assert the required
error-display setting at runtime (rejected — after the handler registers there is
nothing left for an assertion to catch, and it would fail working installations to
cover only the window before registration; recorded as a deployment note instead).

**Source**: `secure/src/functions/errorHygiene.php`,
`public/management/index.php`, `secure/admin/AdminRouter.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Cross-origin access ships closed (locked 2026-07-31)

**Decision**: The shipped configuration example lists **no** allowed origins, and a
configuration with no cross-origin block at all resolves the same way. An
installation names the origins it actually serves. A wildcard entry is still
honoured if a deployer writes one, and the example says plainly what it means.

**Reasoning**: The example shipped a wildcard, so every new installation answered
any origin that asked, from any visitor's browser — a default nobody chose and most
deployers would never notice. Same-origin calls, which is what the admin panel and
any page this installation serves are, are recognised before the list is consulted,
so an empty list costs the common case nothing: the setting only ever governs
*other* sites calling this API. That asymmetry is what makes closed the right
default rather than merely the cautious one — the permissive value buys nothing
until someone has a specific integration, and at that point they know its origin.
Keeping the wildcard *available* rather than removing it is deliberate: there are
legitimate uses, and a setting that silently ignores what a deployer wrote is worse
than one that does what it says.

**Alternatives considered**: Keep the wildcard and warn in the documentation
(rejected — a default that needs a warning is the wrong default). Remove wildcard
support entirely (rejected — it has legitimate uses, and refusing to honour a
written setting is its own surprise). Derive the allowed origins from the
installation's configured public base (rejected — that origin is already allowed as
same-origin; the list exists for the ones that are not).

**Source**: `secure/management/config/auth.php.example`,
`secure/src/functions/AuthManagement.php`. Behaviour:
[ARCHITECTURE.md §3](ARCHITECTURE.md).

### The browser session IS the session — rotation dropped (locked 2026-08-10)

**Supersedes**: *Access token plus refresh token, with rotation and reuse
detection* and *The admin panel holds one session; the access token lives in
memory only* (both above).

**Decision**: A login establishes a **PHP session** and nothing else. There is no
access token, no refresh token, no rotation, no session family and no server-side
token store. The session cookie is the credential; the response also returns one
**per-session token**, which every API call sends back as `Authorization: Bearer`.
Both halves are required on every call.

**Reasoning**: The pair-and-rotate design was correct for a system whose
credential travelled only in a header. QuickSite's does not: the admin panel is
already a PHP-session holder, the preview iframe is a plain navigation that can
carry no header at all, and the rotation machinery existed mostly to keep a
header-borne credential short-lived. Once the session cookie carries identity,
that machinery is answering a question nobody is asking any more — and it was not
free: a 228 KB token store that was 99.4% orphaned, a reuse-detection window that
could log the victim out, and two places (the cookie and the store) that had to
agree about who was signed in.

The per-session token stays, in a smaller role. Cookie-only authentication would
have handed every page on the internet the ability to drive this API through a
visitor's browser, because browsers send cookies on cross-site requests
automatically. Requiring a value the caller can only have obtained by *reading a
page of the session* closes that: another origin can neither read the page nor set
an `Authorization` header cross-origin without a preflight this installation
refuses. The token is strictly weaker than what it replaces — on its own it
authorizes nothing — which is the point: leaking it is no longer a compromise.

**Alternatives considered**: Keep the pair and only change the preview cookie
(rejected — leaves the store, the rotation and the two-owner problem in place to
serve a header path the working deployments do not use). Make the bearer token the
PHP session id (rejected — page-embedding it would undo the HttpOnly protection
the cookie exists for, and turn an XSS from "steals a short-lived token" into
"steals the session"). A signed, self-contained token with no store (rejected —
that is construction where deletion was available, and it re-creates the problem
of a page-embedded credential that works on its own). A distinct `X-QS-CSRF`
header instead of reusing `Authorization` (rejected — roughly sixty hand-built
fetch call sites already send `Authorization`, and each one missed in a rename is
a silent 401; the header name is unchanged, only what the value means).

**Source**: `secure/src/functions/SessionManagement.php`,
`secure/src/functions/AuthManagement.php` (`qs_session_auth`,
`validateBearerToken`), `secure/admin/AdminRouter.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md), [ARCHITECTURE.md §3](ARCHITECTURE.md).

### The session kill switch is a generation counter, not a session index (locked 2026-08-10)

**Decision**: Each user record carries one integer, `session_generation`. A
session stamps its value at login; every request compares the stamp against the
record. Raising the integer ends every existing session of that account at once.
"Log out everywhere" is that bump. A password change is that bump followed by
re-stamping the session that asked, so the caller stays signed in and every other
device does not.

**Reasoning**: The obvious alternative is to keep an index of live sessions per
user and walk it. PHP keys session files by session id and offers no
user→sessions lookup, so that index would have to be built and maintained by hand
— which is the store this release just deleted, re-created under a new name, with
the same failure modes: it can drift from reality, it needs pruning, and it grows
with every login. A counter needs none of that. It is O(1) to bump, it is already
being read (the user record is loaded on every request to check status and role),
it cannot drift because there is nothing to keep in sync, and it degrades to
"generation 0" for a record written before the field existed. Django and Laravel
both solve this the same way.

The cost is honest and small: you cannot enumerate or selectively end one *other*
session, because nothing records that they exist. The product need — "end my
sessions everywhere" and "a password change should not leave a thief signed in" —
is served exactly.

**Alternatives considered**: A per-user index of live session ids (rejected —
above). Deleting the user's session files directly (rejected — it means reaching
into PHP's storage layer and reading every file to find the owner, and it breaks
outright under any non-file session handler). Bumping on every password change
*without* the re-stamp (rejected — it would sign the user out of the browser they
just used to change their password, which reads as a bug).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_user_generation`,
`qs_user_bump_generation`), `secure/src/functions/SessionManagement.php`
(`qs_session_restamp`), `secure/management/command/logoutSession.php`,
`secure/management/command/changePassword.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Single-session-per-user was considered and rejected (locked 2026-08-10)

**Decision**: A login does **not** evict the account's other sessions. Two
browsers, a desktop and a phone, stay signed in together. Ending other sessions is
an explicit action the user takes ("log out everywhere"), never a side effect of
signing in.

**Reasoning**: One-session-at-a-time looks like a security control and is not one.
Someone holding the password can log in and evict the legitimate user — a denial
of service delivered *by the attacker*, at will. The two then take turns evicting
each other, which is indistinguishable from a flaky connection and obscures the
very intrusion the mechanism appears to reveal. It also breaks ordinary use: a
designer previewing on a phone while editing on a desktop is a normal working
pattern, not an anomaly to be defended against.

What the idea is actually reaching for is the ability to end sessions you did not
start, and the generation counter (see the entry above) delivers that directly,
on demand, without punishing the honest case.

**Alternatives considered**: Ship it as the model (rejected — above). Ship it as a
setting defaulting on (rejected — same behaviour, with a setting to blame). If it
is ever wanted, it ships as a setting defaulting **off**, never as the model.

**Source**: `secure/management/command/logoutSession.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md).

### Surface B is cookie-only (locked 2026-08-10)

**Decision**: The `/p/<id>/` visibility gate reads the panel's session cookie and
nothing else. The `Authorization: Bearer` path it also accepted is dropped, along
with the short-lived `qs_preview` cookie that used to carry a token to it.

**Reasoning**: The header path did not work the same way on the two supported
targets. Apache does not forward `Authorization` to this surface unless the
deployment configures it; nginx does. The same code therefore decided access
differently depending on the web server, which is the sort of divergence that is
discovered in production rather than in review. Choosing between them is easy once
stated plainly: the credential that actually reaches this surface is a cookie,
because a preview iframe is a plain browser navigation and can carry no header of
its own. Keeping the header as a second path bought nothing on Apache and, on
nginx, was a second way in to maintain and test. And with the session cookie
covering the whole origin, `qs_preview` had nothing left to carry.

The accepted cost is stated rather than worked around: a project mapped to its
**own** domain is a different origin, so the panel's cookie is not sent there.
That is irrelevant for a public project — no credential is needed — and it means a
**private** project cannot be previewed on its mapped domain. It is previewed at
`/p/<id>/` on the panel's own hostname, which is where the editor points anyway.

**Alternatives considered**: Keep both paths and document the Apache caveat
(rejected — a gate whose behaviour depends on the web server is a defect, not a
caveat). Keep the header path and require every Apache deployment to forward the
header (rejected — it makes correct behaviour depend on a configuration step
deployers will miss, and the failure is silent). Issue a separate short-lived
preview cookie for mapped domains (rejected — it re-introduces a second credential
to serve a case the editor does not use).

**Source**: `secure/src/functions/surfaceB.php` (`qs_surface_b_gate`),
`secure/admin/templates/layout.php`. Behaviour:
[ARCHITECTURE.md §5.1](ARCHITECTURE.md), [ADMIN_PANEL.md](ADMIN_PANEL.md).

### Session files live in the installation, not the shared system path (locked 2026-08-10)

**Decision**: PHP sessions are written to `secure/tmp/sessions` inside the
installation, with `gc_maxlifetime` raised to cover the longest session QuickSite
promises, and the session cookie is named `QSSESSID` rather than PHP's default.

**Reasoning**: The default save path is shared by every application on the host,
and PHP's garbage collector deletes files older than *the collecting request's*
`gc_maxlifetime` — commonly 24 minutes. A neighbouring application's ordinary
traffic would therefore sign QuickSite's users out mid-work, at random, with
nothing in any log to explain it. Owning the directory removes the interaction
entirely and keeps session state inside the installation, which is where every
other piece of this file-based system's state already lives. The distinct cookie
name is the same reasoning applied to the browser: an unrelated `PHPSESSID` set by
another application on the same hostname is no longer something QuickSite has to
reason about.

**Alternatives considered**: Leave the default path and raise `gc_maxlifetime`
(rejected — it only changes what QuickSite's own requests collect; the neighbour
still collects at its own setting). Leave the default path and accept the logouts
(rejected — an unexplainable intermittent logout is the worst kind of defect to
diagnose). A database or custom session handler (rejected — a database dependency
is exactly what this project's positioning rules out).

**Source**: `secure/src/functions/SessionManagement.php` (`qs_session_boot`,
`qs_session_save_path`). Behaviour:
[PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

### The pre-session auth forms get a double-submit CSRF pair, not a session-backed token (locked 2026-08-11)

**Decision**: `/admin/login`, `/admin/register` and `/admin/setup` carry a CSRF
token planted as an HttpOnly, `SameSite=Strict` cookie scoped to the admin path
and repeated as a hidden field; a POST is accepted only when the two match, and
the check runs *before* the credentials are looked at. Signing out becomes a POST
carrying the per-session token instead of a `GET` link.

**Reasoning**: The Management API's CSRF defence is the per-session token a page
embeds — but these three forms run before any session exists, so they have no
token to embed. A value the server plants in a cookie and repeats in the form
satisfies the same property: another origin can make the browser send a cookie,
but it can neither read one nor put its value in a form it builds. Running the
comparison ahead of the credential check matters as much as the check itself — a
forged POST must not be able to spend the login throttle probing usernames, nor
the registration flood budget. Logout moved to POST for the plainer reason that a
state change reachable by `GET` is reachable from any page on the internet with
one `<img>` tag.

**Alternatives considered**: A token held in the PHP session (rejected — it would
tie a security control to a side effect of another module: `AdminTranslation`
happens to open a write session on every admin render today, and a CSRF defence
that quietly depends on that staying true is fragile. The cookie pair is
self-contained and needs no session at all). Origin/Referer header checking
(rejected — the headers are absent or stripped often enough that the fallback
decides the policy, and the fallback is either "allow" or "break real users").
Leaving the auth forms unprotected because login CSRF is low severity (rejected —
the API gained a CSRF defence in the same beta, and leaving the front door of the
same panel without one is an inconsistency that will not survive the next reader).

**Source**: `secure/admin/AdminRouter.php` (`formToken`, `formTokenValid`,
`sessionTokenValid`, the logout branch of `dispatch`), the three auth templates,
`secure/admin/templates/layout.php`. Behaviour:
[ADMIN_PANEL.md §6](ADMIN_PANEL.md), [ARCHITECTURE.md §7](ARCHITECTURE.md).

### Project visibility lives on the members page, shaped deliberately unlike the join policy (locked 2026-08-11)

**Decision**: `setProjectVisibility` is surfaced on `/admin/members`, above the
join-policy control, rendered as a framed block with a coloured state badge, the
consequence written out in a sentence, the URL a public setting exposes, and a
button named after the state it moves to — *"Make it public to everyone"*, never
*"publish"*. Each of the two sections states what it is *not*, and the
confirmation states that the site is **not deployed**: no domain, no search-engine
submission, no change of address; the existing `/p/<id>/` view merely stops
requiring membership. The control is shown to the owner only, and going public
requires a tick inside its confirm modal.

**Reasoning**: The two settings sound alike — "open" and "public" both read as
"anyone can get in" — and they were in fact confused during testing: an open join
policy was taken to have opened the site to everyone, which it does not do. Two
lookalike toggles side by side is the defect, so the fix is to make them not look
alike rather than to add explanatory text to a matching pair. Naming the button
after its destination removes the second ambiguity a *Toggle* button carries,
which is what state you end up in. **"Publish" is avoided deliberately**: the
first wording of this control said *"Publish to the internet"*, and it was
rejected in review for implying a deployment — a domain, a sitemap, an SEO step —
none of which happens. The operation changes one flag; a control that suggests it
did more is a worse lie than a vague one. The page is the right home because it
already reads visibility to warn about the private+open posture, so the value is
on screen either way; a control that only reads a value it will not let you change
is its own confusion. Owner-only follows the command: the `project.visibility`
category sits at the delete/transfer tier, so the UI must not offer it to an admin
the server will refuse.

**Alternatives considered**: A dedicated project-settings page (rejected — it
separates visibility from the join policy it is confused *with*, which is exactly
the comparison the user needs to see). A matching toggle next to the join policy
for consistency (rejected — visual consistency is what caused the confusion).
Leaving it on the generic command console (rejected — the single knob that opens a
site to everyone should not be reachable only by finding it among 175 others). A
typed confirmation like the account deletion (rejected — the flag is reversible,
so the friction of a tick is proportionate where typing is not).

**Source**: `secure/admin/templates/pages/members.php`,
`public/admin/assets/js/pages/members.js` (`renderVisibility`,
`setupVisibilityToggle`), `public/admin/assets/css/members-admin.css`. Behaviour:
[ADMIN_PANEL.md §9.12](ADMIN_PANEL.md).

### Account deletion ships on the account page, behind a typed confirmation (locked 2026-08-11)

**Decision**: `/admin/account` carries `deleteMyAccount` alongside the password
change and the sign-out-everywhere action, gated by the current password *and* by
typing the account's username exactly. The page is reached by clicking your own
name in the header, and is open to every authenticated user with no role gate.

**Reasoning**: A settings page that hides the destructive action does not prevent
the deletion; it sends the person to the raw command console to do the same thing
with less context and no confirmation at all. Putting it here is the safer of the
two, because the surface can explain what will happen and can list the projects
whose sole ownership is blocking the deletion — information the console shows only
as a JSON error. The typed confirmation is the username rather than a fixed word
like DELETE: it is the one string only this account's owner has in mind, and
unlike a checkbox it cannot be clicked through by reflex. No role gate, because
all three commands are global-scope `access: 'any'` and act only on the caller —
an account belonging to no project must still be able to change its own password.
The header badge is the entry point because clicking your own name is where people
already look for account settings.

**Alternatives considered**: Omit deletion and keep it console-only (rejected —
see above; it moves the risk rather than removing it). A checkbox confirmation
like the ownership transfer (rejected — transfer is recoverable by the new owner,
deletion is recoverable by nobody, so the friction should differ). A new top-level
nav entry (rejected — the account surface is per-user, not part of the project
work the nav describes, and the header badge already names the account).

**Source**: `secure/admin/templates/pages/account.php`,
`public/admin/assets/js/pages/account.js`, `secure/admin/AdminRouter.php` (the
page allowlist), `secure/admin/templates/layout.php` (the badge link). Behaviour:
[ADMIN_PANEL.md §9.13](ADMIN_PANEL.md).

### "Does this project exist?" is answered by the gate, not by routing (locked 2026-08-11)

**Supersedes**: *A private project and a nonexistent project are indistinguishable*
(locked 2026-07-24, above). The contract it states is unchanged and still holds;
what changed is the mechanism that holds it up.

**Decision**: `/p/<id>/` binds whatever id the URL names, without asking whether
that id names a real project. The visibility gate is the single decision point,
and it has no existence check at all: an id naming nothing has no `members.json`,
so it reads as *private with no members*, which is exactly what it is. A ghost id
and a private project therefore run the same lines, in the same order, at the same
moment in the request, and are refused by the same call. The refusal's headers are
set unconditionally rather than inherited from whatever ran earlier.

**Reasoning**: The first closure made two different refusal paths emit matching
responses. That is a promise about two pieces of code staying in step, and it
lasted until beta.11 moved the session model underneath it. Routing checked
existence first, so a real id ran the gate and a ghost id was refused before it —
and once the gate resolved identity from a session cookie, running it *started a
PHP session*, whose cache limiter adds `Expires` and `Pragma`. A private project's
`404` carried two headers a nonexistent one did not, and anyone holding any cookie
at all — no account needed, an invented value works — could read the difference.
Nothing had been done wrong to the refusal paths; a third thing had been added to
one of them, which is the failure mode of every invariant maintained by hand.

So the second closure removes the thing being maintained. There are no longer two
refusal paths to compare, which is why this cannot regress the way the first did:
a future change to the gate lands on "private" and "does not exist" simultaneously,
because they are not distinct cases in the code. The rule that replaces the promise
is short enough to hold: existence may not be consulted before identity, and the
way to guarantee that is not to consult it at all.

Two consequences are accepted deliberately. Because the marker is now anchored
rather than searched for, `/p/<id>/` means that id — a URL like `/p/<ghost>/p/<real>/`
is refused instead of quietly binding the second project, which is the more honest
reading of the path. And the mapped-domain entry keeps an existence check ahead of
its gate: there the id comes from the vhost, so a visitor cannot vary it and has no
second answer to compare it against.

**Alternatives considered**: Equalise the two paths' headers again — suppress the
session's cache limiter, or mirror it onto the other branch (rejected: it repairs
the symptom that was measured and leaves the structure that produced it, so the
next thing to touch one path reopens the oracle, exactly as this one did). Keep
routing's existence check and add a second refusal beside it that runs the gate
first (rejected: it is the same two-paths shape wearing the fix's clothes). Put the
existence check inside the gate, after the identity read (rejected: it works, but
it is held up by a line that must not be moved, and a rule of that kind is what the
whole entry is about).

**Source**: `secure/src/functions/surfaceB.php` (`qs_surface_b_gate`,
`qs_surface_b_maybe_handle`, `qs_sb_deny`). Behaviour:
[ARCHITECTURE.md §5.1](ARCHITECTURE.md), [ARCHITECTURE.md §6](ARCHITECTURE.md).

---

## Installation and first run (beta.11)

### Setup is a menu, and it never writes what it has no subject for (locked 2026-08-11)

**Decision**: `setup.sh` / `setup.bat` present a menu of six independent items —
public folder name, secure folder name, URL space, environment, self-registration,
show my setup token — instead of walking a fixed sequence. The menu header shows
the values currently on disk. Two of those items write config the scripts
previously left to be discovered by hand; a third only reads.

**Reasoning**: The linear walk made changing one setting mean answering all of
them, which is why the scripts were run once and then avoided. A menu is the
shape the task actually has: these settings are independent, they are revisited
at different times, and the state they read back is on disk rather than in the
operator's memory.

The harder half of the decision is what setup may NOT do. It runs before any
account exists, so anything whose subject is an account cannot be written here —
not the user registry, not the project roster, not the operator list. The setup
token is the case that looks like an exception and is not: it is minted by the
engine when the first-run page renders, so on a fresh clone the file genuinely
does not exist while setup is running. The menu item therefore says so and gives
the ordering, rather than printing an empty value that reads as a broken install.

The environment and self-registration answers are written as server-side files
for the same reason the environment setting has never been an API: an operator
running the setup script has filesystem access by definition, and a setting that
governs outbound network reach must not be reachable by anything weaker than
that.

Setup also stops carrying its own copy of the nginx routing generator. It had
one, it drifted, and it emitted a root catch-all pointing at an entry point that
no longer exists plus no project-renderer block at all — so re-running setup
broke the routing of an install that was working. It now deletes the generated
file and lets the engine rebuild it from the single generator on the next page
load. Deleting is the one operation that cannot go stale.

**Alternatives considered**: Keep the linear walk and add the two new prompts to
the end (rejected — it makes the walk longer, and the complaint was never that it
was short). Have setup shell out to PHP to regenerate the nginx config
(rejected — it makes a working install depend on a PHP CLI being present and on
PATH, which is not true on Windows and not guaranteed on a locked-down host).
Have setup write the routing file itself from a corrected copy of the generator
(rejected — that is exactly the duplication that produced the defect, with the
serial number filed off). Make the environment switchable from the admin panel
(rejected — it is the control on server-side fetch reach; putting it behind a
credential means a leaked credential can re-open it).

**Source**: `setup.sh`, `setup.bat`, `secure/src/functions/NginxConfig.php`,
`public/init.php`. Behaviour: [README.md](../README.md).

### First run makes the bootstrap account the owner of the projects that shipped (locked 2026-08-11)

**Decision**: When the first account on an installation is created, the engine
writes a `config/members.json` for every project directory that has none, naming
that account as owner, and mirrors those projects into its user record the same
way `createProject` does. Adoption is by ABSENCE of a roster, not by matching the
starter project's id.

**Reasoning**: `qs_project_birth_write_members()` runs at project creation, and a
project that arrived in the download never passes through it. Without a roster,
`loadProjectMembers()` reads a project as private with no members — invisible and
uneditable to everyone, including the person who just installed QuickSite. The
result was an install that started with a starter project nobody could open.

Adoption keys on the missing roster rather than on the id `quicksite` because the
starter's name is not a contract, because someone may drop a second project in
before first run, and — the load-bearing reason — because a project with no
roster is reachable by nobody, so adopting it cannot take anything away from
anyone. The registry was empty one instruction earlier; there is no other account
with a claim to weigh.

Failure is reported and never fatal. The account exists by the time this runs,
and an account creation that rolled back because one directory was read-only
would leave the deployer with no way in at all — whereas an account plus a
warning leaves them signed in, looking at the panel, able to fix it.

**Alternatives considered**: Ship a `members.json` with the starter project
(rejected — it would have to name a user id that does not exist yet, and the file
is per-install by construction). Have setup write it (rejected — no account
exists at setup time, which is the same seam as the operator list below). Adopt
only the id `quicksite` (rejected — hardcodes a name that is not a contract, and
silently does nothing for any other shipped project). Adopt every project,
roster or not (rejected — that would re-home a project somebody else owns, which
is the one thing this must never do).

**Source**: `secure/src/functions/firstRun.php`,
`secure/src/functions/AuthManagement.php` (`qs_auth_attempt_setup`).
Behaviour: [README.md](../README.md).

### The operator list is a display preference, not a role (locked 2026-08-11)

**Decision**: `secure/management/config/operator.php` names the accounts that see
operator-facing notices. It is gitignored with a tracked `.example`, written at
first run naming the bootstrap account, and edited by hand thereafter — no
command writes it. **It grants nothing**: it decides whether a notice renders and
nothing else, and no code path may consult it to authorize an action. A missing,
unreadable or malformed file reads as an empty list, never as "everybody".

**Reasoning**: Notices like "an update is available" are addressed to whoever
maintains the server. Showing them to every authenticated account means someone
invited to edit one project's text is told the engine version and offered an
action they cannot take.

The obvious answer — a role — is the one thing that must not be built. A global
permission in QuickSite is now either "any signed-in account" or "nobody", and
nothing else can be expressed; that was arrived at by deleting an
installation-wide tier and the escalation it carried. A file naming "the
administrator of this server" puts that principal straight back. Keeping the file
display-only is what avoids it: a list that authorizes nothing is not a
principal, whatever it is named.

Who the operator IS needs no definition, because it is not a grant. It is whoever
can edit the file — which is whoever has filesystem access, which is strictly
more power than any list could hand out. That is the same principle the first-run
setup token already runs on, and it dissolves the questions a role would have
raised: nobody appoints an operator, operators do not appoint each other, and
there is no hierarchy to design.

Absence reads as "nobody" rather than "everybody" because every install QuickSite
creates has this file, so absence means either an install predating it — whose
operator can add a line from the shell they demonstrably have — or an empty list
somebody chose. Guessing "everybody" for either restores the disclosure the file
exists to close.

**Alternatives considered**: A deployer role (rejected — reintroduces the
installation-wide principal, in full, with the escalation path that made it
dangerous). Show the notice to every project owner (rejected — owning a project
says nothing about maintaining the server, and on a multi-tenant install it is
most of the accounts). Restrict the underlying update check instead (rejected —
that is authorization, and the problem is presentation; the check has no
principal to gate on). Default to "everybody" when the file is absent (rejected —
preserves today's disclosure precisely on the installs least likely to notice).

**Source**: `secure/management/config/operator.php.example`,
`secure/src/functions/firstRun.php` (`qs_operator_ids`,
`qs_first_run_write_operator`). Behaviour: [README.md](../README.md).

### The free web root gets a placeholder, not a front controller (locked 2026-08-12)

**Decision**: On its **first** run only, and **only** when the web root holds no
`index.*` of its own, setup copies `secure/deploy/index.html.example` to
`public/index.html` — a static page saying the panel is at `/admin/`. The live
file is gitignored; the template is tracked. Nothing in the engine reads it, no
routing changes, and a later setup run never re-creates it.

**Reasoning**: QuickSite serves nothing at the domain root on purpose — the root
belongs to the deployment, so the engine never squats it. The cost, unnoticed
until someone installed from scratch and looked, is that a fresh install answers
**403 Forbidden** at the address a person types first. It is the correct
behaviour and it is indistinguishable from a broken install, which is the worst
pairing an install step can have: every other failure mode here explains itself.

A static file is the answer that does not undo the decision it is patching. The
root is free because nothing is ROUTED there — no `FallbackResource`, no
`try_files` into PHP — and a plain file that `DirectoryIndex` happens to pick up
changes none of that. Put a site at the root and it is served exactly as before.

Both conditions are load-bearing, in opposite directions. *Only when the root is
empty*, because QuickSite is installed onto servers that already have a site and
overwriting somebody's front page would be unforgivable — the check is for any
`index.*`, not just the name we would write, since the deployment picks its own
`DirectoryIndex`. *Only on the first run*, because deleting the placeholder has
to be permanent: a file that reappears every time you open setup is not a
placeholder, it is a nag.

It has to be setup that does this, not the engine. On Apache a request for `/`
with no index file never executes PHP at all, so there is no point at which
QuickSite could notice and react — by the time anything of ours runs, the 403
has already been sent.

The page is honest about announcing that the server runs QuickSite, and says so
in its own comment: an operator who does not want that deletes it, which is the
same gesture that replaces it.

**Alternatives considered**: A `FallbackResource` at the root that renders a
QuickSite welcome page (rejected — that is squatting the domain, which is the
decision this one exists inside). Redirect `/` to `/admin/` (rejected — same
objection, plus it makes the root unusable for the site it is reserved for).
Ship `public/index.html` as a tracked file (rejected — a `git pull` would
resurrect it after deletion, or worse, overwrite the page that replaced it).
Document the 403 and change nothing (rejected — it was already documented, and
the person hitting it has not read the README yet; that is the point at which
they are deciding whether this software works). Create it on every setup run
when the root is empty (rejected — indistinguishable from the above for a fresh
install, and a nag for everyone else).

**Source**: `secure/deploy/index.html.example`, `setup.sh` /
`setup.bat` (`maybe_place_landing_page`). Behaviour: [README.md](../README.md).

### The project namespace is `^~` with a named-location fallback (locked 2026-08-13)

**Decision**: The generated nginx routing gives `/p/` — and only `/p/` — the `^~`
modifier, and its `try_files` fallback is the **named location**
`@quicksite_project`, never a path. The named location is defined once by the
operator in their own server block, because it needs a php-fpm upstream QuickSite
cannot know, and its `SCRIPT_FILENAME` is a **fixed** path to the `/p/` entry
point. A nested `location ~ \.php$ { return 404; }` sits inside the `^~` block.

**Reasoning**: A project's files live in `secure/projects/<id>/public/`,
deliberately outside the web root, so the visibility gate runs before a byte is
sent. Nothing under `/p/` is ever findable on disk. Panel-generated vhosts almost
all carry a static-asset rule of the shape
`location ~* ^.+\.(css|js|png|…)$ { expires max; }` — and in nginx **a regex
location outranks a prefix location**. So every stylesheet, script and image
under `/p/` was answered by that block, which looked in the web root, found
nothing, and returned 404, while extensionless page routes matched no regex,
reached the prefix, and rendered perfectly. A site that renders its HTML and
none of its assets looks like an application bug and is a routing one. `^~` is
the only modifier that takes a prefix out of that competition.

The reason this entry exists is the second half, because the naive version looks
identical and is far worse than the bug it fixes. `^~` suppresses regex matching
for everything it wins, **including the vhost's own `location ~ \.php$`**. So

    location ^~ /p/ { try_files $uri $uri/ /p/index.php; }

is a trap: the fallback is a path, a path re-enters location matching, `^~` wins
again with regex still suppressed, and this time `try_files $uri` finds
`/p/index.php` **on disk** — nginx serves the renderer as `text/plain`. The fix
for a 404 would have been handing out the engine's source. A named location
cannot do that, because nginx jumps straight to it without re-running location
matching; there is no second pass to be trapped in. The nested `.php` refusal
closes the one door that remains, a direct request for the entry point.

`SCRIPT_FILENAME` is hardcoded rather than derived from `$fastcgi_script_name`
for the same class of reason: a request-derived script path is exactly what lets
`/photo.jpg/x.php` execute an uploaded file. With a fixed path there is no
request-controlled component, so the class cannot arise here at all — immunity by
construction rather than by validation.

The cost is one block the operator pastes at install time. It is accepted because
the failure is loud: with the include in place and the block missing, every
project URL answers `500` and the log says `could not find named location
"@quicksite_project"`. Compare the alternative below, whose failure is a silent
404 on assets only — the exact symptom that took a full debugging session to
attribute the first time.

Only `/p/` gets this. `/admin/`, `/admin/api/`, `/management/` and the free root
stay plain prefixes, and that is deliberate in both directions. They do not need
`^~`: every dotted URL they serve is a real file inside the web root (111 of
them under `public/admin/`), so the panel's regex and our prefix both resolve it,
and every URL of theirs that needs PHP is extensionless — no routed command name
contains a dot, and a project id cannot. And they must **not** have `^~`, because
their fallback *is* a `.php` path; adding the modifier would arm the exact trap
described above on three more namespaces.

**Alternatives considered**: A regex of our own, `location ~ ^/p/.+\.(css|js|…)$`,
placed ahead of the panel's (rejected — regex-versus-regex is settled by
configuration order, so it works until the include moves or the panel regenerates
the vhost, and then it silently returns to 404ing assets; it would also require
keeping an extension list in sync with someone else's forever). Plain `^~` with a
path fallback (rejected — discloses source, as above). Serving project files from
the web root so no PHP is involved (rejected — it is the visibility model: a
private project's contents would be world-readable, and the existence of
`public/p/<id>/` would answer "does this project exist?" where no application
code can intervene). Leaving it and documenting the 404 (rejected — it makes a
supported target ship visibly broken).

**Source**: `secure/src/functions/NginxConfig.php` (`generate_nginx_config`),
`public/init.php` (the first-load setup page),
`secure/deploy/nginx-vhosts.conf.example`. Behaviour:
[README.md](../README.md).

## Session hygiene and static serving (beta.11)

### A read never mints a session (locked 2026-08-14)

**Decision**: a read-mode session boot declines unless the id in the cookie names
a session file that already exists. Only a deliberate write — logging in, storing
a language choice, setting a one-shot flash — may create one. `qs_session_present()`
is the single place that answers "is there a session to open", and every caller
that must not invent one goes through it.

**Reasoning**: `session.use_strict_mode = 1` was already set, and it does close
session fixation: an id the caller invented is never adopted. What it does not do
is decline. Strict mode's answer to an unknown id is *mint a fresh one instead*,
and minting writes a file and sends a `Set-Cookie`. A browser takes the id it is
handed and stops asking; a script, a scanner or a curl suite ignores `Set-Cookie`
and asks again on every request, and is handed a new session every time. Measured
on the development install: 5393 session files, 4619 of them empty, all inside
three days, from an unauthenticated request that needs no account and no valid
cookie — only a cookie shaped like one. Two distinct paths produced it: the
surface-B gate reading the cookie, and the admin panel resolving its display
language, which opened the session for WRITE on every page render including
anonymous ones, so even a request carrying no cookie at all left a file behind.

The fix is contained: reading a session that does not exist has no result worth
having, so declining costs nothing a caller could want. It does couple the engine
to PHP's `files` session handler, which is why `qs_session_file_path()` returns
null under any other handler and both callers fall back to their previous
behaviour — a store QuickSite cannot read is never reported as empty.

**Alternatives considered**: leaving it to PHP's garbage collector (rejected — it
cannot, see the entry below). A shorter `gc_maxlifetime` (rejected — PHP has one
lifetime setting for the whole store, so the longest promise, remember-me at 30
days, sets the floor for the junk as well). Refusing cookies that do not name a
session at the web-server layer (rejected — the server cannot see the session
store, and it would put an authentication decision outside the application).
Accepting the accumulation and sweeping only (rejected — it treats the symptom,
and the sweep would then be load-bearing rather than housekeeping).

**Source**: `secure/src/functions/SessionManagement.php` (`qs_session_boot`,
`qs_session_present`, `qs_session_file_path`),
`secure/admin/functions/AdminTranslation.php`. Behaviour:
[ARCHITECTURE.md](ARCHITECTURE.md) §3.

### QuickSite sweeps its own session store, and the sweep is not a command (locked 2026-08-14)

**Decision**: the engine collects dead session files on its own rule — empty
files past a short grace, sessions idle past `idle_ttl`, and sessions holding no
QuickSite login once they are past the longest lifetime this install promises
anything. It runs opportunistically after a login on a 1-in-N die
(`auth.php` `authentication.session.sweep_divisor`, default 10) and on demand
from `php secure/cli/session-sweep.php`. It is **not** a routed command.

**Reasoning**: PHP's own collector cannot do this job. It fires on 0.1% of
session starts, and its `gc_maxlifetime` must outlive the longest thing the
install promises — remember-me, 30 days — so it refuses to touch anything
younger than a month. QuickSite already knows better: its own idle check is what
actually expires a session, and it knows one is dead a day after it was last
seen. Login is the right host for the die because it is infrequent, already
writing to disk, and needs no scheduler and nothing for an operator to remember.

Not a command, because clearing the session store is installation-wide and there
is no principal that could authorize it. Every permission in QuickSite is a fact
about one project, and a project role cannot mean "sign out everyone on this
server"; beta.10 deleted the last installation-wide tier deliberately, and adding
a command here would reintroduce exactly the global principal that was removed.
The credential for an installation-wide action is filesystem access to the
server — the same principle the first-run setup token uses — so the entry point
is a script outside the web root that refuses to run under a web SAPI.

Correctness is bought ahead of thoroughness in three places. The sweep only
deletes what it can positively classify, so a session belonging to another
component sharing the save path is left alone rather than guessed about. Every
deletion happens under a non-blocking exclusive lock — the same lock PHP's files
handler takes for a write — and the file's stats are re-read under it, so a
session written between the scan and the delete is re-judged instead of removed
on stale evidence. And the scan costs one stat per file: a file written inside
the idle window cannot hold an idle-dead session, because its own `qs_seen` was
written before it was, so a fresh modification time proves a live session without
opening the file. Measured: 870 files, nearly all of them candidates, 0.25 s;
the same store once tidy, 0.011 s.

**Alternatives considered**: a routed sweep command (rejected — the principal
problem above). A scheduled task or cron entry (rejected — it is one more thing
an install can be missing, and the failure is silent). Sweeping on every request
(rejected — it puts a directory scan in the path of every page view). Sweeping on
every login without a die (rejected — same cost, concentrated on the one action a
user is waiting on). Deleting anything not recognised as a QuickSite session
(rejected — the save path is shared with the author's-site OAuth state store when
that runs in the same request, and a sweep that guesses is a sweep that
eventually deletes something live).

**Source**: `secure/src/functions/SessionManagement.php` (`qs_session_sweep`,
`qs_session_sweep_consider`, `qs_session_sweep_maybe`),
`secure/cli/session-sweep.php`. Behaviour: [ARCHITECTURE.md](ARCHITECTURE.md) §3.

### Range and conditional requests are answered in PHP, not delegated to the web server (locked 2026-08-14)

**Decision**: the static passthrough emits `Accept-Ranges`, `ETag` and
`Last-Modified`, answers `304` to a matching `If-None-Match` / `If-Modified-Since`,
and answers `Range` with `206 Partial Content` and a correct `Content-Range`,
seeking to the requested offset rather than reading the file from the start.
`X-Sendfile` / `X-Accel-Redirect` — handing the file to the web server to send —
was considered and deferred.

**Reasoning**: the accelerator is the option that looks obviously better, which
is why the reasoning is worth recording. It is genuinely faster: the web server
sends the bytes with its own zero-copy path, no PHP process is held open for the
duration of a download, and byte ranges and conditional requests come free with
it. But it is server-specific configuration. nginx has `X-Accel-Redirect` built
in; Apache needs `mod_xsendfile`, which is absent from most Apache builds and
from WAMP. So it can only ever be an optional accelerator sitting on top of a
correct fallback — which means the fallback has to be right regardless, and the
fallback is the part that fixes the user-visible defects on every deployment with
no configuration at all. Engine-level work had no home after this release; the
accelerator can land in any later one, because it slots in ahead of this path
rather than replacing it.

The everyday cost being fixed is not seeking, it is revalidation. Without a
validator there is nothing for a browser to revalidate against, so once
`max-age` lapses it must refetch the whole file — on every asset of every project
site, invisible only because most assets are small.

Two details are load-bearing. The ETag describes content and never the
filesystem: for files up to 1 MiB it is a hash of the bytes, above that the
modification time and size, and neither form can carry a path or an inode —
beta.10 removed absolute paths from responses deliberately and an ETag is a
response header like any other. And the visibility gate still runs first: a
`Range` header is a request header, read long after membership was decided, so a
partial request reaches no bytes a whole request could not have.

Multi-range is declined here rather than faked — the whole representation is sent
with `200`, which RFC 9110 permits — and it is worth knowing that the client may
still see a multipart `206` anyway, because declaring `Accept-Ranges` on a
response with a known length arms the web server's own range filter. Measured on
Apache 2.4.62: a two-range request comes back as a correct
`206 multipart/byteranges` assembled by Apache from the full body. Single ranges
never reach that filter, since a `206` is not re-sliced.

**Alternatives considered**: `X-Sendfile` / `X-Accel-Redirect` now (deferred, as
above). Moving project files into the web root so the server serves them directly
(rejected — it is the visibility model: a private project's contents would become
world-readable, and the existence of `public/p/<id>/` on disk would answer "does
this project exist?" somewhere no application code can intervene). Emitting only
`Last-Modified` and skipping ETags (rejected — one-second resolution makes it a
weak validator, and a strong one costs a hash on files small enough for the hash
to be cheap). Implementing `multipart/byteranges` (rejected — a lot of machinery
for something nothing fetching a project asset asks for, and the web server
already answers it).

**Source**: `secure/src/functions/surfaceB.php` (`qs_sb_send_file`, `qs_sb_etag`,
`qs_sb_not_modified`, `qs_sb_parse_range`, `qs_sb_emit_range`). Behaviour:
[ARCHITECTURE.md](ARCHITECTURE.md) §5.1, §6.

### Browser storage is namespaced per project, inside qs.js, always (locked 2026-08-15)

**Decision**: `qs.js` writes and reads every author-declared storage key as
`qsp_<projectId>_<key>`. The prefix is applied at the storage boundary and
nowhere else — the declared key stays the logical identity in the registry, in
the consent category map, in the `data-storage-show` / `data-storage-value` /
`data-auth-source` attributes, in the verb arguments and on the generated cookie
policy. The project id is supplied by the server as `window.QS_PROJECT`, emitted
before `qs.js` on both render paths. It is applied identically on the live site
and in a built site.

**Reasoning**: browser storage is scoped by **origin**, and a path is not part of
an origin. Every project served at `/p/<id>/` on one host therefore shares one
`localStorage`, and a key named `cart` in project A is literally the same slot as
`cart` in project B — one project silently reading and overwriting another's
data, including its auth tokens. That is a property of the multi-tenant serving
model this release exists to make safe, not a matter of tidiness.

⚠ **The boundary holds because authors cannot execute JavaScript.** `script`,
`noscript`, `style`, `object`, `embed` and `applet` are blacklisted in
`TagRegistry`; any tag outside the allowlist (`foreignObject`, raw SVG children)
is dropped by the renderer; `on*` attributes are refused unless they are
`{{call:…}}` syntax, which compiles to a fixed 26-verb allowlist with arguments
quoted as string literals; URL attributes are scheme-allowlisted by `UrlPolicy`;
uploaded and imported SVG is sanitised and additionally served under
`default-src 'none'; sandbox`; `.html` is in neither the import nor the publish
extension allowlist; an `iframe` with `srcdoc` has no host to match a sandbox
rule against, so it always receives the strictest `sandbox=""`; and custom JS
functions were deleted in beta.3. `qs.js` is consequently the only path from a
page to storage, which is what makes a prefix inside it a boundary rather than a
suggestion. **If author-supplied JavaScript is ever reintroduced, this degrades
from a boundary to a naming convention** and the isolation has to be
re-established somewhere the author cannot reach — separate origins being the
obvious answer. (One hole in that premise was found while verifying it and closed
in the same change; see the entry below.)

**The prefix could not be `qs_`.** `secure/src/functions/reservedStorageKeys.php`
refuses author keys matching `quicksite_` / `quicksite-` / `qs_` / `qs-` so a
rendered page cannot read or clear the admin panel's own storage, which shares
the same origin. Generating `qs_<id>_<key>` would have meant carving an exception
into the guard that exists to block precisely that shape. `qsp_` keeps the keys
recognisably QuickSite and clears the reservation with no exception; a probe
asserts both halves — generated keys pass, and `qs_` / `QS_` / `quicksite-`
remain blocked.

**The id must come from the server.** It arrives differently per serving mode: at
`/p/<id>/` it is a URL segment, but on a mapped domain it comes from the vhost's
`QS_PROJECT` and the path contains no id at all. Deriving it from
`location.pathname` would give a mapped-domain deployment a different — or
empty — prefix, so the same site would store under different names in preview and
in production. `PROJECT_NAME` is bound by `qs_load_project_context()` in both
modes, so one emit covers both; `window.QS_PROJECT` follows the existing
`window.QS_*` hydration precedent and is read lazily so script ordering cannot
matter. When it is absent the runtime falls back to the unnamed namespace
`qsp__<key>` and warns — never to an unprefixed key, which would silently
reproduce the bug.

**Alternatives considered**: stripping the prefix at build time, on the grounds
that a deployed site has its own origin and cannot collide (rejected — two
behaviours means preview and production store under different key names, which
is the exact shape of "works in preview, broken in production", and it stops the
build output being testable against the same expectations; nothing is gained,
since `/p/<id>/` and a mapped domain are already different origins and storage
never carried between them). Deriving the id from the URL path (rejected — the
deployment trap above). Giving each project its own origin or subdomain (a
deployment-model change far larger than this, and not something a single-host
install can assume). Prefixing only the keys the registry marks sensitive
(rejected — the collision is on the key name, so a partial rule leaves exactly
the collisions that are hardest to notice). Rendering the physical key on the
generated cookie-policy page (rejected — that page is a snapshot written at
generate time, so baking the project id into it creates a staleness class; the
admin storage page shows the physical key live instead, which is where an author
looks before opening developer tools).

**Source**: `secure/src/runtime/qs.js` (`QS_STORAGE_PREFIX`, `_storageKey`,
`_storageGet` / `_storageSet` / `_storageRemove`, `QS.storageKey`),
`secure/src/classes/PageManagement.php`, `secure/src/classes/Page.php`,
`public/p/index.php`, `public/admin/assets/js/pages/storage.js`. Behaviour:
[ARCHITECTURE.md](ARCHITECTURE.md) §8, [ADMIN_PANEL.md](ADMIN_PANEL.md) §6, §9.10.

### QS.redirect enforces a scheme allowlist at the sink (locked 2026-08-15)

**Decision**: `QS.redirect` refuses to navigate to any URL whose explicit scheme
is outside `http` / `https` / `mailto` / `tel`, warning instead. Relative paths,
anchors and protocol-relative URLs carry no scheme and pass unchanged.

**Reasoning**: found while verifying the no-author-JavaScript premise the storage
namespace above depends on. `UrlPolicy` guards URL *attributes* — `href`, `src`,
`action` and the rest — so an anchor carrying a script URL has been neutralised
since beta.10. But `{{call:redirect:javascript:alert(1)}}` compiled to
`QS.redirect('javascript:alert(1)')`, which passed the handler validator — it is
a structurally valid `QS.<verb>(…)` call with a quoted string argument, and that
validator's job is to prove nothing foreign was injected, not to interpret
arguments — and the value went straight to `location.href`. That executes in the
page's own origin. The surface-B CSP cannot prevent it: engine pages emit inline
handlers and therefore require `script-src 'unsafe-inline'`, which permits script
URLs too.

The check belongs at the sink rather than at compile time because three callers
reach it and only one is the verb: the magic-link verbs pass their `returnTo`
argument to it, and when that is absent they fall back to the `?return=` query
parameter — a value supplied by whoever wrote the link, not by the author. One
guard covers all three, and it lives in the file that freezes at the end of this
release, whereas a compile-time equivalent can still be added by a later one.

Scheme detection mirrors `UrlPolicy::sanitize` rather than being reinvented:
leading ASCII whitespace and control characters are stripped before the scheme is
read, and any embedded control character is refused outright, because a browser
ignores those before resolving the URL — a tab inside the word `javascript` still
produces a script URL.

**Alternatives considered**: sanitising the argument in `CallTransformer` at
compile time (a reasonable defence-in-depth addition, but it covers only the verb
and neither of the two runtime callers, and it lives in a file later releases can
still reach). Teaching `isValidHandler` to inspect argument values (rejected — it
validates structure across every verb; per-verb argument semantics would
duplicate the catalog and still miss the `?return=` path). Doing nothing on the
grounds that only a project editor can author the call (rejected — an editor of
one project holds no authority over another project's data, and same-origin
script execution is exactly what the storage namespace assumes cannot happen).

**Source**: `secure/src/runtime/qs.js` (`QS_ALLOWED_URL_SCHEMES`,
`_qsSafeNavigationUrl`, `QS.redirect`). Behaviour:
[ARCHITECTURE.md](ARCHITECTURE.md) §8.

---

### Applying an update is a CLI script, not a command; discovery stays in the panel (locked 2026-08-15) (superseded 2026-08-21)

**Decision**: `secure/management/command/applyUpdate.php` is deleted. Applying an
update is done by `update.sh` / `update.bat` at the install root, which have no
HTTP surface at all. `checkForUpdates` stays routed and unchanged, so the panel
still reports that a newer release exists.

**Reasoning**: applying an update rewrites the code every project on the
installation runs on, and it shells out to do it. There is no principal to gate
that on. Authority in QuickSite is per-project — no superadmin, no global tier —
so a per-project role cannot sanely imply "you may rewrite the shared
substrate". The command had already been unrouted for that reason; what remained
was a file still reachable in-process by defining `COMMAND_INTERNAL_CALL`, whose
own header documented that it was waiting for a CLI entry point. This is that
entry point, so the file has no remaining reason to exist.

The credential for an update is **filesystem access to the server**. Somebody
who can run the script can already edit `users.php`, so they hold strictly more
than any role could grant — which is why editing files, and not calling an API,
is how this works. The first-run setup token rests on the same principle.

Action and discovery are separate problems, and both ship. A script cannot tell
you there is something to do; you have to remember to run it. An HTTP endpoint
can tell you, and must not be the thing that does it. So the panel reports and
the script applies.

**Alternatives considered**: a "deployer" role that could authorise the command
(rejected, and rejected by Sangio himself when he proposed it — beta.10
deliberately deleted every installation-wide tier, and reintroducing one brings
back the escalation class that made it dangerous: `system.admin` at access
`owner` resolved to "owns ANY project", while `projects.create` is access `any`,
so any account could mint that ownership in one call and point it at an
arbitrary-code-execution primitive). Keeping the command file unrouted but
present (rejected — it stays reachable in-process, and a file whose header
explains why nobody may call it is a trap for the next person). Making the
script apply-only and dropping `--check` (rejected — a check costs nothing, and
it is what makes the script usable from `cron`).

**Source**: `update.sh`, `update.bat`, `update.ps1`;
`secure/management/config/categories.php` (the `system.read` note).
Behaviour: [README.md](../README.md) *Keeping QuickSite up to date*,
[COMMAND_API.md](COMMAND_API.md) *Update detection*.

---

### operator.php decides who SEES a notice, and grants nothing (locked 2026-08-15)

**Decision**: `secure/management/config/operator.php` — gitignored, with a
tracked `.example`, written at first run naming the account that created the
install — lists the user ids that see operator notices in the panel. Today that
is one notice: "a QuickSite update is available". It is consumed in
`layout.php`, which emits the banner container and its script only for a listed
account. No code path may use the list to authorize an action.

**Reasoning**: `checkForUpdates` is readable by any authenticated user, so
somebody invited to edit one project's text was being told the installation has
an update they cannot apply. The notice is addressed to whoever maintains the
server, and that is not a role QuickSite hands out — it is a fact about who has
filesystem access to the machine.

Making this a *display preference* rather than a permission is what keeps it
compatible with the authority model. A file that could name "the administrator
of this installation" would put back the installation-wide principal beta.10
removed, and with it the escalation that came with it. A file that only decides
whether a banner renders cannot. The distinction is load-bearing, and it is
stated in the file's own header, in `firstRun.php`, and in the probe that guards
it: a non-operator must still be able to call `checkForUpdates` exactly as
before.

It matches an existing pattern exactly — `environment.php` and
`deploy-roots.php` are both gitignored, operator-controlled files the runtime
reads and no command writes.

Absence reads as **nobody**, never as everybody. An install QuickSite created
always has the file; absence means either an install predating it (a one-line
fix from a shell its operator demonstrably has) or an empty list somebody chose.
Guessing "everybody" for either would hand the engine version to every account
invited to edit one project's text. Malformed reads as nobody too, and that
needed a `try`/`catch` rather than `@`: a syntax error in an included file is a
`ParseError`, not a suppressible warning, and every authenticated admin page
calls this function — so one typo in a hand-edited file would otherwise take
down the whole panel, including the pages someone would use to diagnose it.

**Alternatives considered**: gating `checkForUpdates` itself on the list
(rejected — that is exactly what turns a display preference into an
authorization tier; and it would not even close the version-disclosure it aims
at, since `/admin/settings` already emits `quicksiteVersion` to every
authenticated account by a different path). Showing the notice to every project
owner (rejected — "owns any project" is the target-independent resolution that
caused the beta.10 escalation, and a project owner is not necessarily the person
who maintains the machine). Shipping no notice and relying on the operator
running `--check` from `cron` (rejected — that is the discovery half of the
problem, and it is the half a script cannot solve).

**Source**: `secure/management/config/operator.php.example`,
`secure/src/functions/firstRun.php` (`qs_operator_ids`),
`secure/admin/templates/layout.php`,
`public/admin/assets/js/core/update-notice.js`.
Behaviour: [ADMIN_PANEL.md](ADMIN_PANEL.md) §9.14.

---

### A suggested username is fully random, never derived from the display name (locked 2026-08-15)

**Decision**: the first-run and self-registration forms pre-fill the username
field with a generated suggestion of the shape `qk_483927` — two lowercase
letters, an underscore, six digits. It is a suggestion: the field stays
editable, and a rejected submission hands back whatever was typed rather than a
fresh value nobody saw.

**Reasoning**: an empty username field asks somebody to invent an identifier at
the moment they are least equipped to, and what they invent is usually their
name — which is the one thing it must not be. The username is the **private
login identifier**: nobody else is shown it, and `qs_user_create` already
refuses a username equal to the display name for that reason. A suggestion
derived from the display name would give back the property the field exists to
have, since anyone who knows "Alice Martin" would think to try `alice-martin`.
So the generator is told nothing about the person.

The shape is a compromise between unguessable and usable. Nine characters,
inside the existing 3–32 rule and built only from characters that rule allows,
so what is offered always validates. Letters-then-digits reads as a name rather
than a hash, which matters because a human has to read it off one screen and
type it into another. ~676 million combinations — not a secret (the password is
the secret), just not derivable from a public name.

**Alternatives considered**: seeding from the display name with a random suffix
(rejected — it leaks the thing the username is private about, which is the whole
point). A longer random token (rejected — nobody would retype it, so it would be
copy-pasted or lost, and this is the value you sign in with). Leaving the field
empty (the status quo; rejected — it produces name-shaped usernames, which is
the outcome the privacy rule exists to avoid).

**Source**: `secure/src/functions/AuthManagement.php` (`qs_suggest_username`,
beside `qs_valid_username` whose rule it satisfies),
`secure/admin/templates/pages/setup.php`,
`secure/admin/templates/pages/register.php`.

---

### A private project's assets are Cache-Control private, not no-store (locked 2026-08-15)

**Decision**: `qs_sb_send_file()` emits `Cache-Control: private, max-age=300`
for an asset belonging to a project that is not public, and keeps
`public, max-age=300` for one that is. The visibility is stashed by
`qs_surface_b_gate()`, which has already computed it, rather than being read a
second time.

**Reasoning**: the function sent `public` to everything. `public` is an
invitation to *any* cache to store the response, shared proxies and CDNs
included — so a member-only asset could be held somewhere that might then answer
a request the gate never admitted. The gate itself was never wrong; the header
contradicted it.

`private` rather than `no-store` is the substantive half of the decision. A
member's own browser holding an asset it was entitled to fetch is fine, and it
is the entire point of the `ETag` / `Last-Modified` validators added alongside
range support. `no-store` would forbid that too, so every navigation would
refetch the bytes in full instead of answering `304` — a real and permanent cost
paid for nothing, because the thing being kept out is the *shared* cache, and
`private` already keeps it out.

Reading the gate's answer instead of re-deriving it is deliberate: two readings
of the same question are two things that have to be kept in step. An unset flag
falls back to `private`, so a future caller that somehow reaches the sender
without passing the gate fails closed.

**Alternatives considered**: `no-store` for private projects (rejected as above —
it discards revalidation to prevent something `private` already prevents).
Leaving it `public` and relying on the gate (rejected — the gate governs
QuickSite's own responses, and says nothing to an intermediary that has already
been handed a cacheable copy). Re-reading `members.json` inside the sender
(rejected — a second answer to a question already answered, and one more file
read on the hot path of every asset request).

**Source**: `secure/src/functions/surfaceB.php` (`qs_surface_b_gate` stashes,
`qs_sb_send_file` reads). Behaviour: [ARCHITECTURE.md](ARCHITECTURE.md) §6.

---

## Asset pipeline and media correctness (beta.11)

### An over-`post_max_size` upload is answered with the real limit, from the dispatcher (locked 2026-08-16)

**Decision**: the request dispatcher compares `CONTENT_LENGTH` against
`post_max_size` and answers `413 request.body_too_large` with the server's actual
numbers, before any command runs. `uploadAsset` and `importProject` repeat the
check in their own "no file" branches, for the paths that do not pass through the
dispatcher. Every limit is read from PHP at request time; none is written into
the source.

**Reasoning**: a body over `post_max_size` is discarded by PHP before the command
executes — `$_POST` and `$_FILES` are both emptied and nothing in the request
says why. Every command therefore sees a request with no parameters and answers
whatever "you sent nothing" means to it. On `uploadAsset` that was *"No file
source provided. Upload a file or provide a url parameter."*, which is false
about a request that carried a file and sends the author looking for a fault in
their own form. The condition is a fact about the request rather than about any
one command, so the dispatcher is where one check covers every command.

The limits are read rather than declared because both directives are
`PHP_INI_PERDIR`: they differ per server *and* per directory, so a number in the
source would be authoritative-looking and wrong on somebody's install.

Two measurements shaped the implementation. `php://input` is **not** empty when
the limit is exceeded — the widely repeated "empty `$_POST` plus empty input"
test never fires on this stack, and a check built on it would have been dead code
that looked correct. And a multipart body is larger than the file it carries, so
where `post_max_size` equals `upload_max_filesize` — a common default — the post
limit binds for every upload and `UPLOAD_ERR_INI_SIZE` is unreachable; the
effective figure shown to the author is the smallest of the three ceilings rather
than any single directive.

**Alternatives considered**: handling it inside `uploadAsset` only (rejected —
`importProject` has the same surface and is the upload most likely to be large,
and any future multipart command would inherit the bug). Detecting it by empty
`$_POST` plus empty `php://input` (rejected on measurement — the input stream
still holds the body). Hard-coding a limit to display (rejected — wrong on any
install configured differently, while looking definitive). Running the check
before authentication (rejected — it would let an anonymous caller read the
server's configured limits; the `Authorization` header and session cookie both
survive a discarded body, so gating it costs a genuine caller nothing).

**Source**: `secure/src/functions/uploadLimits.php`,
`public/management/index.php`, `secure/management/command/uploadAsset.php`,
`secure/management/command/importProject.php`, the `upload-limits` arm in
`public/admin/api/index.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md) (*Upload size limits*).

---

### A tag may carry DEFAULT params, separate from mandatory ones (locked 2026-08-16)

**Decision**: `TagRegistry::DEFAULT_PARAMS` holds params written onto a new node
when the author supplied none, distinct from `MANDATORY_PARAMS`, which the
writers refuse a node without. `video` and `audio` default to `controls`. Values
are non-empty strings, never PHP booleans.

**Reasoning**: "this tag needs the attribute to work" and "the author must choose
a value for it" are different statements, and only the second one
`MANDATORY_PARAMS` can express — the writers count an empty string as missing, so
listing a boolean HTML attribute there makes the tag impossible to create.
`<video src="…">` is valid HTML that browsers render as a blank rectangle with no
play button, and `<audio src="…">` has no intrinsic size at all and renders as
nothing. Neither is recoverable by the author: `<script>` is blocked, `on*`
handlers are refused, and the custom-JS feature was removed in beta.3, so no
authored page has a code path to `play()`. The editor was emitting elements the
author had no way to make work.

The string value is the second half of the decision. The renderer emits a PHP
`true` as a bare `controls` while the build compiler runs the same value through
`var_export` and `htmlspecialchars` and emits `controls="1"` — the same thing to
a browser, but a preview-versus-build difference in exactly the class beta.10
spent a release removing. `'controls'` produces identical markup from both, and
HTML permits a boolean attribute to carry its own name as its value.

Defaults apply at creation and when a tag *changes* into one that has them, never
on an ordinary edit: re-adding a `controls` the author has just removed is the
opposite of a default.

**Alternatives considered**: adding `controls` to `MANDATORY_PARAMS` (rejected —
the empty-value rule makes video uncreatable). Forcing it in the renderer
(rejected — removes the author's ability to build an autoplaying muted background
video, which is a legitimate design). `true` as the value (rejected on the
preview/build divergence above). Applying defaults on every edit (rejected — it
would fight the author).

**Source**: `secure/src/classes/TagRegistry.php` (`DEFAULT_PARAMS`,
`defaultParamsFor`), `secure/management/command/addNode.php`,
`secure/management/command/editNode.php`.

---

### `importProject` never replaces an existing project (locked 2026-08-16)

**Decision**: the `overwrite` parameter is removed. A colliding project id is
always refused with `409`, and nothing is written.

**Reasoning**: `overwrite=true` deleted the existing project directory and
recreated it from the archive, birth-writing the importer as sole owner — with no
membership check of any kind. `projects.create` is a global `access: 'any'`
category, so this made "replace any project on this installation and take its id"
available to every signed-in account, including one invited to edit a single
unrelated project. It was reproduced end to end: a non-member replaced another
account's project and `members.json` came back naming the attacker as owner.

A project id is not only an identity, it is the namespace its browser storage
lives in, so reassigning one is a heavier act than a flag on an upload suggests.
Deleting a project already exists as its own owner-gated command; making that the
only route means the destructive step is explicit rather than a side effect of
importing something.

The refusal deliberately says nothing about who owns the existing project or
whether the caller can see it, and no longer echoes its path — the answer is
identical for a project the caller owns and one they have never heard of.

**Alternatives considered**: gating `overwrite` on ownership of the existing
project (rejected — it keeps a delete-by-upload path for a case `deleteProject`
already covers, and one wrong ownership check reopens the whole hole). Renaming
the incoming project automatically (rejected — an import silently landing under a
different id than the caller asked for is its own surprise).

**Source**: `secure/management/command/importProject.php`. Behaviour:
[COMMAND_API.md](COMMAND_API.md) (*Export / Import*).

---

### The storage prefix is shown everywhere and stored nowhere (locked 2026-08-16) (superseded 2026-08-20)

**Decision**: `/admin/storage` shows `qsp_<projectId>_` as a non-editable chip in
front of the key input, and the generated cookie-policy page prints the full
physical key plus one sentence explaining the prefix. The registry continues to
store only the declared key; the prefix is composed at each point of use.

**Reasoning**: after the prefix was introduced, the author declared `cart` and the
browser held `qsp_<project>_cart`, with nothing in the authoring flow saying so.
The cookie-policy page mattered most: it is a disclosure document, and a visitor
who checks it against their own browser has to find the same names there.

Storing the composed form would have been the easy way to show it and is the
mistake worth naming. The declared key is what consent categories are matched on,
what the `data-storage-*` attributes reference and what the pickers offer — an id
baked into the stored key would stop matching the moment the project was imported
under another name. There is no rename command, so a composed id cannot drift out
from under a generated page; clone and import create new projects that generate
their own.

The chip follows the scope: `qs.js` writes no cookies, so showing a prefix in
front of a cookie name would be the same kind of false statement the change
exists to remove.

**Alternatives considered**: an editable field pre-filled with the prefix
(rejected — it invites an author to change or delete a part they do not own, and
a form field is a thing that gets submitted). Showing the physical key only on the
card after saving (rejected — the author learns the name after choosing it, which
is when it is least useful). Persisting the prefixed key (rejected as above).

**Source**: `public/admin/assets/js/pages/storage.js`,
`secure/src/functions/storageHelpers.php` (`qs_storage_physical_key`),
`secure/src/functions/consentLayerHelpers.php`. Behaviour:
[ADMIN_PANEL.md](ADMIN_PANEL.md) §6, §9.10.

---

### An editor fragment composes URLs against the project, not the installation (locked 2026-08-16)

**Decision**: the renderer resolves the project's public base itself, once per
instance, through the same resolution the served page uses — so a page fragment
rendered by the management API carries the same URLs the served page does. Fixed
on the server; the editor's three DOM-insertion sites were left untouched.

**Reasoning**: the visual editor does not reload the preview after each edit. It
asks the management API to render just the node that changed and drops the
returned fragment into the iframe. That fragment was rendered in the
`/management/` request, whose base URL is where the INSTALLATION is, while a
served page's base is where the PROJECT is. An author who inserted a video saw
`http://host/assets/videos/intro.mp4` — a location that serves nothing — and the
same node read `/p/<id>/assets/videos/intro.mp4` after a reload. A broken video
announces itself with an error box; a broken image is a small icon nobody looks
at twice, and this affected every URL attribute the renderer rewrites.

Fixing it in the browser was the obvious shape and the wrong one. It would have
meant re-deriving the project base in JavaScript and rewriting attributes after
parsing — in three places, for eight attributes, on a string the server had
already got wrong, with a second implementation of the base rule to keep in step
with the first. Worse, the client sees three insertion sites while the server has
six commands that return a rendered fragment, so a client-side repair would have
been both duplicated and incomplete. Correcting the source means every caller,
including any added later, is right by construction.

The same resolution — not a second copy of the rule — is what makes a mapped
domain agree. `QS_PUBLIC_BASE_URL` is declared per vhost, and one vhost serves
both the management API and the site, so the fragment render and the page render
read one identical value: `/p/<id>/…` on the authoring host, `/…` on a mapped
domain, in both contexts.

The base was not the only thing the fragment render arrived without. A
multilingual project prefixes every non-asset URL with the current language, and
a render given no language emitted `/about` where the served page emitted
`/en/about` — the same divergence, one attribute over. The renderer now falls
back to the project's default language when the caller supplies none, in the same
constructor and for the same reason: the six commands already compute that value
for the translator, and a default in one place is a thing none of them can
forget. A caller that passes a language — every page template does — is
untouched.

Two things were deliberately left alone. CSS `url()` inside a `style` attribute
is not rewritten in either context — it was consistent before and stays
consistent, and changing that is a change to what authors may write, not a
divergence fix. `ping`, `longdesc` and `background` get scheme safety but no
rebasing, which was already the documented split between URL sinks and rewritable
attributes.

**Alternatives considered**: repairing the fragment in
`preview-iframe-inject.js` (rejected as above). Reloading the preview after every
insertion (rejected — it hides the wrong URL instead of correcting it, and costs
the author their scroll position and selection on every edit). Passing the base
in from each of the six commands (rejected — six copies of one decision is the
duplication the shared resolver exists to prevent).

**Source**: `secure/src/functions/renderBootstrap.php`
(`qs_render_public_base`), `secure/src/classes/JsonToHtmlRenderer.php`.
Behaviour: [ARCHITECTURE.md](ARCHITECTURE.md) §5.1.

## Deployment resource limits and front-end truthfulness (beta.11)

### Per-user quotas exist, and default to unlimited (locked 2026-08-16) (superseded 2026-08-20)

**Decision**: `uploadAsset` and `importProject` enforce two optional per-user
ceilings — total bytes owned, and uploads per period — configured in
`secure/management/config/quota.php`. With no such file, **neither axis limits
anything**. Usage is the measurement `getMySpaceUsage` already produces, not a
second measurer.

**Reasoning**: creating a project and uploading into it are open to every
authenticated account by design. On a shared install that makes filling the disk
an ordinary use of the product rather than an exploit, and no other control
bounds it. That is a missing control on a deployment surface, which is why it
shipped with the deployment work rather than waiting.

Permissive defaults are the load-bearing half of the decision. A limit that
arrived switched on would mean an existing install began refusing uploads
because it was updated — the same failure as a bad release, delivered by a
security feature. So the file is absent by default, and a malformed one is
ignored rather than obeyed. That inverts `filePolicy.php`, where a malformed
override falls back to the *stricter* built-in allowlists; both fail towards not
breaking the install, which points in opposite directions for a deny-list and a
budget.

Measuring by ownership follows from consuming the existing measurement, and has
two consequences that were accepted rather than worked around. A project with
two owners counts in full against both — ownership is a set, not a share, and
that is the direction that cannot be gamed. And bytes belong to the project they
land in, so an invited member's upload counts against the project's owner; an
account owning nothing has no total to exceed. Attributing bytes to the writer
would need per-file uploader records that do not exist, and the residual is
gated by an invitation an owner chose to send. The rate axis is what bounds such
an account, which is part of why there are two axes and not one.

The rate axis is a count rather than a byte rate because volume is already
bounded by the byte axis; what a count bounds is churn — upload, delete, upload
again — which never grows a total but keeps the server working indefinitely.

Enforcement had to defeat the measurement cache. Sizes are cached for five
minutes, and a ceiling compared against a five-minute-old number is a ceiling a
burst walks straight through. The write paths therefore drop the project's
cached measurement after writing, so growth is always exact. A *deletion*
elsewhere still ages out normally, which leaves a total reading high — stricter
than reality, never looser — and the dashboard's existing refresh control
already re-measures on the spot, so the escape hatch was there to point at
rather than to build.

**Alternatives considered**: re-walking every owned project on every upload
(rejected — it pays for freshness on projects nothing touched, and the walk cost
grows with the install); enforcing against the target project's owners rather
than the caller (rejected — it lets one over-quota co-owner freeze a project for
everyone, and the refusal names a budget the person reading it cannot act on);
a byte rate as the second axis (rejected — it duplicates what the byte ceiling
already does); making the per-category asset caps configurable in the same file
(deferred — they are QuickSite policy about what an asset *is*, not a deployment
budget, and mixing the two would put one number in two files).

**Source**: `secure/src/functions/quota.php`,
`secure/management/config/quota.php.example`,
`secure/src/functions/spaceUsage.php` (`qs_invalidate_space_cache`).
Behaviour: [COMMAND_API.md](COMMAND_API.md) *Per-user resource limits*.

### nginx's body limit is generated from PHP's, and is required rather than suggested (locked 2026-08-16)

**Decision**: the generated `dynamic_routes.conf` carries a
`client_max_body_size` line on its `/management/` block, computed from the
serving PHP's `post_max_size` and set deliberately above it. The shipped nginx
vhost example carries it as a required line, and both the setup page and the
README name it.

**Reasoning**: nginx's default is 1 MB, which is *smaller* than the upload size
a normal PHP configuration accepts. So an nginx deployment refuses uploads
QuickSite advertises as fine — and refuses them in the way that hides the
reason, because nginx answers 413 with an HTML error page before PHP runs at
all. Apache's `LimitRequestBody` is unlimited by default, so an Apache install
never sees it and cannot be used to find it.

The value is derived rather than written down for the same reason every other
limit in this engine is: `post_max_size` is `PHP_INI_PERDIR`, so it differs per
server and can differ per directory, and a number in the source would be wrong
on somebody's install while looking authoritative. It is set one megabyte above
PHP's rather than equal to it so that PHP is always the component that refuses
an oversized upload — PHP's refusal is JSON naming the real limit, nginx's is an
HTML page naming nothing.

It goes on the `/management/` block rather than at server level because that is
the only namespace that accepts a file: raising the ceiling there raises it
where uploads land and nowhere else. A vhost that proxies to a second server
block needs its own copy in the public block, which is where the client's bytes
actually arrive — that layout is common enough on hosting panels to be called
out rather than left to be discovered.

**Alternatives considered**: a fixed value in the example file only (rejected —
it is wrong on any server whose PHP differs, and the generated file is the one
deployers actually include); emitting it at server level (rejected — it raises
the body limit for the free web root and everything else the vhost serves);
documenting it as an optional note (rejected — the default is *smaller* than
what the product claims to accept, so a deployment that skips it is broken, not
merely untuned).

**Source**: `secure/src/functions/uploadLimits.php`
(`qs_nginx_client_max_body_size`), `secure/src/functions/NginxConfig.php`,
`public/init.php` (setup page), `secure/deploy/nginx-vhosts.conf.example`.
Behaviour: [COMMAND_API.md](COMMAND_API.md) *Upload size limits*.

### An HTTP error is not assumed to be JSON (locked 2026-08-16)

**Decision**: the admin panel reads every response body as text and parses it
defensively. A non-JSON error becomes a structured envelope carrying the status
code and a sentence derived from it, and the upstream body is never used as the
message.

**Reasoning**: not every answer to a QuickSite request comes from QuickSite.
nginx refusing an oversized body, a proxy, a gateway timeout — each answers
HTML, and `response.json()` throws on all of them. The upload path turned that
throw into a message about unexpected tokens, shown to someone whose actual
problem was a file that was too large. The status code survives where the body
does not, and it is the part that carries meaning.

Passing the upstream body through as the message was rejected on two counts: it
is an unbounded HTML document, and the panel interpolates the message into the
DOM. The status-derived sentence names the likely cause where there is one —
413 is worth naming precisely, because on nginx it is a default that is smaller
than what the product accepts.

Successful responses were left exactly as they were, including an empty body
still reading as null, so no working call site changed shape.

**Alternatives considered**: catching the parse error at each call site
(rejected — three upload paths had already been written independently and two
had made the same assumption, which is what a shared reader exists to prevent);
checking `Content-Type` before parsing (rejected — it decides on a header rather
than on the bytes, and a misdeclared JSON error would then be discarded).

**Source**: `public/admin/assets/js/core/api.js` (`readResponseBody`),
`public/admin/assets/admin.js`, `public/admin/assets/js/pages/dashboard.js`.

### The authoring hostname and a mapped domain stay separate (locked 2026-08-16) (superseded 2026-08-16)

**Decision**: `QS_PROJECT` must never be set on the hostname serving `/admin/`.
This is stated in the README and both shipped vhost examples, and the panel
warns when it detects the combination. **No engine behaviour changed.**

**Reasoning**: a vhost with `QS_PROJECT` serves one project at its root and
answers 404 to any literal `/p/…` request — one domain, one site, which is what
stops a production domain being used to enumerate the install's other projects.
The visual editor previews by loading `/p/<projectId>/` in an iframe. Each is
correct alone; together on one hostname they mean an editor whose preview 404s,
for every project rather than only the mapped one.

Since both halves are behaving as designed, the gap was never in the engine — it
was that nothing said so where a deployer would read it, and nothing noticed when
the configuration was wrong. A warning is the whole fix, and it names the project
the vhost declares so the reader knows which line to remove.

Pointing the preview at the mapped domain was considered and is worse than
documenting it. A mapped domain is a different origin, so the panel's session
cookie is not sent there: a public project would appear to work while a private
one silently failed to load, turning a legible 404 into a bug that depends on
project visibility.

**Alternatives considered**: admitting `/p/<id>/` on a mapped domain when `<id>`
equals `QS_PROJECT` (rejected — it puts a project id back into a routing decision
on the surface where existence was deliberately removed from routing, and reopens
the question the oracle probe exists to close); building the preview URL from the
vhost root when `QS_PROJECT` names the edited project (rejected — it works only
when the edited project happens to be the mapped one, and leaves every other
project on that install unpreviewable with no warning at all).

**Source**: `secure/admin/templates/layout.php`,
`secure/deploy/apache-vhosts.conf.example`,
`secure/deploy/nginx-vhosts.conf.example`, `README.md`. Engine behaviour is
`secure/src/functions/surfaceB.php` and is unchanged.

---

## Production is build-only (beta.11)

### Serving a live project at a mapped domain is withdrawn (locked 2026-08-16)

**Supersedes**: *The authoring hostname and a mapped domain stay separate*
(entry above).

**Decision**: a project is served at `/p/<projectId>/` on the install's own
hostname and nowhere else, for its whole life. **The build is the production
artifact.** The second entry point — a vhost declaring `SetEnv QS_PROJECT <id>`
/ `fastcgi_param QS_PROJECT <id>` and serving that project at its root — is
removed from the engine, along with the rule that made such a domain answer 404
to every literal `/p/…` request. `QS_PUBLIC_BASE_URL`, `QS_TRUSTED_HOSTS` and
`QS_ERROR_PAGE_*` are a different mechanism that merely shares a prefix; they are
unaffected and still read.

**Reasoning**: the superseded entry treated the conflict between mapped-domain
serving and the visual editor as a documentation problem, because both halves
were behaving as designed. That was the right reading of the symptom and the
wrong reading of the cause. The mode itself was the mistake, on two counts that
have nothing to do with the editor.

It put **unoptimised output on the public internet**. A mapped domain rendered
the project live — parsing the page JSON on every request — which is precisely
the work the build exists to do once. The deployment shape that was easiest to
reach was therefore the slowest one a visitor could be given.

And it made every project on the install a **neighbour of a production domain**.
The `/p/` refusal existed only to keep that domain from being used to reach or
enumerate the others, which is a boundary that has to be maintained rather than
one that exists. A build has no such neighbours: it is one site, in its own
folder, with its own vhost, and nothing to enumerate.

Once the mode goes, that refusal has nothing left to protect, and its only
remaining effect was breaking the editor's preview iframe wherever the variable
happened to be set — so it goes with it. The panel warning added for the old
shape is deleted rather than kept: it warned about a configuration that can no
longer do anything.

Nothing is stranded. `/p/<id>/` still serves for development and preview, and it
is the surface the editor already points at.

**Alternatives considered**: keeping the mode and fixing the editor instead
(rejected — it preserves the two real costs above to save a deployment shape that
should not be used, and the narrower variant of that fix puts a project id back
into a routing decision on the one surface where existence was deliberately
removed from routing); keeping the mode but documenting it as development-only
(rejected — a documented footgun in a shipped vhost example is still a shipped
vhost example, and this one was the first thing a deployer read); leaving the
`/p/` refusal in place defensively (rejected — a rule whose stated purpose no
longer exists is one nobody can later reason about, and this one has a measured
cost).

**Source**: `secure/src/functions/surfaceB.php` (the ENV entry branch and the
`/p/` refusal, both removed), `secure/admin/templates/layout.php` (warning
removed), `secure/deploy/apache-vhosts.conf.example`,
`secure/deploy/nginx-vhosts.conf.example`, `README.md`, `docs/ARCHITECTURE.md`
§5.1 / §6 / §10.

### A build is not static and has no feature gap (locked 2026-08-16)

**Decision**: a build is **precompiled pages plus the runtime that serves them**
— a self-contained QuickSite serving exactly one project, in which resolvers,
param routes, server-side auth and `serverFetch` all work. Documentation says so,
and never describes a build as static or as a reduced form of the project.
Anything a built site cannot do is recorded as a **defect in the build**, not as
a property of builds.

**Reasoning**: the difference matters because of what each framing licenses. If
the build is "the static version", every gap found in it is a known limitation
and closes no loop — a feature that fails there is behaving correctly. If the
build is the same site compiled, the same gap is a bug with an owner. Only one of
those framings ever produces a build that works, and the words in the docs are
what decide which one a future reader inherits.

The compilation removes *editing-time* computation: traversing the page JSON on
every request, and the editor machinery around it. It does not remove runtime
behaviour, and there is no point at which it was decided that it should.

**Alternatives considered**: documenting what a static build can and cannot do
(rejected — it was the original roadmap line, and it presumes the gap it would
describe; writing the limitation down is what makes it permanent).

**Source**: `docs/ARCHITECTURE.md` §10 and the renderer/compiler diagram in §4,
`docs/COMMAND_API.md` (Builds), `README.md`. This entry records the framing only;
build behaviour is unchanged by it.

### `ApiResponse` has no default message, and `withMessage()` is mandatory (locked 2026-08-16)

**Decision**: the response-code registry — a table mapping `(status, code)` to a
default message — is deleted, along with `getAllCodes()`, `exportRegistry()` and
the `success()` helper that existed to wrap a registry default. Every response
supplies its own message. `create()` no longer sets one, and a response reaching
`send()` or `toArray()` without a message is refused with a 500 rather than given
a fallback.

**Reasoning**: the registry failed at both halves of its job at once.

It was **never the message anyone wanted**. All 1417 `create()` call sites in the
engine pass their own `withMessage()`, because a useful message names the field,
the file or the limit — which a table keyed on a code cannot know. The 28 sites
that did not were not preferring the default; they were sites nobody had
finished, and each was given a real message before the table was removed. The
improvement is visible from outside: `addNode` with no parameters answered
*"Required parameter missing"* and now answers *"Missing required parameter:
type"*.

And it **covered a small and shrinking fraction of what the engine emits**.
Measured before removal: 99 distinct `(status, code)` pairs unregistered,
producing 65,498 `Unregistered response code` lines — 82% of an 8 MB error log,
every one of them describing a response that was already correct, because the
caller had supplied its own message. The registry's only observable effect had
become to fill the log with warnings about responses that were fine.

**"Mandatory" is enforced twice, and neither mechanism is redundant.** A static
scan walks the token stream of every command and exits non-zero if any `create()`
reaches its terminating `;` without a `withMessage()` — that sees branches no test
ever executes, which is exactly where a forgotten message would hide. A runtime
check catches what the scan cannot: `->withMessage($e->getMessage())` on an
exception with an empty message reads as correct to any static check and produces
a response that says nothing.

The runtime check **fails rather than inventing a message**, and answering 500 is
not a severity choice — a response with no message is a malformed command, and
saying so is true. Answering "Unknown response" instead is the failure mode that
hides, because nobody investigates a response that looks like an answer. In
development it throws where the mistake is; in production it degrades to a 500
and writes **one** log line naming the file and line. That it can fail hard is
safe precisely because all 1417 existing sites carry a message, so nothing
shipped today can reach it — it can only fire on code written afterwards.

**Alternatives considered**: keeping the registry and registering the missing 99
pairs (rejected — it treats the log volume as the problem when the problem is a
fallback nobody reads; the table would need extending on every new code forever);
making the message a required third argument of `create()` (rejected — PHP would
enforce it perfectly, but it means editing 1417 call sites that already pass a
message, and leaves two ways to say one thing; `custom()` already offers that
shape for new code); convention plus a code-review note (rejected — the 28
unfinished sites are what convention alone produced over the project's life).

**Source**: `secure/src/classes/ApiResponse.php`, and the 13 command files listed
by `NOTES/tests/beta11/s210_create_scan.php`.

---

## Editor hygiene (beta.11)

### The editor's tag lists are emitted by the server, never copied (locked 2026-08-17)

**Decision**: `TagRegistry` gains `editorPayload()`, a single method returning
every tag fact the visual editor needs — the classification lists, mandatory
params, per-tag defaults, translation-key params and reserved params.
`preview-config.php` emits it as `PreviewConfig.tagInfo`, and `preview.js` reads
that as its `TAG_INFO`. The client keeps no tag list of its own.

**Reasoning**: `TagRegistry`'s own header has always said *"NEVER define tag
lists anywhere else. Always reference this class."* — and `preview.js` carried a
hand-written mirror of it anyway. The mirror had drifted, which is what a mirror
does: it offered `embed` and `object`, both on the security blacklist, so the
editor advertised tags the server refuses; it listed `alt` as a **mandatory**
param on `img` and `area`, which the server has never required, so the add form
demanded a value nothing was asking for; and it had lost `dl` from its block-tag
list. None of these drifts was introduced deliberately. They accumulated because
adding a tag meant remembering a second file.

The payload is the whole set rather than only the two fields the editor reads
today, because a partial payload recreates the original problem the first time
someone needs a third field: they either extend the payload (fine) or add a
literal to the client (how the mirror started). Emitting everything makes the
client's list-free property structural rather than a habit. It costs about 2 KB
on a page that already carries a 200-field config object.

**Alternatives considered**: a build step that generates the JS from the PHP
(rejected — QuickSite has no build step for its own admin assets, and adding one
to solve a copy-paste problem is the wrong shape); keeping the copy and adding a
test that diffs the two (rejected — a test that guards a duplicate still leaves
the duplicate, and the duplicate is the defect); emitting only `MANDATORY_PARAMS`
(rejected, see above).

**Source**: `secure/src/classes/TagRegistry.php`,
`secure/admin/templates/pages/preview-config.php`,
`public/admin/assets/js/pages/preview/preview.js`.

### `alt` holds a translation key the author picks — in both writers (locked 2026-08-17)

**Decision**: `alt` is an optional param whose value is a translation key,
offered in the editor through the existing searchable key picker. `addNode` no
longer writes an empty-string `alt`; `editNode` no longer generates a
`<page>.itemN.alt` key or the empty translation entry behind it. Whatever the
author chose is stored verbatim; choosing nothing writes no `alt` attribute. The
same rule extends to `title` on `iframe`, expressed as a new
`TagRegistry::TRANSLATION_KEY_PARAMS` map. `TAGS_WITH_ALT` is removed — it
assumed the param is always `alt`, which the map no longer needs to.

**Reasoning**: one attribute had two meanings depending on which command last
touched the node. An image created in the editor got a literal empty `alt`; the
first time it was edited, the same attribute became a generated translation key
with an empty entry created for it. The author asked for neither, and could not
see either. The panel had drifted a third way: it still promised *"Alt text key
will be auto-generated"* next to an element nothing populated, because the
generation it described had already been removed from `addNode`.

Picking the key is what removes the ambiguity, not choosing which of the two
behaviours to keep. The renderer already translates `alt` when the value looks
like a key, so the only missing piece was a way to say which key — and the
picker for that already existed, built for the Complex Element wizards. Reusing
it also means the author can create the key inline, which is what made
generation attractive in the first place.

`title` is on `RESERVED_PARAMS`, and reaching it through the picker is not a
weakening of that reservation: reserved means the translation system owns the
value, which is exactly what a key picker enforces — what stays refused is
typing a literal `title` into the free-text params.

Existing empty `alt` values on disk are left alone. Nothing but test projects
exists, and beta does not carry migrations.

**Alternatives considered**: generating the key in `addNode` too, matching
`editNode` (rejected — consistent, but it keeps inventing keys the author never
asked for and never sees); dropping `alt` handling entirely and leaving it to
the free-text custom-params row (rejected — `alt` is an accessibility attribute
on the two tags where it is required, and burying it under *Advanced* is how it
gets skipped); auto-filling from the asset's own alt metadata as the only source
(rejected as the *only* source, kept as a pre-fill — an asset's alt text
describes the asset, not its use on this page).

**Source**: `secure/src/classes/TagRegistry.php`,
`secure/management/command/addNode.php`,
`secure/management/command/editNode.php`,
`secure/admin/templates/pages/preview/contextual-add.php`.

### `dialog` leaves the allowlist, and does not join the blacklist (locked 2026-08-17)

**Decision**: `dialog` is removed from `ALLOWED_TAGS`, `CONTAINER_TAGS` and the
editor's tag picker. It is **not** added to `BLOCKED_TAGS`.

**Reasoning**: a `<dialog>` is not displayed until it is opened, and opening one
properly means calling `showModal()` or `show()`. Authors cannot run
JavaScript — `<script>` is blacklisted, `on*` handlers are refused server-side,
and the custom-JS feature was removed in beta.3 — and none of the 26 `QS.*`
verbs calls either method. So the editor was offering a tag that renders as
nothing the author has any means to reveal.

The two lists are not interchangeable, and putting it on the blacklist would
have been the easier and wronger move. `BLOCKED_TAGS` is a **security** list:
every entry is there because emitting it would let an author execute code or
load a remote resource. A merely useless tag does not belong in it — and
`isBlocked()` produces a different refusal message, so the author would be told
their dialog was a security problem, which is false. Absent from both lists, it
is refused as an unknown tag, which is what it now is.

Recorded honestly: **it is not completely inert today.** `open` is not a
reserved param, so an author who knows the HTML can type `open` into the custom
params and get a visible — non-modal — box, which the class verbs can then show
and hide like any other element. That path is obscure enough not to be a feature
anyone would find, and it is the path this decision closes. It is named here so
that a later reversal starts from the true picture rather than from "nothing
used it".

**Alternatives considered**: adding it to `BLOCKED_TAGS` (rejected — see above,
it is a security list); adding a `QS.openDialog` verb so the tag becomes usable
(rejected for this release — a new verb is engine surface with a picker,
catalogue entry, renderer allowlist and build allowlist behind it, which is a
feature decision and not editor hygiene); leaving it and documenting that it
needs a hand-typed `open` (rejected — the tag picker is a list of things that
work).

**Source**: `secure/src/classes/TagRegistry.php`; reachability established by
`NOTES/tests/beta11/s29_dialog_probe.php`.

### An emptied page renders a selectable root, not nothing (locked 2026-08-17)

**Decision**: two changes, and both are needed. The root-insert branch in
`addNode` and `addComplexElement` tests for the empty array explicitly instead
of relying on the key comparison. And in editor mode the renderer emits a dashed
placeholder for a structure with no nodes, carrying `data-qs-struct` and an
empty `data-qs-node` — the editor's existing spelling for "the structure root".
The published page still renders nothing.

**Reasoning**: PHP's `range(0, -1)` is a two-element array, not an empty one. So
the key comparison answered false for an empty page, the page was read as
node-shaped, and a root insert rewrote it into a children-wrapper object — a
shape the renderer hands to `renderNode` as a single node, producing an empty
page and an "unknown node type" comment. Verified against the live commands:
after the corrupting insert, the node just added is not addressable at all
(*"Node not found at index 0"*).

The arithmetic fix alone would have been a fix that still stranded the author.
`deleteNode` has no last-node guard — deleting every top-level element is
allowed, and reaches an empty page through the ordinary UI — and selection is
anchored to elements carrying `data-qs-node`. A page with no nodes therefore had
nothing to click, and the add form only opens with a selection. The page was not
merely broken, it was unrecoverable from the editor. "Does not crash" was not
the bar; "the author can add the first element back" was.

The placeholder is editor-only for the same reason the whole editor-mode
apparatus is: a published page with no content is a page with no content, and
inventing a visible box on the live site would be a rendering side effect of an
authoring convenience.

**Alternatives considered**: refusing to delete the last node (rejected — it
forbids a legitimate action to avoid a bug in a different command, and leaves
every already-emptied page stranded); seeding a wrapper node on delete
(rejected — the author deleted it; putting one back silently is the same class
of mistake as the generated alt key); making the whole `<body>` selectable in
editor mode (rejected — it makes every page's root clickable to solve a case
that only arises when a page is empty).

**Source**: `secure/management/command/addNode.php`,
`secure/management/command/addComplexElement.php`,
`secure/src/classes/JsonToHtmlRenderer.php`,
`public/admin/assets/js/pages/preview/preview-iframe-inject.js`.

### `track` requires `kind` and `srclang` (locked 2026-08-17)

**Decision**: the mandatory params for `track` become `src`, `kind`, `srclang`.

**Reasoning**: `kind` defaults to `subtitles`, and HTML requires `srclang`
whenever `kind` is `subtitles`. So a `track` carrying only `src` — exactly what
the editor emitted — is invalid in the default case, names no language, and
gives a player nothing to label the track with or decide when to offer it. Both
are values the author supplies, which is what `MANDATORY_PARAMS` can express;
`controls` on `video` is in `DEFAULT_PARAMS` instead precisely because a boolean
attribute has no value to ask for.

`source` was considered alongside it and deliberately left out: its valid
parameters depend on its parent — a responsive-image source is selected by
`srcset` with `media` or `type`, while a media source uses `src` — which is a
conditional form like the `input` wizard rather than a registry line. The
limitation is documented in `README.md` instead.

**Source**: `secure/src/classes/TagRegistry.php`.

### One byte formatter, and the duplicates were not equivalent (locked 2026-08-17)

**Decision**: the three `formatBytes()` copies — in `deleteProject.php`,
`listProjects.php` and `public/admin/api/index.php` — are deleted and their call
sites moved to `qs_format_size()` in `utilsManagement.php`.

**Reasoning**: they were three implementations of one idea and they disagreed
with each other. Measured across a corpus straddling every rounding boundary:
identical on ordinary sizes (13 of 17 values), but the admin-api copy rounded to
one decimal where the other two used two, the shared helper rounds to whole
numbers above 100 units where the copies kept hundredths, and **none of the
three had a TB unit** — so a large deletion reported a four-digit GB figure.
Consolidating changes what those three sites display at the edges; that was
checked to be safe rather than assumed, by scanning the tree for anything that
parses the strings back. Nothing does: they are display-only fields.

There was also a latent failure with no formatting component at all. Two of the
copies were **global functions declared in command files**, and
`public/admin/api/index.php` both declared a third and requires command files
into its own process — so a fatal redeclare was one endpoint away. Not reachable
today, because the two endpoints that pass a variable command name only ever
build an asset-list or a structure lookup. Reachable the moment anyone adds a
third.

**Source**: `secure/src/functions/utilsManagement.php` and the three former
copies; measured by `NOTES/tests/beta11/s29_formatbytes_probe.php`.

---

### The engine's own cookies are namespaced per project (locked 2026-08-20)

**Supersedes**: *The storage prefix is shown everywhere and stored nowhere*
(locked 2026-08-16), on one point only — that entry's cookie carve-out. Its
rule for a DECLARED registry key is unchanged and still correct.

**Decision**: the two cookies QuickSite writes for itself — the visitor consent
record and the visitor OAuth session id — carry the same `qsp_<projectId>_`
namespace a storage key does, composed through one helper on each side:
`qs_project_cookie_name()` in `storageHelpers.php` and `_cookieName()` in
`qs.js`. A cookie a site author declares in the storage registry is still NOT
prefixed, because QuickSite is not in the write path for one.

**Reasoning**: the earlier entry justified leaving cookies unprefixed with
"`qs.js` writes no cookies". That was true when it was written and is not true
now: `QS.setConsent` writes `consent_prefs`, and the OAuth callback writes
`qs_oauth_user`. A cookie is scoped by origin and path, not by URL prefix, so
every project served at `/p/<id>/` on one host shares one jar. A visitor who
accepted analytics on one project had it read back as consented on the next,
whose banner then never appeared — a consent record silently transferred
between two unrelated sites. `qs_oauth_user` is the same collision applied to
an authentication credential.

The name carries the namespace rather than the path. `Path=/p/<id>/` would
scope it correctly while a project is served under `/p/`, but a built site
serves at `/`, where `Path=/` is right — so the same code would behave one way
in preview and another in production. That is the mode split rejected when the
same question was asked about stripping the storage prefix at build time.

One helper per side rather than a composed name at each call site, because a
cookie is removed by NAME and PATH: a clear that still used the bare name
would not raise anything — it would expire a cookie that does not exist and
leave the real one in the browser, which for the OAuth cookie is a logout that
reports success and does not log anybody out.

Cookies already in a visitor's browser under the old names are orphaned. That
is accepted: the visible effect is a consent banner shown once more, which is
the correct outcome for a record that was never that project's to begin with.

**Alternatives considered**: deriving `Path=/p/<id>/` (rejected — preview and
production would disagree, see above). Prefixing the cookie name at each call
site without a helper (rejected — the set/clear pair is exactly where drift
goes unnoticed). Leaving the OAuth cookie alone as "not a privacy surface"
(rejected — it is a session credential, and the collision is worse there than
for consent). Migrating old cookies to the new names (rejected — no backward
compatibility during beta, and the orphan is harmless).

**Source**: `secure/src/functions/storageHelpers.php`
(`qs_project_cookie_name`), `secure/src/runtime/qs.js` (`_cookieName`),
`secure/src/classes/OAuthHandler.php`, `secure/src/functions/oauthStateStore.php`,
`public/p/index.php`. Found during the beta.11 deployment pass, raised by Sangio
from the storage-prefix precedent.

---

### A storage quota is charged to the project's owner, not the uploader (locked 2026-08-20)

**Supersedes**: *Per-user quotas exist, and default to unlimited* (locked
2026-08-16), on one point only — which account the storage axis is measured
against. That entry's decision that the quotas exist, and that an absent
`quota.php` limits nothing, is unchanged.

**Decision**: `uploadAsset` charges the incoming bytes to the account that owns
the project being written to, and passes the caller separately so the refusal
can be phrased for them. The upload **rate** axis still follows the caller.
`importProject` is unaffected — an import creates a project owned by the caller,
so there the two accounts are the same one.

**Reasoning**: the storage axis measured the CALLER's owned projects while the
bytes landed in a project owned by someone else. A member with upload rights on
another account's project therefore had their own (possibly empty) projects
weighed, was found to have room, and the write went through — so any member
could push an owner past their ceiling indefinitely while spending none of
their own allowance. The two axes answer different questions: storage is about
whose disk grows, rate is about what one actor does, so they follow different
accounts by design rather than by oversight.

A caller who is not the owner is told the outcome and the install-wide ceiling,
and nothing else. The owner's usage total and project count aggregate every
project that account owns, including ones the caller is not a member of and
cannot otherwise see, so naming them in a refusal would disclose them. The
ceiling itself is a property of the install rather than of the owner, so it can
be stated.

When no owner is recorded the check falls back to the caller, so a malformed
`members.json` still enforces something rather than nothing.

**Alternatives considered**: charging both accounts (rejected — the uploader's
disk does not grow, so it would refuse writes for no reason). Refusing
cross-owner uploads outright (rejected — collaborating on someone else's
project is the point of membership). Giving the non-owner the full figures
(rejected on disclosure, see above). Leaving it and documenting the gap
(rejected — it is a resource control that any member could bypass, which is
the failure the control exists to prevent).

**Source**: `secure/management/command/uploadAsset.php`,
`secure/src/functions/quota.php` (`qs_quota_check_storage`). Found by Sangio
during the beta.11 deployment pass, uploading into another account's project.

---

### The update scripts are removed; updating is `git pull` (locked 2026-08-21)

**Supersedes**: *Applying an update is a CLI script, not a command; discovery
stays in the panel* (locked 2026-08-15). That entry's SECOND half stands
unchanged — discovery is still the panel's job, `checkForUpdates` is still
routed, and `operator.php` still decides who sees the notice. What is reversed
is the first half: the scripts it introduced are deleted.

**Decision**: `update.sh`, `update.ps1` and `update.bat` are removed. A git
install is updated with `git pull`, documented in README. The operator notice
stays and now points at that procedure instead of naming a script.

**Reasoning**: the evidence changed, which is why this is a reversal and not a
change of mind.

The scripts wrapped one command — `git pull` — in roughly 1400 lines of shell
across three platforms, to cover a case that does not arise: the repository
publishes no releases, so the archive path they existed for had never run, and
a deployer with a git install already has the command.

And the wrapping cost more than it saved. Every shell script in this beta has
produced a defect: `grep -P` refusing to run under a non-UTF-8 locale,
PowerShell draining stdin so a menu read a half-line, an LF `.bat`
mis-resolving `goto`, a UTF-8 BOM in a written config breaking every panel API
call, `sed -i` stripping every CR from a file it edited, and — in the updater
itself, found the week it was removed — a version comparison that silently
degraded to string equality on the project's own version format, and a lookup
that ignored tags so a repository without releases looked un-updatable. That
is a measured rate, not a worry.

The asymmetry that remains is deliberate: DISCOVERY is worth code because a
procedure cannot tell you it is time to run it, while APPLYING is one command
a person already knows.

**Alternatives considered**: keeping them and fixing the two defects found
(rejected — it fixes this round, not the rate). Keeping only `update.sh` and
dropping the Windows pair (rejected — the split is what produced the `.bat`
and BOM defects, and a half-supported feature is worse than none). Removing
the notice as well (rejected — discovery is the half that earns its keep;
nothing else tells an operator to look). Waiting until releases are published
(rejected — that is the condition for bringing it BACK, recorded in
`NOTES/planning/POST_1_0_IF_REQUESTED.md`, not for keeping it now).

**Source**: deletion of `update.sh` / `update.ps1` / `update.bat`;
`public/admin/assets/js/core/update-notice.js`, `README.md` (*Keeping QuickSite
up to date*). Sangio's ruling, 2026-08-21, at the close of beta.11 sequence 2.

---

### One function answers "what language is this request?" (locked 2026-08-21)

**Decision**: project-language detection lives in a single shared file,
`secure/src/functions/projectLanguage.php`, whose
`qs_resolve_project_language()` is the only place the question is answered.
`TrimParameters` and `Translator` call it and decide nothing themselves.
`Translator::getLang()` is deleted.

**Reasoning**: the answer was written four times. `TrimParameters` seeded a
default in its constructor and overrode it from the URL in `parseUrl()`.
`Translator` re-derived the same thing in its own constructor — the same
membership test against the same config, written a second time — and then, in
`loadTranslations()`, fell back to a method that **did not exist**. Anything
that called `Translator::translate()` statically without constructing a
`Translator` first reached that fourth copy and took the whole request down:
`ApiEndpointManager` does it when compiling a count-sentence binding, and
`CallTransformer` does it when resolving a translation-key argument on an
interaction verb. On the per-project public view the failure arrived as a PHP
fatal on every page of the site.

Four copies is also how a request ends up with the router on one language and
the translator on another, which is the quieter version of the same defect.

The file sits in `src/functions/` and not in `utilsManagement.php` — the usual
home for shared helpers — because both callers travel into a production build,
and `utilsManagement.php` does not. Putting the answer there would have made
every built site fatal on its first page. The build copies this file alongside
`String.php` for the same reason.

The resolver reads the request path the way the router reads it: the optional
`PUBLIC_FOLDER_SPACE` prefix removed, and on `/p/<projectId>/` serving the
project marker removed too. That surface rewrites `REQUEST_URI` part-way
through a request, so without the marker strip the single point would have
answered differently depending on when it was asked — which is not a single
point.

An explicit candidate that the project does not declare is discarded rather
than trusted, so a language value that reached a caller from the request cannot
select a translation file the project never declared.

`Translator`'s loaded table is also dropped when the language changes.
`Translator` caches translations in a static and never invalidated it; that was
harmless only because the static-first path used to be fatal, so a request
could never load one language and then be asked for another. With the fatal
gone that ordering is ordinary — on the per-project view the artifact
regeneration translates before the page template constructs its own
`Translator` — and without the invalidation the first language served would
win every lookup after it. Fixing the fatal without this would have traded a
loud failure for a silent wrong-language render.

**This is the author's site's language only.** The admin panel runs a separate
system — `AdminTranslation`, its own files, chosen by `?lang=` then the admin
session then `Accept-Language`. The two share nothing, and the boundary is
deliberate: they look alike, and merging them would put a visitor-controlled
URL segment in a position to choose what language an operator's own panel
speaks.

**Alternatives considered**: a static method on `Translator` (rejected — the
router needs the answer before a translator exists, and the dependency would
run the wrong way). A static method on `TrimParameters` (rejected — it makes
the translator depend on the URL parser for a question that is not about URLs,
and the static callers have no parser). Leaving the duplication and only
defining the missing method (rejected — it fixes the fatal and keeps the three
remaining copies, so the router/translator split stays possible). Putting it in
`utilsManagement.php` per the usual convention (rejected on evidence: it does
not travel into a build). Folding the admin panel's detection into the same
function (rejected — see the boundary above).

**Source**: `secure/src/functions/projectLanguage.php` (new);
`secure/src/classes/TrimParameters.php`, `secure/src/classes/Translator.php`,
`secure/management/command/build.php`. Sangio's ruling, 2026-08-21, during
beta.11 sequence 3.

---

### The public project view registers the fatal handler too (locked 2026-08-21)

**Decision**: the per-project view at `/p/<projectId>/` registers the shared
fatal handler (`errorHygiene.php`, HTML shape), the way `/management`,
`/admin/api` and `/admin` already do. It is registered inside `init.php`'s
`/p/` block rather than in the view's own entry file.

**Reasoning**: it was the only entry point without one, and the only one facing
anonymous internet visitors. A fatal anywhere in a project render answered
**HTTP 200** with PHP's own error text in the body — absolute server path
included — to whoever asked. The status is wrong on every deployment
regardless of `php.ini`; the disclosure additionally depends on
`display_errors`, which the handler turns off for itself on a production
install. Both close with one call.

Registering in `init.php` rather than in the view's entry file is forced: that
file cannot name `secure/` until `SECURE_FOLDER_PATH` has been resolved, which
is `init.php`'s job. Registering at the first line of the `/p/` block that may
load engine code also puts the **visibility gate** inside the handler's reach,
which registering later would not.

The HTML shape is reused rather than given a fourth variant. A rendered site
cannot answer with a JSON envelope, and the panel's page shape already says the
only two things a visitor may be told: something failed, and the details are in
the server log.

The constraint that shaped the work: refusals on this surface are
byte-identical across identities and targets, so that a private project is
indistinguishable from one that does not exist. A handler that altered the
status, the headers, their order, or the deny body would have reopened that.
It does none of those — on a request with no fatal it emits nothing at all —
and the guarantee was re-proven across three identities (anonymous, an invented
cookie, and a real signed-in non-member) against both a private project and a
nonexistent id.

**Alternatives considered**: registering in `public/p/index.php` after the
`init.php` require (rejected — it leaves `init.php` itself and the whole
visibility gate uncovered, which is the window the worst disclosures live in).
A fourth response shape written for public sites (rejected — the shared file's
whole point is that a fourth hand-rolled copy is how the surfaces drift apart;
the existing HTML shape already discloses nothing). Relying on `display_errors
= Off` in the deployment's `php.ini` instead (rejected — it closes the
disclosure and leaves the `200`, so a broken site still reports healthy to
every monitor).

**Source**: `public/init.php` (`QS_SURFACE_B_ENTRY` block);
`secure/src/functions/errorHygiene.php` (unchanged, reused). Sangio's ruling,
2026-08-21, during beta.11 sequence 3.

---

### A substitution that yields a complete URL is not re-processed (locked 2026-08-22)

**Decision**: an attribute value containing `{{__current_page;lang=xx}}` is
exempt from `processUrl()` in the live renderer, matching the exemption the
compiler already applied. The recognition happens BEFORE substitution, and both
writers use the same predicate as the substitution they guard.

**Reasoning**: the language switch resolves to a complete URL — base, language
and route all present. `processUrl()`'s job is to turn an author's
root-relative path into a full one, so running it over a value that is already
full composed the base twice. On the per-project view the visible result was a
language picker pointing at `/p/<id>/fr/p/<id>/en/`: the project marker doubled
and the language not switched, on every page and in both directions.

The guard that was supposed to prevent it — "does this URL already start with a
language code?" — is anchored at the start of the string, and a per-project URL
starts with the project marker, so it never matched. Making that guard hunt for
a language anywhere in the string was rejected: it would invent a third
behaviour where two already disagreed, and it does not describe the defect. The
base is composed a second time whether or not a language is involved, which is
why a mono-language project doubled too, with no language anywhere in the value.

The exemption is expressed as "this substitution returns a complete URL", not as
"this URL looks complete". A value is exempt because of what produced it, which
is knowable before substitution and unknowable after — once substituted, a
complete URL is indistinguishable from an ordinary root-relative path.

**Which codes count as a language is the project's own set.** The trailing-slash
rule for a URL that is exactly a language code was written as a literal `en|fr`
in both writers and consulted no configuration, so a project speaking `es`/`de`
never received it while `/en` received it on a site with no English at all. Both
writers now read the declared languages. On a mono-language project the set is
empty and the rule does not fire, which is correct: there, a URL that looks like
a language code is an ordinary route.

**Alternatives considered**: widening `processUrl()`'s language guard to find a
code anywhere in the path (rejected — a third behaviour, and it leaves the
mono-language and base-only cases broken). Making `buildLanguageSwitchUrl()`
return a root-relative fragment for `processUrl()` to complete (rejected — it
already has to resolve the base to know the target, so the completion would run
twice and the two results could differ). Detecting completeness after
substitution by testing whether the value starts with the base (rejected —
brittle, and it would exempt any author-written URL that happened to begin with
the base). Fixing the renderer alone and leaving the language codes hardcoded
(rejected by Sangio — the codes are a live defect for any project that speaks
neither English nor French).

**Source**: `secure/src/classes/JsonToHtmlRenderer.php` (`renderAttribute`,
`isLanguageSwitchPlaceholder`, `hasLanguageSwitchPlaceholder`, `processUrl`),
`secure/src/classes/JsonToPhpCompiler.php` (the emitted `processUrl`). Sangio's
ruling, 2026-08-22, during beta.11 sequence 3.

---

### `{{__base_url}}` and `{{__space}}` are removed, not exempted (locked 2026-08-22)

**Decision**: the `{{__base_url}}` and `{{__space}}` system placeholders are
deleted from both writers. The system placeholders that remain —
`{{__current_page}}`, `{{__lang}}`, `{{__public_folder}}`, `{{__current_route}}`
— all REPORT a value; none composes a URL. An author who needs a URL writes a
root-relative one and the engine composes it against the base.

**Reasoning**: both placeholders resolved to an already-based value, which was
then pasted in front of a path the engine bases again. `{{__base_url}}style.css`
rendered as `/p/<id>/<lang>/p/<id>/style.css` — the project marker twice.
`{{__space}}` carried the identical defect, dormant only because a URL space is
empty on most installs.

They were also redundant. `processUrl()` already turns `/style/style.css` into
the correct absolute URL, in every render context, and it is the only thing that
knows the base for the request actually being served. The placeholders were a
hand-rolled version of that, and having both is precisely what produced the
double.

Neither was authored anywhere: zero uses across every project on the install,
zero mentions in the documentation, zero entries in the command help. In
compiled pages `$__base_url` was assigned and never read — dead weight in every
built site.

**Why removed rather than exempted.** Exempting them from re-processing works —
it is what the language switch needed, because that one has to resolve a
complete URL to do its job. But an exemption leaves authors an invisible rule:
some placeholders yield already-based values and must not be re-processed,
others do not, and guessing wrong produces a silently malformed URL with no
error anywhere. A placeholder that nothing uses, that duplicates a mechanism
that already works, and that is wrong when used does not earn that rule.
Removing it deletes the question instead of answering it a third time.

**An unknown placeholder is now emitted verbatim by the compiler**, which is
what the renderer has always done with one. This had to land in the same change:
the compiler turned every unrecognised `{{__name}}` into `$__name`, so a removal
that made two names unrecognised would have put an undefined-variable warning
and an empty string into built pages while the live render showed the literal
text — the same writer-drift the removal exists to reduce. The recognised set is
now a named list in the compiler, mirroring the renderer's map.

**Alternatives considered**: exempting them the way the language switch is
exempted (rejected — see above; it preserves a footgun for a feature with no
users). Keeping them and documenting the rule (rejected — the documentation
would exist only to warn against the feature it documents). Removing
`{{__base_url}}` alone (rejected — `{{__space}}` has the same defect and the
same zero usage; leaving it would mean doing this twice). Removing the reporting
placeholders too (rejected — they return a value rather than composing a URL, so
they carry no defect, and `{{__current_page}}` is genuinely used).

**Source**: `secure/src/classes/JsonToHtmlRenderer.php`
(`getSystemPlaceholders`), `secure/src/classes/JsonToPhpCompiler.php`
(`generateSystemVariables`, `convertPlaceholdersToPhp`, `SYSTEM_PLACEHOLDERS`).
Sangio's ruling, 2026-08-22, during beta.11 sequence 3.


### A build lives outside the served directory, not behind a deny rule (locked 2026-08-22)

**Decision**: A project's build is written to `secure/projects/<id>/qs_build/<name>/`
— a sibling of the project's `public/`, not a child of it. `downloadBuild` is the
only way to fetch one: it archives the folder on demand, streams it, and stores
nothing.

**Reasoning**: `/p/<id>/` serves out of a project's `public/` and nowhere else, so
moving the output one level up makes a build unreachable by construction rather
than by rule. That mattered because the boundary did not compose where it stood:
on a public project an anonymous request for the build's `.zip` returned the whole
archive, and `build_manifest.json` — which carries the complete compiled route
list, unlinked pages included — returned 200 as well, while the individual files
inside the same build correctly answered 403. Three doors into one directory, two
of them open.

**A deny file would not have fixed it.** `/p/` serving runs through PHP's
passthrough, not the web server's own file handling, so an `.htaccess` dropped in
that directory is not consulted the way its presence suggests. A rule that looks
like protection and is not is worse than no rule.

**The exposure and the missing download were one defect.** `downloadBuild`
contained no `header()` call and no `readfile`: it answered a JSON envelope whose
`download_url` pointed at that static path. The archive was anonymously reachable
*because* the static URL was the only fetch path that existed. So moving the
build and making the command actually stream could not be separated — either
alone leaves the feature broken. Streaming also puts the download behind the
dispatcher's authentication, which a statically served file never had.

**No stored zip.** The build used to write both an expanded folder and an archive
of that same folder, paying disk twice for one deliverable and leaving the archive
free to go stale against the folder beside it. Generating on download removes both
problems and costs a compression pass per download — the folder is what `deployBuild`
copies, so nothing wanted the stored archive in the first place.

**Alternatives considered**: an `.htaccess` deny inside `public/build/` (rejected —
not consulted on this path, see above); tightening the passthrough's extension
allowlist so `.zip` and `.json` are refused (rejected — it fixes two spellings of
the hole rather than the hole, and every future artifact type reopens the
question); keeping the static URL and gating it on membership (rejected — that
rebuilds the dispatcher's authentication in a second place, which is where the
two copies drift apart).

**Source**: `secure/src/functions/utilsManagement.php` (`qs_build_root`,
`qs_build_path`, `qs_build_current`, `qs_build_is_complete` — the one derivation
every caller uses), `secure/management/command/build.php`,
`secure/management/command/downloadBuild.php`,
`public/admin/assets/js/core/api.js` (`downloadFile`).
[ARCHITECTURE.md §10](ARCHITECTURE.md), [COMMAND_API.md](COMMAND_API.md).
Sangio's ruling, design conversation 2026-08-21.

### One build per project, and a second build is refused rather than overwriting (locked 2026-08-22)

**Decision**: A project holds at most one build. `build` answers
`409 conflict.already_exists` while one exists and names `downloadBuild` and
`deleteBuild` in the response. A build that FAILS removes its own partial
directory. `listBuilds` and `cleanBuilds` are deleted; `getBuild`, `deleteBuild`
and `downloadBuild` take no parameters at all.

**Reasoning**: A build is a regenerable artifact of the project's current state,
and builds count toward the owner's space quota — so keeping a history of them
charges the user for copies of something they can always rebuild. At one build
the retention question disappears: "delete builds older than X" has nothing to
range over, and a 0-or-1 element array carries strictly less than a command that
answers with the build itself.

**Refusing is what makes it safe, and it is simpler than the alternative.** With
a single slot, overwriting would destroy a good build to make one that might
fail. Refusing means the only thing that destroys a build is the user's own
`deleteBuild`, so there is never an old build to protect during a build — which
is why a build-to-staging-then-promote swap was weighed and dropped: it buys the
same safety with more machinery.

**Cleanup on failure is load-bearing, not tidying.** `release_build_lock()`'s
comment claimed "release lock and cleanup on error" and its body released the
lock and nothing else; of roughly thirty failure exits exactly one also removed
the directory. Every other failure left a partial on disk forever, quota-counted
and indistinguishable from a good build. Under refuse-if-exists that partial
would block the next build outright — so the cleanup the comment already claimed
is what the design needs to be true.

**Belt and braces.** `build_manifest.json` is written last, so its absence marks
an unfinished build. If the cleanup itself fails, the survivor is still
identifiable: `getBuild` reports `complete: false`, `downloadBuild` refuses it
(`409 build.incomplete`) rather than handing over a broken deliverable, and
`deleteBuild` removes it — the user recovers without touching the filesystem.

**Alternatives considered**: keeping the newest N builds (rejected — N > 1 is a
history of regenerable artifacts charged to the user's quota, and it keeps every
retention question alive); overwriting the existing build (rejected — destroys a
good artifact to attempt one that may fail); building to a staging directory and
promoting on success (rejected — equivalent safety, more moving parts, and
nothing to protect once the user has deliberately deleted the old build);
keeping `listBuilds` as a convenience (rejected — at one build it is `getBuild`
with a weaker shape and one more command to keep registered in nine places).

**Source**: `secure/management/command/build.php` (`abort_build`, the retention
refusal), `getBuild.php`, `deleteBuild.php`, `downloadBuild.php`,
`secure/src/functions/utilsManagement.php`. [COMMAND_API.md](COMMAND_API.md),
[ARCHITECTURE.md §10](ARCHITECTURE.md). Sangio's ruling, design conversation
2026-08-21.
