<?php
/**
 * getSocialPreview Command
 *
 * Reads what a shared link's card is drawn from, and what each page's tags will say: the site's social values;
 * each page's values (or one page's, when `route` is given), with its picture as Media holds it; per language,
 * the page's title and description and the description, Open Graph and X tags it will carry, made by the one
 * producer a served page calls (qs_social_tag_list()); the warnings per platform; and the platform table the
 * warnings come from, each figure with its source and the date it was read.
 *
 * The tags' addresses are composed for this request's host and the project's `/p/<projectId>/` base, as the
 * live render composes them (a deployment's QS_PUBLIC_BASE_URL included); a build composes its own when served.
 * A parameter's placeholder stays as written: it is filled per request.
 *
 * In `content.read` (every role).
 *
 * @method GET
 * @route /management/p/<projectId>/getSocialPreview[?route=<route>]
 * @auth required (content.read)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';      // qs_page_name_refusal, SPECIAL_PAGES
require_once SECURE_FOLDER_PATH . '/src/functions/socialPreviewHelpers.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params    Query parameters
 * @param array $urlParams URL segments (unused)
 */
function __command_getSocialPreview(array $params = [], array $urlParams = []): ApiResponse
{
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/getSocialPreview');
    }
    foreach (array_keys($params) as $name) {
        if ((string) $name !== 'route') {
            return ApiResponse::create(400, 'validation.invalid_value')
                ->withMessage("'{$name}' is not a parameter of getSocialPreview (route)")
                ->withErrors([['field' => (string) $name, 'reason' => 'unknown_field']]);
        }
    }
    $route = $params['route'] ?? null;
    if ($route === '') {
        $route = null;
    }
    if ($route !== null) {
        $refusal = qs_social_route_refusal($route, 'route');
        if ($refusal !== null) {
            return $refusal;
        }
        $route = trim((string) $route, '/');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage($route !== null ? "Social preview of page '{$route}'" : 'Social preview of every page')
        ->withData(qs_social_report(PROJECT_PATH, $route));
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getSocialPreview($trimParams->params(), $trimParams->additionalParams())->send();
}
