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
    
    'build' => [
        'description' => 'Creates a production-ready build with compiled PHP files, removing management system and creating ZIP archive',
        'method' => 'POST',
        'parameters' => [
            'public' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name for public folder (default: public_template)',
                'example' => 'public',
                'validation' => 'Alphanumeric, hyphens, underscores only'
            ],
            'secure' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name for secure folder (default: secure_template)',
                'example' => 'secure',
                'validation' => 'Alphanumeric, hyphens, underscores only'
            ]
        ],
        'example_post' => 'POST /management/build with body: {"public": "public", "secure": "secure"}',
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
            '400.validation.invalid_format' => 'Invalid folder name format',
            '500.server.file_write_failed' => 'Failed to create build directory or files',
            '500.server.internal_error' => 'Build or compression failed'
        ],
        'notes' => 'Compiles JSON templates to PHP using JsonToPhpCompiler. Removes entire management/ folder and material/ folder. Sanitizes config.php (removes database credentials). Creates timestamped folder and ZIP. Page titles use translation system: $translator->translate("page.titles.{route}"). Includes processUrl() helper and system variables ($__current_page, $__lang, $__base_url). Components are inlined during compilation.'
    ],
    
    'changeFavicon' => [
        'description' => 'Updates the site favicon with a new image from assets/images folder (PNG only)',
        'method' => 'POST',
        'parameters' => [
            'imageName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Filename from assets/images folder (must be PNG)',
                'example' => 'logo.png',
                'validation' => 'Must exist in assets/images/, PNG format only'
            ]
        ],
        'example_post' => 'POST /management/changeFavicon with body: {"imageName": "logo.png"}',
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
    
    'modifyTitle' => [
        'description' => 'Updates page title in all translation files (page.titles.{route} structure)',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route name to update title for',
                'example' => 'home',
                'validation' => 'Must be an existing route'
            ],
            'titles' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Object with language codes as keys and title strings as values',
                'example' => '{"en": "Home - My Site", "fr": "Accueil - Mon Site"}',
                'validation' => 'Must include all active languages, titles max 200 chars each'
            ]
        ],
        'example_post' => 'POST /management/modifyTitle with body: {"route": "home", "titles": {"en": "Home - My Site", "fr": "Accueil - Mon Site"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page titles updated successfully',
            'data' => [
                'route' => 'home',
                'updated_languages' => ['en', 'fr'],
                'titles' => {
                    'en' => 'Home - My Site',
                    'fr' => 'Accueil - Mon Site'
                }
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route or titles parameter',
            '400.validation.invalid_format' => 'Titles must be object, title too long (>200 chars), or invalid language code',
            '400.translation.missing_language' => 'Title not provided for all active languages',
            '404.route.not_found' => 'Route does not exist',
            '404.file.not_found' => 'Translation file not found for a language',
            '500.server.file_write_failed' => 'Failed to write translation file',
            '500.server.internal_error' => 'Invalid JSON in translation file'
        ],
        'notes' => 'Updates page.titles.{route} key in all language translation files. Creates nested page.titles object if not exists. Used by Page.php and PageManagement.php: $translator->translate("page.titles.{$route}"). Must provide titles for ALL active languages.'
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
        'description' => 'Retrieves the JSON structure for a page, menu, footer, or component',
        'method' => 'GET',
        'url_structure' => '/management/getStructure/{type}/{name?}',
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
            ]
        ],
        'example_get' => 'GET /management/getStructure/menu, GET /management/getStructure/page/home, or GET /management/getStructure/component/img-dynamic',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure retrieved successfully',
            'data' => [
                'type' => 'component',
                'name' => 'img-dynamic',
                'structure' => {...},
                'file' => '/path/to/img-dynamic.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type or name in URL',
            '400.validation.invalid_format' => 'Invalid type (must be page/menu/footer/component)',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to read structure file',
            '500.server.internal_error' => 'Invalid JSON in structure file'
        ],
        'notes' => 'Uses URL segments. For menu/footer: GET /getStructure/{type}. For pages/components: GET /getStructure/{type}/{name}. Components are reusable templates with {{placeholder}} syntax.'
    ],

    'editStructure' => [
        'description' => 'Updates the JSON structure for a page, menu, footer, or component (creates component if new)',
        'method' => 'POST',
        'parameters' => [
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Type of structure to update',
                'example' => 'component',
                'validation' => 'Must be one of: page, menu, footer, component'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Name (required for page/component)',
                'example' => 'img-dynamic',
                'validation' => 'Must be existing route (pages) or alphanumeric/hyphens/underscores (components)'
            ],
            'structure' => [
                'required' => true,
                'type' => 'array/object',
                'description' => 'Complete JSON structure (replaces existing). Array for pages/menu/footer, object for components',
                'example' => '{"tag": "img", "params": {"src": "{{src}}"}, "children": null}',
                'validation' => 'Must be valid JSON, max 10,000 nodes, max 50 levels deep'
            ]
        ],
        'example_post' => 'POST /management/editStructure with body: {"type": "component", "name": "button", "structure": {...}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Structure updated successfully',
            'data' => [
                'type' => 'component',
                'name' => 'button',
                'file' => '/path/to/button.json',
                'structure_size' => 1,
                'node_count' => 3,
                'created' => true
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing type, name, or structure parameter',
            '400.validation.invalid_format' => 'Invalid type, structure format, too large, or too deeply nested',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to write structure file',
            '500.server.internal_error' => 'Failed to encode structure to JSON'
        ],
        'notes' => 'Replaces existing structure. For components: creates new if doesn\'t exist. Components use {{placeholder}} for dynamic data. Pages/menu/footer are arrays of nodes, components are single objects. Security: max 10,000 nodes, max 50 levels deep.'
    ],
    
    'getTranslation' => [
        'description' => 'Retrieves translations for a single language',
        'method' => 'GET',
        'url_structure' => '/management/getTranslation/{lang}',
        'parameters' => [
            '{lang}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code (URL segment)',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/getTranslation/en',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation retrieved successfully',
            'data' => [
                'language' => 'en',
                'translations' => {...},
                'file' => '/path/to/en.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing language code in URL',
            '400.validation.invalid_format' => 'Invalid language code format',
            '404.file.not_found' => 'Translation file not found',
            '500.server.file_write_failed' => 'Failed to read translation file'
        ],
        'notes' => 'Returns translations for a single language. Use this when editing one language at a time.'
    ],
    
    'getTranslations' => [
        'description' => 'Retrieves translations for all languages',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getTranslations',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translations retrieved successfully',
            'data' => [
                'translations' => {
                    'en' => {...},
                    'fr' => {...}
                },
                'languages' => ['en', 'fr'],
                'multilingual_enabled' => true
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'No translation files found'
        ],
        'notes' => 'Returns all translation files. Use getTranslation for single language operations.'
    ],
    
    'editTranslation' => [
        'description' => 'Updates translations for a single language',
        'method' => 'POST',
        'parameters' => [
            'language' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ],
            'translations' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Complete translation object (replaces existing)',
                'example' => '{"menu": {"home": "Home"}, "footer": {...}}',
                'validation' => 'Must be valid JSON object'
            ]
        ],
        'example_post' => 'POST /management/editTranslation with body: {"language": "en", "translations": {...}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translations updated successfully',
            'data' => [
                'language' => 'en',
                'file' => '/path/to/en.json'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing language or translations parameter',
            '400.validation.invalid_format' => 'Invalid translation format',
            '500.server.file_write_failed' => 'Failed to write translation file'
        ],
        'notes' => 'Completely replaces translation file for specified language.'
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
                'language_names' => {
                    'en' => 'English',
                    'fr' => 'Français'
                }
            ]
        ],
        'error_responses' => [],
        'notes' => 'Returns configuration from config.php. Useful for UI language selectors.'
    ],
    
    'addLang' => [
        'description' => 'Adds a new language to the system',
        'method' => 'POST',
        'parameters' => [
            'code' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code (ISO 639-1)',
                'example' => 'es',
                'validation' => '2-3 lowercase letters'
            ],
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language display name',
                'example' => 'Español',
                'validation' => 'Any string'
            ]
        ],
        'example_post' => 'POST /management/addLang with body: {"code": "es", "name": "Español"}',
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
            '400.validation.required' => 'Missing code or name parameter',
            '400.validation.invalid_format' => 'Invalid language code format',
            '409.conflict.duplicate' => 'Language already exists',
            '500.server.file_write_failed' => 'Failed to update config or create translation file'
        ],
        'notes' => 'Updates config.php and creates translation file by copying from default language. Requires system reload to apply changes.'
    ],
    
    'removeLang' => [
        'description' => 'Removes a language from the system',
        'method' => 'POST',
        'parameters' => [
            'code' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code to remove',
                'example' => 'es',
                'validation' => 'Must be an existing language (not default, not last)'
            ]
        ],
        'example_post' => 'POST /management/removeLang with body: {"code": "es"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Language removed successfully',
            'data' => [
                'code' => 'es',
                'config_updated' => '/path/to/config.php',
                'translation_file_deleted' => true,
                'remaining_languages' => ['en', 'fr']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing code parameter',
            '400.validation.invalid_format' => 'Cannot remove default or last language',
            '404.route.not_found' => 'Language not found',
            '500.server.file_write_failed' => 'Failed to update config'
        ],
        'notes' => 'Cannot remove default language or last remaining language. Updates config.php and deletes translation file.'
    ],
    
    'getTranslationKeys' => [
        'description' => 'Scans all JSON structures and extracts required translation keys',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/getTranslationKeys',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translation keys extracted successfully',
            'data' => [
                'keys_by_source' => {
                    'home' => ['home.title', 'home.welcomeMessage'],
                    'menu' => ['menu.home', 'menu.about'],
                    'footer' => ['footer.privacy', 'footer.terms']
                },
                'all_keys' => ['home.title', 'home.welcomeMessage', 'menu.home', ...],
                'total_keys' => 15,
                'scanned_files' => {
                    'pages' => ['home', 'privacy', 'terms'],
                    'menu' => true,
                    'footer' => true
                }
            ]
        ],
        'error_responses' => [],
        'notes' => 'Recursively scans all page JSONs, menu.json, and footer.json to extract textKey values. Ignores __RAW__ prefixed keys. Useful for identifying all translation keys that need to be defined.'
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
                'validation_results' => {
                    'en' => {
                        'file_exists' => true,
                        'file_valid' => true,
                        'required_keys' => 15,
                        'missing_keys' => [],
                        'total_missing' => 0,
                        'coverage_percent' => 100
                    },
                    'fr' => {
                        'file_exists' => true,
                        'file_valid' => true,
                        'required_keys' => 15,
                        'missing_keys' => ['menu.newpage', 'footer.copyright'],
                        'total_missing' => 2,
                        'coverage_percent' => 86.67
                    }
                },
                'total_required_keys' => 15,
                'languages_validated' => ['en', 'fr']
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_format' => 'Invalid language code format'
        ],
        'notes' => 'Compares keys from getTranslationKeys with actual translation files. Shows missing keys per language and coverage percentage. Use this to identify incomplete translations before deployment.'
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
        'method' => 'GET',
        'parameters' => [
            'category' => [
                'required' => true,
                'type' => 'query_string',
                'description' => 'Asset category',
                'example' => 'images',
                'validation' => 'Must be one of: images, scripts, font, audio, videos'
            ],
            'filename' => [
                'required' => true,
                'type' => 'query_string',
                'description' => 'Filename to delete',
                'example' => 'logo.png',
                'validation' => 'Must exist in specified category'
            ]
        ],
        'example_get' => 'GET /management?command=deleteAsset&category=images&filename=logo.png',
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
        'parameters' => [
            'category' => [
                'required' => false,
                'type' => 'query_string',
                'description' => 'Filter by category (optional)',
                'example' => 'images',
                'validation' => 'If provided, must be one of: images, scripts, font, audio, videos'
            ]
        ],
        'example_get' => 'GET /management?command=listAssets (all) or GET /management?command=listAssets&category=images (filtered)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Assets retrieved for category images',
            'data' => [
                'assets' => {
                    'images' => [
                        {
                            'filename' => 'logo.png',
                            'size' => 45678,
                            'modified' => '2025-12-03 10:30:15',
                            'path' => '/assets/images/logo.png'
                        },
                        {
                            'filename' => 'banner.jpg',
                            'size' => 123456,
                            'modified' => '2025-12-02 14:22:10',
                            'path' => '/assets/images/banner.jpg'
                        }
                    ]
                },
                'total_categories' => 1,
                'total_files' => 2
            ]
        ],
        'error_responses' => [
            '400.asset.invalid_category' => 'Invalid category'
        ],
        'notes' => 'Returns files sorted alphabetically. Excludes index.php files. Shows size in bytes and last modified timestamp.'
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
                'file' => '/path/to/style.scss',
                'size' => 12345,
                'modified' => '2025-12-03 10:30:15'
            ]
        ],
        'error_responses' => [
            '404.file.not_found' => 'Style file not found',
            '500.server.file_write_failed' => 'Failed to read style file'
        ],
        'notes' => 'Returns the complete content of style.scss. Use this to retrieve current styles before editing.'
    ],
    
    'editStyles' => [
        'description' => 'Updates the content of the main SCSS/CSS file',
        'method' => 'POST',
        'parameters' => [
            'content' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Complete CSS/SCSS content (replaces existing file)',
                'example' => 'body { margin: 0; }',
                'validation' => 'Must be string, max 2MB'
            ]
        ],
        'example_post' => 'POST /management/editStyles with body: {"content": "body { margin: 0; }"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Style file updated successfully',
            'data' => [
                'file' => '/path/to/style.scss',
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
        'notes' => 'Completely replaces style.scss content. Response includes backup_content for manual rollback if needed. Max size: 2MB. File locking prevents concurrent writes.'
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
        'command_categories' => [
            'folder_management' => ['movePublicRoot', 'moveSecureRoot'],
            'route_management' => ['addRoute', 'deleteRoute', 'getRoutes'],
            'structure_management' => ['getStructure', 'editStructure'],
            'translation_management' => ['getTranslation', 'getTranslations', 'editTranslation', 'getTranslationKeys', 'validateTranslations'],
            'language_management' => ['getLangList', 'addLang', 'removeLang'],
            'asset_management' => ['uploadAsset', 'deleteAsset', 'listAssets'],
            'style_management' => ['getStyles', 'editStyles'],
            'site_customization' => ['changeFavicon', 'modifyTitle'],
            'build_deployment' => ['build'],
            'documentation' => ['help']
        ],
        'usage' => 'GET commands: help, getRoutes, getStructure, getTranslation, getTranslations, getLangList, getTranslationKeys, validateTranslations, deleteAsset, listAssets, getStyles. POST commands: all others (movePublicRoot, moveSecureRoot, addRoute, deleteRoute, editStructure, editTranslation, addLang, removeLang, uploadAsset, editStyles, build, changeFavicon, modifyTitle).',
        'note' => 'For GET commands with URL parameters, use URL segments (e.g., /getStructure/menu, /validateTranslations/en). For POST commands, send parameters as JSON in request body. For file uploads, use multipart/form-data encoding.',
        'workflows' => [
            'translation_workflow' => '1) getTranslationKeys to see required keys, 2) validateTranslations to find missing translations, 3) editTranslation to add them.',
            'asset_workflow' => '1) listAssets to see existing files, 2) uploadAsset to add new files (auto-renames if exists), 3) deleteAsset to remove files.',
            'style_workflow' => '1) getStyles to retrieve current CSS, 2) editStyles to update (response includes backup for rollback).'
        ]
    ])
    ->send();