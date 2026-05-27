<?php
/**
 * AccordionBuilder
 *
 * Emits a FAQ-style accordion using native HTML disclosure widgets —
 * no JS, no ARIA wiring needed (the browser handles toggling):
 *
 *   <div class="accordion">
 *     <details [open]>
 *       <summary><textKey/></summary>
 *       <p><textKey/></p>
 *     </details>
 *     <details>
 *       <summary><textKey/></summary>
 *       <p><textKey/></p>
 *     </details>
 *     ...
 *   </div>
 *
 * The outer <div class="accordion"> wrapper exists for CSS styling
 * hooks (vertical gap between items, etc.). The content inside each
 * <details> is intentionally simple — one <p> per item. For richer
 * content (links, lists, multiple paragraphs), the user can edit the
 * emitted <p> using the regular visual-editor tools after save.
 *
 * Config:
 *   - items  array, required — ≥ 1, each
 *                                { summaryKey:    string,
 *                                  contentKey:    string,
 *                                  openByDefault: bool (optional, default false) }
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class AccordionBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'accordion';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "Accordion must have at least one item"
            );
        }

        $detailsNodes = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException("Item at index $idx must be an object");
            }
            $summaryKey = isset($item['summaryKey']) ? trim((string)$item['summaryKey']) : '';
            $contentKey = isset($item['contentKey']) ? trim((string)$item['contentKey']) : '';
            if ($summaryKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'summaryKey'");
            }
            if ($contentKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'contentKey'");
            }

            $detailsParams = [];
            if (!empty($item['openByDefault'])) {
                $detailsParams['open'] = true;
            }

            $detailsNode = [
                'tag' => 'details',
                'children' => [
                    [
                        'tag' => 'summary',
                        'children' => [['textKey' => $summaryKey]]
                    ],
                    [
                        'tag' => 'p',
                        'children' => [['textKey' => $contentKey]]
                    ]
                ]
            ];
            if (!empty($detailsParams)) {
                $detailsNode['params'] = $detailsParams;
            }
            $detailsNodes[] = $detailsNode;
        }

        return [
            'tag' => 'div',
            'params' => ['class' => 'accordion'],
            'children' => $detailsNodes
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['items']) && is_array($config['items'])) {
            foreach ($config['items'] as $item) {
                if (!is_array($item)) continue;
                if (isset($item['summaryKey']) && $item['summaryKey'] !== '') $keys[] = (string)$item['summaryKey'];
                if (isset($item['contentKey']) && $item['contentKey'] !== '') $keys[] = (string)$item['contentKey'];
            }
        }
        return $keys;
    }
}
