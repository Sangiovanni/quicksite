<?php
/**
 * NavMenuBuilder
 *
 * Emits a navigation menu — a <nav> wrapping a <ul> of N <li> entries,
 * each a single anchor:
 *
 *   <nav>
 *     <ul>
 *       <li><a href="..."><textKey/></a></li>
 *       <li><a href="..." target="_blank" rel="noopener"><textKey/></a></li>
 *       ...
 *     </ul>
 *   </nav>
 *
 * Each item has a label translation key and an href. Per-item "external"
 * toggle adds `target="_blank" rel="noopener"` (the noopener mitigates
 * the reverse-tabnabbing risk that bare target=_blank carries).
 *
 * Config:
 *   - items  array, required — ≥ 1, each
 *                                { labelKey: string,
 *                                  href:     string,
 *                                  external: bool (optional, default false) }
 *
 * Skipped for MVP:
 *   - aria-label on the <nav>. Add when needed (renderer auto-translates
 *     dot-shaped attribute strings, so a textKey works as the value).
 *   - Nested submenus.
 *   - Per-item icon.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class NavMenuBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'nav-menu';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "Nav menu must have at least one item"
            );
        }

        $liNodes = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException("Item at index $idx must be an object");
            }
            $labelKey = isset($item['labelKey']) ? trim((string)$item['labelKey']) : '';
            $href     = isset($item['href'])     ? trim((string)$item['href'])     : '';
            if ($labelKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'labelKey'");
            }
            if ($href === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'href'");
            }

            $aParams = ['href' => $href];
            if (!empty($item['external'])) {
                $aParams['target'] = '_blank';
                $aParams['rel']    = 'noopener';
            }

            $liNodes[] = [
                'tag' => 'li',
                'children' => [
                    [
                        'tag'    => 'a',
                        'params' => $aParams,
                        'children' => [['textKey' => $labelKey]]
                    ]
                ]
            ];
        }

        return [
            'tag' => 'nav',
            'children' => [
                [
                    'tag' => 'ul',
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
