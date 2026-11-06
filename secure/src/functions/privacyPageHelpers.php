<?php
/**
 * privacyPageHelpers.php — build the generated privacy-policy page structure.
 * Mirrors consentLayerHelpers' buildCookiePolicyStructure. Prose is textKeys
 * (translatable, live):
 *   - structural copy: privacy.policy.* (its words in <secure>/src/translations/<lang>.json)
 *   - collected-data labels/purposes: privacy.collected.<id>.label / .purpose
 *     (authored via the registry, resolved live)
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php'; // _cNode/_cText/_cRaw + consentOAuthLinks

/**
 * @param array $collected  datum ids (label/purpose resolved via textKeys)
 * @param array $sharing    [ ['name'=>, 'url'=>|null, 'datumIds'=>[...]] ] third parties
 * @param array $oauthLinks consentOAuthLinks() — [ ['name'=>, 'url'=>|null] ]
 * @param array $cookie     ['mode'=>'link'|'hint'|'omit', 'route'=>?string]
 */
function buildPrivacyPolicyStructure(array $collected, array $sharing, array $oauthLinks, array $cookie): array {
    $children = [
        _cNode('h1', [], [_cText('privacy.policy.title')]),
        _cNode('p', ['class' => 'qs-privacy-policy__intro'], [_cText('privacy.policy.intro')]),
    ];

    // --- Data we collect (table: label | purpose) ---
    if (!empty($collected)) {
        $children[] = _cNode('h2', [], [_cText('privacy.policy.collect_title')]);
        $thead = _cNode('thead', [], [_cNode('tr', [], [
            _cNode('th', [], [_cText('privacy.policy.col_data')]),
            _cNode('th', [], [_cText('privacy.policy.col_purpose')]),
        ])]);
        $rows = [];
        foreach ($collected as $id) {
            $rows[] = _cNode('tr', [], [
                _cNode('td', [], [_cText(privacyLabelKey((string) $id))]),
                _cNode('td', [], [_cText(privacyPurposeKey((string) $id))]),
            ]);
        }
        $children[] = _cNode('table', ['class' => 'qs-privacy-policy'], [$thead, _cNode('tbody', [], $rows)]);
    }

    // --- Data sharing (third parties) ---
    $shareItems = [];
    foreach ($sharing as $tp) {
        $name = (string) ($tp['name'] ?? '');
        if ($name === '') continue;
        $li = [_cRaw($name)];
        $labels = [];
        foreach (($tp['datumIds'] ?? []) as $did) {
            $labels[] = _cText(privacyLabelKey((string) $did));
        }
        if (!empty($labels)) {
            $li[] = _cRaw(' — ');
            foreach ($labels as $idx => $lab) {
                if ($idx > 0) $li[] = _cRaw(', ');
                $li[] = $lab;
            }
        }
        if (!empty($tp['url'])) {
            $li[] = _cRaw(' — ');
            $li[] = _cNode('a', ['href' => (string) $tp['url'], 'target' => '_blank', 'rel' => 'noopener'], [_cText('privacy.policy.provider_link')]);
        }
        $shareItems[] = _cNode('li', [], $li);
    }
    if (!empty($shareItems)) {
        $children[] = _cNode('h2', [], [_cText('privacy.policy.sharing_title')]);
        $children[] = _cNode('p', ['class' => 'qs-privacy-policy__sharing-intro'], [_cText('privacy.policy.sharing_intro')]);
        $children[] = _cNode('ul', ['class' => 'qs-privacy-policy__sharing'], $shareItems);
    }

    // --- Third-party sign-in (OAuth) ---
    $oauthItems = [];
    foreach ($oauthLinks as $p) {
        $name = (string) ($p['name'] ?? '');
        if ($name === '') continue;
        if (!empty($p['url'])) {
            $oauthItems[] = _cNode('li', [], [_cRaw($name . ' — '), _cNode('a', ['href' => (string) $p['url'], 'target' => '_blank', 'rel' => 'noopener'], [_cText('privacy.policy.provider_link')])]);
        } else {
            $oauthItems[] = _cNode('li', [], [_cRaw($name)]);
        }
    }
    if (!empty($oauthItems)) {
        $children[] = _cNode('h2', [], [_cText('privacy.policy.oauth_title')]);
        $children[] = _cNode('p', ['class' => 'qs-privacy-policy__oauth-intro'], [_cText('privacy.policy.oauth_intro')]);
        $children[] = _cNode('ul', ['class' => 'qs-privacy-policy__providers'], $oauthItems);
    }

    // --- Cookies cross-link (link / hint / omit) ---
    $cookieMode = $cookie['mode'] ?? 'omit';
    if ($cookieMode === 'link' && !empty($cookie['route'])) {
        $children[] = _cNode('h2', [], [_cText('privacy.policy.cookie_title')]);
        $children[] = _cNode('p', [], [
            _cText('privacy.policy.cookie_intro'), _cRaw(' '),
            _cNode('a', ['href' => (string) $cookie['route']], [_cText('privacy.policy.cookie_link')]),
        ]);
    } elseif ($cookieMode === 'hint') {
        $children[] = _cNode('h2', [], [_cText('privacy.policy.cookie_title')]);
        $children[] = _cNode('p', [], [_cText('privacy.policy.cookie_hint')]);
    }

    $children[] = _cNode('p', ['class' => 'qs-privacy-policy__disclaimer'], [_cText('privacy.policy.disclaimer')]);

    return [_cNode('main', ['class' => 'container qs-privacy-policy-page'], $children)];
}
