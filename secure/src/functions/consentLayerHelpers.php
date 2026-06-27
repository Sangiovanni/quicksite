<?php
/**
 * consentLayerHelpers.php — generate + render the consent banner + popup
 * (beta.9 Phase 2, slice 7).
 *
 * The banner/popup are ordinary QuickSite structures (templates/model/json/
 * consent-banner.json + consent-popup.json), seeded from the registry, rendered
 * globally like menu/footer, and styleable/editable in the existing tools. They
 * carry reserved data-attributes that qs.js wires:
 *
 *   #qs-consent-banner / #qs-consent-popup  — reserved container ids
 *   [data-consent-action="accept-all|refuse-all|customize|save|close"]
 *   [data-consent-toggle="<category>"]       — per-category checkbox in the popup
 *
 * Visible copy uses translation keys (textKey) so the layer shows in the
 * visitor's language from day one. See NOTES/planning/BETA9_STORAGE_REGISTRY.md
 * (Phase 2 build spec).
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';

const CONSENT_BANNER_FILE = '/templates/model/json/consent-banner.json';
const CONSENT_POPUP_FILE  = '/templates/model/json/consent-popup.json';
// Categories that can be consent-gated (essential is always-on, shown locked).
const CONSENT_TOGGLE_CATEGORIES = ['functional', 'analytics', 'marketing'];

function consentBannerPath(): string { return PROJECT_PATH . CONSENT_BANNER_FILE; }
function consentPopupPath(): string  { return PROJECT_PATH . CONSENT_POPUP_FILE; }

/** True once the author has generated the layer (files on disk). */
function consentLayerGenerated(): bool {
    return file_exists(consentBannerPath()) && file_exists(consentPopupPath());
}

/** Distinct non-essential categories actually declared in the registry. */
function consentDeclaredCategories(): array {
    $items = loadStorageRegistry()['items'];
    $found = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $cat = $item['category'] ?? 'functional';
        if ($cat !== 'essential' && in_array($cat, CONSENT_TOGGLE_CATEGORIES, true) && !in_array($cat, $found, true)) {
            $found[] = $cat;
        }
    }
    // Keep canonical order.
    return array_values(array_filter(CONSENT_TOGGLE_CATEGORIES, function ($c) use ($found) {
        return in_array($c, $found, true);
    }));
}

/** Small node helpers keep the structure trees readable. */
function _cNode(string $tag, array $params = [], array $children = []): array {
    $n = ['tag' => $tag];
    if (!empty($params)) $n['params'] = $params;
    if (!empty($children)) $n['children'] = $children;
    return $n;
}
function _cText(string $key): array { return ['textKey' => $key]; }
function _cRaw(string $literal): array { return ['textKey' => '__RAW__' . $literal]; }

/** Banner structure (array of one root node). */
function buildConsentBannerStructure(?string $policyRoute): array {
    $textChildren = [_cText('consent.banner.message')];
    if ($policyRoute) {
        $textChildren[] = _cNode('a', [
            'href' => $policyRoute,
            'class' => 'qs-consent-banner__policy',
        ], [_cText('consent.banner.policy_link')]);
    }
    return [
        _cNode('div', [
            'id' => 'qs-consent-banner',
            'class' => 'qs-consent-banner',
            'role' => 'region',
            'aria-label' => 'Cookie consent',
            'hidden' => true,
        ], [
            _cNode('p', ['class' => 'qs-consent-banner__text'], $textChildren),
            _cNode('div', ['class' => 'qs-consent-banner__actions'], [
                _cNode('button', ['type' => 'button', 'class' => 'qs-consent-btn qs-consent-btn--ghost', 'data-consent-action' => 'refuse-all'], [_cText('consent.action.refuse_all')]),
                _cNode('button', ['type' => 'button', 'class' => 'qs-consent-btn qs-consent-btn--ghost', 'data-consent-action' => 'customize'], [_cText('consent.action.customize')]),
                _cNode('button', ['type' => 'button', 'class' => 'qs-consent-btn qs-consent-btn--primary', 'data-consent-action' => 'accept-all'], [_cText('consent.action.accept_all')]),
            ]),
        ]),
    ];
}

/** Popup structure (array of one root node). $categories = declared non-essential. */
function buildConsentPopupStructure(array $categories): array {
    $rows = [];
    // Essential is always-on, shown locked.
    $rows[] = _cNode('div', ['class' => 'qs-consent-row qs-consent-row--locked'], [
        _cNode('span', ['class' => 'qs-consent-row__name'], [_cText('consent.category.essential')]),
        _cNode('span', ['class' => 'qs-consent-row__note'], [_cText('consent.category.essential_note')]),
        _cNode('input', ['type' => 'checkbox', 'class' => 'qs-consent-row__toggle', 'checked' => true, 'disabled' => true]),
    ]);
    foreach ($categories as $cat) {
        $rows[] = _cNode('label', ['class' => 'qs-consent-row'], [
            _cNode('span', ['class' => 'qs-consent-row__name'], [_cText('consent.category.' . $cat)]),
            _cNode('input', ['type' => 'checkbox', 'class' => 'qs-consent-row__toggle', 'data-consent-toggle' => $cat]),
        ]);
    }
    return [
        _cNode('div', [
            'id' => 'qs-consent-popup',
            'class' => 'qs-consent-popup',
            'role' => 'dialog',
            'aria-modal' => 'true',
            'aria-label' => 'Cookie preferences',
            'hidden' => true,
        ], [
            _cNode('div', ['class' => 'qs-consent-popup__dialog'], [
                _cNode('div', ['class' => 'qs-consent-popup__head'], [
                    _cNode('h2', ['class' => 'qs-consent-popup__title'], [_cText('consent.popup.title')]),
                    _cNode('button', ['type' => 'button', 'class' => 'qs-consent-popup__close', 'data-consent-action' => 'close', 'aria-label' => 'Close'], [_cRaw('✕')]),
                ]),
                _cNode('p', ['class' => 'qs-consent-popup__intro'], [_cText('consent.popup.intro')]),
                _cNode('div', ['class' => 'qs-consent-popup__rows'], $rows),
                _cNode('div', ['class' => 'qs-consent-popup__actions'], [
                    _cNode('button', ['type' => 'button', 'class' => 'qs-consent-btn qs-consent-btn--ghost', 'data-consent-action' => 'refuse-all'], [_cText('consent.action.refuse_all')]),
                    _cNode('button', ['type' => 'button', 'class' => 'qs-consent-btn qs-consent-btn--primary', 'data-consent-action' => 'save'], [_cText('consent.action.save')]),
                ]),
            ]),
        ]),
    ];
}

/** Does a dot-notation key already resolve in a nested translation tree? */
function _consentKeyExists(array $nested, string $dotKey): bool {
    $cursor = $nested;
    foreach (explode('.', $dotKey) as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return false;
        }
        $cursor = $cursor[$part];
    }
    return true;
}

/**
 * Filter a flat seed map down to keys NOT already present in translate/<lang>.json
 * — re-generating must never clobber the author's edited copy.
 */
function consentFilterNewKeys(string $lang, array $flat): array {
    $file = PROJECT_PATH . '/translate/' . $lang . '.json';
    $existing = [];
    if (file_exists($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded)) $existing = $decoded;
    }
    $out = [];
    foreach ($flat as $key => $val) {
        if (!_consentKeyExists($existing, $key)) {
            $out[$key] = $val;
        }
    }
    return $out;
}

/** Default EN/FR copy for the seeded textKeys. Flat dot-notation maps. */
function consentTranslationSeed(): array {
    return [
        'en' => [
            'consent.banner.message' => 'We use cookies to improve your experience. You can accept, refuse, or customize your choices.',
            'consent.banner.policy_link' => 'Cookie policy',
            'consent.action.accept_all' => 'Accept all',
            'consent.action.refuse_all' => 'Refuse all',
            'consent.action.customize' => 'Customize',
            'consent.action.save' => 'Save choices',
            'consent.popup.title' => 'Cookie preferences',
            'consent.popup.intro' => 'Choose which categories you allow. Essential cookies are always on.',
            'consent.category.essential' => 'Essential',
            'consent.category.essential_note' => 'Always on',
            'consent.category.functional' => 'Functional',
            'consent.category.analytics' => 'Analytics',
            'consent.category.marketing' => 'Marketing',
        ],
        'fr' => [
            'consent.banner.message' => 'Nous utilisons des cookies pour améliorer votre expérience. Vous pouvez accepter, refuser ou personnaliser vos choix.',
            'consent.banner.policy_link' => 'Politique des cookies',
            'consent.action.accept_all' => 'Tout accepter',
            'consent.action.refuse_all' => 'Tout refuser',
            'consent.action.customize' => 'Personnaliser',
            'consent.action.save' => 'Enregistrer mes choix',
            'consent.popup.title' => 'Préférences des cookies',
            'consent.popup.intro' => 'Choisissez les catégories que vous autorisez. Les cookies essentiels sont toujours actifs.',
            'consent.category.essential' => 'Essentiels',
            'consent.category.essential_note' => 'Toujours actifs',
            'consent.category.functional' => 'Fonctionnels',
            'consent.category.analytics' => 'Analytique',
            'consent.category.marketing' => 'Marketing',
        ],
    ];
}
