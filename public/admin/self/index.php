<?php
/**
 * Admin Panel Self-Service Endpoint
 *
 * The caller's own account, their own project ACCESS, and the two directory
 * lookups those flows need. Under the beta.11 rule the command surface is a CLI
 * for DEVELOPING a project and the panel is a tool that USES it; none of this is
 * project development, so none of it is a command.
 *
 * WHY ITS OWN FILE. /admin/api is read-only by contract — its arms exist to give
 * a form its options — and /admin/state holds the panel's own per-user state
 * (which project you have open). Account and membership are neither: they are
 * operations on the caller's identity and their access to projects. A third
 * concern gets a third door rather than being folded into a surface whose
 * contract it would break.
 *
 * ROUTES — reads are GET, writes are POST, and the method is enforced per route.
 * A password change reachable by GET is a password change a link or a prefetch
 * could make, so the table below is the gate, not documentation.
 *
 *   GET  /admin/self/space-usage[?refresh=1]  owner-wide disk footprint
 *   GET  /admin/self/permissions              the caller's role + commands
 *   GET  /admin/self/invitations              membership inbox
 *   GET  /admin/self/proposals                the caller's outgoing proposals
 *   GET  /admin/self/roles                    the fixed role catalogue
 *   POST /admin/self/find-user                {"name": "<display name>"}
 *   POST /admin/self/change-password          {"current_password","new_password"}
 *   POST /admin/self/delete                   {"current_password","confirm"}
 *   POST /admin/self/accept-invitation        {"project"}
 *   POST /admin/self/decline-invitation       {"project"}
 *   POST /admin/self/leave-project            {"project"}
 *   POST /admin/self/dismiss-notice           {"project"}
 *   POST /admin/self/request-to-join          {"project","note"}
 *   POST /admin/self/withdraw-request         {"project","user_id"?}
 *
 * AUTH is the shared admin gate (qs_admin_json_boot): the session cookie AND the
 * per-session token as `Authorization: Bearer`. The header is what proves the
 * call came from something that could READ a panel page, which is what stops a
 * foreign site driving this endpoint through the visitor's browser.
 *
 * AUTHORIZATION BEYOND THAT lives where it always did — inside each handler.
 * Every route here replaces a command in a `scope: global`, `access: 'any'`
 * category, so hasPermission() contributed authentication and nothing else. The
 * real gates (the current-password re-check and the login backoff on the two
 * credential routes; the F1 project validation, the caller-owns-this-entry rule
 * and the accept-time re-validation on the membership routes) are all in the
 * handlers and are unchanged.
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

// qs_format_size, used by the space-usage report.
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

// The route key: /admin/self/<key>, with the PUBLIC_FOLDER_SPACE segments and
// the 'admin'/'self' prefix peeled off exactly as /admin/state does.
$parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/'));
foreach (array_filter(explode('/', PUBLIC_FOLDER_SPACE)) as $_) {
    array_shift($parts);
}
array_shift($parts); // admin
array_shift($parts); // self
$key = array_shift($parts) ?? '';

// The route table IS the method gate. A route is unreachable by any verb it does
// not declare, so a mistyped method fails loudly instead of being interpreted.
const QS_SELF_ROUTE_METHODS = [
    'space-usage'        => 'GET',
    'permissions'        => 'GET',
    'invitations'        => 'GET',
    'proposals'          => 'GET',
    'roles'              => 'GET',
    'find-user'          => 'POST',
    'change-password'    => 'POST',
    'delete'             => 'POST',
    'accept-invitation'  => 'POST',
    'decline-invitation' => 'POST',
    'leave-project'      => 'POST',
    'dismiss-notice'     => 'POST',
    'request-to-join'    => 'POST',
    'withdraw-request'   => 'POST',
];

if (!isset(QS_SELF_ROUTE_METHODS[$key])) {
    http_response_code(404);
    echo json_encode([
        'status'  => 404,
        'code'    => 'resource.not_found',
        'message' => 'Unknown account route',
    ]);
    exit;
}

$expected = QS_SELF_ROUTE_METHODS[$key];
$method   = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== $expected) {
    http_response_code(405);
    header('Allow: ' . $expected);
    echo json_encode([
        'status'  => 405,
        'code'    => 'validation.method_not_allowed',
        'message' => 'This route only accepts ' . $expected,
    ]);
    exit;
}

$body = $expected === 'POST' ? json_decode(file_get_contents('php://input'), true) : [];
if (!is_array($body)) {
    $body = [];
}

require_once SECURE_FOLDER_PATH . '/admin/functions/accountSelf.php';
require_once SECURE_FOLDER_PATH . '/admin/functions/membershipSelf.php';
require_once SECURE_FOLDER_PATH . '/admin/functions/directory.php';

switch ($key) {
    // ---- account ------------------------------------------------------
    case 'change-password':    qs_account_change_password($body)->send();     break;
    case 'delete':             qs_account_delete($body)->send();              break;
    case 'space-usage':        qs_account_space_usage($body)->send();         break;
    case 'permissions':        qs_account_permissions($tokenInfo)->send();    break;

    // ---- membership ---------------------------------------------------
    case 'invitations':        qs_membership_list_invitations()->send();      break;
    case 'proposals':          qs_membership_list_proposals()->send();        break;
    case 'accept-invitation':  qs_membership_accept($body)->send();           break;
    case 'decline-invitation': qs_membership_decline($body)->send();          break;
    case 'leave-project':      qs_membership_leave($body)->send();            break;
    case 'dismiss-notice':     qs_membership_dismiss_notice($body)->send();   break;
    case 'request-to-join':    qs_membership_request_join($body)->send();     break;
    case 'withdraw-request':   qs_membership_withdraw_request($body)->send(); break;

    // ---- directory ----------------------------------------------------
    case 'find-user':          qs_directory_find_user($body)->send();         break;
    case 'roles':              qs_directory_list_roles($tokenInfo)->send();   break;
}
