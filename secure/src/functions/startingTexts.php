<?php
/**
 * startingTexts.php — the texts QuickSite writes into a site where a key it names has none.
 *
 * A complex element can name a translation key whose text the author has not written yet (the
 * OAuth button's label, a form field's label); left alone, the page shows the missing-translation
 * marker. Its builder answers a starting text for each such key (ComplexElementBuilder::
 * startingTexts()) and addComplexElement writes it into every translation file the project's
 * pages read, only where the key has no text there. A text is never replaced, and a starting text
 * is edited like any translation afterwards.
 *
 * A starting text is either a WORD — read by key from <secure>/src/translations/<lang>.json, one
 * file per language, its {{name}} markers filled — or a TEXT the builder gives as it is, the same
 * in every language (a form field's name). No code holds a word: a language with a file gets its
 * own, one without takes its base language's (`pt` for `pt-br`) when that has a file, and any
 * other gets English. A new language is a new file. The commands that generate a whole page or the
 * consent layer write trees of these words (qs_starting_words_seed()).
 *
 * The same files hold the words QuickSite itself shows a site's visitor (the `visitor` tree: a
 * call's toasts, the theme switch's labels, a build's 404 and fatal pages), read per page by the
 * runtime handoff (qs_visitor_words(), runtimeHandoff.php), the words Fresh Start writes into a
 * site (`freshStart`, through a workflow step's {{startingWord:<key>}}, WorkflowManager) and a new
 * component's starter text (`component.starter`, the visual editor).
 *
 * Authoring only: this file never travels into a build. A build carries the visitor words it
 * answers, resolved for each of its site's languages (qs-site.php).
 */

if (!defined('SECURE_FOLDER_PATH')) {
    die('Direct access not allowed');
}

require_once SECURE_FOLDER_PATH . '/src/functions/jsonIo.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_guard
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/** How a translation file is written (writeTranslationsToFile() writes the same bytes). */
const QS_STARTING_TEXTS_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/**
 * The words <secure>/src/translations/<lang>.json holds; [] when there is no such file. A code
 * names a file only once it passes the language rule (RegexPatterns `language_code`), the one a
 * project's languages follow.
 */
function qs_starting_words_file(string $lang): array
{
    static $cache = [];
    if (array_key_exists($lang, $cache)) {
        return $cache[$lang];
    }
    if (!RegexPatterns::match('language_code', $lang)) {
        return $cache[$lang] = [];
    }
    $path = SECURE_FOLDER_PATH . '/src/translations/' . $lang . '.json';
    $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    return $cache[$lang] = is_array($data) ? $data : [];
}

/**
 * The word $key in $lang — its own file's, else its base language's, else English's — with its
 * {{name}} markers filled from $params (a marker with no value stays as written). null when no
 * file holds it.
 */
function qs_starting_word(string $lang, string $key, array $params = []): ?string
{
    $lang = strtolower($lang);
    $tries = [$lang];
    if (($dash = strpos($lang, '-')) !== false) {
        $tries[] = substr($lang, 0, $dash);
    }
    $tries[] = 'en';
    foreach (array_unique($tries) as $try) {
        $node = qs_starting_words_file($try);
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                $node = null;
                break;
            }
            $node = $node[$part];
        }
        if (is_string($node) && $node !== '') {
            return preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $m) use ($params): string {
                return (array_key_exists($m[1], $params) && is_scalar($params[$m[1]])) ? (string) $params[$m[1]] : $m[0];
            }, $node);
        }
    }
    return null;
}

/**
 * The keys the English word file holds under $tree (a dot path: `consent.banner`), each a whole dot
 * path, in the file's order: the list of a tree's words, which no code holds. [] when the English
 * file holds no such tree.
 *
 * @return list<string>
 */
function qs_starting_words_keys(string $tree): array
{
    $node = qs_starting_words_file('en');
    foreach (explode('.', $tree) as $part) {
        $node = is_array($node) ? ($node[$part] ?? null) : null;
    }
    if (!is_array($node)) {
        return [];
    }
    $keys = [];
    $walk = static function (array $node, string $path) use (&$walk, &$keys): void {
        foreach ($node as $name => $value) {
            if (is_array($value)) {
                $walk($value, $path . '.' . $name);
            } else {
                $keys[] = $path . '.' . $name;
            }
        }
    };
    $walk($node, $tree);
    return $keys;
}

/**
 * The words of $tree in $lang, as a tree shaped like the English file's (qs_starting_words_keys()):
 * each read by qs_starting_word(), so a language takes its own file's word, else its base
 * language's, else English's. Its {{name}} markers are left as written, for whoever shows the word
 * to fill.
 */
function qs_starting_words_tree(string $lang, string $tree): array
{
    $out = [];
    foreach (qs_starting_words_keys($tree) as $key) {
        $word = qs_starting_word($lang, $key);
        if ($word !== null) {
            qs_starting_text_set($out, substr($key, strlen($tree) + 1), $word);
        }
    }
    return $out;
}

/**
 * The word $key in each of $langs and in English, keyed by language: what a page hands a script
 * that writes the word in a language it chooses. English is the word of any language the list does
 * not name (qs_starting_word()'s last file): one the site gains after the page was written.
 *
 * @param list<string> $langs
 * @return array<string, string>
 */
function qs_starting_word_by_language(string $key, array $langs): array
{
    $out = [];
    foreach (array_merge($langs, ['en']) as $lang) {
        if (is_string($lang) && $lang !== '' && !isset($out[$lang])) {
            $word = qs_starting_word($lang, $key);
            if ($word !== null) {
                $out[$lang] = $word;
            }
        }
    }
    return $out;
}

/**
 * Where $dotKey stands in a translation tree: 'none' when it has no text (absent, null or an
 * empty string, which a page shows as the missing marker too), 'text' when it has one, 'taken'
 * when it cannot take a text without losing another (its place holds other keys, a parent is a
 * text, or a segment is empty).
 */
function qs_starting_text_slot(array $tree, string $dotKey): string
{
    $node = $tree;
    foreach (explode('.', $dotKey) as $part) {
        if ($part === '' || !is_array($node)) {
            return 'taken';
        }
        if (!array_key_exists($part, $node) || $node[$part] === null) {
            return 'none';
        }
        $node = $node[$part];
    }
    if (is_array($node)) {
        return 'taken';
    }
    return $node === '' ? 'none' : 'text';
}

/** Set $dotKey to $text in $tree, making the parents it needs (after qs_starting_text_slot() said 'none'). */
function qs_starting_text_set(array &$tree, string $dotKey, string $text): void
{
    $parts = explode('.', $dotKey);
    $leaf = array_pop($parts);
    $ref = &$tree;
    foreach ($parts as $part) {
        if (!isset($ref[$part]) || !is_array($ref[$part])) {
            $ref[$part] = [];
        }
        $ref = &$ref[$part];
    }
    $ref[$leaf] = $text;
    unset($ref);
}

/**
 * The translation files the project's pages read, each with the language its words are taken in:
 * on a multilingual project each listed language's file that exists, then default.json (what a
 * listed language with no file of its own reads), in the default language; on a monolingual one
 * default.json alone, in the default language. A file that does not exist is never created: a
 * language file that appeared would stop its language reading default.json. A code names a file
 * only once it passes the language rule (RegexPatterns `language_code`) every writer of the list
 * applies: a list edited by hand can hold anything, and a code shaped like a path would name a
 * file outside translate/.
 *
 * @return array<string, array{file: string, lang: string}> keyed by the file's name ('default' for default.json)
 */
function qs_starting_text_targets(): array
{
    $dir = PROJECT_PATH . '/translate';
    $default = qs_project_default_language();
    $out = [];
    if (qs_project_is_multilingual()) {
        foreach (qs_project_language_codes() as $code) {
            if (RegexPatterns::match('language_code', $code) && is_file("$dir/$code.json")) {
                $out[$code] = ['file' => "$dir/$code.json", 'lang' => $code];
            }
        }
    }
    if (is_file("$dir/default.json")) {
        $out['default'] = ['file' => "$dir/default.json", 'lang' => $default];
    }
    return $out;
}

/**
 * What writing $entries' starting texts would change, file by file: for each target file
 * (qs_starting_text_targets()), the keys with no text there and their text in that file's
 * language, the file's new content and its size. A file nothing changes in is left out, and so is
 * a file that does not hold a translation tree.
 *
 * An entry is ['key' => <translation key>, 'word' => <key in the word files>, 'params' => [...]]
 * or ['key' => <translation key>, 'text' => <the text, in every language>]. A key named twice
 * takes its first entry; an entry with no usable key or text is skipped.
 *
 * @return array<string, array{file: string, lang: string, data: array, texts: array<string,string>, bytes: int}>
 */
function qs_starting_texts_plan(array $entries): array
{
    $plan = [];
    foreach (qs_starting_text_targets() as $name => $target) {
        $data = json_decode((string) @file_get_contents($target['file']), true);
        if (!is_array($data)) {
            continue;
        }
        $texts = [];
        foreach ($entries as $entry) {
            $key = is_array($entry) && is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            if (!isValidTranslationKey($key) || isset($texts[$key]) || qs_starting_text_slot($data, $key) !== 'none') {
                continue;
            }
            if (is_string($entry['word'] ?? null)) {
                $text = qs_starting_word($target['lang'], $entry['word'], is_array($entry['params'] ?? null) ? $entry['params'] : []);
            } else {
                $text = is_string($entry['text'] ?? null) ? trim($entry['text']) : null;
            }
            if ($text === null || $text === '') {
                continue;
            }
            qs_starting_text_set($data, $key, $text);
            $texts[$key] = $text;
        }
        if ($texts !== []) {
            $plan[$name] = $target + ['data' => $data, 'texts' => $texts, 'bytes' => qs_json_size($data, QS_STARTING_TEXTS_JSON_FLAGS)];
        }
    }
    return $plan;
}

/**
 * Write a plan (qs_starting_texts_plan()) after the command's size check passed for every file in
 * it. Answers the keys written per file and the files that failed to write.
 *
 * @return array{written: array<string, string[]>, failed: string[]}
 */
function qs_starting_texts_write(array $plan): array
{
    $written = [];
    $failed = [];
    foreach ($plan as $name => $item) {
        if (qs_json_write($item['file'], $item['data'], QS_STARTING_TEXTS_JSON_FLAGS, LOCK_EX)) {
            $written[$name] = array_keys($item['texts']);
        } else {
            $failed[] = $name;
        }
    }
    return ['written' => $written, 'failed' => $failed];
}

/**
 * Write whole trees of the word files, in every language the site's pages read, only where a key
 * has no text. A key and its word are the same dot path, and the keys are the ones the English file
 * holds under each of $trees, so no code holds a word or a list of them. Every file passes the size
 * check first (qs_small_write_guard()), and a refusal is answered here. default.json counts under the
 * default language when no file of that language did, as it is what a monolingual site reads.
 *
 * $others are single keys whose word is read under another name, written by the same rule: a generated
 * page's title (`page.titles.<route>`) takes its policy's own title word (`consent.policy.title`). Each
 * key written counts in languagesSeeded, a title included.
 *
 * @param list<string> $trees dot paths in the word files: consent.banner, privacy.policy
 * @param list<array{key: string, word: string}> $others a site key and the word-file key of its word
 * @return array{languagesSeeded: array<string, int>, languagesFailed: string[]}
 */
function qs_starting_words_seed(array $trees, array $others = []): array
{
    $entries = $others;
    foreach ($trees as $tree) {
        foreach (qs_starting_words_keys($tree) as $key) {
            $entries[] = ['key' => $key, 'word' => $key];
        }
    }
    $plan = qs_starting_texts_plan($entries);
    foreach ($plan as $item) {
        qs_small_write_guard($item['file'], $item['bytes']);
    }
    qs_small_write_send_refusal();
    $written = qs_starting_texts_write($plan);
    $seeded = [];
    foreach ($written['written'] as $name => $keys) {
        if ($name !== 'default') {
            $seeded[$name] = count($keys);
        }
    }
    $defaultLang = qs_project_default_language();
    if (isset($written['written']['default']) && !isset($seeded[$defaultLang])) {
        $seeded[$defaultLang] = count($written['written']['default']);
    }
    return ['languagesSeeded' => $seeded, 'languagesFailed' => $written['failed']];
}
