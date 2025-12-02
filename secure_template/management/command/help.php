<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

$commands = [
    'movePublicRoot' => [
        'description' => 'Moves the public template folder to a new location and updates all references',
        'method' => 'POST',
        'parameters' => [
            'destination' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Relative path from PUBLIC_FOLDER_ROOT (use empty string for root)',
                'example' => 'subfolder/app',
                'validation' => 'Max 255 chars, max 10 levels deep, alphanumeric/hyphens/underscores only'
            ]
        ],
        'example_post' => 'POST /management/movePublicRoot with body: {"destination": "subfolder/app"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Public root successfully moved',
            'data' => [
                'old_path' => '/path/to/old',
                'new_path' => '/path/to/new',
                'destination' => 'subfolder/app'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing destination parameter',
            '400.validation.invalid_format' => 'Invalid path format (max 255 chars, max 10 levels)',
            '500.server.file_write_failed' => 'Failed to move or update files'
        ]
    ],
    
    'moveSecureRoot' => [
        'description' => 'Moves the secure template folder to a new single-level folder name and updates references',
        'method' => 'POST',
        'parameters' => [
            'destination' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Single folder name (no subdirectories allowed)',
                'example' => 'secure_v2',
                'validation' => 'Must be single folder name (no slashes), cannot be empty, max 255 chars, alphanumeric/hyphens/underscores only'
            ]
        ],
        'example_post' => 'POST /management/moveSecureRoot with body: {"destination": "secure_v2"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Secure root successfully moved',
            'data' => [
                'old_path' => '/path/to/secure_template',
                'new_path' => '/path/to/secure_v2',
                'old_name' => 'secure_template',
                'new_name' => 'secure_v2',
                'init_file_updated' => '/path/to/init.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing destination parameter',
            '400.validation.invalid_format' => 'Must be single folder name (no subdirectories), cannot be empty',
            '409.conflict.duplicate' => 'A folder with this name already exists',
            '500.server.file_write_failed' => 'Failed to move or update files'
        ],
        'notes' => 'Destination must be a single folder name. Cannot move to subdirectories. Checks for naming conflicts with sibling folders.'
    ],
    
    'addRoute' => [
        'description' => 'Creates a new route with PHP page template and empty JSON structure',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route name (will be used in URL)',
                'example' => 'about-us',
                'validation' => 'Lowercase letters, numbers, and hyphens only'
            ]
        ],
        'example_post' => 'POST /management/addRoute with body: {"route": "about-us"}',
        'success_response' => [
            'status' => 201,
            'code' => 'route.created',
            'message' => 'Route successfully created and registered',
            'data' => [
                'route' => 'about-us',
                'php_file' => '/path/to/about-us.php',
                'json_file' => '/path/to/about-us.json',
                'routes_updated' => '/path/to/routes.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route parameter',
            '400.route.invalid_name' => 'Invalid route name (only lowercase, numbers, hyphens)',
            '400.route.already_exists' => 'Route already exists',
            '500.server.file_write_failed' => 'Failed to create files',
            '500.server.directory_create_failed' => 'Failed to create directory'
        ],
        'notes' => 'Creates PHP template with JSON renderer and empty JSON structure ([]). Use editStructure to populate content.'
    ],
    
    'deleteRoute' => [
        'description' => 'Deletes an existing route and its associated files (PHP and JSON)',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route name to delete',
                'example' => 'about-us',
                'validation' => 'Must be an existing route'
            ]
        ],
        'example_post' => 'POST /management/deleteRoute with body: {"route": "about-us"}',
        'success_response' => [
            'status' => 200,
            'code' => 'route.deleted',
            'message' => 'Route successfully deleted',
            'data' => [
                'route' => 'about-us',
                'deleted_files' => [
                    'php' => '/path/to/about-us.php',
                    'json' => '/path/to/about-us.json'
                ],
                'routes_updated' => '/path/to/routes.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route parameter',
            '400.route.invalid_name' => 'Invalid route name format',
            '404.route.not_found' => 'Route does not exist',
            '404.file.not_found' => 'Page template file not found',
            '500.server.file_write_failed' => 'Failed to delete files or update routes'
        ],
        'notes' => 'Deletes both PHP template and JSON page structure. Updates routes.php automatically.'
    ],
    
    'getRoutes' => [
        'description' => 'Returns list of all available routes in the system',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getRoutes',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Routes retrieved successfully',
            'data' => [
                'routes' => ['home', 'privacy', 'terms'],
                'count' => 3
            ]
        ],
        'error_responses' => [],
        'notes' => 'Returns the current list of registered routes. Useful for validation before creating menu/footer links.'
    ],
    
    'getStructure' => [
        'description' => 'Retrieves the JSON structure for a page, menu, or footer',
        'method' => 'GET',
        'url_structure' => '/management/getStructure/{type}/{name?}',
        'parameters' => [
            '{type}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Type of structure to retrieve (URL segment)',
                'example' => 'page',
                'validation' => 'Must be one of: page, menu, footer'
            ],
            '{name}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required only when type=page, as URL segment)',
                'example' => 'home',
                'validation' => 'Must be an existing route'
            ]
        ],
        'example_get' => 'GET /management/getStructure/menu or GET /management/getStructure/page/home',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure retrieved successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'structure' => [...],
                'file' => '/path/to/home.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type or name in URL',
            '400.validation.invalid_format' => 'Invalid type (must be page/menu/footer)',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to read structure file',
            '500.server.internal_error' => 'Invalid JSON in structure file'
        ],
        'notes' => 'Uses URL segments for parameters. For menu/footer: GET /getStructure/{type}. For pages: GET /getStructure/page/{pageName}.'
    ],
    
    'editStructure' => [
        'description' => 'Updates the JSON structure for a page, menu, or footer',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Type of structure to update',
                'example' => 'page',
                'validation' => 'Must be one of: page, menu, footer'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required only when type=page)',
                'example' => 'home',
                'validation' => 'Must be an existing route'
            ],
            'structure' => [
                'required' => true,
                'type' => 'array',
                'description' => 'Complete JSON structure (replaces existing)',
                'example' => '[{"tag": "h1", "children": [{"textKey": "home.title"}]}]',
                'validation' => 'Must be valid JSON array/object'
            ]
        ],
        'example_post' => 'POST /management/editStructure with body: {"type": "page", "name": "home", "structure": [...]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure updated successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'file' => '/path/to/home.json',
                'structure_size' => 5
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type, name, or structure parameter',
            '400.validation.invalid_format' => 'Invalid type or structure format',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to write structure file',
            '500.server.internal_error' => 'Failed to encode structure to JSON'
        ],
        'notes' => 'Completely replaces the existing structure. UI should retrieve current structure with getStructure, modify it, then send back via editStructure. For menu/footer editing, no translation key management - add textKeys manually to translation files.'
    ],
    
    'help' => [
        'description' => 'Returns documentation for all available commands',
        'method' => 'GET',
        'url_structure' => '/management/help or /management/help/{commandName}',
        'parameters' => [
            '{commandName}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Specific command to get help for (as URL segment)',
                'example' => 'addRoute'
            ]
        ],
        'example_get' => 'GET /management/help (all commands) or GET /management/help/addRoute (specific)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Help documentation retrieved',
            'data' => [
                'commands' => '...',
                'total' => 8
            ]
        ]
    ]
];

// Check if specific command requested via URL segment
// URL format: /management/help/{commandName}
$urlSegments = $trimParametersManagement->additionalParams();

if (!empty($urlSegments) && isset($urlSegments[0])) {
    $cmd = $urlSegments[0];
    
    if (isset($commands[$cmd])) {
        ApiResponse::create(200, 'operation.success')
            ->withMessage('Command documentation retrieved')
            ->withData($commands[$cmd])
            ->send();
    } else {
        ApiResponse::create(404, 'route.not_found')
            ->withMessage("Command documentation not found")
            ->withData([
                'requested_command' => $cmd,
                'available_commands' => array_keys($commands)
            ])
            ->send();
    }
}

// Return all commands if no specific command requested
ApiResponse::create(200, 'operation.success')
    ->withMessage('All command documentation retrieved')
    ->withData([
        'commands' => $commands,
        'total' => count($commands),
        'base_url' => BASE_URL . '/management',
        'usage' => 'All commands except "help" and "getRoutes" require POST with JSON body. Use GET /management/help/{commandName} for specific command help.',
        'note' => 'Send parameters as JSON in request body, not as URL query parameters.'
    ])
    ->send();