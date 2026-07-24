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
        'register',    // Self-registration page (C8; renders only when auth.php allows it)
        'dashboard',   // Main admin panel after login
        'command',     // Individual command pages
        'settings',    // Settings and configuration
        'workflows',   // Workflows (AI and manual)
        'ai-settings', // Legacy alias (redirects to ai-connections)
        'ai-connections', // AI Connections (cloud BYOK + local AI)
        'embed-security', // Embed Security (iframe sandbox)
        'preview',     // Visual Editor (route kept as 'preview' for URL compatibility)
        'apis',        // External API Registry
        'oauth-providers', // OAuth Provider catalogue + per-project overrides (beta.9 A1 Slice 8)
        'storage',     // Storage registry — GDPR / cookie-consent data layer (beta.9)
        'privacy',     // Privacy helper — data-sharing / API surface (beta.9)
        'memberships', // My Memberships — inbox / requests / proposals / notices (C8 8.3c; any authenticated user)
        'members',     // Project Members — roster / queue / invite / policy for the EDITED project (C8 8.3c)
        'assets',      // Asset Management page
        'sitemap',     // Visual Sitemap & Route Management
        'optimize',    // Optimization Tools
        'logout',      // Logout action
        'session-refresh' // C5b: JSON endpoint — rotates the PHP-held session, returns a fresh access token
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
     * The panel's session model (C5b): PHP $_SESSION is the SINGLE holder of the
     * access + refresh pair — the refresh token never reaches the browser's JS
     * (only the short-lived access token is page-embedded). One holder = one
     * rotation actor = no false theft signals; PHP's per-session file locking
     * serializes concurrent tabs by construction. "Remember me" persists the
     * CURRENT refresh token in an HttpOnly `qs_refresh` cookie (re-set on every
     * rotation) so a fresh PHP session can re-establish itself.
     */

    /**
     * Check if user is authenticated (valid, store-backed access token in the
     * PHP session — rotating in-process when it is about to expire).
     */
    public function isAuthenticated(): bool {
        $this->ensureFreshSession();
        $access = $_SESSION['admin_token'] ?? null;
        if (!is_string($access) || $access === '') {
            return false;
        }
        // Store-backed check (catches a family revoked elsewhere, e.g. reuse
        // detection or a management-API logout of the same session) AND the
        // user-level check (L10 disabled, vanished account).
        //
        // C8 8.2 / F-C8-8.2-1: this used to call qs_session_validate_access,
        // which only inspects token/family/expiry and never resolves the user —
        // so a DISABLED account kept rendering every page absent from
        // PAGE_PERMISSIONS (dashboard, command, memberships) until its access
        // token expired, even though the management API, the login gate and the
        // refresh boundary all refused it. validateBearerToken is the single
        // path that already does both checks (it is what getTokenRole uses), so
        // routing through it keeps ONE status-check implementation.
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        if (!validateBearerToken('Bearer ' . $access)['valid']) {
            $this->clearSessionAuth();
            return false;
        }
        return true;
    }

    /**
     * Make sure the session's access token has at least $margin seconds left,
     * rotating in-process (via the session's refresh token, then the qs_refresh
     * remember-me cookie) when it does not. Clears dead state on failure.
     */
    private function ensureFreshSession(int $margin = 120): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        $access  = $_SESSION['admin_token'] ?? null;
        $expires = (int)($_SESSION['admin_token_expires'] ?? 0);
        if (is_string($access) && $access !== '' && time() < $expires - $margin) {
            return; // still fresh
        }

        // Stale/expired: rotate with the session's own refresh token first.
        $refresh = $_SESSION['admin_refresh'] ?? null;
        if (is_string($refresh) && $refresh !== '') {
            $rotated = qs_session_rotate($refresh);
            if ($rotated['ok'] ?? false) {
                $this->storeSessionPair($rotated);
                return;
            }
        }

        // Fall back to the remember-me cookie (fresh PHP session after browser
        // restart / session GC). A dead cookie is cleared so we stop retrying it.
        $cookie = $_COOKIE['qs_refresh'] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            $rotated = qs_session_rotate($cookie);
            if ($rotated['ok'] ?? false) {
                $_SESSION['admin_remember'] = true;
                $this->storeSessionPair($rotated);
                return;
            }
            $this->clearRefreshCookie();
        }

        $this->clearSessionAuth();
    }

    /**
     * Persist a freshly issued/rotated pair into the PHP session (and, when the
     * user chose "remember me", roll the qs_refresh cookie forward — rotation
     * retires the token the cookie previously held).
     */
    private function storeSessionPair(array $pair): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_token']           = $pair['access_token'];
        $_SESSION['admin_token_expires']   = (int)$pair['access_expires'];
        $_SESSION['admin_refresh']         = $pair['refresh_token'];
        $_SESSION['admin_refresh_expires'] = (int)$pair['refresh_expires'];

        if (!empty($_SESSION['admin_remember'])) {
            setcookie('qs_refresh', $pair['refresh_token'], [
                'expires'  => (int)$pair['refresh_expires'],
                'path'     => rtrim(parse_url($this->getBaseUrl(), PHP_URL_PATH) ?: '/admin', '/') ?: '/admin',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
    }

    /**
     * Drop the panel's auth state from the PHP session (tokens only — the rest
     * of the session, e.g. admin language, survives).
     */
    private function clearSessionAuth(): void {
        unset(
            $_SESSION['admin_token'],
            $_SESSION['admin_token_expires'],
            $_SESSION['admin_refresh'],
            $_SESSION['admin_refresh_expires'],
            $_SESSION['admin_remember']
        );
    }

    private function clearRefreshCookie(): void {
        setcookie('qs_refresh', '', [
            'expires'  => time() - 3600,
            'path'     => rtrim(parse_url($this->getBaseUrl(), PHP_URL_PATH) ?: '/admin', '/') ?: '/admin',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    /**
     * Attempt a username + password login (C5b; username identity C8 8.0b) —
     * the panel form's entry into the ONE shared credential-check + issuance
     * path (qs_auth_attempt_login). On success the pair lands in the PHP
     * session; with $remember the refresh token also lands in the HttpOnly
     * qs_refresh cookie.
     *
     * @return string|null null on success, else an error key:
     *                     'invalid_credentials' | 'throttled:<seconds>' | 'server'
     */
    public function attemptLogin(string $username, string $password, bool $remember = false): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        // Distinguish an empty submission (stale cached form, autofill mishap)
        // from wrong credentials — a real diagnostic for the user, and empty
        // probes are not brute force. The management `login` command 400s the
        // same case with validation.required.
        if (trim($username) === '' || $password === '') {
            return 'missing_fields';
        }

        $attempt = qs_auth_attempt_login($username, $password);
        if (!$attempt['ok']) {
            if ($attempt['error'] === 'throttled') {
                return 'throttled:' . (int)($attempt['retry_after'] ?? 60);
            }
            return $attempt['error'];
        }

        session_regenerate_id(true); // fresh session id on privilege change
        $_SESSION['admin_remember'] = $remember;
        $this->storeSessionPair($attempt['session']);
        if (!$remember) {
            $this->clearRefreshCookie();
        }
        // Expire the pre-C5b raw-token cookie if a stale one is still around.
        if (!empty($_COOKIE['admin_token'])) {
            setcookie('admin_token', '', ['expires' => time() - 3600, 'path' => '/admin', 'httponly' => true, 'samesite' => 'Strict']);
        }
        return null;
    }

    /**
     * Is self-registration currently allowed (auth.php
     * registration.allow_self_registration)? Drives the register page's
     * existence and the login page's register link (C8).
     */
    public function isRegistrationOpen(): bool {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        return qs_registration_config()['allow_self_registration'];
    }

    /**
     * Attempt a self-registration (C8) — the register page's entry into the
     * ONE shared gate (qs_auth_attempt_register, also behind the public
     * `register` command). On success a one-shot session flash is set for the
     * login page's "account created" banner. A duplicate username reports
     * success exactly like the command (login identifiers are private — no
     * account oracle).
     *
     * @return string|null null on success, else an error key:
     *                     'registration_disabled' | 'registration_closed' |
     *                     'missing_fields' | 'invalid_username' |
     *                     'name_equals_username' | 'password_too_short:<min>' |
     *                     'throttled:<seconds>' | 'server'
     */
    public function attemptRegister(string $name, string $username, string $password): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        if (trim($name) === '' || trim($username) === '' || $password === '') {
            return 'missing_fields';
        }

        $attempt = qs_auth_attempt_register($name, $username, $password);
        if ($attempt['ok']) {
            $_SESSION['qs_register_flash'] = true;
            return null;
        }
        if ($attempt['error'] === 'throttled') {
            return 'throttled:' . (int)($attempt['retry_after'] ?? 60);
        }
        if ($attempt['error'] === 'password_too_short') {
            return 'password_too_short:' . (int)($attempt['min_length'] ?? 12);
        }
        return $attempt['error'];
    }

    /**
     * Get the current ACCESS token (short-lived). This is what layout.php embeds
     * for the admin JS and what the qs_preview cookie carries — never the
     * refresh token.
     */
    public function getToken(): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION['admin_token'] ?? null;
    }

    /**
     * Seconds until the current access token expires (0 when unauthenticated).
     * Emitted to the admin JS so its keepalive can schedule the next refresh.
     */
    public function getTokenExpiresIn(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return max(0, (int)($_SESSION['admin_token_expires'] ?? 0) - time());
    }

    /**
     * Get the effective role of the currently authenticated user (C6).
     * Resolves the session's access token -> user -> role on the project the
     * panel actually acts as: `resolveEffectiveRole` = the selected project when
     * that membership is real, ELSE the first project the user is genuinely a
     * member of. This is the SAME resolution `getMyPermissions` /
     * `getTokenPermissions` (the client-side permission filter) and
     * `getCurrentProject` (resolveDefaultProject) use — so the server-side page
     * gate agrees with the nav links the client shows and with the project the
     * page operates on. A freshly-joined user who never called setSelectedProject
     * (empty selected_project) still resolves their role instead of being locked
     * out of every role-gated page. Returns a role slug ('viewer' … 'owner') or
     * null when not authenticated, unresolved, disabled, or a member of nothing.
     * No superadmin / no '*'.
     */
    public function getTokenRole(): ?string {
        $token = $this->getToken();
        if (!$token) return null;

        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $auth = validateBearerToken('Bearer ' . $token);
        if (empty($auth['valid'])) return null;

        return resolveEffectiveRole($auth['user']);
    }

    /**
     * The project THIS user is EDITING = their per-user `selected_project`
     * (resolveDefaultProject: selected_project when the membership is real, else their
     * first real membership).
     *
     * "Switching project" (header picker AND the dashboard) means switching which project
     * you EDIT — this value — via `setSelectedProject`. The editor marker, badge and
     * preview follow it; every project is authored and previewed at its own /p/<id>/.
     * A UX pointer only — the dispatcher re-authorizes every request against members.json
     * (C7).
     *
     * C15 R3: an account that is a member of NOTHING gets `null`, not a fallback project.
     * There is no installation-wide project to fall back to, and inventing one would hand
     * a non-member somebody else's project id. null means "show the empty state".
     *
     * @return string|null the edited project id, or null if none resolvable
     */
    public function getCurrentProject(): ?string {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $token = $this->getToken();
        if ($token) {
            $auth = validateBearerToken('Bearer ' . $token);
            if (!empty($auth['valid'])) {
                $proj = resolveDefaultProject($auth['user']);
                if ($proj !== null && $proj !== '') return $proj;
            }
        }
        return null; // 0-membership: the panel's empty state (C15 R3)
    }

    /**
     * The absolute URL a project's own site is served at — its surface-B view `/p/<id>`.
     *
     * THE single answer to "where does this project live, as a URL?". Five places used to
     * hand-roll this same concatenation (this method, the header's back-to-site link, the
     * miniplayer, the preview iframe, and PreviewConfig.previewBase) — each with its own
     * "…unless it is the served project, then the root" branch. The served project is gone
     * and so are the branches; the derivation lives here once (CLAUDE.md: centralize).
     *
     * Returned WITHOUT a trailing slash so callers append '/assets/...' the way they do
     * with baseUrl. Callers that navigate a browser there add the slash themselves.
     *
     * @param string|null $project project id; defaults to the EDITED project.
     * @return string e.g. 'http://host/p/test', or the bare install base when no project.
     */
    public function projectSiteBase(?string $project = null): string {
        $base    = rtrim(BASE_URL, '/');
        $project = $project ?? $this->getCurrentProject();
        if ($project === null || $project === '') {
            return $base;
        }
        return $base . '/p/' . rawurlencode($project);
    }

    /**
     * Where the EDITED project's own `public/` (assets, styles, builds) is reachable as a
     * URL — identical to its site base. Exposed to every admin page as
     * QUICKSITE_CONFIG.projectContentBase so /admin/assets thumbnails resolve against the
     * project on screen rather than against the web root.
     *
     * @return string e.g. 'http://host/p/test'
     */
    public function getProjectContentBase(): string {
        return $this->projectSiteBase();
    }

    /**
     * The management API base carrying the C7 project marker —
     * `<base>/management/p/<id>/`, or a bare `<base>/management/` when no project is
     * resolvable. A different URL family from projectSiteBase() (that one is where the
     * SITE is; this one is where its COMMANDS are), and the second thing admin templates
     * used to hand-roll.
     *
     * With no marker the dispatcher refuses project-scoped commands with
     * `400 project.required`, which is the correct answer for a caller who has no project
     * to edit — the URL is deliberately still well-formed rather than empty.
     *
     * @param string|null $project project id; defaults to the EDITED project.
     * @return string WITH a trailing slash, so callers append the command name directly.
     */
    public function projectManagementBase(?string $project = null): string {
        $base    = rtrim(BASE_URL, '/') . '/management/';
        $project = $project ?? $this->getCurrentProject();
        if ($project === null || $project === '') {
            return $base;
        }
        return $base . 'p/' . rawurlencode($project) . '/';
    }

    /**
     * Pages that require at least one specific command in the token's role.
     * A role must hold at least one listed command (owner/admin do, via their
     * expanded categories). Pages not listed here are open to all authenticated users.
     */
    private const PAGE_PERMISSIONS = [
        'assets'         => ['listAssets', 'uploadAsset'],
        'sitemap'        => ['getSiteMap', 'addRoute'],
        'optimize'       => ['getStyles', 'editStyles'],
        // 'ai-connections' has no permission gate: it is a UI over
        // browser-stored data. Any authenticated admin can view it.
        // (Old 'ai-settings' route 301-redirects to it in dispatch().)
        'apis'           => ['listApiEndpoints'],
        'oauth-providers'=> ['listOAuthProviders'],
        'storage'        => ['listStorageItems'],
        'privacy'        => ['getPrivacyStatus'],
        'embed-security' => ['getIframeSandbox'],
        // C8 8.3c — Project Members page: any member rank passes (all roles hold
        // getProjectRoster/proposeMember); non-members of the edited project
        // (incl. the 0-membership served-project fallback) bounce to dashboard.
        // 'memberships' is deliberately ABSENT: the self-service inbox must work
        // for every authenticated account, 0-membership included.
        'members'        => ['listMembers', 'getProjectRoster', 'proposeMember'],
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

        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $commands = getRoleCommands($role) ?? [];
        foreach (self::PAGE_PERMISSIONS[$page] as $cmd) {
            if (in_array($cmd, $commands, true)) return true;
        }
        return false;
    }

    /**
     * Log the panel out: revoke the whole session FAMILY server-side (the pair
     * dies everywhere, not just in this browser), then drop every client trace
     * (session auth keys, qs_refresh remember-me cookie, qs_preview cookie, and
     * any stale pre-C5b admin_token cookie).
     */
    public function clearToken(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        $refresh = $_SESSION['admin_refresh'] ?? ($_COOKIE['qs_refresh'] ?? null);
        if (is_string($refresh) && $refresh !== '') {
            qs_session_revoke_by_refresh($refresh);
        }

        $this->clearSessionAuth();
        $this->clearRefreshCookie();
        setcookie('qs_preview', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        setcookie('admin_token', '', ['expires' => time() - 3600, 'path' => '/admin', 'httponly' => true, 'samesite' => 'Strict']);
    }

    /**
     * C5b: JSON endpoint for the admin JS (POST /admin/session-refresh).
     * Rotates the PHP-held session when needed and returns the CURRENT access
     * token + its remaining lifetime; also re-emits the qs_preview cookie so a
     * long-open editor's private /p/<id>/ preview keeps authenticating. The
     * refresh token itself never leaves the server.
     */
    private function handleSessionRefresh(): void {
        header('Content-Type: application/json');
        header('Cache-Control: no-store'); // the response body IS a token
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']);
            exit;
        }
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        // Rotate proactively at half the access TTL so the returned token is
        // never about to die under the caller.
        $this->ensureFreshSession((int)floor(qs_session_config()['access_ttl'] / 2));

        if (!$this->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthenticated']);
            exit;
        }

        setcookie('qs_preview', (string)$_SESSION['admin_token'], [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        echo json_encode([
            'token'      => $_SESSION['admin_token'],
            'expires_in' => $this->getTokenExpiresIn(),
        ]);
        exit;
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
        // C5b: JSON session-refresh endpoint for the admin JS (exits).
        if ($this->page === 'session-refresh') {
            $this->handleSessionRefresh();
        }

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
        if (!in_array($this->page, ['login', 'register']) && !$this->isAuthenticated()) {
            $this->redirect('login');
        }

        // If already authenticated and trying to access login/register, go to dashboard
        if (in_array($this->page, ['login', 'register']) && $this->isAuthenticated()) {
            $this->redirect('dashboard');
        }

        // C8: the register page exists ONLY while self-registration is allowed
        // (server-side gate — the command enforces the same flag independently).
        if ($this->page === 'register' && !$this->isRegistrationOpen()) {
            $this->redirect('login');
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
        // C5b: admin pages embed the short-lived access token (and the login
        // page is a credential form) — they must never come out of a cache.
        // Also prevents a stale pre-C5b login form (old token field) being
        // resurrected by the browser and posting empty username/password.
        if (!headers_sent()) {
            header('Cache-Control: no-store');
        }

        // Load admin functions
        require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';
        require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTranslation.php';
        
        // Initialize translation helper
        $lang = AdminTranslation::getInstance();
        
        // Pass router to templates
        $router = $this;
        
        // Determine which template to load
        $templatePath = SECURE_FOLDER_PATH . '/admin/templates/pages/' . $this->page . '.php';
        
        // Workflows: nothing is authored here. The browser and per-spec runner are
        // subsumed by the in-editor AI tools mode at /admin/preview, and the custom
        // workflow EDITOR was removed in beta.10 C8 (the feature was an unused
        // artifact whose ungated save/delete arms were a flaw vector — see
        // WorkflowManager::listWorkflows). Every /admin/workflows* URL, including
        // bookmarks to the old editor, lands on the AI tools panel.
        if ($this->page === 'workflows') {
            $this->redirect('preview');
            return;
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
