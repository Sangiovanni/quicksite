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
    /**
     * The admin URL namespace — the ONLY first segments that are pages.
     *
     * beta.10 C13 (F-C13-19): this list was declared here and never read, so the
     * real router was `templates/pages/{segment}.php` + `file_exists`. Every file
     * in that directory was therefore a URL, and six of them are PARTIALS meant to
     * be included by a parent view — they rendered as top-level pages with their
     * parent's variables undefined (one of them fatally). They are not pages with
     * an unmet precondition, so there is no page to redirect to and no state to
     * explain; the honest answer is that the URL does not exist. Reading the list
     * answers 404 for all six, and for any partial added to that directory later.
     */
    private array $validPages = [
        'login',       // Authentication page
        'register',    // Self-registration page (C8; renders only when auth.php allows it)
        'setup',       // First-run page (C14; renders only while the user registry is empty)
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
        'account',     // My Account — password, sign out everywhere, delete account (any authenticated user)
        'assets',      // Asset Management page
        'sitemap',     // Visual Sitemap & Route Management
        'optimize',    // Optimization Tools
        'logout'       // Logout action — POST only (everywhere=1 ends the account's other sessions too)
    ];

    /**
     * The cookie carrying the CSRF token of the UNAUTHENTICATED auth forms.
     *
     * Login, register and first-run run before any session exists, so they
     * cannot borrow the per-session token every other admin page embeds. They
     * use a double-submit pair instead: the server plants this cookie and the
     * same value as a hidden field, and only accepts a POST where the two
     * match. A foreign origin can make the browser send a cookie but can
     * neither read it (HttpOnly) nor put its value in a form — and SameSite
     * Strict means it is not even sent on a cross-site POST, so the comparison
     * has nothing to compare and fails closed.
     */
    private const FORM_TOKEN_COOKIE = 'qs_form_token';

    /** Memoised for the request, so one render never plants two tokens. */
    private ?string $formToken = null;

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
     * The panel's session model (beta.11 S1): the PHP session IS the session.
     * You log in on arrival and the browser session holds the login — there is
     * no access token, no refresh token and no rotation. The panel resolves
     * itself straight from the session cookie (qs_session_auth); the only thing
     * it hands the browser is the per-session token pages embed so the admin
     * JS can prove, on each management-API call, that it read a page of this
     * session. "Remember me" simply gives the session cookie a lifetime.
     */

    /**
     * Is the caller signed in? One resolution path — the same one the
     * management API and surface B use — so a disabled account, a vanished
     * account and a session killed elsewhere (generation bump) all stop
     * rendering pages immediately rather than at some token's expiry.
     */
    public function isAuthenticated(): bool {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        return qs_session_auth()['valid'];
    }

    /**
     * Attempt a username + password login — the panel form's entry into the ONE
     * shared credential-check path (qs_auth_attempt_login), followed by
     * establishing the session. $remember gives the session cookie a lifetime
     * so it survives a browser restart.
     *
     * @return string|null null on success, else an error key:
     *                     'invalid_credentials' | 'missing_fields' | 'throttled:<seconds>'
     */
    public function attemptLogin(string $username, string $password, bool $remember = false): ?string {
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

        $user = $attempt['user'];
        qs_session_establish((string)$user['id'], qs_user_generation($user), $remember);
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
     * Has this install been bootstrapped — i.e. does ANY account exist? (C14)
     *
     * The test is the REGISTRY BEING EMPTY, not users.php existing: a file that
     * is present but holds no users is the same dead end, and loadUsersConfig()
     * answers both cases identically. While this returns false there is nobody
     * to log in as, so every admin URL renders the first-run page.
     */
    public function needsFirstRun(): bool {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        return (loadUsersConfig()['users'] ?? []) === [];
    }

    /**
     * Attempt the FIRST-RUN account creation (C14) — the page's entry into the
     * shared bootstrap gate (qs_auth_attempt_setup). Authorisation is the setup
     * token the deployer reads off disk; the flag governing public
     * self-registration is deliberately not consulted (creating the first
     * account is an installation step, and must work on a default install).
     *
     * On success a one-shot flash is set for the login page's banner. No
     * auto-login: the login page stays the single session-establishing point.
     *
     * @return string|null null on success, else an error key:
     *                     'setup_complete' | 'missing_fields' | 'invalid_token' |
     *                     'invalid_username' | 'name_equals_username' |
     *                     'password_too_short:<min>' | 'throttled:<seconds>' | 'server'
     */
    public function attemptSetup(string $name, string $username, string $password, string $token): ?string {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        qs_session_boot(true); // the one-shot flash below rides the same session

        $attempt = qs_auth_attempt_setup($name, $username, $password, $token);
        if ($attempt['ok']) {
            // Carry the username so the login page can pre-fill it — see
            // attemptRegister() below for why that matters.
            $_SESSION['qs_register_flash'] = strtolower(trim($username));
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
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        qs_session_boot(true); // the one-shot flash below rides the same session

        if (trim($name) === '' || trim($username) === '' || $password === '') {
            return 'missing_fields';
        }

        $attempt = qs_auth_attempt_register($name, $username, $password);
        if ($attempt['ok']) {
            // The flash carries the USERNAME, not just a boolean, so the login
            // page can pre-fill it.
            //
            // This matters because a duplicate username reports success exactly
            // like a real creation — deliberately, so the private login
            // identifier cannot be probed. The cost is a dead end: mistype your
            // password while registering, and you get "account created", cannot
            // sign in, and re-registering silently does nothing. Pre-filling
            // turns that into an ordinary failed login against a name you can
            // see, which is diagnosable. It discloses nothing: the value is the
            // one this browser just typed into the form on this page.
            $_SESSION['qs_register_flash'] = strtolower(trim($username));
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
     * The CSRF token for the unauthenticated auth forms (login / register /
     * first-run), planting the cookie half of the pair if it is not there yet.
     *
     * MUST be reached before any output — planting means Set-Cookie. dispatch()
     * primes it for exactly those three pages, so a template calling this
     * always finds the value already minted and never depends on whether the
     * page has begun flushing.
     *
     * An existing well-shaped cookie is REUSED rather than replaced: two login
     * tabs open at once must both stay submittable, and re-minting on every
     * render would silently invalidate whichever form was drawn first.
     */
    public function formToken(): string {
        if ($this->formToken !== null) {
            return $this->formToken;
        }
        $existing = (string)($_COOKIE[self::FORM_TOKEN_COOKIE] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $existing) === 1) {
            $this->formToken = $existing;
            return $this->formToken;
        }

        $this->formToken = bin2hex(random_bytes(32));
        if (!headers_sent()) {
            // Path from the panel's own base so an install under a public
            // folder space (PUBLIC_FOLDER_SPACE) scopes the cookie to its real
            // admin prefix instead of a '/admin' that does not exist there.
            $path = parse_url($this->getBaseUrl(), PHP_URL_PATH);
            setcookie(self::FORM_TOKEN_COOKIE, $this->formToken, [
                'expires'  => 0, // dies with the browser session
                'path'     => is_string($path) && $path !== '' ? $path : '/admin',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        return $this->formToken;
    }

    /**
     * Does a submitted auth-form token match the one planted in the browser?
     *
     * Compared against the COOKIE THE REQUEST CARRIED, never against
     * formToken() — that would happily compare a freshly minted value with
     * itself and authorise everything. A missing cookie is a failure, which is
     * precisely the cross-site case.
     */
    public function formTokenValid(string $submitted): bool {
        $planted = (string)($_COOKIE[self::FORM_TOKEN_COOKIE] ?? '');
        return $planted !== '' && $submitted !== '' && hash_equals($planted, $submitted);
    }

    /**
     * Does a submitted value match THIS session's per-session token?
     *
     * The signed-in half of the same idea: pages already embed that token for
     * the admin JS, so a form on a rendered page can prove the same thing an
     * API call proves — that whoever built the request could read a page of
     * this session. Used by the logout form.
     */
    public function sessionTokenValid(string $submitted): bool {
        $token = (string)$this->getToken();
        return $token !== '' && $submitted !== '' && hash_equals($token, $submitted);
    }

    /**
     * The PER-SESSION TOKEN this page embeds for the admin JS.
     *
     * It is NOT a credential on its own — it authenticates nothing without the
     * session cookie it belongs to. Its job is to prove, on each management-API
     * call, that the caller could read a page of this session, which a foreign
     * origin cannot do. See AuthManagement's validateBearerToken.
     */
    public function getToken(): ?string {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $auth = qs_session_auth();
        return $auth['valid'] ? $auth['token'] : null;
    }

    /**
     * Get the effective role of the currently authenticated user (C6).
     * Resolves the session -> user -> role on the project the
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
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        $auth = qs_session_auth();
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
        $auth = qs_session_auth();
        if (!empty($auth['valid'])) {
            $proj = resolveDefaultProject($auth['user']);
            if ($proj !== null && $proj !== '') return $proj;
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
     * Log the panel out.
     *
     * $everywhere also bumps the account's session generation, which ends every
     * OTHER session of this user — a second browser, a phone, a session left
     * open at work — on its next request. That is the whole mechanism: one
     * integer on the user record, compared on every request. There is no session
     * index to walk and nothing to clean up afterwards.
     *
     * Stale cookies from the retired token model (`qs_refresh`, `qs_preview`,
     * `admin_token`) are expired here as one-time upgrade hygiene: they carried
     * credentials, and a browser holding one should not keep it.
     */
    public function clearToken(bool $everywhere = false): void {
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

        if ($everywhere) {
            $auth = qs_session_auth();
            if (!empty($auth['valid'])) {
                qs_user_bump_generation((string)$auth['userId']);
            }
        }

        qs_session_destroy();
        foreach (['qs_refresh' => '/admin', 'qs_preview' => '/', 'admin_token' => '/admin'] as $name => $path) {
            if (isset($_COOKIE[$name])) {
                setcookie($name, '', ['expires' => time() - 3600, 'path' => $path, 'httponly' => true, 'samesite' => 'Lax']);
            }
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
        // Handle logout. `everywhere=1` also ends the account's OTHER sessions
        // (the header offers it as a second action next to the plain logout).
        //
        // POST + the per-session token, not a GET link: ending a session is a
        // state change, and as a GET any foreign page could spend one <img> tag
        // signing the user out of the panel. The token is the same proof the
        // management API requires — readable only from a page of this session.
        // Anything that is not that pair is not a logout request, so nothing is
        // ended and the caller simply goes back where they belong (idempotent:
        // a caller with no session lands on the login page either way).
        if ($this->page === 'logout') {
            $submitted = (string)($_POST['session_token'] ?? '');
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $this->sessionTokenValid($submitted)) {
                $this->clearToken((string)($_POST['everywhere'] ?? '') === '1');
                $this->redirect('login');
            }
            $this->redirect($this->isAuthenticated() ? 'dashboard' : 'login');
        }

        // Legacy: ai-settings -> ai-connections (Phase 3 rename).
        if ($this->page === 'ai-settings') {
            $this->redirect('ai-connections');
        }

        // C14 — FIRST RUN. With an empty user registry there is nobody to log in
        // as, so every admin URL lands on the first-run page instead of a login
        // form that cannot work. The moment an account exists the page is dead:
        // /admin/setup redirects to login, and the underlying gate refuses
        // independently (this redirect is navigation, never the security check).
        if ($this->needsFirstRun()) {
            if ($this->page !== 'setup') {
                $this->redirect('setup');
            }
        } elseif ($this->page === 'setup') {
            $this->redirect('login');
        }

        // Check authentication for protected pages
        if (!in_array($this->page, ['login', 'register', 'setup']) && !$this->isAuthenticated()) {
            $this->redirect('login');
        }

        // If already authenticated and trying to access login/register, go to dashboard
        if (in_array($this->page, ['login', 'register', 'setup']) && $this->isAuthenticated()) {
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

        // C13 — the visual editor needs a project to edit. An account that is a
        // member of NOTHING resolves no project (C15 R3), and the page then points
        // its iframe at the install base, which is not a QuickSite URL: on a
        // default deployment the web root has no index and Apache answers the
        // editor's own iframe with its 403 page. Send that account to the one
        // place that can fix its situation instead.
        //
        // This fires ONLY at zero membership. A member of other projects always
        // resolves one, and a request for a project the caller is genuinely not a
        // member of is refused by the marker gate (403) and by surface B (404) —
        // deliberately NOT softened here, because those are real permission
        // failures and a friendly redirect would hide one.
        if ($this->page === 'preview' && $this->getCurrentProject() === null) {
            $this->redirect('dashboard?noproject=1');
        }

        // The auth forms' CSRF token rides a cookie, so it has to be planted
        // before the first byte of the page. Priming it here — not inside the
        // templates, which run once the layout has begun emitting — is what
        // keeps that Set-Cookie reachable regardless of output buffering.
        if (in_array($this->page, ['login', 'register', 'setup'], true)) {
            $this->formToken();
        }

        // C13 (F-C13-19) — anything outside the declared namespace is not a page.
        // Placed AFTER the authentication gate on purpose: an unauthenticated
        // caller keeps getting the same redirect to /admin/login for every
        // segment, so this cannot become a pre-auth oracle for which page names
        // exist.
        if (!in_array($this->page, $this->validPages, true)) {
            http_response_code(404);
            // The 404 template is rendered as the page, so the layout stops
            // reflecting the requested segment into <title> and data-page too.
            $this->page = '404';
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
