<?php
/**
 * privacyPageHelpers.php — build the generated privacy-policy page structure +
 * its default structural translation seed. Mirrors consentLayerHelpers'
 * buildCookiePolicyStructure. Prose is textKeys (translatable, live):
 *   - structural copy: privacy.policy.* (seeded EN/FR)
 *   - collected-data labels/purposes: privacy.collected.<id>.label / .purpose
 *     (authored via the registry, resolved live)
 *
 * See NOTES/planning/PRIVACY_POLICY_GENERATOR.md.
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

/** Default EN/FR structural copy for the privacy-policy textKeys. */
function privacyPolicyTranslationSeed(): array {
    return [
        'en' => [
            'privacy.policy.title' => 'Privacy policy',
            'privacy.policy.intro' => 'This page describes the personal data this site collects and who it is shared with.',
            'privacy.policy.collect_title' => 'Data we collect',
            'privacy.policy.col_data' => 'Data',
            'privacy.policy.col_purpose' => 'Purpose',
            'privacy.policy.sharing_title' => 'Data sharing',
            'privacy.policy.sharing_intro' => 'This site shares data with the following third parties. See each one\'s own privacy policy for what they do with it.',
            'privacy.policy.provider_link' => 'Privacy policy',
            'privacy.policy.oauth_title' => 'Third-party sign-in',
            'privacy.policy.oauth_intro' => 'This site offers sign-in via the following providers. See each provider\'s own privacy policy for what they collect.',
            'privacy.policy.cookie_title' => 'Cookies',
            'privacy.policy.cookie_intro' => 'This site uses browser storage and cookies, described in detail on the',
            'privacy.policy.cookie_link' => 'cookie policy',
            'privacy.policy.cookie_hint' => 'This site may use cookies. Consider publishing a cookie policy that lists them.',
            'privacy.policy.disclaimer' => 'This summary is generated from the site\'s configuration and provided for transparency. It is not legal advice — obtain your own legal review for your jurisdiction.',
        ],
        'fr' => [
            'privacy.policy.title' => 'Politique de confidentialité',
            'privacy.policy.intro' => 'Cette page décrit les données personnelles que ce site collecte et avec qui elles sont partagées.',
            'privacy.policy.collect_title' => 'Données que nous collectons',
            'privacy.policy.col_data' => 'Donnée',
            'privacy.policy.col_purpose' => 'Finalité',
            'privacy.policy.sharing_title' => 'Partage de données',
            'privacy.policy.sharing_intro' => 'Ce site partage des données avec les tiers suivants. Consultez la politique de confidentialité de chacun pour savoir ce qu\'ils en font.',
            'privacy.policy.provider_link' => 'Politique de confidentialité',
            'privacy.policy.oauth_title' => 'Connexion tierce',
            'privacy.policy.oauth_intro' => 'Ce site propose la connexion via les fournisseurs suivants. Consultez la politique de confidentialité de chaque fournisseur pour savoir ce qu\'il collecte.',
            'privacy.policy.cookie_title' => 'Cookies',
            'privacy.policy.cookie_intro' => 'Ce site utilise du stockage navigateur et des cookies, décrits en détail dans la',
            'privacy.policy.cookie_link' => 'politique des cookies',
            'privacy.policy.cookie_hint' => 'Ce site peut utiliser des cookies. Envisagez de publier une politique des cookies qui les répertorie.',
            'privacy.policy.disclaimer' => 'Ce résumé est généré à partir de la configuration du site et fourni à titre de transparence. Il ne constitue pas un avis juridique — faites réaliser votre propre vérification juridique pour votre juridiction.',
        ],
    ];
}
