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
   that's deferred to a separate scope-aware-rendering concern
   landing closer to Slice 4 — chip filed (task_5b20a582).

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
