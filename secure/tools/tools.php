<?php
/**
 * tools.php — the deployer's one entry to the tools in this folder (see its
 * README.md): it lists them, and runs one.
 *
 *   php secure/tools/tools.php                          list every tool, its line and how to run it
 *   php secure/tools/tools.php <tool> [options]         run that tool with those options
 *   php secure/tools/tools.php log-prune --help         a tool's own help, through the launcher
 *
 * HOW A TOOL JOINS THE LIST: by being a `.php` file in this folder whose header
 * (its first comment, within the first 8 KB) holds three lines, each a ` * ` line
 * of the comment:
 *
 *   `@qs-tool` and the tool's name, which is its file's name without `.php`;
 *   `@qs-line` and one line saying what it does;
 *   `@qs-usage` and its options, as they follow its name.
 *
 * The launcher reads that text and never runs a tool, nor loads one, to list it.
 * A file without the three lines (the shared lib/ folder, this launcher) is not
 * listed, and cannot be run through it.
 *
 * HOW IT RUNS ONE: in a process of its own, started with the PHP that runs the
 * launcher (PHP_BINARY) and no shell between them, with every option passed as it
 * was given; the tool reads and writes the launcher's own terminal, and its exit
 * code is the launcher's. Settings given to the launcher's PHP on its command line
 * (`-d …`) do not reach the tool, which reads its php.ini as PHP always does. Each
 * tool still runs on its own (`php secure/tools/<tool>.php`), which is what a cron
 * line names.
 *
 * It never prompts, and refuses a web request as every tool here does.
 *
 * Exit codes: the tool's own; 1 = the launcher refused (a web request, an unknown
 * tool, a tool that could not be started); 0 for the list.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/** How much of a file the launcher reads for its header. */
const QS_TOOLS_HEADER_BYTES = 8192;

$args = array_slice($argv ?? [], 1);
$self = $argv[0] ?? basename(__FILE__);
$tools = qs_tools_list(__DIR__);

if ($args === [] || $args[0] === '--help' || $args[0] === '-h') {
    echo "QuickSite deployer tools\n\n";
    foreach ($tools as $name => $tool) {
        printf("  %-22s %s\n  %-22s php %s %s%s\n\n", $name, $tool['line'], '', $self, $name, $tool['usage'] !== '' ? ' ' . $tool['usage'] : '');
    }
    echo "Each tool's --help says more. Each also runs on its own, as php <this folder>/<tool>.php,\n"
       . "which is what a cron line names. Run as the web server's user, so what a tool writes stays theirs.\n";
    exit(0);
}

$name = $args[0];
if (!isset($tools[$name])) {
    fwrite(STDERR, 'Unknown tool: ' . $name . ' (php ' . $self . " lists them)\n");
    exit(1);
}
if (!is_string(PHP_BINARY) || PHP_BINARY === '') {
    fwrite(STDERR, "This PHP does not know its own program, so it cannot start a tool: run the tool's file itself.\n");
    exit(1);
}
$process = @proc_open(array_merge([PHP_BINARY, $tools[$name]['file']], array_slice($args, 1)), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, 'The tool ' . $name . " could not be started (proc_open): run its file itself.\n");
    exit(1);
}
$code = proc_close($process);
exit($code >= 0 ? $code : 1);

/**
 * Every tool of the folder, by name, from its header alone: [name => file, line,
 * usage], sorted by name.
 *
 * @return array<string, array{file: string, line: string, usage: string}>
 */
function qs_tools_list(string $dir): array {
    $tools = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        if (realpath($file) === realpath(__FILE__) || !is_file($file) || is_link($file)) {
            continue;
        }
        $header = qs_tools_header($file);
        if ($header !== null) {
            $tools[$header['name']] = ['file' => $file, 'line' => $header['line'], 'usage' => $header['usage']];
        }
    }
    ksort($tools, SORT_STRING);
    return $tools;
}

/**
 * A tool's header, read as text from the start of its file: its name, its line
 * and its usage, or null when one of the three is missing or the name is not the
 * file's own. The file is never run.
 *
 * @return array{name: string, line: string, usage: string}|null
 */
function qs_tools_header(string $file): ?array {
    $h = @fopen($file, 'r');
    if ($h === false) {
        return null;
    }
    $text = (string) fread($h, QS_TOOLS_HEADER_BYTES);
    fclose($h);
    $found = [];
    foreach (['tool', 'line', 'usage'] as $tag) {
        if (!preg_match('/^[ \t]*\*[ \t]*@qs-' . $tag . '[ \t]+(\S[^\r\n]*?)[ \t]*\r?$/m', $text, $m)) {
            if ($tag === 'usage' && preg_match('/^[ \t]*\*[ \t]*@qs-usage[ \t]*\r?$/m', $text)) {
                $found[$tag] = ''; // a tool with no option
                continue;
            }
            return null;
        }
        $found[$tag] = $m[1];
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $found['tool']) || $found['tool'] !== basename($file, '.php')) {
        return null;
    }
    return ['name' => $found['tool'], 'line' => $found['line'], 'usage' => $found['usage']];
}
