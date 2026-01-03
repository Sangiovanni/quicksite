<?php
/**
 * Authentication & CORS Configuration
 * 
 * Auto-updated: 2026-01-03 14:45:00
 */

return array (
  'authentication' => 
  array (
    'enabled' => true,
    'tokens' => 
    array (
      'tvt_dev_default_change_me_in_production' => 
      array (
        'name' => 'Default Superadmin Token',
        'role' => '*',
        'created' => '2025-12-11',
        'note' => 'Replace this token before deploying to production',
      ),
      'tvt_viewer_default_change_me_in_production' => 
      array (
        'name' => 'Default Viewer Token',
        'role' => 'viewer',
        'created' => '2026-01-03',
        'note' => 'Read-only access - Replace before production',
      ),
      'tvt_editor_default_change_me_in_production' => 
      array (
        'name' => 'Default Editor Token',
        'role' => 'editor',
        'created' => '2026-01-03',
        'note' => 'Content editing access - Replace before production',
      ),
      'tvt_designer_default_change_me_in_production' => 
      array (
        'name' => 'Default Designer Token',
        'role' => 'designer',
        'created' => '2026-01-03',
        'note' => 'Style editing access - Replace before production',
      ),
      'tvt_developer_default_change_me_in_production' => 
      array (
        'name' => 'Default Developer Token',
        'role' => 'developer',
        'created' => '2026-01-03',
        'note' => 'Build and deploy access - Replace before production',
      ),
      'tvt_admin_default_change_me_in_production' => 
      array (
        'name' => 'Default Admin Token',
        'role' => 'admin',
        'created' => '2026-01-03',
        'note' => 'Full admin access (no token management) - Replace before production',
      ),
    ),
  ),
  'cors' => 
  array (
    'enabled' => true,
    'development_mode' => true,
    'allowed_origins' => 
    array (
      0 => 'http://localhost:3000',
      1 => 'http://localhost:8080',
      2 => 'http://127.0.0.1:3000',
      3 => 'http://127.0.0.1:8080',
      4 => 'http://template.vitrine',
      5 => 'http://test.test',
    ),
    'allowed_methods' => 
    array (
      0 => 'GET',
      1 => 'POST',
      2 => 'PUT',
      3 => 'PATCH',
      4 => 'DELETE',
      5 => 'OPTIONS',
    ),
    'allowed_headers' => 
    array (
      0 => 'Content-Type',
      1 => 'Authorization',
      2 => 'X-Requested-With',
    ),
    'expose_headers' => 
    array (
      0 => 'X-Request-Id',
    ),
    'max_age' => 86400,
    'allow_credentials' => true,
  ),
);