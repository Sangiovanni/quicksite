<?php
/**
 * oauthProviderHelpers.php — which OAuth providers this installation offers, and the keys a
 * project signs in with.
 *
 * TWO OWNERS, TWO FILES.
 *
 *   - THE PROVIDERS belong to the installation. One file the operator edits by hand,
 *     `<secure>/management/config/oauth-providers.json`, says which providers every project may use
 *     and where each one's endpoints are. When the operator has not made that file, the shipped
 *     `oauth-providers.json.example` beside it is the list. No command writes either: a project can
 *     choose among the providers, never define one, so no project can change the endpoints another
 *     project's sign-in talks to.
 *
 *   - THE KEYS belong to the project. Its owner or admin enters them on the OAuth providers page
 *     (`setOAuthCredentials`), and they live in the project's `data/oauth-secrets.json`, two sets
 *     per provider: `preview`, which the sign-in uses on this installation, and `build`, which a
 *     build carries to the deployed site. There are no installation-wide keys: the operator offers
 *     providers, each project brings its own registration with each provider.
 *
 * A BUILT SITE carries copies of both, written by build.php: the entries of the providers its
 * project uses at `<secure>/data/oauth-providers.json`, and only the `build` keys at
 * `<secure>/data/oauth-secrets.json` — each client id as it is, each secret only when the build was
 * asked to include it, else a placeholder the site reads as not set (qs_oauth_secret_placeholder()).
 * On the deployed server, `QS_OAUTH_<PROVIDER>_CLIENT_ID` / `_CLIENT_SECRET` come first, and
 * `_CLIENT_SECRET` alone fills a secret the build left out. The installation never reads those
 * variables: on a server that hosts many projects they would sign every project in with one
 * registration.
 *
 * Installation or build is decided by the folders on disk, as IframeSandbox decides it: an
 * installation carries `<secure>/management/config`, a build does not. Never a request-scoped
 * signal — the build command reads a built site's parameters on the installation.
 *
 * This file travels into every build, so it requires only files a build carries.
 */

require_once __DIR__ . '/environment.php'; // qs_is_development
require_once __DIR__ . '/jsonIo.php';      // qs_json_write

if (!function_exists('qs_oauth_on_installation')) {

/** The two key sets a project keeps per provider. */
define('QS_OAUTH_KEY_SETS', ['preview', 'build']);

/** Authorize parameters the engine writes itself; a provider entry may not replace them. */
define('QS_OAUTH_RESERVED_AUTHORIZE_PARAMS', [
    'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method',
]);

/** The longest client id and client secret a project may store, in bytes. */
define('QS_OAUTH_CLIENT_ID_MAX', 512);
define('QS_OAUTH_CLIENT_SECRET_MAX', 1024);

/** True on the installation, false inside a built site. */
function qs_oauth_on_installation(): bool
{
    return is_dir(SECURE_FOLDER_PATH . '/management/config');
}

/**
 * The provider list this request reads: the operator's file, else the shipped `.example`, on the
 * installation; the build's copy in a built site.
 */
function qs_oauth_providers_path(): string
{
    if (!qs_oauth_on_installation()) {
        return SECURE_FOLDER_PATH . '/data/oauth-providers.json';
    }
    $own = SECURE_FOLDER_PATH . '/management/config/oauth-providers.json';
    return is_file($own) ? $own : $own . '.example';
}

/** The list's name as an operator reads it in a log line: the placeholder, never the real path. */
function qs_oauth_providers_label(): string
{
    if (!qs_oauth_on_installation()) {
        return '<secure>/data/oauth-providers.json';
    }
    return '<secure>/management/config/' . basename(qs_oauth_providers_path());
}

/**
 * Every usable provider, id => entry, in the file's order. Read and checked once per request.
 *
 * A key starting with `_` is documentation and is passed over. An entry that fails the check is
 * left out and the reason written to the PHP error log. A file that is not a JSON object leaves
 * the list EMPTY: it is never replaced by the shipped list, which could offer a provider the
 * operator removed.
 *
 * @return array<string, array>
 */
function qs_oauth_providers(): array
{
    static $cache = [];
    $path = qs_oauth_providers_path();
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $out = [];
    if (!is_file($path)) {
        return $cache[$path] = $out;
    }
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw);
    if (!($data instanceof stdClass)) {
        error_log('QuickSite: ' . qs_oauth_providers_label() . ' is not a JSON object'
            . ($raw === false ? ' (unreadable)' : ' (' . json_last_error_msg() . ')') . ' — no OAuth provider is offered.');
        return $cache[$path] = $out;
    }
    foreach (json_decode($raw, true) as $id => $entry) {
        $id = (string) $id;
        if ($id !== '' && $id[0] === '_') {
            continue;
        }
        $problem = qs_oauth_provider_problem($id, $entry);
        if ($problem !== null) {
            error_log("QuickSite: OAuth provider '" . substr($id, 0, 64) . "' in " . qs_oauth_providers_label()
                . " skipped: $problem.");
            continue;
        }
        $out[$id] = $entry;
    }
    return $cache[$path] = $out;
}

/** One provider's entry, or null when the list does not offer it. */
function qs_oauth_provider(string $id): ?array
{
    return qs_oauth_providers()[$id] ?? null;
}

/** True for a provider id's shape: lowercase letters, digits and hyphens, starting with a letter. */
function qs_oauth_provider_id_ok(string $id): bool
{
    return strlen($id) <= 64 && preg_match('/^[a-z][a-z0-9-]*$/D', $id) === 1;
}

/**
 * Why a provider entry cannot be used, or null when it can.
 *
 * Every address is absolute, with a host and no user name, password or fragment, and uses https.
 * Plain http is accepted only in development, the same switch that lets the server reach a local
 * address. The privacy link is checked as a link instead (qs_oauth_link_problem()). Unknown fields
 * are refused, so a misspelt optional field is reported rather than silently ignored.
 */
function qs_oauth_provider_problem(string $id, $entry): ?string
{
    if (!qs_oauth_provider_id_ok($id)) {
        return 'the id must be lowercase letters, digits and hyphens, starting with a letter';
    }
    if (!is_array($entry) || ($entry !== [] && array_keys($entry) === range(0, count($entry) - 1))) {
        return 'the entry is not an object';
    }
    $known = ['name', 'console_url', 'privacy_url', 'authorize_url', 'token_url', 'userinfo_url', 'revoke_url', 'scope',
        'userinfo_sub_path', 'userinfo_email_path', 'userinfo_name_path', 'extra_authorize_params',
        'refresh_token_supported'];
    foreach (array_keys($entry) as $field) {
        $field = (string) $field;
        if ($field !== '' && $field[0] === '_') {
            continue;
        }
        if (!in_array($field, $known, true)) {
            return "unknown field '" . substr($field, 0, 64) . "'";
        }
    }
    foreach (['authorize_url', 'token_url', 'userinfo_url'] as $field) {
        if (!isset($entry[$field])) {
            return "'$field' is missing";
        }
    }
    foreach (['authorize_url', 'token_url', 'userinfo_url', 'revoke_url', 'console_url'] as $field) {
        if (array_key_exists($field, $entry) && ($why = qs_oauth_address_problem($entry[$field])) !== null) {
            return "'$field' $why";
        }
    }
    if (array_key_exists('privacy_url', $entry) && ($why = qs_oauth_link_problem($entry['privacy_url'])) !== null) {
        return "'privacy_url' $why";
    }
    foreach (['scope', 'userinfo_sub_path', 'userinfo_email_path'] as $field) {
        if (!isset($entry[$field]) || !is_string($entry[$field]) || trim($entry[$field]) === '') {
            return "'$field' must be a non-empty string";
        }
    }
    foreach (['name', 'userinfo_name_path'] as $field) {
        if (array_key_exists($field, $entry) && (!is_string($entry[$field]) || trim($entry[$field]) === '')) {
            return "'$field' must be a non-empty string";
        }
    }
    if (array_key_exists('refresh_token_supported', $entry) && !is_bool($entry['refresh_token_supported'])) {
        return "'refresh_token_supported' must be true or false";
    }
    if (array_key_exists('extra_authorize_params', $entry)) {
        $extra = $entry['extra_authorize_params'];
        if (!is_array($extra) || ($extra !== [] && array_keys($extra) === range(0, count($extra) - 1))) {
            return "'extra_authorize_params' must be an object";
        }
        foreach ($extra as $k => $v) {
            if (in_array((string) $k, QS_OAUTH_RESERVED_AUTHORIZE_PARAMS, true)) {
                return "'extra_authorize_params' may not set '$k', which the engine writes";
            }
            if (!is_string($v) && !is_int($v) && !is_bool($v)) {
                return "'extra_authorize_params.$k' must be a string, a number or true / false";
            }
        }
    }
    return null;
}

/** Why a provider address cannot be used, or null when it can. */
function qs_oauth_address_problem($url): ?string
{
    if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return 'must be an address with no spaces or control characters';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'must be an absolute address';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return 'may not hold a user name, a password or a fragment';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme === 'https' || ($scheme === 'http' && qs_is_development())) {
        return null;
    }
    return $scheme === 'http' ? 'must use https (http is accepted only in development)' : 'must use https';
}

/**
 * Why a provider's privacy link cannot be used, or null when it can. A generated cookie or privacy
 * policy writes it into a public page, where a visitor's browser opens it and the server never
 * calls it: so it may end in a fragment, and it is https even in development (the exception for
 * reaching a local test server does not apply to a link). Absolute, with a host, and no user name,
 * password, space or control character, like every address of the list.
 */
function qs_oauth_link_problem($url): ?string
{
    if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return 'must be an address with no spaces or control characters';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'must be an absolute address';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return 'may not hold a user name or a password';
    }
    return strtolower($parts['scheme']) === 'https' ? null : 'must use https';
}

/** The provider's display name: its `name`, else its id with the first letter capitalised. */
function qs_oauth_provider_name(string $id, array $entry): string
{
    return isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : ucfirst(str_replace('-', ' ', $id));
}

// ── a project's keys ───────────────────────────────────────────────────────────────────────

/** The project's key file, or null when no project is bound. */
function qs_oauth_keys_path(): ?string
{
    return defined('PROJECT_PATH') ? PROJECT_PATH . '/data/oauth-secrets.json' : null;
}

/** The key file's name as an operator reads it in a log line: the placeholder, never the real path. */
function qs_oauth_keys_label(): string
{
    return qs_oauth_on_installation() ? "this project's data/oauth-secrets.json" : '<secure>/data/oauth-secrets.json';
}

/**
 * What a copy carried out of the project — a build, an export — writes for a client secret it
 * leaves out: words for the deployer who opens the file. They hold spaces, so they can never be a
 * secret a project stores (qs_oauth_key_value_ok()) nor one a provider issues, and every reader takes
 * them as "not set" (qs_oauth_keys_read()).
 */
function qs_oauth_secret_placeholder(string $provider): string
{
    return 'REPLACE THIS VALUE with the client secret, or set ' . qs_oauth_server_variable($provider)
        . '_CLIENT_SECRET on the server';
}

/**
 * The project's key file, provider => set => {client_id, client_secret}, keeping only well-formed
 * sets. [] when absent or unreadable.
 *
 * Only a set whose secret is absent or null is a public client (client_secret null). Any other
 * secret that no project stores and no provider issues — the placeholder a copy writes when it
 * leaves the secret out, a string holding a space or a character outside printable ASCII, an empty
 * string, or a value that is not a string at all — is read as not set: the set answers
 * client_secret null with secret_left_out true, so it is never sent to a provider and never taken for
 * a public client. Every writer of a key file writes a set through qs_oauth_stored_set(), so the
 * flag never reaches a file.
 *
 * @return array<string, array<string, array{client_id: string, client_secret: ?string, secret_left_out?: bool}>>
 */
function qs_oauth_keys_read(): array
{
    $path = qs_oauth_keys_path();
    if ($path === null || !is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        error_log("QuickSite: this project's data/oauth-secrets.json is not a JSON object — its OAuth keys are not read.");
        return [];
    }
    $out = [];
    foreach ($data as $provider => $sets) {
        if (!is_string($provider) || !is_array($sets)) {
            continue;
        }
        foreach (QS_OAUTH_KEY_SETS as $set) {
            $s = $sets[$set] ?? null;
            if (is_array($s) && isset($s['client_id']) && is_string($s['client_id']) && $s['client_id'] !== '') {
                $secret = $s['client_secret'] ?? null;
                $out[$provider][$set] = $secret === null
                    || (is_string($secret) && qs_oauth_key_value_ok($secret, QS_OAUTH_CLIENT_SECRET_MAX))
                    ? ['client_id' => $s['client_id'], 'client_secret' => $secret]
                    : ['client_id' => $s['client_id'], 'client_secret' => null, 'secret_left_out' => true];
            }
        }
    }
    return $out;
}

/**
 * A set as a key file holds it: its client id, and its secret, or the placeholder for one the read
 * found left out. What every writer of a key file writes, so a left-out secret stays left out.
 */
function qs_oauth_stored_set(string $provider, array $set): array
{
    return [
        'client_id'     => (string) ($set['client_id'] ?? ''),
        'client_secret' => !empty($set['secret_left_out']) ? qs_oauth_secret_placeholder($provider) : ($set['client_secret'] ?? null),
    ];
}

/**
 * The build set as a copy carried out of the project writes it — a build, an export: the client id
 * as it is (it is public: every sign-in shows it to the browser), and the secret only when
 * $withSecret, else the placeholder. A public client carries no secret either way.
 *
 * @return array{0: array{client_id: string, client_secret: ?string}, 1: bool} the set, and whether
 *         its secret was left out
 */
function qs_oauth_carried_set(string $provider, array $set, bool $withSecret): array
{
    $leftOut = !empty($set['secret_left_out']);
    if (($set['client_secret'] ?? null) === null && !$leftOut) {
        return [qs_oauth_stored_set($provider, $set), false];
    }
    if ($withSecret && !$leftOut) {
        return [qs_oauth_stored_set($provider, $set), false];
    }
    return [['client_id' => (string) ($set['client_id'] ?? ''), 'client_secret' => qs_oauth_secret_placeholder($provider)], true];
}

/**
 * True for a client id or secret a project may store: printable ASCII, no space, at most $max
 * bytes. Every provider issues them in that alphabet; anything else is a paste gone wrong.
 */
function qs_oauth_key_value_ok(string $value, int $max): bool
{
    return $value !== '' && strlen($value) <= $max && preg_match('/^[\x21-\x7E]+$/D', $value) === 1;
}

/**
 * Write the project's key file, every set as a key file holds it (qs_oauth_stored_set()). Readable by
 * the server's own user only, on systems that honour file modes.
 */
function qs_oauth_keys_write(array $keys): bool
{
    $path = qs_oauth_keys_path();
    if ($path === null) {
        return false;
    }
    $stored = [];
    foreach ($keys as $provider => $sets) {
        foreach (is_array($sets) ? $sets : [] as $set => $value) {
            if (is_array($value)) {
                $stored[(string) $provider][(string) $set] = qs_oauth_stored_set((string) $provider, $value);
            }
        }
    }
    // The size check (qs_small_write_guard): false when refused, and the command
    // answers qs_small_write_refused().
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    require_once __DIR__ . '/quota.php';
    if (!qs_small_write_guard($path, qs_json_size((object) $stored, $flags, "\n"))) {
        return false;
    }
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
        return false;
    }
    if (!qs_json_write($path, (object) $stored, $flags, LOCK_EX, "\n")) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

/**
 * Make the key file of a project-shaped folder ($root: a project, a backup) readable by the
 * server's own user only, on systems that honour file modes, as qs_oauth_keys_write() leaves it.
 * Every copy that writes a key file calls it once the file is written — a backup, the copy kept
 * before a restore, a restore (qs_backup_copy_item()), and an import — since a copy is made at the
 * default mode. A link is left alone: its mode is its target's.
 */
function qs_oauth_keys_protect(string $root): void
{
    $path = $root . '/data/oauth-secrets.json';
    if (is_file($path) && !is_link($path)) {
        @chmod($path, 0600);
    }
}

/**
 * The keys this request's sign-in uses for a provider, or null when it has none.
 *
 *   installation  the project's `preview` set;
 *   built site    the server's QS_OAUTH_<PROVIDER>_CLIENT_ID / _CLIENT_SECRET when set, else the
 *                 `build` set the build carried; when the build left its secret out, the server's
 *                 _CLIENT_SECRET alone fills it.
 *
 * A set whose secret was left out and is filled from nowhere is no keys at all: the sign-in answers
 * that it is not configured, nothing is sent to the provider, and the log names the file and the
 * variable.
 *
 * @return array{client_id: string, client_secret: ?string}|null
 */
function qs_oauth_sign_in_keys(string $provider): ?array
{
    $installation = qs_oauth_on_installation();
    if (!$installation) {
        $server = qs_oauth_server_keys($provider);
        if ($server !== null) {
            return $server;
        }
    }
    $set = qs_oauth_keys_read()[$provider][$installation ? 'preview' : 'build'] ?? null;
    if ($set === null || empty($set['secret_left_out'])) {
        return $set;
    }
    $variable = qs_oauth_server_variable($provider) . '_CLIENT_SECRET';
    if (!$installation) {
        $secret = qs_oauth_server_value($variable);
        if ($secret !== null) {
            return ['client_id' => $set['client_id'], 'client_secret' => $secret];
        }
    }
    error_log("QuickSite: OAuth provider '" . substr((string) preg_replace('/[^a-z0-9-]/', '?', $provider), 0, 64)
        . "' has no client secret in " . qs_oauth_keys_label()
        . ($installation ? '' : ": write it there, or set $variable on the server")
        . ' — its sign-in is not configured.');
    return null;
}

/**
 * The name of a provider's server variables, before `_CLIENT_ID` / `_CLIENT_SECRET`: QS_OAUTH_ and
 * the provider id upper-cased with every run of other characters folded to `_` (`my-sso` reads
 * QS_OAUTH_MY_SSO_CLIENT_ID).
 */
function qs_oauth_server_variable(string $provider): string
{
    return 'QS_OAUTH_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $provider));
}

/**
 * One server variable, or null when it is not set or empty. `REDIRECT_` is checked too: Apache
 * prefixes environment variables with it once a request has been through an internal redirect,
 * which a build's FallbackResource does to every page request.
 */
function qs_oauth_server_value(string $key): ?string
{
    $raw = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? getenv($key);
    return is_string($raw) && $raw !== '' ? $raw : null;
}

/**
 * The deployed server's keys for a provider: QS_OAUTH_<PROVIDER>_CLIENT_ID and _CLIENT_SECRET
 * (qs_oauth_server_variable()), or null when the client id is not set.
 *
 * @return array{client_id: string, client_secret: ?string}|null
 */
function qs_oauth_server_keys(string $provider): ?array
{
    $name = qs_oauth_server_variable($provider);
    $id = qs_oauth_server_value($name . '_CLIENT_ID');
    if ($id === null) {
        return null;
    }
    return ['client_id' => $id, 'client_secret' => qs_oauth_server_value($name . '_CLIENT_SECRET')];
}

// ── what a project's routes use ────────────────────────────────────────────────────────────

/**
 * The project's sign-in routes: every route whose resolver is an OAuth kind, with the provider it
 * names. A provider taken from the address (`{:param}`) is reported as null.
 *
 * @return list<array{route: string, kind: string, provider: ?string}>
 */
function qs_oauth_project_routes(): array
{
    $out = [];
    if (!defined('PROJECT_PATH')) {
        return $out;
    }
    $file = PROJECT_PATH . '/data/route-resolvers.json';
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $all = $raw === false ? null : json_decode($raw, true);
    if (!is_array($all)) {
        return $out;
    }
    foreach ($all as $route => $entry) {
        // One resolver is stored as an object, several as a list.
        $configs = (is_array($entry) && isset($entry['kind'])) ? [$entry] : $entry;
        if (!is_array($configs)) {
            continue;
        }
        foreach ($configs as $config) {
            $kind = is_array($config) ? ($config['kind'] ?? null) : null;
            if ($kind !== 'oauth-start' && $kind !== 'oauth-callback' && $kind !== 'oauth-logout') {
                continue;
            }
            $provider = $config['provider'] ?? null;
            $literal = is_string($provider) && $provider !== '' && preg_match('/^\{:\w+\}$/D', $provider) !== 1;
            if ($kind === 'oauth-logout' && !is_string($provider)) {
                continue; // a sign-out names no provider: it reads the session's
            }
            $out[] = ['route' => (string) $route, 'kind' => $kind, 'provider' => $literal ? $provider : null];
        }
    }
    return $out;
}

/** Every oauth-button on the project's pages that names $provider, page => count. */
function qs_oauth_project_buttons(string $provider): array
{
    $out = [];
    $dir = defined('PROJECT_PATH') ? PROJECT_PATH . '/templates/model/json/pages' : '';
    if ($dir === '' || !is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
            continue;
        }
        $raw = @file_get_contents($file->getPathname());
        $tree = $raw === false ? null : json_decode($raw, true);
        $n = is_array($tree) ? qs_oauth_count_buttons($tree, $provider) : 0;
        if ($n > 0) {
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $dir)) + 1);
            $out[substr($rel, 0, -5)] = $n;
        }
    }
    ksort($out);
    return $out;
}

/**
 * The oauth-button nodes under $nodes for $provider. The button is written as plain markup, so it
 * is recognised by the class the builder gives it.
 */
function qs_oauth_count_buttons(array $nodes, string $provider): int
{
    $n = 0;
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $class = $node['params']['class'] ?? '';
        if (is_string($class) && in_array('qs-oauth-button--' . $provider, preg_split('/\s+/', $class), true)) {
            $n++;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $n += qs_oauth_count_buttons($node['children'], $provider);
        }
    }
    return $n;
}

} // end if (!function_exists('qs_oauth_on_installation'))
