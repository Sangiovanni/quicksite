<?php
/**
 * Shared boot for the admin panel's JSON endpoints.
 *
 * THE PANEL IS A TOOL THAT USES THE CLI; IT IS NOT PART OF IT. Anything about
 * the installation, the account, or the panel's own state is not a command
 * (beta.11 S6). Those surfaces live here instead — but they still have to
 * authenticate exactly as `/management` does, because they run engine code on
 * the caller's behalf.
 *
 * There are three of them today:
 *   - public/admin/api/index.php    read-only helper arms (form options, the
 *                                   update check)
 *   - public/admin/state/index.php  the panel's own per-user state (writes)
 *   - public/admin/self/index.php   the account and its project memberships
 *
 * ONE implementation of the gate, not two. A second hand-rolled copy of a
 * bearer-token check is the kind of thing that stays right for one release and
 * then drifts, and a drift between two admin auth gates is a security defect
 * rather than an inconsistency. Each endpoint still requires whatever else IT
 * needs; only the gate is shared.
 *
 * Must be required AFTER init.php — it depends on SECURE_FOLDER_PATH.
 */

if (!function_exists('qs_admin_json_boot')) {
    /**
     * Register fatal handling, set the JSON headers, and resolve the caller.
     *
     * Authenticates exactly like /management does: the session cookie AND the
     * per-session token in the Authorization header. These endpoints run engine
     * code in-process, so they must not be reachable on a weaker credential
     * than the commands themselves.
     *
     * Exits 401 when the caller is not authenticated — a caller that gets a
     * return value is always authenticated.
     *
     * @return array The resolved user (has 'id'), never null.
     */
    function qs_admin_json_boot(): array {
        // A fatal anywhere below (a malformed project config.php is enough) would
        // otherwise leave the status at 200 and let PHP print its own error,
        // absolute filesystem path included, as the body. Registered as early as
        // the constants allow, sharing one implementation with /management so the
        // surfaces cannot drift apart.
        require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';
        qs_register_fatal_handler(QS_FATAL_SHAPE_ERROR);

        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        $tokenValidation = validateBearerToken($authHeader !== '' ? $authHeader : null);
        $tokenInfo = $tokenValidation['valid'] ? $tokenValidation['user'] : null;

        if ($tokenInfo === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        return $tokenInfo;
    }
}
