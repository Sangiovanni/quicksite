<?php
/**
 * Project CONTAINMENT helpers (beta.10 C8).
 *
 * A project-scoped command is authorized by the dispatcher against the project
 * marker in the URL ('/management/p/<projectId>/<command>', see
 * public/management/index.php) BEFORE the command runs. The command must then act
 * on THAT project and no other — otherwise the marker is authorized and ignored,
 * and a request-body value silently retargets the action at a project the caller
 * was never authorized for (the confused-deputy class closed across C8).
 *
 * These helpers are the single implementation of that rule, plus the per-project
 * storage derivation that keeps generated archives from sharing one namespace.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * The authorized project marker for this request, or '' when none is bound.
 *
 * @return string
 */
function qs_marker_project(): string {
    return defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
}

/**
 * Bind a command's target project to the authorized URL marker.
 *
 * The marker is the ONLY source of the target. A project named in the request is
 * accepted as an optional, redundant echo and must match; a disagreeing value is
 * refused rather than silently ignored, so a caller attempting to retarget gets a
 * hard error instead of a surprising success on the wrong project.
 *
 * Usage:
 *   $bound = qs_bind_marker_project($params, 'createSnippet');
 *   if ($bound['refusal'] !== null) { return $bound['refusal']; }
 *   $projectName = $bound['project'];
 *
 * @param array    $params  Command params (body+query already merged by the caller)
 * @param string   $command Command name, for the error message
 * @param string[] $fields  Param names that may echo the project, in priority order
 * @return array{project:string, refusal:?ApiResponse}
 */
function qs_bind_marker_project(array $params, string $command, array $fields = ['project']): array {
    $marker = qs_marker_project();
    if ($marker === '') {
        // No marker ⇒ the dispatcher authorized nothing. Never fall back to a
        // served-main pointer or a per-user default: neither was authorized.
        return ['project' => '', 'refusal' => ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/' . $command)];
    }

    foreach ($fields as $field) {
        $echo = trim((string)($params[$field] ?? ''));
        if ($echo !== '' && $echo !== $marker) {
            return ['project' => '', 'refusal' => ApiResponse::create(400, 'project.mismatch')
                ->withMessage('The targeted project does not match the project in the request')
                ->withErrors([$field => "Targeted '{$marker}' but the request named '{$echo}'"])];
        }
    }

    return ['project' => $marker, 'refusal' => null];
}

/**
 * The per-project export directory.
 *
 * Exports live UNDER their project (not in one installation-wide folder) so that
 * an archive is reachable only through its own project's marker. A shared folder
 * made every export addressable from any authorized marker, which turned
 * downloadExport into a cross-project read and clearExports into a cross-project
 * delete (C8 8.5 findings 2 and 3).
 *
 * Callers must pass a project id already validated by is_valid_project_name().
 *
 * @param string $project Validated project id
 * @return string Absolute path (not guaranteed to exist yet)
 */
function qs_project_exports_dir(string $project): string {
    return SECURE_FOLDER_PATH . '/projects/' . $project . '/exports';
}

/**
 * Ensure the per-project export directory exists.
 *
 * @param string $project Validated project id
 * @return string|null The directory path, or null when it could not be created
 */
function qs_ensure_project_exports_dir(string $project): ?string {
    $dir = qs_project_exports_dir($project);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    return $dir;
}
