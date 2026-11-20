<?php
require_once __DIR__ . '/utilsManagement.php'; // qs_json_write
/**
 * translationHelpers.php — pure helpers + callable writer for translation files
 *
 * Extracted from setTranslationKeys.php (and adopted by importStructureTranslations.php)
 * so future translation-writing commands get the same plumbing for free instead of
 * inlining duplicates. The collision-detection + string-vs-branch rule is identical
 * to setTranslationKeys' merge behaviour: a key may be a leaf string OR a parent of
 * nested keys, never both — every mainstream i18n library (i18next, Rails I18n,
 * Symfony Translation, gettext via context) enforces the same constraint.
 *
 * Loading is idempotent — guarded so commands can include both this and the
 * caller's own translation-related helpers without double-declaration errors.
 */

if (!function_exists('convertDotNotationToNested')) {

/**
 * Translation-key validity check — single source of truth.
 *
 * Permissive on purpose. The runtime Translator + setTranslationKeys +
 * the JSON storage all accept any non-empty string as a key. Per-character
 * regexes (the old `translation_key_simple` pattern) tried to enforce
 * `[a-zA-Z0-9._-]+$` but real keys legitimately include:
 *   - `/` for nested-route titles (e.g. page.titles.documentation/commands)
 *   - `$` for component template variables (e.g. reassurance-item1.$icon)
 *   - any UTF-8 character a translator chose for an identifier
 * Trying to keep a character whitelist was whack-a-mole — every new
 * legitimate pattern broke deleteTranslationKeys. This helper replaces
 * the per-character rule with the minimal security floor.
 *
 * Returns true iff `$key` is a string, non-empty after trim, and
 * contains no null byte (PHP's filesystem path-traversal sentinel).
 * The dot-notation key never reaches a filesystem path — only the
 * `language` param does, which is validated separately — so we don't
 * need to block `..` or `/` here.
 */
function isValidTranslationKey($key): bool {
    if (!is_string($key)) return false;
    if (trim($key) === '') return false;
    if (strpos($key, "\0") !== false) return false;
    return true;
}

/**
 * Convert flat dot-notation keys to a nested structure.
 * {"menu.home": "Home"} → {"menu": {"home": "Home"}}
 */
function convertDotNotationToNested(array $flat): array {
    $nested = [];
    foreach ($flat as $key => $value) {
        if (strpos((string)$key, '.') !== false) {
            $parts = explode('.', (string)$key);
            $current = &$nested;
            $lastIndex = count($parts) - 1;
            foreach ($parts as $i => $part) {
                if ($i === $lastIndex) {
                    $current[$part] = $value;
                } else {
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
            unset($current);
        } else {
            if (is_array($value)) {
                $nested[$key] = convertDotNotationToNested($value);
            } else {
                $nested[$key] = $value;
            }
        }
    }
    return $nested;
}

/**
 * Recursively merge new translations into existing.
 * New values overwrite existing leaves; nested branches recurse;
 * unspecified keys are preserved.
 */
function mergeTranslations(array $existing, array $new): array {
    foreach ($new as $key => $value) {
        if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
            $existing[$key] = mergeTranslations($existing[$key], $value);
        } else {
            $existing[$key] = $value;
        }
    }
    return $existing;
}

/**
 * Strip keys carrying unresolved `{{` component placeholders. Those are
 * authoring artifacts and must never reach a translation file.
 */
function sanitizePlaceholderKeys(array $data): array {
    $clean = [];
    foreach ($data as $key => $value) {
        if (is_string($key) && strpos($key, '{{') !== false) {
            continue;
        }
        $clean[$key] = is_array($value) ? sanitizePlaceholderKeys($value) : $value;
    }
    return $clean;
}

/**
 * Walk the incoming payload against the existing tree to detect
 * string-vs-branch collisions at the same path. Returns an array of
 * collision descriptors; [] = clean.
 */
function detectTranslationCollisions(array $existing, array $new, string $pathPrefix = ''): array {
    $collisions = [];
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) continue;
        $path = $pathPrefix === '' ? (string)$key : ($pathPrefix . '.' . $key);
        $existingIsLeaf = !is_array($existing[$key]);
        $newIsLeaf = !is_array($value);

        if ($existingIsLeaf && !$newIsLeaf) {
            $childKey = is_array($value) ? array_key_first($value) : '';
            $newSiblingPath = $pathPrefix === '' ? (string)$childKey : ($pathPrefix . '.' . $childKey);
            $collisions[] = [
                'path' => $path,
                'existingShape' => 'string',
                'newShape' => 'nested branch',
                'suggestion' =>
                    "Key '$path' is already a string. Translation systems can't "
                    . "make it BOTH a string AND a parent of nested keys at the "
                    . "same time. Options: (a) rename the new key as a sibling — "
                    . "e.g. '" . $newSiblingPath . "' instead of '" . $path . "." . $childKey . "'; "
                    . "or (b) delete the existing string at '$path' first, then re-add it as a branch."
            ];
        } elseif (!$existingIsLeaf && $newIsLeaf) {
            $collisions[] = [
                'path' => $path,
                'existingShape' => 'nested branch',
                'newShape' => 'string',
                'suggestion' =>
                    "Key '$path' is already a branch with nested sub-keys. "
                    . "Overwriting it with a string would silently drop those children. "
                    . "Options: (a) rename the new string to a sibling like '$path.text' or '$path.value'; "
                    . "or (b) delete the existing branch at '$path' first via deleteTranslationKeys, then re-add the string."
            ];
        } elseif (!$existingIsLeaf && !$newIsLeaf) {
            foreach (detectTranslationCollisions($existing[$key], $value, $path) as $sub) {
                $collisions[] = $sub;
            }
        }
        // else: both leaves — standard merge, no collision.
    }
    return $collisions;
}

/**
 * Count net additions/updates between existing and new payloads (for
 * reporting in the command's response). Mutates $added / $updated by
 * reference. Brand-new keys count as additions; matched paths with
 * different leaf values count as updates; nested arrays recurse.
 */
function countTranslationChanges(array $existing, array $new, int &$added, int &$updated): void {
    foreach ($new as $key => $value) {
        if (!isset($existing[$key])) {
            if (is_array($value)) {
                $added += countNestedTranslationKeys($value);
            } else {
                $added++;
            }
        } elseif (is_array($value) && is_array($existing[$key])) {
            countTranslationChanges($existing[$key], $value, $added, $updated);
        } elseif ($existing[$key] !== $value) {
            $updated++;
        }
    }
}

/**
 * Count leaf keys recursively. Helper for countTranslationChanges when
 * a whole branch is newly added.
 */
function countNestedTranslationKeys(array $arr): int {
    $count = 0;
    foreach ($arr as $value) {
        if (is_array($value)) {
            $count += countNestedTranslationKeys($value);
        } else {
            $count++;
        }
    }
    return $count;
}

/**
 * The named groups of a translation tree, as a tree of their own: each path is dotted ('consent',
 * 'storage.desc'), and a path the tree does not hold is skipped.
 */
function qs_translation_groups(array $tree, array $paths): array {
    $groups = [];
    foreach ($paths as $path) {
        $parts = explode('.', $path);
        $value = $tree;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                continue 2;
            }
            $value = $value[$part];
        }
        foreach (array_reverse($parts) as $part) {
            $value = [$part => $value];
        }
        $groups = mergeTranslations($groups, $value);
    }
    return $groups;
}

/**
 * Load → optional collision check → merge → write a translation file,
 * with its copy into default.json (every write on a one-language project,
 * a write in the default language alone on a multilingual one), checked
 * for clashes as the language's file is. Callers handle higher-level input
 * validation (language code format, payload depth/size) — this function
 * assumes both inputs are already trusted.
 *
 * Return shape:
 *   success: ['ok' => true, 'file' => string, 'keysAdded' => int,
 *             'keysUpdated' => int, 'defaultSynced' => bool,
 *             'merged' => array]
 *   collision: ['ok' => false, 'reason' => 'collisions',
 *               'collisions' => array]
 *   io / encode failure: ['ok' => false,
 *                         'reason' => 'json_encode_failed' | 'file_write_failed',
 *                         'message' => string]
 *   size check refused: ['ok' => false, 'reason' => 'refused',
 *                        'response' => ApiResponse (the 413 / 507 to answer),
 *                        'message' => string] — every file the command
 *                        wrote is put back (qs_small_write_guard)
 */
function writeTranslationsToFile(string $language, array $newNested, bool $replaceMode = false, array $keep = []): array {
    $translationsFile = PROJECT_PATH . '/translate/' . $language . '.json';
    $isDefault = ($language === 'default');

    // Replace mode with groups to keep: what the file holds under them goes into the new payload,
    // which wins where it names the same key.
    if ($replaceMode && $keep !== [] && file_exists($translationsFile)) {
        $current = json_decode((string) @file_get_contents($translationsFile), true);
        if (is_array($current)) {
            $newNested = mergeTranslations(qs_translation_groups($current, $keep), $newNested);
        }
    }

    // Load existing (skip in replace mode — the new payload replaces it)
    $existingTranslations = [];
    if (!$replaceMode && file_exists($translationsFile)) {
        $existingJson = @file_get_contents($translationsFile);
        if ($existingJson !== false) {
            $decoded = json_decode($existingJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingTranslations = $decoded;
            }
        }
    }

    // Collision check (merge mode only). In replace mode we're nuking
    // the file, so by definition no pre-existing shape can clash.
    if (!$replaceMode && !empty($existingTranslations)) {
        $collisions = detectTranslationCollisions($existingTranslations, $newNested);
        if (!empty($collisions)) {
            return [
                'ok' => false,
                'reason' => 'collisions',
                'collisions' => $collisions,
            ];
        }
    }

    // Merge + change counts
    $mergedTranslations = $replaceMode
        ? $newNested
        : mergeTranslations($existingTranslations, $newNested);

    $keysAdded = 0;
    $keysUpdated = 0;
    countTranslationChanges($existingTranslations, $newNested, $keysAdded, $keysUpdated);

    // Encode
    $jsonContent = json_encode(
        $mergedTranslations,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($jsonContent === false) {
        return [
            'ok' => false,
            'reason' => 'json_encode_failed',
            'message' => 'Failed to encode translations to JSON',
        ];
    }

    // The copy into default.json, the words a language without a file of its own reads: on a one-language
    // project every write, so its pages read it; on a multilingual project a write in the default language
    // alone, so it never holds whichever language was written last. It takes the same clash check as the
    // language's file, before anything is written: a word there is never silently replaced by a group, nor
    // a group by a word.
    require_once __DIR__ . '/projectLanguage.php';
    $defaultFile = PROJECT_PATH . '/translate/default.json';
    $defaultJsonContent = false;
    if (!$isDefault && (!qs_project_is_multilingual() || $language === qs_project_default_language())) {
        $existingDefault = [];
        if (file_exists($defaultFile)) {
            $defaultJson = @file_get_contents($defaultFile);
            if ($defaultJson !== false) {
                $decoded = json_decode($defaultJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingDefault = $decoded;
                }
            }
        }
        $collisions = detectTranslationCollisions($existingDefault, $newNested);
        if (!empty($collisions)) {
            foreach ($collisions as &$collision) {
                $collision['suggestion'] = "In the words of 'default' (the file a page reads when its language has none of its own): "
                    . $collision['suggestion'];
            }
            unset($collision);
            return [
                'ok' => false,
                'reason' => 'collisions',
                'collisions' => $collisions,
            ];
        }
        $mergedDefault = mergeTranslations($existingDefault, $newNested);
        if ($mergedDefault !== $existingDefault) {
            $defaultJsonContent = json_encode(
                $mergedDefault,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    }

    // The size check, write by write (qs_small_write_guard): this writer also
    // runs inside chains of helpers, and a refusal takes back every file the
    // command wrote before it — this language file included.
    require_once __DIR__ . '/quota.php';
    $refused = static function (): array {
        $refusal = qs_small_write_refused();
        return [
            'ok' => false,
            'reason' => 'refused',
            'response' => $refusal,
            'message' => $refusal !== null ? (string)($refusal->toArray()['message'] ?? '') : '',
        ];
    };

    if (!qs_small_write_guard($translationsFile, strlen($jsonContent))) {
        return $refused();
    }
    // Ensure directory exists (importStructureTranslations relied on this)
    $translateDir = dirname($translationsFile);
    if (!is_dir($translateDir)) {
        @mkdir($translateDir, 0755, true);
    }

    if (file_put_contents($translationsFile, $jsonContent, LOCK_EX) === false) {
        return [
            'ok' => false,
            'reason' => 'file_write_failed',
            'message' => 'Failed to write translation file',
        ];
    }

    $defaultUpdated = false;
    if ($defaultJsonContent !== false) {
        if (!qs_small_write_guard($defaultFile, strlen($defaultJsonContent))) {
            return $refused();
        }
        $defaultUpdated = file_put_contents($defaultFile, $defaultJsonContent, LOCK_EX) !== false;
    }

    return [
        'ok' => true,
        'file' => $translationsFile,
        'keysAdded' => $keysAdded,
        'keysUpdated' => $keysUpdated,
        'defaultSynced' => $defaultUpdated,
        'merged' => $mergedTranslations,
    ];
}

/**
 * Read a single dot-notation key from translate/<lang>.json. Returns null if the
 * file is missing/invalid, the key is absent, or the value isn't a string. Reads
 * ONLY that language file (no fallback) — callers layer their own fallback.
 */
function translationGetKey(string $lang, string $dotKey): ?string {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return null;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return null;
    $cur = $decoded;
    foreach (explode('.', $dotKey) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    return is_string($cur) ? $cur : null;
}

/**
 * Set a single dot-notation key in translate/<lang>.json to a literal value,
 * creating parents as needed. Edits ONLY that language file — no default.json
 * sync (unlike writeTranslationsToFile) — used to empty/patch one key in place.
 */
function translationSetKey(string $lang, string $dotKey, string $value): void {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return;
    $parts = explode('.', $dotKey);
    $leaf  = array_pop($parts);
    $cur = &$decoded;
    foreach ($parts as $part) {
        if (!isset($cur[$part]) || !is_array($cur[$part])) { $cur[$part] = []; }
        $cur = &$cur[$part];
    }
    $cur[$leaf] = $value;
    unset($cur);
    // The size check, write by write: these run inside chains of helpers. A refusal
    // leaves the file as it is and is answered by the command (qs_small_write_refused).
    require_once __DIR__ . '/quota.php';
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (!qs_small_write_guard($file, qs_json_size($decoded, $flags))) {
        return;
    }
    qs_json_write($file, $decoded, $flags, LOCK_EX);
}

/** Unset a single dot-notation key from translate/<lang>.json (best-effort). */
function translationUnsetKey(string $lang, string $dotKey): void {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    if (!file_exists($file)) return;
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) return;
    $parts = explode('.', $dotKey);
    $leaf  = array_pop($parts);
    $cur = &$decoded;
    foreach ($parts as $part) {
        if (!is_array($cur) || !isset($cur[$part]) || !is_array($cur[$part])) return;
        $cur = &$cur[$part];
    }
    if (!array_key_exists($leaf, $cur)) return;
    unset($cur[$leaf]);
    unset($cur);
    require_once __DIR__ . '/quota.php';
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (!qs_small_write_guard($file, qs_json_size($decoded, $flags))) {
        return;
    }
    qs_json_write($file, $decoded, $flags, LOCK_EX);
}

// ---------------------------------------------------------------------------
// Which keys a site uses: one answer, by source.
// ---------------------------------------------------------------------------

/**
 * The translation keys a site uses, by source: THE answer every reader of a site's keys takes
 * (getTranslationKeys, getUnusedTranslationKeys, validateTranslations, analyzeTranslations, and deleteNode,
 * which removes a key only once this no longer finds it). A new place where a site names a key the engine
 * looks up joins this function, and the census of those places (MAINTAINING.md).
 *
 * It reads no word file: what a language holds is the caller's (qs_translation_keys_in_use()). Each key comes
 * with what it needs, the stronger winning where two places name it:
 *   required  needed in every language: a visitor sees it, and a missing word shows the missing marker
 *   optional  used where a language holds it, never missing where none does
 *
 * The sources, in this order (each name its own: a page routed `menu` is `page:menu`, never the menu's):
 *   page:<route>        each page by its route, nested and parameter routes included: its structure; its title,
 *                       page.titles.<route> (required; a special page with a structure has one too); its
 *                       description, page.descriptions.<route> (optional); its page events' call arguments
 *   menu, footer        the two layout structures
 *   component:<name>    each component as its file stands: the keys it writes itself and the options of its enums
 *                       that land in a text, all optional (a page that places it needs them, under that page)
 *   site:consent        the consent layer's banner and popup, once generated
 *   site:media          the key a Media picture's alt holds (required: Media's badge marks a language without it)
 *   site:api            an API's count sentences, zeroKey, oneKey and manyKey (required)
 *   site:link-previews  the site's name in a page's link-preview tags, site.name (optional)
 *   site:storage        the Storage registry's descriptions, storage.desc.<item> (optional)
 *   site:privacy        the Privacy registry's labels and purposes (optional)
 * A page, the menu, the footer, a component and the consent layer are answered whenever their file exists,
 * keys or none; a site: source only when it names a key.
 *
 * Where a structure names a key, the renderer's rule decides (JsonToHtmlRenderer, and JsonToPhpCompiler for a
 * build): a text node's text is a key unless it is empty, raw (__RAW__) or an unbound slot; a translated attribute
 * (TagRegistry::TRANSLATABLE_ATTRIBUTES) holds one when it has the key shape and is not raw; an event attribute's
 * call chain names one in each translatable argument (CallTransformer::transformSegments()): a keyword argument
 * is required, a positional one optional, as it falls back to its own text. A placed component is read as the
 * renderer draws it: its enums chosen, its data written into it (qs_resolve_component_placeholders()), the call's
 * params over its root's, a nested call followed up to QS_SITE_KEYS_COMPONENT_DEPTH calls and never into itself.
 *
 * @param string $projectPath the project's folder
 * @return array<string, array<string, string>> source => key => 'required' | 'optional'
 */
function qs_site_translation_keys(string $projectPath): array {
    qs_site_keys_require();

    $projectPath = rtrim(str_replace('\\', '/', $projectPath), '/');
    $json = $projectPath . '/templates/model/json';
    $componentsDir = $json . '/components';
    $sources = [];

    // Pages: each structure file by its route, then every route's title and description.
    $pages = [];
    foreach (scanAllPageJsonFiles($projectPath) as $page) {
        $keys = [];
        qs_site_keys_walk(loadJsonStructure($page['path']), $keys, $componentsDir, [], false);
        $pages[$page['route']] = $keys;
    }
    $routesFile = $projectPath . '/routes.php';
    $routes = is_file($routesFile) ? (static function (string $f) { return include $f; })($routesFile) : [];
    $routes = is_array($routes) ? flattenRoutes($routes) : [];
    foreach ($routes as $route) {
        $pages[$route] = $pages[$route] ?? [];
        qs_site_keys_add($pages[$route], 'page.titles.' . $route, 'required');
        qs_site_keys_add($pages[$route], 'page.descriptions.' . $route, 'optional');
    }
    foreach (array_keys($pages) as $route) {
        if (in_array((string) $route, SPECIAL_PAGES, true)) {
            qs_site_keys_add($pages[$route], 'page.titles.' . $route, 'required');
        }
    }
    // Page events: each route's chains, joined per event as both page shells join them.
    $events = json_decode((string) @file_get_contents($projectPath . '/data/page-events.json'), true);
    foreach (is_array($events) ? $events : [] as $route => $byEvent) {
        if (!is_array($byEvent)) {
            continue;
        }
        foreach (['onload', 'onresize', 'onscroll'] as $event) {
            if (!empty($byEvent[$event]) && is_array($byEvent[$event])) {
                $route = (string) $route;
                $pages[$route] = $pages[$route] ?? [];
                qs_site_keys_call_chain(implode('', array_filter($byEvent[$event], 'is_string')), $pages[$route], false);
            }
        }
    }
    ksort($pages, SORT_STRING);
    foreach ($pages as $route => $keys) {
        $sources['page:' . $route] = $keys;
    }

    // The two layout structures.
    foreach (['menu', 'footer'] as $layout) {
        if (is_file("$json/$layout.json")) {
            $keys = [];
            qs_site_keys_walk(loadJsonStructure("$json/$layout.json"), $keys, $componentsDir, [], false);
            $sources[$layout] = $keys;
        }
    }

    // Each component as its file stands: what it writes itself, and each enum option written into it.
    $componentFiles = is_dir($componentsDir) ? (glob($componentsDir . '/*.json') ?: []) : [];
    sort($componentFiles, SORT_STRING);
    foreach ($componentFiles as $file) {
        $name = basename($file, '.json');
        $template = loadJsonStructure($file);
        $keys = [];
        if (is_array($template)) {
            $enums = is_array($template['__enums__'] ?? null) ? $template['__enums__'] : [];
            unset($template['__enums__']);
            qs_site_keys_walk($template, $keys, $componentsDir, [$name], true);
            foreach ($enums as $variable => $enum) {
                if (!is_array($enum) || !is_array($enum['map'] ?? null)) {
                    continue;
                }
                foreach ($enum['map'] as $option) {
                    if (is_scalar($option)) {
                        qs_site_keys_walk(qs_site_keys_fill($template, [(string) $variable => $option]), $keys, $componentsDir, [$name], true);
                    }
                }
            }
        }
        $sources['component:' . $name] = $keys;
    }

    // The consent layer, once generated.
    $consent = [];
    $generated = false;
    foreach (['consent-banner', 'consent-popup'] as $part) {
        if (is_file("$json/$part.json")) {
            $generated = true;
            qs_site_keys_walk(loadJsonStructure("$json/$part.json"), $consent, $componentsDir, [], false);
        }
    }
    if ($generated) {
        $sources['site:consent'] = $consent;
    }

    // The key each Media picture's alt holds (Media's own test of a key: QSMediaAlt.isKey()).
    $media = [];
    $metadata = json_decode((string) @file_get_contents($projectPath . '/data/assets_metadata.json'), true);
    foreach (is_array($metadata) ? $metadata : [] as $entry) {
        $alt = is_array($entry) && is_string($entry['alt'] ?? null) ? trim($entry['alt']) : '';
        if (strpos($alt, '__RAW__') !== 0 && qs_site_keys_is_key_shaped($alt)) {
            qs_site_keys_add($media, $alt, 'required');
        }
    }

    // An API's count sentences: the keys qs_api_count_strings() hands a served page.
    $api = [];
    require_once __DIR__ . '/apiRegistry.php';
    foreach (qs_api_load_config($projectPath)['apis'] as $definition) {
        foreach ((is_array($definition) ? ($definition['endpoints'] ?? []) : []) as $endpoint) {
            foreach ((is_array($endpoint) ? ($endpoint['responseBindings'] ?? []) : []) as $binding) {
                if (!is_array($binding) || ($binding['renderMode'] ?? null) !== 'count' || ($binding['format'] ?? null) !== 'sentence') {
                    continue;
                }
                foreach (['zeroKey', 'oneKey', 'manyKey'] as $field) {
                    if (is_string($binding[$field] ?? null) && $binding[$field] !== '') {
                        qs_site_keys_add($api, $binding[$field], 'required');
                    }
                }
            }
        }
    }

    // The registries' words: a registry owns them, as a reset keeps them (fresh-start's keep).
    require_once __DIR__ . '/storageHelpers.php';
    require_once __DIR__ . '/privacyHelpers.php';
    $storage = [];
    $storageRegistry = json_decode((string) @file_get_contents($projectPath . '/data/storage.json'), true);
    foreach (is_array($storageRegistry['items'] ?? null) ? $storageRegistry['items'] : [] as $id => $item) {
        qs_site_keys_add($storage, storageDescKey((string) $id), 'optional');
    }
    $privacy = [];
    $privacyRegistry = json_decode((string) @file_get_contents($projectPath . '/data/privacy.json'), true);
    foreach (is_array($privacyRegistry['collectedData'] ?? null) ? $privacyRegistry['collectedData'] : [] as $id) {
        if (is_string($id) && $id !== '') {
            qs_site_keys_add($privacy, privacyLabelKey($id), 'optional');
            qs_site_keys_add($privacy, privacyPurposeKey($id), 'optional');
        }
    }

    foreach (['site:media' => $media, 'site:api' => $api, 'site:link-previews' => ['site.name' => 'optional'],
              'site:storage' => $storage, 'site:privacy' => $privacy] as $source => $keys) {
        if ($keys !== []) {
            $sources[$source] = $keys;
        }
    }
    return $sources;
}

/** How many component calls deep qs_site_translation_keys() follows a placement (qs_component_variables()' depth). */
define('QS_SITE_KEYS_COMPONENT_DEPTH', 10);

/** What the key walk reads beside this file: the component rule, the tag lists, the call chains. */
function qs_site_keys_require(): void {
    require_once __DIR__ . '/componentPolicy.php';
    require_once __DIR__ . '/../classes/TagRegistry.php';
    require_once __DIR__ . '/../classes/CallTransformer.php';
}

/**
 * The keys one node names, and its children's, by the rule qs_site_translation_keys() reads a structure with, in the
 * order the node names them: deleteNode's candidates. $asWritten: the node is in a component's file, where a value
 * still holding a slot names no key yet.
 *
 * @return string[]
 */
function qs_node_translation_keys(array $node, string $projectPath, bool $asWritten): array {
    qs_site_keys_require();
    $keys = [];
    qs_site_keys_walk($node, $keys, rtrim(str_replace('\\', '/', $projectPath), '/') . '/templates/model/json/components', [], $asWritten);
    return array_map('strval', array_keys($keys));
}

/** A key named in a source; required wins over optional. */
function qs_site_keys_add(array &$keys, string $key, string $need): void {
    if (!isset($keys[$key]) || $need === 'required') {
        $keys[$key] = $need;
    }
}

/**
 * The renderer's test of a key in a translated attribute (JsonToHtmlRenderer::renderAttribute(),
 * JsonToPhpCompiler::attributeValueExpr()), Media's (QSMediaAlt.isKey()) and the link preview's
 * (qs_social_picture_alt()): segments of letters, digits and underscores joined by dots, two at least.
 */
function qs_site_keys_is_key_shaped(string $value): bool {
    return preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/iD', $value) === 1;
}

/**
 * The keys one node names, and its children's, as the renderer reads them (qs_site_translation_keys()). $asWritten:
 * a component's file as it stands, so every key is optional and a value still holding a slot is no key yet.
 *
 * @param mixed    $node  one node, or a structure stored as a list of nodes
 * @param string[] $stack the components the walk is inside of
 */
function qs_site_keys_walk($node, array &$keys, string $componentsDir, array $stack, bool $asWritten): void {
    if (!is_array($node)) {
        return;
    }
    if (array_is_list($node)) {
        foreach ($node as $child) {
            qs_site_keys_walk($child, $keys, $componentsDir, $stack, $asWritten);
        }
        return;
    }
    $need = $asWritten ? 'optional' : 'required';
    $slotFree = static fn(string $v): bool => !$asWritten || qs_component_slot_names($v) === [];

    if (isset($node['component'])) {
        qs_site_keys_placed($node, $keys, $componentsDir, $stack, $asWritten);
        return;
    }
    if (isset($node['textKey'])) {
        // renderTextNode(): empty draws nothing, __RAW__ is text, a lone unbound slot shows as written.
        $text = $node['textKey'];
        if (is_string($text) && !empty($text) && strpos($text, '__RAW__') !== 0
            && preg_match('/\A\{\{\$?\w+\}\}\n?\z/', $text) !== 1 && $slotFree($text)) {
            qs_site_keys_add($keys, $text, $need);
        }
        return;
    }
    if (!isset($node['tag'])) {
        return;
    }
    $params = is_array($node['params'] ?? null) ? $node['params'] : [];
    foreach (TagRegistry::TRANSLATABLE_ATTRIBUTES as $attribute) {
        $value = $params[$attribute] ?? null;
        if (is_string($value) && strpos($value, '__RAW__') !== 0 && qs_site_keys_is_key_shaped($value)) {
            qs_site_keys_add($keys, $value, $need);
        }
    }
    foreach ($params as $name => $value) {
        if (is_string($value) && preg_match('/^on[a-z]+$/iD', (string) $name) === 1 && strpos($value, '{{call:') !== false) {
            qs_site_keys_call_chain($value, $keys, $asWritten);
        }
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            qs_site_keys_walk($child, $keys, $componentsDir, $stack, $asWritten);
        }
    }
}

/** The keys a call chain's translatable arguments name: a keyword argument required, a positional one optional. */
function qs_site_keys_call_chain(string $chain, array &$keys, bool $asWritten): void {
    foreach (CallTransformer::transformSegments($chain) as $segment) {
        if (!is_array($segment) || !isset($segment['arg']) || !is_string($segment['arg'])) {
            continue;
        }
        if ($asWritten && qs_component_slot_names($segment['arg']) !== []) {
            continue;
        }
        qs_site_keys_add($keys, $segment['arg'], ($segment['keyword'] !== null && !$asWritten) ? 'required' : 'optional');
    }
}

/**
 * A placed component, read as the renderer draws it (renderNode()): its enums chosen, its data written into it, the
 * call's params over its root's, then walked; a reference that resolves to no file, a call of a component the walk
 * is inside of, or one deeper than QS_SITE_KEYS_COMPONENT_DEPTH names nothing.
 */
function qs_site_keys_placed(array $node, array &$keys, string $componentsDir, array $stack, bool $asWritten): void {
    $name = $node['component'];
    if (!is_string($name) || in_array($name, $stack, true) || count($stack) >= QS_SITE_KEYS_COMPONENT_DEPTH) {
        return;
    }
    $path = qs_resolve_component_path($name, $componentsDir);
    $template = $path !== null ? loadJsonStructure($path) : null;
    if (!is_array($template)) {
        return;
    }
    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
    $enums = is_array($template['__enums__'] ?? null) ? $template['__enums__'] : [];
    unset($template['__enums__']);
    $template = qs_site_keys_fill($template, qs_site_keys_enum_data($enums, $data));
    if (is_array($node['params'] ?? null) && $node['params'] !== [] && is_array($template)) {
        // class and style are joined by the renderer rather than replaced: neither ever holds a key.
        $template['params'] = $node['params'] + (is_array($template['params'] ?? null) ? $template['params'] : []);
    }
    $stack[] = $name;
    qs_site_keys_walk($template, $keys, $componentsDir, $stack, $asWritten);
}

/**
 * A component's data with its enums chosen, as the renderer's and the compiler's resolveEnumVariables() choose them:
 * the option the source names when the map has it, else the enum's default, else the map's first.
 */
function qs_site_keys_enum_data(array $enums, array $data): array {
    foreach ($enums as $variable => $enum) {
        if (!is_array($enum) || !isset($enum['source'], $enum['map']) || !is_array($enum['map']) || $enum['map'] === []) {
            continue;
        }
        $map = $enum['map'];
        $chosen = is_scalar($enum['source']) ? ($data[(string) $enum['source']] ?? null) : null;
        if (!is_scalar($chosen) || !array_key_exists((string) $chosen, $map)) {
            $chosen = $enum['default'] ?? array_key_first($map);
        }
        $data[(string) $variable] = is_scalar($chosen) ? ($map[(string) $chosen] ?? null) : null;
    }
    return $data;
}

/** A component's template with its data written in, string by string (the renderer's processComponentTemplate()). */
function qs_site_keys_fill($template, array $data) {
    if (is_string($template)) {
        return qs_resolve_component_placeholders($template, $data);
    }
    if (is_array($template)) {
        foreach ($template as $k => $v) {
            $template[$k] = qs_site_keys_fill($v, $data);
        }
    }
    return $template;
}

/**
 * The keys a site's sources name, as one language or several make them used: every required key, and each optional
 * one a word tree holds (any of $trees). The readers' one step from qs_site_translation_keys() to their answers.
 *
 * @param array   $sites qs_site_translation_keys()'s answer
 * @param array[] $trees word trees, as their files decode
 * @return array{used: string[], required: string[], bySource: array<string, string[]>} used and required sorted;
 *         bySource each source's used keys, in its order
 */
function qs_translation_keys_in_use(array $sites, array $trees): array {
    $used = [];
    $required = [];
    $bySource = [];
    foreach ($sites as $source => $keys) {
        $bySource[$source] = [];
        foreach ($keys as $key => $need) {
            $key = (string) $key;
            $isUsed = $need === 'required';
            foreach ($isUsed ? [] : $trees as $tree) {
                if (is_array($tree) && qs_translation_tree_holds($tree, $key)) {
                    $isUsed = true;
                    break;
                }
            }
            if ($isUsed) {
                $bySource[$source][] = $key;
                $used[$key] = true;
            }
            if ($need === 'required') {
                $required[$key] = true;
            }
        }
    }
    $used = array_map('strval', array_keys($used));
    $required = array_map('strval', array_keys($required));
    sort($used, SORT_STRING);
    sort($required, SORT_STRING);
    return ['used' => $used, 'required' => $required, 'bySource' => $bySource];
}

/** Every key a site's sources name, whatever its need: what deleteNode must never remove. */
function qs_site_translation_keys_named(array $sites): array {
    $named = [];
    foreach ($sites as $keys) {
        foreach ($keys as $key => $need) {
            $named[(string) $key] = true;
        }
    }
    return $named;
}

/** Whether a word tree holds a key as a word (a leaf, empty or not), not as a group. */
function qs_translation_tree_holds(array $tree, string $key): bool {
    $current = $tree;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return false;
        }
        $current = $current[$segment];
    }
    return !is_array($current);
}

/** Whether a word tree gives a key a word a page shows: a text that is not empty (Translator::lookup()'s rule). */
function qs_translation_tree_has_word(array $tree, string $key): bool {
    $current = $tree;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return false;
        }
        $current = $current[$segment];
    }
    return is_scalar($current) && (string) $current !== '';
}

/** Every key a word tree holds as a word, in dot notation (`{"menu": {"home": "Home"}}` → `menu.home`). */
function qs_translation_tree_keys(array $tree, string $prefix = ''): array {
    $keys = [];
    foreach ($tree as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, qs_translation_tree_keys($value, $full));
        } else {
            $keys[] = $full;
        }
    }
    return $keys;
}

/**
 * The words each language's pages read, by language (Translator::loadTranslations()'s rule): on a multilingual
 * project each language's file, default.json for one that has none; otherwise default.json alone, as `default`.
 * The current project's languages; a file that does not read is left out.
 *
 * @return array<string, array>
 */
function qs_translation_trees_read(string $projectPath): array {
    require_once __DIR__ . '/projectLanguage.php';
    $dir = rtrim(str_replace('\\', '/', $projectPath), '/') . '/translate/';
    $files = [];
    if (qs_project_is_multilingual()) {
        foreach (qs_project_language_codes() as $lang) {
            $files[$lang] = is_file($dir . $lang . '.json') ? $dir . $lang . '.json' : $dir . 'default.json';
        }
    } else {
        $files['default'] = $dir . 'default.json';
    }
    $trees = [];
    foreach ($files as $lang => $file) {
        $tree = qs_translation_file_tree($file);
        if ($tree !== null) {
            $trees[$lang] = $tree;
        }
    }
    return $trees;
}

/** A project's word file as a tree: [] when absent, null when it is not a JSON object. */
function qs_translation_file_tree(string $file): ?array {
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($file), true);
    return is_array($decoded) ? $decoded : null;
}

} // end if (!function_exists('convertDotNotationToNested'))
