<?php
/**
 * Admin Command Form Page
 * 
 * Shows the form to execute a specific command.
 * Dynamically generates form fields based on help.php documentation.
 */

// $selectedCommand is already set from command.php
// The documentation is fetched by command-form.js from the `help` command.
?>

<div class="admin-command-form-page"
     data-command-name="<?= adminAttr($selectedCommand) ?>">



<div class="admin-page-header">
    <div class="admin-breadcrumb">
        <a href="<?= $router->url('command') ?>" class="admin-breadcrumb__link" id="breadcrumb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            <?= adminEscape(__admin('commands.title')) ?>
        </a>
    </div>
    <h1 class="admin-page-header__title">
        <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="16 18 22 12 16 6"/>
            <polyline points="8 6 2 12 8 18"/>
        </svg>
        <code><?= adminEscape($selectedCommand) ?></code>
    </h1>
    <p class="admin-page-header__subtitle" id="command-description">
        <?= adminEscape(__admin('common.loading')) ?>
    </p>
</div>
<?php if (AdminTranslation::getInstance()->getCurrentLanguage() !== 'en'): ?>
<div class="admin-alert admin-alert--info" id="command-docs-language"><?= adminEscape(__admin('commandForm.docsInEnglish')) ?></div>
<?php endif; ?>

<div class="admin-grid admin-grid--cols-2">
    <!-- Command Form -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= adminEscape(__admin('commands.execute')) ?></h2>
            <p class="admin-card__subtitle" id="command-method">
                <span class="badge" id="method-badge">...</span>
            </p>
        </div>
        <div class="admin-card__body">
            <form id="command-form" class="admin-command-form" data-command="<?= adminAttr($selectedCommand) ?>">
                <div id="command-params">
                    <div class="admin-loading">
                        <span class="admin-spinner"></span>
                        <span><?= adminEscape(__admin('common.loading')) ?></span>
                    </div>
                </div>
                
                <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary admin-btn--lg" id="submit-btn">
                        <?= adminEscape(__admin('commands.execute')) ?>
                    </button>
                    <button type="reset" class="admin-btn admin-btn--outline">
                        <?= adminEscape(__admin('common.reset')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Documentation Panel -->
    <div class="admin-card">
        <div class="admin-card__header">
            <h2 class="admin-card__title"><?= adminEscape(__admin('commands.viewDocs')) ?></h2>
        </div>
        <div class="admin-card__body" id="command-docs">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= adminEscape(__admin('common.loading')) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Response Area -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title"><?= adminEscape(__admin('commands.response')) ?></h2>
    </div>
    <div class="admin-card__body" id="command-response">
        <div class="admin-empty" style="padding: var(--space-lg);">
            <p><?= adminEscape(__admin('commands.tryIt')) ?></p>
        </div>
    </div>
</div>

<?php
// Installation settings that decide whether a listed command could run here AT
// ALL. Not authorization — permissions do that server-side, per call — but the
// difference between a command this installation offers and one it is switched
// off for, which the operator otherwise discovers only from a 403.
//
// The row stays listed and documented either way: only the SUBMIT closes, the
// same treatment login and logoutSession get. A console that hid a command would
// stop being a complete view of the API and become a second source of truth
// about what exists.
// BOTH, and in this order. qs_registration_config() lives in SessionManagement
// but reads loadAuthConfig(), which lives in AuthManagement — requiring only the
// first fatals the page wherever the router has not already pulled the second
// in. members.php requires what it uses for the same reason.
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SessionManagement.php';
$qsConsoleFlags = [
    'selfRegistration' => (bool)qs_registration_config()['allow_self_registration'],
];

// The snippet categories createSnippet's dropdown offers: the server's one list
// (qs_snippet_categories()), so the page keeps no copy of its own. Each is named by
// the editor's word for it, preview.snippetCategory<Category>, handed below.
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';
$qsSnippetCategories = qs_snippet_categories();

// The tags addNode's and editNode's tag field offers: the editor's HTML Tag picker's groups and
// descriptions (TagRegistry::getUICategories()), and every other tag the commands accept
// (TagRegistry::ALLOWED_TAGS) in its Other group, so the console offers all of them and keeps no
// list of its own.
require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';
$qsTagGroups = [];
$qsTagsListed = [];
foreach (TagRegistry::getUICategories() as $qsGroupId => $qsGroup) {
    $qsTags = [];
    foreach ($qsGroup['tags'] as $qsTag => $qsTagInfo) {
        $qsTags[] = ['value' => $qsTag, 'description' => (string) ($qsTagInfo['desc'] ?? '')];
        $qsTagsListed[] = $qsTag;
    }
    $qsTagGroups[$qsGroupId] = ['label' => (string) $qsGroup['label'], 'tags' => $qsTags];
}
if (!isset($qsTagGroups['other'])) {
    $qsTagGroups['other'] = ['label' => __admin('preview.otherTags'), 'tags' => []];
}
foreach (array_diff(TagRegistry::ALLOWED_TAGS, $qsTagsListed) as $qsTag) {
    // A tag the word files describe shows its description; a tag they do not, none.
    $qsTagDesc = 'preview.tagDesc.' . $qsTag;
    $qsTagGroups['other']['tags'][] = ['value' => $qsTag,
        'description' => AdminTranslation::getInstance()->has($qsTagDesc) ? __admin($qsTagDesc) : ''];
}

// The commands getCommandHistory's filter offers: the index's own groups, so the page keeps no
// list of its own.
$qsCommandGroups = [];
foreach (getCommandCategories() as $qsCategory) {
    $qsCommandGroups[] = ['label' => (string) $qsCategory['label'], 'commands' => array_values($qsCategory['commands'])];
}
?>
<?= __adminWords(array_merge([
    'commandForm.hint', 'commandForm.toast', 'commandForm.placeholder', 'commandForm.errors', 'commandForm.select',
    'commandForm.empty', 'commandForm.status', 'commandForm.assets', 'commandForm.deleteAsset', 'commandForm.uploadAsset',
    'commandForm.keyGroup', 'commandForm.editFavicon', 'commandForm.clearHistory', 'commandForm.editStyles',
    'commandForm.setRootVariables', 'commandForm.styleRule', 'commandForm.setKeyframes', 'commandForm.getStructure',
    'commandForm.addComponent', 'commandForm.editComponent', 'commandForm.componentData', 'commandForm.setTranslationKeys',
    'commandForm.deleteTranslationKeys', 'commandForm.field', 'commandForm.noDocumentation', 'commandForm.notPermitted',
    'commandForm.notExecutable', 'commandForm.setRouteResolver',
], array_map(static fn(string $category): string => 'preview.snippetCategory' . ucfirst($category), $qsSnippetCategories))) ?>
<script>
    window.QS_COMMAND_GROUPS = <?= qs_inline_script_json($qsCommandGroups) ?>;
    window.QS_CONSOLE_FLAGS = <?= json_encode($qsConsoleFlags, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.QS_SNIPPET_CATEGORIES = <?= qs_inline_script_json($qsSnippetCategories) ?>;
    window.QS_TAG_GROUPS = <?= qs_inline_script_json(array_values($qsTagGroups)) ?>;
</script>
<!-- The pictures a card can show (setSocialPreview's picture field), the list the Social previews page offers too -->
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/core/media-pictures.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/core/media-pictures.js') ?>"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/admin/assets/js/pages/command-form.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/command-form.js') ?>"></script>

</div> <!-- .admin-command-form-page -->

