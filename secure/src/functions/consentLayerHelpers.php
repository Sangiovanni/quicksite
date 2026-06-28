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

/** Whether a route (slash path) currently exists in the ROUTES structure. */
function consentRouteExists(string $route): bool {
    if (!defined('ROUTES') || !is_array(ROUTES)) return false;
    $segs = array_values(array_filter(explode('/', trim($route, '/')), fn($s) => $s !== ''));
    if (empty($segs)) return false;
    $cur = ROUTES;
    foreach ($segs as $s) {
        if (!is_array($cur) || !isset($cur[$s])) return false;
        $cur = $cur[$s];
    }
    return true;
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

// Privacy-policy URLs for the common OAuth presets. Custom providers list
// without a link (a per-provider override field is a possible later addition).
const CONSENT_OAUTH_PRIVACY_URLS = [
    'google'    => 'https://policies.google.com/privacy',
    'github'    => 'https://docs.github.com/site-policy/privacy-policies/github-privacy-statement',
    'meta'      => 'https://www.facebook.com/privacy/policy',
    'facebook'  => 'https://www.facebook.com/privacy/policy',
    'amazon'    => 'https://www.amazon.com/gp/help/customer/display.html?nodeId=GX7NJQ4ZB8MHFRNJ',
    'microsoft' => 'https://privacy.microsoft.com/privacystatement',
];

/**
 * OAuth providers THIS project actually offers sign-in with, as
 * [['name','url'|null]]. The signal is the project's route-resolvers wiring an
 * oauth-start/callback/logout flow to a literal provider id — NOT the presence
 * of (possibly global/admin) credentials. So a project that hasn't wired any
 * OAuth login returns [] (no third-party section). Mirrors the in-use scan in
 * listOAuthProviders.
 */
function consentOAuthLinks(): array {
    if (!defined('PROJECT_PATH')) return [];
    $sidecar = PROJECT_PATH . '/data/route-resolvers.json';
    if (!file_exists($sidecar)) return [];
    $all = json_decode((string) @file_get_contents($sidecar), true);
    if (!is_array($all)) return [];

    $providers = [];
    foreach ($all as $entry) {
        // Single resolver = scalar config; multi = array of configs.
        $configs = (isset($entry['kind']) && is_string($entry['kind'])) ? [$entry] : $entry;
        if (!is_array($configs)) continue;
        foreach ($configs as $config) {
            if (!is_array($config)) continue;
            $kind = $config['kind'] ?? null;
            if ($kind !== 'oauth-start' && $kind !== 'oauth-callback' && $kind !== 'oauth-logout') continue;
            $provider = $config['provider'] ?? null;
            // Skip empty + param-placeholder providers ('{:provider}').
            if (!is_string($provider) || $provider === '' || preg_match('/^\{:\w+\}$/', $provider)) continue;
            $providers[$provider] = true;
        }
    }

    $out = [];
    foreach (array_keys($providers) as $pid) {
        $out[] = ['name' => ucfirst($pid), 'url' => CONSENT_OAUTH_PRIVACY_URLS[strtolower($pid)] ?? null];
    }
    return $out;
}

/** Per-item description in the given language, with fallback to any present. */
function _consentItemDescription(array $item, string $lang): string {
    $d = $item['description'] ?? null;
    if (!is_array($d)) return '';
    if (isset($d[$lang]) && is_string($d[$lang]) && $d[$lang] !== '') return $d[$lang];
    foreach ($d as $v) {
        if (is_string($v) && $v !== '') return $v;
    }
    return '';
}

/** Translation key for an item's policy-table description cell. */
function _consentDescKey(string $id): string {
    return 'consent.policy.desc.' . preg_replace('/[^a-z0-9_]/i', '_', $id);
}

/**
 * Per-language description seed for the policy table — { descKey => text } for
 * every item that has a description, resolved in $lang (fallback to any present).
 * These keys are REFRESHED from the registry on each regenerate (storage.json is
 * the source of truth), unlike the structural copy which is author-editable.
 */
function consentPolicyDescSeed(array $items, string $lang): array {
    $out = [];
    foreach ($items as $id => $item) {
        if (!is_array($item)) continue;
        $desc = _consentItemDescription($item, $lang);
        if ($desc !== '') {
            $out[_consentDescKey((string) $id)] = $desc;
        }
    }
    return $out;
}

/**
 * Cookie-policy page structure (array of nodes) — a deterministic table built
 * from the registry, plus an OAuth-provider privacy-link section and a legal
 * note. Structural copy + per-key descriptions use textKeys (translatable);
 * name/scope/retention are baked from the registry at generation time
 * (re-generate to refresh). $oauthLinks = [['name'=>..,'url'=>..|null], ...].
 */
function buildCookiePolicyStructure(array $items, array $oauthLinks): array {
    // Table header row.
    $headCols = ['key', 'scope', 'category', 'retention', 'consent', 'description'];
    $headCells = [];
    foreach ($headCols as $c) {
        $headCells[] = _cNode('th', [], [_cText('consent.policy.col_' . $c)]);
    }
    $thead = _cNode('thead', [], [_cNode('tr', [], $headCells)]);

    // One body row per declared key (stable order).
    ksort($items);
    $bodyRows = [];
    foreach ($items as $id => $item) {
        if (!is_array($item)) continue;
        $cat = $item['category'] ?? 'functional';
        $consentKey = ($cat === 'essential') ? 'consent.policy.no' : 'consent.policy.yes';
        $retention = $item['retention'] ?? '';
        // Description cell: a translatable key when the item has a description
        // (seeded per-language from the registry), an empty cell otherwise.
        $hasDesc = _consentItemDescription($item, 'en') !== '';
        $bodyRows[] = _cNode('tr', [], [
            _cNode('td', ['class' => 'qs-cookie-policy__key'], [_cRaw((string) $id)]),
            _cNode('td', [], [_cRaw((string) ($item['scope'] ?? ''))]),
            _cNode('td', [], [_cText('consent.category.' . $cat)]),
            _cNode('td', [], [_cRaw($retention !== '' ? (string) $retention : '—')]),
            _cNode('td', [], [_cText($consentKey)]),
            _cNode('td', [], $hasDesc ? [_cText(_consentDescKey((string) $id))] : []),
        ]);
    }
    $table = _cNode('table', ['class' => 'qs-cookie-policy'], [$thead, _cNode('tbody', [], $bodyRows)]);

    $children = [
        _cNode('h1', [], [_cText('consent.policy.title')]),
        _cNode('p', ['class' => 'qs-cookie-policy__intro'], [_cText('consent.policy.intro')]),
        $table,
    ];

    // OAuth provider privacy-policy links (only when providers exist).
    if (!empty($oauthLinks)) {
        $liItems = [];
        foreach ($oauthLinks as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name === '') continue;
            if (!empty($p['url'])) {
                $liItems[] = _cNode('li', [], [
                    _cRaw($name . ' — '),
                    _cNode('a', ['href' => (string) $p['url'], 'target' => '_blank', 'rel' => 'noopener'], [_cText('consent.policy.provider_link')]),
                ]);
            } else {
                $liItems[] = _cNode('li', [], [_cRaw($name)]);
            }
        }
        if (!empty($liItems)) {
            $children[] = _cNode('h2', [], [_cText('consent.policy.oauth_title')]);
            $children[] = _cNode('p', ['class' => 'qs-cookie-policy__oauth-intro'], [_cText('consent.policy.oauth_intro')]);
            $children[] = _cNode('ul', ['class' => 'qs-cookie-policy__providers'], $liItems);
        }
    }

    $children[] = _cNode('p', ['class' => 'qs-cookie-policy__disclaimer'], [_cText('consent.policy.disclaimer')]);

    return [_cNode('main', ['class' => 'container qs-cookie-policy-page'], $children)];
}

/** Default EN/FR copy for the cookie-policy page textKeys. */
function cookiePolicyTranslationSeed(): array {
    return [
        'en' => [
            'consent.policy.title' => 'Cookie policy',
            'consent.policy.intro' => 'This page lists the browser storage this site uses, generated from the site\'s storage registry.',
            'consent.policy.col_key' => 'Name',
            'consent.policy.col_scope' => 'Storage',
            'consent.policy.col_category' => 'Category',
            'consent.policy.col_retention' => 'Retention',
            'consent.policy.col_consent' => 'Needs consent',
            'consent.policy.col_description' => 'Purpose',
            'consent.policy.yes' => 'Yes',
            'consent.policy.no' => 'No',
            'consent.policy.oauth_title' => 'Third-party sign-in',
            'consent.policy.oauth_intro' => 'This site offers sign-in via the following providers. See each provider\'s own privacy policy for what they collect.',
            'consent.policy.provider_link' => 'Privacy policy',
            'consent.policy.disclaimer' => 'This summary is generated from the site\'s storage registry and is provided for transparency. It is not legal advice — obtain your own legal review.',
        ],
        'fr' => [
            'consent.policy.title' => 'Politique des cookies',
            'consent.policy.intro' => 'Cette page liste les données de stockage que ce site utilise, générée à partir du registre de stockage du site.',
            'consent.policy.col_key' => 'Nom',
            'consent.policy.col_scope' => 'Stockage',
            'consent.policy.col_category' => 'Catégorie',
            'consent.policy.col_retention' => 'Conservation',
            'consent.policy.col_consent' => 'Consentement requis',
            'consent.policy.col_description' => 'Finalité',
            'consent.policy.yes' => 'Oui',
            'consent.policy.no' => 'Non',
            'consent.policy.oauth_title' => 'Connexion tierce',
            'consent.policy.oauth_intro' => 'Ce site propose la connexion via les fournisseurs suivants. Consultez la politique de confidentialité de chaque fournisseur pour savoir ce qu\'il collecte.',
            'consent.policy.provider_link' => 'Politique de confidentialité',
            'consent.policy.disclaimer' => 'Ce résumé est généré à partir du registre de stockage du site et fourni à titre de transparence. Il ne constitue pas un avis juridique — faites réaliser votre propre vérification juridique.',
        ],
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
