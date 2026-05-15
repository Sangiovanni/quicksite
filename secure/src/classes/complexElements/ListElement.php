<?php
/**
 * ListElementBuilder
 *
 * Emits a simple <ul> or <ol> with N <li> children, each <li> wrapping
 * a single translation key:
 *   <ul>
 *     <li><textKey></li>
 *     <li><textKey></li>
 *     ...
 *   </ul>
 *
 *   <ol start="N" reversed>          (start + reversed are <ol>-only)
 *     <li><textKey></li>
 *     ...
 *   </ol>
 *
 * Config:
 *   - tag       string, required — 'ul' | 'ol'.
 *   - items     array,  required — ≥ 1 entry, each shape: { labelKey: string }.
 *   - start     int,    optional — only honored when tag === 'ol'.
 *   - reversed  bool,   optional — only honored when tag === 'ol'.
 *
 * Notes:
 *   - File is named `ListElement.php` (not `List.php`) and the class is
 *     `ListElementBuilder` because `List` is a reserved word in modern
 *     PHP — declaring `class List` fails to parse. The `kind()` stays
 *     `'list'` so the JS wizard and the rest of the system don't care.
 *   - The MVP only emits per-item textKeys (no raw text, no nested
 *     lists, no <a> children inside <li>). Those land in a follow-up
 *     sprint if needed.
 *   - `start` and `reversed` are silently dropped on <ul> (browsers
 *     ignore them, and we don't want a stray `start="3"` attribute
 *     leaking into a <ul>). The wizard hides those fields when ul is
 *     picked, so this is defence-in-depth.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class ListElementBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'list';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- tag ----
        self::requireField($config, 'tag');
        $tag = (string)$config['tag'];
        if ($tag !== 'ul' && $tag !== 'ol') {
            throw new ComplexElementBuilderException(
                "Invalid list tag '$tag'. Must be 'ul' or 'ol'."
            );
        }

        // ---- items ----
        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "List must have at least one item"
            );
        }

        $liNodes = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException(
                    "Item at index $idx must be an object"
                );
            }
            $labelKey = isset($item['labelKey']) ? trim((string)$item['labelKey']) : '';
            if ($labelKey === '') {
                throw new ComplexElementBuilderException(
                    "Item at index $idx is missing 'labelKey'"
                );
            }
            $liNodes[] = [
                'tag' => 'li',
                'children' => [['textKey' => $labelKey]]
            ];
        }

        // ---- ol-only optional params ----
        $params = [];
        if ($tag === 'ol') {
            if (isset($config['start']) && $config['start'] !== '' && $config['start'] !== null) {
                if (!is_numeric($config['start'])) {
                    throw new ComplexElementBuilderException(
                        "'start' must be an integer when provided"
                    );
                }
                $params['start'] = (int)$config['start'];
            }
            if (!empty($config['reversed'])) {
                $params['reversed'] = true;
            }
        }

        $listNode = ['tag' => $tag, 'children' => $liNodes];
        if (!empty($params)) {
            $listNode['params'] = $params;
        }
        return $listNode;
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['items']) && is_array($config['items'])) {
            foreach ($config['items'] as $item) {
                if (is_array($item) && isset($item['labelKey']) && $item['labelKey'] !== '') {
                    $keys[] = (string)$item['labelKey'];
                }
            }
        }
        return $keys;
    }
}
