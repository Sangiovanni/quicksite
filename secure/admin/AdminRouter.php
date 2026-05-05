<?php
/**
 * QuickSite Admin Panel Router
 * 
 * Handles URL routing for the admin panel.
 * Routes are parsed from URL segments: /admin/{page}/{command}/{params...}
 * 
 * @version 1.6.0
 */

class AdminRouter {
    private string $page = 'login';
    private string $command = '';
    private array $params = [];
    private ?string $workflowId = null;  // For workflow routing
    private array $validPages = [
        'login',       // Authentication page
        'dashboard',   // Main admin panel after login
        'command',     // Individual command pages
        'settings',    // Settings and configuration
        'workflows',   // Workflows (AI and manual)
        'ai-settings', // Legacy alias (redirects to ai-connections)
        'ai-connections', // AI Connections (cloud BYOK + local AI)
        'embed-security', // Embed Security (iframe sandbox)
        'preview',     // Visual Editor (route kept as 'preview' for URL compatibility)
        'apis',        // External API Registry
        'assets',      // Asset Management page
        'sitemap',     // Visual Sitemap & Route Management
        'optimize',    // Optimization Tools
        'logout'       // Logout action
    ];

    public function __construct() {
        $this->parseUrl();
    }

    /**
     * Parse the URL to extract page, command, and parameters
     */
    private function parseUrl(): void {
        $requestUri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        
        // Remove public folder space prefix if set
        $folder = defined('PUBLIC_FOLDER_SPACE') ? PUBLIC_FOLDER_SPACE : '';
        if ($folder) {
            $requestUri = preg_replace('#^' . preg_quote(trim($folder, '/'), '#') . '/?#', '', $requestUri);
        }
        
        $parts = explode('/', $requestUri);
        
        // Remove 'admin' prefix
        if (!empty($parts) && $parts[0] === 'admin') {
            array_shift($parts);
        }
        
        // First segment is the page
        if (!empty($parts) && $parts[0] !== '') {
            $this->page = array_shift($parts);
        }
        
        // For command pages, second segment is the command name
        if ($this->page === 'command' && !empty($parts)) {
            $this->command = array_shift($parts);
        }
        
        // For workflow pages, second segment is the workflow ID (if present)
        // Can be: {workflowId}, 'new', or 'edit/{workflowId}'
        if ($this->page === 'workflows' && !empty($parts)) {
            $workflowPart = array_shift($parts);
            
            // Handle edit/{workflowId} pattern
            if ($workflowPart === 'edit' && !empty($parts)) {
                $this->workflowId = 'edit/' . array_shift($parts);
            } else {
                $this->workflowId = $workflowPart;
            }
        }
        
        // Remaining segments are parameters
        $this->params = $parts;
    }

    /**
     * Get current page
     */
    public function getPage(): string {
        return $this->page;
    }

    /**
     * Get current command (for command pages)
     */
    public function getCommand(): string {
        return $this->command;
    }

    /**
     * Get workflow ID (for /admin/workflows/{workflowId} routes)
     */
    public function getWorkflowId(): ?string {
        return $this->workflowId;
    }
    
    // Legacy alias for backward compatibility
    public function getSpecId(): ?string {
        return $this->workflowId;
    }

    /**
     * Get URL parameters
     */
    public function getParams(): array {
        return $this->params;
    }

    /**
     * Check if user is authenticated (has valid token in session/cookie)
     */
    public function isAuthenticated(): bool {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if token exists in session
        if (empty($_SESSION['admin_token'])) {
            return false;
        }
        
        // Validate token against auth.php
        $token = $_SESSION['admin_token'];
        $authConfigPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
        
        if (!file_exists($authConfigPath)) {
            return false;
        }
        
        $authConfig = include $authConfigPath;
        
        // Check if token exists in config
        if (!isset($authConfig['authentication']['tokens'][$token])) {
            // Invalid token - clear session
            unset($_SESSION['admin_token']);
            return false;
        }
        
        return true;
    }

    /**
     * Get stored token
     */
    public function getToken(): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return $_SESSION['admin_token'] ?? null;
    }

    /**
     * Get the role of the currently authenticated token.
     * Returns '*' for superadmin, a role slug (e.g. 'viewer') for named roles,
     * or null when not authenticated.
     */
    public function getTokenRole(): ?string {
        $token = $this->getToken();
        if (!$token) return null;

        $authConfigPath = SECURE_FOLDER_PATH . '/management/config/auth.php';
        if (!file_exists($authConfigPath)) return null;

        $authConfig = include $authConfigPath;
        return $authConfig['authentication']['tokens'][$token]['role'] ?? null;
    }

    /**
     * Pages that require at least one specific command in the token's role.
     * Superadmin ('*') always passes. Any other role must have at least one listed command.
     * Pages not listed here are open to all authenticated users.
     */
    private const PAGE_PERMISSIONS = [
        'assets'         => ['listAssets', 'uploadAsset'],
        'sitemap'        => ['getSiteMap', 'addRoute'],
        'optimize'       => ['getStyles', 'editStyles'],
        // 'ai-connections' has no permission gate: it is a UI over
        // browser-stored data. Any authenticated admin can view it.
        // (Old 'ai-settings' route 301-redirects to it in dispatch().)
        'apis'           => ['listApiEndpoints'],
        'embed-security' => ['getIframeSandbox'],
        // 'workflows' is no longer gated by callAi: AI calls now happen in
        // the browser via QSAiCall (no PHP proxy). Any admin user can open
        // the workflows UI.
    ];

    /**
     * Check whether the current token may access the requested page.
     */
    public function canAccessPage(string $page): bool {
        if (!isset(self::PAGE_PERMISSIONS[$page])) return true;

        $role = $this->getTokenRole();
        if ($role === null) return false;
        if ($role === '*') return true;

        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $commands = getRoleCommands($role) ?? [];
        foreach (self::PAGE_PERMISSIONS[$page] as $cmd) {
            if (in_array($cmd, $commands, true)) return true;
        }
        return false;
    }

    /**
     * Store authentication token
     */
    public function setToken(string $token, bool $remember = false): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['admin_token'] = $token;
        
        // If remember is checked, set a longer-lived cookie
        if ($remember) {
            setcookie('admin_token', $token, [
                'expires' => time() + (30 * 24 * 60 * 60), // 30 days
                'path' => '/admin',
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
    }

    /**
     * Clear authentication
     */
    public function clearToken(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION['admin_token']);
        
        // Also clear cookie
        setcookie('admin_token', '', [
            'expires' => time() - 3600,
            'path' => '/admin',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    /**
     * Restore token from cookie if session is empty
     */
    private function restoreTokenFromCookie(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['admin_token']) && !empty($_COOKIE['admin_token'])) {
            $_SESSION['admin_token'] = $_COOKIE['admin_token'];
        }
    }

    /**
     * Get the base URL for the admin panel
     */
    public function getBaseUrl(): string {
        return rtrim(BASE_URL, '/') . '/admin';
    }

    /**
     * Get the management API base URL
     */
    public function getApiUrl(): string {
        return rtrim(BASE_URL, '/') . '/management';
    }

    /**
     * Generate a URL for an admin page
     */
    public function url(string $page, string $command = '', array $params = []): string {
        $url = $this->getBaseUrl() . '/' . $page;
        
        if ($command) {
            $url .= '/' . $command;
        }
        
        if (!empty($params)) {
            $url .= '/' . implode('/', $params);
        }
        
        return $url;
    }

    /**
     * Redirect to another admin page
     */
    public function redirect(string $page, string $command = '', array $params = []): void {
        header('Location: ' . $this->url($page, $command, $params));
        exit;
    }

    /**
     * Dispatch the request to the appropriate handler
     */
    public function dispatch(): void {
        // Restore token from cookie if available
        $this->restoreTokenFromCookie();
        
        // Handle logout
        if ($this->page === 'logout') {
            $this->clearToken();
            $this->redirect('login');
        }

        // Legacy: ai-settings -> ai-connections (Phase 3 rename).
        if ($this->page === 'ai-settings') {
            $this->redirect('ai-connections');
        }
        
        // Check authentication for protected pages
        if (!in_array($this->page, ['login']) && !$this->isAuthenticated()) {
            $this->redirect('login');
        }
        
        // If already authenticated and trying to access login, go to dashboard
        if ($this->page === 'login' && $this->isAuthenticated()) {
            $this->redirect('dashboard');
        }

        // Check page-level permissions (role-based access control)
        if ($this->isAuthenticated() && !$this->canAccessPage($this->page)) {
            $this->redirect('dashboard?denied=1');
        }
        
        // Load the appropriate template
        $this->render();
    }

    /**
     * Render the current page
     */
    private function render(): void {
        // Load admin functions
        require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';
        require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTranslation.php';
        
        // Initialize translation helper
        $lang = AdminTranslation::getInstance();
        
        // Pass router to templates
        $router = $this;
        
        // Determine which template to load
        $templatePath = SECURE_FOLDER_PATH . '/admin/templates/pages/' . $this->page . '.php';
        
        // Special handling for workflows with workflowId
        if ($this->page === 'workflows' && $this->workflowId !== null) {
            // Check for editor routes (new or edit/*)
            if ($this->workflowId === 'new' || str_starts_with($this->workflowId, 'edit/')) {
                $editorPath = SECURE_FOLDER_PATH . '/admin/templates/pages/workflows/editor.php';
                if (file_exists($editorPath)) {
                    $templatePath = $editorPath;
                }
            } else {
                // Load workflow-specific template for viewing
                $workflowTemplatePath = SECURE_FOLDER_PATH . '/admin/templates/pages/workflows/spec.php';
                if (file_exists($workflowTemplatePath)) {
                    $templatePath = $workflowTemplatePath;
                }
            }
        } elseif ($this->page === 'workflows' && $this->workflowId === null) {
            // Load workflows index (browser) if it exists
            $indexPath = SECURE_FOLDER_PATH . '/admin/templates/pages/workflows/index.php';
            if (file_exists($indexPath)) {
                $templatePath = $indexPath;
            }
        }
        
        if (!file_exists($templatePath)) {
            // Show 404 page
            http_response_code(404);
            $templatePath = SECURE_FOLDER_PATH . '/admin/templates/pages/404.php';
        }
        
        // Load the layout with the page content
        require_once SECURE_FOLDER_PATH . '/admin/templates/layout.php';
    }
}
