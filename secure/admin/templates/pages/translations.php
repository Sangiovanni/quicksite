<?php
/**
 * Translations page.
 *
 * A site's words in every language, side by side: a row per key, a column per language, each word as it is stored
 * (markers and placeholders as written), a gap where a language has none, and each key's state per language (used,
 * unset, unused). Above them, the site's languages: each one's words and gaps, the default marked, and a language
 * added, made the default or removed; on a one-language site, a language made the site's one language.
 *
 * Reachable by every member rank: the page is gated on getTranslations (content.read). Every control that writes
 * carries its command's role mark: the static ones here (data-requires-command, which admin.js filters once the
 * permissions load), the drawn ones in translations.js, drawn only for a role that can run their command
 * (translation.write).
 *
 * It reads and writes through the panel's one list of a site's words (js/core/site-words.js, QSSiteWords), which the
 * editor's Translation tool reads too: the same reading, the same writes, the same Create.
 *
 * Static structure here; every dynamic part is built by translations.js with createElement and named _render*
 * helpers (the CLAUDE.md HTML-in-JS rule). Its stylesheet is css/translations-admin.css, with no media query: the
 * table scrolls in .admin-table-wrapper at every width, and everything else wraps.
 */

// The page's words, translations.js reading each by its key's whole path; `common` comes with every page.
?>
<?= __adminWords(['translations']) ?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/>
        </svg>
        <?= adminEscape(__admin('translations.title')) ?>
    </h1>
    <p class="admin-page-header__subtitle"><?= adminEscape(__admin('translations.subtitle')) ?></p>
</div>

<p class="admin-alert admin-alert--info" id="translations-readonly" hidden><?= adminEscape(__admin('translations.readOnly')) ?></p>
<p class="admin-alert admin-alert--warning translations-notice" id="translations-notice" role="status" hidden></p>

<section class="admin-card translations-languages" aria-labelledby="translations-languages-title">
    <div class="admin-card__header">
        <h2 class="admin-card__title" id="translations-languages-title"><?= adminEscape(__admin('translations.languagesTitle')) ?></h2>
    </div>
    <div class="admin-card__body">
    <p class="admin-hint" id="translations-languages-hint" hidden><?= adminEscape(__admin('translations.languagesHint')) ?></p>
    <div class="translations-languages__list" id="translations-languages"></div>
    <div class="translations-languages__offer" id="translations-switch-offer" role="status" hidden></div>
    <div class="translations-languages__add" id="translations-language-add" data-requires-command="addLang" hidden>
        <label class="admin-label" for="translations-language-pick"><?= adminEscape(__admin('translations.addLanguage')) ?></label>
        <div class="translations-languages__add-row">
            <select class="admin-input translations-languages__pick" id="translations-language-pick"></select>
            <button type="button" class="admin-btn admin-btn--primary" id="translations-language-add-btn"><?= adminEscape(__admin('translations.addLanguageButton')) ?></button>
            <button type="button" class="admin-btn admin-btn--ghost" id="translations-language-default-btn" data-requires-command="setDefaultLang" hidden><?= adminEscape(__admin('translations.makeDefault')) ?></button>
        </div>
    </div>
    </div>
</section>

<section class="admin-card translations-words" aria-labelledby="translations-words-title">
    <div class="admin-card__header">
        <h2 class="admin-card__title" id="translations-words-title"><?= adminEscape(__admin('translations.wordsTitle')) ?></h2>
    </div>
    <div class="admin-card__body">

    <div class="translations-toolbar">
        <input type="search" class="admin-input translations-toolbar__search" id="translations-search" autocomplete="off"
               placeholder="<?= adminAttr(__admin('translations.search')) ?>" aria-label="<?= adminAttr(__admin('translations.search')) ?>">
        <label class="translations-toolbar__scope">
            <span class="translations-toolbar__label"><?= adminEscape(__admin('translations.filterWhere')) ?></span>
            <select class="admin-input" id="translations-scope"></select>
        </label>
    </div>

    <div class="translations-filters" role="group" aria-label="<?= adminAttr(__admin('translations.filterStates')) ?>">
        <label class="translations-chip translations-chip--used" title="<?= adminAttr(__admin('translations.stateUsedHint')) ?>">
            <input type="checkbox" data-state="used" checked>
            <span><?= adminEscape(__admin('translations.stateUsed')) ?></span>
            <span class="translations-chip__count" id="translations-count-used">0</span>
        </label>
        <label class="translations-chip translations-chip--unset" title="<?= adminAttr(__admin('translations.stateUnsetHint')) ?>">
            <input type="checkbox" data-state="unset" checked>
            <span><?= adminEscape(__admin('translations.stateUnset')) ?></span>
            <span class="translations-chip__count" id="translations-count-unset">0</span>
        </label>
        <label class="translations-chip translations-chip--unused" title="<?= adminAttr(__admin('translations.stateUnusedHint')) ?>">
            <input type="checkbox" data-state="unused" checked>
            <span><?= adminEscape(__admin('translations.stateUnused')) ?></span>
            <span class="translations-chip__count" id="translations-count-unused">0</span>
        </label>
        <label class="translations-chip translations-chip--same" id="translations-same-chip" title="<?= adminAttr(__admin('translations.sameAsDefaultHint')) ?>" hidden>
            <input type="checkbox" data-state="same">
            <span><?= adminEscape(__admin('translations.sameAsDefault')) ?></span>
            <span class="translations-chip__count" id="translations-count-same">0</span>
        </label>
    </div>

    <details class="translations-add" id="translations-add" data-requires-command="setTranslationKeys">
        <summary class="translations-add__summary"><?= adminEscape(__admin('translations.addKey')) ?></summary>
        <form class="translations-add__form" id="translations-add-form" novalidate>
            <div class="admin-form-group">
                <label class="admin-label" for="translations-add-key"><?= adminEscape(__admin('translations.addKeyName')) ?></label>
                <input type="text" class="admin-input" id="translations-add-key" autocomplete="off" spellcheck="false"
                       placeholder="<?= adminAttr(__admin('translations.addKeyNamePlaceholder')) ?>">
            </div>
            <div class="admin-form-group">
                <label class="admin-label" for="translations-add-language"><?= adminEscape(__admin('translations.addKeyLanguage')) ?></label>
                <select class="admin-input" id="translations-add-language"></select>
            </div>
            <div class="admin-form-group translations-add__word">
                <label class="admin-label" for="translations-add-word"><?= adminEscape(__admin('translations.addKeyWord')) ?></label>
                <textarea class="admin-input" id="translations-add-word" rows="2"></textarea>
                <p class="admin-hint"><?= adminEscape(__admin('translations.addKeyHint')) ?></p>
            </div>
            <div class="translations-add__actions">
                <button type="submit" class="admin-btn admin-btn--primary" id="translations-add-create"><?= adminEscape(__admin('translations.addKeyCreate')) ?></button>
                <span class="translations-add__error" id="translations-add-error" role="alert"></span>
            </div>
        </form>
    </details>

    <p class="translations-count" id="translations-count" aria-live="polite"></p>
    <div class="translations-table" id="translations-table">
        <p class="admin-loading"><span class="admin-spinner"></span> <?= adminEscape(__admin('common.loading')) ?></p>
    </div>
    </div>
</section>

<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/translations.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/translations.js') ?>"></script>
