<?php
/**
 * utilsStyleManagement.php
 * 
 * Shared helpers for CSS file path resolution, locking, and dual-write
 * operations (live stylesheet + project backup copy).
 * 
 * All CSS write commands should use these helpers to guarantee both the live
 * stylesheet and the project backup copy stay in sync.
 */

/**
 * Maximum bytes any CSS write may land on disk, and the largest stylesheet the
 * CssParser will process. The two are ONE number on purpose: every writer must
 * produce a file the parser can then read back.
 *
 * Sized to the parser's real capacity, not to taste. CssParser peaks at ~140-210x
 * the input in memory (block-tree + substr copies), so at the install's 128 MB
 * limit the 8.0.30 FLOOR fatals around 0.9 MB. 512 KB parses at ~77 MB peak on the
 * floor (≈40% headroom) and is still 12x the largest real stylesheet on this
 * install (quicksite, 40 KB). The prior 2 MB write cap was ~2.5x beyond what the
 * floor survives — a single oversized write bricked every CssParser-using command
 * for that project (F-C13-6).
 */
const CSS_MAX_BYTES = 512 * 1024;

/**
 * F-C13-4 confinement. `{` and `}` are the only characters that open or close a
 * CSS block, so a selector / media prelude / variable name / declaration that
 * carries either can break out of its rule and emit arbitrary CSS. No legitimate
 * value in any of those positions contains a brace — the `>` child combinator,
 * quotes, `[attr="x"]` selectors, `var()`, `calc()` are all fine and pass. This is
 * the CSS-structural guard only; HTML metacharacters are handled at the render
 * boundary (F-C13-3), not here, because CSS values legitimately contain quotes.
 *
 * @param string $fragment A single CSS input (selector, media prelude, variable
 *                         name/value, or declaration block) — never a whole sheet.
 * @return bool true if safe to emit, false if it must be refused.
 */
function qs_css_confine(string $fragment): bool {
    return strpos($fragment, '{') === false && strpos($fragment, '}') === false;
}

/**
 * Normalise CSS for a SECURITY SCAN only (never for writing). A byte-level denylist
 * is trivially bypassed by the two things CSS lets you write without changing meaning:
 *   - comments between tokens:   `behavior/**​/:`  ->  `behavior:`
 *   - escapes inside identifiers: `b\65 havior`    ->  `behavior`
 * Stripping comments and decoding `\XX` / `\c` escapes first makes the denylist see
 * what the browser will actually parse. The decoded copy is used ONLY to run the
 * patterns; the original bytes are what gets stored. (F-C13-3 / F5 denylist.)
 *
 * @param string $css Raw CSS to scan.
 * @return string A comment-stripped, escape-decoded copy for pattern matching.
 */
function qs_css_normalize_for_scan(string $css): string {
    // 1. Drop /* ... */ comments.
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;

    // 2. Decode CSS escapes: `\` + 1-6 hex (with an optional single trailing space),
    //    or `\` + any single char. The dangerous keywords are all ASCII, so ASCII is
    //    what must decode faithfully; other code points collapse to a placeholder that
    //    still breaks any keyword they were hiding inside.
    return preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})[ \t\n\r\f]?|\\\\(.)/s', static function ($m) {
        if (isset($m[1]) && $m[1] !== '') {
            $cp = hexdec($m[1]);
            if ($cp === 0) return '';
            if ($cp >= 0x20 && $cp <= 0x7E) return chr($cp);
            if (function_exists('mb_chr')) {
                $ch = mb_chr($cp, 'UTF-8');
                return $ch === false ? "\u{FFFD}" : $ch;
            }
            return "\u{FFFD}";
        }
        return $m[2] ?? '';
    }, $css) ?? $css;
}

/**
 * Returns the path to the live stylesheet for the current project.
 * This is the file actively served to site visitors.
 */
function cssLivePath(): string {
    return PUBLIC_CONTENT_PATH . '/style/style.css';
}

/**
 * Returns the path to the project backup stylesheet for the current project.
 * This copy mirrors the live file and is used during builds and deployments.
 */
function cssProjectPath(): string {
    return PROJECT_PATH . '/public/style/style.css';
}

/**
 * Acquires an exclusive file lock for CSS write operations.
 * The lock is keyed on the live stylesheet path.
 * 
 * @param string $styleFile Path to the live stylesheet (used to derive the lock key).
 * @return resource|null File handle with lock held, or null if the lock could not be acquired.
 */
function cssAcquireLock(string $styleFile) {
    $lockFile = sys_get_temp_dir() . '/quicksite_style_' . md5($styleFile) . '.lock';
    $lock = fopen($lockFile, 'w');
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return null;
    }
    return $lock;
}

/**
 * Releases and closes a CSS write lock previously acquired by cssAcquireLock().
 * 
 * @param resource $lock File handle returned by cssAcquireLock().
 */
function cssReleaseLock($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * Writes CSS content to the live stylesheet and the project backup copy.
 * 
 * If both paths resolve to the same file, the content is written only once.
 * Ensures the project backup directory exists before writing.
 * The caller must hold the lock (via cssAcquireLock) before calling this.
 * 
 * @param string $content    Updated CSS content to write.
 * @param string $livePath   Path to the live stylesheet.
 * @param string $projectPath Path to the project backup stylesheet.
 * @throws Exception If a directory cannot be created or a write fails.
 */
function cssWriteAllTargets(string $content, string $livePath, string $projectPath): void {
    // F-C13-6 — the single enforcement point for the write cap. Every rule-level
    // writer (setStyleRule / setRootVariables / setKeyframes / delete*) reaches disk
    // through here, so capping here caps all of them at a size the CssParser can
    // read back. editStyles and injectSnippetCss write directly for their own
    // backup/response reasons and check CSS_MAX_BYTES at their own boundary.
    if (strlen($content) > CSS_MAX_BYTES) {
        throw new Exception('Stylesheet exceeds the maximum size ('
            . (int) round(CSS_MAX_BYTES / 1024) . ' KB)');
    }

    if (file_put_contents($livePath, $content) === false) {
        throw new Exception('Failed to write live style file');
    }

    if ($projectPath !== $livePath) {
        $projectDir = dirname($projectPath);
        if (!is_dir($projectDir) && !mkdir($projectDir, 0755, true)) {
            throw new Exception('Failed to create project style directory');
        }
        if (file_put_contents($projectPath, $content) === false) {
            throw new Exception('Failed to write project style file');
        }
    }
}

/**
 * Extract every CSS class, ID and tag named by a JSON structure, recursively.
 *
 * Walks a page / menu / footer / component / snippet subtree, resolving
 * `component` references against the project's component folder so a component's
 * own selectors count too, and returns the three selector sets a CssParser query
 * needs.
 *
 * Shared: `injectSnippetCss` reaches it through `extractSnippetCss()` in
 * SnippetManagement.php, which `createSnippet` and `duplicateSnippet` call.
 *
 * Requires `componentPolicy.php` (qs_resolve_component_path) and the
 * TEMPLATES_JSON_PATH constant to be in scope.
 *
 * @param array $structure  Array of nodes.
 * @param array $components Component cache, filled as references are resolved.
 * @return array ['classes' => [...], 'ids' => [...], 'tags' => [...]]
 */
function extractCssSelectorsFromStructure(array $structure, array &$components = []): array {
    $classes = [];
    $ids = [];
    $tags = [];

    foreach ($structure as $node) {
        // Handle component references
        if (isset($node['component'])) {
            $componentName = $node['component'];

            // Load component if not already loaded
            if (!isset($components[$componentName])) {
                // beta.11 S3.10c: stored reference, jailed by the shared resolver.
                $componentPath = qs_resolve_component_path($componentName, TEMPLATES_JSON_PATH . '/components');
                if ($componentPath !== null) {
                    $componentContent = @file_get_contents($componentPath);
                    if ($componentContent !== false) {
                        $componentData = json_decode($componentContent, true);
                        if (is_array($componentData)) {
                            $components[$componentName] = $componentData;
                        }
                    }
                }
            }

            // Recursively extract from component
            if (isset($components[$componentName])) {
                $componentSelectors = extractCssSelectorsFromStructure(
                    is_array($components[$componentName][0] ?? null) ? $components[$componentName] : [$components[$componentName]],
                    $components
                );
                $classes = array_merge($classes, $componentSelectors['classes']);
                $ids = array_merge($ids, $componentSelectors['ids']);
                $tags = array_merge($tags, $componentSelectors['tags']);
            }

            // Also extract from data params (component might have class in data)
            if (isset($node['data']) && is_array($node['data'])) {
                if (isset($node['data']['class'])) {
                    $nodeClasses = preg_split('/\s+/', trim($node['data']['class']));
                    $classes = array_merge($classes, $nodeClasses);
                }
                if (isset($node['data']['id'])) {
                    $ids[] = $node['data']['id'];
                }
            }

            continue;
        }

        // Handle regular tags
        if (isset($node['tag'])) {
            $tags[] = $node['tag'];

            // Extract params
            if (isset($node['params']) && is_array($node['params'])) {
                if (isset($node['params']['class'])) {
                    $nodeClasses = preg_split('/\s+/', trim($node['params']['class']));
                    $classes = array_merge($classes, $nodeClasses);
                }
                if (isset($node['params']['id'])) {
                    $ids[] = $node['params']['id'];
                }
            }
        }

        // Recurse into children
        if (isset($node['children']) && is_array($node['children'])) {
            $childSelectors = extractCssSelectorsFromStructure($node['children'], $components);
            $classes = array_merge($classes, $childSelectors['classes']);
            $ids = array_merge($ids, $childSelectors['ids']);
            $tags = array_merge($tags, $childSelectors['tags']);
        }
    }

    return [
        'classes' => array_unique(array_filter($classes)),
        'ids' => array_unique(array_filter($ids)),
        'tags' => array_unique(array_filter($tags))
    ];
}
