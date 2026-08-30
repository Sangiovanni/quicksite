<?php
/**
 * The deployment marker — `<secure>/qs-deployment.json`, written at deploy.
 *
 * ONE record, read by two very different callers, which is why it lives here
 * rather than inside `deployBuild`:
 *
 *   - `deployBuild` WRITES it, and reads it back on the next deploy to tell an
 *     update of this project from a stranger's folder (the co-tenancy rule).
 *   - `NginxConfig` READS it to answer a question the disk alone cannot: is
 *     there a deployed site at this installation's document root, and at which
 *     URL space? The generated `location /` block is correct only if it agrees
 *     with that answer.
 *
 * ⚠ WHY NOT `qs-site.php`. A build ships `<public>/qs-site.php`, which holds
 * exactly these fields and is the site's own copy of them. It is NOT usable
 * here: it answers 404 and CALLS `exit` unless `QS_SITE_BOOT` is defined, so
 * requiring it from the admin panel or from `init.php` would end the request.
 * Defining `QS_SITE_BOOT` to get around that is worse — it is the constant that
 * tells the site's entry point it is booting. The marker is JSON, is inert, and
 * per the project's data rule is the right shape for data describing a
 * deployed website.
 *
 * ⚠ THE MARKER IS A CLAIM; THE DISK IS THE FACT. `qs_deployed_sites()` never
 * reports a site whose entry point is not on disk. A marker left behind by
 * files somebody deleted by hand must not make the web server route to nothing.
 */

// Guarded: `deployBuild` used to declare this itself, and an install that has
// both loaded must not fatal on a redeclaration.
if (!defined('QS_DEPLOY_MARKER')) {
    define('QS_DEPLOY_MARKER', 'qs-deployment.json');
}

/**
 * The marker's field set, in one place, so the writer and the reader cannot
 * drift apart.
 *
 * `project`/`build`/`deployed_at` are the ownership record. `public`/`secure`/
 * `space` are WHERE the deployment landed — without them nothing downstream can
 * tell a site deployed at the document root from one deployed beside it, which
 * is the whole question the nginx root block turns on.
 *
 * @return array<string,string> Ready for json_encode.
 */
function qs_deployment_marker_fields(
    string $project,
    string $build,
    string $publicFolder,
    string $secureFolder,
    string $space
): array {
    return [
        'project'     => $project,
        'build'       => $build,
        'deployed_at' => date('c'),
        'public'      => $publicFolder,
        'secure'      => $secureFolder,
        'space'       => trim($space, '/'),
    ];
}

/**
 * Read one marker file.
 *
 * @return array<string,mixed>|null Null when absent, unreadable or not a JSON object.
 */
function qs_deployment_marker_read(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Is this URL space safe to write into an nginx `location` line?
 *
 * The space reaches a web-server configuration file, so the charset is checked
 * at the sink rather than trusted from the record. `is_valid_relative_path()`
 * guards the FILESYSTEM meaning of a space at build time and permits characters
 * — a brace, a newline — that would close a `location` block early. Nothing
 * legitimate needs them: a URL space is path segments.
 */
function qs_space_is_config_safe(string $space): bool
{
    if ($space === '') {
        return true;
    }
    return (bool) preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', $space)
        && strpos($space, '..') === false;
}

/**
 * Directories under $serverRoot that could hold a deployment marker.
 *
 * ⚠ TWO LEVELS, NOT ONE, AND THE ASSUMPTION IS WORTH STATING. A build's secure
 * folder name is validated by `build` with `is_valid_relative_path(..., 5, ...)`
 * and its own error message offers `backend/core` as a valid answer — so
 * `$destSecure` is NOT always a direct child of the target. It is by default
 * (`secure`), and at depth 2 for the documented nested form.
 *
 * The cap is deliberate. Descending all five permitted levels from a server root
 * means walking `<public>/assets/...` and `<secure>/projects/<p>/...` on every
 * deploy, for a shape almost nobody uses. Two levels is a handful of scandir
 * calls. Deeper than that the deployment is simply not found, the root block
 * stays FREE, and that is the safe direction: a missed deployment routes nothing,
 * where a wrongly-added one would claim a domain.
 *
 * And the deploy that is happening RIGHT NOW never depends on the cap at all —
 * `deployBuild` hands its own `$destSecure` in through $extraDirs.
 *
 * @return list<string> Absolute directory paths, no duplicates.
 */
function qs_marker_candidate_dirs(string $serverRoot, array $extraDirs = []): array
{
    $dirs = [];
    $seen = [];
    $add = static function (string $d) use (&$dirs, &$seen): void {
        $key = rtrim($d, '/\\');
        if ($key !== '' && !isset($seen[$key])) { $seen[$key] = true; $dirs[] = $key; }
    };

    foreach ($extraDirs as $extra) {
        if (is_dir($extra)) { $add($extra); }
    }

    $top = @scandir($serverRoot);
    if ($top === false) {
        return $dirs;
    }
    foreach ($top as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $lvl1 = $serverRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($lvl1)) {
            continue;
        }
        $add($lvl1);

        // A directory that IS a deployment does not contain another one, so a
        // marker here ends the descent.
        if (is_file($lvl1 . DIRECTORY_SEPARATOR . QS_DEPLOY_MARKER)) {
            continue;
        }
        foreach ((array) @scandir($lvl1) as $sub) {
            if ($sub === '.' || $sub === '..' || $sub[0] === '.') {
                continue;
            }
            $lvl2 = $lvl1 . DIRECTORY_SEPARATOR . $sub;
            if (is_dir($lvl2)) { $add($lvl2); }
        }
    }
    return $dirs;
}

/**
 * Every QuickSite deployment that landed INSIDE this installation's document
 * root, newest first.
 *
 * A deployment whose `public` is NOT this install's public folder is skipped: it
 * landed BESIDE the document root, no web server is looking at it, and routing
 * for it would claim URLs that serve nothing.
 *
 * @param  string $serverRoot       The installation root (SERVER_ROOT).
 * @param  string $publicFolderName The installation's own public folder name.
 * @param  list<string> $extraDirs  Marker directories the caller already knows —
 *         `deployBuild` passes the folder it has just written, so the deployment
 *         in progress is found whatever its nesting depth.
 * @return list<array{space:string,project:string,secure:string,entry:string,deployed_at:string}>
 */
function qs_deployed_sites(string $serverRoot, string $publicFolderName, array $extraDirs = []): array
{
    $sites = [];
    foreach (qs_marker_candidate_dirs($serverRoot, $extraDirs) as $dir) {
        $marker = qs_deployment_marker_read($dir . DIRECTORY_SEPARATOR . QS_DEPLOY_MARKER);
        if ($marker === null) {
            continue;
        }
        $entry = basename($dir);

        // A marker written before this file existed carries no placement. It is
        // skipped rather than guessed at: the next deploy rewrites it, and that
        // deploy is also what regenerates the configuration.
        $public = (string) ($marker['public'] ?? '');
        if ($public === '' || $public !== $publicFolderName) {
            continue;
        }

        $space = trim((string) ($marker['space'] ?? ''), '/');
        if (!qs_space_is_config_safe($space)) {
            continue;
        }

        // THE DISK DECIDES. A marker is a claim about a front controller; if the
        // front controller is not there, there is nothing to route to.
        $entryFile = $serverRoot . DIRECTORY_SEPARATOR . $publicFolderName
            . ($space !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $space) : '')
            . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($entryFile)) {
            continue;
        }

        $sites[] = [
            'space'       => $space,
            'project'     => (string) ($marker['project'] ?? ''),
            'secure'      => (string) ($marker['secure'] ?? $entry),
            'entry'       => $entryFile,
            'deployed_at' => (string) ($marker['deployed_at'] ?? ''),
        ];
    }

    // Newest first, so that when two deployments claim the SAME space — both
    // wrote the same `<public>/<space>/index.php`, the second over the first —
    // the one that actually owns the file on disk is the one kept below.
    usort($sites, static function (array $a, array $b): int {
        return strcmp($b['deployed_at'], $a['deployed_at']);
    });

    // ⚠ ONE ROUTE PER SPACE, ALWAYS. Two rows with the same space would emit two
    // `location` blocks with the same prefix, and nginx refuses to load at all
    // with `duplicate location` — turning a deploy into an outage of everything
    // that server hosts. Deduplicating here is what makes that unreachable.
    $seen = [];
    $unique = [];
    foreach ($sites as $site) {
        if (isset($seen[$site['space']])) {
            continue;
        }
        $seen[$site['space']] = true;
        $unique[] = $site;
    }

    return $unique;
}
