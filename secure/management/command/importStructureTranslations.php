<?php
/**
 * importStructureTranslations - Bulk-write translation values for a
 * complex-element subtree (currently: a Table) by pasting a CSV-shaped
 * grid in another language.
 *
 * Lets the user translate an existing table without touching its JSON
 * structure: paste a French CSV against the table id "q1Sales" and we
 * write French values for every `table.q1Sales.head.<col>` /
 * `table.q1Sales.body.<r>.<c>` key that the table already references.
 *
 * Counterpart to the Table wizard's Translatable paste mode (which
 * CREATES the structure + writes the FIRST language's values). This
 * command is for adding additional languages later.
 *
 * Validates that the target structure exists on the named page AND
 * that the pasted grid's dimensions match exactly. On mismatch we
 * refuse the whole write (no partials) and return a 422 with a clear
 * expected-vs-got diff so the UI can show it.
 *
 * Currently only `kind: 'table'` is supported. Future complex elements
 * (lists, accordions) can extend the kind switch in _scanForStructure
 * and the key-generation block.
 *
 * @method POST
 * @url /management/importStructureTranslations
 * @auth required
 * @permission editStructure
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * Walk a JSON structure tree and find the first node matching a kind
 * + structureId via the `data-qs-complex` / `data-qs-complex-id` data
 * attrs stamped by complex-element builders.
 *
 * @param array $structure Parsed page JSON (array of top-level nodes).
 * @param string $kind Expected `data-qs-complex` value, e.g. 'table'.
 * @param string $structureId Expected `data-qs-complex-id` value.
 * @return array|null The matching node, or null if not found.
 */
function _scanForComplexStructure(array $structure, string $kind, string $structureId): ?array {
    foreach ($structure as $node) {
        if (!is_array($node)) continue;
        // Match: tag + data-qs-complex + data-qs-complex-id
        $params = $node['params'] ?? [];
        if (is_array($params)
            && (($params['data-qs-complex'] ?? null) === $kind)
            && (($params['data-qs-complex-id'] ?? null) === $structureId)
        ) {
            return $node;
        }
        // Recurse into children.
        $children = $node['children'] ?? null;
        if (is_array($children)) {
            $found = _scanForComplexStructure($children, $kind, $structureId);
            if ($found !== null) return $found;
        }
    }
    return null;
}

/**
 * Inspect a table node's emitted shape and return its dimensions:
 *   [ 'hasHead' => bool, 'headerCols' => int, 'bodyRows' => int, 'bodyCols' => int ]
 *
 * The col count is taken from the FIRST body row when one exists; falls
 * back to the header row when there's no body. Returns null when the
 * node doesn't look like a Table-builder-emitted table.
 */
function _measureTableDimensions(array $tableNode): ?array {
    $children = $tableNode['children'] ?? [];
    if (!is_array($children)) return null;

    $hasHead = false;
    $headerCols = 0;
    $bodyRows = 0;
    $bodyCols = 0;

    foreach ($children as $section) {
        if (!is_array($section)) continue;
        $tag = $section['tag'] ?? '';
        if ($tag === 'thead') {
            $hasHead = true;
            $tr = $section['children'][0] ?? null;
            if (is_array($tr) && is_array($tr['children'] ?? null)) {
                $headerCols = count($tr['children']);
            }
        } elseif ($tag === 'tbody') {
            $rows = $section['children'] ?? [];
            if (is_array($rows)) {
                $bodyRows = count($rows);
                $firstTr = $rows[0] ?? null;
                if (is_array($firstTr) && is_array($firstTr['children'] ?? null)) {
                    $bodyCols = count($firstTr['children']);
                }
            }
        }
        // <caption> ignored — doesn't affect dimensions.
    }

    return [
        'hasHead'    => $hasHead,
        'headerCols' => $headerCols,
        'bodyRows'   => $bodyRows,
        'bodyCols'   => $bodyCols,
    ];
}

/**
 * Convert a flat `{dot.path: value}` map into the nested structure the
 * translation files use. Replicates setTranslationKeys.php's
 * convertDotNotationToNested — extracted here so we don't pull in
 * setTranslationKeys.php (whose logic runs at top-level on include).
 * BACKLOG: factor both into `src/functions/translationHelpers.php`.
 */
function _flatToNested(array $flat): array {
    $nested = [];
    foreach ($flat as $dotKey => $value) {
        if (strpos($dotKey, '.') === false) {
            $nested[$dotKey] = $value;
            continue;
        }
        $parts = explode('.', $dotKey);
        $cursor = &$nested;
        $lastIdx = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $lastIdx) {
                $cursor[$part] = $value;
            } else {
                if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                    $cursor[$part] = [];
                }
                $cursor = &$cursor[$part];
            }
        }
        unset($cursor);
    }
    return $nested;
}

/**
 * Deep-merge $new INTO $existing (mutating semantics, returns the
 * merged tree). Same shape as setTranslationKeys.php's mergeTranslations:
 * when both sides have the same key and both values are arrays, recurse;
 * otherwise $new's value wins.
 */
function _deepMergeTranslations(array $existing, array $new): array {
    foreach ($new as $k => $v) {
        if (is_array($v) && isset($existing[$k]) && is_array($existing[$k])) {
            $existing[$k] = _deepMergeTranslations($existing[$k], $v);
        } else {
            $existing[$k] = $v;
        }
    }
    return $existing;
}

/**
 * @param array $params Body: route, kind, structureId, language, header[], rows[][]
 * @param array $urlParams (unused)
 * @return ApiResponse
 */
function __command_importStructureTranslations(array $params = [], array $urlParams = []): ApiResponse {
    // ---- validation ------------------------------------------------------
    $required = ['route', 'kind', 'structureId', 'language', 'rows'];
    $missing = [];
    foreach ($required as $f) {
        if (!isset($params[$f]) || ($params[$f] === '' && $params[$f] !== 0)) {
            $missing[] = $f;
        }
    }
    if (!empty($missing)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required parameters: ' . implode(', ', $missing))
            ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing));
    }

    $route = (string)$params['route'];
    $kind = (string)$params['kind'];
    $structureId = (string)$params['structureId'];
    $language = (string)$params['language'];
    $header = $params['header'] ?? [];
    $rows = $params['rows'];

    // Only 'table' supported today; the design (BETA7_TABLE_TRANSLATION_CSV.md)
    // anticipates expanding to list / accordion / etc. as more kinds become
    // CSV-shaped. Reject unknown kinds now so a future addition forces an
    // explicit code change.
    if ($kind !== 'table') {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Unsupported kind '$kind'. Currently only 'table' is supported.")
            ->withErrors([['field' => 'kind', 'value' => $kind, 'allowed' => ['table']]]);
    }

    // structureId must match the HTML-id format we stamp on Table builds.
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $structureId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid structureId '$structureId' — must start with a letter and use letters, digits, hyphens, underscores.")
            ->withErrors([['field' => 'structureId', 'value' => $structureId]]);
    }

    if (!is_array($header)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("'header' must be an array (empty when the table has no <thead>).")
            ->withErrors([['field' => 'header', 'reason' => 'must be array']]);
    }
    if (!is_array($rows) || count($rows) === 0) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("'rows' must be a non-empty array.")
            ->withErrors([['field' => 'rows', 'reason' => 'must be non-empty array']]);
    }
    foreach ($rows as $rIdx => $row) {
        if (!is_array($row)) {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("Row $rIdx must be an array of cell strings.")
                ->withErrors([['field' => "rows[$rIdx]", 'reason' => 'must be array']]);
        }
    }

    // Language must be configured.
    $configuredLangs = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']))
        ? CONFIG['LANGUAGES_SUPPORTED'] : ['en'];
    if (!in_array($language, $configuredLangs, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Language '$language' is not in this project's configured languages.")
            ->withErrors([['field' => 'language', 'value' => $language, 'allowed' => $configuredLangs]]);
    }

    // ---- locate the page + structure -------------------------------------
    $jsonFile = resolvePageJsonPath($route);
    if ($jsonFile === null) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("Page '$route' does not exist (no JSON file found).");
    }
    $jsonContent = @file_get_contents($jsonFile);
    if ($jsonContent === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read page JSON file.');
    }
    $structure = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($structure)) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Page JSON file is malformed: ' . json_last_error_msg());
    }

    $tableNode = _scanForComplexStructure($structure, $kind, $structureId);
    if ($tableNode === null) {
        return ApiResponse::create(404, 'structure.not_found')
            ->withMessage("No <table data-qs-complex='table' data-qs-complex-id='$structureId'> found on page '$route'. The table may have been created before this concern shipped — use the 'twin + delete' workaround documented in BETA7_TABLE_TRANSLATION_CSV.md, or re-create with the Table wizard so the markers get stamped.");
    }

    // ---- validate dimensions ---------------------------------------------
    $dims = _measureTableDimensions($tableNode);
    if ($dims === null) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Could not measure dimensions of <table data-qs-complex-id='$structureId'>.");
    }

    $pastedHeader = count($header);
    $pastedBody = count($rows);
    $pastedCols = 0;
    foreach ($rows as $r) {
        $w = count($r);
        if ($w > $pastedCols) $pastedCols = $w;
    }
    // Also consider the header's width when computing pasted cols.
    if ($pastedHeader > $pastedCols) $pastedCols = $pastedHeader;

    $expectedHeader = $dims['hasHead'] ? $dims['headerCols'] : 0;
    $expectedBody = $dims['bodyRows'];
    $expectedCols = max($dims['headerCols'], $dims['bodyCols']);

    // Hard checks: header presence/count + body row count.
    // The PER-ROW column count is NOT a hard check — the write loop
    // below safely pads short rows with empty strings and truncates
    // long rows beyond $expectedCols. The frontend live-validation
    // surfaces per-row variance as a warning so the user is aware
    // before they click Apply, but the backend accepts and writes
    // the deterministic result. Keeping these checks symmetric.
    $diffs = [];
    if ($pastedHeader !== $expectedHeader) {
        $diffs[] = "header columns: expected $expectedHeader, got $pastedHeader"
            . (!$dims['hasHead'] ? ' (table has no <thead> — omit the header row)' : '');
    }
    if ($pastedBody !== $expectedBody) {
        $diffs[] = "body rows: expected $expectedBody, got $pastedBody";
    }
    if (!empty($diffs)) {
        return ApiResponse::create(422, 'validation.dimension_mismatch')
            ->withMessage('Pasted grid dimensions do not match the existing table. ' . implode('; ', $diffs) . '.')
            ->withData([
                'expected' => [
                    'hasHead'    => $dims['hasHead'],
                    'headerCols' => $expectedHeader,
                    'bodyRows'   => $expectedBody,
                    'cols'       => $expectedCols,
                ],
                'got' => [
                    'headerCols' => $pastedHeader,
                    'bodyRows'   => $pastedBody,
                    'cols'       => $pastedCols,
                ],
                'diffs' => $diffs,
            ]);
    }

    // ---- build the flat translation map ----------------------------------
    // Empty cells per the planning: write the empty string (matches the
    // "no translation yet" state). Don't skip — the key MUST exist after
    // this write or the renderer will fall back to the key text.
    $flat = [];
    if ($dims['hasHead']) {
        foreach ($header as $c => $val) {
            $key = "table.$structureId.head.$c";
            $flat[$key] = (string)$val;
        }
    }
    foreach ($rows as $r => $row) {
        // Pad short rows with empty cells (keeps the key namespace
        // rectangular even if the user pasted a slightly-jagged grid).
        for ($c = 0; $c < $expectedCols; $c++) {
            $flat["table.$structureId.body.$r.$c"] = (string)($row[$c] ?? '');
        }
    }

    if (empty($flat)) {
        // Should be unreachable given the validation above, but defensive.
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Nothing to write — empty grid.');
    }

    // ---- write to the language file --------------------------------------
    $newNested = _flatToNested($flat);

    $translationsFile = PROJECT_PATH . '/translate/' . $language . '.json';
    $existingTranslations = [];
    if (file_exists($translationsFile)) {
        $existingJson = @file_get_contents($translationsFile);
        if ($existingJson !== false) {
            $decoded = json_decode($existingJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingTranslations = $decoded;
            }
        }
    }

    $merged = _deepMergeTranslations($existingTranslations, $newNested);

    $output = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($output === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Failed to encode merged translations JSON.');
    }

    // Ensure translate directory exists.
    $translateDir = dirname($translationsFile);
    if (!is_dir($translateDir)) {
        @mkdir($translateDir, 0755, true);
    }

    if (file_put_contents($translationsFile, $output, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save translations file.');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Translations imported for "' . $structureId . '" (' . $language . ').')
        ->withData([
            'route'       => $route,
            'kind'        => $kind,
            'structureId' => $structureId,
            'language'    => $language,
            'keysWritten' => count($flat),
            'dimensions'  => [
                'hasHead'    => $dims['hasHead'],
                'headerCols' => $expectedHeader,
                'bodyRows'   => $expectedBody,
                'cols'       => $expectedCols,
            ],
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_importStructureTranslations($trimParams->params(), $trimParams->additionalParams())->send();
}
