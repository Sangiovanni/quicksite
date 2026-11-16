<?php
/**
 * socialPreview.php — the description, Open Graph and X tags in a page's <head>: what a platform reads to
 * draw the card a shared link gets. Its crawlers run no script, so the tags are in the HTML the server sends.
 *
 * THE RUNTIME HALF, and a build carries it. Both page shells call qs_social_head_tags() just after the
 * <title> (PageManagement::render() on `/p/<projectId>/`, Page::render() in a build), and the tags come from
 * ONE function, qs_social_tag_list(), which getSocialPreview also calls to say what a page's tags will be.
 * The authoring half — the checks that need Media, the warnings, the platform table, the writer — is
 * socialPreviewHelpers.php, which never travels.
 *
 * Where each value comes from (the page's, else the site's, else derived):
 *   - the title: the page's <title>, as the shell computed it (qs_page_title());
 *   - the description: the translation key `page.descriptions.<route>` in the page's language, read quietly
 *     (Translator::lookup()), with the title's placeholders; none, no tag;
 *   - the site's name: the translation key `site.name`; none, no tag;
 *   - the language: `<code>_<TERRITORY>`, the territory the author gave the language, else its usual one
 *     (QS_SOCIAL_LANGUAGE_TERRITORIES), and each other language of a multilingual site as an alternate; a
 *     language with neither, none;
 *   - the picture, the card type: the project's social values (`data/social-preview.json`), the picture a
 *     Media file whose size, type and description Media's metadata holds (a build: its own copy);
 *   - the X account and the theme colour: the site's values.
 * Every address is a full one, composed by qs_full_address() (requestRuntime.php). A response that is not a
 * page of the site (a 404) carries the theme colour alone.
 */

require_once __DIR__ . '/requestRuntime.php';      // qs_full_address
require_once __DIR__ . '/runtimePlaceholders.php'; // qs_apply_runtime_placeholders
require_once __DIR__ . '/projectLanguage.php';     // qs_path_without_base, qs_project_languages, qs_project_is_multilingual

/** The project's social values, relative to its folder (and to a build's secure folder). */
if (!defined('QS_SOCIAL_FILE')) {
    define('QS_SOCIAL_FILE', 'data/social-preview.json');
}

/** The card types an author picks, and what each writes in `twitter:card`. */
if (!defined('QS_SOCIAL_CARD_TYPES')) {
    define('QS_SOCIAL_CARD_TYPES', ['large' => 'summary_large_image', 'small' => 'summary']);
}

/** The values a site holds, and those a page holds. */
if (!defined('QS_SOCIAL_SITE_FIELDS')) {
    define('QS_SOCIAL_SITE_FIELDS', ['picture', 'cardType', 'xAccount', 'themeColor', 'locales']);
}
if (!defined('QS_SOCIAL_PAGE_FIELDS')) {
    define('QS_SOCIAL_PAGE_FIELDS', ['picture', 'cardType']);
}

/**
 * Each language's territories (`og:locale` is the language, `_`, a territory), its usual one first, then the others in
 * order. From Unicode CLDR 49 (https://github.com/unicode-org/cldr-json, cldr-core 49.0.0, read on 2026-11-16): a
 * language's territories are the regions of its locales (availableLocales.json, its full list, and defaultContent.json:
 * fr_BE, fr_CA, fr_FR...) and its likely one (likelySubtags.json), which is its usual one; a region that is not an
 * officially assigned ISO 3166-1 alpha-2 code (the world 001, Europe 150, Latin America 419, Kosovo XK, the Canary
 * Islands IC, Ceuta and Melilla EA, Diego Garcia DG) is left out. The languages: every language of a CLDR locale, and
 * every language of the shipped language list (languages.json.example), those with no CLDR locale holding their likely
 * territory alone (Haitian: HT; Twi, which CLDR names Akan: GH). A language whose likely region is the world
 * (Esperanto, Interlingua, Ido, Volapuk, Toki Pona) has no entry, so no territory.
 *
 * The one list: the shape check below refuses any other territory for a language, getSocialPreview answers each
 * project language's, and a page's address announces the usual one when the author chose none. A language added to
 * the list of languages is added here too, from CLDR. Names are not held here: the panel's browser names each territory
 * in the panel's language.
 */
if (!defined('QS_SOCIAL_LANGUAGE_TERRITORIES')) {
    define('QS_SOCIAL_LANGUAGE_TERRITORIES', [
    'aa' => 'ET',
    'ab' => 'GE',
    'ady' => 'RU JO TR',
    'ae' => 'IR',
    'af' => 'ZA NA',
    'agq' => 'CM',
    'ak' => 'GH',
    'am' => 'ET',
    'an' => 'ES',
    'ar' => 'EG AE BH DJ DZ EH ER IL IQ JO KM KW LB LY MA MR OM PS QA SA SD SO SS SY TD TN YE',
    'as' => 'IN',
    'asa' => 'TZ',
    'ast' => 'ES',
    'av' => 'RU',
    'ay' => 'BO',
    'az' => 'AZ',
    'ba' => 'RU',
    'bal' => 'PK',
    'bas' => 'CM',
    'be' => 'BY',
    'bem' => 'ZM',
    'bez' => 'TZ',
    'bg' => 'BG',
    'bgc' => 'IN',
    'bho' => 'IN',
    'bi' => 'VU',
    'blo' => 'BJ',
    'bm' => 'ML',
    'bn' => 'BD IN',
    'bo' => 'CN IN',
    'br' => 'FR',
    'brx' => 'IN',
    'bs' => 'BA',
    'bua' => 'RU',
    'ca' => 'ES AD FR IT',
    'ccp' => 'BD IN',
    'ce' => 'RU',
    'ceb' => 'PH',
    'cgg' => 'UG',
    'ch' => 'GU',
    'chr' => 'US',
    'ckb' => 'IQ IR',
    'co' => 'FR',
    'cop' => 'EG',
    'cr' => 'CA',
    'cs' => 'CZ',
    'csw' => 'CA',
    'cu' => 'RU',
    'cv' => 'RU',
    'cy' => 'GB',
    'da' => 'DK GL',
    'dav' => 'KE',
    'de' => 'DE AT BE CH IT LI LU',
    'dje' => 'NE',
    'doi' => 'IN',
    'dsb' => 'DE',
    'dua' => 'CM',
    'dv' => 'MV',
    'dyo' => 'SN',
    'dz' => 'BT',
    'ebu' => 'KE',
    'ee' => 'GH TG',
    'el' => 'GR CY',
    'en' => 'US AE AG AI AS AT AU BB BE BI BM BS BW BZ CA CC CH CK CM CX CY CZ DE DK DM EE ER ES FI FJ'
        . ' FK FM FR GB GD GE GG GH GI GM GS GU GY HK HU ID IE IL IM IN IO IT JE JM JP KE KI KN KY LC'
        . ' LR LS LT LV MG MH MO MP MS MT MU MV MW MY NA NF NG NL NO NR NU NZ PG PH PK PL PN PR PT PW'
        . ' RO RW SB SC SD SE SG SH SI SK SL SS SX SZ TC TK TO TT TV TZ UA UG UM VC VG VI VU WS ZA ZM'
        . ' ZW',
    'es' => 'ES AR BO BR BZ CL CO CR CU DO EC GQ GT HN MX NI PA PE PH PR PY SV US UY VE',
    'et' => 'EE',
    'eu' => 'ES',
    'ewo' => 'CM',
    'fa' => 'IR AF',
    'ff' => 'SN BF CM GH GM GN GW LR MR NE NG SL',
    'fi' => 'FI',
    'fil' => 'PH',
    'fj' => 'FJ',
    'fo' => 'FO DK',
    'fr' => 'FR BE BF BI BJ BL CA CD CF CG CH CI CM DJ DZ GA GF GN GP GQ HT KM LU MA MC MF MG ML MQ MR'
        . ' MU NC NE PF PM RE RW SC SN SY TD TG TN VU WF YT',
    'fur' => 'IT',
    'fy' => 'NL',
    'ga' => 'IE GB',
    'gaa' => 'GH',
    'gd' => 'GB',
    'gl' => 'ES',
    'gn' => 'PY',
    'gsw' => 'CH FR LI',
    'gu' => 'IN',
    'guz' => 'KE',
    'gv' => 'IM',
    'ha' => 'NG GH NE',
    'haw' => 'US',
    'he' => 'IL',
    'hi' => 'IN',
    'ho' => 'PG',
    'hr' => 'HR BA',
    'hsb' => 'DE',
    'ht' => 'HT',
    'hu' => 'HU',
    'hy' => 'AM',
    'hz' => 'NA',
    'id' => 'ID',
    'ie' => 'EE',
    'ig' => 'NG',
    'ii' => 'CN',
    'ik' => 'US',
    'is' => 'IS',
    'it' => 'IT CH SM VA',
    'iu' => 'CA',
    'ja' => 'JP',
    'jgo' => 'CM',
    'jmc' => 'TZ',
    'jv' => 'ID',
    'ka' => 'GE',
    'kab' => 'DZ',
    'kam' => 'KE',
    'kbd' => 'RU TR',
    'kde' => 'TZ',
    'kea' => 'CV',
    'kek' => 'GT',
    'kg' => 'CD',
    'kgp' => 'BR',
    'khq' => 'ML',
    'ki' => 'KE',
    'kj' => 'NA',
    'kk' => 'KZ CN',
    'kkj' => 'CM',
    'kl' => 'GL',
    'kln' => 'KE',
    'km' => 'KH',
    'kn' => 'IN',
    'ko' => 'KR CN KP',
    'kok' => 'IN',
    'kr' => 'NG',
    'ks' => 'IN',
    'ksb' => 'TZ',
    'ksf' => 'CM',
    'ksh' => 'DE',
    'ku' => 'TR IQ SY',
    'kv' => 'RU',
    'kw' => 'GB',
    'kxv' => 'IN',
    'ky' => 'KG',
    'la' => 'VA',
    'lag' => 'TZ',
    'lb' => 'LU',
    'lg' => 'UG',
    'li' => 'NL',
    'lij' => 'IT',
    'lkt' => 'US',
    'lld' => 'IT',
    'lmo' => 'IT',
    'ln' => 'CD AO CF CG',
    'lo' => 'LA',
    'lrc' => 'IR IQ',
    'lt' => 'LT',
    'lu' => 'CD',
    'luo' => 'KE',
    'luy' => 'KE',
    'lv' => 'LV',
    'mai' => 'IN',
    'mas' => 'KE TZ',
    'mer' => 'KE',
    'mfe' => 'MU',
    'mg' => 'MG',
    'mgh' => 'MZ',
    'mgo' => 'CM',
    'mh' => 'MH',
    'mi' => 'NZ',
    'mk' => 'MK',
    'ml' => 'IN',
    'mn' => 'MN',
    'mni' => 'IN',
    'mr' => 'IN',
    'ms' => 'MY BN ID SG',
    'mt' => 'MT',
    'mua' => 'CM',
    'my' => 'MM',
    'mzn' => 'IR',
    'na' => 'NR',
    'naq' => 'NA',
    'nb' => 'NO SJ',
    'nd' => 'ZW',
    'nds' => 'DE NL',
    'ne' => 'NP IN',
    'ng' => 'NA',
    'nl' => 'NL AW BE BQ CW SR SX',
    'nmg' => 'CM',
    'nn' => 'NO',
    'nnh' => 'CM',
    'no' => 'NO',
    'nqo' => 'GN',
    'nr' => 'ZA',
    'nso' => 'ZA',
    'nus' => 'SS',
    'nv' => 'US',
    'ny' => 'MW',
    'nyn' => 'UG',
    'oc' => 'FR ES',
    'oj' => 'CA',
    'om' => 'ET KE',
    'or' => 'IN',
    'os' => 'GE RU',
    'pa' => 'IN PK',
    'pcm' => 'NG',
    'pi' => 'GB',
    'pl' => 'PL',
    'pms' => 'IT',
    'prg' => 'PL',
    'ps' => 'AF PK',
    'pt' => 'BR AO CH CV GQ GW LU MO MZ PT ST TL',
    'qu' => 'PE BO EC',
    'quc' => 'GT',
    'raj' => 'IN',
    'rm' => 'CH',
    'rn' => 'BI',
    'ro' => 'RO MD',
    'rof' => 'TZ',
    'ru' => 'RU BY KG KZ MD UA',
    'rw' => 'RW',
    'rwk' => 'TZ',
    'sa' => 'IN',
    'sah' => 'RU',
    'saq' => 'KE',
    'sat' => 'IN',
    'sbp' => 'TZ',
    'sc' => 'IT',
    'scn' => 'IT',
    'sd' => 'PK IN',
    'se' => 'NO FI SE',
    'seh' => 'MZ',
    'ses' => 'ML',
    'sg' => 'CF',
    'shi' => 'MA',
    'shn' => 'MM TH',
    'si' => 'LK',
    'sk' => 'SK',
    'sl' => 'SI',
    'sm' => 'WS',
    'smn' => 'FI',
    'sn' => 'ZW',
    'so' => 'SO DJ ET KE',
    'sq' => 'AL MK',
    'sr' => 'RS BA ME',
    'ss' => 'ZA',
    'st' => 'ZA LS',
    'su' => 'ID',
    'suz' => 'NP',
    'sv' => 'SE AX FI',
    'sw' => 'TZ CD KE UG',
    'syr' => 'IQ SY',
    'szl' => 'PL',
    'ta' => 'IN LK MY SG',
    'te' => 'IN',
    'teo' => 'UG KE',
    'tg' => 'TJ',
    'th' => 'TH',
    'ti' => 'ET ER',
    'tk' => 'TM',
    'tl' => 'PH',
    'tn' => 'ZA BW',
    'to' => 'TO',
    'tr' => 'TR CY',
    'ts' => 'ZA',
    'tt' => 'RU',
    'tw' => 'GH',
    'twq' => 'NE',
    'ty' => 'PF',
    'tyv' => 'RU',
    'tzm' => 'MA',
    'ug' => 'CN',
    'uk' => 'UA',
    'ur' => 'PK IN',
    'uz' => 'UZ AF',
    'vai' => 'LR',
    've' => 'ZA',
    'vec' => 'IT',
    'vi' => 'VN',
    'vmw' => 'MZ',
    'vun' => 'TZ',
    'wa' => 'BE',
    'wae' => 'CH',
    'wo' => 'SN',
    'xh' => 'ZA',
    'xnr' => 'IN',
    'xog' => 'UG',
    'yav' => 'CM',
    'yi' => 'UA',
    'yo' => 'NG BJ',
    'yrl' => 'BR CO VE',
    'yue' => 'HK CN MO',
    'za' => 'CN',
    'zgh' => 'MA',
    'zh' => 'CN HK MO MY SG TW',
    'zu' => 'ZA',
    ]);
}

if (!function_exists('qs_social_language_territories')) {
    /** A language's territories, its usual one first; none for a language CLDR gives none. @return list<string> */
    function qs_social_language_territories(string $lang): array
    {
        $codes = QS_SOCIAL_LANGUAGE_TERRITORIES[$lang] ?? '';
        return $codes === '' ? [] : explode(' ', $codes);
    }
}

if (!function_exists('qs_social_usual_territory')) {
    /** The territory a language's address announces when the author chose none, or null for a language with none. */
    function qs_social_usual_territory(string $lang): ?string
    {
        return qs_social_language_territories($lang)[0] ?? null;
    }
}

if (!function_exists('qs_social_value_problem')) {
    /**
     * Why a stored social value is not one the writer could have written, or null when it is one. The one
     * definition of each value's shape: setSocialPreview checks what it is about to write with it, the
     * import checks an archive's file with it, and a served page writes no tag for a value it refuses.
     *
     *   picture     a Media file in `images`, named as Media's metadata names it (`images/x.png`): never a
     *               folder, a hidden name or SVG (no platform draws one)
     *   cardType    `large` or `small`
     *   xAccount    `@` and 1 to 15 letters, digits or `_`
     *   themeColor  `#` and 6 hexadecimal digits (the writer doubles a 3-digit one: Discord reads 6 or 8 alone)
     *   locales     a map of a language code (2 or 3 small letters) to one of its territories
     *               (QS_SOCIAL_LANGUAGE_TERRITORIES)
     *
     * @param mixed $value
     */
    function qs_social_value_problem(string $field, $value): ?string
    {
        switch ($field) {
            case 'picture':
                if (!is_string($value) || strlen($value) > 255
                    || preg_match('#^images/[^/\\\\\x00-\x1F\x7F]+$#D', $value) !== 1) {
                    return 'a picture is a file of Media\'s images, named as Media names it (images/<file>)';
                }
                $name = substr($value, 7);
                if ($name[0] === '.') {
                    return 'a picture\'s name does not start with a dot';
                }
                if (preg_match('/\.svgz?$/iD', $name) === 1) {
                    return 'a picture is never SVG: no platform draws one in a card';
                }
                return null;
            case 'cardType':
                return is_string($value) && isset(QS_SOCIAL_CARD_TYPES[$value]) ? null : 'a card type is large or small';
            case 'xAccount':
                return is_string($value) && preg_match('/^@[A-Za-z0-9_]{1,15}$/D', $value) === 1
                    ? null : 'an X account is @ and 1 to 15 letters, digits or underscores';
            case 'themeColor':
                return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1
                    ? null : 'a theme colour is # and 6 hexadecimal digits (#336699), or 3 the writer doubles (#369)';
            case 'locales':
                if (!is_array($value)) {
                    return 'locales is a map of a language code to its territory';
                }
                foreach ($value as $code => $territory) {
                    if (preg_match('/^[a-z]{2,3}$/D', (string) $code) !== 1) {
                        return "'" . $code . "' is not a language code";
                    }
                    $codes = qs_social_language_territories((string) $code);
                    if (!$codes) {
                        return "'" . $code . "' has no territory: CLDR gives that language none";
                    }
                    if (!is_string($territory) || !in_array($territory, $codes, true)) {
                        return "the territory of '" . $code . "' is one of the " . count($codes) . ' CLDR gives that language, in capitals ('
                            . implode(', ', array_slice($codes, 0, 3)) . (count($codes) > 3 ? ', ...' : '')
                            . "; getSocialPreview's territories lists them)";
                    }
                }
                return null;
        }
        return "'" . $field . "' is not a social value";
    }
}

if (!function_exists('qs_social_values')) {
    /**
     * A project's social values, as a served page reads them: `site` and `pages` (route => values), each value
     * kept only when qs_social_value_problem() accepts it, so a file edited by hand never puts a refused value in
     * a tag. A build's copy also holds `media`, the Media entry of each picture it names (build.php).
     * Read once per request and project.
     *
     * @return array{site: array, pages: array, media: array}
     */
    function qs_social_values(string $projectPath): array
    {
        static $read = [];
        if (!isset($read[$projectPath])) {
            $read[$projectPath] = qs_social_values_read($projectPath);
        }
        return $read[$projectPath];
    }
}

if (!function_exists('qs_social_values_read')) {
    /**
     * qs_social_values() read afresh from the file, for a writer that must see what it just wrote.
     *
     * @return array{site: array, pages: array, media: array}
     */
    function qs_social_values_read(string $projectPath): array
    {
        $out = ['site' => [], 'pages' => [], 'media' => []];
        $file = $projectPath . '/' . QS_SOCIAL_FILE;
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($data)) {
            foreach (QS_SOCIAL_SITE_FIELDS as $field) {
                if (array_key_exists($field, (array) ($data['site'] ?? [])) && qs_social_value_problem($field, $data['site'][$field]) === null) {
                    $out['site'][$field] = $data['site'][$field];
                }
            }
            foreach ((is_array($data['pages'] ?? null) ? $data['pages'] : []) as $route => $values) {
                foreach (QS_SOCIAL_PAGE_FIELDS as $field) {
                    if (is_array($values) && array_key_exists($field, $values) && qs_social_value_problem($field, $values[$field]) === null) {
                        $out['pages'][(string) $route][$field] = $values[$field];
                    }
                }
            }
            if (is_array($data['media'] ?? null)) {
                foreach ($data['media'] as $key => $entry) {
                    if (is_array($entry)) {
                        $out['media'][(string) $key] = $entry;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('qs_social_picture')) {
    /**
     * A picture's Media entry (its `width`, `height`, `mime_type`, `alt`…), or null when the picture is not, or no
     * longer, in Media. On `/p/` and in the panel: the project's Media metadata, and the file itself must be
     * there; in a build: the build's own copy of the entry, written only for a file the build carries.
     */
    function qs_social_picture(string $projectPath, string $key): ?array
    {
        if (qs_social_value_problem('picture', $key) !== null) {
            return null;
        }
        if (defined('QS_BUILD_VERSION')) {
            $entry = qs_social_values($projectPath)['media'][$key] ?? null;
            return is_array($entry) ? $entry : null;
        }
        static $metadata = [];
        if (!isset($metadata[$projectPath])) {
            $decoded = json_decode((string) @file_get_contents($projectPath . '/data/assets_metadata.json'), true);
            $metadata[$projectPath] = is_array($decoded) ? $decoded : [];
        }
        $entry = $metadata[$projectPath][$key] ?? null;
        if (!is_array($entry) || !is_file($projectPath . '/public/assets/' . $key)) {
            return null;
        }
        return $entry;
    }
}

if (!function_exists('qs_social_picture_alt')) {
    /**
     * A picture's description for `og:image:alt`, in a language: Media's `alt`. The one reader of it for the
     * tags, so a description per language changes this function alone.
     */
    function qs_social_picture_alt(array $entry, string $lang): string
    {
        return is_string($entry['alt'] ?? null) ? trim($entry['alt']) : '';
    }
}

if (!function_exists('qs_social_site_path')) {
    /**
     * A site path from its segments, each written encoded (a segment arriving encoded is decoded first, so it is
     * never encoded twice): no byte of an address reaches a tag as it came. A path that ends with `/` keeps it
     * (a language's home is `fr/`).
     */
    function qs_social_site_path(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $encoded = implode('/', array_map(static fn($s) => rawurlencode(rawurldecode($s)), $segments));
        return ($encoded !== '' && substr($path, -1) === '/') ? $encoded . '/' : $encoded;
    }
}

if (!function_exists('qs_social_tag_list')) {
    /**
     * THE tags of one page, in their order, each with a value: [attribute, name, content]. The one producer:
     * a served page writes these (qs_social_head_tags()), getSocialPreview reports these.
     *
     * @param array $in
     *   projectPath  the project's folder (a build: its secure folder)
     *   routePath    the route as the routes name it (`products/:slug`), for the page's own values
     *   notFound     true for a response that is not a page of the site: the theme colour alone
     *   title        the page's title, as its <title> shows it
     *   description  the page's description in this language, its placeholders filled, or null
     *   siteName     the site's name in this language, or null
     *   lang         the page's language code
     *   languages    the project's language codes when it is multilingual, else []
     *   sitePath     the page's address below the site's base, encoded (qs_social_site_path())
     *   derivedBase  the base this request derives, or null for QS_PUBLIC_BASE (qs_full_address())
     * @return list<array{0: string, 1: string, 2: string}>
     */
    function qs_social_tag_list(array $in): array
    {
        $values = qs_social_values((string) $in['projectPath']);
        $site = $values['site'];
        $tags = [];
        $add = static function (string $attribute, string $name, ?string $content) use (&$tags): void {
            // A line break, a tab or any other control character becomes one space: a tag holds one line.
            $content = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $content));
            if ($content !== '') {
                $tags[] = [$attribute, $name, $content];
            }
        };

        if (empty($in['notFound'])) {
            $page = $values['pages'][(string) $in['routePath']] ?? [];
            $lang = (string) $in['lang'];
            $derivedBase = $in['derivedBase'] ?? null;

            $add('name', 'description', $in['description'] ?? null);
            $add('property', 'og:title', (string) $in['title']);
            $add('property', 'og:description', $in['description'] ?? null);
            $add('property', 'og:type', 'website');
            $add('property', 'og:url', qs_full_address((string) $in['sitePath'], $derivedBase));
            $add('property', 'og:site_name', $in['siteName'] ?? null);

            // Each language's territory: the author's, else the language's usual one.
            $locales = $site['locales'] ?? [];
            $territory = static fn(string $code): ?string => $locales[$code] ?? qs_social_usual_territory($code);
            if (($own = $territory($lang)) !== null) {
                $add('property', 'og:locale', $lang . '_' . $own);
                foreach ((array) ($in['languages'] ?? []) as $other) {
                    if ($other !== $lang && ($theirs = $territory((string) $other)) !== null) {
                        $add('property', 'og:locale:alternate', $other . '_' . $theirs);
                    }
                }
            }

            $pictureKey = $page['picture'] ?? $site['picture'] ?? null;
            $picture = $pictureKey !== null ? qs_social_picture((string) $in['projectPath'], $pictureKey) : null;
            if ($picture !== null) {
                $add('property', 'og:image', qs_full_address('assets/' . qs_social_site_path($pictureKey), $derivedBase));
                if (is_int($picture['width'] ?? null) && is_int($picture['height'] ?? null)
                    && $picture['width'] > 0 && $picture['height'] > 0) {
                    $add('property', 'og:image:width', (string) $picture['width']);
                    $add('property', 'og:image:height', (string) $picture['height']);
                }
                if (is_string($picture['mime_type'] ?? null) && preg_match('#^image/[a-z0-9.+-]+$#D', $picture['mime_type']) === 1) {
                    $add('property', 'og:image:type', $picture['mime_type']);
                }
                $add('property', 'og:image:alt', qs_social_picture_alt($picture, $lang));
            }

            $cardType = $page['cardType'] ?? $site['cardType'] ?? ($picture !== null ? 'large' : 'small');
            $add('name', 'twitter:card', QS_SOCIAL_CARD_TYPES[$cardType]);
            $add('name', 'twitter:site', $site['xAccount'] ?? null);
        }
        $add('name', 'theme-color', $site['themeColor'] ?? null);
        return $tags;
    }
}

if (!function_exists('qs_social_head_tags')) {
    /**
     * The tags of the page this request serves, as HTML for its <head>, each escaped as the title is. Both page
     * shells call it just after the <title>; '' when there is nothing to write.
     *
     * @param string      $title  the title the shell computed (qs_page_title())
     * @param string      $lang   the page's language (the shell's, as <html lang> states it)
     * @param object|null $route  the request's TrimParameters
     * @param string      $before written before each tag (the build's shell indents its head)
     * @param string      $after  written after each tag
     */
    function qs_social_head_tags(string $title, string $lang, $route, string $before = '', string $after = ''): string
    {
        $notFound = $route === null || !$route->routeFound() || $route->routePath() === '404';
        $in = ['projectPath' => PROJECT_PATH, 'notFound' => $notFound, 'routePath' => '', 'title' => $title, 'lang' => $lang];
        if (!$notFound) {
            require_once __DIR__ . '/../classes/Translator.php';
            $routePath = (string) $route->routePath();
            $description = Translator::lookup('page.descriptions.' . $routePath);
            // The address the request was served at, below the site's base and without its query string
            // (after an internal alias, the page's own address: the router's), written as the site links its
            // pages: no trailing slash but on a language's home (`fr/`, as processUrl() writes it). `/p/` reads
            // its path from its segments, so a visitor's own trailing slash is known in neither path.
            $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $sitePath = qs_path_without_base(is_string($requestPath) ? $requestPath : '');
            if ($sitePath !== '' && qs_project_is_multilingual() && in_array($sitePath, qs_project_languages(), true)) {
                $sitePath .= '/';
            }
            $in += [
                'description' => $description !== null
                    ? qs_apply_runtime_placeholders($description, (array) $route->routeParams()) : null,
                'siteName'    => Translator::lookup('site.name'),
                'languages'   => qs_project_is_multilingual() ? qs_project_languages() : [],
                'sitePath'    => qs_social_site_path($sitePath),
            ];
            $in['routePath'] = $routePath;
        }
        $html = '';
        foreach (qs_social_tag_list($in) as [$attribute, $name, $content]) {
            $html .= $before . '<meta ' . $attribute . '="' . $name . '" content="'
                . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $after;
        }
        return $html;
    }
}
