<?php
/**
 * Component references — the single application point.
 *
 * A page/menu/footer node may say `{"component": "lang-switch"}`. That name is
 * stored PROJECT DATA: an author writes it, nothing outside this file decides
 * what it is allowed to be, and several readers turn it into a filesystem path.
 *
 * A reference has two halves and this file owns both: WHICH FILE the name
 * points at (`qs_resolve_component_path`, and the charset rule under it) and
 * HOW THE `data` MAP BINDS to the slots that file leaves open
 * (`qs_resolve_component_placeholders`). Every reader needs both, and each
 * half had been copied per reader until it was collected here.
 *
 * WHY A SHARED FUNCTION FILE. Ten call sites resolve a component reference —
 * the `/p/<id>/` renderer, the JSON→PHP compiler, and eight commands. Before
 * this file each one concatenated the name into a path on its own, so `../`
 * walked out of `templates/model/json/components/` and reached any `.json` the
 * process could read, INCLUDING ANOTHER PROJECT'S. Where the
 * out-of-jail file happened to be component-shaped, its full content rendered
 * into the preview HTML and compiled into the built site. Three commands did
 * carry a jail, but each carried its OWN copy of a near-identical regex and two
 * of the three disagreed about whether an underscore was legal.
 *
 * One rule, one resolver, every reader.
 *
 * THE RULE (RegexPatterns::component_name): a reference starts with a letter,
 * then letters, digits, hyphens and underscores, up to 64 characters. It is not
 * a path — no separators, no dots, no traversal, nothing that names a directory.
 * This is not a new restriction: it is the rule getComponent and
 * addComponentToNode already enforced on write, promoted to the read side so
 * both agree. Every component file on disk, every reference stored in every
 * project, and every reference the shipped workflows write already satisfies it,
 * and no project nests components in a subdirectory.
 *
 * A REFERENCE IS REFUSED, NOT REPAIRED. There is no rewriting of `../menu` that
 * means anything as a component name, so a malformed reference is dropped and
 * the reader reports "not found" — the same treatment the tag gate beside it
 * already gives a blocked tag ("an identifier from project data
 * never becomes code").
 *
 * WHY `src/functions/` AND NOT `utilsManagement.php`. Same reason as
 * projectLanguage.php and aliasRouting.php: the renderer is on the request path
 * of every preview render and does not require utilsManagement.php, and pulling
 * that file in for one predicate would put its whole dependency tail there. This
 * file has two dependencies — the pattern registry and the tag registry (which
 * attributes are translated) — and nothing else.
 *
 * It does NOT travel into a build: a built site serves COMPILED pages, so
 * neither the renderer nor the compiler ships, and no compiled page resolves a
 * component reference at request time.
 */

require_once __DIR__ . '/../classes/RegexPatterns.php';
require_once __DIR__ . '/../classes/TagRegistry.php';

if (!function_exists('qs_is_valid_component_reference')) {
    /**
     * Is this a legal component reference?
     *
     * Deliberately accepts `mixed`: a stored node can carry anything JSON can
     * express, and `{"component": ["x"]}` used to reach a path concatenation
     * as the string "Array". A non-string is not a reference.
     *
     * @param mixed $reference the raw `component` value from a node or request
     * @return bool
     */
    function qs_is_valid_component_reference($reference): bool {
        if (!is_string($reference) || $reference === '') {
            return false;
        }
        return RegexPatterns::match('component_name', $reference);
    }
}

if (!function_exists('qs_resolve_component_path')) {
    /**
     * Resolve a component reference to the file that holds it.
     *
     * Returns the path only when the reference is legal AND the file exists
     * AND the resolved file really sits inside the given components directory.
     * Otherwise null — the caller reports "not found" and carries on.
     *
     * The realpath containment check is the second layer. The charset rule
     * already makes traversal unexpressible; containment is what keeps that
     * true if the rule is ever loosened, and it is what catches a reparse
     * point placed inside the components directory itself.
     *
     * @param mixed  $reference     the raw `component` value
     * @param string $componentsDir directory holding the project's components
     * @return string|null absolute path to the component file, or null
     */
    function qs_resolve_component_path($reference, string $componentsDir): ?string {
        if (!qs_is_valid_component_reference($reference)) {
            return null;
        }

        $candidate = rtrim($componentsDir, "/\\") . '/' . $reference . '.json';
        if (!is_file($candidate)) {
            return null;
        }

        $realFile = realpath($candidate);
        $realDir  = realpath($componentsDir);
        if ($realFile === false || $realDir === false) {
            return null;
        }

        $realFile = str_replace('\\', '/', $realFile);
        $realDir  = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
        if (strpos($realFile, $realDir) !== 0) {
            return null;
        }

        return $realFile;
    }
}

if (!function_exists('qs_component_reference_error')) {
    /**
     * The validation-error shape a command reports for a bad reference.
     *
     * Shared so the eight commands that validate a reference all answer with
     * the same field, reason and description instead of eight phrasings.
     *
     * @param string $field the request field the reference arrived in
     * @param mixed  $value what arrived
     * @return array
     */
    function qs_component_reference_error(string $field, $value): array {
        return [
            'field'    => $field,
            'value'    => is_string($value) ? $value : gettype($value),
            'reason'   => 'invalid_component_reference',
            'expected' => RegexPatterns::getDescription('component_name'),
        ];
    }
}

if (!function_exists('qs_resolve_component_placeholders')) {
    /**
     * Bind one `{{slot}}` string against a component reference's `data` map.
     *
     * The second half of what a component reference means. `{"component":
     * "menu-link", "data": {"imgClass": "menu-icon"}}` names a file (resolved
     * above) AND supplies values for the slots that file leaves open. This is
     * the substitution every reader of a component performs, in one spelling.
     *
     * WHY IT IS HERE AND NOT COPIED AGAIN. Four near-identical
     * `preg_replace_callback` calls existed before this function:
     * JsonToHtmlRenderer::processComponentTemplate, the compiler's method of
     * the same name, and the structure walkers in getSnippet and getComponent.
     * They did not agree — the compiler alone refused a `{{$var}}` slot, and
     * the two commands alone refused a hyphenated one — so the same component
     * could bind in a preview and not in a build. The CSS extractor needed the
     * same substitution and would have made it five, which is what this file
     * exists to prevent.
     *
     * THE RULE: `{{name}}` or `{{$name}}`, where name is letters, digits,
     * underscores and hyphens. Hyphens are deliberate — a slot may be called
     * `alt-logo`. Nothing else is a slot: `{{call:…}}` (event syntax),
     * `{{param:…}}` / `{{resolved:…}}` (request-time values) and
     * `{{__current_page;lang=en}}` (system placeholders) all carry characters
     * outside the rule and are left untouched for the reader that owns them.
     *
     * A MISSING SLOT KEEPS ITS PLACEHOLDER, verbatim — the behaviour every
     * copy already had. The caller decides what an unbound slot means; a
     * renderer prints it, and the CSS extractor drops it, because a class name
     * containing braces cannot match a rule.
     *
     * A NON-SCALAR VALUE ALSO KEEPS ITS PLACEHOLDER. This is the one place the
     * shared function does not copy the old behaviour: `data: {"class": []}`
     * used to substitute PHP's literal string "Array" (plus an array-to-string
     * warning) into the attribute. Scalars still bind and still stringify —
     * `3` binds as "3", `false` as "" — so nothing an author can reasonably
     * write behaves differently.
     *
     * @param string $template a single string from a component structure
     * @param array  $data     the referencing node's `data` map
     * @return string the same string with every bound slot substituted
     */
    function qs_resolve_component_placeholders(string $template, array $data): string {
        if ($data === [] || strpos($template, '{{') === false) {
            return $template;
        }

        return preg_replace_callback(
            '/\{\{(\$?[\w-]+)\}\}/',
            static function (array $matches) use ($data): string {
                $value = $data[$matches[1]] ?? null;
                return is_scalar($value) ? (string) $value : $matches[0];
            },
            $template
        );
    }
}

if (!function_exists('qs_component_variables')) {
    /**
     * The slots a component leaves open, each with its type: a `{{name}}` in a
     * translatable attribute (TagRegistry::TRANSLATABLE_ATTRIBUTES), a text or a nested
     * call's data is a text slot ("textKey": its value is a translation key), one in any
     * other attribute a "param". Read by addComponentToNode, which makes a text slot's
     * key, and editComponentToNode, which lets only a param change.
     *
     * Left out: system placeholders (starting with __, e.g. __current_page) and
     * placeholders with parameters (containing ;), which are fixed values.
     *
     * @param mixed $node      a component structure or one of its nodes
     * @param array $variables the slots found so far, by name (first found wins)
     * @return array name => [name, type, paramName]
     */
    function qs_component_variables($node, array &$variables = []): array {
        if (!is_array($node)) {
            return $variables;
        }

        // Check params for placeholders
        if (isset($node['params']) && is_array($node['params'])) {
            foreach ($node['params'] as $paramName => $value) {
                if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                    foreach ($matches[1] as $varName) {
                        // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                        if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                            continue;
                        }

                        if (!isset($variables[$varName])) {
                            $type = in_array($paramName, TagRegistry::TRANSLATABLE_ATTRIBUTES, true) ? 'textKey' : 'param';
                            $variables[$varName] = ['name' => $varName, 'type' => $type, 'paramName' => $paramName];
                        }
                    }
                }
            }
        }

        // Check textKey for placeholders
        if (isset($node['textKey']) && is_string($node['textKey'])) {
            if (preg_match_all('/\{\{([^}]+)\}\}/', $node['textKey'], $matches)) {
                foreach ($matches[1] as $varName) {
                    // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                    if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                        continue;
                    }

                    if (!isset($variables[$varName])) {
                        $variables[$varName] = ['name' => $varName, 'type' => 'textKey', 'paramName' => null];
                    }
                }
            }
        }

        // Check component data for placeholders
        if (isset($node['data']) && is_array($node['data'])) {
            foreach ($node['data'] as $value) {
                if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                    foreach ($matches[1] as $varName) {
                        // Skip system placeholders (start with __) and fixed placeholders (contain ;)
                        if (str_starts_with($varName, '__') || strpos($varName, ';') !== false) {
                            continue;
                        }

                        if (!isset($variables[$varName])) {
                            $variables[$varName] = ['name' => $varName, 'type' => 'textKey', 'paramName' => null];
                        }
                    }
                }
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                qs_component_variables($child, $variables);
            }
        }

        return $variables;
    }
}
