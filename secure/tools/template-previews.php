<?php
/**
 * template-previews.php — builds the previews the panel shows for the site templates of
 * Start from a template. One of the tools in this folder (see its README.md); the launcher,
 * tools.php, lists it by the three lines below.
 *
 * @qs-tool   template-previews
 * @qs-line   Build the site templates' previews that are stale or missing (--check: say which)
 * @qs-usage  [--all | --check]
 *
 *   php secure/tools/template-previews.php            build the previews that are stale or missing
 *   php secure/tools/template-previews.php --all      build every preview again
 *   php secure/tools/template-previews.php --check    build nothing: say which previews are stale, exit 1 if any is
 *
 * WHAT A PREVIEW IS. For each template of <secure>/admin/workflows/templates/, and for its
 * light theme and its dark theme when it has one: the pages the engine's own build answers
 * for that template with every one of its languages, kept as static files under the panel's
 * assets (template-previews/<template>/<theme>/). A page keeps its markup and its stylesheet;
 * its addresses are made relative, so the files work wherever the installation is mounted,
 * and the full address a build writes for a shared link (og:url) is left out, as a preview has
 * no address of its own; the files it loads as scripts are left out, and of its own scripts
 * only the theme switch's stay, with the page's words block when it holds the switch's labels
 * (the label a press shows is read from it). The project's own files it links (a template's photos) are
 * kept once for both themes, beside them, byte for byte. The panel shows a preview in a frame that is given no access to
 * the panel.
 *
 * A LINK THAT LEADS OUT OF A PREVIEW. A preview holds its own pages and languages, as the
 * build wrote them, and nothing else. A link to anything else (a booking page, a call, a
 * letter, a page the preview does not hold) is switched off in the preview's copy: its
 * address becomes `#` and it is marked. A page that holds such a link keeps one script of
 * this tool's: a press on a marked link is stopped, and the page tells the panel's dialog
 * with one fixed message that carries nothing, so the dialog can say the link works on a
 * real site. The site a run makes keeps its links.
 *
 * WHY THIS IS A SCRIPT AND NOT A COMMAND. It rewrites files QuickSite itself ships, for
 * the whole installation: no project role can express that, as for the other tools here.
 * It is run by whoever changes a template or releases a version, from a shell.
 *
 * HOW IT WORKS. It touches no account and no project of this installation. It copies
 * the engine's own files to a temporary folder, serves that copy with PHP's built-in
 * server on a free local port, signs in there with an account it makes for the run,
 * runs each template on a project of that copy as the panel runs it, builds it, serves
 * the build the same way, fetches every page, and removes the copy. It needs PHP's
 * command line alone.
 *
 * WHAT IT REFUSES. A template fits a phone, a tablet and a computer at QuickSite's two
 * limits, the panel's own: a media query that names a width is `(max-width: 64rem)` or
 * `(max-width: 40rem)`, written so; a stylesheet holds at most one block of each, the
 * tablet's first, and no plain rule after them (it would win over them). A template that
 * breaks it gets no preview, and --check names it. A query that names no width is not
 * this rule's (see docs/WORKFLOW_SYSTEM.md, Site templates).
 *
 * WHEN A PREVIEW IS STALE. Each preview records the hash of the template it was built
 * from and a fingerprint of the engine's files that built it (the engine's source folder,
 * every command, the workflows a template's run goes through, and this tool), and the hash of
 * each photo the template brings in. A preview whose template or one of its photos changed is
 * stale; so is one built by another engine, whatever the version number says. --check is what
 * a release runs.
 *
 * Exit codes: 0 = every preview is fresh (after building, or as checked); 1 = refused
 * to run, a build failed, --check found a stale preview, or a template breaks the rule
 * of the three views.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// The engine folder is this script's parent, whatever setup renamed it to.
define('QS_TP_SECURE', str_replace('\\', '/', dirname(__DIR__)));
define('QS_TP_ROOT', dirname(QS_TP_SECURE));
/** The URL prefix every preview is built for, taken out of each page afterwards. */
const QS_TP_SPACE = 'qs-template-preview';
/** The project name written where a page names its project, in place of the run's own. */
const QS_TP_PROJECT = 'template-preview';
/** The row a template is run through, and the workflows that run goes through. */
const QS_TP_WORKFLOW = 'start-from-template';
const QS_TP_WORKFLOWS = ['start-from-template', 'fresh-start', 'setup-languages', 'update-lang-switch', 'setup-theme-switch'];
/** An attribute only the site's runtime serves: a page that carries one cannot be previewed without it. */
const QS_TP_NEEDS_RUNTIME = '/\s(on[a-z]+|data-bind[a-z-]*|data-list-template|data-(?:state|storage|auth|consent)-[a-z-]+)=/i';
/** What marks a link of a preview page that leads out of the preview: its address is gone, and a press on it tells the panel's dialog. */
const QS_TP_OUTSIDE = 'data-preview-outside';
/**
 * The script a preview page keeps for those links, the tool's own: a press on one is stopped, and the page tells its parent
 * with one fixed message, { qsTemplatePreview: "outside-link" }, which carries no address and no text. The dialog that shows
 * the preview (preview-ai-tools.js, _openTemplatePreview()) expects exactly that shape, from its own frame's window alone.
 */
const QS_TP_SCRIPT = '<script>document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[' . QS_TP_OUTSIDE . ']"):null;'
    . 'if(!a)return;e.preventDefault();try{window.parent.postMessage({qsTemplatePreview:"outside-link"},"*");}catch(x){}},true);</script>';
/** The only widths a template's stylesheet names: QuickSite's two limits, a tablet up to 64rem and a phone up to 40rem, in this order. */
const QS_TP_TABLET = '(max-width: 64rem)';
const QS_TP_PHONE = '(max-width: 40rem)';

$args = array_slice($argv ?? [], 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "QuickSite — template previews\n\n"
       . '  php ' . basename(__FILE__) . "            build the previews that are stale or missing\n"
       . '  php ' . basename(__FILE__) . " --all      build every preview again\n"
       . '  php ' . basename(__FILE__) . " --check    build nothing: say which are stale, exit 1 if any is\n\n"
       . "Builds, for each site template, the pages the panel shows as its preview.\n"
       . "Touches no account and no project of this installation.\n";
    exit(0);
}
$unknown = array_diff($args, ['--all', '--check']);
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . implode(' ', $unknown) . " (see --help)\n");
    exit(1);
}
$all = in_array('--all', $args, true);
$checkOnly = in_array('--check', $args, true);

// ── The installation's layout ─────────────────────────────────────────────────

$conf = [];
foreach (@file(QS_TP_ROOT . '/.quicksite.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) $conf[$m[1]] = trim($m[2]);
}
$publicName = ($conf['PUBLIC_FOLDER_NAME'] ?? '') !== '' ? $conf['PUBLIC_FOLDER_NAME'] : 'public';
$public = QS_TP_ROOT . '/' . $publicName;
if (!is_file($public . '/init.php') || !is_dir($public . '/admin/assets')) {
    fwrite(STDERR, "The public folder was not found beside the engine folder (looked for '$publicName', the name .quicksite.conf gives).\n");
    exit(1);
}
$templatesDir = QS_TP_SECURE . '/admin/workflows/templates';
$previewsDir = $public . '/admin/assets/template-previews';

// ── What is stale ─────────────────────────────────────────────────────────────

/** A file's text with one kind of line ending, so a checkout's endings do not change a hash. */
function qs_tp_text(string $file): string {
    return str_replace("\r\n", "\n", (string) file_get_contents($file));
}

/** The fingerprint of the engine's files that build a preview: their paths and their texts. */
function qs_tp_engine(): string {
    $files = [];
    // The engine's source, and every command: a template's run sends commands, and the build is one. The whole folder is
    // read, so a command a template comes to send later is covered with no edit here.
    foreach ([QS_TP_SECURE . '/src', QS_TP_SECURE . '/management/command'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    foreach (QS_TP_WORKFLOWS as $id) $files[] = QS_TP_SECURE . '/admin/workflows/core/' . $id . '.json';
    $files[] = str_replace('\\', '/', __FILE__);
    sort($files);
    $h = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($h, substr($file, strlen(QS_TP_SECURE)) . "\n" . hash('sha256', qs_tp_text($file)) . "\n");
    }
    return hash_final($h);
}

/**
 * The photos a template's steps bring in (uploadAsset's templatePhoto), by name, each with the hash of the file shipped in
 * the photos' folder ('' when it is missing): a photo that changes makes its template's preview stale.
 */
function qs_tp_photos(string $templateFile): array {
    $t = json_decode((string) @file_get_contents($templateFile), true);
    $photos = [];
    foreach ((array) ($t['steps'] ?? []) as $step) {
        if (!is_array($step) || ($step['command'] ?? null) !== 'uploadAsset') continue;
        $name = $step['params']['templatePhoto'] ?? null;
        if (!is_string($name) || !preg_match('/^[a-z0-9-]+\.[a-z]+$/', $name)) continue;
        $file = QS_TP_SECURE . '/admin/workflows/templates/photos/' . $name;
        $photos[$name] = is_file($file) ? hash_file('sha256', $file) : '';
    }
    ksort($photos);
    return $photos;
}

/** Why a template's preview is stale, or null when it is fresh. */
function qs_tp_stale(string $id, string $templateFile, string $previewsDir, string $engine): ?string {
    $manifest = json_decode((string) @file_get_contents("$previewsDir/$id/preview.json"), true);
    if (!is_array($manifest)) return 'it has no preview';
    if (($manifest['template'] ?? null) !== hash('sha256', qs_tp_text($templateFile))) return 'its template changed';
    if (($manifest['engine'] ?? null) !== $engine) return 'it was built by another engine';
    if ((array) ($manifest['photos'] ?? []) !== qs_tp_photos($templateFile)) return 'a photo of it changed';
    foreach ((array) ($manifest['files'] ?? []) as $rel) {
        if (!is_string($rel) || !is_file("$previewsDir/$id/$rel")) return 'a file of it is missing';
    }
    return null;
}

/** Why one media query breaks the rule of the three views, or null: a query that names a width names one of the two, written so. */
function qs_tp_width_query(string $query): ?string {
    $query = preg_replace('/\s+/', ' ', trim($query));
    if (!preg_match('/(^|[^a-z-])((min|max)-)?(device-)?width\b/i', $query)) return null;
    return in_array($query, [QS_TP_TABLET, QS_TP_PHONE], true) ? null : "a width that is neither the tablet's nor the phone's: @media $query";
}

/**
 * Why a template breaks the rule of the three views, or null. Every text of the template file is read: a stylesheet it
 * sends (any text holding a media query), and the media query a step names for one rule (`mediaQuery`). In a stylesheet,
 * at most one block of each width, the tablet's first, and after the first of them only at-rules: a plain rule after them
 * would override them.
 */
function qs_tp_widths(array $template): ?string {
    $found = null;
    array_walk_recursive($template, static function ($value, $key) use (&$found): void {
        if ($found !== null || !is_string($value)) return;
        if ($key === 'mediaQuery') { $found = qs_tp_width_query($value); return; }
        if (stripos($value, '@media') === false) return;
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $value);
        preg_match_all('/@media\b([^{]*)\{/i', $css, $m, PREG_OFFSET_CAPTURE);
        $seen = [];
        foreach ($m[1] as $i => [$query, ]) {
            $query = preg_replace('/\s+/', ' ', trim($query));
            if (($why = qs_tp_width_query($query)) !== null) { $found = $why; return; }
            if (!in_array($query, [QS_TP_TABLET, QS_TP_PHONE], true)) continue;
            if (isset($seen[$query])) { $found = "a second block of @media $query"; return; }
            if ($query === QS_TP_TABLET && isset($seen[QS_TP_PHONE])) { $found = 'the phone block before the tablet block'; return; }
            $seen[$query] = $m[0][$i][1];
        }
        if ($seen === []) return;
        // From the first width block on, a rule at the top level is an at-rule: a plain rule there would win over the blocks.
        $depth = 0;
        $prelude = '';
        for ($at = min($seen), $n = strlen($css); $at < $n; $at++) {
            $c = $css[$at];
            if ($c === '{') {
                if ($depth === 0 && ($p = trim($prelude)) !== '' && $p[0] !== '@') { $found = "a rule after the width blocks: $p"; return; }
                $depth++;
                $prelude = '';
            } elseif ($c === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0) {
                $prelude .= $c;
            }
        }
    });
    return $found;
}

$templates = [];
foreach (glob($templatesDir . '/*.json') ?: [] as $file) {
    $t = json_decode((string) file_get_contents($file), true);
    $id = basename($file, '.json');
    if (!is_array($t) || ($t['id'] ?? null) !== $id || !is_array($t['languages'] ?? null) || $t['languages'] === []) {
        echo "  skipped    $id: not a template\n";
        continue;
    }
    $templates[$id] = ['file' => $file, 'languages' => array_values($t['languages']), 'dark' => !empty($t['data']['dark']), 'widths' => qs_tp_widths($t)];
}
ksort($templates);
$engine = qs_tp_engine();
$todo = [];
echo 'QuickSite template previews' . ($checkOnly ? ' (check)' : '') . "\n";
$refused = 0;
foreach ($templates as $id => $t) {
    if ($t['widths'] !== null) {
        printf("  %-10s %s: %s\n", 'refused', $id, $t['widths']);
        $refused++;
        continue;
    }
    $why = qs_tp_stale($id, $t['file'], $previewsDir, $engine);
    printf("  %-10s %s%s\n", $why === null ? 'fresh' : 'stale', $id, $why === null ? '' : ": $why");
    if ($why !== null || $all) $todo[$id] = $t;
}
// A preview whose template is gone is removed with the next build, and is stale until then.
$orphans = [];
foreach (glob($previewsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (!isset($templates[basename($dir)])) $orphans[] = basename($dir);
}
foreach ($orphans as $id) printf("  %-10s %s: its template is gone\n", 'stale', $id);

if ($checkOnly) {
    $stale = count(array_filter(array_keys($templates), static fn($id) => qs_tp_stale($id, $templates[$id]['file'], $previewsDir, $engine) !== null)) + count($orphans);
    if ($refused > 0) echo "$refused refused: a template's stylesheet names only QuickSite's two widths (see docs/WORKFLOW_SYSTEM.md, Site templates).\n";
    echo $stale === 0 ? "Every preview is fresh.\n" : "$stale stale: run this tool without --check, and keep what it writes.\n";
    exit($stale === 0 && $refused === 0 ? 0 : 1);
}
if ($todo === [] && $orphans === []) {
    echo $refused > 0 ? "Nothing to build; $refused refused.\n" : "Nothing to build.\n";
    exit($refused > 0 ? 1 : 0);
}

// ── The run ───────────────────────────────────────────────────────────────────

foreach (['proc_open', 'proc_terminate', 'stream_socket_server'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "This tool starts PHP's built-in server: $fn() is not available in this PHP.\n");
        exit(1);
    }
}
if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "This tool reads its own local server: allow_url_fopen is off in this PHP (run it with -d allow_url_fopen=1).\n");
    exit(1);
}

function qs_tp_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function qs_tp_copy(string $from, string $to, array $skip = []): void {
    if (is_file($from)) {
        if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
        copy($from, $to);
        return;
    }
    if (!is_dir($from)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($from) + 1);
        foreach ($skip as $prefix) if (strpos($rel, $prefix) === 0) continue 2;
        if (!is_dir(dirname("$to/$rel"))) mkdir(dirname("$to/$rel"), 0755, true);
        copy($f->getPathname(), "$to/$rel");
    }
}

/** A local port nothing listens on. */
function qs_tp_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$s) throw new RuntimeException("no local port: $errstr");
    $port = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

/**
 * PHP's built-in server on a folder, behind a router; returns the process. It runs with OPcache off, so it reads every
 * file as it is on disk: a run rewrites files and reads them back within the two seconds a cached file is trusted for,
 * and a cache shared between PHP processes would hand one build the files of the build before it.
 */
function qs_tp_serve(string $docroot, string $router, int $port, string $log) {
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", '-t', $docroot, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('the built-in server did not start');
    fclose($pipes[0]);
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.3);
        if ($s) { fclose($s); return $proc; }
        usleep(100000);
    }
    proc_terminate($proc);
    throw new RuntimeException('the built-in server did not answer');
}

function qs_tp_stop($proc): void {
    if (!is_resource($proc)) return;
    if (proc_get_status($proc)['running']) proc_terminate($proc);
    proc_close($proc);
    usleep(200000);
}

/** One request to a local server: [status, decoded JSON or null, body]. */
function qs_tp_http(int $port, string $method, string $uri, $body = null, string $token = ''): array {
    static $cookies = []; // the run's own session on its own copy, by port
    $headers = ['Host: 127.0.0.1:' . $port, 'Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    if (!empty($cookies[$port])) $headers[] = 'Cookie: ' . implode('; ', $cookies[$port]);
    $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 300, 'follow_location' => 0];
    if ($body !== null) {
        $opts['content'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts['header'] = implode("\r\n", $headers);
    $text = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => $opts]));
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
        if (stripos($h, 'Set-Cookie:') === 0) {
            $pair = trim(explode(';', substr($h, 11))[0]);
            $cookies[$port][explode('=', $pair)[0]] = $pair;
        }
    }
    $json = json_decode($text, true);
    return [$status, is_array($json) ? $json : null, $text];
}

/** The address a link of a built page points at, from the folder of the page that holds it; null for one left as it is. */
function qs_tp_relative(string $target, array $langs, array $pages, string $up, array &$assets): ?string {
    $prefix = '/' . QS_TP_SPACE . '/';
    if (strpos($target, $prefix) !== 0) return null;
    $rest = substr($target, strlen($prefix));
    $hash = '';
    if (($at = strpos($rest, '#')) !== false) { $hash = substr($rest, $at); $rest = substr($rest, 0, $at); }
    $rest = preg_replace('/\?.*$/', '', $rest);
    if (preg_match('/\.[A-Za-z0-9]{2,5}$/', $rest)) {
        // A file the page links: kept beside the pages, at its own place. A project's own file (a template's photo) is the
        // same in every theme, so it is kept once, beside the themes.
        $assets[$rest] = true;
        return (strpos($rest, 'assets/') === 0 ? $up . '../' : $up) . $rest . $hash;
    }
    $parts = $rest === '' ? [] : explode('/', trim($rest, '/'));
    $lang = '';
    if ($langs !== [] && $parts !== [] && in_array($parts[0], $langs, true)) $lang = array_shift($parts) . '/';
    elseif ($langs !== []) $lang = $langs[0] . '/';
    $page = $parts === [] ? 'home' : implode('/', $parts);
    if (!in_array($page, $pages, true)) return '#';
    return $up . $lang . $page . '.html' . $hash;
}

/**
 * Whether a link's address, as a preview keeps it, leads out of the preview: one qs_tp_relative() left as it is that is no
 * place in the same page (a web address, a call, a letter, a path of the site's host), or the `#` it gives a page the
 * preview does not hold. The preview's own pages and languages, as the build wrote them, are the only list.
 */
function qs_tp_leads_out(string $href): bool {
    return $href === '#' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/)~i', $href) === 1;
}

/**
 * A page's links, as a preview keeps them: one that leads out of the preview is switched off (its address becomes `#`) and
 * marked with QS_TP_OUTSIDE, in the preview's copy alone. Returns how many were marked.
 */
function qs_tp_outside(string &$html): int {
    $marked = 0;
    $html = (string) preg_replace_callback('#<a\b[^>]*>#i', static function (array $m) use (&$marked): string {
        if (!preg_match('/\bhref="([^"]*)"/', $m[0], $h)) return $m[0];
        if (!qs_tp_leads_out(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) return $m[0];
        $marked++;
        return str_replace($h[0], 'href="#" ' . QS_TP_OUTSIDE, $m[0]);
    }, $html);
    return $marked;
}

/** A built page as a preview keeps it: relative addresses, no loaded script, the theme switch's own scripts and words only, its outside links switched off. */
function qs_tp_page(string $html, string $projectId, array $langs, array $pages, string $up, array &$assets, bool &$scripts): string {
    $html = preg_replace('#<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>#i', '', $html);
    $html = preg_replace_callback('#<script\b[^>]*>(.*?)</script>#is', static function (array $m) use (&$scripts): string {
        // The page's words block (window.QS_VISITOR_WORDS) holds the switch's labels when the switch is wired: kept with it.
        $switchWords = strncmp($m[1], 'window.QS_VISITOR_WORDS=', 24) === 0 && strpos($m[1], '"themeSwitch"') !== false;
        if (!$switchWords && strpos($m[1], 'data-theme') === false) return '';
        $scripts = true;
        return $m[0];
    }, $html);
    $html = str_replace($projectId, QS_TP_PROJECT, $html);
    $html = preg_replace_callback('/\b(href|src|action)="([^"]*)"/', static function (array $m) use ($langs, $pages, $up, &$assets): string {
        $to = qs_tp_relative(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $langs, $pages, $up, $assets);
        return $to === null ? $m[0] : $m[1] . '="' . htmlspecialchars($to, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }, $html);
    // A preview has no address of its own: the full address a build writes for a shared link names the server this tool
    // fetched the page from, which is gone once the tool ends and differs from one build to the next. Its line is left out.
    $html = preg_replace('#^[ \t]*<meta\s+property="og:url"\s[^>]*>[ \t]*\r?\n#mi', '', $html);
    $html = preg_replace('#<meta\s+property="og:url"\s[^>]*>#i', '', $html);
    // A link that leads out of the preview goes nowhere here; a press on it is stopped and the page tells the panel's dialog,
    // with one fixed message that carries nothing (QS_TP_SCRIPT). The page keeps that script only when it holds such a link.
    if (qs_tp_outside($html) > 0) {
        $at = strripos($html, '</body>');
        if ($at !== false) {
            $html = substr($html, 0, $at) . QS_TP_SCRIPT . "\n" . substr($html, $at);
            $scripts = true;
        }
    }
    // Lines that end with spaces only differ from one build to the next by nothing a browser shows.
    return rtrim(preg_replace('/[ \t]+\n/', "\n", str_replace("\r\n", "\n", $html))) . "\n";
}

$temp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/qs-template-previews-' . bin2hex(random_bytes(6));
$panel = null;
$site = null;
$failed = false;
try {
    // The engine's own files, and nothing an installation adds: no account, no project but the starter, no setting.
    $secureName = basename(QS_TP_SECURE);
    foreach (['init.php', '.htaccess', 'admin', 'management', 'p'] as $entry) {
        qs_tp_copy("$public/$entry", "$temp/$publicName/$entry", ['assets/template-previews/']);
    }
    foreach (['admin', 'src', 'snippets'] as $entry) qs_tp_copy(QS_TP_SECURE . "/$entry", "$temp/$secureName/$entry", ['config/']);
    foreach (glob(QS_TP_SECURE . '/admin/config/*.example') ?: [] as $f) qs_tp_copy($f, "$temp/$secureName/admin/config/" . basename($f));
    qs_tp_copy(QS_TP_SECURE . '/management', "$temp/$secureName/management", ['config/']);
    foreach (glob(QS_TP_SECURE . '/management/config/*') ?: [] as $f) {
        $base = basename($f);
        if (preg_match('/\.example$/', $base) || in_array($base, ['categories.php', 'assetCategories.php'], true)) {
            qs_tp_copy($f, "$temp/$secureName/management/config/$base");
        }
    }
    qs_tp_copy(QS_TP_SECURE . '/projects/quicksite', "$temp/$secureName/projects/quicksite", ['backups/', 'qs_build/']);
    foreach (['.quicksite.conf', 'VERSION', 'LICENSE'] as $entry) qs_tp_copy(QS_TP_ROOT . "/$entry", "$temp/$entry");
    foreach (['logs', 'tmp/sessions', 'cache'] as $dir) @mkdir("$temp/$secureName/$dir", 0755, true);

    // An account for this run alone: its password is made here, used once, and goes with the copy.
    $password = bin2hex(random_bytes(16));
    $users = ['users' => ['usr_' . bin2hex(random_bytes(16)) => [
        'name' => 'Template previews', 'username' => 'template_previews', 'status' => 'active',
        'password_hash' => password_hash($password, PASSWORD_BCRYPT), 'session_generation' => 0,
        'selected_project' => null, 'projects' => [],
    ]]];
    file_put_contents("$temp/$secureName/management/config/users.php", "<?php\nreturn " . var_export($users, true) . ";\n");

    // What the web server's rules do for the panel, for PHP's built-in server.
    $router = "$temp/router.php";
    file_put_contents($router, <<<'PHP'
<?php
$docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($docroot . rawurldecode($path))) return false;
foreach (['/admin/api/', '/admin/self/', '/admin/state/', '/admin/', '/management/', '/p/'] as $prefix) {
    if (strpos($path . '/', $prefix) === 0) {
        $script = $prefix . 'index.php';
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['SCRIPT_FILENAME'] = $docroot . $script;
        $_SERVER['PHP_SELF'] = $script;
        chdir(dirname($docroot . $script));
        require $docroot . $script;
        return true;
    }
}
return false;
PHP);
    $panelPort = qs_tp_port();
    $panel = qs_tp_serve("$temp/$publicName", $router, $panelPort, "$temp/panel.log");
    [$st, $json] = qs_tp_http($panelPort, 'POST', '/management/login', ['username' => 'template_previews', 'password' => $password]);
    $token = (string) ($json['data']['session_token'] ?? '');
    if ($token === '') throw new RuntimeException("the copy refused the run's sign-in ($st)");

    foreach ($orphans as $id) {
        qs_tp_rmdir("$previewsDir/$id");
        echo "  removed    $id\n";
    }
    foreach ($todo as $id => $t) {
        $written = [];
        $scripts = false;
        $work = "$temp/out/$id";
        foreach ($t['dark'] ? ['light', 'dark'] : ['light'] as $theme) {
            [$st, $json, $raw] = qs_tp_http($panelPort, 'POST', '/management/createProject', ['name' => "Preview $id $theme", 'language' => 'en', 'switch_to' => true], $token);
            $pid = (string) ($json['data']['project_id'] ?? $json['data']['id'] ?? $json['data']['project'] ?? '');
            if ($pid === '') throw new RuntimeException("$id: no project for its run ($st)");
            $several = count($t['languages']) > 1;
            $params = ['template' => $id, 'language' => $t['languages'][0], 'multilingual' => $several,
                'languages' => $several ? $t['languages'] : [], 'darkMode' => $t['dark']];
            // The run, as the panel's runner sends it: the plan, then each phase resolved when it is reached, each step in order.
            [$st, $json] = qs_tp_http($panelPort, 'POST', "/admin/api/p/$pid/workflow-generate-steps", ['workflowId' => QS_TP_WORKFLOW, 'params' => $params], $token);
            $phases = $json['data']['phases'] ?? null;
            if ($st !== 200 || !is_array($phases) || $phases === []) throw new RuntimeException("$id: its run could not be planned ($st)");
            $steps = 0;
            foreach ($phases as $i => $entry) {
                if (!empty($entry['ai'])) throw new RuntimeException("$id: its run asks a model, which this tool never answers");
                [$st, $json] = qs_tp_http($panelPort, 'POST', "/admin/api/p/$pid/workflow-generate-steps", ['workflowId' => QS_TP_WORKFLOW, 'params' => $params, 'phase' => $i], $token);
                if ($st !== 200) throw new RuntimeException("$id: a phase of its run could not be resolved ($st)");
                foreach (($json['data']['phase']['steps'] ?? []) as $step) {
                    $method = strtoupper((string) ($step['method'] ?? 'POST'));
                    $stepParams = is_array($step['params'] ?? null) ? $step['params'] : [];
                    $uri = "/management/p/$pid/" . $step['command'] . ($method === 'GET' && $stepParams ? '?' . http_build_query($stepParams) : '');
                    [$st, , $raw] = qs_tp_http($panelPort, $method, $uri, $method === 'GET' ? null : ($stepParams ?: (object) []), $token);
                    $steps++;
                    if ($st < 200 || $st >= 300) throw new RuntimeException("$id: its step {$step['command']} failed ($st): " . substr($raw, 0, 200));
                }
            }
            // The dark preview opens in the dark theme: the one thing this tool sets that a run does not.
            if ($theme === 'dark') {
                [$st] = qs_tp_http($panelPort, 'POST', "/management/p/$pid/setThemeMode", ['default' => 'dark'], $token);
                if ($st !== 200) throw new RuntimeException("$id: its dark theme could not be made the first ($st)");
            }
            [$st, $json, $raw] = qs_tp_http($panelPort, 'POST', "/management/p/$pid/build", ['name' => 'preview', 'space' => QS_TP_SPACE], $token);
            if ($st !== 201) throw new RuntimeException("$id: its build failed ($st): " . substr($raw, 0, 200));
            $build = "$temp/$secureName/projects/$pid/qs_build/preview";
            $manifest = json_decode((string) file_get_contents("$build/build_manifest.json"), true);
            $langs = !empty($manifest['multilingual']) ? array_values((array) $manifest['languages']) : [];
            // The template's own order of languages, whatever order the project holds them in.
            if ($langs !== []) $langs = array_values(array_intersect($t['languages'], $langs));
            $pages = array_values(array_filter((array) $manifest['compiled_pages'], static fn($p) => $p !== '404'));
            $buildPublic = "$build/public";
            // A router of its own for each build: no build's server is started on a file another build's server ran.
            $siteRouter = "$temp/site-router-$pid.php";
            file_put_contents($siteRouter, '<?php $p = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
                . ' if ($p !== "/" && is_file($_SERVER["DOCUMENT_ROOT"] . rawurldecode($p))) return false;'
                . ' require ' . var_export("$buildPublic/" . QS_TP_SPACE . '/index.php', true) . ';');
            $sitePort = qs_tp_port();
            $site = qs_tp_serve($buildPublic, $siteRouter, $sitePort, "$temp/site.log");
            $assets = [];
            $count = 0;
            foreach ($langs !== [] ? $langs : [''] as $lang) {
                foreach ($pages as $page) {
                    $uri = '/' . QS_TP_SPACE . ($lang !== '' ? "/$lang" : '') . ($page === 'home' ? '/' : "/$page");
                    [$st, , $html] = qs_tp_http($sitePort, 'GET', $uri);
                    if ($st !== 200 || stripos($html, '<html') === false) throw new RuntimeException("$id: its built page $uri did not answer ($st)");
                    if (preg_match(QS_TP_NEEDS_RUNTIME, $html, $m)) {
                        throw new RuntimeException("$id: its page $uri carries " . trim($m[1]) . ', which needs the site\'s runtime: a preview does not carry it');
                    }
                    $rel = "$theme/" . ($lang !== '' ? "$lang/" : '') . "$page.html";
                    $up = str_repeat('../', substr_count($rel, '/') - 1);
                    if (!is_dir(dirname("$work/$rel"))) mkdir(dirname("$work/$rel"), 0755, true);
                    file_put_contents("$work/$rel", qs_tp_page($html, $pid, $langs, $pages, $up, $assets, $scripts));
                    $written[] = $rel;
                    $count++;
                }
            }
            qs_tp_stop($site);
            $site = null;
            foreach (array_keys($assets) as $rel) {
                $from = "$buildPublic/" . QS_TP_SPACE . "/$rel";
                if (strpos($rel, '..') !== false || !is_file($from)) continue;
                // A project's own file once for every theme, any other (the stylesheet) once per theme.
                $to = strpos($rel, 'assets/') === 0 ? $rel : "$theme/$rel";
                // Text with one kind of line ending; anything else (a photo) byte for byte, as a line ending's bytes may
                // stand anywhere in a compressed image.
                $bytes = preg_match('/\.(?:css|js|json|svg|txt|html?)$/i', $rel) ? qs_tp_text($from) : (string) file_get_contents($from);
                if (is_file("$work/$to") && file_get_contents("$work/$to") !== $bytes) {
                    throw new RuntimeException("$id: $rel is not the same in its two themes");
                }
                if (!is_dir(dirname("$work/$to"))) mkdir(dirname("$work/$to"), 0755, true);
                file_put_contents("$work/$to", $bytes);
                if (!in_array($to, $written, true)) $written[] = $to;
            }
            printf("  built      %s, %s: %d steps, %d pages%s\n", $id, $theme, $steps, $count, $langs !== [] ? ' in ' . implode(', ', $langs) : '');
        }
        sort($written);
        $preview = [
            'template' => hash('sha256', qs_tp_text($t['file'])),
            'engine' => $engine,
            'themes' => $t['dark'] ? ['light', 'dark'] : ['light'],
            'languages' => count($t['languages']) > 1 ? $t['languages'] : [],
            'pages' => $pages,
            // Whether a page kept a script (the theme switch's): the frame that shows the preview lets scripts run only then.
            'scripts' => $scripts,
            'photos' => qs_tp_photos($t['file']),
            'files' => $written,
        ];
        file_put_contents("$work/preview.json", json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        // The whole preview replaced at once: a file a template no longer builds does not stay.
        qs_tp_rmdir("$previewsDir/$id");
        qs_tp_copy($work, "$previewsDir/$id");
    }
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, '  FAILED     ' . $e->getMessage() . "\n");
    foreach (['panel.log', 'site.log'] as $log) {
        $tail = array_slice(@file("$temp/$log", FILE_IGNORE_NEW_LINES) ?: [], -5);
        foreach ($tail as $line) fwrite(STDERR, "             $log: $line\n");
    }
} finally {
    qs_tp_stop($site);
    qs_tp_stop($panel);
    qs_tp_rmdir($temp);
}
if ($failed) {
    echo "No preview was replaced by a build that failed.\n";
    exit(1);
}
$stale = 0;
foreach ($templates as $id => $t) if (qs_tp_stale($id, $t['file'], $previewsDir, $engine) !== null) $stale++;
if ($refused > 0) echo "$refused refused: no preview built for it.\n";
echo $stale === 0 ? "Every preview is fresh.\n" : "$stale still stale.\n";
exit($stale === 0 && $refused === 0 ? 0 : 1);
