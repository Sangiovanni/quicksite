<?php
/**
 * consentHelpers.php — consent-layer config + client hydration for the storage
 * registry's GDPR consent layer (beta.9, Phase 2).
 *
 * The consent layer is OFF until the author generates + enables it (slice 7).
 * Per-project config lives in data/consent.json (author website data → JSON):
 *
 *   { "enabled": false, "policyRoute": null, "version": 1 }
 *
 *   enabled      — is runtime write-gating + the banner active for this project
 *   policyRoute  — route the generated cookie-policy page lives at (slice 8)
 *   version      — bump (or a registry category change) re-prompts the visitor
 *
 * When enabled, the page emits window.QS_CONSENT carrying the key→category map
 * so qs.js can gate non-essential storage writes against the consent_prefs
 * cookie. See NOTES/planning/BETA9_STORAGE_REGISTRY.md (Phase 2 build spec).
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
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $merged = array_merge(CONSENT_CONFIG_DEFAULTS, $config);
    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
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
 * The <script> hydration tag for the consent runtime, or '' when the layer is
 * disabled (nothing emitted → qs.js gating stays dormant). Live-site only;
 * callers skip this in editor mode.
 */
function consentHydrationScript(): string {
    $cfg = loadConsentConfig();
    if (empty($cfg['enabled'])) {
        return '';
    }
    $payload = [
        'enabled'    => true,
        'version'    => (int) ($cfg['version'] ?? 1),
        'categories' => consentCategoryMap(),
    ];
    return '<script>window.QS_CONSENT=' . json_encode($payload, JSON_UNESCAPED_SLASHES) . ';</script>';
}
