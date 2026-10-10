<?php
/**
 * setOAuthCredentials — set or clear one of this project's key sets for an OAuth provider
 *
 * @method POST
 * @url /management/p/<projectId>/setOAuthCredentials
 * @auth required
 * @permission oauth.manage (the project's admin and owner)
 *
 * Body (JSON):
 *   {
 *     "provider":      "google",     // Required — a provider the installation offers
 *     "set":           "preview",    // Required — "preview" (sign-in on this installation)
 *                                    //   or "build" (carried by a build to the deployed site)
 *     "client_id":     "...",        // Required unless "clear"
 *     "client_secret": "...",        // Required the first time; omitted later = keep the stored one
 *     "clear":         false         // Optional — true removes this set
 *   }
 *
 * Writes the project's own data/oauth-secrets.json and nothing else: the providers belong to the
 * installation (a file its operator edits, which no command writes), the keys to the project. The
 * answer never contains the secret, only whether one is stored.
 *
 * The secret must come in the request body: a value in the address would be written to the web
 * server's access log.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';      // qs_param_string
require_once SECURE_FOLDER_PATH . '/src/functions/oauthProviderHelpers.php';

function __command_setOAuthCredentials(array $params = [], array $urlParams = []): ApiResponse {
    if (isset($_GET['client_secret'])) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('Send client_secret in the request body, never in the address: an address is written to the web server\'s access log.')
            ->withErrors([['field' => 'client_secret', 'reason' => 'in_query_string']]);
    }

    $provider = qs_param_string($params, 'provider');
    $set      = qs_param_string($params, 'set');
    $clear    = filter_var($params['clear'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $errors = [];
    if ($provider === null || $provider === '') {
        $errors[] = ['field' => 'provider', 'reason' => 'required'];
    }
    if ($set === null || !in_array($set, QS_OAUTH_KEY_SETS, true)) {
        $errors[] = ['field' => 'set', 'reason' => 'invalid_value', 'expected' => "'preview' or 'build'"];
    }
    if (!empty($errors)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('Invalid OAuth credentials request')
            ->withErrors($errors);
    }

    if (qs_oauth_provider($provider) === null) {
        return ApiResponse::create(404, 'oauth.provider.not_offered')
            ->withMessage("The installation does not offer the OAuth provider '" . substr($provider, 0, 64) . "'. listOAuthProviders lists the providers it offers.");
    }

    // A key file that is not a JSON object is never written over: its content would be lost.
    $keysPath = qs_oauth_keys_path();
    if ($keysPath !== null && is_file($keysPath) && !is_array(json_decode((string) @file_get_contents($keysPath), true))) {
        return ApiResponse::create(500, 'server.invalid_json')
            ->withMessage("This project's data/oauth-secrets.json is not a JSON object, so it is not written over. Fix it or remove it, then try again.");
    }
    $keys = qs_oauth_keys_read();

    if ($clear) {
        unset($keys[$provider][$set]);
        if (empty($keys[$provider])) {
            unset($keys[$provider]);
        }
        if (!qs_oauth_keys_write($keys)) {
            return ApiResponse::create(500, 'server.file_write_failed')
                ->withMessage("Could not write the project's OAuth keys.");
        }
        return ApiResponse::create(200, 'oauth.credentials.cleared')
            ->withMessage("The $set keys for '$provider' were removed")
            ->withData(['provider' => $provider, 'set' => $set, 'client_id' => null, 'secret_set' => false]);
    }

    $clientId = qs_param_string($params, 'client_id');
    $secretGiven = array_key_exists('client_secret', $params) && $params['client_secret'] !== null && $params['client_secret'] !== '';
    $secret = $secretGiven ? qs_param_string($params, 'client_secret') : null;
    $stored = $keys[$provider][$set] ?? null;

    if ($clientId === null || $clientId === '') {
        $errors[] = ['field' => 'client_id', 'reason' => 'required'];
    } elseif (!qs_oauth_key_value_ok($clientId, QS_OAUTH_CLIENT_ID_MAX)) {
        $errors[] = ['field' => 'client_id', 'reason' => 'invalid_format',
            'hint' => 'At most ' . QS_OAUTH_CLIENT_ID_MAX . ' printable ASCII characters, with no space.'];
    }
    if ($secretGiven && ($secret === null || !qs_oauth_key_value_ok($secret, QS_OAUTH_CLIENT_SECRET_MAX))) {
        $errors[] = ['field' => 'client_secret', 'reason' => 'invalid_format',
            'hint' => 'A string of at most ' . QS_OAUTH_CLIENT_SECRET_MAX . ' printable ASCII characters, with no space.'];
    }
    if (!$secretGiven && ($stored === null || $stored['client_secret'] === null)) {
        $errors[] = ['field' => 'client_secret', 'reason' => 'required',
            'hint' => 'No secret is stored for this set yet. Copy it from the provider\'s console.'];
    }
    if (!empty($errors)) {
        return ApiResponse::create(400, 'validation.failed')
            ->withMessage('Invalid OAuth credentials')
            ->withErrors($errors);
    }

    $keys[$provider][$set] = [
        'client_id'     => $clientId,
        'client_secret' => $secretGiven ? $secret : $stored['client_secret'],
    ];
    if (!qs_oauth_keys_write($keys)) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage("Could not write the project's OAuth keys.");
    }

    return ApiResponse::create(200, 'oauth.credentials.saved')
        ->withMessage("The $set keys for '$provider' were saved")
        ->withData(['provider' => $provider, 'set' => $set, 'client_id' => $clientId, 'secret_set' => true]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_setOAuthCredentials($trimParams->params(), $trimParams->additionalParams())->send();
}
