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
require_once SECURE_FOLDER_PATH . '/admin/AdminRouter.php';

// Initialize the admin router
$router = new AdminRouter();

// C15 15.3 — bind the project THIS user is EDITING (their per-user selected_project).
// The panel used to inherit an installation-wide served project, which is why every
// page's CONFIG-derived value (languages, theme flags) described whatever project the
// deployment happened to serve rather than the one on screen. There is no served project
// any more, and the only project the panel has any business reading is the edited one.
//
// Non-strict on purpose: getCurrentProject() returns null for an account that is a member
// of nothing (C15 R3), and a missing/blank project must give that account the panel's
// empty state — never a die() and never somebody else's project. The router itself is
// safe to construct first: its constructor only parses the URL.
$__adminProject = $router->getCurrentProject();
qs_load_project_context($__adminProject ?? '', false);

$router->dispatch();
