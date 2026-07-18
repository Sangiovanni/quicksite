<?php
/**
 * projectPublicArtifacts.php (beta.10 C9) — per-project generated public artifacts.
 *
 * C9 makes each project's OWN `secure/projects/<id>/public/` authoritative and serves
 * it live at `/p/<id>/`. The generated client artifacts (qs-api-config.js / qs-enums.js
 * / qs-route-schema.js) therefore must live in the PROJECT'S own `public/scripts/`, not
 * only in the base live `public/` (which is reserved to the served base project
 * `quicksite` — Sangio 2026-07-10; C9 §8.9 D1/D2/D6).
 *
 * Entry points:
 *   - qs_public_artifact_targets($relPath) / qs_write_public_artifact($relPath,$content):
 *       the absolute path(s) a generated artifact must be written to in the CURRENT
 *       command context — the edited project's own folder ALWAYS, PLUS the base live
 *       public/ when the edited project IS the reserved base (D2 "write both + stay in
 *       sync"). Used by the event-driven command writers.
 *   - qs_regenerate_project_scripts($projectPath,$projectName): (re)build all three
 *       qs-*.js into a project's own public/scripts/ from that project's own data.
 *       Used as the freshness / backfill safety-net when serving /p/<id>/.
 */

/**
 * The project currently SERVED at the site root (BASE_URL/) — target.php. Its public IS
 * the base live public/ (PUBLIC_CONTENT_PATH, DOCUMENT_ROOT); every OTHER project serves
 * from its own folder at /p/<id>/. This is DYNAMIC (switchProject can repoint it) — not a
 * hardcoded name — so the base-mirror + the /p/ redirect + the per-project dispatcher
 * override all track whatever is actually served (C9 §8.9 D2, corrected 2026-07-10 after
 * Sangio's browser test surfaced switchProject still repoints the served project).
 * 'quicksite' remains a reserved NAME (createProject) but is not hardcoded as the base.
 *
 * @param string|null $secure  SECURE_FOLDER_PATH (pass explicitly pre-init).
 */
function qs_served_project(?string $secure = null): ?string {
    $secure = $secure ?? (defined('SECURE_FOLDER_PATH') ? SECURE_FOLDER_PATH : null);
    if ($secure === null) return null;
    $tf = $secure . '/management/config/target.php';
    if (!is_file($tf)) return null;
    $t = @include $tf;
    if (is_array($t)) return $t['project'] ?? null;
    if (is_string($t) && $t !== '') return $t;
    return null;
}

/**
 * Absolute write target(s) for a generated public artifact in the CURRENT context.
 *
 * @param string $relPath e.g. 'scripts/qs-api-config.js'
 * @return string[] one or two absolute paths (project folder [+ base live for quicksite]).
 */
function qs_public_artifact_targets(string $relPath): array {
    $relPath = ltrim($relPath, '/\\');
    $targets = [];
    if (defined('PROJECT_PATH')) {
        $targets[] = PROJECT_PATH . '/public/' . $relPath;
    }
    // Served-base mirror: editing the SERVED project ALSO refreshes the base live public/
    // so the root deployment (BASE_URL/) stays in sync (D2). When editing the served
    // project the dispatcher does NOT override PUBLIC_CONTENT_PATH, so it is the base
    // there; editing a NON-served project overrides it to that project's own public, and
    // PROJECT_NAME !== served, so no base mirror (no cross-project clobber).
    if (defined('PROJECT_NAME') && defined('PUBLIC_CONTENT_PATH') && PROJECT_NAME === qs_served_project()) {
        $base = PUBLIC_CONTENT_PATH . '/' . $relPath;
        if (!in_array($base, $targets, true)) {
            $targets[] = $base;
        }
    }
    // Fallback for any non-C7 context where PROJECT_PATH is unset but a base exists.
    if (empty($targets) && defined('PUBLIC_CONTENT_PATH')) {
        $targets[] = PUBLIC_CONTENT_PATH . '/' . $relPath;
    }
    return $targets;
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
 * A target identical to the source is SKIPPED, not copied onto itself: the common
 * caller writes the file to PUBLIC_CONTENT_PATH first and then mirrors it, and for a
 * NON-served project PUBLIC_CONTENT_PATH already IS the project's own public/ — so
 * source and target coincide. copy() onto itself is a truncation risk, never useful.
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
 * Delete an artifact from every target for $relPath — the mirror-aware counterpart of
 * qs_copy_public_artifact (C8 8.1). Without this, deleting an asset while editing the
 * SERVED project removes it from the base live public/ but leaves the copy in the
 * project's own folder, which then resurrects on the next serve/switch.
 *
 * Tolerant by design: a target that does not exist is not a failure (the mirror may
 * never have been written for a pre-8.1 asset).
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
 * Event-driven emitters (D2 "write both + stay in sync"). A command that changes a
 * project's API / routes / enums calls these instead of writing a single hard-coded
 * PUBLIC_CONTENT_PATH path — the artifact lands in the edited project's own public/
 * ALWAYS, plus the base live public/ when the edited project is the reserved base
 * (quicksite). Under C7 the command's PROJECT_PATH/PROJECT_NAME is the edited project,
 * so this Just Works for both the served base and any /p/<id>/ project.
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
 * Idempotent. Serve-time freshness / backfill net for /p/<id>/. Does NOT mirror to the
 * base (serving never edits — mirroring is the command path's job via the helpers above).
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
