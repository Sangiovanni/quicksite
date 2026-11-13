<?php
/**
 * The mode chooser: which menu this account wants, simple or advanced, asked once.
 *
 * layout.php renders it only on a panel page of a signed-in account that has no mode yet, never on sign-in,
 * register or the first-run page: a signed-out person has no account to ask. It is a <template>, so it shows
 * nothing by itself. js/core/panel-mode.js opens it through the panel's one modal (QuickSiteUtils.openModal)
 * once permissions have loaded, after the role filter has hidden in its lists what it hides in the menu: each
 * item carries the same data-requires-command as its menu entry. It closes only by a pick.
 *
 * The lists and the drawings are the menu's own definition (panelMenu.php), so the chooser never names a
 * page the menu does not. ⚠ The mode is not a permission: a page out of the simple menu still opens at its
 * address for anyone whose role allows it.
 */
require_once SECURE_FOLDER_PATH . '/admin/functions/panelMenu.php';

$chooserWords = static function (array $label): string {
    return $label[1] === null ? __admin($label[0]) : __admin($label[0], $label[1]);
};
$chooserPages = qs_admin_menu_pages();
$chooserSimple = array_values(array_filter($chooserPages, static fn($p) => $p['simple']));
$chooserMore = array_values(array_filter($chooserPages, static fn($p) => !$p['simple']));

/**
 * A drawing of the menu in one mode, from the definition: a window, its header band with the menu's
 * top-level items as bars (a group's with its arrow), Build's dropdown open under its bar, and a page below.
 * No words; its colours are the panel's variables (admin.css, .admin-mode-drawing__*).
 */
$chooserDrawing = static function (bool $simple): string {
    $items = array_values(array_filter(qs_admin_menu_drawn(), static fn($i) => !$simple || $i['simple']));
    $n = max(1, count($items));
    $gap = 4;
    $width = (224 - $gap * ($n - 1)) / $n;
    $svg = '<svg class="admin-mode-drawing" viewBox="0 0 240 150" aria-hidden="true" focusable="false">'
        . '<rect class="admin-mode-drawing__frame" x="1" y="1" width="238" height="148" rx="8"/>'
        . '<path class="admin-mode-drawing__band" d="M1 9a8 8 0 0 1 8-8h222a8 8 0 0 1 8 8v40H1z"/>'
        . '<circle class="admin-mode-drawing__accent" cx="16" cy="14" r="5"/>'
        . '<rect class="admin-mode-drawing__line" x="26" y="11" width="34" height="6" rx="3"/>'
        . '<rect class="admin-mode-drawing__pill" x="196" y="8" width="34" height="12" rx="6"/>';
    $drop = '';
    foreach ($items as $i => $item) {
        $x = 8 + $i * ($width + $gap);
        // The first bar is the page you are on, as the menu marks it.
        $active = $i === 0;
        $svg .= '<rect class="admin-mode-drawing__pill' . ($active ? ' admin-mode-drawing__pill--active' : '') . '" x="' . round($x, 2)
            . '" y="30" width="' . round($width, 2) . '" height="12" rx="4"/>';
        if (isset($item['entries'])) {
            $cx = $x + $width - 5;
            $svg .= '<polyline class="admin-mode-drawing__arrow" points="' . round($cx - 2, 2) . ',35 ' . round($cx, 2) . ',37 ' . round($cx + 2, 2) . ',35"/>';
            if ($drop === '' && ($item['group'] ?? '') === 'build') {
                $entries = array_values(array_filter($item['entries'], static fn($e) => !$simple || $e['simple']));
                $boxWidth = max($width, 64);
                $boxX = min($x, 232 - $boxWidth);
                $drop .= '<rect class="admin-mode-drawing__drop" x="' . round($boxX, 2) . '" y="46" width="' . round($boxWidth, 2)
                    . '" height="' . (6 + 10 * count($entries)) . '" rx="4"/>';
                foreach ($entries as $k => $entry) {
                    $drop .= '<rect class="admin-mode-drawing__line" x="' . round($boxX + 6, 2) . '" y="' . (51 + 10 * $k)
                        . '" width="' . round($boxWidth - 18, 2) . '" height="5" rx="2.5"/>';
                }
            }
        }
    }
    // The page under the menu.
    $svg .= '<rect class="admin-mode-drawing__block" x="12" y="62" width="100" height="10" rx="3"/>'
        . '<rect class="admin-mode-drawing__block" x="12" y="82" width="104" height="54" rx="5"/>'
        . '<rect class="admin-mode-drawing__block" x="124" y="82" width="104" height="54" rx="5"/>';
    return $svg . $drop . '</svg>';
};

/** One page of a list: the menu entry's words, hidden by the same role filter as the entry. */
$chooserItem = static function (array $page) use ($chooserWords): string {
    return '<li class="admin-mode-chooser__item"' . ($page['requires'] !== '' ? ' data-requires-command="' . adminAttr($page['requires']) . '"' : '')
        . '>' . $chooserWords($page['label']) . '</li>';
};
?>
<template id="admin-mode-chooser">
    <div class="admin-modal-dialog admin-modal-dialog--chooser" role="dialog" aria-modal="true"
         aria-labelledby="admin-mode-chooser-title" aria-describedby="admin-mode-chooser-intro">
        <div class="admin-modal-dialog__content admin-mode-chooser">
            <h2 class="admin-modal-dialog__title" id="admin-mode-chooser-title"><?= __admin('panelMode.chooser.title', 'How should your panel look?') ?></h2>
            <p class="admin-mode-chooser__intro" id="admin-mode-chooser-intro"><?= __admin('panelMode.chooser.intro', 'Pick the menu that suits you. Both give you the same panel: Simple shows the pages you need to build and publish a site, Advanced shows every page.') ?></p>
            <div class="admin-mode-chooser__cards">
                <?php foreach (['simple' => $chooserSimple, 'advanced' => $chooserMore] as $chooserMode => $chooserList): ?>
                <div class="admin-mode-chooser__card" data-panel-mode="<?= $chooserMode ?>">
                    <?= $chooserDrawing($chooserMode === 'simple') ?>
                    <h3 class="admin-mode-chooser__name" id="admin-mode-chooser-<?= $chooserMode ?>-name"><?= __admin('nav.mode' . ucfirst($chooserMode)) ?></h3>
                    <p class="admin-mode-chooser__lead"><?= __admin('panelMode.chooser.' . $chooserMode . 'Lead') ?></p>
                    <ul class="admin-mode-chooser__list" id="admin-mode-chooser-<?= $chooserMode ?>-list">
                        <?php if ($chooserMode === 'advanced'): ?>
                        <li class="admin-mode-chooser__item admin-mode-chooser__item--all"><?= __admin('panelMode.chooser.everythingInSimple', 'Everything in Simple, plus:') ?></li>
                        <?php endif; ?>
                        <?php foreach ($chooserList as $chooserPage): ?>
                        <?= $chooserItem($chooserPage) ?>
                        <?php endforeach; ?>
                        <?php if ($chooserMode === 'advanced'): ?>
                        <?php /* When the role leaves Advanced nothing more (an account in no project yet), panel-mode.js shows this line
                                 in place of "plus:" over an empty list. */ ?>
                        <li class="admin-mode-chooser__item admin-mode-chooser__item--none" hidden><?= __admin('panelMode.chooser.advancedNone', 'The same pages for now: the others come with a role in a project.') ?></li>
                        <?php endif; ?>
                    </ul>
                    <button type="button" class="admin-btn admin-btn--primary admin-mode-chooser__pick" data-panel-mode="<?= $chooserMode ?>"
                            aria-describedby="admin-mode-chooser-<?= $chooserMode ?>-list"><?= __admin('panelMode.chooser.pick' . ucfirst($chooserMode)) ?></button>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="admin-mode-chooser__note"><strong><?= __admin('panelMode.chooser.note', 'You can change this at any time from the account menu, top right.') ?></strong></p>
            <p class="admin-mode-chooser__error" role="alert" hidden><?= __admin('panelMode.saveFailed', 'Your choice could not be saved. Try again.') ?></p>
        </div>
    </div>
</template>
