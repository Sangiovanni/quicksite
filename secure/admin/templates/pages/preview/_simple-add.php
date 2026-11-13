<?php
/**
 * The simple Add: a beginner's list of plain elements, shown in the Add form in place of its five tabs while the
 * panel is in simple mode (the page's admin-body--simple class; preview.js decides which form opens). Each item
 * has a small form of its own here; what each one sends is preview-simple-add.js's. The list is fixed in code:
 * each item needs its own form, which a line in a settings file could not bring.
 *
 * Included by contextual-add.php. Before / After / Inside, Cancel and Add are the Add form's own.
 */

$simpleAddItems = [
    'text'   => ['preview.simpleAdd.items.text', 'Text',
                 '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="4" y1="14" x2="20" y2="14"/><line x1="4" y1="18" x2="14" y2="18"/>'],
    'title'  => ['preview.simpleAdd.items.title', 'Title',
                 '<polyline points="4 7 4 4 20 4 20 7"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="9" y1="20" x2="15" y2="20"/>'],
    'button' => ['preview.simpleAdd.items.button', 'Button',
                 '<rect x="3" y="7" width="18" height="10" rx="5"/><line x1="8" y1="12" x2="16" y2="12"/>'],
    'link'   => ['preview.simpleAdd.items.link', 'Link',
                 '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'],
    'image'  => ['preview.simpleAdd.items.image', 'Image',
                 '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>'],
    'video'  => ['preview.simpleAdd.items.video', 'Video',
                 '<rect x="2" y="5" width="15" height="14" rx="2"/><polygon points="23 7 17 12 23 17 23 7"/>'],
    'audio'  => ['preview.simpleAdd.items.audio', 'Audio',
                 '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'],
    'list'   => ['preview.simpleAdd.items.list', 'List',
                 '<line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>'],
    'block'  => ['preview.simpleAdd.items.block', 'Block',
                 '<rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="4 3"/>'],
];
?>
<div class="preview-contextual-form__section" id="add-simple" style="display: none;">
    <label class="preview-contextual-form__section-label"><?= __admin('preview.simpleAdd.pick', 'What do you want to add?') ?></label>
    <div class="preview-simple-add__items" id="add-simple-items">
        <?php foreach ($simpleAddItems as $preset => [$labelKey, $labelEn, $icon]): ?>
        <button type="button" class="preview-simple-add__item" data-preset="<?= $preset ?>" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
            <span><?= __admin($labelKey, $labelEn) ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Text: a paragraph -->
    <div class="preview-contextual-form__field" data-preset-fields="text" style="display: none;">
        <label for="simple-add-text"><?= __admin('preview.simpleAdd.text', 'Text') ?>:</label>
        <textarea id="simple-add-text" class="admin-input" rows="3" placeholder="<?= __admin('preview.simpleAdd.textPlaceholder', 'Type the text…') ?>"></textarea>
    </div>

    <!-- Title: big, medium or small (h1, h2, h3) -->
    <div class="preview-contextual-form__section" data-preset-fields="title" style="display: none;">
        <div class="preview-contextual-form__field">
            <span class="preview-contextual-form__section-label" id="simple-add-size-label"><?= __admin('preview.simpleAdd.size', 'Size') ?>:</span>
            <!-- Three cards side by side, each word in its own size; the radios stay, for the keyboard -->
            <div class="preview-simple-add__sizes" role="radiogroup" aria-labelledby="simple-add-size-label">
                <?php foreach (['big' => 'Big', 'medium' => 'Medium', 'small' => 'Small'] as $size => $sizeEn): ?>
                <label class="preview-simple-add__size preview-simple-add__size--<?= $size ?>"><input type="radio" name="simple-add-size" value="<?= $size ?>"><span><?= __admin('preview.simpleAdd.' . $size, $sizeEn) ?></span></label>
                <?php endforeach; ?>
            </div>
            <p class="preview-contextual-form__hint" id="simple-add-title-hint" style="display: none;"><?= __admin('preview.simpleAdd.titleHint', 'This page already has a big title. Search engines read a page\'s big title as its subject, so one per page is best.') ?></p>
        </div>
        <div class="preview-contextual-form__field">
            <label for="simple-add-title-text"><?= __admin('preview.simpleAdd.text', 'Text') ?>:</label>
            <input type="text" id="simple-add-title-text" class="admin-input" autocomplete="off" placeholder="<?= __admin('preview.simpleAdd.textPlaceholder', 'Type the text…') ?>">
        </div>
    </div>

    <!-- Button: a real <button>, sending its form when it lands in one -->
    <div class="preview-contextual-form__field" data-preset-fields="button" style="display: none;">
        <label for="simple-add-button-text"><?= __admin('preview.simpleAdd.buttonText', 'Button text') ?>:</label>
        <input type="text" id="simple-add-button-text" class="admin-input" autocomplete="off" placeholder="<?= __admin('preview.simpleAdd.textPlaceholder', 'Type the text…') ?>">
        <p class="preview-contextual-form__hint" id="simple-add-button-in-form" style="display: none;"><?= __admin('preview.simpleAdd.buttonInForm', 'It lands inside a form: it will send the form.') ?></p>
        <p class="preview-contextual-form__hint" id="simple-add-button-no-form" style="display: none;"><?= __admin('preview.simpleAdd.buttonNoForm', 'It does nothing until it is given an action, in Advanced mode under Interactions.') ?></p>
    </div>

    <!-- Link: its text, a page of the site or an outside address, a new tab or not -->
    <div class="preview-contextual-form__section" data-preset-fields="link" style="display: none;">
        <div class="preview-contextual-form__field">
            <label for="simple-add-link-text"><?= __admin('preview.simpleAdd.linkText', 'Link text') ?>:</label>
            <input type="text" id="simple-add-link-text" class="admin-input" autocomplete="off" placeholder="<?= __admin('preview.simpleAdd.textPlaceholder', 'Type the text…') ?>">
        </div>
        <div class="preview-contextual-form__field">
            <label for="simple-add-link-page"><?= __admin('preview.simpleAdd.linkTo', 'Goes to') ?>:</label>
            <select id="simple-add-link-page" class="admin-input">
                <option value=""><?= __admin('preview.simpleAdd.linkPickPage', '— choose a page —') ?></option>
                <!-- The site's pages, filled by preview-simple-add.js; the outside address last -->
                <option value="outside" data-simple-add-outside><?= __admin('preview.simpleAdd.linkOutside', 'An outside address…') ?></option>
            </select>
        </div>
        <div class="preview-contextual-form__field" id="simple-add-link-url-field" style="display: none;">
            <label for="simple-add-link-url"><?= __admin('preview.simpleAdd.linkUrl', 'Address') ?>:</label>
            <input type="url" id="simple-add-link-url" class="admin-input" autocomplete="off" spellcheck="false" placeholder="https://">
        </div>
        <div class="preview-contextual-form__field">
            <label class="admin-checkbox"><input type="checkbox" id="simple-add-link-new-tab"> <span><?= __admin('preview.simpleAdd.newTab', 'Open in a new tab') ?></span></label>
        </div>
    </div>

    <!-- Image, Video, Audio: a file from Media (or uploaded); the image's short description -->
    <div class="preview-contextual-form__section" data-preset-fields="image video audio" style="display: none;">
        <div class="preview-contextual-form__param-row">
            <label class="preview-contextual-form__param-label" for="simple-add-file"><?= __admin('preview.simpleAdd.file', 'File') ?>:</label>
            <div class="preview-contextual-form__param-input-group">
                <input type="text" id="simple-add-file" class="admin-input admin-input--sm preview-contextual-form__param-input" readonly placeholder="<?= __admin('preview.simpleAdd.filePlaceholder', 'Choose a file') ?>">
                <button type="button" class="admin-btn admin-btn--outline preview-asset-browse-btn" id="simple-add-file-choose"><?= __admin('preview.simpleAdd.choose', 'Choose…') ?></button>
            </div>
        </div>
        <div class="preview-contextual-form__field" id="simple-add-alt-field">
            <label for="simple-add-alt"><?= __admin('preview.simpleAdd.description', 'Short description') ?>:</label>
            <input type="text" id="simple-add-alt" class="admin-input" autocomplete="off">
            <p class="preview-contextual-form__hint"><?= __admin('preview.simpleAdd.descriptionHint', 'Read aloud to people who cannot see the image, and shown when it cannot load.') ?></p>
        </div>
        <p class="preview-contextual-form__hint" id="simple-add-media-controls"><?= __admin('preview.simpleAdd.mediaControls', 'Shown with its play controls.') ?></p>
    </div>

    <!-- List: a bulleted list, three lines to start; its lines are drawn by preview-simple-add.js, which adds and removes them -->
    <div class="preview-contextual-form__section" data-preset-fields="list" style="display: none;">
        <label class="preview-contextual-form__section-label"><?= __admin('preview.simpleAdd.listItems', 'Items') ?>:</label>
        <div id="simple-add-list-items"></div>
        <button type="button" class="admin-btn admin-btn--outline" id="simple-add-list-more">+ <?= __admin('preview.simpleAdd.listMore', 'Add an item') ?></button>
    </div>

    <!-- Block: a plain box -->
    <p class="preview-contextual-form__hint" data-preset-fields="block" style="display: none;"><?= __admin('preview.simpleAdd.blockHint', 'An empty box: select it, then add elements inside it.') ?></p>
</div>
