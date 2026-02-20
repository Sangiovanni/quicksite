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
$router->dispatch();
