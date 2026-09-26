<?php
/**
 * The runtime handoff — everything the server tells qs.js about this page.
 *
 * A rendered page ends with a run of `<script>` tags: the route schema, the
 * storage namespace, the library itself, the consent map, the theme wiring, the
 * API config, the enum tables, this page's count-sentence strings, this route's
 * state stores, and what the server-side resolver already fetched. Together they
 * are the contract between PHP and the browser runtime.
 *
 * WHY THIS FILE EXISTS. That contract had TWO writers that did not agree.
 * PageManagement (the live `/p/<projectId>/` render) emitted seven blocks;
 * Page.php (a compiled page) emitted four, and the compiler emitted one of the
 * missing three by itself. A built site therefore lost QS_CONSENT, QS_RESOLVED
 * and QS_RESOLVED_BY_INDEX — which is not a cosmetic gap:
 *
 *   - qs.js reads an absent window.QS_CONSENT as "no consent layer configured"
 *     and lets every storage write through. A project that had configured
 *     consent lost the gate in production, silently, while its own policy pages
 *     went on promising a banner.
 *   - without the resolved blocks, a state store bound to the same endpoint the
 *     resolver already fetched re-fetches it on load, and client code has no
 *     access to the values the page was rendered with.
 *
 * ⚠ ORDER IS PART OF THE CONTRACT, not formatting.
 *   - the route schema and the storage namespace go BEFORE qs.js, because its
 *     IIFE reads window.QS_ROUTES and window.QS_PROJECT synchronously at load;
 *     after it, the matcher has already run against undefined
 *   - the state stores go AFTER qs-api-config, because a store's endpoint
 *     resolves against QS_API_ENDPOINTS
 *   - the page-events script goes LAST, because an onload chain can call
 *     fetchState and needs the stores to exist
 *
 * ⚠ WHAT IT DOES NOT DO: gather. Each caller supplies values, because the
 * SOURCES legitimately differ — a live render reads data/state-stores.json for
 * the current route, a compiled page already has that route's stores baked in.
 * What must never differ is the order and the content, and that is what lives
 * here.
 *
 * ⚠ EVERY VALUE IN AN INLINE SCRIPT GOES THROUGH qs_inline_script_json(), here
 * and in every other file that writes one. See that function for why.
 */

if (!function_exists('qs_inline_script_json')) {
    /**
     * A data value, as the JavaScript expression that reads it, for writing
     * inside an inline `<script>` element.
     *
     * The HTML tokenizer reads a script element's content before JavaScript
     * does, and it knows nothing of JavaScript strings: it ends the element at
     * the first end tag it meets, and a `<!--` switches it into states where
     * the real end tag no longer counts. A `<` taken from a value can do either,
     * whatever quoting surrounds it. So the rule is that the element's content
     * holds no `<` from a value at all:
     *
     *   JSON_HEX_TAG            writes `<` and `>` as \u003C and \u003E, which
     *                           JavaScript reads as the same characters. This is
     *                           the flag the rule rests on.
     *   JSON_UNESCAPED_SLASHES  kept. The `\/` it switches off only existed to
     *                           break an end tag, which the flag above already
     *                           makes impossible; keeping it leaves every page
     *                           whose values hold no `<` or `>` byte-identical.
     *   unicode left escaped    (no JSON_UNESCAPED_UNICODE) — as before, so every
     *                           non-ASCII character, U+2028 and U+2029 included,
     *                           is written \uXXXX.
     *   no HEX_AMP/APOS/QUOT    those matter inside an HTML attribute; script
     *                           data has no character references and no
     *                           attribute quotes to break.
     *
     * One function for every writer, so the rule has no exceptions: the runtime
     * handoff's blocks below, both theme scripts (Page.php, PageManagement.php),
     * the component preview (public/p/index.php) and the admin panel's own config
     * blocks. It lives HERE because a build copies this file and a built page
     * calls it at request time; a file of its own would have to join the build's
     * copy list too.
     *
     * A value json_encode() cannot encode (invalid UTF-8) gives '', as it did
     * when each writer called json_encode() itself.
     *
     * @param mixed $value Any JSON-encodable value.
     */
    function qs_inline_script_json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}

if (!function_exists('qs_runtime_handoff')) {
    /**
     * Emit the whole handoff for one page.
     *
     * @param array $ctx {
     *   base:               string  URL prefix for script srcs, trailing slash
     *   contentPath:        string  filesystem dir holding scripts/
     *   projectKey:         string  PROJECT_NAME — the browser-storage namespace
     *   themeEnabled:       bool
     *   themeToggleEnabled: bool
     *   consentPayload:     ?array  from qs_consent_payload(), null when off
     *   countStrings:       array   from qs_api_count_strings() — this page's
     *                               language, for count-sentence bindings
     *   stateStores:        array   this route's stores ({storeId => def})
     *   resolverConfigs:    array   this route's resolvers (for hydration matching)
     *   resolvedVars:       array   what they resolved to
     *   pageEventsScript:   string  precompiled <script> tag, or ''
     *   extraScripts:       array   additional script URLs
     * }
     */
    function qs_runtime_handoff(array $ctx): string
    {
        $base        = (string) ($ctx['base'] ?? '/');
        $contentPath = (string) ($ctx['contentPath'] ?? '');
        $projectKey  = (string) ($ctx['projectKey'] ?? 'default');

        $out = '';

        // 1. Route schema — BEFORE qs.js (see the ordering note above).
        if ($contentPath !== '' && file_exists($contentPath . '/scripts/qs-route-schema.js')) {
            $out .= '<script src="' . $base . 'scripts/qs-route-schema.js"></script>';
        }

        // 2. Storage namespace. It MUST come from the server: at /p/<id>/ the id
        //    is a URL segment, but a built site is served from its own root and
        //    the path carries no id at all, so anything derived from
        //    location.pathname would give development and production different
        //    key prefixes for the same site.
        $out .= '<script>window.QS_PROJECT=' . qs_inline_script_json($projectKey) . ';</script>';

        // 3. The library.
        $out .= '<script src="' . $base . 'scripts/qs.js"></script>';

        // 4. Consent map — drives qs.js write-gating. Emitting nothing means
        //    "no layer configured", which is why the payload must be passed in
        //    rather than assumed absent.
        if (!empty($ctx['consentPayload']) && is_array($ctx['consentPayload'])) {
            $out .= qs_consent_hydration_script($ctx['consentPayload']);
        }

        // 5. Theme toggle wiring.
        if (!empty($ctx['themeEnabled']) && !empty($ctx['themeToggleEnabled'])) {
            $out .= qs_theme_toggle_script($projectKey);
        }

        // 6. API config, then the enum registry. Enums load even when empty so
        //    QS.enum has a table to look against instead of warning.
        if ($contentPath !== ''
            && file_exists($contentPath . '/scripts/qs-api-config.js')
            && filesize($contentPath . '/scripts/qs-api-config.js') > 100) {
            $out .= '<script src="' . $base . 'scripts/qs-api-config.js"></script>';
        }
        if ($contentPath !== '' && file_exists($contentPath . '/scripts/qs-enums.js')) {
            $out .= '<script src="' . $base . 'scripts/qs-enums.js"></script>';
        }

        // 6b. Count-sentence strings, in THIS page's language.
        //
        // They cannot travel in qs-api-config.js above: that file is written
        // once per project, so whichever language wrote it last would be served
        // to everyone. The compiled config carries the translation KEYS, which
        // are language-independent; the sentences ride the page, like every
        // other per-request value in this run.
        $countStrings = is_array($ctx['countStrings'] ?? null) ? $ctx['countStrings'] : [];
        if (!empty($countStrings)) {
            $out .= '<script>window.QS_COUNT_STRINGS=' . qs_inline_script_json($countStrings) . ';</script>';
        }

        // 7. This route's state stores.
        $stateStores = is_array($ctx['stateStores'] ?? null) ? $ctx['stateStores'] : [];
        if (!empty($stateStores)) {
            $out .= '<script>window.QS_STATE_STORES=' . qs_inline_script_json($stateStores) . ';</script>';
        }

        // 8 + 9. Resolver handoff.
        $out .= qs_resolved_hydration_scripts(
            $stateStores,
            is_array($ctx['resolverConfigs'] ?? null) ? $ctx['resolverConfigs'] : [],
            is_array($ctx['resolvedVars'] ?? null) ? $ctx['resolvedVars'] : []
        );

        // 10. Caller-supplied extras.
        foreach (($ctx['extraScripts'] ?? []) as $src) {
            $out .= '<script src="' . htmlspecialchars((string) $src, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        // 11. Page events LAST — an onload chain may call fetchState.
        $out .= (string) ($ctx['pageEventsScript'] ?? '');

        return $out;
    }
}

if (!function_exists('qs_resolved_hydration_scripts')) {
    /**
     * `window.QS_RESOLVED` and `window.QS_RESOLVED_BY_INDEX`.
     *
     * QS_RESOLVED is store-keyed and exists to SKIP work: when the server-side
     * resolver already fetched the endpoint a state store is bound to, the
     * store seeds itself from these values and does not fetch again on load.
     * The data is already in the DOM; a second request for it on first paint is
     * pure waste. Later fetches (search, paginate, load more) are unaffected.
     *
     * The match is "same endpoint, same field name". An author who exposes a
     * resolver key under a different name than the store's field gets no
     * hydration and a normal fetch — a silent no-op, not an error.
     *
     * QS_RESOLVED_BY_INDEX mirrors the PHP-side `r0` / `r1` namespace, so client
     * code can address values the same way a template does. It is emitted
     * whenever the route resolved anything, with or without state stores.
     *
     * @param array $stateStores     This route's stores.
     * @param array $resolverConfigs This route's resolvers.
     * @param array $resolvedVars    Flat + `rN` values from the lifecycle.
     */
    function qs_resolved_hydration_scripts(array $stateStores, array $resolverConfigs, array $resolvedVars): string
    {
        if (empty($resolverConfigs) || empty($resolvedVars)) {
            return '';
        }

        $out = '';

        // --- store-keyed hydration ---
        if (!empty($stateStores)) {
            $resolverEndpoints = [];
            foreach ($resolverConfigs as $cfg) {
                if (is_array($cfg) && !empty($cfg['endpoint'])) {
                    $resolverEndpoints[] = (string) $cfg['endpoint'];
                }
            }
            $resolverEndpoints = array_unique($resolverEndpoints);

            $byStore = [];
            foreach ($stateStores as $storeId => $store) {
                if (!is_array($store)) {
                    continue;
                }
                $storeEndpoint = (string) ($store['endpoint'] ?? '');
                if ($storeEndpoint === '' || !in_array($storeEndpoint, $resolverEndpoints, true)) {
                    continue;
                }
                $hydration = [];
                foreach (($store['fields'] ?? []) as $fieldName => $fieldDef) {
                    // Request-only fields carry nothing back from the server.
                    $dir = $fieldDef['dir'] ?? 'request';
                    if ($dir !== 'response' && $dir !== 'both') {
                        continue;
                    }
                    if (array_key_exists($fieldName, $resolvedVars)) {
                        $hydration[$fieldName] = $resolvedVars[$fieldName];
                    }
                }
                if (!empty($hydration)) {
                    $byStore[$storeId] = $hydration;
                }
            }
            if (!empty($byStore)) {
                $out .= '<script>window.QS_RESOLVED=' . qs_inline_script_json($byStore) . ';</script>';
            }
        }

        // --- per-resolver namespace ---
        $byIndex = [];
        foreach ($resolvedVars as $key => $val) {
            // `rN` keys only, so a flat variable an author happened to name `r1`
            // stays out of the namespaced bucket.
            if (is_string($key) && preg_match('/^r\d+$/', $key) && is_array($val)) {
                $byIndex[$key] = $val;
            }
        }
        if (!empty($byIndex)) {
            $out .= '<script>window.QS_RESOLVED_BY_INDEX=' . qs_inline_script_json($byIndex) . ';</script>';
        }

        return $out;
    }
}

if (!function_exists('qs_theme_toggle_script')) {
    /**
     * The `[data-theme-toggle]` wiring: set the icon and label on load and on
     * click, and persist the choice under this project's own key.
     *
     * The key is namespaced by project for the same reason QS_PROJECT is: two
     * built sites deployed under one origin must not share a theme setting.
     */
    function qs_theme_toggle_script(string $projectKey): string
    {
        $js  = '(function(){';
        $js .= 'var key=' . qs_inline_script_json('qs-theme-' . $projectKey) . ';';
        $js .= 'function apply(t){document.documentElement.setAttribute("data-theme",t);try{localStorage.setItem(key,t);}catch(e){}}';
        $js .= 'function sync(t){document.querySelectorAll("[data-theme-toggle]").forEach(function(b){';
        $js .= 'var icon=b.querySelector(".theme-switch-icon");';
        $js .= 'var lbl=b.querySelector(".theme-switch-label");';
        $js .= 'if(icon)icon.textContent=t==="dark"?"☀️":"🌙";';
        $js .= 'if(lbl)lbl.textContent=t==="dark"?"Light mode":"Dark mode";';
        $js .= 'b.setAttribute("aria-pressed",t==="dark"?"true":"false");';
        $js .= '});}';
        $js .= 'document.addEventListener("DOMContentLoaded",function(){';
        $js .= 'var cur=document.documentElement.getAttribute("data-theme")||"light";';
        $js .= 'sync(cur);';
        $js .= 'document.querySelectorAll("[data-theme-toggle]").forEach(function(b){';
        $js .= 'b.addEventListener("click",function(){';
        $js .= 'var cur=document.documentElement.getAttribute("data-theme")||"light";';
        $js .= 'var next=cur==="dark"?"light":"dark";apply(next);sync(next);';
        $js .= '});});';
        $js .= '});';
        $js .= '})();';
        return '<script>' . $js . '</script>';
    }
}

/**
 * The `<script>` tag that hands a payload to qs.js.
 *
 * ⚠ ABSENCE IS MEANINGFUL. qs.js reads a missing window.QS_CONSENT as "this
 * project has no consent layer" and lets every storage write through. That is
 * correct for a project that never configured one — and it is why a build that
 * merely FORGOT to emit the payload did not look broken: the gate failed open,
 * silently, while the project's own policy pages went on promising a banner.
 * Both surfaces emit through this one function so neither can forget.
 *
 * @param array $payload From qs_consent_payload().
 */
if (!function_exists('qs_consent_hydration_script')) {
function qs_consent_hydration_script(array $payload): string
{
    return '<script>window.QS_CONSENT=' . qs_inline_script_json($payload) . ';</script>';
}
}
