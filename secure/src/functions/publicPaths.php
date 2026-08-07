<?php
/**
 * Install-layout removal from response bodies (beta.10 C12 F-C12-4, C13 F-C13-18).
 *
 * A response that names a file is useful. A response that names
 * `C:\wamp64\www\template_vitrinne\secure\projects\p\...` also hands the caller
 * the install location, the OS, the account the server runs as and usually the
 * web root. C12 removed exactly that string from FATALS; it was still going out
 * in ordinary 200s from thirteen endpoints.
 *
 * THE RULE — a response never publishes the install layout:
 *   1. under the current project -> relative to the project root
 *                                   ("templates/model/json/pages/home/home.json")
 *   2. elsewhere under the install root -> relative to the install root
 *                                   ("secure/logs/p/x.json", "public/build")
 *   3. a root itself -> "." — never the empty string, which reads as "missing"
 *   4. anything else (a caller-supplied deploy target, a system temp dir) is
 *      left alone. It is not the install layout, and deciding by shape alone
 *      that a leading "/" is a filesystem path would rewrite every URL the
 *      product returns — route paths, asset paths and API endpoint paths all
 *      start with "/". The C12 suite asserts no response carries such a path,
 *      so a future one is caught there rather than silently mangled here.
 * Separators are normalised to "/" inside the rewritten token only.
 *
 * NOT gated on qs_is_development(). An exception message is diagnostics and is
 * rightly dev-gated (qs_safe_error_message); a `file` field in a 200 is part of
 * the API contract, and a contract that changes shape between development and
 * production is how environment-only bugs get made.
 *
 * Dependency-free and constant-tolerant on purpose: ApiResponse.php requires it,
 * and ApiResponse is loaded on paths where PROJECT_PATH is not defined at all
 * (global commands, a caller with zero memberships). Every constant read is
 * guarded; with no root resolvable the scrub is the identity function.
 */

/**
 * The prefix table for this request: longest root first.
 *
 * `key` is separator-normalised (and case-folded on Windows) so it can be
 * matched against a likewise-folded copy of the subject. The measured leaks mix
 * separators inside ONE path —
 * `C:/wamp64/www/tv\secure\projects\p/templates/...` — because SERVER_ROOT is
 * derived from a forward-slash document root while PROJECT_PATH is glued with
 * DIRECTORY_SEPARATOR. A literal str_replace on the constant misses those: that
 * is not hypothetical, it is why listPages' existing
 * `str_replace(PROJECT_PATH . '/templates/model/json/pages/', '', $file)`
 * silently fails and ships the absolute path today. Folding first is what makes
 * the match reliable.
 *
 * `anchor` is the longest separator-free segment of the root, used as a cheap
 * pre-filter: a subject that does not contain it cannot contain the root, and
 * testing it costs one stripos on the raw string with no allocation — which is
 * what keeps this off the hot path when a large payload has no path in it.
 */
function qs_public_path_roots(): array
{
    // Memoised, but keyed on PROJECT_PATH rather than "have I run yet". A
    // dispatcher can build an ApiResponse BEFORE qs_load_project_context()
    // defines PROJECT_PATH — an auth refusal, an invalid project id — and a
    // plain `if ($cache !== null)` would then pin a project-less table for the
    // rest of the process. That is not a disclosure (the install root is still
    // stripped; paths merely render install-relative instead of
    // project-relative) but it is a wrong answer that would only ever show up
    // on one request shape, which is the hardest kind to find later.
    static $cache = null;
    static $cachedProject = null;
    $project = defined('PROJECT_PATH') ? (string)PROJECT_PATH : '';
    if ($cache !== null && $cachedProject === $project) {
        return $cache;
    }
    $cachedProject = $project;
    $roots = [];
    foreach (['PROJECT_PATH', 'SECURE_FOLDER_PATH', 'SERVER_ROOT'] as $const) {
        if (!defined($const)) {
            continue;
        }
        $key = rtrim(str_replace('\\', '/', (string)constant($const)), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $key = strtolower($key);
        }
        if ($key === '' || isset($roots[$key])) {
            continue;
        }
        // A drive letter ("c:") is not usable as an anchor — it is two bytes and
        // appears in unrelated text — so segments ending in ':' are dropped.
        $segments = array_values(array_filter(
            explode('/', $key),
            static function ($s) { return $s !== '' && substr($s, -1) !== ':'; }
        ));
        usort($segments, static function ($a, $b) { return strlen($b) <=> strlen($a); });
        $roots[$key] = [
            'key'    => $key,
            'len'    => strlen($key),
            'anchor' => $segments[0] ?? '',
            // What replaces the root. The project root and the install root both
            // become "", so what follows reads relative to whichever matched. A
            // secure folder that is NOT under the install root (a relocated
            // install) keeps its own folder name, so the two namespaces stay
            // distinguishable; when it IS under the install root the longer
            // SERVER_ROOT-relative form wins by ordering and the label is unused.
            'label'  => $const === 'SECURE_FOLDER_PATH' ? basename($key) . '/' : '',
        ];
    }
    // Longest first: the project root must win over the install root that
    // contains it, which it does at equal offsets by being tested first.
    $cache = array_values($roots);
    usort($cache, static function (array $a, array $b): int { return $b['len'] <=> $a['len']; });
    return $cache;
}

/**
 * Rewrite every install-root occurrence inside one string.
 *
 * Occurrences are located with strpos on a folded copy — same byte length, so
 * offsets carry over — and only the matched path TOKEN is walked byte by byte.
 * A string with no root in it is returned identical and untouched, which is what
 * keeps this off page content, CSS and translations.
 */
function qs_scrub_path_string(string $s): string
{
    if ($s === '') {
        return $s;
    }
    $roots = qs_public_path_roots();
    if ($roots === []) {
        return $s;
    }

    // Pre-filter on the RAW string: no allocation, no normalisation.
    $possible = false;
    foreach ($roots as $r) {
        if ($r['anchor'] === '' || stripos($s, $r['anchor']) !== false) { $possible = true; break; }
    }
    if (!$possible) {
        return $s;
    }

    $probe = str_replace('\\', '/', $s);
    if (DIRECTORY_SEPARATOR === '\\') {
        $probe = strtolower($probe);
    }
    $any = false;
    foreach ($roots as $r) {
        if (strpos($probe, $r['key']) !== false) { $any = true; break; }
    }
    if (!$any) {
        return $s;
    }

    $n   = strlen($s);
    $out = '';
    $pos = 0;
    while ($pos < $n) {
        $best   = null;
        $bestAt = $n;
        foreach ($roots as $r) {                       // longest first
            $at = strpos($probe, $r['key'], $pos);
            if ($at !== false && $at < $bestAt) { $bestAt = $at; $best = $r; }
        }
        if ($best === null) {
            $out .= substr($s, $pos);
            break;
        }
        $out .= substr($s, $pos, $bestAt - $pos);
        $i = $bestAt + $best['len'];
        while ($i < $n && ($s[$i] === '/' || $s[$i] === '\\')) { $i++; }

        // The rest of the path token, separators normalised. Stops at the first
        // byte that cannot be inside a path, so a path embedded in a sentence
        // ("Failed to copy file: <path>") keeps its sentence.
        $tail = '';
        while ($i < $n && strpos(" \t\r\n\"'<>|?*", $s[$i]) === false) {
            $tail .= $s[$i] === '\\' ? '/' : $s[$i];
            $i++;
        }
        $rel  = $best['label'] . $tail;
        $out .= ($rel === '' ? '.' : $rel);
        $pos  = $i;
    }
    return $out;
}

/**
 * Recursively rewrite every string in a response payload.
 *
 * Keys as well as values: a payload keyed BY path (a per-file map) would
 * otherwise publish in its keys what it no longer publishes in its values. A key
 * that is a path necessarily contains a separator, so the cheap byte test in
 * front of the key scrub cannot miss one.
 *
 * Shaped for the size of what it walks. Response payloads are the largest
 * structures the engine handles — the biggest real page in this install is
 * 530 KB across 6,480 nodes — so the walk returns the ORIGINAL array whenever
 * nothing changed (PHP compares identical zend_arrays in O(1), so an untouched
 * subtree costs nothing to propagate) and materialises a replacement only from
 * the first changed element onward. A by-reference in-place walker measured
 * 3.4 ms on that payload against 1.8 ms for this one, because taking a
 * reference into an array defeats copy-on-write for every node it touches.
 */
function qs_scrub_paths($value)
{
    $roots = qs_public_path_roots();
    if ($roots === []) {
        return $value;
    }
    // Resolved ONCE here and carried down, rather than memoised in a static
    // inside the recursion: a static would outlive the roots table it was built
    // from, which is the staleness qs_public_path_roots() just stopped having.
    // Re-deriving it per node instead would cost 6,480 calls on a large page.
    return qs_scrub_paths_walk($value, array_column($roots, 'anchor'));
}

/** The recursion. `$anchors` is the hoisted pre-filter; see qs_scrub_paths. */
function qs_scrub_paths_walk($value, array $anchors)
{
    if (is_string($value)) {
        return qs_scrub_path_string($value);
    }
    if (!is_array($value)) {
        return $value;
    }

    $out = null;
    foreach ($value as $k => $v) {
        $nk = $k;
        $nv = $v;
        if (is_string($v)) {
            // Pre-filter inlined: at 6,480 nodes the per-node CALL is the cost,
            // not the work inside it.
            foreach ($anchors as $a) {
                if ($a === '' || stripos($v, $a) !== false) { $nv = qs_scrub_path_string($v); break; }
            }
        } elseif (is_array($v)) {
            $nv = qs_scrub_paths_walk($v, $anchors);
        }
        if (is_string($k) && (strpos($k, '/') !== false || strpos($k, '\\') !== false)) {
            $nk = qs_scrub_path_string($k);
        }

        if ($out !== null) {
            $out[$nk] = $nv;
        } elseif ($nk !== $k || $nv !== $v) {
            $out = [];
            foreach ($value as $ok => $ov) {
                if ($ok === $k) { break; }
                $out[$ok] = $ov;
            }
            $out[$nk] = $nv;
        }
    }
    return $out ?? $value;
}
