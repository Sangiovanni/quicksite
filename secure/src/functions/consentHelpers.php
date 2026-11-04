<?php
/**
 * consentHelpers.php — consent-layer config, and the payload the page hydrates
 * from, for the storage registry's GDPR consent layer.
 *
 * The consent layer is OFF until the author generates + enables it.
 * Per-project config lives in data/consent.json (author website data → JSON):
 *
 *   { "enabled": false, "policyRoute": null, "version": 1 }
 *
 *   enabled      — is the consent rule + the banner active for this project
 *   policyRoute  — route the generated cookie-policy page lives at
 *   version      — a bump asks every visitor again (generateConsentLayer's
 *                  askAgain); a change to a category's declared keys asks again
 *                  for that category by itself (qs_consent_fingerprints())
 *
 * When enabled, the page emits window.QS_CONSENT carrying the key→category map
 * and one fingerprint per non-essential category, so qs.js reads, writes and
 * keeps a non-essential key only while the visitor consents to its category
 * under that category's fingerprint now (the consent cookie records the
 * fingerprints a choice was made under).
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/storageHelpers.php';

const CONSENT_CONFIG_DEFAULTS = ['enabled' => false, 'policyRoute' => null, 'version' => 1];

function getConsentConfigPath(): string {
    return PROJECT_PATH . '/data/consent.json';
}

/** Load the consent config, merged over defaults. Missing/invalid → disabled. */
function loadConsentConfig(): array {
    $path = getConsentConfigPath();
    if (!file_exists($path)) {
        return CONSENT_CONFIG_DEFAULTS;
    }
    $raw = @file_get_contents($path);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return CONSENT_CONFIG_DEFAULTS;
    }
    return array_merge(CONSENT_CONFIG_DEFAULTS, $decoded);
}

/** Persist the consent config. Creates data/ if missing. */
function saveConsentConfig(array $config): bool {
    $path = getConsentConfigPath();
    $merged = array_merge(CONSENT_CONFIG_DEFAULTS, $config);
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    // The size check, write by write (it runs inside chains of helpers); a
    // refusal is answered by the command (qs_small_write_refused).
    require_once __DIR__ . '/quota.php';
    if (!qs_small_write_guard($path, qs_json_size($merged, $flags, "\n"))) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // qs_json_write, not json_encode() . "\n": a failed encode must leave the
    // file as it is, not write a lone newline over it.
    return qs_json_write($path, $merged, $flags, LOCK_EX, "\n");
}

/** key => category map from the registry — drives client-side write gating. */
function consentCategoryMap(): array {
    $items = loadStorageRegistry()['items'];
    $map = [];
    foreach ($items as $key => $item) {
        if (is_array($item)) {
            $map[$key] = $item['category'] ?? 'functional';
        }
    }
    return $map;
}

/**
 * The consent payload for THIS project, or null when the layer is off.
 *
 * Split out from the emitter so a production build can precompute it. Deriving
 * the payload reads data/consent.json and walks the storage registry — that is
 * authoring code, it pulls in translationHelpers and the shared utility drawer,
 * and none of it belongs in a deployed site. The answer only changes when the
 * author edits the consent config or the registry, so the build computes it
 * once and ships the result as data.
 *
 * @return array{enabled: bool, version: int, categories: array<string,string>, fingerprints: array<string,string>}|null
 */
function qs_consent_payload(): ?array
{
    $cfg = loadConsentConfig();
    if (empty($cfg['enabled'])) {
        return null;
    }
    $version = (int) ($cfg['version'] ?? 1);
    $categories = consentCategoryMap();
    return [
        'enabled'      => true,
        'version'      => $version,
        'categories'   => $categories,
        'fingerprints' => qs_consent_fingerprints($version, $categories),
    ];
}

/**
 * One fingerprint per category a visitor chooses (every category but essential): the stored
 * version and the category's declared keys, sorted. A consent counts only while the fingerprint
 * recorded with it is the category's fingerprint now, so a key added to, moved into or out of,
 * or removed from a category asks the visitor again for that category alone; a version bump
 * asks again for all of them; an essential key asks nothing. A key's retention, scope and
 * description are not part of it: the version is bumped when one of them must ask again.
 *
 * @param array<string,string> $categories key → category (consentCategoryMap())
 * @return array<string,string> category → 16 hex characters
 */
function qs_consent_fingerprints(int $version, array $categories): array
{
    $out = [];
    foreach (STORAGE_CATEGORIES as $category) {
        if ($category === 'essential') {
            continue;
        }
        $keys = [];
        foreach ($categories as $key => $keyCategory) {
            if ($keyCategory === $category) {
                $keys[] = (string) $key;
            }
        }
        sort($keys, SORT_STRING);
        // A declared key holds no whitespace (validateStorageItem()), so the line break
        // separates the keys without ambiguity.
        $out[$category] = substr(hash('sha256', $version . "\n" . $category . "\n" . implode("\n", $keys)), 0, 16);
    }
    return $out;
}
