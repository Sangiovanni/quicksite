<?php
/**
 * consentLayerHelpers.php — generate + render the consent banner + popup.
 *
 * The banner/popup are ordinary QuickSite structures (templates/model/json/
 * consent-banner.json + consent-popup.json), seeded from the registry, rendered
 * globally like menu/footer, and styleable/editable in the existing tools. They
 * carry reserved data-attributes that qs.js wires:
 *
 *   #qs-consent-banner / #qs-consent-popup  — reserved container ids
 *   [data-consent-action="accept-all|refuse-all|customize|save|close"]
 *                                            — every action inside the banner and
 *                                              the popup; anywhere else only
 *                                              customize, which reopens the
 *                                              preferences (the cookie-policy
 *                                              page carries one)
 *   [data-consent-toggle="<category>"]       — per-category checkbox in the popup;
 *                                              qs.js shows its row while the
 *                                              category has a declared key
 *   [data-consent-asks]                      — on the cookie-policy page: shown only
 *                                              on a site that asks its visitors
 *                                              anything (qs_consent_asks()); valued
 *                                              "false", only on a site with nothing
 *                                              to ask
 *
 * Visible copy uses translation keys (textKey) so the layer shows in the
 * visitor's language from day one. Their words are in
 * <secure>/src/translations/<lang>.json, read by key (qs_starting_words_seed()).
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
            'aria-label' => 'consent.banner.aria_label',
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

/**
 * Popup structure (array of one root node). $categories = declared non-essential.
 *
 * One row per category a visitor can choose, whether or not it has a key now: a category declared
 * later brings the banner back by itself, and its row must be there to choose. A category with no
 * declared key is marked hidden. On a page, qs.js shows a row exactly while its category has a key;
 * with no window.QS_CONSENT (the editor) the rows stay as written here.
 */
function buildConsentPopupStructure(array $categories): array {
    $rows = [];
    // Essential is always-on, shown locked.
    $rows[] = _cNode('div', ['class' => 'qs-consent-row qs-consent-row--locked'], [
        _cNode('span', ['class' => 'qs-consent-row__name'], [_cText('consent.category.essential')]),
        _cNode('span', ['class' => 'qs-consent-row__note'], [_cText('consent.category.essential_note')]),
        _cNode('input', ['type' => 'checkbox', 'class' => 'qs-consent-row__toggle', 'checked' => true, 'disabled' => true]),
    ]);
    foreach (CONSENT_TOGGLE_CATEGORIES as $cat) {
        $rowParams = ['class' => 'qs-consent-row'];
        if (!in_array($cat, $categories, true)) {
            $rowParams['hidden'] = true;
        }
        $rows[] = _cNode('label', $rowParams, [
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
            'aria-label' => 'consent.popup.aria_label',
            'hidden' => true,
        ], [
            _cNode('div', ['class' => 'qs-consent-popup__dialog'], [
                _cNode('div', ['class' => 'qs-consent-popup__head'], [
                    _cNode('h2', ['class' => 'qs-consent-popup__title'], [_cText('consent.popup.title')]),
                    _cNode('button', ['type' => 'button', 'class' => 'qs-consent-popup__close', 'data-consent-action' => 'close', 'aria-label' => 'consent.popup.close_label'], [_cRaw('✕')]),
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

/**
 * The OAuth providers THIS project signs visitors in with, as [['name', 'url'|null]], for the
 * sign-in section of a generated cookie or privacy policy. The routes come from the one scan of the
 * project's sign-in routes (qs_oauth_project_routes()); a route that takes its provider from the
 * address names none, so for it every offered provider the project has keys for, in either set,
 * is listed. Only a provider the installation's list offers is listed: a route naming another
 * signs nobody in. The name and the privacy link are the list's (qs_oauth_provider_name(),
 * `privacy_url`); a provider without a link is listed by name. A project that wires no sign-in
 * returns [] (no third-party section).
 */
function consentOAuthLinks(): array {
    if (!defined('PROJECT_PATH')) return [];
    require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';
    $offered = qs_oauth_providers();
    $keys = null;
    $ids = [];
    foreach (qs_oauth_project_routes() as $route) {
        $id = $route['provider'];
        if ($id === null) {
            $keys = $keys ?? qs_oauth_keys_read();
            foreach (array_keys($offered) as $offeredId) {
                if (isset($keys[$offeredId])) $ids[$offeredId] = true;
            }
        } elseif (isset($offered[$id])) {
            $ids[$id] = true;
        }
    }

    $out = [];
    foreach (array_keys($ids) as $id) {
        $entry = $offered[$id];
        $out[] = ['name' => qs_oauth_provider_name((string) $id, $entry), 'url' => $entry['privacy_url'] ?? null];
    }
    return $out;
}

/**
 * The cookies QuickSite itself sets for a visitor of this site, outside the registry and the consent
 * rule, each named by what names it: the consent record, and, on a site that signs visitors in, the
 * sign-in's state store and the signed-in session. A site signs visitors in when consentOAuthLinks()
 * finds a provider through the one scan of its routes ($oauthLinks, its answer), so the rows and the
 * sign-in section say the same thing.
 *
 * The consent record is set only by a visitor's choice, so its row follows the same rule as the
 * policy's button (`onlyWhenAsking`): both are always written and carry data-consent-asks, so they
 * follow the registry at run time. Testing the rule here instead would lose the row on the path
 * everyone uses, since the panel generates the policy before the layer.
 *
 * @return list<array{name: string, text: string, onlyWhenAsking: bool}> `text` starts the row's two
 *         textKeys: consent.policy.<text>_retention and consent.policy.<text>_description
 */
function consentEngineCookies(array $oauthLinks): array {
    $cookies = [['name' => qs_project_cookie_name(QS_CONSENT_COOKIE), 'text' => 'record', 'onlyWhenAsking' => true]];
    if ($oauthLinks !== []) {
        $cookies[] = ['name' => QS_OAUTH_SESSION_COOKIE, 'text' => 'oauth_session', 'onlyWhenAsking' => false];
        $cookies[] = ['name' => qs_project_cookie_name(QS_OAUTH_COOKIE), 'text' => 'oauth_user', 'onlyWhenAsking' => false];
    }
    return $cookies;
}

/**
 * Cookie-policy page structure (array of nodes) — a button that reopens the
 * cookie preferences, a deterministic table built from the registry (and the
 * theme switch's key when the switch is on, and the cookies QuickSite itself
 * sets: consentEngineCookies()), an OAuth-provider privacy-link
 * section and a legal note. Structural copy + per-key descriptions use textKeys
 * (translatable); name/scope/retention are baked from the registry and the
 * project's settings at generation time (re-generate to refresh).
 * $oauthLinks = [['name'=>..,'url'=>..|null], ...].
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
    //
    // ⚠ THE KEY COLUMN SHOWS THE PHYSICAL NAME, not the declared one. This page
    // is a disclosure document: a visitor who opens devtools to check what the
    // site stores must find what this table says. qs.js writes
    // `qsp_<projectId>_<key>`, so the declared name alone would send them
    // looking for something that is not there.
    //
    // Composed HERE, at generation, from the live project id — never persisted
    // into the registry (see qs_storage_physical_key). There is no rename
    // command, so a composed id cannot drift out from under a generated page;
    // clone and import create NEW projects, which generate their own.
    ksort($items);
    $bodyRows = [];
    $anyPrefixed = false;
    foreach ($items as $id => $item) {
        if (!is_array($item)) continue;
        $cat = $item['category'] ?? 'functional';
        $consentKey = ($cat === 'essential') ? 'consent.policy.no' : 'consent.policy.yes';
        $retention = $item['retention'] ?? '';
        $scope = (string) ($item['scope'] ?? '');
        $physical = qs_storage_physical_key((string) $id, $scope);
        if ($physical !== (string) $id) {
            $anyPrefixed = true;
        }
        // Description cell references the storage registry's canonical
        // description key (translate/ — storageDescKey), resolved live at render.
        // Empty cell when the item has no description.
        $hasDesc = storageItemDescription((string) $id, $item, storageDescLang()) !== '';
        $bodyRows[] = _cNode('tr', [], [
            _cNode('td', ['class' => 'qs-cookie-policy__key'], [_cRaw($physical)]),
            _cNode('td', [], [_cRaw($scope)]),
            _cNode('td', [], [_cText('consent.category.' . $cat)]),
            _cNode('td', [], [_cRaw($retention !== '' ? (string) $retention : '—')]),
            _cNode('td', [], [_cText($consentKey)]),
            _cNode('td', [], $hasDesc ? [_cText(storageDescKey((string) $id))] : []),
        ]);
    }

    // The theme switch's key, when the switch is on (theme mode and the visitor's own
    // toggle, the one case where a page reads or writes it). QuickSite itself keeps the
    // visitor's choice under it, outside the registry and the consent rule: only a click
    // on the switch sets it, to keep the choice that click made, so it needs no consent —
    // but a visitor checking their storage must find it here. Like every row, it is the
    // setting at generation: the page is generated again after the switch changes.
    if (defined('PROJECT_NAME') && defined('THEME_MODE_ENABLED') && THEME_MODE_ENABLED
        && defined('THEME_USER_TOGGLE_ENABLED') && THEME_USER_TOGGLE_ENABLED) {
        require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php'; // qs_theme_storage_key
        $bodyRows[] = _cNode('tr', [], [
            _cNode('td', ['class' => 'qs-cookie-policy__key'], [_cRaw(qs_theme_storage_key((string) PROJECT_NAME))]),
            _cNode('td', [], [_cRaw('localStorage')]),
            _cNode('td', [], [_cText('consent.category.essential')]),
            _cNode('td', [], [_cText('consent.policy.theme_retention')]),
            _cNode('td', [], [_cText('consent.policy.no')]),
            _cNode('td', [], [_cText('consent.policy.theme_description')]),
        ]);
    }

    // The cookies QuickSite itself sets for a visitor, in the same row. They are outside the registry and
    // the consent rule, and a visitor checking their cookies must find them here. Like every row, each is
    // the setting at generation: the page is generated again after the site's sign-in routes change.
    // The prefix note explains a name a visitor can see: when the consent record is the only name
    // holding the prefix, the note follows that row.
    $prefixOnlyWhenAsking = false;
    $onlyWhenAskingRows = 0;
    foreach (consentEngineCookies($oauthLinks) as $cookie) {
        if ($cookie['onlyWhenAsking']) {
            $onlyWhenAskingRows++;
        }
        if (strpos($cookie['name'], 'qsp_') === 0) {
            if ($cookie['onlyWhenAsking']) {
                $prefixOnlyWhenAsking = true;
            } else {
                $anyPrefixed = true;
            }
        }
        $bodyRows[] = _cNode('tr', $cookie['onlyWhenAsking'] ? ['data-consent-asks' => true] : [], [
            _cNode('td', ['class' => 'qs-cookie-policy__key'], [_cRaw($cookie['name'])]),
            _cNode('td', [], [_cRaw('cookie')]),
            _cNode('td', [], [_cText('consent.category.essential')]),
            _cNode('td', [], [_cText('consent.policy.' . $cookie['text'] . '_retention')]),
            _cNode('td', [], [_cText('consent.policy.no')]),
            _cNode('td', [], [_cText('consent.policy.' . $cookie['text'] . '_description')]),
        ]);
    }
    // When the consent record's row is the table's only row, the table follows it: on a site with
    // nothing to ask it would show a header alone. The line beside it says so instead, written hidden,
    // so with the layer off, with no script and in the editor the table shows as ever. It says
    // "declares" on purpose: an embed or a hand-written script is not in the registry, so the page
    // cannot say the site stores nothing.
    $onlyTheRecord = $onlyWhenAskingRows > 0 && count($bodyRows) === $onlyWhenAskingRows;
    $tableParams = ['class' => 'qs-cookie-policy'];
    if ($onlyTheRecord) {
        $tableParams['data-consent-asks'] = true;
    }
    $table = _cNode('table', $tableParams, [$thead, _cNode('tbody', [], $bodyRows)]);

    // The way back to the cookie preferences, right under the intro: withdrawing consent must be
    // as easy as giving it, and a visitor reading the policy has often come to change their choice.
    // qs.js opens the preferences from any element marked customize. The paragraph carries
    // data-consent-asks, not the button, so a site with nothing to ask keeps no empty gap.
    $preferences = _cNode('p', ['class' => 'qs-cookie-policy__preferences', 'data-consent-asks' => true], [
        _cNode('button', [
            'type' => 'button',
            'class' => 'qs-consent-btn qs-consent-btn--primary',
            'data-consent-action' => 'customize',
        ], [_cText('consent.policy.change_preferences')]),
    ]);

    $children = [
        _cNode('h1', [], [_cText('consent.policy.title')]),
        _cNode('p', ['class' => 'qs-cookie-policy__intro'], [_cText('consent.policy.intro')]),
        $preferences,
        $table,
    ];
    if ($onlyTheRecord) {
        $children[] = _cNode('p', ['class' => 'qs-cookie-policy__none', 'data-consent-asks' => 'false', 'hidden' => true], [_cText('consent.policy.none')]);
    }

    // One sentence explaining the prefix, and only when there is a prefixed key
    // to explain. A visitor comparing this table against their browser sees a
    // name they did not choose; saying why turns an oddity into a disclosure.
    if ($anyPrefixed || $prefixOnlyWhenAsking) {
        $noteParams = ['class' => 'qs-cookie-policy__prefix-note'];
        if (!$anyPrefixed) {
            $noteParams['data-consent-asks'] = true;
        }
        $children[] = _cNode('p', $noteParams, [_cText('consent.policy.prefix_note')]);
    }

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
