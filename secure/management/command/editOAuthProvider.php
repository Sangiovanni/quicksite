<?php
/**
 * editOAuthProvider — Update an OAuth provider preset (+ optional credentials)
 *
 * @method POST
 * @url /management/editOAuthProvider
 * @auth required
 * @permission admin
 *
 * Body (JSON):
 *   {
 *     "scope":  "admin" | "project",  // Required — where the entry currently lives
 *     "id":     "<lowercase-id>",     // Required — provider id
 *     "preset": { ... },              // Required — full preset (replace-all semantic)
 *     "credentials": {                // Optional — when present, written to secrets file
 *       "client_id":     "...",
 *       "client_secret": "..."        // optional; when empty/omitted KEEPS the existing
 *                                     //  value (so the UI can edit client_id without
 *                                     //  forcing re-entry of the secret).
 *     },
 *     "newId":    "<lowercase-id>",   // Optional — rename. New id must not conflict at
 *                                     //  target scope.
 *     "newScope": "admin" | "project" // Optional — move between scopes. Atomic-ish:
 *                                     //  copy then delete; partial failure leaves
 *                                     //  the entry in BOTH scopes (recoverable).
 *   }
 *
 * Returns 200 with the updated entry. 404 when the source entry
 * doesn't exist at the declared scope. 409 when newId / newScope
 * would collide with an existing entry. 400 on validation errors.
 *
 * **Replace semantics on preset**: the provided preset object replaces
 * the existing one in full. Authors who want field-level updates must
 * read the current preset first (listOAuthProviders) and merge client-
 * side. This matches the "override is at provider level, full-entry
 * replace" rule locked in Slice 2.5.
 *
 * Admin-tier only — handles credentials. Beta.9 A1 Slice 8.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';

function __command_editOAuthProvider(array $params = [], array $urlParams = []): ApiResponse {
    $errors = __editOAuthProvider_validate($params);
    if (!empty($errors)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('Invalid OAuth provider payload')
            ->withErrors($errors);
    }

    $scope = (string) $params['scope'];
    $id    = (string) $params['id'];
    $preset = $params['preset'];
    $credentials = isset($params['credentials']) && is_array($params['credentials']) ? $params['credentials'] : null;
    $newId    = isset($params['newId'])    && is_string($params['newId'])    && $params['newId']    !== '' ? (string) $params['newId']    : $id;
    $newScope = isset($params['newScope']) && is_string($params['newScope']) && $params['newScope'] !== '' ? (string) $params['newScope'] : $scope;

    // Source must exist.
    $existing = oauthProviderReadPresetsFile($scope);
    if (!isset($existing[$id])) {
        return ApiResponse::create(404, 'oauth.provider.not_found')
            ->withMessage("OAuth provider '$id' not found at scope '$scope'.");
    }

    // Target collision check (only when id or scope is changing).
    if ($newId !== $id || $newScope !== $scope) {
        $targetMap = ($newScope === $scope) ? $existing : oauthProviderReadPresetsFile($newScope);
        if (isset($targetMap[$newId]) && !($newScope === $scope && $newId === $id)) {
            return ApiResponse::create(409, 'oauth.provider.duplicate')
                ->withMessage("OAuth provider '$newId' already exists at scope '$newScope'. Pick a different id or remove the existing entry first.");
        }
    }

    // Write the new entry to its target location first, then remove
    // the old location. Partial failure leaves the entry in BOTH
    // locations (recoverable via a follow-up delete) rather than
    // disappearing entirely.
    if ($newScope === $scope) {
        if ($newId !== $id) {
            unset($existing[$id]);
        }
        $existing[$newId] = $preset;
        if (!oauthProviderWritePresetsFile($scope, $existing)) {
            return ApiResponse::create(500, 'server.operation_failed')
                ->withMessage("Failed to write presets file at scope '$scope'.");
        }
    } else {
        $targetMap = oauthProviderReadPresetsFile($newScope);
        $targetMap[$newId] = $preset;
        if (!oauthProviderWritePresetsFile($newScope, $targetMap)) {
            return ApiResponse::create(500, 'server.operation_failed')
                ->withMessage("Failed to write presets file at scope '$newScope' (new location).");
        }
        unset($existing[$id]);
        if (!oauthProviderWritePresetsFile($scope, $existing)) {
            return ApiResponse::create(500, 'server.operation_failed')
                ->withMessage("Wrote new entry at scope '$newScope', but failed to remove from source scope '$scope'. Run deleteOAuthProvider with id='$id' scope='$scope' to clean up.");
        }
    }

    // Credentials handling: when omitted, KEEP existing. When provided
    // with empty client_secret, keep the existing secret but update
    // client_id (lets UI submit "just rename client_id" without
    // forcing re-entry of the secret). When fully provided, replace.
    if ($credentials !== null) {
        $targetSecrets = oauthProviderReadSecretsFile($newScope);
        $existingSecret = $targetSecrets[$newId] ?? ($targetSecrets[$id] ?? null);
        $existingClientSecret = (is_array($existingSecret) && isset($existingSecret['client_secret'])) ? (string) $existingSecret['client_secret'] : null;

        $entry = ['client_id' => (string) $credentials['client_id']];
        if (isset($credentials['client_secret']) && $credentials['client_secret'] !== null && $credentials['client_secret'] !== '') {
            $entry['client_secret'] = (string) $credentials['client_secret'];
        } elseif ($existingClientSecret !== null) {
            $entry['client_secret'] = $existingClientSecret;
        }
        $targetSecrets[$newId] = $entry;

        // If we renamed within the same scope, drop the old key.
        if ($newId !== $id && $newScope === $scope && isset($targetSecrets[$id])) {
            unset($targetSecrets[$id]);
        }
        if (!oauthProviderWriteSecretsFile($newScope, $targetSecrets)) {
            return ApiResponse::create(500, 'server.operation_failed')
                ->withMessage("Failed to write secrets file at scope '$newScope'. Preset write succeeded.");
        }

        // If we moved across scopes, also clean up the old secret entry.
        if ($newScope !== $scope) {
            $sourceSecrets = oauthProviderReadSecretsFile($scope);
            if (isset($sourceSecrets[$id])) {
                unset($sourceSecrets[$id]);
                oauthProviderWriteSecretsFile($scope, $sourceSecrets);
            }
        }
    }

    return ApiResponse::create(200, 'oauth.provider.updated')
        ->withMessage("OAuth provider '$newId' updated (scope: '$newScope')")
        ->withData([
            'id'       => $newId,
            'scope'    => $newScope,
            'old_id'   => $id !== $newId ? $id : null,
            'old_scope'=> $scope !== $newScope ? $scope : null,
        ]);
}

function __editOAuthProvider_validate(array $params): array {
    $errors = [];

    foreach (['scope', 'id', 'preset'] as $required) {
        if (!isset($params[$required])) {
            $errors[] = ['field' => $required, 'reason' => 'required'];
        }
    }
    if (!empty($errors)) {
        return $errors;
    }

    $scope = $params['scope'];
    if ($scope !== 'admin' && $scope !== 'project') {
        $errors[] = ['field' => 'scope', 'reason' => 'invalid_value', 'expected' => "'admin' or 'project'"];
    }
    if (isset($params['newScope']) && $params['newScope'] !== '' && $params['newScope'] !== 'admin' && $params['newScope'] !== 'project') {
        $errors[] = ['field' => 'newScope', 'reason' => 'invalid_value', 'expected' => "'admin' or 'project'"];
    }
    if (($scope === 'project' || ($params['newScope'] ?? null) === 'project') && !defined('PROJECT_PATH')) {
        $errors[] = ['field' => 'scope', 'reason' => 'no_active_project'];
    }

    $id = $params['id'];
    if (!is_string($id) || $id === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
        $errors[] = ['field' => 'id', 'reason' => 'invalid_format'];
    }
    if (isset($params['newId']) && $params['newId'] !== '' && !preg_match('/^[a-z][a-z0-9-]*$/', (string) $params['newId'])) {
        $errors[] = ['field' => 'newId', 'reason' => 'invalid_format'];
    }

    if (!is_array($params['preset'])) {
        $errors[] = ['field' => 'preset', 'reason' => 'invalid_type', 'expected' => 'object'];
    } else {
        foreach (['authorize_url', 'token_url', 'userinfo_url', 'scope', 'userinfo_sub_path', 'userinfo_email_path'] as $required) {
            if (!isset($params['preset'][$required]) || !is_string($params['preset'][$required]) || $params['preset'][$required] === '') {
                $errors[] = ['field' => 'preset.' . $required, 'reason' => 'required'];
            }
        }
    }

    if (isset($params['credentials'])) {
        if (!is_array($params['credentials']) || !isset($params['credentials']['client_id']) || !is_string($params['credentials']['client_id']) || $params['credentials']['client_id'] === '') {
            $errors[] = ['field' => 'credentials.client_id', 'reason' => 'required'];
        }
    }

    return $errors;
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_editOAuthProvider($trimParams->params(), $trimParams->additionalParams())->send();
}
