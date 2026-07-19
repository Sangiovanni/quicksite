<?php
/**
 * getMySpaceUsage - Disk footprint of the projects the CALLER OWNS
 *
 * @method POST
 * @route /management/getMySpaceUsage
 * @auth required (projects.list — global, any authenticated user)
 *
 * @param bool $refresh Optional. Bypass the measurement cache and re-walk the disk.
 *
 * Answers "how much space do my projects use", aggregated across every project
 * where the caller's role is `owner`, plus a per-project breakdown.
 *
 * GLOBAL on purpose: an owner-wide total is not a fact about any single project,
 * so it cannot be targeted by a `/management/p/<id>/` marker. It takes NO project
 * parameter at all — there is nothing to retarget. Like listProjects, the command
 * is open to any authenticated user and its OUTPUT is what is filtered: ownership
 * is resolved per project from the authoritative members.json, so the response can
 * only ever describe projects the caller owns. A caller who owns nothing gets an
 * empty, zeroed report — never a hint that other projects exist. This is
 * deliberately NOT the installation-wide enumeration that getSizeInfo used to
 * perform (C8 8.5 F-C8-8.5-4); no project the caller has no relationship with is
 * named, counted, or implied.
 *
 * Sizes come from a short-lived shared cache (see spaceUsage.php). The project SET
 * is never cached, so ownership changes land immediately; only byte counts can age,
 * and `refresh=true` forces a re-walk.
 *
 * @return ApiResponse Owner-wide totals + per-project rows
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/spaceUsage.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getMySpaceUsage(array $params = [], array $urlParams = []): ApiResponse {
    $params  = array_merge($_GET, $params);
    $refresh = filter_var($params['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // No resolvable caller (e.g. an in-process run with no request context) →
    // empty report, fail-closed. Mirrors listProjects.
    $user   = getCurrentUser();
    $userId = (string)($user['id'] ?? '');

    $owned = $userId !== '' ? qs_owned_projects($userId) : [];

    // A deleted project must not leave a measurement behind until its TTL lapses.
    qs_prune_space_cache();

    $projects   = [];
    $totContent = 0;
    $totBackups = 0;
    $totExports = 0;
    $totBuilds  = 0;
    $anyStale   = false;

    foreach ($owned as $project) {
        $space = qs_project_space($project, $refresh);

        $totContent += (int)$space['content'];
        $totBackups += (int)$space['backups']['size'];
        $totExports += (int)$space['exports']['size'];
        $totBuilds  += (int)$space['builds']['size'];
        if (!empty($space['cached'])) {
            $anyStale = true;
        }

        $projects[] = [
            'name'           => $project,
            'total'          => (int)$space['total'],
            'total_formatted'=> qs_format_size((int)$space['total']),
            'content'        => (int)$space['content'],
            'backups'        => [
                'size'           => (int)$space['backups']['size'],
                'size_formatted' => qs_format_size((int)$space['backups']['size']),
                'count'          => (int)$space['backups']['count'],
            ],
            'exports'        => [
                'size'           => (int)$space['exports']['size'],
                'size_formatted' => qs_format_size((int)$space['exports']['size']),
                'count'          => (int)$space['exports']['count'],
            ],
            'builds'         => [
                'size'           => (int)$space['builds']['size'],
                'size_formatted' => qs_format_size((int)$space['builds']['size']),
            ],
            'measured_at'    => (int)$space['measured_at'],
        ];
    }

    // Largest first — the point of the panel is "what is eating my space".
    usort($projects, fn($a, $b) => $b['total'] <=> $a['total']);

    $grand = $totContent + $totBackups + $totExports + $totBuilds;

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Owner space usage retrieved successfully')
        ->withData([
            'total' => [
                'size'           => $grand,
                'size_formatted' => qs_format_size($grand),
            ],
            // Same shape as getSizeInfo's by_category so the dashboard's stacked
            // bar renders owner-scale without a second component.
            'by_category' => [
                'content' => ['size' => $totContent, 'size_formatted' => qs_format_size($totContent)],
                'backups' => ['size' => $totBackups, 'size_formatted' => qs_format_size($totBackups)],
                'builds'  => ['size' => $totBuilds,  'size_formatted' => qs_format_size($totBuilds)],
                'exports' => ['size' => $totExports, 'size_formatted' => qs_format_size($totExports)],
            ],
            'projects'      => $projects,
            'project_count' => count($projects),
            'cache'         => [
                'ttl'      => QS_SPACE_CACHE_TTL,
                'from_cache' => $anyStale,
                'refreshed'  => $refresh,
            ],
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getMySpaceUsage($trimParams->params(), $trimParams->additionalParams())->send();
}
