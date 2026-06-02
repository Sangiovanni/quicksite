<?php
/**
 * listDataBindings Command
 *
 * Returns the QuickSite-runtime `data-*` attribute catalog — what each
 * attribute does, what value shape it expects, which attrs it pairs with,
 * etc. Consumed by the in-editor autocomplete in the Add Element wizard
 * (and other custom-params editors) so users can DISCOVER which data-*
 * attributes the runtime recognises instead of having to read the docs.
 *
 * The catalog itself lives in secure/src/functions/qsDataAttributeCatalog.php
 * — single source of truth (mirrors the qsVerbCatalog.php pattern shipped
 * beta.7 commit 142c277).
 *
 * @method GET
 * @url /management/listDataBindings           (user-facing entries only)
 * @url /management/listDataBindings/all       (also include editor-chrome
 *                                              entries — `internal: true`)
 * @auth required
 * @permission read
 *
 * @return ApiResponse
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/qsDataAttributeCatalog.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call.
 *
 * @param array $params    Body parameters (unused; this is a GET).
 * @param array $urlParams URL segments — [0] = 'all' to include internal
 *                         (editor-chrome) entries. Default: user-facing only.
 * @return ApiResponse
 */
function __command_listDataBindings(array $params = [], array $urlParams = []): ApiResponse {
    $includeInternal = isset($urlParams[0]) && $urlParams[0] === 'all';

    $entries = qsDataAttributeCatalog();

    if (!$includeInternal) {
        $entries = array_values(array_filter(
            $entries,
            fn($e) => empty($e['internal'])
        ));
    }

    // Group by category for the picker's optgroup-style dropdown layout.
    // Categories appear in the natural authoring order.
    $byCategory = [];
    foreach ($entries as $e) {
        $cat = $e['category'] ?? 'other';
        $byCategory[$cat] = $byCategory[$cat] ?? [];
        $byCategory[$cat][] = $e;
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(sprintf(
            '%d data-* attribute(s) in the catalog%s',
            count($entries),
            $includeInternal ? ' (incl. internal editor chrome)' : ''
        ))
        ->withData([
            'entries' => $entries,
            'by_category' => $byCategory,
            'count' => count($entries),
            'include_internal' => $includeInternal,
            'names' => array_column($entries, 'name'),
            'categories' => array_keys($byCategory),
            'syntax_hint' => 'Set on any element\'s param: <tag data-state-show="storeId.field">',
            'docs_anchor' => 'docs/ADMIN_PANEL.md (see per-entry docAnchor field for deep-dive section)',
        ]);
}

// Direct API call handler
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_listDataBindings($trimParams->params(), $trimParams->additionalParams())->send();
}
