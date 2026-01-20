<?php

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
        
        // Server errors (5xx)
        500 => [
            'server.file_write_failed' => 'Failed to write file',
            'server.directory_create_failed' => 'Failed to create directory',
            'server.internal_error' => 'Internal server error',
            'asset.move_failed' => 'Failed to move uploaded file',       
            'asset.delete_failed' => 'Failed to delete file',            
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
            $instance->data = $data;
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
        $instance->message = $message;
        return $instance;
    }

    /**
     * Set response data/payload
     */
    public function withData(array $data): self {
        $this->data = $data;
        return $this;
    }

    /**
     * Set validation errors
     */
    public function withErrors(array $errors): self {
        $this->errors = $errors;
        return $this;
    }

    /**
     * Override the default message
     */
    public function withMessage(string $message): self {
        $this->message = $message;
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
        
        http_response_code($this->status);
        header('Content-Type: application/json');
        
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
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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