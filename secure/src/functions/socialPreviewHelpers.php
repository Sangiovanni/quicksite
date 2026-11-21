<?php
/**
 * socialPreviewHelpers.php — the AUTHORING half of a project's social values: what the two commands, the
 * import and deleteRoute need, none of which a served page needs, so a build never carries it.
 *
 *   - the writer of `data/social-preview.json` (through the small-write cap and the quota);
 *   - the checks that need Media (a picture is one of its images, never SVG);
 *   - a page's description, written and removed through the shared translation writer;
 *   - the import's check of an archive's file, and deleteRoute's removal of a page's values;
 *   - the platform table and the warnings getSocialPreview answers.
 *
 * The runtime half — the values' shapes, the reader, the tags — is socialPreview.php.
 */

require_once __DIR__ . '/socialPreview.php';
require_once __DIR__ . '/jsonIo.php';   // qs_json_write, qs_json_size
require_once __DIR__ . '/quota.php';    // qs_small_write_guard, qs_small_write_refused

/** The longest description setSocialPreview writes, in characters: past it a description is refused, below it each platform's cut is a warning. */
if (!defined('QS_SOCIAL_DESCRIPTION_MAX')) {
    define('QS_SOCIAL_DESCRIPTION_MAX', 500);
}

/** The keys an archive's social file may hold at its top. */
if (!defined('QS_SOCIAL_FILE_KEYS')) {
    define('QS_SOCIAL_FILE_KEYS', ['site', 'pages']);
}

/**
 * THE PLATFORM TABLE — every figure read against the platform's own documentation on the date `checked` says. A
 * figure is `value` (or `width` and `height`) with `published` (true: the platform states it; false: it does not,
 * and the value is null, so nothing is judged by it) and `source` (where it was read, or looked for). A `height`
 * of null means the platform states a width alone. Sizes in pixels, files in bytes (a platform's "5 MB" read as
 * 5,000,000, the stricter reading), texts in `unit` (characters, or bytes where the platform counts bytes).
 * `picture.thumbnail` names the figures of `picture` (`min`, `maxBytes`, `shape`) a picture may miss and still be
 * drawn, as a small picture beside the text; a picture that misses any other is not drawn.
 * `lines` is how many lines of the title and of the description the card shows on a `computer` and on a `phone`
 * (0: not shown on that device); the platform's length is cut first, then its lines.
 * X's card documentation left its live site in 2026: its figures are read from the last copies of X's own pages
 * (the Internet Archive's, dated in the source address), which nothing has replaced.
 */
if (!defined('QS_SOCIAL_PLATFORMS')) {
    define('QS_SOCIAL_PLATFORMS', (static function (): array {
        $none = static fn(string $source): array => ['value' => null, 'published' => false, 'source' => $source];
        // A count of lines per device, or none published.
        $lines = static fn(?int $computer, ?int $phone, bool $published, string $source): array
            => ['computer' => $computer, 'phone' => $phone, 'published' => $published, 'source' => $source];
        $noLines = static fn(string $source): array => [
            'title' => $lines(null, null, false, $source), 'description' => $lines(null, null, false, $source)];
        $fb = 'https://developers.facebook.com/documentation/sharing/webmasters/images';
        $fbTags = 'https://developers.facebook.com/docs/sharing/webmasters/';
        $xLarge = 'https://web.archive.org/web/20260204074639/https://developer.x.com/en/docs/x-for-websites/cards/overview/summary-card-with-large-image';
        $xSmall = 'https://web.archive.org/web/20260128060618/https://developer.x.com/en/docs/x-for-websites/cards/overview/summary';
        $xMarkup = 'https://web.archive.org/web/20260116124848/https://developer.x.com/en/docs/x-for-websites/cards/overview/markup';
        $xFormats = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $li = 'https://www.linkedin.com/help/linkedin/answer/a521928';
        $wa = 'https://developers.facebook.com/documentation/business-messaging/whatsapp/link-previews/';
        $dc = 'https://docs.discord.com/developers/link-previews/overview';
        $sl = 'https://api.slack.com/robots';
        $tg = 'https://core.telegram.org/constructor/webPage';
        $ap = 'https://developer.apple.com/documentation/technotes/tn3156-create-rich-previews-for-messages';
        $ig = 'https://about.instagram.com/blog/announcements/expanding-sharing-links-in-stories-to-everyone';
        $x = static function (string $card, string $source, int $minW, int $minH, float $shape) use ($xFormats, $xMarkup, $none, $lines): array {
            return [
                'name'    => $card === 'large' ? 'X (large card)' : 'X (small card)',
                'card'    => $card,
                'checked' => '2026-11-14',
                'checker' => null,
                'reads'   => 'twitter:card, then twitter:title, twitter:description, twitter:image and twitter:image:alt, each falling back to its Open Graph tag; twitter:site.',
                'picture' => [
                    'min'         => ['width' => $minW, 'height' => $minH, 'published' => true, 'source' => $source],
                    'recommended' => ['width' => null, 'height' => null, 'published' => false, 'source' => $source],
                    'max'         => ['width' => 4096, 'height' => 4096, 'published' => true, 'source' => $source],
                    'shape'       => ['value' => $shape, 'published' => true, 'source' => $source],
                    'maxBytes'    => ['value' => 5000000, 'published' => true, 'source' => $source],
                    'formats'     => ['value' => $xFormats, 'published' => true, 'source' => $source],
                    'thumbnail'   => $none($source),
                ],
                'title'       => ['value' => 70, 'unit' => 'characters', 'published' => true, 'source' => $xMarkup],
                'description' => ['value' => 200, 'unit' => 'characters', 'published' => true, 'source' => $xMarkup],
                'lines'       => ['title' => $lines(1, 2, true, $source), 'description' => $lines(3, 0, true, $source)],
                'notes'   => 'The title shows on 1 line on a computer and 2 on a phone; the description shows on 3 lines on a computer and not at all on a phone (X\'s last published rule). An animated GIF shows its first frame. X has no checker since 2022: it reads a card again about every 7 days, sooner for a new address.',
            ];
        };
        return [
            'facebook' => [
                'name'    => 'Facebook',
                'checked' => '2026-11-14',
                'checker' => ['url' => 'https://developers.facebook.com/tools/debug/', 'refreshes' => true],
                'reads'   => 'og:url, og:title, og:description, og:image (with og:image:width and og:image:height), og:type, og:locale.',
                'picture' => [
                    'min'         => ['width' => 200, 'height' => 200, 'published' => true, 'source' => $fb],
                    'recommended' => ['width' => 1200, 'height' => 630, 'published' => true, 'source' => $fb],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $fb],
                    'shape'       => ['value' => 1.91, 'published' => true, 'source' => $fb],
                    'maxBytes'    => ['value' => 8000000, 'published' => true, 'source' => $fb],
                    'formats'     => $none($fb),
                    'thumbnail'   => ['value' => [], 'published' => true, 'source' => $fb],
                ],
                'title'       => $none($fbTags) + ['unit' => 'characters'],
                'description' => $none($fbTags) + ['unit' => 'characters'],
                'lines'       => $noLines($fbTags),
                'notes'   => 'Under 600 × 315 the picture is drawn much smaller. A picture is cached by its address: a new picture needs a new address, and a share already made keeps its card.',
            ],
            'x-large' => $x('large', $xLarge, 300, 157, 2.0),
            'x-small' => $x('small', $xSmall, 144, 144, 1.0),
            'linkedin' => [
                'name'    => 'LinkedIn',
                'checked' => '2026-11-14',
                'checker' => ['url' => 'https://www.linkedin.com/post-inspector/', 'refreshes' => true],
                'reads'   => 'og:title, og:description, og:image, og:url.',
                'picture' => [
                    'min'         => ['width' => 401, 'height' => null, 'published' => true, 'source' => $li],
                    'recommended' => ['width' => 1200, 'height' => 627, 'published' => true, 'source' => $li],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $li],
                    'shape'       => ['value' => 1.91, 'published' => true, 'source' => $li],
                    'maxBytes'    => ['value' => 5000000, 'published' => true, 'source' => $li],
                    'formats'     => $none($li),
                    'thumbnail'   => ['value' => ['min'], 'published' => true, 'source' => $li],
                ],
                'title'       => $none($li) + ['unit' => 'characters'],
                'description' => $none($li) + ['unit' => 'characters'],
                'lines'       => $noLines($li),
                'notes'   => 'LinkedIn names 1200 × 627 its minimum; a picture under 401 pixels wide shows as a thumbnail (a note its help page places after the advertising figures). A refreshed card shows on new posts only.',
            ],
            'whatsapp' => [
                'name'    => 'WhatsApp',
                'checked' => '2026-11-14',
                'checker' => null,
                'reads'   => 'og:title, og:description, og:url and og:image (a full address), in a head within the first 300 KB of the page.',
                'picture' => [
                    'min'         => ['width' => 300, 'height' => null, 'published' => true, 'source' => $wa],
                    'recommended' => ['width' => null, 'height' => null, 'published' => false, 'source' => $wa],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $wa],
                    'shape'       => ['value' => null, 'maxRatio' => 4.0, 'published' => true, 'source' => $wa],
                    'maxBytes'    => ['value' => 600000, 'published' => true, 'source' => $wa],
                    'formats'     => $none($wa),
                    'thumbnail'   => ['value' => ['min', 'maxBytes', 'shape'], 'published' => true, 'source' => $wa],
                ],
                'title'       => $none($wa) + ['unit' => 'characters'],
                'description' => ['value' => 80, 'unit' => 'characters', 'published' => true, 'source' => $wa],
                'lines'       => ['title' => $lines(2, 2, true, $wa), 'description' => $lines(2, 2, true, $wa)],
                'notes'   => 'A picture under 600 KB, at least 300 pixels wide and no wider than 4 times its height gets the large preview; any other, the small one. The title shows in bold on up to 2 lines, the description on 1 or 2 (about 80 characters). No checker: type the link in a message without sending it.',
            ],
            'discord' => [
                'name'    => 'Discord',
                'checked' => '2026-11-14',
                'checker' => ['url' => 'https://discord.com/developers/embeds', 'refreshes' => false],
                'reads'   => 'og:title or twitter:title, og:description or twitter:description, og:site_name, og:image or twitter:image (with their sizes and alt), twitter:card for the layout (summary_large_image: the large picture), theme-color for the colour bar.',
                'picture' => [
                    'min'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $dc],
                    'recommended' => ['width' => null, 'height' => null, 'published' => false, 'source' => $dc],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $dc],
                    'shape'       => $none($dc),
                    'maxBytes'    => $none($dc),
                    'formats'     => ['value' => ['image/png', 'image/gif', 'image/jpeg', 'image/webp', 'image/avif'], 'published' => true, 'source' => $dc],
                    'thumbnail'   => $none($dc),
                ],
                'title'       => ['value' => 70, 'unit' => 'bytes', 'published' => true, 'source' => $dc],
                'description' => ['value' => 350, 'unit' => 'bytes', 'published' => true, 'source' => $dc],
                'lines'       => ['title' => $lines(3, 3, true, $dc), 'description' => $lines(20, 20, true, $dc)],
                'notes'   => 'Lengths are in bytes: an accented letter takes two or more, so a French title is cut sooner. The title shows on up to 3 lines, the description on up to 20. A card is cached about 30 minutes; a new query string on the address shows a change at once.',
            ],
            'slack' => [
                'name'    => 'Slack',
                'checked' => '2026-11-14',
                'checker' => null,
                'reads'   => 'oEmbed, then X or Open Graph tags (whichever comes first in the page), then the description; summary_large_image gives its largest picture.',
                'picture' => [
                    'min'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $sl],
                    'recommended' => ['width' => null, 'height' => null, 'published' => false, 'source' => $sl],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $sl],
                    'shape'       => $none($sl),
                    'maxBytes'    => $none($sl),
                    'formats'     => $none($sl),
                    'thumbnail'   => $none($sl),
                ],
                'title'       => $none($sl) + ['unit' => 'characters'],
                'description' => $none($sl) + ['unit' => 'characters'],
                'lines'       => $noLines($sl),
                'notes'   => 'Slack publishes no picture or text figure. A card is cached about 30 minutes.',
            ],
            'telegram' => [
                'name'    => 'Telegram',
                'checked' => '2026-11-14',
                'checker' => ['url' => 'https://t.me/WebpageBot', 'refreshes' => true],
                'reads'   => 'Not published: a preview holds a site name, a title, a description and a picture, and Telegram does not say which tags fill them.',
                'picture' => [
                    'min'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $tg],
                    'recommended' => ['width' => null, 'height' => null, 'published' => false, 'source' => $tg],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $tg],
                    'shape'       => $none($tg),
                    'maxBytes'    => $none($tg),
                    'formats'     => $none($tg),
                    'thumbnail'   => $none($tg),
                ],
                'title'       => $none($tg) + ['unit' => 'characters'],
                'description' => $none($tg) + ['unit' => 'characters'],
                'lines'       => $noLines($tg),
                'notes'   => 'The person sending the link chooses a large or a small picture. Telegram publishes no figure; its preview bot, which reads a link again, is not in its documentation.',
            ],
            'imessage' => [
                'name'    => 'iMessage',
                'checked' => '2026-11-14',
                'checker' => null,
                'reads'   => 'og:title, og:site_name, og:image; the description only for a social network\'s post.',
                'picture' => [
                    'min'         => ['width' => 150, 'height' => null, 'published' => true, 'source' => $ap],
                    'recommended' => ['width' => 900, 'height' => null, 'published' => true, 'source' => $ap],
                    'max'         => ['width' => null, 'height' => null, 'published' => false, 'source' => $ap],
                    'shape'       => $none($ap),
                    'maxBytes'    => ['value' => 10000000, 'published' => true, 'source' => $ap],
                    'formats'     => $none($ap),
                    'thumbnail'   => $none($ap),
                ],
                'title'       => $none($ap) + ['unit' => 'characters'],
                'description' => $none($ap) + ['unit' => 'characters'],
                'lines'       => $noLines($ap),
                'notes'   => 'A picture under 150 pixels wide may be left out or drawn as an icon. The 10 MB is every picture, icon and video of the page together; the page itself at most 1 MB. No checker: send the link in Messages.',
            ],
            'instagram' => [
                'name'      => 'Instagram',
                'drawsCard' => false,
                'checked'   => '2026-11-14',
                'checker'   => null,
                'reads'     => 'Nothing: a link in a post is plain text; links go in a profile\'s links or a story\'s link sticker.',
                'source'    => $ig,
            ],
        ];
    })());
}

/**
 * The platform table: how each platform draws a shared link's card, the one copy of these figures. getSocialPreview
 * answers it whole (the panel's cards and warnings read it there, never from a copy), and qs_social_warnings()
 * judges a page by it. Each figure carries its source and whether the platform publishes it; a figure no source
 * gives is null, and nothing is judged by it. `checked` is the date the row was last read against the platform.
 *
 * @return array<string, array>
 */
function qs_social_platforms(): array
{
    return QS_SOCIAL_PLATFORMS;
}

/** Absolute path of a project's social values. */
function qs_social_file_path(string $projectPath): string
{
    return $projectPath . '/' . QS_SOCIAL_FILE;
}

/**
 * Write a project's social values: `site` and `pages`, a page with no value left out. Through the size check
 * (qs_small_write_guard): false when refused (the command answers qs_small_write_refused()) or when the write
 * failed. Every level is written as a map, so a route named 0 stays a key.
 */
function qs_social_save(string $projectPath, array $values): bool
{
    $pages = [];
    foreach ($values['pages'] ?? [] as $route => $page) {
        if (is_array($page) && $page !== []) {
            $pages[(string) $route] = $page;
        }
    }
    ksort($pages, SORT_STRING);
    $data = ['site' => (array) ($values['site'] ?? []), 'pages' => $pages];
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT;
    $path = qs_social_file_path($projectPath);
    if (!qs_small_write_guard($path, qs_json_size($data, $flags))) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    return qs_json_write($path, $data, $flags);
}

/**
 * Why a picture cannot be a project's social picture, or null when it can: its shape (qs_social_value_problem()),
 * then Media: an entry in its metadata, the file itself, and an image type that is not SVG.
 */
function qs_social_picture_problem(string $projectPath, $key): ?string
{
    $shape = qs_social_value_problem('picture', $key);
    if ($shape !== null) {
        return $shape;
    }
    $entry = qs_social_picture($projectPath, $key);
    if ($entry === null) {
        return "'" . $key . "' is not in Media (upload it first, or name it as Media's list does)";
    }
    $mime = is_string($entry['mime_type'] ?? null) ? strtolower($entry['mime_type']) : '';
    if (strpos($mime, 'image/') !== 0) {
        return "'" . $key . "' is not a picture";
    }
    if (strpos($mime, 'image/svg') === 0) {
        return 'a picture is never SVG: no platform draws one in a card';
    }
    return null;
}

/**
 * Why a route cannot hold social values, or null when it can: it is one of the project's routes (the page-name
 * rule, qs_page_name_refusal()), not a special page, which carries no social tag.
 *
 * @return ApiResponse|null the refusal to send
 */
function qs_social_route_refusal($route, string $field = 'route'): ?ApiResponse
{
    $refusal = qs_page_name_refusal($route, $field);
    if ($refusal !== null) {
        return $refusal;
    }
    if (in_array(trim((string) $route, '/'), SPECIAL_PAGES, true)) {
        return ApiResponse::create(404, 'route.not_found')
            ->withMessage("'{$route}' is a special page: it carries no social tags")
            ->withErrors([['field' => $field, 'value' => (string) $route, 'reason' => 'special_page']]);
    }
    return null;
}

/**
 * Whether a page key of the social file is shaped as a route the writer could have named: the page-name rule's
 * segments (letters, digits, hyphens and underscores, or ':' and a parameter name), at most 200 bytes, not a
 * special page. The import's check: it cannot ask the project's routes, which the archive brings with it.
 */
function qs_social_route_shape_ok($route): bool
{
    if (!is_string($route) || $route === '' || strlen($route) > 200 || in_array($route, SPECIAL_PAGES, true)) {
        return false;
    }
    require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
    foreach (explode('/', $route) as $segment) {
        if (!RegexPatterns::match('identifier_alphanum', $segment)
            && preg_match('/^:[a-zA-Z_][a-zA-Z0-9_]*$/D', $segment) !== 1) {
            return false;
        }
    }
    return true;
}

/**
 * The first reason an archive's social file is not one setSocialPreview could have written, or null. The
 * import refuses the whole archive for it. A page or a picture the archive no longer holds is accepted: the
 * command could have written it before that page or that picture went, and it writes no tag.
 *
 * @param mixed $data the file, decoded
 * @return array{key: string, message: string}|null
 */
function qs_social_import_problem($data): ?array
{
    if (!is_array($data)) {
        return ['key' => '', 'message' => 'The social values are a JSON object holding site and pages.'];
    }
    foreach (array_keys($data) as $key) {
        if (!in_array((string) $key, QS_SOCIAL_FILE_KEYS, true)) {
            return ['key' => (string) $key, 'message' => "'{$key}' is not a part of the social values (site, pages)."];
        }
    }
    $site = $data['site'] ?? [];
    if (!is_array($site)) {
        return ['key' => 'site', 'message' => 'site is an object of the site\'s values.'];
    }
    foreach ($site as $field => $value) {
        if (!in_array((string) $field, QS_SOCIAL_SITE_FIELDS, true)) {
            return ['key' => 'site.' . $field, 'message' => "'{$field}' is not a value of the site (" . implode(', ', QS_SOCIAL_SITE_FIELDS) . ').'];
        }
        $problem = qs_social_value_problem((string) $field, $value);
        if ($problem !== null) {
            return ['key' => 'site.' . $field, 'message' => ucfirst($problem) . '.'];
        }
    }
    $pages = $data['pages'] ?? [];
    if (!is_array($pages)) {
        return ['key' => 'pages', 'message' => 'pages is an object of each page\'s values, by route.'];
    }
    foreach ($pages as $route => $values) {
        if (!qs_social_route_shape_ok((string) $route)) {
            return ['key' => 'pages.' . $route, 'message' => "'{$route}' is not a route a page's values can be written for."];
        }
        if (!is_array($values)) {
            return ['key' => 'pages.' . $route, 'message' => 'A page\'s values are an object.'];
        }
        foreach ($values as $field => $value) {
            if (!in_array((string) $field, QS_SOCIAL_PAGE_FIELDS, true)) {
                return ['key' => 'pages.' . $route . '.' . $field, 'message' => "'{$field}' is not a value of a page (" . implode(', ', QS_SOCIAL_PAGE_FIELDS) . ').'];
            }
            $problem = qs_social_value_problem((string) $field, $value);
            if ($problem !== null) {
                return ['key' => 'pages.' . $route . '.' . $field, 'message' => ucfirst($problem) . '.'];
            }
        }
    }
    return null;
}

/**
 * Remove the values of deleted pages from the project's social file (deleteRoute, beside the page's events,
 * layout and resolvers). Answers the routes whose values were removed; a write the size check refuses leaves
 * the file as it was.
 *
 * @param string[] $routes
 * @return string[]
 */
function qs_social_forget_routes(string $projectPath, array $routes): array
{
    $values = qs_social_values_read($projectPath);
    $removed = [];
    foreach ($routes as $route) {
        if (isset($values['pages'][(string) $route])) {
            unset($values['pages'][(string) $route]);
            $removed[] = (string) $route;
        }
    }
    if ($removed !== [] && !qs_social_save($projectPath, $values)) {
        return [];
    }
    return $removed;
}

/**
 * Remove a page's description from one translation file: `page.descriptions.<route>`, and the `descriptions`
 * level when it is left empty.
 *
 * @return string 'removed', 'absent', 'refused' (the size check; qs_small_write_refused() holds the answer) or 'failed'
 */
function qs_social_description_unset(string $file, string $route): string
{
    if (!is_file($file)) {
        return 'absent';
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['page']['descriptions'] ?? null)
        || !array_key_exists($route, $data['page']['descriptions'])) {
        return 'absent';
    }
    unset($data['page']['descriptions'][$route]);
    if ($data['page']['descriptions'] === []) {
        unset($data['page']['descriptions']);
    }
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (!qs_small_write_guard($file, qs_json_size($data, $flags))) {
        return 'refused';
    }
    return qs_json_write($file, $data, $flags, LOCK_EX) ? 'removed' : 'failed';
}

/**
 * Write or remove a page's description in one language, as editTitle writes a title: through the shared
 * translation writer, which copies into default.json a write on a one-language site and a write in the
 * default language on a multilingual one. An empty text removes it the same way: from the language's file,
 * and from default.json on a one-language site or when it is the default language's; removing another
 * language's leaves default.json as it is.
 *
 * @return array{ok: bool, reason?: string, response?: ?ApiResponse, collisions?: array, removed?: bool}
 */
function qs_social_write_description(string $projectPath, string $route, string $lang, string $text): array
{
    require_once __DIR__ . '/translationHelpers.php';
    require_once __DIR__ . '/projectLanguage.php';   // qs_project_is_multilingual, qs_project_default_language
    if ($text !== '') {
        $result = writeTranslationsToFile($lang, ['page' => ['descriptions' => [$route => $text]]]);
        if ($result['ok']) {
            return ['ok' => true, 'removed' => false];
        }
        return ['ok' => false, 'reason' => $result['reason'], 'response' => $result['response'] ?? null,
                'collisions' => $result['collisions'] ?? []];
    }
    $outcome = qs_social_description_unset($projectPath . '/translate/' . $lang . '.json', $route);
    if ($outcome === 'removed' || $outcome === 'absent') {
        if (!qs_project_is_multilingual() || $lang === qs_project_default_language()) {
            $fromDefault = qs_social_description_unset($projectPath . '/translate/default.json', $route);
            if ($fromDefault !== 'absent') {
                $outcome = $fromDefault;
            }
        }
    }
    if ($outcome === 'refused') {
        return ['ok' => false, 'reason' => 'refused', 'response' => qs_small_write_refused()];
    }
    if ($outcome === 'failed') {
        return ['ok' => false, 'reason' => 'file_write_failed'];
    }
    return ['ok' => true, 'removed' => $outcome === 'removed'];
}

/**
 * A key's text in one language's file, as a page of the project reads it: `<lang>.json` on a multilingual site,
 * `default.json` on a one-language site; null when it holds no text.
 */
function qs_social_text(string $projectPath, string $lang, string $dotKey): ?string
{
    static $files = [];
    $name = qs_project_is_multilingual() ? $lang : 'default';
    $file = $projectPath . '/translate/' . $name . '.json';
    if (!array_key_exists($file, $files)) {
        $decoded = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($decoded) && $name !== 'default' && is_file($projectPath . '/translate/default.json')) {
            $decoded = json_decode((string) @file_get_contents($projectPath . '/translate/default.json'), true);
        }
        $files[$file] = is_array($decoded) ? $decoded : [];
    }
    $current = $files[$file];
    foreach (explode('.', $dotKey) as $segment) {
        if (!is_array($current) || !isset($current[$segment])) {
            return null;
        }
        $current = $current[$segment];
    }
    return is_scalar($current) && (string) $current !== '' ? (string) $current : null;
}

/**
 * A route's address below the site's base, as a page of it is linked: its language first on a multilingual
 * site, the home page at the base (or its language's), each segment encoded and a parameter's `:name` kept.
 */
function qs_social_route_site_path(string $route, string $lang, bool $multilingual): string
{
    $prefix = $multilingual ? rawurlencode($lang) . '/' : '';
    if ($route === 'home') {
        return $prefix;
    }
    $segments = array_map(static fn($s) => ($s !== '' && $s[0] === ':') ? $s : rawurlencode($s), explode('/', $route));
    return $prefix . implode('/', $segments);
}

/**
 * The warnings of one page in one language, per platform, from the platform table: a picture smaller than a
 * platform's minimum (it shows none, or a small one) or than its recommended size, a file over its limit or in a
 * format it does not show, a title or a description longer than it shows. Never a refusal. X is judged by the
 * card the page asks for. A figure the table does not know is not judged.
 *
 * @return list<array{platform: string, code: string, limit: mixed, actual: mixed, message: string}>
 */
function qs_social_warnings(?array $picture, string $title, ?string $description, string $cardType): array
{
    $out = [];
    foreach (qs_social_platforms() as $id => $p) {
        if ((isset($p['card']) && $p['card'] !== $cardType) || ($p['drawsCard'] ?? true) === false) {
            continue;
        }
        $warn = static function (string $code, $limit, $actual, string $message) use (&$out, $id): void {
            $out[] = ['platform' => $id, 'code' => $code, 'limit' => $limit, 'actual' => $actual, 'message' => $message];
        };
        if ($picture !== null) {
            $w = is_int($picture['width'] ?? null) ? $picture['width'] : null;
            $h = is_int($picture['height'] ?? null) ? $picture['height'] : null;
            // A figure with a width alone (height null) judges the width alone.
            $under = static function (?array $figure) use ($w, $h): bool {
                return $w !== null && $h !== null && is_int($figure['width'] ?? null)
                    && ($w < $figure['width'] || (is_int($figure['height'] ?? null) && $h < $figure['height']));
            };
            $size = static fn(array $f): string => is_int($f['height'] ?? null) ? "{$f['width']} × {$f['height']}" : "{$f['width']} pixels wide";
            $min = $p['picture']['min'] ?? null;
            $rec = $p['picture']['recommended'] ?? null;
            if ($under($min)) {
                $warn('picture.too_small', [$min['width'], $min['height']], [$w, $h],
                    "{$p['name']} shows no picture, or a small one, under " . $size($min));
            } elseif ($under($rec)) {
                $warn('picture.small', [$rec['width'], $rec['height']], [$w, $h],
                    "{$p['name']} recommends " . $size($rec) . ' or more');
            }
            $bytes = is_int($picture['size'] ?? null) ? $picture['size'] : null;
            $max = $p['picture']['maxBytes']['value'] ?? null;
            if ($bytes !== null && is_int($max) && $bytes > $max) {
                // A platform whose small-picture rule names the file size (picture.thumbnail: WhatsApp) shows such a
                // file small, beside the text; the others show none.
                $small = in_array('maxBytes', (array) ($p['picture']['thumbnail']['value'] ?? []), true);
                $warn('picture.too_large', $max, $bytes, $small
                    ? "{$p['name']} shows a picture file over " . qs_format_size($max) . ' small, beside the text'
                    : "{$p['name']} does not show a picture file over " . qs_format_size($max));
            }
            $mime = is_string($picture['mime_type'] ?? null) ? strtolower($picture['mime_type']) : null;
            $formats = $p['picture']['formats']['value'] ?? null;
            if ($mime !== null && is_array($formats) && !in_array($mime, $formats, true)) {
                $warn('picture.format', $formats, $mime, "{$p['name']} shows " . implode(', ', $formats) . ', not ' . $mime);
            }
        }
        // A length in the platform's unit: characters, or bytes where it counts bytes (Discord).
        $length = static fn(string $text, string $unit): int => $unit === 'bytes' ? strlen($text) : mb_strlen($text, 'UTF-8');
        foreach (['title' => $title, 'description' => (string) $description] as $what => $text) {
            $max = $p[$what]['value'] ?? null;
            $unit = (string) ($p[$what]['unit'] ?? 'characters');
            $actual = $length($text, $unit);
            if (is_int($max) && $actual > $max) {
                $warn($what . '.cut', $max, $actual, "{$p['name']} shows about {$max} {$unit} of a {$what}");
            }
        }
    }
    return $out;
}

/**
 * What getSocialPreview answers: the site's values; each page's (or one page's), its picture as Media holds it;
 * per language the title, the description, the tags (qs_social_tag_list(), the producer a served page calls) and
 * the warnings; and the platform table. Addresses are composed for this request's host and the project's
 * `/p/<projectId>/` base; a parameter's placeholder stays as written.
 */
function qs_social_report(string $projectPath, ?string $onlyRoute): array
{
    require_once __DIR__ . '/renderBootstrap.php'; // qs_render_public_base
    require_once __DIR__ . '/../classes/Translator.php';
    $values = qs_social_values_read($projectPath);
    $multilingual = qs_project_is_multilingual();
    $languages = qs_project_language_codes();
    $derivedBase = qs_render_public_base();
    $routes = $onlyRoute !== null ? [$onlyRoute] : array_map('strval', flattenRoutes(ROUTES));

    $pages = [];
    foreach ($routes as $route) {
        $own = $values['pages'][$route] ?? [];
        $pictureKey = $own['picture'] ?? $values['site']['picture'] ?? null;
        $entry = $pictureKey !== null ? qs_social_picture($projectPath, $pictureKey) : null;
        $picture = null;
        if ($pictureKey !== null) {
            $picture = [
                'key'     => $pictureKey,
                'from'    => isset($own['picture']) ? 'page' : 'site',
                'inMedia' => $entry !== null,
                'path'    => 'assets/' . qs_social_site_path($pictureKey),
                'width'   => is_int($entry['width'] ?? null) ? $entry['width'] : null,
                'height'  => is_int($entry['height'] ?? null) ? $entry['height'] : null,
                'type'    => is_string($entry['mime_type'] ?? null) ? $entry['mime_type'] : null,
                'bytes'   => is_int($entry['size'] ?? null) ? $entry['size'] : null,
            ];
        }
        $cardType = $own['cardType'] ?? $values['site']['cardType'] ?? ($entry !== null ? 'large' : 'small');
        $cardFrom = isset($own['cardType']) ? 'page' : (isset($values['site']['cardType']) ? 'site' : 'derived');
        $routeObject = new class(explode('/', $route)) {
            private array $segments;
            public function __construct(array $segments) { $this->segments = $segments; }
            public function route(): array { return $this->segments; }
            public function routeParams(): array { return []; }
        };

        $byLanguage = [];
        foreach ($languages as $lang) {
            $rawTitle = qs_social_text($projectPath, $lang, 'page.titles.' . $route);
            $title = qs_page_title($rawTitle ?? (Translator::MISSING_PREFIX . 'page.titles.' . $route . '}'), $routeObject);
            // The description as stored, markers included (the panel's field shows it); the tags and their
            // warnings take what the page's tags say: its markers removed (textMarkers.php), as the site name's.
            $description = qs_social_text($projectPath, $lang, 'page.descriptions.' . $route);
            $tagDescription = $description !== null ? qs_text_markers_remove($description) : null;
            $siteName = qs_social_text($projectPath, $lang, 'site.name');
            $tags = qs_social_tag_list([
                'projectPath' => $projectPath,
                'notFound'    => false,
                'routePath'   => $route,
                'title'       => $title,
                'description' => $tagDescription,
                'siteName'    => $siteName !== null ? qs_text_markers_remove($siteName) : null,
                'lang'        => $lang,
                'languages'   => $multilingual ? $languages : [],
                'sitePath'    => qs_social_route_site_path($route, $lang, $multilingual),
                'derivedBase' => $derivedBase,
            ]);
            $byLanguage[$lang] = [
                'title'       => $title,
                'description' => $description,
                'tags'        => array_map(static fn($t) => ['attribute' => $t[0], 'name' => $t[1], 'content' => $t[2]], $tags),
                'warnings'    => qs_social_warnings($entry, $title, $tagDescription, $cardType),
            ];
        }
        $pages[$route] = [
            'values'    => (object) $own,
            'picture'   => $picture,
            'cardType'  => ['value' => $cardType, 'from' => $cardFrom],
            'languages' => $byLanguage,
        ];
    }

    return [
        'project'      => defined('PROJECT_NAME') ? PROJECT_NAME : '',
        'multilingual' => $multilingual,
        'languages'    => $languages,
        'site'         => (object) $values['site'],
        'pages'        => (object) $pages,
        'platforms'    => qs_social_platforms(),
        // Each language's territories, from the one table the shape check reads: the usual one (announced when none
        // is chosen), and every one it may be given, the usual one first. A language CLDR gives none: null and [].
        'territories'  => (object) array_combine($languages, array_map(static fn(string $lang): array => [
            'usual' => qs_social_usual_territory($lang),
            'codes' => qs_social_language_territories($lang),
        ], $languages)),
    ];
}
