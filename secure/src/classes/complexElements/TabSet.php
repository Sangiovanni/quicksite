<?php
/**
 * TabSetBuilder
 *
 * Emits an ARIA-correct tab set with native click-to-switch behaviour
 * wired through existing QS.* verbs — no new runtime support needed:
 *
 *   <div class="tabset" id="<setId>">
 *     <div role="tablist" class="tabset__list">
 *       <button type="button" role="tab"
 *               id="<setId>-tab-0"
 *               aria-controls="<setId>-panel-0"
 *               aria-selected="true"
 *               class="tabset__tab tab--active"
 *               onclick="{{call chain — see below}}">
 *         <textKey/>
 *       </button>
 *       <button type="button" role="tab"
 *               id="<setId>-tab-1"
 *               aria-controls="<setId>-panel-1"
 *               aria-selected="false"
 *               tabindex="-1"
 *               class="tabset__tab"
 *               onclick="...">
 *         <textKey/>
 *       </button>
 *       ...
 *     </div>
 *     <div role="tabpanel"
 *          id="<setId>-panel-0"
 *          aria-labelledby="<setId>-tab-0"
 *          class="tabset__panel">
 *       <p><textKey/></p>
 *     </div>
 *     <div role="tabpanel"
 *          id="<setId>-panel-1"
 *          aria-labelledby="<setId>-tab-1"
 *          class="tabset__panel hidden">
 *       <p><textKey/></p>
 *     </div>
 *     ...
 *   </div>
 *
 * Each tab button's onclick is a 4-link chain scoped to THIS tabset
 * (via `#<setId>` as the descendant root), so multiple tab sets on
 * the same page don't interfere:
 *
 *   {{call:removeClass:#<setId> [role=tab],tab--active}};
 *   {{call:addClass:#<setId>-tab-<i>,tab--active}};
 *   {{call:hide:#<setId> .tabset__panel}};
 *   {{call:show:#<setId>-panel-<i>}}
 *
 * MVP scope:
 *  - Each panel's content is a single <p> with a textKey. For richer
 *    panels, the user can edit the emitted <p> with the regular
 *    visual editor after save (each panel is a normal node).
 *  - First tab is always the initial active one. "Open which by
 *    default" config can land later if asked.
 *  - No arrow-key keyboard navigation between tabs. Tab/Shift+Tab
 *    still moves focus; Enter/Space on a tab activates via the
 *    native button click path. WAI-ARIA's full arrow-key spec
 *    requires JS state — deferred.
 *
 * Config:
 *   - setId  string, required — unique HTML id for the wrapper. Used
 *                               to scope the per-button onclick chains
 *                               so multiple tab sets coexist on one page.
 *   - items  array,  required — ≥ 1, each { labelKey, contentKey }.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class TabSetBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'tab-set';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- setId ----------------------------------------------------
        self::requireField($config, 'setId');
        $setId = (string)$config['setId'];
        self::validateHtmlId($setId, 'setId');

        // ---- items ----------------------------------------------------
        $items = $config['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new ComplexElementBuilderException(
                "Tab set must have at least one item"
            );
        }
        $itemsList = array_values($items);

        foreach ($itemsList as $idx => $item) {
            if (!is_array($item)) {
                throw new ComplexElementBuilderException("Item at index $idx must be an object");
            }
            $labelKey   = isset($item['labelKey'])   ? trim((string)$item['labelKey'])   : '';
            $contentKey = isset($item['contentKey']) ? trim((string)$item['contentKey']) : '';
            if ($labelKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'labelKey'");
            }
            if ($contentKey === '') {
                throw new ComplexElementBuilderException("Item at index $idx is missing 'contentKey'");
            }
        }

        // ---- build tabs + panels --------------------------------------
        $tabNodes   = [];
        $panelNodes = [];

        foreach ($itemsList as $idx => $item) {
            $tabId   = $setId . '-tab-'   . $idx;
            $panelId = $setId . '-panel-' . $idx;
            $isFirst = ($idx === 0);

            // onclick chain — scoped via descendant of #<setId>.
            $onclick =
                '{{call:removeClass:#' . $setId . ' [role=tab],tab--active}};' .
                '{{call:addClass:#'    . $tabId . ',tab--active}};' .
                '{{call:hide:#'        . $setId . ' .tabset__panel}};' .
                '{{call:show:#'        . $panelId . '}}';

            $tabParams = [
                'type'           => 'button',
                'role'           => 'tab',
                'id'             => $tabId,
                'aria-controls'  => $panelId,
                'aria-selected'  => $isFirst ? 'true' : 'false',
                'class'          => $isFirst ? 'tabset__tab tab--active' : 'tabset__tab',
                'onclick'        => $onclick,
            ];
            if (!$isFirst) {
                // Roving tabindex pattern: only the active tab is
                // tab-focusable. Once arrow-key nav lands, the JS
                // will move this attribute around.
                $tabParams['tabindex'] = '-1';
            }

            $tabNodes[] = [
                'tag'      => 'button',
                'params'   => $tabParams,
                'children' => [['textKey' => (string)$item['labelKey']]]
            ];

            $panelParams = [
                'role'            => 'tabpanel',
                'id'              => $panelId,
                'aria-labelledby' => $tabId,
                'class'           => $isFirst ? 'tabset__panel' : 'tabset__panel hidden',
            ];

            $panelNodes[] = [
                'tag'      => 'div',
                'params'   => $panelParams,
                'children' => [
                    [
                        'tag'      => 'p',
                        'children' => [['textKey' => (string)$item['contentKey']]]
                    ]
                ]
            ];
        }

        $tabListNode = [
            'tag'    => 'div',
            'params' => ['role' => 'tablist', 'class' => 'tabset__list'],
            'children' => $tabNodes
        ];

        $outerChildren = array_merge([$tabListNode], $panelNodes);

        return [
            'tag'    => 'div',
            'params' => ['class' => 'tabset', 'id' => $setId],
            'children' => $outerChildren
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['items']) && is_array($config['items'])) {
            foreach ($config['items'] as $item) {
                if (!is_array($item)) continue;
                if (isset($item['labelKey'])   && $item['labelKey']   !== '') $keys[] = (string)$item['labelKey'];
                if (isset($item['contentKey']) && $item['contentKey'] !== '') $keys[] = (string)$item['contentKey'];
            }
        }
        return $keys;
    }
}
