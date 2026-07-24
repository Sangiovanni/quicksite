<?php
/**
 * projectPublicArtifacts.php (beta.10 C9) — per-project generated public artifacts.
 *
 * Each project's OWN `secure/projects/<id>/public/` is authoritative and is served live
 * at `/p/<id>/`. The generated client artifacts (qs-api-config.js / qs-enums.js /
 * qs-route-schema.js) live in the PROJECT'S own `public/scripts/`, and that is the only
 * copy: no project is privileged, so nothing is mirrored anywhere else (C15 15.3 deleted
 * the served-base dual-write along with the served project itself).
 *
 * Entry points:
 *   - qs_public_artifact_targets($relPath) / qs_write_public_artifact($relPath,$content):
 *       the absolute path a generated artifact must be written to in the CURRENT command
 *       context — the bound project's own folder. Used by the event-driven command writers.
 *   - qs_regenerate_project_scripts($projectPath,$projectName): (re)build all three
 *       qs-*.js into a project's own public/scripts/ from that project's own data.
 *       Used as the freshness / backfill safety-net when serving /p/<id>/.
 */

/**
 * Absolute write target for a generated public artifact in the CURRENT context.
 *
 * Returns an ARRAY (of exactly zero or one entries) because every caller iterates it: the
 * shape is kept so a future multi-target need does not have to reopen every writer, and so
 * "no project bound" is expressed as "nothing to write" rather than a path built from an
 * undefined constant. A global command binds no project and legitimately gets [].
 *
 * @param string $relPath e.g. 'scripts/qs-api-config.js'
 * @return string[] the project's own absolute path, or [] when no project is bound.
 */
function qs_public_artifact_targets(string $relPath): array {
    if (!defined('PROJECT_PATH')) {
        return [];
    }
    return [PROJECT_PATH . '/public/' . ltrim($relPath, '/\\')];
}

/**
 * Write $content to every target for $relPath (creating dirs). Returns true iff all ok.
 */
function qs_write_public_artifact(string $relPath, string $content): bool {
    $ok = true;
    foreach (qs_public_artifact_targets($relPath) as $target) {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $ok = false;
            continue;
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Copy a source file to every artifact target for $relPath (binary-safe; assets/favicon).
 *
 * A target identical to the source is SKIPPED, not copied onto itself: the common caller
 * writes the file to PUBLIC_CONTENT_PATH first and then calls this, and PUBLIC_CONTENT_PATH
 * IS the bound project's own public/ — so source and target coincide. copy() onto itself is
 * a truncation risk, never useful. Kept deliberately: the guard costs one realpath and is
 * what makes those callers safe to leave as they are.
 */
function qs_copy_public_artifact(string $sourceFile, string $relPath): bool {
    if (!is_file($sourceFile)) return false;
    $sourceReal = realpath($sourceFile);
    $ok = true;
    foreach (qs_public_artifact_targets($relPath) as $target) {
        $targetReal = realpath($target);
        if ($targetReal !== false && $sourceReal !== false && $targetReal === $sourceReal) {
            continue; // already the file we were asked to mirror
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $ok = false;
            continue;
        }
        if (!@copy($sourceFile, $target)) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Delete an artifact from every target for $relPath — the counterpart of
 * qs_copy_public_artifact (C8 8.1), so an asset removed through a command cannot survive
 * in a copy the command did not know about.
 *
 * Tolerant by design: a target that does not exist is not a failure.
 *
 * @return bool false only if a target exists and could not be unlinked.
 */
function qs_delete_public_artifact(string $relPath): bool {
    $ok = true;
    foreach (qs_public_artifact_targets($relPath) as $target) {
        if (is_file($target) && !@unlink($target)) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Event-driven emitters. A command that changes a project's API / routes / enums calls
 * these instead of writing a hard-coded path — the artifact lands in the bound project's
 * own public/, whichever project that is. Under C7 the command's PROJECT_PATH is the
 * project the request targeted, so this is correct for every project uniformly.
 */
function qs_emit_api_config(ApiEndpointManager $manager): bool {
    $ok = true;
    foreach (qs_public_artifact_targets('scripts/qs-api-config.js') as $target) {
        $dir = dirname($target);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!$manager->writeCompiledJs($target)) $ok = false;
    }
    return $ok;
}

function qs_emit_route_schema(array $routes): bool {
    if (!function_exists('writeRoutesMetaFile')) {
        require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
    }
    $ok = true;
    foreach (qs_public_artifact_targets('scripts/qs-route-schema.js') as $target) {
        $dir = dirname($target);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!writeRoutesMetaFile($routes, $target)) $ok = false;
    }
    return $ok;
}

function qs_emit_enums(?string $projectPath = null): array {
    require_once SECURE_FOLDER_PATH . '/src/classes/EnumSyncHelper.php';
    $projectPath = $projectPath ?? (defined('PROJECT_PATH') ? PROJECT_PATH : '');
    $result = ['ok' => true, 'written' => false, 'count' => 0];
    foreach (qs_public_artifact_targets('scripts') as $scriptsDir) {
        if (!is_dir($scriptsDir)) @mkdir($scriptsDir, 0755, true);
        $result = EnumSyncHelper::sync($projectPath, $scriptsDir);
    }
    return $result;
}

/**
 * (Re)generate all three qs-*.js into a project's OWN public/scripts/ from its own data.
 * Idempotent. Serve-time freshness / backfill net for /p/<id>/.
 *
 * @return array{api:bool,enums:bool,routes:bool,scriptsDir:string}
 */
function qs_regenerate_project_scripts(string $projectPath, string $projectName): array {
    $scriptsDir = $projectPath . '/public/scripts';
    if (!is_dir($scriptsDir)) {
        @mkdir($scriptsDir, 0755, true);
    }
    $res = ['api' => false, 'enums' => false, 'routes' => false, 'scriptsDir' => $scriptsDir];

    require_once SECURE_FOLDER_PATH . '/src/classes/ApiEndpointManager.php';
    $api = new ApiEndpointManager($projectPath);
    $res['api'] = $api->writeCompiledJs($scriptsDir . '/qs-api-config.js');

    require_once SECURE_FOLDER_PATH . '/src/classes/EnumSyncHelper.php';
    $enum = EnumSyncHelper::sync($projectPath, $scriptsDir);
    $res['enums'] = $enum['written'] ?? false;

    require_once SECURE_FOLDER_PATH . '/src/functions/routeHelpers.php';
    $routesFile = $projectPath . '/routes.php';
    if (is_file($routesFile)) {
        $routes = require $routesFile;
        if (is_array($routes)) {
            $res['routes'] = writeRoutesMetaFile($routes, $scriptsDir . '/qs-route-schema.js');
        }
    }
    return $res;
}

/**
 * True when any qs-*.js is missing, or older than a source that feeds it. Cheap
 * (stat-only). Directory sources are checked by their own mtime (catches add/remove of
 * components; a bare edit inside is covered by the editor-mode force-regen at serve time).
 */
function qs_project_scripts_stale(string $projectPath): bool {
    $scriptsDir = $projectPath . '/public/scripts';
    $generated = ['qs-api-config.js', 'qs-enums.js', 'qs-route-schema.js'];
    $genMtime = PHP_INT_MAX;
    foreach ($generated as $g) {
        $p = $scriptsDir . '/' . $g;
        if (!is_file($p)) {
            return true;
        }
        $genMtime = min($genMtime, (int) @filemtime($p));
    }
    $sources = [
        $projectPath . '/data/api-endpoints.json',
        $projectPath . '/routes.php',
        $projectPath . '/templates/model/json/components',
    ];
    foreach ($sources as $s) {
        $m = @filemtime($s);
        if ($m !== false && $m > $genMtime) {
            return true;
        }
    }
    return false;
}
