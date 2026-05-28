<?php
/**
 * PagedNavigatorBuilder
 *
 * Emits a numbered-page navigator bound to a state store — a `<nav>`
 * with no inner content at build time; the runtime
 * (`[data-state-pagenav]` binding in `public/scripts/qs.js`) populates
 * buttons whenever the bound store updates:
 *
 *   <nav class="paged-nav" [id="<navId>"]
 *        data-qs-complex="paged-navigator"
 *        [data-qs-complex-id="<navId>"]
 *        data-state-pagenav="<storeId>"
 *        data-state-pagenav-page-field="<pageField>"
 *        data-state-pagenav-totalpages-field="<totalPagesField>"
 *        data-state-pagenav-window="<N>"
 *        data-state-pagenav-prev-next="true|false"></nav>
 *
 * Why runtime-rendered (unique among complex elements): the total page
 * count is only known AFTER the first API fetch — `totalPages` is a
 * response field on the store. Baking buttons at build time isn't an
 * option. The pattern mirrors `data-state-list` / `data-state-value`
 * which also re-render on every store update.
 *
 * Pair this navigator with an offset-pagination store (see
 * `docs/ADMIN_PANEL.md §9.6`):
 *   - `page`       (request)  — what the navigator writes via setState
 *   - `totalPages` (response) — what the navigator reads to size itself
 *   - `items`      (response, replace mode) — your data list
 *
 * Config:
 *   - storeId          string, required — must match a state store
 *                                          defined on the page.
 *   - id               string, optional — HTML id of the <nav>.
 *                                          When provided, also stamped
 *                                          as `data-qs-complex-id` for
 *                                          future tooling consistency.
 *   - pageField        string, optional — store field the buttons WRITE
 *                                          on click. Default: 'page'.
 *   - totalPagesField  string, optional — store field the navigator
 *                                          READS to size itself.
 *                                          Default: 'totalPages'.
 *   - window           int,    optional — number of siblings each side
 *                                          of the current page (smart-
 *                                          ellipsis layout). Default 2.
 *   - includePrevNext  bool,   optional — emit ‹ Prev / Next › flanking
 *                                          chevrons. Default true.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class PagedNavigatorBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'paged-navigator';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- storeId (required) --------------------------------------
        self::requireField($config, 'storeId');
        $storeId = (string)$config['storeId'];
        if (!preg_match('/^[a-zA-Z][\w-]*$/', $storeId)) {
            throw new ComplexElementBuilderException(
                "Invalid storeId '$storeId' — must start with a letter; use letters, digits, hyphens, underscores."
            );
        }

        // ---- optional fields -----------------------------------------
        $pageField = isset($config['pageField']) && $config['pageField'] !== ''
            ? (string)$config['pageField'] : 'page';
        if (!preg_match('/^[a-zA-Z_][\w]*$/', $pageField)) {
            throw new ComplexElementBuilderException(
                "Invalid pageField '$pageField' — must be a valid JSON property name (letters/digits/_)."
            );
        }

        $totalPagesField = isset($config['totalPagesField']) && $config['totalPagesField'] !== ''
            ? (string)$config['totalPagesField'] : 'totalPages';
        if (!preg_match('/^[a-zA-Z_][\w]*$/', $totalPagesField)) {
            throw new ComplexElementBuilderException(
                "Invalid totalPagesField '$totalPagesField' — must be a valid JSON property name."
            );
        }

        $window = isset($config['window']) ? (int)$config['window'] : 2;
        if ($window < 0 || $window > 10) {
            throw new ComplexElementBuilderException(
                "window must be between 0 and 10 (got $window)."
            );
        }

        $includePrevNext = array_key_exists('includePrevNext', $config)
            ? !empty($config['includePrevNext']) : true;

        $navId = isset($config['id']) && $config['id'] !== '' ? trim((string)$config['id']) : null;
        if ($navId !== null) {
            self::validateHtmlId($navId, 'id');
        }

        // ---- build params --------------------------------------------
        $params = [
            'class'                              => 'paged-nav',
            'data-qs-complex'                    => 'paged-navigator',
            'data-state-pagenav'                 => $storeId,
            'data-state-pagenav-page-field'      => $pageField,
            'data-state-pagenav-totalpages-field'=> $totalPagesField,
            'data-state-pagenav-window'          => (string)$window,
            // Stored as a string so the runtime's getAttribute matches
            // its truthy check exactly ('true' vs missing/'false').
            'data-state-pagenav-prev-next'       => $includePrevNext ? 'true' : 'false',
        ];
        if ($navId !== null) {
            $params['id'] = $navId;
            $params['data-qs-complex-id'] = $navId;
        }

        // No children at build time — runtime populates buttons on
        // every store update via [data-state-pagenav] binding.
        return [
            'tag'      => 'nav',
            'params'   => $params,
            'children' => [],
        ];
    }

    public function declaredTextKeys(array $config): array {
        // No translatable strings — Prev/Next labels are emitted as
        // raw chevrons by the runtime (purely visual). If we later
        // want translatable labels we'd add prevLabelKey / nextLabelKey
        // config + read them in the runtime via store-bound attrs.
        return [];
    }
}
