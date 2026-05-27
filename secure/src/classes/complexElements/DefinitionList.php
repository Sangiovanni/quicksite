<?php
/**
 * DefinitionListBuilder
 *
 * Emits a <dl> with N (term, description) pairs:
 *
 *   <dl>
 *     <dt><textKey/></dt>
 *     <dd><textKey/></dd>
 *     <dt><textKey/></dt>
 *     <dd><textKey/></dd>
 *     ...
 *   </dl>
 *
 * MVP scope: 1:1 (one <dt> per <dd>). The full HTML spec allows
 * 1-to-many in either direction (multiple <dt> sharing one <dd>, or
 * multiple <dd> under one <dt>); add when users ask.
 *
 * Config:
 *   - items  array, required — ≥ 1, each { termKey: string, descKey: string }.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class DefinitionListBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'definition-list';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "Definition list must have at least one item"
            );
        }

        $children = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException("Item at index $idx must be an object");
            }
            $termKey = isset($item['termKey']) ? trim((string)$item['termKey']) : '';
            $descKey = isset($item['descKey']) ? trim((string)$item['descKey']) : '';
            if ($termKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'termKey'");
            }
            if ($descKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'descKey'");
            }
            $children[] = [
                'tag' => 'dt',
                'children' => [['textKey' => $termKey]]
            ];
            $children[] = [
                'tag' => 'dd',
                'children' => [['textKey' => $descKey]]
            ];
        }

        return [
            'tag' => 'dl',
            'children' => $children
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['items']) && is_array($config['items'])) {
            foreach ($config['items'] as $item) {
                if (!is_array($item)) continue;
                if (isset($item['termKey']) && $item['termKey'] !== '') $keys[] = (string)$item['termKey'];
                if (isset($item['descKey']) && $item['descKey'] !== '') $keys[] = (string)$item['descKey'];
            }
        }
        return $keys;
    }
}
