<?php
/**
 * Authentication & CORS Configuration
 * 
 * Auto-updated: 2025-12-15 13:06:19
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
      'tvt_57653b93fb720480faa7388dfe81ee07d22c1b8c2f61a6d8' => 
      array (
        'name' => 'Read-Only API Access',
        'permissions' => 
        array (
          0 => 'read',
        ),
        'created' => '2025-12-15 13:05:09',
      ),
      'tvt_7bc9bf8672ead4b1d8b0e93e72e9a6517991fbe9fb90a05e' => 
      array (
        'name' => 'Collaborator Token',
        'permissions' => 
        array (
          0 => 'read',
          1 => 'write',
        ),
        'created' => '2025-12-15 13:05:55',
      ),
      'tvt_978867529f614b601565e305d1e05fffe9b0cdb2e698015c' => 
      array (
        'name' => 'Developer Token',
        'permissions' => 
        array (
          0 => '*',
        ),
        'created' => '2025-12-15 13:06:19',
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
