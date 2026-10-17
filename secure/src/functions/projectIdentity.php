<?php
/**
 * A project's identity: the id the engine draws for it, and the name its owner gives it.
 *
 * THE ID is the project's folder under <secure>/projects/, its `/p/<id>/` address, every
 * command's `p/<id>` marker, its log bucket and its browser-storage prefix (`qsp_<id>_`). A new
 * project's id is drawn here, never chosen: nine characters, a letter first, from an alphabet
 * without the characters people misread when they type one (0 o 1 l i). Random and of fixed
 * length, it is hard to guess — a project can be public for a client without its address being
 * found by trying names — and it holds no `_`, so two projects can never build the same storage
 * name. Every drawn id passes is_valid_project_name() (PathManagement.php), which stays the rule
 * for READING an id: a project made before ids were drawn keeps the one it has.
 *
 * THE NAME is free text the owner chooses, shown wherever the project is listed ("Name #id"). It
 * lives in the project, in config/project.json — per-project data, so JSON — and is read when it
 * is shown: nothing else keeps a copy. Two projects may share a name; the id tells them apart. A
 * project without the file (or with one that does not hold a valid name) is shown by its id.
 *
 * The name is the panel's label for the project. What a visitor reads as the site's name is the
 * `site.name` translation, the site's own text, which a rename never touches.
 */

require_once __DIR__ . '/PathManagement.php';   // is_valid_project_name
require_once __DIR__ . '/jsonIo.php';           // qs_json_write, qs_json_file_bytes

/** A drawn id's length. */
const QS_PROJECT_ID_LENGTH = 9;

/** The characters an id starts with: a–z without i, l and o. */
const QS_PROJECT_ID_FIRST = 'abcdefghjkmnpqrstuvwxyz';

/** The characters of the rest of the id: the same letters and the digits 2–9. */
const QS_PROJECT_ID_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

/** Ids never handed out, whatever their length: folder names the engine keeps for itself. */
const QS_PROJECT_ID_RESERVED = ['admin', 'management', 'src', 'logs', 'config', 'projects'];

/** Draws before giving up. A clash is about one in 10^13; reaching this means something else is wrong. */
const QS_PROJECT_ID_ATTEMPTS = 100;

/** Where a project keeps its name, relative to its folder. */
const QS_PROJECT_NAME_FILE = 'config/project.json';

/** The longest name, in characters. */
const QS_PROJECT_NAME_MAX = 60;

/** One id, drawn. Whether it is free is qs_project_id_available()'s question. */
function qs_project_id_draw(): string
{
    $id = QS_PROJECT_ID_FIRST[random_int(0, strlen(QS_PROJECT_ID_FIRST) - 1)];
    for ($i = 1; $i < QS_PROJECT_ID_LENGTH; $i++) {
        $id .= QS_PROJECT_ID_ALPHABET[random_int(0, strlen(QS_PROJECT_ID_ALPHABET) - 1)];
    }
    return $id;
}

/**
 * Whether an id may be given to a new project: not reserved, and nothing of that name in the
 * projects folder or the log folder — a log left by a project deleted outside the engine would
 * otherwise become the new project's history.
 */
function qs_project_id_available(string $id): bool
{
    if (!is_valid_project_name($id) || in_array(strtolower($id), QS_PROJECT_ID_RESERVED, true)) {
        return false;
    }
    $paths = [SECURE_FOLDER_PATH . '/projects/' . $id];
    if (defined('LOGS_PATH')) {
        $paths[] = LOGS_PATH . '/p/' . $id;
    }
    foreach ($paths as $path) {
        if (file_exists($path) || is_link($path)) {
            return false;
        }
    }
    return true;
}

/**
 * Draw a free id and create its project folder, exclusively: two requests that draw the same id
 * cannot share a folder. Returns the id, or null when no folder could be made.
 *
 * @param callable|null $draw the drawing function (qs_project_id_draw() when null)
 */
function qs_project_id_claim(?callable $draw = null): ?string
{
    $draw ??= 'qs_project_id_draw';
    $root = SECURE_FOLDER_PATH . '/projects';
    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
        return null;
    }
    for ($i = 0; $i < QS_PROJECT_ID_ATTEMPTS; $i++) {
        $id = (string) $draw();
        if (qs_project_id_available($id) && @mkdir($root . '/' . $id, 0755)) {
            return $id;
        }
    }
    error_log('qs_project_id_claim: no free project id after ' . QS_PROJECT_ID_ATTEMPTS . ' draws');
    return null;
}

/**
 * A project id as a person typed it: surrounding spaces and one leading `#` removed (a project is
 * shown as "Name #id", so the id is often copied with its `#`). The case is kept: a drawn id is
 * lowercase, and an id made before ids were drawn may not be.
 */
function qs_project_id_from_input(string $value): string
{
    $value = trim($value);
    if (strpos($value, '#') === 0) {
        $value = trim(substr($value, 1));
    }
    return $value;
}

/**
 * Why a name cannot be used, or null when it can. $clean receives the name as it is kept: trimmed.
 * The rule: text of 1 to QS_PROJECT_NAME_MAX characters once trimmed, valid UTF-8, with no control
 * character (U+0000–U+001F, U+007F–U+009F) — refused, never stripped, so what is kept is what was
 * sent.
 *
 * @return array{0: int, 1: string, 2: string, 3: string}|null [status, code, reason, message]
 */
function qs_project_name_problem($value, ?string &$clean = null): ?array
{
    $clean = null;
    if (!is_string($value)) {
        return [400, 'validation.invalid_type', 'invalid_type', 'The name must be text.'];
    }
    $name = trim($value);
    if ($name === '') {
        return [400, 'validation.missing_field', 'required', 'A project needs a name.'];
    }
    if (!mb_check_encoding($name, 'UTF-8')) {
        return [400, 'validation.unencodable', 'invalid_encoding', 'The name is not valid UTF-8 text.'];
    }
    if (mb_strlen($name, 'UTF-8') > QS_PROJECT_NAME_MAX) {
        return [400, 'validation.invalid_length', 'too_long', 'A project name has at most ' . QS_PROJECT_NAME_MAX . ' characters.'];
    }
    if (preg_match('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', $name) === 1) {
        return [400, 'validation.invalid_format', 'control_character', 'A project name cannot hold a control character.'];
    }
    $clean = $name;
    return null;
}

/** The answer for a name qs_project_name_problem() refuses. */
function qs_project_name_refusal(array $problem, string $field = 'name'): ApiResponse
{
    [$status, $code, $reason, $message] = $problem;
    return ApiResponse::create($status, $code)
        ->withMessage($message)
        ->withErrors([['field' => $field, 'reason' => $reason, 'max_length' => QS_PROJECT_NAME_MAX]]);
}

/** The bytes a name file holds. */
function qs_project_name_file_bytes(string $name): ?string
{
    return qs_json_file_bytes(['name' => $name], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, "\n");
}

/** Write a project's name file. $name has passed qs_project_name_problem(). */
function qs_project_name_write(string $projectPath, string $name): bool
{
    $dir = $projectPath . '/config';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    return qs_json_write($projectPath . '/' . QS_PROJECT_NAME_FILE, ['name' => $name],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, LOCK_EX, "\n");
}

/**
 * The name in a project folder's name file, or null when there is none it could hold (no file, not
 * JSON, or a name the rule refuses — a hand-edited file is read, never trusted).
 */
function qs_project_name_in(string $projectPath): ?string
{
    $file = $projectPath . '/' . QS_PROJECT_NAME_FILE;
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || qs_project_name_problem($data['name'] ?? null, $clean) !== null || $clean !== $data['name']) {
        return null;
    }
    return $clean;
}

/** A project's name, or null when it has none. */
function qs_project_name(string $id): ?string
{
    if ($id === '' || strpbrk($id, "/\\") !== false || strpos($id, '..') !== false) {
        return null;
    }
    return qs_project_name_in(SECURE_FOLDER_PATH . '/projects/' . $id);
}

/** The name a project is shown by: its name, else its id. */
function qs_project_display_name(string $id): string
{
    return qs_project_name($id) ?? $id;
}

/** "Name #id", or the id alone when the project has no name of its own. */
function qs_project_label(string $id): string
{
    $name = qs_project_name($id);
    return ($name === null || $name === $id) ? $id : $name . ' #' . $id;
}
