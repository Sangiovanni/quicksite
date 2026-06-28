<?php
/**
 * privacyScanHelpers.php — scan the API registry (data/api-endpoints.json) for
 * the outbound data the site is configured to send, and build the privacy
 * coverage/status report consumed by getPrivacyStatus + the /admin/privacy page.
 *
 * The scan is SCHEMA-DRIVEN, not runtime: it reads declared `parameters` +
 * `requestSchema.properties` as the fields sent outward (response schemas are
 * ignored — persisted responses are the storage registry's concern). An atom is
 * a (endpoint, field) pair. Two coverage flags: (a) an atom not mapped to a
 * collected datum; (b) a body-bearing endpoint with NO request schema — we
 * can't verify what it sends and won't guess.
 *
 * See NOTES/planning/PRIVACY_POLICY_GENERATOR.md.
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/privacyHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/consentLayerHelpers.php';

const PRIVACY_BODY_METHODS = ['POST', 'PUT', 'PATCH'];

function privacyApiEndpointsPath(): string {
    return PROJECT_PATH . '/data/api-endpoints.json';
}

/** The `apis` map from data/api-endpoints.json ({} when absent/invalid). */
function privacyLoadApis(): array {
    $path = privacyApiEndpointsPath();
    if (!file_exists($path)) return [];
    $decoded = json_decode((string) @file_get_contents($path), true);
    return (is_array($decoded) && isset($decoded['apis']) && is_array($decoded['apis'])) ? $decoded['apis'] : [];
}

/**
 * Enumerate every endpoint with its outbound field atoms. Each entry:
 *   { apiId, endpointId, key:"apiId/endpointId", name, method, path, baseUrl,
 *     fields:[fieldName,...], undeclaredBody:bool }
 */
function privacyScanEndpoints(): array {
    $apis = privacyLoadApis();
    $out = [];
    foreach ($apis as $apiId => $api) {
        if (!is_array($api)) continue;
        $baseUrl = is_string($api['baseUrl'] ?? null) ? $api['baseUrl'] : '';
        $endpoints = (isset($api['endpoints']) && is_array($api['endpoints'])) ? $api['endpoints'] : [];
        foreach ($endpoints as $ep) {
            if (!is_array($ep)) continue;
            $epId = $ep['id'] ?? ($ep['name'] ?? ($ep['path'] ?? null));
            if (!is_string($epId) || $epId === '') continue;
            $method = strtoupper((string) ($ep['method'] ?? 'GET'));

            $fields = [];
            if (isset($ep['parameters']) && is_array($ep['parameters'])) {
                foreach ($ep['parameters'] as $p) {
                    if (is_array($p) && isset($p['name']) && is_string($p['name']) && $p['name'] !== '') {
                        $fields[$p['name']] = true;
                    }
                }
            }
            $hasReqSchema = isset($ep['requestSchema']['properties'])
                && is_array($ep['requestSchema']['properties'])
                && !empty($ep['requestSchema']['properties']);
            if ($hasReqSchema) {
                foreach (array_keys($ep['requestSchema']['properties']) as $fn) {
                    if (is_string($fn) && $fn !== '') $fields[$fn] = true;
                }
            }

            $out[] = [
                'apiId'          => (string) $apiId,
                'endpointId'     => $epId,
                'key'            => $apiId . '/' . $epId,
                'name'           => is_string($ep['name'] ?? null) ? $ep['name'] : $epId,
                'method'         => $method,
                'path'           => is_string($ep['path'] ?? null) ? $ep['path'] : '',
                'baseUrl'        => $baseUrl,
                'fields'         => array_keys($fields),
                // Body-bearing method with no declared request body = blind spot.
                'undeclaredBody' => in_array($method, PRIVACY_BODY_METHODS, true) && !$hasReqSchema,
            ];
        }
    }
    return $out;
}

/** Distinct baseUrls with the apiIds that use each: { baseUrl: [apiId,...] }. */
function privacyScanHosts(): array {
    $apis = privacyLoadApis();
    $hosts = [];
    foreach ($apis as $apiId => $api) {
        if (!is_array($api)) continue;
        $b = is_string($api['baseUrl'] ?? null) ? $api['baseUrl'] : '';
        if ($b === '') continue;
        if (!isset($hosts[$b])) $hosts[$b] = [];
        $hosts[$b][] = (string) $apiId;
    }
    return $hosts;
}

// ---- known-flow auto-seed (OAuth + magic-link) ----------------------------

/** Structure JSON files a verb chain could live in (pages/menu/footer/components). */
function privacyStructureFiles(): array {
    $files = [];
    $pagesDir = PROJECT_PATH . '/templates/model/json/pages';
    if (is_dir($pagesDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'json') $files[] = $f->getPathname();
        }
    }
    foreach (['menu.json', 'footer.json'] as $n) {
        $p = PROJECT_PATH . '/templates/model/json/' . $n;
        if (file_exists($p)) $files[] = $p;
    }
    $compDir = PROJECT_PATH . '/templates/model/json/components';
    if (is_dir($compDir)) {
        foreach (glob($compDir . '/*.json') as $f) $files[] = $f;
    }
    return $files;
}

/** Magic-link is "wired" when its qs.js verbs appear in any structure chain. */
function privacyDetectMagicLink(): bool {
    $needles = ['requestMagicLink', 'exchangeMagicLink'];
    foreach (privacyStructureFiles() as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) continue;
        foreach ($needles as $n) {
            if (strpos($raw, $n) !== false) return true;
        }
    }
    return false;
}

/**
 * Auto-seed suggestions from KNOWN auth flows — fields QuickSite already knows
 * the shape of (unlike arbitrary API schemas). OAuth + magic-link both collect a
 * fixed field set; OAuth providers also feed the page's third-party links section
 * (consentOAuthLinks, reused at generation time). Suggestions are datum-id slugs
 * the admin offers to one-click add; nothing is written here.
 */
function privacyAuthSeed(): array {
    $oauthLinks = function_exists('consentOAuthLinks') ? consentOAuthLinks() : [];
    $oauthWired = !empty($oauthLinks);
    $magicWired = privacyDetectMagicLink();
    return [
        'oauth' => [
            'wired'                => $oauthWired,
            'providers'            => $oauthLinks, // [{name, url|null}]
            'collectedSuggestions' => $oauthWired ? ['email', 'name', 'provider-account-id'] : [],
        ],
        'magicLink' => [
            'wired'                => $magicWired,
            'collectedSuggestions' => $magicWired ? ['email'] : [],
        ],
    ];
}

/**
 * Build the full privacy status report — the registry state joined with the live
 * API scan + coverage counts + the auth auto-seed + the cookie cross-link signal.
 */
function privacyBuildStatus(): array {
    $reg = loadPrivacyRegistry();
    $descLang = privacyDescLang($reg);

    // Collected data resolved in the description language.
    $collected = [];
    foreach ($reg['collectedData'] as $id) {
        $collected[] = [
            'id'      => (string) $id,
            'label'   => privacyDatumLabel((string) $id, $descLang) ?? '',
            'purpose' => privacyDatumPurpose((string) $id, $descLang) ?? '',
        ];
    }

    // Endpoints + per-field mapping + coverage.
    $totalAtoms = 0;
    $mappedAtoms = 0;
    $unmapped = [];
    $undeclared = [];
    $epOut = [];
    foreach (privacyScanEndpoints() as $ep) {
        $fieldsOut = [];
        foreach ($ep['fields'] as $f) {
            $datum = privacyGetMapping($reg, $ep['key'], $f);
            $totalAtoms++;
            if ($datum !== null) {
                $mappedAtoms++;
            } else {
                $unmapped[] = ['endpoint' => $ep['key'], 'field' => $f];
            }
            $fieldsOut[] = ['field' => $f, 'datum' => $datum];
        }
        if ($ep['undeclaredBody']) {
            $undeclared[] = ['endpoint' => $ep['key'], 'method' => $ep['method']];
        }
        $ep['fields'] = $fieldsOut;
        $epOut[] = $ep;
    }

    // Hosts + classification.
    $hostsOut = [];
    $unclassified = 0;
    foreach (privacyScanHosts() as $baseUrl => $apiIds) {
        $kind = privacyHostKind($reg, $baseUrl);
        if ($kind === null) $unclassified++;
        $entry = ['baseUrl' => $baseUrl, 'kind' => $kind, 'apiIds' => $apiIds];
        if ($kind === 'third-party') {
            $entry['name']       = $reg['hosts'][$baseUrl]['name'] ?? '';
            $entry['privacyUrl'] = $reg['hosts'][$baseUrl]['privacyUrl'] ?? '';
        }
        $hostsOut[] = $entry;
    }

    // Privacy-page existence + cookie-page cross-link signal.
    $privacyRoute = $reg['privacyRoute'];
    $privacyRouteExists = (is_string($privacyRoute) && trim($privacyRoute, '/') !== '')
        ? consentRouteExists($privacyRoute) : false;
    $cookieCfg = loadConsentConfig();
    $cookieRoute = $cookieCfg['policyRoute'] ?? null;
    $cookieRouteExists = (is_string($cookieRoute) && trim($cookieRoute, '/') !== '')
        ? consentRouteExists($cookieRoute) : false;

    $languages = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
        ? array_values(array_filter(CONFIG['LANGUAGES_SUPPORTED'], fn($l) => is_string($l) && $l !== '' && $l !== 'default'))
        : [$descLang];
    if (empty($languages)) $languages = [$descLang];

    return [
        'descLang'      => $descLang,
        'languages'     => $languages,
        'collectedData' => $collected,
        'hosts'         => $hostsOut,
        'endpoints'     => $epOut,
        'coverage'      => [
            'totalAtoms'          => $totalAtoms,
            'mappedAtoms'         => $mappedAtoms,
            'unmappedAtoms'       => count($unmapped),
            'unmapped'            => $unmapped,
            'undeclaredEndpoints' => $undeclared,
            'unclassifiedHosts'   => $unclassified,
            'complete'            => (count($unmapped) === 0 && count($undeclared) === 0 && $unclassified === 0),
        ],
        'authSeed'           => privacyAuthSeed(),
        'privacyRoute'       => $privacyRoute,
        'privacyRouteExists' => $privacyRouteExists,
        'cookieSection'      => $reg['cookieSection'],
        'cookie'             => ['policyRoute' => $cookieRoute, 'policyRouteExists' => $cookieRouteExists],
    ];
}
