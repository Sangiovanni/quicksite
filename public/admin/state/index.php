<?php
/**
 * Admin Panel State Endpoint
 *
 * The panel's own per-user state — what the person editing has open, not
 * anything about a project's content. Under the beta.11 rule the command
 * surface is a CLI for DEVELOPING a project and the panel is a tool that USES
 * it; panel state is therefore not a command and is served here.
 *
 * WHY ITS OWN FILE rather than an arm of /admin/api. That helper is read-only —
 * twenty-eight arms, every one a read — and its whole contract is "give the form
 * its options". Putting the first mutation into it would quietly turn a lookup
 * surface into a write surface, and the next person adding an arm would have no
 * way to tell which kind they were adding. Writes get their own door.
 *
 * ROUTES
 *   POST /admin/state/selected-project   {"project": "<id>"}
 *
 * AUTH is the shared admin gate (qs_admin_json_boot): the session cookie AND
 * the per-session token as `Authorization: Bearer`. The header is what proves
 * the call came from something that could READ a panel page, which is what
 * stops a foreign site driving this endpoint through the visitor's browser.
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../init.php';
require_once SECURE_FOLDER_PATH . '/admin/functions/adminJsonEndpoint.php';

$tokenInfo = qs_admin_json_boot();

// A defined, benign working context from the caller's UX-default project (never
// an authz input), so an account that is a member of nothing still gets an empty
// context rather than a fatal. Same non-strict binding /admin and /admin/api use.
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
qs_load_project_context(resolveDefaultProject($tokenInfo) ?? '', false);

// The key being written: /admin/state/<key>, with the PUBLIC_FOLDER_SPACE
// segments and the 'admin'/'state' prefix peeled off exactly as /admin/api does.
$parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/'));
foreach (array_filter(explode('/', PUBLIC_FOLDER_SPACE)) as $_) {
    array_shift($parts);
}
array_shift($parts); // admin
array_shift($parts); // state
$key = array_shift($parts) ?? '';

// Every route here MUTATES, so every route is POST. A state change reachable by
// GET is a state change a link, an image tag or a prefetch can make.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'status'  => 405,
        'code'    => 'validation.method_not_allowed',
        'message' => 'This endpoint only accepts POST',
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

require_once SECURE_FOLDER_PATH . '/admin/functions/panelState.php';

switch ($key) {
    case 'selected-project':
        qs_admin_set_selected_project($tokenInfo, (string)($body['project'] ?? ''))->send();
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'status'  => 404,
            'code'    => 'resource.not_found',
            'message' => 'Unknown panel state key',
        ]);
        break;
}
