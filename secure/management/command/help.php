<?php
/**
 * help - API Documentation for all management commands
 * 
 * @method GET
 * @url /management/help
 * @url /management/help/{commandName}
 * @auth required
 * @permission read
 * 
 * Returns comprehensive documentation for all API commands,
 * or detailed documentation for a specific command.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Define commands in global scope for access from __command_help()
$GLOBALS['__help_commands'] = [
    'setPublicSpace' => [
        'description' => 'Sets the URL space/prefix for the public site. Creates a subdirectory inside the public folder and moves all content there. Use empty destination to restore to root. Note: Site URL changes (e.g., http://domain/web/ when set to "web")',
        'method' => 'PATCH',
        'parameters' => [
            'destination' => [
                'required' => true,
                'type' => 'string',
                'description' => 'URL prefix/space (use empty string "" to remove space and serve from root)',
                'example' => 'web or app/v1/public',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/hyphens/underscores/forward-slash only, empty allowed'
            ]
        ],
        'example_patch' => 'PATCH /management/setPublicSpace with body: {"destination": "web"} or {"destination": ""}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Public space successfully set',
            'data' => [
                'old_path' => 'C:/path/to/public',
                'new_path' => 'C:/path/to/public/web',
                'destination' => 'web',
                'init_file_updated' => 'C:/path/to/public/web/init.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing destination parameter',
            '400.validation.invalid_type' => 'destination must be a string',
            '400.validation.invalid_format' => 'Invalid path format (max 255 chars, max 5 levels)',
            '409.conflict.same_path' => 'Source and destination are the same',
            '423.locked' => 'Operation locked by another process',
            '500.server.file_write_failed' => 'Failed to move files or update .htaccess/init.php'
        ],
        'notes' => 'Sets the URL space/prefix by creating a subdirectory inside the public folder. Updates PUBLIC_FOLDER_SPACE constant and .htaccess files. After setting to "web", site becomes http://domain/web/ and management at http://domain/web/management. Use renamePublicFolder to actually rename the public folder itself.'
    ],
    
    'renameSecureFolder' => [
        'description' => 'Renames or moves the secure/backend folder. Supports nested paths for organizing multiple deployments (e.g., backends/project1). Updates SECURE_FOLDER_NAME constant. Management URL stays the same.',
        'method' => 'PATCH',
        'parameters' => [
            'destination' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New folder path relative to server root. Can be nested (e.g., backends/project1) up to 5 levels deep. Cannot be empty.',
                'example' => 'app_backend, secure_v2, or backends/project1',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/hyphens/underscores/forward-slashes only, cannot be empty'
            ]
        ],
        'example_patch' => 'PATCH /management/renameSecureFolder with body: {"destination": "backends/mysite"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Secure folder successfully renamed',
            'data' => [
                'old_path' => '/path/to/secure_template',
                'new_path' => '/path/to/app',
                'old_name' => 'secure_template',
                'new_name' => 'app',
                'init_file_updated' => '/path/to/init.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing destination parameter',
            '400.validation.invalid_type' => 'destination must be a string',
            '400.validation.invalid_format' => 'Must be single folder name (no subdirectories/slashes), cannot be empty, max 255 chars',
            '409.conflict.same_path' => 'Source and destination are the same',
            '409.conflict.duplicate' => 'A folder with this name already exists',
            '423.locked' => 'Operation locked by another process',
            '500.server.file_write_failed' => 'Failed to rename folder or update init.php'
        ],
        'notes' => 'Renames the secure/backend folder at server root level (e.g., secure_template → app). Restricted to single folder name (no nesting) due to init.php path resolution. Management URL does NOT change. Uses file locking to prevent concurrent operations.'
    ],

    'renamePublicFolder' => [
        'description' => 'Renames the public folder at server root level. Updates all references. Requires Apache/web server config update after rename.',
        'method' => 'PATCH',
        'parameters' => [
            'destination' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New folder name (single name, no paths)',
                'example' => 'www or public or web',
                'validation' => 'Max 255 chars, single folder name only, alphanumeric/hyphens/underscores only'
            ]
        ],
        'example_patch' => 'PATCH /management/renamePublicFolder with body: {"destination": "www"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Public folder successfully renamed',
            'data' => [
                'old_path' => '/path/to/public_template',
                'new_path' => '/path/to/www',
                'old_name' => 'public_template',
                'new_name' => 'www',
                'init_file_updated' => '/path/to/www/init.php'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing destination parameter',
            '400.validation.invalid_type' => 'destination must be a string',
            '400.validation.invalid_format' => 'Must be single folder name (no paths/slashes), cannot be empty',
            '409.conflict.same_path' => 'Source and destination are the same',
            '409.conflict.duplicate' => 'A folder with this name already exists',
            '423.locked' => 'Operation locked by another process',
            '500.server.file_write_failed' => 'Failed to rename folder or update init.php'
        ],
        'notes' => 'Renames the public folder at server root level (e.g., public_template → www). After renaming, you must update your Apache/web server DocumentRoot to point to the new folder name. Uses file locking to prevent concurrent operations.'
    ],
    
    'addRoute' => [
        'description' => 'Creates a new route with PHP page template and empty JSON structure. Supports nested routes (e.g., "guides/getting-started").',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route path (will be used in URL). Can use "name" as alias for simple routes.',
                'example' => 'about-us',
                'validation' => 'Lowercase letters, numbers, and hyphens only. Max depth: 5 levels.',
                'alias' => 'name'
            ]
        ],
        'example_post' => 'POST /management/addRoute with body: {"route": "about-us"} or {"name": "about-us"}',
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
        'description' => 'Deletes an existing route and its associated files (PHP and JSON). Also removes any URL aliases pointing to this route.',
        'method' => 'DELETE',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route name to delete',
                'example' => 'about-us',
                'validation' => 'Must be an existing route'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteRoute with body: {"route": "about-us"}',
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
                'routes_updated' => '/path/to/routes.php',
                'aliases_cleaned' => [
                    ['alias' => '/old-about', 'target' => '/about-us']
                ],
                'aliases_removed_count' => 1
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route parameter',
            '400.route.invalid_name' => 'Invalid route name format',
            '404.route.not_found' => 'Route does not exist',
            '404.file.not_found' => 'Page template file not found',
            '500.server.file_write_failed' => 'Failed to delete files or update routes'
        ],
        'notes' => 'Deletes both PHP template and JSON page structure. Updates routes.php automatically. Also automatically removes any URL aliases that were pointing to this route to prevent ghost redirects.'
    ],
    
    'build' => [
        'description' => 'Creates a production-ready build with compiled PHP files, optional folder renaming, config sanitization, and ZIP archive creation',
        'method' => 'POST',
        'parameters' => [
            'public' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name/path for public folder (default: public_template)',
                'example' => 'public or www/v1/public',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/hyphens/underscores/forward-slash only'
            ],
            'secure' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name for secure folder - single level only (default: secure_template)',
                'example' => 'backend or app',
                'validation' => 'Max 255 chars, max 1 level (single folder name), alphanumeric/hyphens/underscores only'
            ],
            'space' => [
                'required' => false,
                'type' => 'string',
                'description' => 'PUBLIC_FOLDER_SPACE - subdirectory inside public folder for all public files (default: empty string)',
                'example' => '' or 'web or space/v1',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/hyphens/underscores/forward-slash only, empty allowed'
            ]
        ],
        'example_post' => 'POST /management/build with body: {"public": "www/public", "secure": "app", "space": "web"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build completed successfully',
            'data' => [
                'build_path' => '/path/to/build_20251206_143022',
                'zip_file' => '/path/to/build_20251206_143022.zip',
                'zip_size' => 1234567,
                'timestamp' => '20251206_143022',
                'original_size' => 2345678,
                'compression_ratio' => '52.6%'
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_type' => 'Parameter must be a string',
            '400.validation.invalid_format' => 'Invalid path format (check max depth and allowed characters)',
            '400.validation.shared_parent' => 'Public and secure folders cannot share the same root directory (security requirement)',
            '423.locked' => 'Build operation locked by another process',
            '500.server.build_too_large' => 'Build exceeds maximum size limit',
            '500.server.file_write_failed' => 'Failed to create build directory or copy files',
            '500.server.internal_error' => 'Build compilation or ZIP creation failed'
        ],
        'notes' => 'Compiles JSON templates to PHP using JsonToPhpCompiler. Removes management/ folder from build. Sanitizes config.php (removes DB credentials). Creates timestamped build folder and ZIP. The "space" parameter controls PUBLIC_FOLDER_SPACE - when set (e.g., "web"), all public files go inside {public}/{space}/ creating access URL like http://site.com/web/. Public and secure folders MUST have different root directories for security. Secure folder restricted to single name (no nesting) for init.php compatibility. Uses file locking to prevent concurrent builds.'
    ],
    
    'listBuilds' => [
        'description' => 'Lists all production builds with metadata including folder names, sizes, and language settings',
        'method' => 'GET',
        'parameters' => [],
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build list retrieved successfully',
            'data' => [
                'builds' => [
                    ['name' => 'build_20251214_084504', 'created' => '2025-12-14T08:45:04+00:00', 'public' => 'www', 'secure' => 'backend', 'space' => '', 'multilingual' => true, 'languages' => ['en', 'fr'], 'pages_count' => 7, 'has_zip' => true, 'has_manifest' => true, 'folder_size_mb' => 3.46, 'zip_size_mb' => 2.3]
                ],
                'total' => 1,
                'total_folder_size_mb' => 3.46,
                'total_zip_size_mb' => 2.3,
                'build_directory' => '/path/to/public/build'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Reads build_manifest.json from each build folder for accurate metadata. Legacy builds without manifest show partial info parsed from folder structure. Sorted by creation date (newest first).'
    ],
    
    'getBuild' => [
        'description' => 'Returns detailed information for a specific build including manifest data, file counts, and download URL',
        'method' => 'GET',
        'parameters' => [
            '{name}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Build folder name (URL path segment)',
                'example' => 'build_20251214_084504',
                'validation' => 'Must match format build_YYYYMMDD_HHMMSS'
            ]
        ],
        'example_get' => 'GET /management/getBuild/build_20251214_084504',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build details retrieved successfully',
            'data' => [
                'name' => 'build_20251214_084504',
                'created' => '2025-12-14T08:45:04+00:00',
                'public' => 'www',
                'secure' => 'backend',
                'folder_size_mb' => 3.46,
                'file_count' => 49,
                'download_url' => 'http://site.com/build/build_20251214_084504.zip',
                'contents' => ['LICENSE', 'README.txt', 'backend/', 'www/']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Build name missing from URL',
            '400.validation.invalid_format' => 'Invalid build name format',
            '404.build.not_found' => 'Build folder does not exist'
        ],
        'notes' => 'Returns full manifest data if available, plus calculated folder size, file count, and ZIP info. Contents shows top-level items in build folder.'
    ],
    
    'deleteBuild' => [
        'description' => 'Deletes a build folder and its associated ZIP archive',
        'method' => 'DELETE',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Build folder name to delete',
                'example' => 'build_20251214_084504',
                'validation' => 'Must match format build_YYYYMMDD_HHMMSS'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteBuild with body: {"name": "build_20251214_084504"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build deleted successfully',
            'data' => [
                'deleted_build' => 'build_20251214_084504',
                'deleted_items' => [
                    ['type' => 'folder', 'name' => 'build_20251214_084504', 'size_mb' => 3.46],
                    ['type' => 'zip', 'name' => 'build_20251214_084504.zip', 'size_mb' => 2.3]
                ],
                'space_freed_mb' => 5.76
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing name parameter',
            '400.validation.invalid_format' => 'Invalid build name format',
            '404.build.not_found' => 'Build not found (neither folder nor ZIP exists)',
            '207.operation.partial_success' => 'Some items could not be deleted',
            '500.server.file_delete_failed' => 'Failed to delete build'
        ],
        'notes' => 'Deletes both folder and ZIP if they exist. Reports freed disk space. Build name format prevents path traversal attacks.'
    ],
    
    'cleanBuilds' => [
        'description' => 'Deletes all builds older than a specified timestamp',
        'method' => 'DELETE',
        'parameters' => [
            'before' => [
                'required' => true,
                'type' => 'integer|string',
                'ui_type' => 'datetime', // Renders date/time picker in admin UI
                'description' => 'Unix timestamp or ISO 8601 date - delete builds created before this time',
                'example' => '2024-12-13T00:00:00',
                'validation' => 'Must be valid timestamp or parseable date string'
            ],
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, only list what would be deleted without actually deleting',
                'example' => true,
                'validation' => 'Boolean true/false'
            ]
        ],
        'example_delete' => 'DELETE /management/cleanBuilds with body: {"before": "2024-12-01", "dry_run": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Old builds cleaned successfully',
            'data' => [
                'before_timestamp' => 1701388800,
                'before_date' => '2024-12-01T00:00:00+00:00',
                'builds_processed' => 3,
                'space_freed_mb' => 15.5
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing before parameter',
            '400.validation.invalid_format' => 'Invalid timestamp format',
            '207.operation.partial_success' => 'Some builds could not be deleted'
        ],
        'notes' => 'Use dry_run=true first to preview what will be deleted. Useful for automated cleanup scripts. Compares build creation time from manifest or folder name.'
    ],
    
    'deployBuild' => [
        'description' => 'Copies a build to production paths (public and secure folders)',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Build folder name to deploy',
                'example' => 'build_20251214_084504',
                'validation' => 'Must match format build_YYYYMMDD_HHMMSS'
            ],
            'publicPath' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Absolute path where public folder contents should be copied',
                'example' => 'C:/wamp64/www/mysite (Windows) or /var/www/mysite (Linux)',
                'validation' => 'Must be absolute path, no path traversal (..)'
            ],
            'securePath' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Absolute path where secure folder contents should be copied',
                'example' => 'C:/wamp64/www/mysite_app',
                'validation' => 'Must be absolute path, different from publicPath, not nested'
            ],
            'overwrite' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, overwrite existing files in destination',
                'example' => false,
                'validation' => 'Boolean true/false (default: false)'
            ]
        ],
        'example_post' => 'POST /management/deployBuild with body: {"name": "build_20251214_084504", "publicPath": "C:/wamp64/www/prod", "securePath": "C:/wamp64/www/prod_app"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build deployed successfully',
            'data' => [
                'build' => 'build_20251214_084504',
                'deployed_to' => ['public' => 'C:/wamp64/www/prod', 'secure' => 'C:/wamp64/www/prod_app'],
                'public_deployment' => ['files_copied' => 28, 'directories_created' => 7],
                'secure_deployment' => ['files_copied' => 18, 'directories_created' => 6],
                'extra_files_copied' => ['LICENSE', 'README.txt', 'build_manifest.json']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_format' => 'Path must be absolute',
            '400.validation.security_violation' => 'Path traversal (..) not allowed',
            '404.build.not_found' => 'Build not found',
            '409.conflict.directory_not_empty' => 'Destination not empty (use overwrite=true)',
            '409.conflict.operation_in_progress' => 'Another deployment in progress',
            '500.server.directory_create_failed' => 'Failed to create destination directory',
            '500.server.permission_denied' => 'Destination not writable'
        ],
        'notes' => 'SECURITY: Allows copying to any absolute path - protect your API token! The secure folder should be placed outside the web root. LICENSE and README are copied to secure folder. Uses file locking to prevent concurrent deployments.'
    ],
    
    'downloadBuild' => [
        'description' => 'Returns the download URL and file info for a build ZIP archive',
        'method' => 'GET',
        'parameters' => [
            '{name}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Build folder name (URL path segment)',
                'example' => 'build_20251214_084504',
                'validation' => 'Must match format build_YYYYMMDD_HHMMSS'
            ]
        ],
        'example_get' => 'GET /management/downloadBuild/build_20251214_084504',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Download URL retrieved successfully',
            'data' => [
                'build' => 'build_20251214_084504',
                'download_url' => 'http://site.com/build/build_20251214_084504.zip',
                'filename' => 'build_20251214_084504.zip',
                'file_size_mb' => 2.3,
                'content_type' => 'application/zip',
                'manifest' => ['public' => 'www', 'secure' => 'backend', 'multilingual' => true]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Build name missing from URL',
            '400.validation.invalid_format' => 'Invalid build name format',
            '404.build.not_found' => 'Build not found',
            '404.build.zip_not_found' => 'Build exists but ZIP file not found'
        ],
        'notes' => 'Returns direct download URL for the ZIP file. Includes manifest summary if available. Use this URL to download the build via browser or wget/curl.'
    ],
    
    'getCommandHistory' => [
        'description' => 'Retrieves command execution history with optional filtering and pagination. Useful for audit trails, debugging, and AI context.',
        'method' => 'GET',
        'parameters' => [],
        'query_parameters' => [
            'start_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Filter from date (inclusive)',
                'default' => '7 days ago',
                'format' => 'YYYY-MM-DD',
                'example' => '2025-12-01',
                'ui_type' => 'date' // Renders date picker in admin UI
            ],
            'end_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Filter to date (inclusive)',
                'default' => 'today',
                'format' => 'YYYY-MM-DD',
                'example' => '2025-12-14',
                'ui_type' => 'date' // Renders date picker in admin UI
            ],
            'command' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Filter by specific command name',
                'example' => 'editStructure'
            ],
            'status' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Filter by result status',
                'allowed_values' => ['success', 'error'],
                'example' => 'success'
            ],
            'token_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Filter by token name (partial match)',
                'example' => 'Development'
            ],
            'page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Page number',
                'default' => 1
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Entries per page',
                'default' => 100,
                'max' => 500
            ],
            'dates_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'If true, only return list of available log dates with summary',
                'example' => 'true'
            ]
        ],
        'example_get' => 'GET /management/getCommandHistory?command=editStructure&status=success&limit=50',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'data' => [
                'entries' => [
                    [
                        'id' => 'log_20251214_120000_abc123',
                        'timestamp' => '2025-12-14T12:00:00+00:00',
                        'command' => 'editStructure',
                        'method' => 'POST',
                        'body' => ['type' => 'page', 'name' => 'home'],
                        'publisher' => ['token_preview' => 'tvt_dev...tion', 'token_name' => 'Dev Token'],
                        'result' => ['status' => 'success', 'code' => 'operation.success'],
                        'duration_ms' => 45.2
                    ]
                ],
                'pagination' => ['page' => 1, 'limit' => 100, 'total' => 150, 'pages' => 2]
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_date' => 'Invalid date format (expected YYYY-MM-DD)'
        ],
        'notes' => 'Logs are stored in daily files. By default returns last 7 days. The getCommandHistory command itself is not logged to prevent recursion. Request bodies are sanitized (uploadAsset files, generateToken tokens are redacted).'
    ],
    
    'clearCommandHistory' => [
        'description' => 'Deletes command log files older than a specified date. Requires confirmation to execute.',
        'method' => 'DELETE',
        'parameters' => [
            'before' => [
                'type' => 'string',
                'ui_type' => 'date', // Renders date picker in admin UI
                'required' => true,
                'description' => 'Delete logs before this date (exclusive)',
                'format' => 'YYYY-MM-DD',
                'example' => '2025-12-01'
            ],
            'confirm' => [
                'type' => 'boolean',
                'required' => true,
                'description' => 'Must be true to execute deletion. Without it, shows preview of what would be deleted.',
                'example' => true
            ]
        ],
        'example_delete' => 'DELETE /management/clearCommandHistory with body: {"before": "2025-12-01", "confirm": true}',
        'example_body' => [
            'before' => '2025-12-01',
            'confirm' => true
        ],
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Command history cleared successfully',
            'data' => [
                'deleted' => [
                    'deleted_files' => 5,
                    'deleted_entries' => 324,
                    'space_freed_kb' => 156.4
                ],
                'before_date' => '2025-12-01'
            ]
        ],
        'preview_response' => [
            'status' => 200,
            'code' => 'operation.preview',
            'message' => 'Preview: Add "confirm": true to execute deletion',
            'data' => [
                'would_delete' => ['files' => 5, 'entries' => 324, 'size_kb' => 156.4],
                'dates_affected' => ['2025-11-25', '2025-11-26', '2025-11-27']
            ]
        ],
        'error_responses' => [
            '400.validation.missing_parameter' => 'Missing required parameter: before',
            '400.validation.invalid_date' => 'Invalid date format or future date'
        ],
        'notes' => 'Without confirm=true, returns a preview showing what would be deleted. Requires admin permission.'
    ],
    
    'editFavicon' => [
        'description' => 'Updates the site favicon with a new image from assets/images folder (PNG only)',
        'method' => 'PATCH',
        'parameters' => [
            'imageName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Filename from assets/images folder (must be PNG)',
                'example' => 'logo.png',
                'validation' => 'Must exist in assets/images/, PNG format only'
            ]
        ],
        'example_patch' => 'PATCH /management/editFavicon with body: {"imageName": "logo.png"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Favicon updated successfully',
            'data' => [
                'old_favicon' => '/assets/favicon.png',
                'new_favicon' => '/assets/favicon.png',
                'backup_created' => '/assets/images/favicon_backup_20251206_143022.png',
                'source_image' => '/assets/images/logo.png'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing imageName parameter',
            '400.validation.invalid_format' => 'Invalid filename (path traversal blocked)',
            '400.asset.invalid_file_type' => 'File is not a PNG image (MIME type validation)',
            '404.file.not_found' => 'Source image not found in assets/images/',
            '500.server.file_write_failed' => 'Failed to copy or backup favicon'
        ],
        'notes' => 'Validates actual PNG MIME type, not just extension. Creates timestamped backup of old favicon. Uses basename() to prevent path traversal. Favicon must be in /assets/ root as favicon.png.'
    ],
    
    'editTitle' => [
        'description' => 'Updates page title for a specific route and language in the translation file (page.titles.{route} structure)',
        'method' => 'PATCH',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route name to update title for (must exist in ROUTES)',
                'example' => 'home',
                'validation' => 'Max 100 chars, alphanumeric/hyphens/underscores only, must be existing route, path traversal blocked'
            ],
            'lang' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code (must be in supported languages)',
                'example' => 'en',
                'validation' => '2-char code, must be supported language'
            ],
            'title' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New title text for the page',
                'example' => 'Home - My Site',
                'validation' => 'Max 200 chars, no null bytes'
            ]
        ],
        'example_patch' => 'PATCH /management/editTitle with body: {"route": "home", "lang": "en", "title": "Home - My Site"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page title updated successfully',
            'data' => [
                'route' => 'home',
                'lang' => 'en',
                'title' => 'Home - My Site',
                'file_updated' => '/translate/en.json'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing route, lang, or title parameter',
            '400.validation.invalid_type' => 'route, lang, or title must be a string',
            '400.validation.invalid_format' => 'Invalid characters in route (path traversal blocked)',
            '400.validation.invalid_length' => 'route too long (>100) or title too long (>200 chars)',
            '400.validation.invalid_lang' => 'Language not in supported languages list',
            '404.validation.invalid_route' => 'Route does not exist in ROUTES',
            '404.file.not_found' => 'Translation file not found for language',
            '500.server.file_read_failed' => 'Failed to read translation file',
            '500.server.file_write_failed' => 'Failed to write updated translation file',
            '500.server.internal_error' => 'Invalid JSON in translation file'
        ],
        'notes' => 'Updates ONE language at a time for single route. Updates page.titles.{route} key in the specified language translation file. Creates nested page.titles object if it doesn\'t exist. Used by Page.php: $translator->translate("page.titles.{$route}"). Route must exist in ROUTES constant, and language must be in supported languages list.'
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
    
    'getSiteMap' => [
        'description' => 'Generates a complete sitemap of all routes × all languages. Useful for SEO sitemap.txt generation and Dashboard insights.',
        'method' => 'GET',
        'url_structure' => '/management/getSiteMap/{format?}',
        'parameters' => [
            '{format}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Output format (URL segment)',
                'example' => 'json',
                'validation' => 'json|text',
                'default' => 'json'
            ]
        ],
        'example_get' => 'GET /management/getSiteMap or GET /management/getSiteMap/text',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Sitemap generated successfully',
            'data' => [
                'baseUrl' => 'https://example.com',
                'multilingual' => true,
                'languages' => ['en', 'fr'],
                'defaultLang' => 'en',
                'languageNames' => ['en' => 'English', 'fr' => 'Français'],
                'routes' => [
                    ['name' => 'home', 'path' => '/', 'urls' => ['en' => 'https://example.com/en', 'fr' => 'https://example.com/fr']],
                    ['name' => 'about', 'path' => '/about', 'urls' => ['en' => 'https://example.com/en/about', 'fr' => 'https://example.com/fr/about']]
                ],
                'urls' => ['https://example.com/en', 'https://example.com/fr', 'https://example.com/en/about', 'https://example.com/fr/about'],
                'totalUrls' => 4,
                'coverage' => [
                    'en' => ['code' => 'en', 'name' => 'English', 'isDefault' => true, 'hasTranslations' => true, 'translationKeyCount' => 150],
                    'fr' => ['code' => 'fr', 'name' => 'Français', 'isDefault' => false, 'hasTranslations' => true, 'translationKeyCount' => 145]
                ]
            ]
        ],
        'text_response' => 'When format=text, returns plain text with one URL per line (Content-Type: text/plain). Suitable for saving directly as sitemap.txt for SEO crawlers.',
        'error_responses' => [
            '400.validation.invalid_format' => 'Invalid format parameter (must be text or json)'
        ],
        'notes' => 'Use format=json (default) for programmatic access and Dashboard. Use format=text to generate sitemap.txt for SEO. Coverage data includes translation key counts per language to help identify incomplete translations.'
    ],
    
    'getStructure' => [
        'description' => 'Retrieves the JSON structure for a page, menu, footer, or component. Supports node identifiers for targeted retrieval.',
        'method' => 'GET',
        'url_structure' => '/management/getStructure/{type}/{name?}/{option?}',
        'parameters' => [
            '{type}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Type of structure to retrieve (URL segment)',
                'example' => 'page',
                'validation' => 'Must be one of: page, menu, footer, component'
            ],
            '{name}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Name (required for page/component, optional for menu/footer)',
                'example' => 'home',
                'validation' => 'Must be an existing route (for pages) or component name'
            ],
            '{option}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional: "showIds" to add _nodeId to all nodes, "summary" for tree overview, or a nodeId (e.g., "0.2.1") to get specific node',
                'example' => 'showIds, summary, 0.2.1',
                'validation' => 'Either "showIds", "summary", or dot-notation number (0, 0.1, 0.1.2, etc.)'
            ]
        ],
        'example_get' => 'GET /management/getStructure/page/home, GET /management/getStructure/page/home/showIds, GET /management/getStructure/page/home/summary, GET /management/getStructure/page/home/0.0.2',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure retrieved successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'structure' => '(JSON structure with optional _nodeId on each node)',
                'file' => '/path/to/home.json',
                'nodeIds' => 'included or not included'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type or name in URL',
            '400.validation.invalid_format' => 'Invalid type or option format',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '404.node.not_found' => 'Node not found at specified identifier',
            '500.server.file_write_failed' => 'Failed to read structure file'
        ],
        'notes' => 'Node identifiers use 0-indexed dot notation: "0.2.1" = root\'s 1st child → 3rd child → 2nd child. Use /summary to see structure overview with nodeIds. Use specific nodeId to retrieve just that node.'
    ],

    'editStructure' => [
        'description' => 'Updates JSON structure for page/menu/footer/component. Supports targeted node editing via nodeId parameter.',
        'method' => 'PATCH',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Type of structure to update',
                'example' => 'page',
                'validation' => 'Must be one of: page, menu, footer, component'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Name (required for page/component)',
                'example' => 'home',
                'validation' => 'Must be existing route (pages) or alphanumeric/hyphens/underscores (components)'
            ],
            'structure' => [
                'required' => true,
                'type' => 'array/object',
                'description' => 'JSON structure. Full replacement if no nodeId, single node if nodeId provided. Not required for action=delete.',
                'example' => '{"tag": "h2", "children": [{"textKey": "title"}]}',
                'validation' => 'Must be valid JSON, max 10,000 nodes, max 50 levels deep'
            ],
            'nodeId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Target node identifier for surgical edits (e.g., "0.2.1"). When provided, only that node is affected.',
                'example' => '0.2.1',
                'validation' => 'Dot-notation numbers (0, 0.1, 0.1.2, etc.)'
            ],
            'action' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Action to perform when nodeId is provided',
                'default' => 'update',
                'example' => 'update, delete, insertBefore, insertAfter',
                'validation' => 'Must be: update (replace node), delete (remove node), insertBefore, insertAfter'
            ]
        ],
        'example_patch' => 'Full: {"type": "page", "name": "home", "structure": [...]}. Targeted: {"type": "page", "name": "home", "nodeId": "0.2", "structure": {...}}. Delete: {"type": "page", "name": "home", "nodeId": "0.2", "action": "delete"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure/Node updated successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'nodeId' => '0.2 (if targeted edit)',
                'action' => 'updated/deleted/inserted',
                'file' => '/path/to/home.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type, name, or structure parameter',
            '400.validation.invalid_format' => 'Invalid type, nodeId format, or structure format',
            '400.operation.failed' => 'Node operation failed (e.g., node not found)',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to write structure file',
            '500.server.internal_error' => 'Failed to encode structure to JSON'
        ],
        'notes' => 'Two modes: (1) Full replacement - sends complete structure. (2) Targeted edit - use nodeId to modify single node. Use getStructure/page/name/showIds to see node identifiers first. Actions: update (replace), delete (remove), insertBefore/insertAfter (add sibling). Security: max 10,000 nodes, max 50 levels deep.'
    ],
    
    'getTranslation' => [
        'description' => 'Retrieves translations for a single language',
        'method' => 'GET',
        'url_structure' => '/management/getTranslation/{lang}',
        'parameters' => [
            '{lang}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code (URL segment), or "default" for mono-language mode',
                'example' => 'en',
                'validation' => '2-3 lowercase letters, or literal "default"'
            ]
        ],
        'example_get' => 'GET /management/getTranslation/en',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation retrieved successfully',
            'data' => [
                'language' => 'en',
                'translations' => '(translation object)',
                'file' => '/path/to/en.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing language code in URL',
            '400.validation.invalid_format' => 'Invalid language code format',
            '404.file.not_found' => 'Translation file not found',
            '500.server.file_write_failed' => 'Failed to read translation file'
        ],
        'notes' => 'Returns translations for a single language. Use language="default" in mono-language mode to access default.json.'
    ],
    
    'getTranslations' => [
        'description' => 'Retrieves translations for all languages (mode-aware)',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getTranslations',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translations retrieved successfully',
            'data' => [
                'translations' => [
                    'en' => '(translation object)',
                    'fr' => '(translation object)'
                ],
                'languages' => ['en', 'fr'],
                'multilingual_enabled' => true
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'No translation files found'
        ],
        'notes' => 'In multilingual mode: returns all language files (en.json, fr.json, etc.). In mono-language mode: returns only default.json. Response includes multilingual_enabled flag.'
    ],
    
    'setTranslationKeys' => [
        'description' => 'Sets/updates specific translation keys (merge, not replace)',
        'method' => 'PATCH',
        'parameters' => [
            'language' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code, or "default" for mono-language mode',
                'example' => 'en',
                'validation' => '2-3 lowercase letters, or literal "default"'
            ],
            'translations' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Translation keys to add or update (existing keys preserved)',
                'example' => '{"menu": {"home": "Home"}, "footer": {"new_key": "value"}}',
                'validation' => 'Must be valid JSON object'
            ]
        ],
        'example_patch' => 'PATCH /management/setTranslationKeys with body: {"language": "en", "translations": {"home": {"title": "New Title"}}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation keys updated successfully',
            'data' => [
                'language' => 'en',
                'file' => '/path/to/en.json',
                'keys_added' => 2,
                'keys_updated' => 1,
                'keys_unchanged' => 'preserved'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing language or translations parameter',
            '400.validation.invalid_format' => 'Invalid translation format',
            '500.server.file_write_failed' => 'Failed to write translation file'
        ],
        'notes' => 'SAFE: Merges with existing translations. Use language="default" in mono-language mode to edit default.json. New keys are added, existing keys are updated, other keys are preserved.'
    ],
    
    'deleteTranslationKeys' => [
        'description' => 'Deletes specific translation keys from a language file',
        'method' => 'DELETE',
        'parameters' => [
            'language' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code, or "default" for mono-language mode',
                'example' => 'en',
                'validation' => '2-3 lowercase letters, or literal "default"'
            ],
            'keys' => [
                'required' => true,
                'type' => 'array',
                'description' => 'Array of keys to delete (supports dot notation)',
                'example' => '["home.old_key", "footer.deprecated", "menu.removed_item"]',
                'validation' => 'Each key must be a non-empty string'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteTranslationKeys with body: {"language": "en", "keys": ["home.old_key", "deprecated_section"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation keys deleted successfully',
            'data' => [
                'language' => 'en',
                'file' => '/path/to/en.json',
                'deleted' => ['home.old_key'],
                'deleted_count' => 1,
                'not_found' => ['nonexistent.key'],
                'not_found_count' => 1
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing language or keys parameter',
            '404.resource.not_found' => 'Translation file not found or no keys deleted',
            '500.server.file_write_failed' => 'Failed to write translation file'
        ],
        'notes' => 'Supports dot notation for nested keys. Use language="default" in mono-language mode. Empty parent objects are automatically cleaned up after deletion.'
    ],
    
    'getLangList' => [
        'description' => 'Returns list of configured languages and multilingual settings',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getLangList',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Language list retrieved successfully',
            'data' => [
                'multilingual_enabled' => true,
                'languages' => ['en', 'fr'],
                'default_language' => 'en',
                'language_names' => [
                    'en' => 'English',
                    'fr' => 'Français'
                ]
            ]
        ],
        'error_responses' => [],
        'notes' => 'Returns configuration from config.php. Useful for UI language selectors and checking current mode.'
    ],
    
    'setMultilingual' => [
        'description' => 'Enable or disable multilingual support. Requires at least 2 languages to enable. Syncs translations between default.json and default language file.',
        'method' => 'PATCH',
        'parameters' => [
            'enabled' => [
                'required' => true,
                'type' => 'boolean',
                'description' => 'true for multilingual mode, false for mono-language mode',
                'example' => true,
                'validation' => 'Must be boolean true/false'
            ]
        ],
        'example_patch' => 'PATCH /management/setMultilingual with body: {"enabled": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Successfully switched to multilingual mode',
            'data' => [
                'multilingual_enabled' => true,
                'changed' => true,
                'mode' => 'multilingual',
                'default_language' => 'en',
                'sync' => [
                    'direction' => 'default.json → en.json',
                    'keys_added' => 42
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing enabled parameter',
            '400.validation.invalid_type' => 'enabled must be boolean',
            '400.validation.invalid_format' => 'Multilingual mode requires at least 2 languages (use addLang first)',
            '500.server.file_write_failed' => 'Failed to update config.php or translation files'
        ],
        'notes' => 'To enable multilingual: 1) Use addLang to add languages first, 2) Then use setMultilingual(enabled=true). When switching modes, translations are synced: mono→multi copies default.json keys to default language file, multi→mono copies default language to default.json.'
    ],
    
    'checkStructureMulti' => [
        'description' => 'Scans all structures (pages, menu, footer, components) for lang-specific content. Use before switching to mono-language mode.',
        'method' => 'GET',
        'parameters' => [],
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Found 4 multilingual-specific pattern(s). Review before switching to mono-language mode.',
            'data' => [
                'status' => 'has_multilingual_content',
                'total_findings' => 4,
                'findings_by_source' => [
                    'footer' => [
                        ['path' => 'children.0.children.1...', 'pattern' => 'lang= parameter', 'match' => 'lang=en', 'value' => '{{__current_page;lang=en}}']
                    ]
                ],
                'affected_sources' => ['footer'],
                'scanned' => [
                    'pages' => ['home', 'docs', '...'],
                    'menu' => true,
                    'footer' => true,
                    'components' => ['menu-link', '...']
                ],
                'recommendation' => 'Remove or update lang-specific content before switching to mono-language mode'
            ]
        ],
        'error_responses' => [
            '500.server.file_read_failed' => 'Failed to read structure files'
        ],
        'notes' => 'Detects patterns like lang=XX, {{__current_page;lang=XX}}, ?lang= query params, and /XX/ path segments. Returns "clean" status if no multilingual content found. Useful to audit before setMultilingual(enabled=false).'
    ],
    
    'addLang' => [
        'description' => 'Adds a new language to the system. Can be used before or after enabling multilingual mode.',
        'method' => 'POST',
        'parameters' => [
            'code' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code (ISO 639-1). Can use "lang" as shorthand alias.',
                'example' => 'es',
                'validation' => '2-3 lowercase letters',
                'alias' => 'lang'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language display name. Auto-generated if not provided (e.g., "fr" → "French").',
                'example' => 'Español',
                'validation' => 'Any string',
                'default' => 'Auto-generated from language code'
            ]
        ],
        'example_post' => 'POST /management/addLang with body: {"lang": "fr"} or {"code": "es", "name": "Español"}',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.success',
            'message' => 'Language added successfully',
            'data' => [
                'code' => 'es',
                'name' => 'Español',
                'config_updated' => '/path/to/config.php',
                'translation_file' => '/path/to/es.json',
                'copied_from' => 'en'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing code/lang parameter',
            '400.validation.invalid_format' => 'Invalid language code format',
            '409.conflict.duplicate' => 'Language already exists',
            '500.server.file_write_failed' => 'Failed to update config or create translation file'
        ],
        'notes' => 'Can be used before enabling multilingual mode (to add languages first). Use setMultilingual to enable multilingual mode after adding 2+ languages. Updates config.php and creates translation file by copying from default language.'
    ],
    
    'deleteLang' => [
        'description' => 'Deletes a language from the system. Requires MULTILINGUAL_SUPPORT = true.',
        'method' => 'DELETE',
        'requires_mode' => 'multilingual',
        'parameters' => [
            'code' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code to delete',
                'example' => 'es',
                'validation' => 'Must be an existing language (not default, not last)'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteLang with body: {"code": "es"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Language deleted successfully',
            'data' => [
                'code' => 'es',
                'config_updated' => '/path/to/config.php',
                'translation_file_deleted' => true,
                'remaining_languages' => ['en', 'fr']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing code parameter',
            '400.validation.invalid_format' => 'Cannot delete default or last language',
            '403.mode.requires_multilingual' => 'This command requires multilingual mode',
            '404.route.not_found' => 'Language not found',
            '500.server.file_write_failed' => 'Failed to update config'
        ],
        'notes' => 'Only available when MULTILINGUAL_SUPPORT = true. Cannot delete default language or last remaining language.'
    ],
    
    'setDefaultLang' => [
        'description' => 'Sets the default language for the site. The language must already exist in LANGUAGES_SUPPORTED.',
        'method' => 'PATCH',
        'requires_mode' => 'multilingual',
        'parameters' => [
            'lang' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code to set as default',
                'example' => 'fr',
                'validation' => '2-3 lowercase letters, must exist in LANGUAGES_SUPPORTED'
            ]
        ],
        'example_patch' => 'PATCH /management/setDefaultLang with body: {"lang": "fr"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Default language updated successfully',
            'data' => [
                'new_default' => ['code' => 'fr', 'name' => 'Français'],
                'previous_default' => ['code' => 'en', 'name' => 'English'],
                'config_updated' => true
            ]
        ],
        'error_responses' => [
            '200.operation.no_change' => 'Language is already the default',
            '400.validation.required' => 'Missing lang parameter',
            '400.validation.invalid_format' => 'Invalid language code format',
            '403.mode.requires_multilingual' => 'This command requires multilingual mode',
            '404.not_found.language' => 'Language not found in LANGUAGES_SUPPORTED',
            '500.server.file_write_failed' => 'Failed to update config file'
        ],
        'notes' => 'Only available when MULTILINGUAL_SUPPORT = true. The language must first be added using addLang. This affects the LANGUAGE_DEFAULT config value.'
    ],
    
    'getTranslationKeys' => [
        'description' => 'Scans all JSON structures and extracts required translation keys. Optionally includes translation status per key.',
        'method' => 'GET',
        'url_structure' => '/management/getTranslationKeys/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to check translation status (URL segment). If provided, returns translated/untranslated status for each key',
                'example' => 'en',
                'validation' => '2-10 characters (ISO 639 or BCP 47 locale code)'
            ]
        ],
        'example_get' => 'GET /management/getTranslationKeys (keys only) or GET /management/getTranslationKeys/fr (with translation status)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation keys extracted successfully',
            'data' => [
                'keys_by_source' => [
                    'home' => ['home.title', 'home.welcomeMessage'],
                    'menu' => ['menu.home', 'menu.about'],
                    'footer' => ['footer.privacy', 'footer.terms']
                ],
                'all_keys' => ['home.title', 'home.welcomeMessage', 'menu.home', '...'],
                'total_keys' => 15,
                'scanned_files' => [
                    'pages' => ['home', 'privacy', 'terms'],
                    'menu' => true,
                    'footer' => true
                ],
                'keys_status' => '(only with lang parameter) [{"key": "home.title", "translated": true}, ...]',
                'language' => '(only with lang parameter) "en"',
                'translated_count' => '(only with lang parameter) 12',
                'untranslated_count' => '(only with lang parameter) 3',
                'coverage_percent' => '(only with lang parameter) 80.0'
            ]
        ],
        'error_responses' => [
            [
                'status' => 400,
                'code' => 'validation.invalid_format',
                'message' => 'Invalid language code format'
            ]
        ],
        'notes' => 'Recursively scans all page JSONs, menu.json, and footer.json to extract textKey values. Ignores __RAW__ prefixed keys. When language is provided, also checks if each key has a non-empty translation (empty string = untranslated).'
    ],
    
    'validateTranslations' => [
        'description' => 'Validates translation completeness by comparing required keys with existing translations',
        'method' => 'GET',
        'url_structure' => '/management/validateTranslations/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to validate (URL segment). If omitted, validates all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/validateTranslations (all languages) or GET /management/validateTranslations/fr (specific)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation validation complete',
            'data' => [
                'validation_results' => [
                    'en' => [
                        'file_exists' => true,
                        'file_valid' => true,
                        'required_keys' => 15,
                        'missing_keys' => [],
                        'total_missing' => 0,
                        'coverage_percent' => 100
                    ],
                    'fr' => [
                        'file_exists' => true,
                        'file_valid' => true,
                        'required_keys' => 15,
                        'missing_keys' => ['menu.newpage', 'footer.copyright'],
                        'total_missing' => 2,
                        'coverage_percent' => 86.67
                    ]
                ],
                'total_required_keys' => 15,
                'languages_validated' => ['en', 'fr']
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_format' => 'Invalid language code format'
        ],
        'notes' => 'Compares keys from getTranslationKeys with actual translation files. Shows missing keys per language and coverage percentage. Use this to identify incomplete translations before deployment.'
    ],
    
    'getUnusedTranslationKeys' => [
        'description' => 'Finds translation keys that exist in translation files but are not used in any structure',
        'method' => 'GET',
        'url_structure' => '/management/getUnusedTranslationKeys/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to check (URL segment). If omitted, checks all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/getUnusedTranslationKeys or GET /management/getUnusedTranslationKeys/en',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Found X unused translation key(s)',
            'data' => [
                'results' => [
                    'en' => [
                        'file_exists' => true,
                        'total_translation_keys' => 50,
                        'unused_keys' => ['old.key', 'deprecated.section'],
                        'total_unused' => 2,
                        'usage_percent' => 96
                    ]
                ],
                'total_unused_across_languages' => 2,
                'recommendation' => 'Consider removing unused keys with deleteTranslationKeys command'
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_format' => 'Invalid language code format'
        ],
        'notes' => 'Identifies orphaned translations not referenced by any page, menu, footer, or component. Useful for cleaning up after refactoring. Use deleteTranslationKeys to remove identified unused keys.'
    ],
    
    'analyzeTranslations' => [
        'description' => 'Complete translation health check - finds both missing AND unused keys',
        'method' => 'GET',
        'url_structure' => '/management/analyzeTranslations/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to analyze (URL segment). If omitted, analyzes all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/analyzeTranslations or GET /management/analyzeTranslations/fr',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation analysis complete',
            'data' => [
                'summary' => [
                    'total_required_keys' => 15,
                    'total_missing_across_languages' => 2,
                    'total_unused_across_languages' => 5,
                    'health_status' => 'needs_attention'
                ],
                'analysis' => [
                    'en' => [
                        'status' => 'healthy|has_unused|incomplete|needs_attention',
                        'missing_keys' => [],
                        'unused_keys' => ['old.key'],
                        'coverage_percent' => 100,
                        'efficiency_percent' => 98
                    ]
                ],
                'recommendations' => ['Add missing translations...', 'Clean up unused keys...']
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_format' => 'Invalid language code format'
        ],
        'notes' => 'Combines validateTranslations + getUnusedTranslationKeys in one call. Returns health status: healthy, has_unused, incomplete, needs_attention, or critical. Ideal for CI/CD pipelines and dashboard views.'
    ],
    
    'uploadAsset' => [
        'description' => 'Uploads a file to the assets folder with validation and automatic naming',
        'method' => 'POST',
        'content_type' => 'multipart/form-data',
        'parameters' => [
            'category' => [
                'required' => true,
                'type' => 'query_string',
                'description' => 'Asset category (in URL query string)',
                'example' => 'images',
                'validation' => 'Must be one of: images, scripts, font, audio, videos'
            ],
            'file' => [
                'required' => true,
                'type' => 'file',
                'description' => 'File to upload (in multipart form data)',
                'validation' => 'See size and type limits below'
            ]
        ],
        'size_limits' => [
            'images' => '5MB',
            'scripts' => '1MB',
            'font' => '2MB',
            'audio' => '10MB',
            'videos' => '50MB'
        ],
        'allowed_types' => [
            'images' => 'JPEG, PNG, GIF, WebP, SVG',
            'scripts' => 'JavaScript (.js)',
            'font' => 'TTF, OTF, WOFF, WOFF2',
            'audio' => 'MP3, WAV, OGG',
            'videos' => 'MP4, WebM, OGV'
        ],
        'example_curl' => 'curl -F "file=@logo.png" "http://yoursite.com/management?command=uploadAsset&category=images"',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.success',
            'message' => 'File uploaded successfully',
            'data' => [
                'filename' => 'logo.png',
                'category' => 'images',
                'path' => '/assets/images/logo.png',
                'size' => 45678,
                'mime_type' => 'image/png'
            ]
        ],
        'error_responses' => [
            '400.asset.invalid_category' => 'Invalid category',
            '400.asset.upload_failed' => 'File upload error',
            '400.asset.file_too_large' => 'File exceeds size limit',
            '400.asset.invalid_file_type' => 'MIME type not allowed',
            '400.asset.invalid_extension' => 'File extension not allowed',
            '500.asset.move_failed' => 'Failed to save file'
        ],
        'notes' => 'Validates MIME type (actual content, not just extension). Sanitizes filename. Auto-renames if file exists (adds _1, _2, etc.). Use multipart/form-data encoding.'
    ],
    
    'deleteAsset' => [
        'description' => 'Deletes a file from the assets folder',
        'method' => 'DELETE',
        'parameters' => [
            'category' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Asset category',
                'example' => 'images',
                'validation' => 'Must be one of: images, scripts, font, audio, videos'
            ],
            'filename' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Filename to delete',
                'example' => 'logo.png',
                'validation' => 'Must exist in specified category'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteAsset {"category": "images", "filename": "logo.png"}',
        'success_response' => [
            'status' => 204,
            'code' => 'operation.success',
            'message' => 'File deleted successfully',
            'data' => [
                'filename' => 'logo.png',
                'category' => 'images'
            ]
        ],
        'error_responses' => [
            '400.asset.invalid_category' => 'Invalid category',
            '400.validation.required' => 'Missing filename',
            '400.asset.invalid_filename' => 'Invalid filename (path traversal blocked)',
            '404.asset.not_found' => 'File not found',
            '500.asset.delete_failed' => 'Failed to delete file'
        ],
        'notes' => 'Includes path traversal protection. Only deletes files, not directories. Returns 204 on success.'
    ],
    
    'listAssets' => [
        'description' => 'Lists all files in assets folder, optionally filtered by category',
        'method' => 'GET',
        'url_structure' => '/management/listAssets/{category?}',
        'parameters' => [
            '{category}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Filter by category (URL segment, optional)',
                'example' => 'images',
                'validation' => 'If provided, must be one of: images, scripts, font, audio, videos'
            ]
        ],
        'example_get' => 'GET /management/listAssets (all) or GET /management/listAssets/images (filtered)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Assets retrieved for category images',
            'data' => [
                'assets' => [
                    'images' => [
                        [
                            'filename' => 'logo.png',
                            'size' => 45678,
                            'modified' => '2025-12-03 10:30:15',
                            'path' => '/assets/images/logo.png'
                        ],
                        [
                            'filename' => 'banner.jpg',
                            'size' => 123456,
                            'modified' => '2025-12-02 14:22:10',
                            'path' => '/assets/images/banner.jpg'
                        ]
                    ]
                ],
                'total_categories' => 1,
                'total_files' => 2
            ]
        ],
        'error_responses' => [
            '400.asset.invalid_category' => 'Invalid category'
        ],
        'notes' => 'Returns files sorted alphabetically. Excludes index.php files. Shows size in bytes and last modified timestamp. Includes metadata (description, alt, dimensions) when available.'
    ],
    
    'updateAssetMeta' => [
        'description' => 'Updates metadata (description, alt text) for an existing asset. Useful for AI context and accessibility.',
        'method' => 'POST',
        'parameters' => [
            'category' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Asset category',
                'example' => 'images',
                'validation' => 'Must be one of: images, scripts, font, audio, videos'
            ],
            'filename' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the file to update metadata for',
                'example' => 'logo.png',
                'validation' => 'Must exist in specified category'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Description of the asset for AI context and documentation',
                'example' => 'Company logo displayed in header',
                'validation' => 'Max 500 characters'
            ],
            'alt' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Alt text for images (accessibility)',
                'example' => 'Acme Corp logo - blue mountains on white background',
                'validation' => 'Max 200 characters'
            ]
        ],
        'example_request' => 'POST /management/updateAssetMeta {"category": "images", "filename": "logo.png", "description": "Main company logo", "alt": "Company logo"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Asset metadata updated successfully',
            'data' => [
                'category' => 'images',
                'filename' => 'logo.png',
                'metadata' => [
                    'description' => 'Main company logo',
                    'alt' => 'Company logo',
                    'dimensions' => ['width' => 200, 'height' => 50]
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing required parameter (category or filename)',
            '400.validation.invalid_type' => 'Parameter must be a string',
            '400.validation.invalid_value' => 'Invalid category',
            '400.validation.invalid_length' => 'Description or alt text too long',
            '400.validation.no_updates' => 'At least one metadata field required',
            '404.asset.not_found' => 'Asset file not found'
        ],
        'notes' => 'Metadata is stored in secure/config/assets_metadata.json. At least one of description or alt must be provided. Image dimensions are auto-detected if not already stored.'
    ],
    
    'getStyles' => [
        'description' => 'Retrieves the content of the main SCSS/CSS file',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management?command=getStyles',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style file retrieved successfully',
            'data' => [
                'content' => '/* CSS content here */',
                'file' => '/path/to/style.css',
                'size' => 12345,
                'modified' => '2025-12-03 10:30:15'
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to read style file'
        ],
        'notes' => 'Returns the complete content of style.css. Use this to retrieve current styles before editing.'
    ],
    
    'editStyles' => [
        'description' => 'Updates the content of the main SCSS/CSS file',
        'method' => 'PUT',
        'parameters' => [
            'content' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Complete CSS/SCSS content (replaces existing file). Can use "css" as alias.',
                'example' => 'body { margin: 0; }',
                'validation' => 'Must be string, max 2MB',
                'alias' => 'css'
            ]
        ],
        'example_put' => 'PUT /management/editStyles with body: {"content": "body { margin: 0; }"} or {"css": "body { margin: 0; }"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style file updated successfully',
            'data' => [
                'file' => '/path/to/style.css',
                'new_size' => 1234,
                'old_size' => 1200,
                'backup_content' => '/* old content */',
                'modified' => '2025-12-03 10:35:20'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing content parameter',
            '400.validation.invalid_format' => 'Content must be string or exceeds 2MB limit',
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to read or write style file'
        ],
        'notes' => 'Completely replaces style.css content. Response includes backup_content for manual rollback if needed. Max size: 2MB. File locking prevents concurrent writes.'
    ],
    
    // ==========================================================================
    // CSS VARIABLES & RULES MANAGEMENT
    // ==========================================================================
    
    'getRootVariables' => [
        'description' => 'Retrieves all CSS custom properties (variables) defined in the :root selector',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getRootVariables',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Root variables retrieved successfully',
            'data' => [
                'variables' => [
                    '--color-primary' => '#007bff',
                    '--color-secondary' => '#6c757d',
                    '--spacing-md' => '1rem'
                ],
                'count' => 3
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found',
            '404.root.not_found' => 'No :root block found in CSS',
            '500.server.file_read_failed' => 'Failed to read style file'
        ],
        'notes' => 'Returns all CSS variables from the :root selector. Variable names include the -- prefix. Use setRootVariables to modify.'
    ],
    
    'setRootVariables' => [
        'description' => 'Add or update CSS custom properties (variables) in the :root selector',
        'method' => 'PATCH',
        'parameters' => [
            'variables' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Object of variable names and values to set/update',
                'example' => '{"--color-primary": "#ff6600", "--new-var": "10px"}',
                'validation' => 'Variable names must start with -- or will be auto-prefixed'
            ]
        ],
        'example_patch' => 'PATCH /management/setRootVariables with body: {"variables": {"--color-primary": "#ff6600"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Root variables updated successfully',
            'data' => [
                'added' => ['--new-variable' => 'value'],
                'updated' => ['--color-primary' => '#ff6600'],
                'total_changes' => 2,
                'current_variables' => ['/* all variables */']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing variables parameter',
            '400.validation.invalid_format' => 'Variables must be a non-empty object',
            '400.validation.security' => 'Dangerous CSS pattern detected',
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to write style file'
        ],
        'notes' => 'Adds new variables or updates existing ones. Security validated against CSS injection. File locking prevents concurrent writes. Creates :root block if not exists.'
    ],
    
    'listStyleRules' => [
        'description' => 'Lists all CSS selectors in the stylesheet, organized by global and media query scopes',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listStyleRules',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style rules listed successfully',
            'data' => [
                'global' => [':root', 'body', '.btn', '.container'],
                'mediaQueries' => [
                    '(max-width: 768px)' => ['.hero', '.nav']
                ],
                'totalSelectors' => 100,
                'totalMediaQueries' => 3
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found',
            '500.server.file_read_failed' => 'Failed to read style file'
        ],
        'notes' => 'Returns overview of all CSS selectors organized by scope. Use getStyleRule to get specific rule details.'
    ],
    
    'getStyleRule' => [
        'description' => 'Get CSS styles for a specific selector, optionally within a media query context',
        'method' => 'GET',
        'url_structure' => '/management/getStyleRule/{selector} or /management/getStyleRule/{selector}/{mediaQuery}',
        'parameters' => [
            '{selector}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'CSS selector (URL-encoded if contains special chars)',
                'example' => '.btn-primary or body'
            ],
            '{mediaQuery}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Media query context (URL-encoded)',
                'example' => '(max-width: 768px)'
            ]
        ],
        'example_get' => 'GET /management/getStyleRule/.btn-primary or GET /management/getStyleRule/.hero/(max-width%3A%20768px)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style rule retrieved successfully',
            'data' => [
                'selector' => '.btn-primary',
                'styles' => 'background-color: var(--color-secondary); color: white;',
                'mediaQuery' => null
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing selector parameter',
            '404.file.not_found' => 'Style file not found',
            '404.selector.not_found' => 'Selector not found (in specified scope)'
        ],
        'notes' => 'URL-encode selectors with special characters. Returns styles as raw CSS string. Use listStyleRules to discover available selectors.'
    ],
    
    'setStyleRule' => [
        'description' => 'Add, update, or selectively remove properties from a CSS rule',
        'method' => 'PATCH',
        'parameters' => [
            'selector' => [
                'required' => true,
                'type' => 'string',
                'description' => 'CSS selector to add/update',
                'example' => '.my-class or #my-id'
            ],
            'styles' => [
                'required' => 'conditional',
                'type' => 'string|object',
                'description' => 'CSS declarations as string or object. Required unless removeProperties is provided.',
                'example' => '"background: #fff; padding: 10px;" or {"background": "#fff", "padding": "10px"}'
            ],
            'removeProperties' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Array of property names to remove from the rule. If all properties are removed, the entire rule is deleted.',
                'example' => '["margin", "padding", "border"]'
            ],
            'mediaQuery' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Media query context (creates if not exists)',
                'example' => '(max-width: 768px)'
            ]
        ],
        'example_post' => 'POST /management/setStyleRule with body: {"selector": ".btn-custom", "styles": {"background": "#007bff", "color": "white"}}',
        'example_remove' => 'PATCH /management/setStyleRule with body: {"selector": ".btn-custom", "removeProperties": ["margin", "padding"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style rule added/updated successfully',
            'data' => [
                'action' => 'added|updated|deleted',
                'selector' => '.btn-custom',
                'mediaQuery' => null,
                'styles' => 'background: #007bff; color: white;',
                'removedProperties' => ['margin', 'padding']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing selector, or neither styles nor removeProperties provided',
            '400.validation.invalid_format' => 'Invalid selector or styles format',
            '400.validation.security' => 'Dangerous CSS pattern detected (javascript:, expression(), etc.)',
            '400.validation.invalid_media_query' => 'Invalid media query format',
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to write style file'
        ],
        'notes' => 'Styles can be string or object format. Use removeProperties to selectively delete properties. If removing properties leaves the rule empty, it is automatically deleted (action: deleted). Security validated.'
    ],
    
    'deleteStyleRule' => [
        'description' => 'Remove a CSS rule from the stylesheet',
        'method' => 'DELETE',
        'parameters' => [
            'selector' => [
                'required' => true,
                'type' => 'string',
                'description' => 'CSS selector to delete',
                'example' => '.unused-class'
            ],
            'mediaQuery' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Media query context to delete from',
                'example' => '(max-width: 768px)'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteStyleRule with body: {"selector": ".unused-class"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style rule deleted successfully',
            'data' => [
                'selector' => '.unused-class',
                'mediaQuery' => null
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing selector parameter',
            '404.file.not_found' => 'Style file not found',
            '404.selector.not_found' => 'Selector not found (in specified scope)',
            '500.server.file_write_failed' => 'Failed to write style file'
        ],
        'notes' => 'Permanently removes the CSS rule. Use getStyleRule first to confirm selector exists. Cannot be undone.'
    ],
    
    'listKeyframes' => [
        'description' => 'Returns a lightweight list of all @keyframes animation names (without frame details)',
        'method' => 'GET',
        'url_structure' => '/management/listKeyframes',
        'parameters' => [],
        'example_get' => 'GET /management/listKeyframes',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Keyframe names retrieved successfully',
            'data' => [
                'animations' => ['fadeIn', 'slideInFromLeft', 'bounce', 'pulse'],
                'count' => 4
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found'
        ],
        'notes' => 'Lightweight alternative to getKeyframes when you only need animation names. Use getKeyframes for full frame content.'
    ],
    
    'getAnimatedSelectors' => [
        'description' => 'Returns all CSS selectors using animations, grouped by animation name with orphan detection',
        'method' => 'GET',
        'url_structure' => '/management/getAnimatedSelectors',
        'parameters' => [],
        'example_get' => 'GET /management/getAnimatedSelectors',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Animated selectors retrieved successfully',
            'data' => [
                'animations' => [
                    'fadeIn' => [
                        'exists' => true,
                        'selectors' => ['.hero-title', '.card-content']
                    ],
                    'unknownAnim' => [
                        'exists' => false,
                        'selectors' => ['.orphan-element']
                    ]
                ],
                'orphanAnimations' => ['unknownAnim'],
                'totalSelectors' => 3
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found'
        ],
        'notes' => 'Useful for finding which elements use animations and detecting orphan animations (referenced but not defined). Checks both animation and animation-name properties.'
    ],
    
    'getKeyframes' => [
        'description' => 'Retrieves all @keyframes animations defined in the stylesheet, or a specific one by name',
        'method' => 'GET',
        'url_structure' => '/management/getKeyframes/{name?}',
        'parameters' => [
            '{name}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Animation name to retrieve (URL segment, optional)',
                'example' => 'fadeIn'
            ]
        ],
        'example_get' => 'GET /management/getKeyframes (all) or GET /management/getKeyframes/fadeIn (specific)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Keyframes retrieved successfully',
            'data' => [
                'keyframes' => [
                    'fadeIn' => [
                        'frames' => ['from', 'to'],
                        'content' => '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }'
                    ]
                ],
                'count' => 1
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found',
            '500.server.file_read_failed' => 'Failed to read style file'
        ],
        'notes' => 'Returns all @keyframes animations with their frame definitions. Use setKeyframes to add/update animations.'
    ],
    
    'setKeyframes' => [
        'description' => 'Add or update a @keyframes animation',
        'method' => 'PATCH',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Animation name (alphanumeric, hyphens, underscores)',
                'example' => 'fadeIn or slideInFromLeft'
            ],
            'frames' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Object with frame keys (0%, 50%, 100%, from, to) and CSS values',
                'example' => '{"from": "opacity: 0;", "to": "opacity: 1;"} or {"0%, 100%": "transform: scale(1);", "50%": "transform: scale(1.1);"}'
            ],
            'allowOverwrite' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If false, returns error when animation exists. If true (default), overwrites existing animation.',
                'example' => 'false'
            ]
        ],
        'example_patch' => 'PATCH /management/setKeyframes with body: {"name": "bounce", "frames": {"0%, 100%": "transform: translateY(0);", "50%": "transform: translateY(-20px);"}}',
        'example_no_overwrite' => 'PATCH /management/setKeyframes with body: {"name": "newAnim", "frames": {...}, "allowOverwrite": false}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Keyframe animation added/updated successfully',
            'data' => [
                'action' => 'added|updated',
                'name' => 'bounce',
                'frames' => ['0%, 100%', '50%']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing name or frames parameter',
            '400.validation.invalid_format' => 'Invalid name format (must start with letter, alphanumeric only)',
            '400.validation.invalid_frame' => 'Invalid frame key (must be percentage or from/to)',
            '400.validation.security' => 'Dangerous CSS pattern detected',
            '409.keyframes.exists' => 'Animation already exists (when allowOverwrite is false)',
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to write style file'
        ],
        'notes' => 'Frame keys: percentages (0%, 50%, 100%), combined (0%, 100%), or keywords (from, to). Use allowOverwrite:false to prevent accidental overwrites. Security validated against CSS injection.'
    ],
    
    'deleteKeyframes' => [
        'description' => 'Remove a @keyframes animation from the stylesheet',
        'method' => 'DELETE',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the animation to delete',
                'example' => 'fadeIn'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteKeyframes with body: {"name": "fadeIn"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Keyframe animation deleted successfully',
            'data' => [
                'name' => 'fadeIn'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing name parameter',
            '400.validation.invalid_format' => 'Animation name cannot be empty',
            '404.file.not_found' => 'Style file not found',
            '404.keyframes.not_found' => 'Animation not found',
            '500.server.file_write_failed' => 'Failed to write style file'
        ],
        'notes' => 'Permanently removes the @keyframes animation. Use getKeyframes first to confirm animation exists. Cannot be undone.'
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
                'total' => 19
            ]
        ]
    ],
    
    'generateToken' => [
        'description' => 'Creates a new API authentication token with specified role. Requires superadmin (*) permission.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Descriptive name for the token (1-100 characters)',
                'example' => 'Collaborator Token'
            ],
            'role' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Role to assign to the token',
                'valid_values' => [
                    '*' => 'Superadmin - full access to all 97 commands including token/role management',
                    'viewer' => 'Read-only access (27 commands)',
                    'editor' => 'Content editing - structure, translations, assets (55 commands)',
                    'designer' => 'Style editing - CSS, animations, visual elements (63 commands)',
                    'developer' => 'Build and deploy access (70 commands)',
                    'admin' => 'Full access except token/role management (91 commands)',
                    '<custom>' => 'Any custom role name created via createRole'
                ],
                'example' => 'editor or designer or admin'
            ],
            'note' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional note about the token purpose',
                'example' => 'For the design team'
            ]
        ],
        'example_post' => 'POST /management/generateToken with body: {"name": "Design Team", "role": "designer", "note": "For external designers"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Token generated successfully',
            'data' => [
                'token' => 'tvt_a1b2c3d4e5f6... (52 characters)',
                'name' => 'Design Team',
                'role' => 'designer',
                'command_count' => 54,
                'created' => '2026-01-03 10:30:00',
                'warning' => 'Save this token securely - it cannot be retrieved later!'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name and role parameters are required',
            '400.validation.invalid_type' => 'name must be string',
            '400.validation.invalid_length' => 'name must be between 1 and 100 characters',
            '400.validation.invalid_role' => 'Role does not exist',
            '403.auth.forbidden' => 'Insufficient permissions (requires * superadmin)',
            '500.server.file_write_failed' => 'Failed to save new token'
        ],
        'notes' => 'Token format: tvt_ prefix + 48 hex characters. Store the token immediately as it cannot be retrieved again. Use listTokens to see masked previews of existing tokens. Use listRoles to see available roles.'
    ],
    
    'listTokens' => [
        'description' => 'Lists all API tokens with masked values. Requires admin permission.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listTokens',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Tokens retrieved successfully',
            'data' => [
                'total_tokens' => 2,
                'tokens' => [
                    [
                        'token_preview' => 'tvt_dev_...tion',
                        'name' => 'Default Development Token',
                        'permissions' => ['*'],
                        'created' => '2025-12-11'
                    ]
                ],
                'auth_enabled' => true,
                'development_mode' => true
            ]
        ],
        'error_responses' => [
            '403.auth.forbidden' => 'Insufficient permissions (requires admin)'
        ],
        'notes' => 'Token values are masked (first 8 + last 4 characters visible). Use the token_preview value with revokeToken to delete tokens. Full token values are never exposed after creation.'
    ],
    
    'revokeToken' => [
        'description' => 'Revokes (permanently deletes) an API token. Requires admin permission.',
        'method' => 'DELETE',
        'parameters' => [
            'token_preview' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The token preview from listTokens (e.g., "tvt_dev_...tion") or the full token value',
                'example' => 'tvt_abc1...xyz9'
            ]
        ],
        'example_delete' => 'DELETE /management/revokeToken with body: {"token_preview": "tvt_abc1...xyz9"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Token revoked successfully',
            'data' => [
                'revoked_token' => 'tvt_abc1...xyz9',
                'name' => 'Old Token Name',
                'remaining_tokens' => 1
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'token_preview parameter is required',
            '400.operation.denied' => 'Cannot revoke the last remaining token or currently used token',
            '403.auth.forbidden' => 'Insufficient permissions (requires admin)',
            '404.resource.not_found' => 'Token not found',
            '500.server.file_write_failed' => 'Failed to save config after revoking'
        ],
        'notes' => 'Cannot revoke: 1) the last remaining token (create a new one first), 2) the token currently being used for this request. Use a different admin token to revoke another.'
    ],
    
    'listComponents' => [
        'description' => 'Lists all reusable JSON components with metadata. Shows available slots (placeholders), typed variables, and component dependencies.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listComponents',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Components listed successfully',
            'data' => [
                'components' => [
                    [
                        'name' => 'footer-link',
                        'file' => 'footer-link.json',
                        'valid' => true,
                        'slots' => ['href', 'label', 'target'],
                        'variables' => [
                            ['name' => 'label', 'type' => 'textKey'],
                            ['name' => 'href', 'type' => 'param']
                        ],
                        'uses_components' => [],
                        'size' => 256,
                        'modified' => '2025-01-15 10:30:00'
                    ]
                ],
                'count' => 3,
                'directory' => 'secure/templates/model/json/components/'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Slots are {{placeholder}} names (backwards compatibility). Variables array includes type detection: textKey for translatable content, param for URL/attribute values. System placeholders (__ prefix) are filtered out. Use editStructure with type="component" to create/update/delete components.'
    ],
    
    'listPages' => [
        'description' => 'Lists all JSON page structures with metadata. Shows route status, components used, and translation keys.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listPages',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Pages listed successfully',
            'data' => [
                'pages' => [
                    [
                        'name' => 'home',
                        'file' => 'home.json',
                        'valid' => true,
                        'has_route' => true,
                        'route_url' => '/home',
                        'components_used' => ['menu-link', 'footer-link'],
                        'translation_keys' => ['page.home.title'],
                        'node_count' => 15,
                        'size' => 1024,
                        'modified' => '2025-01-15 10:30:00'
                    ]
                ],
                'count' => 4,
                'with_routes' => 3,
                'orphaned' => 1,
                'directory' => 'secure/templates/model/json/pages/'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Pages without routes (orphaned) are JSON files that exist but have no route defined. Use addRoute to make them accessible. Use editStructure with type="page" to create/update/delete pages.'
    ],
    
    // =========================================================================
    // NODE MANAGEMENT (Visual Editor)
    // =========================================================================
    
    'moveNode' => [
        'description' => 'Moves a node from one position to another within a structure. Handles same-level reordering and cross-level moves with automatic index adjustment.',
        'method' => 'PATCH',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'sourceNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID to move (dot-notation path)',
                'example' => '0.2.1'
            ],
            'targetNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target position node ID',
                'example' => '0.3'
            ],
            'position' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Insert before or after target (default: after)',
                'example' => 'before'
            ]
        ],
        'example_patch' => 'PATCH /management/moveNode with body: {"type": "page", "name": "home", "sourceNodeId": "0.2.1", "targetNodeId": "0.3", "position": "after"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node moved successfully',
            'data' => [
                'movedNode' => '0.2.1',
                'newNodeId' => '0.4',
                'targetNode' => '0.3',
                'position' => 'after',
                'type' => 'page',
                'name' => 'home'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid type or position value',
            '400.operation.denied' => 'Cannot move to same position or inside self',
            '404.resource.not_found' => 'Source or target node not found'
        ],
        'notes' => 'Atomic move operation with proper index adjustment. Use in Visual Editor drag & drop. Components are moved as single units.'
    ],
    
    'deleteNode' => [
        'description' => 'Deletes a node and all its children from a structure.',
        'method' => 'DELETE',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID to delete (dot-notation path)',
                'example' => '0.2.1'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteNode with body: {"type": "page", "name": "home", "nodeId": "0.2.1"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node deleted successfully',
            'data' => [
                'deletedNode' => '0.2.1',
                'type' => 'page',
                'name' => 'home'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid type value',
            '404.resource.not_found' => 'Node not found'
        ],
        'notes' => 'Recursively deletes all children. Does not delete associated translation keys. Use in Visual Editor with Del key.'
    ],
    
    'addNode' => [
        'description' => 'Adds a new HTML tag node to a structure with automatic textKey generation and mandatory parameter validation.',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'targetNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Reference node ID for positioning',
                'example' => '0.2'
            ],
            'position' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Where to insert: before, after, or inside',
                'example' => 'after'
            ],
            'tag' => [
                'required' => true,
                'type' => 'string',
                'description' => 'HTML tag name',
                'example' => 'div'
            ],
            'params' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Tag attributes including mandatory ones (href for <a>, src/alt for <img>, etc.)',
                'example' => '{"class": "card", "href": "/contact"}'
            ],
            'textKey' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Translation key (auto-generated if not provided for inline/block tags)',
                'example' => 'home.greeting'
            ]
        ],
        'example_post' => 'POST /management/addNode with body: {"type": "page", "name": "home", "targetNodeId": "0.2", "position": "after", "tag": "a", "params": {"href": "/contact", "class": "btn"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node added successfully',
            'data' => [
                'nodeId' => '0.3',
                'tag' => 'a',
                'textKeyGenerated' => true,
                'textKey' => 'home.item1',
                'translationCreated' => true
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid tag or position',
            '400.validation.missing_params' => 'Missing mandatory params (e.g., href for <a>)',
            '400.operation.denied' => 'Cannot insert inside component node',
            '404.resource.not_found' => 'Target node not found'
        ],
        'notes' => 'Auto-generates textKey as {struct}.item{N}. Creates empty translation in default.json. Position "inside" moves existing text children into new node. For components, use addComponentToNode.'
    ],
    
    'editNode' => [
        'description' => 'Edits an existing tag node: change tag type, add/update/remove params, change textKey reference.',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID to edit',
                'example' => '0.2.1'
            ],
            'tag' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New tag type (validates mandatory params)',
                'example' => 'a'
            ],
            'addParams' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Params to add or update',
                'example' => '{"href": "/new-link", "class": "btn"}'
            ],
            'removeParams' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Param names to remove (cannot remove mandatory params)',
                'example' => '["data-old", "aria-hidden"]'
            ],
            'textKey' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Change textKey reference (edge case)',
                'example' => 'home.newKey'
            ]
        ],
        'example_post' => 'POST /management/editNode with body: {"type": "page", "name": "home", "nodeId": "0.2", "addParams": {"class": "highlight"}, "removeParams": ["data-temp"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node updated successfully',
            'data' => [
                'nodeId' => '0.2',
                'changes' => [
                    'paramsAdded' => ['class'],
                    'paramsRemoved' => ['data-temp']
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid tag type',
            '400.validation.mandatory_params' => 'Cannot remove mandatory params',
            '400.operation.denied' => 'Cannot edit component node (use editComponentToNode)',
            '404.resource.not_found' => 'Node not found'
        ],
        'notes' => 'Does NOT edit translation values (use setTranslationKeys). Cannot edit component nodes or pure text nodes. After tag change, validates mandatory params are present.'
    ],
    
    'addComponentToNode' => [
        'description' => 'Adds a component instance to a structure with auto-generated textKeys for text-type variables.',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'targetNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Reference node ID for positioning',
                'example' => '0.2'
            ],
            'position' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Where to insert: before, after, or inside',
                'example' => 'after'
            ],
            'component' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Component name from listComponents',
                'example' => 'menu-card'
            ],
            'data' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Variable bindings (param-type only, textKey auto-generated)',
                'example' => '{"href": "/contact"}'
            ]
        ],
        'example_post' => 'POST /management/addComponentToNode with body: {"type": "page", "name": "home", "targetNodeId": "0.2", "position": "after", "component": "menu-card", "data": {"href": "/about"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Component added successfully',
            'data' => [
                'nodeId' => '0.3',
                'component' => 'menu-card',
                'instanceNumber' => 2,
                'generatedTextKeys' => [
                    'title' => 'home.menuCard2.title',
                    'desc' => 'home.menuCard2.desc'
                ],
                'translationsCreated' => ['home.menuCard2.title', 'home.menuCard2.desc'],
                'html' => '<div class=\"menu-card\">...</div>'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Component not found',
            '400.operation.denied' => 'Cannot insert inside component node',
            '404.resource.not_found' => 'Target node not found'
        ],
        'notes' => 'Auto-generates textKeys as {struct}.{component}{N}.{var}. Creates empty translations. Returns rendered HTML for live DOM insertion. System placeholders (__ prefix) are filtered out.'
    ],
    
    'editComponentToNode' => [
        'description' => 'Edits param-type variables in an existing component node. TextKey variables are read-only.',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'name' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for type=page/component)',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Component node ID to edit',
                'example' => '0.2'
            ],
            'data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Updated variable bindings (param-type only)',
                'example' => '{"href": "/new-target", "style": "primary"}'
            ]
        ],
        'example_post' => 'POST /management/editComponentToNode with body: {"type": "page", "name": "home", "nodeId": "0.2", "data": {"href": "/contact"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Component updated successfully',
            'data' => [
                'nodeId' => '0.2',
                'component' => 'menu-card',
                'changes' => [
                    ['name' => 'href', 'type' => 'param', 'oldValue' => '/about', 'newValue' => '/contact']
                ],
                'html' => '<div class=\"menu-card\">...</div>'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_variable' => 'Variable does not exist in component',
            '400.operation.denied' => 'Cannot edit textKey-type variables (use setTranslationKeys)',
            '404.resource.not_found' => 'Node not found or not a component'
        ],
        'notes' => 'Only param-type variables can be edited (href, src, etc.). TextKey variables are read-only - use setTranslationKeys to change translation values. Returns rendered HTML for live DOM update.'
    ],
    
    'createAlias' => [
        'description' => 'Creates a URL redirect alias that points to an existing route. Supports 301 redirects or internal (transparent) routing.',
        'method' => 'POST',
        'parameters' => [
            'alias' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The alias URL path (with or without leading /)',
                'example' => '/old-page or legacy/path'
            ],
            'target' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The target route to redirect to',
                'example' => '/home or /docs/getting-started'
            ],
            'type' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Alias type: "redirect" (301 HTTP redirect) or "internal" (transparent). Default: redirect',
                'example' => 'redirect',
                'validation' => 'Must be "redirect" or "internal"'
            ]
        ],
        'example_post' => 'POST /management/createAlias with body: {"alias": "/old-home", "target": "/home", "type": "redirect"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Alias '/old-home' created successfully",
            'data' => [
                'alias' => '/old-home',
                'target' => '/home',
                'type' => 'redirect',
                'redirect_code' => 301
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing alias or target parameter',
            '400.validation.invalid_parameter' => 'Invalid alias format or target does not exist',
            '409.conflict' => 'Alias conflicts with existing route or reserved path'
        ],
        'notes' => 'Aliases cannot conflict with existing routes or reserved paths (management, assets, build). Delete an alias first to modify its target. Stored in secure/config/aliases.json.'
    ],
    
    'deleteAlias' => [
        'description' => 'Deletes an existing URL redirect alias.',
        'method' => 'DELETE',
        'parameters' => [
            'alias' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The alias URL path to delete',
                'example' => '/old-page'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteAlias with body: {"alias": "/old-home"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Alias '/old-home' deleted successfully",
            'data' => [
                'deleted' => [
                    'alias' => '/old-home',
                    'target' => '/home',
                    'type' => 'redirect'
                ],
                'remaining_count' => 5
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing alias parameter',
            '404.not_found' => 'Alias not found'
        ],
        'notes' => 'Returns list of available aliases if the requested alias is not found.'
    ],
    
    'listAliases' => [
        'description' => 'Lists all URL redirect aliases with their targets and types.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listAliases',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Aliases listed successfully',
            'data' => [
                'aliases' => [
                    [
                        'alias' => '/old-home',
                        'target' => '/home',
                        'type' => 'redirect',
                        'redirect_code' => 301,
                        'created' => '2025-01-15 10:30:00'
                    ]
                ],
                'count' => 2,
                'by_type' => [
                    'redirect' => 2,
                    'internal' => 0
                ]
            ]
        ],
        'error_responses' => [],
        'notes' => 'Returns empty array if no aliases defined. Use createAlias to add new aliases.'
    ],
    
    // ==========================================
    // PROJECT MANAGEMENT COMMANDS
    // ==========================================
    
    'listProjects' => [
        'description' => 'Lists all available projects with metadata (name, site name, routes, languages, size)',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listProjects',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Projects listed successfully',
            'data' => [
                'projects' => [
                    [
                        'name' => 'quicksite',
                        'path' => 'secure/projects/quicksite',
                        'site_name' => 'QuickSite Demo',
                        'routes_count' => 5,
                        'pages_count' => 5,
                        'languages' => ['en', 'fr'],
                        'size' => '2.5 MB',
                        'size_bytes' => 2621440,
                        'is_active' => true
                    ]
                ],
                'count' => 1,
                'active_project' => 'quicksite'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Use to get overview of all managed projects. The is_active flag shows which project is currently being served.'
    ],
    
    'getActiveProject' => [
        'description' => 'Returns information about the currently active project',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getActiveProject',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Active project: quicksite',
            'data' => [
                'project' => 'quicksite',
                'path' => 'secure/projects/quicksite',
                'exists' => true,
                'site_name' => 'QuickSite Demo'
            ]
        ],
        'error_responses' => [
            '500.config.target_missing' => 'target.php configuration not found'
        ],
        'notes' => 'Shows which project is currently being served by the website.'
    ],
    
    'switchProject' => [
        'description' => 'Switches the active project. Syncs live edits back to previous project before switching, then copies new project files to live folder.',
        'method' => 'PATCH',
        'parameters' => [
            'project' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Project name to switch to',
                'example' => 'quicksite'
            ],
            'copy_public' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Copy project public files (assets, styles, build) to live public folder',
                'default' => true
            ]
        ],
        'example_patch' => 'PATCH /management/switchProject with body: {"project": "mysite", "copy_public": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Switched to project 'mysite'",
            'data' => [
                'project' => 'mysite',
                'previous_project' => 'quicksite',
                'previous_project_synced' => true,
                'target_updated' => true,
                'public_files_copied' => true,
                'custom_js_regenerated' => true,
                'custom_functions_count' => 0
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing project parameter',
            '400.validation.incomplete_project' => 'Project missing required files',
            '200.operation.no_change' => 'Already on this project (returns success with was_already_active=true)',
            '404.resource.not_found' => 'Project not found'
        ],
        'notes' => 'IMPORTANT: Before switching, live CSS/assets are synced BACK to the previous project to prevent data loss. The website will immediately start serving the new project. Custom JS functions are regenerated for the new project.'
    ],
    
    'createProject' => [
        'description' => 'Creates a new empty project with basic structure and templates',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Project name (alphanumeric, starts with letter)',
                'example' => 'my_new_site',
                'validation' => 'Max 50 chars, alphanumeric/dash/underscore only, must start with letter'
            ],
            'site_name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Display name for the site',
                'default' => 'Capitalized project name'
            ],
            'language' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Default language code',
                'default' => 'en',
                'validation' => 'ISO format: en, fr, de, en-US, etc.'
            ],
            'switch_to' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Switch to this project after creation',
                'default' => false
            ]
        ],
        'example_post' => 'POST /management/createProject with body: {"name": "mysite", "site_name": "My Website", "language": "en", "switch_to": true}',
        'success_response' => [
            'status' => 201,
            'code' => 'resource.created',
            'message' => "Project 'mysite' created successfully",
            'data' => [
                'project' => 'mysite',
                'path' => 'secure/projects/mysite',
                'site_name' => 'My Website',
                'default_language' => 'en',
                'created' => true,
                'switched_to' => true
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing name parameter',
            '400.validation.invalid_format' => 'Invalid project name format',
            '400.validation.reserved_name' => 'Project name is reserved for system use',
            '409.resource.already_exists' => 'Project already exists'
        ],
        'notes' => 'Creates complete project structure: config.php, routes.php, templates/, translate/, etc. with basic home page template.'
    ],
    
    'deleteProject' => [
        'description' => 'Permanently deletes a project and all its files',
        'method' => 'DELETE',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Project name to delete'
            ],
            'confirm' => [
                'required' => true,
                'type' => 'boolean',
                'description' => 'Safety confirmation (must be true)',
                'validation' => 'Must be true to proceed'
            ],
            'force' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Force delete even if project is active',
                'default' => false
            ]
        ],
        'example_delete' => 'DELETE /management/deleteProject with body: {"name": "oldsite", "confirm": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'resource.deleted',
            'message' => "Project 'oldsite' deleted successfully",
            'data' => [
                'project' => 'oldsite',
                'deleted' => true,
                'files_deleted' => 45,
                'directories_deleted' => 12,
                'size_freed' => '1.2 MB'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing name parameter',
            '400.validation.confirmation_required' => 'Must set confirm=true',
            '400.validation.active_project' => 'Cannot delete active project (use force=true)',
            '404.resource.not_found' => 'Project not found'
        ],
        'notes' => 'WARNING: This is permanent and cannot be undone. Use exportProject first to backup. If deleting active project, system will auto-switch to another available project.'
    ],
    
    'exportProject' => [
        'description' => 'Exports a project as a downloadable ZIP file',
        'method' => 'GET',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Project name to export (defaults to active project if not specified)'
            ],
            'include_public' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Include public folder in export',
                'default' => true
            ],
            'download' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Stream file directly as download',
                'default' => false
            ]
        ],
        'example_get' => 'GET /management/exportProject (exports active project) or GET /management/exportProject?name=quicksite&download=true',
        'success_response' => [
            'status' => 200,
            'code' => 'resource.exported',
            'message' => "Project 'quicksite' exported successfully",
            'data' => [
                'project' => 'quicksite',
                'filename' => 'quicksite_export_20250120_143022.zip',
                'size' => '2.1 MB',
                'download_url' => '/management/downloadExport?file=quicksite_export_20250120_143022.zip',
                'expires' => '2025-01-21 14:30:22'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing name parameter',
            '404.resource.not_found' => 'Project not found',
            '500.server.missing_extension' => 'PHP ZIP extension not available'
        ],
        'notes' => 'Exports are stored in secure/exports/ and auto-cleaned (keeps last 5). Use download=true to stream directly.'
    ],
    
    'importProject' => [
        'description' => 'Imports a project from an uploaded ZIP file',
        'method' => 'POST',
        'content_type' => 'multipart/form-data',
        'parameters' => [
            'file' => [
                'required' => true,
                'type' => 'file',
                'description' => 'ZIP file containing project',
                'validation' => 'Must be valid ZIP with config.php or routes.php'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Override project name',
                'default' => 'Uses folder name from ZIP'
            ],
            'overwrite' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Overwrite if project exists',
                'default' => false
            ],
            'switch_to' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Switch to imported project',
                'default' => false
            ]
        ],
        'example_post' => 'POST /management/importProject (multipart/form-data with file)',
        'success_response' => [
            'status' => 201,
            'code' => 'resource.imported',
            'message' => "Project 'mysite' imported successfully",
            'data' => [
                'project' => 'mysite',
                'imported' => true,
                'files_count' => 45,
                'site_name' => 'My Website',
                'routes_count' => 5,
                'switched_to' => true
            ]
        ],
        'error_responses' => [
            '400.upload.failed' => 'File upload failed',
            '400.validation.invalid_zip' => 'Invalid or corrupted ZIP',
            '400.validation.invalid_structure' => 'ZIP missing required project files',
            '409.resource.already_exists' => 'Project exists (use overwrite=true)'
        ],
        'notes' => 'Compatible with exports from exportProject. ZIP must contain project folder with config.php or routes.php.'
    ],
    
    'downloadExport' => [
        'description' => 'Downloads a previously exported project ZIP file',
        'method' => 'GET',
        'parameters' => [
            'file' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Export filename to download',
                'example' => 'quicksite_export_20250120_143022.zip'
            ]
        ],
        'example_get' => 'GET /management/downloadExport?file=quicksite_export_20250120_143022.zip',
        'success_response' => [
            'description' => 'Streams ZIP file with appropriate headers'
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing file parameter',
            '400.validation.invalid_filename' => 'Invalid filename (path traversal blocked)',
            '404.resource.not_found' => 'Export file not found or expired'
        ],
        'notes' => 'Export files expire after 24 hours. Use exportProject with download=true for immediate download.'
    ],
    
    // =========================================================================
    // BACKUP COMMANDS
    // =========================================================================
    
    'backupProject' => [
        'description' => 'Creates a timestamped backup of a project (internal backup, not for sharing)',
        'method' => 'GET',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Project name to backup (defaults to active project)',
                'example' => 'quicksite'
            ],
            'max_backups' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Maximum backups to keep (default: 5, 0 = unlimited)',
                'example' => 5
            ]
        ],
        'example_get' => 'GET /management/backupProject',
        'example_get_with_params' => 'GET /management/backupProject?name=quicksite&max_backups=3',
        'success_response' => [
            'status' => 200,
            'message' => 'Backup created successfully: 2026-01-03_14-30-00',
            'data' => [
                'project' => 'quicksite',
                'backup' => [
                    'name' => '2026-01-03_14-30-00',
                    'path' => '/secure/projects/quicksite/backups/2026-01-03_14-30-00',
                    'size' => 1234567,
                    'size_formatted' => '1.18 MB',
                    'files' => 42,
                    'items' => ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'],
                    'created' => '2026-01-03_14-30-00'
                ],
                'total_backups' => 3,
                'max_backups' => 5,
                'deleted_old_backups' => []
            ]
        ],
        'notes' => 'Backups are stored in project/backups/ folder. Old backups are auto-deleted when max_backups is exceeded. For sharing projects externally, use exportProject instead (JSON-only, secure).'
    ],
    
    'listBackups' => [
        'description' => 'Lists all available backups for a project',
        'method' => 'GET',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Project name (defaults to active project)',
                'example' => 'quicksite'
            ]
        ],
        'example_get' => 'GET /management/listBackups',
        'success_response' => [
            'status' => 200,
            'message' => 'Found 3 backup(s)',
            'data' => [
                'project' => 'quicksite',
                'backups' => [
                    [
                        'name' => '2026-01-03_14-30-00',
                        'type' => 'manual',
                        'size' => 1234567,
                        'size_formatted' => '1.18 MB',
                        'files' => 42,
                        'contents' => ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'],
                        'created' => 1704291000,
                        'created_formatted' => '2026-01-03 14:30:00',
                        'created_relative' => '2 hours ago'
                    ]
                ],
                'count' => 3,
                'total_size' => 3703701,
                'total_size_formatted' => '3.53 MB'
            ]
        ],
        'notes' => 'Backup types: "manual" (created via backupProject), "pre-restore" (auto-created before restore), "auto" (scheduled backups).'
    ],
    
    'restoreBackup' => [
        'description' => 'Restores a project from a backup (auto-creates pre-restore backup first)',
        'method' => 'POST',
        'parameters' => [
            'backup' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Backup name (timestamp folder name)',
                'example' => '2026-01-03_14-30-00'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Project name (defaults to active project)',
                'example' => 'quicksite'
            ],
            'skip_pre_backup' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Skip creating pre-restore safety backup (default: false)',
                'example' => false
            ]
        ],
        'example_post' => 'POST /management/restoreBackup\n{"backup": "2026-01-03_14-30-00"}',
        'success_response' => [
            'status' => 200,
            'message' => 'Backup restored successfully',
            'data' => [
                'project' => 'quicksite',
                'backup_restored' => '2026-01-03_14-30-00',
                'restored_items' => ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'],
                'pre_restore_backup' => 'pre-restore_2026-01-03_16-45-22',
                'public_synced_to_live' => true
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Backup name required',
            '404.resource.not_found' => 'Backup not found'
        ],
        'notes' => 'A pre-restore backup is automatically created before restoring, allowing rollback if something goes wrong. If restoring the active project, public/ is synced to live folder.'
    ],
    
    'deleteBackup' => [
        'description' => 'Deletes a specific backup',
        'method' => 'DELETE',
        'parameters' => [
            'backup' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Backup name to delete',
                'example' => '2026-01-03_14-30-00'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Project name (defaults to active project)',
                'example' => 'quicksite'
            ]
        ],
        'example_delete' => 'DELETE /management/deleteBackup?backup=2026-01-03_14-30-00',
        'success_response' => [
            'status' => 200,
            'message' => 'Backup deleted successfully',
            'data' => [
                'project' => 'quicksite',
                'deleted_backup' => '2026-01-03_14-30-00',
                'freed_space' => 1234567,
                'freed_space_formatted' => '1.18 MB',
                'remaining_backups' => 2
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Backup name required',
            '400.validation.invalid_filename' => 'Invalid backup name (path traversal blocked)',
            '404.resource.not_found' => 'Backup not found'
        ]
    ],
    
    'getSizeInfo' => [
        'description' => 'Returns detailed storage size information for all folders in the project structure. Useful for monitoring disk usage and identifying what takes up space.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getSizeInfo',
        'success_response' => [
            'status' => 200,
            'code' => 'size_info.success',
            'message' => 'Size information retrieved successfully',
            'data' => [
                'summary' => [
                    'total' => ['size' => 32100000, 'size_formatted' => '30.62 MB'],
                    'by_category' => [
                        'projects' => ['size' => 20200000, 'size_formatted' => '19.28 MB', 'description' => 'Project files (assets, styles, builds, project data)'],
                        'backups' => ['size' => 10100000, 'size_formatted' => '9.66 MB', 'description' => 'All project backups'],
                        'admin' => ['size' => 3000000, 'size_formatted' => '2.88 MB', 'description' => 'Admin panel interface'],
                        'management' => ['size' => 500000, 'size_formatted' => '500 KB', 'description' => 'API and command system'],
                        'core' => ['size' => 300000, 'size_formatted' => '300 KB', 'description' => 'Core system files (src, config, logs)']
                    ],
                    'active_project' => [
                        'name' => 'quicksite',
                        'size' => 20200000,
                        'size_formatted' => '19.28 MB',
                        'backups_count' => 3
                    ]
                ],
                'public' => ['total' => '...', 'folders' => '...'],
                'secure' => ['total' => '...', 'folders' => '...', 'projects_detail' => '...']
            ]
        ],
        'notes' => 'Categories combine related folders: projects (assets+style+build+secure/projects), backups (all project backups), admin (public/admin+secure/admin), management (API system), core (src+config+logs). Used by dashboard storage overview widget.'
    ],
    
    'clearExports' => [
        'description' => 'Clears all exported project ZIP files from the exports folder. Useful to free up disk space after exports have been downloaded.',
        'method' => 'DELETE',
        'parameters' => [],
        'example_delete' => 'DELETE /management/clearExports',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Exports cleared successfully',
            'data' => [
                'deleted_count' => 5,
                'freed_space' => '15.5 MB'
            ]
        ],
        'error_responses' => [
            '500.server.delete_failed' => 'Failed to delete some export files'
        ],
        'notes' => 'Deletes all .zip files in secure/exports/ folder. Does not affect project data or backups.'
    ],

    // ==========================================
    // ROLE MANAGEMENT COMMANDS
    // ==========================================
    
    'listRoles' => [
        'description' => 'Lists all available roles in the system. Superadmin (*) users see full command lists; other users see names and descriptions only.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listRoles',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Roles retrieved successfully',
            'data' => [
                'roles' => [
                    'viewer' => [
                        'description' => 'Read-only access for viewing content and structure',
                        'builtin' => true,
                        'command_count' => 26,
                        'commands' => ['getRoutes', 'getStructure', '...'] // Only for * users
                    ],
                    'editor' => [
                        'description' => 'Content editing including structure, translations, and assets',
                        'builtin' => true,
                        'command_count' => 45
                    ],
                    // ... other roles
                ],
                'total_roles' => 5
            ]
        ],
        'notes' => 'For non-superadmin users, the commands array is omitted from each role. This prevents information disclosure about system capabilities. Custom roles created via createRole also appear here.'
    ],
    
    'getMyPermissions' => [
        'description' => 'Returns the current token\'s role and the full list of commands accessible with that role.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getMyPermissions',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Permissions retrieved successfully',
            'data' => [
                'role' => 'editor',
                'role_description' => 'Content editing including structure, translations, and assets',
                'commands' => [
                    'getRoutes', 'getStructure', 'listProjects', 'listBackups',
                    'editStructure', 'addRoute', 'deleteRoute', '...'
                ],
                'command_count' => 45,
                'is_superadmin' => false
            ]
        ],
        'notes' => 'Use this to determine what commands the current token can execute. Useful for building permission-aware UIs. Superadmin (*) users have is_superadmin=true and access to all 93 commands.'
    ],
    
    'createRole' => [
        'description' => 'Creates a new custom role with specified commands. Requires superadmin (*) permission.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Role name (lowercase alphanumeric with underscores, 2-50 characters)',
                'example' => 'content_manager'
            ],
            'description' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Human-readable description of the role',
                'example' => 'Manages blog content and translations'
            ],
            'commands' => [
                'required' => true,
                'type' => 'array',
                'description' => 'Array of command names this role can execute',
                'example' => "['getRoutes', 'listProjects', 'editStructure', 'addRoute']"
            ]
        ],
        'example_post' => 'POST /management/createRole with body: {"name": "content_manager", "description": "Manages blog content", "commands": ["getRoutes", "editStructure", "addRoute"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Role created successfully',
            'data' => [
                'name' => 'content_manager',
                'description' => 'Manages blog content',
                'builtin' => false,
                'command_count' => 3,
                'commands' => ['getRoutes', 'editStructure', 'addRoute']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name, description, and commands are required',
            '400.validation.invalid_format' => 'Role name must be lowercase alphanumeric with underscores',
            '400.validation.invalid_command' => 'One or more commands do not exist',
            '400.validation.role_exists' => 'A role with this name already exists',
            '403.auth.forbidden' => 'Requires superadmin (*) permission'
        ],
        'notes' => 'Custom roles are stored in secure/config/roles.php. Commands must be valid existing commands. Use listRoles to see all available roles. Restricted commands (generateToken, revokeToken, listTokens, createRole, editRole, deleteRole) can only be assigned to custom roles by superadmin users with * permission.'
    ],
    
    'editRole' => [
        'description' => 'Edits an existing role. Builtin roles can only have description changed. Requires superadmin (*) permission.',
        'method' => 'PUT',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the role to edit',
                'example' => 'content_manager'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New description for the role',
                'example' => 'Updated description for content management'
            ],
            'commands' => [
                'required' => false,
                'type' => 'array',
                'description' => 'New array of commands (only for custom roles)',
                'example' => "['getRoutes', 'editStructure', 'addRoute', 'deleteRoute']"
            ]
        ],
        'example_put' => 'PUT /management/editRole with body: {"name": "content_manager", "description": "Updated description", "commands": ["getRoutes", "editStructure"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Role updated successfully',
            'data' => [
                'name' => 'content_manager',
                'description' => 'Updated description',
                'builtin' => false,
                'command_count' => 2,
                'commands' => ['getRoutes', 'editStructure'],
                'changes' => ['description', 'commands']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name parameter is required',
            '400.validation.no_changes' => 'No changes provided',
            '400.validation.invalid_command' => 'One or more commands do not exist',
            '400.validation.builtin_commands' => 'Cannot modify commands for builtin roles',
            '404.role.not_found' => 'Role does not exist',
            '403.auth.forbidden' => 'Requires superadmin (*) permission'
        ],
        'notes' => 'Builtin roles (viewer, editor, designer, developer, admin) can only have their description changed. Use createRole to add a custom role if you need different commands.'
    ],
    
    'deleteRole' => [
        'description' => 'Deletes a custom role. Builtin roles cannot be deleted. Requires superadmin (*) permission.',
        'method' => 'DELETE',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the role to delete',
                'example' => 'content_manager'
            ],
            'force' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, reassigns tokens using this role to viewer role',
                'default' => false,
                'example' => true
            ]
        ],
        'example_delete' => 'DELETE /management/deleteRole with body: {"name": "content_manager", "force": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Role deleted successfully',
            'data' => [
                'name' => 'content_manager',
                'tokens_reassigned' => 2 // Only if force=true
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name parameter is required',
            '400.validation.builtin_role' => 'Cannot delete builtin roles',
            '400.validation.role_in_use' => 'Role is assigned to tokens. Use force=true to reassign to viewer',
            '404.role.not_found' => 'Role does not exist',
            '403.auth.forbidden' => 'Requires superadmin (*) permission'
        ],
        'notes' => 'Builtin roles (viewer, editor, designer, developer, admin) cannot be deleted. If tokens are using this role, deletion will fail unless force=true, which reassigns those tokens to the viewer role.'
    ],
    
    // ==========================================
    // AI INTEGRATION COMMANDS (BYOK - Bring Your Own Key)
    // ==========================================
    
    'listAiProviders' => [
        'description' => 'Lists all supported AI providers with their names, detection methods, and default models. BYOK system - use your own API keys.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listAiProviders',
        'success_response' => [
            'status' => 200,
            'code' => 'providers.list',
            'message' => 'AI providers retrieved successfully',
            'data' => [
                'providers' => [
                    [
                        'id' => 'openai',
                        'name' => 'OpenAI',
                        'has_prefix_detection' => true,
                        'default_models' => ['gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo']
                    ],
                    [
                        'id' => 'anthropic',
                        'name' => 'Anthropic',
                        'has_prefix_detection' => true,
                        'default_models' => ['claude-opus-4-20250514', 'claude-sonnet-4-20250514', 'claude-3-5-sonnet-latest']
                    ]
                ],
                'count' => 4
            ]
        ],
        'error_responses' => [
            '500.server.error' => 'Failed to load AI providers configuration'
        ],
        'notes' => 'BYOK (Bring Your Own Key) system - QuickSite does not store or manage API keys. Users provide their own keys per-request. Keys are only held in memory during API calls and never written to disk.'
    ],
    
    'detectProvider' => [
        'description' => 'Detects the AI provider from an API key prefix (sk- = OpenAI, sk-ant- = Anthropic, AIza = Google). Returns fallback providers for prefix-less keys like Mistral.',
        'method' => 'POST',
        'parameters' => [
            'key' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API key to analyze (minimum 20 characters)',
                'example' => 'sk-proj-abc123...'
            ]
        ],
        'example_post' => 'POST /management/detectProvider with body: {"key": "sk-proj-abc123..."}',
        'success_response' => [
            'status' => 200,
            'code' => 'provider.detected',
            'message' => 'Provider detected: OpenAI',
            'data' => [
                'provider' => 'openai',
                'name' => 'OpenAI',
                'method' => 'prefix'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'key parameter is required',
            '400.validation.empty' => 'API key cannot be empty',
            '400.validation.invalid_format' => 'API key appears too short to be valid'
        ],
        'notes' => 'Key is analyzed in memory and never stored. If provider cannot be detected (e.g., Mistral keys have no prefix), returns fallback_providers list to try with testAiKey.'
    ],
    
    'testAiKey' => [
        'description' => 'Tests if an API key is valid by making a minimal API call. Returns available models on success. Auto-detects provider from prefix, or specify provider parameter.',
        'method' => 'POST',
        'parameters' => [
            'key' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API key to test',
                'example' => 'sk-proj-abc123...'
            ],
            'provider' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Provider to test against (required for keys without detectable prefix)',
                'example' => 'mistral',
                'enum' => ['openai', 'anthropic', 'google', 'mistral']
            ]
        ],
        'example_post' => 'POST /management/testAiKey with body: {"key": "sk-proj-abc123..."} or {"key": "abc123...", "provider": "mistral"}',
        'success_response' => [
            'status' => 200,
            'code' => 'key.valid',
            'message' => 'API key is valid for OpenAI',
            'data' => [
                'valid' => true,
                'provider' => 'openai',
                'name' => 'OpenAI',
                'models' => ['gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'key parameter is required',
            '400.validation.provider_required' => 'Could not detect provider - specify provider parameter',
            '400.validation.invalid_provider' => 'Unknown provider',
            '401.key.invalid' => 'API key is invalid or expired'
        ],
        'notes' => 'Key is used for a single validation request and never stored on disk. Models returned are fetched from the provider API when possible, falling back to default list if unavailable.'
    ],
    
    'callAi' => [
        'description' => 'Makes an AI completion request via the specified provider. Server acts as proxy to avoid CORS. Key is used once and never stored.',
        'method' => 'POST',
        'parameters' => [
            'key' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API key for the provider',
                'example' => 'sk-proj-abc123...'
            ],
            'provider' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Provider to use',
                'example' => 'openai',
                'enum' => ['openai', 'anthropic', 'google', 'mistral']
            ],
            'model' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Model to use (from testAiKey models list)',
                'example' => 'gpt-4o'
            ],
            'messages' => [
                'required' => true,
                'type' => 'array',
                'description' => 'Chat messages array with role (system/user/assistant) and content',
                'example' => "[{\"role\": \"system\", \"content\": \"You are helpful.\"}, {\"role\": \"user\", \"content\": \"Hello!\"}]"
            ],
            'max_tokens' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Maximum tokens in response (1-128000)',
                'default' => 4096,
                'example' => 2000
            ],
            'temperature' => [
                'required' => false,
                'type' => 'number',
                'description' => 'Response randomness (0-2)',
                'default' => 0.7,
                'example' => 0.5
            ],
            'timeout' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Request timeout in seconds (10-300)',
                'default' => 120,
                'example' => 60
            ]
        ],
        'example_post' => 'POST /management/callAi with body: {"key": "sk-...", "provider": "openai", "model": "gpt-4o", "messages": [{"role": "user", "content": "Hello"}]}',
        'success_response' => [
            'status' => 200,
            'code' => 'ai.response',
            'message' => 'AI response received',
            'data' => [
                'content' => 'Hello! How can I help you today?',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 8
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_messages' => 'Messages must be a non-empty array',
            '400.validation.invalid_provider' => 'Unknown provider',
            '400.ai.invalid_request' => 'Invalid request format',
            '401.ai.invalid_key' => 'API key is invalid or expired',
            '402.ai.quota_exceeded' => 'API quota exhausted',
            '403.ai.no_access' => 'Key does not have access to this model',
            '429.ai.rate_limit' => 'Rate limit exceeded',
            '502.ai.network_error' => 'Could not connect to AI provider',
            '503.ai.overloaded' => 'AI provider is overloaded'
        ],
        'notes' => 'SECURITY: API key is passed per-request, used for a single API call, and immediately garbage collected. Keys are NEVER stored on disk. Server acts as proxy to bypass CORS restrictions. User controls their own AI costs and usage.'
    ],

    // JAVASCRIPT FUNCTIONS / INTERACTIONS
    // ==========================================
    
    'listJsFunctions' => [
        'description' => 'Lists all available QS.* JavaScript functions that can be used in {{call:...}} syntax for page interactions. Returns both core built-in functions and custom project-specific functions.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/listJsFunctions',
        'success_response' => [
            'status' => 200,
            'code' => 'js_functions.list',
            'message' => 'JavaScript functions retrieved successfully',
            'data' => [
                'functions' => [
                    [
                        'name' => 'show',
                        'type' => 'core',
                        'args' => [
                            ['name' => 'target', 'type' => 'string', 'required' => true, 'description' => 'CSS selector for target element(s)'],
                            ['name' => 'hideClass', 'type' => 'string', 'required' => false, 'default' => 'hidden', 'description' => 'CSS class to remove']
                        ],
                        'description' => 'Shows element(s) by removing the hide class',
                        'example' => '{{call:show:#modal}} or {{call:show:.cards,invisible}}'
                    ],
                    [
                        'name' => 'customFunc',
                        'type' => 'custom',
                        'args' => [['name' => 'param1', 'type' => 'string', 'required' => true]],
                        'description' => 'A custom function added via addJsFunction',
                        'example' => '{{call:customFunc:value}}'
                    ]
                ],
                'total' => 14,
                'core_count' => 12,
                'custom_count' => 2
            ]
        ],
        'error_responses' => [
            '500.server.error' => 'Failed to load JavaScript functions'
        ],
        'notes' => 'Core functions (12) are built into QS namespace and cannot be modified. Custom functions are project-specific and stored in secure/projects/{project}/config/custom-js-functions.json. Use {{call:functionName:arg1,arg2}} syntax in structure params like onclick, oninput.'
    ],
    
    'addJsFunction' => [
        'description' => 'Adds a custom JavaScript function to the QS namespace. Function becomes available in {{call:...}} syntax. Auto-regenerates qs-custom.js.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Function name (alphanumeric + underscore, cannot start with number, cannot match core function names)',
                'example' => 'myCustomToggle'
            ],
            'args' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Function arguments array with name, type, required, default, description',
                'example' => '[{"name": "target", "type": "string", "required": true}, {"name": "duration", "type": "number", "default": 300}]'
            ],
            'body' => [
                'required' => true,
                'type' => 'string',
                'description' => 'JavaScript function body (without function wrapper). Must be valid JS.',
                'example' => 'const el = document.querySelector(target); el.style.transition = `opacity ${duration}ms`; el.style.opacity = el.style.opacity === "0" ? "1" : "0";'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Human-readable description of what the function does',
                'example' => 'Toggles element opacity with custom duration'
            ]
        ],
        'example_post' => 'POST /management/addJsFunction with body: {"name": "fadeToggle", "args": [{"name": "target", "type": "string", "required": true}], "body": "const el = document.querySelector(target); el.classList.toggle(\"faded\");", "description": "Toggles faded class"}',
        'success_response' => [
            'status' => 201,
            'code' => 'js_function.created',
            'message' => 'JavaScript function "fadeToggle" created successfully',
            'data' => [
                'name' => 'fadeToggle',
                'type' => 'custom',
                'args' => [['name' => 'target', 'type' => 'string', 'required' => true]],
                'description' => 'Toggles faded class',
                'usage' => '{{call:fadeToggle:#myElement}}'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name and body parameters are required',
            '400.validation.invalid_name' => 'Invalid function name format',
            '400.validation.reserved_name' => 'Cannot use reserved core function name',
            '400.validation.duplicate_name' => 'Function with this name already exists',
            '400.validation.invalid_body' => 'JavaScript body contains syntax errors or dangerous patterns',
            '403.forbidden' => 'Insufficient permissions (admin required)'
        ],
        'notes' => 'Custom functions are stored per-project. The body is validated for balanced brackets and dangerous patterns (eval, Function constructor, etc.). Functions are immediately available after creation via qs-custom.js regeneration.'
    ],
    
    'editJsFunction' => [
        'description' => 'Edits an existing custom JavaScript function. Can update name, args, body, and description. Core functions cannot be edited.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Current name of the function to edit',
                'example' => 'fadeToggle'
            ],
            'newName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New name for the function (if renaming)',
                'example' => 'opacityToggle'
            ],
            'args' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Updated function arguments',
                'example' => '[{"name": "target", "type": "string", "required": true}, {"name": "speed", "type": "number", "default": 500}]'
            ],
            'body' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Updated JavaScript function body',
                'example' => 'document.querySelector(target).classList.toggle("visible");'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Updated description',
                'example' => 'Toggles visible class with optional speed'
            ]
        ],
        'example_post' => 'POST /management/editJsFunction with body: {"name": "fadeToggle", "newName": "opacityToggle", "description": "Improved toggle function"}',
        'success_response' => [
            'status' => 200,
            'code' => 'js_function.updated',
            'message' => 'JavaScript function "opacityToggle" updated successfully',
            'data' => [
                'name' => 'opacityToggle',
                'previous_name' => 'fadeToggle',
                'type' => 'custom',
                'updated_fields' => ['name', 'description']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name parameter is required',
            '400.validation.no_changes' => 'No fields provided to update',
            '400.validation.invalid_name' => 'Invalid new function name format',
            '400.validation.duplicate_name' => 'A function with the new name already exists',
            '400.validation.invalid_body' => 'JavaScript body contains syntax errors',
            '403.forbidden' => 'Cannot edit core functions',
            '404.not_found' => 'Function not found'
        ],
        'notes' => 'Only custom functions can be edited. Core functions (show, hide, toggle, etc.) are protected. Provide only the fields you want to update. Regenerates qs-custom.js on success.'
    ],
    
    'deleteJsFunction' => [
        'description' => 'Deletes a custom JavaScript function. Core functions cannot be deleted. Removes from qs-custom.js.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the custom function to delete',
                'example' => 'fadeToggle'
            ]
        ],
        'example_post' => 'POST /management/deleteJsFunction with body: {"name": "fadeToggle"}',
        'success_response' => [
            'status' => 200,
            'code' => 'js_function.deleted',
            'message' => 'JavaScript function "fadeToggle" deleted successfully',
            'data' => [
                'deleted_function' => 'fadeToggle',
                'remaining_custom_functions' => 3
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'name parameter is required',
            '403.forbidden' => 'Cannot delete core functions',
            '404.not_found' => 'Custom function not found'
        ],
        'notes' => 'WARNING: Deleting a function that is used in page structures will cause JavaScript errors at runtime. Check usage before deleting. Core functions (12) are protected and cannot be deleted.'
    ],
    
    // ==========================================
    // INTERACTION MANAGEMENT COMMANDS
    // ==========================================
    
    'listInteractions' => [
        'description' => 'Lists all interactions ({{call:...}} bindings) on a specific element. Returns parsed interactions grouped by event, available events for the element type, and element info.',
        'method' => 'GET',
        'parameters' => [
            'structType' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: page, menu, footer, or component',
                'example' => 'page'
            ],
            'pageName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required when structType is "page")',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID from data-qs-node attribute',
                'example' => 'hero/cta-button'
            ]
        ],
        'example_get' => 'GET /management/listInteractions/page/home/hero%2Fcta-button',
        'success_response' => [
            'status' => 200,
            'code' => 'interactions.list',
            'message' => 'Interactions retrieved successfully',
            'data' => [
                'interactions' => [
                    [
                        'event' => 'onclick',
                        'function' => 'toggleHide',
                        'params' => ['#menu'],
                        'raw' => '{{call:toggleHide:#menu}}'
                    ],
                    [
                        'event' => 'onmouseover',
                        'function' => 'addClass',
                        'params' => ['.tooltip', 'visible'],
                        'raw' => '{{call:addClass:.tooltip,visible}}'
                    ]
                ],
                'availableEvents' => ['onclick', 'ondblclick', 'onmouseover', 'onmouseout', 'onmouseenter', 'onmouseleave', 'onfocus', 'onblur'],
                'element' => [
                    'tag' => 'button',
                    'nodeId' => 'hero/cta-button'
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'structType and nodeId are required',
            '400.validation.invalid_struct_type' => 'Invalid structure type',
            '404.node.not_found' => 'Node not found in structure'
        ],
        'notes' => 'Available events are filtered by element tag type. Forms get onsubmit/onreset, inputs get oninput/onchange, media elements get onplay/onpause/onended. Interactions are parsed from {{call:...}} syntax in event params.'
    ],
    
    'addInteraction' => [
        'description' => 'Adds an interaction ({{call:...}}) to an element\'s event attribute. Generates the call syntax automatically from function name and params.',
        'method' => 'POST',
        'parameters' => [
            'structType' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: page, menu, footer, or component',
                'example' => 'page'
            ],
            'pageName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required when structType is "page")',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID from data-qs-node attribute',
                'example' => 'hero/cta-button'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Event name (onclick, onmouseover, oninput, etc.)',
                'example' => 'onclick'
            ],
            'function' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Function name from listJsFunctions (core or custom)',
                'example' => 'toggleHide'
            ],
            'params' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Function parameters as array of strings',
                'example' => '["#contact-modal"]'
            ]
        ],
        'example_post' => 'POST /management/addInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "function": "show", "params": ["#contact-modal"]}',
        'success_response' => [
            'status' => 201,
            'code' => 'interaction.added',
            'message' => 'Interaction added successfully',
            'data' => [
                'event' => 'onclick',
                'function' => 'show',
                'params' => ['#contact-modal'],
                'raw' => '{{call:show:#contact-modal}}',
                'total_on_event' => 2
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'structType, nodeId, event, and function are required',
            '400.validation.invalid_event' => 'Invalid event name',
            '400.validation.invalid_function' => 'Function not found in available functions',
            '404.node.not_found' => 'Node not found in structure'
        ],
        'notes' => 'If the event already has interactions, the new one is appended (space-separated). Use listJsFunctions to get available function names. Params array order must match function argument order.'
    ],
    
    'editInteraction' => [
        'description' => 'Edits an existing interaction on an element. Replaces the interaction at the specified index within the event.',
        'method' => 'PUT',
        'parameters' => [
            'structType' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: page, menu, footer, or component',
                'example' => 'page'
            ],
            'pageName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required when structType is "page")',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID from data-qs-node attribute',
                'example' => 'hero/cta-button'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Event name containing the interaction to edit',
                'example' => 'onclick'
            ],
            'index' => [
                'required' => true,
                'type' => 'integer',
                'description' => 'Index of the interaction within the event (0-based)',
                'example' => 0
            ],
            'function' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New function name',
                'example' => 'toggleHide'
            ],
            'params' => [
                'required' => false,
                'type' => 'array',
                'description' => 'New function parameters',
                'example' => '["#modal", "invisible"]'
            ]
        ],
        'example_put' => 'PUT /management/editInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "index": 0, "function": "toggleHide", "params": ["#modal"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'interaction.updated',
            'message' => 'Interaction updated successfully',
            'data' => [
                'event' => 'onclick',
                'index' => 0,
                'function' => 'toggleHide',
                'params' => ['#modal'],
                'raw' => '{{call:toggleHide:#modal}}'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'structType, nodeId, event, index, and function are required',
            '400.validation.invalid_index' => 'Index out of bounds for this event',
            '404.node.not_found' => 'Node not found in structure',
            '404.interaction.not_found' => 'No interaction found at specified index'
        ],
        'notes' => 'Index is 0-based within the specific event. Use listInteractions to find the correct index. Only replaces the interaction at that index, other interactions on the same event are preserved.'
    ],
    
    'deleteInteraction' => [
        'description' => 'Deletes an interaction from an element. Can delete a specific interaction by index or all interactions on an event.',
        'method' => 'DELETE',
        'parameters' => [
            'structType' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: page, menu, footer, or component',
                'example' => 'page'
            ],
            'pageName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page name (required when structType is "page")',
                'example' => 'home'
            ],
            'nodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Node ID from data-qs-node attribute',
                'example' => 'hero/cta-button'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Event name containing the interaction(s) to delete',
                'example' => 'onclick'
            ],
            'index' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Index of specific interaction to delete (omit to delete all on event)',
                'example' => 0
            ]
        ],
        'example_delete' => 'DELETE /management/deleteInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "index": 0}',
        'success_response' => [
            'status' => 200,
            'code' => 'interaction.deleted',
            'message' => 'Interaction deleted successfully',
            'data' => [
                'event' => 'onclick',
                'deleted_index' => 0,
                'remaining_on_event' => 1
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'structType, nodeId, and event are required',
            '400.validation.invalid_index' => 'Index out of bounds for this event',
            '404.node.not_found' => 'Node not found in structure',
            '404.interaction.not_found' => 'No interaction found on this event'
        ],
        'notes' => 'If index is omitted, ALL interactions on that event are removed (the event param is deleted entirely). If index is provided, only that specific interaction is removed and others are preserved.'
    ]
];

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments - [0] = command name (optional)
 * @return ApiResponse
 */
function __command_help(array $params = [], array $urlParams = []): ApiResponse {
    // Access the commands defined in global scope
    $commands = $GLOBALS['__help_commands'];
    
    // Check if specific command requested via URL segment
    if (!empty($urlParams) && isset($urlParams[0])) {
        $cmd = $urlParams[0];
        
        if (isset($commands[$cmd])) {
            return ApiResponse::create(200, 'operation.success')
                ->withMessage('Command documentation retrieved')
                ->withData($commands[$cmd]);
        } else {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Command documentation not found")
                ->withData([
                    'requested_command' => $cmd,
                    'available_commands' => array_keys($commands)
                ]);
        }
    }

    // Return all commands if no specific command requested
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('All command documentation retrieved')
        ->withData([
            'commands' => $commands,
            'total' => count($commands),
            'base_url' => rtrim(BASE_URL, '/') . '/management',
            'command_categories' => [
                'folder_management' => ['setPublicSpace', 'renameSecureFolder', 'renamePublicFolder'],
                'route_management' => ['addRoute', 'deleteRoute', 'getRoutes', 'getSiteMap'],
                'structure_management' => ['getStructure', 'editStructure', 'listComponents', 'listPages'],
                'node_management' => ['moveNode', 'deleteNode', 'addNode', 'editNode', 'addComponentToNode', 'editComponentToNode'],
                'alias_management' => ['createAlias', 'deleteAlias', 'listAliases'],
                'translation_management' => ['getTranslation', 'getTranslations', 'setTranslationKeys', 'deleteTranslationKeys', 'getTranslationKeys', 'validateTranslations', 'getUnusedTranslationKeys', 'analyzeTranslations'],
                'language_management' => ['getLangList', 'setMultilingual', 'checkStructureMulti', 'addLang', 'deleteLang', 'setDefaultLang'],
                'asset_management' => ['uploadAsset', 'deleteAsset', 'listAssets', 'updateAssetMeta'],
                'style_management' => ['getStyles', 'editStyles'],
                'css_variables_rules' => ['getRootVariables', 'setRootVariables', 'listStyleRules', 'getStyleRule', 'setStyleRule', 'deleteStyleRule'],
                'css_animations' => ['getKeyframes', 'setKeyframes', 'deleteKeyframes'],
                'site_customization' => ['editFavicon', 'editTitle'],
                'build_deployment' => ['build', 'listBuilds', 'getBuild', 'deleteBuild', 'cleanBuilds', 'deployBuild', 'downloadBuild'],
                'project_management' => ['listProjects', 'getActiveProject', 'switchProject', 'createProject', 'deleteProject'],
                'backup_restore' => ['backupProject', 'listBackups', 'restoreBackup', 'deleteBackup'],
                'export_import' => ['exportProject', 'importProject', 'downloadExport', 'clearExports'],
                'storage_monitoring' => ['getSizeInfo'],
                'command_history' => ['getCommandHistory', 'clearCommandHistory'],
                'authentication' => ['generateToken', 'listTokens', 'revokeToken'],
                'role_management' => ['listRoles', 'getMyPermissions', 'createRole', 'editRole', 'deleteRole'],
                'ai_integration' => ['listAiProviders', 'detectProvider', 'testAiKey', 'callAi'],
                'documentation' => ['help']
            ],
            'authentication' => [
                'required' => true,
                'header' => 'Authorization: Bearer <your-token>',
                'token_format' => 'tvt_<48 hex characters>',
                'default_token' => 'tvt_dev_default_change_me_in_production (CHANGE IN PRODUCTION!)',
                'role_system' => [
                    '*' => 'Superadmin - full access to all 97 commands including token/role management',
                    'viewer' => 'Read-only access (27 commands) - get*, list*, validate*, help, listAiProviders, listJsFunctions, listInteractions',
                    'editor' => 'Content editing (55 commands) - viewer + structure, translations, assets, interactions',
                    'designer' => 'Style editing (63 commands) - editor + CSS, animations, visual elements',
                    'developer' => 'Build access (70 commands) - designer + build, deploy, projects, AI, listJsFunctions',
                    'admin' => 'Full except tokens (91 commands) - developer + all except token/role management + JS functions'
                ],
                'endpoints' => [
                    'listRoles' => 'View available roles (* sees commands, others see names only)',
                    'getMyPermissions' => 'See your role and accessible commands',
                    'createRole' => 'Create custom role (* only)',
                    'editRole' => 'Edit role (* only, builtin description only)',
                    'deleteRole' => 'Delete custom role (* only)'
                ],
                'config_file' => 'secure/management/config/auth.php',
                'roles_config' => 'secure/config/roles.php'
            ],
            'cors' => [
                'development_mode' => 'Allows localhost:* origins automatically',
                'config_file' => 'secure/management/config/auth.php',
                'allowed_methods' => ['GET', 'POST', 'OPTIONS']
            ],
            'usage' => 'All requests require Authorization header. GET commands: help, getRoutes, getSiteMap, getStructure, getTranslation, getTranslations, getLangList, getTranslationKeys, validateTranslations, getUnusedTranslationKeys, analyzeTranslations, listAssets, getStyles, getRootVariables, listStyleRules, getStyleRule, getKeyframes, listTokens, listComponents, listPages, listAliases, listAiProviders. POST commands: all others.',
            'note' => 'For GET commands with URL parameters, use URL segments (e.g., /getStructure/menu, /validateTranslations/en, /getStyleRule/.btn-primary, /getSiteMap/text). For POST commands, send parameters as JSON in request body. For file uploads, use multipart/form-data encoding.',
            'workflows' => [
                'translation_workflow' => '1) analyzeTranslations for full health check, OR 2) validateTranslations to find missing, 3) getUnusedTranslationKeys to find orphans, 4) setTranslationKeys to add/update, 5) deleteTranslationKeys to clean up.',
                'asset_workflow' => '1) listAssets to see existing files with metadata, 2) uploadAsset to add new files (with optional description), 3) updateAssetMeta to add/update descriptions and alt text, 4) deleteAsset to remove files.',
                'style_workflow' => '1) getStyles to retrieve current CSS, 2) editStyles to update (response includes backup for rollback).',
                'css_granular_workflow' => '1) getRootVariables to see all CSS variables, 2) setRootVariables to update colors/spacing/etc, 3) listStyleRules to see all selectors, 4) getStyleRule to inspect specific rules, 5) setStyleRule to add/update rules, 6) deleteStyleRule to remove rules.',
                'animation_workflow' => '1) getKeyframes to list all animations, 2) setKeyframes to add/update animations, 3) deleteKeyframes to remove animations.',
                'token_workflow' => '1) listTokens to see existing tokens, 2) generateToken to create new ones with role, 3) revokeToken to delete old tokens.',
                'role_workflow' => '1) listRoles to see available roles and commands, 2) getMyPermissions to check current access, 3) createRole to add custom roles, 4) editRole to modify roles, 5) deleteRole to remove custom roles.',
                'alias_workflow' => '1) listAliases to see existing redirects, 2) createAlias to add URL redirects, 3) deleteAlias to remove redirects.',
                'component_workflow' => '1) listComponents to see available reusable components, 2) getStructure/component/{name} to view details, 3) editStructure with type="component" to create/update/delete.',
                'sitemap_workflow' => '1) getSiteMap for JSON data with route details and coverage, 2) getSiteMap/text to generate plain text sitemap.txt for SEO crawlers.',
                'project_workflow' => '1) listProjects to see all available projects, 2) getActiveProject to check current project, 3) createProject to start a new project, 4) switchProject to change active project, 5) deleteProject to remove (requires confirm=true).',
                'backup_workflow' => '1) backupProject to create instant backup, 2) listBackups to see available backups with size/age info, 3) restoreBackup to restore from backup (optional pre-restore backup), 4) deleteBackup to free disk space.',
                'export_workflow' => '1) exportProject to create shareable ZIP (JSON-only, secure), 2) downloadExport to download the ZIP, 3) importProject to import from ZIP (rebuilds PHP from JSON), 4) clearExports to clean up old exports.',
                'ai_workflow' => '1) listAiProviders to see supported AI providers, 2) detectProvider to identify provider from key prefix, 3) testAiKey to validate key and get available models, 4) callAi to make AI completion requests (BYOK - user provides own API key per-request).'
            ]
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_help([], $urlSegments)->send();
}