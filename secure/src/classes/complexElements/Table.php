<?php
/**
 * TableBuilder
 *
 * Emits a <table> with an optional <caption>, an optional <thead> (one
 * <tr> of N <th>), and a <tbody> of M rows × N columns of <td>:
 *
 *   <table>
 *     <caption><textKey/></caption>?
 *     <thead>?
 *       <tr>
 *         <th><textKey/></th>
 *         <th><textKey/></th>
 *         ...
 *       </tr>
 *     </thead>?
 *     <tbody>
 *       <tr>
 *         <td><textKey/></td>
 *         ...
 *       </tr>
 *       ...
 *     </tbody>
 *   </table>
 *
 * Every cell value is a translation key. The wizard's CSV-paste shortcut
 * also stores values as keys (the CSV cells become the keys themselves —
 * the user can either pick existing keys per-cell, OR paste a CSV of
 * existing keys and adjust afterwards).
 *
 * Config:
 *   - id           string, optional  — HTML id of the <table>. Required
 *                                      for the cross-language CSV
 *                                      translation workflow (planned
 *                                      follow-up — see
 *                                      `NOTES/planning/BETA7_TABLE_TRANSLATION_CSV.md`).
 *                                      Format: letters, digits, hyphens,
 *                                      underscores; must start with a
 *                                      letter.
 *   - captionKey   string, optional  — textKey for <caption>. Omitted ⇒ no caption.
 *   - hasHead      bool,   default true.
 *   - colCount     int,    required  — ≥ 1. Used to validate row widths.
 *   - headerCells  array,  optional  — only when hasHead. Length must
 *                                      equal colCount. Each entry: string
 *                                      (a textKey; empty allowed for
 *                                      placeholder cells but the renderer
 *                                      will show an empty <th>).
 *   - bodyRows     array,  required  — ≥ 1 rows. Each row is an array
 *                                      of exactly colCount textKey strings.
 *
 * Skipped for MVP:
 *   - <tfoot> (rare; add later if asked).
 *   - colgroup / col widths.
 *   - per-cell colspan / rowspan.
 *   - per-row class hooks.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class TableBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'table';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- optional id ----------------------------------------------
        $tableId = isset($config['id']) && $config['id'] !== '' ? trim((string)$config['id']) : null;
        if ($tableId !== null) {
            self::validateHtmlId($tableId, 'id');
        }

        // ---- column count ---------------------------------------------
        self::requireField($config, 'colCount');
        $colCount = (int)$config['colCount'];
        if ($colCount < 1) {
            throw new ComplexElementBuilderException(
                "colCount must be at least 1 (got $colCount)"
            );
        }

        // ---- caption (optional) ---------------------------------------
        $captionKey = isset($config['captionKey']) && $config['captionKey'] !== ''
            ? trim((string)$config['captionKey'])
            : null;

        // ---- thead (optional) -----------------------------------------
        $hasHead = !array_key_exists('hasHead', $config) ? true : !empty($config['hasHead']);
        $headerCells = $config['headerCells'] ?? [];
        if ($hasHead) {
            if (!is_array($headerCells)) {
                throw new ComplexElementBuilderException("'headerCells' must be an array when hasHead is true");
            }
            if (count($headerCells) !== $colCount) {
                throw new ComplexElementBuilderException(
                    "headerCells length (" . count($headerCells) . ") must equal colCount ($colCount)"
                );
            }
        }

        // ---- tbody rows -----------------------------------------------
        self::requireField($config, 'bodyRows');
        $bodyRows = $config['bodyRows'];
        if (!is_array($bodyRows) || count($bodyRows) === 0) {
            throw new ComplexElementBuilderException("Table must have at least one body row");
        }
        foreach ($bodyRows as $rIdx => $row) {
            if (!is_array($row)) {
                throw new ComplexElementBuilderException("Body row $rIdx must be an array of cells");
            }
            if (count($row) !== $colCount) {
                throw new ComplexElementBuilderException(
                    "Body row $rIdx has " . count($row) . " cells; expected $colCount"
                );
            }
        }

        // ---- build children -------------------------------------------
        $tableChildren = [];

        if ($captionKey !== null) {
            $tableChildren[] = [
                'tag' => 'caption',
                'children' => [['textKey' => $captionKey]]
            ];
        }

        if ($hasHead) {
            $theadTr = ['tag' => 'tr', 'children' => []];
            foreach ($headerCells as $cellKey) {
                $cellKeyStr = trim((string)$cellKey);
                $cellChildren = $cellKeyStr === '' ? [] : [['textKey' => $cellKeyStr]];
                $theadTr['children'][] = [
                    'tag' => 'th',
                    'children' => $cellChildren
                ];
            }
            $tableChildren[] = [
                'tag' => 'thead',
                'children' => [$theadTr]
            ];
        }

        $tbodyRowNodes = [];
        foreach ($bodyRows as $row) {
            $tr = ['tag' => 'tr', 'children' => []];
            foreach ($row as $cellKey) {
                $cellKeyStr = trim((string)$cellKey);
                $cellChildren = $cellKeyStr === '' ? [] : [['textKey' => $cellKeyStr]];
                $tr['children'][] = [
                    'tag' => 'td',
                    'children' => $cellChildren
                ];
            }
            $tbodyRowNodes[] = $tr;
        }
        $tableChildren[] = [
            'tag' => 'tbody',
            'children' => $tbodyRowNodes
        ];

        $tableNode = [
            'tag' => 'table',
            'children' => $tableChildren
        ];
        if ($tableId !== null) {
            // Two data attrs that flag this table as a complex-element
            // subtree the editor + importStructureTranslations command can
            // recognise by id (no inverse-render-to-config — just the bare
            // minimum metadata to find this table later for cross-language
            // CSV translation, see BETA7_TABLE_TRANSLATION_CSV.md).
            // Forward-compatible: future complex elements opt in by
            // stamping the same `data-qs-complex` + `data-qs-complex-id`
            // pair with their own kind name.
            $tableNode['params'] = [
                'id' => $tableId,
                'data-qs-complex' => 'table',
                'data-qs-complex-id' => $tableId,
            ];
        }
        return $tableNode;
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['captionKey']) && $config['captionKey'] !== '') {
            $keys[] = (string)$config['captionKey'];
        }
        if (!empty($config['hasHead']) && isset($config['headerCells']) && is_array($config['headerCells'])) {
            foreach ($config['headerCells'] as $cell) {
                $s = trim((string)$cell);
                if ($s !== '') $keys[] = $s;
            }
        }
        if (isset($config['bodyRows']) && is_array($config['bodyRows'])) {
            foreach ($config['bodyRows'] as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $cell) {
                    $s = trim((string)$cell);
                    if ($s !== '') $keys[] = $s;
                }
            }
        }
        return $keys;
    }
}
