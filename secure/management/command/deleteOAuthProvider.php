<?php
/**
 * deleteOAuthProvider — Remove an OAuth provider preset (+ credentials)
 *
 * @method POST
 * @url /management/deleteOAuthProvider
 * @auth required
 * @permission admin
 *
 * Body (JSON):
 *   {
 *     "scope": "admin" | "project",  // Required — where to remove from
 *     "id":    "<lowercase-id>"      // Required — provider id
 *   }
 *
 * **Strict in-use block**: this command refuses with HTTP 409 when the
 * provider is referenced anywhere in the active project — route-
 * resolvers (oauth-start / oauth-callback / oauth-logout configs with
 * matching literal `provider`) or page structure JSON (oauth-button
 * elements rendered with the matching CSS modifier class). The
 * response carries a structured `usage` summary so the UI can show
 * "remove these consumers first" guidance. Locked design — see
 * DESIGN_DECISIONS.md "OAuth providers admin page shape".
 *
 * When scope='admin' and a project-scope override exists for the same
 * id, the override is left untouched (it now becomes the sole entry
 * for that provider — defensible: the project author intentionally
 * created the override).
 *
 * Admin-tier only — handles credentials. Beta.9 A1 Slice 8.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';

function __command_deleteOAuthProvider(array $params = [], array $urlParams = []): ApiResponse {
    $scope = $params['scope'] ?? null;
    if ($scope !== 'admin' && $scope !== 'project') {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('scope is required and must be "admin" or "project"')
            ->withErrors([['field' => 'scope', 'reason' => 'invalid_value']]);
    }
    if ($scope === 'project' && !defined('PROJECT_PATH')) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage("scope='project' requires an active project")
            ->withErrors([['field' => 'scope', 'reason' => 'no_active_project']]);
    }

    $id = $params['id'] ?? null;
    if (!is_string($id) || $id === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('id is required and must match /^[a-z][a-z0-9-]*$/')
            ->withErrors([['field' => 'id', 'reason' => 'invalid_format']]);
    }

    $existing = oauthProviderReadPresetsFile($scope);
    if (!isset($existing[$id])) {
        return ApiResponse::create(404, 'oauth.provider.not_found')
            ->withMessage("OAuth provider '$id' not found at scope '$scope'.");
    }

    // In-use guard. Scans the active project for routes + buttons that
    // reference this provider. Skipped only when removing a PROJECT-
    // scope OVERRIDE (the admin entry survives, so consumers still
    // resolve — removing the override is non-destructive). Otherwise
    // (admin delete OR project-only delete) the consumers would break.
    $isProjectOverride = false;
    if ($scope === 'project') {
        $adminPresets = oauthProviderReadPresetsFile('admin');
        $isProjectOverride = isset($adminPresets[$id]);
    }

    if (!$isProjectOverride) {
        $usage = oauthProviderScanUsage($id);
        if ($usage['count'] > 0) {
            return ApiResponse::create(409, 'oauth.provider.in_use')
                ->withMessage("OAuth provider '$id' is still used by " . $usage['count'] . " site(s). Remove those before deleting the provider.")
                ->withData(['id' => $id, 'scope' => $scope, 'usage' => $usage]);
        }
    }

    unset($existing[$id]);
    if (!oauthProviderWritePresetsFile($scope, $existing)) {
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage("Failed to write presets file at scope '$scope'.");
    }

    // Clean up secrets too. Idempotent — missing entry is fine.
    $secrets = oauthProviderReadSecretsFile($scope);
    if (isset($secrets[$id])) {
        unset($secrets[$id]);
        oauthProviderWriteSecretsFile($scope, $secrets);
    }

    return ApiResponse::create(200, 'oauth.provider.deleted')
        ->withMessage("OAuth provider '$id' removed from scope '$scope'" . ($isProjectOverride ? ' (admin entry remains; provider still resolvable)' : ''))
        ->withData([
            'id'                => $id,
            'scope'             => $scope,
            'was_override'      => $isProjectOverride,
            'admin_entry_remains' => $isProjectOverride,
        ]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_deleteOAuthProvider($trimParams->params(), $trimParams->additionalParams())->send();
}
