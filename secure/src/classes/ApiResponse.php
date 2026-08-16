<?php

// beta.10 C12 12.5 (F-C12-4, F-C13-18). Every path that leaves a command leaves
// through one of the setters below, so the install layout is stripped HERE
// rather than at the ~48 sites that build one. A per-site fix is one whose
// completeness cannot be proven — the static scan for those sites produced both
// false positives ('files' => a COUNT) and false negatives (ZipUtilities'
// zip_path, setupToken's path) — and it would miss the getData()/toArray()
// readers that /admin/api and CommandRunner use instead of send().
require_once __DIR__ . '/../functions/publicPaths.php';

class ApiResponse {
    private $status;
    private $code;
    private $message;
    private $data;
    private $errors;
    
    // Logging callback - called before send
    private static $beforeSendCallback = null;

    // Registry of standard responses
    private static $registry = [
        // Success responses (2xx)
        201 => [
            'operation.success' => 'Operation completed successfully',
            'route.created' => 'Route successfully created',
            'menu.option.added' => 'Menu option added successfully',
            'footer.option.added' => 'Footer option added successfully',
        ],
        200 => [
            'route.retrieved' => 'Route retrieved successfully',
            'operation.success' => 'Operation completed successfully',
        ],
        204 => [
            'operation.success' => 'Operation completed successfully',
            'route.deleted' => 'Route deleted successfully',
            'menu.option.deleted' => 'Menu option deleted',
        ],
        // 207: the operation partly succeeded. Used by sweeps that act on a SET
        // — some members done, some refused — where 200 would overstate and 5xx
        // would understate. `deleteBuild` and `cleanBuilds` have emitted it since
        // before this entry existed, which meant every partial failure wrote
        // "Warning: Unregistered response code: 207.operation.partial_success"
        // to the error log, and any caller that did not pass its own
        // withMessage() would have been handed "Unknown response". Registering
        // it fixes both, for them as well as for clearExports.
        207 => [
            'operation.partial_success' => 'Operation partially completed',
        ],

        // Client errors (4xx)
        400 => [
            'validation.required' => 'Required parameter missing',
            'validation.invalid_format' => 'Invalid format provided',
            'route.already_exists' => 'Route already exists',
            'route.invalid_name' => 'Invalid route name',
            'asset.invalid_category' => 'Invalid asset category',        
            'asset.invalid_filename' => 'Invalid filename',              
            'asset.invalid_file_type' => 'Invalid file type',            
            'asset.invalid_extension' => 'Invalid file extension',       
            'asset.file_too_large' => 'File exceeds maximum size',       
            'asset.upload_failed' => 'File upload failed',               
        ],
        404 => [
            'route.not_found' => 'Route not found',
            'file.not_found' => 'File not found',
            'asset.not_found' => 'Asset file not found',
            'node.not_found' => 'Node not found at specified path',
        ],
        403 => [
            'auth.forbidden' => 'Access denied',
            'mode.requires_multilingual' => 'This command requires MULTILINGUAL_SUPPORT = true',
            'mode.requires_mono' => 'This command requires MULTILINGUAL_SUPPORT = false',
            'feature.disabled' => 'This feature is disabled in current configuration',
        ],
        409 => [
            'conflict.duplicate' => 'Resource already exists',
        ],
        // 413: this REQUEST is too big. Both are emitted by the upload paths —
        // `request.body_too_large` when PHP discarded the body over
        // post_max_size, `validation.size_limit_exceeded` when an archive
        // exceeds the import limits. Every emitter passes its own withMessage()
        // with the real numbers; these are the fallbacks, and registering them
        // stops the "Unregistered response code" line each refusal wrote.
        413 => [
            'request.body_too_large'         => 'Request body too large',
            'validation.size_limit_exceeded' => 'Size limit exceeded',
        ],
        // 429: a rate limit refused the caller for now, not forever. Every
        // emitter puts the wait in `retry_after` (seconds) — the auth throttles
        // in SessionManagement.php and the per-user upload rate in quota.php.
        429 => [
            'auth.throttled'     => 'Too many attempts - retry later',
            'quota.rate_limited' => 'Too many uploads - retry later',
        ],

        // Server errors (5xx)
        500 => [
            'server.file_write_failed' => 'Failed to write file',
            'server.directory_create_failed' => 'Failed to create directory',
            'server.internal_error' => 'Internal server error',
            'asset.move_failed' => 'Failed to move uploaded file',
            'asset.delete_failed' => 'Failed to delete file',
            // Emitted by deleteProject and clearExports; registering it stops
            // the "Unregistered response code" line those already write.
            'server.delete_failed' => 'Failed to delete',
        ],
        // 507: the request is well-formed and permitted, and the server will not
        // store it — a per-user quota, not a fault and not a size problem with
        // this particular request (413 covers that).
        507 => [
            'quota.storage_exceeded' => 'Storage quota exceeded',
        ],
        503 => [
            'server.unavailable' => 'Service temporarily unavailable',
        ],
    ];

    /**
     * Create a response with a registered code
     */
    public static function create(int $status, string $code): self {
        $instance = new self();
        $instance->status = $status;
        $instance->code = $code;
        
        // Auto-set message from registry
        if (isset(self::$registry[$status][$code])) {
            $instance->message = self::$registry[$status][$code];
        } else {
            error_log("Warning: Unregistered response code: {$status}.{$code}");
            $instance->message = "Unknown response";
        }
        
        return $instance;
    }

    /**
     * Shortcut for success response (200 OK)
     */
    public static function success(?array $data = null): self {
        $instance = self::create(200, 'operation.success');
        if ($data !== null) {
            $instance->data = qs_scrub_paths($data);
        }
        return $instance;
    }

    /**
     * Create a custom response (not in registry)
     */
    public static function custom(int $status, string $code, string $message): self {
        $instance = new self();
        $instance->status = $status;
        $instance->code = $code;
        $instance->message = qs_scrub_path_string($message);
        return $instance;
    }

    /**
     * Set response data/payload
     *
     * Scrubbed at SET time, not at send() time, so the three ways a response
     * leaves a command all see the same public view: send() on /management,
     * getData() on /admin/api's internal relay, and toArray() in CommandRunner.
     */
    public function withData(array $data): self {
        $this->data = qs_scrub_paths($data);
        return $this;
    }

    /**
     * Set validation errors
     */
    public function withErrors(array $errors): self {
        $this->errors = qs_scrub_paths($errors);
        return $this;
    }

    /**
     * Override the default message
     *
     * Messages are interpolated ("Failed to copy file: {$destPath}"), so the
     * message carries paths as often as the data does.
     */
    public function withMessage(string $message): self {
        $this->message = qs_scrub_path_string($message);
        return $this;
    }

    /**
     * Set a callback to be executed before send (for logging)
     */
    public static function setBeforeSendCallback(callable $callback): void {
        self::$beforeSendCallback = $callback;
    }

    /**
     * Get response info without sending (for logging)
     */
    public function getResponseInfo(): array {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message
        ];
    }

    /**
     * Get the HTTP status code
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Get the response code string
     */
    public function getCode(): string {
        return $this->code;
    }

    /**
     * Get response data
     */
    public function getData(): ?array {
        return $this->data;
    }

    /**
     * Convert response to array (for internal use without HTTP)
     */
    public function toArray(): array {
        $response = [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message,
        ];
        
        if (!empty($this->data)) {
            $response['data'] = $this->data;
        }
        
        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }
        
        return $response;
    }

    /**
     * Send the JSON response and exit
     */
    public function send(): void {
        // Call logging callback if set
        if (self::$beforeSendCallback !== null) {
            call_user_func(self::$beforeSendCallback, $this->status, $this->code);
        }
        
        // Clear any output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $response = [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (!empty($this->data)) {
            $response['data'] = $this->data;
        }

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        // beta.10 C13 F-C13-14(b): SERIALISE FIRST, WRITE SECOND.
        //
        // The status and Content-Type used to be sent before json_encode ran. On a
        // large payload the encode is exactly where the memory ceiling is reached,
        // and errorHygiene's fatal handler bails once headers_sent() is true — so a
        // fatal here landed in a window no handler could cover, and the client got
        // a 200 whose body was raw PHP error text. Building the body first puts
        // that failure back BEFORE the first byte, where the handler still works.
        $body = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            // The envelope itself could not be encoded (malformed UTF-8 in the
            // data, or depth > 512). Echoing false would have written an EMPTY
            // body under the original status — a 200 that says nothing. Answer
            // 500 with a payload that is guaranteed to encode.
            error_log('ApiResponse::send: response could not be serialised ('
                . json_last_error_msg() . ') for code ' . $this->code);
            http_response_code(500);
            header('Content-Type: application/json');
            echo '{"status":500,"code":"server.internal_error",'
               . '"message":"Response could not be serialised"}';
            exit;
        }

        http_response_code($this->status);
        header('Content-Type: application/json');
        echo $body;
        exit;
    }

    /**
     * Get all registered response codes (for documentation/UI)
     */
    public static function getAllCodes(): array {
        $formatted = [];
        foreach (self::$registry as $status => $codes) {
            foreach ($codes as $code => $message) {
                $formatted[] = [
                    'status' => $status,
                    'code' => $code,
                    'message' => $message,
                ];
            }
        }
        return $formatted;
    }

    /**
     * Export registry as JSON (for front-end consumption)
     */
    public static function exportRegistry(): string {
        return json_encode(self::$registry, JSON_PRETTY_PRINT);
    }
}