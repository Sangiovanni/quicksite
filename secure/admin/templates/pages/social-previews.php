<?php
/**
 * Social previews page.
 *
 * The card a shared link of the site gets on each platform, drawn as a look-alike for a computer or a phone, and the
 * values it is drawn from: the site's (a picture, a card type, an X account, a theme colour, a territory per language)
 * and one page's (a picture, a card type, its description in the language shown).
 *
 * Reachable by every member rank: the page is gated on getSocialPreview (content.read), so a viewer sees the cards and
 * the values. The controls that send setSocialPreview (route.write) are drawn by social-previews.js only for a role
 * that can run it. Every figure a card uses (a crop, a cut, a line count) is read from getSocialPreview's platform
 * table, never written here or in the script.
 *
 * Static structure here; every dynamic part is built by social-previews.js with createElement and named _render*
 * helpers (the CLAUDE.md HTML-in-JS rule). Its stylesheet is css/social-previews.css, with no media query: the three
 * views live in responsive.css.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php';
require_once SECURE_FOLDER_PATH . '/src/functions/socialPreviewHelpers.php'; // QS_SOCIAL_DESCRIPTION_MAX
require_once SECURE_FOLDER_PATH . '/src/functions/textMarkers.php';          // QS_TEXT_MARKERS

$baseUrl = rtrim(BASE_URL, '/');

// The edited project's default language: the language a one-language site's description is written in.
$__spProject = (string) ($router->getCurrentProject() ?: '');
$__spConfigFile = SECURE_FOLDER_PATH . '/projects/' . $__spProject . '/config.php';
$__spConfig = ($__spProject !== '' && is_file($__spConfigFile)) ? (require $__spConfigFile) : [];
$__spConfig = is_array($__spConfig) ? $__spConfig : [];
?>

<script>
window.QS_SOCIAL_CONFIG = <?= json_encode([
    'project'        => $__spProject,
    'defaultLang'    => qs_project_default_language($__spConfig),
    // The longest description setSocialPreview writes: the field holds no more.
    'descriptionMax' => QS_SOCIAL_DESCRIPTION_MAX,
    // The markers a word's formatting is written with: a description typed is judged by its words, these removed,
    // as the page's tags carry it (core/text-markers.js reads the list; the script holds none).
    'textMarkers'    => QS_TEXT_MARKERS,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php // The page's words, social-previews.js reading each by its key's whole path; `common` comes with every page. ?>
<?= __adminWords(['socialPreviews']) ?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
        </svg>
        <?= adminEscape(__admin('socialPreviews.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('socialPreviews.subtitle')) ?></p>
</div>

<p class="admin-alert admin-alert--info" id="social-readonly" hidden><?= adminEscape(__admin('socialPreviews.readOnly')) ?></p>

<!-- One choice drives the page: the whole site or one page, whose values the form below holds and whose cards are drawn under it -->
<div class="social-previews__pickers">
    <div class="admin-form-group social-previews__picker">
        <label class="admin-label" for="social-target"><?= adminEscape(__admin('socialPreviews.target')) ?></label>
        <select id="social-target" class="admin-select"></select>
    </div>
    <div class="admin-form-group social-previews__picker" id="social-lang-group" hidden>
        <label class="admin-label" for="social-lang"><?= adminEscape(__admin('socialPreviews.language')) ?></label>
        <select id="social-lang" class="admin-select"></select>
    </div>
</div>

<section class="admin-section" id="social-values-section">
    <h2 class="admin-section__title" id="social-values-title"><?= adminEscape(__admin('socialPreviews.siteTitle')) ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="social-values">
            <div class="admin-loading"><span class="admin-spinner"></span><span><?= adminEscape(__admin('common.loading')) ?></span></div>
        </div>
    </div>
</section>

<section class="admin-section" id="social-cards-section">
    <h2 class="admin-section__title"><?= adminEscape(__admin('socialPreviews.cardsTitle')) ?></h2>
    <p class="admin-hint social-previews__look-alike"><?= adminEscape(__admin('socialPreviews.lookAlike')) ?></p>
    <div class="social-previews__bar">
        <p class="social-previews__cards-of" id="social-cards-of"></p>
        <div class="social-previews__device" role="group" aria-label="<?= adminAttr(__admin('socialPreviews.device')) ?>">
            <button type="button" class="admin-btn admin-btn--outline social-previews__device-btn" data-device="computer" aria-pressed="true"><?= adminEscape(__admin('socialPreviews.deviceComputer')) ?></button>
            <button type="button" class="admin-btn admin-btn--outline social-previews__device-btn" data-device="phone" aria-pressed="false"><?= adminEscape(__admin('socialPreviews.devicePhone')) ?></button>
        </div>
    </div>
    <div class="social-previews__cards" id="social-cards"></div>
    <p class="admin-hint social-previews__instagram" id="social-instagram"></p>
</section>

<script src="<?= $baseUrl ?>/admin/assets/js/components/colorpicker.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/components/colorpicker.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/core/media-pictures.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/media-pictures.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/core/social-figures.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/social-figures.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/core/text-markers.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/text-markers.js') ?>"></script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/social-previews.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/social-previews.js') ?>"></script>
