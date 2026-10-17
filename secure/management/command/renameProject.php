<?php
/**
 * renameProject Command
 *
 * Gives the targeted project a new name. The name is the project's label wherever
 * it is listed ("Name #id"); it lives in the project's name file
 * (config/project.json, projectIdentity.php) and is read when it is shown, so a
 * rename reaches every list at once. Nothing else changes: the id, the folder, the
 * `/p/<id>/` address and the site's own texts (the `site.name` translation that
 * visitors read) stay as they are.
 *
 * Category: project.ownership (owner only) — choosing what the project is called
 * is the owner's, as is handing it on.
 *
 * @method POST
 * @route /management/p/<projectId>/renameProject
 * @auth required (project.ownership — owner)
 *
 * @param string $name The new name (required): the rule of qs_project_name_problem()
 *
 * @return ApiResponse The project's id, its name and the name it had
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectIdentity.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_refusal

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_renameProject(array $params = [], array $urlParams = []): ApiResponse {
    // Project containment: marker-only targeting (the project is the authorized URL
    // marker; there is no body project param to confuse it with).
    if (!defined('PROJECT_NAME') || PROJECT_NAME === '') {
        return ApiResponse::create(400, 'project.required')
            ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/renameProject');
    }
    $project = PROJECT_NAME;
    $projectPath = SECURE_FOLDER_PATH . '/projects/' . $project;
    if (!is_dir($projectPath)) {
        return ApiResponse::create(404, 'resource.not_found')
            ->withMessage('Project not found');
    }

    $problem = qs_project_name_problem($params['name'] ?? '', $name);
    if ($problem !== null) {
        return qs_project_name_refusal($problem);
    }

    $previous = qs_project_name_in($projectPath);
    $data = ['project' => $project, 'name' => $name, 'previous_name' => $previous, 'changed' => $previous !== $name];
    if ($previous === $name) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage("The project is already named '$name'")
            ->withData($data);
    }

    // The name file is a small project file: its size cap and the over-quota rule.
    $file = $projectPath . '/' . QS_PROJECT_NAME_FILE;
    $bytes = qs_project_name_file_bytes($name);
    $refusal = $bytes === null ? null : qs_small_write_refusal([$file => strlen($bytes)]);
    if ($refusal !== null) {
        return $refusal;
    }
    if ($bytes === null || !qs_project_name_write($projectPath, $name)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write the project name');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage("Project #$project renamed to '$name'")
        ->withData($data);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_renameProject($trimParams->params(), $trimParams->additionalParams())->send();
}
