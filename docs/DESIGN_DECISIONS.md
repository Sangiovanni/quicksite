# QuickSite — Locked Design Decisions

_Last updated: 2026-06-11._

> Canonical log of design decisions that have been **locked** during the
> project's evolution. Each entry captures the *why* — what was chosen,
> the reasoning behind it, the alternatives weighed and rejected, and
> pointers to the source code + behavioural docs.
>
> Behavioural reference (the *what*) lives in [ARCHITECTURE.md](ARCHITECTURE.md),
> [ADMIN_PANEL.md](ADMIN_PANEL.md), [COMMAND_API.md](COMMAND_API.md),
> [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md), and [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).
> This file is the *why*. Together the two halves form the full doc.

> _Maintainers note:_ append a new entry every time a non-trivial design
> decision is locked — at lock time, not after implementation. This file
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

### Stylesheet editor — full scope committed, no fallback gate (locked 2026-06-11)

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

### Project-to-workflow exporter ships in beta.9 (locked 2026-06-11)

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
