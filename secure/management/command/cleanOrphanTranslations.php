<?php
/**
 * cleanOrphanTranslations - Deletes translation files for languages no longer
 * declared in LANGUAGES_SUPPORTED.
 *
 * @method DELETE
 * @url /management/cleanOrphanTranslations
 * @auth required
 * @permission write
 *
 * Translation files are stored in `<project>/translate/<lang>.json`. The project
 * config (`config.php`) declares which languages are active in
 * `LANGUAGES_SUPPORTED`. When a language is removed from config without its file
 * being deleted (e.g. a previous `deleteLang` failure, a manual config edit, a
 * project import that didn't sync), the file becomes an ORPHAN. Subsequent
 * workflows that re-add the language and call `setTranslationKeys` in merge mode
 * collide with the leftover content (string-vs-branch shape conflicts in
 * particular).
 *
 * This command finds those orphans and deletes them. `default.json` is always
 * preserved (it's the mono-language fallback, not a language-specific file).
 *
 * Used by the `fresh-start` workflow as PHASE 4b to make "fresh start" mean
 * fresh on disk, not just fresh in config.
 *
 * Parameters (all optional, accepted via POST/DELETE body):
 *   - dry_run (bool, default false) — list orphans but don't delete.
 *
 * Returns:
 *   {
 *     cleaned: [<lang>...],          // base names of deleted files (or would-be in dry_run)
 *     kept:    [<lang>...],          // base names of files preserved (in config + 'default')
 *     orphans_found: int,
 *     dry_run: bool,
 *     project: string
 *   }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

function __command_cleanOrphanTranslations(array $params = [], array $urlParams = []): ApiResponse {
    $dryRun = !empty($params['dry_run']);

    // Sanity check: PROJECT_PATH must be defined and translate dir must be reachable
    if (!defined('PROJECT_PATH')) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('PROJECT_PATH not defined');
    }

    $translateDir = PROJECT_PATH . '/translate';
    if (!is_dir($translateDir)) {
        // Not a fatal — a project with no translate dir has no orphans by definition
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No translate directory; nothing to clean')
            ->withData([
                'cleaned' => [],
                'kept' => [],
                'orphans_found' => 0,
                'dry_run' => $dryRun,
                'project' => basename(PROJECT_PATH),
            ]);
    }

    if (!defined('CONFIG') || !is_array(CONFIG)) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('CONFIG not loaded');
    }

    $supported = CONFIG['LANGUAGES_SUPPORTED'] ?? [];
    if (!is_array($supported)) {
        $supported = [];
    }

    // Build the "keep" set: every supported language code + the special 'default' file.
    // Using flip+isset for O(1) lookup; tolerates case-sensitivity differences by lowercasing.
    $keepSet = [];
    foreach ($supported as $code) {
        if (is_string($code) && $code !== '') {
            $keepSet[strtolower($code)] = true;
        }
    }
    $keepSet['default'] = true;

    $cleaned = [];
    $kept = [];
    $errors = [];

    // Iterate every <name>.json in the translate dir
    $files = glob($translateDir . '/*.json');
    if ($files === false) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage('Failed to enumerate translate directory');
    }

    foreach ($files as $file) {
        $base = basename($file, '.json');
        $baseLower = strtolower($base);

        if (isset($keepSet[$baseLower])) {
            $kept[] = $base;
            continue;
        }

        // Orphan — delete (or pretend to, in dry_run)
        if ($dryRun) {
            $cleaned[] = $base;
            continue;
        }

        if (@unlink($file)) {
            $cleaned[] = $base;
        } else {
            $errors[] = [
                'file' => basename($file),
                'reason' => 'unlink_failed',
            ];
        }
    }

    sort($cleaned);
    sort($kept);

    $payload = [
        'cleaned' => $cleaned,
        'kept' => $kept,
        'orphans_found' => count($cleaned) + count($errors),
        'dry_run' => $dryRun,
        'project' => basename(PROJECT_PATH),
    ];

    // Partial failure: some files we couldn't unlink. Return 207-ish via 200 + errors so
    // the caller (typically fresh-start) keeps running other phases, but the failure is visible.
    if (!empty($errors)) {
        return ApiResponse::create(200, 'operation.partial_success')
            ->withMessage(sprintf(
                'Cleaned %d orphan translation file(s); %d failed to delete',
                count($cleaned),
                count($errors)
            ))
            ->withData($payload)
            ->withErrors($errors);
    }

    $message = $dryRun
        ? sprintf('Dry run: %d orphan translation file(s) would be deleted', count($cleaned))
        : sprintf('Deleted %d orphan translation file(s)', count($cleaned));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($message)
        ->withData($payload);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $params = $trimParametersManagement->params();
    __command_cleanOrphanTranslations($params)->send();
}
