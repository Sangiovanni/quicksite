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
 * And what a binding may name (qs_binding_attribute_refusal()): the first layer
 * applies it to `data-bind-attr`, and ApiEndpointManager to a response binding's
 * `attribute`, as qs.js applies it in the browser.
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

if (!function_exists('qs_binding_attribute_refusal')) {
    /**
     * WHAT A BINDING MAY WRITE, on the write side. A binding names an attribute
     * that qs.js sets in the browser, after the page is rendered, with a value
     * from data: a list item's `data-bind-attr`, an endpoint's scalar response
     * binding. It may name only what an author could write in the page's
     * structure, judged as the render gate judges that structure:
     *   - a name outside `html_attribute_name` is refused;
     *   - an event-handler name, in any letter case: the render gate compiles a
     *     handler only from {{call:...}} written in the page, and a value from
     *     data is never compiled;
     *   - `srcdoc` on any element, and `src` or `sandbox` on an iframe: the
     *     render paths decide a frame's sandbox from the src and srcdoc they
     *     render (IframeSandbox), and nothing decides it again in the browser.
     * A URL attribute stays nameable: its VALUE comes from data, so only the
     * runtime can hold it to UrlPolicy's allowlist.
     *
     * THE RUNTIME IS THE GATE (qs.js _qsBoundAttributeRefusal(), the same
     * verdict): a structure stored before this rule, a name a placeholder fills
     * at render and an element only a selector finds all reach the browser
     * unjudged here. What this adds is an immediate error for the author. Change
     * the two together.
     *
     * @param string      $name the attribute a binding would write
     * @param string|null $tag  the element's tag, when the caller knows it; null
     *                          judges what the name alone can tell
     * @return string|null why it is refused, in words an author reads; null when it may be written
     */
    function qs_binding_attribute_refusal(string $name, ?string $tag = null): ?string {
        if (!TagRegistry::isRenderableAttributeName($name)) {
            return "'{$name}' is not a valid HTML attribute name (letters, digits, underscore, colon and hyphen only).";
        }
        // The name passed the charset above, so it is ASCII and strtolower folds it as a browser does.
        $folded = strtolower($name);
        if (preg_match('/^on[a-z]+$/D', $folded)) {
            return "'{$name}' is an event handler, which takes only {{call:...}} written in the page, never a value from data.";
        }
        if ($folded === 'srcdoc') {
            return "'{$name}' would put a whole page from data into a frame.";
        }
        if (($folded === 'src' || $folded === 'sandbox') && $tag !== null && strtolower($tag) === 'iframe') {
            return "an iframe's '{$name}' is fixed when the page is served, as its sandbox follows the address the page holds then.";
        }
        return null;
    }
}

/**
 * Returns a human-readable error for the first unsafe param, or null if clean.
 * $tag, when the caller knows the node's, lets a binding's target be judged
 * against it (an iframe's src); without it the name alone is judged.
 */
if (!function_exists('firstUnsafeParam')) {
    function firstUnsafeParam(array $params, ?string $tag = null): ?string {
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

            // ── A BINDING'S TARGET, BEFORE THE VALUE GUARD TOO ────────────
            //
            // `data-bind-attr` names the attribute qs.js writes with a value
            // from data, so its VALUE is a name, judged by the binding rule
            // above. A number renders as its digits and is judged as that
            // text; `true` renders the bare name, which names nothing. The
            // renderer drops a `__LIT__` prefix from any value, so the name
            // judged is the one the page will hold. A value holding a
            // placeholder is filled when the page is served, so only the
            // runtime can judge it: it passes here, and qs.js refuses it there.
            if (strtolower($name) === 'data-bind-attr' && (is_string($value) || is_int($value) || is_float($value))) {
                $target = (string) $value;
                if (strpos($target, '__LIT__') === 0) {
                    $target = substr($target, 7);
                }
                if ($target !== '' && strpos($target, '{{') === false) {
                    $refusal = qs_binding_attribute_refusal($target, $tag);
                    if ($refusal !== null) {
                        return "Attribute '{$name}' names '{$target}', which a binding cannot write: {$refusal}";
                    }
                }
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
        // One node, whichever of its keys it holds: a root with only params or children is one too.
        if (isset($structure['tag']) || isset($structure['component']) || isset($structure['textKey'])
            || isset($structure['params']) || isset($structure['children'])) {
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
            // The tag the node renders with, so a binding is judged against it (an
            // iframe's src). A component instance's params land on the root of
            // the component's file, a tag this walk does not read: the runtime
            // judges those.
            $tag = isset($node['tag']) && is_string($node['tag']) ? $node['tag'] : null;
            foreach ($node['params'] as $name => $value) {
                $name = (string) $name;
                $unsafe = firstUnsafeParam([$name => $value], $tag);
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
