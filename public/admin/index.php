<?php
/**
 * QuickSite Admin Panel Entry Point
 * 
 * Handles routing for the admin interface.
 * Similar to management/index.php but for human-friendly admin UI.
 * 
 * @version 1.6.0
 */

require_once '../init.php';

// Fatal handling for the admin PAGE surface. /management and /admin/api both
// convert a fatal into a clean 500; without this, PHP prints its own error —
// absolute path and stack frames included — into a body whose status is still
// 200. An array `lang` parameter on any admin URL was enough to raise one, the
// login page included, i.e. before authentication.
//
// Registered BEFORE the router is required so a parse error in AdminRouter.php
// (or in anything it pulls in) is covered too — that class of failure is the one
// seen on /admin/api, and it is exactly the case a later registration
// would miss. It also switches display_errors off in a production install, so
// even a fatal raised after the headers are on the wire — which no shutdown
// handler can repair — stops disclosing paths.
require_once SECURE_FOLDER_PATH . '/src/functions/errorHygiene.php';
qs_register_fatal_handler(QS_FATAL_SHAPE_HTML);

require_once SECURE_FOLDER_PATH . '/admin/AdminRouter.php';

// Initialize the admin router
$router = new AdminRouter();

// Bind the project THIS user is EDITING (their per-user selected_project).
// The panel used to inherit an installation-wide served project, which is why every
// page's CONFIG-derived value (languages, theme flags) described whatever project the
// deployment happened to serve rather than the one on screen. There is no served project
// any more, and the only project the panel has any business reading is the edited one.
//
// Non-strict on purpose: getCurrentProject() returns null for an account that is a member
// of nothing, and a missing/blank project must give that account the panel's
// empty state — never a die() and never somebody else's project. The router itself is
// safe to construct first: its constructor only parses the URL.
$__adminProject = $router->getCurrentProject();
qs_load_project_context($__adminProject ?? '', false);

$router->dispatch();
