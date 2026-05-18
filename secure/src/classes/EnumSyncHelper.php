<?php
/**
 * EnumSyncHelper
 *
 * Keeps `public/scripts/qs-enums.js` in sync with the active project's
 * component-declared enums and the enum references inside endpoint
 * `responseBindings`. Single synchronisation point — no auto-generated
 * commands, no per-binding inline state.
 *
 * Invariant (per BETA7_COMPONENT_LIST_BINDING.md §2a):
 *   bindings ⊆ qs-enums.js ⊆ union of all components' __enums__
 *
 * Where:
 *   - Components' `__enums__`: source of truth, declared per-component
 *     using SHORT keys (e.g. `method_text`, `method_class`).
 *   - `qs-enums.js`: project-scoped runtime registry. Contains exactly
 *     the enums that at least one binding references. Keys are
 *     FULLY-QUALIFIED (`<component-filename>.<short-key>`).
 *   - Bindings: reference enums by the fully-qualified name. Resolved
 *     at runtime via `QS.enum(name, value, fallback)` against
 *     `window.QS_ENUMS`.
 *
 * Naming convention:
 *   Component file `component-command-card.json` declares
 *     "__enums__": { "method_text": { "source": "method", "map": {...} } }
 *   Helper writes
 *     window.QS_ENUMS["component-command-card.method_text"] = { ...map }
 *
 * The component name is the JSON filename (sans `.json`) verbatim —
 * stable, deterministic, no transform fragility.
 *
 * Strategy:
 *   - Sync is forgiving (writes what's valid, warns about what's not).
 *     A binding that references a non-existent enum doesn't block the
 *     save; the runtime falls back to the raw value via QS.enum's
 *     `fallback ?? value` semantics. Warning is surfaced to the
 *     caller for editor display.
 *   - `__RAW__` / `__LIT__` prefixes (renderer-side markers for "skip
 *     i18n" / "literal") are stripped before writing — the runtime
 *     reads plain values.
 *
 * Hooked from:
 *   - editApi (after a successful writeCompiledJs).
 *   - switchProject (after qs-api-config.js regeneration).
 *   - Future component CRUD commands (none ship in beta.7).
 */

class EnumSyncHelper {
    /**
     * Rebuild qs-enums.js from the current project state.
     *
     * @param string|null $projectPath        Defaults to PROJECT_PATH.
     * @param string|null $publicScriptsPath  Defaults to PUBLIC_CONTENT_PATH . '/scripts'.
     * @return array {
     *     ok: bool,                 // false only on filesystem failure.
     *     written: bool,            // whether qs-enums.js was successfully written.
     *     count: int,               // entries in the registry after sync.
     *     warnings: string[],       // bindings referencing non-existent enums.
     *     unreferenced: int,        // count of declared enums not used by any binding.
     *     targetPath: string,       // absolute path written to.
     * }
     */
    public static function sync(?string $projectPath = null, ?string $publicScriptsPath = null): array {
        $projectPath = $projectPath ?? (defined('PROJECT_PATH') ? PROJECT_PATH : '');
        $publicScriptsPath = $publicScriptsPath
            ?? ((defined('PUBLIC_CONTENT_PATH') ? PUBLIC_CONTENT_PATH : '') . '/scripts');

        $targetPath = rtrim($publicScriptsPath, '/\\') . '/qs-enums.js';

        $available  = self::collectAvailableEnums($projectPath);  // full-name => map
        $referenced = self::collectReferencedEnums($projectPath); // [full-name, ...]

        // Validation pass: every referenced enum must exist in some
        // component. Missing references are warnings, not errors —
        // QS.enum's runtime fallback keeps the page functional.
        $warnings = [];
        $missing = [];
        foreach ($referenced as $name) {
            if (!isset($available[$name])) {
                $warnings[] = "Enum '{$name}' is referenced by an endpoint binding but no component declares it. The runtime will fall back to the raw value.";
                $missing[$name] = true;
            }
        }

        // Build the output map: only entries that are both available
        // AND referenced. Sort by key so diffs are stable across writes.
        $output = [];
        foreach ($referenced as $name) {
            if (isset($available[$name])) {
                $output[$name] = $available[$name];
            }
        }
        ksort($output);

        // Make sure the scripts directory exists.
        if (!is_dir($publicScriptsPath)) {
            if (!@mkdir($publicScriptsPath, 0755, true) && !is_dir($publicScriptsPath)) {
                return [
                    'ok' => false,
                    'written' => false,
                    'count' => 0,
                    'warnings' => $warnings,
                    'unreferenced' => count($available) - count($output),
                    'targetPath' => $targetPath,
                    'error' => "Could not create scripts directory: {$publicScriptsPath}",
                ];
            }
        }

        $js = self::renderJs($output);
        $written = @file_put_contents($targetPath, $js, LOCK_EX);
        if ($written === false) {
            return [
                'ok' => false,
                'written' => false,
                'count' => count($output),
                'warnings' => $warnings,
                'unreferenced' => count($available) - count($output),
                'targetPath' => $targetPath,
                'error' => "Could not write {$targetPath}",
            ];
        }

        // Invalidate OPcache for the script if the extension is loaded
        // (qs-enums.js is JS, not PHP, but the broader cache may still
        // have a stale stat — no-op on JS files but harmless).
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($targetPath, true);
        }

        return [
            'ok' => true,
            'written' => true,
            'count' => count($output),
            'warnings' => $warnings,
            'unreferenced' => count($available) - count($output),
            'targetPath' => $targetPath,
        ];
    }

    /**
     * Scan all components for `__enums__` blocks and return a map of
     * fully-qualified names to runtime-shape maps (with `__RAW__` /
     * `__LIT__` prefixes stripped).
     *
     * @return array<string, array<string,mixed>>
     */
    public static function collectAvailableEnums(string $projectPath): array {
        $dir = rtrim($projectPath, '/\\') . '/templates/model/json/components';
        if (!is_dir($dir)) return [];

        $out = [];
        foreach (glob($dir . '/*.json') as $file) {
            $componentName = basename($file, '.json');
            $json = @file_get_contents($file);
            if ($json === false) continue;
            $component = json_decode($json, true);
            if (!is_array($component)) continue;
            if (empty($component['__enums__']) || !is_array($component['__enums__'])) continue;

            foreach ($component['__enums__'] as $shortKey => $def) {
                if (!is_string($shortKey) || $shortKey === '') continue;
                if (!is_array($def)) continue;
                $map = $def['map'] ?? null;
                if (!is_array($map) || empty($map)) continue;

                $stripped = [];
                foreach ($map as $k => $v) {
                    $stripped[(string)$k] = self::stripRenderMarkers($v);
                }

                $fullName = $componentName . '.' . $shortKey;
                // First wins on collision — but the naming scheme makes
                // collisions across components impossible (filename
                // segment differs). Within a single component the same
                // short key declared twice would already be invalid JSON
                // (duplicate object key).
                if (!isset($out[$fullName])) {
                    $out[$fullName] = $stripped;
                }
            }
        }
        return $out;
    }

    /**
     * Scan every endpoint's `responseBindings.fieldMap.*.enum` and
     * return a de-duplicated, sorted list of referenced enum names.
     *
     * @return string[]
     */
    public static function collectReferencedEnums(string $projectPath): array {
        $file = rtrim($projectPath, '/\\') . '/data/api-endpoints.json';
        if (!file_exists($file)) return [];
        $json = @file_get_contents($file);
        if ($json === false) return [];
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['apis']) || !is_array($data['apis'])) return [];

        $names = [];
        foreach ($data['apis'] as $api) {
            if (empty($api['endpoints']) || !is_array($api['endpoints'])) continue;
            foreach ($api['endpoints'] as $endpoint) {
                $bindings = $endpoint['responseBindings'] ?? null;
                if (!is_array($bindings)) continue;
                foreach ($bindings as $binding) {
                    if (!is_array($binding)) continue;
                    $fieldMap = $binding['fieldMap'] ?? null;
                    if (!is_array($fieldMap)) continue;
                    foreach ($fieldMap as $spec) {
                        if (is_array($spec) && !empty($spec['enum']) && is_string($spec['enum'])) {
                            $names[$spec['enum']] = true;
                        }
                    }
                }
            }
        }
        ksort($names);
        return array_keys($names);
    }

    /**
     * Strip `__RAW__` / `__LIT__` prefixes from a value. The renderer
     * uses these to skip i18n / mark as literal; the runtime QS.enum
     * just substitutes plain strings.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function stripRenderMarkers($value) {
        if (!is_string($value)) return $value;
        if (strncmp($value, '__RAW__', 7) === 0) return substr($value, 7);
        if (strncmp($value, '__LIT__', 7) === 0) return substr($value, 7);
        return $value;
    }

    /**
     * Render the JS file. Pretty-printed JSON for readability; the
     * runtime parser doesn't care.
     */
    private static function renderJs(array $enums): string {
        $stamp = date('c');
        $count = count($enums);
        $header =  "// Auto-generated by EnumSyncHelper. Do NOT edit by hand —\n";
        $header .= "// re-runs whenever editApi or switchProject writes its config.\n";
        $header .= "// Generated: {$stamp} ({$count} entries)\n";
        $header .= "//\n";
        $header .= "// Each entry maps a fully-qualified enum name\n";
        $header .= "// (<component-filename>.<short-key>) to its runtime\n";
        $header .= "// lookup table. Bindings reference these via\n";
        $header .= "// fieldMap[var].enum; runtime resolves via QS.enum().\n\n";

        $payload = empty($enums)
            ? '{}'
            : json_encode($enums, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $header . "window.QS_ENUMS = " . $payload . ";\n";
    }
}
