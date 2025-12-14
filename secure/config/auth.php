<?php
/**
 * Authentication & CORS Configuration
 * 
 * Auto-updated: 2025-12-11 11:28:36
 */

return array (
  'authentication' => 
  array (
    'enabled' => true,
    'tokens' => 
    array (
      'tvt_dev_default_change_me_in_production' => 
      array (
        'name' => 'Default Development Token',
        'permissions' => 
        array (
          0 => '*',
        ),
        'created' => '2025-12-11',
        'note' => 'Replace this token before deploying to production',
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
    ),
    'allowed_methods' => 
    array (
      0 => 'GET',
      1 => 'POST',
      2 => 'OPTIONS',
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
