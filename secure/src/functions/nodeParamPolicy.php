<?php

// Prevent direct access
if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/UrlPolicy.php';
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';

/**
 * Writer-side node-param safety — the reject-on-store companion to the render
 * gate. The renderer already blocks raw `on*` handlers and neutralises dangerous
 * URL schemes AT RENDER; the write side rejects them too, so stored JSON never
 * holds the payload and the author gets an immediate error instead of a
 * silently-dropped attribute.
 *
 * Two layers, one policy:
 *   - firstUnsafeParam()                one node's params;
 *   - qs_first_unsafe_structure_param() a WHOLE structure — every node a render
 *     can reach, plus the rule only a node's full attribute list can answer:
 *     no attribute named twice in any mix of letter case.
 *
 * ⚠ EVERY COMMAND THAT WRITES A STRUCTURE calls the second on exactly what it
 * is about to write, and refuses with qs_unsafe_structure_param_response().
 * There is no shared structure writer to hang the check on, so a new writer
 * that skips the call is a writer the gate does not reach. The only writes
 * without the call are the engine's constant seeds — a new project's first
 * pages, menu and footer, a new route's empty page, a policy page's empty
 * parent pages, the consent banner with no policy link — because nothing from
 * a request or a stored file reaches them.
 */

/**
 * Returns a human-readable error for the first unsafe param, or null if clean.
 */
if (!function_exists('firstUnsafeParam')) {
    function firstUnsafeParam(array $params): ?string {
        foreach ($params as $name => $value) {
            // ── THE NAME, BEFORE THE VALUE GUARD ──────────────────────────
            //
            // DEFENCE IN DEPTH, not the fix. The load-bearing gate is in the
            // two renderers (TagRegistry::isRenderableAttributeName): existing
            // projects already hold un-gated structures, and no write-side
            // check can retroactively clean data that is already stored. What
            // this adds is an immediate error for the author instead of an
            // attribute that silently disappears from the page.
            //
            // ⚠ It runs BEFORE the value guard on purpose. That guard skips a
            // non-string or empty value, and the compiler's boolean branch
            // emits the bare name — so a hostile name paired with `true` would
            // have walked straight past a check placed after it.
            //
            // Cast because PHP turns a numeric JSON object key into an int.
            $name = (string) $name;
            if (!TagRegistry::isRenderableAttributeName($name)) {
                return "Attribute name '{$name}' is not a valid HTML attribute name "
                     . '(letters, digits, underscore, colon and hyphen only).';
            }

            if (!is_string($value) || $value === '') {
                continue;
            }
            // Raw event handler — must use {{call:...}} (mirrors renderAttribute).
            if (preg_match('/^on[a-z]+$/i', $name) && strpos($value, '{{call:') === false) {
                return "Attribute '{$name}' must use {{call:...}} syntax, not raw JavaScript.";
            }
            // Dangerous URL scheme on a URL-sink attribute (mirrors UrlPolicy).
            // sanitize() returns '#' for a disallowed scheme / control chars;
            // guard the legitimate literal '#' anchor so it isn't rejected.
            if (UrlPolicy::isUrlAttribute($name)
                && UrlPolicy::sanitize($value) === '#'
                && ltrim($value, " \t\n\r\0\x0B\f") !== '#'
            ) {
                return "Attribute '{$name}' uses a disallowed URL scheme (only http, https, mailto, tel are allowed).";
            }
        }
        return null;
    }
}

if (!function_exists('qs_first_unsafe_structure_param')) {
    /**
     * Walk a WHOLE structure and return the first attribute a writer must
     * refuse, or null when every node passes.
     *
     * WHAT IT REACHES is derived from the two render paths and NodeNavigator,
     * because a walker that misses a nesting leaves a door open:
     *   - both stored shapes — a list of nodes (page, menu, footer, consent
     *     layer) and a single root node (a component file), told apart the way
     *     the renderer tells them apart;
     *   - `children`, recursively;
     *   - a component instance's own `params`: the renderer merges them into the
     *     component's root element, so they are rendered attributes;
     *   - `slots`: no render path emits them today, but NodeNavigator addresses
     *     the nodes inside them (`0.slots.header.1`), and an addressable node is
     *     a writable one.
     * A component instance's `data` is NOT a nesting. It binds scalar values
     * into the component's own strings and a non-scalar is never rendered, so it
     * holds no node. A bad VALUE reaching an attribute that way is the render
     * gate's catch: the write side would have to compose the page with the
     * component's file to see it.
     *
     * PER NODE, IN ATTRIBUTE ORDER, THE FIRST OF:
     *   1. whatever firstUnsafeParam() refuses — reused one attribute at a time,
     *      which is also what lets the failure name its attribute;
     *   2. a name already used on the node in another letter case. Attribute
     *      names are case-insensitive, so `src` and `SRC` are ONE attribute to a
     *      browser, which keeps the first and ignores the rest — and the sandbox
     *      of an iframe carrying both would describe only one of them. The
     *      collision is DETECTED on folded names and nothing is stored folded:
     *      SVG's `viewBox` means something only in that case, and every API
     *      client reads the structure back as it was written.
     *
     * Params are checked on every node whatever its kind, a text node's
     * included: no render path emits those, but no legitimate writer puts
     * params there either, and a gate refuses what it cannot vouch for.
     *
     * @param mixed $structure decoded structure (list of nodes, or one node)
     * @return array{node:string, attribute:string, message:string}|null
     *         `node` is the address NodeNavigator uses — "0.2.1", or "root" for a
     *         component file's root element, whose children are "0", "1", …
     */
    function qs_first_unsafe_structure_param($structure): ?array {
        if (!is_array($structure)) {
            return null;
        }
        if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])) {
            return qs_first_unsafe_node_param($structure, 'root');
        }
        foreach ($structure as $index => $node) {
            $failure = qs_first_unsafe_node_param($node, (string) $index);
            if ($failure !== null) {
                return $failure;
            }
        }
        return null;
    }
}

if (!function_exists('qs_first_unsafe_node_param')) {
    /**
     * Single-node recursion behind qs_first_unsafe_structure_param().
     *
     * @param mixed  $node
     * @param string $path this node's address
     * @return array|null
     */
    function qs_first_unsafe_node_param($node, string $path): ?array {
        if (!is_array($node)) {
            return null;
        }

        if (isset($node['params']) && is_array($node['params'])) {
            $seen = [];
            foreach ($node['params'] as $name => $value) {
                $name = (string) $name;
                $unsafe = firstUnsafeParam([$name => $value]);
                if ($unsafe !== null) {
                    return ['node' => $path, 'attribute' => $name, 'message' => $unsafe];
                }
                // The name passed the gate above, so it is ASCII and strtolower
                // folds it exactly as the render paths fold `src` and `sandbox`.
                $folded = strtolower($name);
                if (isset($seen[$folded])) {
                    return [
                        'node' => $path,
                        'attribute' => $name,
                        'message' => "Attribute '{$name}' repeats '{$seen[$folded]}'. Attribute names are "
                                   . 'case-insensitive, so a browser keeps the first of the two and '
                                   . 'ignores the other. Remove one of them.',
                    ];
                }
                $seen[$folded] = $name;
            }
        }

        $childBase = $path === 'root' ? '' : $path . '.';
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $index => $child) {
                $failure = qs_first_unsafe_node_param($child, $childBase . $index);
                if ($failure !== null) {
                    return $failure;
                }
            }
        }

        if (isset($node['slots']) && is_array($node['slots'])) {
            foreach ($node['slots'] as $slotName => $content) {
                if (!is_array($content)) {
                    continue;
                }
                $slotPath = $path . '.slots.' . $slotName;
                // NodeNavigator's reading: a list is a run of nodes, anything
                // else is one node.
                if ($content === [] || array_keys($content) === range(0, count($content) - 1)) {
                    foreach ($content as $index => $slotNode) {
                        $failure = qs_first_unsafe_node_param($slotNode, $slotPath . '.' . $index);
                        if ($failure !== null) {
                            return $failure;
                        }
                    }
                } else {
                    $failure = qs_first_unsafe_node_param($content, $slotPath);
                    if ($failure !== null) {
                        return $failure;
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('qs_first_unsafe_param_in_tree')) {
    /**
     * Verify every structure a project-shaped directory holds, for the commands
     * that copy a whole tree in (restoreBackup, cloneProject). They must check
     * EVERYTHING they would bring in before they write ANYTHING: a half-restored
     * project is worse than a refused one.
     *
     * Reads `templates/model/json/**` (each file is a structure) and `snippets/**`
     * (each file wraps one under `structure`) — the two places a project keeps
     * structures, and the same two an import's gates read. Files are visited in
     * sorted order, so the same tree always reports the same first failure. A
     * file that does not decode is skipped: no render path can read it either.
     *
     * @param string $root a project directory, or a backup of one
     * @return array|null the first failure, with `file` relative to $root
     */
    function qs_first_unsafe_param_in_tree(string $root): ?array {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        foreach (['templates/model/json' => false, 'snippets' => true] as $subdir => $wrapped) {
            $dir = $root . '/' . $subdir;
            if (!is_dir($dir)) {
                continue;
            }
            $files = [];
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walk as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
            sort($files);
            foreach ($files as $path) {
                $data = json_decode((string) @file_get_contents($path), true);
                if (!is_array($data)) {
                    continue;
                }
                $failure = qs_first_unsafe_structure_param($wrapped ? ($data['structure'] ?? null) : $data);
                if ($failure !== null) {
                    $failure['file'] = substr($path, strlen($root) + 1);
                    return $failure;
                }
            }
        }
        return null;
    }
}

if (!function_exists('qs_unsafe_structure_param_response')) {
    /**
     * The refusal every structure writer returns, so every writer fails the same
     * way: the code and reason addNode and editNode already answer a
     * firstUnsafeParam failure with, plus WHERE — the node, the attribute and,
     * from a command that writes several files, the file.
     *
     * An import also answers here for every other way a structure file in an
     * archive can fail, so an archive refusal always has this one shape: a file
     * that cannot be read, does not parse, or is refused by the archive content
     * check, and a blocked tag or an invalid component reference. Those name no
     * node or attribute, so both are optional; `reason` says which check failed
     * (default `unsafe_value`, the attribute gate's), and `value` carries the
     * offending tag or reference.
     *
     * @param array $failure what the verifier returned; `file`, `reason` and
     *                       `value` are optional, and so are `node` and
     *                       `attribute` when no single attribute is at fault
     */
    function qs_unsafe_structure_param_response(array $failure): ApiResponse {
        $where = $failure['file'] ?? null;
        if (isset($failure['node'])) {
            $where = $where === null ? "Node {$failure['node']}" : "{$where}, node {$failure['node']}";
        }
        $error = [
            'field'  => 'structure',
            'reason' => $failure['reason'] ?? 'unsafe_value',
        ];
        foreach (['node', 'attribute', 'value', 'file'] as $key) {
            if (isset($failure[$key])) {
                $error[$key] = $failure[$key];
            }
        }
        return ApiResponse::create(400, 'validation.unsafe_param')
            ->withMessage($where === null ? $failure['message'] : "{$where}: {$failure['message']}")
            ->withErrors([$error]);
    }
}
