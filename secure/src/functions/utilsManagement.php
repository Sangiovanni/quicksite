<?php

// Beta.8 A1 — paramRoutePathToFs / paramRouteSegmentToFs live here.
// utilsManagement uses them in resolvePageJsonPath / resolvePagePhpPath.
require_once __DIR__ . '/routeHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/opcacheHygiene.php';

// qs_json_write lives in its own file so the three runtime consumers can take it
// without this whole utility drawer. One definition, required from both paths.
require_once __DIR__ . '/jsonIo.php';

/**
 * Special pages that exist as templates but are NOT managed as routes.
 * Used in addRoute (guard) and editStructure (validation bypass).
 */
const SPECIAL_PAGES = ['404', '500', '403', '401'];

/**
 * array_is_list() polyfill — PHP 8.1+ builtin, and 8.0.30 is the supported floor.
 * Guarded so 8.1+ keeps the (faster) engine implementation.
 *
 * Semantics match the builtin exactly, INCLUDING the empty case: array_is_list([])
 * is true. Note `range(0, -1)` is [0, -1], not [], so the empty array has to be
 * special-cased rather than falling through to the key comparison.
 *
 * Declared here so it is available tree-wide (utilsManagement is the shared
 * utility home). Do not confuse it with resolverHelpers' _isResolverArrayShape,
 * which deliberately answers FALSE for an empty array — see the note there.
 */
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool {
        if ($array === []) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }
}

/**
 * Read a request parameter that MUST be a string, coercing nothing.
 *
 * `?name[]=x` (and its JSON equivalent) delivers an ARRAY where a command expects
 * a string, and the array then reaches trim() / strtolower() / preg_match() /
 * file_exists(), each of which is typed for a string: TypeError → 500
 * (beta.10 C13 F-C13-11, 18 sites across 16 commands). Every one of those sites
 * was in early validation, so nothing was half-written — but a 500 is the wrong
 * answer to a malformed parameter, and it is reachable before authentication on
 * the public commands.
 *
 * Returns $default (null unless overridden) when the key is absent OR present
 * with a non-string value, so the caller's existing "missing required parameter"
 * branch handles both without a second code path.
 *
 * @param array  $params  the merged parameter array
 * @param string $key     parameter name
 * @param mixed  $default returned when absent or not a string
 * @return mixed the string, or $default
 */
function qs_param_string(array $params, string $key, $default = null)
{
    return (isset($params[$key]) && is_string($params[$key])) ? $params[$key] : $default;
}


/**
 * ---------------------------------------------------------------------------
 * BUILD LOCATION — the single derivation of where a project's build lives.
 * ---------------------------------------------------------------------------
 *
 * `secure/projects/<id>/qs_build/<name>/`. OUTSIDE the project's public/, so no
 * URL reaches it: the /p/<id>/ passthrough serves out of public/ and nothing
 * else, which is what makes the boundary compose. A deny file inside public/
 * would NOT have been equivalent — /p/ serving runs through PHP, not the web
 * server's own file handling, so an .htaccess there is not consulted the way it
 * appears to be. The qs_ prefix is defensive naming, kept even though the
 * directory is already unreachable.
 *
 * The build is downloaded through `downloadBuild`, which zips on demand and
 * streams; nothing static is served and no zip is stored.
 *
 * Retention is N = 1: qs_build/ holds at most one build directory. `build`
 * refuses while one exists rather than overwriting it, so the user's deliberate
 * delete is the only thing that destroys a build.
 *
 * Every caller uses these four functions. There is no second spelling of the
 * path anywhere in the tree.
 */

/** Root that holds the single build. Does not create it. */
function qs_build_root(): string
{
    return PROJECT_PATH . DIRECTORY_SEPARATOR . 'qs_build';
}

/** Absolute path of one named build directory. */
function qs_build_path(string $buildName): string
{
    return qs_build_root() . DIRECTORY_SEPARATOR . $buildName;
}

/**
 * The name of the single existing build, or null when there is none.
 *
 * Scans rather than trusting a pointer file: the directory IS the record, so a
 * build cannot be present-but-unlisted (which is exactly how a failed build's
 * partial used to hide among the good ones).
 */
function qs_build_current(): ?string
{
    $root = qs_build_root();
    if (!is_dir($root)) {
        return null;
    }
    $entries = @scandir($root);
    if ($entries === false) {
        return null;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($root . DIRECTORY_SEPARATOR . $entry)) {
            return $entry;
        }
    }
    return null;
}

/**
 * Is this build complete?
 *
 * `build_manifest.json` is written last, so its presence is the completion
 * marker. This is the belt to cleanup-on-failure's braces: if the cleanup
 * itself fails, the survivor is still identifiable as incomplete instead of
 * passing for a good build.
 */
function qs_build_is_complete(string $buildName): bool
{
    return is_file(qs_build_path($buildName) . DIRECTORY_SEPARATOR . 'build_manifest.json');
}

/**
 * Export array with proper formatting for nested routes.
 * Forces all keys to strings — PHP auto-casts numeric string keys to int.
 */
function varExportNested(array $array, int $indent = 0): string {
    if (empty($array)) {
        return '[]';
    }

    $isAssoc = array_keys($array) !== range(0, count($array) - 1);

    if (!$isAssoc) {
        // Simple indexed array - shouldn't happen for routes but handle it
        return var_export($array, true);
    }

    $spaces = str_repeat('    ', $indent);
    $innerSpaces = str_repeat('    ', $indent + 1);

    $lines = ["["];
    foreach ($array as $key => $value) {
        // Force string keys — PHP auto-casts numeric strings to int in arrays
        $exportedKey = var_export((string) $key, true);
        $exportedValue = is_array($value) ? varExportNested($value, $indent + 1) : var_export($value, true);
        $lines[] = "{$innerSpaces}{$exportedKey} => {$exportedValue},";
    }
    $lines[] = "{$spaces}]";

    return implode("\n", $lines);
}

/**
 * Generate page template content for a new route
 * 
 * @param string $route The route name
 * @return string The complete PHP page template content
 */
function generate_page_template(string $route): string {
    // Beta.8 A1 — emit a route-agnostic bootstrap. Pulling page +
    // routeParams from TrimParameters at request time means the same
    // template works for static AND param routes:
    //   - static route 'home' → routePath() === 'home', routeParams() === []
    //   - param  route 'test/:slug' → routePath() === 'test/:slug',
    //                                routeParams() === ['slug' => 'red-vase']
    // Behaviour for static routes is identical to the prior hardcoded
    // form. Existing per-route .php files (generated before this
    // change) keep their hardcoded shape — no migration needed.
    //
    // Page title key uses the route LITERAL ('page.titles.test/:slug')
    // so authors can localise per-pattern. Falls back to a sensible
    // default when no translation key is set.
    return <<<'PHP'
<?php

require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$lang = $trimParameters->lang();

require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
$renderer = new JsonToHtmlRenderer($translator, [
    'lang'        => $lang,
    'page'        => $trimParameters->routePath(),
    'routeParams' => $trimParameters->routeParams(),
]);

$content = $renderer->renderPage($trimParameters->routePath());

require_once SECURE_FOLDER_PATH . '/src/classes/PageManagement.php';

// Page title — lookup uses the route PATTERN ('page.titles.test/:slug').
// Translator returns the key itself when no translation is found; in
// that case fall back to the route's leaf segment as a reasonable default.
$titleKey = 'page.titles.' . $trimParameters->routePath();
$pageTitle = $translator->translate($titleKey);
if ($pageTitle === $titleKey || $pageTitle === '') {
    $segments = $trimParameters->route();
    $leaf = end($segments) ?: 'page';
    $pageTitle = ucfirst(str_replace(['-', ':'], [' ', ''], $leaf));
}

$page = new PageManagement($pageTitle, $content, $lang);
$page->render();

PHP;
}

/**
 * Generate default JSON page structure
 * 
 * @param string $route_name The route name
 * @return string JSON content for the page
 */
function generate_page_json(string $route_name): string {
    $json_structure = [
        [
            'tag' => 'main',
            'params' => ['class' => 'container'],
            'children' => []
        ]
    ];
    
    return json_encode($json_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}


function validateStructureDepth($node, $depth = 0, $maxDepth = 50): bool {
    if ($depth > $maxDepth) {
        return false;
    }
    
    if (!is_array($node)) {
        return true;
    }
    
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (!validateStructureDepth($child, $depth + 1, $maxDepth)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Depth-check a WHOLE structure — the list-of-nodes shape and the single-node
 * (component) shape — in one call. Wraps validateStructureDepth with exactly the
 * branching editStructure.php does inline, so the other writers get the same rule
 * without copying it nine times.
 *
 * Call it on the RESULT of an insert, never on the request: each request adds one
 * level, so a request-side check sees nothing and the page still walks past the
 * limit one call at a time (beta.10 C13 F-C13-13 — 254 × addNode(position=inside)
 * each returned 200, and the page then exceeded JSON's own 512-level read bound
 * and became unreadable by anything).
 *
 * Deliberately NOT applied to purely-removing writers (deleteNode): the check is
 * on the result, so guarding a shrink operation would make an already-too-deep
 * page impossible to repair by deleting from it.
 *
 * @param mixed $structure decoded structure (list of nodes, or one node)
 * @param int   $maxDepth  same default as editStructure
 * @return bool true when within the limit
 */
function qs_structure_depth_ok($structure, int $maxDepth = 50): bool {
    if (!is_array($structure)) {
        return true;
    }
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            if (!validateStructureDepth($node, 0, $maxDepth)) {
                return false;
            }
        }
        return true;
    }
    return validateStructureDepth($structure, 0, $maxDepth);
}

// TagRegistry is the single source of truth for tag policy (CLAUDE.md: never
// define tag lists anywhere else). Required explicitly rather than relied on
// transitively, so qs_first_unrenderable_tag() works from any caller.
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';
// componentPolicy is the same arrangement for component REFERENCES, which the
// tag walker below never inspected (beta.11 S3.10c).
require_once SECURE_FOLDER_PATH . '/src/functions/componentPolicy.php';

/**
 * Walk a WHOLE structure and return the first tag the render/compile layers would
 * refuse, or null when every tag is renderable. Handles both stored shapes — the
 * list-of-nodes (page/menu/footer) and the single-node (component) — with the same
 * branching qs_structure_depth_ok uses.
 *
 * TagRegistry::isRenderable is the shared policy: well-formed name, NOT blocked,
 * and on the allowlist. The renderer (JsonToHtmlRenderer) and the compiler
 * (JsonToPhpCompiler) both enforce it, so a non-renderable tag can never be SERVED
 * — it renders as an HTML comment and compiles to nothing. This helper is the
 * WRITE-side twin of that gate: belt-and-braces, so stored JSON stays clean and the
 * author gets an immediate error instead of a node that silently never appears.
 *
 * Use it on whatever a writer is about to persist. Note the distinction that
 * decides whether a given writer wants it at all (beta.10 C13 13.5):
 *   - a writer that takes a tag/structure FROM THE REQUEST can INTRODUCE a bad tag,
 *     and a gate there PREVENTS;
 *   - a writer that only copies or moves tags already in the store (moveNode,
 *     duplicateNode, insertSnippet) can only PROPAGATE one, and a gate there
 *     QUARANTINES — it would refuse to move or duplicate pre-existing content,
 *     which is a behaviour change, not a hardening.
 *
 * @param mixed $structure decoded structure (list of nodes, or one node)
 * @return string|null the offending tag, or null when all tags are renderable
 */
function qs_first_unrenderable_tag($structure): ?string {
    if (!is_array($structure)) {
        return null;
    }
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            $bad = qs_first_unrenderable_tag_node($node);
            if ($bad !== null) {
                return $bad;
            }
        }
        return null;
    }
    return qs_first_unrenderable_tag_node($structure);
}

/**
 * Single-node recursion behind qs_first_unrenderable_tag().
 *
 * @param mixed $node
 * @return string|null
 */
function qs_first_unrenderable_tag_node($node): ?string {
    if (!is_array($node)) {
        return null;
    }
    if (isset($node['tag']) && is_string($node['tag']) && !TagRegistry::isRenderable($node['tag'])) {
        return $node['tag'];
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $bad = qs_first_unrenderable_tag_node($child);
            if ($bad !== null) {
                return $bad;
            }
        }
    }
    return null;
}

/**
 * Walk a WHOLE structure and return the first component REFERENCE the render and
 * compile layers would refuse, or null when every reference is legal.
 *
 * The write-side twin of the jail in qs_resolve_component_path(), and the exact
 * mirror of qs_first_unrenderable_tag() above: same two stored shapes, same
 * recursion, same belt-and-braces intent. Until beta.11 S3.10c nothing inspected
 * `component` on write at all — the tag walker looks at `tag`, the param policy
 * looks at `params` — so a reference reached the readers unexamined and `../`
 * walked out of the components directory.
 *
 * The READ side is the load-bearing gate, because existing projects already hold
 * references nothing ever checked and only the resolver protects a render or a
 * build made from them. This gate exists so an author who writes a bad reference
 * is told immediately instead of getting a node that silently never appears.
 *
 * Same caller distinction as the tag walker: use it on a writer that takes a
 * structure FROM THE REQUEST (which can INTRODUCE a bad reference), not on one
 * that only moves or copies what is already stored (which would QUARANTINE
 * pre-existing content rather than harden anything).
 *
 * @param mixed $structure decoded structure (list of nodes, or one node)
 * @return string|null the offending reference, or null when all are legal
 */
function qs_first_invalid_component_reference($structure): ?string {
    if (!is_array($structure)) {
        return null;
    }
    if (isset($structure[0]) || empty($structure)) {
        foreach ($structure as $node) {
            $bad = qs_first_invalid_component_reference_node($node);
            if ($bad !== null) {
                return $bad;
            }
        }
        return null;
    }
    return qs_first_invalid_component_reference_node($structure);
}

/**
 * Single-node recursion behind qs_first_invalid_component_reference().
 *
 * @param mixed $node
 * @return string|null
 */
function qs_first_invalid_component_reference_node($node): ?string {
    if (!is_array($node)) {
        return null;
    }
    if (array_key_exists('component', $node) && !qs_is_valid_component_reference($node['component'])) {
        // A non-string reference is reported by type, not by casting it: an
        // array used to reach a path concatenation as the string "Array".
        return is_string($node['component']) ? $node['component'] : gettype($node['component']);
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $bad = qs_first_invalid_component_reference_node($child);
            if ($bad !== null) {
                return $bad;
            }
        }
    }
    return null;
}

/**
 * Validate nested object/array depth (for translations, configs, etc.)
 * Unlike validateStructureDepth which checks 'children' arrays,
 * this checks ALL nested arrays/objects recursively
 * 
 * @param mixed $data The data to validate
 * @param int $depth Current recursion depth
 * @param int $maxDepth Maximum allowed depth (default 20)
 * @return bool True if depth is valid, false if exceeds limit
 */
function validateNestedDepth($data, $depth = 0, $maxDepth = 20): bool {
    if ($depth > $maxDepth) {
        return false;
    }
    
    if (!is_array($data)) {
        return true;
    }
    
    // Check depth of ALL nested values
    foreach ($data as $value) {
        if (is_array($value)) {
            if (!validateNestedDepth($value, $depth + 1, $maxDepth)) {
                return false;
            }
        }
    }
    
    return true;
}


function countNodes($structure): int {
    if (!is_array($structure)) {
        return 0;
    }
    
    $count = 1;
    
    if (isset($structure['children']) && is_array($structure['children'])) {
        foreach ($structure['children'] as $child) {
            $count += countNodes($child);
        }
    }
    
    return $count;
}

/**
 * Recursively extract textKey values from a JSON structure with depth protection
 * 
 * @param mixed $node The node to extract keys from
 * @param array $keys Accumulator for found keys (passed by reference)
 * @param int $currentDepth Current recursion depth
 * @param int $maxDepth Maximum allowed recursion depth (default 20)
 * @return array The accumulated keys
 */
function extractTextKeys($node, &$keys = [], $currentDepth = 0, $maxDepth = 20): array {
    // Depth limit protection - prevent stack overflow
    if ($currentDepth > $maxDepth) {
        return $keys;
    }
    
    // Only process arrays
    if (!is_array($node)) {
        return $keys;
    }
    
    // Extract textKey if present (skip __RAW__ prefixed keys)
    if (isset($node['textKey']) && is_string($node['textKey'])) {
        if (strpos($node['textKey'], '__RAW__') !== 0) {
            $keys[] = $node['textKey'];
        }
    }
    
    // Recursively process children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            extractTextKeys($child, $keys, $currentDepth + 1, $maxDepth);
        }
    }
    
    // Process component data - check all string values that look like translation keys
    if (isset($node['component']) && isset($node['data']) && is_array($node['data'])) {
        foreach ($node['data'] as $key => $value) {
            // String value that looks like a translation key (contains dot, no spaces, not a path/url)
            if (is_string($value) && 
                strpos($value, '.') !== false && 
                strpos($value, ' ') === false &&
                strpos($value, '/') !== 0 &&
                strpos($value, 'http') !== 0 &&
                strpos($value, '__RAW__') !== 0 &&
                strpos($value, '{{') !== 0) {
                $keys[] = $value;
            }
            // Array of labels (for carousel, etc.)
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (isset($item['textKey']) && is_string($item['textKey'])) {
                        if (strpos($item['textKey'], '__RAW__') !== 0) {
                            $keys[] = $item['textKey'];
                        }
                    }
                    // Also check if item itself is a translation key string
                    if (is_string($item) && 
                        strpos($item, '.') !== false && 
                        strpos($item, ' ') === false &&
                        strpos($item, '/') !== 0 &&
                        strpos($item, 'http') !== 0 &&
                        strpos($item, '__RAW__') !== 0) {
                        $keys[] = $item;
                    }
                }
            }
        }
    }
    
    return $keys;
}

/**
 * Load and parse a JSON structure file with error handling
 * 
 * @param string $filePath Path to the JSON file
 * @return array|null Parsed array on success, null on failure
 */
function loadJsonStructure(string $filePath): ?array {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $json = file_get_contents($filePath);
    if ($json === false) {
        return null;
    }
    
    $structure = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    return $structure;
}

/**
 * Guard a page ROUTE path against traversal before it becomes a filesystem path
 * (beta.10 C3 F1 cluster D). Legit page routes are lowercase alnum/hyphen segments
 * (see addRoute) and never contain '..'; reject any '..' (incl. its Windows
 * backslash form). Shared by the three page-path resolvers below so every caller
 * is covered in one place — a traversal route now resolves to null (→404) instead
 * of silently escaping the pages/ dir.
 */
function routePathIsSafe(string $routePath): bool {
    return strpos(str_replace('\\', '/', $routePath), '..') === false;
}

/**
 * Resolve page JSON file path using folder structure convention
 * Convention: ALL pages use folder structure - route/route.json
 * Falls back to flat route.json for backward compatibility
 *
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return string|null Full path to JSON file, or null if not found
 */
function resolvePageJsonPath(string $routePath, ?string $projectPath = null): ?string {
    if (!routePathIsSafe($routePath)) return null;
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = $projectPath . '/templates/model/json/pages';

    $routePath = trim($routePath, '/');
    // Beta.8 A1 — sanitise ':slug' → '__slug' for filesystem lookup.
    // See routeHelpers.php for the canonical helper used everywhere.
    $fsPath = paramRoutePathToFs($routePath);
    $segments = explode('/', $fsPath);
    $leafName = end($segments);

    // Try folder structure first: path/name/name.json
    $folderPath = $basePath . '/' . $fsPath . '/' . $leafName . '.json';
    if (file_exists($folderPath)) {
        return $folderPath;
    }

    // Fallback to flat structure: path/name.json
    $flatPath = $basePath . '/' . $fsPath . '.json';
    if (file_exists($flatPath)) {
        return $flatPath;
    }

    return null;
}

/**
 * Resolve page PHP file path using folder structure convention
 * Convention: ALL pages use folder structure - route/route.php
 * Falls back to flat route.php for backward compatibility
 * 
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return string|null Full path to PHP file, or null if not found
 */
function resolvePagePhpPath(string $routePath, ?string $projectPath = null): ?string {
    if (!routePathIsSafe($routePath)) return null;
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = $projectPath . '/templates/pages';

    $routePath = trim($routePath, '/');
    // Beta.8 A1 — same `:slug` → `__slug` sanitisation as resolvePageJsonPath.
    $fsPath = paramRoutePathToFs($routePath);
    $segments = explode('/', $fsPath);
    $leafName = end($segments);

    // Try folder structure first: path/name/name.php
    $folderPath = $basePath . '/' . $fsPath . '/' . $leafName . '.php';
    if (file_exists($folderPath)) {
        return $folderPath;
    }

    // Fallback to flat structure: path/name.php
    $flatPath = $basePath . '/' . $fsPath . '.php';
    if (file_exists($flatPath)) {
        return $flatPath;
    }

    return null;
}

/**
 * Get the target path for a NEW page (always uses folder structure)
 * 
 * @param string $routePath Route path (e.g., 'home', 'guides/getting-started')
 * @param string $extension File extension ('json' or 'php')
 * @param string|null $projectPath Optional project path
 * @return string|null Full path where the file should be created, or null if the
 *                     route is unsafe (contains '..').
 */
function getNewPagePath(string $routePath, string $extension, ?string $projectPath = null): ?string {
    if (!routePathIsSafe($routePath)) return null;
    $projectPath = $projectPath ?? PROJECT_PATH;
    $basePath = ($extension === 'json') 
        ? $projectPath . '/templates/model/json/pages'
        : $projectPath . '/templates/pages';
    
    $routePath = trim($routePath, '/');
    $segments = explode('/', $routePath);
    $leafName = end($segments);
    
    // Always use folder structure: path/name/name.ext
    return $basePath . '/' . $routePath . '/' . $leafName . '.' . $extension;
}

/**
 * Recursively scan all page JSON files in the pages directory
 * Handles both folder structure (route/route.json) and flat structure (route.json)
 * 
 * @param string|null $projectPath Optional project path, defaults to PROJECT_PATH constant
 * @return array Array of ['path' => absolute path, 'route' => route path]
 */
function scanAllPageJsonFiles(?string $projectPath = null): array {
    $projectPath = $projectPath ?? PROJECT_PATH;
    $pagesDir = $projectPath . '/templates/model/json/pages';
    $results = [];
    
    if (!is_dir($pagesDir)) {
        return $results;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pagesDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $fullPath = $file->getPathname();
            $relativePath = str_replace($pagesDir . DIRECTORY_SEPARATOR, '', $fullPath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            
            // Extract route from path
            // For folder structure: guides/getting-started/getting-started.json → guides/getting-started
            // For flat structure: home.json → home
            $route = preg_replace('/\.json$/', '', $relativePath);

            // If folder structure, the last segment is duplicated: guides/getting-started/getting-started
            // Remove the duplicate leaf
            $segments = explode('/', $route);
            if (count($segments) >= 2) {
                $lastTwo = array_slice($segments, -2);
                if ($lastTwo[0] === $lastTwo[1]) {
                    array_pop($segments);
                    $route = implode('/', $segments);
                }
            }

            // Beta.8 A1 — reverse the `:slug` → `__slug` sanitisation so the
            // route identity returned matches what's in routes.php (and what
            // the admin UI / URL pattern expect). E.g., disk 'test/__slug'
            // becomes route 'test/:slug'.
            $route = fsRoutePathToParam($route);

            $results[] = [
                'path' => $fullPath,
                'route' => $route,
                'filename' => $file->getFilename()
            ];
        }
    }
    
    return $results;
}

/**
 * Flatten nested routes array to flat list of route paths
 * e.g., ['home' => [], 'guides' => ['getting-started' => []]] 
 *       → ['home', 'guides', 'guides/getting-started']
 * 
 * @param array $routes Nested routes array
 * @param string $prefix Current path prefix
 * @return array Flat list of route paths
 */
function flattenRoutes(array $routes, string $prefix = ''): array {
    $result = [];
    
    foreach ($routes as $name => $children) {
        $path = $prefix === '' ? $name : $prefix . '/' . $name;
        $result[] = $path;
        
        if (is_array($children) && !empty($children)) {
            $result = array_merge($result, flattenRoutes($children, $path));
        }
    }
    
    return $result;
}

/**
 * Check if a route path exists in nested routes structure
 * 
 * @param string $routePath Route path to check (e.g., 'guides/getting-started')
 * @param array $routes Nested routes array
 * @return bool True if route exists
 */
function routeExists(string $routePath, array $routes): bool {
    $segments = array_filter(explode('/', trim($routePath, '/')));
    $current = $routes;
    
    foreach ($segments as $segment) {
        if (!isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    
    return true;
}
/**
 * Validate that params array does not contain reserved data-qs-* attributes
 * 
 * These attributes are auto-generated by QuickSite and must not be set manually:
 * - data-qs-node: Node identifier for Visual Editor
 * - data-qs-struct: Structure type indicator
 * - data-qs-*: Any other QuickSite internal attribute
 * 
 * @param array $params Associative array of HTML attributes
 * @return string|null The first reserved attribute found, or null if all valid
 */
function findReservedQsParam(array $params): ?string {
    foreach (array_keys($params) as $key) {
        if (is_string($key) && str_starts_with(strtolower($key), 'data-qs-')) {
            return $key;
        }
    }
    return null;
}

/**
 * Recursively check a structure tree for reserved data-qs-* attributes in params
 * 
 * @param mixed $node Node to validate (can be array structure or scalar)
 * @param int $depth Current recursion depth
 * @param int $maxDepth Maximum recursion depth to prevent infinite loops
 * @return array|null ['key' => string, 'path' => string] if found, null if valid
 */
function findReservedQsParamInStructure($node, int $depth = 0, int $maxDepth = 50, string $path = 'root'): ?array {
    if ($depth > $maxDepth || !is_array($node)) {
        return null;
    }
    
    // Check params in current node
    if (isset($node['params']) && is_array($node['params'])) {
        $reserved = findReservedQsParam($node['params']);
        if ($reserved !== null) {
            return ['key' => $reserved, 'path' => $path];
        }
    }
    
    // Recursively check children
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $index => $child) {
            $childPath = $path . '.children[' . $index . ']';
            $result = findReservedQsParamInStructure($child, $depth + 1, $maxDepth, $childPath);
            if ($result !== null) {
                return $result;
            }
        }
    }
    
    return null;
}

/**
 * Human-readable size, matching the formatting used across the panel.
 *
 * Lives here rather than beside any one caller: it started in spaceUsage.php,
 * gained a second caller in uploadLimits.php, and a second copy is exactly how
 * the three formatBytes() duplicates elsewhere in the tree happened. Those were
 * collapsed onto this function in S2.9 — deleteProject, listProjects and
 * public/admin/api/index.php now all call it. ⚠ THIS IS THE ONLY ONE. A new
 * local copy is how the last three started.
 */
function qs_format_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    if ($i === 0) {
        return $bytes . ' B';
    }
    // Trailing zeros are dropped: a LIMIT reads badly as "2.00 MB" and a user
    // reasonably wonders what the hundredths are hiding. Significant digits are
    // kept — 1.25 MB stays 1.25 MB, only 2.00 and 1.50 lose what they were not
    // saying anything with.
    $text = number_format($n, $n >= 100 ? 0 : ($n >= 10 ? 1 : 2), '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return $text . ' ' . $units[$i];
}

/**
 * Read a project's config.php under an exclusive lock, let a callback patch it,
 * and write it back.
 *
 * Four commands need the same eight steps — lock, invalidate, re-read FRESH (the
 * `CONFIG` constant is the request's stale snapshot), patch, `var_export`, write,
 * unlock, invalidate again — and each hand-rolled them. `setThemeMode`,
 * `setMultilingual` and `addLang` still do; they hold the lock across other work
 * (translation-file merges) and cannot be folded in without restructuring what
 * runs inside the critical section. New writers use this.
 *
 * `var_export` is what makes the write safe: a config value ends up as PHP
 * source in an array literal, so anything a caller stores must be re-parsable
 * rather than interpolated. Callers still validate their VALUES — this
 * guarantees the file's syntax, not the sense of what is in it.
 *
 * The callback receives the fresh config by reference and returns true to
 * commit, false to abandon (lock released, file untouched).
 *
 * @param string   $configPath Absolute path to the project's config.php
 * @param callable $patch      fn(array &$config): bool
 * @return array{ok:bool, reason:string, config:array} `reason` is one of
 *         'lock_failed', 'read_failed', 'write_failed', 'abandoned', or ''.
 */
function qs_config_mutate(string $configPath, callable $patch): array
{
    $fail = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'config' => []];

    $lockFile   = $configPath . '.lock';
    $lockHandle = @fopen($lockFile, 'w');
    if ($lockHandle === false) {
        return $fail('lock_failed');
    }
    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return $fail('lock_failed');
    }

    $release = static function ($handle, string $file): void {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($file);
    };

    // Read FRESH. `include` rather than `require` so a missing file is a
    // reported failure, not a fatal; opcache is invalidated first because the
    // last writer may have been this same request.
    clearstatcache(true, $configPath);
    qs_opcache_invalidate($configPath);
    $config = @include $configPath;
    if (!is_array($config)) {
        $release($lockHandle, $lockFile);
        return $fail('read_failed');
    }

    if ($patch($config) !== true) {
        $release($lockHandle, $lockFile);
        return ['ok' => false, 'reason' => 'abandoned', 'config' => $config];
    }

    $written = @file_put_contents(
        $configPath,
        "<?php\n\nreturn " . var_export($config, true) . ";\n",
        LOCK_EX
    );
    $release($lockHandle, $lockFile);

    if ($written === false) {
        return $fail('write_failed');
    }
    qs_opcache_invalidate($configPath);
    clearstatcache(true, $configPath);

    return ['ok' => true, 'reason' => '', 'config' => $config];
}

/**
 * Keep `CONFIG['FAVICON_PATH']` honest when the asset it points at moves or goes.
 *
 * `editFavicon` stores a POINTER rather than copying the image, which is what
 * stops backup files piling up in `assets/images/` — but a pointer can dangle,
 * and the old copy-based command could not. Deleting or renaming the chosen
 * image has to travel to the pointer, or the site emits an icon link to a file
 * that is not there.
 *
 * Deliberately silent about the common case: if the pointer names some OTHER
 * asset (or is unset), nothing is written and nothing is reported. Callers
 * delete assets in batches, and a config rewrite per file would be wasteful and
 * noisy.
 *
 * @param string      $projectPath Project root (the one holding config.php)
 * @param string      $oldName     Asset filename as it was
 * @param string|null $newName     Its new filename, or null when it was deleted
 * @return bool True when the pointer was actually changed
 */
function qs_favicon_repoint(string $projectPath, string $oldName, ?string $newName): bool
{
    $configPath = $projectPath . '/config.php';
    if (!is_file($configPath)) {
        return false;
    }

    // Cheap pre-check against the request's own snapshot, so the overwhelmingly
    // common "this asset is not the favicon" case costs no lock and no re-read.
    // qs_config_mutate re-reads FRESH under the lock and re-tests before
    // writing, so a stale CONSTANT here can only skip work, never corrupt.
    $current = (defined('CONFIG') && isset(CONFIG['FAVICON_PATH'])) ? CONFIG['FAVICON_PATH'] : null;
    if ($current !== null && $current !== '/assets/images/' . $oldName) {
        return false;
    }

    $changed = false;
    qs_config_mutate($configPath, function (array &$config) use ($oldName, $newName, &$changed): bool {
        if (($config['FAVICON_PATH'] ?? null) !== '/assets/images/' . $oldName) {
            return false;   // not ours — abandon, leave the file untouched
        }
        if ($newName === null) {
            unset($config['FAVICON_PATH']);
        } else {
            $config['FAVICON_PATH'] = '/assets/images/' . $newName;
        }
        $changed = true;
        return true;
    });

    return $changed;
}
