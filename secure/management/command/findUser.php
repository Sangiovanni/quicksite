<?php
/**
 * findUser Command (C8 8.3a)
 *
 * EXACT public-name lookup — the primitive behind "invite someone": look the
 * person up by the display name they gave you, confirm the {user_id, name}
 * pair, invite by id. Display names are NOT unique — several matches may
 * return; the opaque user id is the unique PUBLIC identifier that
 * disambiguates.
 *
 * PRIVACY (C8 8.0b): the response carries user_id + name ONLY. The PRIVATE
 * username is never searchable and never returned. Exact match only in
 * beta.10 (no substring/fuzzy search — no roster harvesting).
 *
 * @method POST
 * @route /management/findUser
 * @auth required (users.lookup — any authenticated user; global, no marker)
 *
 * @param string $name Public display name to match (exact, case-insensitive)
 *
 * @return ApiResponse matches[] of {user_id, name}
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * Command function for internal execution or direct PHP call
 *
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_findUser(array $params = [], array $urlParams = []): ApiResponse {
    $name = trim((string)($params['name'] ?? ''));
    if ($name === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('name is required')
            ->withErrors(['name' => 'Required field']);
    }

    $needle = mb_strtolower($name);
    $matches = [];
    foreach (loadUsersConfig()['users'] ?? [] as $userId => $user) {
        $candidate = $user['name'] ?? null;
        if (is_string($candidate) && $candidate !== '' && mb_strtolower(trim($candidate)) === $needle) {
            $matches[] = ['user_id' => (string)$userId, 'name' => $candidate];
        }
    }

    // Zero matches is a successful search, not an error — names are public
    // display data and their existence is the feature (unlike usernames).
    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($matches) === 1 ? '1 user found' : count($matches) . ' users found')
        ->withData([
            'query'   => $name,
            'matches' => $matches,
            'count'   => count($matches),
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_findUser($trimParams->params(), $trimParams->additionalParams())->send();
}
