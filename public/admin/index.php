<?php
/**
 * QuickSite Admin Panel Entry Point
 * 
 * Handles routing for the admin interface.
 * Similar to management/index.php but for human-friendly admin UI.
 * 
 * @version 1.6.0
 */

// C8 8.1 — the panel must boot even when NO main project is served (deleteProject
// clears the pointer rather than auto-promoting one, and target.php can name a
// project that no longer exists). Without this, init.php would refuse to bind a
// project context and the operator would be locked out of the very UI they need to
// publish a new main. init.php gives us an empty context instead; the panel already
// copes with a user who has no project (the 0-membership empty state).
define('QS_TOLERATE_NO_SERVED_PROJECT', true);

require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/admin/AdminRouter.php';

// Initialize the admin router
$router = new AdminRouter();
$router->dispatch();
