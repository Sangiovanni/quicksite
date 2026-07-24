<?php
/**
 * addOAuthProvider — Add a new OAuth provider preset (+ optional credentials)
 *
 * @method POST
 * @url /management/addOAuthProvider
 * @auth required
 * @permission admin
 *
 * Body (JSON):
 *   {
 *     "scope": "admin" | "project",   // Required — where to write the preset
 *     "id":    "<lowercase-id>",      // Required — provider id (slug)
 *     "preset": {                     // Required — preset fields
 *       "authorize_url":       "...",
 *       "token_url":           "...",
 *       "userinfo_url":        "...",
 *       "revoke_url":          "...", // optional, RFC 7009
 *       "scope":               "openid email profile",
 *       "userinfo_sub_path":   "id",
 *       "userinfo_email_path": "email",
 *       "userinfo_name_path":  "name",     // optional
 *       "extra_authorize_params": { ... }, // optional
 *       "refresh_token_supported": true|false,
 *       "_comment":            "..."  // optional
 *     },
 *     "credentials": {                // Optional — when omitted, only preset is written
 *       "client_id":     "...",
 *       "client_secret": "..."  // optional for public clients (PKCE-only)
 *     }
 *   }
 *
 * Returns 201 with the written entry on success. 409 when an entry
 * with the given id already exists at the target scope (author must
 * use editOAuthProvider to update). 400 on validation errors.
 *
 * Admin-tier only — handles credentials. Beta.9 A1 Slice 8.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';

function __command_addOAuthProvider(array $params = [], array $urlParams = []): ApiResponse {
    $errors = __addOAuthProvider_validate($params);
    if (!empty($errors)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('Invalid OAuth provider payload')
            ->withErrors($errors);
    }

    $scope = (string) $params['scope'];
    $id    = (string) $params['id'];
    $preset = $params['preset'];
    $credentials = isset($params['credentials']) && is_array($params['credentials']) ? $params['credentials'] : null;

    // Reject duplicate at target scope. (Cross-scope duplication is
    // expected — that's the per-project override pattern locked in
    // Slice 2.5 — but two entries for the same id at the SAME scope
    // would silently overwrite.)
    $existing = oauthProviderReadPresetsFile($scope);
    if (isset($existing[$id])) {
        return ApiResponse::create(409, 'oauth.provider.duplicate')
            ->withMessage("OAuth provider '$id' already exists at scope '$scope'. Use editOAuthProvider to update it.");
    }

    $existing[$id] = $preset;
    if (!oauthProviderWritePresetsFile($scope, $existing)) {
        return ApiResponse::create(500, 'server.operation_failed')
            ->withMessage("Failed to write presets file at scope '$scope'.");
    }

    if ($credentials !== null) {
        $secrets = oauthProviderReadSecretsFile($scope);
        $secrets[$id] = [
            'client_id' => (string) $credentials['client_id'],
        ];
        if (isset($credentials['client_secret']) && $credentials['client_secret'] !== null && $credentials['client_secret'] !== '') {
            $secrets[$id]['client_secret'] = (string) $credentials['client_secret'];
        }
        if (!oauthProviderWriteSecretsFile($scope, $secrets)) {
            // Best-effort: don't roll back the preset write — partial
            // success is better than partial failure that leaves nothing.
            return ApiResponse::create(500, 'server.operation_failed')
                ->withMessage("Preset written, but failed to write secrets file at scope '$scope'.");
        }
    }

    return ApiResponse::create(201, 'oauth.provider.created')
        ->withMessage("OAuth provider '$id' created at scope '$scope'")
        ->withData([
            'id'                 => $id,
            'scope'              => $scope,
            'credentials_status' => $credentials !== null ? 'set' : 'missing',
        ]);
}

/**
 * Validate the addOAuthProvider payload. Returns a list of errors
 * (empty when valid).
 */
function __addOAuthProvider_validate(array $params): array {
    $errors = [];

    $scope = $params['scope'] ?? null;
    if ($scope !== 'admin' && $scope !== 'project') {
        $errors[] = ['field' => 'scope', 'reason' => 'invalid_value', 'expected' => "'admin' or 'project'"];
    }
    if ($scope === 'project' && !defined('PROJECT_PATH')) {
        $errors[] = ['field' => 'scope', 'reason' => 'no_project', 'hint' => "scope='project' requires a project-scoped request: target one with /management/p/<projectId>/addOAuthProvider"];
    }

    $id = $params['id'] ?? null;
    if (!is_string($id) || $id === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
        $errors[] = ['field' => 'id', 'reason' => 'invalid_format', 'hint' => 'Provider id must start with a lowercase letter and contain only lowercase letters, digits, and hyphens (e.g., "google", "mycorp-sso").'];
    } elseif ($id !== '' && $id[0] === '_') {
        $errors[] = ['field' => 'id', 'reason' => 'reserved', 'hint' => 'Ids starting with "_" are reserved for documentation entries (_schema, _comment).'];
    }

    $preset = $params['preset'] ?? null;
    if (!is_array($preset)) {
        $errors[] = ['field' => 'preset', 'reason' => 'required', 'hint' => 'Preset object is required.'];
    } else {
        foreach (['authorize_url', 'token_url', 'userinfo_url'] as $required) {
            if (!isset($preset[$required]) || !is_string($preset[$required]) || $preset[$required] === '') {
                $errors[] = ['field' => 'preset.' . $required, 'reason' => 'required'];
            }
        }
        foreach (['scope', 'userinfo_sub_path', 'userinfo_email_path'] as $required) {
            if (!isset($preset[$required]) || !is_string($preset[$required]) || $preset[$required] === '') {
                $errors[] = ['field' => 'preset.' . $required, 'reason' => 'required'];
            }
        }
        if (isset($preset['extra_authorize_params']) && !is_array($preset['extra_authorize_params'])) {
            $errors[] = ['field' => 'preset.extra_authorize_params', 'reason' => 'invalid_type', 'expected' => 'object'];
        }
    }

    $credentials = $params['credentials'] ?? null;
    if ($credentials !== null) {
        if (!is_array($credentials) || !isset($credentials['client_id']) || !is_string($credentials['client_id']) || $credentials['client_id'] === '') {
            $errors[] = ['field' => 'credentials.client_id', 'reason' => 'required', 'hint' => 'When credentials are provided, client_id is required.'];
        }
    }

    return $errors;
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addOAuthProvider($trimParams->params(), $trimParams->additionalParams())->send();
}
