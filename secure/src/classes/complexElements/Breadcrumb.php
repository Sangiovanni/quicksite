<?php
/**
 * BreadcrumbBuilder
 *
 * Emits a semantic breadcrumb — <nav aria-label="Breadcrumb"> wrapping
 * an <ol> of N <li>. Every item except the LAST renders as an anchor;
 * the last item renders as plain text with `aria-current="page"` to
 * indicate the current location:
 *
 *   <nav aria-label="Breadcrumb">
 *     <ol class="breadcrumb">
 *       <li><a href="/"><textKey/></a></li>
 *       <li><a href="/docs"><textKey/></a></li>
 *       <li aria-current="page"><textKey/></li>
 *     </ol>
 *   </nav>
 *
 * The last item's `href` is intentionally ignored (the user often
 * leaves it blank for the current-page entry; if they provided one,
 * we still drop it so the rendered HTML stays current-page-correct).
 * No separator nodes are emitted — separators belong in CSS
 * (`.breadcrumb li + li::before { content: "/"; }`).
 *
 * Config:
 *   - items  array, required — ≥ 1, each { labelKey: string, href: string }.
 *                              The last item's href is allowed to be empty.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class BreadcrumbBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'breadcrumb';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "Breadcrumb must have at least one item"
            );
        }

        $itemsList = array_values($items);
        $lastIdx = count($itemsList) - 1;
        $liNodes = [];

        foreach ($itemsList as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException("Item at index $idx must be an object");
            }
            $labelKey = isset($item['labelKey']) ? trim((string)$item['labelKey']) : '';
            if ($labelKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'labelKey'");
            }

            if ($idx === $lastIdx) {
                // Current-page entry: plain <li> + aria-current="page".
                // Any href the user typed is intentionally ignored — a
                // breadcrumb's tail is by definition the current location.
                $liNodes[] = [
                    'tag'    => 'li',
                    'params' => ['aria-current' => 'page'],
                    'children' => [['textKey' => $labelKey]]
                ];
            } else {
                // Intermediate entry: required href + anchor.
                $href = isset($item['href']) ? trim((string)$item['href']) : '';
                if ($href === '') {
                    throw new ComplexElementBuilderException(
                        "Item at index $idx is missing 'href' (only the LAST item may omit it)"
                    );
                }
                $liNodes[] = [
                    'tag' => 'li',
                    'children' => [
                        [
                            'tag'    => 'a',
                            'params' => ['href' => $href],
                            'children' => [['textKey' => $labelKey]]
                        ]
                    ]
                ];
            }
        }

        return [
            'tag'    => 'nav',
            'params' => ['aria-label' => 'Breadcrumb'],
            'children' => [
                [
                    'tag'    => 'ol',
                    'params' => ['class' => 'breadcrumb'],
                    'children' => $liNodes
                ]
            ]
        ];
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
