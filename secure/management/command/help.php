<?php
/**
 * help - API Documentation for all management commands
 * 
 * @method GET
 * @url /management/help
 * @url /management/help/{commandName}
 * @auth none (public)
 * @permission read
 * 
 * Returns comprehensive documentation for all API commands,
 * or detailed documentation for a specific command.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Define commands in global scope for access from __command_help()
$GLOBALS['__help_commands'] = [
    'addRoute' => [
        'description' => 'Creates a new route with PHP page template and empty JSON structure. Supports nested routes (e.g., "guides/getting-started") and parameterised routes (beta.8 A1) via ":name" segments (e.g., "products/:slug").',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route path (will be used in URL). Use slashes for nested routes. Use ":name" segments for path parameters (e.g., "products/:slug" matches /products/red-vase). Can use "name" as alias for simple routes.',
                'example' => 'products/:slug',
                'validation' => 'Literal segments: lowercase letters, numbers, hyphens (no leading/trailing hyphens). Param segments: ":" followed by an identifier ([a-zA-Z_][a-zA-Z0-9_]*). Max depth: 5 levels.',
                'alias' => 'name'
            ],
            'parent' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Parent route to prepend. Alternative to using slashes in route param (e.g., parent="documentation", route="commands" is equivalent to route="documentation/commands").',
                'example' => 'documentation',
                'validation' => 'Must be an existing route if provided'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addRoute with body: {"route": "documentation/commands"} or {"route": "commands", "parent": "documentation"} or {"route": "products/:slug"}',
        'success_response' => [
            'status' => 201,
            'code' => 'route.created',
            'message' => 'Route successfully created and registered',
            'data' => [
                'route' => 'products/:slug',
                'php_file' => '/templates/pages/products/__slug/__slug.php',
                'json_file' => '/templates/model/json/pages/products/__slug/__slug.json',
                'routes_updated' => '/path/to/routes.php',
                'warnings' => '(optional) Array of structured conflict warnings (beta.8 A1) — see notes'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route parameter',
            '400.route.invalid_segment' => 'Invalid segment. Literal: lowercase / numbers / hyphens. Param: ":name" with identifier.',
            '400.route.already_exists' => 'Route already exists',
            '500.server.file_write_failed' => 'Failed to create files',
            '500.server.directory_create_failed' => 'Failed to create directory',
            '200.route.special_page' => 'The first segment names a special-page template (404, 500, 403 or 401), which already exists — nothing was created and no route entry is needed. A 200, not an error.',
            '400.route.too_deep' => 'Route path exceeds maximum depth of 5 levels.',
            '400.validation.invalid_length' => 'Route path must be between 1 and 200 characters. Segment exceeds maximum length of 50.',
            '400.validation.invalid_type' => 'The route parameter is neither a string nor a number - an array, object, boolean or null. An integer or float is accepted and used as its string form.',
            '500.server.operation_failed' => 'An unexpected failure while creating the route files; any directories already created are removed first. The exception message is returned.'
        ],
        'notes' => 'Creates PHP template + empty JSON structure. **Filesystem mapping**: ":slug" segments become "__slug" on disk (NTFS reserves ":") via routeHelpers.php. **Conflict warnings** (beta.8 A1, non-blocking): the response data.warnings[] array surfaces situations the user should confirm. Each warning carries a machine-readable `type` (i18n key) + EN `message` fallback + structured details. Two shapes today: `route.warning.param_shadows_exact_siblings` (param route alongside existing literals — runtime-safe via specificity but worth verifying intent) and `route.warning.duplicate_param_at_depth` (two ":name" siblings — declaration order resolves, but ambiguous).'
    ],
    
    'deleteRoute' => [
        'description' => 'Deletes an existing route and its associated files (PHP and JSON). Also removes any URL aliases pointing to this route. For routes with children, requires force=true to cascade delete.',
        'method' => 'DELETE',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route path to delete',
                'example' => 'about-us',
                'validation' => 'Must be an existing route'
            ],
            'force' => [
                'required' => false,
                'type' => 'boolean',
                'ui_type' => 'checkbox',
                'description' => 'Force cascade deletion of route and all its children. Required when deleting a route that has nested child routes.',
                'example' => 'true',
                'default' => false
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteRoute with body: {"route": "about-us"} or {"route": "guides", "force": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'route.deleted',
            'message' => 'Route successfully deleted',
            'data' => [
                'route' => 'about-us',
                'deleted_routes' => ['about-us'],
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
            '400.route.has_children' => 'Route has child routes. Use force=true to cascade delete.',
            '404.route.not_found' => 'Route does not exist',
            '404.file.not_found' => 'Page template file not found',
            '500.server.file_write_failed' => 'Failed to delete files or update routes',
            '400.route.invalid_segment' => 'A segment of the route path is neither a valid literal (lowercase letters, numbers, hyphens, no leading or trailing hyphen) nor a valid ":name" parameter segment.',
            '400.validation.invalid_length' => 'Route path must be between 1 and 200 characters.',
            '400.validation.invalid_type' => 'The route parameter is neither a string nor a number - an array, object, boolean or null. An integer or float is accepted and used as its string form.'
        ],
        'notes' => 'Deletes both PHP template and JSON page structure. Updates routes.php automatically. Also removes URL aliases, page events, and route layout config. When force=true, deletes all descendant routes recursively.'
    ],
    
    'setRouteLayout' => [
        'description' => 'Configures visibility of menu (header) and footer for a specific route. Supports inheritance from parent routes and propagation to descendants.',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route path to configure',
                'example' => 'landing or app/dashboard',
                'validation' => 'Must be an existing route'
            ],
            'menu' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Whether to show menu (header). At least one of menu/footer required.',
                'example' => 'false',
                'validation' => 'Boolean (true/false)'
            ],
            'footer' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Whether to show footer. At least one of menu/footer required.',
                'example' => 'false',
                'validation' => 'Boolean (true/false)'
            ],
            'propagate' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Apply settings to all descendant routes',
                'example' => 'true',
                'default' => false
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setRouteLayout with body: {"route": "landing", "menu": false, "footer": false}',
        'success_response' => [
            'status' => 200,
            'code' => 'route.layout_updated',
            'message' => 'Layout settings updated for route',
            'data' => [
                'route' => 'landing',
                'layout' => ['menu' => false, 'footer' => false],
                'explicit' => true
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing route or both menu/footer parameters',
            '400.validation.invalid_type' => 'Invalid parameter type',
            '404.route.not_found' => 'Route does not exist',
            '400.validation.invalid_length' => 'Route path must be between 1 and 200 characters.'
        ],
        'notes' => 'Layout settings use inheritance: child routes inherit from nearest ancestor with explicit settings. Default is menu=true, footer=true. Use propagate=true to apply settings to all descendants at once.'
    ],

    'setRouteResolver' => [
        'description' => 'Attaches, patches or clears the server-side data resolver(s) on a route. One idempotent command covers six body shapes: replace the whole entry with a single resolver or with an array of them, patch or append one slot by index, remove one slot by index, or clear the route entirely. Resolvers run before the page renders and expose their results to the template.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/setRouteResolver',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route the resolvers attach to. It must already exist — create it with addRoute first. setRouteResolver never touches the route\'s .php / .json template files, only the resolver sidecar.',
                'example' => 'book/:id',
                'validation' => 'Leading and trailing slashes are stripped and backslashes are converted to slashes before the lookup, so "/book/:id/" and "book/:id" name the same route. An integer or float is accepted and used as its string form.'
            ],
            'resolver' => [
                'required' => false,
                'type' => 'object|array',
                'description' => 'One resolver config object, or an array of them. Omit it — or send null, {} or [] — to clear the route, or to remove one slot when combined with index. Config fields: "kind" ("data", the default, or the side-effect kinds "oauth-start", "oauth-callback", "oauth-logout"); for kind=data, "endpoint" (required, an "@apiId/endpointId" reference that must resolve in the project\'s API registry and must not be callableFrom="client"), "inputs" (object of input name to "param:<segment>" / "query:<key>" / "session:<field>" / a bare literal), "expose" (object of template variable name to a response dot-path; "" means the whole response), "cacheTTL" (non-negative integer seconds) and "onMiss" ("render-empty"); for the oauth kinds, "provider" (a preset id or a "{:routeParam}" placeholder) and, on oauth-start only, "callback_url" — the data fields are refused on an oauth kind and vice versa.',
                'example' => '{"endpoint": "@books-api/get-book", "inputs": {"id": "param:id"}, "expose": {"book": "data"}, "cacheTTL": 3600}'
            ],
            'index' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Targets one slot of the array shape. With a resolver object it patches slot N, or appends when N equals the current length. Without a resolver it removes slot N. It cannot be combined with an array resolver: an array replaces the whole entry while an index targets one slot, so the pair is ambiguous and is refused.',
                'example' => 0,
                'validation' => 'Must be a JSON integer. A string, a float or a negative number is refused — 1.5 and "0" are both rejected, not coerced. Patch or append accepts 0 through the current length; remove accepts 0 through length-1.'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setRouteResolver with body: {"route": "book/:id", "resolver": {"endpoint": "@books-api/get-book", "inputs": {"id": "param:id"}, "expose": {"book": "data"}, "cacheTTL": 3600}}',
        'example_array' => 'Replace the whole entry with several resolvers (they run in parallel): {"route": "book/:id", "resolver": [{"endpoint": "@books-api/get-book", "inputs": {"id": "param:id"}, "expose": {"book": "data"}, "cacheTTL": 3600}, {"endpoint": "@books-api/get-chapters", "inputs": {"id": "param:id"}, "expose": {"chapters": "data.chapters"}, "cacheTTL": 60}]}',
        'example_patch_slot' => 'Patch just the first resolver, leaving the others alone: {"route": "book/:id", "resolver": {"endpoint": "@books-api/get-book", "expose": {"book": "data"}}, "index": 0}',
        'example_append' => 'Append a resolver — index must equal the current length: {"route": "book/:id", "resolver": {"endpoint": "@books-api/get-chapters", "expose": {"chapters": "data"}}, "index": 2}',
        'example_remove' => 'Remove one resolver, shrinking the array: {"route": "book/:id", "index": 1}',
        'example_clear' => 'Clear every resolver on the route: {"route": "book/:id"}',
        'success_response' => [
            'status' => 200,
            'code' => 'resolver.saved',
            'message' => "Resolver saved for route 'book/:id' (replace_all_scalar, 1 resolver)",
            'data' => [
                'route' => 'book/:id',
                'resolvers' => [
                    [
                        'endpoint' => '@books-api/get-book',
                        'inputs' => ['id' => 'param:id'],
                        'expose' => ['book' => 'data'],
                        'cacheTTL' => 3600
                    ]
                ],
                'mode' => 'replace_all_scalar | replace_all_array | patch | append',
                'index' => 'the index that was targeted, or null when the whole entry was replaced'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'The route parameter is missing, empty, or neither a string nor a number.',
            '404.route.not_found' => 'The route does not exist. Create it with addRoute first — setRouteResolver only writes the sidecar.',
            '400.validation.invalid_type' => 'index is not a non-negative JSON integer, or resolver is neither an object, an array of objects, nor null.',
            '400.validation.conflict' => 'An array resolver was combined with index. Pass a single resolver object with index to patch one slot, or an array with no index to replace the whole entry.',
            '400.resolver.index_out_of_range' => 'index targets a slot that does not exist: past the end when patching or appending (append must equal the current length), or at/past the end when removing. data.currentLength reports the length the caller should have used.',
            '400.validation.invalid' => 'One or more resolver configs failed validation. errors[] carries a field path re-pathed to resolver[N].x.y plus a resolverIndex, so each error maps back to one slot. Reasons include a missing or unregistered endpoint, an endpoint marked callableFrom="client", a kind outside the allowed set, a field that does not apply to the kind, a malformed inputs or expose name, a negative cacheTTL, an onMiss outside the allowed set, two resolvers exposing the same flat template variable (reason "collision"), and an array that mixes data with side-effect kinds (reason "mixed_kinds").',
            '200.resolver.cleared' => 'Every resolver was removed from the route. A 200, not an error.',
            '200.resolver.unchanged' => 'A clear was requested but the route had no resolver attached — the idempotent no-op. A 200, not an error.',
            '200.resolver.removed' => 'The resolver at index was removed and the array shrank. data.removedIndex echoes the slot. A 200, not an error.',
            '500.server.operation_failed' => 'The resolver sidecar could not be written.'
        ],
        'notes' => 'The six body shapes are: {route, resolver} replace with one; {route, resolver: [...]} replace with several; {route, resolver, index} patch that slot; {route, resolver, index: <length>} append; {route, index} remove that slot; {route} clear all. The on-disk shape follows the length — a single resolver is stored as a scalar, several as an array — but data.resolvers in the response is ALWAYS the resulting array, empty when cleared. Every resolver on one route must be the same kind: side-effect kinds short-circuit the render with a redirect while data resolvers feed it, so a mixed array is refused. Across data resolvers, expose names share one flat template namespace and a duplicate is refused at save time; the always-available $r0 / $r1 namespaced form is the alternative. Use cleanResolverCache to drop cached resolver responses after changing an endpoint.'
    ],

    'cleanResolverCache' => [
        'description' => 'Deletes entries from the server-side data-resolver response cache. With no parameters it is a housekeeping pass that removes only expired entries; the other parameters target a single endpoint, a single API, everything stored before a timestamp, or the whole cache.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/cleanResolverCache',
        'parameters' => [
            'endpoint' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Exact endpoint reference ("@apiId/endpointId"). Deletes every cached response for that one endpoint. The most surgical option, and the highest priority — when present, the other parameters are ignored.',
                'example' => '@books-api/get-book'
            ],
            'apiId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Deletes every cached response belonging to that API, across all of its endpoints. Ignored when endpoint is present.',
                'example' => 'books-api'
            ],
            'all' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Deletes every cached entry regardless of expiry. Ignored when endpoint or apiId is present.',
                'example' => true,
                'default' => false,
                'validation' => 'Read with a boolean filter, so true/false, 1/0 and the strings "true"/"false"/"on"/"yes" are all accepted; anything else reads as false.'
            ],
            'before' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Unix timestamp. Deletes every entry stored before it, regardless of expiry — the "drop everything older than X" sweep. Ignored when endpoint, apiId or all is present.',
                'example' => 1717900000,
                'validation' => 'Cast to an integer, which must be positive. A numeric string is accepted; 0, a negative number and a non-numeric string are refused.'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/cleanResolverCache with body: {} (housekeeping — expired entries only), or {"endpoint": "@books-api/get-book"}, {"apiId": "books-api"}, {"before": 1717900000}, {"all": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'cache.cleared',
            'message' => 'Resolver cache housekeeping: 12 expired entries deleted',
            'data' => [
                'deleted' => 12,
                'mode' => 'expired | endpoint | api | all | before',
                'endpoint' => '(mode=endpoint only) the endpoint reference that was cleared',
                'apiId' => '(mode=api only) the API whose entries were cleared',
                'before' => '(mode=before only) the timestamp entries were cleared before'
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_value' => 'before did not cast to a positive unix timestamp. errors[] reports the value received and what was expected.'
        ],
        'notes' => 'The parameters are mutually exclusive by priority, not by refusal: endpoint wins over apiId, which wins over all, which wins over before, and no parameter at all means the expired-only housekeeping pass. Sending two therefore succeeds and applies the higher-priority one — data.mode always reports which pass actually ran. Cached responses are produced by data resolvers with a cacheTTL; see setRouteResolver.'
    ],
    
    'build' => [
        'description' => 'Creates a production-ready build with compiled PHP files and optional folder renaming. One build per project: refuses while a build already exists.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom build folder name. If omitted, auto-generates build_YYYYMMDD_HHMMSS.',
                'example' => 'v2-staging',
                'validation' => 'Max 100 chars, alphanumeric/dots/hyphens/underscores, must start with alphanumeric'
            ],
            'public' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name/path for public folder (default: public_template)',
                'example' => 'public or www/v1/public',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/dots/hyphens/underscores/forward-slash only. Must not name the build root itself ("." or an empty string) and must not contain, or sit inside, the secure folder.'
],
            'secure' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Custom name for secure folder - single level only (default: secure_template)',
                'example' => 'backend or app',
                'validation' => 'Max 255 chars, max 1 level (single folder name), alphanumeric/dots/hyphens/underscores only. Must not name the build root itself and must not contain, or sit inside, the public folder.'
],
            'space' => [
                'required' => false,
                'type' => 'string',
                'description' => 'PUBLIC_FOLDER_SPACE - subdirectory inside public folder for all public files (default: empty string)',
                'example' => '' or 'web or space/v1',
                'validation' => 'Max 255 chars, max 5 levels deep, alphanumeric/dots/hyphens/underscores/forward-slash only, empty allowed'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/build with body: {"public": "www/public", "secure": "app", "space": "web"}',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.success',
            'message' => 'Build completed successfully',
            'data' => [
                'build_name' => 'build_20251206_143022',
                'build_path' => 'qs_build/build_20251206_143022',
                'build_size_mb' => 2.24,
                'total_pages' => 7,
                'entry_point_written' => true,
                'entry_point_verified' => true,
                'project_name' => 'myproject',
                'download_with' => 'downloadBuild'
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_type' => 'Parameter must be a string',
            '400.validation.invalid_format' => 'Invalid path format (check max depth and allowed characters)',
            '400.validation.shared_parent_folder' => 'The public and secure folders would overlap: one contains the other, they are the same folder, or they share a root directory. data.conflict names which (identical, secure_inside_public, public_inside_secure, shared_root).',
'409.conflict.already_exists' => 'This project already has a build - delete it first with deleteBuild',
            '409.conflict.operation_in_progress' => 'Another build is already running',
            '413.validation.size_limit_exceeded' => 'Build exceeds MAX_BUILD_SIZE_MB',
            '500.server.file_write_failed' => 'Failed to create build directory or copy files',
            '500.server.internal_error' => 'Build compilation failed, OR the finished build cannot serve requests and was discarded (data.problems names what is missing)',
            '404.file.not_found' => 'The project is mono-language and its default.json translation file is missing, so the build cannot resolve any text. The build is aborted and rolled back.',
            '500.server.directory_create_failed' => 'Failed to create build directory. Failed to create timestamped build folder. Failed to create directory. Failed to create the build\'s data directory.'
        ],
        'notes' => 'Compiles JSON templates to PHP using JsonToPhpCompiler and emits a self-contained single-project site: a front controller, the parameters it reads, an .htaccess that funnels requests into it, and the small runtime the compiled pages need. Before answering success the command checks that the finished build can actually serve - funnel target, parameters, project data, runtime, menu/footer, every route reachable at the path routing will compute, and the 404 - and DISCARDS the build with a 500 if any of that is missing. Output goes to qs_build/<name>/ inside the project - OUTSIDE its public/, so no URL reaches a build; downloadBuild is the only way to fetch one. RETENTION IS ONE BUILD PER PROJECT: a second build is refused rather than overwriting the first, so use deleteBuild between builds. A build that FAILS removes its own partial directory; if that removal also fails the leftover carries no build_manifest.json and getBuild reports it as incomplete. No ZIP is stored - downloadBuild archives the folder on demand. The "space" parameter controls PUBLIC_FOLDER_SPACE - when set (e.g., "web"), all public files go inside {public}/{space}/ creating access URL like http://site.com/web/, and the document root gets its own non-browsable .htaccess since the site is not there. Public and secure folders MUST NOT overlap: neither may contain the other, be the other, or share a root directory - otherwise a deployed build would serve its own secure folder over the web. The built site stores visitor state under the REAL project id, so it shares a browser-storage namespace with the same project served at /p/<projectId>/. Uses file locking to prevent concurrent builds.'
    ],
    
    'getBuild' => [
        'description' => 'Returns the build of this project - manifest data, size, file count and completeness. Takes no parameters: there is one build or none.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getBuild',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build details retrieved successfully',
            'data' => [
                'exists' => true,
                'name' => 'build_20251214_084504',
                'complete' => true,
                'created' => '2025-12-14T08:45:04+00:00',
                'public' => 'www',
                'secure' => 'backend',
                'size_mb' => 3.46,
                'file_count' => 49,
                'download_with' => 'downloadBuild',
                'contents' => ['LICENSE', 'README.txt', 'backend/', 'www/']
            ]
        ],
        'error_responses' => [
            '404.build.not_found' => 'This project has no build'
        ],
        'notes' => 'The complete field is the completeness marker - build_manifest.json is written last, so a build without one did not finish and should be removed with deleteBuild before building again. No download URL is returned because a build is not reachable by URL; downloadBuild streams it.'
    ],
    
    'deleteBuild' => [
        'description' => 'Deletes the build of this project. Takes no parameters: there is one build or none.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/deleteBuild with an empty body',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build deleted successfully',
            'data' => [
                'deleted_build' => 'build_20251214_084504',
                'was_complete' => true,
                'space_freed_bytes' => 3627418,
                'space_freed_mb' => 3.46
            ]
        ],
        'error_responses' => [
            '404.build.not_found' => 'This project has no build to delete',
            '500.server.file_delete_failed' => 'Failed to delete build'
        ],
        'notes' => 'This is what unblocks build, which refuses while a build exists rather than overwriting one. It also removes an INCOMPLETE build, so a partial whose own cleanup failed is recoverable without touching the filesystem. No longer answers 207: with a single directory to remove there is no partial outcome to report.'
    ],
    
    'deployBuild' => [
        'description' => 'Deploys this project\'s build to a target root directory. The build\'s public and secure folders are copied as subdirectories of the target path. Three independent gates stand in front of it and all three must pass: the installation must allow deploying at all (an operator decision, absent means no), the caller must hold the deploy permission, and the target must sit inside an allowed deploy root.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Build folder name to deploy. Retention is one build per project, so the command finds it on its own. Supplying the name asserts WHICH build you meant: a name that is not the current build is a 404 rather than a silent substitution.',
                'example' => 'build_20251214_084504',
                'validation' => 'Must match format build_YYYYMMDD_HHMMSS'
            ],
            'targetPath' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Absolute path to the root directory. The build\'s public and secure folders will be placed inside it. Defaults to SERVER_ROOT when omitted, which is the only target a default installation permits.',
                'example' => 'omit it, or a path inside an allowed deploy root',
                'default' => 'SERVER_ROOT',
                'validation' => 'Absolute, no path traversal (..), and inside SERVER_ROOT or a root listed in <secure>/management/config/deploy-roots.php. Anything else is refused 403 validation.security_violation.'
            ],
            'overwrite' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, overwrite existing files inside the build\'s own subtree. When false, returns the list of file conflicts instead. It does NOT reach paths outside that subtree and it answers none of the co-tenancy refusals below.',
                'example' => false,
                'validation' => 'Boolean true/false (default: false)'
            ],
            'confirmUpdate' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'The target already holds a deployment OF THIS PROJECT. Confirms updating it in place. This is the routine path for every deploy after the first, and the only control that answers 409 deploy.update_confirmation_required.',
                'example' => true,
                'validation' => 'Boolean true/false (default: false)'
            ],
            'replaceDeployment' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'The target\'s secure folder belongs to a DIFFERENT project. Overwrites what that deployment wrote. Answers 409 deploy.secure_folder_in_use and nothing else.',
                'example' => false,
                'validation' => 'Boolean true/false (default: false)'
            ],
            'adoptSecureFolder' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'The target\'s secure folder has contents and carries no QuickSite marker, so its owner is unknown. Writes into it anyway. Answers 409 deploy.secure_folder_unmarked and nothing else.',
                'example' => false,
                'validation' => 'Boolean true/false (default: false)'
            ],
            'acceptRouteCollisions' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Deploy even though one of the site\'s routes is already a directory at the target and would never be reachable. Answers 409 conflict.route_collision and nothing else.',
                'example' => false,
                'validation' => 'Boolean true/false (default: false)'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/deployBuild with an empty body (deploys the project\'s one build to SERVER_ROOT), or with body: {"confirmUpdate": true} to update a deployment already there',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Build deployed successfully',
            'data' => [
                'build' => 'build_20251214_084504',
                'target' => '<the target root>',
                'folders' => ['public' => 'www.mysite.com', 'secure' => 'secure', 'space' => 'web'],
                'deployed_paths' => ['public' => '<target>/www.mysite.com', 'secure' => '<target>/secure'],
                'public_deployment' => ['files_copied' => 28, 'directories_created' => 7],
                'secure_deployment' => ['files_copied' => 18, 'directories_created' => 6],
                'license_copied' => true,
                'overwrite_mode' => false,
                'files_overwritten' => 0,
                'ownership_marker' => ['written' => true, 'path' => '<target>/<secureFolder>/qs-deployment.json', 'updated_existing' => false],
                'nginx' => ['config_regenerated' => true, 'config_path' => '<secure>/nginx/dynamic_routes.conf', 'reload' => 'reloaded|pending|not_applicable', 'reload_note' => '...', 'root_serves_a_build' => false],
                'php_opcache' => ['files_invalidated' => 46, 'note' => '...'],
                'shared_paths_skipped' => 'Present only when the target held paths outside this build\'s subtree; they are never replaced.',
                'route_collisions' => 'Present only when acceptRouteCollisions=true was used and collisions existed.'
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_type' => 'targetPath is not a string',
            '400.validation.invalid_format' => 'Invalid build name format, or targetPath is not absolute',
            '400.validation.security_violation' => 'targetPath contains path traversal (..)',
            '403.deploy.disabled' => 'Deploying is disabled on this installation. Asked before any parameter is read, so a disabled install answers the same way whatever it is asked.',
            '403.validation.security_violation' => 'targetPath is outside SERVER_ROOT and every configured deploy root. This is what a default installation answers to any target but its own root.',
            '404.build.not_found' => 'This project has no build, or the name given is not the build that exists',
            '409.build.incomplete' => 'The build carries no manifest, so it did not finish, and is refused rather than deployed',
            '409.conflict.build_in_progress' => 'A build of this name is being written right now',
            '409.conflict.files_exist' => 'Files inside the build\'s own subtree would be overwritten (use overwrite=true). Returns a detailed conflict list.',
            '409.conflict.operation_in_progress' => 'Another deployment holds the lock',
            '409.conflict.route_collision' => 'A route of this site is already a directory at the target and would never be reachable (use acceptRouteCollisions=true)',
            '409.deploy.update_confirmation_required' => 'The target already holds a deployment of THIS project (use confirmUpdate=true). The routine path for any deploy after the first.',
            '409.deploy.secure_folder_in_use' => 'The target\'s secure folder belongs to a different project (use replaceDeployment=true)',
            '409.deploy.secure_folder_unmarked' => 'The target\'s secure folder has contents and no QuickSite marker, so its owner is unknown (use adoptSecureFolder=true)',
            '500.build.invalid_structure' => 'The build\'s manifest does not describe a deployable tree',
            '500.build.missing_folder' => 'The build is missing the public or secure folder its manifest names',
            '500.server.directory_create_failed' => 'Failed to create target directory',
            '500.server.permission_denied' => 'Target directory not writable',
            '500.deploy.copy_failed' => 'The copy failed part-way'
        ],
        'notes' => 'THREE GATES, all of which must pass: the installation must have deploying enabled (an operator decision made in <secure>/management/config/deploy.php; absent means no), the caller must hold the deploy permission, and the target must be SERVER_ROOT or a root the operator listed in <secure>/management/config/deploy-roots.php. A default installation therefore deploys to itself and nowhere else, and any other target is refused 403 — the target is NOT an arbitrary absolute path. The folder names come from the build manifest (set during build). The "space" field from the manifest is shown in the response — if it is empty, public files are placed at the public root level (not inside a subdirectory). CO-TENANCY: a build owns its own subtree and nothing else; outside it a deploy may create but never overwrite, and overwrite does not reach those paths. Nothing is ever deleted. Without overwrite=true, the command scans its own subtree for file conflicts and returns a detailed list. Uses file locking to prevent concurrent deployments. On success it writes a deployment marker into the target\'s secure folder recording which project, build and layout landed there — that is what lets the next deploy tell an update from a stranger. NGINX: you probably need to do nothing. This installation regenerates its own routing file after a deploy and attempts a reload, and the response says which of the three outcomes happened. The nginx_routes.conf shipped inside the build is for deploying onto a server that carries NO QuickSite installation; including it alongside an installation\'s own generated routing declares the same location twice, which is a duplicate-location emergency and nginx then refuses to start. Apache users (.htaccess) need no extra step.'
    ],
    
    'downloadBuild' => [
        'description' => 'Streams the build of this project as a ZIP archive. Takes no parameters: there is one build or none.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/downloadBuild',
        'success_response' => [
            'status' => 200,
            'code' => 'binary',
            'message' => 'Streams application/zip as an attachment named after the build. NOT a JSON envelope.',
            'data' => null
        ],
        'error_responses' => [
            '404.build.not_found' => 'This project has no build to download',
            '409.build.incomplete' => 'The build did not finish and cannot be downloaded',
            '500.server.file_write_failed' => 'Failed to create the download archive'
        ],
        'notes' => 'The ZIP is created ON DEMAND from the build folder, streamed, and removed - no archive is stored, so a download can never be stale against the build it claims to be. Errors still answer the usual JSON envelope; success answers bytes. A browser cannot fetch this with a plain link because the surface requires an Authorization header as well as the session cookie: fetch it with the header, then hand the blob to the user (the admin panel does this through QuickSiteAdmin.downloadFile).'
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
        'example_get' => 'GET /management/p/<projectId>/getCommandHistory?command=editStructure&status=success&limit=50',
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
                        'publisher' => ['user_id' => 'usr_a1b2c3...', 'token_name' => 'Your Name'],
                        'result' => ['status' => 'success', 'code' => 'operation.success'],
                        'duration_ms' => 45.2
                    ]
                ],
                'pagination' => ['page' => 1, 'limit' => 100, 'total' => 150, 'pages' => 2]
            ]
        ],
        'error_responses' => [
            '400.validation.invalid_date' => 'Invalid date format (expected YYYY-MM-DD)',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.'
        ],
        'notes' => 'PROJECT-SCOPED: returns the history of the project named by the URL marker (/management/p/<projectId>/getCommandHistory) and nothing else - there is no installation-wide view. Logs are stored in daily files under <secure>/logs/p/<projectId>/. By default returns last 7 days. The getCommandHistory command itself is not logged to prevent recursion. Commands that target no project (registration, login, project creation) are recorded separately in <secure>/logs/_global/, which no command reads. Request bodies are sanitized DENY-BY-DEFAULT: any key that looks like a credential (password/secret/token/credential/key/auth/authorization/signature/salt) has its value replaced with [redacted] at every depth, for every command including ones added later; the session commands (login/register/logoutSession) log no body at all; uploadAsset logs file metadata only and editStyles truncates stylesheets over 5KB.'
    ],

    'clearCommandHistory' => [
        'description' => 'Deletes command log files older than a specified date, for the project named by the URL marker only. Requires confirmation to execute.',
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
        'example_delete' => 'DELETE /management/p/<projectId>/clearCommandHistory with body: {"before": "2025-12-01", "confirm": true}',
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
            '400.validation.invalid_date' => 'Invalid date format or future date',
            '200.operation.preview' => 'Preview: Add "confirm": true to execute deletion.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.'
        ],
        'notes' => 'PROJECT-SCOPED: deletes only inside <secure>/logs/p/<projectId>/ for the project named by the URL marker, so clearing one project\'s history can never affect another\'s. Without confirm=true, returns a preview showing what would be deleted. A future "before" date is refused. Requires admin permission ON THAT PROJECT.'
    ],

    'editFavicon' => [
        'description' => 'Points the site favicon at an existing image in assets/images. Writes CONFIG[FAVICON_PATH]; copies nothing.',
        'method' => 'POST',
        'parameters' => [
            'imageName' => [
                'required' => true,
                'type' => 'string|null',
                'description' => 'Filename of an existing asset in assets/images. Pass null to clear the favicon and fall back to the site default.',
                'example' => 'logo.svg',
                'validation' => 'Must exist in assets/images/ and be a favicon-capable format: ico, png, svg, gif, jpg, jpeg, webp, avif'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/editFavicon with body: {"imageName": "logo.svg"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Favicon updated successfully',
            'data' => [
                'favicon_path' => '/assets/images/logo.svg',
                'previous_path' => '/assets/images/old.png',
                'source_image' => 'logo.svg',
                'changed' => true
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing imageName parameter',
            '400.validation.invalid_type' => 'imageName is not a string',
            '400.validation.invalid_format' => 'Invalid filename, or a format that cannot be a favicon',
            '400.validation.invalid_length' => 'Filename exceeds 100 characters',
            '404.file.not_found' => 'Named image does not exist in assets/images/',
            '500.server.file_write_failed' => 'Could not read or write the project config'
        ],
        'notes' => 'Stores a POINTER, not a copy: the chosen asset stays where it is and no favicon.png or favicon_backup_* file is written. The value is written with var_export and the filename is validated before the write, so it lands in config.php as data. Selecting a different asset REPLACES the pointer (exactly one favicon per site). Renaming the chosen asset follows the pointer; deleting it clears the pointer. Because a build copies config.php verbatim, the choice travels into a built site. File validity is uploadAsset\'s job and is not re-checked here.'
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
        'example_patch' => 'PATCH /management/p/<projectId>/editTitle with body: {"route": "home", "lang": "en", "title": "Home - My Site"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success.title_updated',
            'message' => 'Page title updated successfully',
            'data' => [
                'route' => 'home',
                'language' => 'en',
                'title' => 'Home - My Site',
                'translation_key' => 'page.titles.home'
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
            '500.server.internal_error' => 'Invalid JSON in translation file',
            '400.validation.unsupported_language' => 'Language is not supported.',
            '500.server.invalid_json' => 'Translation file contains invalid JSON.',
            '500.server.json_encode_failed' => 'Failed to encode translation data.'
        ],
        'notes' => 'Updates ONE language at a time for single route. Updates page.titles.{route} key in the specified language translation file. Creates nested page.titles object if it doesn\'t exist. Used by Page.php: $translator->translate("page.titles.{$route}"). Route must exist in ROUTES constant, and language must be in supported languages list.'
    ],
    
    'getRoutes' => [
        'description' => 'Returns all routes as both a nested structure and a flat list',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getRoutes',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Routes retrieved successfully',
            'data' => [
                'routes' => ['home' => [], 'guides' => ['getting-started' => []]],
                'flat_routes' => ['home', 'guides', 'guides/getting-started'],
                'count' => 3
            ]
        ],
        'error_responses' => [],
        'notes' => 'Returns both the nested route structure (routes) and a flat list of all route paths (flat_routes). Useful for validation before creating menu/footer links.'
    ],
    
    'getSiteMap' => [
        'description' => 'Generates a complete sitemap of all routes × all languages. Useful for SEO sitemap.txt generation and Dashboard insights.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getSiteMap/{format?}',
        'parameters' => [
            '{format}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Output format (URL segment)',
                'example' => 'json',
                'validation' => 'json|text',
                'default' => 'json'
            ],
            'baseUrl' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Absolute base URL to build the sitemap entries against. First in the base-URL chain: when set and valid it wins over the resolved public base. A value that does not parse as a URL is ignored rather than refused.',
                'example' => 'https://example.com',
                'validation' => 'Must parse as an absolute URL'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getSiteMap or GET /management/p/<projectId>/getSiteMap/text',
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
        'notes' => 'Read-only. Use format=json (default) for programmatic access and Dashboard. Use format=text to preview or download sitemap.txt for SEO. Persisting exclusions/custom URLs and publishing sitemap.txt are setSiteMapConfig (route.write). Coverage data includes translation key counts per language to help identify incomplete translations.'
    ],

    'setSiteMapConfig' => [
        'description' => 'Persists the sitemap configuration (routes excluded from the sitemap, extra custom URLs appended to it) and optionally publishes public/sitemap.txt. Split out of getSiteMap because both writes decide what the published sitemap contains, which is an authoring capability rather than a read.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/setSiteMapConfig',
        'parameters' => [
            'excludedRoutes' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Route names to keep OUT of the sitemap. Omit to leave the stored value unchanged.',
                'example' => ['legal', 'internal-preview']
            ],
            'customUrls' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Extra absolute URLs to append. Values that are not valid absolute URLs are dropped and reported in rejectedUrls. Omit to leave the stored value unchanged.',
                'example' => ['https://example.com/landing']
            ],
            'save' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Also write public/sitemap.txt from the current routes plus this configuration',
                'example' => true
            ],
            'baseUrl' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Base URL for the generated entries when save is true (defaults to the resolved public base)',
                'example' => 'https://example.com'
            ]
        ],
        'example_post' => 'POST /management/p/mysite/setSiteMapConfig with body: {"excludedRoutes":["legal"],"customUrls":["https://example.com/landing"],"save":true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Sitemap configuration saved and sitemap.txt published',
            'data' => [
                'project' => 'mysite',
                'excludedRoutes' => ['legal'],
                'customUrls' => ['https://example.com/landing'],
                'saved' => true,
                'published' => true,
                'path' => '/…/<secure>/projects/mysite/public/sitemap.txt',
                'urlCount' => 5
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'called without the /p/<projectId>/ marker',
            '400.validation.missing_field' => 'neither excludedRoutes nor customUrls was provided',
            '400.validation.invalid_type' => 'excludedRoutes or customUrls is not an array',
            '403.auth.forbidden' => 'the caller\'s role does not grant route.write (viewer cannot write sitemap data)',
            '500.server.file_write_failed' => 'could not persist sitemap-config.json',
            '500.operation.write_failed' => 'configuration saved, but sitemap.txt could not be generated or written'
        ],
        'notes' => 'Sending only one of excludedRoutes / customUrls merges onto the stored value rather than clearing the other. Requires route.write (editor and above) — getSiteMap remains readable by viewers but can no longer write anything.'
    ],

    'analyzeReachability' => [
        'description' => 'Analyzes route reachability via BFS from the home page. Follows internal links in page structures, menu, and footer to find orphan routes that are not reachable through any navigation path.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/analyzeReachability',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/analyzeReachability',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'All routes are reachable from home | N orphan route(s) found',
            'data' => [
                'total_routes' => 6,
                'reachable_count' => 5,
                'orphan_count' => 1,
                'reachable' => ['docs', 'get-started', 'guides', 'home', 'terms'],
                'orphans' => ['privacy'],
                'graph' => [
                    'home' => ['docs', 'get-started', 'guides', 'terms'],
                    'privacy' => ['home', 'terms']
                ],
                'global_links' => [
                    'menu' => ['home', 'docs', 'get-started', 'guides'],
                    'footer' => ['terms', 'privacy']
                ]
            ]
        ],
        'error_responses' => [],
        'notes' => 'Performs a BFS (breadth-first search) starting from the home route. Each route\'s outgoing links are computed from: (1) internal hrefs in the page JSON structure, (2) menu links (if menu is visible on that route per route-layout.json), (3) footer links (if footer is visible). The graph field shows the full adjacency list. Routes in orphans[] have no navigation path from home.'
    ],
    
    'getStructure' => [
        'description' => 'Retrieves the JSON structure for a page, menu, footer, or component. Supports node identifiers for targeted retrieval.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getStructure/{type}/{name?}/{option?}',
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
        'example_get' => 'GET /management/p/<projectId>/getStructure/page/home, GET /management/p/<projectId>/getStructure/page/home/showIds, GET /management/p/<projectId>/getStructure/page/home/summary, GET /management/p/<projectId>/getStructure/page/home/0.0.2',
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
            '500.server.file_write_failed' => 'Failed to read structure file',
            '400.validation.invalid_length' => 'The type parameter is longer than the longest allowed type name, or the name parameter exceeds 200 characters.',
            '400.validation.invalid_type' => 'The type parameter is not a string, or the name parameter is neither a string nor a number. An integer or float name is accepted and used as its string form.',
            '400.validation.invalid_value' => 'The type parameter is not one of menu, footer, page, component.',
            '500.server.internal_error' => 'Invalid JSON in structure file.'
        ],
        'notes' => 'Node identifiers use 0-indexed dot notation: "0.2.1" = root\'s 1st child → 3rd child → 2nd child. Use /summary to see structure overview with nodeIds. Use specific nodeId to retrieve just that node. **Component vs Page structure**: Pages/menu/footer have an ARRAY root where "0", "1", "2" are root elements. Components have a single OBJECT root where the root itself is accessed via empty string "" and its children are "0", "1", "2". This affects all node operations (addNode, editNode, deleteNode, moveNode).'
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
        'example_patch' => 'PATCH /management/p/<projectId>/editStructure — full: {"type": "page", "name": "home", "structure": [...]}. Targeted: {"type": "page", "name": "home", "nodeId": "0.2", "structure": {...}}. Delete: {"type": "page", "name": "home", "nodeId": "0.2", "action": "delete"}',
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
            '400.validation.invalid_format' => 'Invalid type, nodeId format, or structure format. Also returned when A component reference in the structure is not a bare component name. It must start with a letter, then letters, digits, hyphens and underscores, up to 64 characters - never a path. errors[0].expected states the rule.',
'400.validation.blocked_tag' => 'The structure holds something the renderer would refuse: a blocked or non-allowlisted tag, an attribute NAME outside [letters, digits, underscore, colon, hyphen], a raw on* handler (use {{call:...}} syntax), or a disallowed URL scheme (only http, https, mailto, tel). The message names the offender.',
            '400.operation.failed' => 'Node operation failed (e.g., node not found)',
            '404.route.not_found' => 'Page does not exist',
            '404.file.not_found' => 'Structure file not found',
            '500.server.file_write_failed' => 'Failed to write structure file',
            '500.server.internal_error' => 'Failed to encode structure to JSON',
            '400.operation.denied' => 'Cannot delete component: used by other components.',
            '400.validation.invalid_length' => 'The type parameter is longer than the longest allowed type name, or the name parameter exceeds 200 characters.',
            '400.validation.invalid_type' => 'The structure parameter is not an object or array of objects, the type parameter is not a string, or the name parameter is neither a string nor a number. An integer or float name is accepted and used as its string form.',
            '400.validation.invalid_value' => 'The type parameter is not one of menu, footer, page, component; or, in targeted mode, action is not one of update, delete, insertBefore, insertAfter. The allowed set is echoed in errors[].allowed.',
            '400.validation.reserved_attribute' => 'A node carries an attribute starting with "data-qs-", which is reserved for QuickSite. Use a different prefix such as "data-custom-" or "data-app-". The offending attribute and its node are named in errors[].',
            '400.validation.reserved_key' => 'Reserved admin-namespace prefix (quicksite_ / quicksite- / qs_ / qs-). These are used by the admin panel and would collide with admin state. Pick a project-specific prefix.',
            '500.server.directory_create_failed' => 'Failed to create components directory.',
            '500.server.file_delete_failed' => 'Failed to delete component file.',
            '500.server.file_read_failed' => 'Failed to read structure file.'
        ],
        'notes' => 'Two modes: (1) Full replacement - sends complete structure. (2) Targeted edit - use nodeId to modify single node. Use getStructure/page/name/showIds to see node identifiers first. Actions: update (replace), delete (remove), insertBefore/insertAfter (add sibling). Security: max 10,000 nodes, max 50 levels deep. **Component node paths**: For type=component, the root object is "" (empty) and children are "0", "1", etc. This differs from pages where root elements are "0", "1".'
    ],
    
    'getTranslation' => [
        'description' => 'Retrieves translations for a single language',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getTranslation/{lang}',
        'parameters' => [
            '{lang}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Language code (URL segment), or "default" for mono-language mode',
                'example' => 'en',
                'validation' => '2-3 lowercase letters, or literal "default"'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getTranslation/en',
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
            '500.server.file_write_failed' => 'Failed to read translation file',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter must be a string.',
            '500.server.internal_error' => 'Invalid JSON in translation file.'
        ],
        'notes' => 'Returns translations for a single language. Use language="default" in mono-language mode to access default.json.'
    ],
    
    'getTranslations' => [
        'description' => 'Retrieves translations for all languages (mode-aware)',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getTranslations',
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
            '404.file.not_found' => 'No translation files found',
            '500.server.internal_error' => 'Failed to load any translation files.'
        ],
        'notes' => 'In multilingual mode: returns all language files (en.json, fr.json, etc.). In mono-language mode: returns only default.json. Response includes multilingual_enabled flag.'
    ],
    
    'setTranslationKeys' => [
        'description' => 'Sets/updates specific translation keys (merge by default, or replace entire file)',
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
                'description' => 'Translation keys to add or update (existing keys preserved unless replace=true)',
                'example' => '{"menu": {"home": "Home"}, "footer": {"new_key": "value"}}',
                'validation' => 'Must be valid JSON object'
            ],
            'replace' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, replaces entire file instead of merging (useful for fresh start)',
                'example' => 'true',
                'default' => false,
                'validation' => 'Boolean value'
            ]
        ],
        'example_patch' => 'PATCH /management/p/<projectId>/setTranslationKeys with body: {"language": "en", "translations": {"home": {"title": "New Title"}}}',
        'example_replace' => 'PATCH /management/p/<projectId>/setTranslationKeys with body: {"language": "en", "replace": true, "translations": {"site": {"name": "My Site"}}}',
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
            '500.server.file_write_failed' => 'Failed to write translation file',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters. Translation data too large (max 5MB).',
            '400.validation.invalid_type' => 'The language parameter must be a string. The translations parameter must be an object/array.',
            '500.server.internal_error' => 'The translation file could not be written for a reason other than a failed write or invalid JSON; the underlying reason is returned in the message.'
        ],
        'notes' => 'SAFE by default: Merges with existing translations. Use language="default" in mono-language mode to edit default.json. New keys are added, existing keys are updated, other keys are preserved. Set replace=true to completely replace the file (used by fresh-start workflow).'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteTranslationKeys with body: {"language": "en", "keys": ["home.old_key", "deprecated_section"]}',
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
            '500.server.file_write_failed' => 'Failed to write translation file',
            '400.validation.invalid_format' => 'Language code contains invalid characters. Invalid language code format.',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter is not a string, the keys parameter is not an array, or one of its entries is not a string. The offending index is named in errors[].',
            '500.server.file_read_failed' => 'Failed to read translation file.',
            '500.server.internal_error' => 'Failed to parse translation file. Failed to encode translations to JSON.'
        ],
        'notes' => 'Supports dot notation for nested keys. Use language="default" in mono-language mode. Empty parent objects are automatically cleaned up after deletion.'
    ],
    
    'getLangList' => [
        'description' => 'Returns list of configured languages and multilingual settings',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getLangList',
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
        'error_responses' => [
            '500.server.internal_error' => 'Configuration not loaded.'
        ],
        'notes' => 'Returns configuration from config.php. Useful for UI language selectors and checking current mode.'
    ],

    'getLanguageList' => [
        'description' => 'Returns the master list of languages a project can add. This is the fixed engine catalogue, not the project\'s configuration — for the languages a project has actually enabled, use getLangList.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getLanguageList',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getLanguageList',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Language list retrieved successfully',
            'data' => [
                'languages' => [
                    ['code' => 'en', 'name' => 'English'],
                    ['code' => 'fr', 'name' => 'Français'],
                    ['code' => 'es', 'name' => 'Español'],
                    '... 39 entries in total'
                ]
            ]
        ],
        'error_responses' => [],
        'notes' => 'The catalogue holds 39 languages and is the same on every installation — it is compiled into the engine, not read from project data, so nothing a project does changes it. Each entry is an object with a "code" (the ISO 639 code passed to addLang) and a "name" (the language\'s own endonym, e.g. "Français", "日本語"). Intended for populating an "add a language" picker; pair it with getLangList to show which of the 39 a project already has.'
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
        'example_patch' => 'PATCH /management/p/<projectId>/setMultilingual with body: {"enabled": true}',
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
            '500.server.file_write_failed' => 'Failed to update config.php or translation files',
            '500.server.internal_error' => 'Failed to create config lock file. Failed to acquire config lock. Failed to read configuration file.'
        ],
        'notes' => 'To enable multilingual: 1) Use addLang to add languages first, 2) Then use setMultilingual(enabled=true). When switching modes, translations are synced: mono→multi copies default.json keys to default language file, multi→mono copies default language to default.json.'
    ],
    
    'checkStructureMulti' => [
        'description' => 'Scans all structures (pages, menu, footer, components) for lang-specific content. Use before switching to mono-language mode.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/checkStructureMulti',
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
                'description' => 'Language code (ISO 639-1). Can use "lang" as shorthand, or "language" — all three spellings are accepted, first non-empty wins in the order code, lang, language.',
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
        'example_post' => 'POST /management/p/<projectId>/addLang with body: {"lang": "fr"} or {"code": "es", "name": "Español"}',
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
            '500.server.file_write_failed' => 'Failed to update config or create translation file',
            '500.file.not_found' => 'Configuration file not found.',
            '500.server.internal_error' => 'Failed to create config lock file. Failed to acquire config lock. Failed to parse configuration file.'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteLang with body: {"code": "es"}',
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
            '500.server.file_write_failed' => 'Failed to update config',
            '500.server.internal_error' => 'Failed to parse configuration file.'
        ],
        'notes' => 'Only available when MULTILINGUAL_SUPPORT = true. Cannot delete default language or last remaining language.'
    ],

    'cleanOrphanTranslations' => [
        'description' => 'Deletes translation files for languages no longer declared in LANGUAGES_SUPPORTED. Preserves default.json. Used by fresh-start to keep disk in sync with config.',
        'method' => 'DELETE',
        'parameters' => [
            'dry_run' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'If true, list orphans that WOULD be deleted without actually deleting them',
                'example' => 'true',
                'default' => false
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/cleanOrphanTranslations with body: {}',
        'example_dry_run' => 'DELETE /management/p/<projectId>/cleanOrphanTranslations with body: {"dry_run": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Deleted N orphan translation file(s)',
            'data' => [
                'cleaned' => ['fr'],
                'kept' => ['default', 'en'],
                'orphans_found' => 1,
                'dry_run' => false,
                'project' => 'test'
            ]
        ],
        'error_responses' => [
            '200.operation.partial_success' => 'Some files could not be deleted (errors array lists the failures); the rest succeeded',
            '500.server.internal_error' => 'PROJECT_PATH or CONFIG not loaded, or glob failure'
        ],
        'notes' => 'Orphan = a `<lang>.json` file present on disk whose base name is NOT in CONFIG[LANGUAGES_SUPPORTED] (excluding default.json, which is always preserved). Safe to run on any project; idempotent (running twice is a no-op).'
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
        'example_patch' => 'PATCH /management/p/<projectId>/setDefaultLang with body: {"lang": "fr"}',
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
            '500.server.file_write_failed' => 'Failed to update config file',
            '500.file.not_found' => 'Configuration file not found.',
            '500.server.internal_error' => 'Failed to parse configuration file.'
        ],
        'notes' => 'Only available when MULTILINGUAL_SUPPORT = true. The language must first be added using addLang. This affects the LANGUAGE_DEFAULT config value.'
    ],
    
    'getTranslationKeys' => [
        'description' => 'Scans all JSON structures and extracts required translation keys. Optionally includes translation status per key.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getTranslationKeys/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to check translation status (URL segment). If provided, returns translated/untranslated status for each key',
                'example' => 'en',
                'validation' => '2-10 characters (ISO 639 or BCP 47 locale code)'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getTranslationKeys (keys only) or GET /management/p/<projectId>/getTranslationKeys/fr (with translation status)',
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
            ],
            '400.validation.invalid_format' => 'Invalid language code format.',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter must be a string.'
        ],
        'notes' => 'Recursively scans all page JSONs, menu.json, and footer.json to extract textKey values. Ignores __RAW__ prefixed keys. When language is provided, also checks if each key has a non-empty translation (empty string = untranslated).'
    ],
    
    'validateTranslations' => [
        'description' => 'Validates translation completeness by comparing required keys with existing translations',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/validateTranslations/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to validate (URL segment). If omitted, validates all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/validateTranslations (all languages) or GET /management/p/<projectId>/validateTranslations/fr (specific)',
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
            '400.validation.invalid_format' => 'Invalid language code format',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter must be a string.'
        ],
        'notes' => 'Compares keys from getTranslationKeys with actual translation files. Shows missing keys per language and coverage percentage. Use this to identify incomplete translations before deployment.'
    ],
    
    'getUnusedTranslationKeys' => [
        'description' => 'Finds translation keys that exist in translation files but are not used in any structure',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getUnusedTranslationKeys/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to check (URL segment). If omitted, checks all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getUnusedTranslationKeys or GET /management/p/<projectId>/getUnusedTranslationKeys/en',
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
            '400.validation.invalid_format' => 'Invalid language code format',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter must be a string.'
        ],
        'notes' => 'Identifies orphaned translations not referenced by any page, menu, footer, or component. Useful for cleaning up after refactoring. Use deleteTranslationKeys to remove identified unused keys.'
    ],
    
    'analyzeTranslations' => [
        'description' => 'Complete translation health check - finds both missing AND unused keys',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/analyzeTranslations/{lang?}',
        'parameters' => [
            '{lang}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Language code to analyze (URL segment). If omitted, analyzes all languages',
                'example' => 'en',
                'validation' => '2-3 lowercase letters'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/analyzeTranslations or GET /management/p/<projectId>/analyzeTranslations/fr',
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
            '400.validation.invalid_format' => 'Invalid language code format',
            '400.validation.invalid_length' => 'Language code must not exceed 10 characters.',
            '400.validation.invalid_type' => 'The language parameter must be a string.'
        ],
        'notes' => 'Combines validateTranslations + getUnusedTranslationKeys in one call. Returns health status: healthy, has_unused, incomplete, needs_attention, or critical. Ideal for CI/CD pipelines and dashboard views.'
    ],
    
    'uploadAsset' => [
        'description' => 'Uploads a file to the assets folder with validation and automatic naming. Category is auto-detected from file extension. Supports multipart file upload or HTTPS URL download.',
        'method' => 'POST',
        'content_type' => 'multipart/form-data or application/json',
        'parameters' => [
            'file' => [
                'required' => false,
                'type' => 'file',
                'description' => 'File to upload (in multipart form data). Takes priority over url.',
                'validation' => 'See size and type limits below'
            ],
            'url' => [
                'required' => false,
                'type' => 'string',
                'description' => 'HTTPS URL to download the file from. Used if no file is uploaded.',
                'example' => 'https://example.com/image.png',
                'validation' => 'Must be HTTPS. Max 2048 characters.'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Description of the asset for AI context',
                'example' => 'Main company logo',
                'validation' => 'Max 500 characters',
                'ui_type' => 'textarea'
            ],
            'alt' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Alt text for images (accessibility)',
                'example' => 'Company logo',
                'validation' => 'Max 250 characters',
                'ui_type' => 'text'
            ]
        ],
        'size_limits' => [
            'images' => '5MB',
            'font' => '2MB',
            'audio' => '10MB',
            'videos' => '50MB'
        ],
        'allowed_types' => [
            'images' => 'JPEG, PNG, GIF, WebP, SVG',
            'font' => 'TTF, OTF, WOFF, WOFF2',
            'audio' => 'MP3, WAV, OGG',
            'videos' => 'MP4, WebM, OGV'
        ],
        'example_curl' => 'curl -F "file=@logo.png" "https://example.com/management/p/<projectId>/uploadAsset"',
        'example_curl_url' => 'curl -X POST -H "Content-Type: application/json" -d \'{"url":"https://example.com/photo.jpg"}\' "https://example.com/management/p/<projectId>/uploadAsset"',
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
            '400.asset.upload_failed' => 'File upload error',
            '400.asset.url_download_failed' => 'URL download failed (invalid URL, SSRF blocked, or HTTP error)',
            '400.asset.file_too_large' => 'File exceeds size limit',
            '400.asset.invalid_file_type' => 'MIME type not allowed',
            '400.asset.invalid_extension' => 'Unrecognized file extension',
            '500.asset.move_failed' => 'Failed to save file',
            '400.asset.invalid_upload' => 'File was not uploaded via HTTP POST.',
            '400.validation.forbidden_extension' => 'Executable file types are not allowed.',
            '400.validation.invalid_extension' => 'The file extension — on the uploaded file or on the url — is not one this install recognises, so the asset category cannot be determined.',
            '400.validation.invalid_file' => 'Invalid or missing filename. Could not determine filename from URL. URL must point to a file with a recognized extension. Invalid filename provided. File must have a valid extension. Could not determine file type. Filename cannot be empty. SVG file could not be sanitized — it may contain malformed XML.',
            '400.validation.invalid_format' => 'Filename contains only invalid characters.',
            '400.validation.invalid_length' => 'The description parameter must not exceed 500 characters. The alt parameter must not exceed 250 characters. URL must not exceed 2048 characters. Filename must not exceed 100 characters. Filename with uniqueness counter exceeds 100 characters. Please use a shorter filename.',
            '400.validation.invalid_mime_type' => 'File type not allowed for category.',
            '400.validation.invalid_type' => 'The description parameter must be a string. The alt parameter must be a string. The url parameter must be a string.',
            '400.validation.missing_field' => 'No file source provided. Upload a file or provide a url parameter.',
            '413.request.body_too_large' => 'The request body exceeded what this server accepts, so PHP discarded it before the command could read it.',
            '429.quota.rate_limited' => 'Too many uploads in the current period. The response carries retry_after (seconds) and the message states the limit in force.',
            '500.server.directory_not_found' => 'The asset directory for the resolved category does not exist under the project. The category is named in the message.',
            '500.server.file_corrupted' => 'File upload failed: size mismatch (possible corruption).',
            '500.server.file_move_failed' => 'Failed to move file to destination.',
            '500.server.file_verification_failed' => 'File upload failed: file not found after move.',
            '500.server.permission_denied' => 'Target directory is not writable.',
            '500.server.too_many_duplicates' => 'Unable to generate unique filename after 1000 attempts.',
            '507.quota.storage_exceeded' => 'Storing this file would take the owner over their configured storage quota. Charged to the project owner, so the refusal is generic when the caller is not that owner.'
        ],
        'notes' => 'Category is auto-detected from file extension (no category parameter needed). Validates MIME type (actual content, not just extension). Sanitizes filename. Auto-renames if file exists (adds _1, _2, etc.). SVG files are sanitized to remove scripts. URL downloads require HTTPS and block private IPs. Either file or url must be provided.'
    ],
    
    'deleteAsset' => [
        'description' => 'Deletes one or more files from the assets folder. Category is auto-detected from file extension. Supports single or batch deletion.',
        'method' => 'DELETE',
        'parameters' => [
            'filename' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Single filename to delete (use filename OR filenames, not both)',
                'example' => 'logo.png',
                'validation' => 'Must exist in auto-detected category folder'
            ],
            'filenames' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Array of filenames to delete in batch (max 50)',
                'example' => '["logo.png", "banner.jpg", "icon.svg"]',
                'validation' => 'Each filename validated individually'
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteAsset {"filename": "logo.png"}',
        'example_batch' => 'DELETE /management/p/<projectId>/deleteAsset {"filenames": ["logo.png", "banner.jpg"]}',
        'success_response' => [
            'status' => 204,
            'code' => 'operation.success',
            'message' => 'File deleted successfully',
            'data' => [
                'filename' => 'logo.png (single mode)',
                'deleted' => '[{filename, category}, ...] (batch mode)',
                'failed' => '[{filename, error}, ...] (batch mode)'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing filename or filenames',
            '400.validation.invalid_extension' => 'Unrecognized file extension',
            '400.validation.invalid_params' => 'Both filename and filenames provided',
            '400.asset.invalid_filename' => 'Invalid filename (path traversal blocked)',
            '404.asset.not_found' => 'File not found',
            '500.asset.delete_failed' => 'Failed to delete file',
            '400.operation.failed' => 'No files were deleted (failed).',
            '400.validation.invalid_length' => 'Maximum 50 files per batch delete request.',
            '400.validation.invalid_type' => 'The filenames parameter must be an array of strings. The filename parameter must be a string.',
            '400.validation.invalid_value' => 'The filenames array must not be empty.',
            '400.validation.missing_field' => 'Neither filename nor filenames was supplied (single delete needs filename; bulk delete needs filenames).'
        ],
        'notes' => 'Category is auto-detected from file extension. Supports batch deletion with filenames array (max 50). In batch mode, partial success is possible — check deleted and failed arrays in the response.'
    ],
    
    'listAssets' => [
        'description' => 'Lists all files in assets folder, optionally filtered by category',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/listAssets/{category?}',
        'parameters' => [
            '{category}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Filter by category (URL segment, optional)',
                'example' => 'images',
                'validation' => 'If provided, must be one of: images, font, audio, videos'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/listAssets (all) or GET /management/p/<projectId>/listAssets/images (filtered)',
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
                'favicon' => 'logo.png',
                'favicon_path' => '/assets/images/logo.png',
                'total_categories' => 1,
                'total_files' => 2
            ]
        ],
        'error_responses' => [
            '400.asset.invalid_category' => 'Invalid category',
            '400.validation.invalid_length' => 'The category parameter is longer than the longest configured category name.',
            '400.validation.invalid_type' => 'The category parameter must be a string.',
            '400.validation.invalid_value' => 'The category is not one of the configured asset categories. The allowed set is echoed in errors[].allowed.'
        ],
        'notes' => 'Returns files sorted alphabetically. Excludes index.php files. Shows size in bytes and last modified timestamp. Includes metadata (description, alt, dimensions) when available. Also reports the site favicon: `favicon` is the bare filename when the pointer names an asset in this project (so a caller can match it against a listed file), and null otherwise; `favicon_path` is the raw stored value, which may be an absolute URL.'
    ],
    
    'editAsset' => [
        'description' => 'Edit an existing asset: rename it, update its metadata (description, alt text), or both. Category is auto-detected from file extension.',
        'method' => 'POST',
        'parameters' => [
            'filename' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Current filename of the asset to edit',
                'example' => 'logo.png',
                'validation' => 'Must exist in auto-detected category folder'
            ],
            'newFilename' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New filename (extension auto-appended if omitted, must match original if provided)',
                'example' => 'company-logo',
                'validation' => 'Max 100 chars, must not already exist'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Description of the asset for AI context. Send empty string to remove.',
                'example' => 'Company logo displayed in header',
                'validation' => 'Max 500 characters',
                'ui_type' => 'textarea'
            ],
            'alt' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Alt text for images (accessibility). Send empty string to remove.',
                'example' => 'Company logo',
                'validation' => 'Max 250 characters',
                'ui_type' => 'text'
            ],
            'starred' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Mark asset as starred/favorite for AI prompt inclusion. Accepts true/false or "true"/"false".',
                'example' => true
            ]
        ],
        'example_request' => 'POST /management/p/<projectId>/editAsset {"filename": "old-logo.png", "newFilename": "company-logo", "description": "Main company logo", "alt": "Company logo"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Asset updated successfully',
            'data' => [
                'category' => 'images',
                'filename' => 'company-logo.png',
                'path' => '/assets/images/company-logo.png',
                'metadata' => [
                    'description' => 'Main company logo',
                    'alt' => 'Company logo'
                ],
                'changes' => ['renamed', 'description', 'alt']
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing filename or no updates specified',
            '400.validation.invalid_type' => 'Parameter must be a string',
            '400.validation.invalid_extension' => 'Unrecognized file extension',
            '400.validation.invalid_value' => 'Extension mismatch or same name',
            '400.validation.invalid_format' => 'Invalid filename format or path traversal',
            '400.validation.invalid_length' => 'Filename, description, or alt text too long',
            '404.asset.not_found' => 'Asset file not found',
            '409.asset.already_exists' => 'Target filename already exists',
            '500.asset.rename_failed' => 'File system rename failed'
        ],
        'notes' => 'Category is auto-detected from file extension. At least one of newFilename, description, alt, or starred must be provided. If newFilename omits the extension, the original extension is auto-appended. If an extension is provided, it must match the original. Metadata is preserved when renaming. Send empty string for description/alt to remove that field. Starred assets are included in AI workflow prompts when the user enables the option.'
    ],
    
    'getStyles' => [
        'description' => 'Retrieves the content of the main SCSS/CSS file',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getStyles',
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
        'example_put' => 'PUT /management/p/<projectId>/editStyles with body: {"content": "body { margin: 0; }"} or {"css": "body { margin: 0; }"}',
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
            '500.server.file_write_failed' => 'Failed to read or write style file',
            '400.validation.invalid_length' => 'The content parameter is empty, or larger than 512 KB.',
            '400.validation.invalid_type' => 'The content parameter must be a string.'
        ],
        'notes' => 'Completely replaces style.css content. Response includes backup_content for manual rollback if needed. Max size: 2MB. File locking prevents concurrent writes.'
    ],
    
    // ==========================================================================
    // CSS VARIABLES & RULES MANAGEMENT
    // ==========================================================================
    
    'getRootVariables' => [
        'description' => 'Retrieves all CSS custom properties (variables) defined in the :root selector',
        'method' => 'GET',
        'parameters' => [
            'themeTarget' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Which theme scope to read. "light" reads :root; "dark" reads the [data-theme="dark"] block.',
                'example' => 'dark',
                'validation' => 'light|dark',
                'default' => 'light'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getRootVariables',
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
            '500.server.file_read_failed' => 'Failed to read style file',
            '400.validation.invalid_format' => 'themeTarget must be "light" or "dark".'
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
            ],
            'themeTarget' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Which theme scope to write. "light" writes :root; "dark" writes the [data-theme="dark"] block.',
                'example' => 'dark',
                'validation' => 'light|dark',
                'default' => 'light'
            ]
        ],
        'example_patch' => 'PATCH /management/p/<projectId>/setRootVariables with body: {"variables": {"--color-primary": "#ff6600"}}',
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
            '500.server.file_write_failed' => 'Failed to write style file',
            '400.validation.invalid_css' => 'Invalid CSS value detected. Variable names and values may not contain "{" or "}".',
            '500.server.lock_failed' => 'Could not acquire file lock.',
            '500.server.operation_failed' => 'An unexpected failure while writing the variables; the stylesheet lock is released first. The exception message is returned.'
        ],
        'notes' => 'Adds new variables or updates existing ones. Security validated against CSS injection. File locking prevents concurrent writes. Creates :root block if not exists.'
    ],

    'setThemeMode' => [
        'description' => 'Configures the project\'s dark-mode support: whether theme switching is available at all, which theme a visitor gets by default, and whether the site offers a visitor-facing toggle. All three parameters are optional and only the ones supplied are written.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/setThemeMode',
        'parameters' => [
            'enabled' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Whether theme mode is available at all. Writes THEME_MODE_ENABLED.',
                'example' => true,
                'validation' => 'A JSON boolean, or the strings "true" / "false". Any other value — including 1 and 0 — is refused.'
            ],
            'default' => [
                'required' => false,
                'type' => 'string',
                'description' => 'The theme a visitor gets before choosing one. Writes THEME_DEFAULT.',
                'example' => 'dark',
                'validation' => 'Trimmed, then must be exactly "light", "dark" or "system".'
            ],
            'userToggle' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Whether the site renders a visitor-facing theme switch. Writes THEME_USER_TOGGLE_ENABLED.',
                'example' => true,
                'validation' => 'A JSON boolean, or the strings "true" / "false". Any other value — including 1 and 0 — is refused.'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setThemeMode with body: {"enabled": true, "default": "dark", "userToggle": true} or {"default": "system"} to change one setting only',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Theme mode updated successfully',
            'data' => [
                'changes' => [
                    'THEME_MODE_ENABLED' => true,
                    'THEME_DEFAULT' => 'dark',
                    'THEME_USER_TOGGLE_ENABLED' => true
                ],
                'current' => [
                    'THEME_MODE_ENABLED' => true,
                    'THEME_DEFAULT' => 'dark',
                    'THEME_USER_TOGGLE_ENABLED' => true
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'None of enabled, default or userToggle was supplied — at least one is needed.',
            '400.validation.invalid_type' => 'enabled or userToggle was neither a boolean nor the string "true"/"false".',
            '400.validation.invalid_format' => 'default was not "light", "dark" or "system".',
            '500.server.internal_error' => 'The project config lock could not be created or acquired, or the configuration file could not be read back.',
            '500.server.file_write_failed' => 'The updated configuration could not be written.'
        ],
        'notes' => 'data.changes lists only the settings this call wrote; data.current reports all three afterwards, so a partial update still shows the resulting state. The write takes an exclusive lock on the project configuration and re-reads it inside the lock, so concurrent calls that each set a different setting do not overwrite one another.'
    ],
    
    'listStyleRules' => [
        'description' => 'Lists all CSS selectors in the stylesheet, organized by global and media query scopes',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listStyleRules',
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
        'url_structure' => '/management/p/<projectId>/getStyleRule/{selector} or /management/p/<projectId>/getStyleRule/{selector}/{mediaQuery}',
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
        'example_get' => 'GET /management/p/<projectId>/getStyleRule/.btn-primary or GET /management/p/<projectId>/getStyleRule/.hero/(max-width%3A%20768px)',
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
            '404.selector.not_found' => 'Selector not found (in specified scope)',
            '500.server.file_read_failed' => 'Failed to read style file.'
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
        'example_post' => 'POST /management/p/<projectId>/setStyleRule with body: {"selector": ".btn-custom", "styles": {"background": "#007bff", "color": "white"}}',
        'example_remove' => 'PATCH /management/p/<projectId>/setStyleRule with body: {"selector": ".btn-custom", "removeProperties": ["margin", "padding"]}',
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
            '500.server.file_write_failed' => 'Failed to write style file',
            '500.server.lock_failed' => 'Could not acquire file lock.',
            '500.server.operation_failed' => 'An unexpected failure while writing the rule; the stylesheet lock is released first. The exception message is returned.'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteStyleRule with body: {"selector": ".unused-class"}',
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
            '500.server.file_write_failed' => 'Failed to write style file',
            '400.validation.invalid_format' => 'Selector cannot be empty.',
            '500.server.lock_failed' => 'Could not acquire file lock.',
            '500.server.operation_failed' => 'An unexpected failure while removing the rule; the stylesheet lock is released first. The exception message is returned.'
        ],
        'notes' => 'Permanently removes the CSS rule. Use getStyleRule first to confirm selector exists. Cannot be undone.'
    ],
    
    'listKeyframes' => [
        'description' => 'Returns a lightweight list of all @keyframes animation names (without frame details)',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/listKeyframes',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listKeyframes',
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
            '404.file.not_found' => 'Style file not found',
            '500.server.file_read_failed' => 'Failed to read style file.'
        ],
        'notes' => 'Lightweight alternative to getKeyframes when you only need animation names. Use getKeyframes for full frame content.'
    ],
    
    'getAnimatedSelectors' => [
        'description' => 'Returns all CSS selectors using animations, grouped by animation name with orphan detection',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getAnimatedSelectors',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getAnimatedSelectors',
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
            '404.file.not_found' => 'Style file not found',
            '500.server.file_read_failed' => 'Failed to read style file.'
        ],
        'notes' => 'Useful for finding which elements use animations and detecting orphan animations (referenced but not defined). Checks both animation and animation-name properties.'
    ],
    
    'getKeyframes' => [
        'description' => 'Retrieves all @keyframes animations defined in the stylesheet, or a specific one by name',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getKeyframes/{name?}',
        'parameters' => [
            '{name}' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Animation name to retrieve (URL segment, optional)',
                'example' => 'fadeIn'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getKeyframes (all) or GET /management/p/<projectId>/getKeyframes/fadeIn (specific)',
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
            '500.server.file_read_failed' => 'Failed to read style file',
            '404.keyframe.not_found' => 'Keyframe animation not found.'
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
        'example_patch' => 'PATCH /management/p/<projectId>/setKeyframes with body: {"name": "bounce", "frames": {"0%, 100%": "transform: translateY(0);", "50%": "transform: translateY(-20px);"}}',
        'example_no_overwrite' => 'PATCH /management/p/<projectId>/setKeyframes with body: {"name": "newAnim", "frames": {...}, "allowOverwrite": false}',
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
            '500.server.file_write_failed' => 'Failed to write style file',
            '409.keyframe.already_exists' => 'Keyframe already exists. Set allowOverwrite: true to replace it.',
            '500.server.lock_failed' => 'Could not acquire file lock.',
            '500.server.operation_failed' => 'An unexpected failure while writing the keyframes; the stylesheet lock is released first. The exception message is returned.'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteKeyframes with body: {"name": "fadeIn"}',
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
            '500.server.file_write_failed' => 'Failed to write style file',
            '404.keyframe.not_found' => 'Keyframe animation not found.',
            '500.server.lock_failed' => 'Could not acquire file lock.',
            '500.server.operation_failed' => 'An unexpected failure while removing the keyframes; the stylesheet lock is released first. The exception message is returned.'
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
        ],
        'error_responses' => [
            '404.route.not_found' => 'Command documentation not found.'
        ]
    ],
    
    'login' => [
        'description' => 'Exchanges username + password for a SESSION. The response sets the session cookie and returns that session token; every later request sends both - the cookie as the credential, the token as Authorization: Bearer. PUBLIC + self-authenticating.',
        'method' => 'POST',
        'parameters' => [
            'username' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Login identifier (the private username in users.php - never shown to other users)',
                'example' => 'your-username'
            ],
            'password' => [
                'required' => true,
                'type' => 'string',
                'ui_type' => 'password',
                'description' => 'Plain password, verified against the password_hash of the user',
                'example' => '********'
            ],
            'remember' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Give the session cookie a lifetime so it survives a browser restart. Default false - the cookie dies with the browser session.',
                'example' => true
            ]
        ],
        'example_post' => 'POST /management/login with body: {"username": "your-username", "password": "********"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Logged in',
            'data' => [
                'token_type' => 'Bearer',
                'session_token' => 'a1b2c3... (64 hex characters)',
                'user' => [
                    'id' => 'usr_...',
                    'name' => 'Your Name',
                    'username' => 'your-username',
                    'selected_project' => 'quicksite'
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'username and password are required',
            '401.auth.invalid_credentials' => 'Invalid username or password (uniform for unknown username, wrong password, externally managed or disabled account - no account oracle)',
            '429.auth.throttled' => 'Too many failed attempts - retry_after gives the wait in seconds'
        ],
        'notes' => 'A command-line client needs a cookie jar (curl -c jar -b jar) as well as the Authorization header: the token authorizes nothing without the session cookie it belongs to, which is what keeps a cookie-authenticated API safe from cross-site request forgery. Session lifetimes are configured in auth.php (authentication.session: idle_ttl / remember_ttl). Users with password_hash null are externally managed and cannot log in here.'
    ],

    'logoutSession' => [
        'description' => 'Ends the calling session. With everywhere=true it also bumps the account session generation, which ends every OTHER session of this user on their next request. AUTHENTICATED.',
        'method' => 'POST',
        'parameters' => [
            'everywhere' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Also end this account\'s other sessions (other browsers, other devices)',
                'example' => true
            ]
        ],
        'example_post' => 'POST /management/logoutSession with body: {"everywhere": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Signed out',
            'data' => [
                'ended' => true,
                'other_sessions_ended' => false
            ]
        ],
        'error_responses' => [
            '401.auth.required' => 'Authentication required',
            '500.server.file_write_failed' => 'Could not end the account\'s other sessions'
        ],
        'notes' => 'Proving you may end a session is proving you hold it, so this is an ordinary authenticated command rather than a public one - there is no token to present separately. The admin panel logout ends the session the same way in-process, and its "log out everywhere" link is the same generation bump.'
    ],

    'register' => [
        'description' => 'Self-registration: creates a user account from a public name + a private username + password. PUBLIC + self-gating - the command enforces the auth.php registration.allow_self_registration flag server-side (default: DISABLED) plus flood controls (per-IP rate, install-wide hourly cap, absolute account cap). A duplicate username returns the SAME success response as a real creation (login identifiers are private - no account-existence oracle); sign in afterwards with the login command.',
        'method' => 'POST',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Public display name (max 200 characters - how other users identify you; must differ from the private username)',
                'example' => 'Your Name'
            ],
            'username' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Private login identifier - unique; 3-32 characters, lowercase letters/digits/dash/underscore',
                'example' => 'your-username'
            ],
            'password' => [
                'required' => true,
                'type' => 'string',
                'ui_type' => 'password',
                'description' => 'Plain password (minimum length from auth.php registration.min_password_length, default 12)',
                'example' => '************'
            ]
        ],
        'example_post' => 'POST /management/register with body: {"name": "Your Name", "username": "your-username", "password": "************"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Account registered - you can now sign in',
            'data' => [
                'registered' => true
            ]
        ],
        'error_responses' => [
            '403.auth.registration_disabled' => 'Self-registration is disabled on this installation (auth.php registration.allow_self_registration)',
            '403.auth.registration_closed' => 'Registration is closed - the account limit (registration.max_users) is reached',
            '403.auth.setup_required' => 'The installation has no accounts at all. Registration cannot create the first one - open /admin/ and use the first-run page, which requires the setup token written to <secure>/management/config/setup-token.txt',
            '429.auth.throttled' => 'Too many registration attempts - retry_after gives the wait in seconds (per-IP rate or install-wide hourly cap)',
            '400.validation.required' => 'name, username and password are required',
            '400.validation.invalid_format' => 'Invalid username (3-32 chars: lowercase letters, digits, dash, underscore); OR the public name equals the username (they must differ - the username is private); OR password shorter than the configured minimum (data.min_length)',
            '500.server.registration_failed' => 'Could not register the account'
        ],
        'notes' => 'No session and no user id are returned - the new account signs in through the login command. The success response is identical whether the account was created or the username already existed; if the username belonged to someone else, the subsequent login simply fails - pick another username and register again. Flood-control knobs live in auth.php authentication.registration (throttle.per_ip_per_minute, throttle.global_per_hour, max_users; 0 disables a limit). This command can never create the FIRST account on an install: while the user registry is empty the shared mint path requires the first-run setup token, which registration does not carry, so the answer is 403 auth.setup_required regardless of the allow_self_registration flag.'
    ],



    'listComponents' => [
        'description' => 'Lists all reusable JSON components with metadata. Shows available slots (placeholders), typed variables, and component dependencies.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listComponents',
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
                'directory' => '<secure>/templates/model/json/components/'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Slots are {{placeholder}} names (backwards compatibility). Variables array includes type detection: textKey for translatable content, param for URL/attribute values. System placeholders (__ prefix) are filtered out. Use editStructure with type="component" to create/update/delete components.'
    ],
    
    'getComponent' => [
        'description' => 'Gets a single component by name with full structure, variables, and preview data. Expands nested component references for rendering.',
        'method' => 'GET',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Component name (filename without .json extension)'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getComponent?name=footer-link',
        'success_response' => [
            'status' => 200,
            'code' => 'components.get_success',
            'message' => 'Component loaded: footer-link',
            'data' => [
                'name' => 'footer-link',
                'structure' => ['tag' => 'a', 'params' => ['href' => '{{href}}'], 'textKey' => '{{label}}'],
                'variables' => [
                    ['name' => 'label', 'type' => 'textKey'],
                    ['name' => 'href', 'type' => 'param']
                ],
                'uses_components' => [],
                'previewStructure' => '(included when nested components are expanded)',
                'translations' => ['en' => ['key' => 'value']]
            ]
        ],
        'error_responses' => [
            ['status' => 400, 'code' => 'components.name_required', 'when' => 'No name parameter provided'],
            ['status' => 400, 'code' => 'components.invalid_name', 'when' => 'Name contains invalid characters'],
            ['status' => 404, 'code' => 'components.not_found', 'when' => 'Component file does not exist'],
            ['status' => 422, 'code' => 'components.invalid_json', 'when' => 'Component file contains invalid JSON'],
            '400.components.invalid_name' => 'Invalid component name.',
            '400.components.name_required' => 'Component name is required.',
            '404.components.not_found' => 'Component not found.',
            '422.components.invalid_json' => 'The component file on disk is not valid JSON; the decoder message is returned.',
            '500.components.read_error' => 'Failed to read component file.'
        ],
        'notes' => 'Returns previewStructure only when nested component references exist. Translations are loaded from project translation files for all textKeys in the expanded structure.'
    ],
    
    'findComponentUsages' => [
        'description' => 'Finds all pages, menu, footer, and other components that use a specific component. Essential for safe delete/rename operations.',
        'method' => 'GET',
        'parameters' => [
            '{component}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Component name to search for (URL segment)',
                'example' => 'menu-card'
            ],
            'component' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Component name to search for, as a parameter instead of a URL segment. The URL segment wins when both are present.',
                'example' => 'menu-card'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/findComponentUsages/menu-card',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Component 'menu-card' is used in 5 location(s)",
            'data' => [
                'component' => 'menu-card',
                'is_used' => true,
                'can_delete' => true,
                'delete_warning' => 'Warning: component is used in pages/menu/footer',
                'total_usages' => 5,
                'used_in' => ['page:home', 'page:about', 'menu'],
                'usages' => [
                    'pages' => [
                        ['name' => 'home', 'type' => 'page', 'locations' => ['0.2', '0.3'], 'count' => 2],
                        ['name' => 'about', 'type' => 'page', 'locations' => ['0.1'], 'count' => 1]
                    ],
                    'menu' => ['type' => 'menu', 'locations' => ['0.0', '0.1'], 'count' => 2],
                    'footer' => null,
                    'components' => []
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.missing_parameter' => 'Component name not provided',
            '400.validation.invalid_format' => 'Component name is not a bare component name (letter first, then letters, digits, hyphens and underscores, max 64 - never a path)',
            '404.file.not_found' => 'Component does not exist'
],
        'notes' => 'can_delete is false only if other components use this component (would break them). Pages/menu/footer usage shows warning but allows delete. Use before deleteComponent to understand impact. Locations array contains node IDs where component is referenced.'
    ],
    
    'renameComponent' => [
        'description' => 'Renames a component and updates all references in pages, menu, footer, and other components.',
        'method' => 'POST',
        'parameters' => [
            'oldName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Current component name. Can use "from" as an alias.',
                'example' => 'menu-card',
                'alias' => 'from'
            ],
            'newName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New component name (letters, numbers, hyphens). Can use "to" as an alias.',
                'example' => 'nav-card',
                'alias' => 'to'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/renameComponent with body: {"oldName": "menu-card", "newName": "nav-card"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Component renamed from 'menu-card' to 'nav-card' (5 references updated)",
            'data' => [
                'oldName' => 'menu-card',
                'newName' => 'nav-card',
                'references_updated' => 5,
                'files_updated' => [
                    ['file' => 'pages/home.json', 'references' => 3],
                    ['file' => 'menu.json', 'references' => 2]
                ],
                'errors' => null
            ]
        ],
        'error_responses' => [
            '400.validation.missing_parameter' => 'oldName or newName not provided',
            '400.validation.invalid_format' => 'Invalid component name format',
            '404.file.not_found' => 'Source component does not exist',
            '409.file.already_exists' => 'Target component name already exists',
            '500.server.file_rename_failed' => 'Failed to rename component file.'
        ],
        'notes' => 'Renames file and updates all references atomically. Name must start with letter and contain only letters, numbers, hyphens. Use findComponentUsages first to preview impact.'
    ],
    
    'duplicateComponent' => [
        'description' => 'Creates a copy of an existing component with a new name. References are NOT updated - creates independent copy.',
        'method' => 'POST',
        'parameters' => [
            'source' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Source component name to copy. Can use "from" as an alias.',
                'example' => 'menu-card',
                'alias' => 'from'
            ],
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name for the new component. Can use "to" as an alias.',
                'example' => 'menu-card-v2',
                'alias' => 'to'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/duplicateComponent with body: {"source": "menu-card", "name": "menu-card-v2"}',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.created',
            'message' => "Component 'menu-card-v2' created as copy of 'menu-card'",
            'data' => [
                'source' => 'menu-card',
                'name' => 'menu-card-v2',
                'file' => 'components/menu-card-v2.json',
                'size' => 512
            ]
        ],
        'error_responses' => [
            '400.validation.missing_parameter' => 'source or name not provided',
            '400.validation.invalid_format' => 'Invalid component name format',
            '404.file.not_found' => 'Source component does not exist',
            '409.file.already_exists' => 'Target component name already exists',
            '500.server.directory_create_failed' => 'Failed to create components directory.',
            '500.server.file_read_failed' => 'Failed to read source component.',
            '500.server.file_write_failed' => 'Failed to create new component file.',
            '500.validation.invalid_json' => 'Source component contains invalid JSON.'
        ],
        'notes' => 'Creates independent copy. Existing pages using original component are NOT modified. Use to create variations of components.'
    ],
    
    'listPages' => [
        'description' => 'Lists all JSON page structures with metadata. Shows route status, components used, and translation keys.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listPages',
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
                'directory' => '<secure>/templates/model/json/pages/'
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
        'example_patch' => 'PATCH /management/p/<projectId>/moveNode with body: {"type": "page", "name": "home", "sourceNodeId": "0.2.1", "targetNodeId": "0.3", "position": "after"}',
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
            '404.resource.not_found' => 'Source or target node not found',
            '400.operation.failed' => 'The move could not be applied: the source node could not be removed, the target could no longer be found once the source was removed, or the insert failed. The structure file is left unchanged.',
            '400.validation.invalid_format' => 'Invalid sourceNodeId format. Use dot notation like \'0.2.1\'. Invalid targetNodeId format. Use dot notation like \'0.2.1\'. Structure too deeply nested (max 50 levels).',
            '404.file.not_found' => 'Structure file not found.',
            '404.node.not_found' => 'The sourceNodeId or the targetNodeId does not resolve to a node in the structure.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to encode structure to JSON.'
        ],
        'notes' => 'Atomic move operation with proper index adjustment. Use in Visual Editor drag & drop. Components are moved as single units. **Component node paths**: For type=component, children are "0", "1", etc. (root is "", not movable).'
    ],
    
    'deleteNode' => [
        'description' => 'Deletes a node and all its children from a structure, with automatic cleanup of associated translation keys.',
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
            ],
            'keepTranslationKeys' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Keep the translation keys the deleted node referenced. By default they are removed from every language along with the node.',
                'example' => 'true',
                'default' => false
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteNode with body: {"type": "page", "name": "home", "nodeId": "0.2.1"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node deleted successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'nodeId' => '0.2.1',
                'deletedNode' => '{tag, params, children info}',
                'file' => '/path/to/home.json',
                'translationKeysRemoved' => 3,
                'translationKeys' => ['home.item1', 'home.item2', 'home.item3']
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid type value',
            '404.resource.not_found' => 'Node not found',
            '400.operation.failed' => 'Failed to delete node.',
            '400.validation.invalid_format' => 'Invalid nodeId format. Use dot notation like \'0.2.1\'.',
            '404.file.not_found' => 'Structure file not found.',
            '404.node.not_found' => 'The nodeId does not resolve to a node in the structure.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to encode structure to JSON.'
        ],
        'notes' => 'Recursively deletes all children AND removes associated translation keys from all language files. Collects textKey and translatable attributes (alt, placeholder, title, aria-*) from deleted node and children. Use in Visual Editor with Del key. **Component node paths**: For type=component, children are "0", "1", etc. (root itself is "", but cannot be deleted).'
    ],
    
    'addNode' => [
        'description' => 'Adds a node to a structure. Two modes: TAG (default, nodeKind=tag) inserts an HTML tag element with its params; TEXT (nodeKind=text) inserts a bare text node — either a RAW literal or a translation key picked/created by the client. Validates mandatory params per tag.',
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
                'description' => 'Reference node ID for positioning (or "root" for the structure root)',
                'example' => '0.2'
            ],
            'position' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Where to insert: before, after, or inside (default after; forced inside when targetNodeId="root")',
                'example' => 'after',
                'default' => 'after'
            ],
            'nodeKind' => [
                'required' => false,
                'type' => 'string',
                'description' => 'What to add: "tag" (default — HTML tag element) or "text" (bare text node).',
                'example' => 'text',
                'allowed_values' => ['tag', 'text'],
                'default' => 'tag'
            ],
            'tag' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'HTML tag name. REQUIRED when nodeKind=tag (default); omit for nodeKind=text.',
                'example' => 'div'
            ],
            'params' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Tag attributes including mandatory ones (href for <a>, src/alt for <img>, etc.). Ignored for nodeKind=text.',
                'example' => '{"class": "card", "href": "/contact"}'
            ],
            'textKey' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'For nodeKind=tag: an OPTIONAL explicit translation key to attach as a text child of the new tag (the legacy auto-generated placeholder textKey was removed — tag elements now come in empty). For nodeKind=text + textRaw=false: REQUIRED — the translation key picked/created by the client (the client text-key picker calls setTranslationKeys to write the value, then passes the key here).',
                'example' => 'home.greeting'
            ],
            'textValue' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'For nodeKind=text + textRaw=true: the literal text. Stored as the new node\'s textKey = "__RAW__"+textValue (rendered verbatim, no translation lookup).',
                'example' => 'Hello world'
            ],
            'textRaw' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'For nodeKind=text: when true, textValue becomes a __RAW__ literal; when false, the client-provided textKey is inserted as-is.',
                'example' => true,
                'default' => false
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addNode — TAG mode: {"type":"page","name":"home","targetNodeId":"0.2","position":"after","tag":"a","params":{"href":"/contact","class":"btn"}}. TEXT RAW: {"type":"page","name":"home","targetNodeId":"0.2","position":"inside","nodeKind":"text","textRaw":true,"textValue":" — "}. TEXT key: {"type":"page","name":"home","targetNodeId":"0.2","position":"inside","nodeKind":"text","textKey":"home.greeting"}.',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node added successfully',
            'data' => [
                'newNodeId' => '0.3',
                'newNode' => ['tag' => 'a', 'params' => ['href' => '/contact']],
                'position' => 'after',
                'targetNodeId' => '0.2',
                'html' => '<a href="/contact" data-qs-node="0.3" ...>...</a>'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter (e.g., tag for nodeKind=tag; textKey for nodeKind=text + textRaw=false)',
            '400.validation.invalid_value' => 'Invalid tag, position, or nodeKind',
            '400.validation.missing_params' => 'Missing mandatory params (e.g., href for <a>)',
            '400.validation.unsafe_param' => 'A param the renderer would refuse: an attribute NAME outside [letters, digits, underscore, colon, hyphen]; a raw on* handler (use {{call:...}} syntax); or a disallowed URL scheme (only http, https, mailto, tel)',
            '400.operation.denied' => 'Cannot insert inside component node',
            '404.resource.not_found' => 'Target node not found',
            '400.operation.failed' => 'Failed to insert node.',
            '400.validation.blocked_tag' => 'Tag is blocked for security reasons.',
            '400.validation.invalid_format' => 'Invalid targetNodeId format. Use dot notation like \'0.2.1\' or \'root\'. Structure too deeply nested (max 50 levels).',
            '400.validation.reserved_attribute' => 'Cannot use reserved attribute. Attributes starting with \'data-qs-\' are reserved for QuickSite. Use a different prefix like \'data-custom-\' or \'data-app-\'.',
            '400.validation.reserved_key' => 'Reserved admin-namespace prefix (quicksite_ / quicksite- / qs_ / qs-). These are used by the admin panel and would collide with admin state. Pick a project-specific prefix.',
            '404.file.not_found' => 'Structure file not found.',
            '404.node.not_found' => 'The targetNodeId does not resolve to a node in the structure.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'The structure file on disk is not valid JSON; the decoder message is returned.'
        ],
        'notes' => 'TAG mode adds a tag element that comes in EMPTY by default; the legacy auto-generated placeholder textKey was removed (it was the cause of span-in-span nesting when authoring bound elements). Pass an explicit `textKey` to attach a translation-key text child at add-time, or leave empty and use the dedicated TEXT mode + the Text-mode inline editor (Text tool) afterwards. TEXT mode adds a bare `{textKey:...}` text node — set `textRaw=true` + `textValue` for a literal, or pass an explicit `textKey` (the visual editor\'s text-key picker handles key creation + translation write via setTranslationKeys before calling addNode). Position "inside" moves existing text children of the target into the new tag node; this move logic is skipped for TEXT inserts. For components, use addComponentToNode. **Component node paths**: For type=component, root is "" (empty) and children are "0", "1", etc. (differs from pages where root elements start at "0").'
    ],

    'addComplexElement' => [
        'description' => 'Insert a wizard-built subtree (form scaffold, select, list, field row, etc.) atomically — single command dispatches to the right builder by kind, splices the produced subtree under one file lock.',
        'method' => 'POST',
        'parameters' => [
            'kind' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Builder kind to dispatch to. Built-ins: field-row, form-scaffold, select, list. Lowercase + hyphens only.',
                'example' => 'list'
            ],
            'config' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Builder-specific config. Shape depends on kind (see Complex Element wizard docs).',
                'example' => '{"tag": "ul", "items": [{"labelKey": "menu.home"}, {"labelKey": "menu.about"}]}'
            ],
            'structType' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Structure type: menu, footer, page, or component',
                'example' => 'page'
            ],
            'pageName' => [
                'required' => 'conditional',
                'type' => 'string',
                'description' => 'Structure name (required for structType=page/component)',
                'example' => 'home'
            ],
            'targetNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Reference node ID for positioning (dot-notation like "0.2.1") or the literal "root" to splice at the top of the structure.',
                'example' => '0.2'
            ],
            'position' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Where to insert relative to targetNodeId: before, after, or inside. Default "after". Forced to "inside" when targetNodeId="root".',
                'default' => 'after',
                'example' => 'inside'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addComplexElement with body: {"kind": "list", "config": {"tag": "ul", "items": [{"labelKey": "menu.home"}, {"labelKey": "menu.about"}]}, "structType": "page", "pageName": "home", "targetNodeId": "0", "position": "inside"}',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.success',
            'message' => 'Complex element \'<kind>\' inserted',
            'data' => [
                'kind' => 'list',
                'newNodeId' => '0.0',
                'newNode' => '{"tag": "ul", "children": [...]}',
                'targetNodeId' => '0',
                'position' => 'inside',
                'structType' => 'page',
                'pageName' => 'home'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter (kind, config, structType, targetNodeId)',
            '400.validation.invalid_format' => 'Invalid kind format or invalid targetNodeId format, or the builder emitted a component reference that is not a bare component name (see editStructure).',
'400.validation.invalid_value' => 'Invalid structType / position, or attempt to insert inside a component or text node',
            '400.complex_element.build_failed' => 'Builder rejected the config (e.g. list with no items, select with duplicate option values)',
            '404.complex_element.unknown_kind' => 'No builder registered for the requested kind (response includes availableKinds list)',
            '404.route.not_found' => 'Target page does not exist',
            '404.node.not_found' => 'Target node not found at the given dot path',
            '500.complex_element.build_failed' => 'Builder threw unexpectedly or returned a malformed node',
            '400.operation.failed' => 'Failed to insert subtree.',
            '400.validation.blocked_tag' => 'Builder emitted tag, which is not allowed (security restriction).',
            '400.validation.reserved_key' => 'Reserved admin-namespace prefix (quicksite_ / quicksite- / qs_ / qs-). These are used by the admin panel and would collide with admin state. Pick a project-specific prefix.',
            '404.file.not_found' => 'Structure file not found.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'Builder failed unexpectedly. See server log. Invalid JSON.'
        ],
        'notes' => 'After save, the emitted subtree is INDISTINGUISHABLE from a hand-built one — same JSON shape, same renderer, editable with the regular visual-editor tools. Wizard is build-time only; nothing at render time knows the element came from here. Builders live in <secure>/src/classes/complexElements/*.php as ComplexElementBuilder subclasses and are auto-discovered by the dispatcher. Drop a new builder file + a matching public/admin/.../contextual-complex/complex-<kind>.js wizard to add a kind — zero registration. Reuses addNode\'s insertion helper for non-root targets (same atomicity). For targetNodeId="root", detects page (list-shape root) vs component (object-shape root) and splices accordingly.'
    ],

    'duplicateNode' => [
        'description' => 'Duplicates a node with all its children, generating new unique translation keys to prevent collisions.',
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
                'description' => 'Node ID to duplicate (dot-notation path)',
                'example' => '0.2.1'
            ],
            'copyTranslations' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Copy translation values from source keys to new keys',
                'default' => true,
                'example' => 'true'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/duplicateNode with body: {"type": "page", "name": "home", "nodeId": "0.2.1"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node duplicated successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'sourceNodeId' => '0.2.1',
                'newNodeId' => '0.2.2',
                'translationKeysMapped' => 3,
                'keyMappings' => ['home.item1 => home.item4', 'home.item2 => home.item5'],
                'translationsCopied' => true,
                'html' => '<div>...rendered HTML...</div>'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing required parameter',
            '400.validation.invalid_value' => 'Invalid type value',
            '400.operation.denied' => 'Cannot duplicate component nodes (use at component definition level)',
            '404.resource.not_found' => 'Node not found',
            '400.operation.failed' => 'Component root has no children to duplicate. Failed to insert duplicated node.',
            '400.validation.invalid_format' => 'Invalid nodeId format. Use dot notation like \'0.2.1\'. Structure too deeply nested (max 50 levels).',
            '404.file.not_found' => 'Structure file not found.',
            '404.node.not_found' => 'The nodeId does not resolve to a node in the structure.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to encode structure to JSON.'
        ],
        'notes' => 'Creates a deep copy of the node and all children. Generates new unique translation keys for textKey and translatable attributes (alt, placeholder, title, aria-*). New keys follow pattern {prefix}.item{N+1}. Optionally copies translation values from source keys. Returns rendered HTML for live DOM update. Use in Visual Editor with D key shortcut. **Component node paths**: For type=component, children are "0", "1", etc. (root itself is "", but cannot be duplicated).'
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
        'example_post' => 'POST /management/p/<projectId>/editNode with body: {"type": "page", "name": "home", "nodeId": "0.2", "addParams": {"class": "highlight"}, "removeParams": ["data-temp"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Node updated successfully',
            'data' => [
                'type' => 'page',
                'name' => 'home',
                'nodeId' => '0.2',
                'file' => '/path/to/home.json',
                'html' => '<div class="highlight">...</div>',
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
            '400.validation.empty_value' => 'addParams contains keys with empty/null values — use removeParams to drop a key',
            '400.validation.reserved_key' => 'addParams contains a reserved admin-namespace storage key (qs_/qs-/quicksite_/quicksite-)',
            '400.validation.reserved_attribute' => 'addParams contains a reserved data-qs-* attribute (auto-managed by QuickSite)',
            '400.validation.unsafe_param' => 'A param the renderer would refuse: an attribute NAME outside [letters, digits, underscore, colon, hyphen]; a raw on* handler (use {{call:...}} syntax); or a disallowed URL scheme (only http, https, mailto, tel)',
            '400.operation.denied' => 'Cannot edit component node (use editComponentToNode)',
            '404.resource.not_found' => 'Node not found',
            '400.operation.failed' => 'Failed to edit node.',
            '400.validation.blocked_tag' => 'Tag is blocked for security reasons.',
            '400.validation.invalid_format' => 'Invalid nodeId format. Use dot notation like \'0.2.1\'. Structure too deeply nested (max 50 levels).',
            '404.file.not_found' => 'Structure file not found.',
            '404.node.not_found' => 'The nodeId does not resolve to a node in the structure.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.server.internal_error' => 'The structure file on disk is not valid JSON; the decoder message is returned.'
        ],
        'notes' => 'Does NOT edit translation values (use setTranslationKeys). Cannot edit component nodes or pure text nodes. After tag change, validates mandatory params are present. Returns rendered HTML for live DOM updates. **Component node paths**: For type=component, root is "" and children are "0", "1", etc. **addParams**: empty/null values are rejected — use removeParams to drop a key.'
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
            ],
            'params' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Per-instance attributes stamped on the inserted call site - a class, an id, aria-* and the like, or the binding attributes componentList mode reads. Keys must be non-empty strings; values may be string, boolean or numeric.',
                'example' => '{"class": "featured", "aria-label": "Primary navigation"}'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addComponentToNode with body: {"type": "page", "name": "home", "targetNodeId": "0.2", "position": "after", "component": "menu-card", "data": {"href": "/about"}}',
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
            '404.resource.not_found' => 'Target node not found',
            '400.operation.failed' => 'Failed to insert component node.',
            '400.validation.invalid_format' => 'Invalid targetNodeId format. Use dot notation like \'0.2.1\' or \'root\'. Invalid component name format. Structure too deeply nested (max 50 levels).',
            '400.validation.reserved_key' => 'Reserved admin-namespace prefix (quicksite_ / quicksite- / qs_ / qs-). These are used by the admin panel and would collide with admin state. Pick a project-specific prefix.',
            '404.error.notFound' => 'One of the four things the call needs does not exist: the component, the page, the structure file for the given type (and name), or the target node.',
            '500.error.fileRead' => 'Failed to read component file.',
            '500.error.fileWrite' => 'Failed to save structure file.',
            '500.error.invalidJson' => 'Component has invalid JSON. Structure file has invalid JSON.'
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
        'example_post' => 'POST /management/p/<projectId>/editComponentToNode with body: {"type": "page", "name": "home", "nodeId": "0.2", "data": {"href": "/contact"}}',
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
            '404.resource.not_found' => 'Node not found or not a component',
            '400.validation.failed' => 'Some variables could not be updated.',
            '400.validation.invalid_format' => 'Invalid nodeId format. Use dot notation like \'0.2.1\'. Structure too deeply nested (max 50 levels).',
            '400.validation.invalid_value' => 'The type parameter is not one of menu, footer, page, component; or the node at nodeId is a tag node rather than a component node (use editNode for those).',
            '404.error.notFound' => 'One of the four things the call needs does not exist: the page, the structure file for the given type (and name), the node, or the component definition it points at.',
            '500.error.fileWrite' => 'Failed to save structure file.',
            '500.error.internal' => 'Failed to update node.',
            '500.error.invalidJson' => 'Structure file has invalid JSON. Component has invalid JSON.'
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
        'example_post' => 'POST /management/p/<projectId>/createAlias with body: {"alias": "/old-home", "target": "/home", "type": "redirect"}',
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
            '409.conflict' => 'Alias conflicts with existing route or reserved path',
            '400.api.error.invalid_parameter' => 'One of: type is neither "redirect" nor "internal"; the alias is not alphanumerics, dashes, underscores and slashes; the alias points at itself; or the target is neither an existing route nor a page.',
            '400.api.error.invalid_request' => 'The request body is not valid JSON.',
            '400.api.error.missing_parameter' => 'Missing or invalid "alias" parameter. Missing or invalid "target" parameter.',
            '409.api.error.conflict' => 'Alias conflicts with existing route. Alias uses a reserved path. Alias already exists. Use deleteAlias first to modify it.',
            '500.api.error.write_failed' => 'Failed to save aliases.'
        ],
        'notes' => 'Aliases cannot conflict with existing routes or reserved paths (management, assets, build). Delete an alias first to modify its target. Stored per project in <secure>/projects/<projectId>/data/aliases.json.'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteAlias with body: {"alias": "/old-home"}',
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
            '404.not_found' => 'Alias not found',
            '400.api.error.invalid_request' => 'The request body is not valid JSON.',
            '400.api.error.missing_parameter' => 'Missing or invalid "alias" parameter.',
            '404.api.error.not_found' => 'Alias not found (no aliases exist). Alias not found.',
            '500.api.error.write_failed' => 'Failed to save aliases.'
        ],
        'notes' => 'Returns list of available aliases if the requested alias is not found.'
    ],
    
    'listAliases' => [
        'description' => 'Lists all URL redirect aliases with their targets and types.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listAliases',
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
        'error_responses' => [
            '500.api.error.read_failed' => 'Failed to parse aliases file.'
        ],
        'notes' => 'Returns empty array if no aliases defined. Use createAlias to add new aliases.'
    ],
    
    // ==========================================
    // PROJECT MANAGEMENT COMMANDS
    // ==========================================
    
    'listProjects' => [
        'description' => 'Lists the CALLER\'s projects with metadata (name, site name, routes, languages, size). The list is filtered to the projects the authenticated user is a member of (from each project\'s members.json) - there is no all-projects view.',
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
                        'path' => '<secure>/projects/quicksite',
                        'site_name' => 'QuickSite Demo',
                        'routes_count' => 5,
                        'pages_count' => 5,
                        'languages' => ['en', 'fr'],
                        'size' => '2.5 MB',
                        'size_bytes' => 2621440,
                        'my_role' => 'owner'
                    ]
                ],
                'count' => 1,
                'projects_path' => '<secure>/projects/'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Projects you are not a member of are simply absent from the list. my_role is your role on that project (members.json). A user with no memberships gets an empty list. No project is privileged, so the list is plain alphabetical.'
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
                'default' => 'Capitalized project name',
                'validation' => 'Max 200 chars; control characters stripped'
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
                'path' => '<secure>/projects/mysite',
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
            '409.resource.already_exists' => 'Project already exists',
            '500.server.directory_create_failed' => 'Failed to create project structure.',
            '500.server.file_write_failed' => 'Failed to create config.php. Failed to create routes.php. Failed to initialise project membership.'
        ],
        'notes' => 'Creates complete project structure: config.php, routes.php, templates/, translate/, etc. with basic home page template.'
    ],

    'cloneProject' => [
        'description' => 'Duplicates a project under a new name. Every file is copied except the backups folder; the clone gets its own site name and a fresh membership file with the caller as its sole owner.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/cloneProject',
        'parameters' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Name of the new project. Becomes the clone\'s project id and, title-cased with dashes and underscores turned into spaces, its site name.',
                'example' => 'my-site-copy',
                'validation' => 'Must start with a letter and contain only letters, digits, dashes and underscores; maximum 50 characters. The names admin, management, src, logs, config and projects are reserved.'
            ],
            'source' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Echo-check only. The project cloned is ALWAYS the one in the URL marker (/management/p/<projectId>/cloneProject); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'my-site'
            ],
            'switch_to' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Point the caller\'s own editing target at the clone once it exists. This moves nothing but the caller\'s per-user selection — no installation-wide pointer changes, and every project is still edited, previewed and served at /p/<projectId>/.',
                'example' => true,
                'default' => false,
                'validation' => 'Read with a boolean filter, so true/false, 1/0 and the strings "true"/"false"/"on"/"yes" are all accepted; anything else reads as false.'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/cloneProject with body: {"name": "my-site-copy"} or {"name": "my-site-copy", "switch_to": true}',
        'success_response' => [
            'status' => 201,
            'code' => 'resource.created',
            'message' => "Project 'my-site' cloned to 'my-site-copy' successfully",
            'data' => [
                'project' => 'my-site-copy',
                'source' => 'my-site',
                'path' => '<secure>/projects/my-site-copy',
                'site_name' => 'My site copy',
                'files_copied' => 25,
                'cloned' => true,
                'owner_user_id' => 'usr_0123456789abcdef0123456789abcdef',
                'switched_to' => false
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted — use /management/p/<projectId>/cloneProject.',
            '400.project.mismatch' => 'A source in the request disagreed with the project in the URL.',
            '400.validation.missing_field' => 'name is missing or empty.',
            '400.validation.invalid_format' => 'name does not match the allowed pattern, or the marker names a project id that is not a valid project name.',
            '400.validation.reserved_name' => 'name is one of the reserved names: admin, management, src, logs, config, projects.',
            '404.resource.not_found' => 'The source project folder does not exist.',
            '409.resource.already_exists' => 'A project with that name already exists. data.existing_path reports where.',
            '500.server.operation_failed' => 'The recursive copy failed; anything already copied is removed first.',
            '500.server.file_write_failed' => 'The clone\'s membership file could not be created. The whole clone is deleted rather than left ownerless.'
        ],
        'notes' => 'The clone does NOT inherit the source\'s roster: its membership file is written fresh with the caller as sole owner, no members, no pending invitations, visibility private and joining closed. A clone is an independent project — re-invite collaborators explicitly. The backups folder is skipped, so a clone starts with no backup history; everything else, including config, templates, translations, data and public assets, is copied. The clone\'s site name is derived from the new project name, and any site.name entry in its translation files is rewritten to match. data.files_copied counts the files present in the clone after the copy.'
    ],
    
    'deleteProject' => [
        'description' => 'Permanently deletes a project and all its files. Project-scoped and OWNER-ONLY: authorized against the URL marker project (/management/p/<projectId>/deleteProject) via that project\'s members.json. The deleted project is always the marker project; a body "name" that disagrees is refused.',
        'method' => 'DELETE',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional. If provided, MUST equal the project in the URL marker (defense-in-depth); the marker project is what gets deleted.'
            ],
            'confirm' => [
                'required' => true,
                'type' => 'boolean',
                'description' => 'Safety confirmation (must be true)',
                'validation' => 'Must be true to proceed'
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteProject with body: {"name": "oldsite", "confirm": true}',
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
            '400.project.mismatch' => 'The body name does not match the targeted (URL marker) project',
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/deleteProject',
            '400.validation.confirmation_required' => 'Must set confirm=true',
            '403.auth.forbidden' => 'Not the owner of this project (owner-only)',
            '404.resource.not_found' => 'Project not found',
            '500.server.delete_failed' => 'The tree could not be fully removed. data.partial says whether anything was deleted; data.survived names what could not be (project-relative paths); data.retained names what was kept deliberately so a retry stays possible; data.files_deleted / data.directories_deleted count what did go.',
            '400.validation.invalid_format' => 'Invalid project name.'
        ],
        'notes' => 'WARNING: This is permanent and cannot be undone. Use exportProject first to backup. Only the project OWNER may delete it. Membership cascade: every OTHER member and every ENGAGED pending party (invitees, self-requesters) gets a dismissable status "deleted" notice in their own cache (it appears in their membership inbox in the admin panel, where they clear it) so the deletion is never mistaken for a refusal or removal; the deleting owner\'s own entry is simply removed, and a sponsored not-yet-validated proposal target gets NOTHING (they were never told the project existed). The response reports the cascade under data.membership_cascade. PARTIAL DELETES: the removal continues past a file it cannot remove rather than stopping at the first, and reports what is left — data.survived (blocked) and data.retained (kept on purpose). config.php, routes.php and config/ go LAST and are skipped entirely when anything else failed: the first two are what the project context boots from and config/members.json is the permission gate, so removing them on the way past would leave a project that could be neither named nor authorized, and no retry could finish it. Release whatever blocked the delete and re-run; the command is safe to repeat.'
    ],

    'listMembers' => [
        'description' => 'Roster of the TARGET project: active members (rank-descending) plus the pending-invitations block. Users are referenced as {user_id, name} - the public display name and the opaque public id. The PRIVATE username never appears in membership output.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listMembers',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Members listed successfully',
            'data' => [
                'project' => 'prj_a1b2c3',
                'owner_user_id' => 'usr_...',
                'visibility' => 'private',
                'members' => [
                    ['user_id' => 'usr_...', 'name' => 'Sangio', 'role' => 'owner', 'rank' => 6, 'is_owner' => true],
                    ['user_id' => 'usr_...', 'name' => 'Alice', 'role' => 'editor', 'rank' => 2, 'is_owner' => false]
                ],
                'invitations' => [
                    ['user_id' => 'usr_...', 'name' => 'Bob', 'role' => 'developer', 'direction' => 'invite',
                     'invited_by' => ['user_id' => 'usr_...', 'name' => 'Sangio'], 'at' => '2026-07-16', 'note' => 'welcome']
                ],
                'member_count' => 2,
                'invitation_count' => 1
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/listMembers',
            '403.auth.forbidden' => 'Caller is not an admin/owner of this project (project.members category)'
        ],
        'notes' => 'Project-scoped on the URL marker; the body carries no project parameter. A pending entry grants NO access - it lives in a separate block that no permission check reads. Each invitations[] row carries direction: "invite" (offer awaiting the person\'s consent) or "request" (join request/proposal awaiting approveJoinRequest / denyJoinRequest; invited_by is then the asker - themselves for a self-request, the sponsoring member for a proposal). A converted proposal additionally carries sponsored_by. Names resolve live from the user registry (null if the account no longer exists).'
    ],

    'inviteMember' => [
        'description' => 'Offers project membership to an existing account (consent model): writes a pending invitation that only materializes when the invitee accepts it - where the inviter\'s authority is re-validated. Accepting is an account operation, not a command: the invitee answers from their membership inbox in the admin panel. Targeting is by user_id ONLY (the unique public identifier, looked up by public display name in the panel); the private username is never a membership target.',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target account\'s user id (from the panel\'s user lookup, or a shared id)',
                'example' => 'usr_a1b2c3d4e5f6...'
            ],
            'role' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Offered role - any built-in role below owner that the actor outranks (admin offers up to developer; owner offers up to admin)',
                'example' => 'editor'
            ],
            'note' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional message shown to the invitee (control characters stripped, 500 chars max)'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/inviteMember with body: {"user_id": "usr_...", "role": "editor", "note": "Come help with the docs"}',
        'success_response' => [
            'status' => 201,
            'code' => 'resource.created',
            'message' => 'Invitation sent',
            'data' => [
                'project' => 'prj_a1b2c3',
                'user_id' => 'usr_...',
                'name' => 'Bob',
                'role' => 'editor',
                'at' => '2026-07-16'
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id and role are required',
            '400.validation.invalid_format' => 'Unknown role',
            '400.member.role_not_assignable' => 'The owner role cannot be offered - use transferOwnership',
            '403.authz.insufficient_rank' => 'Offered role is not strictly below the actor\'s own rank (checked in-lock against the current members.json)',
            '404.user.not_found' => 'No account with this user id',
            '409.member.already_exists' => 'Target is already a member',
            '409.invitation.already_pending' => 'Target already has a pending invitation (cancel it first to change the offer) or a pending join request/proposal (approve or deny it instead)',
            '500.members.integrity' => 'members.json missing/unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the invitation',
            '400.validation.unencodable' => 'The note is not valid UTF-8 text.',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'The invitee sees the invitation in their membership inbox in the admin panel and accepts or declines it there - neither answer is a command. The rank check runs inside the members.json write lock, so a concurrent demotion of the actor cannot be outrun. The invitee\'s cache gains a pending_invite mirror entry (display only - never an access input).'
    ],

    'cancelInvitation' => [
        'description' => 'Withdraws a pending INVITATION (direction "invite") before the invitee answers. Plain removal on both sides - a withdrawn offer leaves no notice in the invitee\'s cache (it is not a decision against them). Join requests/proposals are NOT cancellable here: answer them with approveJoinRequest / denyJoinRequest (a cancel would be a silent deny dodging the mandatory refusal note).',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Invitee\'s user id'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/cancelInvitation with body: {"user_id": "usr_..."}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Invitation cancelled',
            'data' => ['project' => 'prj_a1b2c3', 'user_id' => 'usr_...', 'cancelled' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id is required',
            '403.authz.insufficient_rank' => 'The offered role is not strictly below the actor\'s rank (cancelling an offer = managing that role; no inviter carve-out)',
            '404.invitation.not_found' => 'No pending INVITATION for this user (a direction "request" entry answers here too - use approve/denyJoinRequest for those)',
            '500.members.integrity' => 'members.json missing/unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the cancellation',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Any admin/owner outranking the offered role may cancel - not just the original inviter (an owner can always clean up an admin\'s invitations; an admin cannot touch an owner-sent admin offer). Invites only: requests and proposals go through the adjudication lane.'
    ],

    'changeMemberRole' => [
        'description' => 'Changes an existing member\'s role. The actor must outrank BOTH the member\'s current role AND the new role (an admin can neither touch another admin nor promote anyone to admin; the owner manages everything below owner). The owner\'s role is immutable here - transferOwnership is the only door.',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target member\'s user id'
            ],
            'role' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New role (below owner, below the actor)',
                'example' => 'designer'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/changeMemberRole with body: {"user_id": "usr_...", "role": "designer"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Member role updated',
            'data' => [
                'project' => 'prj_a1b2c3',
                'user_id' => 'usr_...',
                'role' => 'designer',
                'previous_role' => 'editor',
                'role_changed' => true
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id and role are required',
            '400.validation.invalid_format' => 'Unknown role',
            '400.member.role_not_assignable' => 'The owner role cannot be assigned - use transferOwnership',
            '400.member.owner_immutable' => 'The owner\'s role only changes through transferOwnership',
            '400.member.cannot_target_self' => 'You cannot change your own role',
            '403.authz.insufficient_rank' => 'Current or new role is not strictly below the actor\'s rank',
            '404.member.not_found' => 'This user is not a member',
            '500.members.integrity' => 'members.json missing/unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the change',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Same-role no-op returns 200 with role_changed=false and writes nothing. No cache touch: the users.php mirror is roleless (the role is authoritative in members.json only).'
    ],

    'removeMember' => [
        'description' => 'Removes a member from the project (rank rule: strictly below the actor). The removed user keeps a dismissable "removed" notice in their own cache - with the optional note as the reason - so the removal is visible to them (other-initiated terminations leave a notice; self-initiated exits do not).',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target member\'s user id'
            ],
            'note' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional reason shown to the removed user (control characters stripped, 500 chars max)'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/removeMember with body: {"user_id": "usr_...", "note": "project wrapped up"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Member removed',
            'data' => ['project' => 'prj_a1b2c3', 'user_id' => 'usr_...', 'removed' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id is required',
            '400.member.owner_immutable' => 'The owner cannot be removed - transfer ownership first',
            '400.member.cannot_target_self' => 'Leaving a project is an account operation, not a command - do it from your memberships page in the admin panel',
            '403.authz.insufficient_rank' => 'Target\'s role is not strictly below the actor\'s rank',
            '404.member.not_found' => 'This user is not a member',
            '500.members.integrity' => 'members.json missing/unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the removal',
            '400.validation.unencodable' => 'The note is not valid UTF-8 text.',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Removal is effective immediately (the next request re-reads members.json). The removed user\'s sessions stay valid for their OTHER projects - membership, not authentication, is what was revoked.'
    ],

    'transferOwnership' => [
        'description' => 'Rotates project ownership to an EXISTING member (owner-only). One atomic members.json write: owner field -> new owner, new owner\'s role -> owner, old owner -> old_owner_role (default admin). Transfer is a role rotation, never an implicit add - invite + accept first.',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New owner\'s user id - must already be a member AND still resolve in the user registry at transfer time'
            ],
            'confirm' => [
                'required' => true,
                'type' => 'boolean',
                'description' => 'Safety confirmation (must be true)',
                'validation' => 'Must be true to proceed'
            ],
            'old_owner_role' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Role the departing owner keeps (any built-in role below owner)',
                'default' => 'admin'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/transferOwnership with body: {"user_id": "usr_...", "confirm": true, "old_owner_role": "developer"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Ownership transferred',
            'data' => [
                'project' => 'prj_a1b2c3',
                'new_owner' => ['user_id' => 'usr_...', 'name' => 'Alice'],
                'old_owner' => ['user_id' => 'usr_...', 'name' => 'Sangio'],
                'old_owner_role' => 'developer',
                'transferred' => true
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id is required',
            '400.validation.confirmation_required' => 'Must set confirm=true',
            '400.validation.invalid_format' => 'Unknown old_owner_role',
            '400.member.role_not_assignable' => 'old_owner_role must be below owner',
            '400.member.not_a_member' => 'The new owner must already be a member - invite them first',
            '400.member.cannot_target_self' => 'You already own this project',
            '403.auth.forbidden' => 'Caller is not the owner (project.ownership category)',
            '404.user.not_found' => 'The target no longer resolves in the user registry',
            '500.members.integrity' => 'The owner field and the owner role disagree - surfaced, never silently repaired',
            '500.server.file_write_failed' => 'Could not persist the rotation',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.',
            '403.authz.insufficient_rank' => 'Only the current owner can transfer ownership.'
        ],
        'notes' => 'The rotation happens inside one write lock with a fresh read and an invariant backstop (exactly one owner; owner field matches the owner role) - there is no read-back-reverse pass; the atomic temp+rename swap IS the integrity guarantee. No cache touch (both parties remain members).'
    ],









    'proposeMember' => [
        'description' => 'The sponsor lane: ANY member (viewer included) vouches an outsider for membership. The proposal is a pending direction:"request" entry with a MANDATORY note (the vouch) and needs admin/owner validation (approveJoinRequest) - the proposed person is NOT told anything until then (no inbox row, no cache entry), and nothing is granted.',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Proposed account\'s user id (from the panel\'s user lookup)'
            ],
            'role' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Suggested role - below owner AND no higher than your OWN rank (a viewer proposes viewers, an editor up to editors). The VALIDATOR must also outrank it at approval time.',
                'example' => 'editor'
            ],
            'note' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The vouch - why this person (control characters stripped, 500 chars max)'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/proposeMember with body: {"user_id": "usr_...", "role": "editor", "note": "My colleague, she runs our content"}',
        'success_response' => [
            'status' => 201,
            'code' => 'resource.created',
            'message' => 'Proposal recorded - a project admin or the owner must validate it before the person is invited',
            'data' => ['project' => 'prj_a1b2c3', 'user_id' => 'usr_...', 'name' => 'Carol', 'role' => 'editor', 'at' => '2026-07-17', 'proposed' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id, role and note are required',
            '400.validation.invalid_format' => 'Unknown role',
            '400.member.role_not_assignable' => 'The owner role cannot be proposed',
            '403.auth.forbidden' => 'Caller is not a member of this project (project.propose category)',
            '403.authz.insufficient_rank' => 'Caller left/lost membership between dispatch and the write (re-checked in-lock), OR the suggested role is higher than the caller\'s own rank',
            '404.user.not_found' => 'No account with this user id',
            '409.member.already_exists' => 'Already a member',
            '409.invitation.already_pending' => 'Something already pends for this user on this project',
            '500.members.integrity' => 'members.json unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the proposal',
            '400.validation.unencodable' => 'The note is not valid UTF-8 text.',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'The suggested role is capped at your OWN rank (a viewer proposes viewers, an editor up to editors - checked against your fresh in-lock rank); the validator re-checks canManageRole at approve. join_policy does NOT gate proposals (it gates only the self-service join-request door). On approval the proposal converts into a real invitation carried by the approver\'s rank, with you kept as sponsored_by; the person then accepts or declines like any invitee. Withdrawing your own proposal is an account operation, not a command - do it from your memberships page in the admin panel.'
    ],

    'approveJoinRequest' => [
        'description' => 'Authority\'s "yes" on a pending join request or proposal. Consent rule: membership materializes exactly when BOTH consents exist. A SELF-REQUEST (the person asked) joins immediately at the granted role. A SPONSORED PROPOSAL converts into a normal invitation (by = the approver, sponsor kept, note kept) - the person is engaged for the first time and still answers it themselves from their membership inbox. The approver may name the role to grant (optional; defaults to the stored role).',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Requester/proposed account\'s user id (see listMembers invitations with direction "request")'
            ],
            'role' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Role to grant - any built-in role below owner that the approver outranks. Defaults to the stored role (viewer for a self-request, the sponsor\'s suggestion for a proposal). Lets you approve straight to a role instead of approving to viewer then changeMemberRole.',
                'example' => 'editor'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/approveJoinRequest with body: {"user_id": "usr_...", "role": "editor"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Request approved - the requester is now a member',
            'data' => ['project' => 'prj_a1b2c3', 'user_id' => 'usr_...', 'name' => 'Carol', 'role' => 'editor', 'approved' => true, 'joined' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id is required',
            '400.validation.invalid_format' => 'Unknown role',
            '400.member.role_not_assignable' => 'The owner role cannot be granted - use transferOwnership',
            '403.authz.insufficient_rank' => 'The GRANTED role (override or stored) is not strictly below the approver\'s CURRENT rank (re-checked in-lock) - you cannot approve at or above your own rank',
            '404.request.not_found' => 'No pending join request/proposal for this user (invites are answered by the invitee, not here)',
            '409.request.void' => 'The requester\'s account or the proposal\'s sponsor is gone - the entry was pruned, nothing granted',
            '500.members.integrity' => 'members.json unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the approval',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Approve-time re-validation mirrors the invitee\'s own accept step: approver rank in-lock (against the GRANTED role); target account must still exist; a sponsored entry\'s sponsor must still be a MEMBER (any rank - a demoted sponsor stays valid, a removed one voids the proposal). The optional role is the authority changeMemberRole already carries, folded into the approval as one atomic step (supersedes the earlier "no role override at approve" rule). For a converted proposal the response carries converted_to_invitation=true and joined=false.'
    ],

    'denyJoinRequest' => [
        'description' => 'Authority\'s "no" on a pending join request or proposal. The note is MANDATORY - a refusal always carries its reason. A denied SELF-REQUEST leaves a dismissable "refused" notice (with the reason) in the requester\'s inbox and blocks re-requesting until dismissed. A denied SPONSORED proposal is simply removed - the proposed person was never engaged and is told nothing.',
        'method' => 'POST',
        'parameters' => [
            'user_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Requester/proposed account\'s user id'
            ],
            'note' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Why the request is refused (control characters stripped, 500 chars max)'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/denyJoinRequest with body: {"user_id": "usr_...", "note": "Team is full this quarter"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Join request denied',
            'data' => ['project' => 'prj_a1b2c3', 'user_id' => 'usr_...', 'denied' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'user_id and note are required',
            '403.authz.insufficient_rank' => 'The stored role is not strictly below the denier\'s rank - nobody may veto what they could not grant (an admin cannot kill a proposal-for-admin before the owner sees it)',
            '404.request.not_found' => 'No pending join request/proposal for this user',
            '500.members.integrity' => 'members.json unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the denial',
            '400.validation.unencodable' => 'The note is not valid UTF-8 text.',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'The refused notice inherits the privacy-correct display name (a private project\'s notice shows the project id, not the site name). The sponsor of a denied proposal gets no automatic notice (structural: their cache slot holds their membership) - the reason lives in this response and the command history.'
    ],

    'setJoinPolicy' => [
        'description' => 'Opens or closes the SELF-SERVICE join-request lane for the target project by setting join_policy in members.json. Asking to join is an account operation rather than a command - an outsider knocks from the admin panel - and this is the switch that decides whether the knock is answered. Default is closed. Gates ONLY the front door: member proposals (proposeMember) always reach the admin queue, and closing never purges requests already pending - they stay adjudicable.',
        'method' => 'POST',
        'parameters' => [
            'policy' => [
                'required' => true,
                'type' => 'string',
                'description' => "'open' or 'closed'",
                'example' => 'open'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setJoinPolicy with body: {"policy": "open"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Join policy set to 'open'",
            'data' => ['project' => 'prj_a1b2c3', 'join_policy' => 'open', 'previous' => 'closed', 'changed' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'policy is required',
            '400.validation.invalid_format' => "policy must be 'open' or 'closed'",
            '403.auth.forbidden' => 'Caller is not an admin/owner of this project (project.settings category)',
            '403.authz.insufficient_rank' => 'Caller lost the authority between dispatch and the write (re-checked in-lock)',
            '500.members.integrity' => 'members.json unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the policy',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Same-value calls are a no-op (200, changed=false). PRIVACY TRADE, stated plainly: a PRIVATE project with an open policy is knockable-by-id - any authenticated account that guesses/knows the id can send a request and thereby confirm the project exists (the response carries an advisory note when this combination becomes active). Closed private projects stay indistinguishable from nonexistent ones on the request lane.'
    ],

    'setProjectVisibility' => [
        'description' => 'Sets the TARGET project visibility (private|public) in members.json - the knob surface-B reads to decide whether the site is served to the PUBLIC internet or only to authenticated members. Default is private. OWNER-ONLY (project.visibility category): public exposure is the gravest decision a project carries, held at the delete/transfer tier, not the admin-tier project.settings that setJoinPolicy uses.',
        'method' => 'POST',
        'parameters' => [
            'visibility' => [
                'required' => true,
                'type' => 'string',
                'description' => "'private' or 'public'",
                'example' => 'public'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setProjectVisibility with body: {"visibility": "public"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Visibility set to 'public'",
            'data' => ['project' => 'prj_a1b2c3', 'visibility' => 'public', 'previous' => 'private', 'changed' => true]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '400.validation.missing_field' => 'visibility is required',
            '400.validation.invalid_format' => "visibility must be 'private' or 'public'",
            '403.auth.forbidden' => 'Caller is not the owner of this project (project.visibility category)',
            '403.authz.insufficient_rank' => 'Caller is no longer the owner between dispatch and the write (re-checked in-lock)',
            '500.members.integrity' => 'members.json unsound - refused',
            '500.server.file_write_failed' => 'Could not persist the visibility',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'Same-value calls are a no-op (200, changed=false). Owner-only. Making a project PRIVATE while its join_policy is open re-creates the knockable-by-id state (the response carries the same advisory note setJoinPolicy uses). Flipping to public dissolves that concern (existence is public by design). A visibility change never purges the pending request queue.'
    ],

    'reconcileMemberships' => [
        'description' => 'Heals every user\'s users.php membership cache for the TARGET project against its AUTHORITATIVE members.json. The cache is only a mirror (members.json is the sole grant authority); drift comes from a members.json hand-edit, a failed cascade cache-write, or pre-8.3a legacy entries. admin/owner (project.members).',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/reconcileMemberships',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "Reconciled membership cache for 'prj_a1b2c3' against members.json",
            'data' => [
                'project' => 'prj_a1b2c3',
                'counts' => [
                    'members' => 2, 'pending_invites' => 1, 'pending_requests' => 0,
                    'added' => 0, 'healed' => 1, 'pruned_stale' => 1, 'preserved_tombstones' => 3, 'total_changes' => 2
                ]
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted',
            '403.auth.forbidden' => 'Caller is not an admin/owner of this project (project.members category)',
            '500.members.unreadable' => 'members.json missing/corrupt - reconcile aborted (never prunes off a failed read)',
            '500.server.file_write_failed' => 'Could not persist the reconciled cache',
            '401.auth.required' => 'The command could not resolve the caller to a user. Over HTTP the dispatcher refuses an unauthenticated request first with 401 auth.unauthorized, so this is reached when the session stops resolving between those two checks (revoked, disabled, expired), or when the command is executed in-process with no authenticated caller.'
        ],
        'notes' => 'MERGE-PRESERVE rule (the point of the command): member/pending_invite/pending_request are DERIVABLE from members.json and rebuilt from it; but refused/removed/deleted are TOMBSTONES that live ONLY in the user cache (members.json keeps no record of a refusal, kick, or dead project). Reconcile PRESERVES any tombstone for a user the authority no longer lists, and prunes only STALE POSITIVES (a cache claiming member/pending the authority contradicts). Aborts on an unreadable authority rather than wipe real memberships.'
    ],

    'getProjectRoster' => [
        'description' => 'Reduced roster of the TARGET project for EVERY member rank: active members only, rank-descending - {user_id, name, role, rank, is_owner}. The pending invitations/requests block is deliberately absent (adjudication data stays admin/owner via listMembers). Exists so any member - viewer included - can see who is on the project with them.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getProjectRoster',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Roster listed successfully',
            'data' => [
                'project' => 'prj_a1b2c3',
                'members' => [
                    ['user_id' => 'usr_...', 'name' => 'Sangio', 'role' => 'owner', 'rank' => 6, 'is_owner' => true],
                    ['user_id' => 'usr_...', 'name' => 'Alice', 'role' => 'editor', 'rank' => 2, 'is_owner' => false]
                ],
                'member_count' => 2
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/getProjectRoster',
            '403.auth.forbidden' => 'Caller is not a member of this project (project.roster category)'
        ],
        'notes' => 'Project-scoped on the URL marker. Same member-row shape as listMembers, minus the queue: no invitations, no visibility/owner metadata. Admin/owner surfaces use listMembers instead. Users are {user_id, name} public references - the PRIVATE username never appears.'
    ],


    'exportProject' => [
        'description' => 'Exports the targeted project as a ZIP. Streams the archive by default; set save=true to store it in the project\'s exports folder and get a download URL instead.',
        'method' => 'GET',
        'parameters' => [
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional echo of the project in the URL marker. It does NOT select what gets exported - the marker does. A value that disagrees with the marker is refused with 400 project.mismatch. Accepts `project` as an alias.'
            ],
            'include_public' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Include the project\'s public folder (assets, style, build) in the archive',
                'default' => true
            ],
            'save' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'false (default): stream the ZIP bytes straight back as the response body - no JSON envelope, nothing stored. true: write the archive into <secure>/projects/<id>/exports/ and return the JSON envelope below with a download_url.',
                'default' => false
            ]
        ],
        'example_get' => 'GET /management/p/quicksite/exportProject (streams the ZIP) or GET /management/p/quicksite/exportProject?save=true&include_public=false (stores it, returns JSON)',
        'success_response' => [
            'status' => 200,
            'code' => 'resource.exported',
            'message' => "Project 'quicksite' exported and saved",
            'note' => 'This envelope is returned ONLY when save=true. With the default save=false the response body is the raw ZIP stream.',
            'data' => [
                'project' => 'quicksite',
                'filename' => 'quicksite_export_20250120_143022.zip',
                'path' => '<secure>/projects/quicksite/exports/quicksite_export_20250120_143022.zip',
                'size' => '2.1 MB',
                'size_bytes' => 2202009,
                'files_count' => 148,
                'directories_count' => 21,
                'original_size' => '6.4 MB',
                'download_url' => '/management/p/quicksite/downloadExport?file=quicksite_export_20250120_143022.zip',
                'expires' => '2025-01-21 14:30:22',
                'format' => 'v2.0-secure',
                'note' => 'Secure format: PHP files excluded, will be rebuilt on import'
            ]
        ],
        'error_responses' => [
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/exportProject',
            '400.project.mismatch' => 'A name/project in the request disagreed with the project in the URL',
            '400.validation.invalid_format' => 'The targeted project id is not a valid project name',
            '404.resource.not_found' => 'Project not found',
            '500.server.missing_extension' => 'PHP ZIP extension not available',
            '500.server.zip_create_failed' => 'Could not create the archive',
            '500.server.zip_error' => 'The archive failed to finalise',
            '500.server.move_failed' => 'save=true, but the archive could not be written into the exports folder'
        ],
        'notes' => 'Project-scoped: the exported project is the one in the URL marker; a name/project in the request is optional and must match. Export format v2.0-secure - PHP files are NOT included, they are rebuilt from JSON on import. Saved exports live in that project\'s own folder (<secure>/projects/<id>/exports/), are auto-cleaned (keeps the last 5), and are reachable only through their own project\'s marker via downloadExport.'
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
                'validation' => 'Must be a valid ZIP containing a project folder identified by config.json, routes.json, or templates/model/json/'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Override project name',
                'default' => 'Uses folder name from ZIP'
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
                'switched_to' => true,
                'security' => [
                    'skipped_unsafe_paths' => 0,
                    'skipped_unsafe' => [],
                    'skipped_disallowed_files' => 1,
                    'skipped_disallowed' => ['public/assets/logo.png (content is not a valid .png (signature mismatch))']
                ]
            ]
        ],
        'notes' => [
            'An archive is untrusted input. Entries are accepted against an extension ALLOWLIST and each one is checked so its content matches what its name claims (magic bytes for binary formats, valid JSON for .json, no PHP open tag for text, sanitisation for SVG).',
            'A refused entry is skipped and listed in security.skipped_disallowed with the reason; the rest of the archive still imports. Entries refusing to stay inside the project are listed in security.skipped_unsafe.',
            'Archive resource limits are enforced from the ZIP headers before anything is extracted: entry count, total and per-entry uncompressed size, and per-entry compression ratio. Exceeding any of them returns 413 and writes nothing.',
            'The permitted extensions and the limits can be changed by copying <secure>/management/config/import-policy.php.example to import-policy.php.',
            'PHP is never imported. config.php and routes.php are rebuilt from the archive JSON, and any members.json in the archive is discarded — the importer becomes the sole owner.',
            'A project id is unique across the installation and an import never reassigns one. If the id is taken the import refuses with 409 and writes nothing; there is no option to replace the existing project. Import under a different name, or delete the existing project first.',
            'Compatible with exports from exportProject.'
        ],
        'error_responses' => [
            '413.request.body_too_large' => 'The archive exceeded the server\'s post_max_size, so PHP discarded the request before the command ran. The response carries the real limit.',
            '413.validation.size_limit_exceeded' => 'Archive exceeds an entry-count, size or compression-ratio limit',
            '400.upload.failed' => 'File upload failed',
            '400.validation.invalid_zip' => 'Invalid or corrupted ZIP',
            '400.validation.invalid_structure' => 'ZIP missing required project files',
            '409.resource.already_exists' => 'A project with that id already exists',
            '400.validation.incomplete_project' => 'Imported project is incomplete.',
            '400.validation.invalid_format' => 'Invalid project name format.',
            '400.validation.invalid_type' => 'File must be a ZIP archive.',
            '400.validation.missing_field' => 'No archive was uploaded. Send the ZIP as multipart/form-data.',
            '400.validation.reserved_name' => 'Project name is reserved.',
            '429.quota.rate_limited' => 'Too many uploads in the current period. The response carries retry_after (seconds) and the message states the limit in force.',
            '500.server.directory_create_failed' => 'Failed to create project directory.',
            '500.server.extract_failed' => 'Failed to extract project files.',
            '500.server.file_write_failed' => 'Failed to initialise imported project membership.',
            '500.server.missing_extension' => 'ZIP extension not available.',
            '500.server.rebuild_failed' => 'Failed to rebuild PHP files from JSON.',
            '507.quota.storage_exceeded' => 'Importing this archive would take the owner over their configured storage quota. Charged to the project owner, so the refusal is generic when the caller is not that owner.'
        ]
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
        'example_get' => 'GET /management/p/quicksite/downloadExport?file=quicksite_export_20250120_143022.zip',
        'success_response' => [
            'status' => 200,
            'code' => 'binary',
            'message' => 'Streams application/zip as an attachment. NOT a JSON envelope.',
            'data' => null
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing file parameter',
            '400.validation.invalid_filename' => 'Invalid filename (path traversal blocked)',
            '404.resource.not_found' => 'Export file not found or expired',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.validation.invalid_type' => 'Only ZIP files can be downloaded.'
        ],
        'notes' => 'Export files expire after 24 hours. This command only serves archives that exportProject was asked to SAVE (save=true); for an immediate download call exportProject with no options - streaming is its default and stores nothing. A browser cannot fetch this with a plain link: the surface requires an Authorization header as well as the session cookie, so the archive has to be fetched with the header and handed to the user as a blob (the admin panel does this through QuickSiteAdmin.downloadFile).'
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
                'description' => 'Echo-check only. The project backed up is ALWAYS the one in the URL marker (/management/p/<projectId>/backupProject); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'quicksite'
            ],
            'max_backups' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Maximum backups to keep (default: 5, 0 = unlimited)',
                'example' => 5
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/backupProject',
        'example_get_with_params' => 'GET /management/p/<projectId>/backupProject?name=quicksite&max_backups=3',
        'success_response' => [
            'status' => 200,
            'message' => 'Backup created successfully: 2026-01-03_14-30-00',
            'data' => [
                'project' => 'quicksite',
                'backup' => [
                    'name' => '2026-01-03_14-30-00',
                    'path' => '<secure>/projects/quicksite/backups/2026-01-03_14-30-00',
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
        'error_responses' => [
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/backupProject',
            '400.project.mismatch' => 'A name in the request disagreed with the project in the URL',
            '404.project.not_found' => 'Project not found',
            '200.backup.created' => 'Backup created successfully.',
            '400.validation.invalid_format' => 'Invalid project name.',
            '500.backup.create_failed' => 'Failed to create backup directory.',
            '500.backup.folder_create_failed' => 'Failed to create backups directory.',
            '500.backup.no_files_copied' => 'Failed to create backup - no files copied.'
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
                'description' => 'Echo-check only. The project listed is ALWAYS the one in the URL marker (/management/p/<projectId>/listBackups); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'quicksite'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/listBackups',
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
        'error_responses' => [
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/listBackups',
            '400.project.mismatch' => 'A name in the request disagreed with the project in the URL',
            '404.project.not_found' => 'Project not found',
            '200.backup.list_empty' => 'No backups folder exists yet.',
            '200.backup.list_success' => 'The backup list, newest first. An empty list is still a 200 — no backups is not an error.',
            '400.validation.invalid_format' => 'Invalid project name.'
        ],
        'notes' => 'Backup types: "manual" (created via backupProject), "pre-restore" (auto-created before restore), "auto" (scheduled backups).'
    ],
    
    'restoreBackup' => [
        'description' => 'Restores a project from a backup, overwriting its current state. The pre-restore snapshot is OPT-IN (create_backup) and is NOT taken by default.',
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
                'description' => 'Echo-check only. The project restored is ALWAYS the one in the URL marker (/management/p/<projectId>/restoreBackup); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'quicksite'
            ],
            'create_backup' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Take a pre-restore snapshot before overwriting. The snapshot captures the project\'s CURRENT state (config.php, routes.php, templates, translate, data, public) into a new backup named pre-restore_<timestamp>, so the restore can be undone by restoring that snapshot. Default: false - no snapshot is taken and the current state is lost.',
                'example' => true
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/restoreBackup with body: {"backup": "2026-01-03_14-30-00", "create_backup": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'restore.success',
            'message' => 'Backup restored successfully: 2026-01-03_14-30-00',
            'data' => [
                'project' => 'quicksite',
                'restored_backup' => '2026-01-03_14-30-00',
                'pre_restore_backup' => 'pre-restore_2026-01-03_16-45-22',
                'restored_items' => ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'],
                'pre_restore_items' => ['config.php', 'routes.php', 'templates', 'translate', 'data', 'public'],
                'errors' => []
            ]
        ],
        'error_responses' => [
            '400.backup.name_required' => 'Backup name required',
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/restoreBackup',
            '400.project.mismatch' => 'The name in the body does not match the project in the URL marker',
            '400.validation.invalid_format' => 'Backup name or project name is not a valid identifier',
            '404.project.not_found' => 'Project not found',
            '404.backup.not_found' => 'Backup not found',
            '500.backup.prerestore_failed' => 'create_backup=true was requested but the pre-restore snapshot folder could not be created - nothing was overwritten',
            '500.restore.no_files_restored' => 'No file could be restored; data.errors lists why, data.pre_restore_backup names the snapshot if one was taken'
        ],
        'notes' => 'DESTRUCTIVE: config.php, routes.php, templates/, translate/, data/ and public/ are overwritten from the chosen backup. There is NO automatic safety net - pass create_backup=true to snapshot the current state first (data.pre_restore_backup then names it, and restoring that snapshot undoes this restore). Omit it and data.pre_restore_backup is null and the state being overwritten is not recoverable from within QuickSite. The admin panel asks before it restores and sends create_backup according to the answer; a direct API caller gets no such prompt.'
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
                'description' => 'Echo-check only. The project deleted from is ALWAYS the one in the URL marker (/management/p/<projectId>/deleteBackup); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'quicksite'
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteBackup?backup=2026-01-03_14-30-00',
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
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/deleteBackup',
            '400.validation.missing_field' => 'Backup name required',
            '400.validation.invalid_filename' => 'Invalid backup name (path traversal blocked)',
            '404.resource.not_found' => 'Backup not found',
            '200.backup.deleted' => 'Backup deleted successfully.',
            '400.backup.name_required' => 'Backup name is required.',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.validation.invalid_format' => 'Invalid backup name. Invalid project name.',
            '404.backup.not_found' => 'No backup with that id exists for this project.',
            '404.project.not_found' => 'The project in the URL marker has no backup directory.',
            '500.backup.delete_failed' => 'Failed to delete backup directory.'
        ]
    ],
    
    'getSizeInfo' => [
        'description' => 'Returns detailed storage size information for all folders in the project structure. Useful for monitoring disk usage and identifying what takes up space.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getSizeInfo',
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
                    'project' => [
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
        'notes' => 'Project-scoped: the report covers ONLY the project in the URL marker. secure.projects_detail holds that single project (name, size, file count, its backups); it does not enumerate other projects on the installation, and folder entries carry no absolute filesystem path. summary.project names the reported project (it is NOT the globally served main). Categories combine related folders: projects, backups, admin (public/admin+<secure>/admin), management (API system), core (src+config+logs). Used by dashboard storage overview widget.',
        'error_responses' => [
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.'
        ]
    ],
    
    'clearExports' => [
        'description' => 'Clears the exported project ZIP files belonging to the targeted project. Useful to free up disk space after exports have been downloaded.',
        'method' => 'POST',
        'parameters' => [
            'confirm' => [
                'required' => true,
                'type' => 'boolean',
                'description' => 'Safety confirmation. Without confirm=true the command refuses and deletes nothing.',
                'example' => true
            ],
            'project' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Redundant echo of the URL marker. It cannot select another project: a value that disagrees with the marker is refused. Present only as a filter on the filename prefix within the marker project\'s own exports folder.',
                'example' => 'quicksite'
            ]
        ],
        'example_post' => 'POST /management/p/quicksite/clearExports with body: {"confirm": true}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => '5 export file(s) deleted',
            'data' => [
                'deleted_count' => 5,
                'deleted_files' => ['quicksite_export_20260807_101500.zip'],
                'failed_count' => 0,
                'failed_files' => [],
                'freed_space' => '15.5 MB'
            ]
        ],
        'error_responses' => [
            '400.validation.confirmation_required' => 'confirm was not true; nothing was deleted',
            '400.validation.invalid_format' => 'The project filter is not a valid project name',
            '207.operation.partial_success' => 'Some archives were deleted and some could not be removed. data.failed_files names the survivors and errors lists them; the deletions that DID happen are real.',
            '500.server.delete_failed' => 'Every archive that was found failed to delete (deleted_count is 0)',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.'
        ],
        'notes' => 'Project-scoped: deletes .zip files only in the targeted project\'s own exports folder (<secure>/projects/<id>/exports/). It cannot reach another project\'s archives. Does not affect project data or backups. A run in which any unlink fails NEVER answers 200 — check the status/code, not just data.deleted_count.'
    ],

    // ==========================================
    // ROLE MANAGEMENT COMMANDS
    // ==========================================
    
    
    
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
            '403.auth.forbidden' => 'Requires superadmin (*) permission',
            '400.validation.invalid_commands' => 'Some commands do not exist.',
            '400.validation.invalid_length' => 'Description must be between 3 and 255 characters.',
            '400.validation.invalid_type' => 'Each command must be a string.',
            '400.validation.reserved_name' => 'Cannot use "*" as role name - it is reserved for superadmin.',
            '409.role.already_exists' => 'Role already exists.',
            '500.server.file_write_failed' => 'Failed to save role configuration.'
        ],
        'notes' => 'Roles are defined in <secure>/management/config/roles.php. Commands must be valid existing commands. The role set is fixed and is read from the admin panel, not from a command. The createRole/editRole/deleteRole commands are disabled (denied to every role) in the fixed-role model.'
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
            '403.auth.forbidden' => 'Requires superadmin (*) permission',
            '400.validation.invalid_commands' => 'Some commands do not exist.',
            '400.validation.invalid_length' => 'Description must be between 3 and 255 characters.',
            '400.validation.invalid_role' => 'Cannot edit "*" - it is not a role.',
            '400.validation.invalid_type' => 'The commands parameter is not an array, or one of its entries is not a string.',
            '403.role.builtin_commands_protected' => 'Cannot modify commands for builtin role.',
            '500.server.file_write_failed' => 'Failed to save role configuration.'
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
            '403.auth.forbidden' => 'Requires superadmin (*) permission',
            '400.validation.invalid_role' => 'Cannot delete "*" - it is not a role.',
            '403.role.builtin_protected' => 'Cannot delete builtin role.',
            '409.role.in_use' => 'Role is in use by token(s).',
            '500.server.file_write_failed' => 'Failed to update token configuration. Failed to save role configuration.'
        ],
        'notes' => 'Builtin roles (viewer, editor, designer, developer, admin) cannot be deleted. If tokens are using this role, deletion will fail unless force=true, which reassigns those tokens to the viewer role.'
    ],

    // JAVASCRIPT FUNCTIONS / INTERACTIONS
    // ==========================================
    
    'listJsFunctions' => [
        'description' => 'Lists all available QS.* JavaScript functions that can be used in {{call:...}} syntax for page interactions. Returns core built-in functions from qs.js.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listJsFunctions',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
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
                    ]
                ],
                'total' => 15,
                'core_count' => 15,
                'custom_count' => 0
            ]
        ],
        'error_responses' => [
            '500.server.error' => 'Failed to load JavaScript functions'
        ],
        'notes' => 'Returns 15 core functions built into the QS namespace (show, hide, toggle, toggleHide, addClass, removeClass, setValue, redirect, filter, scrollTo, focus, blur, fetch, renderList, toast). Use {{call:functionName:arg1,arg2}} syntax in structure params like onclick, oninput.'
    ],

    'listDataBindings' => [
        'description' => 'Lists the QuickSite-runtime `data-*` attribute catalog — what each attribute does, what value shape it expects, which attrs it pairs with. Consumed by the in-editor autocomplete in the Add Element wizard so users can discover which data-* attributes the runtime recognises (data-state-*, data-auth-*, data-storage-*, data-bind, data-error-for, data-qs-complex, …). Single source of truth lives in <secure>/src/functions/qsDataAttributeCatalog.php — mirrors the qsVerbCatalog.php pattern from beta.7.',
        'method' => 'GET',
        'parameters' => [],
        'url_segments' => [
            'all' => '(optional) Pass /all as the first URL segment to include editor-chrome entries (`internal: true`) like data-qs-textkey, data-qs-node, data-qs-struct — normally hidden from the user-facing picker.'
        ],
        'example_get' => 'GET /management/p/<projectId>/listDataBindings',
        'example_get_all' => 'GET /management/p/<projectId>/listDataBindings/all',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'N data-* attribute(s) in the catalog',
            'data' => [
                'entries' => [
                    [
                        'name' => 'data-state-show',
                        'description' => 'Toggle the standard `hidden` attribute on truthiness of a state-store field…',
                        'category' => 'state',
                        'valueShape' => 'store-field-ref',
                        'valuePlaceholder' => 'storeId.fieldName',
                        'tagsAllowed' => ['*'],
                        'docAnchor' => 'ADMIN_PANEL.md#96-state-stores',
                        'examplePayload' => '<button data-state-show="people.nextPage">Next</button>',
                        'since' => 'v1.0.0-beta.7'
                    ]
                ],
                'by_category' => '{state: [...], auth: [...], storage: [...], template: [...], form: [...], complex: [...]}',
                'count' => 19,
                'include_internal' => false,
                'names' => ['data-state-value', 'data-state-list', '…'],
                'categories' => ['state', 'auth', 'storage', 'template', 'form', 'complex'],
            ]
        ],
        'error_responses' => [
            '500.server.internal_error' => 'Catalog file missing or malformed'
        ],
        'notes' => 'Returns 19 user-facing data-* entries by default (state / auth / storage / template / form / complex categories). Pass /all to also include 5 editor-chrome entries (data-qs-textkey / -raw / -textonly / -node / -struct). Each entry carries `valueShape` to drive smart widget UI, optional `companion` for paired attrs (e.g. data-auth-show ↔ data-auth-source), and `docAnchor` linking to the deep-dive section in ADMIN_PANEL.md or ARCHITECTURE.md.'
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
        'example_get' => 'GET /management/p/<projectId>/listInteractions/page/home/hero%2Fcta-button',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
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
            '404.node.not_found' => 'Node not found in structure',
            '400.validation.invalid_value' => 'The structType parameter is not one of menu, footer, page, component.',
            '404.file.not_found' => 'The structure file for the requested page (or for the menu, footer or component) does not exist.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file.'
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
        'example_post' => 'POST /management/p/<projectId>/addInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "function": "show", "params": ["#contact-modal"]}',
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
            '404.node.not_found' => 'Node not found in structure',
            '201.operation.success' => 'Interaction added successfully.',
            '400.validation.invalid_format' => 'Invalid function name format.',
            '400.validation.invalid_value' => 'The structType parameter is not one of menu, footer, page, component; or event is not a valid HTML event attribute (UNIVERSAL_EVENTS plus the entry for the node tag in TAG_SPECIFIC_EVENTS).',
            '404.file.not_found' => 'The structure file for the requested page (or for the menu, footer or component) does not exist.',
            '404.route.not_found' => 'Page does not exist.',
            '422.node.invalid_target' => 'Interactions must be attached to a tag node, not a text node. Select the parent element (e.g. the button or link) and try again.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to save structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to update node.'
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
            ],
            'newEvent' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New event name to move this interaction to (optional, for changing the event)',
                'example' => 'oninput'
            ]
        ],
        'example_put' => 'PUT /management/p/<projectId>/editInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "index": 0, "function": "toggleHide", "params": ["#modal"]}',
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
            '404.interaction.not_found' => 'No interaction found at specified index',
            '200.operation.success' => 'Interaction updated successfully.',
            '400.validation.invalid_format' => 'Invalid function name format.',
            '400.validation.invalid_value' => 'The structType parameter is not one of menu, footer, page, component; index is not a non-negative integer; or event / newEvent is not a valid HTML event attribute.',
            '404.file.not_found' => 'The structure file for the requested page (or for the menu, footer or component) does not exist.',
            '404.route.not_found' => 'Page does not exist.',
            '422.node.invalid_target' => 'Interactions must be attached to a tag node, not a text node. Select the parent element and try again.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to save structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to update node.'
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
        'example_delete' => 'DELETE /management/p/<projectId>/deleteInteraction with body: {"structType": "page", "pageName": "home", "nodeId": "hero/cta-button", "event": "onclick", "index": 0}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
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
            '404.interaction.not_found' => 'No interaction found on this event',
            '400.validation.invalid_value' => 'The structType parameter is not one of menu, footer, page, component; or event is not a valid HTML event attribute.',
            '404.file.not_found' => 'The structure file for the requested page (or for the menu, footer or component) does not exist.',
            '404.route.not_found' => 'Page does not exist.',
            '500.server.file_read_failed' => 'Failed to read structure file.',
            '500.server.file_write_failed' => 'Failed to save structure file.',
            '500.server.internal_error' => 'Invalid JSON in structure file. Failed to update node.'
        ],
        'notes' => 'If index is omitted, ALL interactions on that event are removed (the event param is deleted entirely). If index is provided, only that specific interaction is removed and others are preserved.'
    ],

    'getPageEvents' => [
        'description' => 'Returns the page-level event interactions attached to one page — the document-level onload / onresize / onscroll handlers, as opposed to the element-level events listInteractions reports.',
        'method' => 'GET',
        'url_structure' => '/management/p/<projectId>/getPageEvents/{pageName}',
        'parameters' => [
            '{pageName}' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page/route name, as URL segments. A nested route is written with slashes, exactly as the route reads — the segments after the command name are joined back together.',
                'example' => 'docs/commands',
                'validation' => 'Must be an existing route, or one of the special pages 404, 500, 403, 401.'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getPageEvents/home or GET /management/p/<projectId>/getPageEvents/docs/commands',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page events retrieved',
            'data' => [
                'pageName' => 'home',
                'events' => [
                    'onload' => ['{{call:fetchState:commandsList}}', '{{call:toast:Hi,info}}']
                ],
                'interactions' => [
                    [
                        'event' => 'onload',
                        'index' => 0,
                        'function' => 'fetchState',
                        'params' => ['commandsList'],
                        'raw' => '{{call:fetchState:commandsList}}'
                    ]
                ],
                'availableEvents' => ['onload', 'onresize', 'onscroll'],
                'count' => 1
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'No page name was given — the URL carried no segment after the command.',
            '404.route.not_found' => 'The page is neither an existing route nor one of the special pages 404, 500, 403, 401.'
        ],
        'notes' => 'data.events is the raw stored map of event name to call strings; data.interactions is the same content parsed into function + params, with the index each call sits at — that index is what addPageEvent returns and what editPageEvent and deletePageEvent take. A page with nothing attached answers 200 with an empty events map and count 0, so absence is not an error. data.availableEvents is the fixed set of page-level events; element-level events live on nodes and are reached through listInteractions.'
    ],

    'addPageEvent' => [
        'description' => 'Appends a page-level event interaction — a QS.* call fired on the document\'s onload, onresize or onscroll for one page. The element-level counterpart is addInteraction.',
        'method' => 'POST',
        'url_structure' => '/management/p/<projectId>/addPageEvent',
        'parameters' => [
            'pageName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page/route the event belongs to. Nested routes are written with slashes.',
                'example' => 'home',
                'validation' => 'Must be an existing route, or one of the special pages 404, 500, 403, 401.'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page-level event to attach to.',
                'example' => 'onload',
                'validation' => 'Exactly one of onload, onresize, onscroll.'
            ],
            'function' => [
                'required' => true,
                'type' => 'string',
                'description' => 'QS.* verb to call. Must be a name in the QS verb catalogue.',
                'example' => 'onScrollFetchState',
                'validation' => 'Must match an identifier — a letter or underscore followed by letters, digits or underscores — and name a catalogued verb.'
            ],
            'params' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Positional arguments for the verb. A single non-array value is wrapped into a one-element array. Each argument is checked against the verb\'s catalogue signature: a required argument may not be empty, and an argument the catalogue types as a route parameter must name a :param of the page\'s own route.',
                'example' => '["scrollingStore", "200", "100"]'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addPageEvent with body: {"pageName": "home", "event": "onload", "function": "onScrollFetchState", "params": ["scrollingStore", "200", "100"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page event added successfully',
            'data' => [
                'pageName' => 'home',
                'event' => 'onload',
                'callSyntax' => '{{call:onScrollFetchState:scrollingStore,200,100}}',
                'index' => 0
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'pageName, event or function is missing or empty; or one of the verb\'s arguments failed validation, in which case errors[] carries a field, an index and a reason per argument.',
            '404.route.not_found' => 'pageName is neither an existing route nor one of the special pages 404, 500, 403, 401.',
            '400.validation.invalid_value' => 'event is not one of onload, onresize, onscroll.',
            '400.validation.invalid_format' => 'function is not a valid identifier.',
            '422.validation.unknown_verb' => 'function is a well-formed name but is not in the QS verb catalogue.',
            '500.server.file_write_failed' => 'The page-events file could not be written.'
        ],
        'notes' => 'The call is appended to the event\'s list, and data.index is the position it landed at — pass that index to editPageEvent or deletePageEvent. The verb\'s arguments are validated against the catalogue, but the catalogue\'s own events list is NOT enforced here: a verb documented only for click events is accepted on onload. Prefer onScrollFetchState over a raw onscroll fetchState for infinite scroll — it carries the debounce and exhausted guards. Adding the first event for a page creates the project\'s page-events file and its data folder if they do not exist yet.'
    ],

    'editPageEvent' => [
        'description' => 'Edits an existing page-level event interaction (onload/onresize/onscroll). Replaces the call at the given index, optionally moving it to a different page-level event.',
        'method' => 'PUT',
        'parameters' => [
            'pageName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page/route name as stored in data/page-events.json',
                'example' => 'test/scrolling'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Current page-level event holding the interaction: onload, onresize, or onscroll',
                'example' => 'onload'
            ],
            'index' => [
                'required' => true,
                'type' => 'integer',
                'description' => '0-based index of the interaction within the event array',
                'example' => 0
            ],
            'function' => [
                'required' => true,
                'type' => 'string',
                'description' => 'New QS.* function name (must be in qsVerbCatalog)',
                'example' => 'onScrollFetchState'
            ],
            'params' => [
                'required' => false,
                'type' => 'array',
                'description' => 'New function parameters (positional)',
                'example' => '["scrollingStore", "200", "100"]'
            ],
            'newEvent' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Move the interaction to a different page-level event (onload/onresize/onscroll). Omit to keep on the current event.',
                'example' => 'onscroll'
            ]
        ],
        'example_put' => 'PUT /management/p/<projectId>/editPageEvent with body: {"pageName": "test/scrolling", "event": "onload", "index": 0, "function": "onScrollFetchState", "params": ["scrollingStore", "200", "100"]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page event updated successfully',
            'data' => [
                'pageName' => 'test/scrolling',
                'event' => 'onload',
                'index' => 0,
                'oldCallSyntax' => '{{call:onScrollFetchState:scrollingStore,50,100}}',
                'newInteraction' => [
                    'function' => 'onScrollFetchState',
                    'params' => ['scrollingStore', '200', '100'],
                    'raw' => '{{call:onScrollFetchState:scrollingStore,200,100}}'
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'pageName, event, index, and function are required',
            '400.validation.invalid_value' => 'event/newEvent not in [onload, onresize, onscroll], or index negative',
            '400.validation.invalid_format' => 'function name does not match identifier pattern',
            '404.route.not_found' => 'pageName does not exist in routes',
            '404.data.not_found' => 'page-events.json file is missing',
            '404.interaction.not_found' => 'No interaction at the given (event, index) for this page',
            '500.server.file_read_failed' => 'Failed to read page events file.',
            '500.server.file_write_failed' => 'Failed to save page events file.',
            '500.server.internal_error' => 'Invalid JSON in page events file. Failed to encode page events JSON.'
        ],
        'notes' => 'When newEvent differs from event, the interaction is spliced out of the source event and pushed onto the new event (empty event/page entries are cleaned up). When newEvent is omitted or equal, the call is replaced in-place at the same index. Counterpart to editInteraction (which edits element-level events on a node).'
    ],

    'deletePageEvent' => [
        'description' => 'Removes one page-level event interaction, identified by its page, its event and its index within that event\'s list.',
        'method' => 'DELETE',
        'url_structure' => '/management/p/<projectId>/deletePageEvent',
        'parameters' => [
            'pageName' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page/route holding the interaction, as stored in the project\'s page-events file. Nested routes are written with slashes.',
                'example' => 'home'
            ],
            'event' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page-level event the interaction sits on: onload, onresize or onscroll.',
                'example' => 'onload'
            ],
            'index' => [
                'required' => true,
                'type' => 'integer',
                'description' => '0-based position of the interaction within that event\'s list, as reported by getPageEvents.',
                'example' => 0,
                'validation' => 'Cast to an integer, so a numeric string is accepted. Must be at least 0 and less than the number of interactions on that event.'
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deletePageEvent with body: {"pageName": "home", "event": "onload", "index": 0}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Page event deleted successfully',
            'data' => [
                'pageName' => 'home',
                'event' => 'onload',
                'index' => 0,
                'removed' => '{{call:fetchState:commandsList}}'
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'pageName, event or index is missing.',
            '404.data.not_found' => 'The project has no page-events file yet, or that page and event hold no interactions. An unknown page name reports this rather than a route error — this command reads the stored events, it does not check the route table.',
            '400.validation.invalid_value' => 'index is negative or beyond the last interaction on that event.',
            '500.server.file_write_failed' => 'The page-events file could not be written.'
        ],
        'notes' => 'data.removed returns the call string that was deleted, so the caller can undo by passing it back through addPageEvent. Removing the last interaction on an event drops the event, and removing the last event on a page drops the page, so an emptied page does not linger in the file. Indexes shift after a delete: re-read getPageEvents before deleting a second interaction from the same event.'
    ],

    'importStructureTranslations' => [
        'description' => 'Bulk-write translation values for a complex-element subtree (currently: Table) by submitting a CSV-shaped grid in another language. Translation-only — no JSON structure change. Validates that the target structure exists on the named page AND that the pasted grid dimensions match exactly; refuses partial writes on mismatch.',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page route containing the target structure',
                'example' => 'test/complex-element'
            ],
            'kind' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Complex-element kind. Currently only "table" is supported.',
                'example' => 'table'
            ],
            'structureId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The data-qs-complex-id attribute value on the target structure (matches the table id stamped by the Table builder).',
                'example' => 'q1Sales'
            ],
            'language' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target language code (must be in the project LANGUAGES_SUPPORTED config).',
                'example' => 'fr'
            ],
            'header' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Header row cell values (one per column). Empty array [] when the table has no <thead>.',
                'example' => '["Trimestre", "Ventes", "Notes"]'
            ],
            'rows' => [
                'required' => true,
                'type' => 'array',
                'description' => 'Body rows — array of arrays of cell values. Width must match the existing table\'s column count.',
                'example' => '[["Q1", "100", "..."], ["Q2", "150", "..."]]'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/importStructureTranslations with body: {"route": "test/complex-element", "kind": "table", "structureId": "q1Sales", "language": "fr", "header": ["Trimestre","Ventes","Notes"], "rows": [["Q1","100","..."]]}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Translations imported for "q1Sales" (fr).',
            'data' => [
                'route' => 'test/complex-element',
                'kind' => 'table',
                'structureId' => 'q1Sales',
                'language' => 'fr',
                'keysWritten' => 6,
                'dimensions' => ['hasHead' => true, 'headerCols' => 3, 'bodyRows' => 1, 'cols' => 3]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'route, kind, structureId, language, and rows are required',
            '400.validation.invalid_value' => 'unsupported kind, language not in project config, or rows malformed',
            '400.validation.invalid_format' => 'structureId does not match HTML id format',
            '404.route.not_found' => 'page JSON file does not exist for the route',
            '404.structure.not_found' => 'no <table data-qs-complex-id=X> found on the page (table may pre-date the marker — see BETA7_TABLE_TRANSLATION_CSV.md)',
            '422.validation.dimension_mismatch' => 'pasted grid dimensions do not match the existing table — response data carries the expected vs got diff',
            '500.server.file_read_failed' => 'page JSON file unreadable',
            '500.server.file_write_failed' => 'translation file write failed',
            '500.server.internal_error' => 'The page JSON is malformed, a complex table node\'s dimensions could not be measured, or the merged translations could not be encoded. The offending element is named in the message.'
        ],
        'notes' => 'Counterpart to the Table wizard\'s Translatable paste mode (which CREATES + writes the FIRST language\'s values). This command is for adding additional languages to an existing structure. Empty cells in the pasted grid become empty translation values (NOT skipped — the key must exist after this write or the renderer falls back to the raw key text).'
    ],

    // =========================================================================
    // STATE STORE COMMANDS
    // =========================================================================

    'getStateStores' => [
        'description' => 'Reads per-page state-store definitions. A state store is a named, endpoint-bound client view-model that gives interactions memory (pagination, search, filters, infinite scroll, auth state). Returns the store-set for a single route, or every route when "route" is omitted.',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Page/route name. Omit (or send empty) to retrieve stores for ALL routes. "pageName" is accepted as an alias.',
                'example' => 'home',
                'alias' => 'pageName'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/getStateStores with body: {"route": "home"} (single route) or {} (all routes)',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'State stores retrieved',
            'data' => [
                'route' => 'home',
                'stores' => [
                    'commandsList' => [
                        'endpoint' => '@help-api/list',
                        'fetchOnLoad' => true,
                        'fields' => [
                            'page' => ['dir' => 'request', 'init' => 'query:page', 'default' => 1],
                            'items' => ['dir' => 'response', 'from' => 'data', 'append' => false]
                        ]
                    ]
                ]
            ]
        ],
        'error_responses' => [],
        'notes' => 'Read-only. When "route" is provided the "stores" object holds only that route\'s stores ({storeId => def}); when omitted it is keyed by route ({route => {storeId => def}}). Definitions live in the per-project data/state-stores.json sidecar and are emitted to the client as window.QS_STATE_STORES for the qs.js runtime (QS.getState / QS.setState / QS.fetchState). Permission: editStructure.'
    ],

    'setStateStores' => [
        'description' => 'Replaces a page\'s state-store definitions (read-modify-write: the admin panel sends the route\'s FULL store-set). An empty "stores" object clears the route\'s entry. Each store binds ONE endpoint and declares fields with a direction, an init source, and/or a response path.',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Page/route name to write stores for. Must be an existing route (special pages 404/500/403/401 are also allowed). "pageName" is accepted as an alias.',
                'example' => 'home',
                'alias' => 'pageName'
            ],
            'stores' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Map of {storeId => storeDefinition} that REPLACES the route\'s entire store-set. Send {} to clear the route. Store id must start with a letter, then letters/digits/underscore/hyphen.',
                'example' => '{"commandsList": {"endpoint": "@help-api/list", "fetchOnLoad": true, "fields": {"page": {"dir": "request", "init": "query:page", "default": 1}, "items": {"dir": "response", "from": "data", "append": false}}}}'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/setStateStores with body: {"route": "home", "stores": {"results": {"endpoint": "@search-api/query", "fields": {"q": {"dir": "request", "init": "query:q", "default": ""}, "items": {"dir": "response", "from": "data.results"}}}}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'State stores saved',
            'data' => [
                'route' => 'home',
                'stores' => [
                    'results' => [
                        'endpoint' => '@search-api/query',
                        'fields' => [
                            'q' => ['dir' => 'request', 'init' => 'query:q', 'default' => ''],
                            'items' => ['dir' => 'response', 'from' => 'data.results']
                        ]
                    ]
                ]
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing/invalid "route", or "stores" is not an object',
            '400.validation.invalid_value' => 'A store failed validation (bad store id; endpoint not "@apiId/endpointId"; no fields; bad field name; dir not request|response|both; or missing "from" for a response/both field)',
            '404.route.not_found' => 'Route does not exist'
        ],
        'notes' => 'Store definition shape — endpoint: "@apiId/endpointId" (required); fetchOnLoad: bool (optional); fields: {name => {dir, init?, default?, from?, append?}} (at least one field). Field directions: "request" (sent only), "response" (set from the response only — requires "from"), "both" (sent from its current value, then updated from the response — requires "from"). init (for sent fields): a literal, or "query:<param>" | "localStorage:<key>" | "sessionStorage:<key>". from (for received fields): a response dot-path (e.g. "data" or "meta.total"). append: true makes a list field grow (infinite scroll) instead of replacing. The definition is runtime-agnostic JSON; beta.8\'s server-side data-resolver reads the same shape. Permission: editStructure.'
    ],

    // =========================================================================
    // API REGISTRY COMMANDS
    // =========================================================================
    
    'listApiEndpoints' => [
        'description' => 'Lists all registered external API endpoints, grouped by API. Can filter by specific API ID.',
        'method' => 'GET',
        'parameters' => [
            'apiId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Filter to show endpoints from only this API (URL segment or query param)',
                'example' => 'main-backend'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/listApiEndpoints or GET /management/p/<projectId>/listApiEndpoints/main-backend',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'All API endpoints listed successfully',
            'data' => [
                'apis' => [
                    [
                        'apiId' => 'main-backend',
                        'name' => 'Main Backend API',
                        'description' => 'Our primary REST API',
                        'baseUrl' => 'https://api.example.com',
                        'auth' => ['type' => 'bearer', 'tokenSource' => 'localStorage:apiToken'],
                        'endpointCount' => 2,
                        'endpoints' => [
                            [
                                'id' => 'contact-submit',
                                'name' => 'Submit Contact Form',
                                'path' => '/contact',
                                'method' => 'POST',
                                'fullUrl' => 'https://api.example.com/contact'
                            ]
                        ]
                    ]
                ],
                'apiCount' => 1,
                'totalEndpoints' => 2
            ]
        ],
        'error_responses' => [
            '404.api.error.not_found' => 'Specified API not found'
        ],
        'notes' => 'APIs and endpoints are defined per-project in data/api-endpoints.json. Use {{call:fetch:@apiId/endpointId,...}} in interactions to call these endpoints.'
    ],
    'listOAuthProviders' => [
        'description' => 'Lists available OAuth provider presets (the union of admin catalogue + per-project overrides) along with whether the per-provider routes are already set up. Drives the oauth-button wizard\'s provider picker.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listOAuthProviders',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => '2 OAuth providers listed',
            'data' => [
                'providers' => [
                    [
                        'id' => 'google',
                        'name' => 'Google',
                        'source' => 'admin',
                        'scope' => 'openid email profile',
                        'refresh_token_supported' => true,
                        'has_revoke_url' => true,
                        'setup' => [
                            'start_route_exists' => false,
                            'callback_route_exists' => false,
                            'fully_set_up' => false,
                            'start_route_path' => 'auth/oauth/google/start',
                            'callback_route_path' => 'auth/oauth/google/callback'
                        ]
                    ]
                ],
                'count' => 1
            ]
        ],
        'error_responses' => [],
        'notes' => 'Sources: "admin" (engine catalogue at <secure>/admin/config/oauth-presets.json), "project" (project-only at <secure>/projects/<active>/data/oauth-presets.json), "project-override" (project overrides an admin entry). Per the Slice 2.5 lookup order locked 2026-06-15, project entries replace admin entries at PROVIDER level (full-entry replace, not field-level merge). Each provider entry includes preset, credentials_status (set/missing), resolver_count (route-resolvers explicitly referencing this provider id), and setup (per-provider route existence).'
    ],
    'addOAuthProvider' => [
        'description' => 'Add a new OAuth provider preset and (optionally) its credentials at admin or per-project scope. Writes to oauth-presets.json + oauth-secrets.{php,json}. Drives the /admin/oauth-providers page\'s Add modal.',
        'method' => 'POST',
        'parameters' => [
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['admin', 'project'], 'description' => 'Where to write the preset.'],
            'id' => ['required' => true, 'type' => 'string', 'description' => 'Lowercase provider id (slug). /^[a-z][a-z0-9-]*$/.', 'example' => 'mycorp-sso'],
            'preset' => ['required' => true, 'type' => 'object', 'description' => 'Full preset shape: authorize_url, token_url, userinfo_url, revoke_url (optional), scope, userinfo_sub_path, userinfo_email_path, userinfo_name_path (optional), extra_authorize_params (optional), refresh_token_supported.'],
            'credentials' => ['required' => false, 'type' => 'object', 'description' => 'Optional {client_id, client_secret}. client_secret optional for public clients (PKCE-only).']
        ],
        'example_post' => 'POST /management/p/<projectId>/addOAuthProvider with {"scope":"project","id":"mycorp-sso","preset":{...},"credentials":{"client_id":"abc","client_secret":"xyz"}}',
        'success_response' => [
            'status' => 201,
            'code' => 'oauth.provider.created',
            'data' => ['id' => 'mycorp-sso', 'scope' => 'project', 'credentials_status' => 'set']
        ],
        'error_responses' => [
            '400.validation.failed' => 'Invalid body — see errors[] for per-field details',
            '409.oauth.provider.duplicate' => 'An entry with this id already exists at the target scope; use editOAuthProvider',
            '500.server.operation_failed' => 'The provider presets file could not be written at the requested scope ("admin" or "project"), or the preset was written but its secrets file could not be. The scope is named in the message.'
        ],
        'notes' => 'Admin-tier only — handles client_secret. Cross-scope duplicates (e.g., same id in both admin and project) are allowed and are the per-project override pattern locked in Slice 2.5.'
    ],
    'editOAuthProvider' => [
        'description' => 'Update an existing OAuth provider preset and (optionally) credentials. Supports rename (newId) and cross-scope move (newScope). Replace-all semantic on the preset object — read the current entry first if you want field-level updates.',
        'method' => 'POST',
        'parameters' => [
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['admin', 'project']],
            'id' => ['required' => true, 'type' => 'string'],
            'preset' => ['required' => true, 'type' => 'object', 'description' => 'Full preset shape (replaces existing)'],
            'credentials' => ['required' => false, 'type' => 'object', 'description' => 'When omitted, existing secret is left untouched. When client_secret is empty, only client_id is updated; the existing secret is preserved.'],
            'newId' => ['required' => false, 'type' => 'string', 'description' => 'New provider id (rename). Must not collide at target scope.'],
            'newScope' => ['required' => false, 'type' => 'string', 'enum' => ['admin', 'project'], 'description' => 'Move between scopes; copy-then-delete.']
        ],
        'example_post' => 'POST /management/p/<projectId>/editOAuthProvider with {"scope":"admin","id":"google","preset":{...},"newScope":"project"}',
        'success_response' => [
            'status' => 200,
            'code' => 'oauth.provider.updated',
            'data' => ['id' => 'google', 'scope' => 'project', 'old_id' => null, 'old_scope' => 'admin']
        ],
        'error_responses' => [
            '400.validation.failed' => 'Invalid body',
            '404.oauth.provider.not_found' => 'Source entry not found at declared scope',
            '409.oauth.provider.duplicate' => 'newId / newScope target already has an entry',
            '500.server.operation_failed' => 'A write failed at one of the scopes involved ("admin" or "project"): the presets file at the current scope, the presets file at a new scope on a move, the removal from the source scope after a successful move (the message then names the deleteOAuthProvider call that cleans it up), or the secrets file.'
        ],
        'notes' => 'Admin-tier only. Cross-scope move is copy-then-delete; on partial failure the entry exists in BOTH scopes (recoverable via deleteOAuthProvider).'
    ],
    'deleteOAuthProvider' => [
        'description' => 'Remove an OAuth provider preset and credentials at the given scope. STRICT in-use block: refuses with 409 when route-resolvers or page-structure oauth-button elements still reference this provider. The response carries a usage summary so the UI can guide the author to remove consumers first.',
        'method' => 'POST',
        'parameters' => [
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['admin', 'project']],
            'id' => ['required' => true, 'type' => 'string']
        ],
        'example_post' => 'POST /management/p/<projectId>/deleteOAuthProvider with {"scope":"project","id":"mycorp-sso"}',
        'success_response' => [
            'status' => 200,
            'code' => 'oauth.provider.deleted',
            'data' => ['id' => 'mycorp-sso', 'scope' => 'project', 'was_override' => false, 'admin_entry_remains' => false]
        ],
        'error_responses' => [
            '400.validation.failed' => 'Invalid body',
            '404.oauth.provider.not_found' => 'No entry at the declared scope',
            '409.oauth.provider.in_use' => 'Provider is referenced by route-resolvers or oauth-button elements; data.usage carries the per-site list',
            '500.server.operation_failed' => 'The provider presets file could not be written at the requested scope ("admin" or "project"). The scope is named in the message.'
        ],
        'notes' => 'Removing a PROJECT-scope OVERRIDE (when an admin entry with the same id exists) skips the in-use check — the admin entry survives, so consumers still resolve. data.was_override = true in that case.'
    ],

    'listStorageItems' => [
        'description' => 'List the project storage registry — every declared browser-storage key (localStorage / sessionStorage / cookie) with category, retention, translatable description, and derived consentRequired. Drives /admin/storage + the storageKey picker. The GDPR / cookie-consent data layer.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/listStorageItems',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['items' => '[{id, scope, category, description?, retention?, consentRequired, ...}]', 'count' => 0, 'scopes' => ['localStorage', 'sessionStorage', 'cookie'], 'categories' => ['essential', 'functional', 'analytics', 'marketing']]
        ],
        'error_responses' => [],
        'notes' => 'consentRequired is derived (essential => false, else => true), never stored. The key VALUE is provided by the site visitor at runtime; the registry never holds it.'
    ],
    'addStorageItem' => [
        'description' => 'Declare a new storage key in the project registry. id is the key name; scope + category required. Cookie scope accepts domain/path/secure/sameSite. description is a translatable {lang: text} map.',
        'method' => 'POST',
        'parameters' => [
            'id' => ['required' => true, 'type' => 'string', 'description' => 'Storage key name (no whitespace).', 'example' => 'cartSession'],
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['localStorage', 'sessionStorage', 'cookie']],
            'category' => ['required' => true, 'type' => 'string', 'enum' => ['essential', 'functional', 'analytics', 'marketing']],
            'description' => ['required' => false, 'type' => 'object', 'description' => '{lang: text} translatable purpose.'],
            'retention' => ['required' => false, 'type' => 'string', 'description' => 'session | 30d | 1y | custom.'],
            'domain' => ['required' => false, 'type' => 'string', 'description' => 'Cookie scope only.'],
            'path' => ['required' => false, 'type' => 'string', 'description' => 'Cookie scope only.'],
            'secure' => ['required' => false, 'type' => 'boolean', 'description' => 'Cookie scope only.'],
            'sameSite' => ['required' => false, 'type' => 'string', 'enum' => ['Strict', 'Lax', 'None'], 'description' => 'Cookie scope only.']
        ],
        'example_post' => 'POST /management/p/<projectId>/addStorageItem with {"id":"cartSession","scope":"localStorage","category":"functional","description":{"en":"Saved cart"},"retention":"30d"}',
        'success_response' => [
            'status' => 201,
            'code' => 'storage.created',
            'data' => ['item' => '{id, scope, category, ..., consentRequired}']
        ],
        'error_responses' => [
            '400.validation.required' => 'id missing',
            '400.validation.invalid' => 'Invalid scope/category/etc. — see errors[]',
            '409.storage.duplicate' => 'An item with this id already exists; use editStorageItem',
            '500.server.operation_failed' => 'Failed to write the storage registry.'
        ],
        'notes' => 'Writes data/storage.json. consentRequired is derived from category.'
    ],
    'editStorageItem' => [
        'description' => 'Update (replace-all on fields) and optionally rename a storage item. Pass newId to rename. Read the current entry first if you want a field-level merge.',
        'method' => 'POST',
        'parameters' => [
            'id' => ['required' => true, 'type' => 'string', 'description' => 'Existing key name.'],
            'newId' => ['required' => false, 'type' => 'string', 'description' => 'New key name (rename).'],
            'scope' => ['required' => true, 'type' => 'string', 'enum' => ['localStorage', 'sessionStorage', 'cookie']],
            'category' => ['required' => true, 'type' => 'string', 'enum' => ['essential', 'functional', 'analytics', 'marketing']],
            'description' => ['required' => false, 'type' => 'object'],
            'retention' => ['required' => false, 'type' => 'string']
        ],
        'example_post' => 'POST /management/p/<projectId>/editStorageItem with {"id":"cartSession","scope":"localStorage","category":"functional","retention":"90d"}',
        'success_response' => [
            'status' => 200,
            'code' => 'storage.updated',
            'data' => ['item' => '{...}', 'renamedFrom' => null]
        ],
        'error_responses' => [
            '404.storage.not_found' => 'No item with this id',
            '409.storage.duplicate' => 'newId already taken',
            '400.validation.invalid' => 'Invalid fields — see errors[]',
            '400.validation.required' => 'Storage item id is required.',
            '500.server.operation_failed' => 'Failed to write the storage registry.'
        ],
        'notes' => 'Cookie-only fields (domain/path/secure/sameSite) accepted when scope is cookie.'
    ],
    'deleteStorageItem' => [
        'description' => 'Remove a storage item from the project registry.',
        'method' => 'POST',
        'parameters' => [
            'id' => ['required' => true, 'type' => 'string']
        ],
        'example_post' => 'POST /management/p/<projectId>/deleteStorageItem with {"id":"cartSession"}',
        'success_response' => [
            'status' => 200,
            'code' => 'storage.deleted',
            'data' => ['id' => 'cartSession']
        ],
        'error_responses' => [
            '404.storage.not_found' => 'No item with this id',
            '400.validation.required' => 'Storage item id is required.',
            '500.server.operation_failed' => 'Failed to write the storage registry.'
        ],
        'notes' => 'Does not check in-use references yet — the scan/reconcile slice surfaces dangling reads. Also clears the item description key from translate/.'
    ],
    'setStorageDescLang' => [
        'description' => 'Change the language storage descriptions are authored in (registry-level descLang on data/storage.json). MOVES every item description from the current language to the target in the translate files (true move — source cleared), OVERWRITING any existing target-language values. Two-step: call without confirm to preview (409 needsConfirm with moved/overwrites counts), then re-call with confirm:true to execute. Descriptions are page content (textKeys) so the move is live — no regenerate needed.',
        'method' => 'POST',
        'parameters' => [
            'lang' => ['required' => true, 'type' => 'string', 'description' => 'Target language, must be in LANGUAGES_SUPPORTED.'],
            'confirm' => ['required' => false, 'type' => 'boolean', 'description' => 'Execute the move (defaults false → preview only).']
        ],
        'example_post' => 'POST /management/p/<projectId>/setStorageDescLang with {"lang":"fr","confirm":true}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['descLang' => 'fr', 'moved' => 3, 'overwrites' => 1]
        ],
        'error_responses' => [
            '409.storage.desclang_confirm' => 'Confirmation required — data: {from, to, moved, overwrites, needsConfirm}',
            '400.validation.invalid' => 'Language not in LANGUAGES_SUPPORTED',
            '400.validation.required' => 'A target language is required.',
            '500.server.operation_failed' => 'Failed to write the storage registry.'
        ],
        'notes' => 'No-op (200) when lang already active. Drives the /admin/storage description-language selector.'
    ],
    'scanStorageUsage' => [
        'description' => 'Scan the build for storage-key references and reconcile against the declared registry. Triggered, warn-style check (not blocking). Walks structures (data-storage-* attrs + saveToken/store/clearToken chains), api-endpoints auth sources, page-events handler chains, and state-store init. Buckets keys into ok / incomplete (used but undeclared) / dangling_read (read but never written) / orphan (declared but unreferenced).',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/scanStorageUsage',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['buckets' => '{ok, incomplete, dangling_read, orphan: [{id, declared, inferredScope, writers, readers, clearers}]}', 'counts' => '{ok, incomplete, dangling_read, orphan}']
        ],
        'error_responses' => [],
        'notes' => 'Read-only. Drives the /admin/storage Scan view; incomplete keys get a one-click Declare (addStorageItem with inferred scope). Dangling reads are flagged, not auto-removed.'
    ],

    'generateConsentLayer' => [
        'description' => 'Generate (or re-generate) the consent banner + popup structures from the registry and enable the consent layer (data/consent.json enabled=true). Writes templates/model/json/consent-banner.json + consent-popup.json — one popup toggle row per DECLARED non-essential category — and seeds default-language copy for the textKeys (NEW keys only, never clobbering edited copy; other languages are translated via the Translation Manager). The banner links to the cookie-policy route recorded in consent.json (owned by generateCookiePolicy). The structures render globally like menu/footer and are styleable/editable in the visual editor.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/generateConsentLayer',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['categories' => '[declared non-essential categories]', 'languagesSeeded' => '{lang: keysAdded}', 'policyRoute' => 'string|null', 'enabled' => true]
        ],
        'error_responses' => [
            '500.server.operation_failed' => 'Failed to write the consent layer structure files.'
        ],
        'notes' => 'Idempotent. Runtime write-gating only activates once this enables the layer. Drives the /admin/storage "Generate consent layer" button.'
    ],

    'generateCookiePolicy' => [
        'description' => 'Generate (or overwrite) the cookie-policy page at an author-chosen route — a deterministic table built from the registry (one row per declared key) plus an OAuth-provider privacy-link section and a legal-review note. Seeds default-language structural copy (new keys only; other languages translate via the Translation Manager) and records the route in data/consent.json so the banner links to it. Per-item descriptions are not seeded here — they live in translate/ under storage.desc.<id>, authored via the storage registry and resolved live.',
        'method' => 'POST',
        'parameters' => [
            'route' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Route to host the policy page (1-5 lowercase literal segments). If it already exists, its structure is overwritten.',
                'example' => 'cookies'
            ],
            'overwrite' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Confirm replacing a policy page that already exists. Without it the command refuses rather than overwriting, so the caller can re-send with overwrite:true once it knows.',
                'example' => 'true',
                'default' => false
            ],
        ],
        'example_post' => 'POST /management/p/<projectId>/generateCookiePolicy {"route":"cookies"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['route' => '/cookies', 'overwritten' => false, 'rows' => 'int', 'languagesSeeded' => '{lang: keysAdded}']
        ],
        'error_responses' => [
            '400.validation.required' => 'A route is required for the cookie-policy page.',
            '409.route.exists' => 'The requested route already exists, and generating the cookie-policy page there would overwrite its content.',
            '500.server.operation_failed' => 'Failed to write the cookie-policy page structure.'
        ],
        'notes' => 'Creates the route (cascading parents) when missing; overwrites the structure when it exists (warned). Pairs with generateConsentLayer (which links the banner to this route).'
    ],

    'getConsentStatus' => [
        'description' => 'Current consent-layer state for the project in the URL marker: whether it is enabled, whether the banner/popup structures are generated, the recorded cookie-policy route, and whether that route still exists. Read-only; drives the /admin/storage generate modal (pre-fill + Generate/Update/Delete).',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/getConsentStatus',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['enabled' => true, 'generated' => true, 'policyRoute' => '/cookies', 'policyRouteExists' => true]
        ],
        'error_responses' => [],
        'notes' => 'policyRouteExists=false with a non-null policyRoute means the page was deleted manually (stale reference).'
    ],

    'deleteCookiePolicy' => [
        'description' => 'Delete the generated cookie-policy page (the route recorded in consent.json), clear the recorded route, and regenerate the banner without its policy link. No body — the route is read from consent.json so an unrelated route can not be deleted by accident.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/deleteCookiePolicy',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['route' => '/cookies', 'routeDeleted' => true]
        ],
        'error_responses' => [
            '400 consent.no_policy_route' => 'No cookie-policy route is configured',
            '400.consent.no_policy_route' => 'No cookie-policy route is configured for this project.'
        ],
        'notes' => 'Deletes the leaf route + its page files; parent routes stay. routeDeleted=false means the route was already gone (stale reference cleared).'
    ],

    'getPrivacyStatus' => [
        'description' => 'Full privacy-helper state for /admin/privacy: the privacy registry (collected data, per-baseUrl host classifications, cookieSection, privacyRoute) joined with a live scan of the API registry (data/api-endpoints.json) for outbound data atoms — (endpoint, field) pairs from declared parameters + requestSchema.properties (response schemas ignored) — plus coverage (unmapped atoms, body-bearing endpoints with no request schema, unclassified hosts) and the cookie-page cross-link signal. Read-only; the scan never guesses undeclared fields.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/getPrivacyStatus',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => [
                'descLang' => 'en',
                'languages' => ['en', 'fr'],
                'collectedData' => '[{id, label, purpose}]',
                'hosts' => '[{baseUrl, kind:self|third-party|null, apiIds, name?, privacyUrl?}]',
                'endpoints' => '[{apiId, endpointId, key, name, method, path, baseUrl, fields:[{field, datum|null}], undeclaredBody}]',
                'coverage' => '{totalAtoms, mappedAtoms, unmappedAtoms, unmapped:[{endpoint,field}], undeclaredEndpoints:[{endpoint,method}], unclassifiedHosts, complete}',
                'authSeed' => '{oauth:{wired, providers:[{name,url}], collectedSuggestions}, magicLink:{wired, collectedSuggestions}}',
                'privacyRoute' => 'string|null',
                'privacyRouteExists' => 'bool',
                'cookieSection' => 'auto|omit',
                'cookie' => '{policyRoute, policyRouteExists}'
            ]
        ],
        'error_responses' => [],
        'notes' => 'Drives the /admin/privacy page. An atom = a (endpoint, field) the site is configured to send outward; map it to a collected datum to cover it. undeclaredBody flags POST/PUT/PATCH endpoints with no requestSchema.'
    ],

    'setCollectedDatum' => [
        'description' => 'Create or update a "data collected" entry in data/privacy.json. The id is a stable slug (atoms map to it); label + purpose are prose authored in the registry description language and stored in translate/ under privacy.collected.<id>.label / .purpose. Editing label/purpose is live (no regenerate).',
        'method' => 'POST',
        'parameters' => [
            'id' => ['required' => true, 'type' => 'string', 'description' => 'Stable slug: letters, numbers, hyphens, underscores.'],
            'label' => ['required' => true, 'type' => 'string', 'description' => 'Display name, e.g. "Email address".'],
            'purpose' => ['required' => false, 'type' => 'string', 'description' => 'What you do with it (empty clears).']
        ],
        'example_post' => 'POST /management/p/<projectId>/setCollectedDatum with {"id":"email","label":"Email address","purpose":"To send login links"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['datum' => ['id' => 'email', 'label' => 'Email address', 'purpose' => 'To send login links']]
        ],
        'error_responses' => [
            '400.validation.invalid' => 'Invalid id slug',
            '400.validation.required' => 'Missing id or label',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'
        ],
        'notes' => '201 when new, 200 on update. Drives the /admin/privacy collected-data editor.'
    ],
    'deleteCollectedDatum' => [
        'description' => 'Remove a "data collected" entry. Nulls any atom mappings that pointed at it and clears its label/purpose keys from translate/.',
        'method' => 'POST',
        'parameters' => [
            'id' => ['required' => true, 'type' => 'string']
        ],
        'example_post' => 'POST /management/p/<projectId>/deleteCollectedDatum with {"id":"email"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['id' => 'email']
        ],
        'error_responses' => [
            '404.privacy.not_found' => 'No collected datum with this id',
            '400.validation.required' => 'A collected-data id is required.',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'
        ],
        'notes' => 'Mappings that referenced it become unset (null), not deleted.'
    ],
    'setPrivacyDescLang' => [
        'description' => 'Change the language collected-data prose is authored in (descLang on data/privacy.json). Mirrors setStorageDescLang: MOVES every datum label + purpose from the current language to the target in translate/ (empty-not-delete on the source), OVERWRITING existing target values. Two-step: call without confirm to preview (409 needsConfirm with moved/overwrites), then re-call with confirm:true.',
        'method' => 'POST',
        'parameters' => [
            'lang' => ['required' => true, 'type' => 'string', 'description' => 'Target language, must be in LANGUAGES_SUPPORTED.'],
            'confirm' => ['required' => false, 'type' => 'boolean', 'description' => 'Execute the move (defaults false → preview only).']
        ],
        'example_post' => 'POST /management/p/<projectId>/setPrivacyDescLang with {"lang":"fr","confirm":true}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['descLang' => 'fr', 'moved' => 4, 'overwrites' => 0]
        ],
        'error_responses' => [
            '409.privacy.desclang_confirm' => 'Confirmation required — data: {from, to, moved, overwrites, needsConfirm}',
            '400.validation.invalid' => 'Language not in LANGUAGES_SUPPORTED',
            '400.validation.required' => 'A target language is required.',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'
        ],
        'notes' => 'No-op (200) when lang already active. Drives the /admin/privacy description-language selector.'
    ],
    'setPrivacyMapping' => [
        'description' => 'Map a scanned atom (endpoint, field) to a collected datum, or unset it. The atom comes from the API request-schema scan (getPrivacyStatus); the datum must already exist (setCollectedDatum). The recipient (your server vs a third party) is derived from the endpoint host classification, never stored on the mapping.',
        'method' => 'POST',
        'parameters' => [
            'endpoint' => ['required' => true, 'type' => 'string', 'description' => 'Atom endpoint key, "apiId/endpointId".'],
            'field' => ['required' => true, 'type' => 'string', 'description' => 'Field name sent by that endpoint.'],
            'datum' => ['required' => false, 'type' => 'string', 'description' => 'Collected-data id; empty / "__unset__" clears the mapping.']
        ],
        'example_post' => 'POST /management/p/<projectId>/setPrivacyMapping with {"endpoint":"test-api-auth/login","field":"login","datum":"email"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['endpoint' => 'test-api-auth/login', 'field' => 'login', 'datum' => 'email']
        ],
        'error_responses' => [
            '400.privacy.unknown_datum' => 'datum is not a declared collected-data id',
            '400.validation.required' => 'Missing endpoint or field',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'
        ],
        'notes' => 'Drives the /admin/privacy atom-mapping UI. Mapping an atom reduces the unmapped-coverage count.'
    ],
    'setPrivacyHost' => [
        'description' => 'Classify an API host (baseUrl) as a server you operate ("self") or a third party. Third parties carry an optional display name + privacy-policy URL, rendered on the privacy page data-sharing section. Classification is author-declared (QuickSite cannot derive it). Reduces the unclassified-hosts coverage count.',
        'method' => 'POST',
        'parameters' => [
            'baseUrl' => ['required' => true, 'type' => 'string', 'description' => 'The API baseUrl to classify (from getPrivacyStatus.hosts).'],
            'kind' => ['required' => true, 'type' => 'string', 'enum' => ['self', 'third-party']],
            'name' => ['required' => false, 'type' => 'string', 'description' => 'Third-party display name.'],
            'privacyUrl' => ['required' => false, 'type' => 'string', 'description' => 'Third-party privacy-policy URL.']
        ],
        'example_post' => 'POST /management/p/<projectId>/setPrivacyHost with {"baseUrl":"https://hooks.x.com","kind":"third-party","name":"X","privacyUrl":"https://x.com/privacy"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['host' => ['baseUrl' => 'https://hooks.x.com', 'kind' => 'third-party', 'name' => 'X', 'privacyUrl' => 'https://x.com/privacy']]
        ],
        'error_responses' => [
            '400.validation.invalid' => 'kind must be self or third-party',
            '400.validation.required' => 'Missing baseUrl',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'
        ],
        'notes' => 'Drives the /admin/privacy host-classification UI. name/privacyUrl are ignored for kind=self.'
    ],
    'generatePrivacyPolicy' => [
        'description' => 'Generate (or overwrite) the privacy-policy page at an author-chosen route, deterministically from the privacy registry + API scan. Builds a "data we collect" table (collected-data label/purpose textKeys), a per-third-party "data sharing" section (derived from atom mappings + host classification), an OAuth sign-in section, a cookie cross-link (link if a cookie page exists / hint if not / omitted when cookieSection=omit), and a legal disclaimer. Records the route in data/privacy.json and seeds default-language structural copy.',
        'method' => 'POST',
        'parameters' => [
            'route' => ['required' => true, 'type' => 'string', 'description' => 'Route to host the page (1-5 lowercase literal segments). Existing route is overwritten with overwrite:true.', 'example' => 'privacy'],
            'overwrite' => ['required' => false, 'type' => 'boolean', 'description' => 'Confirm overwriting an existing route.']
        ],
        'example_post' => 'POST /management/p/<projectId>/generatePrivacyPolicy {"route":"privacy"}',
        'success_response' => [
            'status' => 200,
            'code' => 'success',
            'data' => ['route' => '/privacy', 'overwritten' => false, 'collected' => 3, 'thirdParties' => 1, 'cookieMode' => 'link', 'languagesSeeded' => '{lang: keysAdded}']
        ],
        'error_responses' => [
            '409.route.exists' => 'Route exists — re-call with overwrite:true (data.needsConfirm)',
            '400.route.invalid' => 'Route must have 1-5 segments',
            '400.route.invalid_segment' => 'Invalid route segment',
            '400.validation.required' => 'A route is required for the privacy-policy page.',
            '500.server.operation_failed' => 'Failed to write the privacy-policy page structure.'
        ],
        'notes' => 'Descriptions resolve live (textKeys); regenerate only for data changes (new mappings, host re-classification). Drives the /admin/privacy Generate/Update flow.'
    ],
    'deletePrivacyPolicy' => [
        'description' => 'Delete the generated privacy-policy page (the route recorded in data/privacy.json) and clear the recorded route. No body — the route is read from privacy.json so an unrelated route cannot be deleted by accident. Deletes the leaf route + its page files; parent routes stay.',
        'method' => 'POST',
        'parameters' => [],
        'example_post' => 'POST /management/p/<projectId>/deletePrivacyPolicy',
        'success_response' => ['status' => 200, 'code' => 'success', 'data' => ['route' => '/privacy', 'routeDeleted' => true]],
        'error_responses' => ['400 privacy.no_policy_route' => 'No privacy-policy route is configured',
            '400.privacy.no_policy_route' => 'No privacy-policy route is configured for this project.'],
        'notes' => 'routeDeleted=false means the route was already gone (stale reference cleared).'
    ],
    'setPrivacyCookieSection' => [
        'description' => 'Set how the generated privacy page treats cookies: "auto" (link the cookie policy if one exists, else hint to make one) or "omit" (no cookie section — for sites that handle cookies elsewhere or use none).',
        'method' => 'POST',
        'parameters' => [
            'cookieSection' => ['required' => true, 'type' => 'string', 'enum' => ['auto', 'omit']]
        ],
        'example_post' => 'POST /management/p/<projectId>/setPrivacyCookieSection with {"cookieSection":"omit"}',
        'success_response' => ['status' => 200, 'code' => 'success', 'data' => ['cookieSection' => 'omit']],
        'error_responses' => ['400.validation.invalid' => 'cookieSection must be auto or omit',
            '500.server.operation_failed' => 'Failed to write the privacy registry.'],
        'notes' => 'Applies on the next generate/update. Drives the /admin/privacy cookie-section toggle.'
    ],

    'getApiEndpoint' => [
        'description' => 'Gets a single endpoint by ID, including the parent API\'s auth configuration.',
        'method' => 'GET',
        'parameters' => [
            'endpointId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'The endpoint ID to retrieve (URL segment)',
                'example' => 'contact-submit'
            ],
            'apiId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'API filter for faster lookup or when duplicate IDs exist (URL segment)',
                'example' => 'main-backend'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getApiEndpoint/contact-submit or GET /management/p/<projectId>/getApiEndpoint/main-backend/contact-submit',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Endpoint retrieved successfully',
            'data' => [
                'endpoint' => [
                    'id' => 'contact-submit',
                    'name' => 'Submit Contact Form',
                    'path' => '/contact',
                    'method' => 'POST',
                    'fullUrl' => 'https://api.example.com/contact',
                    'apiId' => 'main-backend',
                    'apiAuth' => ['type' => 'bearer', 'tokenSource' => 'localStorage:apiToken'],
                    'requestSchema' => [
                        'type' => 'object',
                        'required' => ['name', 'email'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string', 'format' => 'email']
                        ]
                    ]
                ],
                'hasDuplicates' => false,
                'duplicateCount' => 1
            ]
        ],
        'error_responses' => [
            '400.api.error.missing_parameter' => 'Missing endpoint ID',
            '404.api.error.not_found' => 'Endpoint not found'
        ],
        'notes' => 'If the same endpoint ID exists in multiple APIs and apiId is not specified, returns the first match. Check hasDuplicates in response to handle ambiguity.'
    ],
    
    'addApi' => [
        'description' => 'Creates a new API group for organizing external endpoints. Endpoints are added via editApi.',
        'method' => 'POST',
        'parameters' => [
            'apiId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Unique identifier for the API (alphanumeric, dashes, underscores)',
                'example' => 'main-backend'
            ],
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Human-readable display name',
                'example' => 'Main Backend API'
            ],
            'baseUrl' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Base URL for all endpoints in this API',
                'example' => 'https://api.example.com'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional description of the API',
                'example' => 'Our primary REST API'
            ],
            'auth' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Authentication configuration: {type: "none"|"bearer"|"apiKey"|"basic", tokenSource: "localStorage:key"|"sessionStorage:key"|"config:key"}',
                'example' => '{"type": "bearer", "tokenSource": "localStorage:apiToken"}'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/addApi with body: {"apiId": "main-backend", "name": "Main Backend", "baseUrl": "https://api.example.com", "auth": {"type": "bearer", "tokenSource": "localStorage:apiToken"}}',
        'success_response' => [
            'status' => 201,
            'code' => 'operation.success',
            'message' => "API 'main-backend' created successfully",
            'data' => [
                'apiId' => 'main-backend',
                'api' => [
                    'name' => 'Main Backend',
                    'baseUrl' => 'https://api.example.com',
                    'auth' => ['type' => 'bearer', 'tokenSource' => 'localStorage:apiToken'],
                    'endpoints' => []
                ]
            ]
        ],
        'error_responses' => [
            '400.api.error.missing_parameter' => 'Missing required parameter (apiId, name, or baseUrl)',
            '400.api.error.invalid_parameter' => 'API with this ID already exists'
        ],
        'notes' => 'After creating an API, add endpoints using editApi with addEndpoint parameter. The API config is stored in data/api-endpoints.json and compiled to qs-api-config.js for client-side use.'
    ],
    
    'editApi' => [
        'description' => 'Modifies an existing API group or manages its endpoints. Can update API properties, add/edit/delete individual endpoints, or replace all endpoints.',
        'method' => 'POST',
        'parameters' => [
            'apiId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API to edit',
                'example' => 'main-backend'
            ],
            'name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New display name',
                'example' => 'Updated API Name'
            ],
            'baseUrl' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New base URL',
                'example' => 'https://new-api.example.com'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New description',
                'example' => 'Updated description'
            ],
            'auth' => [
                'required' => false,
                'type' => 'object',
                'description' => 'New authentication config',
                'example' => '{"type": "apiKey", "tokenSource": "header:X-API-Key"}'
            ],
            'endpoints' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Full replacement of all endpoints (use with caution)',
                'example' => '[{"id": "ep1", "name": "Endpoint 1", "path": "/ep1", "method": "GET"}]'
            ],
            'addEndpoint' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Add a single endpoint: {id, name, path, method, description?, requestSchema?, responseSchema?, headers?, queryParams?, responseBindings?, callableFrom?}. callableFrom (beta.8 A4): "client" | "server" | "both". Absent = auto-derived from auth type (apiKey → server; others → both). Server-only endpoints are filtered out of qs-api-config.js.',
                'example' => '{"id": "contact", "name": "Contact Form", "path": "/contact", "method": "POST"}'
            ],
            'editEndpoint' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Edit existing endpoint: {id: "endpoint-to-edit", updates: {...}}. Updates accept the same fields as addEndpoint, including callableFrom (clear with empty string to revert to auto-derive).',
                'example' => '{"id": "contact", "updates": {"name": "New Name", "path": "/new-path"}}'
            ],
            'deleteEndpoint' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Delete endpoint by ID',
                'example' => 'old-endpoint'
            ],
            'renameEndpoint' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Rename an endpoint id and re-point every reference to it: {from, to, updates?}. Use this rather than deleteEndpoint plus addEndpoint, which would leave references pointing at an id that no longer exists.',
                'example' => '{"from": "contact", "to": "contact-submit"}'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/editApi with body: {"apiId": "main-backend", "addEndpoint": {"id": "users-list", "name": "List Users", "path": "/users", "method": "GET"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "API 'main-backend' updated successfully",
            'data' => [
                'apiId' => 'main-backend',
                'api' => ['...updated API object...'],
                'endpointCount' => 3,
                'countKeyWarnings' => [
                    ['api' => 'main-backend', 'endpoint' => 'users-list', 'slot' => 'many',
                     'key' => 'users.many', 'missingIn' => ['fr']]
                ]
            ]
        ],
        'error_responses' => [
            '400.api.error.missing_parameter' => 'Missing apiId or no updates provided',
            '400.api.error.invalid_parameter' => 'Endpoint already exists (when adding), invalid update operation, or invalid callableFrom value (must be client/server/both or omitted for auto-derive)',
            '404.api.error.not_found' => 'API or endpoint not found'
        ],
        'notes' => 'Use ONE endpoint operation per request: endpoints (full replace), addEndpoint, editEndpoint, or deleteEndpoint. Regenerates qs-api-config.js automatically (server-only endpoints are filtered out). **callableFrom** (beta.8 A4): per-endpoint marker — "client"/"server"/"both", or absent for auto-derive (apiKey → server, others → both). **countKeyWarnings**: advisory list of count-sentence bindings whose translation key does not resolve in every project language (empty array when all resolve). The edit is saved regardless. **auth.csrf**: optional on auth type "cookie" only — {"from": "cookie:XSRF-TOKEN", "to": "header:X-XSRF-TOKEN"} makes QS.fetch echo the named cookie into the named request header (the double-submit pattern used by the AUTHOR\'s own API, unrelated to QuickSite\'s session).'
    ],
    
    'deleteApi' => [
        'description' => 'Deletes an API group and all its endpoints. This action cannot be undone.',
        'method' => 'DELETE',
        'parameters' => [
            'apiId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API to delete (URL segment or body param)',
                'example' => 'old-api'
            ]
        ],
        'example_delete' => 'DELETE /management/p/<projectId>/deleteApi/old-api',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => "API 'old-api' deleted successfully",
            'data' => [
                'apiId' => 'old-api',
                'deletedEndpoints' => 5
            ]
        ],
        'error_responses' => [
            '400.api.error.missing_parameter' => 'Missing API ID',
            '404.api.error.not_found' => 'API not found'
        ],
        'notes' => 'Removes the API and all its endpoints from api-endpoints.json. Regenerates qs-api-config.js automatically. Cannot be undone - use backupProject first if needed.'
    ],
    
    'testApiEndpoint' => [
        'description' => 'Makes a real HTTP request to a registered external API endpoint for testing. Only registered endpoints can be tested - no arbitrary URLs allowed.',
        'method' => 'POST',
        'parameters' => [
            'endpointId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Endpoint to test',
                'example' => 'contact-submit'
            ],
            'apiId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'API filter (required if duplicate endpoint IDs exist)',
                'example' => 'main-backend'
            ],
            'testData' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Request body for POST/PUT/PATCH endpoints',
                'example' => '{"name": "Test User", "email": "test@example.com"}'
            ],
            'queryParams' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Query parameters for GET requests',
                'example' => '{"page": 1, "limit": 10}'
            ],
            'headers' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Additional headers to include',
                'example' => '{"X-Custom-Header": "value"}'
            ],
            'authToken' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Override auth token for testing',
                'example' => 'test-token-123'
            ],
            'timeout' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Request timeout in seconds (default: 30)',
                'example' => 10
            ],
            'pathParams' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Values for the ":placeholder" segments in the endpoint path. Substituted into the URL before any query parameters are appended.',
                'example' => '{"id": "42"}'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/testApiEndpoint with body: {"endpointId": "contact-submit", "testData": {"name": "Test", "email": "test@test.com"}}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'API endpoint test completed',
            'data' => [
                'endpoint' => ['id' => 'contact-submit', 'method' => 'POST', 'fullUrl' => 'https://api.example.com/contact'],
                'request' => [
                    'method' => 'POST',
                    'url' => 'https://api.example.com/contact',
                    'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ***'],
                    'body' => ['name' => 'Test', 'email' => 'test@test.com']
                ],
                'response' => [
                    'statusCode' => 200,
                    'statusText' => 'OK',
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['success' => true, 'id' => 123],
                    'isJson' => true
                ],
                'timing' => [
                    'duration_ms' => 245.3,
                    'duration_formatted' => '245ms'
                ]
            ]
        ],
        'error_responses' => [
            '400.api.error.missing_parameter' => 'Missing endpointId',
            '400.api.error.invalid_parameter' => 'Duplicate endpoint IDs - specify apiId',
            '404.api.error.not_found' => 'Endpoint not found',
            '500.api.error.request_failed' => 'HTTP request failed (timeout, connection error)',
            '400.api.error.blocked_url' => 'Endpoint URL blocked by SSRF policy.',
            '500.api.error.internal_error' => 'Error testing endpoint.',
            '502.api.error.external_request_failed' => 'The outbound request to the endpoint under test did not complete (DNS, TLS, connection or timeout). The transport error is returned in the message.'
        ],
        'notes' => 'This makes a server-side (PHP) request to the external API. Useful for testing auth configuration and endpoint availability without client-side CORS issues. Auth tokens are masked in response for security. PATH PARAMETERS: a value supplied in pathParams is substituted; an OPTIONAL one that is omitted has its path segment removed (along with the preceding segment when that segment is a literal equal to the parameter name — the key/value path-pair convention), so the request stays valid. A REQUIRED one that is omitted is deliberately left as the literal :name in the URL this command reports, because seeing it is how the omission surfaces in a test panel. Same rule as QS.fetch and the server-side resolver — qs_api_substitute_path() in src/functions/apiRegistry.php.'
    ],
    
    // ==========================================
    // SNIPPET MANAGEMENT
    // ==========================================
    
    'listSnippets' => [
        'description' => 'Lists all available snippets (core + project). Returns snippets organized by category with metadata for display in the Visual Editor.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/listSnippets',
        'success_response' => [
            'status' => 200,
            'code' => 'snippets.list_success',
            'message' => 'Snippets retrieved',
            'data' => [
                'snippets' => [
                    ['id' => 'navbar-basic', 'name' => 'Basic Navbar', 'category' => 'nav', 'description' => 'Basic navigation bar', 'isCore' => true],
                    ['id' => 'contact-form', 'name' => 'Contact Form', 'category' => 'forms', 'description' => 'Basic contact form', 'isCore' => true]
                ],
                'byCategory' => [
                    'nav' => [['id' => 'navbar-basic', 'name' => 'Basic Navbar', 'isCore' => true]],
                    'forms' => [['id' => 'contact-form', 'name' => 'Contact Form', 'isCore' => true]]
                ],
                'categories' => ['nav', 'forms', 'cards', 'layouts']
            ]
        ],
        'error_responses' => [
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.'
        ],
        'notes' => 'Core snippets are read-only and can be duplicated to project snippets. Project snippets can be edited and deleted.'
    ],
    
    'getSnippet' => [
        'description' => 'Gets a single snippet by ID with full structure, translations, and CSS (for core snippets). Used for preview and insertion.',
        'method' => 'GET',
        'parameters' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Snippet ID (e.g., navbar-basic)',
                'example' => 'navbar-basic'
            ]
        ],
        'example_get' => 'GET /management/p/<projectId>/getSnippet?id=navbar-basic',
        'success_response' => [
            'status' => 200,
            'code' => 'snippets.get_success',
            'message' => 'Snippet retrieved',
            'data' => [
                'id' => 'navbar-basic',
                'name' => 'Basic Navbar',
                'category' => 'nav',
                'description' => 'Basic navigation bar with logo and links',
                'isCore' => true,
                'structure' => ['tag' => 'nav', 'params' => ['class' => 'qs-snippet-navbar'], 'children' => []],
                'translations' => ['en' => ['snippet.navbar.home' => 'Home'], 'fr' => ['snippet.navbar.home' => 'Accueil']],
                'css' => '.qs-snippet-navbar { ... }'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing snippet id parameter',
            '404.snippet.not_found' => 'Snippet not found',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.snippets.id_required' => 'Snippet ID is required.',
            '404.snippets.not_found' => 'No snippet with that id exists in this project.'
        ],
        'notes' => 'The css field is only present for core snippets. User snippets use existing project classes.'
    ],
    
    'createSnippet' => [
        'description' => 'Creates a new user snippet in the project snippets folder. Used by "Save as Snippet" feature.',
        'method' => 'POST',
        'parameters' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Unique snippet ID (alphanumeric with dashes)',
                'example' => 'my-custom-nav'
            ],
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Display name for the snippet',
                'example' => 'My Custom Navigation'
            ],
            'category' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Category folder (nav, forms, cards, layouts, content, lists)',
                'example' => 'nav'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Optional description',
                'example' => 'Custom navigation with dropdown menus'
            ],
            'structure' => [
                'required' => true,
                'type' => 'object',
                'description' => 'The snippet structure (same format as component structure)',
                'example' => ['tag' => 'nav', 'children' => []]
            ],
            'translations' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Translation keys used in the snippet',
                'example' => ['en' => ['key' => 'value']]
            ],
            'scope' => [
                'required' => false,
                'type' => 'string',
                'description' => "Where the snippet is stored. 'project' (default) puts it in the marker project's own snippets folder, visible to every member of that project. 'personal' puts it in the CALLER'S OWN library (<secure>/snippets/custom/<userId>/), which they can reuse in any project they are a member of and which no other user can list, read, insert or delete. 'global' is accepted as a legacy alias of 'personal'.",
                'example' => 'personal'
            ]
        ],
        'notes' => [
            "A structure carrying a tag the renderer would refuse (a blocked tag, or one that is not on the allowlist) is rejected with 400 validation.blocked_tag — the same TagRegistry policy the renderer and the compiler enforce.",
            "The project written to is bound to the URL marker; a body 'project' that disagrees is refused with 400 project.mismatch.",
            'User snippets do not include a css field - they use existing project classes. Category folder is created if it does not exist.'
        ],
        'example_post' => 'POST /management/p/<projectId>/createSnippet with body: {"id": "my-nav", "name": "My Nav", "category": "nav", "structure": {"tag": "nav"}, "scope": "personal"}',
        'success_response' => [
            'status' => 201,
            'code' => 'snippets.create_success',
            'message' => 'Snippet created successfully',
            'data' => [
                'id' => 'my-nav',
                'path' => '<secure>/projects/quicksite/snippets/nav/my-nav.json'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing required field (id, name, category, structure)',
            '400.validation.invalid_format' => 'Invalid snippet ID format, or A component reference in the structure is not a bare component name. It must start with a letter, then letters, digits, hyphens and underscores, up to 64 characters - never a path. errors[0].expected states the rule.',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.snippets.id_required' => 'Snippet ID is required.',
            '400.snippets.invalid_id' => 'Snippet ID must start with a letter and contain only letters, numbers, dashes, and underscores.',
            '400.snippets.name_required' => 'Snippet name is required.',
            '400.snippets.structure_required' => 'Snippet structure is required.',
            '400.validation.blocked_tag' => 'Tag is not allowed (security restriction).',
            '409.snippets.already_exists' => 'A snippet with this ID already exists.',
            '500.snippets.save_failed' => 'The snippet passed validation but could not be written to the project. The underlying reason is returned in the message.'
        ]
    ],
    
    'deleteSnippet' => [
        'description' => 'Deletes a user snippet from the project. Core snippets cannot be deleted.',
        'method' => 'DELETE',
        'parameters' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Snippet ID to delete',
                'example' => 'my-custom-nav'
            ]
        ],
        'example_post' => 'DELETE /management/p/<projectId>/deleteSnippet?id=my-custom-nav',
        'success_response' => [
            'status' => 200,
            'code' => 'snippets.delete_success',
            'message' => 'Snippet deleted successfully',
            'data' => ['id' => 'my-custom-nav', 'deleted' => true]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing snippet id parameter',
            '403.snippet.core_protected' => 'Cannot delete core snippets (use duplicateSnippet first)',
            '404.snippet.not_found' => 'Snippet not found',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.snippets.id_required' => 'Snippet ID is required.',
            '403.snippets.cannot_delete_core' => 'Cannot delete core snippets. Use duplicateSnippet to create an editable copy.',
            '404.snippets.not_found' => 'No snippet with that id exists in this project.'
        ],
        'notes' => 'Only user snippets in the project folder can be deleted. Core snippets are protected.'
    ],
    
    'duplicateSnippet' => [
        'description' => 'Duplicates a core snippet to the project snippets folder, making it editable. Creates a copy with a new ID.',
        'method' => 'POST',
        'parameters' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'ID of the snippet to duplicate',
                'example' => 'navbar-basic'
            ],
            'newId' => [
                'required' => false,
                'type' => 'string',
                'description' => 'New ID for the duplicate (defaults to <id>-copy, then <id>-copy-2 and so on)',
                'example' => 'my-navbar'
            ],
            'newName' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Display name for the duplicate (defaults to "Copy of <source name>")',
                'example' => 'My Navbar'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/duplicateSnippet with body: {"id": "navbar-basic", "newId": "my-navbar"}',
        'success_response' => [
            'status' => 201,
            'code' => 'snippets.duplicate_success',
            'message' => 'Snippet duplicated successfully',
            'data' => [
                'sourceId' => 'navbar-basic',
                'newId' => 'my-navbar',
                'path' => '<secure>/projects/quicksite/snippets/nav/my-navbar.json'
            ]
        ],
        'error_responses' => [
            '400.snippets.id_required' => 'Missing id parameter',
            '404.snippet.not_found' => 'Source snippet not found',
            '409.snippet.exists' => 'Target snippet ID already exists',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.snippets.invalid_id' => 'New ID must start with a letter and contain only letters, numbers, dashes, and underscores.',
            '404.snippets.source_not_found' => 'Source snippet not found.',
            '409.snippets.already_exists' => 'A snippet with the requested new id already exists in this project.',
            '500.snippets.save_failed' => 'The copy could not be written to the project. The underlying reason is returned in the message.'
        ],
        'notes' => 'The duplicate is created without the css field (user snippets use project classes). Edit the structure JSON directly or use Visual Editor.'
    ],
    
    'insertSnippet' => [
        'description' => 'Inserts a snippet into a page or component structure. Generates unique textKeys for all nodes and merges translations into project files.',
        'method' => 'POST',
        'parameters' => [
            'snippetId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'ID of the snippet to insert',
                'example' => 'navbar-basic'
            ],
            'type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target structure type: page, component, menu, footer',
                'example' => 'page'
            ],
            'name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target name (page name or component name). Required for type page and component; ignored for menu and footer.',
                'example' => 'home'
            ],
            'targetNodeId' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Target node ID (dot-separated path like 0.2.1)',
                'example' => '0.2'
            ],
            'position' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Insertion position: inside, before, after. Default: after. Forced to inside when targetNodeId is the literal "root".',
                'example' => 'inside'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/insertSnippet with body: {"snippetId": "navbar-basic", "type": "page", "name": "home", "targetNodeId": "0", "position": "inside"}',
        'success_response' => [
            'status' => 200,
            'code' => 'snippets.inserted',
            'message' => 'Snippet inserted successfully',
            'data' => [
                'snippetId' => 'navbar-basic',
                'snippetName' => 'Basic Navbar',
                'newNodeId' => '0.1',
                'position' => 'after',
                'targetNodeId' => '0.0',
                'translationsAdded' => 3,
                'keyMapping' => ['snippet.navbar.home' => 'text_abc12345'],
                'html' => '<nav class="qs-snippet-navbar">...</nav>'
            ]
        ],
        'error_responses' => [
            '400.validation.missing_field' => 'Missing required parameter',
            '400.operation.failed' => 'Failed to insert snippet at the specified position',
            '404.snippet.not_found' => 'Snippet not found',
            '404.file.not_found' => 'Target structure file not found',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '400.project.required' => 'No project marker on the request. This command is project-scoped: target a project with /management/p/<projectId>/.',
            '400.snippets.no_structure' => 'Snippet has no structure defined.',
            '400.validation.invalid_format' => 'Structure too deeply nested (max 50 levels).',
            '400.validation.invalid_position' => 'Position must be: before, after, or inside.',
            '400.validation.invalid_type' => 'Invalid structure type. Must be: page, menu, footer, or component.',
            '400.validation.name_required' => 'Name is required for page/component structures.',
            '400.validation.snippet_required' => 'Snippet ID is required.',
            '400.validation.target_required' => 'Target node ID is required.',
            '404.snippets.not_found' => 'No snippet with that id exists in this project.',
            '404.structure.not_found' => 'Page structure not found. Structure file not found.',
            '500.server.file_write_failed' => 'Failed to write structure file.',
            '500.structure.invalid' => 'Invalid structure JSON.'
        ],
        'notes' => 'Each inserted node gets a unique textKey (e.g., text_abc12345) to prevent conflicts. Translations from the snippet are mapped to new keys and added to all project language files.'
    ],

    'injectSnippetCss' => [
        'description' => 'Injects a snippet\'s saved CSS into the stylesheet of the project in the URL marker. Two modes: "missing" adds only rules for selectors not found in the current stylesheet; "replace" removes existing matching rules and re-adds the snippet\'s full CSS block.',
        'method' => 'POST',
        'parameters' => [
            'id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Snippet ID to read CSS from',
                'example' => 'hero-centered'
            ],
            'mode' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Injection mode: "missing" (add only missing selectors) or "replace" (remove existing + re-add all)',
                'example' => 'missing'
            ],
            'project' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Echo-check only. The project written to is ALWAYS the one in the URL marker (/management/p/<projectId>/injectSnippetCss); this parameter cannot select a different one. Supply it and it must match the marker, or the call is refused with 400 project.mismatch.',
                'example' => 'my-project'
            ]
        ],
        'example_post' => 'POST /management/p/<projectId>/injectSnippetCss with body: {"id": "hero-centered", "mode": "missing"}',
        'success_response' => [
            'status' => 200,
            'code' => 'snippets.css_injected',
            'message' => 'CSS added successfully',
            'data' => [
                'injected' => ['.hero', '.hero-logo', '.hero-subtitle'],
                'mode' => 'missing',
                'snippetId' => 'hero-centered',
                'project' => 'my-project'
            ]
        ],
        'error_responses' => [
            '400.snippets.id_required' => 'Snippet ID is required',
            '400.snippets.invalid_mode' => 'Mode must be "missing" or "replace"',
            '400.snippets.no_css' => 'This snippet has no saved CSS to inject',
            '400.project.required' => 'No project targeted - use /management/p/<projectId>/injectSnippetCss',
            '404.snippets.not_found' => 'Snippet not found',
            '200.snippets.css_already_present' => 'All CSS selectors already exist in the project stylesheet.',
            '200.snippets.css_nothing_to_inject' => 'No CSS rules to inject.',
            '400.project.mismatch' => 'The project named in the body does not match the project in the URL marker. The marker decides; a disagreeing echo is refused rather than ignored.',
            '413.validation.size_limit_exceeded' => 'Injecting this snippet would take the project stylesheet past 512 KB, the ceiling every CSS writer enforces.',
            '500.server.file_write_failed' => 'Failed to write stylesheet.'
        ],
        'notes' => 'Writes to both the live public stylesheet and the project backup in <secure>/projects/. Includes :root variables referenced by injected rules. In "replace" mode, only global-scope rules are removed (media query rules are left untouched).'
    ],

    'getIframeSandbox' => [
        'description' => 'Returns the embed sandbox configuration for the project in the URL marker. Shows tag-based rules (tag → domain → sandbox permissions) and the default sandbox policy.',
        'method' => 'GET',
        'parameters' => [],
        'example_get' => 'GET /management/p/<projectId>/getIframeSandbox',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'data' => [
                'tags' => [
                    'iframe' => [
                        'youtube.com' => 'allow-scripts allow-same-origin',
                        'youtu.be' => 'allow-scripts allow-same-origin'
                    ],
                    'video' => (object)[],
                    'audio' => (object)[]
                ],
                'default' => '',
                'valid_permissions' => ['allow-scripts', 'allow-same-origin', 'allow-forms', '...'],
                'never_allowed' => ['allow-top-navigation', 'allow-top-navigation-by-user-activation', 'allow-popups-to-escape-sandbox'],
                'valid_tags' => ['iframe', 'video', 'audio']
            ]
        ],
        'error_responses' => [],
        'notes' => 'Empty default ("") means bare sandbox — blocks everything. Rules are organized by tag (iframe, video, audio) then by domain. Domain matching is CSP-style: hostname must equal the domain or end with .{domain}.'
    ],
    
    'setIframeSandbox' => [
        'description' => 'Add or update an embed sandbox rule for a tag + domain, or change the default sandbox policy. Admin only.',
        'method' => 'POST',
        'parameters' => [
            'tag' => '(string, required for rules) The embed tag: "iframe", "video", or "audio".',
            'domain' => '(string, required for rules) The domain to add/update, e.g. "youtube.com". Duplicate tag+domain combos overwrite the existing rule.',
            'sandbox' => '(string) Space-separated sandbox permissions, e.g. "allow-scripts allow-same-origin". Empty string = block all.',
            'default' => '(string, alternative) If provided without tag/domain, updates the default sandbox policy instead.'
        ],
        'example_post' => 'POST /management/p/<projectId>/setIframeSandbox with body: {"tag": "iframe", "domain": "youtube.com", "sandbox": "allow-scripts allow-same-origin"} - or, to change the default policy instead of a rule: {"default": "allow-scripts"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Sandbox rule added',
            'data' => [
                'tag' => 'iframe',
                'domain' => 'youtube.com',
                'sandbox' => 'allow-scripts allow-same-origin',
                'action' => 'added',
                'never_allowed_stripped' => null
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Missing tag or domain',
            '400.validation.invalid_value' => 'Invalid tag, domain, or unknown permission',
            '500.api.error.write_failed' => 'Failed to save iframe sandbox config.'
        ],
        'notes' => 'Valid embed tags: iframe, video, audio. Never-allowed permissions are always stripped silently. Subdomains are automatically covered: "youtube.com" matches www.youtube.com, m.youtube.com, etc.'
    ],
    
    'removeIframeSandbox' => [
        'description' => 'Remove an embed sandbox rule by tag + domain. Admin only.',
        'method' => 'POST',
        'parameters' => [
            'tag' => '(string) The embed tag: "iframe", "video", or "audio".',
            'domain' => '(string) The domain to remove from the tag\'s rules.'
        ],
        'example_post' => 'POST /management/p/<projectId>/removeIframeSandbox with body: {"tag": "iframe", "domain": "youtube.com"}',
        'success_response' => [
            'status' => 200,
            'code' => 'operation.success',
            'message' => 'Sandbox rule removed',
            'data' => [
                'tag' => 'iframe',
                'domain' => 'youtube.com',
                'removed_sandbox' => 'allow-scripts allow-same-origin',
                'remaining_rules' => 2
            ]
        ],
        'error_responses' => [
            '400.validation.required' => 'Must provide tag and domain',
            '400.validation.invalid_value' => 'Invalid embed tag',
            '404.operation.not_found' => 'No rule found for tag+domain',
            '500.api.error.write_failed' => 'Failed to save iframe sandbox config.'
        ],
        'notes' => 'Removes the sandbox rule for the specified tag and domain.'
    ],
];

/**
 * The routed command surface, read from its source rather than restated here.
 *
 * The two indexes this feeds — the command total and the category map — were
 * hand-maintained lists, and both fell behind the surface they described. A
 * derived index cannot disagree with what it counts: a command added to
 * routes.php and categories.php appears here with no edit to this file, and one
 * that has no documentation entry is REPORTED as undocumented rather than
 * quietly left out of the count.
 *
 * Both sources are shipped engine config, identical on every installation, so
 * nothing deployment-specific reaches the response.
 *
 * @return array{routed:string[], categories:array<string,array>}
 */
function __help_command_surface(): array {
    // The dispatcher has already loaded the routable allowlist for this
    // request; fall back to the file for an in-process caller that has not.
    if (defined('ROUTES_MANAGEMENT')) {
        $routed = ROUTES_MANAGEMENT;
    } else {
        $routesPath = SECURE_FOLDER_PATH . '/management/routes.php';
        $routed = file_exists($routesPath) ? require $routesPath : [];
    }
    $routed = is_array($routed) ? array_values(array_unique($routed)) : [];

    // loadCategoriesConfig() is the shared reader (AuthManagement.php); it is
    // what the permission check itself resolves a command through, so this
    // index and the authorization it describes cannot drift apart.
    if (!function_exists('loadCategoriesConfig')) {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
    }
    $categories = loadCategoriesConfig();

    $index = [];
    foreach ($categories as $name => $def) {
        $entry = ['scope' => $def['scope'] ?? 'project'];
        if (isset($def['access'])) {
            $entry['access'] = $def['access'];
        }
        $entry['commands'] = array_values($def['commands'] ?? []);
        $index[$name] = $entry;
    }

    return ['routed' => $routed, 'categories' => $index];
}

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

    // Build an array-form companion to `commands` for list-rendering
    // consumers (e.g. response bindings with renderMode:'componentList',
    // which require an array, not an object map). Keys promoted to a
    // `name` field; method lowercased so it matches the lowercase keys
    // most component __enums__ tables declare. Lossy on purpose — the
    // primary `commands` object map keeps the full per-command schema
    // for any tool that needs deep details.
    $commandsList = [];
    foreach ($commands as $cmdName => $cmdDef) {
        $methodLower = isset($cmdDef['method']) ? strtolower((string)$cmdDef['method']) : 'get';
        $exampleStr = '';
        if (isset($cmdDef['example_post']) && is_string($cmdDef['example_post'])) {
            $exampleStr = $cmdDef['example_post'];
        } elseif (isset($cmdDef['example_get']) && is_string($cmdDef['example_get'])) {
            $exampleStr = $cmdDef['example_get'];
        }
        $commandsList[] = [
            'name'        => $cmdName,
            'method'      => $methodLower,
            'description' => $cmdDef['description'] ?? '',
            'example'     => $exampleStr,
        ];
    }

    // The routable surface and its category map, derived from the same files the
    // dispatcher and the permission check read. `total` counts the entries this
    // response actually carries; `command_surface` says how that compares to the
    // routable set, so a command with no entry is named rather than silently
    // absent from both numbers.
    $surface = __help_command_surface();
    $documented = array_values(array_intersect($surface['routed'], array_keys($commands)));

    // Return all commands if no specific command requested
    return ApiResponse::create(200, 'operation.success')
        ->withMessage('All command documentation retrieved')
        ->withData([
            'commands' => $commands,
            'commandsList' => $commandsList,
            'total' => count($commands),
            // NAMES the constant, never its value. `help` answers before
            // authentication and must read identically on every installation, so
            // it states no deployment-specific fact: not the request origin, not
            // the folder space, not a resolved path. Also removes the last piece
            // of request-derived text in this payload — every byte below is a
            // compile-time literal, so nothing a caller sends can be reflected.
            'base_url' => '<PUBLIC_FOLDER_SPACE>/management',
            'base_url_note' => 'A placeholder, not a literal path: PUBLIC_FOLDER_SPACE is the subdirectory this installation serves its public files from, and it is empty by default. Compose requests against the origin and folder space you already used to reach this endpoint.',
            'command_surface' => [
                'routed' => count($surface['routed']),
                'documented' => count($documented),
                'undocumented' => array_values(array_diff($surface['routed'], array_keys($commands))),
                'note' => 'routed is every command the dispatcher will accept; documented is how many of those this response carries an entry for. undocumented names the difference and is empty when the two agree.'
            ],
            'command_categories' => $surface['categories'],
            'authentication' => [
                'required' => true,
                'login' => 'POST /management/login with {username, password} (users.php credentials) sets the session cookie and returns that session token',
                'cookie' => 'The session cookie QSSESSID is the credential - a command-line client needs a cookie jar (curl -c jar -b jar)',
                'header' => 'Authorization: Bearer <session_token> - required alongside the cookie; it proves the caller is the one the session was handed to, which is what keeps a cookie-authenticated API safe from cross-site request forgery',
                'session_token_format' => '64 hex characters; grants nothing on its own, and lives exactly as long as the session',
                'public_commands' => ['help', 'login', 'register'],
                'registration' => 'POST /management/register (public) creates an account when auth.php registration.allow_self_registration is true (default: false); flood-controlled; sign in afterwards via login',
                'role_system' => [
                    'note' => 'Roles are PER PROJECT (config/members.json), fixed set, no superadmin. Rank order: viewer < editor < designer < developer < admin < owner.',
                    'viewer' => 'Read-only access to content, structure, styles',
                    'editor' => 'Content editing - structure, translations, routes, assets, interactions',
                    'designer' => 'Style editing - CSS, animations, visual elements',
                    'developer' => 'Build and integration access - builds, APIs, storage, snippets',
                    'admin' => 'Project administration - backups, export/import, policies',
                    'owner' => 'Everything on the project, including membership (top of the project)'
                ],
                'introspection' => 'The role catalogue and your own effective role are read from the admin panel, not from a command: authorization introspection is a fact about your account rather than a step in developing a project. The commands this response documents are the ones you may run; a command you are not granted answers 403.',
                'config_file' => '<secure>/management/config/auth.php (session TTLs, self-registration gate, CORS)',
                'users_file' => '<secure>/management/config/users.php (username + password_hash per user)',
                'roles_config' => '<secure>/management/config/roles.php'
            ],
            'cors' => [
                'development_mode' => 'Allows localhost:* origins automatically',
                'config_file' => '<secure>/management/config/auth.php',
                'allowed_methods' => ['GET', 'POST', 'OPTIONS']
            ],
            'usage' => 'All requests require Authorization header. GET commands: help, getRoutes, getSiteMap, analyzeReachability, getStructure, getTranslation, getTranslations, getLangList, getTranslationKeys, validateTranslations, getUnusedTranslationKeys, analyzeTranslations, listAssets, getStyles, getRootVariables, listStyleRules, getStyleRule, getKeyframes, listComponents, getComponent, listPages, listAliases, listMembers. POST commands: all others.',
            'note' => 'For GET commands with URL parameters, use URL segments (e.g., /getStructure/menu, /validateTranslations/en, /getStyleRule/.btn-primary, /getSiteMap/text). For POST commands, send parameters as JSON in request body. For file uploads, use multipart/form-data encoding.',
            'workflows' => [
                'translation_workflow' => '1) analyzeTranslations for full health check, OR 2) validateTranslations to find missing, 3) getUnusedTranslationKeys to find orphans, 4) setTranslationKeys to add/update, 5) deleteTranslationKeys to clean up.',
                'asset_workflow' => '1) listAssets to see existing files with metadata, 2) uploadAsset to add new files (with optional description), 3) editAsset to rename files or update descriptions and alt text, 4) deleteAsset to remove files.',
                'style_workflow' => '1) getStyles to retrieve current CSS, 2) editStyles to update (response includes backup for rollback).',
                'css_granular_workflow' => '1) getRootVariables to see all CSS variables, 2) setRootVariables to update colors/spacing/etc, 3) listStyleRules to see all selectors, 4) getStyleRule to inspect specific rules, 5) setStyleRule to add/update rules, 6) deleteStyleRule to remove rules.',
                'animation_workflow' => '1) getKeyframes to list all animations, 2) setKeyframes to add/update animations, 3) deleteKeyframes to remove animations.',
                'session_workflow' => '1) login with username+password - the response sets the session cookie and returns the session token, 2) send BOTH on every later call: the cookie, and the token as Authorization: Bearer, 3) logoutSession to end the session, or logoutSession {everywhere: true} to end every session of the account.',
                'role_workflow' => 'There is no role-management workflow: the role set is FIXED (viewer, editor, designer, developer, admin, owner) and there are no custom roles, so createRole / editRole / deleteRole are denied to every role. Roles are per project - assign one when you invite somebody, change it with changeMemberRole, and read the catalogue or your own effective role from the admin panel rather than from a command.',
                'alias_workflow' => '1) listAliases to see existing redirects, 2) createAlias to add URL redirects, 3) deleteAlias to remove redirects.',
                'component_workflow' => '1) listComponents to see available reusable components, 2) getComponent?name=... to view full details with preview, 3) editStructure with type="component" to create/update/delete.',
                'sitemap_workflow' => '1) getSiteMap for JSON data with route details and coverage, 2) getSiteMap/text to generate plain text sitemap.txt for SEO crawlers.',
                'project_workflow' => '1) listProjects to see the projects you are a member of, 2) createProject to start a new one, 3) deleteProject to remove (requires confirm=true). There is no current project to set: every other project command names its target in the URL marker (/management/p/<projectId>/<command>), and every project is edited, previewed and served at /p/<projectId>/. No project is privileged - which project a production domain serves is a web-server mapping, not a command.',
                'membership_workflow' => '1) confirm the {user_id, name} pair by public display name (a panel lookup, not a command - user_id is what inviteMember takes), 2) inviteMember (admin/owner, by user_id) to offer a role, 3) the invitee answers from their membership inbox in the admin panel; nothing is granted until they accept, 4) manage the roster with listMembers / changeMemberRole / removeMember (cancelInvitation to withdraw an offer), 5) transferOwnership (owner-only, member target, confirm=true) to rotate the top role. Leaving a project yourself and clearing a refused/removed/deleted notice are account operations rather than commands - both live on your memberships page.',
                'join_request_workflow' => 'Front door (setJoinPolicy open): an outsider knocks from the admin panel with a mandatory note and a fixed viewer ask, and tracks it on their own memberships page - knocking, tracking and retracting are account operations, not commands. Admins/owner see the ask in listMembers (direction "request") and answer with approveJoinRequest (member immediately) or denyJoinRequest {note} (dismissable refused notice; re-request blocked until dismissed). Sponsor lane (any member, policy-independent): proposeMember {user_id, role, note} - the person learns NOTHING until approveJoinRequest converts it into a real invitation (by = approver, sponsored_by = you), which they answer themselves; denyJoinRequest removes it silently on their side.',
                'backup_workflow' => '1) backupProject to create instant backup, 2) listBackups to see available backups with size/age info, 3) restoreBackup to restore from backup (optional pre-restore backup), 4) deleteBackup to free disk space.',
                'export_workflow' => '1) exportProject to create shareable ZIP (JSON-only, secure), 2) downloadExport to download the ZIP, 3) importProject to import from ZIP (rebuilds PHP from JSON), 4) clearExports to clean up old exports.'
            ]
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    $urlSegments = $trimParametersManagement->additionalParams();
    __command_help([], $urlSegments)->send();
}