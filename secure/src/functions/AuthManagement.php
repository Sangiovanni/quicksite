<?php
/**
 * Authentication & CORS Management Functions
 *
 * Handles API token validation, role-based permissions, and CORS header management.
 * C5b/C8 8.0b: credentials are username + password (users.php `password_hash`);
 * per-request auth is a short-lived ACCESS token from the session store
 * (SessionManagement.php). The username is PRIVATE (login-only); public identity
 * is the display `name` + the opaque user id — there is no email field.
 */

require_once __DIR__ . '/SessionManagement.php';

/**
 * Load authentication configuration
 *
 * @return array Auth configuration
 */
function loadAuthConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/auth.php';

    if (!file_exists($configPath)) {
        // Return default restrictive config if file missing
        return [
            'authentication' => [
                'session'      => [],
                'registration' => ['allow_self_registration' => false],
            ],
            'cors' => ['enabled' => false]
        ];
    }
    
    // Invalidate opcode cache if available (needed for dynamic config changes)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }
    
    return require $configPath;
}

/**
 * Load roles configuration
 * 
 * @return array Role definitions
 */
function loadRolesConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/roles.php';
    
    if (!file_exists($configPath)) {
        return [];
    }
    
    // Invalidate opcode cache if available (needed for dynamic config changes)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }
    
    return require $configPath;
}

/**
 * Load the command CATEGORY map (categories.php — the trust-coherent authz map). C6.
 * category => ['scope'=>'project'|'global', 'access'?=>'any'|'owner', 'commands'=>[]].
 *
 * @return array
 */
function loadCategoriesConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/categories.php';

    if (!file_exists($configPath)) {
        return [];
    }

    // Invalidate opcode cache if available (config changes must be seen live)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }

    return require $configPath;
}

/**
 * Load the user registry (token identities). C5.
 *
 * @return array ['users' => [ userId => record ]]
 */
function loadUsersConfig(): array {
    $configPath = SECURE_FOLDER_PATH . '/management/config/users.php';

    if (!file_exists($configPath)) {
        return ['users' => []];
    }

    // Invalidate opcode cache if available (config changes must be seen live)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configPath, true);
    }

    return require $configPath;
}

/**
 * Load a project's members.json (AUTHORITATIVE for access — L5). C5.
 * Defensive single-segment guard on $project (F1); empty on anything unsafe.
 *
 * @return array ['owner'=>?string,'visibility'=>string,'members'=>[uid=>['role'=>..]]]
 */
function loadProjectMembers(string $project): array {
    if ($project === '' || strpbrk($project, "/\\") !== false || strpos($project, '..') !== false) {
        return ['members' => []];
    }
    $path = SECURE_FOLDER_PATH . '/projects/' . $project . '/config/members.json';
    if (!is_file($path)) {
        return ['members' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['members' => []];
}

/**
 * A user's authoritative role on a project (members.json, never the users.php
 * cache — L5). C5.
 *
 * @return string|null role name, or null if the user is not a member
 */
function getUserRoleForProject(string $userId, string $project): ?string {
    $members = loadProjectMembers($project);
    return $members['members'][$userId]['role'] ?? null;
}

/**
 * Resolve a user's EFFECTIVE role for the transitional bridge (C5): their role on
 * the selected project, or — if that pointer is stale (project deleted / no longer
 * a member) — a graceful fallback to any project they are genuinely a member of.
 * Never lets a stale selected_project brick access (design requirement). Returns
 * null only when the user has no real membership anywhere. Role always comes from
 * the AUTHORITATIVE members.json (L5). Replaced in C7 by the per-request project.
 *
 * @return string|null role name, or null if the user has no membership
 */
function resolveEffectiveRole(array $user): ?string {
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return null;
    }
    // Prefer the selected project, but only when that membership is real
    $selected = $user['selected_project'] ?? null;
    if ($selected !== null && $selected !== '') {
        $role = getUserRoleForProject($userId, (string)$selected);
        if ($role !== null) {
            return $role;
        }
    }
    // Fallback: first cached project where the membership actually exists
    foreach (array_keys($user['projects'] ?? []) as $project) {
        $role = getUserRoleForProject($userId, (string)$project);
        if ($role !== null) {
            return $role;
        }
    }
    return null;
}

/**
 * Resolve a user's UX-DEFAULT project NAME (C7) — their selected_project when that
 * membership is still real, else the first cached project they are genuinely a
 * member of, else null. This is used ONLY to give GLOBAL commands a benign working
 * PROJECT_PATH context (never authz — global authz does not depend on a project,
 * and project-scoped authz uses the per-request URL project). Mirrors
 * resolveEffectiveRole's selection but returns the project id instead of the role.
 *
 * @return string|null project id, or null if the user has no real membership
 */
function resolveDefaultProject(array $user): ?string {
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return null;
    }
    $selected = $user['selected_project'] ?? null;
    if ($selected !== null && $selected !== '' && getUserRoleForProject($userId, (string)$selected) !== null) {
        return (string)$selected;
    }
    foreach (array_keys($user['projects'] ?? []) as $project) {
        if (getUserRoleForProject($userId, (string)$project) !== null) {
            return (string)$project;
        }
    }
    return null;
}

/**
 * The project ids the user is genuinely a member of (authoritative members.json — L5):
 * their users.php `projects` cache VERIFIED against members.json (a stale cache entry
 * never lists a project they can't access), plus selected_project. For the "my projects"
 * editing picker (C9). Sorted, deduped. Informational only — never an authz decision.
 *
 * @return string[] project ids
 */
function getUserProjectIds(array $user): array {
    $userId = $user['id'] ?? null;
    if ($userId === null) {
        return [];
    }
    $ids = [];
    foreach (array_keys($user['projects'] ?? []) as $project) {
        if (getUserRoleForProject($userId, (string)$project) !== null) {
            $ids[(string)$project] = true;
        }
    }
    $selected = $user['selected_project'] ?? null;
    if ($selected !== null && $selected !== '' && getUserRoleForProject($userId, (string)$selected) !== null) {
        $ids[(string)$selected] = true;
    }
    $out = array_keys($ids);
    sort($out);
    return $out;
}

/**
 * Get the master list of all commands from routes.php
 *
 * @return array List of all command names
 */
function getAllCommands(): array {
    $routesPath = SECURE_FOLDER_PATH . '/management/routes.php';
    
    if (!file_exists($routesPath)) {
        return [];
    }
    
    return require $routesPath;
}

/**
 * Reverse index: command name -> its category (from categories.php). C6.
 * Unmapped command -> null (hasPermission treats that as DENY, fail-closed).
 *
 * @param string $command
 * @return string|null category name, or null if unmapped
 */
function getCommandCategory(string $command): ?string {
    $index = [];
    foreach (loadCategoriesConfig() as $cat => $def) {
        foreach (($def['commands'] ?? []) as $cmd) {
            $index[$cmd] = $cat;
        }
    }
    return $index[$command] ?? null;
}

/**
 * The set of GLOBAL-scoped command names (categories.php scope === 'global'). C8 (8.W).
 *
 * The admin client uses this to choose transport: a global command is called at
 * '/management/<cmd>'; every OTHER (project-scoped) command must carry the
 * '/management/p/<projectId>/<cmd>' marker (C7). Emitting the set from THIS source
 * (categories.php) keeps client + server in agreement by construction, mirroring
 * the server's own default — hasPermission treats an unmapped command as 'project'
 * ('scope' ?? 'project') — so the client rule is: global IFF listed here, else project.
 *
 * @return string[] global command names (may contain duplicates only if categories.php does; it does not — 1:1)
 */
function getGlobalCommands(): array {
    $globals = [];
    foreach (loadCategoriesConfig() as $def) {
        if (($def['scope'] ?? 'project') === 'global') {
            foreach (($def['commands'] ?? []) as $cmd) {
                $globals[] = $cmd;
            }
        }
    }
    return $globals;
}

/**
 * Expand a role's granted CATEGORIES to its full PROJECT command list (C6).
 * Replaces the old flat roles.php['commands'] lookup; stable interface (callers
 * still receive a command array or null). Global commands (any-auth + the
 * owner-interim system.admin set) are layered on by getTokenPermissions, not here.
 *
 * @param string $roleName The role name
 * @return array|null Array of command names, or null if role doesn't exist
 */
function getRoleCommands(string $roleName): ?array {
    $roles = loadRolesConfig();

    if (!isset($roles[$roleName])) {
        return null;
    }

    $categories = loadCategoriesConfig();
    $commands = [];
    foreach (($roles[$roleName]['categories'] ?? []) as $cat) {
        foreach (($categories[$cat]['commands'] ?? []) as $cmd) {
            $commands[$cmd] = true; // dedupe via keys
        }
    }
    return array_keys($commands);
}

/**
 * A role's numeric rank (viewer 1 … owner 6) from roles.php. C6.
 * Drives the L9 manages-below hierarchy + the F6 self-escalation guard.
 *
 * @param string|null $roleName
 * @return int rank, or 0 for an unknown / invalid / null role
 */
function roleRank(?string $roleName): int {
    if ($roleName === null || $roleName === '') {
        return 0;
    }
    $roles = loadRolesConfig();
    return (int)($roles[$roleName]['rank'] ?? 0);
}

/**
 * May an actor holding $actorRole grant/assign/manage a member at $targetRole?
 * Self-escalation guard (F6, L9): the target must be STRICTLY below the actor's own
 * rank — you can never grant or act on a role at or above your own. C6.
 *
 * This is the rank primitive only. The membership commands that consume it (C8)
 * MUST ALSO require the actor to hold the relevant capability
 * (project.members = admin/owner; project.ownership = owner) via hasPermission —
 * so e.g. a developer (rank 4) manages no one despite rank alone allowing 1–3.
 *
 * @param string $actorRole
 * @param string $targetRole
 * @return bool
 */
function canManageRole(string $actorRole, string $targetRole): bool {
    $a = roleRank($actorRole);
    $t = roleRank($targetRole);
    return $a > 0 && $t > 0 && $a > $t;
}

/**
 * Check if a role name is valid (exists in roles.php)
 * 
 * @param string $roleName The role name to validate
 * @return bool
 */
function isValidRole(string $roleName): bool {
    // No superadmin / no '*' role (C6). A role is valid iff it exists in roles.php.
    $roles = loadRolesConfig();
    return isset($roles[$roleName]);
}

/**
 * Check if a role is a builtin role (cannot be deleted)
 * 
 * @param string $roleName The role name
 * @return bool
 */
function isBuiltinRole(string $roleName): bool {
    $roles = loadRolesConfig();
    return ($roles[$roleName]['builtin'] ?? false) === true;
}

/**
 * Validate a Bearer ACCESS token and resolve it to a USER (C5/C5b).
 * access token -> session family (sessions.json) -> userId -> user (users.php).
 * Disabled users are rejected before any command runs (L10). The returned user
 * has its 'id' attached.
 *
 * The 'code' key distinguishes an EXPIRED access token ('auth.token_expired' —
 * the client should refresh and retry) from every other refusal
 * ('auth.unauthorized' — the client should re-authenticate).
 *
 * @param string|null $authHeader The Authorization header value
 * @return array ['valid'=>bool, 'user'=>array|null, 'userId'=>string|null, 'family'=>string|null, 'error'=>string|null, 'code'=>string|null]
 *               'family' = the presenting access token's session family (C8:
 *               lets changePassword spare the caller's own session when revoking)
 */
function validateBearerToken(?string $authHeader): array {
    $refuse = static function (string $error, ?string $userId = null, string $code = 'auth.unauthorized'): array {
        return ['valid' => false, 'user' => null, 'userId' => $userId, 'family' => null, 'error' => $error, 'code' => $code];
    };

    // No header provided
    if (empty($authHeader)) {
        return $refuse('Authorization header required');
    }

    // Check Bearer format
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return $refuse('Invalid Authorization header format. Use: Bearer <token>');
    }

    $token = trim($matches[1]);

    // access token -> session family -> userId (C5b)
    $session = qs_session_validate_access($token);
    if (!$session['valid']) {
        if ($session['error'] === 'expired') {
            return $refuse('Access token expired', null, 'auth.token_expired');
        }
        return $refuse('Invalid or expired token');
    }

    // userId -> user
    $userId = $session['userId'];
    $users = loadUsersConfig();
    $user = ($userId !== null) ? ($users['users'][$userId] ?? null) : null;
    if ($user === null) {
        return $refuse('Token does not resolve to a user');
    }

    // L10: disabled user — ALL their sessions die everywhere; short-circuit here
    if (($user['status'] ?? 'active') === 'disabled') {
        return $refuse('User account is disabled', $userId);
    }

    $user['id'] = $userId; // attach resolved id for downstream (authz, logging, ownership)
    return ['valid' => true, 'user' => $user, 'userId' => $userId, 'family' => $session['family'], 'error' => null, 'code' => null];
}

/**
 * Username shape rule (C8 8.0b): 3–32 chars, lowercase a-z / 0-9 / '_' / '-'.
 * The username is the PRIVATE login identifier (the email field was dropped —
 * a mailer-less system cannot verify or use one): it is never shown to other
 * users. Public identity = the display `name` + the opaque user id.
 */
function qs_valid_username(string $username): bool {
    return preg_match('/^[a-z0-9_-]{3,32}$/', $username) === 1;
}

/**
 * Find a user by USERNAME (the private login identifier — C8 8.0b).
 * Case-insensitive; users without a username (externally managed ones) are
 * simply never matched. The returned record has its 'id' attached.
 *
 * @return array|null
 */
function findUserByUsername(string $username): ?array {
    $needle = strtolower(trim($username));
    if ($needle === '') {
        return null;
    }
    foreach (loadUsersConfig()['users'] ?? [] as $userId => $user) {
        $candidate = $user['username'] ?? null;
        if (is_string($candidate) && strtolower(trim($candidate)) === $needle) {
            $user['id'] = (string)$userId;
            return $user;
        }
    }
    return null;
}

/**
 * THE login gate (C5b) — shared by the `login` command and the admin panel's
 * form so there is exactly ONE credential-check + issuance path (C8's
 * register/createUser flows mint through qs_session_issue the same way).
 *
 * Refusals are deliberately uniform ('invalid_credentials' for unknown
 * username, wrong password, passwordless/externally-managed account, disabled
 * user) — no account-existence oracle. Verification is timing-equalized with
 * a dummy hash when the user has no usable password.
 *
 * @return array {ok:true, user:array, session:array}   (session = qs_session_issue result)
 *             | {ok:false, error:'invalid_credentials'|'throttled'|'server', retry_after?:int}
 */
function qs_auth_attempt_login(string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'invalid_credentials'];
    }

    $wait = qs_login_throttle_check($username);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'throttled', 'retry_after' => $wait];
    }

    $user = findUserByUsername($username);
    $hash = is_array($user) ? ($user['password_hash'] ?? null) : null;

    if (is_string($hash) && $hash !== '') {
        $verified = password_verify($password, $hash);
    } else {
        // No such user / no local password: burn the same time as a real check.
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuvC5bDummyTimingEqualizerHashXX2u');
        $verified = false;
    }

    if (!$verified || ($user['status'] ?? 'active') === 'disabled') {
        qs_login_throttle_fail($username);
        return ['ok' => false, 'error' => 'invalid_credentials'];
    }

    qs_login_throttle_clear($username);
    $session = qs_session_issue($user['id']);
    if ($session === false) {
        return ['ok' => false, 'error' => 'server'];
    }
    return ['ok' => true, 'user' => $user, 'session' => $session];
}

/**
 * Serialized read-modify-write on users.php — THE single users-registry writer
 * (C8): createProject, setSelectedProject, register and changePassword all
 * come through here. flock sidecar (the pre-C8 inline writers were temp+rename
 * only, so two simultaneous writers could lose an update) + temp + rename
 * (atomic swap for lock-free readers) + opcache_invalidate (readers must see
 * the new file immediately).
 *
 * @param callable $fn function(array &$cfg): mixed — receives the full
 *                     users.php array by reference. Return false to abort
 *                     WITHOUT writing; any other value is passed through
 *                     after a successful write.
 * @return mixed the callback's return value, or false on abort/lock/write failure
 */
function qs_users_mutate(callable $fn) {
    $path = SECURE_FOLDER_PATH . '/management/config/users.php';
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    try {
        $cfg = loadUsersConfig(); // opcache-invalidated fresh read
        $result = $fn($cfg);
        if ($result === false) {
            return false;
        }
        $content = "<?php\n/**\n * User Registry (C5) — stable userId => identity.\n * Engine plumbing (PHP). Atomic write (temp+rename).\n */\n\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            // Windows: a transient sharing violation (AV scan) can fail the swap once.
            usleep(50000);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Mint a NEW user account — THE single identity-creation path (C8; the
 * identity mirror of qs_session_issue): the public `register` command and the
 * admin register page both come through here. Username uniqueness and the
 * account cap are checked INSIDE the users.php write lock (no TOCTOU).
 *
 * The bcrypt hash is computed BEFORE the lock (it costs ~100ms — must not
 * hold the write lock) and UNCONDITIONALLY — so the duplicate-username path
 * burns the same time as a real creation (anti-enumeration timing, the same
 * discipline as qs_auth_attempt_login's dummy verify).
 *
 * @param string|null $password null/'' = externally managed (password_hash null)
 * @param int         $maxUsers 0 = unlimited; refused as 'full' under the lock
 * @return array {ok:true, userId:string}
 *             | {ok:false, error:'invalid_username'|'name_equals_username'
 *                |'duplicate'|'full'|'store'}
 */
function qs_user_create(string $name, string $username, ?string $password, int $maxUsers = 0): array {
    $username = strtolower(trim($username));
    if (!qs_valid_username($username)) {
        return ['ok' => false, 'error' => 'invalid_username'];
    }
    // Display name: cap + control-strip (same rule as createProject site_name).
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', mb_substr(trim($name), 0, 200));
    // Privacy: the username is the PRIVATE login identifier; the public display
    // name is shown to other users. Forbid them being equal (case-insensitive)
    // so the public surface can never directly reveal a valid login identifier.
    // Enforced HERE at the single mint path so every creation route inherits it
    // (this also backstops the register gate against a control-char evasion:
    // the name is compared AFTER control-strip). Broader "username never appears
    // in any output visible to others" is a C10 audit item.
    if (strtolower($name) === $username) {
        return ['ok' => false, 'error' => 'name_equals_username'];
    }
    $hash = ($password !== null && $password !== '') ? password_hash($password, PASSWORD_DEFAULT) : null;
    $userId = 'usr_' . bin2hex(random_bytes(16));

    $error = null;
    $result = qs_users_mutate(function (array &$cfg) use ($name, $username, $hash, $userId, $maxUsers, &$error) {
        if ($maxUsers > 0 && count($cfg['users'] ?? []) >= $maxUsers) {
            $error = 'full';
            return false;
        }
        foreach (($cfg['users'] ?? []) as $existing) {
            $candidate = $existing['username'] ?? null;
            if (is_string($candidate) && strtolower(trim($candidate)) === $username) {
                $error = 'duplicate';
                return false;
            }
        }
        $cfg['users'][$userId] = [
            'name'             => $name,
            'username'         => $username,
            'status'           => 'active',
            'password_hash'    => $hash,
            'selected_project' => null,
            'projects'         => [],
        ];
        return true;
    });

    if ($result === true) {
        return ['ok' => true, 'userId' => $userId];
    }
    return ['ok' => false, 'error' => $error ?? 'store'];
}

/**
 * THE registration gate (C8) — shared by the public `register` command and
 * the admin register page (the qs_auth_attempt_login pattern): ONE
 * flag-check + flood-control + creation path.
 *
 * Enumeration safety: a duplicate USERNAME reports ok:true EXACTLY like a
 * real creation ('created' is for the caller's own logic only and must never
 * reach the HTTP response or the page), and the bcrypt cost is burned on
 * both paths (qs_user_create). The username is the PRIVATE login identifier
 * (C8 8.0b) — it must not be enumerable pre-auth. Every other refusal
 * (disabled / closed / throttled / validation) is independent of whether the
 * username exists.
 *
 * @return array {ok:true, created:bool, userId:?string}
 *             | {ok:false, error:'registration_disabled'|'registration_closed'
 *                |'missing_fields'|'invalid_username'|'name_equals_username'
 *                |'password_too_short'|'throttled'|'server',
 *                retry_after?:int, min_length?:int}
 */
function qs_auth_attempt_register(string $name, string $username, string $password): array {
    $cfg = qs_registration_config();
    if (!$cfg['allow_self_registration']) {
        return ['ok' => false, 'error' => 'registration_disabled'];
    }
    $name = trim($name);
    $username = strtolower(trim($username));
    if ($name === '' || $username === '' || $password === '') {
        return ['ok' => false, 'error' => 'missing_fields'];
    }
    if (!qs_valid_username($username)) {
        return ['ok' => false, 'error' => 'invalid_username'];
    }
    // The public name must differ from the private username (see qs_user_create).
    // Checked here BEFORE the throttle so a bad submission costs no budget; the
    // mint path re-checks after control-strip as the authoritative backstop.
    if (strtolower($name) === $username) {
        return ['ok' => false, 'error' => 'name_equals_username'];
    }
    if (mb_strlen($password) < $cfg['min_password_length']) {
        return ['ok' => false, 'error' => 'password_too_short', 'min_length' => $cfg['min_password_length']];
    }

    $wait = qs_registration_throttle_check($cfg);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'throttled', 'retry_after' => $wait];
    }
    qs_registration_throttle_attempt(); // every attempt counts against the IP

    $created = qs_user_create($name, $username, $password, $cfg['max_users']);
    if ($created['ok']) {
        qs_registration_record_success(); // only real creations fill the global cap
        return ['ok' => true, 'created' => true, 'userId' => $created['userId']];
    }
    if ($created['error'] === 'duplicate') {
        // Uniform success — no account-existence oracle.
        return ['ok' => true, 'created' => false, 'userId' => null];
    }
    if ($created['error'] === 'full') {
        return ['ok' => false, 'error' => 'registration_closed'];
    }
    if ($created['error'] === 'invalid_username') {
        return ['ok' => false, 'error' => 'invalid_username'];
    }
    if ($created['error'] === 'name_equals_username') {
        return ['ok' => false, 'error' => 'name_equals_username'];
    }
    return ['ok' => false, 'error' => 'server'];
}

/**
 * Check whether a USER may run a command — per-project category RBAC (C6/C7).
 *
 *   command -> category (categories.php) -> scope:
 *     global 'any'   → any authenticated user
 *     global 'owner' → interim: effective role must be owner
 *     project        → the user's role ON THE PER-REQUEST PROJECT must grant that
 *                      category (owner short-circuits — owner is the top, L9)
 *   unmapped command → DENY (fail-closed). NO superadmin, NO '*'.
 *
 * C7 — the PROJECT-scoped decision now keys off $requestedProject (the projectId
 * peeled from the request URL, already validated + membership-checked by the
 * dispatcher), NOT the user's selected_project. selected_project is a UX default
 * ONLY and is never consulted here — that closes the "selected_project is never
 * authz" rule for actions. The role always comes from the AUTHORITATIVE
 * members.json (L5). GLOBAL scope is unchanged (global-'owner' remains the GAP-A
 * interim on selected_project, relocating to operator/CLI in beta.11). Disabled
 * users are already rejected in validateBearerToken (L10), before this runs.
 *
 * @param array       $user             Resolved user (from validateBearerToken; must have 'id')
 * @param string      $command          Command name being accessed
 * @param string|null $requestedProject Per-request projectId (required for a
 *                                       project-scoped command; ignored for global).
 *                                       Absent/empty on a project command → DENY.
 * @return bool
 */
function hasPermission(array $user, string $command, ?string $requestedProject = null): bool {
    $category = getCommandCategory($command);
    if ($category === null) {
        return false; // unmapped command → fail-closed
    }

    $categories = loadCategoriesConfig();
    $def = $categories[$category] ?? [];
    $scope = $def['scope'] ?? 'project';

    if ($scope === 'global') {
        $access = $def['access'] ?? '';
        if ($access === 'any') {
            return true; // any authenticated (non-disabled) user
        }
        if ($access === 'owner') {
            return resolveEffectiveRole($user) === 'owner'; // GAP-A interim owner-only
        }
        return false; // unknown global access rule → fail-closed
    }

    // project-scoped — role comes from the PER-REQUEST project (C7), never
    // selected_project. No projectId ⇒ no project to authorize against ⇒ DENY.
    if ($requestedProject === null || $requestedProject === '') {
        return false;
    }
    $role = getUserRoleForProject((string)($user['id'] ?? ''), $requestedProject);
    if ($role === null) {
        return false; // not a member of THIS project → 403
    }
    if ($role === 'owner') {
        return true; // owner-top (L9)
    }

    $roles = loadRolesConfig();
    $granted = $roles[$role]['categories'] ?? [];
    return in_array($category, $granted, true);
}

/**
 * Get a USER's effective role + full command list for their current project (C6).
 * Feeds getMyPermissions (admin JS reads {role, commands} to gate the UI). Category
 * RBAC: any-auth globals ∪ the role's project commands (∪ the owner-interim
 * system.admin set when the role is owner). Project source = selected_project
 * (transitional; C7 swaps to the per-request URL project).
 *
 * @param array $user Resolved user (must have 'id')
 * @return array ['role' => string|null, 'commands' => string[]]
 */
function getTokenPermissions(array $user): array {
    $categories = loadCategoriesConfig();

    // Commands every authenticated user may run (global, access == 'any').
    $commands = [];
    foreach ($categories as $def) {
        if (($def['scope'] ?? '') === 'global' && ($def['access'] ?? '') === 'any') {
            foreach (($def['commands'] ?? []) as $cmd) {
                $commands[$cmd] = true;
            }
        }
    }

    $role = resolveEffectiveRole($user);

    if ($role === null) {
        // No membership anywhere: only the any-auth globals.
        $list = array_keys($commands);
        sort($list);
        return ['role' => null, 'commands' => $list];
    }

    // Project commands granted by the role (owner grants all project categories).
    foreach ((getRoleCommands($role) ?? []) as $cmd) {
        $commands[$cmd] = true;
    }

    // Interim: an owner also holds the owner-only global system.admin set.
    if ($role === 'owner') {
        foreach ($categories as $def) {
            if (($def['scope'] ?? '') === 'global' && ($def['access'] ?? '') === 'owner') {
                foreach (($def['commands'] ?? []) as $cmd) {
                    $commands[$cmd] = true;
                }
            }
        }
    }

    $list = array_keys($commands);
    sort($list);
    return ['role' => $role, 'commands' => $list];
}

/**
 * Handle CORS preflight and headers
 * 
 * @param string|null $origin The Origin header from request
 * @return bool True if origin is allowed, false otherwise
 */
function handleCors(?string $origin): bool {
    $config = loadAuthConfig();
    $corsConfig = $config['cors'] ?? [];
    
    // CORS disabled
    if (!($corsConfig['enabled'] ?? false)) {
        return true; // Allow request but don't set CORS headers
    }
    
    // No origin = same-origin request, allow it
    if (empty($origin)) {
        return true;
    }
    
    // Same-origin check: if Origin matches the current host, it's not cross-origin
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $selfOrigin = $scheme . '://' . $host;
    if (strcasecmp($origin, $selfOrigin) === 0) {
        return true; // Same-origin, no CORS headers needed
    }
    
    $isAllowed = false;
    
    // Development mode: allow any localhost
    if ($corsConfig['development_mode'] ?? false) {
        if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
            $isAllowed = true;
        }
    }
    
    // Check allowed origins list
    if (!$isAllowed) {
        $allowedOrigins = $corsConfig['allowed_origins'] ?? [];
        // Support wildcard '*' to allow any origin
        $isAllowed = in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins);
    }
    
    if ($isAllowed) {
        // Set CORS headers
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: " . implode(', ', $corsConfig['allowed_methods'] ?? ['GET', 'POST', 'OPTIONS']));
        header("Access-Control-Allow-Headers: " . implode(', ', $corsConfig['allowed_headers'] ?? ['Content-Type', 'Authorization']));
        header("Access-Control-Expose-Headers: " . implode(', ', $corsConfig['expose_headers'] ?? []));
        header("Access-Control-Max-Age: " . ($corsConfig['max_age'] ?? 86400));
        
        if ($corsConfig['allow_credentials'] ?? false) {
            header("Access-Control-Allow-Credentials: true");
        }
        
        return true;
    }
    
    return false;
}

/**
 * Handle OPTIONS preflight request
 * Sends appropriate headers and exits
 */
function handlePreflightRequest(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    
    if (handleCors($origin)) {
        http_response_code(204); // No Content
        exit;
    } else {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 403,
            'code' => 'cors.origin_not_allowed',
            'message' => 'Origin not allowed by CORS policy'
        ]);
        exit;
    }
}

/**
 * Resolve the current request's full auth context from the Authorization
 * header: the user AND the presenting session family (C8 — changePassword
 * spares the caller's own family when revoking the rest).
 *
 * @return array|null the full validateBearerToken success shape
 *                    (user / userId / family), or null when unauthenticated
 */
function getCurrentAuth(): ?array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }
    $result = validateBearerToken($authHeader);
    return $result['valid'] ? $result : null;
}

/**
 * Resolve the current request's USER from the Authorization header (C5).
 * For commands that need the caller's identity (e.g. createProject owner).
 * Returns null if unauthenticated / unresolved / disabled.
 *
 * @return array|null Resolved user (with 'id') or null
 */
function getCurrentUser(): ?array {
    $auth = getCurrentAuth();
    return $auth !== null ? $auth['user'] : null;
}

/**
 * Send 401 Unauthorized response
 *
 * @param string $message Error message
 * @param string|null $hint Optional hint for fixing the error
 * @param string $code Response code — 'auth.token_expired' tells the client to
 *                     refresh + retry; anything else means re-authenticate (C5b)
 */
function sendUnauthorizedResponse(string $message, ?string $hint = null, string $code = 'auth.unauthorized'): void {
    http_response_code(401);
    header('Content-Type: application/json');
    header('WWW-Authenticate: Bearer realm="Template Vitrine Management API"');

    $response = [
        'status' => 401,
        'code' => $code,
        'message' => $message
    ];
    
    if ($hint) {
        $response['hint'] = $hint;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send 403 Forbidden response (for permission denied)
 * 
 * @param string $command The command that was denied
 */
function sendForbiddenResponse(string $command): void {
    http_response_code(403);
    header('Content-Type: application/json');
    
    echo json_encode([
        'status' => 403,
        'code' => 'auth.forbidden',
        'message' => 'Insufficient permissions for this command',
        'command' => $command
    ], JSON_PRETTY_PRINT);
    exit;
}
