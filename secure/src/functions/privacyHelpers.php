<?php
/**
 * privacyHelpers.php — read / write the per-project privacy registry
 * (data/privacy.json). Structure-only (mirrors storage.json): the human-readable
 * collected-data labels + purposes live in translate/ under convention keys
 * (privacy.collected.<id>.label / .purpose), authored in the registry description
 * language and translated via the project language tool.
 *
 * Shape:
 *   {
 *     "descLang": "en",                          // language labels/purposes authored in
 *     "collectedData": ["email", "full-name"],   // datum ids (prose keyed in translate/)
 *     "mappings": {                               // atom (endpoint, field) -> datum id | null
 *       "<apiId>/<endpointId>": { "email": "email", "userId": null }
 *     },
 *     "hosts": {                                  // per baseUrl classification
 *       "https://api.example.com":  { "kind": "self" },
 *       "https://hooks.x.com":      { "kind": "third-party", "name": "X", "privacyUrl": "..." }
 *     },
 *     "privacyRoute": "/privacy" | null,          // null until the page is generated
 *     "cookieSection": "auto" | "omit"            // auto = link/hint the cookie page; omit = hide it
 *   }
 *
 * A datum's recipient is DERIVED, never stored: atom -> endpoint -> baseUrl ->
 * host classification. The mapping holds only (endpoint, field) -> datum id.
 *
 * See NOTES/planning/PRIVACY_POLICY_GENERATOR.md for the locked design.
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';

const PRIVACY_HOST_KINDS    = ['self', 'third-party'];
const PRIVACY_COOKIE_SECTION = ['auto', 'omit'];

function getPrivacyRegistryPath(): string {
    return PROJECT_PATH . '/data/privacy.json';
}

/** Load the registry, normalised to the full shape (safe defaults). */
function loadPrivacyRegistry(): array {
    $path = getPrivacyRegistryPath();
    $reg = [];
    if (file_exists($path)) {
        $raw = @file_get_contents($path);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $reg = $decoded;
        }
    }
    if (!isset($reg['collectedData']) || !is_array($reg['collectedData'])) $reg['collectedData'] = [];
    if (!isset($reg['mappings']) || !is_array($reg['mappings']))           $reg['mappings'] = [];
    if (!isset($reg['hosts']) || !is_array($reg['hosts']))                 $reg['hosts'] = [];
    if (!isset($reg['cookieSection']) || !in_array($reg['cookieSection'], PRIVACY_COOKIE_SECTION, true)) $reg['cookieSection'] = 'auto';
    if (!array_key_exists('privacyRoute', $reg))                           $reg['privacyRoute'] = null;
    return $reg;
}

/** Write the registry. Creates data/ if missing. Empty maps encode as {} not []. */
function savePrivacyRegistry(array $reg): bool {
    $path = getPrivacyRegistryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $cd = (isset($reg['collectedData']) && is_array($reg['collectedData']))
        ? array_values(array_unique(array_filter($reg['collectedData'], 'is_string'))) : [];
    sort($cd);

    $out = [];
    if (isset($reg['descLang']) && is_string($reg['descLang']) && $reg['descLang'] !== '') {
        $out['descLang'] = $reg['descLang'];
    }
    $out['collectedData'] = $cd;
    $out['mappings']      = (isset($reg['mappings']) && is_array($reg['mappings']) && !empty($reg['mappings'])) ? $reg['mappings'] : (object) [];
    $out['hosts']         = (isset($reg['hosts']) && is_array($reg['hosts']) && !empty($reg['hosts'])) ? $reg['hosts'] : (object) [];
    $out['cookieSection'] = (isset($reg['cookieSection']) && in_array($reg['cookieSection'], PRIVACY_COOKIE_SECTION, true)) ? $reg['cookieSection'] : 'auto';
    $out['privacyRoute']  = (isset($reg['privacyRoute']) && is_string($reg['privacyRoute']) && $reg['privacyRoute'] !== '') ? $reg['privacyRoute'] : null;

    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/** Language the collected-data prose is authored in (defaults to website default). */
function privacyDescLang(?array $reg = null): string {
    if ($reg === null) {
        $reg = loadPrivacyRegistry();
    }
    $l = $reg['descLang'] ?? null;
    if (is_string($l) && $l !== '') {
        return $l;
    }
    return (defined('CONFIG') && isset(CONFIG['LANGUAGE_DEFAULT']) && is_string(CONFIG['LANGUAGE_DEFAULT']))
        ? CONFIG['LANGUAGE_DEFAULT'] : 'en';
}

/** Project languages plus the synthetic `default` bucket. */
function privacyAllLangs(): array {
    $langs = (defined('CONFIG') && isset(CONFIG['LANGUAGES_SUPPORTED']) && is_array(CONFIG['LANGUAGES_SUPPORTED']))
        ? CONFIG['LANGUAGES_SUPPORTED'] : ['en'];
    $langs[] = 'default';
    return array_values(array_unique(array_filter($langs, fn($l) => is_string($l) && $l !== '')));
}

// ---- collected-data prose (translate/ convention keys) --------------------

function _privacySanitizeId(string $id): string {
    return preg_replace('/[^a-z0-9_]/i', '_', $id);
}
function privacyLabelKey(string $id): string {
    return 'privacy.collected.' . _privacySanitizeId($id) . '.label';
}
function privacyPurposeKey(string $id): string {
    return 'privacy.collected.' . _privacySanitizeId($id) . '.purpose';
}

/** Resolve a datum's label / purpose in $lang (raw — no cross-language fallback). */
function privacyDatumLabel(string $id, string $lang): ?string {
    return translationGetKey($lang, privacyLabelKey($id));
}
function privacyDatumPurpose(string $id, string $lang): ?string {
    return translationGetKey($lang, privacyPurposeKey($id));
}

/** Write a datum's label + purpose into translate/<lang> (skips empty values). */
function privacyWriteDatumText(string $id, string $lang, ?string $label, ?string $purpose): void {
    $flat = [];
    if (is_string($label) && $label !== '')     $flat[privacyLabelKey($id)] = $label;
    if (is_string($purpose) && $purpose !== '') $flat[privacyPurposeKey($id)] = $purpose;
    if (!empty($flat)) {
        writeTranslationsToFile($lang, convertDotNotationToNested($flat), false);
    }
}

/** Remove a datum's label + purpose keys from every project language. */
function privacyClearDatumText(string $id): void {
    foreach (privacyAllLangs() as $lang) {
        translationUnsetKey($lang, privacyLabelKey($id));
        translationUnsetKey($lang, privacyPurposeKey($id));
    }
}

// ---- collected-data CRUD --------------------------------------------------

function privacyAddDatum(array &$reg, string $id): bool {
    if ($id === '' || in_array($id, $reg['collectedData'], true)) {
        return false;
    }
    $reg['collectedData'][] = $id;
    return true;
}

/** Remove a datum + null out any mappings that referenced it. */
function privacyRemoveDatum(array &$reg, string $id): void {
    $reg['collectedData'] = array_values(array_filter($reg['collectedData'], fn($d) => $d !== $id));
    foreach ($reg['mappings'] as $endpoint => $fields) {
        if (!is_array($fields)) continue;
        foreach ($fields as $field => $datum) {
            if ($datum === $id) {
                $reg['mappings'][$endpoint][$field] = null;
            }
        }
    }
}

// ---- atom -> datum mapping ------------------------------------------------

/** Set the datum a scanned atom (endpoint, field) maps to. null = unset. */
function privacySetMapping(array &$reg, string $endpoint, string $field, ?string $datumId): void {
    if (!isset($reg['mappings'][$endpoint]) || !is_array($reg['mappings'][$endpoint])) {
        $reg['mappings'][$endpoint] = [];
    }
    $reg['mappings'][$endpoint][$field] = $datumId;
}
function privacyGetMapping(array $reg, string $endpoint, string $field): ?string {
    $d = $reg['mappings'][$endpoint][$field] ?? null;
    return is_string($d) && $d !== '' ? $d : null;
}

// ---- per-baseUrl host classification --------------------------------------

/** Classify a host. kind = self | third-party; name/url stored for third parties. */
function privacySetHost(array &$reg, string $baseUrl, string $kind, ?string $name = null, ?string $privacyUrl = null): void {
    $kind = in_array($kind, PRIVACY_HOST_KINDS, true) ? $kind : 'self';
    $h = ['kind' => $kind];
    if ($kind === 'third-party') {
        if (is_string($name) && $name !== '')             $h['name'] = $name;
        if (is_string($privacyUrl) && $privacyUrl !== '') $h['privacyUrl'] = $privacyUrl;
    }
    $reg['hosts'][$baseUrl] = $h;
}

/** Classification of a host, or null when not yet classified. */
function privacyHostKind(array $reg, string $baseUrl): ?string {
    $k = $reg['hosts'][$baseUrl]['kind'] ?? null;
    return in_array($k, PRIVACY_HOST_KINDS, true) ? $k : null;
}

// ---- description-language move (mirrors storage; empty-not-delete) ---------

/**
 * Move every datum's label + purpose from language $from to $to in the translate
 * files. EMPTIES the source key (keeps it as "") so the old language is flagged
 * as a missing translation rather than deleted (locked rule, shared with storage).
 *
 * @return array{moved:int, overwrites:int}
 */
function privacyMoveDescriptions(string $from, string $to, bool $execute = true): array {
    if ($from === $to) {
        return ['moved' => 0, 'overwrites' => 0];
    }
    $reg = loadPrivacyRegistry();
    $moved = 0;
    $overwrites = 0;
    foreach ($reg['collectedData'] as $id) {
        foreach ([privacyLabelKey((string) $id), privacyPurposeKey((string) $id)] as $key) {
            $val = translationGetKey($from, $key);
            if ($val === null || $val === '') continue;
            $existing = translationGetKey($to, $key);
            if ($existing !== null && $existing !== '') $overwrites++;
            $moved++;
            if ($execute) {
                writeTranslationsToFile($to, convertDotNotationToNested([$key => $val]), false);
                translationSetKey($from, $key, '');
            }
        }
    }
    return ['moved' => $moved, 'overwrites' => $overwrites];
}
